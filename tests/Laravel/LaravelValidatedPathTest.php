<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The whole path a FormRequest walks, for shapes where the rules and the hydrator used to disagree:
 * `rules()`, then `withValidator()` over the raw body, then `fromValidated()`, then the output fed back
 * in. The parity suite counts a hydration crash as a refusal, which is right for comparing modes and
 * blind to the difference between a 422 and a 500 — this suite tells them apart: a bad payload must be
 * refused BY THE VALIDATOR, and a good one must come out of the DTO as it went in.
 */
final class LaravelValidatedPathTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-laravel-validated-path';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*') ?: [] as $entry) {
            foreach (glob($entry . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($entry);
        }
        @rmdir($this->outputDirectory);
    }

    /**
     * A closed component reached through a `$ref`: the undeclared key, the fourth property past
     * `maxProperties` and the missing `dependentRequired` partner are refused wherever the component
     * sits, and a conforming value comes back out unchanged — the undeclared key used to be dropped on
     * hydration instead.
     */
    #[DataProvider('closedComponentProvider')]
    public function testAReferencedComponentKeepsItsObjectWideKeywords(string $wrap): void
    {
        $fqcn = $this->generate('closed component ' . $wrap, [
            'Inner' => [
                'type' => 'object',
                'required' => ['alpha'],
                'additionalProperties' => false,
                'maxProperties' => 3,
                'dependentRequired' => ['gamma' => ['delta']],
                'properties' => [
                    'alpha' => ['type' => 'string', 'minLength' => 2],
                    'beta' => ['type' => 'string'],
                    'gamma' => ['type' => 'string'],
                    'delta' => ['type' => 'string'],
                ],
            ],
            'Widget' => ['type' => 'object', 'properties' => [
                'inner' => ['$ref' => '#/components/schemas/Inner'],
                'list' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']],
                'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']]],
                'map' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Inner']],
                // Inside a list: an absent optional nullable property is written back as `null`, which is
                // not what this round trip is about.
                'maybes' => ['type' => 'array', 'items' => ['anyOf' => [['$ref' => '#/components/schemas/Inner'], ['type' => 'null']]]],
            ]],
        ], 'Widget');
        $place = static fn(string $inner): string => str_replace('%s', $inner, $wrap);

        $this->assertRoundTrips($fqcn, $place('{"alpha":"ab","gamma":"c","delta":"d"}'));
        $this->assertRoundTrips($fqcn, $place('{"alpha":"ab"}'));
        $this->assertRefused($fqcn, $place('{"alpha":"ab","x":1}'));
        $this->assertRefused($fqcn, $place('{"alpha":"ab","beta":"b","gamma":"c","delta":"d"}'));
        $this->assertRefused($fqcn, $place('{"alpha":"ab","gamma":"c"}'));
        // A property keyword of the component is still enforced beside the object-wide ones.
        $this->assertRefused($fqcn, $place('{"alpha":"a"}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedComponentProvider(): iterable
    {
        yield 'alone' => ['{"inner":%s}'];
        yield 'list item' => ['{"list":[%s]}'];
        yield 'list of lists' => ['{"grid":[[%s]]}'];
        yield 'map value' => ['{"map":{"k":%s}}'];
        yield 'beside null' => ['{"maybes":[%s]}'];
    }

    /**
     * An `allOf` child and a discriminated union two containers deep. The rules stopped at the inner
     * list — `grid.*` => `list` — and nothing below it was checked, while the same depth of a plain
     * object component was. The valid payloads still come back out as they went in.
     */
    public function testACompositionTwoContainersDeepIsValidated(): void
    {
        $fqcn = $this->generate('composition two containers deep', [
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
            'Widget' => ['type' => 'object', 'properties' => [
                'grid' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']]],
                'mol' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']]],
                'lom' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Shape']]],
                'zoo' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Animal']]],
            ]],
        ], 'Widget');

        $this->assertRoundTrips($fqcn, '{"grid":[[{"id":1,"side":2.5}]],"mol":{"a":[{"id":2,"side":1}]},"lom":[{"a":{"id":3,"side":1}}]}');
        $this->assertRoundTrips($fqcn, '{"zoo":[[{"kind":"cat","lives":9},{"kind":"dog","bark":true}]]}');
        foreach (
            [
                '{"grid":[[{"id":"x","side":2}]]}',
                '{"grid":[[{"id":1}]]}',
                '{"grid":[["str"]]}',
                '{"mol":{"a":[{"id":"x","side":2}]}}',
                '{"lom":[{"a":{"id":"x"}}]}',
                '{"zoo":[[{"kind":"cat","lives":"x"}]]}',
                '{"zoo":[[{"kind":"dog"}]]}',
                '{"zoo":[[{"kind":"cow"}]]}',
            ] as $invalid
        ) {
            $this->assertRefused($fqcn, $invalid);
        }
    }

    /**
     * A union of one class and a scalar, and of a list and a boolean. The constructor was typed
     * `Shade|string` and handed the validated array — a TypeError on every valid object — and
     * `toArray()` wrote `array_values()` around a `true` it held for `array|bool`. Both members of
     * each union come back out as they went in, the object as the class it was built into.
     */
    public function testAClassOrAScalarUnionHydratesTheMemberItWasSent(): void
    {
        $fqcn = $this->generate('class or scalar union', [
            'Shade' => ['type' => 'object', 'required' => ['hue'], 'properties' => ['hue' => ['type' => 'string']]],
            'Widget' => ['type' => 'object', 'properties' => [
                'tint' => ['anyOf' => [['$ref' => '#/components/schemas/Shade'], ['type' => 'string']]],
                'size' => ['oneOf' => [['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'integer']]], ['type' => 'integer']]],
                'marks' => ['anyOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'boolean']]],
            ]],
        ], 'Widget');

        $this->assertRoundTrips($fqcn, '{"tint":{"hue":"red"},"size":{"k":3},"marks":[1,2]}');
        $this->assertRoundTrips($fqcn, '{"tint":"plain","size":4,"marks":true}');
        $validator = $this->validate($fqcn, '{"tint":{"hue":"red"}}');
        $this->assertTrue($validator->passes());
        $dto = call_user_func([$fqcn, 'fromValidated'], $validator->validated());
        $this->assertSame(substr($fqcn, 0, (int)strrpos($fqcn, '\\')) . '\Shade', get_debug_type($dto->getTint()));
        foreach (['{"tint":5}', '{"tint":{}}', '{"tint":[1]}', '{"size":"x"}', '{"size":{}}', '{"marks":["x"]}', '{"marks":"x"}'] as $invalid) {
            $this->assertRefused($fqcn, $invalid);
        }
    }

    /**
     * A discriminated `oneOf` whose discriminator is a plain string. A value no variant answers to,
     * and a payload whose fields fit one variant while its discriminator names the other, are refused
     * with a 422; they passed validation and died in `fromValidated()` — `Unknown kind`, or a
     * `TypeError` building a dog out of a cat's fields. Written inline with no mapping, the union was
     * not dispatched at all, and every payload, a valid one included, reached the constructor as an
     * array.
     *
     * @param array<string, string>|null $mapping
     * @param array<int, string> $valid
     * @param array<int, string> $invalid
     */
    #[DataProvider('plainDiscriminatorProvider')]
    public function testAPlainStringDiscriminatorIsCheckedBeforeHydration(?array $mapping, array $valid, array $invalid): void
    {
        $discriminator = ['propertyName' => 'kind'];
        if ($mapping !== null) {
            $discriminator['mapping'] = array_map(static fn(string $name): string => '#/components/schemas/' . $name, $mapping);
        }
        $fqcn = $this->generate('plain discriminator ' . json_encode($mapping), [
            'Cat' => ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['kind', 'bark'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'boolean']]],
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => $discriminator,
            ],
            'Widget' => ['type' => 'object', 'properties' => [
                'pet' => ['$ref' => '#/components/schemas/Animal'],
                'pets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Animal']],
                // The same union written on the property, with no component to carry its mapping.
                'inline' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => $discriminator,
                ],
            ]],
        ], 'Widget');

        foreach ($valid as $pet) {
            $this->assertRoundTrips($fqcn, '{"pet":' . $pet . '}');
            $this->assertRoundTrips($fqcn, '{"pets":[' . $pet . ']}');
            $this->assertRoundTrips($fqcn, '{"inline":' . $pet . '}');
        }
        foreach ($invalid as $pet) {
            $this->assertRefused($fqcn, '{"pet":' . $pet . '}');
            $this->assertRefused($fqcn, '{"pets":[' . $pet . ']}');
            $this->assertRefused($fqcn, '{"inline":' . $pet . '}');
        }
    }

    /**
     * @return iterable<string, array{array<string, string>|null, array<int, string>, array<int, string>}>
     */
    public static function plainDiscriminatorProvider(): iterable
    {
        yield 'implicit mapping' => [
            null,
            ['{"kind":"Cat","lives":1}', '{"kind":"Dog","bark":true}'],
            ['{"kind":"Cow","lives":1}', '{"kind":"Dog","lives":1}', '{"kind":"Cat","bark":true}', '{"lives":1}'],
        ];
        yield 'explicit mapping' => [
            ['c' => 'Cat', 'd' => 'Dog'],
            ['{"kind":"c","lives":1}', '{"kind":"d","bark":false}'],
            ['{"kind":"zz","lives":1}', '{"kind":"Cat","lives":1}', '{"kind":"d","lives":1}'],
        ];
    }

    /**
     * JSON `true` and `false` are not numbers, in any position an integer or a number is declared.
     * Laravel's `integer` rule reads `true` as `1`; the raw-body check let it through because it only
     * looked for strings — and the typed constructor, the enum's `from()` or an `array<int>` got a bool.
     */
    #[DataProvider('booleanForNumberProvider')]
    public function testABooleanIsRefusedWhereANumberIsDeclared(string $valid, string $invalid): void
    {
        $fqcn = $this->generate('boolean for number', [
            'Level' => ['type' => 'integer', 'enum' => [1, 2]],
            'Inner' => ['type' => 'object', 'required' => ['beta'], 'properties' => ['beta' => ['type' => 'integer']]],
            'Widget' => ['type' => 'object', 'properties' => [
                'count' => ['type' => 'integer'],
                // A list, so that an absent optional nullable property (written back as `null`) stays out of the round trip.
                'maybes' => ['type' => 'array', 'items' => ['type' => ['integer', 'null']]],
                'big' => ['type' => 'integer', 'format' => 'int64'],
                'ratio' => ['type' => 'number'],
                'level' => ['$ref' => '#/components/schemas/Level'],
                'counts' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'levels' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Level']],
                'inner' => ['$ref' => '#/components/schemas/Inner'],
                'inners' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']],
                'flag' => ['type' => 'boolean'],
            ]],
        ], 'Widget');

        $this->assertRoundTrips($fqcn, $valid);
        $this->assertRefused($fqcn, $invalid);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function booleanForNumberProvider(): iterable
    {
        yield 'integer' => ['{"count":1}', '{"count":true}'];
        yield 'integer false' => ['{"count":0}', '{"count":false}'];
        yield 'nullable integer' => ['{"maybes":[null,1]}', '{"maybes":[true]}'];
        yield 'int64' => ['{"big":5}', '{"big":true}'];
        yield 'number' => ['{"ratio":1.5}', '{"ratio":true}'];
        yield 'int-backed enum' => ['{"level":1}', '{"level":true}'];
        yield 'list of integers' => ['{"counts":[1,2]}', '{"counts":[true]}'];
        yield 'map of enums' => ['{"levels":{"a":2}}', '{"levels":{"a":true}}'];
        yield 'integer of a referenced class' => ['{"inner":{"beta":3}}', '{"inner":{"beta":true}}'];
        yield 'integer of a list item class' => ['{"inners":[{"beta":3}]}', '{"inners":[{"beta":false}]}'];
        // The control: a boolean is still a boolean, and a number is still not one.
        yield 'boolean' => ['{"flag":true}', '{"flag":1}'];
        yield 'numeric string' => ['{"count":2}', '{"count":"2"}'];
    }

    /**
     * One component in two discriminated unions. Each union's interface declares its discriminator
     * constants, and a class implementing both inherited two `DISCRIMINATOR_PROPERTY` constants — a
     * fatal "ambiguous" error on autoload, with nothing said at generation. Every class has to load, and
     * each union still dispatches on its own discriminator values.
     */
    public function testAComponentInTwoDiscriminatedUnionsLoads(): void
    {
        $fqcn = $this->generate('component in two unions', [
            'Cat' => ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['kind', 'bark'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'boolean']]],
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['c' => '#/components/schemas/Cat', 'd' => '#/components/schemas/Dog']],
            ],
            'Widget' => ['type' => 'object', 'properties' => [
                'animal' => ['$ref' => '#/components/schemas/Animal'],
                'pet' => ['$ref' => '#/components/schemas/Pet'],
            ]],
        ], 'Widget');

        // Loaded in a process of its own first: the ambiguity is a compile-time fatal, which no
        // `try` catches and which would take this whole run down with it.
        $namespace = substr($fqcn, 0, (int)strrpos($fqcn, '\\'));
        $directory = $this->outputDirectory . '/' . $namespace;
        $script = sprintf(
            'spl_autoload_register(static function (string $c): void { $f = %s . "/" . substr($c, %d) . ".php"; if (is_file($f)) { require $f; } });'
                . ' foreach (["Cat", "Dog", "Widget"] as $c) { class_exists(%s . $c); } echo "loaded";',
            var_export($directory, true),
            strlen($namespace) + 1,
            var_export($namespace . '\\', true),
        );
        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        $this->assertSame('loaded', trim($output));
        $this->assertRoundTrips($fqcn, '{"animal":{"kind":"Cat","lives":1},"pet":{"kind":"d","bark":true}}');
        $this->assertRefused($fqcn, '{"pet":{"kind":"Cat","lives":1}}');
        $this->assertRefused($fqcn, '{"animal":{"kind":"c","lives":1}}');
    }

    /**
     * Two unions that discriminate one shared variant by DIFFERENT properties leave no single
     * `DISCRIMINATOR_PROPERTY` for the variant to restate: generation says so, instead of writing a
     * class that cannot load.
     */
    public function testAVariantOfUnionsDisagreeingOnTheDiscriminatorIsRefusedAtGeneration(): void
    {
        $member = static fn(string $other): array => [
            'type' => 'object',
            'required' => ['kind', 'sort'],
            'properties' => ['kind' => ['type' => 'string'], 'sort' => ['type' => 'string'], $other => ['type' => 'integer']],
        ];
        $union = static fn(string $propertyName): array => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'discriminator' => ['propertyName' => $propertyName],
        ];

        $this->expectExceptionMessage('Cat is a variant of the discriminated unions Animal, Pet, which discriminate by different properties (kind, sort)');
        $this->generate('unions disagreeing on the discriminator', [
            'Cat' => $member('lives'),
            'Dog' => $member('bones'),
            'Animal' => $union('kind'),
            'Pet' => $union('sort'),
        ], 'Cat');
    }

    /**
     * A header and a cookie arrive as strings whatever they declare. A boolean, a number and a list
     * are read into their declared type the way a path or a query parameter already was; Laravel's
     * `boolean` rule refused `"true"` and its `list` rule refused `"1,2"`, so a valid request got a 422.
     */
    public function testAHeaderAndACookieAreReadIntoTheirDeclaredType(): void
    {
        $namespace = 'LvPathParams';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => ['/api/shapes/{on}' => ['get' => [
                    'operationId' => 'getShapes',
                    'parameters' => [
                        ['name' => 'on', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'boolean']],
                        ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ['name' => 'X-Flag', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'boolean']],
                        ['name' => 'X-Ids', 'in' => 'header', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                        ['name' => 'X-Ratio', 'in' => 'header', 'schema' => ['type' => 'number']],
                        ['name' => 'crisp', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'boolean']],
                        ['name' => 'X-Name', 'in' => 'header', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                ]]],
            ],
            outputDirectory: $target,
            namespace: $namespace,
            mode: 'laravel',
        );
        if (!class_exists(\Illuminate\Foundation\Http\FormRequest::class)) {
            require_once __DIR__ . '/Stub/form-request.php';
        }
        $files = glob($target . '/*QueryParams*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;
        }
        $dtoClass = $namespace . '\\' . basename((string)current(array_filter($files, static fn(string $f): bool => !str_contains($f, 'FormRequest'))), '.php');
        $formRequestClass = $dtoClass . 'FormRequest';
        $factory = $this->validatorFactory();
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$dtoClass, 'rules']);
        $validate = static function (string $uri, array $headers, array $cookies) use ($formRequestClass, $factory, $rules): \Illuminate\Validation\Validator {
            $request = $formRequestClass::create(uri: $uri, method: 'GET', cookies: $cookies, server: $headers);
            $route = new Route(methods: ['GET'], uri: 'api/shapes/{on}', action: []);
            $route->bind($request);
            $request->setRouteResolver(static fn(): Route => $route);

            return $factory->make($request->validationData(), $rules);
        };

        $validator = $validate(
            '/api/shapes/true?active=true',
            ['HTTP_X_FLAG' => 'true', 'HTTP_X_IDS' => '1,2', 'HTTP_X_RATIO' => '1.5', 'HTTP_X_NAME' => 'true'],
            ['crisp' => 'false'],
        );
        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
        $dto = call_user_func([$dtoClass, 'fromValidated'], $validator->validated());
        $this->assertSame(
            expected: ['on' => true, 'active' => true, 'X-Flag' => true, 'X-Ids' => [1, 2], 'X-Ratio' => 1.5, 'crisp' => false, 'X-Name' => 'true'],
            actual: $dto->toArray(),
        );

        $validator = $validate('/api/shapes/false', ['HTTP_X_FLAG' => '0', 'HTTP_X_IDS' => '7'], ['crisp' => '1']);
        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame(
            expected: ['on' => false, 'X-Flag' => false, 'X-Ids' => [7], 'crisp' => true],
            actual: call_user_func([$dtoClass, 'fromValidated'], $validator->validated())->toArray(),
        );

        $failed = array_keys($validate('/api/shapes/true', ['HTTP_X_FLAG' => 'maybe', 'HTTP_X_IDS' => '1,x', 'HTTP_X_RATIO' => 'many'], ['crisp' => 'yes'])->errors()->toArray());
        sort($failed);
        $this->assertSame(['X-Flag', 'X-Ids.1', 'X-Ratio', 'crisp'], $failed);
    }

    /**
     * The payload passes the rules and the raw-body interpreter, hydrates, comes back out of the DTO
     * as it went in, and that output passes again.
     *
     * @param class-string $fqcn
     */
    private function assertRoundTrips(string $fqcn, string $json): void
    {
        $validator = $this->validate($fqcn, $json);
        $this->assertTrue($validator->passes(), $json . ' must pass: ' . json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
        $dto = call_user_func([$fqcn, 'fromValidated'], $validator->validated());
        $output = json_encode($dto, JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), json_decode($output, true, 512, JSON_THROW_ON_ERROR), $json);
        $this->assertTrue($this->validate($fqcn, $output)->passes(), 'output of ' . $json . ' must pass again');
    }

    /**
     * An optional object with a schema `default` PHP has no constant for (README.laravel, "An optional
     * property with a schema `default`"): not applied, so an absent key is null, as in the other modes.
     * It was declared non-nullable with nothing to fall back on, and `fromValidated()` died with a
     * TypeError on every payload that left the key out. A scalar default beside it is still applied.
     */
    public function testAnAbsentObjectWithADefaultIsNullNotATypeError(): void
    {
        $inner = ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]];
        $fqcn = $this->generate(
            key: 'object-default',
            schemas: [
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
            rootClass: 'Probe',
        );

        $this->assertRoundTrips($fqcn, '{}');
        $this->assertRoundTrips($fqcn, '{"e":{"x":2},"g":{"x":3}}');
        $this->assertRefused($fqcn, '{"e":null}');

        $dto = call_user_func([$fqcn, 'fromValidated'], []);
        $this->assertNull($dto->getE());
        $this->assertNull($dto->getG());
        $this->assertSame(4, $dto->getN());
        $this->assertFalse($dto->isEProvided());
    }

    /**
     * A number past the float range (`1e400`) decodes to INF: `multiple_of` threw `MathException` on it
     * — a 500 — and `numeric` let it into the DTO, which `toJson()` could not write. Refused by the
     * validator now, at every depth; a finite multiple still passes.
     */
    public function testANumberPastTheFloatRangeIsRefusedByTheValidator(): void
    {
        $fqcn = $this->generate(
            key: 'overflowing-number',
            schemas: [
                'Probe' => [
                    'type' => 'object',
                    'properties' => [
                        'step' => ['type' => 'number', 'multipleOf' => 0.01],
                        'count' => ['type' => 'integer', 'multipleOf' => 3],
                        'halves' => ['type' => 'array', 'items' => ['type' => 'number', 'multipleOf' => 0.5]],
                        'ratio' => ['type' => 'number'],
                    ],
                ],
            ],
            rootClass: 'Probe',
        );

        foreach (['{"step":1e400}', '{"step":-1e400}', '{"count":1e400}', '{"halves":[1e400]}', '{"ratio":1e400}', '{"step":0.015}', '{"step":"x"}'] as $json) {
            $this->assertRefused($fqcn, $json);
        }
        $this->assertRoundTrips($fqcn, '{"step":0.02,"count":3,"halves":[0.5],"ratio":1.5}');
    }

    /**
     * The payload is refused by the validator — a 422 — and not by an exception thrown on the way.
     *
     * @param class-string $fqcn
     */
    private function assertRefused(string $fqcn, string $json): void
    {
        try {
            $refused = $this->validate($fqcn, $json)->fails();
        } catch (Throwable $exception) {
            $this->fail($json . ' threw instead of failing validation: ' . $exception->getMessage());
        }
        $this->assertTrue($refused, $json . ' must be refused');
    }

    /**
     * @param class-string $fqcn
     */
    private function validate(string $fqcn, string $json): \Illuminate\Validation\Validator
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $validator = $this->validatorFactory()->make($payload, $rules);
        call_user_func([$fqcn, 'withValidator'], $validator, $json);

        return $validator;
    }

    private function validatorFactory(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     * @return class-string
     */
    private function generate(string $key, array $schemas, string $rootClass): string
    {
        $namespace = 'LvPath' . substr(md5($key), 0, 10);
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray(
            openApi: ['openapi' => '3.1.0', 'info' => ['title' => 'T', 'version' => '1.0.0'], 'components' => ['schemas' => $schemas]],
            outputDirectory: $target,
            namespace: $namespace,
            mode: 'laravel',
        );
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
        $fqcn = $namespace . '\\' . $rootClass;

        return $fqcn;
    }
}
