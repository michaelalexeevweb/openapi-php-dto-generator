<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use DateTimeImmutable;
use Exception;
use JsonException;
use OpenapiPhpDtoGenerator\Command\Rendering\GlobalFunctionImports;
use OpenapiPhpDtoGenerator\Command\Rendering\NamesLibraryClasses;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersLaravelDataDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersLaravelDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersRuntimeDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersSymfonyDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersYii3Dto;
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
use Twig\TwigFilter;

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
 *   itemsTemporalFormat?: string|null,
 *   inPath?: bool,
 *   inQuery?: bool,
 *   inHeader?: bool,
 *   inCookie?: bool,
 *   inQueryString?: bool,
 *   parameterStyle?: string|null,
 *   parameterExplode?: bool|null,
 *   allowReserved?: bool,
 *   allowEmptyValue?: bool|null,
 *   constraints?: array<string, mixed>,
 *   readOnly?: bool,
 *   writeOnly?: bool,
 *   deprecated?: bool,
 *   isMap?: bool
 * }
 * @phpstan-type SchemaMetadata array{
 *   properties: array<int, SchemaProperty>,
 *   extends: string|null,
 *   unionTypes: array<string>,
 *   discriminator: array{propertyName: string, mapping: array<string, string>}|null,
 *   abstract?: bool
 * }
 */
#[AsCommand(name: 'openapi:generate-dto', description: 'Generate readonly DTO classes from OpenAPI components.schemas')]
final class GenerateDtoCommand extends Command
{
    // One emitter per mode, each in its own file; everything they need (schema registries, type
    // resolution, naming, templates) stays on this class and is shared by all three. Laravel mode also
    // reads `RendersSymfonyDto::renderSymfonyValidationBlock()` — the interpreter has one
    // implementation and three packagings, see `renderLaravelInterpreterBlock()`.
    use NamesLibraryClasses;
    use RendersLaravelDataDto;
    use RendersLaravelDto;
    use RendersRuntimeDto;
    use RendersSymfonyDto;
    use RendersYii3Dto;

    /** OAS 3.2 `$self`: the document's own URI, used to recognise self-addressing `$ref`s. */
    private ?string $documentSelfUri = null;

    /**
     * Warnings collected during generation and reported by the CLI.
     *
     * @var array<int, string>
     */
    private array $generationWarnings = [];

    /**
     * Classes that describe a REQUEST payload (a request body or the query/path parameters of an
     * operation), as opposed to a response or a plain component. Laravel mode emits a FormRequest for
     * these and for nothing else.
     *
     * @var array<string, true>
     */
    private array $requestPayloadClasses = [];

    /** Generation mode backed by this library's runtime (DtoValidator/Normalizer/Deserializer). */
    public const string ATTRIBUTE_MODE_RUNTIME = 'runtime';

    /** Generation mode emitting Symfony Validator/Serializer attributes (no library runtime). */
    public const string ATTRIBUTE_MODE_SYMFONY = 'symfony';

    /**
     * Generation mode emitting first-party Laravel artefacts: a plain DTO carrying a `rules()` array
     * for `illuminate/validation` (no library runtime, and nothing to install — `FormRequest` and the
     * validator ship with the framework).
     */
    public const string ATTRIBUTE_MODE_LARAVEL = 'laravel';

    /**
     * Generation mode emitting `spatie/laravel-data` classes: ONE `Data` subclass per schema instead of
     * the FormRequest + DTO pair, hydrated and normalized by that package's own pipeline.
     *
     * Opt-in rather than default, and the only mode whose output needs a third-party package installed.
     * What it buys is presence tracking as a language-level fact: an unprovided optional property IS a
     * `Spatie\LaravelData\Optional`, so PATCH semantics need no emitted flag array.
     */
    public const string ATTRIBUTE_MODE_LARAVEL_DATA = 'laravel-data';

    /**
     * Generation mode emitting `yiisoft/validator` attributes on a `yiisoft/input-http` input class,
     * hydrated by `yiisoft/hydrator` and validated through `yiisoft/hydrator-validator`.
     *
     * Yii3 applications are already served by runtime mode over PSR-7 (`DtoDeserializerPsr7`), so this
     * mode buys native-attribute ergonomics rather than capability — with one honest limit the other
     * framework modes do not have: Yii3 does NOT turn a failed validation into a 422 by itself. The
     * emitted class implements `ValidatedInputInterface`, and the action reads `getValidationResult()`.
     *
     * Two measured facts shape the emitter, both verified against the real packages rather than the
     * docs: `#[Callback]` is `TARGET_CLASS`, so the interpreter is entered ONCE per object with the DTO
     * as its value (the Symfony packaging, not the Laravel one); and a bare `#[Nested]` cascades into a
     * nested class's own attributes, so recursive schemas need no repeated rule set.
     */
    public const string ATTRIBUTE_MODE_YII3 = 'yii3';

    /** @var array<int, string> */
    public const array ATTRIBUTE_MODES = [
        self::ATTRIBUTE_MODE_RUNTIME,
        self::ATTRIBUTE_MODE_SYMFONY,
        self::ATTRIBUTE_MODE_LARAVEL,
        self::ATTRIBUTE_MODE_LARAVEL_DATA,
        self::ATTRIBUTE_MODE_YII3,
    ];

    /**
     * Names PHP refuses as a class name, lowercased. Two groups, one symptom: the hard keywords
     * (`list`, `match`, `readonly`, `static`, …) make the `class X` line a parse error, while the
     * soft-reserved type names (`int`, `string`, `mixed`, `never`, …) and `self`/`parent` fail at
     * load time with "Cannot use X as a class name as it is reserved". A schema is free to be called
     * any of them, so `avoidReservedPhpClassName()` renames the class, not the schema.
     *
     * @var array<int, string>
     */
    private const array PHP_RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface',
        'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw',
        'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        // Soft-reserved: legal as an identifier elsewhere, rejected as a class name.
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'numeric', 'object',
        'parent', 'resource', 'self', 'string', 'true', 'void',
    ];

    /**
     * Keywords that give an object schema a shape even when it declares no `properties` — their
     * presence means the schema is not free-form. See `isFreeFormObjectSchema()`.
     *
     * @var array<int, string>
     */
    private const array OBJECT_SHAPING_KEYWORDS = [
        '$ref',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'discriminator',
        'patternProperties',
        'propertyNames',
        'dependentSchemas',
        'dependentRequired',
        'unevaluatedProperties',
        'required',
    ];

    /**
     * The subset of `OBJECT_SHAPING_KEYWORDS` that constrains WHICH KEYS a payload may or must carry
     * without declaring a schema for any of them. On an object with no `properties` these do not shape
     * anything a DTO could hold, and materializing one produced a class with no properties that
     * silently swallowed the whole payload — measured at four keywords, in every mode:
     *
     *   - `required: [a]` — `a` must be there, its value is unconstrained;
     *   - `dependentRequired` — the same, conditionally;
     *   - `propertyNames` — constrains key names, values unconstrained;
     *   - `unevaluatedProperties: false` — with no `properties` to evaluate, forbids every key. The
     *     empty class was almost right and yet wrong in the way that matters: it ACCEPTED the keys and
     *     dropped them, instead of reporting them.
     *
     * So such a schema stays a map, and the keyword is enforced over the map by the validator (runtime)
     * or the emitted interpreter (symfony, laravel) — which is where every other key-level keyword,
     * `additionalProperties` and `patternProperties` included, is already enforced.
     *
     * They stay in `OBJECT_SHAPING_KEYWORDS` because `isScalarAliasSchema()` reads that list for a
     * different question: whether a `type: string` schema is a plain alias. A `required` beside a scalar
     * type is not, whatever it means.
     *
     * @var array<int, string>
     */
    private const array PROPERTY_KEY_CONSTRAINT_KEYWORDS = [
        'required',
        'dependentRequired',
        'propertyNames',
        'unevaluatedProperties',
    ];

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

    /**
     * Source endpoint (e.g. "GET /api/orders/shipment-report/{date}") of a DTO derived from an
     * operation — path/query parameters, an inline request body, or an inline response schema —
     * keyed by generated class name. Emitted as a `Route:` doc line so the endpoint a DTO belongs
     * to is discoverable.
     *
     * @var array<string, string>
     */
    public array $endpointByClass = [];

    /**
     * Origin of a DTO the generator synthesised itself (an inline nested object/array-item/allOf
     * schema that has no name in the spec), keyed by generated class name and expressed as
     * "OwnerClass.property" (with a "[]" suffix for array-item types). Emitted as a `From:` doc line
     * so the field a nameless DTO was inlined from is discoverable.
     *
     * @var array<string, string>
     */
    public array $relatedByClass = [];

    /**
     * Cache of parsed external documents: canonical file path => [schemaName => schemaDefinition].
     *
     * @var array<string, array<string, mixed>>
     */
    public array $externalDocSchemas = [];

    /**
     * Raw, unmodified schema definitions keyed by generated class name, captured for every
     * registered schema (DTOs and enums alike). Lets a property that only carries a $ref recover
     * keywords declared on the referenced schema itself — notably `default`, which lives on the
     * target schema, not on the inline `{$ref: ...}` node.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $rawSchemasByClass = [];

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

    public string $generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface';
    public string $unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\Contract\UnsetValue';

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
            mode: InputOption::VALUE_REQUIRED,
            description: 'Namespace for generated DTO classes (overrides directory-derived namespace)',
        );
        $this->addOption(
            name: 'dto-generator-directory',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Copy DTO generator services to specified subdirectory (can be absolute path)',
            default: false,
        );
        $this->addOption(
            name: 'dto-generator-namespace',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Custom namespace for DTO generator services',
        );
        $this->addOption(
            name: 'attributes',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Generation mode: "runtime" (default, library runtime), "symfony" (Symfony Validator/Serializer attributes), "laravel" (plain DTO + Laravel validation rules) or "laravel-data" (spatie/laravel-data Data classes)',
            default: self::ATTRIBUTE_MODE_RUNTIME,
        );
        $this->addOption(
            name: 'with-psr7',
            mode: InputOption::VALUE_NONE,
            description: 'Also copy the PSR-7 deserializer (DtoDeserializerPsr7) when vendoring the runtime. Requires symfony/psr-http-message-bridge in the consuming project.',
        );
        $this->addOption(
            name: 'ref',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Explicit output directory for an external $ref file or directory: "<refFileOrDir>=<directory>". A directory key maps every ref\'d file inside it. Repeatable. Requires a matching --ref-namespace.',
        );
        $this->addOption(
            name: 'ref-namespace',
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
            $io->error(sprintf(
                'Option --attributes must be one of: %s.',
                implode(', ', array_map(static fn(string $known): string => '"' . $known . '"', self::ATTRIBUTE_MODES)),
            ));
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
            $io->error('Option --namespace cannot be empty. Example: --namespace=Generated\Test');
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
            $this->generatedDtoInterfaceImportFqcn = $dtoGeneratorNamespace . '\GeneratedDtoInterface';
            $this->unsetValueImportFqcn = $dtoGeneratorNamespace . '\UnsetValue';
        } else {
            $this->generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface';
            $this->unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\Contract\UnsetValue';
        }

        $refOption = $input->getOption('ref');
        $refNamespaceOption = $input->getOption('ref-namespace');
        $refPairs = is_array($refOption) ? array_values(array_filter($refOption, 'is_string')) : [];
        $refNamespacePairs = is_array($refNamespaceOption) ? array_values(array_filter($refNamespaceOption, 'is_string')) : [];

        $withPsr7 = $input->getOption('with-psr7') === true;

        try {
            $this->setExternalRefMappings($refPairs, $refNamespacePairs);

            $count = $this->generateFromFile(filePath: $file, outputDirectory: $outputDirectory, namespace: $namespace, mode: $mode);

            if ($dtoGeneratorDirectory !== null) {
                $this->copyCommonServices(
                    outputDirectory: $outputDirectory,
                    namespace: $namespace,
                    dtoGeneratorDirectory: $dtoGeneratorDirectory,
                    dtoGeneratorNamespace: $dtoGeneratorNamespace,
                    withPsr7: $withPsr7,
                );
            }
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        if ($withPsr7) {
            $io->note(
                'PSR-7 deserializer copied. Install the bridge in your project: '
                . 'composer require symfony/psr-http-message-bridge',
            );
        }

        foreach ($this->generationWarnings as $warning) {
            $io->warning($warning);
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
        $data = $this->foldJsonSchemaDefs($data);

        $realFilePath = realpath($filePath);
        if ($realFilePath === false) {
            throw new RuntimeException(sprintf('Cannot resolve real path for file: %s', $filePath));
        }

        $this->initializeGeneration(
            outputDirectory: $outputDirectory,
            namespace: $namespace,
            rootSpecFile: $realFilePath,
        );
        // After initializeGeneration: it resets the per-run document state this reads into.
        $this->readDocumentLevelFields($data);
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
     * Folds JSON Schema's `$defs` (2019-09/2020-12) into OpenAPI's `components.schemas`:
     * every root-level `$defs` entry is copied into `components.schemas` (an existing
     * component of the same name wins) and every `#/$defs/X` pointer (local `#/$defs/X`
     * OR external `file.yaml#/$defs/X`) is rewritten to the `#/components/schemas/X` form.
     * The rest of the generator resolves only `#/components/schemas/`, so it then handles
     * `$defs`-based specs unchanged. Applied to the root document AND each external file
     * (see externalSchemasOf), so cross-file `$defs` references resolve too.
     *
     * Not folded: subschema-LOCAL `$defs` (e.g. `#/components/schemas/Foo/$defs/Bar`) —
     * rare; prefer `components.schemas` for shared types.
     *
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function foldJsonSchemaDefs(array $openApi): array
    {
        $defs = $openApi['$defs'] ?? null;
        $hasDefs = is_array($defs) && $defs !== [];

        if ($hasDefs) {
            $components = is_array($openApi['components'] ?? null) ? $openApi['components'] : [];
            $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
            foreach ($defs as $name => $schema) {
                if (is_string($name) && !array_key_exists($name, $schemas)) {
                    $schemas[$name] = $schema;
                }
            }
            $components['schemas'] = $schemas;
            $openApi['components'] = $components;
            unset($openApi['$defs']);
        }

        $rewritten = $this->rewriteDefsRefs($openApi);

        return is_array($rewritten) ? $rewritten : $openApi;
    }

    /**
     * Recursively rewrites every `$ref` pointer containing `#/$defs/` (local or
     * external-file) to the `#/components/schemas/` form. Subschema-local `$defs`
     * pointers (`.../Foo/$defs/Bar`, no leading `#/`) are intentionally left untouched.
     * Other keys/values are returned unchanged.
     */
    private function rewriteDefsRefs(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $result = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_contains($value, '#/$defs/')) {
                $result[$key] = str_replace('#/$defs/', '#/components/schemas/', $value);
                continue;
            }
            $result[$key] = $this->rewriteDefsRefs($value);
        }

        return $result;
    }

    /**
     * @param array<mixed> $openApi
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace, string $mode = self::ATTRIBUTE_MODE_RUNTIME): int
    {
        $this->setAttributeMode($mode);
        $this->initializeGeneration(outputDirectory: $outputDirectory, namespace: $namespace, rootSpecFile: null);
        $this->readDocumentLevelFields($openApi);
        $openApi = $this->foldJsonSchemaDefs($openApi);
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

            $className = $this->schemaClassName($schemaName);
            $this->registerSchema(className: $className, schemaDefinition: $schemaDefinition, sourceFile: null);
        }

        return $this->finalizeGeneration();
    }

    private function setAttributeMode(string $mode): void
    {
        if (!in_array($mode, self::ATTRIBUTE_MODES, true)) {
            throw new RuntimeException(sprintf(
                'Unknown generation mode: %s (expected one of: %s).',
                $mode,
                implode(', ', array_map(static fn(string $known): string => '"' . $known . '"', self::ATTRIBUTE_MODES)),
            ));
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
        bool $withPsr7 = false,
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

        // PSR-7 deserializer is optional: only vendored when explicitly requested, so the
        // default copy stays free of the symfony/psr-http-message-bridge dependency.
        if ($withPsr7) {
            $filesToCopy[] = 'Service/DtoDeserializerPsr7.php';
        }

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
                '/namespace OpenapiPhpDtoGenerator\\\(Contract|Service);/',
                'namespace ' . $targetNamespace . ';',
                $content,
            ) ?? $content;

            $content = preg_replace(
                '/use OpenapiPhpDtoGenerator\\\(Contract|Service)\\\/',
                'use ' . $targetNamespace . '\\',
                $content,
            ) ?? $content;

            // Remove self-namespace imports (same namespace as target)
            $content = preg_replace(
                '/^use ' . preg_quote($targetNamespace, '/') . '\\\[^;]+;\n/m',
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
        $this->documentSelfUri = null;
        $this->generationWarnings = [];
        $this->requestPayloadClasses = [];
        $this->parentClasses = [];
        $this->unionInterfacesByClass = [];
        $this->discriminatorSchemas = [];
        $this->schemaSourceFiles = [];
        $this->schemaNamespaces = [];
        $this->schemaOutputDirectories = [];
        $this->enumSourceFiles = [];
        $this->enumNamespaces = [];
        $this->enumOutputDirectories = [];
        $this->externalDocSchemas = [];
        $this->endpointByClass = [];
        $this->relatedByClass = [];
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

        // Every schema is registered by now (including nested and external ones), which is what
        // makes the document-wide decision below reliable.
        $this->serializationGroupsRequired = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY
            && $this->documentNeedsSerializationGroups();
        $this->resetSymfonyReachabilityCache();

        $this->prepareOutputDirectory($this->baseOutputDirectory);
        $this->warnAboutClassNamesTheEmittedCodeAlsoUses();

        $generatedCount = 0;

        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            // A component schema whose top-level type is `array` is a type alias (a list), not an
            // object — references to it resolve to the aliased array type, so no empty DTO class
            // file is emitted for it.
            //
            // A free-form `{type: object}` component keeps its class on purpose. Where it is USED
            // as a value (a property, an array item, a `$ref` target) it resolves to a map so the
            // payload survives — but the class itself is also how a response schema is named, so
            // deleting it would take away a type the application may reference.
            if (($schemaDefinition['type'] ?? null) === 'array' || $this->isScalarAliasSchema($schemaDefinition)) {
                continue;
            }

            $schemaMetadata = $this->analyzeSchema(className: $className, schemaDefinition: $schemaDefinition);
            $this->warnAboutUnhydratableUnionProperties($className, $schemaMetadata['properties']);
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

            // Laravel mode also emits the first-party entry point for an INCOMING payload, so the
            // application type-hints it and gets a validated, typed object without writing anything.
            if ($this->laravelEmitsFormRequestFor($className)) {
                file_put_contents(
                    rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $className . 'FormRequest.php',
                    $this->renderLaravelFormRequestClass($namespace, $className),
                );
                $generatedCount++;
            }
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

            $className = $this->schemaClassName($schemaName);
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
     * A named `type: string`/`type: integer` schema whose `enum` holds a member of another type
     * cannot become a backed enum, and the fall-through is worse than a refusal: the schema is
     * synthesized into a DTO class with no properties, so generation "succeeds" and then EVERY
     * request carrying the field dies with `Cannot deserialize nested DTO X from non-array value`.
     * The message here names the schema and the offending value instead.
     *
     * A `null` member of a `nullable` enum is not such a member — that is the ordinary way to spell
     * an optional enum, and the property is rendered nullable.
     *
     * @param array<string, mixed> $schemaDefinition
     */
    private function assertEnumMembersMatchDeclaredType(string $className, array $schemaDefinition): void
    {
        $enum = $schemaDefinition['enum'] ?? null;
        $type = $schemaDefinition['type'] ?? null;
        if (!is_array($enum) || $enum === [] || !in_array($type, ['string', 'integer'], true)) {
            return;
        }

        $nullable = ($schemaDefinition['nullable'] ?? false) === true;

        foreach ($enum as $value) {
            if ($value === null && $nullable) {
                continue;
            }
            if ($type === 'integer' ? is_int($value) : is_string($value) || is_int($value)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Enum schema %s declares type %s but contains the %s value %s. '
                . 'A backed enum cannot represent it: fix the spec, or drop the `type` so the '
                . 'values are validated as a plain enum constraint.',
                $className,
                $type,
                get_debug_type($value),
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ));
        }
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function registerSchema(string $className, array $schemaDefinition, ?string $sourceFile): void
    {
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);

        // Keep the raw definition (including enums, which otherwise return early below) so a
        // referencing property can later read keywords declared on the target, e.g. `default`.
        $this->rawSchemasByClass[$className] = $schemaDefinition;

        $this->assertEnumMembersMatchDeclaredType($className, $schemaDefinition);

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

            // Discriminator children are addressed as plain string values in `discriminator.mapping`,
            // not as `$ref` nodes, so the generic walk below would miss them. Register each mapping
            // target (and its transitive refs) so subtype DTOs are emitted, not just referenced.
            if ($key === 'discriminator' && is_array($value)) {
                $mapping = $value['mapping'] ?? null;
                if (is_array($mapping)) {
                    foreach ($mapping as $mappingRef) {
                        if (is_string($mappingRef)) {
                            $this->ensureSchemaRefRegistered(ref: $mappingRef, currentSourceFile: $currentSourceFile);
                        }
                    }
                }
            }

            if (is_array($value)) {
                $this->scanExternalSchemaRefs(node: $value, currentSourceFile: $currentSourceFile);
            }
        }
    }

    private function ensureSchemaRefRegistered(string $ref, ?string $currentSourceFile): void
    {
        $ref = $this->stripDocumentSelfPrefix($ref);

        if (str_starts_with($ref, '#/components/schemas/')) {
            // A local pointer inside an external document addresses a sibling schema of THAT file,
            // not the root spec. Register it (and its own transitive refs) against the external
            // file so same-file children of a cross-file ref target are emitted too. Local pointers
            // of the root document are already handled by registerDocumentSchemas.
            if ($currentSourceFile !== null && $currentSourceFile !== $this->rootSpecFile) {
                $schemaName = $this->externalPointerSchemaName($ref);
                if ($schemaName !== null) {
                    $this->registerExternalSchema(externalFile: $currentSourceFile, schemaName: $schemaName);
                }
            }

            return;
        }

        $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $currentSourceFile);
        if ($resolved === null) {
            return;
        }

        [$externalFile, $pointer] = $resolved;
        $schemaName = $this->externalPointerSchemaName($pointer);
        if ($schemaName === null) {
            // Deeper pointer (e.g. .../properties/id). A scalar target is inlined at type-resolution
            // time (see resolvePropertyType); nothing to register here, so we do NOT pull the whole
            // external document and its unrelated schema graph.
            return;
        }

        // Register only the referenced schema (plus its transitive refs), not every schema in the file.
        $this->registerExternalSchema(externalFile: $externalFile, schemaName: $schemaName);
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

    /**
     * Parse (once, cached) the components.schemas map of an external document.
     *
     * @return array<string, mixed>
     */
    private function externalSchemasOf(string $filePath): array
    {
        if (array_key_exists($filePath, $this->externalDocSchemas)) {
            return $this->externalDocSchemas[$filePath];
        }

        $data = $this->parseSpecFile($filePath);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('OpenAPI root must be an object/array in %s.', $filePath));
        }
        // Fold this external document's own `$defs` into its components.schemas (and rewrite
        // its internal `#/$defs/` pointers) so cross-file `file#/$defs/X` references resolve.
        $data = $this->foldJsonSchemaDefs($data);

        $schemas = $this->extractSchemas($data);

        return $this->externalDocSchemas[$filePath] = $schemas;
    }

    /**
     * Returns the schema name when the pointer addresses a top-level schema exactly
     * (#/components/schemas/Name), or null for an internal pointer or a deeper pointer
     * (e.g. #/components/schemas/Name/properties/id).
     */
    private function externalPointerSchemaName(string $pointer): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($pointer, $prefix)) {
            return null;
        }

        $path = substr($pointer, strlen($prefix));
        if ($path === '' || str_contains($path, '/')) {
            return null;
        }

        return $path;
    }

    /**
     * Walk a JSON pointer (#/components/schemas/Name/properties/x/...) inside an external document
     * and return the addressed node, or null when it cannot be resolved.
     */
    private function resolveExternalPointerNode(string $filePath, string $pointer): mixed
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($pointer, $prefix)) {
            return null;
        }

        $segments = explode('/', substr($pointer, strlen($prefix)));
        $schemaName = array_shift($segments);
        if ($schemaName === '') {
            return null;
        }

        $node = $this->externalSchemasOf($filePath)[$schemaName] ?? null;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isScalarSchemaDefinition(array $schema): bool
    {
        if (array_key_exists('$ref', $schema)) {
            return false;
        }

        return in_array($schema['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            && !array_key_exists('properties', $schema)
            && !array_key_exists('items', $schema);
    }

    /**
     * Inline (B): when a $ref points deeper than a top-level external schema and the target is a
     * scalar, return that scalar sub-schema so the caller can treat it as if it were declared inline.
     * Avoids dragging the whole external document in just to reuse a primitive property type.
     *
     * @return array<string, mixed>|null
     */
    private function tryInlineExternalScalarRef(string $ref, ?string $currentSourceFile): ?array
    {
        if (str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $currentSourceFile);
        if ($resolved === null) {
            return null;
        }

        [$externalFile, $pointer] = $resolved;
        if ($this->externalPointerSchemaName($pointer) !== null) {
            return null;
        }

        $node = $this->resolveExternalPointerNode($externalFile, $pointer);

        return is_array($node) && $this->isScalarSchemaDefinition($node) ? $node : null;
    }

    /**
     * Register a single schema from an external document (plus its transitive refs), instead of
     * registering every schema declared in that file.
     */
    private function registerExternalSchema(string $externalFile, string $schemaName): void
    {
        $className = $this->schemaClassName($schemaName);
        if (array_key_exists($className, $this->dtoSchemas) || array_key_exists($className, $this->enumSchemas)) {
            return;
        }

        $definition = $this->externalSchemasOf($externalFile)[$schemaName] ?? null;
        if (!is_array($definition)) {
            return;
        }

        if ($this->isPureExternalSchemaAlias($definition)) {
            $aliasRef = $definition['$ref'];
            if (is_string($aliasRef)) {
                $this->ensureSchemaRefRegistered(ref: $aliasRef, currentSourceFile: $externalFile);
            }
            return;
        }

        $this->registerSchema(className: $className, schemaDefinition: $definition, sourceFile: $externalFile);
        $this->scanExternalSchemaRefs(node: $definition, currentSourceFile: $externalFile);
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

    /**
     * Find the discriminator base class among a variant's allOf $refs.
     *
     * Returns the referenced class that declares a discriminator whose mapping lists
     * $variantClass as a target, so the variant can `extends` it. Returns null when no such
     * ref exists, or when more than one qualifies (ambiguous — fall back to the ref-count rule).
     *
     * @param array<int, string> $refClassNames
     */
    private function findDiscriminatorBaseAmongRefs(string $variantClass, array $refClassNames): ?string
    {
        $matches = [];
        foreach ($refClassNames as $refClassName) {
            $meta = $this->discriminatorSchemas[$refClassName] ?? null;
            if ($meta === null) {
                continue;
            }

            if (in_array($variantClass, $meta['mapping'], true)) {
                $matches[$refClassName] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * Find the discriminator base a schema is a mapping target of, regardless of how it composes.
     * Used to link oneOf/anyOf-discriminator members (plain objects that carry no allOf $ref back
     * to the base) to their abstract base via `extends`. Returns null when the class is not a
     * mapping target, or is a target of more than one base (ambiguous).
     */
    private function discriminatorBaseForMember(string $memberClass): ?string
    {
        $matches = [];
        foreach ($this->discriminatorSchemas as $baseClass => $meta) {
            if ($baseClass === $memberClass) {
                continue;
            }
            if (in_array($memberClass, $meta['mapping'], true)) {
                $matches[$baseClass] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * True when a schema is a discriminator base expressed with `oneOf`/`anyOf` (the composition
     * pattern). Such a base has no shared properties to inherit, so it is emitted as an abstract
     * class carrying the discriminator methods, and its members `extends` it. (The allOf pattern,
     * by contrast, makes the base a concrete class the variants already extend.)
     */
    private function isOneOfDiscriminatorBase(string $className): bool
    {
        if (!array_key_exists($className, $this->discriminatorSchemas)) {
            return false;
        }

        $schema = $this->dtoSchemas[$className] ?? null;

        return is_array($schema)
            && (
                (array_key_exists('oneOf', $schema) && is_array($schema['oneOf']))
                || (array_key_exists('anyOf', $schema) && is_array($schema['anyOf']))
            );
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
            // A oneOf/anyOf discriminator base is an abstract parent its members extend.
            if ($this->isOneOfDiscriminatorBase($className)) {
                $this->parentClasses[$className] = true;
            }

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
        // Worklist to a fixpoint: collectUnionTypes may register new nested-union schemas
        // (inline oneOf/anyOf branches), which must in turn have their own members linked.
        // A plain foreach over dtoSchemas would not revisit schemas added mid-iteration.
        $processed = [];

        while (true) {
            $unprocessed = array_diff(array_keys($this->dtoSchemas), array_keys($processed));
            if ($unprocessed === []) {
                return;
            }

            foreach ($unprocessed as $schemaName) {
                $processed[$schemaName] = true;
                $schemaDefinition = $this->dtoSchemas[$schemaName];
                if (!array_key_exists('oneOf', $schemaDefinition) && !array_key_exists('anyOf', $schemaDefinition)) {
                    continue;
                }

                // A oneOf/anyOf schema with a discriminator is an abstract base, not a marker
                // interface: its members extend it (see analyzeSchema), so skip interface linking.
                if (array_key_exists($schemaName, $this->discriminatorSchemas)) {
                    continue;
                }

                $className = $this->schemaClassName($schemaName);

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

            // A branch that is itself a nested union (inline oneOf/anyOf) must become its own
            // union interface; otherwise its members are silently dropped from this union.
            // Register it as a member — detectUnionInterfaces' worklist then links the nested
            // union's own members, and the nested interface extends this owner (see the interface
            // branch in renderDtoClass).
            if (
                (array_key_exists('oneOf', $variant) && is_array($variant['oneOf']))
                || (array_key_exists('anyOf', $variant) && is_array($variant['anyOf']))
            ) {
                $suffix = $keyword === 'oneOf' ? 'OneOf' : 'AnyOf';
                $nestedUnionName = $ownerClassName . $suffix . ($index + 1);
                $this->registerSchema(
                    className: $nestedUnionName,
                    schemaDefinition: $variant,
                    sourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                $result[] = $nestedUnionName;
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

            // Referenced class names, in declaration order, used to detect a discriminator base.
            $refClassNames = [];
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (is_array($allOfItem) && array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $refClassNames[] = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );
                }
            }

            // A discriminator variant is usually declared as `allOf: [$ref base, $ref mixin, ...]`.
            // The plain ref-count rule below would flatten every branch and drop the `extends base`
            // link, so `is_a($variant, $base)` fails at runtime and a property typed as the base
            // cannot hold the variant. When this schema is a mapping target of one of its own allOf
            // refs, make that ref the parent and merge the remaining branches as own properties.
            // Otherwise keep the historical rule: a single $ref means inheritance, multiple $refs
            // are merged.
            $discriminatorParent = $this->findDiscriminatorBaseAmongRefs($className, $refClassNames);
            $extends = $discriminatorParent ?? (count($refClassNames) === 1 ? $refClassNames[0] : null);

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $refClassName = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );

                    // The parent branch is inherited (its properties arrive via getParentProperties);
                    // every other branch is merged as own properties.
                    if ($refClassName === $extends) {
                        continue;
                    }

                    foreach ($this->getSchemaProperties($refClassName) as $property) {
                        $allProperties[] = $property;
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

            // Own properties must not collide — case-insensitively — with the inherited accessors.
            $inheritedNames = $extends === null
                ? []
                : array_map(
                    static fn(array $property): string => $property['name'],
                    $this->getSchemaProperties($extends),
                );
            $allProperties = $this->dedupeCaseInsensitivePropertyNames($allProperties, $inheritedNames);

            return [
                'properties' => $allProperties,
                'extends' => $extends,
                'unionTypes' => [],
                'discriminator' => $this->discriminatorSchemas[$className] ?? null,
            ];
        }

        if (array_key_exists('oneOf', $schemaDefinition) && is_array($schemaDefinition['oneOf'])) {
            if (array_key_exists($className, $this->discriminatorSchemas)) {
                return $this->discriminatorBaseMetadata($className, $schemaDefinition);
            }

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
            if (array_key_exists($className, $this->discriminatorSchemas)) {
                return $this->discriminatorBaseMetadata($className, $schemaDefinition);
            }

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

        // A plain-object schema that is a mapping target of a oneOf/anyOf discriminator base has no
        // allOf $ref linking it to the base, so link it here: it extends the abstract base.
        $memberBase = $this->discriminatorBaseForMember($className);
        $memberExtends = ($memberBase !== null && $this->isOneOfDiscriminatorBase($memberBase)) ? $memberBase : null;

        return [
            'properties' => $this->extractProperties(schemaDefinition: $schemaDefinition, ownerClassName: $className),
            'extends' => $memberExtends,
            'unionTypes' => [],
            'discriminator' => $this->discriminatorSchemas[$className] ?? null,
        ];
    }

    /**
     * Metadata for a oneOf/anyOf discriminator base: an abstract class that carries the
     * discriminator methods (its members extend it). It has no union members and no shared
     * properties of its own.
     *
     * @param array<string, mixed> $schemaDefinition
     * @return SchemaMetadata
     */
    private function discriminatorBaseMetadata(string $className, array $schemaDefinition): array
    {
        return [
            'properties' => $this->extractProperties($schemaDefinition, $className),
            'extends' => null,
            'unionTypes' => [],
            'discriminator' => $this->discriminatorSchemas[$className],
            'abstract' => true,
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
            // Aliases are inlined FIRST: everything below reads the property's own keywords, and an
            // `enum` that arrives with the alias has to be marked for constraint validation like any
            // inline one — otherwise it is silently dropped and the values are never checked.
            $propertySchema = $this->inlineFreeFormObjectRef($propertySchema);
            $propertySchema = $this->inlineScalarAliasRef($propertySchema, $ownerClassName);
            if (is_array($propertySchema['items'] ?? null)) {
                // An `items: {$ref: Alias}` names the same alias one level down.
                $propertySchema['items'] = $this->inlineScalarAliasRef($propertySchema['items'], $ownerClassName);
            }
            $propertySchema = $this->markInlineEnumValidation($propertySchema);

            [$type, $nullableBySchema] = $this->resolvePropertyType(
                propertySchema: $propertySchema,
                ownerClassName: $ownerClassName,
                propertyName: $openApiPropertyName,
            );
            $isRequired = array_key_exists($openApiPropertyName, $requiredMap);
            $nullable = $nullableBySchema || !$isRequired;
            $default = $this->extractDefaultValue($propertySchema, $type);
            if (!array_key_exists('default', $propertySchema)) {
                // No inline default on the property itself: a pure `$ref` (or single-ref allOf)
                // inherits the `default` declared on the referenced schema. OpenAPI 3.0/3.1 both
                // treat this as valid — the default belongs to the target schema.
                $referencedDefault = $this->resolveReferencedDefault($propertySchema, $ownerClassName);
                if ($referencedDefault !== null) {
                    $default = $referencedDefault;
                }
            }
            $description = $this->extractDescription($propertySchema);
            $example = $this->extractExample($propertySchema);
            $temporalFormat = $this->resolveTemporalPhpDocFormat($propertySchema);
            $itemsTemporalFormat = $this->resolveItemsTemporalPhpDocFormat($propertySchema);
            $constraints = $this->extractValidationConstraints($propertySchema);

            $paramIn = $propertySchema['x-parameter-in'] ?? null;
            $isInPath = $paramIn === 'path';
            $isInQuery = $paramIn === 'query';
            $isInHeader = $paramIn === 'header';
            $isInCookie = $paramIn === 'cookie';
            // OAS 3.2 `in: querystring` binds the raw query string, so it is a source of its own.
            $isInQueryString = $paramIn === 'querystring';
            $parameterStyle = is_string($propertySchema['x-parameter-style'] ?? null)
                ? $propertySchema['x-parameter-style']
                : null;
            $parameterExplode = is_bool($propertySchema['x-parameter-explode'] ?? null)
                ? $propertySchema['x-parameter-explode']
                : null;
            $allowReserved = (bool)($propertySchema['x-parameter-allow-reserved'] ?? false);
            $allowEmptyValue = is_bool($propertySchema['x-parameter-allow-empty-value'] ?? null)
                ? $propertySchema['x-parameter-allow-empty-value']
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
            $normalizedName = $this->disambiguateCaseInsensitiveName($normalizedName, $openApiPropertyName, $normalizedToOpenApiName);
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
                'itemsTemporalFormat' => $itemsTemporalFormat,
                'inPath' => $isInPath,
                'inQuery' => $isInQuery,
                'inHeader' => $isInHeader,
                'inCookie' => $isInCookie,
                'inQueryString' => $isInQueryString,
                'parameterStyle' => $parameterStyle,
                'parameterExplode' => $parameterExplode,
                'allowReserved' => $allowReserved,
                'allowEmptyValue' => $allowEmptyValue,
                'constraints' => $constraints,
                'readOnly' => (bool)($propertySchema['readOnly'] ?? false),
                'writeOnly' => (bool)($propertySchema['writeOnly'] ?? false),
                'deprecated' => (bool)($propertySchema['deprecated'] ?? false),
                'isMap' => $this->isMapType($type),
            ];
        }

        return $result;
    }

    /**
     * A `type: object` + `additionalProperties` schema (a string-keyed map) is represented as the
     * generic type `array<string, V>`. Such a field must serialize as a JSON object, not a JSON
     * array — otherwise dense integer-like keys (0, 1, 2, …) make json_encode emit a list and the
     * map's keys are lost.
     */
    private function isMapType(string $type): bool
    {
        return str_starts_with($type, 'array<string, ');
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function extractValidationConstraints(array $propertySchema): array
    {
        $propertySchema = $this->normalizeNullableBranchInAllOf($propertySchema);
        // Applied HERE rather than where the property schemas are prepared, because that is not the
        // only entrance: Symfony, Laravel and Yii3 modes extract the constraints of a whole CLASS
        // straight from its registered schema, so anything done to the prepared property schema was
        // invisible to four modes out of five. Runtime mode alone saw it, which is exactly how the
        // hole looked when measured.
        $propertySchema = $this->inlineNestedContainerValidation($propertySchema);

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
            // JSON Schema 2019-09/2020-12 (OpenAPI 3.1): enforced against inline composed
            // objects/arrays that are not materialized into a dedicated nested DTO. May be a
            // bool (kept verbatim) or a schema (scrubbed in scrubUnvalidatableSubschemas).
            'unevaluatedProperties',
            'unevaluatedItems',
            // String content assertions (JSON Schema 2019-09/2020-12, OpenAPI 3.1).
            // contentEncoding/contentMediaType are scalars; contentSchema is a subschema
            // (scrubbed in scrubUnvalidatableSubschemas).
            'contentEncoding',
            'contentMediaType',
            'contentSchema',
        ];

        // NOTE: property-level enums are usually materialized to PHP backed enums and therefore
        // don't need an explicit enum validator here. If enum synthesis is not possible
        // (`x-php-inline-enum=true`, e.g. bool/null members), keep enum as an inline constraint.

        $constraints = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $propertySchema)) {
                continue;
            }

            $constraints[$key] = $propertySchema[$key];
        }

        if (
            ($propertySchema['x-php-inline-enum'] ?? false) === true
            && is_array($propertySchema['enum'] ?? null)
            && $propertySchema['enum'] !== []
        ) {
            $constraints['enum'] = $propertySchema['enum'];
        }

        // A `string` + `format: binary` property is materialized as an UploadedFile, not a
        // string. Forwarding `type: string` would make the validator reject the uploaded file
        // ("param must be of type string") at deserialization time; the file is validated by its
        // PHP type instead. Drop the string type/format so no string constraint applies.
        if (($propertySchema['type'] ?? null) === 'string' && ($propertySchema['format'] ?? null) === 'binary') {
            unset($constraints['type'], $constraints['format']);
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
                if (
                    $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY
                    && $unionKey === 'oneOf'
                    && $extracted === []
                    && array_key_exists('$ref', $variant)
                    && is_string($variant['$ref'])
                ) {
                    $branchClass = $this->schemaRefToClassName($variant['$ref']);
                    if ($branchClass !== 'mixed') {
                        $extracted = ['x-php-instanceof' => $branchClass];
                    }
                }
                if ($extracted === []) {
                    $hasUnvalidatableBranch = true;
                    break;
                }
                $branchConstraints[] = $extracted;
            }

            if (!$hasUnvalidatableBranch && $branchConstraints !== []) {
                // `nullable: true` NEXT TO a union says the same thing as spelling a `{type: null}`
                // variant inside it, and only the spelled form reached the emitted interpreter. So a
                // document written the first way had its own `null` refused — "does not match any oneOf
                // branch (expected integer or string, got null)" — in every mode but runtime, which reads
                // the schema directly and always accepted it. Normalising the one spelling into the other
                // is what closes that, and it reuses the branch matching that already worked.
                if ($this->schemaAllowsNull($propertySchema) && !$this->unionBranchesAcceptNull($branchConstraints)) {
                    $branchConstraints[] = ['type' => 'null'];
                }

                $constraints[$unionKey] = $branchConstraints;
                if ($unionKey === 'oneOf' && is_array($propertySchema['discriminator'] ?? null)) {
                    $discriminator = $propertySchema['discriminator'];
                    $propertyName = $discriminator['propertyName'] ?? null;
                    $mapping = $discriminator['mapping'] ?? null;
                    if (is_string($propertyName) && is_array($mapping) && $mapping !== []) {
                        $classMap = [];
                        foreach ($mapping as $discriminatorValue => $ref) {
                            if (!is_string($discriminatorValue) || !is_string($ref)) {
                                continue;
                            }
                            $mappedClass = $this->schemaRefToClassName($ref);
                            if ($mappedClass === 'mixed') {
                                continue;
                            }
                            $classMap[$discriminatorValue] = $mappedClass;
                        }
                        if ($classMap !== []) {
                            $constraints['x-discriminator-property'] = $propertyName;
                            $constraints['x-discriminator-php-property'] = $this->normalizePropertyName($propertyName);
                            $constraints['x-discriminator-map'] = $classMap;
                        }
                    }
                }
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
     * Everything at depth two or deeper inside a container, made validatable.
     *
     * At depth ONE the generator materializes: `items: {$ref: StrEnum}` becomes a PHP enum and
     * `items: {enum: [...]}` becomes a synthesized one, so the value's own type is the check and the
     * constraints deliberately carry no `enum` (see `extractValidationConstraints()`).
     *
     * Below that it materializes nothing — `resolveNestedContainerDocType()` declares `mixed` for
     * exactly that reason — and the same two spellings were then checked by NOTHING: a `$ref` is not
     * a constraint keyword, so `scrubUnvalidatableSubschemas()` dropped the whole subschema, and an
     * unmarked `enum` was filtered out of it. `array<array<StrEnum>>` holding the string `"zzz"`, or
     * an integer, came back from `validate()` with no problems at all.
     *
     * So at depth two and below: a `$ref` is inlined even when it names a backed-enum schema (there is
     * no class here to carry the check), and every `enum` is marked for inline validation.
     *
     * @param array<string, mixed> $schema
     * @param int $depth how many containers were entered to reach this schema
     * @param bool $belowMaterialization whether no class is generated for anything from here down
     * @param array<int, string> $visitedRefs refs already inlined on THIS path, to stop a cycle
     * @return array<string, mixed>
     */
    private function inlineNestedContainerValidation(
        array $schema,
        int $depth = 0,
        bool $belowMaterialization = false,
        array $visitedRefs = [],
    ): array {
        // Two containers down, nothing is materialized any more — and that stays true for everything
        // INSIDE what gets inlined here, however many `properties` hops away it is. A depth counter
        // alone said otherwise: a property of an inlined object read as depth 0, so its own `$ref`
        // was left for the scrubber to drop and the values under it went unchecked again.
        $belowMaterialization = $belowMaterialization || $depth >= 2;

        if ($belowMaterialization) {
            $ref = $schema['$ref'] ?? null;
            if (is_string($ref) && !in_array($ref, $visitedRefs, true)) {
                $definition = $this->nestedScalarRefDefinition($ref, $this->rootSpecFile)
                    ?? $this->nestedObjectRefDefinition($ref);
                if ($definition !== null) {
                    // Recorded on THIS path only: a schema reachable twice through different
                    // properties is inlined in both, a schema reachable from itself is inlined once
                    // and its recursion left as the bare `$ref` the scrubber drops. Without it a
                    // self-referential component inlined until memory ran out.
                    $visitedRefs[] = $ref;
                    unset($schema['$ref']);
                    $schema += $definition;
                }
            }

            if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
                $schema['x-php-inline-enum'] = true;
            }
        }

        foreach (['items', 'additionalProperties'] as $containerKey) {
            if (is_array($schema[$containerKey] ?? null)) {
                $schema[$containerKey] = $this->inlineNestedContainerValidation(
                    schema: $schema[$containerKey],
                    depth: $depth + 1,
                    belowMaterialization: $belowMaterialization,
                    visitedRefs: $visitedRefs,
                );
            }
        }

        // A PROPERTY restarts the container count — it is a value of its own, and where an item DID
        // become a DTO that class runs this for itself. What it does not restart is
        // `$belowMaterialization`: below the first level of items there is no class to run it.
        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (is_array($propertySchema)) {
                    $schema['properties'][$name] = $this->inlineNestedContainerValidation(
                        schema: $propertySchema,
                        depth: 0,
                        belowMaterialization: $belowMaterialization,
                        visitedRefs: $visitedRefs,
                    );
                }
            }
        }

        return $schema;
    }

    /**
     * The definition behind a `$ref` to an OBJECT-shaped component, for a position where no class is
     * generated for it.
     *
     * At the first level of items that ref becomes a DTO and the DTO checks itself. Below it nothing
     * does: `array<array<Tag>>` accepted an item with `id` missing, with `id` below its `minimum`, and
     * even a bare string where the object belonged — every one of them silently, because `$ref` is not
     * a constraint keyword and `scrubUnvalidatableSubschemas()` dropped the whole subschema.
     *
     * Inlining the component's own `properties`/`required` puts those checks back. The VALUE is still
     * not hydrated — it stays the `stdClass` `json_decode()` produced, which is why the declared type
     * remains `array<array<mixed>>`.
     *
     * A free-form object is skipped: it declares nothing to check, and inlining `{type: object}` would
     * only add a type assertion the container already makes.
     *
     * @return array<string, mixed>|null
     */
    private function nestedObjectRefDefinition(string $ref): ?array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $definition = $this->dtoSchemas[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? null;
        if (!is_array($definition) || $this->isEnumSchema($definition)) {
            return null;
        }

        $hasProperties = is_array($definition['properties'] ?? null) && $definition['properties'] !== [];
        $hasRequired = is_array($definition['required'] ?? null) && $definition['required'] !== [];

        return $hasProperties || $hasRequired ? $definition : null;
    }

    /**
     * The definition behind a `$ref` used at depth two or deeper: any scalar-typed schema, an enum
     * included.
     *
     * `scalarAliasDefinition()` refuses a backed-enum schema because at depth one that ref DOES get a
     * class. Here it does not, so refusing would leave the values unchecked.
     *
     * @return array<string, mixed>|null
     */
    private function nestedScalarRefDefinition(string $ref, ?string $currentSourceFile): ?array
    {
        $alias = $this->scalarAliasDefinition($ref, $currentSourceFile);
        if ($alias !== null) {
            return $alias;
        }

        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $className = $this->schemaClassName(substr($ref, strlen($prefix)));

        $definition = $this->dtoSchemas[$className] ?? null;
        if (
            is_array($definition)
            && $this->isEnumSchema($definition)
            && in_array($definition['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
        ) {
            return $definition;
        }

        // A named enum COMPONENT is registered as an enum rather than a schema, and its members live
        // under `values`. Spelled back into `enum` here, which is the keyword a constraint carries.
        $enum = $this->enumSchemas[$className] ?? null;
        if (
            $enum !== null
            && $enum['values'] !== []
            && in_array($enum['type'], ['string', 'integer', 'number', 'boolean'], true)
        ) {
            return ['type' => $enum['type'], 'enum' => $enum['values']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function markInlineEnumValidation(array $schema): array
    {
        if (
            is_array($schema['enum'] ?? null)
            && $schema['enum'] !== []
            && !$this->canGenerateBackedEnumFromSchema($schema)
        ) {
            $schema['x-php-inline-enum'] = true;
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->markInlineEnumValidation($schema['items']);
        }

        return $schema;
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
        foreach (['items', 'contains', 'propertyNames', 'if', 'then', 'else', 'contentSchema'] as $key) {
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

        // additionalProperties / unevaluatedProperties / unevaluatedItems may be a bool
        // (keep verbatim) or a schema (scrub). A schema that scrubs to [] means "allow
        // anything" — a no-op for these keywords — so the key is dropped.
        foreach (['additionalProperties', 'unevaluatedProperties', 'unevaluatedItems'] as $boolOrSchemaKey) {
            if (!array_key_exists($boolOrSchemaKey, $constraints) || !is_array($constraints[$boolOrSchemaKey])) {
                continue;
            }
            $extracted = $this->extractValidationConstraints($constraints[$boolOrSchemaKey]);
            if ($extracted === []) {
                unset($constraints[$boolOrSchemaKey]);
            } else {
                $constraints[$boolOrSchemaKey] = $extracted;
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

        // B: a $ref pointing to a scalar property of an external schema is inlined as that scalar,
        // so we neither emit a bogus class name nor pull in the whole external document.
        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            $inlinedScalar = $this->tryInlineExternalScalarRef(
                ref: $propertySchema['$ref'],
                currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            if ($inlinedScalar !== null) {
                if ($nullable) {
                    $inlinedScalar['nullable'] = true;
                }

                return $this->resolvePropertyType(
                    propertySchema: $inlinedScalar,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
            }
        }

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
                $binaryType = $this->resolveBinaryRefType((string)$propertySchema['allOf'][0]['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($binaryType !== null) {
                    return [$binaryType, $nullable];
                }

                $temporalType = $this->resolveTemporalRefType((string)$propertySchema['allOf'][0]['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($temporalType !== null) {
                    return [$temporalType, $nullable];
                }

                $refType = $this->schemaRefToClassName(
                    ref: $propertySchema['allOf'][0]['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                return [$refType, $nullable];
            }

            // allOf wrapping a single composed union (e.g. `allOf: [{ oneOf: [...] }]`) resolves to
            // the union type, not an empty merged object. Without this the property collapses to a
            // standalone empty DTO whose toArray() is always [].
            if (count($propertySchema['allOf']) === 1 && is_array($propertySchema['allOf'][0])) {
                $only = $propertySchema['allOf'][0];
                foreach (['oneOf', 'anyOf'] as $unionKeyword) {
                    if (array_key_exists($unionKeyword, $only) && is_array($only[$unionKeyword])) {
                        [$unionType, $unionNullable] = $this->resolveComposedUnionPropertyType(
                            propertySchema: $only,
                            keyword: $unionKeyword,
                            ownerClassName: $ownerClassName,
                            propertyName: $propertyName,
                        );
                        return [$unionType, $nullable || $unionNullable];
                    }
                }
            }

            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $mergedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $this->recordSynthesizedOrigin($mergedClassName, $ownerClassName, $propertyName);
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
            $binaryType = $this->resolveBinaryRefType($propertySchema['$ref'], $this->getSchemaSourceFile($ownerClassName));
            if ($binaryType !== null) {
                return [$binaryType, $nullable];
            }

            $temporalType = $this->resolveTemporalRefType($propertySchema['$ref'], $this->getSchemaSourceFile($ownerClassName));
            if ($temporalType !== null) {
                return [$temporalType, $nullable];
            }

            // A $ref to a component schema whose top-level type is `array` is a type alias, not an
            // object: resolve it to the aliased array type (e.g. array<Item>) so the property
            // is typed as a list, instead of pointing at an empty generated class.
            $aliasArrayType = $this->resolveArrayAliasRefType(
                ref: $propertySchema['$ref'],
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
            if ($aliasArrayType !== null) {
                return [$aliasArrayType, $nullable];
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
            && $this->canGenerateBackedEnumFromSchema($propertySchema)
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
            $this->recordSynthesizedOrigin($enumName, $ownerClassName, $propertyName);
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
            if ($this->isPatternPropertiesOnlyObjectSchema($propertySchema)) {
                $mapValueType = $this->resolvePatternPropertiesValueType(
                    propertySchema: $propertySchema,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
                return ['array<string, ' . $mapValueType . '>', $nullable];
            }

            if ($this->isMapLikeObjectSchema($propertySchema)) {
                $mapValueType = $this->resolveAdditionalPropertiesValueType(
                    propertySchema: $propertySchema,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
                return ['array<string, ' . $mapValueType . '>', $nullable];
            }

            $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $nestedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $this->recordSynthesizedOrigin($nestedClassName, $ownerClassName, $propertyName);
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
                $binaryItemType = $this->resolveBinaryRefType($items['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($binaryItemType !== null) {
                    return ['array<' . $itemPrefix . $binaryItemType . '>', $nullable];
                }

                $temporalItemType = $this->resolveTemporalRefType($items['$ref'], $this->getSchemaSourceFile($ownerClassName));
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
                if ($this->canGenerateBackedEnumFromSchema($items)) {
                    $enumName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                    $enumType = $this->resolveEnumBackingType($items);
                    /** @var array<int, string|int> $values */
                    $values = $items['enum'];
                    $this->registerEnum(
                        enumName: $enumName,
                        type: $enumType,
                        values: $values,
                        sourceFile: $this->getSchemaSourceFile($ownerClassName),
                        varnames: $this->extractEnumVarnames($items, $values),
                        descriptions: $this->extractEnumDescriptions($items, $values),
                    );
                    $this->recordSynthesizedOrigin($enumName, $ownerClassName, $propertyName, arrayItem: true);
                    return ['array<' . $itemPrefix . $enumName . '>', $nullable];
                }

                $itemEnumType = $items['type'] ?? null;
                if ($itemEnumType === 'boolean') {
                    return ['array<' . $itemPrefix . 'bool>', $nullable];
                }
            }

            $itemsType = $items['type'] ?? null;

            // A list of lists. Left to the scalar mapping below it landed on `default => 'mixed'`, so
            // `array<array<int>>` was declared `array<mixed>` — and `mixed` is the one item type
            // `DtoNormalizer::validate()` skips, which is why a matrix with a scalar where a row
            // belonged passed in silence.
            if ($itemsType === 'array') {
                return [
                    'array<' . $itemPrefix . $this->resolveNestedContainerDocType($items) . '>',
                    $nullable,
                ];
            }

            if ($itemsType === 'object') {
                // Free-form / pattern-keyed item objects have no fixed properties: synthesizing a
                // DTO for them would yield a class with an empty constructor and the payload data
                // would be dropped at deserialization. Keep them as maps instead.
                if ($this->isPatternPropertiesOnlyObjectSchema($items) || $this->isMapLikeObjectSchema($items)) {
                    return [
                        'array<' . $itemPrefix . $this->resolveNestedContainerDocType($items) . '>',
                        $nullable,
                    ];
                }

                $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $this->registerSchema(
                    className: $nestedClassName,
                    schemaDefinition: $items,
                    sourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                $this->recordSynthesizedOrigin($nestedClassName, $ownerClassName, $propertyName, arrayItem: true);
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

        $resolved = [];
        foreach ($allOf as $item) {
            if (!is_array($item) || !$this->canFlattenAllOfPropertyItem($item)) {
                return null;
            }

            // last-wins: each next allOf part overwrites previous keys
            $resolved = array_replace_recursive($resolved, $item);
        }

        $topLevel = $propertySchema;
        unset($topLevel['allOf']);
        $resolved = array_replace_recursive($resolved, $topLevel);

        // Only a merge that ends up describing a concrete value is usable here: a branch set of
        // pure constraints (`allOf: [{minLength: 3}]`) says nothing about the PHP type, so leave
        // such a schema to the regular object path instead of inventing a scalar.
        $describesValue = array_key_exists('type', $resolved)
            || array_key_exists('enum', $resolved)
            || array_key_exists('format', $resolved);

        return ($resolved !== [] && $describesValue) ? $resolved : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function canFlattenAllOfPropertyItem(array $item): bool
    {
        // Composition or an inline object means the branch is a schema in its own right — those
        // keep the class-synthesis path. Everything else (a type, an enum, or plain constraint
        // keywords such as `minLength` split into their own branch) merges into one property.
        return !array_key_exists('$ref', $item)
            && !array_key_exists('properties', $item)
            && !array_key_exists('allOf', $item)
            && !array_key_exists('oneOf', $item)
            && !array_key_exists('anyOf', $item);
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

            // OpenAPI 3.0 idiom for "X or null": a bare `{nullable: true}` branch (no type/$ref/
            // composition) just adds null to the union. Treat it as the null branch instead of
            // letting it resolve to `mixed` and collapse the whole union to mixed.
            if (($variant['nullable'] ?? false) === true && $this->isNullOnlyBranch($variant)) {
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
     * True when a union variant carries no type-bearing keyword — i.e. it only constrains
     * nullability (the OpenAPI 3.0 `{nullable: true}` idiom for adding null to a oneOf/anyOf).
     *
     * @param array<string, mixed> $variant
     */
    private function isNullOnlyBranch(array $variant): bool
    {
        foreach (
            ['type', '$ref', 'enum', 'const', 'oneOf', 'anyOf', 'allOf', 'not',
                'properties', 'items', 'additionalProperties', 'prefixItems', 'if'] as $key
        ) {
            if (array_key_exists($key, $variant)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function isMapLikeObjectSchema(array $propertySchema): bool
    {
        $properties = $propertySchema['properties'] ?? null;
        if (is_array($properties) && $properties !== []) {
            return false;
        }

        if (array_key_exists('additionalProperties', $propertySchema)) {
            return ($propertySchema['additionalProperties'] ?? null) !== false;
        }

        return $this->isFreeFormObjectSchema($propertySchema);
    }

    /**
     * A property that is a pure `$ref` to a free-form object component gets the referenced schema
     * inlined. Resolving the type alone is not enough: the constraints are read from the property
     * schema, and a bare `$ref` carries none, so the map would lose its `type: object` and the
     * deserializer would then reject a JSON object as "expects array".
     *
     * Sibling keywords on the property win over the referenced schema, per JSON Schema 2020-12.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineFreeFormObjectRef(array $propertySchema): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        if (!is_string($ref)) {
            return $propertySchema;
        }

        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return $propertySchema;
        }

        $definition = $this->dtoSchemas[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? null;
        if (!is_array($definition) || !$this->isFreeFormObjectSchema($definition)) {
            return $propertySchema;
        }

        unset($propertySchema['$ref']);

        return $propertySchema + $definition;
    }

    /**
     * A component whose top-level `type` is a scalar is a TYPE ALIAS, exactly like a `type: array`
     * component — `Uuid: {type: string, format: uuid}` names a string, it does not describe an
     * object. Materializing it produced a class with no properties, the referencing property was
     * typed with that class, and then every request carrying the field failed with
     * `Cannot deserialize nested DTO Uuid from non-array value`. The endpoint could not be used at
     * all, in either mode.
     *
     * The reference is therefore replaced by the referenced schema itself (siblings on the property
     * win, so a local `description` or `nullable` still overrides), which puts the property back on
     * the ordinary scalar path: the right PHP type AND the alias's own `format`/`minLength`/`enum`
     * as constraints.
     *
     * A named enum that CAN become a backed enum keeps its class — `isScalarAliasSchema()` excludes
     * it. One that cannot (a `nullable` enum carrying `null`, a `type: number` enum) is inlined and
     * validated as an enum constraint on a scalar property, which is what the inline spelling of the
     * same schema has always done.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineScalarAliasRef(array $propertySchema, string $ownerClassName): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        $sourceFile = $this->getSchemaSourceFile($ownerClassName);

        // `allOf: [{$ref: Alias}]` with nothing else is the same reference, spelled longer.
        if (!is_string($ref) && $this->isSingleRefAllOf($propertySchema)) {
            /** @var array<int, array<string, mixed>> $allOf */
            $allOf = $propertySchema['allOf'];
            $onlyRef = $allOf[0]['$ref'] ?? null;
            if (is_string($onlyRef) && $this->scalarAliasDefinition($onlyRef, $sourceFile) !== null) {
                unset($propertySchema['allOf']);
                $propertySchema['$ref'] = $onlyRef;
                $ref = $onlyRef;
            }
        }

        if (!is_string($ref)) {
            return $propertySchema;
        }

        $definition = $this->scalarAliasDefinition($ref, $sourceFile);
        if ($definition === null) {
            return $propertySchema;
        }

        unset($propertySchema['$ref']);

        return $propertySchema + $definition;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isSingleRefAllOf(array $schema): bool
    {
        $allOf = $schema['allOf'] ?? null;

        return is_array($allOf)
            && count($allOf) === 1
            && is_array($allOf[0] ?? null)
            && array_keys($allOf[0]) === ['$ref'];
    }

    /**
     * The definition behind a `$ref` when it names a scalar alias, otherwise null.
     *
     * A ref into another FILE is resolved too, the same way `resolveBinaryRefType()` does it: the
     * alias gets no class file either, so leaving an external one on class-name typing produced a
     * property typed with a class that is never written — a missing-class fatal instead of the
     * previous (useless but loadable) empty class.
     *
     * @return array<string, mixed>|null
     */
    private function scalarAliasDefinition(string $ref, ?string $currentSourceFile = null): ?array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;

            if (!str_starts_with($ref, $prefix)) {
                return null;
            }
        }

        $definition = $this->dtoSchemas[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? null;

        return is_array($definition) && $this->isScalarAliasSchema($definition) ? $definition : null;
    }

    /**
     * A schema that describes a scalar VALUE rather than an object: a plain top-level scalar `type`
     * and no object shape. A backed-enum schema is excluded — it has a generated class of its own —
     * and so is anything carrying `properties` or a composition keyword, which would make the
     * "scalar type" a contradiction the caller should not silently resolve.
     *
     * @param array<string, mixed> $schema
     */
    private function isScalarAliasSchema(array $schema): bool
    {
        if (!in_array($schema['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
            return false;
        }

        if (array_key_exists('properties', $schema) || $this->isEnumSchema($schema)) {
            return false;
        }

        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `{type: object}` with nothing else on it allows any properties — it is the same free-form
     * object as `{type: object, additionalProperties: true}`, only spelled shorter. Materializing
     * it into a DTO class produced a class with no properties, which silently swallowed the whole
     * payload, so it is treated as a map instead.
     *
     * Any keyword that gives the object a shape (composition, a reference, conditional or
     * pattern-based subschemas) disqualifies it: those schemas do describe properties, just not
     * inline.
     *
     * A keyword that constrains the KEYS without declaring a schema for any of them does NOT —
     * see `PROPERTY_KEY_CONSTRAINT_KEYWORDS`.
     *
     * @param array<string, mixed> $schema
     */
    private function isFreeFormObjectSchema(array $schema): bool
    {
        if (($schema['type'] ?? null) !== 'object') {
            return false;
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && $properties !== []) {
            return false;
        }

        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (
                array_key_exists($keyword, $schema)
                && !in_array($keyword, self::PROPERTY_KEY_CONSTRAINT_KEYWORDS, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * `patternProperties`-only object schemas are dictionary-shaped and should keep payload data as
     * a map; materializing them to an empty DTO makes the dynamic keys unreachable.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function isPatternPropertiesOnlyObjectSchema(array $propertySchema): bool
    {
        if (($propertySchema['type'] ?? null) !== 'object') {
            return false;
        }

        if (!is_array($propertySchema['patternProperties'] ?? null) || $propertySchema['patternProperties'] === []) {
            return false;
        }

        return !is_array($propertySchema['properties'] ?? null) || $propertySchema['properties'] === [];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolvePatternPropertiesValueType(
        array $propertySchema,
        string $ownerClassName,
        string $propertyName,
    ): string {
        $patternProperties = $propertySchema['patternProperties'] ?? null;
        if (!is_array($patternProperties) || $patternProperties === []) {
            return 'mixed';
        }

        $valueTypes = [];
        foreach ($patternProperties as $schema) {
            if (!is_array($schema)) {
                continue;
            }
            [$resolvedType] = $this->resolvePropertyType(
                propertySchema: $schema,
                ownerClassName: $ownerClassName,
                propertyName: $propertyName . 'PatternValue',
            );
            $valueTypes[$resolvedType] = true;
        }

        $resolved = array_keys($valueTypes);
        if ($resolved === []) {
            return 'mixed';
        }

        return count($resolved) === 1 ? $resolved[0] : 'mixed';
    }

    /**
     * The docblock type of a container nested inside another container: `array<int>` for the items of
     * `array<array<int>>`, `array<string, int>` for the values of `array<string, array<string, int>>`.
     *
     * Its own resolver rather than a recursion into `resolvePropertyType()`, for two reasons.
     *
     * That one REGISTERS a synthesized class for an object-shaped item — and at this depth nothing
     * hydrates one, so the class would be emitted into the output and never referenced by anything.
     *
     * And it would promise more than the generated code delivers. Casting stops at the first level of
     * items: a `$ref` two containers deep arrives as the `stdClass` `json_decode()` produced, never as
     * the DTO. `array<array<string, Tag>>` was exactly that lie — the declaration named `Tag`, the
     * value was a `stdClass`, and `validate()` reported nothing either way. So a value that would need
     * CONVERTING is declared `mixed`: a DTO, an enum, a date, an uploaded file, and `number` too,
     * because JSON hands `1` over as an int and nothing widens it at this depth.
     *
     * What survives is what `json_decode()` already produces in the declared form — `string`, `int`,
     * `bool` — plus containers, recursively.
     *
     * @param array<string, mixed> $schema the nested container's OWN schema
     */
    private function resolveNestedContainerDocType(array $schema, int $remainingDepth = 8): string
    {
        if ($remainingDepth <= 0) {
            return 'mixed';
        }

        $type = $schema['type'] ?? null;

        if ($type === 'array') {
            $items = $schema['items'] ?? null;

            return is_array($items)
                ? 'array<' . $this->nestedContainerValueDocType($items, $remainingDepth) . '>'
                : 'array';
        }

        if ($type === 'object' || $this->isMapLikeObjectSchema($schema)) {
            $valueSchema = $schema['additionalProperties'] ?? null;

            // `patternProperties` is the other dictionary spelling. One value schema is a type; several
            // are a union nothing here can narrow, so `mixed`.
            if (!is_array($valueSchema) && is_array($schema['patternProperties'] ?? null)) {
                $patterned = array_values(array_filter($schema['patternProperties'], 'is_array'));
                $valueSchema = count($patterned) === 1 ? $patterned[0] : null;
            }

            return is_array($valueSchema)
                ? 'array<string, ' . $this->nestedContainerValueDocType($valueSchema, $remainingDepth) . '>'
                : 'array<string, mixed>';
        }

        return 'mixed';
    }

    /**
     * One value of a nested container: another container, a scalar JSON already delivers in that form,
     * or `mixed`.
     *
     * @param array<string, mixed> $schema
     */
    private function nestedContainerValueDocType(array $schema, int $remainingDepth): string
    {
        $type = $schema['type'] ?? null;

        if ($type === 'array' || $type === 'object' || $this->isMapLikeObjectSchema($schema)) {
            return $this->resolveNestedContainerDocType($schema, $remainingDepth - 1);
        }

        // A `$ref` naming a scalar alias or an enum COMPONENT: the value here is that scalar, because
        // nothing casts it at this depth — an `enum` two containers deep arrives as the plain string
        // the payload carried, measured. A `$ref` to an OBJECT is the one that stays `mixed`: there
        // the value is the `stdClass` `json_decode()` produced, and naming the class would be exactly
        // the lie 2.15.4 removed.
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $definition = $this->nestedScalarRefDefinition($ref, $this->rootSpecFile);

            return $definition === null ? 'mixed' : $this->nestedScalarDocType($definition['type'] ?? null);
        }

        // `format: date` and an inline `enum` used to answer `mixed` here on the grounds that the
        // value is "really" a date or an enum case. It is not: one level up it becomes one, at this
        // depth nothing converts it, and `mixed` said less than was known — a consumer's PHPStan got
        // nothing where `string` was both true and useful.
        return $this->nestedScalarDocType($type);
    }

    /**
     * The PHP type of a scalar JSON value at a depth where nothing casts it.
     */
    private function nestedScalarDocType(mixed $type): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            // JSON hands `1` over as an int and `1.5` as a float, and neither is widened here.
            // Measured: ONE `type: number` array held both in the same payload, so `float` alone
            // would have been a lie half the time.
            'number' => 'float|int',
            default => 'mixed',
        };
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

        // A map whose values are themselves containers. Resolved WITHOUT the general resolver, which
        // would both synthesize a class nothing at that depth hydrates and name types the generated
        // code does not deliver — see `resolveNestedContainerDocType()`. It used to collapse to a bare
        // `mixed`, the one item type `DtoNormalizer::validate()` skips entirely.
        // A value with fixed `properties` is a DTO and stays one: that IS hydrated at this depth, and
        // the map-like predicate says no to it for exactly that reason.
        if (
            ($additionalProperties['type'] ?? null) === 'array'
            || $this->isPatternPropertiesOnlyObjectSchema($additionalProperties)
            || $this->isMapLikeObjectSchema($additionalProperties)
        ) {
            return $this->resolveNestedContainerDocType($additionalProperties);
        }

        [$valueType] = $this->resolvePropertyType($additionalProperties, $ownerClassName, $propertyName . 'Value');

        // A union is a claim no single item type can carry.
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

    /**
     * The temporal format of an ARRAY ITEM (or map value), or null when the items are not temporal.
     *
     * `items: {type: string, format: date}` types the item as `DateTimeImmutable` exactly like the
     * scalar case, so the getter owes the reader the same `Y-m-d` string. Without this the format is
     * known only to the schema and the item leaves as an RFC 3339 date-time — a response that
     * contradicts the spec it was generated from.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveItemsTemporalPhpDocFormat(array $propertySchema): ?string
    {
        $items = $propertySchema['items'] ?? $propertySchema['additionalProperties'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return $this->resolveTemporalPhpDocFormat($items);
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

        $className = $this->schemaClassName($schemaName);
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

    /**
     * If $ref points at a component schema whose top-level type is `array`, returns the aliased
     * array type (e.g. `array<Item>`) so a property referencing it is typed as a list rather
     * than an empty generated class. Returns null for object schemas and external refs (which keep
     * the class-name typing). Only local `#/components/schemas/...` aliases are redirected.
     */
    private function resolveArrayAliasRefType(string $ref, string $ownerClassName, string $propertyName): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        // A free-form object component is an alias too — for a map. Without this a `$ref` to
        // `{type: object}` points at a generated class with no properties, which drops the payload.
        if ($this->isFreeFormObjectSchema($definition)) {
            return 'array<string, mixed>';
        }

        if (($definition['type'] ?? null) !== 'array') {
            return null;
        }

        // Resolve relative to the alias schema itself so its `items` $ref resolves correctly.
        [$type] = $this->resolvePropertyType($definition, $className, $propertyName);

        return $type;
    }

    private function resolveBinaryRefType(string $ref, ?string $currentSourceFile = null): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (($definition['type'] ?? null) === 'string' && (($definition['format'] ?? null) === 'binary')) {
            return 'UploadedFile';
        }

        return null;
    }

    private function resolveTemporalRefType(string $ref, ?string $currentSourceFile = null): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
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
                ? $this->schemaClassName($schemaName)
                : 'mixed';
        }

        $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolvedExternal === null) {
            return 'mixed';
        }

        [$externalFile, $pointer] = $resolvedExternal;

        $schemaName = $this->externalPointerSchemaName($pointer);
        if ($schemaName === null) {
            // Deeper pointer (e.g. .../properties/id): not a class. Scalars are inlined upstream
            // (see resolvePropertyType); anything else is unsupported as a class reference.
            return 'mixed';
        }

        $this->registerExternalSchema(externalFile: $externalFile, schemaName: $schemaName);

        return $this->schemaClassName($schemaName);
    }

    private function getSchemaSourceFile(string $className): ?string
    {
        return $this->schemaSourceFiles[$className] ?? null;
    }

    /**
     * Spec path for a DTO's `Spec:` doc line, as a PhpStorm-navigable `@link` target relative to the
     * generated file's own directory (so Ctrl+B / Ctrl+Click opens the source yaml). The relative
     * geometry between the output directory and the spec file is stable across machines, so
     * generated output stays deterministic. Returns null when no source file is known (e.g.
     * generateFromArray).
     */
    private function resolveSpecLink(string $className): ?string
    {
        $specFile = $this->schemaSourceFiles[$className] ?? $this->enumSourceFiles[$className] ?? null;
        if ($specFile === null) {
            return null;
        }

        $outputDirectory = $this->schemaOutputDirectories[$className]
            ?? $this->enumOutputDirectories[$className]
            ?? $this->baseOutputDirectory;
        if ($outputDirectory === '') {
            return null;
        }

        return $this->makeRelativePath(
            fromDirectory: $this->toAbsolutePath($outputDirectory),
            toPath: $this->toAbsolutePath($specFile),
        );
    }

    /**
     * Resolves a path to absolute form for relative-path math. An already-absolute path is returned
     * unchanged; a relative one is anchored at the current working directory. The shared CWD prefix
     * cancels out in makeRelativePath, so the resulting relative link is independent of where the
     * generator ran.
     */
    private function toAbsolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();

        return $cwd === false ? $path : rtrim($cwd, '/') . '/' . $path;
    }

    /**
     * Records the field a nameless nested DTO was inlined from, as "OwnerClass.property" (with a
     * "[]" suffix when the DTO is the item type of an array property). Surfaced as a `From:` doc
     * line so a synthesised DTO's origin is discoverable.
     */
    private function recordSynthesizedOrigin(
        string $className,
        string $ownerClassName,
        string $propertyName,
        bool $arrayItem = false,
    ): void {
        $this->relatedByClass[$className] = $ownerClassName . '.' . $propertyName . ($arrayItem ? '[]' : '');

        // A synthesised DTO belongs to exactly one operation, so it inherits its owner's endpoint
        // (the `Route:` line). The owner's entry is already set by the time its properties resolve;
        // for deeper nesting the owner is itself a synthesised class that inherited it in turn.
        if (array_key_exists($ownerClassName, $this->endpointByClass)) {
            $this->endpointByClass[$className] = $this->endpointByClass[$ownerClassName];
        }
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
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL) {
            // Laravel DTOs are flattened for the same reason Symfony ones are: the payload is
            // validated as one flat rule set and hydrated by a single factory, so a partially
            // initialised parent would have nothing to hydrate it.
            return $this->renderLaravelDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedSymfonyProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL_DATA) {
            // Flattened for the same reason, and one more: laravel-data reads the CONSTRUCTOR to learn
            // a class's properties, so an inherited property that never reaches this constructor would
            // not be hydrated at all.
            return $this->renderLaravelDataDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedSymfonyProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_YII3) {
            // Flattened for the same reason as Symfony: the hydrator populates ONE constructor, so an
            // inherited property that never reaches it would never be hydrated.
            return $this->renderYii3DtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedSymfonyProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                extends: $schemaMetadata['extends'],
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
            // Symfony DTOs are flattened: inherited properties are merged into a single standalone
            // constructor (no `extends`/parent::__construct chaining), which maps cleanly onto the
            // Symfony serializer/validator without partially-initialised parent state.
            return $this->renderSymfonyDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedSymfonyProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                extends: $schemaMetadata['extends'],
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        return $this->renderRuntimeDtoClass($namespace, $className, $schemaMetadata);
    }

    /**
     * The single-quoted PHP literal for a value, quotes included — the ONE spelling every renderer and
     * every template goes through (templates via the `php_string` Twig filter).
     *
     * It is one function because it was three, and they disagreed: the Laravel helper escaped every
     * backslash, the yii3 renderer escaped only the quote in one place and both in another, and four
     * literals escaped nothing at all. A schema property named `it's` then produced a file that did not
     * parse — in runtime and yii3 modes.
     */
    private function phpStringLiteral(string $value): string
    {
        return "'" . $this->escapeSingleQuoted($value) . "'";
    }

    /**
     * Escapes a string for a single-quoted PHP literal using the minimal form: in single quotes
     * only `\` and `'` are special, and a backslash is literal unless it precedes another
     * backslash or a quote (or terminates the string). Emitting `\\` for every backslash is valid
     * but php-cs-fixer's string_implicit_backslashes rule strips the redundant ones — producing
     * this minimal form up front keeps generated code a fixed point of that rule.
     */
    private function escapeSingleQuoted(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === "'") {
                $result .= "\\'";
            } elseif ($char === '\\') {
                $next = $i + 1 < $length ? $value[$i + 1] : '';
                $result .= ($next === '\\' || $next === "'" || $next === '') ? '\\\\' : '\\';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderPhpTemplate(string $templateName, array $context): string
    {
        $content = $this->renderTwig($templateName, $context);

        return GlobalFunctionImports::apply(rtrim($content) . "\n");
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

        // A template writes `'{{ name|php_string }}'` and gets the SAME escaping the renderers use.
        // Before this each template spelled the rule itself — `|replace({'\\':'\\\\','\'':'\\\''})` in about
        // thirty places, only the quote half in the yii3 one, and nothing at all in four literals —
        // so a property named `it's` produced a file that did not parse in runtime and yii3 modes.
        // One filter is the only way the answer cannot differ between two templates.
        $this->twig->addFilter(new TwigFilter(
            'php_string',
            fn(string $value): string => $this->escapeSingleQuoted($value),
        ));

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

        // `mixed` already includes null — `?mixed` is a fatal error
        // ("Type mixed cannot be marked as nullable since mixed already includes null").
        if ($type === 'mixed') {
            return 'mixed';
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
        // Resolve recursively: a parent may itself be an allOf composition (deep inheritance
        // chain, e.g. a discriminator variant that is also a discriminator base). A flat read
        // of `properties` would return nothing for such a parent, so the child would drop the
        // grandparent's constructor arguments and omit parent::__construct().
        return $this->getSchemaProperties($parentClassName);
    }

    /**
     * Recursively get all properties from a schema, including inherited ones.
     *
     * @param array<string, true> $visiting allOf ancestry on the current recursion path, used to
     *                                       detect (and reject) circular allOf inheritance
     * @return array<int, SchemaProperty>
     */
    private function getSchemaProperties(string $className, array $visiting = []): array
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? null;
        if ($schemaDefinition === null) {
            return [];
        }

        // If schema has allOf with inheritance, collect parent properties first
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            if (array_key_exists($className, $visiting)) {
                throw new RuntimeException(sprintf(
                    'Circular allOf inheritance detected involving %s (cycle: %s). '
                    . 'A schema cannot transitively compose itself via allOf.',
                    $className,
                    implode(' -> ', [...array_keys($visiting), $className]),
                ));
            }
            $visiting[$className] = true;

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
                    foreach ($this->getSchemaProperties($parentClass, $visiting) as $prop) {
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

            return $this->dedupeCaseInsensitivePropertyNames($allProperties);
        }

        return $this->extractProperties($schemaDefinition, $className);
    }

    /**
     * The class name for a whole schema name, safe to declare in PHP.
     *
     * `normalizeClassName()` is also used for FRAGMENTS that are concatenated into a larger name
     * (`$ownerClassName . normalizeClassName($propertyName)`, route segments, `…Item`). A fragment
     * may legitimately be a keyword — `ProbeList` is a fine class name — so the reserved-word guard
     * belongs here, where the name stands on its own, and not in the normalizer.
     *
     * Every schema-name-to-class-name conversion must go through this method: `$ref` resolution looks
     * the class up by the same derived name, so a site left on the raw normalizer would not find it.
     */
    private function schemaClassName(string $schemaName): string
    {
        return $this->avoidReservedPhpClassName($this->normalizeClassName($schemaName));
    }

    /**
     * A schema named `Parent`, `List` or `Int` produced a file PHP cannot load at all — either
     * `Cannot use "Parent" as a class name as it is reserved` or a parse error on the class keyword.
     * Such a name gets a `Schema` suffix (`ParentSchema`), which is neutral about the kind: the same
     * name may end up a DTO, an enum or a union interface.
     *
     * If the document ALSO declares a schema that normalizes to the suffixed name, `registerSchema()`
     * reports it as a name collision — loudly, and with both names in the message.
     */
    private function avoidReservedPhpClassName(string $className): string
    {
        return in_array(strtolower($className), self::PHP_RESERVED_CLASS_NAMES, true)
            ? $className . 'Schema'
            : $className;
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

    /**
     * The same rule as `disambiguateCaseInsensitiveName()`, applied to an ALREADY assembled property
     * list — an `allOf` merge builds its list from several branches, each with its own naming pass,
     * so a case-only clash between two branches (or against an inherited property) only becomes
     * visible here.
     *
     * `$reservedNames` are the PHP names a subclass cannot use because its parent already defines the
     * accessor: `Discriminator` declares `name`, so `Discriminator2`'s own `NAme` must not render as
     * `getNAme()`, which PHP would treat as an override of `getName()`.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $reservedNames
     * @return array<int, SchemaProperty>
     */
    private function dedupeCaseInsensitivePropertyNames(array $properties, array $reservedNames = []): array
    {
        /** @var array<string, string> $assigned normalized PHP name => OpenAPI name */
        $assigned = [];
        foreach ($reservedNames as $reservedName) {
            $assigned[$reservedName] = "\0reserved";
        }

        /** @var array<string, string> $namesByOpenApiName */
        $namesByOpenApiName = [];

        foreach ($properties as $index => $property) {
            $openApiName = $property['openApiName'];

            // A property repeated by two merged branches keeps one PHP name (the branches are
            // asserted compatible elsewhere), otherwise the duplicate would be renamed to `x2`.
            if (array_key_exists($openApiName, $namesByOpenApiName)) {
                $properties[$index]['name'] = $namesByOpenApiName[$openApiName];
                continue;
            }

            $name = $this->disambiguateCaseInsensitiveName($property['name'], $openApiName, $assigned);
            $assigned[$name] = $openApiName;
            $namesByOpenApiName[$openApiName] = $name;
            $properties[$index]['name'] = $name;
        }

        return $properties;
    }

    /**
     * Two OpenAPI keys that differ only in case (`name` and `NAme`) produce two DISTINCT PHP
     * properties — property names are case-sensitive — but their accessors are not: PHP method names
     * are case-insensitive, so `getName()` and `getNAme()` are the same method. In Symfony mode that
     * is a fatal "Cannot redeclare"; in runtime mode the child's `getNAme()` silently OVERRIDES the
     * parent's `getName()` (covariant return type, so it parses), and reading `name` returns the
     * value of `NAme`. Both are wrong and both are reachable from a perfectly valid document.
     *
     * The second and later spellings therefore get a numeric suffix (`$nAme2`, `getNAme2()`). Only
     * the PHP identifier changes: the wire name is carried by `#[SerializedName]` in Symfony mode
     * and by the php-to-OpenAPI name map in runtime mode, so payloads are unaffected.
     *
     * An EXACT normalized collision (`first_name` and `firstName` both become `$firstName`) still
     * throws in the caller — there the two keys are indistinguishable in PHP, and silently renaming
     * one would hide a modelling mistake.
     *
     * @param array<string, string> $normalizedToOpenApiName normalized PHP name => OpenAPI name
     */
    private function disambiguateCaseInsensitiveName(
        string $normalizedName,
        string $openApiPropertyName,
        array $normalizedToOpenApiName,
    ): string {
        $taken = [];
        $takenExactly = [];
        foreach ($normalizedToOpenApiName as $takenName => $takenOpenApiName) {
            if ($takenOpenApiName === $openApiPropertyName) {
                continue;
            }
            $taken[strtolower($takenName)] = true;
            $takenExactly[$takenName] = true;
        }

        // An identically spelled name is a property OVERRIDE — a subclass redeclaring a parent
        // property, or two allOf branches declaring the same one. That is a different question
        // (answered by the override/merge compatibility checks) and must not be renamed away.
        if (array_key_exists($normalizedName, $takenExactly)) {
            return $normalizedName;
        }

        if (!array_key_exists(strtolower($normalizedName), $taken)) {
            return $normalizedName;
        }

        $suffix = 2;
        while (array_key_exists(strtolower($normalizedName . $suffix), $taken)) {
            $suffix++;
        }

        return $normalizedName . $suffix;
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
     * Swagger 2.0 describes payloads with a different model (`definitions`, `in: body`/`formData`,
     * `collectionFormat`), none of which this generator reads — it would quietly emit nothing
     * useful. Refuse such a document instead, and flag anything that is not an OpenAPI 3.x version.
     *
     * @param array<mixed> $openApi
     */
    private function assertSupportedSpecVersion(array $openApi): void
    {
        $swagger = $openApi['swagger'] ?? null;
        if ($swagger !== null) {
            throw new RuntimeException(sprintf(
                'Swagger %s documents are not supported: this generator reads OpenAPI 3.0+ '
                . '(components.schemas, requestBody, parameter schema/content). '
                . 'Convert the document first, e.g. with swagger2openapi.',
                is_scalar($swagger) ? (string)$swagger : '2.0',
            ));
        }

        $version = $openApi['openapi'] ?? null;
        if (!is_string($version) || $version === '') {
            $this->generationWarnings[] = 'Document has no "openapi" version field; it is read as OpenAPI 3.x.';

            return;
        }

        if (!str_starts_with($version, '3.')) {
            $this->generationWarnings[] = sprintf(
                'OpenAPI version "%s" is newer than this generator knows; it is read with the 3.x rules.',
                $version,
            );
        }
    }

    /**
     * Reads the document-level OAS 3.1/3.2 fields that affect how the rest is interpreted:
     * `$self` (the document's own URI, so a `$ref` addressing it is really a local pointer) and
     * `jsonSchemaDialect` (an unfamiliar dialect means the keyword vocabulary may differ from the
     * one this generator implements, which is worth saying out loud rather than guessing).
     *
     * @param array<mixed> $openApi
     */
    private function readDocumentLevelFields(array $openApi): void
    {
        $this->assertSupportedSpecVersion($openApi);

        $self = $openApi['$self'] ?? null;
        $this->documentSelfUri = is_string($self) && $self !== '' ? rtrim($self, '#') : null;

        $dialect = $openApi['jsonSchemaDialect'] ?? null;
        if (!is_string($dialect) || $dialect === '') {
            return;
        }

        $known = [
            'https://spec.openapis.org/oas/3.1/dialect/base',
            'https://spec.openapis.org/oas/3.1/dialect/2024-11-10',
            'https://spec.openapis.org/oas/3.2/dialect/2024-11-10',
            'https://json-schema.org/draft/2020-12/schema',
        ];

        if (!in_array(rtrim($dialect, '#'), $known, true)) {
            $this->generationWarnings[] = sprintf(
                'Unknown jsonSchemaDialect "%s": schemas are interpreted with the OAS 3.1 dialect (JSON Schema 2020-12).',
                $dialect,
            );
        }
    }

    /**
     * Rewrites a `$ref` that addresses this very document by its `$self` URI into the equivalent
     * local pointer, so it resolves against the loaded document instead of being treated as an
     * unreachable external file.
     */
    private function stripDocumentSelfPrefix(string $ref): string
    {
        if ($this->documentSelfUri === null || !str_starts_with($ref, $this->documentSelfUri)) {
            return $ref;
        }

        $remainder = substr($ref, strlen($this->documentSelfUri));

        return $remainder === '' || $remainder[0] !== '#' ? $ref : $remainder;
    }

    /**
     * Whether the DOCUMENT allows null for this schema — either spelling.
     *
     * @param array<string, mixed> $schema
     */
    private function schemaAllowsNull(array $schema): bool
    {
        if (($schema['nullable'] ?? null) === true) {
            return true;
        }

        $type = $schema['type'] ?? null;

        return is_array($type) && in_array('null', $type, true);
    }

    /**
     * Whether a union's branches already have one that accepts null, so it is not added twice.
     *
     * @param array<int, array<string, mixed>> $branches
     */
    private function unionBranchesAcceptNull(array $branches): bool
    {
        foreach ($branches as $branch) {
            $type = $branch['type'] ?? null;
            if ($type === 'null' || (is_array($type) && in_array('null', $type, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports a schema whose CLASS NAME is one the emitted code already uses for a library class.
     *
     * The generator carries a PHP type as a short name — `UploadedFile` is what `format: binary`
     * resolves to, `DateTimeImmutable` what `format: date-time` does — so a component schema of that
     * name is, from there on, indistinguishable from the library class. What the emitted file then does
     * depends on where the name lands, and none of it is what the document asked for:
     *
     *     the file DECLARING it     `use X;` beside `class X` is a fatal — the file never loads
     *     any SIBLING file          the import wins over the same-namespace class, so the property is
     *                               typed with the library's class and its payload is a TypeError
     *     a resolved TYPE           `format: binary` and a `$ref` to a schema named UploadedFile
     *                               produce the same string, so the property is treated as an upload
     *
     * Only the first two are fixable from the import list, and laravel-data mode does exactly that
     * (`libraryClassRef()`). The third is not: it would take a type representation that keeps a
     * class's namespace all the way through, which this generator does not have. So the collision is
     * named at BUILD time instead of surfacing as a fatal or a wrong type at request time — the same
     * bargain as `warnAboutUnhydratableUnionProperties()`.
     */
    private function warnAboutClassNamesTheEmittedCodeAlsoUses(): void
    {
        // Keyed by the short name a document must not take, valued by what the emitted code uses it for.
        // Two are shared by every mode because they are what a schema KEYWORD resolves to; the rest are
        // the classes a mode's own emitted file imports.
        $reserved = [
            'DateTimeImmutable' => 'the type `format: date-time` and `format: date` resolve to',
            'UploadedFile' => 'the type `format: binary` resolves to',
        ];

        $byMode = [
            // runtime resolves its own imports against the document (`libraryClassRef()`), so a
            // schema named `GeneratedDtoInterface`, `JsonException`, `Stringable`, `Closure` or
            // `RuntimeException` is spelled `\Foo` in the emitted file and needs no warning.
            //
            // `UnsetValue` is the exception, and not because of the emitter: DtoDeserializer
            // recognises the sentinel by SHORT name (`str_ends_with($typeName, '\UnsetValue')`),
            // deliberately, so that a sentinel copied into the user's own namespace by
            // `--dto-generator-namespace` is still recognised. A document schema of that name falls
            // under the same test and is skipped instead of hydrated, whatever the emitted file says.
            self::ATTRIBUTE_MODE_RUNTIME => [
                'UnsetValue' => 'the sentinel every optional property defaults to, which the '
                    . 'deserializer recognises by short name',
            ],
            self::ATTRIBUTE_MODE_SYMFONY => [
                'Assert' => 'the alias of Symfony\Component\Validator\Constraints in every emitted attribute',
                'Ignore' => 'the serializer attribute a writeOnly property carries',
                'SerializedName' => 'the serializer attribute a renamed property carries',
                'ExecutionContextInterface' => 'the parameter type of the emitted #[Assert\Callback]',
            ],
            self::ATTRIBUTE_MODE_LARAVEL => [
                'Validator' => 'the parameter type of the emitted withValidator()',
                'Rule' => 'the facade an enum or a discriminator rule is built with',
                'FormRequest' => 'the base class of the emitted FormRequest',
                'stdClass' => 'the type the emitted code casts a JSON map to',
            ],
            // laravel-data resolves its own imports against the document (`libraryClassRef()`),
            // so only the two shared type names are left to warn about there.
            self::ATTRIBUTE_MODE_LARAVEL_DATA => [],
            // yii3 resolves its own imports against the document too, since 2.15.2: every framework
            // short name its renderer writes goes through `yii3Lib()`, and `yii3SortedImports()` drops
            // the import the document's class would collide with. Both ask `namespaceDeclaresClass()`,
            // so the body and the import list cannot disagree.
            //
            // `DateTimeImmutable` is not in reach of that and stays in the shared list above: the type
            // a `format: date-time` resolves to is produced by the mode-neutral type mapper, which does
            // not know the namespace, so a schema of that name silently TYPES the property as its own
            // class. Runtime mode has the same gap for the same reason.
            self::ATTRIBUTE_MODE_YII3 => [],
        ];

        $reserved = [...$reserved, ...$byMode[$this->attributeMode] ?? []];

        foreach ([...array_keys($this->dtoSchemas), ...array_keys($this->enumSchemas)] as $generatedClass) {
            $usedFor = $reserved[$generatedClass] ?? null;
            if ($usedFor === null) {
                continue;
            }

            $warning = sprintf(
                'Schema "%s" is generated as class %s, which is also %s in %s mode. The emitted code '
                    . 'cannot tell the two apart — rename the schema, or move it to a namespace of its '
                    . 'own with --ref-namespace.',
                $generatedClass,
                $generatedClass,
                $usedFor,
                $this->attributeMode,
            );

            if (!in_array($warning, $this->generationWarnings, true)) {
                $this->generationWarnings[] = $warning;
            }
        }
    }

    /**
     * Reports a property whose schema is a union of OBJECTS with no `discriminator`.
     *
     * Such a union is emitted as an interface its members implement, and NOTHING can turn a payload back
     * into one of them: the document does not say which member a given object is, and picking by structure
     * would be a guess two overlapping branches could not settle. Every mode therefore fails on a payload
     * the document allows, and each fails differently and late — measured:
     *
     *     runtime       RuntimeException: Unsupported type: Shape
     *     symfony       NotNormalizableValueException: … must be one of "Shape" ("array" given)
     *     laravel       Error: Call to undefined method Shape::fromValidated()
     *     laravel-data  TypeError: Argument #1 ($shape) must be of type Shape, array given
     *
     * A 500 at request time for a shape the generator could see at build time is the actual defect, so it
     * is named here instead. Generation still succeeds: the interface and its members are useful as types,
     * and a document may reference the union only in a response, which never gets hydrated.
     *
     * The remedy is in the document — add a `discriminator` — and then every mode resolves it: runtime and
     * laravel switch on the mapping in generated hydration, Symfony uses the serializer's discriminator
     * map, and laravel-data gets an abstract `Data` base with `morph()`.
     *
     * @param array<int, SchemaProperty> $properties
     */
    private function warnAboutUnhydratableUnionProperties(string $className, array $properties): void
    {
        foreach ($properties as $property) {
            $target = $this->undiscriminatedUnionTypeOf($property);
            if ($target === null) {
                continue;
            }

            $warning = sprintf(
                'Property "%s" of %s refers to %s, an undiscriminated %s union: no mode can hydrate a '
                    . 'payload into it. Add a discriminator to %s, or type the property as one member.',
                $property['openApiName'],
                $className,
                $target['class'],
                $target['keyword'],
                $target['class'],
            );

            if (!in_array($warning, $this->generationWarnings, true)) {
                $this->generationWarnings[] = $warning;
            }
        }
    }

    /**
     * The union base a property (or its array items) resolves to, when that base has no discriminator.
     *
     * @param SchemaProperty $property
     * @return array{class: string, keyword: string}|null
     */
    private function undiscriminatedUnionTypeOf(array $property): ?array
    {
        foreach ([$this->laravelDtoClass($property), $this->laravelDtoItemClass($property)] as $candidate) {
            if ($candidate === null || array_key_exists($candidate, $this->discriminatorSchemas)) {
                continue;
            }

            $schema = $this->dtoSchemas[$candidate] ?? null;
            if (!is_array($schema)) {
                continue;
            }

            foreach (['oneOf', 'anyOf'] as $keyword) {
                if (array_key_exists($keyword, $schema) && $this->collectsObjectUnionMembers($schema[$keyword])) {
                    return ['class' => $candidate, 'keyword' => $keyword];
                }
            }
        }

        return null;
    }

    /**
     * Whether a generated class is a union interface NOTHING can hydrate a payload into: a `oneOf`/`anyOf`
     * over object members with no `discriminator` to choose between them.
     *
     * The same condition {@see warnAboutUnhydratableUnionProperties()} reports at generation time, asked
     * about the class rather than about a property, so an emitter can decline to write a call that cannot
     * work. {@see RendersLaravelDto::laravelNestedDtoExpression()} is the one that used to write it.
     */
    private function isUnhydratableUnionClass(string $class): bool
    {
        if (array_key_exists($class, $this->discriminatorSchemas)) {
            return false;
        }

        $schema = $this->dtoSchemas[$class] ?? null;
        if (!is_array($schema)) {
            return false;
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (array_key_exists($keyword, $schema) && $this->collectsObjectUnionMembers($schema[$keyword])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a union's variants are `$ref`s to schemas of their own — the case that becomes an interface.
     * A union of SCALARS becomes a PHP union type (`int|string`) instead, which hydrates fine.
     */
    private function collectsObjectUnionMembers(mixed $variants): bool
    {
        if (!is_array($variants)) {
            return false;
        }

        foreach ($variants as $variant) {
            if (!is_array($variant) || !is_string($variant['$ref'] ?? null)) {
                continue;
            }

            $memberClass = $this->schemaRefToClassName($variant['$ref']);
            if (array_key_exists($memberClass, $this->dtoSchemas)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getGenerationWarnings(): array
    {
        return $this->generationWarnings;
    }

    /**
     * Path Item Objects to walk: `paths` plus, since OAS 3.1, `webhooks`. A webhook entry is an
     * incoming request our own endpoint receives, so its body/parameters deserve DTOs exactly like
     * a path operation's. The key is a name, not a URL — it is used verbatim for class naming.
     *
     * @param array<mixed> $openApi
     * @return array<string, array<mixed>>
     */
    private function collectPathItems(array $openApi): array
    {
        $items = [];

        foreach (['paths', 'webhooks'] as $section) {
            $group = $openApi[$section] ?? [];
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $key => $pathItem) {
                if (!is_string($key) || !is_array($pathItem)) {
                    continue;
                }
                // A webhook named like an existing path would otherwise overwrite it.
                $items[$section === 'webhooks' ? 'webhook:' . $key : $key] = $pathItem;
            }
        }

        foreach ($items as $key => $pathItem) {
            $items[$key] = $this->foldAdditionalOperations($pathItem);
        }

        foreach ($items as $pathItem) {
            foreach ($this->collectCallbackPathItems($pathItem) as $callbackKey => $callbackPathItem) {
                // Two operations may declare the same callback name with different payloads.
                $uniqueKey = $callbackKey;
                $counter = 2;
                while (array_key_exists($uniqueKey, $items)) {
                    $uniqueKey = $callbackKey . $counter;
                    $counter++;
                }
                $items[$uniqueKey] = $callbackPathItem;
            }
        }

        return $items;
    }

    /**
     * OAS 3.2 `additionalOperations` holds operations for methods that have no fixed field (QUERY
     * and friends). They are operations like any other, so they are folded into the Path Item under
     * their method name — lowercased, since every walker matches methods case-insensitively.
     *
     * @param array<mixed> $pathItem
     * @return array<mixed>
     */
    private function foldAdditionalOperations(array $pathItem): array
    {
        $additional = $pathItem['additionalOperations'] ?? null;
        unset($pathItem['additionalOperations']);

        if (!is_array($additional)) {
            return $pathItem;
        }

        foreach ($additional as $method => $operation) {
            if (!is_string($method) || $method === '' || !is_array($operation)) {
                continue;
            }
            // The spec forbids re-declaring a fixed-field method here; if one shows up anyway the
            // fixed field wins rather than being silently overwritten.
            $normalizedMethod = strtolower($method);
            if (!array_key_exists($normalizedMethod, $pathItem)) {
                $pathItem[$normalizedMethod] = $operation;
            }
        }

        return $pathItem;
    }

    /**
     * Path Item Objects nested under an operation's `callbacks`. The map key there is a runtime
     * expression (`{$request.body#/callbackUrl}`), useless for naming, so the callback name is used
     * instead — the payload is what matters here, not the URL it will be delivered to.
     *
     * @param array<mixed> $pathItem
     * @return array<string, array<mixed>>
     */
    private function collectCallbackPathItems(array $pathItem): array
    {
        $collected = [];

        foreach ($pathItem as $method => $operation) {
            if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                continue;
            }

            $callbacks = $operation['callbacks'] ?? null;
            if (!is_array($callbacks)) {
                continue;
            }

            foreach ($callbacks as $callbackName => $callback) {
                if (!is_string($callbackName) || !is_array($callback)) {
                    continue;
                }

                foreach ($callback as $expression => $callbackPathItem) {
                    if (!is_string($expression) || !is_array($callbackPathItem)) {
                        continue;
                    }
                    $collected['callback:' . $callbackName] = $callbackPathItem;
                }
            }
        }

        return $collected;
    }

    /**
     * Naming input for a Path Item key: a `webhook:`/`callback:` marker becomes a path segment so
     * the class name reads `WebhookNewPetPostRequest` / `CallbackOnDataPostRequest` instead of
     * colliding with a same-named path.
     */
    private function pathItemNamingKey(string $key): string
    {
        foreach (['webhook:', 'callback:'] as $marker) {
            if (str_starts_with($key, $marker)) {
                return rtrim($marker, ':') . '/' . substr($key, strlen($marker));
            }
        }

        return $key;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractInlineResponseSchemas(array $openApi): array
    {
        $paths = $this->collectPathItems($openApi);

        $inlineSchemas = [];
        $inlineOwners = [];

        foreach ($paths as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $responses = $operation['responses'] ?? [];
                if (!is_array($responses)) {
                    continue;
                }

                foreach ($responses as $statusCode => $response) {
                    $response = $this->resolveComponentRef($response, 'responses', $openApi);
                    if (!is_array($response)) {
                        continue;
                    }

                    $content = $response['content'] ?? [];
                    if (!is_array($content)) {
                        continue;
                    }

                    foreach ($content as $mediaTypeObject) {
                        $mediaTypeObject = $this->resolveComponentRef($mediaTypeObject, 'mediaTypes', $openApi);
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

                        $ownerKey = strtoupper($method) . ' ' . $path;
                        $schemaName = $this->uniqueEndpointSchemaName(
                            path: $this->pathItemNamingKey($path),
                            tail: (string)$statusCode,
                            ownerKey: $ownerKey,
                            owners: $inlineOwners,
                        );
                        $inlineSchemas[$schemaName] = $schema;
                        $inlineOwners[$schemaName] = $ownerKey;
                        $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
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
        $paths = $this->collectPathItems($openApi);

        $inlineSchemas = [];
        $inlineOwners = [];

        foreach ($paths as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $requestBody = $this->resolveComponentRef($operation['requestBody'] ?? null, 'requestBodies', $openApi);
                if (!is_array($requestBody)) {
                    continue;
                }

                $content = $requestBody['content'] ?? [];
                if (!is_array($content)) {
                    continue;
                }

                foreach ($content as $mediaTypeObject) {
                    $mediaTypeObject = $this->resolveComponentRef($mediaTypeObject, 'mediaTypes', $openApi);
                    if (!is_array($mediaTypeObject)) {
                        continue;
                    }

                    $schema = $mediaTypeObject['schema'] ?? null;
                    if (!is_array($schema)) {
                        continue;
                    }

                    // A body that points at a named component declares no NEW class — the component
                    // already has one — but it is still a request payload, and that is what decides
                    // whether the class gets a FormRequest. Only a LOCAL pointer: a component in an
                    // external file belongs to whichever run generates that file.
                    $bodyRef = $schema['$ref'] ?? null;
                    if (is_string($bodyRef)) {
                        $referencedSchema = $this->externalPointerSchemaName($bodyRef);
                        if ($referencedSchema !== null) {
                            $this->requestPayloadClasses[$this->schemaClassName($referencedSchema)] = true;
                        }
                        continue;
                    }

                    if (($schema['type'] ?? null) !== 'object') {
                        continue;
                    }

                    $schema = $this->applyEncodingToBodySchema($schema, $mediaTypeObject['encoding'] ?? null);

                    $ownerKey = strtoupper($method) . ' ' . $path;
                    $schemaName = $this->uniqueEndpointSchemaName(
                        path: $this->pathItemNamingKey($path),
                        tail: ucfirst(strtolower($method)) . 'Request',
                        ownerKey: $ownerKey,
                        owners: $inlineOwners,
                    );
                    $inlineSchemas[$schemaName] = $schema;
                    $inlineOwners[$schemaName] = $ownerKey;
                    $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
                    $this->requestPayloadClasses[$this->schemaClassName($schemaName)] = true;
                }
            }
        }

        return $inlineSchemas;
    }

    private function isHttpMethod(string $method): bool
    {
        // QUERY (and other custom methods) reach a Path Item through OAS 3.2 additionalOperations,
        // which is folded in before the walkers run — so accept any method token, not just the
        // eight with a fixed field. Non-method keys of a Path Item are excluded explicitly.
        if (in_array($method, ['parameters', 'servers', 'summary', 'description', '$ref', 'additionalOperations'], true)) {
            return false;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $method) === 1;
    }

    /**
     * Builds a collision-free schema name for an endpoint-derived DTO
     * (inline request/response bodies and path/query parameter DTOs).
     *
     * The base name strips path parameters, so distinct endpoints differing only
     * by a path parameter (e.g. `/x/{a}` and `/x`) would otherwise collapse to the
     * same name and silently overwrite each other. When a different endpoint wants
     * an already-owned name, the path-parameter names are injected as a
     * `By<Param...>` segment; a numeric suffix is appended only as a last resort.
     *
     * The same endpoint reclaiming its own name (e.g. several media types under one
     * response) keeps the plain name — that is an intentional single DTO, not a
     * collision.
     *
     * @param array<string, string> $owners map of already-assigned name => owner key
     */
    private function uniqueEndpointSchemaName(string $path, string $tail, string $ownerKey, array $owners): string
    {
        $base = $this->normalizePathForSchemaName($path);
        $name = $base . $tail;

        if (($owners[$name] ?? $ownerKey) === $ownerKey) {
            return $name;
        }

        $paramSuffix = '';
        foreach ($this->pathParameterNames($path) as $parameterName) {
            $paramSuffix .= $this->pascalizeSegment($parameterName);
        }

        $prefix = $base . ($paramSuffix !== '' ? 'By' . $paramSuffix : '');
        $candidate = $prefix . $tail;
        $counter = 2;
        while (array_key_exists($candidate, $owners) && $owners[$candidate] !== $ownerKey) {
            $candidate = $prefix . $tail . $counter;
            $counter++;
        }

        return $candidate;
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

    /**
     * @param array<string, mixed> $schema
     */
    private function isEnumSchema(array $schema): bool
    {
        return array_key_exists('enum', $schema)
            && is_array($schema['enum'])
            && $schema['enum'] !== []
            && array_key_exists('type', $schema)
            && in_array($schema['type'], ['string', 'integer'], true)
            && $this->canGenerateBackedEnumFromSchema($schema, true);
    }

    /**
     * Backed enums can represent only string/int values. Enums containing bool/null/objects
     * stay inline on the property and are validated via constraints instead of enum synthesis.
     *
     * @param array<string, mixed> $schema
     */
    private function canGenerateBackedEnumFromSchema(array $schema, bool $requireExplicitType = false): bool
    {
        $enum = $schema['enum'] ?? null;
        if (!is_array($enum) || $enum === []) {
            return false;
        }

        foreach ($enum as $value) {
            if (!is_string($value) && !is_int($value)) {
                return false;
            }
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            return false;
        }
        if ($type !== null && !is_string($type)) {
            return false;
        }

        if ($requireExplicitType && !in_array($type, ['string', 'integer'], true)) {
            return false;
        }

        if ($type === 'integer') {
            foreach ($enum as $value) {
                if (!is_int($value)) {
                    return false;
                }
            }
        }

        if ($type !== null && !in_array($type, ['string', 'integer'], true)) {
            return false;
        }

        return true;
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

        // There is ONE axis here, and it is not the framework: does the enum carry this package's
        // runtime interface and its methods, or is it a plain backed enum?
        //
        // Runtime mode needs the interface — `DtoNormalizer` reads it. The other four do not: the
        // Symfony serializer handles a backed enum natively through `BackedEnumNormalizer`, Laravel's
        // and laravel-data's generated hydration map the value themselves, and Yii3's `ObjectParser`
        // does too. So all four emit the same enum, byte for byte — which is why there are two
        // templates and not five.
        //
        // The old spelling of this was `$isSymfony` plus `enum.symfony.php.twig`, and both names were
        // false four times out of five: nothing about either is Symfony's.
        $rendersStandaloneEnum = $this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME;

        $imports = [];
        $generatedDtoInterfaceRef = 'GeneratedDtoInterface';
        $jsonExceptionRef = 'JsonException';
        if (!$rendersStandaloneEnum) {
            // Import the runtime interface only when it lives in another namespace (avoid a
            // self-import), and JsonException for the enum's toJson() (@throws + json_encode with
            // JSON_THROW_ON_ERROR). Sort so the output matches php-cs-fixer's ordered_imports.
            $fqcnNamespace = implode('\\', array_slice(explode('\\', $this->generatedDtoInterfaceImportFqcn), 0, -1));
            $generatedDtoInterfaceRef = $fqcnNamespace === $namespace
                ? 'GeneratedDtoInterface'
                : $this->libraryClassRef($this->generatedDtoInterfaceImportFqcn, $namespace, $imports);
            $jsonExceptionRef = $this->libraryClassRef('JsonException', $namespace, $imports);
            sort($imports);
        }

        return $this->renderPhpTemplate(
            templateName: $rendersStandaloneEnum ? 'enum.standalone.php.twig' : 'enum.php.twig',
            context: [
                'namespace' => $namespace,
                'imports' => $imports,
                'enumName' => $enumName,
                'backingType' => $backingType,
                'cases' => $cases,
                'sourceEndpoint' => $this->endpointByClass[$enumName] ?? null,
                'sourceSpecLink' => $this->resolveSpecLink($enumName),
                'sourceRelated' => $this->relatedByClass[$enumName] ?? null,
                'generatedDtoInterfaceRef' => $generatedDtoInterfaceRef,
                'jsonExceptionRef' => $jsonExceptionRef,
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

        $escaped = $this->escapeSingleQuoted((string)$value);
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
     * Recovers the `default` declared on a referenced schema when the property itself only carries
     * a `$ref` (optionally wrapped in a single-ref allOf). Returns null when the property is not a
     * pure reference or the target declares no default.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveReferencedDefault(array $propertySchema, string $ownerClassName): mixed
    {
        $ref = $this->singleRefOf($propertySchema);
        if ($ref === null) {
            return null;
        }

        return $this->defaultFromRef(
            ref: $ref,
            currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
            depth: 0,
        );
    }

    /**
     * Returns the `$ref` string when the schema is a pure reference — either a direct `$ref` or an
     * allOf with exactly one ref-only branch — otherwise null.
     *
     * @param array<string, mixed> $schema
     */
    private function singleRefOf(array $schema): ?string
    {
        if (array_key_exists('$ref', $schema) && is_string($schema['$ref'])) {
            return $schema['$ref'];
        }

        if (array_key_exists('allOf', $schema) && is_array($schema['allOf']) && count($schema['allOf']) === 1) {
            $first = $schema['allOf'][0];
            if (is_array($first) && array_key_exists('$ref', $first) && is_string($first['$ref'])) {
                return $first['$ref'];
            }
        }

        return null;
    }

    /**
     * Walks a $ref (internal or external) to the target schema node and returns its `default`,
     * following one further level of pure-reference indirection. Bounded by $depth to guard
     * against reference cycles.
     */
    private function defaultFromRef(string $ref, ?string $currentSourceFile, int $depth): mixed
    {
        if ($depth > 10) {
            return null;
        }

        $resolved = $this->resolveSchemaNodeByRef($ref, $currentSourceFile);
        if ($resolved === null) {
            return null;
        }

        [$node, $nodeSourceFile] = $resolved;

        if (array_key_exists('default', $node)) {
            return $node['default'];
        }

        $nestedRef = $this->singleRefOf($node);
        if ($nestedRef !== null) {
            return $this->defaultFromRef(ref: $nestedRef, currentSourceFile: $nodeSourceFile, depth: $depth + 1);
        }

        return null;
    }

    /**
     * Resolves a $ref (internal `#/components/schemas/Name` or external `file#/...`) to its raw
     * schema node and the source file that node belongs to (for chained ref resolution). Returns
     * null when the target cannot be located.
     *
     * @return array{0: array<string, mixed>, 1: ?string}|null
     */
    private function resolveSchemaNodeByRef(string $ref, ?string $currentSourceFile): ?array
    {
        $prefix = '#/components/schemas/';

        if (str_starts_with($ref, $prefix)) {
            $schemaName = substr($ref, strlen($prefix));
            if ($schemaName === '' || str_contains($schemaName, '/')) {
                return null;
            }

            $className = $this->schemaClassName($schemaName);
            $node = $this->rawSchemasByClass[$className] ?? null;

            return is_array($node) ? [$node, $this->getSchemaSourceFile($className)] : null;
        }

        $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolvedExternal === null) {
            return null;
        }

        [$externalFile, $pointer] = $resolvedExternal;
        $node = $this->resolveExternalPointerNode($externalFile, $pointer);

        return is_array($node) ? [$node, $externalFile] : null;
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
        return preg_replace('/\s+/', ' ', $description);
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractExample(array $propertySchema): ?string
    {
        // OAS 3.2 adds `serializedExample` (the encoded form); use it when no plain example exists.
        $exampleKey = match (true) {
            array_key_exists('example', $propertySchema) => 'example',
            array_key_exists('serializedExample', $propertySchema) => 'serializedExample',
            default => null,
        };
        if ($exampleKey === null) {
            return null;
        }

        $example = $propertySchema[$exampleKey];

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

    /**
     * A `format: date` / `date-time` default as a constructor-parameter default expression.
     *
     * Parsed here rather than trusted: a document may carry anything under `default`, and a value
     * `DateTimeImmutable` cannot read would move the failure from generation time (where the spec
     * author can see it) to every request. An unusable default is simply not emitted.
     */
    private function renderTemporalDefaultValue(mixed $defaultValue): string
    {
        if (!is_string($defaultValue) || $defaultValue === '') {
            return '';
        }

        try {
            new DateTimeImmutable($defaultValue);
        } catch (Exception) {
            return '';
        }

        return " = new DateTimeImmutable('" . $this->escapeSingleQuoted($defaultValue) . "')";
    }

    private function renderDefaultValue(mixed $defaultValue, string $phpType, string $fullType): string
    {
        if ($defaultValue === null) {
            return '';
        }

        // A temporal default is NOT an enum case, and the branch below would name one that does not
        // exist: `format: date, default: "2020-01-01"` produced
        // `DateTimeImmutable::VALUE_2020_01_01` — a fatal on class load in symfony mode and on the
        // first defaulted construction in runtime and laravel. Measured in all three. `new` is legal
        // in a PARAMETER default (PHP 8.1), which is where those two put it.
        if ($phpType === 'DateTimeImmutable') {
            return $this->renderTemporalDefaultValue($defaultValue);
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
            $escaped = $this->escapeSingleQuoted((string)$defaultValue);
            return " = '" . $escaped . "'";
        }

        if ($phpType === 'array') {
            return ' = []';
        }

        return '';
    }

    /**
     * Folds an Encoding Object into the body schema's properties.
     *
     * A part declared `contentType: application/json` arrives as a JSON string, so it is marked
     * with the same `json` serialization sentinel a `content:` parameter uses — the deserializer
     * decodes it before casting. `style`/`explode` on a form-urlencoded part describe how an array
     * or object part is flattened into the field, i.e. exactly what the parameter style machinery
     * already resolves. Per-part `headers` are transport metadata and carry no payload shape.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyEncodingToBodySchema(array $schema, mixed $encoding): array
    {
        if (!is_array($encoding) || $encoding === [] || !is_array($schema['properties'] ?? null)) {
            return $schema;
        }

        foreach ($encoding as $propertyName => $partEncoding) {
            if (!is_string($propertyName) || !is_array($partEncoding)) {
                continue;
            }
            if (!array_key_exists($propertyName, $schema['properties']) || !is_array($schema['properties'][$propertyName])) {
                continue;
            }

            $contentType = $partEncoding['contentType'] ?? null;
            if (is_string($contentType) && $this->isJsonMediaTypeName($contentType)) {
                $schema['properties'][$propertyName]['x-parameter-style'] = 'json';
                $schema['properties'][$propertyName]['x-parameter-explode'] = false;
                continue;
            }

            $style = $partEncoding['style'] ?? null;
            if (!is_string($style) || $style === '') {
                continue;
            }

            $schema['properties'][$propertyName]['x-parameter-style'] = $style;
            $schema['properties'][$propertyName]['x-parameter-explode'] = is_bool($partEncoding['explode'] ?? null)
                ? $partEncoding['explode']
                : $style === 'form';
        }

        return $schema;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractParameterSchemas(array $openApi): array
    {
        $paths = $this->collectPathItems($openApi);

        $parameterSchemas = [];
        $parameterOwners = [];

        foreach ($paths as $path => $pathItem) {
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

                $ownerKey = strtoupper($method) . ' ' . $path;
                $schemaName = $this->uniqueEndpointSchemaName(
                    path: $this->pathItemNamingKey($path),
                    tail: ucfirst(strtolower($method)) . 'QueryParams',
                    ownerKey: $ownerKey,
                    owners: $parameterOwners,
                );
                $parameterSchemas[$schemaName] = $this->buildParameterSchema($pathAndQueryParameters);
                $parameterOwners[$schemaName] = $ownerKey;
                $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
                $this->requestPayloadClasses[$this->schemaClassName($schemaName)] = true;
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
     * Resolves a `$ref` to a reusable component of the given section (`requestBodies`,
     * `responses`, …) so a referenced body/response contributes its schema like an inline one.
     * Non-references pass through; unresolvable references yield null and are skipped by callers.
     *
     * @param array<mixed> $openApi
     * @return array<mixed>|null
     */
    private function resolveComponentRef(mixed $node, string $section, array $openApi): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        $ref = $node['$ref'] ?? null;
        if (!is_string($ref)) {
            return $node;
        }

        $prefix = '#/components/' . $section . '/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $component = $openApi['components'][$section][substr($ref, strlen($prefix))] ?? null;

        // A component may itself be a reference; follow a short chain, guarding against cycles.
        $guard = 0;
        while (is_array($component) && is_string($component['$ref'] ?? null) && $guard++ < 10) {
            $nestedRef = $component['$ref'];
            if (!str_starts_with($nestedRef, $prefix)) {
                return null;
            }
            $component = $openApi['components'][$section][substr($nestedRef, strlen($prefix))] ?? null;
        }

        return is_array($component) ? $component : null;
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
            if (in_array($paramIn, ['path', 'query', 'header', 'cookie', 'querystring'], true)) {
                $filtered[] = $parameter;
            }
        }

        return $filtered;
    }

    /**
     * Extracts the `{placeholder}` parameter names from a path, in order.
     *
     * @return array<int, string>
     */
    private function pathParameterNames(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return $matches[1];
    }

    private function pascalizeSegment(string $segment): string
    {
        $splitResult = preg_split('/[\/\-_]+/', $segment);
        $parts = $splitResult !== false ? $splitResult : [];

        $pascalized = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $pascalized .= ucfirst($part);
        }

        return $pascalized;
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

            // A parameter may serialize a complex value via `content` (e.g.
            // content: {application/json: {schema}}) instead of a plain `schema`. Extract the
            // (single) media type's schema; JSON media types are decoded at deserialization.
            $contentJson = false;
            if (!is_array($schema) && is_array($parameter['content'] ?? null)) {
                foreach ($parameter['content'] as $mediaType => $mediaTypeObject) {
                    if (is_array($mediaTypeObject) && is_array($mediaTypeObject['schema'] ?? null)) {
                        $schema = $mediaTypeObject['schema'];
                        $contentJson = is_string($mediaType) && $this->isJsonMediaTypeName($mediaType);
                        break;
                    }
                }
            }

            if (!is_string($name) || !is_array($schema)) {
                continue;
            }

            // `deprecated`, `description` and `example` live on the Parameter Object, not on its
            // schema; carry them over (without overwriting the schema's own) so the generated DTO
            // documents a parameter the same way it documents a body property.
            foreach (['deprecated', 'description', 'example'] as $annotation) {
                if (!array_key_exists($annotation, $schema) && array_key_exists($annotation, $parameter)) {
                    $schema[$annotation] = $parameter[$annotation];
                }
            }

            $paramIn = $parameter['in'] ?? null;
            if ($paramIn === 'querystring') {
                // OAS 3.2: the value is the entire query string, always described through
                // `content`. The deserializer reads the raw string and decodes it per media type.
                $schema['x-parameter-in'] = 'querystring';
                $schema['x-parameter-style'] = $contentJson ? 'json' : 'querystring';
                $schema['x-parameter-explode'] = false;
            } elseif (in_array($paramIn, ['path', 'query', 'header', 'cookie'], true)) {
                $schema['x-parameter-in'] = $paramIn;
                if ($contentJson) {
                    // A content:application/json parameter arrives as a JSON string; the
                    // deserializer json_decodes it before casting. Reuse the style channel with
                    // a 'json' sentinel (not a real OpenAPI style) to carry that signal.
                    $schema['x-parameter-style'] = 'json';
                    $schema['x-parameter-explode'] = false;
                } else {
                    $schema['x-parameter-style'] = $this->resolveParameterStyle($parameter, $paramIn);
                    $schema['x-parameter-explode'] = $this->resolveParameterExplode(
                        parameter: $parameter,
                        style: $schema['x-parameter-style'],
                    );
                }
                $schema['x-parameter-allow-reserved'] = $this->toBoolean($parameter['allowReserved'] ?? false);
                // Tri-state on purpose: only an explicit `allowEmptyValue` reaches the DTO, so the
                // deserializer can tell "spec forbids an empty value" from "spec says nothing".
                if (array_key_exists('allowEmptyValue', $parameter)) {
                    $schema['x-parameter-allow-empty-value'] = $this->toBoolean($parameter['allowEmptyValue']);
                }
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
     * Whether a media type carries JSON (application/json or any structured +json suffix),
     * so a content-typed parameter's string value should be JSON-decoded before validation.
     */
    private function isJsonMediaTypeName(string $mediaType): bool
    {
        $normalized = strtolower(trim(explode(';', $mediaType)[0]));

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }

    /**
     * Resolves the OpenAPI `explode` flag. The spec default is `true` only when the
     * style is `form`, and `false` for every other style.
     *
     * @param array<string, mixed> $parameter
     */
    private function resolveParameterExplode(array $parameter, string $style): bool
    {
        if ($style === 'deepObject') {
            // Per RFC6570, deepObject is always exploded (nested brackets like filter[name]=value).
            // OpenAPI spec allows explicit explode: false for deepObject, but it's ignored in practice.
            // deepObject cannot be non-exploded per the spec.
            return true;
        }

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
