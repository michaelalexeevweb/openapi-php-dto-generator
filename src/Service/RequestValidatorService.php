<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;
use OpenapiPhpDtoGenerator\Exception\RequestValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
     * @throws RequestValidationException
     */
    public function validate(Request $request, string $dtoClass): object
    {
        $errors = [];

        try {
            $dto = $this->deserializer->deserialize($request, $dtoClass);
        } catch (\Throwable $e) {
            throw new RequestValidationException('Failed to deserialize request: ' . $e->getMessage());
        }

        // Validate DTO
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $dto;
        }

        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            if (!$paramType instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $paramType->getName();
            $allowsNull = $paramType->allowsNull();

            // Get value from DTO
            $getterMethod = 'get' . ucfirst($paramName);
            if (!$reflection->hasMethod($getterMethod)) {
                continue;
            }

            $getter = $reflection->getMethod($getterMethod);
            $value = $getter->invoke($dto);

            // Validate type
            $error = $this->validateValue($value, $typeName, $allowsNull, $paramName);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw new RequestValidationException(implode("\n", $errors));
        }

        return $dto;
    }

    private function validateValue(mixed $value, string $expectedType, bool $allowsNull, string $fieldName): ?string
    {
        // Null check
        if ($value === null) {
            if (!$allowsNull) {
                return sprintf('Field "%s" cannot be null.', $fieldName);
            }
            return null;
        }

        // Type validation
        return match ($expectedType) {
            'int' => is_int($value) ? null : sprintf('Field "%s" must be an integer, got %s.', $fieldName, $this->getTypeString($value)),
            'float' => is_float($value) || is_int($value) ? null : sprintf('Field "%s" must be a float, got %s.', $fieldName, $this->getTypeString($value)),
            'string' => is_string($value) ? null : sprintf('Field "%s" must be a string, got %s.', $fieldName, $this->getTypeString($value)),
            'bool' => is_bool($value) ? null : sprintf('Field "%s" must be a boolean, got %s.', $fieldName, $this->getTypeString($value)),
            'array' => is_array($value) ? null : sprintf('Field "%s" must be an array, got %s.', $fieldName, $this->getTypeString($value)),
            DateTimeImmutable::class => $value instanceof DateTimeImmutable ? null : sprintf('Field "%s" must be a DateTimeImmutable, got %s.', $fieldName, $this->getTypeString($value)),
            UploadedFile::class => $value instanceof UploadedFile ? null : sprintf('Field "%s" must be an UploadedFile, got %s.', $fieldName, $this->getTypeString($value)),
            default => $this->validateObject($value, $expectedType, $fieldName),
        };
    }

    private function validateObject(mixed $value, string $expectedType, string $fieldName): ?string
    {
        // Check if it's an enum
        if (enum_exists($expectedType)) {
            if ($value instanceof $expectedType) {
                return null;
            }
            return sprintf('Field "%s" must be an instance of enum %s, got %s.', $fieldName, $expectedType, $this->getTypeString($value));
        }

        // Check if it's a class
        if (class_exists($expectedType)) {
            if ($value instanceof $expectedType) {
                return null;
            }
            return sprintf('Field "%s" must be an instance of %s, got %s.', $fieldName, $expectedType, $this->getTypeString($value));
        }

        // Unknown type - skip validation
        return null;
    }

    private function getTypeString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return gettype($value);
    }
}

