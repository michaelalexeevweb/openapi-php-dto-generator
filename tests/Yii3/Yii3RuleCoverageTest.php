<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every keyword the yii3 emitter claims, driven through the real validator with a value that must
 * PASS and one that must FAIL.
 *
 * A rule that accepts everything is invisible to a one-sided test, which is why each case asserts
 * both halves. The keywords split two ways and both are covered here: those a `yiisoft/validator`
 * rule enforces natively, and those that reach the emitted interpreter through the class-level
 * `#[Callback]` because no rule expresses them.
 */
final class Yii3RuleCoverageTest extends TestCase
{
    use GeneratesYii3Input;

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('nativeRuleProvider')]
    public function testANativeRuleAcceptsTheGoodValueAndRejectsTheBadOne(
        array $schema,
        mixed $valid,
        mixed $invalid,
    ): void {
        self::assertTrue(
            $this->verdictFor($schema, $valid)->isValid(),
            sprintf('the valid value %s was rejected', json_encode($valid)),
        );

        $result = $this->verdictFor($schema, $invalid);
        self::assertFalse(
            $result->isValid(),
            sprintf('the invalid value %s was accepted', json_encode($invalid)),
        );
        self::assertNotSame([], $this->messages($result), 'a rejection must carry a message');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: mixed}>
     */
    public static function nativeRuleProvider(): array
    {
        return [
            // Lengths and bounds — one rule each, unlike Laravel's overloaded min:/max:.
            'minLength' => [['type' => 'string', 'minLength' => 3], 'abc', 'ab'],
            'maxLength' => [['type' => 'string', 'maxLength' => 2], 'ab', 'abc'],
            'pattern' => [['type' => 'string', 'pattern' => '^a'], 'ab', 'ba'],
            'minimum' => [['type' => 'integer', 'minimum' => 3], 3, 2],
            'maximum' => [['type' => 'integer', 'maximum' => 3], 3, 4],
            'exclusiveMinimum' => [['type' => 'integer', 'exclusiveMinimum' => 3], 4, 3],
            'exclusiveMaximum' => [['type' => 'integer', 'exclusiveMaximum' => 3], 2, 3],
            'minItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2], ['a', 'b'], ['a']],
            'maxItems' => [['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1], ['a'], ['a', 'b']],

            // Formats that map onto a rule. The rest are the interpreter's, below.
            'format email' => [['type' => 'string', 'format' => 'email'], 'a@b.co', 'nope'],
            'format uuid' => [
                ['type' => 'string', 'format' => 'uuid'],
                '7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b',
                'not-a-uuid',
            ],
            'format ipv4' => [['type' => 'string', 'format' => 'ipv4'], '192.0.2.1', '999.1.1.1'],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('interpreterKeywordProvider')]
    public function testAnInterpreterKeywordAcceptsTheGoodValueAndRejectsTheBadOne(
        array $schema,
        mixed $valid,
        mixed $invalid,
    ): void {
        self::assertTrue(
            $this->verdictFor($schema, $valid)->isValid(),
            sprintf('the valid value %s was rejected', json_encode($valid)),
        );
        self::assertFalse(
            $this->verdictFor($schema, $invalid)->isValid(),
            sprintf('the invalid value %s was accepted', json_encode($invalid)),
        );
    }

    /**
     * Keywords `yiisoft/validator` has no rule for. Each one used to be dropped ENTIRELY — the
     * Symfony constraint filter removes scalar keywords because that mode has an `#[Assert\*]` for
     * each, and this mode does not, so they were enforced nowhere until the filter call was changed.
     *
     * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: mixed}>
     */
    public static function interpreterKeywordProvider(): array
    {
        return [
            'const' => [['type' => 'string', 'const' => 'a'], 'a', 'b'],
            'multipleOf' => [['type' => 'integer', 'multipleOf' => 3], 6, 7],
            'not' => [['type' => 'string', 'not' => ['const' => 'zz']], 'ok', 'zz'],
            'oneOf' => [
                ['oneOf' => [['type' => 'integer', 'minimum' => 10], ['type' => 'string']]],
                11,
                5,
            ],
            'uniqueItems' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                ['a', 'b'],
                ['a', 'a'],
            ],
            'minProperties' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'minProperties' => 2],
                ['a' => 1, 'b' => 2],
                ['a' => 1],
            ],
            'maxProperties' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'maxProperties' => 1],
                ['a' => 1],
                ['a' => 1, 'b' => 2],
            ],
            'propertyNames' => [
                [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                    'propertyNames' => ['pattern' => '^x-'],
                ],
                ['x-a' => 1],
                ['y' => 1],
            ],
            'additionalProperties schema' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                ['a' => 1],
                ['a' => 'x'],
            ],
            'format hostname' => [['type' => 'string', 'format' => 'hostname'], 'ex.com', 'not a host'],
            'format byte' => [['type' => 'string', 'format' => 'byte'], 'aGk=', '!!!'],
            'format int32' => [['type' => 'integer', 'format' => 'int32'], 5, 2147483648],
            'format uint32' => [['type' => 'integer', 'format' => 'uint32'], 5, -1],
        ];
    }

    /**
     * One mistake, one message — the rule every mode in this package follows.
     *
     * A missing required property is reported by the interpreter as "is required"; the rules on that
     * same property must stay quiet, or the useful line drowns. Measured before the fix: one absent
     * property produced five extra lines about strings and allowed types.
     */
    public function testAMissingRequiredPropertyProducesExactlyOneMessageEvenWhenItCarriesRules(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep', 'name', 'age'],
                'properties' => [
                    'keep' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 10, 'pattern' => '^[a-z]+$'],
                    'age' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $result = $container->validate($container->hydrate($namespace . '\Probe', ['keep' => 'x']));

        self::assertFalse($result->isValid());

        $messages = array_map(
            static fn(object $error): string => $error->getMessage(),
            $result->getErrors(),
        );
        self::assertCount(2, $messages, 'two absent properties, two messages: ' . implode(' | ', $messages));
        self::assertSame(
            ['field "name" is required', 'field "age" is required'],
            $messages,
        );
    }

    /**
     * The same rule for the OTHER way a value can be empty: an explicit `null` the schema does not
     * allow is one mistake, so it gets one message.
     *
     * Measured before the skip condition became `WhenNull` everywhere: an optional `string` sent as
     * null produced three lines — the interpreter's "must be of type string" plus "must be a string"
     * and "must be a string. null given." from the native rules, all about the same null.
     */
    public function testAnExplicitNullTheSchemaForbidsProducesExactlyOneMessage(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep'],
                'properties' => [
                    'keep' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'minLength' => 3],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $class = $namespace . '\Probe';

        foreach (['name' => 'string', 'tags' => 'array'] as $property => $type) {
            $result = $container->validate(
                $container->hydrate($class, ['keep' => 'x', $property => null]),
            );

            self::assertFalse($result->isValid(), $property . ' sent as null must be refused');
            self::assertSame(
                [sprintf('field "%s" must be of type %s', $property, $type)],
                array_map(static fn(object $error): string => $error->getMessage(), $result->getErrors()),
            );
        }
    }

    /**
     * …and a null the schema DOES allow is not a mistake at all, so the same condition must not be
     * reading as "nulls are never checked".
     */
    public function testAnExplicitNullTheSchemaAllowsIsAccepted(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['keep'],
                'properties' => [
                    'keep' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'minLength' => 3, 'nullable' => true],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                        'nullable' => true,
                    ],
                ],
            ],
        ]);

        $container = new Yii3Container();
        $result = $container->validate(
            $container->hydrate($namespace . '\Probe', ['keep' => 'x', 'name' => null, 'tags' => null]),
        );

        self::assertTrue($result->isValid(), implode(' | ', $this->messages($result)));
    }

    /**
     * The mirror of the case above: a property that IS present is still validated normally, so the
     * skip condition cannot have disabled the rules outright.
     */
    public function testAPresentValueIsStillValidated(): void
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string', 'minLength' => 3, 'pattern' => '^[a-z]+$']],
            ],
        ]);

        $container = new Yii3Container();
        $class = $namespace . '\Probe';

        self::assertTrue($container->validate($container->hydrate($class, ['name' => 'alice']))->isValid());
        self::assertStringContainsString(
            'at least 3 characters',
            implode(' ', $this->messages($container->validate($container->hydrate($class, ['name' => 'ab'])))),
        );
        self::assertStringContainsString(
            'invalid',
            implode(' ', $this->messages($container->validate($container->hydrate($class, ['name' => 'Alice'])))),
        );
    }
}
