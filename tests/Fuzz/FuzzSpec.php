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
        $grammar = new SchemaGrammar(random: $random, openApi31: $openApi31, names: $names);
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

        return new self(seed: $seed, openApi31: $openApi31, cases: $cases, components: $renderer->components());
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
