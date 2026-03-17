<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
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
use UnitEnum;

final class RequestDeserializerService implements RequestDeserializerInterface
{
    private const PREDECODED_BODY_ATTRIBUTE = '__opg_predecoded_body_data';

    private OpenApiConstraintValidatorInterface $constraintValidator;
    private ValidationMessageProviderInterface $messageProvider;
    private OpenApiFormatRegistry $formatRegistry;

    // -----------------------------------------------------------------------
    // Static per-class reflection caches (populated once, shared across all
    // instances and requests within the same PHP worker process).
    // -----------------------------------------------------------------------

    /** @var array<class-string, ReflectionClass<object>> */
    private static array $reflectionCache = [];

    /**
     * Comprehensive per-class DTO metadata: constructor params + inRequest flag properties.
     * Key: class-string
     *
     * @var array<class-string, array{
     *   hasConstructor: bool,
     *   params: list<array{
     *     name: string,
     *     requestFieldName: string,
     *     typeNames: list<string>,
     *     allowsNull: bool,
     *     hasDefaultValue: bool,
     *     defaultValue: mixed,
     *     schemaAllowsNull: bool,
     *     arrayItemType: string|null,
     *     openApiFormat: string|null,
     *     allowsAssociativeArray: bool,
     *     temporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *   }>,
     *   inRequestProperties: array<string, \ReflectionProperty|null>,
     * }>
     */
    private static array $dtoMetaCache = [];

    /**
     * Enum cases cache to avoid repeated reflection per castToEnum call.
     * Key: enum class-string → list of UnitEnum cases
     *
     * @var array<class-string, list<\UnitEnum>>
     */
    private static array $enumCasesCache = [];

    // -----------------------------------------------------------------------
    // Instance cache: last parsed request body (avoids re-parsing the same
    // JSON content multiple times within a single deserialization call, since
    // getBodyData() is invoked once per constructor parameter).
    // -----------------------------------------------------------------------

    private ?string $bodyDataCacheKey = null;
    /** @var array<string, mixed> */
    private array $bodyDataCacheValue = [];

    /**
     * @param array<string, string> $messageOverrides
     */
    public function __construct(
        OpenApiConstraintValidatorInterface|null $constraintValidator = null,
        ValidationMessageProviderInterface|null $messageProvider = null,
        array $messageOverrides = [],
        OpenApiFormatRegistry|null $formatRegistry = null,
    ) {
        $this->messageProvider = $messageProvider ?? new ValidationMessageProvider($messageOverrides);
        $this->formatRegistry = $formatRegistry ?? new OpenApiFormatRegistry();
        $this->constraintValidator = $constraintValidator ?? new OpenApiConstraintValidator(
            messageProvider: $this->messageProvider,
            messageOverrides: $messageOverrides,
            formatRegistry: $this->formatRegistry,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object
    {
        // Use cached ReflectionClass – avoids repeated object instantiation.
        $reflection = self::$reflectionCache[$dtoClass] ??= new ReflectionClass($dtoClass);

        // Build (or retrieve from cache) all class-level metadata so that the
        // expensive reflection + file-reading work is done only on the very first
        // deserialization of a given DTO class within this PHP worker process.
        if (!array_key_exists($dtoClass, self::$dtoMetaCache)) {
            self::$dtoMetaCache[$dtoClass] = $this->buildDtoMeta($reflection, $dtoClass);
        }
        $classMeta = self::$dtoMetaCache[$dtoClass];

        if (!$classMeta['hasConstructor']) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::DTO_HAS_NO_CONSTRUCTOR, ['dtoClass' => $dtoClass]),
            );
        }

        $args = [];
        $providedParams = [];
        $errors = [];

        foreach ($classMeta['params'] as $paramMeta) {
            $paramName = $paramMeta['name'];
            $requestFieldName = $paramMeta['requestFieldName'];
            $typeNames = $paramMeta['typeNames'];
            $allowsNull = $paramMeta['allowsNull'];
            $hasDefaultValue = $paramMeta['hasDefaultValue'];
            $schemaAllowsNull = $paramMeta['schemaAllowsNull'];
            $arrayItemType = $paramMeta['arrayItemType'];
            $openApiFormat = $paramMeta['openApiFormat'];
            $allowsAssociativeArray = $paramMeta['allowsAssociativeArray'];
            $temporalFormat = $paramMeta['temporalFormat'];
            $fieldConstraints = $paramMeta['fieldConstraints'];

            // If parameter is absent in request and constructor has a default value,
            // keep constructor default instead of forcing null.
            $rawSource = '';
            $rawWasProvided = false;
            $this->extractRawValueFromRequest($request, $requestFieldName, $rawWasProvided, $rawSource);
            if (!$rawWasProvided && $hasDefaultValue) {
                $args[] = $paramMeta['defaultValue'];
                $providedParams[] = $paramName;
                continue;
            }

            // Try to get value from request (body, query, path, files)
            $wasProvided = false;
            try {
                if (count($typeNames) === 1) {
                    $value = $this->extractValueFromRequest(
                        request: $request,
                        paramName: $requestFieldName,
                        typeName: $typeNames[0],
                        allowsNull: $allowsNull,
                        wasProvided: $wasProvided,
                        arrayItemType: $arrayItemType,
                        paramPath: $requestFieldName,
                        schemaAllowsNull: $schemaAllowsNull,
                        temporalFormat: $temporalFormat,
                        openApiFormat: $openApiFormat,
                        allowsAssociativeArray: $allowsAssociativeArray,
                    );
                } else {
                    $value = $this->extractUnionValueFromRequest(
                        request: $request,
                        paramName: $requestFieldName,
                        typeNames: $typeNames,
                        allowsNull: $allowsNull,
                        wasProvided: $wasProvided,
                        arrayItemType: $arrayItemType,
                        schemaAllowsNull: $schemaAllowsNull,
                        dtoReflection: $reflection,
                        openApiFormat: $openApiFormat,
                        allowsAssociativeArray: $allowsAssociativeArray,
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
                $value = $paramMeta['defaultValue'];
                $wasProvided = true;
            }

            $args[] = $value;

            if ($wasProvided && is_array($fieldConstraints) && $fieldConstraints !== []) {
                foreach (
                    $this->constraintValidator->validate(
                        sprintf('param "%s"', $requestFieldName),
                        $value,
                        $fieldConstraints,
                    ) as $constraintError
                ) {
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

        /** @var T $dto */
        $dto = $reflection->newInstanceArgs($args);

        // Mark fields as provided in request using pre-resolved (and already
        // setAccessible'd) ReflectionProperty objects from the metadata cache.
        foreach ($providedParams as $paramName) {
            $flagProperty = $classMeta['inRequestProperties'][$paramName] ?? null;
            if ($flagProperty !== null) {
                $flagProperty->setValue($dto, true);
            }
        }

        return $dto;
    }

    /**
     * Builds and returns the complete per-class metadata array used by deserialize().
     * Called exactly once per DTO class per PHP process; result is stored in $dtoMetaCache.
     *
     * @param ReflectionClass<object> $reflection
     * @return array{
     *   hasConstructor: bool,
     *   params: list<array{
     *     name: string,
     *     requestFieldName: string,
     *     typeNames: list<string>,
     *     allowsNull: bool,
     *     hasDefaultValue: bool,
     *     defaultValue: mixed,
     *     schemaAllowsNull: bool,
     *     arrayItemType: string|null,
     *     openApiFormat: string|null,
     *     allowsAssociativeArray: bool,
     *     temporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *   }>,
     *   inRequestProperties: array<string, \ReflectionProperty|null>,
     * }
     */
    private function buildDtoMeta(ReflectionClass $reflection, string $dtoClass): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return ['hasConstructor' => false, 'params' => [], 'inRequestProperties' => []];
        }

        $aliases = $this->resolveOpenApiPropertyAliases($reflection);
        $constraints = $this->resolveOpenApiConstraints($reflection);

        $params = [];
        $inRequestProperties = [];

        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $requestFieldName = $aliases[$paramName] ?? $paramName;
            $paramType = $param->getType();
            $hasDefaultValue = $param->isDefaultValueAvailable();
            $fieldConstraints = $constraints[$paramName] ?? null;
            $allowsAssociativeArray = $this->allowsAssociativeArray($fieldConstraints);
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
                // Unsupported intersection / no-type-hint param – surface the error immediately.
                throw new RuntimeException(
                    $this->messageProvider->format(ValidationMessageKey::PARAMETER_HAS_UNSUPPORTED_TYPE, [
                        'paramName' => $paramName,
                        'dtoClass' => $dtoClass,
                    ]),
                );
            }

            if ($typeNames === []) {
                $typeNames[] = 'mixed';
            }

            $arrayItemType = in_array('array', $typeNames, true)
                ? $this->resolveArrayItemType($reflection, $paramName)
                : null;

            // Pre-compute temporal format for DateTimeImmutable fields.
            $temporalFormat = in_array(DateTimeImmutable::class, $typeNames, true)
                ? $this->resolveTemporalFormat($reflection, $paramName, $openApiFormat)
                : null;

            // Pre-resolve and make accessible the inRequest flag property (if any).
            $flagPropName = $this->resolveInRequestFlagPropertyName($reflection, $paramName);
            $flagProperty = null;
            if ($flagPropName !== null) {
                $flagProperty = $reflection->getProperty($flagPropName);
                if (!$flagProperty->isPublic()) {
                    $flagProperty->setAccessible(true);
                }
            }
            $inRequestProperties[$paramName] = $flagProperty;

            $params[] = [
                'name' => $paramName,
                'requestFieldName' => $requestFieldName,
                'typeNames' => $typeNames,
                'allowsNull' => $allowsNull,
                'hasDefaultValue' => $hasDefaultValue,
                'defaultValue' => $hasDefaultValue ? $param->getDefaultValue() : null,
                'schemaAllowsNull' => $schemaAllowsNull,
                'arrayItemType' => $arrayItemType,
                'openApiFormat' => $openApiFormat,
                'allowsAssociativeArray' => $allowsAssociativeArray,
                'temporalFormat' => $temporalFormat,
                'fieldConstraints' => $fieldConstraints,
            ];
        }

        return [
            'hasConstructor' => true,
            'params' => $params,
            'inRequestProperties' => $inRequestProperties,
        ];
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
        bool $allowsAssociativeArray = false,
    ): mixed {
        $paramPath ??= $paramName;
        // Check in request body (JSON)
        $bodyData = $this->getBodyData($request);
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            return $this->castValue(
                $bodyData[$paramName],
                $paramName,
                $typeName,
                $allowsNull,
                'json',
                $arrayItemType,
                $paramPath,
                $schemaAllowsNull,
                $temporalFormat,
                $openApiFormat,
                $allowsAssociativeArray,
            );
        }

        // Check in query parameters
        if ($request->query->has($paramName)) {
            $wasProvided = true;
            return $this->castValue(
                $request->query->get($paramName),
                $paramName,
                $typeName,
                $allowsNull,
                'query',
                $arrayItemType,
                $paramPath,
                openApiFormat: $openApiFormat,
                allowsAssociativeArray: $allowsAssociativeArray,
            );
        }

        // Check in route parameters (path)
        if ($request->attributes->has($paramName)) {
            $wasProvided = true;
            return $this->castValue(
                $request->attributes->get($paramName),
                $paramName,
                $typeName,
                $allowsNull,
                'path',
                $arrayItemType,
                $paramPath,
                openApiFormat: $openApiFormat,
                allowsAssociativeArray: $allowsAssociativeArray,
            );
        }

        // Check in uploaded files
        if ($typeName === UploadedFile::class && $request->files->has($paramName)) {
            $wasProvided = true;
            return $request->files->get($paramName);
        }

        // Check in multipart form data
        if ($request->request->has($paramName)) {
            $wasProvided = true;
            return $this->castValue(
                $request->request->get($paramName),
                $paramName,
                $typeName,
                $allowsNull,
                'form',
                $arrayItemType,
                $paramPath,
                openApiFormat: $openApiFormat,
                allowsAssociativeArray: $allowsAssociativeArray,
            );
        }

        // If nullable and not found, return null
        if ($allowsNull) {
            $wasProvided = false;
            return null;
        }

        throw new RuntimeException(
            $this->messageProvider->format(
                ValidationMessageKey::REQUIRED_PARAMETER_NOT_FOUND,
                ['paramName' => $paramName],
            ),
        );
    }

    /**
     * @param array<int, string> $typeNames
     * @param ReflectionClass<object> $dtoReflection
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
        bool $allowsAssociativeArray,
    ): mixed {
        $source = '';
        $rawValue = $this->extractRawValueFromRequest($request, $paramName, $wasProvided, $source);

        if (!$wasProvided) {
            if ($allowsNull) {
                return null;
            }

            throw new RuntimeException(
                $this->messageProvider->format(
                    ValidationMessageKey::REQUIRED_PARAMETER_NOT_FOUND,
                    ['paramName' => $paramName],
                ),
            );
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
                    $allowsAssociativeArray,
                );
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new RuntimeException(implode("\n", array_values(array_unique($errors))));
    }

    private function extractRawValueFromRequest(
        Request $request,
        string $paramName,
        bool &$wasProvided,
        string &$source,
    ): mixed {
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
        $preDecoded = $request->attributes->get(self::PREDECODED_BODY_ATTRIBUTE);
        if (is_array($preDecoded)) {
            return $preDecoded;
        }

        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $contentType = (string)$request->headers->get('Content-Type', '');
        $cacheKey = $contentType . "\n" . $content;

        // Fast path for repeated reads of the same request body.
        if ($cacheKey === $this->bodyDataCacheKey) {
            return $this->bodyDataCacheValue;
        }

        if (!str_contains($contentType, 'application/json')) {
            $this->bodyDataCacheKey = $cacheKey;
            $this->bodyDataCacheValue = [];
            return [];
        }

        // Decode without assoc flag so JSON objects become stdClass,
        // allowing us to distinguish {} (object) from [] (array).
        $decoded = json_decode($content, false);
        $result = ($decoded instanceof \stdClass) ? $this->stdClassToArray($decoded) : [];

        $this->bodyDataCacheKey = $cacheKey;
        $this->bodyDataCacheValue = $result;

        return $result;
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
        foreach ((array)$obj as $key => $value) {
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
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInRequestFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        $candidates = [
            $this->normalizeInRequestFlagName($paramName),
            $paramName . 'InRequest',
            $paramName . 'WasProvidedInRequest',
        ];

        foreach ($candidates as $candidate) {
            if ($reflection->hasProperty($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeInRequestFlagName(string $propertyName): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $propertyName) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            $camel = 'value';
        } else {
            $first = $parts[0];
            $camel = strtoupper($first) === $first ? strtolower($first) : lcfirst($first);

            for ($i = 1, $count = count($parts); $i < $count; $i++) {
                $part = $parts[$i];
                $camel .= ucfirst(strtolower($part));
            }
        }

        if (is_numeric($camel[0])) {
            $camel = '_' . $camel;
        }

        return $camel . 'InRequest';
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
        bool $allowsAssociativeArray = false,
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
            throw new RuntimeException(
                $this->messageProvider->format(
                    ValidationMessageKey::CANNOT_CAST_NULL_TO_NON_NULLABLE_TYPE,
                    ['typeName' => $typeName],
                ),
            );
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
                return (float)$value;
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
                    if (!$allowsAssociativeArray) {
                        throw new RuntimeException($this->expectsTypeMessage($paramPath, 'array', 'object'));
                    }
                    $value = $this->stdClassToArray($value);
                }

                if (!is_array($value)) {
                    throw new RuntimeException($this->expectsTypeMessage($paramPath, 'array', $value));
                }

                if (!array_is_list($value) && !$allowsAssociativeArray) {
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
                        $normalized[$index] = $this->castArrayItemValue($itemValue, $arrayItemType, $itemPath);
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

            return (int)$value;
        }

        if ($typeName === 'float') {
            if (!$this->isStrictFloatValue($value)) {
                throw new RuntimeException($this->expectsTypeMessage($paramPath, 'float', $value));
            }

            return (float)$value;
        }

        if ($typeName === 'string') {
            return (string)$value;
        }

        if ($typeName === 'bool') {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            }
            return (bool)$value;
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
                return $this->parseDateTimeStrict($value, $paramPath, $temporalFormat);
            }
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DATE_STRING, [
                'paramPath' => $paramPath,
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
            return $this->castToEnum($value, $typeName, $paramPath);
        }

        // Handle nested DTOs
        if (class_exists($typeName)) {
            if ($value instanceof \stdClass) {
                $value = $this->stdClassToArray($value);
            }
            if (is_array($value)) {
                $targetDtoClass = $this->resolveDiscriminatorTargetClass(
                    $typeName,
                    $value,
                    $paramPath,
                ) ?? $typeName;
                // Recursively deserialize nested DTO
                $nestedRequest = $this->createRequestFromArray($value);
                if (!is_string($targetDtoClass) || !class_exists($targetDtoClass)) {
                    throw new RuntimeException(
                        $this->messageProvider->format(
                            ValidationMessageKey::DISCRIMINATOR_MAPPING_UNKNOWN_CLASS,
                            ['paramPath' => $paramPath, 'targetClass' => (string)$targetDtoClass],
                        ),
                    );
                }

                /** @var class-string<object> $targetDtoClass */
                return $this->deserialize($nestedRequest, $targetDtoClass);
            }
            throw new RuntimeException(
                $this->messageProvider->format(
                    ValidationMessageKey::CANNOT_DESERIALIZE_NESTED_DTO_FROM_NON_ARRAY,
                    ['typeName' => $typeName],
                ),
            );
        }

        throw new RuntimeException(
            $this->messageProvider->format(ValidationMessageKey::UNSUPPORTED_TYPE, ['typeName' => $typeName]),
        );
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
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_EMPTY_STRING, [
                    'paramPath' => $paramPath,
                    'formatHint' => $hint,
                ]),
            );
        }

        // date format: only Y-m-d
        if ($temporalFormat === 'Y-m-d') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException(
                    $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DATE_IN_FORMAT, [
                        'paramPath' => $paramPath,
                        'format' => 'Y-m-d',
                        'example' => '2026-03-10',
                        'value' => $value,
                    ]),
                );
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
                throw new RuntimeException(
                    $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME, [
                        'paramPath' => $paramPath,
                        'example' => '2026-03-10T12:00:00+00:00',
                        'value' => $value,
                    ]),
                );
            }

            return $dt;
        }

        // No format hint — try generic parse but reject "now"-like strings
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME_GENERIC, [
                    'paramPath' => $paramPath,
                    'value' => $value,
                ]),
            );
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
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolveTemporalFormat(
        ReflectionClass $reflection,
        string $paramName,
        ?string $openApiFormat = null,
    ): ?string {
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

    /**
     * @param ReflectionClass<object> $reflection
     */
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

        // If required AND PHP-nullable -> schema has nullable:true -> null is valid.
        // If not required AND PHP-nullable -> just optional -> null is NOT valid in JSON.
        return str_contains($body, 'return true;');
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
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
     * @return UnitEnum
     */
    private function castToEnum(mixed $value, string $enumClass, ?string $paramPath = null): UnitEnum
    {
        if (!enum_exists($enumClass)) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::UNSUPPORTED_TYPE, ['typeName' => $enumClass]),
            );
        }

        // Fetch and cache enum cases – avoids new ReflectionClass + cases() call on every cast.
        if (!array_key_exists($enumClass, self::$enumCasesCache)) {
            /** @var class-string<UnitEnum> $enumClass */
            $reflection = new ReflectionClass($enumClass);
            self::$enumCasesCache[$enumClass] = $reflection->getMethod('cases')->invoke(null);
        }

        /** @var list<UnitEnum> $cases */
        $cases = self::$enumCasesCache[$enumClass];

        foreach ($cases as $case) {
            if ($case instanceof BackedEnum && $case->value === $value) {
                return $case;
            }

            if ($case->name === $value) {
                return $case;
            }
        }

        $allowed = [];
        foreach ($cases as $case) {
            $allowed[] = $case instanceof BackedEnum ? (string)$case->value : $case->name;
        }

        if ($paramPath !== null) {
            throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_ENUM, [
                'paramPath' => $paramPath,
                'enumClass' => $enumClass,
                'value' => (string)$value,
                'allowed' => implode(', ', $allowed),
            ]));
        }

        throw new RuntimeException($this->messageProvider->format(ValidationMessageKey::INVALID_ENUM_VALUE, [
            'value' => (string)$value,
            'enumClass' => $enumClass,
        ]));
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();

        static $constraintsCache = [];
        if (array_key_exists($className, $constraintsCache)) {
            return $constraintsCache[$className];
        }

        $method = $this->resolveMetadataMethod($className, ['getConstraints']);
        if ($method === null) {
            return $constraintsCache[$className] = [];
        }

        $constraints = call_user_func($method);
        return $constraintsCache[$className] = is_array($constraints) ? $constraints : [];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function resolveOpenApiPropertyAliases(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();

        static $aliasesCache = [];
        if (array_key_exists($className, $aliasesCache)) {
            return $aliasesCache[$className];
        }

        $method = $this->resolveMetadataMethod($className, ['getAliases']);
        if ($method === null) {
            return $aliasesCache[$className] = [];
        }

        $aliases = call_user_func($method);
        if (!is_array($aliases)) {
            return $aliasesCache[$className] = [];
        }

        $result = [];
        foreach ($aliases as $propertyName => $openApiName) {
            if (!is_string($propertyName) || !is_string($openApiName)) {
                continue;
            }
            $result[$propertyName] = $openApiName;
        }

        return $aliasesCache[$className] = $result;
    }

    /**
     * @param array<string, mixed>|null $constraints
     */
    private function allowsAssociativeArray(?array $constraints): bool
    {
        if (!is_array($constraints)) {
            return false;
        }

        return ($constraints['type'] ?? null) === 'object';
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
     * @param array<string, mixed> $value
     */
    private function resolveDiscriminatorTargetClass(string $baseClass, array $value, string $paramPath): ?string
    {
        if (!class_exists($baseClass)) {
            return null;
        }

        /** @var class-string $baseClass */
        if (!method_exists($baseClass, 'getDiscriminatorPropertyName') || !method_exists(
                $baseClass,
                'getDiscriminatorMapping',
            )) {
            return null;
        }

        $discriminatorProperty = $baseClass::getDiscriminatorPropertyName();
        $mapping = $baseClass::getDiscriminatorMapping();

        if (!is_string($discriminatorProperty) || $discriminatorProperty === '' || !is_array(
                $mapping,
            ) || $mapping === []) {
            throw new RuntimeException(
                $this->messageProvider->format(
                    ValidationMessageKey::DTO_HAS_INVALID_DISCRIMINATOR_METADATA,
                    ['baseClass' => $baseClass],
                ),
            );
        }

        $fullDiscriminatorPath = $paramPath . '.' . $discriminatorProperty;

        if (!array_key_exists($discriminatorProperty, $value)) {
            throw new RuntimeException(
                $this->messageProvider->format(
                    ValidationMessageKey::PARAM_WAS_NOT_PROVIDED,
                    ['paramPath' => $fullDiscriminatorPath],
                ),
            );
        }

        $discriminatorValue = $value[$discriminatorProperty];
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::PARAM_EXPECTS_DISCRIMINATOR_VALUE, [
                    'paramPath' => $fullDiscriminatorPath,
                    'actualType' => $this->getTypeString($discriminatorValue),
                ]),
            );
        }

        $discriminatorKey = (string)$discriminatorValue;
        if (!array_key_exists($discriminatorKey, $mapping)) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::PARAM_HAS_INVALID_DISCRIMINATOR_VALUE, [
                    'paramPath' => $fullDiscriminatorPath,
                    'value' => $discriminatorKey,
                    'allowed' => implode(', ', array_keys($mapping)),
                ]),
            );
        }

        $targetClass = $mapping[$discriminatorKey];
        if (!is_string($targetClass) || !class_exists($targetClass)) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::DISCRIMINATOR_MAPPING_UNKNOWN_CLASS, [
                    'paramPath' => $fullDiscriminatorPath,
                    'targetClass' => (string)$targetClass,
                ]),
            );
        }

        if (!is_a($targetClass, $baseClass, true)) {
            throw new RuntimeException(
                $this->messageProvider->format(ValidationMessageKey::DISCRIMINATOR_MAPPING_MUST_EXTEND, [
                    'targetClass' => $targetClass,
                    'baseClass' => $baseClass,
                ]),
            );
        }

        return $targetClass;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRequestFromArray(array $data): Request
    {
        // Create a minimal request from array data without JSON re-encode/re-decode.
        $request = new Request();
        $request->initialize();
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set(self::PREDECODED_BODY_ATTRIBUTE, $data);
        return $request;
    }

    private function expectsTypeMessage(string $paramPath, string $expectedType, mixed $value): string
    {
        $actualType = is_string($value) && in_array(
            $value,
            ['int', 'float', 'string', 'bool', 'array', 'object', 'null'],
            true,
        )
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
