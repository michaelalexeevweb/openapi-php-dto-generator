<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use stdClass;

/**
 * Draws instances from a `SchemaGrammar` model node: one VALID value by construction, and INVALID
 * values that each break exactly one constraint in a known way, tagged with what they break.
 *
 * Only mutations whose invalidity is certain under JSON Schema 2020-12 / OpenAPI are produced. Union
 * branches are disjoint by JSON type (or by a single-member `kind` enum), so a branch's mutation is kept
 * only when its value's JSON type is admitted by that branch and by no other — then it cannot match a
 * sibling — save in a union of two object members, whose object mutations are kept and left to
 * `FuzzSpec` and the reference oracle to sort out. Bound mutations are skipped beside an `enum`, where
 * they would break two keywords at once.
 *
 * JSON objects are `stdClass` throughout, so `{}` encodes as `{}` and never as `[]`.
 *
 * Tags describe where a mutation sits — `in:items`, `in:map`, `in:prop`, `in:union`, … outermost first —
 * and what it hits (`site:dto`, `site:temporal`, …); `KnownDivergences` matches documented framework
 * limits against them.
 */
final class InstanceGenerator
{
    private const int MUTATIONS_PER_NODE = 6;

    private const string FOREIGN_WORD = 'zzq';

    /** @var array<int, string> */
    private array $validTags = [];

    public function __construct(
        private readonly FuzzRandom $random,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @return array{value: mixed, tags: array<int, string>}
     */
    public function validInstance(array $node): array
    {
        $this->validTags = [];
        $value = $this->valid($node);

        return ['value' => $value, 'tags' => array_values(array_unique($this->validTags))];
    }

    /**
     * Whether a number satisfies the bounds and `multipleOf` of a numeric node.
     *
     * @param array<string, mixed> $node
     */
    public static function withinBounds(array $node, int|float $value): bool
    {
        if (array_key_exists('minimum', $node)) {
            if ($node['exclusiveMinimum'] ? $value <= $node['minimum'] : $value < $node['minimum']) {
                return false;
            }
        }
        if (array_key_exists('maximum', $node)) {
            if ($node['exclusiveMaximum'] ? $value >= $node['maximum'] : $value > $node['maximum']) {
                return false;
            }
        }
        if (array_key_exists('multipleOf', $node)) {
            $quotient = $value / $node['multipleOf'];
            if (abs($quotient - round($quotient)) > 1e-9) {
                return false;
            }
        }

        return true;
    }

    /**
     * The JSON type a PHP value encodes as; an integral float counts as an integer (2020-12 §6.1.1).
     */
    public static function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => floor($value) === $value ? 'integer' : 'number',
            is_string($value) => 'string',
            $value instanceof stdClass => 'object',
            default => 'array',
        };
    }

    /**
     * The JSON types a node admits.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public static function admittedTypes(array $node): array
    {
        $types = match ($node['t']) {
            'number' => ['integer', 'number'],
            'map', 'object', 'discriminated' => ['object'],
            'union' => array_merge(...array_map(self::admittedTypes(...), $node['branches'])),
            default => [$node['t']],
        };
        if ($node['nullable'] ?? false) {
            $types[] = 'null';
        }

        return array_values(array_unique($types));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function valid(array $node): mixed
    {
        if (($node['nullable'] ?? false) && $this->random->chance(0.2)) {
            return null;
        }

        return match ($node['t']) {
            'string' => $this->validString($node),
            'integer', 'number' => $this->validNumber($node),
            'boolean' => $this->random->chance(0.5),
            'null' => null,
            'array' => $this->validArray($node),
            'object' => $this->validObject($node),
            'map' => $this->validMap($node),
            'union' => $this->validUnionMember($node),
            'discriminated' => $this->validDiscriminated($node),
        };
    }

    /**
     * A valid value of one member. Tagged when it is not the union's object member and that object
     * requires a key: Symfony builds the object member's class first (support matrix, "a scalar for a
     * property typed as a generated class").
     *
     * Beside an `array` member, an object member that asks for no key is also sent as `{}` now and
     * then — a VALID value the `array`-typed PHP field holds as `[]`, and the one a list member's
     * `minItems` must not judge (support matrix, "an EMPTY JSON container"). Before, `{}` there came
     * only as a mutation, so a mode that refused the valid one went unnoticed.
     *
     * @param array<string, mixed> $node
     */
    private function validUnionMember(array $node): mixed
    {
        $branch = $this->random->pick($node['branches']);
        $requiringObject = false;
        foreach (self::memberBranches($node) as $sibling) {
            $requiringObject = $requiringObject || ($sibling['t'] === 'object' && $sibling['required'] !== []);
        }
        if ($branch['t'] !== 'object' && $branch['t'] !== 'union' && $requiringObject) {
            $this->validTags[] = 'besideRequiringObject';
        }

        if (($node['objectPair'] ?? false) === true) {
            // Which member's object it is, as the Symfony serializer sees it, depends on the generated
            // class names: `FuzzRunner::tagObjectsOfAPair()` adds `objectOfPair` once they exist.
            $this->validTags[] = 'unionObjectPair';
        }
        if (self::isUndiscriminatedComponent($node)) {
            $this->validTags[] = 'undiscriminatedComponent';
        }

        $besideArray = in_array('array', self::admittedTypes($node), true);
        $value = $besideArray && self::admitsEmptyObject($branch) && $this->random->chance(0.3)
            ? new stdClass()
            : $this->valid($branch);
        if ($besideArray && $value instanceof stdClass && get_object_vars($value) === []) {
            $this->validTags[] = 'emptyBesideArray';
        }
        // A nested union's value: beside a requiring object only when it is no object itself.
        if ($branch['t'] === 'union' && $requiringObject && !$value instanceof stdClass) {
            $this->validTags[] = 'besideRequiringObject';
        }

        return $value;
    }

    /**
     * Whether a union is spelled as a component of its own (`asComponent`, {@see SchemaRenderer::placed()})
     * and has two object members or more — a union of two objects, or another union with one nested as
     * its branch: the component the Laravel modes are documented, and warned, not to hydrate (support
     * matrix, "hydration of a union COMPONENT of OBJECTS with no `discriminator`").
     *
     * @param array<string, mixed> $node
     */
    private static function isUndiscriminatedComponent(array $node): bool
    {
        if (($node['asComponent'] ?? false) !== true) {
            return false;
        }
        $objects = array_filter(self::memberBranches($node), static fn(array $member): bool => $member['t'] === 'object');

        return count($objects) >= 2;
    }

    /**
     * A union's members with the branches of a union nested as a branch spelled out in its place — what
     * the generator flattens them into, so the tags about siblings see them as one list.
     *
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    public static function memberBranches(array $node): array
    {
        $members = [];
        foreach ($node['branches'] as $branch) {
            $members = [...$members, ...($branch['t'] === 'union' ? self::memberBranches($branch) : [$branch])];
        }

        return $members;
    }

    /**
     * Whether `{}` is a valid instance of the node: an object that requires no key, or a map that asks
     * for no minimum. The grammar keeps union branches disjoint by type group, so no sibling of such a
     * member takes `{}` too and a `oneOf` stays satisfied by exactly one.
     *
     * @param array<string, mixed> $node
     */
    private static function admitsEmptyObject(array $node): bool
    {
        return match ($node['t']) {
            'object' => $node['required'] === [],
            'map' => ($node['minProperties'] ?? 0) === 0,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function validDiscriminated(array $node): stdClass
    {
        $this->validTags[] = $node['asComponent'] ? 'componentDiscriminator' : 'inlineDiscriminator';

        return $this->validObject($this->random->pick($node['branches'])['object']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function validString(array $node, ?int $length = null): string
    {
        if (array_key_exists('enum', $node)) {
            return $this->random->pick($node['enum']);
        }
        if (array_key_exists('format', $node)) {
            return match ($node['format']) {
                'date' => sprintf('20%02d-%02d-%02d', $this->random->int(10, 30), $this->random->int(1, 12), $this->random->int(1, 28)),
                'date-time' => $this->validDateTime(),
                'email' => $this->random->letters($this->random->int(1, 6)) . '@example.com',
                'uuid' => $this->uuid(),
            };
        }

        $min = $node['minLength'] ?? 0;
        $max = $node['maxLength'] ?? $min + 6;
        $length ??= $this->random->int($min, $max);
        $alphabet = array_key_exists('pattern', $node) ? SchemaGrammar::PATTERNS[$node['pattern']]['alphabet'] : 'abcdefghij';

        return $this->random->letters($length, $alphabet);
    }

    /**
     * An RFC 3339 `date-time`, sometimes with fractional seconds — three or six digits, the lengths
     * every mode reads (support matrix, "`format: date-time` with one, two, four or five fraction
     * digits") — and sometimes with `t` and `z` in lower case, which RFC 3339 §5.6 allows and four modes
     * refuse (support matrix, "RFC 3339 spellings of a `format: date-time` property"): tagged so.
     */
    private function validDateTime(): string
    {
        $value = vsprintf(
            format: '20%02d-%02d-%02dT%02d:%02d:%02d%s%s',
            values: [
                $this->random->int(10, 30),
                $this->random->int(1, 12),
                $this->random->int(1, 28),
                $this->random->int(0, 23),
                $this->random->int(0, 59),
                $this->random->int(0, 59),
                $this->random->chance(0.3) ? '.' . $this->random->letters($this->random->pick([3, 6]), '0123456789') : '',
                $this->random->pick(['Z', '+02:00', '-05:30']),
            ],
        );
        if (!$this->random->chance(0.2)) {
            return $value;
        }
        $this->validTags[] = 'lowercaseDateTime';

        return strtolower($value);
    }

    private function uuid(): string
    {
        $hex = $this->random->letters(32, '0123456789abcdef');

        return vsprintf(
            format: '%s-%s-4%s-%s%s-%s',
            values: [
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 13, 3),
                $this->random->pick(['8', '9', 'a', 'b']),
                substr($hex, 17, 3),
                substr($hex, 20, 12),
            ],
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function validNumber(array $node): int|float
    {
        if (array_key_exists('enum', $node)) {
            return $this->random->pick($node['enum']);
        }
        $candidates = $this->numericCandidates($node);

        return $this->random->pick($candidates);
    }

    /**
     * Every value on the node's grid (its `multipleOf`, else 1 or 0.5) inside its bounds, within a window.
     *
     * @param array<string, mixed> $node
     * @return array<int, int|float>
     */
    private function numericCandidates(array $node, bool $insideBounds = true): array
    {
        $step = $this->step($node);
        // A window around the bounds the node has, 40 steps wide where a side is open.
        $minimum = $node['minimum'] ?? (array_key_exists('maximum', $node) ? $node['maximum'] - 40 * $step : -40);
        $maximum = $node['maximum'] ?? $minimum + 40 * $step;
        $low = (int)floor($minimum / $step) - 2;
        $high = (int)ceil($maximum / $step) + 2;
        $values = [];
        for ($k = $low; $k <= $high; $k++) {
            $value = $this->normalizeNumber($k * $step, $node['t'] === 'integer');
            if (!$insideBounds || self::withinBounds($node, $value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function step(array $node): int|float
    {
        return $node['multipleOf'] ?? ($node['t'] === 'integer' ? 1 : 0.5);
    }

    private function normalizeNumber(int|float $value, bool $integer): int|float
    {
        if ($integer || floor((float)$value) === (float)$value) {
            return (int)$value;
        }

        return (float)$value;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, mixed>
     */
    private function validArray(array $node, ?int $count = null): array
    {
        $prefix = $node['prefix'] ?? [];
        $min = $node['minItems'] ?? 0;
        $max = $node['maxItems'] ?? $min + 3;
        $count ??= $this->random->int($min, $max);

        $items = [];
        $seen = [];
        for ($i = 0; $i < $count; $i++) {
            $itemNode = $prefix[$i] ?? $node['items'];
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $value = $this->valid($itemNode);
                $key = json_encode($value);
                if (!$node['unique'] || !in_array($key, $seen, true)) {
                    break;
                }
            }
            $seen[] = json_encode($value);
            if ($value === null) {
                $this->validTags[] = 'nullItem:' . self::descriptor($itemNode);
            }
            $items[] = $value;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $include property names that must be present
     * @param array<int, string> $exclude property names that must be absent
     */
    private function validObject(array $node, array $include = [], array $exclude = []): stdClass
    {
        $object = new stdClass();
        foreach ($node['props'] as $name => $child) {
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $present = in_array($name, $node['required'], true) || in_array($name, $include, true) || $this->random->chance(0.5);
            if ($present) {
                $object->{$name} = $this->valid($child);
            }
        }
        if (is_array($node['additional']) && $exclude === [] && $this->random->chance(0.3)) {
            $object->stray = $this->valid($node['additional']);
            $this->validTags[] = 'additionalKey';
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function validMap(array $node, ?int $count = null): stdClass
    {
        $min = $node['minProperties'] ?? 0;
        $max = $node['maxProperties'] ?? $min + 2;
        $count ??= $this->random->int($min, $max);
        $map = new stdClass();
        for ($i = 0; $i < $count; $i++) {
            $value = $this->valid($node['values']);
            if ($value === null) {
                $this->validTags[] = 'nullMapValue:' . self::descriptor($node['values']);
            }
            $map->{'k' . chr(ord('a') + $i)} = $value;
        }

        return $map;
    }

    /**
     * What a node is, in the vocabulary the allow-list uses.
     *
     * @param array<string, mixed> $node
     */
    public static function descriptor(array $node): string
    {
        $nullable = ($node['nullable'] ?? false) ? 'nullable-' : '';

        return $nullable . match (true) {
            $node['t'] === 'object' => 'dto',
            $node['t'] === 'discriminated' => 'discriminated',
            $node['t'] === 'union' && in_array('string', array_column(self::memberBranches($node), 't'), true) => 'unionWithString',
            $node['t'] === 'string' && in_array($node['format'] ?? null, ['date', 'date-time'], true) => 'temporal',
            array_key_exists('enum', $node) => 'enum',
            default => $node['t'],
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    public function mutations(array $node): array
    {
        $own = match ($node['t']) {
            'string' => $this->stringMutations($node),
            'integer', 'number' => $this->numberMutations($node),
            'array' => $this->arrayMutations($node),
            'object' => $this->objectMutations($node),
            'map' => $this->mapMutations($node),
            'union' => $this->unionMutations($node),
            'discriminated' => $this->discriminatedMutations($node),
            default => [],
        };
        $site = 'site:' . self::descriptor($node);
        $own = array_map(
            callback: static fn(array $mutation): array => [...$mutation, 'tags' => $mutation['tags'] === [] ? [$site] : $mutation['tags']],
            array: $own,
        );

        $common = [];
        if (!($node['nullable'] ?? false) && $node['t'] !== 'null') {
            $common[] = ['kind' => 'nullForbidden', 'value' => null, 'tags' => [$site]];
        }
        $common = [...$common, ...$this->wrongTypes($node, $site, self::classMemberTags($node))];

        // `{}` where the node also admits a list — the object member of a union beside an `array`
        // member, or a bare `type: array` — is `[]` once a framework decodes it to a PHP array.
        // The other way round, `[]` where the node also admits an object — the list member of a union
        // beside an object member — is the same PHP `[]`, which the Symfony serializer builds into
        // the object member's class.
        $admitted = self::admittedTypes($node);
        $admitsArray = in_array('array', $admitted, true);
        $admitsObject = in_array('object', $admitted, true);
        $tagged = array_map(
            callback: static fn(array $mutation): array => match (true) {
                $admitsArray && $mutation['value'] instanceof stdClass && get_object_vars($mutation['value']) === [] => [...$mutation, 'tags' => [...$mutation['tags'], 'emptyBesideArray']],
                $admitsObject && $mutation['value'] === [] => [...$mutation, 'tags' => [...$mutation['tags'], 'emptyListBesideObject']],
                default => $mutation,
            },
            array: [...$common, ...$own],
        );

        return $this->random->sample($tagged, self::MUTATIONS_PER_NODE);
    }

    /**
     * `unionClassMember` for a union one of whose members is a generated class (an object or a
     * discriminated union): a wrong-typed value there meets the framework's class hydration first, as
     * at a `site:dto`, so the support-matrix rows about a class property speak for it too.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public static function classMemberTags(array $node): array
    {
        if ($node['t'] !== 'union') {
            return [];
        }
        foreach (self::memberBranches($node) as $branch) {
            if (in_array($branch['t'], ['object', 'discriminated'], true)) {
                return ['unionClassMember'];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $extraTags
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function wrongTypes(array $node, string $site, array $extraTags = []): array
    {
        $admitted = self::admittedTypes($node);
        $object = new stdClass();
        $object->zz = 1;
        $pool = ['string' => self::FOREIGN_WORD, 'integer' => 7, 'number' => 2.5, 'boolean' => true, 'array' => [1, 2], 'object' => $object];
        $mutations = [];
        foreach ($this->random->sample(array_keys($pool), 6) as $type) {
            if (in_array($type, $admitted, true) || ($type === 'integer' && in_array('number', $admitted, true))) {
                continue;
            }
            $mutations[] = ['kind' => 'wrongType:' . $type, 'value' => $pool[$type], 'tags' => [$site, ...$extraTags]];
            if (count($mutations) === 2) {
                break;
            }
        }

        return $mutations;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function stringMutations(array $node): array
    {
        if (array_key_exists('enum', $node)) {
            return [['kind' => 'notInEnum', 'value' => self::FOREIGN_WORD, 'tags' => []]];
        }
        if (array_key_exists('format', $node)) {
            $mutations = [[
                'kind' => 'badFormat:' . $node['format'],
                'value' => 'not-a-' . str_replace('-', '', $node['format']),
                'tags' => [],
            ]];
            if ($node['format'] === 'date' || $node['format'] === 'date-time') {
                $mutations[] = [
                    'kind' => 'impossibleDate:' . $node['format'],
                    'value' => $node['format'] === 'date' ? '2026-02-30' : '2026-02-30T10:00:00Z',
                    'tags' => [],
                ];
            }
            if ($node['format'] === 'date-time') {
                $mutations[] = ['kind' => 'looseDateTime', 'value' => '2026-01-02', 'tags' => []];
            }
            $nearMiss = $this->nearMissFormat($node);
            if ($nearMiss !== null) {
                $mutations[] = $nearMiss;
            }

            return $mutations;
        }

        $mutations = [];
        $min = $node['minLength'] ?? 0;
        if ($min > 0) {
            $mutations[] = ['kind' => 'tooShort', 'value' => $this->validString($node, $min - 1), 'tags' => []];
        }
        if (array_key_exists('maxLength', $node)) {
            $mutations[] = ['kind' => 'tooLong', 'value' => $this->validString($node, $node['maxLength'] + 1), 'tags' => []];
        }
        $max = $node['maxLength'] ?? $min + 4;
        if (array_key_exists('pattern', $node) && $max >= 1) {
            $length = $this->random->int(max($min, 1), $max);
            $text = $this->validString($node, $length);
            $position = $this->random->int(0, $length - 1);
            $text[$position] = SchemaGrammar::PATTERNS[$node['pattern']]['outside'];
            $mutations[] = ['kind' => 'patternMismatch', 'value' => $text, 'tags' => []];
        }

        return $mutations;
    }

    /**
     * A string the format refuses that a lenient reader takes: for a date or date-time, one PHP's
     * free-form parser reads (`now`, `@0`, `2020-1-2`, `+1 day`) — and for a date-time one without an
     * offset or with an offset RFC 3339 does not spell, tagged for the rows that document them; for a
     * uuid the hex without hyphens or in braces, for an email an empty local part or a misplaced dot. Tags carry the site
     * themselves, since `mutations()` only adds it to an untagged mutation.
     *
     * @param array<string, mixed> $node
     * @return array{kind: string, value: string, tags: array<int, string>}|null
     */
    private function nearMissFormat(array $node): ?array
    {
        $site = 'site:' . self::descriptor($node);
        $parseable = ['x', 'now', 'tomorrow', '@0', '2020-1-2', '+1 day'];
        $choices = match ($node['format']) {
            // A one-digit month or day is tagged for the row that documents it in symfony, where
            // `createFromFormat('!Y-m-d')` reads it before any generated code sees the string.
            'date' => array_map(static fn(string $value): array => [$value, $value === '2020-1-2' ? ['dateOneDigit'] : []], $parseable),
            'date-time' => [
                ...array_map(static fn(string $value): array => [$value, []], $parseable),
                ['2024-01-02T10:00:00', ['dateTimeNoOffset']],
                ['2024-01-02T10:00:00+0200', ['dateTimeOffsetWithoutColon']],
            ],
            'uuid' => [['123e4567e89b42d3a456426614174000', []], ['{123e4567-e89b-42d3-a456-426614174000}', []]],
            // Not `a@b`: RFC 5321 admits a dotless domain, so refusing it is no requirement.
            'email' => [['@example.com', []], ['a.@example.com', []], ['a..b@example.com', []], ['a@example..com', []]],
            default => [],
        };
        if ($choices === []) {
            return null;
        }
        [$value, $tags] = $this->random->pick($choices);
        $kind = in_array($node['format'], ['date', 'date-time'], true) ? 'parseableNonDate:' : 'nearMissFormat:';

        return ['kind' => $kind . $node['format'], 'value' => $value, 'tags' => [$site, ...$tags]];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function numberMutations(array $node): array
    {
        $grid = $this->numericCandidates(node: $node, insideBounds: false);
        if (array_key_exists('enum', $node)) {
            $outside = array_values(array_filter(
                array: $grid,
                callback: static fn(int|float $value): bool => self::withinBounds($node, $value) && !in_array($value, $node['enum'], true),
            ));

            return $outside === [] ? [] : [['kind' => 'notInEnum', 'value' => $this->random->pick($outside), 'tags' => []]];
        }

        $mutations = [];
        $withoutLower = array_filter($node, static fn(string $key): bool => $key !== 'minimum', ARRAY_FILTER_USE_KEY);
        $withoutUpper = array_filter($node, static fn(string $key): bool => $key !== 'maximum', ARRAY_FILTER_USE_KEY);
        if (array_key_exists('minimum', $node)) {
            // The largest grid value the lower bound refuses — the bound itself when it is exclusive.
            $below = array_filter($grid, static fn(int|float $value): bool => !self::withinBounds($node, $value) && self::withinBounds($withoutLower, $value) && $value <= $node['minimum']);
            if ($below !== []) {
                $mutations[] = ['kind' => $node['exclusiveMinimum'] ? 'atExclusiveMinimum' : 'belowMinimum', 'value' => max($below), 'tags' => []];
            }
        }
        if (array_key_exists('maximum', $node)) {
            $above = array_filter($grid, static fn(int|float $value): bool => !self::withinBounds($node, $value) && self::withinBounds($withoutUpper, $value) && $value >= $node['maximum']);
            if ($above !== []) {
                $mutations[] = ['kind' => $node['exclusiveMaximum'] ? 'atExclusiveMaximum' : 'aboveMaximum', 'value' => min($above), 'tags' => []];
            }
        }
        if (array_key_exists('multipleOf', $node)) {
            $withoutStep = array_filter($node, static fn(string $key): bool => $key !== 'multipleOf', ARRAY_FILTER_USE_KEY);
            $offGrid = [];
            foreach ($this->numericCandidates($node) as $value) {
                foreach ([1, -1] as $direction) {
                    $candidate = $this->normalizeNumber($value + $direction * ($node['t'] === 'integer' ? 1 : 0.125), $node['t'] === 'integer');
                    if (self::withinBounds($withoutStep, $candidate) && !self::withinBounds($node, $candidate)) {
                        $offGrid[] = $candidate;
                    }
                }
            }
            if ($offGrid !== []) {
                $mutations[] = ['kind' => 'notMultipleOf', 'value' => $this->random->pick($offGrid), 'tags' => []];
            }
        }

        return $mutations;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function arrayMutations(array $node): array
    {
        $mutations = [];
        $min = $node['minItems'] ?? 0;
        $max = $node['maxItems'] ?? null;
        $prefixCount = count($node['prefix'] ?? []);
        if ($min > 0) {
            $mutations[] = ['kind' => 'tooFewItems', 'value' => $this->validArray($node, $min - 1), 'tags' => []];
        }
        if ($max !== null) {
            $mutations[] = ['kind' => 'tooManyItems', 'value' => $this->validArray($node, $max + 1), 'tags' => []];
        }
        if ($node['unique'] && ($max ?? 2) >= 2) {
            $list = $this->validArray($node, max(2, $min));
            $list[1] = $list[0];
            $mutations[] = ['kind' => 'duplicateItems', 'value' => $list, 'tags' => []];
        }

        $itemSlots = [];
        $length = max(1, $min, $prefixCount + 1);
        if ($max === null || $length <= $max) {
            $itemSlots[$prefixCount] = $node['items'];
        }
        if ($max === null || max($min, $prefixCount) <= $max) {
            foreach ($node['prefix'] ?? [] as $index => $prefixNode) {
                $itemSlots[$index] = $prefixNode;
            }
        }
        foreach ($itemSlots as $index => $itemNode) {
            $context = $index < $prefixCount ? 'in:prefix' : 'in:items';
            foreach ($this->random->sample($this->mutations($itemNode), 3) as $inner) {
                $count = $index < $prefixCount ? max($min, $prefixCount) : max($min, $index + 1);
                $list = $this->validArray($node, $count);
                $list[$index] = $inner['value'];
                $mutations[] = [
                    'kind' => $inner['kind'],
                    'value' => $list,
                    'tags' => [$context, 'itemsOf:' . self::descriptor($itemNode), ...$inner['tags']],
                ];
            }
        }

        return $mutations;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function objectMutations(array $node): array
    {
        $mutations = [];
        foreach ($node['required'] as $name) {
            $mutations[] = ['kind' => 'missingRequired', 'value' => $this->validObject(node: $node, exclude: [$name]), 'tags' => []];
        }
        if ($node['additional'] === false) {
            $object = $this->validObject($node);
            $object->stray = 'x';
            $mutations[] = ['kind' => 'extraKeyClosed', 'value' => $object, 'tags' => []];
        }
        if (is_array($node['additional'])) {
            foreach ($this->random->sample($this->mutations($node['additional']), 2) as $inner) {
                $object = $this->validObject(node: $node, exclude: ['stray']);
                $object->stray = $inner['value'];
                $mutations[] = ['kind' => $inner['kind'], 'value' => $object, 'tags' => ['in:additional', ...$inner['tags']]];
            }
        }
        foreach ($this->random->sample(array_keys($node['props']), 3) as $name) {
            foreach ($this->random->sample($this->mutations($node['props'][$name]), 3) as $inner) {
                $object = $this->validObject(node: $node, include: [$name]);
                $object->{$name} = $inner['value'];
                $mutations[] = ['kind' => $inner['kind'], 'value' => $object, 'tags' => ['in:prop', ...$inner['tags']]];
            }
        }

        return $mutations;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function mapMutations(array $node): array
    {
        $mutations = [];
        $min = $node['minProperties'] ?? 0;
        if ($min > 0) {
            $mutations[] = ['kind' => 'tooFewProperties', 'value' => $this->validMap($node, $min - 1), 'tags' => []];
        }
        if (array_key_exists('maxProperties', $node)) {
            $mutations[] = ['kind' => 'tooManyProperties', 'value' => $this->validMap($node, $node['maxProperties'] + 1), 'tags' => []];
        }
        $count = max(1, $min);
        if (!array_key_exists('maxProperties', $node) || $count <= $node['maxProperties']) {
            foreach ($this->random->sample($this->mutations($node['values']), 3) as $inner) {
                $map = $this->validMap($node, $count);
                $map->ka = $inner['value'];
                $mutations[] = ['kind' => $inner['kind'], 'value' => $map, 'tags' => ['in:map', 'valuesOf:' . self::descriptor($node['values']), ...$inner['tags']]];
            }
        }

        return $mutations;
    }

    /**
     * A branch's mutation is kept only when its value's JSON type belongs to that branch alone: then no
     * sibling can match it, and "invalid" holds for `oneOf` and `anyOf` alike.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function unionMutations(array $node): array
    {
        $members = self::memberBranches($node);
        $withString = in_array('string', array_map(static fn(array $branch): string => $branch['t'], $members), true);
        // A date beside an array-typed branch — a list, an object, a map: yii3 cannot keep an integer
        // sibling from reading as a timestamp.
        $descriptors = array_map(self::descriptor(...), $members);
        $withTemporal = in_array('temporal', $descriptors, true);
        $temporalBesideArray = $withTemporal && array_intersect(['array', 'dto', 'map'], $descriptors) !== [];
        // … nor beside `null`, whether a `type: null` member, a nullable member or the union itself.
        $temporalBesideNull = $withTemporal && (
            ($node['nullable'] ?? false)
            || in_array('null', array_column($members, 't'), true)
            || in_array(true, array_map(static fn(array $branch): bool => $branch['nullable'] ?? false, $members), true)
        );
        $classMember = self::classMemberTags($node);
        // Two object members share the JSON type, so a broken object of one could be a valid one of the
        // other. Its mutations are kept all the same, tagged; `FuzzSpec` drops the ones the reference
        // oracle finds the union still accepts.
        $objectPair = ($node['objectPair'] ?? false) === true;
        $mutations = [];
        foreach ($node['branches'] as $index => $branch) {
            $others = [];
            foreach ($node['branches'] as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    $others = [...$others, ...self::admittedTypes($other)];
                }
            }
            if ($node['nullable'] ?? false) {
                $others[] = 'null';
            }
            foreach ($this->mutations($branch) as $inner) {
                $type = self::jsonType($inner['value']);
                $sharedObject = $objectPair && $type === 'object' && $branch['t'] === 'object';
                if (!in_array($type, self::admittedTypes($branch), true) || (in_array($type, $others, true) && !$sharedObject)) {
                    continue;
                }
                // A scalar sent to a union with a class member meets the framework's class hydration
                // first, whichever constraint of its own branch it breaks (`notInEnum`, `tooLong`, …).
                $scalar = !in_array($type, ['object', 'array', 'null'], true);
                $tags = [
                    'in:union',
                    ...($withString ? ['unionWithString'] : []),
                    ...($temporalBesideArray ? ['unionTemporalBesideArray'] : []),
                    ...($temporalBesideNull ? ['unionTemporalBesideNull'] : []),
                    ...($withTemporal && $type === 'integer' ? ['integerBesideTemporal'] : []),
                    ...($scalar ? $classMember : []),
                    ...($objectPair ? ['unionObjectPair'] : []),
                    ...(self::isUndiscriminatedComponent($node) ? ['undiscriminatedComponent'] : []),
                    ...$inner['tags'],
                ];
                $mutations[] = ['kind' => $inner['kind'], 'value' => $inner['value'], 'tags' => $tags];
            }
        }

        return $mutations;
    }

    /**
     * Every mutation of a branch keeps that branch's `kind` (or breaks `kind` itself), so it cannot
     * match a sibling, whose `kind` enum is a different single tag.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{kind: string, value: mixed, tags: array<int, string>}>
     */
    private function discriminatedMutations(array $node): array
    {
        $placement = $node['asComponent'] ? 'componentDiscriminator' : 'inlineDiscriminator';
        $mutations = [];
        foreach ($node['branches'] as $branch) {
            foreach ($this->random->sample($this->objectMutations($branch['object']), 3) as $inner) {
                $mutations[] = ['kind' => $inner['kind'], 'value' => $inner['value'], 'tags' => ['in:discriminated', $placement, ...$inner['tags']]];
            }
        }
        $object = $this->validObject($this->random->pick($node['branches'])['object']);
        $object->{SchemaGrammar::DISCRIMINATOR_PROPERTY} = self::FOREIGN_WORD;
        $mutations[] = ['kind' => 'unknownDiscriminator', 'value' => $object, 'tags' => [$placement]];

        return $mutations;
    }
}
