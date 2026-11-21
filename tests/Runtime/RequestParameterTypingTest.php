<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request parameters a runtime DTO reads from the URL, headers, cookies and form bodies — where every
 * value arrives as a string and the deserializer, not the client, gives it its type.
 *
 * Each case generates a small document, builds the request the way a client sends it and drives it
 * through the real deserializer, then writes the DTO back out with the real normalizer.
 */
final class RequestParameterTypingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-params-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDeepObjectWithTypedPropertiesIsConverted(): void
    {
        $class = $this->generateParams([
            ['name' => 'rng', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['$ref' => '#/components/schemas/Range']],
            [
                'name' => 'deep',
                'in' => 'query',
                'style' => 'deepObject',
                'schema' => ['type' => 'object', 'properties' => ['lo' => ['type' => 'integer'], 'on' => ['type' => 'boolean']]],
            ],
        ]);

        $dto = $this->query(class: $class, queryString: 'rng[lo]=1&rng[tag]=x&deep[lo]=5&deep[on]=false');
        $this->assertSame(1, $dto->getRng()?->getLo());
        $this->assertSame('x', $dto->getRng()?->getTag());
        $this->assertSame(['lo' => 1, 'tag' => 'x'], $this->normalize($dto->getRng()));
        $this->assertSame(['lo' => 5, 'on' => false], $this->normalize($dto->getDeep()));

        // Converted, not waved through: a value that is no integer in any spelling is still refused.
        $this->assertQueryRejected(class: $class, queryString: 'rng[lo]=x', message: 'param "rng.lo" expects int');
        $this->assertQueryRejected(class: $class, queryString: 'deep[on]=maybe', message: 'param "deep.on" expects bool');
    }

    public function testDeepObjectMapsKeepTheirConversion(): void
    {
        $class = $this->generateParams([
            ['name' => 'tags', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
            ['name' => 'counts', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]],
        ]);

        $dto = $this->query(class: $class, queryString: 'tags[a]=1&counts[cats]=2');
        $this->assertSame(['a' => '1'], $dto->getTags());
        $this->assertSame(['cats' => 2], $dto->getCounts());
    }

    public function testFormObjectWithoutExplodeIsConverted(): void
    {
        $class = $this->generateParams([
            [
                'name' => 'colorObj',
                'in' => 'query',
                'style' => 'form',
                'explode' => false,
                'schema' => ['type' => 'object', 'properties' => ['r' => ['type' => 'integer'], 'g' => ['type' => 'integer']]],
            ],
        ]);

        $dto = $this->query(class: $class, queryString: 'colorObj=r,100,g,200');
        $this->assertSame(['r' => 100, 'g' => 200], $this->normalize($dto->getColorObj()));
    }

    public function testHeaderObjectIsConverted(): void
    {
        $class = $this->generateParams([
            [
                'name' => 'X-Obj',
                'in' => 'header',
                'explode' => true,
                'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'string']]],
            ],
        ]);

        $dto = $this->query(class: $class, queryString: '', headers: ['X-Obj' => 'a=1,b=x']);
        $this->assertSame(['a' => 1, 'b' => 'x'], $this->normalize($dto->getXObj()));
    }

    public function testPathObjectIsConverted(): void
    {
        $class = $this->generateParams(
            parameters: [
                [
                    'name' => 'spot',
                    'in' => 'path',
                    'required' => true,
                    'explode' => true,
                    'schema' => ['type' => 'object', 'properties' => ['r' => ['type' => 'integer'], 'g' => ['type' => 'integer']]],
                ],
            ],
            path: '/widgets/{spot}',
        );

        $dto = $this->query(class: $class, queryString: '', attributes: ['spot' => 'r=1,g=2']);
        $this->assertSame(['r' => 1, 'g' => 2], $this->normalize($dto->getSpot()));
    }

    public function testFormBodyNestedObjectsAreConverted(): void
    {
        $class = $this->generateComponents();

        $dto = $this->form(class: $class, body: 's[lo]=1&s[on]=true&sl[0][lo]=4&i=3');
        $this->assertSame(
            expected: ['i' => 3, 's' => ['lo' => 1, 'on' => true], 'sl' => [['lo' => 4]]],
            actual: (new DtoNormalizer())->toArray($dto),
        );
    }

    public function testJsonBodyNestedObjectsStayStrict(): void
    {
        $class = $this->generateComponents();

        // The same field written as a JSON string is a different value, not a different spelling:
        // JSON carries types, so the conversion a URL needs must not leak into a JSON body.
        $this->assertJsonRejected(class: $class, body: '{"s":{"lo":"1"}}', message: 'param "s.lo" expects int, got string');
        $this->assertJsonRejected(class: $class, body: '{"sl":[{"lo":"4"}]}', message: 'expects int, got string');
        $dto = $this->deserializeJson(class: $class, body: '{"s":{"lo":1,"on":true},"sl":[{"lo":4}]}');
        $this->assertSame(['s' => ['lo' => 1, 'on' => true], 'sl' => [['lo' => 4]]], (new DtoNormalizer())->toArray($dto));
    }

    public function testJsonContentQueryParameterStaysStrict(): void
    {
        $class = $this->generateParams([
            ['name' => 'rng', 'in' => 'query', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Range']]]],
        ]);

        $dto = $this->query(class: $class, queryString: 'rng=' . rawurlencode('{"lo":1,"tag":"x"}'));
        $this->assertSame(['lo' => 1, 'tag' => 'x'], $this->normalize($dto->getRng()));
        // Decoded, the value IS JSON — `"1"` is a string there, whatever the URL around it was.
        $this->assertQueryRejected(class: $class, queryString: 'rng=' . rawurlencode('{"lo":"1"}'), message: 'expects int, got string');
    }

    public function testQueryParameterNamesWithADotOrASpaceAreRead(): void
    {
        $class = $this->generateParams([
            ['name' => 'page.size', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'a b', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'filter.ids', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            ['name' => 'sel.rng', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['$ref' => '#/components/schemas/Range']],
            ['name' => 'plain', 'in' => 'query', 'schema' => ['type' => 'string']],
        ]);

        $dto = $this->query(class: $class, queryString: 'page.size=5&a%20b=x+y&filter.ids=1&filter.ids=2&sel.rng[lo]=3&plain=p+q');
        $this->assertSame(5, $dto->getPageSize());
        $this->assertSame('x y', $dto->getAB());
        $this->assertSame([1, 2], $dto->getFilterIds());
        $this->assertSame(3, $dto->getSelRng()?->getLo());
        $this->assertSame('p q', $dto->getPlain());

        // `a+b` is how a form spells the space in the name, the same as `%20`.
        $this->assertSame('z', $this->query(class: $class, queryString: 'page.size=1&a+b=z')->getAB());

        // PHP files `page.size` under `page_size`, but `page_size` is another name, not a spelling.
        $this->assertQueryRejected(class: $class, queryString: 'page_size=7', message: 'Required parameter "page.size" not found');
        $this->assertQueryRejected(class: $class, queryString: 'page.size=x', message: 'param "page.size" expects int');
    }

    public function testCookieNamesWithADotAreRead(): void
    {
        $class = $this->generateParams([
            ['name' => 'tok.id', 'in' => 'cookie', 'schema' => ['type' => 'integer']],
            ['name' => 'plain', 'in' => 'cookie', 'schema' => ['type' => 'integer']],
        ]);

        // What PHP hands over for `Cookie: tok.id=7; plain=8`: `$_COOKIE` is built by the same
        // parser as `$_GET`, so the dotted name arrives renamed, beside the header that still has it.
        $request = Request::create(
            uri: '/widgets',
            cookies: ['tok_id' => '7', 'plain' => '8'],
            server: ['HTTP_COOKIE' => 'tok.id=7; plain=8'],
        );
        $dto = (new DtoDeserializer())->deserialize($request, $class);
        $this->assertSame(7, $dto->getTokId());
        $this->assertSame(8, $dto->getPlain());
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    private function generateParams(array $parameters, string $path = '/widgets'): string
    {
        $namespace = $this->generate(paths: [
            $path => ['get' => ['operationId' => 'listWidgets', 'parameters' => $parameters, 'responses' => ['200' => ['description' => 'ok']]]],
        ]);
        $listed = scandir($this->root . '/' . $namespace);
        $this->assertIsArray($listed);
        $this->assertContains('WidgetsGetQueryParams.php', $listed);

        return $namespace . '\WidgetsGetQueryParams';
    }

    private function generateComponents(): string
    {
        return $this->generate(paths: []) . '\Widget';
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function generate(array $paths): string
    {
        $namespace = 'Params' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.3',
                'info' => ['title' => 'Params', 'version' => '1.0.0'],
                'paths' => $paths,
                'components' => ['schemas' => [
                    'Range' => ['type' => 'object', 'properties' => ['lo' => ['type' => 'integer'], 'tag' => ['type' => 'string']]],
                    'Shape' => ['type' => 'object', 'properties' => ['lo' => ['type' => 'integer'], 'on' => ['type' => 'boolean']]],
                    'Widget' => ['type' => 'object', 'properties' => [
                        'i' => ['type' => 'integer'],
                        's' => ['$ref' => '#/components/schemas/Shape'],
                        'sl' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']],
                    ]],
                ]],
            ],
            outputDirectory: $directory,
            namespace: $namespace,
        );
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $attributes
     */
    private function query(string $class, string $queryString, array $headers = [], array $attributes = []): mixed
    {
        $request = Request::create('/widgets?' . $queryString);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        foreach ($attributes as $name => $value) {
            $request->attributes->set($name, $value);
        }

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function form(string $class, string $body): mixed
    {
        parse_str($body, $fields);
        $request = Request::create(
            uri: '/',
            method: 'POST',
            parameters: $fields,
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function deserializeJson(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function assertQueryRejected(string $class, string $queryString, string $message): void
    {
        try {
            $this->query(class: $class, queryString: $queryString);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail(sprintf('?%s must be rejected.', $queryString));
    }

    private function assertJsonRejected(string $class, string $body, string $message): void
    {
        try {
            $this->deserializeJson(class: $class, body: $body);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail(sprintf('%s must be rejected.', $body));
    }

    private function normalize(?object $dto): mixed
    {
        $this->assertNotNull($dto);

        return (new DtoNormalizer())->toArray($dto);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
