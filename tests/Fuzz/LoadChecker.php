<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use RuntimeException;

/**
 * Talks to one long-lived `load-generated.php` child: asks it to load a generated directory and name
 * the types its classes reference but nobody wrote. The child takes the fatal errors a class that
 * cannot link raises; when it dies, the answer is "fatal" and the next request starts a new one.
 */
final class LoadChecker
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** Requests served by the current child; it is replaced now and then, since every load stays in its memory. */
    private int $served = 0;

    private readonly string $errorLog;

    public function __construct()
    {
        $this->errorLog = (string)tempnam(sys_get_temp_dir(), 'fuzz-load-');
    }

    /**
     * @return array{fatal: string|null, problems: array<int, string>}
     */
    public function check(string $directory, string $namespace): array
    {
        if ($this->served >= 400) {
            $this->stop();
        }
        $this->start();
        $this->served++;
        fwrite($this->pipes[0], $directory . "\t" . $namespace . "\n");
        $line = fgets($this->pipes[1]);
        $problems = is_string($line) ? json_decode($line, true) : null;
        if (is_array($problems)) {
            return ['fatal' => null, 'problems' => $problems];
        }

        $rest = (string)stream_get_contents($this->pipes[1]);
        $this->stop();
        $errors = (string)@file_get_contents($this->errorLog);

        return ['fatal' => trim((string)$line . $rest . "\n" . mb_substr($errors, -1500)), 'problems' => []];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        $this->served = 0;
    }

    public function __destruct()
    {
        $this->stop();
        @unlink($this->errorLog);
    }

    private function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        $process = proc_open(
            command: [PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'display_errors=stderr', __DIR__ . '/load-generated.php'],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->errorLog, 'w']],
            pipes: $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the load checker.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }
}
