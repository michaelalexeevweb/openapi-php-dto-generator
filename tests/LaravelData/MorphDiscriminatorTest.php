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
            condition: $base->implementsInterface(PropertyMorphableData::class),
            message: 'without PropertyMorphableData laravel-data never calls morph()',
        );

        // The member inherits the discriminator rather than redeclaring it: a redeclared readonly
        // property is a compile-time fatal, so this is what keeps the emitted file loadable at all.
        $member = new ReflectionClass($namespace . '\Circle');
        $this->assertSame($namespace . '\Shape', $member->getParentClass()?->getName());
        $this->assertFalse(
            condition: $member->hasProperty($phpName) && $member->getProperty($phpName)->getDeclaringClass()->getName() === $member->getName(),
            message: 'the member must not redeclare the inherited discriminator property',
        );
    }

    #[DataProvider('discriminatorNameProvider')]
    public function testThePayloadIsHydratedIntoTheMemberTheDiscriminatorNames(string $wireName, string $phpName): void
    {
        $namespace = $this->generate($wireName);
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $circle = LaravelDataContainer::withRequest(
            json: sprintf('{"shape":{"%s":"circle","r":3}}', $wireName),
            work: static fn(Request $request): object => $holder::from($request),
        );
        $square = LaravelDataContainer::withRequest(
            json: sprintf('{"shape":{"%s":"square","a":4}}', $wireName),
            work: static fn(Request $request): object => $holder::from($request),
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
            json: sprintf('{"shape":{"%s":"nope","a":4}}', $wireName),
            work: static fn(Request $request): object => $holder::from($request),
        );
    }

    /**
     * The union as the ROOT of a payload: laravel-data picks the member class before it validates, so an
     * unknown or missing discriminator was `CannotCreateAbstractClass` — a 500 — where the nested case
     * above is a 422. The base checks it first, under the discriminator's wire name.
     */
    #[DataProvider('discriminatorNameProvider')]
    public function testAnUnknownDiscriminatorAtTheRootIsAValidationError(string $wireName): void
    {
        $namespace = $this->generate($wireName);
        /** @var class-string $shape */
        $shape = $namespace . '\Shape';

        $circle = LaravelDataContainer::withRequest(
            json: sprintf('{"%s":"circle","r":3}', $wireName),
            work: static fn(Request $request): object => $shape::from($request),
        );
        $this->assertInstanceOf($namespace . '\Circle', $circle);

        foreach ([sprintf('{"%s":"nope"}', $wireName), '{}'] as $json) {
            try {
                LaravelDataContainer::withRequest($json, static fn(Request $request): object => $shape::from($request));
                $this->fail('an unknown discriminator must not build: ' . $json);
            } catch (ValidationException $exception) {
                $this->assertSame([$wireName], array_keys($exception->errors()), $json);
            }
        }

        $this->expectException(ValidationException::class);
        $shape::validateAndCreate([$wireName => 'nope']);
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
            json: sprintf('{"shape":{"%s":"circle"}}', $wireName),
            work: static fn(Request $request): object => $holder::from($request),
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

    /**
     * A value that is not an object where a discriminated union is expected — nested, as a list item, as
     * a map value, two classes down, inside a member. laravel-data reads the discriminator out of that
     * value before any rule runs, and a string, a number or an item's null there was a `TypeError` from
     * `array_key_exists()` — a 500 — where the same value on a plain object property is a 422.
     *
     * @param array<int, string> $errorKeys
     */
    #[DataProvider('nonObjectUnionPositionProvider')]
    public function testANonObjectWhereAUnionIsExpectedIsAValidationError(string $json, array $errorKeys): void
    {
        $namespace = $this->generateSpec(spec: self::positionSpec(), salt: 'positions');
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';

        $errors = LaravelDataContainer::withRequest(
            json: $json,
            work: static function (Request $request) use ($holder): ?array {
                try {
                    $holder::from($request);

                    return null;
                } catch (ValidationException $exception) {
                    return $exception->errors();
                }
            },
        );

        $this->assertIsArray($errors, 'refused as a validation error: ' . $json);
        $this->assertSame($errorKeys, array_keys($errors), $json . ' ' . json_encode($errors));

        // The array path goes through the same validation.
        try {
            $holder::validateAndCreate((array)json_decode($json, true));
            $this->fail('validateAndCreate() must refuse it too: ' . $json);
        } catch (ValidationException $exception) {
            $this->assertSame($errorKeys, array_keys($exception->errors()), $json);
        }
    }

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function nonObjectUnionPositionProvider(): iterable
    {
        yield 'a string for the union' => ['{"pet":"x"}', ['pet']];
        yield 'a number for the union' => ['{"pet":5}', ['pet']];
        yield 'null in a list' => ['{"pets":[null]}', ['pets.0']];
        yield 'a number in a list' => ['{"pets":[{"kind":"cat","lives":9},1]}', ['pets.1']];
        yield 'a string in a list' => ['{"pets":["x"]}', ['pets.0']];
        yield 'null as a map value' => ['{"byName":{"a":null}}', ['byName.a']];
        yield 'a number as a map value' => ['{"byName":{"a":5}}', ['byName.a']];
        yield 'two classes down' => ['{"kennel":{"pet":"x"}}', ['kennel.pet']];
        yield 'in a list of classes' => ['{"kennels":[{"pet":"x"}]}', ['kennels.0.pet']];
        yield 'inside the member chosen' => ['{"pet":{"kind":"dog","size":2,"friend":"x"}}', ['pet.friend']];
        yield 'null item of a nullable list' => ['{"maybePets":[null]}', ['maybePets.0']];
        // Neighbours: what was a 422 already stays one.
        yield 'a string for a plain object' => ['{"kennel":"x"}', ['kennel']];
        // The union's own rules answer a null, under its discriminator; the interpreter, which checks the
        // union by the member its tag names since 2.15.153, refuses the null object as well.
        yield 'null for the union' => ['{"pet":null}', ['pet.kind', 'pet']];
        yield 'a string for the list' => ['{"pets":"x"}', ['pets']];
        // Judged by the tag alone: a member the tag does not name is not asked for its fields.
        yield 'an unmapped discriminator' => ['{"pet":{"kind":"cow"}}', ['pet.kind']];
    }

    /**
     * The valid payloads of the same document still hydrate, each into the member its discriminator
     * names, and come back out unchanged.
     */
    public function testEveryUnionPositionStillHydrates(): void
    {
        $namespace = $this->generateSpec(spec: self::positionSpec(), salt: 'positions');
        /** @var class-string $holder */
        $holder = $namespace . '\Holder';
        $json = '{"pet":{"kind":"dog","size":2,"friend":{"kind":"cat","lives":9}},'
            . '"pets":[{"kind":"cat","lives":1}],"byName":{"a":{"kind":"dog","size":3}},'
            . '"kennel":{"pet":{"kind":"cat","lives":2}},"kennels":[{"pet":{"kind":"dog","size":4}}],"maybePets":[]}';

        $dto = LaravelDataContainer::withRequest($json, static fn(Request $request): object => $holder::from($request));

        $this->assertInstanceOf($namespace . '\Dog', $dto->pet);
        $this->assertInstanceOf($namespace . '\Cat', $dto->pet->friend);
        $this->assertInstanceOf($namespace . '\Cat', $dto->pets[0]);
        $this->assertInstanceOf($namespace . '\Dog', $dto->byName['a']);
        // Equal, not identical: a member writes its inherited discriminator last.
        $this->assertEquals(json_decode($json, true), json_decode($dto->toJson(), true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function positionSpec(): array
    {
        $pet = ['$ref' => '#/components/schemas/Pet'];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'properties' => [
                            'pet' => $pet,
                            'pets' => ['type' => 'array', 'items' => $pet],
                            'byName' => ['type' => 'object', 'additionalProperties' => $pet],
                            'kennel' => ['$ref' => '#/components/schemas/Kennel'],
                            'kennels' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Kennel']],
                            'maybePets' => ['type' => 'array', 'items' => ['oneOf' => [$pet, ['type' => 'null']]]],
                        ],
                    ],
                    'Kennel' => ['type' => 'object', 'properties' => ['pet' => $pet]],
                    'Pet' => [
                        'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog'],
                        ],
                    ],
                    'Cat' => [
                        'type' => 'object',
                        'required' => ['kind', 'lives'],
                        'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']],
                    ],
                    // A member that holds the union again: the walk follows the payload, not a fixed depth.
                    'Dog' => [
                        'type' => 'object',
                        'required' => ['kind', 'size'],
                        'properties' => ['kind' => ['type' => 'string'], 'size' => ['type' => 'integer'], 'friend' => $pet],
                    ],
                ],
            ],
        ];
    }

    private function generate(string $wireName): string
    {
        return $this->generateSpec(spec: self::spec($wireName), salt: $wireName);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function generateSpec(array $spec, string $salt): string
    {
        $namespace = 'MorphProbe' . substr(md5((string)$this->name() . $salt), 0, 8);
        $this->outputDirectory = sys_get_temp_dir() . '/ld-morph-' . strtolower($namespace);
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray(
            openApi: $spec,
            outputDirectory: $this->outputDirectory,
            namespace: $namespace,
            mode: GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
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
