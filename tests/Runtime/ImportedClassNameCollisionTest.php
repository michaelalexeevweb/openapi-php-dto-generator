<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A component named after a class the emitted file imports — `use stdClass;` beside `class StdClass`,
 * `use Symfony\…\Context;` beside `class Context` — is a fatal the moment the file loads. The generator
 * warns about such names from a hand-kept list, and the list missed most of them: what a mode imports
 * for a `uuid`, a `date-time`, a map or a union was not on it, nor were laravel-data's and yii3's few
 * plain imports, and `stdClass` was looked up case-sensitively while PHP folds class names. The run then
 * reported success over files that never load. Every generated file that cannot load because of an
 * import now has its warning; a name nothing collides with still has none.
 *
 * A warning is the fallback, not the fix. Every mode now names its library classes in full, or through
 * `libraryClassRef()` / `yii3Lib()`, and the rendered file imports a class under its short name only when
 * no class generated into that namespace owns it — otherwise under an alias, or not at all. So a
 * colliding name loads, which is asserted by loading it: a warning that is no longer printed proves
 * nothing about the file. Symfony and Laravel were the last to join; their attributes, `Validator`,
 * `FormRequest`, `JsonSerializable` and what the shared interpreter names (`BackedEnum`,
 * `DateTimeInterface`) were all imported plainly. Only the type a `format: date-time` resolves to is
 * still warned about, because no spelling can save it.
 */
final class ImportedClassNameCollisionTest extends TestCase
{
    private const array SYMFONY_LIBRARY_NAMES = [
        'AbstractObjectNormalizer', 'Assert', 'BackedEnum', 'Context', 'DateTimeInterface', 'DiscriminatorMap',
        'ExecutionContextInterface', 'Groups', 'Ignore', 'SerializedName',
    ];

    private const array LARAVEL_LIBRARY_NAMES = [
        'BackedEnum', 'Closure', 'FormRequest', 'InvalidArgumentException', 'JsonSerializable', 'LogicException',
        'ValidationRule', 'Validator', 'ValidatorAwareRule',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-collision-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    #[DataProvider('collidingNames')]
    public function testAComponentNamedAfterAnImportedClassIsReported(GenerationMode $mode, string $schemaName, string $className): void
    {
        [$warnings, $lintFailures] = $this->generate(mode: $mode, schemaName: $schemaName);

        $this->assertNotSame([], $lintFailures, 'the shape this pins no longer collides; pick a name that does');
        $this->assertCount(
            expectedCount: 1,
            haystack: array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => str_contains($warning, 'class ' . $className . ','),
            ),
            message: implode("\n", [...$warnings, ...$lintFailures]),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode, string, string}>
     */
    public static function collidingNames(): iterable
    {
        // What a `format: date-time` resolves to is chosen by the mode-neutral type mapper, which does not
        // know the namespace, so the type itself names the document's class: no spelling saves that file.
        foreach ([GenerationMode::Symfony, GenerationMode::Laravel] as $mode) {
            yield $mode->value . ' DateTimeImmutable' => [$mode, 'DateTimeImmutable', 'DateTimeImmutable'];
        }
    }

    /**
     * A mode that resolves its own imports turns the same names into a file that loads: the library
     * class is spelled out or aliased, the document's class keeps its name, and nothing is reported.
     */
    #[DataProvider('resolvedNames')]
    public function testAComponentNamedAfterALibraryClassLoads(GenerationMode $mode, string $schemaName): void
    {
        [$warnings, $lintFailures, $output] = $this->generate(mode: $mode, schemaName: $schemaName);

        $this->assertSame(
            expected: [],
            actual: $lintFailures,
        );
        $this->assertSame(
            expected: [],
            actual: array_values(array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => str_contains($warning, 'is generated as class'),
            )),
        );
        $this->assertSame(
            expected: 'loaded',
            actual: $this->loadEveryFile($output),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode, string}>
     */
    public static function resolvedNames(): iterable
    {
        // `Rule` is what the enum rule is built with, `stdClass` what an empty map is written as; both
        // spellings of the latter, since the document's `stdClass` is generated as `StdClass`.
        foreach (['Rule', 'stdClass', 'StdClass'] as $name) {
            yield 'laravel-data ' . $name => [GenerationMode::LaravelData, $name];
        }
        foreach (['stdClass', 'StdClass', 'JsonSerializable', 'Result'] as $name) {
            yield 'yii3 ' . $name => [GenerationMode::Yii3, $name];
        }
        // laravel shares the enum rule with laravel-data, and writes `stdClass` for the raw-JSON check.
        foreach (['Rule', 'stdClass', 'StdClass'] as $name) {
            yield 'laravel ' . $name => [GenerationMode::Laravel, $name];
        }
        // Symfony's serializer and validator attributes, the callback's context and what the interpreter
        // names: each is spelled out in full, or imported under an alias, beside a class of that name.
        foreach (self::SYMFONY_LIBRARY_NAMES as $name) {
            yield 'symfony ' . $name => [GenerationMode::Symfony, $name];
        }
        foreach (self::LARAVEL_LIBRARY_NAMES as $name) {
            yield 'laravel ' . $name => [GenerationMode::Laravel, $name];
        }
        // The interpreter is shared, and named both classes unqualified in yii3 too.
        foreach (['BackedEnum', 'DateTimeInterface'] as $name) {
            yield 'yii3 ' . $name => [GenerationMode::Yii3, $name];
        }
        // Names those modes resolved before; pinned beside the new ones.
        yield 'laravel-data Request' => [GenerationMode::LaravelData, 'Request'];
        yield 'laravel-data Optional' => [GenerationMode::LaravelData, 'Optional'];
        foreach (['Rule', 'stdClass', 'Context'] as $name) {
            yield 'runtime ' . $name => [GenerationMode::Runtime, $name];
        }
    }

    /**
     * The names above prove something only while the document makes the mode import them: with a name
     * nothing collides with, every one of them is in some generated file's import list.
     *
     * @param list<string> $names
     */
    #[DataProvider('libraryNamesByMode')]
    public function testTheDocumentMakesTheModeImportEveryPinnedName(GenerationMode $mode, array $names): void
    {
        [, , $output] = $this->generate(mode: $mode, schemaName: 'Widget');

        $imported = [];
        foreach (glob($output . '/*.php') ?: [] as $file) {
            preg_match_all('/^use (?:[\w\\\]+\\\)?(\w+)(?: as (\w+))?;$/m', (string)file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $imported[($match[2] ?? '') !== '' ? $match[2] : $match[1]] = true;
            }
        }

        $this->assertSame(
            expected: [],
            actual: array_values(array_diff($names, array_keys($imported))),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode, list<string>}>
     */
    public static function libraryNamesByMode(): iterable
    {
        yield 'symfony' => [GenerationMode::Symfony, self::SYMFONY_LIBRARY_NAMES];
        yield 'laravel' => [GenerationMode::Laravel, self::LARAVEL_LIBRARY_NAMES];
        yield 'yii3' => [GenerationMode::Yii3, ['BackedEnum', 'DateTimeInterface']];
    }

    /**
     * The discriminated union a request body names gets laravel's variant FormRequest, which builds the
     * discriminator's `Rule::in()` — a second place the facade was imported plainly.
     */
    #[DataProvider('everyMode')]
    public function testADiscriminatedUnionNamedRuleLoads(GenerationMode $mode): void
    {
        [$warnings, $lintFailures, $output] = $this->generate(mode: $mode, schemaName: 'Rule', discriminated: true);

        $this->assertSame(
            expected: [],
            actual: $lintFailures,
        );
        $this->assertSame(
            expected: [],
            actual: array_values(array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => str_contains($warning, 'is generated as class'),
            )),
        );
        $this->assertSame(
            expected: 'loaded',
            actual: $this->loadEveryFile($output),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode}>
     */
    public static function everyMode(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * The other half: a name that loads is not reported, in every mode — runtime resolves its own
     * imports, so even `stdClass` is fine there.
     */
    #[DataProvider('harmlessNames')]
    public function testANameThatLoadsIsNotReported(GenerationMode $mode, string $schemaName): void
    {
        [$warnings, $lintFailures, $output] = $this->generate(mode: $mode, schemaName: $schemaName);

        $this->assertSame(
            expected: [],
            actual: $lintFailures,
        );
        $this->assertSame(
            expected: [],
            actual: array_values(array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => str_contains($warning, 'is generated as class'),
            )),
        );
        $this->assertSame(
            expected: 'loaded',
            actual: $this->loadEveryFile($output),
        );
    }

    /**
     * @return iterable<string, array{GenerationMode, string}>
     */
    public static function harmlessNames(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value . ' Widget' => [$mode, 'Widget'];
        }
        yield 'runtime stdClass' => [GenerationMode::Runtime, 'stdClass'];
        yield 'runtime Context' => [GenerationMode::Runtime, 'Context'];
    }

    /**
     * @return array{list<string>, list<string>, string} the warnings, one line per file that does not
     *                                                   lint, and the output directory
     */
    private function generate(GenerationMode $mode, string $schemaName, bool $discriminated = false): array
    {
        $output = $this->root . '/' . $mode->value . '-' . $schemaName;
        $generator = new GenerateDtoCommand();
        $generator->generateFromArray(
            openApi: $discriminated ? $this->discriminatedDocument($schemaName) : $this->document($schemaName),
            outputDirectory: $output,
            namespace: 'Zoo\Collision',
            mode: $mode->value,
        );

        $lintFailures = [];
        foreach (glob($output . '/*.php') ?: [] as $file) {
            $lintOutput = [];
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $lintOutput, $lintStatus);
            if ($lintStatus !== 0) {
                $lintFailures[] = implode(' ', $lintOutput);
            }
        }

        return [array_values($generator->getGenerationWarnings()), $lintFailures, $output];
    }

    /**
     * Requires every generated file in a fresh PHP process, with the framework autoloaded and the
     * generated namespace resolved from the output directory — `php -l` passes a file whose import
     * collides only at compile time of the class, and a sibling's `extends` or `implements` loads more.
     * The FormRequest stub stands in for `laravel/framework`, which this package does not require.
     *
     * @return string `loaded`, or what the process printed instead
     */
    private function loadEveryFile(string $output): string
    {
        // Beside the output directory, not in it: the script requires every `*.php` in there.
        $script = $output . '-load.php';
        file_put_contents(
            filename: $script,
            data: sprintf(
                <<<'PHP'
                    <?php
                    require %s;
                    if (!class_exists('Illuminate\\Foundation\\Http\\FormRequest')) {
                        require %s;
                    }
                    spl_autoload_register(static function (string $class): void {
                        $prefix = 'Zoo\\Collision\\';
                        $file = %s . '/' . substr($class, strlen($prefix)) . '.php';
                        if (str_starts_with($class, $prefix) && is_file($file)) {
                            require_once $file;
                        }
                    });
                    foreach (glob(%s . '/*.php') ?: [] as $file) {
                        require_once $file;
                    }
                    echo 'loaded';
                    PHP,
                var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
                var_export(dirname(__DIR__) . '/Laravel/Stub/form-request.php', true),
                var_export($output, true),
                var_export($output, true),
            ),
        );

        $printed = [];
        exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $printed);
        unlink($script);

        return implode("\n", $printed);
    }

    /**
     * A request body that is a discriminated union of two objects.
     *
     * @return array<string, mixed>
     */
    private function discriminatedDocument(string $schemaName): array
    {
        $pet = static fn(string $extra, string $type): array => [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string'], $extra => ['type' => $type]],
        ];

        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Zoo', 'version' => '1.0.0'],
            'paths' => ['/api/test/animals' => ['post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schemaName]]]],
                'responses' => ['204' => ['description' => 'ok']],
            ]]],
            'components' => ['schemas' => [
                $schemaName => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => [
                        'propertyName' => 'kind',
                        'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog'],
                    ],
                ],
                'Cat' => $pet('lives', 'integer'),
                'Dog' => $pet('bark', 'string'),
            ]],
        ];
    }

    /**
     * Each property is there for an import some mode writes for it: a renamed one for
     * `SerializedName`, a readOnly one for `Groups`, a writeOnly one for `Ignore`, `minProperties` for
     * the callback that takes an `ExecutionContextInterface`, an enum beside a scalar for `BackedEnum`,
     * a discriminated union for `DiscriminatorMap`, and a response for what a FormRequest names.
     *
     * @return array<string, mixed>
     */
    private function document(string $schemaName): array
    {
        $body = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schemaName]]];
        $pet = static fn(string $extra, string $type): array => [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string'], $extra => ['type' => $type]],
        ];

        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Zoo', 'version' => '1.0.0'],
            'paths' => ['/api/test/widgets' => ['post' => [
                'requestBody' => ['content' => $body],
                'responses' => ['200' => ['description' => 'ok', 'content' => $body]],
            ]]],
            'components' => ['schemas' => [
                $schemaName => [
                    'type' => 'object',
                    'required' => ['id'],
                    'minProperties' => 1,
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'seen_at' => ['type' => 'string', 'format' => 'date-time'],
                        'color' => ['type' => 'string', 'enum' => ['red', 'teal']],
                        'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                        'nest' => ['type' => 'object', 'properties' => ['depth' => ['type' => 'integer']]],
                        'sizes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'uniqueItems' => true],
                        'mark' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                        'token' => ['type' => 'string', 'readOnly' => true],
                        'secret' => ['type' => 'string', 'writeOnly' => true],
                        'hue' => ['$ref' => '#/components/schemas/Hue'],
                        'pet' => ['$ref' => '#/components/schemas/Pet'],
                        'tint' => ['anyOf' => [['$ref' => '#/components/schemas/Hue'], ['type' => 'integer', 'minimum' => 5]]],
                    ],
                ],
                'Hue' => ['type' => 'string', 'enum' => ['blue', 'green']],
                'Pet' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => [
                        'propertyName' => 'kind',
                        'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog'],
                    ],
                ],
                'Cat' => $pet('lives', 'integer'),
                'Dog' => $pet('bark', 'string'),
            ]],
        ];
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
