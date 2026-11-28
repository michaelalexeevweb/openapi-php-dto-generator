<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime: a member of a union that a referenced document's run writes into ANOTHER namespace imports
 * the union interface it implements.
 *
 * `Shape: oneOf [Circle, other.json#/Square]` made `Other\Square implements Shape`, and the import
 * collector never looked at the union interfaces: `Shape` resolved to `Other\Shape`, and loading
 * `Square` was a fatal "Interface not found".
 */
final class UnionInterfaceImportTest extends TestCase
{
    private string $root;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = (string)getcwd();
        $this->root = sys_get_temp_dir() . '/opg-union-import-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->root);
    }

    public function testAMemberInAnotherNamespaceImportsItsUnionInterface(): void
    {
        $namespace = 'Garden' . bin2hex(random_bytes(8));
        mkdir('specs');
        file_put_contents('specs/other.json', json_encode($this->document([
            'Hue' => ['type' => 'string', 'enum' => ['red', 'cyan']],
            'Square' => [
                'type' => 'object',
                'properties' => ['side' => ['type' => 'integer'], 'hue' => ['$ref' => '#/components/schemas/Hue']],
                'required' => ['side'],
            ],
        ]), JSON_THROW_ON_ERROR));
        file_put_contents('specs/main.json', json_encode($this->document([
            'Garden' => ['type' => 'object', 'properties' => ['shape' => ['$ref' => '#/components/schemas/Shape']]],
            'Circle' => [
                'type' => 'object',
                'properties' => ['radius' => ['type' => 'number']],
                'required' => ['radius'],
                'additionalProperties' => false,
            ],
            'Shape' => ['oneOf' => [
                ['$ref' => '#/components/schemas/Circle'],
                ['$ref' => 'other.json#/components/schemas/Square'],
            ]],
        ]), JSON_THROW_ON_ERROR));

        $this->generate($namespace);

        $this->assertTrue(is_subclass_of($namespace . '\Other\Square', $namespace . '\Shape'));

        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"shape":{"side":3,"hue":"red"}}',
        );
        $garden = (new DtoDeserializer())->deserialize($request, $namespace . '\Garden');
        $this->assertInstanceOf($namespace . '\Other\Square', $garden->getShape());
    }

    /**
     * A union of the referenced document that is a branch of this run's union is an interface that
     * EXTENDS this run's one: the same import, on the `interface … extends` line.
     */
    public function testANestedUnionInterfaceInAnotherNamespaceImportsItsOuterInterface(): void
    {
        $namespace = 'Garden' . bin2hex(random_bytes(8));
        mkdir('specs');
        file_put_contents('specs/other.json', json_encode($this->document([
            'Square' => ['type' => 'object', 'properties' => ['side' => ['type' => 'integer']], 'required' => ['side']],
            'Star' => ['type' => 'object', 'properties' => ['tips' => ['type' => 'string']], 'required' => ['tips']],
            'Tint' => ['oneOf' => [
                ['$ref' => '#/components/schemas/Square'],
                ['$ref' => '#/components/schemas/Star'],
            ]],
        ]), JSON_THROW_ON_ERROR));
        file_put_contents('specs/main.json', json_encode($this->document([
            'Garden' => ['type' => 'object', 'properties' => ['shape' => ['$ref' => '#/components/schemas/Shape']]],
            'Circle' => [
                'type' => 'object',
                'properties' => ['radius' => ['type' => 'number']],
                'required' => ['radius'],
                'additionalProperties' => false,
            ],
            'Shape' => ['oneOf' => [
                ['$ref' => '#/components/schemas/Circle'],
                ['$ref' => 'other.json#/components/schemas/Tint'],
            ]],
        ]), JSON_THROW_ON_ERROR));

        $this->generate($namespace);

        $this->assertTrue(is_subclass_of($namespace . '\Other\Square', $namespace . '\Shape'));
        $this->assertTrue(interface_exists($namespace . '\Other\Tint'));
    }

    private function generate(string $namespace): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $tester->execute([
            '--file' => 'specs/main.json',
            '--directory' => 'out/Main',
            '--namespace' => $namespace,
            '--ref' => ['specs/other.json=out/Main/Other=' . $namespace . '\Other'],
        ]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        spl_autoload_register(function (string $class) use ($namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $this->root . '/out/Main/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /**
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private function document(array $schemas): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Garden', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ];
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }
}
