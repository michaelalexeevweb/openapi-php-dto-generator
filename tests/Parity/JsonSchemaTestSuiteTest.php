<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use ErrorException;
use Exception;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

/**
 * The official JSON-Schema-Test-Suite (draft 2020-12, required tests) through every generation mode.
 *
 * Each group's `schema` becomes the schema of the required property `f` of a component `Probe` in an
 * OpenAPI 3.1.0 document, each test's `data` the body `{"f": data}`, and the test's `valid` the
 * expected verdict. Every mode is measured through its real framework, exactly as
 * `ValidationParityTest` does, with one addition: an `Error` (TypeError, …) or a PHP warning is a
 * `crashes` — a 500 — and is not confused with a refusal.
 *
 * The suite is the reference; what does not agree with it YET is listed, per file, group, test and
 * mode, in `tests/fixtures/json-schema-test-suite/expectations.php`, with a status and a reason. That
 * file is the to-do list: an entry that starts to agree fails this test as loudly as a regression,
 * so the list only shrinks on purpose. Re-record it with `JSTS_RECORD=1` (reasons of entries whose
 * status did not change are kept).
 *
 * Each group is generated once per mode and reused for all its tests.
 */
final class JsonSchemaTestSuiteTest extends TestCase
{
    private const string FIXTURE_DIRECTORY = __DIR__ . '/../fixtures/json-schema-test-suite';
    private const string EXPECTATIONS_FILE = self::FIXTURE_DIRECTORY . '/expectations.php';
    private const string WHOLE_GROUP = '*';
    private const string COMPONENT = 'ProbeF';
    private const string COMPONENT_POINTER = '#/components/schemas/' . self::COMPONENT;
    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Keywords whose value is DATA, not a schema: a `{"$ref": …}` inside an `enum` is a value to
     * compare against, and rewriting or resolving it would change the test.
     */
    private const array DATA_KEYWORDS = ['const', 'enum', 'default', 'examples'];

    private const string STATUS_DIVERGES = 'diverges';
    private const string STATUS_CRASHES = 'crashes';
    private const string STATUS_GENERATION_FAILS = 'generation-fails';
    private const string STATUS_SKIPPED = 'skipped';
    private const string STATUS_NOT_REPRESENTABLE = 'not-representable';

    /**
     * What a record run observed, per file — written out once every file has been measured.
     *
     * @var array<string, array<string, array<string, array<string, array{0: string, 1: string}>>>>
     */
    private static array $recorded = [];

    /**
     * @var array{0: Serializer, 1: ValidatorInterface}|null
     */
    private static ?array $symfony = null;

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-jsts';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$recorded === []) {
            return;
        }

        $previous = self::expectations();
        $merged = [];
        foreach (self::suiteFileNames() as $file) {
            $entries = self::$recorded[$file] ?? $previous[$file] ?? [];
            if (array_key_exists($file, self::$recorded)) {
                $entries = self::keepReasons(recorded: $entries, previous: $previous[$file] ?? []);
            }
            if ($entries !== []) {
                $merged[$file] = $entries;
            }
        }
        file_put_contents(self::EXPECTATIONS_FILE, self::exportExpectations($merged));
        self::$recorded = [];
    }

    #[DataProvider('suiteFiles')]
    public function testFileAgreesWithTheSuite(string $file): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $observed = $this->observeFile($file);
        if (getenv('JSTS_RECORD') === '1') {
            self::$recorded[$file] = $observed;
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(
            expected: self::flatten(self::expectations()[$file] ?? []),
            actual: self::flatten($observed),
            message: sprintf(
                '%s: the modes no longer answer as %s lists. An entry that now agrees with the suite is '
                . 'progress — delete it; a new one is a regression or needs a reason. Re-record with JSTS_RECORD=1.',
                $file,
                'expectations.php',
            ),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function suiteFiles(): iterable
    {
        foreach (self::suiteFileNames() as $file) {
            yield $file => [$file];
        }
    }

    /**
     * Every (group, test, mode) that does not agree with the suite, keyed group → test → mode, with
     * `*` as the test key for what holds for the whole group (representability, generation, loading).
     *
     * Three passes: generate every group once per mode, load-check the generated classes in a child
     * process, then evaluate every test against the classes that load. The child is there because a
     * generated class that cannot be LINKED (`X cannot implement Y - it is not an interface`) is a
     * fatal error no `catch` sees — loaded here, it would end the whole run.
     *
     * @return array<string, array<string, array<string, array{0: string, 1: string}>>>
     */
    private function observeFile(string $file): array
    {
        $contents = (string)file_get_contents(self::FIXTURE_DIRECTORY . '/' . $file);
        /** @var list<array{description: string, schema: mixed, tests: list<array{description: string, valid: bool}>}> $groups */
        $groups = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        // The data is decoded a second time as objects: `{}` and `[]` are different payloads, and an
        // associative decode cannot tell them apart any more.
        /** @var list<stdClass> $rawGroups */
        $rawGroups = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

        $observed = [];
        $generated = [];
        foreach ($groups as $groupIndex => $group) {
            $wrapped = self::wrap($group['schema']);
            foreach (GenerationMode::cases() as $mode) {
                if (!array_key_exists('spec', $wrapped)) {
                    $observed[$group['description']][self::WHOLE_GROUP][$mode->value] = [$wrapped['status'], $wrapped['reason']];

                    continue;
                }

                $namespace = 'Jsts' . $mode->tag() . substr(md5($file . "\0" . $group['description']), 0, 12);
                try {
                    $generated[$groupIndex][$mode->value] = $this->generate(
                        spec: $wrapped['spec'],
                        namespace: $namespace,
                        mode: $mode->value,
                    );
                } catch (Throwable $throwable) {
                    $observed[$group['description']][self::WHOLE_GROUP][$mode->value] = [
                        self::STATUS_GENERATION_FAILS,
                        self::describe($throwable),
                    ];
                }
            }
        }

        $directories = [];
        foreach ($generated as $byMode) {
            foreach ($byMode as $directory) {
                $directories[] = $directory;
            }
        }
        $fatalErrors = $this->loadCheck($directories);

        foreach ($generated as $groupIndex => $byMode) {
            $group = $groups[$groupIndex];
            foreach (GenerationMode::cases() as $mode) {
                $directory = $byMode[$mode->value] ?? null;
                if ($directory === null) {
                    continue;
                }
                $fatalError = $fatalErrors[$directory] ?? null;
                if ($fatalError !== null) {
                    $observed[$group['description']][self::WHOLE_GROUP][$mode->value] = [
                        self::STATUS_GENERATION_FAILS,
                        'fatal error loading the generated classes: ' . $fatalError,
                    ];

                    continue;
                }

                try {
                    $fqcn = $this->load($directory);
                } catch (Throwable $throwable) {
                    $observed[$group['description']][self::WHOLE_GROUP][$mode->value] = [
                        self::STATUS_GENERATION_FAILS,
                        self::describe($throwable),
                    ];

                    continue;
                }

                foreach ($group['tests'] as $testIndex => $test) {
                    $body = (string)json_encode(
                        value: (object)['f' => $rawGroups[$groupIndex]->tests[$testIndex]->data],
                        flags: JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    );
                    [$answer, $detail] = $this->answer(mode: $mode, fqcn: $fqcn, json: $body);
                    if ($answer === 'crashes') {
                        $observed[$group['description']][$test['description']][$mode->value] = [self::STATUS_CRASHES, $detail];

                        continue;
                    }
                    if (($answer === 'accepts') === $test['valid']) {
                        continue;
                    }
                    $observed[$group['description']][$test['description']][$mode->value] = [
                        self::STATUS_DIVERGES,
                        $test['valid']
                            ? 'refuses data the suite accepts (untriaged)'
                            : 'accepts data the suite rejects (untriaged)',
                    ];
                }
            }
        }

        return self::inSuiteOrder(observed: $observed, groups: $groups);
    }

    /**
     * The entries in the order the suite lists groups, tests and modes, so a re-recorded file diffs
     * by content alone.
     *
     * @param array<string, array<string, array<string, array{0: string, 1: string}>>> $observed
     * @param list<array{description: string, schema: mixed, tests: list<array{description: string, valid: bool}>}> $groups
     * @return array<string, array<string, array<string, array{0: string, 1: string}>>>
     */
    private static function inSuiteOrder(array $observed, array $groups): array
    {
        $ordered = [];
        foreach ($groups as $group) {
            $entries = $observed[$group['description']] ?? [];
            $tests = [self::WHOLE_GROUP, ...array_column($group['tests'], 'description')];
            foreach ($tests as $test) {
                foreach (GenerationMode::cases() as $mode) {
                    $entry = $entries[$test][$mode->value] ?? null;
                    if ($entry !== null) {
                        $ordered[$group['description']][$test][$mode->value] = $entry;
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * Loads each directory's generated classes in a child process, in the order `load()` will, and
     * returns the fatal error of each directory that does not load. A fatal ends the child, so it is
     * restarted after the directory that killed it.
     *
     * @param list<string> $directories
     * @return array<string, string>
     */
    private function loadCheck(array $directories): array
    {
        $script = 'require $argv[1];'
            . 'foreach (array_slice($argv, 2) as $directory) {'
            . ' foreach (glob($directory . "/*.php") ?: [] as $file) { require_once $file; }'
            . ' fwrite(STDOUT, "loaded " . $directory . "\n");'
            . '}';
        $fatalErrors = [];
        while ($directories !== []) {
            $arguments = [
                PHP_BINARY,
                '-d',
                'display_errors=stderr',
                '-d',
                'log_errors=0',
                '-r',
                $script,
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                ...$directories,
            ];
            $command = implode(' ', array_map(escapeshellarg(...), $arguments));
            $process = proc_open(
                command: $command,
                descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                pipes: $pipes,
            );
            self::assertIsResource($process);
            $output = (string)stream_get_contents($pipes[1]);
            $errors = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $loaded = substr_count($output, 'loaded ');
            if ($loaded === count($directories)) {
                break;
            }
            $failed = $directories[$loaded];
            $message = trim((string)preg_replace('/^(PHP )?Fatal error:\s*/m', '', strtok($errors, "\n") ?: 'the child process died'));
            $fatalErrors[$failed] = self::shorten($message);
            $directories = array_slice($directories, $loaded + 1);
        }

        return $fatalErrors;
    }

    /**
     * `accepts`, `refuses` or `crashes` (with what was thrown). A PHP warning is turned into an
     * exception for the duration, so it counts as the crash it is in production.
     *
     * @param class-string $fqcn
     * @return array{0: string, 1: string}
     */
    private function answer(GenerationMode $mode, string $fqcn, string $json): array
    {
        self::throwOnWarnings();

        try {
            $accepts = match ($mode) {
                GenerationMode::Runtime => $this->runtimeAccepts($fqcn, $json),
                GenerationMode::Symfony => $this->symfonyAccepts($fqcn, $json),
                GenerationMode::Laravel => $this->laravelAccepts($fqcn, $json),
                GenerationMode::LaravelData => $this->laravelDataAccepts($fqcn, $json),
                GenerationMode::Yii3 => $this->yii3Accepts($fqcn, $json),
            };
        } catch (Throwable $throwable) {
            return ['crashes', self::describe($throwable)];
        } finally {
            restore_error_handler();
        }

        return [$accepts ? 'accepts' : 'refuses', ''];
    }

    /**
     * The deserializer reports a bad payload with an exception — that is its 400. An `Error` or a
     * PHP warning is not a verdict, so it is rethrown and counted as a crash.
     *
     * @param class-string $fqcn
     */
    private function runtimeAccepts(string $fqcn, string $json): bool
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            (new DtoNormalizer())->validateAndNormalizeToArray((new DtoDeserializer())->deserialize($request, $fqcn));
        } catch (Exception $exception) {
            if ($exception instanceof ErrorException) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /**
     * A serializer exception is what `#[MapRequestPayload]` turns into a 4xx, a violation is the
     * validator's refusal; anything else escapes as the 500 it would be.
     *
     * @param class-string $fqcn
     */
    private function symfonyAccepts(string $fqcn, string $json): bool
    {
        [$serializer, $validator] = self::symfony();
        try {
            $dto = $serializer->deserialize($json, $fqcn, 'json');
        } catch (SerializerException) {
            return false;
        }

        return count($validator->validate($dto)) === 0;
    }

    /**
     * Rules, `withValidator()` with the raw body, then `fromValidated()` — as the generated
     * FormRequest runs them. A refusal is a failing validator; a throw from hydration is a 500.
     *
     * @param class-string $fqcn
     */
    private function laravelAccepts(string $fqcn, string $json): bool
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return false;
        }

        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator, $json);
        }

        try {
            if ($validator->fails()) {
                return false;
            }
        } catch (ValidationException) {
            return false;
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        call_user_func([$fqcn, 'fromValidated'], $validated);

        return true;
    }

    /**
     * Through a request, as `ValidationParityTest` explains: `from($array)` would not validate.
     *
     * @param class-string $fqcn
     */
    private function laravelDataAccepts(string $fqcn, string $json): bool
    {
        LaravelDataContainer::boot();

        return (bool)LaravelDataContainer::withRequest(
            json: $json,
            work: static function (LaravelRequest $request) use ($fqcn): bool {
                try {
                    $fqcn::from($request);
                } catch (ValidationException) {
                    return false;
                }

                return true;
            },
        );
    }

    /**
     * Through `ValidatingHydrator`, the setup README.yii3 makes mandatory. An invalid result is the
     * refusal; a throw is a 500.
     *
     * @param class-string $fqcn
     */
    private function yii3Accepts(string $fqcn, string $json): bool
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return false;
        }

        return (new Yii3Container())->hydrateValidated($fqcn, $payload)->isValid();
    }

    /**
     * @return array{0: Serializer, 1: ValidatorInterface}
     */
    private static function symfony(): array
    {
        if (self::$symfony !== null) {
            return self::$symfony;
        }

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            normalizers: [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                    propertyAccessor: null,
                    propertyTypeExtractor: $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            encoders: [new JsonEncoder()],
        );

        self::$symfony = [$serializer, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()];

        return self::$symfony;
    }

    /**
     * The OpenAPI document a group's schema becomes, or why it cannot become one faithfully.
     *
     * The schema moves from the document root to `properties.f`, so every JSON pointer that named the
     * root has to follow it: root `$defs` are hoisted into `components.schemas` (the generator's own
     * reading of a document-level `$defs`), and a pointer into the rest of the root makes the root a
     * component `ProbeF` that `f` references. What cannot be followed is said, not guessed:
     *
     * - a reference to the suite's `remotes/` (served at localhost:1234) or to a json-schema.org
     *   metaschema is `skipped` — the generator does not fetch them, and the remotes are not copied;
     * - a JSON pointer inside an embedded `$id` resource is relative to THAT resource; it is left as
     *   written, and so is every pointer of a root whose `$id` is kept, since the generator resolves
     *   pointers against the resource they sit in.
     *
     * @return array{spec: array<string, mixed>}|array{status: string, reason: string}
     */
    private static function wrap(mixed $schema): array
    {
        if (is_bool($schema)) {
            return ['spec' => self::document(property: $schema, components: [])];
        }
        if (!is_array($schema)) {
            return ['status' => self::STATUS_NOT_REPRESENTABLE, 'reason' => 'the schema is neither an object nor a boolean'];
        }

        $dialect = $schema['$schema'] ?? null;
        if (is_string($dialect) && $dialect !== self::DIALECT) {
            return [
                'status' => self::STATUS_SKIPPED,
                'reason' => sprintf('declares the remote metaschema %s (suite remotes/, not resolvable offline)', $dialect),
            ];
        }

        $identifiers = [];
        $references = [];
        self::collect(node: $schema, base: null, embedded: false, root: true, identifiers: $identifiers, references: $references);

        $needsComponent = false;
        $hoistDefinitions = false;
        $keepRootIdentifier = false;
        foreach ($references as [$keyword, $reference, $base, $embedded]) {
            if (str_starts_with($reference, '#')) {
                $isPointer = $reference === '#' || str_starts_with($reference, '#/');
                // A pointer inside an embedded `$id` resource is relative to THAT resource, which
                // keeps its `$id` and moves with the root; the generator resolves it there. A
                // `$dynamicRef` written as a pointer is a pointer like any `$ref`.
                if ($isPointer && !$embedded) {
                    $hoistDefinitions = $hoistDefinitions || str_starts_with($reference, '#/$defs/');
                    $needsComponent = $needsComponent || !str_starts_with($reference, '#/$defs/');
                }

                continue;
            }

            $target = self::resolveUri(base: $base, reference: $reference);
            $document = $target === null ? null : explode('#', $target, 2)[0];
            if ($document !== null && in_array($document, $identifiers, true)) {
                $keepRootIdentifier = true;

                continue;
            }

            return [
                'status' => self::STATUS_SKIPPED,
                'reason' => $document !== null && str_contains($document, 'json-schema.org/')
                    ? sprintf('%s "%s" needs the json-schema.org metaschema, not resolvable offline', $keyword, $reference)
                    : sprintf('%s "%s" needs the suite remotes/ (localhost:1234), not resolvable offline', $keyword, $reference),
            ];
        }

        if ($keepRootIdentifier) {
            // The root stays a resource of its own, so every pointer in it resolves against it where
            // it now sits — hoisting `$defs` out of it would take them away from that base.
            $hoistDefinitions = false;
            $needsComponent = false;
        }

        $definitions = $hoistDefinitions && is_array($schema['$defs'] ?? null) ? $schema['$defs'] : [];
        foreach (array_keys($definitions) as $name) {
            if (in_array((string)$name, ['Probe', self::COMPONENT], true)) {
                return [
                    'status' => self::STATUS_NOT_REPRESENTABLE,
                    'reason' => sprintf('$defs "%s" collides with a wrapper component name', $name),
                ];
            }
        }

        $root = [];
        foreach ($schema as $keyword => $value) {
            $dropped = $keyword === '$schema'
                || ($keyword === '$id' && !$keepRootIdentifier)
                || ($keyword === '$defs' && $hoistDefinitions);
            if (!$dropped) {
                $root[$keyword] = $value;
            }
        }

        $components = [];
        foreach ($definitions as $name => $definition) {
            $components[(string)$name] = self::rewritePointers(node: $definition, hoistDefinitions: $hoistDefinitions, root: false);
        }
        if (!$keepRootIdentifier) {
            $root = self::rewritePointers(node: $root, hoistDefinitions: $hoistDefinitions, root: true);
        }
        if ($needsComponent) {
            $components[self::COMPONENT] = $root;
            $root = ['$ref' => self::COMPONENT_POINTER];
        }

        return ['spec' => self::document(property: $root, components: $components)];
    }

    /**
     * @param array<string, mixed>|bool $property
     * @param array<string, mixed> $components
     * @return array<string, mixed>
     */
    private static function document(array|bool $property, array $components): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => $property],
                    ],
                    ...$components,
                ],
            ],
        ];
    }

    /**
     * Walks a schema, collecting the absolute `$id`s it declares and every `$ref`/`$dynamicRef`
     * together with the base URI it resolves against and whether it sits in an embedded resource.
     *
     * @param list<string> $identifiers
     * @param list<array{0: string, 1: string, 2: ?string, 3: bool}> $references
     */
    private static function collect(mixed $node, ?string $base, bool $embedded, bool $root, array &$identifiers, array &$references): void
    {
        if (!is_array($node)) {
            return;
        }

        $identifier = $node['$id'] ?? null;
        if (is_string($identifier) && !array_is_list($node)) {
            $resolved = self::resolveUri(base: $base, reference: $identifier);
            $base = $resolved === null ? $base : explode('#', $resolved, 2)[0];
            if ($resolved !== null) {
                $identifiers[] = $base;
            }
            $embedded = $embedded || !$root;
        }

        foreach ($node as $keyword => $value) {
            if (in_array($keyword, self::DATA_KEYWORDS, true) && !array_is_list($node)) {
                continue;
            }
            if (($keyword === '$ref' || $keyword === '$dynamicRef') && is_string($value)) {
                $references[] = [$keyword, $value, $base, $embedded];

                continue;
            }
            self::collect(node: $value, base: $base, embedded: $embedded, root: false, identifiers: $identifiers, references: $references);
        }
    }

    /**
     * Follows the root to its new place: `#` and `#/…` into the `ProbeF` component, `#/$defs/x…` into
     * the hoisted `components.schemas.x…`, for `$ref` and `$dynamicRef` alike. Anchors (`#name`) stay
     * as written, and so does everything inside an embedded `$id` resource: its pointers are its own.
     */
    private static function rewritePointers(mixed $node, bool $hoistDefinitions, bool $root): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (!$root && !array_is_list($node) && is_string($node['$id'] ?? null)) {
            return $node;
        }

        $rewritten = [];
        foreach ($node as $keyword => $value) {
            if (in_array($keyword, self::DATA_KEYWORDS, true) && !array_is_list($node)) {
                $rewritten[$keyword] = $value;

                continue;
            }
            if (($keyword === '$ref' || $keyword === '$dynamicRef') && is_string($value) && ($value === '#' || str_starts_with($value, '#/'))) {
                $rewritten[$keyword] = match (true) {
                    $hoistDefinitions && str_starts_with($value, '#/$defs/') => '#/components/schemas/' . substr($value, 8),
                    $value === '#' => self::COMPONENT_POINTER,
                    default => self::COMPONENT_POINTER . substr($value, 1),
                };

                continue;
            }
            $rewritten[$keyword] = self::rewritePointers(node: $value, hoistDefinitions: $hoistDefinitions, root: false);
        }

        return $rewritten;
    }

    /**
     * Just enough of RFC 3986 reference resolution to tell a reference to a resource the schema
     * declares itself from one that needs the suite's remotes. Null when there is nothing to resolve
     * against.
     */
    private static function resolveUri(?string $base, string $reference): ?string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1) {
            return $reference;
        }
        if ($base === null) {
            return null;
        }
        $base = explode('#', $base, 2)[0];
        if (str_starts_with($reference, '#')) {
            return $base . $reference;
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*:)(//[^/?#]*)?([^?#]*)~i', $base, $parts) !== 1 || ($parts[2] ?? '') === '') {
            return null;
        }
        if (str_starts_with($reference, '//')) {
            return $parts[1] . $reference;
        }
        $slash = strrpos($parts[3], '/');
        $directory = $slash === false ? '' : substr($parts[3], 0, $slash + 1);
        $path = str_starts_with($reference, '/') ? $reference : $directory . $reference;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            if ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $parts[1] . $parts[2] . '/' . ltrim(implode('/', $segments), '/');
    }

    /**
     * Writes the group's classes for one mode and returns their directory. Nothing is loaded here.
     *
     * @param array<string, mixed> $spec
     */
    private function generate(array $spec, string $namespace, string $mode): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        self::throwOnWarnings();
        ob_start();

        try {
            (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);
        } finally {
            ob_end_clean();
            restore_error_handler();
        }

        return $target;
    }

    /**
     * @return class-string
     */
    private function load(string $directory): string
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        /** @var class-string $fqcn */
        $fqcn = basename($directory) . '\Probe';

        return $fqcn;
    }

    /**
     * A PHP warning or notice becomes an exception until `restore_error_handler()`: in production it
     * is a 500 (or a broken class), not a verdict. Deprecations stay with PHPUnit.
     */
    private static function throwOnWarnings(): void
    {
        set_error_handler(
            callback: static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            error_levels: E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED,
        );
    }

    private static function describe(Throwable $throwable): string
    {
        $message = strtok($throwable->getMessage(), "\n");

        return (new ReflectionClass($throwable))->getShortName() . ': ' . self::shorten($message === false ? '' : $message);
    }

    /**
     * One line, without this machine's paths or the per-group namespaces, so the expectations file
     * is the same wherever it is recorded.
     */
    private static function shorten(string $message): string
    {
        $message = str_replace(dirname(__DIR__, 2) . '/', '', $message);
        $message = (string)preg_replace('~tests/Parity/output-jsts/Jsts[A-Z][a-z][0-9a-f]{12}/~', '', $message);
        $message = (string)preg_replace('/Jsts[A-Z][a-z][0-9a-f]{12}\\\/', '', $message);
        if (strlen($message) > 160) {
            $message = substr($message, 0, 157) . '...';
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private static function suiteFileNames(): array
    {
        $files = array_map(basename(...), glob(self::FIXTURE_DIRECTORY . '/*.json') ?: []);
        sort($files);

        return $files;
    }

    /**
     * @return array<string, array<string, array<string, array<string, array{0: string, 1: string}>>>>
     */
    private static function expectations(): array
    {
        if (!is_file(self::EXPECTATIONS_FILE)) {
            return [];
        }

        /** @var array<string, array<string, array<string, array<string, array{0: string, 1: string}>>>> $expectations */
        $expectations = require self::EXPECTATIONS_FILE;

        return $expectations;
    }

    /**
     * @param array<string, array<string, array<string, array{0: string, 1: string}>>> $entries
     * @return array<string, string>
     */
    private static function flatten(array $entries): array
    {
        $flat = [];
        foreach ($entries as $group => $tests) {
            foreach ($tests as $test => $modes) {
                foreach ($modes as $mode => [$status]) {
                    $flat[sprintf('%s › %s › %s', $group, $test, $mode)] = $status;
                }
            }
        }

        return $flat;
    }

    /**
     * @param array<string, array<string, array<string, array{0: string, 1: string}>>> $recorded
     * @param array<string, array<string, array<string, array{0: string, 1: string}>>> $previous
     * @return array<string, array<string, array<string, array{0: string, 1: string}>>>
     */
    private static function keepReasons(array $recorded, array $previous): array
    {
        foreach ($recorded as $group => $tests) {
            foreach ($tests as $test => $modes) {
                foreach ($modes as $mode => [$status]) {
                    $old = $previous[$group][$test][$mode] ?? null;
                    if ($old !== null && $old[0] === $status) {
                        $recorded[$group][$test][$mode] = $old;
                    }
                }
            }
        }

        return $recorded;
    }

    /**
     * @param array<string, array<string, array<string, array<string, array{0: string, 1: string}>>>> $expectations
     */
    private static function exportExpectations(array $expectations): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/*',
            ' * What does NOT agree with the JSON-Schema-Test-Suite yet, per file › group › test › mode:',
            ' * [status, reason]. Status is one of diverges, crashes, generation-fails, skipped,',
            ' * not-representable; the test key "*" covers the whole group. Read by',
            ' * tests/Parity/JsonSchemaTestSuiteTest.php — burn it down, and re-record with JSTS_RECORD=1.',
            ' */',
            '',
            'return [',
        ];
        foreach ($expectations as $file => $groups) {
            $lines[] = '    ' . self::quote($file) . ' => [';
            foreach ($groups as $group => $tests) {
                $lines[] = '        ' . self::quote((string)$group) . ' => [';
                foreach ($tests as $test => $modes) {
                    $lines[] = '            ' . self::quote((string)$test) . ' => [';
                    foreach ($modes as $mode => [$status, $reason]) {
                        $lines[] = sprintf(
                            '                %s => [%s, %s],',
                            self::quote($mode),
                            self::quote($status),
                            self::quote($reason),
                        );
                    }
                    $lines[] = '            ],';
                }
                $lines[] = '        ],';
            }
            $lines[] = '    ],';
        }
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }

    /**
     * A single-quoted PHP literal, escaping a backslash only where single quotes need it (before a
     * quote, a backslash or the closing quote) — the spelling php-cs-fixer keeps, so a re-recorded
     * file does not churn.
     */
    private static function quote(string $value): string
    {
        $quoted = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $character = $value[$index];
            $next = $value[$index + 1] ?? '';
            $quoted .= match (true) {
                $character === "'" => "\\'",
                $character === '\\' && ($next === '' || $next === '\\' || $next === "'") => '\\\\',
                default => $character,
            };
        }

        return "'" . $quoted . "'";
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
