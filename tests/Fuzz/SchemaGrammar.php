<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

/**
 * Random property SHAPES — the semantic model a schema is rendered from and instances are drawn from.
 *
 * A model node is an array with a `t` key (`string`, `integer`, `number`, `boolean`, `null`, `array`,
 * `object`, `map`, `union`, `discriminated`) and the constraints that kind carries. It says what the
 * schema MEANS; `SchemaRenderer` decides how it is SPELLED (inline, `$ref`, alias of an alias, `allOf`
 * split, `$ref` with siblings, 3.0 `nullable` or a 3.1 type list), and `InstanceGenerator` draws valid
 * and single-fault invalid values from the meaning alone. Keeping meaning and spelling apart is what
 * lets one valid payload be checked against every spelling of the same constraint.
 *
 * Every node is satisfiable by construction: bounds are drawn around a value that satisfies them, an
 * enum lists values the other keywords admit, and union branches are disjoint by JSON type (or carry a
 * discriminator), so "matches exactly one branch" is decidable from the type alone.
 */
final class SchemaGrammar
{
    public const int MAX_DEPTH = 3;

    /** Wire names of properties: lowercase single words, so no name normalisation is involved. */
    public const array PROPERTY_NAMES = [
        'otter', 'heron', 'badger', 'lynx', 'marten', 'plover', 'gecko', 'tapir', 'ibis', 'koala', 'lemur',
        'okapi', 'panda', 'quokka', 'raven', 'sable', 'tern', 'vole', 'wombat', 'yak', 'zebu', 'bison',
        'crane', 'dingo', 'egret', 'ferret', 'gibbon', 'hyena', 'jackal', 'kiwi', 'llama', 'macaw', 'newt',
        'ocelot', 'puffin', 'robin', 'stoat', 'toucan', 'urchin', 'walrus',
    ];

    public const string DISCRIMINATOR_PROPERTY = 'kind';

    /**
     * Patterns whose valid alphabet and a character outside it are known, so a valid string and a
     * mismatching one of any length can be drawn. `*` rather than `+`, so `minLength: 0` stays satisfiable.
     */
    public const array PATTERNS = [
        ['pattern' => '^[a-j]*$', 'alphabet' => 'abcdefghij', 'outside' => '7'],
        ['pattern' => '^[a-c0-4]*$', 'alphabet' => 'abc0124', 'outside' => 'z'],
        ['pattern' => '^[^q]*$', 'alphabet' => 'abcxyz', 'outside' => 'q'],
    ];

    public const array STRING_ENUM_VALUES = ['red', 'green', 'blue', 'amber', 'violet', 'cyan'];

    private const array FORMATS = ['date', 'date-time', 'email', 'uuid'];

    private const array DISCRIMINATOR_TAGS = ['circle', 'square', 'hexagon', 'oval'];

    public function __construct(
        private readonly FuzzRandom $random,
        private readonly bool $openApi31,
        private readonly NameAllocator $names,
    ) {
    }

    /**
     * @param array<int, string> $excludedGroups JSON type groups a union sibling already takes
     * @return array<string, mixed>
     */
    public function node(int $depth, array $excludedGroups = [], bool $allowNullable = true): array
    {
        $kind = $this->pickKind(depth: $depth, excludedGroups: $excludedGroups);
        $node = match ($kind) {
            'string' => $this->stringNode(),
            'integer' => $this->numericNode(integer: true),
            'number' => $this->numericNode(integer: false),
            'boolean' => ['t' => 'boolean'],
            'null' => ['t' => 'null'],
            'array' => $this->arrayNode($depth),
            'object' => $this->objectNode($depth),
            'map' => $this->mapNode($depth),
            'union' => $this->unionNode($depth),
            'discriminated' => $this->discriminatedNode($depth),
        };

        $nullableAllowed = $allowNullable && $kind !== 'null' && !($kind === 'discriminated' && !$this->openApi31);
        $node['nullable'] = $nullableAllowed && $this->random->chance(0.25);

        return $node;
    }

    /**
     * The JSON type group a kind occupies inside a union: two branches of one group would make
     * "which branch matched" a question of constraints rather than of type.
     */
    public static function group(string $kind): string
    {
        return match ($kind) {
            'integer', 'number' => 'number',
            'object', 'map', 'discriminated' => 'object',
            default => $kind,
        };
    }

    /**
     * @param array<int, string> $excludedGroups
     */
    private function pickKind(int $depth, array $excludedGroups): string
    {
        $weights = [
            'string' => 18,
            'integer' => 12,
            'number' => 7,
            'boolean' => 5,
            'null' => $this->openApi31 && $excludedGroups === [] ? 1 : 0,
        ];
        if ($depth < self::MAX_DEPTH) {
            $weights += ['array' => 14, 'object' => 12, 'map' => 6];
            if ($excludedGroups === []) {
                $weights += ['union' => 11, 'discriminated' => 4];
            }
        }

        $total = 0;
        $available = [];
        foreach ($weights as $kind => $weight) {
            if ($weight === 0 || in_array(self::group($kind), $excludedGroups, true)) {
                continue;
            }
            $available[$kind] = $weight;
            $total += $weight;
        }

        $roll = $this->random->int(1, $total);
        foreach ($available as $kind => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $kind;
            }
        }

        return 'string';
    }

    /**
     * @return array<string, mixed>
     */
    private function stringNode(): array
    {
        $node = ['t' => 'string'];
        $flavour = $this->random->int(1, 10);
        if ($flavour <= 2) {
            $node['format'] = $this->random->pick(self::FORMATS);

            return $node;
        }
        if ($flavour <= 4) {
            $node['enum'] = $this->random->sample(self::STRING_ENUM_VALUES, $this->random->int(1, 4));

            return $node;
        }

        if ($this->random->chance(0.5)) {
            $node['minLength'] = $this->random->int(0, 3);
        }
        if ($this->random->chance(0.5)) {
            $node['maxLength'] = ($node['minLength'] ?? 0) + $this->random->int(0, 5);
        }
        if ($this->random->chance(0.35)) {
            $node['pattern'] = $this->random->int(0, count(self::PATTERNS) - 1);
        }

        return $node;
    }

    /**
     * Bounds are drawn around a value `v` that satisfies them, so the range is never empty; `multipleOf`
     * is chosen first and `v` is one of its multiples.
     *
     * @return array<string, mixed>
     */
    private function numericNode(bool $integer): array
    {
        $node = ['t' => $integer ? 'integer' : 'number'];
        $step = $integer
            ? $this->random->pick([null, null, 2, 3, 5])
            : $this->random->pick([null, null, 0.5, 0.25, 2]);
        if ($step !== null) {
            $node['multipleOf'] = $step;
        }
        $unit = $step ?? ($integer ? 1 : 0.5);
        $center = $this->numeric($unit * $this->random->int(-12, 12), $integer);

        if ($this->random->chance(0.5)) {
            $node['enum'] = $this->enumAround(center: $center, unit: $unit, integer: $integer);
        }

        if ($this->random->chance(0.55)) {
            $gap = $this->random->int(0, 3);
            $node['minimum'] = $this->numeric($center - $unit * $gap, $integer);
            $node['exclusiveMinimum'] = $gap > 0 && $this->random->chance(0.4);
        }
        if ($this->random->chance(0.55)) {
            $gap = $this->random->int(0, 3);
            $node['maximum'] = $this->numeric($center + $unit * $gap, $integer);
            $node['exclusiveMaximum'] = $gap > 0 && $this->random->chance(0.4);
        }
        if (array_key_exists('enum', $node)) {
            // Keep only the members the bounds admit; the center always survives.
            $node['enum'] = array_values(array_filter(
                array: $node['enum'],
                callback: fn(int|float $value): bool => InstanceGenerator::withinBounds($node, $value),
            ));
        }
        if ($integer && $this->random->chance(0.15)) {
            $node['format'] = $this->random->pick(['int32', 'int64']);
        }

        return $node;
    }

    /**
     * @return array<int, int|float>
     */
    private function enumAround(int|float $center, int|float $unit, bool $integer): array
    {
        $values = [$center];
        $count = $this->random->int(0, 3);
        for ($i = 0; $i < $count; $i++) {
            $candidate = $this->numeric($center + $unit * $this->random->int(-4, 4), $integer);
            if (!in_array($candidate, $values, true)) {
                $values[] = $candidate;
            }
        }

        return $this->random->shuffle($values);
    }

    private function numeric(int|float $value, bool $integer): int|float
    {
        if ($integer) {
            return (int)$value;
        }

        return (float)$value === floor((float)$value) ? (int)$value : (float)$value;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayNode(int $depth): array
    {
        $unique = $this->random->chance(0.2);
        $items = $unique ? $this->uniqueFriendlyItem() : $this->node($depth + 1);
        $node = ['t' => 'array', 'items' => $items, 'unique' => $unique];

        if ($this->openApi31 && $this->random->chance(0.15)) {
            $node['prefix'] = [];
            $prefixCount = $this->random->int(1, 2);
            for ($i = 0; $i < $prefixCount; $i++) {
                $node['prefix'][] = $this->node(depth: self::MAX_DEPTH);
            }
            // A tuple is unique only by luck; keep the two features apart.
            $node['unique'] = false;
            $node['items'] = $this->node($depth + 1);
        }

        if ($this->random->chance(0.4)) {
            $node['minItems'] = $this->random->int(0, 2);
        }
        if ($this->random->chance(0.4)) {
            $node['maxItems'] = max(1, ($node['minItems'] ?? 0) + $this->random->int(0, 3));
        }

        return $node;
    }

    /**
     * An item kind with plenty of distinct values, so `uniqueItems` with any `maxItems` + 1 is drawable.
     *
     * @return array<string, mixed>
     */
    private function uniqueFriendlyItem(): array
    {
        if ($this->random->chance(0.5)) {
            return ['t' => 'integer', 'nullable' => false];
        }
        $node = ['t' => 'string', 'nullable' => false];
        if ($this->random->chance(0.4)) {
            $node['maxLength'] = $this->random->int(4, 8);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectNode(int $depth, bool $closable = true): array
    {
        $names = $this->random->sample(self::PROPERTY_NAMES, $this->random->int(1, 4));
        $properties = [];
        foreach ($names as $name) {
            $properties[$name] = $this->node($depth + 1);
        }
        $required = array_values(array_filter(
            array: $names,
            callback: fn(string $name): bool => $this->random->chance(0.45),
        ));

        $additional = null;
        if ($closable) {
            $roll = $this->random->int(1, 10);
            if ($roll <= 2) {
                $additional = false;
            } elseif ($roll === 3) {
                $additional = $this->node(depth: self::MAX_DEPTH);
            }
        }

        return ['t' => 'object', 'props' => $properties, 'required' => $required, 'additional' => $additional];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapNode(int $depth): array
    {
        $node = ['t' => 'map', 'values' => $this->node($depth + 1)];
        if ($this->random->chance(0.3)) {
            $node['minProperties'] = $this->random->int(0, 2);
        }
        if ($this->random->chance(0.3)) {
            $node['maxProperties'] = max(1, ($node['minProperties'] ?? 0) + $this->random->int(0, 2));
        }

        return $node;
    }

    /**
     * Two to three branches, pairwise disjoint by JSON type group; at most one object-like branch.
     *
     * @return array<string, mixed>
     */
    private function unionNode(int $depth): array
    {
        $branches = [];
        $groups = [];
        $count = $this->random->int(2, 3);
        for ($i = 0; $i < $count; $i++) {
            // `null` is the union's own nullability, never a branch of its own here.
            $branch = $this->node(depth: $depth + 1, excludedGroups: [...$groups, 'null'], allowNullable: false);
            $groups[] = self::group($branch['t']);
            $branches[] = $branch;
        }

        return ['t' => 'union', 'op' => $this->random->pick(['oneOf', 'anyOf']), 'branches' => $branches];
    }

    /**
     * A union of object components told apart by a required `kind` whose single enum member is the
     * branch's tag — so the branches are disjoint under plain JSON Schema too, discriminator or not.
     *
     * @return array<string, mixed>
     */
    private function discriminatedNode(int $depth): array
    {
        $byName = $this->random->chance(0.3);
        $tags = $this->random->sample(self::DISCRIMINATOR_TAGS, $this->random->int(2, 3));
        $branches = [];
        foreach ($tags as $tag) {
            $name = $this->names->next();
            $object = $this->objectNode(depth: $depth, closable: false);
            $object['props'] = [
                self::DISCRIMINATOR_PROPERTY => [
                    't' => 'string',
                    'enum' => [$byName ? $name : $tag],
                    'nullable' => false,
                ],
            ] + $object['props'];
            $object['required'] = [self::DISCRIMINATOR_PROPERTY, ...$object['required']];
            $object['nullable'] = false;
            $branches[] = ['tag' => $byName ? $name : $tag, 'name' => $name, 'object' => $object];
        }

        return [
            't' => 'discriminated',
            'op' => $this->random->pick(['oneOf', 'oneOf', 'anyOf']),
            'branches' => $branches,
            'mapping' => !$byName,
            // Decided here rather than by the renderer: laravel-data reads a discriminator only on a
            // component union, so whether it is inline changes what that mode is expected to do.
            'asComponent' => $this->random->chance(0.5),
        ];
    }
}
