<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
 *   example: string|null,
 *   temporalFormat?: string|null,
 *   inPath?: bool,
 *   inQuery?: bool,
 *   inHeader?: bool,
 *   inCookie?: bool,
 *   parameterStyle?: string|null,
 *   parameterExplode?: bool|null,
 *   constraints?: array<string, mixed>,
 *   readOnly?: bool,
 *   writeOnly?: bool,
 *   deprecated?: bool
 * }
 * @phpstan-type SchemaMetadata array{
 *   properties: array<int, SchemaProperty>,
 *   extends: string|null,
 *   unionTypes: array<string>,
 *   discriminator: array{propertyName: string, mapping: array<string, string>}|null
 * }
 */
#[AsCommand(name: 'openapi:generate-dto', description: 'Generate readonly DTO classes from OpenAPI components.schemas')]
final class GenerateDtoCommand extends Command
{
    /** Generation mode backed by this library's runtime (DtoValidator/Normalizer/Deserializer). */
    public const string ATTRIBUTE_MODE_RUNTIME = 'runtime';

    /** Generation mode emitting Symfony Validator/Serializer attributes (no library runtime). */
    public const string ATTRIBUTE_MODE_SYMFONY = 'symfony';

    /** @var array<int, string> */
    public const array ATTRIBUTE_MODES = [self::ATTRIBUTE_MODE_RUNTIME, self::ATTRIBUTE_MODE_SYMFONY];

    public ?Environment $twig = null;

    /** @var array<string, array<mixed>> */
    public array $dtoSchemas = [];

    /** @var array<string, array{type: string, values: array<int, string|int>, caseNames: array<int, string>, descriptions: array<int, ?string>}> */
    public array $enumSchemas = [];

    /** @var array<string, true> */
    public array $parentClasses = [];

    /** @var array<string, array<int, string>> */
    public array $unionInterfacesByClass = [];

    /** @var array<string, array{propertyName: string, mapping: array<string, string>}> */
    public array $discriminatorSchemas = [];

    /** @var array<string, string|null> */
    public array $schemaSourceFiles = [];

    /** @var array<string, string> */
    public array $schemaNamespaces = [];

    /** @var array<string, string> */
    public array $schemaOutputDirectories = [];

    /** @var array<string, string|null> */
    public array $enumSourceFiles = [];

    /** @var array<string, string> */
    public array $enumNamespaces = [];

    /** @var array<string, string> */
    public array $enumOutputDirectories = [];

    /** @var array<string, true> */
    public array $loadedExternalFiles = [];

    public ?string $rootSpecFile = null;
    public string $baseOutputDirectory = '';
    public string $baseNamespace = '';

    /**
     * Generation mode. 'runtime' (default) emits DTOs backed by this library's
     * DtoValidator/DtoNormalizer/DtoDeserializer. 'symfony' emits plain data classes
     * decorated with Symfony Validator/Serializer attributes, validated and mapped by
     * Symfony itself (no copy-runtime).
     */
    public string $attributeMode = self::ATTRIBUTE_MODE_RUNTIME;

    /**
     * Explicit per-external-ref placement, keyed by the canonical (realpath) ref file. When an
     * external $ref resolves to a mapped file, its schemas go to the mapped output directory /
     * namespace instead of the default spec-name-derived placement. Set via --ref / --ref-namespace.
     *
     * @var array<string, string>
     */
    public array $refOutputDirectoryMap = [];

    /** @var array<string, string> */
    public array $refNamespaceMap = [];

    public string $generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\\Contract\\GeneratedDtoInterface';
    public string $unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\\Contract\\UnsetValue';

    protected function configure(): void
    {
        $this->addOption(
            name: 'file',
            shortcut: 'f',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Path to OpenAPI yaml file',
        );
        $this->addOption(
            name: 'directory',
            shortcut: 'd',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Output directory for generated DTO classes',
        );
        $this->addOption(
            name: 'namespace',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Namespace for generated DTO classes (overrides directory-derived namespace)',
        );
        $this->addOption(
            name: 'dto-generator-directory',
            shortcut: null,
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Copy DTO generator services to specified subdirectory (can be absolute path)',
            default: false,
        );
        $this->addOption(
            name: 'dto-generator-namespace',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Custom namespace for DTO generator services',
        );
        $this->addOption(
            name: 'attributes',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Generation mode: "runtime" (default, library runtime) or "symfony" (Symfony Validator/Serializer attributes)',
            default: self::ATTRIBUTE_MODE_RUNTIME,
        );
        $this->addOption(
            name: 'ref',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Explicit output directory for an external $ref file or directory: "<refFileOrDir>=<directory>". A directory key maps every ref\'d file inside it. Repeatable. Requires a matching --ref-namespace.',
        );
        $this->addOption(
            name: 'ref-namespace',
            shortcut: null,
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Explicit namespace for an external $ref file or directory: "<refFileOrDir>=<namespace>". A directory key maps every ref\'d file inside it. Repeatable. Requires a matching --ref.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // File path must be provided only via --file
        $fileOption = $input->getOption('file');
        $file = is_string($fileOption) ? trim($fileOption) : '';
        $directoryOption = $input->getOption('directory');
        $directory = is_string($directoryOption) ? trim($directoryOption) : '';
        $namespaceOption = $input->getOption('namespace');
        $namespaceOption = is_string($namespaceOption) ? trim($namespaceOption) : '';
        $attributesOption = $input->getOption('attributes');
        $mode = is_string($attributesOption) && $attributesOption !== ''
            ? $attributesOption
            : self::ATTRIBUTE_MODE_RUNTIME;

        if (!in_array($mode, self::ATTRIBUTE_MODES, true)) {
            $io->error('Option --attributes must be "runtime" or "symfony".');
            return Command::FAILURE;
        }

        if ($file === '') {
            $io->error('Option --file is required. Example: --file=OpenApiExamples/test.yaml');
            return Command::FAILURE;
        }

        if ($directory === '') {
            $io->error('Option --directory is required. Example: --directory=generated/test');
            return Command::FAILURE;
        }

        if ($input->hasParameterOption('--namespace') && $namespaceOption === '') {
            $io->error('Option --namespace cannot be empty. Example: --namespace=Generated\\Test');
            return Command::FAILURE;
        }

        if (!is_file($file)) {
            $io->error(sprintf('File not found: %s', $file));
            return Command::FAILURE;
        }

        $outputDirectory = $this->resolveOutputDirectory($directory);
        $namespace = $namespaceOption !== ''
            ? $this->normalizeExplicitNamespace($namespaceOption)
            : $this->directoryToNamespace($directory);

        $dtoGeneratorDirectoryOption = $input->getOption('dto-generator-directory');
        $dtoGeneratorDirectory = null;
        $dtoGeneratorNamespace = null;

        if ($dtoGeneratorDirectoryOption !== false) {
            $dtoGeneratorDirectory = is_string($dtoGeneratorDirectoryOption)
                ? $dtoGeneratorDirectoryOption
                : 'Common';
            $dtoGeneratorNamespaceOption = $input->getOption('dto-generator-namespace');
            $dtoGeneratorNamespaceOption = is_string($dtoGeneratorNamespaceOption)
                ? $dtoGeneratorNamespaceOption
                : null;
            $dtoGeneratorNamespace = $dtoGeneratorNamespaceOption;

            // Keep existing CLI behavior: when custom directory is provided without namespace,
            // derive namespace from directory path itself.
            if ($dtoGeneratorNamespace === null && $dtoGeneratorDirectory !== 'Common') {
                $dtoGeneratorNamespace = $this->directoryToNamespace($dtoGeneratorDirectory);
            }

            $dtoGeneratorNamespace = $this->resolveDtoGeneratorTargetNamespace(
                namespace: $namespace,
                dtoGeneratorDirectory: $dtoGeneratorDirectory,
                dtoGeneratorNamespace: $dtoGeneratorNamespace,
            );
            $this->generatedDtoInterfaceImportFqcn = $dtoGeneratorNamespace . '\\' . 'GeneratedDtoInterface';
            $this->unsetValueImportFqcn = $dtoGeneratorNamespace . '\\' . 'UnsetValue';
        } else {
            $this->generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\\Contract\\GeneratedDtoInterface';
            $this->unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\\Contract\\UnsetValue';
        }

        $refOption = $input->getOption('ref');
        $refNamespaceOption = $input->getOption('ref-namespace');
        $refPairs = is_array($refOption) ? array_values(array_filter($refOption, 'is_string')) : [];
        $refNamespacePairs = is_array($refNamespaceOption) ? array_values(array_filter($refNamespaceOption, 'is_string')) : [];

        try {
            $this->setExternalRefMappings($refPairs, $refNamespacePairs);

            $count = $this->generateFromFile(filePath: $file, outputDirectory: $outputDirectory, namespace: $namespace, mode: $mode);

            if ($dtoGeneratorDirectory !== null) {
                $this->copyCommonServices(
                    outputDirectory: $outputDirectory,
                    namespace: $namespace,
                    dtoGeneratorDirectory: $dtoGeneratorDirectory,
                    dtoGeneratorNamespace: $dtoGeneratorNamespace,
                );
            }
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(
            sprintf('Generated %d DTO class(es) in %s with namespace %s.', $count, $outputDirectory, $namespace),
        );

        return Command::SUCCESS;
    }

    public function generateFromFile(string $filePath, string $outputDirectory, string $namespace, string $mode = self::ATTRIBUTE_MODE_RUNTIME): int
    {
        $this->setAttributeMode($mode);

        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File not found: %s', $filePath));
        }

        $data = $this->parseSpecFile($filePath);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAPI root must be an object/array.');
        }

        $realFilePath = realpath($filePath);
        if ($realFilePath === false) {
            throw new RuntimeException(sprintf('Cannot resolve real path for file: %s', $filePath));
        }

        $this->initializeGeneration(
            outputDirectory: $outputDirectory,
            namespace: $namespace,
            rootSpecFile: $realFilePath,
        );
        $this->registerDocumentSchemas(openApi: $data, sourceFile: $realFilePath, includeInlineSchemas: true);
        $this->scanExternalSchemaRefs(node: $data, currentSourceFile: $realFilePath);

        return $this->finalizeGeneration();
    }

    /**
     * Parses an OpenAPI document from disk. A `.json` spec is decoded with json_decode
     * for strict, fast parsing and clear JSON error messages; every other extension is
     * parsed as YAML (which also accepts JSON, so unknown extensions still work).
     */
    private function parseSpecFile(string $filePath): mixed
    {
        // Strip common env/sample suffixes so `openapi.json.dist` still takes the JSON path.
        $effectivePath = preg_replace('/\.(dist|example|local|sample)$/i', '', $filePath) ?? $filePath;

        if (strtolower(pathinfo($effectivePath, PATHINFO_EXTENSION)) === 'json') {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new RuntimeException(sprintf('Cannot read file: %s', $filePath));
            }

            try {
                // Depth well above JSON's default 512: deeply nested (but valid) specs must
                // not be misreported as malformed JSON.
                return json_decode($contents, associative: true, depth: 4096, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(
                    sprintf('Invalid JSON in %s: %s', $filePath, $e->getMessage()),
                    previous: $e,
                );
            }
        }

        return Yaml::parseFile($filePath);
    }

    /**
     * @param array<mixed> $openApi
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace, string $mode = self::ATTRIBUTE_MODE_RUNTIME): int
    {
        $this->setAttributeMode($mode);
        $this->initializeGeneration(outputDirectory: $outputDirectory, namespace: $namespace, rootSpecFile: null);

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

            $className = $this->normalizeClassName($schemaName);
            $this->registerSchema(className: $className, schemaDefinition: $schemaDefinition, sourceFile: null);
        }

        return $this->finalizeGeneration();
    }

    private function setAttributeMode(string $mode): void
    {
        if (!in_array($mode, self::ATTRIBUTE_MODES, true)) {
            throw new RuntimeException(sprintf('Unknown generation mode: %s (expected "runtime" or "symfony").', $mode));
        }

        $this->attributeMode = $mode;
    }

    /**
     * Configures explicit per-external-ref placement. Each entry maps a ref spec file to an output
     * directory (--ref) and a namespace (--ref-namespace). Both are required for a given file. Keys
     * are canonicalised (realpath) so they match the resolved ref target regardless of how the
     * `$ref` is written. When a ref file is not mapped, the default placement is used.
     *
     * @param array<int, string> $refDirectoryPairs `<refFile>=<outputDirectory>` entries
     * @param array<int, string> $refNamespacePairs `<refFile>=<namespace>` entries
     */
    public function setExternalRefMappings(array $refDirectoryPairs, array $refNamespacePairs): void
    {
        $directoryMap = $this->parseRefPairs($refDirectoryPairs, '--ref');
        $namespaceMap = $this->parseRefPairs($refNamespacePairs, '--ref-namespace');

        foreach (array_keys($directoryMap) as $file) {
            if (!array_key_exists($file, $namespaceMap)) {
                throw new RuntimeException(sprintf('--ref for "%s" requires a matching --ref-namespace.', $file));
            }
        }
        foreach (array_keys($namespaceMap) as $file) {
            if (!array_key_exists($file, $directoryMap)) {
                throw new RuntimeException(sprintf('--ref-namespace for "%s" requires a matching --ref.', $file));
            }
        }

        $this->refOutputDirectoryMap = $directoryMap;
        $this->refNamespaceMap = $namespaceMap;
    }

    /**
     * @param array<int, string> $pairs
     * @return array<string, string>
     */
    private function parseRefPairs(array $pairs, string $optionName): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            $position = strpos($pair, '=');
            if ($position === false || $position === 0) {
                throw new RuntimeException(sprintf('Invalid %s value "%s" (expected "<refFile>=<value>").', $optionName, $pair));
            }

            $file = trim(substr($pair, 0, $position));
            $value = trim(substr($pair, $position + 1));
            if ($file === '' || $value === '') {
                throw new RuntimeException(sprintf('Invalid %s value "%s" (empty file or value).', $optionName, $pair));
            }

            $map[$this->canonicalizeRefPath($file)] = $value;
        }

        return $map;
    }

    private function canonicalizeRefPath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    /**
     * Resolves an external $ref source file against a --ref map. A map key may be either a single
     * file (exact match) or a directory (matches any file inside it, recursively). An exact file
     * key wins over a containing-directory key.
     *
     * @param array<string, string> $map
     */
    private function matchRefMapping(string $sourceFile, array $map): ?string
    {
        if ($map === []) {
            return null;
        }

        $canonical = $this->canonicalizeRefPath($sourceFile);

        if (array_key_exists($canonical, $map)) {
            return $map[$canonical];
        }

        foreach ($map as $key => $value) {
            if (is_dir($key) && str_starts_with($canonical, $key . DIRECTORY_SEPARATOR)) {
                return $value;
            }
        }

        return null;
    }

    public function copyCommonServices(
        string $outputDirectory,
        string $namespace,
        ?string $dtoGeneratorDirectory = null,
        ?string $dtoGeneratorNamespace = null,
    ): void {
        $dtoGeneratorDirectory ??= 'Common';

        // If dtoGeneratorDirectory is a relative path, calculate it from current directory
        if (
            str_starts_with($dtoGeneratorDirectory, '/') || (strlen(
                $dtoGeneratorDirectory,
            ) > 1 && $dtoGeneratorDirectory[1] === ':')
        ) {
            $commonDir = rtrim($dtoGeneratorDirectory, '/');
        } elseif ($dtoGeneratorDirectory === 'Common') {
            // Special case: if default 'Common' value is used,
            // maintain backward compatibility and copy it inside $outputDirectory
            $commonDir = rtrim($outputDirectory, '/') . '/Common';
        } else {
            $cwd = getcwd();
            $workingDirectory = $cwd !== false ? $cwd : '.';
            $commonDir = rtrim($workingDirectory . '/' . ltrim($dtoGeneratorDirectory, '/'), '/');
        }

        $this->ensureDirectoryExists($commonDir);
        $this->deleteDirectoryContents($commonDir);

        $filesToCopy = [
            'Contract/GeneratedDtoInterface.php',
            'Contract/UnsetValue.php',
            'Contract/DtoNormalizerInterface.php',
            'Contract/DtoValidatorInterface.php',
            'Contract/DtoDeserializerInterface.php',
            'Service/DtoNormalizer.php',
            'Service/DtoValidator.php',
            'Service/DtoDeserializer.php',
        ];

        $sourceBase = dirname(__DIR__);
        $targetNamespace = $this->resolveDtoGeneratorTargetNamespace(
            namespace: $namespace,
            dtoGeneratorDirectory: $dtoGeneratorDirectory,
            dtoGeneratorNamespace: $dtoGeneratorNamespace,
        );

        foreach ($filesToCopy as $relativePath) {
            $sourcePath = realpath($sourceBase . '/' . $relativePath);
            if ($sourcePath === false) {
                continue;
            }

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                continue;
            }

            // Move all files to a single Common namespace, removing Contract/Service separation
            $content = preg_replace(
                '/namespace OpenapiPhpDtoGenerator\\\\(Contract|Service);/',
                'namespace ' . $targetNamespace . ';',
                $content,
            ) ?? $content;

            $content = preg_replace(
                '/use OpenapiPhpDtoGenerator\\\\(Contract|Service)\\\\/',
                'use ' . $targetNamespace . '\\',
                $content,
            ) ?? $content;

            // Remove self-namespace imports (same namespace as target)
            $content = preg_replace(
                '/^use ' . preg_quote($targetNamespace, '/') . '\\\\[^;]+;\n/m',
                '',
                $content,
            ) ?? $content;

            $fileName = basename($relativePath);
            file_put_contents($commonDir . '/' . $fileName, $content);
        }
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
    }

    private function resolveDtoGeneratorTargetNamespace(
        string $namespace,
        string $dtoGeneratorDirectory,
        ?string $dtoGeneratorNamespace,
    ): string {
        if (is_string($dtoGeneratorNamespace) && trim($dtoGeneratorNamespace) !== '') {
            return trim($dtoGeneratorNamespace, '\\');
        }

        // Keep BC for copyCommonServices direct calls.
        return rtrim($namespace, '\\') . '\\' . str_replace('/', '\\', $dtoGeneratorDirectory);
    }

    private function finalizeGeneration(): int
    {
        $this->expandNestedSchemas();
        $this->detectParentClasses();
        $this->detectUnionInterfaces();
        $this->prepareOutputDirectory($this->baseOutputDirectory);

        $generatedCount = 0;

        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            $schemaMetadata = $this->analyzeSchema(className: $className, schemaDefinition: $schemaDefinition);
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
                caseNames: $enumDefinition['caseNames'],
                descriptions: $enumDefinition['descriptions'],
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

            if ($this->isPureExternalSchemaAlias($schemaDefinition)) {
                $externalRef = $schemaDefinition['$ref'];
                if (is_string($externalRef)) {
                    $this->ensureSchemaRefRegistered(ref: $externalRef, currentSourceFile: $sourceFile);
                }
                continue;
            }

            $className = $this->normalizeClassName($schemaName);
            $this->registerSchema(className: $className, schemaDefinition: $schemaDefinition, sourceFile: $sourceFile);
        }
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function isPureExternalSchemaAlias(array $schemaDefinition): bool
    {
        if (count($schemaDefinition) !== 1) {
            return false;
        }

        $ref = $schemaDefinition['$ref'] ?? null;

        return is_string($ref)
            && $ref !== ''
            && !str_starts_with($ref, '#/components/schemas/');
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
            $this->registerEnum(
                enumName: $className,
                type: $type,
                values: $values,
                sourceFile: $sourceFile,
                varnames: $this->extractEnumVarnames($schemaDefinition, $values),
                descriptions: $this->extractEnumDescriptions($schemaDefinition, $values),
            );
            return;
        }

        if (array_key_exists($className, $this->dtoSchemas)) {
            if ($this->dtoSchemas[$className] !== $schemaDefinition) {
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
        $this->collectDiscriminatorMetadata(className: $className, schemaDefinition: $schemaDefinition);
    }

    private function resolveNamespaceForSourceFile(?string $sourceFile): string
    {
        if ($sourceFile === null || $this->rootSpecFile === null || $sourceFile === $this->rootSpecFile) {
            return $this->baseNamespace;
        }

        // Explicit --ref-namespace mapping takes highest precedence.
        $explicit = $this->matchRefMapping($sourceFile, $this->refNamespaceMap);
        if ($explicit !== null) {
            return $explicit;
        }

        $sharedNamespace = $this->resolveCommonNamespaceForSourceFile($sourceFile);
        if ($sharedNamespace !== null) {
            return $sharedNamespace;
        }

        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return $this->baseNamespace;
        }

        $segments = array_values(
            array_filter(
                explode('/', $relativeDirectory),
                static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
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

        // Explicit --ref mapping takes highest precedence.
        $explicit = $this->matchRefMapping($sourceFile, $this->refOutputDirectoryMap);
        if ($explicit !== null) {
            return $explicit;
        }

        $sharedOutputDirectory = $this->resolveCommonOutputDirectoryForSourceFile($sourceFile);
        if ($sharedOutputDirectory !== null) {
            return $sharedOutputDirectory;
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
        if ($this->rootSpecFile === null || $this->rootSpecFile === '') {
            return '';
        }

        // Relative path is calculated from root spec file path (not its directory),
        // so external refs naturally go one level up (../common, ../../test/common, etc.).
        $relativeFile = $this->makeRelativePath(fromDirectory: $this->rootSpecFile, toPath: $sourceFile);
        $relativeDirectory = dirname($relativeFile);

        return $relativeDirectory === '.' ? '' : trim(str_replace('\\', '/', $relativeDirectory));
    }

    private function resolveCommonNamespaceForSourceFile(string $sourceFile): ?string
    {
        $relativeSegments = $this->resolveNormalizedRelativeDirectorySegments($sourceFile);
        if ($relativeSegments === [] || strtolower($relativeSegments[0]) !== 'common') {
            return null;
        }

        $baseNamespaceParts = array_values(
            array_filter(explode('\\', $this->baseNamespace), static fn(string $part): bool => $part !== ''),
        );
        if ($baseNamespaceParts === []) {
            return null;
        }

        $rebasedNamespaceParts = $this->rebasePartsForCommonRoot(parts: $baseNamespaceParts, commonSegment: 'Common');
        if ($rebasedNamespaceParts === null) {
            return null;
        }

        $suffixParts = array_map(
            fn(string $segment): string => $this->normalizeClassName($segment),
            array_slice($relativeSegments, 1),
        );

        return implode('\\', [...$rebasedNamespaceParts, ...$suffixParts]);
    }

    private function resolveCommonOutputDirectoryForSourceFile(string $sourceFile): ?string
    {
        $relativeSegments = $this->resolveNormalizedRelativeDirectorySegments($sourceFile);
        if ($relativeSegments === [] || strtolower($relativeSegments[0]) !== 'common') {
            return null;
        }

        $baseOutputParts = $this->splitPathSegments($this->baseOutputDirectory);
        if ($baseOutputParts === []) {
            return null;
        }

        $rebasedOutputParts = $this->rebasePartsForCommonRoot(parts: $baseOutputParts, commonSegment: 'Common');
        if ($rebasedOutputParts === null) {
            return null;
        }

        $targetParts = [...$rebasedOutputParts, ...array_slice($relativeSegments, 1)];
        if ($targetParts === []) {
            return $this->baseOutputDirectory;
        }

        $prefix = str_starts_with($this->baseOutputDirectory, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';

        return $prefix . implode(DIRECTORY_SEPARATOR, $targetParts);
    }

    /**
     * @return array<int, string>
     */
    private function resolveNormalizedRelativeDirectorySegments(string $sourceFile): array
    {
        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return [];
        }

        return array_values(
            array_filter(
                explode('/', $relativeDirectory),
                static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
            ),
        );
    }

    /**
     * @param array<int, string> $parts
     * @return array<int, string>|null
     */
    private function rebasePartsForCommonRoot(array $parts, string $commonSegment): ?array
    {
        $rootSpecSegment = $this->getRootSpecSegmentName();
        if ($rootSpecSegment === null) {
            return null;
        }

        for ($index = count($parts) - 1; $index >= 0; $index--) {
            if ($this->normalizeClassName($parts[$index]) !== $rootSpecSegment) {
                continue;
            }

            $parts[$index] = $commonSegment;
            return $parts;
        }

        return null;
    }

    private function getRootSpecSegmentName(): ?string
    {
        if ($this->rootSpecFile === null || $this->rootSpecFile === '') {
            return null;
        }

        $rootSpecName = pathinfo($this->rootSpecFile, PATHINFO_FILENAME);
        if ($rootSpecName === '') {
            return null;
        }

        return $this->normalizeClassName($rootSpecName);
    }

    /**
     * @return array<int, string>
     */
    private function splitPathSegments(string $path): array
    {
        return array_values(
            array_filter(
                explode('/', str_replace('\\', '/', $path)),
                static fn(string $part): bool => $part !== '',
            ),
        );
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
                $this->ensureSchemaRefRegistered(ref: $value, currentSourceFile: $currentSourceFile);
                continue;
            }

            if (is_array($value)) {
                $this->scanExternalSchemaRefs(node: $value, currentSourceFile: $currentSourceFile);
            }
        }
    }

    private function ensureSchemaRefRegistered(string $ref, ?string $currentSourceFile): void
    {
        if (str_starts_with($ref, '#/components/schemas/')) {
            return;
        }

        $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $currentSourceFile);
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
        if (array_key_exists($filePath, $this->loadedExternalFiles)) {
            return;
        }

        $this->loadedExternalFiles[$filePath] = true;
        $data = $this->parseSpecFile($filePath);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('OpenAPI root must be an object/array in %s.', $filePath));
        }

        $this->registerDocumentSchemas(openApi: $data, sourceFile: $filePath, includeInlineSchemas: false);
        $this->scanExternalSchemaRefs(node: $data, currentSourceFile: $filePath);
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

            $targetClass = $this->schemaRefToClassName(
                ref: $ref,
                currentSourceFile: $this->getSchemaSourceFile($className),
            );
            if ($targetClass === 'mixed') {
                throw new RuntimeException(
                    sprintf(
                        'Discriminator mapping value for "%s" in %s must reference #/components/schemas/*.',
                        $mappingValue,
                        $className,
                    ),
                );
            }

            if (array_key_exists($targetClass, $targetToSource)) {
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
                $this->collectNestedFromSchema(ownerClassName: $className, schemaDefinition: $schemaDefinition);
            }
        }
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function collectNestedFromSchema(string $ownerClassName, array $schemaDefinition): void
    {
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem)) {
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
            if (!array_key_exists('allOf', $schemaDefinition) || !is_array($schemaDefinition['allOf'])) {
                continue;
            }

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem) || !array_key_exists('$ref', $allOfItem) || !is_string($allOfItem['$ref'])) {
                    continue;
                }

                $parentClass = $this->schemaRefToClassName(
                    ref: $allOfItem['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
                $this->parentClasses[$parentClass] = true;
            }
        }
    }

    private function detectUnionInterfaces(): void
    {
        foreach ($this->dtoSchemas as $schemaName => $schemaDefinition) {
            if (!array_key_exists('oneOf', $schemaDefinition) && !array_key_exists('anyOf', $schemaDefinition)) {
                continue;
            }

            $className = $this->normalizeClassName($schemaName);

            foreach (
                $this->collectUnionTypes(
                    ownerClassName: $className,
                    variants: $schemaDefinition['oneOf'] ?? [],
                    keyword: 'oneOf',
                ) as $unionClass
            ) {
                $this->unionInterfacesByClass[$unionClass][] = $className;
            }

            foreach (
                $this->collectUnionTypes(
                    ownerClassName: $className,
                    variants: $schemaDefinition['anyOf'] ?? [],
                    keyword: 'anyOf',
                ) as $unionClass
            ) {
                $this->unionInterfacesByClass[$unionClass][] = $className;
            }
        }
    }

    /**
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

            if (array_key_exists('$ref', $variant) && is_string($variant['$ref'])) {
                $result[] = $this->schemaRefToClassName(
                    ref: $variant['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                continue;
            }

            if (!$this->isInlineObjectVariant($variant)) {
                continue;
            }

            $suffix = $keyword === 'oneOf' ? 'OneOf' : 'AnyOf';
            $variantClassName = $ownerClassName . $suffix . ($index + 1);
            $this->registerSchema(
                className: $variantClassName,
                schemaDefinition: $variant,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
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
        return array_key_exists('properties', $variant) && is_array($variant['properties']);
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @return SchemaMetadata
     */
    private function analyzeSchema(string $className, array $schemaDefinition): array
    {
        $extends = null;
        $unionTypes = [];

        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            $allProperties = [];
            $refCount = 0;
            $firstRef = null;

            // Count how many $refs we have
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (is_array($allOfItem) && array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
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

                if (array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    if ($useInheritance) {
                        $extends = $this->schemaRefToClassName(
                            ref: $allOfItem['$ref'],
                            currentSourceFile: $this->getSchemaSourceFile($className),
                        );
                    } else {
                        // Multiple $refs: collect properties from referenced schema
                        $refClassName = $this->schemaRefToClassName(
                            ref: $allOfItem['$ref'],
                            currentSourceFile: $this->getSchemaSourceFile($className),
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

            // Multiple merged branches can declare the same property with conflicting types.
            // The inheritance path rejects such conflicts; the merge path must too, instead of
            // silently keeping whichever branch happens to be last.
            $this->assertMergedPropertiesCompatible($allProperties, $className);

            return [
                'properties' => $allProperties,
                'extends' => $extends,
                'unionTypes' => [],
                'discriminator' => $this->discriminatorSchemas[$className] ?? null,
            ];
        }

        if (array_key_exists('oneOf', $schemaDefinition) && is_array($schemaDefinition['oneOf'])) {
            $unionTypes = $this->collectUnionTypes(
                ownerClassName: $className,
                variants: $schemaDefinition['oneOf'],
                keyword: 'oneOf',
            );

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        if (array_key_exists('anyOf', $schemaDefinition) && is_array($schemaDefinition['anyOf'])) {
            $unionTypes = $this->collectUnionTypes(
                ownerClassName: $className,
                variants: $schemaDefinition['anyOf'],
                keyword: 'anyOf',
            );

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        return [
            'properties' => $this->extractProperties(schemaDefinition: $schemaDefinition, ownerClassName: $className),
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
        /** @var array<string, string> $normalizedToOpenApiName */
        $normalizedToOpenApiName = [];

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
            $isRequired = array_key_exists($openApiPropertyName, $requiredMap);
            $nullable = $nullableBySchema || !$isRequired;
            $default = $this->extractDefaultValue($propertySchema, $type);
            $description = $this->extractDescription($propertySchema);
            $example = $this->extractExample($propertySchema);
            $temporalFormat = $this->resolveTemporalPhpDocFormat($propertySchema);
            $constraints = $this->extractValidationConstraints($propertySchema);

            $paramIn = $propertySchema['x-parameter-in'] ?? null;
            $isInPath = $paramIn === 'path';
            $isInQuery = $paramIn === 'query';
            $isInHeader = $paramIn === 'header';
            $isInCookie = $paramIn === 'cookie';
            $parameterStyle = is_string($propertySchema['x-parameter-style'] ?? null)
                ? $propertySchema['x-parameter-style']
                : null;
            $parameterExplode = is_bool($propertySchema['x-parameter-explode'] ?? null)
                ? $propertySchema['x-parameter-explode']
                : null;

            $normalizedName = $this->normalizePropertyName($openApiPropertyName);
            $alreadyMappedOpenApiName = $normalizedToOpenApiName[$normalizedName] ?? null;
            if ($alreadyMappedOpenApiName !== null && $alreadyMappedOpenApiName !== $openApiPropertyName) {
                throw new RuntimeException(sprintf(
                    'Property name collision in %s: "%s" and "%s" normalize to "$%s".',
                    $ownerClassName,
                    $alreadyMappedOpenApiName,
                    $openApiPropertyName,
                    $normalizedName,
                ));
            }
            $normalizedToOpenApiName[$normalizedName] = $openApiPropertyName;

            $result[] = [
                'name' => $normalizedName,
                'openApiName' => $openApiPropertyName,
                'type' => $type,
                'nullable' => $nullable,
                'required' => $isRequired,
                'default' => $default,
                'description' => $description,
                'example' => $example,
                'temporalFormat' => $temporalFormat,
                'inPath' => $isInPath,
                'inQuery' => $isInQuery,
                'inHeader' => $isInHeader,
                'inCookie' => $isInCookie,
                'parameterStyle' => $parameterStyle,
                'parameterExplode' => $parameterExplode,
                'constraints' => $constraints,
                'readOnly' => (bool)($propertySchema['readOnly'] ?? false),
                'writeOnly' => (bool)($propertySchema['writeOnly'] ?? false),
                'deprecated' => (bool)($propertySchema['deprecated'] ?? false),
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
        $propertySchema = $this->normalizeNullableBranchInAllOf($propertySchema);

        $allowedKeys = [
            'type',
            'nullable',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
            'pattern',
            'format',
            'minItems',
            'maxItems',
            'uniqueItems',
            'items',
            'contains',
            'minContains',
            'maxContains',
            'prefixItems',
            'const',
            'if',
            'then',
            'else',
            // Object-level constraints. DtoValidator enforces these against inline
            // objects / map types (additionalProperties: {schema}) that are NOT
            // materialized into a dedicated nested DTO.
            'required',
            'properties',
            'additionalProperties',
            'minProperties',
            'maxProperties',
            'dependentRequired',
            'dependentSchemas',
            'patternProperties',
            'propertyNames',
        ];

        // NOTE: 'enum' is intentionally NOT forwarded. A property-level enum is
        // materialized into a PHP backed enum and the value is cast to an enum
        // instance before constraint validation; DtoValidator's enum check uses a
        // strict in_array() against scalar values, so forwarding it would always
        // false-positive on the cast enum instance.

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

            // If any variant extracts to [] (e.g. a bare $ref the validator can't resolve),
            // the oneOf/anyOf match count can't be enforced soundly — an empty branch is
            // vacuously satisfied, which would over-count oneOf (false "more than one") and
            // make anyOf always pass. In that case drop the whole keyword.
            $branchConstraints = [];
            $hasUnvalidatableBranch = false;
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $extracted = $this->extractValidationConstraints($variant);
                if ($extracted === []) {
                    $hasUnvalidatableBranch = true;
                    break;
                }
                $branchConstraints[] = $extracted;
            }

            if (!$hasUnvalidatableBranch && $branchConstraints !== []) {
                $constraints[$unionKey] = $branchConstraints;
            }
        }

        // allOf: every branch must pass. Branches are usually `$ref`s the validator
        // cannot resolve, so recurse and keep only branches that carry actionable
        // constraints; a fully-unresolvable allOf is dropped entirely (no-op noise).
        $allOf = $propertySchema['allOf'] ?? null;
        if (is_array($allOf) && $allOf !== []) {
            $branches = [];
            foreach ($allOf as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $extracted = $this->extractValidationConstraints($branch);
                if ($extracted !== []) {
                    $branches[] = $extracted;
                }
            }
            if ($branches !== []) {
                $constraints['allOf'] = $branches;
            }
        }

        // not: value must NOT match the subschema. Forwarded only when the recursively
        // extracted subschema has actionable constraints — a `$ref`-only `not` would
        // extract to an empty schema that every value vacuously satisfies, making the
        // validator's "must not match" check fire a false positive on every value.
        $not = $propertySchema['not'] ?? null;
        if (is_array($not)) {
            $extractedNot = $this->extractValidationConstraints($not);
            if ($extractedNot !== []) {
                $constraints['not'] = $extractedNot;
            }
        }

        // Recursively scrub subschema-bearing keys: a $ref (or any unvalidatable subschema)
        // extracts to [], which the validator can't resolve. Forwarding it verbatim is unsafe
        // — most dangerously `if: {$ref}` would extract to a vacuously-true schema and apply
        // `then` to every value. Drop what becomes empty (same guard as oneOf/anyOf/allOf/not).
        $constraints = $this->scrubUnvalidatableSubschemas($constraints);

        if (($propertySchema['readOnly'] ?? false) === true) {
            $constraints['readOnly'] = true;
        }

        // OpenAPI 3.1: type: [string, null] → normalize to type: string, nullable: true
        if (array_key_exists('type', $constraints) && is_array($constraints['type'])) {
            $nonNullTypes = array_values(
                array_filter($constraints['type'], static fn(mixed $t): bool => is_string($t) && $t !== 'null'),
            );
            if (count($nonNullTypes) < count($constraints['type'])) {
                $constraints['nullable'] = true;
            }
            $constraints['type'] = count($nonNullTypes) === 1 ? $nonNullTypes[0] : $nonNullTypes;
            if ($constraints['type'] === []) {
                unset($constraints['type']);
            }
        }

        return $constraints;
    }

    /**
     * Recursively re-extracts subschema-bearing constraint keys so that $ref-only / otherwise
     * unvalidatable subschemas don't survive verbatim into the generated constraints (the
     * validator can't resolve $ref and would either silently skip or — for `if` — falsely match).
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function scrubUnvalidatableSubschemas(array $constraints): array
    {
        // Single-subschema keys: drop the key entirely when it extracts to nothing.
        foreach (['items', 'contains', 'propertyNames', 'if', 'then', 'else'] as $key) {
            if (!array_key_exists($key, $constraints) || !is_array($constraints[$key])) {
                continue;
            }
            $extracted = $this->extractValidationConstraints($constraints[$key]);
            if ($extracted === []) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $extracted;
            }
        }

        // additionalProperties may be a bool (keep) or a schema (scrub).
        if (array_key_exists('additionalProperties', $constraints) && is_array($constraints['additionalProperties'])) {
            $extracted = $this->extractValidationConstraints($constraints['additionalProperties']);
            if ($extracted === []) {
                unset($constraints['additionalProperties']);
            } else {
                $constraints['additionalProperties'] = $extracted;
            }
        }

        // Schema-map keys: scrub each value, drop empties; drop the whole key if none remain.
        foreach (['properties', 'patternProperties', 'dependentSchemas'] as $key) {
            if (!array_key_exists($key, $constraints) || !is_array($constraints[$key])) {
                continue;
            }
            $scrubbed = [];
            foreach ($constraints[$key] as $name => $subSchema) {
                if (!is_array($subSchema)) {
                    continue;
                }
                $extracted = $this->extractValidationConstraints($subSchema);
                if ($extracted !== []) {
                    $scrubbed[$name] = $extracted;
                }
            }
            if ($scrubbed === []) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $scrubbed;
            }
        }

        // prefixItems is positional — keep an empty slot as [] (a no-op constraint) rather than
        // shifting indices.
        if (array_key_exists('prefixItems', $constraints) && is_array($constraints['prefixItems'])) {
            $scrubbed = [];
            foreach ($constraints['prefixItems'] as $subSchema) {
                $scrubbed[] = is_array($subSchema) ? $this->extractValidationConstraints($subSchema) : [];
            }
            $constraints['prefixItems'] = $scrubbed;
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

        $propertySchema = $this->normalizeNullableBranchInAllOf($propertySchema);
        $nullable = (bool)($propertySchema['nullable'] ?? false);

        if (array_key_exists('allOf', $propertySchema) && is_array($propertySchema['allOf'])) {
            $normalizedAllOf = $this->normalizeAllOfPropertySchema($propertySchema);
            if ($normalizedAllOf !== null) {
                return $this->resolvePropertyType(
                    propertySchema: $normalizedAllOf,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
            }

            // Keep legacy allOf behavior for refs/objects: single ref -> ref type, multi-part -> merged DTO.
            if (count($propertySchema['allOf']) === 1 && array_key_exists('$ref', $propertySchema['allOf'][0])) {
                $binaryType = $this->resolveBinaryRefType((string)$propertySchema['allOf'][0]['$ref']);
                if ($binaryType !== null) {
                    return [$binaryType, $nullable];
                }

                $temporalType = $this->resolveTemporalRefType((string)$propertySchema['allOf'][0]['$ref']);
                if ($temporalType !== null) {
                    return [$temporalType, $nullable];
                }

                $refType = $this->schemaRefToClassName(
                    ref: $propertySchema['allOf'][0]['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                return [$refType, $nullable];
            }

            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $mergedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            return [$mergedClassName, $nullable];
        }

        if (array_key_exists('oneOf', $propertySchema) && is_array($propertySchema['oneOf'])) {
            return $this->resolveComposedUnionPropertyType(
                propertySchema: $propertySchema,
                keyword: 'oneOf',
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
        }

        if (array_key_exists('anyOf', $propertySchema) && is_array($propertySchema['anyOf'])) {
            return $this->resolveComposedUnionPropertyType(
                propertySchema: $propertySchema,
                keyword: 'anyOf',
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
        }

        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            $binaryType = $this->resolveBinaryRefType($propertySchema['$ref']);
            if ($binaryType !== null) {
                return [$binaryType, $nullable];
            }

            $temporalType = $this->resolveTemporalRefType($propertySchema['$ref']);
            if ($temporalType !== null) {
                return [$temporalType, $nullable];
            }

            return [
                $this->schemaRefToClassName(
                    ref: $propertySchema['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                ),
                $nullable,
            ];
        }

        if (
            array_key_exists('enum', $propertySchema) && is_array(
                $propertySchema['enum'],
            ) && $propertySchema['enum'] !== []
        ) {
            $parentEnumType = $this->resolveParentEnumTypeForOverride($ownerClassName, $propertyName, $propertySchema);
            if ($parentEnumType !== null) {
                return [$parentEnumType, $nullable];
            }

            $enumName = $ownerClassName . $this->normalizeClassName($propertyName);
            $type = $this->resolveEnumBackingType($propertySchema);
            /** @var array<int, string|int> $values */
            $values = $propertySchema['enum'];
            $this->registerEnum(
                enumName: $enumName,
                type: $type,
                values: $values,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
                varnames: $this->extractEnumVarnames($propertySchema, $values),
                descriptions: $this->extractEnumDescriptions($propertySchema, $values),
            );
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
                    propertySchema: $propertySchema,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
                return ['array<' . $mapValueType . '>', $nullable];
            }

            $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $nestedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            return [$nestedClassName, $nullable];
        }

        if ($type === 'array') {
            $items = $propertySchema['items'] ?? null;

            if (!is_array($items)) {
                return ['array', $nullable];
            }

            $itemNullable = (bool)($items['nullable'] ?? false);
            $itemPrefix = $itemNullable ? '?' : '';

            if (array_key_exists('$ref', $items) && is_string($items['$ref'])) {
                $binaryItemType = $this->resolveBinaryRefType($items['$ref']);
                if ($binaryItemType !== null) {
                    return ['array<' . $itemPrefix . $binaryItemType . '>', $nullable];
                }

                $temporalItemType = $this->resolveTemporalRefType($items['$ref']);
                if ($temporalItemType !== null) {
                    return ['array<' . $itemPrefix . $temporalItemType . '>', $nullable];
                }

                return [
                    'array<' . $itemPrefix . $this->schemaRefToClassName(
                        ref: $items['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                    ) . '>',
                    $nullable,
                ];
            }

            if (array_key_exists('enum', $items) && is_array($items['enum']) && $items['enum'] !== []) {
                $enumName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $enumType = $this->resolveEnumBackingType($items);
                /** @var array<int, string|int> $values */
                $values = $items['enum'];
                $this->registerEnum(
                    $enumName,
                    $enumType,
                    $values,
                    $this->getSchemaSourceFile($ownerClassName),
                    $this->extractEnumVarnames($items, $values),
                    $this->extractEnumDescriptions($items, $values),
                );
                return ['array<' . $itemPrefix . $enumName . '>', $nullable];
            }

            $itemsType = $items['type'] ?? null;
            if ($itemsType === 'object') {
                $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $this->registerSchema(
                    className: $nestedClassName,
                    schemaDefinition: $items,
                    sourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                return ['array<' . $itemPrefix . $nestedClassName . '>', $nullable];
            }

            if ($itemsType === 'string') {
                $itemsFormatType = $this->mapStringFormatType($items['format'] ?? null);
                if ($itemsFormatType !== null) {
                    return ['array<' . $itemPrefix . $itemsFormatType . '>', $nullable];
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

                return ['array<' . $itemPrefix . $mapped . '>', $nullable];
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
            $nullable,
        ];
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function normalizeNullableBranchInAllOf(array $propertySchema): array
    {
        if (!array_key_exists('allOf', $propertySchema) || !is_array($propertySchema['allOf'])) {
            return $propertySchema;
        }

        $nullable = (bool)($propertySchema['nullable'] ?? false);
        $filteredAllOf = [];
        $hadNullableBranch = false;

        foreach ($propertySchema['allOf'] as $item) {
            if (is_array($item) && count($item) === 1 && ($item['nullable'] ?? null) === true) {
                $nullable = true;
                $hadNullableBranch = true;
                continue;
            }

            $filteredAllOf[] = $item;
        }

        if (!$hadNullableBranch) {
            return $propertySchema;
        }

        $normalized = $propertySchema;
        $normalized['allOf'] = $filteredAllOf;
        $normalized['nullable'] = $nullable;

        return $normalized;
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
        $parentValues = $parentEnum['values'];
        if ($parentValues === []) {
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
            if (!is_array($item) || !array_key_exists('$ref', $item) || !is_string($item['$ref'])) {
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

        return $this->schemaRefToClassName(
            ref: $ref,
            currentSourceFile: $this->getSchemaSourceFile($className),
        );
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
        if (
            array_key_exists('$ref', $item)
            || array_key_exists('properties', $item)
            || array_key_exists('allOf', $item)
            || array_key_exists('oneOf', $item)
            || array_key_exists('anyOf', $item)
        ) {
            return false;
        }

        return array_key_exists('type', $item) || array_key_exists('enum', $item) || array_key_exists('format', $item);
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

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolveTemporalPhpDocFormat(array $propertySchema): ?string
    {
        $format = $propertySchema['format'] ?? null;
        if (is_string($format)) {
            return $this->mapTemporalPhpDocFormat($format);
        }

        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            return $this->resolveTemporalRefPhpDocFormat($propertySchema['$ref']);
        }

        if (
            array_key_exists('allOf', $propertySchema)
            && is_array($propertySchema['allOf'])
            && count($propertySchema['allOf']) === 1
        ) {
            $allOfItem = $propertySchema['allOf'][0] ?? null;
            if (is_array($allOfItem) && array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
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
        if ($schemaName === '') {
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
        if ($schemaName === '') {
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
        if ($schemaName === '') {
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

            return $schemaName !== ''
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

        return $schemaName !== ''
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

        if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
            // Symfony DTOs are flattened: inherited properties are merged into a single standalone
            // constructor (no `extends`/parent::__construct chaining), which maps cleanly onto the
            // Symfony serializer/validator without partially-initialised parent state.
            return $this->renderSymfonyDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedSymfonyProperties($className, $properties),
                unionTypes: $unionTypes,
            );
        }

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
                    array_map(
                        fn(string $type): string => $this->formatClassNameForNamespace($type, $namespace),
                        $unionTypes,
                    ),
                ),
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

        $classModifiers = array_key_exists($className, $this->parentClasses) ? '' : 'final ';
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
        ]);
    }

    /**
     * Renders a DTO in Symfony mode: a plain data class with promoted public readonly
     * constructor properties decorated with Symfony Validator (#[Assert\*]) and Serializer
     * (#[SerializedName]) attributes. No library runtime, interface, or normalization map.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $unionTypes
     */
    private function renderSymfonyDtoClass(
        string $namespace,
        string $className,
        array $properties,
        array $unionTypes,
    ): string {
        $useStatements = [];
        if ($this->needsDateTimeImmutableImport($properties)) {
            $useStatements[] = 'DateTimeImmutable';
        }
        if ($this->needsUploadedFileImport($properties)) {
            $useStatements[] = 'Symfony\\Component\\HttpFoundation\\File\\UploadedFile';
        }
        foreach ($this->collectGeneratedClassImports($namespace, $className, $properties, null, $unionTypes, null) as $import) {
            $useStatements[] = $import;
        }

        $params = [];
        $needsSerializedName = false;
        $needsGroups = false;
        foreach ($properties as $property) {
            $param = $this->resolveSymfonyParam($property, $namespace);
            if ($param['serializedName'] !== null) {
                $needsSerializedName = true;
            }
            foreach ($param['attributes'] as $attribute) {
                if (str_contains($attribute, 'Groups(')) {
                    $needsGroups = true;
                }
            }
            $params[] = $param;
        }

        $useStatements[] = 'Symfony\\Component\\Validator\\Constraints as Assert';
        if ($needsSerializedName) {
            $useStatements[] = 'Symfony\\Component\\Serializer\\Attribute\\SerializedName';
        }
        if ($needsGroups) {
            $useStatements[] = 'Symfony\\Component\\Serializer\\Attribute\\Groups';
        }
        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.symfony.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'extends' => null,
            'params' => $params,
        ]);
    }

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
     * @return array{declaredType: string, docType: ?string, name: string, serializedName: ?string, default: string, attributes: array<int, string>}
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

        $required = $property['required'];
        $default = $property['default'] ?? null;

        // Symfony mode drops the UnsetValue sentinel: an optional property becomes nullable with a
        // default (null unless the schema declares one). PATCH partial-update semantics (present vs
        // omitted) are intentionally not modelled here — that is a documented limitation.
        $declaredNullable = $property['nullable'] || (!$required && $default === null);
        $declaredType = $this->composePhpTypeHint($phpType, $declaredNullable);

        if ($required) {
            $defaultLiteral = '';
        } elseif ($default !== null) {
            $defaultLiteral = $this->renderDefaultValue($default, $phpType, $declaredType);
        } else {
            $defaultLiteral = ' = null';
        }

        return [
            'declaredType' => $declaredType,
            'docType' => $docType !== null ? $this->composePhpTypeHint($docType, $declaredNullable) : null,
            'name' => $property['name'],
            'serializedName' => $property['name'] !== $property['openApiName'] ? $property['openApiName'] : null,
            'default' => $defaultLiteral,
            'attributes' => $this->resolveSymfonyAttributes($property),
        ];
    }

    /**
     * Maps OpenAPI constraints to Symfony Validator attribute lines. Covers the common scalar,
     * string, numeric and array constraints plus cascade validation. Composition keywords
     * (oneOf/anyOf/allOf/discriminator/if-then-else) have no clean Symfony equivalent and are not
     * emitted — a documented limitation of this mode.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function resolveSymfonyAttributes(array $property): array
    {
        $constraints = is_array($property['constraints'] ?? null) ? $property['constraints'] : [];
        $attributes = [];

        if ($property['required'] && !$property['nullable']) {
            $attributes[] = '#[Assert\\NotNull]';
        }

        // Scalar/value-level constraints (Length, Range, Regex, EqualTo, format-based, ...).
        foreach ($this->scalarConstraintSpecs($constraints) as $spec) {
            $attributes[] = $spec['args'] === ''
                ? '#[Assert\\' . $spec['name'] . ']'
                : '#[Assert\\' . $spec['name'] . '(' . $spec['args'] . ')]';
        }

        // Array/map size — minItems/maxItems (lists) and minProperties/maxProperties (inline maps)
        // both count elements of the backing PHP array, so they share a single Count attribute.
        $count = [];
        $countMin = $constraints['minItems'] ?? $constraints['minProperties'] ?? null;
        $countMax = $constraints['maxItems'] ?? $constraints['maxProperties'] ?? null;
        if (is_int($countMin)) {
            $count[] = 'min: ' . $countMin;
        }
        if (is_int($countMax)) {
            $count[] = 'max: ' . $countMax;
        }
        if ($count !== []) {
            $attributes[] = '#[Assert\\Count(' . implode(', ', $count) . ')]';
        }

        if (($constraints['uniqueItems'] ?? null) === true) {
            $attributes[] = '#[Assert\\Unique]';
        }

        // Typed map values (additionalProperties: { schema }) — validate every value via All.
        $additionalProperties = $constraints['additionalProperties'] ?? null;
        if (is_array($additionalProperties)) {
            $valueExpressions = $this->valueConstraintExpressions($additionalProperties);
            if ($valueExpressions !== []) {
                $attributes[] = '#[Assert\\All([' . implode(', ', $valueExpressions) . '])]';
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
                    : 'new Assert\\Sequentially([' . implode(', ', $expressions) . '])';
            }
            if ($allBranchesValidatable && count($branches) >= 2) {
                $attributes[] = '#[Assert\\AtLeastOneOf([' . implode(', ', $branches) . '])]';
            }
        }

        // Per-item constraints for arrays of scalars (array of DTOs cascades via Valid instead).
        $items = $constraints['items'] ?? null;
        if (is_array($items) && !$this->symfonyPropertyCascades($property)) {
            $itemSpecs = $this->scalarConstraintSpecs($items);
            if ($itemSpecs !== []) {
                $expressions = array_map(
                    static fn(array $spec): string => 'new Assert\\' . $spec['name'] . '(' . $spec['args'] . ')',
                    $itemSpecs,
                );
                $attributes[] = '#[Assert\\All([' . implode(', ', $expressions) . '])]';
            }
        }

        // Serialization groups for read-only / write-only fields.
        if (($property['readOnly'] ?? false) === true) {
            $attributes[] = "#[Groups(['read'])]";
        }
        if (($property['writeOnly'] ?? false) === true) {
            $attributes[] = "#[Groups(['write'])]";
        }

        if ($this->symfonyPropertyCascades($property)) {
            $attributes[] = '#[Assert\\Valid]';
        }

        return $attributes;
    }

    /**
     * Maps the scalar/value-level OpenAPI constraints of a (sub)schema to Symfony constraint
     * specs: [{name, args}]. Shared by property attributes and by #[Assert\All] item constraints.
     * Composition keywords (oneOf/anyOf/allOf/not/if-then-else), tuple prefixItems and inline-map
     * object constraints (minProperties/additionalProperties) have no clean Symfony equivalent and
     * are intentionally not mapped — a documented limitation of this mode.
     *
     * @param array<string, mixed> $constraints
     * @return array<int, array{name: string, args: string}>
     */
    private function scalarConstraintSpecs(array $constraints): array
    {
        $specs = [];

        $length = [];
        if (is_int($constraints['minLength'] ?? null)) {
            $length[] = 'min: ' . $constraints['minLength'];
        }
        if (is_int($constraints['maxLength'] ?? null)) {
            $length[] = 'max: ' . $constraints['maxLength'];
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
        } elseif ($exclusiveMin === true && (is_int($min) || is_float($min))) {
            $specs[] = ['name' => 'GreaterThan', 'args' => $this->numericLiteral($min)];
            $min = null;
        }

        if (is_int($exclusiveMax) || is_float($exclusiveMax)) {
            $specs[] = ['name' => 'LessThan', 'args' => $this->numericLiteral($exclusiveMax)];
        } elseif ($exclusiveMax === true && (is_int($max) || is_float($max))) {
            $specs[] = ['name' => 'LessThan', 'args' => $this->numericLiteral($max)];
            $max = null;
        }

        $range = [];
        if (is_int($min) || is_float($min)) {
            $range[] = 'min: ' . $this->numericLiteral($min);
        }
        if (is_int($max) || is_float($max)) {
            $range[] = 'max: ' . $this->numericLiteral($max);
        }
        if ($range !== []) {
            $specs[] = ['name' => 'Range', 'args' => implode(', ', $range)];
        }

        if (is_int($constraints['multipleOf'] ?? null) || is_float($constraints['multipleOf'] ?? null)) {
            $specs[] = ['name' => 'DivisibleBy', 'args' => $this->numericLiteral($constraints['multipleOf'])];
        }

        if (is_string($constraints['pattern'] ?? null) && $constraints['pattern'] !== '') {
            $delimited = '/' . str_replace('/', '\\/', $constraints['pattern']) . '/';
            $specs[] = ['name' => 'Regex', 'args' => $this->phpStringLiteral($delimited)];
        }

        if (array_key_exists('const', $constraints) && $this->isScalarConstValue($constraints['const'])) {
            $specs[] = ['name' => 'EqualTo', 'args' => 'value: ' . $this->scalarLiteral($constraints['const'])];
        }

        $hasRange = $range !== [];
        foreach ($this->formatConstraintSpecs($constraints['format'] ?? null) as $spec) {
            // An explicit minimum/maximum Range already covers (and is tighter than) the format's
            // implicit int32 bounds — avoid emitting a redundant second Range.
            if ($spec['name'] === 'Range' && $hasRange) {
                continue;
            }
            $specs[] = $spec;
        }

        return $specs;
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
            'uri', 'iri', 'url' => [['name' => 'Url', 'args' => '']],
            'hostname', 'idn-hostname' => [['name' => 'Hostname', 'args' => '']],
            'ipv4' => [['name' => 'Ip', 'args' => "version: '4'"]],
            'ipv6' => [['name' => 'Ip', 'args' => "version: '6'"]],
            'int32' => [['name' => 'Range', 'args' => 'min: -2147483648, max: 2147483647']],
            'uint32' => [['name' => 'Range', 'args' => 'min: 0, max: 4294967295']],
            // uint64's upper bound (2^64-1) exceeds PHP's signed int, so only the lower bound
            // is expressible; int64 is the native PHP int range and needs no constraint.
            'uint64' => [['name' => 'Range', 'args' => 'min: 0']],
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
    private function valueConstraintExpressions(array $schema): array
    {
        $expressions = [];

        $symfonyType = $this->openApiTypeToSymfonyType($schema['type'] ?? null);
        if ($symfonyType !== null) {
            $expressions[] = "new Assert\\Type('" . $symfonyType . "')";
        }

        foreach ($this->scalarConstraintSpecs($schema) as $spec) {
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

    private function shortClassName(string $type): string
    {
        $parts = explode('\\', $type);

        return end($parts);
    }

    private function numericLiteral(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        $rendered = json_encode($value);

        return is_string($rendered) ? $rendered : (string)$value;
    }

    private function phpStringLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
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
            'usesUnsetSentinel' => !$property['required'] && $property['default'] === null,
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

        // Use UnsetValue enum for optional parameters that track presence and have no explicit default
        $usesUnsetSentinel = false;
        if (!$property['required'] && $defaultValue === '') {
            if ($tracksArgPresence) {
                $usesUnsetSentinel = true;
                // Add union type with UnsetValue and null. Strip any existing nullability first
                // (leading ? or a null union member) so the result never has a duplicate null.
                // null is emitted last to satisfy the ordered_types code-style rule
                // (null_adjustment: always_last).
                $baseType = strpos($type, '?') === 0 ? substr($type, 1) : $type;
                $members = array_filter(
                    explode('|', $baseType),
                    static fn(string $member): bool => $member !== '' && $member !== 'null',
                );
                $type = implode('|', $members) . '|UnsetValue|null';
            } elseif ($property['nullable']) {
                $defaultValue = ' = null';
            }
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
     * @return array{name: string, openApiName: string, nameSuffix: string, methodName: string, returnType: string, hasGuard: bool, docDescriptionLines: array<int, string>, docReturnType: ?string, expectedFormat: ?string, returnKind: string, phpDateFormat: ?string, isNullableTemporal: bool, requiredLiteral: string, inPathFlagName: string, inQueryFlagName: string, inHeaderFlagName: string, inCookieFlagName: string, inRequestFlagName: string, presenceFlagName: string, hasArrayAdder: bool, arrayAdderMethodName: string, arrayAdderItemType: string, nullableArray: bool, usesUnsetSentinel: bool, getterUsesSentinel: bool, isParameter: bool}
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
        $usesUnsetSentinel = !$property['required'] && $property['default'] === null;
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
            'hasArrayAdder' => str_starts_with($property['type'], 'array'),
            'arrayAdderMethodName' => 'addItemTo' . ucfirst($property['name']),
            'arrayAdderItemType' => $this->resolveArrayItemPhpType($property['type']),
            'arrayAdderItemNullable' => str_starts_with($this->resolveArrayItemPhpType($property['type']), '?'),
            'nullableArray' => $property['nullable'],
            'usesUnsetSentinel' => $usesUnsetSentinel,
            'getterUsesSentinel' => $getterUsesSentinel,
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
            $isParameter = ($property['inPath'] ?? false) === true
                || ($property['inQuery'] ?? false) === true
                || ($property['inHeader'] ?? false) === true
                || ($property['inCookie'] ?? false) === true;
            if (!$isParameter) {
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
     * @param array<string> $unionTypes
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
            $this->appendImportForClass(
                imports: $imports,
                className: $extends,
                currentNamespace: $namespace,
                currentClassName: $className,
            );
        }

        foreach ($unionTypes as $unionType) {
            $this->appendImportForClass(
                imports: $imports,
                className: $unionType,
                currentNamespace: $namespace,
                currentClassName: $className,
            );
        }

        foreach ($properties as $property) {
            foreach ($this->extractReferencedClassNamesFromType($property['type']) as $typeClass) {
                $this->appendImportForClass(
                    imports: $imports,
                    className: $typeClass,
                    currentNamespace: $namespace,
                    currentClassName: $className,
                );
            }
        }

        if ($discriminator !== null) {
            foreach ($discriminator['mapping'] as $targetClass) {
                $this->appendImportForClass(
                    imports: $imports,
                    className: $targetClass,
                    currentNamespace: $namespace,
                    currentClassName: $className,
                );
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
        $splitResult = preg_split('/\|/', $normalized);
        $parts = $splitResult !== false ? $splitResult : [];
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (
                $part === '' || in_array(
                    $part,
                    ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'],
                    true,
                )
            ) {
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
        $splitResult = preg_split('/\|/', $type);
        $parts = $splitResult !== false ? $splitResult : [];
        $formatted = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (
                $part === '' || in_array(
                    $part,
                    ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'],
                    true,
                )
            ) {
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

    private function resolveArrayItemPhpType(string $fullType): string
    {
        if (!str_starts_with($fullType, 'array<')) {
            return 'mixed';
        }

        $itemType = substr($fullType, 6, -1);
        if ($itemType === '') {
            return 'mixed';
        }

        return match ($itemType) {
            'int', 'float', 'string', 'bool', 'mixed', 'array' => $itemType,
            default => $itemType,
        };
    }

    /**
     * @param array<int, SchemaProperty> $properties
     */
    private function needsDateTimeImmutableImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = $property['type'];
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
            if (array_key_exists($name, $seen)) {
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
     * Rejects merged allOf branches that declare the same property with conflicting PHP types.
     *
     * @param array<int, SchemaProperty> $properties
     */
    private function assertMergedPropertiesCompatible(array $properties, string $className): void
    {
        $byName = [];
        foreach ($properties as $property) {
            $name = $property['name'];
            if (array_key_exists($name, $byName) && $byName[$name]['type'] !== $property['type']) {
                throw new RuntimeException(sprintf(
                    'Property merge conflict in %s for $%s: type %s vs %s.',
                    $className,
                    $name,
                    $this->describePropertyType($byName[$name]),
                    $this->describePropertyType($property),
                ));
            }
            $byName[$name] = $property;
        }
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

    /**
     * @return array<int, SchemaProperty>
     */
    private function getParentProperties(string $parentClassName): array
    {
        $schemaDefinition = $this->dtoSchemas[$parentClassName] ?? null;

        return $schemaDefinition !== null ? $this->extractProperties($schemaDefinition, $parentClassName) : [];
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
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            $allProperties = [];

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $parentClass = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );
                    // Recursively get parent properties
                    foreach ($this->getSchemaProperties($parentClass) as $prop) {
                        $allProperties[] = $prop;
                    }
                    continue;
                }

                foreach (
                    $this->extractProperties(
                        schemaDefinition: $allOfItem,
                        ownerClassName: $className,
                    ) as $property
                ) {
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
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $name);
        $parts = array_values(array_filter($splitResult !== false ? $splitResult : [], static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'GeneratedDto';
        }

        $normalized = '';
        foreach ($parts as $part) {
            $normalized .= ucfirst(strtolower($part));
        }

        if (is_numeric($normalized[0])) {
            return 'Value' . $normalized;
        }

        return $normalized;
    }

    private function normalizePropertyName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return 'value';
        }

        // Split camelCase/PascalCase and keep arbitrary separators from OpenAPI keys.
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $normalized) ?? $normalized;
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $normalized);
        $parts = array_values(array_filter($splitResult !== false ? $splitResult : [], static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'value';
        }

        $first = strtolower($parts[0]);
        $propertyName = $first;

        for ($index = 1, $count = count($parts); $index < $count; $index++) {
            $propertyName .= ucfirst(strtolower($parts[$index]));
        }

        if (is_numeric($propertyName[0])) {
            return 'value' . $propertyName;
        }

        return $propertyName;
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

    private function prepareOutputDirectory(string $outputDirectory): void
    {
        if (is_dir($outputDirectory)) {
            $this->deleteDirectoryContents($outputDirectory);
            return;
        }

        if (!mkdir($outputDirectory, 0o775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $outputDirectory));
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
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
                        if (!is_array($schema) || array_key_exists('$ref', $schema)) {
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
                    if (!is_array($schema) || array_key_exists('$ref', $schema)) {
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
        $splitResult = preg_split('/[\/\-_]+/', $pathPart);
        $segments = $splitResult !== false ? $splitResult : [];

        $normalizedPath = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            // Skip path parameter placeholders like {id}, {userId}, etc.
            if (preg_match('/^\{[^}]+\}$/', $segment) === 1) {
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
        return array_key_exists('enum', $schema)
            && is_array($schema['enum'])
            && $schema['enum'] !== []
            && array_key_exists('type', $schema)
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
     * @param array<int, string>|null $varnames mapped positionally onto $values (x-enum-varnames)
     * @param array<int, ?string>|null $descriptions mapped positionally onto $values (x-enum-descriptions)
     */
    private function registerEnum(
        string $enumName,
        string $type,
        array $values,
        ?string $sourceFile,
        ?array $varnames = null,
        ?array $descriptions = null,
    ): void {
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);

        if (array_key_exists($enumName, $this->enumSchemas)) {
            $existing = $this->enumSchemas[$enumName];
            if ($existing['type'] !== $type || $existing['values'] !== $values) {
                throw new RuntimeException(sprintf('Enum schema name collision for %s.', $enumName));
            }

            if (($this->enumNamespaces[$enumName] ?? $namespace) !== $namespace) {
                throw new RuntimeException(sprintf('Enum schema namespace collision for %s.', $enumName));
            }
            return;
        }

        if (array_key_exists($enumName, $this->dtoSchemas)) {
            throw new RuntimeException(sprintf('Enum/DTO name collision for %s.', $enumName));
        }

        $caseNames = $this->buildEnumCaseNames($values, $varnames);
        $normalizedDescriptions = [];
        foreach (array_keys($values) as $i) {
            $description = $descriptions[$i] ?? null;
            $normalizedDescriptions[$i] = is_string($description) && $description !== ''
                ? $this->normalizeEnumCaseDescription($description)
                : null;
        }

        $this->enumSchemas[$enumName] = [
            'type' => $type,
            'values' => $values,
            'caseNames' => $caseNames,
            'descriptions' => $normalizedDescriptions,
        ];
        $this->enumSourceFiles[$enumName] = $sourceFile;
        $this->enumNamespaces[$enumName] = $namespace;
        $this->enumOutputDirectories[$enumName] = $outputDirectory;
    }

    /**
     * @param array<int, string|int> $values
     * @param array<int, string> $caseNames
     * @param array<int, ?string> $descriptions
     */
    private function renderEnum(
        string $namespace,
        string $enumName,
        string $backingType,
        array $values,
        array $caseNames,
        array $descriptions,
    ): string {
        $cases = [];

        foreach (array_values($values) as $index => $value) {
            $cases[] = [
                'name' => $caseNames[$index],
                'value' => $this->renderEnumValue($value, $backingType),
                'description' => $descriptions[$index] ?? null,
            ];
        }

        // Symfony mode emits a plain backed enum (no library runtime interface/methods) — the
        // Symfony serializer handles backed enums natively via BackedEnumNormalizer.
        $isSymfony = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY;

        return $this->renderPhpTemplate(
            $isSymfony ? 'enum.symfony.php.twig' : 'enum.php.twig',
            [
                'namespace' => $namespace,
                'imports' => $isSymfony ? [] : [$this->generatedDtoInterfaceImportFqcn],
                'enumName' => $enumName,
                'backingType' => $backingType,
                'cases' => $cases,
            ],
        );
    }

    /**
     * Reads the x-enum-varnames vendor extension. Returns positional case names only when the
     * extension is a list of non-empty strings matching the value count, otherwise null (fallback
     * to value-derived names). x-enum-varnames is not part of the OpenAPI spec; it is a de-facto
     * codegen convention.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string|int> $values
     * @return array<int, string>|null
     */
    private function extractEnumVarnames(array $schema, array $values): ?array
    {
        $varnames = $schema['x-enum-varnames'] ?? null;
        if (!is_array($varnames) || count($varnames) !== count($values)) {
            return null;
        }

        $result = [];
        foreach (array_values($varnames) as $name) {
            if (!is_string($name) || $name === '') {
                return null;
            }
            $result[] = $name;
        }

        return $result;
    }

    /**
     * Reads the x-enum-descriptions vendor extension. Returns a positional list aligned with the
     * value count (entries may be null), otherwise null.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string|int> $values
     * @return array<int, ?string>|null
     */
    private function extractEnumDescriptions(array $schema, array $values): ?array
    {
        $descriptions = $schema['x-enum-descriptions'] ?? null;
        if (!is_array($descriptions) || count($descriptions) !== count($values)) {
            return null;
        }

        $result = [];
        foreach (array_values($descriptions) as $description) {
            $result[] = is_string($description) ? $description : null;
        }

        return $result;
    }

    /**
     * Builds the final enum case names (deduplicated) for a value list. When $varnames is provided
     * (from x-enum-varnames) each name is sanitised into a valid identifier; otherwise names are
     * derived from the values. This is the single source of truth shared by enum rendering and
     * enum default-value resolution.
     *
     * @param array<int, string|int> $values
     * @param array<int, string>|null $varnames
     * @return array<int, string>
     */
    private function buildEnumCaseNames(array $values, ?array $varnames): array
    {
        $usedCaseNames = [];
        $caseNames = [];

        foreach (array_values($values) as $index => $value) {
            $caseNames[] = $varnames !== null
                ? $this->sanitizeEnumCaseName($varnames[$index], $usedCaseNames)
                : $this->buildEnumCaseName($value, $usedCaseNames);
        }

        return $caseNames;
    }

    /**
     * Turns an x-enum-varnames entry into a valid, unique PHP enum case identifier, preserving the
     * author's intended casing where possible.
     *
     * @param array<string, true> $usedCaseNames
     */
    private function sanitizeEnumCaseName(string $varname, array &$usedCaseNames): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '_', $varname) ?? $varname;
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'VALUE';
        }

        if (is_numeric($base[0])) {
            $base = 'VALUE_' . $base;
        }

        $name = $base;
        $i = 2;

        while (array_key_exists($name, $usedCaseNames)) {
            $name = $base . '_' . $i;
            $i++;
        }

        $usedCaseNames[$name] = true;

        return $name;
    }

    /**
     * Resolves the generated case name for an enum default value by matching it against the
     * registered enum's value list, so defaults reference the same case name the enum declares
     * (including any x-enum-varnames mapping). Returns null when the enum is not registered.
     */
    private function resolveEnumCaseNameForValue(string $enumName, string|int $value): ?string
    {
        $enum = $this->enumSchemas[$enumName] ?? null;
        if ($enum === null) {
            return null;
        }

        $index = array_search($value, $enum['values'], true);
        if ($index === false) {
            return null;
        }

        return $enum['caseNames'][$index] ?? null;
    }

    /**
     * Collapses an x-enum-descriptions entry to a single docblock-safe line.
     */
    private function normalizeEnumCaseDescription(string $description): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($description)) ?? $description;

        return str_replace('*/', '* /', $normalized);
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

        while (array_key_exists($name, $usedCaseNames)) {
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
        if (!array_key_exists('default', $propertySchema)) {
            return null;
        }

        return $propertySchema['default'];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractDescription(array $propertySchema): ?string
    {
        if (!array_key_exists('description', $propertySchema)) {
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

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractExample(array $propertySchema): ?string
    {
        if (!array_key_exists('example', $propertySchema)) {
            return null;
        }

        $example = $propertySchema['example'];

        if (is_string($example)) {
            $normalized = trim($example);
            if ($normalized === '') {
                return null;
            }

            return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        }

        if (is_int($example) || is_float($example) || is_bool($example) || is_array($example)) {
            $encoded = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : null;
        }

        return null;
    }

    private function renderDefaultValue(mixed $defaultValue, string $phpType, string $fullType): string
    {
        if ($defaultValue === null) {
            return '';
        }

        // Handle enum types - need to use the enum case
        if (
            !str_contains($phpType, '|') && !in_array(
                $phpType,
                ['int', 'float', 'string', 'bool', 'array', 'mixed'],
                true,
            )
        ) {
            // It's an enum or custom type. Resolve the case name against the registered enum so the
            // default references the same case the enum declares (honouring x-enum-varnames). Fall
            // back to value-derived naming for enums not registered here (e.g. external types).
            if (is_string($defaultValue) || is_int($defaultValue)) {
                $enumCaseName = $this->resolveEnumCaseNameForValue($phpType, $defaultValue);
                if ($enumCaseName === null) {
                    $usedNames = [];
                    $enumCaseName = $this->buildEnumCaseName($defaultValue, $usedNames);
                }
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
            return $defaultValue === true ? ' = true' : ' = false';
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
            if (array_key_exists('$ref', $parameter) && is_string($parameter['$ref'])) {
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

        if (!is_array($componentsParameters) || !array_key_exists($parameterName, $componentsParameters)) {
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
            if (in_array($paramIn, ['path', 'query', 'header', 'cookie'], true)) {
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
            if (in_array($paramIn, ['path', 'query', 'header', 'cookie'], true)) {
                $schema['x-parameter-in'] = $paramIn;
                $schema['x-parameter-style'] = $this->resolveParameterStyle($parameter, $paramIn);
                $schema['x-parameter-explode'] = $this->resolveParameterExplode(
                    $parameter,
                    $schema['x-parameter-style'],
                );
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

    /**
     * Resolves the OpenAPI serialization `style` for a parameter, applying the
     * spec defaults when absent: `form` for query/cookie, `simple` for path/header.
     *
     * @param array<string, mixed> $parameter
     */
    private function resolveParameterStyle(array $parameter, string $paramIn): string
    {
        $style = $parameter['style'] ?? null;
        if (is_string($style) && $style !== '') {
            return $style;
        }

        return in_array($paramIn, ['query', 'cookie'], true) ? 'form' : 'simple';
    }

    /**
     * Resolves the OpenAPI `explode` flag. The spec default is `true` only when the
     * style is `form`, and `false` for every other style.
     *
     * @param array<string, mixed> $parameter
     */
    private function resolveParameterExplode(array $parameter, string $style): bool
    {
        if (array_key_exists('explode', $parameter)) {
            return $this->toBoolean($parameter['explode']);
        }

        return $style === 'form';
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

    private function resolveOutputDirectory(string $directory): string
    {
        $normalized = str_replace('\\', '/', $directory);

        if (str_starts_with($normalized, '/')) {
            return rtrim($normalized, '/');
        }

        $cwd = getcwd();
        $workingDirectory = $cwd !== false ? $cwd : '.';
        return rtrim($workingDirectory . '/' . ltrim($normalized, '/'), '/');
    }

    private function directoryToNamespace(string $directory): string
    {
        $normalized = trim(str_replace('\\', '/', $directory), '/');

        if ($normalized === '') {
            return 'Generated';
        }

        $segments = array_filter(explode('/', $normalized), static fn(string $segment): bool => $segment !== '');
        $namespaceParts = [];

        foreach ($segments as $segment) {
            $namespaceParts[] = $this->normalizeNamespaceSegment($segment);
        }

        return implode('\\', $namespaceParts);
    }

    private function normalizeNamespaceSegment(string $segment): string
    {
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $segment);
        $parts = $splitResult !== false ? $splitResult : [];
        $normalized = implode(
            '',
            array_map(static fn(string $part): string => ucfirst(strtolower($part)), array_filter($parts)),
        );

        if ($normalized === '') {
            return 'Generated';
        }

        if (is_numeric($normalized[0])) {
            return 'Value' . $normalized;
        }

        return $normalized;
    }

    private function normalizeExplicitNamespace(string $namespace): string
    {
        return trim(trim($namespace), '\\');
    }
}
