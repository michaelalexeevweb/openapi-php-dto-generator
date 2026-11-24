<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Error;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
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
    use RunsPayloadsThroughModes;

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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            diverges: self::declaredDivergences()[$key] ?? [],
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
            // A conditional branch carrying `properties`: the enclosing schema must not treat those
            // rules as the DTO's own and strip them — the class has never heard of the condition.
            'then with properties' => [
                [
                    'type' => 'object',
                    'properties' => ['kind' => ['type' => 'string'], 'code' => ['type' => 'string']],
                    'if' => ['type' => 'object'],
                    'then' => ['properties' => ['code' => ['type' => 'string', 'minLength' => 7]]],
                ],
                '{"f":{"kind":"long","code":"loooong"}}',
                '{"f":{"kind":"long","code":"short"}}',
            ],
            // The same permission with `null` written FIRST — one document, two spellings.
            'type union null first' => [['type' => ['null', 'string']], '{"f":null}', '{"f":5}'],
            'enum' => [['type' => 'string', 'enum' => ['a', 'b']], '{"f":"a"}', '{"f":"z"}'],
            'const' => [['type' => 'string', 'const' => 'a'], '{"f":"a"}', '{"f":"b"}'],
            'minLength' => [['type' => 'string', 'minLength' => 3], '{"f":"abc"}', '{"f":"ab"}'],
            'maxLength' => [['type' => 'string', 'maxLength' => 2], '{"f":"ab"}', '{"f":"abc"}'],
            // Equal bounds: yiisoft throws "Use $exactly instead." for `min` equal to `max`, so yii3
            // mode refused every payload, valid ones too, until it emitted `exactly:`.
            'minLength equals maxLength' => [['type' => 'string', 'minLength' => 3, 'maxLength' => 3], '{"f":"abc"}', '{"f":"ab"}'],
            // A negative bound is no bound, but yiisoft throws "Only positive or zero values are
            // allowed." while validating, so yii3 mode refused every payload until it left it alone.
            'negative minLength' => [['type' => 'string', 'minLength' => -1, 'maxLength' => 2], '{"f":"ab"}', '{"f":"abc"}'],
            'pattern' => [['type' => 'string', 'pattern' => '^a'], '{"f":"ab"}', '{"f":"ba"}'],
            // Document strings that are PHP syntax inside a single-quoted literal: a quote, `\'`, a
            // trailing backslash. Escaped twice, the enforced value is no longer the document's and the
            // valid payload fails; not at all, the file does not parse.
            'enum with quotes and backslashes' => [
                ['type' => 'string', 'enum' => ["a'b\\c\\'d?>e*/f\${x}g\\", 'plain']],
                (string)json_encode(['f' => "a'b\\c\\'d?>e*/f\${x}g\\"]),
                (string)json_encode(['f' => "a'b\\c\\\\'d?>e*/f\${x}g\\\\"]),
            ],
            'const with quotes and backslashes' => [
                ['type' => 'string', 'const' => "a'b\\c\\'d?>e*/f\${x}g\\"],
                (string)json_encode(['f' => "a'b\\c\\'d?>e*/f\${x}g\\"]),
                (string)json_encode(['f' => "a\\'b\\c\\'d?>e*/f\${x}g\\"]),
            ],
            'pattern with quotes and backslashes' => [
                ['type' => 'string', 'pattern' => "^q'\\\\?\\'?>*/\\{x\\}\\\\$"],
                (string)json_encode(['f' => "q'\\'>>/{x}\\"]),
                (string)json_encode(['f' => "q\\'\\'>>/{x}\\"]),
            ],
            // ECMA-262 `$` is the very end; PCRE's also matched before a final newline, so an
            // allow-list pattern let "abc\n" through in every mode.
            // An `allOf` class: Symfony mode read its constraints from the raw schema, where every
            // property sits inside a branch, and emitted no callback — the uuid was checked by nothing.
            'allOf object keeps its property formats' => [['allOf' => [['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'format' => 'uuid'], 'tags' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string']]]]]], '{"f":{"code":"7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b","tags":["a","b"]}}', '{"f":{"code":"nope","tags":["a","a"]}}'],
            'pattern end before a trailing newline' => [['type' => 'string', 'pattern' => '^[a-z]+$'], '{"f":"abc"}', '{"f":"abc\n"}'],
            // ECMA-262 `[^]` is any character; PCRE read it as an unclosed class and every value failed.
            'pattern any-character class' => [['type' => 'string', 'pattern' => '^[^]$'], '{"f":"x"}', '{"f":"xy"}'],
            // ECMA-262 `.` stops at every line terminator, `\r` included.
            'pattern dot stops at a carriage return' => [['type' => 'string', 'pattern' => '^a.b$'], '{"f":"axb"}', '{"f":"a\rb"}'],
            'format uuid before a trailing newline' => [['type' => 'string', 'format' => 'uuid'], '{"f":"7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b"}', '{"f":"7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b\n"}'],
            // RFC 9562's nil and max UUIDs have no version nibble; runtime accepted them, the interpreter did not.
            'format uuid nil' => [['type' => 'string', 'format' => 'uuid'], '{"f":"00000000-0000-0000-0000-000000000000"}', '{"f":"00000000-0000-0000-0000-00000000000"}'],
            'format uuid max' => [['type' => 'string', 'format' => 'uuid'], '{"f":"ffffffff-ffff-ffff-ffff-ffffffffffff"}', '{"f":"ffffffff-ffff-ffff-ffff-fffffffffffg"}'],
            'format time before a trailing newline' => [['type' => 'string', 'format' => 'time'], '{"f":"10:00:00Z"}', '{"f":"10:00:00Z\n"}'],
            // A numeric format alone: laravel and laravel-data called a string-format checker they had
            // not emitted, so a wrong-typed value was "Call to undefined method", a 500.
            'int64 given a string' => [['type' => 'integer', 'format' => 'int64'], '{"f":5}', '{"f":"abc"}'],
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
            'minItems equals maxItems' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 2],
                '{"f":["a","b"]}',
                '{"f":["a"]}',
            ],
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
            // The boolean subschema that CLOSES a tuple. The matrix carried its neighbour above and
            // not this one, which is exactly why the 2.15.27 fix could land in the runtime validator
            // alone and stay green: symfony and yii3 never even copied the keyword into their
            // emitted constraints, so nothing enforced it there.
            'items false closes a tuple' => [['type' => 'array', 'prefixItems' => [['type' => 'string']], 'items' => false], '{"f":["a"]}', '{"f":["a","b"]}'],
            // The rest of the BOOLEAN spellings. `items: false` above was the first of them and it
            // reached the matrix only in 2.15.36, after a fix had already landed in one validator and
            // stayed green everywhere else. Each of these is written so that a valid payload exists —
            // a position that refuses everything has none, and those are pinned in
            // `GeneratedConstraintsIntegrationTest` against runtime mode instead.
            'contains false' => [['contains' => false], '{"f":"s"}', '{"f":["a"]}'],
            'propertyNames false' => [['type' => 'object', 'propertyNames' => false], '{"f":{}}', '{"f":{"k":"v"}}'],
            'then false' => [['if' => ['type' => 'string'], 'then' => false], '{"f":5}', '{"f":"s"}'],
            'else false' => [['if' => ['const' => 'a'], 'else' => false], '{"f":"a"}', '{"f":"b"}'],
            'prefixItems false' => [['type' => 'array', 'prefixItems' => [false]], '{"f":[]}', '{"f":["a"]}'],
            // The valid payload carries `b` rather than being `{}`: an EMPTY object for a required
            // nested property is refused by laravel mode for a reason of its own, unrelated to
            // booleans, and a case has to fail for the reason it names.
            'dependentSchemas false' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
                    'dependentSchemas' => ['a' => false],
                ],
                '{"f":{"b":"y"}}',
                '{"f":{"a":"x"}}',
            ],
            'anyOf with a false branch' => [['anyOf' => [false, ['type' => 'string']]], '{"f":"s"}', '{"f":5}'],
            'oneOf with a false branch' => [['oneOf' => [false, ['type' => 'string']]], '{"f":"s"}', '{"f":5}'],
            // `enum: []` admits nothing (testAnUnsatisfiableSchemaRefusesEveryValue), except that the 3.0
            // `nullable` keyword beside it still admits null, as it does beside any enum.
            'empty enum beside nullable admits only null' => [['type' => 'string', 'nullable' => true, 'enum' => []], '{"f":null}', '{"f":"a"}'],
            // A union beside an `allOf` is a constraint of the class the allOf makes, not an interface of
            // the same name: emitted as both, the members `implements` a class and PHP refused to load
            // them (see CompositionClassesLoadTest). Loaded, the class keeps checking its allOf; what the
            // union's own branches declare is measured by the two cases after it.
            'allOf beside anyOf checks the allOf' => [
                [
                    'allOf' => [['properties' => ['alpha' => ['type' => 'string']]]],
                    'anyOf' => [['properties' => ['beta' => ['type' => 'integer']]]],
                ],
                '{"f":{"alpha":"a","beta":1}}',
                '{"f":{"alpha":5,"beta":1}}',
            ],
            'allOf beside an anyOf of objects' => [
                [
                    'allOf' => [['properties' => ['alpha' => ['type' => 'string']]]],
                    'anyOf' => [
                        ['properties' => ['beta' => ['type' => 'integer']], 'required' => ['beta']],
                        ['properties' => ['gamma' => ['type' => 'boolean']], 'required' => ['gamma']],
                    ],
                ],
                '{"f":{"alpha":"a","beta":1}}',
                '{"f":{"alpha":"a"}}',
            ],
            'allOf beside a oneOf of objects' => [
                [
                    'allOf' => [['properties' => ['alpha' => ['type' => 'string']]]],
                    'oneOf' => [
                        ['properties' => ['beta' => ['type' => 'integer']], 'required' => ['beta']],
                        ['properties' => ['gamma' => ['type' => 'boolean']], 'required' => ['gamma']],
                    ],
                ],
                '{"f":{"alpha":"a","gamma":true}}',
                '{"f":{"alpha":"a","beta":1,"gamma":true}}',
            ],
            // A composition that admits NOTHING, one level down in every keyword that takes a subschema.
            // Each of these is the false branch that symfony, laravel, laravel-data and yii3 dropped:
            // the position below opened up, and the invalid payload was accepted there while runtime
            // refused it.
            'items with an unsatisfiable allOf' => [['type' => 'array', 'items' => ['allOf' => [true, false]]], '{"f":[]}', '{"f":["a"]}'],
            'prefixItems with an unsatisfiable anyOf' => [['type' => 'array', 'prefixItems' => [['anyOf' => [false, false]]]], '{"f":[]}', '{"f":["a"]}'],
            'contains with an unsatisfiable oneOf' => [['contains' => ['oneOf' => [false, false]]], '{"f":"s"}', '{"f":["a"]}'],
            'properties with an unsatisfiable allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['allOf' => [true, false]], 'b' => ['type' => 'string']]],
                '{"f":{"b":"y"}}',
                '{"f":{"a":"x"}}',
            ],
            // No `properties` beside the map keywords: an object that declares some becomes a DTO, and a
            // closed DTO is a documented boundary of symfony and yii3 (README.support-matrix), not this one.
            'additionalProperties with an unsatisfiable anyOf' => [['type' => 'object', 'additionalProperties' => ['anyOf' => [false, false]]], '{"f":{}}', '{"f":{"c":1}}'],
            'patternProperties with an unsatisfiable allOf' => [['type' => 'object', 'patternProperties' => ['^x' => ['allOf' => [false]]]], '{"f":{"b":"y"}}', '{"f":{"xa":1}}'],
            'propertyNames with an unsatisfiable anyOf' => [['type' => 'object', 'propertyNames' => ['anyOf' => [false, false]]], '{"f":{}}', '{"f":{"k":"v"}}'],
            'dependentSchemas with an unsatisfiable allOf' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
                    'dependentSchemas' => ['a' => ['allOf' => [true, false]]],
                ],
                '{"f":{"b":"y"}}',
                '{"f":{"a":"x"}}',
            ],
            'not of an unsatisfiable anyOf' => [['type' => 'string', 'not' => ['anyOf' => [false, false]]], '{"f":"a"}', '{"f":5}'],
            'if with an unsatisfiable allOf' => [['if' => ['allOf' => [true, false]], 'then' => false, 'else' => ['type' => 'string']], '{"f":"a"}', '{"f":5}'],
            'then with an unsatisfiable anyOf' => [['if' => ['type' => 'string'], 'then' => ['anyOf' => [false, false]]], '{"f":5}', '{"f":"s"}'],
            'else with an unsatisfiable oneOf' => [['if' => ['const' => 'a'], 'else' => ['oneOf' => [false, false]]], '{"f":"a"}', '{"f":"b"}'],
            'unevaluatedItems with an unsatisfiable anyOf' => [
                ['type' => 'array', 'prefixItems' => [['type' => 'string']], 'unevaluatedItems' => ['anyOf' => [false, false]]],
                '{"f":["a"]}',
                '{"f":["a","b"]}',
            ],
            'unevaluatedProperties with an unsatisfiable allOf' => [['type' => 'object', 'unevaluatedProperties' => ['allOf' => [false]]], '{"f":{}}', '{"f":{"c":1}}'],
            'allOf with a true branch' => [['allOf' => [true, ['type' => 'string', 'minLength' => 3]]], '{"f":"abc"}', '{"f":"ab"}'],
            'anyOf' => [['anyOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'integer']]], '{"f":5}', '{"f":"ab"}'],
            'oneOf scalar' => [['oneOf' => [['type' => 'string', 'minLength' => 5], ['type' => 'string', 'pattern' => '^a']]], '{"f":"ab"}', '{"f":"abcdef"}'],
            'allOf of scalars' => [['allOf' => [['type' => 'string'], ['minLength' => 3]]], '{"f":"abc"}', '{"f":"ab"}'],
            'format email' => [['type' => 'string', 'format' => 'email'], '{"f":"a@b.co"}', '{"f":"nope"}'],
            'format uuid' => [['type' => 'string', 'format' => 'uuid'], '{"f":"7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b"}', '{"f":"nope"}'],
            'format uri-reference' => [['type' => 'string', 'format' => 'uri-reference'], '{"f":"/rel/path"}', '{"f":"has space "}'],
            'format date' => [['type' => 'string', 'format' => 'date'], '{"f":"2026-01-01"}', '{"f":"2026-13-45"}'],
            // The `Z` spelling of a UTC offset — the commonest one an API writes, and the one laravel
            // mode refused until 2.15.40. `date_format` does not merely parse: it formats the parsed
            // value back and compares the string, so `P` (`+00:00`) and `p` (`Z`) each round-trip one
            // spelling and refuse the other. Both are emitted now, and both are pinned: the matrix had
            // only ever sent an explicit numeric offset.
            // The invalid half is a string PHP cannot parse AT ALL, deliberately. Both other candidates
            // pin PHP's leniency rather than this keyword: `+99:99` is normalised into `+100:39`, and
            // `2026-13-45T99:99:99` overflows into a real date in 2027. A mode that casts before its
            // rules run — yii3 does — therefore accepts both, and the case would be measuring the
            // parser instead of the two offset spellings it exists to pin.
            'format date-time with Z' => [
                ['type' => 'string', 'format' => 'date-time'],
                '{"f":"2026-01-02T03:04:05Z"}',
                '{"f":"not a timestamp"}',
            ],
            // Milliseconds, as JavaScript's `toISOString()` writes them. Laravel's `date_format` with
            // `.u` formats back to six digits and compares strings, so laravel and laravel-data
            // refused this until 2.15.64.
            'format date-time with milliseconds' => [
                ['type' => 'string', 'format' => 'date-time'],
                '{"f":"2026-01-02T03:04:05.123Z"}',
                '{"f":"not a timestamp"}',
            ],
            'format date-time with a numeric offset' => [
                ['type' => 'string', 'format' => 'date-time'],
                '{"f":"2026-01-02T03:04:05+00:00"}',
                '{"f":"not a timestamp"}',
            ],
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
            // The URL filter knows authority URIs only: framework modes refused a URN.
            'format uri as a URN' => [['type' => 'string', 'format' => 'uri'], '{"f":"urn:isbn:0451450523"}', '{"f":"urn: spaced"}'],
            // The filter checks an email's domain as ASCII; an internationalized one is what idn-email is for.
            'format idn-email with an internationalized domain' => [['type' => 'string', 'format' => 'idn-email'], '{"f":"cat@пример.рф"}', '{"f":"cat@@пример.рф"}'],
            'multipleOf rejects a near multiple' => [['type' => 'number', 'multipleOf' => 1], '{"f":3}', '{"f":1.0000000001}'],
            'multipleOf rejects a long decimal' => [['type' => 'number', 'multipleOf' => 0.1], '{"f":0.3}', '{"f":0.30000000001}'],
            // A key of digits arrives as a PHP int and is still a name.
            'propertyNames with a numeric key' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'propertyNames' => ['maxLength' => 2]], '{"f":{"12":1}}', '{"f":{"12345":1}}'],
            'patternProperties with a numeric key' => [['type' => 'object', 'patternProperties' => ['^[0-9]+$' => ['minimum' => 5]]], '{"f":{"7":9}}', '{"f":{"7":1}}'],
            'additionalProperties false with a numeric key' => [['type' => 'object', 'patternProperties' => ['^x-' => []], 'additionalProperties' => false], '{"f":{"x-a":1}}', '{"f":{"42":1}}'],
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
            // RFC 5891 §4.2.3.2: a label must not BEGIN with a combining mark (U+302E here). ext-intl and
            // a current symfony/polyfill-intl-idn refuse it inside idn_to_ascii(); polyfill 1.15 — what
            // a prefer-lowest install resolves — does not, and accepted it. The valid side carries a
            // mark INSIDE a label (a decomposed `é`), which the rule leaves alone.
            'format idn-hostname refuses a label opening with a combining mark' => [
                ['type' => 'string', 'format' => 'idn-hostname'],
                '{"f":"e\u0301clair.\uc2e4\ub840.\ud14c\uc2a4\ud2b8"}',
                '{"f":"\u302e\uc2e4\ub840.\ud14c\uc2a4\ud2b8"}',
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
     * A schema that admits NO value refuses both payloads, in every mode.
     *
     * `false` is the subschema nothing satisfies, and an `allOf` holding one can never pass; an `anyOf`
     * or `oneOf` whose every branch is `false` has nothing to match. README.validation promises boolean
     * branches in every mode, and runtime kept the `allOf` one — symfony, laravel, laravel-data and yii3
     * dropped it with the `true` beside it, and accepted `"alpha"` against `allOf: [true, false]`. An
     * `enum: []` lists no member, so it is the same schema in another spelling.
     *
     * There is no valid payload to contrast with, so both slots of the verdict carry one the document
     * refuses, and the expectation is that neither is accepted.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('unsatisfiableSchemas')]
    public function testAnUnsatisfiableSchemaRefusesEveryValue(string $key, array $propertySchema): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);

        $this->assertEveryModeYields(
            expected: ['valid' => false, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'unsatisfiable-' . $key, '{"f":"alpha"}', '{"f":5}'),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function unsatisfiableSchemas(): iterable
    {
        $cases = [
            'allOf true and false' => ['allOf' => [true, false]],
            'allOf false alone' => ['allOf' => [false]],
            'allOf string and false' => ['allOf' => [['type' => 'string'], false]],
            'allOf string and not everything' => ['allOf' => [['type' => 'string'], ['not' => []]]],
            'anyOf all false' => ['anyOf' => [false, false]],
            'anyOf false and not everything' => ['anyOf' => [false, ['not' => []]]],
            'oneOf all false' => ['oneOf' => [false, false]],
            'oneOf false alone' => ['oneOf' => [false]],
            'empty enum' => ['enum' => []],
            'empty enum beside a type' => ['type' => 'string', 'enum' => []],
            // The neighbours that already held: the array spellings of `false` on their own.
            'not everything' => ['not' => []],
            'not true' => ['not' => true],
        ];
        foreach ($cases as $key => $schema) {
            yield $key => [$key, $schema];
        }
    }

    /**
     * OpenAPI 3.0 `nullable: true` inside a union branch: the branch's `type` alone kept null out, so
     * the null the document allows was refused as "does not match any oneOf branch".
     */
    public function testNullableUnionBranchAcceptsNull(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $spec = self::probeSpec([$keyword => [['type' => 'string', 'nullable' => true], ['type' => 'integer']]]);
            $spec['openapi'] = '3.0.3';

            $this->assertEveryModeYields(
                expected: ['valid' => true, 'invalid' => false],
                observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'nullable ' . $keyword, '{"f":null}', '{"f":[1]}'),
                diverges: [],
                context: 'nullable ' . $keyword,
            );
        }
    }

    /**
     * `nullable` on the property AND on a branch — a common way to write one thing in 3.0 — made
     * null match two `oneOf` branches, and `oneOf` refused it.
     */
    public function testNullableOnThePropertyAndOnABranchAdmitsNullOnce(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec(['nullable' => true, 'oneOf' => [['type' => 'string', 'nullable' => true], ['type' => 'integer']]]);
        $spec['openapi'] = '3.0.3';

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'nullable twice', '{"f":null}', '{"f":[1]}'),
            context: 'nullable twice',
        );
    }

    /**
     * A `uuid` is checked for its shape. Version and variant nibbles were pinned in the interpreter
     * and refused the Microsoft-variant GUIDs .NET writes, which yii3 had accepted until 2.15.124.
     */
    public function testUuidIsCheckedForItsShapeOnly(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']]);

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: 'uuid shape',
                validJson: '{"f":["3f2504e0-4f89-41d3-da0c-0305e82c3301","3f2504e0-4f89-01d3-9a0c-0305e82c3301"]}',
                invalidJson: '{"f":["3f2504e0-4f89-41d3-da0c-0305e82c330"]}',
            ),
            context: 'uuid shape',
        );
    }

    /**
     * An inline `allOf` branch closed with `additionalProperties: false` beside a `$ref` parent: Symfony
     * merged the branch into the class and refused every property the parent brought.
     */
    public function testClosedBranchBesideAParentKeepsTheInheritedProperties(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                'Probe' => ['allOf' => [
                    ['$ref' => '#/components/schemas/Base'],
                    ['type' => 'object', 'required' => ['f'], 'properties' => ['f' => ['type' => 'string']], 'additionalProperties' => false],
                ]],
            ]],
        ];

        foreach ([GenerationMode::Symfony, GenerationMode::Runtime] as $mode) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $spec, 'closed branch beside a parent', '{"id":"a","f":"b"}', '{"f":1}'),
                message: $mode->value,
            );
        }
    }

    /**
     * `additionalProperties` / `unevaluatedProperties` beside `allOf: [$ref Parent, inline]` — on the class
     * itself, or as a schema on the inline branch — speak about the keys the class does not declare.
     * Symfony merged the inline branch into the class and counted every inherited key as an extra one.
     * The parent's own rules still apply to its keys.
     *
     * An `unevaluatedProperties` INSIDE the branch is the exception JSON Schema 2020-12 makes: a branch
     * reads only what it evaluated itself, never a sibling branch's keys (the suite's "can't see inside
     * cousins"), so the parent's `id` is unevaluated there and has to be a string as well as an integer
     * — no payload carrying it passes.
     *
     * @param array<string, mixed> $closing
     * @param array<string, mixed> $branchClosing
     */
    #[DataProvider('closedCompositionSpellings')]
    public function testInheritedPropertiesAreNotExtraOnesBesideAParent(
        string $key,
        array $closing,
        array $branchClosing,
        bool $validAccepted = true,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
                'Probe' => [...$closing, 'allOf' => [
                    ['$ref' => '#/components/schemas/Base'],
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], ...$branchClosing],
                ]],
            ]],
        ];

        foreach ([GenerationMode::Symfony, GenerationMode::Runtime] as $mode) {
            $this->assertSame(
                expected: ['valid' => $validAccepted, 'invalid' => false],
                actual: $this->verdict($mode, $spec, $key, '{"id":5,"name":"n"}', '{"id":"x","name":"n"}'),
                message: $mode->value . ' ' . $key,
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3?: bool}>
     */
    public static function closedCompositionSpellings(): iterable
    {
        yield 'unevaluatedProperties false on the class' => ['closed class unevaluated', ['unevaluatedProperties' => false], []];
        yield 'additionalProperties false on the class' => ['closed class additional', ['additionalProperties' => false], []];
        yield 'additionalProperties false on the branch' => ['closed branch additional', [], ['additionalProperties' => false]];
        yield 'additionalProperties schema on the branch' => ['schema branch additional', [], ['additionalProperties' => ['type' => 'string']]];
        yield 'unevaluatedProperties schema on the branch' => ['schema branch unevaluated', [], ['unevaluatedProperties' => ['type' => 'string']], false];
    }

    /**
     * Symfony writes an `allOf` child as one flat class holding the parent's properties, and the
     * parent's rules for them — a format, a `const`, `uniqueItems` — were checked by nothing there.
     * Open or closed, the child refuses what the parent refuses.
     *
     * @param array<string, mixed> $closing
     */
    #[DataProvider('inheritedRuleCases')]
    public function testAnAllOfChildChecksTheRulesOfItsParentsProperties(string $key, array $closing, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                    'id' => ['type' => 'integer'],
                    'u' => ['type' => 'string', 'format' => 'uuid'],
                    'k' => ['const' => 'x'],
                    't' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                ]],
                'Probe' => [...$closing, 'allOf' => [
                    ['$ref' => '#/components/schemas/Base'],
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ]],
            ]],
        ];

        foreach ([GenerationMode::Symfony, GenerationMode::Runtime] as $mode) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict(
                    mode: $mode,
                    spec: $spec,
                    key: $key,
                    validJson: '{"id":1,"u":"3f2504e0-4f89-41d3-9a0c-0305e82c3301","k":"x","t":["a","b"],"name":"n"}',
                    invalidJson: $invalidJson,
                ),
                message: $mode->value . ' ' . $key,
            );
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function inheritedRuleCases(): iterable
    {
        foreach (['open' => [], 'closed' => ['additionalProperties' => false]] as $shape => $closing) {
            yield $shape . ' format' => ['inherited format ' . $shape, $closing, '{"id":1,"u":"nope"}'];
            yield $shape . ' const' => ['inherited const ' . $shape, $closing, '{"id":1,"k":"y"}'];
            yield $shape . ' uniqueItems' => ['inherited unique ' . $shape, $closing, '{"id":1,"t":["a","a"]}'];
        }
    }

    /**
     * A uuid in a property-level `anyOf` branch is checked for its shape, like one in a `oneOf` branch:
     * Symfony turned it into `Assert\Uuid`, which pins version and variant.
     */
    public function testUuidInAnAnyOfBranchIsCheckedForItsShapeOnly(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            $spec = self::probeSpec([$keyword => [['type' => 'string', 'format' => 'uuid'], ['type' => 'integer']]]);

            $this->assertEveryModeYields(
                expected: ['valid' => true, 'invalid' => false],
                observe: fn(GenerationMode $mode): array => $this->verdict(
                    mode: $mode,
                    spec: $spec,
                    key: 'uuid branch ' . $keyword,
                    validJson: '{"f":"12345678-1234-0234-c234-123456789012"}',
                    invalidJson: '{"f":"nope"}',
                ),
                context: 'uuid branch ' . $keyword,
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('schemaAssertionCases')]
    public function testSchemaAssertionsAcrossModes(
        GenerationMode $mode,
        string $key,
        array $schema,
        string $validJson,
        string $invalidJson,
    ): void {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, self::probeSpec($schema), 'schema-assertion-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function schemaAssertionCases(): iterable
    {
        $cases = [
            'nullable const null' => [['type' => ['string', 'null'], 'const' => null], '{"f":null}', '{"f":"red"}'],
            'nullable enum includes null' => [['type' => ['string', 'null'], 'enum' => ['red', null]], '{"f":null}', '{"f":"blue"}'],
            'nullable not const' => [['type' => ['string', 'null'], 'not' => ['const' => 'red']], '{"f":null}', '{"f":"red"}'],
            'nullable const' => [['type' => ['string', 'null'], 'const' => 'red'], '{"f":"red"}', '{"f":null}'],
            'nullable enum' => [['type' => ['string', 'null'], 'enum' => ['red', 'blue']], '{"f":"red"}', '{"f":null}'],
            'nullable not' => [['type' => ['string', 'null'], 'not' => ['type' => 'null']], '{"f":"red"}', '{"f":null}'],
            'nullable conditional' => [
                ['type' => ['string', 'null'], 'if' => ['type' => 'null'], 'then' => ['const' => 'red']],
                '{"f":"red"}', '{"f":null}',
            ],
            'nullable ignores string length' => [['type' => ['string', 'null'], 'minLength' => 3], '{"f":null}', '{"f":"r"}'],
            // The 3.0 `nullable` keyword admits null beside `const` — the documented deviation.
            'legacy nullable const' => [['type' => 'string', 'nullable' => true, 'const' => 'red'], '{"f":null}', '{"f":"blue"}'],
            'legacy nullable ignores length' => [['type' => 'string', 'nullable' => true, 'minLength' => 3], '{"f":null}', '{"f":"r"}'],
            'allOf conditional scope' => [
                ['type' => 'string', 'allOf' => [['if' => ['const' => 'red']], ['then' => ['const' => 'green']], ['minLength' => 3]]],
                '{"f":"red"}', '{"f":"r"}',
            ],
            'allOf parent bound' => [['type' => 'integer', 'minimum' => 1, 'allOf' => [['minimum' => 10]]], '{"f":11}', '{"f":5}'],
            'allOf preserves nullable scope' => [['nullable' => true, 'allOf' => [['type' => 'string'], ['minLength' => 1]]], '{"f":"red"}', '{"f":null}'],
        ];
        foreach ([false, true] as $reverse) {
            $bounds = [['type' => 'integer', 'minimum' => 10], ['minimum' => 1]];
            $enums = [['type' => 'string', 'enum' => ['red']], ['enum' => ['red', 'blue']]];
            $cases['allOf enums ' . (int)$reverse] = [['allOf' => $reverse ? array_reverse($enums) : $enums], '{"f":"red"}', '{"f":"blue"}'];
            $patterns = [['type' => 'string', 'pattern' => '^a'], ['pattern' => 'z$']];
            $cases['allOf bounds ' . (int)$reverse] = [['allOf' => $reverse ? array_reverse($bounds) : $bounds], '{"f":11}', '{"f":5}'];
            $cases['allOf patterns ' . (int)$reverse] = [['allOf' => $reverse ? array_reverse($patterns) : $patterns], '{"f":"az"}', '{"f":"ab"}'];
        }
        // A mixed outer list keeps hydration independent of the nested assertion under test.
        foreach ([
            'nulls count toward maximum' => [['type' => 'array', 'maxItems' => 1], '[[null]]', '[[null,null]]'],
            'nulls count toward minimum' => [['type' => 'array', 'minItems' => 2], '[[null,1]]', '[[null]]'],
            'nulls participate in uniqueness' => [['type' => 'array', 'uniqueItems' => true], '[[null,1]]', '[[null,null]]'],
            'null object properties count' => [['type' => 'object', 'minProperties' => 1], '[{"x":null}]', '[{}]'],
        ] as $key => [$inner, $valid, $invalid]) {
            $cases[$key] = [['type' => 'array', 'items' => [], 'contains' => $inner], '{"f":' . $valid . '}', '{"f":' . $invalid . '}'];
        }
        foreach ($cases as $key => [$schema, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $schema, $valid, $invalid];
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('numericAndEqualityCases')]
    public function testNumericAndEqualityAssertions(
        GenerationMode $mode,
        string $key,
        array $schema,
        string $validJson,
        string $invalidJson,
    ): void {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, self::probeSpec($schema), 'numeric-equality-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function numericAndEqualityCases(): iterable
    {
        $cases = [
            'large unique numbers' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[9007199254740993,9007199254740992.0]', '[9007199254740992,9007199254740992.0]'],
            'int64 unique boundary' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[9223372036854775807,9223372036854775808.0]', '[-9223372036854775808,-9223372036854775808.0]'],
            'nested inclusive minimum' => [['type' => 'array', 'items' => [], 'contains' => ['type' => 'integer', 'minimum' => 10, 'exclusiveMinimum' => 1]], '[10]', '[5]'],
            'nested inclusive maximum' => [['type' => 'array', 'items' => [], 'contains' => ['type' => 'integer', 'maximum' => 1, 'exclusiveMaximum' => 10]], '[1]', '[5]'],
            'precise fractional minimum' => [['type' => 'number', 'minimum' => 0.1234567890123456], '0.1234567890123457', '0.1234567890123455'],
            'number enum' => [['type' => 'number', 'enum' => [1]], '1.0', '2'],
            'number const' => [['const' => 1], '1.0', '"1"'],
            'boolean const' => [['const' => false], 'false', '0'],
            'array const' => [['type' => 'array', 'items' => ['type' => 'integer'], 'const' => [1, 2]], '[1,2]', '[2,1]'],
            'object const' => [['type' => 'object', 'const' => ['a' => 1, 'b' => 2]], '{"b":2,"a":1.0}', '{"a":1,"b":3}'],
            'mixed enum' => [['enum' => [1, false]], '1.0', '"1"'],
            // An enum with no `type` is backed by what its members ARE. All integers made a STRING-backed
            // PHP enum: runtime refused `1` ("expects enum ProbeF, got 1") and laravel died on
            // `ProbeF::from(): Argument #1 must be of type string, int given`.
            'untyped integer enum' => [['enum' => [1, 2, 3]], '2', '4'],
            // Not `"1"`: symfony and laravel-data hand an int-backed enum a numeric string as its int,
            // the typed `type: integer` enum included — a leniency of the int backing, not of this case.
            'untyped integer enum refuses a string' => [['enum' => [1, 2, 3]], '1', '"alpha"'],
            'untyped zero enum refuses false' => [['enum' => [0]], '0', 'false'],
            'untyped one enum refuses true' => [['enum' => [1]], '1', 'true'],
            'untyped integer enum in a list' => [['type' => 'array', 'items' => ['enum' => [1, 2]]], '[2]', '[3]'],
            // Members of different kinds share no backing type, so there is no PHP enum to make: the
            // members stay an inline constraint, compared as JSON values.
            'untyped integer and string enum' => [['enum' => [1, 'alpha']], '1', '"1"'],
            'untyped integer and string enum takes its string' => [['enum' => [1, 'alpha']], '"alpha"', '2'],
            'untyped mixed enum' => [['enum' => [1, 'alpha', true]], 'true', 'false'],
            'untyped null enum' => [['enum' => [null]], 'null', '0'],
            'untyped float enum' => [['enum' => [1.5, 2]], '1.5', '1'],
            // Neighbours that already held: one shared kind with a `type`, and strings without one.
            'untyped string enum' => [['enum' => ['alpha', 'beta']], '"beta"', '"gamma"'],
            'typed integer enum' => [['type' => 'integer', 'enum' => [1, 2]], '2', '3'],
            'structural enum' => [['enum' => [['a' => 1, 'b' => 2]]], '{"b":2,"a":1.0}', '{"a":1,"b":3}'],
            'unique numbers' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[1,"1",true,null]', '[1,1.0]'],
            'unique negative zero' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[0,false]', '[0,-0.0]'],
            'unique object keys' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[{"a":1},{"a":2}]', '[{"a":1,"b":2},{"b":2,"a":1.0}]'],
            'unique list order' => [['type' => 'array', 'items' => [], 'uniqueItems' => true], '[[1,2],[2,1]]', '[[1,2],[1.0,2]]'],
            'inclusive minimum survives' => [['type' => 'integer', 'minimum' => 10, 'exclusiveMinimum' => 1], '10', '5'],
            'inclusive maximum survives' => [['type' => 'integer', 'maximum' => 1, 'exclusiveMaximum' => 10], '1', '5'],
            'boolean exclusive minimum' => [['type' => 'integer', 'minimum' => 10, 'exclusiveMinimum' => true], '11', '10'],
            'boolean exclusive maximum' => [['type' => 'integer', 'maximum' => 10, 'exclusiveMaximum' => true], '9', '10'],
            'int32 upper bound' => [['type' => 'integer', 'format' => 'int32', 'minimum' => 0], '2147483647', '2147483648'],
            'int32 lower bound' => [['type' => 'integer', 'format' => 'int32', 'maximum' => 0], '-2147483648', '-2147483649'],
            'uint32 upper bound' => [['type' => 'integer', 'format' => 'uint32', 'minimum' => 0], '4294967295', '4294967296'],
            'uint32 lower bound' => [['type' => 'integer', 'format' => 'uint32', 'maximum' => 100], '0', '-1'],
            'tiny multiple' => [['type' => 'number', 'multipleOf' => 0.00000000001], '2e-11', '2.5e-11'],
            'tiny minimum' => [['type' => 'number', 'minimum' => 0.00000000002], '3e-11', '1e-11'],
            'tiny maximum' => [['type' => 'number', 'maximum' => 0.00000000002], '1e-11', '3e-11'],
            // Fractions divide in decimal, not by a float ratio within 1e-9.
            'tiny value multipleOf' => [['type' => 'number', 'multipleOf' => 0.1], '0.3', '1e-12'],
            'decimal multipleOf' => [['type' => 'number', 'multipleOf' => 0.05], '1.35', '1.351'],
            'nested decimal multipleOf' => [['type' => 'array', 'items' => [], 'contains' => ['type' => 'number', 'multipleOf' => 0.1]], '[0.7]', '[1e-12]'],
            // Two integers divide exactly; a float ratio cannot tell 2^53 + 1 from its neighbours.
            'integer multipleOf' => [['type' => 'integer', 'multipleOf' => 3], '9007199254740993', '9007199254740994'],
            'nested integer multipleOf' => [['type' => 'array', 'items' => [], 'contains' => ['type' => 'integer', 'multipleOf' => 3]], '[4611686018427387906]', '[4611686018427387905]'],
            // Hydrated dates have no public state: every date fingerprinted as `{}` and was a duplicate.
            'unique dates' => [['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date'], 'uniqueItems' => true], '["2020-01-01","2020-01-02"]', '["2020-01-01","2020-01-01"]'],
            'unique date-times' => [['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time'], 'uniqueItems' => true], '["2020-01-01T00:00:00+00:00","2021-01-01T00:00:00+00:00"]', '["2020-01-01T00:00:00+00:00","2020-01-01T00:00:00+00:00"]'],
            'unique objects with dates' => [['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['d' => ['type' => 'string', 'format' => 'date-time']]], 'uniqueItems' => true], '[{"d":"2020-01-01T00:00:00+00:00"},{"d":"2021-01-01T00:00:00+00:00"}]', '[{"d":"2020-01-01T00:00:00+00:00"},{"d":"2020-01-01T00:00:00+00:00"}]'],
        ];
        foreach ([9007199254740992, PHP_INT_MAX - 1] as $bound) {
            foreach (['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'] as $keyword) {
                $above = str_contains($keyword, 'Minimum') || $keyword === 'minimum';
                $exclusive = str_starts_with($keyword, 'exclusive');
                $valid = $exclusive ? $bound + ($above ? 1 : -1) : $bound;
                $invalid = $exclusive ? $bound : $bound + ($above ? -1 : 1);
                $inner = ['type' => 'integer', $keyword => $bound];
                $cases[$keyword . ' ' . $bound] = [
                    ['type' => 'array', 'items' => [], 'contains' => $inner],
                    '[' . $valid . ']', '[' . $invalid . ']',
                ];
            }
        }
        foreach ($cases as $key => [$schema, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $schema, '{"f":' . $valid . '}', '{"f":' . $invalid . '}'];
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('patternAndNullCases')]
    public function testPatternAndNullAssertions(
        GenerationMode $mode,
        string $key,
        array $schema,
        string $validJson,
        string $invalidJson,
    ): void {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, self::probeSpec($schema), 'pattern-null-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function patternAndNullCases(): iterable
    {
        $cases = [
            'empty pattern' => [['type' => 'string', 'pattern' => '^[A-Z]+$'], 'A', ''],
            'empty allowed' => [['type' => 'string', 'pattern' => '^A*$'], '', 'B'],
            'slash' => [['type' => 'string', 'pattern' => '^a/b$'], 'a/b', 'ab'],
            'escaped slash' => [['type' => 'string', 'pattern' => '^a\/b$'], 'a/b', 'ab'],
            'hash' => [['type' => 'string', 'pattern' => '^a#b$'], 'a#b', 'ab'],
            'escaped hash' => [['type' => 'string', 'pattern' => '^a\#b$'], 'a#b', 'ab'],
            'backslash hash' => [['type' => 'string', 'pattern' => '^a\\\#b$'], 'a\#b', 'a#b'],
            'delimiter class' => [['type' => 'string', 'pattern' => '^[#/]+$'], '#/', 'a'],
            'unicode' => [['type' => 'string', 'pattern' => '^.$'], 'ж', 'жж'],
            'emoji' => [['type' => 'string', 'pattern' => '^.$'], '🐈', '🐈🐈'],
            // ECMA-262 `\d` `\w` `\b` stay ASCII; PHP's `u` modifier alone made them Unicode.
            // ECMA-262 `\u` escapes: PCRE has none, `\x{…}` is its spelling.
            'unicode escape' => [['type' => 'string', 'pattern' => '^\u0041\u{1F408}$'], 'A🐈', 'B🐈'],
            'unicode escape class' => [['type' => 'string', 'pattern' => '^[\u0430-\u044F]+$'], 'кот', 'cat'],
            'ascii digit' => [['type' => 'string', 'pattern' => '^\d{2}$'], '42', '٤٢'],
            'ascii word' => [['type' => 'string', 'pattern' => '^\w+$'], 'cat_1', 'кот'],
            'ascii word class' => [['type' => 'string', 'pattern' => '^[\w-]+$'], 'a-b', 'а-б'],
            'ascii non-digit' => [['type' => 'string', 'pattern' => '^\D$'], '٣', '3'],
            'ascii boundary' => [['type' => 'string', 'pattern' => '\bcat'], 'жcat', 'acat'],
            'vertical tab' => [['type' => 'string', 'pattern' => '^\v$'], "\x0B", "\n"],
            'vertical tab rejects carriage return' => [['type' => 'string', 'pattern' => '^\v$'], "\x0B", "\r"],
            'vertical tab class' => [['type' => 'string', 'pattern' => '^[\v]$'], "\x0B", "\n"],
            'null type' => [['type' => 'null'], null, 'red'],
            'null type const' => [['type' => 'null', 'const' => null], null, 'red'],
            'null array type' => [['type' => ['null']], null, 'red'],
            'null items' => [['type' => 'array', 'items' => ['type' => 'null']], [null], ['red']],
            'null map' => [['type' => 'object', 'additionalProperties' => ['type' => 'null']], ['a' => null], ['a' => 'red']],
        ];
        foreach (['escaped slash', 'hash', 'escaped hash', 'backslash hash', 'delimiter class', 'unicode', 'emoji', 'ascii digit', 'ascii word class', 'null type', 'null array type'] as $key) {
            [$inner, $valid, $invalid] = $cases[$key];
            $cases['contains ' . $key] = [['type' => 'array', 'items' => [], 'contains' => $inner], [$valid], [$invalid]];
        }
        $cases['unicode property pattern'] = [['type' => 'object', 'patternProperties' => ['^.$' => ['type' => 'integer']], 'additionalProperties' => false], ['ж' => 1], ['жж' => 1]];
        $cases['unicode escape property pattern'] = [['type' => 'object', 'patternProperties' => ['^\u0061$' => ['type' => 'integer']], 'additionalProperties' => false], ['a' => 1], ['b' => 1]];
        $cases['ascii property pattern'] = [['type' => 'object', 'patternProperties' => ['^\w+$' => ['type' => 'integer']], 'additionalProperties' => false], ['cat' => 1], ['кот' => 1]];
        $cases['escaped property pattern'] = [['type' => 'object', 'patternProperties' => ['^a\#b$' => ['type' => 'integer']], 'additionalProperties' => false], ['a#b' => 1], ['ab' => 1]];
        $cases['regex format delimiter'] = [['type' => 'string', 'format' => 'regex'], '^a\#b$', '('];
        foreach (['email' => 'cat@example.com', 'idn-email' => 'cat@example.com', 'uuid' => '123e4567-e89b-42d3-a456-426614174000', 'hostname' => 'example.com', 'ipv4' => '127.0.0.1', 'ipv6' => '::1'] as $format => $valid) {
            $cases['empty ' . $format] = [['type' => 'string', 'format' => $format], $valid, ''];
            $cases['nullable ' . $format] = [['type' => ['string', 'null'], 'format' => $format], null, ''];
        }
        $cases['nullable pattern'] = [['type' => ['string', 'null'], 'pattern' => '^A+$'], null, ''];
        foreach ($cases as $key => [$schema, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $schema, json_encode(['f' => $valid], JSON_THROW_ON_ERROR), json_encode(['f' => $invalid], JSON_THROW_ON_ERROR)];
            }
        }
    }

    /**
     * A keyword that speaks about ONE JSON type says nothing about a value of another: `pattern`
     * ignores `true`, `minimum` ignores `"x"`, `minItems` ignores a string. The official
     * JSON-Schema-Test-Suite found the framework modes applying their native rule to whatever the
     * value was when `type` did not pin it — `Assert\Regex`, Laravel's `regex:` and `multiple_of:`,
     * yii3's `Length` and `Count` — and refusing values the document allows. Each case pairs a value
     * of another type, which must pass, with a value of the keyword's own type that breaks it, which
     * must still be refused: the keyword moved to the interpreter, it did not disappear.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('typeSpecificKeywordCases')]
    public function testTypeSpecificKeywordIgnoresOtherTypes(string $key, array $propertySchema, string $validJson, string $invalidJson): void
    {
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, self::probeSpec($propertySchema), 'type-specific-' . $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function typeSpecificKeywordCases(): iterable
    {
        // One or two values of another type per keyword, the suite's own among them: the generated
        // classes stay loaded for the rest of the run, so the matrix is sampled rather than crossed.
        // keyword schema, a value of another type it must pass, a value of its own type it refuses
        $cases = [
            'pattern ignores boolean' => [['pattern' => '^a*$'], true, 'abc'],
            'pattern ignores null' => [['pattern' => '^a*$'], null, 'abc'],
            'pattern ignores object' => [['pattern' => '^a*$'], ['b' => 1], 'abc'],
            'minLength ignores integer' => [['minLength' => 2], 1, 'a'],
            'maxLength ignores list' => [['maxLength' => 2], ['b', 'c', 'd'], 'abc'],
            'minimum ignores string' => [['minimum' => 5], 'x', 3],
            'maximum ignores boolean' => [['maximum' => 5], true, 7],
            'exclusiveMinimum ignores object' => [['exclusiveMinimum' => 5], ['b' => 1], 5],
            'exclusiveMaximum ignores string' => [['exclusiveMaximum' => 5], 'x', 5],
            'multipleOf ignores null' => [['multipleOf' => 2], null, 3],
            'multipleOf ignores string' => [['multipleOf' => 2], 'x', 3],
            'minItems ignores string' => [['minItems' => 4], 'x', [1]],
            'minItems ignores object' => [['minItems' => 4], ['b' => 1], [1]],
            'maxItems ignores integer' => [['maxItems' => 1], 12, [1, 2]],
            // Equal member values, so `uniqueItems` would have something to refuse were it applied.
            'uniqueItems ignores object' => [['uniqueItems' => true], ['b' => 1, 'c' => 1], [1, 1]],
            'contains ignores string' => [['contains' => ['const' => 1]], 'x', [2]],
            'minProperties ignores string' => [['minProperties' => 4], 'x', ['a' => 1]],
            'maxProperties ignores boolean' => [['maxProperties' => 1], true, ['a' => 1, 'b' => 2]],
            'required ignores integer' => [['required' => ['a']], 12, ['b' => 1]],
            'dependentRequired ignores string' => [['dependentRequired' => ['b' => ['a']]], 'x', ['b' => 1]],
            // A `type` LIST pins nothing either: `regex:` on `type: [string, integer]` refused the integer.
            'pattern beside a string-or-integer type' => [['type' => ['string', 'integer'], 'pattern' => '^a*$'], 5, 'abc'],
            'minimum beside a string-or-integer type' => [['type' => ['string', 'integer'], 'minimum' => 5], 'x', 3],
            'minItems beside a string-or-array type' => [['type' => ['string', 'array'], 'minItems' => 2], 'x', [1]],
            // Where `type` DOES pin the value the native rules stay, and so does the verdict.
            'pattern beside type string' => [['type' => 'string', 'pattern' => '^a*$'], 'aa', 'abc'],
            'pattern beside a nullable string' => [['type' => ['string', 'null'], 'pattern' => '^a*$'], null, 'abc'],
            'minLength beside type string' => [['type' => 'string', 'minLength' => 2], 'ab', 'a'],
            'minimum beside type integer' => [['type' => 'integer', 'minimum' => 5], 5, 3],
            'minItems beside type array' => [['type' => 'array', 'minItems' => 2], [1, 2], [1]],
            'maxProperties beside type object' => [['type' => 'object', 'maxProperties' => 1], ['a' => 1], ['a' => 1, 'b' => 2]],
            // And without `type`, a value of the keyword's own type that satisfies it still passes.
            'pattern alone on a matching string' => [['pattern' => '^a*$'], 'aa', 'abc'],
            'minItems alone on a long list' => [['minItems' => 2], [1, 2], [1]],
        ];
        foreach ($cases as $key => [$schema, $valid, $invalid]) {
            yield $key => [$key, $schema, json_encode(['f' => $valid], JSON_THROW_ON_ERROR), json_encode(['f' => $invalid], JSON_THROW_ON_ERROR)];
        }
    }

    /**
     * A schema with no `type` that describes no object KEY admits any value, and the official
     * JSON-Schema-Test-Suite found it generated as an object DTO: `allOf: [{maximum: 30}, {minimum:
     * 20}]` refused 25 with "Cannot deserialize nested DTO … from non-array value", a `$ref` to
     * `{maximum: 5}` refused every number, an untyped tuple refused every list. Below such a value
     * nothing is materialized, and three more holes sat there: a `$ref` under it was dropped unchecked
     * in every mode, its `properties` were checked by runtime alone, and Laravel's `field.*` rules hit
     * the members of an object an untyped `items` says nothing about. The object keywords ignore a
     * JSON LIST, which was counted as an object; a count written `2.0` is the integer 2. Each case
     * pairs a value that must pass with one that must be refused, in every mode.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $components
     */
    #[DataProvider('untypedSchemaCases')]
    public function testUntypedSchemasHoldAnyValueAndStillConstrain(
        string $key,
        array $propertySchema,
        array $components,
        string $validJson,
        string $invalidJson,
    ): void {
        $spec = self::probeSpec($propertySchema);
        $spec['components']['schemas'] = [...$spec['components']['schemas'], ...$components];

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'untyped-' . $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string, 4: string}>
     */
    public static function untypedSchemaCases(): iterable
    {
        $small = ['Small' => ['maximum' => 5]];
        $pair = ['Pair' => ['prefixItems' => [['type' => 'integer'], ['$ref' => '#/components/schemas/Pair/prefixItems/0']]]];
        // schema, extra components, a value it must pass, a value it must refuse
        $cases = [
            'allOf of bounds' => [['allOf' => [['maximum' => 30], ['minimum' => 20]]], [], 25, 35],
            'allOf of bounds admits a string' => [['allOf' => [['maximum' => 30], ['minimum' => 20]]], [], 'x', 15],
            'allOf with a conditional branch' => [['allOf' => [['if' => ['exclusiveMaximum' => 0], 'then' => ['minimum' => -10]]]], [], -5, -100],
            'allOf with a tuple' => [['allOf' => [['prefixItems' => [['type' => 'integer']]]]], [], [1, 'x'], ['x']],
            'nested allOf of null' => [['allOf' => [['allOf' => [['type' => 'null']]]]], [], null, 123],
            'allOf of an empty branch and a number' => [['allOf' => [[], ['type' => 'number']]], [], 1, 'foo'],
            'ref to an untyped component' => [['$ref' => '#/components/schemas/Small'], $small, 3, 7],
            'ref to an untyped component admits a string' => [['$ref' => '#/components/schemas/Small'], $small, 'x', 9],
            'ref to an untyped tuple component' => [['$ref' => '#/components/schemas/Pair'], $pair, [1, 2], [1, 'x']],
            'properties without type' => [['properties' => ['alpha' => ['type' => 'integer']]], [], ['alpha' => 1], ['alpha' => []]],
            'properties without type admit a number' => [['properties' => ['alpha' => ['type' => 'integer']]], [], 12, ['alpha' => 'x']],
            'ref under properties without type' => [
                ['properties' => ['alpha' => ['$ref' => '#/components/schemas/Whole']]],
                ['Whole' => ['type' => 'integer']],
                ['alpha' => 1],
                ['alpha' => 'x'],
            ],
            'escaped pointer under properties without type' => [
                ['properties' => ['alpha' => ['$ref' => '#/components/schemas/alpha~1beta'], 'gamma' => ['$ref' => '#/components/schemas/gamma~0delta']]],
                ['alpha/beta' => ['type' => 'integer'], 'gamma~delta' => ['type' => 'integer']],
                ['alpha' => 1, 'gamma' => 2],
                ['gamma' => 'x'],
            ],
            'additionalProperties beside properties without type' => [
                ['properties' => ['colors' => ['type' => 'array']], 'additionalProperties' => ['type' => 'integer']],
                [],
                ['colors' => ['red'], 'beta' => 2],
                ['beta' => 'x'],
            ],
            'items without type ignore an object' => [['items' => ['type' => 'integer']], [], ['0' => 'x', 'length' => 1], [1, 'x']],
            'enum listing a ref-shaped object' => [
                ['$defs' => ['word' => ['type' => 'string']], 'enum' => [['$ref' => '#/$defs/word']]],
                [],
                ['$ref' => '#/$defs/word'],
                'x',
            ],
            'maxProperties ignores a list' => [['maxProperties' => 2], [], [1, 2, 3], ['a' => 1, 'b' => 2, 'c' => 3]],
            'required ignores a list' => [['required' => ['alpha']], [], [1, 2], ['beta' => 1]],
            'dependentRequired ignores a list' => [['dependentRequired' => ['alpha' => ['beta']]], [], ['alpha'], ['alpha' => 1]],
            // The other side of the list rule: where `type` pins `object`, a branch keyword still counts.
            'minProperties in a branch of an object' => [['type' => 'object', 'allOf' => [['minProperties' => 1]]], [], ['a' => 1], new stdClass()],
            'maxContains written as a decimal' => [['contains' => ['const' => 1], 'maxContains' => 2.0], [], [1, 1], [1, 1, 1]],
            'minContains written as a decimal' => [['contains' => ['const' => 1], 'minContains' => 2.0], [], [1, 1], [1]],
            'maxLength written as a decimal' => [['type' => 'string', 'maxLength' => 2.0], [], 'ab', 'abc'],
            'minItems written as a decimal' => [['type' => 'array', 'minItems' => 2.0], [], [1, 2], [1]],
            'maxProperties written as a decimal' => [['type' => 'object', 'maxProperties' => 1.0], [], ['a' => 1], ['a' => 1, 'b' => 2]],
        ];
        foreach ($cases as $key => [$schema, $components, $valid, $invalid]) {
            yield $key => [$key, $schema, $components, json_encode(['f' => $valid], JSON_THROW_ON_ERROR), json_encode(['f' => $invalid], JSON_THROW_ON_ERROR)];
        }
    }

    /**
     * Where the convention that keeps a class stops. A component describing KEYS is an object DTO
     * with `type: object` or without it — ordinary OpenAPI documents leave the type out, and the
     * class is what an application types its code with — and so is the component that writes no
     * keyword at all. Only an untyped component that describes no key becomes an alias of `mixed`,
     * with no class file of its own.
     */
    public function testUntypedComponentKeepsItsClassOnlyWhereItDescribesAnObject(): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Pet']);
        $spec['components']['schemas']['Probe']['properties'] += [
            'anything' => ['$ref' => '#/components/schemas/Anything'],
            'small' => ['$ref' => '#/components/schemas/Small'],
        ];
        $spec['components']['schemas']['Probe']['required'] = ['f', 'anything', 'small'];
        $spec['components']['schemas'] += [
            'Pet' => ['properties' => ['name' => ['type' => 'string']]],
            'Anything' => ['description' => 'any value at all'],
            'Small' => ['maximum' => 5],
        ];
        $namespace = $this->namespaceFor(GenerationMode::Runtime, 'untyped-component-classes');
        $fqcn = $this->generate(spec: $spec, namespace: $namespace, mode: 'runtime');

        $listed = scandir($this->outputDirectory . '/' . $namespace);
        $this->assertIsArray($listed);
        $this->assertContains('Pet.php', $listed);
        $this->assertContains('Anything.php', $listed);
        $this->assertNotContains('Small.php', $listed);

        $types = [];
        foreach (['f', 'anything', 'small'] as $property) {
            $types[$property] = (string)(new ReflectionProperty($fqcn, $property))->getType();
        }
        $this->assertSame(
            expected: ['f' => $namespace . '\Pet', 'anything' => $namespace . '\Anything', 'small' => 'mixed'],
            actual: $types,
        );
    }

    /**
     * `format` is asserted on a STRING in this generator — a documented deviation from 2020-12, where it
     * is an annotation by default — and only on a string. `{"format": "email"}` said nothing about `12`,
     * yet Laravel's `email` rule and yii3's `Email` refused it, and `date_format:` refused even null.
     * The numeric formats are the mirror image: `int32` bounds a number and ignores a string, where
     * Symfony's `Range` refused `"x"`.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('formatOnOtherTypeCases')]
    public function testFormatIgnoresOtherTypes(string $key, array $propertySchema, string $validJson, string $invalidJson): void
    {
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, self::probeSpec($propertySchema), 'format-other-' . $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function formatOnOtherTypeCases(): iterable
    {
        // format, a value of another type it must pass, a string (or a number) it refuses
        $cases = [
            'email ignores integer' => ['email', 12, 'nope'],
            'email ignores null' => ['email', null, 'nope'],
            'email ignores object' => ['email', ['a' => 1], 'nope'],
            'idn-email ignores integer' => ['idn-email', 12, 'nope'],
            'ipv4 ignores boolean' => ['ipv4', true, '1.2.3'],
            'ipv6 ignores list' => ['ipv6', [1], 'nope'],
            'date ignores integer' => ['date', 12, '2026-13-45'],
            'date ignores null' => ['date', null, '2026-13-45'],
            'date-time ignores float' => ['date-time', 1.5, 'nope'],
            'uuid ignores object' => ['uuid', ['a' => 1], 'nope'],
            // Formats no framework rule enforced: the neighbours that already agreed.
            'hostname ignores integer' => ['hostname', 12, '-nope-'],
            'uri ignores null' => ['uri', null, 'nope'],
            'int32 ignores string' => ['int32', 'x', 3000000000],
            'uint32 ignores list' => ['uint32', [1], -1],
        ];
        foreach ($cases as $key => [$format, $valid, $invalid]) {
            yield $key => [$key, ['format' => $format], json_encode(['f' => $valid], JSON_THROW_ON_ERROR), json_encode(['f' => $invalid], JSON_THROW_ON_ERROR)];
        }
        // `type: string` pins the value: there the format stays a native rule, refusing as before.
        yield 'email beside type string' => ['email-typed', ['type' => 'string', 'format' => 'email'], '{"f":"cat@example.com"}', '{"f":"nope"}'];
        yield 'date beside type string' => ['date-typed', ['type' => 'string', 'format' => 'date'], '{"f":"2026-01-02"}', '{"f":"2026-13-45"}'];
    }

    /**
     * ECMA-262 names a Unicode property in full under the `u` flag — `\p{Letter}`, `\p{Script=Greek}`,
     * `\p{gc=Lu}` — where PCRE knows the short General_Category names and the bare script names. A
     * long name did not compile: `pattern` refused every value as an invalid regex in all five modes,
     * and a `patternProperties` key raised `preg_match(): Compilation failed` on every request in the
     * framework modes.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('unicodePropertyNameCases')]
    public function testUnicodePropertyNamesCompile(string $key, array $propertySchema, string $validJson, string $invalidJson): void
    {
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array|string => $this->verdictRaisingWarnings(
                mode: $mode,
                spec: self::probeSpec($propertySchema),
                key: 'unicode-property-' . $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function unicodePropertyNameCases(): iterable
    {
        // The short spellings PCRE always knew are the neighbours: they must keep working.
        foreach (['Letter', 'L', 'Lowercase_Letter', 'General_Category=Letter', 'gc=Ll', 'Script=Greek', 'sc=Greek', 'Script_Extensions=Greek'] as $name) {
            yield 'pattern \p{' . $name . '}' => [
                'pattern-' . $name,
                ['type' => 'string', 'pattern' => '^\p{' . $name . '}+$'],
                '{"f":"π"}',
                '{"f":"1"}',
            ];
        }
        yield 'pattern \P{Letter}' => ['negated', ['type' => 'string', 'pattern' => '^\P{Letter}+$'], '{"f":"1"}', '{"f":"π"}'];
        yield 'pattern \p{Decimal_Number}' => ['decimal', ['type' => 'string', 'pattern' => '^\p{Decimal_Number}$'], '{"f":"٣"}', '{"f":"a"}'];
        yield 'pattern \p{Cased_Letter}' => ['cased', ['type' => 'string', 'pattern' => '^\p{Cased_Letter}$'], '{"f":"π"}', '{"f":"1"}'];
        yield 'pattern \p{Letter} in a class' => ['class', ['type' => 'string', 'pattern' => '^[\p{Letter}\d]+$'], '{"f":"π1"}', '{"f":"-"}'];
        yield 'pattern \p{Letter} without type' => ['untyped', ['pattern' => '\p{Letter}cole'], '{"f":"école"}', '{"f":"1cole"}'];
        yield 'pattern \p{Letter} on list items' => ['items', ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^\p{Letter}+$']], '{"f":["π"]}', '{"f":["1"]}'];
        yield 'patternProperties \p{Letter}' => [
            'pattern-properties',
            ['type' => 'object', 'patternProperties' => ['^\p{Letter}+$' => ['type' => 'number']]],
            '{"f":{"π":1}}',
            '{"f":{"π":"x"}}',
        ];
        yield 'patternProperties \p{Letter} without type' => [
            'pattern-properties-untyped',
            ['patternProperties' => ['^\p{Letter}+$' => ['type' => 'number']]],
            '{"f":{"π":1}}',
            '{"f":{"π":"x"}}',
        ];
        yield 'patternProperties \p{Letter} closing the object' => [
            'pattern-properties-closed',
            ['type' => 'object', 'patternProperties' => ['^\p{Letter}+$' => ['type' => 'number']], 'additionalProperties' => false],
            '{"f":{"π":1}}',
            '{"f":{"1":1}}',
        ];
        yield 'propertyNames \p{Letter}' => ['property-names', ['type' => 'object', 'propertyNames' => ['pattern' => '^\p{Letter}+$']], '{"f":{"π":1}}', '{"f":{"1":1}}'];
        // A name no dialect knows stays the uncompilable-pattern case it always was — refused as an
        // invalid regex for `pattern`, matching no key for `patternProperties` — and never a warning.
        yield 'pattern \p{} of an unknown property' => ['unknown', ['pattern' => '^\p{Nonexistent}*$'], '{"f":5}', '{"f":"a"}'];
        yield 'patternProperties \p{} of an unknown property' => [
            'unknown-key',
            ['type' => 'object', 'patternProperties' => ['^\p{Nonexistent}+$' => ['minimum' => 5]], 'maxProperties' => 1],
            '{"f":{"π":1}}',
            '{"f":{"π":1,"ж":2}}',
        ];
    }

    /**
     * {@see verdict()} with every PHP warning raised as an `ErrorException`, the way an application's
     * error handler raises it — and regardless of `@`, as some do. A `preg_match()` warning on a
     * document pattern is then the 500 it is in production, and no longer passes for a refusal.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}|string
     */
    private function verdictRaisingWarnings(GenerationMode $mode, array $spec, string $key, string $validJson, string $invalidJson): array|string
    {
        $warnings = [];
        set_error_handler(
            callback: static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            error_levels: E_WARNING,
        );
        try {
            $verdict = $this->verdict($mode, $spec, $key, $validJson, $invalidJson);
        } finally {
            restore_error_handler();
        }

        return $warnings === [] ? $verdict : 'warning: ' . $warnings[0];
    }

    public function testRuntimeRootPatternPreservesEscapedDelimiter(): void
    {
        $spec = self::probeSpec(['type' => 'string']);
        $spec['components']['schemas']['Probe']['patternProperties'] = ['^a\#b$' => ['type' => 'integer']];
        $spec['components']['schemas']['Probe']['additionalProperties'] = false;
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->runtimeVerdict($spec, 'root-escaped-pattern', '{"f":"ok","a#b":1}', '{"f":"ok","other":1}'),
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('emptySchemaCases')]
    public function testEmptySchemasKeepTheirMeaning(
        GenerationMode $mode,
        string $key,
        array $schema,
        mixed $accepted,
        mixed $rejected,
        bool $hasAcceptedValue,
    ): void {
        $this->assertSame(
            expected: ['valid' => $hasAcceptedValue, 'invalid' => false],
            actual: $this->verdict(
                mode: $mode,
                spec: self::probeSpec($schema),
                key: 'empty-schema-' . $key,
                validJson: json_encode(['f' => $accepted], JSON_THROW_ON_ERROR),
                invalidJson: json_encode(['f' => $rejected], JSON_THROW_ON_ERROR),
            ),
        );
    }

    public static function emptySchemaCases(): iterable
    {
        $cases = [
            'not empty' => [['type' => 'string', 'not' => []], 'red', 'blue', false],
            'not annotated' => [['type' => 'string', 'not' => ['title' => 'Anything']], 'red', 'blue', false],
            'not false control' => [['type' => 'string', 'minLength' => 2, 'not' => false], 'red', 'x', true],
            'if empty' => [['type' => 'string', 'if' => [], 'then' => ['minLength' => 3]], 'red', 'x', true],
            'if annotated' => [['type' => 'string', 'if' => ['description' => 'Anything'], 'then' => ['minLength' => 3]], 'red', 'x', true],
            'if empty ignores else' => [['type' => 'string', 'if' => [], 'then' => ['minLength' => 3], 'else' => false], 'red', 'x', true],
            'then empty' => [['type' => 'string', 'if' => ['const' => 'red'], 'then' => [], 'else' => false], 'red', 'blue', true],
            'else empty' => [['type' => 'string', 'if' => ['const' => 'red'], 'then' => false, 'else' => []], 'blue', 'red', true],
            'items empty evaluated' => [['type' => 'array', 'items' => [], 'unevaluatedItems' => false, 'maxItems' => 1], [1], [1, 2], true],
            'items annotated evaluated' => [['type' => 'array', 'items' => ['title' => 'Anything'], 'unevaluatedItems' => false, 'maxItems' => 1], [1], [1, 2], true],
            'contains empty minimum' => [['type' => 'array', 'contains' => []], [1], [], true],
            'contains empty maximum' => [['type' => 'array', 'contains' => [], 'maxContains' => 1], [1], [1, 2], true],
            'contains empty evaluates' => [['type' => 'array', 'contains' => [], 'unevaluatedItems' => false], [1], [], true],
            'prefix empty' => [['type' => 'array', 'prefixItems' => [[]], 'items' => false], [1], [1, 2], true],
            'oneOf empty' => [['oneOf' => [[], ['type' => 'string', 'minLength' => 3]]], 'x', 'red', true],
            'anyOf empty' => [['type' => 'string', 'maxLength' => 4, 'anyOf' => [[], ['minLength' => 3]]], 'x', 'lengthy', true],
            'allOf empty control' => [['type' => 'string', 'allOf' => [[], ['minLength' => 3]]], 'red', 'x', true],
            'properties empty evaluated' => [['type' => 'array', 'contains' => ['type' => 'object', 'properties' => ['a' => []], 'additionalProperties' => false]], [['a' => 1]], [['b' => 1]], true],
            'properties true evaluated' => [['type' => 'array', 'contains' => ['type' => 'object', 'properties' => ['a' => true], 'additionalProperties' => false]], [['a' => 1]], [['b' => 1]], true],
            'patternProperties empty evaluated' => [['type' => 'object', 'patternProperties' => ['^a$' => []], 'additionalProperties' => false], ['a' => 1], ['b' => 1], true],
            'patternProperties true evaluated' => [['type' => 'object', 'patternProperties' => ['^a$' => true], 'additionalProperties' => false], ['a' => 1], ['b' => 1], true],
            'content object' => [['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'object']], '{}', '[]', true],
            'content array' => [['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'array']], '[]', '{}', true],
            'content numeric keys' => [['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'array']]]], '{"a":[1]}', '{"a":{"0":1}}', true],
            'content object props' => [['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]]], '{"a":1}', '{"a":"x"}', true],
            // An applicator's own `unevaluated*` is an annotation the location above must read.
            'nested unevaluatedItems' => [['type' => 'array', 'allOf' => [['unevaluatedItems' => ['type' => 'string']]], 'unevaluatedItems' => false], ['x'], [1], true],
            'branch items evaluated' => [['type' => 'array', 'allOf' => [['items' => ['type' => 'integer']]], 'unevaluatedItems' => false], [1, 2], ['x'], true],
            'oneOf branch prefix' => [['type' => 'array', 'oneOf' => [['prefixItems' => [['type' => 'integer']]], ['prefixItems' => [['type' => 'string']]]], 'unevaluatedItems' => false], [1], [1, 2], true],
            'nested unevaluatedProperties' => [['type' => 'array', 'contains' => ['type' => 'object', 'allOf' => [['unevaluatedProperties' => ['type' => 'integer']]], 'unevaluatedProperties' => false]], [['a' => 1]], [['a' => 'x']], true],
            'branch properties evaluated' => [['type' => 'array', 'contains' => ['type' => 'object', 'allOf' => [['properties' => ['a' => ['type' => 'integer']]]], 'unevaluatedProperties' => false]], [['a' => 1]], [['a' => 1, 'b' => 2]], true],
            'if arm properties' => [['type' => 'array', 'contains' => ['type' => 'object', 'if' => ['required' => ['a']], 'then' => ['properties' => ['b' => ['type' => 'integer']]], 'properties' => ['a' => ['type' => 'integer']], 'unevaluatedProperties' => false]], [['a' => 1, 'b' => 2]], [['b' => 2]], true],
            'additional empty evaluated' => [['type' => 'object', 'additionalProperties' => [], 'unevaluatedProperties' => false, 'maxProperties' => 1], ['a' => 1], ['a' => 1, 'b' => 2], true],
        ];
        foreach ($cases as $key => [$schema, $valid, $invalid, $hasValid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . '/' . $mode->value => [$mode, $key, $schema, $valid, $invalid, $hasValid];
            }
        }
    }

    #[DataProvider('interpreterPropertyNames')]
    public function testLaravelInterpreterSeparatesObjectAndPropertySchemas(GenerationMode $mode, string $name, bool $withRoot): void
    {
        $spec = self::probeSpec(['type' => 'string']);
        $root = [
            'type' => 'object',
            'required' => [$name],
            'properties' => [
                $name => ['type' => 'string', 'not' => ['const' => 'bad']],
                'marker' => ['type' => 'integer'],
            ],
        ];
        if ($withRoot) {
            $root['not'] = ['required' => ['marker'], 'properties' => ['marker' => ['const' => 0]]];
        }
        $spec['components']['schemas']['Probe'] = $root;
        $valid = json_encode([$name => 'good', 'marker' => 1], JSON_THROW_ON_ERROR);
        $invalid = json_encode([$name => 'bad', 'marker' => 1], JSON_THROW_ON_ERROR);
        $key = 'interpreter-name-' . $name . '-' . (int)$withRoot;
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, $key, $valid, $invalid),
        );
        if ($withRoot) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $spec, $key . '-root', $valid, json_encode([$name => 'good', 'marker' => 0], JSON_THROW_ON_ERROR)),
            );
        }
    }

    public static function interpreterPropertyNames(): iterable
    {
        foreach ([GenerationMode::Laravel, GenerationMode::LaravelData] as $mode) {
            foreach (['x-openapi-object', 'properties', 'fields', 'object', 'not'] as $name) {
                foreach ([false, true] as $root) {
                    yield $mode->value . '/' . $name . '/' . (int)$root => [$mode, $name, $root];
                }
            }
        }
    }

    public function testNullOnlyPresenceAcrossModes(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $required = self::probeSpec(['type' => 'null']);
            // Symfony's serializer supplies null for an omitted TYPED nullable constructor argument; the
            // Symfony DTO leaves a required nullable one untyped, so the missing key is reported there too.
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $required, 'required-null-presence', '{"f":null}', '{}'),
                message: $mode->value,
            );
            $optional = $required;
            $optional['components']['schemas']['Probe']['required'] = [];
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $optional, 'optional-null-presence', '{}', '{"f":"red"}'),
                message: $mode->value,
            );
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $optional, 'optional-null-value', '{"f":null}', '{"f":false}'),
                message: $mode->value,
            );
        }
    }

    /**
     * A nullable `$ref` enum: the member check is `Rule::enum()`, the only one it has. Dropping that rule
     * for nullable properties still refused `"x"` — at hydration, as a `CannotCastEnum` 500 instead of a
     * 422 — so the verdict alone cannot see it; the exception class is what is asserted.
     */
    public function testLaravelDataRejectsANullableRefEnumMemberInValidation(): void
    {
        $spec = self::probeSpec(['oneOf' => [['$ref' => '#/components/schemas/Shade'], ['type' => 'null']]]);
        $spec['components']['schemas']['Shade'] = ['type' => 'string', 'enum' => ['red', 'blue']];
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::LaravelData, 'nullable-ref-enum'), 'laravel-data');
        LaravelDataContainer::boot();

        $outcome = static fn(string $json): string => (string)LaravelDataContainer::withRequest(
            json: $json,
            work: static function (LaravelRequest $request) use ($fqcn): string {
                try {
                    $fqcn::from($request);
                } catch (Throwable $exception) {
                    return $exception::class;
                }

                return 'accepted';
            },
        );

        $this->assertSame('accepted', $outcome('{"f":"red"}'));
        $this->assertSame('accepted', $outcome('{"f":null}'));
        $this->assertSame(ValidationException::class, $outcome('{"f":"x"}'));
    }

    /**
     * laravel-data copies a mapped wire key to its PHP name and keeps both, so the root object counted
     * `wire-name` twice and `{"wire-name":"good"}` met `minProperties: 2`.
     */
    public function testAMappedPropertyCountsOnceTowardsTheObject(): void
    {
        $spec = self::probeSpec(['type' => 'string']);
        $spec['components']['schemas']['Probe'] = [
            'type' => 'object',
            'minProperties' => 2,
            'required' => ['wire-name'],
            'properties' => ['wire-name' => ['type' => 'string'], 'marker' => ['type' => 'integer']],
        ];
        foreach (GenerationMode::cases() as $mode) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $spec, 'mapped-root-count', '{"wire-name":"good","marker":1}', '{"wire-name":"good"}'),
                message: $mode->value,
            );
        }
    }

    /**
     * A `$ref` to a whole file, or to a pointer outside `components.schemas` of another file, names
     * a schema like any component does. Both used to be read as "no constraint": the property became
     * `mixed`, and every mode accepted whatever arrived.
     */
    public function testAnExternalReferenceToAWholeFileOrAnyPointerIsValidated(): void
    {
        $this->siblingDocuments = [
            'Animal.json' => [
                'type' => 'object',
                'properties' => ['legs' => ['type' => 'integer']],
                'required' => ['legs'],
            ],
            'shapes.json' => [
                'Side' => ['type' => 'integer', 'minimum' => 3],
                'definitions' => [
                    'Corner' => [
                        'type' => 'object',
                        'properties' => ['angle' => ['$ref' => '#/Side']],
                        'required' => ['angle'],
                    ],
                ],
            ],
            'Box.json' => [
                'type' => 'object',
                'properties' => [
                    'inner' => ['$ref' => '#'],
                    'tag' => ['$ref' => 'shapes.json#/Side'],
                ],
            ],
            'tiles.json' => [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => ['Tile' => ['type' => 'string', 'minLength' => 2]]],
            ],
        ];
        $cases = [
            'whole-file' => [['$ref' => 'Animal.json'], '{"f":{"legs":4}}', ['"text"', '{}', '{"legs":"four"}']],
            'whole-file-relative' => [['$ref' => './Animal.json'], '{"f":{"legs":4}}', ['"text"', '{"legs":"four"}']],
            'root-pointer' => [['$ref' => 'shapes.json#/Side'], '{"f":5}', ['"text"', '2']],
            'nested-pointer' => [['$ref' => 'shapes.json#/definitions/Corner'], '{"f":{"angle":4}}', ['{}', '{"angle":1}']],
            // A scalar is left out: Symfony lets one through to a self-referencing DTO whatever
            // spelling names it — `#/components/schemas/Box` too.
            'self-reference' => [['$ref' => 'Box.json'], '{"f":{"inner":{"tag":3}}}', ['{"tag":1}', '{"inner":{"tag":1}}']],
            // The neighbour that always worked keeps working.
            'component' => [['$ref' => 'tiles.json#/components/schemas/Tile'], '{"f":"ab"}', ['"a"', '5']],
            'pointer-through-a-local-pointer' => [['$ref' => 'shapes.json#/definitions/Corner/properties/angle'], '{"f":4}', ['1', '"text"']],
        ];
        foreach ($cases as $name => [$propertySchema, $validJson, $invalidJsons]) {
            $spec = self::probeSpec($propertySchema);
            foreach (GenerationMode::cases() as $mode) {
                foreach ($invalidJsons as $index => $invalidJson) {
                    $this->assertSame(
                        expected: ['valid' => true, 'invalid' => false],
                        actual: $this->verdict($mode, $spec, 'external-' . $name . '-' . $index, $validJson, '{"f":' . $invalidJson . '}'),
                        message: $mode->value . ' ' . $name . ' ' . $invalidJson,
                    );
                }
            }
        }
    }

    /**
     * A pointer outside `components.schemas` of the document itself names a schema too.
     */
    public function testALocalReferenceOutsideTheComponentsIsValidated(): void
    {
        $cases = [
            'definitions' => [['$ref' => '#/definitions/Corner'], '{"f":{"angle":4}}', ['"text"', '{}', '{"angle":1}']],
            'root-entry' => [['$ref' => '#/shapes/Side'], '{"f":5}', ['"text"', '2']],
        ];
        foreach ($cases as $name => [$propertySchema, $validJson, $invalidJsons]) {
            $spec = self::probeSpec($propertySchema);
            $spec['shapes'] = ['Side' => ['type' => 'integer', 'minimum' => 3]];
            $spec['definitions'] = [
                'Corner' => ['type' => 'object', 'properties' => ['angle' => ['$ref' => '#/shapes/Side']], 'required' => ['angle']],
            ];
            foreach (GenerationMode::cases() as $mode) {
                foreach ($invalidJsons as $index => $invalidJson) {
                    $this->assertSame(
                        expected: ['valid' => true, 'invalid' => false],
                        actual: $this->verdict($mode, $spec, 'local-pointer-' . $name . '-' . $index, $validJson, '{"f":' . $invalidJson . '}'),
                        message: $mode->value . ' ' . $name . ' ' . $invalidJson,
                    );
                }
            }
        }
    }

    /**
     * A reference that names nothing stops generation: a missing file, a pointer the file does not
     * contain, or a whole OpenAPI document used as a schema. Each used to generate `mixed`.
     */
    public function testAnExternalReferenceToNothingStopsGeneration(): void
    {
        $this->siblingDocuments = [
            'shapes.json' => ['Side' => ['type' => 'integer']],
            'tiles.json' => [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => ['Tile' => ['type' => 'string']]],
            ],
        ];
        $cases = [
            'missing.json' => 'Referenced OpenAPI file not found: missing.json',
            'missing.json#/Side' => 'Referenced OpenAPI file not found: missing.json#/Side',
            'sub/../missing.yaml#/Side' => 'Referenced OpenAPI file not found: sub/../missing.yaml#/Side',
            'shapes.json#/Nothing' => 'Unresolvable reference "shapes.json#/Nothing"',
            'tiles.json' => 'Unresolvable reference "tiles.json"',
        ];
        foreach ($cases as $ref => $message) {
            foreach (GenerationMode::cases() as $mode) {
                $refusal = 'generated';
                try {
                    $this->generate(self::probeSpec(['$ref' => $ref]), 'App\ExternalNothing' . md5($ref . $mode->value), $mode->value);
                } catch (RuntimeException $exception) {
                    $refusal = $exception->getMessage();
                }
                $this->assertStringContainsString(needle: $message, haystack: $refusal, message: $mode->value . ' ' . $ref);
            }
        }
    }

    /**
     * A `$ref` to a component that is `{type: null}` is the null schema, not an empty object.
     */
    public function testAReferencedNullSchemaAcceptsOnlyNull(): void
    {
        foreach (['null', ['null']] as $index => $type) {
            $spec = self::probeSpec(['$ref' => '#/components/schemas/Nothing']);
            $spec['components']['schemas']['Nothing'] = ['type' => $type];
            foreach (GenerationMode::cases() as $mode) {
                foreach (['"x"', '{}', '0'] as $invalid) {
                    $this->assertSame(
                        expected: ['valid' => true, 'invalid' => false],
                        actual: $this->verdict($mode, $spec, 'ref-null-' . $index . '-' . $invalid, '{"f":null}', '{"f":' . $invalid . '}'),
                        message: $mode->value . ' ' . $invalid,
                    );
                }
            }
        }
    }

    /**
     * A pattern PCRE cannot compile is refused as a validation error in every mode. The native rules
     * of Laravel and Yii3 raised a `preg_match()` warning instead — a 500 under Laravel.
     */
    public function testAnUncompilablePatternIsAValidationError(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            foreach ([['pattern' => '^(a$'], ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^(a$']]] as $index => $schema) {
                $spec = self::probeSpec($schema + ['type' => 'string']);
                $value = $index === 0 ? '"a"' : '["a"]';
                $this->assertSame(
                    expected: ['valid' => false, 'invalid' => false],
                    actual: $this->verdict($mode, $spec, 'uncompilable-' . $index, '{"f":' . $value . '}', '{"f":' . $value . '}'),
                    message: $mode->value . ' ' . $index,
                );
            }
        }
    }

    /**
     * Null where the schema does not allow it, and null where it does, inside a list.
     */
    public function testNullIsJudgedByTheSchemaNotTheMode(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            // yii3: the hydrator casts `null` to the declared `string`, and the raw `#[Validate]` is
            // what still sees the null and refuses it.
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, self::probeSpec(['type' => 'string']), 'required-string-null', '{"f":"a"}', '{"f":null}'),
                message: $mode->value . ' required string',
            );
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, self::probeSpec(['type' => 'array', 'items' => ['type' => ['string', 'null']]]), 'nullable-items', '{"f":[null,"a"]}', '{"f":[1]}'),
                message: $mode->value . ' nullable items',
            );
        }
    }

    /**
     * Mode divergences found by the 2.15.49 audit — each pair is the spec's own verdict, asked of all
     * five modes.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('modeDivergenceCases')]
    public function testModeDivergencesFoundByTheAudit(GenerationMode $mode, string $key, array $spec, string $validJson, string $invalidJson): void
    {
        // yii3 used to cast `null` to a declared `string` member before any rule ran; the raw check over
        // the union's members now sees the null first.
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'divergence-' . $key, $validJson, $invalidJson),
        );
    }

    public static function modeDivergenceCases(): iterable
    {
        $optional = static function (array $schema): array {
            $spec = self::probeSpec($schema);
            $spec['components']['schemas']['Probe']['required'] = [];

            return $spec;
        };
        $withShade = static function (array $schema): array {
            $spec = self::probeSpec($schema);
            $spec['components']['schemas']['Shade'] = ['type' => 'string', 'enum' => ['red', 'blue']];

            return $spec;
        };
        $cases = [
            'untyped accepts null' => [self::probeSpec(['not' => ['const' => 5]]), '{"f":null}', '{"f":5}'],
            'union refuses null' => [self::probeSpec(['oneOf' => [['type' => 'string'], ['type' => 'integer']]]), '{"f":"a"}', '{"f":null}'],
            'anyOf union refuses null' => [self::probeSpec(['anyOf' => [['type' => 'string'], ['type' => 'integer']]]), '{"f":1}', '{"f":null}'],
            'optional nullable enum member' => [$optional(['type' => ['string', 'null'], 'enum' => ['red', 'blue', null]]), '{}', '{"f":"green"}'],
            'optional nullable enum null' => [$optional(['type' => ['string', 'null'], 'enum' => ['red', 'blue', null]]), '{"f":null}', '{"f":"green"}'],
            'nullable array enum items' => [self::probeSpec(['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => ['a', 'b']]]), '{"f":["a"]}', '{"f":["z"]}'],
            'item object maxProperties' => [self::probeSpec(['type' => 'array', 'items' => ['type' => 'object', 'maxProperties' => 1]]), '{"f":[{"a":1}]}', '{"f":[{"a":1,"b":2}]}'],
            'item object minProperties' => [self::probeSpec(['type' => 'array', 'items' => ['type' => 'object', 'minProperties' => 2]]), '{"f":[{"a":1,"b":2}]}', '{"f":[{"a":1}]}'],
            'enum items' => [self::probeSpec(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']]]), '{"f":["a"]}', '{"f":["z"]}'],
            'enum ref items' => [$withShade(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shade']]), '{"f":["red"]}', '{"f":["green"]}'],
            'enum map values' => [self::probeSpec(['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['a', 'b']]]), '{"f":{"k":"a"}}', '{"f":{"k":"z"}}'],
            'allOf enum and const' => [self::probeSpec(['allOf' => [['type' => 'string', 'enum' => ['a', 'b']], ['const' => 'a']]]), '{"f":"a"}', '{"f":"b"}'],
        ];
        foreach ($cases as $key => [$spec, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . '/' . $mode->value => [$mode, $key, $spec, $valid, $invalid];
            }
        }
    }

    /**
     * Object shapes the runtime refused or emptied while the other modes took them: a property-level
     * union with an object branch (`[map, null]`, `[{type: object}, string]`) refused every JSON
     * object as "expects array, got object"; keys beside a `$ref` were merged at property level only,
     * not in items, map values or a union branch; and `type: [object, null]` became an empty class.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('objectShapeCases')]
    public function testObjectShapesAreJudgedAlikeInEveryPosition(GenerationMode $mode, string $key, array $spec, string $validJson, string $invalidJson): void
    {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'object-shape-' . $key, $validJson, $invalidJson),
        );
    }

    public static function objectShapeCases(): iterable
    {
        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];
        $withComponents = static function (array $schema): array {
            $spec = self::probeSpec($schema);
            $spec['components']['schemas']['Tally'] = ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];
            $spec['components']['schemas']['Loose'] = ['type' => 'object'];
            $spec['components']['schemas']['Swatch'] = ['type' => 'object', 'properties' => ['colour' => ['type' => 'string']]];

            return $spec;
        };
        $swatchAnd = static fn(array $required): array => [
            '$ref' => '#/components/schemas/Swatch',
            'properties' => ['size' => ['type' => 'integer']],
        ] + ($required === [] ? [] : ['required' => $required]);
        $cases = [
            'inline map or null' => [self::probeSpec(['anyOf' => [$map, ['type' => 'null']]]), '{"f":{"a":1}}', '{"f":"x"}'],
            'map ref or null' => [$withComponents(['oneOf' => [['$ref' => '#/components/schemas/Tally'], ['type' => 'null']]]), '{"f":{"a":1}}', '{"f":{"a":"x"}}'],
            'map ref or null given null' => [$withComponents(['oneOf' => [['$ref' => '#/components/schemas/Tally'], ['type' => 'null']]]), '{"f":null}', '{"f":"x"}'],
            'free object or string' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'string']]]), '{"f":{"x":1}}', '{"f":true}'],
            'free object or string given a string' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'string']]]), '{"f":"s"}', '{"f":1}'],
            'free object or integer' => [self::probeSpec(['oneOf' => [['type' => 'object'], ['type' => 'integer']]]), '{"f":{"x":1}}', '{"f":"s"}'],
            'free object or null' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'null']]]), '{"f":{"k":1}}', '{"f":1}'],
            'free object or null given {}' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'null']]]), '{"f":{}}', '{"f":"s"}'],
            'free object ref or null' => [$withComponents(['anyOf' => [['$ref' => '#/components/schemas/Loose'], ['type' => 'null']]]), '{"f":{"k":1}}', '{"f":1}'],
            'list or object' => [self::probeSpec(['anyOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'object']]]), '{"f":{"x":1}}', '{"f":true}'],
            'list or object given a list' => [self::probeSpec(['anyOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'object']]]), '{"f":["a"]}', '{"f":1}'],
            'map or string' => [self::probeSpec(['anyOf' => [$map, ['type' => 'string']]]), '{"f":{"x":1}}', '{"f":1}'],
            'type object or null' => [self::probeSpec(['type' => ['object', 'null']]), '{"f":{"k":1}}', '{"f":1}'],
            'items of type object or null' => [self::probeSpec(['type' => 'array', 'items' => ['type' => ['object', 'null']]]), '{"f":[{"k":1},null]}', '{"f":[1]}'],
            'ref beside keys in items' => [$withComponents(['type' => 'array', 'items' => $swatchAnd([])]), '{"f":[{"size":2,"colour":"a"}]}', '{"f":[{"size":"x"}]}'],
            'ref beside required in items' => [$withComponents(['type' => 'array', 'items' => $swatchAnd(['size'])]), '{"f":[{"size":2}]}', '{"f":[{"colour":"a"}]}'],
            'ref beside keys in map values' => [$withComponents(['type' => 'object', 'additionalProperties' => $swatchAnd([])]), '{"f":{"k":{"size":2}}}', '{"f":{"k":{"size":"x"}}}'],
            'ref beside required in map values' => [$withComponents(['type' => 'object', 'additionalProperties' => $swatchAnd(['size'])]), '{"f":{"k":{"size":2}}}', '{"f":{"k":{}}}'],
            'ref beside required or null' => [$withComponents(['anyOf' => [$swatchAnd(['size']), ['type' => 'null']]]), '{"f":{"size":2}}', '{"f":{"colour":"a"}}'],
            'ref beside keys or null' => [$withComponents(['anyOf' => [$swatchAnd([]), ['type' => 'null']]]), '{"f":{"size":2}}', '{"f":{"size":"x"}}'],
        ];
        foreach ($cases as $key => [$spec, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . '/' . $mode->value => [$mode, $key, $spec, $valid, $invalid];
            }
        }
    }

    /**
     * Shapes judged differently by one mode or two: an unsent container whose `default` breaks its
     * own count keyword (symfony refused `{}`); a nullable container alias or nullable inline map,
     * whose inside nothing checked (symfony, and yii3 for the 3.0 `allOf` spelling); a numeric
     * `format` on a `type: string` (symfony applied the integer range to the string); keys beside
     * a `$ref` that admit null the referenced schema refuses; a string `format` on a number
     * (laravel, laravel-data and yii3 applied it); `minProperties` and its neighbours on a class,
     * which the runtime counted on the declared properties only.
     *
     * @param array<string, mixed> $spec
     * @param array{valid: bool, invalid: bool} $expected
     */
    #[DataProvider('modeSpecificShapeCases')]
    public function testModeSpecificShapesAreJudgedByTheSchema(GenerationMode $mode, string $key, array $spec, string $validJson, string $invalidJson, array $expected): void
    {
        $this->assertSame(
            expected: $expected,
            actual: $this->verdict($mode, $spec, 'mode-shape-' . $key, $validJson, $invalidJson),
        );
    }

    public static function modeSpecificShapeCases(): iterable
    {
        $optional = static function (array $schema): array {
            $spec = self::probeSpec($schema);
            $spec['components']['schemas']['Probe'] = ['type' => 'object', 'properties' => ['f' => $schema]];

            return $spec;
        };
        $withComponents = static function (array $schema, string $version = '3.1.0'): array {
            $spec = self::probeSpec($schema);
            $spec['openapi'] = $version;
            $spec['components']['schemas']['Pair'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2];
            $spec['components']['schemas']['Tally'] = ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];
            $spec['components']['schemas']['Swatch'] = ['type' => 'object', 'properties' => ['colour' => ['type' => 'string']]];
            $spec['components']['schemas']['Hue'] = ['type' => 'string'];

            return $spec;
        };
        $list = ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2];
        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'minProperties' => 1];
        $pairOrNull = ['anyOf' => [['$ref' => '#/components/schemas/Pair'], ['type' => 'null']]];
        $tallyOrNull = ['anyOf' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], ['type' => 'null']]];
        $shape = static fn(array $keywords): array => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]] + $keywords;
        // Symfony and yii3 build the class from the payload and drop every undeclared key before a
        // rule runs: README.support-matrix lists it beside `additionalProperties: false`.
        // The runtime deserializer counts the body (`GeneratedConstraintsIntegrationTest`), but the
        // write-back keeps the declared keys only, and `{"a":1}` is short of `minProperties: 2`.
        $declaredOnly = [
            'runtime' => ['valid' => false, 'invalid' => false],
            'symfony' => ['valid' => false, 'invalid' => false],
            'yii3' => ['valid' => false, 'invalid' => false],
        ];
        $undeclaredUnseen = ['symfony' => ['valid' => true, 'invalid' => true], 'yii3' => ['valid' => true, 'invalid' => true]];
        $cases = [
            'unsent list default below minItems' => [$optional($list + ['default' => [1]]), '{}', '{"f":[1]}'],
            'unsent map default below minProperties' => [$optional($map + ['default' => []]), '{}', '{"f":{}}'],
            'unsent list default within minItems' => [$optional($list + ['default' => [1, 2]]), '{}', '{"f":[1]}'],
            'sent list beside a breaking default' => [$optional($list + ['default' => [1]]), '{"f":[1,2]}', '{"f":[1]}'],
            'list alias or null past maxItems' => [$withComponents($pairOrNull), '{"f":[1,2]}', '{"f":[1,2,3]}'],
            'list alias or null with a bad item' => [$withComponents($pairOrNull), '{"f":null}', '{"f":["x"]}'],
            'list alias or null or string' => [$withComponents(['anyOf' => [['$ref' => '#/components/schemas/Pair'], ['type' => 'null'], ['type' => 'string']]]), '{"f":"s"}', '{"f":[1,2,3]}'],
            'inline map or null with a bad value' => [self::probeSpec($tallyOrNull), '{"f":{"a":1}}', '{"f":{"a":"x"}}'],
            'map alias nullable through allOf (3.0)' => [$withComponents(['allOf' => [['$ref' => '#/components/schemas/Tally']], 'nullable' => true], '3.0.3'), '{"f":{"a":1}}', '{"f":{"a":"x"}}'],
            'map alias nullable through allOf given null (3.0)' => [$withComponents(['allOf' => [['$ref' => '#/components/schemas/Tally']], 'nullable' => true], '3.0.3'), '{"f":null}', '{"f":{"a":"x"}}'],
            'map alias through allOf' => [$withComponents(['allOf' => [['$ref' => '#/components/schemas/Tally']]]), '{"f":{"a":1}}', '{"f":{"a":"x"}}'],
            'string int32 past the integer range' => [self::probeSpec(['type' => 'string', 'format' => 'int32']), '{"f":"5000000000"}', '{"f":5}'],
            'string uint32 below zero' => [self::probeSpec(['type' => 'string', 'format' => 'uint32']), '{"f":"-1"}', '{"f":5}'],
            'integer int32 past the integer range' => [self::probeSpec(['type' => 'integer', 'format' => 'int32']), '{"f":5}', '{"f":5000000000}'],
            'object ref beside type object or null' => [$withComponents(['$ref' => '#/components/schemas/Swatch', 'type' => ['object', 'null']]), '{"f":{"colour":"a"}}', '{"f":null}'],
            'string ref beside type string or null' => [$withComponents(['$ref' => '#/components/schemas/Hue', 'type' => ['string', 'null']]), '{"f":"a"}', '{"f":null}'],
            'object ref or null' => [$withComponents(['anyOf' => [['$ref' => '#/components/schemas/Swatch'], ['type' => 'null']]]), '{"f":null}', '{"f":{"colour":5}}'],
            'integer with format date-time' => [self::probeSpec(['type' => 'integer', 'format' => 'date-time']), '{"f":1700000000}', '{"f":"x"}'],
            'integer with format uuid' => [self::probeSpec(['type' => 'integer', 'format' => 'uuid']), '{"f":5}', '{"f":"x"}'],
            'integer with format email' => [self::probeSpec(['type' => 'integer', 'format' => 'email']), '{"f":5}', '{"f":"x"}'],
            'string with format email' => [self::probeSpec(['type' => 'string', 'format' => 'email']), '{"f":"a@example.com"}', '{"f":"x"}'],
            'class minProperties counts undeclared keys' => [self::probeSpec($shape(['minProperties' => 2])), '{"f":{"a":1,"z":2}}', '{"f":{"a":1}}', $declaredOnly],
            'class maxProperties counts undeclared keys' => [self::probeSpec($shape(['maxProperties' => 1])), '{"f":{"a":1}}', '{"f":{"a":1,"z":2}}', $undeclaredUnseen],
            'class propertyNames sees undeclared keys' => [self::probeSpec($shape(['propertyNames' => ['maxLength' => 1]])), '{"f":{"a":1,"z":2}}', '{"f":{"a":1,"zz":2}}', $undeclaredUnseen],
            'class patternProperties sees undeclared keys' => [self::probeSpec($shape(['patternProperties' => ['^x' => ['type' => 'integer']]])), '{"f":{"a":1,"x1":2}}', '{"f":{"a":1,"x1":"s"}}', $undeclaredUnseen],
            'class minProperties met by declared keys' => [self::probeSpec($shape(['minProperties' => 1])), '{"f":{"a":1}}', '{"f":{}}'],
            // Symfony's `BackedEnumNormalizer` and laravel-data hand "1" to `Level::from()`, which
            // takes it: README.support-matrix lists it beside the numeric string for `type: integer`.
            'integer enum given a numeric string' => [
                self::probeSpec(['type' => 'integer', 'enum' => [1, 2]]),
                '{"f":2}',
                '{"f":"1"}',
                ['symfony' => ['valid' => true, 'invalid' => true], 'laravel-data' => ['valid' => true, 'invalid' => true]],
            ],
        ];
        foreach ($cases as $key => $case) {
            [$spec, $valid, $invalid] = $case;
            $diverges = $case[3] ?? [];
            foreach (GenerationMode::cases() as $mode) {
                yield $key . '/' . $mode->value => [$mode, $key, $spec, $valid, $invalid, $diverges[$mode->value] ?? ['valid' => true, 'invalid' => false]];
            }
        }
    }

    /**
     * What the runtime writes back is what it read: keys beside a `$ref` in items or map values, a
     * `type: [object, null]` free-form object, an empty object in a union whose only container is an
     * object. Each was accepted and came back emptied — `{"size":2}` as `{}`, `{}` as `[]`.
     */
    #[DataProvider('runtimeRoundTrips')]
    public function testTheRuntimeWritesBackWhatItRead(array $spec, string $key, string $json): void
    {
        $this->assertSame(expected: $json, actual: $this->runtimeRoundTrip($spec, 'round-trip-' . $key, $json));
    }

    public static function runtimeRoundTrips(): iterable
    {
        $withSwatch = static function (array $schema): array {
            $spec = self::probeSpec($schema);
            $spec['components']['schemas']['Swatch'] = ['type' => 'object', 'properties' => ['colour' => ['type' => 'string']]];

            return $spec;
        };
        $swatchAndSize = ['$ref' => '#/components/schemas/Swatch', 'properties' => ['size' => ['type' => 'integer']]];
        $objectOrNull = self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'null']]]);
        $objectOrString = self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'string']]]);
        $mapOrNull = self::probeSpec(['anyOf' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], ['type' => 'null']]]);

        yield 'ref beside keys in items' => [$withSwatch(['type' => 'array', 'items' => $swatchAndSize]), 'items', '{"f":[{"colour":"a","size":2}]}'];
        yield 'ref beside keys in map values' => [$withSwatch(['type' => 'object', 'additionalProperties' => $swatchAndSize]), 'map', '{"f":{"k":{"colour":"a","size":2}}}'];
        yield 'type object or null' => [self::probeSpec(['type' => ['object', 'null']]), 'nullable', '{"f":{"k":1}}'];
        yield 'type object or null given null' => [self::probeSpec(['type' => ['object', 'null']]), 'nullable', '{"f":null}'];
        yield 'items of type object or null' => [self::probeSpec(['type' => 'array', 'items' => ['type' => ['object', 'null']]]), 'nullable-items', '{"f":[{"k":1},null]}'];
        yield 'object or null' => [$objectOrNull, 'object-or-null', '{"f":{"k":1}}'];
        yield 'object or null given {}' => [$objectOrNull, 'object-or-null', '{"f":{}}'];
        yield 'object or null given a digit key' => [$objectOrNull, 'object-or-null', '{"f":{"0":1}}'];
        yield 'object or string given {}' => [$objectOrString, 'object-or-string', '{"f":{}}'];
        yield 'object or string given a string' => [$objectOrString, 'object-or-string', '{"f":"s"}'];
        yield 'map or null given {}' => [$mapOrNull, 'map-or-null', '{"f":{}}'];
        yield 'map or null' => [$mapOrNull, 'map-or-null', '{"f":{"a":1}}'];
    }

    /**
     * The values of a map inside a union are checked once the union takes the object: before, the
     * object never got that far.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('unionMapValues')]
    public function testTheRuntimeChecksTheValuesOfAMapInAUnion(array $spec, string $key, string $validJson, string $invalidJson): void
    {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->runtimeVerdict($spec, 'union-map-values-' . $key, $validJson, $invalidJson),
        );
    }

    public static function unionMapValues(): iterable
    {
        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];

        yield 'map or null' => [self::probeSpec(['anyOf' => [$map, ['type' => 'null']]]), 'or-null', '{"f":{"a":1}}', '{"f":{"a":"x"}}'];
        yield 'map or string' => [self::probeSpec(['anyOf' => [$map, ['type' => 'string']]]), 'or-string', '{"f":{"a":1}}', '{"f":{"a":"x"}}'];
        yield 'object or string given a list' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'string']]]), 'object-or-string', '{"f":{"a":1}}', '{"f":[1]}'];
        yield 'object or null given a list' => [self::probeSpec(['anyOf' => [['type' => 'object'], ['type' => 'null']]]), 'object-or-null', '{"f":{"a":1}}', '{"f":[]}'];
    }

    /**
     * A JSON array is no object at any depth the runtime decoded itself. The cast checks the levels it
     * types; an undeclared key under `additionalProperties: {type: object}` and the items of a list of
     * lists were only asked of the validator, which reads any PHP array as a possible map, and `[1, 2]`
     * came back as `{"0":1,"1":2}`.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('arraysWhereObjectsBelong')]
    public function testTheRuntimeRefusesAnArrayWhereAnObjectBelongs(array $spec, string $key, string $json, bool $accepted): void
    {
        $verdict = $this->runtimeVerdict($spec, 'array-for-object-' . $key, $json, $json);
        $this->assertSame(expected: $accepted, actual: $verdict['valid'], message: $json);
    }

    public static function arraysWhereObjectsBelong(): iterable
    {
        $extras = static function (array $extra): array {
            $spec = self::probeSpec(['type' => 'string']);
            $spec['components']['schemas']['Probe']['additionalProperties'] = $extra;

            return $spec;
        };
        $objectExtras = $extras(['type' => 'object']);
        $listOfObjectsExtras = $extras(['type' => 'array', 'items' => ['type' => 'object']]);
        $listOfLists = self::probeSpec(['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'object']]]);

        yield 'extra object given a list' => [$objectExtras, 'extra', '{"f":"a","e":[1,2]}', false];
        yield 'extra object given []' => [$objectExtras, 'extra', '{"f":"a","e":[]}', false];
        yield 'extra object given an object' => [$objectExtras, 'extra', '{"f":"a","e":{"k":1}}', true];
        yield 'extra object given {}' => [$objectExtras, 'extra', '{"f":"a","e":{}}', true];
        yield 'extra list of objects given [[1]]' => [$listOfObjectsExtras, 'extra-list', '{"f":"a","e":[[1]]}', false];
        yield 'extra list of objects given [[]]' => [$listOfObjectsExtras, 'extra-list', '{"f":"a","e":[[]]}', false];
        yield 'extra list of objects given [{}]' => [$listOfObjectsExtras, 'extra-list', '{"f":"a","e":[{}]}', true];
        yield 'list of lists given [[[1]]]' => [$listOfLists, 'nested', '{"f":[[[1,2]]]}', false];
        yield 'list of lists given [[[]]]' => [$listOfLists, 'nested', '{"f":[[[]]]}', false];
        yield 'list of lists given [[{}]]' => [$listOfLists, 'nested', '{"f":[[{"a":1}]]}', true];
        yield 'list of lists given [[]]' => [$listOfLists, 'nested', '{"f":[[]]}', true];
    }

    /**
     * An enum ITEM is refused by Laravel's validator, not by the hydrator afterwards: no `field.*`
     * rule existed, so `["z"]` passed validation and died in `fromValidated()` — a 500, not a 422.
     */
    public function testLaravelRefusesAnEnumItemInValidation(): void
    {
        $fqcn = $this->generate(
            spec: self::probeSpec(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']]]),
            namespace: $this->namespaceFor(GenerationMode::Laravel, 'enum-item-validation'),
            mode: 'laravel',
        );
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        foreach (['{"f":["a"]}' => false, '{"f":["z"]}' => true] as $json => $fails) {
            /** @var array<string, mixed> $rules */
            $rules = call_user_func([$fqcn, 'rules']);
            $validator = $factory->make(json_decode($json, true), $rules);
            call_user_func([$fqcn, 'withValidator'], $validator, $json);
            $this->assertSame($fails, $validator->fails(), $json);
        }
    }

    /**
     * The documented deviation from OpenAPI 3.0.3 (README.validation): the 3.0 `nullable: true`
     * keyword admits null next to an `enum` / `const` that does not list it, inline and through a
     * `$ref`. The 3.1 spelling stays JSON Schema: there `enum` decides alone.
     */
    #[DataProvider('nullableEnumSpellings')]
    public function testNullableKeywordAdmitsNullBesideEnum(GenerationMode $mode, string $key, string $version, array $property, bool $nullAccepted): void
    {
        $spec = self::probeSpec($property);
        $spec['openapi'] = $version;
        $spec['components']['schemas']['EnumView'] = ['nullable' => true, 'type' => 'string', 'enum' => ['a', 'b']];
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'nullable-enum-' . $key, '{"f":"a"}', '{"f":"z"}'),
            message: $key . ' members',
        );
        $this->assertSame(
            expected: ['valid' => $nullAccepted, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'nullable-enum-null-' . $key, '{"f":null}', '{"f":"z"}'),
            message: $key . ' null',
        );
    }

    public static function nullableEnumSpellings(): iterable
    {
        $cases = [
            '3.0 inline enum' => ['3.0.3', ['nullable' => true, 'type' => 'string', 'enum' => ['a', 'b']], true],
            '3.0 ref enum' => ['3.0.3', ['$ref' => '#/components/schemas/EnumView'], true],
            '3.0 inline const' => ['3.0.3', ['nullable' => true, 'type' => 'string', 'const' => 'a'], true],
            '3.1 type list enum' => ['3.1.0', ['type' => ['string', 'null'], 'enum' => ['a', 'b']], false],
            '3.1 type list enum with null' => ['3.1.0', ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]], true],
        ];
        foreach ($cases as $key => [$version, $property, $nullAccepted]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . '/' . $mode->value => [$mode, $key, $version, $property, $nullAccepted];
            }
        }
    }

    /**
     * OpenAPI 3.0 has no nullable `$ref`; a document writes one as `nullable: true` beside a one-branch
     * `allOf`. Where the reference is inlined — two containers down, nothing materializes — the
     * `nullable` stayed above the branch, and the branch refused the null it was meant to admit.
     */
    #[DataProvider('everyMode')]
    public function testANullableAllOfReferenceAdmitsNullWhereItIsInlined(GenerationMode $mode): void
    {
        $spec = self::probeSpec([
            'type' => 'array',
            'items' => ['type' => 'array', 'items' => ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Person']]]],
        ]);
        $spec['openapi'] = '3.0.3';
        $spec['components']['schemas']['Person'] = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'nullable-allof-ref', '{"f":[[null, {"name":"a"}]]}', '{"f":[[{}]]}'),
        );
    }

    public static function everyMode(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * The value of a key that `additionalProperties: {schema}` admits beside named properties is judged
     * against that schema where the raw body is reachable — runtime and both Laravel modes. The Symfony
     * serializer and the yii3 hydrator drop the undeclared key before any validator runs, so neither
     * sees the value: pinned as accepted there (README.support-matrix), to notice if that ever changes.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('additionalKeyCases')]
    public function testTheValueOfAnAdditionalKeyBesideNamedProperties(GenerationMode $mode, string $key, array $property): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Bag'] = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer']],
            'additionalProperties' => ['type' => 'string', 'maxLength' => 3],
        ];
        $frameworkDrops = in_array($mode, [GenerationMode::Symfony, GenerationMode::Yii3], true);

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => $frameworkDrops],
            actual: $this->verdict($mode, $spec, 'additional-key-' . $key, '{"f":{"a":1,"k":"abc"}}', '{"f":{"a":1,"k":"abcd"}}'),
        );
    }

    public static function additionalKeyCases(): iterable
    {
        $cases = [
            'inline' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string', 'maxLength' => 3]],
            'component' => ['$ref' => '#/components/schemas/Bag'],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => $property) {
                yield $mode->value . ' ' . $key => [$mode, $key, $property];
            }
        }
    }

    /**
     * A schema that holds itself — a tree — is checked at every depth the data reaches. Laravel's rules
     * are written out ahead of the data, so the cycle guard stopped them one level down: a wrong value
     * deeper than that passed validation and failed in hydration — a 500 (`fromValidated(): Argument #1
     * must be of type array`) where a 422 was due. The deeper levels are checked as they are reached.
     */
    #[DataProvider('everyMode')]
    public function testASelfReferringSchemaIsCheckedAtEveryDepth(GenerationMode $mode): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Node']);
        $spec['components']['schemas']['Node'] = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'child' => ['$ref' => '#/components/schemas/Node']],
        ];
        $deep = static fn(string $leaf): string => '{"f":{"name":"a","child":{"name":"b","child":{"name":"c","child":' . $leaf . '}}}}';

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'tree-leaf-type', $deep('{"name":"d"}'), $deep('{"name":4}')),
        );
        // A scalar where a class is due is the serializer's to refuse in symfony mode, and whether it does
        // depends on its release — README.support-matrix, "a scalar for a property typed as a generated
        // class": 7.4.10 built an empty object, 7.4.23 and 8.1 refuse it. Only the valid tree is asserted
        // there; every other mode must refuse.
        $verdict = $this->verdict($mode, $spec, 'tree-leaf-shape', $deep('{"name":"d"}'), $deep('false'));
        $this->assertTrue($verdict['valid']);
        if ($mode !== GenerationMode::Symfony) {
            $this->assertFalse($verdict['invalid']);
        }
    }

    /**
     * A `$ref` to an object class with keys described beside it is both (JSON Schema 2020-12, OpenAPI
     * 3.1): the property was typed by the named class alone, and `properties` / `required` beside the
     * `$ref` were dropped — `{"foo": "x"}` passed a `foo: {type: integer}`, and a missing required
     * `foo` was not noticed. The keys of the named class still apply.
     */
    #[DataProvider('everyMode')]
    public function testKeysBesideAnObjectReferenceApply(GenerationMode $mode): void
    {
        $spec = self::probeSpec([
            '$ref' => '#/components/schemas/Inner',
            'properties' => ['foo' => ['type' => 'integer']],
            'required' => ['foo'],
        ]);
        $spec['components']['schemas']['Inner'] = ['type' => 'object', 'properties' => ['bar' => ['type' => 'string']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'ref-siblings-own-key', '{"f":{"bar":"a","foo":1}}', '{"f":{"bar":"a","foo":"x"}}'),
        );
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'ref-siblings-required', '{"f":{"foo":1}}', '{"f":{"bar":"a"}}'),
        );
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'ref-siblings-target-key', '{"f":{"bar":"a","foo":2}}', '{"f":{"bar":1,"foo":2}}'),
        );
    }

    /**
     * A `$ref` that points INSIDE a component — at one of its properties, into its `$defs` — names that
     * schema. It read as a class called `Pet/properties/code` and stopped generation as unresolvable. A
     * boolean component is the schema it spells: `true` admits every value, `false` none — so for those
     * two cases both payloads share one verdict.
     *
     * @param array<string, mixed> $property
     * @param array<string, mixed> $extra
     * @param array{valid: bool, invalid: bool} $expected the verdicts on the first and the second payload
     */
    #[DataProvider('componentSubschemaCases')]
    public function testAReferenceIntoAComponentNamesTheSchemaThere(GenerationMode $mode, string $key, array $property, array $extra, string $first, string $second, array $expected): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas'] = [...$spec['components']['schemas'], ...$extra];

        $this->assertSame(
            expected: $expected,
            actual: $this->verdict($mode, $spec, 'component-subschema-' . $key, $first, $second),
        );
    }

    public static function componentSubschemaCases(): iterable
    {
        $pet = ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'minLength' => 2]]];
        $inner = ['type' => 'object', 'properties' => ['z' => ['type' => 'string', 'minLength' => 2]]];
        $cases = [
            'property-of-a-component' => [
                ['$ref' => '#/components/schemas/Pet/properties/code'],
                ['Pet' => $pet],
                '{"f":"ab"}',
                '{"f":"a"}',
                ['valid' => true, 'invalid' => false],
            ],
            'defs-of-a-component' => [
                ['$ref' => '#/components/schemas/Holder/$defs/level'],
                ['Holder' => ['type' => 'object', '$defs' => ['level' => ['type' => 'integer', 'minimum' => 1]]]],
                '{"f":2}',
                '{"f":0}',
                ['valid' => true, 'invalid' => false],
            ],
            'boolean-false-component' => [
                ['$ref' => '#/components/schemas/Nothing'],
                ['Nothing' => false],
                '{"f":1}',
                '{"f":{}}',
                ['valid' => false, 'invalid' => false],
            ],
            'boolean-true-component' => [
                ['$ref' => '#/components/schemas/Anything'],
                ['Anything' => true],
                '{"f":[1,"a",null]}',
                '{"f":"text"}',
                ['valid' => true, 'invalid' => true],
            ],
            // The name the path spells, `WidgetPropertiesInner`, is a class the document already has
            // under another spelling: the pointer takes the next free name instead of stopping.
            'name-taken-under-another-spelling' => [
                ['$ref' => '#/components/schemas/Widget/properties/inner'],
                [
                    'widgetPropertiesInner' => ['type' => 'object', 'properties' => ['q' => ['type' => 'integer']]],
                    'Widget' => ['type' => 'object', 'properties' => ['inner' => $inner]],
                ],
                '{"f":{"z":"ab"}}',
                '{"f":{"z":"a"}}',
                ['valid' => true, 'invalid' => false],
            ],
            'name-taken-in-the-same-spelling' => [
                ['$ref' => '#/components/schemas/Widget/properties/inner'],
                [
                    'WidgetPropertiesInner' => ['type' => 'object', 'properties' => ['q' => ['type' => 'integer']]],
                    'Widget' => ['type' => 'object', 'properties' => ['inner' => $inner]],
                ],
                '{"f":{"z":"ab"}}',
                '{"f":{"z":"a"}}',
                ['valid' => true, 'invalid' => false],
            ],
            // Twenty-five levels down a chain: the name the path spells is past what a file system
            // accepts as a file name, so it is shortened.
            'deep-pointer' => [
                ['$ref' => '#/components/schemas/Deep/' . implode('/', array_fill(0, 25, 'properties/a'))],
                ['Deep' => self::propertyChain(28)],
                '{"f":{"a":{"a":{"a":"ab"}}}}',
                '{"f":{"a":{"a":{"a":"a"}}}}',
                ['valid' => true, 'invalid' => false],
            ],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => [$property, $extra, $first, $second, $expected]) {
                yield $mode->value . ' ' . $key => [$mode, $key, $property, $extra, $first, $second, $expected];
            }
        }
    }

    /**
     * Pointers at every level of one chain: each pointed schema becomes one class, and the classes a
     * pointed subtree contains are written once — a schema below another pointer is that pointer's
     * class, not a fresh copy of it. Copied per pointer, ten pointers into a ten-level chain wrote
     * sixty-odd classes; every file name stays short enough to be written.
     */
    public function testPointersIntoOneChainShareTheirClasses(): void
    {
        $depth = 10;
        $properties = [];
        for ($level = 1; $level <= $depth; $level++) {
            $properties['p' . $level] = ['$ref' => '#/components/schemas/Deep/' . implode('/', array_fill(0, $level, 'properties/a'))];
        }
        $spec = self::probeSpec(['type' => 'string']);
        $spec['components']['schemas']['Probe']['properties'] += $properties;
        $spec['components']['schemas']['Deep'] = self::propertyChain($depth + 1);

        $namespace = $this->namespaceFor(GenerationMode::Runtime, 'pointer-chain');
        $this->generate($spec, $namespace, 'runtime');
        $files = glob($this->outputDirectory . '/' . $namespace . '/*.php') ?: [];

        // Deep with its eleven levels, one class per pointer, Probe — and nothing duplicated.
        $this->assertLessThanOrEqual(2 * ($depth + 1) + 1, count($files));
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict(
                GenerationMode::Runtime,
                $spec,
                'pointer-chain-verdict',
                '{"f":"x","p9":{"a":{"a":"ab"}}}',
                '{"f":"x","p9":{"a":{"a":"a"}}}',
            ),
        );
    }

    /**
     * An object whose `a` holds an object whose `a` … `$levels` deep, ending in a string of at least
     * two characters.
     *
     * @return array<string, mixed>
     */
    private static function propertyChain(int $levels): array
    {
        $schema = ['type' => 'string', 'minLength' => 2];
        for ($level = 0; $level < $levels; $level++) {
            $schema = ['type' => 'object', 'properties' => ['a' => $schema]];
        }

        return $schema;
    }

    /**
     * A component that only names another — beside a `$comment`, an `$id`, its own `$defs` — is that
     * other schema. Read as more than an alias it became an empty class that validated nothing (and
     * yii3 died calling a method that class did not have): `{"bar": 1}` passed a `type: string`.
     */
    #[DataProvider('everyMode')]
    public function testAnAliasBesideResourceKeywordsIsTheSchemaItNames(GenerationMode $mode): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Entry']);
        $spec['components']['schemas']['Entry'] = ['$comment' => 'the entry point', '$ref' => '#/components/schemas/Inner'];
        $spec['components']['schemas']['Inner'] = ['type' => 'object', 'properties' => ['bar' => ['type' => 'string']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'alias-beside-resource-keywords', '{"f":{"bar":"a"}}', '{"f":{"bar":1}}'),
        );
    }

    /**
     * "A scalar alias, or null" in the OpenAPI 3.1 spelling — `anyOf` / `oneOf` of the `$ref` and
     * `{type: null}`, inline or as a component of its own — is the alias, nullable. Inline, the property
     * was typed by the alias's class, which is never written; as a component it became an empty union
     * interface, and the runtime refused every string with `Unsupported type`, laravel could not hydrate
     * it and laravel-data died on the constructor. The alias's own keywords still apply.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('nullableScalarAliasCases')]
    public function testANullableScalarAliasUnionIsTheAliasOrNull(GenerationMode $mode, string $key, array $property): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Code'] = ['type' => 'string', 'minLength' => 2];
        $spec['components']['schemas']['CodeOrNull'] = ['anyOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/Code']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'nullable-alias-' . $key, '{"f":"ab"}', '{"f":"a"}'),
        );
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'nullable-alias-null-' . $key, '{"f":null}', '{"f":5}'),
        );
    }

    public static function nullableScalarAliasCases(): iterable
    {
        $cases = [
            'component' => ['$ref' => '#/components/schemas/CodeOrNull'],
            'inline-anyof' => ['anyOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/Code']]],
            'inline-oneof' => ['oneOf' => [['$ref' => '#/components/schemas/Code'], ['type' => 'null']]],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => $property) {
                yield $mode->value . ' ' . $key => [$mode, $key, $property];
            }
        }
    }

    /**
     * A scalar alias named where a value sits — a branch of a union beside other branches, a branch of
     * an `allOf`, the value of a map — is the schema it names. The reference typed the property by the
     * alias's class, which is never written: the runtime refused every value with `Unsupported type` or
     * `unknown type`, laravel died on the constructor or on `Class not found` before any payload was
     * read, and the alias's own keywords went unchecked where the value was accepted.
     *
     * @param array<string, mixed> $property
     * @param list<array{string, string, bool}> $pairs valid payload, invalid payload, whether the invalid one is a numeric string
     */
    #[DataProvider('scalarAliasPositionCases')]
    public function testAScalarAliasIsTheSchemaItNamesWhereverAValueSits(GenerationMode $mode, string $key, array $property, array $pairs): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Code'] = ['type' => 'string', 'minLength' => 2, 'maxLength' => 3];
        $spec['components']['schemas']['Count'] = ['type' => 'integer', 'minimum' => 0];
        $spec['components']['schemas']['Any'] = true;
        $spec['components']['schemas']['Never'] = false;
        $spec['components']['schemas']['CodeOrCount'] = ['anyOf' => [['$ref' => '#/components/schemas/Code'], ['$ref' => '#/components/schemas/Count']]];

        foreach ($pairs as $index => [$valid, $invalid, $numericString]) {
            // A numeric string for an integer is the documented leniency of these two modes
            // (README.support-matrix.md, `testLenientScalarsArePinnedPerMode`), not this defect.
            if ($numericString && in_array($mode, [GenerationMode::LaravelData, GenerationMode::Yii3], true)) {
                continue;
            }
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $spec, 'alias-position-' . $key . '-' . $index, $valid, $invalid),
                message: $valid . ' / ' . $invalid,
            );
        }
    }

    public static function scalarAliasPositionCases(): iterable
    {
        $code = ['$ref' => '#/components/schemas/Code'];
        $count = ['$ref' => '#/components/schemas/Count'];
        $cases = [
            'anyof-two-aliases' => [
                ['anyOf' => [$code, $count]],
                [['{"f":"ab"}', '{"f":"a"}', false], ['{"f":3}', '{"f":-1}', false], ['{"f":3}', '{"f":true}', false], ['{"f":"ab"}', '{"f":1.5}', false]],
            ],
            'anyof-alias-null-scalar' => [
                ['anyOf' => [$code, ['type' => 'null'], ['type' => 'integer']]],
                [['{"f":"ab"}', '{"f":"a"}', false], ['{"f":null}', '{"f":1.5}', false], ['{"f":-4}', '{"f":true}', false]],
            ],
            'oneof-alias-inline-scalar' => [
                ['oneOf' => [$count, ['type' => 'string']]],
                [['{"f":3}', '{"f":-1}', false], ['{"f":"x"}', '{"f":true}', false]],
            ],
            'union-component' => [
                ['$ref' => '#/components/schemas/CodeOrCount'],
                [['{"f":"ab"}', '{"f":"a"}', false], ['{"f":3}', '{"f":-1}', false]],
            ],
            'list-of-union-component' => [
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CodeOrCount']],
                [['{"f":["ab",3]}', '{"f":["a"]}', false], ['{"f":[]}', '{"f":[-1]}', false]],
            ],
            'map-of-union-component' => [
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/CodeOrCount']],
                [['{"f":{"k":"ab","j":3}}', '{"f":{"k":-1}}', false]],
            ],
            'allof-alias-and-bound' => [
                ['allOf' => [$count, ['maximum' => 10]]],
                [['{"f":5}', '{"f":true}', false], ['{"f":5}', '{"f":"5"}', true], ['{"f":0}', '{"f":11}', false], ['{"f":10}', '{"f":-1}', false]],
            ],
            'map-of-string-alias' => [
                ['type' => 'object', 'additionalProperties' => $code],
                [['{"f":{"k":"ab"}}', '{"f":{"k":"abcd"}}', false], ['{"f":{}}', '{"f":{"k":5}}', false]],
            ],
            'map-of-integer-alias' => [
                ['type' => 'object', 'additionalProperties' => $count],
                [['{"f":{"k":1,"j":0}}', '{"f":{"k":-1}}', false], ['{"f":{}}', '{"f":{"k":"1"}}', true]],
            ],
            'map-with-key-constraint' => [
                ['type' => 'object', 'propertyNames' => ['maxLength' => 3], 'additionalProperties' => $code],
                [['{"f":{"k":"ab"}}', '{"f":{"k":"abcd"}}', false], ['{"f":{"k":"ab"}}', '{"f":{"long":"ab"}}', false]],
            ],
            'map-of-true' => [
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Any']],
                [['{"f":{"x":{},"y":[],"z":1}}', '{"f":5}', false]],
            ],
            'map-of-false' => [
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Never']],
                [['{"f":{}}', '{"f":{"x":1}}', false]],
            ],
            // Neighbours that already worked and must keep working.
            'list-of-alias' => [
                ['type' => 'array', 'items' => $count],
                [['{"f":[0,4]}', '{"f":[-1]}', false]],
            ],
            'alias-or-null' => [
                ['anyOf' => [$code, ['type' => 'null']]],
                [['{"f":"ab"}', '{"f":"a"}', false], ['{"f":null}', '{"f":5}', false]],
            ],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => [$property, $pairs]) {
                yield $mode->value . ' ' . $key => [$mode, $key, $property, $pairs];
            }
        }
    }

    /**
     * A list whose `items` name a scalar alias (`Num: {type: integer}`) checks each item's type. The
     * reference was left to the PHP type, and Symfony 7's serializer does not type-check inside a
     * collection: `["a"]` passed. Symfony 8 refuses it on its own, which is how it surfaced — the same
     * suite case answered differently on the two.
     */
    #[DataProvider('everyMode')]
    public function testAListOfAScalarAliasChecksItsItems(GenerationMode $mode): void
    {
        $spec = self::probeSpec(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Num']]);
        $spec['components']['schemas']['Num'] = ['type' => 'integer', 'minimum' => 0];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'list-of-scalar-alias', '{"f":[1,2]}', '{"f":["a"]}'),
        );
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'list-of-scalar-alias-bound', '{"f":[0]}', '{"f":[-1]}'),
        );
    }

    /**
     * An alias of a value that refers to another alias of a value — `F: {$ref: Pair, maxItems: 2}`,
     * `Pair: {prefixItems: […]}` — is a value too. One level was inlined and the inner `$ref` left in
     * place: the property was typed by the class of `Pair`, a class that is never written, and the
     * DTO could not be loaded. Both schemas apply, the tuple and the bound.
     */
    #[DataProvider('everyMode')]
    public function testAnAliasOfAnAliasIsAValue(GenerationMode $mode): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Bounded']);
        $spec['components']['schemas']['Bounded'] = ['$ref' => '#/components/schemas/Pair', 'maxItems' => 2];
        $spec['components']['schemas']['Pair'] = ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'alias-of-alias', '{"f":["a",1]}', '{"f":["a","b"]}'),
        );
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'alias-of-alias-bound', '{"f":"any value"}', '{"f":["a",1,true]}'),
        );
    }

    /**
     * A `default` is not validated: with the key absent, `{maximum: 3, default: 5}` holds nothing to
     * check. Symfony's attribute checked the property, which held the default, and refused `{}`. A
     * value SENT is checked as ever, the default's own value included.
     */
    #[DataProvider('everyMode')]
    public function testADefaultIsNotValidatedWhenTheKeyIsAbsent(GenerationMode $mode): void
    {
        $spec = self::probeSpec(['type' => 'number', 'maximum' => 3, 'default' => 5]);
        $spec['components']['schemas']['Probe']['required'] = [];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'unvalidated-default', '{}', '{"f":5}'),
        );
    }

    /**
     * JSON has one number type: `1.0` is the integer 1, so it matches `type: integer` and an integer
     * `enum`, typed or not. PHP decodes it to a float, which never strict-equals the int a backed enum
     * case holds: the runtime and yii3 refused it for an enum, and laravel-data let `1.5` through as
     * the case 1. A real fraction is still no integer.
     *
     * Symfony's serializer reads a number by the PHP type and refuses a float for an `int` and an
     * int-backed enum alike — a documented limitation (README.support-matrix), pinned here so a change
     * in Symfony is noticed: it refuses the valid `1.0`, never accepts the invalid `1.5`.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('integralFloatCases')]
    public function testAnIntegralFloatIsTheInteger(GenerationMode $mode, string $key, array $property): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Level'] = ['type' => 'integer', 'enum' => [1, 2, 3]];

        $this->assertSame(
            expected: ['valid' => $mode !== GenerationMode::Symfony, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'integral-float-' . $key, '{"f":1.0}', '{"f":1.5}'),
        );
    }

    public static function integralFloatCases(): iterable
    {
        $cases = [
            'integer' => ['type' => 'integer'],
            'typed-enum' => ['type' => 'integer', 'enum' => [1, 2, 3]],
            'untyped-enum' => ['enum' => [1, 2, 3]],
            'enum-component' => ['$ref' => '#/components/schemas/Level'],
        ];
        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => $property) {
                yield $mode->value . ' ' . $key => [$mode, $key, $property];
            }
        }
    }

    /**
     * `null` for an object the schema does not let be null is refused, sent or not required alike.
     * Symfony's serializer denormalized the `null` into an empty object — `new Inner()` — and the
     * setter took it, so `{"f": null}` passed wherever `Inner` required nothing; it failed only by
     * accident, on the constructor, where `Inner` did. Where the schema admits null, null passes.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('nullForAnObjectCases')]
    public function testNullForAnObjectIsJudgedByTheSchema(GenerationMode $mode, string $key, string $version, array $property, bool $required, bool $nullAccepted): void
    {
        $spec = self::probeSpec($property);
        $spec['openapi'] = $version;
        $spec['components']['schemas']['Probe']['required'] = $required ? ['f'] : [];
        $spec['components']['schemas']['Inner'] = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => $nullAccepted],
            actual: $this->verdict($mode, $spec, 'null-object-' . $key, '{"f":{"a":1}}', '{"f":null}'),
        );
    }

    public static function nullForAnObjectCases(): iterable
    {
        $inline = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]];
        $reference = ['$ref' => '#/components/schemas/Inner'];
        $cases = [];
        foreach (['30' => '3.0.3', '31' => '3.1.0'] as $short => $version) {
            foreach (['optional' => false, 'required' => true] as $presence => $required) {
                $cases['inline-' . $presence . '-' . $short] = [$version, $inline, $required, false];
                $cases['ref-' . $presence . '-' . $short] = [$version, $reference, $required, false];
            }
        }
        // The neighbours: the same object where null IS admitted.
        $cases['inline-nullable-30'] = ['3.0.3', $inline + ['nullable' => true], false, true];
        $cases['ref-nullable-allof-30'] = ['3.0.3', ['nullable' => true, 'allOf' => [$reference]], false, true];
        $cases['inline-type-null-31'] = ['3.1.0', ['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'integer']]], true, true];
        $cases['ref-oneof-null-31'] = ['3.1.0', ['oneOf' => [$reference, ['type' => 'null']]], true, true];

        foreach (GenerationMode::cases() as $mode) {
            foreach ($cases as $key => [$version, $property, $required, $nullAccepted]) {
                yield $mode->value . ' ' . $key => [$mode, $key, $version, $property, $required, $nullAccepted];
            }
        }
    }

    /**
     * A reference by `$anchor`, by an `$id` (relative to the resource it sits in, absolute, or a URN),
     * a JSON pointer INTO an embedded `$id` resource and a `$dynamicRef` all name a schema the
     * document declares, so each must constrain the value like a `#/components/schemas/…` pointer
     * does. They used to resolve to nothing and the constraint vanished: every mode accepted the
     * invalid payload. The plain pointers are pinned beside them — the same resolution path handles
     * both now.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $extraComponents
     */
    #[DataProvider('schemaResourceReferenceCases')]
    public function testSchemaResourceReferencesConstrainInEveryMode(
        string $key,
        array $propertySchema,
        array $extraComponents,
        string $validJson,
        string $invalidJson,
    ): void {
        $spec = self::probeSpec($propertySchema);
        foreach ($extraComponents as $name => $component) {
            $spec['components']['schemas'][$name] = $component;
        }

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string, 4: string}>
     */
    public static function schemaResourceReferenceCases(): iterable
    {
        // Neighbours: the pointers that always resolved, through the same code now.
        yield 'pointer to a component' => [
            'ref-pointer-component',
            ['$ref' => '#/components/schemas/Shade'],
            ['Shade' => ['type' => 'string', 'maxLength' => 3]],
            '{"f":"red"}',
            '{"f":"crimson"}',
        ];
        yield 'pointer to an object component' => [
            'ref-pointer-object',
            ['$ref' => '#/components/schemas/Gadget'],
            ['Gadget' => [
                'type' => 'object',
                'required' => ['size'],
                'properties' => ['size' => ['type' => 'integer', 'maximum' => 9]],
            ]],
            '{"f":{"size":4}}',
            '{"f":{"size":40}}',
        ];

        yield 'an $anchor in a local $defs' => [
            'ref-anchor-defs',
            ['$ref' => '#count', '$defs' => ['count' => ['$anchor' => 'count', 'type' => 'integer']]],
            [],
            '{"f":3}',
            '{"f":"three"}',
        ];
        yield 'an $anchor on a component' => [
            'ref-anchor-component',
            ['$ref' => '#shade'],
            ['Shade' => ['$anchor' => 'shade', 'type' => 'string', 'maxLength' => 3]],
            '{"f":"red"}',
            '{"f":"crimson"}',
        ];
        yield 'an $anchor inside an object component' => [
            'ref-anchor-object',
            ['$ref' => '#widget'],
            ['Widget' => [
                '$anchor' => 'widget',
                'type' => 'object',
                'required' => ['size'],
                'properties' => ['size' => ['type' => 'integer', 'maximum' => 9]],
            ]],
            '{"f":{"size":4}}',
            '{"f":{"size":40}}',
        ];
        yield 'a relative $id resolved against the embedding resource' => [
            'ref-relative-id',
            [
                '$id' => 'https://example.com/shapes/root',
                '$ref' => 'circle',
                '$defs' => ['circle' => ['$id' => 'circle', 'type' => 'integer', 'minimum' => 5]],
            ],
            [],
            '{"f":7}',
            '{"f":2}',
        ];
        yield 'a pointer into an embedded resource' => [
            'ref-pointer-in-resource',
            [
                '$id' => 'https://example.com/shapes/root',
                '$ref' => 'palette#/$defs/hue',
                '$defs' => ['palette' => ['$id' => 'palette', '$defs' => ['hue' => ['type' => 'string', 'minLength' => 2]]]],
            ],
            [],
            '{"f":"teal"}',
            '{"f":"t"}',
        ];
        yield 'an absolute $id on a component' => [
            'ref-absolute-id',
            ['$ref' => 'https://example.com/shapes/square'],
            ['Square' => ['$id' => 'https://example.com/shapes/square', 'type' => 'number', 'maximum' => 4]],
            '{"f":3}',
            '{"f":5}',
        ];
        yield 'a URN $id and a pointer through it' => [
            'ref-urn-pointer',
            [
                '$id' => 'urn:uuid:5b3f7a1e-0000-4000-8000-00000000c0de',
                'type' => 'object',
                'properties' => ['alpha' => ['$ref' => 'urn:uuid:5b3f7a1e-0000-4000-8000-00000000c0de#/$defs/beta']],
                '$defs' => ['beta' => ['type' => 'string']],
            ],
            [],
            '{"f":{"alpha":"x"}}',
            '{"f":{"alpha":1}}',
        ];
        yield 'a URN $id and an $anchor through it' => [
            'ref-urn-anchor',
            [
                '$id' => 'urn:example:animals',
                'type' => 'object',
                'properties' => ['cat' => ['$ref' => 'urn:example:animals#whiskers']],
                '$defs' => ['whiskers' => ['$anchor' => 'whiskers', 'type' => 'integer']],
            ],
            [],
            '{"f":{"cat":12}}',
            '{"f":{"cat":"twelve"}}',
        ];
        yield 'a recursive pair of resources' => [
            'ref-recursive-resources',
            ['$ref' => 'https://example.com/tree'],
            ['Tree' => [
                '$id' => 'https://example.com/tree',
                'type' => 'object',
                'required' => ['nodes'],
                'properties' => ['nodes' => ['type' => 'array', 'items' => ['$ref' => 'node']]],
                '$defs' => ['node' => [
                    '$id' => 'https://example.com/node',
                    'type' => 'object',
                    'required' => ['value'],
                    'properties' => ['value' => ['type' => 'number'], 'subtree' => ['$ref' => 'tree']],
                ]],
            ]],
            '{"f":{"nodes":[{"value":1,"subtree":{"nodes":[{"value":2}]}}]}}',
            '{"f":{"nodes":[{"value":1,"subtree":{"nodes":[{"value":"two"}]}}]}}',
        ];
        yield 'a $dynamicRef bound by the outer resource (generic list)' => [
            'dynamic-ref-generic-list',
            [
                '$id' => 'https://example.com/colors',
                '$ref' => 'list',
                '$defs' => [
                    'color' => ['$dynamicAnchor' => 'item', 'type' => 'string'],
                    'list' => [
                        '$id' => 'list',
                        'type' => 'array',
                        'items' => ['$dynamicRef' => '#item'],
                        '$defs' => ['default' => ['$dynamicAnchor' => 'item']],
                    ],
                ],
            ],
            [],
            '{"f":["red","blue"]}',
            '{"f":["red",7]}',
        ];
        yield 'a $dynamicRef with no outer anchor stays on its own' => [
            'dynamic-ref-own-anchor',
            [
                '$id' => 'https://example.com/sizes',
                'type' => 'array',
                'items' => ['$dynamicRef' => '#size'],
                '$defs' => ['size' => ['$dynamicAnchor' => 'size', 'type' => 'integer']],
            ],
            [],
            '{"f":[1,2]}',
            '{"f":["big"]}',
        ];
    }

    /**
     * `unevaluatedProperties` and `unevaluatedItems` read what every in-place applicator beside them
     * evaluated: `properties`, `patternProperties`, `additionalProperties`, `prefixItems`, `items` and
     * `contains` of the schema itself, of every `allOf` branch, of each `anyOf`/`oneOf` branch that
     * matched, of the `if`/`then`/`else` arm that applied, of a triggered `dependentSchemas` entry and
     * of a `$ref` target — and nothing from a branch that failed. Runtime, laravel and laravel-data
     * refused valid payloads here: the keys an `allOf` branch, a `$ref` or an `additionalProperties:
     * true` evaluated came out unevaluated, because the class closed itself on its own declared names
     * or the reference was gone from the constraints.
     *
     * Symfony and yii3 hydrate first, and a key no class declares does not survive that — the closed
     * object limitation README.support-matrix documents. Only there do they accept the invalid payload.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $extraComponents
     */
    #[DataProvider('unevaluatedAnnotationCases')]
    public function testUnevaluatedKeywordsSeeWhatInPlaceApplicatorsEvaluated(
        string $key,
        array $schema,
        array $extraComponents,
        string $validJson,
        string $invalidJson,
        bool $atRoot,
        bool $closedObjectDiverges,
    ): void {
        if ($atRoot) {
            $spec = self::probeSpec(['type' => 'string']);
            $spec['components']['schemas']['Probe'] = $schema;
        } else {
            $spec = self::probeSpec($schema);
        }
        foreach ($extraComponents as $name => $component) {
            $spec['components']['schemas'][$name] = $component;
        }

        $diverges = [];
        if ($closedObjectDiverges) {
            $reason = 'documented: a closed object is not enforceable once hydrated (README.support-matrix) — '
                . 'the undeclared key is dropped before any generated check runs';
            $diverges = [
                ...self::diverges(mode: GenerationMode::Symfony, expected: ['valid' => true, 'invalid' => true], reason: $reason),
                ...self::diverges(mode: GenerationMode::Yii3, expected: ['valid' => true, 'invalid' => true], reason: $reason),
            ];
        }

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string, 4: string, 5: bool, 6: bool}>
     */
    public static function unevaluatedAnnotationCases(): iterable
    {
        $colour = ['type' => 'string'];

        // Properties evaluated in place.
        yield 'properties beside an allOf branch' => [
            'uneval-allof-properties',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['properties' => ['beta' => $colour]]], 'unevaluatedProperties' => false],
            [],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":"red","beta":"blue","gamma":"green"}}',
            false,
            true,
        ];
        yield 'patternProperties in an allOf branch' => [
            'uneval-allof-pattern',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['patternProperties' => ['^beta' => $colour]]], 'unevaluatedProperties' => false],
            [],
            '{"f":{"alpha":"red","betaOne":"blue"}}',
            '{"f":{"alpha":"red","betaOne":"blue","gamma":"green"}}',
            false,
            true,
        ];
        yield 'additionalProperties true beside it' => [
            'uneval-additional-true',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'additionalProperties' => true, 'unevaluatedProperties' => false],
            [],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":1}}',
            false,
            false,
        ];
        yield 'additionalProperties true in an allOf branch' => [
            'uneval-allof-additional',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['additionalProperties' => true]], 'unevaluatedProperties' => false],
            [],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":1}}',
            false,
            false,
        ];
        yield 'unevaluatedProperties true in an allOf branch' => [
            'uneval-allof-inner-true',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['unevaluatedProperties' => true]], 'unevaluatedProperties' => false],
            [],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":1}}',
            false,
            false,
        ];
        yield 'the matching anyOf branch only' => [
            'uneval-anyof',
            [
                'type' => 'object',
                'properties' => ['alpha' => $colour],
                'anyOf' => [
                    ['properties' => ['beta' => ['const' => 'blue']], 'required' => ['beta']],
                    ['properties' => ['gamma' => ['const' => 'green']], 'required' => ['gamma']],
                ],
                'unevaluatedProperties' => false,
            ],
            [],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":"red","beta":"blue","gamma":"grey"}}',
            false,
            false,
        ];
        yield 'the target of a $ref beside it' => [
            'uneval-ref',
            ['$ref' => '#/components/schemas/Swatch', 'properties' => ['alpha' => $colour], 'unevaluatedProperties' => false],
            ['Swatch' => ['type' => 'object', 'properties' => ['beta' => $colour]]],
            '{"f":{"alpha":"red","beta":"blue"}}',
            '{"f":{"alpha":"red","beta":"blue","gamma":"green"}}',
            false,
            true,
        ];

        // Items evaluated in place.
        yield 'items beside it' => [
            'uneval-items',
            ['type' => 'array', 'items' => $colour, 'unevaluatedItems' => false],
            [],
            '{"f":["red","blue"]}',
            '{"f":["red",1]}',
            false,
            false,
        ];
        yield 'prefixItems in an allOf branch' => [
            'uneval-allof-prefix',
            ['type' => 'array', 'prefixItems' => [$colour], 'allOf' => [['prefixItems' => [true, ['type' => 'number']]]], 'unevaluatedItems' => false],
            [],
            '{"f":["red",1]}',
            '{"f":["red",1,true]}',
            false,
            false,
        ];
        yield 'contains in allOf branches' => [
            'uneval-allof-contains',
            ['type' => 'array', 'allOf' => [['contains' => ['multipleOf' => 2]], ['contains' => ['multipleOf' => 3]]], 'unevaluatedItems' => ['multipleOf' => 5]],
            [],
            '{"f":[2,3,4,5,6]}',
            '{"f":[2,3,4,7,8]}',
            false,
            false,
        ];
        yield 'prefixItems of the matching anyOf branch' => [
            'uneval-anyof-prefix',
            [
                'type' => 'array',
                'prefixItems' => [['const' => 'red']],
                'anyOf' => [['prefixItems' => [true, ['const' => 'blue']]], ['prefixItems' => [true, true, ['const' => 'green']]]],
                'unevaluatedItems' => false,
            ],
            [],
            '{"f":["red","blue"]}',
            '{"f":["red","blue","grey"]}',
            false,
            false,
        ];
        yield 'prefixItems of a $ref target' => [
            'uneval-ref-prefix',
            ['$ref' => '#/components/schemas/Pair', 'prefixItems' => [$colour], 'unevaluatedItems' => false],
            ['Pair' => ['type' => 'array', 'prefixItems' => [true, $colour]]],
            '{"f":["red","blue"]}',
            '{"f":["red","blue","green"]}',
            false,
            false,
        ];

        // The same at the top of the body, where runtime and laravel read the raw request.
        yield 'root: properties beside an allOf branch' => [
            'uneval-root-allof-properties',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['properties' => ['beta' => $colour]]], 'unevaluatedProperties' => false],
            [],
            '{"alpha":"red","beta":"blue"}',
            '{"alpha":"red","beta":"blue","gamma":"green"}',
            true,
            true,
        ];
        yield 'root: additionalProperties true in an allOf branch' => [
            'uneval-root-allof-additional',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['additionalProperties' => true]], 'unevaluatedProperties' => false],
            [],
            '{"alpha":"red","beta":"blue"}',
            '{"alpha":1}',
            true,
            false,
        ];
        yield 'root: unevaluatedProperties true in an allOf branch' => [
            'uneval-root-allof-inner-true',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['unevaluatedProperties' => true]], 'unevaluatedProperties' => false],
            [],
            '{"alpha":"red","beta":"blue"}',
            '{"alpha":1}',
            true,
            false,
        ];
        yield 'root: the then arm that applied' => [
            'uneval-root-then',
            [
                'type' => 'object',
                'properties' => ['alpha' => $colour],
                'if' => ['required' => ['alpha']],
                'then' => ['properties' => ['beta' => $colour]],
                'unevaluatedProperties' => false,
            ],
            [],
            '{"alpha":"red","beta":"blue"}',
            '{"beta":"blue"}',
            true,
            true,
        ];
        yield 'root: a branch sees only its own evaluation' => [
            'uneval-root-cousin',
            ['type' => 'object', 'properties' => ['alpha' => $colour], 'allOf' => [['unevaluatedProperties' => false]]],
            [],
            '{}',
            '{"alpha":"red"}',
            true,
            false,
        ];
    }

    /**
     * An `enum` beside a scalar keyword (`minimum`, `minLength`, `pattern`): both apply, the keyword
     * to the member that was sent. yii3 put the keyword's rule on the property the hydrator had already
     * turned into the generated backed enum, so `GreaterThanOrEqual(0)` judged the enum OBJECT and
     * refused every member, the valid ones included. The keyword is still enforced: a member it rules
     * out is refused.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('enumBesideAScalarKeywordCases')]
    public function testAnEnumBesideAScalarKeywordKeepsItsValidMembers(
        GenerationMode $mode,
        string $key,
        array $schema,
        bool $required,
        string $validJson,
        string $invalidJson,
    ): void {
        $spec = self::probeSpec($schema);
        if (!$required) {
            $spec['components']['schemas']['Probe'] = ['type' => 'object', 'properties' => ['f' => $schema]];
        }
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'enum-beside-keyword-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function enumBesideAScalarKeywordCases(): iterable
    {
        $cases = [
            'minimum rules a member out' => [['type' => 'integer', 'minimum' => 0, 'enum' => [-1, 2]], true, '{"f":2}', '{"f":-1}'],
            'minimum beside members it allows' => [['type' => 'integer', 'minimum' => 0, 'enum' => [1, 2]], true, '{"f":1}', '{"f":3}'],
            'minLength rules a member out' => [['type' => 'string', 'minLength' => 2, 'enum' => ['a', 'cd']], true, '{"f":"cd"}', '{"f":"a"}'],
            'pattern rules a member out' => [['type' => 'string', 'pattern' => '^c', 'enum' => ['ab', 'cd']], true, '{"f":"cd"}', '{"f":"ab"}'],
            'optional minimum' => [['type' => 'integer', 'minimum' => 0, 'enum' => [-1, 2]], false, '{"f":2}', '{"f":-1}'],
            'optional maximum' => [['type' => 'number', 'maximum' => 5, 'enum' => [1, 7]], false, '{}', '{"f":7}'],
            'list items' => [['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2, 'enum' => ['a', 'cd']]], true, '{"f":["cd"]}', '{"f":["a"]}'],
        ];
        foreach ($cases as $key => [$schema, $required, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $schema, $required, $valid, $invalid];
            }
        }
    }

    /**
     * `additionalProperties: false` on a class one of whose DECLARED properties is a nested class. yii3
     * dropped such a property from the interpreter's `properties` — its own class validates it — and
     * with it the name, so the closed object refused the key as "additional property" for every value
     * it could hold. The value is still checked by its own class.
     */
    #[DataProvider('closedClassWithANestedPropertyCases')]
    public function testADeclaredNestedPropertyIsNotAnAdditionalOne(
        GenerationMode $mode,
        string $key,
        bool $onlyNested,
        string $validJson,
        string $invalidJson,
    ): void {
        $schemas = $onlyNested
            ? [
                'Flags' => ['type' => 'object', 'properties' => ['a' => ['type' => 'boolean']]],
                'Probe' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['flags' => ['$ref' => '#/components/schemas/Flags']],
                ],
            ]
            : [
                'Leaf' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]],
                'Probe' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'leaf' => ['$ref' => '#/components/schemas/Leaf'],
                        'parent' => ['$ref' => '#/components/schemas/Probe'],
                        'leaves' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Leaf']],
                        'tags' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    ],
                ],
            ];
        $spec = ['openapi' => '3.1.0', 'info' => ['title' => 'T', 'version' => '1.0.0'], 'components' => ['schemas' => $schemas]];
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'closed-nested-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function closedClassWithANestedPropertyCases(): iterable
    {
        $cases = [
            'a referenced class' => [false, '{"name":"a","leaf":{"v":"x"}}', '{"name":"a","leaf":{"v":1}}'],
            'the class itself' => [false, '{"name":"a","parent":{"name":"b"}}', '{"name":"a","parent":{"name":1}}'],
            'a nested class two levels down' => [false, '{"name":"a","parent":{"name":"b","leaf":{"v":"x"}}}', '{"name":"a","parent":{"name":"b","leaf":{"v":1}}}'],
            'a list of classes' => [false, '{"name":"a","leaves":[{"v":"x"}]}', '{"name":"a","leaves":[{"v":1}]}'],
            'a map' => [false, '{"name":"a","tags":{"k":1}}', '{"name":"a","tags":{"k":"x"}}'],
            'the only property' => [true, '{"flags":{"a":true}}', '{"flags":{"a":"x"}}'],
            'the only property, empty' => [true, '{"flags":{}}', '{"flags":{"a":"x"}}'],
        ];
        foreach ($cases as $key => [$onlyNested, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $onlyNested, $valid, $invalid];
            }
        }
    }

    /**
     * An optional object property with a schema `default` PHP cannot spell as a constant. laravel mode
     * read "has a default" and declared it non-nullable with nothing to fall back on, so a payload that
     * left the key out died in `fromValidated()` with a TypeError — every one of them.
     */
    #[DataProvider('objectDefaultCases')]
    public function testAnAbsentObjectWithADefaultIsAccepted(GenerationMode $mode, string $key, string $validJson, string $invalidJson): void
    {
        $inner = ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]];
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Inner' => $inner,
                    'Probe' => [
                        'type' => 'object',
                        'properties' => [
                            'e' => [...$inner, 'default' => ['x' => 1]],
                            'g' => ['$ref' => '#/components/schemas/Inner', 'default' => ['x' => 2]],
                            'n' => ['type' => 'integer', 'default' => 4],
                        ],
                    ],
                ],
            ],
        ];
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict($mode, $spec, 'object-default-' . $key, $validJson, $invalidJson),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function objectDefaultCases(): iterable
    {
        $cases = [
            'every key absent' => ['{}', '{"e":{"x":"s"}}'],
            'one object sent' => ['{"e":{"x":2}}', '{"g":{"x":"s"}}'],
            'the referenced one sent' => ['{"g":{"x":2}}', '{"n":"s"}'],
        ];
        foreach ($cases as $key => [$valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $valid, $invalid];
            }
        }
    }

    /**
     * The `type: object` wire check (support matrix "a JSON array for a `type: object` property") at
     * every depth of a self-referring schema, in the modes that make it. The Laravel modes stopped at
     * the first level that refers back: `{"meta": [1]}` was refused and `{"next": {"meta": [1]}}`
     * accepted, hydrated as an empty object.
     */
    #[DataProvider('wireShapeBelowRecursionCases')]
    public function testTheWireShapeIsCheckedBelowASelfReferringLevel(
        GenerationMode $mode,
        string $key,
        bool $throughAnotherClass,
        string $validJson,
        string $invalidJson,
    ): void {
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict(
                mode: $mode,
                spec: $throughAnotherClass ? self::recursionBelowARootSpec() : self::selfReferringSpec(),
                key: 'wire-below-recursion-' . $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function wireShapeBelowRecursionCases(): iterable
    {
        $cases = [
            'top level' => [false, '{"value":1,"shape":{"x":1}}', '{"value":1,"shape":[1]}'],
            'one level down' => [false, '{"value":1,"child":{"value":1,"shape":{"x":1}}}', '{"value":1,"child":{"value":1,"shape":[1]}}'],
            'two levels down' => [false, '{"value":1,"child":{"value":1,"child":{"value":1,"shape":{}}}}', '{"value":1,"child":{"value":1,"child":{"value":1,"shape":[1]}}}'],
            'in a list item' => [false, '{"value":1,"kids":[{"value":1,"shape":{"x":1}}]}', '{"value":1,"kids":[{"value":1,"shape":[1]}]}'],
            'the self-reference itself' => [false, '{"value":1,"kids":[{"value":1,"kids":[{"value":1}]}]}', '{"value":1,"kids":[{"value":1,"kids":[[5]]}]}'],
            'below a root' => [true, '{"node":{"next":{"next":{"meta":{"a":1}}}}}', '{"node":{"next":{"next":{"meta":[1]}}}}'],
            'through a mutual reference' => [true, '{"node":{"other":{"back":{"meta":{"a":1}}}}}', '{"node":{"other":{"back":{"meta":[1]}}}}'],
        ];
        // The modes that refuse a JSON array for an object at all; the other two are the documented
        // divergence of that row, at every depth alike.
        foreach ($cases as $key => [$throughAnotherClass, $valid, $invalid]) {
            foreach ([GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData] as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $throughAnotherClass, $valid, $invalid];
            }
        }
    }

    /**
     * Below a self-referring level the Laravel modes report each problem once, under the path it is
     * about — README.laravel "a violation is reported once" and "an error sits under the field it is
     * about". The rule that checks such a level folded the inner messages into one error on the outer
     * key (`child: child.child: child.child: validation.array`), and a mistake the interpreter also saw
     * came back a second time under its own key.
     */
    #[DataProvider('laravelModes')]
    public function testASelfReferringLevelReportsEachErrorOnceAtItsPath(GenerationMode $mode): void
    {
        $cases = [
            '{"value":"x"}' => ['value' => ['validation.integer']],
            '{"value":1,"child":{"value":"x"}}' => ['child.value' => ['validation.integer']],
            '{"value":1,"child":{"value":2,"child":{"value":3,"child":"str"}}}' => ['child.child.child' => ['validation.array']],
            '{"value":1,"kids":[{"value":1,"kids":[{"value":1},{"value":"x"}]}]}' => ['kids.0.kids.1.value' => ['validation.integer']],
            '{"value":1,"child":{"value":1,"child":{"value":1,"shape":[1]}}}' => ['child.child.shape' => ['child.child.shape expects object, got array.']],
            '{"value":1,"child":{"value":1,"child":{"value":1}}}' => [],
        ];
        foreach ($cases as $json => $expected) {
            $this->assertSame(
                expected: $expected,
                actual: $this->laravelModeErrors($mode, self::selfReferringSpec(), 'recursive-error-paths', $json),
                message: $mode->value . ': ' . $json,
            );
        }
    }

    public static function laravelModes(): iterable
    {
        yield 'laravel' => [GenerationMode::Laravel];
        yield 'laravel-data' => [GenerationMode::LaravelData];
    }

    /**
     * A JSON number past the float range (`1e400`) decodes to INF. Laravel's `multiple_of` threw
     * `MathException` on it — a 500 for any client — and `numeric` took it into the DTO, where no JSON
     * encoder could write it back. Runtime, yii3 and now the Laravel modes refuse it everywhere;
     * Symfony mode still accepts it for a `type: number` property of the class itself (the serializer
     * and `DivisibleBy` both take INF) and refuses it in a list or a map, which the interpreter checks —
     * the support matrix row "a number past the float range".
     */
    #[DataProvider('overflowingNumberCases')]
    public function testANumberPastTheFloatRangeIsRefused(GenerationMode $mode, string $key, string $invalidJson, bool $refused): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'properties' => [
                            'step' => ['type' => 'number', 'multipleOf' => 0.01],
                            'count' => ['type' => 'integer', 'multipleOf' => 3],
                            'halves' => ['type' => 'array', 'items' => ['type' => 'number', 'multipleOf' => 0.5]],
                            'weights' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'multipleOf' => 0.5]],
                            'ratio' => ['type' => 'number'],
                            'limit' => ['type' => 'number', 'maximum' => 10],
                        ],
                    ],
                ],
            ],
        ];
        $this->assertSame(
            expected: ['valid' => true, 'invalid' => !$refused],
            actual: $this->verdict(
                mode: $mode,
                spec: $spec,
                key: 'overflowing-number-' . $key,
                validJson: '{"step":0.02,"count":3,"halves":[0.5],"weights":{"a":1},"ratio":1.5,"limit":-2}',
                invalidJson: $invalidJson,
            ),
            message: $mode->value . ': ' . $key,
        );
    }

    public static function overflowingNumberCases(): iterable
    {
        // The last column: whether Symfony mode refuses it.
        $cases = [
            'multipleOf number' => ['{"step":1e400}', false],
            'multipleOf integer' => ['{"count":1e400}', true],
            'multipleOf list item' => ['{"halves":[1e400]}', true],
            'multipleOf map value' => ['{"weights":{"a":1e400}}', true],
            'plain number' => ['{"ratio":1e400}', false],
            'negative beside a maximum' => ['{"limit":-1e400}', false],
        ];
        foreach ($cases as $key => [$invalid, $symfonyRefuses]) {
            foreach (GenerationMode::cases() as $mode) {
                yield $key . ' / ' . $mode->value => [$mode, $key, $invalid, $mode !== GenerationMode::Symfony || $symfonyRefuses];
            }
        }
    }

    /**
     * Probe → `child` and `kids[]` back to itself, with an object (`shape`) and a required integer on
     * every level.
     *
     * @return array<string, mixed>
     */
    private static function selfReferringSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['value'],
                        'properties' => [
                            'value' => ['type' => 'integer'],
                            'shape' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
                            'child' => ['$ref' => '#/components/schemas/Probe'],
                            'kids' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Probe']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A root that does NOT refer to itself, holding a `Node` that does — directly (`next`) and through
     * another class (`other.back`).
     *
     * @return array<string, mixed>
     */
    private static function recursionBelowARootSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'properties' => [
                            'meta' => ['type' => 'object'],
                            'next' => ['$ref' => '#/components/schemas/Node'],
                            'other' => ['$ref' => '#/components/schemas/Other'],
                        ],
                    ],
                    'Other' => [
                        'type' => 'object',
                        'properties' => [
                            'back' => ['$ref' => '#/components/schemas/Node'],
                            'n' => ['type' => 'integer'],
                        ],
                    ],
                    'Probe' => [
                        'type' => 'object',
                        'properties' => ['node' => ['$ref' => '#/components/schemas/Node']],
                    ],
                ],
            ],
        ];
    }

    /**
     * The errors a Laravel-mode class reports for a payload, the way the application sees them: laravel
     * through `rules()` and `withValidator()` over the raw body, laravel-data through a request.
     *
     * @param array<string, mixed> $spec
     * @return array<string, array<int, string>>
     */
    private function laravelModeErrors(GenerationMode $mode, array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor($mode, $key), $mode->value);
        if ($mode === GenerationMode::LaravelData) {
            LaravelDataContainer::boot();

            /** @var array<string, array<int, string>> */
            return LaravelDataContainer::withRequest(
                json: $json,
                work: static function (LaravelRequest $request) use ($fqcn): array {
                    try {
                        $fqcn::from($request);
                    } catch (ValidationException $exception) {
                        return $exception->errors();
                    }

                    return [];
                },
            );
        }

        /** @var array<string, array<int, mixed>> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make(json_decode($json, true), $rules);
        call_user_func([$fqcn, 'withValidator'], $validator, $json);
        $validator->fails();

        /** @var array<string, array<int, string>> */
        return $validator->errors()->toArray();
    }

    /**
     * laravel-data reports an object-level error under a usable key, without a leading space.
     */
    public function testLaravelDataKeysObjectLevelErrors(): void
    {
        $spec = self::probeSpec(['type' => 'string']);
        $spec['components']['schemas']['Probe'] = [
            'type' => 'object',
            'additionalProperties' => false,
            'minProperties' => 1,
            'properties' => ['code' => ['type' => 'string']],
        ];
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::LaravelData, 'object-error-keys'), 'laravel-data');
        LaravelDataContainer::boot();
        $errors = static fn(string $json): mixed => LaravelDataContainer::withRequest(
            json: $json,
            work: static function (LaravelRequest $request) use ($fqcn): array {
                try {
                    $fqcn::from($request);
                } catch (ValidationException $exception) {
                    return $exception->errors();
                }

                return [];
            },
        );

        $this->assertSame(['bad' => ['payload has additional property "bad" which is not allowed.']], $errors('{"bad":1}'));
        $this->assertSame(['payload' => ['payload must have at least 1 property.']], $errors('{}'));
    }

    /**
     * A JSON string or 0/1 where the schema says integer, number or boolean: refused by the modes that
     * check the JSON type, accepted by the two whose framework rules (or hydrator) are lenient — the
     * support matrix row "a numeric string for `type: integer`". Laravel mode joined the strict side in
     * 2.15.81: its rules are as lenient as laravel-data's, but `withValidator()` reads the raw body.
     */
    public function testLenientScalarsArePinnedPerMode(): void
    {
        $lenient = [GenerationMode::LaravelData, GenerationMode::Yii3];
        foreach ([['integer', '5', '"5"'], ['number', '1.5', '"1.5"'], ['boolean', 'true', '1'], ['boolean', 'false', '"0"']] as [$type, $valid, $invalid]) {
            foreach (GenerationMode::cases() as $mode) {
                $this->assertSame(
                    expected: ['valid' => true, 'invalid' => in_array($mode, $lenient, true)],
                    actual: $this->verdict($mode, self::probeSpec(['type' => $type]), 'lenient-' . $type . $invalid, '{"f":' . $valid . '}', '{"f":' . $invalid . '}'),
                    message: $mode->value . ' ' . $type . ' ' . $invalid,
                );
            }
        }
    }

    public function testSymfonyStrictContextRequiresNullableKeys(): void
    {
        foreach (['null', ['string', 'null']] as $index => $type) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->symfonyVerdict(
                    spec: self::probeSpec(['type' => $type]),
                    key: 'strict-null-presence-' . $index,
                    validJson: '{"f":null}',
                    invalidJson: '{}',
                    context: ['require_all_properties' => true],
                ),
            );
        }
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
                mode: GenerationMode::Symfony,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the serializer denormalizes before any constraint runs, so the array IS the map by then',
            ),
            self::diverges(
                mode: GenerationMode::Yii3,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the hydrator fills the object before the validator runs, and it reads the object, not the body',
            ),
        );

        // A plain map is accepted, the array is not.
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":{"a":1}}', $asArray),
            diverges: $symfonyIsBlind,
            context: $key,
        );

        // And a JSON object whose keys are 0..n-1 stays an object — the check must not overreach.
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key . ' dense', '{"f":{"0":1,"1":2}}', $asArray),
            diverges: $symfonyIsBlind,
            context: $key . ' dense',
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $nested, 'nested object', '{"f":{"a":1}}', $asArray),
            diverges: $symfonyIsBlind,
            context: 'nested object',
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":42.0}', '{"f":42.5}'),
            diverges: array_merge(
                // Both rejected — the serializer never hands the value to a constraint.
                self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => false, 'invalid' => false],
                    reason: 'the serializer type-checks `int $f` first, so 42.0 is refused before generated code runs',
                ),
            ),
            context: $key,
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":{"known":"a"}}', $withExtra),
            diverges: [
                ...self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the serializer denormalizes before any constraint runs, so the undeclared key is '
                        . 'already gone by the time generated code is reached',
                ),
                ...self::diverges(
                    mode: GenerationMode::Yii3,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the hydrator fills the object first and the validator reads the OBJECT, so a key '
                        . 'the schema never declared has nowhere to survive',
                ),
            ],
            context: $key,
        );

        // The same keyword at the TOP of the component, which is the shape the support matrix's row
        // actually names ("on a DTO-shaped schema") and the shape nothing here used to drive. Laravel
        // and laravel-data accepted the undeclared key: the interpreter's literal is keyed by property,
        // so a keyword belonging to the object itself had nowhere to travel, and the object slot beside
        // it did not carry the closed flag. Runtime refused the same payload, one level of nesting
        // away from an identical document.
        $rootSpec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['known'],
                        'additionalProperties' => false,
                        'properties' => ['known' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $rootKey = 'unknown keys are dropped at the root';

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $rootSpec,
                key: $rootKey,
                validJson: '{"known":"a"}',
                invalidJson: '{"known":"a","extra":"b"}',
            ),
            diverges: [
                ...self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the same reason as the nested shape above: the serializer denormalizes first',
                ),
                ...self::diverges(
                    mode: GenerationMode::Yii3,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the same reason as the nested shape above: the hydrator fills the object first',
                ),
            ],
            context: $rootKey,
        );

        // Closed AND patterned. A key the patterns claim is not additional — JSON Schema evaluates
        // `patternProperties` before deciding — so the VALID payload here carries one, and refusing it
        // is the failure this pins. Runtime did refuse it: its root check compared body keys against
        // the declared parameters alone and never read the patterns, which is the one shape where
        // being strict is simply wrong. The invalid payload's key matches no pattern and no property,
        // so it must still be refused; a fix that stopped closing the object would pass the first
        // assertion and fail this one.
        $patternedSpec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['known'],
                        'additionalProperties' => false,
                        'patternProperties' => ['^x_' => ['type' => 'string']],
                        'properties' => ['known' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $patternedKey = 'a patterned key is not an undeclared one';

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $patternedSpec,
                key: $patternedKey,
                validJson: '{"known":"a","x_extra":"b"}',
                invalidJson: '{"known":"a","nope":"b"}',
            ),
            diverges: [
                ...self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the same reason as the two shapes above: the serializer denormalizes first',
                ),
                ...self::diverges(
                    mode: GenerationMode::Yii3,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the same reason as the two shapes above: the hydrator fills the object first',
                ),
            ],
            context: $patternedKey,
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $valid, $asNull),
            // Symfony agrees since its setter admits null only where the schema does.
            diverges: [],
            context: $key,
        );

        // The same property WITH `nullable` must accept the null in every mode, or the assertion above
        // would read as "laravel rejects null", which is not what the schema asked for.
        $nullableSpec = $spec;
        $nullableSpec['components']['schemas']['Probe']['properties']['f']['nullable'] = true;
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => true],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $nullableSpec, $key . ' nullable', $valid, $asNull),
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
            // The 3.0 spelling of that branch: it extracted to the bare permission, which the
            // interpreter dropped, so Symfony refused the null the branch stands for.
            'a 3.0 nullable branch inside the union' => [
                'oneOf' => [['type' => 'integer', 'minimum' => 10], ['type' => 'string'], ['nullable' => true]],
            ],
        ];

        foreach ($spellings as $key => $propertySchema) {
            $spec = self::probeSpec($propertySchema);

            // `null` is allowed; an integer below the branch's own minimum is not.
            $this->assertEveryModeYields(
                expected: ['valid' => true, 'invalid' => false],
                observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":null}', '{"f":5}'),
                context: $key,
            );
        }

        // The same branch beside a `$ref`, which Symfony matches by class rather than by schema.
        $key = 'a 3.0 nullable branch beside a reference';
        $spec = self::probeSpec(['oneOf' => [['$ref' => '#/components/schemas/Widget'], ['nullable' => true]]]);
        $spec['components']['schemas']['Widget'] = [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 10]],
        ];
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":null}', '{"f":{"id":5}}'),
            context: $key,
        );
    }

    /**
     * A property that is a `$ref` to a `type: array` component means the same as the array written inline.
     *
     * The property was TYPED through the alias (`array<Item>`), but its constraints were read from the
     * bare `$ref`, which carries none. Laravel and laravel-data then emitted `['sometimes']` with no
     * `array`/`list` rule: `{"f": "x"}` passed validation and hydration died on `array_map()` — a 500
     * after the validator said yes — while an object or a list of the wrong scalars was accepted outright.
     * Symfony, runtime and Yii3 read a class's constraints from its registered schema, where the same
     * bare `$ref` was scrubbed, so Symfony accepted the list of wrong scalars too.
     *
     * Held to the inline spelling in every mode, directly on the property and one container down.
     * `Items` is the component referenced; the provider may route it through further components.
     *
     * @param array<string, mixed> $aliasSchema the array the reference stands for, written inline
     * @param array<string, array<string, mixed>> $components
     * @param array<int, string> $invalidValues
     */
    #[DataProvider('arrayAliasProvider')]
    public function testAnArrayAliasValidatesLikeTheInlineArray(
        array $aliasSchema,
        array $components,
        string $validValue,
        array $invalidValues,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $item = [
            'type' => 'object',
            'required' => ['value'],
            'properties' => ['value' => ['type' => 'string', 'format' => 'email']],
        ];
        $containers = [
            'property' => [static fn(array $schema): array => $schema, '%s'],
            'list item' => [static fn(array $schema): array => ['type' => 'array', 'items' => $schema], '[%s]'],
            'map value' => [static fn(array $schema): array => ['type' => 'object', 'additionalProperties' => $schema], '{"k":%s}'],
        ];

        foreach ($containers as $container => [$wrap, $payload]) {
            $inline = self::probeSpec($wrap($aliasSchema));
            $inline['components']['schemas']['Item'] = $item;
            $aliased = self::probeSpec($wrap(['$ref' => '#/components/schemas/Items']));
            $aliased['components']['schemas'] += $components + ['Item' => $item];
            $validJson = '{"f":' . sprintf($payload, $validValue) . '}';

            foreach ($invalidValues as $index => $invalidValue) {
                $invalidJson = '{"f":' . sprintf($payload, $invalidValue) . '}';
                $key = 'array-alias-' . md5(json_encode([$aliasSchema, $container], JSON_THROW_ON_ERROR)) . '-' . $index;
                foreach (GenerationMode::cases() as $mode) {
                    $expected = $this->verdict($mode, $inline, $key . '-inline', $validJson, $invalidJson);
                    $this->assertSame(
                        expected: $expected,
                        actual: $this->verdict($mode, $aliased, $key . '-alias', $validJson, $invalidJson),
                        message: $mode->value . ' ' . $invalidJson,
                    );
                    // The equivalence alone would hold if both spellings accepted everything.
                    if ($container === 'property' && in_array($mode, [GenerationMode::Laravel, GenerationMode::LaravelData], true)) {
                        $this->assertSame(['valid' => true, 'invalid' => false], $expected, $mode->value . ' ' . $invalidJson);
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, array<string, mixed>>, string, array<int, string>}>
     */
    public static function arrayAliasProvider(): array
    {
        $objects = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']];
        $strings = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1];
        $nullableStrings = [...$strings, 'type' => ['array', 'null']];
        $notAList = ['"x"', '{"k":{"value":"a@example.com"}}', '[{"value":"invalid"}]'];
        $notStrings = ['"x"', '[1]', '[]'];
        $ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];

        return [
            'list of objects' => [$objects, ['Items' => $objects], '[{"value":"a@example.com"}]', $notAList],
            'nullable list of objects' => [[...$objects, 'nullable' => true], ['Items' => [...$objects, 'nullable' => true]], 'null', $notAList],
            'list of strings' => [$strings, ['Items' => $strings], '["a"]', $notStrings],
            // The OpenAPI 3.1 spelling of a nullable list: it became an empty class before 2.15.59.
            'type list with null' => [$nullableStrings, ['Items' => $nullableStrings], 'null', $notStrings],
            'type list with null, a value' => [$nullableStrings, ['Items' => $nullableStrings], '["a"]', $notStrings],
            // An alias of an alias, which became an empty class as well.
            'alias of an alias' => [$strings, ['Items' => $ref('Strings'), 'Strings' => $strings], '["a"]', $notStrings],
            'alias of a nullable alias' => [
                [...$objects, 'nullable' => true],
                ['Items' => $ref('Objects'), 'Objects' => [...$objects, 'nullable' => true]],
                'null',
                $notAList,
            ],
        ];
    }

    /**
     * An array alias in the other places a reference can stand: a union branch, an `allOf` item, beside
     * a keyword of the property's own, and as the values of a map. Each was measured correct when 2.15.58
     * shipped; these pin it.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     */
    #[DataProvider('arrayAliasPositionProvider')]
    public function testAnArrayAliasIsCheckedWhereverItIsReferenced(
        array $propertySchema,
        array $components,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['components']['schemas'] += $components;
        $key = 'array-alias-position-' . md5(json_encode([$propertySchema, $components], JSON_THROW_ON_ERROR));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, array<string, mixed>>, string, string}>
     */
    public static function arrayAliasPositionProvider(): array
    {
        $ref = ['$ref' => '#/components/schemas/Strings'];
        $strings = ['Strings' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1]];
        $objects = [
            'Objects' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
            'Item' => [
                'type' => 'object',
                'required' => ['value'],
                'properties' => ['value' => ['type' => 'string', 'format' => 'email']],
            ],
        ];

        return [
            'oneOf branch' => [['oneOf' => [$ref, ['type' => 'integer']]], $strings, '{"f":["a"]}', '{"f":[1]}'],
            'allOf item' => [['type' => 'array', 'items' => ['allOf' => [$ref]]], $strings, '{"f":[["a"]]}', '{"f":[[1]]}'],
            'sibling maxItems' => [[...$ref, 'maxItems' => 1], $strings, '{"f":["a"]}', '{"f":["a","b"]}'],
            'uniqueItems' => [
                $ref,
                ['Strings' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true]],
                '{"f":["a","b"]}',
                '{"f":["a","a"]}',
            ],
            'map values' => [
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Objects']],
                $objects,
                '{"f":{"k":[{"value":"a@example.com"}]}}',
                '{"f":{"k":[{"value":"invalid"}]}}',
            ],
            // A nullable alias as an `allOf` item accepts its null.
            'allOf item of a nullable alias' => [
                ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/NullableStrings']]]],
                ['NullableStrings' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']]],
                '{"f":[null]}',
                '{"f":[[1]]}',
            ],
        ];
    }

    /**
     * Bounds no value can meet are a refusal, not a crash. yiisoft throws "$min must be lower than $max."
     * while validating, so yii3 mode answered every payload with an exception until it left the pair to
     * the interpreter.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('crossedBoundsProvider')]
    public function testCrossedBoundsAreAViolationInYii3(array $propertySchema, string $json): void
    {
        $key = 'crossed-bounds-' . md5(json_encode($propertySchema, JSON_THROW_ON_ERROR));
        $fqcn = $this->generate(self::probeSpec($propertySchema), $this->namespaceFor(GenerationMode::Yii3, $key), 'yii3');
        $this->skipYii3WithoutIntl($fqcn, $json, $key);

        $container = new Yii3Container();
        $payload = json_decode($json, true);
        $this->assertIsArray($payload);

        $this->assertFalse($container->validate($container->hydrate($fqcn, $payload))->isValid());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function crossedBoundsProvider(): array
    {
        return [
            'minItems above maxItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 2], '{"f":["a","b"]}'],
            'minLength above maxLength' => [['type' => 'string', 'minLength' => 3, 'maxLength' => 2], '{"f":"ab"}'],
        ];
    }

    /**
     * A list with no `items` below the first container holds anything a JSON array can. Runtime mode
     * refused all of it before 2.15.61: the declaration said `array<array>`, and a bare `array` item is
     * the map form, so `[[1]]` was "expects object, got array" while every other mode accepted it.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('itemlessNestedListProvider')]
    public function testAnItemlessNestedListAcceptsAnyJsonArray(
        array $propertySchema,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $key = 'itemless-list-' . md5(json_encode([$propertySchema, $validJson], JSON_THROW_ON_ERROR));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function itemlessNestedListProvider(): array
    {
        $lists = ['type' => 'array', 'items' => ['type' => 'array']];
        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'array']];

        return [
            'list of lists, scalars' => [$lists, '{"f":[[1,"a",true]]}', '{"f":[1]}'],
            'list of lists, null' => [$lists, '{"f":[[null]]}', '{"f":["a"]}'],
            'list of lists, objects' => [$lists, '{"f":[[{"a":1}]]}', '{"f":[{"a":1}]}'],
            'map of lists' => [$map, '{"f":{"k":[1,{"a":1}]}}', '{"f":{"k":"x"}}'],
        ];
    }

    /**
     * A component that only names another one — `X: {$ref: Y}`, or `allOf: [{$ref: Y}]` around a
     * non-object — is Y. Until 2.15.63 it became an empty class: a scalar or enum alias refused every
     * valid value in four modes while Symfony accepted anything, and an object alias validated nothing.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     * @param array<string, array{expected: mixed, reason: string}> $diverges
     */
    #[DataProvider('pureAliasProvider')]
    public function testAComponentThatOnlyNamesAnotherIsThatSchema(
        array $propertySchema,
        array $components,
        string $validJson,
        string $invalidJson,
        array $diverges = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['components']['schemas'] += $components;
        $key = 'pure-alias-' . md5(json_encode([$propertySchema, $components, $validJson], JSON_THROW_ON_ERROR));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: string, 3: string, 4?: array<string, array{expected: mixed, reason: string}>}>
     */
    public static function pureAliasProvider(): array
    {
        $ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $code = ['Code' => ['type' => 'string', 'minLength' => 2]];
        $kind = ['Kind' => ['type' => 'string', 'enum' => ['a', 'b']]];
        $item = ['Item' => ['type' => 'object', 'required' => ['v'], 'properties' => ['v' => ['type' => 'string']]]];

        return [
            'scalar alias' => [$ref('Alias'), $code + ['Alias' => $ref('Code')], '{"f":"ab"}', '{"f":"a"}'],
            'scalar alias through allOf' => [$ref('Alias'), $code + ['Alias' => ['allOf' => [$ref('Code')]]], '{"f":"ab"}', '{"f":"a"}'],
            'alias of a scalar alias' => [
                $ref('Outer'),
                $code + ['Inner' => $ref('Code'), 'Outer' => $ref('Inner') + ['description' => 'two hops']],
                '{"f":"ab"}',
                '{"f":"a"}',
            ],
            'enum alias' => [$ref('Alias'), $kind + ['Alias' => $ref('Kind')], '{"f":"a"}', '{"f":"z"}'],
            'enum alias as a list item' => [
                ['type' => 'array', 'items' => $ref('Alias')],
                $kind + ['Alias' => $ref('Kind')],
                '{"f":["a","b"]}',
                '{"f":["z"]}',
            ],
            'object alias' => [$ref('Alias'), $item + ['Alias' => $ref('Item')], '{"f":{"v":"x"}}', '{"f":{}}'],
            'object alias as a list item' => [
                ['type' => 'array', 'items' => $ref('Alias')],
                $item + ['Alias' => $ref('Item')],
                '{"f":[{"v":"x"}]}',
                '{"f":[{}]}',
            ],
            'nullable scalar alias' => [$ref('Alias'), $code + ['Alias' => $ref('Code') + ['nullable' => true]], '{"f":null}', '{"f":"a"}'],
            // Not an alias component, but the same reference read through a union: the enum's members
            // were checked against the null item the second branch allows.
            'enum or null as a list item' => [
                ['type' => 'array', 'items' => ['anyOf' => [$ref('Kind'), ['type' => 'null']]]],
                $kind,
                '{"f":["a",null]}',
                '{"f":["z"]}',
                // OPEN DEFECT, the same as the null date item: the serializer hands the null item to
                // BackedEnumNormalizer, which throws.
                self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => false, 'invalid' => false],
                    reason: 'open defect: Symfony serializer cannot denormalize a null enum item',
                ),
            ],
        ];
    }

    /**
     * Shapes that crashed or refused a valid payload until 2.15.62: a `type` list in yii3 stopped the
     * generation, an optional `anyOf: [{$ref}, {type: null}]` refused its null, a nullable date or DTO
     * item was a TypeError in laravel's `fromValidated()`, and runtime refused null in a `{}` map.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     * @param array<string, array{expected: mixed, reason: string}> $diverges
     */
    #[DataProvider('crashShapeProvider')]
    public function testShapesThatCrashedAreAnsweredInEveryMode(
        array $propertySchema,
        array $components,
        string $validJson,
        string $invalidJson,
        array $diverges = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['components']['schemas'] += $components;
        // Optional, which is where the folded null branch was lost.
        $spec['components']['schemas']['Probe']['required'] = [];
        $key = 'crash-shape-' . md5(json_encode([$propertySchema, $components, $validJson], JSON_THROW_ON_ERROR));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: string, 3: string, 4?: array<string, array{expected: mixed, reason: string}>}>
     */
    public static function crashShapeProvider(): array
    {
        // OPEN DEFECT: yiisoft's CollectionResolver skips an element it cannot build before any rule
        // runs, and the raw payload is gone by then.
        $droppedByYii3 = self::diverges(
            mode: GenerationMode::Yii3,
            expected: ['valid' => true, 'invalid' => true],
            reason: 'open defect: yii3 Collection hydration drops a non-object item before validation',
        );
        $tag = ['Tag' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]];
        $tagRef = ['$ref' => '#/components/schemas/Tag'];

        return [
            'type list of two named types' => [['type' => ['string', 'integer']], [], '{"f":5}', '{"f":true}'],
            'anyOf ref or null, null' => [['anyOf' => [$tagRef, ['type' => 'null']]], $tag, '{"f":null}', '{"f":{}}'],
            'oneOf ref or null, null' => [['oneOf' => [$tagRef, ['type' => 'null']]], $tag, '{"f":null}', '{"f":{}}'],
            'nullable date items' => [
                ['type' => 'array', 'items' => ['type' => ['string', 'null'], 'format' => 'date']],
                [],
                '{"f":[null,"2026-01-02"]}',
                '{"f":["x"]}',
                // OPEN DEFECT, not a design choice: the serializer's DateTimeNormalizer is handed the
                // null item and throws, and it parses "x" leniently as every Symfony date does.
                self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => false, 'invalid' => true],
                    reason: 'open defect: Symfony serializer cannot denormalize a null date item',
                ),
            ],
            'nullable dto items' => [
                ['type' => 'array', 'items' => ['anyOf' => [$tagRef, ['type' => 'null']]]],
                $tag,
                '{"f":[null,{"id":1}]}',
                '{"f":[{}]}',
                // OPEN DEFECT: `#[DataCollectionOf(Tag::class)]` builds a Tag out of the null item.
                self::diverges(
                    mode: GenerationMode::LaravelData,
                    expected: ['valid' => false, 'invalid' => false],
                    reason: 'open defect: DataCollectionOf cannot hold a null item',
                ) + self::diverges(
                    mode: GenerationMode::Yii3,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'open defect: yii3 does not check a nullable DTO item, as it drops a non-object one',
                ),
            ],
            // A non-object ITEM of a DTO list or map. Laravel had no rule on `f.*` and yii3's collection
            // hydrator skipped what it could not build, so `["x"]` hydrated to `[]` without an error.
            'string item in a list of DTOs' => [['type' => 'array', 'items' => $tagRef], $tag, '{"f":[{"id":1}]}', '{"f":["x"]}', $droppedByYii3],
            'null item in a list of DTOs' => [['type' => 'array', 'items' => $tagRef], $tag, '{"f":[{"id":1}]}', '{"f":[null]}', $droppedByYii3],
            'string value in a map of DTOs' => [
                ['type' => 'object', 'additionalProperties' => $tagRef],
                $tag,
                '{"f":{"k":{"id":1}}}',
                '{"f":{"k":"x"}}',
            ],
            // A `$ref` to a MAP component as a list item: its values were checked nowhere, while the same
            // map written inline was enforced in every mode.
            'map component as a list item' => [
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Colors']],
                ['Colors' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['red', 'green']]]],
                '{"f":[{"k":"red"}]}',
                '{"f":[{"k":"blue"}]}',
            ],
            'map component as a property' => [
                ['$ref' => '#/components/schemas/Colors'],
                ['Colors' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['red', 'green']]]],
                '{"f":{"k":"red"}}',
                '{"f":{"k":"blue"}}',
            ],
            'null in a free-form map' => [
                ['type' => 'object', 'additionalProperties' => []],
                [],
                '{"f":{"j":null,"k":1}}',
                '{"f":"x"}',
            ],
        ];
    }

    /**
     * A null that the document allows below the first container: a list of lists of nullable strings,
     * and a map of nullable lists written inline. 2.15.59 typed the first `array<array<string>>`, and the
     * second never reached the list typing at all.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('nestedNullProvider')]
    public function testANestedNullIsAcceptedWhereTheDocumentAllowsIt(
        array $propertySchema,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $key = 'nested-null-' . md5(json_encode([$propertySchema, $validJson], JSON_THROW_ON_ERROR));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $validJson, $invalidJson),
            context: $key,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function nestedNullProvider(): array
    {
        $lists = static fn(array $item): array => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $item]];
        $map = ['type' => 'object', 'additionalProperties' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']]];

        return [
            'list of lists of nullable strings' => [$lists(['type' => ['string', 'null']]), '{"f":[["a",null]]}', '{"f":[[1]]}'],
            'the same, nullable: true' => [$lists(['type' => 'string', 'nullable' => true]), '{"f":[["a",null]]}', '{"f":[[1]]}'],
            'list of lists of nullable numbers' => [$lists(['type' => ['number', 'null']]), '{"f":[[1.5,null]]}', '{"f":[["a"]]}'],
            'inline map of nullable lists, null' => [$map, '{"f":{"k":null}}', '{"f":{"k":[1]}}'],
            'inline map of nullable lists, a list' => [$map, '{"f":{"k":["a"]}}', '{"f":{"k":"a"}}'],
        ];
    }

    /**
     * An array alias whose items lead back to it has no finite inline form. Generation has to stop at
     * the repeat limit instead of inlining it forever, and the levels it does inline are still checked.
     */
    public function testASelfReferentialArrayAliasGenerates(): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Nodes']);
        $spec['components']['schemas']['Nodes'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['children' => ['$ref' => '#/components/schemas/Nodes']],
            ],
        ];

        foreach (GenerationMode::cases() as $mode) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false],
                actual: $this->verdict($mode, $spec, 'self-alias', '{"f":[{"children":[]}]}', '{"f":"x"}'),
                message: $mode->value,
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'nullable ref', '{"child":null}', '{"child":{}}'),
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'rec ' . $key, $valid, $invalidJson),
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
            expected: ['valid' => true, 'invalid' => false],
            // The branch keyword belongs in the namespace key: without it both spellings generate into
            // one namespace, PHP keeps the classes it loaded first, and the second spelling silently
            // re-tests the first one's classes — green and proving nothing.
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: 'chain ' . $branchKeyword . ' ' . $key,
                validJson: $valid,
                invalidJson: $invalidJson,
            ),
            diverges: self::declaredUnionChainDivergences()[$key] ?? [],
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
     * An `allOf` under a keyword that materializes nothing is enforced in every mode.
     *
     * Symfony, Laravel, laravel-data and yii3 share one constraint filter, and the flag that says
     * "nothing materializes here, keep the composition" was set for the container keys and lost at the
     * first hop into `contentSchema`, `not`, `if`/`then` and `contains`. Those four modes emitted `{}`
     * for a branch carrying `required` and a bound while runtime kept it — measured per key before the
     * flag was threaded through.
     *
     * @param array<string, mixed> $extra the keyword under test, wrapping the same `allOf`
     */
    #[DataProvider('allOfUnderKeywordProvider')]
    public function testAnAllOfUnderANonMaterializingKeywordIsEnforcedInEveryMode(
        string $key,
        array $extra,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Probe' => ['type' => 'object', 'required' => ['f'], 'properties' => ['f' => $extra]],
            ]],
        ];

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'allofunder ' . $key, $validJson, $invalidJson),
            context: 'allOf under ' . $key,
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function allOfUnderKeywordProvider(): array
    {
        $deep = ['allOf' => [[
            'type' => 'object',
            'required' => ['marker'],
            'properties' => ['marker' => ['type' => 'string', 'minLength' => 7]],
        ]]];

        return [
            'then' => [
                'then',
                ['type' => 'object', 'properties' => ['marker' => ['type' => 'string']], 'if' => ['type' => 'object']] + ['then' => $deep],
                '{"f":{"marker":"loooong"}}',
                '{"f":{"marker":"short"}}',
            ],
            'contains' => [
                'contains',
                ['type' => 'array', 'items' => ['type' => 'object']] + ['contains' => $deep],
                '{"f":[{"marker":"loooong"}]}',
                '{"f":[{"marker":"short"}]}',
            ],
        ];
    }

    /**
     * What a document states about the OBJECT ITSELF, enforced in every mode.
     *
     * These keywords have no property to hang off, so each mode has to carry them at the class level or
     * lose them. Measured before 2.15.16: runtime, laravel and laravel-data carried none of them and
     * accepted every violation; Symfony carried three of four, losing `minProperties` because on a
     * property that one becomes `#[Assert\Count]` and was dropped from the interpreter to avoid
     * doubling — with no attribute at the class level to take over.
     *
     * @param array<string, mixed> $extra the object-wide keyword under test
     */
    #[DataProvider('objectWideProvider')]
    public function testObjectWideKeywordsAreEnforcedInEveryMode(
        string $key,
        array $extra,
        string $validJson,
        string $invalidJson,
    ): void {
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
                        'properties' => [
                            'kind' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'extra' => ['type' => 'string'],
                        ],
                    ] + $extra,
                ],
            ],
        ];

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'objectwide ' . $key, $validJson, $invalidJson),
            context: 'object-wide ' . $key,
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function objectWideProvider(): array
    {
        return [
            'minProperties' => [
                'minProperties',
                ['minProperties' => 3],
                '{"kind":"a","code":"b","extra":"c"}',
                '{"kind":"a","code":"b"}',
            ],
            'dependentRequired' => [
                'dependentRequired',
                ['dependentRequired' => ['kind' => ['code']]],
                '{"kind":"a","code":"b"}',
                '{"kind":"a"}',
            ],
            'not' => [
                'not',
                ['not' => ['required' => ['extra']]],
                '{"kind":"a","code":"b"}',
                '{"kind":"a","extra":"c"}',
            ],
            'conditional' => [
                'conditional',
                [
                    'if' => ['required' => ['kind']],
                    'then' => ['properties' => ['code' => ['type' => 'string', 'minLength' => 7]]],
                ],
                '{"kind":"a","code":"loooong"}',
                '{"kind":"a","code":"short"}',
            ],
        ];
    }

    /**
     * A `$ref` under an APPLICATOR, enforced in every mode.
     *
     * The generator's inlining is what makes these keywords work at all — nothing materializes for
     * `contains`, `prefixItems` or `unevaluatedItems`, so each mode reads the same emitted constraints
     * with its own machine. Runtime was measured first and fixed in 2.15.14; this is the same question
     * asked of all five, because the last two rounds of this bug answered differently per mode: `allOf`
     * needed a second fix in Symfony (its interpreter had no such keyword) and a third in Laravel (its
     * rule map already covered part of it).
     *
     * @param string $invalidJson a payload the referenced component forbids
     */
    #[DataProvider('applicatorRefProvider')]
    public function testARefUnderAnApplicatorIsEnforcedInEveryMode(string $key, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Marked' => [
                        'type' => 'object',
                        'required' => ['marker'],
                        'properties' => ['marker' => ['type' => 'string', 'minLength' => 7]],
                    ],
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => self::applicatorProperty($key)],
                    ],
                ],
            ],
        ];

        $valid = match ($key) {
            'unevaluatedItems' => '{"f":["x",{"marker":"loooong"}]}',
            'not' => '{"f":{"other":"y"}}',
            'then' => '{"f":{"marker":"loooong"}}',
            'dependentSchemas' => '{"f":{"kind":"x","marker":"loooong"}}',
            default => '{"f":[{"marker":"loooong"}]}',
        };

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'applicator ' . $key, $valid, $invalidJson),
            context: 'applicator ' . $key,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function applicatorRefProvider(): array
    {
        return [
            'contains' => ['contains', '{"f":[{"marker":"short"}]}'],
            'prefixItems' => ['prefixItems', '{"f":[{"marker":"short"}]}'],
            'unevaluatedItems' => ['unevaluatedItems', '{"f":["x",{"marker":"short"}]}'],
            // `marker` present makes the value MATCH what the reference describes, which `not` forbids.
            'not' => ['not', '{"f":{"marker":"anything"}}'],
            'then' => ['then', '{"f":{"marker":"short"}}'],
            'dependentSchemas' => ['dependentSchemas', '{"f":{"kind":"x","marker":"short"}}'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function applicatorProperty(string $keyword): array
    {
        $ref = ['$ref' => '#/components/schemas/Marked'];

        return match ($keyword) {
            'contains' => ['type' => 'array', 'items' => ['type' => 'object'], 'contains' => $ref],
            'prefixItems' => ['type' => 'array', 'prefixItems' => [$ref]],
            // `other` exists so the VALID payload can be non-empty: Laravel's `required` rule fails on
            // an empty array, so `{"f":{}}` would be rejected there for a reason that has nothing to do
            // with `not` — the probe, not the code.
            'not' => [
                'type' => 'object',
                'properties' => ['marker' => ['type' => 'string'], 'other' => ['type' => 'string']],
                'not' => $ref,
            ],
            'then' => [
                'type' => 'object',
                'properties' => ['marker' => ['type' => 'string']],
                'if' => ['type' => 'object'],
                'then' => $ref,
            ],
            'dependentSchemas' => [
                'type' => 'object',
                'properties' => ['kind' => ['type' => 'string'], 'marker' => ['type' => 'string']],
                'dependentSchemas' => ['kind' => $ref],
            ],
            default => [
                'type' => 'array',
                'prefixItems' => [['type' => 'string']],
                'unevaluatedItems' => $ref,
            ],
        };
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'rootrec ' . $key, $valid, $invalidJson),
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

        // Every mode, not the three this used to name. The support matrix's row claims all five, and
        // laravel-data and yii3 were asserted by nobody — measured since, and both refuse, but an
        // unmeasured cell is a claim either way.
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, $valid, $loose),
            diverges: self::diverges(
                mode: GenerationMode::Symfony,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the property is a DateTimeImmutable, so the serializer parses the string before any '
                    . "generated constraint runs, and PHP's parser accepts \"yesterday\"",
            ),
            context: $key,
        );
    }

    #[DataProvider('dateOrDateTimeUnionCases')]
    public function testADateOrDateTimeUnionAcceptsBothShapesInEveryMode(
        string $firstFormat,
        string $secondFormat,
        string $values,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        [$validValue, $invalidValue] = explode('|', $values);
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'nullable' => true,
                                'oneOf' => [
                                    ['type' => 'string', 'format' => $firstFormat],
                                    ['type' => 'string', 'format' => $secondFormat],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $key = "date|date-time {$firstFormat} first, {$validValue} / {$invalidValue}";
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: '{"f":' . $validValue . '}',
                invalidJson: '{"f":' . $invalidValue . '}',
            ),
            diverges: $invalidValue === '"2026-02-30"'
                ? self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the serializer parses the date before any generated constraint runs, and PHP rolls '
                        . '2026-02-30 over to 2 March — as for a plain `format: date`',
                )
                : [],
            context: $key,
        );
    }

    /**
     * A `oneOf` of a `format: date` string and a `format: date-time` string is ONE getter returning
     * `?DateTimeImmutable`, and both wire shapes belong to it. Runtime used to read only a top-level
     * `format`, found none, fell back to strict date-time and refused `"2026-12-01"` before the `oneOf`
     * was ever consulted — whichever order the branches came in.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function dateOrDateTimeUnionCases(): array
    {
        $provided = [];
        foreach (['date first' => ['date', 'date-time'], 'date-time first' => ['date-time', 'date']] as $order => $formats) {
            foreach ([
                'date' => '"2026-12-01"',
                'date-time' => '"2026-12-01T10:00:00+00:00"',
                'Z date-time' => '"2026-12-01T10:00:00Z"',
                'midnight date-time' => '"2026-12-01T00:00:00Z"',
            ] as $shape => $validValue) {
                foreach ([
                    'impossible date' => '"2026-02-30"',
                    'garbage' => '"not a date"',
                    'empty' => '""',
                ] as $invalidShape => $invalidValue) {
                    $provided["{$order}, {$shape} / {$invalidShape}"] = [$formats[0], $formats[1], $validValue . '|' . $invalidValue];
                }
            }
        }

        return $provided;
    }

    /**
     * An impossible calendar date under a plain `format: date`. Symfony mode's serializer parses the string
     * before any generated constraint runs, and PHP's date parsing rolls an overflowing day forward instead
     * of failing — the same serializer-decides-first gap as the loose date-time above, found while pinning
     * the date-or-date-time union.
     */
    public function testAnImpossibleCalendarDateIsRefusedWhereverTheGeneratorOwnsTheCheck(): void
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
                        'properties' => ['f' => ['type' => 'string', 'format' => 'date']],
                    ],
                ],
            ],
        ];

        $key = 'impossible calendar date';
        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, $key, '{"f":"2026-12-01"}', '{"f":"2026-02-30"}'),
            diverges: self::diverges(
                mode: GenerationMode::Symfony,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the serializer parses the date before any generated constraint runs, and PHP rolls '
                    . '2026-02-30 over to 2 March',
            ),
            context: $key,
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
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict($mode, $spec, 'mutual', $valid, $invalid),
            context: 'mutual recursion',
        );
    }

    /**
     * `required` binds a property of the whole object, wherever the `allOf` declares it: a `required`
     * in one branch, or beside the `allOf`, names a property another branch — often the `$ref` parent —
     * declares. Only the outer list was read, and only for the branches merged into the class, so an
     * inherited property stayed optional and `{}` went through. Every spelling of the same object must
     * refuse it; the last two pin the requirements that already held, so the fix cannot trade one for
     * the other.
     *
     * @param array<string, array<string, mixed>> $schemas
     */
    #[DataProvider('crossBranchRequiredProvider')]
    public function testARequiredNameBindsAPropertyAnotherAllOfBranchDeclares(array $schemas, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => $schemas],
        ];
        $key = 'cross-branch required ' . md5((string)json_encode($schemas));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, string, string}>
     */
    public static function crossBranchRequiredProvider(): iterable
    {
        $base = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer']]];
        $ref = ['$ref' => '#/components/schemas/Base'];
        $extra = ['extra' => ['type' => 'boolean']];

        yield 'a branch of required alone' => [['Base' => $base, 'Probe' => ['allOf' => [$ref, ['required' => ['name']]]]], '{"name":"a"}', '{}'];
        yield 'a typed branch of required alone' => [['Base' => $base, 'Probe' => ['allOf' => [$ref, ['type' => 'object', 'required' => ['name']]]]], '{"name":"a"}', '{"size":1}'];
        yield 'a branch with properties of its own' => [['Base' => $base, 'Probe' => ['allOf' => [$ref, ['type' => 'object', 'required' => ['name'], 'properties' => $extra]]]], '{"name":"a","extra":true}', '{"extra":true}'];
        yield 'beside the allOf' => [['Base' => $base, 'Probe' => ['required' => ['name'], 'allOf' => [$ref]]], '{"name":"a"}', '{}'];
        yield 'beside an allOf with a branch' => [['Base' => $base, 'Probe' => ['required' => ['name'], 'allOf' => [$ref, ['type' => 'object', 'properties' => $extra]]]], '{"name":"a"}', '{"extra":true}'];
        yield 'two inline branches' => [['Probe' => ['allOf' => [$base, ['required' => ['name']]]]], '{"name":"a"}', '{}'];
        // Two `$ref`s are merged rather than inherited, which is the other path a class is built by.
        yield 'two merged parents' => [
            [
                'Base' => $base,
                'Tagged' => ['type' => 'object', 'properties' => ['tag' => ['type' => 'string']]],
                'Probe' => ['allOf' => [$ref, ['$ref' => '#/components/schemas/Tagged'], ['required' => ['name', 'tag']]]],
            ],
            '{"name":"a","tag":"b"}',
            '{"name":"a"}',
        ];
        yield 'a grandparent property' => [
            [
                'Base' => $base,
                'Middle' => ['allOf' => [$ref, ['type' => 'object', 'properties' => $extra]]],
                'Probe' => ['allOf' => [['$ref' => '#/components/schemas/Middle'], ['required' => ['name']]]],
            ],
            '{"name":"a"}',
            '{"extra":true}',
        ];
        yield 'control: required in the parent' => [
            ['Base' => [...$base, 'required' => ['name']], 'Probe' => ['allOf' => [$ref, ['type' => 'object', 'properties' => $extra]]]],
            '{"name":"a"}',
            '{"extra":true}',
        ];
        yield 'control: required on the branch\'s own property' => [
            ['Base' => $base, 'Probe' => ['allOf' => [$ref, ['type' => 'object', 'required' => ['extra'], 'properties' => $extra]]]],
            '{"extra":true}',
            '{"name":"a"}',
        ];
    }

    /**
     * A branch that restates a property the parent declares narrows it: under `allOf` both schemas hold,
     * so the parent's `required`, `maxLength`, `enum` and type stay in force beside the branch's own
     * `minLength`. Symfony and both Laravel modes let the branch REPLACE the parent's rules, and runtime
     * refused to generate the document at all ("Property override conflict … parent type string, child
     * type ?string") although nothing in it conflicts.
     *
     * @param array<string, mixed> $restated the branch's schema for `name`
     */
    #[DataProvider('restatedPropertyProvider')]
    public function testABranchRestatingAParentPropertyKeepsTheParentsRules(string $shape, array $restated, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // `tag` is required so that an unknown value fails in yii3 too: there an OPTIONAL enum the
        // hydrator cannot fill is left unset and passes as absent — a gap of its own, outside `allOf`.
        $base = [
            'type' => 'object',
            'required' => ['name', 'tag'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 3, 'pattern' => '^[a-z]+$'],
                'tag' => ['type' => 'string', 'enum' => ['a', 'b']],
            ],
        ];
        $branch = ['type' => 'object', 'properties' => ['name' => $restated, 'tag' => ['type' => 'string'], 'flag' => ['type' => 'boolean']]];
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => match ($shape) {
                'inherited' => ['Base' => $base, 'Probe' => ['allOf' => [['$ref' => '#/components/schemas/Base'], $branch]]],
                // Two `$ref`s are merged into one class instead of inherited; the restatement is the same.
                'merged' => [
                    'Base' => $base,
                    'Other' => ['type' => 'object', 'properties' => ['flag' => ['type' => 'boolean']]],
                    'Probe' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['$ref' => '#/components/schemas/Other'], $branch]],
                ],
                'inline' => ['Probe' => ['allOf' => [$base, $branch]]],
            }],
        ];
        $key = 'restated ' . $shape . ' ' . json_encode($restated) . ' ' . $invalidJson;

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: '{"name":"ab","tag":"a","flag":true}',
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function restatedPropertyProvider(): iterable
    {
        $invalid = [
            'the parent\'s required' => '{"tag":"a","flag":true}',
            'the parent\'s maxLength' => '{"name":"abcd","tag":"a"}',
            'the branch\'s minLength' => '{"name":"a","tag":"a"}',
            'the parent\'s enum' => '{"name":"ab","tag":"zzz"}',
            'the parent\'s pattern' => '{"name":"AB","tag":"a"}',
        ];
        foreach (['inherited', 'merged', 'inline'] as $shape) {
            foreach (['typed' => ['type' => 'string', 'minLength' => 2], 'untyped' => ['minLength' => 2]] as $spelling => $restated) {
                foreach ($invalid as $rule => $json) {
                    yield $shape . ' ' . $spelling . ': ' . $rule => [$shape, $restated, $json];
                }
            }
            yield $shape . ' untyped: the parent\'s type' => [$shape, ['minLength' => 2], '{"name":12345,"tag":"a"}'];
            // The same keyword on both sides: neither value replaces the other, the stricter one decides.
            yield $shape . ' looser maxLength: the parent\'s' => [$shape, ['type' => 'string', 'maxLength' => 5], '{"name":"abcd","tag":"a"}'];
            yield $shape . ' tighter maxLength: the branch\'s' => [$shape, ['type' => 'string', 'maxLength' => 2], '{"name":"abc","tag":"a"}'];
            // Two patterns have no stricter one; both are kept and both must match.
            yield $shape . ' second pattern: the branch\'s' => [$shape, ['type' => 'string', 'pattern' => '^a'], '{"name":"bc","tag":"a"}'];
            yield $shape . ' second pattern: the parent\'s' => [$shape, ['type' => 'string', 'pattern' => '^a'], '{"name":"aB","tag":"a"}'];
        }
    }

    /**
     * The OAS polymorphism pattern: a base with a `discriminator` and no `mapping`, its variants each
     * `allOf` it. The implicit value is each variant's schema name, as for a `oneOf` of `$ref`s — but
     * the implicit map was built only from the base's own union members, which an `allOf` base does not
     * have, and generation stopped with "Discriminator mapping must be a non-empty map". The explicit
     * mapping is the control: the same document, spelled out, must give the same verdicts.
     *
     * @param string $invalidJson a payload the variant the discriminator names refuses
     */
    #[DataProvider('implicitAllOfDiscriminatorProvider')]
    public function testAnAllOfBaseWithoutMappingSelectsItsVariantsByName(bool $explicit, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => self::animalSchemas($explicit) + [
                'Probe' => ['type' => 'object', 'required' => ['pet'], 'properties' => ['pet' => ['$ref' => '#/components/schemas/Animal']]],
            ]],
        ];
        $key = 'allOf discriminator ' . ($explicit ? 'explicit' : 'implicit') . ' ' . $invalidJson;

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function implicitAllOfDiscriminatorProvider(): iterable
    {
        foreach (['explicit mapping' => true, 'no mapping' => false] as $spelling => $explicit) {
            yield $spelling . ': a cat' => [$explicit, '{"pet":{"kind":"Cat","name":"Tom","purr":true}}', '{"pet":{"kind":"Cat","purr":"loud"}}'];
            yield $spelling . ': a dog' => [$explicit, '{"pet":{"kind":"Dog","bark":true}}', '{"pet":{"kind":"Dog","bark":"loud"}}'];
            yield $spelling . ': the base\'s own rule' => [$explicit, '{"pet":{"kind":"Cat","name":"Tom"}}', '{"pet":{"kind":"Cat","name":5}}'];
        }
    }

    /**
     * The OAS `Pet`/`Cat`/`Dog` shape, with or without the mapping it implies.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function animalSchemas(bool $explicitMapping): array
    {
        $discriminator = ['propertyName' => 'kind'];
        if ($explicitMapping) {
            $discriminator['mapping'] = ['Cat' => '#/components/schemas/Cat', 'Dog' => '#/components/schemas/Dog'];
        }

        return [
            'Animal' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string']],
                'discriminator' => $discriminator,
            ],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['type' => 'object', 'properties' => ['purr' => ['type' => 'boolean']]]]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Animal'], ['type' => 'object', 'properties' => ['bark' => ['type' => 'boolean']]]]],
        ];
    }

    /**
     * A property whose union names generated classes holds one of them once it is read, and that
     * object's own rules are checked by the object, not by the property. Symfony looked for a single
     * class name in `Alpha|Beta`, found none, and left the `#[Assert\Valid]` cascade off, so `{"a":"x"}`
     * went through against `minLength: 2` while runtime refused it. The list and the map of the same
     * union already held — they are pinned beside it so the fix cannot trade one for the other.
     *
     * @param array<string, mixed> $property the schema of `f`
     * @param array<int, string> $invalidJsons
     */
    #[DataProvider('unionOfClassesProvider')]
    public function testAUnionOfClassesChecksTheBranchItHolds(array $property, string $validJson, array $invalidJsons): void
    {
        $spec = self::probeSpec($property);
        $spec['components']['schemas'] += self::unionMemberSchemas();

        $this->assertHydratingModesRefuse(spec: $spec, key: 'union of classes ' . md5((string)json_encode($property)), validJsons: [$validJson], invalidJsons: $invalidJsons);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<int, string>}>
     */
    public static function unionOfClassesProvider(): iterable
    {
        $alpha = ['$ref' => '#/components/schemas/Alpha'];
        $beta = ['$ref' => '#/components/schemas/Beta'];

        foreach (['oneOf', 'anyOf'] as $keyword) {
            yield $keyword . ' of two classes' => [[$keyword => [$alpha, $beta]], '{"f":{"a":"xy"}}', ['{"f":{"a":"x"}}', '{"f":{"b":5}}']];
            yield $keyword . ' of two classes or null' => [[$keyword => [$alpha, $beta, ['type' => 'null']]], '{"f":null}', ['{"f":{"a":"x"}}', '{"f":{"b":5}}']];
            yield $keyword . ' of classes in a list' => [['type' => 'array', 'items' => [$keyword => [$alpha, $beta]]], '{"f":[{"b":11}]}', ['{"f":[{"a":"x"}]}', '{"f":[{"b":5}]}']];
        }
        yield 'a discriminated oneOf' => [
            ['oneOf' => [$alpha, $beta], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['alpha' => '#/components/schemas/Alpha', 'beta' => '#/components/schemas/Beta']]],
            '{"f":{"kind":"beta","b":11}}',
            ['{"f":{"kind":"alpha","a":"x"}}', '{"f":{"kind":"beta","b":5}}'],
        ];
        yield 'control: a single class' => [$alpha, '{"f":{"a":"xy"}}', ['{"f":{"a":"x"}}']];
    }

    /**
     * A union of scalar branches beside a class branch. The class branch extracted to nothing, and an
     * `anyOf` holding a branch the validator cannot read is dropped whole — so the scalar bounds went
     * with it, and `"abcd"` passed `maxLength: 3` in Symfony and in runtime, for `oneOf` as well there.
     * Every branch keeps its rules now: the scalar ones are checked as values, the class one as "is that
     * class", which checked itself — by its cascade in Symfony, while it was hydrated in runtime.
     *
     * @param array<int, string> $invalidJsons
     * @param array<int, GenerationMode> $modes
     */
    #[DataProvider('scalarBesideClassProvider')]
    public function testScalarBranchesBesideAClassBranchKeepTheirRules(string $keyword, array $invalidJsons, array $modes): void
    {
        $spec = self::probeSpec([$keyword => [
            ['type' => 'string', 'maxLength' => 3],
            ['type' => 'integer', 'minimum' => 5],
            ['$ref' => '#/components/schemas/Alpha'],
        ]]);
        $spec['components']['schemas'] += self::unionMemberSchemas();

        $this->assertHydratingModesRefuse(
            spec: $spec,
            key: 'scalar beside class ' . $keyword,
            validJsons: ['{"f":"ab"}', '{"f":7}', '{"f":{"a":"xy"}}'],
            invalidJsons: $invalidJsons,
            modes: $modes,
        );
    }

    /**
     * @return iterable<string, array{string, array<int, string>, array<int, GenerationMode>}>
     */
    public static function scalarBesideClassProvider(): iterable
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            yield $keyword . ': the class branch' => [$keyword, ['{"f":{"a":"x"}}'], [GenerationMode::Runtime, GenerationMode::Symfony]];
            yield $keyword . ': the scalar branches' => [$keyword, ['{"f":"abcd"}', '{"f":1}'], [GenerationMode::Runtime, GenerationMode::Symfony]];
        }
    }

    /**
     * The same union one level down, where the class branch meets a value the runtime validator sees
     * in two states. As a list item it is hydrated, so the class is judged like the property above. As
     * a key the schema does not declare it stays the raw payload, which cannot be told from the class:
     * the union is then not judged for an object, and a scalar is still held to the scalar branches.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<int, string> $validJsons
     * @param array<int, string> $invalidJsons
     */
    #[DataProvider('classBranchBelowThePropertyProvider')]
    public function testClassBranchBelowThePropertyKeepsTheScalarRules(array $propertySchema, array $validJsons, array $invalidJsons): void
    {
        $spec = self::probeSpec($propertySchema);
        $spec['components']['schemas'] += self::unionMemberSchemas();

        $this->assertHydratingModesRefuse(
            spec: $spec,
            key: 'class branch below ' . md5((string)json_encode($propertySchema)),
            validJsons: $validJsons,
            invalidJsons: $invalidJsons,
            modes: [GenerationMode::Runtime],
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<int, string>, array<int, string>}>
     */
    public static function classBranchBelowThePropertyProvider(): iterable
    {
        $union = static fn(string $keyword): array => [$keyword => [
            ['type' => 'string', 'maxLength' => 3],
            ['$ref' => '#/components/schemas/Alpha'],
        ]];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            yield $keyword . ': list items' => [
                ['type' => 'array', 'items' => $union($keyword)],
                ['{"f":["ab",{"a":"xy"}]}'],
                ['{"f":["abcd"]}', '{"f":[{"a":"x"}]}'],
            ];
            yield $keyword . ': map values' => [
                ['type' => 'object', 'additionalProperties' => $union($keyword)],
                ['{"f":{"x":"ab","y":{"a":"xy"}}}'],
                ['{"f":{"x":"abcd"}}'],
            ];
        }
    }

    /**
     * A string enum beside a string branch the enum's own values do not satisfy: `red` is the enum's,
     * `123` the pattern's. Runtime holds the enum as a case, and the string branch took the case for a
     * string while skipping its `pattern` — `red` matched two `oneOf` branches and was refused.
     *
     * @param array<string, mixed> $stringBranch
     */
    #[DataProvider('enumBesideAConstrainedStringProvider')]
    public function testAnEnumCaseIsJudgedByItsValueInAStringBranch(
        string $keyword,
        array $stringBranch,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec([$keyword => [
            ['$ref' => '#/components/schemas/Tint'],
            $stringBranch,
            ['type' => 'integer'],
        ]]);
        $spec['components']['schemas']['Tint'] = ['type' => 'string', 'enum' => ['red', 'blue']];
        $key = sprintf('enum beside a string branch %s %s %s', $keyword, json_encode($stringBranch), $validJson);

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, string}>
     */
    public static function enumBesideAConstrainedStringProvider(): iterable
    {
        $digits = ['type' => 'string', 'pattern' => '^\d+$'];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            yield $keyword . ': the enum, pattern' => [$keyword, $digits, '{"f":"red"}', '{"f":"abc"}'];
            yield $keyword . ': the other case, pattern' => [$keyword, $digits, '{"f":"blue"}', '{"f":true}'];
            yield $keyword . ': the pattern' => [$keyword, $digits, '{"f":"123"}', '{"f":"12a"}'];
            yield $keyword . ': the integer' => [$keyword, $digits, '{"f":42}', '{"f":null}'];
            yield $keyword . ': the enum, length' => [$keyword, ['type' => 'string', 'minLength' => 5], '{"f":"blue"}', '{"f":"abc"}'];
            yield $keyword . ': the string, length' => [$keyword, ['type' => 'string', 'minLength' => 5], '{"f":"longer"}', '{"f":"abcd"}'];
        }
    }

    /**
     * A `$ref` to an enum beside a scalar branch. Runtime hydrates the enum, so the class branch holds
     * the case and the scalar branch the integer; a wire value that is neither is refused.
     */
    #[DataProvider('enumBranchBesideScalarProvider')]
    public function testEnumBranchBesideAScalarBranchKeepsBoth(string $keyword): void
    {
        $spec = self::probeSpec([$keyword => [
            ['$ref' => '#/components/schemas/Hue'],
            ['type' => 'integer', 'minimum' => 5],
        ]]);
        $spec['components']['schemas']['Hue'] = ['type' => 'string', 'enum' => ['red', 'teal']];

        $this->assertHydratingModesRefuse(
            spec: $spec,
            key: 'enum beside scalar ' . $keyword,
            validJsons: ['{"f":"red"}', '{"f":7}'],
            invalidJsons: ['{"f":"blue"}', '{"f":1}'],
            // Symfony dropped the whole `anyOf` once and accepted `1`: its branch builder knew a DTO
            // branch but not an enum one. laravel-data accepted `"blue"` in both spellings: its enum
            // branch was checked as a string and nothing more.
            modes: GenerationMode::cases(),
        );
    }

    /**
     * The mirror image of the case above, and its optional spelling: an integer-backed enum beside a
     * string branch, and a string-backed one on a property that may be absent. laravel-data's constructor
     * declared the union as the document spelled it, without the enum's backing type, so a member value
     * was a TypeError before the constructor could turn it into the case.
     */
    #[DataProvider('enumBranchBesideScalarProvider')]
    public function testEnumBranchOfEitherBackingKeepsBoth(string $keyword): void
    {
        $spec = self::probeSpec([$keyword => [
            ['$ref' => '#/components/schemas/Level'],
            ['type' => 'string', 'maxLength' => 3],
        ]]);
        $spec['components']['schemas']['Level'] = ['type' => 'integer', 'enum' => [10, 20]];
        $spec['components']['schemas']['Probe']['properties']['g'] = [$keyword => [
            ['$ref' => '#/components/schemas/Hue'],
            ['type' => 'integer', 'minimum' => 5],
        ]];
        $spec['components']['schemas']['Hue'] = ['type' => 'string', 'enum' => ['red', 'teal']];

        $this->assertHydratingModesRefuse(
            spec: $spec,
            key: 'enum of either backing ' . $keyword,
            validJsons: ['{"f":20}', '{"f":"ab"}', '{"f":10,"g":"teal"}', '{"f":"ab","g":9}'],
            invalidJsons: ['{"f":30}', '{"f":"abcd"}', '{"f":10,"g":"blue"}', '{"f":10,"g":2}'],
            modes: GenerationMode::cases(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function enumBranchBesideScalarProvider(): iterable
    {
        yield 'anyOf' => ['anyOf'];
        yield 'oneOf' => ['oneOf'];
    }

    /**
     * A union of ONE object branch and a scalar branch, with no discriminator. The object is a class
     * (a `$ref`) or an inline shape, and either way the verdict must be the schema's: laravel typed the
     * constructor `Shade|string` and handed it the validated array, a TypeError on every valid object;
     * laravel-data checked the object's `required` on a string; symfony asked `Type('array')` of the
     * hydrated class and lost the string to the class; yii3 checked nothing. The list branch beside a
     * boolean is the same question one keyword over: its items are part of the branch.
     *
     * @param array<string, mixed> $property the schema of `f`
     * @param array<int, string> $validJsons
     * @param array<int, string> $invalidJsons
     * @param array<string, array<int, string>> $frameworkDecides payloads a mode's framework judges before
     *     any generated check runs, keyed by mode — not measured there; README.support-matrix has the rows
     */
    #[DataProvider('objectBesideScalarProvider')]
    public function testAnObjectBranchBesideAScalarBranchIsJudgedByTheBranchItMatches(
        array $property,
        array $validJsons,
        array $invalidJsons,
        array $frameworkDecides = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Shade'] = ['type' => 'object', 'required' => ['hue'], 'properties' => ['hue' => ['type' => 'string']]];
        $spec['components']['schemas']['Loose'] = ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]];
        $key = 'object beside scalar ' . md5((string)json_encode($property));

        $expected = array_fill_keys($validJsons, true) + array_fill_keys($invalidJsons, false);
        $this->assertEveryModeYields(
            expected: $expected,
            observe: function (GenerationMode $mode) use ($spec, $key, $expected, $frameworkDecides): array {
                $observed = [];
                foreach ($expected as $json => $verdict) {
                    $observed[$json] = in_array($json, $frameworkDecides[$mode->value] ?? [], true)
                        ? $verdict
                        : $this->verdict(mode: $mode, spec: $spec, key: $key, validJson: $json, invalidJson: $json)['valid'];
                }

                return $observed;
            },
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>, 3?: array<string, array<int, string>>}>
     */
    public static function objectBesideScalarProvider(): iterable
    {
        $shade = ['$ref' => '#/components/schemas/Shade'];
        $loose = ['$ref' => '#/components/schemas/Loose'];
        $inline = ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'integer']]];

        foreach (['anyOf', 'oneOf'] as $keyword) {
            yield $keyword . ': a class or a string' => [
                [$keyword => [$shade, ['type' => 'string']]],
                ['{"f":{"hue":"x"}}', '{"f":"ok"}'],
                ['{"f":5}', '{"f":true}', '{"f":[1]}', '{"f":{}}', '{"f":{"hue":5}}'],
            ];
            // A class nothing in it requires can be built from anything. Symfony's serializer tries the
            // class member first and, on 7.4.10, builds it from a scalar as well ("a scalar for a property
            // typed as a generated class"); newer releases refuse the scalar and fall through to the
            // string. A non-empty JSON list is the decoded `{"0":1}` once the framework has decoded it
            // ("a JSON array for a `type: object` property").
            yield $keyword . ': a class with nothing required or a string' => [
                [$keyword => [$loose, ['type' => 'string']]],
                ['{"f":{"hue":"x"}}', '{"f":{}}', '{"f":"ok"}'],
                ['{"f":5}', '{"f":true}', '{"f":[1]}', '{"f":{"hue":5}}'],
                [
                    GenerationMode::Symfony->value => ['{"f":5}', '{"f":true}', '{"f":[1]}'],
                    GenerationMode::Yii3->value => ['{"f":[1]}'],
                ],
            ];
            yield $keyword . ': a class or an integer' => [
                [$keyword => [$shade, ['type' => 'integer']]],
                ['{"f":{"hue":"x"}}', '{"f":3}'],
                ['{"f":"x"}', '{"f":{}}', '{"f":true}'],
            ];
            yield $keyword . ': an inline object or a string' => [
                [$keyword => [$inline, ['type' => 'string']]],
                ['{"f":{"k":1}}', '{"f":"s"}'],
                ['{"f":5}', '{"f":{}}', '{"f":{"k":"x"}}', '{"f":true}'],
            ];
            yield $keyword . ': a list of integers or a boolean' => [
                [$keyword => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'boolean']]],
                ['{"f":[1,2]}', '{"f":[]}', '{"f":true}'],
                ['{"f":["x"]}', '{"f":"x"}', '{"f":5}'],
            ];
        }
    }

    /**
     * A component named after a class the referring file names for something else — Symfony's
     * `Context`, Laravel's `Validator`, PHP's `BackedEnum`. Both meanings sit in Probe's file, and the
     * component's own file declares the name: either the import won and the component's type was the
     * library's, or the component's file did not load. Validated and hydrated in every mode, it must
     * behave as the same component under a neutral name does.
     */
    #[DataProvider('libraryClassNameProvider')]
    public function testAComponentNamedAfterALibraryClassValidates(string $component): void
    {
        $spec = self::probeSpec(['$ref' => '#/components/schemas/' . $component]);
        $spec['components']['schemas']['Probe']['properties']['at'] = ['type' => 'string', 'format' => 'date-time'];
        $spec['components']['schemas']['Probe']['minProperties'] = 1;
        $spec['components']['schemas'][$component] = [
            'type' => 'object',
            'required' => ['at'],
            'properties' => [
                'at' => ['type' => 'string', 'format' => 'date-time'],
                'first_name' => ['type' => 'string', 'minLength' => 2],
                'tone' => ['type' => 'string', 'enum' => ['red', 'teal']],
            ],
        ];

        $this->assertHydratingModesRefuse(
            spec: $spec,
            key: 'library class name ' . $component,
            validJsons: ['{"f":{"at":"2026-03-10T12:00:00+00:00","first_name":"Ann","tone":"teal"},"at":"2026-03-10T12:00:00+03:00"}'],
            invalidJsons: [
                '{"f":{"at":"later"}}',
                '{"f":{"at":"2026-03-10T12:00:00+00:00","first_name":"A"}}',
                '{"f":{"at":"2026-03-10T12:00:00+00:00","tone":"blue"}}',
                '{"f":{"at":"2026-03-10T12:00:00+00:00"},"at":"later"}',
            ],
            modes: GenerationMode::cases(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function libraryClassNameProvider(): iterable
    {
        foreach (['Widget', 'Context', 'Ignore', 'SerializedName', 'Assert', 'BackedEnum', 'DateTimeInterface', 'Validator', 'JsonSerializable', 'FormRequest', 'Rule'] as $component) {
            yield $component => [$component];
        }
    }

    /**
     * The smallest integer has no literal of its own in PHP: `-9223372036854775808` is unary minus on
     * `9223372036854775808`, which is already a float. Emitted bare, a default of that value was a
     * float default on an `int` parameter — a fatal on load — and an enum case backed by it made the
     * int-backed enum refuse to resolve at all. Its neighbours, which do have a literal, are pinned
     * beside it.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('integerExtremeLiteralProvider')]
    public function testTheSmallestIntegerIsEmittedAsAnInteger(array $propertySchema, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => [
                'type' => 'object',
                'properties' => ['f' => $propertySchema],
            ]]],
        ];
        $key = 'integer extreme ' . md5((string)json_encode([$propertySchema, $validJson, $invalidJson]));

        // Linted before anything loads it: the bug was a compile fatal, and a fatal in `require` takes
        // the whole test run down instead of failing this case.
        foreach (GenerationMode::cases() as $mode) {
            $target = $this->outputDirectory . '/' . $this->namespaceFor($mode, $key);
            (new GenerateDtoCommand())->generateFromArray(
                openApi: $spec,
                outputDirectory: $target,
                namespace: $this->namespaceFor($mode, $key),
                mode: $mode->value,
            );
            foreach (glob($target . '/*.php') ?: [] as $file) {
                $lintOutput = [];
                exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $lintOutput, $lintStatus);
                $this->assertSame(
                    expected: 0,
                    actual: $lintStatus,
                    message: sprintf('%s: %s', $mode->value, implode("\n", $lintOutput)),
                );
            }
        }

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function integerExtremeLiteralProvider(): iterable
    {
        foreach (['smallest' => PHP_INT_MIN, 'largest' => PHP_INT_MAX, 'ordinary' => -5] as $label => $value) {
            yield $label . ': default' => [['type' => 'integer', 'default' => $value], '{}', '{"f":"x"}'];
            yield $label . ': enum case' => [
                ['type' => 'integer', 'enum' => [$value, 3]],
                sprintf('{"f":%d}', $value),
                '{"f":5}',
            ];
            yield $label . ': const' => [['type' => 'integer', 'const' => $value], sprintf('{"f":%d}', $value), '{"f":5}'];
        }
    }

    /**
     * `minProperties` counts the keys of the PAYLOAD, and a schema that lets in keys it does not declare
     * lets them count. Symfony's serializer drops an undeclared key before anything validates, so the
     * object-wide callback counted only the declared ones and refused a valid body with a 422 — `{"alpha":
     * "a","x":1}` against `minProperties: 2` and `additionalProperties: {type: integer}`. Where the
     * schema is open, the count is unknowable there; where it is closed, it is exact and still enforced.
     *
     * @param array<string, mixed> $extraKeywords
     */
    #[DataProvider('minPropertiesBesideExtraKeysProvider')]
    public function testMinPropertiesCountsTheKeysTheSchemaLetsIn(array $extraKeywords, string $validJson, string $invalidJson, array $diverges): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => [
                'type' => 'object',
                'required' => ['alpha'],
                'properties' => ['alpha' => ['type' => 'string'], 'beta' => ['type' => 'string'], 'count' => ['type' => 'integer']],
                'minProperties' => 2,
                ...$extraKeywords,
            ]]],
        ];
        $key = 'min properties ' . md5((string)json_encode([$extraKeywords, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, array<string, array{expected: mixed, reason: string}>}>
     */
    public static function minPropertiesBesideExtraKeysProvider(): iterable
    {
        $uncountable = self::diverges(
            mode: GenerationMode::Symfony,
            expected: ['valid' => true, 'invalid' => true],
            reason: 'the serializer drops an undeclared key before validation, so on a schema that admits '
                . 'such keys the count is unknowable and the lower bound is not checked — a refusal there '
                . 'would refuse valid bodies (README.symfony: unknown JSON key)',
        );
        $schemaValue = ['additionalProperties' => ['type' => 'integer']];

        yield 'an extra key a value schema admits' => [$schemaValue, '{"alpha":"a","x":1}', '{"x":1}', self::diverges(
            mode: GenerationMode::Yii3,
            expected: ['valid' => false, 'invalid' => false],
            reason: 'OPEN, not a boundary: yii3 counts the declared properties of the hydrated object and '
                . 'refuses the valid body the way Symfony did — pinned so that fixing it fails here',
        )];
        yield 'too few keys where extras are admitted' => [$schemaValue, '{"alpha":"a","beta":"b"}', '{"alpha":"a"}', $uncountable];
        yield 'control: too few keys on a closed schema' => [['additionalProperties' => false], '{"alpha":"a","beta":"b"}', '{"alpha":"a"}', []];
        yield 'control: too few keys, unevaluated closed' => [['unevaluatedProperties' => false], '{"alpha":"a","count":1}', '{"alpha":"a"}', []];
    }

    /**
     * The other ways a schema gives an undeclared key a place, measured in Symfony alone: runtime
     * accepts these bodies on the way in but its own output check then counts the extra key as gone and
     * refuses the DTO it built — a runtime question, open, and not this mode's. A schema that merely
     * does not forbid other keys keeps the bound; `testObjectWideKeywordsAreEnforcedInEveryMode` pins
     * that.
     *
     * @param array<string, mixed> $extraKeywords
     */
    #[DataProvider('symfonyOpenSchemaProvider')]
    public function testSymfonyDoesNotCountAwayAKeyTheSchemaAdmits(array $extraKeywords, string $validJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => [
                'type' => 'object',
                'required' => ['alpha'],
                'properties' => ['alpha' => ['type' => 'string'], 'beta' => ['type' => 'string']],
                'minProperties' => 2,
                ...$extraKeywords,
            ]]],
        ];

        $this->assertSame(
            expected: ['valid' => true, 'invalid' => false],
            actual: $this->verdict(
                mode: GenerationMode::Symfony,
                spec: $spec,
                key: 'symfony open schema ' . md5((string)json_encode($extraKeywords)),
                validJson: $validJson,
                invalidJson: '{"beta":"b","x1":1}',
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function symfonyOpenSchemaProvider(): iterable
    {
        yield 'an extra key any schema admits' => [['additionalProperties' => true], '{"alpha":"a","x1":"y"}'];
        yield 'an extra key a pattern admits' => [['patternProperties' => ['^x' => ['type' => 'integer']]], '{"alpha":"a","x1":1}'];
        yield 'a pattern beside a closed schema' => [['additionalProperties' => false, 'patternProperties' => ['^x' => ['type' => 'integer']]], '{"alpha":"a","x1":1}'];
    }

    /**
     * A closed component reached through a `$ref` keeps its object-wide keywords wherever it sits:
     * alone, as a list item, as a map value, beside `null` in an `anyOf`. Laravel folded such a class
     * into its `properties` and `required` only, so `maxProperties`, `dependentRequired` and
     * `additionalProperties: false` held for the class validated on its own and for nobody one level
     * up — the undeclared key was then dropped on hydration, and the output no longer matched the input.
     *
     * @param array<string, mixed> $property the schema of `f`
     * @param string $wrap how a value of the component is placed into `f`, `%s` standing for it
     */
    #[DataProvider('closedComponentPositionProvider')]
    public function testAComponentKeepsItsObjectWideKeywordsWhereverItIsReferenced(
        string $position,
        array $property,
        string $wrap,
        string $keyword,
        string $invalidValue,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Inner'] = [
            'type' => 'object',
            'required' => ['alpha'],
            'additionalProperties' => false,
            'maxProperties' => 3,
            'dependentRequired' => ['gamma' => ['delta']],
            'properties' => [
                'alpha' => ['type' => 'string'],
                'beta' => ['type' => 'string'],
                'gamma' => ['type' => 'string'],
                'delta' => ['type' => 'string'],
            ],
        ];
        $key = 'closed component ' . $position . ' ' . $keyword;
        $validJson = sprintf('{"f":%s}', sprintf($wrap, '{"alpha":"ab","gamma":"c","delta":"d"}'));
        $invalidJson = sprintf('{"f":%s}', sprintf($wrap, $invalidValue));

        // An undeclared key is invisible to the two modes that build the object before any rule runs;
        // the same boundary `testAClosedObjectRefusesAnUndeclaredKeyWhereverTheRawBodyIsReachable` names.
        // yii3 does refuse it inside a map value (measured), so only its other positions diverge.
        $diverges = [];
        if ($keyword === 'additionalProperties') {
            $diverges = self::diverges(
                mode: GenerationMode::Symfony,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the serializer denormalizes before any constraint runs, so the undeclared key is gone',
            );
            if ($position !== 'map value') {
                $diverges += self::diverges(
                    mode: GenerationMode::Yii3,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'the hydrator fills the object first, so the undeclared key has nowhere to survive',
                );
            }
        }

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, string, string}>
     */
    public static function closedComponentPositionProvider(): iterable
    {
        $ref = ['$ref' => '#/components/schemas/Inner'];
        $positions = [
            'alone' => [$ref, '%s'],
            'list item' => [['type' => 'array', 'items' => $ref], '[%s]'],
            'map value' => [['type' => 'object', 'additionalProperties' => $ref], '{"k":%s}'],
            'beside null' => [['anyOf' => [$ref, ['type' => 'null']]], '%s'],
        ];
        $invalid = [
            'additionalProperties' => '{"alpha":"ab","x":1}',
            'maxProperties' => '{"alpha":"a","beta":"b","gamma":"c","delta":"d"}',
            'dependentRequired' => '{"alpha":"a","gamma":"c"}',
        ];
        foreach ($positions as $position => [$property, $wrap]) {
            foreach ($invalid as $keyword => $value) {
                yield $position . ': ' . $keyword => [$position, $property, $wrap, $keyword, $value];
            }
        }
    }

    /**
     * A discriminated `oneOf` whose discriminator property is a plain string: the discriminator value
     * picks the variant, so a value no variant answers to is refused, and so is a payload that matches
     * one variant while naming the other. Laravel validated it as a bare `oneOf` — the discriminator
     * went nowhere — and hydration, which does dispatch on the value, then threw: a 500 for `"Cow"`,
     * and a `TypeError` for a `"Dog"` carrying a cat's fields.
     */
    #[DataProvider('plainStringDiscriminatorProvider')]
    public function testAPlainStringDiscriminatorChoosesTheVariantInEveryMode(
        bool $mapped,
        string $validJson,
        string $invalidJson,
        string $keyword = 'oneOf',
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $discriminator = ['propertyName' => 'kind'];
        if ($mapped) {
            $discriminator['mapping'] = ['c' => '#/components/schemas/Cat', 'd' => '#/components/schemas/Dog'];
        }
        $spec = self::probeSpec(['$ref' => '#/components/schemas/Animal']);
        $spec['components']['schemas'] += [
            'Cat' => [
                'type' => 'object',
                'required' => ['kind', 'lives'],
                'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']],
            ],
            'Dog' => [
                'type' => 'object',
                'required' => ['kind', 'bark'],
                'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'boolean']],
            ],
            'Animal' => [
                $keyword => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => $discriminator,
            ],
        ];
        $key = 'plain string discriminator ' . $keyword . ' ' . ($mapped ? 'mapped ' : '') . $invalidJson;

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function plainStringDiscriminatorProvider(): iterable
    {
        yield 'unknown value' => [false, '{"f":{"kind":"Cat","lives":1}}', '{"f":{"kind":"Cow","lives":1}}'];
        yield 'the other variant\'s fields' => [false, '{"f":{"kind":"Dog","bark":true}}', '{"f":{"kind":"Dog","lives":1}}'];
        // The implicit map is the schema names behind the `$ref`s whichever keyword lists them.
        yield 'anyOf: unknown value' => [false, '{"f":{"kind":"Cat","lives":1}}', '{"f":{"kind":"Cow","lives":1}}', 'anyOf'];
        yield 'anyOf mapped: unknown value' => [true, '{"f":{"kind":"c","lives":1}}', '{"f":{"kind":"zz","lives":1}}', 'anyOf'];
        yield 'mapped: unknown value' => [true, '{"f":{"kind":"c","lives":1}}', '{"f":{"kind":"zz","lives":1}}'];
        yield 'mapped: a class name is not a mapped value' => [true, '{"f":{"kind":"d","bark":false}}', '{"f":{"kind":"Cat","lives":1}}'];
        yield 'mapped: the other variant\'s fields' => [true, '{"f":{"kind":"c","lives":1}}', '{"f":{"kind":"d","lives":1}}'];
    }

    /**
     * JSON `true` is not an integer. Laravel's `integer` rule reads it through `FILTER_VALIDATE_INT`,
     * which turns it into `1`, so it passed — and then either died in the typed constructor or landed
     * in an `array<int>` as `true`. Every spelling of an integer is pinned: the plain one, a nullable
     * one, a `format`, an int-backed enum, a list, a map, a list of lists and one inside a referenced
     * class — alone, as a list item, as a map value and two classes down.
     *
     * laravel-data refuses it from `withValidator()`, reading the validator's data rather than the raw
     * body: a decoded `true` is still a boolean, so no request is needed to see it.
     *
     * yii3 refuses it through the raw `#[Validate]` each integer carries, which sees the boolean before
     * the hydrator casts it to `1`.
     *
     * @param array<string, mixed> $property the schema of `f`
     */
    #[DataProvider('booleanForIntegerProvider')]
    public function testABooleanIsNotAnIntegerInEveryMode(array $property, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas'] += [
            'Level' => ['type' => 'integer', 'enum' => [1, 2]],
            'Inner' => ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'integer']]],
            'Outer' => ['type' => 'object', 'required' => ['inner'], 'properties' => ['inner' => ['$ref' => '#/components/schemas/Inner']]],
        ];
        $key = 'boolean for integer ' . $invalidJson . ' ' . md5((string)json_encode($property));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function booleanForIntegerProvider(): iterable
    {
        yield 'integer true' => [['type' => 'integer'], '{"f":1}', '{"f":true}'];
        yield 'integer false' => [['type' => 'integer'], '{"f":0}', '{"f":false}'];
        yield 'nullable integer' => [['type' => ['integer', 'null']], '{"f":null}', '{"f":true}'];
        yield 'int64' => [['type' => 'integer', 'format' => 'int64'], '{"f":5}', '{"f":true}'];
        yield 'number' => [['type' => 'number'], '{"f":1.5}', '{"f":true}'];
        yield 'int-backed enum' => [['$ref' => '#/components/schemas/Level'], '{"f":1}', '{"f":true}'];
        yield 'list of integers' => [['type' => 'array', 'items' => ['type' => 'integer']], '{"f":[1]}', '{"f":[true]}'];
        yield 'map of integers' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], '{"f":{"a":1}}', '{"f":{"a":true}}'];
        yield 'integer of a referenced class' => [['$ref' => '#/components/schemas/Inner'], '{"f":{"n":1}}', '{"f":{"n":true}}'];
        yield 'integer of a list item class' => [['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']], '{"f":[{"n":1}]}', '{"f":[{"n":true}]}'];
        yield 'integer of a map value class' => [['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Inner']], '{"f":{"a":{"n":1}}}', '{"f":{"a":{"n":true}}}'];
        yield 'integer two classes deep' => [['$ref' => '#/components/schemas/Outer'], '{"f":{"inner":{"n":1}}}', '{"f":{"inner":{"n":true}}}'];
        yield 'list of lists of integers' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]], '{"f":[[1]]}', '{"f":[[true]]}'];
    }

    /**
     * An `additionalProperties` SCHEMA beside declared properties: the undeclared keys must match it. The
     * rule-based modes carried `additionalProperties: false` into the object slot and dropped the schema
     * form, so `{"a":"x","n":"s"}` passed where `n` must be an integer — at the root, behind a `$ref`
     * and in a list of them alike. A plain map (no declared properties) was already checked and is
     * pinned beside them.
     *
     * @param array<string, mixed>|null $property the schema of `f`, or null to put `Colors` at the root
     */
    #[DataProvider('additionalPropertiesSchemaProvider')]
    public function testAnAdditionalPropertiesSchemaIsEnforcedInEveryMode(?array $property, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $colors = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'integer'],
        ];
        $spec = $property === null ? self::probeSpec([]) : self::probeSpec($property);
        if ($property === null) {
            $spec['components']['schemas']['Probe'] = $colors;
        }
        $spec['components']['schemas']['Colors'] = $colors;
        $key = 'additionalProperties schema ' . $invalidJson . ' ' . md5((string)json_encode($property));

        // Both hydrating modes bind the payload into a typed object before anything validates it, and an
        // undeclared key has nowhere to go there — the same boundary as `additionalProperties: false`.
        $hydrated = $property === null || ($property['type'] ?? null) !== 'object';
        $diverges = $hydrated ? [
            ...self::diverges(
                mode: GenerationMode::Symfony,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the serializer denormalizes before any constraint runs, so the undeclared key is already gone',
            ),
            ...self::diverges(
                mode: GenerationMode::Yii3,
                expected: ['valid' => true, 'invalid' => true],
                reason: 'the hydrator fills the object first, so the undeclared key has nowhere to survive',
            ),
        ] : [];

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, string, string}>
     */
    public static function additionalPropertiesSchemaProvider(): iterable
    {
        $colors = ['$ref' => '#/components/schemas/Colors'];

        yield 'at the root' => [null, '{"name":"x","red":5}', '{"name":"x","red":"s"}'];
        yield 'behind a $ref' => [$colors, '{"f":{"name":"x","red":5}}', '{"f":{"name":"x","red":"s"}}'];
        yield 'in a list of them' => [['type' => 'array', 'items' => $colors], '{"f":[{"name":"x","red":5}]}', '{"f":[{"name":"x","red":"s"}]}'];
        yield 'a plain map' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], '{"f":{"red":5}}', '{"f":{"red":"s"}}'];
    }

    /**
     * A property that is a discriminated union OR null. Each spelling put `nullable` beside the folded
     * union in the rule-based modes, and the union check reads nullability only from its own branches,
     * so `{"f":null}` was refused with "does not match any oneOf branch" — and every other mode accepts
     * it. The invalid payload is a member missing its required field, so a fix that stopped checking
     * the union would fail here too.
     *
     * @param array<string, mixed> $property the schema of `f`
     * @param array<string, array{expected: array{valid: bool, invalid: bool}, reason: string}> $diverges
     */
    #[DataProvider('nullableUnionProvider')]
    public function testANullableDiscriminatedUnionAdmitsNullInEveryMode(array $property, string $validJson, string $invalidJson, array $diverges = []): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas'] += [
            // The union sorts before its members: the parity harness loads the generated files in
            // name order, and a member needs the interface it implements already declared.
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => ['c' => '#/components/schemas/Cat', 'd' => '#/components/schemas/Dog'],
                ],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['kind', 'size'], 'properties' => ['kind' => ['type' => 'string'], 'size' => ['type' => 'number']]],
        ];
        $key = 'nullable union ' . $validJson . ' ' . md5((string)json_encode($property));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3?: array<string, array{expected: array{valid: bool, invalid: bool}, reason: string}>}>
     */
    public static function nullableUnionProvider(): iterable
    {
        $animal = ['$ref' => '#/components/schemas/Animal'];
        $broken = '{"f":{"kind":"c"}}';

        yield 'oneOf with a null branch' => [['oneOf' => [$animal, ['type' => 'null']]], '{"f":null}', $broken];
        yield 'anyOf with a null branch' => [['anyOf' => [$animal, ['type' => 'null']]], '{"f":null}', $broken];
        yield 'null branch first' => [['oneOf' => [['type' => 'null'], $animal]], '{"f":null}', $broken];
        yield 'an OAS 3.0 nullable branch' => [['oneOf' => [$animal, ['nullable' => true]]], '{"f":null}', $broken];
        yield 'allOf with nullable' => [['allOf' => [$animal], 'nullable' => true], '{"f":null}', $broken];
        yield '$ref with nullable' => [[...$animal, 'nullable' => true], '{"f":null}', $broken];
        // The member is still checked once null is let through.
        yield 'oneOf with a null branch, a member' => [['oneOf' => [$animal, ['type' => 'null']]], '{"f":{"kind":"c","lives":9}}', $broken];
        yield 'oneOf with a null branch, an unknown value' => [['oneOf' => [$animal, ['type' => 'null']]], '{"f":{"kind":"d","size":2}}', '{"f":{"kind":"zz","size":2}}'];
        // Neighbours: without a null anywhere, null stays refused — and a plain object with a null branch
        // already admitted it.
        yield 'no null anywhere' => [$animal, '{"f":{"kind":"d","size":2}}', '{"f":null}'];
        yield 'a plain object or null' => [['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['type' => 'null']]], '{"f":null}', $broken];
    }

    /**
     * A value the yii3 hydrator cannot hold as sent: it casts to the declared type before any rule
     * runs, or leaves the property unset when no cast fits, so only the raw `#[Validate]` checks see
     * what the payload said. Each case is a shape where that check was missing or answered wrong — an
     * optional enum or object given null, an integer read as a Unix timestamp for a date, a union of
     * scalars cast to whichever member took the value. Every other mode refuses these from the payload
     * itself. A date inside a union is pinned in yii3's own suite (`Yii3RawValueCheckTest`): the other
     * modes do not agree with one another on those yet.
     *
     * @param array<string, mixed> $property the schema of `f`
     */
    #[DataProvider('rawValueProvider')]
    public function testAValueTheHydratorWouldCastIsJudgedAsSentInEveryMode(
        array $property,
        bool $required,
        string $validJson,
        string $invalidJson,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas']['Probe']['required'] = $required ? ['f'] : [];
        $spec['components']['schemas'] += [
            'Color' => ['type' => 'string', 'enum' => ['red', 'blue']],
            'Widget' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        ];
        $key = 'raw value ' . ($required ? 'required ' : 'optional ') . $invalidJson . ' ' . md5((string)json_encode($property));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, string, string}>
     */
    public static function rawValueProvider(): iterable
    {
        $inlineEnum = ['type' => 'string', 'enum' => ['a', 'b']];
        $color = ['$ref' => '#/components/schemas/Color'];
        $widget = ['$ref' => '#/components/schemas/Widget'];
        $date = ['type' => 'string', 'format' => 'date'];
        $dateTime = ['type' => 'string', 'format' => 'date-time'];

        // An enum the hydrator cannot fill is left unset; optional, that read as "not sent".
        yield 'optional inline enum, unknown value' => [$inlineEnum, false, '{"f":"a"}', '{"f":"zzz"}'];
        yield 'optional enum $ref, unknown value' => [$color, false, '{"f":"red"}', '{"f":"zzz"}'];
        yield 'required inline enum, unknown value' => [$inlineEnum, true, '{"f":"b"}', '{"f":"zzz"}'];
        // Null for an optional property the schema does not let be null.
        yield 'optional object $ref, null' => [$widget, false, '{"f":{"id":1}}', '{"f":null}'];
        yield 'optional enum $ref, null' => [$color, false, '{"f":"blue"}', '{"f":null}'];
        yield 'optional inline enum, null' => [$inlineEnum, false, '{}', '{"f":null}'];
        yield 'optional string, null' => [['type' => 'string'], false, '{"f":"x"}', '{"f":null}'];
        yield 'optional nullable object $ref, null is fine' => [['oneOf' => [$widget, ['type' => 'null']]], false, '{"f":null}', '{"f":{"id":"x"}}'];
        yield 'optional nullable enum $ref, null is fine' => [[...$color, 'nullable' => true], false, '{"f":null}', '{"f":"zzz"}'];
        // An integer is not a date string, though the yii3 rule and the hydrator both take it as a timestamp.
        yield 'date given an integer' => [$date, true, '{"f":"2024-01-02"}', '{"f":20240102}'];
        yield 'optional date given an integer' => [$date, false, '{"f":"2024-01-02"}', '{"f":5}'];
        yield 'date-time given an integer' => [$dateTime, true, '{"f":"2024-01-02T03:04:05Z"}', '{"f":5}'];
        yield 'date given nonsense' => [$date, true, '{"f":"2024-01-02"}', '{"f":"nonsense"}'];
        // A union of scalars: the hydrator cast the value to one member, so nothing was refused.
        $numberOrBool = ['anyOf' => [['type' => 'number'], ['type' => 'boolean']]];
        yield 'number or boolean, a word' => [$numberOrBool, true, '{"f":1.5}', '{"f":"x"}'];
        yield 'number or boolean, a list' => [$numberOrBool, true, '{"f":true}', '{"f":[1]}'];
        yield 'number or boolean, an object' => [$numberOrBool, true, '{"f":2}', '{"f":{"a":1}}'];
        yield 'type list integer or boolean, a word' => [['type' => ['integer', 'boolean']], true, '{"f":3}', '{"f":"x"}'];
        yield 'type list integer or boolean, a fraction' => [['type' => ['integer', 'boolean']], true, '{"f":false}', '{"f":1.5}'];
        yield 'string or integer, a fraction' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], true, '{"f":"x"}', '{"f":1.5}'];
        yield 'optional number or boolean, a word' => [$numberOrBool, false, '{}', '{"f":"x"}'];
    }

    /**
     * A composed or discriminated component two containers deep. The plain-object component at that
     * depth was already folded into the rules; an `allOf` child or a discriminated union was not, so a
     * list of lists of them validated nothing below the inner list.
     *
     * @param array<string, mixed> $property the schema of `f`
     * @param array<int, GenerationMode> $divergent the modes that still accept the invalid payload, each for the reason below
     */
    #[DataProvider('deepCompositionProvider')]
    public function testACompositionTwoContainersDeepIsEnforcedInEveryMode(array $property, string $validJson, string $invalidJson, array $divergent = []): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($property);
        $spec['components']['schemas'] += [
            'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            'Shape' => ['allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                ['type' => 'object', 'required' => ['side'], 'properties' => ['side' => ['type' => 'number']]],
            ]],
            'Cat' => ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['cat']], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['kind', 'bark'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['dog']], 'bark' => ['type' => 'boolean']]],
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']],
            ],
            'Plain' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        ];
        $key = 'composition two deep ' . $invalidJson . ' ' . md5((string)json_encode($property));
        $reasons = [
            GenerationMode::Symfony->value => 'OPEN GAP, not a boundary: the serializer builds the variant objects '
                . 'two containers down through the discriminator map, and nothing checks them there; inlining the '
                . 'union instead refused valid payloads, its branches reading the objects\' private state as missing',
        ];
        $diverges = [];
        foreach ($divergent as $mode) {
            $diverges += self::diverges($mode, ['valid' => true, 'invalid' => true], $reasons[$mode->value]);
        }

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            diverges: $diverges,
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3?: array<int, GenerationMode>}>
     */
    public static function deepCompositionProvider(): iterable
    {
        $listOf = static fn(array $items): array => ['type' => 'array', 'items' => $items];
        $mapOf = static fn(array $values): array => ['type' => 'object', 'additionalProperties' => $values];
        $shape = ['$ref' => '#/components/schemas/Shape'];
        $animal = ['$ref' => '#/components/schemas/Animal'];
        $plain = ['$ref' => '#/components/schemas/Plain'];

        yield 'control: plain list of lists' => [$listOf($listOf($plain)), '{"f":[[{"id":1}]]}', '{"f":[[{"id":"x"}]]}'];
        yield 'control: allOf one list deep' => [$listOf($shape), '{"f":[{"id":1,"side":2}]}', '{"f":[{"id":"x","side":2}]}'];
        yield 'allOf list of lists: parent property' => [$listOf($listOf($shape)), '{"f":[[{"id":1,"side":2}]]}', '{"f":[[{"id":"x","side":2}]]}'];
        yield 'allOf list of lists: own property' => [$listOf($listOf($shape)), '{"f":[[{"id":1,"side":2}]]}', '{"f":[[{"id":1}]]}'];
        yield 'allOf list of lists: a scalar item' => [$listOf($listOf($shape)), '{"f":[[{"id":1,"side":2}]]}', '{"f":[["str"]]}'];
        yield 'allOf map of lists' => [$mapOf($listOf($shape)), '{"f":{"a":[{"id":1,"side":2}]}}', '{"f":{"a":[{"id":"x","side":2}]}}'];
        yield 'allOf list of maps' => [$listOf($mapOf($shape)), '{"f":[{"a":{"id":1,"side":2}}]}', '{"f":[{"a":{"id":"x"}}]}'];
        yield 'union list of lists' => [$listOf($listOf($animal)), '{"f":[[{"kind":"cat","lives":9}]]}', '{"f":[[{"kind":"cat","lives":"x"}]]}', [GenerationMode::Symfony]];
        yield 'union map of lists' => [$mapOf($listOf($animal)), '{"f":{"a":[{"kind":"dog","bark":true}]}}', '{"f":{"a":[{"kind":"dog"}]}}', [GenerationMode::Symfony]];
    }

    /**
     * A document pattern the regex engine gives up on. `preg_match()` returns false once a subject
     * exhausts the backtrack limit or the JIT stack, and every place that read false as "did not
     * match" opened a door: a `patternProperties` key slipped past its schema, `not: {pattern}` and
     * `if: {pattern}` flipped, and a pattern run over ten thousand items held a worker for twenty
     * seconds. The attack must be refused, and quickly — the clock starts after a warm-up run, so it
     * measures the request rather than the generator.
     *
     * The neighbours in the provider are ordinary mismatches of the same shapes: they were refused
     * before and must stay refused, for the same reason.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('regexEngineLimitProvider')]
    public function testAPatternTheEngineGivesUpOnRefusesQuickly(array $propertySchema, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $key = 'regex engine limit ' . md5((string)json_encode([$propertySchema, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false, 'quick' => true],
            observe: fn(GenerationMode $mode): array => $this->timedVerdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function regexEngineLimitProvider(): iterable
    {
        // A long run of letters is a JIT-stack error for this alternation inside a repeat.
        $letters = '^(?:[a-z]|_)+$';
        $percent = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];
        $longKey = str_repeat('a', 20000);
        yield 'patternProperties: a key the engine gives up on' => [
            ['type' => 'object', 'patternProperties' => [$letters => $percent]],
            '{"f":{"max_items":5}}',
            (string)json_encode(['f' => [$longKey => -999999]]),
        ];
        yield 'patternProperties: a short key out of range' => [
            ['type' => 'object', 'patternProperties' => [$letters => $percent]],
            '{"f":{"max_items":5}}',
            '{"f":{"max_items":999999}}',
        ];

        // Nested quantifiers: thirty letters and a stray character exhaust the backtrack limit.
        $nested = '^(?:(a+)+b|a+)$';
        yield 'patternProperties: a backtracking key' => [
            ['type' => 'object', 'patternProperties' => [$nested => $percent]],
            '{"f":{"aaa":5}}',
            (string)json_encode(['f' => [str_repeat('a', 30) . '!' => -5]]),
        ];
        $manyKeys = [];
        for ($index = 0; $index < 20000; $index++) {
            $manyKeys[str_repeat('a', 30) . '!' . $index] = 1;
        }
        yield 'patternProperties: twenty thousand backtracking keys' => [
            ['type' => 'object', 'patternProperties' => [$nested => $percent]],
            '{"f":{"aaa":5}}',
            (string)json_encode(['f' => $manyKeys]),
        ];

        $tag = '(?:<|%3[cC])(?:[a-z]|\s)*script';
        $hidden = '<' . str_repeat('a', 20000) . 'script>alert(1)</script>';
        yield 'not pattern: a value the engine gives up on' => [
            ['type' => 'string', 'maxLength' => 100000, 'not' => ['pattern' => $tag]],
            '{"f":"hello"}',
            (string)json_encode(['f' => $hidden]),
        ];
        yield 'not pattern: a short forbidden value' => [
            ['type' => 'string', 'maxLength' => 100000, 'not' => ['pattern' => $tag]],
            '{"f":"hello"}',
            '{"f":"<aaascript>"}',
        ];
        yield 'if pattern: a value the engine gives up on' => [
            ['type' => 'string', 'maxLength' => 100000, 'if' => ['pattern' => $tag], 'then' => ['maxLength' => 0]],
            '{"f":"hello"}',
            (string)json_encode(['f' => $hidden]),
        ];
        yield 'if pattern: a short value that takes then' => [
            ['type' => 'string', 'maxLength' => 100000, 'if' => ['pattern' => $tag], 'then' => ['maxLength' => 0]],
            '{"f":"hello"}',
            '{"f":"<aaascript>"}',
        ];
        yield 'propertyNames not pattern: a key the engine gives up on' => [
            ['type' => 'object', 'propertyNames' => ['not' => ['pattern' => $tag]]],
            '{"f":{"hello":1}}',
            (string)json_encode(['f' => [$hidden => 1]]),
        ];
        yield 'propertyNames not pattern: a short forbidden key' => [
            ['type' => 'object', 'propertyNames' => ['not' => ['pattern' => $tag]]],
            '{"f":{"hello":1}}',
            '{"f":{"<script>":1}}',
        ];

        yield 'pattern under maxLength: a long backtracking value' => [
            ['type' => 'string', 'maxLength' => 64, 'pattern' => '^(\w+\s?)*$'],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => str_repeat('a', 5000) . '!']),
        ];
        yield 'pattern: a short backtracking value' => [
            ['type' => 'string', 'maxLength' => 64, 'pattern' => '^(\w+\s?)*$'],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => str_repeat('a', 30) . '!']),
        ];
    }

    /**
     * The same attack spread over a long list: one engine failure must end the walk, and a list over
     * its `maxItems` must not be walked at all.
     *
     * Every spelling of "a pattern on each member" is listed — items, items of items, map values, the
     * properties of inline and of `$ref` items — because the Laravel modes reach each of them through a
     * different wildcard path (`f.*`, `f.*.*`, `f.*.name`). A native `regex:` rule on such a path is
     * applied by Laravel to every member with no way for one member to stop the others, so those
     * patterns belong to the interpreter there, whose walk stops at the first engine failure. The
     * attack on a list of OBJECTS is in the next test, which says why it is not asked of every mode.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('regexEngineLimitOverManyItemsProvider')]
    public function testAPatternTheEngineGivesUpOnStopsTheListWalk(array $spec, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $key = 'regex engine limit over items ' . md5((string)json_encode([$spec, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false, 'quick' => true],
            observe: fn(GenerationMode $mode): array => $this->timedVerdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function regexEngineLimitOverManyItemsProvider(): iterable
    {
        // A common "words" pattern; each item below burns the whole backtrack limit.
        $words = ['type' => 'string', 'maxLength' => 64, 'pattern' => '^([a-zA-Z0-9]+\s?)*$'];
        $burning = str_repeat('a', 26) . '!';
        $burningList = array_fill(start_index: 0, count: 10000, value: $burning);
        $named = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => $words]];
        $withWidget = static function (array $propertySchema) use ($named): array {
            $spec = self::probeSpec($propertySchema);
            $spec['components']['schemas']['Widget'] = $named;

            return $spec;
        };
        $widgets = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Widget']];

        yield 'items pattern: ten thousand backtracking items' => [
            self::probeSpec(['type' => 'array', 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            (string)json_encode(['f' => $burningList]),
        ];
        yield 'items pattern: one short mismatch' => [
            self::probeSpec(['type' => 'array', 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            '{"f":["alpha","beta!"]}',
        ];
        yield 'items pattern under maxItems: ten thousand backtracking items' => [
            self::probeSpec(['type' => 'array', 'maxItems' => 20, 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            (string)json_encode(['f' => $burningList]),
        ];
        yield 'items pattern under maxItems: too many good items' => [
            self::probeSpec(['type' => 'array', 'maxItems' => 2, 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            '{"f":["alpha","beta","gamma"]}',
        ];
        yield 'items pattern: an item over its maxLength' => [
            self::probeSpec(['type' => 'array', 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            (string)json_encode(['f' => ['alpha', str_repeat('b', 65)]]),
        ];
        yield 'items pattern: an item of the wrong type' => [
            self::probeSpec(['type' => 'array', 'items' => $words]),
            '{"f":["alpha beta","gamma"]}',
            '{"f":["alpha",7]}',
        ];
        yield 'nested items pattern: ten thousand backtracking items' => [
            self::probeSpec(['type' => 'array', 'items' => ['type' => 'array', 'items' => $words]]),
            '{"f":[["alpha beta"],["gamma"]]}',
            (string)json_encode(['f' => [$burningList]]),
        ];
        yield 'nested items pattern: one short mismatch' => [
            self::probeSpec(['type' => 'array', 'items' => ['type' => 'array', 'items' => $words]]),
            '{"f":[["alpha beta"],["gamma"]]}',
            '{"f":[["alpha"],["beta!"]]}',
        ];
        yield 'map values pattern: ten thousand backtracking values' => [
            self::probeSpec(['type' => 'object', 'additionalProperties' => $words]),
            '{"f":{"red":"alpha beta","blue":"gamma"}}',
            (string)json_encode(['f' => array_combine(
                keys: array_map(callback: static fn(int $i): string => 'k' . $i, array: range(0, 9999)),
                values: $burningList,
            )]),
        ];
        yield 'map values pattern: one short mismatch' => [
            self::probeSpec(['type' => 'object', 'additionalProperties' => $words]),
            '{"f":{"red":"alpha beta","blue":"gamma"}}',
            '{"f":{"red":"alpha","blue":"beta!"}}',
        ];
        yield 'inline object items pattern: one short mismatch' => [
            self::probeSpec(['type' => 'array', 'items' => $named]),
            '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}',
            '{"f":[{"name":"alpha"},{"name":"beta!"}]}',
        ];
        yield '$ref items pattern: one short mismatch' => [
            $withWidget($widgets),
            '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}',
            '{"f":[{"name":"alpha"},{"name":"beta!"}]}',
        ];
        yield '$ref items pattern: a missing name' => [
            $withWidget($widgets),
            '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}',
            '{"f":[{"name":"alpha"},{}]}',
        ];
        yield '$ref property pattern: one short mismatch' => [
            $withWidget(['$ref' => '#/components/schemas/Widget']),
            '{"f":{"name":"alpha beta"}}',
            '{"f":{"name":"beta!"}}',
        ];
        yield 'property pattern: one short mismatch' => [
            self::probeSpec($words),
            '{"f":"alpha beta"}',
            '{"f":"beta!"}',
        ];
    }

    /**
     * The attack on a list of objects. In the Laravel modes the member's `pattern` is folded into the
     * interpreter of the class that owns the list, so one engine failure ends the walk; runtime
     * hydrates every member as a DTO of its own, and stops at the first member whose refusal says the
     * engine gave up (`tests/Runtime/HostileRequestTest` measures it at ten thousand members and depth).
     *
     * Not Symfony and yii3: there the walk is the framework's own cascade — `#[Assert\Valid]`, yii3's
     * nested validation — which validates every member regardless, so the same request still costs a
     * backtrack limit per member (measured: ~19 s for ten thousand members). Generating code that
     * steps outside the framework for it is not on the table; the README documents the limitation.
     *
     * Three thousand members: enough that a per-member burn (~2 ms each) blows the budget, few enough
     * that Laravel's own cost of expanding `f.*.name` stays well inside it.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('regexEngineLimitOverManyObjectsProvider')]
    public function testAPatternTheEngineGivesUpOnStopsTheWalkOverObjects(array $spec, string $validJson, string $invalidJson): void
    {
        $key = 'regex engine limit over objects ' . md5((string)json_encode([$spec, $validJson, $invalidJson]));

        foreach ([GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData] as $mode) {
            $this->assertSame(
                expected: ['valid' => true, 'invalid' => false, 'quick' => true],
                actual: $this->timedVerdict(
                    mode: $mode,
                    spec: $spec,
                    key: $key,
                    validJson: $validJson,
                    invalidJson: $invalidJson,
                ),
                message: $mode->value,
            );
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function regexEngineLimitOverManyObjectsProvider(): iterable
    {
        $words = ['type' => 'string', 'maxLength' => 64, 'pattern' => '^([a-zA-Z0-9]+\s?)*$'];
        $named = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => $words]];
        $burningObjects = (string)json_encode([
            'f' => array_fill(start_index: 0, count: 3000, value: ['name' => str_repeat('a', 26) . '!']),
        ]);
        $validJson = '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}';

        yield 'inline object items' => [
            self::probeSpec(['type' => 'array', 'items' => $named]),
            $validJson,
            $burningObjects,
        ];

        $spec = self::probeSpec(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Widget']]);
        $spec['components']['schemas']['Widget'] = $named;
        yield '$ref items' => [$spec, $validJson, $burningObjects];
    }

    /**
     * An `anyOf` whose pattern branch the engine gives up on, beside a branch that would match. The
     * runtime validator dropped the "gave up" error as soon as the sibling matched, so nothing told the
     * list walk to stop, and every item paid the whole backtrack limit again (~14 s for four thousand
     * items) for a request that was then accepted — while the interpreter modes refused it at the
     * first item.
     *
     * The verdict is the one `oneOf`, `not`, `if` and `contains` already give: a branch that was run
     * and could not be finished has no answer, and the value is refused rather than waved through on
     * the strength of a sibling. That is the only reading under which the walk can stop at the first
     * item without accepting the items after it unchecked. A branch that matches EARLIER ends the
     * `anyOf` before the pattern runs at all — the neighbour pins that this costs nothing and passes.
     *
     * @param array<string, mixed> $propertySchema
     * @param array{valid: bool, invalid: bool, quick: bool} $expected
     */
    #[DataProvider('anyOfBranchTheEngineGivesUpOnProvider')]
    public function testAnAnyOfBranchTheEngineGivesUpOnRefusesQuickly(
        array $propertySchema,
        string $validJson,
        string $invalidJson,
        array $expected,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $key = 'anyOf regex engine limit ' . md5((string)json_encode([$propertySchema, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: $expected,
            observe: fn(GenerationMode $mode): array => $this->timedVerdict(
                mode: $mode,
                spec: self::probeSpec($propertySchema),
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, array{valid: bool, invalid: bool, quick: bool}}>
     */
    public static function anyOfBranchTheEngineGivesUpOnProvider(): iterable
    {
        $words = ['type' => 'string', 'pattern' => '^([a-zA-Z0-9]+\s?)*$'];
        $short = ['type' => 'string', 'maxLength' => 64];
        $burning = str_repeat('a', 26) . '!';
        $burningList = (string)json_encode(['f' => array_fill(start_index: 0, count: 3000, value: $burning)]);
        $refused = ['valid' => true, 'invalid' => false, 'quick' => true];

        yield 'items anyOf: pattern first, three thousand backtracking items' => [
            ['type' => 'array', 'items' => ['anyOf' => [$words, $short]]],
            '{"f":["alpha beta","gamma"]}',
            $burningList,
            $refused,
        ];
        // `gamma!` is a quick mismatch of the pattern that the length branch takes; the long value is
        // refused by both branches at once, without a backtrack.
        yield 'items anyOf: pattern first, ordinary values' => [
            ['type' => 'array', 'items' => ['anyOf' => [$words, $short]]],
            '{"f":["alpha beta","gamma!"]}',
            (string)json_encode(['f' => ['alpha', '!' . str_repeat('b', 65)]]),
            $refused,
        ];
        yield 'items anyOf: length first, the pattern never runs' => [
            ['type' => 'array', 'items' => ['anyOf' => [$short, $words]]],
            '{"f":["alpha beta","gamma"]}',
            $burningList,
            ['valid' => true, 'invalid' => true, 'quick' => true],
        ];
        yield 'items anyOf: pattern first, no branch can take the value' => [
            ['type' => 'array', 'items' => ['anyOf' => [$words, ['type' => 'string', 'maxLength' => 3]]]],
            '{"f":["alpha beta","gamma"]}',
            $burningList,
            $refused,
        ];
        yield 'property anyOf: pattern first, one backtracking value' => [
            ['anyOf' => [$words, $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            $refused,
        ];
        yield 'property anyOf: pattern first, ordinary values' => [
            ['anyOf' => [$words, $short]],
            '{"f":"gamma!"}',
            (string)json_encode(['f' => '!' . str_repeat('b', 65)]),
            $refused,
        ];
        yield 'property anyOf: length first, the pattern never runs' => [
            ['anyOf' => [$short, $words]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            ['valid' => true, 'invalid' => true, 'quick' => true],
        ];
        // The pattern one level down inside the branch: Symfony read only a top-level `pattern` as the
        // sign to check the anyOf in its interpreter, so these went to Assert\AtLeastOneOf with the
        // nested pattern dropped, and the sibling took the value the other modes refuse.
        yield 'property anyOf: pattern under allOf in the branch' => [
            ['anyOf' => [['type' => 'string', 'allOf' => [['pattern' => $words['pattern']]]], $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            $refused,
        ];
        yield 'property anyOf: pattern in an anyOf inside the branch' => [
            ['anyOf' => [['anyOf' => [$words, ['type' => 'string', 'maxLength' => 3]]], $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            $refused,
        ];
        yield 'property anyOf: pattern under not in the branch' => [
            ['anyOf' => [['type' => 'string', 'not' => ['pattern' => $words['pattern']]], $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            $refused,
        ];
        yield 'property anyOf: pattern under if in the branch' => [
            ['anyOf' => [['type' => 'string', 'if' => ['pattern' => $words['pattern']], 'then' => ['maxLength' => 64]], $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => $burning]),
            $refused,
        ];
        yield 'property anyOf: no pattern anywhere, the sibling still decides' => [
            ['anyOf' => [['type' => 'string', 'allOf' => [['minLength' => 40]]], $short]],
            '{"f":"alpha beta"}',
            (string)json_encode(['f' => str_repeat('b', 65) . '!']),
            ['valid' => true, 'invalid' => true, 'quick' => true],
        ];
    }

    /**
     * Whether the regex engine gave up was read from the error MESSAGE, and a message carries the
     * member names the request chose: a map key spelling the phrase made an ordinary pattern mismatch
     * look like a give-up, and the anyOf stopped before the sibling that matched. The signal is now
     * carried beside the message, so no request text can forge it.
     *
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('gaveUpPhraseInTheRequestProvider')]
    public function testAMemberNamedLikeTheGiveUpIsAnOrdinaryMismatch(array $propertySchema, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $key = 'gave up phrase ' . md5((string)json_encode([$propertySchema, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: self::probeSpec($propertySchema),
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function gaveUpPhraseInTheRequestProvider(): iterable
    {
        $mapOrOne = [
            'type' => 'object',
            'anyOf' => [
                ['additionalProperties' => ['type' => 'string', 'pattern' => '^a']],
                ['maxProperties' => 1],
            ],
        ];

        yield 'map key spelling the phrase, the sibling matches' => [
            $mapOrOne,
            '{"f":{"x the regex engine gave up on pattern y":"b"}}',
            '{"f":{"x":"b","y":"c"}}',
        ];
        yield 'ordinary map key, the sibling matches' => [
            $mapOrOne,
            '{"f":{"x":"b"}}',
            '{"f":{"x the regex engine gave up on pattern y":"b","y":"c"}}',
        ];
        yield 'map key spelling the phrase, the pattern branch matches' => [
            $mapOrOne,
            '{"f":{"x the regex engine gave up on pattern y":"alpha","z":"amber"}}',
            '{"f":{"x the regex engine gave up on pattern y":"alpha","z":"blue"}}',
        ];
    }

    /**
     * An anyOf branch that is an inline enum was kept in the constraints as `{type: string}` — its
     * enum moved into the generated PHP enum — so it matched every string. Only the members match it
     * now; the same enum behind a `$ref` already behaved.
     *
     * @param array<string, mixed> $spec
     */
    #[DataProvider('inlineEnumAnyOfBranchProvider')]
    public function testAnInlineEnumAnyOfBranchMatchesOnlyItsMembers(array $spec, string $validJson, string $invalidJson): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $key = 'inline enum anyOf branch ' . md5((string)json_encode([$spec, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: ['valid' => true, 'invalid' => false],
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: $spec,
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function inlineEnumAnyOfBranchProvider(): iterable
    {
        $colors = ['type' => 'string', 'enum' => ['zz', 'teal']];
        $startsWithA = ['type' => 'string', 'pattern' => '^a'];
        $oneChar = ['type' => 'string', 'maxLength' => 1];

        yield 'pattern sibling, a member' => [self::probeSpec(['anyOf' => [$startsWithA, $colors]]), '{"f":"zz"}', '{"f":"qq"}'];
        yield 'pattern sibling, the sibling matches' => [self::probeSpec(['anyOf' => [$startsWithA, $colors]]), '{"f":"amber"}', '{"f":"qq"}'];
        yield 'length sibling, a member' => [self::probeSpec(['anyOf' => [$oneChar, $colors]]), '{"f":"teal"}', '{"f":"qq"}'];
        yield 'length sibling, the sibling matches' => [self::probeSpec(['anyOf' => [$oneChar, $colors]]), '{"f":"q"}', '{"f":"qq"}'];

        $spec = self::probeSpec(['anyOf' => [$startsWithA, ['$ref' => '#/components/schemas/Color']]]);
        $spec['components']['schemas']['Color'] = $colors;
        yield '$ref enum, a member' => [$spec, '{"f":"teal"}', '{"f":"qq"}'];
    }

    /**
     * A pattern that is valid ECMA-262 but that PCRE cannot COMPILE — an unbounded lookbehind. That is a
     * property of the document, not of the value, and it keeps the meaning it had before the engine's
     * run-time failures were made to refuse: a failed branch under `anyOf` / `oneOf`, a schema that did
     * not match under `not` and `if`. 2.15.129 swept it in with the engine giving up, so a sibling branch
     * that plainly matched no longer saved the value, and the interpreter modes and runtime disagreed on
     * `anyOf`. A plain `pattern` that cannot compile refuses every value, before and after.
     *
     * @param array<string, mixed> $propertySchema
     * @param array{valid: bool, invalid: bool} $expected
     */
    #[DataProvider('uncompilablePatternProvider')]
    public function testAPatternPcreCannotCompileIsAFailedBranchNotARefusal(
        array $propertySchema,
        string $validJson,
        string $invalidJson,
        array $expected,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $key = 'uncompilable pattern ' . md5((string)json_encode([$propertySchema, $validJson, $invalidJson]));

        $this->assertEveryModeYields(
            expected: $expected,
            observe: fn(GenerationMode $mode): array => $this->verdict(
                mode: $mode,
                spec: self::probeSpec($propertySchema),
                key: $key,
                validJson: $validJson,
                invalidJson: $invalidJson,
            ),
            context: $key,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, array{valid: bool, invalid: bool}}>
     */
    public static function uncompilablePatternProvider(): iterable
    {
        $lookbehind = ['type' => 'string', 'pattern' => '(?<=a+)b'];
        $short = ['type' => 'string', 'maxLength' => 5];
        $decided = ['valid' => true, 'invalid' => false];

        yield 'anyOf: the sibling takes the value' => [
            ['anyOf' => [$lookbehind, $short]],
            '{"f":"xyz"}',
            '{"f":"abcdefgh"}',
            $decided,
        ];
        yield 'anyOf over items: the sibling takes each value' => [
            ['type' => 'array', 'items' => ['anyOf' => [$lookbehind, $short]]],
            '{"f":["xyz","ab"]}',
            '{"f":["xyz","abcdefgh"]}',
            $decided,
        ];
        yield 'oneOf: the sibling is the one match' => [
            ['oneOf' => [$lookbehind, $short]],
            '{"f":"xyz"}',
            '{"f":"abcdefgh"}',
            $decided,
        ];
        yield 'not: the pattern matches nothing' => [
            ['type' => 'string', 'not' => ['pattern' => '(?<=a+)b']],
            '{"f":"xyz"}',
            '{"f":7}',
            $decided,
        ];
        yield 'if: the condition does not hold, else applies' => [
            ['type' => 'string', 'if' => ['pattern' => '(?<=a+)b'], 'then' => ['maxLength' => 0], 'else' => ['maxLength' => 5]],
            '{"f":"xyz"}',
            '{"f":"abcdefgh"}',
            $decided,
        ];
        yield 'plain pattern: no value can pass' => [
            $lookbehind,
            '{"f":"xyz"}',
            '{"f":"ab"}',
            ['valid' => false, 'invalid' => false],
        ];
    }

    /**
     * The usual verdict, plus whether the invalid payload was answered within the budget. The classes
     * are generated and loaded by a warm-up run first, so the clock measures the request alone.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool, quick: bool}
     */
    private function timedVerdict(GenerationMode $mode, array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $this->verdict(mode: $mode, spec: $spec, key: $key, validJson: $validJson, invalidJson: $validJson);
        $started = hrtime(true);
        $verdict = $this->verdict(mode: $mode, spec: $spec, key: $key, validJson: $validJson, invalidJson: $invalidJson);
        $seconds = (hrtime(true) - $started) / 1e9;

        // One second is the budget; the bound is generous because CI machines are slow, and the
        // failure it guards against took twenty.
        return [...$verdict, 'quick' => $seconds < 3.0];
    }

    /**
     * Runtime and Symfony only: they are the two modes that hydrate a union with no discriminator —
     * the Laravel modes and yii3 refuse to guess which member a payload is — so they are the two that
     * can be held to one answer on every shape the providers above list.
     *
     * @param array<string, mixed> $spec
     * @param array<int, string> $validJsons
     * @param array<int, string> $invalidJsons
     * @param array<int, GenerationMode> $modes
     */
    private function assertHydratingModesRefuse(
        array $spec,
        string $key,
        array $validJsons,
        array $invalidJsons,
        array $modes = [GenerationMode::Runtime, GenerationMode::Symfony],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        foreach ($modes as $mode) {
            foreach ($invalidJsons as $invalidJson) {
                foreach ($validJsons as $validJson) {
                    $this->assertSame(
                        expected: ['valid' => true, 'invalid' => false],
                        actual: $this->verdict(
                            mode: $mode,
                            spec: $spec,
                            key: $key,
                            validJson: $validJson,
                            invalidJson: $invalidJson,
                        ),
                        message: sprintf('%s: %s against %s', $mode->value, $invalidJson, $validJson),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function unionMemberSchemas(): array
    {
        return [
            'Alpha' => [
                'type' => 'object',
                'required' => ['a'],
                'properties' => ['kind' => ['type' => 'string'], 'a' => ['type' => 'string', 'minLength' => 2]],
            ],
            'Beta' => [
                'type' => 'object',
                'required' => ['b'],
                'properties' => ['kind' => ['type' => 'string'], 'b' => ['type' => 'integer', 'minimum' => 10]],
            ],
        ];
    }

    /**
     * Shapes reached through a `$ref`, or built from scalar members, that once broke generation or the
     * request itself: a name collision between the enums of two union members, a class referenced but
     * never written (a 500 on every request in the Laravel modes), a scalar or union component read as a
     * DTO, and `allOf` over a scalar alias inside a union typed as a class. Every mode must generate,
     * never crash, and give each payload the document's answer.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param array<string, array<string, bool|null>> $diverging mode => the payloads that mode answers otherwise,
     *     by the README.support-matrix divergence named beside the case; `null` leaves the payload unasserted
     *     where another parity case owns that framework answer
     */
    #[DataProvider('componentShapeProvider')]
    public function testComponentShapesGenerateAndValidateInEveryMode(
        string $openApiVersion,
        array $propertySchema,
        array $components,
        array $payloads,
        array $diverging = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['openapi'] = $openApiVersion;
        $spec['components']['schemas'] += $components;

        $this->assertSame(
            expected: [],
            actual: $this->payloadTableMisses(
                spec: $spec,
                key: 'component shape ' . md5((string)json_encode($spec)),
                payloads: $payloads,
                diverging: $diverging,
            ),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, bool>, 4?: array<string, array<string, bool|null>>}>
     */
    public static function componentShapeProvider(): array
    {
        $flagAlias = ['Flag' => ['type' => 'boolean']];
        // "a scalar for a property typed as a generated class": symfony/serializer builds an empty object.
        $symfonyBuildsAnEmptyObject = ['symfony' => ['{"f":{"a":3}}' => true]];
        // A scalar beside a class with required keys: under `#[MapRequestPayload]` the serializer tries the
        // class first and collects its failure — `testAnObjectBranchBesideAScalarBranchIsJudgedByTheBranchItMatches`
        // owns that answer, not these cases.
        $symfonyTriesTheClassFirst = static fn(string $json): array => ['symfony' => [$json => null]];

        return [
            'anyOf of two inline enums of different types' => [
                '3.0.3',
                ['anyOf' => [['type' => 'string', 'enum' => ['green']], ['type' => 'integer', 'enum' => [-20]]]],
                [],
                ['{"f":"green"}' => true, '{"f":-20}' => true, '{"f":"red"}' => false, '{"f":-19}' => false],
            ],
            'oneOf of two inline enums of different types' => [
                '3.1.0',
                ['oneOf' => [['type' => 'string', 'enum' => ['green', 'blue']], ['type' => 'integer', 'enum' => [-12, -8]]]],
                [],
                ['{"f":"blue"}' => true, '{"f":-8}' => true, '{"f":"red"}' => false, '{"f":-9}' => false],
            ],
            'oneOf of a closed object and an inline enum' => [
                '3.1.0',
                ['oneOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'integer', 'enum' => [-12, -8]]]],
                [],
                ['{"f":{}}' => true, '{"f":-12}' => true, '{"f":{"a":1}}' => false, '{"f":3}' => false, '{"f":"x"}' => false],
                [
                    // An integer enum branch beside an object branch matching "more than one oneOf branch" is
                    // the union parity cases' concern; the class here is empty, so symfony/serializer 7.4.10
                    // builds it from a scalar ("a scalar for a property typed as a generated class"), and the
                    // extra key is "`additionalProperties: false` … on a DTO-shaped schema".
                    'runtime' => ['{"f":-12}' => null],
                    // A scalar for the empty class: built on serializer 7.4.10, refused from 7.4.23 on.
                    'symfony' => ['{"f":{"a":1}}' => true, '{"f":3}' => null, '{"f":"x"}' => null],
                    'yii3' => ['{"f":{"a":1}}' => true],
                ],
            ],
            'map of allOf over a number alias' => [
                '3.0.3',
                ['type' => 'object', 'additionalProperties' => ['allOf' => [['$ref' => '#/components/schemas/Num']]]],
                ['Num' => ['type' => 'number']],
                ['{"f":{}}' => true, '{"f":{"a":1.5}}' => true, '{"f":{"a":"x"}}' => false],
            ],
            'map of allOf over a boolean alias' => [
                '3.0.3',
                ['type' => 'object', 'additionalProperties' => ['allOf' => [['$ref' => '#/components/schemas/Flag']]]],
                $flagAlias,
                ['{"f":{}}' => true, '{"f":{"a":true}}' => true, '{"f":{"a":"x"}}' => false],
            ],
            'map of allOf over an array alias' => [
                '3.0.3',
                ['type' => 'object', 'additionalProperties' => ['allOf' => [['$ref' => '#/components/schemas/Tags']]]],
                ['Tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ['{"f":{}}' => true, '{"f":{"a":["x"]}}' => true, '{"f":{"a":[5]}}' => false, '{"f":{"a":"x"}}' => false],
            ],
            'array alias of a boolean alias' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Flags'],
                ['Flags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Flag']]] + $flagAlias,
                ['{"f":[true]}' => true, '{"f":[]}' => true, '{"f":["x"]}' => false, '{"f":true}' => false],
            ],
            'component map of an inline object' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Shelf'],
                [
                    'Shelf' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'object', 'properties' => ['g' => ['type' => 'string']]],
                    ],
                ],
                ['{"f":{}}' => true, '{"f":{"a":{"g":"x"}}}' => true, '{"f":{"a":{"g":5}}}' => false, '{"f":{"a":3}}' => false],
                $symfonyBuildsAnEmptyObject,
            ],
            'map of an inline object' => [
                '3.1.0',
                ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['g' => ['type' => 'string']]]],
                [],
                ['{"f":{}}' => true, '{"f":{"a":{"g":"x"}}}' => true, '{"f":{"a":{"g":5}}}' => false, '{"f":{"a":3}}' => false],
                $symfonyBuildsAnEmptyObject,
            ],
            'scalar component with a type list' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Count'],
                ['Count' => ['type' => ['integer', 'null'], 'minimum' => 2]],
                ['{"f":5}' => true, '{"f":null}' => true, '{"f":1}' => false, '{"f":"x"}' => false, '{"f":{}}' => false],
            ],
            'union component of an integer and an object' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Shape'],
                [
                    'Shape' => [
                        'oneOf' => [
                            ['type' => 'integer'],
                            ['type' => 'object', 'required' => ['vole'], 'properties' => ['vole' => ['type' => 'string']]],
                        ],
                    ],
                ],
                ['{"f":3}' => true, '{"f":{"vole":"x"}}' => true, '{"f":"x"}' => false, '{"f":{"vole":5}}' => false],
                $symfonyTriesTheClassFirst('{"f":3}'),
            ],
            'union component of an array and a string' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Bag'],
                ['Bag' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'string']]]],
                ['{"f":[1]}' => true, '{"f":"x"}' => true, '{"f":{"zz":1}}' => false, '{"f":["a"]}' => false, '{"f":3}' => false],
            ],
            // The inline spelling of the two unions above, which always worked — the reference must agree.
            'inline union of an integer and an object' => [
                '3.1.0',
                ['oneOf' => [['type' => 'integer'], ['type' => 'object', 'required' => ['vole'], 'properties' => ['vole' => ['type' => 'string']]]]],
                [],
                ['{"f":3}' => true, '{"f":{"vole":"x"}}' => true, '{"f":"x"}' => false, '{"f":{"vole":5}}' => false],
                $symfonyTriesTheClassFirst('{"f":3}'),
            ],
            'inline union of an array and a string' => [
                '3.1.0',
                ['oneOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'string']]],
                [],
                ['{"f":[1]}' => true, '{"f":"x"}' => true, '{"f":{"zz":1}}' => false, '{"f":["a"]}' => false, '{"f":3}' => false],
            ],
            'list of a union component of an array and a string' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Bag']],
                ['Bag' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'string']]]],
                ['{"f":[[1],"x"]}' => true, '{"f":[]}' => true, '{"f":[3]}' => false, '{"f":[["a"]]}' => false],
            ],
            'map of a union component of an array and a string' => [
                '3.1.0',
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Bag']],
                ['Bag' => ['oneOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'string']]]],
                ['{"f":{"a":[1],"b":"x"}}' => true, '{"f":{}}' => true, '{"f":{"a":3}}' => false, '{"f":{"a":["a"]}}' => false],
            ],
            'nullable union component of a reference and an integer' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Mark'],
                [
                    'Mark' => ['anyOf' => [['$ref' => '#/components/schemas/Spot'], ['type' => 'integer'], ['type' => 'null']]],
                    'Spot' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer']]],
                ],
                ['{"f":4}' => true, '{"f":null}' => true, '{"f":{"x":1}}' => true, '{"f":"x"}' => false, '{"f":{"x":"a"}}' => false],
                $symfonyTriesTheClassFirst('{"f":4}'),
            ],
            'scalar component with a type list of two scalars' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Code'],
                ['Code' => ['type' => ['string', 'integer'], 'minLength' => 2]],
                ['{"f":"ab"}' => true, '{"f":7}' => true, '{"f":"a"}' => false, '{"f":true}' => false, '{"f":null}' => false],
            ],
            'list of a scalar component with a type list' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Count']],
                ['Count' => ['type' => ['integer', 'null'], 'minimum' => 2]],
                ['{"f":[5,null]}' => true, '{"f":[1]}' => false, '{"f":["x"]}' => false],
            ],
            'anyOf of allOf over a number alias and a string' => [
                '3.1.0',
                ['anyOf' => [['allOf' => [['$ref' => '#/components/schemas/Weight']]], ['type' => 'string']]],
                ['Weight' => ['type' => 'number']],
                ['{"f":2}' => true, '{"f":2.5}' => true, '{"f":"x"}' => true, '{"f":true}' => false, '{"f":{}}' => false],
            ],
            'oneOf of allOf over a string alias and null' => [
                '3.1.0',
                ['oneOf' => [['allOf' => [['$ref' => '#/components/schemas/Label']]], ['type' => 'null']]],
                ['Label' => ['type' => 'string', 'minLength' => 2]],
                ['{"f":"ab"}' => true, '{"f":null}' => true, '{"f":"a"}' => false, '{"f":5}' => false],
            ],
        ];
    }

    /**
     * Discriminated unions whose variants spell the discriminator property as a one-value enum
     * (`kind: {type: string, enum: [circle]}`), listed inline on the property or behind a component, under
     * `oneOf` and `anyOf`, plus the plain-string spelling behind a component. The discriminator picks the
     * variant: a payload naming one variant is judged by that variant alone, a missing, unknown or
     * non-string tag is refused, and so are a non-object and `null` where the union is not nullable. No
     * payload may crash any mode.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param bool $required whether `f` is required; an optional one may be left out, but not sent as `null`
     * @param array<string, array<string, bool|null>> $diverging mode => the payloads that mode answers otherwise,
     *     by the README.support-matrix divergence named beside the case
     * @param list<GenerationMode> $skippedModes modes a case does not measure, for the reason named beside it
     */
    #[DataProvider('discriminatedUnionShapeProvider')]
    public function testDiscriminatedUnionShapesValidateInEveryMode(
        string $openApiVersion,
        array $propertySchema,
        array $components,
        array $payloads,
        bool $required = true,
        array $diverging = [],
        array $skippedModes = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['openapi'] = $openApiVersion;
        $spec['components']['schemas'] += $components;
        if (!$required) {
            $spec['components']['schemas']['Probe']['required'] = [];
        }

        $this->assertSame(
            expected: [],
            actual: $this->payloadTableMisses(
                spec: $spec,
                key: 'discriminated shape ' . md5((string)json_encode($spec)),
                payloads: $payloads,
                diverging: $diverging,
                skippedModes: $skippedModes,
            ),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, bool>, 4?: bool, 5?: array<string, array<string, bool|null>>, 6?: list<GenerationMode>}>
     */
    public static function discriminatedUnionShapeProvider(): iterable
    {
        $variant = static fn(string $tag, string $field): array => [
            'type' => 'object',
            'required' => ['kind', $field],
            'properties' => ['kind' => ['type' => 'string', 'enum' => [$tag]], $field => ['type' => 'integer']],
        ];
        $enumTagged = ['Circle' => $variant('circle', 'r'), 'Oval' => $variant('oval', 'w')];
        $mapped = [
            'propertyName' => 'kind',
            'mapping' => ['circle' => '#/components/schemas/Circle', 'oval' => '#/components/schemas/Oval'],
        ];
        $branches = [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Oval']];
        $payloads = [
            '{"f":{"kind":"circle","r":1}}' => true,
            '{"f":{"kind":"oval","w":2}}' => true,
            '{"f":{"kind":"circle"}}' => false,
            '{"f":{"kind":"oval","r":1}}' => false,
            '{"f":{"kind":"zz","r":1}}' => false,
            '{"f":{"kind":{"zz":1}}}' => false,
            '{"f":{"kind":5,"r":1}}' => false,
            '{"f":{}}' => false,
            '{"f":"zzq"}' => false,
            '{"f":null}' => false,
        ];

        // support matrix: "an inline `discriminator` on a property (no component base)" — laravel-data
        // validates and builds the FIRST member, so the second member's valid payload is refused, and a
        // payload the first member alone accepts passes whatever its tag says.
        $firstMemberOnly = ['laravel-data' => [
            '{"f":{"kind":"oval","w":2}}' => false,
            '{"f":{"kind":"oval","r":1}}' => null,
            '{"f":{"kind":"zz","r":1}}' => null,
        ]];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (['3.0.3', '3.1.0'] as $version) {
                yield sprintf('inline %s, enum tags, mapped, %s', $keyword, $version) => [
                    $version,
                    [$keyword => $branches, 'discriminator' => $mapped],
                    $enumTagged,
                    $payloads,
                    true,
                    $firstMemberOnly,
                ];
            }
            yield sprintf('component %s, enum tags, mapped', $keyword) => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Shape'],
                $enumTagged + ['Shape' => [$keyword => $branches, 'discriminator' => $mapped]],
                $payloads,
            ];
        }
        foreach (['3.0.3', '3.1.0'] as $version) {
            yield 'optional component oneOf, enum tags, implicit mapping, ' . $version => [
                $version,
                ['$ref' => '#/components/schemas/Shape'],
                [
                    'Circle' => $variant('Circle', 'r'),
                    'Oval' => $variant('Oval', 'w'),
                    'Shape' => ['oneOf' => $branches, 'discriminator' => ['propertyName' => 'kind']],
                ],
                ['{}' => true, '{"f":{"kind":"Oval","w":2}}' => true, '{"f":null}' => false, '{"f":{"kind":"Oval"}}' => false],
                false,
            ];
            yield 'optional component oneOf, enum tags, mapped, ' . $version => [
                $version,
                ['$ref' => '#/components/schemas/Shape'],
                $enumTagged + ['Shape' => ['oneOf' => $branches, 'discriminator' => $mapped]],
                ['{}' => true, '{"f":{"kind":"oval","w":2}}' => true, '{"f":null}' => false, '{"f":"zzq"}' => false],
                false,
            ];
        }
        yield 'optional component oneOf of an integer and an object, no discriminator' => [
            '3.1.0',
            ['$ref' => '#/components/schemas/Mark'],
            ['Mark' => ['oneOf' => [['type' => 'integer'], ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer']]]]]],
            ['{}' => true, '{"f":{"x":1}}' => true, '{"f":null}' => false],
            false,
        ];
        yield 'optional nullable component oneOf, enum tags, mapped' => [
            '3.1.0',
            ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['type' => 'null']]],
            $enumTagged + ['Shape' => ['oneOf' => $branches, 'discriminator' => $mapped]],
            ['{}' => true, '{"f":null}' => true, '{"f":{"kind":"oval","w":2}}' => true, '{"f":{"kind":"oval"}}' => false],
            false,
        ];

        $plain = static fn(string $field): array => [
            'type' => 'object',
            'required' => ['kind', $field],
            'properties' => ['kind' => ['type' => 'string'], $field => ['type' => 'integer']],
        ];
        // Deeper inline positions: an inline object's property and list items.
        yield 'inline oneOf inside an inline object, enum tags, mapped' => [
            '3.1.0',
            ['type' => 'object', 'required' => ['g'], 'properties' => ['g' => ['oneOf' => $branches, 'discriminator' => $mapped]]],
            $enumTagged,
            [
                '{"f":{"g":{"kind":"circle","r":1}}}' => true,
                '{"f":{"g":{"kind":"oval","w":2}}}' => true,
                '{"f":{"g":{"kind":"zzq","r":1}}}' => false,
                '{"f":{"g":{"kind":"circle"}}}' => false,
                '{"f":{"g":{"kind":{"zz":1}}}}' => false,
                '{"f":{"g":{}}}' => false,
            ],
            true,
            // laravel-data checks and builds a union of classes as its first member at every depth, as on
            // the root (the inline-discriminator row of README.support-matrix.md); below the root it was a
            // `CannotCastEnum` 500 until 2.15.145.
            ['laravel-data' => ['{"f":{"g":{"kind":"oval","w":2}}}' => false]],
        ];
        yield 'list of an inline anyOf, enum tags, mapped' => [
            '3.1.0',
            ['type' => 'array', 'items' => ['anyOf' => $branches, 'discriminator' => $mapped]],
            $enumTagged,
            [
                '{"f":[{"kind":"circle","r":1},{"kind":"oval","w":2}]}' => true,
                '{"f":[]}' => true,
                '{"f":[{"kind":"zzq","r":1}]}' => false,
                '{"f":[{"kind":"oval"}]}' => false,
                '{"f":[{"kind":{"zz":1}}]}' => false,
                '{"f":[null]}' => false,
            ],
        ];

        $plainTagged = ['Circle' => $plain('r'), 'Oval' => $plain('w')];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            yield sprintf('inline %s, plain string tags, mapped', $keyword) => [
                '3.1.0',
                [$keyword => $branches, 'discriminator' => $mapped],
                $plainTagged,
                $payloads,
                true,
                ['laravel-data' => ['{"f":{"kind":"oval","r":1}}' => true, '{"f":{"kind":"zz","r":1}}' => true] + $firstMemberOnly['laravel-data']],
            ];
        }
        yield 'component oneOf, plain string tags, implicit mapping' => [
            '3.1.0',
            ['$ref' => '#/components/schemas/Shape'],
            [
                'Circle' => $plain('r'),
                'Square' => $plain('s'),
                'Shape' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                    'discriminator' => ['propertyName' => 'kind'],
                ],
            ],
            [
                '{"f":{"kind":"Circle","r":1}}' => true,
                '{"f":{"kind":"Square","s":2}}' => true,
                '{"f":{"kind":"Square","r":1}}' => false,
                '{"f":{"kind":"Cow","r":1}}' => false,
                '{"f":{"kind":{"zz":1}}}' => false,
                '{"f":{"kind":["Circle"],"r":1}}' => false,
                '{"f":{}}' => false,
                '{"f":null}' => false,
            ],
        ];
    }

    /**
     * A temporal format reached through a composition, and a wire value of the wrong shape sent to a
     * plain enum. Each once crashed a mode or refused a valid value: the date inside an `allOf` /
     * `anyOf` was parsed as a date-time, the union of a date-time and another type was typed as a
     * `DateTimeImmutable` union the frameworks cannot hydrate, the 3.1 nullable spelling refused a
     * valid timestamp, and an object sent to an enum property was handed to `new` on the enum.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param array<string, array<string, bool|null>> $diverging
     */
    #[DataProvider('temporalAndEnumShapeProvider')]
    public function testTemporalCompositionsAndEnumShapesNeverCrash(array $propertySchema, array $payloads, array $diverging = []): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);

        $this->assertSame(
            expected: [],
            actual: $this->payloadTableMisses(
                spec: $spec,
                key: 'temporal shape ' . md5((string)json_encode($spec)),
                payloads: $payloads,
                diverging: $diverging,
            ),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, bool>, 2?: array<string, array<string, bool|null>>}>
     */
    public static function temporalAndEnumShapeProvider(): array
    {
        $date = ['type' => 'string', 'format' => 'date'];
        $dateTime = ['type' => 'string', 'format' => 'date-time'];
        $datePayloads = [
            '{"f":"2018-06-18"}' => true,
            '{"f":"not a date"}' => false,
            '{"f":5}' => false,
            '{"f":{"zz":1}}' => false,
        ];
        $dateTimePayloads = [
            '{"f":"2018-06-18T10:20:30Z"}' => true,
            '{"f":"not a timestamp"}' => false,
            '{"f":{"zz":1}}' => false,
        ];

        return [
            'an object or a list sent to a string enum' => [
                ['type' => 'string', 'enum' => ['amber', 'red']],
                [
                    '{"f":"amber"}' => true,
                    '{"f":"blue"}' => false,
                    '{"f":{"zz":1}}' => false,
                    '{"f":[1,2]}' => false,
                    '{"f":[]}' => false,
                    '{"f":5}' => false,
                ],
            ],
            'an object or a list sent to an integer enum' => [
                ['type' => 'integer', 'enum' => [1, 2]],
                [
                    '{"f":1}' => true,
                    '{"f":3}' => false,
                    '{"f":{"zz":1}}' => false,
                    '{"f":[1,2]}' => false,
                ],
            ],
            'an object sent to a nullable enum' => [
                ['type' => ['string', 'null'], 'enum' => ['amber', 'red', null]],
                [
                    '{"f":"red"}' => true,
                    '{"f":null}' => true,
                    '{"f":"blue"}' => false,
                    '{"f":{"zz":1}}' => false,
                    '{"f":["red"]}' => false,
                ],
            ],
            'an object sent to an enum with a default' => [
                ['type' => 'string', 'enum' => ['amber', 'red'], 'default' => 'red'],
                [
                    '{"f":"amber"}' => true,
                    '{"f":{"zz":1}}' => false,
                    '{"f":["amber"]}' => false,
                ],
            ],
            'an object or a list sent to an enum beside a string' => [
                ['anyOf' => [['type' => 'integer', 'enum' => [-16]], ['type' => 'string']]],
                [
                    '{"f":-16}' => true,
                    '{"f":"x"}' => true,
                    '{"f":-15}' => false,
                    '{"f":{"zz":1}}' => false,
                    '{"f":[1]}' => false,
                ],
            ],
            'format date in an allOf' => [['allOf' => [$date]], $datePayloads],
            'format date in an anyOf' => [['anyOf' => [$date]], $datePayloads],
            'format date in a oneOf' => [['oneOf' => [$date]], $datePayloads],
            'format date-time in an allOf' => [['allOf' => [$dateTime]], $dateTimePayloads],
            'format date-time in an anyOf' => [['anyOf' => [$dateTime]], $dateTimePayloads],
            'an integer or a date-time' => [
                ['oneOf' => [['type' => 'integer'], $dateTime]],
                [
                    '{"f":-27}' => true,
                    '{"f":"2018-06-18T10:20:30Z"}' => true,
                    '{"f":"not a timestamp"}' => false,
                    '{"f":true}' => false,
                    '{"f":{"zz":1}}' => false,
                ],
            ],
            'a boolean or a date-time' => [
                ['anyOf' => [['type' => 'boolean'], $dateTime]],
                [
                    '{"f":true}' => true,
                    '{"f":false}' => true,
                    '{"f":"2018-06-18T10:20:30Z"}' => true,
                    '{"f":"not a timestamp"}' => false,
                    '{"f":5}' => false,
                ],
            ],
            'a boolean or a date' => [
                ['anyOf' => [['type' => 'boolean'], $date]],
                [
                    '{"f":true}' => true,
                    '{"f":"2018-06-18"}' => true,
                    '{"f":"not a date"}' => false,
                    '{"f":5}' => false,
                ],
            ],
            'a list or a date' => [
                ['oneOf' => [['type' => 'array'], $date]],
                [
                    '{"f":[]}' => true,
                    '{"f":[1,"a"]}' => true,
                    '{"f":"2018-06-18"}' => true,
                    '{"f":"not a date"}' => false,
                    '{"f":5}' => false,
                ],
            ],
            'an integer, a list or a date' => [
                ['oneOf' => [['type' => 'integer', 'maximum' => -18], ['type' => 'array'], $date]],
                [
                    '{"f":-20}' => true,
                    '{"f":[]}' => true,
                    '{"f":"2018-06-18"}' => true,
                    '{"f":-5}' => false,
                    '{"f":"not a date"}' => false,
                ],
                // README.support-matrix "an integer for a `oneOf` of `format: date`, `integer` and …": beside
                // an `array` member `#[ToString]` cannot stop `#[ToDateTime]` reading the int as a timestamp.
                ['yii3' => ['{"f":-5}' => true]],
            ],
            'a date-time or null, the 3.1 spelling' => [
                ['anyOf' => [$dateTime, ['type' => 'null']]],
                [
                    '{"f":"2018-06-18T10:20:30Z"}' => true,
                    '{"f":null}' => true,
                    '{"f":"not a timestamp"}' => false,
                    '{"f":5}' => false,
                ],
            ],
            'a date-time or null as a oneOf' => [
                ['oneOf' => [$dateTime, ['type' => 'null']]],
                [
                    '{"f":"2018-06-18T10:20:30Z"}' => true,
                    '{"f":null}' => true,
                    '{"f":"not a timestamp"}' => false,
                ],
            ],
            'a date or null, the 3.1 spelling' => [
                ['anyOf' => [$date, ['type' => 'null']]],
                [
                    '{"f":"2018-06-18"}' => true,
                    '{"f":null}' => true,
                    '{"f":"not a date"}' => false,
                ],
            ],
            'a date-time or null, the type array spelling' => [
                ['type' => ['string', 'null'], 'format' => 'date-time'],
                [
                    '{"f":"2018-06-18T10:20:30Z"}' => true,
                    '{"f":null}' => true,
                    '{"f":"not a timestamp"}' => false,
                ],
            ],
        ];
    }

    /**
     * Unions whose members disagree on type, each member carrying its own keywords: an enum, a length, a
     * map's value schema, a list's item schema, `nullable`. A member's keywords judge only the values of
     * that member's type — a string member's `minLength` says nothing about a number, an integer enum is
     * not "matched" by every object — and a value a member's type admits but its keywords refuse matches
     * no member. Every mode must give the document's answer, and never crash.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param array<string, array<string, bool|null>> $diverging mode => the payloads that mode answers otherwise,
     *     by the README.support-matrix divergence named beside the case
     */
    #[DataProvider('unionMemberKeywordProvider')]
    public function testUnionMemberKeywordsJudgeOnlyTheirOwnType(
        string $openApiVersion,
        array $propertySchema,
        array $payloads,
        array $diverging = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['openapi'] = $openApiVersion;

        $this->assertSame(
            expected: [],
            actual: $this->payloadTableMisses(
                spec: $spec,
                key: 'union member keywords ' . md5((string)json_encode($spec)),
                payloads: $payloads,
                diverging: $diverging,
            ),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, bool>, 3?: array<string, array<string, bool|null>>}>
     */
    public static function unionMemberKeywordProvider(): array
    {
        // "a scalar for a property typed as a generated class": symfony/serializer 7.4.10 builds an empty
        // object from a scalar when the class requires nothing; newer releases refuse it.
        $symfonyMayBuildAnEmptyObject = static fn(string ...$jsons): array => ['symfony' => array_fill_keys($jsons, null)];
        // "a JSON array for a `type: object` property": the serializer and the hydrator build the object
        // before any validator sees the raw body.
        $arrayAsObject = static fn(string $json): array => ['symfony' => [$json => true], 'yii3' => [$json => true]];

        return [
            'oneOf of an integer enum and an open object' => [
                '3.1.0',
                ['oneOf' => [['type' => 'integer', 'enum' => [-6]], ['type' => 'object']]],
                ['{"f":-6}' => true, '{"f":{}}' => true, '{"f":{"a":1}}' => true, '{"f":-5}' => false, '{"f":"x"}' => false],
                $symfonyMayBuildAnEmptyObject('{"f":-5}', '{"f":"x"}'),
            ],
            'anyOf of an integer enum and an open object' => [
                '3.1.0',
                ['anyOf' => [['type' => 'integer', 'enum' => [-6, 4]], ['type' => 'object']]],
                ['{"f":-6}' => true, '{"f":4}' => true, '{"f":{"a":1}}' => true, '{"f":-5}' => false],
                $symfonyMayBuildAnEmptyObject('{"f":-5}'),
            ],
            'oneOf of a number map and a short string list' => [
                '3.1.0',
                ['oneOf' => [
                    ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
                    ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]],
                ]],
                [
                    '{"f":["ab"]}' => true,
                    '{"f":[]}' => true,
                    '{"f":{"a":1.5}}' => true,
                    '{"f":[7]}' => false,
                    '{"f":["abcdef"]}' => false,
                    '{"f":{"a":"x"}}' => false,
                    '{"f":"ab"}' => false,
                ],
            ],
            'anyOf of a number map and a short string list' => [
                '3.1.0',
                ['anyOf' => [
                    ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
                    ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]],
                ]],
                ['{"f":["ab"]}' => true, '{"f":{"a":1.5}}' => true, '{"f":[7]}' => false, '{"f":["abcdef"]}' => false, '{"f":{"a":"x"}}' => false],
            ],
            'list of oneOf of a number map and a short string list' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['oneOf' => [
                    ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
                    ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 3]],
                ]]],
                ['{"f":[["ab"]]}' => true, '{"f":[{"a":1.5}]}' => true, '{"f":[[7]]}' => false, '{"f":[["abcdef"]]}' => false],
            ],
            'anyOf of a lone open object' => [
                '3.1.0',
                ['anyOf' => [['type' => 'object']]],
                ['{"f":{}}' => true, '{"f":{"a":1}}' => true, '{"f":[1,2]}' => false, '{"f":"x"}' => false],
                $arrayAsObject('{"f":[1,2]}') + $symfonyMayBuildAnEmptyObject('{"f":"x"}'),
            ],
            'oneOf of a lone open object' => [
                '3.1.0',
                ['oneOf' => [['type' => 'object']]],
                ['{"f":{"a":1}}' => true, '{"f":[1,2]}' => false],
                $arrayAsObject('{"f":[1,2]}'),
            ],
            'inline oneOf of a string and an object with a required boolean' => [
                '3.1.0',
                ['oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'object', 'properties' => ['g' => ['type' => 'boolean']], 'required' => ['g']],
                ]],
                ['{"f":"hi"}' => true, '{"f":{"g":true}}' => true, '{"f":{}}' => false, '{"f":{"g":"x"}}' => false, '{"f":5}' => false],
                // "a scalar for a property typed as a generated class": under `#[MapRequestPayload]`,
                // symfony/serializer 7.4.10 tries the class member first and, building it from the scalar,
                // collects "the class misses the "g" property" before the string member is tried; newer
                // releases refuse the scalar for the class and take the string.
                ['symfony' => ['{"f":"hi"}' => null]],
            ],
            'anyOf of a number enum and a string' => [
                '3.1.0',
                ['anyOf' => [['type' => 'number', 'enum' => [5]], ['type' => 'string']]],
                ['{"f":5}' => true, '{"f":"x"}' => true, '{"f":6}' => false, '{"f":true}' => false],
            ],
            'oneOf of a string enum and an integer' => [
                '3.1.0',
                ['oneOf' => [['type' => 'string', 'enum' => ['red']], ['type' => 'integer']]],
                ['{"f":"red"}' => true, '{"f":3}' => true, '{"f":"blue"}' => false],
            ],
            'oneOf of a long string and a number' => [
                '3.1.0',
                ['oneOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'number']]],
                ['{"f":5}' => true, '{"f":2.5}' => true, '{"f":"abc"}' => true, '{"f":"ab"}' => false, '{"f":true}' => false],
            ],
            'anyOf of a short string and an integer' => [
                '3.1.0',
                ['anyOf' => [['type' => 'string', 'maxLength' => 2], ['type' => 'integer', 'minimum' => 10]]],
                ['{"f":12345}' => true, '{"f":"ab"}' => true, '{"f":"abc"}' => false, '{"f":5}' => false],
            ],
            'anyOf of a string and null, the 3.1 spelling' => [
                '3.1.0',
                ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                ['{"f":"x"}' => true, '{"f":null}' => true, '{"f":7}' => false, '{"f":true}' => false],
            ],
            'oneOf of an integer and null, the 3.1 spelling' => [
                '3.1.0',
                ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                ['{"f":7}' => true, '{"f":null}' => true, '{"f":"x"}' => false, '{"f":true}' => false],
            ],
            'oneOf of a nullable number and a string, the 3.0 spelling' => [
                '3.0.3',
                ['oneOf' => [['type' => 'number', 'nullable' => true], ['type' => 'string']]],
                ['{"f":null}' => true, '{"f":1.5}' => true, '{"f":"x"}' => true, '{"f":true}' => false],
            ],
            'list of a nullable number or a string, the 3.0 spelling' => [
                '3.0.3',
                ['type' => 'array', 'items' => ['oneOf' => [['type' => 'number', 'nullable' => true], ['type' => 'string']]]],
                ['{"f":[null]}' => true, '{"f":[1.5]}' => true, '{"f":["x"]}' => true, '{"f":[true]}' => false],
            ],
            'oneOf of a nullable integer and a string, the 3.0 spelling' => [
                '3.0.3',
                ['oneOf' => [['type' => 'integer', 'nullable' => true], ['type' => 'string']]],
                ['{"f":null}' => true, '{"f":1}' => true, '{"f":"x"}' => true, '{"f":true}' => false],
            ],
            'map of a nullable number or a string, the 3.0 spelling' => [
                '3.0.3',
                ['type' => 'object', 'additionalProperties' => ['oneOf' => [['type' => 'number', 'nullable' => true], ['type' => 'string']]]],
                ['{"f":{"a":null}}' => true, '{"f":{"a":1}}' => true, '{"f":{"a":true}}' => false],
            ],
            'optional oneOf of a nullable number and a string, the 3.0 spelling' => [
                '3.0.3',
                ['type' => 'object', 'properties' => ['g' => ['oneOf' => [['type' => 'number', 'nullable' => true], ['type' => 'string']]]]],
                ['{"f":{"g":null}}' => true, '{"f":{}}' => true, '{"f":{"g":1}}' => true, '{"f":{"g":true}}' => false],
            ],
            'optional anyOf of a number and a nullable string, the 3.0 spelling' => [
                '3.0.3',
                ['type' => 'object', 'properties' => ['g' => ['anyOf' => [['type' => 'number'], ['type' => 'string', 'nullable' => true]]]]],
                ['{"f":{"g":null}}' => true, '{"f":{"g":"x"}}' => true, '{"f":{"g":true}}' => false],
            ],
            'optional anyOf of a string and null, the 3.1 spelling' => [
                '3.1.0',
                ['type' => 'object', 'properties' => ['g' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]]],
                ['{"f":{"g":null}}' => true, '{"f":{"g":"x"}}' => true, '{"f":{}}' => true, '{"f":{"g":7}}' => false, '{"f":{"g":true}}' => false],
            ],
            'anyOf of a number and a nullable string, the 3.0 spelling' => [
                '3.0.3',
                ['anyOf' => [['type' => 'number'], ['type' => 'string', 'nullable' => true]]],
                ['{"f":null}' => true, '{"f":1.5}' => true, '{"f":"x"}' => true, '{"f":true}' => false],
            ],
        ];
    }

    /**
     * Containers whose members carry their own rules: positional `prefixItems` before `items`, declared
     * properties beside an `additionalProperties` schema, a map of a nullable enum, a map alias with a
     * sibling count keyword, an array alias of a string alias, and a JSON object where an array belongs.
     * Every mode must give the document's answer, and never crash.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $components
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param array<string, array<string, bool|null>> $diverging mode => the payloads that mode answers otherwise,
     *     by the README.support-matrix divergence named beside the case
     */
    #[DataProvider('containerShapeProvider')]
    public function testContainerMembersAreJudgedByTheirOwnSchema(
        string $openApiVersion,
        array $propertySchema,
        array $components,
        array $payloads,
        array $diverging = [],
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = self::probeSpec($propertySchema);
        $spec['openapi'] = $openApiVersion;
        $spec['components']['schemas'] += $components;

        $this->assertSame(
            expected: [],
            actual: $this->payloadTableMisses(
                spec: $spec,
                key: 'container shape ' . md5((string)json_encode($spec)),
                payloads: $payloads,
                diverging: $diverging,
            ),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, bool>, 4?: array<string, array<string, bool|null>>}>
     */
    public static function containerShapeProvider(): array
    {
        $tuple = ['type' => 'array', 'prefixItems' => [['type' => 'integer']], 'items' => ['type' => 'string']];
        $weights = ['Weights' => ['type' => 'object', 'additionalProperties' => ['type' => 'number']]];
        $uuid = '"0b7e6c1a-3f4d-4c2b-9a8e-1d2c3b4a5f60"';
        // "a `null` ITEM of a nullable list of dates or enums, or a `null` VALUE of such a map":
        // symfony/serializer hands the null to the enum or date normalizer, which refuses it.
        $symfonyRefusesANullMember = static fn(string $json): array => ['symfony' => [$json => false]];

        return [
            'prefixItems before items' => [
                '3.1.0',
                $tuple,
                [],
                [
                    '{"f":[]}' => true,
                    '{"f":[-75]}' => true,
                    '{"f":[-75,"a"]}' => true,
                    '{"f":[-75,"a","b"]}' => true,
                    '{"f":["a"]}' => false,
                    '{"f":[-75,5]}' => false,
                    '{"f":[-75,"a",5]}' => false,
                ],
            ],
            'prefixItems of two before items' => [
                '3.1.0',
                ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'boolean']], 'items' => ['type' => 'string']],
                [],
                ['{"f":[1,true,"a"]}' => true, '{"f":[1]}' => true, '{"f":[1,"a"]}' => false, '{"f":[1,true,2]}' => false],
            ],
            'prefixItems without items' => [
                '3.1.0',
                ['type' => 'array', 'prefixItems' => [['type' => 'integer']]],
                [],
                ['{"f":[1]}' => true, '{"f":[1,"a",true]}' => true, '{"f":["a"]}' => false],
            ],
            'prefixItems inside a list' => [
                '3.1.0',
                ['type' => 'array', 'items' => $tuple],
                [],
                ['{"f":[[-75],[-75,"a"]]}' => true, '{"f":[[-75,"a"],["a"]]}' => false, '{"f":[[-75,5]]}' => false],
            ],
            'declared property beside an additionalProperties schema' => [
                '3.1.0',
                ['type' => 'object', 'properties' => ['ibis' => ['type' => 'boolean']], 'additionalProperties' => ['type' => 'integer']],
                [],
                [
                    '{"f":{"ibis":true}}' => true,
                    '{"f":{"ibis":true,"x":3}}' => true,
                    '{"f":{"x":3}}' => true,
                    '{"f":{"ibis":true,"x":"a"}}' => false,
                    '{"f":{"ibis":3}}' => false,
                ],
                // "a key `additionalProperties: {schema}` admits beside the named properties": the
                // serializer and the hydrator fill the declared properties only.
                ['symfony' => ['{"f":{"ibis":true,"x":"a"}}' => true], 'yii3' => ['{"f":{"ibis":true,"x":"a"}}' => true]],
            ],
            'map of a nullable enum, the 3.1 spelling' => [
                '3.1.0',
                ['type' => 'object', 'additionalProperties' => ['anyOf' => [['type' => 'string', 'enum' => ['violet']], ['type' => 'null']]]],
                [],
                ['{"f":{"a":null}}' => true, '{"f":{"a":"violet"}}' => true, '{"f":{"a":"red"}}' => false, '{"f":{"a":3}}' => false],
                $symfonyRefusesANullMember('{"f":{"a":null}}'),
            ],
            'list of a nullable enum, the 3.1 spelling' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string', 'enum' => ['violet']], ['type' => 'null']]]],
                [],
                ['{"f":[null]}' => true, '{"f":["violet",null]}' => true, '{"f":["red"]}' => false, '{"f":[3]}' => false],
            ],
            'map of a nullable component enum' => [
                '3.1.0',
                ['type' => 'object', 'additionalProperties' => ['anyOf' => [['$ref' => '#/components/schemas/Hue'], ['type' => 'null']]]],
                ['Hue' => ['type' => 'string', 'enum' => ['violet', 'teal']]],
                ['{"f":{"a":null}}' => true, '{"f":{"a":"teal"}}' => true, '{"f":{"a":"red"}}' => false],
                $symfonyRefusesANullMember('{"f":{"a":null}}'),
            ],
            'list of a nullable component enum' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/Hue'], ['type' => 'null']]]],
                ['Hue' => ['type' => 'string', 'enum' => ['violet', 'teal']]],
                ['{"f":[null]}' => true, '{"f":["teal"]}' => true, '{"f":["red"]}' => false],
                $symfonyRefusesANullMember('{"f":[null]}'),
            ],
            'map of a nullable date' => [
                '3.1.0',
                ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'null'], 'format' => 'date']],
                [],
                ['{"f":{"a":null}}' => true, '{"f":{"a":"2020-01-02"}}' => true, '{"f":{"a":5}}' => false],
                $symfonyRefusesANullMember('{"f":{"a":null}}'),
            ],
            'map of a nullable enum, the 3.0 spelling' => [
                '3.0.3',
                ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['violet', null], 'nullable' => true]],
                [],
                ['{"f":{"a":null}}' => true, '{"f":{"a":"violet"}}' => true, '{"f":{"a":"red"}}' => false],
            ],
            'map alias with a sibling maxProperties' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Weights', 'maxProperties' => 1],
                $weights,
                [
                    '{"f":{}}' => true,
                    '{"f":{"a":1.5}}' => true,
                    '{"f":{"a":"x"}}' => false,
                    '{"f":null}' => false,
                    '{"f":{"a":1,"b":2}}' => false,
                ],
            ],
            'map alias with a sibling minProperties' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Weights', 'minProperties' => 1],
                $weights,
                ['{"f":{"a":1.5}}' => true, '{"f":{}}' => false, '{"f":{"a":"x"}}' => false],
            ],
            'map alias' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Weights'],
                $weights,
                ['{"f":{"a":1.5}}' => true, '{"f":{"a":"x"}}' => false, '{"f":null}' => false],
            ],
            'array alias of a string alias' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Ids'],
                [
                    'Uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'Ids' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Uuid'], 'minItems' => 1],
                ],
                ['{"f":[' . $uuid . ']}' => true, '{"f":[]}' => false, '{"f":["x"]}' => false, '{"f":[5]}' => false],
            ],
            'array of a string alias' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Uuid'], 'minItems' => 1],
                ['Uuid' => ['type' => 'string', 'format' => 'uuid']],
                ['{"f":[' . $uuid . ']}' => true, '{"f":[]}' => false, '{"f":["x"]}' => false],
            ],
            'optional array alias of a string alias' => [
                '3.1.0',
                ['type' => 'object', 'properties' => ['g' => ['$ref' => '#/components/schemas/Ids']]],
                [
                    'Uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'Ids' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Uuid'], 'minItems' => 1],
                ],
                ['{"f":{"g":[' . $uuid . ']}}' => true, '{"f":{"g":[]}}' => false, '{"f":{"g":["x"]}}' => false],
            ],
            'map alias with a sibling maxProperties, one level down' => [
                '3.1.0',
                ['type' => 'object', 'properties' => ['g' => ['$ref' => '#/components/schemas/Weights', 'maxProperties' => 1]]],
                $weights,
                ['{"f":{"g":{"a":1.5}}}' => true, '{"f":{"g":{"a":"x"}}}' => false, '{"f":{"g":{"a":1,"b":2}}}' => false],
            ],
            'array alias of a number alias with sibling item counts' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Amber', 'minItems' => 1, 'maxItems' => 3],
                [
                    'Azure' => ['type' => 'number', 'minimum' => -3.5, 'exclusiveMaximum' => -2],
                    'Amber' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Azure']],
                ],
                ['{"f":[-3]}' => true, '{"f":[]}' => false, '{"f":[0]}' => false],
            ],
            'array alias with sibling item counts in a class extended beside its reference' => [
                '3.1.0',
                ['$ref' => '#/components/schemas/Crimson', 'properties' => ['lynx' => ['type' => 'boolean']]],
                [
                    'Azure' => ['type' => 'number', 'minimum' => -3.5, 'exclusiveMaximum' => -2],
                    'Amber' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Azure']],
                    'Crimson' => ['type' => 'object', 'properties' => ['quokka' => ['$ref' => '#/components/schemas/Amber', 'minItems' => 1, 'maxItems' => 3]], 'required' => ['quokka']],
                ],
                ['{"f":{"quokka":[-3]}}' => true, '{"f":{"quokka":[]}}' => false, '{"f":{"quokka":[0]}}' => false],
            ],
            'a JSON object for an array of integers' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['type' => 'integer']],
                [],
                ['{"f":[1]}' => true, '{"f":[]}' => true, '{"f":{"zz":1}}' => false, '{"f":["a"]}' => false],
            ],
            'a JSON object for an array of strings' => [
                '3.1.0',
                ['type' => 'array', 'items' => ['type' => 'string']],
                [],
                ['{"f":["a"]}' => true, '{"f":{"zz":"a"}}' => false],
            ],
        ];
    }

    /**
     * Generate the spec in every mode and run each payload through it, then collect every answer that
     * differs from the expected one — every mode and payload first, then one assertion: the table of
     * misses says more than the first. A crash is a miss whatever was expected.
     *
     * @param array<string, mixed> $spec
     * @param array<string, bool> $payloads JSON payload => accepted
     * @param array<string, array<string, bool|null>> $diverging mode => the payloads that mode answers otherwise;
     *     `null` leaves the payload unasserted, but a crash is still a miss
     * @param list<GenerationMode> $skippedModes
     * @return list<string>
     */
    private function payloadTableMisses(array $spec, string $key, array $payloads, array $diverging = [], array $skippedModes = []): array
    {
        $misses = [];
        foreach (GenerationMode::cases() as $mode) {
            if (in_array($mode, $skippedModes, true)) {
                continue;
            }
            try {
                $fqcn = $this->generate($spec, $this->namespaceFor($mode, $key), $mode->value);
            } catch (Throwable $throwable) {
                $misses[] = sprintf('%s: generation failed: %s', $mode->value, $throwable->getMessage());

                continue;
            }
            if ($mode === GenerationMode::LaravelData) {
                LaravelDataContainer::boot();
            }
            foreach ($payloads as $json => $expected) {
                // Symfony as `#[MapRequestPayload]` runs it: a payload of the wrong type is a 422 there.
                $outcome = $this->payloadOutcome($mode, $fqcn, (string)$json, self::SYMFONY_REQUEST_PAYLOAD_CONTEXT);
                if (array_key_exists($json, $diverging[$mode->value] ?? [])) {
                    $expected = $diverging[$mode->value][$json];
                }
                // An unasserted answer is still an answer: a crash is a 500 whatever the verdict should be.
                if ($expected === null && $outcome->verdict !== PayloadVerdict::Crashed) {
                    continue;
                }
                $wanted = match ($expected) {
                    true => PayloadVerdict::Accepted,
                    false => PayloadVerdict::Refused,
                    // Unasserted: either verdict but a crash.
                    null => PayloadVerdict::Refused,
                };
                if ($outcome->verdict !== $wanted) {
                    $misses[] = sprintf(
                        '%s: %s %s, expected %s (%s: %s)',
                        $mode->value,
                        $json,
                        $outcome->verdict->value,
                        $wanted->value,
                        $outcome->stage,
                        $outcome->detail,
                    );
                }
            }
        }

        return $misses;
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
     * Through `ValidatingHydrator`, the setup README.yii3 makes mandatory: the hydrator casts before
     * any rule runs, and only the raw `#[Validate]` checks see what the payload actually said. A plain
     * `Hydrator` skips them by design, so measuring through it charged yii3 with gaps — a string cast
     * to `0`, an optional enum the hydrator could not fill and left absent — that the documented
     * setup does not have.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function yii3Verdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Yii3, $key), 'yii3');
        $this->skipYii3WithoutIntl($fqcn, $validJson, $key);

        $accepts = fn(string $json): bool => $this->yii3Outcome($fqcn, $json)->isAccepted();

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
            mode: GenerationMode::Yii3,
            expected: ['valid' => true, 'invalid' => true],
            reason: 'the hydrator CASTS before any rule runs — PhpNativeTypeCaster turned 5 into "5", so the '
            . 'type rule sees a valid value. Dropping that caster was measured too and is worse: '
            . 'valid payloads stop hydrating as well. Symfony mode diverges here for the same reason.',
        );

        // Symfony types the property `DateTimeImmutable`, so the serializer parses the string before
        // any constraint runs and PHP's parser is generous — the same leniency the scalar case
        // records. Pinned again one level down, because a container is reached through different
        // code and "the scalar diverges" is not proof that the item does.
        $lenientDateTime = self::diverges(
            mode: GenerationMode::Symfony,
            expected: ['valid' => true, 'invalid' => true],
            reason: 'the property is a DateTimeImmutable and the serializer parses the item before any '
            . 'constraint runs; PHP accepts what the schema does not, exactly as for a scalar '
            . 'date-time — see "a loose format: date-time string"',
        );

        // The properties a union BRANCH declares are not properties of the class an `allOf` beside it
        // makes: hydration keeps only what the class declares, and the branch's `required` is then asked
        // about an object that no longer carries the key. Folding branch properties into the class is a
        // design question of its own — branches are alternatives, and merging them as allOf restatements
        // would intersect their types — so it is recorded rather than guessed. Laravel modes validate the
        // raw payload and answer right.
        $branchPropertiesDropped = static fn(string $keyword): array => [
            ...self::diverges(
                mode: GenerationMode::Runtime,
                expected: ['valid' => false, 'invalid' => false],
                reason: 'the ' . $keyword . ' branch properties are not class properties, so hydration drops them '
                . 'and the branch `required` fails on what is left',
            ),
            ...self::diverges(
                mode: GenerationMode::Yii3,
                expected: ['valid' => false, 'invalid' => false],
                reason: 'the ' . $keyword . ' branch properties are not class properties, so hydration drops them '
                . 'and the branch `required` fails on what is left',
            ),
        ];

        return [
            // A `false` branch can never match, so this `anyOf` collapses to the single `type: string`
            // branch and the property is typed `?string` — but no raw `#[Validate]` is emitted for a
            // composition, so the caster above reaches the value first. The boolean is carried into
            // the constraints correctly; what diverges is the coercion alone.
            'anyOf with a false branch' => $coerced,
            'oneOf with a false branch' => $coerced,
            'allOf beside an anyOf of objects' => [
                ...$branchPropertiesDropped('anyOf'),
                // The class-level `anyOf` waits for an `Assert\AtLeastOneOf` that Symfony mode emits for
                // a PROPERTY only, so on a class nothing checks it — the same hole a `required`-only
                // `anyOf` beside an `allOf` has had all along.
                ...self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => true, 'invalid' => true],
                    reason: 'a class-level anyOf is left to Assert\AtLeastOneOf, which is emitted for properties only',
                ),
            ],
            'allOf beside a oneOf of objects' => [
                ...$branchPropertiesDropped('oneOf'),
                ...self::diverges(
                    mode: GenerationMode::Symfony,
                    expected: ['valid' => false, 'invalid' => false],
                    reason: 'the oneOf branch properties are not class properties, so the serializer drops them '
                    . 'and the branch `required` fails on what is left',
                ),
            ],
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
                condition: GenerationMode::Yii3->isLast(),
                message: 'yii3 may be skipped for a missing ext-intl, and a skip aborts the whole test — so it '
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

        // The runtime deserializer validates on the way in; normalizing re-checks the DTO.
        $accepts = fn(string $json): bool => $this->runtimeOutcome($fqcn, $json)->isAccepted();

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * The JSON the runtime writes back for a payload it accepted, or the refusal.
     *
     * @param array<string, mixed> $spec
     */
    private function runtimeRoundTrip(array $spec, string $key, string $json): string
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Runtime, $key), 'runtime');
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            return (new DtoNormalizer())->validateAndNormalizeToJson((new DtoDeserializer())->deserialize($request, $fqcn));
        } catch (RuntimeException $e) {
            return 'refused: ' . $e->getMessage();
        }
    }

    /**
     * A FormRequest hands the controller `toDto()`, so hydration is part of the verdict: a payload the
     * rules accept but the DTO cannot hold is not "accepted" in any useful sense.
     *
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function laravelVerdict(array $spec, string $key, string $validJson, string $invalidJson): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Laravel, $key), 'laravel');

        $accepts = fn(string $json): bool => $this->laravelOutcome($fqcn, $json)->isAccepted();

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

        $accepts = fn(string $json): bool => $this->laravelDataOutcome($fqcn, $json)->isAccepted();

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{valid: bool, invalid: bool}
     */
    private function symfonyVerdict(array $spec, string $key, string $validJson, string $invalidJson, array $context = []): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Symfony, $key), 'symfony');

        $accepts = fn(string $json): bool => $this->symfonyOutcome($fqcn, $json, $context)->isAccepted();

        return ['valid' => $accepts($validJson), 'invalid' => $accepts($invalidJson)];
    }
}
