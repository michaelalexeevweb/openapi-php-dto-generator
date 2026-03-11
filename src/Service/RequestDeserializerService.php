<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class RequestDeserializerService
{
    private OpenApiConstraintValidator $constraintValidator;

    public function __construct(?OpenApiConstraintValidator $constraintValidator = null)
    {
        $this->constraintValidator = $constraintValidator ?? new OpenApiConstraintValidator();
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
            throw new RuntimeException(sprintf('DTO %s has no constructor.', $dtoClass));
        }

        $params = $constructor->getParameters();
        $args = [];
        $providedParams = [];
        $errors = [];
        $constraintsByField = $this->resolveOpenApiConstraints($reflection);

        foreach ($params as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

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
                throw new RuntimeException(sprintf('Parameter $%s in %s has unsupported type.', $paramName, $dtoClass));
            }

            if ($typeNames === []) {
                $typeNames[] = 'mixed';
            }

            $arrayItemType = in_array('array', $typeNames, true) ? $this->resolveArrayItemType($reflection, $paramName) : null;

            // Try to get value from request (body, query, path, files)
            $wasProvided = false;
            try {
                if (count($typeNames) === 1) {
                    $singleType = $typeNames[0];
                    $temporalFormat = $singleType === DateTimeImmutable::class
                        ? $this->resolveTemporalFormat($reflection, $paramName)
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

            $args[] = $value;

            $constraints = $constraintsByField[$paramName] ?? null;
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
    ): mixed {
        $paramPath ??= $paramName;
        // Check in request body (JSON)
        $bodyData = $this->getBodyData($request);
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            return $this->castValue($bodyData[$paramName], $paramName, $typeName, $allowsNull, 'json', $arrayItemType, $paramPath, $schemaAllowsNull, $temporalFormat);
        }

        // Check in query parameters
        if ($request->query->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->query->get($paramName), $paramName, $typeName, $allowsNull, 'query', $arrayItemType, $paramPath);
        }

        // Check in route parameters (path)
        if ($request->attributes->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->attributes->get($paramName), $paramName, $typeName, $allowsNull, 'path', $arrayItemType, $paramPath);
        }

        // Check in uploaded files
        if ($typeName === UploadedFile::class && $request->files->has($paramName)) {
            $wasProvided = true;
            return $request->files->get($paramName);
        }

        // Check in multipart form data
        if ($request->request->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->request->get($paramName), $paramName, $typeName, $allowsNull, 'form', $arrayItemType, $paramPath);
        }

        // If nullable and not found, return null
        if ($allowsNull) {
            $wasProvided = false;
            return null;
        }

        throw new RuntimeException(sprintf('Required parameter "%s" not found in request.', $paramName));
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
    ): mixed {
        $source = '';
        $rawValue = $this->extractRawValueFromRequest($request, $paramName, $wasProvided, $source);

        if (!$wasProvided) {
            if ($allowsNull) {
                return null;
            }

            throw new RuntimeException(sprintf('Required parameter "%s" not found in request.', $paramName));
        }

        $errors = [];
        foreach ($typeNames as $typeName) {
            $temporalFormat = $typeName === DateTimeImmutable::class
                ? $this->resolveTemporalFormat($dtoReflection, $paramName)
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
            $result[$key] = $value instanceof \stdClass ? $value : (is_array($value) ? $this->normalizeArrayValues($value) : $value);
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
    ): mixed {
        $paramPath ??= $paramName;
        if ($value === null) {
            if ($source === 'json') {
                // Explicit null in JSON body: only allowed if schema declares nullable: true
                if ($schemaAllowsNull) {
                    return null;
                }
                throw new RuntimeException(sprintf('param "%s" expects %s, got null', $paramPath, $typeName));
            }
            if ($allowsNull) {
                return null;
            }
            throw new RuntimeException(sprintf('Cannot cast null to non-nullable type %s.', $typeName));
        }

        // JSON should stay strict: no implicit scalar conversions.
        if ($source === 'json') {
            if ($typeName === 'int') {
                if (!is_int($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects int, got %s', $paramPath, $this->getTypeString($value)));
                }
                return $value;
            }

            if ($typeName === 'float') {
                if (!is_float($value) && !is_int($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects float, got %s', $paramPath, $this->getTypeString($value)));
                }
                return (float) $value;
            }

            if ($typeName === 'string') {
                if (!is_string($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects string, got %s', $paramPath, $this->getTypeString($value)));
                }
                return $value;
            }

            if ($typeName === 'bool') {
                if (!is_bool($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects bool, got %s', $paramPath, $this->getTypeString($value)));
                }
                return $value;
            }

            if ($typeName === 'array') {
                if ($value instanceof \stdClass) {
                    throw new RuntimeException(sprintf('param "%s" expects array, got object', $paramPath));
                }

                if (!is_array($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects array, got %s', $paramPath, $this->getTypeString($value)));
                }

                if (!array_is_list($value)) {
                    throw new RuntimeException(sprintf('param "%s" expects array, got object', $paramPath));
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
            return (int) $value;
        }

        if ($typeName === 'float') {
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
            throw new RuntimeException(sprintf('param "%s" expects a date string, got %s', $paramPath ?? $paramName, $this->getTypeString($value)));
        }

        // Handle UploadedFile
        if ($typeName === UploadedFile::class) {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            throw new RuntimeException('Expected UploadedFile but got something else.');
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
            throw new RuntimeException(sprintf('Cannot deserialize nested DTO %s from non-array value.', $typeName));
        }

        throw new RuntimeException(sprintf('Unsupported type: %s', $typeName));
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
                throw new RuntimeException(sprintf('param "%s" expects object, got %s', $itemPath, $this->getTypeString($itemValue)));
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
            throw new RuntimeException(sprintf('param "%s" expects a valid date%s, got empty string', $paramPath, $hint));
        }

        // date format: only Y-m-d
        if ($temporalFormat === 'Y-m-d') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException(sprintf('param "%s" expects a date in Y-m-d format (e.g. 2026-03-10), got "%s"', $paramPath, $value));
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
                throw new RuntimeException(sprintf(
                    'param "%s" expects a valid date-time (e.g. 2026-03-10T12:00:00+00:00), got "%s"',
                    $paramPath,
                    $value,
                ));
            }

            return $dt;
        }

        // No format hint — try generic parse but reject "now"-like strings
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('param "%s" expects a valid date/time, got "%s"', $paramPath, $value));
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
    private function resolveTemporalFormat(ReflectionClass $reflection, string $paramName): ?string
    {
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
            return array_is_list($value) ? 'array' : 'object';
        }

        return match (gettype($value)) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            'NULL' => 'null',
            default => gettype($value),
        };
    }

    private function castToEnum(mixed $value, string $enumClass, ?string $paramPath = null): object
    {
        $reflection = new ReflectionClass($enumClass);
        $cases = $reflection->getMethod('cases')->invoke(null);

        foreach ($cases as $case) {
            if (property_exists($case, 'value') && $case->value === $value) {
                return $case;
            }
            if ($case->name === $value) {
                return $case;
            }
        }

        $allowed = [];
        foreach ($cases as $case) {
            $allowed[] = property_exists($case, 'value') ? (string) $case->value : $case->name;
        }

        if ($paramPath !== null) {
            throw new RuntimeException(sprintf(
                'param "%s" expects enum %s, got "%s". Allowed: %s',
                $paramPath,
                $enumClass,
                (string) $value,
                implode(', ', $allowed),
            ));
        }

        throw new RuntimeException(sprintf('Invalid enum value "%s" for %s.', (string) $value, $enumClass));
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
            throw new RuntimeException(sprintf('DTO %s has invalid discriminator metadata.', $baseClass));
        }

        $fullDiscriminatorPath = $paramPath . '.' . $discriminatorProperty;

        if (!array_key_exists($discriminatorProperty, $value)) {
            throw new RuntimeException(sprintf('param "%s" wasn\'t provided', $fullDiscriminatorPath));
        }

        $discriminatorValue = $value[$discriminatorProperty];
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            throw new RuntimeException(sprintf(
                'param "%s" expects string|int discriminator value, got %s',
                $fullDiscriminatorPath,
                $this->getTypeString($discriminatorValue),
            ));
        }

        $discriminatorKey = (string) $discriminatorValue;
        if (!array_key_exists($discriminatorKey, $mapping)) {
            throw new RuntimeException(sprintf(
                'param "%s" has invalid discriminator value "%s". Allowed: %s',
                $fullDiscriminatorPath,
                $discriminatorKey,
                implode(', ', array_keys($mapping)),
            ));
        }

        $targetClass = $mapping[$discriminatorKey];
        if (!is_string($targetClass) || !class_exists($targetClass)) {
            throw new RuntimeException(sprintf('Discriminator mapping for "%s" points to unknown class "%s".', $fullDiscriminatorPath, (string) $targetClass));
        }

        if (!is_a($targetClass, $baseClass, true)) {
            throw new RuntimeException(sprintf('Discriminator mapping class %s must extend or implement %s.', $targetClass, $baseClass));
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
}
