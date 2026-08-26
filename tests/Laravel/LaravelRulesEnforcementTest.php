<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Laravel;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The generated `rules()` driven through the REAL `illuminate/validation` validator.
 *
 * Asserting the emitted source only proves we wrote what we meant to write; it does not prove Laravel
 * agrees. Every case below feeds one valid and one invalid payload to a real `Validator` built from
 * the generated rules, and demands that the verdicts differ — a rule that accepts everything would
 * otherwise pass unnoticed.
 *
 * No Laravel application is booted: a `Factory` over an `ArrayLoader` translator is all the validator
 * needs.
 */
final class LaravelRulesEnforcementTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-laravel-rules';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*.php') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($entry);
                continue;
            }
            @unlink($entry);
        }
        @rmdir($this->outputDirectory);
    }

    private function validatorFactory(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @param array<string, array<string, mixed>> $extraSchemas
     * @return class-string
     */
    private function generateProbe(string $key, array $propertySchema, array $extraSchemas = []): string
    {
        $namespace = 'LvRule' . substr(md5($key), 0, 10);
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => $propertySchema],
                    ],
                ] + $extraSchemas,
            ],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, 'laravel');

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

    /**
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $invalid
     * @param array<string, array<string, mixed>> $extraSchemas
     */
    #[DataProvider('keywordProvider')]
    public function testGeneratedRulesAcceptTheValidPayloadAndRejectTheInvalidOne(
        string $key,
        array $propertySchema,
        array $valid,
        array $invalid,
        array $extraSchemas,
    ): void {
        $fqcn = $this->generateProbe($key, $propertySchema, $extraSchemas);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $factory = $this->validatorFactory();

        $this->assertTrue(
            $factory->make($valid, $rules)->passes(),
            sprintf(
                "%s: the valid payload must pass\n rules: %s\n errors: %s",
                $key,
                json_encode(array_map(static fn(array $set): array => array_map('strval', $set), $rules)),
                json_encode($factory->make($valid, $rules)->errors()->all()),
            ),
        );

        $this->assertTrue(
            $factory->make($invalid, $rules)->fails(),
            sprintf('%s: the invalid payload must fail (rules that accept everything are useless)', $key),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>, 4: array<string, array<string, mixed>>}>
     */
    public static function keywordProvider(): array
    {
        $tag = ['Tag' => [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string', 'minLength' => 2]],
        ]];

        $cases = [
            // schema, valid payload, invalid payload, extra schemas
            'type string' => [['type' => 'string'], ['f' => 'a'], ['f' => 5], []],
            'type integer' => [['type' => 'integer'], ['f' => 5], ['f' => 'nope'], []],
            'type number' => [['type' => 'number'], ['f' => 1.5], ['f' => 'nope'], []],
            'type boolean' => [['type' => 'boolean'], ['f' => true], ['f' => 'nope'], []],
            'minLength' => [['type' => 'string', 'minLength' => 3], ['f' => 'abc'], ['f' => 'ab'], []],
            'maxLength' => [['type' => 'string', 'maxLength' => 2], ['f' => 'ab'], ['f' => 'abc'], []],
            'minimum' => [['type' => 'integer', 'minimum' => 3], ['f' => 3], ['f' => 2], []],
            'maximum' => [['type' => 'integer', 'maximum' => 3], ['f' => 3], ['f' => 4], []],
            'multipleOf' => [['type' => 'integer', 'multipleOf' => 3], ['f' => 6], ['f' => 7], []],
            // A `|` inside the pattern is why the rule list must never be a pipe string.
            'pattern with a pipe' => [
                ['type' => 'string', 'pattern' => '^(a|b)$'],
                ['f' => 'b'],
                ['f' => 'c'],
                [],
            ],
            'enum' => [['type' => 'string', 'enum' => ['a', 'b']], ['f' => 'a'], ['f' => 'z'], []],
            // A value containing a comma is why `in:a,b` is not used.
            'enum with a comma' => [
                ['type' => 'string', 'enum' => ['a,b', 'c']],
                ['f' => 'a,b'],
                ['f' => 'a'],
                [],
            ],
            'const' => [['type' => 'string', 'const' => 'only'], ['f' => 'only'], ['f' => 'other'], []],
            'format email' => [['type' => 'string', 'format' => 'email'], ['f' => 'a@b.co'], ['f' => 'nope'], []],
            'format uuid' => [
                ['type' => 'string', 'format' => 'uuid'],
                ['f' => '7f8d4c22-3d1f-4b6e-9c5a-2b1d3e4f5a6b'],
                ['f' => 'nope'],
                [],
            ],
            'format ipv4' => [['type' => 'string', 'format' => 'ipv4'], ['f' => '1.2.3.4'], ['f' => '999.1.1.1'], []],
            'format date' => [['type' => 'string', 'format' => 'date'], ['f' => '2026-03-10'], ['f' => '2026-13-45'], []],
            'format date-time' => [
                ['type' => 'string', 'format' => 'date-time'],
                ['f' => '2026-03-10T12:00:00+00:00'],
                ['f' => 'yesterday'],
                [],
            ],
            'format date-time with microseconds' => [
                ['type' => 'string', 'format' => 'date-time'],
                ['f' => '2026-03-10T12:00:00.123456+03:00'],
                ['f' => 'nope'],
                [],
            ],
            'array of scalars' => [
                ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
                ['f' => [1, 2]],
                ['f' => [1, -1]],
                [],
            ],
            'minItems' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
                ['f' => ['a', 'b']],
                ['f' => ['a']],
                [],
            ],
            'uniqueItems' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                ['f' => ['a', 'b']],
                ['f' => ['a', 'a']],
                [],
            ],
            // `array` alone accepts an associative array; `list` is what says "JSON array".
            'array rejects an object' => [
                ['type' => 'array', 'items' => ['type' => 'string']],
                ['f' => ['a']],
                ['f' => ['k' => 'a']],
                [],
            ],
            'nested dto property' => [
                ['$ref' => '#/components/schemas/Tag'],
                ['f' => ['name' => 'php']],
                ['f' => ['name' => 'p']],
                $tag,
            ],
            'list of dtos' => [
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                ['f' => [['name' => 'php'], ['name' => 'api']]],
                ['f' => [['name' => 'php'], ['name' => 'x']]],
                $tag,
            ],
            'map values' => [
                ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 5]],
                ['f' => ['a' => 9]],
                ['f' => ['a' => 1]],
                [],
            ],
            'required field missing' => [['type' => 'string'], ['f' => 'a'], [], []],
        ];

        // The case name is also the namespace seed, so it is threaded through as the first argument.
        $provided = [];
        foreach ($cases as $key => [$schema, $valid, $invalid, $extra]) {
            $provided[$key] = [$key, $schema, $valid, $invalid, $extra];
        }

        return $provided;
    }

    /**
     * An optional property must accept an absent key and a value, and still reject a value that breaks
     * its own rules. That is `sometimes`, and it is what makes PATCH work.
     *
     * An explicit null is a SEPARATE question, answered by the schema and not by optionality: without
     * `nullable` the key is there carrying a value the schema never allowed, so it is rejected — which
     * is the runtime verdict, pinned across the modes in `ValidationParityTest`.
     */
    public function testOptionalPropertyAcceptsAbsentKeyAndValueButNotNull(): void
    {
        $namespace = 'LvRuleOptional';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);

        (new GenerateDtoCommand())->generateFromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Patch' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'nickname' => ['type' => 'string', 'minLength' => 2],
                            'note' => ['type' => ['string', 'null'], 'minLength' => 2],
                        ],
                    ],
                ],
            ],
        ], $target, $namespace, 'laravel');
        require_once $target . '/Patch.php';

        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$namespace . '\Patch', 'rules']);
        $factory = $this->validatorFactory();

        $this->assertTrue($factory->make(['id' => 1], $rules)->passes(), 'absent optional');
        $this->assertTrue($factory->make(['id' => 1, 'nickname' => 'ok'], $rules)->passes(), 'value');
        $this->assertTrue($factory->make(['id' => 1, 'nickname' => 'x'], $rules)->fails(), 'value below minLength');
        $this->assertTrue($factory->make(['id' => 1, 'nickname' => null], $rules)->fails(), 'null, not nullable');

        // Nullable in the schema: the null is legal, and the rest of the rules still apply to a value.
        $this->assertTrue($factory->make(['id' => 1, 'note' => null], $rules)->passes(), 'null, nullable');
        $this->assertTrue($factory->make(['id' => 1, 'note' => 'x'], $rules)->fails(), 'nullable below minLength');
    }

    /**
     * The whole point of the mode: what the validator accepts, the DTO can be built from — the rules and
     * `fromValidated()` have to agree on the same payload.
     */
    public function testValidatedDataHydratesTheDto(): void
    {
        $namespace = 'LvRuleHydrate';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);

        (new GenerateDtoCommand())->generateFromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Kind' => ['type' => 'string', 'enum' => ['dog', 'cat']],
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['name', 'kind', 'born'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 2],
                            'kind' => ['$ref' => '#/components/schemas/Kind'],
                            'born' => ['type' => 'string', 'format' => 'date'],
                        ],
                    ],
                ],
            ],
        ], $target, $namespace, 'laravel');
        foreach (['Kind', 'Pet'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        $payload = ['name' => 'Rex', 'kind' => 'dog', 'born' => '2026-03-10'];
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$namespace . '\Pet', 'rules']);

        $validator = $this->validatorFactory()->make($payload, $rules);
        $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));

        /** @var object $dto */
        $dto = call_user_func([$namespace . '\Pet', 'fromValidated'], $validator->validated());

        $this->assertSame('Rex', $dto->getName());
        $this->assertSame('dog', $dto->getKind()->value);
        // A date stays a date on the way out, as in every other mode.
        $this->assertSame('2026-03-10', $dto->getBorn());
        $this->assertSame($payload, $dto->toArray());
    }

    /**
     * The half no Laravel rule can express, enforced through `withValidator()` — this is what no other
     * spec-first Laravel generator does. Every case is driven by the real validator: the rules and the
     * interpreter run together, exactly as a FormRequest would run them.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $invalid
     */
    #[DataProvider('interpreterProvider')]
    public function testTheInterpreterEnforcesWhatRulesCannot(
        string $key,
        array $propertySchema,
        array $valid,
        array $invalid,
        string $expectedMessageFragment,
    ): void {
        $fqcn = $this->generateProbe('interpreter ' . $key, $propertySchema);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        $accepted = $this->validatorFactory()->make($valid, $rules);
        call_user_func([$fqcn, 'withValidator'], $accepted);
        $this->assertTrue($accepted->passes(), sprintf('%s: valid payload — %s', $key, json_encode($accepted->errors()->all())));

        $rejected = $this->validatorFactory()->make($invalid, $rules);
        call_user_func([$fqcn, 'withValidator'], $rejected);
        $this->assertTrue($rejected->fails(), $key . ': invalid payload must fail');
        $this->assertStringContainsString(
            $expectedMessageFragment,
            implode("\n", $rejected->errors()->all()),
            $key . ': the message must say what actually failed',
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>, 4: string}>
     */
    public static function interpreterProvider(): array
    {
        $cases = [
            'anyOf' => [
                ['anyOf' => [['type' => 'string', 'minLength' => 3], ['type' => 'integer']]],
                ['f' => 7],
                ['f' => 1.5],
                'does not match any anyOf branch',
            ],
            'oneOf is exclusive' => [
                ['oneOf' => [['type' => 'integer', 'minimum' => 10], ['type' => 'integer', 'maximum' => 100]]],
                ['f' => 5],
                ['f' => 50],
                'more than one',
            ],
            'not' => [
                ['type' => 'string', 'not' => ['const' => 'forbidden']],
                ['f' => 'fine'],
                ['f' => 'forbidden'],
                "must not match the 'not' schema",
            ],
            'if then' => [
                ['type' => 'string', 'if' => ['const' => 'a'], 'then' => ['minLength' => 3]],
                ['f' => 'b'],
                ['f' => 'a'],
                'at least 3 characters',
            ],
            'contains' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'hit']],
                ['f' => ['miss', 'hit']],
                ['f' => ['miss']],
                "matching the 'contains' schema",
            ],
            // The interpreter reads `$validator->getData()`, i.e. the RAW payload — so an undeclared key
            // is still there to be caught. Neither runtime nor symfony mode can do this: both bind the
            // payload into an object first, and the unknown key is gone by then (E3 in
            // `.todo.codegeneration_symfony_vs_runtime`).
            'unevaluatedProperties false sees unknown keys' => [
                [
                    'type' => 'object',
                    'properties' => ['known' => ['type' => 'string']],
                    'unevaluatedProperties' => false,
                ],
                ['f' => ['known' => 'a']],
                ['f' => ['known' => 'a', 'extra' => 'b']],
                'unevaluated property',
            ],
            'propertyNames' => [
                [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                    'propertyNames' => ['pattern' => '^x-'],
                ],
                ['f' => ['x-a' => 1]],
                ['f' => ['y' => 1]],
                'key',
            ],
            'content base64 json' => [
                [
                    'type' => 'string',
                    'contentEncoding' => 'base64',
                    'contentMediaType' => 'application/json',
                    'contentSchema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]],
                ],
                ['f' => 'eyJhIjo5fQ=='],
                ['f' => 'eyJiIjo5fQ=='],
                'is required',
            ],
            'uniqueItems over objects' => [
                [
                    'type' => 'array',
                    'uniqueItems' => true,
                    'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
                ],
                ['f' => [['a' => 1], ['a' => 2]]],
                ['f' => [['a' => 1], ['a' => 1]]],
                'must contain unique items',
            ],
        ];

        $provided = [];
        foreach ($cases as $key => [$schema, $valid, $invalid, $fragment]) {
            $provided[$key] = [$key, $schema, $valid, $invalid, $fragment];
        }

        return $provided;
    }

    /**
     * A schema whose every keyword IS expressible gets no interpreter at all — the emitted class stays
     * a plain DTO with a rules() array.
     */
    public function testASchemaWithoutCompositionEmitsNoInterpreter(): void
    {
        $fqcn = $this->generateProbe('plain', ['type' => 'string', 'minLength' => 2]);
        $file = (new ReflectionClass($fqcn))->getFileName();
        $this->assertIsString($file);
        $source = (string)file_get_contents($file);

        $this->assertStringNotContainsString('withValidator', $source);
        $this->assertStringNotContainsString('validateOpenApiNode', $source);
        $this->assertStringNotContainsString('OPENAPI_VALIDATION_CONSTRAINTS', $source);
    }

    /**
     * One mistake, one message.
     *
     * A property that lands in the interpreter often ALSO carries keywords the rules express, and both
     * layers then reported the same violation: `validation.min.string` from Laravel plus
     * `f length must be at least 3 characters` from the interpreter. Measured at two messages per case
     * before the interpreter schema was pruned down to what the rules did not take.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $payload
     */
    #[DataProvider('overlapProvider')]
    public function testARuleCoveredViolationIsReportedOnce(string $key, array $propertySchema, array $payload): void
    {
        $fqcn = $this->generateProbe('overlap ' . $key, $propertySchema);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        $validator = $this->validatorFactory()->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator);
        }

        $errors = $validator->errors()->all();
        $this->assertCount(1, $errors, sprintf('%s: expected one message, got %s', $key, json_encode($errors)));
    }

    /**
     * Each schema pairs a rule-expressible keyword with one only the interpreter can enforce, and the
     * payload breaks the rule-expressible half.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function overlapProvider(): array
    {
        $cases = [
            'minLength + not' => [
                ['type' => 'string', 'minLength' => 3, 'not' => ['const' => 'zzz']],
                ['f' => 'ab'],
            ],
            'minimum + oneOf' => [
                ['type' => 'integer', 'minimum' => 10, 'oneOf' => [['multipleOf' => 2], ['multipleOf' => 5]]],
                ['f' => 4],
            ],
            'pattern + if/then' => [
                ['type' => 'string', 'pattern' => '^a', 'if' => ['const' => 'abc'], 'then' => ['maxLength' => 2]],
                ['f' => 'zzz'],
            ],
            // The declared property NAMES still have to reach the interpreter (unevaluatedProperties is
            // defined in terms of them) — only their rules are dropped.
            'nested properties + unevaluatedProperties' => [
                [
                    'type' => 'object',
                    'properties' => ['known' => ['type' => 'string', 'minLength' => 3]],
                    'unevaluatedProperties' => false,
                ],
                ['f' => ['known' => 'ab']],
            ],
            'items + contains' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 5],
                    'contains' => ['const' => 9],
                ],
                ['f' => [1, 9]],
            ],
            // A temporal property gets no `string` rule — the value becomes a DateTimeImmutable — so
            // `type` looked unconsumed and went to the interpreter, next to a `date_format:` rule that
            // already refuses every non-string.
            'date-time + not' => [
                ['type' => 'string', 'format' => 'date-time', 'not' => ['const' => '2020-01-01T00:00:00+00:00']],
                ['f' => 123],
            ],
            // The MAP spelling of the row below it. The prune tested `items` and not
            // `additionalProperties`, so a map's value schema went to the interpreter whole and
            // `f.k` failed both `validation.string` and `f.k must be of type string`.
            'map values + propertyNames' => [
                [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string', 'minLength' => 3],
                    'propertyNames' => ['pattern' => '^[a-z]+$'],
                ],
                ['f' => ['k' => 'ab']],
            ],
            // A container two deep: the `f.*.*` rules cover the inner scalar, so its schema must not
            // travel to the interpreter next to them.
            'nested container values + contains' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 5]],
                    'contains' => ['minItems' => 1],
                ],
                ['f' => [[1]]],
            ],
            // The keyword the rules cannot take sits INSIDE the nested property, and the whole nested
            // subschema used to travel with it — `type: string` included, which `f.tags.*` already has.
            'nested items type + contains' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'contains' => ['const' => 'hit']],
                        ],
                    ],
                ],
                ['f' => ['tags' => [7]]],
            ],
        ];

        $provided = [];
        foreach ($cases as $key => [$schema, $payload]) {
            $provided[$key] = [$key, $schema, $payload];
        }

        return $provided;
    }

    /**
     * An enum inside an `anyOf` branch moves into the PHP type (`ProbeF|int`) and disappears from the
     * constraint map. Runtime mode still rejects an unknown member (its deserializer casts through the
     * enum) and Symfony mode fails at denormalization — but Laravel validates BEFORE hydration, so the
     * members have to be put back into the schema the interpreter sees. Measured: `"zz"` was accepted.
     */
    public function testAnEnumInsideAUnionBranchIsStillEnforced(): void
    {
        $fqcn = $this->generateProbe('union enum', [
            'anyOf' => [
                ['type' => 'string', 'enum' => ['a', 'b']],
                ['type' => 'integer'],
            ],
        ]);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        foreach ([['f' => 'a'], ['f' => 7]] as $valid) {
            $validator = $this->validatorFactory()->make($valid, $rules);
            call_user_func([$fqcn, 'withValidator'], $validator);
            $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
        }

        $rejected = $this->validatorFactory()->make(['f' => 'zz'], $rules);
        call_user_func([$fqcn, 'withValidator'], $rejected);
        $this->assertTrue($rejected->fails(), 'an unknown enum member must be rejected');
        $this->assertStringContainsString('must be one of', implode("\n", $rejected->errors()->all()));
    }

    /**
     * A keyword declared inside a REFERENCED schema has to be enforced too.
     *
     * The nested class carries its own `rules()`, but nobody calls it — the parent's rules only expand
     * the rule-expressible keywords into dotted paths. So `dependentRequired` inside `Child`, and even
     * `Child`'s own `required`, went unchecked until the nested schema was folded into the parent's
     * interpreter. Found by poking the demo endpoint with a nested payload, not by a unit test.
     */
    public function testKeywordsInsideAReferencedSchemaAreEnforced(): void
    {
        $namespace = 'LvNestedInterp';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);

        (new GenerateDtoCommand())->generateFromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Child' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'bag' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string'],
                                'dependentRequired' => ['kind' => ['extra']],
                            ],
                        ],
                    ],
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['child'],
                        'properties' => ['child' => ['$ref' => '#/components/schemas/Child']],
                    ],
                ],
            ],
        ], $target, $namespace, 'laravel');

        foreach (['Child', 'Holder'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        $fqcn = $namespace . '\Holder';
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);

        $verdict = function (array $payload) use ($fqcn, $rules): array {
            $validator = $this->validatorFactory()->make($payload, $rules);
            call_user_func([$fqcn, 'withValidator'], $validator);

            return $validator->fails() ? $validator->errors()->all() : [];
        };

        $this->assertSame([], $verdict(['child' => ['id' => 1]]), 'a valid nested payload');

        // The nested class's own `required` — no Laravel rule can say "required only if the parent has a
        // value", so the interpreter owns it.
        $this->assertNotSame([], $verdict(['child' => []]), 'a missing nested required property');

        // A cross-field keyword one level down.
        $this->assertNotSame(
            [],
            $verdict(['child' => ['id' => 1, 'bag' => ['kind' => 'k']]]),
            'dependentRequired inside the referenced schema',
        );
        $this->assertSame(
            [],
            $verdict(['child' => ['id' => 1, 'bag' => ['kind' => 'k', 'extra' => 'v']]]),
            'the same payload with the dependency satisfied',
        );
    }
}
