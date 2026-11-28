<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

/**
 * Semantics-preserving rewrites of a fuzz document: the same schema, spelled differently. Each one is a
 * pure function document -> document, applied at ONE site — a schema reached from a chosen property of
 * `Probe`, through its sub-schemas and the components it refers to. A metamorphic run
 * ({@see MetamorphicRunner}) expects every mode to give the same verdict on every payload for the
 * rewritten document as for the original.
 *
 * Equivalence is that of JSON Schema 2020-12 (OpenAPI 3.1), plus OpenAPI 3.0 `nullable` read the way
 * {@see ReferenceOracle} reads it. A site where a rewrite would NOT be equivalent — or where the
 * equivalence is disputed — is skipped with a reason, never rewritten:
 *
 * - a member of a union with a `discriminator`, and a component such a union names: the discriminator
 *   matches a member by its `$ref` (explicit `mapping`) or by its component NAME (implicit), so wrapping,
 *   extracting or aliasing it changes which member a tag selects;
 * - a schema carrying a `discriminator` is not wrapped in `allOf`: there it reads as an inheritance parent;
 * - 3.0 `nullable: true` beside an `enum` that does not list null: 3.0.3 says null is refused, the
 *   oracle (and part of the ecosystem) says it is taken — no spelling of it is equivalent under both.
 */
final class SpecRewriter
{
    public const string WRAP_ALL_OF = 'wrapAllOf';

    public const string EXTRACT_COMPONENT = 'extractComponent';

    public const string ALIAS_CHAIN = 'aliasChain';

    public const string MOVE_TO_OTHER_FILE = 'moveToOtherFile';

    public const string ANNOTATE_REF = 'annotateRef';

    public const string NULLABLE_SPELLING = 'nullableSpelling';

    public const string SPLIT_SCALAR = 'splitScalar';

    public const string REORDER_MEMBERS = 'reorderMembers';

    /** The identity, generated from a file instead of an array: the control of {@see MOVE_TO_OTHER_FILE}. */
    public const string FILE_INPUT = 'fileInput';

    public const array REWRITES = [
        self::WRAP_ALL_OF,
        self::EXTRACT_COMPONENT,
        self::ALIAS_CHAIN,
        self::MOVE_TO_OTHER_FILE,
        self::ANNOTATE_REF,
        self::NULLABLE_SPELLING,
        self::SPLIT_SCALAR,
        self::REORDER_MEMBERS,
        self::FILE_INPUT,
    ];

    public const string OTHER_FILE = 'other.json';

    public const string SKIP_DISCRIMINATED_MEMBER = 'discriminated member';

    public const string SKIP_DISCRIMINATED_COMPONENT = 'component a discriminator names';

    public const string SKIP_DISCRIMINATOR_BEARER = 'schema carries a discriminator';

    public const string SKIP_ENUM_WITHOUT_NULL = '3.0 nullable beside an enum without null';

    private const string LOCAL_PREFIX = '#/components/schemas/';

    /** Keywords that assert nothing: they stay at the site when a rewrite moves the assertions. */
    private const array ANNOTATIONS = ['description', 'title', 'deprecated', 'default', 'example', 'examples', '$comment', 'externalDocs'];

    private const array COMPOSITIONS = ['allOf', 'anyOf', 'oneOf'];

    private const array UNIONS = ['anyOf', 'oneOf'];

    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    private const int MAX_REF_HOPS = 16;

    /**
     * The rewrite applied at a site reached from the property, the site chosen by `$random`; null when
     * no site takes it. `skips` names every site passed over because the rewrite would not be equivalent there.
     *
     * @param array<string, mixed> $document
     * @return array{rewritten: RewrittenSpec|null, skips: array<int, string>}
     */
    public static function apply(string $rewrite, array $document, string $property, FuzzRandom $random): array
    {
        $choiceSeed = $random->int(1, 2_000_000_000);
        $skips = [];
        foreach ($random->shuffle(self::sites(document: $document, property: $property)) as $path) {
            $result = self::applyAt(rewrite: $rewrite, document: $document, path: $path, choiceSeed: $choiceSeed);
            if ($result instanceof RewrittenSpec) {
                return ['rewritten' => $result, 'skips' => $skips];
            }
            if (is_string($result)) {
                $skips[] = $result;
            }
        }

        return ['rewritten' => null, 'skips' => $skips];
    }

    /**
     * The rewrite at one site: the rewritten spec, a skip reason, or null where it does not apply. The
     * same document, path and seed give the same result — which is how a finding is replayed on the
     * document restricted to one property.
     *
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     */
    public static function applyAt(string $rewrite, array $document, array $path, int $choiceSeed): RewrittenSpec|string|null
    {
        $schema = self::at(node: $document, path: $path);
        if (!is_array($schema)) {
            return null;
        }
        $choice = new FuzzRandom($choiceSeed);
        $openApi31 = !str_starts_with((string)($document['openapi'] ?? '3.1.0'), '3.0');

        return match ($rewrite) {
            self::WRAP_ALL_OF => self::wrapAllOf(document: $document, path: $path, schema: $schema),
            self::EXTRACT_COMPONENT => self::extractComponent(document: $document, path: $path, schema: $schema),
            self::ALIAS_CHAIN => self::aliasChain(document: $document, path: $path, schema: $schema),
            self::MOVE_TO_OTHER_FILE => self::moveToOtherFile(document: $document, path: $path),
            self::ANNOTATE_REF => self::annotateRef(document: $document, path: $path, schema: $schema),
            self::NULLABLE_SPELLING => self::nullableSpelling(document: $document, path: $path, schema: $schema, openApi31: $openApi31, choice: $choice),
            self::SPLIT_SCALAR => self::splitScalar(document: $document, path: $path, schema: $schema, openApi31: $openApi31, choice: $choice),
            self::REORDER_MEMBERS => self::reorderMembers(document: $document, path: $path, schema: $schema, choice: $choice),
            self::FILE_INPUT => $path === ['components', 'schemas', 'Probe']
                ? new RewrittenSpec(rewrite: $rewrite, document: $document, externals: [], path: $path, choiceSeed: $choiceSeed, note: 'identity, read from a file', asFile: true)
                : null,
            default => null,
        };
    }

    /**
     * Every schema the property reaches: itself, its sub-schemas, and the components it refers to (each
     * once), breadth first; `Probe` itself only for {@see FILE_INPUT}.
     *
     * @param array<string, mixed> $document
     * @return array<int, array<int, int|string>>
     */
    public static function sites(array $document, string $property): array
    {
        $schemas = $document['components']['schemas'] ?? [];
        $schemas = is_array($schemas) ? $schemas : [];
        $queue = [['components', 'schemas', 'Probe', 'properties', $property]];
        $seen = [];
        $paths = [['components', 'schemas', 'Probe']];
        while ($queue !== []) {
            $path = array_shift($queue);
            $schema = self::at(node: $document, path: $path);
            if (!is_array($schema)) {
                continue;
            }
            $paths[] = $path;
            foreach (self::children($schema) as $suffix) {
                $queue[] = [...$path, ...$suffix];
            }
            $name = self::localRef($schema);
            if ($name !== null && $name !== 'Probe' && array_key_exists($name, $schemas) && !in_array($name, $seen, true)) {
                $seen[] = $name;
                $queue[] = ['components', 'schemas', $name];
            }
        }

        return $paths;
    }

    /**
     * Every `$ref` (and discriminator `mapping` value) spelled `$from<Name>` re-spelled `$to<Name>` —
     * for every name, or only for `$names`.
     *
     * @param array<int, string>|null $names
     */
    public static function retargetReferences(mixed $node, string $from, string $to, ?array $names, bool $inMapping = false): mixed
    {
        if (is_string($node)) {
            return $node;
        }
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            if (($key === '$ref' || $inMapping) && is_string($value) && str_starts_with($value, $from)) {
                $name = substr($value, strlen($from));
                $result[$key] = $names === null || in_array($name, $names, true) ? $to . $name : $value;

                continue;
            }
            $result[$key] = self::retargetReferences(
                node: $value,
                from: $from,
                to: $to,
                names: $names,
                inMapping: $key === 'mapping' && is_array($value) && !array_is_list($value),
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function wrapAllOf(array $document, array $path, array $schema): RewrittenSpec|string|null
    {
        if ($schema === [] || self::isProbe($path)) {
            return null;
        }
        $bound = self::discriminatorBound(document: $document, path: $path);
        if ($bound !== null) {
            return $bound;
        }
        if (array_key_exists('discriminator', $schema)) {
            return self::SKIP_DISCRIMINATOR_BEARER;
        }

        return self::rewritten(
            rewrite: self::WRAP_ALL_OF,
            document: self::with(node: $document, path: $path, value: ['allOf' => [$schema]]),
            path: $path,
            note: 'X -> {allOf: [X]}',
        );
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function extractComponent(array $document, array $path, array $schema): RewrittenSpec|string|null
    {
        if ($schema === [] || self::isComponent($path) || array_keys($schema) === ['$ref']) {
            return null;
        }
        if (self::isDiscriminatedMember(document: $document, path: $path)) {
            return self::SKIP_DISCRIMINATED_MEMBER;
        }
        $name = self::freeName(document: $document, base: 'Extracted');
        $document['components']['schemas'][$name] = $schema;

        return self::rewritten(
            rewrite: self::EXTRACT_COMPONENT,
            document: self::with(node: $document, path: $path, value: ['$ref' => self::LOCAL_PREFIX . $name]),
            path: $path,
            note: 'inline -> components/' . $name,
        );
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function aliasChain(array $document, array $path, array $schema): RewrittenSpec|string|null
    {
        $target = self::localRef($schema);
        if ($target === null) {
            return null;
        }
        if (self::isDiscriminatedMember(document: $document, path: $path)) {
            return self::SKIP_DISCRIMINATED_MEMBER;
        }
        $alias = self::freeName(document: $document, base: $target . 'alias');
        $document['components']['schemas'][$alias] = ['$ref' => self::LOCAL_PREFIX . $target];
        $schema['$ref'] = self::LOCAL_PREFIX . $alias;

        return self::rewritten(
            rewrite: self::ALIAS_CHAIN,
            document: self::with(node: $document, path: $path, value: $schema),
            path: $path,
            note: $target . ' -> ' . $alias . ' -> ' . $target,
        );
    }

    /**
     * The component and every component it reaches move to {@see OTHER_FILE}; references to them, in
     * the root document, become `other.json#/components/schemas/<Name>`. The moved set is closed under
     * references, so the other document refers only to itself.
     *
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     */
    private static function moveToOtherFile(array $document, array $path): ?RewrittenSpec
    {
        if (!self::isComponent($path) || self::isProbe($path)) {
            return null;
        }
        $schemas = (array)$document['components']['schemas'];
        $name = (string)$path[2];
        $moved = array_values(array_unique([$name, ...FuzzSpec::reachableComponents(start: [$schemas[$name]], components: $schemas)]));
        $kept = [];
        $other = [];
        foreach ($schemas as $key => $schema) {
            if (in_array((string)$key, $moved, true)) {
                $other[$key] = $schema;
            } else {
                $kept[$key] = $schema;
            }
        }
        $document['components']['schemas'] = $kept;
        $root = self::retargetReferences(node: $document, from: self::LOCAL_PREFIX, to: self::OTHER_FILE . self::LOCAL_PREFIX, names: $moved);

        return new RewrittenSpec(
            rewrite: self::MOVE_TO_OTHER_FILE,
            document: (array)$root,
            externals: [self::OTHER_FILE => [
                'openapi' => $document['openapi'] ?? '3.1.0',
                'info' => ['title' => 'Other', 'version' => '1.0.0'],
                'components' => ['schemas' => $other],
            ]],
            path: $path,
            choiceSeed: 0,
            note: 'moved ' . implode(', ', $moved) . ' to ' . self::OTHER_FILE,
            asFile: true,
        );
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function annotateRef(array $document, array $path, array $schema): ?RewrittenSpec
    {
        if (self::localRef($schema) === null) {
            return null;
        }
        $existing = $schema['description'] ?? null;
        $schema['description'] = (is_string($existing) ? $existing . ' ' : '') . 'An annotated reference.';

        return self::rewritten(
            rewrite: self::ANNOTATE_REF,
            document: self::with(node: $document, path: $path, value: $schema),
            path: $path,
            note: '{$ref} -> {$ref, description}',
        );
    }

    /**
     * 3.0: `{type: T, nullable: true, …}` <-> `{allOf: [$ref C], nullable: true}` with C the non-null
     * core. 3.1: `{type: [T, "null"], …}` <-> `{anyOf|oneOf: [{type: T, …}, {type: "null"}]}`, and
     * `anyOf` <-> `oneOf` for such a pair when the other member cannot be null.
     *
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function nullableSpelling(array $document, array $path, array $schema, bool $openApi31, FuzzRandom $choice): RewrittenSpec|string|null
    {
        $options = [];
        $skip = null;
        if (!$openApi31) {
            if (($schema['nullable'] ?? null) === true && is_string($schema['type'] ?? null) && !self::composes($schema)) {
                if (self::enumWithoutNull($schema)) {
                    $skip = self::SKIP_ENUM_WITHOUT_NULL;
                } elseif (self::discriminatorBound(document: $document, path: $path) === null) {
                    $options[] = 'inlineToAllOfRef';
                }
            }
            if (self::allOfRefTarget(document: $document, schema: $schema) !== null) {
                $options[] = 'allOfRefToInline';
            }
        } else {
            $type = $schema['type'] ?? null;
            if (is_array($type) && count($type) === 2 && in_array('null', $type, true) && !self::composes($schema) && !array_key_exists('const', $schema)) {
                if (self::enumWithoutNull($schema)) {
                    $skip = 'type list beside an enum without null';
                } else {
                    $options[] = 'typeListToUnion';
                }
            }
            $pair = self::nullPair($schema);
            if ($pair !== null) {
                $other = $schema[$pair['op']][$pair['other']];
                if (self::isPlainTyped($other) && !array_key_exists('const', $other)) {
                    $options[] = 'unionToTypeList';
                }
                if (self::cannotBeNull(document: $document, schema: $other)) {
                    $options[] = 'swapUnionKeyword';
                }
            }
        }
        if ($options === []) {
            return $skip;
        }
        $option = $choice->pick($options);
        $annotations = self::annotationsOf($schema);

        if ($option === 'inlineToAllOfRef') {
            $core = self::withoutNullInEnum(self::assertionsOf(schema: $schema, keep: ['type']));
            if (($core['enum'] ?? null) === []) {
                return 'enum of null alone';
            }
            $name = self::freeName(document: $document, base: 'Nonnull');
            $document['components']['schemas'][$name] = $core;
            $replacement = $annotations + ['allOf' => [['$ref' => self::LOCAL_PREFIX . $name]], 'nullable' => true];
        } elseif ($option === 'allOfRefToInline') {
            $target = (array)self::allOfRefTarget(document: $document, schema: $schema);
            $replacement = array_merge(self::withNullInEnum($target), ['nullable' => true], $annotations);
        } elseif ($option === 'typeListToUnion') {
            $type = (array)$schema['type'];
            $branch = self::withoutNullInEnum(self::assertionsOf(schema: $schema, keep: ['type']));
            $branch['type'] = array_values(array_filter($type, static fn(mixed $member): bool => $member !== 'null'))[0];
            if (($branch['enum'] ?? null) === []) {
                return 'enum of null alone';
            }
            $replacement = $annotations + [$choice->pick(self::UNIONS) => [$branch, ['type' => 'null']]];
        } elseif ($option === 'unionToTypeList') {
            $pair = (array)self::nullPair($schema);
            $other = $schema[$pair['op']][$pair['other']];
            $inline = self::withNullInEnum($other);
            $inline['type'] = [$other['type'], 'null'];
            $replacement = array_merge($inline, $annotations);
        } else {
            $pair = (array)self::nullPair($schema);
            $swapped = $pair['op'] === 'anyOf' ? 'oneOf' : 'anyOf';
            $replacement = [];
            foreach ($schema as $key => $value) {
                $replacement[$key === $pair['op'] ? $swapped : $key] = $value;
            }
        }

        return self::rewritten(
            rewrite: self::NULLABLE_SPELLING,
            document: self::with(node: $document, path: $path, value: $replacement),
            path: $path,
            note: $option,
        );
    }

    /**
     * `{type: T, <assertions>}` -> `{allOf: [{type: T}, {<assertions>}]}` (branch order chosen), the
     * annotations staying at the site and a 3.0 `nullable` staying beside its `type`.
     *
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function splitScalar(array $document, array $path, array $schema, bool $openApi31, FuzzRandom $choice): RewrittenSpec|string|null
    {
        $type = $schema['type'] ?? null;
        $scalar = is_string($type)
            ? in_array($type, self::SCALAR_TYPES, true)
            : is_array($type) && count($type) === 2 && in_array('null', $type, true)
                && array_intersect($type, self::SCALAR_TYPES) !== [];
        if (!$scalar || self::composes($schema) || array_key_exists('discriminator', $schema)) {
            return null;
        }
        $assertions = self::assertionsOf(schema: $schema, keep: []);
        $assertions = array_filter($assertions, static fn(string $key): bool => $key !== 'type' && $key !== 'nullable', ARRAY_FILTER_USE_KEY);
        if ($assertions === []) {
            return null;
        }
        if (!$openApi31 && ($schema['nullable'] ?? null) === true && self::enumWithoutNull($schema)) {
            return self::SKIP_ENUM_WITHOUT_NULL;
        }
        $typed = ['type' => $type];
        if (array_key_exists('nullable', $schema)) {
            $typed['nullable'] = $schema['nullable'];
        }
        $branches = $choice->chance(0.5) ? [$typed, $assertions] : [$assertions, $typed];

        return self::rewritten(
            rewrite: self::SPLIT_SCALAR,
            document: self::with(node: $document, path: $path, value: self::annotationsOf($schema) + ['allOf' => $branches]),
            path: $path,
            note: 'split ' . implode(', ', array_keys($assertions)),
        );
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     * @param array<mixed> $schema
     */
    private static function reorderMembers(array $document, array $path, array $schema, FuzzRandom $choice): ?RewrittenSpec
    {
        $unions = array_values(array_filter(
            array: self::UNIONS,
            callback: static fn(string $op): bool => is_array($schema[$op] ?? null) && array_is_list($schema[$op]) && count($schema[$op]) >= 2,
        ));
        if ($unions === []) {
            return null;
        }
        $op = $choice->pick($unions);
        $members = $schema[$op];
        $reordered = $choice->shuffle($members);
        if (json_encode($reordered) === json_encode($members)) {
            $reordered = array_reverse($members);
        }
        if (json_encode($reordered) === json_encode($members)) {
            return null;
        }
        $schema[$op] = $reordered;
        $mapping = $schema['discriminator']['mapping'] ?? null;
        if (is_array($mapping) && count($mapping) >= 2) {
            $schema['discriminator']['mapping'] = array_reverse($mapping, true);
        }

        return self::rewritten(
            rewrite: self::REORDER_MEMBERS,
            document: self::with(node: $document, path: $path, value: $schema),
            path: $path,
            note: 'permuted ' . $op,
        );
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     */
    private static function rewritten(string $rewrite, array $document, array $path, string $note): RewrittenSpec
    {
        return new RewrittenSpec(rewrite: $rewrite, document: $document, externals: [], path: $path, choiceSeed: 0, note: $note);
    }

    /**
     * Why the site is tied to a discriminator, or null.
     *
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     */
    private static function discriminatorBound(array $document, array $path): ?string
    {
        if (self::isDiscriminatedMember(document: $document, path: $path)) {
            return self::SKIP_DISCRIMINATED_MEMBER;
        }
        if (self::isComponent($path) && in_array((string)$path[2], self::discriminatedComponents($document), true)) {
            return self::SKIP_DISCRIMINATED_COMPONENT;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, int|string> $path
     */
    private static function isDiscriminatedMember(array $document, array $path): bool
    {
        if (count($path) < 2 || !in_array($path[count($path) - 2], self::UNIONS, true)) {
            return false;
        }
        $parent = self::at(node: $document, path: array_slice($path, 0, -2));

        return is_array($parent) && array_key_exists('discriminator', $parent);
    }

    /**
     * The components a discriminator names: the `$ref` members of its union and its mapping targets.
     *
     * @param array<string, mixed> $document
     * @return array<int, string>
     */
    private static function discriminatedComponents(array $document): array
    {
        $names = [];
        $queue = [$document];
        while ($queue !== []) {
            $node = array_pop($queue);
            if (array_key_exists('discriminator', $node)) {
                foreach (self::UNIONS as $op) {
                    foreach (is_array($node[$op] ?? null) ? $node[$op] : [] as $member) {
                        $name = is_array($member) ? self::localRef($member) : null;
                        if ($name !== null) {
                            $names[] = $name;
                        }
                    }
                }
                $mapping = $node['discriminator']['mapping'] ?? [];
                foreach (is_array($mapping) ? $mapping : [] as $target) {
                    if (is_string($target) && str_starts_with($target, self::LOCAL_PREFIX)) {
                        $names[] = substr($target, strlen(self::LOCAL_PREFIX));
                    }
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The component a 3.0 `{allOf: [$ref C], nullable: true}` (annotations aside) refers to, when C is
     * a plain typed schema without `nullable` that can be written inline instead.
     *
     * @param array<string, mixed> $document
     * @param array<mixed> $schema
     * @return array<string, mixed>|null
     */
    private static function allOfRefTarget(array $document, array $schema): ?array
    {
        if (($schema['nullable'] ?? null) !== true || !is_array($schema['allOf'] ?? null) || count($schema['allOf']) !== 1) {
            return null;
        }
        $rest = array_diff(array_keys($schema), ['allOf', 'nullable', ...self::ANNOTATIONS]);
        $member = $schema['allOf'][0];
        if ($rest !== [] || !is_array($member) || array_keys($member) !== ['$ref']) {
            return null;
        }
        $name = self::localRef($member);
        $target = $name === null ? null : ($document['components']['schemas'][$name] ?? null);
        if (!is_array($target) || !self::isPlainTyped($target) || array_key_exists('nullable', $target) || array_key_exists('discriminator', $target)) {
            return null;
        }

        return $target;
    }

    /**
     * The `anyOf` / `oneOf` of exactly two members, one of them `{type: "null"}`, with nothing but
     * annotations beside it.
     *
     * @param array<mixed> $schema
     * @return array{op: string, other: int}|null
     */
    private static function nullPair(array $schema): ?array
    {
        foreach (self::UNIONS as $op) {
            $members = $schema[$op] ?? null;
            if (!is_array($members) || count($members) !== 2 || !array_is_list($members)) {
                continue;
            }
            if (array_diff(array_keys($schema), [$op, ...self::ANNOTATIONS]) !== []) {
                return null;
            }
            foreach ([0, 1] as $index) {
                if ($members[$index] === ['type' => 'null'] && is_array($members[1 - $index])) {
                    return ['op' => $op, 'other' => 1 - $index];
                }
            }
        }

        return null;
    }

    /**
     * Whether the schema has one non-null `type` and nothing that composes or refers.
     *
     * @param array<mixed> $schema
     */
    private static function isPlainTyped(array $schema): bool
    {
        return is_string($schema['type'] ?? null) && $schema['type'] !== 'null' && !self::composes($schema) && !array_key_exists('nullable', $schema);
    }

    /**
     * Whether no value of the schema is null: its own (or, through `$ref`s, its target's) `type` is
     * one non-null type, and no 3.0 `nullable` widens it.
     *
     * @param array<string, mixed> $document
     * @param array<mixed> $schema
     */
    private static function cannotBeNull(array $document, array $schema): bool
    {
        for ($hop = 0; $hop < self::MAX_REF_HOPS; $hop++) {
            if (($schema['nullable'] ?? null) === true) {
                return false;
            }
            if (is_string($schema['type'] ?? null) && $schema['type'] !== 'null') {
                return true;
            }
            $name = self::localRef($schema);
            $target = $name === null ? null : ($document['components']['schemas'][$name] ?? null);
            if (!is_array($target)) {
                return false;
            }
            $schema = $target;
        }

        return false;
    }

    /**
     * @param array<mixed> $schema
     */
    private static function composes(array $schema): bool
    {
        return array_key_exists('$ref', $schema) || array_intersect(array_keys($schema), [...self::COMPOSITIONS, 'not', 'discriminator']) !== [];
    }

    /**
     * @param array<mixed> $schema
     */
    private static function enumWithoutNull(array $schema): bool
    {
        return is_array($schema['enum'] ?? null) && !in_array(null, $schema['enum'], true);
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function withoutNullInEnum(array $schema): array
    {
        if (is_array($schema['enum'] ?? null)) {
            $schema['enum'] = array_values(array_filter($schema['enum'], static fn(mixed $member): bool => $member !== null));
        }

        return $schema;
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function withNullInEnum(array $schema): array
    {
        if (is_array($schema['enum'] ?? null) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'] = [...$schema['enum'], null];
        }

        return $schema;
    }

    /**
     * @param array<mixed> $schema
     * @return array<string, mixed>
     */
    private static function annotationsOf(array $schema): array
    {
        $annotations = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, self::ANNOTATIONS, true)) {
                $annotations[$key] = $value;
            }
        }

        return $annotations;
    }

    /**
     * Everything but the annotations and `nullable`, except the keys in `$keep`, which stay whatever they are.
     *
     * @param array<mixed> $schema
     * @param array<int, string> $keep
     * @return array<string, mixed>
     */
    private static function assertionsOf(array $schema, array $keep): array
    {
        $assertions = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, $keep, true) || (!in_array($key, self::ANNOTATIONS, true) && $key !== 'nullable')) {
                $assertions[(string)$key] = $value;
            }
        }

        return $assertions;
    }

    /**
     * @param array<mixed> $schema
     * @return array<int, array<int, int|string>>
     */
    private static function children(array $schema): array
    {
        $children = [];
        if (is_array($schema['properties'] ?? null)) {
            foreach (array_keys($schema['properties']) as $name) {
                $children[] = ['properties', $name];
            }
        }
        foreach (['items', 'additionalProperties', 'not'] as $keyword) {
            if (is_array($schema[$keyword] ?? null) && ($schema[$keyword] === [] || !array_is_list($schema[$keyword]))) {
                $children[] = [$keyword];
            }
        }
        foreach ([...self::COMPOSITIONS, 'prefixItems'] as $keyword) {
            if (is_array($schema[$keyword] ?? null) && array_is_list($schema[$keyword])) {
                foreach (array_keys($schema[$keyword]) as $index) {
                    $children[] = [$keyword, $index];
                }
            }
        }

        return $children;
    }

    /**
     * @param array<mixed> $schema
     */
    private static function localRef(array $schema): ?string
    {
        $ref = $schema['$ref'] ?? null;

        return is_string($ref) && str_starts_with($ref, self::LOCAL_PREFIX) ? substr($ref, strlen(self::LOCAL_PREFIX)) : null;
    }

    /**
     * @param array<int, int|string> $path
     */
    private static function isComponent(array $path): bool
    {
        return count($path) === 3 && $path[0] === 'components' && $path[1] === 'schemas';
    }

    /**
     * @param array<int, int|string> $path
     */
    private static function isProbe(array $path): bool
    {
        return $path === ['components', 'schemas', 'Probe'];
    }

    /**
     * A component name not yet taken (compared without case: it becomes a class name).
     *
     * @param array<string, mixed> $document
     */
    private static function freeName(array $document, string $base): string
    {
        $taken = array_map(
            callback: static fn(int|string $name): string => strtolower((string)$name),
            array: array_keys((array)($document['components']['schemas'] ?? [])),
        );
        $name = $base;
        for ($suffix = 2; in_array(strtolower($name), $taken, true); $suffix++) {
            $name = $base . $suffix;
        }

        return $name;
    }

    /**
     * @param array<mixed> $node
     * @param array<int, int|string> $path
     */
    private static function at(array $node, array $path): mixed
    {
        $current = $node;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<mixed> $node
     * @param array<int, int|string> $path
     * @return array<mixed>
     */
    private static function with(array $node, array $path, mixed $value): array
    {
        $key = $path[0];
        $rest = array_slice($path, 1);
        $child = $node[$key] ?? [];
        $node[$key] = $rest === [] ? $value : self::with(node: is_array($child) ? $child : [], path: $rest, value: $value);

        return $node;
    }
}
