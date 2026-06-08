<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use Symfony\Component\HttpFoundation\File\File;

final class DtoValidator implements DtoValidatorInterface
{
    private const int MAX_VALIDATION_DEPTH = 256;

    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    public function validate(string $subject, mixed $value, array $constraints): array
    {
        return $this->validateConstraints($subject, $value, $constraints, 0);
    }

    /**
     * Recursion depth is threaded as a parameter (not stored on the instance) so a single
     * shared validator is safe under concurrency (Swoole/RoadRunner/FrankenPHP coroutines).
     *
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateConstraints(string $subject, mixed $value, array $constraints, int $depth): array
    {
        // Guard against pathologically nested schemas exhausting the stack.
        if ($depth >= self::MAX_VALIDATION_DEPTH) {
            return [sprintf('%s: schema nesting exceeds %d levels', $subject, self::MAX_VALIDATION_DEPTH)];
        }

        if ($constraints === []) {
            return [];
        }

        if ($value === null) {
            $nullable = ($constraints['nullable'] ?? false) === true;
            $typeConstraint = $constraints['type'] ?? null;
            if (
                $nullable
                || $typeConstraint === 'null'
                || (is_array($typeConstraint) && in_array('null', $typeConstraint, true))
            ) {
                return [];
            }
            // null not explicitly allowed: fall through so type/union checks can report errors
        }

        $value = $this->normalizeTemporalValueForValidation($value, $constraints);

        $errors = [];

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

        // enum: value must be strictly equal to one of the allowed values.
        if (array_key_exists('enum', $constraints) && is_array($constraints['enum'])) {
            if (!in_array($value, $constraints['enum'], true)) {
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

        // const: value must be strictly equal to the given constant.
        if (array_key_exists('const', $constraints)) {
            if ($value !== $constraints['const']) {
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
            if ($this->validateConstraints($subject, $value, $constraints['not'], $depth + 1) === []) {
                $errors[] = "{$subject} must not match the 'not' schema";
            }
        }

        // if/then/else: conditional schema application.
        if (array_key_exists('if', $constraints) && is_array($constraints['if'])) {
            if ($this->validateConstraints($subject, $value, $constraints['if'], $depth + 1) === []) {
                if (array_key_exists('then', $constraints) && is_array($constraints['then'])) {
                    $errors = [...$errors, ...$this->validateConstraints($subject, $value, $constraints['then'], $depth + 1)];
                }
            } else {
                if (array_key_exists('else', $constraints) && is_array($constraints['else'])) {
                    $errors = [...$errors, ...$this->validateConstraints($subject, $value, $constraints['else'], $depth + 1)];
                }
            }
        }

        // type: value must match the declared OpenAPI type.
        if (array_key_exists('type', $constraints)) {
            $typeConstraint = $constraints['type'];
            if (is_string($typeConstraint)) {
                if (!$this->matchesOpenApiType(value: $value, type: $typeConstraint)) {
                    $errors[] = "{$subject} must be of type {$typeConstraint}";
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

        if ((is_int($value) || is_float($value)) && $this->constraintsHave($constraints, 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf')) {
            $errors = [...$errors, ...$this->validateNumeric(subject: $subject, value: (float)$value, constraints: $constraints)];
        }

        if ((is_int($value) || is_float($value)) && is_string($constraints['format'] ?? null)) {
            $errors = [...$errors, ...$this->validateNumericFormat(subject: $subject, value: $value, format: $constraints['format'])];
        }

        if (is_string($value) && $this->constraintsHave($constraints, 'minLength', 'maxLength', 'pattern', 'format')) {
            $errors = [...$errors, ...$this->validateString(subject: $subject, value: $value, constraints: $constraints)];
        }

        if (is_array($value) && $this->constraintsHave($constraints, 'minItems', 'maxItems', 'uniqueItems', 'items', 'contains', 'minContains', 'maxContains', 'prefixItems')) {
            $errors = [...$errors, ...$this->validateArray(subject: $subject, value: $value, constraints: $constraints, depth: $depth)];
        }

        if (is_array($value) && $this->constraintsHave($constraints, 'minProperties', 'maxProperties', 'properties', 'additionalProperties', 'required', 'dependentRequired', 'dependentSchemas', 'patternProperties', 'propertyNames')) {
            $errors = [...$errors, ...$this->validateObjectConstraints(subject: $subject, value: $value, constraints: $constraints, depth: $depth)];
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
        return [
            "{$subject} does not match any {$kind} branch",
        ];
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
            'integer' => is_int($value) || ($value instanceof BackedEnum && is_int($value->value)),
            'number' => is_int($value) || is_float($value) || ($value instanceof BackedEnum && is_int($value->value)),
            'string' => is_string($value) || ($value instanceof BackedEnum && is_string($value->value)),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => (is_array($value) && !array_is_list($value)) || is_object($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateNumeric(string $subject, float $value, array $constraints): array
    {
        $errors = [];

        $minimum = $this->toFloatOrNull($constraints['minimum'] ?? null);
        $maximum = $this->toFloatOrNull($constraints['maximum'] ?? null);

        $exclusiveMinimum = $constraints['exclusiveMinimum'] ?? null;
        if (is_numeric($exclusiveMinimum)) {
            $minExclusive = (float)$exclusiveMinimum;
            if (!($value > $minExclusive)) {
                $errors[] = "{$subject} must be greater than {$this->stringifyNumber($minExclusive)}";
            }
        } elseif ($minimum !== null) {
            if (($constraints['exclusiveMinimum'] ?? null) === true) {
                if (!($value > $minimum)) {
                    $errors[] = "{$subject} must be greater than {$this->stringifyNumber($minimum)}";
                }
            } elseif (!($value >= $minimum)) {
                $errors[] = "{$subject} must be greater than or equal to {$this->stringifyNumber($minimum)}";
            }
        }

        $exclusiveMaximum = $constraints['exclusiveMaximum'] ?? null;
        if (is_numeric($exclusiveMaximum)) {
            $maxExclusive = (float)$exclusiveMaximum;
            if (!($value < $maxExclusive)) {
                $errors[] = "{$subject} must be less than {$this->stringifyNumber($maxExclusive)}";
            }
        } elseif ($maximum !== null) {
            if (($constraints['exclusiveMaximum'] ?? null) === true) {
                if (!($value < $maximum)) {
                    $errors[] = "{$subject} must be less than {$this->stringifyNumber($maximum)}";
                }
            } elseif (!($value <= $maximum)) {
                $errors[] = "{$subject} must be less than or equal to {$this->stringifyNumber($maximum)}";
            }
        }

        $multipleOf = $this->toFloatOrNull($constraints['multipleOf'] ?? null);
        if ($multipleOf !== null && $multipleOf > 0.0) {
            $ratio = $value / $multipleOf;
            if (abs($ratio - round($ratio)) > 1e-9) {
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
            default => [],
        };
    }

    /**
     * @return array<string>
     */
    private function validateIntegerFormat(string $subject, int|float $value, int $min, int $max, string $format): array
    {
        if (is_float($value) && (is_nan($value) || is_infinite($value) || floor($value) !== $value)) {
            return ["{$subject} must be an integer ({$format})"];
        }

        // A float can't represent the int64 boundary: (float)PHP_INT_MAX rounds up to 2^63,
        // so `$value > $max` misses an overflowing float. Reject any float at/beyond 2^63.
        if (is_float($value) && $value >= 9223372036854775808.0) {
            return ["{$subject} must be within {$format} range ({$min} to {$max})"];
        }

        if ($value < $min || $value > $max) {
            return ["{$subject} must be within {$format} range ({$min} to {$max})"];
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
            $regex = '#' . str_replace('#', '\\#', $pattern) . '#u';
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
        } elseif (is_array($additionalProperties) && $additionalProperties !== []) {
            foreach ($value as $key => $itemValue) {
                if ($isKnownKey((string)$key)) {
                    continue;
                }
                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        sprintf('%s.%s', $subject, (string)$key),
                        $itemValue,
                        $additionalProperties,
                        $depth + 1,
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
                if (is_scalar($item) || $item === null) {
                    $fingerprint = 's:' . var_export($item, true);
                } else {
                    try {
                        $fingerprint = 'j:' . json_encode(
                            value: $item,
                            flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        );
                    } catch (JsonException) {
                        $fingerprint = 'j:' . serialize($item);
                    }
                }

                if (array_key_exists($fingerprint, $seen)) {
                    $errors[] = "{$subject} must contain unique items";
                    break;
                }

                $seen[$fingerprint] = true;
            }
        }

        $itemConstraints = $constraints['items'] ?? null;
        if (is_array($itemConstraints) && $itemConstraints !== []) {
            // Per JSON Schema 2020-12, `items` is a suffix validator: when `prefixItems` is
            // also present it applies only to indices ≥ count(prefixItems). The prefix
            // positions are validated by their own `prefixItems` schemas below.
            $prefixCount = (array_key_exists('prefixItems', $constraints) && is_array($constraints['prefixItems']))
                ? count($constraints['prefixItems'])
                : 0;

            foreach ($value as $index => $itemValue) {
                if (is_int($index) && $index < $prefixCount) {
                    continue;
                }

                array_push(
                    $errors,
                    ...$this->validateConstraints(
                        sprintf('%s.%s', $subject, (string)$index),
                        $itemValue,
                        $itemConstraints,
                        $depth + 1,
                    ),
                );
            }
        }

        $containsSchema = $constraints['contains'] ?? null;
        if (is_array($containsSchema) && $containsSchema !== []) {
            $matchCount = 0;
            foreach ($value as $itemValue) {
                if ($this->validateConstraints($subject, $itemValue, $containsSchema, $depth + 1) === []) {
                    $matchCount++;
                }
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
                        sprintf('%s.%s', $subject, (string)$index),
                        $value[$index],
                        $itemSchema,
                        $depth + 1,
                    ),
                );
            }
        }

        return $errors;
    }

    private function isValidStringFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date' => $this->isValidDateFormat(value: $value),
            'date-time', 'datetime' => $this->isValidDateTimeFormat(value: $value),
            'time' => $this->isValidTimeFormat(value: $value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'idn-email' => filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false,
            'uuid' => $this->isValidUuid(value: $value),
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'iri' => $this->isValidIri(value: $value),
            'duration' => $this->isValidDuration(value: $value),
            'json-pointer' => $this->isValidJsonPointer(value: $value),
            'regex' => $this->isValidRegexFormat(value: $value),
            'hostname' => filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
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
        $regex = '#' . str_replace('#', '\\#', $pattern) . '#u';
        // Suppress warnings from an invalid schema pattern → treat as no match.
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($regex, $key) === 1;
        } finally {
            restore_error_handler();
        }
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

    private function isValidRegexFormat(string $value): bool
    {
        // The value itself must be a compilable regular expression. preg_match returns
        // false (not 0) when the pattern fails to compile. No `u` modifier: `format: regex`
        // only asks whether the pattern compiles, and forcing UTF-8 would reject otherwise
        // valid byte-oriented patterns.
        $regex = '#' . str_replace('#', '\\#', $value) . '#';
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

    private function stringifyNumber(float $value): string
    {
        if ((float)(int)$value === $value) {
            return (string)(int)$value;
        }

        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
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
     * @param array<string, mixed> $constraints
     */
    private function constraintsHave(array $constraints, string ...$keys): bool
    {
        // Plain foreach avoids allocating a closure on every call (this runs on the
        // numeric/string/array/object type-gate of every validated value).
        foreach ($keys as $key) {
            if (array_key_exists($key, $constraints)) {
                return true;
            }
        }

        return false;
    }
}
