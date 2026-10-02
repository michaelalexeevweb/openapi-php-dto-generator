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

    public static function modes(): iterable
    {
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            yield $mode => [$mode];
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
}
