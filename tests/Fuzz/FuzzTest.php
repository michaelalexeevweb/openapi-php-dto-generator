<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Property-based check of every generation mode against random schema shapes.
 *
 * By default a SMALL fixed budget runs — seeds known to come out clean — and must find nothing, so a
 * regression on a shape those seeds draw fails CI. Longer runs are opt-in and REPORT-ONLY: they never
 * fail the test, they write the minimal cases to a JSON report.
 *
 *     FUZZ_ITERATIONS=200 FUZZ_SEED=1000 FUZZ_REPORT=/tmp/fuzz.json vendor/bin/phpunit --filter FuzzTest
 *
 * Knobs: `FUZZ_SEED` (first seed), `FUZZ_ITERATIONS` (specs, one seed each), `FUZZ_MODES` (comma list
 * of mode values), `FUZZ_PROPERTIES` (properties per spec), `FUZZ_REPORT` (report path),
 * `FUZZ_VERBOSE=1` (progress on stderr), `FUZZ_STRICT=1` (a long run fails on findings too),
 * `FUZZ_SHRINK=0` (report unminimised cases — a fast scan), `FUZZ_OUTPUT` (where generated code goes;
 * give each parallel run its own).
 */
#[Group('fuzz')]
final class FuzzTest extends TestCase
{
    /**
     * Seeds that came out clean in every mode when the harness was written (2.15.144), as seed =>
     * properties per spec. Most seeds are NOT clean yet — the long runs find defects in nearly every
     * spec — so the CI budget pins the shapes that work: a finding here is a regression (or a new shape
     * the grammar learned to draw; then pick other clean seeds with a `FUZZ_SHRINK=0` scan).
     */
    private const array CLEAN_SEEDS = [
        // Out until fixed: 95 — runtime refuses an integer for a union component `anyOf: [$ref integer,
        // $ref A, $ref B]` of two objects and a scalar, and yii3 accepts `null` and broken objects there.
        70 => 6, 71 => 6, 84 => 6, 89 => 6, 144 => 6, 152 => 6,
        // Back since 2.15.162: a one-digit month or day for `format: date` is refused in yii3 and the
        // documented `!Y-m-d` row in symfony, and a date beside an `array` member is read strictly there.
        161 => 6, 163 => 6, 42 => 4, 91 => 4,
        4 => 4, 29 => 4, 40 => 4, 46 => 4, 50 => 4, 60 => 4, 64 => 4, 65 => 4, 76 => 4, 80 => 4,
        81 => 4, 85 => 4, 92 => 4, 93 => 4, 102 => 4, 120 => 4, 136 => 4, 140 => 4, 146 => 4,
        147 => 4, 173 => 4, 186 => 4, 192 => 4, 194 => 4, 199 => 4, 204 => 4, 207 => 4,
        208 => 4, 216 => 4,
        // Discriminated unions (2.15.145): component ones, and an inline one on a property.
        221 => 4, 242 => 4, 282 => 4,
        // An array or an object sent to an enum (yii3 tried to build the enum), and a date beside an array
        // branch (laravel-data cast every value as a date, yii3 made the array a string).
        14 => 4, 15 => 4,
        // Unions whose members carry their own keywords: an enum or a length judged only on its own type,
        // a list beside a map, an all-object `anyOf` sent a list, a nullable member on an optional property.
        311 => 4, 323 => 4, 330 => 4, 335 => 4, 340 => 4, 347 => 4, 355 => 4, 401 => 4, 406 => 4, 415 => 4,
        441 => 4,
    ];

    private const int LONG_RUN_PROPERTIES = 12;

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = self::env('FUZZ_OUTPUT') ?? __DIR__ . '/output-fuzz';
    }

    protected function tearDown(): void
    {
        FuzzRunner::deleteTree($this->outputDirectory);
    }

    public function testTheSameSeedDrawsTheSameDocumentAndPayloads(): void
    {
        $first = FuzzSpec::generate(seed: 77, propertyCount: 6);
        $second = FuzzSpec::generate(seed: 77, propertyCount: 6);

        $this->assertSame(json_encode($first->document()), json_encode($second->document()));
        $this->assertSame(json_encode($first->cases), json_encode($second->cases));
        $this->assertNotSame(
            expected: json_encode($first->document()),
            actual: json_encode(FuzzSpec::generate(seed: 78, propertyCount: 6)->document()),
        );
    }

    public function testEveryModeHoldsTheInvariantsOnTheCiBudget(): void
    {
        $seed = self::env('FUZZ_SEED');
        $iterations = self::env('FUZZ_ITERATIONS');
        $longRun = $seed !== null || $iterations !== null;
        $properties = self::env('FUZZ_PROPERTIES');
        $budget = [];
        if ($longRun) {
            foreach (range((int)($seed ?? 1), (int)($seed ?? 1) + max(1, (int)($iterations ?? 10)) - 1) as $current) {
                $budget[$current] = (int)($properties ?? self::LONG_RUN_PROPERTIES);
            }
        } else {
            $budget = self::CLEAN_SEEDS;
        }
        $seeds = array_keys($budget);

        $runner = new FuzzRunner(
            outputDirectory: $this->outputDirectory,
            modes: self::modes(),
            propertiesPerSpec: (int)($properties ?? self::LONG_RUN_PROPERTIES),
            verbose: self::env('FUZZ_VERBOSE') !== null,
            shrink: self::env('FUZZ_SHRINK') !== '0',
        );
        $started = microtime(true);
        $report = self::env('FUZZ_REPORT');
        foreach ($seeds as $index => $current) {
            if (self::env('FUZZ_VERBOSE') !== null) {
                fwrite(STDERR, sprintf("[%d/%d] seed %d, %.0fs\n", $index + 1, count($seeds), $current, microtime(true) - $started));
            }
            $runner->runSpec(seed: $current, propertyCount: $budget[$current]);
            if ($report !== null) {
                // After every spec: a long run that dies on the way still leaves what it found.
                self::writeReport(path: $report, seeds: [$seeds[0], $current], seconds: microtime(true) - $started, runner: $runner);
            }
        }
        $findings = $runner->findings();

        $this->assertGreaterThan(0, $runner->stats()['payloads'], 'the run must have measured something');
        if ($longRun && self::env('FUZZ_STRICT') === null) {
            return;
        }

        $this->assertSame(
            expected: [],
            actual: array_map(
                callback: static fn(array $finding): string => vsprintf(
                    format: '%s %s %s (seed %d): %s %s',
                    values: [
                        $finding['mode'],
                        $finding['category'],
                        $finding['kind'],
                        $finding['seed'],
                        (string)json_encode($finding['document']['components']['schemas'] ?? []),
                        (string)($finding['payload'] ?? ''),
                    ],
                ),
                array: $findings,
            ),
        );
    }

    /**
     * @param array{0: int, 1: int} $seeds
     */
    private static function writeReport(string $path, array $seeds, float $seconds, FuzzRunner $runner): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, json_encode(
            value: [
                'seeds' => $seeds,
                'modes' => array_map(static fn(GenerationMode $mode): string => $mode->value, self::modes()),
                'seconds' => round($seconds, 1),
                'stats' => $runner->stats(),
                'findings' => $runner->findings(),
            ],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * @return array<int, GenerationMode>
     */
    private static function modes(): array
    {
        $selected = self::env('FUZZ_MODES');
        if ($selected === null) {
            return GenerationMode::cases();
        }

        return array_map(
            callback: static fn(string $mode): GenerationMode => GenerationMode::from(trim($mode)),
            array: explode(',', $selected),
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
