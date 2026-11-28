<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;

/**
 * Absence in laravel-data mode, through the real `validateAndCreate()` and `toArray()`:
 *
 * - an optional property with no type (`mixed`) that was not sent stays out of `toArray()`, as a typed
 *   optional one does — it went out as `"x": null`;
 * - a property with a schema `default` holds the default when absent, and `is<Name>Provided()` tells
 *   "sent the default" from "not sent", as Laravel and yii3 mode do.
 */
final class AbsenceTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/ld-absence-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->outputDirectory);
    }

    public function testAnUntypedOptionalPropertyThatWasNotSentStaysAbsent(): void
    {
        $namespace = $this->generate();
        $cardClass = $namespace . '\Card';
        $deckClass = $namespace . '\Deck';

        $this->assertSame(['id' => 1, 'status' => 'unassigned'], $this->wire($cardClass::validateAndCreate(['id' => 1])->toArray()));
        $this->assertSame(['id' => 1, 'extra' => null, 'status' => 'unassigned'], $this->wire($cardClass::validateAndCreate(['id' => 1, 'extra' => null])->toArray()));
        $this->assertSame(
            ['id' => 1, 'extra' => ['a' => 1], 'note' => 0, 'status' => 'unassigned'],
            $this->wire($cardClass::validateAndCreate(['id' => 1, 'extra' => ['a' => 1], 'note' => 0])->toArray()),
        );
        // Built in code, and as a nested item.
        $this->assertSame(['id' => 2, 'status' => 'unassigned'], $this->wire((new $cardClass(id: 2))->toArray()));
        $deck = $deckClass::validateAndCreate(['cards' => [['id' => 3], ['id' => 4, 'note' => 'x']]]);
        $this->assertSame(
            ['cards' => [['id' => 3, 'status' => 'unassigned'], ['id' => 4, 'note' => 'x', 'status' => 'unassigned']]],
            $this->wire($deck->toArray()),
        );
    }

    public function testAPropertyWithASchemaDefaultTellsWhetherItWasSent(): void
    {
        $namespace = $this->generate();
        $cardClass = $namespace . '\Card';
        $deckClass = $namespace . '\Deck';

        $absent = $cardClass::validateAndCreate(['id' => 1]);
        $this->assertSame('unassigned', $absent->status->value);
        $this->assertFalse($absent->isStatusProvided());

        $sent = $cardClass::validateAndCreate(['id' => 1, 'status' => 'unassigned']);
        $this->assertSame('unassigned', $sent->status->value);
        $this->assertTrue($sent->isStatusProvided());
        // Either way the default goes out, as in every mode.
        $this->assertSame($this->wire($absent->toArray()), $this->wire($sent->toArray()));

        $this->assertTrue((new $cardClass(id: 2))->isStatusProvided());
        $deck = $deckClass::validateAndCreate(['cards' => [['id' => 3], ['id' => 4, 'status' => 'open']]]);
        $this->assertFalse($deck->cards[0]->isStatusProvided());
        $this->assertTrue($deck->cards[1]->isStatusProvided());
    }

    /**
     * @return array<mixed>
     */
    private function wire(mixed $value): array
    {
        $decoded = json_decode((string)json_encode($value), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function generate(): string
    {
        LaravelDataContainer::boot();
        $namespace = 'Absence' . bin2hex(random_bytes(6));
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.3',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [
                    'Card' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'extra' => [],
                            'note' => ['description' => 'anything at all'],
                            'status' => ['type' => 'string', 'enum' => ['open', 'unassigned'], 'default' => 'unassigned'],
                        ],
                    ],
                    'Deck' => [
                        'type' => 'object',
                        'required' => ['cards'],
                        'properties' => ['cards' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Card']]],
                    ],
                ]],
            ],
            outputDirectory: $this->outputDirectory,
            namespace: $namespace,
            mode: GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );
        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }
}
