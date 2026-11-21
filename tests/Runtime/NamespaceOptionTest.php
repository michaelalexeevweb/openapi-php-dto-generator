<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A namespace option is written into every generated file as `namespace …;` and `use …\…;`. It was only
 * trimmed, so `--namespace='Zoo;echo "x";//'` became a statement that ran whenever the class autoloaded,
 * and the run reported success. A value that is not a PHP namespace name is refused before anything is
 * written; the spellings that are one — a leading or trailing backslash included — still generate.
 */
final class NamespaceOptionTest extends TestCase
{
    private string $project;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->project = (string)realpath(sys_get_temp_dir()) . '/opg-namespace-' . bin2hex(random_bytes(8));
        mkdir($this->project . '/shared', 0o775, true);
        file_put_contents($this->project . '/spec.json', (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Zoo', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Widget' => ['type' => 'object', 'properties' => [
                    'color' => ['type' => 'string'],
                    'palette' => ['$ref' => 'shared/colors.json#/components/schemas/Palette'],
                ]],
            ]],
        ]));
        file_put_contents($this->project . '/shared/colors.json', (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Colors', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Palette' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]],
            ]],
        ]));
        $this->workingDirectory = (string)getcwd();
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->project);
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    #[DataProvider('refusedNamespaces')]
    public function testANamespaceThatIsNotOneIsRefusedAndNothingIsWritten(array $options, string $option): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute(input: [
            '--file' => 'spec.json',
            '--directory' => 'gen',
            ...$options,
        ]);

        $this->assertSame(1, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString($option, $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->project . '/gen');
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string}>
     */
    public static function refusedNamespaces(): iterable
    {
        yield 'a statement after the name' => [['--namespace' => 'Zoo;echo "x";//'], '--namespace'];
        yield 'a space inside' => [['--namespace' => 'Zoo\Gen Extra'], '--namespace'];
        yield 'an empty segment' => [['--namespace' => 'Zoo\\\Gen'], '--namespace'];
        yield 'a segment starting with a digit' => [['--namespace' => 'Zoo\9Gen'], '--namespace'];
        yield 'the services namespace' => [
            ['--namespace' => 'Zoo\Gen', '--dto-generator-directory' => 'gen/Common', '--dto-generator-namespace' => 'Svc;echo "x";//'],
            '--dto-generator-namespace',
        ];
        yield 'a referenced document namespace' => [
            ['--namespace' => 'Zoo\Gen', '--ref' => ['shared=gen/Shared=Zoo\Shared;echo "x";//']],
            '--ref',
        ];
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    #[DataProvider('acceptedNamespaces')]
    public function testANamespaceNameStillGenerates(array $options, string $expectedNamespace): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute(input: [
            '--file' => 'spec.json',
            '--directory' => 'gen',
            ...$options,
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString(
            needle: 'namespace ' . $expectedNamespace . ';',
            haystack: (string)file_get_contents($this->project . '/gen/Widget.php'),
        );
        foreach ($this->phpFilesBelow($this->project . '/gen') as $file) {
            $lintOutput = [];
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $lintOutput, $lintStatus);
            $this->assertSame(
                expected: 0,
                actual: $lintStatus,
                message: implode("\n", $lintOutput),
            );
        }
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string}>
     */
    public static function acceptedNamespaces(): iterable
    {
        yield 'plain' => [['--namespace' => 'Zoo\Gen'], 'Zoo\Gen'];
        yield 'leading and trailing backslash' => [['--namespace' => '\Zoo\Gen\\'], 'Zoo\Gen'];
        yield 'underscore and digits' => [['--namespace' => '_Zoo\V2'], '_Zoo\V2'];
        yield 'derived from the directory' => [[], 'Gen'];
        yield 'with a services namespace' => [
            ['--namespace' => 'Zoo\Gen', '--dto-generator-directory' => 'gen/Common', '--dto-generator-namespace' => 'Zoo\Gen\Common'],
            'Zoo\Gen',
        ];
        yield 'with a referenced document namespace' => [
            ['--namespace' => 'Zoo\Gen', '--ref' => ['shared=gen/Shared=Zoo\Gen\Shared']],
            'Zoo\Gen',
        ];
    }

    /**
     * The library entry point takes the namespace as given, so it is held to the same rule.
     */
    public function testGenerateFromArrayRefusesANamespaceThatIsNotOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a PHP namespace');

        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->inlineSpec(),
            outputDirectory: $this->project . '/api',
            namespace: 'Zoo;echo "x";//',
        );
    }

    public function testGenerateFromArrayKeepsAcceptingANamespaceName(): void
    {
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->inlineSpec(),
            outputDirectory: $this->project . '/api',
            namespace: 'Zoo\Api',
        );

        $this->assertStringContainsString(
            needle: 'namespace Zoo\Api;',
            haystack: (string)file_get_contents($this->project . '/api/Widget.php'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function inlineSpec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Zoo', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Widget' => ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]],
            ]],
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFilesBelow(string $directory): array
    {
        $files = [];
        foreach ((array)scandir($directory) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $files = [...$files, ...$this->phpFilesBelow($path)];
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

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
