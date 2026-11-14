<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use FilesystemIterator;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What one run generates: every document a `--file` mask matches, and of those only the classes
 * `--only` / `--exclude` leave — always together with every class they need.
 */
final class DocumentSelectionTest extends TestCase
{
    private string $root;
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openapi_dto_generator_' . uniqid('selection_', true);
        mkdir($this->root, 0o755, true);
        $workingDirectory = getcwd();
        $this->assertIsString($workingDirectory);
        $this->workingDirectory = $workingDirectory;
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->deleteDirectory($this->root);
    }

    /**
     * One mask, two documents: `{1}` is the directory the wildcard matched, in class-name case in both
     * the directory and the namespace.
     */
    public function testFileMaskGeneratesEveryMatchingDocument(): void
    {
        $this->writeServiceDocument('specs/alpha/api.json', 'Widget');
        $this->writeServiceDocument('specs/user_reports/api.json', 'Gadget');

        $tester = $this->generate([
            '--file' => 'specs/*/api.json',
            '--directory' => 'src/Generated/{1}',
            '--namespace' => 'App\Generated\{1}',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('from 2 document(s)', $tester->getDisplay());
        $this->assertContains('Widget.php', (array)scandir('src/Generated/Alpha'));
        $this->assertContains('Gadget.php', (array)scandir('src/Generated/UserReports'));
        $this->assertStringContainsString(
            needle: 'namespace App\Generated\UserReports;',
            haystack: (string)file_get_contents('src/Generated/UserReports/Gadget.php'),
        );
    }

    /**
     * A wildcard in the file name stands for the name without its extension; without --namespace the
     * namespace follows the filled directory, as it does for a plain --file.
     */
    public function testFileNameWildcardFillsWithoutTheExtension(): void
    {
        $this->writeServiceDocument('specs/billing.json', 'Invoice');
        $this->writeServiceDocument('specs/shipping.json', 'Parcel');

        $tester = $this->generate(['--file' => 'specs/*.json', '--directory' => 'out/{1}']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertContains('Invoice.php', (array)scandir('out/Billing'));
        $this->assertStringContainsString('namespace Out\Shipping;', (string)file_get_contents('out/Shipping/Parcel.php'));
    }

    /**
     * The point of one run for many documents is that it is the same as one run per document: no
     * schema, cache or warning of one document reaches the next. A document --ref'd by both is one
     * file, generated identically twice.
     */
    #[DataProvider('modes')]
    public function testFileMaskOutputEqualsOneRunPerDocument(GenerationMode $mode): void
    {
        $this->writeSharedDocuments();

        $masked = $this->generate([
            '--file' => 'specs/*/api.json',
            '--directory' => 'masked/{1}',
            '--namespace' => 'Acme\{1}',
            '--attributes' => $mode->value,
            '--ref' => ['specs/common=masked/Common=Acme\Common'],
        ]);
        $this->assertSame(0, $masked->getStatusCode(), $masked->getDisplay());

        foreach (['alpha' => 'Alpha', 'beta' => 'Beta'] as $service => $segment) {
            $single = $this->generate([
                '--file' => 'specs/' . $service . '/api.json',
                '--directory' => 'single/' . $segment,
                '--namespace' => 'Acme\\' . $segment,
                '--attributes' => $mode->value,
                '--ref' => ['specs/common=single/Common=Acme\Common'],
            ]);
            $this->assertSame(0, $single->getStatusCode(), $single->getDisplay());
        }

        $this->assertNotSame([], $this->tree('masked'));
        $this->assertSame($this->tree('single'), $this->tree('masked'));
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    #[DataProvider('refusedDocumentSelections')]
    public function testRefusedDocumentSelectionWritesNothing(array $options, string $message): void
    {
        $this->writeServiceDocument('specs/alpha/api.json', 'Widget', label: 'alpha');
        $this->writeServiceDocument('specs/beta/api.json', 'Widget', label: 'beta');

        $tester = $this->generate($options + ['--directory' => 'out/{1}']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString($message, $this->flatDisplay($tester));
        $this->assertDirectoryDoesNotExist('out');
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string}>
     */
    public static function refusedDocumentSelections(): iterable
    {
        yield 'mask matching nothing' => [['--file' => 'specs/*/missing.json'], '--file "specs/*/missing.json" matches no file.'];
        yield 'placeholder past the wildcards' => [
            ['--file' => 'specs/*/api.json', '--directory' => 'out/{2}'],
            '--directory "out/{2}" uses {2}, but --file "specs/*/api.json" has 1 wildcard segment(s)',
        ];
        yield 'placeholder without a mask' => [
            ['--file' => 'specs/alpha/api.json', '--directory' => 'out', '--namespace' => 'Acme\{1}'],
            '--namespace "Acme\{1}" uses {1}, but --file "specs/alpha/api.json" has 0 wildcard segment(s)',
        ];
        yield 'one class per mask directory' => [
            ['--file' => 'specs/*/api.json', '--directory' => 'out/Same', '--namespace' => 'Acme\Same'],
            'specs/beta/api.json: generates',
        ];
    }

    /**
     * One broken document stops the whole run before a byte is written, and the error names it.
     */
    public function testOneBrokenDocumentPublishesNothing(): void
    {
        $this->writeServiceDocument('specs/alpha/api.json', 'Widget');
        mkdir('specs/beta', 0o755, true);
        file_put_contents('specs/beta/api.json', '[1, 2]');

        $tester = $this->generate(['--file' => 'specs/*/api.json', '--directory' => 'out/{1}']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('specs/beta/api.json: OpenAPI root must be an object/array.', $this->flatDisplay($tester));
        $this->assertDirectoryDoesNotExist('out');
    }

    /**
     * `--only=Holder` keeps Holder and everything it reaches — the list's item class, the enum, the
     * union and its members, Laravel's FormRequest — and nothing else; `--exclude='Unrelated*'` lands
     * on the same set from the other side. The expected set is the unfiltered run minus the unrelated
     * classes, so it holds whatever a mode names its interfaces.
     */
    #[DataProvider('modes')]
    public function testOnlyKeepsWhatTheSelectedClassesNeed(GenerationMode $mode): void
    {
        $this->writeSelectionDocument();
        $everything = $this->generatedFiles(['--attributes' => $mode->value]);
        $needed = array_values(array_filter(
            array: $everything,
            callback: static fn(string $file): bool => !str_starts_with($file, 'Unrelated'),
        ));
        $this->assertContains('Item.php', $needed);
        $this->assertContains('Unrelated.php', $everything);

        $this->assertSame($needed, $this->generatedFiles(['--attributes' => $mode->value, '--only' => ['Holder']]));
        foreach ($needed as $file) {
            $this->assertSame('', $this->lintError('out/' . $file), $file);
        }
        $this->assertSame($needed, $this->generatedFiles(['--attributes' => $mode->value, '--exclude' => ['unrelated*']]));
        $this->assertSame(
            ['Unrelated.php', 'UnrelatedChild.php'],
            $this->generatedFiles(['--attributes' => $mode->value, '--only' => ['Unrelated']]),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode}>
     */
    public static function modes(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * Excluding a class something kept still needs is refused — a DTO pointing at a class that is not
     * there fatals at its first use — and so is a mask that matches nothing.
     */
    public function testRefusedClassSelectionWritesNothing(): void
    {
        $this->writeSelectionDocument();

        $needed = $this->generate(['--file' => 'spec.json', '--directory' => 'out', '--exclude' => ['Item']]);
        $this->assertSame(1, $needed->getStatusCode());
        $this->assertStringContainsString('Out\Holder needs Out\Item, which --exclude removes.', $this->flatDisplay($needed));

        $typo = $this->generate(['--file' => 'spec.json', '--directory' => 'out', '--only' => ['Holder', 'Hodler']]);
        $this->assertSame(1, $typo->getStatusCode());
        $this->assertStringContainsString('--only "Hodler" matches no generated class.', $this->flatDisplay($typo));

        $this->assertDirectoryDoesNotExist('out');
    }

    /**
     * The filter spans the whole mask run: a mask has to match in some document, not in every one,
     * and a class --ref'd into another namespace is followed through its `use` line.
     */
    public function testOnlyAcrossAMaskRunFollowsImportedClasses(): void
    {
        $this->writeSharedDocuments();

        $tester = $this->generate([
            '--file' => 'specs/*/api.json',
            '--directory' => 'out/{1}',
            '--namespace' => 'Acme\{1}',
            '--ref' => ['specs/common=out/Common=Acme\Common'],
            '--only' => ['Widget'],
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['Widget.php'], array_values(array_diff((array)scandir('out/Alpha'), ['.', '..'])));
        $this->assertSame([], array_values(array_diff((array)scandir('out/Beta'), ['.', '..'])));
        $this->assertContains('Badge.php', (array)scandir('out/Common'));
        $this->assertCount(2, array_diff((array)scandir('out/Common'), ['.', '..']), 'Badge and its enum');
    }

    /**
     * The run owns its directory as before: a class the filter leaves out is not left behind from an
     * earlier, unfiltered run.
     */
    public function testFilteredRunRemovesClassesItNoLongerGenerates(): void
    {
        $this->writeSelectionDocument();
        $this->generatedFiles([]);

        $this->assertNotContains('Unrelated.php', $this->generatedFiles(['--only' => ['Holder']]));
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    private function generate(array $options): CommandTester
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $tester->execute($options);

        return $tester;
    }

    /**
     * @param array<string, string|list<string>> $options
     * @return list<string>
     */
    private function generatedFiles(array $options): array
    {
        $tester = $this->generate($options + ['--file' => 'spec.json', '--directory' => 'out']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return array_values(array_diff((array)scandir('out'), ['.', '..']));
    }

    private function flatDisplay(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', str_replace(' ! ', ' ', $tester->getDisplay()));
    }

    private function writeServiceDocument(string $path, string $schemaName, string $label = 'label'): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => [
            $schemaName => ['type' => 'object', 'properties' => [$label => ['type' => 'string']]],
        ]]], JSON_THROW_ON_ERROR));
    }

    /**
     * Two services that both use a shared `common` document, each with schemas of its own.
     */
    private function writeSharedDocuments(): void
    {
        mkdir('specs/common', 0o755, true);
        file_put_contents('specs/common/shapes.json', json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => [
            'Badge' => ['type' => 'object', 'properties' => [
                'label' => ['type' => 'string'],
                'tone' => ['type' => 'string', 'enum' => ['crimson', 'azure']],
            ]],
        ]]], JSON_THROW_ON_ERROR));
        foreach (['alpha' => 'Widget', 'beta' => 'Gadget'] as $service => $schemaName) {
            mkdir('specs/' . $service, 0o755, true);
            file_put_contents('specs/' . $service . '/api.json', json_encode([
                'openapi' => '3.1.0',
                'paths' => ['/api/test/' . $service => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schemaName]]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]]],
                'components' => ['schemas' => [
                    $schemaName => ['type' => 'object', 'required' => ['badge'], 'properties' => [
                        'badge' => ['$ref' => '../common/shapes.json#/components/schemas/Badge'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function writeSelectionDocument(): void
    {
        file_put_contents('spec.json', json_encode([
            'openapi' => '3.1.0',
            'paths' => ['/api/test/holders' => ['post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Holder']]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => ['schemas' => [
                'Holder' => ['type' => 'object', 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
                    'color' => ['$ref' => '#/components/schemas/Color'],
                    'shape' => ['$ref' => '#/components/schemas/Shape'],
                ]],
                'Item' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
                'Color' => ['type' => 'string', 'enum' => ['crimson', 'azure']],
                'Shape' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/Circle'],
                    ['$ref' => '#/components/schemas/Square'],
                ]],
                'Circle' => ['type' => 'object', 'required' => ['radius'], 'properties' => ['radius' => ['type' => 'number']]],
                'Square' => ['type' => 'object', 'required' => ['side'], 'properties' => ['side' => ['type' => 'number']]],
                'Unrelated' => ['type' => 'object', 'properties' => ['child' => ['$ref' => '#/components/schemas/UnrelatedChild']]],
                'UnrelatedChild' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function tree(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = (string)$file;
            $files[substr($path, strlen($directory) + 1)] = (string)file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    private function lintError(string $file): string
    {
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

        return $status === 0 ? '' : implode("\n", $output);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir((string)$entry) : unlink((string)$entry);
        }
        rmdir($directory);
    }
}
