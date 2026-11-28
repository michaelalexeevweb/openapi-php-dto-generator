<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Schema\SchemaNormalizer;

/**
 * How close two documents are after {@see SchemaNormalizer}, as the strongest of a ladder of
 * projections under which they are equal — each projection forgets one more thing generation may
 * still care about:
 *
 * - `exact`: the normalized documents are equal (keyword order aside);
 * - `reachable`: equal once restricted to `Probe` and the components it reaches — an alias left
 *   behind by the chain it was cut out of no longer counts;
 * - `annotationFree`: equal once descriptions, titles, examples and the like are dropped too;
 * - `structural`: equal once every reference is inlined (a recursive one marked), and the result
 *   normalized again — component NAMES forgotten, which are class names;
 * - `unordered`: equal once the members of every `oneOf` / `anyOf` are sorted — order forgotten,
 *   which decides the member a hydrating mode tries first;
 * - `none`.
 */
final class NormalizedForm
{
    public const string EXACT = 'exact';

    public const string REACHABLE = 'reachable';

    public const string ANNOTATION_FREE = 'annotationFree';

    public const string STRUCTURAL = 'structural';

    public const string UNORDERED = 'unordered';

    public const string NONE = 'none';

    public const array LADDER = [self::EXACT, self::REACHABLE, self::ANNOTATION_FREE, self::STRUCTURAL, self::UNORDERED, self::NONE];

    private const string LOCAL_PREFIX = '#/components/schemas/';

    private const array ANNOTATIONS = ['description', 'title', 'deprecated', 'default', 'example', 'examples', '$comment', 'externalDocs'];

    private const array DATA_VALUED = ['enum', 'const', 'default', 'example', 'examples'];

    private const array NAME_KEYED = ['properties', 'patternProperties', 'dependentSchemas', 'schemas'];

    private const int MAX_DEPTH = 48;

    /**
     * The normalized original and the normalized rewrite, folded back into one document.
     *
     * @param array<string, mixed> $original
     * @return array{original: array<string, mixed>, rewritten: array<string, mixed>}
     */
    public static function normalizedPair(array $original, RewrittenSpec $rewritten): array
    {
        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalizeDocuments(document: $rewritten->document, externals: $rewritten->externals);
        $bundle = new RewrittenSpec(
            rewrite: $rewritten->rewrite,
            document: $normalized['document'],
            externals: $normalized['externals'],
            path: $rewritten->path,
            choiceSeed: $rewritten->choiceSeed,
            note: $rewritten->note,
        );

        /** @var array<string, mixed> $left */
        $left = $normalizer->normalize($original);

        return ['original' => $left, 'rewritten' => $bundle->bundled()];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public static function level(array $left, array $right): string
    {
        if (self::same($left, $right)) {
            return self::EXACT;
        }
        $left = self::reachable($left);
        $right = self::reachable($right);
        if (self::same($left, $right)) {
            return self::REACHABLE;
        }
        $left = self::withoutAnnotations($left);
        $right = self::withoutAnnotations($right);
        if (self::same($left, $right)) {
            return self::ANNOTATION_FREE;
        }
        $left = self::structural($left);
        $right = self::structural($right);
        if (self::same($left, $right)) {
            return self::STRUCTURAL;
        }
        if (self::same(self::unordered($left), self::unordered($right))) {
            return self::UNORDERED;
        }

        return self::NONE;
    }

    /**
     * Equality with object keys in any order, list order kept, and values compared strictly.
     */
    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    public static function canonical(mixed $value): string
    {
        return (string)json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map(self::sorted(...), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function reachable(array $document): array
    {
        $schemas = (array)($document['components']['schemas'] ?? []);
        $kept = ['Probe' => $schemas['Probe'] ?? null];
        foreach (FuzzSpec::reachableComponents(start: [$schemas['Probe'] ?? []], components: $schemas) as $name) {
            $kept[$name] = $schemas[$name];
        }
        $document['components']['schemas'] = $kept;

        return $document;
    }

    private static function withoutAnnotations(mixed $node, bool $namesKeys = false): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            if (!$namesKeys && in_array($key, self::ANNOTATIONS, true)) {
                continue;
            }
            $result[$key] = !$namesKeys && in_array($key, self::DATA_VALUED, true)
                ? $value
                : self::withoutAnnotations(node: $value, namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED, true));
        }

        return $result;
    }

    /**
     * `Probe` with every reference inlined, normalized again.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function structural(array $document): array
    {
        $schemas = (array)($document['components']['schemas'] ?? []);
        $inlined = self::inlined(node: $schemas['Probe'] ?? [], schemas: $schemas, trail: ['Probe'], depth: 0);
        $document['components']['schemas'] = ['Probe' => $inlined];

        /** @var array<string, mixed> $renormalized */
        $renormalized = (new SchemaNormalizer())->normalize($document);

        return $renormalized;
    }

    /**
     * @param array<array-key, mixed> $schemas
     * @param array<int, string> $trail
     */
    private static function inlined(mixed $node, array $schemas, array $trail, int $depth, bool $namesKeys = false): mixed
    {
        if (!is_array($node) || $depth > self::MAX_DEPTH) {
            return $node;
        }
        $ref = $namesKeys ? null : ($node['$ref'] ?? null);
        if (is_string($ref) && str_starts_with($ref, self::LOCAL_PREFIX)) {
            $name = substr($ref, strlen(self::LOCAL_PREFIX));
            $rest = [];
            foreach ($node as $key => $value) {
                if ($key !== '$ref') {
                    $rest[$key] = self::inlined(node: $value, schemas: $schemas, trail: $trail, depth: $depth + 1);
                }
            }
            $target = in_array($name, $trail, true) || !array_key_exists($name, $schemas)
                ? ['$recursive' => true]
                : self::inlined(node: $schemas[$name], schemas: $schemas, trail: [...$trail, $name], depth: $depth + 1);

            return $rest === [] ? $target : ['allOf' => [$target]] + $rest;
        }
        $result = [];
        foreach ($node as $key => $value) {
            $result[$key] = !$namesKeys && in_array($key, self::DATA_VALUED, true)
                ? $value
                : self::inlined(node: $value, schemas: $schemas, trail: $trail, depth: $depth + 1, namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED, true));
        }

        return $result;
    }

    private static function unordered(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = array_map(self::unordered(...), $node);
        foreach (['oneOf', 'anyOf'] as $op) {
            if (is_array($result[$op] ?? null) && array_is_list($result[$op])) {
                $members = $result[$op];
                usort($members, static fn(mixed $a, mixed $b): int => strcmp(self::canonical($a), self::canonical($b)));
                $result[$op] = $members;
            }
        }

        return $result;
    }
}
