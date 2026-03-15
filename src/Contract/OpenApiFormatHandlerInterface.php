<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

interface OpenApiFormatHandlerInterface
{
    public function validate(string $subject, mixed $value): string|null;

    public function deserialize(mixed $value, string $typeName, string $paramPath, bool $allowsNull): mixed;
}
