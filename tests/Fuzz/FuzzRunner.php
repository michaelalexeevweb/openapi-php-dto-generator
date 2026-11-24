<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadOutcome;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use OpenapiPhpDtoGenerator\Tests\Parity\RunsPayloadsThroughModes;
use stdClass;
use Throwable;

/**
 * Generates fuzzed specs, runs every payload through every mode, and keeps the minimal failing cases.
 *
 * Invariants, per mode: generation succeeds and every written class links (checked in a child process,
 * so a fatal error cannot end the run); the valid payload is accepted; every invalid payload is refused;
 * nothing crashes. A failure is minimised — the property alone, then keywords removed one by one, then
 * payload parts removed — while it persists. "Persists" is judged differentially: the failing mode
 * keeps its wrong answer AND a mode that answered right before still answers right, so a removal that
 * changes what the payload means is rejected rather than shrunk into a bogus case.
 */
final class FuzzRunner
{
    use RunsPayloadsThroughModes;

    private const int SHRINK_SCHEMA_TRIALS = 90;

    private const int SHRINK_PAYLOAD_TRIALS = 30;

    /**
     * Oracle order for shrinking: the modes that read the raw body and own their checks first, the ones
     * whose framework decides before generated code (and is documented to be lenient) last.
     */
    private const array ORACLE_TRUST = [
        GenerationMode::Runtime,
        GenerationMode::Laravel,
        GenerationMode::LaravelData,
        GenerationMode::Symfony,
        GenerationMode::Yii3,
    ];

    private const array COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    private const array LIST_KEYWORDS = ['enum', 'required', 'type', 'allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** @var array<string, array<string, mixed>> keyed by signature */
    private array $findings = [];

    /** @var array<string, string> preliminary signature => final signature */
    private array $preliminary = [];

    /** @var array<string, array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}> */
    private array $generated = [];

    private ?string $bootedLaravelData = null;

    /** How many generation warnings the case being shrunk had before any removal. */
    private int $originalWarnings = 0;

    /**
     * Whether the reference oracle agreed with the case's expectation on the unshrunk document. Only
     * then is it asked after each shrink step: where it misjudges the original it has nothing to say.
     */
    private bool $referenceAgreed = false;

    private readonly LoadChecker $loadChecker;

    /** @var array<string, int> */
    private array $stats = ['specs' => 0, 'payloads' => 0, 'failures' => 0, 'documented' => 0, 'shrinkRuns' => 0, 'collateral' => 0];

    /** @var array<string, int> */
    private array $documented = [];

    /** @var array<int, int> seeds with at least one undocumented failure */
    private array $failingSeeds = [];

    /** @var array<string, float> seconds spent per phase */
    private array $timing = ['generate' => 0.0, 'payloads' => 0.0, 'shrink' => 0.0];

    /**
     * @param array<int, GenerationMode> $modes
     */
    public function __construct(
        string $outputDirectory,
        private readonly array $modes,
        private readonly int $propertiesPerSpec,
        private readonly bool $verbose = false,
        private readonly bool $shrink = true,
    ) {
        $this->outputDirectory = $outputDirectory;
        $this->loadChecker = new LoadChecker();
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0o755, true);
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
        return [...$this->stats, 'seconds' => array_map(static fn(float $seconds): float => round($seconds, 1), $this->timing), 'documentedByRow' => $this->documented, 'failingSeeds' => $this->failingSeeds];
    }

    public function runSpec(int $seed, ?int $propertyCount = null): void
    {
        $spec = FuzzSpec::generate(seed: $seed, propertyCount: $propertyCount ?? $this->propertiesPerSpec);
        $document = $spec->document();
        $this->stats['specs']++;

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

        /** @var array<string, array<int, PayloadOutcome>> $outcomes */
        $outcomes = [];
        foreach ($this->modes as $mode) {
            $generation = $this->generateChecked(document: $document, mode: $mode);
            if ($generation['stage'] !== 'done') {
                $this->recordGenerationProblem(spec: $spec, mode: $mode, generation: $generation);
            }
            if ($generation['fqcn'] === null) {
                continue;
            }
            $started = microtime(true);
            foreach ($payloads as $index => $payload) {
                $outcomes[$mode->value][$index] = $this->run(mode: $mode, fqcn: $generation['fqcn'], json: $payload['json']);
                $this->stats['payloads']++;
            }
            $this->timing['payloads'] += microtime(true) - $started;
        }

        foreach ($payloads as $index => $payload) {
            foreach ($this->modes as $mode) {
                $outcome = $outcomes[$mode->value][$index] ?? null;
                if ($outcome === null) {
                    continue;
                }
                $category = self::category(expected: $payload['expected'], observed: $outcome->verdict);
                if ($category === null) {
                    continue;
                }
                $this->stats['failures']++;
                $row = $category === 'crash' ? null : KnownDivergences::explain(mode: $mode, category: $category, kind: $payload['kind'], tags: $payload['tags']);
                if ($row !== null) {
                    $this->stats['documented']++;
                    $this->documented[$row] = ($this->documented[$row] ?? 0) + 1;

                    continue;
                }
                if (!in_array($spec->seed, $this->failingSeeds, true)) {
                    $this->failingSeeds[] = $spec->seed;
                }
                $oracles = [];
                foreach (self::ORACLE_TRUST as $other) {
                    if ($other !== $mode && ($outcomes[$other->value][$index] ?? null)?->verdict === $payload['expected']) {
                        $oracles[] = $other;
                    }
                }
                $started = microtime(true);
                $this->recordFailure(
                    spec: $spec,
                    payload: $payload,
                    mode: $mode,
                    outcome: $outcome,
                    category: $category,
                    oracles: $oracles,
                    warnings: $this->generated[$this->namespaceOf($document, $mode)]['warnings'] ?? [],
                );
                $this->timing['shrink'] += microtime(true) - $started;
            }
        }

        $this->cleanOutput();
    }

    private static function category(PayloadVerdict $expected, PayloadVerdict $observed): ?string
    {
        return match (true) {
            $observed === PayloadVerdict::Crashed => 'crash',
            $observed === $expected => null,
            $expected === PayloadVerdict::Accepted => 'wrong-refuse',
            default => 'wrong-accept',
        };
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
     * @param array<string, mixed> $document
     */
    private function namespaceOf(array $document, GenerationMode $mode): string
    {
        return 'Fz' . $mode->tag() . substr(md5(json_encode($document, JSON_THROW_ON_ERROR) . $mode->value), 0, 12);
    }

    /**
     * Generate (once per document and mode), check in a child process that every class links and every
     * type it names exists, then load it here.
     *
     * @param array<string, mixed> $document
     * @return array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}
     */
    private function generateChecked(array $document, GenerationMode $mode): array
    {
        $namespace = $this->namespaceOf($document, $mode);
        if (array_key_exists($namespace, $this->generated)) {
            return $this->generated[$namespace];
        }

        $started = microtime(true);
        try {
            return $this->generated[$namespace] = $this->generateAndLoad(document: $document, mode: $mode, namespace: $namespace);
        } finally {
            $this->timing['generate'] += microtime(true) - $started;
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string}
     */
    private function generateAndLoad(array $document, GenerationMode $mode, string $namespace): array
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        $command = new GenerateDtoCommand();
        try {
            $command->generateFromArray($document, $target, $namespace, $mode->value);
        } catch (Throwable $error) {
            return [
                'fqcn' => null,
                'warnings' => $command->getGenerationWarnings(),
                'error' => $error::class . ': ' . $error->getMessage(),
                'stage' => 'generate',
            ];
        }
        $warnings = $command->getGenerationWarnings();

        $check = $this->loadChecker->check(directory: $target, namespace: $namespace);
        if ($check['fatal'] !== null) {
            // A class that cannot load at all: nothing can run here.
            return ['fqcn' => null, 'warnings' => $warnings, 'error' => str_replace($target, '<out>', mb_substr($check['fatal'], 0, 800)), 'stage' => 'load'];
        }

        $this->registerGeneratedAutoloader(namespace: $namespace, target: $target);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        // A type some class names was never written. The classes load; a payload that reaches the
        // missing type fails on its own, so the payloads still run.
        $missing = $check['problems'] === [] ? null : implode("\n", array_slice($check['problems'], 0, 6));

        return ['fqcn' => $fqcn, 'warnings' => $warnings, 'error' => $missing, 'stage' => $missing === null ? 'done' : 'missing-type'];
    }

    /**
     * @param array{fqcn: class-string|null, warnings: array<int, string>, error: string|null, stage: string} $generation
     */
    private function recordGenerationProblem(FuzzSpec $spec, GenerationMode $mode, array $generation): void
    {
        if (!in_array($spec->seed, $this->failingSeeds, true)) {
            $this->failingSeeds[] = $spec->seed;
        }
        $category = match ($generation['stage']) {
            'generate' => 'generation-error',
            'missing-type' => 'missing-type',
            default => 'load-error',
        };
        $culprits = [];
        foreach (array_keys($spec->cases) as $name) {
            $alone = $this->generateChecked(document: $spec->document([$name]), mode: $mode);
            if ($alone['stage'] === $generation['stage']) {
                $culprits[] = $name;
            }
        }
        if ($culprits === []) {
            $this->addFinding(
                signature: implode('|', [$mode->value, $category, 'interaction', md5((string)$generation['error'])]),
                finding: [
                    'mode' => $mode->value,
                    'category' => $category,
                    'kind' => 'interaction',
                    'standalone' => false,
                    'document' => $spec->document(),
                    'payload' => null,
                    'observed' => $generation['error'],
                    'tags' => [],
                    'seed' => $spec->seed,
                ],
            );

            return;
        }

        foreach ($culprits as $name) {
            $stage = $generation['stage'];
            $stillFails = fn(array $document): bool => $this->generateChecked(document: $document, mode: $mode)['stage'] === $stage;
            $preliminary = implode('|', [$mode->value, $category, self::errorShape((string)$generation['error']), InstanceGenerator::descriptor($spec->cases[$name]['node'])]);
            if (array_key_exists($preliminary, $this->preliminary)) {
                $this->bump(signature: $this->preliminary[$preliminary], seed: $spec->seed);

                continue;
            }
            $document = $this->shrinkSchema(document: $spec->document([$name]), property: $name, stillFails: $stillFails);
            $final = $this->generateChecked(document: $document, mode: $mode);
            $signature = implode('|', [$mode->value, $category, self::errorShape((string)$final['error']), md5((string)json_encode(self::skeleton($document)))]);
            $this->preliminary[$preliminary] = $signature;
            $this->addFinding(signature: $signature, finding: [
                'mode' => $mode->value,
                'category' => $category,
                'kind' => $category,
                'standalone' => true,
                'document' => $document,
                'payload' => null,
                'observed' => $final['error'],
                'tags' => [],
                'seed' => $spec->seed,
            ]);
        }
    }

    /**
     * The message with names and numbers blurred, so one defect met in two specs dedupes.
     */
    private static function errorShape(string $error): string
    {
        return md5((string)preg_replace(['/Fz\w+/', '/"[^"]*"/', '/\d+/', '/\$\w+/'], ['NS', 'S', 'N', 'V'], strtok($error, "\n")));
    }

    /**
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array<int, GenerationMode> $oracles
     * @param array<int, string> $warnings
     */
    private function recordFailure(
        FuzzSpec $spec,
        array $payload,
        GenerationMode $mode,
        PayloadOutcome $outcome,
        string $category,
        array $oracles,
        array $warnings,
    ): void {
        $property = $payload['property'];
        $kindBase = (string)strtok($payload['kind'], ':');
        $throwableClass = $outcome->throwable === null ? '' : $outcome->throwable::class;
        $descriptor = $property === null ? 'probe' : InstanceGenerator::descriptor($spec->cases[$property]['node']);
        $preliminary = $category === 'crash'
            ? implode('|', [$mode->value, $category, $throwableClass, self::errorShape($outcome->detail)])
            : implode('|', [$mode->value, $category, $kindBase, $descriptor, implode(',', $payload['tags'])]);
        if (array_key_exists($preliminary, $this->preliminary)) {
            $this->bump(signature: $this->preliminary[$preliminary], seed: $spec->seed);

            return;
        }
        if (!$this->shrink) {
            $this->finishFailure(
                spec: $spec,
                payload: $payload,
                mode: $mode,
                category: $category,
                oracles: $oracles,
                warnings: $warnings,
                document: $spec->document(),
                suspect: $property,
                value: $property === null ? null : json_decode($payload['json'])->{$property},
                preliminary: $preliminary,
                standalone: false,
            );

            return;
        }
        $this->stats['shrinkRuns']++;
        if ($this->verbose) {
            fwrite(STDERR, sprintf("  shrinking %s %s %s (%s) seed %d\n", $mode->value, $category, $payload['kind'], $property ?? '-', $spec->seed));
        }

        $stillFails = fn(array $candidate, ?string $name, mixed $candidateValue): bool => $this->stillFails(
            document: $candidate,
            property: $name,
            value: $candidateValue,
            mode: $mode,
            original: $outcome,
            expected: $payload['expected'],
            oracles: $oracles,
        );

        // The empty body fails: some property breaks the whole class. Find which, alone.
        $suspects = $property === null ? array_keys($spec->cases) : [$property];
        $found = false;
        foreach ($suspects as $suspect) {
            $aloneDocument = null;
            $value = $property === null ? null : json_decode($payload['json'])->{$property};
            $document = $spec->document([$suspect]);
            $this->originalWarnings = count($this->generateChecked(document: $document, mode: $mode)['warnings']);
            $this->referenceAgreed = $payload['expected'] !== PayloadVerdict::Crashed
                && (new ReferenceOracle($document))->accepts($property === null ? '{}' : FuzzSpec::payload($property, $value)) === ($payload['expected'] === PayloadVerdict::Accepted);
            if (!$stillFails($document, $property, $value)) {
                continue;
            }
            $found = true;
            // Shrinking needs an oracle that owns its checks: with only symfony or yii3 answering right
            // — the modes whose frameworks are documented to decide first — a removal can drift into a
            // documented divergence unnoticed. Such a case is reported as the property alone.
            $oracleValues = array_map(static fn(GenerationMode $oracle): string => $oracle->value, $oracles);
            $ownChecks = [GenerationMode::Runtime->value, GenerationMode::Laravel->value, GenerationMode::LaravelData->value];
            $trusted = array_intersect($oracleValues, $ownChecks);
            if ($trusted !== [] || $category === 'crash') {
                $aloneDocument = $document;
                $document = $this->shrinkSchema(
                    document: $document,
                    property: $suspect,
                    stillFails: static fn(array $candidate): bool => $stillFails($candidate, $property, $value),
                    payloadKeys: self::payloadKeys($value),
                );
                if ($property !== null) {
                    $value = $this->shrinkPayload(
                        value: $value,
                        stillFails: static fn(mixed $candidate): bool => $stillFails($document, $property, $candidate),
                    );
                }
            }
            $this->finishFailure(
                spec: $spec,
                payload: $payload,
                mode: $mode,
                category: $category,
                oracles: $oracles,
                warnings: $warnings,
                document: $document,
                suspect: $suspect,
                value: $value,
                preliminary: $preliminary,
                standalone: true,
                aloneDocument: $aloneDocument,
            );
        }

        $this->originalWarnings = PHP_INT_MAX;
        $this->referenceAgreed = false;
        if (!$found) {
            // Fails only beside other properties — usually collateral of a property that breaks the
            // whole class, which is then reported on its own. Kept, unshrunk, so nothing is lost.
            $this->stats['collateral']++;
            $this->finishFailure(
                spec: $spec,
                payload: $payload,
                mode: $mode,
                category: $category,
                oracles: $oracles,
                warnings: $warnings,
                document: $spec->document(),
                suspect: $property,
                value: $property === null ? null : json_decode($payload['json'])->{$property},
                preliminary: $preliminary,
                standalone: false,
            );
        }
    }

    /**
     * @param array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>} $payload
     * @param array<int, GenerationMode> $oracles
     * @param array<int, string> $warnings
     * @param array<string, mixed> $document
     */
    private function finishFailure(
        FuzzSpec $spec,
        array $payload,
        GenerationMode $mode,
        string $category,
        array $oracles,
        array $warnings,
        array $document,
        ?string $suspect,
        mixed $value,
        string $preliminary,
        bool $standalone,
        ?array $aloneDocument = null,
    ): void {
        $property = $payload['property'];
        $json = $property === null ? '{}' : FuzzSpec::payload($property, $value);
        $generation = $this->generateChecked(document: $document, mode: $mode);
        $final = $generation['fqcn'] === null ? null : $this->run(mode: $mode, fqcn: $generation['fqcn'], json: $json);
        $throwableClass = $final?->throwable === null ? '' : $final->throwable::class;

        $signature = implode(separator: '|', array: [
            $mode->value,
            $category,
            $category === 'crash' ? $throwableClass . self::errorShape((string)$final?->detail) : (string)strtok($payload['kind'], ':'),
            $standalone ? '' : 'collateral',
            md5((string)json_encode(self::skeleton($suspect === null ? $document : self::propertyView($document, $suspect)))),
        ]);
        $this->preliminary[$preliminary] = $signature;
        $this->addFinding(signature: $signature, finding: [
            'mode' => $mode->value,
            'category' => $category,
            'kind' => $payload['kind'],
            'expected' => $payload['expected']->value,
            'observed' => $final?->verdict->value,
            'stage' => $final?->stage,
            'detail' => mb_substr((string)$final?->detail, 0, 600),
            'standalone' => $standalone,
            'property' => $suspect,
            'document' => $document,
            'payload' => $json,
            'originalPayload' => $payload['json'],
            'unshrunkSchemas' => $aloneDocument === null ? null : self::displayable($aloneDocument['components']['schemas']),
            'tags' => $payload['tags'],
            'everyModeAgrees' => $oracles === [],
            'oracles' => array_map(static fn(GenerationMode $oracle): string => $oracle->value, $oracles),
            'warnings' => array_values(array_filter(
                array: $warnings,
                callback: static fn(string $warning): bool => $suspect !== null && str_contains($warning, '"' . $suspect . '"'),
            )),
            'seed' => $spec->seed,
        ]);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, GenerationMode> $oracles
     */
    private function stillFails(
        array $document,
        ?string $property,
        mixed $value,
        GenerationMode $mode,
        PayloadOutcome $original,
        PayloadVerdict $expected,
        array $oracles,
    ): bool {
        $json = $property === null ? '{}' : FuzzSpec::payload($property, $value);
        $generation = $this->generateChecked(document: $document, mode: $mode);
        if ($generation['fqcn'] === null) {
            return false;
        }
        $outcome = $this->run(mode: $mode, fqcn: $generation['fqcn'], json: $json);
        if ($outcome->verdict !== $original->verdict) {
            return false;
        }
        if ($original->verdict === PayloadVerdict::Crashed) {
            return $outcome->throwable !== null && $original->throwable !== null && $outcome->throwable::class === $original->throwable::class;
        }
        if ($oracles === []) {
            // No mode answered right to begin with: only the verdict itself can be compared, and the
            // case is not shrunk (see recordFailure), since nothing tells a removal that keeps the
            // payload's meaning from one that changes it.
            return true;
        }
        // The expectation must still hold on the SHRUNK document, by a judge that is no generation mode:
        // the oracles share the generator, so a removal they all misread — the `enum` behind a
        // `notInEnum` gone, a component shrunk to `{}` that admits everything — was kept, and the
        // payload drifted to one the shrunk document admits.
        if ($this->referenceAgreed && $this->referenceDisagrees(document: $document, json: $json, expected: $expected)) {
            return false;
        }
        // Nor into a documented divergence at the top: a JSON array (symfony, yii3) or a scalar (symfony)
        // where the property now resolves to an object.
        if ($property !== null && self::landsInDocumentedTopLevel(document: $document, property: $property, value: $value, mode: $mode)) {
            return false;
        }
        // A removal must not lead into what the generator itself warns about (an undiscriminated union of
        // objects, …): that is documented at generation time, not a finding.
        if (count($generation['warnings']) > $this->originalWarnings) {
            return false;
        }
        // The two most trusted oracles must both still answer right: one lenient mode alone would let a
        // removal change what the payload means.
        $needed = min(2, count($oracles));
        $agreeing = 0;
        foreach (array_slice($oracles, 0, $needed) as $oracle) {
            $oracleGeneration = $this->generateChecked(document: $document, mode: $oracle);
            if ($oracleGeneration['fqcn'] === null || $this->run(mode: $oracle, fqcn: $oracleGeneration['fqcn'], json: $json)->verdict !== $expected) {
                return false;
            }
            $agreeing++;
        }

        return $agreeing === $needed;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function referenceDisagrees(array $document, string $json, PayloadVerdict $expected): bool
    {
        $verdict = (new ReferenceOracle($document))->accepts($json);

        return $verdict !== null && $verdict !== ($expected === PayloadVerdict::Accepted);
    }

    /**
     * Whether the payload's top-level value meets a support-matrix row for this mode: a JSON array for an
     * object ("a JSON array for a `type: object` property": symfony, yii3) or a scalar for an object ("a
     * scalar for a property typed as a generated class": symfony). The fuzzer never generates those for
     * these modes; a shrink must not arrive at one either.
     *
     * @param array<string, mixed> $document
     */
    private static function landsInDocumentedTopLevel(array $document, string $property, mixed $value, GenerationMode $mode): bool
    {
        if ($mode !== GenerationMode::Symfony && $mode !== GenerationMode::Yii3) {
            return false;
        }
        $schemas = $document['components']['schemas'];
        if (!self::isObjectShaped(schema: $schemas['Probe']['properties'][$property] ?? [], schemas: $schemas, depth: 0)) {
            return false;
        }
        if (is_array($value)) {
            return true;
        }

        return $mode === GenerationMode::Symfony && $value !== null && !$value instanceof stdClass;
    }

    /**
     * Whether a schema resolves to an object: through `$ref` hops, a single-member `anyOf`/`oneOf`
     * wrapper, or a union whose every non-null member is an object — the shapes symfony and yii3 type
     * as the class, so the class rows of the support matrix speak for them.
     *
     * @param array<string, mixed> $schemas
     */
    private static function isObjectShaped(mixed $schema, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 8) {
            return false;
        }
        if (is_string($schema['$ref'] ?? null)) {
            return self::isObjectShaped(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? [],
                schemas: $schemas,
                depth: $depth + 1,
            );
        }
        $type = $schema['type'] ?? null;
        if ($type !== null) {
            return $type === 'object' || (is_array($type) && in_array('object', $type, true));
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }
            $objectMembers = 0;
            foreach ($schema[$keyword] as $member) {
                if (is_array($member) && ($member['type'] ?? null) === 'null') {
                    continue;
                }
                if (!self::isObjectShaped(schema: $member, schemas: $schemas, depth: $depth + 1)) {
                    return false;
                }
                $objectMembers++;
            }

            return $objectMembers > 0;
        }

        return false;
    }

    /**
     * Greedy: try every single edit, keep the first that still fails, start over; stop when none does.
     *
     * @param array<string, mixed> $document
     * @param callable(array<string, mixed>): bool $stillFails
     * @param array<int, string> $payloadKeys object keys the payload sends; their properties are not removed,
     *     which would turn a declared key into an undeclared one — the documented closed-object divergence
     * @return array<string, mixed>
     */
    private function shrinkSchema(array $document, string $property, callable $stillFails, array $payloadKeys = []): array
    {
        $trials = 0;
        $improved = true;
        while ($improved && $trials < self::SHRINK_SCHEMA_TRIALS) {
            $improved = false;
            foreach ($this->schemaEdits(document: $document, property: $property, payloadKeys: $payloadKeys) as $candidate) {
                if (++$trials > self::SHRINK_SCHEMA_TRIALS) {
                    break;
                }
                $candidate = self::prune($candidate);
                if ($candidate === $document) {
                    continue;
                }
                if ($stillFails($candidate)) {
                    $document = $candidate;
                    $improved = true;

                    break;
                }
            }
        }

        return $document;
    }

    /**
     * @param callable(mixed): bool $stillFails
     */
    private function shrinkPayload(mixed $value, callable $stillFails): mixed
    {
        $trials = 0;
        $improved = true;
        while ($improved && $trials < self::SHRINK_PAYLOAD_TRIALS) {
            $improved = false;
            foreach (self::valueEdits($value) as $candidate) {
                if (++$trials > self::SHRINK_PAYLOAD_TRIALS) {
                    break;
                }
                if ($stillFails($candidate)) {
                    $value = $candidate;
                    $improved = true;

                    break;
                }
            }
        }

        return $value;
    }

    /**
     * Every document one edit away, the largest reductions first.
     *
     * @param array<string, mixed> $document
     * @param array<int, string> $payloadKeys
     * @return iterable<array<string, mixed>>
     */
    private function schemaEdits(array $document, string $property, array $payloadKeys): iterable
    {
        $paths = [];
        $this->collectSchemaPaths(document: $document, path: ['components', 'schemas', 'Probe', 'properties', $property], into: $paths);
        foreach (array_keys($document['components']['schemas']) as $name) {
            if ($name !== 'Probe') {
                $this->collectSchemaPaths(document: $document, path: ['components', 'schemas', $name], into: $paths);
            }
        }

        // A discriminator names its branches by reference: those stay references, and the keyword stays,
        // or the union becomes one of OBJECTS without a discriminator — which the generator warns about.
        $discriminated = [];
        foreach ($paths as $path) {
            $schema = self::at($document, $path);
            if (array_key_exists('discriminator', $schema)) {
                foreach (self::COMPOSITION_KEYWORDS as $keyword) {
                    foreach ((array)($schema[$keyword] ?? []) as $branch) {
                        $discriminated[] = is_array($branch) ? ($branch['$ref'] ?? '') : '';
                    }
                }
            }
        }

        // 1. a composition collapsed into one of its branches; a `$ref` replaced by its target
        foreach ($paths as $path) {
            $schema = self::at($document, $path);
            foreach (self::COMPOSITION_KEYWORDS as $keyword) {
                foreach ((array)($schema[$keyword] ?? []) as $branch) {
                    if (is_array($branch)) {
                        yield self::with($document, $path, $branch);
                    }
                }
            }
            if (array_key_exists('$ref', $schema) && is_string($schema['$ref']) && !in_array($schema['$ref'], $discriminated, true)) {
                $target = $document['components']['schemas'][substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null;
                $siblings = array_filter($schema, static fn(string $key): bool => $key !== '$ref', ARRAY_FILTER_USE_KEY);
                if (is_array($target) && array_intersect_key($siblings, $target) === []) {
                    yield self::with($document, $path, $siblings + $target);
                }
            }
        }
        // 2. a property of a nested object dropped
        foreach ($paths as $path) {
            $schema = self::at($document, $path);
            foreach (array_keys((array)($schema['properties'] ?? [])) as $name) {
                if (in_array((string)$name, $payloadKeys, true)) {
                    continue;
                }
                $properties = $schema['properties'];
                $kept = array_filter($properties, static fn(string $key): bool => $key !== $name, ARRAY_FILTER_USE_KEY);
                yield self::with($document, $path, ['properties' => $kept] + $schema);
            }
        }
        // 3. a keyword dropped
        foreach ($paths as $path) {
            $schema = self::at($document, $path);
            foreach (array_keys($schema) as $keyword) {
                // `type` stays: without it the case turns into an untyped schema, a different shape.
                // `properties` stays while the payload sends one of them (see $payloadKeys).
                $sent = array_intersect(array_map('strval', array_keys((array)($schema['properties'] ?? []))), $payloadKeys);
                if ($keyword === 'type' || $keyword === 'discriminator' || ($keyword === 'properties' && $sent !== [])) {
                    continue;
                }
                $rest = array_filter($schema, static fn(string $key): bool => $key !== $keyword, ARRAY_FILTER_USE_KEY);
                yield self::with($document, $path, $rest);
            }
        }
        // 4. one member of a list keyword dropped
        foreach ($paths as $path) {
            $schema = self::at($document, $path);
            foreach (self::LIST_KEYWORDS as $keyword) {
                if (!is_array($schema[$keyword] ?? null) || count($schema[$keyword]) < 2) {
                    continue;
                }
                foreach (array_keys($schema[$keyword]) as $index) {
                    $list = $schema[$keyword];
                    $kept = array_values(array_filter($list, static fn(int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
                    $replacement = $schema;
                    $replacement[$keyword] = $keyword === 'type' && count($kept) === 1 ? $kept[0] : $kept;
                    yield self::with($document, $path, $replacement);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, string|int> $path
     * @param array<int, array<int, string|int>> $into
     */
    private function collectSchemaPaths(array $document, array $path, array &$into): void
    {
        $schema = self::at($document, $path);
        if ($schema === []) {
            return;
        }
        $into[] = $path;
        foreach (['items', 'additionalProperties', 'not'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $this->collectSchemaPaths(document: $document, path: [...$path, $keyword], into: $into);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
            foreach (array_keys((array)($schema[$keyword] ?? [])) as $index) {
                $this->collectSchemaPaths(document: $document, path: [...$path, $keyword, $index], into: $into);
            }
        }
        foreach (array_keys((array)($schema['properties'] ?? [])) as $name) {
            $this->collectSchemaPaths(document: $document, path: [...$path, 'properties', $name], into: $into);
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, string|int> $path
     * @return array<string, mixed>
     */
    private static function at(array $document, array $path): array
    {
        $current = $document;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return [];
            }
            $current = $current[$key];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, string|int> $path
     * @return array<string, mixed>
     */
    private static function with(array $document, array $path, mixed $replacement): array
    {
        if ($path === []) {
            return is_array($replacement) ? $replacement : $document;
        }
        $key = $path[0];
        $child = is_array($document[$key] ?? null) ? $document[$key] : [];
        $document[$key] = count($path) === 1 ? $replacement : self::with($child, array_slice($path, 1), $replacement);

        return $document;
    }

    /**
     * Drop the components nothing reaches any more.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function prune(array $document): array
    {
        $schemas = $document['components']['schemas'];
        $reachable = FuzzSpec::reachableComponents(start: $schemas['Probe'], components: $schemas);
        $kept = ['Probe' => $schemas['Probe']];
        foreach ($schemas as $name => $schema) {
            if (in_array($name, $reachable, true)) {
                $kept[$name] = $schema;
            }
        }
        $document['components']['schemas'] = $kept;

        return $document;
    }

    /**
     * Every object key anywhere in a value.
     *
     * @return array<int, string>
     */
    private static function payloadKeys(mixed $value): array
    {
        $keys = [];
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $inner) {
                $keys = [...$keys, (string)$key, ...self::payloadKeys($inner)];
            }
        } elseif (is_array($value)) {
            foreach ($value as $inner) {
                $keys = [...$keys, ...self::payloadKeys($inner)];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every value one removal away: a key of a nested object or a member of a nested list.
     *
     * @return iterable<mixed>
     */
    private static function valueEdits(mixed $value): iterable
    {
        if ($value instanceof stdClass) {
            // Never down to an EMPTY container: `[]` and `{}` decode to one PHP value in the framework
            // modes, a documented divergence a shrink must not wander into.
            foreach (count(get_object_vars($value)) > 1 ? array_keys(get_object_vars($value)) : [] as $key) {
                $copy = json_decode((string)json_encode($value));
                $kept = new stdClass();
                foreach (get_object_vars($copy) as $name => $inner) {
                    if ((string)$name !== (string)$key) {
                        $kept->{$name} = $inner;
                    }
                }
                yield $kept;
            }
            foreach (get_object_vars($value) as $key => $inner) {
                foreach (self::valueEdits($inner) as $edited) {
                    $copy = json_decode((string)json_encode($value));
                    $copy->{$key} = $edited;
                    yield $copy;
                }
            }
        } elseif (is_array($value)) {
            foreach (count($value) > 1 ? array_keys($value) : [] as $index) {
                yield array_values(array_filter($value, static fn(int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
            }
            foreach ($value as $index => $inner) {
                foreach (self::valueEdits($inner) as $edited) {
                    $copy = json_decode((string)json_encode($value));
                    $copy[$index] = $edited;
                    yield $copy;
                }
            }
        }
    }

    /**
     * The property's schema with every reachable component, for the signature.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function propertyView(array $document, string $property): array
    {
        $schemas = $document['components']['schemas'];

        return ['property' => $schemas['Probe']['properties'][$property] ?? [], 'components' => array_values(array_filter(
            array: $schemas,
            callback: static fn(string $name): bool => $name !== 'Probe',
            mode: ARRAY_FILTER_USE_KEY,
        ))];
    }

    /**
     * The shape of a schema with its literal values blurred: what dedupes two findings of one defect.
     */
    private static function skeleton(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return is_bool($schema) ? $schema : '#';
        }
        if (array_is_list($schema)) {
            return array_map(self::skeleton(...), $schema);
        }
        $shape = [];
        foreach ($schema as $key => $value) {
            $shape[$key] = match (true) {
                in_array($key, ['type', 'format', 'nullable', 'uniqueItems'], true) => $value,
                $key === '$ref' => 'R',
                in_array($key, ['enum', 'required', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'multipleOf', 'minItems', 'maxItems', 'minProperties', 'maxProperties'], true) => '#',
                $key === 'exclusiveMinimum' || $key === 'exclusiveMaximum' => is_bool($value) ? $value : '#',
                $key === 'properties' && is_array($value) => array_values(array_map(self::skeleton(...), $value)),
                default => self::skeleton($value),
            };
        }
        ksort($shape);

        return $shape;
    }

    /**
     * The document as JSON should read it: an empty schema is `{}`, not the `[]` an empty PHP array
     * encodes as.
     */
    private static function displayable(mixed $value, bool $isSchema = false): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return $isSchema ? new stdClass() : [];
        }
        $display = [];
        foreach ($value as $key => $inner) {
            $display[$key] = match (true) {
                in_array($key, ['items', 'additionalProperties', 'not'], true) => self::displayable(value: $inner, isSchema: true),
                in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true) && is_array($inner) => array_map(
                    callback: static fn(mixed $branch): mixed => self::displayable(value: $branch, isSchema: true),
                    array: $inner,
                ),
                in_array($key, ['properties', 'schemas'], true) && is_array($inner) => $inner === [] ? new stdClass() : array_map(
                    callback: static fn(mixed $schema): mixed => self::displayable(value: $schema, isSchema: true),
                    array: $inner,
                ),
                default => self::displayable($inner),
            };
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function addFinding(string $signature, array $finding): void
    {
        $finding['document'] = self::displayable($finding['document']);
        if (array_key_exists($signature, $this->findings)) {
            $this->bump(signature: $signature, seed: $finding['seed']);

            return;
        }
        $this->findings[$signature] = ['signature' => $signature, 'occurrences' => 1, 'seeds' => [$finding['seed']]] + $finding;
        if ($this->verbose) {
            fwrite(STDERR, sprintf("  FINDING %s %s %s\n", $finding['mode'], $finding['category'], $finding['kind']));
        }
    }

    private function bump(string $signature, int $seed): void
    {
        if (!array_key_exists($signature, $this->findings)) {
            return;
        }
        $this->findings[$signature]['occurrences']++;
        if (!in_array($seed, $this->findings[$signature]['seeds'], true) && count($this->findings[$signature]['seeds']) < 10) {
            $this->findings[$signature]['seeds'][] = $seed;
        }
    }

    /**
     * Generated files are already loaded; the directory can go.
     */
    private function cleanOutput(): void
    {
        foreach (glob($this->outputDirectory . '/*') ?: [] as $entry) {
            self::deleteTree($entry);
        }
    }

    public static function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::deleteTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
