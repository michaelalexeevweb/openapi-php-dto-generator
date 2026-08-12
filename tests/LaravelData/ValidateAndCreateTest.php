<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Spatie\LaravelData\Optional;

/**
 * The OTHER entry point of a generated `Data` class: `validateAndCreate($array)`, for a caller holding an
 * array rather than a request.
 *
 * Everything else in the suites drives `from($request)`, because that is what an application writes and
 * the only path laravel-data validates by default (`validation_strategy: OnlyRequests`). This one exists
 * for what the array path does DIFFERENTLY, which the emitted class documents and nothing measured:
 *
 * > One check needs the UNDECODED body … Only the request still has those bytes, so on a
 * > `validateAndCreate($array)` call, where there is no request, that single check is skipped and
 * > everything else still runs.
 *
 * A documented limitation nobody exercises is a claim, not a boundary. Both halves of it are asserted
 * here: the skip really is limited to the wire-shape check, and every other rule still fires.
 */
final class ValidateAndCreateTest extends TestCase
{
    private string $outputDirectory = '';

    protected function setUp(): void
    {
        LaravelDataContainer::boot();
    }

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    /**
     * The array path validates. Without this, everything below could pass for the wrong reason: on
     * `from($array)` laravel-data does not validate at all.
     */
    public function testTheArrayPathEnforcesTheEmittedRules(): void
    {
        /** @var class-string $fqcn */
        $fqcn = $this->generate() . '\Probe';

        $this->expectException(ValidationException::class);

        try {
            $fqcn::validateAndCreate(['m' => ['a' => 1], 'n' => 2]);
        } catch (ValidationException $exception) {
            // `n` has `minimum: 5`, a plain rule — so the failure is the rule set, not the interpreter.
            $this->assertArrayHasKey('n', $exception->errors());

            throw $exception;
        }
    }

    /**
     * And the interpreter runs on the array path too: everything it owns that does not need the raw bytes
     * is unaffected by there being no request.
     */
    public function testTheInterpreterStillRunsWithoutARequest(): void
    {
        /** @var class-string $fqcn */
        $fqcn = $this->generate() . '\Probe';

        $this->expectException(ValidationException::class);

        try {
            // `propertyNames` is interpreter-owned: no Laravel rule can express it.
            $fqcn::validateAndCreate(['m' => ['nope' => 1], 'n' => 9]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['m' => ['m key "nope" must match pattern ^x']],
                $exception->errors(),
            );

            throw $exception;
        }
    }

    /**
     * The documented gap, pinned: a JSON array for a `type: object` property is refused through a request
     * and ACCEPTED without one, because the check reads the undecoded body and an array argument has no
     * body to read. Asserted in both directions, so the day laravel-data hands `withValidator()` the raw
     * payload this test says the documentation can change.
     */
    public function testTheWireShapeCheckIsSkippedWithoutARequestAndEnforcedWithOne(): void
    {
        $namespace = $this->generate();
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        $asArray = ['m' => [1, 2], 'n' => 9];

        $dto = $fqcn::validateAndCreate($asArray);
        $this->assertSame(
            [1, 2],
            $dto->m,
            'without a request the wire shape is unknowable, so the array is accepted',
        );

        $rejection = LaravelDataContainer::withRequest(
            '{"m":[1,2],"n":9}',
            static function (Request $request) use ($fqcn): ?array {
                try {
                    $fqcn::from($request);

                    return null;
                } catch (ValidationException $exception) {
                    return $exception->errors();
                }
            },
        );

        $this->assertSame(
            ['m' => ['m expects object, got array']],
            $rejection,
            'through a request the same payload must be refused',
        );
    }

    /**
     * Presence works the same on both paths: absence is the property's own type, not something read off
     * the request.
     */
    public function testPresenceIsTrackedOnTheArrayPathToo(): void
    {
        /** @var class-string $fqcn */
        $fqcn = $this->generate() . '\Probe';

        $dto = $fqcn::validateAndCreate(['m' => ['xa' => 1], 'n' => 9]);
        $this->assertInstanceOf(Optional::class, $dto->note);
        $this->assertSame(['m' => ['xa' => 1], 'n' => 9], $dto->toArray());

        $withNote = $fqcn::validateAndCreate(['m' => ['xa' => 1], 'n' => 9, 'note' => 'x']);
        $this->assertSame('x', $withNote->note);
    }

    private function generate(): string
    {
        $namespace = 'ArrayPath' . substr(md5((string)$this->name()), 0, 8);
        $this->outputDirectory = sys_get_temp_dir() . '/ld-array-' . strtolower($namespace);
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray(
            self::spec(),
            $this->outputDirectory,
            $namespace,
            GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );

        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }

    /**
     * `m` carries both an interpreter-owned keyword (`propertyNames`) and the wire-shape question;
     * `n` is a plain rule; `note` is optional, for presence.
     *
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
                        'required' => ['m', 'n'],
                        'properties' => [
                            'm' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'integer'],
                                'propertyNames' => ['pattern' => '^x'],
                            ],
                            'n' => ['type' => 'integer', 'minimum' => 5],
                            'note' => ['type' => 'string'],
                        ],
                    ],
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
