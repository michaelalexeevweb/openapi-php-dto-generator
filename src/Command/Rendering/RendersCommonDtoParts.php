<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * Rendering decisions every attribute mode shares: the order properties are declared in, the union
 * interfaces a class implements, a property's doc description, how a temporal value is read back.
 *
 * They lived in whichever mode needed them first and carried its name, so yii3 called a `laravel*`
 * method and Laravel a `symfony*` one. A mode trait calls this trait, the interpreter trait and the
 * generator — never another mode's trait.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 *
 * @phpstan-ignore trait.unused
 */
trait RendersCommonDtoParts
{
    /**
     * The params in the order the schema declares the properties, which is the order `toArray()` writes
     * the keys in. The constructor needs required parameters first, so `$params` is in that order
     * instead — and `toArray()` followed it, writing `{success, payload, message}` for a schema that
     * declares `success, message, payload`, where runtime and Symfony mode keep the schema's order.
     *
     * @template TParam of array<string, mixed>
     * @param array<int, TParam> $params
     * @return array<int, TParam>
     */
    private function inSchemaOrder(string $className, array $params): array
    {
        if (!array_key_exists($className, $this->dtoSchemas)) {
            return $params;
        }

        $position = [];
        foreach ($this->getSchemaProperties($className) as $index => $property) {
            $position[$property['name']] ??= $index;
        }
        usort(
            $params,
            static fn(array $a, array $b): int => ($position[$a['name']] ?? PHP_INT_MAX) <=> ($position[$b['name']] ?? PHP_INT_MAX),
        );

        return $params;
    }

    /**
     * Union interfaces this class implements: the oneOf/anyOf schemas listing it as a member, plus
     * any discriminated base it is mapped to (Symfony DTOs are flattened, so the base cannot be a
     * parent class).
     *
     * @return array<int, string>
     */
    private function implementedUnionInterfaces(string $className, ?string $extends): array
    {
        $interfaces = $this->unionInterfacesByClass[$className] ?? [];

        $base = $extends;
        $guard = 0;
        while ($base !== null && $guard++ < 10) {
            // An allOf discriminator base is an interface only in Symfony mode; the others keep it a class.
            $allOfBaseIsInterface = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && $this->isAllOfDiscriminatorBase($base);
            if ($this->isOneOfDiscriminatorBase($base) || $allOfBaseIsInterface) {
                $interfaces[] = $base;
            }
            $base = $this->discriminatorBaseForMember($base);
        }

        return array_values(array_unique(array_filter(
            $interfaces,
            static fn(string $interface): bool => $interface !== $className,
        )));
    }

    /**
     * The `description` / `example` / `deprecated` annotations as one `@param` suffix. Promoted
     * constructor properties cannot carry their own docblock (and PHP's `#[\Deprecated]` does not
     * apply to properties), so the constructor docblock is where this metadata can live — the same
     * text the runtime mode puts on the getter.
     *
     * @param SchemaProperty $property
     */
    private function resolveDocDescription(array $property): ?string
    {
        $parts = [];

        if (($property['deprecated'] ?? false) === true) {
            $parts[] = 'Deprecated.';
        }

        $description = $property['description'] ?? null;
        if (is_string($description) && $description !== '') {
            $parts[] = $this->stripDocAnnotationSentenceDot($description);
        }

        $example = $property['example'] ?? null;
        if (is_string($example) && $example !== '') {
            $parts[] = 'Example: ' . $example;
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Whether the property is an ARRAY or MAP whose ITEMS are dates — `items: {format: date}` and
     * `additionalProperties: {format: date}` both land here.
     *
     * A predicate rather than "is the getter body non-null?": every mode has to answer this question,
     * and two of them (laravel-data, yii3) answer it without emitting a getter at all — laravel-data
     * to declare the docblock honestly, yii3 to keep an attribute off a container its resolver cannot
     * read.
     *
     * @param SchemaProperty $property
     */
    private function propertyHasTemporalItems(array $property): bool
    {
        if (!is_string($property['itemsTemporalFormat'] ?? null) || !str_contains($property['type'], '<')) {
            return false;
        }

        $itemType = $this->resolveArrayItemPhpType(ltrim($property['type'], '?'));

        return $this->shortClassName(ltrim($itemType, '?')) === 'DateTimeImmutable';
    }

    private function numericLiteral(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        $rendered = json_encode($value);

        return is_string($rendered) ? $rendered : (string)$value;
    }

    private function resolveArrayItemPhpType(string $fullType): string
    {
        if (!str_starts_with($fullType, 'array<')) {
            return 'mixed';
        }

        $itemType = substr($fullType, 6, -1);
        if ($itemType === '') {
            return 'mixed';
        }

        // Map type `array<string, V>` — the value type is the part after the key prefix. Only a
        // comma at depth 0 separates key from value: `array<array<string, mixed>>` is a LIST of
        // maps, and splitting on its inner comma used to yield the unparsable type `mixed>`.
        $depth = 0;
        $length = strlen($itemType);
        for ($i = 0; $i < $length; $i++) {
            $character = $itemType[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $itemType = ltrim(substr($itemType, $i + 1));
                break;
            }
        }

        // A generic item type (a nested list or map) is still just `array` to PHP.
        return str_contains($itemType, '<') ? 'array' : $itemType;
    }

    /**
     * Removes the trailing period from a single-sentence PHPDoc tag description so the
     * generated @param line is already a fixed point of the php-cs-fixer
     * phpdoc_annotation_without_dot rule (which strips that dot otherwise). Multi-sentence
     * text (any internal period) is left untouched — the rule does not act on it either.
     */
    private function stripDocAnnotationSentenceDot(string $text): string
    {
        if (substr_count($text, '.') === 1 && str_ends_with($text, '.')) {
            return substr($text, 0, -1);
        }

        return $text;
    }

    /**
     * The body of a temporal getter, or null when the property is not a date/date-time.
     *
     * @param SchemaProperty $property
     */
    private function temporalGetterBody(array $property): ?string
    {
        $temporalFormat = $property['temporalFormat'] ?? null;
        if (!is_string($temporalFormat)) {
            return null;
        }

        $baseType = ltrim($property['type'], '?');
        if ($this->shortClassName($baseType) !== 'DateTimeImmutable') {
            return null;
        }

        $nullable = $property['nullable'] || !$this->propertyIsRequiredOnInput($property);
        $name = $property['name'];
        $guard = $nullable ? sprintf('$this->%s === null ? null : ', $name) : '';

        if ($temporalFormat === 'Y-m-d') {
            return sprintf('%s$this->%s->format(\'Y-m-d\')', $guard, $name);
        }

        // Same rule as runtime mode: keep sub-second precision when the value carries it.
        return sprintf(
            '%s($this->%s->format(\'u\') === \'000000\'' . "\n            " . '? $this->%s->format(\'c\')' . "\n            " . ': $this->%s->format(\'Y-m-d\TH:i:s.uP\'))',
            $guard,
            $name,
            $name,
            $name,
        );
    }

    /**
     * The body of a temporal ARRAY/MAP getter, or null when the items are not date/date-time.
     *
     * `items: {format: date}` types the item as DateTimeImmutable exactly like the scalar case, and
     * the reader is owed the same string. Symfony's DateTimeNormalizer would print every item RFC
     * 3339 — a `date` array leaving as date-times contradicts the schema it came from.
     *
     * @param SchemaProperty $property
     */
    private function temporalArrayGetterBody(array $property): ?string
    {
        if (!$this->propertyHasTemporalItems($property)) {
            return null;
        }

        // The predicate above already established this is a non-empty string; the `?? null` is only
        // because the key is optional in the shape.
        $itemsTemporalFormat = $property['itemsTemporalFormat'] ?? null;
        $name = $property['name'];
        $nullable = $property['nullable'] || !$this->propertyIsRequiredOnInput($property);
        $guard = $nullable ? sprintf('$this->%s === null ? null : ', $name) : '';

        // `mixed`, and an `instanceof` test rather than a parameter type — the same rule runtime
        // mode follows. PHP cannot type the ITEMS of an array, so a DTO built by hand can hold a
        // string where the docblock promises a date; a typed callback turns that into a TypeError
        // from inside the getter, which takes `DtoNormalizer::validate()` down with it — the very
        // call whose job is to REPORT the mismatch. Passing the value through leaves it reportable.
        // A null item needs no arm of its own: it fails `instanceof` like any other non-date.
        if ($itemsTemporalFormat === 'Y-m-d') {
            $callback = 'static fn (mixed $item): mixed => $item instanceof DateTimeImmutable'
                . "\n                " . '? $item->format(\'Y-m-d\')'
                . "\n                " . ': $item';
        } else {
            // Same rule as runtime mode: keep sub-second precision when the value carries it.
            $callback = 'static fn (mixed $item): mixed => $item instanceof DateTimeImmutable'
                . "\n                " . '? ($item->format(\'u\') === \'000000\''
                . "\n                    " . '? $item->format(\'c\')'
                . "\n                    " . ': $item->format(\'Y-m-d\TH:i:s.uP\'))'
                . "\n                " . ': $item';
        }

        return sprintf(
            '%sarray_map(' . "\n            " . '%s,' . "\n            " . '$this->%s,' . "\n        " . ')',
            $guard,
            $callback,
            $name,
        );
    }

    /**
     * The allOf pattern itself, whatever the mode: an object base with a discriminator whose mapping
     * names only OTHER schemas, each of which `allOf`s it.
     */
    private function isAllOfDiscriminatorBase(string $className): bool
    {
        $discriminator = $this->discriminatorSchemas[$className] ?? null;
        if ($discriminator === null || $discriminator['mapping'] === [] || $this->isOneOfDiscriminatorBase($className)) {
            return false;
        }

        return !in_array($className, $discriminator['mapping'], true)
            && $this->discriminatorMembers($className) !== [];
    }

    /**
     * Classes a discriminated base maps to (its members are linked by the discriminator mapping,
     * not by a oneOf/anyOf member list).
     *
     * @return array<int, string>
     */
    private function discriminatorMembers(string $className): array
    {
        $members = [];
        foreach ($this->dtoSchemas as $candidate => $_definition) {
            if ($this->discriminatorBaseForMember($candidate) === $className) {
                $members[] = $candidate;
            }
        }

        return $members;
    }
}
