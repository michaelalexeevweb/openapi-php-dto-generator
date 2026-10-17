<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
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
 * `--symfony-path-params` writes `<Operation>PathParams`, and the value resolver README.symfony.md
 * shows fills it. The resolver is READ OUT OF THE README and run, so the example cannot drift from what
 * the generated class needs.
 */
final class SymfonyPathParamsTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-symfony-path';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*/*.php') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->outputDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            @rmdir($directory);
        }
        @rmdir($this->outputDirectory);
    }

    public function testThePathDtoIsWrittenOnlyWhenAskedFor(): void
    {
        $this->generate('PathOff', false);
        $this->assertFileDoesNotExist($this->outputDirectory . '/PathOff/HangarsBaysGetPathParams.php');
        $this->assertFileExists($this->outputDirectory . '/PathOff/HangarsBaysGetPathParamsKind.php', 'the inline enum stays');
        $this->assertFileExists($this->outputDirectory . '/PathOff/HangarsBaysGetQueryParams.php');

        $this->generate('PathOn', true);
        $this->assertFileExists($this->outputDirectory . '/PathOn/HangarsBaysGetPathParams.php');
        $this->assertFileExists($this->outputDirectory . '/PathOn/HangarsBaysDeletePathParams.php');
        $this->assertFileExists($this->outputDirectory . '/PathOn/HangarsBaysGetPathParamsKind.php');
        $query = (string)file_get_contents($this->outputDirectory . '/PathOn/HangarsBaysGetQueryParams.php');
        $this->assertStringNotContainsString('$hangarId', $query, 'the query DTO keeps the query alone');
    }

    public function testTheReadmeResolverFillsAndChecksIt(): void
    {
        $this->generate('PathBind', true);
        foreach (['HangarsBaysGetPathParamsKind', 'HangarsBaysGetPathParams', 'RacksGetPathParams'] as $class) {
            require_once $this->outputDirectory . '/PathBind/' . $class . '.php';
        }
        $resolver = $this->readmeResolver();

        $path = $this->resolve($resolver, \PathBind\HangarsBaysGetPathParams::class, ['hangarId' => '0b6e2c52-8a7e-4f8c-9d2b-3f1d7a5c9e10', 'bayNo' => '12', 'kind' => 'wide']);
        $this->assertInstanceOf(\PathBind\HangarsBaysGetPathParams::class, $path);
        $this->assertSame('0b6e2c52-8a7e-4f8c-9d2b-3f1d7a5c9e10', $path->getHangarId());
        $this->assertSame(12, $path->getBayNo());
        $this->assertSame(\PathBind\HangarsBaysGetPathParamsKind::WIDE, $path->getKind());
        $rack = $this->resolve($resolver, \PathBind\RacksGetPathParams::class, ['trays' => '3,4']);
        $this->assertInstanceOf(\PathBind\RacksGetPathParams::class, $rack);
        $this->assertSame([3, 4], $rack->getTrays(), 'a `simple` list is comma-joined');

        $refusals = [
            'hangarId' => [\PathBind\HangarsBaysGetPathParams::class, ['hangarId' => 'nope', 'bayNo' => '12', 'kind' => 'wide']],
            'bayNo' => [\PathBind\HangarsBaysGetPathParams::class, ['hangarId' => '0b6e2c52-8a7e-4f8c-9d2b-3f1d7a5c9e10', 'bayNo' => '0', 'kind' => 'wide']],
            'kind' => [\PathBind\HangarsBaysGetPathParams::class, ['hangarId' => '0b6e2c52-8a7e-4f8c-9d2b-3f1d7a5c9e10', 'bayNo' => '12', 'kind' => 'tall']],
            'trays[1]' => [\PathBind\RacksGetPathParams::class, ['trays' => '3,x']],
        ];
        foreach ($refusals as $field => [$class, $routeParams]) {
            try {
                $this->resolve($resolver, $class, $routeParams);
                $this->fail($field . ' must not pass');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode(), $field);
                $failure = $exception->getPrevious();
                $this->assertInstanceOf(ValidationFailedException::class, $failure);
                $this->assertCount(1, $failure->getViolations(), $field . ': ' . $failure->getMessage());
                $this->assertSame($field, $failure->getViolations()->get(0)->getPropertyPath());
            }
        }
    }

    /**
     * @param class-string $class
     * @param array<string, string> $routeParams
     */
    private function resolve(ValueResolverInterface $resolver, string $class, array $routeParams): object
    {
        $attributeClass = 'ReadmePathParams\MapPathParams';
        $attribute = new $attributeClass();
        $argument = new ArgumentMetadata('path', $class, false, false, null, false, [$attribute]);
        $request = Request::create('/');
        $request->attributes->set('_route_params', $routeParams);

        $resolved = [...$resolver->resolve($request, $argument)];
        $this->assertCount(1, $resolved);
        $this->assertIsObject($resolved[0]);

        return $resolved[0];
    }

    private function readmeResolver(): ValueResolverInterface
    {
        if (!class_exists('ReadmePathParams\PathParamsValueResolver', false)) {
            $readme = (string)file_get_contents(__DIR__ . '/../../README.symfony.md');
            $section = strstr($readme, '### Path parameters as a DTO');
            if ($section === false || preg_match('/```php\n(namespace App\\\Http;.*?)```/s', $section, $match) !== 1) {
                throw new RuntimeException('README.symfony.md no longer shows the path parameter resolver.');
            }
            eval(str_replace('namespace App\Http;', 'namespace ReadmePathParams;', $match[1]));
        }

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([
            new BackedEnumNormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]),
            ),
            new ArrayDenormalizer(),
        ]);
        $resolverClass = 'ReadmePathParams\PathParamsValueResolver';
        $resolver = new $resolverClass($serializer, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $this->assertInstanceOf(ValueResolverInterface::class, $resolver);

        return $resolver;
    }

    private function generate(string $namespace, bool $pathParams): void
    {
        $command = new GenerateDtoCommand();
        $command->symfonyPathParams = $pathParams;
        $command->generateFromArray(
            [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => [
                    '/racks/{trays}' => [
                        'get' => [
                            'parameters' => [
                                ['name' => 'trays', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                            ],
                            'responses' => ['200' => ['description' => 'ok']],
                        ],
                    ],
                    '/hangars/{hangarId}/bays/{bayNo}/{kind}' => [
                        'parameters' => [
                            ['name' => 'hangarId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ],
                        'get' => [
                            'parameters' => [
                                ['name' => 'bayNo', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                                ['name' => 'kind', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['wide', 'narrow']]],
                                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ],
                            'responses' => ['200' => ['description' => 'ok']],
                        ],
                        'delete' => ['responses' => ['204' => ['description' => 'ok']]],
                    ],
                ],
            ],
            $this->outputDirectory . '/' . $namespace,
            $namespace,
            'symfony',
        );
    }
}
