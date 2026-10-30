<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use BackedEnum;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * A container's declaration names what the object really holds after hydration, in the modes where
 * that differed until 2.15.65. Validation was right in every case; only the promise to static analysis
 * was wrong, and code written against it passed PHPStan and failed at runtime.
 */
final class DeclarationHonestyTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-declarations';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    /**
     * yii3 builds objects only for the items of a list of DTOs (`#[Collection]`); a DTO anywhere else in a
     * container is the decoded JSON — or the object `create()` was given — and is declared as such. An enum member is held as its value and
     * handed out as a case by the getter.
     */
    public function testYii3DeclaresTheDecodedValuesItDoesNotBuild(): void
    {
        $source = $this->generate('yii3', 'DeclYii3');

        $this->assertStringContainsString('/** @return array<Kind> */' . "\n" . '    public function getKinds(): array', $source);
        $this->assertStringContainsString('/** @return array<Tag> */' . "\n" . '    public function getTags(): array', $source);
        $this->assertStringContainsString('/** @return array<string, Tag|array<string, mixed>> */' . "\n" . '    public function getTagMap(): array', $source);
        $this->assertStringContainsString('/** @return array<array<Tag|array<string, mixed>>> */' . "\n" . '    public function getTagRows(): array', $source);
        // An optional container's getter says null as its native type does.
        $this->assertStringContainsString('/** @return ?array<Kind> */' . "\n" . '    public function getOptKinds(): ?array', $source);

        $this->requireGenerated('DeclYii3');
        $container = new Yii3Container();
        $dto = $container->hydrate('DeclYii3\Box', self::payload());
        $this->assertSame(['a', 'b'], array_map(static fn(BackedEnum $kind): string|int => $kind->value, $dto->getKinds()));
        $this->assertInstanceOf('DeclYii3\Tag', $dto->getTags()[0]);
        $this->assertSame(['id' => 2], $dto->getTagMap()['k']);
        $this->assertSame([[['id' => 3]]], $dto->getTagRows());
    }

    /**
     * Laravel holds what its docblock names two containers deep: `fromValidated()` builds the DTOs there,
     * as runtime and Symfony mode do, and `toArray()` writes them back.
     */
    public function testLaravelBuildsTheDtosItDeclaresTwoContainersDeep(): void
    {
        $this->generate('laravel', 'DeclLaravel');
        $this->requireGenerated('DeclLaravel');

        $payload = self::payload();
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, \DeclLaravel\Box::rules());
        \DeclLaravel\Box::withValidator($validator, (string)json_encode($payload));
        $this->assertFalse($validator->fails(), implode(' ', $validator->errors()->all()));

        $dto = \DeclLaravel\Box::fromValidated($validator->validated());
        $this->assertInstanceOf('DeclLaravel\Tag', $dto->getTagRows()[0][0]);
        $this->assertSame(3, $dto->getTagRows()[0][0]->getId());
        $this->assertSame($payload['tagRows'], json_decode((string)json_encode($dto->toArray()['tagRows']), true));
    }

    /**
     * Runtime casts objects to arrays two containers deep and stops; from the third container on the value
     * is the `stdClass` `json_decode()` produced, and the declaration says `mixed` there.
     */
    public function testRuntimeDeclaresMixedWhereItKeepsTheDecodedObject(): void
    {
        $source = $this->generate('runtime', 'DeclRuntime');
        $this->assertStringContainsString('@param array<array<mixed>> $deep', $source);

        $this->requireGenerated('DeclRuntime');
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string)json_encode(self::payload()),
        );
        $dto = (new DtoDeserializer())->deserialize($request, 'DeclRuntime\Box');
        $this->assertInstanceOf(stdClass::class, $dto->getDeep()[0][0]);
    }

    /**
     * `required` beside an `allOf` binds the properties its branches declare. Each branch was read on its
     * own, so they came out optional — nullable and tracked for presence — while validation required them.
     */
    #[DataProvider('everyMode')]
    public function testRequiredBesideAnAllOfBindsItsBranches(GenerationMode $mode): void
    {
        $namespace = 'DeclSibling' . $mode->tag();
        $target = $this->outputDirectory . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [
                    'Note' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
                    'Memo' => [
                        'type' => 'object',
                        'required' => ['text', 'extra'],
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Note'],
                            ['type' => 'object', 'properties' => ['extra' => ['type' => 'string'], 'spare' => ['type' => 'string']]],
                        ],
                    ],
                ]],
            ],
            $target,
            $namespace,
            $mode->value,
        );
        $source = (string)file_get_contents($target . '/Memo.php');

        $declares = static fn(string $name, string $type): bool => preg_match(
            sprintf('/function get%1$s\(\): %2$s\b|readonly %2$s \$%3$s\b/', ucfirst($name), preg_quote($type, '/'), $name),
            $source,
        ) === 1;
        $this->assertTrue($declares('extra', 'string'), 'extra is required beside the allOf');
        $this->assertFalse($declares('spare', 'string'), 'spare is not');
        // Runtime keeps a single-`$ref` branch as the parent class, whose getter cannot change here.
        if ($mode !== GenerationMode::Runtime) {
            $this->assertTrue($declares('text', 'string'), 'text comes from the referenced branch');
        }
    }

    public static function everyMode(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    private function generate(string $mode, string $namespace): string
    {
        $tag = ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]];
        $tagRef = ['$ref' => '#/components/schemas/Tag'];
        $kindRef = ['$ref' => '#/components/schemas/Kind'];
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Kind' => ['type' => 'string', 'enum' => ['a', 'b']],
                'Tag' => $tag,
                'Box' => [
                    'type' => 'object',
                    'required' => ['kinds', 'tags', 'tagMap', 'tagRows', 'deep'],
                    'properties' => [
                        'kinds' => ['type' => 'array', 'items' => $kindRef],
                        'tags' => ['type' => 'array', 'items' => $tagRef],
                        'tagMap' => ['type' => 'object', 'additionalProperties' => $tagRef],
                        'tagRows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $tagRef]],
                        'deep' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'object']]],
                        'optKinds' => ['type' => 'array', 'items' => $kindRef],
                    ],
                ],
            ]],
        ];
        $target = $this->outputDirectory . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);

        return (string)file_get_contents($target . '/Box.php');
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'kinds' => ['a', 'b'],
            'tags' => [['id' => 1]],
            'tagMap' => ['k' => ['id' => 2]],
            'tagRows' => [[['id' => 3]]],
            'deep' => [[['x' => 1]]],
        ];
    }

    private function requireGenerated(string $namespace): void
    {
        foreach (['Kind.php', 'Tag.php', 'Box.php'] as $file) {
            require_once $this->outputDirectory . '/' . $namespace . '/' . $file;
        }
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
