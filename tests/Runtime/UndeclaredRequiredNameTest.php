<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A class whose `required` names a property the class does not declare stops generation, in every mode.
 *
 * The class is generated without that key, so it can never hold it: a response built from it failed
 * `field "ghost" is required` wherever the built object is validated, and the raw-body modes demanded
 * of every request a key the class then threw away. Until 2.15.168 it was a warning.
 *
 * Only a CLASS's own list counts, against the properties it declares through `allOf`. A union member may
 * require what a sibling member or the class around the union declares, an `if` / `then` /
 * `dependentSchemas` subschema narrows which declared keys are present, and an object that declares no
 * property at all is held whole — those generate as before.
 */
final class UndeclaredRequiredNameTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/opg-undeclared-required-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->outputDirectory);
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     * @param list<string> $names
     */
    #[DataProvider('culpritProvider')]
    public function testAClassRequiringANameItDoesNotDeclareStopsGeneration(string $mode, array $schemas, string $schema, array $names): void
    {
        try {
            $this->generate(mode: $mode, schemas: $schemas);
            $this->fail('Generated a class that requires a name it does not declare.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString(sprintf('schema "%s"', $schema), $message);
            $this->assertStringContainsString('the in-memory document', $message);
            foreach ($names as $name) {
                $this->assertStringContainsString('"' . $name . '"', $message);
            }
            $this->assertStringContainsString('Declare the property in `properties`, or remove the name from', $message);
        }
        $this->assertSame([], glob($this->outputDirectory . '/*.php') ?: [], 'nothing is written');
    }

    /**
     * @return iterable<string, array{string, array<string, array<string, mixed>>, string, list<string>}>
     */
    public static function culpritProvider(): iterable
    {
        $shapes = [
            'a component' => [
                ['Lamp' => ['type' => 'object', 'required' => ['color', 'ghost'], 'properties' => ['color' => ['type' => 'string']]]],
                'Lamp',
                ['ghost'],
            ],
            'an open component' => [
                ['Lamp' => [
                    'type' => 'object',
                    'required' => ['ghost'],
                    'properties' => ['color' => ['type' => 'string']],
                    'additionalProperties' => true,
                    'patternProperties' => ['^x-' => ['type' => 'string']],
                ]],
                'Lamp',
                ['ghost'],
            ],
            'an inline object' => [
                ['Room' => ['type' => 'object', 'properties' => [
                    'shade' => ['type' => 'object', 'required' => ['ear'], 'properties' => ['tail' => ['type' => 'string']]],
                ]]],
                'RoomShade',
                ['ear'],
            ],
            'an allOf child, against its parent' => [
                [
                    'Base' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
                    'Child' => ['allOf' => [
                        ['$ref' => '#/components/schemas/Base'],
                        ['type' => 'object', 'required' => ['kind', 'wing'], 'properties' => ['size' => ['type' => 'integer']]],
                    ]],
                ],
                'Child',
                ['wing'],
            ],
            'a required beside a $ref' => [
                [
                    'Shape' => ['type' => 'object', 'properties' => ['ant' => ['type' => 'boolean']]],
                    'Room' => ['type' => 'object', 'properties' => ['owl' => ['$ref' => '#/components/schemas/Shape', 'required' => ['bee']]]],
                ],
                'RoomOwl',
                ['bee'],
            ],
            'a union member naming what no member declares' => [
                ['Room' => ['type' => 'object', 'properties' => ['plum' => ['oneOf' => [
                    ['type' => 'object', 'properties' => ['stoat' => ['type' => 'integer']]],
                    ['type' => 'object', 'properties' => ['marten' => ['type' => 'integer']], 'required' => ['zebu']],
                ]]]]],
                'RoomPlumOneOf2',
                ['zebu'],
            ],
            'a member of a union component' => [
                [
                    'Teal' => ['type' => 'object', 'properties' => ['marten' => ['type' => 'integer']]],
                    'Shade' => ['oneOf' => [
                        ['type' => 'object', 'properties' => ['stoat' => ['type' => 'integer']]],
                        ['$ref' => '#/components/schemas/Teal', 'required' => ['zebu']],
                    ]],
                ],
                'ShadeOneOf2',
                ['zebu'],
            ],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($shapes as $name => [$schemas, $schema, $names]) {
                yield $mode->value . ', ' . $name => [$mode->value, $schemas, $schema, $names];
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     */
    #[DataProvider('legitimateProvider')]
    public function testARequiredThatIsNoClassesOwnUndeclaredNameStillGenerates(string $mode, array $schemas): void
    {
        $this->assertGreaterThan(0, $this->generate(mode: $mode, schemas: $schemas));
    }

    /**
     * @return iterable<string, array{string, array<string, array<string, mixed>>}>
     */
    public static function legitimateProvider(): iterable
    {
        $shapes = [
            'a member requiring what a sibling declares' => ['Room' => ['type' => 'object', 'properties' => ['plum' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['stoat' => ['type' => 'integer']]],
                ['type' => 'object', 'properties' => ['marten' => ['type' => 'integer']], 'required' => ['stoat']],
            ]]]]],
            'a member of a union component requiring what a sibling declares' => ['Shade' => ['anyOf' => [
                ['type' => 'object', 'properties' => ['okapi' => ['type' => 'integer']], 'required' => ['ocelot']],
                ['type' => 'object', 'properties' => ['ocelot' => ['type' => 'string']], 'required' => ['okapi']],
            ]]],
            'arms naming the declared keys, if/then and dependentSchemas' => ['Owner' => [
                'type' => 'object',
                'properties' => ['east' => ['type' => 'integer'], 'west' => ['type' => 'integer']],
                'oneOf' => [['required' => ['east']], ['required' => ['west']]],
                'if' => ['properties' => ['east' => ['const' => 1]]],
                'then' => ['required' => ['west']],
                'dependentSchemas' => ['east' => ['required' => ['west']]],
            ]],
            'an object declaring no property' => ['Bag' => ['type' => 'object', 'required' => ['kind', 'radius']]],
            'a use-site required beside a closed reference' => [
                'Shape' => ['type' => 'object', 'properties' => ['tail' => ['type' => 'string']], 'additionalProperties' => false],
                'Room' => ['type' => 'object', 'properties' => ['owl' => ['allOf' => [['$ref' => '#/components/schemas/Shape']], 'required' => ['tail']]]],
            ],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($shapes as $name => $schemas) {
                yield $mode->value . ', ' . $name => [$mode->value, $schemas];
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     */
    private function generate(string $mode, array $schemas): int
    {
        return (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => $schemas],
            ],
            outputDirectory: $this->outputDirectory,
            namespace: 'Undeclared' . bin2hex(random_bytes(6)),
            mode: $mode,
        );
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }
}
