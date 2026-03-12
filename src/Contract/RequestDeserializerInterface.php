<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use Symfony\Component\HttpFoundation\Request;

interface RequestDeserializerInterface
{
    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object;
}

