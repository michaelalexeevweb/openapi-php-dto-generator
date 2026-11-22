<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

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
 * The child cannot be set up exactly like this process: an extension loaded with `-d extension=…`
 * is in no file the child could read, and nothing tells this process where it was loaded from. So
 * the child's verdict is used only where it holds whatever the child lacks. A parse that SUCCEEDED
 * nested no deeper than the parser's own limit of 128, which no setup crashes on, and only then does
 * this process parse the document again. A parse that FAILED is that failure, reported from here
 * without parsing at all — a child without pcov reaches the nesting limit that the parse here would
 * have crashed on the way to. Running out of memory is reported the same way, never retried here.
 *
 * A crash refuses no document the parser would have read: it takes nesting thousands of levels deep,
 * and the parser refuses anything past 128 once it gets that far.
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
     * Run in the child. A parse error is the parent's to report, so it goes back whole — raw message,
     * line, snippet, file — for the parent to throw the same exception; only a parse that never
     * returns leaves FINISHED unsaid. The fields are private to ParseException and read through a
     * bound closure: a parse error rebuilt from its rendered message would say its line twice.
     */
    private const string CHILD_CODE = <<<'PHP'
        require $argv[1];
        echo %s;
        $failure = null;
        try {
            \Symfony\Component\Yaml\Yaml::parseFile($argv[2]);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            try {
                $failure = \Closure::bind(
                    closure: static fn (\Symfony\Component\Yaml\Exception\ParseException $e): array => [
                        'message' => $e->rawMessage,
                        'line' => $e->parsedLine,
                        'snippet' => $e->snippet,
                        'file' => $e->parsedFile,
                    ],
                    newThis: null,
                    newScope: \Symfony\Component\Yaml\Exception\ParseException::class,
                )($e);
            } catch (\Throwable) {
            }
        } catch (\Throwable) {
        }
        echo %s, json_encode($failure, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        PHP;

    /**
     * @param float       $timeoutSeconds how long the child may take before it is killed and this
     *                                    process parses as it always did: a child that waits for input
     *                                    or never ends must not hold the run
     * @param string|null $phpBinary      the PHP CLI the child runs on; null for the one this PHP came
     *                                    with
     */
    public function __construct(
        private readonly float $timeoutSeconds = 120.0,
        private readonly ?string $phpBinary = null,
    ) {
    }

    /**
     * The status the child died with parsing `$filePath`, or null when it parsed the file like any
     * document — this process may then parse it too.
     *
     * Null as well where no child can be asked (no `proc_open()`, no CLI binary, no Composer
     * autoloader) and where the child did not answer in time: the parse then runs in this process as
     * it always did.
     *
     * @throws ParseException   the child's parse error, so that this process never runs the parse
     *                          that may crash on the way to it
     * @throws RuntimeException the child ran out of memory
     */
    public function crashStatus(string $filePath, string $text): ?int
    {
        if (substr_count($text, '[') + substr_count($text, '{') <= self::UNPROBED_BRACKETS) {
            return null;
        }

        $autoloader = $this->autoloader();
        $binary = $this->cliBinary();
        if ($autoloader === null || $binary === null || !function_exists('proc_open')) {
            return null;
        }

        $command = [$binary];
        // The child reads the ini this process read — PHPRC and PHP_INI_SCAN_DIR come along in the
        // environment, `-c` and `-n` do not.
        $iniFile = php_ini_loaded_file();
        if (is_string($iniFile)) {
            array_push($command, '-c', $iniFile);
        } elseif (php_ini_scanned_files() === false) {
            $command[] = '-n';
        }
        array_push(
            $command,
            '-d',
            'memory_limit=' . ini_get('memory_limit'),
            // Every message on STDOUT: one stream to read.
            '-d',
            'display_errors=1',
            '-d',
            'log_errors=0',
            '-r',
            sprintf(self::CHILD_CODE, var_export(self::STARTED, true), var_export(self::FINISHED, true)),
            '--',
            $autoloader,
            $filePath,
        );

        $process = @proc_open(
            command: $command,
            // STDERR goes nowhere: `display_errors=1` puts every message on STDOUT, and a second pipe
            // left unread could fill up and stall the child.
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
            pipes: $pipes,
        );
        if ($process === false) {
            return null;
        }
        // No input, ever: a binary that would prompt reads end-of-file instead of waiting.
        fclose($pipes[0]);
        [$output, $inTime] = $this->drain(stream: $pipes[1]);
        fclose($pipes[1]);
        if (!$inTime) {
            proc_terminate($process, 9);
            proc_close($process);

            return null;
        }
        $status = proc_close($process);

        $started = strpos($output, self::STARTED);
        if ($started === false) {
            return null;
        }
        $finished = strpos($output, self::FINISHED, $started);
        if ($finished === false) {
            if (str_contains(substr($output, $started), 'Allowed memory size of')) {
                throw new RuntimeException(message: sprintf(
                    'Parsing %s ran out of memory (memory_limit=%s), as a document that nests flow collections ([ ] or { }) thousands of levels deep does. Nothing was generated; a larger memory_limit lets the parser reach its verdict.',
                    $filePath,
                    ini_get('memory_limit'),
                ));
            }

            return $status;
        }

        $failure = json_decode(trim(substr($output, $finished + strlen(self::FINISHED))), associative: true);
        if (is_array($failure) && is_string($failure['message'] ?? null) && is_int($failure['line'] ?? null)) {
            throw new ParseException(
                rawMessage: $failure['message'],
                parsedLine: $failure['line'],
                snippet: is_string($failure['snippet'] ?? null) ? $failure['snippet'] : null,
                parsedFile: is_string($failure['file'] ?? null) ? $failure['file'] : null,
            );
        }

        return null;
    }

    /**
     * The CLI binary: PHP_BINARY is the running SAPI's, and phpdbg takes `-r` code for the name of a
     * script it cannot open — then sits at its prompt — so under any other SAPI the `php` beside it
     * is asked instead.
     */
    private function cliBinary(): ?string
    {
        $binary = $this->phpBinary ?? (PHP_SAPI === 'cli'
            ? PHP_BINARY
            : PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'));

        return $binary !== '' && is_executable($binary) ? $binary : null;
    }

    /**
     * Everything the child prints, and whether it ended before the timeout.
     *
     * @param resource $stream
     *
     * @return array{string, bool}
     */
    private function drain($stream): array
    {
        stream_set_blocking($stream, false);
        $deadline = microtime(true) + $this->timeoutSeconds;
        $output = '';
        while (!feof($stream)) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return [$output, false];
            }
            $read = [$stream];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000)) === false) {
                return [$output, false];
            }
            $output .= (string)fread($stream, 65536);
        }

        return [$output, true];
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
