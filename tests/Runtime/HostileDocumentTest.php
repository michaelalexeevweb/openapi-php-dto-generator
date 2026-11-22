<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use FilesystemIterator;
use OpenapiPhpDtoGenerator\Command\GeneratedFilePublisher;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use stdClass;

/**
 * What an attacker-supplied document can do to the GENERATOR and to the code it writes: a document is
 * text from outside, and none of it may become a phpDoc tag, an identifier that reads differently from
 * what it is, a run that eats the machine, or a crash with nothing said.
 */
final class HostileDocumentTest extends TestCase
{
    private const string INJECTED_TAG = '@injected';

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string)realpath(sys_get_temp_dir()) . '/opg-hostile-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * A line break in document text that lands in a docblock starts a new docblock line, and a line
     * that starts with `@` is a TAG to every static analyser and IDE: a `format` of
     * `x\n * @var \Other` rewrote the type a reviewer saw. Descriptions were folded onto one line; the
     * rest of the document text — `format` and `pattern` in the constructor's constraints line first —
     * was not. Every spelling of a line break is tried, because a phpDoc parser splits on all of them.
     */
    #[DataProvider('modeProvider')]
    public function testDocumentTextNeverStartsADocblockLine(GenerationMode $mode): void
    {
        $directory = $this->generate(spec: self::injectingSpec(mode: $mode), mode: $mode);

        $docLines = 0;
        foreach ($this->docCommentLines($directory) as $file => $line) {
            $docLines++;
            $this->assertStringStartsNotWith(
                prefix: self::INJECTED_TAG,
                string: $line,
                message: sprintf('%s mode: %s carries a docblock tag the document injected', $mode->value, $file),
            );
        }
        $this->assertGreaterThan(expected: 0, actual: $docLines);
    }

    /**
     * The fold is a docblock concern only: the constraint the runtime enforces is still the document's
     * pattern, line breaks and all, and a pattern without one reads in the docblock exactly as written.
     */
    public function testFoldingLeavesTheEnforcedPatternAlone(): void
    {
        $directory = $this->generate(
            spec: self::document(schemas: ['Widget' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'pattern' => "^a\n * @injected\n$"],
                    'name' => ['type' => 'string', 'pattern' => '^[a-z]+  [0-9]$'],
                ],
            ]]),
            mode: GenerationMode::Runtime,
        );

        $source = (string)file_get_contents($directory . '/Widget.php');
        $this->assertStringContainsString(needle: 'pattern=^[a-z]+  [0-9]$', haystack: $source);
        $this->assertStringContainsString(needle: 'pattern=^a * @injected $' . "\n", haystack: $source);

        require_once $directory . '/Widget.php';
        /** @var array<string, array<string, mixed>> $constraints */
        $constraints = call_user_func([$this->fqcn(directory: $directory, className: 'Widget'), 'getConstraints']);
        $this->assertSame(expected: "^a\n * @injected\n$", actual: $constraints['code']['pattern'] ?? null);
    }

    /**
     * Unicode format characters (category Cf) render as nothing or reorder the text around them, so an
     * identifier carrying one is two names that look like one — `$is<U+200B>admin` beside `$isAdmin` —
     * or a line that displays differently from what it does (U+202E). Class names were already ASCII;
     * property, accessor, parameter and flag names kept them. The wire name is data and keeps them.
     */
    #[DataProvider('modeProvider')]
    public function testNoIdentifierCarriesAFormatCharacter(GenerationMode $mode): void
    {
        $directory = $this->generate(
            spec: self::document(
                schemas: ['Widget' => [
                    'type' => 'object',
                    'required' => ["is\u{200B}Admin"],
                    'properties' => [
                        "is\u{200B}Admin" => ['type' => 'boolean'],
                        "role\u{202E}" => ['type' => 'string'],
                        "\u{FEFF}tone" => ['type' => 'string'],
                        "hue\u{2060}\u{200D}" => ['type' => 'string', 'enum' => ['crimson', 'azure']],
                        // Letters outside ASCII are identifier characters and stay.
                        'имя' => ['type' => 'string'],
                    ],
                ]],
                paths: ['/widgets/{shape}' => ['get' => [
                    'operationId' => 'listWidgets',
                    'parameters' => [
                        ['name' => "shape\u{200C}", 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => "page\u{202A}Size", 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Widget']]]]],
                ]]],
            ),
            mode: $mode,
        );

        $identifiers = [];
        foreach ($this->phpFiles($directory) as $file) {
            foreach (token_get_all((string)file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertDoesNotMatchRegularExpression(
                        pattern: '/\p{Cf}/u',
                        string: $token[1],
                        message: sprintf('%s mode: %s declares an identifier with a format character', $mode->value, basename($file)),
                    );
                    $identifiers[$token[1]] = true;
                }
            }
        }
        $this->assertArrayHasKey(key: '$isAdmin', array: $identifiers);
        $this->assertArrayHasKey(key: '$имя', array: $identifiers);
    }

    /**
     * @return iterable<string, array{GenerationMode}>
     */
    public static function modeProvider(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * Stripping a format character can make two names one; that is the collision every other spelling
     * of the same name already hits, and it gets the same answer — `is_admin` beside `isAdmin` included.
     */
    public function testAStrippedNameCollidesLikeAnyOtherSpelling(): void
    {
        $outcome = function (string $spelling): string {
            try {
                $this->generate(
                    spec: self::document(schemas: ['Widget' => [
                        'type' => 'object',
                        'properties' => [$spelling => ['type' => 'boolean'], 'isAdmin' => ['type' => 'boolean']],
                    ]]),
                    mode: GenerationMode::Runtime,
                );
            } catch (RuntimeException $exception) {
                return str_replace($spelling, '<name>', $exception->getMessage());
            }

            return 'generated';
        };

        $this->assertSame(expected: $outcome('is_admin'), actual: $outcome("is\u{200B}Admin"));
        $this->assertStringContainsString(needle: 'collision', haystack: $outcome("is\u{200B}Admin"));
    }

    /**
     * Aliases share one parsed array between every place that names it, so 600 bytes of anchors stood
     * for 3.3 million nodes — under the old fixed budget — and took 16 s, 856 MB and wrote 15 MB of
     * DTOs. The expansion is now weighed against the text it came from, so the document is refused at
     * once, before anything is built.
     */
    public function testAliasAmplificationIsRefusedQuickly(): void
    {
        $yaml = "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\nx-a0: &a0 [x, x, x, x, x, x, x, x, x, x]\n";
        for ($level = 1; $level <= 5; $level++) {
            $yaml .= sprintf("x-a%d: &a%d [%s]\n", $level, $level, implode(', ', array_fill(0, 10, '*a' . ($level - 1))));
        }
        $yaml .= "components:\n  schemas:\n";
        foreach (['Alpha', 'Beta', 'Gamma'] as $schema) {
            $yaml .= sprintf("    %s: {type: object, properties: {p: {type: string, enum: *a5}}}\n", $schema);
        }
        file_put_contents($this->root . '/api.yaml', $yaml);

        $started = microtime(true);
        try {
            (new GenerateDtoCommand())->generateFromFile($this->root . '/api.yaml', $this->root . '/out', 'Bomb');
            $this->fail('An alias bomb must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(needle: 'YAML aliases', haystack: $exception->getMessage());
        }
        $this->assertLessThan(expected: 2.0, actual: microtime(true) - $started);
        $this->assertDirectoryDoesNotExist($this->root . '/out');
    }

    /**
     * The budget must not cost an honest document its anchors: one shared enum and one shared object
     * named from forty places is what anchors are for.
     */
    public function testOrdinaryAnchorReuseStillGenerates(): void
    {
        $yaml = "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\n"
            . "x-colors: &colors [crimson, azure, amber, jade, coral, slate, ivory, umber]\n"
            . "x-size: &size {type: object, properties: {width: {type: integer}, height: {type: integer}, depth: {type: integer}}}\n"
            . "components:\n  schemas:\n";
        for ($index = 1; $index <= 40; $index++) {
            $yaml .= sprintf("    Widget%d: {type: object, properties: {color: {type: string, enum: *colors}, size: *size}}\n", $index);
        }
        file_put_contents($this->root . '/api.yaml', $yaml);

        (new GenerateDtoCommand())->generateFromFile($this->root . '/api.yaml', $this->root . '/out', 'Anchors');

        $this->assertFileExists($this->root . '/out/Widget40.php');
    }

    /**
     * symfony/yaml lexes a flow collection by recursing once per bracket, before its own nesting limit
     * applies, so 100 000 `[` killed the process with SIGSEGV and said nothing. Run as a subprocess,
     * because on the old code it is the PROCESS that dies.
     */
    public function testDeepFlowNestingIsRefusedNotACrash(): void
    {
        file_put_contents(
            filename: $this->root . '/api.yaml',
            data: "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\nx-deep: " . str_repeat('[', 100000) . str_repeat(']', 100000) . "\n",
        );

        [$exitCode, $output] = $this->runConsole(file: $this->root . '/api.yaml');

        $this->assertSame(expected: 1, actual: $exitCode, message: $output);
        $this->assertStringContainsString(needle: 'nests flow collections', haystack: $output);
        $this->assertDirectoryDoesNotExist($this->root . '/out');
    }

    #[DataProvider('bracketsThatAreNotNestingProvider')]
    public function testBracketsThatAreNotNestingStillGenerate(string $extension): void
    {
        file_put_contents(
            filename: $this->root . '/api.yaml',
            data: "openapi: 3.0.3\ninfo: {title: t, version: '1'}\npaths: {}\n" . $extension
            . "components:\n  schemas:\n    Widget: {type: object, properties: {name: {type: string}}}\n",
        );

        (new GenerateDtoCommand())->generateFromFile($this->root . '/api.yaml', $this->root . '/out', 'Brackets');

        $this->assertFileExists($this->root . '/out/Widget.php');
    }

    /**
     * What the depth scan must NOT count: brackets inside quoted strings, in block scalars, in comments
     * and in plain text (`[0, 1)` is a range, not a sequence), and an honest flow nesting a few levels
     * deep. Each is far past the limit if counted, and each document generates.
     *
     * @return iterable<string, array{string}>
     */
    public static function bracketsThatAreNotNestingProvider(): iterable
    {
        $deep = str_repeat('[', 600);
        yield 'double-quoted' => ['x-text: "' . $deep . "\"\n"];
        yield 'single-quoted with an escaped quote' => ["x-text: 'it''s " . $deep . "'\n"];
        yield 'quoted inside a flow sequence' => ["x-text: [a, '" . $deep . "', \"" . $deep . "\"]\n"];
        yield 'block scalar' => ["x-text: |\n  Don't " . $deep . "\n  'unclosed\nx-after: [a, [b]]\n"];
        yield 'comment' => ['x-text: a # ' . $deep . "\n"];
        yield 'plain scalars' => [implode(
            separator: '',
            array: array_map(
                callback: static fn(int $index): string => sprintf("x-range%d: values in [0, 1)\n", $index),
                array: range(1, 600),
            ),
        )];
        yield 'honest flow nesting' => ['x-matrix: ' . str_repeat('[', 40) . 'a' . str_repeat(']', 40) . "\n"];
    }

    /**
     * Running out of memory on a large document ended in a bare PHP fatal; the run now says what
     * happened and how to give it more room, exits non-zero, and has written nothing.
     */
    public function testRunningOutOfMemoryIsExplained(): void
    {
        $schemas = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $properties = [];
            foreach (['red', 'green', 'blue', 'cyan'] as $property) {
                $properties[$property] = ['type' => 'string', 'description' => str_repeat('word ', 100000)];
            }
            $schemas[$name] = ['type' => 'object', 'properties' => $properties];
        }
        file_put_contents($this->root . '/api.json', (string)json_encode(self::document($schemas)));

        [$exitCode, $output] = $this->runConsole(file: $this->root . '/api.json', memoryLimit: '40M');

        $this->assertNotSame(expected: 0, actual: $exitCode, message: $output);
        $this->assertStringContainsString(needle: 'ran out of memory', haystack: $output);
        $this->assertStringContainsString(needle: 'php -d memory_limit=1G', haystack: $output);
        $this->assertStringContainsString(needle: 'api.json', haystack: $output);
        $this->assertDirectoryDoesNotExist($this->root . '/out');
    }

    /**
     * The last line of defence: whatever the document did, a run that would write more than the cap is
     * refused before a single file lands.
     */
    public function testOutputOverTheCapIsRefusedBeforeWriting(): void
    {
        $publisher = new GeneratedFilePublisher(maxOutputBytes: 1000);
        $files = [
            $this->root . '/out/Alpha.php' => "<?php\n// auto-generated from an OpenAPI schema\n" . str_repeat('// x' . "\n", 150),
            $this->root . '/out/Beta.php' => "<?php\n// auto-generated from an OpenAPI schema\n" . str_repeat('// x' . "\n", 150),
        ];

        try {
            $publisher->publish(files: $files, ownedDirectories: [$this->root . '/out']);
            $this->fail('Output over the cap must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(needle: 'over the limit', haystack: $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/out');

        (new GeneratedFilePublisher(maxOutputBytes: 2000))->publish(files: $files, ownedDirectories: [$this->root . '/out']);
        $this->assertFileExists($this->root . '/out/Beta.php');
    }

    /**
     * @return array<string, mixed>
     */
    private static function injectingSpec(GenerationMode $mode): array
    {
        $tag = static fn(string $where, string $break = "\n"): string => 'text' . $break . ' * ' . self::INJECTED_TAG . ' ' . $where . $break;

        // A line break in a WIRE name is refused outright by the Laravel modes — their validator reads
        // a rule key as a path — so the name travels only where it is accepted.
        $wireName = in_array($mode, [GenerationMode::Laravel, GenerationMode::LaravelData], true)
            ? []
            : [$tag('wire name') => ['type' => 'string']];

        return self::document(
            schemas: [
                'Widget' => [
                    'type' => 'object',
                    'title' => $tag('title'),
                    'description' => $tag('description', "\r\n"),
                    'required' => ['code'],
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'format' => $tag('format'),
                            'pattern' => '^a' . $tag('pattern', "\r\n") . '$',
                            'description' => $tag('property description', "\r"),
                            'example' => $tag('example', "\r"),
                        ],
                        'shade' => [
                            'type' => 'string',
                            'enum' => ['crimson', 'azure'],
                            'x-enum-descriptions' => [$tag('enum description'), 'blue'],
                        ],
                        'count' => ['type' => 'integer', 'format' => $tag('integer format', "\r")],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => $tag('item format'), 'pattern' => '^b' . $tag('item pattern')]],
                        ...$wireName,
                    ],
                ],
            ],
            paths: ['/widgets/{shape}' => [
                'post' => [
                    'operationId' => 'createWidget',
                    'summary' => $tag('summary'),
                    'description' => $tag('operation description'),
                    'parameters' => [
                        ['name' => 'shape', 'in' => 'path', 'required' => true, 'description' => $tag('parameter'), 'schema' => ['type' => 'string', 'format' => $tag('parameter format'), 'pattern' => '^c' . $tag('parameter pattern')]],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'format' => $tag('query format', "\r\n")]],
                    ],
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Widget']]]],
                    'responses' => ['200' => ['description' => $tag('response'), 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Widget']]]]],
                ],
            ]],
        );
    }

    /**
     * @param array<string, mixed> $schemas
     * @param array<string, mixed> $paths
     * @return array<string, mixed>
     */
    private static function document(array $schemas, array $paths = []): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Widgets', 'version' => '1.0.0'],
            'paths' => $paths === [] ? new stdClass() : $paths,
            'components' => ['schemas' => $schemas],
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function generate(array $spec, GenerationMode $mode): string
    {
        $directory = $this->root . '/out-' . $mode->tag() . '-' . bin2hex(random_bytes(4));
        (new GenerateDtoCommand())->generateFromArray(
            openApi: (array)json_decode((string)json_encode($spec), true),
            outputDirectory: $directory,
            namespace: 'Hostile' . $mode->tag() . bin2hex(random_bytes(4)),
            mode: $mode->value,
        );

        return $directory;
    }

    /**
     * @return class-string
     */
    private function fqcn(string $directory, string $className): string
    {
        preg_match('/^namespace ([^;]+);/m', (string)file_get_contents($directory . '/' . $className . '.php'), $match);

        /** @var class-string $fqcn */
        $fqcn = ($match[1] ?? '') . '\\' . $className;

        return $fqcn;
    }

    /**
     * Every docblock line of every generated file, stripped of its comment markup.
     *
     * @return iterable<string, string> file name => line
     */
    private function docCommentLines(string $directory): iterable
    {
        foreach ($this->phpFiles($directory) as $file) {
            foreach (token_get_all((string)file_get_contents($file)) as $token) {
                if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
                    continue;
                }
                foreach (preg_split('/\R/u', $token[1]) ?: [] as $line) {
                    yield basename($file) => ltrim($line, " \t/*");
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        $this->assertNotSame(expected: [], actual: $files);

        return $files;
    }

    /**
     * @return array{int, string} exit code and everything the run printed
     */
    private function runConsole(string $file, string $memoryLimit = '256M'): array
    {
        $command = sprintf(
            '%s -d memory_limit=%s %s openapi:generate-dto --file=%s --directory=%s --namespace=Hostile 2>&1',
            escapeshellarg(PHP_BINARY),
            $memoryLimit,
            escapeshellarg(dirname(__DIR__, 2) . '/bin/console'),
            escapeshellarg($file),
            escapeshellarg($this->root . '/out'),
        );
        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
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
