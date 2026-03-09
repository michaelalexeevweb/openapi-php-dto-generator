<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Exception\RequestValidationException;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidationService
{
    private RequestValidatorService $validator;

    public function __construct(?RequestValidatorService $validator = null)
    {
        $this->validator = $validator ?? new RequestValidatorService();
    }

    /**
     * Validates request and returns result with DTO or errors.
     *
     * @template T
     * @param class-string<T> $dtoClass
     */
    public function validate(Request $request, string $dtoClass): RequestValidationResult
    {
        try {
            $dto = $this->validator->validate($request, $dtoClass);
            return RequestValidationResult::success($dto);
        } catch (RequestValidationException $e) {
            $errors = explode("\n", $e->getMessage());
            return RequestValidationResult::failure(array_filter($errors));
        }
    }

    /**
     * Validates request and returns DTO or throws exception.
     *
     * @template T
     * @param class-string<T> $dtoClass
     * @return T
     * @throws RequestValidationException
     */
    public function validateOrThrow(Request $request, string $dtoClass): object
    {
        return $this->validator->validate($request, $dtoClass);
    }
}
