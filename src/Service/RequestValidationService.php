<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\RequestValidationServiceInterface;
use OpenapiPhpDtoGenerator\Contract\RequestValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidationService implements RequestValidationServiceInterface
{
    private RequestValidatorInterface $validator;
    private ValidationMessageProviderInterface $messageProvider;

    public function __construct(
        RequestValidatorInterface|null $validator = null,
        ValidationMessageProviderInterface|null $messageProvider = null,
        array $messageOverrides = [],
        OpenApiFormatRegistry|null $formatRegistry = null,
    ) {
        $this->messageProvider = $messageProvider ?? new ValidationMessageProvider($messageOverrides);
        $this->validator = $validator ?? new RequestValidatorService(
            messageProvider: $this->messageProvider,
            messageOverrides: $messageOverrides,
            formatRegistry: $formatRegistry,
        );
    }

    /**
     * Validates request and returns result with DTO or errors.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return RequestValidationResult<T>
     */
    public function validate(Request $request, string $dtoClass): RequestValidationResult
    {
        try {
            $dto = $this->validator->validate($request, $dtoClass);
            return RequestValidationResult::success($dto, $this->messageProvider);
        } catch (BadRequestException $e) {
            $errors = explode("\n", $e->getMessage());
            /** @var RequestValidationResult<T> $result */
            $result = RequestValidationResult::failure(array_values(array_filter($errors)), $this->messageProvider);
            return $result;
        }
    }

    /**
     * Validates request and returns DTO or throws exception.
     *
     * @template T
     * @param class-string<T> $dtoClass
     * @return T
     * @throws BadRequestException
     */
    public function validateOrThrow(Request $request, string $dtoClass): object
    {
        return $this->validator->validate($request, $dtoClass);
    }
}
