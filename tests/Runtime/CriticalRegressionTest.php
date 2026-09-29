<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GeneratedFilePublisher;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class CriticalRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-critical-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }

    private function spec(array $properties): array
    {
        return ['openapi' => '3.1.0', 'components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => $properties],
        ]]];
    }

    private function generate(array $spec, string $mode = 'runtime'): string
    {
        $namespace = 'Critical' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray($spec, $directory, $namespace, $mode);
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require_once $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            }
        });
        return $namespace . '\Probe';
    }

    public function testCleaningOutputDoesNotFollowDirectoryOrDanglingSymlinks(): void
    {
        mkdir($this->root . '/outside');
        mkdir($this->root . '/out');
        file_put_contents($this->root . '/outside/keep.txt', 'unrelated');
        symlink($this->root . '/outside', $this->root . '/out/link');
        symlink($this->root . '/missing', $this->root . '/out/dangling');
        (new GenerateDtoCommand())->generateFromArray($this->spec([]), $this->root . '/out', 'Safe');
        self::assertSame('unrelated', file_get_contents($this->root . '/outside/keep.txt'));
        self::assertFalse(is_link($this->root . '/out/link'));
        self::assertFalse(is_link($this->root . '/out/dangling'));
        self::assertContains('Probe.php', scandir($this->root . '/out'));
    }

    public function testOutputRootSymlinkIsRejectedWithoutChangingItsTarget(): void
    {
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/keep.txt', 'unrelated');
        symlink($this->root . '/outside', $this->root . '/out');
        try {
            (new GenerateDtoCommand())->generateFromArray($this->spec([]), $this->root . '/out', 'Safe');
            self::fail('A linked output root must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('symlink', $error->getMessage());
        }
        self::assertSame('unrelated', file_get_contents($this->root . '/outside/keep.txt'));
    }

    /**
     * The output directory itself is kept: only its contents change. Renaming the directory needed a
     * writable parent, reset its permissions, and cannot work on a mount point.
     */
    public function testPublishingKeepsTheOutputDirectoryItself(): void
    {
        mkdir($this->root . '/parent');
        mkdir($this->root . '/parent/out', 0o700);
        chmod($this->root . '/parent/out', 0o700);
        file_put_contents($this->root . '/parent/out/old.php', 'old');
        $inode = fileinode($this->root . '/parent/out');
        chmod($this->root . '/parent', 0o555);
        try {
            (new GenerateDtoCommand())->generateFromArray($this->spec([]), $this->root . '/parent/out', 'Safe');
        } finally {
            chmod($this->root . '/parent', 0o755);
        }
        clearstatcache();
        self::assertSame($inode, fileinode($this->root . '/parent/out'));
        self::assertSame(0o700, fileperms($this->root . '/parent/out') & 0o777);
        self::assertSame(['Probe.php'], array_values(array_diff(scandir($this->root . '/parent/out'), ['.', '..'])));
        self::assertSame(['out'], array_values(array_diff(scandir($this->root . '/parent'), ['.', '..'])));
    }

    public function testFailedExternalWritePreservesTheEntirePreviousGeneration(): void
    {
        mkdir($this->root . '/out');
        mkdir($this->root . '/external');
        mkdir($this->root . '/external/Item.php');
        file_put_contents($this->root . '/out/old.php', 'old');
        file_put_contents($this->root . '/external/unrelated.txt', 'keep');
        try {
            (new GeneratedFilePublisher())->publish(files: [
                $this->root . '/out/new.php' => 'new',
                $this->root . '/external/Item.php' => 'item',
            ], ownedDirectories: [$this->root . '/out']);
            self::fail('A directory cannot be published as a file.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('destination', $error->getMessage());
        }
        self::assertSame(['old.php'], array_values(array_diff(scandir($this->root . '/out'), ['.', '..'])));
        self::assertSame('old', file_get_contents($this->root . '/out/old.php'));
        self::assertSame('keep', file_get_contents($this->root . '/external/unrelated.txt'));
        self::assertSame([], glob($this->root . '/.openapi-*'));
    }

    public function testMappedOutputsPreserveUnrelatedFilesAndReplaceOnlyTheLink(): void
    {
        mkdir($this->root . '/external');
        file_put_contents($this->root . '/external/unrelated.txt', 'keep');
        file_put_contents($this->root . '/target.txt', 'target');
        symlink($this->root . '/target.txt', $this->root . '/external/Item.php');
        (new GeneratedFilePublisher())->publish(files: [
            $this->root . '/out/Probe.php' => 'probe',
            $this->root . '/external/Item.php' => 'new',
        ], ownedDirectories: [$this->root . '/out']);
        self::assertSame('keep', file_get_contents($this->root . '/external/unrelated.txt'));
        self::assertSame('target', file_get_contents($this->root . '/target.txt'));
        self::assertSame('new', file_get_contents($this->root . '/external/Item.php'));
        self::assertFalse(is_link($this->root . '/external/Item.php'));
    }

    public function testFailureDuringPublicationRollsBackEveryOutput(): void
    {
        mkdir($this->root . '/out');
        mkdir($this->root . '/external');
        file_put_contents($this->root . '/out/old.php', 'old');
        file_put_contents($this->root . '/external/First.php', 'old-first');
        file_put_contents($this->root . '/external/Second.php', 'old-second');
        $process = proc_open(
            command: [PHP_BINARY, __DIR__ . '/../fixtures/publisher-rename-failure.php', $this->root],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process), $errors);
        self::assertStringContainsString('Cannot move generated output', $output);
        self::assertSame('old', file_get_contents($this->root . '/out/old.php'));
        self::assertFileDoesNotExist($this->root . '/out/new.php');
        self::assertSame('old-first', file_get_contents($this->root . '/external/First.php'));
        self::assertSame('old-second', file_get_contents($this->root . '/external/Second.php'));
        self::assertSame([], glob($this->root . '/.openapi-*'));
        self::assertSame([], glob($this->root . '/external/.openapi-*'));
    }

    public function testCliIncludesCommonServicesInTheSameTransaction(): void
    {
        mkdir($this->root . '/out');
        file_put_contents($this->root . '/out/old.php', 'old');
        file_put_contents($this->root . '/blocked', 'not a directory');
        file_put_contents($this->root . '/spec.json', json_encode($this->spec([]), JSON_THROW_ON_ERROR));
        $command = new CommandTester(new GenerateDtoCommand());
        $exit = $command->execute(input: [
            '--file' => $this->root . '/spec.json',
            '--directory' => $this->root . '/out',
            '--namespace' => 'Safe',
            '--dto-generator-directory' => $this->root . '/blocked',
            '--dto-generator-namespace' => 'Safe\Common',
        ]);
        self::assertSame(1, $exit);
        self::assertSame('old', file_get_contents($this->root . '/out/old.php'));
        self::assertFileDoesNotExist($this->root . '/out/Probe.php');
        self::assertSame('not a directory', file_get_contents($this->root . '/blocked'));
    }

    public function testRepeatedDeserializationDoesNotShareMutablePayloadObjects(): void
    {
        $class = $this->generate(spec: $this->spec(properties: ['entries' => ['type' => 'array', 'items' => []]]));
        $makeRequest = static fn(): Request => Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"entries":[{"label":"original"}]}',
        );
        $deserializer = new DtoDeserializer();
        $request = $makeRequest();
        $first = $deserializer->deserialize($request, $class);
        $first->getEntries()[0]->label = 'changed';
        foreach ([$request, $makeRequest()] as $nextRequest) {
            $next = $deserializer->deserialize($nextRequest, $class);
            self::assertSame('original', $next->getEntries()[0]->label);
            self::assertNotSame($first->getEntries()[0], $next->getEntries()[0]);
        }
    }

    #[DataProvider('modes')]
    public function testSchemaProseCannotTerminateGeneratedComments(string $mode): void
    {
        $class = $this->generate(spec: $this->spec(properties: ['value' => [
            'type' => 'string',
            'description' => 'A literal */ appears in documentation.',
            'example' => 'Example */ text',
            'default' => 'wire */ value',
        ]]), mode: $mode);
        self::assertTrue(class_exists($class));
        if ($mode === 'runtime') {
            self::assertSame('wire */ value', (new $class())->getValue());
        }
    }

    public static function modes(): iterable
    {
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('laravelModes')]
    public function testLaravelRejectsAmbiguousPropertyPathsBeforeReplacingOutput(string $mode): void
    {
        $out = $this->root . '/out';
        mkdir($out);
        file_put_contents($out . '/old.php', 'old');
        $named = static fn(string $name): array => [
            'declared' => ['properties' => [$name => ['type' => 'string']]],
            'required' => ['properties' => ['x' => ['type' => 'string']], 'required' => [$name]],
            'dependent' => ['properties' => ['x' => ['type' => 'string']], 'dependentRequired' => ['x' => [$name]]],
            'trigger' => ['properties' => ['x' => ['type' => 'string']], 'dependentRequired' => [$name => ['x']]],
        ];
        foreach (['a.b', 'a*b'] as $name) {
            foreach ($named($name) as $where => $schema) {
                try {
                    (new GenerateDtoCommand())->generateFromArray(
                        openApi: ['openapi' => '3.1.0', 'components' => ['schemas' => ['Probe' => ['type' => 'object'] + $schema]]],
                        outputDirectory: $out,
                        namespace: 'Safe',
                        mode: $mode,
                    );
                    self::fail('Ambiguous validation path was accepted: ' . $where);
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('cannot safely represent property name', $error->getMessage(), $where);
                }
                self::assertSame('old', file_get_contents($out . '/old.php'));
            }
            try {
                (new GenerateDtoCommand())->generateFromArray($this->spec([$name => ['type' => 'string']]), $out, 'Safe', $mode);
                self::fail('Ambiguous validation path was accepted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('cannot safely represent property name', $error->getMessage());
            }
            self::assertSame('old', file_get_contents($out . '/old.php'));
        }
    }

    #[DataProvider('laravelModes')]
    public function testLaravelValidatesBlankValuesIncludingNestedArrayElements(string $mode): void
    {
        $class = $this->generate(spec: $this->spec(properties: [
            'unconstrained' => ['type' => 'string'],
            'nullable' => ['type' => ['string', 'null'], 'minLength' => 3],
            'count' => ['type' => 'integer'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'code' => ['type' => 'string', 'pattern' => '^[A-Z]+$'],
            'labels' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 4]],
        ]), mode: $mode);
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        foreach (['', '   '] as $blank) {
            foreach (['count', 'email', 'code', 'labels'] as $field) {
                $value = $field === 'labels' ? [$blank] : $blank;
                $validator = $factory->make([$field => $value], $class::rules());
                self::assertFalse($validator->passes(), $mode . ': ' . $field);
            }
        }
        $valid = ['unconstrained' => '', 'nullable' => null, 'labels' => ['abcd']];
        self::assertTrue($factory->make($valid, $class::rules())->passes());
        self::assertFalse($factory->make(['nullable' => ''], $class::rules())->passes());
        if ($mode === 'laravel-data') {
            LaravelDataContainer::boot();
            self::assertInstanceOf($class, $class::validateAndCreate($valid));
            $this->expectException(\Illuminate\Validation\ValidationException::class);
            $class::validateAndCreate(['count' => '']);
        }
    }

    public static function laravelModes(): iterable
    {
        yield ['laravel'];
        yield ['laravel-data'];
    }

    public function testRecursiveLaravelValidationRejectsTheDepthLimit(): void
    {
        $spec = $this->spec(properties: [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Probe']],
        ]);
        $class = $this->generate($spec, 'laravel');
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $payload = ['id' => 0];
        for ($depth = 0; $depth < 130; $depth++) {
            $payload = ['id' => 1, 'children' => [$payload]];
        }
        $validator = $factory->make($payload, $class::rules());
        $class::withValidator($validator);
        self::assertFalse($validator->passes());
        self::assertStringContainsString('maximum validation depth', implode(' ', $validator->errors()->all()));
        $valid = $factory->make(['id' => 1, 'children' => [['id' => 2]]], $class::rules());
        $class::withValidator($valid);
        self::assertTrue($valid->passes());
    }

    public function testNegationCannotTurnAnIncompleteValidationIntoSuccess(): void
    {
        $schema = ['const' => 'forbidden'];
        for ($depth = 0; $depth < 260; $depth++) {
            $schema = ['not' => $schema];
        }
        $class = $this->generate(spec: $this->spec(properties: ['value' => $schema]), mode: 'laravel');
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $validator = $factory->make(['value' => 'otherwise valid'], $class::rules());
        $class::withValidator($validator);
        self::assertFalse($validator->passes());
        self::assertStringContainsString('maximum validation depth', implode(' ', $validator->errors()->all()));
    }
}
