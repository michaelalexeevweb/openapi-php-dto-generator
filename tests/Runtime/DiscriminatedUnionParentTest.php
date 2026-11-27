<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime: a member of a discriminated `oneOf` that is an `allOf` child keeps its `allOf` parent.
 *
 * The members of such a union extend its abstract class, and a class has one parent: an
 * `allOf: [$ref Tile, …]` member was emitted `extends AnyTile`, its `Tile` dropped, and code typed with
 * `Tile` broke at load. Now the union extends the parent all its members name; a member whose parent is
 * not that one — or a class another document's run writes — keeps its own, and a property typed with
 * the union is spelled as its members, picked by the tag.
 */
final class DiscriminatedUnionParentTest extends TestCase
{
    private string $root;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = (string)getcwd();
        $this->root = sys_get_temp_dir() . '/opg-union-parent-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->root);
    }

    public function testMembersSharingAParentKeepItThroughTheUnion(): void
    {
        $namespace = 'Tiles' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->document([
                'Tile' => $this->tile(),
                'StarTile' => $this->member(parentRef: '#/components/schemas/Tile', valueType: 'integer'),
                'MoonTile' => $this->member(parentRef: '#/components/schemas/Tile', valueType: 'string'),
                'AnyTile' => $this->union(['a:star' => '#/components/schemas/StarTile', 'a:moon' => '#/components/schemas/MoonTile']),
                'Board' => $this->board(),
            ]),
            outputDirectory: $directory,
            namespace: $namespace,
        );
        $this->autoload(namespace: $namespace, directory: $directory);

        $this->assertTrue(is_subclass_of($namespace . '\StarTile', $namespace . '\Tile'));
        $this->assertTrue(is_subclass_of($namespace . '\MoonTile', $namespace . '\Tile'));
        $this->assertTrue(is_subclass_of($namespace . '\StarTile', $namespace . '\AnyTile'));
        $this->assertTrue(is_subclass_of($namespace . '\AnyTile', $namespace . '\Tile'));

        // Every member extends the union, so a property typed with it stays typed with it.
        $this->assertSame(
            expected: $namespace . '\AnyTile',
            actual: (string)(new ReflectionMethod($namespace . '\Board', 'getTile'))->getReturnType(),
        );

        $board = $this->deserialize(
            class: $namespace . '\Board',
            body: '{"tile":{"kind":"a:star","value":3},"tiles":[{"kind":"a:moon","value":"new"},{"kind":"a:star","value":5}]}',
        );
        $this->assertInstanceOf($namespace . '\StarTile', $board->getTile());
        $this->assertSame('a:star', $board->getTile()->getKind());
        $this->assertSame(3, $board->getTile()->getValue());
        $this->assertSame('new', $board->getTiles()[0]->getValue());
        $this->assertInstanceOf($namespace . '\MoonTile', $board->getTiles()[0]);
        $this->assertInstanceOf($namespace . '\StarTile', $board->getTiles()[1]);
    }

    public function testMembersWithDifferentParentsKeepTheirOwn(): void
    {
        $namespace = 'Tiles' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->document([
                'Tile' => $this->tile(),
                'Brick' => $this->tile(),
                'StarTile' => $this->member(parentRef: '#/components/schemas/Tile', valueType: 'integer'),
                'MoonTile' => $this->member(parentRef: '#/components/schemas/Brick', valueType: 'integer'),
                'AnyTile' => $this->union(['a:star' => '#/components/schemas/StarTile', 'a:moon' => '#/components/schemas/MoonTile']),
                'Board' => $this->board(),
            ]),
            outputDirectory: $directory,
            namespace: $namespace,
        );
        $this->autoload(namespace: $namespace, directory: $directory);

        $this->assertTrue(is_subclass_of($namespace . '\StarTile', $namespace . '\Tile'));
        $this->assertTrue(is_subclass_of($namespace . '\MoonTile', $namespace . '\Brick'));

        // Both members take the same shape: only the tag tells them apart.
        $board = $this->deserialize(
            class: $namespace . '\Board',
            body: '{"tile":{"kind":"a:moon","value":3},"tiles":[{"kind":"a:star","value":1},{"kind":"a:moon","value":2}]}',
        );
        $this->assertInstanceOf($namespace . '\MoonTile', $board->getTile());
        $this->assertInstanceOf($namespace . '\StarTile', $board->getTiles()[0]);
        $this->assertInstanceOf($namespace . '\MoonTile', $board->getTiles()[1]);
    }

    /**
     * The parent in a referenced document, generated into its own namespace, and one member of the union
     * written there too: that member is the class the referenced document's own run writes.
     */
    public function testMemberOfAReferencedDocumentIsWrittenAsItsOwnRunWritesIt(): void
    {
        $namespace = 'Tiles' . bin2hex(random_bytes(8));
        mkdir('specs');
        file_put_contents('specs/core.json', json_encode($this->document([
            'Tile' => $this->tile(),
            'CoreTile' => $this->member(parentRef: '#/components/schemas/Tile', valueType: 'string'),
        ]), JSON_THROW_ON_ERROR));
        file_put_contents('specs/main.json', json_encode($this->document([
            'StarTile' => $this->member(parentRef: 'core.json#/components/schemas/Tile', valueType: 'integer'),
            'AnyTile' => $this->union(['a:star' => '#/components/schemas/StarTile', 'a:core' => 'core.json#/components/schemas/CoreTile']),
            'Board' => $this->board(),
        ]), JSON_THROW_ON_ERROR));

        $this->runCommand([
            '--file' => 'specs/core.json',
            '--directory' => 'own/Core',
            '--namespace' => $namespace . '\Core',
        ]);
        $this->runCommand([
            '--file' => 'specs/main.json',
            '--directory' => 'out/Main',
            '--namespace' => $namespace . '\Main',
            '--ref' => ['specs/core.json=out/Core=' . $namespace . '\Core'],
        ]);

        $this->assertSame(
            expected: file_get_contents('own/Core/CoreTile.php'),
            actual: file_get_contents('out/Core/CoreTile.php'),
        );
        $this->autoload(namespace: $namespace . '\Main', directory: $this->root . '/out/Main');
        $this->autoload(namespace: $namespace . '\Core', directory: $this->root . '/out/Core');

        $this->assertTrue(is_subclass_of($namespace . '\Main\StarTile', $namespace . '\Core\Tile'));
        $this->assertTrue(is_subclass_of($namespace . '\Main\StarTile', $namespace . '\Main\AnyTile'));
        $this->assertTrue(is_subclass_of($namespace . '\Core\CoreTile', $namespace . '\Core\Tile'));

        $board = $this->deserialize(
            class: $namespace . '\Main\Board',
            body: '{"tile":{"kind":"a:core","value":"x"},"tiles":[{"kind":"a:star","value":1},{"kind":"a:core","value":"y"}]}',
        );
        $this->assertInstanceOf($namespace . '\Core\CoreTile', $board->getTile());
        $this->assertInstanceOf($namespace . '\Main\StarTile', $board->getTiles()[0]);
        $this->assertInstanceOf($namespace . '\Core\CoreTile', $board->getTiles()[1]);
    }

    /**
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private function document(array $schemas): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Tiles', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tile(): array
    {
        return ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function member(string $parentRef, string $valueType): array
    {
        return ['allOf' => [
            ['$ref' => $parentRef],
            ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => $valueType]]],
        ]];
    }

    /**
     * @param array<string, string> $mapping
     * @return array<string, mixed>
     */
    private function union(array $mapping): array
    {
        return [
            'oneOf' => array_map(static fn(string $ref): array => ['$ref' => $ref], array_values($mapping)),
            'discriminator' => ['propertyName' => 'kind', 'mapping' => $mapping],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        return [
            'type' => 'object',
            'required' => ['tile', 'tiles'],
            'properties' => [
                'tile' => ['$ref' => '#/components/schemas/AnyTile'],
                'tiles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AnyTile']],
            ],
        ];
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    private function runCommand(array $options): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $tester->execute($options);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function autoload(string $namespace, string $directory): void
    {
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });
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
