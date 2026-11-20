<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime-mode shapes a document may legally write that were generated, read or written back wrong.
 * Each case generates a small document and drives a payload through the real deserializer.
 */
final class RuntimeShapeFixesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-shapes-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testComponentEnumSpelledTheOpenApi31WayIsAnEnum(): void
    {
        $class = $this->generate(
            components: [
                'Tone' => ['type' => ['string', 'null'], 'enum' => ['red', 'green']],
                'Shade' => ['enum' => ['light', 'dark']],
                'Size' => ['enum' => [1, 2]],
            ],
            properties: [
                'tone' => ['$ref' => '#/components/schemas/Tone'],
                'shade' => ['$ref' => '#/components/schemas/Shade'],
                'size' => ['$ref' => '#/components/schemas/Size'],
            ],
            openapi: '3.1.0',
        );

        $dto = $this->deserialize(class: $class, body: '{"tone":"red","shade":"dark","size":2}');
        $this->assertSame('red', $dto->getTone()?->value);
        $this->assertSame('dark', $dto->getShade()?->value);
        $this->assertSame(2, $dto->getSize()?->value);
        $this->assertNull($this->deserialize(class: $class, body: '{"tone":null}')->getTone());
        $this->assertRejected(class: $class, body: '{"tone":"blue"}', message: 'Allowed: red, green');
    }

    public function testDateOrNullUnionAcceptsADate(): void
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $class = $this->generate(properties: [
                'day' => [$keyword => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
            ], openapi: '3.1.0');

            $this->assertSame('2024-01-02', $this->deserialize(class: $class, body: '{"day":"2024-01-02"}')->getDay()?->format('Y-m-d'), $keyword);
            $this->assertNull($this->deserialize(class: $class, body: '{"day":null}')->getDay(), $keyword);
        }
    }

    public function testDateOrIntegerUnionParsesTheDateAsADate(): void
    {
        $class = $this->generate(properties: [
            'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'integer']]],
        ]);

        $this->assertSame('2024-01-02', $this->deserialize(class: $class, body: '{"day":"2024-01-02"}')->getDay()->format('Y-m-d'));
        $this->assertSame(7, $this->deserialize(class: $class, body: '{"day":7}')->getDay());
    }

    public function testDateOrDateTimeUnionDoesNotDependOnTheServerTimezone(): void
    {
        $class = $this->generate(properties: [
            'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date-time']]],
        ]);
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $dto = $this->deserialize(class: $class, body: '{"day":"2024-01-02"}');
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertSame('2024-01-02T00:00:00+00:00', $dto->getDay()->format(DATE_ATOM));
    }

    /**
     * A temporal union's value went out as the PHP object — `{"date": …, "timezone": …}` from the DTO's
     * own `toJson()` — and a date as a date-time. Each branch now goes out as its own string.
     */
    public function testTemporalUnionGoesOutAsTheBranchItCameIn(): void
    {
        $class = $this->generate(properties: [
            'when' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date-time']]],
            'day' => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
        ], openapi: '3.1.0');

        foreach (['{"when":"2024-01-02","day":"2024-03-04"}', '{"when":"2024-01-02T10:00:00+02:00","day":null}', '{"when":"2024-01-02T10:00:00.250000+02:00"}'] as $body) {
            $dto = $this->deserialize(class: $class, body: $body);
            $this->assertSame($body, $dto->toJson());
            $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        }
    }

    public function testListOfListsOfAnIntegerEnumIsValidated(): void
    {
        $class = $this->generate(
            components: ['Size' => ['type' => 'integer', 'enum' => [1, 2]]],
            properties: ['sizes' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Size']]]],
        );

        $this->assertSame([[1, 2]], $this->deserialize(class: $class, body: '{"sizes":[[1,2]]}')->getSizes());
        $this->assertRejected(class: $class, body: '{"sizes":[[9]]}', message: 'sizes');
    }

    public function testMapOfMapsGoesOutAsObjectsAndComesBackIn(): void
    {
        $class = $this->generate(properties: [
            'grid' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]],
        ]);
        $body = '{"grid":{"a":{"0":1,"1":2},"b":{}}}';

        $json = (new DtoNormalizer())->toJson($this->deserialize(class: $class, body: $body));

        $this->assertSame($body, $json);
        $this->assertSame($body, (new DtoNormalizer())->toJson($this->deserialize(class: $class, body: $json)));
    }

    public function testAdditionalPropertiesBesideNamedOnesAreValidatedAndKept(): void
    {
        $class = $this->generate(properties: [
            'extra' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']],
        ]);

        $body = '{"extra":{"count":1,"color":"red"}}';
        $dto = $this->deserialize(class: $class, body: $body);
        $this->assertSame(1, $dto->getExtra()->getCount());
        $this->assertSame(['color' => 'red'], $dto->getExtra()->undeclaredProperties());
        $this->assertSame($body, $dto->toJson());
        $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        $this->assertRejected(class: $class, body: '{"extra":{"count":1,"color":2}}', message: 'extra.color');
    }

    public function testUnionMemberTypedDirectlyIgnoresItsParentsDiscriminator(): void
    {
        $class = $this->generate(
            components: [
                'Animal' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']],
                ],
                'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
                'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
            ],
            properties: ['cat' => ['$ref' => '#/components/schemas/Cat']],
        );

        $this->assertSame('tabby', $this->deserialize(class: $class, body: '{"cat":{"kind":"tabby","meow":"m"}}')->getCat()->getKind());
    }

    public function testNumberThatOverflowsIsRejected(): void
    {
        $class = $this->generate(properties: ['price' => ['type' => 'number']]);

        $this->assertRejected(class: $class, body: '{"price":1e400}', message: 'must be a finite number');
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $components
     */
    private function generate(array $properties, array $components = [], string $openapi = '3.0.3'): string
    {
        $namespace = 'Shapes' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            [
                'openapi' => $openapi,
                'info' => ['title' => 'Shapes', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [...$components, 'Probe' => ['type' => 'object', 'properties' => $properties]]],
            ],
            $directory,
            $namespace,
        );
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });

        return $namespace . '\Probe';
    }

    private function deserialize(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function assertRejected(string $class, string $body, string $message): void
    {
        try {
            $this->deserialize(class: $class, body: $body);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail(sprintf('%s must be rejected.', $body));
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
