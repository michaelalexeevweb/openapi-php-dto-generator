<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidatorService
{
    private RequestDeserializerService $deserializer;

    public function __construct(?RequestDeserializerService $deserializer = null)
    {
        $this->deserializer = $deserializer ?? new RequestDeserializerService();
    }

    /**
     * @template T
     * @param class-string<T> $dtoClass
     * @return T
     * @throws BadRequestException
     */
    public function validate(Request $request, string $dtoClass): object
    {
        try {
            return $this->deserializer->deserialize($request, $dtoClass);
        } catch (\Throwable $e) {
            throw new BadRequestException($e->getMessage(), previous: $e);
        }
    }
}
