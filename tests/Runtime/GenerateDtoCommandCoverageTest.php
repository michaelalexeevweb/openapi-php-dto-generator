<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateDtoCommandCoverageTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-gencov';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDirectory)) {
            $this->deleteDirectory($this->outputDirectory);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function buildTester(): CommandTester
    {
        $application = new Application();
        $command = new GenerateDtoCommand();
        $application->add($command);

        return new CommandTester($command);
    }

    private function writeSpec(string $contents): string
    {
        $path = $this->outputDirectory . '/spec_' . uniqid('', false) . '.yaml';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testExecuteFailsWhenFileOptionMissing(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--directory' => 'generated/test']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --file is required', $tester->getDisplay());
    }

    public function testExecuteFailsWhenDirectoryOptionMissing(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--file' => 'something.yaml']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --directory is required', $tester->getDisplay());
    }

    public function testExecuteFailsWhenNamespaceProvidedButEmpty(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => 'something.yaml',
            '--directory' => 'generated/test',
            '--namespace' => '   ',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Option --namespace cannot be empty', $tester->getDisplay());
    }

    public function testExecuteFailsWhenFileNotFound(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => $this->outputDirectory . '/does-not-exist.yaml',
            '--directory' => 'generated/test',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('File not found', $tester->getDisplay());
    }

    public function testExecuteReportsRuntimeExceptionAsFailure(): void
    {
        // Discriminator with empty propertyName triggers a RuntimeException inside generation.
        $spec = $this->writeSpec(
            <<<'YAML'
                openapi: 3.0.0
                info:
                  title: Bad discriminator
                  version: 1.0.0
                paths: { }
                components:
                  schemas:
                    Animal:
                      type: object
                      discriminator:
                        propertyName: ''
                        mapping:
                          dog: '#/components/schemas/Animal'
                      properties:
                        kind:
                          type: string
                YAML,
        );

        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => $spec,
            '--directory' => $this->outputDirectory . '/cli-out',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('propertyName must be a non-empty string', $tester->getDisplay());
    }

    public function testExecuteSucceedsWithExplicitNamespaceAndGeneratorDirectory(): void
    {
        $spec = $this->writeSpec(
            <<<'YAML'
                openapi: 3.0.0
                info:
                  title: CLI success
                  version: 1.0.0
                paths: { }
                components:
                  schemas:
                    CliModel:
                      type: object
                      required:
                        - name
                      properties:
                        name:
                          type: string
                YAML,
        );

        $outDir = $this->outputDirectory . '/cli-generated';
        $generatorDir = $this->outputDirectory . '/cli-common';

        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => $spec,
            '--directory' => $outDir,
            '--namespace' => 'Cli\Generated',
            '--dto-generator-directory' => $generatorDir,
            '--dto-generator-namespace' => 'Cli\Common',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Generated', $tester->getDisplay());
        $this->assertFileExists($outDir . '/CliModel.php');
        $this->assertFileExists($generatorDir . '/UnsetValue.php');

        $content = (string)file_get_contents($outDir . '/CliModel.php');
        $this->assertStringContainsString('namespace Cli\Generated;', $content);
    }

    public function testExecuteDerivesGeneratorNamespaceFromCustomDirectory(): void
    {
        $spec = $this->writeSpec(
            <<<'YAML'
                openapi: 3.0.0
                info:
                  title: CLI derived namespace
                  version: 1.0.0
                paths: { }
                components:
                  schemas:
                    DerivedModel:
                      type: object
                      required:
                        - id
                      properties:
                        id:
                          type: integer
                YAML,
        );

        $outDir = $this->outputDirectory . '/cli-derived';
        $generatorDir = $this->outputDirectory . '/cli-derived-common';

        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => $spec,
            '--directory' => $outDir,
            '--dto-generator-directory' => $generatorDir,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outDir . '/DerivedModel.php');
        $this->assertFileExists($generatorDir . '/GeneratedDtoInterface.php');
    }

    public function testGenerateFromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->generator->generateFromFile(
            $this->outputDirectory . '/missing.yaml',
            $this->outputDirectory,
            'TestNamespace',
        );
    }

    public function testGenerateFromFileThrowsWhenRootIsNotArray(): void
    {
        $spec = $this->writeSpec('"just a scalar string"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAPI root must be an object/array.');

        $this->generator->generateFromFile($spec, $this->outputDirectory, 'TestNamespace');
    }

    public function testDiscriminatorPropertyNameMustBeNonEmptyString(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 't', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DiscBase' => [
                        'type' => 'object',
                        'discriminator' => [
                            'propertyName' => 123,
                            'mapping' => ['dog' => '#/components/schemas/DiscBase'],
                        ],
                        'properties' => ['kind' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('propertyName must be a non-empty string');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsDiscA');
    }

    public function testDiscriminatorMappingMustBeNonEmptyMap(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 't', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DiscBase' => [
                        'type' => 'object',
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => [],
                        ],
                        'properties' => ['kind' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapping must be a non-empty map');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsDiscB');
    }

    public function testDiscriminatorMappingValueMustBeRefString(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 't', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DiscBase' => [
                        'type' => 'object',
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['dog' => ['not' => 'a string']],
                        ],
                        'properties' => ['kind' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a schema $ref string');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsDiscC');
    }

    public function testDiscriminatorMappingValueMustReferenceComponentsSchemas(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 't', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DiscBase' => [
                        'type' => 'object',
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['dog' => '#/components/responses/Foo'],
                        ],
                        'properties' => ['kind' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must reference #/components/schemas/*');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsDiscD');
    }

    public function testTemporalAndBinaryRefTypesResolveToPhpTypes(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'temporal', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DateString' => ['type' => 'string', 'format' => 'date-time'],
                    'BinaryString' => ['type' => 'string', 'format' => 'binary'],
                    'TemporalHolder' => [
                        'type' => 'object',
                        'required' => ['createdAt', 'upload', 'inlineDate', 'history', 'uploads'],
                        'properties' => [
                            'createdAt' => ['$ref' => '#/components/schemas/DateString'],
                            'upload' => ['$ref' => '#/components/schemas/BinaryString'],
                            'inlineDate' => ['type' => 'string', 'format' => 'date'],
                            'history' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/DateString'],
                            ],
                            'uploads' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/BinaryString'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsTemporal');

        $file = $this->outputDirectory . '/TemporalHolder.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('DateTimeImmutable $createdAt', $content);
        $this->assertStringContainsString('UploadedFile $upload', $content);
        $this->assertStringContainsString('DateTimeImmutable $inlineDate', $content);
        $this->assertStringContainsString('@var array<DateTimeImmutable>', $content);
        $this->assertStringContainsString('@var array<UploadedFile>', $content);
    }

    public function testTemporalRefInsideSingleAllOf(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'temporal allof', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DateString' => ['type' => 'string', 'format' => 'date'],
                    'AllOfTemporal' => [
                        'type' => 'object',
                        'required' => ['when'],
                        'properties' => [
                            'when' => [
                                'allOf' => [
                                    ['$ref' => '#/components/schemas/DateString'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsAllOfTemporal');

        $file = $this->outputDirectory . '/AllOfTemporal.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('DateTimeImmutable $when', $content);
    }

    public function testMultiTypeUnionAndNullableMultiType(): void
    {
        $openApi = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'multi-type', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'MultiTypeModel' => [
                        'type' => 'object',
                        'required' => ['value'],
                        'properties' => [
                            'value' => ['type' => ['string', 'integer']],
                            'nullableValue' => ['type' => ['number', 'boolean', 'null']],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsMulti');

        $file = $this->outputDirectory . '/MultiTypeModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('string|int $value', $content);
        $this->assertStringContainsString('float|bool', $content);
    }

    public function testAdditionalPropertiesMapTypes(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'maps', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'MapModel' => [
                        'type' => 'object',
                        'required' => ['stringMap', 'freeMap'],
                        'properties' => [
                            'stringMap' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string'],
                            ],
                            'freeMap' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            'unionMap' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => ['string', 'integer']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsMaps');

        $file = $this->outputDirectory . '/MapModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        // additionalProperties maps are string-keyed value types, not lists.
        $this->assertStringContainsString('@var array<string, string>', $content);
        $this->assertStringContainsString('@var array<string, mixed>', $content);
    }

    public function testComposedUnionPropertyWithNullVariant(): void
    {
        $openApi = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'composed union', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                    'ComposedModel' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'pet' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Pet'],
                                    ['type' => 'null'],
                                ],
                            ],
                            'mixedField' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsComposed');

        $file = $this->outputDirectory . '/ComposedModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('Pet', $content);
        $this->assertStringContainsString('string|int', $content);
    }

    public function testDtoSchemaNameCollisionThrows(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'collision', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Sample_Model' => [
                        'type' => 'object',
                        'required' => ['a'],
                        'properties' => ['a' => ['type' => 'string']],
                    ],
                    'SampleModel' => [
                        'type' => 'object',
                        'required' => ['b'],
                        'properties' => ['b' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO schema name collision');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsCollide');
    }

    public function testEnumNameCollisionThrows(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'enum collision', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status_Enum' => [
                        'type' => 'string',
                        'enum' => ['a', 'b'],
                    ],
                    'StatusEnum' => [
                        'type' => 'string',
                        'enum' => ['c', 'd'],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Enum schema name collision');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsEnumCollide');
    }

    public function testPropertyOverrideConflictThrows(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'override conflict', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'OverrideBase' => [
                        'type' => 'object',
                        'required' => ['field'],
                        'properties' => ['field' => ['type' => 'string']],
                    ],
                    'OverrideChild' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/OverrideBase'],
                            [
                                'type' => 'object',
                                'required' => ['field'],
                                'properties' => ['field' => ['type' => 'integer']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Property override conflict');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsOverride');
    }

    public function testIntegerEnumWithNonIntegerValueThrows(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'bad int enum', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'BadIntEnum' => [
                        'type' => 'integer',
                        'enum' => [1, 'two'],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integer enum contains non-integer value');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsBadEnum');
    }

    public function testEnumDefaultValueRendersEnumCase(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'enum default', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'PriorityEnum' => [
                        'type' => 'integer',
                        'enum' => [1, 2, 3],
                    ],
                    'EnumDefaultModel' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'priority' => [
                                '$ref' => '#/components/schemas/PriorityEnum',
                                'default' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsEnumDefault');

        $file = $this->outputDirectory . '/EnumDefaultModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('PriorityEnum::', $content);
    }

    public function testEnumVarnamesAndDescriptionsDriveCaseNames(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'enum varnames', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'x-enum-varnames' => ['Inactive', 'Active', 'Banned'],
                        'x-enum-descriptions' => ['Not active.', 'Currently active', 'Banned by admin.'],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsEnumVarnames');

        $content = (string)file_get_contents($this->outputDirectory . '/Status.php');
        // x-enum-varnames map positionally onto the values.
        $this->assertStringContainsString('case Inactive = 0;', $content);
        $this->assertStringContainsString('case Active = 1;', $content);
        $this->assertStringContainsString('case Banned = 2;', $content);
        // x-enum-descriptions render as a docblock above the case.
        $this->assertStringContainsString('Currently active', $content);
        $this->assertMatchesRegularExpression('/Banned by admin\.\s+\*\/\s+case Banned/s', $content);
    }

    public function testEnumVarnamesLengthMismatchFallsBackToValueNames(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'enum mismatch', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Mismatch' => [
                        'type' => 'integer',
                        'enum' => [1, 2, 3],
                        'x-enum-varnames' => ['One', 'Two'],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsEnumMismatch');

        $content = (string)file_get_contents($this->outputDirectory . '/Mismatch.php');
        // Wrong-length x-enum-varnames is ignored; case names fall back to value-derived ones.
        $this->assertStringContainsString('case VALUE_1 = 1;', $content);
        $this->assertStringNotContainsString('case One', $content);
    }

    public function testEnumDefaultUsesVarnameCase(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'enum default varname', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'x-enum-varnames' => ['Inactive', 'Active', 'Banned'],
                    ],
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'status' => ['$ref' => '#/components/schemas/Status', 'default' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsEnumDefaultVarname');

        $content = (string)file_get_contents($this->outputDirectory . '/Holder.php');
        // The default must reference the varname-derived case, not a value-derived one.
        $this->assertStringContainsString('Status::Active', $content);
        $this->assertStringNotContainsString('Status::VALUE_1', $content);
    }

    public function testScalarDefaultValuesRenderForAllTypes(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'scalar defaults', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ScalarDefaults' => [
                        'type' => 'object',
                        'required' => ['anchor'],
                        'properties' => [
                            'anchor' => ['type' => 'string'],
                            'count' => ['type' => 'integer', 'default' => 5],
                            'ratio' => ['type' => 'number', 'default' => 1.5],
                            'enabled' => ['type' => 'boolean', 'default' => false],
                            'label' => ['type' => 'string', 'default' => "it's here"],
                            'items' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsScalarDefaults');

        $file = $this->outputDirectory . '/ScalarDefaults.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('= 5', $content);
        $this->assertStringContainsString('= 1.5', $content);
        $this->assertStringContainsString('= false', $content);
        $this->assertStringContainsString("\\'s here", $content);
    }

    public function testValidationConstraintsForUnionAllOfNotAndReadOnly(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'constraints matrix', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ConstraintMatrix' => [
                        'type' => 'object',
                        'required' => ['choice'],
                        'properties' => [
                            'choice' => [
                                'oneOf' => [
                                    ['type' => 'string', 'minLength' => 2],
                                    ['type' => 'integer', 'minimum' => 1],
                                ],
                            ],
                            'combined' => [
                                'type' => 'string',
                                'allOf' => [
                                    ['minLength' => 2],
                                    ['maxLength' => 5],
                                ],
                            ],
                            'excluded' => [
                                'type' => 'string',
                                'not' => ['pattern' => '^x'],
                            ],
                            'readonlyField' => [
                                'type' => 'string',
                                'readOnly' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsConstraintMatrix');

        $file = $this->outputDirectory . '/ConstraintMatrix.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('getConstraints', $content);
        $this->assertStringContainsString('oneOf', $content);
        $this->assertStringContainsString('readOnly', $content);
    }

    public function testParameterRefAndHttpMethodFilteringInQueryParams(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'param refs', 'version' => '1.0.0'],
            'paths' => [
                '/widgets/{id}/actions' => [
                    'get' => [
                        'parameters' => [
                            ['$ref' => '#/components/parameters/IdParam'],
                            [
                                'name' => 'verbose',
                                'in' => 'query',
                                'required' => 'true',
                                'schema' => ['type' => 'boolean'],
                            ],
                            [
                                'name' => 'ignoredHeader',
                                'in' => 'header',
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => [
                'parameters' => [
                    'IdParam' => [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'schemas' => [],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsParamRefs');

        $file = $this->outputDirectory . '/WidgetsActionsGetQueryParams.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('private readonly string $id', $content);
        $this->assertStringContainsString('$verbose', $content);
        // Header parameters are now supported and bound to the 'header' source.
        $this->assertStringContainsString('$ignoredHeader', $content);
        $this->assertStringContainsString('public function isIgnoredHeaderInHeader(): bool', $content);
        $this->assertStringContainsString("\$sources['ignoredHeader'] = 'header';", $content);
        $this->assertStringContainsString("\$sources['id'] = 'path';", $content);
        $this->assertStringContainsString("\$sources['verbose'] = 'query';", $content);
    }

    public function testQueryParamsDocBlockReferencesSourceEndpoint(): void
    {
        // A path/query parameter DTO gets a `Route: METHOD /path` doc line so the endpoint it
        // belongs to is discoverable; a plain component schema (not derived from a path) must not.
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'source endpoint', 'version' => '1.0.0'],
            'paths' => [
                '/widgets/{id}/actions' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Widget' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsSourceEndpoint');

        $queryParams = (string)file_get_contents($this->outputDirectory . '/WidgetsActionsGetQueryParams.php');
        $this->assertStringContainsString(' * Route: GET /widgets/{id}/actions', $queryParams);

        // The `Route:` line sits inside the auto-generated notice block, above the class. Generating
        // from an in-memory array (no spec file) carries no `Spec:` line.
        $this->assertMatchesRegularExpression(
            '~Do not edit this file manually\.\n \*\n \* Route: GET /widgets/\{id}/actions\n \*/~',
            $queryParams,
        );
        $this->assertStringNotContainsString('Spec:', $queryParams);

        // A plain schema DTO is neither endpoint-derived nor synthesised, so no Route/From lines.
        $widget = (string)file_get_contents($this->outputDirectory . '/Widget.php');
        $this->assertStringNotContainsString('Route:', $widget);
        $this->assertStringNotContainsString('From:', $widget);
    }

    public function testGeneratedQueryParamsPassesProjectCodeStyle(): void
    {
        // The `@see` doc line must not break generated code style: the file has to be a fixed point
        // of the project's php-cs-fixer ruleset (dry-run reports nothing to fix).
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'style', 'version' => '1.0.0'],
            'paths' => [
                '/orders/shipment-report/{date}' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'date',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => ['schemas' => []],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsStyle');

        $file = $this->outputDirectory
            . '/OrdersShipmentReportGetQueryParams.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString(
            ' * Route: GET /orders/shipment-report/{date}',
            (string)file_get_contents($file),
        );

        $this->assertPassesProjectCodeStyle($file);
    }

    public function testQueryParamsDocBlockReferencesSpecFile(): void
    {
        // When generated from a file, a DTO records the spec it came from as a `Spec:` line whose
        // path is a PhpStorm-navigable @see relative to the generated file, so Ctrl+B opens the
        // source yaml. The path geometry is machine-independent. The doc line must stay style-clean.
        $specDir = $this->outputDirectory . '/spec-src';
        mkdir($specDir, 0o755, true);
        $spec = $specDir . '/orders.yaml';
        file_put_contents($spec, <<<'YAML'
            openapi: 3.0.3
            info:
              title: orders
              version: 1.0.0
            paths:
              /orders/shipment-report/{date}:
                get:
                  parameters:
                    - name: date
                      in: path
                      required: true
                      schema:
                        type: string
                  responses:
                    '200':
                      description: OK
            components:
              schemas:
                Widget:
                  type: object
                  required:
                    - id
                  properties:
                    id:
                      type: string
            YAML);

        $tester = $this->buildTester();
        $out = $this->outputDirectory . '/spec-out';
        $exitCode = $tester->execute([
            '--file' => $spec,
            '--directory' => $out,
            '--namespace' => 'NsSpecFile',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());

        $file = $out . '/OrdersShipmentReportGetQueryParams.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        // Route reference followed by the spec as a PhpStorm-navigable @see path, relative to this
        // generated file's directory (spec-out/ -> ../spec-src/orders.yaml).
        $this->assertMatchesRegularExpression(
            '~ \* Route: GET /orders/shipment-report/\{date}\n \* Spec: @see \.\./spec-src/orders\.yaml\n~',
            $content,
        );

        // The `Spec:` line is emitted for every DTO with a known source file, not only param DTOs.
        $widget = (string)file_get_contents($out . '/Widget.php');
        $this->assertStringContainsString(' * Spec: @see ../spec-src/orders.yaml', $widget);
        $this->assertStringNotContainsString('Route:', $widget);

        $this->assertPassesProjectCodeStyle($file);
    }

    public function testDocBlockMetadataAcrossDtoKinds(): void
    {
        // Route (request/response bodies), From (synthesised inline nested object / array-item), and
        // the absence of both on a plain schema. Uses generateFromArray, so no `Spec:` line appears.
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'kinds', 'version' => '1.0.0'],
            'paths' => [
                '/orders' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => ['note' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => ['ok' => ['type' => 'boolean']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Order' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => [
                                'type' => 'object',
                                'properties' => ['city' => ['type' => 'string']],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => ['label' => ['type' => 'string']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'NsKinds');

        $request = (string)file_get_contents($this->outputDirectory . '/OrdersPostRequest.php');
        $this->assertStringContainsString(' * Route: POST /orders', $request);

        $response = (string)file_get_contents($this->outputDirectory . '/Orders200.php');
        $this->assertStringContainsString(' * Route: POST /orders', $response);

        // Inline nested object → From: Owner.property; inline array-of-objects item → …property[].
        $address = (string)file_get_contents($this->outputDirectory . '/OrderAddress.php');
        $this->assertStringContainsString(' * From: Order.address', $address);
        $this->assertStringNotContainsString('Route:', $address);

        $tagsItem = (string)file_get_contents($this->outputDirectory . '/OrderTagsItem.php');
        $this->assertStringContainsString(' * From: Order.tags[]', $tagsItem);

        // The plain parent schema carries neither.
        $order = (string)file_get_contents($this->outputDirectory . '/Order.php');
        $this->assertStringNotContainsString('Route:', $order);
        $this->assertStringNotContainsString('From:', $order);
    }

    public function testDocBlockMetadataForEnums(): void
    {
        // Enums get the same doc metadata as DTOs: `Spec:` for every enum with a source file, and
        // `From:` for enums synthesised from an inline (property / array-item) schema. Generated
        // from a file so the navigable `Spec:` @see path is present and must stay style-clean.
        $specDir = $this->outputDirectory . '/enum-src';
        mkdir($specDir, 0o755, true);
        $spec = $specDir . '/enums.yaml';
        file_put_contents($spec, <<<'YAML'
            openapi: 3.0.3
            info:
              title: enums
              version: 1.0.0
            paths: {}
            components:
              schemas:
                Color:
                  type: string
                  enum: [red, green, blue]
                Order:
                  type: object
                  properties:
                    status:
                      type: string
                      enum: [new, paid, shipped]
                    labels:
                      type: array
                      items:
                        type: string
                        enum: [a, b, c]
            YAML);

        $tester = $this->buildTester();
        $out = $this->outputDirectory . '/enum-out';
        $exitCode = $tester->execute(['--file' => $spec, '--directory' => $out, '--namespace' => 'NsEnums']);
        $this->assertSame(0, $exitCode, $tester->getDisplay());

        // Top-level component enum: Spec only, no From.
        $colorFile = $out . '/Color.php';
        $color = (string)file_get_contents($colorFile);
        $this->assertStringContainsString(' * Spec: @see ../enum-src/enums.yaml', $color);
        $this->assertStringNotContainsString('From:', $color);

        // Inline property enum → From: Owner.property (+ Spec).
        $status = (string)file_get_contents($out . '/OrderStatus.php');
        $this->assertStringContainsString(' * From: Order.status', $status);
        $this->assertStringContainsString(' * Spec: @see ../enum-src/enums.yaml', $status);

        // Inline array-item enum → From: Owner.property[].
        $labelsItem = (string)file_get_contents($out . '/OrderLabelsItem.php');
        $this->assertStringContainsString(' * From: Order.labels[]', $labelsItem);

        $this->assertPassesProjectCodeStyle($colorFile);
    }

    /**
     * Asserts a generated file is a fixed point of the project's php-cs-fixer ruleset (a dry-run
     * finds nothing to fix). Skips when the fixer binary is not installed.
     */
    private function assertPassesProjectCodeStyle(string $file): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $binary = $projectRoot . '/vendor/bin/php-cs-fixer';
        if (!is_file($binary)) {
            $this->markTestSkipped('php-cs-fixer binary not installed.');
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
            . ' fix ' . escapeshellarg($file)
            . ' --dry-run --path-mode=override --using-cache=no'
            . ' --config=' . escapeshellarg($projectRoot . '/.php-cs-fixer.php')
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolveUnion(array $propertySchema, string $keyword): mixed
    {
        $method = new ReflectionMethod($this->generator, 'resolveComposedUnionPropertyType');

        return $method->invoke($this->generator, $propertySchema, $keyword, 'Owner', 'prop');
    }

    public function testResolveComposedUnionPropertyTypeBranches(): void
    {
        // Non-array variants → mixed (nullability preserved from the schema).
        $this->assertSame(['mixed', false], $this->resolveUnion(['oneOf' => 'nope'], 'oneOf'));
        $this->assertSame(['mixed', true], $this->resolveUnion(['oneOf' => 'nope', 'nullable' => true], 'oneOf'));

        // A non-array branch is skipped; the scalar branch still resolves.
        $this->assertSame(['string', false], $this->resolveUnion(['oneOf' => [null, ['type' => 'string']]], 'oneOf'));

        // A `type: null` branch adds null and contributes no type → union collapses to mixed|null.
        $this->assertSame(['mixed', true], $this->resolveUnion(['oneOf' => [['type' => 'null']]], 'oneOf'));

        // A nullable branch flips the whole union nullable.
        $this->assertSame(
            ['string|int', true],
            $this->resolveUnion(['oneOf' => [['type' => 'string', 'nullable' => true], ['type' => 'integer']]], 'oneOf'),
        );

        // A branch that resolves to mixed collapses the whole union to mixed.
        $this->assertSame(['mixed', false], $this->resolveUnion(['oneOf' => [[], ['type' => 'string']]], 'oneOf'));

        // An array-typed branch is flattened to the bare `array` type in the union.
        $this->assertSame(
            ['array|int', false],
            $this->resolveUnion(
                ['oneOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'integer']]],
                'oneOf',
            ),
        );
    }

    public function testExecuteDefaultsToRuntimeModeAndSucceeds(): void
    {
        // No --attributes → mode defaults to runtime; a valid spec generates successfully.
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => __DIR__ . '/../fixtures/additional-properties.yaml',
            '--directory' => $this->outputDirectory . '/cli-runtime',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Generated', $tester->getDisplay());
    }

    public function testExecuteDtoGeneratorDirectoryDefaultsToCommon(): void
    {
        // --dto-generator-directory present without a value → defaults to "Common" and copies
        // the runtime services there.
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => __DIR__ . '/../fixtures/additional-properties.yaml',
            '--directory' => $this->outputDirectory . '/cli-common',
            '--dto-generator-directory' => null,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->outputDirectory . '/cli-common/Common/DtoDeserializer.php');
    }

    public function testExecuteWithPsr7PrintsBridgeNote(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([
            '--file' => __DIR__ . '/../fixtures/additional-properties.yaml',
            '--directory' => $this->outputDirectory . '/cli-psr7',
            '--dto-generator-directory' => null,
            '--with-psr7' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('symfony/psr-http-message-bridge', $tester->getDisplay());
    }

    public function testExecuteExtractsInlineResponseRequestAndParameterSchemas(): void
    {
        // A spec with inline request/response object schemas and path/query parameters exercises
        // registerDocumentSchemas' inline block plus extractInline{Response,Request,Parameter}Schemas
        // and the parameter resolution helpers.
        $tester = $this->buildTester();
        $out = $this->outputDirectory . '/cli-inline';
        $exitCode = $tester->execute([
            '--file' => __DIR__ . '/../fixtures/inline-schemas.yaml',
            '--directory' => $out,
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        // Inline request body object → its own DTO (named from path + method).
        $this->assertFileExists($out . '/WidgetsPostRequest.php');
        // Inline response object → its own DTO (named from path + status code).
        $this->assertFileExists($out . '/Widgets200.php');
        // Path/query parameters → a params DTO.
        $this->assertFileExists($out . '/WidgetsGetQueryParams.php');
    }
}
