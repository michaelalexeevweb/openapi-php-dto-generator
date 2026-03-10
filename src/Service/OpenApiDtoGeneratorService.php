<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use RuntimeException;

final class OpenApiDtoGeneratorService
{
    /** @var array<string, array<mixed>> */
    private array $dtoSchemas = [];

    /** @var array<string, array{type: string, values: array<int, string|int>}> */
    private array $enumSchemas = [];

    /** @var array<string, true> */
    private array $parentClasses = [];

    /** @var array<string, array<int, string>> */
    private array $unionInterfacesByClass = [];

    /**
     * @param array<mixed> $openApi
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace): int
    {
        $this->dtoSchemas = [];
        $this->enumSchemas = [];
        $this->parentClasses = [];
        $this->unionInterfacesByClass = [];

        $schemas = $this->extractSchemas($openApi);
        foreach ($this->extractInlineResponseSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }
        foreach ($this->extractInlineRequestSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }
        foreach ($this->extractParameterSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }

        foreach ($schemas as $schemaName => $schemaDefinition) {
            if (!is_array($schemaDefinition)) {
                continue;
            }

            $className = $this->normalizeClassName((string) $schemaName);
            $this->registerSchema($className, $schemaDefinition);
        }

        $this->expandNestedSchemas();
        $this->detectParentClasses();
        $this->detectUnionInterfaces();
        $this->prepareOutputDirectory($outputDirectory);

        $generatedCount = 0;

        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            $schemaMetadata = $this->analyzeSchema($className, $schemaDefinition);
            $classCode = $this->renderDtoClass($namespace, $className, $schemaMetadata);
            $filePath = rtrim(
                    $outputDirectory,
                    DIRECTORY_SEPARATOR
                ) . DIRECTORY_SEPARATOR . $className . '.php';
            file_put_contents($filePath, $classCode);
            $generatedCount++;
        }

        foreach ($this->enumSchemas as $enumName => $enumDefinition) {
            $enumCode = $this->renderEnum($namespace, $enumName, $enumDefinition['type'], $enumDefinition['values']);
            $filePath = rtrim(
                    $outputDirectory,
                    DIRECTORY_SEPARATOR
                ) . DIRECTORY_SEPARATOR . $enumName . '.php';
            file_put_contents($filePath, $enumCode);
            $generatedCount++;
        }

        return $generatedCount;
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function registerSchema(string $className, array $schemaDefinition): void
    {
        if ($this->isEnumSchema($schemaDefinition)) {
            $type = $this->resolveEnumBackingType($schemaDefinition);
            /** @var array<int, string|int> $values */
            $values = $schemaDefinition['enum'];
            $this->registerEnum($className, $type, $values);
            return;
        }

        if (isset($this->dtoSchemas[$className])) {
            if ($this->dtoSchemas[$className] != $schemaDefinition) {
                throw new RuntimeException(sprintf('DTO schema name collision for %s.', $className));
            }
            return;
        }

        $this->dtoSchemas[$className] = $schemaDefinition;
    }

    private function expandNestedSchemas(): void
    {
        $processed = [];

        while (true) {
            $unprocessed = array_diff(array_keys($this->dtoSchemas), array_keys($processed));
            if ($unprocessed === []) {
                return;
            }

            foreach ($unprocessed as $className) {
                $processed[$className] = true;
                $schemaDefinition = $this->dtoSchemas[$className];
                $this->collectNestedFromSchema($className, $schemaDefinition);
            }
        }
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function collectNestedFromSchema(string $ownerClassName, array $schemaDefinition): void
    {
        if (isset($schemaDefinition['allOf']) && is_array($schemaDefinition['allOf'])) {
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (isset($allOfItem['$ref'])) {
                    continue;
                }

                $this->collectNestedFromObjectSchema($ownerClassName, $allOfItem);
            }

            return;
        }

        $this->collectNestedFromObjectSchema($ownerClassName, $schemaDefinition);
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function collectNestedFromObjectSchema(string $ownerClassName, array $schemaDefinition): void
    {
        $properties = $schemaDefinition['properties'] ?? null;
        if (!is_array($properties)) {
            return;
        }

        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || !is_array($propertySchema)) {
                continue;
            }

            $this->resolvePropertyType($propertySchema, $ownerClassName, $propertyName);
        }
    }

    private function detectParentClasses(): void
    {
        foreach ($this->dtoSchemas as $schemaDefinition) {
            if (!isset($schemaDefinition['allOf']) || !is_array($schemaDefinition['allOf'])) {
                continue;
            }

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem) || !isset($allOfItem['$ref']) || !is_string($allOfItem['$ref'])) {
                    continue;
                }

                $parentClass = $this->schemaRefToClassName($allOfItem['$ref']);
                $this->parentClasses[$parentClass] = true;
            }
        }
    }

    private function detectUnionInterfaces(): void
    {
        foreach ($this->dtoSchemas as $schemaName => $schemaDefinition) {
            if (!isset($schemaDefinition['oneOf']) && !isset($schemaDefinition['anyOf'])) {
                continue;
            }

            $className = $this->normalizeClassName((string) $schemaName);

            foreach ($this->collectUnionTypes($className, $schemaDefinition['oneOf'] ?? [], 'oneOf') as $unionClass) {
                $this->unionInterfacesByClass[$unionClass][] = $className;
            }

            foreach ($this->collectUnionTypes($className, $schemaDefinition['anyOf'] ?? [], 'anyOf') as $unionClass) {
                $this->unionInterfacesByClass[$unionClass][] = $className;
            }
        }
    }

    /**
     * @param mixed $variants
     * @return array<int, string>
     */
    private function collectUnionTypes(string $ownerClassName, mixed $variants, string $keyword): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $result = [];

        foreach (array_values($variants) as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            if (isset($variant['$ref']) && is_string($variant['$ref'])) {
                $result[] = $this->schemaRefToClassName($variant['$ref']);
                continue;
            }

            if (!$this->isInlineObjectVariant($variant)) {
                continue;
            }

            $suffix = $keyword === 'oneOf' ? 'OneOf' : 'AnyOf';
            $variantClassName = $ownerClassName . $suffix . ($index + 1);
            $this->registerSchema($variantClassName, $variant);
            $result[] = $variantClassName;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isInlineObjectVariant(array $variant): bool
    {
        if (($variant['type'] ?? null) === 'object') {
            return true;
        }

        // OpenAPI often omits type when object-like structure is obvious.
        return isset($variant['properties']) && is_array($variant['properties']);
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @return array{
     *     properties: array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>,
     *     extends: string|null,
     *     unionTypes: array<string>
     * }
     */
    private function analyzeSchema(string $className, array $schemaDefinition): array
    {
        $extends = null;
        $unionTypes = [];

        if (isset($schemaDefinition['allOf']) && is_array($schemaDefinition['allOf'])) {
            $allProperties = [];
            $refCount = 0;
            $firstRef = null;

            // Count how many $refs we have
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (is_array($allOfItem) && isset($allOfItem['$ref']) && is_string($allOfItem['$ref'])) {
                    $refCount++;
                    if ($firstRef === null) {
                        $firstRef = $allOfItem['$ref'];
                    }
                }
            }

            // If only one $ref, use inheritance. Otherwise, merge all properties.
            $useInheritance = $refCount === 1;

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (isset($allOfItem['$ref']) && is_string($allOfItem['$ref'])) {
                    if ($useInheritance) {
                        $extends = $this->schemaRefToClassName($allOfItem['$ref']);
                    } else {
                        // Multiple $refs: collect properties from referenced schema
                        $refClassName = $this->schemaRefToClassName($allOfItem['$ref']);
                        foreach ($this->getSchemaProperties($refClassName) as $property) {
                            $allProperties[] = $property;
                        }
                    }
                    continue;
                }

                foreach ($this->extractProperties($allOfItem, $className) as $property) {
                    $allProperties[] = $property;
                }
            }

            return [
                'properties' => $allProperties,
                'extends' => $extends,
                'unionTypes' => [],
            ];
        }

        if (isset($schemaDefinition['oneOf']) && is_array($schemaDefinition['oneOf'])) {
            $unionTypes = $this->collectUnionTypes($className, $schemaDefinition['oneOf'], 'oneOf');

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
            ];
        }

        if (isset($schemaDefinition['anyOf']) && is_array($schemaDefinition['anyOf'])) {
            $unionTypes = $this->collectUnionTypes($className, $schemaDefinition['anyOf'], 'anyOf');

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
            ];
        }

        return [
            'properties' => $this->extractProperties($schemaDefinition, $className),
            'extends' => null,
            'unionTypes' => [],
        ];
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractSchemas(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? [];

        return is_array($schemas) ? $schemas : [];
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @return array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>
     */
    private function extractProperties(array $schemaDefinition, string $ownerClassName): array
    {
        $properties = $schemaDefinition['properties'] ?? [];
        $required = $schemaDefinition['required'] ?? [];

        if (!is_array($properties)) {
            return [];
        }

        $requiredMap = [];
        foreach ($required as $requiredProperty) {
            if (is_string($requiredProperty)) {
                $requiredMap[$requiredProperty] = true;
            }
        }

        $result = [];

        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || !is_array($propertySchema)) {
                continue;
            }

            [$type, $nullableBySchema] = $this->resolvePropertyType($propertySchema, $ownerClassName, $propertyName);
            $isRequired = isset($requiredMap[$propertyName]);
            $nullable = $nullableBySchema || !$isRequired;
            $default = $this->extractDefaultValue($propertySchema, $type);
            $description = $this->extractDescription($propertySchema);
            $temporalFormat = $this->resolveTemporalPhpDocFormat($propertySchema);

            $paramIn = $propertySchema['x-parameter-in'] ?? null;
            $isInPath = $paramIn === 'path';
            $isInQuery = $paramIn === 'query';

            $result[] = [
                'name' => $this->normalizePropertyName($propertyName),
                'type' => $type,
                'nullable' => $nullable,
                'required' => $isRequired,
                'default' => $default,
                'description' => $description,
                'temporalFormat' => $temporalFormat,
                'inPath' => $isInPath,
                'inQuery' => $isInQuery,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolvePropertyType(array $propertySchema, string $ownerClassName, string $propertyName): array
    {
        if (isset($propertySchema['$ref']) && is_string($propertySchema['$ref'])) {
            $binaryType = $this->resolveBinaryRefType($propertySchema['$ref']);
            if ($binaryType !== null) {
                return [$binaryType, false];
            }

            $temporalType = $this->resolveTemporalRefType($propertySchema['$ref']);
            if ($temporalType !== null) {
                return [$temporalType, false];
            }

            return [$this->schemaRefToClassName($propertySchema['$ref']), false];
        }

        $nullable = (bool) ($propertySchema['nullable'] ?? false);

        // Handle nullable allOf
        if (isset($propertySchema['allOf']) && is_array($propertySchema['allOf'])) {
            // If allOf has only one $ref, use that type directly
            if (count($propertySchema['allOf']) === 1 && isset($propertySchema['allOf'][0]['$ref'])) {
                $binaryType = $this->resolveBinaryRefType((string) $propertySchema['allOf'][0]['$ref']);
                if ($binaryType !== null) {
                    return [$binaryType, $nullable];
                }

                $temporalType = $this->resolveTemporalRefType((string) $propertySchema['allOf'][0]['$ref']);
                if ($temporalType !== null) {
                    return [$temporalType, $nullable];
                }

                $refType = $this->schemaRefToClassName($propertySchema['allOf'][0]['$ref']);
                return [$refType, $nullable];
            }

            // Multiple allOf elements - create merged DTO
            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema($mergedClassName, $propertySchema);
            return [$mergedClassName, $nullable];
        }

        if (isset($propertySchema['enum']) && is_array($propertySchema['enum']) && $propertySchema['enum'] !== []) {
            $enumName = $ownerClassName . $this->normalizeClassName($propertyName);
            $type = $this->resolveEnumBackingType($propertySchema);
            /** @var array<int, string|int> $values */
            $values = $propertySchema['enum'];
            $this->registerEnum($enumName, $type, $values);
            return [$enumName, $nullable];
        }

        $type = $propertySchema['type'] ?? null;

        if (is_array($type)) {
            $nonNullTypes = array_values(array_filter($type, static fn (mixed $item): bool => $item !== 'null'));
            $nullable = count($nonNullTypes) !== count($type);
            $type = $nonNullTypes[0] ?? 'mixed';
        }

        if (!is_string($type)) {
            return ['mixed', $nullable];
        }

        if ($type === 'string') {
            $formatType = $this->mapStringFormatType($propertySchema['format'] ?? null);
            if ($formatType !== null) {
                return [$formatType, $nullable];
            }
        }

        if ($type === 'object') {
            $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema($nestedClassName, $propertySchema);
            return [$nestedClassName, $nullable];
        }

        if ($type === 'array') {
            $items = $propertySchema['items'] ?? null;

            if (!is_array($items)) {
                return ['array', $nullable];
            }

            if (isset($items['$ref']) && is_string($items['$ref'])) {
                $binaryItemType = $this->resolveBinaryRefType($items['$ref']);
                if ($binaryItemType !== null) {
                    return ['array<' . $binaryItemType . '>', $nullable];
                }

                $temporalItemType = $this->resolveTemporalRefType($items['$ref']);
                if ($temporalItemType !== null) {
                    return ['array<' . $temporalItemType . '>', $nullable];
                }

                return ['array<' . $this->schemaRefToClassName($items['$ref']) . '>', $nullable];
            }

            if (isset($items['enum']) && is_array($items['enum']) && $items['enum'] !== []) {
                $enumName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $enumType = $this->resolveEnumBackingType($items);
                /** @var array<int, string|int> $values */
                $values = $items['enum'];
                $this->registerEnum($enumName, $enumType, $values);
                return ['array<' . $enumName . '>', $nullable];
            }

            $itemsType = $items['type'] ?? null;
            if ($itemsType === 'object') {
                $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $this->registerSchema($nestedClassName, $items);
                return ['array<' . $nestedClassName . '>', $nullable];
            }

            if ($itemsType === 'string') {
                $itemsFormatType = $this->mapStringFormatType($items['format'] ?? null);
                if ($itemsFormatType !== null) {
                    return ['array<' . $itemsFormatType . '>', $nullable];
                }
            }

            if (is_string($itemsType)) {
                $mapped = match ($itemsType) {
                    'integer' => 'int',
                    'number' => 'float',
                    'string' => 'string',
                    'boolean' => 'bool',
                    default => 'mixed',
                };

                return ['array<' . $mapped . '>', $nullable];
            }

            return ['array', $nullable];
        }

        return [match ($type) {
            'integer' => 'int',
            'number' => 'float',
            'string' => 'string',
            'boolean' => 'bool',
            default => 'mixed',
        }, $nullable];
    }

    private function mapStringFormatType(mixed $format): ?string
    {
        if (!is_string($format)) {
            return null;
        }

        return match ($format) {
            'binary' => 'UploadedFile',
            'date', 'date-time', 'datetime' => 'DateTimeImmutable',
            default => null,
        };
    }

    private function resolveTemporalPhpDocFormat(array $propertySchema): ?string
    {
        $format = $propertySchema['format'] ?? null;
        if (is_string($format)) {
            return $this->mapTemporalPhpDocFormat($format);
        }

        if (isset($propertySchema['$ref']) && is_string($propertySchema['$ref'])) {
            return $this->resolveTemporalRefPhpDocFormat($propertySchema['$ref']);
        }

        if (isset($propertySchema['allOf']) && is_array($propertySchema['allOf']) && count($propertySchema['allOf']) === 1) {
            $allOfItem = $propertySchema['allOf'][0] ?? null;
            if (is_array($allOfItem) && isset($allOfItem['$ref']) && is_string($allOfItem['$ref'])) {
                return $this->resolveTemporalRefPhpDocFormat($allOfItem['$ref']);
            }
        }

        return null;
    }

    private function resolveTemporalRefPhpDocFormat(string $ref): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if (!is_string($schemaName) || $schemaName === '') {
            return null;
        }

        $className = $this->normalizeClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $format = $definition['format'] ?? null;
        return is_string($format) ? $this->mapTemporalPhpDocFormat($format) : null;
    }

    private function mapTemporalPhpDocFormat(string $format): ?string
    {
        return match ($format) {
            'date' => 'Y-m-d',
            'date-time', 'datetime' => 'yyyy-MM-dd HH:mm:ss',
            default => null,
        };
    }

    private function resolveBinaryRefType(string $ref): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if (!is_string($schemaName) || $schemaName === '') {
            return null;
        }

        $className = $this->normalizeClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (($definition['type'] ?? null) === 'string' && (($definition['format'] ?? null) === 'binary')) {
            return 'UploadedFile';
        }

        return null;
    }

    private function resolveTemporalRefType(string $ref): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if (!is_string($schemaName) || $schemaName === '') {
            return null;
        }

        $className = $this->normalizeClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (($definition['type'] ?? null) !== 'string') {
            return null;
        }

        $formatType = $this->mapStringFormatType($definition['format'] ?? null);
        return $formatType === 'DateTimeImmutable' ? 'DateTimeImmutable' : null;
    }

    private function schemaRefToClassName(string $ref): string
    {
        $prefix = '#/components/schemas/';

        if (!str_starts_with($ref, $prefix)) {
            return 'mixed';
        }

        $schemaName = substr($ref, strlen($prefix));

        return is_string($schemaName) && $schemaName !== ''
            ? $this->normalizeClassName($schemaName)
            : 'mixed';
    }

    /**
     * @param array{
     *     properties: array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string, temporalFormat?: ?string, inPath?: bool, inQuery?: bool}>,
     *     extends: string|null,
     *     unionTypes: array<string>
     * } $schemaMetadata
     */
    private function renderDtoClass(string $namespace, string $className, array $schemaMetadata): string
    {
        $properties = $schemaMetadata['properties'];
        $extends = $schemaMetadata['extends'];
        $unionTypes = $schemaMetadata['unionTypes'];

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $namespace . ';',
            '',
        ];

        $needsDateTimeImport = $this->needsDateTimeImmutableImport($properties);
        $needsUploadedFileImport = $this->needsUploadedFileImport($properties);

        if ($needsDateTimeImport) {
            $lines[] = 'use DateTimeImmutable;';
        }
        if ($needsUploadedFileImport) {
            $lines[] = 'use Symfony\\Component\\HttpFoundation\\File\\UploadedFile;';
        }
        if ($needsDateTimeImport || $needsUploadedFileImport) {
            $lines[] = '';
        }

        if ($unionTypes !== []) {
            $lines[] = '/**';
            $lines[] = ' * Members: ' . implode('|', $unionTypes);
            $lines[] = ' */';
            $lines[] = 'interface ' . $className;
            $lines[] = '{';
            $lines[] = '}';
            $lines[] = '';

            return implode("\n", $lines);
        }

        $classModifiers = isset($this->parentClasses[$className]) ? '' : 'final ';
        $implementedInterfaces = array_values(array_unique($this->unionInterfacesByClass[$className] ?? []));

        $signature = $classModifiers . 'class ' . $className;
        if ($extends !== null) {
            $signature .= ' extends ' . $extends;
        }
        if ($implementedInterfaces !== []) {
            $signature .= ' implements ' . implode(', ', $implementedInterfaces);
        }

        $lines[] = $signature;
        $lines[] = '{';

        $ownProperties = $this->deduplicatePropertiesByLastDefinition($properties);
        $parentProperties = $extends !== null
            ? $this->deduplicatePropertiesByLastDefinition($this->getParentProperties($extends))
            : [];

        $parentByName = $this->indexPropertiesByName($parentProperties);
        $ownByName = $this->indexPropertiesByName($ownProperties);

        foreach ($ownByName as $name => $ownProperty) {
            if (!isset($parentByName[$name])) {
                continue;
            }

            if (!$this->isPropertyOverrideCompatible($parentByName[$name], $ownProperty)) {
                throw new RuntimeException(sprintf(
                    'Property override conflict in %s extends %s for $%s: parent type %s, child type %s.',
                    $className,
                    (string) $extends,
                    $name,
                    $this->describePropertyType($parentByName[$name]),
                    $this->describePropertyType($ownProperty)
                ));
            }
        }

        foreach ($ownProperties as $ownProperty) {
            if (isset($parentByName[$ownProperty['name']])) {
                continue;
            }

            $this->addPrivateProperty($lines, $ownProperty);
        }

        if (!empty($ownProperties) && !isset($parentByName[$ownProperties[0]['name']])) {
            $lines[] = '';
        }

        $lines[] = '    public function __construct(';

        $allConstructorParams = [];

        if ($extends !== null) {
            foreach ($parentProperties as $parentProperty) {
                $effectiveProperty = $ownByName[$parentProperty['name']] ?? $parentProperty;
                $allConstructorParams[] = $effectiveProperty;
            }
        }

        foreach ($ownProperties as $ownProperty) {
            if (isset($parentByName[$ownProperty['name']])) {
                continue;
            }
            $allConstructorParams[] = $ownProperty;
        }

        foreach ($allConstructorParams as $property) {
            $this->addConstructorParameter($lines, $property);
        }

        $lines[] = '    ) {';

        if ($extends !== null && $parentProperties !== []) {
            $parentArgs = [];
            foreach ($parentProperties as $parentProperty) {
                $parentArgs[] = '$' . $parentProperty['name'];
            }
            $lines[] = '        parent::__construct(' . implode(', ', $parentArgs) . ');';
        }

        foreach ($ownProperties as $ownProperty) {
            if (isset($parentByName[$ownProperty['name']])) {
                continue;
            }
            $lines[] = '        $this->' . $ownProperty['name'] . ' = $' . $ownProperty['name'] . ';';
        }

        $lines[] = '    }';

        $allProperties = [];
        foreach ($ownProperties as $property) {
            if (isset($parentByName[$property['name']])) {
                continue;
            }
            $allProperties[] = $property;
        }

        if (!empty($allProperties)) {
            $lines[] = '';
        }

        foreach ($allProperties as $index => $property) {
            $this->addGetter($lines, $property);
            $lines[] = '';
            $this->addRequiredChecker($lines, $property);
            $lines[] = '';
            $this->addInLocationCheckers($lines, $property);

            if (str_starts_with((string) $property['type'], 'array')) {
                $lines[] = '';
                $this->addArrayItemAdder($lines, $property);
            }

            if ($index < count($allProperties) - 1) {
                $lines[] = '';
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $property
     */
    private function addPrivateProperty(array &$lines, array $property): void
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];

        if (str_contains($phpType, '<')) {
            $phpDocType = $phpType;
            $phpType = 'array';
        }

        $type = $property['nullable'] ? '?' . $phpType : $phpType;
        $description = $property['description'] ?? null;

        // Add description comment if present
        if ($description !== null && $description !== '') {
            $lines[] = '    /**';
            $lines[] = '     * ' . $description;
            if ($phpType !== $phpDocType) {
                $docType = $property['nullable'] ? '?' . $phpDocType : $phpDocType;
                $lines[] = '     *';
                $lines[] = '     * @var ' . $docType;
            }
            $lines[] = '     */';
        } elseif ($phpType !== $phpDocType) {
            $docType = $property['nullable'] ? '?' . $phpDocType : $phpDocType;
            $lines[] = '    /** @var ' . $docType . ' */';
        }

        $lines[] = '    private ' . $type . ' $' . $property['name'] . ';';
        $lines[] = '    private bool $' . $property['name'] . 'WasProvidedInRequest = false;';
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $property
     */
    private function addConstructorParameter(array &$lines, array $property): void
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];

        if (str_contains($phpType, '<')) {
            $phpDocType = $phpType;
            $phpType = 'array';
        }

        $type = $property['nullable'] ? '?' . $phpType : $phpType;
        $defaultValue = $this->renderDefaultValue($property['default'], $phpType, $property['type']);

        $lines[] = '        ' . $type . ' $' . $property['name'] . $defaultValue . ',';
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $property
     */
    private function addGetter(array &$lines, array $property): void
    {
        $phpType = $property['type'];
        $phpDocType = $property['type'];

        if (str_contains($phpType, '<')) {
            $phpDocType = $phpType;
            $phpType = 'array';
        }

        $type = $property['nullable'] ? '?' . $phpType : $phpType;
        $methodName = 'get' . ucfirst($property['name']);
        $description = $property['description'] ?? null;
        $temporalFormat = $property['temporalFormat'] ?? null;

        if ($phpType === 'DateTimeImmutable' && $temporalFormat !== null) {
            $returnType = 'string';
            $phpDateFormat = $temporalFormat === 'Y-m-d' ? 'Y-m-d' : 'c';

            $lines[] = '    /**';
            if ($description !== null && $description !== '') {
                $lines[] = '     * ' . $description;
                $lines[] = '     *';
            }
            $lines[] = '     * Expected format: ' . $temporalFormat;
            $lines[] = '     */';
            $lines[] = '    public function ' . $methodName . '(): ' . $returnType;
            $lines[] = '    {';
            if (!$property['required']) {
                $lines[] = '        if (!$this->' . $property['name'] . 'WasProvidedInRequest) {';
                $lines[] = '            throw new \LogicException(\'Field "' . $property['name'] . '" wasn\\\'t provided in request\');';
                $lines[] = '        }';
            }
            if ($property['nullable']) {
                $lines[] = '        return $this->' . $property['name'] . '?->format(' . "'" . $phpDateFormat . "'" . ') ?? "";';
            } else {
                $lines[] = '        return $this->' . $property['name'] . '->format(' . "'" . $phpDateFormat . "'" . ');';
            }
            $lines[] = '    }';
            return;
        }

        // Add description comment if present
        if ($description !== null && $description !== '') {
            $lines[] = '    /**';
            $lines[] = '     * ' . $description;
            if ($phpType !== $phpDocType) {
                $docType = $property['nullable'] ? '?' . $phpDocType : $phpDocType;
                $lines[] = '     *';
                $lines[] = '     * @return ' . $docType;
            }
            $lines[] = '     */';
        } elseif ($phpType !== $phpDocType) {
            $docType = $property['nullable'] ? '?' . $phpDocType : $phpDocType;
            $lines[] = '    /**';
            $lines[] = '     * @return ' . $docType;
            $lines[] = '     */';
        }

        $lines[] = '    public function ' . $methodName . '(): ' . $type;
        $lines[] = '    {';
        if (!$property['required']) {
            $lines[] = '        if (!$this->' . $property['name'] . 'WasProvidedInRequest) {';
            $lines[] = '            throw new \LogicException(\'Field "' . $property['name'] . '" wasn\\\'t provided in request\');';
            $lines[] = '        }';
        }
        $lines[] = '        return $this->' . $property['name'] . ';';
        $lines[] = '    }';
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $property
     */
    private function addRequiredChecker(array &$lines, array $property): void
    {
        $methodName = 'is' . ucfirst($property['name']) . 'Required';
        $required = $property['required'] ? 'true' : 'false';

        $lines[] = '    public function ' . $methodName . '(): bool';
        $lines[] = '    {';
        $lines[] = '        return ' . $required . ';';
        $lines[] = '    }';
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string, inPath: bool, inQuery: bool} $property
     */
    private function addInLocationCheckers(array &$lines, array $property): void
    {
        $name = ucfirst($property['name']);
        $inPath = $property['inPath'] ? 'true' : 'false';
        $inQuery = $property['inQuery'] ? 'true' : 'false';

        $lines[] = '    public function is' . $name . 'InPath(): bool';
        $lines[] = '    {';
        $lines[] = '        return ' . $inPath . ';';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function is' . $name . 'InQuery(): bool';
        $lines[] = '    {';
        $lines[] = '        return ' . $inQuery . ';';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function is' . $name . 'InRequest(): bool';
        $lines[] = '    {';
        $lines[] = '        return $this->' . lcfirst($name) . 'WasProvidedInRequest;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function markAs' . $name . 'ProvidedInRequest(): void';
        $lines[] = '    {';
        $lines[] = '        $this->' . lcfirst($name) . 'WasProvidedInRequest = true;';
        $lines[] = '    }';
    }

    /**
     * @param array<string> $lines
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string, inPath: bool, inQuery: bool} $property
     */
    private function addArrayItemAdder(array &$lines, array $property): void
    {
        $fieldName = $property['name'];
        $methodName = 'addItemTo' . ucfirst($fieldName);
        $itemType = $this->resolveArrayItemPhpType($property['type']);

        $lines[] = '    public function ' . $methodName . '(' . $itemType . ' $item): void';
        $lines[] = '    {';
        $lines[] = '        if ($item === null) {';
        $lines[] = '            return;';
        $lines[] = '        }';

        if ($property['nullable']) {
            $lines[] = '        if ($this->' . $fieldName . ' === null) {';
            $lines[] = '            $this->' . $fieldName . ' = [];';
            $lines[] = '        }';
        }

        $lines[] = '        $this->' . $fieldName . '[] = $item;';
        $lines[] = '    }';
    }

    private function resolveArrayItemPhpType(string $fullType): string
    {
        if (!str_starts_with($fullType, 'array<')) {
            return 'mixed';
        }

        $itemType = substr($fullType, 6, -1);
        if (!is_string($itemType) || $itemType === '') {
            return 'mixed';
        }

        return match ($itemType) {
            'int', 'float', 'string', 'bool', 'mixed', 'array' => $itemType,
            default => $itemType,
        };
    }

    /**
     * @param array<int, array{name: string, type: string}> $properties
     */
    private function needsDateTimeImmutableImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = (string) ($property['type'] ?? '');
            if ($type === 'DateTimeImmutable' || str_contains($type, 'DateTimeImmutable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{name: string, type: string}> $properties
     */
    private function needsUploadedFileImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = (string) ($property['type'] ?? '');
            if ($type === 'UploadedFile' || str_contains($type, 'UploadedFile')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}> $properties
     * @return array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>
     */
    private function deduplicatePropertiesByLastDefinition(array $properties): array
    {
        $latestByName = [];
        foreach ($properties as $property) {
            $latestByName[$property['name']] = $property;
        }

        $result = [];
        $seen = [];

        for ($i = count($properties) - 1; $i >= 0; $i--) {
            $name = $properties[$i]['name'];
            if (isset($seen[$name])) {
                continue;
            }

            $result[] = $latestByName[$name];
            $seen[$name] = true;
        }

        return array_reverse($result);
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}> $properties
     * @return array<string, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>
     */
    private function indexPropertiesByName(array $properties): array
    {
        $result = [];
        foreach ($properties as $property) {
            $result[$property['name']] = $property;
        }

        return $result;
    }

    /**
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $parentProperty
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $childProperty
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
     * @param array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string} $property
     */
    private function describePropertyType(array $property): string
    {
        return ($property['nullable'] ? '?' : '') . $property['type'];
    }

    /**
     * @return array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>
     */
    private function getParentProperties(string $parentClassName): array
    {
        foreach ($this->dtoSchemas as $schemaName => $schemaDefinition) {
            if ($schemaName !== $parentClassName) {
                continue;
            }

            return $this->extractProperties($schemaDefinition, $parentClassName);
        }

        return [];
    }

    /**
     * Recursively get all properties from a schema, including inherited ones.
     * @return array<int, array{name: string, type: string, nullable: bool, required: bool, default: mixed, description: ?string}>
     */
    private function getSchemaProperties(string $className): array
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? null;
        if ($schemaDefinition === null) {
            return [];
        }

        // If schema has allOf with inheritance, collect parent properties first
        if (isset($schemaDefinition['allOf']) && is_array($schemaDefinition['allOf'])) {
            $allProperties = [];

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (isset($allOfItem['$ref']) && is_string($allOfItem['$ref'])) {
                    $parentClass = $this->schemaRefToClassName($allOfItem['$ref']);
                    // Recursively get parent properties
                    foreach ($this->getSchemaProperties($parentClass) as $prop) {
                        $allProperties[] = $prop;
                    }
                    continue;
                }

                foreach ($this->extractProperties($allOfItem, $className) as $property) {
                    $allProperties[] = $property;
                }
            }

            return $allProperties;
        }

        return $this->extractProperties($schemaDefinition, $className);
    }

    private function normalizeClassName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
        $parts = preg_split('/[^A-Za-z0-9]+/', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'GeneratedDto';
        }

        $normalized = '';
        foreach ($parts as $part) {
            $normalized .= ucfirst(strtolower($part));
        }

        if (is_numeric($normalized[0])) {
            return '_' . $normalized;
        }

        return $normalized;
    }

    private function normalizePropertyName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
        $parts = preg_split('/[^A-Za-z0-9]+/', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'value';
        }

        $propertyName = strtolower((string) array_shift($parts));
        foreach ($parts as $part) {
            $propertyName .= ucfirst(strtolower($part));
        }

        if (is_numeric($propertyName[0])) {
            return '_' . $propertyName;
        }

        return $propertyName;
    }

    private function prepareOutputDirectory(string $outputDirectory): void
    {
        if (is_dir($outputDirectory)) {
            $this->deleteDirectoryContents($outputDirectory);
            return;
        }

        if (!mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $outputDirectory));
        }
    }

    private function deleteDirectoryContents(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException(sprintf('Cannot read directory: %s', $directory));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->deleteDirectoryContents($path);
                rmdir($path);
                continue;
            }

            unlink($path);
        }
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractInlineResponseSchemas(array $openApi): array
    {
        $paths = $openApi['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $inlineSchemas = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $operation) {
                if (!is_array($operation)) {
                    continue;
                }

                $responses = $operation['responses'] ?? [];
                if (!is_array($responses)) {
                    continue;
                }

                foreach ($responses as $statusCode => $response) {
                    if (!is_array($response)) {
                        continue;
                    }

                    $content = $response['content'] ?? [];
                    if (!is_array($content)) {
                        continue;
                    }

                    foreach ($content as $mediaTypeObject) {
                        if (!is_array($mediaTypeObject)) {
                            continue;
                        }

                        $schema = $mediaTypeObject['schema'] ?? null;
                        if (!is_array($schema) || isset($schema['$ref'])) {
                            continue;
                        }

                        if (($schema['type'] ?? null) !== 'object') {
                            continue;
                        }

                        $schemaName = $this->generateInlineSchemaName($path, (string) $statusCode);
                        $inlineSchemas[$schemaName] = $schema;
                    }
                }
            }
        }

        return $inlineSchemas;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractInlineRequestSchemas(array $openApi): array
    {
        $paths = $openApi['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $inlineSchemas = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $requestBody = $operation['requestBody'] ?? null;
                if (!is_array($requestBody)) {
                    continue;
                }

                $content = $requestBody['content'] ?? [];
                if (!is_array($content)) {
                    continue;
                }

                foreach ($content as $mediaTypeObject) {
                    if (!is_array($mediaTypeObject)) {
                        continue;
                    }

                    $schema = $mediaTypeObject['schema'] ?? null;
                    if (!is_array($schema) || isset($schema['$ref'])) {
                        continue;
                    }

                    if (($schema['type'] ?? null) !== 'object') {
                        continue;
                    }

                    $schemaName = $this->generateInlineRequestSchemaName($path, $method);
                    $inlineSchemas[$schemaName] = $schema;
                }
            }
        }

        return $inlineSchemas;
    }

    private function isHttpMethod(string $method): bool
    {
        return in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'], true);
    }

    private function generateInlineRequestSchemaName(string $path, string $method): string
    {
        return $this->normalizePathForSchemaName($path) . ucfirst(strtolower($method)) . 'Request';
    }

    private function normalizePathForSchemaName(string $path): string
    {
        $pathPart = trim($path, '/');
        $segments = preg_split('/[\/\-_]+/', $pathPart) ?: [];

        $normalizedPath = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            // Skip path parameter placeholders like {id}, {userId}, etc.
            if (preg_match('/^\{[^}]+\}$/', $segment)) {
                continue;
            }

            $normalizedPath .= ucfirst($segment);
        }

        return $normalizedPath;
    }

    private function generateInlineSchemaName(string $path, string $statusCode): string
    {
        return $this->normalizePathForSchemaName($path) . $statusCode;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isEnumSchema(array $schema): bool
    {
        return isset($schema['enum'])
            && is_array($schema['enum'])
            && $schema['enum'] !== []
            && isset($schema['type'])
            && in_array($schema['type'], ['string', 'integer'], true);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function resolveEnumBackingType(array $schema): string
    {
        $type = $schema['type'] ?? 'string';

        return $type === 'integer' ? 'int' : 'string';
    }

    /**
     * @param array<int, string|int> $values
     */
    private function registerEnum(string $enumName, string $type, array $values): void
    {
        if (isset($this->enumSchemas[$enumName])) {
            $existing = $this->enumSchemas[$enumName];
            if ($existing['type'] !== $type || $existing['values'] !== $values) {
                throw new RuntimeException(sprintf('Enum schema name collision for %s.', $enumName));
            }
            return;
        }

        if (isset($this->dtoSchemas[$enumName])) {
            throw new RuntimeException(sprintf('Enum/DTO name collision for %s.', $enumName));
        }

        $this->enumSchemas[$enumName] = [
            'type' => $type,
            'values' => $values,
        ];
    }

    /**
     * @param array<int, string|int> $values
     */
    private function renderEnum(string $namespace, string $enumName, string $backingType, array $values): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $namespace . ';',
            '',
            'enum ' . $enumName . ': ' . $backingType,
            '{',
        ];

        $usedCaseNames = [];

        foreach ($values as $value) {
            $caseName = $this->buildEnumCaseName($value, $usedCaseNames);
            $lines[] = '    case ' . $caseName . ' = ' . $this->renderEnumValue($value, $backingType) . ';';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, true> $usedCaseNames
     */
    private function buildEnumCaseName(string|int $value, array &$usedCaseNames): string
    {
        $base = (string) $value;
        $base = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $base) ?? $base;
        $base = preg_replace('/[^A-Za-z0-9]+/', '_', $base) ?? $base;
        $base = strtoupper(trim($base, '_'));

        if ($base === '') {
            $base = 'VALUE';
        }

        if (is_numeric($base[0])) {
            $base = 'VALUE_' . $base;
        }

        $name = $base;
        $i = 2;

        while (isset($usedCaseNames[$name])) {
            $name = $base . '_' . $i;
            $i++;
        }

        $usedCaseNames[$name] = true;

        return $name;
    }

    private function renderEnumValue(string|int $value, string $backingType): string
    {
        if ($backingType === 'int') {
            if (!is_int($value)) {
                throw new RuntimeException('Integer enum contains non-integer value.');
            }

            return (string) $value;
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);
        return "'" . $escaped . "'";
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractDefaultValue(array $propertySchema, string $type): mixed
    {
        if (!isset($propertySchema['default'])) {
            return null;
        }

        return $propertySchema['default'];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractDescription(array $propertySchema): ?string
    {
        if (!isset($propertySchema['description'])) {
            return null;
        }

        $description = $propertySchema['description'];
        if (!is_string($description) || trim($description) === '') {
            return null;
        }

        // Normalize multiline descriptions
        $description = trim($description);
        $description = preg_replace('/\s+/', ' ', $description);

        return $description;
    }

    private function renderDefaultValue(mixed $defaultValue, string $phpType, string $fullType): string
    {
        if ($defaultValue === null) {
            return '';
        }

        // Handle enum types - need to use the enum case
        if (!in_array($phpType, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
            // It's an enum or custom type
            if (is_string($defaultValue)) {
                $usedNames = [];
                $enumCaseName = $this->buildEnumCaseName($defaultValue, $usedNames);
                return ' = ' . $phpType . '::' . $enumCaseName;
            }
            if (is_int($defaultValue)) {
                $usedNames = [];
                $enumCaseName = $this->buildEnumCaseName($defaultValue, $usedNames);
                return ' = ' . $phpType . '::' . $enumCaseName;
            }
        }

        // Handle scalar types
        if ($phpType === 'int') {
            return ' = ' . (int) $defaultValue;
        }

        if ($phpType === 'float') {
            return ' = ' . (float) $defaultValue;
        }

        if ($phpType === 'bool') {
            return $defaultValue ? ' = true' : ' = false';
        }

        if ($phpType === 'string') {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $defaultValue);
            return " = '" . $escaped . "'";
        }

        if ($phpType === 'array') {
            return ' = []';
        }

        return '';
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractParameterSchemas(array $openApi): array
    {
        $paths = $openApi['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $parameterSchemas = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $parameters = $operation['parameters'] ?? null;
                if (!is_array($parameters) || $parameters === []) {
                    continue;
                }

                $resolvedParameters = $this->resolveParameters($parameters, $openApi);
                $pathAndQueryParameters = $this->filterPathAndQueryParameters($resolvedParameters);

                if ($pathAndQueryParameters === []) {
                    continue;
                }

                $schemaName = $this->generateParameterSchemaName($path, $method);
                $parameterSchemas[$schemaName] = $this->buildParameterSchema($pathAndQueryParameters);
            }
        }

        return $parameterSchemas;
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed> $openApi
     * @return array<mixed>
     */
    private function resolveParameters(array $parameters, array $openApi): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            // If parameter is a reference, resolve it
            if (isset($parameter['$ref']) && is_string($parameter['$ref'])) {
                $resolvedParam = $this->resolveParameterRef($parameter['$ref'], $openApi);
                if ($resolvedParam !== null) {
                    $resolved[] = $resolvedParam;
                }
                continue;
            }

            $resolved[] = $parameter;
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>|null
     */
    private function resolveParameterRef(string $ref, array $openApi): ?array
    {
        $prefix = '#/components/parameters/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $parameterName = substr($ref, strlen($prefix));
        $componentsParameters = $openApi['components']['parameters'] ?? [];

        if (!is_array($componentsParameters) || !isset($componentsParameters[$parameterName])) {
            return null;
        }

        $parameter = $componentsParameters[$parameterName];

        return is_array($parameter) ? $parameter : null;
    }

    /**
     * @param array<mixed> $parameters
     * @return array<mixed>
     */
    private function filterPathAndQueryParameters(array $parameters): array
    {
        $filtered = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $paramIn = $parameter['in'] ?? null;
            if ($paramIn === 'path' || $paramIn === 'query') {
                $filtered[] = $parameter;
            }
        }

        return $filtered;
    }

    private function generateParameterSchemaName(string $path, string $method): string
    {
        return $this->normalizePathForSchemaName($path) . ucfirst(strtolower($method)) . 'QueryParams';
    }

    /**
     * @param array<mixed> $pathParameters
     * @return array<string, mixed>
     */
    private function buildParameterSchema(array $pathParameters): array
    {
        $properties = [];
        $required = [];

        foreach ($pathParameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            $schema = $parameter['schema'] ?? null;

            if (!is_string($name) || !is_array($schema)) {
                continue;
            }

            $paramIn = $parameter['in'] ?? null;
            if ($paramIn === 'path' || $paramIn === 'query') {
                $schema['x-parameter-in'] = $paramIn;
            }

            $properties[$name] = $schema;

            $isPathParam = $paramIn === 'path';
            $isRequired = $this->toBoolean($parameter['required'] ?? false);

            // OpenAPI path parameters are always required even in malformed specs.
            if ($isPathParam || $isRequired) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values(array_unique($required)),
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
