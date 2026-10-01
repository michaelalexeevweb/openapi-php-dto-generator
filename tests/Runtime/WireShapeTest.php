<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * A JSON value keeps its JSON kind through runtime hydration: an array is not an object, `{}` is not
 * `[]`, a string is not a number — whatever source carried it.
 */
final class WireShapeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-wire-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAJsonArrayIsNotAnObjectForADtoItem(): void
    {
        $namespace = $this->generate(schemas: ['Thing' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ], 'Probe' => [
            'type' => 'object',
            'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Thing']]],
        ]]);
        $deserializer = new DtoDeserializer();

        $this->assertRejected(static fn(): mixed => $deserializer->deserializeValue(data: [1, 2], type: $namespace . '\Thing'));
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(
            request: self::jsonRequest('{"items":[[1,2]]}'),
            dtoClass: $namespace . '\Probe',
        ));

        $accepted = $deserializer->deserialize(request: self::jsonRequest('{"items":[{"name":"a"},{}]}'), dtoClass: $namespace . '\Probe');
        self::assertCount(2, $accepted->getItems());
    }

    public function testAJsonContentParameterIsCastAsJson(): void
    {
        $json = static fn(array $schema): array => ['application/json' => ['schema' => $schema]];
        $namespace = $this->generateSpec(spec: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => ['/widgets' => ['get' => [
                'parameters' => [
                    ['name' => 'n', 'in' => 'query', 'content' => $json(['type' => 'integer'])],
                    ['name' => 'list', 'in' => 'query', 'content' => $json(['type' => 'array', 'items' => ['type' => 'integer']])],
                    ['name' => 'map', 'in' => 'query', 'content' => $json(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])],
                    ['name' => 'X-Size', 'in' => 'header', 'content' => $json(['type' => 'integer'])],
                ],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
        ]);
        $files = glob($this->root . '/' . $namespace . '/*QueryParams.php') ?: [];
        self::assertCount(1, $files);
        $class = $namespace . '\\' . basename($files[0], '.php');
        $deserializer = new DtoDeserializer();
        $query = static fn(array $query, array $headers = []): Request => Request::create(
            uri: '/widgets?' . http_build_query($query),
            server: array_combine(
                keys: array_map(static fn(string $name): string => 'HTTP_' . strtoupper(str_replace('-', '_', $name)), array_keys($headers)),
                values: $headers,
            ),
        );

        $dto = $deserializer->deserialize(request: $query(['n' => '42', 'list' => '[1,2]', 'map' => '{"a":1}'], ['X-Size' => '3']), dtoClass: $class);
        self::assertSame(42, $dto->getN());
        self::assertSame([1, 2], $dto->getList());
        self::assertSame(['a' => 1], $dto->getMap());
        self::assertSame(3, $dto->getXSize());
        self::assertTrue($dto->isNInQuery());

        // The same values the body refuses: a JSON string for an integer, an object for a list,
        // a list for a map.
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: $query(['n' => '"42"']), dtoClass: $class));
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: $query(['list' => '{"0":1,"1":2}']), dtoClass: $class));
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: $query(['map' => '[1,2]']), dtoClass: $class));
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: $query([], ['X-Size' => '"3"']), dtoClass: $class));
    }

    public function testAnObjectParameterKeepsItsKeysInSimpleAndFormStyle(): void
    {
        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];
        $namespace = $this->generateSpec(spec: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => ['/widgets/{p}' => ['get' => [
                'parameters' => [
                    ['name' => 'q', 'in' => 'query', 'style' => 'form', 'explode' => false, 'schema' => $map],
                    ['name' => 'X-Plain', 'in' => 'header', 'schema' => $map],
                    ['name' => 'X-Pairs', 'in' => 'header', 'explode' => true, 'schema' => $map],
                    ['name' => 'p', 'in' => 'path', 'required' => true, 'schema' => $map],
                ],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
        ]);
        $deserializer = new DtoDeserializer();
        $object = ['role' => 'admin', 'name' => 'Rex'];

        $class = $this->parameterClass($namespace, 'QueryParams');
        $request = static function (string $query): Request {
            $request = Request::create(
                uri: '/widgets/role,admin?' . $query,
                server: ['HTTP_X_PLAIN' => 'role,admin,name,Rex', 'HTTP_X_PAIRS' => 'role=admin,name=Rex'],
            );
            $request->attributes->set('p', 'role,admin');

            return $request;
        };

        $dto = $deserializer->deserialize(request: $request('q=role,admin,name,Rex'), dtoClass: $class);
        self::assertSame($object, $dto->getQ());
        self::assertSame($object, $dto->getXPlain());
        self::assertSame($object, $dto->getXPairs());
        self::assertSame(['role' => 'admin'], $dto->getP());

        // An odd token count is not an object.
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: $request('q=role,admin,name'), dtoClass: $class));
    }

    public function testASchemalessValueKeepsItsJsonKind(): void
    {
        $namespace = $this->generate(schemas: ['Probe' => [
            'type' => 'object',
            'properties' => ['anything' => [], 'picked' => ['enum' => [1, 'a']]],
        ]]);
        $deserializer = new DtoDeserializer();
        $normalizer = new DtoNormalizer();
        $roundTrip = static fn(string $json): string => $normalizer->toJson(
            dto: $deserializer->deserialize(request: self::jsonRequest($json), dtoClass: $namespace . '\Probe'),
        );

        foreach (['{"anything":{}}', '{"anything":{"0":"x"}}', '{"anything":[]}', '{"anything":null}', '{"anything":{"a":[{}]}}'] as $json) {
            self::assertSame($json, $roundTrip($json));
        }

        // Null passes the missing `type`, and is still held to what the schema does assert.
        $this->assertRejected(static fn(): mixed => $roundTrip('{"picked":null}'));
        self::assertSame('{"picked":"a"}', $roundTrip('{"picked":"a"}'));
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('nullableNodeSpellings')]
    public function testANullableDtoTwoContainersDownAcceptsNull(array $node): void
    {
        $list = static fn(array $inner): array => ['type' => 'array', 'items' => $inner];
        $map = static fn(array $inner): array => ['type' => 'object', 'additionalProperties' => $inner];
        $namespace = $this->generate(schemas: [
            'Node' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            'Probe' => ['type' => 'object', 'properties' => [
                'listList' => $list($list($node)),
                'listMap' => $list($map($node)),
                'mapList' => $map($list($node)),
                'mapMap' => $map($map($node)),
            ]],
        ]);
        $deserializer = new DtoDeserializer();
        $dto = $deserializer->deserialize(
            request: self::jsonRequest('{"listList":[[null,{"name":"a"}]],"listMap":[{"k":null}],"mapList":{"k":[null]},"mapMap":{"k":{"j":null}}}'),
            dtoClass: $namespace . '\Probe',
        );
        self::assertNull($dto->getListList()[0][0]);
        self::assertSame('a', $dto->getListList()[0][1]->getName());
        self::assertNull($dto->getListMap()[0]['k']);
        self::assertNull($dto->getMapList()['k'][0]);
        self::assertNull($dto->getMapMap()['k']['j']);

        // One container down as well: the items are DTOs, not the decoded stdClass.
        $flat = $this->generate(schemas: [
            'Node' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Probe' => ['type' => 'object', 'properties' => ['list' => $list($node)]],
        ]);
        $items = $deserializer->deserialize(request: self::jsonRequest('{"list":[null,{"name":"b"}]}'), dtoClass: $flat . '\Probe')->getList();
        self::assertNull($items[0]);
        self::assertSame('b', $items[1]->getName());

        // Not nullable, the same null is still refused.
        $strict = $this->generate(schemas: [
            'Node' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Probe' => ['type' => 'object', 'properties' => ['listList' => $list($list(['$ref' => '#/components/schemas/Node']))]],
        ]);
        $this->assertRejected(static fn(): mixed => $deserializer->deserialize(request: self::jsonRequest('{"listList":[[null]]}'), dtoClass: $strict . '\Probe'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function nullableNodeSpellings(): iterable
    {
        yield 'nullable sibling' => [['$ref' => '#/components/schemas/Node', 'nullable' => true]];
        yield 'anyOf with null' => [['anyOf' => [['$ref' => '#/components/schemas/Node'], ['type' => 'null']]]];
        yield 'oneOf with null' => [['oneOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/Node']]]];
    }

    /**
     * `allowReserved` keeps a literal `+` in the VALUE; the parameter NAME still decodes as in a form.
     */
    public function testAReservedParameterIsFoundUnderAFormEncodedName(): void
    {
        $namespace = $this->generateSpec(spec: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => ['/widgets' => ['get' => [
                'parameters' => [
                    ['name' => 'pet name', 'in' => 'query', 'allowReserved' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'pet tags', 'in' => 'query', 'allowReserved' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
        ]);
        $class = $this->parameterClass($namespace, 'QueryParams');
        $dto = (new DtoDeserializer())->deserialize(request: Request::create(uri: '/widgets?pet+name=a+b&pet%20tags=c+d&pet+tags=e'), dtoClass: $class);
        self::assertSame('a+b', $dto->getPetName());
        self::assertSame(['c+d', 'e'], $dto->getPetTags());
    }

    private function parameterClass(string $namespace, string $suffix): string
    {
        $files = glob($this->root . '/' . $namespace . '/*' . $suffix . '.php') ?: [];
        self::assertCount(1, $files, $suffix);

        return $namespace . '\\' . basename($files[0], '.php');
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     */
    private function generate(array $schemas): string
    {
        return $this->generateSpec(['openapi' => '3.1.0', 'info' => ['title' => 'T', 'version' => '1.0.0'], 'components' => ['schemas' => $schemas]]);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function generateSpec(array $spec): string
    {
        $namespace = 'Wire' . bin2hex(random_bytes(6));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray($spec, $directory, $namespace);
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require_once $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            }
        });

        return $namespace;
    }

    private static function jsonRequest(string $body, string $uri = '/'): Request
    {
        return Request::create(uri: $uri, method: 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private function assertRejected(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('The value must be rejected.');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
