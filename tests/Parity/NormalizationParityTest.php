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
use stdClass;
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
use Throwable;

/**
 * The OTHER half of parity: the response direction.
 *
 * `ValidationParityTest` compares validation verdicts — whether a payload is accepted. It cannot see
 * the shape of what comes back out, because the modes normalize through different code
 * (`DtoNormalizer`, the Symfony Serializer, the emitted `toArray()`). This test pins that shape: the
 * same JSON goes in, and the resulting array is asserted for every mode.
 *
 * A case states ONE expectation — the reference mode's — and any mode that cannot meet it declares
 * its own expectation plus a reason. So a mode says nothing at all when it agrees, which is what
 * makes the mode list additive; and `testEveryDivergenceIsDocumented` enforces the reason, so a new
 * divergence cannot be introduced without someone writing down why.
 */
final class NormalizationParityTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-normalization-parity';
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
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @param array<string, array<string, mixed>> $expectedByMode keyed by mode value; the reference
     *        mode's entry is the common expectation, another mode's entry is a declared divergence
     * @param array<string, string> $reasonByMode keyed by mode value
     */
    #[DataProvider('normalizationProvider')]
    public function testEveryModeNormalizesTheSameWay(
        string $key,
        array $schema,
        array $extraSchemas,
        string $json,
        array $expectedByMode,
        array $reasonByMode,
        array $unsupported,
    ): void {
        $spec = self::spec($schema, $extraSchemas);
        $common = $expectedByMode[GenerationMode::reference()->value];

        foreach (GenerationMode::cases() as $mode) {
            $unsupportedReason = $unsupported[$mode->value] ?? null;
            if ($unsupportedReason !== null) {
                // A declared gap, pinned rather than skipped: the mode must still FAIL, so the day it
                // starts working this assertion breaks and the declaration has to go.
                $this->assertNotNull(
                    $this->normalizationFailure($mode, $spec, $key, $json),
                    sprintf(
                        '"%s" is declared unsupported in %s mode but it worked — drop the declaration: %s',
                        $key,
                        $mode->value,
                        $unsupportedReason,
                    ),
                );

                continue;
            }

            $observed = $this->normalization($mode, $spec, $key, $json);
            $declared = $expectedByMode[$mode->value] ?? null;
            $reason = $reasonByMode[$mode->value] ?? null;

            if ($declared !== null) {
                $this->assertSame(
                    $declared,
                    $observed,
                    sprintf('%s normalization changed for "%s"', $mode->value, $key),
                );

                continue;
            }

            // No expectation of its own: the mode is held to the common one, unless it declares a
            // reason — in which case the difference must actually be there, or the reason is stale.
            if ($reason === null) {
                $this->assertSame(
                    $common,
                    $observed,
                    sprintf('%s must normalize like %s on "%s" (or declare a reason)', $mode->value, GenerationMode::reference()->value, $key),
                );

                continue;
            }

            $this->assertNotSame(
                $common,
                $observed,
                sprintf('"%s" declares a %s divergence but there is none — drop the reason', $key, $mode->value),
            );
        }
    }

    /**
     * The expectations above are only trustworthy while every inequality carries a reason, so the
     * two are checked against each other.
     *
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @param array<string, array<string, mixed>> $expectedByMode
     * @param array<string, string> $reasonByMode
     */
    #[DataProvider('normalizationProvider')]
    public function testEveryDivergenceIsDocumented(
        string $key,
        array $schema,
        array $extraSchemas,
        string $json,
        array $expectedByMode,
        array $reasonByMode,
        array $unsupported,
    ): void {
        $reference = GenerationMode::reference();
        $common = $expectedByMode[$reference->value];
        $this->assertArrayNotHasKey(
            $reference->value,
            $reasonByMode,
            sprintf('"%s" gives the reference mode a divergence reason — it IS the expectation', $key),
        );

        foreach (GenerationMode::others() as $mode) {
            if (array_key_exists($mode->value, $unsupported)) {
                continue;
            }

            $declared = $expectedByMode[$mode->value] ?? null;
            if ($declared === null) {
                continue;
            }

            if ($declared === $common) {
                $this->assertArrayNotHasKey(
                    $mode->value,
                    $reasonByMode,
                    sprintf('"%s" declares a %s divergence but the expectations agree — drop the reason', $key, $mode->value),
                );

                continue;
            }

            $this->assertArrayHasKey(
                $mode->value,
                $reasonByMode,
                sprintf(
                    "\"%s\" normalizes differently in %s mode and says nothing about it\n %s: %s\n %s: %s",
                    $key,
                    $mode->value,
                    $reference->value,
                    json_encode($common),
                    $mode->value,
                    json_encode($declared),
                ),
            );
        }
    }

    /**
     * A payload this corpus calls valid must also pass the DTO's OWN output check.
     *
     * `DtoNormalizer::validate()` reads item types off the getter docblocks, so it can disagree with
     * what the DTO actually holds while the wire shape stays perfectly right. That is exactly what
     * happened to a list of maps: every element was reported as the map's VALUE type, so a valid
     * payload came back with one error per element — and normalization parity could not see it,
     * because it compares output and the output was correct. This walks the same corpus and asserts
     * the verdict is silence, which makes the whole shape table a net for that class of mistake
     * rather than only for the wire form.
     *
     * Runtime mode only: it is the mode that owns `DtoNormalizer`.
     *
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @param array<string, array<string, mixed>> $expectedByMode
     * @param array<string, string> $reasonByMode
     * @param array<string, string> $unsupported
     */
    #[DataProvider('normalizationProvider')]
    public function testRuntimeReportsNoProblemForAnyCorpusPayload(
        string $key,
        array $schema,
        array $extraSchemas,
        string $json,
        array $expectedByMode,
        array $reasonByMode,
        array $unsupported,
    ): void {
        $mode = GenerationMode::reference();
        $unsupportedReason = $unsupported[$mode->value] ?? null;
        if ($unsupportedReason !== null) {
            // The case declares the reference mode cannot build this DTO at all; there is no
            // verdict to read. `testEveryModeNormalizesTheSameWay` is what pins the failure.
            self::markTestSkipped(sprintf('"%s" is declared unsupported in %s mode: %s', $key, $mode->value, $unsupportedReason));
        }

        $fqcn = $this->generate(self::spec($schema, $extraSchemas), $this->namespaceFor($mode, $key), $mode->value);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $dto = (new DtoDeserializer())->deserialize($request, $fqcn);

        $this->assertSame(
            [],
            (new DtoNormalizer())->validate($dto),
            sprintf('"%s" is a valid payload, but the DTO reports a problem with itself', $key),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: string, 4: array<string, mixed>, 5: array<string, mixed>, 6: ?string}>
     */
    public static function normalizationProvider(): array
    {
        $nullOmission = 'runtime omits a property it never received (UnsetValue), Symfony has no '
            . 'presence tracking and emits an explicit null — the response direction of the same '
            . 'limitation as PATCH support';

        $cases = [
            'scalars' => [
                'schema' => self::object(
                    ['s' => ['type' => 'string'], 'i' => ['type' => 'integer'], 'n' => ['type' => 'number'], 'b' => ['type' => 'boolean']],
                    ['s', 'i', 'n', 'b'],
                ),
                'json' => '{"s":"a","i":1,"n":1.5,"b":true}',
                'runtime' => ['s' => 'a', 'i' => 1, 'n' => 1.5, 'b' => true],
                'symfony' => ['s' => 'a', 'i' => 1, 'n' => 1.5, 'b' => true],
            ],
            'falsy values survive' => [
                'schema' => self::object(
                    ['b' => ['type' => 'boolean'], 'i' => ['type' => 'integer'], 's' => ['type' => 'string']],
                    ['b', 'i', 's'],
                ),
                'json' => '{"b":false,"i":0,"s":""}',
                'runtime' => ['b' => false, 'i' => 0, 's' => ''],
                'symfony' => ['b' => false, 'i' => 0, 's' => ''],
            ],
            'optional property missing' => [
                'schema' => self::object(['s' => ['type' => 'string'], 'opt' => ['type' => 'string']], ['s']),
                'json' => '{"s":"a"}',
                'runtime' => ['s' => 'a'],
                'symfony' => ['opt' => null, 's' => 'a'],
                'reason' => $nullOmission,
            ],
            'explicit null is kept by both' => [
                'schema' => self::object(['s' => ['type' => 'string'], 'opt' => ['type' => ['string', 'null']]], ['s', 'opt']),
                'json' => '{"s":"a","opt":null}',
                'runtime' => ['s' => 'a', 'opt' => null],
                'symfony' => ['s' => 'a', 'opt' => null],
            ],
            'date stays a date' => [
                'schema' => self::object(['on' => ['type' => 'string', 'format' => 'date']], ['on']),
                'json' => '{"on":"2026-03-10"}',
                'runtime' => ['on' => '2026-03-10'],
                'symfony' => ['on' => '2026-03-10'],
            ],
            'date-time' => [
                'schema' => self::object(['at' => ['type' => 'string', 'format' => 'date-time']], ['at']),
                'json' => '{"at":"2026-03-10T12:00:00+00:00"}',
                'runtime' => ['at' => '2026-03-10T12:00:00+00:00'],
                'symfony' => ['at' => '2026-03-10T12:00:00+00:00'],
            ],
            'date-time keeps its offset' => [
                'schema' => self::object(['at' => ['type' => 'string', 'format' => 'date-time']], ['at']),
                'json' => '{"at":"2026-03-10T12:00:00+03:00"}',
                'runtime' => ['at' => '2026-03-10T12:00:00+03:00'],
                'symfony' => ['at' => '2026-03-10T12:00:00+03:00'],
            ],
            'date-time with microseconds' => [
                'schema' => self::object(['at' => ['type' => 'string', 'format' => 'date-time']], ['at']),
                'json' => '{"at":"2026-03-10T12:00:00.123456+03:00"}',
                'runtime' => ['at' => '2026-03-10T12:00:00.123456+03:00'],
                'symfony' => ['at' => '2026-03-10T12:00:00.123456+03:00'],
                'diverges' => [
                    'laravel-data' => [
                        'expected' => ['at' => '2026-03-10T12:00:00+03:00'],
                        'reason' => 'a laravel-data transformer takes ONE format string, so it cannot '
                            . 'say "keep the sub-second precision the payload carried" — the emitted '
                            . '#[WithCast] accepts all four patterns on the way in, and the way out is '
                            . 'ATOM',
                    ],
                ],
            ],
            'array of dates stays dates' => [
                'schema' => self::object(
                    ['on' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']]],
                    ['on'],
                ),
                'json' => '{"on":["2026-03-10","2026-03-11"]}',
                'runtime' => ['on' => ['2026-03-10', '2026-03-11']],
                'symfony' => ['on' => ['2026-03-10', '2026-03-11']],
            ],
            'array of date-times keeps the item format' => [
                'schema' => self::object(
                    ['at' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']]],
                    ['at'],
                ),
                'json' => '{"at":["2026-03-10T12:00:00+03:00"]}',
                'runtime' => ['at' => ['2026-03-10T12:00:00+03:00']],
                'symfony' => ['at' => ['2026-03-10T12:00:00+03:00']],
            ],
            'enum' => [
                'schema' => self::object(['e' => ['type' => 'string', 'enum' => ['a', 'b']]], ['e']),
                'json' => '{"e":"a"}',
                'runtime' => ['e' => 'a'],
                'symfony' => ['e' => 'a'],
            ],
            'array of enums' => [
                'schema' => self::object(['es' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']]]], ['es']),
                'json' => '{"es":["a","b"]}',
                'runtime' => ['es' => ['a', 'b']],
                'symfony' => ['es' => ['a', 'b']],
            ],
            'array of scalars' => [
                'schema' => self::object(['list' => ['type' => 'array', 'items' => ['type' => 'string']]], ['list']),
                'json' => '{"list":["a","b"]}',
                'runtime' => ['list' => ['a', 'b']],
                'symfony' => ['list' => ['a', 'b']],
            ],
            'empty array stays a list' => [
                'schema' => self::object(['list' => ['type' => 'array', 'items' => ['type' => 'string']]], ['list']),
                'json' => '{"list":[]}',
                'runtime' => ['list' => []],
                'symfony' => ['list' => []],
            ],
            // This used to drop the whole value in BOTH modes: a bare `type: object` was
            // materialized into an empty DTO class. It is now a map in both, and the only
            // remaining difference is the stdClass/array one below.
            // A property with NO type at all — the one shape that cannot carry `Optional`, because PHP
            // refuses `mixed|Optional`. So laravel-data has nowhere to put "absent" and fills it with null.
            'untyped optional property missing' => [
                'schema' => self::object(['s' => ['type' => 'string'], 'any' => ['description' => 'anything']], ['s']),
                'json' => '{"s":"a"}',
                'runtime' => ['s' => 'a'],
                'symfony' => ['any' => null, 's' => 'a'],
                'reason' => $nullOmission,
                'diverges' => [
                    'laravel-data' => [
                        'expected' => ['s' => 'a', 'any' => null],
                        'reason' => 'an untyped property is plain `mixed`, and `mixed` cannot take part in a '
                            . 'union type — so this is the only property shape with no `|Optional` to mark '
                            . 'absence with. laravel-data fills the missing key with null and echoes it, the '
                            . 'same limitation Symfony mode has for every optional property.',
                    ],
                ],
            ],
            // A `default` is what the SERVER may assume, not something the wire said. The three modes
            // that track presence report the key as absent and leave the default to the application;
            // Symfony fills the property from the constructor default and cannot tell the two apart —
            // the same limitation as every other optional property there, reached from a new direction.
            'optional property with a default, absent' => [
                'schema' => self::object(
                    ['id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'default' => 25]],
                    ['id'],
                ),
                'json' => '{"id":1}',
                'runtime' => ['id' => 1],
                'symfony' => ['limit' => 25, 'id' => 1],
                'reason' => 'the constructor default IS the Symfony DTO\'s value for an absent key, so a '
                    . 'schema default is indistinguishable from one the client sent — the response '
                    . 'direction of the same missing presence tracking',
            ],
            // A `format: date` default is the one default that cannot be written as a constant
            // expression: the property is a DateTimeImmutable, and naming an enum case on it emitted
            // `DateTimeImmutable::VALUE_2020_01_01` — a fatal on class load in symfony mode and on
            // the first defaulted construction in runtime and laravel. Nothing asserted a defaulted
            // temporal property before, which is why three modes shipped unloadable classes.
            'optional temporal property with a default, absent' => [
                'schema' => self::object(
                    ['id' => ['type' => 'integer'], 'on' => ['type' => 'string', 'format' => 'date', 'default' => '2020-01-01']],
                    ['id'],
                ),
                'json' => '{"id":1}',
                'runtime' => ['id' => 1],
                'symfony' => ['on' => '2020-01-01', 'id' => 1],
                'reason' => 'the same absent-with-a-default difference as the integer case above: the '
                    . 'default IS the Symfony DTO\'s value, and it has no presence tracking to tell '
                    . 'that apart from a date the client sent',
            ],
            'free-form object' => [
                'schema' => self::object(['any' => ['type' => 'object']], ['any']),
                'json' => '{"any":{"k":[1,{"z":null}]}}',
                'runtime' => ['any' => ['#object' => ['k' => [1, ['#object' => ['z' => null]]]]]],
                'symfony' => ['any' => ['k' => [1, ['z' => null]]]],
                'reason' => 'same stdClass-vs-array difference as the map cases, applied at every '
                    . 'level of a free-form value',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a free-form value is held as a PHP array, so a nested {} and a '
                            . 'nested [] are the same value by the time anything of ours looks',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                ],
            ],
            'additionalProperties map' => [
                'schema' => self::object(['map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]], ['map']),
                'json' => '{"map":{"a":1,"b":2}}',
                'runtime' => ['map' => ['#object' => ['a' => 1, 'b' => 2]]],
                'symfony' => ['map' => ['a' => 1, 'b' => 2]],
                'reason' => 'runtime normalizes a map to stdClass so it always encodes as a JSON '
                    . 'object, Symfony keeps the PHP array — identical JSON while the map has keys, '
                    . 'see the empty-map case for where it stops being identical',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a map is held as a PHP array, so the wire shape ({} versus []) '
                            . 'is already gone — the same limit laravel-data declares',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                ],
            ],
            // The three cases below pair each transformed value type with the MAP container. The
            // array container is pinned above for all three; a map reaches the same value through a
            // different branch of the emitter, and `format: date` items proved that a container the
            // matrix does not name is a container nothing checks.
            'map of dates' => [
                'schema' => self::object(
                    ['on' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'format' => 'date']]],
                    ['on'],
                ),
                'json' => '{"on":{"a":"2026-03-10","b":"2026-03-11"}}',
                'runtime' => ['on' => ['#object' => ['a' => '2026-03-10', 'b' => '2026-03-11']]],
                'symfony' => ['on' => ['a' => '2026-03-10', 'b' => '2026-03-11']],
                'reason' => 'the same stdClass-versus-array difference as every other map; the VALUES '
                    . 'are the point here — a date must stay a date whichever container holds it',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a map is held as a PHP array, so the wire shape ({} versus []) '
                            . 'is already gone — the same limit laravel-data declares',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                ],
            ],
            'map of enums' => [
                'schema' => self::object(
                    ['es' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['a', 'b']]]],
                    ['es'],
                ),
                'json' => '{"es":{"x":"a","y":"b"}}',
                'runtime' => ['es' => ['#object' => ['x' => 'a', 'y' => 'b']]],
                'symfony' => ['es' => ['x' => 'a', 'y' => 'b']],
                'reason' => 'the same stdClass-versus-array difference as every other map; the values '
                    . 'are enum cases, which every mode writes back as their backing value',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a map is held as a PHP array, so the wire shape ({} versus []) '
                            . 'is already gone — the same limit laravel-data declares',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                ],
            ],
            'map of nested objects' => [
                'schema' => self::object(
                    ['kids' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Child']]],
                    ['kids'],
                ),
                'extra' => ['Child' => self::object(['id' => ['type' => 'integer']], ['id'])],
                'json' => '{"kids":{"a":{"id":1},"b":{"id":2}}}',
                'runtime' => ['kids' => ['#object' => ['a' => ['id' => 1], 'b' => ['id' => 2]]]],
                'symfony' => ['kids' => ['a' => ['id' => 1], 'b' => ['id' => 2]]],
                'reason' => 'the same stdClass-versus-array difference as every other map; the values '
                    . 'are DTOs, so this also pins that a nested object is reached through a map',
                'diverges' => [
                    'laravel' => [
                        'expected' => ['kids' => ['#object' => ['a' => ['#object' => ['id' => 1]], 'b' => ['#object' => ['id' => 2]]]]],
                        'reason' => 'laravel casts a map DEEP, on purpose: a free-form value can nest '
                            . 'maps at any level, so `toJsonObjects()` recurses and turns every keyed '
                            . 'sub-array into an object — including the array the nested DTO\'s own '
                            . '`toArray()` just produced. Runtime casts only the map it owns and leaves '
                            . 'that array alone. Identical JSON while the nested object has keys; they '
                            . 'part company when it is EMPTY, and there laravel is the one writing what '
                            . '`type: object` says.',
                    ],
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a map is held as a PHP array, so the wire shape ({} versus []) '
                            . 'is already gone — the same limit laravel-data declares',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                ],
            ],
            'empty map' => [
                'schema' => self::object(['map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]], ['map']),
                'json' => '{"map":{}}',
                'runtime' => ['map' => ['#object' => []]],
                'symfony' => ['map' => []],
                'reason' => 'the stdClass/array difference becomes visible on the wire: runtime '
                    . 'encodes {} (an object, as the schema says), Symfony encodes [] (an array)',
                'diverges' => [
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does: '
                            . 'its normalizer has no notion of the wire shape, so an empty map encodes '
                            . 'as [] rather than {}',
                    ],
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'the object holds a PHP array, and a JSON {} decodes to the same '
                            . 'empty array as [], so the wire shape is gone before anything of ours '
                            . 'sees it — the same limit laravel-data declares here',
                    ],
                ],
            ],
            // Runtime used to fail this outright: the item type was read off the innermost generic,
            // so `{"maps":[{}]}` could not be deserialized at all, and an empty map inside a list
            // encoded as `[]` while the same map at property level encoded as `{}`.
            'list of maps' => [
                'schema' => self::object(
                    ['maps' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]]],
                    ['maps'],
                ),
                'json' => '{"maps":[{},{"b":2}]}',
                'runtime' => ['maps' => [['#object' => []], ['#object' => ['b' => 2]]]],
                'symfony' => ['maps' => [[], ['b' => 2]]],
                'reason' => 'the same stdClass-vs-array difference as the map cases, one level deeper: '
                    . 'runtime casts every map — including one inside a list — so an empty item '
                    . 'encodes as {}, Symfony leaves the PHP array and encodes []',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'each map in the list is a PHP array, so an empty one encodes as '
                            . '[] rather than {} — the map limit, once per element',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does, '
                            . 'inside a list too',
                    ],
                ],
            ],
            // A list of LISTS, which the emitter used to declare `array<mixed>` — the one item type
            // nothing checks. Pinned for the wire shape too: `[[1,2]]` must survive as nested JSON
            // arrays, and the cast briefly demanded objects there because the map form and the list
            // form of a nested container were the same `array` to it.
            'list of lists' => [
                'schema' => self::object(
                    ['matrix' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
                    ['matrix'],
                ),
                'json' => '{"matrix":[[1,2],[3]]}',
                'runtime' => ['matrix' => [[1, 2], [3]]],
                'symfony' => ['matrix' => [[1, 2], [3]]],
            ],
            'map of lists' => [
                'schema' => self::object(
                    ['byKey' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ]],
                    ['byKey'],
                ),
                'json' => '{"byKey":{"a":["x","y"]}}',
                'runtime' => ['byKey' => ['#object' => ['a' => ['x', 'y']]]],
                'symfony' => ['byKey' => ['a' => ['x', 'y']]],
                'reason' => 'the same stdClass-versus-array difference as every other map; what is '
                    . 'pinned here is that the VALUES stay lists',
                'diverges' => [
                    'yii3' => [
                        'like' => 'symfony',
                        'reason' => 'a map is held as a PHP array, so the wire shape ({} versus []) '
                            . 'is already gone — the same limit laravel-data declares',
                    ],
                    'laravel-data' => [
                        'like' => 'symfony',
                        'reason' => 'laravel-data keeps the PHP array for the same reason Symfony does',
                    ],
                ],
            ],
            'aliased property names' => [
                'schema' => self::object(
                    ['first_name' => ['type' => 'string'], 'x-trace-id' => ['type' => 'string']],
                    ['first_name', 'x-trace-id'],
                ),
                'json' => '{"first_name":"John","x-trace-id":"abc"}',
                'runtime' => ['first_name' => 'John', 'x-trace-id' => 'abc'],
                'symfony' => ['first_name' => 'John', 'x-trace-id' => 'abc'],
            ],
            // A name PHP cannot put in a single-quoted literal unescaped. Both characters END the
            // literal, so getting this wrong is not a wrong VALUE — it is a generated file that does
            // not parse, which runtime and yii3 modes shipped for two releases. The golden corpus
            // answers the parse question for all five modes; this answers the round-trip one.
            'property names carrying a quote and a backslash' => [
                'schema' => self::object(
                    ["it's" => ['type' => 'string'], 'back\slash' => ['type' => 'string'], "both\\'s" => ['type' => 'string']],
                    ["it's", 'back\slash', "both\\'s"],
                ),
                'json' => '{"it\'s":"a","back\\\slash":"b","both\\\\\'s":"c"}',
                'runtime' => ["it's" => 'a', 'back\slash' => 'b', "both\\'s" => 'c'],
                'symfony' => ["it's" => 'a', 'back\slash' => 'b', "both\\'s" => 'c'],
            ],
            // Two keys differing only in case need two PHP identifiers, because PHP method names are
            // case-insensitive. Whatever the generator renames them to, the wire keys must come back
            // exactly as the schema spells them — in both modes.
            'keys differing only in case' => [
                'schema' => self::object(
                    ['name' => ['type' => 'string'], 'NAme' => ['type' => 'string']],
                    ['name', 'NAme'],
                ),
                'json' => '{"name":"a","NAme":"b"}',
                'runtime' => ['name' => 'a', 'NAme' => 'b'],
                'symfony' => ['name' => 'a', 'NAme' => 'b'],
            ],
            'time format' => [
                'schema' => self::object(['t' => ['type' => 'string', 'format' => 'time']], ['t']),
                'json' => '{"t":"12:30:00+00:00"}',
                'runtime' => ['t' => '12:30:00+00:00'],
                'symfony' => ['t' => '12:30:00+00:00'],
            ],
            'nested object with a missing optional' => [
                'schema' => self::object(['child' => ['$ref' => '#/components/schemas/Child']], ['child']),
                'extra' => ['Child' => self::object(['id' => ['type' => 'integer'], 'note' => ['type' => 'string']], ['id'])],
                'json' => '{"child":{"id":7}}',
                'runtime' => ['child' => ['id' => 7]],
                'symfony' => ['child' => ['note' => null, 'id' => 7]],
                'reason' => $nullOmission . ' — it applies at every nesting level',
            ],
            'array of nested objects' => [
                'schema' => self::object(['kids' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Child']]], ['kids']),
                'extra' => ['Child' => self::object(['id' => ['type' => 'integer']], ['id'])],
                'json' => '{"kids":[{"id":1},{"id":2}]}',
                'runtime' => ['kids' => [['id' => 1], ['id' => 2]]],
                'symfony' => ['kids' => [['id' => 1], ['id' => 2]]],
            ],
            // `type: object` with nothing to write is `{}`, never `[]`. PHP spells both as the empty
            // array, so the cast has to happen where the schema is still known — the generated
            // `jsonSerialize()` and `DtoNormalizer` both do it now. It used to leave as `[]` in every
            // position at once: on its own, inside a list and inside a map.
            'empty nested object' => [
                'schema' => self::object(['kid' => ['$ref' => '#/components/schemas/Child']], ['kid']),
                'extra' => ['Child' => self::object(['id' => ['type' => 'integer']])],
                'json' => '{"kid":{}}',
                'runtime' => ['kid' => ['#object' => []]],
                'symfony' => ['kid' => ['id' => null]],
                'reason' => 'symfony never gets as far as the shape question: with no presence '
                    . 'tracking the nested object writes its absent property as an explicit null, so '
                    . 'the array is not empty in the first place — the nested form of $nullOmission',
                'diverges' => [
                    'laravel-data' => [
                        'expected' => ['kid' => []],
                        'reason' => 'the transformation is spatie\'s, and its normalizer has no notion '
                            . 'of the wire shape — the same limit it declares on an empty map, reached '
                            . 'through a nested object instead',
                    ],
                ],
            ],
            'deep nesting' => [
                'schema' => self::object(['a' => ['$ref' => '#/components/schemas/A']], ['a']),
                'extra' => [
                    'A' => self::object(['b' => ['$ref' => '#/components/schemas/B']], ['b']),
                    'B' => self::object(['c' => ['type' => 'string']], ['c']),
                ],
                'json' => '{"a":{"b":{"c":"deep"}}}',
                'runtime' => ['a' => ['b' => ['c' => 'deep']]],
                'symfony' => ['a' => ['b' => ['c' => 'deep']]],
            ],
            'nullable nested object set to null' => [
                'schema' => self::object(
                    ['child' => ['oneOf' => [['$ref' => '#/components/schemas/Child'], ['type' => 'null']]]],
                    ['child'],
                ),
                'extra' => ['Child' => self::object(['id' => ['type' => 'integer']], ['id'])],
                'json' => '{"child":null}',
                'runtime' => ['child' => null],
                'symfony' => ['child' => null],
            ],
            'discriminated union' => [
                'schema' => self::object(['shape' => ['$ref' => '#/components/schemas/Shape']], ['shape']),
                'extra' => [
                    'Shape' => [
                        'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['circle' => '#/components/schemas/Circle', 'square' => '#/components/schemas/Square'],
                        ],
                    ],
                    'Circle' => self::object(['kind' => ['type' => 'string'], 'r' => ['type' => 'integer']], ['kind', 'r']),
                    'Square' => self::object(['kind' => ['type' => 'string'], 'a' => ['type' => 'integer']], ['kind', 'a']),
                ],
                'json' => '{"shape":{"kind":"circle","r":3}}',
                'runtime' => ['shape' => ['kind' => 'circle', 'r' => 3]],
                'symfony' => ['shape' => ['kind' => 'circle', 'r' => 3]],
                'diverges' => [
                    'yii3' => [
                        'expected' => [],
                        'reason' => 'the hydrator cannot pick a branch: a property typed by the union '
                            . 'interface has no rule telling it which member to build, and yiisoft has '
                            . 'no discriminator mapping of its own. The object comes back with the '
                            . 'property unset, which is what an empty array here means. Validation of '
                            . 'a member built by hand still works — see Yii3RequestShapeTest.',
                    ],
                    'laravel-data' => [
                        'expected' => ['shape' => ['r' => 3, 'kind' => 'circle']],
                        'reason' => 'the discriminated base is an abstract Data class here, not an '
                            . 'interface, so the discriminator is an INHERITED property — and PHP '
                            . 'reflection lists a class\'s own properties before its parent\'s, which is '
                            . 'the order laravel-data normalizes in. Same keys and same values; JSON '
                            . 'object order carries no meaning, and assertSame is the only thing that '
                            . 'sees a difference here.',
                    ],
                ],
            ],
            'array of a discriminated union' => [
                'schema' => self::object(
                    ['shapes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']]],
                    ['shapes'],
                ),
                'extra' => [
                    'Shape' => [
                        'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['circle' => '#/components/schemas/Circle', 'square' => '#/components/schemas/Square'],
                        ],
                    ],
                    'Circle' => self::object(['kind' => ['type' => 'string'], 'r' => ['type' => 'integer']], ['kind', 'r']),
                    'Square' => self::object(['kind' => ['type' => 'string'], 'a' => ['type' => 'integer']], ['kind', 'a']),
                ],
                'json' => '{"shapes":[{"kind":"circle","r":3},{"kind":"square","a":4}]}',
                'runtime' => ['shapes' => [['kind' => 'circle', 'r' => 3], ['kind' => 'square', 'a' => 4]]],
                'symfony' => ['shapes' => [['kind' => 'circle', 'r' => 3], ['kind' => 'square', 'a' => 4]]],
                'diverges' => [
                    'yii3' => [
                        'expected' => ['shapes' => []],
                        'reason' => 'the same limit as the scalar union case: nothing tells the hydrator '
                            . 'which member an element is, and yiisoft has no discriminator mapping of '
                            . 'its own. The list survives as a PROPERTY — unlike the scalar case, where '
                            . 'the property itself stays unset — but not one element of it can be built, '
                            . 'so it arrives empty.',
                    ],
                    'laravel-data' => [
                        'expected' => ['shapes' => [['r' => 3, 'kind' => 'circle'], ['a' => 4, 'kind' => 'square']]],
                        'reason' => 'the same inherited-discriminator key order as the scalar union case, '
                            . 'once per element: the base is an abstract Data class, so `kind` is an '
                            . 'INHERITED property and PHP reflection lists a class\'s own properties '
                            . 'first. Same keys and same values; only assertSame sees it.',
                    ],
                ],
            ],
            // The same union with a discriminator whose wire name is NOT a PHP identifier. laravel-data
            // reads the discriminator before it has an object — `DataMorphClassResolver` looks the value
            // up by the property name and by its input-mapped name — so the mapping attribute has to be
            // on the morph base as well, or a payload every other mode hydrates comes back a 422.
            'discriminated union with a mapped discriminator name' => [
                'schema' => self::object(['shape' => ['$ref' => '#/components/schemas/Shape']], ['shape']),
                'extra' => [
                    'Shape' => [
                        'oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']],
                        'discriminator' => [
                            'propertyName' => 'pet_type',
                            'mapping' => ['circle' => '#/components/schemas/Circle', 'square' => '#/components/schemas/Square'],
                        ],
                    ],
                    'Circle' => self::object(['pet_type' => ['type' => 'string'], 'r' => ['type' => 'integer']], ['pet_type', 'r']),
                    'Square' => self::object(['pet_type' => ['type' => 'string'], 'a' => ['type' => 'integer']], ['pet_type', 'a']),
                ],
                'json' => '{"shape":{"pet_type":"circle","r":3}}',
                'runtime' => ['shape' => ['pet_type' => 'circle', 'r' => 3]],
                'symfony' => ['shape' => ['pet_type' => 'circle', 'r' => 3]],
                'diverges' => [
                    'yii3' => [
                        'expected' => [],
                        'reason' => 'the hydrator cannot pick a branch: a property typed by the union '
                            . 'interface has no rule telling it which member to build, and yiisoft has '
                            . 'no discriminator mapping of its own. The object comes back with the '
                            . 'property unset, which is what an empty array here means. Validation of '
                            . 'a member built by hand still works — see Yii3RequestShapeTest.',
                    ],
                    'laravel-data' => [
                        'expected' => ['shape' => ['r' => 3, 'pet_type' => 'circle']],
                        'reason' => 'the inherited-discriminator key order of the case above, unchanged '
                            . 'by the name mapping: PHP reflection lists a class\'s own properties '
                            . 'before its parent\'s, and the discriminator lives on the abstract base.',
                    ],
                ],
            ],
            'big integer' => [
                'schema' => self::object(['n' => ['type' => 'integer', 'format' => 'int64']], ['n']),
                'json' => '{"n":9007199254740993}',
                'runtime' => ['n' => 9007199254740993],
                'symfony' => ['n' => 9007199254740993],
            ],
            'number with an integral value' => [
                'schema' => self::object(['n' => ['type' => 'number']], ['n']),
                'json' => '{"n":2}',
                'runtime' => ['n' => 2.0],
                'symfony' => ['n' => 2.0],
            ],
            'writeOnly property' => [
                'schema' => self::object(
                    ['login' => ['type' => 'string'], 'password' => ['type' => 'string', 'writeOnly' => true]],
                    ['login', 'password'],
                ),
                'json' => '{"login":"u","password":"secret"}',
                'runtime' => ['login' => 'u'],
                'symfony' => ['login' => 'u', 'password' => 'secret'],
                'reason' => 'runtime drops writeOnly fields from the response unconditionally; '
                    . 'Symfony can only do it through serialization groups, and this case '
                    . 'normalizes with an empty context on purpose. With [\'groups\' => \'read\'] the '
                    . 'generated DTO does drop it — see SymfonySerializationGroupsTest',
            ],
            'readOnly property sent by the client' => [
                'schema' => self::object(
                    ['id' => ['type' => ['integer', 'null'], 'readOnly' => true], 'name' => ['type' => 'string']],
                    ['name'],
                ),
                'json' => '{"id":1,"name":"n"}',
                'runtime' => ['name' => 'n'],
                'symfony' => ['id' => 1, 'name' => 'n'],
                'reason' => 'runtime ignores a readOnly value coming from the client, so the property '
                    . 'stays unset and is omitted; Symfony denormalizes it unless the caller passes '
                    . '[\'groups\' => \'write\'], which this case deliberately does not',
                'diverges' => [
                    'laravel-data' => [
                        'expected' => ['name' => 'n', 'id' => 1],
                        'reason' => 'writeOnly has an exact counterpart in laravel-data (#[Hidden], which '
                            . 'the emitter uses) and readOnly has none: nothing says "hydrate everything '
                            . 'except this key". The value therefore arrives and is echoed back, as in '
                            . 'Symfony mode without a write group.',
                    ],
                ],
            ],
        ];

        $provided = [];
        foreach ($cases as $key => $case) {
            $expectedByMode = [
                GenerationMode::Runtime->value => $case['runtime'],
                GenerationMode::Symfony->value => $case['symfony'],
            ];

            // A reason is only ever attached to a mode that is NOT the reference: `reason` has always
            // meant "why Symfony differs", and `laravelReason` its Laravel counterpart.
            $reasonByMode = [];
            if (($case['reason'] ?? null) !== null) {
                $reasonByMode[GenerationMode::Symfony->value] = $case['reason'];
            }
            if (($case['laravelReason'] ?? null) !== null) {
                $reasonByMode[GenerationMode::Laravel->value] = $case['laravelReason'];
            }

            // The general form, for any mode: its own expectation and why it differs. The two keys above
            // predate it and are kept because they read well for the two modes that use them most.
            foreach ($case['diverges'] ?? [] as $modeValue => $divergence) {
                if (array_key_exists('expected', $divergence)) {
                    $expectedByMode[$modeValue] = $divergence['expected'];
                }
                if (array_key_exists('like', $divergence)) {
                    // "differs from the reference in exactly the way that mode does" — said once instead
                    // of copying its expectation, which would then have to be kept in step by hand.
                    $expectedByMode[$modeValue] = $expectedByMode[$divergence['like']];
                }
                $reasonByMode[$modeValue] = $divergence['reason'];
            }

            $provided[$key] = [
                $key,
                $case['schema'],
                $case['extra'] ?? [],
                $case['json'],
                $expectedByMode,
                $reasonByMode,
                $case['unsupported'] ?? [],
            ];
        }

        return $provided;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'required' => $required, 'properties' => $properties];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @return array<string, mixed>
     */
    private static function spec(array $schema, array $extraSchemas): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => $schema] + $extraSchemas],
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function runtimeNormalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Runtime, $key), 'runtime');
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $dto = (new DtoDeserializer())->deserialize($request, $fqcn);

        /** @var array<string, mixed> $canonical */
        $canonical = $this->canonicalize((new DtoNormalizer())->toArray($dto));

        return $canonical;
    }

    /**
     * An `stdClass` in the output is meaningful — it is what makes an empty map encode as `{}`
     * instead of `[]` — but two stdClass instances are never identical, so assertSame cannot see
     * it. Rewriting them as `['#object' => ...]` keeps the distinction visible in the expectations
     * while staying comparable.
     */
    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return ['#object' => $this->canonicalize((array)$value)];
        }

        if (is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }

        return $value;
    }

    /**
     * The exception a mode dies with on this case, or null if it survived — the observation an
     * `unsupported` declaration is checked against.
     *
     * @param array<string, mixed> $spec
     */
    private function normalizationFailure(GenerationMode $mode, array $spec, string $key, string $json): ?string
    {
        try {
            $this->normalization($mode, $spec, $key, $json);
        } catch (Throwable $exception) {
            return $exception::class . ': ' . $exception->getMessage();
        }

        return null;
    }

    /**
     * The one place a mode name turns into a normalization path. No `default` arm on purpose: a mode
     * added to `GenerationMode` without an entry here fails with `UnhandledMatchError` rather than going
     * unmeasured.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function normalization(GenerationMode $mode, array $spec, string $key, string $json): array
    {
        return match ($mode) {
            GenerationMode::Runtime => $this->runtimeNormalization($spec, $key, $json),
            GenerationMode::Symfony => $this->symfonyNormalization($spec, $key, $json),
            GenerationMode::Laravel => $this->laravelNormalization($spec, $key, $json),
            GenerationMode::LaravelData => $this->laravelDataNormalization($spec, $key, $json),
            GenerationMode::Yii3 => $this->yii3Normalization($spec, $key, $json),
        };
    }

    /**
     * yii3 mode has no `toArray()` of its own — the framework ships no response normalizer, so the
     * array form is the DATA SET the class already exposes: what it received, under the names the
     * schema uses. An absent optional is left out, which is the same answer every other mode gives.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function yii3Normalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Yii3, $key), 'yii3');
        $payload = json_decode($json, true);
        $this->skipYii3WithoutIntl($fqcn, $payload, $key);

        /** @var array<string, mixed> $normalized */
        $normalized = $this->canonicalize(
            (new Yii3Container())->hydrate($fqcn, is_array($payload) ? $payload : [])->getData() ?? [],
        );

        return $normalized;
    }

    /**
     * Skip the yii3 arm when, and only when, THIS document actually needs ext-intl.
     *
     * One thing needs it: the `#[ToDateTime]` resolver a SCALAR temporal property carries — its
     * constructor defaults name `IntlDateFormatter`, so it cannot be built without the extension.
     * Which documents that covers used to be guessed from the schema (`"format":"date"` anywhere in
     * it), and the guess went stale the moment a temporal CONTAINER stopped emitting the attribute:
     * those cases were skipped while hydrating perfectly well. The resolver's own failure is the one
     * witness that cannot rot.
     *
     * @param mixed $payload the decoded body, passed to the probe hydration
     */
    private function skipYii3WithoutIntl(string $fqcn, mixed $payload, string $key): void
    {
        if (extension_loaded('intl')) {
            return;
        }

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
            // Anything else is this case's real answer; the measurement below reports it.
        }
    }

    /**
     * laravel-data mode: built through the package's own request entry point and normalized by its own
     * pipeline. None of the array-building is ours here, which is the trade the mode makes.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function laravelDataNormalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::LaravelData, $key), 'laravel-data');
        LaravelDataContainer::boot();

        /** @var array<string, mixed> $normalized */
        $normalized = LaravelDataContainer::withRequest(
            $json,
            fn(LaravelRequest $request): mixed => $this->canonicalize($fqcn::from($request)->toArray()),
        );

        return $normalized;
    }

    /**
     * Laravel mode: the DTO is built the way a controller builds it — from the validated payload — and
     * then asked for its array form.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function laravelNormalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Laravel, $key), 'laravel');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator, $json);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $dto = call_user_func([$fqcn, 'fromValidated'], $validated);

        /** @var array<string, mixed> $normalized */
        $normalized = $this->canonicalize($dto->toArray());

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function symfonyNormalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Symfony, $key), 'symfony');
        $serializer = $this->serializer();

        /** @var array<string, mixed> $normalized */
        $normalized = $this->canonicalize($serializer->normalize($serializer->deserialize($json, $fqcn, 'json')));

        return $normalized;
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        return new Serializer(
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

        // Autoloading rather than a require loop: a discriminated union generates a child class
        // that extends its parent, and requiring the files in glob() order would hit the child
        // first.
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        return $fqcn;
    }

    private function namespaceFor(GenerationMode $mode, string $key): string
    {
        return 'Norm' . $mode->tag() . $this->namespaceSuffix($key);
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
