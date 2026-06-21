<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Validator\Validation;

final class GenerateSymfonyDtoTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->outputDirectory)) {
            return;
        }

        $entries = scandir($this->outputDirectory);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($this->outputDirectory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($this->outputDirectory);
    }

    /**
     * @return array<string, mixed>
     */
    private function userSpec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Address' => [
                        'type' => 'object',
                        'required' => ['city'],
                        'properties' => [
                            'city' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            'zip' => ['type' => 'string', 'pattern' => '^[0-9]{5}$'],
                        ],
                    ],
                    'User' => [
                        'type' => 'object',
                        'required' => ['name', 'age'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
                            'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120],
                            'score' => ['type' => 'number', 'exclusiveMinimum' => 0, 'multipleOf' => 0.5],
                            'created_at' => ['type' => 'string', 'maxLength' => 30],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 1,
                                'maxItems' => 5,
                                'uniqueItems' => true,
                            ],
                            'address' => ['$ref' => '#/components/schemas/Address'],
                            'others' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Address']],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testSymfonyModeEmitsAssertAttributesAndPublicReadonlyProps(): void
    {
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGen', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/User.php');

        $this->assertStringContainsString('use Symfony\\Component\\Validator\\Constraints as Assert;', $content);
        $this->assertStringContainsString('#[Assert\\NotNull]', $content);
        $this->assertStringContainsString('#[Assert\\Length(min: 2, max: 50)]', $content);
        $this->assertStringContainsString('#[Assert\\Range(min: 0, max: 120)]', $content);
        $this->assertStringContainsString('#[Assert\\GreaterThan(0)]', $content);
        $this->assertStringContainsString('#[Assert\\DivisibleBy(0.5)]', $content);
        $this->assertStringContainsString('#[Assert\\Count(min: 1, max: 5)]', $content);
        $this->assertStringContainsString('#[Assert\\Unique]', $content);
        $this->assertStringContainsString('#[Assert\\Valid]', $content);
        $this->assertStringContainsString('public readonly string $name,', $content);
    }

    public function testSymfonyModeEmitsSerializedNameWhenPropertyDiffersFromOpenApiName(): void
    {
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGenSerialized', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/User.php');

        // created_at -> camelCased property createdAt with a SerializedName mapping back.
        $this->assertStringContainsString('use Symfony\\Component\\Serializer\\Attribute\\SerializedName;', $content);
        $this->assertStringContainsString("#[SerializedName('created_at')]", $content);
        $this->assertStringContainsString('$createdAt', $content);
    }

    public function testSymfonyModeOmitsLibraryRuntimeArtifacts(): void
    {
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGenClean', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/User.php');

        $this->assertStringNotContainsString('GeneratedDtoInterface', $content);
        $this->assertStringNotContainsString('UnsetValue', $content);
        $this->assertStringNotContainsString('getNormalizationMap', $content);
        $this->assertStringNotContainsString('function toArray', $content);
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown generation mode');

        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGenBad', 'banana');
    }

    public function testGeneratedSymfonyDtoValidatesWithRealSymfonyValidator(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $namespace = 'SymGenItg';
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, $namespace, 'symfony');

        require_once $this->outputDirectory . '/Address.php';
        require_once $this->outputDirectory . '/User.php';

        $userClass = $namespace . '\\User';
        $addressClass = $namespace . '\\Address';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $valid = new $userClass(
            name: 'Jo',
            age: 30,
            score: 1.5,
            tags: ['a', 'b'],
            address: new $addressClass(city: 'NYC', zip: '10001'),
            others: [],
        );
        $this->assertCount(0, $validator->validate($valid));

        $invalid = new $userClass(
            name: 'X',
            age: 999,
            score: 1.5,
            tags: ['x', 'x'],
            address: new $addressClass(city: '', zip: 'abc'),
            others: [],
        );
        $violations = $validator->validate($invalid);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        $this->assertContains('name', $paths);
        $this->assertContains('age', $paths);
        $this->assertContains('tags', $paths);
        // Assert\Valid cascades into the nested Address DTO.
        $this->assertContains('address.city', $paths);
        $this->assertContains('address.zip', $paths);
    }

    public function testRangeSupportsOnlyMinOrOnlyMax(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Bounds' => [
                        'type' => 'object',
                        'properties' => [
                            'lo' => ['type' => 'integer', 'minimum' => 5],
                            'hi' => ['type' => 'integer', 'maximum' => 9],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenRange', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Bounds.php');
        $this->assertStringContainsString('#[Assert\\Range(min: 5)]', $content);
        $this->assertStringContainsString('#[Assert\\Range(max: 9)]', $content);
    }

    public function testExclusiveBooleanFormBecomesGreaterThanLessThan(): void
    {
        // OpenAPI 3.0 spells exclusive bounds as a boolean modifier on minimum/maximum.
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Excl' => [
                        'type' => 'object',
                        'properties' => [
                            'n' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 10,
                                'exclusiveMinimum' => true,
                                'exclusiveMaximum' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenExcl', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Excl.php');
        $this->assertStringContainsString('#[Assert\\GreaterThan(0)]', $content);
        $this->assertStringContainsString('#[Assert\\LessThan(10)]', $content);
        // The inclusive Range must be dropped once the bound is consumed as exclusive.
        $this->assertStringNotContainsString('#[Assert\\Range(', $content);
    }

    public function testRegexPatternIsDelimitedAndEnforcedByValidator(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // Pattern contains slashes that must be escaped against the / delimiter.
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Pat' => [
                        'type' => 'object',
                        'required' => ['path'],
                        'properties' => [
                            'path' => ['type' => 'string', 'pattern' => '^/api/v[0-9]+$'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymGenPat';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Pat.php');
        $this->assertStringContainsString('#[Assert\\Regex(', $content);

        require_once $this->outputDirectory . '/Pat.php';
        $patClass = $namespace . '\\Pat';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // The escaped slashes must yield a working pattern: matching value passes, other fails.
        $this->assertCount(0, $validator->validate(new $patClass(path: '/api/v2')));
        $this->assertGreaterThan(0, count($validator->validate(new $patClass(path: 'nope'))));
    }

    public function testOptionalScalarIsNullableWithoutNotNull(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Opt' => [
                        'type' => 'object',
                        'properties' => [
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenOpt', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Opt.php');
        $this->assertStringContainsString('public readonly ?string $note = null,', $content);
        $this->assertStringNotContainsString('#[Assert\\NotNull]', $content);
    }

    public function testEmptyDtoRendersParameterlessConstructor(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Blank' => ['type' => 'object']]],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenBlank', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Blank.php');
        $this->assertStringContainsString('public function __construct()', $content);
    }

    public function testRuntimeModeRemainsDefaultAndUnchanged(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Thing' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        // No mode argument → runtime mode: library artifacts present, no Symfony attributes.
        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenRuntime');

        $content = (string)file_get_contents($this->outputDirectory . '/Thing.php');
        $this->assertStringContainsString('GeneratedDtoInterface', $content);
        $this->assertStringContainsString('function getNormalizationMap', $content);
        $this->assertStringNotContainsString('Assert\\', $content);
    }
}
