<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

/**
 * The allOf pattern of polymorphism: an object base carries the discriminator, the variants `allOf`
 * it. Flattened Symfony DTOs cannot extend the base, so it becomes the interface they implement, and
 * a property typed with the base takes any variant — through the stock serializer.
 */
final class SymfonyAllOfDiscriminatorTest extends TestCase
{
    private const string NAMESPACE = 'AllOfPick';

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-symfony-allof-discriminator';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray(self::spec(), $this->outputDirectory, self::NAMESPACE, 'symfony');
        foreach (['ShapeKind', 'Shape', 'CircleShape', 'SquareShape', 'Holder'] as $class) {
            require_once $this->outputDirectory . '/' . $class . '.php';
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->outputDirectory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->outputDirectory . '/' . $entry);
            }
        }
        @rmdir($this->outputDirectory);
    }

    public function testTheBaseIsTheInterfaceTheVariantsImplement(): void
    {
        $base = new ReflectionClass(self::NAMESPACE . '\Shape');
        $this->assertTrue($base->isInterface());
        $this->assertTrue($base->hasMethod('getKind'), 'the base\'s own property is readable through it');
        $this->assertTrue((new ReflectionClass(self::NAMESPACE . '\CircleShape'))->implementsInterface($base->getName()));
        $this->assertTrue((new ReflectionClass(self::NAMESPACE . '\SquareShape'))->implementsInterface($base->getName()));
    }

    public function testAPropertyTypedWithTheBaseTakesAnyVariant(): void
    {
        $json = '{"payload":{"kind":"circle","radius":3}}';
        $holder = $this->serializer()->deserialize($json, self::NAMESPACE . '\Holder', 'json');

        $payload = $holder->getPayload();
        $this->assertInstanceOf(self::NAMESPACE . '\CircleShape', $payload);
        $this->assertSame(3, $payload->getRadius());
        $this->assertSame($json, $this->serializer()->serialize($holder, 'json'));

        $square = $this->serializer()->deserialize('{"payload":{"kind":"square","side":2}}', self::NAMESPACE . '\Holder', 'json');
        $this->assertInstanceOf(self::NAMESPACE . '\SquareShape', $square->getPayload());
    }

    /**
     * `#[MapRequestPayload]` on an argument typed with the base picks the variant itself, so a
     * discriminated body needs no mapper of the application's own. A wrong field of the variant comes
     * back as a violation at its path; a discriminator value outside the mapping is refused by the
     * serializer before the resolver collects anything — the application maps that exception itself.
     */
    public function testMapRequestPayloadPicksTheVariant(): void
    {
        $this->assertInstanceOf(self::NAMESPACE . '\CircleShape', $this->resolve('{"kind":"circle","radius":3}'));

        try {
            $this->resolve('{"kind":"circle","radius":"x"}');
            $this->fail('a wrong field must not pass');
        } catch (HttpException $exception) {
            $failure = $exception->getPrevious();
            $this->assertInstanceOf(ValidationFailedException::class, $failure);
            $this->assertSame('radius', $failure->getViolations()->get(0)->getPropertyPath());
        }

        $this->expectException(NotNormalizableValueException::class);
        $this->resolve('{"kind":"oval","radius":3}');
    }

    private function resolve(string $body): object
    {
        $resolver = new RequestPayloadValueResolver(
            serializer: $this->serializer(),
            validator: Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
        $attribute = new MapRequestPayload();
        $attribute->metadata = new ArgumentMetadata('shape', self::NAMESPACE . '\Shape', false, false, null, false, [$attribute]);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $event = new ControllerArgumentsEvent(
            kernel: $kernel,
            controller: static fn(): null => null,
            arguments: [$attribute],
            request: Request::create(uri: '/', method: 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body),
            requestType: HttpKernelInterface::MAIN_REQUEST,
        );
        $resolver->onKernelControllerArguments($event);

        return $event->getArguments()[0];
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new Serializer(
            [
                new BackedEnumNormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                    propertyTypeExtractor: new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]),
                    classDiscriminatorResolver: new ClassDiscriminatorFromClassMetadata($classMetadataFactory),
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['payload'],
                        'properties' => ['payload' => ['$ref' => '#/components/schemas/Shape']],
                    ],
                    'Shape' => [
                        'type' => 'object',
                        'required' => ['kind'],
                        'properties' => ['kind' => ['type' => 'string']],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => [
                                'circle' => '#/components/schemas/CircleShape',
                                'square' => '#/components/schemas/SquareShape',
                            ],
                        ],
                    ],
                    'CircleShape' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Shape'],
                            ['type' => 'object', 'required' => ['radius'], 'properties' => ['radius' => ['type' => 'integer']]],
                        ],
                    ],
                    'SquareShape' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Shape'],
                            ['type' => 'object', 'required' => ['side'], 'properties' => ['side' => ['type' => 'integer']]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
