<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use BackedEnum;
use JsonException;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\DtoNormalizerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use UnitEnum;

final class DtoNormalizer implements DtoNormalizerInterface
{
    private OpenApiConstraintValidator $constraintValidator;

    public function __construct(
        OpenApiConstraintValidator|null $constraintValidator = null,
        OpenApiFormatRegistry|null $formatRegistry = null,
    ) {
        $this->constraintValidator = $constraintValidator ?? new OpenApiConstraintValidator(
            formatRegistry: $formatRegistry,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $dto): array
    {
        return $this->dtoToArray($dto);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException if validation fails
     */
    public function validateAndNormalizeToArray(object $dto): array
    {
        $errors = $this->validate($dto);

        if ($errors !== []) {
            throw new RuntimeException('DTO validation failed: ' . implode(', ', $errors));
        }

        return $this->dtoToArray($dto);
    }

    /**
     * @throws JsonException
     */
    public function toJson(object $dto): string
    {
        return json_encode($this->dtoToArray($dto), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws RuntimeException if validation fails
     * @throws JsonException
     */
    public function validateAndNormalizeToJson(object $dto): string
    {
        $errors = $this->validate($dto);

        if ($errors !== []) {
            throw new RuntimeException('DTO validation failed: ' . implode(', ', $errors));
        }

        return json_encode($this->dtoToArray($dto), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Returns a list of validation error messages (empty array = valid).
     *
     * @return array<string>
     */
    public function validate(object $dto): array
    {
        $errors = [];
        $reflection = new ReflectionClass($dto);
        $constraintsByField = $this->resolveOpenApiConstraints($reflection);

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();
            $returnType = $method->getReturnType();

            if (!$returnType instanceof ReflectionNamedType && !$returnType instanceof ReflectionUnionType) {
                continue;
            }

            $allowsNull = $returnType->allowsNull();
            $typeNames = [];

            if ($returnType instanceof ReflectionNamedType) {
                $typeNames[] = $returnType->getName();
            } else {
                foreach ($returnType->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType) {
                        continue;
                    }
                    $typeNames[] = $unionType->getName();
                }
            }

            try {
                $value = $method->invoke($dto);

                $error = $this->validateValueAgainstTypes($value, $typeNames, $allowsNull, $methodName);
                if ($error !== null) {
                    $errors[] = $error;
                }

                $propertyName = lcfirst(substr($methodName, 3));
                $constraints = $constraintsByField[$propertyName] ?? null;
                if (is_array($constraints) && $constraints !== []) {
                    $errors = array_merge(
                        $errors,
                        $this->constraintValidator->validate(
                            sprintf('field "%s"', $propertyName),
                            $value,
                            $constraints,
                        ),
                    );
                }
            } catch (LogicException $exception) {
                if (str_contains($exception->getMessage(), "wasn't provided in request")) {
                    continue;
                }
                $errors[] = "Failed to call $methodName(): " . $exception->getMessage();
            } catch (Throwable $exception) {
                $errors[] = "Failed to call $methodName(): " . $exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function dtoToArray(object $dto): array
    {
        $result = [];
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();
            $propertyName = lcfirst(substr($methodName, 3));

            try {
                $value = $method->invoke($dto);
            } catch (LogicException $exception) {
                if (str_contains($exception->getMessage(), "wasn't provided in request")) {
                    $fallback = $this->tryReadBackingPropertyValue($dto, $propertyName);
                    if ($fallback['found']) {
                        $result[$propertyName] = $this->normalizeValue($fallback['value']);
                    }
                }
                continue;
            } catch (Throwable) {
                continue;
            }

            try {
                $result[$propertyName] = $this->normalizeValue($value);
            } catch (Throwable) {
                $result[$propertyName] = $this->normalizeValueFallback($value);
            }
        }

        return $result;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function tryReadBackingPropertyValue(object $dto, string $propertyName): array
    {
        $reflection = new ReflectionClass($dto);
        if (!$reflection->hasProperty($propertyName)) {
            return ['found' => false, 'value' => null];
        }

        $property = $reflection->getProperty($propertyName);
        if (!$property->isPublic()) {
            $property->setAccessible(true);
        }

        return ['found' => true, 'value' => $property->getValue($dto)];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn($item) => $this->normalizeValue($item), $value);
        }

        if ($value instanceof File) {
            return $this->normalizeFileValue($value);
        }

        // instanceof is cheaper than enum_exists(get_class(...))
        if ($value instanceof DateTimeImmutable) {
            return $value->format('c');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            $reflection = new ReflectionClass($value);

            foreach ($reflection->getMethods() as $method) {
                if (str_starts_with($method->getName(), 'get')) {
                    return $this->dtoToArray($value);
                }
            }

            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            return get_class($value);
        }

        return $value;
    }

    private function normalizeValueFallback(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'originalName' => $value->getClientOriginalName(),
                'clientMimeType' => $value->getClientMimeType(),
            ];
        }

        if ($value instanceof File) {
            return ['filename' => $value->getFilename()];
        }

        return null;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function normalizeFileValue(File $file): array
    {
        $mimeType = null;
        $size = null;

        try {
            $mimeType = $file->getMimeType();
        } catch (Throwable) {
        }

        try {
            $size = $file->getSize();
        } catch (Throwable) {
        }

        $result = [
            'filename' => $file->getFilename(),
            'mimeType' => $mimeType,
            'size' => $size,
        ];

        if ($file instanceof UploadedFile) {
            $result['originalName'] = $file->getClientOriginalName();
            $result['clientMimeType'] = $file->getClientMimeType();
        }

        return $result;
    }

    /**
     * @param array<int, string> $expectedTypes
     * @return string|null
     */
    private function validateValueAgainstTypes(
        mixed $value,
        array $expectedTypes,
        bool $allowsNull,
        string $methodName,
    ): string|null {
        if ($value === null) {
            if ($allowsNull) {
                return null;
            }

            return sprintf(
                'Method %s() returned null but type is non-nullable %s.',
                $methodName,
                implode('|', array_values(array_filter($expectedTypes, static fn(string $t): bool => $t !== 'null'))),
            );
        }

        $filtered = array_values(array_filter($expectedTypes, static fn(string $t): bool => $t !== 'null'));
        if ($filtered === []) {
            return null;
        }

        $errors = [];
        foreach ($filtered as $type) {
            $error = $this->validateValue($value, $type, $methodName);
            if ($error === null) {
                return null;
            }
            $errors[] = $error;
        }

        return implode(' | ', $errors);
    }

    // $value is guaranteed non-null by validateValueAgainstTypes
    private function validateValue(mixed $value, string $expectedType, string $methodName): string|null
    {
        return match ($expectedType) {
            'int' => is_int($value) ? null : "Method $methodName() must return int, got " . gettype($value),
            'float' => (is_float($value) || is_int(
                    $value,
                )) ? null : "Method $methodName() must return float, got " . gettype($value),
            'string' => is_string($value) ? null : "Method $methodName() must return string, got " . gettype($value),
            'bool' => is_bool($value) ? null : "Method $methodName() must return bool, got " . gettype($value),
            'array' => is_array($value) ? null : "Method $methodName() must return array, got " . gettype($value),
            default => $this->validateObject($value, $expectedType, $methodName),
        };
    }

    private function validateObject(mixed $value, string $expectedType, string $methodName): string|null
    {
        if ($value instanceof $expectedType) {
            return null;
        }

        // class_exists is cheaper than enum_exists; check it first
        if (!class_exists($expectedType) && !enum_exists($expectedType)) {
            return null;
        }

        if (enum_exists($expectedType)) {
            return "Method $methodName() must return enum $expectedType, got " . get_debug_type($value);
        }

        return "Method $methodName() must return instance of $expectedType, got " . get_debug_type($value);
    }

    private function isSerializableGetter(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        if (!str_starts_with($name, 'get') || $name === 'get') {
            return false;
        }

        return !$method->isStatic() && $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (!method_exists($className, 'getOpenApiConstraints')) {
            return [];
        }

        $constraints = $className::getOpenApiConstraints();
        return is_array($constraints) ? $constraints : [];
    }
}
