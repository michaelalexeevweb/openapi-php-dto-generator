<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Command\YamlParserProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The child process that parses a deeply bracketed YAML document before this process does. Its
 * verdict is only worth something where it holds for this process too — whatever the child was
 * started with — and the child must never hang the run.
 */
final class YamlParserProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-probe-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/noini', 0o775, true);
        file_put_contents($this->root . '/noini/php.ini', '');
    }

    protected function tearDown(): void
    {
        foreach (['/noini/php.ini', '/noini', '/hang.sh', '/api.yaml', '/shapes.yaml', '/probe.php'] as $entry) {
            $path = $this->root . $entry;
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->root);
    }

    /**
     * pcov loaded with `-d extension=…` is in no ini file, so the child — reading only the ini —
     * parsed without it, ran out of memory where the parent would crash, and was read as "no crash":
     * the parent then died with SIGSEGV, saying nothing. Run as a subprocess, because on the old code
     * it is the PROCESS that dies.
     */
    public function testAnExtensionLoadedOnTheCommandLineStillEndsInACleanError(): void
    {
        $pcov = $this->sharedExtension('pcov');
        if ($pcov === null) {
            $this->markTestSkipped('pcov is not installed as a shared extension here.');
        }
        $this->writeDeepDocument();

        [$exitCode, $output] = $this->runConsoleWithoutIni(phpOptions: ['-d', 'extension=' . $pcov]);

        $this->assertSame(expected: 1, actual: $exitCode, message: $output);
        $this->assertMatchesRegularExpression(pattern: '/nests flow collections|Maximum nesting depth of \d+ exceeded/', string: $output);
    }

    /**
     * The same setup without the extension never crashed; it must keep ending in an explanation.
     */
    public function testTheSameRunWithoutTheExtensionStillExplainsItself(): void
    {
        $this->writeDeepDocument();

        [$exitCode, $output] = $this->runConsoleWithoutIni(phpOptions: []);

        $this->assertNotSame(expected: 0, actual: $exitCode, message: $output);
        $this->assertNotSame(expected: 139, actual: $exitCode, message: $output);
        $this->assertMatchesRegularExpression(pattern: '/nests flow collections|Maximum nesting depth of \d+ exceeded|ran out of memory/', string: $output);
    }

    /**
     * A parse error the child met comes back as the very exception the parser throws here — message,
     * line and all — so a caller cannot tell where the parse ran.
     */
    public function testAParseErrorTheChildMetIsTheParsersOwn(): void
    {
        $text = "openapi: 3.0.3\nx-many: [" . str_repeat('[1],', 600) . "[1]]\nbroken: [\n";
        file_put_contents($this->root . '/api.yaml', $text);

        $inProcess = null;
        try {
            Yaml::parseFile($this->root . '/api.yaml');
        } catch (ParseException $e) {
            $inProcess = $e;
        }
        $this->assertInstanceOf(expected: ParseException::class, actual: $inProcess);

        try {
            (new YamlParserProbe())->crashStatus(filePath: $this->root . '/api.yaml', text: $text);
            $this->fail('The probe let a document through that the parser refuses.');
        } catch (ParseException $e) {
            $this->assertSame(expected: $inProcess->getMessage(), actual: $e->getMessage());
            $this->assertSame(expected: $inProcess->getParsedLine(), actual: $e->getParsedLine());
        }
    }

    /**
     * A referenced file is reported by where, never what — also when its parse error came from the
     * child.
     */
    public function testABrokenReferencedFileIsStillReportedByItsLine(): void
    {
        file_put_contents(
            filename: $this->root . '/shapes.yaml',
            data: "components:\n  schemas:\n    Shape: {type: object}\nx-many: [" . str_repeat('[1],', 600) . "[1]]\nbroken: [\n",
        );
        file_put_contents(
            filename: $this->root . '/api.yaml',
            data: "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n    Widget:\n      \$ref: 'shapes.yaml#/components/schemas/Shape'\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Invalid YAML in referenced file .*shapes\.yaml at line 6\.$/');

        (new GenerateDtoCommand())->generateFromFile($this->root . '/api.yaml', $this->root . '/out', 'Shapes');
    }

    public function testAValidBracketedDocumentIsLeftToTheParse(): void
    {
        $text = "openapi: 3.0.3\nx-many: [" . str_repeat('[1],', 600) . "[1]]\n";
        file_put_contents($this->root . '/api.yaml', $text);

        $this->assertNull((new YamlParserProbe())->crashStatus(filePath: $this->root . '/api.yaml', text: $text));
    }

    /**
     * Under phpdbg PHP_BINARY is phpdbg, which takes `-r` code for a script name: the child never
     * parsed, and the probe — silently — never reported anything. The child is the CLI binary now.
     */
    public function testUnderPhpdbgTheChildStillParses(): void
    {
        $phpdbg = PHP_BINDIR . '/phpdbg';
        if (!is_executable($phpdbg) || !is_executable(PHP_BINDIR . '/php')) {
            $this->markTestSkipped('No phpdbg beside a php CLI here.');
        }
        $this->writeDeepDocument();
        file_put_contents(
            filename: $this->root . '/probe.php',
            data: <<<'PHP'
                <?php
                require $argv[1];
                try {
                    $status = (new \OpenapiPhpDtoGenerator\Command\YamlParserProbe())
                        ->crashStatus(filePath: $argv[2], text: (string)file_get_contents($argv[2]));
                    echo $status === null ? 'unprobed' : 'crashed';
                } catch (\Throwable) {
                    echo 'refused';
                }
                PHP,
        );

        [$exitCode, $output] = $this->runProcess(command: [
            $phpdbg,
            '-qrr',
            $this->root . '/probe.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->root . '/api.yaml',
        ]);

        $this->assertSame(expected: 0, actual: $exitCode, message: $output);
        $this->assertMatchesRegularExpression(pattern: '/(crashed|refused)$/', string: $output);
    }

    /**
     * A child that never ends — or waits for input it will never get — is killed at the timeout, and
     * the parse goes on here as it always did.
     */
    public function testAChildThatNeverEndsIsGivenUpOn(): void
    {
        file_put_contents($this->root . '/hang.sh', "#!/bin/sh\nsleep 30\n");
        chmod($this->root . '/hang.sh', 0o755);
        $text = "openapi: 3.0.3\nx-many: [" . str_repeat('[1],', 600) . "[1]]\n";
        file_put_contents($this->root . '/api.yaml', $text);

        $started = microtime(true);
        $status = (new YamlParserProbe(timeoutSeconds: 1.0, phpBinary: $this->root . '/hang.sh'))
            ->crashStatus(filePath: $this->root . '/api.yaml', text: $text);

        $this->assertNull($status);
        $this->assertLessThan(expected: 10.0, actual: microtime(true) - $started);
    }

    private function writeDeepDocument(): void
    {
        file_put_contents(
            filename: $this->root . '/api.yaml',
            data: "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\nx-deep: " . str_repeat('[', 100000) . str_repeat(']', 100000) . "\n",
        );
    }

    /**
     * The shared library of `$name` in the extension directory the PHP CLI's own ini names — this test
     * may itself run under `php -n`, where the directory is the compiled-in one.
     */
    private function sharedExtension(string $name): ?string
    {
        [, $directory] = $this->runProcess(command: [PHP_BINARY, '-r', 'echo ini_get("extension_dir");']);
        $library = trim($directory) . '/' . $name . '.' . PHP_SHLIB_SUFFIX;

        return is_file($library) ? $library : null;
    }

    /**
     * The console under an empty php.ini, so that only what `$phpOptions` loads is loaded.
     *
     * @param list<string> $phpOptions
     *
     * @return array{int, string}
     */
    private function runConsoleWithoutIni(array $phpOptions): array
    {
        return $this->runProcess(
            command: [
                PHP_BINARY,
                ...$phpOptions,
                dirname(__DIR__, 2) . '/bin/console',
                'openapi:generate-dto',
                '--file=' . $this->root . '/api.yaml',
                '--directory=' . $this->root . '/out',
                '--namespace=Probe',
            ],
            environment: ['PHPRC' => $this->root . '/noini', 'PHP_INI_SCAN_DIR' => ''],
        );
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function runProcess(array $command, array $environment = []): array
    {
        $process = proc_open(
            command: $command,
            descriptor_spec: [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            pipes: $pipes,
            env_vars: $environment === [] ? null : [...getenv(), ...$environment],
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
