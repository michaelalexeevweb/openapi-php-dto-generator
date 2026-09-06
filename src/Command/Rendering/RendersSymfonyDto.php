<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

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
     * Whether the emitted interpreter enforces this `format` at all. A custom or unlisted one
     * (`uppercase`, `slug`, …) falls through to the permissive default, exactly as the runtime
     * validator does — so putting it in a constraint map buys nothing but an emitted interpreter.
     */
    private function openApiInterpreterChecksFormat(string $format): bool
    {
        if (in_array($format, self::OPENAPI_NUMERIC_FORMATS, true)) {
            return true;
        }

        $arm = self::OPENAPI_FORMAT_ARMS[$format] ?? null;

        return $arm !== null && $arm[0] !== 'true';
    }

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
        $needsSerializedName = false;
        $needsGroups = false;
        $needsIgnore = false;
        foreach ($properties as $property) {
            $param = $this->resolveSymfonyParam($property, $namespace);
            if ($param['serializedName'] !== null) {
                $needsSerializedName = true;
            }
            foreach ($param['attributes'] as $attribute) {
                if (str_contains($attribute, 'Groups(')) {
                    $needsGroups = true;
                }
                if ($attribute === '#[Ignore]') {
                    $needsIgnore = true;
                }
            }
            // Every optional property carries a presence flag plus an #[Ignore]d accessor for it,
            // and a temporal property carries an #[Ignore]d companion returning the object — so the
            // attribute is needed as soon as either exists.
            if ($param['required'] !== true || $param['temporalGetterBody'] !== null) {
                $needsIgnore = true;
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

        $itemKeywordsCovered = [];
        foreach ($properties as $property) {
            $covered = $this->symfonyItemCoveredKeywords($property);
            if ($covered !== []) {
                $itemKeywordsCovered[$property['openApiName']] = $covered;
            }
        }

        $validationConstraints = $this->filterInterpreterConstraints(
            constraints: $this->extractValidationConstraints($schemaDefinition),
            allowScalarKeywords: false,
            forceScalarOnProperties: $forceRootCallbackBounds,
            itemKeywordsCoveredByProperty: $itemKeywordsCovered,
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

        $validationConstraints = $this->pruneConstraintsCoveredByPhpType($validationConstraints, $params);

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
        // would be a method nobody calls.
        if (
            $validationParts['methods'] === ''
            && $params !== []
            && array_key_exists($className, $this->symfonyClassesReadByACallback())
        ) {
            $validationParts['methods'] = $this->renderSymfonyStandalonePayloadMethod($params);
        }

        $useStatements[] = 'Symfony\Component\Validator\Constraints as Assert';
        if (str_contains($validationParts['methods'], 'ExecutionContextInterface')) {
            $useStatements[] = 'Symfony\Component\Validator\Context\ExecutionContextInterface';
        }
        foreach ($validationParts['imports'] as $validationImport) {
            $useStatements[] = $validationImport;
        }
        if ($needsSerializedName) {
            $useStatements[] = 'Symfony\Component\Serializer\Attribute\SerializedName';
        }
        if ($needsGroups) {
            $useStatements[] = 'Symfony\Component\Serializer\Attribute\Groups';
        }
        if ($needsIgnore) {
            $useStatements[] = 'Symfony\Component\Serializer\Attribute\Ignore';
        }
        $implementedInterfaces = [];
        foreach ($this->symfonyImplementedUnionInterfaces($className, $extends) as $unionInterface) {
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
        $itemKeywordsCovered = [];
        foreach ($this->getSchemaProperties($className) as $property) {
            if ($this->shouldForceRootCallbackForProperty($property)) {
                $forceRootCallbackBounds[$property['openApiName']] = true;
            }
            $covered = $this->symfonyItemCoveredKeywords($property);
            if ($covered !== []) {
                $itemKeywordsCovered[$property['openApiName']] = $covered;
            }
        }

        // `false` is the default, and it is spelled out on purpose: it is the contrasting half of
        // the argument below. Scalar keywords are OFF everywhere and ON for exactly these
        // properties — dropping the `false` leaves the reader to guess what is being overridden.
        return $this->filterInterpreterConstraints(
            constraints: $this->extractValidationConstraints($schemaDefinition),
            allowScalarKeywords: false,
            forceScalarOnProperties: $forceRootCallbackBounds,
            itemKeywordsCoveredByProperty: $itemKeywordsCovered,
        );
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
            unset($propertySchema['enum'], $propertySchema['type']);

            return $propertySchema;
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
     */
    private function renderSymfonyUnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
    ): string {
        $imports = [];
        $memberNames = $unionTypes !== [] ? $unionTypes : $this->symfonyDiscriminatorMembers($className);
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
            $imports[] = 'Symfony\Component\Serializer\Attribute\DiscriminatorMap';
            $discriminatorMap = sprintf(
                "#[DiscriminatorMap(typeProperty: '%s', mapping: [%s])]",
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
                    fn(string $member): string => $this->formatClassNameForNamespace($member, $namespace),
                    $memberNames,
                )),
            'interfaceExtends' => $interfaceExtends,
            'discriminatorMap' => $discriminatorMap,
        ]);
    }

    /**
     * Classes a discriminated base maps to (its members are linked by the discriminator mapping,
     * not by a oneOf/anyOf member list).
     *
     * @return array<int, string>
     */
    private function symfonyDiscriminatorMembers(string $className): array
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
     * Union interfaces this class implements: the oneOf/anyOf schemas listing it as a member, plus
     * any discriminated base it is mapped to (Symfony DTOs are flattened, so the base cannot be a
     * parent class).
     *
     * @return array<int, string>
     */
    private function symfonyImplementedUnionInterfaces(string $className, ?string $extends): array
    {
        $interfaces = $this->unionInterfacesByClass[$className] ?? [];

        $base = $extends;
        $guard = 0;
        while ($base !== null && $guard++ < 10) {
            if ($this->isOneOfDiscriminatorBase($base)) {
                $interfaces[] = $base;
            }
            $base = $this->discriminatorBaseForMember($base);
        }

        return array_values(array_unique(array_filter(
            $interfaces,
            static fn(string $interface): bool => $interface !== $className,
        )));
    }

    /**
     * Keywords that grant permission rather than assert anything. They survive the filter so the
     * callback knows a null is allowed, but they cannot make a schema worth entering.
     *
     * @var array<int, string>
     */
    private const array SYMFONY_ANNOTATION_KEYWORDS = ['nullable'];

    /**
     * Returns the full, flattened property list for a Symfony DTO: inherited properties (resolved
     * recursively through allOf parents) followed by own ones, deduplicated by name so a child
     * override wins. Falls back to the pre-resolved own properties when the schema is not
     * registered (e.g. a union marker).
     *
     * @param array<int, SchemaProperty> $ownProperties
     * @return array<int, SchemaProperty>
     */
    private function flattenedSymfonyProperties(string $className, array $ownProperties): array
    {
        $all = array_key_exists($className, $this->dtoSchemas)
            ? $this->getSchemaProperties($className)
            : $ownProperties;

        $byName = [];
        foreach ($all as $property) {
            $byName[$property['name']] = $property;
        }

        $values = array_values($byName);

        // Required params (which get no default) must precede optional ones (which get a default),
        // otherwise PHP emits an "optional before required" deprecation and construction by the
        // required args alone fails. usort is stable on PHP 8.3, so schema order is otherwise kept.
        usort(
            $values,
            static fn(array $a, array $b): int => ($b['required'] ? 1 : 0) <=> ($a['required'] ? 1 : 0),
        );

        return $values;
    }

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
     *     temporalDefault: ?string,
     *     temporalGetterBody: ?string,
     *     temporalGetterReturnType: string,
     *     temporalGetterDocType: ?string,
     *     temporalObjectDocType: ?string,
     *     temporalObjectGetter: string,
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
        // setter is what records that the payload carried the key — see the class docblock.
        $declaredNullable = $property['nullable'] || (!$required && $default === null);
        $declaredType = $this->composePhpTypeHint($phpType, $declaredNullable);

        // A temporal default is `new DateTimeImmutable(...)`, and PHP forbids `new` in a PROPERTY
        // initialiser — which is what an optional property here is, since the serializer fills it
        // through the setter rather than the constructor. The constructor BODY is the one place that
        // can hold the expression, so the property is left uninitialised and assigned there.
        $temporalDefault = null;
        if ($required) {
            $defaultLiteral = '';
        } elseif ($default !== null && $this->symfonyPropertyIsTemporalScalar($property)) {
            $expression = ltrim($this->renderDefaultValue($default, $phpType, $declaredType), ' =');
            $temporalDefault = $expression === '' ? null : $expression;
            $defaultLiteral = $temporalDefault === null ? ' = null' : '';
        } elseif ($default !== null) {
            $defaultLiteral = $this->renderDefaultValue($default, $phpType, $declaredType);
        } else {
            $defaultLiteral = ' = null';
        }

        return [
            'declaredType' => $declaredType,
            'docType' => $docType !== null ? $this->composePhpTypeHint($docType, $declaredNullable) : null,
            'name' => $property['name'],
            'required' => $required,
            'serializedName' => $property['name'] !== $property['openApiName'] ? $property['openApiName'] : null,
            'default' => $defaultLiteral,
            'attributes' => $this->resolveSymfonyAttributes($property),
            'docDescription' => $this->resolveSymfonyDocDescription($property),
            'getter' => 'get' . ucfirst($property['name']),
            // A temporal property is stored as DateTimeImmutable but READ as the string the schema
            // asks for: `format: date` must not grow a time part, and a date-time must keep the
            // sub-second precision the payload had. Symfony's DateTimeNormalizer has one fixed
            // pattern and can express neither, so the getter formats and an #[Ignore]d companion
            // hands out the object.
            'temporalGetterBody' => $this->symfonyTemporalGetterBody($property)
                ?? $this->symfonyTemporalArrayGetterBody($property),
            // A temporal SCALAR reads as a string; a temporal ARRAY stays an array and its ITEMS
            // become the strings, so only the docblock changes there.
            'temporalGetterReturnType' => str_replace('DateTimeImmutable', 'string', $declaredType),
            'temporalGetterDocType' => $docType !== null
                ? str_replace('DateTimeImmutable', 'string', $this->composePhpTypeHint($docType, $declaredNullable))
                : null,
            'temporalObjectDocType' => $docType !== null
                ? $this->composePhpTypeHint($docType, $declaredNullable)
                : null,
            'temporalDefault' => $temporalDefault,
            'temporalObjectGetter' => 'get' . ucfirst($property['name']) . 'AsDateTime',
            'setter' => 'set' . ucfirst($property['name']),
            'providedFlag' => $property['name'] . 'Provided',
            'providedGetter' => 'is' . ucfirst($property['name']) . 'Provided',
        ];
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
     * The body of a temporal getter, or null when the property is not a date/date-time.
     *
     * @param SchemaProperty $property
     */
    private function symfonyTemporalGetterBody(array $property): ?string
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

    /**
     * The body of a temporal ARRAY/MAP getter, or null when the items are not date/date-time.
     *
     * `items: {format: date}` types the item as DateTimeImmutable exactly like the scalar case, and
     * the reader is owed the same string. Symfony's DateTimeNormalizer would print every item RFC
     * 3339 — a `date` array leaving as date-times contradicts the schema it came from.
     *
     * @param SchemaProperty $property
     */
    private function symfonyTemporalArrayGetterBody(array $property): ?string
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
     * The `description` / `example` / `deprecated` annotations as one `@param` suffix. Promoted
     * constructor properties cannot carry their own docblock (and PHP's `#[\Deprecated]` does not
     * apply to properties), so the constructor docblock is where this metadata can live — the same
     * text the runtime mode puts on the getter.
     *
     * @param SchemaProperty $property
     */
    private function resolveSymfonyDocDescription(array $property): ?string
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
     * Maps OpenAPI constraints to Symfony Validator attribute lines. Covers the common scalar,
     * string, numeric and array constraints plus cascade validation. Complex schema keywords that
     * need cross-field logic are handled by the class-level callback block emitted separately.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function resolveSymfonyAttributes(array $property): array
    {
        $constraints = $this->foldScalarAllOfConstraints(
            is_array($property['constraints'] ?? null) ? $property['constraints'] : [],
        );
        $attributes = [];

        if ($this->propertyIsRequiredOnInput($property) && !$property['nullable']) {
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
        if (is_int($countMin)) {
            $count[] = 'min: ' . $countMin;
        }
        if (is_int($countMax)) {
            $count[] = 'max: ' . $countMax;
        }
        if ($count !== []) {
            $attributes[] = '#[Assert\Count(' . implode(', ', $count) . ')]';
        }

        if (
            ($constraints['uniqueItems'] ?? null) === true
            && !$this->symfonyPropertyCascades($property)
            && !$this->requiresCallbackUniqueItems($constraints['items'] ?? null)
        ) {
            $attributes[] = '#[Assert\Unique]';
        }

        // Typed map values (additionalProperties: { schema }) — validate every value via All.
        //
        // Unless the value is itself a CONTAINER. There `valueConstraintExpressions()` has nothing to
        // say but `new Assert\Type('array')`, and the interpreter already asserts exactly that from
        // `additionalProperties.type` — measured, a map of lists given `{"a":"nope"}` came back with
        // both `field "byKey".a must be of type array` and `This value should be of type array.` The
        // list spelling of the same schema emits no attribute at all, so this also makes the two agree.
        $additionalProperties = $constraints['additionalProperties'] ?? null;
        if (is_array($additionalProperties) && $this->symfonySchemaIsContainer($additionalProperties)) {
            $additionalProperties = null;
        }
        if (is_array($additionalProperties)) {
            $mapValueTypeExpression = $this->symfonyPreferredTypeForMapValue($property['type']);
            $valueExpressions = $this->valueConstraintExpressions(
                schema: $additionalProperties,
                preferredPhpTypeExpression: $mapValueTypeExpression,
                skipScalarConstraintSpecs: $this->shouldSkipSymfonyScalarSpecsForPreferredType($mapValueTypeExpression),
                // A CLASS still gets its `Assert\Type` — nothing else can say "this is that enum".
                // A SCALAR does not: `Assert\Type('int')` refuses `42.0`, which JSON Schema 2020-12
                // §6.1.1 calls an integer and the callback accepts. The LIST spelling of the same
                // schema always left `type` to the callback and answered correctly — measured, a map
                // refused `{"a":42.0}` while a list accepted `[42.0]`. One schema, one answer now;
                // `symfonyItemCoveredKeywords()` stops claiming `type` in step.
                assertType: $mapValueTypeExpression !== null,
            );
            if ($valueExpressions !== []) {
                $attributes[] = '#[Assert\All([' . implode(', ', $valueExpressions) . '])]';
            }
        }

        // anyOf — the value must satisfy at least one branch.
        $anyOf = $constraints['anyOf'] ?? null;
        if (is_array($anyOf) && count($anyOf) >= 2) {
            $branches = [];
            $allBranchesValidatable = true;
            foreach ($anyOf as $branch) {
                $expressions = is_array($branch) ? $this->valueConstraintExpressions($branch) : [];
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

        // Per-item constraints for arrays of scalars (array of DTOs cascades via Valid instead).
        $items = $constraints['items'] ?? null;
        if (is_array($items) && !$this->symfonyPropertyCascades($property)) {
            $itemTypeExpression = $this->symfonyPreferredTypeForArrayItem($property['type']);
            if ($this->shouldSkipSymfonyScalarSpecsForPreferredType($itemTypeExpression)) {
                $attributes[] = '#[Assert\All([new Assert\Type(' . $itemTypeExpression . ')])]';
            } else {
                $itemSpecs = $this->scalarConstraintSpecs($items);
                if ($itemSpecs !== []) {
                    $expressions = array_map(
                        static fn(array $spec): string => 'new Assert\\' . $spec['name'] . '(' . $spec['args'] . ')',
                        $itemSpecs,
                    );
                    $attributes[] = '#[Assert\All([' . implode(', ', $expressions) . '])]';
                }
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
                $attributes[] = '#[Ignore]';
            } else {
                $groups = [];
                if ($readable) {
                    $groups[] = "'read'";
                }
                if ($writable) {
                    $groups[] = "'write'";
                }
                $attributes[] = '#[Groups([' . implode(', ', $groups) . '])]';
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

    /**
     * The keywords a property's `#[Assert\All]` already enforces on its ITEMS or its MAP VALUES.
     *
     * The callback drops exactly these, and nothing more — the same contract
     * `filterInterpreterConstraints()` states for the property level ("supported scalar / count
     * / regex constraints are intentionally removed here so the callback does not duplicate
     * attribute-based violations") and did not keep one level down: the `items` recursion handed the
     * callback every scalar keyword while `Assert\All` was enforcing them too. Measured before this:
     * `{"flatMap":{"a":"x"}}` came back with THREE messages for one mistake, and every bound with two.
     *
     * The branches below mirror `resolveSymfonyAttributes()` exactly, because a keyword dropped here
     * that no attribute actually emits is a check lost:
     *
     *  - an array of DTOs cascades through `#[Assert\Valid]` and emits no `All`, so nothing is covered;
     *  - an item typed as an enum or a date emits `All([Type(X::class)])` INSTEAD of the scalar specs;
     *  - a map value that is itself a container emits nothing at all (see `symfonySchemaIsContainer()`);
     *  - `type` is never covered — no spec asserts it, so the callback keeps it at every level.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function symfonyItemCoveredKeywords(array $property): array
    {
        $constraints = $this->extractValidationConstraints($property['constraints'] ?? []);

        $items = $constraints['items'] ?? null;
        if (is_array($items) && !$this->symfonyPropertyCascades($property)) {
            $itemTypeExpression = $this->symfonyPreferredTypeForArrayItem($property['type']);
            if ($this->shouldSkipSymfonyScalarSpecsForPreferredType($itemTypeExpression)) {
                return [];
            }

            return $this->scalarConstraintSpecList($items)['covered'];
        }

        $additionalProperties = $constraints['additionalProperties'] ?? null;
        if (is_array($additionalProperties) && !$this->symfonySchemaIsContainer($additionalProperties)) {
            $mapValueTypeExpression = $this->symfonyPreferredTypeForMapValue($property['type']);
            if ($this->shouldSkipSymfonyScalarSpecsForPreferredType($mapValueTypeExpression)) {
                return [];
            }

            // `type` is deliberately absent, exactly as in the list branch above: no attribute
            // asserts it for a scalar map value any more, so the callback owns it — and the callback
            // is the one that reads `42.0` as the integer the spec calls it.
            return $this->scalarConstraintSpecList($additionalProperties)['covered'];
        }

        return [];
    }

    /**
     * Whether a subschema describes a CONTAINER — an array, or an object used as a map.
     *
     * @param array<string, mixed> $schema
     */
    private function symfonySchemaIsContainer(array $schema): bool
    {
        return in_array($schema['type'] ?? null, ['array', 'object'], true)
            || is_array($schema['items'] ?? null)
            || is_array($schema['additionalProperties'] ?? null);
    }

    private function shouldSkipSymfonyScalarSpecsForPreferredType(?string $preferredTypeExpression): bool
    {
        if ($preferredTypeExpression === null) {
            return false;
        }

        return str_ends_with($preferredTypeExpression, '::class');
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
    private function scalarConstraintSpecs(array $constraints): array
    {
        return $this->scalarConstraintSpecList($constraints)['specs'];
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
    private function scalarConstraintSpecList(array $constraints): array
    {
        $specs = [];
        $covered = [];

        $length = [];
        if (is_int($constraints['minLength'] ?? null)) {
            $length[] = 'min: ' . $constraints['minLength'];
            $covered[] = 'minLength';
        }
        if (is_int($constraints['maxLength'] ?? null)) {
            $length[] = 'max: ' . $constraints['maxLength'];
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
            && $this->canUseSymfonyRegexConstraint($constraints['pattern'])
        ) {
            $delimited = '/' . str_replace('/', '\/', $constraints['pattern']) . '/';
            $specs[] = ['name' => 'Regex', 'args' => $this->phpStringLiteral($delimited)];
            $covered[] = 'pattern';
        }

        if (array_key_exists('const', $constraints) && $this->isScalarConstValue($constraints['const'])) {
            $specs[] = ['name' => 'EqualTo', 'args' => 'value: ' . $this->scalarLiteral($constraints['const'])];
            $covered[] = 'const';
        }

        if (is_array($constraints['enum'] ?? null) && $constraints['enum'] !== []) {
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

        $hasRange = $range !== [];
        $formatSpecs = $this->formatConstraintSpecs($constraints['format'] ?? null);
        foreach ($formatSpecs as $spec) {
            // An explicit minimum/maximum Range already covers (and is tighter than) the format's
            // implicit int32 bounds — avoid emitting a redundant second Range.
            if ($spec['name'] === 'Range' && $hasRange) {
                continue;
            }
            $specs[] = $spec;
        }
        // `formatConstraintSpecs()` answers for a HANDFUL of formats and skips the rest, so the
        // keyword counts as covered only when it actually produced something. A `format: date` or an
        // unmapped one is still the callback's to enforce.
        if ($formatSpecs !== []) {
            $covered[] = 'format';
        }

        return ['specs' => $specs, 'covered' => array_values(array_unique($covered))];
    }

    /**
     * Maps an OpenAPI `format` to Symfony format constraints. Formats without a clean Symfony
     * equivalent (date/date-time are covered by the DateTimeImmutable type; duration, etc.) are skipped.
     *
     * @return array<int, array{name: string, args: string}>
     */
    private function formatConstraintSpecs(mixed $format): array
    {
        if (!is_string($format)) {
            return [];
        }

        return match ($format) {
            'email', 'idn-email' => [['name' => 'Email', 'args' => '']],
            'uuid' => [['name' => 'Uuid', 'args' => '']],
            'url' => [['name' => 'Url', 'args' => '']],
            'hostname' => [['name' => 'Hostname', 'args' => '']],
            'ipv4' => [['name' => 'Ip', 'args' => "version: '4'"]],
            'ipv6' => [['name' => 'Ip', 'args' => "version: '6'"]],
            'int32' => [['name' => 'Range', 'args' => 'min: -2147483648, max: 2147483647']],
            'uint32' => [['name' => 'Range', 'args' => 'min: 0, max: 4294967295']],
            default => [],
        };
    }

    /**
     * Builds `new Assert\*(...)` expressions enforcing a (sub)schema on a value that has no own PHP
     * type hint — array/map items and anyOf branches. Includes a Type constraint (the element type
     * cannot be expressed in the declared `array` hint) plus the scalar constraint specs.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function valueConstraintExpressions(
        array $schema,
        ?string $preferredPhpTypeExpression = null,
        bool $skipScalarConstraintSpecs = false,
        bool $assertType = true,
    ): array {
        $expressions = [];

        if ($preferredPhpTypeExpression !== null) {
            $expressions[] = 'new Assert\Type(' . $preferredPhpTypeExpression . ')';
        } elseif ($assertType) {
            $symfonyType = $this->openApiTypeToSymfonyType($schema['type'] ?? null);
            if ($symfonyType !== null) {
                $expressions[] = "new Assert\\Type('" . $symfonyType . "')";
            }
        }

        if (!$skipScalarConstraintSpecs) {
            foreach ($this->scalarConstraintSpecs($schema) as $spec) {
                $expressions[] = 'new Assert\\' . $spec['name'] . '(' . $spec['args'] . ')';
            }
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

    private function isScalarConstValue(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }

    private function scalarLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return $this->numericLiteral($value);
        }

        return $this->phpStringLiteral(is_string($value) ? $value : (string)$value);
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
     * @param SchemaProperty $property
     */
    private function symfonyPropertyCascades(array $property): bool
    {
        $type = $property['type'];
        if (preg_match('/^array<(.+)>$/', $type, $matches) === 1) {
            $type = $matches[1];
        }
        $type = ltrim($type, '?');
        $shortName = $this->shortClassName($type);

        return array_key_exists($shortName, $this->dtoSchemas);
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
        if (!is_int($constraints['minProperties'] ?? null) && !is_int($constraints['maxProperties'] ?? null)) {
            return false;
        }
        if (!$this->symfonyPropertyCascades($property)) {
            return false;
        }

        return preg_match('/^array<.+>$/', $property['type']) !== 1;
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
        if (is_array($constraints['oneOf'] ?? null)) {
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

        if (
            ($constraints['uniqueItems'] ?? null) === true
            && (
                $this->symfonyPropertyCascades($property)
                || $this->requiresCallbackUniqueItems($constraints['items'] ?? null)
            )
        ) {
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
            && !$this->canUseSymfonyRegexConstraint($constraints['pattern']);
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
            $format,
            [
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
            true,
        );
    }

    private function requiresCallbackUniqueItems(mixed $itemsSchema): bool
    {
        if (!is_array($itemsSchema)) {
            return false;
        }

        if (
            array_key_exists('$ref', $itemsSchema)
            || array_key_exists('oneOf', $itemsSchema)
            || array_key_exists('anyOf', $itemsSchema)
            || array_key_exists('allOf', $itemsSchema)
            || array_key_exists('properties', $itemsSchema)
            || array_key_exists('additionalProperties', $itemsSchema)
        ) {
            return true;
        }

        $type = $itemsSchema['type'] ?? null;
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (!is_string($candidate) || !in_array($candidate, ['string', 'integer', 'number', 'boolean', 'null'], true)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($type)) {
            return true;
        }

        return !in_array($type, ['string', 'integer', 'number', 'boolean', 'null'], true);
    }

    private function canUseSymfonyRegexConstraint(string $pattern): bool
    {
        $regex = '#' . str_replace('#', '\#', $pattern) . '#u';

        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function numericLiteral(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        $rendered = json_encode($value);

        return is_string($rendered) ? $rendered : (string)$value;
    }
}
