<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use FastHydratorNames\Probe;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The generated `hydrateFast()` named its locals after the properties, beside its own parameters
 * `$data`, `$cast` and `$errors`. A property `data` overwrote the input array, and every property read
 * after it came from null: `array_key_exists(): Argument #2 must be of type array, null given`, a 500
 * for any request DTO whose `data` field is not the last. The same held for `cast`, `errors`, `dto`,
 * and for a property `x` beside one named `xGiven`.
 */
final class FastHydratorLocalNamesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-fast-names-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testPropertiesNamedLikeTheMethodLocalsAreRead(): void
    {
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['data', 'cast'],
                        'properties' => [
                            'data' => ['type' => 'string'],
                            'cast' => ['type' => 'integer'],
                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'dto' => ['type' => 'boolean'],
                            'x' => ['type' => 'integer'],
                            'xGiven' => ['type' => 'integer'],
                        ],
                    ],
                ]],
            ],
            outputDirectory: $this->root,
            namespace: 'FastHydratorNames',
        );
        require_once $this->root . '/Probe.php';
        $this->assertStringContainsString(needle: 'function hydrateFast(', haystack: (string)file_get_contents($this->root . '/Probe.php'));

        $dto = (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"data":"a","cast":1,"errors":["e"],"dto":true,"x":2,"xGiven":3}',
            ),
            Probe::class,
        );

        $this->assertSame('a', $dto->getData());
        $this->assertSame(1, $dto->getCast());
        $this->assertSame(['e'], $dto->getErrors());
        $this->assertTrue($dto->getDto());
        $this->assertSame(2, $dto->getX());
        $this->assertSame(3, $dto->getXGiven());
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
