<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

interface RequestValidatorInterface
{
    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     * @throws BadRequestException
     */
    public function validate(Request $request, string $dtoClass): object;
}
