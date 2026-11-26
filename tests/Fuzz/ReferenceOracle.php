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

    /**
     * Assertion keywords this evaluator does not implement. Read as absent, each one would pass every
     * value it refuses; present, it makes the answer `null`.
     */
    private const array UNEVALUATED_KEYWORDS = [
        'not',
        'contains',
        'minContains',
        'maxContains',
        'propertyNames',
        'patternProperties',
        'unevaluatedProperties',
        'unevaluatedItems',
        'dependentRequired',
        'dependentSchemas',
        'if',
        'then',
        'else',
    ];

    /** @var array<string, mixed> */
    private array $schemas;

    private bool $unsure = false;

    /** Whether the document is OpenAPI 3.1 — JSON Schema 2020-12, where `prefixItems` holds; 3.0 has none. */
    private bool $openApi31;

    /**
     * Whether an object member of a `oneOf` / `anyOf` lets keys it does not declare through, as the
     * Symfony serializer does: it builds the member's class from the keys the class has and leaves the
     * rest behind, `additionalProperties` unread.
     */
    private bool $unionMembersOpen = false;

    /** Whether EVERY object that declares properties lets the keys it does not declare through. */
    private bool $everyObjectOpen = false;

    /** Whether a `oneOf` holds when ANY number of its members take the value, as an `anyOf` does. */
    private bool $oneOfTakesSeveral = false;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(array $document)
    {
        $schemas = $document['components']['schemas'] ?? [];
        $this->schemas = is_array($schemas) ? $schemas : [];
        $this->openApi31 = !str_starts_with((string)($document['openapi'] ?? '3.1.0'), '3.0');
    }

    /**
     * Whether the JSON body is valid for the `Probe` schema; null when the answer is out of reach.
     */
    public function accepts(string $json): ?bool
    {
        return $this->judge(json: $json, unionMembersOpen: false, everyObjectOpen: false);
    }

    /**
     * Whether the JSON body is valid when every object member of a `oneOf` / `anyOf` that declares
     * properties ignores the keys it does not declare — `additionalProperties: false` and `additionalProperties: {schema}` unread
     * there, and only there. A body {@see accepts()} refuses and this one takes is refused by a union
     * member ONLY for an undeclared key; null when the answer is out of reach.
     */
    public function acceptsWithUnionMembersOpen(string $json): ?bool
    {
        return $this->judge(json: $json, unionMembersOpen: true, everyObjectOpen: false);
    }

    /**
     * Whether the JSON body is valid when every object that declares properties ignores the keys it does
     * not declare — what a
     * mode that builds the body into classes first (symfony, yii3) is documented to see.
     */
    public function acceptsWithUndeclaredKeysDropped(string $json): ?bool
    {
        return $this->judge(json: $json, unionMembersOpen: true, everyObjectOpen: true);
    }

    /**
     * Whether the JSON body is valid when a `oneOf` takes a value MORE than one member takes. A body
     * {@see accepts()} refuses and this one takes is refused only because two members of a `oneOf` match
     * it; null when the answer is out of reach.
     */
    public function acceptsWithOneOfTakingSeveral(string $json): ?bool
    {
        return $this->judge(json: $json, unionMembersOpen: false, everyObjectOpen: false, oneOfTakesSeveral: true);
    }

    private function judge(string $json, bool $unionMembersOpen, bool $everyObjectOpen, bool $oneOfTakesSeveral = false): ?bool
    {
        $this->unionMembersOpen = $unionMembersOpen;
        $this->everyObjectOpen = $everyObjectOpen;
        $this->oneOfTakesSeveral = $oneOfTakesSeveral;
        $this->unsure = false;
        $decoded = json_decode($json, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        $valid = $this->valid(value: $decoded, schema: $this->schemas['Probe'] ?? [], depth: 0);

        return $this->unsure ? null : $valid;
    }

    /**
     * @param bool $unionMember whether the schema is a `oneOf` / `anyOf` member, or reached from one
     *     through a `$ref` or an `allOf` without descending into a value
     */
    private function valid(mixed $value, mixed $schema, int $depth, bool $unionMember = false): bool
    {
        if (is_bool($schema)) {
            return $schema;
        }
        if (!is_array($schema) || $depth > self::MAX_DEPTH) {
            $this->unsure = true;

            return true;
        }
        if (array_intersect(self::UNEVALUATED_KEYWORDS, array_keys($schema)) !== []) {
            $this->unsure = true;
        }

        if (is_string($schema['$ref'] ?? null)) {
            if (!str_starts_with($schema['$ref'], self::REF_PREFIX)) {
                $this->unsure = true;

                return true;
            }
            $target = $this->schemas[substr($schema['$ref'], strlen(self::REF_PREFIX))] ?? null;
            if (!$this->valid(value: $value, schema: $target, depth: $depth + 1, unionMember: $unionMember)) {
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
                $matches += $this->valid(value: $value, schema: $branch, depth: $depth + 1, unionMember: $keyword !== 'allOf' || $unionMember) ? 1 : 0;
            }
            $count = count($schema[$keyword]);
            $holds = match ($keyword) {
                'allOf' => $matches === $count,
                'anyOf' => $matches > 0,
                'oneOf' => $matches === 1 || ($this->oneOfTakesSeveral && $matches > 1),
            };
            if (!$holds) {
                return false;
            }
        }

        return match (true) {
            is_string($value) => $this->validString(value: $value, schema: $schema),
            is_int($value) || is_float($value) => $this->validNumber(value: $value, schema: $schema),
            is_array($value) => $this->validList(value: $value, schema: $schema, depth: $depth),
            $value instanceof stdClass => $this->validObject(value: $value, schema: $schema, depth: $depth, open: $this->everyObjectOpen || ($unionMember && $this->unionMembersOpen)),
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

    /**
     * JSON instance equality (2020-12 §4.2.2): numbers by value, lists item by item in order, objects
     * member by member in ANY order. A schema's `enum` / `const` arrives decoded as PHP arrays, the
     * payload as `stdClass`, so a string-keyed array is an object here too.
     */
    private function equal(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    private static function canonical(mixed $value): string
    {
        return match (true) {
            is_int($value) || is_float($value) => 'n:' . json_encode((float)$value),
            $value instanceof stdClass => self::canonicalObject(get_object_vars($value)),
            is_array($value) && !array_is_list($value) => self::canonicalObject($value),
            is_array($value) => '[' . implode(',', array_map(self::canonical(...), $value)) . ']',
            default => (string)json_encode($value),
        };
    }

    /**
     * @param array<int|string, mixed> $members
     */
    private static function canonicalObject(array $members): string
    {
        $pairs = [];
        foreach ($members as $key => $member) {
            $pairs[(string)$key] = json_encode((string)$key) . ':' . self::canonical($member);
        }
        ksort($pairs, SORT_STRING);

        return '{' . implode(',', $pairs) . '}';
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
            'date-time' => $this->isDateTime($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            default => $this->unknown(),
        };
    }

    /**
     * RFC 3339 §5.6 `date-time`: `T` and `Z` in either case, an offset required, and the hour, minute
     * and offset those of a time of day. A leap second is valid only at the end of a UTC day — no answer.
     */
    private function isDateTime(string $value): bool
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))$/i';
        if (preg_match($pattern, $value, $parts) !== 1 || !$this->isDate($parts[1])) {
            return false;
        }
        $offsetHour = (int)($parts[5] ?? 0);
        $offsetMinute = (int)($parts[6] ?? 0);
        if ((int)$parts[2] > 23 || (int)$parts[3] > 59 || (int)$parts[4] > 60 || $offsetHour > 23 || $offsetMinute > 59) {
            return false;
        }

        return (int)$parts[4] === 60 ? $this->unknown() : true;
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
                $seen[] = self::canonical($item);
            }
            if (count(array_unique($seen)) !== count($seen)) {
                return false;
            }
        }
        // 2020-12: element i is held to prefixItems[i], `items` only to the elements past them.
        $prefix = $this->openApi31 && is_array($schema['prefixItems'] ?? null) ? array_values($schema['prefixItems']) : [];
        foreach (array_values($value) as $index => $item) {
            $itemSchema = $index < count($prefix) ? $prefix[$index] : ($schema['items'] ?? true);
            if (!$this->valid(value: $item, schema: $itemSchema, depth: $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validObject(stdClass $value, array $schema, int $depth, bool $open): bool
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
            // Only an object that declares properties is a class; a map is held as an array, its values
            // checked.
            if ($open && $properties !== [] && !array_key_exists($name, $properties)) {
                continue;
            }
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
