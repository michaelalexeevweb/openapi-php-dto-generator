<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\OpenApiConstraintValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OpenApiConstraintValidator implements OpenApiConstraintValidatorInterface
{
    private ValidationMessageProviderInterface $messageProvider;
    private OpenApiFormatRegistry $formatRegistry;

    /**
     * @param array<string, string> $messageOverrides
     */
    public function __construct(
        ?ValidationMessageProviderInterface $messageProvider = null,
        array $messageOverrides = [],
        ?OpenApiFormatRegistry $formatRegistry = null,
    )
    {
        $this->messageProvider = $messageProvider ?? new ValidationMessageProvider($messageOverrides);
        $this->formatRegistry = $formatRegistry ?? new OpenApiFormatRegistry();
    }

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
            $errors[] = $this->messageProvider->format(ValidationMessageKey::EXPECTS_BINARY_DATA, [
                'subject' => $subject,
                'actualType' => $this->typeToOpenApi($value),
            ]);
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

            foreach ($branchErrors as $branchError) {
                $errors[] = $branchError;
            }
        }

        if ($isOneOf) {
            if ($matched === 1 && $errors === []) {
                return [];
            }

            if ($matched > 1 && $errors === []) {
                return [$this->messageProvider->format(ValidationMessageKey::ONE_OF_MULTIPLE_MATCHES, ['subject' => $subject])];
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
        return [$this->messageProvider->format(ValidationMessageKey::UNION_NO_MATCHING_BRANCH, ['subject' => $subject, 'kind' => $kind])];
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
        $exclusiveMaximum = $constraints['exclusiveMaximum'] ?? null;

        if (is_numeric($exclusiveMinimum)) {
            $minExclusive = (float) $exclusiveMinimum;
            if (!($value > $minExclusive)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_GREATER_THAN, ['subject' => $subject, 'value' => $this->stringifyNumber($minExclusive)]);
            }
        } elseif ($minimum !== null) {
            if ($exclusiveMinimum === true) {
                if (!($value > $minimum)) {
                    $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_GREATER_THAN, ['subject' => $subject, 'value' => $this->stringifyNumber($minimum)]);
                }
            } elseif (!($value >= $minimum)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_GREATER_THAN_OR_EQUAL, ['subject' => $subject, 'value' => $this->stringifyNumber($minimum)]);
            }
        }

        if (is_numeric($exclusiveMaximum)) {
            $maxExclusive = (float) $exclusiveMaximum;
            if (!($value < $maxExclusive)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_LESS_THAN, ['subject' => $subject, 'value' => $this->stringifyNumber($maxExclusive)]);
            }
        } elseif ($maximum !== null) {
            if ($exclusiveMaximum === true) {
                if (!($value < $maximum)) {
                    $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_LESS_THAN, ['subject' => $subject, 'value' => $this->stringifyNumber($maximum)]);
                }
            } elseif (!($value <= $maximum)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_LESS_THAN_OR_EQUAL, ['subject' => $subject, 'value' => $this->stringifyNumber($maximum)]);
            }
        }

        $multipleOf = $this->toFloatOrNull($constraints['multipleOf'] ?? null);
        if ($multipleOf !== null && $multipleOf > 0.0) {
            $ratio = $value / $multipleOf;
            if (abs($ratio - round($ratio)) > 1e-9) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::NUMERIC_MUST_BE_MULTIPLE_OF, ['subject' => $subject, 'value' => $this->stringifyNumber($multipleOf)]);
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
            $errors[] = $this->messageProvider->format(ValidationMessageKey::STRING_LENGTH_MIN, ['subject' => $subject, 'value' => $minLength]);
        }

        $maxLength = $this->toIntOrNull($constraints['maxLength'] ?? null);
        if ($maxLength !== null && $length > $maxLength) {
            $errors[] = $this->messageProvider->format(ValidationMessageKey::STRING_LENGTH_MAX, ['subject' => $subject, 'value' => $maxLength]);
        }

        $pattern = $constraints['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $regex = '/' . str_replace('/', '\\/', $pattern) . '/u';
            if (!$this->isValidRegex($regex)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::STRING_INVALID_REGEX_PATTERN, ['subject' => $subject, 'pattern' => $pattern]);
            } elseif (preg_match($regex, $value) !== 1) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::STRING_MUST_MATCH_PATTERN, ['subject' => $subject, 'pattern' => $pattern]);
            }
        }

        $format = $constraints['format'] ?? null;
        if (is_string($format)) {
            if ($this->formatRegistry->has($format)) {
                $customError = $this->formatRegistry->validate($format, $subject, $value);
                if ($customError !== null) {
                    $errors[] = $customError;
                }
            } elseif (!$this->isValidStringFormat($value, $format)) {
                $errors[] = $this->messageProvider->format(ValidationMessageKey::STRING_MUST_MATCH_FORMAT, ['subject' => $subject, 'format' => $format]);
            }
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
            $errors[] = $this->messageProvider->format(ValidationMessageKey::ARRAY_MIN_ITEMS, ['subject' => $subject, 'value' => $minItems]);
        }

        $maxItems = $this->toIntOrNull($constraints['maxItems'] ?? null);
        if ($maxItems !== null && $count > $maxItems) {
            $errors[] = $this->messageProvider->format(ValidationMessageKey::ARRAY_MAX_ITEMS, ['subject' => $subject, 'value' => $maxItems]);
        }

        if (($constraints['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $fingerprint = is_scalar($item) || $item === null
                    ? 's:' . var_export($item, true)
                    : 'j:' . (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($item));

                if (isset($seen[$fingerprint])) {
                    $errors[] = $this->messageProvider->format(ValidationMessageKey::ARRAY_UNIQUE_ITEMS, ['subject' => $subject]);
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

    private function isValidRegex(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
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

