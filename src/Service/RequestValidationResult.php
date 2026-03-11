<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

/**
 * @template T of object
 */
final class RequestValidationResult
{
    /** @var T|null */
    private ?object $dto = null;
    /** @var array<string> */
    private array $errors = [];

    private function __construct()
    {
    }

    /**
     * @template U of object
     * @param U $dto
     * @return self<U>
     */
    public static function success(object $dto): self
    {
        $result = new self();
        $result->dto = $dto;
        return $result;
    }

    /**
     * @template U of object
     * @param array<string> $errors
     * @return self<U>
     */
    public static function failure(array $errors): self
    {
        $result = new self();
        $result->errors = $errors;
        return $result;
    }

    public function isValid(): bool
    {
        return $this->dto !== null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return T
     * @throws \RuntimeException if validation failed
     */
    public function getDto(): object
    {
        if ($this->dto === null) {
            throw new \RuntimeException('Cannot get DTO from failed validation result.');
        }

        return $this->dto;
    }

    /**
     * @return T|null
     */
    public function getDtoOrNull(): ?object
    {
        return $this->dto;
    }

    /**
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    public function getErrorsAsString(string $separator = "\n"): string
    {
        return implode($separator, $this->errors);
    }
}
