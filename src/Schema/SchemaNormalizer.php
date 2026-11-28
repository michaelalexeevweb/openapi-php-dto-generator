<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Schema;

/**
 * Rewrites an OpenAPI document into its canonical spelling before anything classifies it: the same
 * schema written two equivalent ways comes out written ONE way, so the generator meets one spelling
 * instead of every wrapper a document may put around it. Pure: arrays in, arrays out, the OpenAPI
 * shape kept — the output is still a document the generator reads as before.
 *
 * Every rule is semantics-preserving under the reading this library documents (README.validation.md:
 * 3.0 `nullable` beside a `$ref` honoured, 3.0 `nullable` admitting null beside an `enum`); a site
 * where a rule is not equivalent — or where generation reads the two spellings differently (a class
 * name, an inheritance link, a discriminator match) — is left as written. The rules and what they must
 * not touch are listed in `.local/3.0-normalizer.md`.
 *
 * Applied bottom-up, each node to a fixpoint. Generation runs {@see GENERATION_RULES} only:
 *
 * 1. `{allOf|oneOf|anyOf: [M]}` — one member — is M merged with its siblings when those only annotate
 *    (or are a 3.0 `nullable`), the two sides do not disagree on a keyword and no keyword reads one on
 *    the other side (`additionalProperties` reads `properties`, a 3.0 boolean `exclusiveMinimum` reads
 *    `minimum`, …). A bare `$ref` member of an `allOf` is the reference itself when nothing stands beside
 *    the wrapper. Kept: the wrapper around a `$ref` at the top of a component (inheritance or a union
 *    interface, a class of its own), a one-member UNION of a `$ref` anywhere (held as a union, not as the
 *    class), beside a `discriminator`, and as a direct member of a discriminated union (matched by `$ref`);
 * 2. `{allOf: [F1, …, Fn]}` whose fragments only say scalar things (`type`, `enum`, bounds, `format`)
 *    is one flat scalar schema — unless a 3.0 `nullable` fragment meets an `enum` / `const` without null
 *    or a second `type` (the fragment without `nullable` refuses null);
 * 3. 3.1 `anyOf|oneOf: [S, {type: null}]` with S one plainly typed schema is `S` with `type: [T, "null"]`
 *    (and `null` added to its `enum`); a remaining two-member union with `{type: null}` whose other
 *    member cannot be null is spelled `anyOf`; a `type` list puts `"null"` last;
 * 4. 3.0 `{$ref: X, nullable: true}` is `{allOf: [{$ref: X}], nullable: true}`, the idiom the
 *    specification itself offers (not at the top of a component, where `allOf` is inheritance);
 *    `nullable: true` beside an `enum` lists null in the `enum` as well — the library reads both as
 *    admitting null;
 * 5. a `$ref` to a component that is nothing but another `$ref` (`A: {$ref: B}`) names the end of the
 *    chain — the generator redirects such a reference already, so no class name changes. Not for a
 *    member of a discriminated union, nor a `mapping` value: a discriminator matches by component name.
 *
 * @internal
 */
final class SchemaNormalizer
{
    private const string LOCAL_PREFIX = '#/components/schemas/';

    /** A data value: never a schema, kept byte for byte. */
    private const array DATA_VALUED_KEYWORDS = ['enum', 'const', 'default', 'example', 'examples'];

    /** Keywords and OpenAPI containers whose value maps NAMES to schemas or objects. */
    private const array NAME_KEYED_KEYWORDS = [
        'properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions', 'schemas', 'responses',
        'content', 'headers', 'requestBodies', 'securitySchemes', 'links', 'callbacks', 'encoding', 'paths',
        'pathItems', 'webhooks',
    ];

    /** Keywords that assert nothing about a value. */
    private const array ANNOTATION_KEYWORDS = [
        'description', 'title', 'deprecated', 'default', 'example', 'examples', '$comment', 'externalDocs',
        'readOnly', 'writeOnly', 'xml',
    ];

    private const array COMPOSITIONS = ['allOf', 'oneOf', 'anyOf'];

    private const array UNIONS = ['oneOf', 'anyOf'];

    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /** What a scalar `allOf` fragment may say and still be folded into its siblings. */
    private const array SCALAR_FRAGMENT_KEYWORDS = [
        'type', 'format', 'enum', 'const', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum',
        'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'nullable',
    ];

    /** Keywords whose meaning depends on a neighbour: a merge never puts them on two sides. */
    private const array INTERACTING_GROUPS = [
        ['properties', 'additionalProperties', 'patternProperties'],
        ['items', 'prefixItems', 'additionalItems'],
        ['contains', 'minContains', 'maxContains'],
        ['if', 'then', 'else'],
        ['contentEncoding', 'contentMediaType', 'contentSchema'],
    ];

    /** Keywords a merge never moves: they name, scope or read the whole schema. */
    private const array UNMERGEABLE_KEYWORDS = [
        '$ref', 'discriminator', 'unevaluatedProperties', 'unevaluatedItems', '$id', '$anchor',
        '$dynamicAnchor', '$dynamicRef', '$defs', '$schema',
    ];

    /** Rule 1: one-member compositions collapse. */
    public const string RULE_SINGLE_MEMBER = 'singleMember';

    /** Rule 2: scalar `allOf` fragments fold. */
    public const string RULE_SCALAR_FRAGMENTS = 'scalarFragments';

    /** Rule 3, the fold: a 3.1 null union of a plain schema is a `type` list. */
    public const string RULE_NULL_UNION = 'nullUnion';

    /** Rule 4, the reference half: 3.0 `{$ref, nullable}` is the `allOf` idiom. */
    public const string RULE_NULLABLE_REFERENCE = 'nullableReference';

    /** Rules 3 and 4, the spelling: null last, `anyOf` for a null pair, null listed in a 3.0 nullable `enum`. */
    public const string RULE_NULL_SPELLING = 'nullSpelling';

    /** Rule 5: alias chains followed. */
    public const string RULE_ALIAS_CHAINS = 'aliasChains';

    public const array ALL_RULES = [
        self::RULE_SINGLE_MEMBER,
        self::RULE_SCALAR_FRAGMENTS,
        self::RULE_NULL_UNION,
        self::RULE_NULLABLE_REFERENCE,
        self::RULE_NULL_SPELLING,
        self::RULE_ALIAS_CHAINS,
    ];

    /**
     * The rules generation runs today. The others are sound, but the generator still reads their canonical
     * spelling differently from the one they replace — see `.local/3.0-normalizer.md`, "Wiring": each is
     * a spelling-dependent class plan or check to settle before the rule can run in front of it.
     */
    public const array GENERATION_RULES = [
        self::RULE_SCALAR_FRAGMENTS,
        self::RULE_ALIAS_CHAINS,
    ];

    private const int MAX_ALIAS_HOPS = 32;

    private const string POSITION_COMPONENT = 'component';

    private const string POSITION_DISCRIMINATED_MEMBER = 'discriminatedMember';

    private const string POSITION_OTHER = 'other';

    private bool $openApi30 = false;

    /**
     * The documents as given — the root under '' — for reading what a `$ref` names.
     *
     * @var array<array-key, array<array-key, mixed>>
     */
    private array $documents = [];

    /** The document being shaped: '' for the root, else its file as a `$ref` spells it. */
    private string $currentFile = '';

    /**
     * @param list<string> $rules the rules applied, of {@see ALL_RULES}
     */
    public function __construct(
        private readonly array $rules = self::ALL_RULES,
    ) {
    }

    /**
     * The document in its canonical spelling. `$externals` (file as a `$ref` spells it => document) lets
     * an alias chain be followed into another file; a document without its own `openapi` reads as
     * `$assumedVersion` (the root's, for a referenced file), else as 3.1.
     *
     * @param array<array-key, mixed> $document
     * @param array<string, array<array-key, mixed>> $externals
     * @return array<array-key, mixed>
     */
    public function normalize(array $document, array $externals = [], ?string $assumedVersion = null): array
    {
        return $this->normalizeDocuments(document: $document, externals: $externals, assumedVersion: $assumedVersion)['document'];
    }

    /**
     * The root document and every external one in canonical spelling.
     *
     * @param array<array-key, mixed> $document
     * @param array<string, array<array-key, mixed>> $externals
     * @return array{document: array<array-key, mixed>, externals: array<string, array<array-key, mixed>>}
     */
    public function normalizeDocuments(array $document, array $externals = [], ?string $assumedVersion = null): array
    {
        $version = $document['openapi'] ?? null;
        $version = is_string($version) ? $version : ($assumedVersion ?? '3.1.0');
        $this->documents = ['' => $document] + $externals;
        $this->currentFile = '';
        $shaped = $this->shaped(document: $document, version: $version);
        $shapedExternals = [];
        foreach ($externals as $file => $external) {
            $this->currentFile = $file;
            $own = $external['openapi'] ?? null;
            $shapedExternals[$file] = $this->shaped(document: $external, version: is_string($own) ? $own : $version);
        }
        $this->documents = [];

        $retargeted = [];
        foreach ($shapedExternals as $file => $external) {
            $retargeted[$file] = (array)$this->withAliasesFollowed(node: $external, file: $file, root: $shaped, externals: $shapedExternals);
        }

        return [
            'document' => (array)$this->withAliasesFollowed(node: $shaped, file: null, root: $shaped, externals: $shapedExternals),
            'externals' => $retargeted,
        ];
    }

    /**
     * Rules 1–4 over one document.
     *
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private function shaped(array $document, string $version): array
    {
        $this->openApi30 = str_starts_with($version, '3.0');

        return (array)$this->walk(node: $document, namesKeys: false, position: self::POSITION_OTHER, path: []);
    }

    /**
     * @param list<int|string> $path the keys from the document root, for knowing a component's top
     */
    private function walk(mixed $node, bool $namesKeys, string $position, array $path): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $discriminated = !$namesKeys && is_array($node['discriminator'] ?? null);
        $result = [];
        foreach ($node as $key => $value) {
            if (!$namesKeys && (in_array($key, self::DATA_VALUED_KEYWORDS, true) || str_starts_with((string)$key, 'x-') || $key === 'discriminator')) {
                $result[$key] = $value;

                continue;
            }
            $childPath = [...$path, $key];
            $result[$key] = $this->walkChild(
                key: $key,
                value: $value,
                parentNamesKeys: $namesKeys,
                discriminated: $discriminated,
                path: $childPath,
            );
        }
        if ($namesKeys) {
            return $result;
        }

        return $this->canonical(schema: $result, position: $position);
    }

    /**
     * @param list<int|string> $path
     */
    private function walkChild(int|string $key, mixed $value, bool $parentNamesKeys, bool $discriminated, array $path): mixed
    {
        if (!$parentNamesKeys && in_array($key, self::UNIONS, true) && is_array($value) && array_is_list($value)) {
            $members = [];
            foreach ($value as $index => $member) {
                $members[$index] = $this->walk(
                    node: $member,
                    namesKeys: false,
                    position: $discriminated ? self::POSITION_DISCRIMINATED_MEMBER : self::POSITION_OTHER,
                    path: [...$path, $index],
                );
            }

            return $members;
        }
        $isComponent = count($path) === 3 && $path[0] === 'components' && $path[1] === 'schemas';

        return $this->walk(
            node: $value,
            namesKeys: !$parentNamesKeys && in_array($key, self::NAME_KEYED_KEYWORDS, true),
            position: $isComponent ? self::POSITION_COMPONENT : self::POSITION_OTHER,
            path: $path,
        );
    }

    /**
     * One schema node, its children already canonical, to a fixpoint of rules 1–4.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function canonical(array $schema, string $position): array
    {
        for ($round = 0; $round < 64; $round++) {
            $next = ($this->applies(self::RULE_SINGLE_MEMBER) ? $this->collapsedSingleMember(schema: $schema, position: $position) : null)
                ?? ($this->applies(self::RULE_SCALAR_FRAGMENTS) ? $this->foldedScalarFragments($schema) : null)
                ?? ($this->applies(self::RULE_NULL_UNION) ? $this->foldedNullUnion($schema) : null)
                ?? ($this->applies(self::RULE_NULLABLE_REFERENCE) ? $this->nullableReferenceWrapped(schema: $schema, position: $position) : null);
            if ($next === null) {
                break;
            }
            $schema = $next;
        }

        return $this->applies(self::RULE_NULL_SPELLING) ? $this->withNullSpelledOnce($schema) : $schema;
    }

    private function applies(string $rule): bool
    {
        return in_array($rule, $this->rules, true);
    }

    /**
     * Rule 1.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|null
     */
    private function collapsedSingleMember(array $schema, string $position): ?array
    {
        foreach (self::COMPOSITIONS as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || count($members) !== 1 || !array_is_list($members) || !is_array($members[0])) {
                continue;
            }
            if (array_key_exists('discriminator', $schema)) {
                return null;
            }
            $member = $members[0];
            $siblings = $this->without(schema: $schema, keyword: $keyword);
            if (array_key_exists('$ref', $member)) {
                // A bare wrapper around a reference is the reference — but at a component's top it is a
                // class of its own (`allOf` = inheritance, a union = an interface), and a discriminated
                // member is matched by its `$ref`. A one-member UNION of a reference is kept anywhere:
                // the generator holds it as a union (decoded, checked by the interpreter) where the bare
                // reference is a class — collapsing it changes a property's PHP type, which is a 3.0
                // decision of its own (`.local/3.0-normalizer.md`), not a spelling.
                if ($siblings !== [] || $position !== self::POSITION_OTHER || $keyword !== 'allOf') {
                    return null;
                }

                return $member;
            }

            // Beside asserting siblings the wrapper stays: `{allOf: [{properties}], oneOf: [...]}` flattened
            // to `{properties, oneOf}` is the same schema, but the generator writes it back without the
            // properties (`.local/3.0-normalizer.md`, "Wiring").
            if (!$this->onlyAnnotations($siblings)) {
                return null;
            }

            return $this->merged(first: $member, second: $siblings);
        }

        return null;
    }

    /**
     * Rule 2.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|null
     */
    private function foldedScalarFragments(array $schema): ?array
    {
        $fragments = $schema['allOf'] ?? null;
        if (!is_array($fragments) || count($fragments) < 2 || !array_is_list($fragments)) {
            return null;
        }
        $siblings = $this->without(schema: $schema, keyword: 'allOf');
        foreach (array_keys($siblings) as $keyword) {
            if (!$this->annotates($keyword)) {
                return null;
            }
        }
        $typed = 0;
        $nullableFragment = null;
        foreach ($fragments as $index => $fragment) {
            if (!is_array($fragment) || $fragment === [] || array_is_list($fragment)) {
                return null;
            }
            foreach (array_keys($fragment) as $keyword) {
                if (!in_array($keyword, self::SCALAR_FRAGMENT_KEYWORDS, true) && !$this->annotates($keyword)) {
                    return null;
                }
            }
            if (array_key_exists('type', $fragment)) {
                $typed++;
                if (!$this->isScalarType($fragment['type'])) {
                    return null;
                }
            }
            if (array_key_exists('nullable', $fragment)) {
                if ($fragment['nullable'] !== true || !array_key_exists('type', $fragment) || $nullableFragment !== null) {
                    return null;
                }
                $nullableFragment = $index;
            }
        }
        if ($typed === 0) {
            return null;
        }
        if ($nullableFragment !== null) {
            // The fragments without `nullable` refuse null wherever they say a `type`, an `enum` or a
            // `const` that does not list it: folded beside `nullable`, they would take it.
            foreach ($fragments as $index => $fragment) {
                if ($index !== $nullableFragment && ($this->refusesNull($fragment) || array_key_exists('type', $fragment))) {
                    return null;
                }
            }
        }

        $folded = [];
        foreach ($fragments as $fragment) {
            // Which fragment may carry `nullable` is settled above.
            $next = $this->merged(first: $folded, second: $fragment, nullableChecked: false);
            if ($next === null) {
                return null;
            }
            $folded = $next;
        }

        return $this->merged(first: $folded, second: $siblings);
    }

    /**
     * Rule 3, the fold: 3.1 `anyOf|oneOf: [S, {type: null}]` as `S` with `"null"` in its `type`.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|null
     */
    private function foldedNullUnion(array $schema): ?array
    {
        if ($this->openApi30) {
            return null;
        }
        $pair = $this->nullPair($schema);
        if ($pair === null) {
            return null;
        }
        $other = $pair['other'];
        $type = $other['type'] ?? null;
        if (
            !is_string($type)
            || $type === 'null'
            || array_key_exists('const', $other)
            || array_key_exists('nullable', $other)
            || array_intersect(array_keys($other), [...self::COMPOSITIONS, 'not', '$ref', 'discriminator', 'if']) !== []
        ) {
            return null;
        }
        $other['type'] = [$type, 'null'];
        if (is_array($other['enum'] ?? null) && !in_array(null, $other['enum'], true)) {
            $other['enum'] = [...$other['enum'], null];
        }

        return $this->merged(first: $other, second: $this->without(schema: $schema, keyword: $pair['op']));
    }

    /**
     * Rule 4, the 3.0 half: `{$ref: X, nullable: true}` as `{allOf: [{$ref: X}], nullable: true}`.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|null
     */
    private function nullableReferenceWrapped(array $schema, string $position): ?array
    {
        if (!$this->openApi30 || $position !== self::POSITION_OTHER || !is_string($schema['$ref'] ?? null) || ($schema['nullable'] ?? null) !== true) {
            return null;
        }
        $wrapped = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword === '$ref') {
                continue;
            }
            if (!$this->annotates($keyword) && $keyword !== 'nullable') {
                return null;
            }
            $wrapped[$keyword] = $value;
        }

        return ['allOf' => [['$ref' => $schema['$ref']]]] + $wrapped;
    }

    /**
     * Rules 3 and 4, the spelling of what is already one null: a `type` list puts `"null"` last; a 3.1
     * two-member union with `{type: null}` whose other member cannot be null is an `anyOf`; a 3.0
     * `nullable: true` beside an `enum` lists null in the `enum`.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function withNullSpelledOnce(array $schema): array
    {
        $type = $schema['type'] ?? null;
        if (is_array($type) && array_is_list($type) && in_array('null', $type, true) && end($type) !== 'null') {
            $schema['type'] = [...array_values(array_filter($type, static fn(mixed $member): bool => $member !== 'null')), 'null'];
        }
        if ($this->openApi30 && ($schema['nullable'] ?? null) === true && is_array($schema['enum'] ?? null) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'] = [...$schema['enum'], null];
        }
        $pair = $this->openApi30 ? null : $this->nullPair($schema);
        if ($pair !== null) {
            // The null member matches null alone, so where it stands decides nothing: it stands last.
            $op = $pair['op'] === 'oneOf' && $this->cannotBeNull(schema: $pair['other'], file: $this->currentFile) ? 'anyOf' : $pair['op'];
            $renamed = [];
            foreach ($schema as $keyword => $value) {
                $renamed[$keyword === $pair['op'] ? $op : $keyword] = $keyword === $pair['op'] ? [$pair['other'], ['type' => 'null']] : $value;
            }
            $schema = $renamed;
        }

        return $schema;
    }

    /**
     * Rule 5: every schema `$ref` to a pure alias component names the end of its chain.
     *
     * @param array<array-key, mixed> $root
     * @param array<string, array<array-key, mixed>> $externals
     */
    private function withAliasesFollowed(mixed $node, ?string $file, array $root, array $externals, bool $namesKeys = false, bool $discriminatedMember = false): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $discriminated = !$namesKeys && is_array($node['discriminator'] ?? null);
        $result = [];
        foreach ($node as $key => $value) {
            if (!$namesKeys && (in_array($key, self::DATA_VALUED_KEYWORDS, true) || str_starts_with((string)$key, 'x-') || $key === 'discriminator')) {
                $result[$key] = $value;

                continue;
            }
            if (!$namesKeys && $key === '$ref' && is_string($value)) {
                $result[$key] = $discriminatedMember || !$this->applies(self::RULE_ALIAS_CHAINS) ? $value : $this->aliasEnd(ref: $value, file: $file, root: $root, externals: $externals);

                continue;
            }
            if (!$namesKeys && in_array($key, self::UNIONS, true) && is_array($value) && array_is_list($value)) {
                $members = [];
                foreach ($value as $index => $member) {
                    $members[$index] = $this->withAliasesFollowed(node: $member, file: $file, root: $root, externals: $externals, discriminatedMember: $discriminated);
                }
                $result[$key] = $members;

                continue;
            }
            $result[$key] = $this->withAliasesFollowed(
                node: $value,
                file: $file,
                root: $root,
                externals: $externals,
                namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED_KEYWORDS, true),
            );
        }

        return $result;
    }

    /**
     * The reference at the end of a chain of pure aliases, spelled from the document `$file` (null:
     * the root); the reference itself when it names no alias.
     *
     * @param array<array-key, mixed> $root
     * @param array<string, array<array-key, mixed>> $externals
     */
    private function aliasEnd(string $ref, ?string $file, array $root, array $externals): string
    {
        $current = $ref;
        $seen = [];
        for ($hop = 0; $hop < self::MAX_ALIAS_HOPS; $hop++) {
            $located = $this->located(ref: $current, file: $file, root: $root, externals: $externals);
            if ($located === null || in_array($located['key'], $seen, true)) {
                return $current;
            }
            $seen[] = $located['key'];
            $body = $located['body'];
            if (!$this->isPureAlias($body)) {
                return $current;
            }
            $next = $this->spelledFrom(ref: $body['$ref'], holder: $located['file'], reader: $file);
            if ($next === null) {
                return $current;
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * A component body that only names another one: `{$ref: B}`, annotations beside it at most — what
     * the generator redirects every reference to anyway. A 3.0 `nullable` alias is not one here: the
     * reference would have to carry the `nullable`.
     *
     * @phpstan-assert-if-true array{'$ref': string} $body
     */
    private function isPureAlias(mixed $body): bool
    {
        if (!is_array($body) || !is_string($body['$ref'] ?? null)) {
            return false;
        }
        foreach (array_keys($body) as $keyword) {
            if ($keyword !== '$ref' && !$this->annotates($keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The component a schema reference names, read from `$file`.
     *
     * @param array<array-key, mixed> $root
     * @param array<string, array<array-key, mixed>> $externals
     * @return array{key: string, file: string|null, body: mixed}|null
     */
    private function located(string $ref, ?string $file, array $root, array $externals): ?array
    {
        $hash = strpos($ref, '#');
        if ($hash === false) {
            return null;
        }
        $target = $hash === 0 ? $file : substr($ref, 0, $hash);
        $pointer = substr($ref, $hash);
        if (!str_starts_with($pointer, self::LOCAL_PREFIX)) {
            return null;
        }
        $name = substr($pointer, strlen(self::LOCAL_PREFIX));
        if ($name === '' || str_contains($name, '/')) {
            return null;
        }
        $document = $target === null ? $root : ($externals[$target] ?? null);
        if (!is_array($document)) {
            return null;
        }
        $schemas = $document['components']['schemas'] ?? null;
        if (!is_array($schemas) || !array_key_exists($name, $schemas)) {
            return null;
        }

        return ['key' => ($target ?? '') . '#' . $name, 'file' => $target, 'body' => $schemas[$name]];
    }

    /**
     * A reference written in document `$holder`, spelled as document `$reader` would write it; null
     * when the reader cannot name it (a reference back into the root from another file).
     */
    private function spelledFrom(string $ref, ?string $holder, ?string $reader): ?string
    {
        $hash = strpos($ref, '#');
        if ($hash === false) {
            return null;
        }
        $target = $hash === 0 ? $holder : substr($ref, 0, $hash);
        $pointer = substr($ref, $hash);
        if ($target === $reader) {
            return $pointer;
        }

        return $target === null ? null : $target . $pointer;
    }

    /**
     * M merged with S — null when a keyword on both sides disagrees, a keyword would land beside a
     * neighbour that reads it, or the merge would move what must stay put.
     *
     * @param array<array-key, mixed> $first
     * @param array<array-key, mixed> $second
     * @return array<array-key, mixed>|null
     */
    private function merged(array $first, array $second, bool $nullableChecked = true): ?array
    {
        if ($second === []) {
            return $first;
        }
        if ($first === []) {
            return array_intersect(array_keys($second), self::UNMERGEABLE_KEYWORDS) === [] ? $second : null;
        }
        $firstKeys = array_keys($first);
        $secondKeys = array_keys($second);
        if (array_intersect([...$firstKeys, ...$secondKeys], self::UNMERGEABLE_KEYWORDS) !== []) {
            return null;
        }
        foreach (self::INTERACTING_GROUPS as $group) {
            if (array_intersect($firstKeys, $group) !== [] && array_intersect($secondKeys, $group) !== []) {
                if (array_intersect($firstKeys, $group) !== array_intersect($secondKeys, $group)) {
                    return null;
                }
            }
        }
        if ($this->splitsABooleanBound(first: $first, second: $second)) {
            return null;
        }
        // A 3.0 `nullable` on the inner side widens only what it stands beside: next to an outer
        // assertion it would widen that one too.
        if ($nullableChecked && ($first['nullable'] ?? null) === true && !$this->onlyAnnotations($second)) {
            return null;
        }
        if ($nullableChecked && ($second['nullable'] ?? null) === true && $this->openApi30 && $this->composesOrRefers($first)) {
            return null;
        }
        $merged = $first;
        foreach ($second as $keyword => $value) {
            if (array_key_exists($keyword, $merged) && $merged[$keyword] !== $value) {
                return null;
            }
            $merged[$keyword] = $value;
        }

        return $merged;
    }

    /**
     * Whether a 3.0 boolean `exclusiveMinimum` / `exclusiveMaximum` and the bound it modifies would
     * come from different sides.
     *
     * @param array<array-key, mixed> $first
     * @param array<array-key, mixed> $second
     */
    private function splitsABooleanBound(array $first, array $second): bool
    {
        foreach (['minimum' => 'exclusiveMinimum', 'maximum' => 'exclusiveMaximum'] as $bound => $exclusive) {
            foreach ([[$first, $second], [$second, $first]] as [$one, $other]) {
                if (is_bool($one[$exclusive] ?? null) && !array_key_exists($bound, $one) && array_key_exists($bound, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rule 3's shape: a two-member union, one member `{type: null}`, nothing but annotations beside it.
     *
     * @param array<array-key, mixed> $schema
     * @return array{op: string, other: array<array-key, mixed>}|null
     */
    private function nullPair(array $schema): ?array
    {
        foreach (self::UNIONS as $op) {
            $members = $schema[$op] ?? null;
            if (!is_array($members) || count($members) !== 2 || !array_is_list($members)) {
                continue;
            }
            foreach (array_keys($schema) as $keyword) {
                if ($keyword !== $op && !$this->annotates($keyword)) {
                    return null;
                }
            }
            foreach ([0, 1] as $index) {
                if ($members[$index] === ['type' => 'null'] && is_array($members[1 - $index])) {
                    return ['op' => $op, 'other' => $members[1 - $index]];
                }
            }
        }

        return null;
    }

    /**
     * Whether no value of the schema is null: a non-null `type` (and no 3.0 `nullable`), an `enum` or
     * `const` without null, an `allOf` member that cannot be null, or a `$ref` to such a schema.
     *
     * @param array<array-key, mixed> $schema
     */
    private function cannotBeNull(array $schema, string $file = '', int $hops = 0): bool
    {
        if ($hops > self::MAX_ALIAS_HOPS || ($schema['nullable'] ?? null) === true) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if ((is_string($type) && $type !== 'null') || (is_array($type) && $type !== [] && !in_array('null', $type, true))) {
            return true;
        }
        if ($this->refusesNull($schema)) {
            return true;
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $member) {
            if (is_array($member) && $this->cannotBeNull(schema: $member, file: $file, hops: $hops + 1)) {
                return true;
            }
        }
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return false;
        }
        $hash = strpos($ref, '#');
        if ($hash === false || !str_starts_with(substr($ref, $hash), self::LOCAL_PREFIX)) {
            return false;
        }
        $target = $hash === 0 ? $file : substr($ref, 0, $hash);
        $name = substr($ref, $hash + strlen(self::LOCAL_PREFIX));
        $body = $this->documents[$target]['components']['schemas'][$name] ?? null;

        return is_array($body) && $this->cannotBeNull(schema: $body, file: $target, hops: $hops + 1);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function refusesNull(array $schema): bool
    {
        foreach (['enum', 'const'] as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            $value = $schema[$keyword];
            if ($keyword === 'const' ? $value !== null : (!is_array($value) || !in_array(null, $value, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the schema composes, refers or negates — a 3.0 `nullable` moved beside it would have
     * to reach into what it holds.
     *
     * @param array<array-key, mixed> $schema
     */
    private function composesOrRefers(array $schema): bool
    {
        return array_intersect(array_keys($schema), [...self::COMPOSITIONS, 'not', '$ref', 'if']) !== [];
    }

    private function isScalarType(mixed $type): bool
    {
        if (is_string($type)) {
            return in_array($type, self::SCALAR_TYPES, true);
        }
        if (!is_array($type) || !array_is_list($type) || $type === []) {
            return false;
        }
        $scalars = 0;
        foreach ($type as $member) {
            if ($member === 'null') {
                continue;
            }
            if (!is_string($member) || !in_array($member, self::SCALAR_TYPES, true)) {
                return false;
            }
            $scalars++;
        }

        return $scalars === 1;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function onlyAnnotations(array $schema): bool
    {
        foreach (array_keys($schema) as $keyword) {
            if (!$this->annotates($keyword) && $keyword !== 'nullable') {
                return false;
            }
        }

        return true;
    }

    private function annotates(int|string $keyword): bool
    {
        return in_array($keyword, self::ANNOTATION_KEYWORDS, true) || str_starts_with((string)$keyword, 'x-');
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function without(array $schema, string $keyword): array
    {
        $rest = [];
        foreach ($schema as $key => $value) {
            if ($key !== $keyword) {
                $rest[$key] = $value;
            }
        }

        return $rest;
    }
}
