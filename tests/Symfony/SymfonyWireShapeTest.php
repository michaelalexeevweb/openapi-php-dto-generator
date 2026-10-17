<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\BackedEnumValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Exception\ValidationFailedException;
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
     * A route value is a controller argument of its own, and an inline `enum` on it still gets its
     * class, so Symfony resolves and checks the argument itself.
     */
    public function testAnInlineEnumOnARouteParameterKeepsItsClass(): void
    {
        $target = $this->outputDirectory . '/WireRoute';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/shapes/{shapeKind}' => [
                    'delete' => [
                        'parameters' => [[
                            'name' => 'shapeKind',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string', 'enum' => ['circle', 'square']],
                        ]],
                        'responses' => ['204' => ['description' => 'gone']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireRoute', 'symfony');
        $listed = scandir($target);
        $this->assertIsArray($listed);
        $this->assertContains('ShapesDeletePathParamsShapeKind.php', $listed);
        require_once $target . '/ShapesDeletePathParamsShapeKind.php';

        $request = Request::create('/shapes/square');
        $request->attributes->set('shapeKind', 'square');
        $argument = new ArgumentMetadata('shapeKind', 'WireRoute\ShapesDeletePathParamsShapeKind', false, false, null);
        $resolved = (new BackedEnumValueResolver())->resolve($request, $argument);
        $this->assertSame('square', $resolved[0]->value);
    }

    /**
     * `create()` builds the DTO in one expression; an optional argument left out, or passed as null,
     * is simply not set.
     */
    public function testCreateBuildsTheDtoInOneExpression(): void
    {
        $namespace = 'WireCreate';
        $this->generate($namespace);
        $widget = $namespace . '\Widget';
        $gadget = $namespace . '\Gadget';

        $dto = $widget::create(beta: 'b', delta: null, alpha: 'a', part: $gadget::create(tint: 'azure'));

        $this->assertTrue($dto->isAlphaProvided());
        $this->assertFalse($dto->isGammaProvided());
        $this->assertSame(
            '{"alpha":"a","beta":"b","gamma":null,"delta":null,"part":{"tint":"azure"},"size":3}',
            $this->serializer()->serialize($dto, 'json'),
        );
    }

    /**
     * A date is a DateTimeImmutable to the code that reads it and the schema's string on the wire: a
     * date stays a date and a date-time keeps its precision, in a property and in a map alike.
     */
    public function testDatesReadAsObjectsAndLeaveAsTheSchemaWritesThem(): void
    {
        $namespace = 'WireDates';
        $this->generate($namespace);
        $gadget = $namespace . '\Gadget';

        $json = '{"tint":null,"madeOn":"2026-03-10","seen":{"first":"2026-03-10T12:00:00.123456+03:00","last":"2026-03-11T08:00:00+00:00"}}';
        $dto = $this->serializer()->deserialize($json, $gadget, 'json');

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->getMadeOn());
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->getSeen()['first']);
        $this->assertSame($json, $this->serializer()->serialize($dto, 'json'));
    }

    /**
     * `create()` names its local in camelCase, and a property already called `dto` does not shadow it.
     * A class with no constraint imports no Assert.
     */
    public function testCreateKeepsItsLocalApartFromThePropertyNames(): void
    {
        $target = $this->outputDirectory . '/WireLocal';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Parcel' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'dto' => ['type' => 'string'],
                            'builtDto' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireLocal', 'symfony');
        $source = (string)file_get_contents($target . '/Parcel.php');
        $this->assertStringContainsString('$builtDto2 = new self(id: $id);', $source);
        $this->assertStringNotContainsString('Constraints as Assert', $source);

        require_once $target . '/Parcel.php';
        $parcel = \WireLocal\Parcel::create(id: 1, dto: 'a', builtDto: 'b');
        $this->assertSame(['a', 'b'], [$parcel->getDto(), $parcel->getBuiltDto()]);
    }

    /**
     * `#[MapQueryString]` casts a single query value but leaves the items of a list as strings; the
     * DTO reads them itself, and an item that is not of the type still fails at its index.
     */
    public function testAQueryListIsReadItemByItem(): void
    {
        $target = $this->outputDirectory . '/WireList';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/gadgets' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'counts', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                            ['name' => 'weights', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'number']]],
                            ['name' => 'flags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'boolean']]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireList', 'symfony');
        require_once $target . '/GadgetsGetQueryParams.php';
        $fqcn = \WireList\GadgetsGetQueryParams::class;

        $resolved = $this->resolveQuery($fqcn, '/gadgets?counts[]=1&counts[]=-22&weights[]=1.5&weights[]=2&flags[]=true&flags[]=0');
        $this->assertSame([1, -22], $resolved->getCounts());
        $this->assertSame([1.5, 2.0], $resolved->getWeights());
        $this->assertSame([true, false], $resolved->getFlags());

        try {
            $this->resolveQuery($fqcn, '/gadgets?counts[]=1&counts[]=x');
            $this->fail('a non-numeric item must not pass');
        } catch (HttpException $exception) {
            $failure = $exception->getPrevious();
            $this->assertInstanceOf(ValidationFailedException::class, $failure);
            // Symfony 8 refuses the item in the serializer already, Symfony 7 leaves it to the DTO's
            // check: the wording differs, the item it is pinned to does not.
            $this->assertSame('counts[1]', $failure->getViolations()->get(0)->getPropertyPath());
        }
    }

    /**
     * A format is one sentence wherever the value sits: a plain property and a list item are both
     * checked by the callback, as in the runtime mode. A plain one used to get `Assert\Uuid` and its
     * `This is not a valid UUID.`.
     */
    public function testAFormatIsWordedAlikeOnAPropertyAndAnItem(): void
    {
        $target = $this->outputDirectory . '/WireFormat';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Ticket' => [
                        'type' => 'object',
                        'required' => ['code', 'codes'],
                        'properties' => [
                            'code' => ['type' => 'string', 'format' => 'uuid'],
                            'codes' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                            'mail' => ['type' => ['string', 'null'], 'format' => 'email'],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireFormat', 'symfony');
        $source = (string)file_get_contents($target . '/Ticket.php');
        $this->assertStringNotContainsString('Assert\Uuid', $source);
        $this->assertStringNotContainsString('Assert\Email', $source);
        require_once $target . '/Ticket.php';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertCount(0, $validator->validate(\WireFormat\Ticket::create(code: $uuid, codes: [$uuid], mail: null)));

        $messages = [];
        foreach ($validator->validate(\WireFormat\Ticket::create(code: 'x', codes: [$uuid, 'x'], mail: 'nope')) as $violation) {
            $messages[$violation->getPropertyPath()] = (string)$violation->getMessage();
        }
        $this->assertSame([
            'code' => 'field "code" must match format uuid.',
            'codes[1]' => 'field "codes"[1] must match format uuid.',
            'mail' => 'field "mail" must match format email.',
        ], $messages);

        $this->assertCount(1, $validator->validate(\WireFormat\Ticket::create(code: '', codes: [])), 'an empty string is one mistake');
    }

    /**
     * A query list binds in the form its `style` and `explode` name as well as PHP's bracket form: the
     * DTO takes the string through its constructor and splits it there. An item that is not of the
     * type is still one violation at its index.
     */
    public function testAQueryListBindsInTheFormItsStyleNames(): void
    {
        $target = $this->outputDirectory . '/WireStyle';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/levers' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'sizes', 'in' => 'query', 'required' => true, 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
                            ['name' => 'marks', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'boolean']]],
                            ['name' => 'labels', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'spans', 'in' => 'query', 'style' => 'spaceDelimited', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'number']]],
                            ['name' => 'codes', 'in' => 'query', 'style' => 'pipeDelimited', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'tints', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tint']]],
                            ['name' => 'tiers', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tier']]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Tint' => ['type' => 'string', 'enum' => ['amber', 'teal']],
                    'Tier' => ['type' => 'integer', 'enum' => [1, 2]],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireStyle', 'symfony');
        foreach (['Tint', 'Tier', 'LeversGetQueryParams'] as $class) {
            require_once $target . '/' . $class . '.php';
        }
        $fqcn = \WireStyle\LeversGetQueryParams::class;

        $joined = $this->resolveQuery($fqcn, '/levers?sizes=1,22&marks=true,0&labels=a,b&spans=1.5%202&codes=x|y&tints=amber,teal&tiers=2,1');
        $this->assertSame([1, 22], $joined->getSizes());
        $this->assertSame([true, false], $joined->getMarks());
        $this->assertSame(['a,b'], $joined->getLabels(), 'exploded: a lone value is a list of one');
        $this->assertSame([1.5, 2.0], $joined->getSpans());
        $this->assertSame(['x', 'y'], $joined->getCodes());
        $this->assertSame([\WireStyle\Tint::AMBER, \WireStyle\Tint::TEAL], $joined->getTints());
        $this->assertSame([\WireStyle\Tier::VALUE_2, \WireStyle\Tier::VALUE_1], $joined->getTiers());
        $this->assertTrue($joined->isTintsProvided());

        $bracketed = $this->resolveQuery($fqcn, '/levers?sizes[]=3&labels[]=a&labels[]=b&tints[]=teal&tiers[]=2');
        $this->assertSame([3], $bracketed->getSizes());
        $this->assertSame(['a', 'b'], $bracketed->getLabels());
        $this->assertSame([\WireStyle\Tint::TEAL], $bracketed->getTints());
        $this->assertSame([\WireStyle\Tier::VALUE_2], $bracketed->getTiers());
        $this->assertNull($bracketed->getMarks());
        $this->assertFalse($bracketed->isMarksProvided());

        $this->assertSame([7], $this->resolveQuery($fqcn, '/levers?sizes=7')->getSizes());

        $refusals = [
            '/levers?sizes=1,x' => 'sizes[1]',
            '/levers?sizes=1,0' => 'sizes[1]',
            '/levers?sizes=1&tints=amber,grey' => 'tints[1]',
            '/levers?sizes=1&tiers=1,9' => 'tiers[1]',
        ];
        foreach ($refusals as $uri => $path) {
            try {
                $this->resolveQuery($fqcn, $uri);
                $this->fail($uri . ' must not pass');
            } catch (HttpException $exception) {
                $failure = $exception->getPrevious();
                $this->assertInstanceOf(ValidationFailedException::class, $failure);
                $this->assertCount(1, $failure->getViolations(), $uri . ': ' . $failure->getMessage());
                $this->assertSame($path, $failure->getViolations()->get(0)->getPropertyPath(), $uri);
            }
        }
    }

    /**
     * An item of the wrong type is one mistake, so it is one message: a bound on the items is not
     * also asked about a value that is not a number at all. A number out of the bound still fails.
     */
    public function testAnItemOfTheWrongTypeIsReportedOnce(): void
    {
        $target = $this->outputDirectory . '/WireItemOnce';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/sprockets' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'sizes', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Sprocket' => [
                        'type' => 'object',
                        'required' => ['sizes', 'labels', 'weights'],
                        'properties' => [
                            'sizes' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                            'labels' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
                            'weights' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'maximum' => 10]],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireItemOnce', 'symfony');
        require_once $target . '/SprocketsGetQueryParams.php';
        require_once $target . '/Sprocket.php';

        foreach (['/sprockets?sizes[]=2&sizes[]=x' => 'sizes[1]', '/sprockets?sizes[]=2&sizes[]=0' => 'sizes[1]'] as $uri => $path) {
            try {
                $this->resolveQuery(\WireItemOnce\SprocketsGetQueryParams::class, $uri);
                $this->fail($uri . ' must not pass');
            } catch (HttpException $exception) {
                $failure = $exception->getPrevious();
                $this->assertInstanceOf(ValidationFailedException::class, $failure);
                $this->assertCount(1, $failure->getViolations(), $uri . ': ' . $failure->getMessage());
                $this->assertSame($path, $failure->getViolations()->get(0)->getPropertyPath(), $uri);
            }
        }

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $cases = [
            '{"sizes":["x"],"labels":["ab"],"weights":{}}' => 'sizes[0]',
            '{"sizes":[0],"labels":["ab"],"weights":{}}' => 'sizes[0]',
            '{"sizes":[1],"labels":[5],"weights":{}}' => 'labels[0]',
            '{"sizes":[1],"labels":["a"],"weights":{}}' => 'labels[0]',
            '{"sizes":[1],"labels":["ab"],"weights":{"a":"heavy"}}' => 'weights',
            '{"sizes":[1],"labels":["ab"],"weights":{"a":11}}' => 'weights',
        ];
        foreach ($cases as $json => $path) {
            try {
                $sprocket = $this->serializer()->deserialize($json, \WireItemOnce\Sprocket::class, 'json');
            } catch (NotNormalizableValueException $exception) {
                // Symfony 8 refuses an item of the wrong type in the serializer already — one
                // refusal, and validation never runs. Symfony 7 leaves it to the DTO's check.
                $this->assertStringStartsWith($path, (string)$exception->getPath(), $json);

                continue;
            }
            $violations = $validator->validate($sprocket);
            $this->assertCount(1, $violations, $json . ': ' . $violations);
            $this->assertStringStartsWith($path, $violations->get(0)->getPropertyPath(), $json);
        }
    }

    /**
     * Null comes in only where the schema allows it. A required nullable property still has to be
     * present — the serializer would pass null for a missing typed nullable argument — and an optional
     * one that may not be null refuses an explicit null and an empty query value alike.
     */
    public function testNullIsTakenOnlyWhereTheSchemaAllowsIt(): void
    {
        $target = $this->outputDirectory . '/WireNull';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/ratings' => [
                    'get' => [
                        'parameters' => [['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]]],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Rating' => [
                        'type' => 'object',
                        'required' => ['score', 'phantom'],
                        'properties' => [
                            'score' => ['type' => ['integer', 'null']],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        $generator = new GenerateDtoCommand();
        $generator->generateFromArray($spec, $target, 'WireNull', 'symfony');
        $this->assertCount(1, array_filter(
            $generator->getGenerationWarnings(),
            static fn(string $warning): bool => str_contains($warning, '"Rating" lists "phantom" in `required`'),
        ));
        require_once $target . '/Rating.php';
        require_once $target . '/RatingsGetQueryParams.php';

        $rating = $this->serializer()->deserialize('{"score":null}', \WireNull\Rating::class, 'json');
        $this->assertNull($rating->getScore());

        foreach (['{}' => MissingConstructorArgumentsException::class, '{"score":1,"note":null}' => NotNormalizableValueException::class] as $json => $refusal) {
            try {
                $this->serializer()->deserialize($json, \WireNull\Rating::class, 'json');
                $this->fail($json . ' must not pass');
            } catch (MissingConstructorArgumentsException | NotNormalizableValueException $exception) {
                $this->assertInstanceOf($refusal, $exception, $json);
            }
        }

        try {
            $this->resolveQuery(\WireNull\RatingsGetQueryParams::class, '/ratings?limit=');
            $this->fail('an empty query value must not pass as "not given"');
        } catch (HttpException $exception) {
            $failure = $exception->getPrevious();
            $this->assertInstanceOf(ValidationFailedException::class, $failure);
            $this->assertSame('limit', $failure->getViolations()->get(0)->getPropertyPath());
        }
    }

    /**
     * A required property's schema default is there for the code that builds the DTO, never for a
     * payload: `create()` fills it, the constructor the serializer calls still demands it.
     */
    public function testARequiredDefaultFillsCreateButNotAPayload(): void
    {
        $target = $this->outputDirectory . '/WireReceipt';
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Receipt' => [
                        'type' => 'object',
                        'required' => ['status', 'id'],
                        'properties' => [
                            'status' => ['type' => 'string', 'default' => 'done'],
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $target, 'WireReceipt', 'symfony');
        require_once $target . '/Receipt.php';

        $constructor = new ReflectionMethod(\WireReceipt\Receipt::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertFalse($parameter->isDefaultValueAvailable(), $parameter->getName());
        }

        $receipt = \WireReceipt\Receipt::create(id: 7);
        $this->assertSame('{"status":"done","id":7}', $this->serializer()->serialize($receipt, 'json'));
        $this->assertSame('held', \WireReceipt\Receipt::create(id: 7, status: 'held')->getStatus());

        $this->expectException(MissingConstructorArgumentsException::class);
        $this->serializer()->deserialize('{"id":7}', \WireReceipt\Receipt::class, 'json');
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
                        'properties' => [
                            'tint' => ['type' => ['string', 'null']],
                            'madeOn' => ['type' => 'string', 'format' => 'date'],
                            'seen' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
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
