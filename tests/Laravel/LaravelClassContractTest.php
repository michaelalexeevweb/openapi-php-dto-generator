<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Laravel;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What one generated laravel / laravel-data class may count on in another: driven through the real
 * validator and `toArray()`, never asserted on the emitted source.
 *
 * - a map or list typed by an `allOf` base holds the variants the schema admits there — laravel mode
 *   flattens `allOf`, so a variant is no subclass of the base, and `toArray()` must still write it;
 * - a class a `--ref`ed document writes carries `openApiNestedValidation()` whichever run wrote it last.
 *
 * A `required` name the class declares no property for stops generation instead (UndeclaredRequiredNameTest).
 */
final class LaravelClassContractTest extends TestCase
{
    private string $root;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = (string)getcwd();
        $this->root = sys_get_temp_dir() . '/opg-laravel-contract-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->root);
    }

    /**
     * `Circle: allOf [$ref Shape, {radius}]` without a discriminator IS a Shape on the wire, so a map of
     * Shape holds it. Laravel mode emitted `static fn(Shape $item) => $item->toArray()` over the values,
     * and the flattened Circle, which extends nothing, was a TypeError there — a map, a map of maps and
     * a list alike.
     */
    #[DataProvider('modeProvider')]
    public function testACollectionOfAnAllOfBaseWritesItsVariants(string $mode): void
    {
        $namespace = $this->generateDocument(mode: $mode, schemas: [
            'Shape' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
            'Circle' => ['allOf' => [
                ['$ref' => '#/components/schemas/Shape'],
                ['type' => 'object', 'properties' => ['radius' => ['type' => 'number']]],
            ]],
            'Board' => [
                'type' => 'object',
                'required' => ['shapes', 'grid', 'list'],
                'properties' => [
                    'shapes' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Shape']],
                    'grid' => ['type' => 'object', 'additionalProperties' => [
                        'type' => 'object',
                        'additionalProperties' => ['$ref' => '#/components/schemas/Shape'],
                    ]],
                    'list' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']],
                ],
            ],
        ]);
        $circleClass = $namespace . '\Circle';
        $shapeClass = $namespace . '\Shape';
        $boardClass = $namespace . '\Board';
        $circle = new $circleClass(kind: 'circle', radius: 2.5);
        $shape = new $shapeClass(kind: 'blob');

        $board = new $boardClass(shapes: ['a' => $circle, 'b' => $shape], grid: ['row' => ['x' => $circle]], list: [$circle, $shape]);

        $circleWire = ['kind' => 'circle', 'radius' => 2.5];
        $expected = [
            'shapes' => ['a' => $circleWire, 'b' => ['kind' => 'blob']],
            'grid' => ['row' => ['x' => $circleWire]],
            'list' => [$circleWire, ['kind' => 'blob']],
        ];
        $this->assertSame($expected, json_decode((string)json_encode($board->toArray()), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modeProvider(): iterable
    {
        yield 'laravel' => [GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL];
        yield 'laravel-data' => [GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA];
    }

    #[DataProvider('runOrderProvider')]
    public function testAClassAnotherDocumentWritesCarriesItsNestedValidation(string $mode, bool $referencedLast): void
    {
        $suffix = bin2hex(random_bytes(6));
        $mainNamespace = 'Atelier' . $suffix;
        $commonNamespace = 'Atelier' . $suffix . '\Common';
        mkdir('specs');
        file_put_contents('specs/common.json', json_encode($this->document([
            'Gadget' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 2]]],
        ]), JSON_THROW_ON_ERROR));
        file_put_contents('specs/main.json', json_encode($this->document([
            'Widget' => ['type' => 'object', 'required' => ['gadget'], 'properties' => [
                'gadget' => ['$ref' => 'common.json#/components/schemas/Gadget'],
                'gadgets' => ['type' => 'array', 'items' => ['$ref' => 'common.json#/components/schemas/Gadget']],
            ]],
        ]), JSON_THROW_ON_ERROR));

        $runs = [
            ['--file' => 'specs/main.json', '--directory' => 'out/Main', '--namespace' => $mainNamespace, '--ref' => ['specs/common.json=out/Common=' . $commonNamespace]],
            ['--file' => 'specs/common.json', '--directory' => 'out/Common', '--namespace' => $commonNamespace],
        ];
        foreach ($referencedLast ? $runs : array_reverse($runs) as $run) {
            $tester = new CommandTester(new GenerateDtoCommand());
            $tester->execute([...$run, '--attributes' => $mode]);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        }
        $root = $this->root;
        spl_autoload_register(static function (string $class) use ($root, $mainNamespace, $commonNamespace): void {
            foreach ([$commonNamespace => 'out/Common', $mainNamespace => 'out/Main'] as $prefix => $directory) {
                if (str_starts_with($class, $prefix . '\\')) {
                    $file = $root . '/' . $directory . '/' . substr($class, strlen($prefix) + 1) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }

                    return;
                }
            }
        });

        $widgetClass = $mainNamespace . '\Widget';
        $valid = ['gadget' => ['name' => 'ab'], 'gadgets' => [['name' => 'cd']]];
        $invalid = ['gadget' => ['name' => 'ab'], 'gadgets' => [['name' => 'c']]];
        $this->assertVerdicts(mode: $mode, fqcn: $widgetClass, valid: $valid, invalid: $invalid);
    }

    /**
     * `Widget` (main.json) holds `Gadget` from common.json, which a `--ref` placement writes into the
     * directory common.json's own run writes too. `Widget::rules()` asks `Gadget::openApiNestedValidation()`,
     * which only the run whose properties composed Gadget emitted: common.json run last, and the shared
     * file lost the method — "Call to undefined method Gadget::openApiNestedValidation()".
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function runOrderProvider(): iterable
    {
        foreach (self::modeProvider() as $label => [$mode]) {
            yield $label . ', referencing document last' => [$mode, false];
            yield $label . ', referenced document last' => [$mode, true];
        }
    }

    /**
     * The real validator, both ways: the valid payload passes and the invalid one is refused — a rule
     * set that accepts everything would otherwise pass unnoticed.
     *
     * @param class-string|string $fqcn
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $invalid
     */
    private function assertVerdicts(string $mode, string $fqcn, array $valid, array $invalid): void
    {
        if ($mode === GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA) {
            LaravelDataContainer::boot();
            $fqcn::validateAndCreate($valid);
            try {
                $fqcn::validateAndCreate($invalid);
                $this->fail('The invalid payload was accepted: ' . json_encode($invalid));
            } catch (ValidationException $exception) {
                $this->assertNotSame([], $exception->errors());
            }

            return;
        }

        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        foreach ([[$valid, true], [$invalid, false]] as [$payload, $expected]) {
            $validator = $factory->make($payload, $fqcn::rules());
            $fqcn::withValidator($validator, json_encode($payload === [] ? (object)[] : $payload, JSON_THROW_ON_ERROR));
            $this->assertSame($expected, $validator->passes(), json_encode($payload) . ': ' . json_encode($validator->errors()->all()));
        }
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private function generateDocument(string $mode, array $schemas): string
    {
        $namespace = 'Atelier' . bin2hex(random_bytes(6));
        $target = $this->root . '/out';
        (new GenerateDtoCommand())->generateFromArray(
            openApi: $this->document($schemas),
            outputDirectory: $target,
            namespace: $namespace,
            mode: $mode,
        );
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
        if ($mode === GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA) {
            LaravelDataContainer::boot();
        }

        return $namespace;
    }

    /**
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private function document(array $schemas): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Atelier', 'version' => '1.0.0'],
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
