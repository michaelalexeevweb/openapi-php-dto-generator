<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;

/**
 * A component file several documents `$ref` is generated into one shared place, once per document.
 * Its class must not depend on which document wrote it last: one document reads it from a callback
 * (and needs its payload view), the other does not — and whichever ran last used to decide for both.
 */
final class SymfonySharedClassTest extends TestCase
{
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
                [$this->baseDirectory . '/common=' . $commonDirectory],
                [$this->baseDirectory . '/common=Shared\Common'],
            );
            $generator->generateFromFile(
                $this->baseDirectory . '/' . $document . '.json',
                $this->baseDirectory . '/out-' . $document . '/Dto',
                'Shared\Dto',
                'symfony',
            );
            $written[$document] = (string)file_get_contents($commonDirectory . '/Pebble.php');
        }

        $this->assertStringContainsString('public function toOpenApiValidationPayload(): array', $written['reader']);
        $this->assertSame($written['reader'], $written['plain']);
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
