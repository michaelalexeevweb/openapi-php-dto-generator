<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * References that go through the schema-resource index — `$anchor`, `$id`, URN and `$dynamicRef` —
 * at the generator level: what is emitted, and what stops generation. The validation behaviour of
 * the same references in every mode is in `ValidationParityTest`.
 */
final class SchemaResourceReferenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-resource-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root . '/out');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * A reference the index cannot resolve used to type the property `mixed` and drop every
     * constraint of the schema it meant; generation reported success. Like an unresolvable
     * `#/components/schemas/…` pointer, it now stops generation and names the reference.
     *
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $extraComponents
     */
    #[DataProvider('unresolvableReferences')]
    public function testAnUnresolvableReferenceStopsGeneration(array $propertySchema, array $extraComponents, string $named): void
    {
        $document = $this->probeDocument(propertySchema: $propertySchema, extraComponents: $extraComponents);

        try {
            (new GenerateDtoCommand())->generateFromArray(
                openApi: $document,
                outputDirectory: $this->root . '/out',
                namespace: 'ResourceRefs',
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(needle: $named, haystack: $exception->getMessage());

            return;
        }

        $this->fail('generation succeeded with a reference that names nothing: ' . $named);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function unresolvableReferences(): iterable
    {
        yield 'an $anchor nobody declares' => [['$ref' => '#nowhere'], [], '#nowhere'];
        yield 'a $dynamicRef to an anchor nobody declares' => [
            ['type' => 'array', 'items' => ['$dynamicRef' => '#element']],
            [],
            '#element',
        ];
        yield 'an $id nobody declares' => [['$ref' => 'urn:example:missing'], [], 'urn:example:missing'];
        yield 'a remote document' => [
            ['$ref' => 'https://example.com/schemas/widget.json'],
            [],
            'https://example.com/schemas/widget.json',
        ];
        yield 'a pointer into a resource that does not lead anywhere' => [
            ['$ref' => 'https://example.com/palette#/$defs/missing'],
            ['Palette' => ['$id' => 'https://example.com/palette', 'type' => 'string']],
            'https://example.com/palette#/$defs/missing',
        ];
        yield 'an $anchor two schemas of one resource declare' => [
            ['$ref' => '#twice'],
            [
                'Alpha' => ['$anchor' => 'twice', 'type' => 'string'],
                'Beta' => ['$anchor' => 'twice', 'type' => 'integer'],
            ],
            '#twice',
        ];
    }

    /**
     * An embedded resource is ONE schema, wherever it is referenced from: it becomes one class, and
     * every reference names that class.
     */
    public function testAnEmbeddedResourceReferencedTwiceIsOneClass(): void
    {
        $document = $this->probeDocument(
            propertySchema: [
                '$ref' => 'https://example.com/gadget',
                '$defs' => ['gadget' => [
                    '$id' => 'https://example.com/gadget',
                    'type' => 'object',
                    'properties' => ['size' => ['type' => 'integer']],
                ]],
            ],
            extraComponents: [],
        );
        $document['components']['schemas']['Probe']['properties']['spare'] = ['$ref' => 'https://example.com/gadget'];

        (new GenerateDtoCommand())->generateFromArray(
            openApi: $document,
            outputDirectory: $this->root . '/out',
            namespace: 'ResourceRefsOnce',
        );

        $listed = scandir($this->root . '/out');
        $this->assertIsArray($listed);
        $this->assertSame(expected: ['Gadget.php', 'Probe.php'], actual: array_values(array_diff($listed, ['.', '..'])));
        $probe = (string)file_get_contents($this->root . '/out/Probe.php');
        $this->assertStringContainsString(needle: 'public function getF(): Gadget', haystack: $probe);
        $this->assertStringContainsString(needle: 'public function getSpare(): ?Gadget', haystack: $probe);
    }

    /**
     * An anchor reference inside a REFERENCED file resolves against that file, as its pointers do:
     * the constraint it names arrives in the class generated for the root document.
     */
    public function testAnAnchorInsideAReferencedFileResolvesThere(): void
    {
        file_put_contents(
            filename: $this->root . '/colors.json',
            data: (string)json_encode(value: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'Colors', 'version' => '1'],
                'components' => ['schemas' => [
                    'Palette' => [
                        'type' => 'object',
                        'required' => ['hue'],
                        'properties' => ['hue' => ['$ref' => '#hue']],
                    ],
                    'Hue' => ['$anchor' => 'hue', 'type' => 'string', 'maxLength' => 4],
                ]],
            ]),
        );
        file_put_contents(
            filename: $this->root . '/root.json',
            data: (string)json_encode(value: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'Root', 'version' => '1'],
                'components' => ['schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => ['$ref' => 'colors.json#/components/schemas/Palette']],
                    ],
                ]],
            ]),
        );

        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/root.json',
            outputDirectory: $this->root . '/out',
            namespace: 'ResourceRefsFile',
        );
        foreach (glob($this->root . '/out/*.php') ?: [] as $file) {
            require_once $file;
        }

        $this->assertTrue($this->runtimeAccepts(fqcn: 'ResourceRefsFile\Probe', json: '{"f":{"hue":"teal"}}'));
        $this->assertFalse($this->runtimeAccepts(fqcn: 'ResourceRefsFile\Probe', json: '{"f":{"hue":"turquoise"}}'));
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @param array<string, mixed> $extraComponents
     * @return array<string, mixed>
     */
    private function probeDocument(array $propertySchema, array $extraComponents): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => [
                'Probe' => [
                    'type' => 'object',
                    'required' => ['f'],
                    'properties' => ['f' => $propertySchema],
                ],
                ...$extraComponents,
            ]],
        ];
    }

    private function runtimeAccepts(string $fqcn, string $json): bool
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            /** @var class-string $fqcn */
            (new DtoDeserializer())->deserialize($request, $fqcn);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
