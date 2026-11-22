<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

/**
 * Explains a run that ran out of memory. PHP ends such a run with a fatal error naming a line of this
 * package — which reads like a bug in it — and no word on what to do. A shutdown function is the one
 * place left to speak from, so it names the document, its size and the flag that gives the run more
 * room.
 *
 * Only a CLI run is watched, and only while it generates: a library caller owns its own process, and
 * once publication starts "nothing was written" would no longer be certain.
 */
final class MemoryExhaustionReport
{
    /** Released before the report, so the report itself has memory to be written with. */
    private const int RESERVE_BYTES = 512 * 1024;

    private static bool $registered = false;

    private static ?string $document = null;

    public static function watch(string $document): void
    {
        self::$document = $document;
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        $reserve = str_repeat("\0", self::RESERVE_BYTES);
        register_shutdown_function(static function () use (&$reserve): void {
            $reserve = '';
            self::report();
        });
    }

    public static function stopWatching(): void
    {
        self::$document = null;
    }

    private static function report(): void
    {
        $document = self::$document;
        $error = error_get_last();
        if ($document === null || $error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        // The fatal is not always the memory one: exhausted inside an output buffer — Twig renders into
        // one — PHP reports the buffer it could no longer use instead. The peak tells the two apart.
        $limit = self::bytes(ini_get('memory_limit'));
        $exhausted = str_contains($error['message'], 'Allowed memory size')
            || ($limit > 0 && memory_get_peak_usage(true) >= $limit * 0.9);
        if (!$exhausted) {
            return;
        }

        $size = @filesize($document);
        $stream = @fopen('php://stderr', 'wb');
        if ($stream === false) {
            return;
        }
        fwrite(
            stream: $stream,
            data: sprintf(
                "\n [ERROR] The generator ran out of memory (memory_limit=%s) on %s%s. Nothing was written.\n"
                . '         Run the same command with more room: php -d memory_limit=1G %s openapi:generate-dto … '
                . "(or -d memory_limit=-1 for no limit).\n\n",
                ini_get('memory_limit'),
                $document,
                is_int($size) ? sprintf(' (%.1f MB)', $size / 1048576) : '',
                self::script(),
            ),
        );
        fclose($stream);
    }

    private static function script(): string
    {
        $argv = $_SERVER['argv'] ?? null;
        $script = is_array($argv) ? ($argv[0] ?? null) : null;

        return is_string($script) && $script !== '' ? $script : 'bin/console';
    }

    /**
     * `memory_limit` in bytes, read the way PHP reads it (`128M`, `1G`, `-1`); 0 or less is no limit.
     */
    private static function bytes(string $setting): int
    {
        $setting = trim($setting);
        if ($setting === '' || !is_numeric(rtrim($setting, 'kKmMgG'))) {
            return 0;
        }
        $value = (int)rtrim($setting, 'kKmMgG');

        return match (strtolower(substr($setting, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
