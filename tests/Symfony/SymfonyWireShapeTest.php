<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * What a Symfony-mode DTO looks like on the wire when it goes through the stock serializer and
 * argument resolvers, with nothing registered by the application: keys in schema order, a property
 * that was never set left out, a null the schema allows kept, and a query DTO `#[MapQueryString]`
 * can actually bind.
 */
final class SymfonyWireShapeTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-symfony-wire';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    /**
     * The serializer writes keys in property declaration order once a class carries an #[Ignore],
     * and every class with an optional property does. Required properties used to be promoted and so
     * declared after every optional one.
     */
    public function testKeysFollowTheSchemaOrder(): void
    {
        $namespace = 'WireOrder';
        $this->generate($namespace);
        $widget = $namespace . '\Widget';
        $gadget = $namespace . '\Gadget';

        $dto = new $widget(beta: 'b', delta: null);
        $dto->setAlpha('a');
        $dto->setGamma('g');
        $dto->setPart(new $gadget(tint: 'azure'));

        $this->assertSame(
            '{"alpha":"a","beta":"b","gamma":"g","delta":null,"part":{"tint":"azure"},"size":3}',
            $this->serializer()->serialize($dto, 'json'),
        );
    }

    /**
     * Null on an optional property the schema does not let be null can only mean "never set", so it
     * is left out. A null the schema allows stays — under a parent that skips nulls too, and under a
     * caller that asks to skip them everywhere.
     */
    public function testANeverSetPropertyIsLeftOutAndAnAllowedNullIsKept(): void
    {
        $namespace = 'WireNulls';
        $this->generate($namespace);
        $widget = $namespace . '\Widget';
        $gadget = $namespace . '\Gadget';

        $bare = new $widget(beta: 'b', delta: null);
        // gamma is optional AND nullable: unset and explicit null cannot be told apart without a
        // normalizer of the application's own, so it is written as the null the schema allows.
        // size is written with its default, which is its value.
        $this->assertSame('{"beta":"b","gamma":null,"delta":null,"size":3}', $this->serializer()->serialize($bare, 'json'));

        $nested = new $widget(beta: 'b', delta: null);
        $nested->setPart(new $gadget(tint: null));
        $this->assertSame(
            '{"beta":"b","gamma":null,"delta":null,"part":{"tint":null},"size":3}',
            $this->serializer()->serialize($nested, 'json'),
        );
        $this->assertSame(
            '{"beta":"b","gamma":null,"delta":null,"part":{"tint":null},"size":3}',
            $this->serializer()->serialize($nested, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]),
        );
    }

    /**
     * A default fills an optional property, so it never holds null unless the schema allows one —
     * and its PHP type says so.
     */
    public function testAnOptionalPropertyWithADefaultIsNotNullable(): void
    {
        $namespace = 'WireDefault';
        $this->generate($namespace);

        $size = new ReflectionProperty($namespace . '\Widget', 'size');
        $type = $size->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertFalse($type->allowsNull());
    }

    /**
     * A required property's PHP type refuses null before the validator runs, so NotNull on it never
     * fired; only a type that admits null keeps it.
     */
    public function testARequiredPropertyCarriesNoDeadNotNull(): void
    {
        $namespace = 'WireNotNull';
        $this->generate($namespace);

        $source = (string)file_get_contents($this->outputDirectory . '/' . $namespace . '/Widget.php');
        $this->assertStringNotContainsString('#[Assert\NotNull]', $source);
    }

    /**
     * `#[MapQueryString]` reads the query string alone, so a path parameter in the DTO failed every
     * request; the route value reaches the controller as an argument of its own.
     */
    public function testTheQueryDtoBindsFromTheQueryStringAlone(): void
    {
        $namespace = 'WireQuery';
        $this->generate($namespace);
        $fqcn = $namespace . '\WidgetsGetQueryParams';
        $this->assertFalse(property_exists($fqcn, 'widgetId'), 'the path parameter is not part of it');

        $resolved = $this->resolveQuery($fqcn, '/widgets/7?colour=azure');
        $this->assertSame('azure', $resolved->getColour()->value);

        // An empty query string is resolved differently across Symfony 7.4 patch releases (older ones
        // refuse a non-nullable argument outright), so the default is read off the class instead.
        $defaulted = new $fqcn();
        $this->assertSame('crimson', $defaulted->getColour()->value);
        $this->assertFalse($defaulted->isColourProvided());
    }

    public function testDtoGeneratorDirectoryIsIgnoredOutsideRuntimeMode(): void
    {
        $specFile = $this->outputDirectory . '/spec.json';
        file_put_contents($specFile, (string)json_encode(self::spec()));
        $commonDirectory = $this->outputDirectory . '/Common';

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => $specFile,
            '--directory' => $this->outputDirectory . '/Cli',
            '--namespace' => 'WireCli',
            '--attributes' => 'symfony',
            '--dto-generator-directory' => $commonDirectory,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--dto-generator-directory is ignored', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($commonDirectory);
    }

    /**
     * DTOs shared through one directory cannot be in two modes: replacing them names the clash.
     */
    public function testReplacingAnotherModesDtosIsReported(): void
    {
        $specFile = $this->outputDirectory . '/spec.json';
        file_put_contents($specFile, (string)json_encode(self::spec()));
        $directory = $this->outputDirectory . '/Shared';

        $run = function (string $mode) use ($specFile, $directory): string {
            $tester = new CommandTester(new GenerateDtoCommand());
            $this->assertSame(0, $tester->execute([
                '--file' => $specFile,
                '--directory' => $directory,
                '--namespace' => 'WireShared',
                '--attributes' => $mode,
            ]));

            return $tester->getDisplay();
        };

        $run('runtime');
        $this->assertStringNotContainsString('being replaced', $run('runtime'), 'same mode again is no clash');
        $this->assertStringContainsString('--attributes=runtime; they are being replaced by symfony', $run('symfony'));
    }

    private function resolveQuery(string $fqcn, string $uri): object
    {
        $resolver = new RequestPayloadValueResolver(
            serializer: $this->serializer(),
            validator: Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
        $attribute = new MapQueryString();
        $attribute->metadata = new ArgumentMetadata('query', $fqcn, false, false, null, false, [$attribute]);
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
            request: Request::create($uri),
            requestType: HttpKernelInterface::MAIN_REQUEST,
        );

        $resolver->onKernelControllerArguments($event);
        $resolved = $event->getArguments()[0];
        $this->assertInstanceOf($fqcn, $resolved);

        return $resolved;
    }

    private function generate(string $namespace): void
    {
        $target = $this->outputDirectory . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(self::spec(), $target, $namespace, 'symfony');
        foreach (['WidgetsGetQueryParamsColour', 'WidgetsGetQueryParams', 'Gadget', 'Widget'] as $class) {
            require_once $target . '/' . $class . '.php';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/widgets/{widgetId}' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'widgetId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                            [
                                'name' => 'colour',
                                'in' => 'query',
                                'schema' => ['type' => 'string', 'enum' => ['crimson', 'azure'], 'default' => 'crimson'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Gadget' => [
                        'type' => 'object',
                        'required' => ['tint'],
                        'properties' => ['tint' => ['type' => ['string', 'null']]],
                    ],
                    'Widget' => [
                        'type' => 'object',
                        'required' => ['beta', 'delta'],
                        'properties' => [
                            'alpha' => ['type' => 'string'],
                            'beta' => ['type' => 'string'],
                            'gamma' => ['type' => ['string', 'null']],
                            'delta' => ['type' => ['string', 'null']],
                            'part' => ['$ref' => '#/components/schemas/Gadget'],
                            'size' => ['type' => 'integer', 'default' => 3],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new Serializer(
            [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                    propertyTypeExtractor: new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]),
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
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
