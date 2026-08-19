<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;

/**
 * yii3-mode output driven through the REAL `yiisoft/hydrator` and `yiisoft/validator`.
 *
 * Source assertions are deliberately absent: every mode-level bug found in this package so far was
 * found by running the emitted code against the framework, never by reading it. In this mode alone the
 * first such run produced four — `?array<Tag>` was unparsable PHP, `#[Each]` never fired, rules ran
 * against a null optional property, and `#[UniqueIterable]` silently refused to compare objects.
 */
final class GeneratedYii3InputTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/opg-yii3-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->outputDirectory)) {
            return;
        }

        foreach ((array)glob($this->outputDirectory . '/*/*.php') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        foreach ((array)glob($this->outputDirectory . '/*', GLOB_ONLYDIR) as $directory) {
            if (is_string($directory)) {
                rmdir($directory);
            }
        }
        rmdir($this->outputDirectory);
    }

    public function testHydratesAndValidatesThroughTheFramework(): void
    {
        $namespace = $this->generate([
            'Post' => [
                'type' => 'object',
                'required' => ['title', 'email'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'age' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMaximum' => 150],
                ],
            ],
        ]);

        $container = new Yii3Container();

        /** @var object $valid */
        $valid = $container->hydrate($namespace . '\Post', [
            'title' => 'Hello there',
            'email' => 'a@b.co',
            'age' => 30,
        ]);
        self::assertSame('Hello there', $valid->getTitle());
        self::assertSame(30, $valid->getAge());
        self::assertTrue($container->validate($valid)->isValid());

        $invalid = $container->hydrate($namespace . '\Post', [
            'title' => 'ab',
            'email' => 'nope',
            'age' => 200,
        ]);
        $messages = $this->messages($container->validate($invalid));
        self::assertCount(3, $messages, implode(' | ', $messages));
        self::assertStringContainsString('at least 3 characters', $messages['title']);
        self::assertStringContainsString('not a valid email', $messages['email']);
        self::assertStringContainsString('less than', $messages['age']);
    }

    public function testAbsentOptionalIsDistinguishableFromAnExplicitNull(): void
    {
        // The whole point of presence tracking, and the one thing the hydrator gives no help with:
        // its public API says nothing about which keys arrived, so the emitted constructor records it.
        $namespace = $this->generate([
            'Patch' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string', 'minLength' => 3],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $class = $namespace . '\Patch';

        /** @var object $absent */
        $absent = $container->hydrate($class, ['id' => 1]);
        self::assertFalse($absent->isNameProvided());
        self::assertNull($absent->getName());

        /** @var object $explicitNull */
        $explicitNull = $container->hydrate($class, ['id' => 1, 'name' => null]);
        self::assertTrue($explicitNull->isNameProvided());
        self::assertNull($explicitNull->getName());

        /** @var object $sent */
        $sent = $container->hydrate($class, ['id' => 1, 'name' => 'Alice']);
        self::assertTrue($sent->isNameProvided());
        self::assertSame('Alice', $sent->getName());

        // An absent optional must not be validated: every rule would otherwise fire against its null.
        self::assertTrue($container->validate($absent)->isValid());

        // An explicit null is a DIFFERENT thing, and this schema does not carry `nullable: true`, so
        // it is rejected — presence and permissibility are separate questions. (This assertion used to
        // read the other way and was encoding a bug: optional is not nullable.)
        self::assertFalse($container->validate($explicitNull)->isValid());

        // …and a value that IS there is validated normally.
        self::assertFalse($container->validate($container->hydrate($class, ['id' => 1, 'name' => 'ab']))->isValid());
    }

    /**
     * A REQUIRED property the payload did not carry — the case that has no constructor to catch it.
     *
     * With no constructor the hydrator simply leaves the property uninitialised, so nothing throws at
     * build time and the verdict is the interpreter's to give. It only works because the payload view
     * guards EVERY property with `hasProperty()`: reading an uninitialised typed property throws
     * `must not be accessed before initialization`, and an unguarded read turned this into an uncaught
     * PHP Error on all six shapes below instead of a validation message.
     */
    public function testAMissingRequiredPropertyIsReportedByValidationOnEveryShape(): void
    {
        $shapes = [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'free-form object' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'nested DTO' => ['$ref' => '#/components/schemas/Tag'],
            'enum' => ['$ref' => '#/components/schemas/Stage'],
        ];

        foreach ($shapes as $label => $schema) {
            $namespace = $this->generate([
                'Tag' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string']],
                ],
                'Stage' => ['type' => 'string', 'enum' => ['a', 'b']],
                'Probe' => [
                    'type' => 'object',
                    'required' => ['keep', 'f'],
                    'properties' => ['keep' => ['type' => 'string'], 'f' => $schema],
                ],
            ]);

            $container = new Yii3Container();
            $dto = $container->hydrate($namespace . '\Probe', ['keep' => 'x']);

            self::assertFalse($dto->hasProperty('f'), sprintf('%s: an unsent property must read as absent.', $label));

            $result = $container->validate($dto);
            self::assertFalse($result->isValid(), sprintf('%s: a missing required property must be rejected.', $label));
            self::assertStringContainsString(
                'is required',
                implode(' ', $this->messages($result)),
                sprintf('%s: and the message must say so.', $label),
            );
        }
    }

    /**
     * PATCH with nothing but the required key: every optional is absent, and none of them may be
     * validated or reported.
     */
    public function testAPatchCarryingOnlyRequiredKeysValidates(): void
    {
        $namespace = $this->generate([
            'Patch' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string', 'minLength' => 3],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                    'meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $dto = $container->hydrate($namespace . '\Patch', ['id' => 1]);

        self::assertTrue($container->validate($dto)->isValid(), 'An absent optional must not be validated.');
        foreach (['name', 'tags', 'meta'] as $absent) {
            self::assertFalse($dto->hasProperty($absent));
        }
        self::assertTrue($dto->hasProperty('id'));
    }

    public function testNestedRulesCascadeWithoutBeingRepeated(): void
    {
        // A bare `#[Nested]` reads the nested class's own attributes, and `#[Each(new Nested())]` does
        // it per element — which is why recursive schemas cost nothing here, unlike in Laravel mode.
        $namespace = $this->generate([
            'Tag' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string', 'minLength' => 3]],
            ],
            'Post' => [
                'type' => 'object',
                'required' => ['tag', 'tags'],
                'properties' => [
                    'tag' => ['$ref' => '#/components/schemas/Tag'],
                    'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $tag = static fn(string $name): array => ['name' => $name];

        $valid = $container->hydrate($namespace . '\Post', [
            'tag' => $tag('alpha'),
            'tags' => [$tag('beta'), $tag('gamma')],
        ]);
        self::assertTrue($container->validate($valid)->isValid());

        $invalid = $container->hydrate($namespace . '\Post', [
            'tag' => $tag('xy'),
            'tags' => [$tag('ok!'), $tag('no')],
        ]);
        $paths = array_keys($this->messages($container->validate($invalid)));
        self::assertContains('tag.name', $paths);
        self::assertContains('tags.1.name', $paths, 'The list element index must survive into the path.');
    }

    public function testInterpreterEnforcesWhatNoNativeRuleCan(): void
    {
        // `not` has no rule in yiisoft/validator, so it reaches the emitted interpreter — entered once
        // per object by the class-level #[Callback], which is the Symfony packaging.
        $namespace = $this->generate([
            'Order' => [
                'type' => 'object',
                'required' => ['code'],
                'properties' => ['code' => ['type' => 'string', 'not' => ['const' => 'forbidden']]],
            ],
        ]);

        $container = new Yii3Container();

        self::assertTrue($container->validate($container->hydrate($namespace . '\Order', ['code' => 'fine']))->isValid());

        $result = $container->validate($container->hydrate($namespace . '\Order', ['code' => 'forbidden']));
        self::assertFalse($result->isValid());
        self::assertStringContainsString("must not match the 'not' schema", implode(' ', $this->messages($result)));
    }

    public function testEnumPropertyNeedsTheEnumTypeCaster(): void
    {
        // The mode's one real setup requirement. Without `EnumTypeCaster` the string never becomes the
        // generated backed enum, and because the emitted class has no constructor the property is
        // simply never initialised: the object builds, `hasProperty()` reports the value as absent,
        // and reading it throws. An application that misses this sees no mention of enums anywhere.
        $namespace = $this->generate([
            'Stage' => ['type' => 'string', 'enum' => ['early', 'late']],
            'Job' => [
                'type' => 'object',
                'required' => ['stage'],
                'properties' => ['stage' => ['$ref' => '#/components/schemas/Stage']],
            ],
        ]);
        $class = $namespace . '\Job';

        $wired = new Yii3Container();
        /** @var object $job */
        $job = $wired->hydrate($class, ['stage' => 'early']);
        self::assertSame('early', $job->getStage()->value);
        self::assertTrue($job->hasProperty('stage'));

        // The default caster set, which is what `new Hydrator()` gives an application.
        $bare = (new \Yiisoft\Hydrator\Hydrator(
            attributeResolverFactory: new \Yiisoft\Hydrator\AttributeHandling\ResolverFactory\ContainerAttributeResolverFactory(
                new Yii3Container(),
            ),
        ))->create($class, ['stage' => 'early']);

        self::assertFalse($bare->hasProperty('stage'), 'Without EnumTypeCaster the property is never filled.');

        $this->expectExceptionMessageMatches('/must not be accessed before initialization/');
        $bare->getStage();
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private function generate(array $schemas): string
    {
        // A FRESH directory per call. `require_once` keys on the path, so generating a second spec
        // over the same files would silently keep the first namespace's classes loaded and the new
        // ones would never exist — which is exactly how a multi-shape test fails with
        // `NonExistClassException` while looking like a generator bug.
        $namespace = 'Yii3Gen' . bin2hex(random_bytes(5));
        $directory = $this->outputDirectory . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => $schemas],
            ],
            $directory,
            $namespace,
            GenerateDtoCommand::ATTRIBUTE_MODE_YII3,
        );

        foreach ((array)glob($directory . '/*.php') as $file) {
            if (is_string($file)) {
                require_once $file;
            }
        }

        return $namespace;
    }

    /**
     * @return array<string, string> error message keyed by its value path
     */
    private function messages(\Yiisoft\Validator\Result $result): array
    {
        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[implode('.', $error->getValuePath())] = $error->getMessage();
        }

        return $messages;
    }
}
