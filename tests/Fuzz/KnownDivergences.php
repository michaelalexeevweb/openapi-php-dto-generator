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
                // A union with a class member too: the row's "union of such a class and a scalar".
                'tagsAny' => ['site:dto', 'site:nullable-dto', 'unionClassMember'],
                'row' => 'support matrix: "a scalar for a property typed as a generated class" (symfony/serializer 7.4.10 builds an empty object)',
            ],
            [
                'modes' => [GenerationMode::Symfony, GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['extraKeyClosed'],
                'tagsAny' => ['site:dto', 'site:nullable-dto'],
                'row' => 'support matrix: "`additionalProperties: false` / `unevaluatedProperties: false` on a DTO-shaped schema"',
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
                'row' => 'support matrix: "a loose `format: date-time` string" / "an impossible calendar date" (the serializer parses first)',
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
                'tagsAll' => ['unionTemporalBesideArray', 'site:integer'],
                'row' => 'support matrix: "an integer for a `oneOf` of `format: date`, `integer` and … `array`" (yii3 reads it as a timestamp)',
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
                'tagsAny' => ['nullItem:nullable-temporal', 'nullItem:nullable-enum', 'nullMapValue:nullable-temporal', 'nullMapValue:nullable-enum'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of dates or enums", or a `null` VALUE of such a map (symfony refuses)',
            ],
            [
                'modes' => [GenerationMode::LaravelData],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAny' => ['nullItem:nullable-dto', 'nullItem:nullable-discriminated'],
                'row' => 'support matrix: "a `null` ITEM of a nullable list of DTOs" (laravel-data refuses)',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => ['nullForbidden', 'wrongType:'],
                'tagsAny' => ['itemsOf:dto', 'itemsOf:discriminated'],
                'row' => 'support matrix: "a non-object ITEM of a list or map of DTOs" (yii3 drops it)',
            ],
            [
                'modes' => [GenerationMode::Yii3],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAny' => ['itemsOf:nullable-dto'],
                'row' => 'support matrix footnote 3: yii3 does not check the OBJECT items of a nullable list of DTOs',
            ],
            [
                'modes' => [GenerationMode::LaravelData],
                'category' => 'wrong-accept',
                'kinds' => [''],
                'tagsAll' => ['inlineDiscriminator'],
                'row' => 'support matrix: "an inline `discriminator` on a property (no component base)" (laravel-data validates the first member)',
            ],
            [
                'modes' => [GenerationMode::LaravelData],
                'category' => 'wrong-refuse',
                'kinds' => ['valid'],
                'tagsAll' => ['inlineDiscriminator'],
                'row' => 'support matrix: "an inline `discriminator` on a property (no component base)" (laravel-data validates the first member)',
            ],
        ];
    }
}
