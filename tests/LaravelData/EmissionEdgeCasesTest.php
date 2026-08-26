<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use DateTimeImmutable;
use Illuminate\Http\Request;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\LaravelData\Optional;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The corners of the laravel-data emitter that the parity suites and the golden corpus do not reach —
 * each one a branch that decides whether the generated file even LOADS.
 */
final class EmissionEdgeCasesTest extends TestCase
{
    private string $outputDirectory = '';

    protected function setUp(): void
    {
        LaravelDataContainer::boot();
    }

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    /**
     * A property with no type at all — an empty schema, or one carrying only a description or an
     * extension keyword — resolves to `mixed`, and `mixed` CANNOT take part in a union type: PHP refuses
     * `mixed|Optional` at compile time. So this is the one property shape that gets no `|Optional`, which
     * makes the emitted type the difference between a file that loads and a parse error.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('untypedSchemaProvider')]
    public function testAnUntypedPropertyIsPlainMixedWithNoOptionalMember(array $propertySchema): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep'],
                'properties' => ['keep' => ['type' => 'string'], 'any' => $propertySchema],
            ],
        ]);

        $parameters = (new ReflectionClass($namespace . '\Probe'))->getConstructor()?->getParameters() ?? [];
        $this->assertSame('any', $parameters[1]->getName());
        $this->assertSame(
            'mixed',
            (string)$parameters[1]->getType(),
            'a union with mixed in it is a compile-time error, so this must stay standalone',
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function untypedSchemaProvider(): array
    {
        return [
            'an empty schema' => [[]],
            'a description and nothing else' => [['description' => 'anything goes']],
            'an extension keyword only' => [['x-anything' => 1]],
        ];
    }

    /**
     * And the consequence, stated rather than discovered: with no `Optional` in the type, laravel-data
     * fills an absent key with `null`, so presence is NOT observable for such a property and an
     * unprovided one is echoed as `null`. Every other mode omits it. The divergence is declared in
     * `NormalizationParityTest`; what is asserted here is that it does not CRASH, and that a provided
     * value survives intact.
     */
    public function testAnUntypedPropertyHydratesWithoutPresenceTracking(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep'],
                'properties' => ['keep' => ['type' => 'string'], 'any' => ['description' => 'anything']],
            ],
        ]);
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        $absent = LaravelDataContainer::withRequest(
            '{"keep":"x"}',
            static fn(Request $request): object => $fqcn::from($request),
        );
        $this->assertNull($absent->any);
        $this->assertNotInstanceOf(Optional::class, $absent->any, 'mixed cannot carry Optional');

        $provided = LaravelDataContainer::withRequest(
            '{"keep":"x","any":{"k":[1,2]}}',
            static fn(Request $request): object => $fqcn::from($request),
        );
        $this->assertSame(['k' => [1, 2]], $provided->any);
    }

    /**
     * A temporal CONTAINER declares `array<string>`, because that is what it holds.
     *
     * `#[WithCast]` casts the PROPERTY, never the items — see
     * `LaravelDataSemanticsTest::testACastOnAnArrayPropertyDoesNotReachItsItems()`, which measures it
     * against the real package. So the emitter has two honest moves and picked the cheap one: declare
     * the strings. Converting would mean emitting a `Cast` class of ours, and the generated code in
     * this mode depends on nothing of this package — a property the import test next door enforces.
     *
     * Runtime, Symfony and Laravel modes DO hold objects here; the divergence is in the support matrix.
     */
    public function testATemporalContainerDeclaresTheStringsItHolds(): void
    {
        $namespace = $this->generate([
            'Report' => [
                'type' => 'object',
                'required' => ['dates'],
                'properties' => [
                    'dates' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                    'byDay' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string', 'format' => 'date'],
                    ],
                    'at' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
        ]);
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Report';

        $docblock = (string)(new ReflectionClass($fqcn))->getConstructor()?->getDocComment();
        $this->assertStringContainsString('@param array<string> $dates', $docblock);
        $this->assertStringContainsString('@param array<string, string>|Optional $byDay', $docblock);

        $dto = LaravelDataContainer::withRequest(
            '{"dates":["2026-01-15"],"byDay":{"mon":"2026-01-19"},"at":"2026-02-20"}',
            static fn(Request $request): object => $fqcn::from($request),
        );

        // What the declaration now says, driven rather than read: strings in the containers…
        $this->assertSame(['2026-01-15'], $dto->dates);
        $this->assertSame(['mon' => '2026-01-19'], $dto->byDay);
        // …and an object in the SCALAR, where the cast does reach the value.
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->at);
    }

    /**
     * An OPTIONAL uploaded file: `UploadedFile|Optional`, with `file` behind `sometimes`. The binary
     * parity suite only covers a REQUIRED one, and the two differ in exactly the place this mode is about.
     */
    public function testAnOptionalUploadedFileKeepsBothItsTypeAndItsRule(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['note'],
                'properties' => [
                    'note' => ['type' => 'string'],
                    'doc' => ['type' => 'string', 'format' => 'binary'],
                ],
            ],
        ]);
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        $parameters = (new ReflectionClass($fqcn))->getConstructor()?->getParameters() ?? [];
        $type = (string)$parameters[1]->getType();
        $this->assertStringContainsString(UploadedFile::class, $type);
        $this->assertStringContainsString(Optional::class, $type);

        /** @var array<string, mixed> $rules */
        $rules = $fqcn::rules();
        $this->assertSame(['sometimes', 'file'], $rules['doc']);
    }

    /**
     * A schema NAMED like a class this mode imports — `Data`, `Optional`, `Request`, `Validator`,
     * `Container`. The document is entitled to those names, and every generated class in this mode
     * carries imports with exactly them, which PHP resolves in two incompatible ways:
     *
     *     the file DECLARING it     Fatal error: Cannot redeclare X\Data
     *                               (previously declared as local import) — the file never loads
     *     any SIBLING file          the `use` wins over the same-namespace class, so `Holder::$it` was
     *                               typed Illuminate's Request and the payload hydrating it a TypeError
     *
     * Driven end to end rather than asserted on the source, because both failures are invisible to a
     * source assertion: the first is a parse error, the second a type that reads perfectly fine.
     */
    #[DataProvider('collidingSchemaNameProvider')]
    public function testASchemaNamedLikeAnImportedClassStillLoadsAndHydrates(string $schemaName): void
    {
        $namespace = $this->generate([
            'Holder' => [
                'type' => 'object',
                'required' => ['it', 'maps'],
                'properties' => [
                    'it' => ['$ref' => '#/components/schemas/' . $schemaName],
                    // Forces the raw-body check, which is what pulls in Container/Request/stdClass.
                    'maps' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                ],
            ],
            $schemaName => [
                'type' => 'object',
                'required' => ['n'],
                // An optional property is what pulls in Optional.
                'properties' => ['n' => ['type' => 'integer'], 'opt' => ['type' => 'string']],
            ],
        ]);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $dto = LaravelDataContainer::withRequest(
            '{"it":{"n":1},"maps":{"a":2}}',
            static fn(Request $request): object => $holder::from($request),
        );

        $this->assertInstanceOf($namespace . '\\' . $schemaName, $dto->it);
        $this->assertSame(['it' => ['n' => 1], 'maps' => ['a' => 2]], $dto->toArray());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function collidingSchemaNameProvider(): array
    {
        return [
            'Data — the base class every emitted class extends' => ['Data'],
            'Optional — the presence sentinel in every optional type' => ['Optional'],
            'Request — read for the raw body' => ['Request'],
            'Validator — the withValidator() parameter' => ['Validator'],
            'Container — where the request is looked up' => ['Container'],
        ];
    }

    /**
     * Nothing is imported that the file does not use.
     *
     * The import list is assembled from the LARAVEL-mode helpers, and that mode imports for code this one
     * never emits: `InvalidArgumentException` belongs to the `match` in its own hydrator, which throws on
     * an unmapped discriminator value — here laravel-data resolves the morph and there is no hydrator of
     * ours at all. An unused `use` is not a syntax error, which is exactly why nothing else would catch it.
     */
    public function testNothingIsImportedThatTheEmittedFileDoesNotUse(): void
    {
        $namespace = $this->generate([
            'Holder' => [
                'type' => 'object',
                'required' => ['shape'],
                'properties' => [
                    'shape' => ['$ref' => '#/components/schemas/Shape'],
                    'at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'Shape' => [
                'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => [
                        'circle' => '#/components/schemas/Circle',
                        'square' => '#/components/schemas/Square',
                    ],
                ],
            ],
            'Circle' => [
                'type' => 'object',
                'required' => ['kind', 'r'],
                'properties' => ['kind' => ['type' => 'string'], 'r' => ['type' => 'integer']],
            ],
            'Square' => [
                'type' => 'object',
                'required' => ['kind', 'a'],
                'properties' => ['kind' => ['type' => 'string'], 'a' => ['type' => 'integer']],
            ],
        ]);

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            $body = substr($source, (int)strrpos($source, "\nuse ") + 1);

            preg_match_all('/^use ([^;]+);$/m', $source, $matches);
            foreach ($matches[1] as $import) {
                // `use function array_shift;` names a function, not a class: taking the segment after
                // the last backslash would look for the literal "function array_shift" in the body.
                $import = (string)preg_replace('/^function /', '', $import);
                $shortName = substr((string)strrchr('\\' . $import, '\\'), 1);
                $this->assertStringContainsString(
                    $shortName,
                    $body,
                    sprintf('%s imports %s and never uses it', basename($file), $import),
                );
            }
        }
    }

    /**
     * No FormRequest. laravel mode emits one beside the DTO for every request payload, because validation
     * and the typed object are two artefacts there; here they are one class, and an extra file would be a
     * FormRequest nothing resolves.
     *
     * Asserted against laravel mode on the SAME document, so it cannot pass because the probe happens to
     * describe no request payload.
     */
    public function testNoFormRequestIsEmittedWhereLaravelModeEmitsOne(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/things' => [
                    'post' => [
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['s'],
                                'properties' => ['s' => ['type' => 'string']],
                            ]]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        $laravel = $this->emittedFileNames($spec, GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL, 'FrLv');
        $laravelData = $this->emittedFileNames($spec, GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA, 'FrLd');

        $this->assertContains('ThingsPostRequestFormRequest.php', $laravel, 'laravel mode must still emit one');
        $this->assertContains('ThingsPostRequest.php', $laravelData);
        $this->assertSame(
            [],
            array_values(array_filter($laravelData, static fn(string $file): bool => str_contains($file, 'FormRequest'))),
            'laravel-data mode must emit no FormRequest',
        );
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function emittedFileNames(array $spec, string $mode, string $tag): array
    {
        $directory = sys_get_temp_dir() . '/ld-emit-' . strtolower($tag) . '-' . getmypid();
        $this->deleteRecursively($directory);
        mkdir($directory, 0o755, true);

        (new GenerateDtoCommand())->generateFromArray($spec, $directory, 'Emit' . $tag, $mode);
        $files = array_map('basename', glob($directory . '/*.php') ?: []);
        $this->deleteRecursively($directory);

        return $files;
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     */
    private function generate(array $schemas): string
    {
        $namespace = 'Emission' . substr(md5((string)$this->name() . serialize($schemas)), 0, 8);
        $this->outputDirectory = sys_get_temp_dir() . '/ld-emission-' . strtolower($namespace);
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray(
            ['openapi' => '3.1.0', 'info' => ['title' => 'T', 'version' => '1.0.0'], 'components' => ['schemas' => $schemas]],
            $this->outputDirectory,
            $namespace,
            GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );

        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
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
