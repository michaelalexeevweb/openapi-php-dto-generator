<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

final class RequestValidationResult
{
    private ?object $dto = null;
    /** @var array<string> */
    private array $errors = [];

    private function __construct()
    {
    }

    public static function success(object $dto): self
    {
        $result = new self();
        $result->dto = $dto;
        return $result;
    }

    /**
     * @param array<string> $errors
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
     * @template T
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
     * @template T
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
