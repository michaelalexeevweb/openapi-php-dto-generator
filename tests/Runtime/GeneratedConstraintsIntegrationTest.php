<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use BackedEnum;
use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Contract\DtoDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use UnitEnum;

/**
 * Generated-constraint regression coverage.
 *
 * This is the "through the generator" integration layer: it
 * generates a DTO from an OpenAPI spec, loads it, and asserts that the validation
 * constraints actually survive into the generated getConstraints() AND are enforced
 * by DtoNormalizer::validate(). Before the allowlist was widened, keys such as
 * const/not/allOf/properties/required/additionalProperties/min|maxProperties/
 * dependentRequired/dependentSchemas/prefixItems were silently stripped by
 * GenerateDtoCommand::extractValidationConstraints, so these features only ever
 * worked when DtoValidator was called directly — never through a real DTO.
 */
final class GeneratedConstraintsIntegrationTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-gap1';
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
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            unlink($this->outputDirectory . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($this->outputDirectory);
    }

    /**
     * A schema NAMED like a class the emitted runtime file imports — `Stringable`, `RuntimeException`,
     * `Closure`, `UnsetValue`, `JsonException`. The document is entitled to those names, and every
     * generated runtime class carries imports with exactly them, which PHP resolves in two
     * incompatible ways:
     *
     *     the file DECLARING it     Fatal error: Cannot redeclare X\Stringable
     *                               (previously declared as local import) — the file never loads
     *     any SIBLING file          the `use` wins over the same-namespace class, so `Holder::$it`
     *                               was typed the GLOBAL Stringable and the payload a TypeError
     *
     * Driven end to end rather than asserted on the source, because both failures are invisible to a
     * source assertion: the first is a parse error, the second a type that reads perfectly fine.
     *
     * {@see \OpenapiPhpDtoGenerator\Command\Rendering\NamesLibraryClasses} — the same bargain
     * laravel-data has always had, which runtime joined.
     */
    #[DataProvider('runtimeCollidingSchemaNameProvider')]
    public function testASchemaNamedLikeAnImportedClassStillLoadsAndHydrates(string $schemaName): void
    {
        $namespace = 'RuntimeCollide' . $schemaName;
        (new GenerateDtoCommand())->generateFromArray(
            [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['it'],
                        // An optional property is what pulls in the UnsetValue sentinel.
                        'properties' => [
                            'it' => ['$ref' => '#/components/schemas/' . $schemaName],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                    $schemaName => [
                        'type' => 'object',
                        'required' => ['n'],
                        'properties' => ['n' => ['type' => 'integer']],
                    ],
                ]],
            ],
            $this->outputDirectory,
            $namespace,
        );

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $holder */
        $holder = $namespace . '\Holder';
        /** @var class-string<GeneratedDtoInterface> $member */
        $member = $namespace . '\\' . $schemaName;

        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"it":{"n":7}}'), $holder);

        self::assertInstanceOf(
            $member,
            $dto->getIt(),
            'the property must be the DOCUMENT\'s class, not the library one the import would have won',
        );
        self::assertSame(7, $dto->getIt()->getN(), 'and it must carry the payload, not a default');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function runtimeCollidingSchemaNameProvider(): array
    {
        $provided = [];
        // NOT `UnsetValue`: the deserializer recognises the sentinel by short name on purpose, so
        // that name stays reserved and is warned about instead. {@see GenerateDtoCommand}
        foreach (['Stringable', 'RuntimeException', 'Closure', 'JsonException'] as $name) {
            $provided[$name . ' — a name the emitted runtime file imports'] = [$name];
        }

        return $provided;
    }

    /**
     * Generates ProbeModel from the probe fixture into a unique namespace (so each
     * test method gets its own class and PHP never sees a redeclaration) and returns
     * the fully-qualified class name after requiring every generated file.
     *
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateProbeModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/gap1-probe.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\ProbeModel';
        return $fqcn;
    }

    /**
     * Generates OptionalFieldModel and returns its FQCN after requiring it.
     *
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateOptionalFieldModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/optional-field-validation.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\OptionalFieldModel';
        return $fqcn;
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateIntFormatModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-format.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\IntFormatModel';
        return $fqcn;
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateEventModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/array-datetime.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\EventModel';
        return $fqcn;
    }

    private function jsonPostRequest(string $body): Request
    {
        return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    /**
     * `additionalProperties: false` closes the object, and runtime mode is the one mode that can
     * still see the offending key.
     *
     * Every other mode hydrates the payload into a typed property first, and a key the schema never
     * declared has nowhere to land — it is gone before generated code runs. Runtime holds the raw
     * body, so it can compare what arrived against what was declared. Pinned across modes by
     * `ValidationParityTest::testAClosedObjectRefusesAnUndeclaredKeyWhereverTheRawBodyIsReachable`.
     */
    public function testAClosedObjectRefusesAnUndeclaredKey(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Closed' => [
                        'type' => 'object',
                        'required' => ['known'],
                        'properties' => ['known' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'ClosedObjNs', 'Closed');

        $accepted = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"known":"a"}'),
            $fqcn,
        );
        self::assertSame('a', $accepted->getKnown());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown property "extra" is not allowed by the schema.');
        (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"known":"a","extra":"b"}'),
            $fqcn,
        );
    }

    /**
     * The mirror, so the check above cannot be read as "an extra key is always refused": a schema
     * that does NOT close itself keeps the historical behaviour of ignoring what it did not declare.
     */
    public function testAnOpenObjectStillIgnoresAnUndeclaredKey(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Open' => [
                        'type' => 'object',
                        'required' => ['known'],
                        'properties' => ['known' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'OpenObjNs', 'Open');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"known":"a","extra":"b"}'),
            $fqcn,
        );
        self::assertSame('a', $dto->getKnown());
    }

    /**
     * `unevaluatedProperties: false` closes an object the same way, and the runtime treats the two
     * keywords alike — a document may write either.
     */
    public function testUnevaluatedPropertiesFalseAlsoClosesTheObject(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Sealed' => [
                        'type' => 'object',
                        'required' => ['known'],
                        'properties' => ['known' => ['type' => 'string']],
                        'unevaluatedProperties' => false,
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'SealedObjNs', 'Sealed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown property "extra" is not allowed by the schema.');
        (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"known":"a","extra":"b"}'),
            $fqcn,
        );
    }

    /**
     * The edge that a naive implementation gets wrong: a property whose WIRE name differs from its
     * PHP name. Comparing against PHP names would refuse `first_name` — a key the schema declares.
     */
    public function testAClosedObjectAcceptsAnAliasedWireName(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Aliased' => [
                        'type' => 'object',
                        'required' => ['first_name'],
                        'properties' => ['first_name' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'AliasedClosedNs', 'Aliased');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"first_name":"Ada"}'),
            $fqcn,
        );
        self::assertSame('Ada', $dto->getFirstName());
    }

    /**
     * Source precedence for a plain-body DTO, pinned because `deserializeInternal()` inlines the
     * resolver's decision tree for exactly this shape.
     *
     * The inline copy must make the SAME five checks in the SAME order — router attribute, body,
     * query, uploaded file, form — or a request resolves differently depending on which path ran.
     * An earlier attempt at this optimisation replaced the tree with preconditions instead of
     * copying it, missed uploaded files, and changed behaviour silently. These assertions are what
     * would have caught it.
     */
    public function testPlainBodyDtoKeepsTheDeclaredSourcePrecedence(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Plain' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'PlainSourceNs', 'Plain');
        $deserializer = new DtoDeserializer();

        // Body alone.
        self::assertSame(
            'from-body',
            $deserializer->deserialize($this->jsonPostRequest('{"id":"from-body"}'), $fqcn)->getId(),
        );

        // A router-verified path attribute OUTRANKS the body — a request body must never be able to
        // override a value the router established.
        $withAttribute = $this->jsonPostRequest('{"id":"from-body"}');
        $withAttribute->attributes->set('id', 'from-path');
        self::assertSame('from-path', $deserializer->deserialize($withAttribute, $fqcn)->getId());

        // Query is consulted only when the body has no such key.
        $queryOnly = Request::create('/?id=from-query', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame('from-query', $deserializer->deserialize($queryOnly, $fqcn)->getId());

        // …and the body still wins over the query when both carry it.
        $both = Request::create(
            '/?id=from-query',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"id":"from-body"}',
        );
        self::assertSame('from-body', $deserializer->deserialize($both, $fqcn)->getId());
    }

    /**
     * The generated `hydrateFast()` and the general loop must answer identically — same object, same
     * error text, same error ORDER.
     *
     * Two routes through the same semantics is the risk this optimisation carries: the fast one runs
     * when the body is provably the only source, the general one otherwise, and nothing but this test
     * says they agree. Both were caught misbehaving during development — an inherited `hydrateFast()`
     * built the PARENT class, and a plan read off the property list passed a slug where a status was
     * expected — so the guarantee is not theoretical.
     *
     * One test rather than a data provider: the DTO is generated once and `require`d, and a provider
     * would redeclare it per case.
     */
    public function testTheFastHydratorAndTheGeneralLoopAgree(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Both' => [
                        'type' => 'object',
                        // Required and optional INTERLEAVED: the constructor orders required first,
                        // so a plan built in schema order would pass arguments in the wrong slots.
                        'required' => ['b', 'd'],
                        'properties' => [
                            'a' => ['type' => 'string', 'minLength' => 2],
                            'b' => ['type' => 'integer', 'minimum' => 1],
                            'c' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                            'd' => ['type' => 'string', 'enum' => ['x', 'y']],
                            'e' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'BothRoutesNs', 'Both');
        $deserializer = new DtoDeserializer();

        // `toArray()` alone is not enough: it omits an absent optional either way, so a route that
        // forgot to raise the presence flags would still match, so they are compared too.
        $outcome = static function (Request $request) use ($deserializer, $fqcn): string {
            try {
                $dto = $deserializer->deserialize($request, $fqcn);
                $presence = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
                    $method = 'is' . $name . 'InRequest';
                    if (method_exists($dto, $method)) {
                        $presence[$name] = $dto->{$method}();
                    }
                }

                return 'ok:' . json_encode($dto->toArray()) . '|presence:' . json_encode($presence);
            } catch (Throwable $e) {
                return 'err:' . $e->getMessage();
            }
        };

        $valid = ['a' => 'ab', 'b' => 2, 'c' => ['x'], 'd' => 'x', 'e' => ['k' => 1]];
        $payloads = [
            'everything present' => $valid,
            'only the required half' => ['b' => 2, 'd' => 'x'],
            'optional absent' => ['b' => 2, 'd' => 'y', 'a' => 'ab'],
            'optional explicit null' => ['b' => 2, 'd' => 'x', 'a' => null],
            'required missing' => ['a' => 'ab'],
            'wrong scalar type' => array_replace($valid, ['b' => 'nope']),
            'enum out of range' => array_replace($valid, ['d' => 'zz']),
            'bound broken' => array_replace($valid, ['b' => 0]),
            'list too short' => array_replace($valid, ['c' => []]),
            'map item wrong type' => array_replace($valid, ['e' => ['k' => 'x']]),
            'two problems at once' => array_replace($valid, ['b' => 'nope', 'd' => 'zz']),
            'unknown extra key' => array_replace($valid, ['zzz' => 1]),
            'empty payload' => [],
        ];

        foreach ($payloads as $label => $payload) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $fast = $outcome($this->jsonPostRequest($json));
            // A stray query key disqualifies the fast route, so this one takes the general loop.
            $general = $outcome(Request::create(
                '/?__forces_the_general_route=1',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $json,
            ));

            self::assertSame($general, $fast, sprintf('the two hydration routes disagree on "%s"', $label));
        }
    }

    /**
     * A form-encoded or multipart request must keep the general loop.
     *
     * `getBodyData()` decodes `application/json` and nothing else, so for these requests the values
     * live in `$request->request` (and `$request->files`), which the generated `hydrateFast()` never
     * sees — it is handed the JSON body alone. Removing that guard is invisible to every other test:
     * mutation-tested, the whole suite stayed green while a form POST started reporting its required
     * field as missing.
     */
    public function testAFormEncodedRequestDoesNotTakeTheFastHydrator(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Form' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'FormRouteNs', 'Form');

        // The value is in the form bag, not in a JSON body.
        $dto = (new DtoDeserializer())->deserialize(
            Request::create('/', 'POST', ['name' => 'from-form']),
            $fqcn,
        );

        self::assertSame('from-form', $dto->getName());
    }

    /**
     * A body nobody could read says so, instead of blaming the client for missing fields.
     *
     * Only `application/json` is decoded as a JSON body; form and multipart values arrive through
     * their own sources. A client that sends JSON under `text/plain` — or forgets the header, the
     * common version — used to get "Required parameter … not found" for every field, which points at
     * the wrong thing entirely.
     */
    public function testAnUnreadableBodyIsNamedInTheError(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Hinted' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'HintedNs', 'Hinted');
        $deserializer = new DtoDeserializer();

        try {
            $deserializer->deserialize(
                Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"name":"v"}'),
                $fqcn,
            );
            self::fail('a body that was never decoded must not deserialize');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Required parameter "name" not found', $e->getMessage());
            self::assertStringContainsString('The request body was not read', $e->getMessage());
            self::assertStringContainsString('text/plain', $e->getMessage());
        }

        // The hint is only ever ADDED to a failure. A request that carries nothing at all keeps the
        // plain message — there is no unread body to blame.
        try {
            $deserializer->deserialize(Request::create('/', 'POST'), $fqcn);
            self::fail('a missing required field must still be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Required parameter "name" not found', $e->getMessage());
            self::assertStringNotContainsString('The request body was not read', $e->getMessage());
        }

        // A JSON body that WAS read gets no hint, however it fails. Both halves of that matter and
        // both survived mutation until these cases existed: dropping the `application/json` check,
        // and dropping the "body was decoded" check, each put the hint on a request whose body had
        // been read perfectly well.
        foreach (['{}', '{"name":"v","extra":1}'] as $readableBody) {
            try {
                $deserializer->deserialize(
                    Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $readableBody),
                    $fqcn,
                );
                if ($readableBody === '{}') {
                    self::fail('a missing required field must be refused');
                }
            } catch (RuntimeException $e) {
                self::assertStringNotContainsString(
                    'The request body was not read',
                    $e->getMessage(),
                    'the body WAS read: ' . $readableBody,
                );
            }
        }

        // …and a JSON body still works, hint or no hint.
        self::assertSame(
            'v',
            $deserializer->deserialize($this->jsonPostRequest('{"name":"v"}'), $fqcn)->getName(),
        );
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateFromInlineSpec(array $spec, string $namespace, string $rootClass): string
    {
        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, $namespace);

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\' . $rootClass;
        return $fqcn;
    }

    public function testDependentRequiredOnNestedDtoValueIsEnforced(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['bag'],
                        'properties' => [
                            'bag' => [
                                'type' => 'object',
                                'properties' => [
                                    'kind' => ['type' => 'string'],
                                    'code' => ['type' => 'string'],
                                ],
                                'dependentRequired' => ['kind' => ['code']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromInlineSpec($spec, 'GapDependentRequired', 'Holder');
        $deserializer = new DtoDeserializer();
        $normalizer = new DtoNormalizer();

        // The value of `bag` is a generated DTO, not an array — the cross-field rule still applies.
        $satisfied = $deserializer->deserialize($this->jsonPostRequest('{"bag":{"kind":"a","code":"c"}}'), $fqcn);
        $this->assertSame([], $normalizer->validate($satisfied));

        // The deserializer validates on the way in, so the violation surfaces there.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/code is required when kind is present/');
        $deserializer->deserialize($this->jsonPostRequest('{"bag":{"kind":"a"}}'), $fqcn);
    }

    /**
     * `unevaluatedProperties` and `additionalProperties` are defined against the keys `properties`
     * declares. When the value is a generated DTO the validator drops `properties` (the DTO checks
     * its own fields, and re-checking would double every message) — and it used to drop the NAMES
     * with the rules, so every declared key counted as unevaluated: the valid payload
     * `{"bag":{"known":"a"}}` was rejected with `has unevaluated property "known"`.
     *
     * What the keyword can still catch through a DTO value is bounded by the DTO itself: an
     * undeclared key never reaches it (see
     * `testUnknownPayloadKeysAreDroppedBeforeValidationInBothModes` in the parity suite). A rule
     * that reads the declared keys — here `minProperties` alongside it — must keep working.
     */
    public function testDeclaredPropertiesAreNotReportedAsUnevaluatedOnADtoValue(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['bag'],
                        'properties' => [
                            'bag' => [
                                'type' => 'object',
                                'properties' => [
                                    'known' => ['type' => 'string'],
                                    'other' => ['type' => 'string'],
                                ],
                                'unevaluatedProperties' => false,
                                'minProperties' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromInlineSpec($spec, 'GapUnevaluatedOnDto', 'Holder');
        $deserializer = new DtoDeserializer();

        $dto = $deserializer->deserialize($this->jsonPostRequest('{"bag":{"known":"a","other":"b"}}'), $fqcn);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));

        // The keywords that CAN see a DTO value still fire: one declared key is one property.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must have at least 2 propert/');
        $deserializer->deserialize($this->jsonPostRequest('{"bag":{"known":"a"}}'), $fqcn);
    }

    public function testDependentSchemasOnNestedDtoValueIsEnforced(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['bag'],
                        'properties' => [
                            'bag' => [
                                'type' => 'object',
                                'properties' => [
                                    'kind' => ['type' => 'string'],
                                    'code' => ['type' => 'string'],
                                ],
                                'dependentSchemas' => ['kind' => ['properties' => ['code' => ['minLength' => 4]]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromInlineSpec($spec, 'GapDependentSchemas', 'Holder');
        $deserializer = new DtoDeserializer();
        $normalizer = new DtoNormalizer();

        $satisfied = $deserializer->deserialize($this->jsonPostRequest('{"bag":{"kind":"a","code":"abcd"}}'), $fqcn);
        $this->assertSame([], $normalizer->validate($satisfied));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 4 characters/');
        $deserializer->deserialize($this->jsonPostRequest('{"bag":{"kind":"a","code":"ab"}}'), $fqcn);
    }

    public function testScalarAllOfBecomesTypedPropertyWithMergedConstraints(): void
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

        $fqcn = $this->generateFromInlineSpec($spec, 'GapScalarAllOf', 'Merged');

        // Previously the property was synthesized into a nested object class instead of a string.
        $this->assertSame('string', (string)(new ReflectionMethod($fqcn, 'getCode'))->getReturnType());

        $deserializer = new DtoDeserializer();
        $normalizer = new DtoNormalizer();

        $valid = $deserializer->deserialize($this->jsonPostRequest('{"code":"abc"}'), $fqcn);
        $this->assertSame([], $normalizer->validate($valid));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 3 characters/');
        $deserializer->deserialize($this->jsonPostRequest('{"code":"ab"}'), $fqcn);
    }

    public function testAdditionalPropertiesMapSerializesAsObjectAndRoundTrips(): void
    {
        // A `type: object` + `additionalProperties` map ({id: name}) must serialize as a JSON
        // object even when its keys are dense integers (0, 1, 2, …) — a raw array would become a
        // JSON list and lose the keys. Deserialization casts each value to the items type.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Map', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['fieldTypes'],
                        'properties' => [
                            'fieldTypes' => [
                                'type' => 'object',
                                'additionalProperties' => ['$ref' => '#/components/schemas/FieldTypesEnumView'],
                            ],
                        ],
                    ],
                    'FieldTypesEnumView' => ['type' => 'string', 'enum' => ['text', 'number', 'date']],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenMap');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenMap\Holder';
        $enum = '\GenMap\FieldTypesEnumView';
        $normalizer = new DtoNormalizer();

        // Dense integer keys must still produce a JSON object on every output path.
        $dense = new $cls([0 => $enum::TEXT, 1 => $enum::NUMBER, 2 => $enum::DATE]);
        $expected = '{"fieldTypes":{"0":"text","1":"number","2":"date"}}';
        $this->assertSame($expected, $dense->toJson());
        $this->assertSame($expected, (string)json_encode($normalizer->toArray($dense)));
        $this->assertSame($expected, (string)json_encode($normalizer->validateAndNormalizeToArray($dense)));

        // Deserialize a JSON object body → keys preserved, values cast to the enum.
        $deserialized = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"fieldTypes":{"0":"text","5":"date"}}'),
            $cls,
        );
        $map = $deserialized->getFieldTypes();
        $this->assertSame([0, 5], array_keys($map));
        $this->assertSame($enum::TEXT, $map[0]);
        $this->assertSame($enum::DATE, $map[5]);

        // A map exposes a keyed adder ($key, $item) that preserves keys in the object output.
        $built = new $cls([0 => $enum::TEXT]);
        $built->addItemToFieldTypes('5', $enum::DATE);
        $built->addItemToFieldTypes('label', $enum::NUMBER);
        $this->assertSame(
            '{"fieldTypes":{"0":"text","5":"date","label":"number"}}',
            $built->toJson(),
        );
    }

    public function testTemporalFieldExposesObjectGetterAlongsideStringGetter(): void
    {
        // A scalar temporal field keeps its string getter (formatted per the OpenAPI format) and
        // gains a getXAsDateTime() that returns the underlying DateTimeImmutable. Covers a required
        // (non-null) and a nullable field.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Temporal', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Event' => [
                        'type' => 'object',
                        'required' => ['startDate'],
                        'properties' => [
                            'startDate' => ['type' => 'string', 'format' => 'date'],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenTemporal');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenTemporal\Event';

        /** @var object{getStartDate: callable, getStartDateAsDateTime: callable, getCreatedAt: callable, getCreatedAtAsDateTime: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"startDate":"2026-01-15","createdAt":"2026-02-20T08:30:00+00:00"}'),
            $cls,
        );

        // String getter: formatted per the OpenAPI format.
        $this->assertSame('2026-01-15', $dto->getStartDate());
        // Object getter: the underlying DateTimeImmutable.
        $startDateObject = $dto->getStartDateAsDateTime();
        $this->assertInstanceOf(DateTimeImmutable::class, $startDateObject);
        $this->assertSame('2026-01-15', $startDateObject->format('Y-m-d'));

        // Nullable field present → both getters return the value.
        $this->assertSame('2026-02-20T08:30:00+00:00', $dto->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->getCreatedAtAsDateTime());

        // Reflect the declared return types.
        $this->assertSame(
            'DateTimeImmutable',
            (string)(new ReflectionMethod($cls, 'getStartDateAsDateTime'))->getReturnType(),
        );
        $this->assertSame(
            '?DateTimeImmutable',
            (string)(new ReflectionMethod($cls, 'getCreatedAtAsDateTime'))->getReturnType(),
        );
    }

    public function testArrayOfDateTimeItemsAreParsedThroughGeneratedDto(): void
    {
        // Regression: array<DateTimeImmutable> items used to fail deserialization —
        // (1) resolveFileImports dropped the first char of imported short names, and
        // (2) castArrayItemValue routed datetime items through the nested-DTO branch.
        $cls = $this->generateEventModel('GapArrDt');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"dates":["2026-01-15T12:00:00+00:00","2026-02-20T08:30:00+00:00"]}'),
            $cls,
        );

        $dates = $dto->getDatesAsDateTime();
        $this->assertCount(2, $dates);
        $this->assertInstanceOf(DateTimeImmutable::class, $dates[0]);
        $this->assertSame('2026-01-15T12:00:00+00:00', $dates[0]->format('c'));

        // The string getter formats every item per the ITEMS format, exactly like the scalar one.
        $this->assertSame(
            ['2026-01-15T12:00:00+00:00', '2026-02-20T08:30:00+00:00'],
            $dto->getDates(),
        );
    }

    /**
     * `items: {format: date}` types the item as DateTimeImmutable, and for a long time that was the
     * end of it: the getter handed the objects back and the normalizer printed them RFC 3339, so a
     * schema that said `date` produced `2026-01-15T00:00:00+00:00`. The item now gets the same
     * treatment the scalar has always had — the string getter formats, the `AsDateTime` twin keeps
     * the objects.
     */
    public function testArrayAndMapOfDateItemsSerializeAsDateStrings(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Report' => [
                        'type' => 'object',
                        'required' => ['dates', 'byDay'],
                        'properties' => [
                            'dates' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'format' => 'date'],
                            ],
                            'byDay' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string', 'format' => 'date'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'DateItemsNs', 'Report');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"dates":["2026-01-15","2026-02-20"],"byDay":{"mon":"2026-01-19"}}'),
            $fqcn,
        );

        $this->assertSame(['2026-01-15', '2026-02-20'], $dto->getDates());
        $this->assertSame(['mon' => '2026-01-19'], $dto->getByDay());

        // The objects stay reachable through the twin getter.
        $objects = $dto->getDatesAsDateTime();
        $this->assertInstanceOf(DateTimeImmutable::class, $objects[0]);
        $this->assertSame('2026-01-15', $objects[0]->format('Y-m-d'));

        // Serialization goes through the string getter, so the wire matches the schema.
        $this->assertSame(['2026-01-15', '2026-02-20'], $dto->toArray()['dates']);
        $normalized = (new DtoNormalizer())->toArray($dto);
        $this->assertSame(['2026-01-15', '2026-02-20'], $normalized['dates']);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));

        // A map still encodes as a JSON object, not a list.
        $this->assertSame(
            '{"dates":["2026-01-15","2026-02-20"],"byDay":{"mon":"2026-01-19"}}',
            $dto->toJson(),
        );
    }

    /**
     * The two ways the array can be empty of a value — the array itself absent/null, and a null
     * item inside it — both survive the mapping getter.
     */
    public function testNullableTemporalArrayAndNullableItemsSurviveFormatting(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Optional' => [
                        'type' => 'object',
                        'properties' => [
                            'dates' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'OptionalDateItemsNs', 'Optional');

        $withItems = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"dates":["2026-01-15",null]}'),
            $fqcn,
        );
        $this->assertSame(['2026-01-15', null], $withItems->getDates());
        $this->assertSame(['2026-01-15', null], $withItems->toArray()['dates']);

        // Field absent: the getter must not map over a null.
        $absent = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{}'), $fqcn);
        $this->assertNull($absent->getDates());
        $this->assertArrayNotHasKey('dates', $absent->toArray());
    }

    /**
     * `date-time` items keep the RFC 3339 spelling the scalar getter uses — including the
     * sub-second form when the value carries one.
     */
    public function testArrayOfDateTimeItemsSerializeAsRfc3339Strings(): void
    {
        $cls = $this->generateEventModel('GapArrDtFmt');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"dates":["2026-01-15T12:00:00+00:00","2026-02-20T08:30:00.123456+00:00"]}'),
            $cls,
        );

        $this->assertSame(
            ['2026-01-15T12:00:00+00:00', '2026-02-20T08:30:00.123456+00:00'],
            $dto->getDates(),
        );
        $this->assertSame(
            ['2026-01-15T12:00:00+00:00', '2026-02-20T08:30:00.123456+00:00'],
            (new DtoNormalizer())->toArray($dto)['dates'],
        );
    }

    public function testArrayOfDateTimeRejectsNonStringItem(): void
    {
        $cls = $this->generateEventModel('GapArrDtBad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dates.0" expects date string');
        (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"dates":[123]}'), $cls);
    }

    public function testResolveFileImportsKeepsFirstCharOfImportedNames(): void
    {
        // Direct lock for the resolveFileImports off-by-one: imported names without a
        // namespace separator (e.g. the global `use DateTimeImmutable;`) must keep their
        // first character. The bug produced 'ateTimeImmutable' => 'DateTimeImmutable'.
        $this->generateEventModel('GapImports');

        $deserializer = new DtoDeserializer();
        $method = new ReflectionMethod($deserializer, 'resolveFileImports');
        /** @var array<string, string> $imports */
        $imports = $method->invoke($deserializer, new ReflectionClass('\GapImports\EventModel'));

        $this->assertArrayHasKey('DateTimeImmutable', $imports);
        $this->assertSame('DateTimeImmutable', $imports['DateTimeImmutable']);
        $this->assertArrayNotHasKey('ateTimeImmutable', $imports);
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateBoxModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/array-enum-dto.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\Box';
        return $fqcn;
    }

    public function testArrayOfEnumsAndNestedDtosDeserialize(): void
    {
        $cls = $this->generateBoxModel('GapBox');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"colors":["red","blue"],"tags":[{"name":"a"},{"name":"b"}]}'),
            $cls,
        );

        $colors = $dto->getColors();
        $this->assertCount(2, $colors);
        $this->assertSame('red', $colors[0]->value);

        $tags = $dto->getTags();
        $this->assertCount(2, $tags);
        $this->assertSame('a', $tags[0]->getName());
    }

    public function testArrayOfEnumsRejectsInvalidMember(): void
    {
        $cls = $this->generateBoxModel('GapBoxBad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colors.0');
        (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"colors":["magenta"],"tags":[]}'),
            $cls,
        );
    }

    public function testIntBackedEnumAcceptsQueryStringValue(): void
    {
        // Regression: int-backed enum case (value 1) never strict-equalled the incoming "1"
        // from a query parameter (Symfony delivers query/path/form as strings).
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-enum-query.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIntEnum');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapIntEnum\Filter';
        // status arrives from the query bag as the string "2"; body is empty.
        $request = Request::create('/?status=2', 'GET');
        $dto = (new DtoDeserializer())->deserialize($request, $cls);
        $this->assertSame(2, $dto->getStatus()->value);
    }

    public function testIntOverflowStringFromQueryIsRejected(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-enum-query.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIntOverflow');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapIntOverflow\Filter';
        // count = 23 nines: (int) cast would saturate to PHP_INT_MAX — must be rejected instead.
        $this->expectException(RuntimeException::class);
        (new DtoDeserializer())->deserialize(Request::create('/?status=1&count=99999999999999999999999', 'GET'), $cls);
    }

    public function testIfWithRefConditionDoesNotForceThen(): void
    {
        // Regression: if:{$ref} extracted to an empty (vacuously-true) schema, so `then`
        // (discountCode required) was applied to EVERY value. The unvalidatable if/then is dropped.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/if-ref.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapIfRef\Account';
        $profile = $cls::getConstraints()['profile'] ?? [];
        // The $ref `if` extracted to empty and must be dropped — otherwise it is vacuously true
        // and `then` applies to every value. (`then` may remain but is inert without `if`.)
        $this->assertArrayNotHasKey('if', $profile);
        // items:{$ref} likewise extracts to empty and is dropped (would otherwise silently skip).
        $this->assertArrayNotHasKey('items', $profile);
    }

    public function testGeneratedNormalizationMapCarriesArrayItemType(): void
    {
        // The map must carry the array item type so DtoNormalizer needn't reflect getter docblocks.
        $cls = $this->generateBoxModel('GapMapItemType');
        $map = $cls::getNormalizationMap();

        $this->assertSame('array<Color>', $map['colors']['metadata']['arrayItemType']);
        $this->assertSame('array<Tag>', $map['tags']['metadata']['arrayItemType']);
    }

    public function testNestedDtoWriteOnlyFieldIsNotSerialized(): void
    {
        // write-only fields of a NESTED DTO must not leak into the parent's serialized output.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nested-writeonly.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapNestedWo');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapNestedWo\Wrap';
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"child":{"name":"Bob","secret":"sekret"}}'),
            $cls,
        );

        $array = (new DtoNormalizer())->toArray($dto);
        $this->assertSame('Bob', $array['child']['name']);
        $this->assertArrayNotHasKey('secret', $array['child']);
    }

    public function testCyclicDtoGraphSerializesWithoutInfiniteRecursion(): void
    {
        // Cycles are now explicit serialization errors (instead of silent null truncation).
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapCycle');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapCycle\Node';
        $a = new $cls('A');
        $b = new $cls('B');
        $a->addItemToChildren($b);
        $b->addItemToChildren($a); // A -> B -> A cycle

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected');

        (new DtoNormalizer())->toArray($a);
    }

    public function testCyclicDtoGraphIsReportedByValidateAndRejectedByValidateAndNormalize(): void
    {
        // Validation must reject circular graphs, and validateAndNormalize* must fail too.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapCycleValidate');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapCycleValidate\Node';
        $a = new $cls('A');
        $b = new $cls('B');
        $a->addItemToChildren($b);
        $b->addItemToChildren($a);

        $normalizer = new DtoNormalizer();
        $errors = $normalizer->validate($a);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('circular reference', implode(' | ', $errors));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('circular reference');
        $normalizer->validateAndNormalizeToArray($a);
    }

    public function testSelfReferentialRootSerializesWithoutInfiniteRecursion(): void
    {
        // Root self-reference is now an explicit serialization error.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSelfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\GapSelfRef\Node';
        $node = new $cls('root');
        $node->addItemToChildren($node);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected');

        (new DtoNormalizer())->toArray($node);
    }

    public function testOneOfWithRefVariantDoesNotEmitUnvalidatableConstraint(): void
    {
        // Regression: a oneOf with a $ref variant extracted an empty branch that the
        // validator treated as always-matching → false "matches more than one oneOf
        // branch". The unvalidatable union must be dropped from getConstraints().
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/oneof-ref.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapOneOfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GapOneOfRef\Holder';
        $constraints = $cls::getConstraints();
        // The whole unvalidatable oneOf is dropped → no 'oneOf' (the 'value' entry may be absent entirely).
        $this->assertArrayNotHasKey('oneOf', $constraints['value'] ?? []);

        // A value matching the inline string branch must validate cleanly (no false positive).
        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"value":"hello"}'), $cls);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));
    }

    public function testInt32FormatRangeIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateIntFormatModel('GapInt32');
        $deserializer = new DtoDeserializer();

        // In-range value deserializes fine.
        $dto = $deserializer->deserialize($this->jsonPostRequest('{"small":100}'), $cls);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));

        // Over-range int32 is rejected during deserialization constraint checks.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('within int32 range');
        $deserializer->deserialize($this->jsonPostRequest('{"small":5000000000}'), $cls);
    }

    public function testOmittedOptionalNonNullableFieldDoesNotProduceFalseValidationError(): void
    {
        $cls = $this->generateOptionalFieldModel('GapOptOmitted');

        // Request provides only the required field; optional non-nullable string/int omitted.
        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"id":5}'), $cls);

        $errors = (new DtoNormalizer())->validate($dto);

        // Before the fix: ['field "note" must be of type string', 'field "count" must be of type integer'].
        $this->assertSame([], $errors);
    }

    public function testProvidedOptionalFieldIsStillValidated(): void
    {
        $cls = $this->generateOptionalFieldModel('GapOptProvided');
        $normalizer = new DtoNormalizer();

        // A valid optional value passes (minLength: 3 satisfied).
        $this->assertSame([], $normalizer->validate(new $cls(5, note: 'hello')));

        // Skipping unprovided fields must NOT disable validation of provided ones:
        // a provided value violating a schema constraint must still be reported.
        $invalid = $normalizer->validate(new $cls(5, note: 'hi'));
        $this->assertContains('field "note" length must be at least 3 characters', $invalid);
    }

    public function testEnumWithBoolOrNullMembersFallsBackToInlineConstraints(): void
    {
        $openApi = [
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

        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapEnumInline');
        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsBoolOnly.php');
        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsMixed.php');
        $this->assertFileDoesNotExist($this->outputDirectory . '/FlagsNullableText.php');

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GapEnumInline\Flags';
        $constraints = $cls::getConstraints();
        $this->assertSame([true, false], $constraints['boolOnly']['enum'] ?? null);
        $this->assertSame([1, 'a', true], $constraints['mixed']['enum'] ?? null);
        $this->assertSame(['a', 'b', null], $constraints['nullableText']['enum'] ?? null);

        $normalizer = new DtoNormalizer();
        $valid = new $cls(boolOnly: true, mixed: 'a', nullableText: null);
        $this->assertSame([], $normalizer->validate($valid));

        $invalid = new $cls(boolOnly: false, mixed: 'zzz', nullableText: 'c');
        $errors = $normalizer->validate($invalid);
        $this->assertContains('field "mixed" must be one of: 1, "a", true', $errors);
        $this->assertContains('field "nullableText" must be one of: "a", "b", null', $errors);
    }

    public function testAllNewlyAllowedConstraintKeysSurviveIntoGetConstraints(): void
    {
        $model = $this->generateProbeModel('GapPresence');
        $constraints = $model::getConstraints();

        // const — scalar equality constraint.
        $this->assertArrayHasKey('const', $constraints['constField']);
        $this->assertSame('locked', $constraints['constField']['const']);

        // not — recursively extracted subschema (a $ref-only `not` would be dropped).
        $this->assertArrayHasKey('not', $constraints['notField']);
        $this->assertSame(['const' => 'forbidden'], $constraints['notField']['not']);

        // object-level keys on a non-materialized map type.
        $this->assertArrayHasKey('minProperties', $constraints['mapField']);
        $this->assertArrayHasKey('maxProperties', $constraints['mapField']);
        $this->assertArrayHasKey('additionalProperties', $constraints['mapField']);

        $this->assertArrayHasKey('properties', $constraints['strictMap']);
        $this->assertArrayHasKey('additionalProperties', $constraints['strictMap']);
        $this->assertFalse($constraints['strictMap']['additionalProperties']);

        $this->assertArrayHasKey('dependentRequired', $constraints['depReqMap']);
        $this->assertArrayHasKey('dependentSchemas', $constraints['depSchemaMap']);

        // prefixItems — positional tuple validation.
        $this->assertArrayHasKey('prefixItems', $constraints['tupleField']);

        // allOf — inline branches kept, fully-unresolvable ($ref-only) ones dropped.
        $this->assertArrayHasKey('allOf', $constraints['intersection']);
        $this->assertSame(
            [['minLength' => 2], ['maxLength' => 5]],
            $constraints['intersection']['allOf'],
        );
    }

    public function testConstConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapConst');
        $normalizer = new DtoNormalizer();

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1]));
        $this->assertNotContains('field "constField" must equal "locked"', $valid);

        $invalid = $normalizer->validate(new $cls('WRONG', ['a' => 1]));
        $this->assertContains('field "constField" must equal "locked"', $invalid);
    }

    public function testNotConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapNot');
        $normalizer = new DtoNormalizer();

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1], notField: 'allowed'));
        $this->assertNotContains("field \"notField\" must not match the 'not' schema", $valid);

        $invalid = $normalizer->validate(new $cls('locked', ['a' => 1], notField: 'forbidden'));
        $this->assertContains("field \"notField\" must not match the 'not' schema", $invalid);
    }

    public function testMapObjectConstraintsAreEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapMap');
        $normalizer = new DtoNormalizer();

        // minProperties: 1 — an empty map must fail.
        $tooFew = $normalizer->validate(new $cls('locked', []));
        $this->assertContains('field "mapField" must have at least 1 property', $tooFew);

        // additionalProperties: { type: integer } — a string value must fail.
        $wrongItemType = $normalizer->validate(new $cls('locked', ['a' => 'not-an-int']));
        $this->assertContains('field "mapField".a must be of type integer', $wrongItemType);

        $ok = $normalizer->validate(new $cls('locked', ['a' => 1, 'b' => 2]));
        $this->assertNotContains('field "mapField" must have at least 1 property', $ok);
    }

    public function testPrefixItemsConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapPrefix');
        $normalizer = new DtoNormalizer();

        // prefixItems: [string, integer] — index 0 must be a string.
        $invalid = $normalizer->validate(new $cls('locked', ['a' => 1], tupleField: [123, 7]));
        $this->assertContains('field "tupleField".0 must be of type string', $invalid);

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1], tupleField: ['ok', 7]));
        $this->assertNotContains('field "tupleField".0 must be of type string', $valid);
    }

    public function testUnevaluatedItemsIsEnforcedThroughGeneratedDto(): void
    {
        // An array field with prefixItems + unevaluatedItems: false stays a raw list at
        // runtime (not materialized), so the constraint reaches DtoValidator via the
        // generated getConstraints() and is enforced by DtoNormalizer::validate().
        $openApi = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'UnevalItems', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'TupleHolder' => [
                        'type' => 'object',
                        'required' => ['pair'],
                        'properties' => [
                            'pair' => [
                                'type' => 'array',
                                'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                                'unevaluatedItems' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenUnevalItems');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenUnevalItems\TupleHolder';
        $constraints = $cls::getConstraints();
        $this->assertArrayHasKey('pair', $constraints);
        $this->assertFalse($constraints['pair']['unevaluatedItems']);

        $normalizer = new DtoNormalizer();

        // Exact 2-tuple: nothing left unevaluated.
        $this->assertSame([], $normalizer->validate(new $cls(['x', 1])));

        // A third item is not covered by prefixItems → rejected.
        $errors = $normalizer->validate(new $cls(['x', 1, 'extra']));
        $matched = array_filter(
            $errors,
            static fn(string $e): bool => str_contains($e, 'unevaluated item at index 2'),
        );
        $this->assertNotEmpty($matched, 'expected an unevaluated-item error, got: ' . implode(' | ', $errors));
    }

    public function testUnevaluatedPropertiesIsForwardedAndEnforcedThroughGeneratedConstraints(): void
    {
        // A composed object with named properties is materialized into a dedicated nested DTO
        // (so unknown keys are impossible by construction). What we verify here is the other
        // half: the generator forwards allOf + unevaluatedProperties into getConstraints(),
        // and DtoValidator enforces them for the non-materialized (raw map / inline) path.
        $openApi = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'UnevalProps', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ConfigHolder' => [
                        'type' => 'object',
                        'required' => ['config'],
                        'properties' => [
                            'config' => [
                                'type' => 'object',
                                'allOf' => [
                                    ['properties' => ['a' => ['type' => 'string']]],
                                    ['properties' => ['b' => ['type' => 'integer']]],
                                ],
                                'unevaluatedProperties' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenUnevalProps');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenUnevalProps\ConfigHolder';
        $constraints = $cls::getConstraints();
        $this->assertArrayHasKey('config', $constraints);
        $this->assertFalse($constraints['config']['unevaluatedProperties']);
        $this->assertArrayHasKey('allOf', $constraints['config']);

        // Feed a raw map through DtoValidator using the generated constraints: keys covered
        // by the allOf branches pass, an extra key is rejected as unevaluated.
        $validator = new DtoValidator();
        $this->assertSame([], $validator->validate('config', ['a' => 'x', 'b' => 1], $constraints['config']));
        $this->assertContains(
            'config has unevaluated property "extra" which is not allowed',
            $validator->validate('config', ['a' => 'x', 'b' => 1, 'extra' => 9], $constraints['config']),
        );
    }

    public function testJsonSchemaDefsAreFoldedIntoComponentsAndResolved(): void
    {
        // JSON Schema `$defs` (sibling to `components`) + a `#/$defs/X` pointer: the generator
        // folds $defs into components.schemas and rewrites the ref, so the referenced schema is
        // materialized and the property is typed to it.
        $openApi = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Defs', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DefsHolder' => [
                        'type' => 'object',
                        'required' => ['addr'],
                        'properties' => [
                            'addr' => ['$ref' => '#/$defs/DefsAddress'],
                        ],
                    ],
                ],
            ],
            '$defs' => [
                'DefsAddress' => [
                    'type' => 'object',
                    'required' => ['city'],
                    'properties' => ['city' => ['type' => 'string']],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenDefs');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        // The $defs entry became a real DTO class.
        $this->assertTrue(class_exists('\GenDefs\DefsAddress'));

        // The holder's property is typed to the folded schema (ref resolved, not left dangling).
        $holderReflection = new ReflectionClass('\GenDefs\DefsHolder');
        $ctor = $holderReflection->getConstructor();
        $this->assertNotNull($ctor);
        $addrType = (string)$ctor->getParameters()[0]->getType();
        $this->assertStringContainsString('DefsAddress', $addrType);

        // End-to-end: a nested JSON body deserializes into the referenced DTO.
        /** @var class-string<GeneratedDtoInterface> $holder */
        $holder = '\GenDefs\DefsHolder';
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"addr":{"city":"Berlin"}}'),
            $holder,
        );
        $this->assertSame('Berlin', $dto->getAddr()->getCity());
    }

    public function testContentJsonParameterIsExtractedAndDeserialized(): void
    {
        // A query parameter serialized via `content: {application/json: {schema}}` (instead of
        // a plain `schema`) is no longer silently dropped: the schema is extracted, the param
        // is generated, and its JSON-string value is decoded before validation/casting.
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'ContentParam', 'version' => '1.0.0'],
            'paths' => [
                '/search' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'filter',
                                'in' => 'query',
                                'required' => true,
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['status'],
                                            'properties' => [
                                                'status' => ['type' => 'string'],
                                                'limit' => ['type' => 'integer'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => ['schemas' => []],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenContentParam');

        // Require every generated file — the inline content schema is materialized into a
        // separate nested DTO alongside the *QueryParams class.
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }
        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        $this->assertNotEmpty($queryParamFiles);

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenContentParam\\' . basename((string)($queryParamFiles === false ? '' : $queryParamFiles[0]), '.php');

        // The generator carries the JSON content-type via the 'json' style sentinel.
        $this->assertSame(['filter' => ['style' => 'json', 'explode' => false]], $cls::getParameterStyles());

        // The JSON string arrives in the query and is decoded into the nested object.
        $request = new Request(query: ['filter' => '{"status":"active","limit":5}']);
        $dto = (new DtoDeserializer())->deserialize($request, $cls);
        $this->assertSame('active', $dto->getFilter()->getStatus());
        $this->assertSame(5, $dto->getFilter()->getLimit());

        // Malformed JSON is a clear validation error, not a silent failure.
        $bad = new Request(query: ['filter' => '{not json']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parameter "filter" must be valid JSON');
        (new DtoDeserializer())->deserialize($bad, $cls);
    }

    public function testHeaderAndCookieParamsAreDeserializedThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/source-params.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSrc');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        $this->assertNotEmpty($queryParamFiles);
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GapSrc\\' . basename((string)$queryParamFiles[0], '.php');

        // The generator emitted the per-source binding map.
        $this->assertSame(
            ['id' => 'path', 'page' => 'query', 'token' => 'header', 'sid' => 'cookie'],
            $cls::getParameterSources(),
        );

        // Each value arrives only from its declared source.
        $request = new Request(
            query: ['page' => '5'],
            request: [],
            attributes: ['id' => 'abc'],
            cookies: ['sid' => 'cookie-1'],
            files: [],
            server: ['HTTP_TOKEN' => 'tok-1'],
        );

        $dto = (new DtoDeserializer())->deserialize($request, $cls);

        $this->assertSame('abc', $dto->getId());
        $this->assertSame(5, $dto->getPage());
        $this->assertSame('tok-1', $dto->getToken());
        $this->assertSame('cookie-1', $dto->getSid());
        $this->assertTrue($dto->isIdInPath());
        $this->assertTrue($dto->isPageInQuery());
        $this->assertTrue($dto->isTokenInHeader());
        $this->assertTrue($dto->isSidInCookie());

        // Parameter-bound fields are request transport, never serialized into the
        // payload — neither via the DTO's own toArray() nor through the normalizer.
        $this->assertSame([], $dto->toArray());
        $this->assertSame([], (new DtoNormalizer())->toArray($dto));
    }

    public function testRequiredHeaderParamMissingThrowsThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/source-params.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSrcMissing');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GapSrcMissing\\' . basename((string)($queryParamFiles[0] ?? ''), '.php');

        // token is a required header; omitting the header must fail even though a
        // same-named body field is present (strict source binding).
        $request = new Request(
            query: ['page' => '5'],
            request: [],
            attributes: ['id' => 'abc'],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"token":"from-body"}',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "token" not found in request');

        (new DtoDeserializer())->deserialize($request, $cls);
    }

    public function testDelimitedArrayParamsSplitByStyleThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/parameter-style.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenStyle');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenStyle\\' . basename((string)($queryParamFiles[0] ?? ''), '.php');

        // form/explode=false → comma, spaceDelimited → space, pipeDelimited → pipe,
        // form/explode=true → arrives as a repeated-key array (no re-splitting).
        $request = new Request(
            query: [
                'tags' => 'a,b,c',
                'codes' => 'x y z',
                'ids' => '1|2|3',
                'exploded' => ['p', 'q'],
            ],
        );

        /** @var object{getTags: callable, getCodes: callable, getIds: callable, getExploded: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize($request, $cls);

        $this->assertSame(['a', 'b', 'c'], $dto->getTags());
        $this->assertSame(['x', 'y', 'z'], $dto->getCodes());
        $this->assertSame(['1', '2', '3'], $dto->getIds());
        $this->assertSame(['p', 'q'], $dto->getExploded());
    }

    public function testNullableArrayItemsAreAcceptedThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nullable-array-items.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenNullItems');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenNullItems\TagBag';

        // items: {type: string, nullable: true} — a null element must be accepted, not rejected.
        /** @var object{getTags: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"tags":["a",null,"b"]}'),
            $cls,
        );

        $this->assertSame(['a', null, 'b'], $dto->getTags());
    }

    public function testDefaultValuedParamPresenceFlagReflectsActualProvision(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/default-param-presence.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenDefaultParam');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }
        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenDefaultParam\\' . basename((string)(($queryParamFiles ?: [])[0] ?? ''), '.php');

        // Direct construction without scope → flag false (it was never "in the query").
        /** @var object{isScopeInQuery: callable} $direct */
        $direct = new $cls();
        $this->assertFalse($direct->isScopeInQuery());

        // Deserialize with scope actually present → flag flipped true via reflection.
        /** @var object{isScopeInQuery: callable} $provided */
        $provided = (new DtoDeserializer())->deserialize(
            Request::create('/things?scope=active', 'GET'),
            $cls,
        );
        $this->assertTrue($provided->isScopeInQuery());
    }

    public function testOptionalBodyFieldWithDefaultCanBeOmittedViaUnsetSentinel(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Body default omission', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'SampleRequest' => [
                        'type' => 'object',
                        'required' => ['itemIds'],
                        'properties' => [
                            'stage' => ['$ref' => '#/components/schemas/SampleEnumView'],
                            'itemIds' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'SampleEnumView' => [
                        'type' => 'string',
                        'enum' => ['alpha', 'beta'],
                        'default' => 'alpha',
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBodyDefault');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenBodyDefault\SampleRequest';
        $enum = '\GenBodyDefault\SampleEnumView';

        // Plain construction → declared default applies and is serialized (intent preserved).
        /** @var object{toArray: callable, getStage: callable, isStageInRequest: callable} $withDefault */
        $withDefault = new $cls([1, 2]);
        $this->assertTrue($withDefault->isStageInRequest());
        $this->assertSame($enum::ALPHA, $withDefault->getStage());
        $this->assertArrayHasKey('stage', $withDefault->toArray());

        // Explicit UnsetValue::UNSET → omitted from the payload (new capability).
        /** @var object{toArray: callable, getStage: callable, isStageInRequest: callable} $omitted */
        $omitted = new $cls([1, 2], \OpenapiPhpDtoGenerator\Contract\UnsetValue::UNSET);
        $this->assertFalse($omitted->isStageInRequest());
        $this->assertNull($omitted->getStage());
        $this->assertArrayNotHasKey('stage', $omitted->toArray());

        // Deserialization of a payload without the field → default applied, presence stays false.
        $deserialized = (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                parameters: [],
                cookies: [],
                files: [],
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string)json_encode(['itemIds' => [1]]),
            ),
            $cls,
        );
        $this->assertSame($enum::ALPHA, $deserialized->getStage());
        $this->assertFalse($deserialized->isStageInRequest());

        // Deserialization of a payload WITH the field → value taken from payload, presence true.
        $provided = (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                parameters: [],
                cookies: [],
                files: [],
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string)json_encode(['itemIds' => [1], 'stage' => 'beta']),
            ),
            $cls,
        );
        $this->assertSame($enum::BETA, $provided->getStage());
        $this->assertTrue($provided->isStageInRequest());
    }

    public function testDeserializeCollectionParsesTopLevelJsonArrayBody(): void
    {
        // A bulk endpoint whose requestBody schema is `type: array` sends a top-level JSON array
        // (`[{...}, {...}]`). deserializeCollection() turns it into a list of item DTOs.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulk');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $itemClass */
        $itemClass = '\GenBulk\Item';
        $deserializer = new DtoDeserializer();

        // Happy path: top-level array of objects → list of DTOs.
        /** @var array<int, object{getId: callable}> $items */
        $items = $deserializer->deserializeCollection(
            $this->jsonPostRequest('[{"id":1},{"id":2},{"id":3}]'),
            $itemClass,
        );
        $this->assertCount(3, $items);
        $this->assertSame([1, 2, 3], array_map(static fn(object $i): int => $i->getId(), $items));

        // Empty array → empty list.
        $this->assertSame([], $deserializer->deserializeCollection($this->jsonPostRequest('[]'), $itemClass));
    }

    public function testDeserializeCollectionOfScalars(): void
    {
        // requestBody: {type: array, items: {type: <scalar>}} → top-level array of scalars.
        $deserializer = new DtoDeserializer();

        $this->assertSame(
            [1, 2, 3],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,2,3]'), 'int'),
        );
        $this->assertSame(
            [1.5, 2.5],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1.5,2.5]'), 'float'),
        );
        $this->assertSame(
            ['test1', 'test2'],
            $deserializer->deserializeCollection($this->jsonPostRequest('["test1","test2"]'), 'string'),
        );
        $this->assertSame(
            [true, false, true],
            $deserializer->deserializeCollection($this->jsonPostRequest('[true,false,true]'), 'bool'),
        );

        // A wrong-typed element is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,"x",3]'), 'int');
            $this->fail('Expected a type error for the non-int element.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('expects int', $e->getMessage());
        }
    }

    public function testDeserializeCollectionOfEnums(): void
    {
        // requestBody: {type: array, items: {$ref: SomeEnum}} → top-level array of enum values.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk enum', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'StageEnum' => [
                        'type' => 'string',
                        'enum' => ['early', 'late'],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulkEnum');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string $enumClass */
        $enumClass = '\GenBulkEnum\StageEnum';
        $deserializer = new DtoDeserializer();

        /** @var array<int, UnitEnum> $enums */
        $enums = $deserializer->deserializeCollection(
            $this->jsonPostRequest('["early","late","early"]'),
            $enumClass,
        );
        $this->assertCount(3, $enums);
        $this->assertSame(
            [$enumClass::EARLY, $enumClass::LATE, $enumClass::EARLY],
            $enums,
        );

        // An unknown enum value is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('["early","WRONG"]'), $enumClass);
            $this->fail('Expected an enum error for the unknown value.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('Allowed: early, late', $e->getMessage());
        }
    }

    public function testDeserializeCollectionOfDiscriminatedMixedObjects(): void
    {
        // A heterogeneous collection — requestBody: {type: array, items: {$ref: Pet}} where Pet
        // carries a discriminator. Each element resolves to its concrete subtype by the
        // discriminator property; you pass the BASE class, not a list of candidate classes.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk mixed', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['petType'],
                        'properties' => ['petType' => ['type' => 'string']],
                        'discriminator' => [
                            'propertyName' => 'petType',
                            'mapping' => [
                                'dog' => '#/components/schemas/Dog',
                                'cat' => '#/components/schemas/Cat',
                            ],
                        ],
                    ],
                    'Dog' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'string']]],
                        ],
                    ],
                    'Cat' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];
        $namespace = 'GenBulkMixed';
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        // Autoload by class name so parent classes load before their subtypes (require-order safe).
        spl_autoload_register(function (string $class) use ($namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $this->outputDirectory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        /** @var class-string<GeneratedDtoInterface> $baseClass */
        $baseClass = '\\' . $namespace . '\Pet';

        $pets = (new DtoDeserializer())->deserializeCollection(
            $this->jsonPostRequest('[{"petType":"dog","bark":"woof"},{"petType":"cat","meow":"mew"}]'),
            $baseClass,
        );

        $this->assertCount(2, $pets);
        $this->assertInstanceOf('\\' . $namespace . '\Dog', $pets[0]);
        $this->assertInstanceOf('\\' . $namespace . '\Cat', $pets[1]);
    }

    public function testDeserializeCollectionValidatesEachElement(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulkBad');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $itemClass */
        $itemClass = '\GenBulkBad\Item';
        $deserializer = new DtoDeserializer();

        // An element missing a required field is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('[{"id":1},{}]'), $itemClass);
            $this->fail('Expected a validation error for the invalid element.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
        }

        // An object root (not an array) is rejected by the collection path.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON body must be an array');
        $deserializer->deserializeCollection($this->jsonPostRequest('{"id":1}'), $itemClass);
    }

    public function testDeserializeValueCastsScalarsAndDateTimes(): void
    {
        // deserializeValue() is the per-element cast on its own: no Request, no JSON body,
        // just one already-decoded value.
        $deserializer = new DtoDeserializer();

        $this->assertSame(1, $deserializer->deserializeValue(1, 'int'));
        // JSON Schema 2020-12 §6.1.1: a number with a zero fractional part IS an integer.
        $this->assertSame(42, $deserializer->deserializeValue(42.0, 'int'));
        $this->assertSame(1.5, $deserializer->deserializeValue(1.5, 'float'));
        $this->assertSame('test', $deserializer->deserializeValue('test', 'string'));
        $this->assertTrue($deserializer->deserializeValue(true, 'bool'));
        $this->assertSame('anything', $deserializer->deserializeValue('anything', 'mixed'));

        // `array` in this position means a MAP (a list of maps is array<array<string, V>>), so it
        // takes the stdClass a JSON object decodes to — and refuses a JSON array, exactly like
        // `type: object` does anywhere else.
        $this->assertSame(['a' => 1], $deserializer->deserializeValue(json_decode('{"a":1}', false), 'array'));
        try {
            $deserializer->deserializeValue(json_decode('[1,2]', false), 'array');
            $this->fail('Expected a JSON array to be refused where an object is expected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expects object, got array', $e->getMessage());
        }

        $date = $deserializer->deserializeValue('2026-03-10T12:00:00+00:00', DateTimeImmutable::class);
        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2026-03-10T12:00:00+00:00', $date->format('c'));

        // The mirror case: a wrong-typed value is rejected, so the cast is not a no-op.
        try {
            $deserializer->deserializeValue('x', 'int');
            $this->fail('Expected a type error for the non-int value.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('param "value"', $e->getMessage());
            $this->assertStringContainsString('expects int', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects a valid date-time');
        $deserializer->deserializeValue('10.03.2026', DateTimeImmutable::class);
    }

    public function testDeserializeValueHonoursNullableAndTemporalFormat(): void
    {
        // A DTO property infers both facts from its own items schema. A bare $type has no owning
        // property to infer from, so they are parameters — without them a `format: date` element
        // and a nullable element are undeserializable, which is what these two used to be.
        $deserializer = new DtoDeserializer();

        $date = $deserializer->deserializeValue('2026-03-10', DateTimeImmutable::class, 'value', false, 'Y-m-d');
        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2026-03-10', $date->format('Y-m-d'));

        $this->assertNull($deserializer->deserializeValue(null, 'int', 'value', true));
        $this->assertNull($deserializer->deserializeValue(null, DateTimeImmutable::class, 'value', true));

        // Both are opt-in: the defaults keep rejecting, so nothing loosened for existing callers.
        try {
            $deserializer->deserializeValue('2026-03-10', DateTimeImmutable::class);
            $this->fail('Expected a date-only string to be refused without a temporal format.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expects a valid date-time', $e->getMessage());
        }

        try {
            $deserializer->deserializeValue(null, 'int');
            $this->fail('Expected null to be refused without $nullable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expects int, got null', $e->getMessage());
        }

        // 'Y-m-d' narrows the cast, it does not disable it: a malformed date still fails, and the
        // message names the narrowed format rather than the date-time one.
        try {
            $deserializer->deserializeValue('2026-13-99', DateTimeImmutable::class, 'value', false, 'Y-m-d');
            $this->fail('Expected an invalid date to be refused under Y-m-d.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expects a date in Y-m-d format', $e->getMessage());
        }
    }

    public function testDeserializeCollectionHonoursNullableAndTemporalFormat(): void
    {
        // The same two holes existed on the collection path since it was written — deserializeValue()
        // inherited them rather than introduced them, so both entry points carry the fix.
        $deserializer = new DtoDeserializer();

        $dates = $deserializer->deserializeCollection(
            $this->jsonPostRequest('["2026-03-10","2026-03-11"]'),
            DateTimeImmutable::class,
            false,
            'Y-m-d',
        );
        $this->assertCount(2, $dates);
        $this->assertSame(
            ['2026-03-10', '2026-03-11'],
            array_map(static fn(DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates),
        );

        $this->assertSame(
            [1, null, 3],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,null,3]'), 'int', true),
        );

        // Defaults unchanged: a null element without itemsNullable still fails, still by index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,null,3]'), 'int');
            $this->fail('Expected a null element to be refused without $itemsNullable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
        }

        $this->assertSame(
            [1, 2, 3],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,2,3]'), 'int'),
        );
    }

    public function testDeserializeValueKeepsObjectAndArrayApartUnderAssocInput(): void
    {
        // The docblock promises a value from json_decode($json, false), but a plain assoc array is
        // accepted too (a DTO from an assoc payload is asserted elsewhere in this file). What that
        // tolerance costs is the point of this test, and it is NOT a weakened `type: object`: the
        // check reads array_is_list() plus whether the value arrived as a JSON object, so under
        // assoc input it errs toward REFUSING. Nothing shaped like an array is silently accepted.
        //
        // The price is paid in the other direction, on one exotic-but-legal document: an object
        // whose keys are sequential integers decodes assoc to a list and is refused, while the same
        // document decoded to stdClass is accepted. Pinned here so the asymmetry is a visible
        // choice — six months from now someone passes `true` to json_decode and this says what
        // changes.
        $deserializer = new DtoDeserializer();

        // Objects pass either way.
        $this->assertSame(['a' => 1], $deserializer->deserializeValue(json_decode('{"a":1}', false), 'array'));
        $this->assertSame(['a' => 1], $deserializer->deserializeValue(['a' => 1], 'array'));

        // Arrays are refused either way — the object-vs-array line holds under assoc input.
        foreach ([json_decode('[1,2]', false), [1, 2]] as $arrayShaped) {
            try {
                $deserializer->deserializeValue($arrayShaped, 'array');
                $this->fail('Expected an array-shaped value to be refused where an object is expected.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('expects object, got array', $e->getMessage());
            }
        }

        // The asymmetry: {"0":1,"1":2} is an OBJECT, and only the stdClass decoding survives as one.
        $this->assertSame([1, 2], $deserializer->deserializeValue(json_decode('{"0":1,"1":2}', false), 'array'));
        try {
            $deserializer->deserializeValue(json_decode('{"0":1,"1":2}', true), 'array');
            $this->fail('Expected an integer-keyed object decoded assoc to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expects object, got array', $e->getMessage());
        }

        // An empty object is left alone in both decodings — a pre-decoded body reports `{}` as [].
        $this->assertSame([], $deserializer->deserializeValue(json_decode('{}', false), 'array'));
        $this->assertSame([], $deserializer->deserializeValue([], 'array'));
    }

    public function testContractAndServiceDeclareTheSameSignatures(): void
    {
        // 2.13.0 put the two items-schema arguments on the CONTRACT rather than on the service
        // alone. That is a breaking change for implementors — and the reason to pay it is that a
        // contract without them cannot state the null honestly: an implementation may not widen a
        // return type the interface narrows, so the flag-aware return only works if the flag is
        // part of the declaration. This test is the guard: interface and service must not drift.
        $this->assertInstanceOf(DtoDeserializerInterface::class, new DtoDeserializer());

        $signature = static function (string $class, string $method): array {
            return array_map(
                static fn(ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod($class, $method))->getParameters(),
            );
        };

        foreach (
            [
                'deserializeValue' => ['data', 'type', 'path', 'nullable', 'temporalFormat'],
                'deserializeCollection' => ['request', 'itemType', 'itemsNullable', 'itemTemporalFormat'],
            ] as $method => $expected
        ) {
            $this->assertSame($expected, $signature(DtoDeserializerInterface::class, $method));
            $this->assertSame($expected, $signature(DtoDeserializer::class, $method));
        }

        // Everything past the pre-2.13.0 arity stays optional, so no existing CALL site has to change
        // — only implementors of the interface do.
        foreach (['deserializeValue' => 3, 'deserializeCollection' => 2] as $method => $requiredBefore) {
            foreach ((new ReflectionMethod(DtoDeserializerInterface::class, $method))->getParameters() as $parameter) {
                if ($parameter->getPosition() < $requiredBefore) {
                    continue;
                }
                $this->assertTrue(
                    $parameter->isOptional(),
                    sprintf('DtoDeserializerInterface::%s($%s) must stay optional.', $method, $parameter->getName()),
                );
            }
        }
    }

    public function testDeserializeValueCastsEnumMembers(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value enum', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'StageEnum' => [
                        'type' => 'string',
                        'enum' => ['early', 'late'],
                    ],
                ],
            ],
        ];
        $this->generateFromInlineSpec($openApi, 'GenValueEnum', 'StageEnum');

        /** @var class-string $enumClass */
        $enumClass = '\GenValueEnum\StageEnum';
        $deserializer = new DtoDeserializer();

        $this->assertSame($enumClass::EARLY, $deserializer->deserializeValue('early', $enumClass));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Allowed: early, late');
        $deserializer->deserializeValue('WRONG', $enumClass);
    }

    public function testDeserializeValueBuildsADtoFromStdClassAndFromArray(): void
    {
        // json_decode($json, false) hands over a stdClass for an object — the shape the
        // docblock promises — while an assoc-decoded payload arrives as a plain array.
        // Both must land on the same DTO.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value dto', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'tag' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        $itemClass = $this->generateFromInlineSpec($openApi, 'GenValueDto', 'Item');
        $deserializer = new DtoDeserializer();

        $fromStdClass = $deserializer->deserializeValue(
            json_decode('{"id":1,"tag":"a"}', false),
            $itemClass,
        );
        $fromArray = $deserializer->deserializeValue(['id' => 1, 'tag' => 'a'], $itemClass);

        $this->assertInstanceOf($itemClass, $fromStdClass);
        $this->assertInstanceOf($itemClass, $fromArray);
        $this->assertSame($fromStdClass->toArray(), $fromArray->toArray());
        $this->assertSame(['id' => 1, 'tag' => 'a'], $fromStdClass->toArray());

        // A missing required property still fails — the element is validated, not just built.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parameter "value.id"');
        $deserializer->deserializeValue(json_decode('{"tag":"a"}', false), $itemClass);
    }

    public function testDeserializeValueResolvesTheDiscriminator(): void
    {
        // Same guarantee deserializeCollection() gives per element: you pass the BASE class
        // and the discriminator property picks the concrete subtype.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value discriminator', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['petType'],
                        'properties' => ['petType' => ['type' => 'string']],
                        'discriminator' => [
                            'propertyName' => 'petType',
                            'mapping' => [
                                'dog' => '#/components/schemas/Dog',
                                'cat' => '#/components/schemas/Cat',
                            ],
                        ],
                    ],
                    'Dog' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'string']]],
                        ],
                    ],
                    'Cat' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];
        $namespace = 'GenValueDiscriminated';
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        // Autoload by class name so parent classes load before their subtypes (require-order safe).
        spl_autoload_register(function (string $class) use ($namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $this->outputDirectory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        /** @var class-string<GeneratedDtoInterface> $baseClass */
        $baseClass = '\\' . $namespace . '\Pet';
        $deserializer = new DtoDeserializer();

        $this->assertInstanceOf(
            '\\' . $namespace . '\Dog',
            $deserializer->deserializeValue(json_decode('{"petType":"dog","bark":"woof"}', false), $baseClass),
        );
        $this->assertInstanceOf(
            '\\' . $namespace . '\Cat',
            $deserializer->deserializeValue(json_decode('{"petType":"cat","meow":"mew"}', false), $baseClass),
        );
    }

    public function testDeserializeValueNamesThePathInEveryError(): void
    {
        // $path is what a batch endpoint uses to say WHICH element went wrong; the default
        // keeps the message readable when there is no position to name.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value path', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        $itemClass = $this->generateFromInlineSpec($openApi, 'GenValuePath', 'Item');
        $deserializer = new DtoDeserializer();

        try {
            $deserializer->deserializeValue(json_decode('{"id":"x"}', false), $itemClass, '3');
            $this->fail('Expected a type error for the non-int id.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('param "3.id"', $e->getMessage());
        }

        try {
            $deserializer->deserializeValue(json_decode('{"id":"x"}', false), $itemClass);
            $this->fail('Expected a type error for the non-int id.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('param "value.id"', $e->getMessage());
        }

        // A scalar names the value itself, not a property of it.
        try {
            $deserializer->deserializeValue('x', 'int', 'items.7');
            $this->fail('Expected a type error for the non-int element.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('param "items.7"', $e->getMessage());
        }
    }

    public function testDeserializeValueLetsABatchEndpointReportPerElementErrors(): void
    {
        // The reason the method exists. deserializeCollection() aggregates every element
        // error into one exception, so one bad element fails the whole body; a batch
        // endpoint loops instead and keeps the good elements.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value batch', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        $itemClass = $this->generateFromInlineSpec($openApi, 'GenValueBatch', 'Item');
        $deserializer = new DtoDeserializer();

        /** @var array<int, mixed> $elements */
        $elements = json_decode('[{"id":1},{},{"id":3},{"id":"x"}]', false);

        $accepted = [];
        $rejected = [];
        foreach ($elements as $index => $element) {
            try {
                $accepted[$index] = $deserializer->deserializeValue($element, $itemClass, (string)$index);
            } catch (RuntimeException $e) {
                $rejected[$index] = $e->getMessage();
            }
        }

        $this->assertSame([0, 2], array_keys($accepted));
        $this->assertSame([1, 3], array_keys($rejected));
        $this->assertSame([1, 3], array_map(static fn(object $i): int => $i->getId(), array_values($accepted)));
        $this->assertStringContainsString('parameter "1.id"', $rejected[1]);
        $this->assertStringContainsString('param "3.id"', $rejected[3]);

        // The same body through deserializeCollection() fails as a whole — that is the
        // difference the method is there to remove.
        try {
            $deserializer->deserializeCollection(
                $this->jsonPostRequest('[{"id":1},{},{"id":3},{"id":"x"}]'),
                $itemClass,
            );
            $this->fail('Expected the collection path to fail on the invalid elements.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('element 3', $e->getMessage());
        }
    }

    public function testDeserializeValueMatchesDeserializeCollectionElementForElement(): void
    {
        // Both entry points go through the same per-element cast, so a valid body must
        // produce the same DTOs either way.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Value parity', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'tag' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        $itemClass = $this->generateFromInlineSpec($openApi, 'GenValueParity', 'Item');
        $deserializer = new DtoDeserializer();
        $body = '[{"id":1,"tag":"a"},{"id":2}]';

        $viaCollection = $deserializer->deserializeCollection($this->jsonPostRequest($body), $itemClass);

        /** @var array<int, mixed> $elements */
        $elements = json_decode($body, false);
        $viaValue = [];
        foreach ($elements as $index => $element) {
            $viaValue[] = $deserializer->deserializeValue($element, $itemClass, (string)$index);
        }

        $this->assertSame(
            array_map(static fn(GeneratedDtoInterface $i): array => $i->toArray(), $viaCollection),
            array_map(static fn(GeneratedDtoInterface $i): array => $i->toArray(), $viaValue),
        );
    }

    public function testDateTimeSubSecondPrecisionRoundTrips(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/datetime-precision.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenMoment');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenMoment\Moment';

        // Microseconds are preserved on output (not silently dropped by 'c').
        /** @var object{getAt: callable} $withMicros */
        $withMicros = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"at":"2026-01-01T12:00:00.123456+00:00"}'),
            $cls,
        );
        $this->assertSame('2026-01-01T12:00:00.123456+00:00', $withMicros->getAt());

        // Whole-second values keep the plain RFC 3339 ('c') form — no spurious fraction.
        /** @var object{getAt: callable} $wholeSecond */
        $wholeSecond = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"at":"2026-01-01T12:00:00+00:00"}'),
            $cls,
        );
        $this->assertSame('2026-01-01T12:00:00+00:00', $wholeSecond->getAt());
    }

    public function testValidateAndNormalizeOmitsUnprovidedOptionalField(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/normalize-unprovided.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenNormUnprov');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenNormUnprov\GenericResponse';

        // message is optional and not provided → must be omitted (not emitted as null),
        // matching the DTO's own inRequest-gated toArray().
        $dto = new $cls(true);
        $normalizer = new DtoNormalizer();

        $this->assertSame(['success' => true], $normalizer->validateAndNormalizeToArray($dto));
        $this->assertSame('{"success":true}', $normalizer->validateAndNormalizeToJson($dto));
    }

    public function testBinaryUploadFieldDropsStringTypeConstraintThroughGeneratedDto(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => ['/upload' => ['post' => [
                'operationId' => 'upload',
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['avatar'],
                    'properties' => ['avatar' => ['type' => 'string', 'format' => 'binary']],
                ]]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ];
        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'GenBinary');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\GenBinary\UploadPostRequest';

        // A binary field is materialized as UploadedFile — the string type/format must NOT be
        // forwarded as a constraint, else the validator rejects the uploaded file at deserialization.
        $avatarConstraints = $cls::getConstraints()['avatar'] ?? [];
        $this->assertArrayNotHasKey('type', $avatarConstraints);
        $this->assertArrayNotHasKey('format', $avatarConstraints);

        // And the upload actually deserializes: the file passes through, no "must be of type string".
        $tmp = tempnam(sys_get_temp_dir(), 'dto_bin_');
        $this->assertNotFalse($tmp);
        try {
            $file = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
            $request = new Request([], [], [], [], ['avatar' => $file]);
            $dto = (new DtoDeserializer())->deserialize($request, $cls);
            $this->assertInstanceOf(UploadedFile::class, $dto->getAvatar());
        } finally {
            @unlink($tmp);
        }
    }

    public function testChildClassSerializationMergesParentFields(): void
    {
        // A child (allOf/extends) must serialize parent fields too: toArray()/jsonSerialize()/
        // getNormalizationMap() merge parent:: (parent props are private, only the parent's own
        // methods read them). Otherwise update endpoints drop all base fields from the response.
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Box' => [
                    'type' => 'object',
                    'required' => ['id', 'name'],
                    'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                ],
                'BoxWithLabels' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/Box'],
                        [
                            'type' => 'object',
                            'required' => ['labels'],
                            'properties' => ['labels' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ]],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'MergeNs');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\MergeNs\BoxWithLabels';

        $this->assertSame(['id', 'name', 'labels'], array_keys($cls::getNormalizationMap()));

        $dto = new $cls(id: 7, name: 'alpha', labels: ['a', 'b']);

        $this->assertSame(['id' => 7, 'name' => 'alpha', 'labels' => ['a', 'b']], $dto->toArray());
        $this->assertSame(['id' => 7, 'name' => 'alpha', 'labels' => ['a', 'b']], $dto->jsonSerialize());
        $this->assertSame(
            ['id' => 7, 'name' => 'alpha', 'labels' => ['a', 'b']],
            (new DtoNormalizer())->toArray($dto),
        );
    }

    /**
     * A discriminator variant written as `allOf: [$ref base, $ref mixin]` must extend the
     * base at runtime, so a property typed as the base accepts the variant and the
     * deserializer's is_a() check passes. Before the fix the variant was a standalone class
     * (is_a === false) and deserialization into a base-typed property threw a TypeError.
     */
    public function testDiscriminatorAllOfMixinVariantExtendsBaseAndDeserializes(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/discriminator-allof-multiref.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'DiscMixinNs');
        // Load the base before the variants that extend it (glob is alphabetical, so a
        // variant would otherwise be required before its parent class exists).
        require $this->outputDirectory . '/ShapeView.php';
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            if (basename($file) === 'ShapeView.php') {
                continue;
            }
            require $file;
        }

        $base = '\DiscMixinNs\ShapeView';
        $variant = '\DiscMixinNs\CircleView';

        // Core regression: the runtime inheritance link exists.
        $this->assertTrue(is_a($variant, $base, true));

        // A request whose base-typed `shape` carries a variant payload resolves to the
        // concrete variant instance (the discriminator maps `circle` → CircleView).
        $body = (string)json_encode(['shape' => ['shapeType' => 'circle', 'label' => 'Berlin']]);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        /** @var class-string<GeneratedDtoInterface> $container */
        $container = '\DiscMixinNs\ShapeContainer';
        $dto = (new DtoDeserializer())->deserialize($request, $container);

        $shape = $dto->getShape();
        $this->assertInstanceOf(ltrim($variant, '\\'), $shape);
        $this->assertInstanceOf(ltrim($base, '\\'), $shape);
        $this->assertSame('Berlin', $shape->getLabel());
        // toArray merges the inherited discriminator property with the variant's own field.
        $array = $shape->toArray();
        $this->assertSame(['shapeType', 'label'], array_keys($array));
        $this->assertSame('Berlin', $array['label']);
        $this->assertInstanceOf(BackedEnum::class, $array['shapeType']);
        $this->assertSame('circle', $array['shapeType']->value);
    }

    /**
     * A three-level allOf inheritance chain where the intermediate parent is itself an allOf
     * composition. The leaf must inherit the full ancestor constructor (root + mid properties)
     * and chain parent::__construct upward. Before getParentProperties resolved allOf
     * recursively, the intermediate's properties read as empty, so the leaf dropped the
     * grandparent's arguments and omitted the parent constructor call (fatal at instantiation).
     */
    public function testDiscriminatorAllOfDeepChainInheritsWholeAncestry(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/discriminator-allof-deep-chain.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'DeepChainNs');
        // Load ancestors before descendants (glob is alphabetical).
        require $this->outputDirectory . '/RootBase.php';
        require $this->outputDirectory . '/MidLevel.php';
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            if (in_array(basename($file), ['RootBase.php', 'MidLevel.php'], true)) {
                continue;
            }
            require $file;
        }

        $root = 'DeepChainNs\RootBase';
        $mid = 'DeepChainNs\MidLevel';
        $leaf = 'DeepChainNs\LeafLevel';

        // Transitive inheritance link across all three levels.
        $this->assertTrue(is_a($mid, $root, true));
        $this->assertTrue(is_a($leaf, $mid, true));
        $this->assertTrue(is_a($leaf, $root, true));

        // The leaf constructor must accept every ancestor property, not just its own.
        $params = (new ReflectionMethod($leaf, '__construct'))->getParameters();
        $names = array_map(static fn($p): string => $p->getName(), $params);
        $this->assertSame(['kind', 'midField', 'leafField'], $names);

        // Instantiating the leaf runs the full parent::__construct chain and normalizes every level.
        $enumClass = (new ReflectionMethod($mid, '__construct'))->getParameters()[0]->getType();
        $this->assertNotNull($enumClass);
        $enumFqcn = (string)$enumClass;
        $leafInstance = new $leaf($enumFqcn::from('leaf'), 'mid-value', 42);
        $this->assertInstanceOf($root, $leafInstance);
        $this->assertSame(
            ['midField' => 'mid-value', 'leafField' => 42],
            array_intersect_key($leafInstance->toArray(), ['midField' => 1, 'leafField' => 1]),
        );
        $this->assertArrayHasKey('kind', $leafInstance->toArray());
    }

    /**
     * Loads every generated class/interface in $this->outputDirectory under a temporary
     * autoloader so require-order (interface extends chains) does not matter.
     */
    private function autoloadGenerated(string $namespace): callable
    {
        $dir = $this->outputDirectory;
        $loader = static function (string $class) use ($dir, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $short = substr($class, strrpos($class, '\\') + 1);
            $file = $dir . '/' . $short . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($loader);

        return $loader;
    }

    /**
     * Gap A — a union branch that is a $ref to another union. LeafB is a member of InnerUnion,
     * and InnerUnion is a branch of OuterUnion, so LeafB must also satisfy OuterUnion. This
     * requires the InnerUnion interface to extend the OuterUnion interface.
     */
    public function testNestedUnionViaRefLinksInnerMembersToOuterUnion(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nested-union.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'NestedUnionRefNs');
        $loader = $this->autoloadGenerated('NestedUnionRefNs');

        try {
            $this->assertTrue(is_a('NestedUnionRefNs\LeafA', 'NestedUnionRefNs\OuterUnion', true));
            $this->assertTrue(is_a('NestedUnionRefNs\LeafB', 'NestedUnionRefNs\InnerUnion', true));
            // The nested link: an InnerUnion member must also be an OuterUnion member.
            $this->assertTrue(is_a('NestedUnionRefNs\LeafB', 'NestedUnionRefNs\OuterUnion', true));
            $this->assertTrue(is_a('NestedUnionRefNs\LeafC', 'NestedUnionRefNs\OuterUnion', true));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * Gap B — an anyOf with an inline oneOf branch. The inline union's members (LeafB, LeafC)
     * should be recognised as members of the outer MixedUnion.
     */
    public function testAnyOfWithInlineOneOfBranchLinksMembers(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nested-union.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'NestedUnionInlineNs');
        $loader = $this->autoloadGenerated('NestedUnionInlineNs');

        try {
            $this->assertTrue(is_a('NestedUnionInlineNs\LeafA', 'NestedUnionInlineNs\MixedUnion', true));
            $this->assertTrue(is_a('NestedUnionInlineNs\LeafB', 'NestedUnionInlineNs\MixedUnion', true));
            $this->assertTrue(is_a('NestedUnionInlineNs\LeafC', 'NestedUnionInlineNs\MixedUnion', true));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * Gap C — a property whose schema wraps a oneOf inside allOf. The field must be typed as the
     * union (a member of it accepts LeafA/LeafB), not materialised as an empty object DTO.
     */
    public function testOneOfInsideAllOfPropertyIsTypedAsUnionNotEmptyObject(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nested-union.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'NestedUnionAllOfNs');
        $loader = $this->autoloadGenerated('NestedUnionAllOfNs');

        try {
            // The property must be typed as the union (LeafA|LeafB), not collapsed into a
            // standalone empty object DTO.
            $this->assertFileDoesNotExist($this->outputDirectory . '/UnionHolderField.php');

            $fieldParam = (new ReflectionMethod('NestedUnionAllOfNs\UnionHolder', '__construct'))
                ->getParameters()[0];
            $type = (string)$fieldParam->getType();
            $this->assertStringContainsString('LeafA', $type);
            $this->assertStringContainsString('LeafB', $type);
            $this->assertStringNotContainsString('UnionHolderField', $type);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * A self-referential schema (a tree node whose properties point back at its own type, directly
     * and through an array) must generate a single class that types those properties as itself —
     * and the generator must terminate rather than recurse forever.
     */
    public function testSelfReferentialSchemaGeneratesRecursiveType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/self-referential.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'SelfRefNs');
        $loader = $this->autoloadGenerated('SelfRefNs');

        try {
            $node = 'SelfRefNs\TreeNode';
            $params = [];
            foreach ((new ReflectionMethod($node, '__construct'))->getParameters() as $param) {
                $params[$param->getName()] = (string)$param->getType();
            }

            // The self-referencing property is typed as the node itself; the array of children
            // is typed as array with a TreeNode item docblock.
            $this->assertStringContainsString('TreeNode', $params['parent']);
            $this->assertArrayHasKey('children', $params);

            // The recursive structure actually instantiates: a parent holding a child of its own type.
            $child = new $node('leaf');
            $root = new $node('root', $child, [$child]);
            $this->assertInstanceOf($node, $root->getParent());
            $this->assertSame('root', $root->getValue());
            $this->assertSame($child, $root->getChildren()[0]);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * A discriminator declared on a `oneOf` base (the composition pattern, as opposed to the allOf
     * inheritance pattern). The base must carry the discriminator mapping and its members must be
     * subtypes of it, so a property typed as the base deserializes to the concrete variant selected
     * by the discriminator value.
     */
    public function testOneOfBasedDiscriminatorResolvesSubtype(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/oneof-discriminator.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'OneOfDiscNs');
        // Load the base before its members (glob is alphabetical).
        require $this->outputDirectory . '/PetBase.php';
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            if (basename($file) === 'PetBase.php') {
                continue;
            }
            require $file;
        }

        $base = 'OneOfDiscNs\PetBase';
        $dog = 'OneOfDiscNs\DogPet';

        // Members are subtypes of the base, and the base exposes the discriminator mapping.
        $this->assertTrue(is_a($dog, $base, true));
        $this->assertTrue(method_exists($base, 'getDiscriminatorMapping'));
        $this->assertSame(
            ['dog' => 'OneOfDiscNs\DogPet', 'cat' => 'OneOfDiscNs\CatPet'],
            $base::getDiscriminatorMapping(),
        );

        // A base-typed property deserializes to the concrete variant chosen by the discriminator.
        $body = (string)json_encode(['pet' => ['petType' => 'dog', 'bark' => 'woof']]);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        /** @var class-string<GeneratedDtoInterface> $owner */
        $owner = 'OneOfDiscNs\PetOwner';
        $dto = (new DtoDeserializer())->deserialize($request, $owner);

        $pet = $dto->getPet();
        $this->assertInstanceOf($dog, $pet);
        $this->assertSame('woof', $pet->getBark());
    }

    /**
     * The discriminator base can also be an `anyOf` (not only `oneOf`). Same abstract-base
     * treatment: members extend it and resolve through the discriminator mapping.
     */
    public function testAnyOfBasedDiscriminatorResolvesSubtype(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'ShapeUnion' => [
                    'anyOf' => [
                        ['$ref' => '#/components/schemas/CircleVariant'],
                        ['$ref' => '#/components/schemas/SquareVariant'],
                    ],
                    'discriminator' => [
                        'propertyName' => 'kind',
                        'mapping' => [
                            'circle' => '#/components/schemas/CircleVariant',
                            'square' => '#/components/schemas/SquareVariant',
                        ],
                    ],
                ],
                'CircleVariant' => [
                    'type' => 'object',
                    'required' => ['kind', 'radius'],
                    'properties' => ['kind' => ['type' => 'string'], 'radius' => ['type' => 'integer']],
                ],
                'SquareVariant' => [
                    'type' => 'object',
                    'required' => ['kind', 'side'],
                    'properties' => ['kind' => ['type' => 'string'], 'side' => ['type' => 'integer']],
                ],
                'ShapeHolder' => [
                    'type' => 'object',
                    'required' => ['shape'],
                    'properties' => ['shape' => ['$ref' => '#/components/schemas/ShapeUnion']],
                ],
            ]],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'AnyOfDiscNs');
        require $this->outputDirectory . '/ShapeUnion.php';
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            if (basename($file) === 'ShapeUnion.php') {
                continue;
            }
            require $file;
        }

        $base = 'AnyOfDiscNs\ShapeUnion';
        $circle = 'AnyOfDiscNs\CircleVariant';
        $this->assertTrue((new ReflectionClass($base))->isAbstract());
        $this->assertTrue(is_a($circle, $base, true));

        $body = (string)json_encode(['shape' => ['kind' => 'circle', 'radius' => 3]]);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        /** @var class-string<GeneratedDtoInterface> $holder */
        $holder = 'AnyOfDiscNs\ShapeHolder';
        $dto = (new DtoDeserializer())->deserialize($request, $holder);

        $shape = $dto->getShape();
        $this->assertInstanceOf($circle, $shape);
        $this->assertSame(3, $shape->getRadius());
    }

    /**
     * A member listed by two different oneOf discriminator bases cannot extend both (PHP single
     * inheritance). The generator must degrade gracefully — emit the member with no parent rather
     * than crash or pick an arbitrary base — since the linkage is ambiguous.
     */
    public function testDiscriminatorMemberInTwoUnionsDegradesGracefully(): void
    {
        $union = static fn(string $prop): array => [
            'oneOf' => [['$ref' => '#/components/schemas/SharedVariant']],
            'discriminator' => [
                'propertyName' => $prop,
                'mapping' => ['s' => '#/components/schemas/SharedVariant'],
            ],
        ];
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'FirstBase' => $union('k'),
                'SecondBase' => $union('k'),
                'SharedVariant' => [
                    'type' => 'object',
                    'required' => ['k'],
                    'properties' => ['k' => ['type' => 'string']],
                ],
            ]],
        ];

        // Generation must not throw despite the ambiguous membership.
        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'TwoUnionNs');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        // The ambiguous member extends neither base (graceful: no wrong parent picked).
        $this->assertFalse((new ReflectionClass('TwoUnionNs\SharedVariant'))->getParentClass());
    }

    /**
     * Each nesting level is deserialized by its own pass, so an error from four levels down used to name
     * only the innermost key: `Required parameter "n" not found in request.` on a payload whose root
     * declares no `n` at all. Every message family has to carry the whole path.
     */
    public function testAnErrorFourLevelsDownNamesTheWholePath(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'A' => [
                    'type' => 'object',
                    'required' => ['b'],
                    'properties' => ['b' => ['$ref' => '#/components/schemas/B']],
                ],
                'B' => [
                    'type' => 'object',
                    'required' => ['cs'],
                    'properties' => ['cs' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/C'],
                    ]],
                ],
                'C' => [
                    'type' => 'object',
                    'required' => ['d'],
                    'properties' => ['d' => ['$ref' => '#/components/schemas/D']],
                ],
                'D' => [
                    'type' => 'object',
                    'required' => ['n'],
                    'properties' => ['n' => ['type' => 'integer', 'minimum' => 10]],
                ],
            ]],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'DeepPathNs');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        $cases = [
            // missing key, wrong type, failed constraint, non-object where a DTO is expected
            '{"b":{"cs":[{"d":{}}]}}' => 'Required parameter "b.cs.0.d.n" not found in request.',
            '{"b":{"cs":[{"d":{"n":"x"}}]}}' => 'param "b.cs.0.d.n" expects int, got string',
            '{"b":{"cs":[{"d":{"n":3}}]}}' => 'param "b.cs.0.d.n" must be greater than or equal to 10',
            '{"b":{"cs":[{"d":"nope"}]}}' => 'param "b.cs.0.d": Cannot deserialize nested DTO',
        ];

        foreach ($cases as $json => $expected) {
            $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);

            try {
                /** @var class-string $fqcn */
                $fqcn = 'DeepPathNs\A';
                (new DtoDeserializer())->deserialize($request, $fqcn);
                $this->fail(sprintf('%s was accepted', $json));
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage(), $json);
            }
        }
    }

    /**
     * A container inside a container, and the two things that were true of it: the DECLARATION named a
     * type nothing delivered, and the CONSTRAINTS named nothing at all.
     *
     * Nothing is materialized below the first level of items — no DTO, no enum class — so
     * `array<array<StrEnum>>` was a promise the hydrator never kept (the values stayed strings) and
     * `array<array<Tag>>` one it kept even less (a `stdClass` from `json_decode()`). Both spellings
     * now declare `mixed`, and the enum members ride in the constraints instead, where the value can
     * actually be checked. A nested SCALAR keeps its type, because JSON already delivers it.
     */
    public function testNestedContainerDeclarationsAreHonestAndItsValuesAreChecked(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Kind' => ['type' => 'string', 'enum' => ['a', 'b']],
                'Tag' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'minimum' => 5]],
                ],
                'Nested' => [
                    'type' => 'object',
                    'required' => ['matrix', 'byKey', 'kindRows', 'tagRows'],
                    'properties' => [
                        'matrix' => [
                            'type' => 'array',
                            'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
                        ],
                        'byKey' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'kindRows' => [
                            'type' => 'array',
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Kind']],
                        ],
                        'tagRows' => [
                            'type' => 'array',
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                        ],
                    ],
                ],
            ]],
        ];
        $fqcn = $this->generateFromInlineSpec($spec, 'NestedContainerNs', 'Nested');

        $source = (string)file_get_contents($this->outputDirectory . '/Nested.php');
        // Every declaration two deep says what is really there, which is the JSON value: nothing casts
        // at this depth, so a date and an enum member are both the `string` the payload carried, and
        // an enum's own $ref resolves to its backing type rather than to the class it would have been
        // one level up.
        $this->assertStringContainsString('@param array<array<int>> $matrix', $source);
        $this->assertStringContainsString('@param array<string, array<string>> $byKey', $source);
        $this->assertStringContainsString('@param array<array<string>> $kindRows', $source);
        // The one that stays `mixed`, and the only honest answer for it: an OBJECT two deep is the
        // `stdClass` `json_decode()` produced, so neither `Tag` nor `array` is true of it.
        $this->assertStringContainsString('@param array<array<mixed>> $tagRows', $source);
        // No class is synthesized down there — one that nothing references would still be written out.
        $this->assertSame(
            ['Kind.php', 'Nested.php', 'Tag.php'],
            array_map('basename', glob($this->outputDirectory . '/*.php') ?: []),
        );

        $valid = '{"matrix":[[1,2],[3]],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[[{"id":9}]]}';
        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest($valid), $fqcn);
        $this->assertSame([[1, 2], [3]], $dto->getMatrix());
        $this->assertSame(['a' => ['x']], $dto->getByKey());
        $this->assertSame([], (new DtoNormalizer())->validate($dto));
        $this->assertSame(
            $valid,
            (string)json_encode((new DtoNormalizer())->toArray($dto)),
            'a nested list must not turn into a map on the way out, nor a nested map into a list',
        );

        // THE case that passed in silence: a member the enum does not have, two containers deep.
        $cases = [
            '{"matrix":[[1]],"byKey":{"a":["x"]},"kindRows":[["zzz"]],"tagRows":[[{"id":9}]]}'
                => 'kindRows".0.0 must be one of: "a", "b"',
            '{"matrix":[[-1]],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[[{"id":9}]]}'
                => 'matrix".0.0 must be greater than or equal to 0',
            '{"matrix":[[1]],"byKey":{"a":[7]},"kindRows":[["a"]],"tagRows":[[{"id":9}]]}'
                => 'byKey".a.0 must be of type string',
            // And the wire shape of the container itself, which the item cast owns.
            '{"matrix":[1],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[[{"id":9}]]}'
                => 'param "matrix.0" expects array, got int',
            // A `$ref`ed OBJECT two containers deep. Nothing hydrates it — the value stays the
            // `stdClass` `json_decode()` produced, which is why the declaration above says
            // `array<array<mixed>>` — but the component's own rules now reach it. All three used to
            // pass in silence: the emitted constraint stopped at `items => ['type' => 'array']`
            // because `$ref` is not a constraint keyword, and even once it did not,
            // `DtoValidator` skipped every object keyword for a value that was not a generated DTO.
            '{"matrix":[[1]],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[[{"id":1}]]}'
                => 'tagRows".0.0.id must be greater than or equal to 5',
            '{"matrix":[[1]],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[[{}]]}'
                => 'tagRows".0.0.id is required',
            '{"matrix":[[1]],"byKey":{"a":["x"]},"kindRows":[["a"]],"tagRows":[["zzz"]]}'
                => 'tagRows".0.0 must be of type object',
        ];

        foreach ($cases as $json => $expected) {
            try {
                (new DtoDeserializer())->deserialize($this->jsonPostRequest($json), $fqcn);
                $this->fail(sprintf('%s was accepted', $json));
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage(), $json);
            }
        }
    }
}
