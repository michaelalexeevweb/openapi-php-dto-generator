<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * What Symfony — not this generator — decides about a request payload.
 *
 * The README documents both halves of this and users act on it, so the claims are pinned here
 * rather than left to rot: which serializer pieces the generated DTOs depend on (two of them fail
 * SILENTLY when absent), and which HTTP status each kind of bad payload produces through
 * `#[MapRequestPayload]`. A Symfony upgrade that changes any of it should fail this test, not
 * quietly make the documentation wrong.
 */
final class SymfonySerializerContractTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-symfony-contract';
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

    private const ORDER_JSON = '{"first_name":"Jo","qty":2,"at":"2026-03-10T12:00:00+00:00","status":"new","lines":[{"sku":"AAA"}]}';

    /**
     * @return array<string, mixed>
     */
    private static function orderSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Order' => [
                        'type' => 'object',
                        'required' => ['first_name', 'qty', 'at', 'status', 'lines'],
                        'properties' => [
                            'first_name' => ['type' => 'string'],
                            'qty' => ['type' => 'integer'],
                            'at' => ['type' => 'string', 'format' => 'date-time'],
                            'status' => ['type' => 'string', 'enum' => ['new', 'done']],
                            'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Line']],
                        ],
                    ],
                    'Line' => [
                        'type' => 'object',
                        'required' => ['sku'],
                        'properties' => ['sku' => ['type' => 'string', 'minLength' => 3]],
                    ],
                ],
            ],
        ];
    }

    public function testWithoutPhpDocExtractorNestedValidationSilentlyDisappears(): void
    {
        $fqcn = $this->generate(self::orderSpec(), 'ContractPhpDoc');
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $tooShort = str_replace('"sku":"AAA"', '"sku":"A"', self::ORDER_JSON);

        $complete = $this->serializer();
        $dto = $complete->deserialize($tooShort, $fqcn, 'json');
        $this->assertInstanceOf('ContractPhpDoc\Line', $dto->getLines()[0]);
        $this->assertCount(1, $validator->validate($dto), 'the nested minLength must be reported');

        // Same payload, same DTO, only PhpDocExtractor removed: the items never become DTOs, so
        // #[Assert\Valid] cascades into nothing and the violation vanishes without a trace.
        $withoutDocblocks = $this->serializer(phpDoc: false);
        $degraded = $withoutDocblocks->deserialize($tooShort, $fqcn, 'json');
        $this->assertIsArray($degraded->getLines()[0]);
        $this->assertCount(0, $validator->validate($degraded));
    }

    public function testWithoutDateTimeNormalizerADateBecomesNow(): void
    {
        $fqcn = $this->generate(self::orderSpec(), 'ContractDate');

        $dto = $this->serializer(dateTime: false)->deserialize(self::ORDER_JSON, $fqcn, 'json');

        $this->assertNotSame(
            '2026-03-10T12:00:00+00:00',
            $dto->getAt(),
            'without DateTimeNormalizer the date is silently replaced by the current time',
        );
    }

    public function testWithoutTheNameConverterAnAliasedPropertyCannotBeBuilt(): void
    {
        $fqcn = $this->generate(self::orderSpec(), 'ContractAlias');
        $bare = new Serializer(
            [new BackedEnumNormalizer(), new DateTimeNormalizer(), new ObjectNormalizer(), new ArrayDenormalizer()],
            [new JsonEncoder()],
        );

        $this->expectExceptionMessage('$firstName');
        $bare->deserialize(self::ORDER_JSON, $fqcn, 'json');
    }

    /**
     * @param array<string, mixed> $serializationContext
     */
    #[DataProvider('payloadProblemProvider')]
    public function testMapRequestPayloadStatusCodes(string $json, array $serializationContext, ?int $expectedStatus): void
    {
        $status = $this->resolvePayload('ContractStatus', $json, $serializationContext);

        $this->assertSame($expectedStatus, $status);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: ?int}>
     */
    public static function payloadProblemProvider(): array
    {
        $ok = self::ORDER_JSON;

        return [
            // Denormalization failures are collected by the resolver and reported as violations,
            // which is why they are 422 and not 400 — the generated constraints never see them.
            'wrong scalar type' => [str_replace('"qty":2', '"qty":"x"', $ok), [], 422],
            'unknown enum member' => [str_replace('"status":"new"', '"status":"nope"', $ok), [], 422],
            'missing required field' => [str_replace('"qty":2,', '', $ok), [], 422],
            'unparsable body' => ['{oops', [], 400],
            'unknown key is dropped' => [substr($ok, 0, -1) . ',"nope":1}', [], null],
            'valid payload' => [$ok, [], null],
        ];
    }

    public function testStrictExtraAttributesEscapesAsAServerError(): void
    {
        // Documented sharp edge: ExtraAttributesException is neither PartialDenormalizationException
        // nor Serializer's InvalidArgumentException, the only two RequestPayloadValueResolver
        // catches, so asking for strict rejection yields a 500 unless the application maps it.
        $this->expectException(ExtraAttributesException::class);

        $this->resolvePayload(
            'ContractStrict',
            substr(self::ORDER_JSON, 0, -1) . ',"nope":1}',
            [AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
        );
    }

    /**
     * Symfony's own answer for PATCH is `OBJECT_TO_POPULATE`: deserialize into the existing object
     * so absent keys keep their current value. It does not work here, and it fails SILENTLY — a
     * `final readonly` class cannot have its properties written a second time, so the patch is
     * dropped without an exception. Pinned because it is the first thing a user will try.
     */
    public function testObjectToPopulateSilentlyDoesNothingOnAReadonlyDto(): void
    {
        $fqcn = $this->generate(self::orderSpec(), 'ContractPopulate');
        $statusEnum = $fqcn . 'Status';
        $existing = new $fqcn(
            firstName: 'old',
            qty: 1,
            at: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            status: $statusEnum::from('new'),
            lines: [],
        );

        $patched = $this->serializer()->deserialize(
            '{"first_name":"new"}',
            $fqcn,
            'json',
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $patched, 'the same instance comes back');
        $this->assertSame('old', $patched->getFirstName(), 'and the payload was silently ignored');
    }

    /**
     * Presence tracking through the path a controller actually takes. The flags are set by the
     * setter, and the resolver reaches the setter through the same serializer — but that is a claim
     * about two moving parts, so it is checked here rather than assumed.
     */
    public function testPresenceFlagsSurviveTheControllerArgumentResolver(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Order' => [
                        'type' => 'object',
                        'required' => ['first_name'],
                        'properties' => [
                            'first_name' => ['type' => 'string'],
                            'note' => ['type' => ['string', 'null']],
                            'gift_wrap' => ['type' => ['boolean', 'null']],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generate($spec, 'ContractPresence');
        $resolver = new RequestPayloadValueResolver(
            $this->serializer(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        $attribute = new MapRequestPayload();
        $attribute->metadata = new ArgumentMetadata('order', $fqcn, false, false, null, false, [$attribute]);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $event = new ControllerArgumentsEvent(
            $kernel,
            static fn(): null => null,
            [$attribute],
            Request::create(
                '/',
                'PATCH',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                '{"first_name":"Jo","note":null}',
            ),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $resolver->onKernelControllerArguments($event);
        $dto = $event->getArguments()[0];

        $this->assertNull($dto->getNote());
        $this->assertNull($dto->getGiftWrap());
        $this->assertTrue($dto->isNoteProvided(), 'sent as null');
        $this->assertFalse($dto->isGiftWrapProvided(), 'never sent');
    }

    /**
     * Runs the payload through the real value resolver and returns the HTTP status it produced, or
     * null when the argument resolved cleanly.
     *
     * @param array<string, mixed> $serializationContext
     */
    private function resolvePayload(string $namespace, string $json, array $serializationContext): ?int
    {
        $fqcn = $this->generate(self::orderSpec(), $namespace);
        $resolver = new RequestPayloadValueResolver(
            $this->serializer(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        $attribute = new MapRequestPayload(serializationContext: $serializationContext);
        $attribute->metadata = new ArgumentMetadata('order', $fqcn, false, false, null, false, [$attribute]);

        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        $event = new ControllerArgumentsEvent(
            $kernel,
            static fn(): null => null,
            [$attribute],
            Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json),
            HttpKernelInterface::MAIN_REQUEST,
        );

        try {
            $resolver->onKernelControllerArguments($event);
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode();
        }

        return null;
    }

    private function serializer(bool $phpDoc = true, bool $dateTime = true): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $extractors = $phpDoc ? [new PhpDocExtractor(), new ReflectionExtractor()] : [new ReflectionExtractor()];

        $normalizers = [new BackedEnumNormalizer()];
        if ($dateTime) {
            $normalizers[] = new DateTimeNormalizer();
        }
        $normalizers[] = new ObjectNormalizer(
            $classMetadataFactory,
            new MetadataAwareNameConverter($classMetadataFactory),
            null,
            new PropertyInfoExtractor([], $extractors),
        );
        $normalizers[] = new ArrayDenormalizer();

        return new Serializer($normalizers, [new JsonEncoder()]);
    }

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generate(array $spec, string $namespace, string $className = 'Order'): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, 'symfony');

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
        $fqcn = $namespace . '\\' . $className;

        return $fqcn;
    }
}
