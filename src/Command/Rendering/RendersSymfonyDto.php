<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

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
     * Cache for `symfonyClassesReadByACallback()` — computed once per run, after every schema is
     * registered.
     *
     * @var array<string, true>|null
     */
    private ?array $symfonyClassesReadByACallback = null;

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
     * Renders a DTO in Symfony mode: a plain data class with promoted public readonly
     * constructor properties decorated with Symfony Validator (#[Assert\*]) and Serializer
     * (#[SerializedName]) attributes. No library runtime, interface, or normalization map.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $unionTypes
     * @param array{propertyName: string, mapping: array<string, string>}|null $discriminator
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
            $param = $this->resolveSymfonyParam($property, $namespace);
            foreach ($param['attributes'] as $attribute) {
                if (str_contains($attribute, 'Groups(')) {
                    $needsGroups = true;
                }
            }
            $params[] = $param;
        }

        $schemaDefinition = $this->dtoSchemas[$className] ?? [];
        $forceRootCallbackBounds = [];
        foreach ($properties as $property) {
            if ($this->shouldForceRootCallbackForProperty($property)) {
                $forceRootCallbackBounds[$property['openApiName']] = true;
            }
        }

        $validationConstraints = $this->filterInterpreterConstraints(
            constraints: $this->withConstraintSourceOf(
                className: $className,
                extraction: fn(): array => $this->extractValidationConstraints($this->classSchemaWithInlineAllOfMerged(schemaDefinition: $schemaDefinition, className: $className)),
            ),
            allowScalarKeywords: false,
            forceScalarOnProperties: $forceRootCallbackBounds,
            unmaterializedProperties: $this->mixedPropertyNames($properties),
        );
        $validationConstraints = $this->symfonyWithoutUncountableMinProperties($validationConstraints);

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
            if (!array_key_exists('type', $existing)) {
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
        // reading it from outside is impossible without this — hence the compact version. Emitted
        // only for the classes some other DTO's callback can actually reach: everywhere else it
        // would be a method nobody calls. A class from another file is the exception: several
        // documents write the same file, and only some of them read it from a callback — so it
        // always carries the method, or the last generation run decides what the others get.
        if (
            $validationParts['methods'] === ''
            && $params !== []
            && (
                array_key_exists($className, $this->symfonyClassesReadByACallback())
                || $this->isSymfonySharedClass($className)
            )
        ) {
            $validationParts['methods'] = $this->renderSymfonyStandalonePayloadMethod($params);
        }
        $validationParts['methods'] .= $this->renderSymfonyScalarUnionCascade($properties);

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
        foreach ($params as $param) {
            if ($param['temporalFormatter'] !== null) {
                $temporalFormatters[$param['temporalFormatter']] = $param['temporalFormatter'];
            }
        }
        $implementedInterfaces = [];
        foreach ($this->implementedUnionInterfaces($className, $extends) as $unionInterface) {
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
            'unionMembers' => null,
            'interfaceExtends' => [],
            'discriminatorMap' => null,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'extends' => null,
            'params' => $params,
            'temporalFormatters' => array_values($temporalFormatters),
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
        $this->symfonyClassesReadByACallback = null;
    }

    /**
     * Whether the class comes from a file other than the document being generated — a `$ref`ed
     * component file that other documents generate into the same place.
     */
    private function isSymfonySharedClass(string $className): bool
    {
        $sourceFile = $this->getSchemaSourceFile($className);

        return $sourceFile !== null && $this->rootSpecFile !== null && $sourceFile !== $this->rootSpecFile;
    }

    /**
     * The generated classes that some OTHER class's `#[Assert\Callback]` can meet as a value —
     * directly as a property, as an array item, or through the interface of a union it belongs to.
     * Only those need a payload view of their own; for the rest it would be dead code.
     *
     * @return array<string, true>
     */
    private function symfonyClassesReadByACallback(): array
    {
        if ($this->symfonyClassesReadByACallback !== null) {
            return $this->symfonyClassesReadByACallback;
        }

        $reachable = [];
        foreach (array_keys($this->dtoSchemas) as $className) {
            // No callback in the reader means it reads nobody.
            if ($this->symfonyCallbackConstraintsFor($className) === []) {
                continue;
            }

            foreach ($this->getSchemaProperties($className) as $property) {
                foreach ($this->generatedClassNamesInType($property['type']) as $referenced) {
                    $reachable[$referenced] = true;
                    // A property typed as a union interface holds one of its members at runtime.
                    foreach ($this->unionMembersOf($referenced) as $member) {
                        $reachable[$member] = true;
                    }
                }
            }
        }

        return $this->symfonyClassesReadByACallback = $reachable;
    }

    /**
     * @return array<string, mixed>
     */
    private function symfonyCallbackConstraintsFor(string $className): array
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? [];

        // Mirror the renderer: a bound that the PHP type cannot enforce forces a callback even
        // when nothing else would, and those are exactly the classes that read a nested DTO.
        $forceRootCallbackBounds = [];
        foreach ($this->getSchemaProperties($className) as $property) {
            if ($this->shouldForceRootCallbackForProperty($property)) {
                $forceRootCallbackBounds[$property['openApiName']] = true;
            }
        }
        $mixedProperties = $this->mixedPropertyNames($this->getSchemaProperties($className));

        // `false` is the default, and it is spelled out on purpose: it is the contrasting half of
        // the argument below. Scalar keywords are OFF everywhere and ON for exactly these
        // properties — dropping the `false` leaves the reader to guess what is being overridden.
        return $this->symfonyWithoutUncountableMinProperties($this->filterInterpreterConstraints(
            constraints: $this->withConstraintSourceOf(
                className: $className,
                extraction: fn(): array => $this->extractValidationConstraints($this->classSchemaWithInlineAllOfMerged(schemaDefinition: $schemaDefinition, className: $className)),
            ),
            allowScalarKeywords: false,
            forceScalarOnProperties: $forceRootCallbackBounds,
            unmaterializedProperties: $mixedProperties,
        ));
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
     * @param array{propertyName: string, mapping: array<string, string>}|null $discriminator
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
        $memberNames = $unionTypes !== [] ? $unionTypes : $this->discriminatorMembers($className);
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
                    $this->escapeSingleQuoted($discriminatorValue),
                    $this->formatClassNameForNamespace($mappedClass, $namespace),
                );
            }
            $discriminatorMap = sprintf(
                "#[\\Symfony\\Component\\Serializer\\Attribute\\DiscriminatorMap(typeProperty: '%s', mapping: [%s])]",
                $this->escapeSingleQuoted($discriminator['propertyName']),
                implode(', ', $entries),
            );
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $this->renderPhpTemplate('dto.symfony.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $className,
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
     * Keywords that grant permission rather than assert anything. They survive the filter so the
     * callback knows a null is allowed, but they cannot make a schema worth entering.
     *
     * @var array<int, string>
     */
    private const array SYMFONY_ANNOTATION_KEYWORDS = ['nullable'];

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
    private function resolveSymfonyParam(array $property, string $namespace): array
    {
        $phpType = $property['type'];
        $docType = null;

        if (str_contains($phpType, '<')) {
            $docType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
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
        // can hold the expression, so the property is left uninitialised and assigned there.
        $temporalDefault = null;
        $defaultLiteral = '';
        if (!$required && $default !== null) {
            $rendered = $this->renderDefaultValue($default, $phpType, $this->composePhpTypeHint($phpType, $schemaNullable));
            if ($this->symfonyPropertyIsTemporalScalar($property)) {
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
        $targetType = $this->dtoSchemas[ltrim($property['type'], '?\\')]['type'] ?? null;
        $targetPinsObject = $targetType === 'object'
            || (is_array($targetType) && in_array('object', $targetType, true) && !in_array('null', $targetType, true));
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
            ),
        ];
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
     */
    private function symfonyContextAttribute(string $name, ?bool $skipNullValues, ?string $temporalFormatter): ?string
    {
        $entries = [];
        if ($skipNullValues !== null) {
            $entries[] = '\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => ' . ($skipNullValues ? 'true' : 'false');
        }
        if ($temporalFormatter !== null) {
            $entries[] = sprintf(
                '\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::CALLBACKS => [%s => [self::class, %s]]',
                var_export($name, true),
                var_export($temporalFormatter, true),
            );
        }

        if ($entries === []) {
            return null;
        }
        if ($temporalFormatter === null) {
            return '#[\Symfony\Component\Serializer\Attribute\Context(normalizationContext: [' . implode(', ', $entries) . '])]';
        }

        // With a callback the line runs long; one entry per line, as php-cs-fixer would lay it out.
        return "#[\\Symfony\\Component\\Serializer\\Attribute\\Context(normalizationContext: [\n        " . implode(",\n        ", $entries) . ",\n    ])]";
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
        }
        if (!is_string($format)) {
            return null;
        }

        return $format === 'Y-m-d' ? 'formatOpenApiDate' : 'formatOpenApiDateTime';
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
        $constraints = $this->withoutKeywordsTheTypeDoesNotPin(
            schema: $this->foldScalarAllOfConstraints(
                constraints: is_array($property['constraints'] ?? null) ? $property['constraints'] : [],
            ),
            phpType: $property['type'],
        );
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
        $countMin = $this->symfonyIntegralCount($countMin);
        $countMax = $this->symfonyIntegralCount($countMax);
        if ($countMin !== null) {
            $count[] = 'min: ' . $countMin;
        }
        if ($countMax !== null) {
            $count[] = 'max: ' . $countMax;
        }
        if ($count !== []) {
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

        // anyOf — the value must satisfy at least one branch.
        // Not on a `mixed` property: the callback owns its `anyOf` — see `shouldForceRootCallbackForProperty()`.
        $anyOf = $constraints['anyOf'] ?? null;
        if (is_array($anyOf) && count($anyOf) >= 2 && !$this->anyOfBranchCarriesPattern($anyOf) && $property['type'] !== 'mixed') {
            $branches = [];
            $allBranchesValidatable = true;
            foreach ($anyOf as $branch) {
                // An inline enum branch is hydrated into the PHP enum generated for it, so holding
                // that enum is the member check — `Type('string')` beside a `Choice` refused every
                // member, because by then it was no string.
                $enumClass = is_array($branch) ? $this->symfonyAnyOfEnumBranchClass(property: $property, branch: $branch) : null;
                $expressions = match (true) {
                    $enumClass !== null => ['new Assert\Type(' . $enumClass . '::class)'],
                    is_array($branch) => $this->valueConstraintExpressions($branch),
                    default => [],
                };
                if ($expressions === []) {
                    $allBranchesValidatable = false;
                    break;
                }
                $branches[] = count($expressions) === 1
                    ? $expressions[0]
                    : 'new Assert\Sequentially([' . implode(', ', $expressions) . '])';
            }
            if ($allBranchesValidatable && count($branches) >= 2) {
                $attributes[] = '#[Assert\AtLeastOneOf([' . implode(', ', $branches) . '])]';
            }
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
            $delimited = DtoValidator::delimitPattern($constraints['pattern']);
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

        $formatSpecs = $this->formatConstraintSpecs($constraints['format'] ?? null, $inAnyOfBranch);
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
                'email', 'idn-email' => ['name' => 'Email', 'args' => ''],
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
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function valueConstraintExpressions(array $schema): array
    {
        $expressions = [];

        // A branch naming a generated class: holding that class is the whole branch here, and its own
        // rules are the cascade's (`symfonyPropertyCascades()`), so `Valid` reports them at their path.
        // An enum the same: the serializer has already turned a member into its case, so holding the
        // enum IS the member check. Left out, the branch had no expression, the whole `AtLeastOneOf`
        // was dropped with it, and the scalar branch beside it — `minimum: 5` — checked nothing.
        $branchClass = $schema['x-php-instanceof'] ?? null;
        if (is_string($branchClass) && (array_key_exists($branchClass, $this->dtoSchemas) || array_key_exists($branchClass, $this->enumSchemas))) {
            return ['new Assert\Type(' . $branchClass . '::class)'];
        }

        $symfonyType = $this->openApiTypeToSymfonyType($schema['type'] ?? null);
        if ($symfonyType !== null) {
            $expressions[] = "new Assert\\Type('" . $symfonyType . "')";
        }

        foreach ($this->scalarConstraintSpecs($schema, inAnyOfBranch: true) as $spec) {
            $expressions[] = 'new Assert\\' . $spec['name'] . '(' . $spec['args'] . ')';
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
        foreach (explode('|', $type) as $member) {
            $member = ltrim(trim($member), '?');
            if ($member !== '' && $member !== 'null') {
                $members[] = $member;
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
        return str_starts_with(ltrim($property['type'], '?'), 'array');
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
            $lines[] = sprintf('        if (is_object($this->%s)) {', $name);
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

        return (new DtoValidator())->validate('default', $default, $constraints) !== [];
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
        if (is_array($constraints['anyOf'] ?? null) && $this->anyOfBranchCarriesPattern($constraints['anyOf'])) {
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

        $format = $constraints['format'] ?? null;
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
}
