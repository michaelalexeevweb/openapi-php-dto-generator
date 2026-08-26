<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Both shipped binaries have to work where they actually land.
 *
 * In a dist install this package sits at `vendor/michaelalexeevweb/openapi-php-dto-generator/`, so
 * `__DIR__ . '/../vendor/autoload.php'` — the obvious spelling, and the one `bin/benchmark` used —
 * points at a directory that does not exist. `bin/console` walked up to find the autoloader and
 * worked; `bin/benchmark` did not and died with "Failed to open stream", while
 * `README.performance.md` (which ships in the archive) tells the reader to run exactly that command.
 *
 * Driven rather than read off the source: the failure is a resolved PATH, and only a real layout has
 * one. The layout is faked with a symlink to this checkout's own `vendor/`, which is what a consumer's
 * autoloader is from the binary's point of view.
 */
final class BinariesRunFromADistInstallTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            exec('rm -rf ' . escapeshellarg($this->root));
            $this->root = '';
        }
    }

    /**
     * @param array<int, string> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('binaryProvider')]
    public function testABinaryFindsItsAutoloaderFromAVendorInstall(string $binary, array $arguments): void
    {
        $package = $this->layOutDistInstall();

        $command = sprintf(
            'cd %s && php %s %s 2>&1',
            escapeshellarg($package),
            escapeshellarg('bin/' . $binary),
            implode(' ', array_map('escapeshellarg', $arguments)),
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $text = implode("\n", $output);

        self::assertStringNotContainsString(
            'Failed to open stream',
            $text,
            sprintf('bin/%s cannot resolve the autoloader from a vendor install', $binary),
        );
        self::assertStringNotContainsString('Unable to find Composer autoload file', $text, $text);
        self::assertSame(0, $status, sprintf("bin/%s exited %d:\n%s", $binary, $status, $text));
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function binaryProvider(): array
    {
        return [
            // Each is asked to do the smallest real unit of its job.
            'console' => ['console', ['--file=openapi.yaml', '--directory=out', '--namespace=DistProbe']],
            'benchmark' => ['benchmark', ['--iterations=1']],
        ];
    }

    /**
     * A package directory nested the way Composer nests it, with the autoloader two levels up — and
     * WITHOUT `tests/`, because the archive has none and the binaries must not need it.
     */
    private function layOutDistInstall(): string
    {
        $checkout = dirname(__DIR__, 2);
        $this->root = (string)tempnam(sys_get_temp_dir(), 'dist');
        unlink($this->root);
        $package = $this->root . '/vendor/michaelalexeevweb/openapi-php-dto-generator';
        mkdir($package, 0o755, true);

        foreach (['bin', 'src', 'templates'] as $directory) {
            exec(sprintf('cp -R %s %s', escapeshellarg($checkout . '/' . $directory), escapeshellarg($package . '/')));
        }
        copy($checkout . '/composer.json', $package . '/composer.json');

        // The consumer's autoloader, which is this checkout's — the binaries only ever require it.
        symlink($checkout . '/vendor/autoload.php', $this->root . '/vendor/autoload.php');
        symlink($checkout . '/vendor/composer', $this->root . '/vendor/composer');

        file_put_contents($package . '/openapi.yaml', <<<'YAML'
            openapi: 3.1.0
            info: { title: T, version: 1.0.0 }
            components:
              schemas:
                Tag:
                  type: object
                  required: [id]
                  properties:
                    id: { type: integer }
            YAML);

        return $package;
    }
}
