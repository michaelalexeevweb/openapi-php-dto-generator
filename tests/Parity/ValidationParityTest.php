<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Error;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
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
 * Every generation mode must agree on what a payload means.
 *
 * Each case is generated once per `GenerationMode` from the same spec and fed the same valid/invalid JSON
 * — runtime through `DtoDeserializer` + `DtoNormalizer`, Symfony through the serializer + validator,
 * Laravel through the emitted rules and `withValidator()` — and the verdicts are compared. A keyword
 * implemented in only one mode (or implemented differently) fails here, which is how the four
 * historical divergences (dependentRequired/dependentSchemas on DTO values, uint32/uint64 bounds,
 * scalar `allOf`) were found.
 *
 * The modes come from `GenerationMode::cases()`, so a mode added there is enrolled in every case below
 * without touching them; a mode that genuinely cannot give the common answer says so through
 * `diverges()`, with the reason.
 */
final class ValidationParityTest extends TestCase
{
    use ComparesModes;

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
    public function testEveryModeAgrees(string $key, array $propertySchema, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);

        // The expectation is the DISCRIMINATING verdict, not merely "the modes agree": a case where
        // every mode accepts everything would compare equal for the wrong reason, and a rule that
        // enforces nothing is invisible otherwise.
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            self::declaredDivergences()[$key] ?? [],
            context: $key,
        );
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
            // The same permission with `null` written FIRST — one document, two spellings.
            'type union null first' => [['type' => ['null', 'string']], '{"f":null}', '{"f":5}'],
            'enum' => [['type' => 'string', 'enum' => ['a', 'b']], '{"f":"a"}', '{"f":"z"}'],
            'const' => [['type' => 'string', 'const' => 'a'], '{"f":"a"}', '{"f":"b"}'],
            'minLength' => [['type' => 'string', 'minLength' => 3], '{"f":"abc"}', '{"f":"ab"}'],
            'maxLength' => [['type' => 'string', 'maxLength' => 2], '{"f":"ab"}', '{"f":"abc"}'],
            'pattern' => [['type' => 'string', 'pattern' => '^a'], '{"f":"ab"}', '{"f":"ba"}'],
            'minimum' => [['type' => 'integer', 'minimum' => 3], '{"f":3}', '{"f":2}'],
            'maximum' => [['type' => 'integer', 'maximum' => 3], '{"f":3}', '{"f":4}'],
            'exclusiveMinimum' => [['type' => 'integer', 'exclusiveMinimum' => 3], '{"f":4}', '{"f":3}'],
            'exclusiveMaximum' => [['type' => 'integer', 'exclusiveMaximum' => 3], '{"f":2}', '{"f":3}'],
            // The OpenAPI 3.0 spelling of the same two bounds: a BOOLEAN modifier on `minimum` /
            // `maximum` rather than a number of its own. Both spellings are legal and a document
            // may use either, so both are measured.
            'exclusiveMinimum as a 3.0 boolean' => [
                ['type' => 'integer', 'minimum' => 3, 'exclusiveMinimum' => true],
                '{"f":4}',
                '{"f":3}',
            ],
            'exclusiveMaximum as a 3.0 boolean' => [
                ['type' => 'integer', 'maximum' => 3, 'exclusiveMaximum' => true],
                '{"f":2}',
                '{"f":3}',
            ],
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
            // The container twins of the case above. A `format` one level down was the 2.15.3 bug —
            // the item was typed as a date and then nothing enforced it on the way out — and nothing
            // here asserted the way IN either, in any mode.
            'format date in a list' => [
                ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                '{"f":["2026-01-01"]}',
                '{"f":["2026-13-45"]}',
            ],
            'format date in a map' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'format' => 'date']],
                '{"f":{"a":"2026-01-01"}}',
                '{"f":{"a":"2026-13-45"}}',
            ],
            // An OBJECT two containers deep. Nothing is materialized down there, so no class checks
            // it and the check has to come from the emitted constraints — which for a long while it
            // did not: a value below its `minimum` and a missing `required` property were both
            // accepted in silence, in every mode.
            'object properties two containers deep' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['id' => ['type' => 'integer', 'minimum' => 5]],
                        ],
                    ],
                ],
                '{"f":[[{"id":9}]]}',
                '{"f":[[{"id":1}]]}',
            ],
            'object required two containers deep' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id'],
                            'properties' => ['id' => ['type' => 'integer']],
                        ],
                    ],
                ],
                '{"f":[[{"id":9}]]}',
                '{"f":[[{}]]}',
            ],
            // And the value that is not an object at all, where one was declared.
            'a scalar where an object belongs two containers deep' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['id' => ['type' => 'integer']],
                        ],
                    ],
                ],
                '{"f":[[{"id":9}]]}',
                '{"f":[["zzz"]]}',
            ],
            'format date-time in a list' => [
                ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']],
                '{"f":["2026-01-01T10:00:00+00:00"]}',
                '{"f":["2026-13-45T99:99:99+00:00"]}',
            ],
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

        $spec = self::probeSpec(['type' => 'object', 'additionalProperties' => ['type' => 'integer']]);
        $key = 'object vs array';
        $asArray = '{"f":[1,2]}';

        // The two modes that build the object before validating it: by then the array IS the map, and
        // nothing downstream can tell the two apart again.
        $symfonyIsBlind = array_merge(
            self::diverges(
                GenerationMode::Symfony,
                ['valid' => true, 'invalid' => true],
                'the serializer denormalizes before any constraint runs, so the array IS the map by then',
            ),
            self::diverges(
                GenerationMode::Yii3,
                ['valid' => true, 'invalid' => true],
                'the hydrator fills the object before the validator runs, and it reads the object, not the body',
            ),
        );

        // A plain map is accepted, the array is not.
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":{"a":1}}', $asArray),
            $symfonyIsBlind,
            $key,
        );

        // And a JSON object whose keys are 0..n-1 stays an object — the check must not overreach.
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key . ' dense', '{"f":{"0":1,"1":2}}', $asArray),
            $symfonyIsBlind,
            $key . ' dense',
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

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $nested, 'nested object', '{"f":{"a":1}}', $asArray),
            $symfonyIsBlind,
            'nested object',
        );
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

        $spec = self::probeSpec(['type' => 'integer', 'minimum' => 10]);
        $key = 'integral float';

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":42.0}', '{"f":42.5}'),
            array_merge(
                // Both rejected — the serializer never hands the value to a constraint.
                self::diverges(
                    GenerationMode::Symfony,
                    ['valid' => false, 'invalid' => false],
                    'the serializer type-checks `int $f` first, so 42.0 is refused before generated code runs',
                ),
                // Both accepted — the mirror image. The hydrator CASTS to the declared `int` before the
                // validator sees the object, so 42.5 arrives as 42 and clears `minimum: 10`.
                self::diverges(
                    GenerationMode::Yii3,
                    ['valid' => true, 'invalid' => true],
                    'the hydrator casts to the declared type first, so 42.5 is an int by the time a rule runs',
                ),
            ),
            $key,
        );
    }

    /**
     * The keyword matrix above cannot host `additionalProperties: false` or
     * `unevaluatedProperties: false` on a DTO-shaped schema, because the modes do not agree on the
     * payload the keyword forbids — the case would fail the "must discriminate" sanity check in two
     * modes and pass in the third. That is worth stating explicitly rather than leaving as a hole.
     *
     * Runtime and Symfony bind the payload into a typed object BEFORE validating it, and an undeclared
     * key has nowhere to go: the Symfony serializer drops it, and the runtime deserializer only reads
     * the properties the schema declares. So for them the rule can only fire where the value stays an
     * array — a map (`additionalProperties: {…}`), which the matrix does cover.
     *
     * Laravel mode does reject it, and that surfaced only once this suite iterated its modes instead of
     * naming two of them: the emitted interpreter runs over the raw payload before anything is
     * hydrated, so `extra` is still there to be seen (`f has additional property "extra" which is not
     * allowed`). It is the reading the schema asked for; the other two are the ones falling short, and
     * closing that gap is a change to the runtime deserializer, not to this test.
     *
     * A rule that reads the declared keys still works on a DTO value; see
     * `GeneratedConstraintsIntegrationTest::testDeclaredPropertiesAreNotReportedAsUnevaluatedOnADtoValue`,
     * where dropping those names made a valid payload fail.
     */
    public function testAClosedObjectRefusesAnUndeclaredKeyWhereverTheRawBodyIsReachable(): void
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

        // The CONFORMANT answer is refusal: the document closed the object. Every mode that can
        // still see the raw body gives it — runtime holds the body, and the two rule-based modes
        // run their interpreter over it. The two that cannot are named below.
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":{"known":"a"}}', $withExtra),
            [
                ...self::diverges(
                    GenerationMode::Symfony,
                    ['valid' => true, 'invalid' => true],
                    'the serializer denormalizes before any constraint runs, so the undeclared key is '
                        . 'already gone by the time generated code is reached',
                ),
                ...self::diverges(
                    GenerationMode::Yii3,
                    ['valid' => true, 'invalid' => true],
                    'the hydrator fills the object first and the validator reads the OBJECT, so a key '
                        . 'the schema never declared has nowhere to survive',
                ),
            ],
            $key,
        );
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

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $valid, $asNull),
            self::diverges(
                GenerationMode::Symfony,
                ['valid' => true, 'invalid' => true],
                'an optional property has a nullable PHP type, so no #[Assert\NotNull] is emitted for it',
            ),
            $key,
        );

        // The same property WITH `nullable` must accept the null in every mode, or the assertion above
        // would read as "laravel rejects null", which is not what the schema asked for.
        $nullableSpec = $spec;
        $nullableSpec['components']['schemas']['Probe']['properties']['f']['nullable'] = true;
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => true],
            fn(GenerationMode $mode): array => $this->verdict($mode, $nullableSpec, $key . ' nullable', $valid, $asNull),
            context: $key . ' nullable',
        );
    }

    /**
     * `nullable: true` NEXT TO a union means the same as a `{type: null}` variant INSIDE it, and only the
     * spelled form used to reach the emitted interpreter.
     *
     * So a document written the first way had its own `null` refused — "does not match any oneOf branch
     * (expected integer or string, got null)" — in symfony, laravel and laravel-data mode, while runtime
     * mode read the schema directly and always accepted it. Three modes wrong about a value the document
     * explicitly allows, and no case in the matrix could see it: every probe there is either a union
     * without `nullable` or a `nullable` without a union.
     */
    public function testNullableNextToAUnionMeansTheSameAsANullBranch(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // Both spellings, held to the same verdict — that equivalence IS the assertion.
        $spellings = [
            'nullable beside the union' => [
                'oneOf' => [['type' => 'integer', 'minimum' => 10], ['type' => 'string']],
                'nullable' => true,
            ],
            'a null branch inside the union' => [
                'oneOf' => [['type' => 'integer', 'minimum' => 10], ['type' => 'string'], ['type' => 'null']],
            ],
        ];

        foreach ($spellings as $key => $propertySchema) {
            $spec = self::probeSpec($propertySchema);

            // `null` is allowed; an integer below the branch's own minimum is not.
            $this->assertEveryModeYields(
                ['valid' => true, 'invalid' => false],
                fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":null}', '{"f":5}'),
                context: $key,
            );
        }
    }

    /**
     * A REQUIRED property that is nullable through a `$ref` — `oneOf: [$ref, {type: null}]`, the only way
     * a document can say "this nested object may be null".
     *
     * The keyword matrix cannot host it: its probes are scalars, and the nullability of a `$ref` is not
     * visible in the property's constraint map at all — there is no `type` there to carry a `null` member.
     * Reading it instead off `$property['nullable']` is wrong for the opposite reason: the walker sets that
     * flag for every OPTIONAL property, so it cannot be trusted unless the property is required.
     *
     * Which is exactly the case this pins, and it is not hypothetical: laravel-data mode needs the answer
     * for its property TYPE (`Child|null` versus `Child`, where a wrong answer is a TypeError on a payload
     * the schema allows), and the fix moved laravel mode's emitted rules too — `['present']` became
     * `['present', 'nullable']`.
     */
    public function testARequiredPropertyNullableThroughARefIsAcceptedAsNullInEveryMode(): void
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
                        'required' => ['child'],
                        'properties' => [
                            'child' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Child'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                    ],
                    'Child' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer', 'minimum' => 5]],
                    ],
                ],
            ],
        ];

        // `null` is what the schema allows; an object missing the child's own required key is not.
        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'nullable ref', '{"child":null}', '{"child":{}}'),
            context: 'required property nullable through a $ref',
        );
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

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'rec ' . $key, $valid, $invalidJson),
            context: 'recursive ' . $key,
        );
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
     * A `$ref` chain under a UNION BRANCH below a container, enforced at every hop in every mode.
     *
     * The recursive case above is the other shape: there the chain repeats, a class materializes at the
     * first hop, and each mode's own cascade carries the rest. Here nothing materializes anywhere —
     * under a container the branch type collapses to `array` — so every level of the chain reaches the
     * consumer ONLY through the constraints the generator inlines, and each mode then has to read those
     * constraints with a different machine: Symfony builds `Assert` specs from them, Laravel a flat rule
     * map, laravel-data its own, runtime walks the schema it holds.
     *
     * 2.15.8 and 2.15.9 were measured on runtime alone, which is exactly the gap that let a recursive
     * schema go unchecked in Laravel mode once before. This is the same question asked of all five.
     *
     * Both branch keywords, because they were broken and fixed a release apart: `oneOf` in 2.15.6-9,
     * `allOf` only in 2.15.11, where it had been skipped on the rule that its `$ref` gets a class —
     * true on a property, false under a container.
     *
     * @param string $invalidJson a payload violating the chain at ONE level
     * @param string $branchKeyword `oneOf` or `allOf`, the two spellings of the same chain
     */
    #[DataProvider('unionChainDepthProvider')]
    public function testARefChainUnderAUnionBranchIsEnforcedAtEveryHopInEveryMode(
        string $key,
        string $invalidJson,
        string $branchKeyword,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ChainTag' => [
                        'type' => 'object',
                        'required' => ['label'],
                        'properties' => ['label' => ['type' => 'string', 'minLength' => 2]],
                    ],
                    'ChainLink' => [
                        'type' => 'object',
                        'required' => ['code'],
                        'properties' => [
                            'code' => ['type' => 'string', 'minLength' => 3],
                            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ChainTag']],
                        ],
                    ],
                    'ChainMiddle' => [
                        'type' => 'object',
                        'required' => ['links'],
                        'properties' => [
                            'links' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ChainLink']],
                        ],
                    ],
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => ['type' => 'array', 'items' => [$branchKeyword => [
                                ['$ref' => '#/components/schemas/ChainMiddle'],
                            ]]],
                        ],
                    ],
                ],
            ],
        ];

        $valid = '{"f":[{"links":[{"code":"abc","tags":[{"label":"ok"}]}]}]}';

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            // The branch keyword belongs in the namespace key: without it both spellings generate into
            // one namespace, PHP keeps the classes it loaded first, and the second spelling silently
            // re-tests the first one's classes — green and proving nothing.
            fn(GenerationMode $mode): array => $this->verdict(
                $mode,
                $spec,
                'chain ' . $branchKeyword . ' ' . $key,
                $valid,
                $invalidJson,
            ),
            self::declaredUnionChainDivergences()[$key] ?? [],
            context: $branchKeyword . ' chain ' . $key,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unionChainDepthProvider(): array
    {
        $cases = [
            // B: the hop every version of the inlining reached.
            'B required' => '{"f":[{}]}',
            // C: reached from 2.15.8.
            'C minLength' => '{"f":[{"links":[{"code":"x"}]}]}',
            'C required' => '{"f":[{"links":[{}]}]}',
            // D: reached from 2.15.9.
            'D minLength' => '{"f":[{"links":[{"code":"abc","tags":[{"label":"z"}]}]}]}',
            'D required' => '{"f":[{"links":[{"code":"abc","tags":[{}]}]}]}',
        ];

        $provided = [];
        foreach (['oneOf', 'allOf'] as $branchKeyword) {
            foreach ($cases as $key => $invalidJson) {
                $provided[$branchKeyword . ' ' . $key] = [$key, $invalidJson, $branchKeyword];
            }
        }

        return $provided;
    }

    /**
     * @return array<string, array<string, array{expected: array{valid: bool, invalid: bool}, reason: string}>>
     */
    private static function declaredUnionChainDivergences(): array
    {
        return [];
    }

    /**
     * The same cycle, entered from the class that IS the cycle: `Probe.children` is a list of `Probe`.
     *
     * The case above always reaches the recursive schema through a non-recursive root, and that is the
     * only shape the suite ever measured — which hid a hole the same size as the one it was written for.
     * Seen from the root, the first `$ref` back to the root looked like a fresh class: it was expanded
     * one level and PRUNED of everything the dotted rules were assumed to cover, while
     * `laravelNestedRules()` had emitted no `children.*` path at all, because it cannot expand a cycle
     * either. Measured: a child violating `minimum: 1` was ACCEPTED in laravel and laravel-data mode, and
     * a child sending a string for an integer died as a `TypeError` in the constructor rather than as a
     * 422. Runtime and Symfony walk the real schema and were right all along.
     */
    #[DataProvider('rootRecursiveDepthProvider')]
    public function testARecursiveSchemaIsEnforcedWhenItIsTheRootClassItself(string $key, string $invalidJson): void
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
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'label' => ['type' => 'string', 'not' => ['const' => 'forbidden']],
                            'children' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Probe'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $valid = '{"id":1,"label":"ok","children":[{"id":2,"children":[{"id":3}]}]}';

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'rootrec ' . $key, $valid, $invalidJson),
            context: 'root-recursive ' . $key,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rootRecursiveDepthProvider(): array
    {
        $cases = [
            'root minimum' => '{"id":0}',
            'child minimum' => '{"id":1,"children":[{"id":0}]}',
            'grandchild minimum' => '{"id":1,"children":[{"id":2,"children":[{"id":0}]}]}',
            'child type' => '{"id":1,"children":[{"id":"nope"}]}',
            'child required' => '{"id":1,"children":[{"label":"x"}]}',
            'child not' => '{"id":1,"children":[{"id":2,"label":"forbidden"}]}',
            'grandchild not' => '{"id":1,"children":[{"id":2,"children":[{"id":3,"label":"forbidden"}]}]}',
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

        // The violation sits three hops in, past two turns of the A -> B -> A cycle.
        $invalid = '{"f":{"aid":1,"b":{"bid":10,"a":{"aid":2,"b":{"bid":9}}}}}';

        $this->assertEveryModeYields(
            ['valid' => true, 'invalid' => false],
            fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'mutual', $valid, $invalid),
            context: 'mutual recursion',
        );
    }

    /**
     * The one place a mode name turns into an implementation. No `default` arm on purpose: a mode
     * added to `GenerationMode` without a verdict here fails with `UnhandledMatchError` instead of
     * quietly going unmeasured.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function verdict(GenerationMode $mode, array $spec, string $key, string $validJson, string $invalidJson): array
    {
        return match ($mode) {
            GenerationMode::Runtime => $this->runtimeVerdict($spec, $key, $validJson, $invalidJson),
            GenerationMode::Symfony => $this->symfonyVerdict($spec, $key, $validJson, $invalidJson),
            GenerationMode::Laravel => $this->laravelVerdict($spec, $key, $validJson, $invalidJson),
            GenerationMode::LaravelData => $this->laravelDataVerdict($spec, $key, $validJson, $invalidJson),
            GenerationMode::Yii3 => $this->yii3Verdict($spec, $key, $validJson, $invalidJson),
        };
    }

    /**
     * Hydration AND validation, as in every other mode: a payload the rules accept but the object
     * cannot hold is not "accepted" in any useful sense — and in this mode hydration is where several
     * rejections actually happen (an out-of-range enum, a nested shape that will not build).
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function yii3Verdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Yii3, $key), 'yii3');
        $this->skipYii3WithoutIntl($fqcn, $validJson, $key);

        $accepts = static function (string $json) use ($fqcn): bool {
            $payload = json_decode($json, true);
            if (!is_array($payload)) {
                return false;
            }

            try {
                $container = new Yii3Container();

                return $container->validate($container->hydrate($fqcn, $payload))->isValid();
            } catch (Throwable) {
                return false;
            }
        };

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * The keyword cases yii3 mode cannot answer the common way, and why.
     *
     * Both reasons are properties of the FRAMEWORK, not gaps in the emitter, and both were measured
     * before being written down here.
     *
     * @return array<string, array<string, array{expected: mixed, reason: string}>>
     */
    private static function declaredDivergences(): array
    {
        $coerced = self::diverges(
            GenerationMode::Yii3,
            ['valid' => true, 'invalid' => true],
            'the hydrator CASTS before any rule runs — PhpNativeTypeCaster turned 5 into "5", so the '
            . 'type rule sees a valid value. Dropping that caster was measured too and is worse: '
            . 'valid payloads stop hydrating as well. Symfony mode diverges here for the same reason.',
        );

        // Symfony types the property `DateTimeImmutable`, so the serializer parses the string before
        // any constraint runs and PHP's parser is generous — the same leniency the scalar case
        // records. Pinned again one level down, because a container is reached through different
        // code and "the scalar diverges" is not proof that the item does.
        $lenientDateTime = self::diverges(
            GenerationMode::Symfony,
            ['valid' => true, 'invalid' => true],
            'the property is a DateTimeImmutable and the serializer parses the item before any '
            . 'constraint runs; PHP accepts what the schema does not, exactly as for a scalar '
            . 'date-time — see "a loose format: date-time string"',
        );

        return [
            'type string' => $coerced,
            'type integer' => $coerced,
            'type union with null' => $coerced,
            'type union null first' => $coerced,
            'format date-time in a list' => $lenientDateTime,
        ];
    }

    /**
     * Skip the yii3 arm when, and only when, THIS document actually needs ext-intl.
     *
     * One thing needs it: the `#[ToDateTime]` resolver a SCALAR temporal property carries — its
     * constructor defaults name `IntlDateFormatter`, so it cannot even be built without the
     * extension. Which documents that covers used to be guessed from the schema (`"format":"date"`
     * anywhere in it), and the guess went stale the moment a temporal CONTAINER stopped emitting the
     * attribute: those cases were skipped while hydrating and validating perfectly well. So the
     * object is asked instead — the resolver's own failure is the only witness that does not rot.
     */
    private function skipYii3WithoutIntl(string $fqcn, string $probeJson, string $key): void
    {
        if (extension_loaded('intl')) {
            return;
        }

        $payload = json_decode($probeJson, true);
        try {
            (new Yii3Container())->hydrate($fqcn, is_array($payload) ? $payload : []);
        } catch (Error $error) {
            if (!str_contains($error->getMessage(), 'IntlDateFormatter')) {
                return;
            }

            self::assertTrue(
                GenerationMode::Yii3->isLast(),
                'yii3 may be skipped for a missing ext-intl, and a skip aborts the whole test — so it '
                . 'must be the LAST case in GenerationMode, or the modes after it stop being measured.',
            );
            self::markTestSkipped(sprintf('yii3 mode needs ext-intl for a temporal property ("%s").', $key));
        } catch (Throwable) {
            // Anything else is a real verdict about this payload; the measurement below reports it.
        }
    }

    /**
     * The shape almost every case shares: one required property `f` carrying the schema under test.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private static function probeSpec(array $propertySchema): array
    {
        return [
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
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function runtimeVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Runtime, $key), 'runtime');
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
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Laravel, $key), 'laravel');
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
     * laravel-data mode goes through the package's own entry point, and through a REQUEST: its default
     * `validation_strategy` is `OnlyRequests`, so `from($array)` would hydrate without validating
     * anything and every case would "pass". The request is also where the emitted interpreter finds the
     * raw body it needs for the `type: object` check.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function laravelDataVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::LaravelData, $key), 'laravel-data');
        LaravelDataContainer::boot();

        $accepts = static fn(string $json): bool => (bool)LaravelDataContainer::withRequest(
            $json,
            static function (LaravelRequest $request) use ($fqcn): bool {
                try {
                    // Hydration is part of the verdict, as in every other mode: a payload the rules
                    // accept but the object cannot hold is not "accepted" in any useful sense.
                    $fqcn::from($request);
                } catch (Throwable) {
                    return false;
                }

                return true;
            },
        );

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function symfonyVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Symfony, $key), 'symfony');

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

    private function namespaceFor(GenerationMode $mode, string $key): string
    {
        return 'Parity' . $mode->tag() . $this->namespaceSuffix($key);
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
