<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

interface OpenApiFormatRegistryInterface
{
    /**
     * Returns true if a handler for the given format is registered.
     */
    public function has(string $format): bool;

    /**
     * Validates a value against the registered handler for the given format.
     * Returns an error message string, or null if the value is valid.
     */
    public function validate(string $format, string $subject, mixed $value): string|null;

    /**
     * Deserializes a raw value using the handler registered for the given format.
     */
    public function deserialize(
        string $format,
        mixed $value,
        string $typeName,
        string $paramPath,
        bool $allowsNull,
    ): mixed;
}
