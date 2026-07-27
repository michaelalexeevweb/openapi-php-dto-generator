<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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
                $path = $this->outputDirectory . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    foreach (glob($path . '/*') ?: [] as $nested) {
                        @unlink($nested);
                    }
                    @rmdir($path);
                    continue;
                }
                @unlink($path);
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

        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints as Assert;', $content);
        $this->assertStringContainsString('#[Assert\NotNull]', $content);
        $this->assertStringContainsString('#[Assert\Length(min: 2, max: 50)]', $content);
        $this->assertStringContainsString('#[Assert\Range(min: 0, max: 120)]', $content);
        $this->assertStringContainsString('#[Assert\GreaterThan(0)]', $content);
        $this->assertStringContainsString('#[Assert\DivisibleBy(0.5)]', $content);
        $this->assertStringContainsString('#[Assert\Count(min: 1, max: 5)]', $content);
        $this->assertStringContainsString('#[Assert\Unique]', $content);
        $this->assertStringContainsString('#[Assert\Valid]', $content);
        $this->assertStringContainsString('private readonly string $name,', $content);
    }

    public function testSymfonyModeEmitsSerializedNameWhenPropertyDiffersFromOpenApiName(): void
    {
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGenSerialized', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/User.php');

        // created_at -> camelCased property createdAt with a SerializedName mapping back.
        $this->assertStringContainsString('use Symfony\Component\Serializer\Attribute\SerializedName;', $content);
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

        $userClass = $namespace . '\User';
        $addressClass = $namespace . '\Address';

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // Only required properties are constructor arguments; the rest are set.
        $validAddress = new $addressClass(city: 'NYC');
        $validAddress->setZip('10001');
        $valid = new $userClass(name: 'Jo', age: 30);
        $valid->setScore(1.5);
        $valid->setTags(['a', 'b']);
        $valid->setAddress($validAddress);
        $valid->setOthers([]);
        $this->assertCount(0, $validator->validate($valid));

        $invalidAddress = new $addressClass(city: '');
        $invalidAddress->setZip('abc');
        $invalid = new $userClass(name: 'X', age: 999);
        $invalid->setScore(1.5);
        $invalid->setTags(['x', 'x']);
        $invalid->setAddress($invalidAddress);
        $invalid->setOthers([]);
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
        $this->assertStringContainsString('#[Assert\Range(min: 5)]', $content);
        $this->assertStringContainsString('#[Assert\Range(max: 9)]', $content);
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
        $this->assertStringContainsString('#[Assert\GreaterThan(0)]', $content);
        $this->assertStringContainsString('#[Assert\LessThan(10)]', $content);
        // The inclusive Range must be dropped once the bound is consumed as exclusive.
        $this->assertStringNotContainsString('#[Assert\Range(', $content);
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
        $this->assertStringContainsString('#[Assert\Regex(', $content);

        require_once $this->outputDirectory . '/Pat.php';
        $patClass = $namespace . '\Pat';
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
        $this->assertStringContainsString('private ?string $note = null;', $content);
        $this->assertStringNotContainsString('#[Assert\NotNull]', $content);
    }

    public function testSimpleRequiredScalarDoesNotEmitRuntimeCallbackBlock(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Simple' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenSimple', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/Simple.php');
        $this->assertStringNotContainsString('use OpenapiPhpDtoGenerator\Service\DtoValidator;', $content);
        $this->assertStringNotContainsString('#[Assert\Callback]', $content);
        $this->assertStringNotContainsString('OPENAPI_VALIDATION_CONSTRAINTS', $content);
    }

    public function testMinPropertiesOnNestedDtoIsValidatedByCallbackNotAssertCount(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Inner' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => ['type' => 'string'],
                            'b' => ['type' => 'string'],
                        ],
                    ],
                    'Outer' => [
                        'type' => 'object',
                        'required' => ['inner'],
                        'properties' => [
                            'inner' => [
                                'allOf' => [['$ref' => '#/components/schemas/Inner']],
                                'minProperties' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymGenNestedBounds';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');

        $outerPath = $this->outputDirectory . '/Outer.php';
        $outerContent = (string)file_get_contents($outerPath);
        $this->assertStringNotContainsString('#[Assert\Count(', $outerContent);
        $this->assertStringContainsString('OPENAPI_VALIDATION_CONSTRAINTS', $outerContent);

        require_once $this->outputDirectory . '/Inner.php';
        require_once $outerPath;

        $innerClass = $namespace . '\Inner';
        $outerClass = $namespace . '\Outer';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $innerFull = new $innerClass();
        $innerFull->setA('x');
        $innerFull->setB('y');
        $this->assertCount(0, $validator->validate(new $outerClass(inner: $innerFull)));
        $innerOne = new $innerClass();
        $innerOne->setA('x');
        $violations = $validator->validate(new $outerClass(inner: $innerOne));
        $this->assertGreaterThan(0, count($violations));
    }

    public function testCallbackCodeSpreadsErrorsAndSkipsUnusedTrackingVariables(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Lean' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['const' => 'hit'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenLeanCallback', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Lean.php');

        // Errors are collected with array unpacking, never array_merge.
        $this->assertStringContainsString('$errors = [', $content);
        $this->assertStringNotContainsString('array_merge(', $content);

        // additionalProperties / unevaluatedProperties / unevaluatedItems / prefixItems are absent,
        // so their bookkeeping variables must not be emitted at all.
        $this->assertStringNotContainsString('$definedProperties', $content);
        $this->assertStringNotContainsString('$patternMatchedProperties', $content);
        $this->assertStringNotContainsString('$evaluatedItemIndices', $content);
        $this->assertStringNotContainsString('$prefixCount', $content);
    }

    public function testCallbackTrackingVariablesAreEmittedWhenTheirKeywordsAreUsed(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Full' => [
                        'type' => 'object',
                        'required' => ['list', 'bag'],
                        'properties' => [
                            'list' => [
                                'type' => 'array',
                                'prefixItems' => [['type' => 'string']],
                                'items' => ['type' => 'integer'],
                                'unevaluatedItems' => false,
                            ],
                            'bag' => [
                                'type' => 'object',
                                'properties' => ['a' => ['type' => 'string']],
                                'patternProperties' => ['^x-' => ['type' => 'integer']],
                                'unevaluatedProperties' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenFullCallback', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Full.php');

        $this->assertStringContainsString('$definedProperties = [];', $content);
        $this->assertStringContainsString('$patternMatchedProperties = [];', $content);
        $this->assertStringContainsString('$evaluatedItemIndices = [];', $content);
        $this->assertStringContainsString('$prefixCount = 0;', $content);
        $this->assertStringContainsString('[...$definedProperties, ...$patternMatchedProperties]', $content);
    }

    public function testCallbackHelperClassesAreImportedNotFullyQualified(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Stamped' => [
                        'type' => 'object',
                        'required' => ['when'],
                        'properties' => [
                            'when' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'not' => ['const' => 'nope'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenImports', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Stamped.php');

        $this->assertStringContainsString('use DateTimeInterface;', $content);
        $this->assertStringContainsString('instanceof DateTimeInterface', $content);
        $this->assertStringNotContainsString('\DateTimeInterface', $content);

        // This DTO cannot hold a generated enum, so the unwrapping branch is not emitted at all.
        $this->assertStringNotContainsString('BackedEnum', $content);

        // Nested DTO values are read through their own public payload method, not via reflection.
        $this->assertStringNotContainsString('Reflection', $content);
        $this->assertStringContainsString('public function toOpenApiValidationPayload(): array', $content);
        $this->assertStringContainsString("method_exists(\$value, 'toOpenApiValidationPayload')", $content);
    }

    public function testEnumUnwrappingIsEmittedOnlyForDtosThatCanHoldEnums(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Tagged' => [
                        'type' => 'object',
                        'required' => ['kind'],
                        'properties' => [
                            'kind' => [
                                'type' => 'string',
                                'enum' => ['a', 'b'],
                                'not' => ['const' => 'zz'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenEnumUnwrap', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Tagged.php');

        $this->assertStringContainsString('use BackedEnum;', $content);
        $this->assertStringContainsString('instanceof BackedEnum', $content);
        // No date/date-time anywhere, so the temporal branch stays out.
        $this->assertStringNotContainsString('DateTimeInterface', $content);
    }

    public function testCallbackEmitsOnlyTheKeywordValuesTheSpecUses(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Narrow' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'format' => 'duration'],
                                'contains' => ['const' => 'P1D'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenNarrow', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Narrow.php');

        // type: only `string` is ever asserted — no arms for the other JSON types, no union branch.
        $this->assertStringContainsString("'string' => is_string(\$value)", $content);
        $this->assertStringNotContainsString("'integer' => is_int(\$value)", $content);
        $this->assertStringNotContainsString('is_array($type)', $content);

        // format: only the duration helper, none of the other format checks.
        $this->assertStringContainsString('isValidOpenApiDuration', $content);
        $this->assertStringNotContainsString('isValidOpenApiUuid', $content);
        $this->assertStringNotContainsString('FILTER_VALIDATE_EMAIL', $content);

        // no numeric format, no content encoding, no maxContains in the spec
        $this->assertStringNotContainsString('validateOpenApiNumericFormat', $content);
        $this->assertStringNotContainsString('decodeOpenApiContent', $content);
        $this->assertStringNotContainsString('maxContains', $content);
        $this->assertStringContainsString('$minContains = 1;', $content);
    }

    public function testNestedDtoPayloadKeepsOpenApiNamesWithoutReflection(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['inner'],
                        'properties' => [
                            'inner' => [
                                'type' => 'object',
                                'properties' => [
                                    'keep_me' => ['type' => 'string'],
                                    'drop_me' => ['type' => 'string'],
                                ],
                                'dependentRequired' => ['keep_me' => ['drop_me']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymGenNestedNames';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');

        $innerPath = $this->outputDirectory . '/HolderInner.php';
        require_once $innerPath;
        require_once $this->outputDirectory . '/Holder.php';

        $holder = $namespace . '\Holder';
        $inner = $namespace . '\HolderInner';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // The dependency is expressed with the OpenAPI (snake_case) names, so the payload the
        // enclosing DTO inspects must use them too — not the camelCase PHP property names.
        $innerKept = new $inner();
        $innerKept->setKeepMe('x');
        $violations = $validator->validate(new $holder(inner: $innerKept));
        $this->assertGreaterThan(0, count($violations));
        $this->assertStringContainsString('field "drop_me" is required when keep_me is present', (string)$violations);

        $innerBoth = new $inner();
        $innerBoth->setKeepMe('x');
        $innerBoth->setDropMe('y');
        $this->assertCount(0, $validator->validate(new $holder(inner: $innerBoth)));
    }

    public function testFreeFormObjectItemsStayMapsSoNestedRequiredIsValidated(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Rows' => [
                        'type' => 'object',
                        'required' => ['rows'],
                        'properties' => [
                            'rows' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['a'],
                                    'additionalProperties' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymGenFreeFormItems';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');

        // A free-form item object must not become a DTO class with an empty constructor.
        $this->assertFileDoesNotExist($this->outputDirectory . '/RowsRowsItem.php');

        $path = $this->outputDirectory . '/Rows.php';
        $content = (string)file_get_contents($path);
        $this->assertStringContainsString('@param array<array<string, mixed>> $rows', $content);

        require_once $path;
        $fqcn = $namespace . '\Rows';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(rows: [['a' => 1]])));
        $violations = $validator->validate(new $fqcn(rows: [['b' => 1]]));
        $this->assertGreaterThan(0, count($violations));
        $this->assertStringContainsString('field "rows"[0].a is required', (string)$violations);
    }

    public function testAnyOfSchemaBecomesInterfaceImplementedByItsMembers(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Cat' => ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                    'Dog' => ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'string']]],
                    'Pet' => ['anyOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']]],
                    'Owner' => ['type' => 'object', 'required' => ['pet'], 'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']]],
                ],
            ],
        ];

        $namespace = 'SymGenAnyOfUnion';
        // Own directory: these class file names repeat across tests and require_once keys on path.
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);
        $this->generator->generateFromArray($spec, $target, $namespace, 'symfony');

        $petContent = (string)file_get_contents($target . '/Pet.php');
        $this->assertStringContainsString('interface Pet', $petContent);
        $this->assertStringContainsString(' * Members: Cat|Dog', $petContent);
        $this->assertStringNotContainsString('class Pet', $petContent);
        $this->assertStringContainsString('class Cat implements Pet', (string)file_get_contents($target . '/Cat.php'));
        $this->assertStringContainsString('class Dog implements Pet', (string)file_get_contents($target . '/Dog.php'));

        foreach (['Pet', 'Cat', 'Dog', 'Owner'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        // A property typed as the union now accepts a branch instead of demanding an empty carrier.
        $ownerClass = $namespace . '\Owner';
        $catClass = $namespace . '\Cat';
        $owner = new $ownerClass(pet: new $catClass(meow: 'mrr'));
        $this->assertInstanceOf($namespace . '\Pet', $owner->getPet());
    }

    public function testDiscriminatedOneOfEmitsDiscriminatorMapAndDenormalizesPolymorphically(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Cat' => ['type' => 'object', 'required' => ['kind', 'meow'], 'properties' => ['kind' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
                    'Dog' => ['type' => 'object', 'required' => ['kind', 'bark'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
                    'Pet' => [
                        'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog'],
                        ],
                    ],
                    'Owner' => ['type' => 'object', 'required' => ['pet'], 'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']]],
                ],
            ],
        ];

        $namespace = 'SymGenDiscriminatedUnion';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);
        $this->generator->generateFromArray($spec, $target, $namespace, 'symfony');

        $petContent = (string)file_get_contents($target . '/Pet.php');
        $this->assertStringContainsString('interface Pet', $petContent);
        $this->assertStringContainsString(
            "#[DiscriminatorMap(typeProperty: 'kind', mapping: ['cat' => Cat::class, 'dog' => Dog::class])]",
            $petContent,
        );
        $this->assertStringContainsString('use Symfony\Component\Serializer\Attribute\DiscriminatorMap;', $petContent);

        foreach (['Pet', 'Cat', 'Dog', 'Owner'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            [
                new ObjectNormalizer($classMetadataFactory, new MetadataAwareNameConverter($classMetadataFactory), null, $typeExtractor),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );

        $owner = $serializer->deserialize('{"pet":{"kind":"dog","bark":"wuf"}}', $namespace . '\Owner', 'json');
        $this->assertInstanceOf($namespace . '\Dog', $owner->getPet());
        $this->assertSame(
            ['pet' => ['kind' => 'dog', 'bark' => 'wuf']],
            $serializer->normalize($owner),
        );
    }

    public function testScalarAllOfMergesIntoAttributesInsteadOfANestedObject(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Merged' => [
                        'type' => 'object',
                        'required' => ['code'],
                        'properties' => [
                            'code' => ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenScalarAllOf', 'symfony');

        // Previously: a synthesized MergedCode object class with no constraints at all.
        $this->assertFileDoesNotExist($this->outputDirectory . '/MergedCode.php');
        $content = (string)file_get_contents($this->outputDirectory . '/Merged.php');
        $this->assertStringContainsString('private readonly string $code,', $content);
        $this->assertStringContainsString('#[Assert\Length(min: 3)]', $content);
    }

    public function testNoCallbackWhenPhpTypesAlreadyEnforceTheSchema(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Nested' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
                    'Plain' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'createdAt'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            'nested' => ['$ref' => '#/components/schemas/Nested'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenNoCallback', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Plain.php');

        // int/string/DateTimeImmutable/DTO type hints already guarantee type and date format, so
        // the DTO must stay a plain data class — no constant, no interpreter, no helpers.
        $this->assertStringNotContainsString('OPENAPI_VALIDATION_CONSTRAINTS', $content);
        $this->assertStringNotContainsString('Assert\Callback', $content);
        $this->assertStringNotContainsString('validateOpenApiNode', $content);
        $this->assertStringNotContainsString('toIntConstraint', $content);
        $this->assertStringNotContainsString('toFloatConstraint', $content);
        $this->assertStringNotContainsString('ExecutionContextInterface', $content);

        $this->assertStringContainsString('private readonly int $id,', $content);
        $this->assertStringContainsString('private readonly DateTimeImmutable $createdAt,', $content);
    }

    public function testCallbackHelpersFollowTheKeywordsInUse(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Patterned' => [
                        'type' => 'object',
                        'required' => ['code'],
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'not' => ['pattern' => '^tmp-'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenHelperGating', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Patterned.php');

        // A pattern inside `not` needs the callback, but neither the length nor the numeric reader.
        $this->assertStringContainsString('must match pattern', $content);
        $this->assertStringNotContainsString('toIntConstraint', $content);
        $this->assertStringNotContainsString('toFloatConstraint', $content);
        $this->assertStringNotContainsString('mb_strlen', $content);
        $this->assertStringNotContainsString('isValidOpenApiStringFormat', $content);
    }

    public function testGeneratedCallbackKeepsSingleBlankLinesBetweenBlocks(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Spaced' => [
                        'type' => 'object',
                        'required' => ['id', 'tags'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['const' => 'hit'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenSpacing', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Spaced.php');

        // Method sections carry their own leading newline, so joining them must not double it.
        $this->assertDoesNotMatchRegularExpression('/\n\n\n/', $content);
    }

    public function testSymfonyDtosAreFinalReadonlyClasses(): void
    {
        $this->generator->generateFromArray($this->userSpec(), $this->outputDirectory, 'SymGenFinalReadonly', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/User.php');

        // Nothing extends a Symfony-mode DTO (inherited properties are flattened) and every
        // property is immutable, so the class carries both modifiers instead of repeating
        // `readonly` on each promoted parameter.
        $this->assertStringContainsString('final class User', $content);
        $this->assertStringNotContainsString('public readonly ', $content);
        $this->assertStringContainsString('private readonly string $name,', $content);
    }

    public function testExplicitAllowEmptyValueFalseBecomesNotBlank(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $queryParameter = static fn(string $name, ?bool $allowEmptyValue): array => array_filter(
            [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
                'allowEmptyValue' => $allowEmptyValue,
            ],
            static fn(mixed $value): bool => $value !== null,
        );

        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/search' => [
                    'get' => [
                        'operationId' => 'search',
                        'parameters' => [
                            $queryParameter('allowed', true),
                            $queryParameter('forbidden', false),
                            $queryParameter('silent', null),
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        $namespace = 'SymGenAllowEmptyValue';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');

        $path = $this->outputDirectory . '/SearchGetQueryParams.php';
        $content = (string)file_get_contents($path);

        // Symfony binds the query string itself, so the only expressible half of the keyword is
        // the explicit prohibition — and only on that one parameter.
        $this->assertSame(1, substr_count($content, '#[Assert\NotBlank(allowNull: true)]'));
        $this->assertStringContainsString(
            "#[Assert\\NotBlank(allowNull: true)]\n    private ?string \$forbidden",
            $content,
        );

        require_once $path;
        $fqcn = $namespace . '\SearchGetQueryParams';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $empties = new $fqcn();
        $empties->setAllowed('');
        $empties->setForbidden('');
        $empties->setSilent('');
        $violations = $validator->validate($empties);
        $this->assertCount(1, $violations);
        $this->assertSame('forbidden', $violations->get(0)->getPropertyPath());

        // "0" and a missing parameter stay valid — NotBlank must not turn into "is required".
        $zeroString = new $fqcn();
        $zeroString->setAllowed('');
        $zeroString->setForbidden('0');
        $zeroString->setSilent('');
        $this->assertCount(0, $validator->validate($zeroString));
        $this->assertCount(0, $validator->validate(new $fqcn()));
    }

    public function testComponentRequestBodyRefIsDereferencedInSymfonyMode(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/orders' => [
                    'post' => [
                        'operationId' => 'createOrder',
                        'requestBody' => ['$ref' => '#/components/requestBodies/OrderBody'],
                        'responses' => ['200' => ['$ref' => '#/components/responses/OrderCreated']],
                    ],
                ],
            ],
            'components' => [
                'requestBodies' => [
                    'OrderBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['sku'],
                                    'properties' => ['sku' => ['type' => 'string', 'minLength' => 3]],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    'OrderCreated' => [
                        'description' => 'created',
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenRefComponents', 'symfony');

        $this->assertFileExists($this->outputDirectory . '/Orders200.php');
        $request = (string)file_get_contents($this->outputDirectory . '/OrdersPostRequest.php');
        $this->assertStringContainsString('private readonly string $sku,', $request);
        $this->assertStringContainsString('#[Assert\Length(min: 3)]', $request);
    }

    public function testDocMetadataIsCarriedIntoTheConstructorDocblock(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/legacy' => [
                    'get' => [
                        'operationId' => 'legacy',
                        'parameters' => [
                            [
                                'name' => 'old',
                                'in' => 'query',
                                'deprecated' => true,
                                'description' => 'Old filter',
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Documented' => [
                        'type' => 'object',
                        'required' => ['ex'],
                        'properties' => [
                            'ex' => ['type' => 'string', 'description' => 'Some field', 'example' => 'sample'],
                            'old' => ['type' => 'string', 'deprecated' => true, 'description' => 'Legacy field.'],
                            'plain' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenDocMeta', 'symfony');

        // Promoted properties cannot carry their own docblock, so the annotations land on @param.
        $documented = (string)file_get_contents($this->outputDirectory . '/Documented.php');
        $this->assertStringContainsString(' * @param string $ex Some field Example: sample', $documented);
        $this->assertStringContainsString(' * @var ?string Deprecated. Legacy field', $documented);
        // A property without annotations gains no @param line.
        $this->assertStringNotContainsString('@param ?int $plain', $documented);

        // `deprecated`/`description` on a Parameter Object (not on its schema) travel too.
        $params = (string)file_get_contents($this->outputDirectory . '/LegacyGetQueryParams.php');
        $this->assertStringContainsString(' * @var ?string Deprecated. Old filter', $params);
    }

    public function testWebhookPayloadGetsAttributeDecoratedDto(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'webhooks' => [
                'newPet' => [
                    'post' => [
                        'operationId' => 'newPetHook',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['id'],
                                        'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGenWebhook', 'symfony');

        $content = (string)file_get_contents($this->outputDirectory . '/WebhookNewPetPostRequest.php');
        $this->assertStringContainsString('Route: POST webhook:newPet', $content);
        $this->assertStringContainsString('#[Assert\Range(min: 1)]', $content);
        $this->assertStringContainsString('private readonly int $id,', $content);
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
