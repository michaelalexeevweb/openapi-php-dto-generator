<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\LaravelData\Contracts\PropertyMorphableData;

/**
 * A discriminated union in laravel-data mode, driven through the package.
 *
 * This is the one place the emitted class SHAPE differs between modes rather than just its attributes.
 * The others emit an interface for the union base; laravel-data cannot hydrate an interface — it dies
 * with a `TypeError` on the constructor — and has its own mechanism instead: an abstract `Data` base
 * implementing `PropertyMorphableData`, whose `morph()` reads the discriminator before there is an
 * object to read it from.
 *
 * The normalization parity suite covers the happy path (the right member, and what it normalizes to).
 * What is here is the rest: that the base really is abstract and morphable, that an unmapped
 * discriminator value is a 422 rather than a crash, and that a member does not redeclare the inherited
 * discriminator property — which would be a fatal, not a test failure.
 */
final class MorphDiscriminatorTest extends TestCase
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

    #[DataProvider('discriminatorNameProvider')]
    public function testTheBaseIsAnAbstractMorphableDataClass(string $wireName, string $phpName): void
    {
        $namespace = $this->generate($wireName);

        $base = new ReflectionClass($namespace . '\Shape');
        $this->assertTrue($base->isAbstract(), 'the union base must be abstract, not an interface');
        $this->assertTrue(
            $base->implementsInterface(PropertyMorphableData::class),
            'without PropertyMorphableData laravel-data never calls morph()',
        );

        // The member inherits the discriminator rather than redeclaring it: a redeclared readonly
        // property is a compile-time fatal, so this is what keeps the emitted file loadable at all.
        $member = new ReflectionClass($namespace . '\Circle');
        $this->assertSame($namespace . '\Shape', $member->getParentClass()?->getName());
        $this->assertFalse(
            $member->hasProperty($phpName) && $member->getProperty($phpName)->getDeclaringClass()->getName() === $member->getName(),
            'the member must not redeclare the inherited discriminator property',
        );
    }

    #[DataProvider('discriminatorNameProvider')]
    public function testThePayloadIsHydratedIntoTheMemberTheDiscriminatorNames(string $wireName, string $phpName): void
    {
        $namespace = $this->generate($wireName);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $circle = LaravelDataContainer::withRequest(
            sprintf('{"shape":{"%s":"circle","r":3}}', $wireName),
            static fn(Request $request): object => $holder::from($request),
        );
        $square = LaravelDataContainer::withRequest(
            sprintf('{"shape":{"%s":"square","a":4}}', $wireName),
            static fn(Request $request): object => $holder::from($request),
        );

        $this->assertInstanceOf($namespace . '\Circle', $circle->shape);
        $this->assertInstanceOf($namespace . '\Square', $square->shape);
        $this->assertSame(3, $circle->shape->r);
        $this->assertSame('circle', $circle->shape->{$phpName});
        $this->assertSame(4, $square->shape->a);

        // And back out under the name the document uses, not the PHP one.
        $this->assertSame(['shape' => ['r' => 3, $wireName => 'circle']], $circle->toArray());
    }

    /**
     * An unmapped discriminator value must be a validation failure — a 422 — and not an exception the
     * application has to translate. laravel-data validates the morph itself, which is why `morph()`
     * returning null is enough.
     */
    #[DataProvider('discriminatorNameProvider')]
    public function testAnUnmappedDiscriminatorValueIsRejectedAsAValidationError(string $wireName): void
    {
        $namespace = $this->generate($wireName);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $this->expectException(ValidationException::class);
        LaravelDataContainer::withRequest(
            sprintf('{"shape":{"%s":"nope","a":4}}', $wireName),
            static fn(Request $request): object => $holder::from($request),
        );
    }

    /**
     * A member's own required property is still enforced after the morph picked the class.
     */
    #[DataProvider('discriminatorNameProvider')]
    public function testTheChosenMemberStillValidatesItsOwnProperties(string $wireName): void
    {
        $namespace = $this->generate($wireName);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $this->expectException(ValidationException::class);
        LaravelDataContainer::withRequest(
            sprintf('{"shape":{"%s":"circle"}}', $wireName),
            static fn(Request $request): object => $holder::from($request),
        );
    }

    /**
     * Every case runs twice: once with a discriminator whose wire name is already a PHP identifier, and
     * once with one that is not. The second is not a variation on the first — the morph base reads the
     * discriminator BEFORE there is an object, by property name and by input-mapped name
     * (`DataMorphClassResolver`), so a base without the mapping attribute never finds the value and a
     * payload the other three modes hydrate comes back as `validation.required`.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function discriminatorNameProvider(): array
    {
        return [
            'a name that is already a PHP identifier' => ['kind', 'kind'],
            'a name that has to be mapped' => ['pet_type', 'petType'],
        ];
    }

    private function generate(string $wireName): string
    {
        $namespace = 'MorphProbe' . substr(md5((string)$this->name() . $wireName), 0, 8);
        $this->outputDirectory = sys_get_temp_dir() . '/ld-morph-' . strtolower($namespace);
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray(
            self::spec($wireName),
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

    /**
     * @return array<string, mixed>
     */
    private static function spec(string $wireName): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['shape'],
                        'properties' => ['shape' => ['$ref' => '#/components/schemas/Shape']],
                    ],
                    'Shape' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/Circle'],
                            ['$ref' => '#/components/schemas/Square'],
                        ],
                        'discriminator' => [
                            'propertyName' => $wireName,
                            'mapping' => [
                                'circle' => '#/components/schemas/Circle',
                                'square' => '#/components/schemas/Square',
                            ],
                        ],
                    ],
                    'Circle' => [
                        'type' => 'object',
                        'required' => [$wireName, 'r'],
                        'properties' => [$wireName => ['type' => 'string'], 'r' => ['type' => 'integer']],
                    ],
                    'Square' => [
                        'type' => 'object',
                        'required' => [$wireName, 'a'],
                        'properties' => [$wireName => ['type' => 'string'], 'a' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
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
