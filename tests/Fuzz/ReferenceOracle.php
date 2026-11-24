<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use stdClass;

/**
 * A plain JSON Schema evaluator over the keywords the fuzz grammar writes, independent of every
 * generation mode. The shrinker asks it after each step whether the payload still means what the case
 * expects on the SHRUNK document: the modes it otherwise asks share the generator, and a removal they
 * all misread — an `enum` dropped while `notInEnum` was still expected, a component shrunk to `{}` that
 * admits everything while a refusal was still expected — was kept and reported as a finding.
 *
 * Answers `null` where it cannot judge (a keyword or a format it does not know), so it only ever
 * rules a step out, never in.
 */
final class ReferenceOracle
{
    private const string REF_PREFIX = '#/components/schemas/';

    private const int MAX_DEPTH = 64;

    /** @var array<string, mixed> */
    private array $schemas;

    private bool $unsure = false;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(array $document)
    {
        $schemas = $document['components']['schemas'] ?? [];
        $this->schemas = is_array($schemas) ? $schemas : [];
    }

    /**
     * Whether the JSON body is valid for the `Probe` schema; null when the answer is out of reach.
     */
    public function accepts(string $json): ?bool
    {
        $this->unsure = false;
        $decoded = json_decode($json, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        $valid = $this->valid(value: $decoded, schema: $this->schemas['Probe'] ?? [], depth: 0);

        return $this->unsure ? null : $valid;
    }

    private function valid(mixed $value, mixed $schema, int $depth): bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        if (!is_array($schema) || $depth > self::MAX_DEPTH) {
            $this->unsure = true;

            return true;
        }

        if (is_string($schema['$ref'] ?? null)) {
            if (!str_starts_with($schema['$ref'], self::REF_PREFIX)) {
                $this->unsure = true;

                return true;
            }
            $target = $this->schemas[substr($schema['$ref'], strlen(self::REF_PREFIX))] ?? null;
            if (!$this->valid(value: $value, schema: $target, depth: $depth + 1)) {
                // 3.0 `nullable` beside the `allOf: [$ref]` idiom is handled where `allOf` is; a bare
                // `$ref` sibling `nullable` is not a thing the grammar writes.
                return false;
            }
        }

        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return true;
        }

        if (array_key_exists('type', $schema) && !$this->matchesType(value: $value, type: $schema['type'])) {
            return false;
        }
        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && !$this->inEnum(value: $value, members: $schema['enum'])) {
            return false;
        }
        if (array_key_exists('const', $schema) && !$this->equal($value, $schema['const'])) {
            return false;
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$keyword] as $branch) {
                $matches += $this->valid(value: $value, schema: $branch, depth: $depth + 1) ? 1 : 0;
            }
            $count = count($schema[$keyword]);
            $holds = match ($keyword) {
                'allOf' => $matches === $count,
                'anyOf' => $matches > 0,
                'oneOf' => $matches === 1,
            };
            if (!$holds) {
                return false;
            }
        }

        return match (true) {
            is_string($value) => $this->validString(value: $value, schema: $schema),
            is_int($value) || is_float($value) => $this->validNumber(value: $value, schema: $schema),
            is_array($value) => $this->validList(value: $value, schema: $schema, depth: $depth),
            $value instanceof stdClass => $this->validObject(value: $value, schema: $schema, depth: $depth),
            default => true,
        };
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $member) {
                if ($this->matchesType(value: $value, type: $member)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value),
            'object' => $value instanceof stdClass,
            default => $this->unknown(),
        };
    }

    /**
     * @param array<int, mixed> $members
     */
    private function inEnum(mixed $value, array $members): bool
    {
        foreach ($members as $member) {
            if ($this->equal($value, $member)) {
                return true;
            }
        }

        return false;
    }

    private function equal(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float)$left === (float)$right;
        }

        return json_encode($left) === json_encode($right);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validString(string $value, array $schema): bool
    {
        $length = mb_strlen($value);
        if (is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
            return false;
        }
        if (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
            return false;
        }
        if (is_string($schema['pattern'] ?? null)) {
            $matched = @preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value);
            if ($matched === false) {
                return $this->unknown();
            }
            if ($matched === 0) {
                return false;
            }
        }
        if (!is_string($schema['format'] ?? null)) {
            return true;
        }

        return match ($schema['format']) {
            'date' => $this->isDate($value),
            'date-time' => preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i', $value, $parts) === 1
                && $this->isDate($parts[1]),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            default => $this->unknown(),
        };
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validNumber(int|float $value, array $schema): bool
    {
        $minimum = $schema['minimum'] ?? null;
        if (is_int($minimum) || is_float($minimum)) {
            $exclusive = ($schema['exclusiveMinimum'] ?? false) === true;
            if ($exclusive ? $value <= $minimum : $value < $minimum) {
                return false;
            }
        }
        $maximum = $schema['maximum'] ?? null;
        if (is_int($maximum) || is_float($maximum)) {
            $exclusive = ($schema['exclusiveMaximum'] ?? false) === true;
            if ($exclusive ? $value >= $maximum : $value > $maximum) {
                return false;
            }
        }
        foreach (['exclusiveMinimum' => 1, 'exclusiveMaximum' => -1] as $keyword => $direction) {
            $bound = $schema[$keyword] ?? null;
            if ((is_int($bound) || is_float($bound)) && ($value - $bound) * $direction <= 0) {
                return false;
            }
        }
        $step = $schema['multipleOf'] ?? null;
        if ((is_int($step) || is_float($step)) && $step > 0) {
            $quotient = $value / $step;
            if (abs($quotient - round($quotient)) > 1e-9) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $value
     * @param array<string, mixed> $schema
     */
    private function validList(array $value, array $schema, int $depth): bool
    {
        if (is_int($schema['minItems'] ?? null) && count($value) < $schema['minItems']) {
            return false;
        }
        if (is_int($schema['maxItems'] ?? null) && count($value) > $schema['maxItems']) {
            return false;
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $seen[] = json_encode($item);
            }
            if (count(array_unique($seen)) !== count($seen)) {
                return false;
            }
        }
        if (array_key_exists('items', $schema)) {
            foreach ($value as $item) {
                if (!$this->valid(value: $item, schema: $schema['items'], depth: $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validObject(stdClass $value, array $schema, int $depth): bool
    {
        $members = get_object_vars($value);
        if (is_int($schema['minProperties'] ?? null) && count($members) < $schema['minProperties']) {
            return false;
        }
        if (is_int($schema['maxProperties'] ?? null) && count($members) > $schema['maxProperties']) {
            return false;
        }
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
            if (!array_key_exists((string)$required, $members)) {
                return false;
            }
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? true;
        foreach ($members as $name => $member) {
            $memberSchema = array_key_exists($name, $properties) ? $properties[$name] : $additional;
            if (!$this->valid(value: $member, schema: $memberSchema, depth: $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private function unknown(): bool
    {
        $this->unsure = true;

        return true;
    }
}
