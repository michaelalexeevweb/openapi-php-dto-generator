<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use stdClass;

/**
 * One generated document: a `Probe` component whose properties are the fuzzed shapes, all optional, so
 * each payload sets exactly one of them — plus the components those shapes were spelled with.
 *
 * Generated ONCE per mode and then fed every payload of every property, which is what keeps a run cheap.
 */
final readonly class FuzzSpec
{
    /** Where the stream that decides on unions of two objects starts, relative to the seed. */
    private const int PAIR_STREAM_OFFSET = 7_919_000;

    private const int VALID_DRAW_ATTEMPTS = 6;

    /** The tag of a refusal a union member owes to a key it does not declare, and to nothing else. */
    public const string REFUSED_ONLY_BY_UNDECLARED_KEY = 'unionMemberRefusesOnlyUndeclaredKey';

    /**
     * @param array<string, array{node: array<string, mixed>, schema: array<string, mixed>, valid: array{value: mixed, tags: array<int, string>}, mutations: array<int, array{kind: string, value: mixed, tags: array<int, string>}>}> $cases
     * @param array<string, mixed> $components
     */
    public function __construct(
        public int $seed,
        public bool $openApi31,
        public array $cases,
        public array $components,
    ) {
    }

    public static function generate(int $seed, int $propertyCount): self
    {
        $random = new FuzzRandom($seed);
        $openApi31 = $random->chance(0.6);
        $names = new NameAllocator();
        $grammar = new SchemaGrammar(
            random: $random,
            openApi31: $openApi31,
            names: $names,
            pairs: new FuzzRandom($seed + self::PAIR_STREAM_OFFSET),
        );
        $renderer = new SchemaRenderer(random: $random, openApi31: $openApi31, names: $names);
        $instances = new InstanceGenerator($random);

        $cases = [];
        foreach ($random->sample(SchemaGrammar::PROPERTY_NAMES, $propertyCount) as $name) {
            $node = $grammar->node(depth: 1);
            $cases[$name] = [
                'node' => $node,
                'schema' => $renderer->render($node),
                'valid' => $instances->validInstance($node),
                'mutations' => $instances->mutations($node),
            ];
        }

        $components = $renderer->components();
        foreach ($cases as $name => $case) {
            if (!SchemaGrammar::holdsObjectPair($case['node'])) {
                continue;
            }
            $confirmed = self::confirmedByReference(
                name: $name,
                case: $case,
                document: self::documentFor(openApi31: $openApi31, properties: [$name => $case['schema']], components: $components),
                instances: $instances,
            );
            if ($confirmed === null) {
                // No draw a union of two objects takes exactly once: nothing to expect of it.
                $cases = array_filter($cases, static fn(string $key): bool => $key !== $name, ARRAY_FILTER_USE_KEY);

                continue;
            }
            $cases[$name] = $confirmed;
        }

        return new self(seed: $seed, openApi31: $openApi31, cases: $cases, components: $components);
    }

    /**
     * The case with its draws held to what they claim, by the reference oracle on the finished document.
     * Two object members of a union share the JSON type, so a value drawn for one may be a value of the
     * other as well: under `oneOf` that is no valid instance but a refusal — kept as one, of kind
     * `matchesTwoMembers` (only when two members do take it; a draw no member takes is drawn again) —
     * and a broken object of one member may be a sound one of the other, which is
     * no refusal at all and is dropped; one a member refuses only for a key it does not declare is
     * tagged {@see REFUSED_ONLY_BY_UNDECLARED_KEY}. A valid value is drawn again until one is taken
     * exactly once; null when none is. Runs after every case is drawn, so its own draws move nothing else.
     *
     * @param array{node: array<string, mixed>, schema: array<string, mixed>, valid: array{value: mixed, tags: array<int, string>}, mutations: array<int, array{kind: string, value: mixed, tags: array<int, string>}>} $case
     * @param array<string, mixed> $document
     * @return array{node: array<string, mixed>, schema: array<string, mixed>, valid: array{value: mixed, tags: array<int, string>}, mutations: array<int, array{kind: string, value: mixed, tags: array<int, string>}>}|null
     */
    private static function confirmedByReference(string $name, array $case, array $document, InstanceGenerator $instances): ?array
    {
        $oracle = new ReferenceOracle($document);
        $mutations = [];
        foreach ($case['mutations'] as $mutation) {
            $json = self::payload($name, $mutation['value']);
            if ($oracle->accepts($json) === true) {
                continue;
            }
            // Refused by some member only for a key that member does not declare: the Symfony serializer
            // builds its class without the key (support matrix, "`additionalProperties: false` … on a
            // DTO-shaped schema").
            if ($oracle->acceptsWithUnionMembersOpen($json) === true) {
                $mutation['tags'] = [...$mutation['tags'], self::REFUSED_ONLY_BY_UNDECLARED_KEY];
            }
            $mutations[] = $mutation;
        }

        $valid = $case['valid'];
        for ($attempt = 0; $attempt < self::VALID_DRAW_ATTEMPTS; $attempt++) {
            $verdict = $oracle->accepts(self::payload($name, $valid['value']));
            if ($verdict !== false) {
                return [...$case, 'valid' => $valid, 'mutations' => $mutations];
            }
            // Kept as a refusal only when two `oneOf` members take the value; one no member takes is the
            // instance generator and the oracle disagreeing, nothing to expect of a mode — drawn again.
            if ($attempt === 0 && $oracle->acceptsWithOneOfTakingSeveral(self::payload($name, $valid['value'])) === true) {
                $mutations[] = ['kind' => 'matchesTwoMembers', 'value' => $valid['value'], 'tags' => [...$valid['tags'], 'unionObjectPair']];
            }
            $valid = $instances->validInstance($case['node']);
        }

        return null;
    }

    /**
     * The OpenAPI document, restricted to some properties (and the components they reach) when asked.
     *
     * @param array<int, string>|null $only
     * @return array<string, mixed>
     */
    public function document(?array $only = null): array
    {
        $properties = [];
        foreach ($this->cases as $name => $case) {
            if ($only === null || in_array($name, $only, true)) {
                $properties[$name] = $case['schema'];
            }
        }

        return self::documentFor(
            openApi31: $this->openApi31,
            properties: $properties,
            components: $this->components,
        );
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $components
     * @return array<string, mixed>
     */
    public static function documentFor(bool $openApi31, array $properties, array $components): array
    {
        $probe = ['type' => 'object', 'properties' => $properties];
        $reachable = self::reachableComponents(start: $properties, components: $components);
        $schemas = ['Probe' => $probe];
        foreach ($components as $name => $schema) {
            if (in_array($name, $reachable, true)) {
                $schemas[$name] = $schema;
            }
        }

        return [
            'openapi' => $openApi31 ? '3.1.0' : '3.0.3',
            'info' => ['title' => 'Fuzz', 'version' => '1.0.0'],
            'components' => ['schemas' => $schemas],
        ];
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $start
     * @param array<string, mixed> $components
     * @return array<int, string>
     */
    public static function reachableComponents(array $start, array $components): array
    {
        $found = [];
        $queue = [$start];
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach ($current as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $name = substr($value, strlen('#/components/schemas/'));
                    if (!in_array($name, $found, true) && array_key_exists($name, $components)) {
                        $found[] = $name;
                        $queue[] = (array)$components[$name];
                    }
                } elseif (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return $found;
    }

    /**
     * The request body that sets one property.
     */
    public static function payload(string $property, mixed $value): string
    {
        $body = new stdClass();
        $body->{$property} = $value;

        return (string)json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
