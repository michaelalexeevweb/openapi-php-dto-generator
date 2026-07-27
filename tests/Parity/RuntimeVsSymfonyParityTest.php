<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Throwable;

/**
 * Both generation modes must agree on what a payload means.
 *
 * Every case is generated twice from the same spec and fed the same valid/invalid JSON — runtime
 * through `DtoDeserializer` + `DtoNormalizer`, Symfony through the serializer + validator — and the
 * two verdicts are compared. A keyword implemented in only one mode (or implemented differently)
 * fails here, which is how the four historical divergences (dependentRequired/dependentSchemas on
 * DTO values, uint32/uint64 bounds, scalar `allOf`) were found.
 */
final class RuntimeVsSymfonyParityTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-parity';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
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

    /**
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('keywordProvider')]
    public function testBothModesAgree(string $key, array $propertySchema, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => $propertySchema],
                    ],
                ],
            ],
        ];

        $runtime = $this->runtimeVerdict($spec, $key, $validJson, $invalidJson);
        $symfony = $this->symfonyVerdict($spec, $key, $validJson, $invalidJson);

        $this->assertSame(
            $runtime,
            $symfony,
            sprintf(
                "modes disagree on %s\n runtime: valid=%s invalid=%s\n symfony: valid=%s invalid=%s",
                $key,
                $runtime['valid'] ? 'accepted' : 'REJECTED',
                $runtime['invalid'] ? 'ACCEPTED' : 'rejected',
                $symfony['valid'] ? 'accepted' : 'REJECTED',
                $symfony['invalid'] ? 'ACCEPTED' : 'rejected',
            ),
        );

        // Sanity: a case that accepts everything (or nothing) would compare equal for the wrong
        // reason, so both modes must actually discriminate between the two payloads.
        $this->assertTrue($runtime['valid'], 'the valid payload must be accepted');
        $this->assertFalse($runtime['invalid'], 'the invalid payload must be rejected');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function keywordProvider(): array
    {
        $cases = [
            'type string' => [['type' => 'string'], '{"f":"a"}', '{"f":5}'],
            'type integer' => [['type' => 'integer'], '{"f":5}', '{"f":"a"}'],
            'type union with null' => [['type' => ['string', 'null']], '{"f":null}', '{"f":5}'],
            'enum' => [['type' => 'string', 'enum' => ['a', 'b']], '{"f":"a"}', '{"f":"z"}'],
            'const' => [['type' => 'string', 'const' => 'a'], '{"f":"a"}', '{"f":"b"}'],
            'minLength' => [['type' => 'string', 'minLength' => 3], '{"f":"abc"}', '{"f":"ab"}'],
            'maxLength' => [['type' => 'string', 'maxLength' => 2], '{"f":"ab"}', '{"f":"abc"}'],
            'pattern' => [['type' => 'string', 'pattern' => '^a'], '{"f":"ab"}', '{"f":"ba"}'],
            'minimum' => [['type' => 'integer', 'minimum' => 3], '{"f":3}', '{"f":2}'],
            'maximum' => [['type' => 'integer', 'maximum' => 3], '{"f":3}', '{"f":4}'],
            'exclusiveMinimum' => [['type' => 'integer', 'exclusiveMinimum' => 3], '{"f":4}', '{"f":3}'],
            'exclusiveMaximum' => [['type' => 'integer', 'exclusiveMaximum' => 3], '{"f":2}', '{"f":3}'],
            'multipleOf' => [['type' => 'integer', 'multipleOf' => 3], '{"f":6}', '{"f":7}'],
            'minItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2], '{"f":["a","b"]}', '{"f":["a"]}'],
            'maxItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1], '{"f":["a"]}', '{"f":["a","b"]}'],
            'uniqueItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true], '{"f":["a","b"]}', '{"f":["a","a"]}'],
            'items scalar rule' => [['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]], '{"f":["ab"]}', '{"f":["a"]}'],
            'minProperties' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'minProperties' => 2], '{"f":{"a":1,"b":2}}', '{"f":{"a":1}}'],
            'maxProperties' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'maxProperties' => 1], '{"f":{"a":1}}', '{"f":{"a":1,"b":2}}'],
            'additionalProperties schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], '{"f":{"a":1}}', '{"f":{"a":"x"}}'],
            'propertyNames' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'propertyNames' => ['pattern' => '^x-']], '{"f":{"x-a":1}}', '{"f":{"y":1}}'],
            'patternProperties' => [['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'integer', 'minimum' => 5]]], '{"f":{"x-a":9}}', '{"f":{"x-a":1}}'],
            'dependentRequired' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'dependentRequired' => ['a' => ['b']]],
                '{"f":{"a":"1","b":"2"}}',
                '{"f":{"a":"1"}}',
            ],
            'dependentSchemas' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'dependentSchemas' => ['a' => ['properties' => ['b' => ['minLength' => 3]]]]],
                '{"f":{"a":"1","b":"abc"}}',
                '{"f":{"a":"1","b":"x"}}',
            ],
            'not' => [['type' => 'string', 'not' => ['const' => 'zz']], '{"f":"ok"}', '{"f":"zz"}'],
            'if then' => [['type' => 'string', 'if' => ['const' => 'a'], 'then' => ['minLength' => 3]], '{"f":"b"}', '{"f":"a"}'],
            'contains' => [['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'hit']], '{"f":["hit"]}', '{"f":["miss"]}'],
            'minContains' => [['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'hit'], 'minContains' => 2], '{"f":["hit","hit"]}', '{"f":["hit"]}'],
            'maxContains' => [['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'hit'], 'maxContains' => 1], '{"f":["hit"]}', '{"f":["hit","hit"]}'],
            'prefixItems' => [['type' => 'array', 'prefixItems' => [['type' => 'string', 'minLength' => 2]]], '{"f":["ab"]}', '{"f":["a"]}'],
            'unevaluatedItems false' => [['type' => 'array', 'prefixItems' => [['type' => 'string']], 'unevaluatedItems' => false], '{"f":["a"]}', '{"f":["a","b"]}'],
            'anyOf' => [['anyOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'integer']]], '{"f":5}', '{"f":"ab"}'],
            'oneOf scalar' => [['oneOf' => [['type' => 'string', 'minLength' => 5], ['type' => 'string', 'pattern' => '^a']]], '{"f":"ab"}', '{"f":"abcdef"}'],
            'allOf of scalars' => [['allOf' => [['type' => 'string'], ['minLength' => 3]]], '{"f":"abc"}', '{"f":"ab"}'],
            'format email' => [['type' => 'string', 'format' => 'email'], '{"f":"a@b.co"}', '{"f":"nope"}'],
            'format uuid' => [['type' => 'string', 'format' => 'uuid'], '{"f":"7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b"}', '{"f":"nope"}'],
            'format uri-reference' => [['type' => 'string', 'format' => 'uri-reference'], '{"f":"/rel/path"}', '{"f":"has space "}'],
            'format date' => [['type' => 'string', 'format' => 'date'], '{"f":"2026-01-01"}', '{"f":"2026-13-45"}'],
            'format time' => [['type' => 'string', 'format' => 'time'], '{"f":"13:45:00Z"}', '{"f":"99:99"}'],
            'format duration' => [['type' => 'string', 'format' => 'duration'], '{"f":"P1DT2H"}', '{"f":"P"}'],
            'format regex' => [['type' => 'string', 'format' => 'regex'], '{"f":"^a.*$"}', '{"f":"(["}'],
            'format json-pointer' => [['type' => 'string', 'format' => 'json-pointer'], '{"f":"/a/b"}', '{"f":"a/b"}'],
            'format hostname' => [['type' => 'string', 'format' => 'hostname'], '{"f":"example.com"}', '{"f":"not a host"}'],
            'format ipv4' => [['type' => 'string', 'format' => 'ipv4'], '{"f":"1.2.3.4"}', '{"f":"999.1.1.1"}'],
            'format ipv6' => [['type' => 'string', 'format' => 'ipv6'], '{"f":"::1"}', '{"f":"zz::"}'],
            'format byte' => [['type' => 'string', 'format' => 'byte'], '{"f":"aGVsbG8="}', '{"f":"!!!"}'],
            'format int32' => [['type' => 'integer', 'format' => 'int32'], '{"f":5}', '{"f":2147483648}'],
            'format int64 fractional' => [['type' => 'number', 'format' => 'int64'], '{"f":42}', '{"f":1.5}'],
            'format uint32' => [['type' => 'integer', 'format' => 'uint32'], '{"f":5}', '{"f":-1}'],
            'format uint64' => [['type' => 'number', 'format' => 'uint64'], '{"f":5}', '{"f":-1}'],
            // Composition nested inside a subschema: the property-level attribute path does not
            // cover these, only the generated callback does.
            'anyOf inside items' => [
                ['type' => 'array', 'minItems' => 1, 'items' => ['anyOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'string', 'pattern' => '^z']]]],
                '{"f":["abc"]}',
                '{"f":["ab"]}',
            ],
            'oneOf inside items' => [
                ['type' => 'array', 'minItems' => 1, 'items' => ['oneOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'integer']]]],
                '{"f":["abc"]}',
                '{"f":["ab"]}',
            ],
            'allOf inside items' => [
                ['type' => 'array', 'minItems' => 1, 'items' => ['allOf' => [['type' => 'string'], ['minLength' => 3]]]],
                '{"f":["abc"]}',
                '{"f":["ab"]}',
            ],
            'not inside items' => [
                ['type' => 'array', 'minItems' => 1, 'items' => ['not' => ['const' => 'ab']]],
                '{"f":["ok"]}',
                '{"f":["ab"]}',
            ],
            'anyOf inside additionalProperties' => [
                [
                    'type' => 'object',
                    'additionalProperties' => ['anyOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'string', 'pattern' => '^z']]],
                ],
                '{"f":{"k":"abc"}}',
                '{"f":{"k":"ab"}}',
            ],
            'anyOf inside not' => [
                ['type' => 'string', 'not' => ['anyOf' => [['const' => 'x'], ['const' => 'y']]]],
                '{"f":"ok"}',
                '{"f":"x"}',
            ],
            // `else` is the branch nothing exercised: `if/then` alone cannot tell "the else branch is
            // evaluated" from "no branch is evaluated", because both accept the same payload.
            'if else' => [
                ['type' => 'string', 'if' => ['const' => 'a'], 'then' => ['minLength' => 1], 'else' => ['minLength' => 4]],
                '{"f":"bbbb"}',
                '{"f":"bb"}',
            ],
            'content base64 json' => [
                [
                    'type' => 'string',
                    'contentEncoding' => 'base64',
                    'contentMediaType' => 'application/json',
                    'contentSchema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer', 'minimum' => 5]]],
                ],
                '{"f":"' . base64_encode('{"a":9}') . '"}',
                '{"f":"' . base64_encode('{"a":1}') . '"}',
            ],
        ];

        $provided = [];
        foreach ($cases as $key => [$schema, $valid, $invalid]) {
            $provided[$key] = [$key, $schema, $valid, $invalid];
        }

        return $provided;
    }

    /**
     * The keyword matrix above cannot host `additionalProperties: false` or
     * `unevaluatedProperties: false` on a DTO-shaped schema, because neither mode rejects the
     * payload the keyword forbids — the case would fail the "must discriminate" sanity check. That
     * is worth stating explicitly rather than leaving as a hole in the matrix.
     *
     * Both modes bind the payload into a typed object BEFORE validating it, and an undeclared key has
     * nowhere to go: the Symfony serializer drops it, and the runtime deserializer only reads the
     * properties the schema declares. So the rule can only fire where the value stays an array — a
     * map (`additionalProperties: {…}`), which the matrix does cover.
     *
     * A rule that reads the declared keys still works on a DTO value; see
     * `GeneratedConstraintsIntegrationTest::testDeclaredPropertiesAreNotReportedAsUnevaluatedOnADtoValue`,
     * where dropping those names made a valid payload fail.
     */
    public function testUnknownPayloadKeysAreDroppedBeforeValidationInBothModes(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'object',
                                'properties' => ['known' => ['type' => 'string']],
                                'additionalProperties' => false,
                                'unevaluatedProperties' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $key = 'unknown keys are dropped';
        $withExtra = '{"f":{"known":"a","extra":"b"}}';
        $expected = ['valid' => true, 'invalid' => true];

        $this->assertSame($expected, $this->runtimeVerdict($spec, $key, '{"f":{"known":"a"}}', $withExtra));
        $this->assertSame($expected, $this->symfonyVerdict($spec, $key, '{"f":{"known":"a"}}', $withExtra));
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function runtimeVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $namespace = 'ParityRt' . $this->namespaceSuffix($key);
        $fqcn = $this->generate($spec, $namespace, 'runtime');
        $deserializer = new DtoDeserializer();
        $normalizer = new DtoNormalizer();

        $accepts = static function (string $json) use ($deserializer, $normalizer, $fqcn): bool {
            $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
            try {
                // The runtime deserializer validates on the way in; normalizing re-checks the DTO.
                $normalizer->validateAndNormalizeToArray($deserializer->deserialize($request, $fqcn));
            } catch (Throwable) {
                return false;
            }

            return true;
        };

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function symfonyVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $namespace = 'ParitySy' . $this->namespaceSuffix($key);
        $fqcn = $this->generate($spec, $namespace, 'symfony');

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    $classMetadataFactory,
                    new MetadataAwareNameConverter($classMetadataFactory),
                    null,
                    $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $accepts = static function (string $json) use ($serializer, $validator, $fqcn): bool {
            try {
                $dto = $serializer->deserialize($json, $fqcn, 'json');
            } catch (Throwable) {
                return false;
            }

            return count($validator->validate($dto)) === 0;
        };

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generate(array $spec, string $namespace, string $mode): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        return $fqcn;
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
