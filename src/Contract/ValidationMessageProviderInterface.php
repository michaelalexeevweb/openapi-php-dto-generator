<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

interface ValidationMessageProviderInterface
{
    /**
     * @param array<string, scalar|null> $parameters
     */
    public function format(string $key, array $parameters = []): string;
}

