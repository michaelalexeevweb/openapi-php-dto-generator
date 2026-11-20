<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a document may make the generator read and replace: only documents, only generated files, and
 * no more nodes than fit in memory.
 */
final class UntrustedDocumentInputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-untrusted-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/specs', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testReferenceToAFileThatIsNoDocumentIsRefused(): void
    {
        file_put_contents($this->root . '/notes.txt', "zebra: striped-private-note\n");
        $this->writeRootDocument('../notes.txt#/components/schemas/Widget');

        try {
            $this->generateFromRoot();
            $this->fail('A $ref at a non-document file must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must be a .yaml, .yml or .json document', $exception->getMessage());
            $this->assertStringNotContainsString('striped-private-note', $exception->getMessage());
        }
    }

    public function testBrokenReferencedYamlIsReportedWithoutItsContent(): void
    {
        file_put_contents($this->root . '/specs/broken.yaml', "zebra: striped-private-note\n  : : [\n");
        $this->writeRootDocument('broken.yaml#/components/schemas/Widget');

        try {
            $this->generateFromRoot();
            $this->fail('A broken referenced document must stop generation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Invalid YAML in referenced file', $exception->getMessage());
            $this->assertStringNotContainsString('striped-private-note', $exception->getMessage());
        }
    }

    public function testNestedYamlAliasesStopBeforeTheyExpand(): void
    {
        $yaml = "openapi: 3.0.0\ninfo: {title: t, version: '1'}\npaths: {}\nx-bomb:\n  l0: &l0 [a, a, a, a, a, a, a, a, a, a]\n";
        for ($level = 1; $level <= 9; $level++) {
            $yaml .= sprintf("  l%d: &l%d [%s]\n", $level, $level, implode(', ', array_fill(0, 10, '*l' . ($level - 1))));
        }
        file_put_contents($this->root . '/specs/api.yaml', $yaml);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expands to more than');
        $this->generateFromRoot();
    }

    public function testCommonDocumentDoesNotReplaceAHandWrittenClass(): void
    {
        mkdir($this->root . '/specs/common');
        file_put_contents($this->root . '/specs/common/shared.yaml', (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Shared', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => ['Kernel' => ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]]]],
        ]));
        $this->writeRootDocument('common/shared.yaml#/components/schemas/Kernel');
        mkdir($this->root . '/app/Common', 0o775, true);
        file_put_contents($this->root . '/app/Common/Kernel.php', '<?php final class Kernel {}');

        try {
            (new GenerateDtoCommand())->generateFromFile($this->root . '/specs/api.yaml', $this->root . '/app/Api', 'App\Api');
            $this->fail('A hand-written class must not be replaced.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to replace', $exception->getMessage());
        }
        $this->assertSame('<?php final class Kernel {}', file_get_contents($this->root . '/app/Common/Kernel.php'));

        unlink($this->root . '/app/Common/Kernel.php');
        (new GenerateDtoCommand())->generateFromFile($this->root . '/specs/api.yaml', $this->root . '/app/Api', 'App\Api');
        (new GenerateDtoCommand())->generateFromFile($this->root . '/specs/api.yaml', $this->root . '/app/Api', 'App\Api');
        $this->assertStringContainsString('auto-generated', (string)file_get_contents($this->root . '/app/Common/Kernel.php'));
    }

    private function writeRootDocument(string $ref): void
    {
        file_put_contents($this->root . '/specs/api.yaml', (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Widgets', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Holder' => ['type' => 'object', 'properties' => ['item' => ['$ref' => $ref]]],
            ]],
        ]));
    }

    private function generateFromRoot(): void
    {
        (new GenerateDtoCommand())->generateFromFile($this->root . '/specs/api.yaml', $this->root . '/out', 'Widgets');
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
