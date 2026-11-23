<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use RuntimeException;

/**
 * Resolves the references JSON Schema 2020-12 makes through schema RESOURCES — `$anchor`,
 * `$dynamicAnchor`/`$dynamicRef`, an `$id` (absolute, relative to the resource around it, or a URN)
 * and a JSON pointer into an embedded resource — and rewrites each one into the
 * `#/components/schemas/…` pointer the rest of the generator understands.
 *
 * The rest of the generator reads a `$ref` as a document pointer or a file path, nothing else. A
 * reference by anchor or by `$id` matched neither, typed its property `mixed` and dropped every
 * constraint of the schema it meant — generation reported success, and the generated code accepted
 * what the document refuses. Resolving here, once, before anything else reads the document, keeps
 * every later step unchanged.
 *
 * - Every `$id` is resolved against the base of the resource around it (RFC 3986 §5.2) and indexed
 *   with its location, as are `$anchor` and `$dynamicAnchor` under the base they belong to.
 * - A reference resolves against the base of the schema it sits in. A target that IS a component
 *   (or a root `$defs` entry) becomes a pointer to it; any other target is hoisted into
 *   `components.schemas` under a fresh name, ONCE — every reference to it names the same class.
 * - A `$dynamicRef` follows the 2020-12 dynamic scope. The scope a schema is evaluated in is known
 *   statically along each path of references, so a schema whose dynamic references bind differently
 *   on different paths is hoisted once per binding (the generic list: `items: {$dynamicRef: '#item'}`
 *   bound by the resource that references the list).
 * - What names nothing — an anchor nobody declares, an `$id` the document does not contain, a remote
 *   URL — stops generation with the reference named, as an unresolvable pointer already does.
 *
 * Plain pointers in the document's own resource and relative file references are left exactly as
 * written: the generator's existing resolution (and its `--ref` confinement) handles them.
 */
final class SchemaResourceResolver
{
    /**
     * The base of a document that declares none (`$self`) and was not read from a file: relative
     * `$id`s still need something to resolve against. Reserved TLD, so it can never name a real
     * resource.
     */
    public const string ARRAY_DOCUMENT_BASE = 'https://openapi-php-dto-generator.invalid/document';

    /**
     * Keywords whose value is data, not a schema: a `$ref` or an `$id` inside an `enum` member is a
     * value to compare against.
     */
    private const array DATA_KEYWORDS = ['const', 'enum', 'default', 'examples', 'example'];

    /**
     * The keywords that identify a schema rather than constrain it.
     */
    private const array RESOURCE_KEYWORDS = ['$id', '$anchor', '$dynamicAnchor'];

    /**
     * Keywords whose meaning depends on other keywords of the same schema object (or which the
     * unevaluated* keywords read through it); two schemas carrying them cannot be merged into one.
     */
    private const array SIBLING_KEYWORDS = [
        'properties',
        'patternProperties',
        'additionalProperties',
        'unevaluatedProperties',
        'prefixItems',
        'items',
        'unevaluatedItems',
        'contains',
        'minContains',
        'maxContains',
        'if',
        'then',
        'else',
    ];

    /**
     * Keywords that annotate and never constrain: two schemas both carrying one can still be merged.
     */
    private const array ANNOTATION_KEYWORDS = ['$comment', 'title', 'description', 'examples', 'example', 'deprecated', '$schema'];

    /**
     * Keywords that make a schema a class (or a family of them) rather than a value constraint: a
     * reference to such a schema is hoisted into one component; any other is inlined where it is
     * referenced, as the generator handles a scalar best.
     */
    private const array CLASS_KEYWORDS = [
        'properties',
        'patternProperties',
        'additionalProperties',
        'unevaluatedProperties',
        'allOf',
        'oneOf',
        'anyOf',
        'discriminator',
        'required',
    ];

    /**
     * Keywords whose value maps NAMES to schemas (or to other objects): a property called `$id` is a
     * name, never an identifier.
     */
    private const array NAME_MAP_KEYWORDS = [
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
        'dependentSchemas',
        'schemas',
        'responses',
        'requestBodies',
        'headers',
        'content',
        'pathItems',
        'callbacks',
        'paths',
        'links',
        'parameters',
        'securitySchemes',
    ];

    /**
     * How many hoisted schemas one document may need. Each distinct (location, dynamic scope) pair is
     * one, and the scopes are finite, so this is never reached by a document that terminates — it is
     * the stop for one that would not.
     */
    private const int HOISTED_LIMIT = 500;

    /**
     * @var array<mixed>
     */
    private array $document = [];

    private string $documentBase = self::ARRAY_DOCUMENT_BASE;

    /**
     * Resource URI (without fragment) → locations declaring it. More than one is an ambiguity
     * reported only when a reference needs it.
     *
     * @var array<string, list<list<int|string>>>
     */
    private array $resources = [];

    /**
     * `resource#name` → locations of `$anchor` AND `$dynamicAnchor` (a dynamic anchor is an anchor too).
     *
     * @var array<string, list<list<int|string>>>
     */
    private array $anchors = [];

    /**
     * `resource#name` → locations of `$dynamicAnchor` only.
     *
     * @var array<string, list<list<int|string>>>
     */
    private array $dynamicAnchors = [];

    /**
     * Anchor names some `$dynamicRef` looks up; only resources declaring one of them can change what
     * a dynamic reference resolves to, so only they distinguish two dynamic scopes.
     *
     * @var array<string, true>
     */
    private array $dynamicNames = [];

    /**
     * `location|scope` → name of the hoisted component.
     *
     * @var array<string, string>
     */
    private array $hoistedNames = [];

    /**
     * Hoisted component name → its schema, in the order they were named.
     *
     * @var array<string, mixed>
     */
    private array $hoisted = [];

    /**
     * Folded names (lowercase, alphanumerics only) already taken by a component.
     *
     * @var array<string, true>
     */
    private array $takenNames = [];

    private bool $changed = false;

    /**
     * `location|scope` keys being inlined right now — reached again, the target is recursive and
     * becomes a component instead.
     *
     * @var array<string, bool>
     */
    private array $inlining = [];

    /**
     * The document with every resource reference rewritten into a component pointer. A document
     * that needs nothing rewritten is returned as it came, byte for byte.
     *
     * @param array<mixed> $document
     * @return array<mixed>
     */
    public function resolve(array $document, string $documentBase): array
    {
        if (!self::mentionsResourceKeywords($document)) {
            return $document;
        }

        $this->document = $document;
        $self = $document['$self'] ?? null;
        $base = self::stripFragment($documentBase);
        $this->documentBase = is_string($self) && $self !== '' ? self::stripFragment(self::resolveUri(base: $base, reference: $self)) : $base;
        $this->resources = [$this->documentBase => [[]]];
        $this->anchors = [];
        $this->dynamicAnchors = [];
        $this->dynamicNames = [];
        $this->hoistedNames = [];
        $this->hoisted = [];
        $this->changed = false;
        $this->inlining = [];
        $this->takenNames = [];
        $schemas = $document['components']['schemas'] ?? null;
        foreach (is_array($schemas) ? array_keys($schemas) : [] as $name) {
            $this->takenNames[self::foldName((string)$name)] = true;
        }
        foreach (is_array($document['$defs'] ?? null) ? array_keys($document['$defs']) : [] as $name) {
            $this->takenNames[self::foldName((string)$name)] = true;
        }

        $this->index(node: $document, path: [], base: $this->documentBase, isNameMap: false);
        $rewritten = $this->rewrite(
            node: $document,
            path: [],
            base: $this->documentBase,
            scope: [$this->documentBase],
            isNameMap: false,
        );
        if (!$this->changed || !is_array($rewritten)) {
            return $document;
        }

        if ($this->hoisted !== []) {
            $components = is_array($rewritten['components'] ?? null) ? $rewritten['components'] : [];
            $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
            foreach ($this->hoisted as $name => $schema) {
                $schemas[$name] = $schema;
            }
            $components['schemas'] = $schemas;
            $rewritten['components'] = $components;
        }

        return $rewritten;
    }

    /**
     * RFC 3986 §5.2 reference resolution, for the URIs `$id` and `$ref` carry — including the
     * non-hierarchical ones (`urn:uuid:…`), which take a fragment-only reference and nothing else.
     */
    public static function resolveUri(string $base, string $reference): string
    {
        $parsedReference = self::parseUri($reference);
        if ($parsedReference['scheme'] !== null) {
            return self::composeUri(
                scheme: $parsedReference['scheme'],
                authority: $parsedReference['authority'],
                path: self::removeDotSegments($parsedReference['path']),
                query: $parsedReference['query'],
                fragment: $parsedReference['fragment'],
            );
        }

        $parsedBase = self::parseUri($base);
        if ($parsedReference['authority'] !== null) {
            return self::composeUri(
                scheme: $parsedBase['scheme'],
                authority: $parsedReference['authority'],
                path: self::removeDotSegments($parsedReference['path']),
                query: $parsedReference['query'],
                fragment: $parsedReference['fragment'],
            );
        }

        if ($parsedReference['path'] === '') {
            return self::composeUri(
                scheme: $parsedBase['scheme'],
                authority: $parsedBase['authority'],
                path: $parsedBase['path'],
                query: $parsedReference['query'] ?? $parsedBase['query'],
                fragment: $parsedReference['fragment'],
            );
        }

        if (str_starts_with($parsedReference['path'], '/')) {
            $path = self::removeDotSegments($parsedReference['path']);
        } elseif ($parsedBase['authority'] !== null && $parsedBase['path'] === '') {
            $path = self::removeDotSegments('/' . $parsedReference['path']);
        } else {
            $slash = strrpos($parsedBase['path'], '/');
            $directory = $slash === false ? '' : substr($parsedBase['path'], 0, $slash + 1);
            $path = self::removeDotSegments($directory . $parsedReference['path']);
        }

        return self::composeUri(
            scheme: $parsedBase['scheme'],
            authority: $parsedBase['authority'],
            path: $path,
            query: $parsedReference['query'],
            fragment: $parsedReference['fragment'],
        );
    }

    /**
     * Whether anything in the document can need this resolver: a resource keyword, or a `$ref` that
     * is neither a local pointer nor a relative file. A document without them — every document the
     * generator read before 2020-12 resources — is not walked twice.
     */
    private static function mentionsResourceKeywords(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }
        foreach ($node as $key => $value) {
            if (in_array($key, ['$id', '$anchor', '$dynamicAnchor', '$dynamicRef'], true) && is_string($value)) {
                return true;
            }
            if ($key === '$ref' && is_string($value) && self::needsResolution($value)) {
                return true;
            }
            if (is_array($value) && self::mentionsResourceKeywords($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An absolute URI, or a fragment that is an anchor rather than a pointer.
     */
    private static function needsResolution(string $reference): bool
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1) {
            return true;
        }
        $hash = strpos($reference, '#');
        if ($hash === false) {
            return false;
        }
        $fragment = substr($reference, $hash + 1);

        return $fragment !== '' && !str_starts_with($fragment, '/');
    }

    /**
     * @param list<int|string> $path
     */
    private function index(mixed $node, array $path, string $base, bool $isNameMap): void
    {
        if (!is_array($node)) {
            return;
        }
        if ($isNameMap) {
            foreach ($node as $key => $child) {
                $this->index(node: $child, path: [...$path, $key], base: $base, isNameMap: false);
            }

            return;
        }

        $isObject = !array_is_list($node);
        if ($isObject) {
            $identifier = $node['$id'] ?? null;
            if (is_string($identifier)) {
                $base = self::stripFragment(self::resolveUri(base: $base, reference: $identifier));
                if ($path !== []) {
                    $this->resources[$base][] = $path;
                }
            }
            $anchor = $node['$anchor'] ?? null;
            if (is_string($anchor)) {
                $this->anchors[$base . '#' . $anchor][] = $path;
            }
            $dynamicAnchor = $node['$dynamicAnchor'] ?? null;
            if (is_string($dynamicAnchor)) {
                $this->anchors[$base . '#' . $dynamicAnchor][] = $path;
                $this->dynamicAnchors[$base . '#' . $dynamicAnchor][] = $path;
            }
            $dynamicRef = $node['$dynamicRef'] ?? null;
            if (is_string($dynamicRef)) {
                $fragment = self::fragmentOf($dynamicRef);
                if ($fragment !== null && $fragment !== '' && !str_starts_with($fragment, '/')) {
                    $this->dynamicNames[$fragment] = true;
                }
            }
        }

        foreach ($node as $key => $child) {
            if ($isObject && in_array($key, self::DATA_KEYWORDS, true)) {
                continue;
            }
            $this->index(
                node: $child,
                path: [...$path, $key],
                base: $base,
                isNameMap: $isObject && in_array($key, self::NAME_MAP_KEYWORDS, true),
            );
        }
    }

    /**
     * The node with every reference below it rewritten for the dynamic scope it is evaluated in.
     *
     * @param list<int|string> $path
     * @param list<string> $scope resources entered so far, outermost first
     */
    private function rewrite(mixed $node, array $path, string $base, array $scope, bool $isNameMap): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if ($isNameMap) {
            $rewritten = [];
            foreach ($node as $key => $child) {
                $rewritten[$key] = $this->rewrite(node: $child, path: [...$path, $key], base: $base, scope: $scope, isNameMap: false);
            }

            return $rewritten;
        }

        $isObject = !array_is_list($node);
        if ($isObject) {
            $identifier = $node['$id'] ?? null;
            if (is_string($identifier)) {
                $base = self::stripFragment(self::resolveUri(base: $base, reference: $identifier));
                $scope = [...$scope, $base];
            }
        }

        $rewritten = [];
        $targets = [];
        foreach ($node as $key => $child) {
            if ($isObject && in_array($key, self::RESOURCE_KEYWORDS, true) && is_string($child)) {
                // Served their purpose once every reference is resolved; left in place, they made
                // a scalar alias look like more than one to the rest of the generator.
                continue;
            }
            if ($isObject && in_array($key, self::DATA_KEYWORDS, true)) {
                $rewritten[$key] = $child;

                continue;
            }
            if ($isObject && ($key === '$ref' || $key === '$dynamicRef') && is_string($child)) {
                $target = $this->rewriteReference(reference: $child, base: $base, scope: $scope, dynamic: $key === '$dynamicRef');
                if ($key === '$ref' && is_string($target) && !array_key_exists('$ref', $rewritten)) {
                    $rewritten[$key] = $target;
                } else {
                    // A `$dynamicRef` becomes a plain reference: the generator knows no other.
                    $this->changed = true;
                    $targets[] = $target;
                }

                continue;
            }
            $rewritten[$key] = $this->rewrite(
                node: $child,
                path: [...$path, $key],
                base: $base,
                scope: $scope,
                isNameMap: $isObject && in_array($key, self::NAME_MAP_KEYWORDS, true),
            );
        }

        foreach ($targets as $target) {
            $rewritten = self::withTarget(schema: $rewritten, target: $target);
            if (is_bool($rewritten)) {
                return $rewritten;
            }
        }

        return $rewritten;
    }

    /**
     * The schema with a resolved reference applied beside what it already says. A pointer becomes
     * its `$ref` (or one more `allOf` branch when it has one); an inlined schema is merged in when
     * nothing of it interacts with the siblings, an `allOf` branch otherwise; a boolean `true` adds
     * nothing, and a reference that is ALL the schema says is replaced by what it names.
     *
     * @param array<mixed> $schema
     * @param string|bool|array<mixed> $target
     * @return array<mixed>|bool
     */
    private static function withTarget(array $schema, string|bool|array $target): array|bool
    {
        if ($target === true) {
            return $schema;
        }
        if ($schema === []) {
            return is_string($target) ? ['$ref' => $target] : $target;
        }
        if (is_string($target) && !array_key_exists('$ref', $schema)) {
            $schema['$ref'] = $target;

            return $schema;
        }
        if (is_array($target) && self::mergeable(schema: $schema, inlined: $target)) {
            // The referencing schema's own annotations win: a `$comment` or a `description` on both
            // sides says nothing about the value.
            return $schema + $target;
        }
        $allOf = is_array($schema['allOf'] ?? null) ? array_values($schema['allOf']) : [];
        $allOf[] = is_string($target) ? ['$ref' => $target] : $target;
        $schema['allOf'] = $allOf;

        return $schema;
    }

    /**
     * Whether two schemas can be one object without changing what either says: no keyword in both,
     * and not both carrying keywords that read their siblings (`additionalProperties` sees only the
     * `properties` beside it — 2020-12 keeps a `$ref` target in its own scope for exactly that).
     *
     * @param array<mixed> $schema
     * @param array<mixed> $inlined
     */
    private static function mergeable(array $schema, array $inlined): bool
    {
        $annotations = array_flip(self::ANNOTATION_KEYWORDS);
        if (array_is_list($inlined) || array_diff_key(array_intersect_key($schema, $inlined), $annotations) !== []) {
            return false;
        }

        $readsSiblings = static fn(array $node): bool => array_intersect_key($node, array_flip(self::SIBLING_KEYWORDS)) !== [];

        return !($readsSiblings($schema) && $readsSiblings($inlined));
    }

    /**
     * The pointer a reference becomes, the schema it names inlined, or the reference unchanged when
     * the generator's own resolution already handles it.
     *
     * @param list<string> $scope
     * @return string|bool|array<mixed>
     */
    private function rewriteReference(string $reference, string $base, array $scope, bool $dynamic): string|bool|array
    {
        $isPointer = $reference === '#' || str_starts_with($reference, '#/');
        if (!$dynamic && $isPointer && $base === $this->documentBase) {
            return $reference;
        }

        $resolved = self::resolveUri(base: $base, reference: $reference);
        $documentUri = self::stripFragment($resolved);
        $fragment = rawurldecode(self::fragmentOf($resolved) ?? '');
        $isAnchor = $fragment !== '' && !str_starts_with($fragment, '/');

        if (!array_key_exists($documentUri, $this->resources)) {
            return $this->unknownResource(reference: $reference, isAnchor: $isAnchor, dynamic: $dynamic);
        }

        $resourcePath = $this->single(
            locations: $this->resources[$documentUri],
            reference: $reference,
            what: sprintf('the resource "%s" is declared more than once', $documentUri),
        );
        if ($isAnchor) {
            $path = $this->single(
                locations: $this->anchors[$documentUri . '#' . $fragment] ?? [],
                reference: $reference,
                what: sprintf('no schema of the resource "%s" declares the anchor "%s"', $documentUri, $fragment),
            );
        } else {
            $path = [...$resourcePath, ...self::pointerSegments($fragment)];
            if ($resourcePath === [] && !$dynamic) {
                // The document's own resource: a pointer the generator resolves as written, so
                // `$self#/components/…` and `#/components/…` stay what they always were.
                $local = '#' . $fragment;

                if ($local !== $reference) {
                    $this->changed = true;
                }

                return $local;
            }
            if (!$this->exists($path)) {
                if ($isPointer && $this->exists(self::pointerSegments($fragment))) {
                    // A document pointer written inside an embedded resource: by the letter of
                    // 2020-12 it is relative to that resource, but where the resource has no such
                    // location the document has always been read as meaning its own.
                    return $reference;
                }

                throw new RuntimeException(message: sprintf(
                    'Unresolvable reference "%s": the pointer leads to nothing inside the resource "%s".',
                    $reference,
                    $documentUri,
                ));
            }
        }

        if ($dynamic && $isAnchor) {
            $path = $this->dynamicTarget(path: $path, name: $fragment, scope: $scope, reference: $reference);
        }

        $this->changed = true;

        return $this->pointerTo(path: $path, scope: $scope, nameHint: $isAnchor ? $fragment : null);
    }

    /**
     * Where a reference that names no resource of this document goes: a relative file is the
     * generator's to read; anything else names nothing it can read.
     */
    private function unknownResource(string $reference, bool $isAnchor, bool $dynamic): string
    {
        $isAbsolute = preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1;
        if (!$isAbsolute && !$isAnchor && !$dynamic) {
            return $reference;
        }

        throw new RuntimeException(message: sprintf(
            'Unresolvable reference "%s": %s',
            $reference,
            match (true) {
                $isAbsolute && !str_starts_with(strtolower($reference), 'urn:') => 'it names no schema resource ($id) of this document, '
                    . 'and remote documents are not fetched. Copy the schema into the document, or reference a local file.',
                $isAbsolute => 'it names no schema resource ($id) of this document.',
                default => 'an anchor reference into another file is not supported; reference the schema by its '
                    . 'JSON pointer (file#/components/schemas/Name) instead.',
            },
        ));
    }

    /**
     * 2020-12 §8.2.3.2: a `$dynamicRef` whose initial target declares the same `$dynamicAnchor`
     * (the "bookend") resolves to the OUTERMOST resource of the dynamic scope that declares one.
     *
     * @param list<int|string> $path
     * @param list<string> $scope
     * @return list<int|string>
     */
    private function dynamicTarget(array $path, string $name, array $scope, string $reference): array
    {
        $initial = $this->nodeAt($path);
        if (!is_array($initial) || ($initial['$dynamicAnchor'] ?? null) !== $name) {
            return $path;
        }

        foreach (self::uniqueScope($scope) as $resource) {
            $locations = $this->dynamicAnchors[$resource . '#' . $name] ?? [];
            if ($locations !== []) {
                return $this->single(
                    locations: $locations,
                    reference: $reference,
                    what: sprintf('the dynamic anchor "%s" is declared more than once in the resource "%s"', $name, $resource),
                );
            }
        }

        return $path;
    }

    /**
     * What a reference to a target evaluated in a scope becomes: the component itself when the
     * target is one and the scope binds nothing differently; the target inlined when it is a value
     * constraint (a boolean, a scalar, a list); a hoisted component otherwise — and for a value
     * constraint that reaches itself again, since an inline copy cannot contain itself.
     *
     * @param list<int|string> $path
     * @param list<string> $scope
     * @return string|bool|array<mixed>
     */
    private function pointerTo(array $path, array $scope, ?string $nameHint): string|bool|array
    {
        $node = $this->nodeAt($path);
        if (is_bool($node)) {
            return $node;
        }

        $base = $this->baseAt($path);
        $targetScope = [...$scope, $base];
        $binding = implode("\n", $this->relevantScope($targetScope));

        $componentName = $this->componentNameAt($path);
        if ($componentName !== null) {
            $natural = implode("\n", $this->relevantScope([$this->documentBase, $base]));
            if ($binding === $natural) {
                return ($path[0] === '$defs' ? '#/$defs/' : '#/components/schemas/') . rawurlencode($componentName);
            }
        }

        $key = json_encode($path, JSON_THROW_ON_ERROR) . '|' . $binding;
        $parent = $path === [] ? [] : array_slice($path, 0, -1);
        if (!self::isClassLike($node) && !($this->inlining[$key] ?? false) && !array_key_exists($key, $this->hoistedNames)) {
            $this->inlining[$key] = true;
            $inlined = $this->rewrite(
                node: $node,
                path: $path,
                base: $this->baseAt($parent),
                scope: $targetScope,
                isNameMap: false,
            );
            $this->inlining[$key] = false;

            return is_array($inlined) ? self::withoutDefinitions($inlined) : (bool)$inlined;
        }

        $name = $this->hoistedNames[$key] ?? null;
        if ($name === null) {
            if (count($this->hoisted) >= self::HOISTED_LIMIT) {
                throw new RuntimeException(message: sprintf(
                    'The dynamic references of the schema at "%s" cannot be resolved at generation time: '
                    . 'they bind differently on more than %d paths.',
                    self::pointerOf($path),
                    self::HOISTED_LIMIT,
                ));
            }
            $name = $this->freshName($this->nameCandidate(path: $path, base: $base, nameHint: $nameHint));
            $this->hoistedNames[$key] = $name;
            $this->hoisted[$name] = null;
            $hoisted = $this->rewrite(
                node: $node,
                path: $path,
                base: $this->baseAt($parent),
                scope: $targetScope,
                isNameMap: false,
            );
            $this->hoisted[$name] = is_array($hoisted) ? self::withoutDefinitions($hoisted) : $hoisted;
        }

        return '#/components/schemas/' . $name;
    }

    /**
     * Whether a schema becomes a class (or a family of them) in the generated code.
     */
    private static function isClassLike(mixed $node): bool
    {
        if (!is_array($node) || array_is_list($node)) {
            return false;
        }
        $type = $node['type'] ?? null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
            return true;
        }

        return array_intersect_key($node, array_flip(self::CLASS_KEYWORDS)) !== [];
    }

    /**
     * A copy's `$defs` are dead weight: every reference into them is resolved, and the generator
     * evaluates nothing in them.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function withoutDefinitions(array $schema): array
    {
        $copy = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== '$defs' && $keyword !== 'definitions') {
                $copy[$keyword] = $value;
            }
        }

        return $copy;
    }

    /**
     * The scope as the dynamic lookup sees it: each resource once (the first, outermost entry is
     * the one a lookup finds), and only the resources that declare a dynamic anchor some
     * `$dynamicRef` asks for — the others cannot change any resolution.
     *
     * @param list<string> $scope
     * @return list<string>
     */
    private function relevantScope(array $scope): array
    {
        $relevant = [];
        foreach (self::uniqueScope($scope) as $resource) {
            foreach (array_keys($this->dynamicNames) as $name) {
                if (array_key_exists($resource . '#' . $name, $this->dynamicAnchors)) {
                    $relevant[] = $resource;

                    break;
                }
            }
        }

        return $relevant;
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    private static function uniqueScope(array $scope): array
    {
        return array_values(array_unique($scope));
    }

    /**
     * @param list<list<int|string>> $locations
     * @return list<int|string>
     */
    private function single(array $locations, string $reference, string $what): array
    {
        if (count($locations) === 1) {
            return $locations[0];
        }

        throw new RuntimeException(message: sprintf(
            'Unresolvable reference "%s": %s.',
            $reference,
            $locations === [] ? $what : $what . sprintf(' (%d declarations, the reference is ambiguous)', count($locations)),
        ));
    }

    /**
     * The component name when the location IS a component (or a root `$defs` entry, which the
     * generator folds into one).
     *
     * @param list<int|string> $path
     */
    private function componentNameAt(array $path): ?string
    {
        if (count($path) === 3 && $path[0] === 'components' && $path[1] === 'schemas') {
            return (string)$path[2];
        }
        if (count($path) === 2 && $path[0] === '$defs') {
            return (string)$path[1];
        }

        return null;
    }

    /**
     * @param list<int|string> $path
     */
    private function nameCandidate(array $path, string $base, ?string $nameHint): string
    {
        if ($nameHint !== null) {
            return $nameHint;
        }
        $node = $this->nodeAt($path);
        if (is_array($node) && is_string($node['$id'] ?? null)) {
            $uriPath = (string)parse_url($base, PHP_URL_PATH);
            $last = basename($uriPath === '' ? $base : $uriPath);
            $last = (string)preg_replace('/\.(json|ya?ml)$/i', '', $last);
            if (preg_match('/[A-Za-z]/', $last) === 1 && !str_contains($last, ':')) {
                return $last;
            }
        }
        for ($index = count($path) - 1; $index >= 0; --$index) {
            $segment = (string)$path[$index];
            if (preg_match('/[A-Za-z]/', $segment) === 1 && !str_starts_with($segment, '$')) {
                return $segment;
            }
        }

        return 'Resource';
    }

    /**
     * A component name no other component folds to: hoisted schemas join `components.schemas`, and
     * the generator names a class after the key.
     */
    private function freshName(string $candidate): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $candidate);
        $words = $words === false ? [] : $words;
        $name = implode('', array_map(callback: ucfirst(...), array: $words));
        if ($name === '' || ctype_digit($name[0])) {
            $name = 'Resource' . $name;
        }

        $fresh = $name;
        for ($suffix = 2; array_key_exists(self::foldName($fresh), $this->takenNames); ++$suffix) {
            $fresh = $name . $suffix;
        }
        $this->takenNames[self::foldName($fresh)] = true;

        return $fresh;
    }

    private static function foldName(string $name): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * @param list<int|string> $path
     */
    private function exists(array $path): bool
    {
        $node = $this->document;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return true;
    }

    /**
     * @param list<int|string> $path
     */
    private function nodeAt(array $path): mixed
    {
        $node = $this->document;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * The base URI in effect AT a location: the document's, changed by every `$id` on the way down,
     * the location's own included.
     *
     * @param list<int|string> $path
     */
    private function baseAt(array $path): string
    {
        $base = $this->documentBase;
        $node = $this->document;
        $isNameMap = false;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                break;
            }
            $parentIsObject = !$isNameMap && !array_is_list($node);
            $node = $node[$segment];
            $isNameMap = $parentIsObject && in_array($segment, self::NAME_MAP_KEYWORDS, true);
            if (!$isNameMap && is_array($node) && !array_is_list($node) && is_string($node['$id'] ?? null)) {
                $base = self::stripFragment(self::resolveUri(base: $base, reference: $node['$id']));
            }
        }

        return $base;
    }

    /**
     * RFC 6901 segments of a fragment pointer (already percent-decoded).
     *
     * @return list<string>
     */
    private static function pointerSegments(string $fragment): array
    {
        if ($fragment === '' || $fragment === '/') {
            return $fragment === '/' ? [''] : [];
        }

        return array_map(
            callback: static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array: explode('/', substr($fragment, 1)),
        );
    }

    /**
     * @param list<int|string> $path
     */
    private static function pointerOf(array $path): string
    {
        return '#/' . implode(separator: '/', array: array_map(
            callback: static fn(int|string $segment): string => str_replace(['~', '/'], ['~0', '~1'], (string)$segment),
            array: $path,
        ));
    }

    private static function stripFragment(string $uri): string
    {
        $hash = strpos($uri, '#');

        return $hash === false ? $uri : substr($uri, 0, $hash);
    }

    private static function fragmentOf(string $uri): ?string
    {
        $hash = strpos($uri, '#');

        return $hash === false ? null : substr($uri, $hash + 1);
    }

    /**
     * RFC 3986 appendix B.
     *
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string}
     */
    private static function parseUri(string $uri): array
    {
        preg_match('~^(?:([a-zA-Z][a-zA-Z0-9+.-]*):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $uri, $parts);

        return [
            'scheme' => ($parts[1] ?? '') === '' ? null : $parts[1],
            'authority' => str_starts_with(substr($uri, strlen(($parts[1] ?? '') === '' ? '' : $parts[1] . ':')), '//') ? ($parts[2] ?? '') : null,
            'path' => $parts[3] ?? '',
            'query' => str_contains(self::stripFragment($uri), '?') ? ($parts[4] ?? '') : null,
            'fragment' => str_contains($uri, '#') ? ($parts[5] ?? '') : null,
        ];
    }

    private static function composeUri(?string $scheme, ?string $authority, string $path, ?string $query, ?string $fragment): string
    {
        return ($scheme === null ? '' : $scheme . ':')
            . ($authority === null ? '' : '//' . $authority)
            . $path
            . ($query === null ? '' : '?' . $query)
            . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * RFC 3986 §5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        if (!str_contains($path, '.')) {
            return $path;
        }

        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                // `..` climbs one segment, never above the root (the leading empty segment of an
                // absolute path).
                if ($segment === '..' && $output !== [] && $output !== ['']) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }
            $output[] = $segment;
        }

        return implode('/', $output);
    }
}
