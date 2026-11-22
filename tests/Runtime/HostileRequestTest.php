<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Requests a hostile client can send to an ordinary document. Each one must be answered — accepted or
 * refused, as the document says — in bounded time, and must not be read from a place the document
 * never put it.
 */
final class HostileRequestTest extends TestCase
{
    /**
     * Generous: every case here finishes in milliseconds once it is linear. The bound only has to
     * separate that from the exponential or quadratic curve, which needs seconds at these sizes.
     */
    private const float TIME_BOUND_SECONDS = 1.0;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-hostile-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    // --- recursive unions without a discriminator ---------------------------------------------

    public function testDeepRecursiveAnyOfIsHydratedInBoundedTime(): void
    {
        $namespace = $this->generateRecursiveUnion(keyword: 'anyOf', withNull: false);
        $body = $this->nestedUnionBody(kinds: array_fill(start_index: 0, count: 16, value: 'b'), leafKind: 'b');

        $started = hrtime(true);
        $dto = $this->deserializeJson(class: $namespace . '\Tree', body: $body);
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);

        $this->assertInstanceOf($namespace . '\B', $dto->getNode());
        $this->assertSame($body, $dto->toJson());
        $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        $this->assertSame($body, $this->deserializeJson(class: $namespace . '\Tree', body: $dto->toJson())->toJson());
    }

    public function testDeepRecursiveAnyOfWithAWrongLeafIsRefusedInBoundedTime(): void
    {
        $namespace = $this->generateRecursiveUnion(keyword: 'anyOf', withNull: false);
        $body = $this->nestedUnionBody(kinds: array_fill(start_index: 0, count: 15, value: 'b'), leafKind: 'zebra');

        $started = hrtime(true);
        try {
            $this->deserializeJson(class: $namespace . '\Tree', body: $body);
            $this->fail('A tree with a leaf of neither kind must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zebra', $exception->getMessage());
        }
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);
    }

    /**
     * The other spelling of the same shape: a nullable union, `null` written as a third branch.
     */
    public function testDeepRecursiveNullableOneOfIsHydratedInBoundedTime(): void
    {
        $namespace = $this->generateRecursiveUnion(keyword: 'oneOf', withNull: true);
        $body = $this->nestedUnionBody(kinds: array_fill(start_index: 0, count: 16, value: 'b'), leafKind: 'a');

        $started = hrtime(true);
        $dto = $this->deserializeJson(class: $namespace . '\Tree', body: $body);
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);

        $this->assertSame($body, $dto->toJson());
        $this->assertSame($body, $this->deserializeJson(class: $namespace . '\Tree', body: $dto->toJson())->toJson());
    }

    /**
     * The branch a value lands in is what the work-saving must not touch: at every level the kind
     * decides, and a shallow wrong leaf reports what it reported before.
     */
    public function testRecursiveUnionStillPicksTheBranchEachLevelNames(): void
    {
        foreach ([['anyOf', false], ['oneOf', false], ['anyOf', true]] as [$keyword, $withNull]) {
            $namespace = $this->generateRecursiveUnion(keyword: $keyword, withNull: $withNull);
            $body = $this->nestedUnionBody(kinds: ['a', 'b', 'a', 'a'], leafKind: 'b');

            $dto = $this->deserializeJson(class: $namespace . '\Tree', body: $body);
            $kinds = [];
            for ($node = $dto->getNode(); is_object($node); $node = $node->getC()) {
                $kinds[] = substr(strrchr($node::class, '\\') ?: '', 1);
            }
            $this->assertSame(expected: ['A', 'B', 'A', 'A', 'B'], actual: $kinds, message: $keyword);
            $this->assertSame(expected: $body, actual: $dto->toJson(), message: $keyword);

            try {
                $this->deserializeJson(class: $namespace . '\Tree', body: $this->nestedUnionBody(kinds: ['a'], leafKind: 'zebra'));
                $this->fail(sprintf('%s: a wrong leaf must be refused.', $keyword));
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('zebra', $exception->getMessage(), $keyword);
                $this->assertStringContainsString('node.c.k', $exception->getMessage(), $keyword);
            }
        }
    }

    /**
     * Work saved on one value must not be handed to another: two sibling objects with the same
     * content are two values, each hydrated as its own branch.
     */
    public function testSiblingObjectsWithTheSameContentAreHydratedSeparately(): void
    {
        $namespace = $this->generateRecursiveUnion(keyword: 'anyOf', withNull: false);
        $body = '{"node":{"k":"b","c":{"k":"a"}},"spare":{"k":"b","c":{"k":"a"}}}';

        $dto = $this->deserializeJson(class: $namespace . '\Tree', body: $body);
        $this->assertInstanceOf($namespace . '\B', $dto->getNode());
        $this->assertInstanceOf($namespace . '\B', $dto->getSpare());
        $this->assertNotSame($dto->getNode(), $dto->getSpare());
        $this->assertInstanceOf($namespace . '\A', $dto->getSpare()->getC());
        $this->assertNotSame($dto->getNode()->getC(), $dto->getSpare()->getC());
        $this->assertSame($body, $dto->toJson());
    }

    /**
     * The same tree sent as form fields or a `deepObject` query parameter: nested arrays rather than
     * decoded JSON objects, so nothing about the value itself tells one position from another. PHP
     * nests such a name up to `max_input_nesting_level` (64) deep, which is 2^64 the exponential way.
     */
    public function testDeepRecursiveUnionFromFormFieldsAndQueryIsHydratedInBoundedTime(): void
    {
        $namespace = $this->generateRecursiveUnionOverForm();
        $node = $this->nestedUnionNode(kinds: array_fill(start_index: 0, count: 16, value: 'b'), leafKind: 'b');

        $started = hrtime(true);
        $dto = $this->deserializeForm(
            class: $namespace . '\Tree',
            body: http_build_query(['node' => $node]),
            fields: ['node' => $node],
        );
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);
        $this->assertSame(expected: (string)json_encode(['node' => $node]), actual: $dto->toJson());

        $started = hrtime(true);
        $params = (new DtoDeserializer())->deserialize(
            request: Request::create(uri: '/trees?' . http_build_query(['q' => $node])),
            dtoClass: $namespace . '\TreesPostQueryParams',
        );
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);
        $this->assertInstanceOf($namespace . '\B', $params->getQ());

        $wrong = $this->nestedUnionNode(kinds: array_fill(start_index: 0, count: 15, value: 'b'), leafKind: 'zebra');
        $started = hrtime(true);
        try {
            $this->deserializeForm(
                class: $namespace . '\Tree',
                body: http_build_query(['node' => $wrong]),
                fields: ['node' => $wrong],
            );
            $this->fail('A tree with a leaf of neither kind must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zebra', $exception->getMessage());
        }
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Form fields pick their branch at every level exactly as JSON does, and report a wrong leaf at
     * the same place.
     */
    public function testRecursiveUnionFromFormFieldsStillPicksTheBranchEachLevelNames(): void
    {
        $namespace = $this->generateRecursiveUnionOverForm();
        $node = $this->nestedUnionNode(kinds: ['a', 'b', 'a', 'a'], leafKind: 'b');

        $dto = $this->deserializeForm(
            class: $namespace . '\Tree',
            body: http_build_query(['node' => $node]),
            fields: ['node' => $node],
        );
        $kinds = [];
        for ($current = $dto->getNode(); is_object($current); $current = $current->getC()) {
            $kinds[] = substr(strrchr($current::class, '\\') ?: '', 1);
        }
        $this->assertSame(expected: ['A', 'B', 'A', 'A', 'B'], actual: $kinds);

        $wrong = $this->nestedUnionNode(kinds: ['a'], leafKind: 'zebra');
        try {
            $this->deserializeForm(
                class: $namespace . '\Tree',
                body: http_build_query(['node' => $wrong]),
                fields: ['node' => $wrong],
            );
            $this->fail('A wrong leaf must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zebra', $exception->getMessage());
            $this->assertStringContainsString('node.c.k', $exception->getMessage());
        }
    }

    /**
     * A map's keys come from the request, and a key may itself hold a dot: `swatches[x.y][z]` and
     * `swatches[x][y.z]` are two values that read the same written as one dotted path. Work saved
     * inside a union must still give each of them its own content.
     */
    public function testDottedMapKeysInsideAUnionKeepTheirOwnValues(): void
    {
        $namespace = $this->generateRecursiveUnionOverForm();
        $node = ['k' => 'a', 'swatches' => ['x.y' => ['z' => ['tint' => 'red']], 'x' => ['y.z' => ['tint' => 'blue']]]];

        $dto = $this->deserializeForm(
            class: $namespace . '\Tree',
            body: http_build_query(['node' => $node]),
            fields: ['node' => $node],
        );
        $swatches = $dto->getNode()->getSwatches();
        $this->assertSame(expected: 'red', actual: $swatches['x.y']['z']->getTint());
        $this->assertSame(expected: 'blue', actual: $swatches['x']['y.z']->getTint());
        $this->assertNotSame($swatches['x.y']['z'], $swatches['x']['y.z']);
    }

    // --- the form-body re-parse and PHP's input limits ----------------------------------------

    public function testCollidingFormKeysCostNoMoreThanPhpsOwnParserAllows(): void
    {
        $namespace = $this->generateFormBody();
        // Keys built from `Ez` and `FY` share one hash in PHP's (unrandomized) string hash, so every
        // one of them lands in the same bucket of whatever array they are inserted into.
        $keys = [''];
        for ($block = 0; $block < 16; $block++) {
            $longer = [];
            foreach ($keys as $key) {
                $longer[] = $key . 'Ez';
                $longer[] = $key . 'FY';
            }
            $keys = $longer;
        }
        $body = 'email=a%40b.c&topics=red&topics=blue&' . implode('=1&', $keys) . '=1';

        $started = hrtime(true);
        $dto = $this->deserializeForm(
            class: $namespace . '\SubscribePostRequest',
            body: $body,
            // What PHP itself builds for `$_POST`: capped at max_input_vars, last repeat wins.
            fields: ['email' => 'a@b.c', 'topics' => 'blue'],
        );
        $this->assertLessThan(self::TIME_BOUND_SECONDS, (hrtime(true) - $started) / 1e9);

        $this->assertSame(['red', 'blue'], $dto->getTopics());
    }

    public function testRepeatedFormAndQueryKeysAreStillCollected(): void
    {
        $namespace = $this->generateFormBody();

        $dto = $this->deserializeForm(
            class: $namespace . '\SubscribePostRequest',
            body: 'email=a%40b.c&topics=red&topics=blue+sky',
            fields: ['email' => 'a@b.c', 'topics' => 'blue sky'],
        );
        $this->assertSame(['red', 'blue sky'], $dto->getTopics());
        $this->assertSame('a@b.c', $dto->getEmail());

        $request = Request::create(uri: '/subscribe?colors=red&colors=green');
        $params = (new DtoDeserializer())->deserialize($request, $namespace . '\SubscribePostQueryParams');
        $this->assertSame(['red', 'green'], $params->getColors());
    }

    /**
     * Past `max_input_vars` PHP stops reading pairs, so `$_POST` never holds a later one. The re-read
     * stops at the same pair: a repeat PHP did not see is not collected either.
     */
    public function testFormPairsPastMaxInputVarsAreNotCollected(): void
    {
        $namespace = $this->generateFormBody();
        $limit = (int)ini_get('max_input_vars') ?: 1000;
        $filler = implode(
            separator: '&',
            array: array_map(
                callback: static fn(int $index): string => 'filler' . $index . '=1',
                array: range(start: 1, end: $limit - 3),
            ),
        );

        $dto = $this->deserializeForm(
            class: $namespace . '\SubscribePostRequest',
            body: 'email=a%40b.c&topics=red&' . $filler . '&topics=blue&' . $filler . '&topics=green',
            fields: ['email' => 'a@b.c', 'topics' => 'blue'],
        );
        $this->assertSame(['red', 'blue'], $dto->getTopics());
    }

    /**
     * PHP drops a query variable nested deeper than `max_input_nesting_level`, earlier pairs under
     * the same name included. The raw re-read an `allowReserved` parameter goes through drops it the
     * same way, so the two readings still differ only in how `+` decodes.
     */
    public function testQueryNestedPastPhpsLimitIsDroppedAsPhpDropsIt(): void
    {
        $namespace = $this->generateFormBody();
        $limit = (int)ini_get('max_input_nesting_level') ?: 64;
        $deep = 'note' . str_repeat(string: '[a]', times: $limit + 1) . '=1';
        $deepest = 'note' . str_repeat(string: '[a]', times: $limit) . '=1';

        $dropped = Request::create(uri: '/subscribe?note=a+b&' . $deep);
        $this->assertFalse($dropped->query->has('note'));
        $params = (new DtoDeserializer())->deserialize($dropped, $namespace . '\SubscribePostQueryParams');
        $this->assertNull($params->getNote());

        $kept = Request::create(uri: '/subscribe?note=a+b');
        $this->assertSame('a+b', (new DtoDeserializer())->deserialize($kept, $namespace . '\SubscribePostQueryParams')->getNote());

        $nested = Request::create(uri: '/subscribe?' . $deepest);
        $this->assertIsArray($nested->query->all()['note'] ?? null);
        try {
            (new DtoDeserializer())->deserialize($nested, $namespace . '\SubscribePostQueryParams');
            $this->fail('A nested value for a string parameter must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('note', $exception->getMessage());
        }
    }

    // --- a JSON-only body is read from the JSON body only --------------------------------------

    public function testJsonOnlyBodyIsNotFilledFromTheQueryString(): void
    {
        $namespace = $this->generateJsonAndFormBodies();

        foreach (['WidgetsPostRequest', 'Gadget'] as $class) {
            $request = Request::create(uri: '/widgets/7?name=ab&shape=round', method: 'POST');
            $this->assertRefused(request: $request, class: $namespace . '\\' . $class, message: 'Required parameter "name" not found');
        }
    }

    public function testJsonOnlyBodyIsNotFilledFromFormFields(): void
    {
        $namespace = $this->generateJsonAndFormBodies();

        foreach (['WidgetsPostRequest', 'Gadget'] as $class) {
            foreach (['application/x-www-form-urlencoded', 'multipart/form-data; boundary=x'] as $contentType) {
                $request = Request::create(
                    uri: '/widgets/7',
                    method: 'POST',
                    parameters: ['name' => 'ab', 'shape' => 'round'],
                    server: ['CONTENT_TYPE' => $contentType],
                    content: 'name=ab&shape=round',
                );
                $this->assertRefused(request: $request, class: $namespace . '\\' . $class, message: 'Required parameter "name" not found');
            }
        }
    }

    public function testJsonOnlyBodyStillReadsTheJsonBodyBesideAQueryString(): void
    {
        $namespace = $this->generateJsonAndFormBodies();

        foreach (['WidgetsPostRequest', 'Gadget'] as $class) {
            $request = Request::create(
                uri: '/widgets/7?name=zz&shape=square',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"name":"ab","shape":"round"}',
            );
            $dto = (new DtoDeserializer())->deserialize($request, $namespace . '\\' . $class);
            $this->assertSame(expected: '{"name":"ab","shape":"round"}', actual: $dto->toJson(), message: $class);

            $partial = Request::create(
                uri: '/widgets/7?shape=square',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"name":"ab"}',
            );
            $dto = (new DtoDeserializer())->deserialize($partial, $namespace . '\\' . $class);
            $this->assertSame(expected: '{"name":"ab"}', actual: $dto->toJson(), message: $class);
        }

        $request = Request::create(uri: '/widgets/7?colors=red&colors=green');
        $request->attributes->set('id', '7');
        $params = (new DtoDeserializer())->deserialize($request, $namespace . '\WidgetsPostQueryParams');
        $this->assertSame(['red', 'green'], $params->getColors());
        $this->assertSame(7, $params->getId());
    }

    /**
     * A multipart post carries uploads beside its fields, and a cross-site form can send them as
     * freely. A JSON body has no file in it, so a JSON-only body takes none, even for a property
     * typed as one (`format: binary`); a body the document declares as multipart keeps its files.
     */
    public function testJsonOnlyBodyIsNotFilledFromUploadedFiles(): void
    {
        $namespace = $this->generateJsonAndFormBodies();
        $upload = $this->root . '/upload.txt';
        file_put_contents($upload, 'alpha');

        foreach (['BlobsPostRequest' => false, 'BlobsFormPostRequest' => true] as $class => $filled) {
            $request = Request::create(
                uri: '/blobs',
                method: 'POST',
                parameters: ['label' => 'ab'],
                files: ['blob' => new UploadedFile(path: $upload, originalName: 'upload.txt', test: true)],
                server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'],
            );
            $dto = (new DtoDeserializer())->deserialize($request, $namespace . '\\' . $class);
            $this->assertSame(expected: $filled, actual: $dto->getBlob() instanceof UploadedFile, message: $class);
            $this->assertSame(expected: $filled ? 'ab' : null, actual: $dto->getLabel(), message: $class);
        }
    }

    /**
     * Where the document DOES describe a form body — alone, or beside JSON on the same component —
     * the form fields are its fields, and they keep filling it.
     */
    public function testFormDeclaredBodiesAreStillFilledFromFormFields(): void
    {
        $namespace = $this->generateJsonAndFormBodies();

        foreach (['FormsPostRequest', 'Sprocket'] as $class) {
            $request = Request::create(
                uri: '/forms',
                method: 'POST',
                parameters: ['name' => 'ab', 'shape' => 'round'],
                server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
                content: 'name=ab&shape=round',
            );
            $dto = (new DtoDeserializer())->deserialize($request, $namespace . '\\' . $class);
            $this->assertSame(expected: '{"name":"ab","shape":"round"}', actual: $dto->toJson(), message: $class);
        }

        $json = Request::create(
            uri: '/sprockets',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"ab"}',
        );
        $this->assertSame('{"name":"ab"}', (new DtoDeserializer())->deserialize($json, $namespace . '\Sprocket')->toJson());
    }

    /**
     * A class no operation names as its body — a nested component, a hand-written DTO, one generated
     * before the media types were recorded — says nothing about where it comes from, so it keeps the
     * permissive lookup it always had.
     */
    public function testClassWithNoDeclaredBodyKeepsTheWaterfall(): void
    {
        $namespace = $this->generateJsonAndFormBodies();

        $request = Request::create(uri: '/x?name=ab', method: 'POST');
        $dto = (new DtoDeserializer())->deserialize($request, $namespace . '\Loose');
        $this->assertSame('{"name":"ab"}', $dto->toJson());
    }

    // --- a pattern the regex engine gives up on, inside the objects of a list ------------------

    /**
     * Every member of a list of objects is hydrated and validated as a DTO of its own, so a `pattern`
     * the engine gives up on cost a whole backtrack limit per member (~2 ms each, ~19 s for ten
     * thousand). The first member that reports the engine failure settles the request — it is refused
     * either way — so the members after it must not be looked at, at whatever depth the list sits.
     */
    #[DataProvider('engineLimitInsideObjectsProvider')]
    public function testEngineFailureInsideAListOfObjectsStopsTheWalk(array $schemas, string $validBody, string $burningBody): void
    {
        $class = $this->generate(['components' => ['schemas' => $schemas]]) . '\Probe';
        // Warm-up: the classes load and their metadata is cached before the clock starts.
        $this->deserializeJson(class: $class, body: $validBody);

        $started = hrtime(true);
        $message = $this->refusalOf(fn(): mixed => $this->deserializeJson(class: $class, body: $burningBody));
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertStringContainsString('the regex engine gave up on pattern', $message);
        $this->assertLessThan(self::TIME_BOUND_SECONDS, $seconds);
        // One member's failure, not ten thousand of them in the message.
        $this->assertSame(1, substr_count($message, 'the regex engine gave up on pattern'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function engineLimitInsideObjectsProvider(): iterable
    {
        $named = self::namedWidget();
        $burning = ['name' => str_repeat('a', 26) . '!'];
        $refWidget = ['$ref' => '#/components/schemas/Widget'];

        yield 'inline object items' => [
            ['Probe' => self::probe(['type' => 'array', 'items' => $named])],
            '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}',
            (string)json_encode(['f' => array_fill(start_index: 0, count: 10000, value: $burning)]),
        ];

        yield '$ref items' => [
            ['Probe' => self::probe(['type' => 'array', 'items' => $refWidget]), 'Widget' => $named],
            '{"f":[{"name":"alpha beta"},{"name":"gamma"}]}',
            (string)json_encode(['f' => array_fill(start_index: 0, count: 10000, value: $burning)]),
        ];

        // A list of objects inside an object inside a list: the inner list stops at its first member,
        // and the outer one must stop at the member that carried that failure up.
        yield 'list of objects inside an object inside a list' => [
            [
                'Probe' => self::probe(['type' => 'array', 'items' => $refWidget]),
                'Widget' => [
                    'type' => 'object',
                    'required' => ['colors'],
                    'properties' => ['colors' => ['type' => 'array', 'items' => $named]],
                ],
            ],
            '{"f":[{"colors":[{"name":"red"}]},{"colors":[{"name":"green blue"}]}]}',
            (string)json_encode(value: [
                'f' => array_fill(
                    start_index: 0,
                    count: 100,
                    value: ['colors' => array_fill(start_index: 0, count: 100, value: $burning)],
                ),
            ]),
        ];

        yield 'map of $ref values' => [
            [
                'Probe' => self::probe(['type' => 'object', 'additionalProperties' => $refWidget]),
                'Widget' => $named,
            ],
            '{"f":{"alpha":{"name":"alpha beta"},"beta":{"name":"gamma"}}}',
            (string)json_encode(value: [
                'f' => array_combine(
                    keys: array_map(callback: static fn(int $i): string => 'k' . $i, array: range(0, 9999)),
                    values: array_fill(start_index: 0, count: 10000, value: $burning),
                ),
            ]),
        ];
    }

    /**
     * The neighbour the early stop must leave alone: an ordinary mismatch is a verdict, not a failure
     * of the engine, so every member that breaks the pattern is still reported, nested ones included.
     */
    public function testOrdinaryMismatchesInsideAListOfObjectsAreAllReported(): void
    {
        $class = $this->generate(openApi: ['components' => ['schemas' => [
            'Probe' => self::probe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Widget']]),
            'Widget' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => self::namedWidget()['properties']['name'],
                    'colors' => ['type' => 'array', 'items' => self::namedWidget()],
                ],
            ],
        ]]]) . '\Probe';

        $message = $this->refusalOf(call: fn(): mixed => $this->deserializeJson(
            class: $class,
            body: '{"f":[{"name":"a-b"},{"name":"ok"},{"name":"c-d","colors":[{"name":"e-f"},{"name":"ok"},{"name":"g-h"}]}]}',
        ));

        foreach (['f.0.name', 'f.2.name', 'f.2.colors.0.name', 'f.2.colors.2.name'] as $path) {
            $this->assertStringContainsString($path, $message);
        }
        $this->assertStringNotContainsString('f.1.name', $message);
        $this->assertStringNotContainsString('gave up', $message);
    }

    /**
     * A collection body walks its elements with the same per-member hydration, so it stops the same
     * way at the first element the engine gave up on.
     */
    public function testEngineFailureInACollectionBodyStopsTheWalk(): void
    {
        $collection = $this->widgetCollection();
        $this->assertCount(2, $collection('[{"name":"alpha beta"},{"name":"gamma"}]'));

        $burningBody = (string)json_encode(array_fill(start_index: 0, count: 10000, value: ['name' => str_repeat('a', 26) . '!']));
        $started = hrtime(true);
        $message = $this->refusalOf(static fn(): array => $collection($burningBody));
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertStringContainsString('the regex engine gave up on pattern', $message);
        $this->assertLessThan(self::TIME_BOUND_SECONDS, $seconds);
        $this->assertSame(1, substr_count($message, 'the regex engine gave up on pattern'));
    }

    /**
     * The neighbour of the case above: an ordinary mismatch still names every element that has one.
     */
    public function testOrdinaryMismatchesInACollectionBodyAreAllReported(): void
    {
        $collection = $this->widgetCollection();

        $mismatches = $this->refusalOf(static fn(): array => $collection('[{"name":"a-b"},{"name":"ok"},{"name":"c-d"}]'));
        $this->assertStringContainsString('Element 0:', $mismatches);
        $this->assertStringContainsString('Element 2:', $mismatches);
        $this->assertStringNotContainsString('Element 1:', $mismatches);
    }

    /**
     * @return callable(string): array<int, mixed> a JSON body in, the deserialized collection of Widgets out
     */
    private function widgetCollection(): callable
    {
        $class = $this->generate(['components' => ['schemas' => ['Widget' => self::namedWidget()]]]) . '\Widget';
        $deserializer = new DtoDeserializer();

        return static fn(string $body): array => $deserializer->deserializeCollection(
            request: Request::create(
                uri: '/',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $body,
            ),
            itemType: $class,
        );
    }

    /**
     * A "words" pattern with a nested quantifier: an ordinary-looking document pattern that backtracks
     * catastrophically on a long run of letters followed by one character it does not admit.
     *
     * @return array<string, mixed>
     */
    private static function namedWidget(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^([a-zA-Z0-9]+\s?)*$']],
        ];
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private static function probe(array $propertySchema): array
    {
        return ['type' => 'object', 'required' => ['f'], 'properties' => ['f' => $propertySchema]];
    }

    private function refusalOf(callable $call): string
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
        $this->fail('The payload must be refused.');
    }

    private function assertRefused(Request $request, string $class, string $message): void
    {
        try {
            (new DtoDeserializer())->deserialize($request, $class);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage(), $class);

            return;
        }
        $this->fail(sprintf('%s must not be filled from outside the JSON body.', $class));
    }

    /**
     * @param list<string> $kinds
     */
    private function nestedUnionBody(array $kinds, string $leafKind): string
    {
        return (string)json_encode(['node' => $this->nestedUnionNode(kinds: $kinds, leafKind: $leafKind)]);
    }

    /**
     * @param list<string> $kinds
     * @return array<string, mixed>
     */
    private function nestedUnionNode(array $kinds, string $leafKind): array
    {
        $node = ['k' => $leafKind];
        foreach (array_reverse($kinds) as $kind) {
            $node = ['k' => $kind, 'c' => $node];
        }

        return $node;
    }

    private function generateRecursiveUnion(string $keyword, bool $withNull): string
    {
        $branches = [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']];
        $union = [$keyword => $withNull ? [...$branches, ['type' => 'null']] : $branches];

        return $this->generate(openApi: [
            'paths' => [],
            'components' => ['schemas' => [
                'Tree' => ['type' => 'object', 'properties' => ['node' => $union, 'spare' => $union]],
                'A' => ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string', 'enum' => ['a']], 'c' => $union]],
                'B' => ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string', 'enum' => ['b']], 'c' => $union]],
            ]],
        ]);
    }

    /**
     * The recursive union of `generateRecursiveUnion()`, the tree arriving as a form body and the
     * union also as a `deepObject` query parameter; `A` carries a map of maps for the dotted keys.
     */
    private function generateRecursiveUnionOverForm(): string
    {
        $union = ['anyOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]];
        $swatches = ['type' => 'object', 'additionalProperties' => [
            'type' => 'object',
            'additionalProperties' => ['$ref' => '#/components/schemas/Swatch'],
        ]];

        return $this->generate(openApi: [
            'paths' => [
                '/trees' => ['post' => [
                    'parameters' => [['name' => 'q', 'in' => 'query', 'style' => 'deepObject', 'schema' => $union]],
                    'requestBody' => ['content' => ['application/x-www-form-urlencoded' => ['schema' => ['$ref' => '#/components/schemas/Tree']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
            'components' => ['schemas' => [
                'Tree' => ['type' => 'object', 'properties' => ['node' => $union]],
                'A' => ['type' => 'object', 'required' => ['k'], 'properties' => [
                    'k' => ['type' => 'string', 'enum' => ['a']],
                    'c' => $union,
                    'swatches' => $swatches,
                ]],
                'B' => ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string', 'enum' => ['b']], 'c' => $union]],
                'Swatch' => ['type' => 'object', 'properties' => ['tint' => ['type' => 'string']]],
            ]],
        ]);
    }

    private function generateFormBody(): string
    {
        return $this->generate(openApi: [
            'paths' => [
                '/subscribe' => ['post' => [
                    'parameters' => [
                        ['name' => 'colors', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ['name' => 'note', 'in' => 'query', 'allowReserved' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => [
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string'],
                            'topics' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string']],
                        ],
                    ]]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
        ]);
    }

    private function generateJsonAndFormBodies(): string
    {
        $fields = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'shape' => ['type' => 'string']]];
        $blob = ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'blob' => ['type' => 'string', 'format' => 'binary']]];

        return $this->generate(openApi: [
            'paths' => [
                '/widgets/{id}' => ['post' => [
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ['name' => 'colors', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $fields]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/gadgets' => ['post' => [
                    'requestBody' => ['content' => ['application/merge-patch+json' => ['schema' => ['$ref' => '#/components/schemas/Gadget']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/blobs' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => $blob]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/blobs/form' => ['post' => [
                    'requestBody' => ['content' => ['multipart/form-data' => ['schema' => $blob]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/forms' => ['post' => [
                    'requestBody' => ['content' => ['application/x-www-form-urlencoded' => ['schema' => $fields]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/sprockets' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Sprocket']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/sprockets/form' => ['post' => [
                    'requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/Sprocket']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
            'components' => ['schemas' => [
                'Gadget' => $fields,
                'Sprocket' => $fields,
                'Loose' => $fields,
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $openApi
     */
    private function generate(array $openApi): string
    {
        $namespace = 'Hostile' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: ['openapi' => '3.1.0', 'info' => ['title' => 'Hostile', 'version' => '1.0.0'], ...$openApi],
            outputDirectory: $directory,
            namespace: $namespace,
        );
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }

    private function deserializeJson(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    /**
     * @param array<string, mixed> $fields what PHP itself parsed into `$_POST`
     */
    private function deserializeForm(string $class, string $body, array $fields): mixed
    {
        $request = Request::create(
            uri: '/subscribe',
            method: 'POST',
            parameters: $fields,
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
