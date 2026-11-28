<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `$ref` written in another file names its neighbours relative to THAT file, for every reader of
 * the property — its type, its temporal format, the null its target admits, an enum branch written
 * out in place — not only for the constraint extraction. Read against the root document, a file of
 * the same name beside the root one, which does not declare the schema, failed the whole generation:
 * "Referenced schema not found".
 */
final class ReferenceScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-ref-scope-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/common', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->root);
    }

    #[DataProvider('modes')]
    public function testEveryReaderResolvesAReferenceAgainstTheFileItIsWrittenIn(string $mode): void
    {
        $document = static fn(array $schemas): string => (string)json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ]);
        // The decoy: same file name as the real neighbour, one directory up, without its schemas.
        file_put_contents($this->root . '/colours.json', $document(['Unrelated' => ['type' => 'string']]));
        file_put_contents($this->root . '/common/colours.json', $document([
            'Palette' => ['type' => 'string', 'enum' => ['crimson', 'azure']],
            'Painter' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            'Dates' => ['$ref' => 'shades.json#/components/schemas/DateList'],
        ]));
        file_put_contents($this->root . '/common/shades.json', $document([
            'DateList' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string', 'format' => 'date']],
        ]));
        file_put_contents($this->root . '/common/canvas.json', $document([
            'Tint' => ['type' => 'object', 'properties' => ['shade' => ['oneOf' => [
                ['$ref' => 'colours.json#/components/schemas/Palette'],
                ['type' => 'integer'],
            ]]]],
            'Stroke' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['signed' => '#/components/schemas/SignedStroke']],
            ],
            'SignedStroke' => ['allOf' => [
                ['$ref' => '#/components/schemas/Stroke'],
                ['type' => 'object', 'properties' => [
                    'painter' => ['$ref' => 'colours.json#/components/schemas/Painter'],
                    'wrapped' => ['allOf' => [['$ref' => 'colours.json#/components/schemas/Painter']]],
                    'dates' => ['$ref' => 'colours.json#/components/schemas/Dates'],
                    'tones' => ['type' => 'array', 'items' => [
                        'allOf' => [['$ref' => 'colours.json#/components/schemas/Palette']],
                        'nullable' => true,
                    ]],
                ]],
            ]],
        ]));
        file_put_contents($this->root . '/main.json', $document(['Probe' => ['type' => 'object', 'properties' => [
            'tint' => ['$ref' => 'common/canvas.json#/components/schemas/Tint'],
            'stroke' => ['$ref' => 'common/canvas.json#/components/schemas/Stroke'],
            'signed' => ['$ref' => 'common/canvas.json#/components/schemas/SignedStroke'],
        ]]]));

        (new GenerateDtoCommand())->generateFromFile($this->root . '/main.json', $this->root . '/out', 'RefScope', $mode);

        $generated = $this->generatedSources($this->root);
        $signed = current(array_filter($generated, static fn(string $code): bool => str_contains($code, 'final class SignedStroke')));
        $this->assertIsString($signed);
        // The list two files away keeps its dates, read through both references.
        $this->assertStringContainsString('Y-m-d', $signed);
        if (in_array($mode, [GenerationMode::Runtime->value, GenerationMode::Symfony->value], true)) {
            return;
        }
        // The modes that hold the value as sent check the enum branch with its own members.
        $tint = current(array_filter($generated, static fn(string $code): bool => str_contains($code, 'final class Tint')));
        $this->assertIsString($tint);
        $this->assertStringContainsString("'crimson'", $tint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode->value];
        }
    }

    /**
     * @return list<string>
     */
    private function generatedSources(string $directory): array
    {
        $sources = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $sources = [...$sources, ...$this->generatedSources($path)];
            } elseif (str_ends_with($path, '.php')) {
                $sources[] = (string)file_get_contents($path);
            }
        }

        return $sources;
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
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
