<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The shapes a real operation produces — POST body, PATCH partial, DELETE/GET parameters — and the
 * edge values that sit between "valid" and "invalid" and are easy to get wrong in both directions.
 */
final class Yii3RequestShapeTest extends TestCase
{
    use GeneratesYii3Input;

    /**
     * A request-payload class carries `#[FromBody]`; a schema that is merely nested does NOT.
     *
     * Emitting it on every schema was a real bug: a nested `Tag` with `#[FromBody]` re-read the WHOLE
     * request body instead of the value it was being hydrated from, and the enclosing object then
     * failed to build at all.
     */
    public function testOnlyARequestPayloadCarriesASourceAttribute(): void
    {
        $namespace = $this->generateSpec([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/posts' => [
                    'post' => [
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/PostBody']],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'PostBody' => [
                        'type' => 'object',
                        'required' => ['title', 'tag'],
                        'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 3],
                            'tag' => ['$ref' => '#/components/schemas/Tag'],
                        ],
                    ],
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string', 'minLength' => 2]],
                    ],
                ],
            ],
        ]);

        $body = new ReflectionClass($namespace . '\PostBody');
        $tag = new ReflectionClass($namespace . '\Tag');

        self::assertNotSame([], $body->getAttributes(\Yiisoft\Input\Http\Attribute\Data\FromBody::class));
        self::assertSame(
            [],
            $tag->getAttributes(\Yiisoft\Input\Http\Attribute\Data\FromBody::class),
            'A nested schema must not re-read the request body.',
        );

        // …and it still hydrates and validates through the nested value.
        $container = new Yii3Container();
        $valid = $container->hydrate($namespace . '\PostBody', ['title' => 'hello', 'tag' => ['name' => 'ok']]);
        self::assertTrue($container->validate($valid)->isValid());

        $invalid = $container->hydrate($namespace . '\PostBody', ['title' => 'hello', 'tag' => ['name' => 'x']]);
        self::assertArrayHasKey('tag.name', $this->messages($container->validate($invalid)));
    }

    /**
     * A DELETE (or GET) whose input is parameters rather than a body: `in: query` becomes `#[Query]`
     * and `in: path` becomes `#[Request]`, which reads PSR-7 request attributes — where routers put
     * path parameters.
     *
     * `in: header` and `in: cookie` have no attribute in `yiisoft/input-http` at all, so a property
     * declaring either is emitted WITHOUT a source rather than with a wrong one.
     */
    public function testQueryAndPathParametersGetTheirOwnSourceAttributes(): void
    {
        $namespace = $this->generateSpec([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/posts/{id}' => [
                    'delete' => [
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'force', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                            ['name' => 'X-Trace', 'in' => 'header', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['204' => ['description' => 'gone']],
                    ],
                ],
            ],
        ]);

        // Classes are autoloaded on demand, so ask the FILES which one the operation produced.
        $class = null;
        foreach ((array)glob($this->outputDirectory . '/' . $namespace . '/*Delete*.php') as $file) {
            if (is_string($file)) {
                $class = $namespace . '\\' . basename($file, '.php');
                break;
            }
        }
        self::assertNotNull($class, 'the operation must produce a parameters class');
        self::assertTrue(class_exists($class));

        $sources = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $names = array_map(
                static fn(ReflectionAttribute $a): string => (new ReflectionClass($a->getName()))->getShortName(),
                $property->getAttributes(),
            );
            $sources[$property->getName()] = $names;
        }

        self::assertContains('Request', $sources['id'] ?? [], 'a path parameter binds through #[Request]');
        self::assertContains('Query', $sources['force'] ?? [], 'a query parameter binds through #[Query]');
        foreach ($sources as $name => $attributes) {
            if (!str_contains(strtolower($name), 'trace')) {
                continue;
            }
            self::assertNotContains('Query', $attributes, 'a header parameter must not be bound as query');
            self::assertNotContains('Request', $attributes, 'a header parameter must not be bound as a route value');
        }
    }

    /**
     * `nullable: true` means the client MAY send null; the absence of it means they may not. The two
     * must not be confused, which is exactly what marking every optional property nullable did.
     */
    public function testNullIsAcceptedOnlyWhereTheSchemaAllowsIt(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep'],
                'properties' => [
                    'keep' => ['type' => 'string'],
                    'plain' => ['type' => 'string'],
                    'nullable' => ['type' => 'string', 'nullable' => true],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $class = $namespace . '\Probe';

        self::assertTrue(
            $container->validate($container->hydrate($class, ['keep' => 'x', 'nullable' => null]))->isValid(),
            'a nullable property must accept its own null',
        );
        self::assertFalse(
            $container->validate($container->hydrate($class, ['keep' => 'x', 'plain' => null]))->isValid(),
            'optional is not nullable: a null the schema never allowed must be rejected',
        );
    }

    /**
     * Values that are "empty" but legal. Each one used to be rejected by something that conflated
     * empty with absent — `#[Required]` treats `{}`, `[]` and `''` as blank, which OpenAPI does not.
     */
    public function testLegalEmptyValuesAreAccepted(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['emptyObject', 'emptyList', 'emptyString', 'zero', 'false'],
                'properties' => [
                    'emptyObject' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    'emptyList' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'emptyString' => ['type' => 'string'],
                    'zero' => ['type' => 'integer'],
                    'false' => ['type' => 'boolean'],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $result = $container->validate($container->hydrate($namespace . '\Probe', [
            'emptyObject' => [],
            'emptyList' => [],
            'emptyString' => '',
            'zero' => 0,
            'false' => false,
        ]));

        self::assertTrue($result->isValid(), implode(' | ', $this->messages($result)));
    }

    /**
     * …and the mirror: a bound that an empty value genuinely breaks is still enforced, so the case
     * above is not "empty values skip validation".
     */
    public function testAGetterNamedLikeAnInterfaceMethodTakesASuffix(): void
    {
        // `data` gave `getData(): ?string` beside `DataSetInterface::getData()` — "Cannot redeclare",
        // and the class never loaded.
        $namespace = $this->generate([
            'Envelope' => [
                'type' => 'object',
                'properties' => ['data' => ['type' => 'string'], 'rules' => ['type' => 'string']],
            ],
        ]);

        $container = new Yii3Container();
        $envelope = $container->hydrate($namespace . '\Envelope', ['data' => 'd', 'rules' => 'r']);

        self::assertSame('d', $envelope->getDataValue());
        self::assertSame('r', $envelope->getRulesValue());
        self::assertSame(['data' => 'd', 'rules' => 'r'], $envelope->getData());
    }

    public function testABooleanQueryParameterReadsTrueAndFalse(): void
    {
        // OpenAPI writes a boolean parameter `true` / `false`; the hydrator's `(bool)` cast made
        // `?force=false` TRUE, and the rules passed it.
        $namespace = $this->generateSpec([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => ['/posts' => ['get' => [
                'operationId' => 'listPosts',
                'parameters' => [['name' => 'force', 'in' => 'query', 'schema' => ['type' => 'boolean']]],
                'responses' => ['204' => ['description' => 'OK']],
            ]]],
        ]);
        $class = $namespace . '\PostsGetQueryParams';
        $read = function (string $value) use ($class): array {
            $container = new Yii3Container((new ServerRequest('GET', '/posts'))->withQueryParams(['force' => $value]));
            $input = $container->hydrator()->create($class, []);

            return [$input->getForce(), $this->messages($container->validate($input))];
        };

        self::assertSame([false, []], $read('false'));
        self::assertSame([true, []], $read('true'));
        self::assertSame([false, []], $read('0'));
        self::assertSame([true, []], $read('1'));
        self::assertArrayHasKey('force', $read('yes')[1]);
    }

    public function testAValueTheCastWouldCorruptIsRefused(): void
    {
        // The hydrator casts BEFORE the rules run: `"abc"` reached `#[Integer]` as 0 and `2.5` as 2, both
        // validated, and the corrupted value was stored. `#[Validate]` checks the raw value.
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['score'],
                'properties' => [
                    'score' => ['type' => 'integer', 'nullable' => true, 'minimum' => 0, 'maximum' => 3],
                    'label' => ['type' => 'string'],
                    'ratio' => ['type' => 'number'],
                    'active' => ['type' => 'boolean'],
                ],
            ],
        ]);
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($namespace . '\Probe', $payload));

        self::assertArrayHasKey('score', $verdict(['score' => 'abc']));
        self::assertArrayHasKey('score', $verdict(['score' => 2.5]));
        self::assertArrayHasKey('label', $verdict(['score' => 1, 'label' => 5]));
        self::assertArrayHasKey('ratio', $verdict(['score' => 1, 'ratio' => 'x']));
        self::assertArrayHasKey('active', $verdict(['score' => 1, 'active' => 'yes']));
        self::assertSame([], $verdict(['score' => 2, 'label' => 'a', 'ratio' => 1.5, 'active' => true]));
        self::assertSame([], $verdict(['score' => null]));
        // A numeric string is what a query, a form or a database row carries; the cast reads it right.
        self::assertSame([], $verdict(['score' => '2', 'ratio' => '1.5']));
    }

    public function testAPropertyNamedWithADotIsReadByItsLiteralKey(): void
    {
        // `#[Data('priority.note')]` is a PATH to the hydrator — `['priority']['note']` — so a nested
        // `{"priority":{"note":…}}`, which the schema does not describe, filled the property too.
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['priority.note'],
                'properties' => ['priority.note' => ['type' => 'string']],
            ],
        ]);

        $container = new Yii3Container();
        $probe = $container->hydrate($namespace . '\Probe', ['priority.note' => 'urgent']);

        self::assertSame('urgent', $probe->getPriorityNote());
        self::assertTrue($container->validate($probe)->isValid());
        self::assertFalse($container->validate($container->hydrate($namespace . '\Probe', ['priority' => ['note' => 'x']]))->isValid());
    }

    public function testCreateBuildsAnObjectThatKnowsWhatWasSent(): void
    {
        // A response was only buildable from an array keyed by strings: a typo was silent, and a list of
        // objects built beforehand was dropped by the hydrator's collection resolver.
        $namespace = $this->generate([
            'Envelope' => [
                'type' => 'object',
                'required' => ['success', 'items'],
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string', 'nullable' => true],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
                    'kind' => ['$ref' => '#/components/schemas/Kind'],
                ],
            ],
            'Item' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string']],
            ],
            'Kind' => ['type' => 'string', 'enum' => ['round', 'square']],
        ]);
        $envelope = $namespace . '\Envelope';
        $item = $namespace . '\Item';
        $kind = $namespace . '\Kind';

        // `message` may be null by the schema: left out it is not sent, as in runtime mode, and `null` is
        // written as `"message": null`. A `null` default could not tell the two apart, and one of them was
        // always wrong on the wire.
        $built = $envelope::create(success: true, items: [$item::create(name: 'a')], kind: $kind::from('round'));
        self::assertFalse($built->isMessageProvided());
        self::assertSame(['success' => true, 'items' => [['name' => 'a']], 'kind' => 'round'], $built->getData());
        self::assertTrue((new Yii3Container())->validate($built)->isValid());

        $withNull = $envelope::create(success: true, message: null, items: [], kind: $kind::from('round'));
        self::assertTrue($withNull->isMessageProvided());
        self::assertSame(['success' => true, 'message' => null, 'items' => [], 'kind' => 'round'], $withNull->getData());

        $without = $withNull->withoutProperty('message');
        self::assertFalse($without->isMessageProvided());
        self::assertTrue($withNull->isMessageProvided(), 'withoutProperty() returns a copy.');

        $noKind = $envelope::create(success: true, items: []);
        self::assertFalse($noKind->isKindProvided());
        self::assertTrue($noKind->withProvided('kind')->isKindProvided());
    }

    public function testCreateTakesBuiltObjectsWhereTheHydratorLeavesArrays(): void
    {
        // A map of objects and an allOf-discriminated base: the map took only arrays, and the base was a
        // class of its own that no variant was a subtype of, so neither could hold a built object.
        $namespace = $this->generate([
            'Board' => [
                'type' => 'object',
                'properties' => [
                    'byId' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Detail']],
                    'meta' => ['$ref' => '#/components/schemas/Report'],
                ],
            ],
            'Detail' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]]],
            'Report' => [
                'type' => 'object',
                'required' => ['report'],
                'properties' => ['report' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'report', 'mapping' => ['alpha' => '#/components/schemas/AlphaReport']],
            ],
            'AlphaReport' => ['allOf' => [
                ['$ref' => '#/components/schemas/Report'],
                ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            ]],
        ]);
        $board = $namespace . '\Board';
        $detail = $namespace . '\Detail';
        $alpha = $namespace . '\AlphaReport';

        self::assertTrue(is_subclass_of($alpha, $namespace . '\Report'));

        $built = $board::create(byId: ['k' => $detail::create(id: 2)], meta: $alpha::create(report: ($namespace . '\ReportReport')::from('alpha'), a: 1));
        self::assertSame(['byId' => ['k' => ['id' => 2]], 'meta' => ['report' => 'alpha', 'a' => 1]], $built->getData());
        self::assertTrue((new Yii3Container())->validate($built)->isValid());
        // A map value was checked by nothing at all — hydrated or built.
        $container = new Yii3Container();
        self::assertFalse($container->validate($container->hydrate($board, ['byId' => ['k' => ['id' => 0]]]))->isValid());
        self::assertFalse($container->validate($board::create(byId: ['k' => $detail::create(id: 0)]))->isValid());
    }

    public function testAMapOfObjectsIsBuiltTheWayTheReadmeSays(): void
    {
        // No attribute builds a map — `#[Collection]` renumbers the keys — so README.yii3 has the reader
        // build the values with the same hydrator. This is that recipe, run.
        $namespace = $this->generate([
            'Board' => [
                'type' => 'object',
                'properties' => [
                    'byId' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Detail']],
                ],
            ],
            'Detail' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        ]);
        $detail = $namespace . '\Detail';
        $container = new Yii3Container();
        $hydrator = $container->hydrator();
        $input = $container->hydrate($namespace . '\Board', ['byId' => ['first' => ['id' => 1], 'second' => ['id' => 2]]]);

        $byId = array_map(
            static fn(object|array $item): object => $item instanceof $detail ? $item : $hydrator->create($detail, $item),
            $input->getById() ?? [],
        );

        self::assertSame(['first', 'second'], array_keys($byId));
        self::assertContainsOnlyInstancesOf($detail, $byId);
        self::assertSame(2, $byId['second']->getId());
    }

    public function testANullableReferenceInsideAUnionMemberTakesNull(): void
    {
        // OpenAPI 3.0 writes a nullable reference as `nullable: true` beside `allOf: [$ref]`. A union is
        // checked as an array against its member's schema, and there the `nullable` was lost: a member
        // built with a null person failed "must be of type object".
        $namespace = $this->generate([
            'Shelf' => [
                'type' => 'object',
                'required' => ['folders'],
                'properties' => [
                    'folders' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Folder']],
                ],
            ],
            'Folder' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['plain' => '#/components/schemas/PlainFolder']],
            ],
            'PlainFolder' => ['allOf' => [
                ['$ref' => '#/components/schemas/Folder'],
                [
                    'type' => 'object',
                    'required' => ['owner'],
                    'properties' => [
                        'owner' => ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Person']]],
                    ],
                ],
            ]],
            'Person' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        ], openApiVersion: '3.0.3');
        $shelf = $namespace . '\Shelf';
        $plain = $namespace . '\PlainFolder';
        $kind = ($namespace . '\FolderKind');
        $container = new Yii3Container();

        $folder = $plain::create(kind: ($namespace . '\FolderKind')::from('plain'), owner: null);
        self::assertSame([], $this->messages($container->validate($shelf::create(folders: [$folder]))));
        self::assertSame([], $this->messages($container->validate($container->hydrate($shelf, ['folders' => [['kind' => 'plain', 'owner' => null]]]))));
        // …and the reference is still checked when it is there.
        self::assertNotSame([], $this->messages($container->validate($container->hydrate($shelf, ['folders' => [['kind' => 'plain', 'owner' => []]]]))));
    }

    public function testAUnionInterfaceDeclaresTheGettersItsMembersShare(): void
    {
        // Code typed by the interface could only reach a member's value through `getPropertyValue()`.
        $namespace = $this->generate([
            'Event' => [
                'type' => 'object',
                'required' => ['uuid', 'kind'],
                'properties' => [
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'kind' => ['type' => 'string', 'enum' => ['ping', 'pong']],
                    'note' => ['type' => 'string'],
                ],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                    'ping' => '#/components/schemas/PingEvent',
                    'pong' => '#/components/schemas/PongEvent',
                ]],
            ],
            'PingEvent' => ['allOf' => [
                ['$ref' => '#/components/schemas/Event'],
                ['type' => 'object', 'properties' => ['delay' => ['type' => 'integer']]],
            ]],
            'PongEvent' => ['allOf' => [
                ['$ref' => '#/components/schemas/Event'],
                ['type' => 'object', 'properties' => ['delay' => ['type' => 'string']]],
            ]],
        ]);
        $event = new ReflectionClass($namespace . '\Event');
        $kind = $namespace . '\EventKind';

        $methods = array_map(static fn(ReflectionMethod $method): string => $method->getName(), $event->getMethods());
        self::assertContains('getUuid', $methods);
        self::assertContains('getKind', $methods);
        self::assertContains('getNote', $methods);
        // `delay` is an integer in one member and a string in the other: no signature fits both.
        self::assertNotContains('getDelay', $methods);

        $ping = ($namespace . '\PingEvent')::create(uuid: 'b7c4f0e2-6a1d-4c3e-9f2a-1d2e3f4a5b6c', kind: $kind::from('ping'));
        self::assertInstanceOf($event->getName(), $ping);
        self::assertSame('b7c4f0e2-6a1d-4c3e-9f2a-1d2e3f4a5b6c', $ping->getUuid());
    }

    public function testANullableReferenceToAUnionTakesNull(): void
    {
        $namespace = $this->generate([
            'Settings' => [
                'type' => 'object',
                'required' => ['edge'],
                'properties' => [
                    'edge' => ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Edge']]],
                ],
            ],
            'Edge' => [
                'oneOf' => [['$ref' => '#/components/schemas/SharpEdge'], ['$ref' => '#/components/schemas/RoundEdge']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                    'sharp' => '#/components/schemas/SharpEdge',
                    'round' => '#/components/schemas/RoundEdge',
                ]],
            ],
            'SharpEdge' => ['type' => 'object', 'required' => ['kind', 'angle'], 'properties' => ['kind' => ['type' => 'string'], 'angle' => ['type' => 'integer']]],
            'RoundEdge' => ['type' => 'object', 'required' => ['kind', 'radius'], 'properties' => ['kind' => ['type' => 'string'], 'radius' => ['type' => 'integer']]],
        ], openApiVersion: '3.0.3');
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($namespace . '\Settings', $payload));

        self::assertSame([], $verdict(['edge' => null]));
        self::assertSame([], $verdict(['edge' => ['kind' => 'sharp', 'angle' => 30]]));
        self::assertNotSame([], $verdict(['edge' => ['kind' => 'sharp']]));
        self::assertArrayHasKey('edge', $verdict([]));
    }

    public function testAValueTypedByAUnionIsReadAndChecked(): void
    {
        // A union is an interface, which the hydrator cannot build: a request carrying one was refused
        // as missing it. It holds the decoded array, and the interpreter picks the branch.
        $namespace = $this->generate([
            'Board' => [
                'type' => 'object',
                'required' => ['meta'],
                'properties' => [
                    'meta' => ['$ref' => '#/components/schemas/Report'],
                    'shapes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']],
                ],
            ],
            'Report' => [
                'type' => 'object',
                'required' => ['report'],
                'properties' => ['report' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'report', 'mapping' => [
                    'alpha' => '#/components/schemas/AlphaReport',
                    'beta' => '#/components/schemas/BetaReport',
                ]],
            ],
            'AlphaReport' => ['allOf' => [
                ['$ref' => '#/components/schemas/Report'],
                ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]],
            ]],
            'BetaReport' => ['allOf' => [
                ['$ref' => '#/components/schemas/Report'],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ]],
            'Shape' => ['oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']]],
            'Circle' => ['type' => 'object', 'required' => ['radius'], 'properties' => ['radius' => ['type' => 'number']], 'additionalProperties' => false],
            'Square' => ['type' => 'object', 'required' => ['side'], 'properties' => ['side' => ['type' => 'number']], 'additionalProperties' => false],
        ]);
        $board = $namespace . '\Board';
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($board, $payload));

        self::assertSame([], $verdict(['meta' => ['report' => 'alpha', 'a' => 1], 'shapes' => [['radius' => 1], ['side' => 2]]]));
        self::assertSame([], $verdict(['meta' => ['report' => 'beta']]));
        self::assertNotSame([], $verdict(['meta' => ['report' => 'alpha']]), 'the branch is checked');
        self::assertNotSame([], $verdict(['meta' => ['report' => 'alpha', 'a' => 'x']]));
        self::assertNotSame([], $verdict(['meta' => ['report' => 'gamma']]));
        self::assertNotSame([], $verdict(['meta' => ['report' => 'beta'], 'shapes' => [['edge' => 1]]]));

        $container = new Yii3Container();
        $read = $container->hydrate($board, ['meta' => ['report' => 'alpha', 'a' => 1]]);
        self::assertSame(['report' => 'alpha', 'a' => 1], $read->getMeta());

        $alpha = $namespace . '\AlphaReport';
        $built = $board::create(meta: $alpha::create(report: ($namespace . '\ReportReport')::from('alpha'), a: 1));
        self::assertSame(['meta' => ['report' => 'alpha', 'a' => 1]], $built->getData());
        self::assertTrue($container->validate($built)->isValid());
    }

    public function testAnEnumBesideAScalarKeepsTheValueAndReadsAsTheCase(): void
    {
        // `Scope|int`: the hydrator found no builtin member for `"all"` and cast it — `"all"` and `"nope"`
        // both became 0, and passed. A listed value read back as the string, never as the case.
        $namespace = $this->generate([
            'Filter' => [
                'type' => 'object',
                'properties' => ['scope' => ['oneOf' => [['$ref' => '#/components/schemas/Scope'], ['type' => 'integer']]]],
            ],
            'Scope' => ['type' => 'string', 'enum' => ['all', 'team']],
        ]);
        $filter = $namespace . '\Filter';
        $scope = $namespace . '\Scope';
        $container = new Yii3Container();
        $read = static fn(array $payload): mixed => $container->hydrate($filter, $payload)->getScope();
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($filter, $payload));

        self::assertSame($scope::from('all'), $read(['scope' => 'all']));
        self::assertSame(5, $read(['scope' => 5]));
        self::assertSame([], $verdict(['scope' => 'team']));
        self::assertSame([], $verdict(['scope' => 5]));
        self::assertArrayHasKey('scope', $verdict(['scope' => 'nope']));
        self::assertArrayHasKey('scope', $verdict(['scope' => '5']));

        $built = $filter::create(scope: $scope::from('team'));
        self::assertSame(['scope' => 'team'], $built->getData());
        self::assertTrue($container->validate($built)->isValid());
    }

    public function testADiscriminatedUnionNamesItsMembers(): void
    {
        // A body that IS a union gave a caller nothing to pick the member by: the interface was empty.
        $namespace = $this->generate([
            'Ident' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                    'city' => '#/components/schemas/CityIdent',
                    'region' => '#/components/schemas/RegionIdent',
                ]],
            ],
            'CityIdent' => ['allOf' => [['$ref' => '#/components/schemas/Ident'], ['type' => 'object', 'properties' => ['cityId' => ['type' => 'integer']]]]],
            'RegionIdent' => ['allOf' => [['$ref' => '#/components/schemas/Ident'], ['type' => 'object', 'properties' => ['regionId' => ['type' => 'integer']]]]],
        ]);
        $ident = $namespace . '\Ident';

        self::assertSame('kind', constant($ident . '::OPENAPI_DISCRIMINATOR'));
        // Every member is a data set; code taking one by that contract refused the union type.
        self::assertTrue(is_subclass_of($ident, \Yiisoft\Validator\DataSetInterface::class));
        self::assertTrue(is_subclass_of($ident, \Yiisoft\Validator\RulesProviderInterface::class));
        self::assertSame(
            ['city' => $namespace . '\CityIdent', 'region' => $namespace . '\RegionIdent'],
            constant($ident . '::OPENAPI_DISCRIMINATOR_MAPPING'),
        );
    }

    public function testARequiredValueSentAsNullIsRefused(): void
    {
        // The cast turned the null into '' and 0 before any rule saw it, and every rule skipped null:
        // `{"name": null}` created a record with an empty name.
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['name', 'count', 'note'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                    'note' => ['type' => 'string', 'nullable' => true],
                    'label' => ['type' => 'string'],
                ],
            ],
        ]);
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($namespace . '\Probe', $payload));

        self::assertSame([], $verdict(['name' => 'a', 'count' => 1, 'note' => null]));
        self::assertArrayHasKey('name', $verdict(['name' => null, 'count' => 1, 'note' => 'n']));
        self::assertArrayHasKey('count', $verdict(['name' => 'a', 'count' => null, 'note' => 'n']));
        self::assertArrayHasKey('label', $verdict(['name' => 'a', 'count' => 1, 'note' => 'n', 'label' => null]));
        // A missing key is still reported once, by the interpreter.
        self::assertSame(['name' => 'field "name" is required.'], $verdict(['count' => 1, 'note' => 'n']));
    }

    public function testAnEnumValueOutsideTheListIsRefused(): void
    {
        // `EnumTypeCaster` cannot cast the value, so the property is skipped and stays uninitialised —
        // which for an OPTIONAL property reads as "not sent": `?shade=nonsense` answered as if the
        // filter were absent.
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['size'],
                'properties' => [
                    'size' => ['$ref' => '#/components/schemas/Size'],
                    'shade' => ['$ref' => '#/components/schemas/Shade'],
                    'level' => ['$ref' => '#/components/schemas/Level'],
                ],
            ],
            'Size' => ['type' => 'string', 'enum' => ['small', 'large']],
            'Shade' => ['type' => 'string', 'enum' => ['crimson', 'azure']],
            'Level' => ['type' => 'integer', 'enum' => [0, 1]],
        ]);
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($namespace . '\Probe', $payload));

        self::assertSame([], $verdict(['size' => 'small', 'shade' => 'azure', 'level' => 1]));
        self::assertSame([], $verdict(['size' => 'small']));
        self::assertArrayHasKey('shade', $verdict(['size' => 'small', 'shade' => 'nonsense']));
        self::assertArrayHasKey('level', $verdict(['size' => 'small', 'level' => 7]));
        // A body is JSON: the string "1" is not the integer the schema lists.
        self::assertArrayHasKey('level', $verdict(['size' => 'small', 'level' => '1']));
        self::assertArrayHasKey('size', $verdict(['size' => 'medium']));
    }

    public function testADateThatDoesNotParseIsRefused(): void
    {
        // `#[ToDateTime]` that parses nothing leaves the property uninitialised — for an OPTIONAL one that
        // reads as "not sent", and `{"begin": "nonsense"}` answered as if there were no filter.
        $namespace = $this->generate([
            'Range' => [
                'type' => 'object',
                'required' => ['at'],
                'properties' => [
                    'at' => ['type' => 'string', 'format' => 'date-time'],
                    'begin' => ['type' => 'string', 'format' => 'date'],
                    'opens' => ['type' => 'string', 'format' => 'time'],
                ],
            ],
        ]);
        $verdict = fn(array $payload): array => $this->messages((new Yii3Container())->hydrateValidated($namespace . '\Range', $payload));

        self::assertSame([], $verdict(['at' => '2026-03-10T08:00:00+00:00', 'begin' => '2026-03-10', 'opens' => '08:30:00+00:00']));
        self::assertSame([], $verdict(['at' => '2026-03-10T08:00:00.250000+00:00']));
        self::assertArrayHasKey('begin', $verdict(['at' => '2026-03-10T08:00:00+00:00', 'begin' => 'nonsense']));
        self::assertArrayHasKey('opens', $verdict(['at' => '2026-03-10T08:00:00+00:00', 'opens' => 'noon']));
        self::assertArrayHasKey('at', $verdict(['at' => 'tomorrow']));
    }

    public function testARawCheckInANestedObjectKeepsItsPathWithTheReadmeRecipe(): void
    {
        // `ValidateResolver` writes only the property name: `{"range": {"begin": "nonsense"}}` came back
        // as `begin`, the same path a top-level `begin` would have.
        $namespace = $this->generate([
            'Filter' => [
                'type' => 'object',
                'properties' => [
                    'range' => ['$ref' => '#/components/schemas/Range'],
                    'begin' => ['type' => 'string', 'format' => 'date'],
                    'ranges' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Range']],
                ],
            ],
            'Range' => [
                'type' => 'object',
                'properties' => [
                    'begin' => ['type' => 'string', 'format' => 'date'],
                    'inner' => ['$ref' => '#/components/schemas/Range'],
                ],
            ],
        ]);
        $verdict = fn(array $payload, bool $nestedPaths): array => $this->messages(
            (new Yii3Container())->hydrateValidated(class: $namespace . '\Filter', payload: $payload, nestedPaths: $nestedPaths),
        );

        self::assertSame(['begin'], array_keys($verdict(['range' => ['begin' => 'nonsense']], nestedPaths: false)));
        self::assertSame(['range.begin'], array_keys($verdict(['range' => ['begin' => 'nonsense']], nestedPaths: true)));
        self::assertSame(['range.inner.begin'], array_keys($verdict(['range' => ['inner' => ['begin' => 'nonsense']]], nestedPaths: true)));
        self::assertSame(['begin'], array_keys($verdict(['begin' => 'nonsense'], nestedPaths: true)));
        self::assertSame([], $verdict(['range' => ['begin' => '2026-03-10'], 'begin' => '2026-03-10'], nestedPaths: true));
        // What the README says the recipe does NOT reach: `#[Collection]` builds items without a caster.
        self::assertSame(['begin'], array_keys($verdict(['ranges' => [['begin' => 'nonsense']]], nestedPaths: true)));
    }

    public function testAQueryEnumValueOutsideTheListIsRefused(): void
    {
        $namespace = $this->generateSpec([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/widgets' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'withAll', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                        ],
                        'responses' => ['204' => ['description' => 'none']],
                    ],
                ],
            ],
        ]);
        $class = null;
        foreach ((array)glob($this->outputDirectory . '/' . $namespace . '/*QueryParams.php') as $file) {
            if (is_string($file)) {
                $class = $namespace . '\\' . basename($file, '.php');
            }
        }
        self::assertNotNull($class, 'the operation must produce a parameters class');
        $verdict = fn(array $query): array => $this->messages(
            (new Yii3Container())->hydrateValidated(class: $class, payload: [], method: 'GET', query: $query),
        );

        // A query string carries text, so "1" is the integer 1.
        self::assertSame([], $verdict(['withAll' => '1']));
        self::assertSame([], $verdict([]));
        self::assertArrayHasKey('withAll', $verdict(['withAll' => 'nonsense']));
        self::assertArrayHasKey('withAll', $verdict(['withAll' => '7']));
    }

    public function testARequiredValueWithADefaultMayBeLeftOut(): void
    {
        // The default wins, as in every other mode: a body without `perPage` was refused as missing it.
        $namespace = $this->generate([
            'Paging' => [
                'type' => 'object',
                'required' => ['page', 'perPage'],
                'properties' => [
                    'page' => ['type' => 'integer', 'default' => 1],
                    'perPage' => ['type' => 'integer', 'default' => 50],
                ],
            ],
        ]);
        $container = new Yii3Container();

        self::assertSame([], $this->messages($container->hydrateValidated($namespace . '\Paging', ['page' => 2])));
        $paging = $container->hydrate($namespace . '\Paging', ['page' => 2]);
        self::assertSame(2, $paging->getPage());
        self::assertSame(50, $paging->getPerPage());
        self::assertArrayHasKey('perPage', $this->messages($container->hydrateValidated($namespace . '\Paging', ['perPage' => 'many'])));
    }

    public function testJsonEncodeWritesTheWireForm(): void
    {
        // A list of these handed to `json_encode()` came out as PHP objects: a date with its timezone
        // type, an enum as `{}`.
        $namespace = $this->generate([
            'Note' => [
                'type' => 'object',
                'required' => ['at', 'tone'],
                'properties' => [
                    'at' => ['type' => 'string', 'format' => 'date'],
                    'tone' => ['type' => 'string', 'enum' => ['calm', 'loud']],
                ],
            ],
            'Blank' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
        ]);
        $note = (new Yii3Container())->hydrate($namespace . '\Note', ['at' => '2026-03-10', 'tone' => 'calm']);
        $blank = (new Yii3Container())->hydrate($namespace . '\Blank', []);

        self::assertSame('[{"at":"2026-03-10","tone":"calm"},{}]', json_encode([$note, $blank]));
    }

    public function testKeysAreWrittenInTheSchemasOrder(): void
    {
        // The properties were declared required-first, and `getData()` follows the declaration.
        $namespace = $this->generate([
            'Envelope' => [
                'type' => 'object',
                'required' => ['success', 'payload'],
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'payload' => ['type' => 'string'],
                ],
            ],
        ]);

        $envelope = (new Yii3Container())->hydrate($namespace . '\Envelope', ['payload' => 'p', 'message' => 'm', 'success' => true]);

        self::assertSame(['success', 'message', 'payload'], array_keys($envelope->getData()));
    }

    public function testAnAbsentValueReadsAsTheSchemaDefault(): void
    {
        $namespace = $this->generate([
            'Filter' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['$ref' => '#/components/schemas/Kind'],
                    'size' => ['type' => 'integer', 'default' => 10],
                ],
            ],
            'Kind' => ['type' => 'string', 'enum' => ['round', 'square'], 'default' => 'square'],
        ]);
        $container = new Yii3Container();

        $absent = $container->hydrate($namespace . '\Filter', []);
        self::assertSame('int', (string)(new ReflectionMethod($namespace . '\Filter', 'getSize'))->getReturnType(), 'A default makes the getter total.');
        self::assertSame('square', $absent->getKind()?->value);
        self::assertSame(10, $absent->getSize());
        self::assertFalse($absent->isSizeProvided());
        self::assertSame([], $absent->getData(), 'The default is read, not written: absent stays absent.');

        $sent = $container->hydrate($namespace . '\Filter', ['kind' => 'round', 'size' => 3]);
        self::assertSame('round', $sent->getKind()?->value);
        self::assertSame(3, $sent->getSize());
    }

    public function testAListOfEnumMembersReadsAsCases(): void
    {
        // The list holds the backing values — `#[Collection]` drops a member the enum lacks before a rule
        // can report it — and the getter handed those strings out where every other mode gives cases.
        $namespace = $this->generate([
            'Filter' => [
                'type' => 'object',
                'required' => ['kinds'],
                'properties' => [
                    'kinds' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Kind']],
                    'byName' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Kind']],
                ],
            ],
            'Kind' => ['type' => 'string', 'enum' => ['round', 'square']],
        ]);
        $container = new Yii3Container();
        $kind = $namespace . '\Kind';

        $filter = $container->hydrate($namespace . '\Filter', ['kinds' => ['round', 'square'], 'byName' => ['a' => 'round']]);
        self::assertTrue($container->validate($filter)->isValid());
        self::assertSame([$kind::from('round'), $kind::from('square')], $filter->getKinds());
        self::assertSame(['a' => $kind::from('round')], $filter->getByName());

        self::assertFalse($container->validate($container->hydrate($namespace . '\Filter', ['kinds' => ['oval']]))->isValid());

        $built = $namespace . '\Filter';
        self::assertSame(['kinds' => ['square']], $built::create(kinds: [$kind::from('square')])->getData());
    }

    public function testARequiredNameWithoutAPropertyIsNotDemanded(): void
    {
        // The generator warns that such a requirement is enforced nowhere, as the other modes do; this
        // one demanded it of every object, which can never carry it — a correct response failed.
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['header', 'scores'],
                'properties' => ['header' => ['type' => 'string']],
            ],
        ]);

        $container = new Yii3Container();

        self::assertTrue($container->validate($container->hydrate($namespace . '\Probe', ['header' => 'h']))->isValid());
        self::assertArrayHasKey('header', $this->messages($container->validate($container->hydrate($namespace . '\Probe', []))));
    }

    public function testAnEmptyValueStillBreaksABoundThatForbidsIt(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['name', 'tags'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $messages = $this->messages($container->validate(
            $container->hydrate($namespace . '\Probe', ['name' => '', 'tags' => []]),
        ));

        self::assertArrayHasKey('name', $messages);
        self::assertArrayHasKey('tags', $messages);
    }

    /**
     * The data-set contract the class implements, exercised directly — it is what the validator reads
     * and what an application reads for PATCH, so it is API, not an internal.
     */
    public function testTheDataSetContractAnswersForPresentAndAbsentProperties(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $dto = $container->hydrate($namespace . '\Probe', ['id' => 7]);

        self::assertTrue($dto->hasProperty('id'));
        self::assertFalse($dto->hasProperty('name'));
        self::assertFalse($dto->hasProperty('nothingLikeThis'));

        self::assertSame(7, $dto->getPropertyValue('id'));
        self::assertNull($dto->getPropertyValue('name'), 'an absent property reads as null, never as a sentinel');

        self::assertSame(['id' => 7], $dto->getData(), 'an absent property must not appear in the data');

        // Without RulesProviderInterface the validator skips attribute parsing entirely, so an empty
        // rule set here would mean nothing is validated at all.
        self::assertNotSame([], iterator_to_array($dto->getRules()));
    }

    /**
     * A discriminated union: the member implements the emitted interface, so a property typed by the
     * union can actually hold it.
     */
    public function testAUnionMemberImplementsTheUnionInterface(): void
    {
        $namespace = $this->generate([
            'Dog' => [
                'type' => 'object',
                'required' => ['petType', 'bark'],
                'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
            ],
            'Cat' => [
                'type' => 'object',
                'required' => ['petType', 'meow'],
                'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']],
            ],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Dog'], ['$ref' => '#/components/schemas/Cat']],
                'discriminator' => [
                    'propertyName' => 'petType',
                    'mapping' => ['dog' => '#/components/schemas/Dog', 'cat' => '#/components/schemas/Cat'],
                ],
            ],
        ]);

        self::assertTrue(interface_exists($namespace . '\Pet'));
        self::assertContains($namespace . '\Pet', class_implements($namespace . '\Dog') ?: []);
        self::assertContains($namespace . '\Pet', class_implements($namespace . '\Cat') ?: []);

        // The union interface must not drag symfony/serializer into a yii3-mode file.
        $source = (string)file_get_contents((new ReflectionClass($namespace . '\Pet'))->getFileName() ?: '');
        self::assertStringNotContainsString('Symfony\\', $source);
    }

    /**
     * The key order a union MEMBER comes back in: the discriminator first, as in every mode but
     * laravel-data.
     *
     * The support matrix states this for yii3 and cited the normalization parity case for it — where
     * yii3 comes back EMPTY, because a property typed by the union interface is one the hydrator
     * cannot fill (see `testAUnionMemberImplementsTheUnionInterface` and the row above it in that
     * table). So the cell was true and its evidence showed something else. The member has to be
     * driven directly, which is also the way an application uses it here: build or hydrate the member,
     * and the union interface is the type its response is declared with.
     */
    public function testAUnionMemberReadsBackWithTheDiscriminatorFirst(): void
    {
        $namespace = $this->generate([
            'Dog' => [
                'type' => 'object',
                'required' => ['petType', 'bark'],
                'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
            ],
            'Cat' => [
                'type' => 'object',
                'required' => ['petType', 'meow'],
                'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']],
            ],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Dog'], ['$ref' => '#/components/schemas/Cat']],
                'discriminator' => [
                    'propertyName' => 'petType',
                    'mapping' => ['dog' => '#/components/schemas/Dog', 'cat' => '#/components/schemas/Cat'],
                ],
            ],
        ]);

        $member = (new Yii3Container())->hydrate(
            $namespace . '\Dog',
            ['petType' => 'dog', 'bark' => 'woof'],
        );

        // assertSame on the ARRAY is what sees the order; the keys and values alone would pass either
        // way, and JSON object order carries no meaning to a client.
        self::assertSame(['petType' => 'dog', 'bark' => 'woof'], $member->getData());
    }

    /**
     * A property with NO `type` in its schema takes any JSON value, and every one of them must
     * survive hydration.
     *
     * The obvious PHP type for it is `mixed`, and `yiisoft/hydrator` refuses to fill a `mixed`
     * property at all — it skipped it and DROPPED the value, which in this mode reads as "the client
     * did not send this key". The union spells the same set out and hydrates; it also keeps the
     * property typed, which is what makes `isInitialized()` a presence answer.
     */
    public function testAFreeFormPropertyKeepsEveryJsonValueItIsSent(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['s'],
                'properties' => [
                    's' => ['type' => 'string'],
                    'any' => ['description' => 'anything'],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $class = $namespace . '\Probe';

        self::assertSame(
            'array|string|int|float|bool|null',
            (string)(new ReflectionProperty($class, 'any'))->getType(),
            'a free-form property must not be typed `mixed`: the hydrator will not fill one',
        );

        foreach (['text', 42, 1.5, true, ['k' => 1], null] as $value) {
            $dto = $container->hydrate($class, ['s' => 'a', 'any' => $value]);

            self::assertTrue(
                $dto->isAnyProvided(),
                sprintf('%s was dropped by the hydrator', json_encode($value)),
            );
            self::assertSame(['s' => 'a', 'any' => $value], $dto->getData());
            self::assertTrue($container->validate($dto)->isValid());
        }

        // …and the mirror, so the assertions above cannot be read as "presence is always true".
        $absent = $container->hydrate($class, ['s' => 'a']);
        self::assertFalse($absent->isAnyProvided());
        self::assertSame(['s' => 'a'], $absent->getData());
    }

    /**
     * Nothing but the DTOs is generated: no sentinel type, no marker class, no helper of ours.
     */
    public function testNoHelperTypesAreGeneratedBesideTheDtos(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
            ],
        ]);

        $files = array_map(
            'basename',
            (array)glob($this->outputDirectory . '/' . $namespace . '/*.php'),
        );

        self::assertSame(['Probe.php'], $files);
    }
}
