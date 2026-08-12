<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use RuntimeException;

/**
 * Runtime-mode rendering: DTOs implementing `GeneratedDtoInterface` with the constraint /
 * normalization / presence-tracking metadata consumed by this library's own services.
 *
 * Extracted from GenerateDtoCommand as a trait for the same reason as {@see RendersSymfonyDto}.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 * @phpstan-import-type SchemaMetadata from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 *
 * @phpstan-ignore trait.unused
 */
trait RendersRuntimeDto
{
    /**
     * Builds the property → allowEmptyValue map emitted as getParameterAllowEmptyValue().
     *
     * Only parameters whose spec states the keyword are listed: `true` allows `?flag=`,
     * `false` forbids it, and an absent entry means the spec is silent (legacy behaviour).
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, allowEmptyValue: bool}>
     */
    private function resolveParameterAllowEmptyValueAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            if (($property['inQuery'] ?? false) !== true) {
                continue;
            }

            $allowEmptyValue = $property['allowEmptyValue'] ?? null;
            if (!is_bool($allowEmptyValue)) {
                continue;
            }

            $result[] = [
                'name' => $property['name'],
                'allowEmptyValue' => $allowEmptyValue,
            ];
        }

        return $result;
    }

    /**
     * Renders a DTO in runtime mode: the class implements `GeneratedDtoInterface` and carries the
     * metadata this library's validator / normalizer / deserializer consume.
     *
     * @param SchemaMetadata $schemaMetadata
     */
    private function renderRuntimeDtoClass(string $namespace, string $className, array $schemaMetadata): string
    {
        $properties = $schemaMetadata['properties'];
        $extends = $schemaMetadata['extends'];
        $unionTypes = $schemaMetadata['unionTypes'];
        $discriminator = $schemaMetadata['discriminator'] ?? null;
        $isAbstract = $schemaMetadata['abstract'] ?? false;

        // A child (allOf/extends) class re-declares its parent's properties as constructor
        // parameters, so imports must cover their types too (e.g. an inherited DateTimeImmutable
        // param). Scan own + parent properties — the same parent set the constructor emits.
        $importScanProperties = $extends !== null
            ? array_merge($this->getParentProperties($extends), $properties)
            : $properties;

        $imports = $this->collectGeneratedClassImports(
            namespace: $namespace,
            className: $className,
            properties: $importScanProperties,
            extends: $extends,
            unionTypes: $unionTypes,
            discriminator: $discriminator,
        );

        $useStatements = [];

        $needsDateTimeImport = $this->needsDateTimeImmutableImport($importScanProperties);
        $needsUploadedFileImport = $this->needsUploadedFileImport($importScanProperties);

        if ($needsDateTimeImport) {
            $useStatements[] = 'DateTimeImmutable';
        }
        if ($needsUploadedFileImport) {
            $useStatements[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
        }
        foreach ($imports as $import) {
            $useStatements[] = $import;
        }
        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        if ($unionTypes !== []) {
            // When this union is itself a branch of another union (a nested union), its interface
            // must extend the outer union interface so that its members satisfy the outer type too.
            $interfaceExtends = array_values(array_unique(array_map(
                fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
                array_filter(
                    $this->unionInterfacesByClass[$className] ?? [],
                    static fn(string $parent): bool => $parent !== $className,
                ),
            )));

            return $this->renderPhpTemplate('dto.php.twig', [
                'namespace' => $namespace,
                'imports' => $useStatements,
                'className' => $className,
                'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
                'sourceSpecLink' => $this->resolveSpecLink($className),
                'sourceRelated' => $this->relatedByClass[$className] ?? null,
                'unionMembers' => implode(
                    '|',
                    array_map(
                        fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
                        $unionTypes,
                    ),
                ),
                'interfaceExtends' => $interfaceExtends,
                'signature' => null,
                'implementedInterfaces' => [],
                'privateProperties' => [],
                'constructorParams' => [],
                'parentArgs' => [],
                'assignments' => [],
                'methodProperties' => [],
                'discriminator' => null,
                'extends' => null,
                'constraintAssignments' => [],
                'aliasAssignments' => [],
                'parameterSourceAssignments' => [],
            ]);
        }

        $classModifiers = $isAbstract
            ? 'abstract '
            : (array_key_exists($className, $this->parentClasses) ? '' : 'final ');
        $fqcnNamespace = implode('\\', array_slice(explode('\\', $this->generatedDtoInterfaceImportFqcn), 0, -1));
        if ($fqcnNamespace !== $namespace) {
            $useStatements[] = $this->generatedDtoInterfaceImportFqcn;
        }
        $useStatements[] = 'JsonException';
        $useStatements[] = 'Stringable';
        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        $implementedInterfaces = array_values(array_unique([
            ...($this->unionInterfacesByClass[$className] ?? []),
            'GeneratedDtoInterface',
            'Stringable',
        ]));
        $implementedInterfaces = array_map(
            fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
            $implementedInterfaces,
        );

        $signature = $classModifiers . 'class ' . $className;
        if ($extends !== null) {
            $signature .= ' extends ' . $this->formatClassNameForNamespace($extends, $namespace);
        }

        $ownProperties = $this->deduplicatePropertiesByLastDefinition($properties);
        $parentProperties = $extends !== null
            ? $this->deduplicatePropertiesByLastDefinition($this->getParentProperties($extends))
            : [];

        $parentByName = $this->indexPropertiesByName($parentProperties);
        $ownByName = $this->indexPropertiesByName($ownProperties);

        foreach ($ownByName as $name => $ownProperty) {
            if (!array_key_exists($name, $parentByName)) {
                continue;
            }

            if (!$this->isPropertyOverrideCompatible($parentByName[$name], $ownProperty)) {
                throw new RuntimeException(
                    sprintf(
                        'Property override conflict in %s extends %s for $%s: parent type %s, child type %s.',
                        $className,
                        (string)$extends,
                        $name,
                        $this->describePropertyType($parentByName[$name]),
                        $this->describePropertyType($ownProperty),
                    ),
                );
            }
        }

        $privateProperties = [];
        foreach ($ownProperties as $ownProperty) {
            if (array_key_exists($ownProperty['name'], $parentByName)) {
                continue;
            }

            $privateProperties[] = $this->resolvePropertyDeclarationData($ownProperty, $namespace);
        }

        $allConstructorParams = [];

        if ($extends !== null) {
            foreach ($parentProperties as $parentProperty) {
                $effectiveProperty = $ownByName[$parentProperty['name']] ?? $parentProperty;
                $allConstructorParams[] = $effectiveProperty;
            }
        }

        foreach ($ownProperties as $ownProperty) {
            if (array_key_exists($ownProperty['name'], $parentByName)) {
                continue;
            }
            $allConstructorParams[] = $ownProperty;
        }

        $constructorParams = [];
        foreach ($allConstructorParams as $property) {
            $tracksArgPresence = !array_key_exists($property['name'], $parentByName);
            $constructorParams[] = $this->resolveConstructorParameterData($property, $namespace, $tracksArgPresence);
        }
        $requiredConstructorParams = [];
        $optionalConstructorParams = [];

        foreach ($constructorParams as $constructorParam) {
            if ($constructorParam['defaultValue'] === '' && !$constructorParam['usesUnsetSentinel']) {
                $requiredConstructorParams[] = $constructorParam;
                continue;
            }

            $optionalConstructorParams[] = $constructorParam;
        }

        $constructorParams = [...$requiredConstructorParams, ...$optionalConstructorParams];
        $constructorDocParams = array_values(
            array_filter(
                $constructorParams,
                static fn(array $param): bool => $param['shouldDocument'],
            ),
        );

        $parentArgs = [];
        if ($extends !== null && $parentProperties !== []) {
            foreach ($parentProperties as $parentProperty) {
                $parentArgs[] = $parentProperty['name'];
            }
        }

        $assignments = [];
        foreach ($ownProperties as $ownProperty) {
            if (array_key_exists($ownProperty['name'], $parentByName)) {
                continue;
            }
            $assignments[] = $ownProperty['name'];
        }

        $allProperties = [];
        $methodProperties = [];
        foreach ($ownProperties as $property) {
            if (array_key_exists($property['name'], $parentByName)) {
                continue;
            }
            $methodProperties[] = $this->resolveMethodPropertyData($property, $namespace);
        }

        $discriminatorData = $discriminator !== null
            ? $this->resolveDiscriminatorRenderData($discriminator, $namespace)
            : null;

        $constraintAssignments = $this->resolveConstraintAssignments($ownProperties);
        $aliasAssignments = $this->resolveAliasAssignments($ownProperties);
        $parameterSourceAssignments = $this->resolveParameterSourceAssignments($ownProperties);
        $parameterStyleAssignments = $this->resolveParameterStyleAssignments($ownProperties);
        $parameterAllowReservedAssignments = $this->resolveParameterAllowReservedAssignments($ownProperties);
        $parameterAllowEmptyValueAssignments = $this->resolveParameterAllowEmptyValueAssignments($ownProperties);

        $needsUnsetValueImport = array_filter(
            $constructorParams,
            static fn(array $param): bool => $param['usesUnsetSentinel'],
        ) !== [];
        if ($needsUnsetValueImport) {
            $useStatements[] = $this->unsetValueImportFqcn;
            $useStatements = array_values(array_unique($useStatements));
            sort($useStatements);
        }

        return $this->renderPhpTemplate('dto.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'unionMembers' => null,
            'signature' => $signature,
            'implementedInterfaces' => $implementedInterfaces,
            'privateProperties' => $privateProperties,
            'constructorParams' => $constructorParams,
            'constructorDocParams' => $constructorDocParams,
            'parentArgs' => $parentArgs,
            'assignments' => $assignments,
            'methodProperties' => $methodProperties,
            'discriminator' => $discriminatorData,
            'extends' => $extends,
            'constraintAssignments' => $constraintAssignments,
            'aliasAssignments' => $aliasAssignments,
            'parameterSourceAssignments' => $parameterSourceAssignments,
            'parameterStyleAssignments' => $parameterStyleAssignments,
            'parameterAllowReservedAssignments' => $parameterAllowReservedAssignments,
            'parameterAllowEmptyValueAssignments' => $parameterAllowEmptyValueAssignments,
        ]);
    }

    /**
     * Decides whether an optional property is modelled with the UnsetValue sentinel so it can be
     * omitted from the serialized payload. Body fields always use it — even when they declare a
     * default, which then only seeds the constructor value (`T|UnsetValue|null = default`), leaving
     * `UnsetValue::UNSET` available for explicit omission. Parameters (path/query/header/cookie)
     * with a default keep the args-only presence model instead (their presence is inferred by the
     * deserializer), so they are excluded here.
     *
     * @param SchemaProperty $property
     */
    private function propertyUsesUnsetSentinel(array $property): bool
    {
        if ($property['required']) {
            return false;
        }

        $isParameter = ($property['inPath'] ?? false) === true
            || ($property['inQuery'] ?? false) === true
            || ($property['inHeader'] ?? false) === true
            || ($property['inCookie'] ?? false) === true;

        if ($isParameter) {
            return $property['default'] === null;
        }

        return true;
    }

    /**
     * @param SchemaProperty $property
     * @return array{description: ?string, example: ?string, constraintsLine: ?string, docVarType: ?string, type: string, name: string, inRequestFlagName: string, inPathFlagName: string, inQueryFlagName: string, inHeaderFlagName: string, inCookieFlagName: string, isArray: bool, usesUnsetSentinel: bool}
     */
    private function resolvePropertyDeclarationData(array $property, string $namespace): array
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];
        $isArray = false;

        if (str_contains($phpType, '<')) {
            $phpDocType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
            $isArray = true;
        } elseif ($phpType === 'array' || $phpType === '?array') {
            // Direct array type (not generic)
            $isArray = true;
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
            $phpDocType = $this->formatDocblockTypeForNamespace($phpDocType, $namespace);
        }

        $type = $this->composePhpTypeHint($phpType, $property['nullable']);
        $description = $property['description'] ?? null;
        $example = $property['example'] ?? null;
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        $constraintsLine = $this->formatConstraintsForDocBlock($constraints);
        $docVarType = null;
        if ($phpType !== $phpDocType) {
            $docVarType = $this->composePhpTypeHint($phpDocType, $property['nullable']);
        }

        return [
            'description' => is_string($description) && $description !== '' ? $description : null,
            'example' => is_string($example) && $example !== '' ? $example : null,
            'constraintsLine' => $constraintsLine,
            'docVarType' => $docVarType,
            'type' => $type,
            'name' => $property['name'],
            'inRequestFlagName' => $this->normalizeInRequestFlagName($property['name']),
            'inPathFlagName' => $this->normalizeInPathFlagName($property['name']),
            'inQueryFlagName' => $this->normalizeInQueryFlagName($property['name']),
            'inHeaderFlagName' => $this->normalizeInHeaderFlagName($property['name']),
            'inCookieFlagName' => $this->normalizeInCookieFlagName($property['name']),
            'isArray' => $isArray,
            'usesUnsetSentinel' => $this->propertyUsesUnsetSentinel($property),
        ];
    }

    /**
     * @param SchemaProperty $property
     * @return array{
     *   type: string,
     *   name: string,
     *   defaultValue: string,
     *   isArray: bool,
     *   isPromoted: bool,
     *   docType: ?string,
     *   description: ?string,
     *   example: ?string,
     *   constraintsLine: ?string,
     *   shouldDocument: bool,
     *   tracksArgPresence: bool,
     *   inRequestFlagName: string,
     *   presenceFlagName: string,
     *   usesUnsetSentinel: bool,
     *   presenceFromArgsOnly: bool
     * }
     */
    private function resolveConstructorParameterData(array $property, string $namespace, bool $tracksArgPresence): array
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];
        $isArray = false;

        if (str_contains($phpType, '<')) {
            $phpDocType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
            $isArray = true;
        } elseif ($phpType === 'array' || $phpType === '?array') {
            // Direct array type (not generic)
            $isArray = true;
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
            $phpDocType = $this->formatDocblockTypeForNamespace($phpDocType, $namespace);
        }

        $type = $this->composePhpTypeHint($phpType, $property['nullable']);
        $defaultValue = $this->renderDefaultValue($property['default'], $phpType, $property['type']);

        // Optional, presence-tracked properties carry the UnsetValue sentinel so they can be
        // omitted. A declared default (if any) stays as the constructor default — the sentinel only
        // adds the ability to pass UnsetValue::UNSET explicitly. Inherited (parent) properties are
        // tracked by the parent, so they are excluded via $tracksArgPresence.
        $usesUnsetSentinel = $tracksArgPresence && $this->propertyUsesUnsetSentinel($property);
        if ($usesUnsetSentinel) {
            // Add union type with UnsetValue and null. Strip any existing nullability first
            // (leading ? or a null union member) so the result never has a duplicate null.
            // null is emitted last to satisfy the ordered_types code-style rule
            // (null_adjustment: always_last).
            $baseType = strpos($type, '?') === 0 ? substr($type, 1) : $type;
            $members = array_filter(
                explode('|', $baseType),
                static fn(string $member): bool => $member !== '' && $member !== 'null',
            );

            // `mixed` cannot take part in a union: `mixed|UnsetValue|null` is a COMPILE-TIME fatal, so an
            // optional property with no type in its schema (an empty schema, or one carrying only a
            // description) used to emit a class that could not be loaded at all. `mixed` already admits
            // the sentinel and null, so it stands alone and the presence tracking is unaffected — the
            // constructor default is still `UnsetValue::UNSET`.
            $type = in_array('mixed', $members, true)
                ? 'mixed'
                : implode('|', $members) . '|UnsetValue|null';

            // No explicit default → the sentinel itself is the constructor default.
            if ($defaultValue === '') {
                $defaultValue = ' = UnsetValue::UNSET';
            }
        } elseif (!$property['required'] && $defaultValue === '' && $property['nullable']) {
            $defaultValue = ' = null';
        }

        $description = $property['description'] ?? null;
        $example = $property['example'] ?? null;
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        $constraintsLine = $this->formatConstraintsForDocBlock($constraints);
        $docType = null;

        if ($phpType !== $phpDocType) {
            $docType = $this->composePhpTypeHint($phpDocType, $property['nullable']);
        }

        $normalizedDescription = is_string($description) && $description !== ''
            ? $this->stripDocAnnotationSentenceDot($description)
            : null;
        $normalizedExample = is_string($example) && $example !== ''
            ? $this->stripDocAnnotationSentenceDot($example)
            : null;
        $shouldDocument = $normalizedDescription !== null
            || $normalizedExample !== null
            || $constraintsLine !== null
            || $docType !== null;
        $presenceFlagName = $this->resolvePresenceFlagName($property);

        // An optional, default-valued parameter (path/query/header/cookie) cannot prove it
        // was "provided" from its constructor default, so its presence flag must start false
        // — the deserializer flips it on via reflection when the value really came in. Body
        // fields keep starting true so a hand-built DTO still serializes its default value.
        $isParameter = ($property['inPath'] ?? false) === true
            || ($property['inQuery'] ?? false) === true
            || ($property['inHeader'] ?? false) === true
            || ($property['inCookie'] ?? false) === true;
        $presenceFromArgsOnly = $tracksArgPresence
            && !$usesUnsetSentinel
            && $isParameter
            && !$property['required'];

        return [
            'type' => $type,
            'name' => $property['name'],
            'defaultValue' => $defaultValue,
            'isArray' => $isArray,
            'isPromoted' => !$isArray && $tracksArgPresence,
            'docType' => $docType,
            'description' => $normalizedDescription,
            'example' => $normalizedExample,
            'constraintsLine' => $constraintsLine,
            'shouldDocument' => $shouldDocument,
            'tracksArgPresence' => $tracksArgPresence,
            'inRequestFlagName' => $this->normalizeInRequestFlagName($property['name']),
            'presenceFlagName' => $presenceFlagName,
            'usesUnsetSentinel' => $usesUnsetSentinel,
            'presenceFromArgsOnly' => $presenceFromArgsOnly,
        ];
    }

    /**
     * @param SchemaProperty $property
     * @return array{name: string, openApiName: string, nameSuffix: string, methodName: string, returnType: string, hasGuard: bool, docDescriptionLines: array<int, string>, docReturnType: ?string, expectedFormat: ?string, returnKind: string, phpDateFormat: ?string, isNullableTemporal: bool, requiredLiteral: string, inPathFlagName: string, inQueryFlagName: string, inHeaderFlagName: string, inCookieFlagName: string, inRequestFlagName: string, presenceFlagName: string, isMap: bool, hasArrayAdder: bool, arrayAdderMethodName: string, arrayAdderItemType: string, nullableArray: bool, usesUnsetSentinel: bool, getterUsesSentinel: bool, hasObjectGetter: bool, objectGetterMethodName: string, objectGetterReturnType: string, isParameter: bool}
     */
    private function resolveMethodPropertyData(array $property, string $namespace): array
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];

        if (str_contains($phpType, '<')) {
            $phpDocType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
            $phpDocType = $this->formatDocblockTypeForNamespace($phpDocType, $namespace);
        }

        $type = $this->composePhpTypeHint($phpType, $property['nullable']);
        $methodName = 'get' . ucfirst($property['name']);
        $description = $property['description'] ?? null;
        $example = $property['example'] ?? null;
        $temporalFormat = $property['temporalFormat'] ?? null;

        $docDescriptionLines = [];
        if ($description !== null && $description !== '') {
            $docDescriptionLines[] = $description;
        }
        if (is_string($example) && $example !== '') {
            $docDescriptionLines[] = 'Example: ' . $example;
        }

        $docReturnType = null;
        $expectedFormat = null;
        $returnKind = 'direct';
        $returnType = $type;
        $phpDateFormat = null;
        $isNullableTemporal = false;
        $usesUnsetSentinel = $this->propertyUsesUnsetSentinel($property);
        $needsInRequestGuard = !$property['required']
            && !($property['inPath'] ?? false)
            && !($property['inQuery'] ?? false)
            && !($property['inHeader'] ?? false)
            && !($property['inCookie'] ?? false);

        if ($phpType === 'DateTimeImmutable' && $temporalFormat !== null) {
            $returnKind = 'temporal';
            $returnType = $property['nullable'] || $usesUnsetSentinel ? '?string' : 'string';
            $expectedFormat = $temporalFormat;
            $phpDateFormat = $temporalFormat === 'Y-m-d' ? 'Y-m-d' : 'c';
            $isNullableTemporal = $property['nullable'] || $usesUnsetSentinel;
        } elseif ($phpType !== $phpDocType) {
            $docReturnType = $this->composePhpTypeHint($phpDocType, $property['nullable']);
        }

        if ($usesUnsetSentinel) {
            $returnType = $this->ensureTypeAllowsNull($returnType);
            if (is_string($docReturnType)) {
                $docReturnType = $this->ensureTypeAllowsNull($docReturnType);
            }
        }

        // Array fields are stored in a dedicated `?array` property (the constructor maps the
        // UnsetValue sentinel to null), so their getter must NOT emit the sentinel guard —
        // the property is never UnsetValue at read time. Non-array sentinel getters still do.
        $getterUsesSentinel = $usesUnsetSentinel && $phpType !== 'array';

        // Scalar temporal fields expose a second getter that returns the underlying
        // DateTimeImmutable object (the primary getter returns a formatted string). The value
        // is already stored as DateTimeImmutable, so this just unwraps the sentinel/null.
        $hasObjectGetter = $returnKind === 'temporal';
        $objectGetterMethodName = $hasObjectGetter ? 'get' . ucfirst($property['name']) . 'AsDateTime' : '';
        $objectGetterReturnType = $isNullableTemporal ? '?DateTimeImmutable' : 'DateTimeImmutable';

        return [
            'name' => $property['name'],
            'openApiName' => $property['openApiName'],
            'nameSuffix' => ucfirst($property['name']),
            'methodName' => $methodName,
            'returnType' => $returnType,
            'hasGuard' => $needsInRequestGuard,
            'docDescriptionLines' => $docDescriptionLines,
            'docReturnType' => $docReturnType,
            'expectedFormat' => $expectedFormat,
            'returnKind' => $returnKind,
            'phpDateFormat' => $phpDateFormat,
            'isNullableTemporal' => $isNullableTemporal,
            'requiredLiteral' => $property['required'] ? 'true' : 'false',
            'inPathFlagName' => $this->normalizeInPathFlagName($property['name']),
            'inQueryFlagName' => $this->normalizeInQueryFlagName($property['name']),
            'inHeaderFlagName' => $this->normalizeInHeaderFlagName($property['name']),
            'inCookieFlagName' => $this->normalizeInCookieFlagName($property['name']),
            'inRequestFlagName' => $this->normalizeInRequestFlagName($property['name']),
            'presenceFlagName' => $this->resolvePresenceFlagName($property),
            // A map (array<string, V>) is keyed: its adder takes ($key, $item) and it serializes
            // as a JSON object. A list adder takes ($item) only.
            'isMap' => $property['isMap'] ?? false,
            // A LIST of maps must keep its items objects too, otherwise an empty item encodes as
            // [] while the very same map at property level encodes as {}.
            'itemsAreMaps' => $this->describesListOfMaps($docReturnType ?? $property['type']),
            'hasArrayAdder' => str_starts_with($property['type'], 'array'),
            'arrayAdderMethodName' => 'addItemTo' . ucfirst($property['name']),
            'arrayAdderItemType' => $this->resolveArrayItemPhpType($property['type']),
            'arrayAdderItemNullable' => str_starts_with($this->resolveArrayItemPhpType($property['type']), '?'),
            'nullableArray' => $property['nullable'],
            'usesUnsetSentinel' => $usesUnsetSentinel,
            'getterUsesSentinel' => $getterUsesSentinel,
            'hasObjectGetter' => $hasObjectGetter,
            'objectGetterMethodName' => $objectGetterMethodName,
            'objectGetterReturnType' => $objectGetterReturnType,
            'readOnly' => $property['readOnly'] ?? false,
            'writeOnly' => $property['writeOnly'] ?? false,
            'deprecated' => $property['deprecated'] ?? false,
            // A property bound to an OpenAPI parameter source (path/query/header/cookie)
            // is request transport, not response payload — excluded from serialization.
            'isParameter' => ($property['inPath'] ?? false)
                || ($property['inQuery'] ?? false)
                || ($property['inHeader'] ?? false)
                || ($property['inCookie'] ?? false),
        ];
    }

    /**
     * @param SchemaProperty $property
     */
    private function resolvePresenceFlagName(array $property): string
    {
        if (($property['inPath'] ?? false) === true) {
            return $this->normalizeInPathFlagName($property['name']);
        }

        if (($property['inQuery'] ?? false) === true) {
            return $this->normalizeInQueryFlagName($property['name']);
        }

        if (($property['inHeader'] ?? false) === true) {
            return $this->normalizeInHeaderFlagName($property['name']);
        }

        if (($property['inCookie'] ?? false) === true) {
            return $this->normalizeInCookieFlagName($property['name']);
        }

        return $this->normalizeInRequestFlagName($property['name']);
    }

    private function ensureTypeAllowsNull(string $type): string
    {
        // `mixed` already includes null, and saying so twice is a fatal: "Type mixed cannot be marked as
        // nullable since mixed already includes null". Same reason `composePhpTypeHint()` special-cases it.
        if ($type === 'mixed') {
            return 'mixed';
        }

        if (str_starts_with($type, '?') || str_contains($type, '|null')) {
            return $type;
        }

        if (str_contains($type, '|')) {
            return $type . '|null';
        }

        return '?' . $type;
    }

    /**
     * @param array{propertyName: string, mapping: array<string, string>} $discriminator
     * @return array{propertyName: string, mappingEntries: array<int, array{value: string, targetClass: string}>}
     */
    private function resolveDiscriminatorRenderData(array $discriminator, string $namespace): array
    {
        $mappingEntries = [];
        foreach ($discriminator['mapping'] as $value => $targetClass) {
            $mappingEntries[] = [
                'value' => $this->escapeSingleQuoted($value),
                'targetClass' => $this->formatClassNameForNamespace($targetClass, $namespace),
            ];
        }

        return [
            'propertyName' => $this->escapeSingleQuoted($discriminator['propertyName']),
            'mappingEntries' => $mappingEntries,
        ];
    }

    /**
     * Builds the property → request-source map emitted as getParameterSources().
     * Only properties bound to an explicit OpenAPI `in:` (path/query/header/cookie)
     * appear; body properties are omitted and fall back to the body waterfall.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, source: string}>
     */
    private function resolveParameterSourceAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            $source = match (true) {
                ($property['inPath'] ?? false) === true => 'path',
                ($property['inQuery'] ?? false) === true => 'query',
                ($property['inHeader'] ?? false) === true => 'header',
                ($property['inCookie'] ?? false) === true => 'cookie',
                ($property['inQueryString'] ?? false) === true => 'querystring',
                default => null,
            };

            if ($source === null) {
                continue;
            }

            $result[] = [
                'name' => $property['name'],
                'source' => $source,
            ];
        }

        return $result;
    }

    /**
     * Builds the property → {style, explode} map emitted as getParameterStyles().
     * Only parameter-bound properties (path/query/header/cookie) carry serialization
     * style; the deserializer uses it to split delimited array values.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, style: string, explode: string}>
     */
    private function resolveParameterStyleAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            // Body properties normally have no serialization style — unless an Encoding Object
            // gave them one (a JSON part, or a delimited form field), which the deserializer needs
            // just as much as it needs a query parameter's style.
            $isParameter = ($property['inPath'] ?? false) === true
                || ($property['inQuery'] ?? false) === true
                || ($property['inHeader'] ?? false) === true
                || ($property['inCookie'] ?? false) === true
                || ($property['inQueryString'] ?? false) === true;
            if (!$isParameter && !is_string($property['parameterStyle'] ?? null)) {
                continue;
            }

            $style = $property['parameterStyle'] ?? null;
            $explode = $property['parameterExplode'] ?? null;
            if (!is_string($style) || !is_bool($explode)) {
                continue;
            }

            $result[] = [
                'name' => $property['name'],
                'style' => $style,
                'explode' => $explode ? 'true' : 'false',
            ];
        }

        return $result;
    }

    /**
     * Builds the property → allowReserved map emitted as getParameterAllowReserved().
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, allowReserved: bool}>
     */
    private function resolveParameterAllowReservedAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            $isParameter = ($property['inQuery'] ?? false) === true;
            if (!$isParameter) {
                continue;
            }

            if (($property['allowReserved'] ?? false) !== true) {
                continue;
            }

            $result[] = [
                'name' => $property['name'],
                'allowReserved' => true,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, value: string}>
     */
    private function resolveConstraintAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            $constraints = $property['constraints'] ?? [];
            if ($constraints === []) {
                continue;
            }

            $result[] = [
                'name' => $property['name'],
                'value' => $this->renderPhpLiteral($constraints),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{name: string, openApiName: string}>
     */
    private function resolveAliasAssignments(array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            $result[] = [
                'name' => $property['name'],
                'openApiName' => $property['openApiName'],
            ];
        }

        return $result;
    }

    private function renderPhpLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            $escaped = $this->escapeSingleQuoted($value);
            return "'" . $escaped . "'";
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $itemLiteral = $this->renderPhpLiteral($item);
                if (is_string($key)) {
                    $escapedKey = $this->escapeSingleQuoted($key);
                    $items[] = "'" . $escapedKey . "' => " . $itemLiteral;
                    continue;
                }

                $items[] = $itemLiteral;
            }

            return '[' . implode(', ', $items) . ']';
        }

        return 'null';
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function formatConstraintsForDocBlock(array $constraints): ?string
    {
        if ($constraints === []) {
            return null;
        }

        $priority = [
            'minimum',
            'exclusiveMinimum',
            'maximum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
            'pattern',
            'format',
            'minItems',
            'maxItems',
            'uniqueItems',
            'contains',
            'minContains',
            'maxContains',
            'oneOf',
            'anyOf',
            'if',
            'then',
            'else',
        ];

        $parts = [];
        foreach ($priority as $key) {
            if (!array_key_exists($key, $constraints)) {
                continue;
            }

            $value = $constraints[$key];
            if (is_bool($value)) {
                $parts[] = $key . '=' . ($value ? 'true' : 'false');
                continue;
            }

            if (is_array($value)) {
                if (in_array($key, ['oneOf', 'anyOf'], true)) {
                    $formattedUnion = $this->formatUnionConstraintsForDocBlock($key, $value);
                    if ($formattedUnion !== null) {
                        $parts[] = $formattedUnion;
                    }
                    continue;
                }

                $parts[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                continue;
            }

            $parts[] = $key . '=' . (string)$value;
        }

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<int, mixed> $variants
     */
    private function formatUnionConstraintsForDocBlock(string $keyword, array $variants): ?string
    {
        $formattedVariants = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $variantText = $this->formatFlatConstraintsForDocBlock($variant);
            if ($variantText === null) {
                continue;
            }

            $formattedVariants[] = '(' . $variantText . ')';
        }

        if ($formattedVariants === []) {
            return null;
        }

        return $keyword . '=' . implode(' | ', $formattedVariants);
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function formatFlatConstraintsForDocBlock(array $constraints): ?string
    {
        $priority = [
            'type',
            'minimum',
            'exclusiveMinimum',
            'maximum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
            'pattern',
            'format',
            'minItems',
            'maxItems',
            'uniqueItems',
            'contains',
            'minContains',
            'maxContains',
            'if',
            'then',
            'else',
        ];

        $parts = [];
        foreach ($priority as $key) {
            if (!array_key_exists($key, $constraints)) {
                continue;
            }

            $value = $constraints[$key];
            if (is_bool($value)) {
                $parts[] = $key . '=' . ($value ? 'true' : 'false');
                continue;
            }

            if (is_array($value)) {
                $parts[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                continue;
            }

            $parts[] = $key . '=' . (string)$value;
        }

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * Whether a doc type describes a LIST whose items are maps — `array<array<string, V>>`, as
     * opposed to a map itself (`array<string, V>`) or a list of scalars (`array<V>`).
     */
    private function describesListOfMaps(?string $docType): bool
    {
        if ($docType === null || preg_match('/^\??(?:array|list)<(.*)>$/is', trim($docType), $matches) !== 1) {
            return false;
        }

        $inner = trim($matches[1]);

        // A depth-0 comma means this type is a map itself, not a list.
        $depth = 0;
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            $character = $inner[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                return false;
            }
        }

        // The item is a map when it is itself a generic carrying a key type.
        return preg_match('/^\??(?:array|list)<[^<>]*,/is', $inner) === 1;
    }

    private function resolveArrayItemPhpType(string $fullType): string
    {
        if (!str_starts_with($fullType, 'array<')) {
            return 'mixed';
        }

        $itemType = substr($fullType, 6, -1);
        if ($itemType === '') {
            return 'mixed';
        }

        // Map type `array<string, V>` — the value type is the part after the key prefix. Only a
        // comma at depth 0 separates key from value: `array<array<string, mixed>>` is a LIST of
        // maps, and splitting on its inner comma used to yield the unparsable type `mixed>`.
        $depth = 0;
        $length = strlen($itemType);
        for ($i = 0; $i < $length; $i++) {
            $character = $itemType[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $itemType = ltrim(substr($itemType, $i + 1));
                break;
            }
        }

        // A generic item type (a nested list or map) is still just `array` to PHP.
        return str_contains($itemType, '<') ? 'array' : $itemType;
    }

    /**
     * @param SchemaProperty $parentProperty
     * @param SchemaProperty $childProperty
     */
    private function isPropertyOverrideCompatible(array $parentProperty, array $childProperty): bool
    {
        if ($parentProperty['type'] !== $childProperty['type']) {
            return false;
        }

        if (!$parentProperty['nullable'] && $childProperty['nullable']) {
            return false;
        }

        return true;
    }

    /**
     * Removes the trailing period from a single-sentence PHPDoc tag description so the
     * generated @param line is already a fixed point of the php-cs-fixer
     * phpdoc_annotation_without_dot rule (which strips that dot otherwise). Multi-sentence
     * text (any internal period) is left untouched — the rule does not act on it either.
     */
    private function stripDocAnnotationSentenceDot(string $text): string
    {
        if (substr_count($text, '.') === 1 && str_ends_with($text, '.')) {
            return substr($text, 0, -1);
        }

        return $text;
    }

    private function normalizeInRequestFlagName(string $propertyName): string
    {
        return $this->normalizeTrackingFlagName($propertyName, 'InRequest');
    }

    private function normalizeInPathFlagName(string $propertyName): string
    {
        return $this->normalizeTrackingFlagName($propertyName, 'InPath');
    }

    private function normalizeInQueryFlagName(string $propertyName): string
    {
        return $this->normalizeTrackingFlagName($propertyName, 'InQuery');
    }

    private function normalizeInHeaderFlagName(string $propertyName): string
    {
        return $this->normalizeTrackingFlagName($propertyName, 'InHeader');
    }

    private function normalizeInCookieFlagName(string $propertyName): string
    {
        return $this->normalizeTrackingFlagName($propertyName, 'InCookie');
    }

    private function normalizeTrackingFlagName(string $propertyName, string $suffix): string
    {
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $propertyName);
        $parts = array_values(array_filter($splitResult !== false ? $splitResult : [], static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            $camel = 'value';
        } else {
            $first = $parts[0];
            $camel = strtoupper($first) === $first ? strtolower($first) : lcfirst($first);

            for ($i = 1, $count = count($parts); $i < $count; $i++) {
                $part = $parts[$i];
                $camel .= ucfirst(strtolower($part));
            }
        }

        if (is_numeric($camel[0])) {
            $camel = 'value' . $camel;
        }

        return $camel . $suffix;
    }
}
