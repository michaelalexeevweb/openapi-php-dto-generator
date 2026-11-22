<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use FilesystemIterator;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every string the generator copies out of a document, written as PHP that breaks out of whatever it
 * lands in, generated in every mode.
 *
 * A parameter `style` was once copied into a single-quoted literal unescaped, and `form'.touch(…).'`
 * then ran on every request the endpoint served. The cure is one rule — a template escapes a value
 * where it puts it in quotes, and nowhere else (see `TemplateEscapingTest`) — and this is the proof
 * from the other side: names, wire names, discriminator values and mapping keys, enum values,
 * defaults, examples, formats, patterns, descriptions, parameter, header and cookie names and `x-*`
 * values all carry the payload; every file must lint and load, nothing may run, and the values must
 * come back out of the loaded classes exactly as the document wrote them — escaped once, not twice.
 */
final class QuotedDocumentStringsTest extends TestCase
{
    private const string MARKER = 'PWNED';

    /**
     * A single quote, a backslash, `\'`, `?>`, `*\/`, `${x}`, three ways out of a literal or a comment
     * into a call that leaves a marker file, and a TRAILING backslash.
     */
    private const string PAYLOAD = "a'b\\c\\'d?>e*/f\${x}g'.touch(__DIR__.'/" . self::MARKER . "').'"
        . "h*/touch(__DIR__.'/" . self::MARKER . "');/*i{\${touch(__DIR__.'/" . self::MARKER . "')}}j\\";

    /**
     * The payload without `.` and `*`, for WIRE names: the Laravel modes refuse both in a property
     * name — their validator reads a rule key as a path — so a wire name keeps every other character.
     */
    private const string WIRE_PAYLOAD = "a'b\\c\\'d?>e/f\${x}g'touch(__DIR__'/" . self::MARKER . "')'"
        . "h/touch(__DIR__'/" . self::MARKER . "');/i{\${touch(__DIR__'/" . self::MARKER . "')}}j\\";

    /**
     * Run in a fresh PHP process: load every generated class, call each public static method a class
     * declares without arguments, and print every string reachable from the class — constants, enum
     * case values, property and parameter defaults, attribute arguments and those methods' results.
     */
    private const string LOADER = <<<'PHP'
        <?php
        declare(strict_types=1);
        require $argv[1];
        [, , $directory, $namespace] = $argv;
        spl_autoload_register(static function (string $class) use ($directory, $namespace): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });
        $strings = [];
        $walk = static function (mixed $value) use (&$walk, &$strings): void {
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }
            if (is_string($value)) {
                $strings[$value] = true;
            } elseif (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $strings[$key] = true;
                    }
                    $walk($item);
                }
            }
        };
        $unloadable = [];
        foreach (glob($directory . '/*.php') as $file) {
            $class = $namespace . '\\' . basename($file, '.php');
            try {
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                    $unloadable[basename($file)] = 'declares no class of its name';
                    continue;
                }
            } catch (Throwable $error) {
                $unloadable[basename($file)] = $error->getMessage();
                continue;
            }
            $reflection = new ReflectionClass($class);
            $walk($reflection->getConstants());
            if ($reflection->isEnum()) {
                $walk($class::cases());
            }
            $attributes = $reflection->getAttributes();
            foreach ($reflection->getProperties() as $property) {
                $attributes = [...$attributes, ...$property->getAttributes()];
                if ($property->hasDefaultValue()) {
                    $walk($property->getDefaultValue());
                }
            }
            foreach ($reflection->getMethods() as $method) {
                $attributes = [...$attributes, ...$method->getAttributes()];
                foreach ($method->getParameters() as $parameter) {
                    $attributes = [...$attributes, ...$parameter->getAttributes()];
                    if ($parameter->isDefaultValueAvailable()) {
                        $walk($parameter->getDefaultValue());
                    }
                }
                if ($method->isStatic() && $method->isPublic() && !$method->isAbstract()
                    && $method->getNumberOfRequiredParameters() === 0 && $method->getDeclaringClass()->getName() === $class) {
                    try {
                        $walk($method->invoke(null));
                    } catch (Throwable) {
                        // A method that needs a booted framework fails here; it has still been compiled
                        // and entered, which is what could run injected code.
                    }
                }
            }
            foreach ($attributes as $attribute) {
                $walk($attribute->getArguments());
            }
        }
        echo json_encode(['strings' => array_keys($strings), 'unloadable' => $unloadable], JSON_THROW_ON_ERROR);
        PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-quoted-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    #[DataProvider('modeProvider')]
    public function testEveryCopiedStringStaysData(GenerationMode $mode): void
    {
        $directory = $this->root . '/out';
        $namespace = 'Quoted' . $mode->tag();
        (new GenerateDtoCommand())->generateFromArray(
            openApi: self::document(),
            outputDirectory: $directory,
            namespace: $namespace,
            mode: $mode->value,
        );

        foreach ($this->phpFiles($directory) as $file) {
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $lint, $lintExit);
            $this->assertSame(expected: 0, actual: $lintExit, message: sprintf('%s mode: %s does not parse', $mode->value, basename($file)));
        }

        $loader = $this->root . '/loader.php';
        file_put_contents($loader, self::LOADER);
        $command = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($loader),
            escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php'),
            escapeshellarg($directory),
            escapeshellarg($namespace),
        );
        exec($command, $output, $exitCode);
        $this->assertSame(expected: 0, actual: $exitCode, message: implode("\n", $output));

        $this->assertSame(
            expected: [],
            actual: $this->markers(),
            message: sprintf('%s mode: a document string ran as code', $mode->value),
        );

        /** @var array{strings: list<string>, unloadable: array<string, string>} $loaded */
        $loaded = json_decode(json: implode("\n", $output), associative: true, flags: JSON_THROW_ON_ERROR);
        foreach ($loaded['unloadable'] as $file => $reason) {
            // A Laravel FormRequest extends `illuminate/foundation`, which this package does not
            // install; the file still lints above, and the DTO it validates for is loaded.
            $this->assertStringStartsWith(
                prefix: 'Class "Illuminate\Foundation\Http\FormRequest" not found',
                string: $reason,
                message: sprintf('%s mode: %s did not load', $mode->value, $file),
            );
        }

        foreach (self::surfacingValues(mode: $mode) as $what => $value) {
            $this->assertContains(
                needle: $value,
                haystack: $loaded['strings'],
                message: sprintf('%s mode: the %s does not come back out as the document wrote it', $mode->value, $what),
            );
        }
    }

    /**
     * @return iterable<string, array{GenerationMode}>
     */
    public static function modeProvider(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * The values a loaded class carries as strings in every mode, and the default where the mode keeps
     * it as a constant expression (yii3 and Laravel answer it from a getter body instead).
     *
     * @return array<string, string>
     */
    private static function surfacingValues(GenerationMode $mode): array
    {
        $values = [
            'wire name' => 'wire' . self::WIRE_PAYLOAD,
            'enum value' => 'enum' . self::PAYLOAD,
            'discriminator property name' => 'kind' . self::WIRE_PAYLOAD,
            'first discriminator value' => 'circle' . self::PAYLOAD,
            'second discriminator value' => 'square' . self::PAYLOAD,
            'query parameter name' => 'query' . self::WIRE_PAYLOAD,
        ];

        return match ($mode) {
            GenerationMode::Runtime, GenerationMode::Symfony, GenerationMode::LaravelData => $values + ['default' => 'default' . self::PAYLOAD],
            GenerationMode::Laravel, GenerationMode::Yii3 => $values,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        $text = static fn(string $where): string => $where . self::PAYLOAD;
        $wire = static fn(string $where): string => $where . self::WIRE_PAYLOAD;
        $widget = ['$ref' => '#/components/schemas/Widget'];

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $text('title'), 'version' => '1', 'description' => $text('info')],
            'paths' => ['/widgets/{shape}' => ['post' => [
                'operationId' => $text('operation'),
                'summary' => $text('summary'),
                'description' => $text('operation description'),
                'x-route-note' => $text('operation x-value'),
                'parameters' => [
                    ['name' => 'shape', 'in' => 'path', 'required' => true, 'description' => $text('path'), 'schema' => ['type' => 'string', 'example' => $text('path example')]],
                    ['name' => $wire('query'), 'in' => 'query', 'style' => 'form', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ['name' => $wire('header'), 'in' => 'header', 'schema' => ['type' => 'string', 'default' => $text('header default')]],
                    ['name' => $wire('cookie'), 'in' => 'cookie', 'schema' => ['type' => 'string', 'format' => $text('cookie format')]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $widget]]],
                'responses' => ['200' => ['description' => $text('response'), 'content' => ['application/json' => ['schema' => $widget]]]],
            ]]],
            'components' => ['schemas' => [
                'Widget' => [
                    'type' => 'object',
                    'title' => $text('widget title'),
                    'description' => $text('widget description'),
                    'x-widget-note' => $text('schema x-value'),
                    'required' => [$wire('wire')],
                    'properties' => [
                        $wire('wire') => [
                            'type' => 'string',
                            'description' => $text('property description'),
                            'example' => $text('example'),
                            'format' => $text('format'),
                            'x-property-note' => $text('property x-value'),
                        ],
                        'color' => ['type' => 'string', 'enum' => [$text('enum'), 'plain'], 'x-enum-descriptions' => [$text('enum description'), 'plain']],
                        'label' => ['type' => 'string', 'default' => $text('default'), 'pattern' => "^q'\\\\?\\'?>*/\\{x\\}\\\\$"],
                        'shape' => ['$ref' => '#/components/schemas/Shape'],
                    ],
                ],
                'Shape' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                    'discriminator' => [
                        'propertyName' => $wire('kind'),
                        'mapping' => [$text('circle') => '#/components/schemas/Circle', $text('square') => '#/components/schemas/Square'],
                    ],
                ],
                'Circle' => ['type' => 'object', 'required' => [$wire('kind')], 'properties' => [$wire('kind') => ['type' => 'string'], 'radius' => ['type' => 'number']]],
                'Square' => ['type' => 'object', 'required' => [$wire('kind')], 'properties' => [$wire('kind') => ['type' => 'string'], 'side' => ['type' => 'number']]],
                // A schema NAME carries it too; a class name is sanitized, and the `/` a JSON pointer
                // would need to escape is left out so the name stays a plain component key.
                str_replace(search: '/', replace: '', subject: $text('Gadget')) => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]],
            ]],
        ];
    }

    /**
     * Every marker file anywhere under the test root — the generated directory is `__DIR__` to the
     * payload, but a mis-escaped path could land it a level up.
     *
     * @return list<string>
     */
    private function markers(): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getFilename() === self::MARKER) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = glob($directory . '/*.php') ?: [];
        $this->assertNotSame(expected: [], actual: $files);

        return $files;
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
