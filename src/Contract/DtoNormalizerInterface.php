<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use JsonException;
use RuntimeException;

interface DtoNormalizerInterface
{
    /**
     * Converts DTO to a plain array without any validation.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $dto): array;

    /**
     * Validates the DTO against its OpenAPI constraints, then converts it to a plain array.
     *
     * @return array<string, mixed>
     * @throws RuntimeException if validation fails
     */
    public function validateAndNormalizeToArray(object $dto): array;

    /**
     * Converts DTO to a JSON string without any validation.
     *
     * @throws JsonException
     */
    public function toJson(object $dto): string;

    /**
     * Validates the DTO against its OpenAPI constraints, then converts it to a JSON string.
     *
     * @throws RuntimeException if validation fails
     * @throws JsonException
     */
    public function validateAndNormalizeToJson(object $dto): string;
}

