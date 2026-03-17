<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeImmutable;
use JsonException;
use JsonSerializable;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\DtoNormalizerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use UnitEnum;

final class DtoNormalizer implements DtoNormalizerInterface
{
    /** @var array<string, bool> */
    private const array INTERNAL_GETTERS = [
        'getModelName' => true,
    ];

    /** @var array<string, bool> */
    private const array INTERNAL_OUTPUT_FIELDS = [
        'modelName' => true,
    ];

    private OpenApiConstraintValidator $constraintValidator;

    /** @var array<class-string, ReflectionClass<object>> */
    private static array $reflectionCache = [];

    /**
     * @var array<class-string, array{
     *   getters: list<array{
     *     method: ReflectionMethod,
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>
     *   }>,
     *   backingProperties: array<string, ReflectionProperty|null>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * }>
     */
    private static array $classMetaCache = [];

    /** @var array<class-string, bool> */
    private static array $classHasGetterCache = [];

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
        $fast = $this->tryFastArray($dto);
        if ($fast !== null) {
            return $fast;
        }

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
        $fastJson = $this->tryFastJson($dto);
        if ($fastJson !== null) {
            return $fastJson;
        }

        $fastArray = $this->tryFastArray($dto);
        if ($fastArray !== null) {
            return json_encode($fastArray, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

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
     * @return array<string, mixed>|null
     */
    private function tryFastArray(object $dto): ?array
    {
        if (method_exists($dto, 'toArray')) {
            try {
                $result = $dto->toArray();
            } catch (Throwable) {
                return null;
            }

            return is_array($result) ? $result : null;
        }

        return $this->tryFastJsonSerializableArray($dto);
    }

    private function tryFastJson(object $dto): ?string
    {
        if (!method_exists($dto, 'toJson')) {
            return null;
        }

        try {
            $result = $dto->toJson();
        } catch (Throwable) {
            return null;
        }

        return is_string($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryFastJsonSerializableArray(object $dto): ?array
    {
        if (!$dto instanceof JsonSerializable) {
            return null;
        }

        try {
            $serialized = $dto->jsonSerialize();
        } catch (Throwable) {
            return null;
        }

        return is_array($serialized) ? $serialized : null;
    }

    /**
     * Returns a list of validation error messages (empty array = valid).
     *
     * @return array<string>
     */
    public function validate(object $dto): array
    {
        $errors = [];
        $meta = $this->getClassMeta($dto);

        foreach ($meta['getters'] as $getterMeta) {
            $method = $getterMeta['method'];
            $methodName = $getterMeta['methodName'];
            $propertyName = $getterMeta['propertyName'];
            $fieldName = $getterMeta['outputName'];

            try {
                $value = $method->invoke($dto);
                $value = $this->normalizeNullableTemporalValue($value, $getterMeta, $meta);

                $error = $this->validateValueAgainstTypes(
                    $value,
                    $getterMeta['typeNames'],
                    $getterMeta['allowsNull'],
                    $methodName,
                );
                if ($error !== null) {
                    $errors[] = $error;
                }

                $constraints = $meta['constraintsByField'][$propertyName] ?? null;
                if (is_array($constraints) && $constraints !== []) {
                    $errors = array_merge(
                        $errors,
                        $this->constraintValidator->validate(
                            sprintf('field "%s"', $fieldName),
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
        $meta = $this->getClassMeta($dto);

        foreach ($meta['getters'] as $getterMeta) {
            $method = $getterMeta['method'];
            $methodName = $getterMeta['methodName'];
            $propertyName = $getterMeta['propertyName'];
            $outputName = $getterMeta['outputName'];

            if ($this->isInternalOutputField($outputName)) {
                continue;
            }

            try {
                $value = $method->invoke($dto);
            } catch (LogicException $exception) {
                if (str_contains($exception->getMessage(), "wasn't provided in request")) {
                    $fallback = $this->tryReadBackingPropertyValue($dto, $propertyName, $meta);
                    if ($fallback['found']) {
                        $normalized = $this->normalizeValue($fallback['value']);
                        $result[$outputName] = $this->normalizeNullableTemporalValue($normalized, $getterMeta, $meta);
                    }
                }
                continue;
            } catch (Throwable) {
                continue;
            }

            try {
                $normalized = $this->normalizeValue($value);
                $result[$outputName] = $this->normalizeNullableTemporalValue($normalized, $getterMeta, $meta);
            } catch (Throwable) {
                $normalized = $this->normalizeValueFallback($value);
                $result[$outputName] = $this->normalizeNullableTemporalValue($normalized, $getterMeta, $meta);
            }
        }

        return $result;
    }

    /**
     * @param array{
     *   getters: list<array{
     *     method: ReflectionMethod,
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>
     *   }>,
     *   backingProperties: array<string, ReflectionProperty|null>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * } $meta
     * @return array{found: bool, value: mixed}
     */
    private function tryReadBackingPropertyValue(object $dto, string $propertyName, array $meta): array
    {
        $property = $meta['backingProperties'][$propertyName] ?? null;
        if (!$property instanceof ReflectionProperty) {
            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $property->getValue($dto)];
    }

    /**
     * @param array{propertyName: string, allowsNull: bool} $getterMeta
     * @param array{constraintsByField: array<string, array<string, mixed>>} $meta
     */
    private function normalizeNullableTemporalValue(mixed $value, array $getterMeta, array $meta): mixed
    {
        if ($value !== '') {
            return $value;
        }

        if (!$getterMeta['allowsNull']) {
            return $value;
        }

        $constraints = $meta['constraintsByField'][$getterMeta['propertyName']] ?? [];

        $format = $constraints['format'] ?? null;
        if (!is_string($format)) {
            return $value;
        }

        if (!in_array($format, ['date', 'date-time', 'datetime'], true)) {
            return $value;
        }

        return null;
    }

    private function isInternalOutputField(string $outputName): bool
    {
        return array_key_exists($outputName, self::INTERNAL_OUTPUT_FIELDS);
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
            $className = $value::class;
            if (!array_key_exists($className, self::$classHasGetterCache)) {
                $reflection = self::$reflectionCache[$className] ??= new ReflectionClass($value);
                $hasGetter = false;
                foreach ($reflection->getMethods() as $method) {
                    if (str_starts_with($method->getName(), 'get')) {
                        $hasGetter = true;
                        break;
                    }
                }
                self::$classHasGetterCache[$className] = $hasGetter;
            }

            if (self::$classHasGetterCache[$className]) {
                return $this->dtoToArray($value);
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

        if (array_key_exists($name, self::INTERNAL_GETTERS)) {
            return false;
        }

        return !$method->isStatic() && $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
    }

    /**
     * Map getter name to real DTO property while preserving all-caps names (e.g. getINTERNAL -> INTERNAL).
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolvePropertyNameFromGetter(ReflectionClass $reflection, string $methodName): string
    {
        $suffix = substr($methodName, 3);
        if ($suffix === '') {
            return '';
        }

        if ($reflection->hasProperty($suffix)) {
            return $suffix;
        }

        $lowerFirst = lcfirst($suffix);
        if ($reflection->hasProperty($lowerFirst)) {
            return $lowerFirst;
        }

        return $lowerFirst;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function resolveOpenApiPropertyAliases(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        $method = $this->resolveMetadataMethod($className, ['getAliases']);
        if (!is_callable($method)) {
            return [];
        }

        $aliases = call_user_func($method);
        if (!is_array($aliases)) {
            return [];
        }

        $result = [];
        foreach ($aliases as $propertyName => $openApiName) {
            if (!is_string($propertyName) || !is_string($openApiName)) {
                continue;
            }
            $result[$propertyName] = $openApiName;
        }

        return $result;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        $method = $this->resolveMetadataMethod($className, ['getConstraints']);
        if (!is_callable($method)) {
            return [];
        }

        $constraints = call_user_func($method);
        return is_array($constraints) ? $constraints : [];
    }

    /**
     * @param array<int, string> $candidateMethods
     * @return callable|null
     */
    private function resolveMetadataMethod(string $className, array $candidateMethods): callable|null
    {
        foreach ($candidateMethods as $methodName) {
            $callable = [$className, $methodName];
            if (is_callable($callable)) {
                return $callable;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   getters: list<array{
     *     method: ReflectionMethod,
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>
     *   }>,
     *   backingProperties: array<string, ReflectionProperty|null>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * }
     */
    private function getClassMeta(object $dto): array
    {
        $className = $dto::class;
        if (array_key_exists($className, self::$classMetaCache)) {
            return self::$classMetaCache[$className];
        }

        $reflection = self::$reflectionCache[$className] ??= new ReflectionClass($dto);
        $aliasesByProperty = $this->resolveOpenApiPropertyAliases($reflection);
        $constraintsByField = $this->resolveOpenApiConstraints($reflection);

        $getters = [];
        $backingProperties = [];

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();
            $propertyName = $this->resolvePropertyNameFromGetter($reflection, $methodName);
            $outputName = $aliasesByProperty[$propertyName] ?? $propertyName;

            $returnType = $method->getReturnType();
            $allowsNull = $returnType?->allowsNull() ?? true;
            $typeNames = [];

            if ($returnType instanceof ReflectionNamedType) {
                $typeNames[] = $returnType->getName();
            } elseif ($returnType instanceof ReflectionUnionType) {
                foreach ($returnType->getTypes() as $unionType) {
                    if ($unionType instanceof ReflectionNamedType) {
                        $typeNames[] = $unionType->getName();
                    }
                }
            }

            $backingProperty = null;
            if ($reflection->hasProperty($propertyName)) {
                $backingProperty = $reflection->getProperty($propertyName);
                if (!$backingProperty->isPublic()) {
                    $backingProperty->setAccessible(true);
                }
            }
            $backingProperties[$propertyName] = $backingProperty;

            $getters[] = [
                'method' => $method,
                'methodName' => $methodName,
                'propertyName' => $propertyName,
                'outputName' => $outputName,
                'allowsNull' => $allowsNull,
                'typeNames' => $typeNames,
            ];
        }

        return self::$classMetaCache[$className] = [
            'getters' => $getters,
            'backingProperties' => $backingProperties,
            'aliasesByProperty' => $aliasesByProperty,
            'constraintsByField' => $constraintsByField,
        ];
    }
}
