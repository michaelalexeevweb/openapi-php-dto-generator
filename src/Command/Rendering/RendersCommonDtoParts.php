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
            array: $params,
            callback: static fn(array $a, array $b): int => ($position[$a['name']] ?? PHP_INT_MAX) <=> ($position[$b['name']] ?? PHP_INT_MAX),
        );

        return $params;
    }

    /**
     * What an undiscriminated union of plain classes names its members with — the keyword and the member
     * classes in document order — so a body that IS the union can be tried member by member: runtime
     * writes it as `OPENAPI_UNION_CONSTRAINTS`, symfony and yii3 as `OPENAPI_UNION_MEMBERS`, since their
     * serializer and hydrator cannot build an interface. Null for a discriminated union (it has its
     * mapping), a union nested in another one (its interface extends the outer one, which a member would
     * then inherit the constant from twice), a member that is itself a union, and a branch that names no
     * member class (`{type: "null"}`), which a member list would leave out.
     *
     * @param array<string> $unionTypes
     * @return array{keyword: string, members: list<string>}|null
     */
    private function undiscriminatedUnionMemberList(string $className, array $unionTypes): ?array
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        $keyword = is_array($schema['oneOf'] ?? null) ? 'oneOf' : (is_array($schema['anyOf'] ?? null) ? 'anyOf' : null);
        if (
            $keyword === null
            || $unionTypes === []
            || count($schema[$keyword]) !== count($unionTypes)
            || array_key_exists($className, $this->discriminatorSchemas)
            || ($this->unionInterfacesByClass[$className] ?? []) !== []
        ) {
            return null;
        }
        foreach ($unionTypes as $member) {
            $memberSchema = $this->dtoSchemas[$this->shortClassName($member)] ?? null;
            if (
                !is_array($memberSchema)
                || array_key_exists('oneOf', $memberSchema)
                || array_key_exists('anyOf', $memberSchema)
            ) {
                return null;
            }
        }

        return ['keyword' => $keyword, 'members' => array_values($unionTypes)];
    }

    /**
     * Whether a class is a member of two unions that both write `OPENAPI_UNION_MEMBERS`: inheriting the
     * same constant from two interfaces is a fatal error at load, so the class declares its own, which
     * overrides both while each interface keeps its value — as runtime does for `OPENAPI_UNION_CONSTRAINTS`.
     */
    private function redeclaresUnionMemberList(string $className): bool
    {
        $listing = 0;
        foreach ($this->unionInterfacesByClass[$className] ?? [] as $interface) {
            if ($this->undiscriminatedUnionMemberList($interface, $this->unionInterfaceMembers($interface)) !== null) {
                $listing++;
            }
        }

        return $listing > 1;
    }

    /**
     * The classes that implement a union interface as its direct members, in the order they were linked.
     *
     * @return list<string>
     */
    private function unionInterfaceMembers(string $interface): array
    {
        $members = [];
        foreach ($this->unionInterfacesByClass as $member => $interfaces) {
            if (in_array($interface, $interfaces, true)) {
                $members[] = $member;
            }
        }

        return $members;
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
            // An allOf discriminator base is an interface in the FLATTENED modes, where a variant cannot
            // extend it; the others keep it a parent class.
            $allOfBaseIsInterface = in_array($this->attributeMode, [self::ATTRIBUTE_MODE_SYMFONY, self::ATTRIBUTE_MODE_YII3], true)
                && $this->isAllOfDiscriminatorBase($base);
            if ($this->isOneOfDiscriminatorBase($base) || $allOfBaseIsInterface) {
                $interfaces[] = $base;
            }
            $base = $this->discriminatorBaseForMember($base);
        }

        return array_values(array_unique(array_filter(
            array: $interfaces,
            callback: static fn(string $interface): bool => $interface !== $className,
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
        // The smallest integer has no literal: `-9223372036854775808` is unary minus on a number one
        // past PHP_INT_MAX, which PHP has already read as a float. Emitted bare, it was a float default
        // on an `int` parameter — a fatal on load — and an int-backed enum case that never resolved.
        if ($value === PHP_INT_MIN) {
            return '(-' . PHP_INT_MAX . ' - 1)';
        }

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
     * A union of `DateTimeImmutable` and members none of which is `string` — `oneOf: [{type: integer},
     * {format: date-time}]` typed `int|DateTimeImmutable`, a plain `array`, a generated class or a
     * non-string enum beside the date. A string the validator let through can then only be the date,
     * and any other value is one of the others, never something to parse.
     */
    private function isTemporalScalarUnion(string $type): bool
    {
        $members = array_map(
            fn(string $member): string => $this->shortClassName($member),
            $this->unionMembersBesideNull($type),
        );
        if (count($members) < 2 || !in_array('DateTimeImmutable', $members, true)) {
            return false;
        }
        foreach ($members as $member) {
            $isScalarOrDate = in_array($member, ['DateTimeImmutable', 'int', 'float', 'bool', 'array'], true);
            // An enum or a generated class beside the date arrives as an int or an array — never as
            // a string, so the string is still the date.
            $isClass = ($this->enumSchemas[$member]['type'] ?? 'string') !== 'string'
                || array_key_exists($member, $this->dtoSchemas);
            if (!$isScalarOrDate && !$isClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a `oneOf`/`anyOf` is made of a `format: date` string and a `format: date-time` string, with
     * at most a null branch beside them — one `DateTimeImmutable` property with two wire shapes, and no
     * single `format` to parse it by.
     *
     * @param array<string, mixed> $constraints
     */
    private function isDateOrDateTimeUnion(array $constraints): bool
    {
        $branches = $constraints['oneOf'] ?? $constraints['anyOf'] ?? null;
        if (!is_array($branches)) {
            return false;
        }

        $formats = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return false;
            }
            if (($branch['type'] ?? null) === 'null') {
                continue;
            }
            $format = $branch['format'] ?? null;
            if (($branch['type'] ?? null) !== 'string' || !in_array($format, ['date', 'date-time'], true)) {
                return false;
            }
            $formats[$format] = true;
        }

        return count($formats) === 2;
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

    /**
     * The class a schema's `allOf` makes its parent — the rule `analyzeSchema()` applies: the
     * discriminator base among the refs, else the single ref; null when the refs are merged.
     */
    private function allOfParentOf(string $className): ?string
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        $refClassNames = [];
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $allOfItem) {
            if (is_array($allOfItem) && is_string($allOfItem['$ref'] ?? null)) {
                $refClassNames[] = $this->schemaRefToClassName(
                    ref: $allOfItem['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
            }
        }

        return $this->findDiscriminatorBaseAmongRefs($className, $refClassNames)
            ?? (count($refClassNames) === 1 ? $refClassNames[0] : null);
    }

    /**
     * Returns the full, flattened property list for a Symfony DTO: inherited properties (resolved
     * recursively through allOf parents) followed by own ones, deduplicated by name so a child
     * override wins. Falls back to the pre-resolved own properties when the schema is not
     * registered (e.g. a union marker).
     *
     * @param array<int, SchemaProperty> $ownProperties
     * @return array<int, SchemaProperty>
     */
    private function flattenedProperties(string $className, array $ownProperties): array
    {
        $all = array_key_exists($className, $this->dtoSchemas)
            ? $this->getSchemaProperties($className)
            : $ownProperties;

        $byName = [];
        foreach ($all as $property) {
            $byName[$property['name']] = $property;
        }

        $values = array_values($byName);

        // Symfony mode keeps schema order: it is the order the properties are declared in, and so
        // the order the serializer writes the keys in. Only required properties are constructor
        // parameters there, so no optional one can precede them.
        if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
            return $values;
        }

        // Elsewhere required params (which get no default) must precede optional ones (which get a
        // default), otherwise PHP emits an "optional before required" deprecation and construction by
        // the required args alone fails. usort is stable on PHP 8.3, so schema order is otherwise kept.
        usort(
            array: $values,
            callback: static fn(array $a, array $b): int => ($b['required'] ? 1 : 0) <=> ($a['required'] ? 1 : 0),
        );

        return $values;
    }

    /**
     * A class schema written as `allOf`, with its branches merged into it — the object the emitted
     * class carries. Symfony mode extracted its constraints from the raw schema, where every property
     * sits inside a branch, so an `allOf` class got no `#[Assert\Callback]` at all: a uuid, a `const`,
     * `uniqueItems` were checked by nothing.
     *
     * A `$ref` branch counts too. Symfony writes the child as one flat class holding the parent's
     * properties, and nothing else checks the parent's rules for them there; listed as merely
     * evaluated, a uuid the parent pinned went through the child unchecked. A property the child
     * redeclares keeps the parent's schema beside its own. With the parent's keys in `properties`, an `additionalProperties`
     * or `unevaluatedProperties` on the class or a branch no longer counts them as extra.
     *
     * @param array<mixed> $schemaDefinition
     * @param array<string, true> $visiting
     * @return array<mixed>
     */
    private function classSchemaWithInlineAllOfMerged(array $schemaDefinition, string $className, array $visiting = []): array
    {
        $allOf = $schemaDefinition['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === []) {
            return $schemaDefinition;
        }
        $visiting[$className] = true;

        $merged = [];
        foreach ($schemaDefinition as $keyword => $value) {
            if ($keyword !== 'allOf') {
                $merged[$keyword] = $value;
            }
        }
        $inherited = [];
        foreach ($allOf as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            if (is_string($branch['$ref'] ?? null)) {
                $parentClass = $this->schemaRefToClassName(
                    ref: $branch['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
                $parentSchema = $this->dtoSchemas[$parentClass] ?? null;
                if (is_array($parentSchema) && !array_key_exists($parentClass, $visiting)) {
                    $parentMerged = $this->classSchemaWithInlineAllOfMerged(
                        schemaDefinition: $parentSchema,
                        className: $parentClass,
                        visiting: $visiting,
                    );
                    $inherited = $this->withRestatedPropertySchemas(
                        properties: $inherited,
                        restated: is_array($parentMerged['properties'] ?? null) ? $parentMerged['properties'] : [],
                    );
                }
                continue;
            }
            if (array_key_exists('$ref', $branch)) {
                continue;
            }
            $required = [
                ...(is_array($merged['required'] ?? null) ? $merged['required'] : []),
                ...(is_array($branch['required'] ?? null) ? $branch['required'] : []),
            ];
            $properties = $this->withRestatedPropertySchemas(
                properties: is_array($merged['properties'] ?? null) ? $merged['properties'] : [],
                restated: is_array($branch['properties'] ?? null) ? $branch['properties'] : [],
            );
            $merged = [...$merged, ...$branch];
            if ($required !== []) {
                $merged['required'] = array_values(array_unique($required));
            }
            if ($properties !== []) {
                $merged['properties'] = $properties;
            }
        }

        if ($inherited !== []) {
            $merged['properties'] = $this->withRestatedPropertySchemas(
                properties: $inherited,
                restated: is_array($merged['properties'] ?? null) ? $merged['properties'] : [],
            );
        }

        return $merged;
    }

    /**
     * The `properties` of two `allOf` branches as one map. A property both declare keeps BOTH schemas
     * under an `allOf`, because both hold: the later one replaced the earlier, so a branch restating
     * `name` with a `minLength` dropped the parent's `maxLength`, its type and its pattern.
     *
     * @param array<mixed> $properties
     * @param array<mixed> $restated
     * @return array<mixed>
     */
    private function withRestatedPropertySchemas(array $properties, array $restated): array
    {
        foreach ($restated as $name => $schema) {
            $earlier = $properties[$name] ?? null;
            $properties[$name] = $earlier === null || $earlier === $schema ? $schema : ['allOf' => [$earlier, $schema]];
        }

        return $properties;
    }

    /**
     * The schema without the type-specific keywords its `type` does not pin — the ones a native rule
     * would apply to a value of ANY type.
     *
     * A keyword that speaks about one JSON type says nothing about a value of another: `pattern`
     * ignores `true`, `minimum` ignores `"x"`, `minItems` ignores a string, and `format: email` ignores
     * `12`. The emitted interpreter knows that; a framework rule does not — `Assert\Regex`, Laravel's
     * `regex:` and `email`, yii3's `Length` all refuse the value instead of passing it over, and the
     * official JSON-Schema-Test-Suite found every framework mode doing so. So a mode builds its native
     * rules from what this returns and leaves the rest to the interpreter, which applies each keyword
     * to its own type only.
     *
     * Pinned means: the PHP type is one kind (`string`, `?int`, a class), or every non-null member of
     * `type` is a type the keyword speaks about. Where it is, nothing changes.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withoutKeywordsTheTypeDoesNotPin(array $schema, string $phpType): array
    {
        $phpMembers = array_values(array_diff(explode('|', ltrim($phpType, '?')), ['null']));
        if (count($phpMembers) === 1 && !in_array($phpMembers[0], ['', 'mixed'], true)) {
            return $schema;
        }

        $declared = $schema['type'] ?? null;
        $pinned = match (true) {
            is_string($declared) => [$declared],
            is_array($declared) => array_values(array_diff(array_filter($declared, is_string(...)), ['null'])),
            default => null,
        };

        $kept = [];
        foreach ($schema as $keyword => $value) {
            $speaksAbout = $this->jsonTypesAKeywordSpeaksAbout(keyword: $keyword, value: $value);
            if ($speaksAbout !== [] && ($pinned === null || array_diff($pinned, $speaksAbout) !== [])) {
                continue;
            }
            $kept[$keyword] = $value;
        }

        return $kept;
    }

    /**
     * The JSON types a keyword constrains, or none for a keyword that constrains every value alike.
     * `format` depends on its value: a string format speaks about strings, a numeric one about numbers.
     *
     * @return array<int, string>
     */
    private function jsonTypesAKeywordSpeaksAbout(string $keyword, mixed $value): array
    {
        return match ($keyword) {
            'minLength', 'maxLength', 'pattern' => ['string'],
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' => ['integer', 'number'],
            'minItems', 'maxItems', 'uniqueItems', 'contains' => ['array'],
            'minProperties', 'maxProperties', 'required', 'dependentRequired' => ['object'],
            'format' => match (true) {
                !is_string($value) => [],
                in_array($value, self::OPENAPI_NUMERIC_FORMATS, true) => ['integer', 'number'],
                default => ['string'],
            },
            default => [],
        };
    }

    /**
     * The properties typed `mixed`, by OpenAPI name — see `filterInterpreterConstraints()`.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<string, true>
     */
    private function mixedPropertyNames(array $properties): array
    {
        $mixed = [];
        foreach ($properties as $property) {
            if (ltrim($property['type'], '?') === 'mixed') {
                $mixed[$property['openApiName']] = true;
            }
        }

        return $mixed;
    }

    /**
     * Whether a schema speaks about the KEYS of an object through a subschema — `properties`,
     * `patternProperties`, `additionalProperties`, `dependentSchemas`. Beside a property typed `mixed`
     * no class is generated for those keys, so nothing but the interpreter can check them: a mode
     * that leaves their subschemas to native attributes or rules leaves them to nobody.
     *
     * @param array<string, mixed> $constraints
     */
    private function schemaDescribesUnmaterializedKeys(array $constraints): bool
    {
        foreach (['properties', 'patternProperties', 'dependentSchemas'] as $mapKeyword) {
            if (is_array($constraints[$mapKeyword] ?? null) && $constraints[$mapKeyword] !== []) {
                return true;
            }
        }

        return array_key_exists('additionalProperties', $constraints) && $constraints['additionalProperties'] !== true;
    }

    /**
     * What a CLASS's `unevaluatedProperties` must see beside the object-level keywords a mode already
     * carries: the names the class declares, every `allOf` branch, and the `anyOf`/`oneOf` branches
     * whole — a key is evaluated by a union branch only when that branch MATCHES, so the branch's
     * assertions decide it. `if`/`then`/`else` and `dependentSchemas` travel as object-level keywords
     * already.
     *
     * The class folds its `allOf` branches into one set of properties and closed itself on those
     * names, which was right only while nothing else evaluated a key: an `additionalProperties: true`
     * or `unevaluatedProperties: true` in a branch, a matching `anyOf` branch, an `if` that applied —
     * each evaluates keys the class never declares, and the payload it allowed was refused. The other
     * direction too: `allOf: [{unevaluatedProperties: false}]` evaluates NOTHING the branch does not
     * declare, its own siblings included, and the folded class accepted them.
     *
     * Property schemas arrive as names over the empty schema: the class validates each property
     * already, so a second copy would report every violation twice, while the NAME is what marks a key
     * evaluated. The same goes for an `allOf` branch's `required`. Empty when the class's in-place
     * tree uses no `unevaluatedProperties` or has no applicator for it to read — then the class's own
     * names are the whole answer and the mode keeps closing on them.
     *
     * @param array<string, mixed> $schema the class schema as the document declared it
     * @param array<string, mixed> $extracted the class schema's validation constraints
     * @return array<string, mixed>
     */
    private function unevaluatedPropertiesEvaluation(array $schema, array $extracted): array
    {
        $hasApplicator = false;
        foreach (['allOf', 'anyOf', 'oneOf', 'if', 'dependentSchemas'] as $applicator) {
            $hasApplicator = $hasApplicator || is_array($schema[$applicator] ?? null);
        }
        if (!$hasApplicator || !$this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedProperties')) {
            return [];
        }

        $evaluation = ['properties' => self::evaluatedNamesOnly($schema['properties'] ?? null)];

        // The extraction reads a tree that uses `unevaluated*` with every `$ref` branch inlined, so a
        // referenced component's keys arrive like an inline branch's.
        $branches = [];
        foreach (is_array($extracted['allOf'] ?? null) ? $extracted['allOf'] : [] as $branch) {
            if (!is_array($branch)) {
                $branches[] = $branch;

                continue;
            }
            $reduced = [];
            foreach ($branch as $keyword => $value) {
                if ($keyword === 'required') {
                    continue;
                }
                $reduced[$keyword] = $keyword === 'properties' ? self::evaluatedNamesOnly($value) : $value;
            }
            $branches[] = $reduced;
        }
        if ($branches !== []) {
            $evaluation['allOf'] = $branches;
        }

        foreach (['anyOf', 'oneOf'] as $union) {
            if (is_array($extracted[$union] ?? null)) {
                $evaluation[$union] = $extracted[$union];
            }
        }

        return $evaluation;
    }

    /**
     * What each `allOf` branch of a class says about the KEYS of the object — its
     * `additionalProperties` (`false` or a schema) and the object-wide keywords a mode asks for — as one
     * interpreter schema per branch, never merged: `{allOf: [{properties: {crane}, additionalProperties:
     * false}]}` closes the object as the plain spelling does, and two branches keep two bounds.
     *
     * The class folds its branches into one set of properties, and these keywords were read from the
     * class's own top level only: such a class closed on nothing, and `{"crane": "a", "stray": "x"}` was
     * accepted with the stray key dropped where the plain spelling refused it. A `$ref` parent branch
     * stands for the parent's schema, at any depth of the chain.
     *
     * A branch's `additionalProperties` speaks about the keys no branch of the same `allOf` declares —
     * the inherited properties are not extra ones beside a parent, as `analyzeSchema()` folds them into
     * one class — while a property the class declares BESIDE its `allOf` reaches no branch, and stays
     * extra to the closure. Property schemas travel as names over the empty schema: the class checks
     * each property already. `unevaluatedProperties` is left to `unevaluatedPropertiesEvaluation()`,
     * which reads the whole tree; the caller drops these parts where that one speaks.
     *
     * @param array<string, mixed> $schema the class schema as the document declared it
     * @param list<string> $objectKeywords the object-wide keywords the mode carries for each branch beside the closure
     * @param string|null $inheritedParent the parent the class extends, whose own method carries its branch
     * @return list<array<string, mixed>>
     */
    private function allOfBranchKeyConstraints(
        array $schema,
        string $className,
        array $objectKeywords,
        ?string $inheritedParent = null,
    ): array {
        $allOf = $schema['allOf'] ?? null;
        if (!is_array($allOf)) {
            return [];
        }

        return $this->keyConstraintsOfAllOf(
            allOf: $allOf,
            sourceClass: $className,
            objectKeywords: $objectKeywords,
            visitedClasses: [$className],
            inheritedParent: $inheritedParent,
        );
    }

    /**
     * @param array<mixed> $allOf
     * @param list<string> $objectKeywords
     * @param list<string> $visitedClasses the `$ref` chain walked so far, so a cycle ends
     * @return list<array<string, mixed>>
     */
    private function keyConstraintsOfAllOf(
        array $allOf,
        string $sourceClass,
        array $objectKeywords,
        array $visitedClasses,
        ?string $inheritedParent,
    ): array {
        $branches = [];
        $siblingNames = [];
        $siblingPatterns = [];
        foreach ($allOf as $branch) {
            $resolved = $this->allOfBranchInPlace(branch: $branch, sourceClass: $sourceClass, visitedClasses: $visitedClasses);
            if ($resolved === null) {
                continue;
            }
            $keys = $this->keysDeclaredInPlace(
                schema: $resolved['schema'],
                sourceClass: $resolved['sourceClass'],
                visitedClasses: $resolved['visitedClasses'],
            );
            $siblingNames += $keys['names'];
            $siblingPatterns += $keys['patterns'];
            $branches[] = $resolved;
        }

        $parts = [];
        foreach ($branches as $resolved) {
            // The class extends that parent and inherits the parent's own constraints with it.
            if ($resolved['sourceClass'] !== $sourceClass && $resolved['sourceClass'] === $inheritedParent) {
                continue;
            }
            $part = $this->branchKeyConstraints(
                branch: $resolved['schema'],
                sourceClass: $resolved['sourceClass'],
                objectKeywords: $objectKeywords,
                siblingNames: $siblingNames,
                siblingPatterns: $siblingPatterns,
            );
            if ($part !== []) {
                $parts[] = $part;
            }
            $nested = $resolved['schema']['allOf'] ?? null;
            if (is_array($nested)) {
                $parts = [...$parts, ...$this->keyConstraintsOfAllOf(
                    allOf: $nested,
                    sourceClass: $resolved['sourceClass'],
                    objectKeywords: $objectKeywords,
                    visitedClasses: $resolved['visitedClasses'],
                    inheritedParent: null,
                )];
            }
        }

        return $parts;
    }

    /**
     * An `allOf` branch as the schema it stands for: itself, or the component a `$ref` names, with the
     * class it belongs to; null for what is not an object schema of this document's classes, or a cycle.
     *
     * @param list<string> $visitedClasses
     * @return array{schema: array<string, mixed>, sourceClass: string, visitedClasses: list<string>}|null
     */
    private function allOfBranchInPlace(mixed $branch, string $sourceClass, array $visitedClasses): ?array
    {
        if (!is_array($branch)) {
            return null;
        }
        $ref = $branch['$ref'] ?? null;
        if (!is_string($ref)) {
            $inline = [];
            foreach ($branch as $keyword => $value) {
                $inline[(string)$keyword] = $value;
            }

            return ['schema' => $inline, 'sourceClass' => $sourceClass, 'visitedClasses' => $visitedClasses];
        }

        $parentClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($sourceClass));
        $parentSchema = $this->dtoSchemas[$parentClass] ?? null;
        if (!is_array($parentSchema) || in_array($parentClass, $visitedClasses, true)) {
            return null;
        }

        return ['schema' => $parentSchema, 'sourceClass' => $parentClass, 'visitedClasses' => [...$visitedClasses, $parentClass]];
    }

    /**
     * The property names and `patternProperties` patterns a schema declares in place: its own and those
     * of its `allOf` branches, a `$ref`'d parent's included.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $visitedClasses
     * @return array{names: array<string, array{}>, patterns: array<string, array{}>}
     */
    private function keysDeclaredInPlace(array $schema, string $sourceClass, array $visitedClasses): array
    {
        $names = self::evaluatedNamesOnly($schema['properties'] ?? null);
        $patterns = self::evaluatedNamesOnly($schema['patternProperties'] ?? null);
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $branch) {
            $resolved = $this->allOfBranchInPlace(branch: $branch, sourceClass: $sourceClass, visitedClasses: $visitedClasses);
            if ($resolved === null) {
                continue;
            }
            $keys = $this->keysDeclaredInPlace(
                schema: $resolved['schema'],
                sourceClass: $resolved['sourceClass'],
                visitedClasses: $resolved['visitedClasses'],
            );
            $names += $keys['names'];
            $patterns += $keys['patterns'];
        }

        return ['names' => $names, 'patterns' => $patterns];
    }

    /**
     * One branch's part of `allOfBranchKeyConstraints()`: the object-wide keywords asked for, and the
     * closure over the names of its `allOf` with the branch's `additionalProperties`.
     *
     * @param array<string, mixed> $branch
     * @param list<string> $objectKeywords
     * @param array<string, array{}> $siblingNames
     * @param array<string, array{}> $siblingPatterns
     * @return array<string, mixed>
     */
    private function branchKeyConstraints(
        array $branch,
        string $sourceClass,
        array $objectKeywords,
        array $siblingNames,
        array $siblingPatterns,
    ): array {
        $objectWide = [];
        foreach ($objectKeywords as $keyword) {
            if (array_key_exists($keyword, $branch)) {
                $objectWide[$keyword] = $branch[$keyword];
            }
        }
        $closes = ($branch['additionalProperties'] ?? null) === false;
        if (is_array($branch['additionalProperties'] ?? null)) {
            $objectWide['additionalProperties'] = $branch['additionalProperties'];
        }

        $extracted = $objectWide === [] ? [] : $this->withConstraintSourceOf(
            className: $sourceClass,
            extraction: fn(): array => $this->extractValidationConstraints(
                $this->inlineNestedContainerValidation(schema: $objectWide, belowMaterialization: true),
            ),
        );
        $part = [];
        foreach ($objectKeywords as $keyword) {
            if (array_key_exists($keyword, $extracted)) {
                $part[$keyword] = $extracted[$keyword];
            }
        }
        // `{}` admits every value, so it asserts nothing.
        $additionalSchema = is_array($extracted['additionalProperties'] ?? null) && $extracted['additionalProperties'] !== []
            ? $extracted['additionalProperties']
            : null;
        if (!$closes && $additionalSchema === null) {
            return $part;
        }

        // The interpreter decides what is additional from `properties` and `patternProperties` of the
        // same schema, so the names of the whole `allOf` travel with the closure.
        $part['properties'] = $siblingNames;
        $ownPatterns = is_array($part['patternProperties'] ?? null) ? $part['patternProperties'] : [];
        if ($ownPatterns !== [] || $siblingPatterns !== []) {
            $part['patternProperties'] = $ownPatterns + $siblingPatterns;
        }
        $part['additionalProperties'] = $closes ? false : $additionalSchema;

        return $part;
    }

    /**
     * The interpreter constraints of a class whose `allOf` was merged into one schema, with the
     * evaluation of its `unevaluatedProperties` put back the way the document wrote it.
     *
     * Merging pours every branch into the class schema, last one wins: `allOf: [{unevaluatedProperties:
     * false}]` beside an outer `true` became one `false` over the class's own properties, and a branch's
     * `additionalProperties` closed or opened the whole class. Each branch evaluates on its own, so where
     * `unevaluatedProperties` reads the tree, the branches travel as written and the merged class keeps
     * only the keywords it declared itself — see `unevaluatedPropertiesEvaluation()`.
     *
     * @param array<string, mixed> $constraints the interpreter constraints built from the merged schema
     * @return array<string, mixed>
     */
    private function withUnevaluatedPropertiesEvaluated(array $constraints, string $className): array
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        $extracted = $this->withConstraintSourceOf(
            className: $className,
            extraction: fn(): array => $this->extractValidationConstraints($schema),
        );
        $evaluation = $this->unevaluatedPropertiesEvaluation(schema: $schema, extracted: $extracted);
        if ($evaluation === []) {
            // Every key the class declares is evaluated by its `properties`, including one whose schema
            // left nothing to check once the PHP type took it over — `x: {$ref: '#'}` went with its
            // name, and the class refused its own `x` as unevaluated.
            if (array_key_exists('unevaluatedProperties', $constraints)) {
                $names = [];
                foreach ($this->getSchemaProperties($className) as $property) {
                    $names[$property['openApiName']] = [];
                }
                $constraints['properties'] = (is_array($constraints['properties'] ?? null) ? $constraints['properties'] : []) + $names;
            }

            return $constraints;
        }

        // These modes judge the HYDRATED object, which holds the declared properties and nothing else. A
        // branch that requires a key the class does not declare cannot match there however the payload
        // looked, so the applicator holding it would refuse every valid payload; it is left out, and the
        // closed-object limitation covers what it could have caught (README.support-matrix). The `$ref`
        // in such a branch used to stay unresolved and drop the applicator by accident.
        $declared = [];
        foreach ($this->getSchemaProperties($className) as $property) {
            $declared[$property['openApiName']] = true;
        }
        $judgeable = static function (mixed $subschema) use ($declared): bool {
            foreach (self::requiredNamesInPlace($subschema) as $requiredName) {
                if (!array_key_exists($requiredName, $declared)) {
                    return false;
                }
            }

            return true;
        };
        if (!$judgeable($evaluation)) {
            $kept = [];
            foreach ($constraints as $keyword => $value) {
                if (in_array($keyword, ['allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'dependentSchemas', 'not'], true) && !$judgeable([$keyword => $value])) {
                    continue;
                }
                $kept[$keyword] = $value;
            }

            return $kept;
        }

        $result = [];
        foreach ($constraints as $keyword => $value) {
            // Replaced below by what the document wrote.
            if (in_array($keyword, ['allOf', 'anyOf', 'oneOf', 'unevaluatedProperties'], true)) {
                continue;
            }
            // Kept only where the class declared it itself, not where a branch was merged in.
            if (in_array($keyword, ['additionalProperties', 'patternProperties'], true) && !array_key_exists($keyword, $schema)) {
                continue;
            }
            $result[$keyword] = $value;
        }
        $result['properties'] = (is_array($constraints['properties'] ?? null) ? $constraints['properties'] : []) + $evaluation['properties'];
        foreach (['allOf', 'anyOf', 'oneOf'] as $applicator) {
            if (array_key_exists($applicator, $evaluation)) {
                $result[$applicator] = $evaluation[$applicator];
            }
        }
        if (array_key_exists('unevaluatedProperties', $extracted) && !array_key_exists('additionalProperties', $schema)) {
            $result['unevaluatedProperties'] = $extracted['unevaluatedProperties'];
        }

        return $result;
    }

    /**
     * Whether `$keyword` appears in the schema or in any subschema that applies to the SAME value — the
     * in-place applicators — rather than to one of its members.
     */
    private function inPlaceTreeUses(mixed $schema, string $keyword, int $depth = 0): bool
    {
        if (!is_array($schema) || $depth > 32) {
            return false;
        }
        if (array_key_exists($keyword, $schema)) {
            return true;
        }
        $subschemas = [];
        foreach (['allOf', 'anyOf', 'oneOf', 'dependentSchemas'] as $listKeyword) {
            foreach (is_array($schema[$listKeyword] ?? null) ? $schema[$listKeyword] : [] as $branch) {
                $subschemas[] = $branch;
            }
        }
        foreach (['if', 'then', 'else'] as $conditionalKeyword) {
            $subschemas[] = $schema[$conditionalKeyword] ?? null;
        }
        foreach ($subschemas as $subschema) {
            if ($this->inPlaceTreeUses(schema: $subschema, keyword: $keyword, depth: $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every name a `required` lists in the schema or in the subschemas that apply to the same object.
     *
     * @return list<string>
     */
    private static function requiredNamesInPlace(mixed $schema, int $depth = 0): array
    {
        if (!is_array($schema) || $depth > 32) {
            return [];
        }
        $names = [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            $names[] = (string)$name;
        }
        $subschemas = [];
        foreach (['allOf', 'anyOf', 'oneOf', 'dependentSchemas'] as $listKeyword) {
            foreach (is_array($schema[$listKeyword] ?? null) ? $schema[$listKeyword] : [] as $branch) {
                $subschemas[] = $branch;
            }
        }
        foreach (['if', 'then', 'else', 'not'] as $conditionalKeyword) {
            $subschemas[] = $schema[$conditionalKeyword] ?? null;
        }
        foreach ($subschemas as $subschema) {
            $names = [...$names, ...self::requiredNamesInPlace($subschema, $depth + 1)];
        }

        return $names;
    }

    /**
     * A `properties` map as the names it declares, each over the empty schema.
     *
     * @return array<string, array<never, never>>
     */
    private static function evaluatedNamesOnly(mixed $properties): array
    {
        $names = [];
        foreach (is_array($properties) ? array_keys($properties) : [] as $name) {
            $names[(string)$name] = [];
        }

        return $names;
    }

    /**
     * The one generated class in a union whose other members are scalars, enums, `array` or
     * `DateTimeImmutable` — `Shade|string`, `Shade|int|null`, `DateTimeImmutable|Shade`,
     * `Level|Shade`, `array|Shade` — or null for any other type. On the wire such a value is the
     * object exactly when it is a JSON object (beside an `array` member: a non-list one), so that alone
     * decides which member it is. A second class makes the question ambiguous and is left alone, and so
     * is a type that is the class alone — that is a plain nested DTO, not a union.
     */
    private function unionClassBesideScalars(string $phpType): ?string
    {
        $members = $this->unionMembersBesideNull($phpType);
        if (count($members) < 2) {
            return null;
        }

        $classes = [];
        foreach ($members as $member) {
            if (in_array($member, ['string', 'int', 'float', 'bool', 'array', 'DateTimeImmutable'], true)) {
                continue;
            }
            $short = $this->shortClassName($member);
            if ($short === 'DateTimeImmutable' || array_key_exists($short, $this->enumSchemas)) {
                continue;
            }
            if (!array_key_exists($short, $this->dtoSchemas)) {
                return null;
            }
            $classes[] = $short;
        }

        return count($classes) === 1 ? $classes[0] : null;
    }

    /**
     * Whether a union names a generated DTO class among its members, however many — `Shade|string`,
     * `DateTimeImmutable|Olive|Teal`. Unlike `unionClassBesideScalars()` it does not ask which one: such
     * a value arrives as a JSON object whatever class it becomes.
     */
    private function unionHasGeneratedClassMember(string $phpType): bool
    {
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            $short = $this->shortClassName($member);
            if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The generated classes of a union holding TWO or more of them — `Olive|Teal`, `Olive|Teal|null`,
     * `Olive|Teal|string` — in declaration order, or `[]` for any other type. Beside them only scalars,
     * enums and `DateTimeImmutable` may stand: a plain `array` member makes a JSON object ambiguous
     * between a class and the free-form value, and that is left alone.
     *
     * The wire value alone does not say which class it is, so a mode building one has to ask each
     * member's own schema — the validator has already decided that one of them matches.
     *
     * @return list<string>
     */
    private function unionClassMembers(string $phpType): array
    {
        $classes = [];
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            if (in_array($member, ['string', 'int', 'float', 'bool', 'DateTimeImmutable'], true)) {
                continue;
            }
            $short = $this->shortClassName($member);
            if ($short === 'DateTimeImmutable' || array_key_exists($short, $this->enumSchemas)) {
                continue;
            }
            if (!array_key_exists($short, $this->dtoSchemas)) {
                return [];
            }
            $classes[] = $short;
        }

        return count($classes) >= 2 ? $classes : [];
    }

    /**
     * Whether a union also holds a plain `array` beside its members — the list a JSON array arrives as,
     * which a class member must then tell apart from a JSON object by its keys.
     */
    private function unionHasArrayMember(string $phpType): bool
    {
        return in_array('array', $this->unionMembersBesideNull($phpType), true);
    }

    /**
     * The top-level members of a PHP type, without `null` — generics (`array<int, X|Y>`) stay whole.
     *
     * @return list<string>
     */
    private function unionMembersBesideNull(string $phpType): array
    {
        $members = [];
        $depth = 0;
        $current = '';
        foreach (str_split(ltrim(trim($phpType), '?')) as $character) {
            $depth += match ($character) {
                '<' => 1,
                '>' => -1,
                default => 0,
            };
            if ($character === '|' && $depth === 0) {
                $members[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $character;
        }
        $members[] = trim($current);

        return array_values(array_filter(
            $members,
            static fn(string $member): bool => $member !== '' && $member !== 'null',
        ));
    }

    /**
     * The names a class's `required` lists — its own, those of its inline `allOf` branches and those of
     * the components its `allOf` references — that none of those schemas declares in `properties`.
     * A union class judges itself branch by branch elsewhere and gets none here.
     *
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private function undeclaredRequiredNamesOfClass(array $schema, string $sourceClass): array
    {
        if (array_key_exists('oneOf', $schema) || array_key_exists('anyOf', $schema)) {
            return [];
        }
        $names = $this->requiredAndDeclaredNamesOfClass(schema: $schema, sourceClass: $sourceClass, depth: 0);

        return array_values(array_diff(array_unique($names['required']), $names['declared']));
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array{required: list<string>, declared: list<string>}
     */
    private function requiredAndDeclaredNamesOfClass(array $schema, string $sourceClass, int $depth): array
    {
        $required = [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (is_string($name) || is_int($name)) {
                $required[] = (string)$name;
            }
        }
        $declared = [];
        foreach (is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [] as $name) {
            $declared[] = (string)$name;
        }

        $branches = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $branches[] = ['$ref' => $ref];
        }
        // A reference cycle through `allOf` is the document's own error; the depth only keeps it finite.
        if ($depth > 16) {
            return ['required' => $required, 'declared' => $declared];
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $branchRef = $branch['$ref'] ?? null;
            if (is_string($branchRef)) {
                $resolved = $this->resolveSchemaNodeByRef($branchRef, $this->getSchemaSourceFile($sourceClass));
                $branch = $resolved === null ? [] : $resolved[0];
            }
            // A union branch is satisfied by one of its members, which says nothing about all of them.
            if (array_key_exists('oneOf', $branch) || array_key_exists('anyOf', $branch)) {
                continue;
            }
            $below = $this->requiredAndDeclaredNamesOfClass(schema: $branch, sourceClass: $sourceClass, depth: $depth + 1);
            $required = [...$required, ...$below['required']];
            $declared = [...$declared, ...$below['declared']];
        }

        return ['required' => $required, 'declared' => $declared];
    }

    /**
     * Where an EMPTY array in a property's wire form is a JSON object, or null when it never is.
     *
     * A map (`additionalProperties`) is held as a PHP array, and the empty one is the same `[]` as an
     * empty list: `{"widths": {}}` was written back `{"widths": []}` in symfony, laravel-data and yii3
     * mode. The schema still knows, so it is named here and the mode's way out writes `{}`: `object`
     * for a map or an object, `object<…>` for a map of values that are such themselves, `list<…>` for a
     * list of them. A non-empty map stays the array it is — the JSON is the same, and code reading the
     * array form keeps its keys.
     *
     * @param array<string, mixed> $constraints the property's schema, for a free-form `type: object`,
     *                                          which is a bare `array` here
     */
    private function emptyObjectWireShape(string $type, array $constraints = []): ?string
    {
        $type = ltrim($type, '?\\');
        if ($type === 'array') {
            $schemaType = $constraints['type'] ?? null;

            return $schemaType === 'object' || (is_array($schemaType) && in_array('object', $schemaType, true) && !in_array('array', $schemaType, true))
                ? 'object'
                : null;
        }
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            $inner = substr($type, 6, -1);
            $depth = 0;
            $key = null;
            $length = strlen($inner);
            for ($position = 0; $position < $length && $key === null; $position++) {
                $depth += match ($inner[$position]) {
                    '<' => 1,
                    '>' => -1,
                    default => 0,
                };
                if ($inner[$position] === ',' && $depth === 0) {
                    $key = trim(substr($inner, 0, $position));
                    $inner = trim(substr($inner, $position + 1));
                }
            }
            $itemShape = $this->emptyObjectWireShape($inner);
            if ($key === 'string') {
                return $itemShape === null ? 'object' : 'object<' . $itemShape . '>';
            }

            return $itemShape === null ? null : 'list<' . $itemShape . '>';
        }

        // A generated class — an object on the wire, held as itself or as the decoded array inside a
        // container of them.
        return !str_contains($type, '|') && array_key_exists($this->shortClassName($type), $this->dtoSchemas)
            ? 'object'
            : null;
    }
}
