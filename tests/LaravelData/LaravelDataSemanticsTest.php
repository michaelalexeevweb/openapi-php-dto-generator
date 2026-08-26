<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

/**
 * What `spatie/laravel-data` guarantees, measured rather than read off its documentation.
 *
 * Nothing here generates code. These are hand-written `Data` classes shaped exactly as the laravel-data
 * emitter intends to shape them, and every assertion is a decision that emitter depends on. They live
 * as tests because each one was a question before it was an answer, and because a minor release of the
 * package could change any of them silently — an emitter built on `Optional` semantics or on
 * `rules()` overriding the inferrers should hear about it here, not through a generated class that
 * stops validating.
 *
 * It doubles as the proof that `LaravelDataContainer` is a working substitute for a Laravel
 * application: if the harness were wrong, none of this would run at all.
 */
final class LaravelDataSemanticsTest extends TestCase
{
    protected function setUp(): void
    {
        LaravelDataContainer::boot();
    }

    /**
     * The harness, end to end. `illuminate/foundation` is not installed and no service provider is
     * registered, so this failing means laravel-data grew a dependency on something the container does
     * not hold.
     */
    public function testThePipelineRunsWithoutALaravelApplication(): void
    {
        $this->assertFalse(
            class_exists(\Illuminate\Foundation\Application::class),
            'illuminate/foundation is installed — this suite no longer proves it is unnecessary',
        );

        $dto = BootProbe::from(['first_name' => 'Ada']);

        $this->assertSame('Ada', $dto->firstName);
        $this->assertSame(['firstName' => 'Ada'], $dto->toArray());
    }

    /**
     * `#[MapInputName]` is how a wire name that is not a PHP name arrives, and the emitter needs it for
     * every property whose OpenAPI name differs from the camelCase one it declares.
     */
    public function testAnInputNameIsMappedOnTheWayIn(): void
    {
        $this->assertSame('Ada', BootProbe::from(['first_name' => 'Ada'])->firstName);
    }

    /**
     * THE decision behind the emitted class having no `#[MergeValidationRules]`.
     *
     * The rule array this package produces is complete; anything merged into it is duplication at best.
     * With the attribute, laravel-data's inferrers are merged in — `required, string` ahead of our
     * `required, string, min:2`, and a `nullable` the schema never asked for ahead of a nullable
     * optional. Duplicate messages and a spurious `nullable` were both real bugs in the first-party
     * Laravel mode; this is the same shape, and the default behaviour avoids it.
     */
    public function testRulesOverrideTheInferrersUnlessTheClassAsksToMerge(): void
    {
        $this->assertSame(
            [
                'required' => ['required', 'string', 'min:2'],
                'optional' => ['sometimes', 'string'],
                'nullableOptional' => ['sometimes', 'nullable', 'string'],
            ],
            OverriddenRules::getValidationRules([]),
            'without the attribute, rules() must be the sole truth',
        );

        $merged = MergedRules::getValidationRules([]);
        $this->assertSame(
            ['required', 'string', 'required', 'string', 'min:2'],
            $merged['required'],
            'with the attribute the inferred rules are merged in, which is the duplication to avoid',
        );
        $this->assertSame(
            ['nullable', 'sometimes', 'string', 'sometimes', 'nullable', 'string'],
            $merged['nullableOptional'],
            'and an inferred `nullable` is prepended — permission the schema never gave',
        );
    }

    /**
     * A closure rule inside `rules()` fires, so a per-property escape hatch exists on top of the
     * `withValidator()` one. Worth knowing before choosing where an interpreter check hangs.
     */
    public function testAClosureRuleInsideRulesIsHonoured(): void
    {
        $this->expectException(ValidationException::class);

        try {
            ClosureRules::validate(['f' => 'bad']);
        } catch (ValidationException $exception) {
            $this->assertSame(['f' => ['closure rule fired for f']], $exception->errors());

            throw $exception;
        }
    }

    /**
     * `string|null|Optional` — an OpenAPI property that is both optional and nullable — keeps all three
     * states apart. This is the PATCH feature: `Optional` means the key was absent, `null` means it
     * arrived as null, and an absent optional is omitted from `toArray()` for free.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expectedArray
     */
    #[DataProvider('presenceProvider')]
    public function testAbsentNullAndAValueStayDistinguishable(
        array $payload,
        string $expectedState,
        array $expectedArray,
    ): void {
        $dto = NullableOptional::from($payload);

        $state = match (true) {
            $dto->maybe instanceof Optional => 'absent',
            $dto->maybe === null => 'null',
            default => 'value',
        };

        $this->assertSame($expectedState, $state);
        $this->assertSame($expectedArray, $dto->toArray());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
     */
    public static function presenceProvider(): array
    {
        return [
            'key absent' => [['required' => 'x'], 'absent', ['required' => 'x']],
            'key present as null' => [['required' => 'x', 'maybe' => null], 'null', ['required' => 'x', 'maybe' => null]],
            'key present with a value' => [['required' => 'x', 'maybe' => 'ab'], 'value', ['required' => 'x', 'maybe' => 'ab']],
        ];
    }

    /**
     * And the rule side draws the same line: `sometimes` alone allows absence but refuses an explicit
     * null, `sometimes|nullable` allows both. Which is exactly the difference between an optional
     * property and a nullable one in OpenAPI — the distinction the first-party Laravel mode got wrong
     * by emitting `nullable` for everything optional.
     */
    public function testOptionalIsNotNullableOnTheRuleSide(): void
    {
        $this->assertSame(
            ['required' => 'xx'],
            OverriddenRules::validate(['required' => 'xx']),
            'an absent optional must pass',
        );

        $this->expectException(ValidationException::class);
        OverriddenRules::validate(['required' => 'xx', 'optional' => null]);
    }

    /**
     * `validation_strategy` defaults to `OnlyRequests`, so `from(array)` hydrates WITHOUT validating.
     * The emitted class must therefore document `from($request)` as its entry point, and any test that
     * means to measure the rules has to go through a request too.
     */
    public function testFromAnArrayDoesNotValidate(): void
    {
        $this->assertSame('ab', TooShort::from(['f' => 'ab'])->f, 'from(array) is not a validating path');

        $this->expectException(ValidationException::class);
        LaravelDataContainer::withRequest('{"f":"ab"}', static fn(): object => TooShort::from(
            \Illuminate\Container\Container::getInstance()->make('request'),
        ));
    }

    /**
     * `validateAndCreate()` is the other validating entry point, for callers who hold an array rather
     * than a request. It validates but cannot see the raw body — see the interpreter test below.
     */
    public function testValidateAndCreateValidatesAnArray(): void
    {
        $this->expectException(ValidationException::class);
        TooShort::validateAndCreate(['f' => 'ab']);
    }

    /**
     * The interpreter hook. laravel-data calls `withValidator(Validator $validator)` with ONE argument
     * — the first-party Laravel mode passes the raw JSON as a second — so the raw body has to come from
     * the request. That matters for the one question a decoded array cannot answer: `{"m":{"0":1,"1":2}}`
     * and `{"m":[1,2]}` are the same PHP value, and `type: object` versus `type: array` is exactly that
     * difference.
     */
    #[DataProvider('wireShapeProvider')]
    public function testTheInterpreterCanReachTheRawBodyThroughTheRequest(string $json, bool $accepted): void
    {
        $rejection = LaravelDataContainer::withRequest($json, static function (): ?array {
            try {
                WireShape::from(\Illuminate\Container\Container::getInstance()->make('request'));

                return null;
            } catch (ValidationException $exception) {
                return $exception->errors();
            }
        });

        if ($accepted) {
            $this->assertNull($rejection, sprintf('%s is an object and must be accepted', $json));

            return;
        }

        $this->assertSame(['m' => ['must be an object, not a list']], $rejection);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function wireShapeProvider(): array
    {
        return [
            'sparse object' => ['{"m":{"a":1}}', true],
            // The case that only the raw bytes can answer: keys 0..n-1 still make it an object.
            'dense object' => ['{"m":{"0":1,"1":2}}', true],
            'list' => ['{"m":[1,2]}', false],
        ];
    }

    /**
     * Without a request there are no raw bytes, and the interpreter has to notice rather than guess.
     * The emitted check needs a defined answer on this path, because `validateAndCreate($array)` is a
     * documented entry point.
     */
    public function testTheInterpreterKnowsWhenTheRawBodyIsOutOfReach(): void
    {
        try {
            WireShape::validateAndCreate(['m' => ['a' => 1]]);
            $this->fail('the probe reports blindness as an error, so this must not pass');
        } catch (ValidationException $exception) {
            $this->assertSame(['m' => ['no raw body in reach']], $exception->errors());
        }
    }

    /**
     * `withValidator()` runs on the ROOT data object only — a nested `Data`'s own hook never fires.
     * So every interpreter check for a nested schema has to be flattened into the root class, which is
     * what the first-party Laravel mode already does.
     */
    public function testANestedWithValidatorNeverRuns(): void
    {
        $errors = LaravelDataContainer::withRequest('{"child":{"v":"x"}}', static function (): array {
            try {
                NestedRoot::from(\Illuminate\Container\Container::getInstance()->make('request'));

                return [];
            } catch (ValidationException $exception) {
                return $exception->errors();
            }
        });

        $this->assertSame([], $errors, 'a nested withValidator() fired — the emitter could stop flattening');
    }

    /**
     * A collection property can be a plain `array` with `#[DataCollectionOf]` or a `DataCollection`.
     * They hydrate to the same items and normalize to the same array, so the emitter uses the plain
     * array: `DataCollection` would put a package type in every generated signature and buy nothing.
     */
    public function testAPlainArrayNormalizesLikeADataCollection(): void
    {
        $payload = ['items' => [['id' => 7], ['id' => 8]]];
        $expected = ['items' => [['id' => 7], ['id' => 8]]];

        $plain = PlainArrayItems::from($payload);
        $wrapped = DataCollectionItems::from($payload);

        $this->assertContainsOnlyInstancesOf(Item::class, $plain->items);
        $this->assertInstanceOf(DataCollection::class, $wrapped->items);
        $this->assertSame($expected, $plain->toArray());
        $this->assertSame($expected, $wrapped->toArray());
    }

    /**
     * A wildcard rule reaches into the items of a plain array, which is how per-item OpenAPI keywords
     * are expressed at all.
     */
    public function testAWildcardRuleReachesIntoAPlainArray(): void
    {
        $this->expectException(ValidationException::class);

        try {
            PlainArrayItems::validateAndCreate(['items' => [['id' => 1]]]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.id', $exception->errors());

            throw $exception;
        }
    }

    /**
     * JSON Schema 2020-12 §6.1.1: a number with a zero fractional part IS an integer. laravel-data
     * lands on the correct side of it — `42.0` passes an `integer` rule, `42.5` does not — which puts
     * this mode with runtime and first-party Laravel rather than with Symfony mode, where the
     * serializer refuses `42.0` before any generated check runs.
     */
    public function testAnIntegralFloatIsAnInteger(): void
    {
        $this->assertSame(42, IntegerProbe::validateAndCreate(['n' => 42.0])->n);

        $this->expectException(ValidationException::class);
        IntegerProbe::validateAndCreate(['n' => 42.5]);
    }

    /**
     * An undeclared key is dropped silently by `from()`, so `additionalProperties: false` cannot be
     * enforced by hydration — it has to go through the interpreter, which CAN see the key because the
     * validator still holds the full payload.
     */
    public function testAnUndeclaredKeyIsDroppedButStillVisibleToTheValidator(): void
    {
        $this->assertSame(['f' => 'abcdef'], TooShort::from(['f' => 'abcdef', 'extra' => 1])->toArray());

        $probe = new class ('x') extends Data {
            /** @var null|array<string, mixed> */
            public static ?array $payloadSeenByTheValidator = null;

            public function __construct(
                public readonly string $f,
            ) {
            }

            /**
             * @return array<string, mixed>
             */
            public static function rules(): array
            {
                return ['f' => ['required', 'string']];
            }

            public static function withValidator(Validator $validator): void
            {
                $validator->after(static function (Validator $validator): void {
                    /** @var array<string, mixed> $data */
                    $data = $validator->getData();
                    self::$payloadSeenByTheValidator = $data;
                });
            }
        };

        $class = $probe::class;
        $class::validateAndCreate(['f' => 'abcdef', 'extra' => 1]);

        $this->assertSame(['f' => 'abcdef', 'extra' => 1], $class::$payloadSeenByTheValidator);
    }

    /**
     * THE measurement behind `array<string>` on a temporal container in this mode.
     *
     * `#[WithCast]` casts the PROPERTY. On an `array` property there is nothing for
     * `DateTimeInterfaceCast` to parse, so it hands the array back untouched and the items stay the
     * strings the payload carried — while the same attribute on a `DateTimeImmutable` property does
     * cast. Both halves are asserted here, because the emitter's choice depends on the difference:
     * the scalar gets the attribute, the container gets an honest docblock.
     *
     * `#[DataCollectionOf]` is not the answer either — it wants a class implementing `BaseData`, and
     * `DateTimeImmutable` is not one. A per-item date cast would have to be a `Cast` class of ours,
     * emitted into generated code that currently depends on nothing of this package.
     */
    public function testACastOnAnArrayPropertyDoesNotReachItsItems(): void
    {
        $dto = DateItems::from(['dates' => ['2026-01-15', '2026-02-20'], 'at' => '2026-03-10']);

        $this->assertSame(['2026-01-15', '2026-02-20'], $dto->dates);
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->at, 'the SCALAR half does cast');
    }
}

#[MergeValidationRules]
final class MergedRules extends Data
{
    public function __construct(
        public readonly string $required,
        public readonly string|Optional $optional,
        public readonly string|Optional|null $nullableOptional,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'required' => ['required', 'string', 'min:2'],
            'optional' => ['sometimes', 'string'],
            'nullableOptional' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

/**
 * The shape the emitter produces: no `#[MergeValidationRules]`, so `rules()` wins outright.
 */
final class OverriddenRules extends Data
{
    public function __construct(
        public readonly string $required,
        public readonly string|Optional $optional,
        public readonly string|Optional|null $nullableOptional,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'required' => ['required', 'string', 'min:2'],
            'optional' => ['sometimes', 'string'],
            'nullableOptional' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

final class BootProbe extends Data
{
    public function __construct(
        #[MapInputName('first_name')]
        public readonly string $firstName,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['first_name' => ['required', 'string', 'min:2']];
    }
}

final class ClosureRules extends Data
{
    public function __construct(
        public readonly string $f,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'f' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== 'ok') {
                        $fail('closure rule fired for ' . $attribute);
                    }
                },
            ],
        ];
    }
}

final class NullableOptional extends Data
{
    public function __construct(
        public readonly string $required,
        public readonly string|Optional|null $maybe,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'required' => ['required', 'string'],
            'maybe' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

final class TooShort extends Data
{
    public function __construct(
        public readonly string $f,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['f' => ['required', 'string', 'min:5']];
    }
}

/**
 * Stands in for a `type: object` property, with the check the emitted interpreter would carry.
 */
final class WireShape extends Data
{
    /**
     * @param array<string, mixed> $m
     */
    public function __construct(
        public readonly array $m,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['m' => ['required', 'array']];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $container = \Illuminate\Container\Container::getInstance();
            if (!$container->bound('request')) {
                $validator->errors()->add('m', 'no raw body in reach');

                return;
            }

            /** @var \Illuminate\Http\Request $request */
            $request = $container->make('request');
            $decoded = json_decode((string)$request->getContent(), false);
            $isObject = is_object($decoded)
                && property_exists($decoded, 'm')
                && is_object($decoded->m);

            if (!$isObject) {
                $validator->errors()->add('m', 'must be an object, not a list');
            }
        });
    }
}

final class NestedChild extends Data
{
    public function __construct(
        public readonly string $v,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['v' => ['required', 'string']];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $validator->errors()->add('child', 'the nested hook ran');
        });
    }
}

final class NestedRoot extends Data
{
    public function __construct(
        public readonly NestedChild $child,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['child' => ['required', 'array'], 'child.v' => ['required', 'string']];
    }
}

final class Item extends Data
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}

final class PlainArrayItems extends Data
{
    /**
     * @param array<int, Item> $items
     */
    public function __construct(
        #[DataCollectionOf(Item::class)]
        public readonly array $items,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'min:5'],
        ];
    }
}

final class DataCollectionItems extends Data
{
    public function __construct(
        #[DataCollectionOf(Item::class)]
        public readonly DataCollection $items,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'min:5'],
        ];
    }
}

final class IntegerProbe extends Data
{
    public function __construct(
        public readonly int $n,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['n' => ['required', 'integer']];
    }
}

/**
 * The two temporal shapes side by side, attributed exactly as the emitter attributes them — the
 * scalar carrying the cast, the container carrying it too, to show it changes nothing there.
 */
final class DateItems extends Data
{
    /**
     * @param array<string> $dates
     */
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d'])]
        public readonly array $dates,
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d'])]
        public readonly DateTimeImmutable $at,
    ) {
    }
}
