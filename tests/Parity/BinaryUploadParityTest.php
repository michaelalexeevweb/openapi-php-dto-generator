<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
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
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Throwable;

/**
 * `format: binary` across all three modes.
 *
 * The keyword resolves to `UploadedFile`, which makes the PHP type do half the work — and that is
 * exactly what made it easy to get wrong: in Laravel mode the import was decided from the `format`
 * keyword, which is PRUNED from the constraints because the type carries it, so the emitted hint named
 * a class that does not exist in the DTO's namespace. On top of that no `file` rule was emitted, so a
 * string payload passed validation and the constructor's TypeError surfaced as a 500 instead of a 422.
 *
 * Neither other mode had it, but nothing asserted that either. Hence one test per mode, in one place:
 * the type and its import are emitted, and a payload that is not a file is REJECTED — never a TypeError.
 */
final class BinaryUploadParityTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-binary-parity';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
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

    /**
     * @return array<string, mixed>
     */
    private static function uploadSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Upload' => [
                        'type' => 'object',
                        'required' => ['doc'],
                        'properties' => [
                            'doc' => ['type' => 'string', 'format' => 'binary'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{0: class-string, 1: string}
     */
    private function generate(GenerationMode $mode): array
    {
        $namespace = 'Bin' . $mode->tag();
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray(self::uploadSpec(), $target, $namespace, $mode->value);

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
        $fqcn = $namespace . '\Upload';

        return [$fqcn, $target . '/Upload.php'];
    }

    #[DataProvider('modeProvider')]
    public function testTheTypeHintAndItsImportAreEmitted(GenerationMode $mode): void
    {
        [, $file] = $this->generate($mode);
        $source = (string)file_get_contents($file);

        if ($mode === GenerationMode::Yii3) {
            // yii3 output may not depend on symfony/http-foundation, and `input-http` binds uploads
            // onto the PSR-7 interface through `#[UploadedFiles]`. Same guarantee, different class:
            // the hint is imported, so it cannot resolve inside the DTO's own namespace.
            $this->assertStringContainsString('use Psr\Http\Message\UploadedFileInterface;', $source);
            $this->assertStringContainsString('UploadedFileInterface $doc', $source);
            $this->assertStringNotContainsString('Symfony\\', $source);
        } else {
            // Laravel's own UploadedFile extends the Symfony one, so those modes hint the Symfony class.
            $this->assertStringContainsString('use Symfony\Component\HttpFoundation\File\UploadedFile;', $source);
            $this->assertStringContainsString('UploadedFile $doc', $source);
        }

        // Without the import the hint resolves inside the DTO's own namespace, and every upload dies
        // with "must be of type <Namespace>\UploadedFile".
        $this->assertStringNotContainsString(sprintf('namespace %s', $mode->value), $source);
    }

    #[DataProvider('modeProvider')]
    public function testANonFilePayloadIsRejectedRatherThanCrashing(GenerationMode $mode): void
    {
        [$fqcn] = $this->generate($mode);
        $payload = ['doc' => 'not-a-file', 'note' => 'x'];

        // No `default` arm: a mode added to GenerationMode without a rejection path here fails loudly.
        $rejection = match ($mode) {
            GenerationMode::Runtime => $this->runtimeRejection($fqcn, $payload),
            GenerationMode::Symfony => $this->symfonyRejection($fqcn, $payload),
            GenerationMode::Laravel => $this->laravelRejection($fqcn, $payload),
            GenerationMode::LaravelData => $this->laravelDataRejection($fqcn, $payload),
            GenerationMode::Yii3 => $this->yii3Rejection($fqcn, $payload),
        };

        $this->assertNotNull(
            $rejection,
            sprintf('%s mode accepted a string for a binary property', $mode->value),
        );
        $this->assertStringNotContainsString(
            'TypeError',
            $rejection,
            sprintf('%s mode rejected it with a TypeError, which is a 500 rather than a 422', $mode->value),
        );
    }

    /**
     * Every mode, from the enum — so a mode added there is measured here without editing this suite.
     *
     * @return array<string, array{0: GenerationMode}>
     */
    public static function modeProvider(): array
    {
        $provided = [];
        foreach (GenerationMode::cases() as $mode) {
            $provided[$mode->value . ' mode'] = [$mode];
        }

        return $provided;
    }

    /**
     * @param class-string $fqcn
     * @param array<string, mixed> $payload
     */
    private function runtimeRejection(string $fqcn, array $payload): ?string
    {
        $request = Request::create('/', 'POST', $payload);

        try {
            (new DtoDeserializer())->deserialize($request, $fqcn);
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return null;
    }

    /**
     * @param class-string $fqcn
     * @param array<string, mixed> $payload
     */
    private function symfonyRejection(string $fqcn, array $payload): ?string
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            [
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

        try {
            $serializer->denormalize($payload, $fqcn);
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return null;
    }

    /**
     * yii3 mode: a string where an `UploadedFileInterface` belongs cannot fill the property, so the
     * property stays uninitialised and the interpreter reports it as missing. The payload is refused
     * either way — which is the guarantee this suite is about — but the stage differs.
     *
     * @param class-string $fqcn
     * @param array<string, mixed> $payload
     */
    private function yii3Rejection(string $fqcn, array $payload): ?string
    {
        $container = new Yii3Container();

        try {
            $result = $container->validate($container->hydrate($fqcn, $payload));
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        if ($result->isValid()) {
            return null;
        }

        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return implode(' | ', $messages);
    }

    /**
     * laravel-data validates and hydrates in one call, so both halves of the question — "is a string
     * refused" and "is it refused with a 422 rather than a TypeError" — are answered by it.
     *
     * @param class-string $fqcn
     * @param array<string, mixed> $payload
     */
    private function laravelDataRejection(string $fqcn, array $payload): ?string
    {
        LaravelDataContainer::boot();

        try {
            $fqcn::validateAndCreate($payload);
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return null;
    }

    /**
     * @param class-string $fqcn
     * @param array<string, mixed> $payload
     */
    private function laravelRejection(string $fqcn, array $payload): ?string
    {
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator);
        }

        if ($validator->fails()) {
            return 'ValidationException: ' . implode(', ', $validator->errors()->all());
        }

        // The rules let it through, so whatever happens next is the bug this test exists for.
        try {
            call_user_func([$fqcn, 'fromValidated'], $validator->validated());
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return null;
    }
}
