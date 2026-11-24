<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

/**
 * Spells a `SchemaGrammar` model node as an OpenAPI schema — the half where combinations multiply.
 *
 * The same meaning can be written inline, as a `$ref` to a component, as an alias of an alias, as an
 * `allOf` whose branches carry parts of the constraints, as a `$ref` with sibling keywords (3.1), and
 * its nullability as 3.0 `nullable: true`, `allOf: [$ref] + nullable`, a 3.1 type list or an
 * `anyOf`/`oneOf` with `{type: "null"}`. Each spelling is chosen at random; none changes what is valid,
 * which is the invariant the instance generator relies on.
 *
 * Spellings whose meaning is disputed are not produced: 3.0 `nullable` is put only where a `type` (or
 * the `allOf: [$ref]` idiom) is beside it, siblings of a `$ref` appear only in 3.1, and an `allOf` split
 * of an object never carries `additionalProperties`, which would judge the other branch's keys.
 */
final class SchemaRenderer
{
    /** @var array<string, mixed> */
    private array $components = [];

    public function __construct(
        private readonly FuzzRandom $random,
        private readonly bool $openApi31,
        private readonly NameAllocator $names,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function render(array $node): array
    {
        $nullable = (bool)($node['nullable'] ?? false);

        return match ($node['t']) {
            'string', 'integer', 'number', 'boolean', 'null' => $this->spellScalar(core: $this->scalarCore($node), nullable: $nullable),
            'array' => $this->spellContainer(core: $this->arrayCore($node), nullable: $nullable),
            'map' => $this->spellContainer(core: $this->mapCore($node), nullable: $nullable),
            'object' => $this->spellObject(node: $node, nullable: $nullable),
            'union' => $this->union(node: $node, nullable: $nullable),
            'discriminated' => $this->discriminated(node: $node, nullable: $nullable),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function scalarCore(array $node): array
    {
        $schema = ['type' => $node['t']];
        foreach (['format', 'minLength', 'maxLength', 'multipleOf', 'enum'] as $keyword) {
            if (array_key_exists($keyword, $node)) {
                $schema[$keyword] = $node[$keyword];
            }
        }
        if (array_key_exists('pattern', $node)) {
            $schema['pattern'] = SchemaGrammar::PATTERNS[$node['pattern']]['pattern'];
        }
        foreach (['minimum' => 'exclusiveMinimum', 'maximum' => 'exclusiveMaximum'] as $bound => $exclusive) {
            if (!array_key_exists($bound, $node)) {
                continue;
            }
            if (!$node[$exclusive]) {
                $schema[$bound] = $node[$bound];
            } elseif ($this->openApi31) {
                $schema[$exclusive] = $node[$bound];
            } else {
                $schema[$bound] = $node[$bound];
                $schema[$exclusive] = true;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function arrayCore(array $node): array
    {
        $schema = ['type' => 'array'];
        if (array_key_exists('prefix', $node)) {
            $schema['prefixItems'] = array_map(fn(array $item): array => $this->render($item), $node['prefix']);
        }
        $schema['items'] = $this->render($node['items']);
        foreach (['minItems', 'maxItems'] as $keyword) {
            if (array_key_exists($keyword, $node)) {
                $schema[$keyword] = $node[$keyword];
            }
        }
        if ($node['unique']) {
            $schema['uniqueItems'] = true;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function mapCore(array $node): array
    {
        $schema = ['type' => 'object', 'additionalProperties' => $this->render($node['values'])];
        foreach (['minProperties', 'maxProperties'] as $keyword) {
            if (array_key_exists($keyword, $node)) {
                $schema[$keyword] = $node[$keyword];
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>|null $only the property names to include, all when null
     * @return array<string, mixed>
     */
    private function objectCore(array $node, ?array $only = null, bool $withAdditional = true): array
    {
        $properties = [];
        foreach ($node['props'] as $name => $child) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            $properties[$name] = $this->render($child);
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        $required = array_values(array_filter(
            array: $node['required'],
            callback: static fn(string $name): bool => $only === null || in_array($name, $only, true),
        ));
        if ($required !== []) {
            $schema['required'] = $required;
        }
        if ($withAdditional && $node['additional'] !== null) {
            $schema['additionalProperties'] = $node['additional'] === false ? false : $this->render($node['additional']);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function spellScalar(array $core, bool $nullable): array
    {
        $constraints = count($core) - 1;
        $options = ['inline' => 8, 'alias' => 3, 'aliasOfAlias' => 1, 'allOfSingleRef' => 1];
        if ($constraints >= 1 && ($this->openApi31 || !$nullable) && $core['type'] !== 'null') {
            $options['allOfSplit'] = 2;
        }
        if ($constraints >= 1 && $this->openApi31) {
            $options['refSiblings'] = 2;
        }

        return match ($this->weighted($options)) {
            'inline' => $nullable ? $this->nullableInline($core) : $core,
            'alias' => $this->alias(core: $core, nullable: $nullable, depth: 1),
            'aliasOfAlias' => $this->alias(core: $core, nullable: $nullable, depth: 2),
            'allOfSingleRef' => $this->allOfSingleRef(core: $core, nullable: $nullable),
            'allOfSplit' => $this->wrapNullable(schema: $this->allOfSplit($core), nullable: $nullable),
            'refSiblings' => $this->wrapNullable(schema: $this->refSiblings($core), nullable: $nullable),
        };
    }

    /**
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function spellContainer(array $core, bool $nullable): array
    {
        $options = ['inline' => 7, 'alias' => 3, 'aliasOfAlias' => 1];
        $siblingKeywords = array_intersect(array_keys($core), ['minItems', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties']);
        if ($this->openApi31 && $siblingKeywords !== []) {
            $options['refSiblings'] = 2;
        }

        return match ($this->weighted($options)) {
            'inline' => $nullable ? $this->nullableInline($core) : $core,
            'alias' => $this->alias(core: $core, nullable: $nullable, depth: 1),
            'aliasOfAlias' => $this->alias(core: $core, nullable: $nullable, depth: 2),
            'refSiblings' => $this->wrapNullable(
                schema: $this->refSiblings(core: $core, movable: array_values($siblingKeywords)),
                nullable: $nullable,
            ),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function spellObject(array $node, bool $nullable): array
    {
        $options = ['inline' => 5, 'component' => 5, 'aliasOfAlias' => 1];
        $splittable = $node['additional'] === null && count($node['props']) >= 2;
        if ($splittable && ($this->openApi31 || !$nullable)) {
            $options['allOfSplit'] = 2;
        }
        if ($splittable && $this->openApi31) {
            $options['refSiblings'] = 2;
        }

        $spelling = $this->weighted($options);
        if ($spelling === 'inline') {
            $core = $this->objectCore($node);

            return $nullable ? $this->nullableInline($core) : $core;
        }
        if ($spelling === 'component' || $spelling === 'aliasOfAlias') {
            return $this->alias(core: $this->objectCore($node), nullable: $nullable, depth: $spelling === 'component' ? 1 : 2);
        }

        $names = $this->random->shuffle(array_keys($node['props']));
        $cut = $this->random->int(1, count($names) - 1);
        $first = array_slice($names, 0, $cut);
        $second = array_slice($names, $cut);

        if ($spelling === 'refSiblings') {
            $base = $this->names->next();
            $this->components[$base] = $this->objectCore(node: $node, only: $first);
            $schema = ['$ref' => '#/components/schemas/' . $base];
            $rest = $this->objectCore(node: $node, only: $second);
            $schema['properties'] = $rest['properties'];
            if (array_key_exists('required', $rest)) {
                $schema['required'] = $rest['required'];
            }

            return $this->wrapNullable(schema: $schema, nullable: $nullable);
        }

        $firstPart = $this->objectCore(node: $node, only: $first);
        if ($this->random->chance(0.5)) {
            $base = $this->names->next();
            $this->components[$base] = $firstPart;
            $firstPart = ['$ref' => '#/components/schemas/' . $base];
        }
        $schema = ['allOf' => [$firstPart, $this->objectCore(node: $node, only: $second)]];
        if ($this->random->chance(0.5)) {
            $schema = ['type' => 'object'] + $schema;
        }

        return $this->wrapNullable(schema: $schema, nullable: $nullable);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function union(array $node, bool $nullable): array
    {
        $branches = [];
        foreach ($node['branches'] as $index => $branch) {
            // 3.0 has no null type: the union admits null through `nullable` on ONE branch — exactly one,
            // so a `oneOf` still matches null once.
            if ($nullable && !$this->openApi31 && $index === 0) {
                $branch['nullable'] = true;
            }
            $branches[] = $this->render($branch);
        }

        $nullInside = $nullable && $this->openApi31 && $this->random->chance(0.5);
        if ($nullInside) {
            $branches[] = ['type' => 'null'];
        }
        $schema = [$node['op'] => $branches];
        if ($this->random->chance(0.3)) {
            $name = $this->names->next();
            $this->components[$name] = $schema;
            $schema = ['$ref' => '#/components/schemas/' . $name];
        }

        return $this->openApi31 && $nullable && !$nullInside ? $this->wrapNullable(schema: $schema, nullable: true) : $schema;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function discriminated(array $node, bool $nullable): array
    {
        $references = [];
        $mapping = [];
        foreach ($node['branches'] as $branch) {
            $this->components[$branch['name']] = $this->objectCore($branch['object']);
            $references[] = ['$ref' => '#/components/schemas/' . $branch['name']];
            $mapping[$branch['tag']] = '#/components/schemas/' . $branch['name'];
        }
        $discriminator = ['propertyName' => SchemaGrammar::DISCRIMINATOR_PROPERTY];
        if ($node['mapping']) {
            $discriminator['mapping'] = $mapping;
        }
        $schema = [$node['op'] => $references, 'discriminator' => $discriminator];
        if ($node['asComponent']) {
            $name = $this->names->next();
            $this->components[$name] = $schema;
            $schema = ['$ref' => '#/components/schemas/' . $name];
        }

        return $this->wrapNullable(schema: $schema, nullable: $nullable);
    }

    /**
     * A component holding the core, reached through `$depth` references; nullability either inside the
     * component or at the use site.
     *
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function alias(array $core, bool $nullable, int $depth): array
    {
        $nullInside = $nullable && $this->random->chance(0.5);
        $target = $this->names->next();
        $this->components[$target] = $nullInside ? $this->nullableInline($core) : $core;
        for ($i = 1; $i < $depth; $i++) {
            $outer = $this->names->next();
            $this->components[$outer] = ['$ref' => '#/components/schemas/' . $target];
            $target = $outer;
        }

        return $this->wrapNullable(schema: ['$ref' => '#/components/schemas/' . $target], nullable: $nullable && !$nullInside);
    }

    /**
     * The 3.0 idiom for "a reference, plus something": `allOf` with a single `$ref`.
     *
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function allOfSingleRef(array $core, bool $nullable): array
    {
        $name = $this->names->next();
        $this->components[$name] = $core;
        $schema = ['allOf' => [['$ref' => '#/components/schemas/' . $name]]];
        if (!$nullable) {
            return $schema;
        }
        if ($this->openApi31) {
            return $this->wrapNullable(schema: $schema, nullable: true);
        }

        return $schema + ['nullable' => true];
    }

    /**
     * The constraint keywords spread over two `allOf` branches, one of them perhaps a component.
     *
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function allOfSplit(array $core): array
    {
        $first = ['type' => $core['type']];
        $second = [];
        foreach ($core as $keyword => $value) {
            if ($keyword === 'type') {
                continue;
            }
            // A 3.0 exclusive flag must stay beside its bound.
            if ($keyword === 'exclusiveMinimum' || $keyword === 'exclusiveMaximum') {
                continue;
            }
            $into = $this->random->chance(0.5);
            if ($into) {
                $first[$keyword] = $value;
            } else {
                $second[$keyword] = $value;
            }
            $flag = ['minimum' => 'exclusiveMinimum', 'maximum' => 'exclusiveMaximum'][$keyword] ?? null;
            if ($flag !== null && ($core[$flag] ?? null) === true) {
                if ($into) {
                    $first[$flag] = true;
                } else {
                    $second[$flag] = true;
                }
            }
        }
        foreach (['exclusiveMinimum', 'exclusiveMaximum'] as $keyword) {
            // 3.1 numeric exclusive bounds travel on their own.
            if (array_key_exists($keyword, $core) && $core[$keyword] !== true) {
                if ($this->random->chance(0.5)) {
                    $first[$keyword] = $core[$keyword];
                } else {
                    $second[$keyword] = $core[$keyword];
                }
            }
        }
        if ($this->random->chance(0.4)) {
            $second = ['type' => $core['type']] + $second;
        }
        if ($this->random->chance(0.35)) {
            $name = $this->names->next();
            $this->components[$name] = $first;
            $first = ['$ref' => '#/components/schemas/' . $name];
        }
        $branches = $this->random->chance(0.5) ? [$first, $second] : [$second, $first];

        return ['allOf' => $branches];
    }

    /**
     * A 3.1 `$ref` with sibling keywords: the component holds the type and part of the constraints, the
     * siblings the rest — both apply.
     *
     * @param array<string, mixed> $core
     * @param array<int, string>|null $movable the keywords that may become siblings; all but `type` when null
     * @return array<string, mixed>
     */
    private function refSiblings(array $core, ?array $movable = null): array
    {
        $movable ??= array_values(array_filter(array_keys($core), static fn(string $keyword): bool => $keyword !== 'type'));
        $siblings = [];
        $component = [];
        $guaranteed = $this->random->pick($movable);
        foreach ($core as $keyword => $value) {
            if (in_array($keyword, $movable, true) && ($keyword === $guaranteed || $this->random->chance(0.4))) {
                $siblings[$keyword] = $value;
            } else {
                $component[$keyword] = $value;
            }
        }
        $name = $this->names->next();
        $this->components[$name] = $component;

        return ['$ref' => '#/components/schemas/' . $name] + $siblings;
    }

    /**
     * Nullability on a schema that has a `type` of its own.
     *
     * @param array<string, mixed> $core
     * @return array<string, mixed>
     */
    private function nullableInline(array $core): array
    {
        $withNullMember = $core;
        if (array_key_exists('enum', $withNullMember)) {
            // Under both 3.0.3 and 2020-12 an enum must list null for null to be valid.
            $withNullMember['enum'] = [...$withNullMember['enum'], null];
        }
        if (!$this->openApi31) {
            return $withNullMember + ['nullable' => true];
        }
        if ($this->random->chance(0.5)) {
            $withNullMember['type'] = [$core['type'], 'null'];

            return $withNullMember;
        }

        return $this->wrapNullable(schema: $core, nullable: true);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function wrapNullable(array $schema, bool $nullable): array
    {
        if (!$nullable) {
            return $schema;
        }
        if (!$this->openApi31) {
            // The one 3.0 spelling generators agree on for a reference: `allOf: [$ref]` + `nullable`.
            return ['allOf' => [$schema], 'nullable' => true];
        }

        return [$this->random->pick(['anyOf', 'oneOf']) => [$schema, ['type' => 'null']]];
    }

    /**
     * @param array<string, int> $options
     */
    private function weighted(array $options): string
    {
        $roll = $this->random->int(1, array_sum($options));
        foreach ($options as $option => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $option;
            }
        }

        return (string)array_key_first($options);
    }
}
