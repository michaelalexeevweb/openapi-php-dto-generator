<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
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
final class ValidationParityTest extends TestCase
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
        $laravel = $this->laravelVerdict($spec, $key, $validJson, $invalidJson);

        $describe = static fn(array $verdict): string => sprintf(
            'valid=%s invalid=%s',
            $verdict['valid'] ? 'accepted' : 'REJECTED',
            $verdict['invalid'] ? 'ACCEPTED' : 'rejected',
        );
        $message = sprintf(
            "modes disagree on %s\n runtime: %s\n symfony: %s\n laravel: %s",
            $key,
            $describe($runtime),
            $describe($symfony),
            $describe($laravel),
        );

        $this->assertSame($runtime, $symfony, $message);
        $this->assertSame($runtime, $laravel, $message);

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
            // An object with no `properties` that constrains only its KEYS stays a map, because a DTO
            // has nothing to hold what those keywords describe. The keyword is then enforced over the
            // map — which is the half that was missing: the schemas below used to become a class with
            // no properties that accepted the payload and dropped it.
            'required on a free-form object' => [
                ['type' => 'object', 'required' => ['a']],
                '{"f":{"a":1}}',
                '{"f":{"b":1}}',
            ],
            'dependentRequired on a free-form object' => [
                ['type' => 'object', 'dependentRequired' => ['kind' => ['x1']]],
                '{"f":{"kind":1,"x1":2}}',
                '{"f":{"kind":1}}',
            ],
            'propertyNames on a free-form object' => [
                ['type' => 'object', 'propertyNames' => ['pattern' => '^x']],
                '{"f":{"xa":1}}',
                '{"f":{"ya":1}}',
            ],
            'unevaluatedProperties false on a free-form object' => [
                ['type' => 'object', 'unevaluatedProperties' => false],
                '{"f":{}}',
                '{"f":{"a":1}}',
            ],
            // The formats the runtime validator and the Symfony matrix each cover on their own, brought
            // into the three-mode comparison so the support matrix can claim them for laravel too.
            'format idn-email' => [['type' => 'string', 'format' => 'idn-email'], '{"f":"a@b.co"}', '{"f":"nope"}'],
            // `uri` and `iri` are ABSOLUTE; only the `*-reference` forms take a relative value. The
            // emitted interpreter used to map all four to the reference check and accept `/rel/path`.
            'format uri' => [['type' => 'string', 'format' => 'uri'], '{"f":"https://a.example/x"}', '{"f":"/rel/path"}'],
            'format iri' => [
                ['type' => 'string', 'format' => 'iri'],
                '{"f":"https://пример.example/путь"}',
                '{"f":"/rel/path"}',
            ],
            'format iri-reference' => [
                ['type' => 'string', 'format' => 'iri-reference'],
                '{"f":"/rel/path"}',
                '{"f":"has space "}',
            ],
            'format uri-template' => [
                ['type' => 'string', 'format' => 'uri-template'],
                '{"f":"/a{/b}"}',
                '{"f":"/a{unclosed"}',
            ],
            'format idn-hostname' => [
                ['type' => 'string', 'format' => 'idn-hostname'],
                '{"f":"example.com"}',
                '{"f":"not a host"}',
            ],
            'format relative-json-pointer' => [
                ['type' => 'string', 'format' => 'relative-json-pointer'],
                '{"f":"1/a"}',
                '{"f":"/a"}',
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
     * A JSON array is not an object, and `{"f":[1,2]}` used to be accepted in every mode — read as a map
     * keyed 0..n-1. The distinction only exists in the RAW body: once PHP has decoded it, an object whose
     * keys are exactly 0..n-1 and an array are the same value.
     *
     * So the check lives wherever the raw shape is still reachable: the runtime deserializer decodes the
     * body itself, and a Laravel FormRequest hands `withValidator()` the undecoded body. Symfony mode
     * cannot — `#[MapRequestPayload]` denormalizes first and a constraint only ever sees the array — so
     * it is the one mode that still accepts it, pinned here rather than left to be discovered.
     */
    public function testAJsonArrayIsRefusedForATypeObjectPropertyWhereverTheRawBodyIsReachable(): void
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
                        'properties' => ['f' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'integer'],
                        ]],
                    ],
                ],
            ],
        ];

        $key = 'object vs array';
        $asArray = '{"f":[1,2]}';

        // A plain map is accepted, the array is not.
        $expected = ['valid' => true, 'invalid' => false];
        $this->assertSame($expected, $this->runtimeVerdict($spec, $key, '{"f":{"a":1}}', $asArray));
        $this->assertSame($expected, $this->laravelVerdict($spec, $key, '{"f":{"a":1}}', $asArray));

        // And a JSON object whose keys are 0..n-1 stays an object — the check must not overreach.
        $this->assertSame($expected, $this->runtimeVerdict($spec, $key . ' dense', '{"f":{"0":1,"1":2}}', $asArray));
        $this->assertSame($expected, $this->laravelVerdict($spec, $key . ' dense', '{"f":{"0":1,"1":2}}', $asArray));

        // Symfony mode accepts both: by the time the constraint runs, the array IS the map.
        $this->assertSame(
            ['valid' => true, 'invalid' => true],
            $this->symfonyVerdict($spec, $key, '{"f":{"a":1}}', $asArray),
        );

        // The same question for a SCHEMA-shaped object, which becomes a nested DTO: with no required
        // property, `[1,2]` used to hydrate an object with everything absent.
        $nested = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => ['$ref' => '#/components/schemas/Inner']],
                    ],
                    'Inner' => [
                        'type' => 'object',
                        'properties' => ['a' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $this->runtimeVerdict($nested, 'nested object', '{"f":{"a":1}}', $asArray));
        $this->assertSame($expected, $this->laravelVerdict($nested, 'nested object', '{"f":{"a":1}}', $asArray));
    }

    /**
     * JSON Schema 2020-12 §6.1.1: a number with a ZERO fractional part is an integer. So `42.0` is a
     * valid `type: integer` payload and `42.5` is not — while PHP decodes both to a float, which is why
     * all three modes used to reject both alike.
     *
     * Runtime and Laravel mode now follow the spec, hydration included. Symfony mode cannot: the
     * serializer type-checks `int $f` before any generated constraint is reached, and that check lives
     * in the caller's serializer rather than in emitted code. It rejects cleanly (a denormalization
     * error, not a TypeError), and the deviation is pinned here so it stays a known boundary.
     */
    public function testAnIntegralFloatIsAnIntegerWhereverTheGeneratorOwnsTheCheck(): void
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
                        'properties' => ['f' => ['type' => 'integer', 'minimum' => 10]],
                    ],
                ],
            ],
        ];

        $key = 'integral float';
        $integral = '{"f":42.0}';
        $fractional = '{"f":42.5}';

        $expected = ['valid' => true, 'invalid' => false];
        $this->assertSame($expected, $this->runtimeVerdict($spec, $key, $integral, $fractional));
        $this->assertSame($expected, $this->laravelVerdict($spec, $key, $integral, $fractional));

        // Both rejected — the serializer never hands the value to a constraint.
        $this->assertSame(
            ['valid' => false, 'invalid' => false],
            $this->symfonyVerdict($spec, $key, $integral, $fractional),
        );
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
     * OPTIONAL is not NULLABLE, and the keyword matrix cannot say so: every probe there makes the
     * property required, so a present-but-null value for an optional property was measured by nobody.
     *
     * It was accepted in laravel mode, because the rule builder emitted `nullable` for every property
     * that was not required — `sometimes` already covers the absent key, so all `nullable` added was
     * permission to send a value the schema never allowed. Runtime mode rejects it, and that is the
     * verdict this pins.
     *
     * Symfony mode cannot: the property is optional, therefore its PHP type is nullable, therefore no
     * `#[Assert\NotNull]` is emitted — a known boundary, asserted here rather than left to be found.
     */
    public function testAnOptionalPropertyIsNotNullable(): void
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
                        'required' => ['keep'],
                        'properties' => [
                            'keep' => ['type' => 'string'],
                            // Optional, and `nullable` is nowhere in the schema.
                            'f' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];

        $key = 'optional is not nullable';
        $valid = '{"keep":"x","f":["a"]}';
        $asNull = '{"keep":"x","f":null}';

        $expected = ['valid' => true, 'invalid' => false];
        $this->assertSame($expected, $this->runtimeVerdict($spec, $key, $valid, $asNull));
        $this->assertSame($expected, $this->laravelVerdict($spec, $key, $valid, $asNull));

        $this->assertSame(
            ['valid' => true, 'invalid' => true],
            $this->symfonyVerdict($spec, $key, $valid, $asNull),
        );

        // The same property WITH `nullable` must accept the null in every mode, or the fix above
        // would read as "laravel rejects null", which is not what the schema asked for.
        $nullableSpec = $spec;
        $nullableSpec['components']['schemas']['Probe']['properties']['f']['nullable'] = true;
        foreach (['runtimeVerdict', 'laravelVerdict', 'symfonyVerdict'] as $mode) {
            $this->assertSame(
                ['valid' => true, 'invalid' => true],
                $this->{$mode}($nullableSpec, $key . ' nullable', $valid, $asNull),
                $mode . ' must accept null for a nullable property',
            );
        }
    }

    /**
     * A schema that refers to itself, enforced at EVERY depth in every mode.
     *
     * The other two get this for free: Symfony cascades through `#[Assert\Valid]` into each nested DTO's
     * own constraints, and the runtime validator walks the schema it holds in memory. Laravel mode has one
     * flat rule map and one emitted schema literal, and a recursive schema has no finite inline form — so
     * the walk used to stop at the first turn of the cycle. Measured then: a CHILD violating `minimum: 1`
     * was accepted while the identical violation at the root was reported, and `laravelNestedRules()`
     * emitted no `children.*.id` path to cover it either. Nothing reported it at all.
     *
     * Now the fold is emitted once and re-entered through a marker, so this walks as deep as the payload.
     */
    #[DataProvider('recursiveDepthProvider')]
    public function testARecursiveSchemaIsEnforcedAtEveryDepthInEveryMode(string $key, string $invalidJson): void
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
                        'properties' => ['f' => ['$ref' => '#/components/schemas/TreeNode']],
                    ],
                    'TreeNode' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'label' => ['type' => 'string', 'not' => ['const' => 'forbidden']],
                            'parent' => ['$ref' => '#/components/schemas/TreeNode'],
                            'children' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/TreeNode'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $valid = '{"f":{"id":1,"label":"ok","children":[{"id":2}]}}';
        $expected = ['valid' => true, 'invalid' => false];

        $this->assertSame($expected, $this->runtimeVerdict($spec, 'rec rt ' . $key, $valid, $invalidJson));
        $this->assertSame($expected, $this->symfonyVerdict($spec, 'rec sy ' . $key, $valid, $invalidJson));
        $this->assertSame($expected, $this->laravelVerdict($spec, 'rec lv ' . $key, $valid, $invalidJson));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function recursiveDepthProvider(): array
    {
        $cases = [
            // A keyword the rules DO express, at each depth — this is the half that went missing.
            'root minimum' => '{"f":{"id":0}}',
            'child minimum' => '{"f":{"id":1,"children":[{"id":0}]}}',
            'grandchild minimum' => '{"f":{"id":1,"children":[{"id":2,"children":[{"id":0}]}]}}',
            'parent minimum' => '{"f":{"id":1,"parent":{"id":0}}}',
            'grandparent minimum' => '{"f":{"id":1,"parent":{"id":2,"parent":{"id":0}}}}',
            // A keyword no rule can express, which was already right and must stay right.
            'root not' => '{"f":{"id":1,"label":"forbidden"}}',
            'child not' => '{"f":{"id":1,"children":[{"id":2,"label":"forbidden"}]}}',
            'grandchild not' => '{"f":{"id":1,"children":[{"id":2,"children":[{"id":3,"label":"forbidden"}]}]}}',
            'child required' => '{"f":{"id":1,"children":[{"label":"x"}]}}',
            'grandchild required' => '{"f":{"id":1,"children":[{"id":2,"children":[{"label":"x"}]}]}}',
        ];

        $provided = [];
        foreach ($cases as $key => $invalidJson) {
            $provided[$key] = [$key, $invalidJson];
        }

        return $provided;
    }

    /**
     * `format: date-time` accepts exactly the four patterns every mode agrees on
     * (`GeneratedDtoInterface::DATE_TIME_FORMATS`). Symfony mode cannot hold that line: the property is a
     * `DateTimeImmutable`, so the serializer parses the string before any constraint runs — and PHP's
     * parser is generous enough to accept `"yesterday"`.
     *
     * Same shape as the `42.0` and JSON-array boundaries: the serializer decides before generated code
     * gets a say. Pinned rather than left to be discovered, and the reason it is not in the agreeing
     * keyword matrix.
     */
    public function testALooseDateTimeStringIsRefusedWhereverTheGeneratorOwnsTheCheck(): void
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
                        'properties' => ['f' => ['type' => 'string', 'format' => 'date-time']],
                    ],
                ],
            ],
        ];

        $key = 'loose date-time';
        $valid = '{"f":"2026-03-10T12:00:00+00:00"}';
        $loose = '{"f":"yesterday"}';

        $expected = ['valid' => true, 'invalid' => false];
        $this->assertSame($expected, $this->runtimeVerdict($spec, $key, $valid, $loose));
        $this->assertSame($expected, $this->laravelVerdict($spec, $key, $valid, $loose));

        $this->assertSame(
            ['valid' => true, 'invalid' => true],
            $this->symfonyVerdict($spec, $key, $valid, $loose),
        );
    }

    /**
     * MUTUAL recursion — `A.b` is a `B` and `B.a` is an `A` — which is where a per-class fold could have
     * looped forever while being built. It terminates because the fold is registered under its class name
     * before it is computed, and the marker branch finds the key already there.
     */
    public function testMutuallyRecursiveSchemasAreEnforcedInEveryMode(): void
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
                        'properties' => ['f' => ['$ref' => '#/components/schemas/A']],
                    ],
                    'A' => [
                        'type' => 'object',
                        'required' => ['aid'],
                        'properties' => [
                            'aid' => ['type' => 'integer', 'minimum' => 1],
                            'b' => ['$ref' => '#/components/schemas/B'],
                        ],
                    ],
                    'B' => [
                        'type' => 'object',
                        'required' => ['bid'],
                        'properties' => [
                            'bid' => ['type' => 'integer', 'minimum' => 10],
                            'a' => ['$ref' => '#/components/schemas/A'],
                        ],
                    ],
                ],
            ],
        ];

        $valid = '{"f":{"aid":1,"b":{"bid":10,"a":{"aid":2,"b":{"bid":11}}}}}';
        $expected = ['valid' => true, 'invalid' => false];

        // The violation sits three hops in, past two turns of the A -> B -> A cycle.
        $invalid = '{"f":{"aid":1,"b":{"bid":10,"a":{"aid":2,"b":{"bid":9}}}}}';

        $this->assertSame($expected, $this->runtimeVerdict($spec, 'mutual rt', $valid, $invalid));
        $this->assertSame($expected, $this->symfonyVerdict($spec, 'mutual sy', $valid, $invalid));
        $this->assertSame($expected, $this->laravelVerdict($spec, 'mutual lv', $valid, $invalid));
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
    private function laravelVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $namespace = 'ParityLv' . $this->namespaceSuffix($key);
        $fqcn = $this->generate($spec, $namespace, 'laravel');
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));

        $accepts = static function (string $json) use ($factory, $fqcn): bool {
            /** @var array<string, mixed>|null $payload */
            $payload = json_decode($json, true);
            if (!is_array($payload)) {
                return false;
            }

            /** @var array<string, mixed> $rules */
            $rules = call_user_func([$fqcn, 'rules']);
            $validator = $factory->make($payload, $rules);
            if (method_exists($fqcn, 'withValidator')) {
                // The raw body is passed exactly as the generated FormRequest passes it: `type: object`
                // versus `type: array` is a wire-shape question the decoded payload can no longer answer.
                call_user_func([$fqcn, 'withValidator'], $validator, $json);
            }

            if ($validator->fails()) {
                return false;
            }

            // A FormRequest hands the controller `toDto()`, so hydration is part of the verdict: a
            // payload the rules accept but the DTO cannot hold is not "accepted" in any useful sense.
            try {
                /** @var array<string, mixed> $validated */
                $validated = $validator->validated();
                call_user_func([$fqcn, 'fromValidated'], $validated);
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
