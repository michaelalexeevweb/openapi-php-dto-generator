<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use OpenapiPhpDtoGenerator\Service\ValidationMessageKey;

interface ValidationMessageProviderInterface
{
    /**
     * @param array<string, scalar|null> $parameters
     */
    public function format(string|ValidationMessageKey $key, array $parameters = []): string;
}
