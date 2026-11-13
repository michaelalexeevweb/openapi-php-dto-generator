<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class GenerationIntegrityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-integrity-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root . '/out');
        file_put_contents($this->root . '/out/previous.txt', 'previous');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }

    private function document(array $schemas): array
    {
        return ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]];
    }

    private function assertPreviousOutputSurvives(): void
    {
        self::assertSame(['.', '..', 'previous.txt'], scandir($this->root . '/out'));
        self::assertSame('previous', file_get_contents($this->root . '/out/previous.txt'));
    }

    #[DataProvider('externalDefinitions')]
    public function testExternalNamesCannotReuseAnotherDocumentsSchema(array $second): void
    {
        $first = ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]];
        file_put_contents($this->root . '/alpha.json', json_encode($this->document(['Item' => $first]), JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/beta.json', json_encode($this->document($second), JSON_THROW_ON_ERROR));
        $root = $this->document(schemas: ['Probe' => ['type' => 'object', 'properties' => [
            'alpha' => ['$ref' => 'alpha.json#/components/schemas/Item'],
            'beta' => ['$ref' => 'beta.json#/components/schemas/Item'],
        ]]]);
        file_put_contents($this->root . '/root.json', json_encode($root, JSON_THROW_ON_ERROR));
        try {
            (new GenerateDtoCommand())->generateFromFile($this->root . '/root.json', $this->root . '/out', 'Integrity');
            self::fail('An external reference silently reused a different document.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Item', $error->getMessage());
            self::assertStringContainsString('beta.json', $error->getMessage());
        }
        $this->assertPreviousOutputSurvives();
    }

    public static function externalDefinitions(): iterable
    {
        yield 'different definition' => [['Item' => ['type' => 'object', 'properties' => ['beta' => ['type' => 'integer']]]]];
        yield 'identical definition, different source' => [['Item' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]]]];
        yield 'missing target' => [[]];
    }

    /**
     * One file reached under two names is one source, not a collision with itself: a hard link on any
     * filesystem, a case variant on a case-insensitive one (macOS, Windows).
     */
    public function testOneFileUnderTwoNamesIsOneSource(): void
    {
        $item = ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]];
        file_put_contents($this->root . '/items.json', json_encode($this->document(['Item' => $item]), JSON_THROW_ON_ERROR));
        link($this->root . '/items.json', $this->root . '/linked.json');
        $names = ['linked.json'];
        if (is_file($this->root . '/ITEMS.json')) {
            $names[] = 'ITEMS.json';
        }
        $properties = ['direct' => ['$ref' => 'items.json#/components/schemas/Item']];
        foreach ($names as $index => $name) {
            $properties['other' . $index] = ['$ref' => $name . '#/components/schemas/Item'];
        }
        file_put_contents($this->root . '/root.json', json_encode($this->document(['Probe' => ['type' => 'object', 'properties' => $properties]]), JSON_THROW_ON_ERROR));

        $generator = new GenerateDtoCommand();
        self::assertSame(2, $generator->generateFromFile($this->root . '/root.json', $this->root . '/out', 'Integrity'));
        self::assertContains('Item.php', scandir($generator->schemaOutputDirectories['Item']));
    }

    /**
     * A `$ref` into ANOTHER file that lands on the null schema is resolved against that file.
     */
    public function testAnExternalNullSchemaAcceptsOnlyNull(): void
    {
        file_put_contents($this->root . '/nothing.json', json_encode($this->document(['Nothing' => ['type' => 'null']]), JSON_THROW_ON_ERROR));
        $root = $this->document(['Probe' => ['type' => 'object', 'required' => ['f'], 'properties' => [
            'f' => ['$ref' => 'nothing.json#/components/schemas/Nothing'],
        ]]]);
        file_put_contents($this->root . '/root.json', json_encode($root, JSON_THROW_ON_ERROR));
        $namespace = 'ExternalNull' . bin2hex(random_bytes(4));
        (new GenerateDtoCommand())->generateFromFile($this->root . '/root.json', $this->root . '/out', $namespace);
        self::assertSame(['.', '..', 'Probe.php'], scandir($this->root . '/out'));
        require_once $this->root . '/out/Probe.php';

        $deserializer = new DtoDeserializer();
        $request = static fn(string $body): Request => Request::create(uri: '/', method: 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertNull($deserializer->deserialize(request: $request('{"f":null}'), dtoClass: $namespace . '\Probe')->getF());
        $this->expectException(RuntimeException::class);
        $deserializer->deserialize(request: $request('{"f":"x"}'), dtoClass: $namespace . '\Probe');
    }

    /**
     * A `$ref` fragment is percent-encoded like any URI fragment (RFC 6901 §6).
     */
    public function testAPercentEncodedPointerResolves(): void
    {
        $document = $this->document(['Probe' => ['type' => 'object', 'properties' => ['c' => ['$ref' => '#/$defs/Big%20Cat']]]]);
        $document['$defs'] = ['Big Cat' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]]];
        self::assertSame(2, (new GenerateDtoCommand())->generateFromArray($document, $this->root . '/out', 'Integrity'));
        self::assertSame(['.', '..', 'BigCat.php', 'Probe.php'], scandir($this->root . '/out'));
    }

    /**
     * An inline object registered twice — once before the reference normalization, once after — is
     * one schema, not a collision with itself. A `$ref` to a `nullable` component, or to the null
     * schema, inside it was normalized in the first copy only.
     */
    #[DataProvider('normalizedReferenceTargets')]
    public function testAnInlineObjectWithANormalizedReferenceIsNotACollision(array $target): void
    {
        $document = $this->document([
            'Kitten' => $target,
            'Basket' => ['type' => 'object', 'properties' => [
                'cushion' => ['type' => 'object', 'properties' => ['kitten' => ['$ref' => '#/components/schemas/Kitten']]],
            ]],
        ]);
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            $out = $this->root . '/out-' . $mode;
            self::assertGreaterThan(0, (new GenerateDtoCommand())->generateFromArray($document, $out, 'Integrity', $mode), $mode);
        }
    }

    public static function normalizedReferenceTargets(): iterable
    {
        yield 'nullable object' => [['type' => 'object', 'nullable' => true, 'properties' => ['id' => ['type' => 'integer']]]];
        yield 'nullable enum' => [['type' => 'string', 'nullable' => true, 'enum' => ['a', 'b']]];
        yield 'null schema' => [['type' => 'null']];
    }

    /**
     * An escape ECMA-262 does not define keeps its PCRE meaning, and the run says so.
     */
    public function testAPcreOnlyEscapeIsReported(): void
    {
        $generator = new GenerateDtoCommand();
        $generator->generateFromArray($this->document(['Probe' => ['type' => 'object', 'properties' => [
            'gap' => ['type' => 'string', 'pattern' => '^\h+$'],
            'code' => ['type' => 'string', 'pattern' => '^\w+\v\\\h$'],
        ], 'patternProperties' => ['^a\Rb$' => ['type' => 'string']]]]), $this->root . '/out', 'Integrity');

        $warnings = array_values(array_filter(
            $generator->getGenerationWarnings(),
            static fn(string $warning): bool => str_contains($warning, 'ECMA-262'),
        ));
        self::assertCount(2, $warnings);
        self::assertStringContainsString('uses \h', $warnings[0]);
        self::assertStringContainsString('uses \R', $warnings[1]);
    }

    public function testRepeatedExternalReferencesAndRecursiveSchemaAreRegisteredOnce(): void
    {
        $item = ['type' => 'object', 'properties' => ['children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']]]];
        file_put_contents($this->root . '/items.json', json_encode($this->document(['Item' => $item]), JSON_THROW_ON_ERROR));
        $root = $this->document(schemas: ['Probe' => ['type' => 'object', 'properties' => [
            'alpha' => ['$ref' => 'items.json#/components/schemas/Item'],
            'beta' => ['$ref' => './items.json#/components/schemas/Item'],
        ]]]);
        file_put_contents($this->root . '/root.json', json_encode($root, JSON_THROW_ON_ERROR));
        $generator = new GenerateDtoCommand();
        self::assertSame(2, $generator->generateFromFile($this->root . '/root.json', $this->root . '/out', 'Integrity'));
        self::assertContains('Item.php', scandir($generator->schemaOutputDirectories['Item']));
        self::assertContains('Probe.php', scandir($this->root . '/out'));
    }

    /**
     * `a/page` → `b/grid` → `b/tiles` → `b/units/units`. Every reference is relative to the document
     * that WRITES it, and from `a/` neither `tiles.json` nor `units/units.json` exists — so a hop
     * resolved against the root document fails instead of landing on the right file by luck.
     *
     * `$shape` is the container nesting around the tile reference in `Grid.groups`, outermost first:
     * `m` a map (`additionalProperties`), `l` a list (`items`). The default is a map of maps.
     */
    private function writeChainedDocuments(string $shape = 'mm'): void
    {
        $groups = ['$ref' => 'tiles.json#/components/schemas/Tile'];
        foreach (array_reverse(str_split($shape)) as $container) {
            $groups = $container === 'm'
                ? ['type' => 'object', 'additionalProperties' => $groups]
                : ['type' => 'array', 'items' => $groups];
        }
        foreach (['a', 'b', 'b/units'] as $directory) {
            mkdir($this->root . '/' . $directory);
        }
        $documents = [
            'a/page.json' => $this->document(['Page' => ['type' => 'object', 'required' => ['grid'], 'properties' => [
                'grid' => ['$ref' => '../b/grid.json#/components/schemas/Grid'],
            ]]]),
            'b/grid.json' => $this->document(['Grid' => ['type' => 'object', 'required' => ['groups'], 'properties' => [
                'groups' => $groups,
            ]]]),
            'b/tiles.json' => $this->document(['Tile' => ['type' => 'object', 'required' => ['size', 'unit'], 'properties' => [
                'size' => ['type' => 'number', 'minimum' => 0],
                'unit' => ['$ref' => 'units/units.json#/components/schemas/Unit'],
            ]]]),
            'b/units/units.json' => $this->document(['Unit' => ['type' => 'string', 'enum' => ['cm', 'mm']]]),
        ];
        foreach ($documents as $path => $document) {
            file_put_contents($this->root . '/' . $path, json_encode($document, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * The value of a map of maps is resolved two containers down, below where anything is materialized,
     * and that resolution looked the reference up next to the ROOT document: generating `a/page` failed
     * with `Referenced OpenAPI file not found: tiles.json#/components/schemas/Tile`. The same
     * document split differently — a plain map, a list, a property — always worked.
     */
    #[DataProvider('modes')]
    public function testAMapOfMapsReferenceResolvesAgainstTheDocumentThatWritesIt(string $mode): void
    {
        $this->writeChainedDocuments();
        $namespace = 'Integrity' . bin2hex(random_bytes(8));

        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/a/page.json',
            outputDirectory: $this->root . '/out',
            namespace: $namespace,
            mode: $mode,
        );

        foreach (['Page.php', 'B/Grid.php', 'B/Tile.php', 'B/Units/Unit.php'] as $file) {
            self::assertFileExists($this->root . '/out/' . $file, $mode);
        }
    }

    /**
     * A class from another document is written where its namespace says, inside the output directory.
     *
     * The namespace dropped `..` from the document's relative directory, the path appended it raw: a
     * sibling document's classes were written NEXT TO the output directory, a subdirectory's one level
     * above it, and a document two directories over two levels up — `<output>/../../b/Grid.php` for a
     * class declared in `<namespace>\B`. None of them could be autoloaded, and the output directory
     * did not contain what was generated for it.
     */
    public function testAnotherDocumentsClassesLandWhereTheirNamespaceSays(): void
    {
        $this->writeChainedDocuments();
        mkdir($this->root . '/a/sub');
        $plain = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]];
        file_put_contents($this->root . '/a/sibling.json', json_encode($this->document(['Sibling' => $plain]), JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/a/sub/nested.json', json_encode($this->document(['Nested' => $plain]), JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/a/root.json', json_encode($this->document(['Probe' => ['type' => 'object', 'properties' => [
            'sibling' => ['$ref' => 'sibling.json#/components/schemas/Sibling'],
            'nested' => ['$ref' => './sub/nested.json#/components/schemas/Nested'],
            'page' => ['$ref' => 'page.json#/components/schemas/Page'],
        ]]]), JSON_THROW_ON_ERROR));

        (new GenerateDtoCommand())->generateFromFile($this->root . '/a/root.json', $this->root . '/out', 'Layout');

        $expected = [
            'Probe.php' => 'Layout',
            'Sibling.php' => 'Layout',
            'Page.php' => 'Layout',
            'Sub/Nested.php' => 'Layout\Sub',
            'B/Grid.php' => 'Layout\B',
            'B/Tile.php' => 'Layout\B',
            'B/Units/Unit.php' => 'Layout\B\Units',
        ];
        foreach ($expected as $file => $namespace) {
            self::assertFileExists($this->root . '/out/' . $file);
            self::assertStringContainsString(
                needle: 'namespace ' . $namespace . ';',
                haystack: (string)file_get_contents($this->root . '/out/' . $file),
                message: $file,
            );
        }
        // Nothing written beside the output directory: the root holds only what the test put there.
        self::assertSame(['.', '..', 'a', 'b', 'out'], scandir($this->root));
    }

    /**
     * Resolving is not enough — the values have to be typed and checked as they would be if the same
     * component were declared locally. Below a map of maps only local references were understood, so
     * an external one typed the values `mixed` and validated nothing in them.
     */
    public function testAMapOfMapsOfAnotherDocumentsComponentIsTypedAndValidated(): void
    {
        $this->writeChainedDocuments();
        $namespace = 'Integrity' . bin2hex(random_bytes(8));
        (new GenerateDtoCommand())->generateFromFile($this->root . '/a/page.json', $this->root . '/out', $namespace);

        $grid = (string)file_get_contents($this->root . '/out/B/Grid.php');
        self::assertStringContainsString('@var array<string, array<string, Tile>>', $grid);
        foreach (['B/Units/Unit.php', 'B/Tile.php', 'B/Grid.php', 'Page.php'] as $file) {
            require_once $this->root . '/out/' . $file;
        }

        $deserializer = new DtoDeserializer();
        $request = static fn(string $body): Request => Request::create(uri: '/', method: 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $page = $deserializer->deserialize(
            request: $request('{"grid":{"groups":{"top":{"left":{"size":2,"unit":"cm"}}}}}'),
            dtoClass: $namespace . '\Page',
        );
        self::assertSame(['top', 'left'], [array_key_first($page->getGrid()->getGroups()), array_key_first($page->getGrid()->getGroups()['top'])]);

        foreach (['{"size":-1,"unit":"cm"}', '{"size":2,"unit":"km"}', '{"size":2}'] as $tile) {
            try {
                $deserializer->deserialize(
                    request: $request('{"grid":{"groups":{"top":{"left":' . $tile . '}}}}'),
                    dtoClass: $namespace . '\Page',
                );
                self::fail('A tile two maps down was not checked: ' . $tile);
            } catch (RuntimeException $error) {
                self::assertStringContainsString('top', $error->getMessage(), $tile);
            }
        }
    }

    /**
     * The fix is not about two levels: every container walk passes the declaring document down, so a
     * reference into another document resolves — and the value under it is checked — at any depth and
     * through any mix of maps and lists. Only the TYPE stops naming the class past two containers,
     * exactly as it does for a local reference: deeper values are not hydrated into objects.
     */
    #[DataProvider('nestingShapes')]
    public function testAnotherDocumentsComponentIsCheckedAtEveryNestingDepth(string $shape): void
    {
        $this->writeChainedDocuments($shape);
        $namespace = 'Integrity' . bin2hex(random_bytes(8));
        (new GenerateDtoCommand())->generateFromFile($this->root . '/a/page.json', $this->root . '/out', $namespace);
        foreach (['B/Units/Unit.php', 'B/Tile.php', 'B/Grid.php', 'Page.php'] as $file) {
            require_once $this->root . '/out/' . $file;
        }

        $deserializer = new DtoDeserializer();
        $deserialize = function (string $tile) use ($deserializer, $namespace, $shape): object {
            foreach (array_reverse(str_split($shape)) as $container) {
                $tile = $container === 'm' ? '{"key":' . $tile . '}' : '[' . $tile . ']';
            }

            return $deserializer->deserialize(
                request: Request::create(
                    uri: '/',
                    method: 'POST',
                    server: ['CONTENT_TYPE' => 'application/json'],
                    content: '{"grid":{"groups":' . $tile . '}}',
                ),
                dtoClass: $namespace . '\Page',
            );
        };

        self::assertInstanceOf($namespace . '\Page', $deserialize('{"size":2,"unit":"cm"}'));
        foreach (['{"size":-1,"unit":"cm"}', '{"size":2,"unit":"km"}', '{"size":2}'] as $tile) {
            try {
                $deserialize($tile);
                self::fail(sprintf('A tile under "%s" was not checked: %s', $shape, $tile));
            } catch (RuntimeException $error) {
                self::assertStringContainsString('groups', $error->getMessage(), $shape . ' ' . $tile);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nestingShapes(): iterable
    {
        foreach (range(1, 6) as $depth) {
            yield str_repeat('map ', $depth) => [str_repeat('m', $depth)];
            yield str_repeat('list ', $depth) => [str_repeat('l', $depth)];
        }
        foreach (['ml', 'lm', 'mlm', 'lml', 'mlml', 'lmlm', 'mlmlml', 'lmlmlm', 'mmllmm', 'llmmll'] as $shape) {
            yield strtr($shape, ['m' => 'map ', 'l' => 'list ']) => [$shape];
        }
    }

    /**
     * Six containers deep in every mode: each one resolves the nested reference through its own
     * renderer, so passing in runtime says nothing about the other four.
     */
    #[DataProvider('deepShapesInEveryMode')]
    public function testSixContainersDeepGenerateInEveryMode(string $mode, string $shape): void
    {
        $this->writeChainedDocuments($shape);
        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/a/page.json',
            outputDirectory: $this->root . '/out',
            namespace: 'Integrity' . bin2hex(random_bytes(8)),
            mode: $mode,
        );

        foreach (['Page.php', 'B/Grid.php', 'B/Tile.php', 'B/Units/Unit.php'] as $file) {
            self::assertFileExists($this->root . '/out/' . $file, $mode . ' ' . $shape);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deepShapesInEveryMode(): iterable
    {
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            foreach (['mmmmmm', 'llllll', 'mlmlml'] as $shape) {
                yield $mode . ' ' . $shape => [$mode, $shape];
            }
        }
    }

    #[DataProvider('schemaOrders')]
    public function testEnumAndDtoCollisionsFailInBothOrders(bool $enumFirst): void
    {
        $schemas = ['shade' => ['type' => 'string', 'enum' => ['red']], 'Shade' => ['type' => 'object']];
        if (!$enumFirst) {
            $schemas = array_reverse($schemas, true);
        }
        try {
            (new GenerateDtoCommand())->generateFromArray($this->document($schemas), $this->root . '/out', 'Integrity');
            self::fail('A DTO and enum shared the same generated name.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('collision', $error->getMessage());
        }
        $this->assertPreviousOutputSurvives();
    }

    public static function schemaOrders(): iterable
    {
        yield 'enum first' => [true];
        yield 'DTO first' => [false];
    }

    public function testGeneratedFormRequestCannotOverwriteAComponent(): void
    {
        $spec = $this->document(['WidgetsPostRequestFormRequest' => ['type' => 'object']]);
        $spec['paths'] = ['/widgets' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => [
            'type' => 'object', 'properties' => ['name' => ['type' => 'string']],
        ]]]]]]];
        try {
            (new GenerateDtoCommand())->generateFromArray($spec, $this->root . '/out', 'Integrity', 'laravel');
            self::fail('A FormRequest replaced an existing component.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('collision', $error->getMessage());
        }
        $this->assertPreviousOutputSurvives();
    }

    #[DataProvider('modes')]
    public function testFormattingPreservesMultilineEnumValues(string $mode): void
    {
        $values = ["alpha\n\n\nbeta", "alpha\n\n  }\nbeta"];
        $namespace = 'Integrity' . bin2hex(random_bytes(8));
        $spec = $this->document(['Shade' => ['type' => 'string', 'enum' => $values]]);
        (new GenerateDtoCommand())->generateFromArray($spec, $this->root . '/out', $namespace, $mode);
        self::assertContains('Shade.php', scandir($this->root . '/out'));
        require $this->root . '/out/Shade.php';
        $class = $namespace . '\Shade';
        foreach ($values as $value) {
            self::assertSame($value, $class::from($value)->value);
        }
    }

    public function testMultipleMorphBasesAreRejectedBeforePublication(): void
    {
        $union = ['oneOf' => [['$ref' => '#/components/schemas/Shared']], 'discriminator' => [
            'propertyName' => 'kind', 'mapping' => ['shared' => '#/components/schemas/Shared'],
        ]];
        $spec = $this->document(schemas: [
            'Shared' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'First' => $union,
            'Second' => $union,
        ]);
        try {
            (new GenerateDtoCommand())->generateFromArray($spec, $this->root . '/out', 'Integrity', 'laravel-data');
            self::fail('A member cannot extend two morph classes.');
        } catch (RuntimeException $error) {
            foreach (['Shared', 'First', 'Second', 'morph'] as $name) {
                self::assertStringContainsString($name, $error->getMessage());
            }
        }
        $this->assertPreviousOutputSurvives();
    }

    private function generateContainer(): string
    {
        $namespace = 'Integrity' . bin2hex(random_bytes(8));
        $spec = $this->document(schemas: ['Probe' => ['type' => 'object', 'required' => ['data'], 'properties' => [
            'data' => ['type' => 'array', 'items' => []],
        ]]]);
        (new GenerateDtoCommand())->generateFromArray($spec, $this->root . '/out', $namespace);
        require $this->root . '/out/Probe.php';
        return $namespace . '\Probe';
    }

    public function testRepeatedObjectsInSiblingBranchesAreNotCycles(): void
    {
        $class = $this->generateContainer();
        $item = (object)['value' => 'red'];
        $dto = new $class([$item, $item, (object)['nested' => $item]]);
        self::assertSame('{"data":[{"value":"red"},{"value":"red"},{"nested":{"value":"red"}}]}', (new DtoNormalizer())->toJson($dto));
    }

    #[DataProvider('cycleKinds')]
    public function testObjectCyclesRaiseAnExceptionInASeparateProcess(string $kind): void
    {
        $class = $this->generateContainer();
        $process = proc_open(
            command: [PHP_BINARY, '-d', 'memory_limit=64M', __DIR__ . '/../fixtures/normalizer-object-cycle.php', $this->root . '/out/Probe.php', $class, $kind],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertStringContainsString('Circular reference detected', $output);
    }

    public static function cycleKinds(): iterable
    {
        yield ['self'];
        yield ['mutual'];
        yield ['array'];
    }

    /**
     * Every string a document controls is pasted into PHP source somewhere — a docblock, a single-quoted
     * literal, an attribute argument, a sprintf format. Each paste must go through `php_doc` or
     * `php_string`; one that does not turns document text into code. The publish-time parse check does
     * not catch that: a payload written to keep the file valid sails through it. So the check here is
     * on the tokens — the marker may sit only inside a string literal or a comment, or in an identifier
     * the name sanitizers built from it.
     *
     * Found that way: an unescaped yii3 `#[Query('…')]` / `#[Request('…')]`, a yii3 docblock quoting the
     * wire name without `php_doc`, and the laravel discriminator `sprintf('Unknown …')` format.
     */
    #[DataProvider('modes')]
    public function testDocumentTextNeverBecomesCode(string $mode): void
    {
        $namespace = 'Integrity' . bin2hex(random_bytes(8));
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->hostileDocument(starsInNames: !in_array($mode, ['laravel', 'laravel-data'], true)),
            outputDirectory: $this->root . '/out',
            namespace: $namespace,
            mode: $mode,
        );

        $files = glob($this->root . '/out/*.php');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $openTags = 0;
            foreach (token_get_all((string)file_get_contents($file), TOKEN_PARSE) as $token) {
                self::assertNotSame('`', $token, basename($file) . ': a backtick escaped into code.');
                if (!is_array($token)) {
                    continue;
                }
                [$id, $text] = $token;
                $openTags += $id === T_OPEN_TAG ? 1 : 0;
                self::assertNotContains($id, [T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO, T_EXIT], basename($file));
                self::assertFalse(
                    condition: $id === T_VARIABLE && in_array($text, ['$hostileA', '$hostileB'], true),
                    message: basename($file) . ': ' . $text . ' escaped into code.',
                );
                if (
                    str_contains($text, 'Hostile')
                    && !in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_COMMENT, T_DOC_COMMENT], true)
                ) {
                    self::assertMatchesRegularExpression(
                        pattern: '/^\$?[A-Za-z0-9_\x80-\xff]+$/',
                        string: $text,
                        message: basename($file) . ': document text escaped into a ' . token_name($id) . ' token.',
                    );
                }
            }
            self::assertSame(1, $openTags, basename($file));
        }
    }

    public static function modes(): iterable
    {
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            yield $mode => [$mode];
        }
    }

    /**
     * Each field carries `Hostile<field>` followed by every character that ends a PHP context:
     * quotes, a backslash, a comment end, `?>`, a new open tag, interpolation, a backtick, a sprintf `%`,
     * line breaks — and an `exit(…)` call that must stay text.
     *
     * laravel and laravel-data refuse a property name containing `*` (a validation-path wildcard), so for
     * them the NAMES leave the asterisk out; every description, default and example keeps it.
     */
    private function hostileDocument(bool $starsInNames): array
    {
        $text = static fn(string $field): string => 'Hostile' . $field
            . "'\"\\*/?><?php \${hostileA}{\$hostileB}`ls`%s%\n*/ exit('Hostile" . $field . "'); /*\r\n// ?>\\";
        $name = static fn(string $field): string => $starsInNames ? $text($field) : str_replace('*', '', $text($field));
        $pattern = "^Hostile'\\\\*/?>[a-z]$";
        $member = static fn(string $field): array => [
            'type' => 'object',
            'required' => [$name('Kind')],
            'properties' => [$name('Kind') => ['type' => 'string', 'description' => $text($field)]],
        ];
        $shape = [
            'type' => 'object',
            'title' => $text('Title'),
            'description' => $text('Description'),
            'required' => [$name('Label')],
            'properties' => [
                $name('Label') => [
                    'type' => 'string',
                    'description' => $text('LabelDescription'),
                    'example' => $text('LabelExample'),
                    'default' => $text('LabelDefault'),
                    'pattern' => $pattern,
                    'format' => $text('Format'),
                ],
                'tone' => [
                    'type' => 'string',
                    'enum' => [$text('ToneA'), $text('ToneB')],
                    'x-enum-varnames' => [$text('VarA'), $text('VarB')],
                    'x-enum-descriptions' => [$text('ToneDescA'), $text('ToneDescB')],
                    'default' => $text('ToneA'),
                ],
                'fixed' => ['const' => $text('Const')],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => $pattern]],
                'extras' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'default' => [$text('MapKey') => $text('MapValue')],
                ],
                'pet' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => ['propertyName' => $name('Kind'), 'mapping' => [
                        $text('MapCat') => '#/components/schemas/Cat',
                        $text('MapDog') => '#/components/schemas/Dog',
                    ]],
                ],
            ],
        ];
        $parameter = static fn(string $in, string $field): array => [
            'name' => $in === 'path' ? 'id' : $name($field),
            'in' => $in,
            'required' => $in === 'path',
            'description' => $text($field . 'Description'),
            'schema' => ['type' => 'string', 'default' => $text($field . 'Default')],
        ];
        $body = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Shape']]]];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $text('InfoTitle'), 'version' => '1', 'description' => $text('InfoDescription')],
            'paths' => ['/shapes/{id}' => ['post' => [
                'operationId' => $text('Operation'),
                'summary' => $text('Summary'),
                'description' => $text('OperationDescription'),
                'parameters' => [
                    $parameter('path', 'Path'),
                    $parameter('query', 'Query'),
                    $parameter('header', 'Header'),
                    $parameter('cookie', 'Cookie'),
                ],
                'requestBody' => ['required' => true] + $body,
                'responses' => ['200' => ['description' => $text('Response')] + $body],
            ]]],
            'components' => ['schemas' => [
                'Shape' => $shape,
                'Cat' => $member('Cat'),
                'Dog' => $member('Dog'),
                $text('SchemaName') => ['type' => 'object', 'properties' => ['plain' => ['type' => 'string']]],
                'Shade' => ['type' => 'string', 'enum' => [$text('ShadeA'), 'plain'], 'description' => $text('Shade')],
            ]],
        ];
    }
}
