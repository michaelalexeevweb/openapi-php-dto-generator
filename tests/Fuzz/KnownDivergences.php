<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;

/**
 * The DOCUMENTED divergences, so the fuzzer does not report them as findings.
 *
 * Each entry names the README.support-matrix.md row (or the open item) it stands for. An entry matches
 * by mode, by what went wrong (`wrong-accept` / `wrong-refuse`), by mutation kind (prefix match; `valid`
 * for the valid payload) and by tags: `tagsAll` must all be present, `tagsAny` at least one. Keep the
 * entries NARROW — an entry that matches more than its row hides real defects.
 */
final class KnownDivergences
{
    /**
     * @param array<int, string> $tags
     */
    public static function explain(GenerationMode $mode, string $category, string $kind, array $tags): ?string
    {
        foreach (self::entries() as $entry) {
            if (!in_array($mode, $entry['modes'], true) || $entry['category'] !== $category) {
                continue;
            }
            if (!self::kindMatches(kind: $kind, prefixes: $entry['kinds'])) {
                continue;
            }
            if (array_diff($entry['tagsAll'] ?? [], $tags) !== []) {
                continue;
            }
            if (($entry['tagsAny'] ?? []) !== [] && array_intersect($entry['tagsAny'], $tags) === []) {
                continue;
            }

            return $entry['row'];
        }

        return null;
    }

    /**
     * A crash or a refusal at a union COMPONENT of two objects and no discriminator (tagged
     * `undiscriminatedComponent`) in a mode the generator WARNED, for a component this property reaches,
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
     * @return array<int, array{modes: array<int, GenerationMode>, category: string, kinds: array<int, string>, tagsAll?: array<int, string>, tagsAny?: array<int, string>, row: string}>
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
                'row' => 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member" (symfony and yii3 read `[]` and `{}` as one)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['wrongType:array'],
                // `unionClassMember`: the row's own last sentence — a union of a class and a scalar is
                // read the same way, the JSON array built into the class.
                'tagsAny' => ['site:dto', 'site:nullable-dto', 'site:map', 'site:nullable-map', 'site:discriminated', 'site:nullable-discriminated', 'unionClassMember'],
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
                'row' => 'support matrix: "`additionalProperties: false` / `unevaluatedProperties: false` on a DTO-shaped schema" and "a key `additionalProperties: {schema}` admits beside the named properties" (symfony, a member of an undiscriminated union of classes included)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAll' => ['in:additional'],
                'row' => 'support matrix: "a key `additionalProperties: {schema}` admits beside the named properties" (dropped unchecked)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                'kinds' => ['impossibleDate:', 'looseDateTime'],
                // Only where the serializer meets a date: the property, a list item or a map value.
                'tagsAny' => ['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'itemsOf:nullable-temporal', 'valuesOf:temporal', 'valuesOf:nullable-temporal'],
                'row' => 'support matrix: "a loose `format: date-time` string" / "an impossible calendar date" (the serializer parses first)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-accept',
                // A string PHP's free-form parser reads (`now`, `@0`, `+1 day`) for a `format: date-time`
                // only: a `format: date` is parsed with `!Y-m-d` since 2.15.160 and has no row.
                'kinds' => ['parseableNonDate:date-time'],
                'tagsAny' => ['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'itemsOf:nullable-temporal', 'valuesOf:temporal', 'valuesOf:nullable-temporal'],
                'row' => 'support matrix: "a loose `format: date-time` string" (the serializer parses first)',
            ],
            [
                'modes' => GenerationMode::cases(),
                'category' => 'wrong-accept',
                'kinds' => ['parseableNonDate:date-time'],
                'tagsAll' => ['dateTimeNoOffset'],
                'row' => 'support matrix: the note under the keyword matrix, "A `format: date-time` with no offset … is accepted … in all five"',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['parseableNonDate:date-time'],
                'tagsAll' => ['dateTimeOffsetWithoutColon'],
                'row' => 'support matrix: "RFC 3339 spellings of a `format: date-time` property" (yii3 reads `+0200` with `p`)',
            ],
            [
                'modes' => [GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData, GenerationMode::Yii3],
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
                'row' => 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member" (symfony and yii3 read `[]` and `{}` as one)',
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
                'row' => 'support matrix: "an object only a member whose class name does not sort first of an inline undiscriminated union of classes accepts" (symfony builds one member, and an object both members accept reaches the validator as that member alone)',
            ],
            [
                'modes' => [GenerationMode::Symfony],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAny' => ['nullItem:nullable-temporal', 'nullItem:nullable-enum', 'nullMapValue:nullable-temporal', 'nullMapValue:nullable-enum'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of dates or enums", or a `null` VALUE of such a map (symfony refuses)',
            ],
            [
                'modes' => [GenerationMode::LaravelData],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAny' => ['nullItem:nullable-dto', 'nullItem:nullable-discriminated', 'nullMapValue:nullable-dto', 'nullMapValue:nullable-discriminated'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of DTOs", or a `null` VALUE of such a map (laravel-data refuses)',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['nullForbidden', 'wrongType:'],
                'tagsAny' => ['itemsOf:dto', 'itemsOf:discriminated'],
                'row' => 'support matrix: "a non-object ITEM of a list or map of DTOs" (yii3 drops it)',
            ],
        ];
    }
}
