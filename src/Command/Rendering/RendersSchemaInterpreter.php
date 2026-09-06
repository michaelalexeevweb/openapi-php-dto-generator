<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * The schema INTERPRETER: the walker four of the five modes carry inside the class they emit.
 *
 * Laravel's rule vocabulary and Symfony's constraint attributes each cover part of what a schema can
 * say, and neither covers `contains`, `not`, `if`/`then`, `propertyNames`, `dependentSchemas`,
 * `unevaluated*` or a boolean subschema. What is left over is checked by a walker over the schema
 * LITERAL emitted next to it — and that walker is one piece of code, written once here.
 *
 * It is ordinary PHP. Nothing in what this trait emits references Symfony, Laravel or Yii; only the
 * ENTRY POINT differs, and each mode supplies its own:
 *
 *     symfony        #[Assert\Callback]        $context->buildViolation(...)->atPath(...)
 *     laravel        withValidator()           $validator->errors()->add($property, ...)
 *     laravel-data   the Laravel packaging, reached through RendersLaravelDto
 *     yii3           #[Callback]               $result->addError($message, valuePath: ...)
 *
 * Why one copy rather than four: the walk is ~1500 emitted lines, and the parity suites exist to
 * prove the modes agree about a schema. Sharing the walker makes that agreement true BY
 * CONSTRUCTION, and leaves the suites to check the edges. Four copies would make it a hope.
 *
 * This lived in `RendersSymfonyDto` until it was extracted here, which is why its methods used to
 * be named after that one mode. The one place the sharing is visible from the outside is
 * `RendersYii3Dto::yii3RepackageInterpreterEntry()`, which string-replaces the Symfony entry with its
 * own and throws if the needle stops matching.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 * @phpstan-import-type SchemaMetadata from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 *
 * @phpstan-ignore trait.unused
 */
trait RendersSchemaInterpreter
{
    /**
     * Every `format` the emitted interpreter can actually check, as the match arm that checks it and
     * the helpers that arm needs. A format absent from this map — or present with a `true` arm — is
     * one no mode enforces, which is what `openApiInterpreterChecksFormat()` reports.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const array OPENAPI_FORMAT_ARMS = [
        'date' => ['$this->isValidOpenApiDateFormat($value)', ['date']],
        'date-time' => ['$this->isValidOpenApiDateTimeFormat($value)', ['date-time']],
        'datetime' => ['$this->isValidOpenApiDateTimeFormat($value)', ['date-time']],
        'time' => ['$this->isValidOpenApiTimeFormat($value)', ['time']],
        'email' => ['filter_var($value, FILTER_VALIDATE_EMAIL) !== false', []],
        'idn-email' => ['filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false', []],
        'uuid' => ['$this->isValidOpenApiUuid($value)', ['uuid']],
        // `uri` and `iri` are ABSOLUTE — only the `*-reference` forms allow a relative value, and the
        // runtime validator has always drawn that line. Mapping all four to the reference check made the
        // emitted interpreter accept `/rel/path` for `format: uri` in both framework modes.
        'uri' => ['filter_var($value, FILTER_VALIDATE_URL) !== false', []],
        'iri' => ['$this->isValidOpenApiIri($value)', ['iri']],
        'uri-reference' => ['$this->isValidOpenApiUriReference($value)', ['uri-reference']],
        'iri-reference' => ['$this->isValidOpenApiUriReference($value)', ['uri-reference']],
        'uri-template' => ['$this->isValidOpenApiUriTemplate($value)', ['uri-reference', 'uri-template']],
        'duration' => ['$this->isValidOpenApiDuration($value)', ['duration']],
        'json-pointer' => ['$this->isValidOpenApiJsonPointer($value)', ['json-pointer']],
        'relative-json-pointer' => ['$this->isValidOpenApiRelativeJsonPointer($value)', ['relative-json-pointer']],
        'regex' => ['$this->isValidOpenApiRegexFormat($value)', ['regex']],
        'hostname' => ['filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false', []],
        'idn-hostname' => ['$this->isValidOpenApiIdnHostname($value)', ['idn-hostname']],
        'ipv4' => ['filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false', []],
        'ipv6' => ['filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false', []],
        'byte' => ['$this->isValidOpenApiBase64($value)', ['base64']],
        'password' => ['true', []],
        'binary' => ['true', []],
    ];

    /**
     * The numeric formats, checked by their own emitted bounds block rather than by a `match` arm in
     * `isValidOpenApiStringFormat()`.
     *
     * @var array<int, string>
     */
    private const array OPENAPI_NUMERIC_FORMATS = ['int32', 'int64', 'uint32', 'uint64'];

    /**
     * @param array<string, mixed> $constraints
     * @param array<string, string> $phpToOpenApiNameMap PHP name -> OpenAPI name mapping
     * @param array<string, string> $providedFlags PHP name -> presence flag property, optional properties only
     * @param array{enum: bool, temporal: bool} $valueKinds non-scalar value kinds the callback can meet
     * @param bool $payloadIsHydratedObject whether the interpreter runs over THIS object once it is built,
     *                                      as in Symfony mode. False for a mode that enters the interpreter
     *                                      its own way and feeds it the raw decoded payload, where neither
     *                                      the `#[Assert\Callback]` entry point, the object-payload view it
     *                                      needs, nor any nested-DTO handling can apply — see
     *                                      `RendersLaravelDto::renderLaravelInterpreterBlock()`.
     * @param array<string, mixed> $recursiveSchemas folded schemas a `x-openapi-recurse` marker points
     *                                               back into, keyed by class name. A recursive schema
     *                                               cannot be written out inline — the literal would be
     *                                               infinite — so it is emitted once and re-entered.
     * @param bool $ownsPayloadView whether the CALLER writes its own `toOpenApiValidationPayload()` and
     *                              replaces the one emitted here. Yii3 mode does: presence there is an
     *                              uninitialised property, not a boolean flag. Its two constants are read
     *                              by nothing once that swap happens, so they are not emitted either.
     * @return array{consts: string, methods: string, imports: array<int, string>}
     */
    private function renderInterpreterBlock(
        array $constraints,
        array $phpToOpenApiNameMap,
        array $providedFlags,
        array $valueKinds,
        bool $payloadIsHydratedObject = true,
        array $recursiveSchemas = [],
        bool $ownsPayloadView = false,
    ): array {
        if ($constraints === []) {
            return ['consts' => '', 'methods' => '', 'imports' => []];
        }

        // Before ANYTHING reads the schema: the emitted constant below and the keyword flags that
        // decide which branches to emit are both derived from this array, and a boolean left in it
        // would be invisible to both.
        $constraints = $this->expandBooleanSubschemasForInterpreter($constraints);

        $constraintsLiteral = $this->renderPhpArrayLiteral($constraints, 1);
        $nameMapLiteral = $this->renderPhpArrayLiteral($phpToOpenApiNameMap, 1);
        $providedFlagsLiteral = $this->renderPhpArrayLiteral($providedFlags, 1);
        // Which keyword blocks to emit is decided over the folded schemas as well: a `not` that appears
        // ONLY inside a recursive fold still has to have its block emitted, or the re-entered schema
        // would reference logic that is not there.
        $keywordSource = $constraints;
        foreach ($recursiveSchemas as $foldedClass => $fold) {
            $keywordSource['x-openapi-fold-' . $foldedClass] = $fold;
        }

        $methods = $this->renderInterpreterMethods(
            constraints: $keywordSource,
            valueKinds: $valueKinds,
            payloadIsHydratedObject: $payloadIsHydratedObject,
            resolvesRecursiveRefs: $recursiveSchemas !== [],
        );

        // The emitted helpers reference these classes unqualified, so the DTO needs the matching
        // imports — which ones depends on the keyword blocks that were actually emitted.
        $imports = [];
        foreach (['BackedEnum', 'DateTimeInterface', 'DateTimeImmutable'] as $referencedClass) {
            if (preg_match('/\b' . $referencedClass . '\b/', $methods) === 1) {
                $imports[] = $referencedClass;
            }
        }

        // The name map and the presence flags exist only for `toOpenApiValidationPayload()`, which
        // turns THIS object into a payload. A mode validating a raw array never calls it, and the
        // two constants would be emitted empty and read by nobody.
        $payloadViewConsts = $payloadIsHydratedObject && !$ownsPayloadView
            ? <<<PHP

    private const array OPENAPI_PHP_TO_NAME_MAP = {$nameMapLiteral};

    private const array OPENAPI_PROVIDED_FLAGS = {$providedFlagsLiteral};

PHP
            : '';

        $recursiveSchemaConsts = '';
        if ($recursiveSchemas !== []) {
            $recursiveLiteral = $this->renderPhpArrayLiteral($recursiveSchemas, 1);
            $recursiveSchemaConsts = <<<PHP

    /**
     * The schemas a `x-openapi-recurse` marker re-enters, keyed by the class they came from. A
     * self-referential schema has no finite inline form, so it is written once and followed at runtime;
     * `OPENAPI_MAX_VALIDATION_DEPTH` is what bounds the walk.
     *
     * @var array<string, mixed>
     */
    private const array OPENAPI_RECURSIVE_SCHEMAS = {$recursiveLiteral};

PHP;
        }

        return [
            'consts' => <<<PHP
    private const array OPENAPI_VALIDATION_CONSTRAINTS = {$constraintsLiteral};
{$payloadViewConsts}{$recursiveSchemaConsts}
    private const int OPENAPI_MAX_VALIDATION_DEPTH = 256;
PHP,
            'methods' => $methods,
            'imports' => $imports,
        ];
    }

    /**
     * @param array<string, mixed> $constraints
     * @param array{enum: bool, temporal: bool} $valueKinds
     */
    private function renderInterpreterMethods(
        array $constraints,
        array $valueKinds,
        bool $payloadIsHydratedObject = true,
        bool $resolvesRecursiveRefs = false,
    ): string {
        $hasConst = $this->schemaUsesKeyword($constraints, 'const');
        $hasType = $this->schemaUsesKeyword($constraints, 'type');
        $hasEnum = $this->schemaUsesKeyword($constraints, 'enum');
        $hasMinLength = $this->schemaUsesKeyword($constraints, 'minLength');
        $hasMaxLength = $this->schemaUsesKeyword($constraints, 'maxLength');
        $hasPattern = $this->schemaUsesKeyword($constraints, 'pattern');
        $hasFormat = $this->schemaUsesKeyword($constraints, 'format');
        $hasMinimum = $this->schemaUsesKeyword($constraints, 'minimum');
        $hasMaximum = $this->schemaUsesKeyword($constraints, 'maximum');
        $hasExclusiveMinimum = $this->schemaUsesKeyword($constraints, 'exclusiveMinimum');
        $hasExclusiveMaximum = $this->schemaUsesKeyword($constraints, 'exclusiveMaximum');
        $hasMultipleOf = $this->schemaUsesKeyword($constraints, 'multipleOf');
        $hasMinItems = $this->schemaUsesKeyword($constraints, 'minItems');
        $hasMaxItems = $this->schemaUsesKeyword($constraints, 'maxItems');
        $hasUniqueItems = $this->schemaUsesKeyword($constraints, 'uniqueItems');
        // `nullable` reaches the interpreter only where the mode has no other way to express it —
        // symfony and laravel carry nullability in the PHP type and the filter drops the keyword, so
        // this stays false there and the guard below is not emitted at all.
        $hasNullable = $this->schemaUsesKeyword($constraints, 'nullable');
        $hasMinProperties = $this->schemaUsesKeyword($constraints, 'minProperties');
        $hasMaxProperties = $this->schemaUsesKeyword($constraints, 'maxProperties');
        $hasRequired = $this->schemaUsesKeyword($constraints, 'required');
        $hasProperties = $this->schemaUsesKeyword($constraints, 'properties');
        $hasPatternProperties = $this->schemaUsesKeyword($constraints, 'patternProperties');
        $hasPropertyNames = $this->schemaUsesKeyword($constraints, 'propertyNames');
        $hasDependentRequired = $this->schemaUsesKeyword($constraints, 'dependentRequired');
        $hasDependentSchemas = $this->schemaUsesKeyword($constraints, 'dependentSchemas');
        $hasOneOf = $this->schemaUsesKeyword($constraints, 'oneOf');
        $hasAnyOf = $this->schemaUsesKeyword($constraints, 'anyOf');
        $hasAllOf = $this->schemaUsesKeyword($constraints, 'allOf');
        $hasNot = $this->schemaUsesKeyword($constraints, 'not');
        $hasIf = $this->schemaUsesKeyword($constraints, 'if');
        $hasPrefixItems = $this->schemaUsesKeyword($constraints, 'prefixItems');
        $hasItems = $this->schemaUsesKeyword($constraints, 'items');
        $hasContains = $this->schemaUsesKeyword($constraints, 'contains');
        $hasUnevaluatedItems = $this->schemaUsesKeyword($constraints, 'unevaluatedItems');
        $hasAdditionalProperties = $this->schemaUsesKeyword($constraints, 'additionalProperties');
        $hasUnevaluatedProperties = $this->schemaUsesKeyword($constraints, 'unevaluatedProperties');
        $hasContentEncoding = $this->schemaUsesKeyword($constraints, 'contentEncoding');
        $hasContentMediaType = $this->schemaUsesKeyword($constraints, 'contentMediaType');
        $hasContentSchema = $this->schemaUsesKeyword($constraints, 'contentSchema');

        $needsListLogic = $hasPrefixItems || $hasItems || $hasContains || $hasUnevaluatedItems;
        // The defined/pattern-matched property sets and the evaluated item indices exist only to
        // feed the additionalProperties / unevaluatedProperties / unevaluatedItems checks — without
        // those keywords they would be written but never read, so do not emit them at all.
        $needsObjectTracking = $hasAdditionalProperties || $hasUnevaluatedProperties;
        $needsItemIndexTracking = $hasUnevaluatedItems;
        // prefixItems consumes the leading positions; `items` only needs the offset when both are
        // present in the same schema.
        $needsPrefixOffset = $hasPrefixItems && $hasItems;
        $needsMatcher = $hasContains || $hasNot || $hasIf || $hasOneOf || $hasAnyOf;
        $needsTypeMatcher = $hasType;
        $needsStringValidation = $hasMinLength || $hasMaxLength || $hasPattern || $hasFormat;
        $needsNumericValidation = $hasMinimum || $hasMaximum || $hasExclusiveMinimum || $hasExclusiveMaximum || $hasMultipleOf;

        // The emitted validator interprets this DTO's own constant, so the keyword *values* are
        // known here as well: only the branches those values can reach are worth emitting.
        $usedTypes = $this->collectSchemaKeywordStrings($constraints, 'type');
        $hasUnionType = $this->schemaKeywordHasListValue($constraints, 'type');
        $usedFormats = $this->collectSchemaKeywordStrings($constraints, 'format');
        $usedContentEncodings = array_map('strtolower', $this->collectSchemaKeywordStrings($constraints, 'contentEncoding'));
        $usedNumericFormats = array_values(array_intersect($usedFormats, self::OPENAPI_NUMERIC_FORMATS));
        $needsNumericFormatValidation = $usedNumericFormats !== [];
        $hasMinContains = $this->schemaUsesKeyword($constraints, 'minContains');
        $hasMaxContains = $this->schemaUsesKeyword($constraints, 'maxContains');
        $needsCollectionCountValidation = $hasMinItems || $hasMaxItems || $hasUniqueItems || $hasMinProperties || $hasMaxProperties;
        $needsValueNormalization = $hasConst
            || $hasEnum
            || $hasType
            || $needsNumericValidation
            || $needsNumericFormatValidation
            || $needsStringValidation;
        $canHoldEnum = $valueKinds['enum'];
        $canHoldTemporal = $valueKinds['temporal'];
        $needsStructuralNormalization = $hasRequired
            || $hasProperties
            || $hasPatternProperties
            || $hasPropertyNames
            || $hasDependentRequired
            || $hasDependentSchemas
            || $hasAdditionalProperties
            || $hasUnevaluatedProperties
            // These read a nested DTO's state, which is private: they need the payload view.
            // uniqueItems compares items by the payload they report; the property count and the
            // discriminator check read it the same way.
            || $hasUniqueItems
            || $needsCollectionCountValidation
            || $hasOneOf;
        // Skipping a nested DTO — it validates its own schema through its own callback — is a question
        // only a hydrated payload can raise. Over a raw decoded payload every value is an array or a
        // scalar, so the helper would be emitted to answer `false` at four call sites.
        $needsGeneratedDtoSkip = $payloadIsHydratedObject
            && ($hasProperties || $hasPrefixItems || $hasItems || $hasAdditionalProperties);
        // The guard at each of the four places a nested value is walked into, emitted only when such a
        // value can be a DTO at all.
        $skipNestedDto = static function (
            string $accessor,
            string $indent = '                ',
            string $extraBeforeContinue = '',
        ) use ($needsGeneratedDtoSkip): string {
            if (!$needsGeneratedDtoSkip) {
                return '';
            }

            return sprintf(
                "%sif (\$this->isGeneratedOpenApiDtoObject(%s)) {\n%s%s    continue;\n%s}\n",
                $indent,
                $accessor,
                $extraBeforeContinue,
                $indent,
                $indent,
            );
        };

        $sections = [];
        // The Symfony entry point and the object-payload view it feeds the interpreter. A mode that
        // enters the interpreter differently and hands it a raw array (Laravel) must not carry them:
        // the attribute would reference constraint classes the DTO does not import, and the payload
        // view reads instance properties that the static packaging cannot reach.
        if ($payloadIsHydratedObject) {
            $sections[] = <<<'PHP'
    #[Assert\Callback]
    public function validateOpenApiConstraints(ExecutionContextInterface $context): void
    {
        foreach ($this->validateOpenApiNode($this->toOpenApiValidationPayload(), self::OPENAPI_VALIDATION_CONSTRAINTS, 'payload', 0) as $error) {
            $violation = $context->buildViolation(str_ends_with($error, '.') ? $error : $error . '.');
            $path = self::openApiViolationPath($error);
            if ($path !== null) {
                $violation->atPath($path);
            }
            $violation->addViolation();
        }
    }

    /**
     * This DTO as an OpenAPI-named payload — machinery for the generated validation, not an API to
     * call. It is public only because an enclosing DTO is a different class and needs this view of
     * a nested one. For output, use the Symfony serializer.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toOpenApiValidationPayload(): array
    {
        $payload = [];
        foreach (self::OPENAPI_PHP_TO_NAME_MAP as $phpName => $openApiName) {
            // A required property always exists. An optional one belongs in the payload only when
            // it was actually provided — its presence flag says so, which is also what keeps the
            // flags themselves out of the payload (iterating the object's properties would put
            // them in and trip `additionalProperties: false`).
            $flag = self::OPENAPI_PROVIDED_FLAGS[$phpName] ?? null;
            if ($flag !== null && $this->{$flag} !== true) {
                continue;
            }
            $payload[$openApiName] = $this->{$phpName};
        }

        return $payload;
    }

PHP;
        }

        // A self-referential schema is stored once and pointed at. Resolved here, at the single entry
        // point of the walk, so every position that can hold one — a property, an item, a branch — gets
        // it for free. The local keywords win over the stored ones: `nullable` is added at the marker.
        $resolveRecursiveRef = $resolvesRecursiveRefs
            ? <<<'PHP'

        $recursiveRef = $schema['x-openapi-recurse'] ?? null;
        if (is_string($recursiveRef) && array_key_exists($recursiveRef, self::OPENAPI_RECURSIVE_SCHEMAS)) {
            /** @var array<string, mixed> $resolved */
            $resolved = self::OPENAPI_RECURSIVE_SCHEMAS[$recursiveRef];
            $schema = [...$resolved, ...array_diff_key($schema, ['x-openapi-recurse' => true])];
        }
PHP
            : '';

        $sections[] = <<<PHP
    /**
     * @param array<string, mixed> \$schema
     * @return array<int, string>
     */
    private function validateOpenApiNode(mixed \$value, array \$schema, string \$path, int \$depth): array
    {
        if (\$depth >= self::OPENAPI_MAX_VALIDATION_DEPTH) {
            return [];
        }
{$resolveRecursiveRef}
        \$errors = [];
PHP;

        // The local setup lines belong to the same statement group as `$errors = []`, so they are
        // appended to that section instead of becoming blank-line separated sections of their own.
        $prologue = [];
        if ($hasNullable) {
            // A null the schema explicitly allows satisfies the node outright. Every other keyword
            // here describes a string, a number or a container, so running them against null only
            // produces a second message about a value the document permits.
            $prologue[] = '        if ($value === null && ($schema[\'nullable\'] ?? false) === true) {';
            $prologue[] = '            return [];';
            $prologue[] = '        }';
            $prologue[] = '';
        }
        if ($needsValueNormalization) {
            // With neither enums nor dates in play there is nothing to normalize, so skip the hop.
            $prologue[] = $canHoldEnum || $canHoldTemporal
                ? '        $normalizedValue = $this->normalizeOpenApiCallbackValue($value, $schema);'
                : '        $normalizedValue = $value;';
        }

        if ($needsStructuralNormalization) {
            $prologue[] = '        $structuredValue = $this->normalizeOpenApiStructuralValue($value);';
        }

        if ($prologue !== []) {
            $sections[array_key_last($sections)] .= "\n" . implode("\n", $prologue);
        }

        if ($hasConst) {
            $sections[] = <<<'PHP'
        if (array_key_exists('const', $schema) && $normalizedValue !== $schema['const']) {
            $expected = json_encode($schema['const']);
            $errors[] = sprintf('%s must equal %s', $path, $expected !== false ? $expected : var_export($schema['const'], true));
        }
PHP;
        }

        if ($hasEnum) {
            $sections[] = <<<'PHP'
        if (is_array($schema['enum'] ?? null) && !in_array($normalizedValue, $schema['enum'], true)) {
            $allowed = implode(', ', array_map(
                static function (mixed $allowedValue): string {
                    $json = json_encode($allowedValue);
                    return $json !== false ? $json : var_export($allowedValue, true);
                },
                $schema['enum'],
            ));
            $errors[] = sprintf('%s must be one of: %s', $path, $allowed);
        }
PHP;
        }

        if ($hasOneOf) {
            // Mirror the runtime validator: a branch whose declared type cannot match is skipped
            // instead of contributing a misleading "must be of type ..." reason.
            $oneOfTypeGate = $needsTypeMatcher
                ? <<<'PHP'
                if (array_key_exists('type', $branch) && !$this->matchesOpenApiCallbackType($normalizedValue, $branch['type'])) {
                    continue;
                }

PHP
                : '';
            $sections[] = <<<PHP
        if (is_array(\$schema['oneOf'] ?? null)) {
            \$matchingBranches = 0;
            \$branchErrors = [];
            foreach (\$schema['oneOf'] as \$branch) {
                if (!is_array(\$branch)) {
                    continue;
                }

                if (is_string(\$branchClass = \$branch['x-php-instanceof'] ?? null) && \$branchClass !== '') {
                    \$branchFqcn = __NAMESPACE__ . '\\\\' . ltrim(\$branchClass, '\\\\');
                    if (is_object(\$value) && \$value instanceof \$branchFqcn) {
                        \$matchingBranches++;
                    }
                    continue;
                }

{$oneOfTypeGate}                // Keep why each branch failed: with no match at all those reasons are far more
                // useful than "does not match any oneOf branch".
                \$errorsForBranch = \$this->validateOpenApiNode(\$value, \$branch, \$path, \$depth + 1);
                if (\$errorsForBranch === []) {
                    \$matchingBranches++;
                    continue;
                }

                \$branchErrors = [...\$branchErrors, ...\$errorsForBranch];
            }

            if (\$matchingBranches > 1) {
                \$errors[] = sprintf('%s matches more than one allowed oneOf branch', \$path);
            } elseif (\$matchingBranches === 0) {
                \$errors = \$branchErrors === []
                    ? [...\$errors, \$this->describeOpenApiUnionMismatch(\$path, 'oneOf', \$schema['oneOf'], \$value)]
                    : [...\$errors, ...array_values(array_unique(\$branchErrors))];
            }

            if (\$matchingBranches === 1) {
                \$discriminatorProperty = is_string(\$schema['x-discriminator-property'] ?? null)
                    ? \$schema['x-discriminator-property']
                    : null;
                \$discriminatorPhpProperty = is_string(\$schema['x-discriminator-php-property'] ?? null)
                    ? \$schema['x-discriminator-php-property']
                    : null;
                \$discriminatorMap = is_array(\$schema['x-discriminator-map'] ?? null)
                    ? \$schema['x-discriminator-map']
                    : null;

                if (\$discriminatorProperty !== null && \$discriminatorMap !== null && is_object(\$value)) {
                    \$phpProperty = \$discriminatorPhpProperty ?? \$discriminatorProperty;
                    // The DTO keeps its state private: read the discriminator from the payload it
                    // reports, keyed by the OpenAPI name, and fall back to a public property for a
                    // hand-written object.
                    \$discriminatorPayload = \$this->normalizeOpenApiStructuralValue(\$value);
                    \$discriminatorCarrier = is_array(\$discriminatorPayload) ? \$discriminatorPayload : [];
                    if (!array_key_exists(\$discriminatorProperty, \$discriminatorCarrier) && !property_exists(\$value, \$phpProperty)) {
                        \$errors[] = sprintf('%s discriminator property %s is required', \$path, \$discriminatorProperty);
                    } else {
                        \$discriminatorValue = \$discriminatorCarrier[\$discriminatorProperty]
                            ?? (property_exists(\$value, \$phpProperty) ? \$value->{\$phpProperty} : null);
                        \$mapKey = is_scalar(\$discriminatorValue) ? (string)\$discriminatorValue : null;
                        if (\$mapKey === null || !array_key_exists(\$mapKey, \$discriminatorMap)) {
                            \$errors[] = sprintf(
                                '%s discriminator %s must be one of: %s',
                                \$path,
                                \$discriminatorProperty,
                                implode(', ', array_keys(\$discriminatorMap)),
                            );
                        } elseif (is_string(\$expectedClass = \$discriminatorMap[\$mapKey]) && \$expectedClass !== '') {
                            \$expectedFqcn = __NAMESPACE__ . '\\\\' . ltrim(\$expectedClass, '\\\\');
                            if (!\$value instanceof \$expectedFqcn) {
                                \$errors[] = sprintf('%s discriminator %s must match concrete class', \$path, \$discriminatorProperty);
                            }
                        }
                    }
                }
            }
        }
PHP;
        }

        if ($hasType) {
            $unionTypeBranch = $hasUnionType
                ? <<<'PHP'
            } elseif (is_array($typeConstraint)) {
                $typeMatched = false;
                foreach ($typeConstraint as $candidateType) {
                    if (is_string($candidateType) && $this->matchesOpenApiCallbackType($normalizedValue, $candidateType)) {
                        $typeMatched = true;
                        break;
                    }
                }
                if (!$typeMatched) {
                    $errors[] = sprintf('%s must be of type %s', $path, implode('|', array_filter($typeConstraint, 'is_string')));
                }

PHP
                : '';
            $listTypeMessage = in_array('array', $usedTypes, true)
                ? <<<'PHP'
                    $errors[] = $typeConstraint === 'array' && is_array($normalizedValue)
                        ? sprintf('%s must be a JSON array (list with sequential keys), got an associative array', $path)
                        : sprintf('%s must be of type %s', $path, $typeConstraint);
PHP
                : <<<'PHP'
                    $errors[] = sprintf('%s must be of type %s', $path, $typeConstraint);
PHP;
            $sections[] = <<<PHP
        if (array_key_exists('type', \$schema)) {
            \$typeConstraint = \$schema['type'];
            if (is_string(\$typeConstraint)) {
                if (!\$this->matchesOpenApiCallbackType(\$normalizedValue, \$typeConstraint)) {
{$listTypeMessage}
                }
{$unionTypeBranch}            }
        }
PHP;
        }

        if ($needsNumericValidation) {
            // Per keyword, like the string block below. One shared gate emitted all five checks for
            // a schema carrying one of them, so a `minimum: 10` property was also asked about
            // `exclusiveMinimum`, `exclusiveMaximum` and `multipleOf` — keywords the document never
            // wrote. The verdict was the same; the generated method was 40 lines longer than the spec.
            $numericKeywords = array_values(array_filter(
                ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
                fn(string $keyword): bool => $this->schemaUsesKeyword($constraints, $keyword),
            ));
            $numericKeywordList = "'" . implode("', '", $numericKeywords) . "'";

            // Three shapes per bound, because `exclusiveMinimum` has two spellings: a NUMBER
            // (JSON Schema 2020-12) and a boolean modifier on `minimum` (OpenAPI 3.0). Only the
            // spellings this document actually wrote are emitted — a schema saying `minimum: 10`
            // has no runtime way to grow an `exclusiveMinimum`, so those branches would be dead.
            $bound = static function (
                bool $hasInclusive,
                bool $hasExclusive,
                string $inclusiveKey,
                string $exclusiveKey,
                string $reader,
                string $comparison,
                string $exclusiveMessage,
                string $inclusiveMessage,
            ): string {
                if ($hasInclusive && $hasExclusive) {
                    return <<<PHP
            \${$exclusiveKey} = \$schema['{$exclusiveKey}'] ?? null;
            if (is_numeric(\${$exclusiveKey})) {
                \$bound = (float)\${$exclusiveKey};
                if (!(\$normalizedValue {$comparison} \$bound)) {
                    \$errors[] = sprintf('%s {$exclusiveMessage} %s', \$path, \$this->stringifyOpenApiNumber(\$bound));
                }
            } elseif (\${$reader} !== null) {
                if ((\$schema['{$exclusiveKey}'] ?? null) === true) {
                    if (!(\$normalizedValue {$comparison} \${$reader})) {
                        \$errors[] = sprintf('%s {$exclusiveMessage} %s', \$path, \$this->stringifyOpenApiNumber(\${$reader}));
                    }
                } elseif (!(\$normalizedValue {$comparison}= \${$reader})) {
                    \$errors[] = sprintf('%s {$inclusiveMessage} %s', \$path, \$this->stringifyOpenApiNumber(\${$reader}));
                }
            }

PHP;
                }

                if ($hasExclusive) {
                    return <<<PHP
            \${$exclusiveKey} = \$schema['{$exclusiveKey}'] ?? null;
            if (is_numeric(\${$exclusiveKey}) && !(\$normalizedValue {$comparison} (float)\${$exclusiveKey})) {
                \$errors[] = sprintf('%s {$exclusiveMessage} %s', \$path, \$this->stringifyOpenApiNumber((float)\${$exclusiveKey}));
            }

PHP;
                }

                if ($hasInclusive) {
                    return <<<PHP
            \${$reader} = \$this->toFloatConstraint(\$schema['{$inclusiveKey}'] ?? null);
            if (\${$reader} !== null && !(\$normalizedValue {$comparison}= \${$reader})) {
                \$errors[] = sprintf('%s {$inclusiveMessage} %s', \$path, \$this->stringifyOpenApiNumber(\${$reader}));
            }

PHP;
                }

                return '';
            };

            // The bound reader is shared by both branches of the two-spelling shape, so it is hoisted
            // there and inlined in the single-spelling one.
            $numericChecks = '';
            if ($hasMinimum && $hasExclusiveMinimum) {
                $numericChecks .= "            \$minimum = \$this->toFloatConstraint(\$schema['minimum'] ?? null);\n\n";
            }
            $numericChecks .= $bound($hasMinimum, $hasExclusiveMinimum, 'minimum', 'exclusiveMinimum', 'minimum', '>', 'must be greater than', 'must be greater than or equal to');

            if ($hasMaximum && $hasExclusiveMaximum) {
                $numericChecks .= "            \$maximum = \$this->toFloatConstraint(\$schema['maximum'] ?? null);\n\n";
            }
            $numericChecks .= $bound($hasMaximum, $hasExclusiveMaximum, 'maximum', 'exclusiveMaximum', 'maximum', '<', 'must be less than', 'must be less than or equal to');

            if ($hasMultipleOf) {
                $numericChecks .= <<<'PHP'
            $multipleOf = $this->toFloatConstraint($schema['multipleOf'] ?? null);
            if ($multipleOf !== null && $multipleOf > 0.0) {
                $ratio = $normalizedValue / $multipleOf;
                if (abs($ratio - round($ratio)) > 1e-9) {
                    $errors[] = sprintf('%s must be a multiple of %s', $path, $this->stringifyOpenApiNumber($multipleOf));
                }
            }

PHP;
            }

            $sections[] = sprintf(
                "        if ((is_int(\$normalizedValue) || is_float(\$normalizedValue)) && \$this->schemaHasAnyOpenApiKey(\$schema, [%s])) {\n%s        }",
                $numericKeywordList,
                rtrim($numericChecks, "\n") . "\n",
            );
        }

        if ($needsNumericFormatValidation) {
            $sections[] = <<<'PHP'
        if ((is_int($normalizedValue) || is_float($normalizedValue)) && is_string($format = $schema['format'] ?? null)) {
            $errors = [...$errors, ...$this->validateOpenApiNumericFormat($path, $normalizedValue, $format)];
        }
PHP;
        }

        if ($needsStringValidation) {
            // Only the string keywords this schema actually uses get a check (and only then does
            // the block need the length reader at all).
            $stringKeywords = array_values(array_filter(
                ['minLength', 'maxLength', 'pattern', 'format'],
                fn(string $keyword): bool => $this->schemaUsesKeyword($constraints, $keyword),
            ));
            $stringKeywordList = "'" . implode("', '", $stringKeywords) . "'";

            $lengthChecks = '';
            if ($hasMinLength || $hasMaxLength) {
                $lengthChecks .= "            \$length = mb_strlen(\$normalizedValue);\n";
            }
            if ($hasMinLength) {
                $lengthChecks .= <<<'PHP'
            $minLength = $this->toIntConstraint($schema['minLength'] ?? null);
            if ($minLength !== null && $length < $minLength) {
                $errors[] = sprintf('%s length must be at least %d characters', $path, $minLength);
            }

PHP;
            }
            if ($hasMaxLength) {
                $lengthChecks .= <<<'PHP'
            $maxLength = $this->toIntConstraint($schema['maxLength'] ?? null);
            if ($maxLength !== null && $length > $maxLength) {
                $errors[] = sprintf('%s length must be at most %d characters', $path, $maxLength);
            }

PHP;
            }

            $patternCheck = $hasPattern ? <<<'PHP'
            if (is_string($pattern = $schema['pattern'] ?? null) && $pattern !== '') {
                $regex = '#' . str_replace('#', '\#', $pattern) . '#u';
                set_error_handler(static fn(): bool => true);
                try {
                    $match = preg_match($regex, $normalizedValue);
                } finally {
                    restore_error_handler();
                }

                if ($match === false) {
                    if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
                        $errors[] = sprintf('%s contains invalid UTF-8 characters', $path);
                    } else {
                        $errors[] = sprintf('%s has invalid regex pattern in schema: %s', $path, $pattern);
                    }
                } elseif ($match !== 1) {
                    $errors[] = sprintf('%s must match pattern %s', $path, $pattern);
                }
            }

PHP : '';

            $formatCheck = $hasFormat ? <<<'PHP'
            if (is_string($format = $schema['format'] ?? null) && !$this->isValidOpenApiStringFormat($normalizedValue, $format)) {
                $errors[] = sprintf('%s must match format %s', $path, $format);
            }

PHP : '';

            $stringBody = rtrim($lengthChecks . $patternCheck . $formatCheck, "\n");
            $sections[] = <<<PHP
        if (is_string(\$normalizedValue) && \$this->schemaHasAnyOpenApiKey(\$schema, [{$stringKeywordList}])) {
{$stringBody}
        }
PHP;
        }

        if ($hasContentEncoding || $hasContentMediaType || $hasContentSchema) {
            $sections[] = <<<'PHP'
        if (is_string($value) && $this->schemaHasAnyOpenApiKey($schema, ['contentEncoding', 'contentMediaType', 'contentSchema'])) {
            $decoded = $value;
            if (is_string($encoding = $schema['contentEncoding'] ?? null)) {
                $decodedValue = $this->decodeOpenApiContent($value, $encoding);
                if ($decodedValue === null) {
                    $errors[] = sprintf('%s is not valid %s-encoded content', $path, $encoding);
                    return $errors;
                }
                $decoded = $decodedValue;
            }

            $mediaType = $schema['contentMediaType'] ?? null;
            if (is_string($mediaType) && $this->isOpenApiJsonMediaType($mediaType)) {
                $parsed = json_decode($decoded, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = sprintf('%s is not valid %s content', $path, $mediaType);
                } elseif (is_array($schema['contentSchema'] ?? null)) {
                    $errors = [
                        ...$errors,
                        ...$this->validateOpenApiNode($parsed, $schema['contentSchema'], $path, $depth + 1),
                    ];
                }
            }
        }
PHP;
        }

        if ($needsCollectionCountValidation) {
            $sections[] = <<<'PHP'
        if (is_array($value) || is_object($value)) {
            $structural = is_array($value) ? $value : $this->normalizeOpenApiStructuralValue($value);
            $valueArray = is_array($structural) ? array_filter(
                $structural,
                static fn(mixed $propertyValue): bool => $propertyValue !== null,
            ) : [];
            $count = count($valueArray);
            $minItems = $this->toIntConstraint($schema['minItems'] ?? null);
            if ($minItems !== null && is_array($value) && $count < $minItems) {
                $errors[] = sprintf('%s must contain at least %d items', $path, $minItems);
            }

            $maxItems = $this->toIntConstraint($schema['maxItems'] ?? null);
            if ($maxItems !== null && is_array($value) && $count > $maxItems) {
                $errors[] = sprintf('%s must contain at most %d items', $path, $maxItems);
            }

            $minProperties = $this->toIntConstraint($schema['minProperties'] ?? null);
            if ($minProperties !== null && $count < $minProperties) {
                $errors[] = sprintf('%s must have at least %d %s', $path, $minProperties, $minProperties === 1 ? 'property' : 'properties');
            }

            $maxProperties = $this->toIntConstraint($schema['maxProperties'] ?? null);
            if ($maxProperties !== null && $count > $maxProperties) {
                $errors[] = sprintf('%s must have at most %d %s', $path, $maxProperties, $maxProperties === 1 ? 'property' : 'properties');
            }

            if (is_array($value) && ($schema['uniqueItems'] ?? false) === true) {
                $seen = [];
                foreach ($valueArray as $item) {
                    if (is_scalar($item) || $item === null) {
                        $fingerprint = 's:' . var_export($item, true);
                    } else {
                        // A generated DTO keeps its state private, so compare the payload it
                        // reports rather than the object, which would encode as {} every time.
                        $comparable = $this->normalizeOpenApiStructuralValue($item);
                        $json = json_encode($comparable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $fingerprint = is_string($json) ? 'j:' . $json : 'j:' . serialize($comparable);
                    }
                    if (array_key_exists($fingerprint, $seen)) {
                        $errors[] = sprintf('%s must contain unique items', $path);
                        break;
                    }
                    $seen[$fingerprint] = true;
                }
            }
        }
PHP;
        }

        if ($hasRequired) {
            $sections[] = <<<'PHP'
        if (is_array($structuredValue) && is_array($schema['required'] ?? null)) {
            foreach ($schema['required'] as $requiredProp) {
                if (is_string($requiredProp) && !array_key_exists($requiredProp, $structuredValue)) {
                    $errors[] = sprintf('%s is required', $this->openApiChildPath($path, $requiredProp));
                }
            }
        }
PHP;
        }

        if ($needsObjectTracking) {
            $sections[] = <<<'PHP'
        $definedProperties = [];
        $patternMatchedProperties = [];
PHP;
        }

        if ($hasProperties) {
            $trackDefined = $needsObjectTracking
                ? "                \$definedProperties[\$propertyName] = true;\n"
                : '';
            $skipNestedDtoProperty = $skipNestedDto('$structuredValue[$propertyName]');
            $sections[] = <<<PHP
        if (is_array(\$schema['properties'] ?? null) && is_array(\$structuredValue)) {
            foreach (\$schema['properties'] as \$propertyName => \$propertySchema) {
                if (!is_string(\$propertyName) || !is_array(\$propertySchema)) {
                    continue;
                }
{$trackDefined}                if (!array_key_exists(\$propertyName, \$structuredValue)) {
                    continue;
                }
{$skipNestedDtoProperty}                \$errors = [
                    ...\$errors,
                    ...\$this->validateOpenApiNode(\$structuredValue[\$propertyName], \$propertySchema, \$this->openApiChildPath(\$path, \$propertyName), \$depth + 1),
                ];
            }
        }
PHP;
        }

        if ($hasPatternProperties) {
            $trackPatternMatched = $needsObjectTracking
                ? "                    \$patternMatchedProperties[\$propertyName] = true;\n"
                : '';
            $sections[] = <<<PHP
        if (is_array(\$schema['patternProperties'] ?? null) && is_array(\$structuredValue)) {
            foreach (\$schema['patternProperties'] as \$pattern => \$propertySchema) {
                if (!is_string(\$pattern) || !is_array(\$propertySchema)) {
                    continue;
                }
                \$regex = '/' . str_replace('/', '\\/', \$pattern) . '/';
                foreach (\$structuredValue as \$propertyName => \$propertyValue) {
                    if (!is_string(\$propertyName) || preg_match(\$regex, \$propertyName) !== 1) {
                        continue;
                    }
{$trackPatternMatched}                    \$errors = [
                        ...\$errors,
                        ...\$this->validateOpenApiNode(\$propertyValue, \$propertySchema, \$this->openApiChildPath(\$path, \$propertyName), \$depth + 1),
                    ];
                }
            }
        }
PHP;
        }

        if ($hasPropertyNames) {
            $sections[] = <<<'PHP'
        if (is_array($schema['propertyNames'] ?? null) && is_array($structuredValue)) {
            foreach (array_keys($structuredValue) as $propertyName) {
                if (!is_string($propertyName)) {
                    continue;
                }
                $errors = [
                    ...$errors,
                    ...$this->validateOpenApiNode($propertyName, $schema['propertyNames'], sprintf('%s key "%s"', $path, $propertyName), $depth + 1),
                ];
            }
        }
PHP;
        }

        if ($hasDependentRequired) {
            $sections[] = <<<'PHP'
        if (is_array($schema['dependentRequired'] ?? null) && is_array($structuredValue)) {
            foreach ($schema['dependentRequired'] as $propertyName => $deps) {
                if (!is_string($propertyName) || !is_array($deps) || !array_key_exists($propertyName, $structuredValue)) {
                    continue;
                }
                foreach ($deps as $depProperty) {
                    if (is_string($depProperty) && !array_key_exists($depProperty, $structuredValue)) {
                        $errors[] = sprintf('%s is required when %s is present', $this->openApiChildPath($path, $depProperty), $propertyName);
                    }
                }
            }
        }
PHP;
        }

        if ($hasDependentSchemas) {
            $sections[] = <<<'PHP'
        if (is_array($schema['dependentSchemas'] ?? null) && is_array($structuredValue)) {
            foreach ($schema['dependentSchemas'] as $propertyName => $depSchema) {
                if (!is_string($propertyName) || !is_array($depSchema) || !array_key_exists($propertyName, $structuredValue)) {
                    continue;
                }
                $errors = [...$errors, ...$this->validateOpenApiNode($structuredValue, $depSchema, $path, $depth + 1)];
            }
        }
PHP;
        }

        if ($hasNot) {
            $sections[] = <<<'PHP'
        if (is_array($schema['not'] ?? null) && $this->matchesOpenApiNode($value, $schema['not'], $depth + 1)) {
            $errors[] = sprintf('%s must not match the \'not\' schema', $path);
        }
PHP;
        }

        if ($hasIf) {
            $sections[] = <<<'PHP'
        if (is_array($schema['if'] ?? null)) {
            if ($this->matchesOpenApiNode($value, $schema['if'], $depth + 1)) {
                if (is_array($schema['then'] ?? null)) {
                    $errors = [...$errors, ...$this->validateOpenApiNode($value, $schema['then'], $path, $depth + 1)];
                }
            } elseif (is_array($schema['else'] ?? null)) {
                $errors = [...$errors, ...$this->validateOpenApiNode($value, $schema['else'], $path, $depth + 1)];
            }
        }
PHP;
        }

        if ($needsListLogic) {
            $listDeclarations = ['        $listValue = is_array($value) && array_is_list($value);'];
            if ($needsItemIndexTracking) {
                $listDeclarations[] = '        $evaluatedItemIndices = [];';
            }
            if ($needsPrefixOffset) {
                $listDeclarations[] = '        $prefixCount = 0;';
            }
            $sections[] = implode("\n", $listDeclarations);
        }

        if ($hasPrefixItems) {
            $prefixOffsetAssignment = $needsPrefixOffset
                ? "            \$prefixCount = count(\$schema['prefixItems']);\n"
                : '';
            $trackPrefixIndex = $needsItemIndexTracking
                ? "                \$evaluatedItemIndices[\$index] = true;\n"
                : '';
            $skipNestedDtoPrefixItem = $skipNestedDto('$value[$index]');
            $sections[] = <<<PHP
        if (\$listValue && is_array(\$schema['prefixItems'] ?? null)) {
{$prefixOffsetAssignment}            foreach (\$schema['prefixItems'] as \$index => \$itemSchema) {
                if (!is_array(\$itemSchema) || !array_key_exists(\$index, \$value)) {
                    continue;
                }
{$trackPrefixIndex}{$skipNestedDtoPrefixItem}                \$errors = [
                    ...\$errors,
                    ...\$this->validateOpenApiNode(\$value[\$index], \$itemSchema, \$path . '[' . \$index . ']', \$depth + 1),
                ];
            }
        }
PHP;
        }

        if ($hasItems) {
            $skipPrefixItems = $needsPrefixOffset
                ? "                if (\$index < \$prefixCount) {\n                    continue;\n                }\n"
                : '';
            $trackItemIndex = $needsItemIndexTracking
                ? "                \$evaluatedItemIndices[\$index] = true;\n"
                : '';
            $skipNestedDtoItem = $skipNestedDto('$itemValue');
            // `items: false` is the BOOLEAN spelling, and it is what closes a tuple: `prefixItems`
            // names the members, `items: false` says there are no others. Only the schema spelling was
            // read here, so a closed tuple accepted any tail — and the constants did not even carry the
            // keyword until the filter learned to keep a boolean. Two modes went with it: yii3 emits
            // this same interpreter. Measured against runtime, which refused the identical payload.
            $sections[] = <<<PHP
        if (\$listValue && (\$schema['items'] ?? null) === false) {
            foreach (array_keys(\$value) as \$index) {
{$skipPrefixItems}                \$errors[] = sprintf('%s has an item at index %s which the schema does not allow', \$path, \$index);
            }
        }
PHP;
            $sections[] = <<<PHP
        if (\$listValue && is_array(\$schema['items'] ?? null)) {
            foreach (\$value as \$index => \$itemValue) {
{$skipPrefixItems}{$trackItemIndex}{$skipNestedDtoItem}                \$errors = [
                    ...\$errors,
                    ...\$this->validateOpenApiNode(\$itemValue, \$schema['items'], \$path . '[' . \$index . ']', \$depth + 1),
                ];
            }
        }
PHP;
        }

        if ($hasContains) {
            $trackContainsIndex = $needsItemIndexTracking
                ? "                    \$evaluatedItemIndices[\$index] = true;\n"
                : '';
            $containsIndexVariable = $needsItemIndexTracking ? '$index' : '$_index';
            // `contains` defaults to "at least one match"; the bounds only need reading when the
            // schema actually carries them.
            $minContainsExpression = $hasMinContains
                ? "is_int(\$schema['minContains'] ?? null) ? \$schema['minContains'] : 1"
                : '1';
            $maxContainsCheck = $hasMaxContains
                ? <<<'PHP'
            $maxContains = is_int($schema['maxContains'] ?? null) ? $schema['maxContains'] : null;
            if ($maxContains !== null && $matchCount > $maxContains) {
                $errors[] = sprintf('%s must contain at most %d item(s) matching the \'contains\' schema', $path, $maxContains);
            }

PHP
                : '';
            $sections[] = <<<PHP
        if (\$listValue && is_array(\$schema['contains'] ?? null)) {
            \$matchCount = 0;
            foreach (\$value as {$containsIndexVariable} => \$itemValue) {
                if (\$this->matchesOpenApiNode(\$itemValue, \$schema['contains'], \$depth + 1)) {
                    \$matchCount++;
{$trackContainsIndex}                }
            }

            \$minContains = {$minContainsExpression};
{$maxContainsCheck}            if (\$matchCount < \$minContains) {
                \$errors[] = sprintf('%s must contain at least %d item(s) matching the \\'contains\\' schema', \$path, \$minContains);
            }
        }
PHP;
        }

        if ($hasAllOf) {
            // Every branch must pass, so there is no matching to do and no branch to skip on type:
            // each one is validated and its errors are all kept, exactly as the runtime validator
            // does it. Only a NESTED allOf gets here — see the filter — because a property-level one
            // is already covered by attributes or by the class it became.
            $sections[] = <<<'PHP'
        if (is_array($schema['allOf'] ?? null)) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }

                $errors = [...$errors, ...$this->validateOpenApiNode($value, $branch, $path, $depth + 1)];
            }
        }
PHP;
        }

        if ($hasAnyOf) {
            // Property-level anyOf becomes #[Assert\AtLeastOneOf]; only a nested one reaches the
            // constant, and then it is on the callback to check it — same shape as oneOf, except
            // one matching branch is enough.
            $anyOfTypeGate = $needsTypeMatcher
                ? <<<'PHP'
                if (array_key_exists('type', $branch) && !$this->matchesOpenApiCallbackType($normalizedValue, $branch['type'])) {
                    continue;
                }

PHP
                : '';
            $sections[] = <<<PHP
        if (is_array(\$schema['anyOf'] ?? null)) {
            \$anyOfMatched = false;
            \$anyOfErrors = [];
            foreach (\$schema['anyOf'] as \$branch) {
                if (!is_array(\$branch)) {
                    continue;
                }

{$anyOfTypeGate}                \$errorsForBranch = \$this->validateOpenApiNode(\$value, \$branch, \$path, \$depth + 1);
                if (\$errorsForBranch === []) {
                    \$anyOfMatched = true;
                    break;
                }

                \$anyOfErrors = [...\$anyOfErrors, ...\$errorsForBranch];
            }

            if (!\$anyOfMatched) {
                \$errors = \$anyOfErrors === []
                    ? [...\$errors, \$this->describeOpenApiUnionMismatch(\$path, 'anyOf', \$schema['anyOf'], \$value)]
                    : [...\$errors, ...array_values(array_unique(\$anyOfErrors))];
            }
        }
PHP;
        }

        if ($hasUnevaluatedItems) {
            // TWO spellings, and only the first was emitted until 2.15.14. `unevaluatedItems: false`
            // forbids an item the other keywords did not reach; `unevaluatedItems: {schema}` requires
            // such an item to MATCH that schema, which is the form a document uses to type the tail
            // after `prefixItems`. The runtime validator has always read both; here the schema form was
            // carried in the constants and read by nothing, so every item past the prefix was accepted
            // whatever it held — measured against runtime, which refused the same payload.
            $sections[] = sprintf(
                <<<'PHP'
        if ($listValue && array_key_exists('unevaluatedItems', $schema)) {
            $unevaluatedItemsSchema = $schema['unevaluatedItems'];
            foreach ($value as $index => $itemValue) {
                if (array_key_exists($index, $evaluatedItemIndices)) {
                    continue;
                }

                if ($unevaluatedItemsSchema === false) {
                    $errors[] = sprintf('%%s has an unevaluated item at index %%s which is not allowed', $path, $index);

                    continue;
                }

                if (!is_array($unevaluatedItemsSchema)) {
                    continue;
                }

%s                $errors = [
                    ...$errors,
                    ...$this->validateOpenApiNode($itemValue, $unevaluatedItemsSchema, $path . '[' . $index . ']', $depth + 1),
                ];
            }
        }
PHP,
                $skipNestedDto('$itemValue'),
            );
        }

        if ($hasAdditionalProperties || $hasUnevaluatedProperties) {
            $sections[] = <<<'PHP'
        if (is_array($structuredValue)) {
            $extraPropertySchema = $schema['additionalProperties'] ?? null;
            $unevaluatedProperties = $schema['unevaluatedProperties'] ?? null;
            $evaluatedProperties = [...$definedProperties, ...$patternMatchedProperties];
PHP;

            if ($hasAdditionalProperties) {
                $skipNestedDtoExtra = $skipNestedDto(
                    '$propertyValue',
                    '                    ',
                    "                        \$evaluatedProperties[\$propertyName] = true;\n",
                );
                $sections[] = <<<PHP
            if (\$extraPropertySchema === false || is_array(\$extraPropertySchema)) {
                foreach (\$structuredValue as \$propertyName => \$propertyValue) {
                    if (!is_string(\$propertyName) || array_key_exists(\$propertyName, \$evaluatedProperties)) {
                        continue;
                    }
                    if (\$extraPropertySchema === false) {
                        \$errors[] = sprintf('%s has additional property "%s" which is not allowed', \$path, \$propertyName);
                        continue;
                    }
{$skipNestedDtoExtra}                    \$errors = [
                        ...\$errors,
                        ...\$this->validateOpenApiNode(\$propertyValue, \$extraPropertySchema, \$this->openApiChildPath(\$path, \$propertyName), \$depth + 1),
                    ];
                    \$evaluatedProperties[\$propertyName] = true;
                }
            }
PHP;
            }

            if ($hasUnevaluatedProperties) {
                $sections[] = <<<'PHP'
            if ($unevaluatedProperties === false) {
                foreach ($structuredValue as $propertyName => $_propertyValue) {
                    if (!is_string($propertyName) || array_key_exists($propertyName, $evaluatedProperties)) {
                        continue;
                    }
                    if ($extraPropertySchema !== false) {
                        $errors[] = sprintf('%s has unevaluated property "%s" which is not allowed', $path, $propertyName);
                    }
                }
            }
PHP;
            }

            // Appended to the PREVIOUS section rather than pushed as its own: the sections are joined
            // with a blank line between them, and a section that is nothing but a closing brace put
            // that blank line INSIDE the block it closes — `Blank line found at end of control
            // structure`, in every mode that carries this interpreter. Measured with PSR-12 over the
            // emitted corpus, which is the only thing that reads this code's style.
            $sections[array_key_last($sections)] .= "\n        }";
        }

        $sections[] = <<<'PHP'
        return $errors;
    }
PHP;

        if ($needsMatcher) {
            $sections[] = <<<'PHP'

    /**
     * @param array<string, mixed> $schema
     */
    private function matchesOpenApiNode(mixed $value, array $schema, int $depth): bool
    {
        return $this->validateOpenApiNode($value, $schema, 'payload', $depth) === [];
    }
PHP;
        }

        if ($hasOneOf || $hasAnyOf) {
            $enumUnwrapInDescription = $canHoldEnum
                ? <<<'PHP'
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

PHP
                : '';
            $sections[] = <<<PHP

    /**
     * Every branch was gated out by its declared type, so no branch has a reason to report. Naming the
     * types the union does accept turns an unactionable sentence into one the caller can act on. Same
     * wording as the runtime validator, so a payload reads the same whichever mode answered it.
     *
     * @param array<int, mixed> \$branches
     */
    private function describeOpenApiUnionMismatch(string \$path, string \$kind, array \$branches, mixed \$value): string
    {
        \$types = [];
        foreach (\$branches as \$branch) {
            if (!is_array(\$branch)) {
                continue;
            }

            \$declared = is_array(\$branch['type'] ?? null) ? \$branch['type'] : [\$branch['type'] ?? null];
            foreach (\$declared as \$type) {
                if (is_string(\$type) && \$type !== '' && !in_array(\$type, \$types, true)) {
                    \$types[] = \$type;
                }
            }
        }

        if (\$types === []) {
            return sprintf('%s does not match any %s branch', \$path, \$kind);
        }

        \$last = array_pop(\$types);
        \$expected = \$types === [] ? \$last : implode(', ', \$types) . ' or ' . \$last;
{$enumUnwrapInDescription}
        return sprintf(
            '%s does not match any %s branch (expected %s, got %s)',
            \$path,
            \$kind,
            \$expected,
            match (true) {
                \$value === null => 'null',
                is_bool(\$value) => 'boolean',
                is_int(\$value) => 'integer',
                is_float(\$value) => 'number',
                is_string(\$value) => 'string',
                is_array(\$value) => array_is_list(\$value) ? 'array' : 'object',
                default => 'object',
            },
        );
    }
PHP;
        }

        if ($needsTypeMatcher) {
            $typeTests = [
                // A JSON `42.0` is an integer per JSON Schema 2020-12 §6.1.1 — a number with a zero
                // fractional part — and PHP decodes it to a float.
                'integer' => 'is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value)',
                'number' => 'is_int($value) || is_float($value)',
                'string' => 'is_string($value)',
                'boolean' => 'is_bool($value)',
                'array' => 'is_array($value) && array_is_list($value)',
                // A map is a PHP array by the time a constraint runs, and a dense-integer-key map is
                // indistinguishable from a list, so any array satisfies `object` here. Only the runtime
                // deserializer sees the raw JSON and can refuse an array for a `type: object` property.
                'object' => 'is_array($value) || is_object($value)',
                'null' => '$value === null',
            ];
            $typeArms = '';
            foreach ($typeTests as $typeName => $test) {
                if (in_array($typeName, $usedTypes, true)) {
                    $typeArms .= sprintf("            '%s' => %s,\n", $typeName, $test);
                }
            }
            $enumUnwrapInMatcher = $canHoldEnum
                ? <<<'PHP'
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

PHP
                : '';
            $listTypeRecursion = $hasUnionType
                ? <<<'PHP'
        if (is_array($type)) {
            if ($type === []) {
                return true;
            }
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $this->matchesOpenApiCallbackType($value, $candidate)) {
                    return true;
                }
            }

            return false;
        }

PHP
                : '';
            $sections[] = <<<PHP

    private function matchesOpenApiCallbackType(mixed \$value, mixed \$type): bool
    {
{$listTypeRecursion}        if (!is_string(\$type) || \$type === '') {
            return true;
        }

{$enumUnwrapInMatcher}        return match (\$type) {
{$typeArms}            default => true,
        };
    }
PHP;
        }

        if ($needsValueNormalization && ($canHoldEnum || $canHoldTemporal)) {
            $enumUnwrap = $canHoldEnum
                ? <<<'PHP'
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

PHP
                : '';
            $temporalUnwrap = $canHoldTemporal
                ? <<<'PHP'
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $format = $schema['format'] ?? null;
        if (!is_string($format)) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return match ($format) {
            'date' => $value->format('Y-m-d'),
            default => $value->format(DateTimeInterface::ATOM),
        };
PHP
                : '        return $value;';
            $schemaParameter = $canHoldTemporal ? 'array $schema' : 'array $_schema';
            $sections[] = <<<PHP

    private function normalizeOpenApiCallbackValue(mixed \$value, {$schemaParameter}): mixed
    {
{$enumUnwrap}{$temporalUnwrap}
    }
PHP;
        }

        if ($needsStructuralNormalization) {
            // The DTO branch belongs to the mode whose payload IS a DTO. Where the interpreter reads a
            // raw decoded payload, no value has a `toOpenApiValidationPayload()` to call — the method
            // is not even emitted there.
            $dtoPayloadView = $payloadIsHydratedObject
                ? <<<'PHP'

        // Generated DTOs expose their OpenAPI-named payload themselves.
        if (method_exists($value, 'toOpenApiValidationPayload')) {
            return $value->toOpenApiValidationPayload();
        }
PHP
                : '';
            $sections[] = <<<PHP

    private function normalizeOpenApiStructuralValue(mixed \$value): mixed
    {
        if (is_array(\$value) || !is_object(\$value)) {
            return \$value;
        }
{$dtoPayloadView}
        // Any other object: only its public state is visible, under PHP property names.
        return array_filter(
            get_object_vars(\$value),
            static fn(mixed \$propertyValue): bool => \$propertyValue !== null,
        );
    }
PHP;
        }

        if ($needsGeneratedDtoSkip) {
            $sections[] = <<<'PHP'

    private function isGeneratedOpenApiDtoObject(mixed $value): bool
    {
        // Such a DTO validates its own schema through its own #[Assert\Callback], reached by the
        // #[Assert\Valid] cascade — the enclosing DTO must not report the same errors again.
        return is_object($value)
            && method_exists($value, 'validateOpenApiConstraints')
            && method_exists($value, 'toOpenApiValidationPayload');
    }
PHP;
        }

        // Gated exactly the way the ENTRY that calls it is gated, and for a reason worth saying: an
        // earlier version asked for properties as well, and a schema carrying only object-level
        // keywords (`minProperties`, `dependentRequired`, a nested `required`) then emitted the call
        // without the method — `Call to undefined method`, on the eight cases that have exactly that
        // shape.
        //
        // Only the two modes whose interpreter is entered once per OBJECT need to read a property name
        // back out of a message: laravel enters it once per PROPERTY and passes the name to
        // `errors()->add()` at the call site, so there is nothing to recover there.
        if ($payloadIsHydratedObject) {
            $sections[] = <<<'PHP'

    /**
     * The property a message is about, as a value path, or null when it is about the payload itself.
     *
     * The interpreter bakes the subject INTO the sentence — `field "tags" must …`, and one level down
     * `field "tags".id must …` — because the wording has to match the runtime validator's word for
     * word. Reading it back here is what lets the violation carry the FIELD as well as the sentence:
     * an error response groups by property, and every keyword this interpreter owns was landing at
     * the root instead, where a per-field renderer never shows it.
     */
    private static function openApiViolationPath(string $error): ?string
    {
        if (!str_starts_with($error, 'field "')) {
            return null;
        }

        $closing = strpos($error, '"', 7);
        if ($closing === false) {
            return null;
        }

        $property = substr($error, 7, $closing - 7);
        if ($property === '') {
            return null;
        }

        // Anything after the closing quote up to the first space is the rest of the path, so
        // `field "tags".id must …` answers `tags.id` rather than just `tags`.
        $rest = substr($error, $closing + 1);
        $deeper = '';
        if (str_starts_with($rest, '.')) {
            $space = strpos($rest, ' ');
            $deeper = $space === false ? $rest : substr($rest, 0, $space);
        }

        return $property . $deeper;
    }
PHP;
        }

        if ($hasProperties || $hasPatternProperties || $hasAdditionalProperties || $hasRequired || $hasDependentRequired) {
            $sections[] = <<<'PHP'

    private function openApiChildPath(string $path, string $propertyName): string
    {
        // The first level names the field the way the runtime validator's caller does, so both
        // modes report the same subject: `field "tags" must ...`.
        return $path === 'payload'
            ? sprintf('field "%s"', $propertyName)
            : $path . '.' . $propertyName;
    }
PHP;
        }

        if (
            $needsStringValidation
            || $needsNumericValidation
            || $needsCollectionCountValidation
            || $hasContentEncoding
            || $hasContentMediaType
            || $hasContentSchema
        ) {
            $sections[] = <<<'PHP'

    /**
     * @param array<int, string> $keys
     */
    private function schemaHasAnyOpenApiKey(array $schema, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }
PHP;
        }

        // Read helpers follow their own keywords: lengths/counts need the int reader, the numeric
        // bounds need the float one.
        if ($hasMinLength || $hasMaxLength || $needsCollectionCountValidation) {
            $sections[] = <<<'PHP'

    private function toIntConstraint(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int)$value;
        }

        return null;
    }
PHP;
        }

        if ($needsNumericValidation) {
            $sections[] = <<<'PHP'

    private function toFloatConstraint(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float)$value : null;
    }
PHP;
        }

        if ($needsNumericValidation) {
            $sections[] = <<<'PHP'

    private function stringifyOpenApiNumber(float $value): string
    {
        $rendered = json_encode($value);
        return is_string($rendered) ? $rendered : (string)$value;
    }
PHP;
        }

        if ($usedFormats !== [] || $usedContentEncodings !== []) {
            $formatArms = self::OPENAPI_FORMAT_ARMS;

            $arms = '';
            $neededHelpers = [];
            foreach ($usedFormats as $usedFormat) {
                if (!array_key_exists($usedFormat, $formatArms)) {
                    continue;
                }
                [$expression, $helpers] = $formatArms[$usedFormat];
                $arms .= sprintf("            '%s' => %s,\n", $usedFormat, $expression);
                foreach ($helpers as $helper) {
                    $neededHelpers[$helper] = true;
                }
            }

            // Content decoding validates base64 payloads through the same helper.
            if (in_array('base64', $usedContentEncodings, true)) {
                $neededHelpers['base64'] = true;
            }

            if ($arms !== '') {
                $sections[] = <<<PHP

    private function isValidOpenApiStringFormat(string \$value, string \$format): bool
    {
        return match (\$format) {
{$arms}            default => true,
        };
    }
PHP;
            }

            foreach ($this->openApiFormatHelperSnippets() as $helper => $snippet) {
                if (array_key_exists($helper, $neededHelpers)) {
                    $sections[] = $snippet;
                }
            }
        }

        if ($needsNumericFormatValidation) {
            $numericBounds = [
                'int32' => '-2147483648, 2147483647',
                'int64' => 'PHP_INT_MIN, PHP_INT_MAX',
                'uint32' => '0, 4294967295',
                'uint64' => '0, 18446744073709551615.0',
            ];
            $numericFormatArms = '';
            foreach ($numericBounds as $numericFormat => $bounds) {
                if (in_array($numericFormat, $usedNumericFormats, true)) {
                    $numericFormatArms .= sprintf(
                        "            '%s' => \$this->validateOpenApiIntegerFormat(\$path, \$value, %s, '%s'),\n",
                        $numericFormat,
                        $bounds,
                        $numericFormat,
                    );
                }
            }
            $sections[] = <<<PHP

    /**
     * @return array<int, string>
     */
    private function validateOpenApiNumericFormat(string \$path, int|float \$value, string \$format): array
    {
        return match (\$format) {
{$numericFormatArms}            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function validateOpenApiIntegerFormat(string \$path, int|float \$value, int \$min, int|float \$max, string \$format): array
    {
        \$maxLabel = \$format === 'uint64' ? '18446744073709551615' : (string)\$max;

        if (is_float(\$value) && (is_nan(\$value) || is_infinite(\$value) || floor(\$value) !== \$value)) {
            return [sprintf('%s must be an integer (%s)', \$path, \$format)];
        }

        if (is_float(\$value)) {
            if (\$format === 'int64' && \$value >= 9223372036854775808.0) {
                return [sprintf('%s must be within %s range (%s to %s)', \$path, \$format, \$min, \$maxLabel)];
            }
            if (\$value < \$min || \$value > \$max) {
                return [sprintf('%s must be within %s range (%s to %s)', \$path, \$format, \$min, \$maxLabel)];
            }

            return [];
        }

        if (\$value < \$min) {
            return [sprintf('%s must be within %s range (%s to %s)', \$path, \$format, \$min, \$maxLabel)];
        }
        if (is_int(\$max) && \$value > \$max) {
            return [sprintf('%s must be within %s range (%s to %s)', \$path, \$format, \$min, \$maxLabel)];
        }

        return [];
    }
PHP;
        }

        if ($hasContentEncoding) {
            // Identity codecs (7bit/8bit/binary) and unknown ones share the permissive default, so
            // only the transforming codecs the constant mentions need an arm.
            $decodeArms = [
                'base64' => <<<'PHP'
            'base64' => $this->isValidOpenApiBase64($value)
                ? (base64_decode($value, true) === false ? null : base64_decode($value, true))
                : null,
PHP,
                'base16' => <<<'PHP'
            'base16' => $value === ''
                ? ''
                : ((strlen($value) % 2 !== 0 || !ctype_xdigit($value))
                    ? null
                    : (hex2bin($value) === false ? null : hex2bin($value))),
PHP,
                'quoted-printable' => "            'quoted-printable' => quoted_printable_decode(\$value),",
            ];
            $emittedArms = '';
            foreach ($decodeArms as $encoding => $arm) {
                if (in_array($encoding, $usedContentEncodings, true)) {
                    $emittedArms .= $arm . "\n";
                }
            }
            $sections[] = <<<PHP

    private function decodeOpenApiContent(string \$value, string \$encoding): ?string
    {
        return match (strtolower(\$encoding)) {
{$emittedArms}            default => \$value,
        };
    }
PHP;
        }

        if ($hasContentMediaType) {
            $sections[] = <<<'PHP'

    private function isOpenApiJsonMediaType(string $mediaType): bool
    {
        $normalized = strtolower(trim(explode(';', $mediaType)[0]));

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }
PHP;
        }

        // Sections are separated by exactly one blank line: some carry their own leading newline
        // (whole methods), so joining alone would double it up.
        $body = implode("\n\n", $sections);

        return preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
    }

    /**
     * `allOf` whose branches are plain constraint fragments (no $ref, no inline object, no nested
     * composition) is just a split-up constraint set — merge it so the scalar keywords reach the
     * Symfony attributes instead of being dropped as an unsupported composition keyword.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function foldScalarAllOfConstraints(array $constraints): array
    {
        $branches = $constraints['allOf'] ?? null;
        if (!is_array($branches) || $branches === []) {
            return $constraints;
        }

        $merged = [];
        foreach ($branches as $branch) {
            if (!is_array($branch) || !$this->canFlattenAllOfPropertyItem($branch)) {
                return $constraints;
            }
            $merged = array_replace_recursive($merged, $branch);
        }

        unset($constraints['allOf']);

        return array_replace_recursive($merged, $constraints);
    }

    /**
     * Which non-scalar PHP values this DTO's callback can actually meet: generated backed enums and
     * `DateTimeImmutable`. Nested generated DTOs are followed because a structural subschema is
     * checked against their payload, whose values may be enums or dates in turn.
     *
     * @param array<int, array{name: string, declaredType: string, docType: string|null}> $params
     * @return array{enum: bool, temporal: bool}
     */
    private function interpreterValueKinds(string $className, array $params): array
    {
        $types = [];
        foreach ($params as $param) {
            $types[] = $param['declaredType'];
            if ($param['docType'] !== null) {
                $types[] = $param['docType'];
            }
        }

        $kinds = ['enum' => false, 'temporal' => false];
        $visited = [$className => true];
        $queue = $types;

        while ($queue !== []) {
            $type = array_pop($queue);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_\\\]*/', $type, $matches);
            foreach ($matches[0] as $referenced) {
                $short = $this->shortClassName($referenced);
                if ($short === 'DateTimeImmutable') {
                    $kinds['temporal'] = true;
                    continue;
                }
                if (array_key_exists($short, $this->enumSchemas)) {
                    $kinds['enum'] = true;
                    continue;
                }
                if (!array_key_exists($short, $this->dtoSchemas) || array_key_exists($short, $visited)) {
                    continue;
                }
                $visited[$short] = true;
                foreach ($this->getSchemaProperties($short) as $nestedProperty) {
                    $queue[] = $nestedProperty['type'];
                }
            }
            if ($kinds['enum'] && $kinds['temporal']) {
                break;
            }
        }

        return $kinds;
    }

    /**
     * Emittable format-check helpers, keyed by the token the format arms request. Only the ones a
     * DTO's own formats need end up in its generated code.
     *
     * @return array<string, string>
     */
    private function openApiFormatHelperSnippets(): array
    {
        return [
            'date' => <<<'PHP'

    private function isValidOpenApiDateFormat(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
PHP,
            'date-time' => <<<'PHP'

    private function isValidOpenApiDateTimeFormat(string $value): bool
    {
        // The four shapes every mode agrees on, and a WARNING check per shape. `createFromFormat()`
        // alone is not a validator: it overflows silently, so `2026-13-45T99:99:99+00:00` parsed
        // clean and a payload the schema forbids was accepted. Only ATOM was tried before, which
        // also meant a sub-second value passed on leftover input rather than on being valid.
        foreach (['Y-m-d\TH:i:sp', 'Y-m-d\TH:i:s.up', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
            if (DateTimeImmutable::createFromFormat($format, $value) === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false) {
                return true;
            }
            if ((int)($errors['warning_count'] ?? 0) === 0 && (int)($errors['error_count'] ?? 0) === 0) {
                return true;
            }
        }

        return false;
    }
PHP,
            'time' => <<<'PHP'

    private function isValidOpenApiTimeFormat(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/', $value) === 1;
    }
PHP,
            'uuid' => <<<'PHP'

    private function isValidOpenApiUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) === 1;
    }
PHP,
            'uri-reference' => <<<'PHP'

    private function isValidOpenApiUriReference(string $value): bool
    {
        return preg_match('/[\s\x00-\x1F\x7F]/u', $value) !== 1;
    }
PHP,
            'uri-template' => <<<'PHP'

    private function isValidOpenApiUriTemplate(string $value): bool
    {
        // RFC 6570: a URI-reference that may embed {expression} blocks. Every brace has to belong to a
        // well-formed expression — an optional operator followed by a comma-separated list of varspecs,
        // each a varname with an optional ":" prefix-length or "*" explode modifier. Strip the valid
        // expressions; a surviving brace means the template is malformed.
        if (!$this->isValidOpenApiUriReference($value)) {
            return false;
        }

        if (!str_contains($value, '{')) {
            return !str_contains($value, '}');
        }

        $expression = '\{[+#./;?&=,!@|]?(?:[\p{L}\p{N}_%]+(?::[1-9][0-9]{0,3}|\*)?)'
            . '(?:,[\p{L}\p{N}_%]+(?::[1-9][0-9]{0,3}|\*)?)*\}';
        $stripped = preg_replace('~' . $expression . '~u', '', $value);

        return $stripped !== null && !str_contains($stripped, '{') && !str_contains($stripped, '}');
    }
PHP,
            'iri' => <<<'PHP'

    private function isValidOpenApiIri(string $value): bool
    {
        // RFC 3987 ABSOLUTE IRI: a scheme is required, and a scheme alone ("a:") is not usable.
        // `FILTER_VALIDATE_URL` cannot stand in — it rejects non-ASCII.
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return false;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:.+/', $value) === 1;
    }
PHP,
            'duration' => <<<'PHP'

    private function isValidOpenApiDuration(string $value): bool
    {
        return preg_match('/^P(?:\d+W|(?=\d|T)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?)$/', $value) === 1;
    }
PHP,
            'json-pointer' => <<<'PHP'

    private function isValidOpenApiJsonPointer(string $value): bool
    {
        return preg_match('#^(/(?:[^/~]|~[01])*)*$#u', $value) === 1;
    }
PHP,
            'relative-json-pointer' => <<<'PHP'

    private function isValidOpenApiRelativeJsonPointer(string $value): bool
    {
        return preg_match('!^(0|[1-9][0-9]*)(?:#|(?:/(?:[^/~]|~[01])*)*)$!u', $value) === 1;
    }
PHP,
            'regex' => <<<'PHP'

    private function isValidOpenApiRegexFormat(string $value): bool
    {
        $regex = '#' . str_replace('#', '\#', $value) . '#';
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
PHP,
            'idn-hostname' => <<<'PHP'

    private function isValidOpenApiIdnHostname(string $value): bool
    {
        $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        return is_string($ascii) && filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
PHP,
            'base64' => <<<'PHP'

    private function isValidOpenApiBase64(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $trimmed = preg_replace('/\s+/', '', $value);
        if (!is_string($trimmed) || $trimmed === '' || strlen($trimmed) % 4 !== 0) {
            return false;
        }
        if (preg_match('/[^A-Za-z0-9+\/=]/', $trimmed) === 1) {
            return false;
        }
        $decoded = base64_decode($trimmed, true);
        if ($decoded === false) {
            return false;
        }

        return rtrim(base64_encode($decoded), '=') === rtrim($trimmed, '=');
    }
PHP,
        ];
    }

    /**
     * Every distinct string value a keyword takes anywhere in the constraint tree (list values are
     * flattened, so `type: [string, null]` contributes both). Used to emit only the branches the
     * baked-in constant can actually reach.
     *
     * @param array<string, mixed> $constraints
     * @return array<int, string>
     */
    private function collectSchemaKeywordStrings(array $constraints, string $keyword): array
    {
        $values = [];

        foreach ($constraints as $key => $value) {
            if ($key === $keyword) {
                foreach (is_array($value) ? $value : [$value] as $candidate) {
                    if (is_string($candidate) && $candidate !== '') {
                        $values[$candidate] = true;
                    }
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($this->collectSchemaKeywordStrings($value, $keyword) as $nested) {
                    $values[$nested] = true;
                }
            }
        }

        return array_keys($values);
    }

    /**
     * Whether the keyword ever holds a list — `type: [string, null]` needs the multi-type branch,
     * a plain `type: string` does not.
     *
     * @param array<string, mixed> $constraints
     */
    private function schemaKeywordHasListValue(array $constraints, string $keyword): bool
    {
        foreach ($constraints as $key => $value) {
            if ($key === $keyword && is_array($value)) {
                return true;
            }
            if ($key !== $keyword && is_array($value) && $this->schemaKeywordHasListValue($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys whose value may be a boolean subschema the emitted interpreter has no branch for.
     *
     * `items`, `additionalProperties`, `unevaluatedItems` and `unevaluatedProperties` are deliberately
     * absent: a boolean is their ORDINARY spelling and the interpreter reads it directly. They are
     * recursed into all the same, because a position further down may still hold one.
     */
    private const array INTERPRETER_BOOLEAN_KEYS = [
        'contains',
        'not',
        'propertyNames',
        'if',
        'then',
        'else',
        'contentSchema',
    ];

    /** Recursed into, but their own boolean value is left alone — see above. */
    private const array INTERPRETER_RECURSE_ONLY_KEYS = [
        'items',
        'additionalProperties',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /** Keys holding a MAP of subschemas, any of which may be boolean. */
    private const array INTERPRETER_BOOLEAN_MAP_KEYS = [
        'properties',
        'patternProperties',
        'dependentSchemas',
    ];

    /** Keys holding a LIST of subschemas, any of which may be boolean. */
    private const array INTERPRETER_BOOLEAN_LIST_KEYS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /**
     * Rewrites a boolean subschema into the object schema it is shorthand for, for the two modes that
     * read their schema through the interpreter emitted INTO the generated class.
     *
     * The interpreter gates every one of those keys on `is_array()`, so a boolean reaching it did
     * nothing — and until 2.15.38 the constraint filter dropped one before it could even get there.
     * Fixing only the filter changed no verdict at all: the value arrived and the branch still would
     * not look at it. That is the second layer, and it is why this is written here rather than as
     * seven more `elseif (is_bool(...))` arms inside the emitted code — one rewrite at the single
     * point both the emitted CONSTANT and the section flags are derived from.
     *
     * `true` is the empty schema. `false` has no direct spelling as an array, and `not` of the empty
     * schema is exactly it: the inner schema matches the value, so `not` rejects it, whatever it is.
     * The same identity `DtoValidator::expandBooleanSubschemas()` uses.
     *
     * Recursion is by KEY, never over every array: `enum: [true, false]` is a list of VALUES, and a
     * walker that could not tell the difference would rewrite a document's enum members into schemas.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function expandBooleanSubschemasForInterpreter(array $constraints): array
    {
        foreach (self::INTERPRETER_BOOLEAN_KEYS as $key) {
            if (!array_key_exists($key, $constraints)) {
                continue;
            }
            $value = $constraints[$key];
            if (is_bool($value)) {
                $constraints[$key] = $value ? [] : ['not' => []];
            } elseif (is_array($value)) {
                $constraints[$key] = $this->expandBooleanSubschemasForInterpreter($value);
            }
        }

        foreach (self::INTERPRETER_RECURSE_ONLY_KEYS as $key) {
            if (is_array($constraints[$key] ?? null)) {
                $constraints[$key] = $this->expandBooleanSubschemasForInterpreter($constraints[$key]);
            }
        }

        foreach ([...self::INTERPRETER_BOOLEAN_MAP_KEYS, ...self::INTERPRETER_BOOLEAN_LIST_KEYS] as $key) {
            if (!is_array($constraints[$key] ?? null)) {
                continue;
            }
            foreach ($constraints[$key] as $name => $subSchema) {
                if (is_bool($subSchema)) {
                    $constraints[$key][$name] = $subSchema ? [] : ['not' => []];
                } elseif (is_array($subSchema)) {
                    $constraints[$key][$name] = $this->expandBooleanSubschemasForInterpreter($subSchema);
                }
            }
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function schemaUsesKeyword(array $constraints, string $keyword): bool
    {
        foreach ($constraints as $key => $value) {
            if ($key === $keyword) {
                return true;
            }
            if (is_array($value) && $this->schemaUsesKeyword($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     */
    private function renderPhpArrayLiteral(array $value, int $indentLevel): string
    {
        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $indentLevel);
        $nestedIndent = str_repeat('    ', $indentLevel + 1);
        $isList = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $renderedItem = is_array($item)
                ? $this->renderPhpArrayLiteral($item, $indentLevel + 1)
                : var_export($item, true);

            if ($isList) {
                $lines[] = $nestedIndent . $renderedItem . ',';
                continue;
            }

            $renderedKey = is_int($key) ? (string)$key : var_export($key, true);
            $lines[] = $nestedIndent . $renderedKey . ' => ' . $renderedItem . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $indent . ']';
    }

    /**
     * Keeps only the Symfony-mode keywords that need the callback path. Supported scalar / count /
     * regex constraints are intentionally removed here so the callback does not duplicate
     * attribute-based violations.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, true> $forceScalarOnProperties
     * @param array<string, array<int, string>> $itemKeywordsCoveredByProperty per OpenAPI property
     *        name, the keywords its `#[Assert\All]` already enforces on the items — dropped from the
     *        FIRST container hop so one mistake produces one message
     * @param array<int, string> $coveredItemKeywords the entry above, once the recursion is inside
     *        that property; empty at every other level
     * @return array<string, mixed>
     */
    private function filterInterpreterConstraints(
        array $constraints,
        bool $allowScalarKeywords = false,
        array $forceScalarOnProperties = [],
        bool $isPropertySchema = false,
        array $itemKeywordsCoveredByProperty = [],
        array $coveredItemKeywords = [],
        bool $belowContainer = false,
    ): array {
        $constraints = $this->foldScalarAllOfConstraints($constraints);
        $filtered = [];

        foreach ($constraints as $key => $value) {
            switch ($key) {
                case 'properties':
                    if (!is_array($value)) {
                        break;
                    }
                    $filtered[$key] = [];
                    foreach ($value as $name => $schema) {
                        // A BOOLEAN entry is that property's entire schema: `false` admits no value,
                        // `true` constrains nothing. Only the array arm existed, so `false` was
                        // dropped and the property went unchecked.
                        if (is_bool($schema) && is_string($name)) {
                            $filtered[$key][$name] = $schema;

                            continue;
                        }
                        if (is_string($name) && is_array($schema)) {
                            $filtered[$key][$name] = $this->filterInterpreterConstraints(
                                constraints: $schema,
                                allowScalarKeywords: $allowScalarKeywords || array_key_exists($name, $forceScalarOnProperties),
                                isPropertySchema: true,
                                coveredItemKeywords: $itemKeywordsCoveredByProperty[$name] ?? [],
                                belowContainer: $belowContainer,
                            );
                        }
                    }
                    break;
                case 'prefixItems':
                    if (!is_array($value)) {
                        break;
                    }
                    $filtered[$key] = [];
                    foreach ($value as $schema) {
                        // `[]` is the EMPTY schema — the one that accepts everything — so collapsing a
                        // `false` member into it opened the very position the document closed. `true`
                        // really is the empty schema, which is why only `false` needs saying.
                        if (is_bool($schema)) {
                            $filtered[$key][] = $schema === false ? false : [];

                            continue;
                        }
                        $filtered[$key][] = is_array($schema)
                            ? $this->filterInterpreterConstraints(
                                $schema,
                                allowScalarKeywords: true,
                                belowContainer: true,
                            )
                            : [];
                    }
                    break;
                case 'items':
                    if (is_array($value)) {
                        // `allowScalarKeywords: true` stays: `Assert\All` does not nest, so from the
                        // SECOND container down there is no attribute and the callback owns everything.
                        // What the attribute does cover at THIS hop is subtracted instead.
                        $nested = $this->filterInterpreterConstraints(
                            $value,
                            allowScalarKeywords: true,
                            belowContainer: true,
                        );
                        $nested = $this->withoutKeywords($nested, $coveredItemKeywords);
                        if ($nested !== []) {
                            $filtered[$key] = $nested;
                        }
                    } elseif (is_bool($value)) {
                        // A BOOLEAN subschema, which is what closes a tuple: `prefixItems` names the
                        // members and `items: false` says there are no others. Only the array arm
                        // existed, so `false` fell through and was dropped — the emitted constraints
                        // carried `prefixItems` and nothing else, and both modes that read this filter
                        // accepted a tuple with extra elements while runtime mode rejected it. The
                        // three keys below have had this arm all along, for the same reason.
                        $filtered[$key] = $value;
                    }
                    break;
                case 'additionalProperties':
                case 'unevaluatedProperties':
                case 'unevaluatedItems':
                    if (is_array($value)) {
                        $nested = $this->filterInterpreterConstraints(
                            $value,
                            allowScalarKeywords: true,
                            belowContainer: true,
                        );
                        if ($key === 'additionalProperties') {
                            $nested = $this->withoutKeywords($nested, $coveredItemKeywords);
                        }
                        if ($nested !== []) {
                            $filtered[$key] = $nested;
                        }
                    } elseif (is_bool($value)) {
                        $filtered[$key] = $value;
                    }
                    break;
                case 'not':
                case 'if':
                case 'then':
                case 'else':
                case 'contains':
                case 'propertyNames':
                case 'contentSchema':
                    if (is_array($value)) {
                        // `belowContainer: true`, and for the same reason the container keys pass it:
                        // nothing materializes under any of these, so an `allOf` here has no class to
                        // check it and must reach the interpreter. Without it the flag was lost at the
                        // first hop and Symfony emitted `{}` for a branch carrying `required` and bounds
                        // — measured against runtime, which kept them, on all four keys.
                        $nested = $this->filterInterpreterConstraints(
                            $value,
                            allowScalarKeywords: true,
                            belowContainer: true,
                        );
                        if ($nested !== []) {
                            $filtered[$key] = $nested;
                        }
                    } elseif (is_bool($value)) {
                        // The seven keys above are the direct siblings of `items`, which got this arm
                        // in 2.15.36 while they were left behind. A boolean is a legal subschema in
                        // every one of them — `not: true` forbids every value, `then: false` makes a
                        // matched condition fatal — and dropping it left the keyword doing nothing.
                        $filtered[$key] = $value;
                    }
                    break;
                case 'oneOf':
                case 'anyOf':
                case 'allOf':
                    // `anyOf` and `allOf` are kept only NESTED (`$allowScalarKeywords` is what every
                    // recursive call sets). On a property both are already covered without the
                    // callback: `anyOf` becomes `#[Assert\AtLeastOneOf]`, and `allOf` is either folded
                    // into scalar attributes by `foldScalarAllOfConstraints()` or becomes a class the
                    // `#[Assert\Valid]` cascade walks. Letting them through there would report every
                    // violation twice. Below a container neither is true — no attribute nests and no
                    // class is written — and until 2.15.11 `allOf` was dropped here with nothing to
                    // replace it: `items: {allOf: [$ref M]}` emitted no callback at all and every
                    // value under it was accepted.
                    if ($key === 'anyOf' && !$allowScalarKeywords) {
                        break;
                    }
                    if ($key === 'allOf' && !$belowContainer) {
                        break;
                    }
                    if (!is_array($value)) {
                        break;
                    }
                    $branches = [];
                    foreach ($value as $branch) {
                        // A boolean branch is not noise. `oneOf: [true, {type: string}]` refuses every
                        // string — `true` matches it as well, so TWO branches match and `oneOf` wants
                        // exactly one — and dropping the `true` reversed that verdict. In `allOf` a
                        // `false` branch makes the whole keyword unsatisfiable.
                        if (is_bool($branch)) {
                            $branches[] = $branch;

                            continue;
                        }
                        if (!is_array($branch)) {
                            continue;
                        }
                        $nested = $this->filterInterpreterConstraints(
                            $branch,
                            allowScalarKeywords: true,
                            belowContainer: $belowContainer,
                        );
                        if ($nested !== []) {
                            $branches[] = $nested;
                        }
                    }
                    if ($branches !== []) {
                        $filtered[$key] = $branches;
                    }
                    break;
                case 'patternProperties':
                case 'dependentSchemas':
                    if (!is_array($value)) {
                        break;
                    }
                    $filtered[$key] = [];
                    foreach ($value as $name => $schema) {
                        // `dependentSchemas: {a: false}` says an object carrying `a` is invalid — the
                        // boolean IS the dependent schema, and skipping it said nothing at all.
                        if (is_bool($schema) && is_string($name)) {
                            $filtered[$key][$name] = $schema;

                            continue;
                        }
                        if (is_string($name) && is_array($schema)) {
                            $nested = $this->filterInterpreterConstraints($schema, allowScalarKeywords: true);
                            if ($nested !== []) {
                                $filtered[$key][$name] = $nested;
                            }
                        }
                    }
                    if ($filtered[$key] === []) {
                        unset($filtered[$key]);
                    }
                    break;
                case 'dependentRequired':
                    $filtered[$key] = $value;
                    break;
                case 'required':
                    // At the CLASS level the constructor enforces it: a required property is a
                    // parameter without a default, so repeating it in the callback would only
                    // double-report. Inside a property's schema nothing does when that schema stayed a
                    // map — an object constraining only its keys never becomes a DTO. Where it did
                    // become one, the emitted `isGeneratedOpenApiDtoObject()` guard skips the value
                    // here and lets the nested class report for itself.
                    if (is_array($value) && ($allowScalarKeywords || $isPropertySchema)) {
                        $filtered[$key] = $value;
                    }
                    break;
                case 'type':
                case 'const':
                case 'enum':
                case 'minLength':
                case 'maxLength':
                case 'pattern':
                case 'format':
                case 'contentEncoding':
                case 'contentMediaType':
                case 'minimum':
                case 'maximum':
                case 'exclusiveMinimum':
                case 'exclusiveMaximum':
                case 'multipleOf':
                case 'minItems':
                case 'maxItems':
                case 'uniqueItems':
                    if ($allowScalarKeywords) {
                        $filtered[$key] = $value;
                    }
                    break;
                case 'minProperties':
                case 'maxProperties':
                    // On a PROPERTY these two become `#[Assert\Count]`, so the callback must not repeat
                    // them. On the CLASS ITSELF there is no attribute that counts an object's own keys —
                    // `Assert\Count` measures an array-typed value, not the DTO — so dropping them left
                    // the keyword enforced by nothing. Measured 2026-08-31: Symfony was the one mode
                    // accepting an object with too few properties once the other four refused it.
                    if ($allowScalarKeywords || (!$isPropertySchema && !$belowContainer)) {
                        $filtered[$key] = $value;
                    }
                    break;
                case 'nullable':
                    // Not an assertion — permission. Dropping it did not relax anything, it made the
                    // callback REJECT a null the document allows: `items: {nullable: true}` and its
                    // map twin both came back "must be of type string". It belongs with the other
                    // annotations, which survive whatever `allowScalarKeywords` says.
                    $filtered[$key] = $value;
                    break;
                case 'x-php-instanceof':
                case 'x-discriminator-property':
                case 'x-discriminator-php-property':
                case 'x-discriminator-map':
                    $filtered[$key] = $value;
                    break;
            }
        }

        // minContains / maxContains are modifiers of `contains` — the runtime callback reads them
        // but they carry no meaning on their own, so they only survive next to a kept `contains`.
        if (array_key_exists('contains', $filtered)) {
            foreach (['minContains', 'maxContains'] as $containsModifier) {
                if (is_int($constraints[$containsModifier] ?? null)) {
                    $filtered[$containsModifier] = $constraints[$containsModifier];
                }
            }
        }

        if (is_array($filtered['properties'] ?? null)) {
            $hasAdditionalOrUnevaluated = array_key_exists('additionalProperties', $filtered)
                || array_key_exists('unevaluatedProperties', $filtered);
            if (!$hasAdditionalOrUnevaluated) {
                $hasMeaningfulPropertyConstraints = false;
                foreach ($filtered['properties'] as $schema) {
                    if ($schema !== []) {
                        $hasMeaningfulPropertyConstraints = true;
                        break;
                    }
                }
                if (!$hasMeaningfulPropertyConstraints) {
                    unset($filtered['properties']);
                }
            }
        }

        // `nullable` rides along, it never keeps a subschema alive on its own: it grants permission,
        // it asserts nothing, and a schema holding only permissions has nothing for the callback to
        // do. Without this the callback was emitted for classes that needed none — measured, the
        // symfony corpus grew five hundred lines of interpreter that would never report anything.
        if ($filtered !== [] && array_diff(array_keys($filtered), self::SYMFONY_ANNOTATION_KEYWORDS) === []) {
            return [];
        }

        return $filtered;
    }

    /**
     * A subschema without the named keywords. Empty list, or nothing to drop: the same array back.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $keywords
     * @return array<string, mixed>
     */
    private function withoutKeywords(array $schema, array $keywords): array
    {
        if ($keywords === []) {
            return $schema;
        }

        foreach ($keywords as $keyword) {
            unset($schema[$keyword]);
        }

        return $schema;
    }
}
