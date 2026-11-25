<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * A property whose schema is a union of OBJECTS with no `discriminator` cannot be hydrated by the framework
 * modes, and the document says so at generation time rather than the request finding out. Runtime mode
 * builds the member the payload matches since 2.15.152, and is not warned; the Laravel modes do the same
 * for a PROPERTY typed as such a union since 2.15.153, and are warned about its list items alone. Symfony
 * holds the decoded object since 2.15.155, checked against the members, and is not warned either.
 *
 * Nothing can turn such a payload back into a member: the document does not say which member a given
 * object is, and choosing by structure would be a guess two overlapping branches could not settle. Every
 * mode therefore failed on a payload the document allows, late and differently — measured before this
 * warning existed:
 *
 *     runtime       RuntimeException: Unsupported type: Shape
 *     symfony       NotNormalizableValueException: … must be one of "Shape" ("array" given)
 *     laravel       Error: Call to undefined method Shape::fromValidated()
 *     laravel-data  TypeError: Argument #1 ($shape) must be of type Shape, array given
 *
 * Two of those read as bugs in the generated code rather than as a gap in the document, and the shape was
 * visible at build time in every mode. So the diagnostic is what this pins, in every mode — and the
 * negative half matters as much: a union of SCALARS, a nested class, and a DISCRIMINATED union all hydrate
 * fine, and must not be warned about.
 */
final class UnhydratableUnionParityTest extends TestCase
{
    private string $outputDirectory = '';

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    public function testEveryModeNamesTheUnhydratableUnionAtGenerationTime(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $warnings = $this->generate($mode);

            $matching = array_values(array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => str_contains($warning, 'an undiscriminated oneOf union'),
            ));

            // Runtime builds the member the payload matches (see
            // testRuntimeBuildsTheMemberThePayloadMatches), and symfony holds the decoded object, checked
            // against the members (ValidationParityTest::testAnUndiscriminatedUnionComponentIsUsableWhereverItSits),
            // so neither has anything to warn about.
            if (in_array($mode, [GenerationMode::Runtime, GenerationMode::Symfony], true)) {
                $this->assertSame(expected: [], actual: $matching);

                continue;
            }
            // The Laravel modes build the property as the member it matches, as the inline `Circle|Square`;
            // the list items of the union are still not built.
            if (in_array($mode, [GenerationMode::Laravel, GenerationMode::LaravelData], true)) {
                $this->assertCount(
                    expectedCount: 1,
                    haystack: $matching,
                    message: sprintf("%s mode:\n %s", $mode->value, implode("\n ", $warnings)),
                );
                $this->assertStringContainsString('Property "many" of Probe refers to Shape', $matching[0]);

                continue;
            }
            // Two properties reach the same union: directly, and as the items of an array.
            $this->assertCount(
                expectedCount: 2,
                haystack: $matching,
                message: sprintf("%s mode did not name both properties:\n %s", $mode->value, implode("\n ", $warnings)),
            );

            $this->assertStringContainsString('Property "shape" of Probe refers to Shape', $matching[0]);
            $this->assertStringContainsString('Property "many" of Probe refers to Shape', $matching[1]);
            // The remedy belongs in the message: a warning that only says "no" costs a debugging session.
            $this->assertStringContainsString('Add a discriminator to Shape', $matching[0]);
        }
    }

    /**
     * The shapes that DO hydrate must stay silent, or the warning becomes noise nobody reads.
     */
    public function testTheShapesThatHydrateAreNotWarnedAbout(): void
    {
        foreach (GenerationMode::cases() as $mode) {
            $warnings = implode("\n", $this->generate($mode));

            $this->assertStringNotContainsString('Property "ok"', $warnings, 'a plain nested class hydrates');
            $this->assertStringNotContainsString('Property "either"', $warnings, 'a scalar union is a PHP union type');
            $this->assertStringNotContainsString('Property "animal"', $warnings, 'a discriminated union resolves');
            $this->assertStringNotContainsString('Property "cup"', $warnings, 'one object member or null is that object');
            $this->assertStringNotContainsString('Property "pet"', $warnings, 'one referenced member or null is that member');
        }
    }

    /**
     * Generation still SUCCEEDS. The interface and its members are useful as types, and a document may
     * reference the union only in a response — which is never hydrated, so refusing to generate would take
     * away something that works.
     */
    public function testGenerationStillSucceeds(): void
    {
        $this->generate(GenerationMode::LaravelData);

        $this->assertFileExists($this->outputDirectory . '/Probe.php');
        $this->assertFileExists($this->outputDirectory . '/Shape.php');
    }

    /**
     * And what the REQUEST sees, which the warning above does not cover.
     *
     * Laravel mode is the one that writes its own hydrator, and it used to write
     * `Shape::fromValidated($data['shape'])` against an interface that declares no such method — so a
     * payload the document allows died on `Error: Call to undefined method`, a PHP-level accident naming
     * an internal artefact for a document-level limitation the generator had already reported. It then
     * threw the same sentence the warning carries. The property typed as the union is built as the member
     * it matches since 2.15.153, a list item or map value of it since 2.15.155 — it was still that throw,
     * a 500 for every valid item.
     *
     * The other modes fail inside their framework's own hydration, which is not ours to reshape; this
     * pins the one failure the generator authors.
     */
    public function testLaravelBuildsTheMatchingMemberInsteadOfDyingOnAnUndefinedMethod(): void
    {
        $this->generate(GenerationMode::Laravel);

        $directory = $this->outputDirectory;
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $file = $directory . '/' . substr($class, (int)strrpos($class, chr(92)) + 1) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        });

        try {
            /** @var class-string $probe */
            $probe = 'UnionWarn' . GenerationMode::Laravel->tag() . chr(92) . 'Probe';

            $payload = [
                'shape' => ['kind' => 'circle', 'a' => 1],
                'many' => [],
                'ok' => ['r' => 1],
            ];
            $built = $probe::fromValidated($payload);
            self::assertSame(
                expected: 'UnionWarn' . GenerationMode::Laravel->tag() . chr(92) . 'Square',
                actual: $built->getShape()::class,
            );

            $items = $probe::fromValidated([...$payload, 'many' => [['r' => 1], ['a' => 2]]])->getMany();
            self::assertSame(
                expected: ['Circle', 'Square'],
                actual: array_map(static fn(object $item): string => substr($item::class, (int)strrpos($item::class, chr(92)) + 1), $items),
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * Runtime mode is the exception: the owner's `getConstraints()` names the members as branches, and
     * `DtoDeserializer` builds a payload into the member it matches — directly, as a list item and as a
     * map value — as it does for an inline `Circle|Square`. Under `oneOf` a payload two members accept is
     * refused rather than built as the first. It used to refuse every payload with
     * `Unsupported type: Shape`.
     */
    public function testRuntimeBuildsTheMemberThePayloadMatches(): void
    {
        $this->generate(GenerationMode::Runtime);
        $namespace = 'UnionWarn' . GenerationMode::Runtime->tag();
        $directory = $this->outputDirectory;
        spl_autoload_register($loader = static function (string $class) use ($directory, $namespace): void {
            if (!str_starts_with($class, $namespace . chr(92))) {
                return;
            }
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
        $deserialize = static fn(string $json): object => (new DtoDeserializer())->deserialize(Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        ), $namespace . chr(92) . 'Probe');
        try {
            $probe = $deserialize('{"shape":{"a":2},"many":[{"r":1},{"a":3}],"ok":{"r":1}}');
            self::assertInstanceOf($namespace . chr(92) . 'Square', $probe->getShape());
            self::assertSame(
                expected: [$namespace . chr(92) . 'Circle', $namespace . chr(92) . 'Square'],
                actual: array_map(static fn(object $item): string => $item::class, $probe->getMany()),
            );
            self::assertSame(
                expected: '{"shape":{"a":2},"many":[{"r":1},{"a":3}],"ok":{"r":1}}',
                actual: (new DtoNormalizer())->validateAndNormalizeToJson($probe),
            );

            foreach (['{"shape":{"r":1,"a":2},"many":[],"ok":{"r":1}}', '{"shape":{"r":1},"many":[{"r":1,"a":2}],"ok":{"r":1}}'] as $both) {
                try {
                    $deserialize($both);
                    self::fail('a oneOf payload both members accept must be refused: ' . $both);
                } catch (RuntimeException $thrown) {
                    self::assertStringContainsString('matches more than one allowed oneOf branch', $thrown->getMessage());
                }
            }
            try {
                $deserialize('{"shape":{"x":1},"many":[],"ok":{"r":1}}');
                self::fail('a payload no member accepts must be refused');
            } catch (RuntimeException $thrown) {
                self::assertStringContainsString('"shape.r"', $thrown->getMessage());
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * A member the union writes inline gets a class of its own (`TileAnyOf2`), and runtime builds it from
     * the same `getConstraints()` branches as a `$ref` member — the property, list items and map values.
     */
    public function testRuntimeBuildsAnInlineMemberOfAComponentUnion(): void
    {
        $spec = self::spec();
        $spec['components']['schemas']['Tile'] = ['anyOf' => [
            ['$ref' => '#/components/schemas/Circle'],
            ['type' => 'object', 'required' => ['eel'], 'properties' => ['eel' => ['type' => 'integer']]],
        ]];
        $spec['components']['schemas']['Board'] = [
            'type' => 'object',
            'properties' => [
                'tile' => ['$ref' => '#/components/schemas/Tile'],
                'tiles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tile']],
                'byName' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Tile']],
            ],
        ];
        $this->generate(GenerationMode::Runtime, $spec, 'InlineMember');
        $namespace = 'UnionWarn' . GenerationMode::Runtime->tag() . 'InlineMember';
        $directory = $this->outputDirectory;
        spl_autoload_register($loader = static function (string $class) use ($directory, $namespace): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . chr(92)) && is_file($file)) {
                require $file;
            }
        });
        $deserialize = static fn(string $json): object => (new DtoDeserializer())->deserialize(Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        ), $namespace . chr(92) . 'Board');
        try {
            $json = '{"tile":{"eel":4},"tiles":[{"r":1},{"eel":2}],"byName":{"a":{"eel":3}}}';
            $board = $deserialize($json);
            self::assertInstanceOf($namespace . chr(92) . 'TileAnyOf2', $board->getTile());
            self::assertSame($json, (new DtoNormalizer())->validateAndNormalizeToJson($board));
            try {
                $deserialize('{"tile":{"eel":"x"}}');
                self::fail('a payload no member accepts must be refused');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testInlineObjectUnionMembersAreDiagnosedInEveryMode(): void
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ([false, true] as $nested) {
                foreach (GenerationMode::cases() as $mode) {
                    $spec = self::inlineContactSpec($keyword);
                    if ($nested) {
                        $branches = $spec['components']['schemas']['Shape'][$keyword];
                        $spec['components']['schemas']['Shape'][$keyword] = [['anyOf' => $branches]];
                    }
                    $suffix = 'Inline' . ucfirst($keyword) . ($nested ? 'Nested' : '');
                    $warnings = $this->generate($mode, $spec, $suffix);
                    $matching = array_values(array_filter(
                        array: $warnings,
                        callback: static fn(string $warning): bool => str_contains($warning, 'an undiscriminated ' . $keyword . ' union'),
                    ));
                    $silent = in_array($mode, [GenerationMode::Runtime, GenerationMode::Symfony], true);
                    self::assertCount($silent ? 0 : 3, $matching, $mode->value . ': ' . implode(' ', $warnings));
                    foreach ($matching as $warning) {
                        self::assertStringContainsString('Add a discriminator to Shape', $warning);
                    }
                    $this->deleteRecursively($this->outputDirectory);
                }
            }
        }
    }

    public function testLaravelInlineUnionNeverCallsAnInterfaceFactory(): void
    {
        foreach (['shape', 'many', 'byName'] as $field) {
            $suffix = 'InlineHydration' . ucfirst($field);
            $this->generate(GenerationMode::Laravel, self::inlineContactSpec('oneOf'), $suffix);
            $namespace = 'UnionWarn' . GenerationMode::Laravel->tag() . $suffix;
            $directory = $this->outputDirectory;
            spl_autoload_register($loader = static function (string $class) use ($directory, $namespace): void {
                if (!str_starts_with($class, $namespace . chr(92))) {
                    return;
                }
                $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            });
            try {
                $probe = $namespace . chr(92) . 'Probe';
                $contact = ['type' => 'email', 'value' => 'a@example.com'];
                $payload = ['shape' => null, 'many' => [], 'byName' => [], 'ok' => ['r' => 1]];
                $empty = $probe::fromValidated($payload);
                self::assertSame([], $empty->getMany());
                self::assertSame([], $empty->getByName());
                self::assertNull($empty->getShape());
                $payload[$field] = match ($field) {
                    'shape' => $contact,
                    'many' => [$contact],
                    default => ['primary' => $contact],
                };
                try {
                    $probe::fromValidated($payload);
                    self::fail('Inline object unions need a discriminator for hydration.');
                } catch (RuntimeException $thrown) {
                    self::assertStringContainsString('Property "' . $field . '"', $thrown->getMessage());
                    self::assertStringContainsString('Add a discriminator to Shape', $thrown->getMessage());
                }
            } finally {
                spl_autoload_unregister($loader);
                $this->deleteRecursively($directory);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function inlineContactSpec(string $keyword): array
    {
        $spec = self::spec();
        $spec['components']['schemas']['Shape'] = [
            'type' => 'object',
            'required' => ['type', 'value'],
            'additionalProperties' => false,
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['email', 'phone']],
                'value' => ['type' => 'string'],
            ],
            $keyword => [
                ['properties' => ['type' => ['type' => 'string', 'enum' => ['email']], 'value' => ['type' => 'string', 'format' => 'email']]],
                ['properties' => ['type' => ['type' => 'string', 'enum' => ['phone']], 'value' => ['type' => 'string', 'pattern' => '^\+?[0-9]{7,15}$']]],
            ],
        ];
        $spec['components']['schemas']['Probe']['properties']['shape']['nullable'] = true;
        $spec['components']['schemas']['Probe']['properties']['byName'] = [
            'type' => 'object',
            'additionalProperties' => ['$ref' => '#/components/schemas/Shape'],
        ];

        return $spec;
    }

    /**
     * @param array<string, mixed>|null $spec
     * @return array<int, string>
     */
    private function generate(GenerationMode $mode, ?array $spec = null, string $suffix = ''): array
    {
        $this->outputDirectory = sys_get_temp_dir() . '/union-warn-' . strtolower($mode->tag()) . $suffix . '-' . getmypid();
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        $generator = new GenerateDtoCommand();
        $generator->generateFromArray(
            openApi: $spec ?? self::spec(),
            outputDirectory: $this->outputDirectory,
            namespace: 'UnionWarn' . $mode->tag() . $suffix,
            mode: $mode->value,
        );

        return $generator->getGenerationWarnings();
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['shape', 'many', 'ok'],
                        'properties' => [
                            // The two that cannot be hydrated.
                            'shape' => ['$ref' => '#/components/schemas/Shape'],
                            'many' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shape']],
                            // And the three that can.
                            'ok' => ['$ref' => '#/components/schemas/Circle'],
                            'either' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                            'animal' => ['$ref' => '#/components/schemas/Animal'],
                            // A union with ONE object member is that object: nothing to choose between.
                            'cup' => ['$ref' => '#/components/schemas/Cup'],
                            'pet' => ['$ref' => '#/components/schemas/MaybeCircle'],
                        ],
                    ],
                    'Shape' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/Circle'],
                            ['$ref' => '#/components/schemas/Square'],
                        ],
                    ],
                    'Cup' => ['oneOf' => [['type' => 'object', 'properties' => ['size' => ['type' => 'number']]], ['type' => 'null']]],
                    'MaybeCircle' => ['oneOf' => [['$ref' => '#/components/schemas/Circle'], ['type' => 'null']]],
                    'Circle' => ['type' => 'object', 'required' => ['r'], 'properties' => ['r' => ['type' => 'integer']]],
                    'Square' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]],
                    'Animal' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/Cat'],
                            ['$ref' => '#/components/schemas/Dog'],
                        ],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => [
                                'cat' => '#/components/schemas/Cat',
                                'dog' => '#/components/schemas/Dog',
                            ],
                        ],
                    ],
                    'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
                    'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
                ],
            ],
        ];
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
