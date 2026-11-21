<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The runtime deserializer learns an item's class from the DTO's source, and the source carries the
 * document's own text — defaults, patterns, descriptions. That text must never decide which class a
 * request's values are handed to: a string default once added a line that read as a `use` import, and
 * the request's items were constructed as another class, with constructor arguments the request chose.
 */
final class DocumentTextChoosesNoClassTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-doctext-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        ConstructedFromRequest::$constructed = 0;
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testImportLineInAStringDefaultDoesNotRedirectItems(): void
    {
        $class = $this->generate(properties: [
            'label' => ['type' => 'string', 'default' => "red\nuse " . ConstructedFromRequest::class . " as Gear;\n"],
            'gears' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Gear']],
        ]);

        $dto = $this->deserialize(class: $class, body: '{"gears":[{"size":3}]}');

        $this->assertSame(0, ConstructedFromRequest::$constructed);
        $this->assertSame(3, $dto->getGears()[0]->getSize());
        $this->assertStringEndsWith('\Gear', $dto->getGears()[0]::class);
    }

    public function testClassNamedInADescriptionIsNotConstructed(): void
    {
        $class = $this->generate(properties: [
            'colors' => [
                'type' => 'array',
                'description' => 'Color names, see array<\\' . ConstructedFromRequest::class . '> notes.',
                'items' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(['red'], $this->deserialize(class: $class, body: '{"colors":["red"]}')->getColors());

        try {
            $this->deserialize(class: $class, body: '{"colors":[{"size":3}]}');
            $this->fail('An object item of a string list must be rejected.');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, ConstructedFromRequest::$constructed);
    }

    public function testGenericInADescriptionDoesNotDecideTheItemType(): void
    {
        $class = $this->generate(properties: [
            'colors' => [
                'type' => 'array',
                'description' => 'Plain color names, not an array<Gear> of gears.',
                'items' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(['red', 'blue'], $this->deserialize(class: $class, body: '{"colors":["red","blue"]}')->getColors());
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function generate(array $properties): string
    {
        $namespace = 'DocText' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.0',
                'info' => ['title' => 'Widgets', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [
                    'Gear' => ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]],
                    'Probe' => ['type' => 'object', 'properties' => $properties],
                ]],
            ],
            outputDirectory: $directory,
            namespace: $namespace,
        );
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require_once $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            }
        });

        return $namespace . '\Probe';
    }

    private function deserialize(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
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

/** A class that is not a generated DTO, counting how often a request managed to construct it. */
final class ConstructedFromRequest
{
    public static int $constructed = 0;

    public function __construct(mixed ...$arguments)
    {
        self::$constructed++;
    }
}
