<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Contract\OpenApiConstraintValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\RequestDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class RequestDeserializerService implements RequestDeserializerInterface
{
    private OpenApiConstraintValidatorInterface $constraintValidator;
    private ValidationMessageProviderInterface $messageProvider;
    private OpenApiFormatRegistry $formatRegistry;

    /**
     * @param array<string, string> $messageOverrides
     */
    public function __construct(
        ?OpenApiConstraintValidatorInterface $constraintValidator = null,
        ?ValidationMessageProviderInterface $messageProvider = null,
        array $messageOverrides = [],
        ?OpenApiFormatRegistry $formatRegistry = null,
    )
    {
        $this->messageProvider = $messageProvider ?? new ValidationMessageProvider($messageOverrides);
        $this->formatRegistry = $formatRegistry ?? new OpenApiFormatRegistry();
        $this->constraintValidator = $constraintValidator ?? new OpenApiConstraintValidator(
            $this->messageProvider,
            $messageOverrides,
            $this->formatRegistry,
        );
    }

    /**
     * @template T
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::DTO_HAS_NO_CONSTRUCTOR, ['dtoClass' => $dtoClass]));
        }

        $params = $constructor->getParameters();
        $args = [];
        $providedParams = [];
        $errors = [];
        $constraintsByField = $this->resolveOpenApiConstraints($reflection);

        foreach ($params as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();
            $hasDefaultValue = $param->isDefaultValueAvailable();
            $fieldConstraints = $constraintsByField[$paramName] ?? null;
            $openApiFormat = $this->resolveOpenApiFormat($fieldConstraints);

            $allowsNull = $paramType?->allowsNull() ?? false;
            $schemaAllowsNull = $this->resolveSchemaAllowsNull($reflection, $paramName, $allowsNull);

            $typeNames = [];
            if ($paramType instanceof ReflectionNamedType) {
                $typeNames[] = $paramType->getName();
            } elseif ($paramType instanceof ReflectionUnionType) {
                foreach ($paramType->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType) {
                        continue;
                    }
                    if ($unionType->getName() === 'null') {
                        continue;
                    }
                    $typeNames[] = $unionType->getName();
                }
            } else {
                throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAMETER_HAS_UNSUPPORTED_TYPE, [
                    'paramName' => $paramName,
                    'dtoClass' => $dtoClass,
                ]));
            }

            if ($typeNames === []) {
                $typeNames[] = 'mixed';
            }

            $arrayItemType = in_array('array', $typeNames, true) ? $this->resolveArrayItemType($reflection, $paramName) : null;

            // If parameter is absent in request and constructor has a default value,
            // keep constructor default instead of forcing null.
            $rawSource = '';
            $rawWasProvided = false;
            $this->extractRawValueFromRequest($request, $paramName, $rawWasProvided, $rawSource);
            if (!$rawWasProvided && $hasDefaultValue) {
                $args[] = $param->getDefaultValue();
                $providedParams[] = $paramName;
                continue;
            }

            // Try to get value from request (body, query, path, files)
            $wasProvided = false;
            try {
                if (count($typeNames) === 1) {
                    $singleType = $typeNames[0];
                    $temporalFormat = $singleType === DateTimeImmutable::class
                        ? $this->resolveTemporalFormat($reflection, $paramName, $openApiFormat)
                        : null;

                    $value = $this->extractValueFromRequest(
                        $request,
                        $paramName,
                        $singleType,
                        $allowsNull,
                        $wasProvided,
                        $arrayItemType,
                        $paramName,
                        $schemaAllowsNull,
                        $temporalFormat,
                        $openApiFormat,
                    );
                } else {
                    $value = $this->extractUnionValueFromRequest(
                        $request,
                        $paramName,
                        $typeNames,
                        $allowsNull,
                        $wasProvided,
                        $arrayItemType,
                        $schemaAllowsNull,
                        $reflection,
                        $openApiFormat,
                    );
                }
            } catch (RuntimeException $e) {
                // Collect errors and use null as placeholder so we can continue validating other params
                foreach (explode("\n", $e->getMessage()) as $msg) {
                    $errors[] = $msg;
                }
                $args[] = null;
                continue;
            }

            if (!$wasProvided && $hasDefaultValue) {
                $value = $param->getDefaultValue();
                $wasProvided = true;
            }

            $args[] = $value;

            $constraints = $fieldConstraints;
            if ($wasProvided && is_array($constraints) && $constraints !== []) {
                foreach ($this->constraintValidator->validate(sprintf('param "%s"', $paramName), $value, $constraints) as $constraintError) {
                    $errors[] = $constraintError;
                }
            }

            if ($wasProvided) {
                $providedParams[] = $paramName;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        $dto = $reflection->newInstanceArgs($args);

        // Mark fields as provided in request
        foreach ($providedParams as $paramName) {
            $markMethodName = 'markAs' . ucfirst($paramName) . 'ProvidedInRequest';
            if ($reflection->hasMethod($markMethodName)) {
                $markMethod = $reflection->getMethod($markMethodName);
                $markMethod->invoke($dto);
            }
        }

        return $dto;
    }

    private function extractValueFromRequest(
        Request $request,
        string $paramName,
        string $typeName,
        bool $allowsNull,
        bool &$wasProvided,
        ?string $arrayItemType = null,
        ?string $paramPath = null,
        bool $schemaAllowsNull = false,
        ?string $temporalFormat = null,
        ?string $openApiFormat = null,
    ): mixed {
        $paramPath ??= $paramName;
        // Check in request body (JSON)
        $bodyData = $this->getBodyData($request);
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            return $this->castValue($bodyData[$paramName], $paramName, $typeName, $allowsNull, 'json', $arrayItemType, $paramPath, $schemaAllowsNull, $temporalFormat, $openApiFormat);
        }

        // Check in query parameters
        if ($request->query->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->query->get($paramName), $paramName, $typeName, $allowsNull, 'query', $arrayItemType, $paramPath, openApiFormat: $openApiFormat);
        }

        // Check in route parameters (path)
        if ($request->attributes->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->attributes->get($paramName), $paramName, $typeName, $allowsNull, 'path', $arrayItemType, $paramPath, openApiFormat: $openApiFormat);
        }

        // Check in uploaded files
        if ($typeName === UploadedFile::class && $request->files->has($paramName)) {
            $wasProvided = true;
            return $request->files->get($paramName);
        }

        // Check in multipart form data
        if ($request->request->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->request->get($paramName), $paramName, $typeName, $allowsNull, 'form', $arrayItemType, $paramPath, openApiFormat: $openApiFormat);
        }

        // If nullable and not found, return null
        if ($allowsNull) {
            $wasProvided = false;
            return null;
        }

        throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::REQUIRED_PARAMETER_NOT_FOUND, ['paramName' => $paramName]));
    }

    /**
     * @param array<int, string> $typeNames
     */
    private function extractUnionValueFromRequest(
        Request $request,
        string $paramName,
        array $typeNames,
        bool $allowsNull,
        bool &$wasProvided,
        ?string $arrayItemType,
        bool $schemaAllowsNull,
        ReflectionClass $dtoReflection,
        ?string $openApiFormat,
    ): mixed {
        $source = '';
        $rawValue = $this->extractRawValueFromRequest($request, $paramName, $wasProvided, $source);

        if (!$wasProvided) {
            if ($allowsNull) {
                return null;
            }

            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::REQUIRED_PARAMETER_NOT_FOUND, ['paramName' => $paramName]));
        }

        $errors = [];
        foreach ($typeNames as $typeName) {
            $temporalFormat = $typeName === DateTimeImmutable::class
                ? $this->resolveTemporalFormat($dtoReflection, $paramName, $openApiFormat)
                : null;

            try {
                return $this->castValue(
                    $rawValue,
                    $paramName,
                    $typeName,
                    false,
                    $source,
                    $arrayItemType,
                    $paramName,
                    $schemaAllowsNull,
                    $temporalFormat,
                    $openApiFormat,
                );
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new RuntimeException(implode("\n", array_values(array_unique($errors))));
    }

    private function extractRawValueFromRequest(Request $request, string $paramName, bool &$wasProvided, string &$source): mixed
    {
        $bodyData = $this->getBodyData($request);
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            $source = 'json';
            return $bodyData[$paramName];
        }

        if ($request->query->has($paramName)) {
            $wasProvided = true;
            $source = 'query';
            return $request->query->get($paramName);
        }

        if ($request->attributes->has($paramName)) {
            $wasProvided = true;
            $source = 'path';
            return $request->attributes->get($paramName);
        }

        if ($request->files->has($paramName)) {
            $wasProvided = true;
            $source = 'files';
            return $request->files->get($paramName);
        }

        if ($request->request->has($paramName)) {
            $wasProvided = true;
            $source = 'form';
            return $request->request->get($paramName);
        }

        $wasProvided = false;
        $source = '';
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getBodyData(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $contentType = $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            // Decode without assoc flag so JSON objects become stdClass,
            // allowing us to distinguish {} (object) from [] (array).
            $decoded = json_decode($content, false);
            if (!($decoded instanceof \stdClass)) {
                return [];
            }
            return $this->stdClassToArray($decoded);
        }

        return [];
    }

    /**
     * Converts stdClass tree to array, but keeps stdClass leaves as-is
     * so castValue can detect JSON objects vs JSON arrays.
     *
     * @return array<string, mixed>
     */
    private function stdClassToArray(\stdClass $obj): array
    {
        $result = [];
        foreach ((array) $obj as $key => $value) {
            if ($value instanceof \stdClass) {
                $result[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->normalizeArrayValues($value);
                continue;
            }

            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    private function normalizeArrayValues(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if ($v instanceof \stdClass) {
                // Keep as stdClass so castValue can flag it as object
                $arr[$k] = $v;
            } elseif (is_array($v)) {
                $arr[$k] = $this->normalizeArrayValues($v);
            }
        }
        return $arr;
    }

    private function castValue(
        mixed $value,
        string $paramName,
        string $typeName,
        bool $allowsNull,
        string $source,
        ?string $arrayItemType = null,
        ?string $paramPath = null,
        bool $schemaAllowsNull = false,
        ?string $temporalFormat = null,
        ?string $openApiFormat = null,
    ): mixed {
        $paramPath ??= $paramName;
        if ($value === null) {
            if ($source === 'json') {
                // Explicit null in JSON body: only allowed if schema declares nullable: true
                if ($schemaAllowsNull) {
                    return null;
                }
                throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_TYPE, [
                    'paramPath' => $paramPath,
                    'expectedType' => $typeName,
                    'actualType' => 'null',
                ]));
            }
            if ($allowsNull) {
                return null;
            }
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::CANNOT_CAST_NULL_TO_NON_NULLABLE_TYPE, ['typeName' => $typeName]));
        }

        if ($openApiFormat !== null && $this->formatRegistry->has($openApiFormat)) {
            return $this->formatRegistry->deserialize($openApiFormat, $value, $typeName, $paramPath, $allowsNull);
        }

        // JSON should stay strict: no implicit scalar conversions.
        if ($source === 'json') {
            if ($typeName === 'int') {
                if (!is_int($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'int', $value));
                }
                return $value;
            }

            if ($typeName === 'float') {
                if (!is_float($value) && !is_int($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'float', $value));
                }
                return (float) $value;
            }

            if ($typeName === 'string') {
                if (!is_string($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'string', $value));
                }
                return $value;
            }

            if ($typeName === 'bool') {
                if (!is_bool($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'bool', $value));
                }
                return $value;
            }

            if ($typeName === 'array') {
                if ($value instanceof \stdClass) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'array', 'object'));
                }

                if (!is_array($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'array', $value));
                }

                if (!array_is_list($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'array', 'object'));
                }

                if ($arrayItemType === null) {
                    return $value;
                }

                $normalized = [];
                $errors = [];
                foreach ($value as $index => $itemValue) {
                    $itemPath = $paramPath . '.' . $index;

                    try {
                        $normalized[] = $this->castArrayItemValue($itemValue, $arrayItemType, $itemPath);
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                if ($errors !== []) {
                    throw new RuntimeException(implode("\n", $errors));
                }

                return $normalized;
            }
        }

        // Handle scalar types
        if ($typeName === 'int') {
            if (!$this->isStrictIntValue($value)) {
                throw new RuntimeException($this->expectsTypeMessage($paramPath, 'int', $value));
            }

            return (int) $value;
        }

        if ($typeName === 'float') {
            if (!$this->isStrictFloatValue($value)) {
                throw new RuntimeException($this->expectsTypeMessage($paramPath, 'float', $value));
            }

            return (float) $value;
        }

        if ($typeName === 'string') {
            return (string) $value;
        }

        if ($typeName === 'bool') {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            }
            return (bool) $value;
        }

        if ($typeName === 'array') {
            return is_array($value) ? $value : [$value];
        }

        // Handle DateTimeImmutable
        if ($typeName === DateTimeImmutable::class) {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if (is_string($value)) {
                return $this->parseDateTimeStrict($value, $paramPath ?? $paramName, $temporalFormat);
            }
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DATE_STRING, [
                'paramPath' => $paramPath ?? $paramName,
                'actualType' => $this->getTypeString($value),
            ]));
        }

        // Handle UploadedFile
        if ($typeName === UploadedFile::class) {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::EXPECTED_UPLOADED_FILE));
        }

        // Handle enums (PHP 8.1+)
        if (enum_exists($typeName)) {
            return $this->castToEnum($value, $typeName, $paramPath ?? $paramName);
        }

        // Handle nested DTOs
        if (class_exists($typeName)) {
            if ($value instanceof \stdClass) {
                $value = $this->stdClassToArray($value);
            }
            if (is_array($value)) {
                $targetDtoClass = $this->resolveDiscriminatorTargetClass($typeName, $value, $paramPath ?? $paramName) ?? $typeName;
                // Recursively deserialize nested DTO
                $nestedRequest = $this->createRequestFromArray($value);
                return $this->deserialize($nestedRequest, $targetDtoClass);
            }
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::CANNOT_DESERIALIZE_NESTED_DTO_FROM_NON_ARRAY, ['typeName' => $typeName]));
        }

        throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::UNSUPPORTED_TYPE, ['typeName' => $typeName]));
    }

    private function castArrayItemValue(mixed $itemValue, string $arrayItemType, string $itemPath): mixed
    {
        if (in_array($arrayItemType, ['int', 'float', 'string', 'bool', 'array'], true)) {
            return $this->castValue($itemValue, $itemPath, $arrayItemType, false, 'json', null, $itemPath);
        }

        if (enum_exists($arrayItemType)) {
            return $this->castToEnum($itemValue, $arrayItemType, $itemPath);
        }

        if (class_exists($arrayItemType)) {
            if ($itemValue instanceof \stdClass) {
                $itemValue = $this->stdClassToArray($itemValue);
            }
            if (!is_array($itemValue)) {
                throw new RuntimeException($this->expectsTypeMessage($itemPath, 'object', $itemValue));
            }

            try {
                $nestedRequest = $this->createRequestFromArray($itemValue);
                return $this->deserialize($nestedRequest, $arrayItemType);
            } catch (RuntimeException $e) {
                throw new RuntimeException($this->prependParamPath($e->getMessage(), $itemPath), previous: $e);
            }
        }

        return $itemValue;
    }

    private function prependParamPath(string $message, string $prefix): string
    {
        if (preg_match('/^param "([^"]+)"\s+expects\s+/', $message, $matches) === 1) {
            return str_replace(
                sprintf('param "%s"', $matches[1]),
                sprintf('param "%s.%s"', $prefix, $matches[1]),
                $message,
            );
        }

        return $message;
    }

    /**
     * Determines whether the schema explicitly allows null for this field.
     *
     * PHP uses ?Type for both:
     *   1. Fields with nullable: true in the schema (explicit null is valid)
     *   2. Optional (non-required) fields (field absent is ok, but null is NOT valid)
     *
     * We distinguish them by checking isXxxRequired(): if the field is required AND
     * nullable in PHP, it means the schema has nullable: true. If it is not required,
     * the nullable PHP type is only to represent "absent" as null internally.
     */
    /**
     * Parses a date/time string strictly according to the schema format.
     * Rejects empty strings and strings that don't match the expected format.
     */
    private function parseDateTimeStrict(string $value, string $paramPath, ?string $temporalFormat): DateTimeImmutable
    {
        if ($value === '') {
            $hint = $this->temporalFormatHint($temporalFormat);
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_EMPTY_STRING, [
                'paramPath' => $paramPath,
                'formatHint' => $hint,
            ]));
        }

        // date format: only Y-m-d
        if ($temporalFormat === 'Y-m-d') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DATE_IN_FORMAT, [
                    'paramPath' => $paramPath,
                    'format' => 'Y-m-d',
                    'example' => '2026-03-10',
                    'value' => $value,
                ]));
            }
            return $dt;
        }

        // date-time / datetime: RFC3339 / ISO8601 — must have at least date+time
        if ($temporalFormat !== null) {
            // Try RFC3339 with offset (most strict)
            $dt = DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value)
                ?: DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);

            if ($dt === false) {
                throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME, [
                    'paramPath' => $paramPath,
                    'example' => '2026-03-10T12:00:00+00:00',
                    'value' => $value,
                ]));
            }

            return $dt;
        }

        // No format hint — try generic parse but reject "now"-like strings
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME_GENERIC, [
                'paramPath' => $paramPath,
                'value' => $value,
            ]));
        }

        return $dt;
    }

    private function temporalFormatHint(?string $temporalFormat): string
    {
        return match ($temporalFormat) {
            'Y-m-d' => ' in Y-m-d format',
            default => '-time',
        };
    }

    /**
     * Reads the temporal format for a DateTimeImmutable field from the getter docblock.
     * Looks for "Expected format: ..." comment generated by the DTO generator.
     */
    private function resolveTemporalFormat(ReflectionClass $reflection, string $paramName, ?string $openApiFormat = null): ?string
    {
        if ($openApiFormat === 'date') {
            return 'Y-m-d';
        }

        if ($openApiFormat === 'date-time' || $openApiFormat === 'datetime') {
            return 'c';
        }

        $getterName = 'get' . ucfirst($paramName);
        if (!$reflection->hasMethod($getterName)) {
            return null;
        }

        $docComment = $reflection->getMethod($getterName)->getDocComment();
        if ($docComment === false) {
            return null;
        }

        if (preg_match('/Expected format:\s*(.+)/i', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $constraints
     */
    private function resolveOpenApiFormat(?array $constraints): ?string
    {
        $format = $constraints['format'] ?? null;

        return is_string($format) && $format !== '' ? $format : null;
    }

    private function resolveSchemaAllowsNull(ReflectionClass $reflection, string $paramName, bool $phpAllowsNull): bool
    {
        if (!$phpAllowsNull) {
            return false;
        }

        // Check isXxxRequired() on the DTO instance would require constructing it first.
        // Instead, read the method body via reflection to see if it returns true/false.
        $requiredMethodName = 'is' . ucfirst($paramName) . 'Required';
        if (!$reflection->hasMethod($requiredMethodName)) {
            // No method available — fall back to allowing null if PHP allows it
            return $phpAllowsNull;
        }

        // Parse the method source to detect `return true;`
        $method = $reflection->getMethod($requiredMethodName);
        $filename = $method->getFileName();
        if ($filename === false) {
            return $phpAllowsNull;
        }

        $lines = file($filename);
        if ($lines === false) {
            return $phpAllowsNull;
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $isRequired = str_contains($body, 'return true;');

        // If required AND PHP-nullable → schema has nullable: true → null is valid
        // If not required AND PHP-nullable → just optional → null is NOT valid in JSON
        return $isRequired && $phpAllowsNull;
    }

    private function resolveArrayItemType(ReflectionClass $reflection, string $paramName): ?string
    {
        if (!$reflection->hasProperty($paramName)) {
            return null;
        }

        $docComment = $reflection->getProperty($paramName)->getDocComment();
        if ($docComment === false) {
            return null;
        }

        if (preg_match('/array<\??([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)>/', $docComment, $matches) !== 1) {
            return null;
        }

        $rawType = ltrim($matches[1], '?');
        if (str_contains($rawType, '\\')) {
            return $rawType;
        }

        return $reflection->getNamespaceName() !== ''
            ? $reflection->getNamespaceName() . '\\' . $rawType
            : $rawType;
    }

    private function getTypeString(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            return 'object';
        }

        if (is_object($value)) {
            return 'object';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return 'array';
            }

            return 'object';
        }

        return match (gettype($value)) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            'NULL' => 'null',
            default => gettype($value),
        };
    }

    /**
     * @return object
     */
    private function castToEnum(mixed $value, string $enumClass, ?string $paramPath = null): object
    {
        $reflection = new ReflectionClass($enumClass);
        /** @var array<object> $cases */
        $cases = $reflection->getMethod('cases')->invoke(null);

        foreach ($cases as $case) {
            if ($case instanceof \BackedEnum && $case->value === $value) {
                return $case;
            }

            if ($case->name === $value) {
                return $case;
            }
        }

        $allowed = [];
        foreach ($cases as $case) {
            $allowed[] = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
        }

        if ($paramPath !== null) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_ENUM, [
                'paramPath' => $paramPath,
                'enumClass' => $enumClass,
                'value' => (string) $value,
                'allowed' => implode(', ', $allowed),
            ]));
        }

        throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::INVALID_ENUM_VALUE, [
            'value' => (string) $value,
            'enumClass' => $enumClass,
        ]));
    }

    /**
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

    /**
     * @param array<string, mixed> $value
     */
    private function resolveDiscriminatorTargetClass(string $baseClass, array $value, string $paramPath): ?string
    {
        if (!method_exists($baseClass, 'getDiscriminatorPropertyName') || !method_exists($baseClass, 'getDiscriminatorMapping')) {
            return null;
        }

        $discriminatorProperty = $baseClass::getDiscriminatorPropertyName();
        $mapping = $baseClass::getDiscriminatorMapping();

        if (!is_string($discriminatorProperty) || $discriminatorProperty === '' || !is_array($mapping) || $mapping === []) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::DTO_HAS_INVALID_DISCRIMINATOR_METADATA, ['baseClass' => $baseClass]));
        }

        $fullDiscriminatorPath = $paramPath . '.' . $discriminatorProperty;

        if (!array_key_exists($discriminatorProperty, $value)) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_WAS_NOT_PROVIDED, ['paramPath' => $fullDiscriminatorPath]));
        }

        $discriminatorValue = $value[$discriminatorProperty];
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DISCRIMINATOR_VALUE, [
                'paramPath' => $fullDiscriminatorPath,
                'actualType' => $this->getTypeString($discriminatorValue),
            ]));
        }

        $discriminatorKey = (string) $discriminatorValue;
        if (!array_key_exists($discriminatorKey, $mapping)) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_HAS_INVALID_DISCRIMINATOR_VALUE, [
                'paramPath' => $fullDiscriminatorPath,
                'value' => $discriminatorKey,
                'allowed' => implode(', ', array_keys($mapping)),
            ]));
        }

        $targetClass = $mapping[$discriminatorKey];
        if (!is_string($targetClass) || !class_exists($targetClass)) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::DISCRIMINATOR_MAPPING_UNKNOWN_CLASS, [
                'paramPath' => $fullDiscriminatorPath,
                'targetClass' => (string) $targetClass,
            ]));
        }

        if (!is_a($targetClass, $baseClass, true)) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::DISCRIMINATOR_MAPPING_MUST_EXTEND, [
                'targetClass' => $targetClass,
                'baseClass' => $baseClass,
            ]));
        }

        return $targetClass;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRequestFromArray(array $data): Request
    {
        // Create a minimal request from array data
        $request = new Request();
        $request->initialize([], [], [], [], [], [], json_encode($data));
        $request->headers->set('Content-Type', 'application/json');
        return $request;
    }

    private function expectsTypeMessage(string $paramPath, string $expectedType, mixed $value): string
    {
        $actualType = is_string($value) && in_array($value, ['int', 'float', 'string', 'bool', 'array', 'object', 'null'], true)
            ? $value
            : $this->getTypeString($value);

        return $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_TYPE, [
            'paramPath' => $paramPath,
            'expectedType' => $expectedType,
            'actualType' => $actualType,
        ]);
    }

    private function isStrictIntValue(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[+-]?\d+$/', $value) === 1;
    }

    private function isStrictFloatValue(mixed $value): bool
    {
        if (is_float($value) || is_int($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[+-]?(?:\d+\.\d+|\d+|\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1;
    }
}
