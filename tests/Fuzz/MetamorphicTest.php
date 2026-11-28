<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadOutcome;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The metamorphic layer: every {@see SpecRewriter} rewrite is equivalent by the reference oracle, and
 * the {@see MetamorphicRunner} compares each mode with itself across the two spellings.
 *
 * By default a small budget runs. A long run is opt-in and REPORT-ONLY (`tests/Fuzz/fuzz-metamorphic.sh`):
 *
 *     METAMORPHIC_SEED=1000 METAMORPHIC_ITERATIONS=20 METAMORPHIC_REPORT=/tmp/mm.json vendor/bin/phpunit --filter MetamorphicTest
 *
 * Knobs: `METAMORPHIC_SEED`, `METAMORPHIC_ITERATIONS`, `METAMORPHIC_PROPERTIES`, `METAMORPHIC_MODES`
 * (comma list), `METAMORPHIC_REWRITES` (comma list), `METAMORPHIC_REPORT`, `METAMORPHIC_OUTPUT`,
 * `METAMORPHIC_VERBOSE=1`.
 */
#[Group('fuzz')]
final class MetamorphicTest extends TestCase
{
    private const int ORACLE_SEEDS = 40;

    private const int LONG_RUN_PROPERTIES = 12;

    /**
     * The CI budget, as seed => properties per spec (all modes, every rewrite): seeds that came out
     * clean when the layer was written (2.15.165), so a finding here is a regression. Seed 70 is out
     * until fixed: a map component `{type: object, additionalProperties: {type: boolean}}` wrapped in
     * `allOf` lets runtime accept a non-boolean value. 63 is a 3.1 document, 71 a 3.0 one, and every
     * rewrite finds a site in both.
     */
    private const array CI_SEEDS = [63 => 4, 71 => 4];

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = self::env('METAMORPHIC_OUTPUT') ?? __DIR__ . '/output-metamorphic';
    }

    protected function tearDown(): void
    {
        FuzzRunner::deleteTree($this->outputDirectory);
    }

    /**
     * On fuzzed specs, the oracle judges every payload the same on the rewritten document as on the
     * original — if it does not, the rewrite is wrong, not a generator.
     */
    #[DataProvider('rewrites')]
    public function testTheRewriteIsEquivalentByTheOracle(string $rewrite): void
    {
        $applied = 0;
        $unsound = [];
        for ($seed = 1; $seed <= self::ORACLE_SEEDS; $seed++) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: 6);
            $document = $spec->document();
            $random = new FuzzRandom($seed);
            foreach (array_keys($spec->cases) as $property) {
                $rewritten = SpecRewriter::apply(rewrite: $rewrite, document: $document, property: $property, random: $random)['rewritten'];
                if ($rewritten === null) {
                    continue;
                }
                $applied++;
                $disagreements = MetamorphicRunner::oracleDisagreements(
                    original: $document,
                    rewritten: $rewritten,
                    jsons: array_column(MetamorphicRunner::payloadsOf($spec), 'json'),
                );
                if ($disagreements !== []) {
                    $unsound[] = sprintf('seed %d %s (%s): %s', $seed, $rewritten->site(), $rewritten->note, $disagreements[0]);
                }
            }
        }

        $this->assertGreaterThan(self::ORACLE_SEEDS, $applied, 'the rewrite must find sites on fuzzed specs');
        $this->assertSame([], $unsound);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rewrites(): iterable
    {
        foreach (SpecRewriter::REWRITES as $rewrite) {
            yield $rewrite => [$rewrite];
        }
    }

    public function testTheOracleCheckCatchesARewriteThatChangesTheMeaning(): void
    {
        $document = self::document(openApi: '3.1.0', property: ['type' => 'string', 'enum' => ['crimson', 'azure']]);
        $changed = new RewrittenSpec(
            rewrite: 'broken',
            document: self::document(openApi: '3.1.0', property: ['type' => 'string']),
            externals: [],
            path: [],
            choiceSeed: 0,
            note: 'enum dropped',
        );

        $this->assertSame(['{"color":"teal"}'], MetamorphicRunner::oracleDisagreements(original: $document, rewritten: $changed, jsons: ['{"color":"crimson"}', '{"color":"teal"}']));
    }

    public function testATypeListBecomesAUnionWithNullLeftOutOfTheEnum(): void
    {
        $document = self::document(openApi: '3.1.0', property: ['type' => ['string', 'null'], 'enum' => ['crimson', null], 'maxLength' => 7, 'description' => 'A colour.']);

        $rewritten = SpecRewriter::applyAt(rewrite: SpecRewriter::NULLABLE_SPELLING, document: $document, path: self::propertyPath(), choiceSeed: 3);

        $this->assertInstanceOf(RewrittenSpec::class, $rewritten);
        $schema = $rewritten->document['components']['schemas']['Probe']['properties']['color'];
        $union = $schema['anyOf'] ?? $schema['oneOf'];
        $this->assertSame([['type' => 'string', 'enum' => ['crimson'], 'maxLength' => 7], ['type' => 'null']], $union);
        $this->assertSame('A colour.', $schema['description']);
        $this->assertSame([], MetamorphicRunner::oracleDisagreements(
            original: $document,
            rewritten: $rewritten,
            jsons: ['{"color":null}', '{"color":"crimson"}', '{"color":"azure"}', '{"color":3}'],
        ));
    }

    public function testASplitKeepsThe30NullableBesideItsTypeAndSkipsADisputedEnum(): void
    {
        $document = self::document(openApi: '3.0.3', property: ['type' => 'integer', 'nullable' => true, 'minimum' => 2, 'enum' => [2, 4, null]]);

        $rewritten = SpecRewriter::applyAt(rewrite: SpecRewriter::SPLIT_SCALAR, document: $document, path: self::propertyPath(), choiceSeed: 1);

        $this->assertInstanceOf(RewrittenSpec::class, $rewritten);
        $branches = $rewritten->document['components']['schemas']['Probe']['properties']['color']['allOf'];
        $this->assertContains(['type' => 'integer', 'nullable' => true], $branches);
        $this->assertContains(['minimum' => 2, 'enum' => [2, 4, null]], $branches);

        $disputed = self::document(openApi: '3.0.3', property: ['type' => 'integer', 'nullable' => true, 'enum' => [2, 4]]);
        $this->assertSame(
            expected: SpecRewriter::SKIP_ENUM_WITHOUT_NULL,
            actual: SpecRewriter::applyAt(rewrite: SpecRewriter::SPLIT_SCALAR, document: $disputed, path: self::propertyPath(), choiceSeed: 1),
        );
    }

    public function testADiscriminatedMemberIsNeitherWrappedNorAliased(): void
    {
        $document = self::document(
            openApi: '3.1.0',
            property: ['oneOf' => [['$ref' => '#/components/schemas/Circle'], ['$ref' => '#/components/schemas/Square']], 'discriminator' => ['propertyName' => 'kind']],
            components: [
                'Circle' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'radius' => ['type' => 'number']], 'required' => ['kind']],
                'Square' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'side' => ['type' => 'number']], 'required' => ['kind']],
            ],
        );
        $member = [...self::propertyPath(), 'oneOf', 0];

        $this->assertSame(SpecRewriter::SKIP_DISCRIMINATED_MEMBER, SpecRewriter::applyAt(rewrite: SpecRewriter::WRAP_ALL_OF, document: $document, path: $member, choiceSeed: 1));
        $this->assertSame(SpecRewriter::SKIP_DISCRIMINATED_MEMBER, SpecRewriter::applyAt(rewrite: SpecRewriter::ALIAS_CHAIN, document: $document, path: $member, choiceSeed: 1));
        $this->assertSame(
            expected: SpecRewriter::SKIP_DISCRIMINATED_COMPONENT,
            actual: SpecRewriter::applyAt(rewrite: SpecRewriter::WRAP_ALL_OF, document: $document, path: ['components', 'schemas', 'Circle'], choiceSeed: 1),
        );

        $reordered = SpecRewriter::applyAt(rewrite: SpecRewriter::REORDER_MEMBERS, document: $document, path: self::propertyPath(), choiceSeed: 1);
        $this->assertInstanceOf(RewrittenSpec::class, $reordered);
        $this->assertSame(
            expected: [['$ref' => '#/components/schemas/Square'], ['$ref' => '#/components/schemas/Circle']],
            actual: $reordered->document['components']['schemas']['Probe']['properties']['color']['oneOf'],
        );
    }

    public function testAMovedComponentTakesWhatItReachesAndBundlesBack(): void
    {
        $document = self::document(
            openApi: '3.1.0',
            property: ['$ref' => '#/components/schemas/Widget'],
            components: [
                'Widget' => ['type' => 'object', 'properties' => ['gadget' => ['$ref' => '#/components/schemas/Gadget']]],
                'Gadget' => ['type' => 'string', 'maxLength' => 3],
            ],
        );

        $moved = SpecRewriter::applyAt(rewrite: SpecRewriter::MOVE_TO_OTHER_FILE, document: $document, path: ['components', 'schemas', 'Widget'], choiceSeed: 1);

        $this->assertInstanceOf(RewrittenSpec::class, $moved);
        $this->assertSame(['Probe'], array_keys($moved->document['components']['schemas']));
        $this->assertSame('other.json#/components/schemas/Widget', $moved->document['components']['schemas']['Probe']['properties']['color']['$ref']);
        $this->assertSame(['Widget', 'Gadget'], array_keys($moved->externals[SpecRewriter::OTHER_FILE]['components']['schemas']));
        $this->assertSame('#/components/schemas/Gadget', $moved->externals[SpecRewriter::OTHER_FILE]['components']['schemas']['Widget']['properties']['gadget']['$ref']);
        $this->assertEquals($document, $moved->bundled());
    }

    /**
     * Seed 41007, members of an inline `oneOf` of two objects swapped: an impossible calendar date in
     * the member that sorts first on one side is accepted there (the documented "impossible calendar
     * date" row) and refused on the other only because symfony builds the other member's class first
     * and misses its required key — which refuses a VALID date too. Right by accident, not a finding.
     */
    public function testARefusalRightOnlyByTheClassTriedFirstExcusesADocumentedWrongAccept(): void
    {
        $payload = [
            'property' => 'gibbon',
            'kind' => 'impossibleDate:date',
            'json' => '{"gibbon":{"ibis":"x","jackal":"2026-02-30"}}',
            'expected' => PayloadVerdict::Refused,
            'tags' => ['in:union', 'unionObjectPair', 'in:prop', 'site:nullable-temporal'],
        ];
        $misses = PayloadOutcome::refused(stage: 'deserialize', detail: 'Failed to create object because the class misses the "stoat" property.');

        $this->assertNotNull(MetamorphicRunner::excusedByASpellingDependentRefusal(
            mode: GenerationMode::Symfony,
            category: 'wrong-accept',
            payload: $payload,
            right: $misses,
            rightTags: [...$payload['tags'], 'objectOfPair'],
        ));

        $runner = new MetamorphicRunner(outputDirectory: $this->outputDirectory, modes: [GenerationMode::Symfony], propertiesPerSpec: self::LONG_RUN_PROPERTIES);
        $runner->runSpec(seed: 41007);
        $reordered = array_filter($runner->findings(), static fn(array $finding): bool => $finding['rewrite'] === SpecRewriter::REORDER_MEMBERS);
        $this->assertSame([], array_column($reordered, 'signature'));
    }

    /**
     * The excuse stays narrow: a wrong answer no row explains, a right refusal from the validator
     * rather than the serializer's first class, or a value the first class does not miss, is still
     * reported.
     */
    public function testAGenuineDivergenceBesideARightRefusalIsStillReported(): void
    {
        $payload = [
            'property' => 'gibbon',
            'kind' => 'impossibleDate:date',
            'json' => '{"gibbon":{"ibis":"x","jackal":"2026-02-30"}}',
            'expected' => PayloadVerdict::Refused,
            'tags' => ['in:union', 'unionObjectPair', 'in:prop', 'site:nullable-temporal'],
        ];
        $misses = PayloadOutcome::refused(stage: 'deserialize', detail: 'Failed to create object because the class misses the "stoat" property.');
        $pairTags = [...$payload['tags'], 'objectOfPair'];

        // No row for the wrong side: the date outside every temporal site.
        $undocumented = [...$payload, 'tags' => ['in:union', 'unionObjectPair', 'in:prop']];
        $this->assertNull(MetamorphicRunner::excusedByASpellingDependentRefusal(
            mode: GenerationMode::Symfony,
            category: 'wrong-accept',
            payload: $undocumented,
            right: $misses,
            rightTags: [...$undocumented['tags'], 'objectOfPair'],
        ));
        // The right side refused in the validator: it met the date, not the class tried first.
        $this->assertNull(MetamorphicRunner::excusedByASpellingDependentRefusal(
            mode: GenerationMode::Symfony,
            category: 'wrong-accept',
            payload: $payload,
            right: PayloadOutcome::refused(stage: 'validate', detail: 'This value is not a valid date.'),
            rightTags: $pairTags,
        ));
        // The value is an instance of the member the right side tries first.
        $this->assertNull(MetamorphicRunner::excusedByASpellingDependentRefusal(
            mode: GenerationMode::Symfony,
            category: 'wrong-accept',
            payload: $payload,
            right: $misses,
            rightTags: $payload['tags'],
        ));
        // A right ACCEPT is never an accident of the class tried first.
        $this->assertNull(MetamorphicRunner::excusedByASpellingDependentRefusal(
            mode: GenerationMode::Symfony,
            category: 'wrong-refuse',
            payload: [...$payload, 'kind' => 'valid', 'expected' => PayloadVerdict::Accepted],
            right: PayloadOutcome::accepted(),
            rightTags: $pairTags,
        ));
    }

    /**
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array<int, string> $wrongBuilt
     * @param array<int, string> $rightBuilt
     */
    #[DataProvider('serializerBuiltPositions')]
    public function testASerializerBuiltPositionOfTheOriginalSpellingExcusesItsDocumentedWrongAccept(array $payload, array $wrongBuilt, array $rightBuilt): void
    {
        $this->assertNotNull(MetamorphicRunner::excusedBySerializerBuiltPosition(
            mode: GenerationMode::Symfony,
            category: 'wrong-accept',
            originalAnsweredWrong: true,
            payload: $payload,
            right: PayloadOutcome::refused(stage: 'validate', detail: 'must match'),
            wrongBuilt: $wrongBuilt,
            rightBuilt: $rightBuilt,
        ));
    }

    /**
     * The symfony rows the serializer answers before a constraint runs, met by ONE spelling only: the
     * original types the payload's position as a serializer-built `DateTimeImmutable` or DTO and accepts
     * (a documented row), the rewritten spelling holds the value raw and its callback refuses. Each
     * pattern as the fuzzer met it, with the classes each side builds at the property.
     *
     * @return iterable<string, array{0: array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function serializerBuiltPositions(): iterable
    {
        $generated = 'Fixture\Jade';

        yield 'a one-digit date ITEM of a list (split format)' => [
            self::refusedPayload(kind: 'parseableNonDate:date', json: '{"ocelot":["2020-1-2","2013-10-07"]}', tags: ['in:items', 'itemsOf:nullable-temporal', 'site:nullable-temporal', 'dateOneDigit']),
            [DateTimeImmutable::class],
            [],
        ];
        yield 'an impossible date-time ITEM of a list' => [
            self::refusedPayload(kind: 'impossibleDate:date-time', json: '{"ocelot":["2026-02-30T10:00:00Z"]}', tags: ['in:items', 'itemsOf:temporal', 'site:temporal']),
            [DateTimeImmutable::class],
            [],
        ];
        yield 'an impossible date-time in a DTO member of an inline union (extracted component)' => [
            self::refusedPayload(kind: 'impossibleDate:date-time', json: '{"ocelot":{"toucan":"2026-02-30T10:00:00Z"}}', tags: ['in:union', 'unionObjectPair', 'in:prop', 'site:temporal']),
            [$generated],
            [],
        ];
        yield 'an additionalProperties value of a list item class' => [
            self::refusedPayload(kind: 'wrongType:number', json: '{"ocelot":[{"hyena":"cadafbe","stray":2.5}]}', tags: ['in:items', 'itemsOf:nullable-dto', 'in:additional', 'site:nullable-boolean']),
            [$generated],
            [],
        ];
        yield 'a JSON array for a list item class' => [
            self::refusedPayload(kind: 'wrongType:array', json: '{"ocelot":[[1,2],{"wombat":"2012-12-09"}]}', tags: ['in:items', 'itemsOf:nullable-dto', 'site:nullable-dto']),
            [$generated],
            [],
        ];
        yield 'an undeclared key on a closed list item class' => [
            self::refusedPayload(kind: 'extraKeyClosed', json: '{"ocelot":[{"wombat":"2012-12-09","stray":1}]}', tags: ['in:items', 'itemsOf:nullable-dto', 'site:nullable-dto']),
            [$generated],
            [],
        ];
    }

    /**
     * The excuse stays narrow: every condition is load-bearing, and a wrong accept no serializer row
     * explains is still a finding.
     */
    public function testASerializerBuiltPositionExcusesNothingElse(): void
    {
        $date = self::refusedPayload(kind: 'parseableNonDate:date', json: '{"ocelot":["2020-1-2"]}', tags: ['in:items', 'itemsOf:nullable-temporal', 'site:nullable-temporal', 'dateOneDigit']);
        $validate = PayloadOutcome::refused(stage: 'validate', detail: 'must match');
        $excused = static fn(array $overrides): ?string => MetamorphicRunner::excusedBySerializerBuiltPosition(...[
            'mode' => GenerationMode::Symfony,
            'category' => 'wrong-accept',
            'originalAnsweredWrong' => true,
            'payload' => $date,
            'right' => $validate,
            'wrongBuilt' => [DateTimeImmutable::class],
            'rightBuilt' => [],
            ...$overrides,
        ]);

        $this->assertNotNull($excused([]));
        // Another mode: yii3 holds the value as sent in both spellings, so a divergence there is a defect.
        $this->assertNull($excused(['mode' => GenerationMode::Yii3]));
        // The rewritten spelling answered wrong: the tags describe the original, not its position.
        $this->assertNull($excused(['originalAnsweredWrong' => false]));
        // A wrong refusal, not an accept the serializer gave.
        $this->assertNull($excused(['category' => 'wrong-refuse']));
        // The right side builds a date there too: both spellings meet the serializer, so they must agree.
        $this->assertNull($excused(['rightBuilt' => [DateTimeImmutable::class]]));
        // The wrong side builds nothing at the property: its accept is no serializer row.
        $this->assertNull($excused(['wrongBuilt' => []]));
        // The right side refused in the serializer, not in the callback over the raw value.
        $this->assertNull($excused(['right' => PayloadOutcome::refused(stage: 'deserialize', detail: 'x')]));
        // A date a DTO-only row does not cover: an additionalProperties value beside a date the class builds.
        $additional = self::refusedPayload(kind: 'wrongType:number', json: '{"ocelot":[{"stray":2.5}]}', tags: ['in:items', 'itemsOf:nullable-dto', 'in:additional', 'site:nullable-boolean']);
        $this->assertNull($excused(['payload' => $additional, 'wrongBuilt' => [DateTimeImmutable::class]]));
        // A refusal no serializer row explains (`minLength` on a list item): a real defect, reported.
        $this->assertNull($excused(['payload' => self::refusedPayload(kind: 'tooShort', json: '{"ocelot":["a"]}', tags: ['in:items', 'itemsOf:string', 'site:string'])]));
        // The scalar-for-a-class row depends on the serializer version, not on the spelling.
        $this->assertNull($excused(['payload' => self::refusedPayload(kind: 'wrongType:integer', json: '{"ocelot":1}', tags: ['site:dto']), 'wrongBuilt' => ['Fixture\Jade']]));
    }

    /**
     * The fuzzer's own cases, end to end (symfony): seed 43067 splits a `format: date` list item into
     * `allOf: [{type: string}, {format: date}]`, seed 43064 wraps a list item class in `allOf: [$ref]`.
     */
    public function testTheSerializerBuiltSeedsComeOutClean(): void
    {
        $runner = new MetamorphicRunner(outputDirectory: $this->outputDirectory, modes: [GenerationMode::Symfony], propertiesPerSpec: self::LONG_RUN_PROPERTIES);
        $runner->runSpec(seed: 43067);
        $runner->runSpec(seed: 43064);

        $this->assertSame([], array_column($runner->findings(), 'signature'));
    }

    public function testEveryModeAnswersTheSameForEveryRewriteOnTheCiBudget(): void
    {
        $seed = self::env('METAMORPHIC_SEED');
        $iterations = self::env('METAMORPHIC_ITERATIONS');
        $longRun = $seed !== null || $iterations !== null;
        $properties = (int)(self::env('METAMORPHIC_PROPERTIES') ?? self::LONG_RUN_PROPERTIES);
        $budget = self::CI_SEEDS;
        if ($longRun) {
            $budget = [];
            foreach (range((int)($seed ?? 1), (int)($seed ?? 1) + max(1, (int)($iterations ?? 4)) - 1) as $current) {
                $budget[$current] = $properties;
            }
        }
        $rewrites = self::env('METAMORPHIC_REWRITES');
        $runner = new MetamorphicRunner(
            outputDirectory: $this->outputDirectory,
            modes: self::modes(),
            propertiesPerSpec: $properties,
            rewrites: $rewrites === null ? SpecRewriter::REWRITES : array_map('trim', explode(',', $rewrites)),
            verbose: self::env('METAMORPHIC_VERBOSE') !== null,
        );
        $started = microtime(true);
        $report = self::env('METAMORPHIC_REPORT');
        $seeds = array_keys($budget);
        foreach ($seeds as $index => $current) {
            if (self::env('METAMORPHIC_VERBOSE') !== null) {
                fwrite(STDERR, sprintf("[%d/%d] seed %d, %.0fs\n", $index + 1, count($seeds), $current, microtime(true) - $started));
            }
            $runner->runSpec(seed: $current, propertyCount: $budget[$current]);
            if ($report !== null) {
                self::writeReport(path: $report, seeds: [$seeds[0], $current], seconds: microtime(true) - $started, runner: $runner);
            }
        }

        $this->assertGreaterThan(0, $runner->stats()['comparisons'], 'the run must have compared something');
        $unsound = array_filter($runner->findings(), static fn(array $finding): bool => $finding['category'] === 'rewrite-unsound');
        $this->assertSame([], array_column($unsound, 'signature'), 'a rewrite the oracle does not hold equivalent is a harness bug');
        if ($longRun) {
            return;
        }
        $this->assertSame([], array_column($runner->findings(), 'signature'));
    }

    /**
     * @param array{0: int, 1: int} $seeds
     */
    private static function writeReport(string $path, array $seeds, float $seconds, MetamorphicRunner $runner): void
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
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param array<string, mixed> $property
     * @param array<string, mixed> $components
     * @return array<string, mixed>
     */
    private static function document(string $openApi, array $property, array $components = []): array
    {
        return [
            'openapi' => $openApi,
            'info' => ['title' => 'Metamorphic', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['color' => $property]], ...$components]],
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}
     */
    private static function refusedPayload(string $kind, string $json, array $tags): array
    {
        return ['property' => 'ocelot', 'kind' => $kind, 'json' => $json, 'expected' => PayloadVerdict::Refused, 'tags' => $tags];
    }

    /**
     * @return array<int, string>
     */
    private static function propertyPath(): array
    {
        return ['components', 'schemas', 'Probe', 'properties', 'color'];
    }

    /**
     * @return array<int, GenerationMode>
     */
    private static function modes(): array
    {
        $selected = self::env('METAMORPHIC_MODES');
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
