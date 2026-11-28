<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use DateTimeInterface;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadOutcome;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use OpenapiPhpDtoGenerator\Tests\Parity\RunsPayloadsThroughModes;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The metamorphic layer over the fuzz harness: an EQUIVALENT spelling of a spec must get the same
 * verdict, in every mode, as the original in that mode — whatever the reference oracle expects.
 *
 * Per seed: the {@see FuzzSpec} document and its payloads, as {@see FuzzRunner} draws them; then per
 * {@see SpecRewriter} rewrite one rewritten document, generated and fed the same payloads. A finding is
 * a payload whose verdict differs between the two documents in one mode, or a generation stage
 * (generate / load / missing type) reached on one and not the other. Before any mode runs, the
 * reference oracle must agree that the rewrite is equivalent on every payload it can judge; where it
 * does not, the rewrite is wrong (`rewrite-unsound`), not the generator, and no mode is asked.
 *
 * A finding is replayed on the document restricted to the payload's property (`standalone`) when the
 * divergence holds there too. No further shrinking.
 */
final class MetamorphicRunner
{
    use RunsPayloadsThroughModes;

    /** Where the stream that chooses sites starts, relative to the seed. */
    private const int REWRITE_STREAM_OFFSET = 4_211_000;

    /** @var array<string, array<string, mixed>> keyed by signature */
    private array $findings = [];

    /** @var array<string, array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}> */
    private array $generated = [];

    private ?string $bootedLaravelData = null;

    private readonly LoadChecker $loadChecker;

    /** @var array<string, mixed> */
    private array $stats = ['specs' => 0, 'payloads' => 0, 'comparisons' => 0, 'divergences' => 0, 'documented' => 0];

    /** @var array<string, array<string, mixed>> per rewrite */
    private array $perRewrite = [];

    /** @var array<string, float> */
    private array $timing = ['generate' => 0.0, 'payloads' => 0.0];

    /**
     * @param array<int, GenerationMode> $modes
     * @param array<int, string> $rewrites
     */
    public function __construct(
        string $outputDirectory,
        private readonly array $modes,
        private readonly int $propertiesPerSpec,
        private readonly array $rewrites = SpecRewriter::REWRITES,
        private readonly bool $verbose = false,
    ) {
        $this->outputDirectory = $outputDirectory;
        $this->loadChecker = new LoadChecker();
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0o755, true);
        }
        foreach ($rewrites as $rewrite) {
            $this->perRewrite[$rewrite] = ['applied' => 0, 'notApplicable' => 0, 'unsound' => 0, 'skips' => [], 'findingsByMode' => []];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findings(): array
    {
        return array_values($this->findings);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [...$this->stats, 'seconds' => array_map(static fn(float $seconds): float => round($seconds, 1), $this->timing), 'rewrites' => $this->perRewrite];
    }

    /**
     * The payloads of a spec, as {@see FuzzRunner} draws them: the empty body, then per property the
     * valid value and every mutation.
     *
     * @return array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}>
     */
    public static function payloadsOf(FuzzSpec $spec): array
    {
        $payloads = [['property' => null, 'kind' => 'empty', 'json' => '{}', 'expected' => PayloadVerdict::Accepted, 'tags' => []]];
        foreach ($spec->cases as $name => $case) {
            $payloads[] = [
                'property' => $name,
                'kind' => 'valid',
                'json' => FuzzSpec::payload($name, $case['valid']['value']),
                'expected' => PayloadVerdict::Accepted,
                'tags' => $case['valid']['tags'],
            ];
            foreach ($case['mutations'] as $mutation) {
                $payloads[] = [
                    'property' => $name,
                    'kind' => $mutation['kind'],
                    'json' => FuzzSpec::payload($name, $mutation['value']),
                    'expected' => PayloadVerdict::Refused,
                    'tags' => $mutation['tags'],
                ];
            }
        }

        return $payloads;
    }

    /**
     * The payloads the reference oracle judges differently on the two documents (both answers known).
     * Empty for an equivalent rewrite.
     *
     * @param array<string, mixed> $original
     * @param array<int, string> $jsons
     * @return array<int, string>
     */
    public static function oracleDisagreements(array $original, RewrittenSpec $rewritten, array $jsons): array
    {
        $before = new ReferenceOracle($original);
        $after = new ReferenceOracle($rewritten->bundled());
        $disagreements = [];
        foreach ($jsons as $json) {
            $left = $before->accepts($json);
            $right = $after->accepts($json);
            if ($left !== null && $right !== null && $left !== $right) {
                $disagreements[] = $json;
            }
        }

        return $disagreements;
    }

    public function runSpec(int $seed, ?int $propertyCount = null): void
    {
        $spec = FuzzSpec::generate(seed: $seed, propertyCount: $propertyCount ?? $this->propertiesPerSpec);
        $document = $spec->document();
        $payloads = self::payloadsOf($spec);
        $this->stats['specs']++;

        /** @var array<string, array<int, PayloadOutcome>> $originalOutcomes */
        $originalOutcomes = [];
        foreach ($this->modes as $mode) {
            $generation = $this->generateChecked(document: $document, externals: [], asFile: false, mode: $mode);
            if ($generation['fqcn'] !== null) {
                $originalOutcomes[$mode->value] = $this->runAll(mode: $mode, fqcn: $generation['fqcn'], payloads: $payloads);
            }
        }

        $random = new FuzzRandom($seed + self::REWRITE_STREAM_OFFSET);
        $properties = array_map('strval', array_keys($spec->cases));
        foreach ($this->rewrites as $rewrite) {
            $rewritten = null;
            $siteProperty = null;
            foreach ($random->shuffle($properties) as $property) {
                $attempt = SpecRewriter::apply(rewrite: $rewrite, document: $document, property: $property, random: $random);
                foreach ($attempt['skips'] as $reason) {
                    $this->perRewrite[$rewrite]['skips'][$reason] = ($this->perRewrite[$rewrite]['skips'][$reason] ?? 0) + 1;
                }
                if ($attempt['rewritten'] !== null) {
                    $rewritten = $attempt['rewritten'];
                    $siteProperty = $property;

                    break;
                }
            }
            if ($rewritten === null || $siteProperty === null) {
                $this->perRewrite[$rewrite]['notApplicable']++;

                continue;
            }
            $this->perRewrite[$rewrite]['applied']++;
            if ($this->verbose) {
                fwrite(STDERR, sprintf("  %s at %s (%s)\n", $rewrite, $rewritten->site(), $rewritten->note));
            }

            $unsound = self::oracleDisagreements(original: $document, rewritten: $rewritten, jsons: array_column($payloads, 'json'));
            if ($unsound !== []) {
                $this->perRewrite[$rewrite]['unsound']++;
                $this->addFinding(signature: 'oracle|' . $rewrite . '|' . $seed . '|' . $rewritten->site(), finding: [
                    'mode' => 'oracle',
                    'rewrite' => $rewrite,
                    'category' => 'rewrite-unsound',
                    'seed' => $seed,
                    'site' => $rewritten->site(),
                    'note' => $rewritten->note,
                    'payload' => $unsound[0],
                    'documentOriginal' => $document,
                    'documentRewritten' => $rewritten->document,
                    'externalsRewritten' => $rewritten->externals,
                ]);

                continue;
            }

            foreach ($this->modes as $mode) {
                $this->compare(
                    spec: $spec,
                    mode: $mode,
                    rewritten: $rewritten,
                    siteProperty: $siteProperty,
                    payloads: $payloads,
                    originalOutcomes: $originalOutcomes[$mode->value] ?? null,
                );
            }
        }

        $this->cleanOutput();
    }

    /**
     * @param array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}> $payloads
     * @param array<int, PayloadOutcome>|null $originalOutcomes
     */
    private function compare(FuzzSpec $spec, GenerationMode $mode, RewrittenSpec $rewritten, string $siteProperty, array $payloads, ?array $originalOutcomes): void
    {
        $document = $spec->document();
        $before = $this->generateChecked(document: $document, externals: [], asFile: false, mode: $mode);
        $after = $this->generateChecked(document: $rewritten->document, externals: $rewritten->externals, asFile: $rewritten->asFile, mode: $mode);
        if ($before['stage'] !== $after['stage']) {
            $this->recordGenerationDivergence(spec: $spec, mode: $mode, rewritten: $rewritten, siteProperty: $siteProperty, before: $before, after: $after);
        }
        if ($originalOutcomes === null || $after['fqcn'] === null) {
            return;
        }
        $outcomes = $this->runAll(mode: $mode, fqcn: $after['fqcn'], payloads: $payloads);
        foreach ($payloads as $index => $payload) {
            $original = $originalOutcomes[$index];
            $outcome = $outcomes[$index];
            $this->stats['comparisons']++;
            if ($original->verdict === $outcome->verdict) {
                continue;
            }
            $this->stats['divergences']++;
            if ($this->documentedSpellingDivergence(spec: $spec, mode: $mode, rewritten: $rewritten, payload: $payload, original: $original, outcome: $outcome, before: $before, after: $after)) {
                $this->stats['documented']++;

                continue;
            }
            $this->recordVerdictDivergence(
                spec: $spec,
                mode: $mode,
                rewritten: $rewritten,
                siteProperty: $siteProperty,
                payload: $payload,
                original: $original,
                outcome: $outcome,
            );
        }
    }

    /**
     * The support-matrix rows that depend on how a schema is SPELLED, not on what it means: a union
     * COMPONENT of objects with no discriminator (laravel and laravel-data warn and do not hydrate it,
     * an inline one they do), a discriminated union inside the FIRST member of an inline union of
     * classes (laravel-data), and the symfony rows of an undiscriminated union of classes, whose member
     * the serializer tries first by generated class name ({@see KnownDivergences::explainSpellingDependent()};
     * extracting or reordering members changes those names). A divergence is
     * one of them when exactly one side answers what the oracle expects and the other side's wrong
     * answer is that row, judged on its own document and classes — or when the side that answers
     * right does so only through such a row, and the other side's answer is documented
     * ({@see excusedByASpellingDependentRefusal()}) — or when the original spelling's wrong accept is
     * a symfony row the serializer answers by BUILDING the position the rewritten spelling holds raw
     * ({@see excusedBySerializerBuiltPosition()}). Every other row describes a shape, which both
     * spellings share: there a divergence is a finding.
     *
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string} $before
     * @param array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string} $after
     */
    private function documentedSpellingDivergence(
        FuzzSpec $spec,
        GenerationMode $mode,
        RewrittenSpec $rewritten,
        array $payload,
        PayloadOutcome $original,
        PayloadOutcome $outcome,
        array $before,
        array $after,
    ): bool {
        $property = $payload['property'];
        if ($property === null) {
            return false;
        }
        $originalAnsweredWrong = $outcome->verdict === $payload['expected'] && $original->verdict !== $payload['expected'];
        if ($original->verdict === $payload['expected']) {
            $document = $rewritten->bundled();
            $wrong = $outcome;
            $wrongSide = $after;
            $rightDocument = $spec->document();
            $right = $original;
            $rightSide = $before;
        } elseif ($outcome->verdict === $payload['expected']) {
            $document = $spec->document();
            $wrong = $original;
            $wrongSide = $before;
            $rightDocument = $rewritten->bundled();
            $right = $outcome;
            $rightSide = $after;
        } else {
            return false;
        }
        $category = match (true) {
            $wrong->verdict === PayloadVerdict::Crashed => 'crash',
            $payload['expected'] === PayloadVerdict::Accepted => 'wrong-refuse',
            default => 'wrong-accept',
        };
        $schemas = (array)$document['components']['schemas'];
        $row = KnownDivergences::explainUnhydratableUnion(
            mode: $mode,
            category: $category,
            // The row is the generator's own warning about a component this property reaches; the
            // tag the original spelling earned does not carry over to a spelling that made it one.
            tags: [...$payload['tags'], 'undiscriminatedComponent'],
            warnings: $wrongSide['warnings'],
            reachable: FuzzSpec::reachableComponents(start: [$schemas['Probe']['properties'][$property] ?? []], components: $schemas),
        );
        $row ??= KnownDivergences::explainFirstMemberDiscriminatedGuard(
            mode: $mode,
            category: $category,
            document: $document,
            property: $property,
            value: get_object_vars((object)json_decode($payload['json']))[$property] ?? null,
        );
        if ($row === null && $mode === GenerationMode::Symfony && $wrongSide['fqcn'] !== null) {
            // `objectOfPair` is read off the classes of the side that answered wrong.
            $tagged = FuzzRunner::tagObjectsOfAPair(payloads: [$payload], document: $document, fqcn: $wrongSide['fqcn'])[0]['tags'];
            $row = KnownDivergences::explainSpellingDependent(mode: $mode, category: $category, kind: $payload['kind'], tags: $tagged);
        }
        if ($row === null && $mode === GenerationMode::Symfony && $rightSide['fqcn'] !== null) {
            // The side that answered right may be right only by accident: tagged as the valid value of
            // its property would be, read off ITS classes.
            $rightTags = FuzzRunner::tagObjectsOfAPair(payloads: [[...$payload, 'kind' => 'valid']], document: $rightDocument, fqcn: $rightSide['fqcn'])[0]['tags'];
            $row = self::excusedByASpellingDependentRefusal(mode: $mode, category: $category, payload: $payload, right: $right, rightTags: $rightTags);
        }
        if ($row === null && $mode === GenerationMode::Symfony && $wrongSide['fqcn'] !== null && $rightSide['fqcn'] !== null) {
            $row = self::excusedBySerializerBuiltPosition(
                mode: $mode,
                category: $category,
                originalAnsweredWrong: $originalAnsweredWrong,
                payload: $payload,
                right: $right,
                wrongBuilt: FuzzRunner::builtClassesOf(class: $wrongSide['fqcn'], wireName: $property),
                rightBuilt: FuzzRunner::builtClassesOf(class: $rightSide['fqcn'], wireName: $property),
            );
        }

        return $row !== null;
    }

    /**
     * A symfony wrong accept of the ORIGINAL spelling that a documented row explains only because the
     * serializer builds the payload's position before any constraint runs — a `DateTimeImmutable`
     * parsed from an impossible or one-digit date (`temporal`), or a DTO that drops an
     * `additionalProperties` value, takes a JSON array for an object or ignores a key a closed object
     * forbids (`dto`) — while the rewritten spelling holds the property raw (`allOf: [{type: string},
     * {format: date}]`, `allOf: [$ref ListComponent]`, an extracted union) and its generated callback
     * refuses the value. Keyed on the mode, the direction (the payload's tags describe the original),
     * the row, and the classes each side builds at the property: the wrong side builds a date or a DTO
     * there (a date also through the DTO that holds it), the right side builds nothing and refused in
     * the validator. Any other divergence is a finding.
     *
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array<int, string> $wrongBuilt the classes the original spelling builds at the property ({@see FuzzRunner::builtClassesOf()})
     * @param array<int, string> $rightBuilt the classes the rewritten spelling builds there
     */
    public static function excusedBySerializerBuiltPosition(
        GenerationMode $mode,
        string $category,
        bool $originalAnsweredWrong,
        array $payload,
        PayloadOutcome $right,
        array $wrongBuilt,
        array $rightBuilt,
    ): ?string {
        if ($mode !== GenerationMode::Symfony || $category !== 'wrong-accept' || !$originalAnsweredWrong) {
            return null;
        }
        if ($right->verdict !== PayloadVerdict::Refused || $right->stage !== 'validate' || $rightBuilt !== []) {
            return null;
        }
        $match = KnownDivergences::explainSerializerBuilt(mode: $mode, category: $category, kind: $payload['kind'], tags: $payload['tags']);
        if ($match === null) {
            return null;
        }
        $dtos = array_filter($wrongBuilt, static fn(string $class): bool => !is_a($class, DateTimeInterface::class, true));
        $meetsTheSerializer = $match['built'] === 'temporal' ? $wrongBuilt !== [] : $dtos !== [];

        return $meetsTheSerializer ? $match['row'] : null;
    }

    /**
     * A divergence where the side that answers what the oracle expects is right only by accident: it
     * refused in the serializer because the value is no instance of the union member whose class it
     * tries first (a valid value would be refused there too — the spelling-dependent symfony row), and
     * the other side's wrong answer is a documented row of its own (any, spelling-dependent or not:
     * an impossible calendar date the serializer parses). Neither side then meets a defect the other
     * does not; a wrong answer no row explains, or a right refusal that came from elsewhere than the
     * serializer building the first class, is still a finding.
     *
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array<int, string> $rightTags the payload's tags as the right side's classes read the value, as a valid one
     */
    public static function excusedByASpellingDependentRefusal(GenerationMode $mode, string $category, array $payload, PayloadOutcome $right, array $rightTags): ?string
    {
        if ($category === 'crash' || $payload['expected'] !== PayloadVerdict::Refused || $right->verdict !== PayloadVerdict::Refused || $right->stage !== 'deserialize') {
            return null;
        }
        if (KnownDivergences::explainSpellingDependent(mode: $mode, category: 'wrong-refuse', kind: 'valid', tags: $rightTags) === null) {
            return null;
        }

        return KnownDivergences::explain(mode: $mode, category: $category, kind: $payload['kind'], tags: $payload['tags']);
    }

    /**
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     */
    private function recordVerdictDivergence(FuzzSpec $spec, GenerationMode $mode, RewrittenSpec $rewritten, string $siteProperty, array $payload, PayloadOutcome $original, PayloadOutcome $outcome): void
    {
        $property = $payload['property'];
        $descriptor = $property === null ? 'probe' : InstanceGenerator::descriptor($spec->cases[$property]['node']);
        $rewrite = $rewritten->rewrite;
        $crashed = $outcome->verdict === PayloadVerdict::Crashed ? $outcome : ($original->verdict === PayloadVerdict::Crashed ? $original : null);
        // One defect, met by every payload of a property: deduplicated by the verdict pair, the shape
        // of the rewrite, and the shape of the property — for a crash, the error with names blurred
        // instead, since a class that cannot load crashes every payload of every property alike.
        $signature = implode('|', [
            $mode->value,
            $rewrite,
            $original->verdict->value . '>' . $outcome->verdict->value,
            $crashed === null ? $descriptor : '',
            (string)preg_replace('/\b[A-Z]\w*/', 'N', $rewritten->note),
            $crashed === null ? '' : self::errorShape($crashed->detail),
        ]);
        if (array_key_exists($signature, $this->findings)) {
            $this->bump(signature: $signature, seed: $spec->seed);

            return;
        }

        // Replayed on the document restricted to the payload's property (the site's, for the empty
        // body), when the site is in it.
        $standalone = false;
        $documentOriginal = $spec->document();
        $narrowed = $rewritten;
        $restricted = $spec->document([$property ?? $siteProperty]);
        $replayed = SpecRewriter::applyAt(rewrite: $rewritten->rewrite, document: $restricted, path: $rewritten->path, choiceSeed: $rewritten->choiceSeed);
        if ($replayed instanceof RewrittenSpec) {
            $left = $this->outcomeOn(document: $restricted, externals: [], asFile: false, mode: $mode, json: $payload['json']);
            $right = $this->outcomeOn(document: $replayed->document, externals: $replayed->externals, asFile: $replayed->asFile, mode: $mode, json: $payload['json']);
            if ($left?->verdict === $original->verdict && $right?->verdict === $outcome->verdict) {
                $standalone = true;
                $documentOriginal = $restricted;
                $narrowed = $replayed;
            }
        }

        $this->addFinding(signature: $signature, finding: [
            'mode' => $mode->value,
            'rewrite' => $rewrite,
            'category' => 'verdict',
            'seed' => $spec->seed,
            'property' => $property,
            'kind' => $payload['kind'],
            'tags' => $payload['tags'],
            'expected' => $payload['expected']->value,
            'verdictOriginal' => $original->verdict->value,
            'verdictRewritten' => $outcome->verdict->value,
            'original' => ['stage' => $original->stage, 'detail' => mb_substr($original->detail, 0, 600)],
            'rewritten' => ['stage' => $outcome->stage, 'detail' => mb_substr($outcome->detail, 0, 600)],
            'payload' => $payload['json'],
            'site' => $narrowed->site(),
            'note' => $narrowed->note,
            'standalone' => $standalone,
            'documentOriginal' => $documentOriginal,
            'documentRewritten' => $narrowed->document,
            'externalsRewritten' => $narrowed->externals,
        ]);
    }

    /**
     * @param array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string} $before
     * @param array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string} $after
     */
    private function recordGenerationDivergence(FuzzSpec $spec, GenerationMode $mode, RewrittenSpec $rewritten, string $siteProperty, array $before, array $after): void
    {
        $signature = implode('|', [
            $mode->value,
            $rewritten->rewrite,
            'generation',
            $before['stage'],
            $after['stage'],
            self::errorShape((string)($after['error'] ?? $before['error'])),
        ]);
        if (array_key_exists($signature, $this->findings)) {
            $this->bump(signature: $signature, seed: $spec->seed);

            return;
        }
        $standalone = false;
        $documentOriginal = $spec->document();
        $narrowed = $rewritten;
        $restricted = $spec->document([$siteProperty]);
        $replayed = SpecRewriter::applyAt(rewrite: $rewritten->rewrite, document: $restricted, path: $rewritten->path, choiceSeed: $rewritten->choiceSeed);
        if ($replayed instanceof RewrittenSpec) {
            $left = $this->generateChecked(document: $restricted, externals: [], asFile: false, mode: $mode);
            $right = $this->generateChecked(document: $replayed->document, externals: $replayed->externals, asFile: $replayed->asFile, mode: $mode);
            if ($left['stage'] === $before['stage'] && $right['stage'] === $after['stage']) {
                $standalone = true;
                $documentOriginal = $restricted;
                $narrowed = $replayed;
                $before = $left;
                $after = $right;
            }
        }
        $this->addFinding(signature: $signature, finding: [
            'mode' => $mode->value,
            'rewrite' => $rewritten->rewrite,
            'category' => 'generation',
            'seed' => $spec->seed,
            'property' => $siteProperty,
            'original' => ['stage' => $before['stage'], 'detail' => self::shortError($before['error'])],
            'rewritten' => ['stage' => $after['stage'], 'detail' => self::shortError($after['error'])],
            'payload' => null,
            'site' => $narrowed->site(),
            'note' => $narrowed->note,
            'standalone' => $standalone,
            'documentOriginal' => $documentOriginal,
            'documentRewritten' => $narrowed->document,
            'externalsRewritten' => $narrowed->externals,
        ]);
    }

    /**
     * The first line of an error with names, strings and numbers blurred, so one defect met in two
     * specs dedupes.
     */
    private static function errorShape(string $error): string
    {
        $line = (string)preg_replace('/^PHP Fatal error:\s+/', '', trim($error));

        return md5((string)preg_replace(['/Mm\w+/', '/"[^"]*"/', '/\d+/', '/\$\w+/'], ['NS', 'S', 'N', 'V'], (string)strtok($line, "\n")));
    }

    private static function shortError(?string $error): ?string
    {
        return $error === null ? null : mb_substr($error, 0, 800);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $externals
     */
    private function outcomeOn(array $document, array $externals, bool $asFile, GenerationMode $mode, string $json): ?PayloadOutcome
    {
        $generation = $this->generateChecked(document: $document, externals: $externals, asFile: $asFile, mode: $mode);
        if ($generation['fqcn'] === null) {
            return null;
        }

        return $this->run(mode: $mode, fqcn: $generation['fqcn'], json: $json);
    }

    /**
     * @param class-string $fqcn
     * @param array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}> $payloads
     * @return array<int, PayloadOutcome>
     */
    private function runAll(GenerationMode $mode, string $fqcn, array $payloads): array
    {
        $started = microtime(true);
        $outcomes = [];
        foreach ($payloads as $index => $payload) {
            $outcomes[$index] = $this->run(mode: $mode, fqcn: $fqcn, json: $payload['json']);
            $this->stats['payloads']++;
        }
        $this->timing['payloads'] += microtime(true) - $started;

        return $outcomes;
    }

    /**
     * @param class-string $fqcn
     */
    private function run(GenerationMode $mode, string $fqcn, string $json): PayloadOutcome
    {
        if ($mode === GenerationMode::LaravelData && $this->bootedLaravelData !== $fqcn) {
            LaravelDataContainer::boot();
            $this->bootedLaravelData = $fqcn;
        }

        return $this->payloadOutcome(mode: $mode, fqcn: $fqcn, json: $json, symfonyContext: self::SYMFONY_REQUEST_PAYLOAD_CONTEXT);
    }

    /**
     * Generate once per documents and mode, check in a child process that every class links, then load.
     *
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $externals
     * @return array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}
     */
    private function generateChecked(array $document, array $externals, bool $asFile, GenerationMode $mode): array
    {
        $namespace = 'Mm' . $mode->tag() . substr(md5(json_encode([$document, $externals, $asFile], JSON_THROW_ON_ERROR) . $mode->value), 0, 12);
        if (array_key_exists($namespace, $this->generated)) {
            return $this->generated[$namespace];
        }
        $started = microtime(true);
        try {
            return $this->generated[$namespace] = $this->generateAndLoad(document: $document, externals: $externals, asFile: $asFile, mode: $mode, namespace: $namespace);
        } finally {
            $this->timing['generate'] += microtime(true) - $started;
        }
    }

    /**
     * From an array, or — for documents that refer to each other — from files, each external one
     * placed by `--ref` into `<target>/Other` and `<namespace>\Other`.
     *
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $externals
     * @return array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}
     */
    private function generateAndLoad(array $document, array $externals, bool $asFile, GenerationMode $mode, string $namespace): array
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        $command = new GenerateDtoCommand();
        try {
            if ($asFile) {
                $specs = $this->outputDirectory . '/' . $namespace . '-specs';
                if (!is_dir($specs)) {
                    mkdir($specs, 0o755, true);
                }
                $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
                file_put_contents($specs . '/main.json', json_encode($document, $flags));
                $pairs = [];
                foreach ($externals as $file => $external) {
                    file_put_contents($specs . '/' . $file, json_encode($external, $flags));
                    $pairs[] = $specs . '/' . $file . '=' . $target . '/Other=' . $namespace . '\Other';
                }
                $command->setExternalRefMappings(refDirectoryPairs: $pairs, refNamespacePairs: []);
                $command->generateFromFile($specs . '/main.json', $target, $namespace, $mode->value);
            } else {
                $command->generateFromArray($document, $target, $namespace, $mode->value);
            }
        } catch (Throwable $error) {
            return ['fqcn' => null, 'warnings' => $command->getGenerationWarnings(), 'error' => $error::class . ': ' . $error->getMessage(), 'stage' => 'generate'];
        }
        $warnings = $command->getGenerationWarnings();

        $check = $this->loadChecker->check(directory: $target, namespace: $namespace);
        if ($check['fatal'] !== null) {
            return ['fqcn' => null, 'warnings' => $warnings, 'error' => str_replace($target, '<out>', mb_substr($check['fatal'], 0, 800)), 'stage' => 'load'];
        }

        $this->registerGeneratedAutoloader(namespace: $namespace, target: $target);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';
        $missing = $check['problems'] === [] ? null : implode("\n", array_slice($check['problems'], 0, 6));

        return ['fqcn' => $fqcn, 'warnings' => $warnings, 'error' => $missing, 'stage' => $missing === null ? 'done' : 'missing-type'];
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function addFinding(string $signature, array $finding): void
    {
        $this->findings[$signature] = ['signature' => $signature, 'occurrences' => 1, 'seeds' => [$finding['seed']]] + $finding;
        $rewrite = (string)$finding['rewrite'];
        $mode = (string)$finding['mode'];
        $this->perRewrite[$rewrite]['findingsByMode'][$mode] = ($this->perRewrite[$rewrite]['findingsByMode'][$mode] ?? 0) + 1;
        if ($this->verbose) {
            fwrite(STDERR, sprintf("  FINDING %s %s %s %s\n", $mode, $rewrite, $finding['category'], (string)($finding['kind'] ?? '')));
        }
    }

    private function bump(string $signature, int $seed): void
    {
        $this->findings[$signature]['occurrences']++;
        if (!in_array($seed, $this->findings[$signature]['seeds'], true) && count($this->findings[$signature]['seeds']) < 10) {
            $this->findings[$signature]['seeds'][] = $seed;
        }
    }

    private function cleanOutput(): void
    {
        $entries = glob($this->outputDirectory . '/*');
        foreach ($entries === false ? [] : $entries as $entry) {
            FuzzRunner::deleteTree($entry);
        }
    }
}
