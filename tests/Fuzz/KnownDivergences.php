<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use stdClass;

/**
 * The DOCUMENTED divergences, so the fuzzer does not report them as findings.
 *
 * Each entry names the README.support-matrix.md row (or the open item) it stands for. An entry matches
 * by mode, by what went wrong (`wrong-accept` / `wrong-refuse`), by mutation kind (prefix match; `valid`
 * for the valid payload) and by tags: `tagsAll` must all be present, `tagsAny` at least one. Keep the
 * entries NARROW — an entry that matches more than its row hides real defects. `spelling` marks a row
 * whose divergence depends on how the schema is spelled; `built` (`temporal` / `dto`) a symfony row the
 * serializer answers because it builds the value's position as a date or a DTO.
 */
final class KnownDivergences
{
    /**
     * @param array<int, string> $tags
     */
    public static function explain(GenerationMode $mode, string $category, string $kind, array $tags): ?string
    {
        return self::firstMatching(mode: $mode, category: $category, kind: $kind, tags: $tags, spellingOnly: false);
    }

    /**
     * {@see explain()} over the entries marked `spelling` only: rows whose divergence depends on how
     * the schema is SPELLED (generated class names, member order), so that two equivalent spellings
     * may meet it differently. The metamorphic run excuses a divergence between spellings only by these.
     *
     * @param array<int, string> $tags
     */
    public static function explainSpellingDependent(GenerationMode $mode, string $category, string $kind, array $tags): ?string
    {
        return self::firstMatching(mode: $mode, category: $category, kind: $kind, tags: $tags, spellingOnly: true);
    }

    /**
     * {@see explain()} over the entries marked `built` only: symfony rows the serializer answers before
     * any constraint runs, because the payload's position is a class it BUILDS — a `DateTimeImmutable`
     * (`temporal`) or a generated DTO (`dto`). A spelling that holds the same position raw leaves the
     * value to the generated callback, so two equivalent spellings may meet such a row differently
     * ({@see MetamorphicRunner::excusedBySerializerBuiltPosition()}).
     *
     * @param array<int, string> $tags
     * @return array{row: string, built: string}|null
     */
    public static function explainSerializerBuilt(GenerationMode $mode, string $category, string $kind, array $tags): ?array
    {
        foreach (self::entries() as $entry) {
            $built = $entry['built'] ?? null;
            if ($built !== null && self::entryMatches(entry: $entry, mode: $mode, category: $category, kind: $kind, tags: $tags)) {
                return ['row' => $entry['row'], 'built' => $built];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $tags
     */
    private static function firstMatching(GenerationMode $mode, string $category, string $kind, array $tags, bool $spellingOnly): ?string
    {
        foreach (self::entries() as $entry) {
            if ($spellingOnly && !($entry['spelling'] ?? false)) {
                continue;
            }
            if (self::entryMatches(entry: $entry, mode: $mode, category: $category, kind: $kind, tags: $tags)) {
                return $entry['row'];
            }
        }

        return null;
    }

    /**
     * @param array{modes: array<int, GenerationMode>, category: string, kinds: array<int, string>, tagsAll?: array<int, string>, tagsAny?: array<int, string>, row: string, spelling?: bool, built?: string} $entry
     * @param array<int, string> $tags
     */
    private static function entryMatches(array $entry, GenerationMode $mode, string $category, string $kind, array $tags): bool
    {
        if (!in_array($mode, $entry['modes'], true) || $entry['category'] !== $category) {
            return false;
        }
        if (!self::kindMatches(kind: $kind, prefixes: $entry['kinds'])) {
            return false;
        }
        if (array_diff($entry['tagsAll'] ?? [], $tags) !== []) {
            return false;
        }

        return ($entry['tagsAny'] ?? []) === [] || array_intersect($entry['tagsAny'], $tags) !== [];
    }

    /**
     * A crash or a refusal at a union COMPONENT of two objects or more and no discriminator (tagged
     * `undiscriminatedComponent`, whether the objects are its own members or a nested union's) in a mode the generator WARNED, for a component this property reaches,
     * that it cannot hydrate a payload into: the support matrix, "Not generated in any mode", "hydration
     * of a union COMPONENT of OBJECTS with no `discriminator`" — the generator names the property at
     * generation time instead of letting the request find out. Only laravel and laravel-data, the modes
     * that row names; a warning in any other mode, or a failure with no warning for that component, is
     * a finding.
     *
     * @param array<int, string> $tags
     * @param array<int, string> $warnings the generation warnings of this mode for the document
     * @param array<int, string> $reachable the components the property's schema reaches
     */
    public static function explainUnhydratableUnion(GenerationMode $mode, string $category, array $tags, array $warnings, array $reachable): ?string
    {
        if (!in_array($mode, [GenerationMode::Laravel, GenerationMode::LaravelData], true)) {
            return null;
        }
        if (!in_array($category, ['crash', 'wrong-refuse'], true) || !in_array('undiscriminatedComponent', $tags, true)) {
            return null;
        }
        foreach ($warnings as $warning) {
            $matched = preg_match('/refers to (\w+), an undiscriminated (?:anyOf|oneOf) union: this mode cannot hydrate/', $warning, $parts);
            if ($matched === 1 && in_array($parts[1], $reachable, true)) {
                return 'support matrix: "hydration of a union COMPONENT of OBJECTS with no `discriminator`" (not generated; the generator warns, naming the property)';
            }
        }

        return null;
    }

    /**
     * A laravel-data refusal of a VALID object of a union of classes whose FIRST member holds a
     * discriminated union under a name a sibling member gives a non-object (`Cat.toy: $ref Toy`, Toy
     * discriminated, `Dog.toy` an integer), the value carrying that name as a non-object: the support
     * matrix, "a discriminated union inside the FIRST member of an inline union of classes" — laravel-data
     * walks the first data class of the property type whatever the tag says, and the generated guard
     * refuses with a 422 what would otherwise be a `TypeError`. Walked with the value, at any depth.
     *
     * @param array<string, mixed> $document
     */
    public static function explainFirstMemberDiscriminatedGuard(GenerationMode $mode, string $category, array $document, string $property, mixed $value): ?string
    {
        if ($mode !== GenerationMode::LaravelData || $category !== 'wrong-refuse') {
            return null;
        }
        $schemas = $document['components']['schemas'] ?? null;
        if (!is_array($schemas)) {
            return null;
        }

        return self::meetsFirstMemberGuard(schema: $schemas['Probe']['properties'][$property] ?? null, value: $value, schemas: $schemas, depth: 0)
            ? 'support matrix: "a discriminated union inside the FIRST member of an inline union of classes" (laravel-data refuses with a 422)'
            : null;
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private static function meetsFirstMemberGuard(mixed $schema, mixed $value, array $schemas, int $depth): bool
    {
        $schema = self::resolved(schema: $schema, schemas: $schemas);
        if ($schema === null || $depth > 16) {
            return false;
        }
        $members = [];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $member) {
                $member = self::resolved(schema: $member, schemas: $schemas);
                if ($member !== null) {
                    $members[] = $member;
                }
            }
        }

        if (is_array($value)) {
            foreach ([$schema, ...$members] as $candidate) {
                foreach ($value as $item) {
                    if (self::meetsFirstMemberGuard(schema: $candidate['items'] ?? null, value: $item, schemas: $schemas, depth: $depth + 1)) {
                        return true;
                    }
                }
            }

            return false;
        }
        if (!$value instanceof stdClass) {
            return false;
        }

        $objectMembers = array_values(array_filter($members, static fn(array $member): bool => is_array($member['properties'] ?? null)));
        if (count($objectMembers) >= 2) {
            foreach ($objectMembers[0]['properties'] as $name => $firstSchema) {
                $first = self::resolved(schema: $firstSchema, schemas: $schemas);
                if ($first === null || !array_key_exists('discriminator', $first) || !property_exists($value, (string)$name) || $value->{$name} instanceof stdClass) {
                    continue;
                }
                foreach (array_slice($objectMembers, 1) as $sibling) {
                    $siblingSchema = self::resolved(schema: $sibling['properties'][$name] ?? null, schemas: $schemas);
                    if ($siblingSchema !== null && !self::describesObject($siblingSchema)) {
                        return true;
                    }
                }
            }
        }

        foreach ([$schema, ...$members] as $candidate) {
            foreach (is_array($candidate['properties'] ?? null) ? $candidate['properties'] : [] as $name => $propertySchema) {
                if (property_exists($value, (string)$name) && self::meetsFirstMemberGuard(schema: $propertySchema, value: $value->{$name}, schemas: $schemas, depth: $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The schema a `$ref` to a component names, or the schema itself; null for anything but an array.
     *
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>|null
     */
    private static function resolved(mixed $schema, array $schemas): ?array
    {
        for ($hops = 0; is_array($schema) && is_string($schema['$ref'] ?? null) && $hops < 16; $hops++) {
            $schema = $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null;
        }

        return is_array($schema) ? $schema : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function describesObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return $type === 'object'
            || (is_array($type) && in_array('object', $type, true))
            || array_key_exists('properties', $schema)
            || array_key_exists('discriminator', $schema);
    }

    /**
     * @param array<int, string> $prefixes
     */
    private static function kindMatches(string $kind, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($kind, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{modes: array<int, GenerationMode>, category: string, kinds: array<int, string>, tagsAll?: array<int, string>, tagsAny?: array<int, string>, row: string, spelling?: bool, built?: string}>
     */
    private static function entries(): array
    {
        return [
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                // `{}` for an object that requires a key, or for a list: the body arrives decoded, where
                // it is the `[]` the `array` member (or the list itself) accepts.
                'kinds' => ['missingRequired', 'tooFewProperties', 'wrongType:object'],
                'tagsAll' => ['emptyBesideArray'],
                'row' => 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member, `[]` for the list member of a union beside an object member" (symfony and yii3 read `[]` and `{}` as one)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // `[]` for the `minItems` list member of a union beside an object member: decoded, it is
                // the `[]` that `{}` is too, and the serializer builds the object member's class from it.
                // yii3 hydrates the union property as sent and refuses.
                'kinds' => ['tooFewItems'],
                'tagsAll' => ['emptyListBesideObject'],
                'row' => 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member, `[]` for the list member of a union beside an object member" (symfony reads `[]` and `{}` as one)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['wrongType:array'],
                // `unionClassMember`: the row's own last sentence — a union of a class and a scalar is
                // read the same way, the JSON array built into the class.
                'tagsAny' => ['site:dto', 'site:nullable-dto', 'site:map', 'site:nullable-map', 'site:discriminated', 'site:nullable-discriminated', 'unionClassMember'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'dto',
                'row' => 'support matrix: "a JSON array for a `type: object` property" (symfony, yii3 accept)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                'kinds' => ['wrongType:'],
                'tagsAny' => ['site:dto', 'site:nullable-dto'],
                'row' => 'support matrix: "a scalar for a property typed as a generated class" (symfony/serializer 7.4.10 builds an empty object)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // A union with a class member too: the row's "union of such a class and a scalar". Any
                // scalar the union refuses — the wrong type, or one its own branch refuses (`notInEnum`,
                // `tooLong`, …) — is built into the class before a constraint runs; the generator
                // tags only scalar mutations so.
                'kinds' => [
                    'wrongType:',
                    'notInEnum',
                    'patternMismatch',
                    'tooShort',
                    'tooLong',
                    'belowMinimum',
                    'aboveMaximum',
                    'atExclusiveMinimum',
                    'atExclusiveMaximum',
                    'notMultipleOf',
                    'badFormat:',
                    'impossibleDate:',
                    'looseDateTime',
                    'parseableNonDate:',
                    'nearMissFormat:',
                ],
                // `classRequiresNothing` (set by `FuzzRunner` off the schema): a class that requires a
                // key refuses the empty object the serializer builds, so a scalar accepted there is no row.
                'tagsAll' => ['unionClassMember', 'classRequiresNothing'],
                'row' => 'support matrix: "a scalar for a property typed as a generated class" (symfony/serializer 7.4.10 builds an empty object)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                'kinds' => ['nullForbidden', 'wrongType:'],
                'tagsAll' => ['classRequiresNothing'],
                'tagsAny' => ['itemsOf:dto', 'valuesOf:dto'],
                'row' => 'support matrix: "a non-object ITEM of a list or map of DTOs" (symfony/serializer 7.4.10 builds an empty object when the class requires nothing)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['extraKeyClosed'],
                'tagsAny' => ['site:dto', 'site:nullable-dto'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'dto',
                'row' => 'support matrix: "`additionalProperties: false` / `unevaluatedProperties: false` on a DTO-shaped schema"',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // Any refusal of an undiscriminated union of objects that one member owes ONLY to a key
                // it does not declare (`additionalProperties: false` or `{schema}`): the serializer
                // builds that member's class without the key. yii3 holds the union decoded and refuses.
                'kinds' => [''],
                'tagsAll' => [FuzzSpec::REFUSED_ONLY_BY_UNDECLARED_KEY],
                // Which member's class the serializer tries first follows the generated class names.
                'spelling' => true,
                'row' => 'support matrix: "`additionalProperties: false` / `unevaluatedProperties: false` on a DTO-shaped schema" and "a key `additionalProperties: {schema}` admits beside the named properties" (symfony, a member of an undiscriminated union of classes included)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAll' => ['in:additional'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'dto',
                'row' => 'support matrix: "a key `additionalProperties: {schema}` admits beside the named properties" (dropped unchecked)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                'kinds' => ['impossibleDate:', 'looseDateTime'],
                // Only where the serializer meets a date: the property, a list item or a map value.
                'tagsAny' => ['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'itemsOf:nullable-temporal', 'valuesOf:temporal', 'valuesOf:nullable-temporal'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'temporal',
                'row' => 'support matrix: "a loose `format: date-time` string" / "an impossible calendar date" (the serializer parses first)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // A string PHP's free-form parser reads (`now`, `@0`, `+1 day`) for a `format: date-time`
                // only: a `format: date` is parsed with `!Y-m-d` since 2.15.160 and has no row.
                'kinds' => ['parseableNonDate:date-time'],
                'tagsAny' => ['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'itemsOf:nullable-temporal', 'valuesOf:temporal', 'valuesOf:nullable-temporal'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'temporal',
                'row' => 'support matrix: "a loose `format: date-time` string" (the serializer parses first)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // `2020-1-2` for a `format: date`: `createFromFormat('!Y-m-d')` takes a one-digit month
                // or day, and the generated code never sees the string.
                'kinds' => ['parseableNonDate:date'],
                'tagsAll' => ['dateOneDigit'],
                'tagsAny' => ['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'itemsOf:nullable-temporal', 'valuesOf:temporal', 'valuesOf:nullable-temporal'],
                // Built by the serializer: a spelling that holds the position raw meets the callback instead.
                'built' => 'temporal',
                'row' => 'support matrix: "an impossible calendar date … or a one-digit month or day" (the serializer parses first)',
            ],
            [
                'modes' => GenerationMode::cases(),
                'category' => 'wrong-accept',
                'kinds' => ['parseableNonDate:date-time'],
                'tagsAll' => ['dateTimeNoOffset'],
                'row' => 'support matrix: the note under the keyword matrix, "A `format: date-time` with no offset … is accepted … in all five"',
            ],
            [
                // Runtime's deserializer reads `t` and `z` as their upper case since 2.15.163.
                'modes' => [GenerationMode::Laravel, GenerationMode::LaravelData, GenerationMode::Yii3],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAll' => ['lowercaseDateTime'],
                'row' => 'support matrix: "RFC 3339 spellings of a `format: date-time` property" (lowercase `t` / `z` refused)',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['nullForbidden'],
                'tagsAll' => ['site:unionWithString'],
                'row' => 'support matrix: "`null` for a `oneOf` / `anyOf` with a string member" (yii3 casts to "")',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAll' => ['unionTemporalBesideArray', 'integerBesideTemporal'],
                'row' => 'support matrix: "an integer for a `oneOf` of `format: date`, `integer` and … `array`" (yii3 reads it as a timestamp)',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAll' => ['unionTemporalBesideNull', 'integerBesideTemporal'],
                'row' => 'support matrix: "an integer for a `oneOf` of `format: date`, `integer` and `null`" (yii3 reads it as a timestamp)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-refuse',
                // A VALID `{}` for the object member of a union beside an `array` member: decoded by the
                // framework, it is the `[]` the list member judges — and `minItems` refuses.
                'kinds' => ['valid'],
                'tagsAll' => ['emptyBesideArray'],
                'row' => 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member, `[]` for the list member of a union beside an object member" (symfony and yii3 read `[]` and `{}` as one)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAll' => ['besideRequiringObject'],
                'row' => 'support matrix: "a scalar for a property typed as a generated class" (symfony/serializer 7.4.10 collects the class member\'s missing required key)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                // `objectOfPair`: the value is no instance of the member whose generated class name sorts
                // first — the class the serializer tries first ({@see FuzzRunner::tagObjectsOfAPair()}).
                'tagsAll' => ['unionObjectPair', 'objectOfPair'],
                // Which member's class the serializer tries first follows the generated class names.
                'spelling' => true,
                'row' => 'support matrix: "an object only a member whose class name does not sort first of an inline undiscriminated union of classes accepts" (symfony builds the class it tries first)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // An object BOTH object members of a `oneOf` accept: the serializer builds only the class
                // it tries first and drops the other member's keys, so the validator never sees the
                // second match ({@see FuzzSpec::confirmedByReference()} tags these `unionObjectPair`).
                'kinds' => ['matchesTwoMembers'],
                'tagsAll' => ['unionObjectPair'],
                // Which member's class the serializer tries first follows the generated class names.
                'spelling' => true,
                'row' => 'support matrix: "an object only a member whose class name does not sort first of an inline undiscriminated union of classes accepts" (symfony builds one member, and an object both members accept reaches the validator as that member alone)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                // An enum one is held as the string it arrives as since 2.15.166, and its null accepted.
                'tagsAny' => ['nullItem:nullable-temporal', 'nullMapValue:nullable-temporal'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of dates", or a `null` VALUE of such a map (symfony refuses)',
            ],
            [
                'modes' => [GenerationMode::LaravelData],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAny' => ['nullItem:nullable-dto', 'nullItem:nullable-discriminated', 'nullMapValue:nullable-dto', 'nullMapValue:nullable-discriminated'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of DTOs", or a `null` VALUE of such a map (laravel-data refuses)',
            ],
        ];
    }
}
