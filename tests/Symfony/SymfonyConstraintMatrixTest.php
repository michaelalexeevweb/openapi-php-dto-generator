<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Mirrors the runtime constraint coverage for the Symfony attribute mode: for each OpenAPI
 * constraint we assert the expected #[Assert\*] attribute is generated AND that a real
 * Symfony validator enforces it (valid value passes, invalid value produces a violation).
 */
final class SymfonyConstraintMatrixTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony-matrix';

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
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: class-string}
     */
    private function generateSingleFieldDto(string $className, array $propertySchema): array
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    $className => [
                        'type' => 'object',
                        'required' => ['v'],
                        'properties' => ['v' => $propertySchema],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymMatrix', 'symfony');

        $path = $this->outputDirectory . '/' . $className . '.php';
        $content = (string)file_get_contents($path);
        require_once $path;

        /** @var class-string $fqcn */
        $fqcn = 'SymMatrix\\' . $className;

        return [$content, $fqcn];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('constraintProvider')]
    public function testConstraintIsGeneratedAndEnforced(
        string $class,
        array $schema,
        string $attribute,
        mixed $valid,
        mixed $invalid,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        [$content, $fqcn] = $this->generateSingleFieldDto($class, $schema);

        $this->assertStringContainsString($attribute, $content, 'expected attribute not generated');

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(v: $valid)), 'valid value must pass');
        $this->assertGreaterThan(
            0,
            count($validator->validate(new $fqcn(v: $invalid))),
            'invalid value must produce a violation',
        );
    }

    /**
     * @return array<string, array{class: string, schema: array<string, mixed>, attribute: string, valid: mixed, invalid: mixed}>
     */
    public static function constraintProvider(): array
    {
        return [
            'minLength' => [
                'class' => 'MinLen',
                'schema' => ['type' => 'string', 'minLength' => 3],
                'attribute' => '#[Assert\Length(min: 3)]',
                'valid' => 'abc',
                'invalid' => 'ab',
            ],
            'maxLength' => [
                'class' => 'MaxLen',
                'schema' => ['type' => 'string', 'maxLength' => 3],
                'attribute' => '#[Assert\Length(max: 3)]',
                'valid' => 'ab',
                'invalid' => 'abcd',
            ],
            'minMaxLength' => [
                'class' => 'MinMaxLen',
                'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4],
                'attribute' => '#[Assert\Length(min: 2, max: 4)]',
                'valid' => 'abc',
                'invalid' => 'a',
            ],
            'minimum' => [
                'class' => 'Min',
                'schema' => ['type' => 'integer', 'minimum' => 10],
                'attribute' => '#[Assert\Range(min: 10)]',
                'valid' => 10,
                'invalid' => 9,
            ],
            'maximum' => [
                'class' => 'Max',
                'schema' => ['type' => 'integer', 'maximum' => 10],
                'attribute' => '#[Assert\Range(max: 10)]',
                'valid' => 10,
                'invalid' => 11,
            ],
            'minMaxRange' => [
                'class' => 'MinMax',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'attribute' => '#[Assert\Range(min: 1, max: 5)]',
                'valid' => 3,
                'invalid' => 6,
            ],
            'exclusiveMinimum' => [
                'class' => 'ExMin',
                'schema' => ['type' => 'integer', 'exclusiveMinimum' => 0],
                'attribute' => '#[Assert\GreaterThan(0)]',
                'valid' => 1,
                'invalid' => 0,
            ],
            'exclusiveMaximum' => [
                'class' => 'ExMax',
                'schema' => ['type' => 'integer', 'exclusiveMaximum' => 10],
                'attribute' => '#[Assert\LessThan(10)]',
                'valid' => 9,
                'invalid' => 10,
            ],
            'multipleOf' => [
                'class' => 'Mult',
                'schema' => ['type' => 'integer', 'multipleOf' => 5],
                'attribute' => '#[Assert\DivisibleBy(5)]',
                'valid' => 10,
                'invalid' => 7,
            ],
            'pattern' => [
                'class' => 'Pat',
                'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
                'attribute' => '#[Assert\Regex(',
                'valid' => 'abc',
                'invalid' => 'A1',
            ],
            'minItems' => [
                'class' => 'MinItems',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
                'attribute' => '#[Assert\Count(min: 2)]',
                'valid' => ['a', 'b'],
                'invalid' => ['a'],
            ],
            'maxItems' => [
                'class' => 'MaxItems',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
                'attribute' => '#[Assert\Count(max: 2)]',
                'valid' => ['a'],
                'invalid' => ['a', 'b', 'c'],
            ],
            'uniqueItems' => [
                'class' => 'Uniq',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                'attribute' => '#[Assert\Unique]',
                'valid' => ['a', 'b'],
                'invalid' => ['a', 'a'],
            ],
            'const' => [
                'class' => 'ConstField',
                'schema' => ['type' => 'string', 'const' => 'locked'],
                'attribute' => "#[Assert\\EqualTo(value: 'locked')]",
                'valid' => 'locked',
                'invalid' => 'WRONG',
            ],
            'constInt' => [
                'class' => 'ConstInt',
                'schema' => ['type' => 'integer', 'const' => 5],
                'attribute' => '#[Assert\EqualTo(value: 5)]',
                'valid' => 5,
                'invalid' => 6,
            ],
            'constBool' => [
                'class' => 'ConstBool',
                'schema' => ['type' => 'boolean', 'const' => true],
                'attribute' => '#[Assert\EqualTo(value: true)]',
                'valid' => true,
                'invalid' => false,
            ],
            'exclusiveMinimumFloat' => [
                'class' => 'ExMinF',
                'schema' => ['type' => 'number', 'exclusiveMinimum' => 0.5],
                'attribute' => '#[Assert\GreaterThan(0.5)]',
                'valid' => 1.0,
                'invalid' => 0.5,
            ],
            'multipleOfFloat' => [
                'class' => 'MultF',
                'schema' => ['type' => 'number', 'multipleOf' => 0.25],
                'attribute' => '#[Assert\DivisibleBy(0.25)]',
                'valid' => 0.5,
                'invalid' => 0.3,
            ],
            'formatEmail' => [
                'class' => 'Em',
                'schema' => ['type' => 'string', 'format' => 'email'],
                'attribute' => '#[Assert\Email]',
                'valid' => 'a@b.com',
                'invalid' => 'not-an-email',
            ],
            'formatUuid' => [
                'class' => 'Uid',
                'schema' => ['type' => 'string', 'format' => 'uuid'],
                'attribute' => '#[Assert\Uuid]',
                'valid' => '550e8400-e29b-41d4-a716-446655440000',
                'invalid' => 'not-a-uuid',
            ],
            'formatUrl' => [
                'class' => 'Url',
                'schema' => ['type' => 'string', 'format' => 'url'],
                'attribute' => '#[Assert\Url]',
                'valid' => 'https://example.com',
                'invalid' => 'not a url',
            ],
            'formatIpv4' => [
                'class' => 'Ip4',
                'schema' => ['type' => 'string', 'format' => 'ipv4'],
                'attribute' => "#[Assert\\Ip(version: '4')]",
                'valid' => '192.168.0.1',
                'invalid' => '999.999.999.999',
            ],
            'formatHostname' => [
                'class' => 'Host',
                'schema' => ['type' => 'string', 'format' => 'hostname'],
                'attribute' => '#[Assert\Hostname]',
                'valid' => 'example.com',
                'invalid' => 'not a hostname',
            ],
            'formatIpv6' => [
                'class' => 'Ip6',
                'schema' => ['type' => 'string', 'format' => 'ipv6'],
                'attribute' => "#[Assert\\Ip(version: '6')]",
                'valid' => '2001:db8::1',
                'invalid' => 'not-an-ip',
            ],
            'formatInt32' => [
                'class' => 'I32',
                'schema' => ['type' => 'integer', 'format' => 'int32'],
                'attribute' => '#[Assert\Range(min: -2147483648, max: 2147483647)]',
                'valid' => 100,
                'invalid' => 5000000000,
            ],
            'formatUint32' => [
                'class' => 'U32',
                'schema' => ['type' => 'integer', 'format' => 'uint32'],
                'attribute' => '#[Assert\Range(min: 0, max: 4294967295)]',
                'valid' => 100,
                'invalid' => -1,
            ],
            'arrayItemConstraints' => [
                'class' => 'ItemAll',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
                'attribute' => '#[Assert\All([new Assert\Length(min: 2)])]',
                'valid' => ['ab', 'cd'],
                'invalid' => ['a'],
            ],
        ];
    }

    public function testSerializedNameRoundTripsThroughSymfonySerializer(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Snake' => [
                        'type' => 'object',
                        'required' => ['user_name'],
                        'properties' => [
                            'user_name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymSerde', 'symfony');
        $path = $this->outputDirectory . '/Snake.php';
        $content = (string)file_get_contents($path);
        $this->assertStringContainsString("#[SerializedName('user_name')]", $content);
        $this->assertStringContainsString('$userName', $content);

        require_once $path;
        $fqcn = 'SymSerde\Snake';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $serializer = new Serializer(
            [new ObjectNormalizer($classMetadataFactory, $nameConverter), new ArrayDenormalizer()],
        );

        // The wire payload uses the OpenAPI snake_case key; SerializedName must map it to $userName.
        $object = $serializer->denormalize(['user_name' => 'bob'], $fqcn);
        $this->assertSame('bob', $object->getUserName());

        // And serializing back must emit the snake_case key again.
        $payload = $serializer->normalize($object);
        $this->assertSame(['user_name' => 'bob'], $payload);
    }

    public function testReadOnlyWriteOnlyMapToSerializationGroups(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Acct' => [
                        'type' => 'object',
                        'required' => ['id', 'password'],
                        'properties' => [
                            'id' => ['type' => 'string', 'readOnly' => true],
                            'password' => ['type' => 'string', 'writeOnly' => true],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymGroups', 'symfony');
        $path = $this->outputDirectory . '/Acct.php';
        $content = (string)file_get_contents($path);
        $this->assertStringContainsString("#[Groups(['read'])]", $content);
        $this->assertStringContainsString("#[Groups(['write'])]", $content);

        require_once $path;
        $fqcn = 'SymGroups\Acct';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($classMetadataFactory)]);

        $object = new $fqcn(id: 'u1', password: 'secret');

        // Serializing with the 'read' group must expose the read-only id and hide the write-only password.
        $readView = $serializer->normalize($object, null, ['groups' => ['read']]);
        $this->assertSame(['id' => 'u1'], $readView);
    }

    public function testUnsupportedKeywordsValidateViaCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Loose' => [
                        'type' => 'object',
                        'required' => ['mode', 'choice', 'tags', 'bag', 'list'],
                        'properties' => [
                            'mode' => ['type' => 'string', 'not' => ['const' => 'forbidden']],
                            'choice' => [
                                'type' => 'string',
                                'if' => ['const' => 'a'],
                                'then' => ['const' => 'a'],
                                'else' => ['const' => 'b'],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['const' => 'hit'],
                                'minContains' => 1,
                            ],
                            'bag' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'integer'],
                                'patternProperties' => ['^x-' => ['type' => 'integer']],
                                'dependentRequired' => ['kind' => ['x-1']],
                                'dependentSchemas' => [
                                    'kind' => [
                                        'required' => ['x-1'],
                                    ],
                                ],
                            ],
                            'list' => [
                                'type' => 'array',
                                'prefixItems' => [['type' => 'string']],
                                'contains' => ['const' => 'hit'],
                                'minContains' => 1,
                                'unevaluatedItems' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymLoose';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/Loose.php';
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('#[Assert\Callback]', $content);
        $this->assertStringContainsString('private const array OPENAPI_VALIDATION_CONSTRAINTS = [', $content);
        $this->assertStringNotContainsString('OPENAPI_VALIDATION_CONSTRAINTS = array (', $content);
        $this->assertStringNotContainsString('use OpenapiPhpDtoGenerator\Service\DtoValidator;', $content);

        require_once $path;
        $fqcn = $namespace . '\Loose';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(
            0,
            $validator->validate(
                new $fqcn(
                    mode: 'ok',
                    choice: 'b',
                    tags: ['hit', 'other'],
                    bag: ['kind' => 1, 'x-1' => 2],
                    list: ['first', 'hit'],
                ),
            ),
        );

        $this->assertGreaterThan(
            0,
            count($validator->validate(
                new $fqcn(
                    mode: 'forbidden',
                    choice: 'c',
                    tags: ['miss'],
                    bag: ['kind' => 1],
                    list: ['first', 'hit', 'extra'],
                ),
            )),
        );
    }

    public function testScalarKeywordsInsideCallbackSubschemasAreEnforced(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'NestedScalars' => [
                        'type' => 'object',
                        'required' => ['notMinLen', 'notInteger', 'thenPattern', 'tags'],
                        'properties' => [
                            'notMinLen' => ['type' => 'string', 'not' => ['minLength' => 3]],
                            'notInteger' => ['not' => ['type' => 'integer']],
                            'thenPattern' => [
                                'type' => 'string',
                                'if' => ['type' => 'string'],
                                'then' => ['pattern' => '^z'],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['minLength' => 5],
                                'minContains' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymNestedScalars';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/NestedScalars.php';
        require_once $path;

        $fqcn = $namespace . '\NestedScalars';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(
            0,
            $validator->validate(new $fqcn(
                notMinLen: 'ab',
                notInteger: 'ok',
                thenPattern: 'zoo',
                tags: ['abcde', 'x'],
            )),
        );

        $violations = $validator->validate(new $fqcn(
            notMinLen: 'abcd',
            notInteger: 7,
            thenPattern: 'abc',
            tags: ['ab', 'cd'],
        ));

        $this->assertGreaterThan(0, count($violations));
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $joined = implode("\n", $messages);
        $this->assertStringContainsString('field "notMinLen" must not match the \'not\' schema', $joined);
        $this->assertStringContainsString('field "notInteger" must not match the \'not\' schema', $joined);
        $this->assertStringContainsString('field "thenPattern" must match pattern ^z', $joined);
        $this->assertStringContainsString('field "tags" must contain at least 1 item(s) matching the \'contains\' schema', $joined);
    }

    #[DataProvider('callbackOnlyFormatProvider')]
    public function testCallbackOnlyFormatsAreEnforced(
        string $class,
        string $format,
        string $valid,
        string $invalid,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        [$content, $fqcn] = $this->generateSingleFieldDto($class, ['type' => 'string', 'format' => $format]);

        $this->assertStringContainsString('#[Assert\Callback]', $content);
        $this->assertStringContainsString(sprintf("'%s' =>", $format), $content, 'format arm not emitted');

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(v: $valid)), 'valid value must pass');
        $violations = $validator->validate(new $fqcn(v: $invalid));
        $this->assertGreaterThan(0, count($violations), 'invalid value must produce a violation');
        $this->assertStringContainsString('must match format ' . $format, (string)$violations);
    }

    /**
     * The formats Symfony has no constraint for: they are enforced by the generated callback, so
     * each one needs a value that passes and a value that must be rejected.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function callbackOnlyFormatProvider(): array
    {
        return [
            'time' => ['CbTime', 'time', '13:45:00Z', '99:99'],
            'json-pointer' => ['CbJsonPointer', 'json-pointer', '/a/b', 'a/b'],
            'relative-json-pointer' => ['CbRelPointer', 'relative-json-pointer', '1/a', '/a'],
            'uri-reference' => ['CbUriRef', 'uri-reference', '/relative/path', "has space \u{202E}"],
            'uri-template' => ['CbUriTemplate', 'uri-template', 'https://a.b/{id}', "bad\ttemplate"],
            'byte' => ['CbByte', 'byte', 'aGVsbG8=', '!!!'],
            'idn-hostname' => ['CbIdnHostname', 'idn-hostname', 'exämple.com', 'not a host'],
        ];
    }

    public function testPropertyNamesIsEnforcedInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        [$content, $fqcn] = $this->generateSingleFieldDto('PropNames', [
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer'],
            'propertyNames' => ['pattern' => '^x-'],
        ]);

        $this->assertStringContainsString("'propertyNames' =>", $content);

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(v: ['x-a' => 1, 'x-b' => 2])));
        $violations = $validator->validate(new $fqcn(v: ['y' => 1]));
        $this->assertGreaterThan(0, count($violations));
        $this->assertStringContainsString('field "v" key "y" must match pattern ^x-', (string)$violations);
    }

    public function testOneOfIsExclusiveNotAtLeastOneOf(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // Overlapping branches: "abcdef" satisfies both, which `oneOf` forbids and `anyOf` allows.
        [$content, $fqcn] = $this->generateSingleFieldDto('XorPick', [
            'oneOf' => [
                ['type' => 'string', 'minLength' => 5],
                ['type' => 'string', 'pattern' => '^a'],
            ],
        ]);

        $this->assertStringContainsString('#[Assert\Callback]', $content);
        $this->assertStringNotContainsString('#[Assert\AtLeastOneOf(', $content);

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(v: 'ab')), 'exactly one branch matches');

        $both = $validator->validate(new $fqcn(v: 'abcdef'));
        $this->assertGreaterThan(0, count($both));
        $this->assertStringContainsString('matches more than one allowed oneOf branch', (string)$both);

        // No branch matches: the reasons from every applicable branch are reported instead of a
        // bare "does not match any oneOf branch", which would leave the caller guessing.
        $none = $validator->validate(new $fqcn(v: 'bcd'));
        $this->assertGreaterThan(0, count($none));
        $this->assertStringContainsString('field "v" length must be at least 5 characters', (string)$none);
        $this->assertStringContainsString('field "v" must match pattern ^a', (string)$none);
    }

    public function testOneOfSkipsBranchesWhoseTypeCannotMatch(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // integer 10..100 | uuid string — for the integer 5 only the numeric branch is applicable,
        // so the report must name the bound instead of adding "must be of type string" noise.
        [, $fqcn] = $this->generateSingleFieldDto('TypedXor', [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 10, 'maximum' => 100],
                ['type' => 'string', 'format' => 'uuid'],
            ],
        ]);

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(v: 42)));
        $this->assertCount(0, $validator->validate(new $fqcn(v: '7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b')));

        $violations = $validator->validate(new $fqcn(v: 5));
        $this->assertCount(1, $violations);
        $this->assertSame('field "v" must be greater than or equal to 10.', $violations->get(0)->getMessage());
    }

    public function testAnyOfBranchThatIsPureNullDropsTheConstraint(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // Documented limitation: a `{type: null}` branch cannot be expressed as an Assert branch,
        // so the whole AtLeastOneOf is dropped and the field is only nullable.
        [$content, $fqcn] = $this->generateSingleFieldDto('NullBranch', [
            'anyOf' => [
                ['type' => 'string', 'minLength' => 5],
                ['type' => 'null'],
            ],
        ]);

        $this->assertStringNotContainsString('#[Assert\AtLeastOneOf(', $content);

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $this->assertCount(0, $validator->validate(new $fqcn(v: 'abcde')));
        $this->assertCount(0, $validator->validate(new $fqcn(v: 'ab')), 'branch constraints are not enforced');
    }

    public function testReadOnlyAndWriteOnlyFieldsAreStillValidated(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Account' => [
                        'type' => 'object',
                        'required' => ['id', 'password'],
                        'properties' => [
                            'id' => ['type' => 'string', 'minLength' => 3, 'readOnly' => true],
                            'password' => ['type' => 'string', 'minLength' => 8, 'writeOnly' => true],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymMatrixGroups';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/Account.php';
        $content = (string)file_get_contents($path);
        require_once $path;

        // Serialization groups steer the payload, they must not weaken validation.
        $this->assertStringContainsString("#[Groups(['read'])]", $content);
        $this->assertStringContainsString("#[Groups(['write'])]", $content);

        $fqcn = $namespace . '\Account';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(id: 'abc', password: 'longenough')));

        $violations = $validator->validate(new $fqcn(id: 'ab', password: 'short'));
        $this->assertCount(2, $violations, 'both the read-only and the write-only field are checked');
    }

    public function testUriAndIriFormatsAreValidatedInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'LinkPayload' => [
                        'type' => 'object',
                        'required' => ['uri', 'iri'],
                        'properties' => [
                            'uri' => ['type' => 'string', 'format' => 'uri'],
                            'iri' => ['type' => 'string', 'format' => 'iri'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymUriFormats';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/LinkPayload.php';
        $content = (string)file_get_contents($path);
        $this->assertStringNotContainsString('#[Assert\Url]', $content);
        $this->assertStringContainsString('#[Assert\Callback]', $content);

        require_once $path;
        $fqcn = $namespace . '\LinkPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // `uri` and `iri` are ABSOLUTE (RFC 3986 / 3987) — a scheme is required. `#[Assert\Url]` is still
        // not used: it rejects non-ASCII, which an IRI is allowed to carry.
        $this->assertCount(0, $validator->validate(new $fqcn(uri: 'https://a.example/docs', iri: 'https://пример.example/путь')));

        $joined = $this->messagesFor($validator->validate(new $fqcn(uri: 'bad uri', iri: 'bad iri')));
        $this->assertStringContainsString('field "uri" must match format uri', $joined);
        $this->assertStringContainsString('field "iri" must match format iri', $joined);

        // A RELATIVE value belongs to `uri-reference`/`iri-reference`, not here. Both were accepted while
        // the emitted interpreter mapped all four formats to the reference check, and the runtime
        // validator refused them all along — see `ValidationParityTest` cases "format uri" / "format iri".
        $relative = $this->messagesFor($validator->validate(new $fqcn(uri: '/docs/page', iri: '/путь')));
        $this->assertStringContainsString('field "uri" must match format uri', $relative);
        $this->assertStringContainsString('field "iri" must match format iri', $relative);
    }

    /**
     * @param iterable<mixed> $violations
     */
    private function messagesFor(iterable $violations): string
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = is_object($violation) && method_exists($violation, 'getMessage')
                ? (string)$violation->getMessage()
                : '';
        }

        return implode("\n", $messages);
    }

    public function testContentKeywordsValidateInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ContentPayload' => [
                        'type' => 'object',
                        'required' => ['encoded'],
                        'properties' => [
                            'encoded' => [
                                'type' => 'string',
                                'contentEncoding' => 'base64',
                                'contentMediaType' => 'application/json',
                                'contentSchema' => [
                                    'type' => 'object',
                                    'required' => ['id'],
                                    'properties' => ['id' => ['type' => 'integer']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymContentCallback';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/ContentPayload.php';
        require_once $path;

        $fqcn = $namespace . '\ContentPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $this->assertCount(0, $validator->validate(new $fqcn(encoded: base64_encode('{"id":1}'))));

        $badEncoding = $validator->validate(new $fqcn(encoded: '@@@'));
        $badJson = $validator->validate(new $fqcn(encoded: base64_encode('{bad-json')));
        $badSchema = $validator->validate(new $fqcn(encoded: base64_encode('{"id":"x"}')));

        $this->assertGreaterThan(0, count($badEncoding));
        $this->assertGreaterThan(0, count($badJson));
        $this->assertGreaterThan(0, count($badSchema));
    }

    public function testInt64AndUint64FormatsValidateInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'NumericPayload' => [
                        'type' => 'object',
                        'required' => ['i64', 'u64'],
                        'properties' => [
                            'i64' => ['type' => 'number', 'format' => 'int64'],
                            'u64' => ['type' => 'number', 'format' => 'uint64'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymInt64Callback';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/NumericPayload.php';
        require_once $path;

        $fqcn = $namespace . '\NumericPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(i64: 12.0, u64: 42.0)));

        $fractional = $validator->validate(new $fqcn(i64: 12.5, u64: 42.0));
        $negativeUnsigned = $validator->validate(new $fqcn(i64: 12.0, u64: -1.0));
        $overflowUnsigned = $validator->validate(new $fqcn(i64: 12.0, u64: 1.0e25));

        $this->assertGreaterThan(0, count($fractional));
        $this->assertGreaterThan(0, count($negativeUnsigned));
        $this->assertGreaterThan(0, count($overflowUnsigned));
    }

    public function testOneOfWithDiscriminatorUsesConcreteDtoClassMatching(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'PetCat' => [
                        'type' => 'object',
                        'required' => ['kind', 'name'],
                        'properties' => [
                            'kind' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'PetDog' => [
                        'type' => 'object',
                        'required' => ['kind', 'name'],
                        'properties' => [
                            'kind' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'PetHolder' => [
                        'type' => 'object',
                        'required' => ['pet'],
                        'properties' => [
                            'pet' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/PetCat'],
                                    ['$ref' => '#/components/schemas/PetDog'],
                                ],
                                'discriminator' => [
                                    'propertyName' => 'kind',
                                    'mapping' => [
                                        'cat' => '#/components/schemas/PetCat',
                                        'dog' => '#/components/schemas/PetDog',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymOneOfDiscriminator';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        foreach (['PetCat', 'PetDog', 'PetHolder'] as $file) {
            require_once $this->outputDirectory . '/' . $file . '.php';
        }

        $holder = $namespace . '\PetHolder';
        $cat = $namespace . '\PetCat';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $holder(pet: new $cat(kind: 'cat', name: 'Milo'))));

        $violations = $validator->validate(new $holder(pet: new $cat(kind: 'dog', name: 'Milo')));
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString(
            'field "pet" discriminator kind must match concrete class',
            implode("\n", $messages),
        );
    }

    public function testUniqueItemsForDtoArraysValidateByValueInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Entry' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                    'EntryBag' => [
                        'type' => 'object',
                        'required' => ['entries'],
                        'properties' => [
                            'entries' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Entry'],
                                'uniqueItems' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymUniqueDto';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/EntryBag.php');
        $this->assertStringNotContainsString('#[Assert\Unique]', $content);
        foreach (['Entry', 'EntryBag'] as $file) {
            require_once $this->outputDirectory . '/' . $file . '.php';
        }

        $entry = $namespace . '\Entry';
        $bag = $namespace . '\EntryBag';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $bag(entries: [new $entry(id: 1), new $entry(id: 2)])));
        $violations = $validator->validate(new $bag(entries: [new $entry(id: 1), new $entry(id: 1)]));
        $this->assertGreaterThan(0, count($violations));
    }

    public function testInvalidPatternFallsBackToCallbackViolation(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'BadPatternPayload' => [
                        'type' => 'object',
                        'required' => ['value'],
                        'properties' => [
                            'value' => ['type' => 'string', 'pattern' => '[a-z'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymBadPattern';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/BadPatternPayload.php';
        $content = (string)file_get_contents($path);
        $this->assertStringNotContainsString('#[Assert\Regex(', $content);
        require_once $path;

        $fqcn = $namespace . '\BadPatternPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate(new $fqcn(value: 'abc'));

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString(
            'field "value" has invalid regex pattern in schema: [a-z',
            implode("\n", $messages),
        );
    }

    public function testEnumWithBoolOrNullMembersFallsBackToChoiceConstraint(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Flags' => [
                        'type' => 'object',
                        'required' => ['boolOnly', 'mixed', 'nullableText'],
                        'properties' => [
                            'boolOnly' => ['type' => 'boolean', 'enum' => [true, false]],
                            'mixed' => ['type' => 'string', 'enum' => [1, 'a', true]],
                            'nullableText' => ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymEnumInline';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/Flags.php';
        $content = (string)file_get_contents($path);

        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsBoolOnly.php');
        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsMixed.php');
        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsNullableText.php');
        $this->assertStringContainsString('#[Assert\Choice(choices: [true, false])]', $content);
        $this->assertStringContainsString("#[Assert\\Choice(choices: [1, 'a', true])]", $content);
        $this->assertStringContainsString("#[Assert\\Choice(choices: ['a', 'b', null])]", $content);

        require_once $path;
        $fqcn = $namespace . '\Flags';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $fqcn(boolOnly: true, mixed: 'a', nullableText: null)));

        $violations = $validator->validate(new $fqcn(boolOnly: false, mixed: 'zzz', nullableText: 'c'));
        $this->assertGreaterThan(0, count($violations));
    }

    public function testFormatRegexIsValidatedInCallback(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'RegexPayload' => [
                        'type' => 'object',
                        'required' => ['ok', 'bad'],
                        'properties' => [
                            'ok' => ['type' => 'string', 'format' => 'regex'],
                            'bad' => ['type' => 'string', 'format' => 'regex'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymRegexFormat';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $path = $this->outputDirectory . '/RegexPayload.php';
        require_once $path;

        $fqcn = $namespace . '\RegexPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $this->assertCount(0, $validator->validate(new $fqcn(ok: '^a.*$', bad: '^b.*$')));

        $violations = $validator->validate(new $fqcn(ok: '^a.*$', bad: '(['));
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString('field "bad" must match format regex', implode("\n", $messages));
    }

    public function testGeneratedEnumAndDateTimeObjectsPassCallbackTypeChecks(): void
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
                        'required' => ['labels', 'timestamps'],
                        'properties' => [
                            'labels' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string', 'enum' => ['a', 'b']],
                            ],
                            'timestamps' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymUnwrap';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        $holder = $namespace . '\Holder';
        $labelEnum = $namespace . '\HolderLabelsItem';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $dto = new $holder(
            labels: [$labelEnum::A],
            timestamps: [new DateTimeImmutable('2026-01-01T00:00:00+00:00')],
        );
        $this->assertCount(0, $validator->validate($dto));
    }

    public function testEnumAndDateTimeTypedValuesDoNotEmitScalarStringAttributes(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'AttrHolder' => [
                        'type' => 'object',
                        'required' => ['e', 'd', 'arr'],
                        'properties' => [
                            'e' => ['type' => 'string', 'enum' => ['ab', 'abc'], 'minLength' => 3],
                            'd' => ['type' => 'string', 'format' => 'date-time', 'pattern' => '^2026'],
                            'arr' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => ['ab', 'abc'], 'minLength' => 3],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymAttrRouting';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/AttrHolder.php');

        $this->assertStringNotContainsString('#[Assert\Length(min: 3)]', $content);
        $this->assertStringNotContainsString('#[Assert\Regex(', $content);
        $this->assertStringContainsString('#[Assert\Callback]', $content);
    }

    public function testPatternPropertiesOnlyObjectIsGeneratedAsArrayMap(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'PatternContainer' => [
                        'type' => 'object',
                        'required' => ['bag'],
                        'properties' => [
                            'bag' => [
                                'type' => 'object',
                                'patternProperties' => [
                                    '^x-' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymPatternMap';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/PatternContainer.php');

        $this->assertStringContainsString('private readonly array $bag', $content);
    }
}
