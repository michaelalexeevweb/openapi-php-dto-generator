<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use OpenapiPhpDtoGenerator\Service\DtoServiceSupport;
use OpenapiPhpDtoGenerator\Service\DtoValidator;

/**
 * Symfony attribute-mode rendering: plain DTOs decorated with Symfony Validator / Serializer
 * attributes — `#[Assert\*]`, `#[Groups]`, `#[SerializedName]`, `#[Ignore]` — and the class shape
 * that carries them.
 *
 * The keywords no attribute can express are checked by the walker in
 * {@see RendersSchemaInterpreter}, which this mode enters through an `#[Assert\Callback]`. That
 * walker is shared with laravel, laravel-data and yii3, and lived HERE until it was extracted —
 * which is why its methods used to be named after this mode.
 *
 * Extracted from GenerateDtoCommand as a trait: the emitters read the generator's schema/enum
 * registries directly, so they stay bound to it instead of duplicating that state.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 * @phpstan-import-type SchemaMetadata from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 *
 * @phpstan-ignore trait.unused
 */
trait RendersSymfonyDto
{
    /**
     * Whether the generated Symfony DTOs carry `read`/`write` serialization groups. Set once per
     * run from the whole schema set — see `documentNeedsSerializationGroups()` for why it is all
     * classes or none.
     */
    private bool $serializationGroupsRequired = false;

    /**
     * Cache for `symfonyClassesUnderADateContext()` — computed once per run, after every schema is
     * registered.
     *
     * @var array<string, true>|null
     */
    private ?array $symfonyClassesUnderADateContext = null;

    /**
     * `readOnly`/`writeOnly` can only be enforced in Symfony mode through serialization groups, and
     * groups are all-or-nothing per class: as soon as ANY attribute of a class carries a group, the
     * normalizer drops every attribute that carries none. So a document containing a single
     * `writeOnly` field forces groups onto every generated class — otherwise normalizing with
     * `['groups' => 'read']` empties the classes that have none (a nested DTO comes out as `[]`).
     *
     * Conversely, a document that uses neither keyword gets no group attributes at all: they would
     * be pure noise in the generated code.
     */
    private function documentNeedsSerializationGroups(): bool
    {
        foreach ($this->dtoSchemas as $schemaDefinition) {
            if ($this->schemaDeclaresReadOrWriteOnly($schemaDefinition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|mixed $schema
     */
    private function schemaDeclaresReadOrWriteOnly(mixed $schema): bool
    {
        if (!is_array($schema)) {
            return false;
        }

        if (($schema['readOnly'] ?? null) === true || ($schema['writeOnly'] ?? null) === true) {
            return true;
        }

        foreach ($schema as $value) {
            if ($this->schemaDeclaresReadOrWriteOnly($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A property whose `oneOf`/`anyOf` has a free-form `{type: object}` member beside a generated
     * class, held as the plain `array` the free-form member is. A member that declares no properties
     * but closes the object (`additionalProperties: false`) is held so as well: the empty class it
     * became dropped every key, so the extra one it forbids was never seen.
     *
     * Typed `Lime|array`, the serializer tried the class first, and under `#[MapRequestPayload]` the
     * object it could not build was a collected error: `{"fox": {"any": 1}}` was refused for a missing
     * `hue`, and `{"fox": {"hue": 5}}` for its type, although the free-form member admits both. Any
     * object the class admits the free-form member admits too, so the class is never needed to hold
     * the value; the callback still judges the whole `anyOf` / `oneOf` against the decoded array.
     *
     * A list member beside classes the serializer cannot pass over is held so too
     * (`symfonyListBesideUnskippableClasses()`), and so is a union with a discriminated union as a
     * member (`symfonyUnionNestsDiscriminatedUnion()`).
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function symfonyWithFreeFormObjectUnionsHeldAsArray(string $className, array $properties): array
    {
        $schemaProperties = $this->dtoSchemas[$className]['properties'] ?? null;
        if (!is_array($schemaProperties)) {
            return $properties;
        }
        foreach ($properties as $index => $property) {
            $schema = $schemaProperties[$property['openApiName']] ?? null;
            if (
                !is_array($schema)
                || (
                    !$this->symfonyUnionHasFreeFormObjectMember(schema: $schema, sourceFile: $this->getSchemaSourceFile($className))
                    && !$this->symfonyListBesideUnskippableClasses($property['type'])
                    && !$this->symfonyUnionNestsDiscriminatedUnion(schema: $schema, sourceFile: $this->getSchemaSourceFile($className))
                    && !$this->symfonyUnionHasMemberWrittenElsewhere($schema)
                )
            ) {
                continue;
            }
            $members = $this->topLevelTypeMembers(ltrim($property['type'], '?'));
            $kept = [];
            $droppedAClass = false;
            foreach ($members as $member) {
                $short = $this->shortClassName($member);
                if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas)) {
                    $droppedAClass = true;

                    continue;
                }
                $kept[] = $member;
            }
            if (!$droppedAClass) {
                continue;
            }
            if (!in_array('array', $kept, true)) {
                $kept[] = 'array';
            }
            $properties[$index]['type'] = (str_starts_with($property['type'], '?') ? '?' : '') . implode('|', $kept);
        }

        return $properties;
    }

    /**
     * Whether a union has an `array` member beside generated classes the serializer, trying a class
     * first, does not fall through to the list from (`symfonyWithFreeFormObjectUnionsHeldAsArray()`
     * holds it as the decoded array then, as the yii3 mode and a union component already are):
     *
     * - two or more classes (`ProbeVoleAnyOf1|array|ProbeVoleAnyOf3`): extra attributes are refused
     *   beside ONE class only (`symfonyContextAttribute()`), so a list became an empty first class —
     *   `["g", "g"]` passed a list of at most one integer, and a valid `[1]` lost its items;
     * - one class that requires a key (`ProbeWren|array`): under `#[MapRequestPayload]` the key the list
     *   lacks is a COLLECTED error rather than a thrown one, so the serializer never tried `array`, and
     *   every list was refused for the missing key.
     */
    private function symfonyListBesideUnskippableClasses(string $phpType): bool
    {
        if (!$this->unionHasArrayMember($phpType)) {
            return false;
        }
        $classes = [];
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            $short = $this->shortClassName($member);
            if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas) && !$this->rendersAsUnion($this->dtoSchemas[$short])) {
                $classes[] = $short;
            }
        }

        return count($classes) >= 2
            || (count($classes) === 1 && $this->allOfRequiredNames($this->dtoSchemas[$classes[0]]) !== []);
    }

    /**
     * Whether a reference names a discriminated union one of whose members is a class another document's
     * own run writes (`memberIsWrittenByItsOwnDocument()`). Such a member implements no interface of this
     * document, so the serializer could not put it into a property typed with the union: `{"pet":
     * {"kind": "dog"}}` was a 500. The value is held as the decoded array, and the member its tag names
     * judges it, as for a union nested in a union (`symfonyUnionNestsDiscriminatedUnion()`).
     *
     * Any spelling of the reference counts (`unionReferenceTheSchemaConsistsOf()`): beside a null,
     * `anyOf: [$ref Teal, {type: null}]` stayed typed `Teal|array|null`, and the serializer refused the
     * member it built for not being a Teal.
     *
     * @param array<mixed> $schema
     */
    private function symfonyUnionHasMemberWrittenElsewhere(array $schema): bool
    {
        $ref = $this->unionReferenceTheSchemaConsistsOf($schema)['ref'] ?? null;

        return $ref !== null
            && str_starts_with($ref, '#/components/schemas/')
            && $this->symfonyUnionClassHasMemberWrittenElsewhere($this->schemaClassName(substr($ref, strlen('#/components/schemas/'))));
    }

    /**
     * {@see symfonyUnionHasMemberWrittenElsewhere()} for the union's class: a list item or map value
     * typed with it is held as the decoded array too, the class kept beside it for a DTO built by hand.
     */
    private function symfonyUnionClassHasMemberWrittenElsewhere(string $baseClass): bool
    {
        if (!$this->isOneOfDiscriminatorBase($baseClass)) {
            return false;
        }
        foreach ($this->discriminatorSchemas[$baseClass]['mapping'] as $memberClass) {
            if ($this->memberIsWrittenByItsOwnDocument($memberClass, $baseClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a `oneOf` / `anyOf` has a member that is itself a discriminated union — `oneOf: [{oneOf:
     * [$ref Circle, $ref Square], discriminator: …}, {type: string}]`. The property is typed
     * `Circle|Square|string`, the `discriminator` having no interface to sit on, and the serializer tried
     * `Circle` first: `{"kind": "square"}` failed its `kind` enum, a COLLECTED error under
     * `#[MapRequestPayload]`, so `Square` was never tried and a valid object was refused. Held as the
     * decoded array, the callback judges it against the member its tag names.
     *
     * A `$ref` to a union component written out where it is used (`Pet: {anyOf: [{anyOf: […],
     * discriminator: …}, {type: integer}]}`) is that union: read only inline, `crane: $ref Pet` was typed
     * `Cobalt|Mauve|int` and refused a valid `Mauve` for Cobalt's `kind` enum.
     *
     * @param array<mixed> $schema
     * @param string|null $sourceFile the document the schema is written in
     */
    private function symfonyUnionNestsDiscriminatedUnion(array $schema, int $depth = 0, ?string $sourceFile = null): bool
    {
        // `allOf: [$ref Pet]` is the same reference spelled longer: read only as `$ref`, the wrapped one
        // was typed `Jade|Ivory` and refused a valid Jade for Ivory's `kind` enum.
        $ref = $this->referenceOrSingleRefAllOf($schema);
        if (is_string($ref) && $depth < 8) {
            $aliased = $this->scalarAliasDefinition($ref, $sourceFile);

            return $aliased !== null && $this->symfonyUnionNestsDiscriminatedUnion(schema: $aliased, depth: $depth + 1, sourceFile: $sourceFile);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                if (
                    is_array($branch)
                    && is_array($branch['discriminator'] ?? null)
                    && (is_array($branch['oneOf'] ?? null) || is_array($branch['anyOf'] ?? null))
                ) {
                    return true;
                }
                // One level further down: `anyOf: [{anyOf: [{type: string}, <discriminated union>]},
                // {type: null}]` spreads the inner union into `string|Amber|Indigo|null` as much as the
                // one-level spelling does, and the serializer built Amber for a valid `circle` the same.
                if (is_array($branch) && $depth < 8 && $this->symfonyUnionNestsDiscriminatedUnion(schema: $branch, depth: $depth + 1, sourceFile: $sourceFile)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The properties `symfonyWithFreeFormObjectUnionsHeldAsArray()` holds as the decoded `array`, by
     * OpenAPI name: their union branches are written out (`symfonyHeldUnionBranchesInlined()`), and no
     * class is built below them, so every keyword there is the callback's.
     *
     * Left to the class the branch would have been, the filter dropped a written-out `$ref` branch
     * (`allOf: [Sienna, {properties: …}]`) as that class's business and kept a marker that matched
     * every object: `{"bison": {"tern": 5}}` passed beside a map of booleans.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<string, true>
     */
    private function symfonyFreeFormObjectUnionsHeldAsArray(string $className, array $properties): array
    {
        $held = [];
        foreach ($this->symfonyWithFreeFormObjectUnionsHeldAsArray($className, $properties) as $index => $property) {
            if ($property['type'] !== ($properties[$index]['type'] ?? null)) {
                $held[$property['openApiName']] = true;
            }
        }

        return $held;
    }

    /**
     * Read through a member that is only a union itself — `oneOf: [{oneOf: [{type: object, required:
     * [gecko]}, {…class…}]}, {type: null}]`, the nullable wrapper around the real union. The free-form
     * member one level down was not seen, the class kept in the type, and the serializer built every
     * object into it: `{"gecko": 1}` refused for the `crane` the class requires.
     *
     * @param array<mixed> $schema
     * @param string|null $sourceFile the document the schema is written in: a `$ref` into another file is
     *        relative to it, not to the root document
     */
    private function symfonyUnionHasFreeFormObjectMember(array $schema, int $depth = 0, ?string $sourceFile = null): bool
    {
        // A `$ref` to a union component that is written out where it is used — one with an object
        // held as the decoded array among its members (`isMixedValueUnionSchema()`) — is that union.
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref) && $depth < 8) {
            $aliased = $this->scalarAliasDefinition($ref, $sourceFile);

            return $aliased !== null && $this->symfonyUnionHasFreeFormObjectMember(schema: $aliased, depth: $depth + 1, sourceFile: $sourceFile);
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                if (is_array($branch) && $this->isFreeFormObjectSchema($branch)) {
                    return true;
                }
                if (
                    $depth < 8
                    && is_array($branch)
                    && !array_key_exists('$ref', $branch)
                    && !array_key_exists('type', $branch)
                    && !array_key_exists('properties', $branch)
                    && $this->symfonyUnionHasFreeFormObjectMember(schema: $branch, depth: $depth + 1, sourceFile: $sourceFile)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A property, list item or map value typed as a union COMPONENT of classes with no `discriminator`
     * (`Shade: {anyOf: [$ref Zebra, $ref Ant]}`), held as the decoded object: `?Shade` becomes `?array`
     * (`array<string, mixed>`), `array<Shade>` becomes `array<array<string, mixed>>`. A union with a
     * member that is not an object is held as `mixed`.
     *
     * Typed as the union's interface, the serializer had no class to build — the document does not say
     * which member an object is — and every object was refused, valid ones included: `{"shade":
     * {"leg": 1}}` came back "must be one of Shade (array given)". The callback judges the decoded
     * value against the members instead (`symfonyWithRawContainerBranchesInlined()`): `anyOf` at least
     * one, `oneOf` exactly one.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function symfonyWithUndiscriminatedUnionsHeldDecoded(array $properties): array
    {
        foreach ($properties as $index => $property) {
            $held = $this->symfonyUndiscriminatedUnionHeldType($property['type']);
            if ($held !== $property['type']) {
                $properties[$index]['type'] = $held;
            }
        }

        return $properties;
    }

    /**
     * The properties `symfonyWithUndiscriminatedUnionsHeldDecoded()` holds as the decoded value, by
     * OpenAPI name: nothing is built below them, so every keyword there is the callback's.
     *
     * A list of lists of such a union (`array<array<Shade>>`) is one of them too: past the second
     * container the type says `mixed` already, and the union below was left to the class nothing builds
     * there — `{"rows": [[{}]]}` was accepted.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<string, true>
     */
    private function symfonyUndiscriminatedUnionsHeldDecoded(string $className, array $properties): array
    {
        $schemaProperties = $this->dtoSchemas[$className]['properties'] ?? null;
        $held = [];
        foreach ($properties as $property) {
            $schema = is_array($schemaProperties) ? ($schemaProperties[$property['openApiName']] ?? null) : null;
            if (
                $this->symfonyUndiscriminatedUnionHeldType($property['type']) !== $property['type']
                || (is_array($schema) && $this->symfonyContainerHoldsUndiscriminatedUnion(schema: $schema, className: $className))
            ) {
                $held[$property['openApiName']] = true;
            }
        }

        return $held;
    }

    /**
     * Whether a list or map holds, at any depth, an undiscriminated union component of classes — or a
     * DISCRIMINATED one where the serializer builds nothing.
     *
     * A discriminated union component is built through the discriminator map one container down
     * (`array<Shape>`), and that object checks itself. Below a container held as the decoded array it
     * is not: the item of a list of lists or of a map of lists is typed `mixed`, a tuple is a bare
     * `?array` (its `prefixItems` slots and the `items` tail beside them alike), so the value stays the
     * decoded JSON and no #[Assert\Valid] reaches it. Left for the class, the reference extracted to
     * nothing there, and `{"newt": [1, {"vole": "g"}]}` — no tag, a wrong-typed field, a bare string —
     * was accepted. Such a property is written out like the undiscriminated one: members and tag check.
     *
     * @param array<mixed> $schema
     * @param bool $unbuilt whether nothing is built at `$schema` itself already
     */
    private function symfonyContainerHoldsUndiscriminatedUnion(array $schema, string $className, int $depth = 0, bool $unbuilt = false): bool
    {
        if ($depth >= 8) {
            return false;
        }
        // A `$ref` to an array alias is that list here: its tuple tail and inner lists are the same
        // unbuilt positions as when written inline (`{"pack": ["x", 5]}` passed for a tuple of Shapes).
        $aliasRef = $this->referenceOrSingleRefAllOf($schema);
        $alias = $aliasRef === null ? null : $this->arrayAliasDefinition($aliasRef);
        if ($alias !== null) {
            // Keywords beside the reference win over the alias's, as for the constraints.
            $own = [];
            foreach ($schema as $keyword => $value) {
                if ($keyword !== '$ref' && $keyword !== 'allOf') {
                    $own[$keyword] = $value;
                }
            }
            $schema = $own + $alias;
        }
        $positions = [];
        foreach (['items', 'additionalProperties'] as $containerKey) {
            if (is_array($schema[$containerKey] ?? null)) {
                // One container down the serializer builds the item; past that, and in a tuple tail, it
                // builds nothing.
                $positions[] = [
                    $schema[$containerKey],
                    $unbuilt || $depth >= 1 || ($containerKey === 'items' && array_key_exists('prefixItems', $schema)),
                ];
            }
        }
        foreach (is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : [] as $slot) {
            if (is_array($slot)) {
                $positions[] = [$slot, true];
            }
        }
        foreach ($positions as [$inner, $innerUnbuilt]) {
            if (
                $this->symfonyNamesUnbuiltUnionComponent(schema: $inner, className: $className, unbuilt: $innerUnbuilt)
                || $this->symfonyUnionComponentBranchUnbuilt(union: $inner, className: $className)
                || $this->symfonyContainerHoldsUndiscriminatedUnion(schema: $inner, className: $className, depth: $depth + 1, unbuilt: $innerUnbuilt)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a list item, map value or tuple slot names a union component nothing builds: an
     * undiscriminated one anywhere, a discriminated one where `$unbuilt` (see
     * `symfonyContainerHoldsUndiscriminatedUnion()`).
     *
     * @param array<mixed> $schema
     */
    private function symfonyNamesUnbuiltUnionComponent(array $schema, string $className, bool $unbuilt): bool
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return false;
        }
        $refClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
        if ($this->isUnhydratableUnionClass($refClass) || $this->symfonyUnionClassHasMemberWrittenElsewhere($refClass)) {
            return true;
        }
        $definition = $this->dtoSchemas[$refClass] ?? null;

        return $unbuilt
            && array_key_exists($refClass, $this->discriminatorSchemas)
            && is_array($definition)
            && $this->rendersAsUnion($definition);
    }

    /**
     * Whether a list item or map value is a `oneOf`/`anyOf` with a `$ref` to a union COMPONENT
     * (discriminated or not) beside a branch that is not only null: `items: {oneOf: [$ref Shape, {type:
     * string}]}`. The container is typed a bare `array` then, so the serializer builds no member and the
     * item stays the decoded array: "is a Shape" refused every object under `oneOf` and passed it
     * unchecked under `anyOf`. Beside null alone the list is typed `array<?Shape>` and the members are
     * built (or held decoded) as before.
     *
     * @param array<mixed> $union
     */
    private function symfonyUnionComponentBranchUnbuilt(array $union, string $className): bool
    {
        $hasUnionComponent = false;
        $hasOtherValue = false;
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($union[$keyword] ?? null) ? $union[$keyword] : [] as $branch) {
                if (!is_array($branch) || $this->isNullBranch($branch)) {
                    continue;
                }
                $ref = $this->splitBranchReference($branch)[0];
                $definition = is_string($ref)
                    ? $this->dtoSchemas[$this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className))] ?? null
                    : null;
                if (is_array($definition) && $this->rendersAsUnion($definition)) {
                    $hasUnionComponent = true;

                    continue;
                }
                $hasOtherValue = true;
            }
        }

        return $hasUnionComponent && $hasOtherValue;
    }

    /**
     * The PHP type with every undiscriminated union component of classes in it replaced by the decoded
     * value it is held as, see `symfonyWithUndiscriminatedUnionsHeldDecoded()`.
     */
    private function symfonyUndiscriminatedUnionHeldType(string $type): string
    {
        $topLevel = $this->topLevelTypeMembers(ltrim($type, '?'));
        $heldAsMixed = false;
        $replaced = preg_replace_callback(
            pattern: '/\??\\\?[A-Za-z_][A-Za-z0-9_\\\]*/',
            callback: function (array $match) use (&$heldAsMixed): string {
                $class = $this->shortClassName(ltrim($match[0], '?\\'));
                if (!$this->isUnhydratableUnionClass($class) && !$this->symfonyUnionClassHasMemberWrittenElsewhere($class)) {
                    return $match[0];
                }
                if (!$this->symfonyUnionHoldsOnlyObjects($class)) {
                    $heldAsMixed = true;

                    return 'mixed';
                }

                // The interface stays beside it: a DTO built by hand — a response — still holds a member.
                return 'array<string, mixed>|' . ltrim($match[0], '?') . (str_starts_with($match[0], '?') ? '|null' : '');
            },
            subject: $type,
        );
        if ($replaced === null || $replaced === $type) {
            return $type;
        }
        // `mixed` admits null and stands in no union: a top-level member held so takes the whole type.
        if ($heldAsMixed) {
            foreach ($topLevel as $member) {
                $class = $this->shortClassName(ltrim($member, '?\\'));
                if ($this->isUnhydratableUnionClass($class) && !$this->symfonyUnionHoldsOnlyObjects($class)) {
                    return 'mixed';
                }
            }
        }

        return $replaced;
    }

    /**
     * Whether every member of a union component is an object — a generated class, an inline object, or
     * a union of those — so the decoded value it is held as is always an `array`.
     */
    private function symfonyUnionHoldsOnlyObjects(string $class, int $depth = 0): bool
    {
        $schema = $this->dtoSchemas[$class] ?? null;
        if (!is_array($schema) || $depth >= 8 || ($schema['nullable'] ?? false) === true) {
            return false;
        }

        return $this->symfonyBranchesAreObjects(schema: $schema, sourceClass: $class, depth: $depth);
    }

    /**
     * @param array<mixed> $schema
     */
    private function symfonyBranchesAreObjects(array $schema, string $sourceClass, int $depth): bool
    {
        $branches = [];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                $branches[] = $branch;
            }
        }
        if ($branches === []) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return false;
            }
            $ref = $branch['$ref'] ?? null;
            if (is_string($ref)) {
                $target = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($sourceClass));
                $definition = $this->dtoSchemas[$target] ?? null;
                if (
                    !is_array($definition)
                    || array_key_exists($target, $this->enumSchemas)
                    || $this->isScalarAliasSchema($definition)
                    || ($definition['nullable'] ?? false) === true
                ) {
                    return false;
                }
                if ($this->rendersAsUnion($definition) && !$this->symfonyUnionHoldsOnlyObjects($target, $depth + 1)) {
                    return false;
                }

                continue;
            }
            if ($this->isInlineObjectVariant($branch) && ($branch['nullable'] ?? false) !== true) {
                continue;
            }
            if ($this->symfonyAllOfBranchIsAnObject(branch: $branch, sourceClass: $sourceClass)) {
                continue;
            }
            if (
                $depth < 8
                && !array_key_exists('type', $branch)
                && (array_key_exists('oneOf', $branch) || array_key_exists('anyOf', $branch))
                && $this->symfonyBranchesAreObjects(schema: $branch, sourceClass: $sourceClass, depth: $depth + 1)
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Whether a union branch written as an `allOf` — a `$ref` with siblings arrives as one — is a member
     * class of its own (`collectUnionTypes()`) whose value is never null: one of its parts is typed
     * `object` without `null`, or is a `$ref` to an object component that does not admit null. Counted
     * as something else, the union was held as `mixed` instead of the decoded object.
     *
     * @param array<mixed> $branch
     */
    private function symfonyAllOfBranchIsAnObject(array $branch, string $sourceClass): bool
    {
        if (
            !is_array($branch['allOf'] ?? null)
            || ($branch['nullable'] ?? false) === true
            || !$this->isObjectUnionMemberOfItsOwn($branch, $sourceClass)
        ) {
            return false;
        }
        foreach ($branch['allOf'] as $part) {
            if (!is_array($part) || ($part['nullable'] ?? false) === true) {
                continue;
            }
            $type = $part['type'] ?? null;
            if ($type === 'object' || (is_array($type) && in_array('object', $type, true) && !in_array('null', $type, true))) {
                return true;
            }
            $ref = $part['$ref'] ?? null;
            if (!is_string($ref)) {
                continue;
            }
            $target = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($sourceClass));
            $definition = $this->dtoSchemas[$target] ?? null;
            if (
                is_array($definition)
                && !array_key_exists($target, $this->enumSchemas)
                && !$this->isScalarAliasSchema($definition)
                && !$this->rendersAsUnion($definition)
                && !$this->componentAdmitsNull($definition)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A list item or map value one container deep that is an enum or null — `additionalProperties: {$ref:
     * Kind, nullable: true}`, `{allOf: [$ref Kind], nullable: true}`, `anyOf: [{$ref: Kind}, {type:
     * null}]` — typed as the strings (or integers) it arrives as (`array<string, ?string>`), the callback
     * checking the enum. Typed `?Kind`, symfony/serializer's `ArrayDenormalizer` handed the null to
     * `BackedEnumNormalizer`, which refuses null: `{"otter": {"ka": null}}` was refused although the
     * document allows it. The inline enum that lists `null` itself is a string already; a nullable list
     * of enums one container deep moved the same way in 2.15.159 (`symfonyAliasInAContainerType()`).
     *
     * A nullable DATE item or value — `items: {type: [string, null], format: date}`, the canonical
     * spelling of `anyOf: [{format: date}, {type: null}]` — is held as the string for the same reason:
     * `DateTimeNormalizer` refuses null too.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function symfonyWithNullableEnumMembersHeldAsStrings(array $properties): array
    {
        foreach ($properties as $index => $property) {
            if (preg_match('/^(\??array<(?:(?:string|int), )?)\?([\\\\\w]+)>$/', $property['type'], $matches) !== 1) {
                continue;
            }
            // A date arrives as a string, the callback checking its format; the backing type is what an
            // enum case arrives as: `string`, or `int` for an integer enum.
            $backing = $matches[2] === 'DateTimeImmutable'
                ? 'string'
                : $this->enumSchemas[$this->shortClassName($matches[2])]['type'] ?? null;
            if (!in_array($backing, ['string', 'int'], true)) {
                continue;
            }
            $properties[$index]['type'] = $matches[1] . '?' . $backing . '>';
        }

        return $properties;
    }

    /**
     * A property whose `oneOf`/`anyOf` holds a list of a UNION component beside `null` or a scalar
     * (`oneOf: [{type: array, items: {$ref: Shape}}, {type: integer}]`), typed as that list:
     * `array<Shape>|int`.
     *
     * Typed `array|int`, the serializer kept the items as decoded arrays, and nothing judged them: a
     * union component is not spelled out below the property in this mode (the serializer builds its
     * variants wherever the list is typed — `nestedObjectRefDefinition()`), so the items were left
     * `{type: array}`. `{"quokka": [{"kind": "square", "side": -1}]}` passed a `minimum: 0`, and a tag
     * the mapping does not name passed too. Typed, the variants are built through the discriminator
     * map and cascaded into, as for `type: [array, "null"]`. A list of a plain class is spelled out
     * already and stays as it was.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function symfonyWithUnionListsTyped(string $className, array $properties): array
    {
        $schemaProperties = $this->dtoSchemas[$className]['properties'] ?? null;
        if (!is_array($schemaProperties)) {
            return $properties;
        }
        foreach ($properties as $index => $property) {
            $schema = $schemaProperties[$property['openApiName']] ?? null;
            $members = $this->topLevelTypeMembers(ltrim($property['type'], '?'));
            if (!is_array($schema) || count(array_keys($members, 'array', true)) !== 1) {
                continue;
            }
            $itemClass = $this->symfonyUnionListItemClass($schema, $className);
            if ($itemClass === null) {
                continue;
            }
            $typed = array_map(
                callback: static fn(string $member): string => $member === 'array' ? 'array<' . $itemClass . '>' : $member,
                array: $members,
            );
            $properties[$index]['type'] = (str_starts_with($property['type'], '?') ? '?' : '') . implode('|', $typed);
        }

        return $properties;
    }

    /**
     * The union component the one list member of a property's `oneOf`/`anyOf` holds, or null.
     *
     * @param array<mixed> $schema
     */
    private function symfonyUnionListItemClass(array $schema, string $className): ?string
    {
        $found = null;
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $type = $branch['type'] ?? null;
                if ($type !== 'array' && !(is_array($type) && in_array('array', $type, true))) {
                    continue;
                }
                // A tuple's leading positions are not the item class: typed `array<Shape>`, the
                // serializer built `[5, {...}]`'s integer as a Shape and refused it. Plain `array`
                // leaves the positions to the emitted tuple checks.
                if ($this->hasPrefixItems($branch)) {
                    return null;
                }
                $items = is_array($branch['items'] ?? null) ? $branch['items'] : [];
                $ref = $items['$ref'] ?? null;
                if ($found !== null || !is_string($ref) || array_keys($items) !== ['$ref']) {
                    return null;
                }
                $itemClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
                $definition = $this->dtoSchemas[$itemClass] ?? null;
                // A union of SCALARS (`Spoke: oneOf: [string, integer]`) is no class: typed
                // `array<Spoke>`, the serializer refused every list as one of a class never written.
                if (
                    !is_array($definition)
                    || $this->isScalarAliasSchema($definition)
                    || ((!is_array($definition['oneOf'] ?? null) || $definition['oneOf'] === [])
                        && (!is_array($definition['anyOf'] ?? null) || $definition['anyOf'] === []))
                ) {
                    return null;
                }
                $found = $itemClass;
            }
        }

        return $found;
    }

    /**
     * Renders a DTO in Symfony mode: a plain data class with promoted public readonly
     * constructor properties decorated with Symfony Validator (#[Assert\*]) and Serializer
     * (#[SerializedName]) attributes. No library runtime, interface, or normalization map.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $unionTypes
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
     */
    private function renderSymfonyDtoClass(
        string $namespace,
        string $className,
        array $properties,
        array $unionTypes,
        ?array $discriminator = null,
        ?string $extends = null,
        bool $isAbstract = false,
    ): string {
        // A oneOf/anyOf schema (and a discriminated base) is a type, not a data class: rendering it
        // as a class produces an empty object that cannot hold any branch. Symfony DTOs are
        // flattened and a schema can belong to several unions, so the union becomes an interface
        // the members implement.
        if ($unionTypes !== [] || ($isAbstract && $discriminator !== null)) {
            return $this->renderSymfonyUnionInterface(
                namespace: $namespace,
                className: $className,
                unionTypes: $unionTypes,
                discriminator: $discriminator,
            );
        }
        // The allOf pattern: an object base with a discriminator whose variants extend it. Flattened,
        // a variant cannot extend anything, so the base becomes the interface the variants implement
        // — with the getters of the base's own properties, which every variant inherits.
        if ($this->isSymfonyAllOfDiscriminatorBase($className)) {
            return $this->renderSymfonyUnionInterface(
                namespace: $namespace,
                className: $className,
                unionTypes: [],
                discriminator: $discriminator,
                sharedProperties: $properties,
            );
        }

        $properties = $this->symfonyWithUnionListsTyped($className, $properties);
        $properties = $this->symfonyWithNullableEnumMembersHeldAsStrings($properties);
        $heldAsArray = $this->symfonyFreeFormObjectUnionsHeldAsArray($className, $properties);
        $properties = $this->symfonyWithFreeFormObjectUnionsHeldAsArray($className, $properties);
        $heldDecoded = $this->symfonyUndiscriminatedUnionsHeldDecoded($className, $properties);
        $properties = $this->symfonyWithUndiscriminatedUnionsHeldDecoded($properties);

        $useStatements = [];
        if ($this->needsDateTimeImmutableImport($properties)) {
            $useStatements[] = 'DateTimeImmutable';
        }
        if ($this->needsUploadedFileImport($properties)) {
            $useStatements[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
        }
        foreach ($this->collectGeneratedClassImports($namespace, $className, $properties, null, $unionTypes, null) as $import) {
            $useStatements[] = $import;
        }

        $params = [];
        $needsGroups = false;
        foreach ($properties as $property) {
            $param = $this->resolveSymfonyParam(
                property: $property,
                namespace: $namespace,
                underADateContext: array_key_exists($className, $this->symfonyClassesUnderADateContext()),
            );
            foreach ($param['attributes'] as $attribute) {
                if (str_contains($attribute, 'Groups(')) {
                    $needsGroups = true;
                }
            }
            $params[] = $param;
        }

        $schemaDefinition = $this->dtoSchemas[$className] ?? [];
        $forceRootCallbackBounds = [];
        $schemaProperties = is_array($schemaDefinition['properties'] ?? null) ? $schemaDefinition['properties'] : [];
        foreach ($properties as $property) {
            // A union with a member another document writes is held as the decoded array, and its
            // members are written out in place (`symfonyContainerUnionReferenceInlined()`): an `anyOf` of
            // them has no `AtLeastOneOf` spelling, so left to the attribute it was checked by nothing —
            // `{"kind": "Azure"}` passed a member that requires `newt`.
            $propertySchema = $schemaProperties[$property['openApiName']] ?? null;
            if (
                $this->shouldForceRootCallbackForProperty($property)
                || (is_array($propertySchema) && $this->symfonyUnionHasMemberWrittenElsewhere($propertySchema))
            ) {
                $forceRootCallbackBounds[$property['openApiName']] = true;
            }
        }

        $extractedClassConstraints = $this->symfonyExtractedClassConstraints(
            className: $className,
            schemaDefinition: $schemaDefinition,
            mixedProperties: $this->mixedPropertyNames($properties),
        );
        $validationConstraints = $this->filterInterpreterConstraints(
            constraints: $extractedClassConstraints,
            allowScalarKeywords: false,
            // Nothing is built below a union held as the decoded value: the callback judges all of it.
            forceScalarOnProperties: $forceRootCallbackBounds + $heldDecoded,
            unmaterializedProperties: $this->mixedPropertyNames($properties) + $heldDecoded + $heldAsArray,
            aroundNestedDtoProperties: $this->propertiesBesideTheirReferencedClass(
                classSchema: $this->classSchemaWithInlineAllOfMerged(schemaDefinition: $schemaDefinition, className: $className),
                properties: $properties,
                className: $className,
            ),
        );
        $validationConstraints = $this->symfonyWithClassLevelAnyOf(
            filtered: $validationConstraints,
            className: $className,
            extracted: $extractedClassConstraints,
        );
        $validationConstraints = $this->symfonyWithAllOfParentObjectKeywords(
            filtered: $this->symfonyWithoutUncountableMinProperties($validationConstraints),
            className: $className,
        );

        // Map php name -> openapi name for constraints matching
        $phpToOpenApiNameMap = [];
        $providedFlags = [];
        foreach ($params as $param) {
            $phpName = $param['name'];
            $phpToOpenApiNameMap[$phpName] = $param['serializedName'] ?? $phpName;
            if ($param['required'] !== true) {
                $providedFlags[$phpName] = $param['providedFlag'];
            }
        }

        $validationConstraints = $this->withUnevaluatedPropertiesEvaluated(
            constraints: $this->pruneConstraintsCoveredByPhpType($validationConstraints, $params),
            className: $className,
        );
        // The null an optional object setter now admits — see `resolveSymfonyParam()` — is refused
        // here, at the field's path: `type: object` passes the nested DTO and nothing else.
        foreach ($params as $param) {
            if (!$param['nullRefusedByCallback']) {
                continue;
            }
            $openApiName = $param['serializedName'] ?? $param['name'];
            $existing = is_array($validationConstraints['properties'][$openApiName] ?? null)
                ? $validationConstraints['properties'][$openApiName]
                : [];
            // A union's other members are values too: only the null is refused there.
            $isUnion = count($this->unionMembersBesideNull($param['declaredType'])) > 1;
            if ($isUnion && !array_key_exists('not', $existing)) {
                $validationConstraints['properties'][$openApiName] = ['not' => ['type' => 'null']] + $existing;
            } elseif (!$isUnion && !array_key_exists('type', $existing)) {
                $validationConstraints['properties'][$openApiName] = ['type' => 'object'] + $existing;
            }
        }
        if (self::constraintsAreInert($validationConstraints)) {
            $validationConstraints = [];
        }

        $validationParts = $this->renderInterpreterBlock(
            constraints: $validationConstraints,
            phpToOpenApiNameMap: $phpToOpenApiNameMap,
            providedFlags: $providedFlags,
            valueKinds: $this->interpreterValueKinds($className, $params),
        );

        // A DTO without callback-validated keywords has no payload method of its own, yet an
        // enclosing DTO may still need to see it as a payload (`uniqueItems` over DTO items,
        // `const`, a subschema applied to the nested object). Its properties are private, so
        // reading it from outside is impossible without this — hence the compact version. Every
        // class with properties carries it: a class is read by documents other than its own (a
        // `--ref`ed component file), so whether some callback reads it is not knowable from one run,
        // and a file that depended on it changed with whichever run wrote it last.
        if ($validationParts['methods'] === '' && $params !== []) {
            $validationParts['methods'] = $this->renderSymfonyStandalonePayloadMethod($params);
        }
        $cascade = $this->renderSymfonyScalarUnionCascade($properties);
        // After the interpreter's methods, which end on their closing brace: a blank line between them.
        $validationParts['methods'] = $validationParts['methods'] !== '' && $cascade !== ''
            ? rtrim($validationParts['methods']) . "\n" . $cascade
            : $validationParts['methods'] . $cascade;

        // Only where an attribute uses it: since NotNull and the empty callback went, many classes
        // carry no constraint at all, and an unused import trips `no_unused_imports` in the consumer.
        $usesAssert = str_contains($validationParts['methods'], 'Assert\\');
        foreach ($params as $param) {
            foreach ($param['attributes'] as $attribute) {
                $usesAssert = $usesAssert || str_contains($attribute, 'Assert\\');
            }
        }
        if ($usesAssert) {
            $useStatements[] = 'Symfony\Component\Validator\Constraints as Assert';
        }
        // The serializer's attributes, the callback's context and the formatters' `DateTimeInterface` are
        // written out in full; the rendered file imports each one under its short name only where no
        // class of the document owns that name. A schema named `Context` or `Ignore` was a file that
        // never loaded, and in a sibling the import silently retargeted a property's type.
        $queryReaders = [];
        $queryListReaders = [];
        foreach ($params as $param) {
            if ($param['queryReader'] !== null) {
                $queryReaders[$param['queryReader']] = $param['queryReader'];
            }
            if ($param['queryList']) {
                $queryListReaders['readQueryList'] = 'readQueryList';
            }
            if ($param['enumReader'] !== null) {
                $queryListReaders[$param['enumReader']] = $param['enumReader'];
            }
        }
        $temporalFormatters = [];
        $wireShapes = [];
        foreach ($params as $param) {
            if ($param['temporalFormatter'] !== null) {
                $temporalFormatters[$param['temporalFormatter']] = $param['temporalFormatter'];
            }
            if ($param['wireShape'] !== null) {
                $wireShapes[] = $param;
            }
        }
        $implementedInterfaces = [];
        // A member two discriminated unions share has no one base to extend, so it implements each
        // interface whose own mapping names it (`symfonyDiscriminatedMembers()`).
        $unionInterfaces = $this->implementedUnionInterfaces($className, $extends);
        foreach ($this->discriminatorSchemas as $baseClass => $meta) {
            if (
                $baseClass !== $className
                && $this->isOneOfDiscriminatorBase($baseClass)
                && in_array($className, $meta['mapping'], true)
                && !in_array($baseClass, $unionInterfaces, true)
                && !$this->memberIsWrittenByItsOwnDocument($className, $baseClass)
            ) {
                $unionInterfaces[] = $baseClass;
            }
        }
        foreach ($unionInterfaces as $unionInterface) {
            $this->appendImportForClass($useStatements, $unionInterface, $namespace, $className);
            $implementedInterfaces[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.symfony.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'implementedInterfaces' => $implementedInterfaces,
            'redeclaresUnionMemberList' => $this->redeclaresUnionMemberList($className),
            'unionMembers' => null,
            'interfaceExtends' => [],
            'discriminatorMap' => null,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'extends' => null,
            'params' => $params,
            'temporalFormatters' => array_values($temporalFormatters),
            'wireShapes' => $wireShapes,
            'queryReaders' => array_values($queryReaders),
            'queryListReaders' => array_values($queryListReaders),
            'factoryLocal' => $this->symfonyFactoryLocalName($params),
            'validationConstsBlock' => $validationParts['consts'],
            'validationMethodsBlock' => $validationParts['methods'],
            'serializationGroups' => $needsGroups,
        ]);
    }

    private function resetSymfonyReachabilityCache(): void
    {
        $this->symfonyClassesUnderADateContext = null;
    }

    /**
     * The object-wide keywords of what the class is built from with `allOf` — a `$ref`'d parent's `not`,
     * `anyOf` / `oneOf`, `if`/`then`/`else`, `minProperties`, `dependentRequired`, … — down the whole
     * chain (`A allOf $ref B`, `B allOf $ref C`), one `allOf` entry per branch, never merged, so two
     * parents that both carry a `not` keep both.
     *
     * Symfony writes the child as one flat class with the parent's properties merged in
     * (`classSchemaWithInlineAllOfMerged()`), and nothing extends the parent, so its object-wide keywords
     * were carried nowhere: `{"yak": {"size": 7}}` passed Square, built from `allOf: [$ref Circle]`,
     * while Circle itself refused it against its `not`. The class's own inline branches are merged into
     * its schema already and are not repeated here.
     *
     * @param array<string, mixed> $filtered
     * @return array<string, mixed>
     */
    private function symfonyWithAllOfParentObjectKeywords(array $filtered, string $className): array
    {
        $parts = [];
        foreach ($this->symfonyAllOfParentObjectKeywords(className: $className, visitedClasses: [$className], ownInlineBranches: false) as $part) {
            // A parent's lower bound counts what the class declares, and the child may admit keys it
            // does not: `symfonyWithoutUncountableMinProperties()` with the child's own permission.
            $countable = $this->symfonyWithoutUncountableMinProperties([
                ...$part,
                ...array_intersect_key($filtered, ['additionalProperties' => true, 'patternProperties' => true]),
            ]);
            $filteredPart = $this->filterInterpreterConstraints(
                constraints: array_intersect_key($countable, $part),
                allowScalarKeywords: true,
            );
            if ($filteredPart !== []) {
                $parts[] = $filteredPart;
            }
        }
        if ($parts === []) {
            return $filtered;
        }

        return [...$filtered, 'allOf' => [...(is_array($filtered['allOf'] ?? null) ? $filtered['allOf'] : []), ...$parts]];
    }

    /**
     * The object-wide keywords of each `allOf` branch a class inherits, extracted with their `$ref`s
     * spelled out — see `symfonyWithAllOfParentObjectKeywords()`.
     *
     * `properties` and `required` stay out: the class declares the parent's properties itself, and
     * repeating them would report one violation twice. A parent's `anyOf` / `oneOf` naming classes is
     * taken only where it judges that parent as a whole (beside its own `allOf`): a discriminator base's
     * `oneOf: [$ref Cat, $ref Dog]` describes its variants, not a check every variant must pass.
     * A parent's own `additionalProperties` / `patternProperties` makes its `minProperties` uncountable.
     *
     * @param list<string> $visitedClasses the chain walked so far, so a cycle ends
     * @param bool $ownInlineBranches whether the class's inline branches are taken — false for the class
     *                                the constraints are built for, whose schema has them merged in
     * @return list<array<string, mixed>>
     */
    private function symfonyAllOfParentObjectKeywords(string $className, array $visitedClasses, bool $ownInlineBranches): array
    {
        $allOf = $this->dtoSchemas[$className]['allOf'] ?? null;
        if (!is_array($allOf)) {
            return [];
        }

        $parts = [];
        foreach ($allOf as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $ref = $branch['$ref'] ?? null;
            if (!is_string($ref)) {
                $part = $ownInlineBranches ? $this->symfonyBranchObjectKeywords(sourceClass: $className, branch: $branch) : [];
                if ($part !== []) {
                    $parts[] = $part;
                }
                continue;
            }

            $parentClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
            $parentSchema = $this->dtoSchemas[$parentClass] ?? null;
            if (in_array($parentClass, $visitedClasses, true) || !is_array($parentSchema)) {
                continue;
            }
            $part = $this->symfonyBranchObjectKeywords(sourceClass: $parentClass, branch: $parentSchema);
            if ($part !== []) {
                $parts[] = $part;
            }
            $parts = [
                ...$parts,
                ...$this->symfonyAllOfParentObjectKeywords(
                    className: $parentClass,
                    visitedClasses: [...$visitedClasses, $parentClass],
                    ownInlineBranches: true,
                ),
            ];
        }

        return $parts;
    }

    /**
     * The object-wide keywords one `allOf` branch asserts, extracted with its `$ref`s spelled out.
     *
     * @param array<mixed> $branch
     * @return array<string, mixed>
     */
    private function symfonyBranchObjectKeywords(string $sourceClass, array $branch): array
    {
        $objectWide = [];
        foreach ([...self::SYMFONY_INHERITED_OBJECT_KEYWORDS, 'anyOf', 'oneOf'] as $keyword) {
            if (!array_key_exists($keyword, $branch)) {
                continue;
            }
            $unionNamesClasses = in_array($keyword, ['anyOf', 'oneOf'], true)
                && (!is_array($branch[$keyword]) || $this->unionHasReferenceBranch($branch[$keyword]));
            if (!$unionNamesClasses) {
                $objectWide[$keyword] = $branch[$keyword];
            }
        }

        $extracted = $objectWide === [] ? [] : $this->withConstraintSourceOf(
            className: $sourceClass,
            extraction: fn(): array => $this->extractValidationConstraints(
                $this->inlineNestedContainerValidation(schema: $objectWide, belowMaterialization: true),
            ),
        );
        $part = [];
        foreach ([...self::SYMFONY_INHERITED_OBJECT_KEYWORDS, 'anyOf', 'oneOf'] as $keyword) {
            if (array_key_exists($keyword, $extracted)) {
                $part[$keyword] = $extracted[$keyword];
            }
        }

        // A union of classes beside the parent's own `allOf` does judge the parent as a whole.
        $parentSchema = $this->dtoSchemas[$sourceClass] ?? [];
        if ($parentSchema === $branch && is_array($parentSchema['allOf'] ?? null) && $parentSchema['allOf'] !== []) {
            foreach (['anyOf', 'oneOf'] as $keyword) {
                if (is_array($branch[$keyword] ?? null) && $this->unionHasReferenceBranch($branch[$keyword])) {
                    $part[$keyword] = $this->classLevelUnionWithReferencesInlined(
                        className: $sourceClass,
                        keyword: $keyword,
                        branches: $branch[$keyword],
                    );
                }
            }
        }

        // The keys the document gives a schema of their own are dropped by the serializer, so a lower
        // bound beside them cannot be counted.
        $additional = $branch['additionalProperties'] ?? null;
        $patterns = $branch['patternProperties'] ?? null;
        if (array_key_exists('minProperties', $part) && (is_array($additional) || $additional === true || (is_array($patterns) && $patterns !== []))) {
            $part = array_diff_key($part, ['minProperties' => true]);
        }

        return $part;
    }

    /**
     * The class-level `anyOf` beside the `allOf` the class is built from. The filter leaves an `anyOf`
     * to the property's `AtLeastOneOf`, and at the class level there is no property to carry one, so
     * it was checked by nothing: `{allOf: [$ref Base, …], anyOf: [{required: [a]}, {required: [b]}]}`
     * accepted `{}`. The callback carries it, as it already carries a `oneOf` there.
     *
     * A class-level `oneOf` with a `$ref` branch is replaced by its spelled-out form as well: the callback
     * judges the class's own payload, which is never an instance of a branch class, so "is a Cat"
     * matched no branch and every payload of `{allOf: […], oneOf: [$ref Cat, $ref Dog]}` was refused.
     *
     * @param array<string, mixed> $filtered
     * @param array<string, mixed> $extracted
     * @return array<string, mixed>
     */
    private function symfonyWithClassLevelAnyOf(array $filtered, string $className, array $extracted): array
    {
        $unions = $this->classLevelUnionsBesideAllOf(className: $className, extracted: $extracted);
        $oneOf = $unions['oneOf'] ?? null;
        $schemaOneOf = $this->dtoSchemas[$className]['oneOf'] ?? null;
        if (is_array($oneOf) && is_array($schemaOneOf) && $this->unionHasReferenceBranch($schemaOneOf)) {
            $filtered = [...$filtered, ...$this->filterInterpreterConstraints(constraints: ['oneOf' => $oneOf], allowScalarKeywords: true)];
        }
        $anyOf = $unions['anyOf'] ?? null;
        if (!is_array($anyOf)) {
            return $filtered;
        }

        return [...$filtered, ...$this->filterInterpreterConstraints(constraints: ['anyOf' => $anyOf], allowScalarKeywords: true)];
    }

    /**
     * The constraints of a whole class, before the callback filter. A `$ref` union branch under a
     * property typed `mixed` names a class nothing hydrates, so those properties are spelled out rather
     * than checked as "is that class".
     *
     * @param array<string, mixed> $schemaDefinition
     * @param array<string, true> $mixedProperties
     * @return array<string, mixed>
     */
    private function symfonyExtractedClassConstraints(string $className, array $schemaDefinition, array $mixedProperties): array
    {
        return $this->withConstraintSourceOf(
            className: $className,
            extraction: function () use ($className, $schemaDefinition, $mixedProperties): array {
                // Ahead of the list below: reading the class's properties extracts their constraints,
                // and that extraction consumes the list as its own.
                $classSchema = $this->symfonyWithRawContainerBranchesInlined(
                    classSchema: $this->classSchemaWithInlineAllOfMerged(schemaDefinition: $schemaDefinition, className: $className),
                    className: $className,
                );
                $rawObjectSchemas = $this->symfonyRawObjectSchemasOfHeldClasses(classSchema: $classSchema, className: $className);
                $this->constraintUnmaterializedProperties = $mixedProperties;

                return $this->symfonyWithRawObjectSchemasAttached(
                    constraints: $this->extractValidationConstraints($this->symfonyWithDiscriminatedUnionPropertiesSpelledOut($classSchema)),
                    rawObjectSchemas: $rawObjectSchemas,
                );
            },
        );
    }

    /**
     * The written-out schema of each generated class a property holds beside `array` (`array|Pet`), per
     * property and class. The serializer falls back to the array member when it cannot build the class
     * — an unknown or missing tag, `[]`, a key the class refuses — and the branch "is that class" let
     * that raw object through unjudged: `{"tern": {"kind": null}}` and `{"tern": []}` passed `anyOf:
     * [{type: array, minItems: 1}, $ref Pet]`. The interpreter judges such a value against this schema
     * (`x-php-raw-object`), as the runtime validator judges a raw object against the class's metadata.
     *
     * @param array<string, mixed> $classSchema
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function symfonyRawObjectSchemasOfHeldClasses(array $classSchema, string $className): array
    {
        if (!is_array($classSchema['properties'] ?? null)) {
            return [];
        }
        $rendered = $this->symfonyWithFreeFormObjectUnionsHeldAsArray(
            className: $className,
            properties: $this->symfonyWithUnionListsTyped($className, $this->getSchemaProperties($className)),
        );
        $schemas = [];
        foreach ($rendered as $property) {
            $typeMembers = array_map($this->shortClassName(...), explode('|', ltrim($property['type'], '?')));
            $propertySchema = $classSchema['properties'][$property['openApiName']] ?? null;
            if (!in_array('array', $typeMembers, true) || !is_array($propertySchema)) {
                continue;
            }
            foreach ($this->symfonyUnionBranchReferences($propertySchema) as $ref) {
                $referenced = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
                if (!in_array($referenced, $typeMembers, true) || !array_key_exists($referenced, $this->dtoSchemas)) {
                    continue;
                }
                // The raw object is no class, so none judges the `allOf` it is built from: below a container.
                $schemas[$property['openApiName']][$referenced] = $this->filterInterpreterConstraints(
                    constraints: $this->extractValidationConstraints($this->symfonyRawContainerInlined(['$ref' => $ref])),
                    allowScalarKeywords: true,
                    belowContainer: true,
                );
            }
        }

        return $schemas;
    }

    /**
     * The `$ref`s that stand as branches of a property's `oneOf` / `anyOf`, through `allOf` parts and
     * nested unions.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function symfonyUnionBranchReferences(array $schema, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [];
        }
        $refs = [];
        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $ref = $this->splitBranchReference($branch)[0];
                if ($ref !== null && $keyword !== 'allOf') {
                    $refs[] = $ref;

                    continue;
                }
                $refs = [...$refs, ...$this->symfonyUnionBranchReferences($branch, $depth + 1)];
            }
        }

        return $refs;
    }

    /**
     * The extracted class constraints with each property's "is that class" union branch carrying the
     * class written out, see `symfonyRawObjectSchemasOfHeldClasses()`.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, array<string, array<string, mixed>>> $rawObjectSchemas
     * @return array<string, mixed>
     */
    private function symfonyWithRawObjectSchemasAttached(array $constraints, array $rawObjectSchemas): array
    {
        if ($rawObjectSchemas === [] || !is_array($constraints['properties'] ?? null)) {
            return $constraints;
        }
        $properties = [];
        foreach ($constraints['properties'] as $name => $propertyConstraints) {
            $schemas = $rawObjectSchemas[(string)$name] ?? [];
            $properties[$name] = is_array($propertyConstraints) && $schemas !== []
                ? $this->symfonyClassBranchesWithRawObjectSchema(schema: $propertyConstraints, rawObjectSchemas: $schemas)
                : $propertyConstraints;
        }

        return ['properties' => $properties] + $constraints;
    }

    /**
     * @param array<mixed> $schema
     * @param array<string, array<string, mixed>> $rawObjectSchemas
     * @return array<mixed>
     */
    private function symfonyClassBranchesWithRawObjectSchema(array $schema, array $rawObjectSchemas, int $depth = 0): array
    {
        if ($depth >= 8) {
            return $schema;
        }
        $branchClass = is_string($schema['x-php-instanceof'] ?? null) ? $this->shortClassName($schema['x-php-instanceof']) : null;
        if (is_string($branchClass) && array_key_exists($branchClass, $rawObjectSchemas)) {
            $schema['x-php-raw-object'] = $rawObjectSchemas[$branchClass];
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $index => $branch) {
                if (is_array($branch)) {
                    $schema[$keyword][$index] = $this->symfonyClassBranchesWithRawObjectSchema(
                        schema: $branch,
                        rawObjectSchemas: $rawObjectSchemas,
                        depth: $depth + 1,
                    );
                }
            }
        }

        return $schema;
    }

    /**
     * The class's own `minProperties`, dropped where the payload may carry keys the class does not
     * declare.
     *
     * The serializer drops an undeclared key before anything validates, so the callback counts only
     * the declared ones that arrived. Where the document gives other keys a schema of their own —
     * `additionalProperties: {…}` or `true`, `patternProperties` — they are part of the payload it
     * describes, the count is short by however many were dropped, and `{"alpha":"a","x":1}` against
     * `minProperties: 2` came back a 422 although the document allows it. A lower bound that cannot
     * be counted refuses valid bodies, so it is not checked there. `maxProperties` stays: the count it
     * reads can only be too low, which never refuses a valid body.
     *
     * A schema that merely does not forbid other keys keeps the bound: there the dropped key is the
     * documented "unknown JSON key" of README.symfony, and counting what the class declares is what
     * makes `minProperties` mean anything in this mode at all.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function symfonyWithoutUncountableMinProperties(array $constraints): array
    {
        if (!array_key_exists('minProperties', $constraints)) {
            return $constraints;
        }

        $additional = $constraints['additionalProperties'] ?? null;
        $patterns = $constraints['patternProperties'] ?? null;
        if (!is_array($additional) && $additional !== true && (!is_array($patterns) || $patterns === [])) {
            return $constraints;
        }

        $countable = [];
        foreach ($constraints as $keyword => $value) {
            if ($keyword !== 'minProperties') {
                $countable[$keyword] = $value;
            }
        }

        return $countable;
    }

    /**
     * Class names inside a PHP type string: `?Foo`, `array<Foo>`, `Foo|Bar`, `array<string, Foo>`.
     *
     * @return array<int, string>
     */
    private function generatedClassNamesInType(string $type): array
    {
        $names = [];
        $candidates = preg_split('/[<>|,\s]+/', $type);
        foreach ($candidates === false ? [] : $candidates as $candidate) {
            $candidate = ltrim(trim($candidate), '?\\');
            if ($candidate === '') {
                continue;
            }
            $short = $this->shortClassName($candidate);
            if (array_key_exists($short, $this->dtoSchemas)) {
                $names[] = $short;
            }
        }

        return $names;
    }

    /**
     * The class schema with every container branch of a property's `oneOf` / `anyOf` written out in
     * place, where the property is held as the decoded `array` (`?array`, `array|string`).
     *
     * No class is built below such a property, so a `$ref` there names a class nothing hydrates: as a
     * union branch it became "is that class" and refused every valid object (`{"lynx": {"ka": {…}}}`
     * against a map of a nullable union component), and as a bare `items` / `additionalProperties` it
     * extracted to nothing and accepted every invalid one.
     *
     * Where the property's type names no generated class at all (`oneOf: [{$ref: Teal}, {type: object,
     * additionalProperties: false}]` is held as `?array`), an object `$ref` branch is written out too:
     * "is a Teal" was never true of the array, so `{"panda": -12}` matched no branch and `{}` matched
     * only the closed member.
     *
     * @param array<string, mixed> $classSchema
     * @return array<string, mixed>
     */
    private function symfonyWithRawContainerBranchesInlined(array $classSchema, string $className): array
    {
        if (!is_array($classSchema['properties'] ?? null)) {
            return $classSchema;
        }
        $rawHeld = [];
        // Typed as rendered: a list of a union component beside a scalar is `array<Shape>|int`, and
        // the serializer builds its variants; a union with a free-form or closed empty object member
        // is held as the decoded `array`, its classes dropped.
        $rendered = $this->symfonyWithFreeFormObjectUnionsHeldAsArray(
            className: $className,
            properties: $this->symfonyWithUnionListsTyped($className, $this->getSchemaProperties($className)),
        );
        foreach ($rendered as $property) {
            $typeMembers = explode('|', ltrim($property['type'], '?'));
            if (in_array('array', $typeMembers, true)) {
                $rawHeld[$property['openApiName']] = $typeMembers;
            }
        }
        // A union component of classes is held as the decoded object wherever it sits — the property, a
        // list item, a map value: written out in place, members and all.
        $heldDecoded = $this->symfonyUndiscriminatedUnionsHeldDecoded($className, $rendered);
        $properties = [];
        foreach ($classSchema['properties'] as $name => $propertySchema) {
            if (is_array($propertySchema) && array_key_exists((string)$name, $heldDecoded)) {
                $properties[$name] = $this->symfonyNullableLifted($this->symfonyRawContainerInlined($propertySchema));

                continue;
            }
            if (is_array($propertySchema) && array_key_exists((string)$name, $rawHeld)) {
                $propertySchema = $this->symfonyHeldUnionBranchesInlined(
                    schema: $this->symfonyContainerUnionReferenceInlined($propertySchema),
                    className: $className,
                    holdsNoClass: $this->symfonyTypeHoldsNoGeneratedClass($rawHeld[(string)$name]),
                    heldMembers: $rawHeld[(string)$name],
                );
            }
            $properties[$name] = $propertySchema;
        }

        return ['properties' => $properties] + $classSchema;
    }

    /**
     * The `oneOf` / `anyOf` branches of a union held as the decoded array, see
     * `symfonyWithRawContainerBranchesInlined()` — a union NESTED in a branch included, which is held as
     * the same array.
     *
     * An inline object branch written with `allOf` is merged and written out as well. It was left for
     * the class it names, which the array never becomes: the filter reduced it to `{type: object}`,
     * `required` and `properties` gone, so `{}` matched it — and `{"gecko": 1}` matched it beside the
     * branch that requires `gecko`, refused as matching twice.
     *
     * Where the property holds no generated class, an inline object branch keeps the enums of its
     * properties as their members (`withPropertyEnumsSpelledOut()`): each had moved into a PHP enum the
     * array never holds, and `{"bison": {"raven": 3}}` passed `enum: [0]` beside a map of booleans.
     *
     * A `$ref` branch whose class the held type does not name is written out wherever the type names
     * another: `oneOf: [{enum: [green]}, $ref Umber, {type: object, required: [bison]}]` is held as
     * `ProbeRaven|array`, the enum kept, and "is an Umber" was never true of the array — `{"plover":
     * 65}` was refused.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $heldMembers the members of the held PHP type
     * @return array<string, mixed>
     */
    private function symfonyHeldUnionBranchesInlined(array $schema, string $className, bool $holdsNoClass, array $heldMembers, int $depth = 0): array
    {
        if ($depth >= 8) {
            return $schema;
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $index => $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if ($this->isContainerBranch($branch) || $this->symfonyBranchClassNotHeld(branch: $branch, className: $className, heldMembers: $heldMembers)) {
                    $schema[$keyword][$index] = $this->symfonyRawContainerInlined($branch);

                    continue;
                }
                if (array_key_exists('$ref', $branch)) {
                    continue;
                }
                if ($holdsNoClass) {
                    $branch = $this->withPropertyEnumsSpelledOut($branch);
                }
                $branch = $this->symfonyHeldUnionBranchesInlined(
                    schema: $branch,
                    className: $className,
                    holdsNoClass: $holdsNoClass,
                    heldMembers: $heldMembers,
                    depth: $depth + 1,
                );
                if (is_array($branch['allOf'] ?? null) && $this->interpreterShadowType($branch) === 'object') {
                    $branch = $this->symfonyRawContainerInlined($this->classSchemaWithInlineAllOfMerged(schemaDefinition: $branch, className: $className));
                }
                $schema[$keyword][$index] = $branch;
            }
        }

        return $schema;
    }

    /**
     * Whether a union branch is a `$ref` to a class or enum the held PHP type does not name, so the
     * decoded value is never one and the branch has to be judged written out.
     *
     * @param array<mixed> $branch
     * @param list<string> $heldMembers
     */
    private function symfonyBranchClassNotHeld(array $branch, string $className, array $heldMembers): bool
    {
        $ref = $this->splitBranchReference($branch)[0];
        if ($ref === null) {
            return false;
        }
        $referenced = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));

        return !in_array($referenced, array_map($this->shortClassName(...), $heldMembers), true);
    }

    /**
     * Whether a rendered property type names no generated object class: every member a PHP type of its
     * own or a backed enum, so an object the property holds is always the decoded array, never a class
     * the serializer built from one of the union's branches.
     *
     * A backed enum beside the array holds a scalar only. Counted as a class, `ProbeWombatAnyOf2|array`
     * (an integer-enum branch beside an object and a list) left the object branch's property enums to a
     * PHP enum the array never holds, and `{"urchin": 9}` passed `enum: [27, 15]`.
     *
     * @param list<string> $typeMembers
     */
    private function symfonyTypeHoldsNoGeneratedClass(array $typeMembers): bool
    {
        foreach ($typeMembers as $member) {
            $short = $this->shortClassName($member);
            if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A property `$ref` to a union component with a container branch (`Amber: {oneOf: [{type: object,
     * additionalProperties: …}, {type: null}]}`), followed through bare aliases, written out in place,
     * so its container branches are judged as the inline union's are — and one with an object held as
     * the decoded array, or a discriminated union as a member. Anything else comes back as is.
     *
     * `{allOf: [$ref Crim]}` is the same reference: left wrapped, the class branch of a union typed
     * `?array` stayed "is an Ivory", which every raw object passed.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function symfonyContainerUnionReferenceInlined(array $propertySchema): array
    {
        $ref = $this->referenceOrSingleRefAllOf($propertySchema);
        // A union with a member another document writes is held as the decoded array in every spelling
        // (`symfonyUnionHasMemberWrittenElsewhere()`), the one beside a null included: written out here,
        // the null kept as `nullable`.
        $reference = $this->unionReferenceTheSchemaConsistsOf($propertySchema);
        $nullable = false;
        if ($ref === null && $reference !== null && $this->symfonyUnionHasMemberWrittenElsewhere($propertySchema)) {
            $ref = $reference['ref'];
            $nullable = $reference['nullable'];
        }
        $visited = [];
        $definition = null;
        while (is_string($ref) && !in_array($ref, $visited, true)) {
            $visited[] = $ref;
            $definition = $this->localComponentDefinition($ref);
            $ref = $this->isAnnotatedReference($definition) ? $definition['$ref'] : null;
        }
        if (!is_array($definition)) {
            return $propertySchema;
        }
        // The definition is read RAW, so an alias inside it (`items: {$ref: Amber}`, `Amber: {$ref:
        // Azure}`) still names the component that has no class and no schema of its own: the item was
        // written out as nothing, and `[true]` passed a list of objects.
        $redirected = $this->withRedirectedAliases($definition, $this->pureAliasRedirects);
        $definition = is_array($redirected) ? $redirected : $definition;
        $hasContainerBranch = false;
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($definition[$keyword] ?? null) ? $definition[$keyword] : [] as $branch) {
                // An object held as the decoded array (`{type: object, required: [yak]}`) too: the union is
                // then written out where it is used, and its `$ref` branches judge that array, not a class.
                $hasContainerBranch = $hasContainerBranch
                    || (is_array($branch) && ($this->isContainerBranch($branch) || $this->isDecodedObjectBranch($branch)));
            }
        }
        // A discriminated union member holds the value as the decoded array too
        // (`symfonyUnionNestsDiscriminatedUnion()`), and the members its tag names judge that array.
        if (!$hasContainerBranch && !$this->symfonyUnionNestsDiscriminatedUnion($definition) && !$this->symfonyUnionHasMemberWrittenElsewhere($propertySchema)) {
            return $propertySchema;
        }
        // The members are written out below, so the implicit map — each member named by its schema —
        // is spelled now, while the `$ref`s still name them: read afterwards it was empty, and
        // `{"kind": "zzq"}` passed a discriminated union with a member of another document.
        if (is_array($definition['discriminator'] ?? null) && !array_key_exists('mapping', $definition['discriminator'])) {
            $implicitMapping = $this->implicitDiscriminatorMapping($definition);
            if ($implicitMapping !== []) {
                $definition['discriminator']['mapping'] = $implicitMapping;
            }
        }
        // The wrapper goes with the reference it held; an `allOf` beside a bare `$ref` stays.
        $referenceKeywords = array_key_exists('$ref', $propertySchema) ? ['$ref'] : ($reference['wrappers'] ?? ['allOf']);
        $own = [];
        foreach ($propertySchema as $keyword => $value) {
            if (!in_array($keyword, $referenceKeywords, true)) {
                $own[$keyword] = $value;
            }
        }
        if ($nullable) {
            $own['nullable'] = true;
        }

        // The component as written still holds `items: {$ref: Indigo, properties: {ferret: …}}`; inlined
        // in that spelling, the keys beside the reference took the place of Indigo's own, which went
        // unchecked. Both halves, as the same union written on the property is judged.
        return $this->objectReferencesWithTheirSiblings($own + $definition);
    }

    /**
     * `nullable: true` beside an inlined union, spelled as the null branch it means.
     *
     * Beside a `oneOf` whose members declare no `type` of their own (`Shape: {type: object, oneOf:
     * [{properties: …}, {properties: …}]}`), the null that `nullable` admits matched EVERY member, and
     * `{"shape": null}` was refused for matching more than one — on a property typed `?Shape`, where the
     * serializer had always let it through.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function symfonyNullableLifted(array $schema): array
    {
        if (($schema['nullable'] ?? null) !== true) {
            return $schema;
        }
        $rest = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== 'nullable') {
                $rest[$keyword] = $value;
            }
        }

        return ['anyOf' => [['type' => 'null'], $rest]];
    }

    /**
     * A union branch naming a union component of classes (`Shape: {oneOf: [$ref Square, $ref Circle]}`,
     * with a discriminator or without): "is a Shape" for the member the serializer built, and the
     * members written out (`x-php-raw-object`) for the decoded array it leaves where it builds none — a
     * property no PHP type spells (`anyOf: [{$ref: Shape}, {not: {type: object}}]` is `mixed`). Judged
     * as the bare class, that raw object passed `anyOf` unchecked and failed every `oneOf`. A plain class
     * needs none of this: below a `mixed` property its reference is written out already.
     *
     * @return array<string, mixed>
     */
    private function symfonyUnionComponentBranch(string $ref, string $interface): array
    {
        $members = $this->symfonyRawContainerInlined(['$ref' => $ref]);
        if (array_key_exists('$ref', $members)) {
            return ['x-php-instanceof' => $interface];
        }

        return [
            'x-php-instanceof' => $interface,
            // Nothing is built from a raw value, so no class judges an `allOf` in it: below a container.
            'x-php-raw-object' => $this->filterInterpreterConstraints(
                constraints: $this->extractValidationConstraints($members),
                allowScalarKeywords: true,
                belowContainer: true,
            ),
        ];
    }

    /**
     * A container nothing materializes, its references — union components among them — inlined.
     *
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    private function symfonyRawContainerInlined(array $container): array
    {
        $inlinesBefore = $this->constraintInlinesUnionComponents;
        $this->constraintInlinesUnionComponents = true;
        try {
            return $this->inlineNestedContainerValidation(schema: $container, belowMaterialization: true);
        } finally {
            $this->constraintInlinesUnionComponents = $inlinesBefore;
        }
    }

    /**
     * The class schema with each of its properties that names a discriminated union spelled out, and
     * the keywords beside an inline union of classes moved, see `symfonyDiscriminatedUnionSpelledOut()`.
     *
     * @param array<string, mixed> $classSchema
     * @return array<string, mixed>
     */
    private function symfonyWithDiscriminatedUnionPropertiesSpelledOut(array $classSchema): array
    {
        if (!is_array($classSchema['properties'] ?? null)) {
            return $classSchema;
        }
        $properties = [];
        foreach ($classSchema['properties'] as $name => $propertySchema) {
            $properties[$name] = is_array($propertySchema) ? $this->symfonyDiscriminatedUnionSpelledOut($propertySchema) : $propertySchema;
        }

        return ['properties' => $properties] + $classSchema;
    }

    /**
     * Symfony: a `$ref` to a discriminated union component is checked like the same union written
     * inline — each branch "is that class", plus "the tag names the class it sits in". The serializer
     * always builds the class the tag names, so only an object built by hand can disagree, and a
     * response DTO is exactly that: `new Holder(pet: new Cat(kind: 'dog'))` was written out as a dog.
     * The inline spelling had the check until 2.15.145 turned it into an interface of its own.
     *
     * A class's own properties only: two containers down the serializer leaves the value an array (the
     * type there is `mixed`), and a branch "is that class" refused every valid one.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function symfonyDiscriminatedUnionSpelledOut(array $propertySchema): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        if (!is_string($ref) || !str_starts_with($ref, '#/components/schemas/')) {
            return $this->symfonyInlineClassUnionOwnKeywords($propertySchema);
        }
        $targetClass = $this->schemaClassName($this->componentNameInPointer($ref));
        $target = $this->dtoSchemas[$targetClass] ?? null;
        if (!is_array($target) || !is_array($target['discriminator'] ?? null) || !is_array($target['discriminator']['mapping'] ?? null)) {
            return $propertySchema;
        }
        $unionKey = is_array($target['oneOf'] ?? null) ? 'oneOf' : (is_array($target['anyOf'] ?? null) ? 'anyOf' : null);
        if ($unionKey === null) {
            return $propertySchema;
        }
        $spelled = [];
        foreach ($propertySchema as $keyword => $value) {
            if ($keyword !== '$ref') {
                $spelled[$keyword] = $value;
            }
        }
        $spelled = $this->symfonyWithKeywordsBesideClassUnion(
            schema: $spelled,
            own: array_intersect_key($target, ['properties' => true, 'required' => true]),
            members: $this->symfonyUnionRefMembers($targetClass),
        );

        return [...$spelled, $unionKey => $target[$unionKey], 'discriminator' => $target['discriminator']];
    }

    /**
     * An inline `oneOf` / `anyOf` of classes with `properties` / `required` of its own beside it, those
     * moved where `symfonyWithKeywordsBesideClassUnion()` says. Left on the property, a `required` name
     * no member declares refused every payload — the built member cannot show the key — and a scalar
     * keyword under `properties` was taken for one a class attribute checks. Anything else as it is.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function symfonyInlineClassUnionOwnKeywords(array $propertySchema): array
    {
        if (!array_key_exists('properties', $propertySchema) && !array_key_exists('required', $propertySchema)) {
            return $propertySchema;
        }
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($propertySchema)));
        if (count($keywords) !== 1 || !is_array($propertySchema[$keywords[0]])) {
            return $propertySchema;
        }
        $members = [];
        foreach ($propertySchema[$keywords[0]] as $branch) {
            if (is_array($branch) && array_keys($branch) === ['type'] && $branch['type'] === 'null') {
                continue;
            }
            $member = $this->isAnnotatedReference($branch)
                ? $this->schemaRefToClassName($branch['$ref'])
                : null;
            if ($member === null || !array_key_exists($member, $this->dtoSchemas)) {
                return $propertySchema;
            }
            $members[] = $member;
        }
        $rest = [];
        foreach ($propertySchema as $keyword => $value) {
            if (!in_array($keyword, ['properties', 'required'], true)) {
                $rest[$keyword] = $value;
            }
        }

        return $this->symfonyWithKeywordsBesideClassUnion(
            schema: $rest,
            own: array_intersect_key($propertySchema, ['properties' => true, 'required' => true]),
            members: $members,
        );
    }

    /**
     * The `properties` and `required` a union of classes carries beside its `oneOf` / `anyOf`, added to
     * the schema as one more `allOf` branch. JSON Schema applies them to the same value, so a
     * component's `value: {type: integer}` refuses `{"kind": "mail", "value": "x"}` where `Mail`
     * declares `value`.
     *
     * Only what the built member can show: the serializer keeps no key the member does not declare,
     * so such a key is dropped unchecked (README.support-matrix, "a property beside a union of
     * classes"), and a `required` name some member lacks is left out — it would refuse every payload
     * of that member. Spelled `{if: {type: object}, then: {…}}`, the same assertion — `properties` and
     * `required` say nothing about a value that is not an object — because the interpreter judges
     * such a branch with its scalar keywords: on the property itself a scalar keyword is taken for one
     * a class attribute checks, and an `allOf` branch with `properties` for one the nested class
     * cascades into.
     *
     * @param array<string, mixed> $schema
     * @param array<mixed> $own
     * @param array<int, string> $members
     * @return array<string, mixed>
     */
    private function symfonyWithKeywordsBesideClassUnion(array $schema, array $own, array $members): array
    {
        $kept = [];
        if (is_array($own['properties'] ?? null) && $own['properties'] !== []) {
            $kept['properties'] = $own['properties'];
        }
        $required = [];
        foreach (is_array($own['required'] ?? null) ? $own['required'] : [] as $name) {
            $everyMemberDeclares = $members !== [];
            foreach ($members as $member) {
                $everyMemberDeclares = $everyMemberDeclares && is_array($this->dtoSchemas[$member]['properties'][$name] ?? null);
            }
            if (is_string($name) && $everyMemberDeclares) {
                $required[] = $name;
            }
        }
        if ($required !== []) {
            $kept['required'] = $required;
        }
        if ($kept === []) {
            return $schema;
        }

        return [
            ...$schema,
            'allOf' => [...(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []), ['if' => ['type' => 'object'], 'then' => $kept]],
        ];
    }

    /**
     * The generated classes a union schema names through its `oneOf` / `anyOf` `$ref` branches.
     *
     * @return array<int, string>
     */
    private function symfonyUnionRefMembers(string $className): array
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        $members = [];
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            foreach (is_array($schema[$unionKey] ?? null) ? $schema[$unionKey] : [] as $branch) {
                $ref = is_array($branch) ? ($branch['$ref'] ?? null) : null;
                if (!is_string($ref)) {
                    continue;
                }
                $member = $this->schemaRefToClassName($ref);
                if (array_key_exists($member, $this->dtoSchemas)) {
                    $members[] = $member;
                }
            }
        }

        return $members;
    }

    /**
     * @return array<int, string>
     */
    private function unionMembersOf(string $interfaceName): array
    {
        $members = [];
        foreach ($this->unionInterfacesByClass as $memberClass => $interfaces) {
            if (in_array($interfaceName, $interfaces, true)) {
                $members[] = $memberClass;
            }
        }

        return $members;
    }

    /**
     * The payload view for a DTO that has no generated `#[Assert\Callback]` — same contract as the
     * one the callback block emits, written out property by property so it needs no constants.
     *
     * @param array<int, array{name: string, required: bool, serializedName: ?string, providedFlag: string}> $params
     */
    private function renderSymfonyStandalonePayloadMethod(array $params): string
    {
        $lines = [];
        foreach ($params as $param) {
            $openApiName = $param['serializedName'] ?? $param['name'];
            $key = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $openApiName) . "'";
            if ($param['required'] === true) {
                $lines[] = sprintf('        $payload[%s] = $this->%s;', $key, $param['name']);
                continue;
            }
            $lines[] = sprintf('        if ($this->%s) {', $param['providedFlag']);
            $lines[] = sprintf('            $payload[%s] = $this->%s;', $key, $param['name']);
            $lines[] = '        }';
        }

        $body = implode("\n", $lines);

        return <<<PHP
    /**
     * This DTO as an OpenAPI-named payload: what it received, under the names the schema uses.
     * Machinery for the generated validation, not an API to call — an enclosing DTO reads a nested
     * one through here, because the properties themselves are private. For output, use the Symfony
     * serializer.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toOpenApiValidationPayload(): array
    {
        \$payload = [];
{$body}

        return \$payload;
    }
PHP;
    }

    /**
     * Whether a pruned constraint tree can never report anything: it holds only `properties` and
     * `items` that lead to more of the same, and `nullable`, which merely relaxes a `type` check that
     * is no longer there. Emitting the interpreter for it added a constant, a callback and some two
     * hundred lines to a class that the PHP types already describe completely. A boolean `true`
     * subschema (`items: {}` arrives as one) accepts everything as well.
     *
     * @param array<mixed>|bool $schema
     */
    private static function constraintsAreInert(array|bool $schema): bool
    {
        if (!is_array($schema)) {
            return $schema;
        }
        foreach ($schema as $keyword => $value) {
            if ($keyword === 'nullable') {
                continue;
            }
            if ($keyword === 'items' && (is_array($value) || is_bool($value)) && self::constraintsAreInert($value)) {
                continue;
            }
            if ($keyword !== 'properties' || !is_array($value)) {
                return false;
            }
            foreach ($value as $propertySchema) {
                if ((!is_array($propertySchema) && !is_bool($propertySchema)) || !self::constraintsAreInert($propertySchema)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Drops property-level keywords the generated PHP type already guarantees: a
     * `DateTimeImmutable` property cannot violate `type: string` / `format: date-time`, an enum
     * class cannot hold a value outside its `enum`, and a scalar type hint cannot hold the wrong
     * JSON type. Keeping them would emit an interpreter (and its helpers) into DTOs whose schema
     * needs no runtime checking at all.
     *
     * @param array<string, mixed> $constraints
     * @param array<int, array{name: string, declaredType: string, serializedName: string|null}> $params
     * @return array<string, mixed>
     */
    private function pruneConstraintsCoveredByPhpType(array $constraints, array $params): array
    {
        if (!is_array($constraints['properties'] ?? null)) {
            return $constraints;
        }

        $declaredTypeByOpenApiName = [];
        foreach ($params as $param) {
            $declaredTypeByOpenApiName[$param['serializedName'] ?? $param['name']] = $param['declaredType'];
        }

        foreach ($constraints['properties'] as $openApiName => $propertySchema) {
            if (!is_array($propertySchema) || !array_key_exists($openApiName, $declaredTypeByOpenApiName)) {
                continue;
            }
            $constraints['properties'][$openApiName] = $this->stripPhpEnforcedKeywords(
                propertySchema: $propertySchema,
                declaredType: $declaredTypeByOpenApiName[$openApiName],
            );
        }

        // Nothing left to check anywhere: drop the map so no callback is emitted for this DTO.
        $hasMeaningfulPropertyConstraints = false;
        foreach ($constraints['properties'] as $propertySchema) {
            if ($propertySchema !== []) {
                $hasMeaningfulPropertyConstraints = true;
                break;
            }
        }
        if (
            !$hasMeaningfulPropertyConstraints
            && !array_key_exists('additionalProperties', $constraints)
            && !array_key_exists('unevaluatedProperties', $constraints)
        ) {
            unset($constraints['properties']);
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function stripPhpEnforcedKeywords(array $propertySchema, string $declaredType): array
    {
        $type = ltrim($declaredType, '?');
        $short = $this->shortClassName($type);

        if ($short === 'DateTimeImmutable') {
            if (in_array($propertySchema['format'] ?? null, ['date', 'date-time', 'datetime'], true)) {
                unset($propertySchema['format']);
            }
            if (($propertySchema['type'] ?? null) === 'string') {
                unset($propertySchema['type']);
            }

            return $propertySchema;
        }

        if (array_key_exists($short, $this->enumSchemas)) {
            return array_filter(
                array: $propertySchema,
                callback: static fn(string $keyword): bool => $keyword !== 'type'
                    && ($keyword !== 'enum' || str_starts_with($declaredType, '?')),
                mode: ARRAY_FILTER_USE_KEY,
            );
        }

        if (array_key_exists($short, $this->dtoSchemas)) {
            if (($propertySchema['type'] ?? null) === 'object') {
                unset($propertySchema['type']);
            }

            return $propertySchema;
        }

        $scalarByPhpType = ['int' => 'integer', 'float' => 'number', 'string' => 'string', 'bool' => 'boolean'];
        if (array_key_exists($type, $scalarByPhpType) && ($propertySchema['type'] ?? null) === $scalarByPhpType[$type]) {
            unset($propertySchema['type']);
        }

        return $propertySchema;
    }

    /**
     * Renders a oneOf/anyOf schema (or a discriminated base) as a marker interface its members
     * implement. With a discriminator the interface also carries `#[DiscriminatorMap]`, which is
     * what lets the Symfony serializer denormalize the payload into the right branch.
     *
     * @param array<int, string> $unionTypes
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
     * @param array<int, SchemaProperty> $sharedProperties the base's own properties, for an allOf base
     */
    private function renderSymfonyUnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
        array $sharedProperties = [],
    ): string {
        $imports = [];
        $interfaceGetters = [];
        foreach ($sharedProperties as $property) {
            $interfaceGetters[] = $this->resolveSymfonyParam($property, $namespace);
        }
        if ($sharedProperties !== []) {
            foreach ($this->collectGeneratedClassImports($namespace, $className, $sharedProperties, null, [], null) as $import) {
                $imports[] = $import;
            }
            if ($this->needsDateTimeImmutableImport($sharedProperties)) {
                $imports[] = 'DateTimeImmutable';
            }
        }
        $memberNames = $unionTypes !== [] ? $unionTypes : $this->symfonyDiscriminatedMembers($className, $discriminator);
        foreach ($memberNames as $member) {
            $this->appendImportForClass($imports, $member, $namespace, $className);
        }

        // A nested union: this interface is itself a branch of an outer one.
        $interfaceExtends = [];
        foreach ($this->unionInterfacesByClass[$className] ?? [] as $outerUnion) {
            if ($outerUnion === $className) {
                continue;
            }
            $this->appendImportForClass($imports, $outerUnion, $namespace, $className);
            $interfaceExtends[] = $this->formatClassNameForNamespace($outerUnion, $namespace);
        }

        $discriminatorMap = null;
        if ($discriminator !== null && $discriminator['mapping'] !== []) {
            $entries = [];
            foreach ($discriminator['mapping'] as $discriminatorValue => $mappedClass) {
                $this->appendImportForClass($imports, $mappedClass, $namespace, $className);
                $entries[] = sprintf(
                    "'%s' => %s::class",
                    $this->escapeSingleQuoted((string)$discriminatorValue),
                    $this->formatClassNameForNamespace($mappedClass, $namespace),
                );
            }
            $discriminatorMap = sprintf(
                "#[\\Symfony\\Component\\Serializer\\Attribute\\DiscriminatorMap(typeProperty: '%s', mapping: [%s])]",
                $this->escapeSingleQuoted($discriminator['propertyName']),
                implode(', ', $entries),
            );
        }

        // The serializer cannot build an interface with no `#[DiscriminatorMap]`, so a body that IS the
        // union is a 500 under `#[MapRequestPayload]`; the members it names are what an application
        // tries the payload against itself (README.symfony, "A body that is a union without a discriminator").
        $memberList = $discriminator === null ? $this->undiscriminatedUnionMemberList($className, $unionTypes) : null;

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $this->renderPhpTemplate('dto.symfony.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $className,
            'unionMemberList' => $memberList === null ? null : [
                'keyword' => $memberList['keyword'],
                'members' => array_map(
                    callback: fn(string $member): string => $this->formatClassNameForNamespace($member, $namespace),
                    array: $memberList['members'],
                ),
            ],
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'extends' => null,
            'params' => [],
            'validationConstsBlock' => '',
            'validationMethodsBlock' => '',
            'implementedInterfaces' => [],
            'unionMembers' => $memberNames === []
                ? null
                : implode('|', array_map(
                    callback: fn(string $member): string => $this->formatClassNameForNamespace($member, $namespace),
                    array: $memberNames,
                )),
            'interfaceExtends' => $interfaceExtends,
            'interfaceGetters' => $interfaceGetters,
            'discriminatorMap' => $discriminatorMap,
        ]);
    }

    /**
     * The members of a discriminated interface: the schemas its OWN mapping names, in schema order.
     *
     * Not `discriminatorMembers()` alone: that asks each schema for its one base, and a schema two
     * discriminated unions share (`ShapeA` and `ShapeB` both `oneOf: [Circle, Square]`) has none, so
     * the interface had no members, rendered as a class and failed on variables only a class has.
     *
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
     * @return array<int, string>
     */
    private function symfonyDiscriminatedMembers(string $className, ?array $discriminator): array
    {
        $mapped = $discriminator === null ? [] : array_values($discriminator['mapping']);
        $members = [];
        foreach (array_keys($this->dtoSchemas) as $candidate) {
            if ($candidate !== $className && in_array($candidate, $mapped, true)) {
                $members[] = $candidate;
            }
        }

        return $members !== [] ? $members : $this->discriminatorMembers($className);
    }

    /**
     * An object schema with a discriminator mapping to OTHER schemas — the variants `allOf` it. In
     * runtime mode it is a parent class; here it is an interface with `#[DiscriminatorMap]`. A base
     * that maps a value to itself is a concrete type of its own and stays a class.
     */
    private function isSymfonyAllOfDiscriminatorBase(string $className): bool
    {
        // yii3 shares the interface lookup, but keeps the base a class of its own.
        return $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && $this->isAllOfDiscriminatorBase($className);
    }

    /**
     * The object-wide keywords a class built from `allOf: [$ref Parent]` inherits from the parent — see
     * `symfonyAllOfParentObjectKeywords()`.
     *
     * @var array<int, string>
     */
    private const array SYMFONY_INHERITED_OBJECT_KEYWORDS = [
        'minProperties',
        'maxProperties',
        'dependentRequired',
        'dependentSchemas',
        'propertyNames',
        'patternProperties',
        'not',
        'if',
        'then',
        'else',
    ];

    /**
     * Keywords that grant permission rather than assert anything. They survive the filter so the
     * callback knows a null is allowed, but they cannot make a schema worth entering.
     *
     * @var array<int, string>
     */
    private const array SYMFONY_ANNOTATION_KEYWORDS = ['nullable'];

    /**
     * Container keywords an `Assert\AtLeastOneOf` branch has no spelling for — see
     * `anyOfBranchOutgrowsItsAttribute()`.
     *
     * @var array<int, string>
     */
    private const array SYMFONY_UNSPELLED_CONTAINER_KEYWORDS = [
        'minItems',
        'maxItems',
        'uniqueItems',
        'contains',
        'minContains',
        'maxContains',
        'prefixItems',
        'unevaluatedItems',
        'additionalProperties',
        'unevaluatedProperties',
        'patternProperties',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'required',
        'dependentRequired',
        'dependentSchemas',
        'not',
        'allOf',
        'anyOf',
        'oneOf',
        'if',
        'enum',
        'const',
    ];

    /**
     * @param SchemaProperty $property
     * @return array{
     *     declaredType: string,
     *     docType: ?string,
     *     name: string,
     *     required: bool,
     *     serializedName: ?string,
     *     default: string,
     *     attributes: array<int, string>,
     *     docDescription: ?string,
     *     getter: string,
     *     setter: string,
     *     providedFlag: string,
     *     providedGetter: string,
     *     contextAttribute: ?string,
     *     factoryType: string,
     *     factoryDefault: string,
     *     factoryDocType: ?string,
     *     temporalFormatter: ?string,
     *     wireShape: ?string,
     *     inputType: string,
     *     nullRefusedByCallback: bool,
     *     constructorType: string,
     *     constructorDocType: ?string,
     *     queryReader: ?string,
     *     queryList: bool,
     *     enumReader: ?string,
     *     inputDocType: ?string,
     *     assignedValue: string,
     *     constructorValue: string,
     *     temporalDefault: ?string,
     * }
     */
    private function resolveSymfonyParam(array $property, string $namespace, bool $underADateContext = false): array
    {
        $phpType = $property['type'];
        $docType = null;

        if (str_contains($phpType, '<')) {
            $docType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            // A list beside other members (`array<Shape>|int`, see `symfonyWithUnionListsTyped()`)
            // keeps them natively; a list alone is `array`, its nullability the schema's below.
            // Unique: `array|…|array<string, mixed>` folds to one `array`, which PHP refuses to name twice.
            $nativeMembers = array_values(array_unique(array_map(
                callback: static fn(string $member): string => str_contains($member, '<') ? 'array' : $member,
                array: $this->topLevelTypeMembers(ltrim($phpType, '?')),
            )));
            $phpType = count($nativeMembers) > 1
                ? $this->formatPhpTypeForNamespace(implode('|', $nativeMembers), $namespace)
                : 'array';
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
        }

        // NOT `$property['required']`: a readOnly property is required of a RESPONSE only. Emitted
        // as a required constructor parameter it made the class undeserializable outright —
        // `MissingConstructorArgumentsException` for any payload that omitted the server-owned field,
        // under every combination of serialization groups.
        $required = $this->propertyIsRequiredOnInput($property);
        $default = $property['default'] ?? null;

        // An optional property is nullable with a default (null unless the schema declares one).
        // It is NOT a constructor parameter: the serializer fills it through the setter, and the
        // setter is what records that the payload carried the key — see the class docblock. A query
        // list is the one exception — see `$queryList` below.
        // `nullable` on the property also covers "optional", which is what the runtime mode needs;
        // here only the schema's own word counts, since an optional property WITH a default never
        // holds null unless the schema allows it — `?Kind $kind = Kind::A` invited a null the
        // document rejects.
        $schemaNullable = $property['schemaNullable'] ?? $property['nullable'];

        // A temporal default is `new DateTimeImmutable(...)`, and PHP forbids `new` in a PROPERTY
        // initialiser — which is what an optional property here is, since the serializer fills it
        // through the setter rather than the constructor. The constructor BODY is the one place that
        // can hold the expression, so the property is left uninitialised and assigned there. The PHP
        // type decides, as it does for `renderDefaultValue()`: a date-time beside a null spelled as a
        // `oneOf` / `anyOf` carries its format as `unionTemporalFormats`, not `temporalFormat`, and its
        // `new` landed in the initialiser — "New expressions are not supported in this context".
        $temporalDefault = null;
        $defaultLiteral = '';
        if (!$required && $default !== null) {
            $rendered = $this->renderDefaultValue($default, $phpType, $this->composePhpTypeHint($phpType, $schemaNullable));
            if ($this->shortClassName(ltrim($phpType, '?')) === 'DateTimeImmutable') {
                $expression = ltrim($rendered, ' =');
                $temporalDefault = $expression === '' ? null : $expression;
            } else {
                $defaultLiteral = $rendered;
            }
        }
        // A REQUIRED property's default lives in `create()` only. The constructor is what the
        // serializer calls, and a default there would let a request that omits the field through
        // without an error; the factory is what code building a response calls, and it is where
        // the default saves the caller from repeating it. PHP allows `new` in a parameter default,
        // so a temporal default needs no special case here.
        $factoryDefault = '';
        if ($required && $default !== null) {
            $factoryDefault = $this->renderDefaultValue($default, $phpType, $this->composePhpTypeHint($phpType, $schemaNullable));
        }

        // Without a usable default an optional property starts as null, so its type must admit it.
        $hasDefault = $defaultLiteral !== '' || $temporalDefault !== null;
        $declaredNullable = $schemaNullable || (!$required && !$hasDefault);
        if (!$required && !$hasDefault) {
            $defaultLiteral = ' = null';
        }
        $declaredType = $this->composePhpTypeHint($phpType, $declaredNullable);

        // `#[MapQueryString]` casts a single query value to the declared scalar, but leaves the items
        // of a list as the strings the URL carried, so `?ids[]=1` reached the property as `['1']` and
        // failed its own item check. The DTO reads such a list through a method of its own, the way
        // `filter_var` does for `#[MapQueryParameter]`; an item that does not read as the type stays a
        // string, for validation to report at its index.
        // What a caller — the serializer included — may hand in: null only where the schema allows it.
        // The property of an optional field is nullable for the "never set" state alone; a setter that
        // took null as well let `{"limit": null}` and `?limit=` through as "not given" (the serializer
        // turns an empty query value into null for a nullable type). A non-nullable setter makes the
        // serializer refuse both at the field's path.
        $inputType = $this->composePhpTypeHint($phpType, $schemaNullable);
        // A generated object class is the exception: given `null` for it, the serializer does not
        // refuse — it denormalizes the null into an EMPTY object, `new Inner()`, and the typed
        // parameter takes it, so `{"inner": null}` passed as `{"inner": {}}` (refused only by accident,
        // where the class requires a property). The parameter admits the null instead, so it arrives
        // as itself: an optional setter stores it, and the interpreter refuses it at the field's path
        // (`nullRefusedByCallback`); a required constructor parameter is untyped — a nullable type
        // would turn a missing key into null — and assigning null to the property refuses it.
        // Only a class whose schema PINS `object`: an untyped one (`{properties: …}` with no `type`)
        // admits every value, null included, and keeps the old reading.
        $nullRefusedByCallback = false;
        $untypedRequiredObject = false;
        // Through `allOf` too: `allOf: [{type: object, properties: …}]` builds a class whose own schema
        // names no `type`, and `{"lemur": null}` passed as an empty object.
        $targetPinsObject = $this->symfonySchemaPinsObject($this->dtoSchemas[ltrim($property['type'], '?\\')] ?? []);
        // A union with a class member (`string|Leaf`) is read the same way: the serializer turned the
        // null into `new Leaf()` — the optional case only, a required union keeps its typed parameter.
        $unionClass = $this->unionClassBesideScalars($property['type']);
        $unionTargetPinsObject = $unionClass !== null && $this->symfonySchemaPinsObject($this->dtoSchemas[$unionClass] ?? []);
        if (!$schemaNullable && !$required && $defaultLiteral === ' = null' && $unionTargetPinsObject) {
            $inputType = $this->composePhpTypeHint($phpType, true);
            $nullRefusedByCallback = true;
        }
        if (!$schemaNullable && $targetPinsObject) {
            if ($required) {
                $untypedRequiredObject = true;
            } elseif ($defaultLiteral === ' = null') {
                $inputType = $this->composePhpTypeHint($phpType, true);
                $nullRefusedByCallback = true;
            }
        }
        $queryReader = null;
        $inputDocType = $docType !== null ? $this->composePhpTypeHint($docType, $schemaNullable) : null;
        $factoryDocType = $docType !== null ? $this->composePhpTypeHint($docType, $required ? $declaredNullable : true) : null;
        if (
            (($property['inQuery'] ?? false) === true || ($property['inPath'] ?? false) === true)
            && preg_match('/^array<\??(int|float|bool)(\|null)?>$/', $property['type'], $itemMatch) === 1
        ) {
            $queryReader = 'readQuery' . ucfirst($itemMatch[1]) . 's';
            $itemPattern = '/\b' . $itemMatch[1] . '\b/';
            $loosened = $itemMatch[1] === 'bool' ? 'bool|string' : $itemMatch[1] . '|numeric-string';
            $inputDocType = $inputDocType === null ? null : (string)preg_replace($itemPattern, $loosened, $inputDocType, 1);
            $factoryDocType = $factoryDocType === null ? null : (string)preg_replace($itemPattern, $loosened, $factoryDocType, 1);
        }
        $assignedValue = '$' . $property['name'];
        if ($queryReader !== null) {
            $assignedValue = $schemaNullable
                ? sprintf('$%1$s === null ? null : self::%2$s($%1$s)', $property['name'], $queryReader)
                : sprintf('self::%s($%s)', $queryReader, $property['name']);
        }

        // A query list as the spec's `style` sends it — `?ids=1,22` — reaches the DTO as one string.
        // The serializer reads a setter by the PROPERTY's type, a list, and refuses the string before
        // the setter runs; a constructor parameter it reads by the parameter's own type once that
        // admits a type the property does not. So a query list is a constructor parameter of
        // `array|string`, optional ones included, and the DTO splits the string itself.
        $queryList = $schemaNullable ? null : $this->symfonyQueryList($property, $namespace);
        $constructorType = ($required && $schemaNullable) || $untypedRequiredObject ? '' : $declaredType;
        $constructorDocType = ($required && $schemaNullable) || $untypedRequiredObject ? ($inputDocType ?? $declaredType) : $inputDocType;
        $constructorValue = $assignedValue;
        if ($queryList !== null) {
            $constructorType = $required ? 'array|string' : 'array|string|null';
            $constructorDocType = $queryList['docType'] . ($required ? '|string' : '|string|null');
            $constructorValue = sprintf(
                'self::readQueryList($%s, %s)',
                $property['name'],
                $queryList['delimiter'] === null ? 'null' : var_export($queryList['delimiter'], true),
            );
            if ($queryReader !== null) {
                $constructorValue = sprintf('self::%s(%s)', $queryReader, $constructorValue);
            } elseif ($queryList['enumReader'] !== null) {
                $constructorValue = sprintf('self::%s(%s::class, %s)', $queryList['enumReader'], $queryList['enumClass'], $constructorValue);
            }
        }

        // A map is a PHP array to the serializer, and the empty one encodes as `[]`: `{"widths": {}}` came
        // back `{"widths": []}`. A property held as a generated class is the serializer's own object, and
        // `PRESERVE_EMPTY_OBJECTS` is the caller's choice there (README.support-matrix).
        $wireShape = array_key_exists($this->shortClassName(ltrim($property['type'], '?')), $this->dtoSchemas)
            ? null
            : $this->emptyObjectWireShape(type: $property['type'], constraints: $property['constraints'] ?? []);

        return [
            'declaredType' => $declaredType,
            'docType' => $docType !== null ? $this->composePhpTypeHint($docType, $declaredNullable) : null,
            'name' => $property['name'],
            'required' => $required,
            'serializedName' => $property['name'] !== $property['openApiName'] ? $property['openApiName'] : null,
            'default' => $defaultLiteral,
            'attributes' => $this->resolveSymfonyAttributes($property),
            'docDescription' => $this->resolveDocDescription($property),
            'getter' => 'get' . ucfirst($property['name']),
            // A temporal property is a DateTimeImmutable to the code that reads it, and the string
            // the schema asks for on the wire: `format: date` must not grow a time part, and a
            // date-time must keep the sub-second precision the payload had. Symfony's
            // DateTimeNormalizer has one fixed pattern and can express neither, so the property hands
            // the serializer a callback of the DTO's own through #[Context].
            'temporalFormatter' => $this->symfonyTemporalFormatter($property),
            'wireShape' => $wireShape,
            'temporalDefault' => $temporalDefault,
            'inputType' => $required ? $declaredType : $inputType,
            'nullRefusedByCallback' => $nullRefusedByCallback,
            // A required property that may be null takes an UNTYPED constructor parameter. For a typed
            // nullable one the serializer passes null when the key is missing, so `{}` came in as
            // `{"score": null}`; without a type it reports the key missing at its path instead, and still
            // reads the value by the property's type. The docblock keeps the type for every other caller.
            'constructorType' => $constructorType,
            'constructorDocType' => $constructorDocType,
            'queryReader' => $queryReader,
            'queryList' => $queryList !== null,
            'enumReader' => $queryList['enumReader'] ?? null,
            'inputDocType' => $inputDocType,
            'assignedValue' => $assignedValue,
            'constructorValue' => $constructorValue,
            'setter' => 'set' . ucfirst($property['name']),
            'providedFlag' => $property['name'] . 'Provided',
            'providedGetter' => 'is' . ucfirst($property['name']) . 'Provided',
            // Symfony writes every property it can read; whether a null belongs in the output is set per
            // property, since a parent's context reaches its children. Null on an optional property the
            // schema does not let be null can only mean "never set", so it is dropped; a nullable one
            // keeps its null even under a parent, or a global, that skips them.
            // `create()` takes an optional property as nullable, null standing for "not set".
            'factoryType' => $required ? $declaredType : $this->composePhpTypeHint(ltrim($declaredType, '?'), true),
            'factoryDefault' => $factoryDefault,
            'factoryDocType' => $factoryDocType,
            'contextAttribute' => $this->symfonyContextAttribute(
                name: $property['name'],
                skipNullValues: $schemaNullable || ($phpType === 'mixed' && !$this->symfonyUntypedValueRefusesNull($property)) ? false : ($declaredNullable ? true : null),
                temporalFormatter: $this->symfonyTemporalFormatter($property),
                writesEmptyObjects: $wireShape !== null,
                strictDate: $this->symfonyDateParsedStrictly($property),
                freeDate: $underADateContext
                    && !$this->symfonyDateParsedStrictly($property)
                    && str_contains($property['type'], 'DateTimeImmutable'),
                refuseExtraAttributes: $this->unionClassBesideScalars($property['type']) !== null
                    && $this->unionHasArrayMember($property['type']),
            ),
        ];
    }

    /**
     * Whether a class schema admits objects only: its own `type` is `object` (or a list of it without
     * `null`), or one `allOf` part — every part must hold — pins it so. A part `nullable: true` pins nothing.
     * A `$ref` part pins what the component it names pins: `Teal: {allOf: [{$ref: Ochre}]}` extends an
     * object, and unread, the setter took `2.5`, `"x"` and `null` as an empty Teal.
     *
     * @param array<mixed> $schema
     */
    private function symfonySchemaPinsObject(array $schema, int $depth = 0): bool
    {
        // The class's own `nullable` is the property's to answer (`$schemaNullable`), as before.
        if ($depth > 0 && ($schema['nullable'] ?? false) === true) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true) && !in_array('null', $type, true))) {
            return true;
        }
        $allOf = $schema['allOf'] ?? null;
        if ($type !== null || !is_array($allOf) || $depth > 8) {
            return false;
        }
        foreach ($allOf as $part) {
            if (is_array($part) && is_string($part['$ref'] ?? null) && $this->isAnnotatedReference($part)) {
                $part = $this->localComponentDefinition($part['$ref']) ?? [];
            }
            if (is_array($part) && $this->symfonySchemaPinsObject($part, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An untyped (`mixed`) property admits null unless its schema pins the value: `{}` takes null like
     * anything else, so its null is kept on the way out, but `const: zed` does not — and a property
     * never set was written as `"c": null`, a response its own schema refuses. Only the keywords that
     * settle it alone are read; anything subtler keeps the null, as before.
     *
     * @param SchemaProperty $property
     */
    private function symfonyUntypedValueRefusesNull(array $property): bool
    {
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        if (array_key_exists('const', $constraints)) {
            return $constraints['const'] !== null;
        }
        if (is_array($constraints['enum'] ?? null)) {
            return !in_array(null, $constraints['enum'], true);
        }
        $type = $constraints['type'] ?? null;
        if (is_string($type)) {
            return $type !== 'null' && ($constraints['nullable'] ?? null) !== true;
        }
        if (is_array($type) && $type !== []) {
            return !in_array('null', $type, true) && ($constraints['nullable'] ?? null) !== true;
        }

        return false;
    }

    /**
     * The local `create()` builds the DTO in. Its parameters are the property names, so the local
     * takes the first camelCase name none of them uses.
     *
     * @param array<int, array{name: string}> $params
     */
    private function symfonyFactoryLocalName(array $params): string
    {
        $taken = array_column($params, 'name');
        $candidate = 'dto';
        $suffix = 1;
        while (in_array($candidate, $taken, true)) {
            $candidate = 'builtDto' . ($suffix === 1 ? '' : $suffix);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * How a query or path list of scalars or enum cases arrives as one string, or null for anything
     * else: the delimiter its `style` joins the items with — null for `form` exploded, which sends one
     * item per key, so a lone string is a list of one — the enum it holds, and the type the constructor
     * takes. A path list is `simple`, comma-joined; `label` and `matrix` are left to the bracket form.
     *
     * @param SchemaProperty $property
     * @return array{delimiter: ?string, enumReader: ?string, enumClass: ?string, docType: string}|null
     */
    private function symfonyQueryList(array $property, string $namespace): ?array
    {
        if (
            (($property['inQuery'] ?? false) !== true && ($property['inPath'] ?? false) !== true)
            || preg_match('/^array<(\?)?([A-Za-z_\\\][\w\\\]*)(\|null)?>$/', $property['type'], $itemMatch) !== 1
        ) {
            return null;
        }

        $style = $property['parameterStyle'] ?? 'form';
        $explode = $property['parameterExplode'] ?? $style === 'form';
        $delimiter = match ($style) {
            'form' => $explode ? null : ',',
            'spaceDelimited' => ' ',
            'pipeDelimited' => '|',
            'simple' => ',',
            default => false,
        };
        if ($delimiter === false) {
            return null;
        }

        $itemType = $itemMatch[2];
        $enumReader = null;
        $enumClass = null;
        $itemDocType = match ($itemType) {
            'int', 'float' => $itemType . '|numeric-string',
            'bool' => 'bool|string',
            'string' => 'string',
            default => null,
        };
        if ($itemDocType === null) {
            $enum = $this->enumSchemas[$this->shortClassName($itemType)] ?? null;
            if ($enum === null) {
                return null;
            }
            $enumClass = $this->formatPhpTypeForNamespace($itemType, $namespace);
            $enumReader = $enum['type'] === 'int' ? 'readQueryIntEnums' : 'readQueryEnums';
            $itemDocType = $enumClass . '|string';
        }
        $itemNullable = $itemMatch[1] === '?' || ($itemMatch[3] ?? '') === '|null';

        return [
            'delimiter' => $delimiter,
            'enumReader' => $enumReader,
            'enumClass' => $enumClass,
            'docType' => 'array<' . $itemDocType . ($itemNullable ? '|null' : '') . '>',
        ];
    }

    /**
     * The one #[Context] a property carries, or null when it needs none: its own null policy and,
     * for a date, the DTO's formatter as the serializer callback.
     *
     * A `format: date` is also READ with the one pattern it allows (`!Y-m-d`). Without it the
     * DateTimeNormalizer hands the string to `new DateTimeImmutable()`, which takes any phrase PHP
     * understands: `"x"` became today, `"tomorrow"` and a full date-time passed. The context reaches the
     * items of a list and the values of a map through the ArrayDenormalizer. A calendar date that rolls
     * over (`2026-02-30`) still parses (README.support-matrix).
     *
     * A union of `array` and one generated class (`array|Lime`) also refuses extra attributes while it
     * is read: the serializer tries the class first, and the object normalizer takes ANY PHP array, so
     * every JSON list became an empty class — an invalid list accepted, a valid one losing its items.
     * With extra attributes refused a list fails the class (its keys `0`, `1`, … are none of the
     * class's) and falls through to the `array` member; the interpreter then judges what the member
     * holds. A JSON object with an undeclared key takes the same way, and an empty `[]` still becomes
     * the class (README.support-matrix, the EMPTY JSON array row).
     */
    private function symfonyContextAttribute(
        string $name,
        ?bool $skipNullValues,
        ?string $temporalFormatter,
        bool $writesEmptyObjects,
        bool $strictDate,
        bool $freeDate,
        bool $refuseExtraAttributes,
    ): ?string {
        $entries = [];
        if ($skipNullValues !== null) {
            $entries[] = '\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => ' . ($skipNullValues ? 'true' : 'false');
        }
        // One callback per property: a map's own writes its values through the date formatter as well.
        $callback = $writesEmptyObjects ? 'writeOpenApiObjects' : $temporalFormatter;
        if ($callback !== null) {
            $entries[] = sprintf(
                '\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::CALLBACKS => [%s => [self::class, %s]]',
                var_export($name, true),
                var_export($callback, true),
            );
        }
        if ($writesEmptyObjects) {
            // The callback hands back an empty `ArrayObject`, which the serializer keeps only with this set.
            $entries[] = '\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true';
        }
        $denormalizationEntries = [];
        if ($strictDate) {
            $denormalizationEntries[] = "\\Symfony\\Component\\Serializer\\Normalizer\\DateTimeNormalizer::FORMAT_KEY => '!Y-m-d'";
        }
        // Under a parent's strict date context a date-time is read freely again: no pattern is PHP's
        // own parser, as everywhere else in this mode.
        if ($freeDate) {
            $denormalizationEntries[] = '\Symfony\Component\Serializer\Normalizer\DateTimeNormalizer::FORMAT_KEY => null';
        }
        if ($refuseExtraAttributes) {
            $denormalizationEntries[] = '\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false';
        }
        $denormalization = $denormalizationEntries === [] ? null : implode(",\n            ", $denormalizationEntries);

        if ($entries === [] && $denormalization === null) {
            return null;
        }
        if ($callback === null && $denormalization === null) {
            return '#[\Symfony\Component\Serializer\Attribute\Context(normalizationContext: [' . implode(', ', $entries) . '])]';
        }

        if ($denormalization === null) {
            // With a callback the line runs long; one entry per line, as php-cs-fixer would lay it out.
            return "#[\\Symfony\\Component\\Serializer\\Attribute\\Context(normalizationContext: [\n        " . implode(",\n        ", $entries) . ",\n    ])]";
        }

        $arguments = [];
        if ($entries !== []) {
            $arguments[] = "normalizationContext: [\n            " . implode(",\n            ", $entries) . ",\n        ]";
        }
        $arguments[] = "denormalizationContext: [\n            " . $denormalization . ",\n        ]";

        return "#[\\Symfony\\Component\\Serializer\\Attribute\\Context(\n        " . implode(",\n        ", $arguments) . ",\n    )]";
    }

    /**
     * The static formatter a date property hands the serializer, or null when it holds no dates.
     *
     * @param SchemaProperty $property
     */
    private function symfonyTemporalFormatter(array $property): ?string
    {
        $format = null;
        if ($this->symfonyPropertyIsTemporalScalar($property)) {
            $format = $property['temporalFormat'] ?? null;
        } elseif ($this->propertyHasTemporalItems($property)) {
            $format = $property['itemsTemporalFormat'] ?? null;
        } elseif (in_array('DateTimeImmutable', array_map($this->shortClassName(...), $this->unionMembersBesideNull($property['type'])), true)) {
            // A date beside other members — `DateTimeImmutable|Shade`, `int|DateTimeImmutable` — went out
            // as the serializer's RFC 3339 timestamp, `2024-01-02T00:00:00+00:00` for a `format: date`.
            // The formatter passes every other value through.
            $unionFormats = $property['unionTemporalFormats'] ?? [];
            $format = match ($unionFormats) {
                ['date'] => 'Y-m-d',
                ['date-time'] => 'c',
                default => null,
            };
        }
        if (!is_string($format)) {
            return null;
        }

        return $format === 'Y-m-d' ? 'formatOpenApiDate' : 'formatOpenApiDateTime';
    }

    /**
     * Whether a `format: date` property is read with its one pattern (see `symfonyContextAttribute()`).
     * Beside a plain `array`, a backed enum or a generated class it is: without it `+1 day`, `now` and
     * `@0` passed `DateTimeImmutable|array`, `Level|DateTimeImmutable` and `DateTimeImmutable|Shade`.
     * The context reaches the class's own properties too, so every class reachable from such a
     * property hands its other temporal properties the free parse back (`symfonyClassesUnderADateContext()`).
     *
     * @param SchemaProperty $property
     */
    private function symfonyDateParsedStrictly(array $property): bool
    {
        if ($this->symfonyTemporalFormatter($property) !== 'formatOpenApiDate') {
            return false;
        }
        if ($this->symfonyPropertyIsTemporalScalar($property) || $this->propertyHasTemporalItems($property)) {
            return true;
        }
        foreach ($this->unionMembersBesideNull($property['type']) as $member) {
            if (
                $this->shortClassName($member) !== 'DateTimeImmutable'
                && !in_array($member, ['int', 'float', 'string', 'bool', 'true', 'false', 'array'], true)
                && !array_key_exists($this->shortClassName($member), $this->enumSchemas)
                && !array_key_exists($this->shortClassName($member), $this->dtoSchemas)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The generated classes a strict `format: date` context can reach: every class reachable — through
     * its properties, or a union interface through its members — from a date property held beside a
     * generated class (`DateTimeImmutable|Shade`, see `symfonyDateParsedStrictly()`). The serializer
     * hands a property's denormalization context down to what it builds, so a `date-time` there would
     * be read as `!Y-m-d`; their temporal properties reset the pattern (`symfonyContextAttribute()`).
     *
     * @return array<string, true>
     */
    private function symfonyClassesUnderADateContext(): array
    {
        if ($this->symfonyClassesUnderADateContext !== null) {
            return $this->symfonyClassesUnderADateContext;
        }
        $reached = [];
        foreach (array_keys($this->dtoSchemas) as $className) {
            foreach ($this->getSchemaProperties($className) as $property) {
                if (
                    !$this->symfonyDateParsedStrictly($property)
                    || $this->symfonyPropertyIsTemporalScalar($property)
                    || $this->propertyHasTemporalItems($property)
                ) {
                    continue;
                }
                foreach ($this->generatedClassNamesInType($property['type']) as $referenced) {
                    $this->symfonyCollectReachableClasses(className: $referenced, reached: $reached);
                }
            }
        }

        return $this->symfonyClassesUnderADateContext = $reached;
    }

    /**
     * @param array<string, true> $reached
     */
    private function symfonyCollectReachableClasses(string $className, array &$reached): void
    {
        if (array_key_exists($className, $reached) || !array_key_exists($className, $this->dtoSchemas)) {
            return;
        }
        $reached[$className] = true;
        foreach ([...$this->unionMembersOf($className), ...$this->symfonyUnionRefMembers($className)] as $member) {
            $this->symfonyCollectReachableClasses(className: $member, reached: $reached);
        }
        foreach ($this->getSchemaProperties($className) as $property) {
            foreach ($this->generatedClassNamesInType($property['type']) as $referenced) {
                $this->symfonyCollectReachableClasses(className: $referenced, reached: $reached);
            }
        }
    }

    /**
     * Whether this property is a temporal SCALAR — the one shape whose default cannot be written as
     * a property initialiser.
     *
     * @param SchemaProperty $property
     */
    private function symfonyPropertyIsTemporalScalar(array $property): bool
    {
        return is_string($property['temporalFormat'] ?? null)
            && $this->shortClassName(ltrim($property['type'], '?')) === 'DateTimeImmutable';
    }

    /**
     * The constraints the attributes are read from: scalar `allOf` folded, and without the keywords the
     * PHP type does not pin.
     *
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function symfonyAttributeConstraints(array $property): array
    {
        return $this->withoutKeywordsTheTypeDoesNotPin(
            schema: $this->foldScalarAllOfConstraints(
                constraints: is_array($property['constraints'] ?? null) ? $property['constraints'] : [],
            ),
            phpType: $property['type'],
        );
    }

    /**
     * Maps OpenAPI constraints to Symfony Validator attribute lines. Covers the common scalar,
     * string, numeric and array constraints plus cascade validation. Complex schema keywords that
     * need cross-field logic are handled by the class-level callback block emitted separately.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function resolveSymfonyAttributes(array $property): array
    {
        // A keyword the property's type does not pin is the callback's, which applies it to its own
        // type only — see `shouldForceRootCallbackForProperty()`.
        $constraints = $this->symfonyAttributeConstraints($property);
        $attributes = [];

        // A schema with no `type` admits null like any other JSON value — the runtime rule since
        // 2.15.49. What else it asserts (`enum`, `const`, `not`) the callback still judges.
        $untyped = $property['type'] === 'mixed'
            && !array_key_exists('type', $constraints)
            && !array_key_exists('nullable', $constraints);
        // Only where the PHP type itself admits null: on `string $name` the serializer fails on the
        // type before the validator runs, and a hand-built DTO cannot hold null there at all, so the
        // assertion never fired.
        $typeAdmitsNull = $property['type'] === 'mixed'
            || str_starts_with($property['type'], '?')
            || in_array('null', explode('|', $property['type']), true);
        if ($this->propertyIsRequiredOnInput($property) && !$property['nullable'] && !$untyped && $typeAdmitsNull) {
            $attributes[] = '#[Assert\NotNull]';
        }

        // OpenAPI allowEmptyValue (query parameters only). Symfony binds the query string itself,
        // so an explicit `false` can only be honoured as a constraint on the bound value: NotBlank
        // rejects "" while still accepting "0" and " ". `true` and a silent spec add nothing.
        if (($property['inQuery'] ?? false) === true && ($property['allowEmptyValue'] ?? null) === false) {
            $attributes[] = '#[Assert\NotBlank(allowNull: true)]';
        }

        // Scalar/value-level constraints (Length, Range, Regex, EqualTo, format-based, ...).
        if (!$this->shouldSkipSymfonyScalarAttributesForProperty($property)) {
            foreach ($this->scalarConstraintSpecs($constraints) as $spec) {
                $attributes[] = $spec['args'] === ''
                    ? '#[Assert\\' . $spec['name'] . ']'
                    : '#[Assert\\' . $spec['name'] . '(' . $spec['args'] . ')]';
            }
        }

        // Array/map size — minItems/maxItems (lists) and minProperties/maxProperties (inline maps)
        // both count elements of the backing PHP array, so they share a single Count attribute.
        $count = [];
        $countMin = $constraints['minItems'] ?? null;
        $countMax = $constraints['maxItems'] ?? null;
        if (!$this->shouldCallbackValidateObjectPropertyBounds($property)) {
            $countMin ??= $constraints['minProperties'] ?? null;
            $countMax ??= $constraints['maxProperties'] ?? null;
        }
        // A JSON OBJECT for a `type: array` property: the serializer hands `{"zz": 1}` over as the PHP
        // array `['zz' => 1]`, which an `array` parameter holds and the callback, that leaves `type`
        // to the PHP type, never questioned — the one mode accepting it. Its keys still say what it was.
        if ($this->symfonySchemaIsAList($constraints, $property['type'])) {
            $attributes[] = "#[Assert\\Type('list')]";
        }

        $countMin = $this->symfonyIntegralCount($countMin);
        $countMax = $this->symfonyIntegralCount($countMax);
        if ($countMin !== null) {
            $count[] = 'min: ' . $countMin;
        }
        if ($countMax !== null) {
            $count[] = 'max: ' . $countMax;
        }
        // The callback took them where the property's unsent value breaks them — see
        // `symfonyDefaultBreaksItsKeywords()`; the attribute would refuse `{}`.
        if ($count !== [] && !$this->symfonyDefaultBreaksItsKeywords($property)) {
            $attributes[] = '#[Assert\Count(' . implode(', ', $count) . ')]';
        }

        // Typed map values (additionalProperties: { schema }) of an enum, a date or a DTO: every value
        // is that class, which nothing but `Assert\Type` can say. Any other value gets no attribute,
        // for the reason the list items below give: the callback owns its `type` — it reads `42.0` as
        // the integer JSON Schema 2020-12 §6.1.1 calls it, where `Assert\Type('int')` refuses it — so
        // it owns the value's bounds as well, or `{"a":"x"}` against `maximum: 10` is reported twice.
        $mapValueTypeExpression = $this->symfonyPreferredTypeForMapValue($property['type']);
        if (is_array($constraints['additionalProperties'] ?? null) && $mapValueTypeExpression !== null) {
            $attributes[] = '#[Assert\All([new Assert\Type(' . $mapValueTypeExpression . ')])]';
        }

        // anyOf — the value must satisfy at least one branch. Where no `Assert\AtLeastOneOf` spells it,
        // the callback owns it — see `shouldForceRootCallbackForProperty()`.
        $atLeastOneOf = $this->symfonyAtLeastOneOf(property: $property, constraints: $constraints);
        if ($atLeastOneOf !== null) {
            $attributes[] = $atLeastOneOf;
        }

        // Per-item constraints for arrays of enums and dates (array of DTOs cascades via Valid instead).
        // A SCALAR item gets none: the callback owns its `type`, and an `Assert\Range` beside it asked
        // `"x"` about its bound as well — measured, `?ids[]=x` against `minimum: 1` came back as both
        // `field "ids"[0] must be of type integer.` and `This value should be a valid number.` The
        // callback checks a bound only on a value of the bound's type, so it owns the bounds too.
        // A query list of enum cases is read by the DTO, not the serializer, so an item that names no
        // case reaches validation as the string it was. Where the item constraints carry the `enum`,
        // the callback reports it, and `Assert\Type` beside it would say the same thing twice; where
        // they do not, `Assert\Type` is the only check the item gets.
        $items = $constraints['items'] ?? null;
        $queryEnumList = ($this->symfonyQueryList($property, '')['enumClass'] ?? null) !== null;
        if ((is_array($items) || $queryEnumList) && !$this->symfonyPropertyCascades($property)) {
            $itemTypeExpression = $this->symfonyPreferredTypeForArrayItem($property['type']);
            if ($itemTypeExpression !== null && !($queryEnumList && is_array($items) && array_key_exists('enum', $items))) {
                $attributes[] = '#[Assert\All([new Assert\Type(' . $itemTypeExpression . ')])]';
            }
        }

        // Serialization groups. `read` is the response direction, `write` the request one, so a
        // readOnly field is only in `read` and a writeOnly field only in `write`. Marking just
        // those two would be worse than useless: the moment a group is passed in the serializer
        // context, every UNMARKED attribute disappears, so the plain fields have to be in both
        // groups. Only emitted when the document uses one of the keywords at all.
        if ($this->serializationGroupsRequired) {
            $readable = ($property['writeOnly'] ?? false) !== true;
            $writable = ($property['readOnly'] ?? false) !== true;

            if (!$readable && !$writable) {
                // readOnly and writeOnly together is a contradiction; runtime mode drops such a
                // field in both directions, and #[Ignore] is the exact equivalent here.
                $attributes[] = '#[\Symfony\Component\Serializer\Attribute\Ignore]';
            } else {
                $groups = [];
                if ($readable) {
                    $groups[] = "'read'";
                }
                if ($writable) {
                    $groups[] = "'write'";
                }
                $attributes[] = '#[\Symfony\Component\Serializer\Attribute\Groups([' . implode(', ', $groups) . '])]';
            }
        }

        if ($this->symfonyPropertyCascades($property)) {
            $attributes[] = '#[Assert\Valid]';
        }

        return $attributes;
    }

    /**
     * @param SchemaProperty $property
     */
    private function shouldSkipSymfonyScalarAttributesForProperty(array $property): bool
    {
        // The callback took them: an attribute would check the default the property holds.
        if ($this->symfonyDefaultBreaksItsKeywords($property)) {
            return true;
        }
        if (preg_match('/[|&]/', $property['type']) === 1) {
            return false;
        }

        $baseType = ltrim($property['type'], '?');
        $short = $this->shortClassName($baseType);
        if (array_key_exists($short, $this->enumSchemas)) {
            return true;
        }

        return $baseType === 'DateTimeImmutable';
    }

    private function symfonyPreferredTypeForArrayItem(string $propertyType): ?string
    {
        if (preg_match('/^array<(.+)>$/', $propertyType, $matches) !== 1) {
            return null;
        }

        $itemType = ltrim(trim($matches[1]), '?');
        if ($itemType === '' || $itemType === 'mixed') {
            return null;
        }

        $short = $this->shortClassName($itemType);
        if (array_key_exists($short, $this->enumSchemas) || $itemType === 'DateTimeImmutable') {
            return $short . '::class';
        }

        return null;
    }

    /**
     * Maps the scalar/value-level OpenAPI constraints of a (sub)schema to Symfony constraint
     * specs: [{name, args}]. Shared by property attributes and by #[Assert\All] item constraints.
     * Cross-field / structural keywords are validated by the class-level callback block instead.
     *
     * @param array<string, mixed> $constraints
     * @return array<int, array{name: string, args: string}>
     */
    private function scalarConstraintSpecs(array $constraints, bool $inAnyOfBranch = false): array
    {
        return $this->scalarConstraintSpecList($constraints, $inAnyOfBranch)['specs'];
    }

    /**
     * The same specs, plus the KEYWORDS they enforce.
     *
     * Reported from here rather than re-derived, for the reason laravel's `laravelSchemaRuleSpec()`
     * reports its own `consumed`: a second reading of these branches would drift from them, and the
     * whole point of the list is that the callback drops exactly what an attribute already covers.
     * `type` is deliberately absent — no spec here asserts it, so the callback keeps it.
     *
     * @param array<string, mixed> $constraints
     * @return array{specs: array<int, array{name: string, args: string}>, covered: array<int, string>}
     */
    private function scalarConstraintSpecList(array $constraints, bool $inAnyOfBranch = false): array
    {
        $specs = [];
        $covered = [];

        $length = [];
        $minLength = $this->symfonyIntegralCount($constraints['minLength'] ?? null);
        if ($minLength !== null) {
            $length[] = 'min: ' . $minLength;
            $covered[] = 'minLength';
        }
        $maxLength = $this->symfonyIntegralCount($constraints['maxLength'] ?? null);
        if ($maxLength !== null) {
            $length[] = 'max: ' . $maxLength;
            $covered[] = 'maxLength';
        }
        if ($length !== []) {
            $specs[] = ['name' => 'Length', 'args' => implode(', ', $length)];
        }

        $min = $constraints['minimum'] ?? null;
        $max = $constraints['maximum'] ?? null;
        $exclusiveMin = $constraints['exclusiveMinimum'] ?? null;
        $exclusiveMax = $constraints['exclusiveMaximum'] ?? null;

        if (is_int($exclusiveMin) || is_float($exclusiveMin)) {
            $specs[] = ['name' => 'GreaterThan', 'args' => $this->numericLiteral($exclusiveMin)];
            $covered[] = 'exclusiveMinimum';
        } elseif ($exclusiveMin === true && (is_int($min) || is_float($min))) {
            $specs[] = ['name' => 'GreaterThan', 'args' => $this->numericLiteral($min)];
            $covered[] = 'exclusiveMinimum';
            $covered[] = 'minimum';
            $min = null;
        }

        if (is_int($exclusiveMax) || is_float($exclusiveMax)) {
            $specs[] = ['name' => 'LessThan', 'args' => $this->numericLiteral($exclusiveMax)];
            $covered[] = 'exclusiveMaximum';
        } elseif ($exclusiveMax === true && (is_int($max) || is_float($max))) {
            $specs[] = ['name' => 'LessThan', 'args' => $this->numericLiteral($max)];
            $covered[] = 'exclusiveMaximum';
            $covered[] = 'maximum';
            $max = null;
        }

        $range = [];
        if (is_int($min) || is_float($min)) {
            $range[] = 'min: ' . $this->numericLiteral($min);
            $covered[] = 'minimum';
        }
        if (is_int($max) || is_float($max)) {
            $range[] = 'max: ' . $this->numericLiteral($max);
            $covered[] = 'maximum';
        }
        if ($range !== []) {
            $specs[] = ['name' => 'Range', 'args' => implode(', ', $range)];
        }

        if (is_int($constraints['multipleOf'] ?? null) || is_float($constraints['multipleOf'] ?? null)) {
            $specs[] = ['name' => 'DivisibleBy', 'args' => $this->numericLiteral($constraints['multipleOf'])];
            $covered[] = 'multipleOf';
        }

        if (
            is_string($constraints['pattern'] ?? null)
            && $constraints['pattern'] !== ''
            && $this->patternCompiles($constraints['pattern'])
        ) {
            $delimited = DtoServiceSupport::delimitPattern($constraints['pattern']);
            $specs[] = ['name' => 'Regex', 'args' => $this->phpStringLiteral($delimited)];
            $covered[] = 'pattern';
            // Symfony Regex skips empty strings; only forbid them when the pattern does.
            if (preg_match($delimited, '') === 0) {
                $specs[] = ['name' => 'NotBlank', 'args' => 'allowNull: true'];
            }
        }

        if (!$this->schemaAllowsNull($constraints) && !$this->enumRequiresJsonEquality($constraints) && is_array($constraints['enum'] ?? null) && $constraints['enum'] !== []) {
            $choices = [];
            $allLiteralizable = true;
            foreach ($constraints['enum'] as $enumValue) {
                if (!is_scalar($enumValue) && $enumValue !== null) {
                    $allLiteralizable = false;
                    break;
                }
                $choices[] = $this->enumChoiceLiteral($enumValue);
            }
            if ($allLiteralizable) {
                $specs[] = ['name' => 'Choice', 'args' => 'choices: [' . implode(', ', $choices) . ']'];
                $covered[] = 'enum';
            }
        }

        $format = $constraints['format'] ?? null;
        // A numeric format bounds a NUMBER: `{type: string, format: int32}` holds `"5000000000"`, and
        // `Assert\Range` read the string as the number it spells and refused it.
        $declaredType = $constraints['type'] ?? null;
        if (
            in_array($format, ['int32', 'uint32'], true)
            && $declaredType !== null
            && array_intersect(is_array($declaredType) ? $declaredType : [$declaredType], ['integer', 'number']) === []
        ) {
            $format = null;
        }
        $formatSpecs = $this->formatConstraintSpecs($format, $inAnyOfBranch);
        foreach ($formatSpecs as $spec) {
            $specs[] = $spec;
        }
        // `formatConstraintSpecs()` answers for a HANDFUL of formats and skips the rest, so the
        // keyword counts as covered only when it actually produced something. A `format: date` or an
        // unmapped one is still the callback's to enforce.
        if ($formatSpecs !== []) {
            $covered[] = 'format';
            // Native string format validators also skip empty strings, but these formats forbid them.
            $notBlank = ['name' => 'NotBlank', 'args' => 'allowNull: true'];
            if (!in_array($constraints['format'], ['int32', 'uint32'], true) && !in_array($notBlank, $specs, true)) {
                $specs[] = $notBlank;
            }
        }

        return ['specs' => $specs, 'covered' => array_values(array_unique($covered))];
    }

    /**
     * Maps an OpenAPI `format` to Symfony format constraints. Formats without a clean Symfony
     * equivalent (date/date-time are covered by the DateTimeImmutable type; duration, etc.) are skipped.
     *
     * @return array<int, array{name: string, args: string}>
     */
    private function formatConstraintSpecs(mixed $format, bool $inAnyOfBranch = false): array
    {
        if (!is_string($format)) {
            return [];
        }

        // A branch of `Assert\AtLeastOneOf` is checked by Symfony alone — the callback has no say in
        // which branch matched — so there the format keeps its constraint, worded as Symfony words it.
        if ($inAnyOfBranch) {
            $branchSpec = match ($format) {
                // The shape only, as the callback checks a uuid everywhere else: `Assert\Uuid` pins the
                // version and variant, and refused here what a `oneOf` branch accepted.
                'uuid' => [
                    'name' => 'Regex',
                    'args' => "pattern: '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', message: 'This is not a valid UUID.'",
                ],
                'hostname' => ['name' => 'Hostname', 'args' => ''],
                'ipv4' => ['name' => 'Ip', 'args' => "version: '4'"],
                'ipv6' => ['name' => 'Ip', 'args' => "version: '6'"],
                default => null,
            };
            if ($branchSpec !== null) {
                return [$branchSpec];
            }
        }

        // `uuid`, `email`, `hostname` and the IP formats are the callback's: it checks them on a list
        // item, where an `Assert\All` beside it reported a wrong-typed item twice, and a constraint
        // here worded the same mistake on a plain property differently — `This is not a valid UUID.`
        // against `field "ids"[0] must match format uuid.`. The callback words both alike, as the
        // runtime mode does.
        return match ($format) {
            'url' => [['name' => 'Url', 'args' => '']],
            'int32' => [['name' => 'Range', 'args' => 'min: -2147483648, max: 2147483647']],
            'uint32' => [['name' => 'Range', 'args' => 'min: 0, max: 4294967295']],
            default => [],
        };
    }

    /**
     * The generated enum an inline `enum` branch of a property's `anyOf` is hydrated into: the
     * property's type member that is an enum listing exactly the branch's members. Null where the
     * branch has no `enum`, or no such enum was generated — a member list that has no PHP enum (bool
     * or null members) stays a scalar `Choice`.
     *
     * @param SchemaProperty $property
     * @param array<mixed> $branch
     */
    private function symfonyAnyOfEnumBranchClass(array $property, array $branch): ?string
    {
        $members = $branch['enum'] ?? null;
        if (!is_array($members) || $members === []) {
            return null;
        }
        $members = array_values(array_filter(array: $members, callback: static fn(mixed $member): bool => $member !== null));

        foreach (explode('|', $property['type']) as $typeMember) {
            $short = $this->shortClassName(ltrim(trim($typeMember), '?'));
            $values = $this->enumSchemas[$short]['values'] ?? null;
            if (is_array($values) && array_values($values) === $members) {
                return $short;
            }
        }

        return null;
    }

    /**
     * Builds `new Assert\*(...)` expressions enforcing an anyOf branch, a value with no PHP type hint
     * of its own: a Type constraint plus the scalar constraint specs.
     *
     * An ITEM of a list branch is held as decoded, nothing casts it: a `number` item is then an `int`
     * as often as a `float`, and `Type('float')` refused `[5]`.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function valueConstraintExpressions(array $schema, bool $isItem = false): array
    {
        $expressions = [];

        // A branch naming a generated class: holding that class is the whole branch here, and its own
        // rules are the cascade's (`symfonyPropertyCascades()`), so `Valid` reports them at their path.
        // An enum the same: the serializer has already turned a member into its case, so holding the
        // enum IS the member check. Left out, the branch had no expression, the whole `AtLeastOneOf`
        // was dropped with it, and the scalar branch beside it — `minimum: 5` — checked nothing.
        $branchClass = is_string($schema['x-php-instanceof'] ?? null) ? $this->shortClassName($schema['x-php-instanceof']) : null;
        if (is_string($branchClass) && (array_key_exists($branchClass, $this->dtoSchemas) || array_key_exists($branchClass, $this->enumSchemas))) {
            return ['new Assert\Type(' . $branchClass . '::class)'];
        }

        $symfonyType = $this->openApiTypeToSymfonyType($schema['type'] ?? null);
        if ($isItem && $symfonyType === 'float') {
            $expressions[] = "new Assert\\Type(['int', 'float'])";
        } elseif (($schema['type'] ?? null) === 'array') {
            // A list, not any PHP array: a JSON object decodes to one too, and `{"zz": 1}` passed a list
            // branch of numbers.
            $expressions[] = "new Assert\\Type('list')";
        } elseif ($symfonyType !== null) {
            $expressions[] = "new Assert\\Type('" . $symfonyType . "')";
        }

        foreach ($this->scalarConstraintSpecs($schema, inAnyOfBranch: true) as $spec) {
            $expressions[] = 'new Assert\\' . $spec['name'] . '(' . $spec['args'] . ')';
        }

        // A list branch of scalar items — `anyOf: [{type: array, items: {type: integer}}, {type: boolean}]`.
        // The items are part of the branch: left out, `["x"]` matched it as "an array". Only after
        // `Type('list')` has held, which the `Sequentially` the branch becomes guarantees; an item of
        // any other shape keeps the branch at "an array", as before.
        $items = $schema['items'] ?? null;
        if (
            $symfonyType === 'array'
            && ($schema['type'] ?? null) === 'array'
            && is_array($items)
            && in_array($items['type'] ?? null, ['integer', 'number', 'string', 'boolean'], true)
        ) {
            $itemExpressions = $this->valueConstraintExpressions(schema: $items, isItem: true);
            // `Type` takes null for valid, so inside `All` it let `[null]` through as a list of
            // booleans. An item the schema does not let be null is refused it first.
            if (($items['nullable'] ?? null) !== true) {
                $itemExpressions = ['new Assert\NotNull()', ...$itemExpressions];
            }
            $expressions[] = 'new Assert\All([' . implode(', ', $itemExpressions) . '])';
        }
        // A list branch of null items — `anyOf: [{type: array, items: {type: 'null'}}, {type: integer}]`.
        // `null` has no `Assert\Type` spelling, so the branch stayed "a list" and `[7]` matched it.
        if (
            ($schema['type'] ?? null) === 'array'
            && is_array($items)
            && in_array($items['type'] ?? null, ['null', ['null']], true)
        ) {
            $expressions[] = 'new Assert\All([new Assert\IsNull()])';
        }

        return $expressions;
    }

    private function openApiTypeToSymfonyType(mixed $type): ?string
    {
        if (!is_string($type)) {
            return null;
        }

        return match ($type) {
            'integer' => 'int',
            'number' => 'float',
            'string' => 'string',
            'boolean' => 'bool',
            'array', 'object' => 'array',
            default => null,
        };
    }

    private function symfonyPreferredTypeForMapValue(string $propertyType): ?string
    {
        if (preg_match('/^array<string,\s*(.+)>$/', $propertyType, $matches) !== 1) {
            return null;
        }

        $valueType = ltrim(trim($matches[1]), '?');
        if ($valueType === '' || $valueType === 'mixed') {
            return null;
        }

        $short = $this->shortClassName($valueType);
        if (
            array_key_exists($short, $this->enumSchemas)
            || array_key_exists($short, $this->dtoSchemas)
            || $valueType === 'DateTimeImmutable'
        ) {
            return $short . '::class';
        }

        return null;
    }

    private function enumChoiceLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return $this->numericLiteral($value);
        }

        return $this->phpStringLiteral((string)$value);
    }

    /**
     * True when the property references a generated DTO (directly or as an array of DTOs), so a
     * cascade (#[Assert\Valid]) should be emitted. Enums validate by type and do not cascade.
     *
     * A union holding a scalar beside a class is the exception: Symfony refuses to cascade into a
     * scalar ("Cannot create metadata for non-objects"), so that one is cascaded by hand —
     * `symfonyScalarUnionCascades()`.
     *
     * @param SchemaProperty $property
     */
    private function symfonyPropertyCascades(array $property): bool
    {
        $members = $this->symfonyCascadeCandidateMembers($property);

        return $this->symfonyMembersNameAClass($members)
            && ($this->symfonyTypeIsContainer($property) || !$this->symfonyMembersNameAScalar($members));
    }

    /**
     * A property typed `string|Alpha`: it holds a class only some of the time, and `Assert\Valid`
     * throws on the scalar the rest of the time. Inside a list the same union is fine — Symfony
     * cascades into the objects of an array and steps over its scalars.
     *
     * @param SchemaProperty $property
     */
    private function symfonyScalarUnionCascades(array $property): bool
    {
        $members = $this->symfonyCascadeCandidateMembers($property);

        return !$this->symfonyTypeIsContainer($property)
            && $this->symfonyMembersNameAClass($members)
            && $this->symfonyMembersNameAScalar($members);
    }

    /**
     * The members of the type the property finally holds.
     *
     * Down through every container, a map's key type included: `array<string, array<Item>>` holds
     * Items as surely as `array<Item>` does. Read one level only, a map of lists never cascaded, and
     * an Item with a callback of its own went unchecked — the parent's callback leaves a DTO with one
     * to the cascade.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function symfonyCascadeCandidateMembers(array $property): array
    {
        $type = ltrim($property['type'], '?');
        while (preg_match('/^array<(?:[^,<>]+,\s*)?(.+)>$/', $type, $matches) === 1) {
            $type = ltrim(trim($matches[1]), '?');
        }

        $members = [];
        foreach ($this->topLevelTypeMembers($type) as $member) {
            // A list beside other members (`array<Shape>|int`) holds its items as surely as a list
            // alone does.
            while (preg_match('/^array<(?:[^,<>]+,\s*)?(.+)>$/', $member, $matches) === 1) {
                $member = ltrim(trim($matches[1]), '?');
            }
            foreach (explode('|', $member) as $inner) {
                $inner = ltrim(trim($inner), '?');
                if ($inner !== '' && $inner !== 'null') {
                    $members[] = $inner;
                }
            }
        }

        return $members;
    }

    /**
     * A union holds ONE of its members, so any member that is a generated class needs the cascade:
     * `Alpha|Beta` was looked up as a single class name, matched nothing, and `{"a":"x"}` went through
     * against Alpha's `minLength`.
     *
     * @param array<int, string> $members
     */
    private function symfonyMembersNameAClass(array $members): bool
    {
        foreach ($members as $member) {
            if (array_key_exists($this->shortClassName($member), $this->dtoSchemas)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $members
     */
    private function symfonyMembersNameAScalar(array $members): bool
    {
        return array_intersect($members, ['string', 'int', 'float', 'bool', 'true', 'false', 'mixed']) !== [];
    }

    /**
     * @param SchemaProperty $property
     */
    private function symfonyTypeIsContainer(array $property): bool
    {
        // `array|string|Fox` is a union that may hold an array, not a container: read as one, it kept
        // `#[Assert\Valid]`, which Symfony 8 refuses to point at the string member (a 500).
        return preg_match('/^array(?:<.*>)?$/', ltrim($property['type'], '?')) === 1;
    }

    /**
     * The cascade `Assert\Valid` cannot give a `string|Alpha` property, as a callback of its own: it
     * validates the object such a property holds, at the property's path, exactly where the attribute
     * would have reported it.
     *
     * @param array<int, SchemaProperty> $properties
     */
    private function renderSymfonyScalarUnionCascade(array $properties): string
    {
        $lines = [];
        foreach ($properties as $property) {
            if (!$this->symfonyScalarUnionCascades($property)) {
                continue;
            }
            $name = $property['name'];
            // A list member (`array<Shape>|int`) is cascaded into the same way: the validator walks
            // the objects of an array it is given.
            $holdsList = false;
            foreach ($this->topLevelTypeMembers(ltrim($property['type'], '?')) as $member) {
                $holdsList = $holdsList || str_starts_with($member, 'array<');
            }
            $lines[] = $holdsList
                ? sprintf('        if (is_object($this->%1$s) || is_array($this->%1$s)) {', $name)
                : sprintf('        if (is_object($this->%s)) {', $name);
            $lines[] = sprintf("            \$context->getValidator()->inContext(\$context)->atPath('%s')->validate(\$this->%s, null, \$context->getGroup());", $name, $name);
            $lines[] = '        }';
        }
        if ($lines === []) {
            return '';
        }

        $body = implode("\n", $lines);

        return <<<PHP

    /**
     * Cascades into the object a property holds beside a scalar member of its type. `#[Assert\\Valid]`
     * would do it, but Symfony throws when that attribute meets the scalar instead.
     */
    #[Assert\\Callback]
    private function validateOpenApiUnionMembers(\\Symfony\\Component\\Validator\\Context\\ExecutionContextInterface \$context): void
    {
{$body}
    }

PHP;
    }

    /**
     * A count keyword's value as the integer it spells: JSON Schema calls `2.0` the integer 2, and a
     * document writing it got no `Assert\Length`/`Assert\Count` at all — while the callback, which
     * drops what an attribute covers, had already let the keyword go.
     */
    private function symfonyIntegralCount(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int)$value,
            default => null,
        };
    }

    /**
     * `minProperties`/`maxProperties` on a generated DTO-typed property cannot use Assert\Count:
     * Count expects array|\Countable, while nested DTOs are objects. These bounds are handled by
     * the callback path instead.
     *
     * @param SchemaProperty $property
     */
    private function shouldCallbackValidateObjectPropertyBounds(array $property): bool
    {
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        if (
            $this->symfonyIntegralCount($constraints['minProperties'] ?? null) === null
            && $this->symfonyIntegralCount($constraints['maxProperties'] ?? null) === null
        ) {
            return false;
        }
        if (!$this->symfonyPropertyCascades($property)) {
            return false;
        }

        return preg_match('/^array<.+>$/', $property['type']) !== 1;
    }

    /**
     * Whether an optional property's `default` breaks the property's own keywords. A default is not
     * validated (JSON Schema 2020-12 §9.2), yet an attribute checks the property, which holds it — so
     * `{maximum: 3, default: 5}` refused `{}`. Such a property's keywords go to the callback, which
     * checks only what was sent. Answered with the runtime validator, the reference reading of the
     * keywords.
     *
     * @param SchemaProperty $property
     */
    private function symfonyDefaultBreaksItsKeywords(array $property): bool
    {
        $default = $property['default'] ?? null;
        if ($default === null || $this->propertyIsRequiredOnInput($property)) {
            return false;
        }
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        // What the property HOLDS while the key is absent: a container default is written as `[]`
        // (`renderDefaultValue()`), so `{minItems: 2, default: [1, 2]}` holds an empty list, which
        // `Assert\Count` refused on `{}` just the same.
        $held = $this->symfonyPropertyHoldsAnArray($property) ? [] : $default;

        return (new DtoValidator())->validate('default', $held, $constraints) !== [];
    }

    /**
     * Whether the schema is `type: array` (null aside) on a property the PHP type holds as an array.
     *
     * @param array<string, mixed> $constraints
     */
    private function symfonySchemaIsAList(array $constraints, string $phpType): bool
    {
        $arrayType = ltrim($phpType, '?');

        return $this->soleTypeBesidesNull($constraints['type'] ?? null) === 'array'
            && ($arrayType === 'array' || str_starts_with($arrayType, 'array<'));
    }

    /**
     * Whether the property's PHP type is a non-nullable array — a list or a map.
     *
     * @param SchemaProperty $property
     */
    private function symfonyPropertyHoldsAnArray(array $property): bool
    {
        return $property['type'] === 'array' || str_starts_with($property['type'], 'array<');
    }

    /**
     * @param SchemaProperty $property
     */
    private function shouldForceRootCallbackForProperty(array $property): bool
    {
        if ($this->shouldCallbackValidateObjectPropertyBounds($property)) {
            return true;
        }

        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        // A `default` is not validated (JSON Schema 2020-12 §9.2): `{maximum: 3, default: 5}` with the
        // key absent is a valid payload. An attribute checks the property, which holds that default,
        // and refused `{}`; the callback checks only what was sent. So where the default breaks the
        // property's own keywords, they go to the callback.
        if ($this->symfonyDefaultBreaksItsKeywords($property)) {
            return true;
        }
        // `pattern` beside no `type`, `minimum` beside `type: [string, integer]`: no attribute says
        // "only if the value is a string", so `resolveSymfonyAttributes()` emits none and the callback,
        // which applies each keyword to its own type, takes them — `Assert\Regex` refused `true`.
        $folded = $this->foldScalarAllOfConstraints($constraints);
        if (count($this->withoutKeywordsTheTypeDoesNotPin(schema: $folded, phpType: $property['type'])) !== count($folded)) {
            return true;
        }
        if (($constraints['type'] ?? null) === 'null') {
            return true;
        }
        // An untyped schema with `properties` is not materialized — JSON Schema lets it hold any value,
        // so the property stays `mixed` — and no class carries attributes for the keys it describes.
        // Filtered with the class-level rule, `properties: {foo: {type: integer}}` lost its `type` as
        // "an attribute's job" and the whole keyword went with it: `{"foo": []}` was accepted.
        if ($property['type'] === 'mixed' && $this->schemaDescribesUnmaterializedKeys($constraints)) {
            return true;
        }
        if (is_array($constraints['oneOf'] ?? null)) {
            return true;
        }
        // On a `mixed` property no attribute can say "only if the value is a string": `Assert\Length`
        // inside `AtLeastOneOf` passed the integer 3 for `{type: string, anyOf: [...]}`; an `anyOf` of
        // object branches or of `{type: null}` had no `Assert\*` spelling and was checked by nothing, and
        // neither was a `prefixItems` inside an `allOf` branch;
        // a type LIST (`[array, object, null]`) had no attribute at all and took any value. The
        // callback reads each keyword for its own type.
        if (
            $property['type'] === 'mixed'
            && (
                is_array($constraints['anyOf'] ?? null)
                || is_array($constraints['allOf'] ?? null)
                || is_array($constraints['type'] ?? null)
            )
        ) {
            return true;
        }
        // An `anyOf` no `Assert\AtLeastOneOf` spells — one branch, a branch with no `Assert\*`
        // expression (an `allOf`, a `multipleOf`) — was dropped by the attribute and skipped by the
        // callback: `{"egret": 25}` passed `anyOf: [{maximum: 20}]`.
        // Not on a property held as a generated class: there the branches describe keys the built object
        // may no longer carry, and checked against it a valid payload was refused.
        if (
            is_array($constraints['anyOf'] ?? null)
            && !array_key_exists($this->shortClassName(ltrim($property['type'], '?')), $this->dtoSchemas)
            && $this->symfonyAtLeastOneOf(property: $property, constraints: $this->symfonyAttributeConstraints($property)) === null
        ) {
            return true;
        }
        if (is_array($constraints['anyOf'] ?? null) && $this->anyOfBranchCarriesPattern($constraints['anyOf'])) {
            return true;
        }
        if (is_array($constraints['anyOf'] ?? null) && $this->anyOfBranchOutgrowsItsAttribute($constraints['anyOf'])) {
            return true;
        }
        if (is_array($constraints['anyOf'] ?? null) && $this->anyOfBranchCarriesUnspelledEnum(property: $property, branches: $constraints['anyOf'])) {
            return true;
        }
        // A BOOLEAN branch has no `Assert\*` spelling, so `Assert\AtLeastOneOf` is not emitted for such an
        // `anyOf` at all — and the callback skipped it too, expecting the attribute. `anyOf: [false,
        // false]` was then checked by nothing and accepted every value the document refuses.
        if (is_array($constraints['anyOf'] ?? null) && array_filter($constraints['anyOf'], is_bool(...)) !== []) {
            return true;
        }

        if (
            is_string($constraints['contentEncoding'] ?? null)
            || is_string($constraints['contentMediaType'] ?? null)
            || is_array($constraints['contentSchema'] ?? null)
        ) {
            return true;
        }

        // Read folded, as the attributes read it: a format inside `allOf` has no attribute either.
        $format = $folded['format'] ?? $constraints['format'] ?? null;
        if (is_string($format) && $this->isCallbackOnlyStringFormat($format)) {
            return true;
        }

        if (($constraints['uniqueItems'] ?? null) === true) {
            return true;
        }

        if (
            $this->shouldSkipSymfonyScalarAttributesForProperty($property)
            && $this->schemaHasSymfonyScalarConstraintKeywords($constraints)
        ) {
            return true;
        }

        return is_string($constraints['pattern'] ?? null)
            && $constraints['pattern'] !== ''
            && !$this->patternCompiles($constraints['pattern']);
    }

    /**
     * The `Assert\AtLeastOneOf` attribute a property-level `anyOf` is checked by, or null when none
     * spells it: a single branch, a branch with no `Assert\*` expression, a pattern, a container
     * keyword or an unspelled enum — and on a `mixed` property. Such an `anyOf` is the callback's.
     *
     * @param SchemaProperty $property
     * @param array<string, mixed> $constraints the folded constraints `resolveSymfonyAttributes()` reads
     */
    private function symfonyAtLeastOneOf(array $property, array $constraints): ?string
    {
        $anyOf = $constraints['anyOf'] ?? null;
        if (
            !is_array($anyOf)
            || count($anyOf) < 2
            || $this->anyOfBranchCarriesPattern($anyOf)
            || $this->anyOfBranchCarriesEmail($anyOf)
            || $this->symfonyAnyOfObjectBranchHeldDecoded(property: $property, branches: $anyOf)
            || $this->anyOfBranchOutgrowsItsAttribute($anyOf)
            || $this->anyOfBranchCarriesUnspelledEnum(property: $property, branches: $anyOf)
            || $this->anyOfBranchRequiresAnUndeclaredName($anyOf)
            || $this->symfonyUnionHasFreeFormObjectMember(['anyOf' => $anyOf])
            || $this->symfonyAnyOfBranchNamesUnionHeldDecoded($anyOf)
            || $property['type'] === 'mixed'
        ) {
            return null;
        }

        $branches = [];
        foreach ($anyOf as $branchIndex => $branch) {
            // An inline enum branch is hydrated into the PHP enum generated for it, so holding
            // that enum is the member check — `Type('string')` beside a `Choice` refused every
            // member, because by then it was no string.
            $enumClass = is_array($branch) ? $this->symfonyAnyOfEnumBranchClass(property: $property, branch: $branch) : null;
            // A `{type: null}` branch beside two others — `anyOf: [Code, null, integer]` — is the
            // `null` the nullable PHP type admits. It had no expression, the whole attribute was
            // dropped, and the other branches' keywords with it: `"a"` passed a `minLength: 2`.
            $isNullBranch = is_array($branch) && in_array($branch['type'] ?? null, ['null', ['null']], true) && count($branch) === 1;
            // An inline object branch beside scalars is hydrated into the class generated for it,
            // as a `$ref` one is: `Type('array')` refused every valid object, which by then was no
            // array. The class checks its own keywords through the union cascade.
            // Beside nothing but `null` (`?ProbeLemur`), the class is the type itself.
            // A free-form `{type: object}` has no class of its own: it is the `array` it is held as.
            $inlineClass = is_array($branch) && ($branch['type'] ?? null) === 'object' && !array_key_exists('x-php-instanceof', $branch)
                && !$this->isFreeFormObjectSchema($branch)
                ? $this->unionClassBesideScalars($property['type'])
                    ?? $this->symfonyLoneDtoClass($property['type'])
                    ?? $this->symfonyInlineBranchClassBesideReferences(phpType: $property['type'], branches: $anyOf, branchIndex: $branchIndex)
                : null;
            // A `format: date` / `date-time` branch is hydrated into the `DateTimeImmutable` the PHP
            // type names, so holding one IS the branch: `Type('string')` refused every valid date,
            // because by then it was no string — `anyOf: [{format: date-time}, {type: null}]` with it.
            $isTemporalBranch = is_array($branch)
                && in_array($branch['type'] ?? 'string', ['string', ['string']], true)
                && in_array($branch['format'] ?? null, ['date', 'date-time', 'datetime'], true)
                && in_array('DateTimeImmutable', explode('|', ltrim($property['type'], '?')), true);
            $expressions = match (true) {
                $isNullBranch => ['new Assert\IsNull()'],
                $isTemporalBranch => ['new Assert\Type(DateTimeImmutable::class)'],
                $enumClass !== null => ['new Assert\Type(' . $enumClass . '::class)'],
                $inlineClass !== null => ['new Assert\Type(' . $inlineClass . '::class)'],
                is_array($branch) => $this->valueConstraintExpressions($branch),
                default => [],
            };
            if ($expressions === []) {
                return null;
            }
            $branches[] = count($expressions) === 1
                ? $expressions[0]
                : 'new Assert\Sequentially([' . implode(', ', $expressions) . '])';
        }

        return count($branches) >= 2 ? '#[Assert\AtLeastOneOf([' . implode(', ', $branches) . '])]' : null;
    }

    /**
     * Whether an `anyOf` branch names an undiscriminated union component of classes — `anyOf: [$ref
     * Shape, {type: string}]`, Shape an `anyOf` of two classes. The value is held as the decoded array
     * (`symfonyUndiscriminatedUnionHeldType()`), never as the interface, so `Type(Shape::class)` refused
     * every valid object; the callback judges the array against the members, as it does for `oneOf`.
     *
     * @param array<mixed> $anyOf
     */
    private function symfonyAnyOfBranchNamesUnionHeldDecoded(array $anyOf): bool
    {
        foreach ($anyOf as $branch) {
            $branchClass = is_array($branch) ? $branch['x-php-instanceof'] ?? null : null;
            if (is_string($branchClass) && $this->isUnhydratableUnionClass($this->shortClassName($branchClass))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class an inline object branch is hydrated into beside `$ref` branches of other classes —
     * `ProbeOkapi` of `ProbeOkapi|Teal`: the one generated class of the type no `$ref` branch names.
     * `Type('array')` refused it there, the value being by then no array.
     *
     * Two or more inline object branches are named apart (`ProbeLynxAnyOf1|ProbeLynxAnyOf2`), in branch
     * order: the n-th inline object branch is held as the n-th such class.
     *
     * @param array<mixed> $branches
     */
    private function symfonyInlineBranchClassBesideReferences(string $phpType, array $branches, int|string $branchIndex): ?string
    {
        $referenced = [];
        foreach ($branches as $branch) {
            $instanceOf = is_array($branch) ? $branch['x-php-instanceof'] ?? null : null;
            if (is_string($instanceOf)) {
                $referenced[] = $this->shortClassName($instanceOf);
            }
        }
        $inline = [];
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            $short = $this->shortClassName($member);
            if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas) && !in_array($short, $referenced, true)) {
                $inline[] = $short;
            }
        }

        if (count($inline) === 1) {
            return $inline[0];
        }
        $inlineObjectBranches = array_keys(array_filter(
            array: $branches,
            callback: fn(mixed $branch): bool => is_array($branch)
                && ($branch['type'] ?? null) === 'object'
                && !array_key_exists('x-php-instanceof', $branch)
                && !$this->isFreeFormObjectSchema($branch),
        ));
        $position = array_search($branchIndex, $inlineObjectBranches, true);

        return $inline !== [] && count($inline) === count($inlineObjectBranches) && is_int($position) ? $inline[$position] : null;
    }

    /**
     * Whether an inline object branch of a property-level `anyOf` requires a name its `properties` do
     * not declare. The class generated for the branch drops that name, so `Type(ProbeFox)` stood in for
     * a branch it does not check: `{"fox": {"tail": "x"}}` passed `required: [ear]`. Such an `anyOf` is
     * the callback's, which reads the branch whole — as a plain object property keeps its callback
     * (README.support-matrix, "a required name no property declares").
     *
     * @param array<mixed> $branches
     */
    private function anyOfBranchRequiresAnUndeclaredName(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && ($branch['type'] ?? null) === 'object' && $this->requiresAnUndeclaredName($branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a property-level `anyOf` has a container branch whose keywords `valueConstraintExpressions()`
     * cannot spell: it says "an array", and for a list of scalars "of these items", nothing more. A
     * nullable list alias with `maxItems: 2` took `[1, 2, 3]`, a nullable map of integers took
     * `{"a": "x"}`, and the callback skipped the `anyOf` as the attribute's. Such an `anyOf` is the
     * callback's, which reads every keyword of every branch.
     *
     * @param array<mixed> $branches
     */
    private function anyOfBranchOutgrowsItsAttribute(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (!is_array($branch) || !in_array($branch['type'] ?? null, ['array', 'object'], true)) {
                continue;
            }
            // A class branch is held as its class, which checks its own keywords.
            if (is_string($branch['x-php-instanceof'] ?? null) || array_key_exists('properties', $branch)) {
                continue;
            }
            if (array_intersect(array_keys($branch), self::SYMFONY_UNSPELLED_CONTAINER_KEYWORDS) !== []) {
                return true;
            }
            $items = $branch['items'] ?? null;
            if (is_array($items) && array_diff(array_keys($items), ['type']) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a property-level `anyOf` has an `enum` branch no `Assert\*` expression spells: a NUMERIC
     * member list is compared by JSON value (`5` and `5.0` are one member), which `Choice` cannot say, and
     * no PHP enum holds a float. `valueConstraintExpressions()` left the `enum` out, so
     * `anyOf: [{type: number, enum: [5]}, {type: string}]` took `6` as "a number". Such an `anyOf` is the
     * callback's, which reads the branch's `enum` the way every other mode does.
     *
     * A `const` branch the same: no expression spells it, and `anyOf: [{type: string, const: violet},
     * {type: null}]` — the canonical spelling of the `oneOf` pair — took `"red"` as "a string".
     *
     * @param SchemaProperty $property
     * @param array<mixed> $branches
     */
    private function anyOfBranchCarriesUnspelledEnum(array $property, array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && array_key_exists('const', $branch) && !is_string($branch['x-php-instanceof'] ?? null)) {
                return true;
            }
            if (!is_array($branch) || !is_array($branch['enum'] ?? null) || $branch['enum'] === []) {
                continue;
            }
            // Hydrated into a PHP enum: holding the case is the member check.
            if (is_string($branch['x-php-instanceof'] ?? null) || $this->symfonyAnyOfEnumBranchClass(property: $property, branch: $branch) !== null) {
                continue;
            }
            if (!in_array('enum', $this->scalarConstraintSpecList($branch, inAnyOfBranch: true)['covered'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an object branch of a property-level `anyOf` is held as the decoded array: a `$ref` branch
     * whose class the PHP type does not name, or an inline object branch beside no generated class at
     * all (`symfonyWithFreeFormObjectUnionsHeldAsArray()` dropped it). `Assert\Type('array')` stood for
     * such a branch and read none of its keywords — `{"fox": {"ear": "x"}}` passed a required integer
     * `ear` — and `Type(Lime::class)` refused every object the array held. The callback judges them.
     *
     * @param SchemaProperty $property
     * @param array<mixed> $branches
     */
    private function symfonyAnyOfObjectBranchHeldDecoded(array $property, array $branches): bool
    {
        $held = array_map($this->shortClassName(...), $this->unionMembersBesideNull($property['type']));
        $holdsAClass = array_filter(
            array: $held,
            callback: fn(string $member): bool => array_key_exists($member, $this->dtoSchemas) && !array_key_exists($member, $this->enumSchemas),
        ) !== [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $branchClass = is_string($branch['x-php-instanceof'] ?? null) ? $this->shortClassName($branch['x-php-instanceof']) : null;
            if (is_string($branchClass)) {
                if (array_key_exists($branchClass, $this->dtoSchemas) && !array_key_exists($branchClass, $this->enumSchemas) && !in_array($branchClass, $held, true)) {
                    return true;
                }

                continue;
            }
            if (($branch['type'] ?? null) === 'object' && !$this->isFreeFormObjectSchema($branch) && !$holdsAClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a property-level `anyOf` has a branch, or the items of a list branch, of `format: email`
     * or `idn-email`. Such an `anyOf` is the callback's: `Assert\Email` checks the HTML5 pattern, which
     * takes `a.@example.com` and `a..b@example.com` — refused on a plain property, where the callback
     * asks `filter_var()` as every other mode does — and refuses the international domain of an
     * `idn-email`.
     *
     * @param array<mixed> $branches
     */
    private function anyOfBranchCarriesEmail(array $branches): bool
    {
        foreach ($branches as $branch) {
            $items = is_array($branch) ? $branch['items'] ?? null : null;
            foreach ([$branch, $items] as $schema) {
                if (is_array($schema) && in_array($schema['format'] ?? null, ['email', 'idn-email'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a property-level `anyOf` has a branch with a document `pattern` — at any depth. Such an
     * `anyOf` is the callback's, not `Assert\AtLeastOneOf`'s: Symfony's `Regex` reads a pattern the
     * engine gave up on as a plain mismatch and moves on to the next branch, so a value that could not
     * be checked was accepted by a sibling — where every other mode refuses it, and where the
     * interpreter tells an engine failure (refused) from a pattern PCRE cannot compile (a failed
     * branch, as before). A pattern one level down — under `allOf`, `not`, `if`, a nested `anyOf`, a
     * map's values — is worse still: the branch's `Assert\*` expressions leave it out altogether, so
     * the regex never ran and the sibling took the value.
     *
     * @param array<mixed> $branches
     */
    private function anyOfBranchCarriesPattern(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && $this->schemaCarriesPattern($branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$schema` or any schema inside it names a regex: a `pattern`, or `patternProperties`,
     * whose keys are regexes. Keywords whose values are data — `enum`, `const`, examples, extensions —
     * are not schemas and are not looked into, nor are the names under `properties`, where a member
     * called `pattern` is no keyword.
     *
     * @param array<mixed> $schema
     */
    private function schemaCarriesPattern(array $schema): bool
    {
        foreach ($schema as $keyword => $value) {
            if ($keyword === 'pattern' && is_string($value) && $value !== '') {
                return true;
            }
            if ($keyword === 'patternProperties' && is_array($value) && $value !== []) {
                return true;
            }
            if (
                !is_array($value)
                || (is_string($keyword) && (in_array($keyword, ['enum', 'const', 'default', 'example', 'examples'], true) || str_starts_with($keyword, 'x-')))
            ) {
                continue;
            }
            // A map of named schemas (`properties`, `dependentSchemas`) or a list of them (`allOf`)
            // is searched member by member; any other array value is a schema of its own.
            $members = in_array($keyword, ['properties', 'dependentSchemas', '$defs', 'definitions', 'allOf', 'anyOf', 'oneOf', 'prefixItems'], true)
                ? $value
                : [$value];
            foreach ($members as $member) {
                if (is_array($member) && $this->schemaCarriesPattern($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function schemaHasSymfonyScalarConstraintKeywords(array $constraints): bool
    {
        foreach (
            [
                'type',
                'const',
                'enum',
                'minLength',
                'maxLength',
                'pattern',
                'format',
                'minimum',
                'maximum',
                'exclusiveMinimum',
                'exclusiveMaximum',
                'multipleOf',
            ] as $keyword
        ) {
            if (array_key_exists($keyword, $constraints)) {
                return true;
            }
        }

        return false;
    }

    private function isCallbackOnlyStringFormat(string $format): bool
    {
        return in_array(
            needle: $format,
            haystack: [
                'uuid',
                'email',
                'idn-email',
                'hostname',
                'ipv4',
                'ipv6',
                'duration',
                'time',
                'regex',
                'json-pointer',
                'relative-json-pointer',
                'uri-reference',
                'iri-reference',
                'uri-template',
                'byte',
                'idn-hostname',
                'iri',
                'uri',
                'int64',
                'uint64',
            ],
            strict: true,
        );
    }

    /**
     * The generated class a type names on its own (`?ProbeLemur`), or null for anything else.
     */
    private function symfonyLoneDtoClass(string $phpType): ?string
    {
        $members = $this->unionMembersBesideNull($phpType);
        if (count($members) !== 1) {
            return null;
        }
        $short = $this->shortClassName($members[0]);

        return array_key_exists($short, $this->dtoSchemas) ? $short : null;
    }
}
