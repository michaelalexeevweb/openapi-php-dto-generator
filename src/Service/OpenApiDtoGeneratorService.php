<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\OpenApiDtoGeneratorServiceInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @phpstan-type SchemaProperty array{
 *   name: string,
 *   openApiName: string,
 *   type: string,
 *   nullable: bool,
 *   required: bool,
 *   default: mixed,
 *   description: string|null,
 *   temporalFormat?: string|null,
 *   inPath?: bool,
 *   inQuery?: bool,
 *   constraints?: array<string, mixed>
 * }
 * @phpstan-type SchemaMetadata array{
 *   properties: array<int, SchemaProperty>,
 *   extends: string|null,
 *   unionTypes: array<string>,
 *   discriminator: array{propertyName: string, mapping: array<string, string>}|null
 * }
 */
final class OpenApiDtoGeneratorService implements OpenApiDtoGeneratorServiceInterface
{
    private Environment|null $twig = null;

    /** @var array<string, array<mixed>> */
    private array $dtoSchemas = [];

    /** @var array<string, array{type: string, values: array<int, string|int>}> */
    private array $enumSchemas = [];

    /** @var array<string, true> */
    private array $parentClasses = [];

    /** @var array<string, array<int, string>> */
    private array $unionInterfacesByClass = [];

    /** @var array<string, array{propertyName: string, mapping: array<string, string>}> */
    private array $discriminatorSchemas = [];

    /** @var array<string, string|null> */
    private array $schemaSourceFiles = [];

    /** @var array<string, string> */
    private array $schemaNamespaces = [];

    /** @var array<string, string> */
    private array $schemaOutputDirectories = [];

    /** @var array<string, string|null> */
    private array $enumSourceFiles = [];

    /** @var array<string, string> */
    private array $enumNamespaces = [];

    /** @var array<string, string> */
    private array $enumOutputDirectories = [];

    /** @var array<string, true> */
    private array $loadedExternalFiles = [];

    private ?string $rootSpecFile = null;
    private string $baseOutputDirectory = '';
    private string $baseNamespace = '';
    private string $baseSpecDirectory = '';

    public function generateFromFile(string $filePath, string $outputDirectory, string $namespace): int
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File not found: %s', $filePath));
        }

        $data = Yaml::parseFile($filePath);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAPI root must be an object/array.');
        }

        $realFilePath = realpath($filePath);
        if ($realFilePath === false) {
            throw new RuntimeException(sprintf('Cannot resolve real path for file: %s', $filePath));
        }

        $this->initializeGeneration($outputDirectory, $namespace, $realFilePath);
        $this->registerDocumentSchemas($data, $realFilePath, true);
        $this->scanExternalSchemaRefs($data, $realFilePath);

        return $this->finalizeGeneration();
    }

    /**
     * @param array<mixed> $openApi
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace): int
    {
        $this->initializeGeneration($outputDirectory, $namespace, null);

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

            $className = $this->normalizeClassName((string)$schemaName);
            $this->registerSchema($className, $schemaDefinition, null);
        }

        return $this->finalizeGeneration();
    }

    private function initializeGeneration(string $outputDirectory, string $namespace, ?string $rootSpecFile): void
    {
        $this->dtoSchemas = [];
        $this->enumSchemas = [];
        $this->parentClasses = [];
        $this->unionInterfacesByClass = [];
        $this->discriminatorSchemas = [];
        $this->schemaSourceFiles = [];
        $this->schemaNamespaces = [];
        $this->schemaOutputDirectories = [];
        $this->enumSourceFiles = [];
        $this->enumNamespaces = [];
        $this->enumOutputDirectories = [];
        $this->loadedExternalFiles = [];
        $this->rootSpecFile = $rootSpecFile;
        $this->baseOutputDirectory = $outputDirectory;
        $this->baseNamespace = $namespace;
        $this->baseSpecDirectory = $rootSpecFile !== null ? dirname($rootSpecFile) : '';
    }

    private function finalizeGeneration(): int
    {
        $this->expandNestedSchemas();
        $this->detectParentClasses();
        $this->detectUnionInterfaces();
        $this->prepareOutputDirectory($this->baseOutputDirectory);

        $generatedCount = 0;

        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            $schemaMetadata = $this->analyzeSchema($className, $schemaDefinition);
            $namespace = $this->schemaNamespaces[$className] ?? $this->baseNamespace;
            $outputDirectory = $this->schemaOutputDirectories[$className] ?? $this->baseOutputDirectory;
            $classCode = $this->renderDtoClass(
                namespace: $namespace,
                className: $className,
                schemaMetadata: $schemaMetadata,
            );
            $this->ensureDirectoryExists($outputDirectory);
            $filePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $className . '.php';
            file_put_contents($filePath, $classCode);
            $generatedCount++;
        }

        foreach ($this->enumSchemas as $enumName => $enumDefinition) {
            $namespace = $this->enumNamespaces[$enumName] ?? $this->baseNamespace;
            $outputDirectory = $this->enumOutputDirectories[$enumName] ?? $this->baseOutputDirectory;
            $enumCode = $this->renderEnum(
                namespace: $namespace,
                enumName: $enumName,
                backingType: $enumDefinition['type'],
                values: $enumDefinition['values'],
            );
            $this->ensureDirectoryExists($outputDirectory);
            $filePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $enumName . '.php';
            file_put_contents($filePath, $enumCode);
            $generatedCount++;
        }

        return $generatedCount;
    }

    /**
     * @param array<mixed> $openApi
     */
    private function registerDocumentSchemas(array $openApi, ?string $sourceFile, bool $includeInlineSchemas): void
    {
        $schemas = $this->extractSchemas($openApi);
        if ($includeInlineSchemas) {
            foreach ($this->extractInlineResponseSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
            foreach ($this->extractInlineRequestSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
            foreach ($this->extractParameterSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
        }

        foreach ($schemas as $schemaName => $schemaDefinition) {
            if (!is_array($schemaDefinition)) {
                continue;
            }

            $className = $this->normalizeClassName((string)$schemaName);
            $this->registerSchema($className, $schemaDefinition, $sourceFile);
        }
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function registerSchema(string $className, array $schemaDefinition, ?string $sourceFile): void
    {
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);

        if ($this->isEnumSchema($schemaDefinition)) {
            $type = $this->resolveEnumBackingType($schemaDefinition);
            /** @var array<int, string|int> $values */
            $values = $schemaDefinition['enum'];
            $this->registerEnum($className, $type, $values, $sourceFile);
            return;
        }

        if (isset($this->dtoSchemas[$className])) {
            if ($this->dtoSchemas[$className] != $schemaDefinition) {
                throw new RuntimeException(sprintf('DTO schema name collision for %s.', $className));
            }

            if (($this->schemaNamespaces[$className] ?? $namespace) !== $namespace) {
                throw new RuntimeException(sprintf('DTO schema namespace collision for %s.', $className));
            }
            return;
        }

        $this->dtoSchemas[$className] = $schemaDefinition;
        $this->schemaSourceFiles[$className] = $sourceFile;
        $this->schemaNamespaces[$className] = $namespace;
        $this->schemaOutputDirectories[$className] = $outputDirectory;
        $this->collectDiscriminatorMetadata($className, $schemaDefinition);
    }

    private function resolveNamespaceForSourceFile(?string $sourceFile): string
    {
        if ($sourceFile === null || $this->rootSpecFile === null || $sourceFile === $this->rootSpecFile) {
            return $this->baseNamespace;
        }

        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return $this->baseNamespace;
        }

        $segments = array_values(
            array_filter(
                explode('/', $relativeDirectory),
                static fn(string $segment): bool => $segment !== '' && $segment !== '.',
            ),
        );
        if ($segments === []) {
            return $this->baseNamespace;
        }

        $namespaceSuffix = implode(
            '\\',
            array_map(fn(string $segment): string => $this->normalizeClassName($segment), $segments),
        );
        return $this->baseNamespace . '\\' . $namespaceSuffix;
    }

    private function resolveOutputDirectoryForSourceFile(?string $sourceFile): string
    {
        if ($sourceFile === null || $this->rootSpecFile === null || $sourceFile === $this->rootSpecFile) {
            return $this->baseOutputDirectory;
        }

        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return $this->baseOutputDirectory;
        }

        return rtrim($this->baseOutputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativeDirectory,
            );
    }

    private function resolveRelativeSpecDirectory(string $sourceFile): string
    {
        if ($this->baseSpecDirectory === '') {
            return '';
        }

        $relativeFile = $this->makeRelativePath($this->baseSpecDirectory, $sourceFile);
        $relativeDirectory = dirname($relativeFile);

        return $relativeDirectory === '.' ? '' : trim(str_replace('\\', '/', $relativeDirectory), '/');
    }

    private function makeRelativePath(string $fromDirectory, string $toPath): string
    {
        $fromParts = array_values(
            array_filter(
                explode('/', str_replace('\\', '/', rtrim($fromDirectory, '/'))),
                static fn(string $part): bool => $part !== '',
            ),
        );
        $toParts = array_values(
            array_filter(explode('/', str_replace('\\', '/', $toPath)), static fn(string $part): bool => $part !== ''),
        );

        $length = min(count($fromParts), count($toParts));
        $commonLength = 0;
        while ($commonLength < $length && $fromParts[$commonLength] === $toParts[$commonLength]) {
            $commonLength++;
        }

        $up = array_fill(0, count($fromParts) - $commonLength, '..');
        $down = array_slice($toParts, $commonLength);
        $relativeParts = array_merge($up, $down);

        return $relativeParts === [] ? basename($toPath) : implode('/', $relativeParts);
    }

    /**
     * @param array<mixed> $node
     */
    private function scanExternalSchemaRefs(array $node, ?string $currentSourceFile): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $this->ensureSchemaRefRegistered($value, $currentSourceFile);
                continue;
            }

            if (is_array($value)) {
                $this->scanExternalSchemaRefs($value, $currentSourceFile);
            }
        }
    }

    private function ensureSchemaRefRegistered(string $ref, ?string $currentSourceFile): void
    {
        if (str_starts_with($ref, '#/components/schemas/')) {
            return;
        }

        $resolved = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolved === null) {
            return;
        }

        [$externalFile] = $resolved;
        $this->loadExternalDocument($externalFile);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveExternalSchemaPointer(string $ref, ?string $currentSourceFile): ?array
    {
        if ($currentSourceFile === null || !str_contains($ref, '#/components/schemas/')) {
            return null;
        }

        [$filePart, $pointerPart] = explode('#', $ref, 2) + [1 => ''];
        $filePart = rtrim($filePart, '/');
        if ($filePart === '') {
            return null;
        }

        $absoluteFile = realpath(dirname($currentSourceFile) . DIRECTORY_SEPARATOR . $filePart);
        if ($absoluteFile === false) {
            throw new RuntimeException(sprintf('Referenced OpenAPI file not found: %s', $ref));
        }

        return [$absoluteFile, '#' . $pointerPart];
    }

    private function loadExternalDocument(string $filePath): void
    {
        if (isset($this->loadedExternalFiles[$filePath])) {
            return;
        }

        $this->loadedExternalFiles[$filePath] = true;
        $data = Yaml::parseFile($filePath);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('OpenAPI root must be an object/array in %s.', $filePath));
        }

        $this->registerDocumentSchemas($data, $filePath, false);
        $this->scanExternalSchemaRefs($data, $filePath);
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function collectDiscriminatorMetadata(string $className, array $schemaDefinition): void
    {
        $discriminator = $schemaDefinition['discriminator'] ?? null;
        if (!is_array($discriminator)) {
            return;
        }

        $propertyName = $discriminator['propertyName'] ?? null;
        $mapping = $discriminator['mapping'] ?? null;

        if (!is_string($propertyName) || trim($propertyName) === '') {
            throw new RuntimeException(
                sprintf('Discriminator propertyName must be a non-empty string in %s.', $className),
            );
        }

        if (!is_array($mapping) || $mapping === []) {
            throw new RuntimeException(sprintf('Discriminator mapping must be a non-empty map in %s.', $className));
        }

        $normalizedMapping = [];
        $targetToSource = [];

        foreach ($mapping as $mappingValue => $ref) {
            if (!is_string($mappingValue) || $mappingValue === '') {
                throw new RuntimeException(
                    sprintf('Discriminator mapping key must be a non-empty string in %s.', $className),
                );
            }

            if (!is_string($ref)) {
                throw new RuntimeException(
                    sprintf(
                        'Discriminator mapping value for "%s" in %s must be a schema $ref string.',
                        $mappingValue,
                        $className,
                    ),
                );
            }

            $targetClass = $this->schemaRefToClassName($ref, $this->getSchemaSourceFile($className));
            if ($targetClass === 'mixed') {
                throw new RuntimeException(
                    sprintf(
                        'Discriminator mapping value for "%s" in %s must reference #/components/schemas/*.',
                        $mappingValue,
                        $className,
                    ),
                );
            }

            if (isset($targetToSource[$targetClass])) {
                throw new RuntimeException(
                    sprintf(
                        'Discriminator mapping in %s has duplicate target "%s" for values "%s" and "%s".',
                        $className,
                        $targetClass,
                        $targetToSource[$targetClass],
                        $mappingValue,
                    ),
                );
            }

            $targetToSource[$targetClass] = $mappingValue;
            $normalizedMapping[$mappingValue] = $targetClass;
        }

        $this->discriminatorSchemas[$className] = [
            'propertyName' => $propertyName,
            'mapping' => $normalizedMapping,
        ];
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
            if (!is_array($propertySchema)) {
                continue;
            }

            $this->resolvePropertyType(
                propertySchema: $propertySchema,
                ownerClassName: $ownerClassName,
                propertyName: (string)$propertyName,
            );
        }
    }

    private function detectParentClasses(): void
    {
        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            if (!isset($schemaDefinition['allOf']) || !is_array($schemaDefinition['allOf'])) {
                continue;
            }

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem) || !isset($allOfItem['$ref']) || !is_string($allOfItem['$ref'])) {
                    continue;
                }

                $parentClass = $this->schemaRefToClassName($allOfItem['$ref'], $this->getSchemaSourceFile($className));
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

            $className = $this->normalizeClassName((string)$schemaName);

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
                $result[] = $this->schemaRefToClassName($variant['$ref'], $this->getSchemaSourceFile($ownerClassName));
                continue;
            }

            if (!$this->isInlineObjectVariant($variant)) {
                continue;
            }

            $suffix = $keyword === 'oneOf' ? 'OneOf' : 'AnyOf';
            $variantClassName = $ownerClassName . $suffix . ($index + 1);
            $this->registerSchema($variantClassName, $variant, $this->getSchemaSourceFile($ownerClassName));
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
     * @return SchemaMetadata
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
                        $extends = $this->schemaRefToClassName(
                            $allOfItem['$ref'],
                            $this->getSchemaSourceFile($className),
                        );
                    } else {
                        // Multiple $refs: collect properties from referenced schema
                        $refClassName = $this->schemaRefToClassName(
                            $allOfItem['$ref'],
                            $this->getSchemaSourceFile($className),
                        );
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
                'discriminator' => $this->discriminatorSchemas[$className] ?? null,
            ];
        }

        if (isset($schemaDefinition['oneOf']) && is_array($schemaDefinition['oneOf'])) {
            $unionTypes = $this->collectUnionTypes($className, $schemaDefinition['oneOf'], 'oneOf');

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        if (isset($schemaDefinition['anyOf']) && is_array($schemaDefinition['anyOf'])) {
            $unionTypes = $this->collectUnionTypes($className, $schemaDefinition['anyOf'], 'anyOf');

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        return [
            'properties' => $this->extractProperties($schemaDefinition, $className),
            'extends' => null,
            'unionTypes' => [],
            'discriminator' => $this->discriminatorSchemas[$className] ?? null,
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
     * @return array<int, SchemaProperty>
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
            $requiredMap[(string)$requiredProperty] = true;
        }

        $result = [];

        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_array($propertySchema)) {
                continue;
            }

            $openApiPropertyName = (string)$propertyName;

            $propertySchema = $this->applyDiscriminatorPropertyEnumIfNeeded(
                ownerClassName: $ownerClassName,
                propertyName: $openApiPropertyName,
                propertySchema: $propertySchema,
            );

            [$type, $nullableBySchema] = $this->resolvePropertyType(
                propertySchema: $propertySchema,
                ownerClassName: $ownerClassName,
                propertyName: $openApiPropertyName,
            );
            $isRequired = isset($requiredMap[$openApiPropertyName]);
            $nullable = $nullableBySchema || !$isRequired;
            $default = $this->extractDefaultValue($propertySchema, $type);
            $description = $this->extractDescription($propertySchema);
            $temporalFormat = $this->resolveTemporalPhpDocFormat($propertySchema);
            $constraints = $this->extractValidationConstraints($propertySchema);

            $paramIn = $propertySchema['x-parameter-in'] ?? null;
            $isInPath = $paramIn === 'path';
            $isInQuery = $paramIn === 'query';

            $result[] = [
                'name' => $this->normalizePropertyName($openApiPropertyName),
                'openApiName' => $openApiPropertyName,
                'type' => $type,
                'nullable' => $nullable,
                'required' => $isRequired,
                'default' => $default,
                'description' => $description,
                'temporalFormat' => $temporalFormat,
                'inPath' => $isInPath,
                'inQuery' => $isInQuery,
                'constraints' => $constraints,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function extractValidationConstraints(array $propertySchema): array
    {
        $allowedKeys = [
            'type',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
            'pattern',
            'minItems',
            'maxItems',
            'uniqueItems',
            'format',
        ];

        $constraints = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $propertySchema)) {
                continue;
            }

            $constraints[$key] = $propertySchema[$key];
        }

        foreach (['oneOf', 'anyOf'] as $unionKey) {
            $variants = $propertySchema[$unionKey] ?? null;
            if (!is_array($variants) || $variants === []) {
                continue;
            }

            $branchConstraints = [];
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $branchConstraints[] = $this->extractValidationConstraints($variant);
            }

            if ($branchConstraints !== []) {
                $constraints[$unionKey] = $branchConstraints;
            }
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function applyDiscriminatorPropertyEnumIfNeeded(
        string $ownerClassName,
        string $propertyName,
        array $propertySchema,
    ): array {
        $discriminator = $this->discriminatorSchemas[$ownerClassName] ?? null;
        if ($discriminator === null || $discriminator['propertyName'] !== $propertyName) {
            return $propertySchema;
        }

        $propertySchema['type'] = 'string';
        $propertySchema['enum'] = array_keys($discriminator['mapping']);

        return $propertySchema;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolvePropertyType(array $propertySchema, string $ownerClassName, string $propertyName): array
    {
        $nullable = (bool)($propertySchema['nullable'] ?? false);

        if (isset($propertySchema['allOf']) && is_array($propertySchema['allOf'])) {
            $normalizedAllOf = $this->normalizeAllOfPropertySchema($propertySchema);
            if ($normalizedAllOf !== null) {
                return $this->resolvePropertyType($normalizedAllOf, $ownerClassName, $propertyName);
            }

            // Keep legacy allOf behavior for refs/objects: single ref -> ref type, multi-part -> merged DTO.
            if (count($propertySchema['allOf']) === 1 && isset($propertySchema['allOf'][0]['$ref'])) {
                $binaryType = $this->resolveBinaryRefType((string)$propertySchema['allOf'][0]['$ref']);
                if ($binaryType !== null) {
                    return [$binaryType, $nullable];
                }

                $temporalType = $this->resolveTemporalRefType((string)$propertySchema['allOf'][0]['$ref']);
                if ($temporalType !== null) {
                    return [$temporalType, $nullable];
                }

                $refType = $this->schemaRefToClassName(
                    $propertySchema['allOf'][0]['$ref'],
                    $this->getSchemaSourceFile($ownerClassName),
                );
                return [$refType, $nullable];
            }

            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema($mergedClassName, $propertySchema, $this->getSchemaSourceFile($ownerClassName));
            return [$mergedClassName, $nullable];
        }

        if (isset($propertySchema['oneOf']) && is_array($propertySchema['oneOf'])) {
            return $this->resolveComposedUnionPropertyType($propertySchema, 'oneOf', $ownerClassName, $propertyName);
        }

        if (isset($propertySchema['anyOf']) && is_array($propertySchema['anyOf'])) {
            return $this->resolveComposedUnionPropertyType($propertySchema, 'anyOf', $ownerClassName, $propertyName);
        }

        if (isset($propertySchema['$ref']) && is_string($propertySchema['$ref'])) {
            $binaryType = $this->resolveBinaryRefType($propertySchema['$ref']);
            if ($binaryType !== null) {
                return [$binaryType, $nullable];
            }

            $temporalType = $this->resolveTemporalRefType($propertySchema['$ref']);
            if ($temporalType !== null) {
                return [$temporalType, $nullable];
            }

            return [
                $this->schemaRefToClassName($propertySchema['$ref'], $this->getSchemaSourceFile($ownerClassName)),
                $nullable
            ];
        }

        if (isset($propertySchema['enum']) && is_array($propertySchema['enum']) && $propertySchema['enum'] !== []) {
            $parentEnumType = $this->resolveParentEnumTypeForOverride($ownerClassName, $propertyName, $propertySchema);
            if ($parentEnumType !== null) {
                return [$parentEnumType, $nullable];
            }

            $enumName = $ownerClassName . $this->normalizeClassName($propertyName);
            $type = $this->resolveEnumBackingType($propertySchema);
            /** @var array<int, string|int> $values */
            $values = $propertySchema['enum'];
            $this->registerEnum($enumName, $type, $values, $this->getSchemaSourceFile($ownerClassName));
            return [$enumName, $nullable];
        }

        $type = $propertySchema['type'] ?? null;

        if (is_array($type)) {
            $nonNullTypes = array_values(
                array_filter($type, static fn(mixed $item): bool => is_string($item) && $item !== 'null'),
            );
            $nullable = count($nonNullTypes) !== count($type);

            if (count($nonNullTypes) > 1) {
                // OAS 3.1 multi-type: type: [string, integer]  →  string|int
                $phpUnionParts = array_values(
                    array_unique(
                        array_map(
                            static fn(string $t): string => match ($t) {
                                'integer' => 'int',
                                'number' => 'float',
                                'string' => 'string',
                                'boolean' => 'bool',
                                'array' => 'array',
                                default => 'mixed',
                            },
                            $nonNullTypes,
                        ),
                    ),
                );

                return [implode('|', $phpUnionParts), $nullable];
            }

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
            if ($this->isAdditionalPropertiesMapSchema($propertySchema)) {
                $mapValueType = $this->resolveAdditionalPropertiesValueType(
                    $propertySchema,
                    $ownerClassName,
                    $propertyName,
                );
                return ['array<' . $mapValueType . '>', $nullable];
            }

            $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema($nestedClassName, $propertySchema, $this->getSchemaSourceFile($ownerClassName));
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

                return [
                    'array<' . $this->schemaRefToClassName(
                        $items['$ref'],
                        $this->getSchemaSourceFile($ownerClassName),
                    ) . '>',
                    $nullable
                ];
            }

            if (isset($items['enum']) && is_array($items['enum']) && $items['enum'] !== []) {
                $enumName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $enumType = $this->resolveEnumBackingType($items);
                /** @var array<int, string|int> $values */
                $values = $items['enum'];
                $this->registerEnum($enumName, $enumType, $values, $this->getSchemaSourceFile($ownerClassName));
                return ['array<' . $enumName . '>', $nullable];
            }

            $itemsType = $items['type'] ?? null;
            if ($itemsType === 'object') {
                $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $this->registerSchema($nestedClassName, $items, $this->getSchemaSourceFile($ownerClassName));
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

        return [
            match ($type) {
                'integer' => 'int',
                'number' => 'float',
                'string' => 'string',
                'boolean' => 'bool',
                default => 'mixed',
            },
            $nullable
        ];
    }

    /**
     * If child schema overrides inherited enum with subset values,
     * reuse parent enum type to keep constructor/parent signature compatible.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveParentEnumTypeForOverride(
        string $ownerClassName,
        string $propertyName,
        array $propertySchema,
    ): ?string {
        $childValues = $propertySchema['enum'] ?? null;
        if (!is_array($childValues) || $childValues === []) {
            return null;
        }

        $parentClassName = $this->resolveSingleParentClassName($ownerClassName);
        if ($parentClassName === null) {
            return null;
        }

        $parentProperties = $this->indexPropertiesByName(
            $this->deduplicatePropertiesByLastDefinition($this->getParentProperties($parentClassName)),
        );
        $parentProperty = $parentProperties[$this->normalizePropertyName($propertyName)] ?? null;
        if (!is_array($parentProperty)) {
            return null;
        }

        $parentType = $parentProperty['type'];
        if (!array_key_exists($parentType, $this->enumSchemas)) {
            return null;
        }

        $parentEnum = $this->enumSchemas[$parentType];
        $parentValues = $parentEnum['values'] ?? [];
        if (!is_array($parentValues) || $parentValues === []) {
            return null;
        }

        foreach ($childValues as $childValue) {
            if (!in_array($childValue, $parentValues, true)) {
                return null;
            }
        }

        return $parentType;
    }

    private function resolveSingleParentClassName(string $className): ?string
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($schemaDefinition)) {
            return null;
        }

        $allOf = $schemaDefinition['allOf'] ?? null;
        if (!is_array($allOf)) {
            return null;
        }

        $ref = null;
        foreach ($allOf as $item) {
            if (!is_array($item) || !isset($item['$ref']) || !is_string($item['$ref'])) {
                continue;
            }

            if ($ref !== null) {
                return null;
            }

            $ref = $item['$ref'];
        }

        if ($ref === null) {
            return null;
        }

        return $this->schemaRefToClassName($ref, $this->getSchemaSourceFile($className));
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>|null
     */
    private function normalizeAllOfPropertySchema(array $propertySchema): ?array
    {
        $allOf = $propertySchema['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === []) {
            return null;
        }

        foreach ($allOf as $item) {
            if (!is_array($item) || !$this->canFlattenAllOfPropertyItem($item)) {
                return null;
            }
        }

        $resolved = [];
        foreach ($allOf as $item) {
            if (!is_array($item)) {
                continue;
            }

            // last-wins: each next allOf part overwrites previous keys
            $resolved = array_replace_recursive($resolved, $item);
        }

        $topLevel = $propertySchema;
        unset($topLevel['allOf']);
        $resolved = array_replace_recursive($resolved, $topLevel);

        return $resolved !== [] ? $resolved : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function canFlattenAllOfPropertyItem(array $item): bool
    {
        if (isset($item['$ref']) || isset($item['properties']) || isset($item['allOf']) || isset($item['oneOf']) || isset($item['anyOf'])) {
            return false;
        }

        return isset($item['type']) || isset($item['enum']) || isset($item['format']);
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolveComposedUnionPropertyType(
        array $propertySchema,
        string $keyword,
        string $ownerClassName,
        string $propertyName,
    ): array {
        $variants = $propertySchema[$keyword] ?? null;
        if (!is_array($variants)) {
            return ['mixed', (bool)($propertySchema['nullable'] ?? false)];
        }

        $nullable = (bool)($propertySchema['nullable'] ?? false);
        $types = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            if (($variant['type'] ?? null) === 'null') {
                $nullable = true;
                continue;
            }

            [$variantType, $variantNullable] = $this->resolvePropertyType($variant, $ownerClassName, $propertyName);
            if ($variantNullable) {
                $nullable = true;
            }

            if ($variantType === 'mixed') {
                return ['mixed', $nullable];
            }

            if (str_contains($variantType, '<')) {
                $variantType = 'array';
            }

            $types[] = $variantType;
        }

        $types = array_values(array_unique($types));
        if ($types === []) {
            return ['mixed', $nullable];
        }

        if (count($types) === 1) {
            return [$types[0], $nullable];
        }

        return [implode('|', $types), $nullable];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function isAdditionalPropertiesMapSchema(array $propertySchema): bool
    {
        if (!array_key_exists('additionalProperties', $propertySchema)) {
            return false;
        }

        if (($propertySchema['additionalProperties'] ?? null) === false) {
            return false;
        }

        $properties = $propertySchema['properties'] ?? null;
        return !is_array($properties) || $properties === [];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolveAdditionalPropertiesValueType(
        array $propertySchema,
        string $ownerClassName,
        string $propertyName,
    ): string {
        $additionalProperties = $propertySchema['additionalProperties'] ?? true;

        if ($additionalProperties === true) {
            return 'mixed';
        }

        if (!is_array($additionalProperties)) {
            return 'mixed';
        }

        [$valueType] = $this->resolvePropertyType($additionalProperties, $ownerClassName, $propertyName . 'Value');

        // Keep map value generic simple in phpdoc: array<scalar|class|mixed>.
        if (str_contains($valueType, '<') || str_contains($valueType, '|')) {
            return 'mixed';
        }

        return $valueType;
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

        if (isset($propertySchema['allOf']) && is_array($propertySchema['allOf']) && count(
                $propertySchema['allOf'],
            ) === 1) {
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
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $this->loadExternalDocument($externalFile);
            $ref = $pointer;
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
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $this->loadExternalDocument($externalFile);
            $ref = $pointer;
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

    private function schemaRefToClassName(string $ref, ?string $currentSourceFile = null): string
    {
        $prefix = '#/components/schemas/';

        if (str_starts_with($ref, $prefix)) {
            $schemaName = substr($ref, strlen($prefix));

            return is_string($schemaName) && $schemaName !== ''
                ? $this->normalizeClassName($schemaName)
                : 'mixed';
        }

        $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolvedExternal === null) {
            return 'mixed';
        }

        [$externalFile, $pointer] = $resolvedExternal;
        $this->loadExternalDocument($externalFile);

        $externalPrefix = '#/components/schemas/';
        if (!str_starts_with($pointer, $externalPrefix)) {
            return 'mixed';
        }

        $schemaName = substr($pointer, strlen($externalPrefix));

        return is_string($schemaName) && $schemaName !== ''
            ? $this->normalizeClassName($schemaName)
            : 'mixed';
    }

    private function getSchemaSourceFile(string $className): ?string
    {
        return $this->schemaSourceFiles[$className] ?? null;
    }

    private function getClassNamespace(string $className): ?string
    {
        return $this->schemaNamespaces[$className] ?? $this->enumNamespaces[$className] ?? null;
    }

    /**
     * @param SchemaMetadata $schemaMetadata
     */
    private function renderDtoClass(string $namespace, string $className, array $schemaMetadata): string
    {
        $properties = $schemaMetadata['properties'];
        $extends = $schemaMetadata['extends'];
        $unionTypes = $schemaMetadata['unionTypes'];
        $discriminator = $schemaMetadata['discriminator'] ?? null;
        $imports = $this->collectGeneratedClassImports(
            namespace: $namespace,
            className: $className,
            properties: $properties,
            extends: $extends,
            unionTypes: $unionTypes,
            discriminator: $discriminator,
        );

        $useStatements = [];

        $needsDateTimeImport = $this->needsDateTimeImmutableImport($properties);
        $needsUploadedFileImport = $this->needsUploadedFileImport($properties);

        if ($needsDateTimeImport) {
            $useStatements[] = 'DateTimeImmutable';
        }
        if ($needsUploadedFileImport) {
            $useStatements[] = 'Symfony\\Component\\HttpFoundation\\File\\UploadedFile';
        }
        foreach ($imports as $import) {
            $useStatements[] = $import;
        }
        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        if ($unionTypes !== []) {
            return $this->renderPhpTemplate('dto.php.twig', [
                'namespace' => $namespace,
                'imports' => $useStatements,
                'className' => $className,
                'unionMembers' => implode(
                    '|',
                    array_map(fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
                        $unionTypes),
                ),
                'signature' => null,
                'privateProperties' => [],
                'constructorParams' => [],
                'parentArgs' => [],
                'assignments' => [],
                'methodProperties' => [],
                'discriminator' => null,
                'extends' => null,
                'constraintAssignments' => [],
                'aliasAssignments' => [],
            ]);
        }

        $classModifiers = isset($this->parentClasses[$className]) ? '' : 'final ';
        $implementedInterfaces = array_values(array_unique([
            ...($this->unionInterfacesByClass[$className] ?? []),
            '\\JsonSerializable',
        ]));

        $signature = $classModifiers . 'class ' . $className;
        if ($extends !== null) {
            $signature .= ' extends ' . $this->formatClassNameForNamespace($extends, $namespace);
        }
        $signature .= ' implements ' . implode(
                ', ',
                array_map(fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
                    $implementedInterfaces),
            );

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

        foreach ($ownProperties as $ownProperty) {
            if (isset($parentByName[$ownProperty['name']])) {
                continue;
            }

            $privateProperties[] = $this->resolvePropertyDeclarationData($ownProperty, $namespace);
        }

        $privateProperties = $privateProperties ?? [];

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
            $constructorParams[] = $this->resolveConstructorParameterData($property, $namespace);
        }

        $constructorParams = $constructorParams ?? [];

        $parentArgs = [];
        if ($extends !== null && $parentProperties !== []) {
            foreach ($parentProperties as $parentProperty) {
                $parentArgs[] = $parentProperty['name'];
            }
        }

        foreach ($ownProperties as $ownProperty) {
            if (isset($parentByName[$ownProperty['name']])) {
                continue;
            }
            $assignments[] = $ownProperty['name'];
        }

        $assignments = $assignments ?? [];

        $allProperties = [];
        foreach ($ownProperties as $property) {
            if (isset($parentByName[$property['name']])) {
                continue;
            }
            $methodProperties[] = $this->resolveMethodPropertyData($property, $namespace);
        }
        $methodProperties = $methodProperties ?? [];

        $discriminatorData = $discriminator !== null
            ? $this->resolveDiscriminatorRenderData($discriminator, $namespace)
            : null;

        $constraintAssignments = $this->resolveConstraintAssignments($ownProperties);
        $aliasAssignments = $this->resolveAliasAssignments($ownProperties);

        return $this->renderPhpTemplate('dto.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'unionMembers' => null,
            'signature' => $signature,
            'privateProperties' => $privateProperties,
            'constructorParams' => $constructorParams,
            'parentArgs' => $parentArgs,
            'assignments' => $assignments,
            'methodProperties' => $methodProperties,
            'discriminator' => $discriminatorData,
            'extends' => $extends,
            'constraintAssignments' => $constraintAssignments,
            'aliasAssignments' => $aliasAssignments,
        ]);
    }

    /**
     * @param SchemaProperty $property
     * @return array{description: ?string, constraintsLine: ?string, docVarType: ?string, type: string, name: string, inRequestFlagName: string}
     */
    private function resolvePropertyDeclarationData(array $property, string $namespace): array
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
        $description = $property['description'] ?? null;
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        $constraintsLine = $this->formatConstraintsForDocBlock($constraints);
        $docVarType = null;
        if ($phpType !== $phpDocType) {
            $docVarType = $this->composePhpTypeHint($phpDocType, $property['nullable']);
        }

        return [
            'description' => is_string($description) && $description !== '' ? $description : null,
            'constraintsLine' => $constraintsLine,
            'docVarType' => $docVarType,
            'type' => $type,
            'name' => $property['name'],
            'inRequestFlagName' => $this->normalizeInRequestFlagName($property['name']),
        ];
    }

    /**
     * @param SchemaProperty $property
     * @return array{type: string, name: string, defaultValue: string}
     */
    private function resolveConstructorParameterData(array $property, string $namespace): array
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
        $defaultValue = $this->renderDefaultValue($property['default'], $phpType, $property['type']);

        return [
            'type' => $type,
            'name' => $property['name'],
            'defaultValue' => $defaultValue,
        ];
    }

    /**
     * @param SchemaProperty $property
     * @return array{name: string, openApiName: string, nameSuffix: string, methodName: string, returnType: string, hasGuard: bool, docDescriptionLines: array<int, string>, docReturnType: ?string, expectedFormat: ?string, returnKind: string, phpDateFormat: ?string, isNullableTemporal: bool, requiredLiteral: string, inPathLiteral: string, inQueryLiteral: string, inRequestFlagName: string, hasArrayAdder: bool, arrayAdderMethodName: string, arrayAdderItemType: string, nullableArray: bool}
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
        $temporalFormat = $property['temporalFormat'] ?? null;

        $docDescriptionLines = [];
        if ($description !== null && $description !== '') {
            $docDescriptionLines[] = $description;
        }

        $docReturnType = null;
        $expectedFormat = null;
        $returnKind = 'direct';
        $returnType = $type;
        $phpDateFormat = null;
        $isNullableTemporal = false;

        if ($phpType === 'DateTimeImmutable' && $temporalFormat !== null) {
            $returnKind = 'temporal';
            $returnType = (bool)$property['nullable'] ? '?string' : 'string';
            $expectedFormat = $temporalFormat;
            $phpDateFormat = $temporalFormat === 'Y-m-d' ? 'Y-m-d' : 'c';
            $isNullableTemporal = (bool)$property['nullable'];
        } elseif ($phpType !== $phpDocType) {
            $docReturnType = $this->composePhpTypeHint($phpDocType, $property['nullable']);
        }

        return [
            'name' => $property['name'],
            'openApiName' => $property['openApiName'],
            'nameSuffix' => ucfirst($property['name']),
            'methodName' => $methodName,
            'returnType' => $returnType,
            'hasGuard' => !$property['required'],
            'docDescriptionLines' => $docDescriptionLines,
            'docReturnType' => $docReturnType,
            'expectedFormat' => $expectedFormat,
            'returnKind' => $returnKind,
            'phpDateFormat' => $phpDateFormat,
            'isNullableTemporal' => $isNullableTemporal,
            'requiredLiteral' => $property['required'] ? 'true' : 'false',
            'inPathLiteral' => ($property['inPath'] ?? false) ? 'true' : 'false',
            'inQueryLiteral' => ($property['inQuery'] ?? false) ? 'true' : 'false',
            'inRequestFlagName' => $this->normalizeInRequestFlagName($property['name']),
            'hasArrayAdder' => str_starts_with((string)$property['type'], 'array'),
            'arrayAdderMethodName' => 'addItemTo' . ucfirst($property['name']),
            'arrayAdderItemType' => $this->resolveArrayItemPhpType($property['type']),
            'nullableArray' => (bool)$property['nullable'],
        ];
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
                'value' => str_replace(['\\', "'"], ['\\\\', "\\'"], $value),
                'targetClass' => $this->formatClassNameForNamespace($targetClass, $namespace),
            ];
        }

        return [
            'propertyName' => str_replace(['\\', "'"], ['\\\\', "\\'"], $discriminator['propertyName']),
            'mappingEntries' => $mappingEntries,
        ];
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
            if (!is_array($constraints) || $constraints === []) {
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

    /**
     * @param array<string, mixed> $context
     */
    private function renderPhpTemplate(string $templateName, array $context): string
    {
        $content = $this->renderTwig($templateName, $context);

        return rtrim($content) . "\n";
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTwig(string $templateName, array $context): string
    {
        return $this->getTwig()->render($templateName, $context);
    }


    private function getTwig(): Environment
    {
        if ($this->twig instanceof Environment) {
            return $this->twig;
        }

        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        $this->twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape' => false,
            'cache' => false,
            'trim_blocks' => true,
            'lstrip_blocks' => true,
        ]);

        return $this->twig;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @param array<string>|null $unionTypes
     * @param array{propertyName: string, mapping: array<string, string>}|null $discriminator
     * @return array<int, string>
     */
    private function collectGeneratedClassImports(
        string $namespace,
        string $className,
        array $properties,
        ?string $extends,
        array $unionTypes,
        ?array $discriminator,
    ): array {
        $imports = [];

        if ($extends !== null) {
            $this->appendImportForClass($imports, $extends, $namespace, $className);
        }

        foreach ($unionTypes as $unionType) {
            $this->appendImportForClass($imports, $unionType, $namespace, $className);
        }

        foreach ($properties as $property) {
            foreach ($this->extractReferencedClassNamesFromType((string)$property['type']) as $typeClass) {
                $this->appendImportForClass($imports, $typeClass, $namespace, $className);
            }
        }

        if ($discriminator !== null) {
            foreach ($discriminator['mapping'] as $targetClass) {
                $this->appendImportForClass($imports, $targetClass, $namespace, $className);
            }
        }

        sort($imports);

        return array_values(array_unique($imports));
    }

    /**
     * @param array<int, string> $imports
     */
    private function appendImportForClass(
        array &$imports,
        string $className,
        string $currentNamespace,
        string $currentClassName,
    ): void {
        $typeNamespace = $this->getClassNamespace($className);
        if ($typeNamespace === null || $typeNamespace === '' || $typeNamespace === $currentNamespace || $className === $currentClassName) {
            return;
        }

        $imports[] = $typeNamespace . '\\' . $className;
    }

    /**
     * @return array<int, string>
     */
    private function extractReferencedClassNamesFromType(string $type): array
    {
        $normalized = str_replace(['array<', '>', '?'], ['', '', ''], $type);
        $parts = preg_split('/\|/', $normalized) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array(
                    $part,
                    ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'],
                    true,
                )) {
                continue;
            }

            $result[] = $part;
        }

        return array_values(array_unique($result));
    }

    private function formatClassNameForNamespace(string $className, string $currentNamespace): string
    {
        $typeNamespace = $this->getClassNamespace($className);

        return ($typeNamespace === null || $typeNamespace === '' || $typeNamespace === $currentNamespace)
            ? $className
            : $className;
    }

    private function formatPhpTypeForNamespace(string $type, string $currentNamespace): string
    {
        $parts = preg_split('/\|/', $type) ?: [];
        $formatted = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array(
                    $part,
                    ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'],
                    true,
                )) {
                $formatted[] = $part;
                continue;
            }

            $formatted[] = $this->formatClassNameForNamespace($part, $currentNamespace);
        }

        return implode('|', $formatted);
    }

    private function formatDocblockTypeForNamespace(string $type, string $currentNamespace): string
    {
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            $inner = substr($type, 6, -1);
            return 'array<' . $this->formatPhpTypeForNamespace($inner, $currentNamespace) . '>';
        }

        return $this->formatPhpTypeForNamespace($type, $currentNamespace);
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
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
            return "'" . $escaped . "'";
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $itemLiteral = $this->renderPhpLiteral($item);
                if (is_string($key)) {
                    $escapedKey = str_replace(['\\', "'"], ['\\\\', "\\'"], $key);
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
            'oneOf',
            'anyOf',
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
            $type = (string)($property['type'] ?? '');
            if ($type === 'DateTimeImmutable' || str_contains($type, 'DateTimeImmutable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     */
    private function needsUploadedFileImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = $property['type'];
            if ($type === 'UploadedFile' || str_contains($type, 'UploadedFile')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
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
     * @param array<int, SchemaProperty> $properties
     * @return array<string, SchemaProperty>
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
     * @param SchemaProperty $property
     */
    private function describePropertyType(array $property): string
    {
        return $this->composePhpTypeHint($property['type'], $property['nullable']);
    }

    private function composePhpTypeHint(string $type, bool $nullable): string
    {
        if (!$nullable) {
            return $type;
        }

        if (str_contains($type, '|')) {
            return str_contains($type, 'null') ? $type : $type . '|null';
        }

        return '?' . $type;
    }

    /**
     * @return array<int, SchemaProperty>
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
     * @return array<int, SchemaProperty>
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
                    $parentClass = $this->schemaRefToClassName(
                        $allOfItem['$ref'],
                        $this->getSchemaSourceFile($className),
                    );
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
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

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
        if ($name === '') {
            return 'value';
        }

        // Keep property names exactly as in the OpenAPI spec when they are valid PHP identifiers.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
            return $name;
        }

        // Fallback for invalid identifiers: replace unsupported characters with underscores.
        $propertyName = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;
        $propertyName = preg_replace('/_+/', '_', $propertyName) ?? $propertyName;
        $propertyName = trim($propertyName, '_');

        if ($propertyName === '') {
            return 'value';
        }

        if (is_numeric($propertyName[0])) {
            return '_' . $propertyName;
        }

        return $propertyName;
    }

    private function normalizeInRequestFlagName(string $propertyName): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $propertyName) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

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
            $camel = '_' . $camel;
        }

        return $camel . 'InRequest';
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

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $directory));
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

                        $schemaName = $this->generateInlineSchemaName($path, (string)$statusCode);
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
        return in_array(
            strtolower($method),
            ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'],
            true,
        );
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
    private function registerEnum(string $enumName, string $type, array $values, ?string $sourceFile): void
    {
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);

        if (isset($this->enumSchemas[$enumName])) {
            $existing = $this->enumSchemas[$enumName];
            if ($existing['type'] !== $type || $existing['values'] !== $values) {
                throw new RuntimeException(sprintf('Enum schema name collision for %s.', $enumName));
            }

            if (($this->enumNamespaces[$enumName] ?? $namespace) !== $namespace) {
                throw new RuntimeException(sprintf('Enum schema namespace collision for %s.', $enumName));
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
        $this->enumSourceFiles[$enumName] = $sourceFile;
        $this->enumNamespaces[$enumName] = $namespace;
        $this->enumOutputDirectories[$enumName] = $outputDirectory;
    }

    /**
     * @param array<int, string|int> $values
     */
    private function renderEnum(string $namespace, string $enumName, string $backingType, array $values): string
    {
        $usedCaseNames = [];
        $cases = [];

        foreach ($values as $value) {
            $caseName = $this->buildEnumCaseName($value, $usedCaseNames);
            $cases[] = [
                'name' => $caseName,
                'value' => $this->renderEnumValue($value, $backingType),
            ];
        }

        return $this->renderPhpTemplate('enum.php.twig', [
            'namespace' => $namespace,
            'imports' => [],
            'enumName' => $enumName,
            'backingType' => $backingType,
            'cases' => $cases,
        ]);
    }

    /**
     * @param array<string, true> $usedCaseNames
     */
    private function buildEnumCaseName(string|int $value, array &$usedCaseNames): string
    {
        $base = (string)$value;
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

            return (string)$value;
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value);
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
        if (!str_contains($phpType, '|') && !in_array(
                $phpType,
                ['int', 'float', 'string', 'bool', 'array', 'mixed'],
                true,
            )) {
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
            return ' = ' . (int)$defaultValue;
        }

        if ($phpType === 'float') {
            return ' = ' . (float)$defaultValue;
        }

        if ($phpType === 'bool') {
            return $defaultValue ? ' = true' : ' = false';
        }

        if ($phpType === 'string') {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$defaultValue);
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
