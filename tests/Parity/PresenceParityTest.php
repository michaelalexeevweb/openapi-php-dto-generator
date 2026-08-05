<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The third parity axis: "was this field sent at all" — the question PATCH and JSON-Merge-Patch are
 * built on.
 *
 * The two existing parity suites cannot see it. `ValidationParityTest` compares accept/reject,
 * `NormalizationParityTest` compares the response array — and in the response an
 * absent optional and one sent as `null` look the same in Symfony mode by design (documented there).
 * Presence used to be a runtime-only feature; since it became the default shape of a Symfony DTO
 * (private property + setter-recorded flag) nothing compared the two implementations.
 *
 * The APIs differ on purpose and the test speaks both: runtime reports provenance per source
 * (`isNicknameInRequest()` — it also binds path/query/header/cookie), Symfony has one flag per
 * optional property (`isNicknameProvided()`). What must agree is the ANSWER: absent is false, present
 * is true, and "present as null" counts as present in both.
 */
final class PresenceParityTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-presence-parity';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    #[DataProvider('payloadProvider')]
    public function testBothModesAgreeOnWhatWasProvided(
        string $json,
        bool $nicknameProvided,
        bool $noteProvided,
    ): void {
        $expected = ['nickname' => $nicknameProvided, 'note' => $noteProvided];

        $this->assertSame($expected, $this->runtimePresence($json), 'runtime presence');
        $this->assertSame($expected, $this->symfonyPresence($json), 'symfony presence');
        $this->assertSame($expected, $this->laravelPresence($json), 'laravel presence');
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function payloadProvider(): array
    {
        return [
            // json, nickname provided, note provided
            'nothing optional sent' => ['{"id":1}', false, false],
            'optional sent as null' => ['{"id":1,"nickname":null}', true, false],
            'optional sent with a value' => ['{"id":1,"nickname":"nick"}', true, false],
            'optional sent as an empty string' => ['{"id":1,"nickname":""}', true, false],
            'both optionals sent' => ['{"id":1,"nickname":"nick","note":"n"}', true, true],
            'only the second optional sent' => ['{"id":1,"note":null}', false, true],
        ];
    }

    /**
     * A nested DTO tracks presence of its own fields — in runtime mode because the nested DTO is
     * deserialized by the same code, in Symfony mode because the serializer calls the nested setter.
     */
    public function testBothModesTrackPresenceInsideANestedDto(): void
    {
        $json = '{"id":1,"child":{"kept":"k"}}';

        $runtime = $this->runtimeNestedPresence($json);
        $symfony = $this->symfonyNestedPresence($json);
        $laravel = $this->laravelNestedPresence($json);

        $this->assertSame(['kept' => true, 'dropped' => false], $runtime, 'runtime nested presence');
        $this->assertSame($runtime, $symfony, 'symfony must agree inside a nested DTO');
        $this->assertSame($runtime, $laravel, 'laravel must agree inside a nested DTO');
    }

    /**
     * @return array<string, bool>
     */
    private function runtimePresence(string $json): array
    {
        $dto = $this->runtimeDto(self::patchSpec(), 'Patch', $json);

        return [
            'nickname' => $dto->isNicknameInRequest(),
            'note' => $dto->isNoteInRequest(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function symfonyPresence(string $json): array
    {
        $dto = $this->symfonyDto(self::patchSpec(), 'Patch', $json);

        return [
            'nickname' => $dto->isNicknameProvided(),
            'note' => $dto->isNoteProvided(),
        ];
    }

    /**
     * Laravel needs no sentinel and no flag written by a setter: `validated()` returns only the keys the
     * payload carried, and the generated DTO records that key set.
     *
     * @return array<string, bool>
     */
    private function laravelPresence(string $json): array
    {
        $dto = $this->laravelDto(self::patchSpec(), 'Patch', $json);

        return [
            'nickname' => $dto->isNicknameProvided(),
            'note' => $dto->isNoteProvided(),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function laravelDto(array $spec, string $rootClass, string $json): object
    {
        $fqcn = $this->generate($spec, 'PresLv' . $this->namespaceSuffix($json . $rootClass), 'laravel', $rootClass);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator, $json);
        }

        // The DTO is built from `validated()`, exactly as a FormRequest's `toDto()` does — anything else
        // would measure a shape the application never sees.
        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return call_user_func([$fqcn, 'fromValidated'], $validated);
    }

    /**
     * @return array<string, bool>
     */
    private function runtimeNestedPresence(string $json): array
    {
        $child = $this->runtimeDto(self::nestedSpec(), 'Owner', $json)->getChild();

        return ['kept' => $child->isKeptInRequest(), 'dropped' => $child->isDroppedInRequest()];
    }

    /**
     * @return array<string, bool>
     */
    private function symfonyNestedPresence(string $json): array
    {
        $child = $this->symfonyDto(self::nestedSpec(), 'Owner', $json)->getChild();

        return ['kept' => $child->isKeptProvided(), 'dropped' => $child->isDroppedProvided()];
    }

    /**
     * @return array<string, bool>
     */
    private function laravelNestedPresence(string $json): array
    {
        $child = $this->laravelDto(self::nestedSpec(), 'Owner', $json)->getChild();

        return ['kept' => $child->isKeptProvided(), 'dropped' => $child->isDroppedProvided()];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function runtimeDto(array $spec, string $rootClass, string $json): object
    {
        $fqcn = $this->generate($spec, 'PresRt' . $this->namespaceSuffix($json . $rootClass), 'runtime', $rootClass);
        $request = Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);

        return (new DtoDeserializer())->deserialize($request, $fqcn);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function symfonyDto(array $spec, string $rootClass, string $json): object
    {
        $fqcn = $this->generate($spec, 'PresSy' . $this->namespaceSuffix($json . $rootClass), 'symfony', $rootClass);

        return $this->serializer()->deserialize($json, $fqcn, 'json');
    }

    /**
     * `id` is required so the payload always has one mandatory field; everything else is optional,
     * which is what presence tracking is about.
     *
     * @return array<string, mixed>
     */
    private static function patchSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Patch' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'nickname' => ['type' => ['string', 'null']],
                            'note' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nestedSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Owner' => [
                        'type' => 'object',
                        'required' => ['id', 'child'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'child' => ['$ref' => '#/components/schemas/Child'],
                        ],
                    ],
                    'Child' => [
                        'type' => 'object',
                        'properties' => [
                            'kept' => ['type' => ['string', 'null']],
                            'dropped' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        return new Serializer(
            [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    $classMetadataFactory,
                    new MetadataAwareNameConverter($classMetadataFactory),
                    null,
                    $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
    }

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generate(array $spec, string $namespace, string $mode, string $rootClass): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);

        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\\' . $rootClass;

        return $fqcn;
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
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
