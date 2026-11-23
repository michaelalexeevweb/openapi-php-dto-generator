<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A schema that combines a union with something that makes it a CLASS must generate classes PHP can load.
 *
 * `allOf` beside `anyOf` made the schema a class (the allOf branches merged into properties) AND a union
 * interface of the same name: every `anyOf` member was emitted `implements` the class, and loading it
 * ended in `ProbeFAnyOf1 cannot implement ProbeF - it is not an interface`. That is a fatal error at
 * class load — no `catch` in the application sees it — so it is measured here in a CHILD process, where
 * it can fail this test instead of ending the run.
 *
 * Every combination of the shapes that decide class-versus-interface is loaded in every mode, inline and
 * as a component, and nested one level down: whatever a mode makes of them, its files must link.
 */
final class CompositionClassesLoadTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/opg-composition-load-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    /**
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('compositions')]
    public function testTheGeneratedClassesLoad(string $key, array $schemas): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $namespace = 'CompositionLoad' . $mode->tag() . substr(md5($key), 0, 10);
            $target = $this->outputDirectory . '/' . $namespace;
            mkdir($target, 0o755, true);

            (new GenerateDtoCommand())->generateFromArray(
                openApi: [
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'T', 'version' => '1.0.0'],
                    'components' => ['schemas' => $schemas],
                ],
                outputDirectory: $target,
                namespace: $namespace,
                mode: $mode->value,
            );

            $this->assertSame(
                expected: '',
                actual: $this->loadFailure($target),
                message: sprintf('%s mode: the classes generated for "%s" must load', $mode->value, $key),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function compositions(): iterable
    {
        $alpha = ['properties' => ['alpha' => ['type' => 'string']]];
        $beta = ['properties' => ['beta' => ['type' => 'integer']]];
        $gamma = ['properties' => ['gamma' => ['type' => 'boolean']]];
        $shapes = [
            'allOf beside anyOf' => ['allOf' => [$alpha], 'anyOf' => [$beta]],
            'allOf beside oneOf' => ['allOf' => [$alpha], 'oneOf' => [$beta]],
            'allOf beside oneOf and anyOf' => ['allOf' => [$alpha], 'oneOf' => [$beta], 'anyOf' => [$gamma]],
            'oneOf beside anyOf' => ['oneOf' => [$alpha], 'anyOf' => [$beta]],
            'properties beside anyOf' => [...$alpha, 'anyOf' => [$beta, $gamma]],
            'allOf beside a closed anyOf branch' => [
                'allOf' => [['properties' => ['foo' => true]]],
                'anyOf' => [['properties' => ['bar' => true], 'unevaluatedProperties' => false]],
            ],
        ];

        foreach ($shapes as $name => $shape) {
            yield 'inline ' . $name => ['inline ' . $name, self::probe($shape)];
            yield 'nested ' . $name => ['nested ' . $name, self::probe(['anyOf' => [$shape, $gamma]])];
            yield 'listed ' . $name => ['listed ' . $name, self::probe(['type' => 'array', 'items' => $shape])];
        }

        // The same shapes as components, their union members `$ref`s: here the member is a component
        // of its own and was emitted `implements Shape` against a class named Shape.
        $components = [
            'Circle' => ['type' => 'object', 'properties' => ['radius' => ['type' => 'number']]],
            'Square' => ['type' => 'object', 'properties' => ['side' => ['type' => 'number']]],
        ];
        $circle = ['$ref' => '#/components/schemas/Circle'];
        $square = ['$ref' => '#/components/schemas/Square'];
        $referenced = [
            'allOf beside anyOf' => ['allOf' => [$alpha], 'anyOf' => [$circle, $square]],
            'allOf beside oneOf' => ['allOf' => [$alpha], 'oneOf' => [$circle, $square]],
            'oneOf beside anyOf' => ['oneOf' => [$circle], 'anyOf' => [$square]],
        ];
        foreach ($referenced as $name => $shape) {
            yield 'component ' . $name => ['component ' . $name, [
                'Shape' => $shape,
                'Holder' => ['type' => 'object', 'properties' => ['shape' => ['$ref' => '#/components/schemas/Shape']]],
                ...$components,
            ]];
        }
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private static function probe(array $propertySchema): array
    {
        return ['Probe' => ['type' => 'object', 'required' => ['f'], 'properties' => ['f' => $propertySchema]]];
    }

    /**
     * Loads every generated file in a child process with an autoloader over the directory, so the order
     * the files are listed in does not matter, and returns the first line the child wrote to stderr —
     * the fatal error — or '' when everything linked.
     */
    private function loadFailure(string $directory): string
    {
        $script = 'require $argv[1];'
            . '$directory = $argv[2];'
            . 'spl_autoload_register(static function (string $class) use ($directory): void {'
            . ' $file = $directory . "/" . substr((string)strrchr($class, "\\\"), 1) . ".php";'
            . ' if (is_file($file)) { require_once $file; }'
            . '});'
            . 'foreach (glob($directory . "/*.php") ?: [] as $file) { require_once $file; }'
            . 'fwrite(STDOUT, "loaded");';
        $arguments = [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-d',
            'log_errors=0',
            '-r',
            $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $directory,
        ];
        $process = proc_open(
            command: implode(' ', array_map(escapeshellarg(...), $arguments)),
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        $errors = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output === 'loaded') {
            return '';
        }

        return trim(strtok($errors, "\n") ?: 'the child process died without a message');
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
