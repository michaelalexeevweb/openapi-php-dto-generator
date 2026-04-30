<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Contract\DtoDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\UnsetValue;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use UnitEnum;

final class DtoDeserializer implements DtoDeserializerInterface
{
    private const string PREDECODED_BODY_ATTRIBUTE = '__opg_predecoded_body_data';

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
     *     isRequired: bool,
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
     *   inPathProperties: array<string, \ReflectionProperty|null>,
     *   inQueryProperties: array<string, \ReflectionProperty|null>,
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

    private(set) DtoValidatorInterface $constraintValidator;

    public function __construct(
        ?DtoValidatorInterface $constraintValidator = null,
    ) {
        $this->constraintValidator = $constraintValidator ?? new DtoValidator();
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
            self::$dtoMetaCache[$dtoClass] = $this->buildDtoMeta(reflection: $reflection, dtoClass: $dtoClass);
        }
        $classMeta = self::$dtoMetaCache[$dtoClass];

        if (!$classMeta['hasConstructor']) {
            throw new RuntimeException(
                "DTO {$dtoClass} has no constructor.",
            );
        }

        // Pre-fetch all request data sources once — avoids N per-parameter array allocations.
        $bodyData = $this->getBodyData($request);
        $queryData = $request->query->all();
        $formData = $request->request->all();

        $args = [];
        $providedParamSources = [];
        $errors = [];

        foreach ($classMeta['params'] as $paramMeta) {
            $requestFieldName = $paramMeta['requestFieldName'];

            $rawSource = '';
            $rawWasProvided = false;
            $rawValue = $this->resolveRawRequestValue(
                request: $request,
                paramName: $requestFieldName,
                bodyData: $bodyData,
                queryData: $queryData,
                formData: $formData,
                wasProvided: $rawWasProvided,
                source: $rawSource,
            );

            if (!$rawWasProvided && $paramMeta['hasDefaultValue']) {
                $defaultValue = $paramMeta['defaultValue'];
                // UnsetValue sentinel must be passed as-is so the constructor can detect "not in request".
                // For regular nullable defaults, null is used to signal "absent from request".
                $args[] = ($paramMeta['allowsNull'] && !($defaultValue instanceof UnsetValue))
                    ? null
                    : $defaultValue;
                continue;
            }

            // Missing optional parameter should not raise an error.
            if (!$rawWasProvided && !$paramMeta['isRequired']) {
                $args[] = null;
                continue;
            }

            // Cast the pre-resolved raw value to the declared type.
            try {
                if (count($paramMeta['typeNames']) === 1) {
                    if (!$rawWasProvided) {
                        if ($paramMeta['allowsNull']) {
                            $args[] = null;
                            continue;
                        }
                        throw new RuntimeException(
                            "Required parameter \"{$requestFieldName}\" not found in request.",
                        );
                    }
                    $value = $this->castValue(
                        value: $rawValue,
                        paramName: $requestFieldName,
                        typeName: $paramMeta['typeNames'][0],
                        allowsNull: $paramMeta['allowsNull'],
                        source: $rawSource,
                        arrayItemType: $paramMeta['arrayItemType'],
                        paramPath: $requestFieldName,
                        schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                        temporalFormat: $paramMeta['temporalFormat'],
                        openApiFormat: $paramMeta['openApiFormat'],
                        allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                    );
                } else {
                    $value = $this->castUnionValue(
                        paramName: $requestFieldName,
                        typeNames: $paramMeta['typeNames'],
                        allowsNull: $paramMeta['allowsNull'],
                        rawValue: $rawValue,
                        rawWasProvided: $rawWasProvided,
                        rawSource: $rawSource,
                        arrayItemType: $paramMeta['arrayItemType'],
                        schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                        dtoReflection: $reflection,
                        openApiFormat: $paramMeta['openApiFormat'],
                        allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                    );
                }
            } catch (RuntimeException $e) {
                // Collect errors and use null as placeholder so we can continue validating other params.
                foreach (explode("\n", $e->getMessage()) as $msg) {
                    $errors[] = $msg;
                }
                $args[] = null;
                continue;
            }

            $args[] = $value;

            if ($rawWasProvided && is_array($paramMeta['fieldConstraints']) && $paramMeta['fieldConstraints'] !== []) {
                foreach (
                    $this->constraintValidator->validate(
                        sprintf('param "%s"', $requestFieldName),
                        $value,
                        $paramMeta['fieldConstraints'],
                    ) as $constraintError
                ) {
                    $errors[] = $constraintError;
                }
            }

            if ($rawWasProvided) {
                $providedParamSources[$paramMeta['name']] = $rawSource;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique(array_filter($errors))));
        }

        /** @var T $dto */
        $dto = $reflection->newInstanceArgs($args);

        // Set tracking flags: all to false first, then mark provided sources.
        foreach ($classMeta['params'] as $paramMeta) {
            $paramName = $paramMeta['name'];
            $source = $providedParamSources[$paramName] ?? null;

            if (($prop = $classMeta['inRequestProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source !== null);
            }
            if (($prop = $classMeta['inPathProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'path');
            }
            if (($prop = $classMeta['inQueryProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'query');
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
     *     isRequired: bool,
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
     *   inPathProperties: array<string, \ReflectionProperty|null>,
     *   inQueryProperties: array<string, \ReflectionProperty|null>,
     * }
     */
    private function buildDtoMeta(ReflectionClass $reflection, string $dtoClass): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [
                'hasConstructor' => false,
                'params' => [],
                'inRequestProperties' => [],
                'inPathProperties' => [],
                'inQueryProperties' => [],
            ];
        }

        $aliases = $this->resolveOpenApiPropertyAliases($reflection);
        $constraints = $this->resolveOpenApiConstraints($reflection);

        $params = [];
        $inRequestProperties = [];
        $inPathProperties = [];
        $inQueryProperties = [];

        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $requestFieldName = $aliases[$paramName] ?? $paramName;
            $paramType = $param->getType();
            $hasDefaultValue = $param->isDefaultValueAvailable();
            $fieldConstraints = $constraints[$paramName] ?? null;
            $allowsAssociativeArray = $this->allowsAssociativeArray($fieldConstraints);
            $openApiFormat = $this->resolveOpenApiFormat($fieldConstraints);
            $allowsNull = $paramType?->allowsNull() ?? false;
            $isRequired = $this->resolveParameterRequiredFlag(
                reflection: $reflection,
                paramName: $paramName,
                allowsNull: $allowsNull,
                hasDefaultValue: $hasDefaultValue,
            );
            $schemaAllowsNull = $this->resolveSchemaAllowsNull(
                reflection: $reflection,
                paramName: $paramName,
                phpAllowsNull: $allowsNull,
            );

            $typeNames = [];
            if ($paramType instanceof ReflectionNamedType) {
                if (!$this->isInternalUnsetValueType($paramType->getName())) {
                    $typeNames[] = $paramType->getName();
                }
            } elseif ($paramType instanceof ReflectionUnionType) {
                foreach ($paramType->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType) {
                        continue;
                    }
                    if ($unionType->getName() === 'null' || $this->isInternalUnsetValueType($unionType->getName())) {
                        continue;
                    }
                    $typeNames[] = $unionType->getName();
                }
            } else {
                // Unsupported intersection / no-type-hint param – surface the error immediately.
                throw new RuntimeException(
                    "Parameter \${$paramName} in {$dtoClass} has unsupported type.",
                );
            }

            if ($typeNames === []) {
                $typeNames[] = 'mixed';
            }

            $arrayItemType = in_array('array', $typeNames, true)
                ? $this->resolveArrayItemType(reflection: $reflection, paramName: $paramName)
                : null;

            // Pre-compute temporal format for DateTimeImmutable fields.
            $temporalFormat = in_array(DateTimeImmutable::class, $typeNames, true)
                ? $this->resolveTemporalFormat(
                    reflection: $reflection,
                    paramName: $paramName,
                    openApiFormat: $openApiFormat,
                )
                : null;

            $inRequestProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInRequestFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inPathProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInPathFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inQueryProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInQueryFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );

            $params[] = [
                'name' => $paramName,
                'requestFieldName' => $requestFieldName,
                'typeNames' => $typeNames,
                'allowsNull' => $allowsNull,
                'isRequired' => $isRequired,
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
            'inPathProperties' => $inPathProperties,
            'inQueryProperties' => $inQueryProperties,
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveParameterRequiredFlag(
        ReflectionClass $reflection,
        string $paramName,
        bool $allowsNull,
        bool $hasDefaultValue,
    ): bool {
        $requiredMethodName = 'is' . ucfirst($paramName) . 'Required';

        if (!$reflection->hasMethod($requiredMethodName)) {
            return !$allowsNull && !$hasDefaultValue;
        }

        return $this->invokeRequiredMethod($reflection, $requiredMethodName)
            ?? (!$allowsNull && !$hasDefaultValue);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function invokeRequiredMethod(ReflectionClass $reflection, string $methodName): ?bool
    {
        try {
            $method = $reflection->getMethod($methodName);
            $instance = $method->isStatic() ? null : $reflection->newInstanceWithoutConstructor();
            return (bool)$method->invoke($instance);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolves the raw value for a single parameter from all request sources using pre-fetched data.
     * Sets $wasProvided and $source by reference.
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param array<string, mixed> $formData
     */
    private function resolveRawRequestValue(
        Request $request,
        string $paramName,
        array $bodyData,
        array $queryData,
        array $formData,
        bool &$wasProvided,
        string &$source,
    ): mixed {
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            $source = 'json';
            return $bodyData[$paramName];
        }

        if (array_key_exists($paramName, $queryData)) {
            $wasProvided = true;
            $source = 'query';
            return $queryData[$paramName];
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

        if (array_key_exists($paramName, $formData)) {
            $wasProvided = true;
            $source = 'form';
            return $formData[$paramName];
        }

        $wasProvided = false;
        $source = '';
        return null;
    }

    /**
     * Casts a pre-resolved raw value to one of the given union types.
     *
     * @param array<int, string> $typeNames
     * @param ReflectionClass<object> $dtoReflection
     */
    private function castUnionValue(
        string $paramName,
        array $typeNames,
        bool $allowsNull,
        mixed $rawValue,
        bool $rawWasProvided,
        string $rawSource,
        ?string $arrayItemType,
        bool $schemaAllowsNull,
        ReflectionClass $dtoReflection,
        ?string $openApiFormat,
        bool $allowsAssociativeArray,
    ): mixed {
        if (!$rawWasProvided) {
            if ($allowsNull) {
                return null;
            }

            throw new RuntimeException(
                "Required parameter \"{$paramName}\" not found in request.",
            );
        }

        $errors = [];
        foreach ($typeNames as $typeName) {
            $temporalFormat = $typeName === DateTimeImmutable::class
                ? $this->resolveTemporalFormat(
                    reflection: $dtoReflection,
                    paramName: $paramName,
                    openApiFormat: $openApiFormat,
                )
                : null;

            try {
                return $this->castValue(
                    value: $rawValue,
                    paramName: $paramName,
                    typeName: $typeName,
                    allowsNull: false,
                    source: $rawSource,
                    arrayItemType: $arrayItemType,
                    paramPath: $paramName,
                    schemaAllowsNull: $schemaAllowsNull,
                    temporalFormat: $temporalFormat,
                    openApiFormat: $openApiFormat,
                    allowsAssociativeArray: $allowsAssociativeArray,
                );
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new RuntimeException(implode("\n", array_values(array_unique($errors))));
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Json is not valid: ' . json_last_error_msg());
        }

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
    private function resolveReflectionProperty(ReflectionClass $reflection, ?string $propName): ?\ReflectionProperty
    {
        return $propName !== null ? $reflection->getProperty($propName) : null;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInRequestFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(reflection: $reflection, paramName: $paramName, suffixes: [
            'InRequest',
            'WasProvidedInRequest',
        ]);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInPathFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InPath'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInQueryFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InQuery'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<string> $suffixes
     */
    private function resolveTrackingFlagPropertyName(
        ReflectionClass $reflection,
        string $paramName,
        array $suffixes,
    ): ?string {
        $candidates = [];
        foreach ($suffixes as $suffix) {
            $candidates[] = $this->normalizeTrackingFlagName(propertyName: $paramName, suffix: $suffix);
            $candidates[] = $paramName . $suffix;
        }

        foreach ($candidates as $candidate) {
            if ($reflection->hasProperty($candidate)) {
                return $candidate;
            }
        }

        return null;
    }


    private function normalizeTrackingFlagName(string $propertyName, string $suffix): string
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

        return $camel . $suffix;
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
                throw new RuntimeException("param \"{$paramPath}\" expects {$typeName}, got null");
            }
            if ($allowsNull) {
                return null;
            }
            throw new RuntimeException(
                "Cannot cast null to non-nullable type {$typeName}.",
            );
        }

        // JSON should stay strict: no implicit scalar conversions.
        if ($source === 'json') {
            if ($typeName === 'int') {
                if (!is_int($value)) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'int', value: $value),
                    );
                }
                return $value;
            }

            if ($typeName === 'float') {
                if (!is_float($value) && !is_int($value)) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'float', value: $value),
                    );
                }
                return (float)$value;
            }

            if ($typeName === 'string') {
                if (!is_string($value)) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'string', value: $value),
                    );
                }

                return $value;
            }

            if ($typeName === 'bool') {
                if (!is_bool($value)) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'bool', value: $value),
                    );
                }
                return $value;
            }

            if ($typeName === 'array') {
                if ($value instanceof \stdClass) {
                    if (!$allowsAssociativeArray) {
                        throw new RuntimeException(
                            $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: 'object'),
                        );
                    }
                    $value = $this->stdClassToArray($value);
                }

                if (!is_array($value)) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: $value),
                    );
                }

                if (!array_is_list($value) && !$allowsAssociativeArray) {
                    throw new RuntimeException(
                        $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: 'object'),
                    );
                }

                if ($arrayItemType === null) {
                    return $value;
                }

                return $this->castArrayItems(
                    items: $value,
                    arrayItemType: $arrayItemType,
                    paramPath: $paramPath,
                    source: $source,
                );
            }
        }

        // Handle scalar types
        if ($typeName === 'int') {
            if (!$this->isStrictIntValue($value)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'int', value: $value),
                );
            }

            return (int)$value;
        }

        if ($typeName === 'float') {
            if (!$this->isStrictFloatValue($value)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'float', value: $value),
                );
            }

            return (float)$value;
        }

        if ($typeName === 'string') {
            if (is_array($value) || $value instanceof \stdClass || is_object($value)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'string', value: $value),
                );
            }

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
            $arrayValue = is_array($value) ? $value : [$value];

            if ($arrayItemType === null) {
                return $arrayValue;
            }

            return $this->castArrayItems(
                items: $arrayValue,
                arrayItemType: $arrayItemType,
                paramPath: $paramPath,
                source: $source,
            );
        }

        // Handle DateTimeImmutable
        if ($typeName === DateTimeImmutable::class) {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if (is_string($value)) {
                return $this->parseDateTimeStrict(
                    value: $value,
                    paramPath: $paramPath,
                    temporalFormat: $temporalFormat,
                );
            }
            throw new RuntimeException(
                "param \"{$paramPath}\" expects a date string, got {$this->getTypeString($value)}",
            );
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
            return $this->castToEnum(value: $value, enumClass: $typeName, paramPath: $paramPath);
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
                if (!class_exists($targetDtoClass)) {
                    throw new RuntimeException(
                        "Discriminator mapping for \"{$paramPath}\" points to unknown class \"{$targetDtoClass}\".",
                    );
                }

                /** @var class-string<object> $targetDtoClass */
                return $this->deserialize(request: $nestedRequest, dtoClass: $targetDtoClass);
            }
            throw new RuntimeException(
                "Cannot deserialize nested DTO {$typeName} from non-array value.",
            );
        }

        throw new RuntimeException(
            "Unsupported type: {$typeName}",
        );
    }

    /**
     * @param array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private function castArrayItems(array $items, string $arrayItemType, string $paramPath, string $source): array
    {
        $normalized = [];
        $errors = [];
        foreach ($items as $index => $itemValue) {
            $itemPath = $paramPath . '.' . $index;
            try {
                $normalized[$index] = $this->castArrayItemValue(
                    itemValue: $itemValue,
                    arrayItemType: $arrayItemType,
                    itemPath: $itemPath,
                    source: $source,
                );
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        return $normalized;
    }

    private function castArrayItemValue(mixed $itemValue, string $arrayItemType, string $itemPath, string $source): mixed
    {
        if (in_array($arrayItemType, ['int', 'float', 'string', 'bool', 'array'], true)) {
            return $this->castValue(
                value: $itemValue,
                paramName: $itemPath,
                typeName: $arrayItemType,
                allowsNull: false,
                source: $source,
                arrayItemType: null,
                paramPath: $itemPath,
            );
        }

        if (enum_exists($arrayItemType)) {
            return $this->castToEnum(value: $itemValue, enumClass: $arrayItemType, paramPath: $itemPath);
        }

        if (class_exists($arrayItemType)) {
            if ($itemValue instanceof \stdClass) {
                $itemValue = $this->stdClassToArray($itemValue);
            }
            if (!is_array($itemValue)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'object', value: $itemValue),
                );
            }

            try {
                $nestedRequest = $this->createRequestFromArray($itemValue);
                return $this->deserialize(request: $nestedRequest, dtoClass: $arrayItemType);
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    $this->prependParamPath(message: $e->getMessage(), prefix: $itemPath),
                    previous: $e,
                );
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
     * Parses a date/time string strictly according to the schema format.
     * Rejects empty strings and strings that don't match the expected format.
     */
    private function parseDateTimeStrict(string $value, string $paramPath, ?string $temporalFormat): DateTimeImmutable
    {
        if ($value === '') {
            $hint = $this->temporalFormatHint($temporalFormat);
            throw new RuntimeException(
                "param \"{$paramPath}\" expects a valid date{$hint}, got empty string",
            );
        }

        // date format: only Y-m-d
        if ($temporalFormat === 'Y-m-d') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException(
                    "param \"{$paramPath}\" expects a date in Y-m-d format (e.g. 2026-03-10), got \"{$value}\"",
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
                    "param \"{$paramPath}\" expects a valid date-time (e.g. 2026-03-10T12:00:00+00:00), got \"{$value}\"",
                );
            }

            return $dt;
        }

        // No format hint — try generic parse but reject "now"-like strings
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RuntimeException(
                "param \"{$paramPath}\" expects a valid date/time, got \"{$value}\"",
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

        $constraints = $this->resolveOpenApiConstraints($reflection);
        $fieldConstraints = $constraints[$paramName] ?? null;
        if (is_array($fieldConstraints) && array_key_exists('nullable', $fieldConstraints)) {
            return $fieldConstraints['nullable'] === true;
        }

        $requiredMethodName = 'is' . ucfirst($paramName) . 'Required';
        if (!$reflection->hasMethod($requiredMethodName)) {
            return $phpAllowsNull;
        }

        // required + PHP-nullable → schema nullable:true → null is valid in JSON.
        // not required + PHP-nullable → just optional → null is NOT valid in JSON.
        return $this->invokeRequiredMethod($reflection, $requiredMethodName)
            ?? $phpAllowsNull;
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
        if (in_array($rawType, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
            return $rawType;
        }

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
                "Unsupported type: {$enumClass}",
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

        $allowed = [];
        foreach ($cases as $case) {
            if ($case instanceof BackedEnum) {
                if ($case->value === $value) {
                    return $case;
                }
                $allowed[] = (string)$case->value;
            } else {
                $allowed[] = $case->name;
            }

            if ($case->name === $value) {
                return $case;
            }
        }

        if ($paramPath !== null) {
            $allowedStr = implode(', ', $allowed);
            $actualValue = $this->formatValueForError($value);
            throw new RuntimeException(
                "param \"{$paramPath}\" expects enum {$enumClass}, got {$actualValue}. Allowed: {$allowedStr}",
            );
        }

        $actualValue = $this->formatValueForError($value);
        throw new RuntimeException(
            "Invalid enum value {$actualValue} for {$enumClass}.",
        );
    }

    private function isInternalUnsetValueType(string $typeName): bool
    {
        return $typeName === 'UnsetValue' || str_ends_with($typeName, '\\UnsetValue');
    }

    private function formatValueForError(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return $this->getTypeString($value);
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

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getConstraints']);
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

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getAliases']);
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
     * @param array<array-key, mixed> $value
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
                "DTO {$baseClass} has invalid discriminator metadata.",
            );
        }

        $fullDiscriminatorPath = $paramPath . '.' . $discriminatorProperty;

        if (!array_key_exists($discriminatorProperty, $value)) {
            throw new RuntimeException(
                "param \"{$fullDiscriminatorPath}\" wasn't provided",
            );
        }

        $discriminatorValue = $value[$discriminatorProperty];
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            throw new RuntimeException(
                "param \"{$fullDiscriminatorPath}\" expects string|int discriminator value, got {$this->getTypeString($discriminatorValue)}",
            );
        }

        $discriminatorKey = (string)$discriminatorValue;
        if (!array_key_exists($discriminatorKey, $mapping)) {
            $allowedKeys = implode(', ', array_keys($mapping));
            throw new RuntimeException(
                "param \"{$fullDiscriminatorPath}\" has invalid discriminator value \"{$discriminatorKey}\". Allowed: {$allowedKeys}",
            );
        }

        $targetClass = $mapping[$discriminatorKey];
        if (!is_string($targetClass) || !class_exists($targetClass)) {
            throw new RuntimeException(
                "Discriminator mapping for \"{$fullDiscriminatorPath}\" points to unknown class \"{$targetClass}\".",
            );
        }

        if (!is_a($targetClass, $baseClass, true)) {
            throw new RuntimeException(
                "Discriminator mapping class {$targetClass} must extend or implement {$baseClass}.",
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

        return "param \"{$paramPath}\" expects {$expectedType}, got {$actualType}";
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
