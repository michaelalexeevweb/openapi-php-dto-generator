<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
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
            '--namespace' => 'Cli\\Generated',
            '--dto-generator-directory' => $generatorDir,
            '--dto-generator-namespace' => 'Cli\\Common',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Generated', $tester->getDisplay());
        $this->assertFileExists($outDir . '/CliModel.php');
        $this->assertFileExists($generatorDir . '/UnsetValue.php');

        $content = (string)file_get_contents($outDir . '/CliModel.php');
        $this->assertStringContainsString('namespace Cli\\Generated;', $content);
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

        $this->assertStringContainsString('@var array<string>', $content);
        $this->assertStringContainsString('@var array<mixed>', $content);
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
}
