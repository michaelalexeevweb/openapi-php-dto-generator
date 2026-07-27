<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Golden;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Byte-exact snapshot of the demo corpus, per mode.
 *
 * Every behavioural test in this repository asserts a fragment of one generated file. Nothing
 * asserted the corpus as a whole, so "the output changed" was measured by hand after each change
 * ("corpus delta: zero files", "2637 -> 2231 lines") and absences were invisible: a dead constant
 * that stopped being emitted, a `use` statement that went missing, a helper method that appeared in
 * 29 files instead of 10 — all three were caught by a human reading the output, not by a test.
 *
 * The snapshot fixes that. `OpenApiExamples/test.yaml` is generated in both modes and compared
 * against a committed text file per mode, so any change to the emitted code shows up as a reviewable
 * diff of the snapshot instead of as silence.
 *
 * Snapshots are text (`.snapshot.txt`), not PHP files: a `.php` snapshot named `Test.php` matches
 * PHPUnit's default test-file suffix and php-cs-fixer would rewrite the very formatting under test.
 *
 * To accept a deliberate change: `UPDATE_GOLDEN_CORPUS=1 vendor/bin/phpunit --filter GoldenCorpus`
 * then read the diff before committing it.
 */
final class GoldenCorpusTest extends TestCase
{
    private const string SPEC = __DIR__ . '/../../OpenApiExamples/test.yaml';
    private const string NAMESPACE = 'GoldenCorpus';
    private const string UPDATE_ENV = 'UPDATE_GOLDEN_CORPUS';

    private string $outputDirectory = '';

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    #[DataProvider('modeProvider')]
    public function testCorpusMatchesItsSnapshot(string $mode): void
    {
        $snapshot = $this->generateCorpusSnapshot($mode);
        $snapshotFile = $this->snapshotFile($mode);

        if (getenv(self::UPDATE_ENV) !== false) {
            file_put_contents($snapshotFile, $snapshot);
            self::markTestSkipped(sprintf('%s written from the current output (%s mode).', basename($snapshotFile), $mode));
        }

        self::assertFileExists(
            $snapshotFile,
            sprintf('Missing snapshot. Run: %s=1 vendor/bin/phpunit --filter GoldenCorpus', self::UPDATE_ENV),
        );

        self::assertSame(
            (string)file_get_contents($snapshotFile),
            $snapshot,
            sprintf(
                "Generated %s-mode output differs from %s.\nIf the change is intended: %s=1 vendor/bin/phpunit --filter GoldenCorpus",
                $mode,
                basename($snapshotFile),
                self::UPDATE_ENV,
            ),
        );
    }

    /**
     * The corpus is also the widest lint surface in the repository: one spec exercising most of the
     * emitter. A snapshot compares text and would happily pin unparsable PHP, so parse it too.
     */
    #[DataProvider('modeProvider')]
    public function testEveryGeneratedCorpusFileParses(string $mode): void
    {
        $this->generateCorpus($mode);

        $errors = [];
        foreach ($this->corpusFiles() as $relativePath => $absolutePath) {
            $output = [];
            $status = 0;
            exec(sprintf('php -l %s 2>&1', escapeshellarg($absolutePath)), $output, $status);
            if ($status !== 0) {
                $errors[$relativePath] = implode("\n", $output);
            }
        }

        self::assertSame([], $errors, sprintf('%s-mode corpus does not parse.', $mode));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modeProvider(): array
    {
        return [
            'runtime mode' => ['runtime'],
            'symfony mode' => ['symfony'],
        ];
    }

    private function generateCorpusSnapshot(string $mode): string
    {
        $this->generateCorpus($mode);

        $files = $this->corpusFiles();
        $inventory = array_keys($files);

        $lines = 0;
        foreach ($files as $absolutePath) {
            $lines += count(explode("\n", rtrim((string)file_get_contents($absolutePath), "\n")));
        }

        // The header is the part a reviewer reads first: a file appearing, disappearing or growing
        // is visible in the diff without scrolling through the bodies.
        $snapshot = sprintf(
            "# %s mode — %d files, %d lines\n# spec: OpenApiExamples/test.yaml, namespace: %s\n#\n",
            $mode,
            count($files),
            $lines,
            self::NAMESPACE,
        );

        foreach ($inventory as $relativePath) {
            $snapshot .= '# ' . $relativePath . "\n";
        }

        foreach ($files as $relativePath => $absolutePath) {
            $snapshot .= sprintf(
                "\n===== %s =====\n%s\n",
                $relativePath,
                rtrim((string)file_get_contents($absolutePath), "\n"),
            );
        }

        return $snapshot;
    }

    private function generateCorpus(string $mode): void
    {
        $this->outputDirectory = __DIR__ . '/output-golden-' . $mode;
        $this->deleteRecursively($this->outputDirectory);
        mkdir($this->outputDirectory, 0o755, true);

        $specFile = realpath(self::SPEC);
        self::assertIsString($specFile, 'Demo spec not found: ' . self::SPEC);

        /** @var array<string, mixed> $openApi */
        $openApi = Yaml::parseFile($specFile);

        // The return value is the number of generated classes, not an exit code.
        $generatedCount = (new GenerateDtoCommand())->generateFromArray(
            $openApi,
            $this->outputDirectory,
            self::NAMESPACE,
            $mode,
        );

        self::assertGreaterThan(0, $generatedCount, sprintf('Generation produced nothing in %s mode.', $mode));
    }

    /**
     * Relative path => absolute path, sorted, so the snapshot does not depend on directory order.
     *
     * @return array<string, string>
     */
    private function corpusFiles(): array
    {
        $files = [];
        $walk = static function (string $directory, string $prefix) use (&$walk, &$files): void {
            $entries = scandir($directory);
            if ($entries === false) {
                return;
            }
            sort($entries, SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    $walk($path, $prefix . $entry . '/');
                    continue;
                }
                $files[$prefix . $entry] = $path;
            }
        };
        $walk($this->outputDirectory, '');

        return $files;
    }

    private function snapshotFile(string $mode): string
    {
        return __DIR__ . '/snapshots/' . $mode . '.snapshot.txt';
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->deleteRecursively($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($directory);
    }
}
