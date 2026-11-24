<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A union whose only container is an object writes a held PHP array back as `{}` — where its PHP type can
 * hold an array at all. A union typed by its one class (`type: object, oneOf: [$ref Stamp], nullable:
 * true` is `?Stamp`) holds the object, never an array: the `is_array() ? (object)` there was dead code in
 * every such class.
 */
final class ObjectUnionOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-object-union-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testOnlyATypeThatHoldsAnArrayIsWrittenBackAsAnObject(): void
    {
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.3',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['last', 'free'],
                        'properties' => [
                            'last' => ['type' => 'object', 'oneOf' => [['$ref' => '#/components/schemas/Stamp']], 'nullable' => true],
                            'free' => ['anyOf' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], ['type' => 'string']]],
                        ],
                    ],
                    'Stamp' => ['type' => 'object', 'properties' => ['at' => ['type' => 'string']]],
                ]],
            ],
            outputDirectory: $this->root,
            namespace: 'ObjectUnionOutput',
        );
        $source = (string)file_get_contents($this->root . '/Probe.php');
        $this->assertStringContainsString("\$result['last'] = \$this->last;", $source);
        $this->assertStringContainsString("\$result['free'] = is_array(\$this->free) ? (object)\$this->free : \$this->free;", $source);

        require_once $this->root . '/Stamp.php';
        require_once $this->root . '/Probe.php';
        $dto = (new DtoDeserializer())->deserialize(
            Request::create(uri: '/', method: 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"last":{"at":"x"},"free":{}}'),
            'ObjectUnionOutput\Probe',
        );
        $this->assertSame('{"last":{"at":"x"},"free":{}}', (new DtoNormalizer())->validateAndNormalizeToJson($dto));
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
