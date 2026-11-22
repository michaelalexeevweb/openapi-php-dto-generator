<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAS 3.2 `style: cookie` — form-like, spelled the way RFC 6265 spells a `Cookie` header: pairs
 * separated by `; `, values sent as they are, never percent-decoded. Its `explode` defaults to `true`,
 * as for `form`, so an exploded list repeats the cookie name.
 *
 * Each case generates a 3.2 document and reads the request through the real deserializer.
 */
final class CookieStyleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-cookie-style-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * The allow-list of styles once left `cookie` out, and a valid 3.2 document generated nothing in
     * runtime mode while every other mode generated it.
     */
    #[DataProvider('modes')]
    public function testCookieStyleGeneratesInEveryMode(GenerationMode $mode): void
    {
        $directory = $this->root . '/' . $mode->tag();
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->document(parameters: [['name' => 'session', 'in' => 'cookie', 'required' => true, 'style' => 'cookie', 'schema' => ['type' => 'string']]]),
            outputDirectory: $directory,
            namespace: 'CookieStyle' . $mode->tag(),
            mode: $mode->value,
        );

        // Symfony mode binds no cookie parameter to a class — a cookie-only operation generates none —
        // so there the document only has to be accepted.
        $generatesClass = match ($mode) {
            GenerationMode::Symfony => false,
            GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData, GenerationMode::Yii3 => true,
        };
        $listed = is_dir($directory) ? (array)scandir($directory) : [];
        $this->assertSame(expected: $generatesClass, actual: in_array('WidgetsGetQueryParams.php', $listed, true));
    }

    public static function modes(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * Every style OpenAPI 3.0, 3.1 and 3.2 define, at a location it is defined for, still generates in
     * runtime mode — the allow-list refuses only what no version defines.
     */
    #[DataProvider('oasStyleProvider')]
    public function testEveryOpenApiStyleGeneratesInRuntimeMode(string $in, string $style): void
    {
        $class = $this->generate(parameters: [[
            'name' => 'alpha',
            'in' => $in,
            'required' => true,
            'style' => $style,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]], path: $in === 'path' ? '/widgets/{alpha}' : '/widgets');

        $this->assertTrue(class_exists($class));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oasStyleProvider(): iterable
    {
        yield 'matrix' => ['path', 'matrix'];
        yield 'label' => ['path', 'label'];
        yield 'simple' => ['header', 'simple'];
        yield 'form' => ['query', 'form'];
        yield 'spaceDelimited' => ['query', 'spaceDelimited'];
        yield 'pipeDelimited' => ['query', 'pipeDelimited'];
        yield 'deepObject' => ['query', 'deepObject'];
        yield 'cookie' => ['cookie', 'cookie'];
    }

    public function testAStyleNoOpenApiVersionDefinesIsStillRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parameter "alpha" has style "sparkle", which is not an OpenAPI parameter style');

        $this->generate(parameters: [['name' => 'alpha', 'in' => 'cookie', 'style' => 'sparkle', 'schema' => ['type' => 'string']]]);
    }

    /**
     * A `cookie`-style value is sent as it is: `a%20b` means those five characters. A `form`-style
     * cookie is percent-decoded as before.
     */
    public function testCookieStyleValueIsNotPercentDecoded(): void
    {
        $class = $this->generate(parameters: [
            ['name' => 'session', 'in' => 'cookie', 'style' => 'cookie', 'schema' => ['type' => 'string']],
            ['name' => 'theme', 'in' => 'cookie', 'style' => 'form', 'schema' => ['type' => 'string']],
            ['name' => 'plain', 'in' => 'cookie', 'schema' => ['type' => 'string']],
        ]);

        $dto = $this->read(class: $class, cookieHeader: 'session=a%20b+c; theme=dark%20blue; plain=x%2Fy');

        $this->assertSame(expected: 'a%20b+c', actual: $dto->getSession());
        $this->assertSame(expected: 'dark blue', actual: $dto->getTheme());
        $this->assertSame(expected: 'x/y', actual: $dto->getPlain());
    }

    /**
     * Without a `Cookie` header — a request built from a cookie bag — the bag's value is all there is.
     */
    public function testCookieStyleValueIsReadFromTheCookieBagWithoutAHeader(): void
    {
        $class = $this->generate(parameters: [
            ['name' => 'session', 'in' => 'cookie', 'required' => true, 'style' => 'cookie', 'schema' => ['type' => 'string']],
            ['name' => 'ids', 'in' => 'cookie', 'style' => 'cookie', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ]);

        $dto = (new DtoDeserializer())->deserialize(
            request: Request::create(uri: '/widgets', cookies: ['session' => 'abc', 'ids' => '7']),
            dtoClass: $class,
        );

        $this->assertSame(expected: 'abc', actual: $dto->getSession());
        $this->assertSame(expected: [7], actual: $dto->getIds());
    }

    /**
     * Lists: `explode: false` is one comma list, the default `explode: true` repeats the name —
     * `ids=1; ids=2`, which PHP's cookie bag folds to its first value.
     */
    public function testCookieStyleListsInBothExplodeSpellings(): void
    {
        $class = $this->generate(parameters: [
            ['name' => 'colors', 'in' => 'cookie', 'style' => 'cookie', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ['name' => 'ids', 'in' => 'cookie', 'style' => 'cookie', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ]);

        $dto = $this->read(class: $class, cookieHeader: 'colors=crimson,azure; ids=1; ids=2; ids=3');

        $this->assertSame(expected: ['crimson', 'azure'], actual: $dto->getColors());
        $this->assertSame(expected: [1, 2, 3], actual: $dto->getIds());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "ids');
        $this->read(class: $class, cookieHeader: 'ids=1; ids=two');
    }

    /**
     * A non-exploded object alternates key and value in one comma list, as `form` does.
     */
    public function testCookieStyleObjectWithoutExplode(): void
    {
        $class = $this->generate(parameters: [[
            'name' => 'shape',
            'in' => 'cookie',
            'style' => 'cookie',
            'explode' => false,
            'schema' => ['type' => 'object', 'properties' => ['sides' => ['type' => 'integer'], 'hue' => ['type' => 'string']]],
        ]]);

        $dto = $this->read(class: $class, cookieHeader: 'shape=sides,3,hue,red');

        $this->assertSame(expected: 3, actual: $dto->getShape()?->getSides());
        $this->assertSame(expected: 'red', actual: $dto->getShape()?->getHue());
    }

    /**
     * The neighbours keep their reading: a non-exploded `form` cookie list is still split, and a
     * `form` cookie scalar is still the first of two same-named cookies, decoded.
     */
    public function testFormStyleCookiesKeepTheirReading(): void
    {
        $class = $this->generate(parameters: [
            ['name' => 'colors', 'in' => 'cookie', 'style' => 'form', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ['name' => 'theme', 'in' => 'cookie', 'schema' => ['type' => 'string']],
        ]);

        $dto = $this->read(class: $class, cookieHeader: 'colors=crimson,azure; theme=dark%20blue; theme=light');

        $this->assertSame(expected: ['crimson', 'azure'], actual: $dto->getColors());
        $this->assertSame(expected: 'dark blue', actual: $dto->getTheme());
    }

    /**
     * The request PHP builds from a `Cookie` header: the header itself, and `$_COOKIE` beside it —
     * names and values form-decoded, the first of two same-named cookies kept.
     */
    private function read(string $class, string $cookieHeader): object
    {
        $cookies = [];
        foreach (explode('; ', $cookieHeader) as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            $cookies[urldecode($name)] ??= urldecode($value);
        }

        return (new DtoDeserializer())->deserialize(
            request: Request::create(uri: '/widgets', cookies: $cookies, server: ['HTTP_COOKIE' => $cookieHeader]),
            dtoClass: $class,
        );
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    private function generate(array $parameters, string $path = '/widgets'): string
    {
        $namespace = 'CookieStyle' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->document(parameters: $parameters, path: $path),
            outputDirectory: $directory,
            namespace: $namespace,
        );
        foreach ((array)scandir($directory) as $file) {
            if (is_string($file) && str_ends_with($file, '.php')) {
                require_once $directory . '/' . $file;
            }
        }
        $classes = array_values(array_filter(
            array: (array)scandir($directory),
            callback: static fn(mixed $file): bool => is_string($file) && str_ends_with($file, 'GetQueryParams.php'),
        ));
        $this->assertCount(expectedCount: 1, haystack: $classes);

        return $namespace . '\\' . substr((string)$classes[0], 0, -4);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @return array<string, mixed>
     */
    private function document(array $parameters, string $path = '/widgets'): array
    {
        return [
            'openapi' => '3.2.0',
            'info' => ['title' => 'Cookies', 'version' => '1.0.0'],
            'paths' => [$path => ['get' => ['operationId' => 'listWidgets', 'parameters' => $parameters, 'responses' => ['200' => ['description' => 'ok']]]]],
        ];
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
