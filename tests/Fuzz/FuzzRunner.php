<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadOutcome;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use OpenapiPhpDtoGenerator\Tests\Parity\RunsPayloadsThroughModes;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use stdClass;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;
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

    /**
     * The keywords a mutation kind breaks (any one of them holds it), by the kind's part before `:`. A
     * kind not listed — `wrongType`, `nullForbidden` — breaks `type`, which no shrink removes.
     */
    private const array MUTATED_KEYWORDS = [
        'tooLong' => ['maxLength'],
        'tooShort' => ['minLength'],
        'patternMismatch' => ['pattern'],
        'notInEnum' => ['enum', 'const'],
        'badFormat' => ['format'],
        'impossibleDate' => ['format'],
        'looseDateTime' => ['format'],
        'belowMinimum' => ['minimum', 'exclusiveMinimum'],
        'atExclusiveMinimum' => ['exclusiveMinimum'],
        'aboveMaximum' => ['maximum', 'exclusiveMaximum'],
        'atExclusiveMaximum' => ['exclusiveMaximum'],
        'notMultipleOf' => ['multipleOf'],
        'tooFewItems' => ['minItems'],
        'tooManyItems' => ['maxItems'],
        'duplicateItems' => ['uniqueItems'],
        'tooFewProperties' => ['minProperties'],
        'tooManyProperties' => ['maxProperties'],
        'missingRequired' => ['required'],
        'extraKeyClosed' => ['additionalProperties', 'unevaluatedProperties'],
        'unknownDiscriminator' => ['discriminator'],
    ];

    private const string ROW_ARRAY_FOR_OBJECT = 'support matrix: "a JSON array for a `type: object` property"';

    private const string ROW_EMPTY_CONTAINER = 'support matrix: "an EMPTY JSON container: `[]` for an object, `{}` for a list or for the object member of a union beside an `array` member, `[]` for the list member of a union beside an object member"';

    private const string ROW_SCALAR_FOR_CLASS = 'support matrix: "a scalar for a property typed as a generated class"';

    private const string ROW_NON_OBJECT_ITEM = 'support matrix: "a non-object ITEM of a list or map of DTOs"';

    /** The keywords that narrow what a schema admits; a schema with none of them admits any JSON value. */
    private const array TYPING_KEYWORDS = ['type', '$ref', 'anyOf', 'oneOf', 'allOf', 'enum', 'const', 'not'];

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

    /**
     * Whether the case being shrunk is a refusal that holds with every undeclared key dropped. Only then
     * may no shrink step arrive at one that does not: symfony and yii3 build the body into classes and
     * never see such a key (support matrix, "`additionalProperties: false` … on a DTO-shaped schema",
     * and for symfony the member of a union it builds). A step that drops the union around a closed
     * member, or turns a declared key into an undeclared one, reported a `missingRequired` that was
     * really that row.
     */
    private bool $undeclaredKeysGuarded = false;

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

        $payloads = self::tagClassesRequiringNothing(payloads: $payloads, document: $document);

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
            if ($mode === GenerationMode::Symfony) {
                $payloads = self::tagObjectsOfAPair(payloads: $payloads, document: $document, fqcn: $generation['fqcn']);
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
                $row ??= $payload['property'] === null ? null : KnownDivergences::explainUnhydratableUnion(
                    mode: $mode,
                    category: $category,
                    tags: $payload['tags'],
                    warnings: $this->generated[$this->namespaceOf($document, $mode)]['warnings'] ?? [],
                    reachable: FuzzSpec::reachableComponents(
                        start: [$document['components']['schemas']['Probe']['properties'][$payload['property']] ?? []],
                        components: $document['components']['schemas'],
                    ),
                );
                $row ??= $payload['property'] === null ? null : KnownDivergences::explainFirstMemberDiscriminatedGuard(
                    mode: $mode,
                    category: $category,
                    document: $document,
                    property: $payload['property'],
                    value: json_decode($payload['json'])->{$payload['property']} ?? null,
                );
                // The tags say what the mutation meant to break; the schema says what the value meets.
                if ($row === null && $category === 'wrong-accept' && $payload['property'] !== null) {
                    $row = self::documentedShapeRow(
                        document: $document,
                        property: $payload['property'],
                        value: json_decode($payload['json'])->{$payload['property']},
                        mode: $mode,
                    );
                }
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
            $preliminary = self::preliminaryKey(
                parts: [$mode->value, $category, self::errorShape((string)$generation['error']), InstanceGenerator::descriptor($spec->cases[$name]['node'])],
                document: $spec->document([$name]),
            );
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
     * The key a failure is deduplicated by before it is shrunk: what failed, and the skeleton of the
     * document it failed in — property and component names, bounds and enum values blurred, so one
     * defect met in two specs dedupes, while the same kind met in two shapes (a list of integers and a
     * list of lists) is shrunk and reported apart instead of being counted under the first.
     *
     * @param array<int, string> $parts
     * @param array<string, mixed> $document
     */
    private static function preliminaryKey(array $parts, array $document): string
    {
        $schemas = $document['components']['schemas'] ?? [];
        $shape = [$document['openapi'] ?? null, self::skeleton(is_array($schemas) ? array_values($schemas) : [])];

        return implode('|', [...$parts, md5((string)json_encode($shape))]);
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
        $preliminary = self::preliminaryKey(
            parts: $category === 'crash'
                ? [$mode->value, $category, $throwableClass, self::errorShape($outcome->detail)]
                : [$mode->value, $category, $kindBase, $descriptor, implode(',', $payload['tags'])],
            document: $property === null ? $spec->document() : $spec->document([$property]),
        );
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
            $this->undeclaredKeysGuarded = $property !== null
                && $payload['expected'] === PayloadVerdict::Refused
                && in_array($mode, [GenerationMode::Symfony, GenerationMode::Yii3], true)
                && (new ReferenceOracle($document))->acceptsWithUndeclaredKeysDropped(FuzzSpec::payload($property, $value)) === false;
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
                    kind: $payload['kind'],
                    value: $value,
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
        $this->undeclaredKeysGuarded = false;
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
        // Nor into an object symfony builds as the member whose class sorts first, when it is another's.
        if (
            $mode === GenerationMode::Symfony
            && $expected === PayloadVerdict::Accepted
            && $property !== null
            && self::missesFirstSortedMember(document: $document, fqcn: $generation['fqcn'], property: $property, value: $value)
        ) {
            return false;
        }
        // Nor into a refusal only an undeclared key carries: symfony and yii3 never see that key.
        if ($this->undeclaredKeysGuarded && (new ReferenceOracle($document))->acceptsWithUndeclaredKeysDropped($json) === true) {
            return false;
        }
        // Nor into a documented divergence: a JSON array (symfony, yii3) or a scalar (symfony) where the
        // shrunk schema now has an object member, at the top or below. Those rows all accept, so only a
        // payload the document refuses can drift into one.
        if (
            $property !== null
            && $expected === PayloadVerdict::Refused
            && self::landsInDocumentedTopLevel(document: $document, property: $property, value: $value, mode: $mode)
        ) {
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
     * Whether the payload's top-level value meets a support-matrix row for this mode — see
     * {@see documentedShapeRow()}. The fuzzer files those under their row; a shrink must not arrive at
     * one either.
     *
     * @param array<string, mixed> $document
     */
    private static function landsInDocumentedTopLevel(array $document, string $property, mixed $value, GenerationMode $mode): bool
    {
        return self::documentedShapeRow(document: $document, property: $property, value: $value, mode: $mode) !== null;
    }

    /**
     * The support-matrix row a value meets where it sits in the document, read off the SCHEMA rather than
     * the mutation's tags — so a shrunk document that changed what the payload hits is still matched:
     *
     * - a JSON array where the schema, or a union member of it, is an object and no member admits a
     *   list ("a JSON array for a `type: object` property": symfony, yii3 — a union of an object and a
     *   scalar included; beside an `array` or untyped member the list member judges the value);
     * - a scalar where the schema, or a union member of it, is a generated class that requires nothing
     *   ("a scalar for a property typed as a generated class": symfony 7.4.10 builds an empty object,
     *   which a required key refuses);
     * - `null` or a scalar ITEM of a list, or VALUE of a map, of such classes ("a non-object ITEM of a
     *   list or map of DTOs": symfony builds an empty object, again only when the class requires
     *   nothing);
     * - `{}` where the schema, or a union member of it, is a list ("an EMPTY JSON container": yii3 reads
     *   `{}` and `[]` as one).
     *
     * The walk follows the value into the properties, items and map values it sends, and into union
     * members.
     *
     * @param array<string, mixed> $document
     */
    private static function documentedShapeRow(array $document, string $property, mixed $value, GenerationMode $mode): ?string
    {
        if ($mode !== GenerationMode::Symfony && $mode !== GenerationMode::Yii3) {
            return null;
        }
        $schemas = $document['components']['schemas'];

        return self::documentedRowAt(
            schema: $schemas['Probe']['properties'][$property] ?? [],
            value: $value,
            schemas: $schemas,
            mode: $mode,
            inContainer: false,
            depth: 0,
        );
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private static function documentedRowAt(
        mixed $schema,
        mixed $value,
        array $schemas,
        GenerationMode $mode,
        bool $inContainer,
        int $depth,
        bool $listAdmittedBeside = false,
    ): ?string {
        if (!is_array($schema) || $depth > 8) {
            return null;
        }
        // Follow a chain of component aliases to its end: a cycle, or a hop to no schema, matches no row.
        for ($hops = 0; is_string($schema['$ref'] ?? null); $hops++) {
            $schema = $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null;
            if (!is_array($schema) || $hops >= 16) {
                return null;
            }
        }
        // A union member that admits a list owns a JSON array: the object member's row does not speak
        // for it, here nor in the members the walk opens below.
        $listAdmitted = $listAdmittedBeside || self::hasMember(schema: $schema, schemas: $schemas, test: self::admitsList(...));
        if (is_array($value) && !$listAdmitted && self::hasMember(schema: $schema, schemas: $schemas, test: self::isObjectShaped(...))) {
            return self::ROW_ARRAY_FOR_OBJECT;
        }
        if (
            $mode === GenerationMode::Yii3
            && $value instanceof stdClass
            && get_object_vars($value) === []
            && self::hasMember(schema: $schema, schemas: $schemas, test: self::isListShaped(...))
        ) {
            return self::ROW_EMPTY_CONTAINER;
        }
        $scalar = $value !== null && !is_array($value) && !$value instanceof stdClass;
        if ($mode === GenerationMode::Symfony && $scalar && self::hasMember(schema: $schema, schemas: $schemas, test: self::isClassRequiringNothing(...))) {
            return self::ROW_SCALAR_FOR_CLASS;
        }
        if (
            $mode === GenerationMode::Symfony
            && $value === null
            && $inContainer
            && self::isClassRequiringNothing(schema: $schema, schemas: $schemas, depth: 0)
            && !($schema['nullable'] ?? false)
        ) {
            return self::ROW_NON_OBJECT_ITEM;
        }

        $children = [];
        if ($value instanceof stdClass) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (get_object_vars($value) as $key => $inner) {
                if (array_key_exists($key, $properties)) {
                    $children[] = [$properties[$key], $inner, false, false];
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $children[] = [$schema['additionalProperties'], $inner, true, false];
                }
            }
        } elseif (is_array($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $inner) {
                $children[] = [$schema['items'], $inner, true, false];
            }
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $member) {
                $children[] = [$member, $value, $inContainer, $listAdmitted];
            }
        }
        foreach ($children as [$childSchema, $childValue, $childInContainer, $childListAdmittedBeside]) {
            $row = self::documentedRowAt(
                schema: $childSchema,
                value: $childValue,
                schemas: $schemas,
                mode: $mode,
                inContainer: $childInContainer,
                depth: $depth + 1,
                listAdmittedBeside: $childListAdmittedBeside,
            );
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a schema passes the test itself or through one of its `anyOf`/`oneOf` members.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $schemas
     * @param callable(mixed, array<string, mixed>, int): bool $test
     */
    private static function hasMember(array $schema, array $schemas, callable $test): bool
    {
        if ($test($schema, $schemas, 0)) {
            return true;
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $member) {
                if ($test($member, $schemas, 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tags `classRequiresNothing` on every payload whose value meets, where it sits in the document, a
     * generated class with an empty `required` as a scalar or as a `null` item or map value — the only
     * place symfony/serializer 7.4.10 builds the empty object the class rows of {@see KnownDivergences}
     * stand for. A class that requires a key refuses that empty object.
     *
     * @param array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}> $payloads
     * @param array<string, mixed> $document
     * @return array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}>
     */
    private static function tagClassesRequiringNothing(array $payloads, array $document): array
    {
        foreach ($payloads as $index => $payload) {
            if ($payload['property'] === null) {
                continue;
            }
            $row = self::documentedShapeRow(
                document: $document,
                property: $payload['property'],
                value: json_decode($payload['json'])->{$payload['property']},
                mode: GenerationMode::Symfony,
            );
            if ($row === self::ROW_SCALAR_FOR_CLASS || $row === self::ROW_NON_OBJECT_ITEM) {
                $payloads[$index]['tags'] = [...$payload['tags'], 'classRequiresNothing'];
            }
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private static function isClassRequiringNothing(mixed $schema, array $schemas, int $depth): bool
    {
        return self::isClassShaped(schema: $schema, schemas: $schemas, depth: $depth) && self::requiresNothing(schema: $schema, schemas: $schemas, depth: $depth);
    }

    /**
     * Whether a schema, followed through `$ref` hops and into its `allOf` parts, requires no key.
     *
     * @param array<string, mixed> $schemas
     */
    private static function requiresNothing(mixed $schema, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 8) {
            return true;
        }
        if (is_string($schema['$ref'] ?? null)) {
            return self::requiresNothing(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? [],
                schemas: $schemas,
                depth: $depth + 1,
            );
        }
        if (is_array($schema['required'] ?? null) && $schema['required'] !== []) {
            return false;
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $part) {
            if (!self::requiresNothing(schema: $part, schemas: $schemas, depth: $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a schema admits a JSON list: by its `type`, through a `$ref`, through a member of its own
     * `anyOf`/`oneOf`, or by naming no type at all.
     *
     * @param array<string, mixed> $schemas
     */
    private static function admitsList(mixed $schema, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 8) {
            return false;
        }
        if (is_string($schema['$ref'] ?? null)) {
            return self::admitsList(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? [],
                schemas: $schemas,
                depth: $depth + 1,
            );
        }
        if (self::isListShaped(schema: $schema, schemas: $schemas, depth: $depth)) {
            return true;
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $member) {
                if (self::admitsList(schema: $member, schemas: $schemas, depth: $depth + 1)) {
                    return true;
                }
            }
        }

        return array_intersect(self::TYPING_KEYWORDS, array_keys($schema)) === [];
    }

    /**
     * Whether a schema is one the generator makes a class of: a component object that is no map, or an
     * inline object that declares properties or closes itself. A bare `{type: object}` is a PHP array, a
     * map (`additionalProperties` alone) is a PHP array of its values, and a component union is its
     * members, not a class of its own.
     *
     * @param array<string, mixed> $schemas
     */
    private static function isClassShaped(mixed $schema, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 8) {
            return false;
        }
        if (is_string($schema['$ref'] ?? null)) {
            $target = $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null;
            if (!is_array($target) || self::isMap($target) || array_key_exists('anyOf', $target) || array_key_exists('oneOf', $target)) {
                return false;
            }
            if (is_string($target['$ref'] ?? null)) {
                return self::isClassShaped(schema: $target, schemas: $schemas, depth: $depth + 1);
            }

            return self::isObjectShaped(schema: $target, schemas: $schemas, depth: $depth + 1);
        }
        $type = $schema['type'] ?? null;
        if ($type !== 'object' && !(is_array($type) && in_array('object', $type, true))) {
            return false;
        }

        return (is_array($schema['properties'] ?? null) && $schema['properties'] !== []) || ($schema['additionalProperties'] ?? null) === false;
    }

    /**
     * Whether a schema is an object whose only keys are those `additionalProperties` (a schema, or
     * `true`) admits.
     *
     * @param array<string, mixed> $schema
     */
    private static function isMap(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        $object = $type === 'object' || (is_array($type) && in_array('object', $type, true));
        $additional = $schema['additionalProperties'] ?? null;

        return $object
            && (!is_array($schema['properties'] ?? null) || $schema['properties'] === [])
            && (is_array($additional) || $additional === true);
    }

    /**
     * Whether a schema resolves to an object: through `$ref` hops, a single-member `anyOf`/`oneOf`
     * wrapper, a union whose every non-null member is an object, or an `allOf` with no `type` of its
     * own and an object part — the shapes symfony and yii3 type as the class, so the class rows of the
     * support matrix speak for them.
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
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $part) {
            if (self::isObjectShaped(schema: $part, schemas: $schemas, depth: $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a schema admits a JSON list by its `type`, followed through a `$ref`.
     *
     * @param array<string, mixed> $schemas
     */
    private static function isListShaped(mixed $schema, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 8) {
            return false;
        }
        if (is_string($schema['$ref'] ?? null)) {
            return self::isListShaped(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? [],
                schemas: $schemas,
                depth: $depth + 1,
            );
        }
        $type = $schema['type'] ?? null;

        return $type === 'array' || (is_array($type) && in_array('array', $type, true));
    }

    /**
     * Tags `objectOfPair` on every valid payload of an undiscriminated union of two objects that is NO
     * instance of the member whose generated class short name sorts first. Symfony's PropertyInfo
     * reports a union type's classes sorted by name (`Green|ProbeFox` for a property declared
     * `ProbeFox|Green`), and the serializer builds the first it tries — the declaration order of the
     * document says nothing about it, and an inline member's generated name (`ProbeFox`) takes part.
     * Read off the Symfony classes generated for the document.
     *
     * @param array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}> $payloads
     * @param array<string, mixed> $document
     * @param class-string $fqcn the generated Symfony `Probe`
     * @return array<int, array{property: string|null, kind: string, json: string, expected: PayloadVerdict, tags: array<int, string>}>
     */
    private static function tagObjectsOfAPair(array $payloads, array $document, string $fqcn): array
    {
        foreach ($payloads as $index => $payload) {
            if ($payload['kind'] !== 'valid' || $payload['property'] === null || !in_array('unionObjectPair', $payload['tags'], true)) {
                continue;
            }
            $value = json_decode($payload['json'])->{$payload['property']};
            if (self::missesFirstSortedMember(document: $document, fqcn: $fqcn, property: $payload['property'], value: $value)) {
                $payloads[$index]['tags'] = [...$payload['tags'], 'objectOfPair'];
            }
        }

        return $payloads;
    }

    /**
     * Whether the value, walked down the document and the generated Symfony classes side by side, meets
     * an undiscriminated union of object members whose first class by short name is built from a value
     * that is no instance of that member's schema.
     *
     * @param array<string, mixed> $document
     * @param class-string $fqcn
     */
    private static function missesFirstSortedMember(array $document, string $fqcn, string $property, mixed $value): bool
    {
        $schemas = is_array($document['components']['schemas'] ?? null) ? $document['components']['schemas'] : [];
        $types = self::phpTypesOf(class: $fqcn, wireName: $property);

        return self::missesAt(
            schema: $schemas['Probe']['properties'][$property] ?? null,
            value: $value,
            classes: $types['classes'],
            itemClasses: $types['items'],
            schemas: $schemas,
            depth: 0,
        );
    }

    /**
     * @param array<int, string> $classes the generated classes the PHP type admits at this point
     * @param array<int, string> $itemClasses the classes its list items or map values are typed as
     * @param array<string, mixed> $schemas
     */
    private static function missesAt(mixed $schema, mixed $value, array $classes, array $itemClasses, array $schemas, int $depth): bool
    {
        if (!is_array($schema) || $depth > 16) {
            return false;
        }
        // An enum member, or a built-in class such as the `DateTimeImmutable` of a `format: date-time`
        // member, is no class an object of the pair is built into: sorted in with the classes, one whose
        // name sorts first matched no object member and the pair went untagged (seeds 22009, 32007).
        $classes = array_values(array_filter(
            array: $classes,
            callback: static fn(string $class): bool => !enum_exists($class) && class_exists($class) && !(new ReflectionClass($class))->isInternal(),
        ));
        if ($value instanceof stdClass && $classes !== []) {
            $members = self::objectMembers(schema: $schema, schemas: $schemas, depth: 0);
            $class = $classes[0];
            if (count($members) >= 2 && count($classes) >= 2) {
                usort($classes, static fn(string $left, string $right): int => strcmp(self::shortName($left), self::shortName($right)));
                $class = $classes[0];
                $member = self::memberOfClass(class: $class, members: $members, schemas: $schemas);
                if ($member === null) {
                    return false;
                }
                $verdict = (new ReferenceOracle(['components' => ['schemas' => ['Probe' => $member] + $schemas]]))
                    ->accepts((string)json_encode($value, JSON_UNESCAPED_SLASHES));
                if ($verdict === false) {
                    return true;
                }
                $schema = $member;
            }
            $properties = self::objectProperties(schema: $schema, schemas: $schemas, depth: 0);
            foreach (get_object_vars($value) as $key => $inner) {
                if (!array_key_exists((string)$key, $properties)) {
                    continue;
                }
                $types = self::phpTypesOf(class: $class, wireName: (string)$key);
                if (self::missesAt(
                    schema: $properties[(string)$key],
                    value: $inner,
                    classes: $types['classes'],
                    itemClasses: $types['items'],
                    schemas: $schemas,
                    depth: $depth + 1,
                )) {
                    return true;
                }
            }

            return false;
        }
        if ($itemClasses === [] || !($value instanceof stdClass || is_array($value))) {
            return false;
        }
        $container = self::resolved(schema: $schema, schemas: $schemas, depth: 0);
        $inner = $value instanceof stdClass ? ($container['additionalProperties'] ?? null) : ($container['items'] ?? null);
        foreach ($value instanceof stdClass ? get_object_vars($value) : $value as $item) {
            if (self::missesAt(schema: $inner, value: $item, classes: $itemClasses, itemClasses: [], schemas: $schemas, depth: $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The schema behind `$ref` hops and a one-object-member `anyOf` / `oneOf` (a nullable wrapper).
     *
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private static function resolved(mixed $schema, array $schemas, int $depth): array
    {
        if (!is_array($schema) || $depth > 16) {
            return [];
        }
        if (is_string($schema['$ref'] ?? null) && !array_key_exists('properties', $schema)) {
            return self::resolved(schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null, schemas: $schemas, depth: $depth + 1);
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $members = array_values(array_filter(
                array: is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [],
                callback: static fn(mixed $member): bool => !(is_array($member) && ($member['type'] ?? null) === 'null'),
            ));
            if (count($members) === 1) {
                return self::resolved(schema: $members[0], schemas: $schemas, depth: $depth + 1);
            }
        }

        return $schema;
    }

    /**
     * The object-shaped members of the `anyOf` / `oneOf` the schema is, behind `$ref` hops. A bare
     * nested union is read as its members, as the generator flattens it into the PHP union.
     *
     * @param array<string, mixed> $schemas
     * @return array<int, array<string, mixed>>
     */
    private static function objectMembers(mixed $schema, array $schemas, int $depth): array
    {
        $schema = self::resolved(schema: $schema, schemas: $schemas, depth: $depth);
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }
            $members = array_values(array_filter(
                array: self::flattenedMembers(members: $schema[$keyword], depth: 0),
                callback: static fn(array $member): bool => self::isObjectShaped(schema: $member, schemas: $schemas, depth: 0),
            ));
            if (count($members) >= 2) {
                return $members;
            }
        }

        return [];
    }

    /**
     * The members of a union with every bare nested `anyOf` / `oneOf` — no `$ref`, `type`, `properties`,
     * `allOf` or `discriminator` of its own — replaced by its members, recursively. A nested union with
     * a discriminator stays one member: the generator does not flatten it either.
     *
     * @param array<mixed> $members
     * @return array<int, array<string, mixed>>
     */
    private static function flattenedMembers(array $members, int $depth): array
    {
        $flattened = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $nested = is_array($member['anyOf'] ?? null) ? $member['anyOf'] : ($member['oneOf'] ?? null);
            $bare = is_array($nested) && $depth < 8;
            foreach (['$ref', 'type', 'properties', 'allOf', 'discriminator'] as $keyword) {
                $bare = $bare && !array_key_exists($keyword, $member);
            }
            if ($bare && !(array_key_exists('anyOf', $member) && array_key_exists('oneOf', $member))) {
                $flattened = [...$flattened, ...self::flattenedMembers(members: $nested, depth: $depth + 1)];

                continue;
            }
            $flattened[] = $member;
        }

        return $flattened;
    }

    /**
     * The properties an object schema declares, merged over `$ref` (and its 3.1 siblings) and `allOf`.
     *
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private static function objectProperties(mixed $schema, array $schemas, int $depth): array
    {
        if (!is_array($schema) || $depth > 16) {
            return [];
        }
        $properties = [];
        if (is_string($schema['$ref'] ?? null)) {
            $properties = self::objectProperties(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null,
                schemas: $schemas,
                depth: $depth + 1,
            );
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $part) {
            $properties = [...$properties, ...self::objectProperties(schema: $part, schemas: $schemas, depth: $depth + 1)];
        }
        $resolved = self::resolved(schema: $schema, schemas: $schemas, depth: $depth);
        if ($resolved !== $schema) {
            $properties = [...$properties, ...self::objectProperties(schema: $resolved, schemas: $schemas, depth: $depth + 1)];
        }

        return [...$properties, ...(is_array($schema['properties'] ?? null) ? $schema['properties'] : [])];
    }

    /**
     * The member whose declared properties are the wire names the class reads.
     *
     * @param array<int, array<string, mixed>> $members
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>|null
     */
    private static function memberOfClass(string $class, array $members, array $schemas): ?array
    {
        $wireNames = self::wireNames($class);
        sort($wireNames);
        $found = [];
        foreach ($members as $member) {
            $declared = array_map('strval', array_keys(self::objectProperties(schema: $member, schemas: $schemas, depth: 0)));
            sort($declared);
            if ($declared === $wireNames) {
                $found[] = $member;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * @return array<int, string>
     */
    private static function wireNames(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $names = [];
        foreach ((new ReflectionClass($class))->getProperties() as $reflected) {
            $wireName = self::wireNameOf($reflected);
            if ($wireName !== null) {
                $names[] = $wireName;
            }
        }

        return $names;
    }

    /**
     * The key a property is read from, null for one the serializer ignores.
     */
    private static function wireNameOf(ReflectionProperty $reflected): ?string
    {
        if ($reflected->isStatic() || $reflected->getAttributes(Ignore::class) !== []) {
            return null;
        }
        foreach ($reflected->getAttributes(SerializedName::class) as $attribute) {
            $name = $attribute->getArguments()[0] ?? $attribute->getArguments()['serializedName'] ?? null;
            if (is_string($name)) {
                return $name;
            }
        }

        return $reflected->getName();
    }

    /**
     * The generated classes a property's type admits, and those its list items or map values are typed
     * as (from the `@var` docblock).
     *
     * @return array{classes: array<int, string>, items: array<int, string>}
     */
    private static function phpTypesOf(string $class, string $wireName): array
    {
        if (!class_exists($class)) {
            return ['classes' => [], 'items' => []];
        }
        foreach ((new ReflectionClass($class))->getProperties() as $reflected) {
            if (self::wireNameOf($reflected) !== $wireName) {
                continue;
            }
            $type = $reflected->getType();
            $named = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType => $type->getTypes(),
                default => [],
            };
            $classes = [];
            foreach ($named as $member) {
                if ($member instanceof ReflectionNamedType && !$member->isBuiltin() && class_exists($member->getName())) {
                    $classes[] = $member->getName();
                }
            }
            $items = [];
            $namespace = $reflected->getDeclaringClass()->getNamespaceName();
            if (preg_match('/@var\s+\??(?:array|list)<(?:(?:int|string),\s*)?([A-Za-z0-9_\\\|]+)>/', (string)$reflected->getDocComment(), $match) === 1) {
                foreach (explode('|', $match[1]) as $name) {
                    $candidate = str_contains($name, '\\') ? ltrim($name, '\\') : $namespace . '\\' . $name;
                    if (class_exists($candidate)) {
                        $items[] = $candidate;
                    }
                }
            }

            return ['classes' => $classes, 'items' => $items];
        }

        return ['classes' => [], 'items' => []];
    }

    private static function shortName(string $class): string
    {
        $cut = strrpos($class, '\\');

        return $cut === false ? $class : substr($class, $cut + 1);
    }

    /**
     * Greedy: try every single edit, keep the first that still fails, start over; stop when none does.
     *
     * A candidate that drops every keyword the mutation `$kind` broke is no candidate: the finding
     * keeps the mutation's kind, and a failure carried on by another constraint (a `tooLong` whose
     * `maxLength` went, still refused by an `enum`) would be reported as a defect it does not show —
     * and filed against the wrong `KnownDivergences` row. The keyword is looked for only on the schemas
     * the payload value meets ({@see schemasAlong()}): anywhere in the document, a `format` dropped
     * from the mutated property was "kept" by an unrelated property that still had one, and a
     * `looseDateTime` was reported for what had become a string sent where `type: null` stands.
     *
     * @param array<string, mixed> $document
     * @param callable(array<string, mixed>): bool $stillFails
     * @param array<int, string> $payloadKeys object keys the payload sends; their properties are not removed,
     *     which would turn a declared key into an undeclared one — the documented closed-object divergence
     * @param string $kind the mutation label the finding is reported under
     * @param mixed $value the payload value of `$property`, decoded with objects as `stdClass`
     * @return array<string, mixed>
     */
    private function shrinkSchema(
        array $document,
        string $property,
        callable $stillFails,
        array $payloadKeys = [],
        string $kind = '',
        mixed $value = null,
    ): array {
        $mutated = self::MUTATED_KEYWORDS[(string)strtok($kind, ':')] ?? [];
        if ($mutated !== [] && !self::holdsKeywordAlong(document: $document, property: $property, value: $value, keywords: $mutated)) {
            // The unshrunk document never held it (a mutation drawn some other way): nothing to keep.
            $mutated = [];
        }
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
                if ($mutated !== [] && !self::holdsKeywordAlong(document: $candidate, property: $property, value: $value, keywords: $mutated)) {
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
     * Whether a schema the payload value meets uses one of `$keywords` as a keyword of its own.
     *
     * @param array<string, mixed> $document
     * @param array<int, string> $keywords
     */
    private static function holdsKeywordAlong(array $document, string $property, mixed $value, array $keywords): bool
    {
        foreach (self::schemasAlong(document: $document, property: $property, value: $value) as $schema) {
            if (array_intersect($keywords, array_map('strval', array_keys($schema))) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The schemas a payload value meets from the property down: the property's own, every `$ref`
     * target and `allOf` / `anyOf` / `oneOf` member on the way, and — below an object — only the
     * properties (or `additionalProperties`) of the keys it sends, below a list only the items it has.
     *
     * @param array<string, mixed> $document
     * @return array<int, array<string, mixed>>
     */
    private static function schemasAlong(array $document, string $property, mixed $value): array
    {
        $schemas = is_array($document['components']['schemas'] ?? null) ? $document['components']['schemas'] : [];
        $found = [];
        self::collectSchemasAlong(
            schema: $schemas['Probe']['properties'][$property] ?? null,
            value: $value,
            schemas: $schemas,
            depth: 0,
            into: $found,
        );

        return $found;
    }

    /**
     * @param array<string, mixed> $schemas
     * @param array<int, array<string, mixed>> $into
     */
    private static function collectSchemasAlong(mixed $schema, mixed $value, array $schemas, int $depth, array &$into): void
    {
        if (!is_array($schema) || array_is_list($schema) || $depth > 32) {
            return;
        }
        $into[] = $schema;
        $next = $depth + 1;
        if (is_string($schema['$ref'] ?? null)) {
            self::collectSchemasAlong(
                schema: $schemas[substr($schema['$ref'], strlen('#/components/schemas/'))] ?? null,
                value: $value,
                schemas: $schemas,
                depth: $next,
                into: $into,
            );
        }
        foreach (self::COMPOSITION_KEYWORDS as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $member) {
                self::collectSchemasAlong(schema: $member, value: $value, schemas: $schemas, depth: $next, into: $into);
            }
        }
        if ($value instanceof stdClass) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (get_object_vars($value) as $key => $inner) {
                $child = array_key_exists((string)$key, $properties) ? $properties[(string)$key] : ($schema['additionalProperties'] ?? null);
                self::collectSchemasAlong(schema: $child, value: $inner, schemas: $schemas, depth: $next, into: $into);
            }
        } elseif (is_array($value)) {
            $prefix = is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : [];
            foreach (array_values($value) as $index => $inner) {
                $child = array_key_exists($index, $prefix) ? $prefix[$index] : ($schema['items'] ?? null);
                self::collectSchemasAlong(schema: $child, value: $inner, schemas: $schemas, depth: $next, into: $into);
            }
        }
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
