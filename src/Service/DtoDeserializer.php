<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Contract\DtoDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
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
     *     arrayItemTemporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *     readOnly: bool,
     *     sourceConstraint: string|null,
     *     arrayDelimiter: non-empty-string|null,
     *     arrayItemsNullable: bool,
     *   }>,
     *   inRequestProperties: array<string, ReflectionProperty|null>,
     *   inPathProperties: array<string, ReflectionProperty|null>,
     *   inQueryProperties: array<string, ReflectionProperty|null>,
     *   inHeaderProperties: array<string, ReflectionProperty|null>,
     *   inCookieProperties: array<string, ReflectionProperty|null>,
     * }>
     */
    private static array $dtoMetaCache = [];

    /**
     * Enum cases cache to avoid repeated reflection per castToEnum call.
     * Key: enum class-string → list of UnitEnum cases
     *
     * @var array<class-string, list<UnitEnum>>
     */
    private static array $enumCasesCache = [];

    /** @var array<class-string, array<string, array<string, mixed>>> */
    private static array $constraintsCache = [];

    /** @var array<class-string, array<string, string>> */
    private static array $aliasesCache = [];

    /**
     * Maps a declared type name to its cast "kind", computed once per type name per
     * process. Avoids enum_exists()/class_exists() calls on every cast value/element.
     *
     * @var array<string, string>
     */
    private static array $typeKindCache = [];

    /**
     * Cache of `use` imports parsed from each DTO source file, keyed by absolute filename.
     *
     * @var array<string, array<string, string>>
     */
    private static array $fileImportsCache = [];

    // -----------------------------------------------------------------------
    // Instance cache: last parsed request body (avoids re-parsing the same
    // JSON content multiple times within a single deserialization call, since
    // getBodyData() is invoked once per constructor parameter).
    // -----------------------------------------------------------------------

    private ?string $bodyDataCacheKey = null;
    /** @var array<string, mixed> */
    private array $bodyDataCacheValue = [];

    public private(set) DtoValidatorInterface $constraintValidator;

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
        // Pre-fetch all request data sources once — avoids N per-parameter array allocations.
        /** @var T $dto */
        $dto = $this->deserializeInternal(
            dtoClass: $dtoClass,
            bodyData: $this->getBodyData($request),
            queryData: $request->query->all(),
            formData: $request->request->all(),
            request: $request,
        );

        return $dto;
    }

    /**
     * Deserializes a nested DTO directly from a decoded JSON array, bypassing the
     * Symfony Request entirely. Nested objects only ever come from the JSON body, so
     * there are no path/query/form/file sources to consider — this avoids allocating a
     * fresh Request + initialize() for every nested object.
     *
     * @template T of object
     * @param array<string, mixed> $data
     * @param class-string<T> $dtoClass
     * @return T
     */
    private function deserializeFromArray(array $data, string $dtoClass): object
    {
        /** @var T $dto */
        $dto = $this->deserializeInternal(
            dtoClass: $dtoClass,
            bodyData: $data,
            queryData: [],
            formData: [],
            request: null,
        );

        return $dto;
    }

    /**
     * Shared deserialization core. `$request` is null for nested array deserialization
     * (no path attributes or uploaded files in that case).
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param array<string, mixed> $formData
     * @param class-string $dtoClass
     */
    private function deserializeInternal(
        string $dtoClass,
        array $bodyData,
        array $queryData,
        array $formData,
        ?Request $request,
    ): object {
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
                sourceConstraint: $paramMeta['sourceConstraint'],
                wasProvided: $rawWasProvided,
                source: $rawSource,
            );

            // OpenAPI delimited-array serialization: a single query/header/cookie string
            // (e.g. "1,2,3" or "1 2 3") is split into elements per the parameter's style.
            // Already-arrayified values (form+explode repeated keys, deepObject brackets)
            // skip this and are cast as-is.
            if (
                $rawWasProvided
                && $paramMeta['arrayDelimiter'] !== null
                && is_string($rawValue)
            ) {
                $rawValue = $rawValue === ''
                    ? []
                    : explode($paramMeta['arrayDelimiter'], $rawValue);

                // For non-string scalar item types, whitespace around delimiters (e.g.
                // "1, 2, 3") is insignificant — trim so strict int/float/bool/enum casts
                // accept it. String items are left untouched (their whitespace is data).
                $itemType = $paramMeta['arrayItemType'];
                if (
                    $itemType !== null
                    && in_array($this->resolveTypeKind($itemType), ['int', 'float', 'bool', 'enum'], true)
                ) {
                    $rawValue = array_map(trim(...), $rawValue);
                }
            }

            if ($paramMeta['readOnly']) {
                if ($paramMeta['hasDefaultValue']) {
                    $args[] = $paramMeta['defaultValue'];
                } elseif ($paramMeta['allowsNull']) {
                    $args[] = null;
                } else {
                    $errors[] = sprintf(
                        'Parameter "%s" is readOnly and non-nullable with no default value.',
                        $requestFieldName,
                    );
                    $args[] = null;
                }
                continue;
            }

            if (!$rawWasProvided && $paramMeta['hasDefaultValue']) {
                $args[] = $paramMeta['defaultValue'];
                continue;
            }

            // Missing optional parameter should not raise an error.
            if (!$rawWasProvided && !$paramMeta['isRequired']) {
                // A non-nullable optional param with no default cannot accept null —
                // pushing null would make newInstanceArgs() throw an opaque TypeError, so
                // surface a clear validation error instead.
                if (!$paramMeta['allowsNull']) {
                    $errors[] = sprintf(
                        'Optional parameter "%s" is non-nullable with no default value and was not provided.',
                        $requestFieldName,
                    );
                }
                $args[] = null;
                continue;
            }

            // Cast the pre-resolved raw value to the declared type.
            try {
                if (count($paramMeta['typeNames']) === 1) {
                    // Optional missing params were already handled above, so a missing
                    // value here always belongs to a required parameter — even when the
                    // PHP type is nullable (required + nullable means the key must be
                    // present, its value may be null).
                    if (!$rawWasProvided) {
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
                        arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                        arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
                    );
                } else {
                    $value = $this->castUnionValue(
                        paramName: $requestFieldName,
                        typeNames: $paramMeta['typeNames'],
                        rawValue: $rawValue,
                        rawWasProvided: $rawWasProvided,
                        rawSource: $rawSource,
                        arrayItemType: $paramMeta['arrayItemType'],
                        schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                        dtoReflection: $reflection,
                        openApiFormat: $paramMeta['openApiFormat'],
                        allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                        arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                        arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
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
            if (($prop = $classMeta['inHeaderProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'header');
            }
            if (($prop = $classMeta['inCookieProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'cookie');
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
     *     arrayItemTemporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *     readOnly: bool,
     *     sourceConstraint: string|null,
     *     arrayDelimiter: non-empty-string|null,
     *     arrayItemsNullable: bool,
     *   }>,
     *   inRequestProperties: array<string, ReflectionProperty|null>,
     *   inPathProperties: array<string, ReflectionProperty|null>,
     *   inQueryProperties: array<string, ReflectionProperty|null>,
     *   inHeaderProperties: array<string, ReflectionProperty|null>,
     *   inCookieProperties: array<string, ReflectionProperty|null>,
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
                'inHeaderProperties' => [],
                'inCookieProperties' => [],
            ];
        }

        $aliases = $this->resolveOpenApiPropertyAliases($reflection);
        $constraints = $this->resolveOpenApiConstraints($reflection);
        // Per-property OpenAPI source binding (path/query/header/cookie). Properties
        // absent from the map — request-body and plain component-schema fields — keep
        // the permissive waterfall. Null when the DTO predates this metadata.
        $parameterSources = $this->resolveParameterSources($reflection) ?? [];
        // Per-property OpenAPI serialization style/explode — drives delimited-array
        // splitting for query/header/cookie params. Empty for legacy DTOs.
        $parameterStyles = $this->resolveParameterStyles($reflection);

        $params = [];
        $inRequestProperties = [];
        $inPathProperties = [];
        $inQueryProperties = [];
        $inHeaderProperties = [];
        $inCookieProperties = [];

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
            $arrayItemsNullable = $this->resolveArrayItemsNullable($fieldConstraints);

            // Pre-compute temporal format for DateTimeImmutable fields.
            $temporalFormat = in_array(DateTimeImmutable::class, $typeNames, true)
                ? $this->resolveTemporalFormat(
                    reflection: $reflection,
                    paramName: $paramName,
                    openApiFormat: $openApiFormat,
                )
                : null;

            // Pre-compute temporal format for DateTimeImmutable[] array items.
            // The items schema (fieldConstraints['items']) may carry format: date, which must
            // be propagated so date-only strings are accepted without a time component.
            $itemsConstraints = is_array($fieldConstraints['items'] ?? null) ? $fieldConstraints['items'] : null;
            $arrayItemTemporalFormat = ($arrayItemType === DateTimeImmutable::class)
                ? $this->resolveTemporalFormat(
                    reflection: $reflection,
                    paramName: $paramName,
                    openApiFormat: $this->resolveOpenApiFormat($itemsConstraints),
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
            $inHeaderProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInHeaderFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inCookieProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInCookieFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );

            $sourceConstraint = $parameterSources[$paramName] ?? null;
            $styleEntry = $parameterStyles[$paramName] ?? null;
            $arrayDelimiter = $arrayItemType !== null && $styleEntry !== null
                ? $this->resolveStyleDelimiter($styleEntry['style'], $styleEntry['explode'])
                : null;

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
                'arrayItemTemporalFormat' => $arrayItemTemporalFormat,
                'fieldConstraints' => $fieldConstraints,
                'readOnly' => ($fieldConstraints['readOnly'] ?? false) === true,
                'sourceConstraint' => $sourceConstraint,
                'arrayDelimiter' => $arrayDelimiter,
                'arrayItemsNullable' => $arrayItemsNullable,
            ];
        }

        return [
            'hasConstructor' => true,
            'params' => $params,
            'inRequestProperties' => $inRequestProperties,
            'inPathProperties' => $inPathProperties,
            'inQueryProperties' => $inQueryProperties,
            'inHeaderProperties' => $inHeaderProperties,
            'inCookieProperties' => $inCookieProperties,
        ];
    }

    /**
     * Reads the optional getParameterSources() metadata that binds properties to a
     * single OpenAPI request source. Returns null when the DTO does not declare it
     * (legacy / hand-written DTOs) so callers can fall back to the permissive waterfall.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>|null
     */
    private function resolveParameterSources(ReflectionClass $reflection): ?array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterSources'],
        );
        if ($method === null) {
            return null;
        }

        $sources = call_user_func($method);
        if (!is_array($sources)) {
            return null;
        }

        $result = [];
        foreach ($sources as $propertyName => $source) {
            if (is_string($propertyName) && is_string($source)) {
                $result[$propertyName] = $source;
            }
        }

        return $result;
    }

    /**
     * Reads the optional getParameterStyles() metadata describing each parameter's
     * OpenAPI serialization style/explode. Returns an empty map when the DTO does not
     * declare it (legacy / hand-written DTOs) so no array splitting is attempted.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, array{style: string, explode: bool}>
     */
    private function resolveParameterStyles(ReflectionClass $reflection): array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterStyles'],
        );
        if ($method === null) {
            return [];
        }

        $styles = call_user_func($method);
        if (!is_array($styles)) {
            return [];
        }

        $result = [];
        foreach ($styles as $propertyName => $entry) {
            if (!is_string($propertyName) || !is_array($entry)) {
                continue;
            }
            $style = $entry['style'] ?? null;
            $explode = $entry['explode'] ?? null;
            if (is_string($style) && is_bool($explode)) {
                $result[$propertyName] = ['style' => $style, 'explode' => $explode];
            }
        }

        return $result;
    }

    /**
     * Resolves the delimiter used to split a single string into array elements for the
     * given OpenAPI style/explode, or null when no splitting applies (the value is
     * already an array from Symfony query parsing, or the style is object-shaped).
     *
     * @return non-empty-string|null
     */
    private function resolveStyleDelimiter(?string $style, ?bool $explode): ?string
    {
        return match ($style) {
            // simple (header default): comma-separated regardless of explode.
            'simple' => ',',
            'spaceDelimited' => ' ',
            'pipeDelimited' => '|',
            // form with explode=true arrives as repeated keys (Symfony already
            // arrayifies); only the non-exploded form uses comma separation.
            'form' => $explode === true ? null : ',',
            // deepObject and unknown styles: no scalar splitting.
            default => null,
        };
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
        } catch (ReflectionException) {
            // Reflection itself failed (missing method, uninstantiable class) → fall back to PHP type inference.
            // Exceptions thrown from inside the isXRequired() body propagate so genuine bugs surface.
            return null;
        }
    }

    /**
     * Resolves the raw value for a single parameter from the request, honouring the
     * declared OpenAPI source when one is known. Sets $wasProvided and $source by reference.
     *
     * Resolution modes:
     *  - $sourceConstraint !== null: the property is bound to one OpenAPI source (path/
     *    query/header/cookie) and is read ONLY from there — a same-named body field cannot
     *    shadow it, and header/cookie are never consulted for any other property.
     *  - otherwise (request-body / plain component-schema field): the permissive waterfall
     *    path → JSON → query → files → form (preserves pre-existing behaviour). Header and
     *    cookie are intentionally absent here so they only ever feed their bound params.
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param array<string, mixed> $formData
     */
    private function resolveRawRequestValue(
        ?Request $request,
        string $paramName,
        array $bodyData,
        array $queryData,
        array $formData,
        ?string $sourceConstraint,
        bool &$wasProvided,
        string &$source,
    ): mixed {
        if ($sourceConstraint !== null) {
            return $this->resolveFromBoundSource(
                request: $request,
                paramName: $paramName,
                sourceConstraint: $sourceConstraint,
                queryData: $queryData,
                wasProvided: $wasProvided,
                source: $source,
            );
        }

        // Path attributes carry router-verified values and must take highest precedence
        // to prevent a malicious request body from overriding trusted path parameters.
        // ($request is null for nested array deserialization — JSON body only.)
        if ($request !== null && $request->attributes->has($paramName)) {
            $wasProvided = true;
            $source = 'path';
            return $request->attributes->get($paramName);
        }

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

        if ($request !== null && $request->files->has($paramName)) {
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
     * Reads a parameter strictly from its declared OpenAPI source.
     * ($request is null for nested array deserialization, where only body data exists,
     * so path/query/header/cookie-bound params are simply absent.)
     *
     * @param array<string, mixed> $queryData
     */
    private function resolveFromBoundSource(
        ?Request $request,
        string $paramName,
        string $sourceConstraint,
        array $queryData,
        bool &$wasProvided,
        string &$source,
    ): mixed {
        if ($sourceConstraint === 'query') {
            if (array_key_exists($paramName, $queryData)) {
                $wasProvided = true;
                $source = 'query';
                return $queryData[$paramName];
            }

            $wasProvided = false;
            $source = '';
            return null;
        }

        if ($request !== null) {
            if ($sourceConstraint === 'path' && $request->attributes->has($paramName)) {
                $wasProvided = true;
                $source = 'path';
                return $request->attributes->get($paramName);
            }

            if ($sourceConstraint === 'header' && $request->headers->has($paramName)) {
                $wasProvided = true;
                $source = 'header';
                return $request->headers->get($paramName);
            }

            if ($sourceConstraint === 'cookie' && $request->cookies->has($paramName)) {
                $wasProvided = true;
                $source = 'cookie';
                return $request->cookies->get($paramName);
            }
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
        mixed $rawValue,
        bool $rawWasProvided,
        string $rawSource,
        ?string $arrayItemType,
        bool $schemaAllowsNull,
        ReflectionClass $dtoReflection,
        ?string $openApiFormat,
        bool $allowsAssociativeArray,
        bool $arrayItemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
    ): mixed {
        // A missing value here always belongs to a required parameter: optional
        // missing params are short-circuited in deserialize() before this call,
        // so nullability must not suppress the error (required + nullable means
        // the key must be present, its value may be null).
        if (!$rawWasProvided) {
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
                    arrayItemsNullable: $arrayItemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
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
            throw new RuntimeException('Json is not valid: ' . json_last_error_msg());
        }

        if (!$decoded instanceof stdClass) {
            throw new RuntimeException(
                'JSON body must be an object, got ' . gettype($decoded),
            );
        }

        $result = $this->stdClassToArray($decoded);

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
    private function stdClassToArray(stdClass $obj): array
    {
        $result = [];
        foreach ((array)$obj as $key => $value) {
            if ($value instanceof stdClass) {
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
    private function resolveReflectionProperty(ReflectionClass $reflection, ?string $propName): ?ReflectionProperty
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
     */
    private function resolveInHeaderFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InHeader'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInCookieFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InCookie'],
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
        $split = preg_split('/[^A-Za-z0-9]+/', $propertyName);
        $parts = $split !== false ? $split : [];
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
            if ($v instanceof stdClass) {
                // Keep as stdClass so castValue can flag it as object
                $arr[$k] = $v;
            } elseif (is_array($v)) {
                $arr[$k] = $this->normalizeArrayValues($v);
            }
        }
        return $arr;
    }

    /**
     * Resolves (and memoizes) the cast "kind" for a declared type name.
     * One of: int, float, string, bool, array, mixed, datetime, file, enum, dto, unknown.
     */
    private function resolveTypeKind(string $typeName): string
    {
        return self::$typeKindCache[$typeName] ??= match ($typeName) {
            'int' => 'int',
            'float' => 'float',
            'string' => 'string',
            'bool' => 'bool',
            'array' => 'array',
            'mixed' => 'mixed',
            DateTimeImmutable::class => 'datetime',
            UploadedFile::class => 'file',
            default => enum_exists($typeName)
                ? 'enum'
                : (class_exists($typeName) ? 'dto' : 'unknown'),
        };
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
        bool $arrayItemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
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

        // Free-form value (schema-less property → `mixed`): accept any non-null value as-is.
        if ($typeName === 'mixed') {
            return $value instanceof stdClass ? $this->stdClassToArray($value) : $value;
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
                if ($value instanceof stdClass) {
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
                    itemsNullable: $arrayItemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
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
            if (is_array($value) || $value instanceof stdClass || is_object($value)) {
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
                $lower = strtolower($value);
                if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                    return false;
                }
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'bool', value: $value),
                );
            }
            return (bool)$value;
        }

        if ($typeName === 'array') {
            if (!is_array($value)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: $value),
                );
            }
            $arrayValue = $value;

            if ($arrayItemType === null) {
                return $arrayValue;
            }

            return $this->castArrayItems(
                items: $arrayValue,
                arrayItemType: $arrayItemType,
                paramPath: $paramPath,
                source: $source,
                itemsNullable: $arrayItemsNullable,
                arrayItemTemporalFormat: $arrayItemTemporalFormat,
            );
        }

        // Object/class kinds: resolved once per type (cached), avoiding enum_exists()/
        // class_exists() on every value. Scalars are already handled above.
        $kind = $this->resolveTypeKind($typeName);

        if ($kind === 'datetime') {
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

        if ($kind === 'file') {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            throw new RuntimeException('Expected UploadedFile but got something else.');
        }

        if ($kind === 'enum') {
            return $this->castToEnum(value: $value, enumClass: $typeName, paramPath: $paramPath, source: $source);
        }

        if ($kind === 'dto') {
            if ($value instanceof stdClass) {
                $value = $this->stdClassToArray($value);
            }
            if (is_array($value)) {
                $targetDtoClass = $this->resolveDiscriminatorTargetClass(
                    $typeName,
                    $value,
                    $paramPath,
                ) ?? $typeName;
                // Recursively deserialize nested DTO.
                // $targetDtoClass existence already guaranteed: resolveDiscriminatorTargetClass()
                // either throws on unknown class, or returns null and we fall back to $typeName
                // (already validated by the 'dto' kind resolution above).
                /** @var class-string<object> $targetDtoClass */
                return $this->deserializeFromArray(data: $value, dtoClass: $targetDtoClass);
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
    private function castArrayItems(
        array $items,
        string $arrayItemType,
        string $paramPath,
        string $source,
        bool $itemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
    ): array {
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
                    itemsNullable: $itemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
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

    private function castArrayItemValue(
        mixed $itemValue,
        string $arrayItemType,
        string $itemPath,
        string $source,
        bool $itemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
    ): mixed {
        // A null element is accepted only when the items schema declares it nullable
        // (items: {nullable: true} or type containing null); otherwise it falls through
        // to the per-kind casts, which reject it with a clear type error.
        if ($itemValue === null && $itemsNullable) {
            return null;
        }

        // Type kind resolved once per type (cached) instead of enum_exists()/class_exists()
        // per array element.
        $kind = $this->resolveTypeKind($arrayItemType);

        if (in_array($kind, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
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

        if ($kind === 'file') {
            if (!$itemValue instanceof UploadedFile) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'UploadedFile', value: $itemValue),
                );
            }
            return $itemValue;
        }

        if ($kind === 'enum') {
            return $this->castToEnum(value: $itemValue, enumClass: $arrayItemType, paramPath: $itemPath, source: $source);
        }

        if ($kind === 'datetime') {
            if ($itemValue instanceof DateTimeImmutable) {
                return $itemValue;
            }
            if (!is_string($itemValue)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'date string', value: $itemValue),
                );
            }
            // Array items carry the temporal format resolved from the items schema
            // (e.g. format: date → 'Y-m-d'); null means full date-time is expected.
            return $this->parseDateTimeStrict(value: $itemValue, paramPath: $itemPath, temporalFormat: $arrayItemTemporalFormat);
        }

        if ($kind === 'dto') {
            if ($itemValue instanceof stdClass) {
                $itemValue = $this->stdClassToArray($itemValue);
            }
            if (!is_array($itemValue)) {
                throw new RuntimeException(
                    $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'object', value: $itemValue),
                );
            }

            // resolveDiscriminatorTargetClass already uses $itemPath in its messages — don't re-wrap
            $targetClass = $this->resolveDiscriminatorTargetClass($arrayItemType, $itemValue, $itemPath) ?? $arrayItemType;
            if (!class_exists($targetClass)) {
                throw new RuntimeException(
                    "Discriminator mapping for \"{$itemPath}\" points to unknown class \"{$targetClass}\".",
                );
            }

            try {
                /** @var class-string<object> $targetClass */
                return $this->deserializeFromArray(data: $itemValue, dtoClass: $targetClass);
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    $this->prependParamPath(message: $e->getMessage(), prefix: $itemPath),
                    previous: $e,
                );
            }
        }

        throw new RuntimeException(
            "Cannot deserialize array item at \"{$itemPath}\": unknown type \"{$arrayItemType}\".",
        );
    }

    private function prependParamPath(string $message, string $prefix): string
    {
        return preg_replace_callback(
            '/param "([^"]+)"/',
            static fn(array $m): string => sprintf('param "%s.%s"', $prefix, $m[1]),
            $message,
        ) ?? $message;
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
            $dt = DateTimeImmutable::createFromFormat('Y-m-d|', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException(
                    "param \"{$paramPath}\" expects a date in Y-m-d format (e.g. 2026-03-10), got \"{$value}\"",
                );
            }
            return $dt;
        }

        // Reject structural mismatches before createFromFormat: it silently accepts trailing characters.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value) !== 1) {
            throw new RuntimeException(
                "param \"{$paramPath}\" expects a valid date-time (e.g. 2026-03-10T12:00:00+00:00), got \"{$value}\"",
            );
        }

        // date-time: try known RFC3339/ISO8601 formats; rejects relative strings like "now", "+1 year".
        foreach (GeneratedDtoInterface::DATE_TIME_FORMATS as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            // createFromFormat rolls overflowing components forward (e.g. Feb 30 → Mar 2) and
            // only flags it via warnings — reject those so invalid calendar dates aren't
            // silently accepted/mutated.
            $errors = DateTimeImmutable::getLastErrors();
            $hasWarnings = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
            if ($dt !== false && !$hasWarnings) {
                return $dt;
            }
        }

        throw new RuntimeException(
            "param \"{$paramPath}\" expects a valid date-time (e.g. 2026-03-10T12:00:00+00:00), got \"{$value}\"",
        );
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
            return null;
        }

        $getterName = 'get' . ucfirst($paramName);
        if (!$reflection->hasMethod($getterName)) {
            return null;
        }

        $docComment = $reflection->getMethod($getterName)->getDocComment();
        if ($docComment === false) {
            return null;
        }

        if (preg_match('/Expected format:\s*(.+)/i', $docComment, $matches) === 1) {
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
        if (is_array($fieldConstraints)) {
            if (array_key_exists('nullable', $fieldConstraints)) {
                return $fieldConstraints['nullable'] === true;
            }
            // OpenAPI 3.1: type: [string, null]
            $typeConstraint = $fieldConstraints['type'] ?? null;
            if (is_array($typeConstraint) && in_array('null', $typeConstraint, true)) {
                return true;
            }
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
     * Whether the array's items schema permits null elements (`items: {nullable: true}`
     * or an OpenAPI 3.1 type array containing "null").
     *
     * @param array<string, mixed>|null $fieldConstraints
     */
    private function resolveArrayItemsNullable(?array $fieldConstraints): bool
    {
        $items = $fieldConstraints['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }

        if (($items['nullable'] ?? false) === true) {
            return true;
        }

        $type = $items['type'] ?? null;
        return is_array($type) && in_array('null', $type, true);
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

        $rawType = ltrim($matches[1], '?\\');
        if (in_array($rawType, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
            return $rawType;
        }

        if (str_contains($rawType, '\\')) {
            return $rawType;
        }

        // Resolve via file-level use imports (e.g. short name imported at top of file)
        $fileImports = $this->resolveFileImports($reflection);
        if (array_key_exists($rawType, $fileImports)) {
            return $fileImports[$rawType];
        }

        return $reflection->getNamespaceName() !== ''
            ? $reflection->getNamespaceName() . '\\' . $rawType
            : $rawType;
    }

    private function getTypeString(mixed $value): string
    {
        if ($value instanceof stdClass) {
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
     */
    private function castToEnum(mixed $value, string $enumClass, ?string $paramPath = null, string $source = 'json'): UnitEnum
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
                // Non-JSON sources (query/path/form) deliver every value as a string, so an
                // int-backed enum case (value 1) never strict-equals the incoming "1". Allow a
                // string<->scalar match for those sources; JSON stays strict.
                if ($source !== 'json' && is_scalar($value) && (string)$case->value === (string)$value) {
                    return $case;
                }
                $allowed[] = (string)$case->value;
            } else {
                // Pure UnitEnum: match by name only
                if ($case->name === $value) {
                    return $case;
                }
                $allowed[] = $case->name;
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
        if (array_key_exists($className, self::$constraintsCache)) {
            return self::$constraintsCache[$className];
        }

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getConstraints']);
        if ($method === null) {
            return self::$constraintsCache[$className] = [];
        }

        $constraints = call_user_func($method);
        return self::$constraintsCache[$className] = is_array($constraints) ? $constraints : [];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function resolveOpenApiPropertyAliases(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (array_key_exists($className, self::$aliasesCache)) {
            return self::$aliasesCache[$className];
        }

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getAliases']);
        if ($method === null) {
            return self::$aliasesCache[$className] = [];
        }

        $aliases = call_user_func($method);
        if (!is_array($aliases)) {
            return self::$aliasesCache[$className] = [];
        }

        $result = [];
        foreach ($aliases as $propertyName => $openApiName) {
            if (!is_string($propertyName) || !is_string($openApiName)) {
                continue;
            }
            $result[$propertyName] = $openApiName;
        }

        return self::$aliasesCache[$className] = $result;
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
     */
    private function resolveMetadataMethod(string $className, array $candidateMethods): ?callable
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
        if (
            !method_exists($baseClass, 'getDiscriminatorPropertyName') || !method_exists(
                $baseClass,
                'getDiscriminatorMapping',
            )
        ) {
            return null;
        }

        $discriminatorProperty = $baseClass::getDiscriminatorPropertyName();
        $mapping = $baseClass::getDiscriminatorMapping();

        if (
            !is_string($discriminatorProperty) || $discriminatorProperty === '' || !is_array(
                $mapping,
            ) || $mapping === []
        ) {
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

        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            return false;
        }

        // Reject magnitudes outside PHP's int range: (int) cast would silently saturate to
        // PHP_INT_MAX/MIN, corrupting the value (and then passing int64 range validation).
        return $this->intStringInRange($value);
    }

    private function intStringInRange(string $value): bool
    {
        $negative = $value[0] === '-';
        $digits = ltrim(ltrim($value, '+-'), '0');
        if ($digits === '') {
            return true; // value is zero (any number of leading zeros)
        }

        // |PHP_INT_MIN| = 9223372036854775808, |PHP_INT_MAX| = 9223372036854775807.
        $bound = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) !== strlen($bound)) {
            return strlen($digits) < strlen($bound);
        }

        return strcmp($digits, $bound) <= 0;
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

    /**
     * Parses file-level `use` imports so short class names in docblocks can be resolved
     * without relying on the class namespace alone.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, string> map of short name → FQCN
     */
    private function resolveFileImports(ReflectionClass $reflection): array
    {
        $filename = $reflection->getFileName();
        if ($filename === false) {
            return [];
        }

        // Cache parsed imports per file (like reflection/meta caches) — the DTO source never
        // changes within a process, so the file_get_contents + preg_match_all runs once.
        if (array_key_exists($filename, self::$fileImportsCache)) {
            return self::$fileImportsCache[$filename];
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return self::$fileImportsCache[$filename] = [];
        }

        $result = preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m',
            $content,
            $matches,
            PREG_SET_ORDER,
        );
        if ($result === false || $result === 0) {
            return self::$fileImportsCache[$filename] = [];
        }

        $imports = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? '';
            $backslashPos = strrpos($fqcn, '\\');
            // No namespace separator → the FQCN is already the short name. (Guard against
            // strrpos() returning false, which (int)-casts to 0 and would drop the first char.)
            $shortName = $alias !== ''
                ? $alias
                : ($backslashPos === false ? $fqcn : substr($fqcn, $backslashPos + 1));
            $imports[$shortName] = $fqcn;
        }

        return self::$fileImportsCache[$filename] = $imports;
    }
}
