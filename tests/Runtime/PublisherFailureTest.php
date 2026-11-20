<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GeneratedFilePublisher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the publisher does when the filesystem fails it: a publication that succeeded stays a success
 * when only the old generation's backup cannot be removed, and a rollback that cannot restore
 * everything says so instead of reporting the original error alone.
 */
final class PublisherFailureTest extends TestCase
{
    private string $root;

    private string $errorLog;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-cleanup-' . bin2hex(random_bytes(8));
        // One level down: a directory that is itself read-only cannot be MOVED on every platform, and
        // the backup has to move it before it can fail to delete it.
        mkdir($this->root . '/out/old/locked', 0o775, true);
        file_put_contents($this->root . '/out/old/locked/Old.php', '<?php final class Old {}');
        chmod($this->root . '/out/old/locked', 0o555);

        $this->errorLog = $this->root . '.log';
        $this->previousErrorLog = (string)ini_get('error_log');
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        $this->remove($this->root);
        if (is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
    }

    public function testFailedBackupRemovalKeepsThePublicationAndReportsTheBackup(): void
    {
        (new GeneratedFilePublisher())->publish(
            files: [$this->root . '/out/Widget.php' => '<?php final class Widget {}'],
            ownedDirectories: [$this->root . '/out'],
        );

        $this->assertSame('<?php final class Widget {}', file_get_contents($this->root . '/out/Widget.php'));
        $this->assertDirectoryDoesNotExist($this->root . '/out/old');
        $leftovers = glob($this->root . '/out/.openapi-*');
        $this->assertNotEmpty($leftovers, 'the backup that could not be removed is kept');
        $this->assertStringContainsString('Cannot remove output backup', (string)file_get_contents($this->errorLog));
    }

    public function testAFailedRollbackSaysSoAndNamesWhatItCouldNotRestore(): void
    {
        mkdir($this->root . '/mapped');
        file_put_contents($this->root . '/out/Old.php', '<?php final class Old {}');
        $failInstallOf = $this->root . '/mapped/Mapped.php';
        $failRestoreOf = $this->root . '/out/Old.php';
        $publisher = new GeneratedFilePublisher(
            rename: static function (string $source, string $target) use ($failInstallOf, $failRestoreOf): bool {
                $installing = $target === $failInstallOf && str_contains(basename($source), '.openapi-');
                $restoring = $target === $failRestoreOf && str_contains($source, '.openapi-');

                return !$installing && !$restoring && @rename($source, $target);
            },
        );

        try {
            $publisher->publish(
                files: [
                    $this->root . '/out/Widget.php' => '<?php final class Widget {}',
                    $failInstallOf => '<?php final class Mapped {}',
                ],
                ownedDirectories: [$this->root . '/out'],
            );
            $this->fail('The publication must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot move generated output', $exception->getMessage());
            $this->assertStringContainsString('Rollback requires attention', $exception->getMessage());
            $this->assertStringContainsString('Old.php', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->root . '/out/Widget.php');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            chmod($path, 0o775);
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
