<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

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
     * @return array<string, array{class: string, schema: array<string, mixed>, attribute: string, valid: mixed, invalid: mixed}>
     */
    public static function constraintProvider(): array
    {
        return [
            'minLength' => [
                'class' => 'MinLen',
                'schema' => ['type' => 'string', 'minLength' => 3],
                'attribute' => '#[Assert\\Length(min: 3)]',
                'valid' => 'abc',
                'invalid' => 'ab',
            ],
            'maxLength' => [
                'class' => 'MaxLen',
                'schema' => ['type' => 'string', 'maxLength' => 3],
                'attribute' => '#[Assert\\Length(max: 3)]',
                'valid' => 'ab',
                'invalid' => 'abcd',
            ],
            'minMaxLength' => [
                'class' => 'MinMaxLen',
                'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4],
                'attribute' => '#[Assert\\Length(min: 2, max: 4)]',
                'valid' => 'abc',
                'invalid' => 'a',
            ],
            'minimum' => [
                'class' => 'Min',
                'schema' => ['type' => 'integer', 'minimum' => 10],
                'attribute' => '#[Assert\\Range(min: 10)]',
                'valid' => 10,
                'invalid' => 9,
            ],
            'maximum' => [
                'class' => 'Max',
                'schema' => ['type' => 'integer', 'maximum' => 10],
                'attribute' => '#[Assert\\Range(max: 10)]',
                'valid' => 10,
                'invalid' => 11,
            ],
            'minMaxRange' => [
                'class' => 'MinMax',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'attribute' => '#[Assert\\Range(min: 1, max: 5)]',
                'valid' => 3,
                'invalid' => 6,
            ],
            'exclusiveMinimum' => [
                'class' => 'ExMin',
                'schema' => ['type' => 'integer', 'exclusiveMinimum' => 0],
                'attribute' => '#[Assert\\GreaterThan(0)]',
                'valid' => 1,
                'invalid' => 0,
            ],
            'exclusiveMaximum' => [
                'class' => 'ExMax',
                'schema' => ['type' => 'integer', 'exclusiveMaximum' => 10],
                'attribute' => '#[Assert\\LessThan(10)]',
                'valid' => 9,
                'invalid' => 10,
            ],
            'multipleOf' => [
                'class' => 'Mult',
                'schema' => ['type' => 'integer', 'multipleOf' => 5],
                'attribute' => '#[Assert\\DivisibleBy(5)]',
                'valid' => 10,
                'invalid' => 7,
            ],
            'pattern' => [
                'class' => 'Pat',
                'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
                'attribute' => '#[Assert\\Regex(',
                'valid' => 'abc',
                'invalid' => 'A1',
            ],
            'minItems' => [
                'class' => 'MinItems',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
                'attribute' => '#[Assert\\Count(min: 2)]',
                'valid' => ['a', 'b'],
                'invalid' => ['a'],
            ],
            'maxItems' => [
                'class' => 'MaxItems',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
                'attribute' => '#[Assert\\Count(max: 2)]',
                'valid' => ['a'],
                'invalid' => ['a', 'b', 'c'],
            ],
            'uniqueItems' => [
                'class' => 'Uniq',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                'attribute' => '#[Assert\\Unique]',
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
                'attribute' => '#[Assert\\EqualTo(value: 5)]',
                'valid' => 5,
                'invalid' => 6,
            ],
            'constBool' => [
                'class' => 'ConstBool',
                'schema' => ['type' => 'boolean', 'const' => true],
                'attribute' => '#[Assert\\EqualTo(value: true)]',
                'valid' => true,
                'invalid' => false,
            ],
            'exclusiveMinimumFloat' => [
                'class' => 'ExMinF',
                'schema' => ['type' => 'number', 'exclusiveMinimum' => 0.5],
                'attribute' => '#[Assert\\GreaterThan(0.5)]',
                'valid' => 1.0,
                'invalid' => 0.5,
            ],
            'multipleOfFloat' => [
                'class' => 'MultF',
                'schema' => ['type' => 'number', 'multipleOf' => 0.25],
                'attribute' => '#[Assert\\DivisibleBy(0.25)]',
                'valid' => 0.5,
                'invalid' => 0.3,
            ],
            'formatEmail' => [
                'class' => 'Em',
                'schema' => ['type' => 'string', 'format' => 'email'],
                'attribute' => '#[Assert\\Email]',
                'valid' => 'a@b.com',
                'invalid' => 'not-an-email',
            ],
            'formatUuid' => [
                'class' => 'Uid',
                'schema' => ['type' => 'string', 'format' => 'uuid'],
                'attribute' => '#[Assert\\Uuid]',
                'valid' => '550e8400-e29b-41d4-a716-446655440000',
                'invalid' => 'not-a-uuid',
            ],
            'formatUri' => [
                'class' => 'Uri',
                'schema' => ['type' => 'string', 'format' => 'uri'],
                'attribute' => '#[Assert\\Url]',
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
                'attribute' => '#[Assert\\Hostname]',
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
                'attribute' => '#[Assert\\Range(min: -2147483648, max: 2147483647)]',
                'valid' => 100,
                'invalid' => 5000000000,
            ],
            'formatUint32' => [
                'class' => 'U32',
                'schema' => ['type' => 'integer', 'format' => 'uint32'],
                'attribute' => '#[Assert\\Range(min: 0, max: 4294967295)]',
                'valid' => 100,
                'invalid' => -1,
            ],
            'formatUint64' => [
                'class' => 'U64',
                'schema' => ['type' => 'integer', 'format' => 'uint64'],
                'attribute' => '#[Assert\\Range(min: 0)]',
                'valid' => 100,
                'invalid' => -5,
            ],
            'arrayItemConstraints' => [
                'class' => 'ItemAll',
                'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
                'attribute' => '#[Assert\\All([new Assert\\Length(min: 2)])]',
                'valid' => ['ab', 'cd'],
                'invalid' => ['a'],
            ],
        ];
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
        $fqcn = 'SymSerde\\Snake';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $serializer = new Serializer(
            [new ObjectNormalizer($classMetadataFactory, $nameConverter), new ArrayDenormalizer()],
        );

        // The wire payload uses the OpenAPI snake_case key; SerializedName must map it to $userName.
        $object = $serializer->denormalize(['user_name' => 'bob'], $fqcn);
        $this->assertSame('bob', $object->userName);

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
        $fqcn = 'SymGroups\\Acct';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($classMetadataFactory)]);

        $object = new $fqcn(id: 'u1', password: 'secret');

        // Serializing with the 'read' group must expose the read-only id and hide the write-only password.
        $readView = $serializer->normalize($object, null, ['groups' => ['read']]);
        $this->assertSame(['id' => 'u1'], $readView);
    }

    public function testUnsupportedKeywordsDegradeGracefullyWithoutBrokenAttributes(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // These keywords have no clean Symfony Validator equivalent (documented limitation): the
        // generator must skip them rather than emit a broken/uncompilable attribute. The class must
        // still be a valid, loadable DTO that the validator accepts.
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Loose' => [
                        'type' => 'object',
                        'properties' => [
                            'notField' => ['type' => 'string', 'not' => ['const' => 'forbidden']],
                            'tupleField' => [
                                'type' => 'array',
                                'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
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

        // No attribute is invented for the unmappable keywords (no Symfony equivalent).
        $this->assertStringNotContainsString('Assert\\Not', $content);
        $this->assertStringNotContainsString('prefixItems', $content);

        require_once $path;
        $fqcn = $namespace . '\\Loose';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // A well-formed instance still validates cleanly (no broken constraint blows up).
        $this->assertCount(0, $validator->validate(new $fqcn(notField: 'ok', tupleField: ['a', 1])));
    }
}
