<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OpenApiConstraintValidator
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

        if (isset($constraints['oneOf']) && is_array($constraints['oneOf'])) {
            return $this->validateUnionBranches($subject, $value, $constraints['oneOf'], true);
        }

        if (isset($constraints['anyOf']) && is_array($constraints['anyOf'])) {
            return $this->validateUnionBranches($subject, $value, $constraints['anyOf'], false);
        }

        $errors = [];

        if ((is_int($value) || is_float($value)) && (isset($constraints['minimum']) || isset($constraints['maximum']) || isset($constraints['exclusiveMinimum']) || isset($constraints['exclusiveMaximum']) || isset($constraints['multipleOf']))) {
            $errors = array_merge($errors, $this->validateNumeric($subject, (float) $value, $constraints));
        }

        if (is_string($value) && (isset($constraints['minLength']) || isset($constraints['maxLength']) || isset($constraints['pattern']) || isset($constraints['format']))) {
            $errors = array_merge($errors, $this->validateString($subject, $value, $constraints));
        }

        if (is_array($value) && (isset($constraints['minItems']) || isset($constraints['maxItems']) || isset($constraints['uniqueItems']))) {
            $errors = array_merge($errors, $this->validateArray($subject, $value, $constraints));
        }

        if (isset($constraints['format']) && $constraints['format'] === 'binary' && !is_string($value) && !$value instanceof UploadedFile && !$value instanceof File) {
            $errors[] = sprintf('%s expects binary data, got %s', $subject, $this->typeToOpenApi($value));
        }

        return $errors;
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

            if (!$this->matchesOpenApiType($value, $branch['type'] ?? null)) {
                continue;
            }

            $matched++;
            $branchConstraints = $branch;
            unset($branchConstraints['oneOf'], $branchConstraints['anyOf']);

            $branchErrors = $this->validate($subject, $value, $branchConstraints);
            if ($branchErrors === []) {
                if (!$isOneOf) {
                    return [];
                }
                continue;
            }

            $errors = array_merge($errors, $branchErrors);
        }

        if ($isOneOf) {
            if ($matched === 1 && $errors === []) {
                return [];
            }

            if ($matched > 1 && $errors === []) {
                return [sprintf('%s matches more than one allowed oneOf branch', $subject)];
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
        return [sprintf('%s does not match any %s branch', $subject, $kind)];
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
            'object' => is_array($value) && !array_is_list($value) || is_object($value),
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
        $exclusiveMaximum = $constraints['exclusiveMaximum'] ?? null;

        if (is_numeric($exclusiveMinimum)) {
            $minExclusive = (float) $exclusiveMinimum;
            if (!($value > $minExclusive)) {
                $errors[] = sprintf('%s must be greater than %s', $subject, $this->stringifyNumber($minExclusive));
            }
        } elseif ($minimum !== null) {
            if ($exclusiveMinimum === true) {
                if (!($value > $minimum)) {
                    $errors[] = sprintf('%s must be greater than %s', $subject, $this->stringifyNumber($minimum));
                }
            } elseif (!($value >= $minimum)) {
                $errors[] = sprintf('%s must be greater than or equal to %s', $subject, $this->stringifyNumber($minimum));
            }
        }

        if (is_numeric($exclusiveMaximum)) {
            $maxExclusive = (float) $exclusiveMaximum;
            if (!($value < $maxExclusive)) {
                $errors[] = sprintf('%s must be less than %s', $subject, $this->stringifyNumber($maxExclusive));
            }
        } elseif ($maximum !== null) {
            if ($exclusiveMaximum === true) {
                if (!($value < $maximum)) {
                    $errors[] = sprintf('%s must be less than %s', $subject, $this->stringifyNumber($maximum));
                }
            } elseif (!($value <= $maximum)) {
                $errors[] = sprintf('%s must be less than or equal to %s', $subject, $this->stringifyNumber($maximum));
            }
        }

        $multipleOf = $this->toFloatOrNull($constraints['multipleOf'] ?? null);
        if ($multipleOf !== null && $multipleOf > 0.0) {
            $ratio = $value / $multipleOf;
            if (abs($ratio - round($ratio)) > 1e-9) {
                $errors[] = sprintf('%s must be a multiple of %s', $subject, $this->stringifyNumber($multipleOf));
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

        $minLength = $this->toIntOrNull($constraints['minLength'] ?? null);
        if ($minLength !== null && $length < $minLength) {
            $errors[] = sprintf('%s length must be at least %d characters', $subject, $minLength);
        }

        $maxLength = $this->toIntOrNull($constraints['maxLength'] ?? null);
        if ($maxLength !== null && $length > $maxLength) {
            $errors[] = sprintf('%s length must be at most %d characters', $subject, $maxLength);
        }

        $pattern = $constraints['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $regex = '/' . str_replace('/', '\\/', $pattern) . '/u';
            if (@preg_match($regex, '') === false) {
                $errors[] = sprintf('%s has invalid regex pattern in schema: %s', $subject, $pattern);
            } elseif (preg_match($regex, $value) !== 1) {
                $errors[] = sprintf('%s must match pattern %s', $subject, $pattern);
            }
        }

        $format = $constraints['format'] ?? null;
        if (is_string($format) && !$this->isValidStringFormat($value, $format)) {
            $errors[] = sprintf('%s must match format %s', $subject, $format);
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

        $minItems = $this->toIntOrNull($constraints['minItems'] ?? null);
        if ($minItems !== null && $count < $minItems) {
            $errors[] = sprintf('%s must contain at least %d items', $subject, $minItems);
        }

        $maxItems = $this->toIntOrNull($constraints['maxItems'] ?? null);
        if ($maxItems !== null && $count > $maxItems) {
            $errors[] = sprintf('%s must contain at most %d items', $subject, $maxItems);
        }

        if (($constraints['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $fingerprint = is_scalar($item) || $item === null
                    ? 's:' . var_export($item, true)
                    : 'j:' . (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($item));

                if (isset($seen[$fingerprint])) {
                    $errors[] = sprintf('%s must contain unique items', $subject);
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
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) === 1,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'hostname' => filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'byte' => $this->isValidBase64($value),
            'password' => true,
            'binary' => true,
            default => true,
        };
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
        return is_numeric($value) ? (float) $value : null;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringifyNumber(float $value): string
    {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
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

