<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\RequestDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\RequestValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidatorService implements RequestValidatorInterface
{
    private RequestDeserializerInterface $deserializer;

    /**
     * @param array<string, string> $messageOverrides
     */
    public function __construct(
        RequestDeserializerInterface|null $deserializer = null,
        ValidationMessageProviderInterface|null $messageProvider = null,
        array $messageOverrides = [],
        OpenApiFormatRegistry|null $formatRegistry = null,
    ) {
        $messageProvider ??= new ValidationMessageProvider($messageOverrides);
        $this->deserializer = $deserializer ?? new RequestDeserializerService(
            messageProvider: $messageProvider,
            messageOverrides: $messageOverrides,
            formatRegistry: $formatRegistry,
        );
    }

    /**
     * @template T of object
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
