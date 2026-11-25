<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use EmptyJsonContainer\Probe;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * `[]` and `{}` decode to the same empty PHP array, so an empty JSON array passed wherever an object
 * was asked for, and an object keyword (`required`, `minProperties`) judged an empty array as an
 * empty object. The runtime decodes the body itself and keeps every JSON object a `stdClass`, so it
 * still knows which of the two arrived: `[]` is refused for an object, and an object keyword leaves
 * an array alone, as JSON Schema says. A value handed over already decoded (`deserializeValue()`)
 * cannot be told apart and keeps reading `[]` as `{}`.
 */
final class EmptyJsonContainerTest extends TestCase
{
    private static ?string $root = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== null) {
            self::remove(self::$root);
            self::$root = null;
        }
    }

    #[DataProvider('bodies')]
    public function testAnEmptyContainerIsJudgedByWhatArrived(string $json, bool $accepted): void
    {
        $this->assertSame(expected: $accepted, actual: self::accepts(json: $json), message: $json);
    }

    public static function bodies(): iterable
    {
        // `type: object` with no properties, a typed map and a generated DTO: an object only.
        yield 'free-form object given []' => ['{"free":[]}', false];
        yield 'free-form object given {}' => ['{"free":{}}', true];
        yield 'map given []' => ['{"map":[]}', false];
        yield 'map given {}' => ['{"map":{}}', true];
        yield 'dto given []' => ['{"inner":[]}', false];
        yield 'dto given {}' => ['{"inner":{}}', true];
        yield 'list of dtos given [[]]' => ['{"inners":[[]]}', false];
        yield 'list of dtos given [{}]' => ['{"inners":[{}]}', true];
        // `type: array`: the mirror case was already refused; pinned beside it.
        yield 'list given {}' => ['{"list":{}}', false];
        yield 'list given []' => ['{"list":[]}', true];
        // No `type`: an object keyword constrains objects only, so an array passes it.
        yield 'required given []' => ['{"needsX":[]}', true];
        yield 'required given {}' => ['{"needsX":{}}', false];
        yield 'required given {"x":1}' => ['{"needsX":{"x":1}}', true];
        yield 'minProperties given []' => ['{"atLeastOne":[]}', true];
        yield 'minProperties given {}' => ['{"atLeastOne":{}}', false];
    }

    /**
     * A DTO built by hand is judged as it will be written: an untyped empty array goes out as `[]`,
     * so the object keywords leave it alone; an empty object goes out as `{}` and is held to them; a
     * typed map's empty array goes out as `{}` and is an object.
     */
    public function testTheNormalizerJudgesAFieldAsItWillBeWritten(): void
    {
        self::generate();
        $normalizer = new DtoNormalizer();
        $writes = static function (object $dto) use ($normalizer): ?string {
            try {
                return json_encode($normalizer->validateAndNormalizeToArray($dto), JSON_THROW_ON_ERROR);
            } catch (RuntimeException) {
                return null;
            }
        };

        $this->assertSame(expected: '{"needsX":[]}', actual: $writes(new Probe(needsX: [])));
        $this->assertNull($writes(new Probe(needsX: new stdClass())));
        $this->assertSame(expected: '{"needsX":{"x":1}}', actual: $writes(new Probe(needsX: (object)['x' => 1])));
        $this->assertSame(expected: '{"map":{}}', actual: $writes(new Probe(map: [])));
    }

    /**
     * `{}` in a union of a list and an object is held as the empty PHP array its `array` type allows,
     * and the deserializer notes that it arrived as an object. Re-validating the DTO judged that
     * empty array as the list member (`minItems: 1` refused it), and the generated `toArray()`,
     * `jsonSerialize()` and `__toString()` wrote it back as `[]` — on the top level and nested.
     */
    #[DataProvider('listOrObjectBodies')]
    public function testAnEmptyObjectBesideAListMemberIsJudgedAndWrittenAsAnObject(string $json, ?string $written): void
    {
        self::generate();
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            $dto = (new DtoDeserializer())->deserialize($request, 'EmptyJsonContainer\Probe');
            $normalized = json_encode((new DtoNormalizer())->validateAndNormalizeToArray($dto), JSON_THROW_ON_ERROR);
        } catch (RuntimeException) {
            $this->assertNull(actual: $written, message: $json . ' was refused');

            return;
        }
        $this->assertInstanceOf(expected: Probe::class, actual: $dto);
        $this->assertSame(expected: $written, actual: $normalized, message: 'DtoNormalizer');
        $this->assertSame(expected: $written, actual: json_encode($dto->toArray(), JSON_THROW_ON_ERROR), message: 'toArray()');
        $this->assertIsArray($dto->toArray());
        $this->assertSame(expected: $written, actual: json_encode($dto, JSON_THROW_ON_ERROR), message: 'jsonSerialize()');
        $this->assertSame(expected: $written, actual: (string)$dto, message: '__toString()');
        $this->assertSame(expected: $written, actual: $dto->toJson(), message: 'toJson()');
    }

    public static function listOrObjectBodies(): iterable
    {
        yield 'empty object' => ['{"fox":{}}', '{"fox":{}}'];
        yield 'object' => ['{"fox":{"a":1}}', '{"fox":{"a":1}}'];
        yield 'list' => ['{"fox":[1]}', '{"fox":[1]}'];
        yield 'empty list, short of minItems' => ['{"fox":[]}', null];
        yield 'nested empty object' => ['{"inner":{"fox":{}}}', '{"inner":{"fox":{}}}'];
        yield 'nested empty list' => ['{"inner":{"fox":[]}}', '{"inner":{"fox":[]}}'];
        yield 'empty objects in a list of dtos' => ['{"inners":[{"fox":{}},{"fox":[]}]}', '{"inners":[{"fox":{}},{"fox":[]}]}'];
    }

    public function testAValueHandedOverDecodedKeepsReadingAnEmptyArrayAsAnObject(): void
    {
        self::generate();

        // A caller's own `json_decode($json, true)` turned `{}` into `[]`: nothing tells it apart.
        $inner = (new DtoDeserializer())->deserializeValue(data: [], type: 'EmptyJsonContainer\Inner');

        $this->assertInstanceOf(expected: 'EmptyJsonContainer\Inner', actual: $inner);
    }

    private static function accepts(string $json): bool
    {
        self::generate();
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            (new DtoDeserializer())->deserialize($request, 'EmptyJsonContainer\Probe');
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private static function generate(): void
    {
        if (self::$root !== null) {
            return;
        }
        self::$root = sys_get_temp_dir() . '/opg-empty-container-' . bin2hex(random_bytes(8));
        mkdir(self::$root);
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'properties' => [
                            'free' => ['type' => 'object'],
                            'map' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                            'inner' => ['$ref' => '#/components/schemas/Inner'],
                            'inners' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']],
                            'list' => ['type' => 'array'],
                            'needsX' => ['required' => ['x']],
                            'atLeastOne' => ['minProperties' => 1],
                            'fox' => ['anyOf' => [
                                ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                                ['type' => 'object'],
                            ]],
                        ],
                    ],
                    'Inner' => ['type' => 'object', 'properties' => [
                        'x' => ['type' => 'integer'],
                        'fox' => ['anyOf' => [['type' => 'array', 'items' => ['type' => 'integer']], ['type' => 'object']]],
                    ]],
                ]],
            ],
            outputDirectory: self::$root,
            namespace: 'EmptyJsonContainer',
        );
        foreach (glob(self::$root . '/*.php') ?: [] as $file) {
            require_once $file;
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
