<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use Symfony\Component\HttpFoundation\File\File;

final class DtoValidator implements DtoValidatorInterface
{
    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    public function validate(string $subject, mixed $value, array $constraints): array
    {
        if ($constraints === [] || $value === null) {
            return [];
        }

        $value = $this->normalizeTemporalValueForValidation($value, $constraints);

        if (array_key_exists('oneOf', $constraints) && is_array($constraints['oneOf'])) {
            return $this->validateUnionBranches(
                subject: $subject,
                value: $value,
                branches: $constraints['oneOf'],
                isOneOf: true,
            );
        }

        if (array_key_exists('anyOf', $constraints) && is_array($constraints['anyOf'])) {
            return $this->validateUnionBranches(
                subject: $subject,
                value: $value,
                branches: $constraints['anyOf'],
                isOneOf: false,
            );
        }

        $errors = [];

        $hasNumericConstraints = array_any(
            ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
            static fn(string $key): bool => array_key_exists($key, $constraints),
        );

        if ((is_int($value) || is_float($value)) && $hasNumericConstraints) {
            $errors = [
                ...$errors,
                ...$this->validateNumeric(subject: $subject, value: (float)$value, constraints: $constraints)
            ];
        }

        $hasStringConstraints = array_any(
            ['minLength', 'maxLength', 'pattern', 'format'],
            static fn(string $key): bool => array_key_exists($key, $constraints),
        );

        if (is_string($value) && $hasStringConstraints) {
            $errors = [
                ...$errors,
                ...$this->validateString(subject: $subject, value: $value, constraints: $constraints)
            ];
        }

        $hasArrayConstraints = array_any(
            ['minItems', 'maxItems', 'uniqueItems'],
            static fn(string $key): bool => array_key_exists($key, $constraints),
        );

        if (is_array($value) && $hasArrayConstraints) {
            $errors = [
                ...$errors,
                ...$this->validateArray(subject: $subject, value: $value, constraints: $constraints)
            ];
        }

        if (array_key_exists('format', $constraints) && $constraints['format'] === 'binary' && !is_string(
                $value,
            ) && !$value instanceof File) {
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
            'date-time', 'datetime' => $value->format(DateTimeInterface::ATOM),
            default => $value->format(DateTimeInterface::ATOM),
        };
    }

    /**
     * @param array<int, mixed> $branches
     * @return array<string>
     */
    private function validateUnionBranches(string $subject, mixed $value, array $branches, bool $isOneOf): array
    {
        $matched = 0;
        $errors = [];

        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            if (!$this->matchesOpenApiType(value: $value, type: $branch['type'] ?? null)) {
                continue;
            }

            $matched++;
            $branchConstraints = $branch;
            unset($branchConstraints['oneOf'], $branchConstraints['anyOf']);

            $branchErrors = $this->validate(subject: $subject, value: $value, constraints: $branchConstraints);
            if ($branchErrors === []) {
                if (!$isOneOf) {
                    return [];
                }
                continue;
            }

            $errors = [...$errors, ...$branchErrors];
        }

        if ($isOneOf) {
            if ($matched === 1 && $errors === []) {
                return [];
            }

            if ($matched > 1 && $errors === []) {
                return [
                    "{$subject} matches more than one allowed oneOf branch"
                ];
            }
        } else {
            if ($matched > 0 && $errors === []) {
                return [];
            }
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        $kind = $isOneOf ? 'oneOf' : 'anyOf';
        return [
            "{$subject} does not match any {$kind} branch"
        ];
    }

    private function matchesOpenApiType(mixed $value, mixed $type): bool
    {
        if (!is_string($type) || $type === '') {
            return true;
        }

        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
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
            $regex = '/' . str_replace('/', '\\/', $pattern) . '/u';
            if (!$this->isValidRegex($regex)) {
                $errors[] = "{$subject} has invalid regex pattern in schema: {$pattern}";
            } elseif (preg_match($regex, $value) !== 1) {
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
     * @param array<mixed> $value
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateArray(string $subject, array $value, array $constraints): array
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
                                $item,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
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

        return $errors;
    }

    private function isValidStringFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date' => $this->isValidDateFormat(value: $value),
            'date-time', 'datetime' => $this->isValidDateTimeFormat(value: $value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match(
                    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
                    $value,
                ) === 1,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'hostname' => filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'byte' => $this->isValidBase64(value: $value),
            'password' => true,
            'binary' => true,
            default => true,
        };
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

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        return $date->format(DateTimeInterface::ATOM) === $value;
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

    private function isValidRegex(string $regex): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function toFloatOrNull(mixed $value): float|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }

    private function toIntOrNull(mixed $value): int|null
    {
        if (!is_numeric($value)) {
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
}
