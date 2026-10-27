<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Spatie\LaravelData\Optional;

/**
 * How a laravel-data class is shaped where the document says more than one class can: the allOf
 * inheritance pattern, a path parameter, an optional argument.
 */
final class DataShapeTest extends TestCase
{
    private string $outputDirectory = '';

    protected function setUp(): void
    {
        LaravelDataContainer::boot();
    }

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    /**
     * A base with a discriminator whose variants `allOf` it is the abstract morph base the variants
     * extend, as a `oneOf` one is. Kept a plain class, a property typed with it hydrated the base and
     * dropped every field a variant adds — in a list as well as alone.
     */
    public function testAnAllOfBaseHydratesTheVariantItsDiscriminatorNames(): void
    {
        $namespace = $this->generate([
            'Holder' => [
                'type' => 'object',
                'required' => ['palette', 'palettes'],
                'properties' => [
                    'palette' => ['$ref' => '#/components/schemas/Palette'],
                    'palettes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Palette']],
                ],
            ],
            'Palette' => [
                'type' => 'object',
                'required' => ['kind', 'label'],
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['warm', 'cold']],
                    'label' => ['type' => 'string'],
                ],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                    'warm' => '#/components/schemas/WarmPalette',
                    'cold' => '#/components/schemas/ColdPalette',
                ]],
            ],
            'WarmPalette' => ['allOf' => [
                ['$ref' => '#/components/schemas/Palette'],
                ['type' => 'object', 'required' => ['score'], 'properties' => ['score' => ['type' => 'integer']]],
            ]],
            'ColdPalette' => ['allOf' => [
                ['$ref' => '#/components/schemas/Palette'],
                ['type' => 'object', 'required' => ['depth'], 'properties' => ['depth' => ['type' => 'integer']]],
            ]],
        ]);

        $base = new ReflectionClass($namespace . '\Palette');
        $this->assertTrue($base->isAbstract());
        $this->assertTrue($base->implementsInterface(PropertyMorphableData::class));
        // What the base schema declares is readable through the base type, not only through a member.
        $this->assertTrue($base->hasProperty('label'));

        /** @var class-string $holder */
        $holder = $namespace . '\Holder';
        $built = LaravelDataContainer::withRequest(
            '{"palette":{"kind":"warm","label":"a","score":3},"palettes":[{"kind":"cold","label":"b","depth":2},{"kind":"warm","label":"c","score":1}]}',
            static fn(Request $request): object => $holder::from($request),
        );

        $this->assertInstanceOf($namespace . '\WarmPalette', $built->palette);
        $this->assertSame(3, $built->palette->score);
        $this->assertSame('a', $built->palette->label);
        $this->assertInstanceOf($namespace . '\ColdPalette', $built->palettes[0]);
        // The schema's order: the base's properties first, as `allOf` lists them — not the member's own
        // first and the inherited ones after, which is how PHP lists a subclass's properties.
        $this->assertSame(
            [
                'palette' => ['kind' => 'warm', 'label' => 'a', 'score' => 3],
                'palettes' => [['kind' => 'cold', 'label' => 'b', 'depth' => 2], ['kind' => 'warm', 'label' => 'c', 'score' => 1]],
            ],
            $built->toArray(),
        );

        $this->expectException(ValidationException::class);
        LaravelDataContainer::withRequest(
            '{"palette":{"kind":"warm","label":"a"},"palettes":[]}',
            static fn(Request $request): object => $holder::from($request),
        );
    }

    /**
     * An interpreter message is keyed by the field it names, as in Laravel mode: `palette.score`, not
     * `palette` with the path in the sentence. A claim a Laravel rule also makes is reported as that rule.
     */
    public function testAnInterpreterErrorIsKeyedByTheFieldItNames(): void
    {
        $namespace = $this->generate([
            'Holder' => [
                'type' => 'object',
                'required' => ['palette'],
                'properties' => ['palette' => ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Palette']]]],
            ],
            'Palette' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => ['kind' => ['type' => 'string', 'enum' => ['warm', 'cold']]],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                    'warm' => '#/components/schemas/WarmPalette',
                    'cold' => '#/components/schemas/ColdPalette',
                ]],
            ],
            'WarmPalette' => ['allOf' => [
                ['$ref' => '#/components/schemas/Palette'],
                ['type' => 'object', 'required' => ['score'], 'properties' => ['score' => ['type' => 'integer']]],
            ]],
            'ColdPalette' => ['allOf' => [
                ['$ref' => '#/components/schemas/Palette'],
                ['type' => 'object', 'required' => ['depth'], 'properties' => ['depth' => ['type' => 'integer']]],
            ]],
        ]);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';
        $errors = static function (string $json) use ($holder): array {
            try {
                LaravelDataContainer::withRequest($json, static fn(Request $request): object => $holder::from($request));
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        };

        $this->assertSame(['palette.score'], array_keys($errors('{"palette":{"kind":"warm","score":"x"}}')));
        $this->assertSame(['palette.score'], array_keys($errors('{"palette":{"kind":"warm"}}')));
        $this->assertSame([], $errors('{"palette":{"kind":"cold","depth":2}}'));
    }

    /**
     * A property named `2` keeps that name on the way out. laravel-data `array_merge()`s the transformed
     * array with the class's additional data, which renumbers an integer key: `{"2":"view","4":"edit"}`
     * came out `["view","edit"]` — in a nested object and in a list too.
     */
    public function testAnIntegerPropertyNameSurvivesToArray(): void
    {
        $namespace = $this->generate([
            'Grants' => [
                'type' => 'object',
                'required' => ['2', 'label'],
                'properties' => [
                    '2' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    '4' => ['type' => 'string'],
                    '7' => ['type' => 'string'],
                ],
            ],
            'Holder' => [
                'type' => 'object',
                'required' => ['grants', 'all'],
                'properties' => [
                    'grants' => ['$ref' => '#/components/schemas/Grants'],
                    'all' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Grants']],
                ],
            ],
        ]);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $built = LaravelDataContainer::withRequest(
            '{"grants":{"2":"view","label":"a","7":"own"},"all":[{"2":"edit","label":"b","4":"x"}]}',
            static fn(Request $request): object => $holder::from($request),
        );

        $this->assertSame(
            '{"grants":{"2":"view","label":"a","7":"own"},"all":[{"2":"edit","label":"b","4":"x"}]}',
            $built->toJson(),
        );
    }

    /**
     * `Data::from($request)` reads the query and the body; a path parameter is in neither, so its
     * `present` rule failed on every request. `#[FromRouteParameter]` brings it in before the rules run.
     */
    public function testAPathParameterIsReadFromTheRoute(): void
    {
        $namespace = $this->generate([], [
            '/api/test/widgets/{widgetId}' => ['delete' => [
                'operationId' => 'deleteWidget',
                'parameters' => [['name' => 'widgetId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => ['204' => ['description' => 'gone']],
            ]],
        ]);
        $classes = array_map(
            static fn(string $file): string => basename($file, '.php'),
            glob($this->outputDirectory . '/*.php') ?: [],
        );
        $this->assertCount(1, $classes);
        /** @var class-string $params */
        $params = $namespace . '\\' . $classes[0];

        $request = Request::create(uri: '/api/test/widgets/7', method: 'DELETE');
        $route = new Route(methods: ['DELETE'], uri: 'api/test/widgets/{widgetId}', action: []);
        $route->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);
        Container::getInstance()->instance('request', $request);

        try {
            $built = $params::from($request);
        } finally {
            Container::getInstance()->forgetInstance('request');
        }

        $this->assertSame(7, $built->widgetId);
    }

    /**
     * Query parameters are read as the document serializes them, before the rules run: `?ids=1,2` for an
     * `explode: false` list, a lone `?tags=3` as a list of one, `?active=true` for a boolean — and the
     * items of `?levels[]=2` as the ints an int-backed enum is built from. Laravel's `list` and `boolean`
     * rules refused the first three, and the items stayed strings: `"2"` was no `Level`.
     */
    public function testQueryParametersAreReadByTheirStyle(): void
    {
        $namespace = $this->generate(['Level' => ['type' => 'integer', 'enum' => [1, 2, 3]]], [
            '/api/test/widgets' => ['get' => [
                'operationId' => 'listWidgets',
                'parameters' => [
                    ['name' => 'ids', 'in' => 'query', 'style' => 'form', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    ['name' => 'levels', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Level']]],
                    ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                ],
                'responses' => ['200' => ['description' => 'OK']],
            ]],
        ]);
        $classes = array_values(array_filter(
            array_map(static fn(string $file): string => basename($file, '.php'), glob($this->outputDirectory . '/*.php') ?: []),
            static fn(string $class): bool => $class !== 'Level',
        ));
        /** @var class-string $params */
        $params = $namespace . '\\' . $classes[0];
        $build = static function (string $query) use ($params): object {
            Container::getInstance()->instance('request', $request = Request::create(uri: '/api/test/widgets?' . $query, method: 'GET'));
            try {
                return $params::from($request);
            } finally {
                Container::getInstance()->forgetInstance('request');
            }
        };

        $built = $build('ids=1,2&tags=3&levels[]=2&active=true');
        $this->assertSame([1, 2], $built->ids);
        $this->assertSame([3], $built->tags);
        $this->assertSame([2], array_map(static fn(BackedEnum $level): int|string => $level->value, $built->levels));
        $this->assertTrue($built->active);
        $this->assertFalse($build('active=false')->active);

        try {
            $build('ids=1,x&active=yes');
            $this->fail('A value its type cannot hold was accepted.');
        } catch (ValidationException $exception) {
            $failed = array_keys($exception->errors());
            sort($failed);
            $this->assertSame(['active', 'ids.1'], $failed);
        }
    }

    /**
     * A non-empty JSON array sent for an object body is refused: it decodes to the PHP array an object
     * keyed 0..n-1 does, so with every property optional it passed. `[]` is the empty object, as nested.
     */
    public function testAnArrayBodyIsRefusedWhereTheBodyIsAnObject(): void
    {
        $namespace = $this->generate(['Note' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]], [
            '/api/test/notes' => ['post' => [
                'operationId' => 'addNote',
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Note']]]],
                'responses' => ['204' => ['description' => 'OK']],
            ]],
        ]);
        /** @var class-string $note */
        $note = $namespace . '\Note';
        $errors = static function (string $json) use ($note): array {
            try {
                LaravelDataContainer::withRequest($json, static fn(Request $request): object => $note::from($request));
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        };

        $this->assertSame(['payload' => ['JSON body must be an object, got array.']], $errors('["a"]'));
        // `[]` is the empty object, as it is nested: `json_encode([])` is how a PHP client sends one.
        $this->assertSame([], $errors('[]'));
        $this->assertSame([], $errors(' [ ] '));
        $this->assertSame([], $errors('{}'));
        $this->assertSame([], $errors('{"text":"a"}'));
    }

    /**
     * An optional argument is optional in PHP too: `new X(code: 'a')` was an ArgumentCountError for
     * every optional field left out. Left out, it is `Optional` — absent from `toArray()`, as when a
     * payload without the key is hydrated.
     */
    public function testAnOptionalArgumentCanBeLeftOut(): void
    {
        $namespace = $this->generate(['Widget' => [
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string'],
                'note' => ['type' => 'string', 'nullable' => true],
                'extra' => [],
            ],
        ]]);
        /** @var class-string $widget */
        $widget = $namespace . '\Widget';

        $built = new $widget(code: 'a');

        $this->assertInstanceOf(Optional::class, $built->note);
        $this->assertSame(['code' => 'a', 'extra' => null], $built->toArray());
        $this->assertSame(['code' => 'a', 'note' => null, 'extra' => null], (new $widget(code: 'a', note: null))->toArray());
    }

    /**
     * A schema default is the constructor's default, which laravel-data takes for an absent key: the
     * property holds it, as the other modes' getters answer it — it was `Optional`, and a `required` one
     * with a default demanded the key, where runtime, Symfony and Laravel mode take the default.
     */
    public function testAnAbsentKeyTakesItsSchemaDefault(): void
    {
        $namespace = $this->generate(['Page' => [
            'type' => 'object',
            'required' => ['perPage'],
            'properties' => [
                'perPage' => ['type' => 'integer', 'default' => 50],
                'sort' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'cursor' => ['type' => 'string'],
            ],
        ]]);
        /** @var class-string $page */
        $page = $namespace . '\Page';

        $built = LaravelDataContainer::withRequest('{}', static fn(Request $request): object => $page::from($request));

        $this->assertSame(50, $built->perPage);
        $this->assertSame('desc', $built->sort->value);
        $this->assertInstanceOf(Optional::class, $built->cursor);
        $this->assertSame(['perPage' => 50, 'sort' => 'desc'], $built->toArray());
        $this->assertSame(7, LaravelDataContainer::withRequest(
            '{"perPage":7}',
            static fn(Request $request): object => $page::from($request),
        )->perPage);
    }

    /**
     * `toArray()` writes the keys in the schema's order, as the other modes do. laravel-data follows the
     * order the properties are declared, and a promoted property is declared where its parameter is —
     * required first — so a schema declaring `success, message, payload` came out `success, payload,
     * message`.
     */
    public function testKeysComeOutInTheSchemasOrder(): void
    {
        $namespace = $this->generate([
            'Envelope' => [
                'type' => 'object',
                'required' => ['success', 'payload'],
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'payload' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Entry']],
                    'limit' => ['type' => 'integer', 'default' => 25],
                ],
            ],
            'Entry' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        ]);
        /** @var class-string $envelope */
        $envelope = $namespace . '\Envelope';

        $built = LaravelDataContainer::withRequest(
            '{"payload":[{"id":1}],"message":"m","success":true}',
            static fn(Request $request): object => $envelope::from($request),
        );

        $this->assertInstanceOf($namespace . '\Entry', $built->payload[0]);
        $this->assertSame(['success', 'message', 'payload', 'limit'], array_keys($built->toArray()));
        $this->assertSame(
            ['success' => true, 'payload' => [], 'limit' => 25],
            (new $envelope(success: true, payload: []))->toArray(),
        );
    }

    /**
     * A scalar union with an enum member — `oneOf: [$ref Tone, string, integer]` — is the enum case when
     * the value is one of its members and the scalar otherwise, as in Laravel mode. laravel-data's own
     * enum cast took every scalar for the enum: `"8812"` was a 500, "Could not cast enum".
     */
    public function testAScalarUnionWithAnEnumKeepsAValueOutsideIt(): void
    {
        $namespace = $this->generate([
            'Tone' => ['type' => 'string', 'enum' => ['warm', 'cold']],
            'Swatch' => [
                'type' => 'object',
                'required' => ['code'],
                'properties' => [
                    'tone' => ['oneOf' => [
                        ['$ref' => '#/components/schemas/Tone'],
                        ['type' => 'string', 'pattern' => '^\d+$'],
                        ['type' => 'integer'],
                    ]],
                    'code' => ['type' => 'string'],
                ],
            ],
        ]);
        /** @var class-string $swatch */
        $swatch = $namespace . '\Swatch';
        $build = static fn(string $json): object => LaravelDataContainer::withRequest(
            $json,
            static fn(Request $request): object => $swatch::from($request),
        );

        $named = $build('{"tone":"warm","code":"a"}');
        $this->assertInstanceOf($namespace . '\Tone', $named->tone);
        $this->assertSame(['tone' => 'warm', 'code' => 'a'], $named->toArray());
        $this->assertSame('8812', $build('{"tone":"8812","code":"a"}')->tone);
        $this->assertSame(7, $build('{"tone":7,"code":"a"}')->tone);
        $this->assertInstanceOf(Optional::class, $build('{"code":"a"}')->tone);
        $this->assertSame(['tone' => 'cold', 'code' => 'b'], (new $swatch(code: 'b', tone: 'cold'))->toArray());

        $this->expectException(ValidationException::class);
        $build('{"tone":"pale","code":"a"}');
    }

    /**
     * A list of enum values holds the cases its docblock promises. laravel-data casts container items
     * only behind `data.features.cast_and_transform_iterables`, which ships off, so the strings stayed
     * strings and `$item->value` died on them; the constructor picks the cases itself.
     */
    public function testAListOfAnEnumHoldsItsCases(): void
    {
        $namespace = $this->generate([
            'Tone' => ['type' => 'string', 'enum' => ['warm', 'cold']],
            'Palette' => [
                'type' => 'object',
                'properties' => [
                    'tones' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tone']],
                    'byName' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Tone']],
                ],
            ],
        ]);
        /** @var class-string $palette */
        $palette = $namespace . '\Palette';
        /** @var class-string<BackedEnum> $tone */
        $tone = $namespace . '\Tone';

        $built = LaravelDataContainer::withRequest(
            '{"tones":["warm","cold"],"byName":{"sky":"cold"}}',
            static fn(Request $request): object => $palette::from($request),
        );

        $this->assertSame([$tone::from('warm'), $tone::from('cold')], $built->tones);
        $this->assertSame(['sky' => $tone::from('cold')], $built->byName);
        $this->assertSame('{"tones":["warm","cold"],"byName":{"sky":"cold"}}', $built->toJson());
        $this->assertSame([$tone::from('warm')], (new $palette(tones: [$tone::from('warm')]))->tones);
        $this->assertInstanceOf(Optional::class, (new $palette())->tones);
    }

    /**
     * A `format: date` is midnight, as every other mode reads it. Parsed with a bare `Y-m-d`,
     * `createFromFormat()` filled in the current time of day, and an interval filter dropped the rows
     * dated exactly on its bound.
     */
    public function testADateIsMidnight(): void
    {
        $namespace = $this->generate(['Window' => [
            'type' => 'object',
            'required' => ['since'],
            'properties' => ['since' => ['type' => 'string', 'format' => 'date']],
        ]]);
        /** @var class-string $window */
        $window = $namespace . '\Window';

        $built = LaravelDataContainer::withRequest(
            '{"since":"2022-01-01"}',
            static fn(Request $request): object => $window::from($request),
        );

        $this->assertSame('2022-01-01T00:00:00.000000', $built->since->format('Y-m-d\TH:i:s.u'));
        $this->assertSame(['since' => '2022-01-01'], $built->toArray());
    }

    /**
     * @param array<string, mixed> $schemas
     * @param array<string, mixed> $paths
     */
    private function generate(array $schemas, array $paths = []): string
    {
        $namespace = 'DataShape' . substr(md5((string)$this->name()), 0, 8);
        $this->outputDirectory = sys_get_temp_dir() . '/ld-shape-' . strtolower($namespace);
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        $spec = ['openapi' => '3.0.3', 'info' => ['title' => 'T', 'version' => '1.0.0'], 'paths' => $paths];
        if ($schemas !== []) {
            $spec['components'] = ['schemas' => $schemas];
        }
        (new GenerateDtoCommand())->generateFromArray(
            $spec,
            $this->outputDirectory,
            $namespace,
            GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );

        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
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
}
