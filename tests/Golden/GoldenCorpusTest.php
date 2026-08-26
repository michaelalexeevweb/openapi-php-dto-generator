<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Golden;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Command\Rendering\GlobalFunctionImports;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
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
 * The snapshot fixes that. `OpenApiExamples/test.yaml` is generated in EVERY mode and compared
 * against a committed text file per mode, so any change to the emitted code shows up as a reviewable
 * diff of the snapshot instead of as silence.
 *
 * The alarm only covers what the spec exercises, so the spec carries a note wherever a schema is there
 * for coverage rather than for realism — `TestPostRequest.code` is a scalar `oneOf` because nothing else
 * in the corpus reaches the emitted branch type matcher or the union-mismatch sentence.
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
     * Every global function the generated code calls is imported — none left bare, none written with
     * a leading backslash, and none imported without being called.
     *
     * The rule is not cosmetic. An unqualified call inside a namespace makes PHP look for a namespaced
     * twin that never exists before falling back to the global function; measured on this corpus that
     * costs roughly 29 ns a call, which the fast hydrator pays once per property. `use function`
     * resolves at compile time and measures the same as the backslash, so the import is both the
     * consistent spelling and the free one.
     *
     * This is asserted rather than left to the snapshot because the emitter has FOUR render paths —
     * two Twig call sites for DTOs, one for enums, and yii3's string builder — and the first attempt
     * at this wired up only one of them. A snapshot would have pinned the half-done output as "the
     * output": a missing import reads exactly like a file that never needed one.
     *
     * {@see GlobalFunctionImports::detect()} is what the emitter itself uses, so the test cannot
     * disagree with the emitter about what counts as a call to a global function. What it adds is the
     * other direction — that the group in the file matches that answer exactly.
     */
    #[DataProvider('modeProvider')]
    public function testEveryGlobalFunctionCallIsImported(string $mode): void
    {
        $this->generateCorpus($mode);

        $problems = [];
        foreach ($this->corpusFiles() as $relativePath => $absolutePath) {
            $source = (string)file_get_contents($absolutePath);

            preg_match_all('/^use function (\w+);$/m', $source, $matches);
            /** @var list<string> $imported */
            $imported = $matches[1];
            $called = GlobalFunctionImports::detect($source);

            foreach (array_diff($called, $imported) as $function) {
                $problems[] = sprintf('%s calls %s() unqualified without importing it', $relativePath, $function);
            }

            foreach (array_diff($imported, $called) as $function) {
                $problems[] = sprintf('%s imports %s() without calling it', $relativePath, $function);
            }

            // A qualified call is the other way to spell the same thing, and it is the spelling this
            // rule replaces. Only a leading backslash counts: the separators inside `\Yiisoft\…\Number`
            // are part of a class name.
            preg_match_all('/(?<![\w\\\])\\\(\w+)\s*\(/', $source, $matches);
            /** @var list<string> $qualified */
            $qualified = $matches[1];
            foreach (array_unique($qualified) as $function) {
                if (!function_exists($function)) {
                    continue;
                }

                $problems[] = sprintf('%s calls \%s(); import it instead', $relativePath, $function);
            }
        }

        self::assertSame([], $problems, sprintf('%s-mode corpus names global functions inconsistently.', $mode));
    }

    /**
     * Every mode, from `GenerationMode` — so a mode added there gets its own snapshot column here
     * without this suite naming it. The snapshot file is `snapshots/<mode value>.snapshot.txt`, so the
     * enum value is also the file name.
     *
     * @return array<string, array{string}>
     */
    public static function modeProvider(): array
    {
        $provided = [];
        foreach (GenerationMode::cases() as $mode) {
            $provided[$mode->value . ' mode'] = [$mode->value];
        }

        return $provided;
    }

    /**
     * Two enum templates, and the axis that justifies them: does the enum carry this package's runtime
     * interface, or is it a plain backed enum?
     *
     * Runtime mode needs the interface, the other four do not — and the four then produce the SAME
     * file, byte for byte. Asserted rather than assumed, because the code that chooses the template
     * once called that answer `$isSymfony` and read it out of `enum.symfony.php.twig`: a name that was
     * false for Laravel, laravel-data and Yii3 alike. If a mode ever needs its own enum shape, this
     * fails and a third template becomes the honest answer.
     */
    public function testEveryModeWithoutTheRuntimeInterfaceEmitsTheSameEnum(): void
    {
        $enumsByMode = [];
        foreach (GenerationMode::cases() as $mode) {
            $this->generateCorpus($mode->value);

            $enums = [];
            foreach ($this->corpusFiles() as $relativePath => $absolutePath) {
                $source = (string)file_get_contents($absolutePath);
                if (preg_match('/^enum \w+/m', $source) === 1) {
                    $enums[$relativePath] = $source;
                }
            }
            $enumsByMode[$mode->value] = $enums;

            $this->deleteRecursively($this->outputDirectory);
        }

        self::assertNotSame([], $enumsByMode[GenerationMode::reference()->value], 'the corpus has no enum to compare');

        $standalone = array_diff_key($enumsByMode, [GenerationMode::reference()->value => true]);
        $first = array_key_first($standalone);
        foreach ($standalone as $mode => $enums) {
            self::assertSame(
                $standalone[$first],
                $enums,
                sprintf('%s mode emits a different enum from %s mode — the one axis has grown a second one', $mode, $first),
            );
        }

        self::assertNotSame(
            $standalone[$first],
            $enumsByMode[GenerationMode::reference()->value],
            'runtime mode must differ: it is the only one that carries the runtime interface',
        );
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
