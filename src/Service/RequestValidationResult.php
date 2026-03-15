<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\RequestValidationResultInterface;
use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;

/**
 * @template T of object
 * @implements RequestValidationResultInterface<T>
 */
final class RequestValidationResult implements RequestValidationResultInterface
{
    /** @var T|null */
    private object|null $dto = null;
    /** @var array<string> */
    private array $errors = [];
    private ValidationMessageProviderInterface $messageProvider;

    private function __construct(ValidationMessageProviderInterface|null $messageProvider = null)
    {
        $this->messageProvider = $messageProvider ?? new ValidationMessageProvider();
    }

    /**
     * @template U of object
     * @param U $dto
     * @return self<U>
     */
    public static function success(object $dto, ValidationMessageProviderInterface|null $messageProvider = null): self
    {
        /** @var self<U> $result */
        $result = new self($messageProvider);
        $result->dto = $dto;
        return $result;
    }

    /**
     * @param array<string> $errors
     * @return self<object>
     */
    public static function failure(array $errors, ValidationMessageProviderInterface|null $messageProvider = null): self
    {
        /** @var self<object> $result */
        $result = new self($messageProvider);
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
            throw new \RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::CANNOT_GET_DTO_FROM_FAILED_RESULT),
            );
        }

        return $this->dto;
    }

    /**
     * @return T|null
     */
    public function getDtoOrNull(): object|null
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

    public function getFirstError(): string|null
    {
        return $this->errors[0] ?? null;
    }

    public function getErrorsAsString(string $separator = "\n"): string
    {
        return implode($separator, $this->errors);
    }
}
