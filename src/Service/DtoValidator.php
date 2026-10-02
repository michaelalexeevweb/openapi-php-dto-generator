<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use stdClass;
use Symfony\Component\HttpFoundation\File\File;

final class DtoValidator implements DtoValidatorInterface
{
    private const int MAX_VALIDATION_DEPTH = 256;

    /** ECMA-262 ASCII escapes outside a character class. */
    private const array ASCII_ATOM_ESCAPES = [
        'd' => '[0-9]',
        'D' => '[^0-9]',
        'w' => '[A-Za-z0-9_]',
        'W' => '[^A-Za-z0-9_]',
        'b' => '(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]))',
        'B' => '(?:(?<=[A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<![A-Za-z0-9_])(?![A-Za-z0-9_]))',
    ];

    /** The same inside a class, as ranges; `\b` there is a backspace in both dialects. */
    private const array ASCII_CLASS_ESCAPES = [
        'd' => '0-9',
        'D' => '\x{0}-\x{2F}\x{3A}-\x{10FFFF}',
        'w' => 'A-Za-z0-9_',
        'W' => '\x{0}-\x{2F}\x{3A}-\x{40}\x{5B}-\x{5E}\x{60}\x{7B}-\x{10FFFF}',
    ];

    /**
     * Delimit a schema pattern without escaping an already escaped delimiter twice, and keep the
     * ECMA-262 ASCII meaning of `\d` `\w` `\b` under the Unicode modifier.
     */
    public static function delimitPattern(string $pattern, bool $unicode = true): string
    {
        $delimited = '#';
        $length = strlen($pattern);
        $inClass = false;
        $classOpenedAt = -1;
        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];
            if ($character === '\\' && $i + 1 < $length) {
                $next = $pattern[$i + 1];
                // ECMA-262 `\uXXXX` and `\u{X…}` are PCRE's `\x{…}`; PCRE has no `\u` at all.
                if ($next === 'u' && preg_match('/\G(?:\{([0-9A-Fa-f]{1,6})\}|([0-9A-Fa-f]{4}))/', $pattern, $hex, 0, $i + 2) === 1) {
                    $delimited .= '\x{' . ($hex[1] !== '' ? $hex[1] : $hex[2]) . '}';
                    $i += 1 + strlen($hex[0]);
                    continue;
                }
                $i++;
                // ECMA-262 `\v` is the one vertical TAB; PCRE's is a class that also holds `\n` and `\r`.
                if ($next === 'v') {
                    $delimited .= '\x{B}';
                    continue;
                }
                // ECMA-262 `\d` `\w` `\b` are ASCII, even under its `u` flag. PHP's `u` also turns on
                // Unicode properties, where `\d` matches `٣` and `\w` matches `ж`: spell them out.
                $ascii = $unicode ? ($inClass ? self::ASCII_CLASS_ESCAPES : self::ASCII_ATOM_ESCAPES)[$next] ?? null : null;
                $delimited .= $ascii ?? '\\' . $next;
                continue;
            }
            if ($inClass && $character === '[' && ($pattern[$i + 1] ?? '') === ':') {
                // A POSIX class carries its own `]`; copied whole, it cannot close the outer class.
                $end = strpos($pattern, ':]', $i + 2);
                if ($end !== false) {
                    $delimited .= substr($pattern, $i, $end + 2 - $i);
                    $i = $end + 1;
                    continue;
                }
            }
            if (!$inClass && $character === '[') {
                $inClass = true;
                $classOpenedAt = ($pattern[$i + 1] ?? '') === '^' ? $i + 1 : $i;
            } elseif ($inClass && $character === ']' && $i > $classOpenedAt + 1) {
                // PCRE reads a `]` right after `[` or `[^` as a literal member, not as the end.
                $inClass = false;
            }
            $delimited .= $character === '#' ? '\#' : $character;
        }

        return $delimited . ($unicode ? '#u' : '#');
    }

    /**
     * One shape for every message this package writes: a full stop at the end, and a capital at the
     * start unless the sentence begins with a name the document chose.
     *
     * It lives on the validator because the validator is what writes these sentences; the normalizer
     * and the deserializer, which both already default to an instance of this class, call it for the
     * messages they build themselves so there is ONE spelling of the rule rather than three.
     *
     * The two halves are not a style preference, they are what the messages already are:
     *
     *     Required parameter "value.id" not found in request.   a sentence with its own subject
     *     param "platforms" expects array, got string.          a SUBJECT LABEL plus a predicate
     *
     * The second kind is shared by three modes and spelled differently on purpose — `param "f"` in
     * runtime mode, `field "f"` in Symfony mode, and in Laravel mode the bare property path, because
     * Laravel keys its error bag by that path and the message sits beside the key. Capitalising there
     * would rewrite an identifier the document owns: `children.leaves.title` is not
     * `Children.leaves.title`. So the subject is left as its owner spelled it, and only a sentence
     * opening with an English word is capitalised.
     *
     * The full stop is unconditional, because these are read as a list — `DtoNormalizer` joins them
     * with `', '` behind `DTO validation failed:` — and half of them ending in one reads as a defect.
     *
     * Idempotent: what has been through already may go through again, which is what lets each class
     * finalise at its own exit without minding what another one did.
     *
     * @param array<int, string> $messages
     * @param string|null $subject the subject label these messages may begin with, when the caller
     *        knows it; a message starting with it keeps its spelling
     * @return array<int, string>
     */
    public static function finalizeMessages(array $messages, ?string $subject = null): array
    {
        return array_map(
            static fn(string $message): string => self::finalizeMessage($message, $subject),
            $messages,
        );
    }

    public static function finalizeMessage(string $message, ?string $subject = null): string
    {
        $message = rtrim($message);
        if ($message === '') {
            return $message;
        }

        // A message that opens with the subject opens with a name, not a word.
        $startsWithSubject = $subject !== null && $subject !== '' && str_starts_with($message, $subject);
        if (!$startsWithSubject) {
            $message = ucfirst($message);
        }

        return str_ends_with($message, '.') ? $message : $message . '.';
    }

    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    public function validate(string $subject, mixed $value, array $constraints): array
    {
        // The one exit: every message this class writes is finalised here rather than at the eighty
        // places that build one. `$subject` is passed on so a message that OPENS with it keeps the
        // name as its owner spelled it — see `finalizeMessages()`.
        return self::finalizeMessages(
            $this->validateConstraints($subject, $value, $constraints, 0),
            $subject,
        );
    }

    /**
     * Keys whose value is a SUBSCHEMA, and may therefore be the boolean form of one.
     *
     * JSON Schema lets `true` and `false` stand where an object schema stands: `true` accepts every
     * value, `false` accepts none. `additionalProperties` and `unevaluated*` already read the boolean
     * directly — for them it is the ordinary spelling — so they are deliberately absent here.
     */
    private const array BOOLEAN_SUBSCHEMA_KEYS = [
        'items',
        'contains',
        'not',
        'propertyNames',
        'if',
        'then',
        'else',
        'contentSchema',
    ];

    /** Keys holding a MAP of subschemas, any of which may be boolean. */
    private const array BOOLEAN_SUBSCHEMA_MAP_KEYS = [
        'properties',
        'patternProperties',
        'dependentSchemas',
    ];

    /** Keys holding a LIST of subschemas, any of which may be boolean. */
    private const array BOOLEAN_SUBSCHEMA_LIST_KEYS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /**
     * Rewrites boolean subschemas into the object schemas they are shorthand for.
     *
     * `true` is the empty schema — it constrains nothing — and `false` is its opposite, which nothing
     * satisfies. That second one has no direct spelling as an array, but `not` of the empty schema is
     * exactly it: the inner schema accepts the value, so `not` rejects it, whatever the value is.
     *
     * Doing it here, at the one entry every level passes through, is what keeps this from becoming
     * ten separate fixes: the boolean was previously dropped by every reader that tested
     * `is_array()` first, so `items: false` did not close a `prefixItems` tuple, `properties: {x:
     * false}` did not forbid `x`, and `anyOf: [false, true]` refused a value the `true` branch
     * accepts. One rewrite, and every reader below sees a shape it already understands.
     *
     * The scan is shallow — recursion brings each nested level back through here — and it rebuilds
     * nothing when the schema holds no boolean subschema, which is the ordinary case.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private static function expandBooleanSubschemas(array $constraints): array
    {
        foreach (self::BOOLEAN_SUBSCHEMA_KEYS as $key) {
            $subschema = $constraints[$key] ?? null;
            if (is_bool($subschema)) {
                $constraints[$key] = self::booleanSchemaAsArray($subschema);
            }
        }

        foreach (self::BOOLEAN_SUBSCHEMA_MAP_KEYS as $key) {
            $group = $constraints[$key] ?? null;
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $name => $subschema) {
                if (is_bool($subschema)) {
                    $constraints[$key][$name] = self::booleanSchemaAsArray($subschema);
                }
            }
        }

        foreach (self::BOOLEAN_SUBSCHEMA_LIST_KEYS as $key) {
            $group = $constraints[$key] ?? null;
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $index => $subschema) {
                if (is_bool($subschema)) {
                    $constraints[$key][$index] = self::booleanSchemaAsArray($subschema);
                }
            }
        }

        return $constraints;
    }

    /**
     * @return array<string, mixed>
     */
    private static function booleanSchemaAsArray(bool $schema): array
    {
        return $schema ? [] : ['not' => []];
    }

    /**
     * Recursion depth is threaded as a parameter (not stored on the instance) so a single
     * shared validator is safe under concurrency (Swoole/RoadRunner/FrankenPHP coroutines).
     *
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateConstraints(
        string $subject,
        mixed $value,
        array $constraints,
        int $depth,
        ?bool $hasComposition = null,
        bool $propertyRulesOwnedByDto = true,
    ): array {
        // Guard against pathologically nested schemas exhausting the stack.
        if ($depth >= self::MAX_VALIDATION_DEPTH) {
            return [sprintf('%s: schema nesting exceeds %d levels', $subject, self::MAX_VALIDATION_DEPTH)];
        }

        if ($constraints === []) {
            return [];
        }

        $constraints = self::expandBooleanSubschemas($constraints);

        // Guard inlined: the helper returns the value untouched for anything that is not a date,
        // and paying a method call per value to learn that is the common case.
        if ($value instanceof DateTimeInterface) {
            $value = $this->normalizeTemporalValueForValidation($value, $constraints);
        }

        $errors = [];

        // Computed once: the sections below ask these four times between them.
        $isNumeric = is_int($value) || is_float($value);
        $isString = is_string($value);
        $isArray = is_array($value);

        // Composition keywords, behind one gate. `$hasComposition` lets a caller that already knows
        // the answer skip seven key probes — `validateArray()` computes it ONCE for an item schema
        // it is about to apply N times. Null means "work it out", which is what every other caller
        // passes, so the default path is unchanged.
        $hasComposition ??= array_key_exists('allOf', $constraints)
            || array_key_exists('oneOf', $constraints)
            || array_key_exists('anyOf', $constraints)
            || array_key_exists('enum', $constraints)
            || array_key_exists('const', $constraints)
            || array_key_exists('not', $constraints)
            || array_key_exists('if', $constraints);

        if ($hasComposition) {
            // allOf: every branch must pass; errors from all failing branches are collected.
            if (array_key_exists('allOf', $constraints) && is_array($constraints['allOf'])) {
                foreach ($constraints['allOf'] as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }
                    array_push($errors, ...$this->validateConstraints($subject, $value, $branch, $depth + 1));
                }
            }

            if (array_key_exists('oneOf', $constraints) && is_array($constraints['oneOf'])) {
                $errors = [...$errors, ...$this->validateUnionBranches(
                    subject: $subject,
                    value: $value,
                    branches: $constraints['oneOf'],
                    isOneOf: true,
                    depth: $depth,
                )];
            }

            if (array_key_exists('anyOf', $constraints) && is_array($constraints['anyOf'])) {
                $errors = [...$errors, ...$this->validateUnionBranches(
                    subject: $subject,
                    value: $value,
                    branches: $constraints['anyOf'],
                    isOneOf: false,
                    depth: $depth,
                )];
            }

            // enum: compare JSON values without coercing strings or booleans.
            if (array_key_exists('enum', $constraints) && is_array($constraints['enum'])) {
                // A backed enum getter returns the enum object; the schema's enum list holds raw
                // scalars. Compare by ->value so a matching backed enum is not falsely rejected.
                $fingerprint = $this->jsonValueFingerprint($value);
                $matches = false;
                if ($fingerprint !== null) {
                    foreach ($constraints['enum'] as $candidate) {
                        if ($fingerprint === $this->jsonValueFingerprint($candidate)) {
                            $matches = true;
                            break;
                        }
                    }
                }
                if ($fingerprint === null) {
                    $errors[] = "{$subject}: JSON equality traversal exceeds its limits";
                } elseif (!$matches) {
                    $allowed = implode(', ', array_map(
                        static function (mixed $v): string {
                            $json = json_encode($v);
                            return $json !== false ? $json : var_export($v, true);
                        },
                        $constraints['enum'],
                    ));
                    $errors[] = "{$subject} must be one of: {$allowed}";
                }
            }

            // const: compare the complete JSON value, including nested containers.
            if (array_key_exists('const', $constraints)) {
                $fingerprint = $this->jsonValueFingerprint($value);
                if ($fingerprint === null) {
                    $errors[] = "{$subject}: JSON equality traversal exceeds its limits";
                } elseif ($fingerprint !== $this->jsonValueFingerprint($constraints['const'])) {
                    $constJson = json_encode($constraints['const']);
                    $errors[] = sprintf(
                        '%s must equal %s',
                        $subject,
                        $constJson !== false ? $constJson : var_export($constraints['const'], true),
                    );
                }
            }

            // not: value must NOT satisfy the given schema.
            if (array_key_exists('not', $constraints) && is_array($constraints['not'])) {
                $notErrors = $this->validateConstraints($subject, $value, $constraints['not'], $depth + 1);
                // A branch that could not be checked to the end did not "fail to match": fail closed.
                $errors = [...$errors, ...$this->incompleteVerdict($notErrors)];
                if ($notErrors === []) {
                    // `not: {}` is how {@see booleanSchemaAsArray()} spells the schema `false`, and a
                    // document that wrote `false` never mentioned `not` — so the sentence must not
                    // either. It reads as the refusal it is: nothing is allowed here.
                    $errors[] = $constraints['not'] === []
                        ? "{$subject} is not allowed by the schema"
                        : "{$subject} must not match the 'not' schema";
                }
            }

            // if/then/else: conditional schema application.
            if (array_key_exists('if', $constraints) && is_array($constraints['if'])) {
                $ifErrors = $this->validateConstraints($subject, $value, $constraints['if'], $depth + 1);
                $incompleteIf = $this->incompleteVerdict($ifErrors);
                if ($incompleteIf !== []) {
                    $errors = [...$errors, ...$incompleteIf];
                } elseif ($ifErrors === []) {
                    if (array_key_exists('then', $constraints) && is_array($constraints['then'])) {
                        $errors = [...$errors, ...$this->validateConstraints($subject, $value, $constraints['then'], $depth + 1, propertyRulesOwnedByDto: false)];
                    }
                } else {
                    if (array_key_exists('else', $constraints) && is_array($constraints['else'])) {
                        $errors = [...$errors, ...$this->validateConstraints($subject, $value, $constraints['else'], $depth + 1, propertyRulesOwnedByDto: false)];
                    }
                }
            }
        }

        // type: value must match the declared OpenAPI type.
        if (array_key_exists('type', $constraints) && !($value === null && ($constraints['nullable'] ?? false) === true)) {
            $typeConstraint = $constraints['type'];
            if (is_string($typeConstraint)) {
                if (!$this->matchesOpenApiType(value: $value, type: $typeConstraint)) {
                    // Clarify the common confusion: an associative PHP array is a JSON object,
                    // not a JSON array (list), even though is_array() is true for both.
                    $errors[] = $typeConstraint === 'array' && is_array($value)
                        ? "{$subject} must be a JSON array (list with sequential keys), got an associative array"
                        : "{$subject} must be of type {$typeConstraint}";
                }
            } elseif (is_array($typeConstraint)) {
                // OpenAPI 3.1: type: [string, null] — value must match at least one listed type
                $typeMatched = false;
                foreach ($typeConstraint as $t) {
                    if (is_string($t) && $this->matchesOpenApiType(value: $value, type: $t)) {
                        $typeMatched = true;
                        break;
                    }
                }
                if (!$typeMatched) {
                    $errors[] = sprintf(
                        '%s must be of type %s',
                        $subject,
                        implode('|', array_filter($typeConstraint, 'is_string')),
                    );
                }
            }
        }

        if (
            $isNumeric && (array_key_exists('minimum', $constraints)
                || array_key_exists('maximum', $constraints)
                || array_key_exists('exclusiveMinimum', $constraints)
                || array_key_exists('exclusiveMaximum', $constraints)
                || array_key_exists('multipleOf', $constraints))
        ) {
            // Passed WITHOUT a float cast: an integer beyond 2^53 loses its identity as a float, so
            // `maximum: 9007199254740992` accepted `9007199254740993` — the two are one float. The
            // comparisons below stay exact while both sides are integers.
            $errors = [...$errors, ...$this->validateNumeric(
                subject: $subject,
                value: $value,
                constraints: $constraints,
            )];
        }

        if ($isNumeric && is_string($constraints['format'] ?? null)) {
            $errors = [...$errors, ...$this->validateNumericFormat(subject: $subject, value: $value, format: $constraints['format'])];
        }

        if (
            $isString && (array_key_exists('minLength', $constraints)
                || array_key_exists('maxLength', $constraints)
                || array_key_exists('pattern', $constraints)
                || array_key_exists('format', $constraints))
        ) {
            $errors = [...$errors, ...$this->validateString(subject: $subject, value: $value, constraints: $constraints)];
        }

        if (
            $isString && (array_key_exists('contentEncoding', $constraints)
                || array_key_exists('contentMediaType', $constraints)
                || array_key_exists('contentSchema', $constraints))
        ) {
            $errors = [...$errors, ...$this->validateContent(subject: $subject, value: $value, constraints: $constraints, depth: $depth)];
        }

        if (
            $isArray && (array_key_exists('minItems', $constraints)
                || array_key_exists('maxItems', $constraints)
                || array_key_exists('uniqueItems', $constraints)
                || array_key_exists('items', $constraints)
                || array_key_exists('contains', $constraints)
                || array_key_exists('minContains', $constraints)
                || array_key_exists('maxContains', $constraints)
                || array_key_exists('prefixItems', $constraints)
                || array_key_exists('unevaluatedItems', $constraints))
        ) {
            $errors = [...$errors, ...$this->validateArray(subject: $subject, value: $value, constraints: $constraints, depth: $depth)];
        }

        // The value gate comes FIRST, unlike the keyword probe it guards: the section can only do
        // something for an array or a generated DTO (`generatedDtoPayload()` returns null for
        // anything else), so a scalar paid ten key lookups and a method call to learn nothing.
        if (
            ($isArray || is_object($value))
                && (array_key_exists('minProperties', $constraints)
                || array_key_exists('maxProperties', $constraints)
                || array_key_exists('properties', $constraints)
                || array_key_exists('additionalProperties', $constraints)
                || array_key_exists('required', $constraints)
                || array_key_exists('dependentRequired', $constraints)
                || array_key_exists('dependentSchemas', $constraints)
                || array_key_exists('patternProperties', $constraints)
                || array_key_exists('propertyNames', $constraints)
                || array_key_exists('unevaluatedProperties', $constraints))
        ) {
            // A generated DTO carries object data too, so cross-field keywords (dependentRequired,
            // dependentSchemas, propertyNames, …) must see its payload instead of being skipped
            // because the value happens to be an object rather than an array.
            $isGeneratedDto = $value instanceof GeneratedDtoInterface && !$value instanceof BackedEnum;
            $objectValue = $this->objectPayloadForConstraints($value);
            if ($objectValue !== null) {
                $errors = [...$errors, ...$this->validateObjectConstraints(
                    subject: $subject,
                    value: $objectValue,
                    // `properties` is owned by the DTO itself: it validates its own fields against
                    // its own constraints, so re-checking them here would duplicate every message —
                    // but the declared NAMES must stay visible, see the helper. A PLAIN object owns
                    // nothing: it is the `stdClass` `json_decode()` produced two containers down,
                    // where no class was generated, so its `properties` are checked right here or
                    // nowhere at all.
                    // …but only for the constraints the DTO's own schema HOLDS. A conditional branch is
                    // not among them: `then`, `else` and a matching `dependentSchemas` entry are rules the
                    // parent schema applies ON TOP of the class, and the class has never heard of them, so
                    // stripping them here checked nothing at all. Measured: `then: {properties: {code:
                    // {minLength: 7}}}` accepted `"short"` — while `then: {required: [code]}`, which this
                    // helper leaves alone, was enforced all along.
                    constraints: $isGeneratedDto && $propertyRulesOwnedByDto
                        ? $this->withPropertyRulesOwnedByTheDto($constraints)
                        : $constraints,
                    depth: $depth,
                )];
            }
        }

        if (
            array_key_exists('format', $constraints) && $constraints['format'] === 'binary' && !is_string(
                $value,
            ) && !$value instanceof File
        ) {
            $errors[] = "{$subject} expects binary data, got {$this->typeToOpenApi($value)}";
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function normalizeTemporalValueForValidation(mixed $value, array $constraints): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $format = $constraints['format'] ?? null;
        if (!is_string($format)) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return match ($format) {
            'date' => $value->format('Y-m-d'),
            default => $value->format(DateTimeInterface::ATOM),
        };
    }

    /**
     * @param array<int, mixed> $branches
     * @return array<string>
     */
    private function validateUnionBranches(string $subject, mixed $value, array $branches, bool $isOneOf, int $depth): array
    {
        $validBranches = 0;
        $errors = [];

        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            // Type-gate: a branch whose declared type can't match the value would fail its
            // own type check anyway, so skip it. Branches without a type are always evaluated.
            if (!$this->matchesOpenApiType(value: $value, type: $branch['type'] ?? null)) {
                continue;
            }

            $branchErrors = $this->validateConstraints($subject, $value, $branch, $depth + 1);
            // `oneOf` counts matches, and a branch that could not be finished may have been one.
            if ($isOneOf && $this->incompleteVerdict($branchErrors) !== []) {
                return $this->incompleteVerdict($branchErrors);
            }
            if ($branchErrors === []) {
                $validBranches++;
                if (!$isOneOf) {
                    return [];
                }
                continue;
            }

            array_push($errors, ...$branchErrors);
        }

        if ($isOneOf) {
            if ($validBranches === 1) {
                return [];
            }

            if ($validBranches > 1) {
                return [
                    "{$subject} matches more than one allowed oneOf branch",
                ];
            }
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        $kind = $isOneOf ? 'oneOf' : 'anyOf';

        // Every branch was gated out by its type, so there is no branch reason to report. Naming the
        // types the union does accept turns an unactionable sentence into one the caller can act on.
        $expected = $this->describeUnionBranchTypes($branches);

        return [
            $expected === null
                ? "{$subject} does not match any {$kind} branch"
                : sprintf(
                    '%s does not match any %s branch (expected %s, got %s)',
                    $subject,
                    $kind,
                    $expected,
                    $this->describeOpenApiTypeOfValue($value),
                ),
        ];
    }

    /**
     * The types a union declares, in document order and without repeats — `integer or string`. Null when
     * no branch declares one, in which case there is nothing to name.
     *
     * @param array<int, mixed> $branches
     */
    private function describeUnionBranchTypes(array $branches): ?string
    {
        $types = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            /** @var array<int, mixed> $declared */
            $declared = is_array($branch['type'] ?? null) ? $branch['type'] : [$branch['type'] ?? null];
            foreach ($declared as $type) {
                if (is_string($type) && $type !== '' && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }

        if ($types === []) {
            return null;
        }

        if (count($types) === 1) {
            return $types[0];
        }

        $last = array_pop($types);

        return implode(', ', $types) . ' or ' . $last;
    }

    /**
     * The value's type as OpenAPI names it, so both halves of the message speak one vocabulary.
     */
    private function describeOpenApiTypeOfValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'object',
        };
    }

    private function matchesOpenApiType(mixed $value, mixed $type): bool
    {
        // OpenAPI 3.1: type may be a list (e.g. [string, null]) — match any listed type.
        // An empty list / non-string places no type constraint.
        if (is_array($type)) {
            if ($type === []) {
                return true;
            }

            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $this->matchesOpenApiType($value, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($type) || $type === '') {
            return true;
        }

        return match ($type) {
            // JSON Schema 2020-12 §6.1.1: "integer" matches any NUMBER with a zero fractional part, so
            // `42.0` in a payload is an integer. PHP decodes it to a float, which is why the naive
            // `is_int()` rejected a value the spec calls valid.
            'integer' => is_int($value)
                || (is_float($value) && is_finite($value) && floor($value) === $value)
                || ($value instanceof BackedEnum && is_int($value->value)),
            'number' => is_int($value) || is_float($value) || ($value instanceof BackedEnum && is_int($value->value)),
            'string' => is_string($value) || ($value instanceof BackedEnum && is_string($value->value)),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            // A map (type: object + additionalProperties) is held as a PHP array. When its keys are
            // dense integers (0, 1, 2, …) the array is a list, yet it still represents a JSON object
            // — PHP cannot tell the two apart — so any array satisfies `object`. The wire shape IS
            // checked, where it is still knowable: DtoDeserializer refuses a JSON array for a
            // `type: object` property before it ever becomes a PHP value.
            'object' => is_array($value) || is_object($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateNumeric(string $subject, int|float $value, array $constraints): array
    {
        $errors = [];

        // The RAW bound is kept beside the float one: when it and the value are both integers the
        // comparison is done on integers, which is the only way `9007199254740993` can be told from
        // `9007199254740992`. Error messages preserve integer bounds too.
        $rawMinimum = $constraints['minimum'] ?? null;
        $rawMaximum = $constraints['maximum'] ?? null;
        $minimum = is_int($rawMinimum) ? $rawMinimum : $this->toFloatOrNull($rawMinimum);
        $maximum = is_int($rawMaximum) ? $rawMaximum : $this->toFloatOrNull($rawMaximum);
        $atLeast = static fn(int|float $bound): bool => is_int($value) && is_int($bound)
            ? $value >= $bound
            : (float)$value >= (float)$bound;
        $atMost = static fn(int|float $bound): bool => is_int($value) && is_int($bound)
            ? $value <= $bound
            : (float)$value <= (float)$bound;
        $above = static fn(int|float $bound): bool => is_int($value) && is_int($bound)
            ? $value > $bound
            : (float)$value > (float)$bound;
        $below = static fn(int|float $bound): bool => is_int($value) && is_int($bound)
            ? $value < $bound
            : (float)$value < (float)$bound;

        $exclusiveMinimum = $constraints['exclusiveMinimum'] ?? null;
        if (is_numeric($exclusiveMinimum)) {
            $minExclusive = is_int($exclusiveMinimum) ? $exclusiveMinimum : (float)$exclusiveMinimum;
            if (!$above(is_int($exclusiveMinimum) ? $exclusiveMinimum : $minExclusive)) {
                $errors[] = "{$subject} must be greater than {$this->stringifyNumber($minExclusive)}";
            }
        }
        if ($minimum !== null) {
            if (($constraints['exclusiveMinimum'] ?? null) === true) {
                if (!$above(is_int($rawMinimum) ? $rawMinimum : $minimum)) {
                    $errors[] = "{$subject} must be greater than {$this->stringifyNumber($minimum)}";
                }
            } elseif (!$atLeast(is_int($rawMinimum) ? $rawMinimum : $minimum)) {
                $errors[] = "{$subject} must be greater than or equal to {$this->stringifyNumber($minimum)}";
            }
        }

        $exclusiveMaximum = $constraints['exclusiveMaximum'] ?? null;
        if (is_numeric($exclusiveMaximum)) {
            $maxExclusive = is_int($exclusiveMaximum) ? $exclusiveMaximum : (float)$exclusiveMaximum;
            if (!$below(is_int($exclusiveMaximum) ? $exclusiveMaximum : $maxExclusive)) {
                $errors[] = "{$subject} must be less than {$this->stringifyNumber($maxExclusive)}";
            }
        }
        if ($maximum !== null) {
            if (($constraints['exclusiveMaximum'] ?? null) === true) {
                if (!$below(is_int($rawMaximum) ? $rawMaximum : $maximum)) {
                    $errors[] = "{$subject} must be less than {$this->stringifyNumber($maximum)}";
                }
            } elseif (!$atMost(is_int($rawMaximum) ? $rawMaximum : $maximum)) {
                $errors[] = "{$subject} must be less than or equal to {$this->stringifyNumber($maximum)}";
            }
        }

        $rawMultipleOf = $constraints['multipleOf'] ?? null;
        $multipleOf = $this->toFloatOrNull($rawMultipleOf);
        if (is_int($value) && is_int($rawMultipleOf) && $rawMultipleOf > 0) {
            // Two integers: exact. Through floats 9007199254740993 was "not a multiple of 3".
            if ($value % $rawMultipleOf !== 0) {
                $errors[] = "{$subject} must be a multiple of {$rawMultipleOf}";
            }
        } elseif ($multipleOf !== null && $multipleOf > 0.0 && !self::isDecimalMultiple($value, is_int($rawMultipleOf) ? $rawMultipleOf : $multipleOf)) {
            // Not a decimal multiple — but a float COMPUTED in PHP (`3 * 1e-8`) is off by one ulp,
            // so a whole ratio within 1e-9 still counts. Only a NON-ZERO one: a tolerance around zero
            // is what said `1e-12` is a multiple of `0.1`.
            $ratio = $value / $multipleOf;
            if (round($ratio) === 0.0 || abs($ratio - round($ratio)) > 1e-9) {
                $errors[] = "{$subject} must be a multiple of {$this->stringifyNumber($multipleOf)}";
            }
        }

        return $errors;
    }

    /**
     * Enforces the integer range implied by OpenAPI numeric formats. `int32`/`int64`
     * must hold whole numbers inside their respective signed ranges; a value that
     * overflows int32 (or is fractional) is rejected. `float`/`double` map to PHP's
     * native double and carry no extra bound, so they are accepted as-is.
     *
     * @return array<string>
     */
    private function validateNumericFormat(string $subject, int|float $value, string $format): array
    {
        return match ($format) {
            'int32' => $this->validateIntegerFormat($subject, $value, -2147483648, 2147483647, 'int32'),
            'int64' => $this->validateIntegerFormat($subject, $value, PHP_INT_MIN, PHP_INT_MAX, 'int64'),
            'uint32' => $this->validateIntegerFormat($subject, $value, 0, 4294967295, 'uint32'),
            // 2^64-1 exceeds PHP_INT_MAX, so the upper bound can only be expressed as a float.
            'uint64' => $this->validateIntegerFormat($subject, $value, 0, 18446744073709551615.0, 'uint64'),
            default => [],
        };
    }

    /**
     * @return array<string>
     */
    private function validateIntegerFormat(string $subject, int|float $value, int $min, int|float $max, string $format): array
    {
        $maxLabel = $format === 'uint64' ? '18446744073709551615' : (string)$max;

        if (is_float($value) && (is_nan($value) || is_infinite($value) || floor($value) !== $value)) {
            return ["{$subject} must be an integer ({$format})"];
        }

        // A float can't represent the int64 boundary: (float)PHP_INT_MAX rounds up to 2^63,
        // so `$value > $max` misses an overflowing float. Reject any float at/beyond 2^63.
        if (is_float($value) && $format === 'int64' && $value >= 9223372036854775808.0) {
            return ["{$subject} must be within {$format} range ({$min} to {$maxLabel})"];
        }

        // `uint64` gets no such guard, and that is measured rather than forgotten: its legal maximum
        // `18446744073709551615` and the first illegal value `18446744073709551616` are THE SAME float
        // once `json_decode()` is done, so a guard that refuses the boundary refuses a legal value and
        // one that accepts it lets 2^64 through. Tried both; the boundary float is accepted, because a
        // false rejection of the documented maximum costs live requests while the other direction costs
        // one impossible-in-practice value. A document needing exact 64-bit unsigned range should carry
        // it as `type: string` with a `pattern`, where nothing is rounded.

        if ($value < $min) {
            return ["{$subject} must be within {$format} range ({$min} to {$maxLabel})"];
        }
        if ($value > $max) {
            return ["{$subject} must be within {$format} range ({$min} to {$maxLabel})"];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateString(string $subject, string $value, array $constraints): array
    {
        $errors = [];
        $length = mb_strlen($value);

        if (($minLength = $this->toIntOrNull($constraints['minLength'] ?? null)) !== null && $length < $minLength) {
            $errors[] = "{$subject} length must be at least {$minLength} characters";
        }

        if (($maxLength = $this->toIntOrNull($constraints['maxLength'] ?? null)) !== null && $length > $maxLength) {
            $errors[] = "{$subject} length must be at most {$maxLength} characters";
        }

        if (is_string($pattern = $constraints['pattern'] ?? null) && $pattern !== '') {
            $regex = self::delimitPattern($pattern);
            // Single compile: preg_match returns false for an invalid pattern (warning
            // suppressed), 1 on match, 0 on no-match — distinguishing both error cases.
            set_error_handler(static fn(): bool => true);
            try {
                $match = preg_match($regex, $value);
            } finally {
                restore_error_handler();
            }

            if ($match === false) {
                // preg_match() fails both for a broken schema pattern and for invalid
                // UTF-8 in the subject (the `u` modifier) — blame the right side.
                if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
                    $errors[] = "{$subject} contains invalid UTF-8 characters";
                } else {
                    $errors[] = "{$subject} has invalid regex pattern in schema: {$pattern}";
                }
            } elseif ($match !== 1) {
                $errors[] = "{$subject} must match pattern {$pattern}";
            }
        }

        $format = $constraints['format'] ?? null;
        if (is_string($format) && !$this->isValidStringFormat(value: $value, format: $format)) {
            $errors[] = "{$subject} must match format {$format}";
        }

        return $errors;
    }

    /**
     * contentEncoding / contentMediaType / contentSchema (JSON Schema 2019-09/2020-12, OpenAPI 3.1).
     * Enforced as assertions on string values: the string must decode under contentEncoding, the
     * decoded bytes must parse as contentMediaType (for JSON media types), and the parsed document
     * must satisfy contentSchema. An unknown encoding / non-JSON media type is accepted leniently
     * (the spec treats these as annotations we cannot assert without a codec/parser).
     *
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateContent(string $subject, string $value, array $constraints, int $depth): array
    {
        $decoded = $value;

        $encoding = $constraints['contentEncoding'] ?? null;
        if (is_string($encoding)) {
            $result = $this->decodeContent($value, $encoding);
            if ($result === null) {
                // Bad encoding: cannot proceed to media-type / schema checks.
                return ["{$subject} is not valid {$encoding}-encoded content"];
            }
            $decoded = $result;
        }

        $mediaType = $constraints['contentMediaType'] ?? null;
        if (!is_string($mediaType) || !$this->isJsonMediaType($mediaType)) {
            // No JSON media type to parse against → nothing further to assert.
            return [];
        }

        try {
            // Objects stay stdClass: decoded to arrays, `[]` passed `type: object` and `{}` failed `array`.
            $parsed = json_decode($decoded, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ["{$subject} is not valid {$mediaType} content"];
        }

        $contentSchema = $constraints['contentSchema'] ?? null;
        if (is_array($contentSchema) && $contentSchema !== []) {
            return [
                ...$this->contentShapeErrors(subject: $subject, value: $parsed, schema: $contentSchema, depth: $depth + 1),
                ...$this->validateConstraints($subject, $parsed, $contentSchema, $depth + 1),
            ];
        }

        return [];
    }

    /**
     * `multipleOf` as JSON means it: the quotient is a whole number in DECIMAL. A float ratio with a
     * tolerance said `1e-12` is a multiple of `0.1`. Both numbers are read at the shortest decimal
     * spelling that round-trips, scaled to one exponent, and divided as a digit string.
     */
    private static function isDecimalMultiple(int|float $value, int|float $of): bool
    {
        if (!is_finite((float)$value) || !is_finite((float)$of) || $of <= 0) {
            return false;
        }
        [$valueDigits, $valueExponent] = self::decimalParts($value);
        [$ofDigits, $ofExponent] = self::decimalParts($of);
        if ($valueDigits === '0') {
            return true;
        }
        $shift = $valueExponent - $ofExponent;
        if ($shift < 0) {
            // Scaling the value down must not cut off a non-zero digit.
            if (strlen($valueDigits) <= -$shift || substr($valueDigits, $shift) !== str_repeat('0', -$shift)) {
                return false;
            }
            $valueDigits = substr($valueDigits, 0, $shift);
        } else {
            $valueDigits .= str_repeat('0', $shift);
        }
        // Seventeen digits at most, so `$remainder * 10` cannot overflow; only an integer divisor
        // can be longer, and next to a float value that is past exactness anyway.
        if (strlen($ofDigits) > 17) {
            return fmod((float)$value, (float)$of) === 0.0;
        }
        $divisor = (int)$ofDigits;
        $remainder = 0;
        foreach (str_split($valueDigits) as $digit) {
            $remainder = ($remainder * 10 + (int)$digit) % $divisor;
        }

        return $remainder === 0;
    }

    /**
     * @return array{0: string, 1: int} significant digits and the power of ten they are scaled by
     */
    private static function decimalParts(int|float $number): array
    {
        if (is_int($number)) {
            return [ltrim((string)$number, '-'), 0];
        }
        $number = abs($number);
        $rendered = sprintf('%.16e', $number);
        for ($precision = 0; $precision < 17; $precision++) {
            $candidate = sprintf('%.' . $precision . 'e', $number);
            if ((float)$candidate === $number) {
                $rendered = $candidate;
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $rendered);
        $fraction = strlen(explode('.', $mantissa . '.')[1]);
        $digits = ltrim(str_replace('.', '', $mantissa), '0');

        return [$digits === '' ? '0' : $digits, (int)$exponent - $fraction];
    }

    /**
     * The errors that say a subschema was NOT checked to the end — the depth limit, an equality walk
     * that could not finish. Where a keyword turns "has errors" into "did not match" (`not`, `if`, a
     * `oneOf` count, `contains`), these must not: an unfinished check is not a verdict.
     *
     * @param array<string> $errors
     * @return array<string>
     */
    private function incompleteVerdict(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static fn(string $error): bool => str_contains($error, 'schema nesting exceeds')
                || str_contains($error, 'JSON equality traversal exceeds its limits'),
        ));
    }

    /**
     * The one wire-shape question the type check cannot answer: a JSON array where only `object` is
     * allowed. `type: object` accepts any PHP array, because a hydrated map `{}` IS `[]` — but an
     * embedded document is decoded here with its objects as stdClass, so every array in it is a JSON
     * array. Walked along the keywords that address a position; a union branch cannot be decided
     * alone and is left to the ordinary check.
     *
     * @return array<string>
     */
    private function contentShapeErrors(string $subject, mixed $value, mixed $schema, int $depth): array
    {
        if (!is_array($schema) || $depth >= self::MAX_VALIDATION_DEPTH) {
            return [];
        }

        $type = $schema['type'] ?? null;
        $types = is_string($type) ? [$type] : (is_array($type) ? $type : []);
        if (is_array($value) && in_array('object', $types, true) && !in_array('array', $types, true)) {
            return ["{$subject} must be of type " . implode('|', array_filter($types, 'is_string'))];
        }

        $errors = [];
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $branch) {
            $errors = [...$errors, ...$this->contentShapeErrors(subject: $subject, value: $value, schema: $branch, depth: $depth + 1)];
        }

        if ($value instanceof stdClass) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $extra = is_array($schema['patternProperties'] ?? null) ? null : ($schema['additionalProperties'] ?? null);
            foreach (get_object_vars($value) as $name => $member) {
                $memberSchema = $properties[$name] ?? $extra;
                $errors = [...$errors, ...$this->contentShapeErrors(
                    subject: "{$subject}.{$name}",
                    value: $member,
                    schema: $memberSchema,
                    depth: $depth + 1,
                )];
            }
        } elseif (is_array($value)) {
            $prefix = is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : [];
            foreach ($value as $index => $member) {
                $errors = [...$errors, ...$this->contentShapeErrors(
                    subject: "{$subject}[{$index}]",
                    value: $member,
                    schema: $prefix[$index] ?? $schema['items'] ?? null,
                    depth: $depth + 1,
                )];
            }
        }

        return $errors;
    }

    private function decodeContent(string $value, string $encoding): ?string
    {
        switch (strtolower($encoding)) {
            case 'base64':
                if (!$this->isValidBase64($value)) {
                    return null;
                }
                $decoded = base64_decode($value, true);
                return $decoded === false ? null : $decoded;
            case 'base16':
                if ($value === '') {
                    return '';
                }
                if (strlen($value) % 2 !== 0 || !ctype_xdigit($value)) {
                    return null;
                }
                $decoded = hex2bin($value);
                return $decoded === false ? null : $decoded;
            case 'quoted-printable':
                return quoted_printable_decode($value);
            case '7bit':
            case '8bit':
            case 'binary':
                // Identity transfer encodings: the string is already the content.
                return $value;
            default:
                // Unknown codec (e.g. base32): accept leniently, no decode applied.
                return $value;
        }
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        // application/json and any structured-suffix +json type (application/ld+json, …).
        $normalized = strtolower(trim(explode(';', $mediaType)[0]));
        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }

    /**
     * JSON equality: numeric value, unordered object keys, ordered lists, no scalar coercion.
     * A missing fingerprint means traversal could not finish; callers must reject it.
     */
    private function jsonValueFingerprint(mixed $value, int $depth = 0): ?string
    {
        if ($depth >= self::MAX_VALIDATION_DEPTH) {
            return null;
        }
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return serialize($value);
            }
            // The upper bound is exclusive: (float)PHP_INT_MAX is already 2^63.
            if ($value >= PHP_INT_MIN && $value < -(float)PHP_INT_MIN && floor($value) === $value) {
                $value = (int)$value;
            } else {
                // Binary representation stays exact even when serialize_precision is overridden.
                return 'float:' . bin2hex(pack('E', $value));
            }
        }
        // A hydrated date has no public state: through get_object_vars() every date was `{}`, so two
        // distinct dates were equal under uniqueItems.
        if ($value instanceof DateTimeInterface) {
            return 'date-time:' . $value->format('Y-m-d\TH:i:s.uP');
        }
        $isObject = is_object($value) || (is_array($value) && !array_is_list($value));
        if (is_object($value)) {
            $value = $this->generatedDtoPayload($value) ?? get_object_vars($value);
        }
        if (!is_array($value)) {
            return serialize($value);
        }
        if ($isObject) {
            ksort($value, SORT_STRING);
        }
        $members = [];
        foreach ($value as $key => $item) {
            $fingerprint = $this->jsonValueFingerprint($item, $depth + 1);
            if ($fingerprint === null) {
                return null;
            }
            $members[$key] = $fingerprint;
        }

        return ($isObject ? 'object:' : 'array:') . serialize($members);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateObjectConstraints(string $subject, array $value, array $constraints, int $depth): array
    {
        $errors = [];
        $count = count($value);

        if (($minProperties = $this->toIntOrNull($constraints['minProperties'] ?? null)) !== null && $count < $minProperties) {
            $errors[] = "{$subject} must have at least {$minProperties} " . ($minProperties === 1 ? 'property' : 'properties');
        }

        if (($maxProperties = $this->toIntOrNull($constraints['maxProperties'] ?? null)) !== null && $count > $maxProperties) {
            $errors[] = "{$subject} must have at most {$maxProperties} " . ($maxProperties === 1 ? 'property' : 'properties');
        }

        $definedPropertyNames = null;
        if (is_array($constraints['properties'] ?? null)) {
            $definedPropertyNames = [];
            foreach ($constraints['properties'] as $propName => $propSchema) {
                if (!is_string($propName) || !is_array($propSchema)) {
                    continue;
                }
                $definedPropertyNames[] = $propName;
                if (!array_key_exists($propName, $value)) {
                    continue;
                }
                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        subject: sprintf('%s.%s', $subject, $propName),
                        value: $value[$propName],
                        constraints: $propSchema,
                        depth: $depth + 1,
                    ),
                );
            }
        }

        if (is_array($constraints['required'] ?? null)) {
            foreach ($constraints['required'] as $requiredProp) {
                if (!is_string($requiredProp)) {
                    continue;
                }
                if (!array_key_exists($requiredProp, $value)) {
                    $errors[] = sprintf('%s.%s is required', $subject, $requiredProp);
                }
            }
        }

        // patternProperties: every key matching a pattern is validated against its schema.
        $patternSchemas = [];
        if (is_array($constraints['patternProperties'] ?? null)) {
            foreach ($constraints['patternProperties'] as $pattern => $schema) {
                if (!is_string($pattern) || !is_array($schema)) {
                    continue;
                }
                $patternSchemas[$pattern] = $schema;
                foreach ($value as $key => $itemValue) {
                    if ($this->keyMatchesPattern((string)$key, $pattern)) {
                        array_push(
                            $errors,
                            ...$this->validateConstraints(
                                subject: sprintf('%s.%s', $subject, (string)$key),
                                value: $itemValue,
                                constraints: $schema,
                                depth: $depth + 1,
                            ),
                        );
                    }
                }
            }
        }

        // propertyNames: every key (as a string) must validate against the schema.
        if (is_array($constraints['propertyNames'] ?? null)) {
            foreach (array_keys($value) as $key) {
                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        subject: sprintf('%s key "%s"', $subject, (string)$key),
                        value: (string)$key,
                        constraints: $constraints['propertyNames'],
                        depth: $depth + 1,
                    ),
                );
            }
        }

        $additionalProperties = $constraints['additionalProperties'] ?? null;
        // A key is "additional" only if it is neither a declared property nor matched by
        // any patternProperties entry (JSON Schema semantics).
        $isKnownKey = function (string $key) use ($definedPropertyNames, $patternSchemas): bool {
            if ($definedPropertyNames !== null && in_array($key, $definedPropertyNames, true)) {
                return true;
            }
            foreach (array_keys($patternSchemas) as $pattern) {
                if ($this->keyMatchesPattern($key, $pattern)) {
                    return true;
                }
            }

            return false;
        };

        if ($additionalProperties === false) {
            // No guard on $definedPropertyNames: a schema with only patternProperties (or
            // a bare additionalProperties:false) must still reject unknown keys.
            foreach (array_keys($value) as $key) {
                if (!$isKnownKey((string)$key)) {
                    $errors[] = "{$subject} has additional property \"{$key}\" which is not allowed";
                }
            }
        } elseif (is_array($additionalProperties)) {
            foreach ($value as $key => $itemValue) {
                if ($isKnownKey((string)$key)) {
                    continue;
                }
                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        subject: sprintf('%s.%s', $subject, (string)$key),
                        value: $itemValue,
                        constraints: $additionalProperties,
                        depth: $depth + 1,
                    ),
                );
            }
        }

        if (array_key_exists('dependentRequired', $constraints) && is_array($constraints['dependentRequired'])) {
            foreach ($constraints['dependentRequired'] as $ifProp => $deps) {
                if (!is_string($ifProp) || !is_array($deps) || !array_key_exists($ifProp, $value)) {
                    continue;
                }
                foreach ($deps as $dep) {
                    if (is_string($dep) && !array_key_exists($dep, $value)) {
                        $errors[] = sprintf('%s.%s is required when %s is present', $subject, $dep, $ifProp);
                    }
                }
            }
        }

        if (array_key_exists('dependentSchemas', $constraints) && is_array($constraints['dependentSchemas'])) {
            foreach ($constraints['dependentSchemas'] as $ifProp => $schema) {
                if (!is_string($ifProp) || !is_array($schema) || !array_key_exists($ifProp, $value)) {
                    continue;
                }
                array_push($errors, ...$this->validateConstraints($subject, $value, $schema, $depth + 1));
            }
        }

        // unevaluatedProperties (JSON Schema 2019-09/2020-12): applies to keys not already
        // "evaluated" by this schema's own properties/patternProperties/additionalProperties
        // NOR by any in-place applicator (allOf, passing anyOf/oneOf branches, the applicable
        // if/then/else, matching dependentSchemas). `true` places no constraint.
        if (array_key_exists('unevaluatedProperties', $constraints) && $constraints['unevaluatedProperties'] !== true) {
            $unevaluated = $constraints['unevaluatedProperties'];
            $evaluatedKeys = $this->collectEvaluatedProperties($value, $constraints, $depth);
            foreach ($value as $key => $itemValue) {
                $keyStr = (string)$key;
                if (array_key_exists($keyStr, $evaluatedKeys)) {
                    continue;
                }
                if ($unevaluated === false) {
                    $errors[] = "{$subject} has unevaluated property \"{$keyStr}\" which is not allowed";
                } elseif (is_array($unevaluated) && $unevaluated !== []) {
                    array_push(
                        $errors,
                        ...$this->validateConstraints(
                            subject: sprintf('%s.%s', $subject, $keyStr),
                            value: $itemValue,
                            constraints: $unevaluated,
                            depth: $depth + 1,
                        ),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateArray(string $subject, array $value, array $constraints, int $depth): array
    {
        $errors = [];
        $count = count($value);

        if (($minItems = $this->toIntOrNull($constraints['minItems'] ?? null)) !== null && $count < $minItems) {
            $errors[] = "{$subject} must contain at least {$minItems} items";
        }

        if (($maxItems = $this->toIntOrNull($constraints['maxItems'] ?? null)) !== null && $count > $maxItems) {
            $errors[] = "{$subject} must contain at most {$maxItems} items";
        }

        if (($constraints['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $fingerprint = $this->jsonValueFingerprint($item);
                if ($fingerprint === null) {
                    $errors[] = "{$subject}: JSON equality traversal exceeds its limits";
                    break;
                }

                if (array_key_exists($fingerprint, $seen)) {
                    $errors[] = "{$subject} must contain unique items";
                    break;
                }

                $seen[$fingerprint] = true;
            }
        }

        $itemConstraints = $constraints['items'] ?? null;
        // `!== []` is deliberately absent: `{}` decodes to an empty PHP array, and an EMPTY SCHEMA is
        // not an absent one — it matches every value. Treating the two alike meant `items: {}` marked
        // nothing as evaluated, so `unevaluatedItems: false` cut a valid array; `contains: {}` found no
        // match, so `minContains` could not be satisfied; and `additionalProperties: {}` left extra keys
        // unevaluated for `unevaluatedProperties: false` to reject. Measured on all three.
        if (is_array($itemConstraints)) {
            // Per JSON Schema 2020-12, `items` is a suffix validator: when `prefixItems` is
            // also present it applies only to indices ≥ count(prefixItems). The prefix
            // positions are validated by their own `prefixItems` schemas below.
            $prefixCount = (array_key_exists('prefixItems', $constraints) && is_array($constraints['prefixItems']))
                ? count($constraints['prefixItems'])
                : 0;

            // One item schema, applied to every element: whether it uses a composition keyword is a
            // property of the SCHEMA, so it is answered once here instead of on each element. Worth
            // ~18% of a long list, measured; the elements themselves are unchanged.
            $itemsHaveComposition = array_key_exists('allOf', $itemConstraints)
                || array_key_exists('oneOf', $itemConstraints)
                || array_key_exists('anyOf', $itemConstraints)
                || array_key_exists('enum', $itemConstraints)
                || array_key_exists('const', $itemConstraints)
                || array_key_exists('not', $itemConstraints)
                || array_key_exists('if', $itemConstraints);

            foreach ($value as $index => $itemValue) {
                if (is_int($index) && $index < $prefixCount) {
                    continue;
                }

                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        subject: sprintf('%s.%s', $subject, (string)$index),
                        value: $itemValue,
                        constraints: $itemConstraints,
                        depth: $depth + 1,
                        hasComposition: $itemsHaveComposition,
                    ),
                );
            }
        }

        $containsSchema = $constraints['contains'] ?? null;
        if (is_array($containsSchema)) {
            // Same reasoning as `items` above: one schema, every element, so the question is asked
            // once rather than per element.
            $containsHasComposition = array_key_exists('allOf', $containsSchema)
                || array_key_exists('oneOf', $containsSchema)
                || array_key_exists('anyOf', $containsSchema)
                || array_key_exists('enum', $containsSchema)
                || array_key_exists('const', $containsSchema)
                || array_key_exists('not', $containsSchema)
                || array_key_exists('if', $containsSchema);
            $matchCount = 0;
            $incompleteMatch = [];
            foreach ($value as $itemValue) {
                $itemErrors = $this->validateConstraints($subject, $itemValue, $containsSchema, $depth + 1, $containsHasComposition);
                if ($itemErrors === []) {
                    $matchCount++;
                }
                $incompleteMatch = [...$incompleteMatch, ...$this->incompleteVerdict($itemErrors)];
            }
            // An item whose match could not be decided makes the count unknown.
            if ($incompleteMatch !== []) {
                $errors = [...$errors, ...array_values(array_unique($incompleteMatch))];
            }

            $minContains = $this->toIntOrNull($constraints['minContains'] ?? null) ?? 1;
            $maxContains = $this->toIntOrNull($constraints['maxContains'] ?? null);

            if ($matchCount < $minContains) {
                $errors[] = "{$subject} must contain at least {$minContains} item(s) matching the 'contains' schema";
            }

            if ($maxContains !== null && $matchCount > $maxContains) {
                $errors[] = "{$subject} must contain at most {$maxContains} item(s) matching the 'contains' schema";
            }
        }

        if (array_key_exists('prefixItems', $constraints) && is_array($constraints['prefixItems'])) {
            foreach ($constraints['prefixItems'] as $index => $itemSchema) {
                if (!is_array($itemSchema) || !array_key_exists($index, $value)) {
                    break;
                }
                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        subject: sprintf('%s.%s', $subject, (string)$index),
                        value: $value[$index],
                        constraints: $itemSchema,
                        depth: $depth + 1,
                    ),
                );
            }
        }

        // unevaluatedItems (JSON Schema 2019-09/2020-12): applies to list positions not
        // already "evaluated" by prefixItems/items/contains NOR by an in-place applicator.
        // Only meaningful for JSON arrays (lists); `true` places no constraint.
        if (
            array_key_exists('unevaluatedItems', $constraints)
            && $constraints['unevaluatedItems'] !== true
            && array_is_list($value)
        ) {
            $unevaluated = $constraints['unevaluatedItems'];
            $evaluatedIndices = $this->collectEvaluatedItems($value, $constraints, $depth);
            foreach ($value as $index => $itemValue) {
                if (array_key_exists($index, $evaluatedIndices)) {
                    continue;
                }
                if ($unevaluated === false) {
                    $errors[] = sprintf('%s has an unevaluated item at index %s which is not allowed', $subject, (string)$index);
                } elseif (is_array($unevaluated) && $unevaluated !== []) {
                    array_push(
                        $errors,
                        ...$this->validateConstraints(
                            subject: sprintf('%s.%s', $subject, (string)$index),
                            value: $itemValue,
                            constraints: $unevaluated,
                            depth: $depth + 1,
                        ),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Collects the object keys considered "evaluated" at this schema location — by its own
     * properties/patternProperties/additionalProperties and by every in-place applicator
     * (allOf, passing anyOf/oneOf branches, the applicable if/then/else, matching
     * dependentSchemas) that applies to the same object. Backs `unevaluatedProperties`,
     * which only inspects keys left over after all of these.
     *
     * A branch contributes annotations only when it actually applies (a failing anyOf/oneOf
     * branch, or the non-taken if/then/else arm, is discarded) — mirroring the JSON Schema
     * rule that annotations from unsuccessful subschemas are dropped.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, true>
     */
    private function collectEvaluatedProperties(mixed $value, array $constraints, int $depth, bool $asSubschema = false): array
    {
        if (!is_array($value) || $depth >= self::MAX_VALIDATION_DEPTH) {
            return [];
        }

        // An in-place subschema carrying its own `unevaluatedProperties` (anything but `false`) has evaluated
        // every position that reached it — the ones it did not, it just did. Its annotation belongs to
        // the location above, which read none of it: `allOf: [{unevaluatedProperties: {…}}], unevaluatedProperties: false`
        // refused what the branch had already checked. Not for the location's OWN keyword, which is
        // the one asking.
        if ($asSubschema && array_key_exists('unevaluatedProperties', $constraints) && $constraints['unevaluatedProperties'] !== false) {
            $all = [];
            foreach (array_keys($value) as $key) {
                $all[(string)$key] = true;
            }

            return $all;
        }

        // The annotation walk reads the same subschema keys the validation walk reads, so it needs
        // the same rewrite: `items: true` marks every index evaluated, `false` marks none.
        $constraints = self::expandBooleanSubschemas($constraints);

        $evaluated = [];

        if (is_array($constraints['properties'] ?? null)) {
            foreach (array_keys($constraints['properties']) as $propName) {
                if (is_string($propName) && array_key_exists($propName, $value)) {
                    $evaluated[$propName] = true;
                }
            }
        }

        if (is_array($constraints['patternProperties'] ?? null)) {
            foreach (array_keys($constraints['patternProperties']) as $pattern) {
                if (!is_string($pattern)) {
                    continue;
                }
                foreach (array_keys($value) as $key) {
                    if ($this->keyMatchesPattern((string)$key, $pattern)) {
                        $evaluated[(string)$key] = true;
                    }
                }
            }
        }

        // additionalProperties as a schema (or `true`) evaluates every remaining key.
        $additional = $constraints['additionalProperties'] ?? null;
        if ($additional === true || is_array($additional)) {
            foreach (array_keys($value) as $key) {
                $evaluated[(string)$key] = true;
            }
        }

        if (is_array($constraints['allOf'] ?? null)) {
            foreach ($constraints['allOf'] as $branch) {
                if (is_array($branch)) {
                    $evaluated += $this->collectEvaluatedProperties($value, $branch, $depth + 1, asSubschema: true);
                }
            }
        }

        foreach (['anyOf', 'oneOf'] as $unionKey) {
            if (!is_array($constraints[$unionKey] ?? null)) {
                continue;
            }
            foreach ($constraints[$unionKey] as $branch) {
                if (
                    is_array($branch)
                    && $this->matchesOpenApiType($value, $branch['type'] ?? null)
                    && $this->validateConstraints('', $value, $branch, $depth + 1) === []
                ) {
                    $evaluated += $this->collectEvaluatedProperties($value, $branch, $depth + 1, asSubschema: true);
                }
            }
        }

        if (is_array($constraints['if'] ?? null)) {
            $ifApplies = $this->validateConstraints('', $value, $constraints['if'], $depth + 1) === [];
            if ($ifApplies) {
                $evaluated += $this->collectEvaluatedProperties($value, $constraints['if'], $depth + 1, asSubschema: true);
                if (is_array($constraints['then'] ?? null)) {
                    $evaluated += $this->collectEvaluatedProperties($value, $constraints['then'], $depth + 1, asSubschema: true);
                }
            } elseif (is_array($constraints['else'] ?? null)) {
                $evaluated += $this->collectEvaluatedProperties($value, $constraints['else'], $depth + 1, asSubschema: true);
            }
        }

        if (is_array($constraints['dependentSchemas'] ?? null)) {
            foreach ($constraints['dependentSchemas'] as $ifProp => $schema) {
                if (is_string($ifProp) && array_key_exists($ifProp, $value) && is_array($schema)) {
                    $evaluated += $this->collectEvaluatedProperties($value, $schema, $depth + 1, asSubschema: true);
                }
            }
        }

        return $evaluated;
    }

    /**
     * Array counterpart of {@see collectEvaluatedProperties}: collects the list indices
     * considered "evaluated" by prefixItems/items/contains and by in-place applicators.
     * Backs `unevaluatedItems`.
     *
     * @param array<string, mixed> $constraints
     * @return array<int, true>
     */
    private function collectEvaluatedItems(mixed $value, array $constraints, int $depth, bool $asSubschema = false): array
    {
        if (!is_array($value) || $depth >= self::MAX_VALIDATION_DEPTH) {
            return [];
        }

        // An in-place subschema carrying its own `unevaluatedItems` (anything but `false`) has evaluated
        // every position that reached it — the ones it did not, it just did. Its annotation belongs to
        // the location above, which read none of it: `allOf: [{unevaluatedItems: {…}}], unevaluatedItems: false`
        // refused what the branch had already checked. Not for the location's OWN keyword, which is
        // the one asking.
        if ($asSubschema && array_key_exists('unevaluatedItems', $constraints) && $constraints['unevaluatedItems'] !== false) {
            $all = [];
            foreach (array_keys($value) as $key) {
                $all[$key] = true;
            }

            return $all;
        }

        // The annotation walk reads the same subschema keys the validation walk reads, so it needs
        // the same rewrite: `items: true` marks every index evaluated, `false` marks none.
        $constraints = self::expandBooleanSubschemas($constraints);

        $evaluated = [];

        $prefixCount = 0;
        if (is_array($constraints['prefixItems'] ?? null)) {
            $prefixCount = count($constraints['prefixItems']);
            foreach (array_keys($value) as $key) {
                if (is_int($key) && $key < $prefixCount) {
                    $evaluated[$key] = true;
                }
            }
        }

        // `items` (a schema, not `false`) is a suffix validator covering every index at or
        // beyond the prefix length → all such positions are evaluated.
        $items = $constraints['items'] ?? null;
        if (is_array($items)) {
            foreach (array_keys($value) as $key) {
                if (is_int($key) && $key >= $prefixCount) {
                    $evaluated[$key] = true;
                }
            }
        }

        // `contains` evaluates each index whose item matches the contains schema.
        $contains = $constraints['contains'] ?? null;
        if (is_array($contains)) {
            foreach ($value as $key => $itemValue) {
                if (is_int($key) && $this->validateConstraints('', $itemValue, $contains, $depth + 1) === []) {
                    $evaluated[$key] = true;
                }
            }
        }

        if (is_array($constraints['allOf'] ?? null)) {
            foreach ($constraints['allOf'] as $branch) {
                if (is_array($branch)) {
                    $evaluated += $this->collectEvaluatedItems($value, $branch, $depth + 1, asSubschema: true);
                }
            }
        }

        foreach (['anyOf', 'oneOf'] as $unionKey) {
            if (!is_array($constraints[$unionKey] ?? null)) {
                continue;
            }
            foreach ($constraints[$unionKey] as $branch) {
                if (
                    is_array($branch)
                    && $this->matchesOpenApiType($value, $branch['type'] ?? null)
                    && $this->validateConstraints('', $value, $branch, $depth + 1) === []
                ) {
                    $evaluated += $this->collectEvaluatedItems($value, $branch, $depth + 1, asSubschema: true);
                }
            }
        }

        if (is_array($constraints['if'] ?? null)) {
            $ifApplies = $this->validateConstraints('', $value, $constraints['if'], $depth + 1) === [];
            if ($ifApplies) {
                $evaluated += $this->collectEvaluatedItems($value, $constraints['if'], $depth + 1, asSubschema: true);
                if (is_array($constraints['then'] ?? null)) {
                    $evaluated += $this->collectEvaluatedItems($value, $constraints['then'], $depth + 1, asSubschema: true);
                }
            } elseif (is_array($constraints['else'] ?? null)) {
                $evaluated += $this->collectEvaluatedItems($value, $constraints['else'], $depth + 1, asSubschema: true);
            }
        }

        return $evaluated;
    }

    private function isValidStringFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date' => $this->isValidDateFormat(value: $value),
            'date-time', 'datetime' => $this->isValidDateTimeFormat(value: $value),
            'time' => $this->isValidTimeFormat(value: $value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'idn-email' => $this->isValidIdnEmail(value: $value),
            'uuid' => $this->isValidUuid(value: $value),
            'uri' => $this->isValidUri(value: $value),
            'iri' => $this->isValidIri(value: $value),
            'uri-reference', 'iri-reference' => $this->isValidUriReference(value: $value),
            'uri-template' => $this->isValidUriTemplate(value: $value),
            'duration' => $this->isValidDuration(value: $value),
            'json-pointer' => $this->isValidJsonPointer(value: $value),
            'relative-json-pointer' => $this->isValidRelativeJsonPointer(value: $value),
            'regex' => $this->isValidRegexFormat(value: $value),
            'hostname' => filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
            'idn-hostname' => $this->isValidIdnHostname(value: $value),
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'byte' => $this->isValidBase64(value: $value),
            'password' => true,
            'binary' => true,
            default => true,
        };
    }

    private function keyMatchesPattern(string $key, string $pattern): bool
    {
        $regex = self::delimitPattern($pattern);
        // Suppress warnings from an invalid schema pattern → treat as no match.
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($regex, $key) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An absolute URI, which is not the same thing as a URL.
     *
     * `FILTER_VALIDATE_URL` only knows the authority-based shapes — `http://`, `ftp://` and friends —
     * so it refused `urn:isbn:0451450523` and `urn:uuid:…`, both perfectly valid URIs under RFC 3986
     * and both things a real document uses as an identifier. A URI needs a scheme and something after
     * the colon; whether that something starts with `//` is the difference between a URL and a URN, not
     * between valid and invalid. The URL filter still answers for the authority-based forms, so nothing
     * that passed before stops passing.
     */
    private function isValidUri(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // scheme ":" then a non-empty, space-free remainder, and NOT starting with `//`: an
        // authority-based URI is exactly what the URL filter above already judged, and letting it in
        // here would accept `http://[` — a malformed host — as valid. This branch is for the
        // scheme-only shapes: `urn:`, `mailto:`, `tel:`.
        return preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:(?!\/\/)[^\s]+$/', $value) === 1;
    }

    private function isValidTimeFormat(string $value): bool
    {
        // RFC 3339 full-time: HH:MM:SS[.frac] with required Z or numeric offset.
        return preg_match(
            pattern: '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/',
            subject: $value,
        ) === 1;
    }

    private function isValidIri(string $value): bool
    {
        // RFC 3987 absolute IRI: a scheme is required and no whitespace/control chars are
        // allowed. FILTER_VALIDATE_URL rejects non-ASCII, so it cannot be reused for IRIs;
        // stricter structural validation of the Unicode path is impractical here.
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return false;
        }

        // A scheme alone ("a:") is not a usable IRI — require at least one char after it.
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:.+/', $value) === 1;
    }

    private function isValidDuration(string $value): bool
    {
        // ISO 8601 / RFC 3339 duration. The week form (PnW) is mutually exclusive with the
        // Y/M/D/T components, so it is a separate alternative; otherwise at least one date
        // or time component is required, and a "T" must be followed by a time component.
        return preg_match(
            pattern: '/^P(?:\d+W|(?=\d|T)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?)$/',
            subject: $value,
        ) === 1;
    }

    private function isValidJsonPointer(string $value): bool
    {
        // RFC 6901: the empty string (whole document) or one-or-more "/"-prefixed tokens.
        // Inside a token "~" is an escape and must be followed by "0" or "1".
        return preg_match('#^(/(?:[^/~]|~[01])*)*$#u', $value) === 1;
    }

    private function isValidRelativeJsonPointer(string $value): bool
    {
        // RFC draft: a non-negative integer (no leading zeros) optionally followed by either
        // "#" (the key/index) or a JSON Pointer. Examples: "0", "1/foo", "2#".
        return preg_match('!^(0|[1-9][0-9]*)(?:#|(?:/(?:[^/~]|~[01])*)*)$!u', $value) === 1;
    }

    private function isValidUriReference(string $value): bool
    {
        // RFC 3986 URI-reference: absolute URI OR a relative reference. The empty string is a
        // valid same-document reference. Structural full-grammar validation is impractical, so
        // apply the sound, cheap invariant: no whitespace or control characters. (Unicode is
        // permitted, which also covers iri-reference.)
        return preg_match('/[\s\x00-\x1F\x7F]/u', $value) !== 1;
    }

    private function isValidUriTemplate(string $value): bool
    {
        // RFC 6570: a URI-reference that may embed {expression} blocks. Reject whitespace/control
        // first, then require every brace to belong to a well-formed expression: an optional
        // operator (+#./;?&=,!@|) followed by a comma-separated list of varspecs, each a varname
        // with optional ":" prefix-length or "*" explode modifier. Stray/unbalanced braces fail.
        if (preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return false;
        }
        if (str_contains($value, '{')) {
            $expression = '\{[+#./;?&=,!@|]?(?:[\p{L}\p{N}_%]+(?::[1-9][0-9]{0,3}|\*)?)'
                . '(?:,[\p{L}\p{N}_%]+(?::[1-9][0-9]{0,3}|\*)?)*\}';
            // Remove every valid expression; if any brace survives (in the leftover), the
            // template is malformed.
            $stripped = preg_replace('~' . $expression . '~u', '', $value);

            return $stripped !== null && !str_contains($stripped, '{') && !str_contains($stripped, '}');
        }

        // No expressions at all: a stray closing brace is malformed.
        return !str_contains($value, '}');
    }

    private function isValidIdnEmail(string $value): bool
    {
        // `idn-email` (RFC 6531) is an address whose local part may be Unicode AND whose domain may
        // be an internationalized name. PHP's filter covers only the first half:
        // `FILTER_FLAG_EMAIL_UNICODE` permits Unicode before the `@` and then validates what follows
        // as an ASCII host, so `a@пример.рф` — the very thing this format exists for — was refused
        // while `ф@example.com` passed. Measured both ways before this was written.
        //
        // Anything the filter accepts is still accepted, unchanged and first: that keeps the address
        // forms it knows and this method does not model — a bracketed IP domain (`a@[192.168.0.1]`),
        // a quoted local part — exactly as valid as they were. Only the internationalized-domain
        // case is ADDED, so no address that validated before can stop validating.
        if (filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false) {
            return true;
        }

        // Split at the LAST `@`: a quoted local part may legally contain one.
        $at = strrpos($value, '@');
        if ($at === false || $at === 0 || $at === strlen($value) - 1) {
            return false;
        }

        // The local part is re-checked against an ASCII placeholder domain so the filter's own rules
        // for it still apply, and the domain goes to the internationalized hostname check that
        // already exists — which handles intl being absent, as it is on some CLI builds.
        return filter_var(
            substr($value, 0, $at) . '@example.com',
            FILTER_VALIDATE_EMAIL,
            FILTER_FLAG_EMAIL_UNICODE,
        ) !== false
            && $this->isValidIdnHostname(value: substr($value, $at + 1));
    }

    private function isValidIdnHostname(string $value): bool
    {
        // Internationalized hostname (RFC 5890). Prefer the intl extension: convert Unicode
        // labels to ASCII (punycode) and validate the result as a plain hostname. Without intl,
        // fall back to a pragmatic Unicode-aware label check (1-63 chars/label, no leading or
        // trailing hyphen, total ≤ 253, no whitespace/control/dots-in-label).
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            return is_string($ascii)
                && filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        }

        foreach (explode('.', $value) as $label) {
            if (
                preg_match('/^(?!-)[\p{L}\p{N}\p{M}-]{1,63}(?<!-)$/u', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function isValidRegexFormat(string $value): bool
    {
        // The value itself must be a compilable regular expression. preg_match returns
        // false (not 0) when the pattern fails to compile. No `u` modifier: `format: regex`
        // only asks whether the pattern compiles, and forcing UTF-8 would reject otherwise
        // valid byte-oriented patterns.
        $regex = self::delimitPattern($value, unicode: false);
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function isValidUuid(string $value): bool
    {
        // RFC 9562 special-case UUIDs: the nil UUID (all zeros) and the max UUID (all
        // ones) carry version/variant nibbles that the general pattern rejects, but both
        // are valid and common in real data (e.g. default/sentinel identifiers).
        if (
            $value === '00000000-0000-0000-0000-000000000000'
            || strtolower($value) === 'ffffffff-ffff-ffff-ffff-ffffffffffff'
        ) {
            return true;
        }

        // Variant nibble [89abABcCdD] deliberately accepts the legacy Microsoft/COM variant
        // (c/d), not only the strict RFC 4122 10xx variant ([89ab]). This is intentional:
        // real-world payloads from .NET/Windows carry variant-2 GUIDs and must not be rejected.
        return preg_match(
            pattern: '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abABcCdD][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            subject: $value,
        ) === 1;
    }

    private function isValidDateFormat(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        return $date->format('Y-m-d') === $value;
    }

    private function isValidDateTimeFormat(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Reject structural mismatches (trailing garbage, wrong separator) before createFromFormat.
        // createFromFormat silently accepts trailing characters, so a pre-check is required.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value) !== 1) {
            return false;
        }

        // Shared with the deserializer via GeneratedDtoInterface — including Z suffix (lowercase p).
        foreach (GeneratedDtoInterface::DATE_TIME_FORMATS as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            // Reject calendar-invalid values that createFromFormat rolls over (e.g. Feb 30);
            // such overflows are reported only as warnings.
            $errors = DateTimeImmutable::getLastErrors();
            $hasWarnings = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
            if ($dt instanceof DateTimeImmutable && !$hasWarnings) {
                return true;
            }
        }

        return false;
    }

    private function isValidBase64(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        if (preg_match('/[^A-Za-z0-9\/+\r\n=]/', $value) === 1) {
            return false;
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $asFloat = (float)$value;
        if ($asFloat !== (float)(int)$asFloat) {
            return null;
        }

        return (int)$value;
    }

    private function stringifyNumber(int|float $value): string
    {
        $rendered = json_encode($value);

        return is_string($rendered) ? $rendered : (string)$value;
    }

    private function typeToOpenApi(mixed $value): string
    {
        return match (gettype($value)) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            'array' => 'array',
            'object' => 'object',
            'NULL' => 'null',
            default => gettype($value),
        };
    }

    /**
     * The array view the object keywords need, or null when there is none.
     *
     * The plain-object arm is the one that was missing. `generatedDtoPayload()` answers for a generated
     * DTO and null for everything else, so a `stdClass` — which is exactly what a `$ref` two containers
     * deep decodes to — took the null branch and every object keyword was skipped: a missing `required`
     * property and a value below its `minimum` were both accepted in silence.
     *
     * `get_object_vars()` is the same view the emitted interpreter takes
     * (`normalizeOpenApiStructuralValue()`), which is what keeps runtime mode and the four
     * interpreter-driven modes answering alike.
     *
     * @return array<string, mixed>|null
     */
    private function objectPayloadForConstraints(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_object($value)) {
            return null;
        }

        $dtoPayload = $this->generatedDtoPayload($value);
        if ($dtoPayload !== null) {
            return $dtoPayload;
        }

        // A BackedEnum is an object with a `value` property and no object shape to check; a generated
        // DTO that could not produce its payload said so by returning null and must not be re-read
        // through reflection.
        if ($value instanceof GeneratedDtoInterface || $value instanceof BackedEnum) {
            return null;
        }

        return get_object_vars($value);
    }

    /**
     * A generated DTO as an OpenAPI-named payload, or null when the value is not one (or cannot be
     * rendered because a required field was never provided — that is reported elsewhere).
     *
     * @return array<string, mixed>|null
     */
    private function generatedDtoPayload(mixed $value): ?array
    {
        if (!$value instanceof GeneratedDtoInterface || $value instanceof BackedEnum) {
            return null;
        }

        try {
            return $value->toArray();
        } catch (LogicException $e) {
            // The ONE expected failure: a required field was never provided, which the deserializer
            // reports itself — skipping the object keywords here avoids a second, vaguer message.
            // Anything else is a defect in the generated code and must surface: catching Throwable
            // hid a `TypeError` from the map-item cast, so a broken response looked like a payload
            // that simply had no object rules. Same narrow catch as `DtoNormalizer::tryFastArray()`.
            if (!str_contains($e->getMessage(), GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function withoutKeyword(array $constraints, string $keyword): array
    {
        unset($constraints[$keyword]);

        return $constraints;
    }

    /**
     * A generated DTO validates its own fields against its own constraints, so re-checking the
     * `properties` subschemas here would report every message twice. Dropping the keyword outright
     * was too much, though: `unevaluatedProperties` and `additionalProperties` are defined in terms
     * of WHICH keys `properties` declares, so without it a declared, perfectly valid key counted as
     * unevaluated — `{"known":"a"}` against `properties: {known}, unevaluatedProperties: false` was
     * rejected with `has unevaluated property "known"`.
     *
     * Each declared property therefore keeps its key and loses its rules: the bookkeeping keywords
     * still see the name, and validating a value against an empty schema produces nothing.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function withPropertyRulesOwnedByTheDto(array $constraints): array
    {
        $properties = $constraints['properties'] ?? null;
        if (!is_array($properties)) {
            return $this->withoutKeyword($constraints, 'properties');
        }

        $names = [];
        foreach (array_keys($properties) as $propertyName) {
            $names[(string)$propertyName] = [];
        }
        $constraints['properties'] = $names;

        return $constraints;
    }
}
