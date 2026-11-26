<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A document and the files beside it may come from someone else; the directories a run reads from and
 * writes to are the user's. A `$ref` reads only below the root document's directory or from a directory
 * a `--ref` names, and every generated file lands in `--directory`, `--dto-generator-directory` or a
 * `--ref` target — a document's file NAME once picked an ancestor of `--directory` to write into.
 */
final class ReferenceConfinementTest extends TestCase
{
    private const string DECOY_TOKEN = 'DECOY_TOKEN_bbb222';

    private string $root;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-confine-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/spec', 0o775, true);
        $this->workingDirectory = (string)getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->root);
    }

    /**
     * `../secret/creds.yaml` was read and its schema emitted, default value and all, into the
     * generated class: any `.yaml` the user can read was one `$ref` away.
     */
    #[DataProvider('modes')]
    public function testReferenceOutOfTheRootDocumentsDirectoryIsRefused(GenerationMode $mode): void
    {
        $this->writeDecoy();
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../secret/creds.yaml#/components/schemas/Leak')]);

        $this->assertRefusedWithoutLeak(
            mode: $mode,
            message: '$ref "../secret/creds.yaml#/components/schemas/Leak" leaves the directory of the root document',
        );
    }

    /**
     * A link inside the spec tree is judged by where it leads, not by where it sits.
     */
    #[DataProvider('linkProvider')]
    public function testLinkOutOfTheRootDocumentsDirectoryIsRefused(string $link, string $target, string $ref): void
    {
        $this->writeDecoy();
        symlink($this->root . '/' . $target, $this->root . '/spec/' . $link);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf($ref)]);

        $this->assertRefusedWithoutLeak(
            mode: GenerationMode::Runtime,
            message: sprintf('$ref "%s" leaves the directory of the root document', $ref),
        );
    }

    public static function linkProvider(): iterable
    {
        yield 'file link' => ['inner.yaml', 'secret/creds.yaml', './inner.yaml#/components/schemas/Leak'];
        yield 'directory link' => ['vault', 'secret', 'vault/creds.yaml#/components/schemas/Leak'];
    }

    /**
     * A document a `--ref` lets in may reach its own neighbours, but not past them.
     */
    public function testMappedDocumentCannotReachOutOfItsDirectory(): void
    {
        $this->writeDecoy();
        $this->writeDocument(path: 'shared/colors.yaml', schemas: ['Palette' => $this->holderOf('../secret/creds.yaml#/components/schemas/Leak')]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../shared/colors.yaml#/components/schemas/Palette')]);

        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->root . '/shared=' . $this->root . '/gen/Shared=Widgets\Shared'],
            refNamespacePairs: [],
        );
        try {
            $generator->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/out', 'Widgets');
            $this->fail('A mapped document must not lead out of its directory.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('$ref "../secret/creds.yaml#/components/schemas/Leak" leaves', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/out');
        $this->assertDirectoryDoesNotExist($this->root . '/gen');
    }

    /**
     * Neighbours of the root document, and documents below it, are its own tree and stay readable.
     */
    #[DataProvider('modes')]
    public function testSiblingAndSubdirectoryReferencesStillResolve(GenerationMode $mode): void
    {
        mkdir($this->root . '/spec/parts');
        $this->writeDocument(path: 'spec/shapes.yaml', schemas: ['Shape' => ['type' => 'object', 'properties' => ['sides' => ['type' => 'integer']]]]);
        $this->writeDocument(path: 'spec/parts/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => ['type' => 'object', 'properties' => [
            'shape' => ['$ref' => './shapes.yaml#/components/schemas/Shape'],
            'tint' => ['$ref' => 'parts/tints.yaml#/components/schemas/Tint'],
        ]]]);

        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/spec/zzz.yaml',
            outputDirectory: $this->root . '/out',
            namespace: 'Widgets' . $mode->tag(),
            mode: $mode->value,
        );

        $this->assertContains('Shape.php', (array)scandir($this->root . '/out'));
        $this->assertContains('Tint.php', (array)scandir($this->root . '/out/Parts'));
    }

    /**
     * A directory the user names with `--ref` is readable, wherever it is, and its documents may
     * refer to one another.
     */
    #[DataProvider('modes')]
    public function testDirectoryNamedByRefStillResolves(GenerationMode $mode): void
    {
        $this->writeDocument(path: 'shared/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'shared/colors.yaml', schemas: ['Palette' => $this->holderOf('./tints.yaml#/components/schemas/Tint')]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../shared/colors.yaml#/components/schemas/Palette')]);
        chdir($this->root);

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'spec/zzz.yaml',
            '--directory' => 'out',
            '--namespace' => 'Widgets' . $mode->tag(),
            '--attributes' => $mode->value,
            '--ref' => ['shared=gen/Shared=Widgets' . $mode->tag() . '\Shared'],
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertContains('Holder.php', (array)scandir($this->root . '/out'));
        $this->assertContains('Palette.php', (array)scandir($this->root . '/gen/Shared'));
        $this->assertContains('Tint.php', (array)scandir($this->root . '/gen/Shared'));
    }

    /**
     * `realpath()` keeps the case it is given, so on a filesystem that folds case `../SPEC/parts` is the
     * root document's own directory spelled differently — it is inside, and a string comparison of the
     * two spellings would call it outside. Where case is not folded it names no file at all.
     */
    public function testReferenceSpelledInAnotherCaseIsJudgedByTheDirectoryItReaches(): void
    {
        mkdir($this->root . '/spec/parts');
        $this->writeDocument(path: 'spec/parts/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../SPEC/parts/tints.yaml#/components/schemas/Tint')]);
        $foldsCase = file_exists($this->root . '/SPEC/zzz.yaml');

        try {
            (new GenerateDtoCommand())->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/out', 'Widgets');
            $this->assertTrue($foldsCase, 'Without case folding the reference names no file.');
        } catch (RuntimeException $exception) {
            $this->assertFalse($foldsCase, $exception->getMessage());
            $this->assertStringContainsString('Referenced OpenAPI file not found', $exception->getMessage());
        }
    }

    /**
     * The same for a directory the user mapped: `../SHARED/tints.yaml` reaches the mapped `shared` on a
     * filesystem that folds case, so it is read — and placed where the mapping says, not below
     * `--directory` under a namespace made up from the letters of the reference.
     */
    #[DataProvider('modes')]
    public function testMappedDirectorySpelledInAnotherCaseIsReadAndPlacedByTheMapping(GenerationMode $mode): void
    {
        $this->writeDocument(path: 'shared/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../SHARED/tints.yaml#/components/schemas/Tint')]);
        $foldsCase = file_exists($this->root . '/SHARED/tints.yaml');
        $namespace = 'Widgets' . $mode->tag();

        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->root . '/shared=' . $this->root . '/gen/Shared=' . $namespace . '\Shared'],
            refNamespacePairs: [],
        );
        try {
            $generator->generateFromFile(
                filePath: $this->root . '/spec/zzz.yaml',
                outputDirectory: $this->root . '/out',
                namespace: $namespace,
                mode: $mode->value,
            );
            $this->assertTrue($foldsCase, 'Without case folding the reference names no file.');
        } catch (RuntimeException $exception) {
            $this->assertFalse($foldsCase, $exception->getMessage());
            $this->assertStringContainsString(needle: 'Referenced OpenAPI file not found', haystack: $exception->getMessage());

            return;
        }

        $this->assertSame(expected: ['.', '..', 'Tint.php'], actual: scandir($this->root . '/gen/Shared'));
        $this->assertStringContainsString(
            needle: 'namespace ' . $namespace . '\Shared;',
            haystack: (string)file_get_contents($this->root . '/gen/Shared/Tint.php'),
        );
        $this->assertSame(expected: ['.', '..', 'Holder.php'], actual: scandir($this->root . '/out'));
    }

    /**
     * A `--ref` key that names one FILE is matched the same way: by the file it names, not its letters.
     */
    public function testMappedFileSpelledInAnotherCaseIsPlacedByTheMapping(): void
    {
        $this->writeDocument(path: 'shared/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../shared/TINTS.yaml#/components/schemas/Tint')]);
        // Where the filesystem tells case apart (Linux CI), TINTS.yaml is a file that does not exist, and
        // the reference is reported as missing; where it folds case (macOS), it is the mapped file.
        $foldsCase = file_exists($this->root . '/shared/TINTS.yaml');

        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->root . '/shared/tints.yaml=' . $this->root . '/gen/Tints=Widgets\Tints'],
            refNamespacePairs: [],
        );
        if (!$foldsCase) {
            try {
                $generator->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/out', 'Widgets');
                $this->fail('A reference to a file that does not exist must be refused.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(needle: 'Referenced OpenAPI file not found', haystack: $exception->getMessage());
            }

            return;
        }
        $generator->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/out', 'Widgets');

        $this->assertSame(expected: ['.', '..', 'Tint.php'], actual: scandir($this->root . '/gen/Tints'));
        $this->assertSame(expected: ['.', '..', 'Holder.php'], actual: scandir($this->root . '/out'));
    }

    /**
     * Folding case lets a mapped directory in under another spelling, not an unmapped one: `../SECRET`
     * is still outside, whatever `--ref` maps beside it.
     */
    public function testUnmappedDirectorySpelledInAnotherCaseIsStillRefused(): void
    {
        $this->writeDecoy();
        $this->writeDocument(path: 'shared/tints.yaml', schemas: ['Tint' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]]);
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Holder' => $this->holderOf('../SECRET/creds.yaml#/components/schemas/Leak')]);
        $foldsCase = file_exists($this->root . '/SECRET/creds.yaml');

        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->root . '/shared=' . $this->root . '/gen/Shared=Widgets\Shared'],
            refNamespacePairs: [],
        );
        try {
            $generator->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/out', 'Widgets');
            $this->fail('An unmapped directory must stay refused in any spelling.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                needle: $foldsCase ? '$ref "../SECRET/creds.yaml#/components/schemas/Leak" leaves' : 'Referenced OpenAPI file not found',
                haystack: $exception->getMessage(),
            );
            $this->assertStringNotContainsString(needle: self::DECOY_TOKEN, haystack: $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/out');
        $this->assertDirectoryDoesNotExist($this->root . '/gen');
    }

    /**
     * The `common` convention swaps the output segment named after the root document for `Common`. The
     * document's file name chose WHICH segment: `api.yaml` against `src/Api/Generated/Petstore` rebased
     * `Api`, two levels above `--directory`, and silently replaced another tree's generated `Money`.
     */
    #[DataProvider('modes')]
    public function testCommonConventionDoesNotWriteOutsideTheChosenDirectories(GenerationMode $mode): void
    {
        $this->writeCommonDocument(path: 'vendorspec/common/types.yaml');
        $this->writeDocument(path: 'vendorspec/api.yaml', schemas: ['Order' => $this->holderOf('./common/types.yaml#/components/schemas/Money')]);
        $victim = $this->root . '/proj/src/Common/Generated/Petstore/Money.php';
        mkdir(dirname($victim), 0o775, true);
        $victimContent = "<?php\n\n// This file was auto-generated from an OpenAPI schema. Do not edit.\n\nfinal class Money {}\n";
        file_put_contents($victim, $victimContent);
        chdir($this->root . '/proj');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => $this->root . '/vendorspec/api.yaml',
            '--directory' => 'src/Api/Generated/Petstore',
            '--namespace' => 'App\Api\Generated\Petstore',
            '--attributes' => $mode->value,
        ]);

        $this->assertSame(1, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('--ref', $tester->getDisplay());
        $this->assertSame($victimContent, file_get_contents($victim));
        $this->assertDirectoryDoesNotExist($this->root . '/proj/src/Api');
    }

    /**
     * The same rebase one level up: `zzz.yaml` into `zzz/out` wrote `Common/out`. The error names the
     * path and the `--ref` that writes exactly there — and that `--ref` does.
     */
    public function testCommonConventionTargetIsNamedAndReachableThroughRef(): void
    {
        $this->writeCommonDocument(path: 'spec/common/types.yaml');
        $this->writeDocument(path: 'spec/zzz.yaml', schemas: ['Order' => $this->holderOf('./common/types.yaml#/components/schemas/Money')]);
        $expectedRef = sprintf("--ref='%s/spec/common=%s/Common/out=Shop\\Common\\Out'", $this->root, $this->root);

        try {
            (new GenerateDtoCommand())->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/zzz/out', 'Shop\Zzz\Out');
            $this->fail('A `common` target outside --directory must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($this->root . '/Common/out', $exception->getMessage());
            $this->assertStringContainsString($expectedRef, $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/zzz');
        $this->assertDirectoryDoesNotExist($this->root . '/Common');

        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->root . '/spec/common=' . $this->root . '/Common/out=Shop\Common\Out'],
            refNamespacePairs: [],
        );
        $generator->generateFromFile($this->root . '/spec/zzz.yaml', $this->root . '/zzz/out', 'Shop\Zzz\Out');

        $this->assertStringContainsString('namespace Shop\Common\Out;', (string)file_get_contents($this->root . '/Common/out/Money.php'));
        $this->assertStringContainsString('use Shop\Common\Out\Money;', (string)file_get_contents($this->root . '/zzz/out/Order.php'));
    }

    /**
     * Where the rebased directory is inside a directory the user chose — here the runtime services'
     * `--dto-generator-directory` — the convention still places the classes there.
     */
    public function testCommonConventionInsideAChosenDirectoryStillApplies(): void
    {
        mkdir($this->root . '/proj');
        $this->writeCommonDocument(path: 'spec/common/types.yaml');
        $this->writeDocument(path: 'spec/module.yaml', schemas: ['Order' => $this->holderOf('./common/types.yaml#/components/schemas/Money')]);
        chdir($this->root . '/proj');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => $this->root . '/spec/module.yaml',
            '--directory' => 'gen/Module/Schemas',
            '--namespace' => 'Shop\Gen\Module\Schemas',
            '--dto-generator-directory' => 'gen/Common',
            '--dto-generator-namespace' => 'Shop\Gen\Common',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString(
            needle: 'namespace Shop\Gen\Common\Schemas;',
            haystack: (string)file_get_contents($this->root . '/proj/gen/Common/Schemas/Money.php'),
        );
        $this->assertFileExists($this->root . '/proj/gen/Common/DtoValidator.php');
        $this->assertFileExists($this->root . '/proj/gen/Module/Schemas/Order.php');
    }

    /**
     * A document in a subdirectory not called `common` goes below `--directory`, as it always did.
     */
    #[DataProvider('modes')]
    public function testSubdirectoryDocumentStaysBelowTheOutputDirectory(GenerationMode $mode): void
    {
        $this->writeCommonDocument(path: 'spec/money/types.yaml');
        $this->writeDocument(path: 'spec/api.yaml', schemas: ['Order' => $this->holderOf('./money/types.yaml#/components/schemas/Money')]);

        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/spec/api.yaml',
            outputDirectory: $this->root . '/src/Api/Out',
            namespace: 'Shop' . $mode->tag() . '\Api\Out',
            mode: $mode->value,
        );

        $this->assertContains('Money.php', (array)scandir($this->root . '/src/Api/Out/Money'));
        $this->assertDirectoryDoesNotExist($this->root . '/src/Common');
    }

    public static function modes(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    private function assertRefusedWithoutLeak(GenerationMode $mode, string $message): void
    {
        try {
            (new GenerateDtoCommand())->generateFromFile(
                filePath: $this->root . '/spec/zzz.yaml',
                outputDirectory: $this->root . '/out',
                namespace: 'Widgets' . $mode->tag(),
                mode: $mode->value,
            );
            $this->fail('A reference out of the root document\'s directory must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString(self::DECOY_TOKEN, $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/out');
        $this->assertSame([$this->root . '/secret/creds.yaml'], $this->filesHolding(directory: $this->root, needle: self::DECOY_TOKEN));
    }

    private function writeDecoy(): void
    {
        mkdir($this->root . '/secret');
        file_put_contents(
            filename: $this->root . '/secret/creds.yaml',
            data: "components:\n  schemas:\n    Leak:\n      type: object\n      properties:\n        token: {type: string, default: " . self::DECOY_TOKEN . "}\n",
        );
    }

    private function writeCommonDocument(string $path): void
    {
        $this->writeDocument(path: $path, schemas: ['Money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'string']]]]);
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private function writeDocument(string $path, array $schemas): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
        file_put_contents($file, (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Widgets', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function holderOf(string $ref): array
    {
        return ['type' => 'object', 'properties' => ['item' => ['$ref' => $ref]]];
    }

    /**
     * @return list<string>
     */
    private function filesHolding(string $directory, string $needle): array
    {
        $found = [];
        foreach ((array)scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $found = [...$found, ...$this->filesHolding(directory: $path, needle: $needle)];
            } elseif (str_contains((string)file_get_contents($path), $needle)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
