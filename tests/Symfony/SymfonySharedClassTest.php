<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use OpenapiPhpDtoGenerator\Tests\Parity\RunsPayloadsThroughModes;
use PHPUnit\Framework\TestCase;

/**
 * A component file several documents `$ref` is generated into one shared place, once per document.
 * Its class must not depend on which document wrote it last: one document reads it from a callback
 * (and needs its payload view), the other does not — and whichever ran last used to decide for both.
 */
final class SymfonySharedClassTest extends TestCase
{
    use RunsPayloadsThroughModes;

    private string $baseDirectory;

    protected function setUp(): void
    {
        $this->baseDirectory = sys_get_temp_dir() . '/' . uniqid('openapi_shared_class_', true);
        mkdir($this->baseDirectory . '/common', 0o755, true);

        $this->writeJson('common/stones.json', [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Stones', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Pebble' => [
                        'type' => 'object',
                        'required' => ['colour'],
                        'properties' => ['colour' => ['type' => 'string']],
                    ],
                ],
            ],
        ]);
        // `uniqueItems` over DTO items is checked by the reader's callback, which reads each Pebble.
        $this->writeJson('reader.json', self::document('Basket', [
            'type' => 'array',
            'uniqueItems' => true,
            'items' => ['$ref' => 'common/stones.json#/components/schemas/Pebble'],
        ]));
        $this->writeJson('plain.json', self::document('Shelf', ['$ref' => 'common/stones.json#/components/schemas/Pebble']));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->baseDirectory);
    }

    public function testTheSharedClassIsTheSameWhicheverDocumentWritesIt(): void
    {
        $written = [];
        foreach (['reader', 'plain'] as $document) {
            $commonDirectory = $this->baseDirectory . '/out-' . $document . '/Common';
            $generator = new GenerateDtoCommand();
            $generator->setExternalRefMappings(
                refDirectoryPairs: [$this->baseDirectory . '/common=' . $commonDirectory],
                refNamespacePairs: [$this->baseDirectory . '/common=Shared\Common'],
            );
            $generator->generateFromFile(
                filePath: $this->baseDirectory . '/' . $document . '.json',
                outputDirectory: $this->baseDirectory . '/out-' . $document . '/Dto',
                namespace: 'Shared\Dto',
                mode: 'symfony',
            );
            $written[$document] = (string)file_get_contents($commonDirectory . '/Pebble.php');
        }

        $this->assertStringContainsString('public function toOpenApiValidationPayload(): array', $written['reader']);
        $this->assertSame($written['reader'], $written['plain']);
    }

    /**
     * The component document's OWN run writes the same class as a run that reaches it through `--ref`.
     * It used to leave the payload view out, since nothing in its own document reads Pebble from a
     * callback; run last, it took the method from under the reader's `uniqueItems`, which then saw
     * every Pebble as `[]` and refused two distinct ones as duplicates.
     */
    public function testTheComponentDocumentsOwnRunWritesTheSameClass(): void
    {
        $namespace = 'SharedOwn' . bin2hex(random_bytes(6));
        $commonDirectory = $this->baseDirectory . '/out/Common';
        $generator = new GenerateDtoCommand();
        $generator->setExternalRefMappings(
            refDirectoryPairs: [$this->baseDirectory . '/common=' . $commonDirectory],
            refNamespacePairs: [$this->baseDirectory . '/common=' . $namespace . '\Common'],
        );
        $generator->generateFromFile(
            filePath: $this->baseDirectory . '/reader.json',
            outputDirectory: $this->baseDirectory . '/out/Dto',
            namespace: $namespace . '\Dto',
            mode: 'symfony',
        );
        $viaRef = (string)file_get_contents($commonDirectory . '/Pebble.php');

        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->baseDirectory . '/common/stones.json',
            outputDirectory: $commonDirectory,
            namespace: $namespace . '\Common',
            mode: 'symfony',
        );
        $this->assertSame($viaRef, (string)file_get_contents($commonDirectory . '/Pebble.php'));

        spl_autoload_register(function (string $class) use ($namespace): void {
            foreach (['Dto', 'Common'] as $part) {
                $prefix = $namespace . '\\' . $part . '\\';
                $file = $this->baseDirectory . '/out/' . $part . '/' . substr($class, strlen($prefix)) . '.php';
                if (str_starts_with($class, $prefix) && is_file($file)) {
                    require $file;
                }
            }
        });
        $verdicts = [];
        foreach (['{"pebbles":[{"colour":"a"},{"colour":"b"}]}', '{"pebbles":[{"colour":"a"},{"colour":"a"}]}'] as $json) {
            $verdicts[$json] = $this->payloadOutcome(GenerationMode::Symfony, $namespace . '\Dto\Basket', $json)->verdict;
        }
        $this->assertSame(
            expected: [
                '{"pebbles":[{"colour":"a"},{"colour":"b"}]}' => PayloadVerdict::Accepted,
                '{"pebbles":[{"colour":"a"},{"colour":"a"}]}' => PayloadVerdict::Refused,
            ],
            actual: $verdicts,
        );
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private static function document(string $holder, array $propertySchema): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $holder, 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    $holder => [
                        'type' => 'object',
                        'required' => ['pebbles'],
                        'properties' => ['pebbles' => $propertySchema],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeJson(string $relativePath, array $document): void
    {
        file_put_contents($this->baseDirectory . '/' . $relativePath, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
