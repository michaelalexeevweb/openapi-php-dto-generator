<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

interface DtoValidatorInterface
{
    /**
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    public function validate(string $subject, mixed $value, array $constraints): array;
}
