<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7;
use PHPUnit\Framework\TestCase;

/**
 * What a reader sees when the PSR-7 bridge is not installed.
 *
 * `symfony/psr-http-message-bridge` is a `suggest`, not a `require`: an application that never
 * touches PSR-7 should not pull it in. So {@see DtoDeserializerPsr7} checks for it and throws a
 * sentence naming the package and the command that installs it — the one place in the runtime whose
 * whole job is to be read by a human at install time.
 *
 * It could not be tested in-process. The bridge IS installed here (require-dev), so `class_exists()`
 * is true in every test and those four lines were the only uncovered ones in the file — 75 %, and all
 * of the missing quarter was the message. Mocking `class_exists()` is not possible, and reshaping the
 * class to make the check injectable would be changing production code to suit a test.
 *
 * A subprocess reproduces the real condition instead of simulating it: PHP is started with an
 * autoloader that resolves this package and nothing else, so the bridge genuinely cannot be found,
 * exactly as in an application that did not install it. The companion case runs the same script
 * through the real Composer autoloader and expects no throw, which is what keeps this from passing
 * for the wrong reason — a guard that always fires would satisfy the first assertion alone.
 *
 * The sibling {@see DtoDeserializerPsr7Test} skips itself when the bridge is absent, so this lives in
 * its own class: the skip would take the test about absence with it.
 *
 * The coverage NUMBER does not move: a driver in this process cannot see a line executed in a child,
 * so the file stays at 75 % with the guard as the missing quarter. That is the cost of testing the
 * real condition, and it is written down beside the guard so nobody spends the afternoon on it.
 */
final class DtoDeserializerPsr7MissingBridgeTest extends TestCase
{
    private const string EXPECTED_MESSAGE = 'PSR-7 support requires symfony/psr-http-message-bridge. '
        . 'Install it with: composer require symfony/psr-http-message-bridge';

    private string $script = '';

    protected function tearDown(): void
    {
        if ($this->script !== '' && is_file($this->script)) {
            unlink($this->script);
        }

        $this->script = '';
    }

    public function testConstructingWithoutTheBridgeNamesThePackageAndTheCommand(): void
    {
        $output = $this->runWithAutoloader(<<<'PHP'
            $src = getenv('PKG_SRC');
            spl_autoload_register(static function (string $class) use ($src): void {
                $prefix = 'OpenapiPhpDtoGenerator\\';
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $path = $src . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            });
            PHP);

        self::assertStringContainsString('RuntimeException', $output, $output);
        self::assertStringContainsString(self::EXPECTED_MESSAGE, $output, $output);
    }

    /**
     * The guard fires ONLY when the bridge is missing.
     *
     * Without this, a check that threw unconditionally — a typo in the class name, a negated
     * condition — would pass the test above and break every PSR-7 application.
     */
    public function testConstructingWithTheBridgeInstalledDoesNotThrow(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $output = $this->runWithAutoloader(sprintf('require %s;', var_export($autoload, true)));

        self::assertSame('CONSTRUCTED', trim($output), $output);
    }

    /**
     * Runs `new DtoDeserializerPsr7()` in a fresh PHP process under the given bootstrap.
     */
    private function runWithAutoloader(string $bootstrap): string
    {
        // Built rather than `tempnam()`: the probe must end in `.php`, and appending the suffix to a
        // tempnam() path leaves the extensionless file it created behind on every run.
        $this->script = sprintf('%s/psr7bridge-%s.php', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        file_put_contents($this->script, "<?php\ndeclare(strict_types=1);\n" . $bootstrap . <<<'PHP'

            try {
                new OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7();
                echo 'CONSTRUCTED';
            } catch (Throwable $e) {
                echo get_class($e), ': ', $e->getMessage();
            }
            PHP);

        $command = sprintf(
            'PKG_SRC=%s php %s 2>&1',
            escapeshellarg(dirname(__DIR__, 2) . '/src'),
            escapeshellarg($this->script),
        );

        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        $output = implode("\n", $lines);

        self::assertSame(0, $status, sprintf("the probe process exited %d:\n%s", $status, $output));

        return $output;
    }
}
