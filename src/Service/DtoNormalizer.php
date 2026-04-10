<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use JsonSerializable;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\DtoNormalizerInterface;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
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
        'getAliases' => true,
        'getConstraints' => true,
        'getNormalizationMap' => true,
        'getDiscriminatorPropertyName' => true,
        'getDiscriminatorMapping' => true,
    ];

    /** @var array<string, bool> */
    private const array INTERNAL_OUTPUT_FIELDS = [
        'modelName' => true,
    ];

    private(set) DtoValidatorInterface $constraintValidator;

    /**
     * @var array<class-string, array{
     *   getters: list<array{
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>,
     *     arrayItemTypeNames: list<string>
     *   }>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * }>
     */
    private static array $classMetaCache = [];

    public function __construct(
        DtoValidatorInterface|null $constraintValidator = null,
    ) {
        $this->constraintValidator = $constraintValidator ?? new DtoValidator();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(GeneratedDtoInterface $dto): array
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
    public function validateAndNormalizeToArray(GeneratedDtoInterface $dto): array
    {
        $this->validateOrThrow($dto);

        return $this->dtoToArray($dto);
    }

    /**
     * @throws JsonException
     */
    public function toJson(GeneratedDtoInterface $dto): string
    {
        return json_encode($this->toArray($dto), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws RuntimeException if validation fails
     * @throws JsonException
     */
    public function validateAndNormalizeToJson(GeneratedDtoInterface $dto): string
    {
        $this->validateOrThrow($dto);

        return json_encode($this->dtoToArray($dto), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws RuntimeException if validation fails
     */
    private function validateOrThrow(GeneratedDtoInterface $dto): void
    {
        $errors = $this->validate($dto);
        if ($errors !== []) {
            throw new RuntimeException('DTO validation failed: ' . implode(', ', $errors));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryFastArray(object $dto): ?array
    {
        if (!method_exists($dto, 'toArray')) {
            return $this->tryFastJsonSerializableArray($dto);
        }

        try {
            $result = $dto->toArray();
        } catch (Throwable) {
            return null;
        }

        if (!is_array($result)) {
            return null;
        }

        return $this->normalizeArrayPayload($result);
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

        if (!is_array($serialized)) {
            return null;
        }

        return $this->normalizeArrayPayload($serialized);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeArrayPayload(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            $result[$key] = $this->normalizeValue($value);
        }

        return $result;
    }

    /**
     * Returns a list of validation error messages (empty array = valid).
     *
     * @return array<string>
     */
    public function validate(GeneratedDtoInterface $dto): array
    {
        return $this->validateDtoRecursive(
            dto: $dto,
            pathPrefix: '',
            visited: [],
        );
    }

    /**
     * Returns a list of validation error messages (empty array = valid).
     *
     * @param array<int, true> $visited
     * @return array<string>
     */
    private function validateDtoRecursive(GeneratedDtoInterface $dto, string $pathPrefix, array $visited): array
    {
        $dtoId = spl_object_id($dto);
        if (array_key_exists($dtoId, $visited)) {
            return [];
        }
        $visited[$dtoId] = true;

        $errors = [];
        $meta = $this->getClassMeta($dto);

        foreach ($meta['getters'] as $getterMeta) {
            $methodName = $getterMeta['methodName'];
            $propertyName = $getterMeta['propertyName'];
            $fieldName = $getterMeta['outputName'];
            $scopedFieldName = $pathPrefix === '' ? $fieldName : $pathPrefix . '.' . $fieldName;

            try {
                $value = $this->invokeGetter($dto, $methodName);
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

                if (is_array($value) && $getterMeta['arrayItemTypeNames'] !== []) {
                    $errors = [
                        ...$errors,
                        ...$this->validateArrayItemsAgainstTypes(
                            value: $value,
                            fieldName: $scopedFieldName,
                            expectedItemTypes: $getterMeta['arrayItemTypeNames'],
                        ),
                    ];
                }

                if ($value instanceof GeneratedDtoInterface) {
                    $errors = [
                        ...$errors,
                        ...$this->validateDtoRecursive(
                            dto: $value,
                            pathPrefix: $scopedFieldName,
                            visited: $visited,
                        ),
                    ];
                } elseif (is_array($value)) {
                    foreach ($value as $index => $itemValue) {
                        if (!$itemValue instanceof GeneratedDtoInterface) {
                            continue;
                        }

                        $errors = [
                            ...$errors,
                            ...$this->validateDtoRecursive(
                                dto: $itemValue,
                                pathPrefix: $scopedFieldName . '.' . (string)$index,
                                visited: $visited,
                            ),
                        ];
                    }
                }

                $constraints = $meta['constraintsByField'][$propertyName] ?? null;
                if (is_array($constraints) && $constraints !== []) {
                    $errors = [
                        ...$errors,
                        ...$this->constraintValidator->validate(
                            subject: sprintf('field "%s"', $scopedFieldName),
                            value: $value,
                            constraints: $constraints,
                        ),
                    ];
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
            if ($this->isInternalOutputField($getterMeta['outputName'])) {
                continue;
            }

            try {
                $value = $this->invokeGetter($dto, $getterMeta['methodName']);
            } catch (LogicException $exception) {
                if (!str_contains($exception->getMessage(), "wasn't provided in request")) {
                    throw $exception;
                }
                continue;
            } catch (Throwable) {
                continue;
            }

            try {
                $normalized = $this->normalizeValue($value);
                $result[$getterMeta['outputName']] = $this->normalizeNullableTemporalValue(
                    $normalized,
                    $getterMeta,
                    $meta,
                );
            } catch (Throwable) {
                $normalized = $this->normalizeValueFallback($value);
                $result[$getterMeta['outputName']] = $this->normalizeNullableTemporalValue(
                    $normalized,
                    $getterMeta,
                    $meta,
                );
            }
        }

        return $result;
    }

    /**
     * @throws LogicException
     */
    private function invokeGetter(object $dto, string $methodName): mixed
    {
        if (!is_callable([$dto, $methodName])) {
            throw new LogicException(sprintf('Getter %s::%s() is not callable.', $dto::class, $methodName));
        }

        return $dto->{$methodName}();
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

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                $arrayValue = $value->toArray();
                if (is_array($arrayValue)) {
                    return $this->normalizeArrayPayload($arrayValue);
                }
            } catch (Throwable) {
            }
        }

        if ($value instanceof File) {
            return $this->normalizeFileValue($value);
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value)) {
            $meta = $this->getClassMeta($value);
            if ($meta['getters'] !== []) {
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
     */
    private function validateValueAgainstTypes(
        mixed $value,
        array $expectedTypes,
        bool $allowsNull,
        string $methodName,
    ): ?string {
        $filtered = array_values(array_filter($expectedTypes, static fn(string $t): bool => $t !== 'null'));

        if ($value === null) {
            if ($allowsNull) {
                return null;
            }

            return sprintf(
                'Method %s() returned null but type is non-nullable %s.',
                $methodName,
                implode('|', $filtered),
            );
        }

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

    private function validateValue(mixed $value, string $expectedType, string $methodName): ?string
    {
        return match ($expectedType) {
            'int' => is_int($value) ? null : "Method $methodName() must return int, got " . gettype($value),
            'float' => (is_float($value) || is_int(
                    $value,
                )) ? null : "Method $methodName() must return float, got " . gettype($value),
            'string' => is_string($value) ? null : "Method $methodName() must return string, got " . gettype($value),
            'bool' => is_bool($value) ? null : "Method $methodName() must return bool, got " . gettype($value),
            'array' => is_array($value) ? null : "Method $methodName() must return array, got " . gettype($value),
            'mixed' => null,
            default => $this->validateObject($value, $expectedType, $methodName),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $expectedItemTypes
     * @return list<string>
     */
    private function validateArrayItemsAgainstTypes(array $value, string $fieldName, array $expectedItemTypes): array
    {
        $errors = [];
        $allowsNull = in_array('null', $expectedItemTypes, true);

        foreach ($value as $index => $itemValue) {
            $error = $this->validateValueAgainstTypes(
                value: $itemValue,
                expectedTypes: $expectedItemTypes,
                allowsNull: $allowsNull,
                methodName: sprintf('%s.%s', $fieldName, (string)$index),
            );

            if ($error !== null) {
                $errors[] = sprintf('field "%s".%s %s', $fieldName, (string)$index, $this->normalizeItemTypeError($error));
            }
        }

        return $errors;
    }

    private function normalizeItemTypeError(string $error): string
    {
        $mustPos = strpos($error, ' must ');
        if ($mustPos !== false) {
            return substr($error, $mustPos + 1);
        }

        $returnedPos = strpos($error, ' returned ');
        if ($returnedPos !== false) {
            return substr($error, $returnedPos + 1);
        }

        return $error;
    }

    private function validateObject(mixed $value, string $expectedType, string $methodName): ?string
    {
        if ($value instanceof $expectedType) {
            return null;
        }

        if (!class_exists($expectedType) && !enum_exists($expectedType)) {
            return null;
        }

        if (enum_exists($expectedType)) {
            return "Method $methodName() must return enum $expectedType, got " . get_debug_type($value);
        }

        return "Method $methodName() must return instance of $expectedType, got " . get_debug_type($value);
    }

    private function isSerializableGetterName(string $name): bool
    {
        if (!str_starts_with($name, 'get') || $name === 'get') {
            return false;
        }

        return !array_key_exists($name, self::INTERNAL_GETTERS);
    }

    private function resolvePropertyNameFromGetterName(string $methodName): string
    {
        $suffix = substr($methodName, 3);

        return $suffix === '' ? '' : lcfirst($suffix);
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
     * @return array<string, string>
     */
    private function resolveOpenApiPropertyAliasesByClass(string $className): array
    {
        $method = $this->resolveMetadataMethod($className, ['getAliases']);
        if ($method === null) {
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
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraintsByClass(string $className): array
    {
        $method = $this->resolveMetadataMethod($className, ['getConstraints']);
        if ($method === null) {
            return [];
        }

        $constraints = call_user_func($method);
        return is_array($constraints) ? $constraints : [];
    }

    /**
     * @return array{
     *   getters: list<array{
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>,
     *     arrayItemTypeNames: list<string>
     *   }>,
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

        $mapMeta = $this->buildClassMetaFromNormalizationMap($className);
        if ($mapMeta !== null) {
            return self::$classMetaCache[$className] = $mapMeta;
        }

        return self::$classMetaCache[$className] = $this->buildClassMetaFromPublicGetters($className);
    }

    /**
     * @return array{
     *   getters: list<array{
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>,
     *     arrayItemTypeNames: list<string>
     *   }>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * }|null
     */
    private function buildClassMetaFromNormalizationMap(string $className): ?array
    {
        $mapCallable = [$className, 'getNormalizationMap'];
        if (!is_callable($mapCallable)) {
            return null;
        }

        $rawMap = call_user_func($mapCallable);
        if (!is_array($rawMap) || $rawMap === []) {
            return null;
        }

        $aliasesByProperty = $this->resolveOpenApiPropertyAliasesByClass($className);
        $constraintsByField = $this->resolveOpenApiConstraintsByClass($className);
        $getters = [];

        foreach ($rawMap as $propertyName => $row) {
            if (!is_string($propertyName) || !is_array($row)) {
                continue;
            }

            $methodName = $row['getter'] ?? null;
            if (!is_string($methodName) || $methodName === '' || !method_exists($className, $methodName)) {
                continue;
            }

            $type = $row['type'] ?? 'mixed';
            $typeNames = $this->normalizeTypeNamesFromMapType(
                className: $className,
                type: is_string($type) ? $type : 'mixed',
            );
            $nullable = $row['nullable'] ?? null;
            $allowsNull = is_bool($nullable) ? $nullable : true;
            $metadata = $row['metadata'] ?? [];
            $outputName = $aliasesByProperty[$propertyName] ?? $propertyName;

            if (is_array($metadata) && is_string($metadata['openApiName'] ?? null) && $metadata['openApiName'] !== '') {
                $outputName = $metadata['openApiName'];
            }

            $getters[] = [
                'methodName' => $methodName,
                'propertyName' => $propertyName,
                'outputName' => $outputName,
                'allowsNull' => $allowsNull,
                'typeNames' => $typeNames,
                'arrayItemTypeNames' => $this->resolveArrayItemTypeNames($className, $methodName),
            ];
        }

        if ($getters === []) {
            return null;
        }

        return [
            'getters' => $getters,
            'aliasesByProperty' => $aliasesByProperty,
            'constraintsByField' => $constraintsByField,
        ];
    }

    /**
     * @return array{
     *   getters: list<array{
     *     methodName: string,
     *     propertyName: string,
     *     outputName: string,
     *     allowsNull: bool,
     *     typeNames: list<string>,
     *     arrayItemTypeNames: list<string>
     *   }>,
     *   aliasesByProperty: array<string, string>,
     *   constraintsByField: array<string, array<string, mixed>>
     * }
     */
    private function buildClassMetaFromPublicGetters(string $className): array
    {
        $aliasesByProperty = $this->resolveOpenApiPropertyAliasesByClass($className);
        $constraintsByField = $this->resolveOpenApiConstraintsByClass($className);
        $methods = get_class_methods($className);
        $getters = [];

        foreach ($methods as $methodName) {
            if (!$this->isSerializableGetterName($methodName)) {
                continue;
            }

            $propertyName = $this->resolvePropertyNameFromGetterName($methodName);
            if ($propertyName === '') {
                continue;
            }

            $getters[] = [
                'methodName' => $methodName,
                'propertyName' => $propertyName,
                'outputName' => $aliasesByProperty[$propertyName] ?? $propertyName,
                'allowsNull' => true,
                'typeNames' => ['mixed'],
                'arrayItemTypeNames' => $this->resolveArrayItemTypeNames($className, $methodName),
            ];
        }

        return [
            'getters' => $getters,
            'aliasesByProperty' => $aliasesByProperty,
            'constraintsByField' => $constraintsByField,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeTypeNamesFromMapType(string $className, string $type): array
    {
        $parts = explode('|', $type);
        $result = [];

        foreach ($parts as $part) {
            $normalized = trim($part);
            if ($normalized === '') {
                continue;
            }

            $resolved = $this->normalizeDocTypeNameInClassContext($className, $normalized);
            if ($resolved === '') {
                continue;
            }

            $result[] = $resolved;
        }

        return $result === [] ? ['mixed'] : array_values(array_unique($result));
    }

    /**
     * @return list<string>
     */
    private function resolveArrayItemTypeNames(string $className, string $methodName): array
    {
        if (!method_exists($className, $methodName)) {
            return [];
        }

        $reflection = new \ReflectionMethod($className, $methodName);
        $docComment = $reflection->getDocComment();
        if (!is_string($docComment) || $docComment === '') {
            return [];
        }

        if (!preg_match('/@return\s+([^\n\r*]+)/', $docComment, $matches)) {
            return [];
        }

        $returnType = trim($matches[1]);
        if ($returnType === '') {
            return [];
        }

        preg_match_all('/(?:array|list)\s*<([^>]+)>/i', $returnType, $genericMatches);
        $genericParts = $genericMatches[1];
        if ($genericParts === []) {
            return [];
        }

        $result = [];
        foreach ($genericParts as $genericPart) {
            $valueTypePart = str_contains($genericPart, ',')
                ? substr($genericPart, (int)strrpos($genericPart, ',') + 1)
                : $genericPart;

            foreach (explode('|', $valueTypePart) as $rawTypeName) {
                $normalizedTypeName = $this->normalizeDocTypeNameInClassContext($className, trim($rawTypeName));
                if ($normalizedTypeName === '' || $normalizedTypeName === 'mixed') {
                    continue;
                }
                $result[] = $normalizedTypeName;
            }
        }

        return array_values(array_unique($result));
    }

    private function normalizeDocTypeNameInClassContext(string $className, string $typeName): string
    {
        $typeName = trim($typeName, " \t\n\r\0\x0B()");
        if ($typeName === '') {
            return '';
        }

        if (str_starts_with($typeName, '?')) {
            $typeName = substr($typeName, 1);
        }

        $lower = strtolower($typeName);
        $builtinMap = [
            'integer' => 'int',
            'boolean' => 'bool',
            'double' => 'float',
            'real' => 'float',
            'null' => 'null',
            'int' => 'int',
            'float' => 'float',
            'string' => 'string',
            'bool' => 'bool',
            'array' => 'array',
            'mixed' => 'mixed',
        ];

        if (array_key_exists($lower, $builtinMap)) {
            return $builtinMap[$lower];
        }

        if (in_array($lower, ['self', 'static', '$this'], true)) {
            return $className;
        }

        $trimmed = ltrim($typeName, '\\');
        if ($trimmed === '') {
            return '';
        }

        if (class_exists($trimmed) || enum_exists($trimmed) || interface_exists($trimmed)) {
            return $trimmed;
        }

        $namespace = '';
        $lastSlashPos = strrpos($className, '\\');
        if ($lastSlashPos !== false) {
            $namespace = substr($className, 0, $lastSlashPos);
        }

        if ($namespace !== '') {
            $candidate = $namespace . '\\' . $trimmed;
            if (class_exists($candidate) || enum_exists($candidate) || interface_exists($candidate)) {
                return $candidate;
            }
        }

        return $trimmed;
    }
}
