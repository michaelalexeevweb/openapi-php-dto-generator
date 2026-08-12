<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\TestCase;

/**
 * A property whose schema is a union of OBJECTS with no `discriminator` cannot be hydrated in ANY mode,
 * and the document says so at generation time rather than the request finding out.
 *
 * Nothing can turn such a payload back into a member: the document does not say which member a given
 * object is, and choosing by structure would be a guess two overlapping branches could not settle. Every
 * mode therefore failed on a payload the document allows, late and differently — measured before this
 * warning existed:
 *
 *     runtime       RuntimeException: Unsupported type: Shape
 *     symfony       NotNormalizableValueException: … must be one of "Shape" ("array" given)
 *     laravel       Error: Call to undefined method Shape::fromValidated()
 *     laravel-data  TypeError: Argument #1 ($shape) must be of type Shape, array given
 *
 * Two of those read as bugs in the generated code rather than as a gap in the document, and the shape was
 * visible at build time in every mode. So the diagnostic is what this pins, in every mode — and the
 * negative half matters as much: a union of SCALARS, a nested class, and a DISCRIMINATED union all hydrate
 * fine, and must not be warned about.
 */
final class UnhydratableUnionParityTest extends TestCase
{
    private string $outputDirectory = '';

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    public function testEveryModeNamesTheUnhydratableUnionAtGenerationTime(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $warnings = $this->generate($mode);

            $matching = array_values(array_filter(
                $warnings,
                static fn(string $warning): bool => str_contains($warning, 'an undiscriminated oneOf union'),
            ));

            // Two properties reach the same union: directly, and as the items of an array.
            $this->assertCount(
                2,
                $matching,
                sprintf("%s mode did not name both properties:\n %s", $mode->value, implode("\n ", $warnings)),
            );

            $this->assertStringContainsString('Property "shape" of Probe refers to Shape', $matching[0]);
            $this->assertStringContainsString('Property "many" of Probe refers to Shape', $matching[1]);
            // The remedy belongs in the message: a warning that only says "no" costs a debugging session.
            $this->assertStringContainsString('Add a discriminator to Shape', $matching[0]);
        }
    }

    /**
     * The three shapes that DO hydrate must stay silent, or the warning becomes noise nobody reads.
     */
    public function testTheShapesThatHydrateAreNotWarnedAbout(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $warnings = implode("\n", $this->generate($mode));

            $this->assertStringNotContainsString('Property "ok"', $warnings, 'a plain nested class hydrates');
            $this->assertStringNotContainsString('Property "either"', $warnings, 'a scalar union is a PHP union type');
            $this->assertStringNotContainsString('Property "animal"', $warnings, 'a discriminated union resolves');
        }
    }

    /**
     * Generation still SUCCEEDS. The interface and its members are useful as types, and a document may
     * reference the union only in a response — which is never hydrated, so refusing to generate would take
     * away something that works.
     */
    public function testGenerationStillSucceeds(): void
    {
        $this->generate(GenerationMode::LaravelData);

        $this->assertFileExists($this->outputDirectory . '/Probe.php');
        $this->assertFileExists($this->outputDirectory . '/Shape.php');
    }

    /**
     * @return array<int, string>
     */
    private function generate(GenerationMode $mode): array
    {
        $this->outputDirectory = sys_get_temp_dir() . '/union-warn-' . strtolower($mode->tag()) . '-' . getmypid();
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        $generator = new GenerateDtoCommand();
        $generator->generateFromArray(
            self::spec(),
            $this->outputDirectory,
            'UnionWarn' . $mode->tag(),
            $mode->value,
        );

        return $generator->getGenerationWarnings();
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['shape', 'many', 'ok'],
                        'properties' => [
                            // The two that cannot be hydrated.
                            'shape' => ['$ref' => '#/components/schemas/Shape'],
                            'many' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']],
                            // And the three that can.
                            'ok' => ['$ref' => '#/components/schemas/Circle'],
                            'either' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                            'animal' => ['$ref' => '#/components/schemas/Animal'],
                        ],
                    ],
                    'Shape' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/Circle'],
                            ['$ref' => '#/components/schemas/Square'],
                        ],
                    ],
                    'Circle' => ['type' => 'object', 'required' => ['r'], 'properties' => ['r' => ['type' => 'integer']]],
                    'Square' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]],
                    'Animal' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/Cat'],
                            ['$ref' => '#/components/schemas/Dog'],
                        ],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => [
                                'cat' => '#/components/schemas/Cat',
                                'dog' => '#/components/schemas/Dog',
                            ],
                        ],
                    ],
                    'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
                    'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
                ],
            ],
        ];
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
