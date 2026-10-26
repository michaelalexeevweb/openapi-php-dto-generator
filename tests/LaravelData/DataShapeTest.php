<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

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

        $base = new ReflectionClass($namespace . '\Palette');
        $this->assertTrue($base->isAbstract());
        $this->assertTrue($base->implementsInterface(PropertyMorphableData::class));

        /** @var class-string $holder */
        $holder = $namespace . '\Holder';
        $built = LaravelDataContainer::withRequest(
            '{"palette":{"kind":"warm","score":3},"palettes":[{"kind":"cold","depth":2},{"kind":"warm","score":1}]}',
            static fn(Request $request): object => $holder::from($request),
        );

        $this->assertInstanceOf($namespace . '\WarmPalette', $built->palette);
        $this->assertSame(3, $built->palette->score);
        $this->assertInstanceOf($namespace . '\ColdPalette', $built->palettes[0]);
        $this->assertSame(
            ['palette' => ['score' => 3, 'kind' => 'warm'], 'palettes' => [['depth' => 2, 'kind' => 'cold'], ['score' => 1, 'kind' => 'warm']]],
            $built->toArray(),
        );

        $this->expectException(ValidationException::class);
        LaravelDataContainer::withRequest(
            '{"palette":{"kind":"warm"},"palettes":[]}',
            static fn(Request $request): object => $holder::from($request),
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
