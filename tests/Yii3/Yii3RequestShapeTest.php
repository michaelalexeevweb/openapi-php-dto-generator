<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
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
