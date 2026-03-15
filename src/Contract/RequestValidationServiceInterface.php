<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use OpenapiPhpDtoGenerator\Service\RequestValidationResult;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

interface RequestValidationServiceInterface
{
    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return RequestValidationResult<T>
     */
    public function validate(Request $request, string $dtoClass): RequestValidationResult;

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     * @throws BadRequestException
     */
    public function validateOrThrow(Request $request, string $dtoClass): object;
}
