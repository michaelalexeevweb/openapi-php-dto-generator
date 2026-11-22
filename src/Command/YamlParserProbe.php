<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * Whether symfony/yaml can parse a document without taking the process down with it.
 *
 * Its lexer reads a flow collection (`[ ]`, `{ }`) by recursing once per opening bracket, and its own
 * nesting limit applies only after the lexer is done. Under an extension that hooks every call — pcov,
 * a profiler — that recursion runs on the C stack, and 20 000 `[` killed the process with SIGSEGV,
 * saying nothing. A scan of the text meant to measure that nesting first read YAML differently from
 * the parser in both directions: it refused a valid document whose plain scalar continued on a line
 * starting with `[0, 1)`, and it missed nesting behind a `#` inside a plain key. So nothing here reads
 * YAML: the parser itself parses the document, in a child process, and a crash there is reported
 * instead of suffered.
 *
 * The child costs a process start and a second parse, so it runs only where a crash is possible at
 * all — the lexer cannot nest deeper than the text has opening brackets.
 *
 * A crash refuses no document the parser would have read: it takes nesting thousands of levels deep,
 * and the parser refuses anything past 128 once it gets that far. Where the child is set up otherwise
 * than this process (an extension the parent switched off with `-d`), the refusal is still one the
 * parse would have ended in.
 */
final class YamlParserProbe
{
    /**
     * Below this many opening brackets the lexer cannot recurse deep enough to hurt: symfony/yaml
     * itself refuses past 128 levels, and the crash starts in the thousands.
     */
    private const int UNPROBED_BRACKETS = 512;

    private const string STARTED = 'opg-yaml-probe:started';

    private const string FINISHED = 'opg-yaml-probe:finished';

    /**
     * Run in the child. A parse error is the parent's to report, with its line, so it ends the child
     * as normally as a parse does; only a parse that never returns leaves FINISHED unsaid.
     */
    private const string CHILD_CODE = <<<'PHP'
        require $argv[1];
        echo %s;
        try {
            \Symfony\Component\Yaml\Yaml::parseFile($argv[2]);
        } catch (\Throwable) {
        }
        echo %s;
        PHP;

    /**
     * The status the child died with parsing `$filePath`, or null when it parsed the file — or failed
     * to parse it — like any document.
     *
     * Null as well where no child can be asked (no `proc_open()`, no CLI binary, no Composer autoloader),
     * and where the child ran out of memory: the parse then runs in this process as it always did, and
     * running out of memory there is explained by the command.
     */
    public function crashStatus(string $filePath, string $text): ?int
    {
        if (substr_count($text, '[') + substr_count($text, '{') <= self::UNPROBED_BRACKETS) {
            return null;
        }

        $autoloader = $this->autoloader();
        if ($autoloader === null || !in_array(PHP_SAPI, ['cli', 'phpdbg'], true) || !function_exists('proc_open')) {
            return null;
        }

        $process = @proc_open(
            command: [
                PHP_BINARY,
                '-d', 'memory_limit=' . ini_get('memory_limit'),
                // Every message on STDOUT, none on STDERR: one pipe to drain cannot fill up while the
                // other is being read.
                '-d', 'display_errors=1',
                '-d', 'log_errors=0',
                '-r', sprintf(self::CHILD_CODE, var_export(self::STARTED, true), var_export(self::FINISHED, true)),
                '--', $autoloader, $filePath,
            ],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        if ($process === false) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if (
            !str_contains($output, self::STARTED)
            || str_contains($output, self::FINISHED)
            || str_contains($output, 'Allowed memory size of')
        ) {
            return null;
        }

        return $status;
    }

    /**
     * Composer's `vendor/autoload.php`, found from where its class loader was loaded from: the child
     * needs symfony/yaml, and this package may be anyone's dependency.
     */
    private function autoloader(): ?string
    {
        if (!class_exists(ClassLoader::class)) {
            return null;
        }
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        if ($loaderFile === false) {
            return null;
        }
        $autoloader = dirname($loaderFile, 2) . DIRECTORY_SEPARATOR . 'autoload.php';

        return is_file($autoloader) ? $autoloader : null;
    }
}
