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

/**
 * The OTHER half of runtime/Symfony parity: the response direction.
 *
 * `ValidationParityTest` compares validation verdicts — whether a payload is accepted. It
 * cannot see the shape of what comes back out, because the two modes normalize through different
 * code (`DtoNormalizer` vs the Symfony Serializer). This test pins that shape: the same JSON goes
 * in, and the resulting array is asserted for BOTH modes.
 *
 * Expectations are written out per mode on purpose. Where they are equal the modes agree; where
 * they differ the case must say why, and `testEveryDivergenceIsDocumented` enforces that — so a new
 * divergence cannot be introduced without someone writing down the reason.
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
     * @param array<string, mixed> $expectedRuntime
     * @param array<string, mixed> $expectedSymfony
     */
    #[DataProvider('normalizationProvider')]
    public function testBothModesNormalizeTheSameWay(
        string $key,
        array $schema,
        array $extraSchemas,
        string $json,
        array $expectedRuntime,
        array $expectedSymfony,
        ?string $divergenceReason,
        ?string $laravelReason,
    ): void {
        $spec = self::spec($schema, $extraSchemas);

        $this->assertSame(
            $expectedRuntime,
            $this->runtimeNormalization($spec, $key, $json),
            sprintf('runtime normalization changed for "%s"', $key),
        );
        $this->assertSame(
            $expectedSymfony,
            $this->symfonyNormalization($spec, $key, $json),
            sprintf('symfony normalization changed for "%s"', $key),
        );

        if ($divergenceReason === null) {
            $this->assertSame($expectedRuntime, $expectedSymfony, sprintf('modes must agree on "%s"', $key));
        }

        // Laravel mode has no third set of expectations: its `toArray()` was written to mirror runtime
        // semantics (omit an unprovided optional, format a temporal value as the schema declares), so it
        // is compared against the RUNTIME expectation and any difference must carry its own reason.
        $laravel = $this->laravelNormalization($spec, $key, $json);
        if ($laravelReason === null) {
            $this->assertSame(
                $expectedRuntime,
                $laravel,
                sprintf('laravel must normalize like runtime on "%s" (or declare a reason)', $key),
            );

            return;
        }

        $this->assertNotSame(
            $expectedRuntime,
            $laravel,
            sprintf('"%s" declares a laravel divergence but there is none — drop the reason', $key),
        );
    }

    /**
     * The expectations above are only trustworthy while every inequality carries a reason, so the
     * two are checked against each other.
     *
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @param array<string, mixed> $expectedRuntime
     * @param array<string, mixed> $expectedSymfony
     */
    #[DataProvider('normalizationProvider')]
    public function testEveryDivergenceIsDocumented(
        string $key,
        array $schema,
        array $extraSchemas,
        string $json,
        array $expectedRuntime,
        array $expectedSymfony,
        ?string $divergenceReason,
        ?string $laravelReason,
    ): void {
        if ($expectedRuntime === $expectedSymfony) {
            $this->assertNull(
                $divergenceReason,
                sprintf('"%s" declares a divergence but the two modes agree — drop the reason', $key),
            );

            return;
        }

        $this->assertNotNull(
            $divergenceReason,
            sprintf(
                "\"%s\" normalizes differently and says nothing about it\n runtime: %s\n symfony: %s",
                $key,
                json_encode($expectedRuntime),
                json_encode($expectedSymfony),
            ),
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
            'free-form object' => [
                'schema' => self::object(['any' => ['type' => 'object']], ['any']),
                'json' => '{"any":{"k":[1,{"z":null}]}}',
                'runtime' => ['any' => ['#object' => ['k' => [1, ['#object' => ['z' => null]]]]]],
                'symfony' => ['any' => ['k' => [1, ['z' => null]]]],
                'reason' => 'same stdClass-vs-array difference as the map cases, applied at every '
                    . 'level of a free-form value',
            ],
            'additionalProperties map' => [
                'schema' => self::object(['map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]], ['map']),
                'json' => '{"map":{"a":1,"b":2}}',
                'runtime' => ['map' => ['#object' => ['a' => 1, 'b' => 2]]],
                'symfony' => ['map' => ['a' => 1, 'b' => 2]],
                'reason' => 'runtime normalizes a map to stdClass so it always encodes as a JSON '
                    . 'object, Symfony keeps the PHP array — identical JSON while the map has keys, '
                    . 'see the empty-map case for where it stops being identical',
            ],
            'empty map' => [
                'schema' => self::object(['map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]], ['map']),
                'json' => '{"map":{}}',
                'runtime' => ['map' => ['#object' => []]],
                'symfony' => ['map' => []],
                'reason' => 'the stdClass/array difference becomes visible on the wire: runtime '
                    . 'encodes {} (an object, as the schema says), Symfony encodes [] (an array)',
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
            ],
        ];

        $provided = [];
        foreach ($cases as $key => $case) {
            $provided[$key] = [
                $key,
                $case['schema'],
                $case['extra'] ?? [],
                $case['json'],
                $case['runtime'],
                $case['symfony'],
                $case['reason'] ?? null,
                $case['laravelReason'] ?? null,
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
        $fqcn = $this->generate($spec, 'NormRt' . $this->namespaceSuffix($key), 'runtime');
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
     * Laravel mode: the DTO is built the way a controller builds it — from the validated payload — and
     * then asked for its array form.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function laravelNormalization(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, 'NormLv' . $this->namespaceSuffix($key), 'laravel');

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
        $fqcn = $this->generate($spec, 'NormSy' . $this->namespaceSuffix($key), 'symfony');
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

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
