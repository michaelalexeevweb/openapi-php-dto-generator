<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use RuntimeException;

/**
 * @template T of object
 */
interface RequestValidationResultInterface
{
    /**
     * Returns true if validation passed and a DTO is available.
     */
    public function isValid(): bool;

    /**
     * Returns true if there are validation error messages.
     */
    public function hasErrors(): bool;

    /**
     * Returns the validated DTO.
     *
     * @return T
     * @throws RuntimeException if validation failed
     */
    public function getDto(): object;

    /**
     * Returns the validated DTO, or null if validation failed.
     *
     * @return T|null
     */
    public function getDtoOrNull(): object|null;

    /**
     * Returns all validation error messages.
     *
     * @return array<string>
     */
    public function getErrors(): array;

    /**
     * Returns the first error message, or null if there are none.
     */
    public function getFirstError(): string|null;

    /**
     * Returns all error messages joined by the given separator.
     */
    public function getErrorsAsString(string $separator = "\n"): string;
}
