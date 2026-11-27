<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use RuntimeException;

/**
 * Laravel-mode emitter: a plain DTO that carries a `rules()` array for `illuminate/validation`.
 *
 * The target is deliberately FIRST-PARTY. `FormRequest` and the validator ship with the framework, so
 * the generated code needs nothing installed — no `spatie/laravel-data`, and none of this package
 * either. An application wires it up in one line:
 *
 *     public function rules(): array { return UserPostRequestData::rules(); }   // in its FormRequest
 *     $dto = UserPostRequestData::fromValidated($request->validated());
 *
 * What Laravel's rule vocabulary CANNOT express (composition, conditionals, `contains`,
 * `unevaluated*`, `content*`, `propertyNames`, `discriminator`) is not silently dropped: those
 * keywords are collected per document and reported through `getGenerationWarnings()` until the
 * interpreter emitter is shared with the Symfony renderer — see `.todo.codegeneration_laravel`, M2.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 */
trait RendersLaravelDto
{
    /**
     * Whether a rendered class carries the interpreter, so its FormRequest knows whether there is a
     * `withValidator()` to forward. Filled while the DTO is rendered, read when its FormRequest is.
     *
     * @var array<string, bool>
     */
    private array $laravelClassesWithInterpreter = [];

    /**
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $unionTypes
     * @param array<string, mixed>|null $discriminator
     */
    private function renderLaravelDtoClass(
        string $namespace,
        string $className,
        array $properties,
        array $unionTypes,
        ?array $discriminator,
        bool $isAbstract = false,
    ): string {
        // A union schema is an interface in Symfony mode for the serializer's sake; here nothing
        // resolves it automatically, so the same shape is used and the members implement it. A
        // DISCRIMINATED union arrives as an abstract base with no unionTypes — it is the same interface,
        // and the generated hydration switches on the discriminator to pick a member.
        if ($unionTypes !== [] || ($isAbstract && is_array($discriminator))) {
            return $this->renderLaravelUnionInterface($namespace, $className, $unionTypes, $discriminator);
        }
        // The allOf pattern: the base is the interface its variants implement, with the getters of its
        // own properties, which every variant inherits.
        if ($this->laravelIsAllOfDiscriminatorBase($className)) {
            return $this->renderLaravelUnionInterface(
                namespace: $namespace,
                className: $className,
                unionTypes: [],
                discriminator: $this->discriminatorSchemas[$className],
                sharedProperties: $properties,
            );
        }

        $useStatements = [];
        $params = [];
        $rules = [];
        $hydrators = [];
        $ignoredKeys = [];

        $objectShapePaths = [];
        $scalarTypePaths = [];
        $freeFormPaths = [];
        $nestedChildren = [];
        // A parameters DTO is handed the request BODY as `$rawJson` by its FormRequest; a body field that
        // happens to share a parameter's name says nothing about that parameter.
        $readsRawBody = !array_key_exists($className, $this->parameterPayloadClasses);

        foreach ($properties as $property) {
            $param = $this->resolveLaravelParam($property, $namespace);
            $params[] = $param;
            $rules[] = [
                'path' => $this->laravelPathSegment($property['openApiName']),
                'rules' => $this->laravelRulesLiteral($this->laravelRulesForProperty($property, true)),
            ];
            foreach ($this->laravelItemRulesForProperty($property) as $path => $itemRules) {
                $rules[] = ['path' => $path, 'rules' => $this->laravelRulesLiteral($itemRules)];
            }
            $child = $this->laravelComposedChild($property);
            if ($child !== null) {
                $nestedChildren[] = $child;
            } else {
                foreach ($this->laravelNestedRules($property, [$className]) as $path => $nestedRules) {
                    $rules[] = ['path' => $path, 'rules' => $this->laravelRulesLiteral($nestedRules)];
                }
            }
            foreach ($this->laravelObjectShapePaths($property, '', [$className], recurse: $child === null) as $objectPath) {
                $objectShapePaths[] = $objectPath;
            }
            if ($readsRawBody) {
                foreach ($this->laravelScalarTypePaths($property, '', [$className], recurse: $child === null) as $scalarPath => $scalarType) {
                    $scalarTypePaths[$scalarPath] = $scalarType;
                }
            }
            if ($readsRawBody) {
                foreach ($this->laravelFreeFormPaths(property: $property, prefix: '', visitedClasses: [$className], depth: 0) as $freeFormPath) {
                    $freeFormPaths[] = $freeFormPath;
                }
            }
            $hydrators[] = $this->laravelHydratorFor($property, $param);
            if (($property['readOnly'] ?? null) === true) {
                $ignoredKeys[] = $property['openApiName'];
            }

            foreach ($this->laravelImportsForProperty($property, $namespace, $className) as $import) {
                $useStatements[] = $import;
            }
        }

        // Everything the rule vocabulary cannot express is enforced by the emitted interpreter, entered
        // from `withValidator()`. The rules stay the native, translatable first line; the interpreter is
        // the authority on the rest of the schema.
        $objectShapePaths = array_values(array_unique($objectShapePaths));
        $nestedVars = $this->laravelNestedTemplateVars(
            className: $className,
            nestedChildren: $nestedChildren,
            objectPaths: $objectShapePaths,
            scalarPaths: $scalarTypePaths,
            checksScalarPaths: $readsRawBody,
        );
        $checksPaths = $nestedVars['checksObjectPaths'] || $nestedVars['checksScalarPaths'];

        $interpreterConstraints = $this->laravelInterpreterConstraints($properties, $className);
        $recursiveSchemas = array_filter($this->laravelRecursiveFolds, static fn(mixed $fold): bool => $fold !== []);
        $readsEmptyContainers = $readsRawBody && $this->laravelReadsEmptyContainers(
            className: $className,
            interpreterConstraints: $interpreterConstraints,
            recursiveSchemas: $recursiveSchemas,
        );
        $interpreter = $this->renderLaravelInterpreterBlock(
            constraints: $interpreterConstraints,
            // A class whose fold carries no assertion needs no entry: the marker pointing at it resolves
            // to nothing, which is what "nothing left to check here" means.
            recursiveSchemas: $recursiveSchemas,
            readsRawBody: $readsEmptyContainers,
        );
        $hasWithValidator = $interpreter['methods'] !== '' || $checksPaths;
        $this->laravelClassesWithInterpreter[$className] = $hasWithValidator;
        // `withValidator()` is emitted for every class now (it keeps `validated()` containers whole). The
        // template names the validator, the FormRequest base and PHP's own classes in full, so a schema
        // called `Validator` or `JsonSerializable` gets them under an alias instead of a fatal.
        $this->importLibraryClass(fqcn: 'Illuminate\Validation\Validator', namespace: $namespace, useStatements: $useStatements);
        // The template spells `\stdClass` out; the import shortens it unless the document owns a
        // `StdClass` here, where the import would be a fatal and the full name is the only safe one.
        $freeFormPaths = array_values(array_unique($freeFormPaths));
        // Below a level where the schema holds itself the paths come from the body, as the shape
        // checks' do — through `rawNodesAt()`, which those checks bring.
        $recursiveFreeForm = $readsRawBody
            && $nestedVars['reachesRecursion']
            && $checksPaths
            && array_filter(
                array: array_keys($this->laravelReachableClasses($className)),
                callback: fn(string $reached): bool => $this->laravelNestedValidationPlan($reached)['freeFormPaths'] !== [],
            ) !== [];
        if (($checksPaths || $readsEmptyContainers || $freeFormPaths !== [] || $recursiveFreeForm) && !$this->namespaceDeclaresClass('stdClass', $namespace)) {
            $useStatements[] = 'stdClass';
        }

        // A member of a discriminated union has to implement its interface, or the union-typed property
        // it is hydrated into rejects it.
        $implementedInterfaces = [];
        foreach ($this->laravelImplementedUnionInterfaces($className) as $unionInterface) {
            $this->appendImportForClass($useStatements, $unionInterface, $namespace, $className);
            $implementedInterfaces[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }
        $ownDiscriminator = $this->laravelVariantOwnDiscriminator($className);
        $ownVariants = [];
        foreach ($ownDiscriminator['variants'] ?? [] as $value => $variant) {
            $this->appendImportForClass($useStatements, $variant, $namespace, $className);
            $ownVariants[] = ['value' => $value, 'className' => $this->shortClassName($variant)];
        }
        // `json_encode()` and `response()->json()` write the DTO as `toArray()` does. PHP's own interface,
        // nothing of a framework's.
        $implementedInterfaces[] = $this->libraryClassRef('JsonSerializable', $namespace, $useStatements);

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'parameterReads' => $this->laravelParameterReads($properties),
            'rulesKeyType' => $this->laravelRulesKeyType(array_column($rules, 'path')),
            'rootIsObject' => $readsRawBody && array_key_exists($className, $this->requestPayloadClasses),
            'implementedInterfaces' => $implementedInterfaces,
            'ownDiscriminatorProperty' => $ownDiscriminator['property'] ?? null,
            'ownDiscriminatorVariants' => $ownVariants,
            'ownDiscriminatorUnions' => array_map($this->shortClassName(...), $ownDiscriminator['unions'] ?? []),
            'interpreterConstsBlock' => $interpreter['consts'],
            // Emit the object-level pass only where the document actually states something about the
            // object: otherwise every class with an interpreter would carry three inert lines.
            'hasObjectConstraints' => $interpreterConstraints['object'] !== [],
            'interpreterMethodsBlock' => $interpreter['methods'],
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => $params,
            'toArrayParams' => $this->inSchemaOrder($className, $params),
            'rules' => $rules,
            'hydrators' => $hydrators,
            'ignoredKeys' => $ignoredKeys,
            'needsJsonObjectHelper' => $this->laravelNeedsJsonObjectHelper($properties),
            'needsJsonValueHelper' => $this->laravelNeedsJsonValueHelper($properties),
            'needsIntCoercionHelper' => $this->laravelNeedsIntCoercionHelper($params),
            'scalarCoercionHelpers' => $this->laravelScalarCoercionHelpers($params),
            'objectShapePaths' => $objectShapePaths,
            'scalarTypePaths' => $scalarTypePaths,
            'readsEmptyContainers' => $readsEmptyContainers,
            'readsRawJsonShapes' => $interpreter['readsRawJsonShapes'],
            'freeFormPaths' => $freeFormPaths,
            'recursiveFreeForm' => $recursiveFreeForm,
            'collectsFreeFormPaths' => $readsRawBody,
            'laravelFormRequest' => $this->laravelFormRequest,
            'rawPathsEscapeDots' => $nestedVars['nestedPathsEscapeDots'] || array_filter(
                array: [...$objectShapePaths, ...array_keys($scalarTypePaths)],
                callback: static fn(string $rawPath): bool => str_contains($rawPath, '\.'),
            ) !== [],
            ...$nestedVars,
            'unionMembers' => null,
            'interfaceExtends' => [],
        ]);
    }

    /**
     * The discriminator values a union interface publishes in `VARIANTS`: value => variant class.
     *
     * @param array<string, mixed>|null $discriminator
     * @return array<string, string>
     */
    private function laravelPublishedVariants(?array $discriminator): array
    {
        $mapping = is_array($discriminator) && is_array($discriminator['mapping'] ?? null) ? $discriminator['mapping'] : [];
        $variants = [];
        foreach ($mapping as $value => $variant) {
            if (is_string($variant) && array_key_exists($variant, $this->dtoSchemas)) {
                $variants[(string)$value] = $variant;
            }
        }

        return $variants;
    }

    /**
     * The discriminator constants a variant has to declare itself, because it is a variant of more than
     * one discriminated union.
     *
     * Each union's interface publishes `DISCRIMINATOR_PROPERTY` and `VARIANTS`, and PHP refuses a class
     * that inherits one constant from two interfaces: "inherits both … which is ambiguous", a fatal on
     * autoload of a document that generated without a word. A constant the class declares itself
     * overrides both, so the variant restates them: the property the unions agree on and every value any
     * of them maps. `Animal::VARIANTS` keeps reading its own union's map, which is what callers use.
     *
     * Unions that discriminate by different properties, or map one value to different classes, have no
     * single answer to restate; generation stops and says so rather than writing a class that cannot load.
     *
     * @return array{property: string, variants: array<string, string>, unions: array<int, string>}|null
     */
    private function laravelVariantOwnDiscriminator(string $className): ?array
    {
        $unions = [];
        foreach ($this->laravelImplementedUnionInterfaces($className) as $unionInterface) {
            if ($this->laravelPublishedVariants($this->discriminatorSchemas[$unionInterface] ?? null) !== []) {
                $unions[] = $unionInterface;
            }
        }
        if (count($unions) < 2) {
            return null;
        }

        $propertyNames = [];
        $variants = [];
        foreach ($unions as $union) {
            $propertyNames[] = $this->discriminatorSchemas[$union]['propertyName'];
            foreach ($this->laravelPublishedVariants($this->discriminatorSchemas[$union]) as $value => $variant) {
                if (($variants[$value] ?? $variant) !== $variant) {
                    throw new RuntimeException(sprintf(
                        '%s is a variant of the discriminated unions %s, which map the value "%s" to different classes; laravel mode cannot declare one VARIANTS map for it.',
                        $className,
                        implode(', ', $unions),
                        $value,
                    ));
                }
                $variants[$value] = $variant;
            }
        }
        $propertyNames = array_values(array_unique($propertyNames));
        if (count($propertyNames) > 1) {
            throw new RuntimeException(sprintf(
                '%s is a variant of the discriminated unions %s, which discriminate by different properties (%s); laravel mode cannot declare one DISCRIMINATOR_PROPERTY for it.',
                $className,
                implode(', ', $unions),
                implode(', ', $propertyNames),
            ));
        }

        return ['property' => $propertyNames[0], 'variants' => $variants, 'unions' => $unions];
    }

    /**
     * @param array<int, string> $unionTypes
     * @param array<string, mixed>|null $discriminator
     * @param array<int, SchemaProperty> $sharedProperties the base's own properties, for an allOf base
     */
    private function renderLaravelUnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
        array $sharedProperties = [],
    ): string {
        $interfaceExtends = [];
        foreach ($this->implementedUnionInterfaces($className, null) as $unionInterface) {
            $interfaceExtends[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        $imports = [];
        // Every member is JsonSerializable, so a value typed as the union can be encoded as one.
        if ($interfaceExtends === []) {
            $interfaceExtends[] = $this->libraryClassRef('JsonSerializable', $namespace, $imports);
        }
        $interfaceGetters = [];
        foreach ($sharedProperties as $property) {
            $interfaceGetters[] = $this->resolveLaravelParam($property, $namespace);
            // Only what a getter's signature names: the rules and the hydrator stay with the variants.
            if ($this->temporalGetterBody($property) !== null || $this->temporalArrayGetterBody($property) !== null) {
                $imports[] = 'DateTimeImmutable';
            }
            if ($this->laravelIsUploadedFileProperty($property)) {
                $imports[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
            }
            foreach ([$this->laravelEnumClass($property), $this->laravelDtoClass($property), $this->laravelDtoItemClass($property)] as $referenced) {
                if ($referenced !== null) {
                    $this->appendImportForClass($imports, $referenced, $namespace, $className);
                }
            }
        }
        // The discriminator's map, public: a caller validating one variant on its own — an item of a
        // batch, say — picks the class by the value and calls that class's `rules()` directly.
        $variants = [];
        foreach ($this->laravelPublishedVariants($discriminator) as $value => $variant) {
            $this->appendImportForClass($imports, $variant, $namespace, $className);
            $variants[] = ['value' => $value, 'className' => $this->shortClassName($variant)];
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $this->renderPhpTemplate('dto.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'interfaceGetters' => $interfaceGetters,
            'discriminatorVariants' => $variants,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => [],
            'toArrayParams' => [],
            'rules' => [],
            'hydrators' => [],
            'implementedInterfaces' => [],
            'ignoredKeys' => [],
            'needsJsonObjectHelper' => false,
            'needsIntCoercionHelper' => false,
            'scalarCoercionHelpers' => [],
            'objectShapePaths' => [],
            'scalarTypePaths' => [],
            'nestedChildren' => [],
            'nestedValidation' => null,
            'selfReachable' => false,
            'reachesRecursion' => false,
            'rawPathsSelectMembers' => false,
            'checksObjectPaths' => false,
            'checksScalarPaths' => false,
            'laravelFormRequest' => $this->laravelFormRequest,
            'rawPathsEscapeDots' => false,
            'interpreterConstsBlock' => '',
            'hasObjectConstraints' => false,
            'interpreterMethodsBlock' => '',
            // Rendered as an interface; the discriminator is documented, not enforced here (nothing
            // in Laravel resolves a polymorphic payload on its own).
            'unionMembers' => $unionTypes,
            'interfaceExtends' => $interfaceExtends,
            'discriminatorProperty' => is_array($discriminator) && is_string($discriminator['propertyName'] ?? null)
                ? $discriminator['propertyName']
                : null,
        ]);
    }

    /**
     * The constructor default a schema `default` becomes, or `''` when PHP cannot spell one.
     *
     * An OBJECT default (`{type: object, default: {x: 1}}`, a `$ref` with a default) and a union one
     * have no constant expression, so the property gets none — and is then an optional property like
     * any other, nullable and null when absent, as in the other modes. Read as "has a default" it was
     * declared non-nullable with nothing to fall back on, and `fromValidated()` died with a TypeError
     * on every payload that left the key out.
     *
     * @param SchemaProperty $property
     */
    private function laravelDefaultLiteral(array $property, string $phpType): string
    {
        $default = $property['default'] ?? null;

        return $default === null ? '' : $this->renderDefaultValue($default, $phpType, $phpType);
    }

    /**
     * @param SchemaProperty $property
     * @return array{declaredType: string, docType: ?string, name: string, required: bool,
     *     openApiName: string, default: string, docDescription: ?string, getter: string,
     *     providedGetter: string, temporalGetterBody: ?string, temporalObjectGetter: string,
     *     temporalGetterReturnType: string, temporalGetterDocType: ?string, temporalObjectDocType: ?string,
     *     isTemporal: bool, isTemporalItems: bool, isEnum: bool, isDto: bool, itemClass: ?string,
     *     isDtoList: bool, toArrayExpression: string, needsIntCoercion: bool}
     */
    private function resolveLaravelParam(array $property, string $namespace): array
    {
        $phpType = $property['type'];
        $docType = null;

        if (str_contains($phpType, '<')) {
            $docType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
        }

        // NOT `$property['required']`: a readOnly property is absent from a request, `fromValidated()`
        // hands the constructor `null` for it, and a required parameter has nowhere to put that.
        $required = $this->propertyIsRequiredOnInput($property);
        // `nullable` on the property also covers "optional"; only the schema's own word counts here, as in
        // Symfony mode. An optional property WITH a default never holds null unless the schema allows it
        // — `?Kind $kind = Kind::A` invited a null the document rejects.
        $schemaNullable = $property['schemaNullable'] ?? $property['nullable'];
        $renderedDefault = $this->laravelDefaultLiteral(property: $property, phpType: $phpType);
        $declaredNullable = $schemaNullable || (!$required && $renderedDefault === '');
        $declaredType = $this->composePhpTypeHint($phpType, $declaredNullable);

        if ($required) {
            $defaultLiteral = '';
        } elseif ($renderedDefault !== '') {
            $defaultLiteral = $renderedDefault;
        } else {
            $defaultLiteral = ' = null';
        }

        $itemClass = $this->laravelDtoItemClass($property);

        return [
            'declaredType' => $declaredType,
            'docType' => $docType !== null ? $this->composePhpTypeHint($docType, $declaredNullable) : null,
            'name' => $property['name'],
            'required' => $required,
            // Required OF THE RESPONSE, which is a different question from `$required` above and the
            // only one `toArray()` may ask. A readOnly property is absent from every request, so its
            // request-presence flag is false forever — gating output on it dropped the field from the
            // response of a DTO the SERVER had just built and filled.
            'readOnly' => ($property['readOnly'] ?? false) === true,
            // An optional property the SCHEMA lets be null is always written, null included, as in Symfony
            // mode: its constructor default is that same null, so a builder passing `photo: null` could not
            // be told from one passing nothing, and the key silently left the response.
            'schemaNullable' => $schemaNullable,
            'openApiName' => $property['openApiName'],
            'default' => $defaultLiteral,
            // The bare expression after ` = `, which the constructor compares an argument against.
            'defaultExpression' => $defaultLiteral === '' ? null : substr($defaultLiteral, strlen(' = ')),
            'docDescription' => $this->resolveDocDescription($property),
            'getter' => 'get' . ucfirst($property['name']),
            'providedGetter' => 'is' . ucfirst($property['name']) . 'Provided',
            // Scalar first, then the container: `items: {format: date}` types the ITEM as
            // DateTimeImmutable and owes the reader the same formatted string the scalar getter
            // returns. Both bodies come from the Symfony renderer — hydration differs between the
            // modes, the FORMATTING does not, and a second copy of it is a second thing to drift.
            'temporalGetterBody' => $this->temporalGetterBody($property)
                ?? $this->temporalArrayGetterBody($property),
            'temporalObjectGetter' => 'get' . ucfirst($property['name']) . 'AsDateTime',
            // A temporal SCALAR reads as a string; a temporal CONTAINER stays an array and only its
            // items become strings, so there the declared type is unchanged and the docblock carries
            // the difference.
            'temporalGetterReturnType' => str_replace('DateTimeImmutable', 'string', $declaredType),
            'temporalGetterDocType' => $docType !== null
                ? str_replace('DateTimeImmutable', 'string', $this->composePhpTypeHint($docType, $declaredNullable))
                : null,
            'temporalObjectDocType' => $docType !== null
                ? $this->composePhpTypeHint($docType, $declaredNullable)
                : null,
            'isTemporal' => $this->temporalGetterBody($property) !== null,
            'isTemporalItems' => $this->temporalArrayGetterBody($property) !== null,
            'isEnum' => $this->laravelEnumClass($property) !== null,
            'isDto' => $this->laravelDtoClass($property) !== null,
            'itemClass' => $itemClass,
            'isDtoList' => $itemClass !== null,
            'toArrayExpression' => $this->laravelToArrayExpression($property),
            'needsIntCoercion' => $this->laravelPropertyAcceptsInt($declaredType),
            // An int-backed enum is read the way an `int` is: `?level=1` arrives as `"1"`, which
            // `Rule::enum` accepts and `Enum::from()` refuses under strict types — a 500 after the
            // validator had said yes.
            'scalarCoercion' => $this->laravelScalarCoercion($declaredType)
                ?? ($this->laravelIsIntBackedEnum($this->laravelEnumClass($property)) ? 'int' : null),
            'itemScalarCoercion' => $this->laravelItemScalarCoercion($property['type'])
                ?? ($this->laravelIsIntBackedEnum($this->laravelDtoItemClass($property)) ? 'int' : null),
        ];
    }

    /**
     * How one property is built from `validated()` data: the named-argument name plus the PHP
     * expression that produces the value. Hydration is OURS in this mode — `validated()` returns
     * plain arrays, so dates, enums and nested DTOs are cast here rather than by a serializer.
     *
     * @param SchemaProperty $property
     * @param array<string, mixed> $param
     * @return array{name: string, expression: string}
     */
    private function laravelHydratorFor(array $property, array $param): array
    {
        $key = $this->phpStringLiteral($property['openApiName']);
        $raw = sprintf('$data[%s]', $key);

        // `validated()` builds its result out of the LEAVES it could extract, so a container holding
        // nothing extractable loses its key entirely — `{"f":{}}`, `{"f":[{}]}`, `{"f":{"k":{}}}` and
        // `{"f":[[{}]]}` all arrive with `f` absent, even though the property has rules of its own and
        // `present` passed on it. Every branch below that treats the value as an array then died on it:
        // `Child::fromValidated(null)`, `array_map(..., null)`, and the constructor itself. A 500 AFTER
        // the validator said yes, which is the worst disagreement this mode can have.
        //
        // The key WAS sent, so an empty array is the faithful reconstruction. Only the required
        // non-nullable path needs it — every other one is wrapped in an `($data[...] ?? null) === null`
        // guard below, where absent has to stay absent.
        $containerRaw = $property['required'] === true && $property['nullable'] !== true
            ? sprintf('(%s ?? [])', $raw)
            : $raw;

        $enumClass = $this->laravelEnumClass($property);
        $dtoClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);

        // A JSON `42.0` is an integer per the spec and passes validation, but PHP decodes it to a float
        // and an `int` property cannot hold it — the constructor threw a TypeError, i.e. a 500 after a
        // successful validation. Coerce exactly the values the spec calls integers.
        //
        // A property of ONE scalar type also takes what Laravel's rules let through as that type: a
        // query, path or header value is a string on the wire (`?page=5`), and the body rules are lenient
        // the same way (`integer` passes `"5"`). Handed over as it came, an `int` property threw a
        // TypeError — a 500 after the validator said yes. A union such as `int|string` is left alone:
        // there `"5"` is a legitimate string.
        $raw = match ($param['scalarCoercion']) {
            'int' => sprintf('self::toIntValue(%s)', $raw),
            'float' => sprintf('self::toFloatValue(%s)', $raw),
            'bool' => sprintf('self::toBoolValue(%s)', $raw),
            default => $this->laravelPropertyAcceptsInt($param['declaredType'])
                ? sprintf('self::toIntIfIntegral(%s)', $raw)
                : $raw,
        };
        // `BoxA|DateTimeImmutable`: a string that is no enum case is the date. Parsed behind the
        // `tryFrom()` below, it was handed over as the string — a TypeError after validation passed.
        if ($this->laravelStringEnumsBesideDate($property['type']) !== []) {
            $raw = sprintf('(is_string(%1$s) ? new DateTimeImmutable(%1$s) : %2$s)', sprintf('$data[%s]', $key), $raw);
        }
        $raw = $this->laravelWithUnionEnumCast($param['declaredType'], sprintf('$data[%s]', $key), $raw);
        // `Shade|string`: the validator has already decided the value matches one member, and only the
        // class arrives as an array. Handed over as that array, the constructor threw a TypeError — a
        // 500 on every valid object.
        $unionDtoMember = $this->unionClassBesideScalars($param['declaredType']);
        if ($unionDtoMember !== null) {
            // Beside an `array` member the class is the array with keys — a list stays the list — and
            // beside a free-form object too, only the one the class accepts.
            $classMatch = $this->laravelClassMatchCondition($property, sprintf('$data[%s]', $key));
            $raw = sprintf(
                $this->unionHasArrayMember($param['declaredType'])
                    ? '(is_array(%1$s) && !array_is_list(%1$s)' . ($classMatch === null ? '' : ' && ' . $classMatch) . ' ? %2$s : (%3$s))'
                    : '(is_array(%1$s) ? %2$s : (%3$s))',
                sprintf('$data[%s]', $key),
                $this->laravelNestedDtoExpression(
                    dtoClass: $unionDtoMember,
                    rawAccess: sprintf('$data[%s]', $key),
                    wireName: $property['openApiName'],
                ),
                $raw,
            );
        }
        // `Olive|Teal`: two classes, and the array alone does not say which. Handed over raw, the
        // constructor threw a TypeError — a 500 on every valid object. The validator has already decided
        // the value matches a member (and, for `oneOf`, exactly one), so the first member whose own
        // schema accepts it is the one built.
        $unionClasses = $this->laravelUndiscriminatedUnionClasses($property);
        // A union COMPONENT (`$ref: Shade`) is such a union too, though the type names one class.
        $builtAsUnionMember = $unionClasses !== [];
        if ($unionClasses !== []) {
            $access = sprintf('$data[%s]', $key);
            $arms = [];
            $lastClass = array_pop($unionClasses);
            foreach ($unionClasses as $memberClass) {
                $arms[] = sprintf(
                    '%s => %s',
                    $this->laravelUnionMemberMatchCondition($memberClass, $access),
                    $this->laravelNestedDtoExpression(dtoClass: $memberClass, rawAccess: $access, wireName: $property['openApiName']),
                );
            }
            $arms[] = 'default => ' . $this->laravelNestedDtoExpression(dtoClass: $lastClass, rawAccess: $access, wireName: $property['openApiName']);
            $raw = sprintf(
                '(%s ? match (true) { %s } : (%s))',
                $this->laravelUnionClassesObjectCondition(phpType: $property['type'], valueAccess: $access),
                implode(', ', $arms),
                $raw,
            );
        }

        $itemsNullable = $this->laravelItemsNullable($property);
        $inlineDiscriminator = $this->laravelInlineDiscriminator($property);
        $nestedDtoContainer = $this->laravelNestedDtoContainerExpression($property['type'], $containerRaw, $property['openApiName']);
        $value = match (true) {
            $param['isTemporal'] === true => sprintf('new DateTimeImmutable(%s)', $raw),
            // A `oneOf` of a `format: date` and a `format: date-time` string has no single format to
            // read back, but its type is still a date — and `validated()` hands over the string. The
            // constructor threw a TypeError on it, a 500 after the validator had accepted the payload.
            $this->shortClassName(ltrim($property['type'], '?')) === 'DateTimeImmutable' => sprintf(
                'new DateTimeImmutable(%s)',
                $raw,
            ),
            // A date beside another scalar — `oneOf: [{type: integer}, {format: date-time}]`, typed
            // `int|DateTimeImmutable`. With no `string` member, a string can only be the date, and it
            // was handed over as the string: a TypeError, a 500 on every valid date.
            $this->isTemporalScalarUnion($property['type']) => sprintf(
                '(is_string(%1$s) ? new DateTimeImmutable(%1$s) : %2$s)',
                sprintf('$data[%s]', $key),
                $raw,
            ),
            // The ITEMS of a temporal container, cast the same way the scalar above is. Without this
            // the property held the strings `validated()` handed over while its docblock promised
            // `array<DateTimeImmutable>` — a lie PHPStan downstream was already believing. `array_map`
            // keeps string keys, so a MAP of dates survives as a map.
            // A null item the document allows is handed over as null: a closure typed `string $item`
            // threw a TypeError on it, a 500 after the validator had accepted the payload.
            $param['isTemporalItems'] === true && $itemsNullable => sprintf(
                'array_map(static fn(?string $item): ?DateTimeImmutable => $item === null ? null : new DateTimeImmutable($item), %s)',
                $containerRaw,
            ),
            $param['isTemporalItems'] === true => sprintf(
                'array_map(static fn(string $item): DateTimeImmutable => new DateTimeImmutable($item), %s)',
                $containerRaw,
            ),
            $enumClass !== null => sprintf('%s::from(%s)', $this->shortClassName($enumClass), $raw),
            // `Circle|Square` written inline: handed over as the array it arrived as, the constructor
            // refused it — a TypeError, a 500 for every request the validator had accepted.
            $inlineDiscriminator !== null => $this->laravelDiscriminatorMatch(
                rawAccess: $containerRaw,
                propertyName: $inlineDiscriminator['propertyName'],
                mapping: $inlineDiscriminator['mapping'],
            ),
            $dtoClass !== null && !$builtAsUnionMember => $this->laravelNestedDtoExpression(
                dtoClass: $dtoClass,
                rawAccess: $containerRaw,
                wireName: $property['openApiName'],
            ),
            $itemClass !== null && $this->laravelIsEnumClass($itemClass) && $itemsNullable => sprintf(
                'array_map(static fn(int|string|null $item): ?%1$s => $item === null ? null : %1$s::from(%3$s), %2$s)',
                $this->shortClassName($itemClass),
                $containerRaw,
                $this->laravelIsIntBackedEnum($itemClass) ? 'self::toIntValue($item)' : '$item',
            ),
            $itemClass !== null && $this->laravelIsEnumClass($itemClass) => sprintf(
                'array_map(static fn(int|string $item): %1$s => %1$s::from(%3$s), %2$s)',
                $this->shortClassName($itemClass),
                $containerRaw,
                $this->laravelIsIntBackedEnum($itemClass) ? 'self::toIntValue($item)' : '$item',
            ),
            // The ITEM is built exactly the way a scalar nested value is — through the one place
            // that knows a discriminated union base is an interface with no `fromValidated()` to
            // call. Emitting `%1$s::fromValidated($item)` here was the array half of that same
            // mistake: the scalar path dispatched on the discriminator, the items path called the
            // interface, and the request died on `Error: Call to undefined method`.
            $itemClass !== null && $itemsNullable => sprintf(
                'array_map(static fn(?array $item): ?%s => $item === null ? null : %s, %s)',
                $this->shortClassName($itemClass),
                $this->laravelNestedDtoExpression($itemClass, '$item', $property['openApiName']),
                $containerRaw,
            ),
            $itemClass !== null => sprintf(
                'array_map(static fn(array $item): %s => %s, %s)',
                $this->shortClassName($itemClass),
                $this->laravelNestedDtoExpression($itemClass, '$item', $property['openApiName']),
                $containerRaw,
            ),
            // DTOs two containers deep — `array<array<Tag>>`, `array<string, array<Tag>>`, a list of maps
            // of them. Declared as the class, as in runtime and Symfony mode, but handed over as the
            // plain arrays `validated()` holds, so `->getId()` on one was a fatal error.
            $nestedDtoContainer !== null => $nestedDtoContainer,
            // The ITEMS of a list or map of one scalar type, for the reason the scalar above is cast: a
            // query string carries `?ids[]=3` as `["3"]`, which passed `ids.*: integer` and reached a
            // property documented `array<int>` as strings.
            $param['itemScalarCoercion'] !== null => sprintf(
                'array_map(self::to%sValue(...), %s)',
                ucfirst($param['itemScalarCoercion']),
                $containerRaw,
            ),
            // A container with no class of its own — a list of lists, a map of scalars — still reaches
            // a constructor parameter typed `array`, so it needs the same accessor.
            default => $param['declaredType'] === 'array' || str_starts_with($param['declaredType'], '?array')
                ? $containerRaw
                : $raw,
        };

        // A readOnly property is server-owned: runtime mode ignores whatever the client sent, so the
        // field stays unset and is omitted from the response. Hydration mirrors that by treating the key
        // as absent.
        // An absent key with a schema default is that default; the type no longer admits null there.
        if (($property['readOnly'] ?? null) === true) {
            return ['name' => $property['name'], 'expression' => $param['defaultExpression'] ?? 'null'];
        }

        // An optional key with a schema default: absent means that default, as the constructor's own
        // default says. Handing over null instead lost it — `getType()` answered null for a query
        // parameter the document defaults to `all_vendors`. A null is still handed over only where the
        // schema allows one; elsewhere the rules have refused it before this runs.
        $default = $param['defaultExpression'];
        if ($param['required'] !== true && $default !== null && $default !== 'null') {
            $admitsNull = str_starts_with($param['declaredType'], '?')
                || array_intersect(['null', 'mixed'], explode('|', $param['declaredType'])) !== [];
            $value = $admitsNull
                ? sprintf('!array_key_exists(%1$s, $data) ? %2$s : ($data[%1$s] === null ? null : %3$s)', $key, $default, $value)
                : sprintf('($data[%s] ?? null) === null ? %s : %s', $key, $default, $value);
        } elseif ($property['required'] !== true || $property['nullable'] === true) {
            // An optional key is absent from `validated()` when it was not sent, and may be an explicit
            // null when it was — both have to reach the constructor as null without touching the cast.
            $value = sprintf('($data[%s] ?? null) === null ? null : %s', $key, $value);
        }

        return ['name' => $property['name'], 'expression' => $value];
    }

    /**
     * The expression that builds a container whose VALUES are containers of DTOs, or null when the
     * type is not that shape. One level of `array_map` per container, null kept where `?` allows it.
     */
    private function laravelNestedDtoContainerExpression(string $type, string $rawAccess, string $wireName): ?string
    {
        $outer = $this->laravelContainerValueType($type);
        if ($outer === null) {
            return null;
        }
        $inner = $this->laravelContainerValueType($outer);
        if ($inner === null) {
            return null;
        }

        $leafNullable = str_starts_with($inner, '?');
        $leaf = ltrim($inner, '?');
        if (!array_key_exists($leaf, $this->dtoSchemas) || $this->laravelIsEnumClass($leaf)) {
            return null;
        }

        $build = $this->laravelNestedDtoExpression($leaf, '$item', $wireName);
        $itemClosure = $leafNullable
            ? sprintf('static fn(?array $item): ?%s => $item === null ? null : %s', $this->shortClassName($leaf), $build)
            : sprintf('static fn(array $item): %s => %s', $this->shortClassName($leaf), $build);
        $rowClosure = str_starts_with($outer, '?')
            ? sprintf('static fn(?array $row): ?array => $row === null ? null : array_map(%s, $row)', $itemClosure)
            : sprintf('static fn(array $row): array => array_map(%s, $row)', $itemClosure);

        return sprintf('array_map(%s, %s)', $rowClosure, $rawAccess);
    }

    /**
     * The `toArray()` side of `laravelNestedDtoContainerExpression()`, or null when the type is not
     * a container of containers of DTOs.
     */
    private function laravelNestedDtoContainerToWire(string $type, string $access): ?string
    {
        $outer = $this->laravelContainerValueType($type);
        if ($outer === null) {
            return null;
        }
        $inner = $this->laravelContainerValueType($outer);
        if ($inner === null) {
            return null;
        }

        $leaf = ltrim($inner, '?');
        if (!array_key_exists($leaf, $this->dtoSchemas) || $this->laravelIsEnumClass($leaf)) {
            return null;
        }

        $itemMapper = $this->laravelItemToWireMapper($leaf, str_starts_with($inner, '?'));

        $isMap = static fn(string $container): bool => preg_match('/^\??array<\s*(?:string|int)\s*,/', $container) === 1;
        $row = $isMap($outer)
            ? sprintf('self::toJsonObjects(array_map(%s, $row))', $itemMapper)
            : sprintf('array_map(%s, array_values($row))', $itemMapper);
        $rowClosure = str_starts_with($outer, '?')
            ? sprintf('static fn(?array $row): array|object|null => $row === null ? null : %s', $row)
            : sprintf('static fn(array $row): array|object => %s', $row);

        return $isMap($type)
            ? sprintf('self::toJsonObjects(array_map(%s, %s))', $rowClosure, $access)
            : sprintf('array_map(%s, array_values(%s))', $rowClosure, $access);
    }

    /**
     * The value type of `array<V>` / `array<K, V>` (with any leading `?` on the container dropped), or
     * null when the type is not a generic container.
     */
    private function laravelContainerValueType(string $type): ?string
    {
        $type = ltrim($type, '?');
        if (!str_starts_with($type, 'array<') || !str_ends_with($type, '>')) {
            return null;
        }

        $inner = substr($type, 6, -1);
        $depth = 0;
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            $character = $inner[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                return trim(substr($inner, $i + 1));
            }
        }

        return trim($inner);
    }

    private function laravelIsIntBackedEnum(?string $className): bool
    {
        return $className !== null && $this->laravelIsEnumClass($className) && $this->enumSchemas[$className]['type'] === 'int';
    }

    /**
     * How one property goes back onto the wire. Nothing serializes this DTO for us in this mode, so
     * an enum has to be unwrapped to its backing value, a nested DTO recursed into, and a temporal
     * value formatted the way the schema declares it.
     *
     * @param SchemaProperty $property
     */
    private function laravelToArrayExpression(array $property): string
    {
        if (($property['writeOnly'] ?? null) === true) {
            return '';
        }

        $name = $property['name'];
        $propertyAccess = '$this->' . $name;
        // The same answer `resolveLaravelParam()` gives: an optional property with a schema default is
        // not nullable, and `?->` on it read as if it were.
        $nullable = ($property['schemaNullable'] ?? $property['nullable']) === true
            || (!$this->propertyIsRequiredOnInput($property) && $this->laravelDefaultLiteral(
                property: $property,
                phpType: str_contains($property['type'], '<') ? 'array' : $property['type'],
            ) === '');
        $arrow = $nullable ? '?->' : '->';

        if ($this->temporalGetterBody($property) !== null) {
            return sprintf('$this->%s()', 'get' . ucfirst($name));
        }

        // A temporal container goes out through its getter for the same reason the scalar does — the
        // getter is the one place that knows `format: date` must not grow a time part. A MAP still
        // needs the object cast around it: `type: object` is `{}` on the wire even when empty, and
        // `json_encode()` cannot tell an empty map from an empty list.
        if ($this->temporalArrayGetterBody($property) !== null) {
            $formatted = sprintf('$this->%s()', 'get' . ucfirst($name));

            if (($property['isMap'] ?? null) !== true) {
                return $formatted;
            }

            return $nullable
                ? sprintf('%s === null ? null : self::toJsonObjects(%s)', $propertyAccess, $formatted)
                : sprintf('self::toJsonObjects(%s)', $formatted);
        }

        if ($this->laravelEnumClass($property) !== null) {
            return $propertyAccess . $arrow . 'value';
        }

        // DTOs two containers deep (see `laravelNestedDtoContainerExpression()`): each one written out
        // with its own `toArray()`, lists reindexed and maps kept as JSON objects at both levels.
        $nestedWire = $this->laravelNestedDtoContainerToWire($property['type'], $propertyAccess);
        if ($nestedWire !== null) {
            return $nullable ? sprintf('%s === null ? null : %s', $propertyAccess, $nestedWire) : $nestedWire;
        }

        // A map must encode as a JSON object, empty or not — `type: object` says so, and runtime mode
        // casts for exactly that reason. Nothing third-party sits in the way here. The cast is deep: a
        // free-form value can nest maps at any level, and each one is an object on the wire.
        if (($property['isMap'] ?? null) === true) {
            // `toJsonObjects()` converts ARRAYS and hands anything else back untouched, so a map whose
            // VALUES are DTOs or enums has to be flattened first — otherwise the objects reach
            // `json_encode()`, which sees only public state and writes `{}` for a DTO whose properties
            // are private. `array_map()` over one array keeps the string keys, so the map survives.
            // A value the document types as an object — `{type: object}`, or an object member of a
            // union without an array one — is `{}` when empty; `toJsonObjects()` alone wrote the empty
            // value back as `[]`, since `array_is_list([])` holds.
            $valueClass = $this->laravelDtoItemClass($property);
            $objectValues = $valueClass === null ? $this->laravelObjectItemWriter($property) : null;
            $mapped = match (true) {
                $valueClass !== null => sprintf('array_map(%s, %s)', $this->laravelItemToWireMapper($valueClass, $this->laravelItemsNullable($property)), $propertyAccess),
                $objectValues !== null => sprintf('array_map(%s, %s)', $objectValues, $propertyAccess),
                default => $propertyAccess,
            };

            return $nullable
                ? sprintf('%s === null ? null : self::toJsonObjects(%s)', $propertyAccess, $mapped)
                : sprintf('self::toJsonObjects(%s)', $mapped);
        }

        if ($this->laravelDtoClass($property) !== null || $this->laravelInlineDiscriminator($property) !== null) {
            // `?: (object)[]` because `type: object` with nothing to write is `{}`, and `toArray()`
            // hands back the empty array PHP cannot tell from an empty LIST. Spelled long-hand for
            // the nullable case: `?->toArray() ?: (object)[]` would turn a legitimate null into `{}`.
            return $nullable
                ? sprintf('%s === null ? null : (%s->toArray() ?: (object)[])', $propertyAccess, $propertyAccess)
                : sprintf('%s->toArray() ?: (object)[]', $propertyAccess);
        }

        if ($this->laravelDescribesListOfMaps($property['type'])) {
            $itemSchema = $this->laravelConstraintsFor($property)['items'] ?? null;
            $mapper = sprintf(
                'static fn(?array $item): ?object => $item === null ? null : %s',
                is_array($itemSchema) ? $this->laravelObjectFromSchema($itemSchema, '$item', 0) : 'self::toJsonObjects($item)',
            );

            return $nullable
                ? sprintf('%s === null ? null : array_map(%s, array_values(%s))', $propertyAccess, $mapper, $propertyAccess)
                : sprintf('array_map(%s, array_values(%s))', $mapper, $propertyAccess);
        }

        $itemClass = $this->laravelDtoItemClass($property);
        if ($itemClass !== null) {
            $mapper = $this->laravelItemToWireMapper($itemClass, $this->laravelItemsNullable($property));

            return $nullable
                ? sprintf('%s === null ? null : array_map(%s, array_values(%s))', $propertyAccess, $mapper, $propertyAccess)
                : sprintf('array_map(%s, array_values(%s))', $mapper, $propertyAccess);
        }

        // A list whose items are objects only through a union or an `allOf`, or one list further down
        // (`items: {anyOf: [{type: object}, {type: integer}]}`, `items: {type: array, items: {type:
        // object}}`): an empty item is `{}`. `toJsonValue()` cannot tell it from an empty list and
        // wrote `[]`; the writer asks the schema instead.
        $objectItems = $this->laravelObjectItemWriter($property);
        if ($objectItems !== null) {
            $wire = sprintf('array_map(%s, array_values(%s))', $objectItems, $propertyAccess);

            return $nullable ? sprintf('%s === null ? null : %s', $propertyAccess, $wire) : $wire;
        }

        // A container whose type says nothing about its items — the items are a union of classes, or
        // the property itself is a union of a list and a map — is written through `toJsonValue()`: a
        // DTO item as its `toArray()`, which `json_encode()` would otherwise write as `{}` from private
        // state. A union that admits a map goes out keyed as the value is, as in runtime mode;
        // `array_values()` turned `{"702": "label"}` into `["label"]`.
        if ($this->laravelIsUntypedContainer($property)) {
            $wire = ($property['isShapeUnion'] ?? false) === true
                ? sprintf('self::toJsonValue(%s)', $propertyAccess)
                : sprintf('array_map(self::toJsonValue(...), array_values(%s))', $propertyAccess);

            return $nullable ? sprintf('%s === null ? null : %s', $propertyAccess, $wire) : $wire;
        }

        // A LIST is reindexed on the way out, for the reason spelled out in `RendersRuntimeDto`: the
        // document promised a JSON array, PHP keys are not part of that promise, and the result of an
        // `array_filter()` handed to the constructor would otherwise be written as `{"0":…,"2":…}`. A
        // MAP never reaches here — it returned above, where its keys ARE the data.
        if ($this->laravelDescribesList($property)) {
            // `array|bool`: the list is one member, and `array_values()` on the other was a TypeError.
            $topLevelMembers = array_diff(explode('|', ltrim((string)preg_replace('/<.*>/s', '', $property['type']), '?')), ['null']);
            // `anyOf: [{type: object}, {type: string}]`: the array is the OBJECT member, and
            // `array_values()` wrote `{"a": 1}` back as `[1]`.
            if (count($topLevelMembers) > 1 && $this->laravelUnionAdmitsObjectButNoArray($this->laravelConstraintsFor($property))) {
                return sprintf('is_array(%1$s) ? (object)%1$s : %1$s', $propertyAccess);
            }
            // `anyOf: [{anyOf: [{type: object}, {type: boolean}]}, {type: array}]`: the object member sits
            // in a nested union, and `array_values()` wrote `{"a": 1}` back as `[1]`. The array may be
            // either, so it goes out keyed as it is.
            $admittedTypes = $this->laravelAdmittedJsonTypes($this->laravelConstraintsFor($property));
            if (count($topLevelMembers) > 1 && $admittedTypes !== null && in_array('object', $admittedTypes, true)) {
                return $propertyAccess;
            }
            if (count($topLevelMembers) > 1) {
                return sprintf('is_array(%1$s) ? array_values(%1$s) : %1$s', $propertyAccess);
            }

            return $nullable
                ? sprintf('%s === null ? null : array_values(%s)', $propertyAccess, $propertyAccess)
                : sprintf('array_values(%s)', $propertyAccess);
        }

        // A union with one class member writes the object the way a plain nested DTO is written; the
        // scalar members go out as they are.
        $unionDtoMember = $this->unionClassBesideScalars($property['type']);
        $wire = $unionDtoMember === null
            ? $this->laravelUnionEnumToWire($property, $propertyAccess)
            : sprintf(
                '%1$s instanceof %2$s ? (%1$s->toArray() ?: (object)[]) : (%3$s)',
                $propertyAccess,
                $this->shortClassName($unionDtoMember),
                $this->laravelUnionEnumToWire($property, $propertyAccess),
            );

        // A date beside other members — `DateTimeImmutable|Shade`, `int|DateTimeImmutable` — went out as
        // the PHP object, which `json_encode()` writes as `{"date": …, "timezone_type": 3}`. So did a union
        // of ONE temporal member (`anyOf: [{format: date}]`, `oneOf: [{format: date-time}, {type: null}]`),
        // collapsed to `?DateTimeImmutable` with no `temporalFormat` for the getter above to read.
        $collapsedTemporalUnion = $this->shortClassName(ltrim($property['type'], '?')) === 'DateTimeImmutable'
            && ($property['unionTemporalFormats'] ?? []) !== [];
        if ($collapsedTemporalUnion || (str_contains($property['type'], '|') && in_array('DateTimeImmutable', array_map($this->shortClassName(...), $this->unionMembersBesideNull($property['type'])), true))) {
            $formatted = ($property['unionTemporalFormats'] ?? []) === ['date']
                ? sprintf("%s->format('Y-m-d')", $propertyAccess)
                : sprintf("(%1\$s->format('u') === '000000' ? %1\$s->format('c') : %1\$s->format('Y-m-d\\TH:i:s.uP'))", $propertyAccess);

            return sprintf('%1$s instanceof DateTimeImmutable ? %2$s : (%3$s)', $propertyAccess, $formatted, $wire);
        }

        return $wire;
    }

    /**
     * A union with an enum member writes the case's value, the way a plain enum property does.
     *
     * @param SchemaProperty $property
     */
    private function laravelUnionEnumToWire(array $property, string $propertyAccess): string
    {
        foreach (explode('|', str_replace('?', '', $property['type'])) as $member) {
            $enumName = $this->shortClassName(trim($member));
            if (str_contains($property['type'], '|') && array_key_exists($enumName, $this->enumSchemas)) {
                return sprintf('%1$s instanceof %2$s ? %1$s->value : %1$s', $propertyAccess, $enumName);
            }
        }

        return $propertyAccess;
    }

    /**
     * The expression that writes the array `$access`, which the document types as an object with
     * schema `$schema`, as a JSON object. Where the schema says what its VALUES are — a map of objects,
     * a map of lists of objects — each value is written by that schema too: `toJsonObjects()` alone
     * guessed every level below with `array_is_list()`, and an empty object two containers down went
     * out as `[]`.
     *
     * @param array<array-key, mixed> $schema
     */
    private function laravelObjectFromSchema(array $schema, string $access, int $depth): string
    {
        $values = $schema['additionalProperties'] ?? null;
        $properties = $schema['properties'] ?? null;
        $valueWriter = is_array($values) && $values !== [] && (!is_array($properties) || $properties === [])
            ? $this->laravelValueFromSchema($values, $depth + 1)
            : null;

        return $valueWriter === null
            ? sprintf('self::toJsonObjects(%s)', $access)
            : sprintf('(object)array_map(%s, %s)', $valueWriter, $access);
    }

    /**
     * The closure that writes one value by its schema when the schema says something `toJsonObjects()`
     * cannot see — an object, or a list that holds one at some depth — or null when the value can be
     * left to it.
     *
     * @param array<array-key, mixed> $schema
     */
    private function laravelValueFromSchema(array $schema, int $depth): ?string
    {
        $types = $depth > self::LARAVEL_SHAPE_PATH_DEPTH ? null : $this->laravelAdmittedJsonTypes($schema);
        if ($types === null) {
            return null;
        }
        if (in_array('object', $types, true) && !in_array('array', $types, true)) {
            $composed = array_intersect(['anyOf', 'oneOf', 'allOf'], array_keys($schema)) !== [];

            return sprintf(
                'static fn(mixed $item): mixed => is_array($item) ? %s : %s',
                $this->laravelObjectFromSchema($schema, '$item', $depth),
                $composed ? 'self::toJsonValue($item)' : '$item',
            );
        }
        $items = $schema['items'] ?? null;
        if (array_values(array_diff($types, ['null'])) !== ['array'] || !is_array($items)) {
            return null;
        }
        $itemWriter = $this->laravelValueFromSchema($items, $depth + 1);

        return $itemWriter === null
            ? null
            : sprintf('static fn(mixed $item): mixed => is_array($item) ? array_map(%s, array_values($item)) : $item', $itemWriter);
    }

    /**
     * The closure that writes one item (of a list) or value (of a map) whose schema admits an object
     * and no array — `{type: object}`, `anyOf: [{type: object}, {type: integer}]`, `allOf: [{type:
     * object}]` — possibly under further lists (`items: {type: array, items: {type: object}}`). An
     * array there IS the object, so it is written as one, `{}` when empty; anything else goes through
     * `toJsonValue()`. Null when the container is not such a list or map, or the items could also be
     * an array: a union with an array member is left to the value, as before.
     *
     * @param SchemaProperty $property
     */
    private function laravelObjectItemWriter(array $property): ?string
    {
        if (($property['isShapeUnion'] ?? false) === true || !str_starts_with(ltrim($property['type'], '?'), 'array')) {
            return null;
        }
        $constraints = $this->laravelConstraintsFor($property);
        $isMap = ($property['isMap'] ?? null) === true;
        $containerTypes = $this->laravelAdmittedJsonTypes($constraints);
        if ($containerTypes === null || array_values(array_diff($containerTypes, ['null'])) !== [$isMap ? 'object' : 'array']) {
            return null;
        }

        $schema = $constraints[$isMap ? 'additionalProperties' : 'items'] ?? null;
        $depth = 0;
        while (is_array($schema)) {
            $types = $this->laravelAdmittedJsonTypes($schema);
            if ($types === null) {
                return null;
            }
            if (in_array('object', $types, true) && !in_array('array', $types, true)) {
                // A list of `{type: object}` items, at the top, is already written by the list-of-maps
                // mapper; this writer is for what that one does not reach.
                // A union member beside the object may be a generated class or an enum, which
                // `toJsonValue()` writes; a plain `type: object` leaves nothing but null beside an array.
                $composed = array_intersect(['anyOf', 'oneOf', 'allOf'], array_keys($schema)) !== [];
                $writer = sprintf(
                    'static fn(mixed $item): mixed => is_array($item) ? %s : %s',
                    $this->laravelObjectFromSchema($schema, '$item', 0),
                    $composed ? 'self::toJsonValue($item)' : '$item',
                );
                for ($level = 0; $level < $depth; $level++) {
                    $writer = sprintf('static fn(mixed $row): mixed => is_array($row) ? array_map(%s, array_values($row)) : $row', $writer);
                }

                return $writer;
            }
            if (array_values(array_diff($types, ['null'])) !== ['array']) {
                return null;
            }
            $schema = $schema['items'] ?? null;
            $depth++;
        }

        return null;
    }

    /**
     * A bare `array`: no item type the generator could name, so the value decides how it is written.
     *
     * @param SchemaProperty $property
     */
    private function laravelIsUntypedContainer(array $property): bool
    {
        return ltrim($property['type'], '?') === 'array' && ($property['isMap'] ?? null) !== true;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     */
    private function laravelNeedsJsonValueHelper(array $properties): bool
    {
        foreach ($properties as $property) {
            if (($property['writeOnly'] ?? null) !== true && ($this->laravelIsUntypedContainer($property) || str_contains($this->laravelToArrayExpression($property), 'self::toJsonValue('))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this property is a LIST — an array whose keys the wire form does not carry.
     *
     * @param SchemaProperty $property
     */
    private function laravelDescribesList(array $property): bool
    {
        return str_starts_with($property['type'], 'array')
            && ($property['isMap'] ?? null) !== true;
    }

    /**
     * The closure that turns ONE container value into its wire form: an enum into its backing value,
     * a DTO into its array. Used by both containers — a list maps it over the items, a map maps it
     * over the values and then casts the result.
     *
     * `$itemNullable`: the items admit null (`array<?Fox>`, an item `oneOf: [$ref, null]` or a
     * `nullable: true` item). The constructor already lets a null item through, so the mapper must
     * hand it back as null — typed `Fox $item`, it was a TypeError in `toArray()`.
     */
    private function laravelItemToWireMapper(string $itemClass, bool $itemNullable): string
    {
        $short = $this->shortClassName($itemClass);

        if ($this->laravelIsEnumClass($itemClass)) {
            return $itemNullable
                ? sprintf('static fn(?%s $item): int|string|null => $item?->value', $short)
                : sprintf('static fn(%s $item): int|string => $item->value', $short);
        }

        // Same reason as the scalar embed: an ITEM that is a `type: object` with nothing to write is
        // `{}`. `toJsonObjects()` leaves a non-array alone, so a cast item survives the map cast that
        // follows it.
        return $itemNullable
            ? sprintf('static fn(?%s $item): array|object|null => $item === null ? null : ($item->toArray() ?: (object)[])', $short)
            : sprintf('static fn(%s $item): array|object => $item->toArray() ?: (object)[]', $short);
    }

    /**
     * Forgets which classes of the PREVIOUS document carried an interpreter.
     *
     * Hygiene, not a fix: the map is keyed by class name and is always written before it is read for
     * that class, and a class the new document does not declare is never rendered and so never asked
     * about — measured across two generations from one Command instance, with and without an
     * interpreter, and the output was identical either way. It is cleared anyway so a second document
     * cannot inherit a fact about the first, which is the same reason
     * `resetSymfonyReachabilityCache()` exists next to it.
     */
    private function resetLaravelRenderState(): void
    {
        $this->laravelClassesWithInterpreter = [];
        $this->laravelRecursiveFolds = [];
    }

    /**
     * The FormRequest for a request-payload class: the first-party place where Laravel resolves,
     * validates and only then hands the controller a typed object.
     *
     * It is deliberately thin — `rules()`, `withValidator()` and `toDto()` all delegate to the DTO — so
     * the two files never disagree, and an application that prefers its own FormRequest can ignore this
     * one and call the same three methods.
     */
    private function renderLaravelFormRequestClass(string $namespace, string $dtoClassName): string
    {
        if ($this->laravelHasVariantFormRequest($dtoClassName)) {
            return $this->renderLaravelVariantFormRequestClass($namespace, $dtoClassName);
        }
        $unionBody = $this->laravelUnionBodyMembers($dtoClassName);
        if ($unionBody !== null) {
            return $this->renderLaravelUnionMembersFormRequestClass(
                namespace: $namespace,
                dtoClassName: $dtoClassName,
                members: $unionBody['members'],
                exactlyOne: $unionBody['exactlyOne'],
            );
        }

        $hasInterpreter = $this->laravelClassesWithInterpreter[$dtoClassName] ?? false;

        // `withValidator()` is always forwarded: besides the interpreter it keeps the containers
        // `validated()` returns whole (see the DTO's method).
        $imports = [];
        $this->importLibraryClass(fqcn: 'Illuminate\Foundation\Http\FormRequest', namespace: $namespace, useStatements: $imports);
        $this->importLibraryClass(fqcn: 'Illuminate\Validation\Validator', namespace: $namespace, useStatements: $imports);
        sort($imports);

        // Laravel validates the query string and the body only. A parameter declared `in: path`,
        // `header` or `cookie` is elsewhere, and without being brought in its `present` rule failed
        // on every request — the FormRequest answered 422 whatever was sent.
        $located = ['path' => [], 'header' => [], 'cookie' => []];
        $properties = $this->dtoSchemas[$dtoClassName]['properties'] ?? [];
        foreach (is_array($properties) ? $properties : [] as $name => $schema) {
            $in = is_array($schema) ? ($schema['x-parameter-in'] ?? null) : null;
            if (is_string($name) && is_string($in) && array_key_exists($in, $located)) {
                $located[$in][] = $name;
            }
        }

        return $this->renderPhpTemplate('formrequest.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $dtoClassName . 'FormRequest',
            'dtoClassName' => $dtoClassName,
            'hasInterpreter' => $hasInterpreter,
            'pathParameters' => $located['path'],
            'headerParameters' => $located['header'],
            'cookieParameters' => $located['cookie'],
            'readsParameters' => $this->laravelParameterReads($this->getSchemaProperties($dtoClassName)) !== [],
            'rulesKeyType' => $this->laravelRulesKeyType(array_column($this->getSchemaProperties($dtoClassName), 'openApiName')),
            'sourceEndpoint' => $this->endpointByClass[$dtoClassName] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($dtoClassName),
        ]);
    }

    /**
     * A body that is one of several variants — a discriminated `oneOf`/`anyOf`, or the base of the
     * allOf pattern — is an interface, with no `rules()` or `fromValidated()` of its own. Its FormRequest
     * reads the discriminator and takes everything from the variant it names.
     */
    private function laravelHasVariantFormRequest(string $className): bool
    {
        return $this->laravelDiscriminatorBaseIsInterface($className)
            && ($this->discriminatorSchemas[$className]['mapping'] ?? []) !== [];
    }

    private function renderLaravelVariantFormRequestClass(string $namespace, string $dtoClassName): string
    {
        $discriminator = $this->discriminatorSchemas[$dtoClassName];
        $imports = [];
        $this->importLibraryClass(fqcn: 'Illuminate\Foundation\Http\FormRequest', namespace: $namespace, useStatements: $imports);
        $this->importLibraryClass(fqcn: 'Illuminate\Validation\Validator', namespace: $namespace, useStatements: $imports);
        $this->importLibraryClass(fqcn: 'LogicException', namespace: $namespace, useStatements: $imports);
        // The template names the facade in full, as the enum rule does; a document's own `Rule` gets it
        // an alias from the import pass instead of this plain import.
        if (!$this->namespaceDeclaresClass('Rule', $namespace)) {
            $imports[] = 'Illuminate\Validation\Rule';
        }
        $arms = [];
        foreach ($discriminator['mapping'] as $value => $variant) {
            $this->appendImportForClass($imports, $variant, $namespace, $dtoClassName . 'FormRequest');
            $arms[] = ['value' => (string)$value, 'variant' => $this->shortClassName($variant)];
        }
        $imports = array_values(array_unique($imports));
        sort($imports);

        return $this->renderPhpTemplate('formrequest.laravel-union.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $dtoClassName . 'FormRequest',
            'dtoClassName' => $dtoClassName,
            'discriminatorProperty' => $discriminator['propertyName'],
            'arms' => $arms,
            'sourceEndpoint' => $this->endpointByClass[$dtoClassName] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($dtoClassName),
        ]);
    }

    /**
     * A body that is an undiscriminated union of classes is an interface too, and nothing in the
     * payload names the member: its FormRequest tries every member's rules and schema checks, and
     * takes the member the `oneOf` / `anyOf` rule leaves.
     *
     * @param list<string> $members
     */
    private function renderLaravelUnionMembersFormRequestClass(string $namespace, string $dtoClassName, array $members, bool $exactlyOne): string
    {
        $imports = [];
        $this->importLibraryClass(fqcn: 'Illuminate\Foundation\Http\FormRequest', namespace: $namespace, useStatements: $imports);
        $this->importLibraryClass(fqcn: 'Illuminate\Validation\Validator', namespace: $namespace, useStatements: $imports);
        $this->importLibraryClass(fqcn: 'LogicException', namespace: $namespace, useStatements: $imports);
        $memberNames = [];
        foreach ($members as $member) {
            $this->appendImportForClass($imports, $member, $namespace, $dtoClassName . 'FormRequest');
            $memberNames[] = $this->shortClassName($member);
        }
        $imports = array_values(array_unique($imports));
        sort($imports);

        return $this->renderPhpTemplate('formrequest.laravel-members.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $dtoClassName . 'FormRequest',
            'dtoClassName' => $dtoClassName,
            'members' => $memberNames,
            'exactlyOne' => $exactlyOne,
            'sourceEndpoint' => $this->endpointByClass[$dtoClassName] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($dtoClassName),
        ]);
    }

    /**
     * A FormRequest is emitted for the classes that describe an INCOMING payload — a request body or an
     * operation's parameters — and for nothing else: a response DTO or a shared component has no
     * request to validate.
     */
    private function laravelEmitsFormRequestFor(string $className): bool
    {
        return $this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL
            && $this->laravelFormRequest
            && array_key_exists($className, $this->requestPayloadClasses)
            && (
                ($this->laravelClassesWithInterpreter[$className] ?? null) !== null
                || $this->laravelHasVariantFormRequest($className)
                || $this->laravelUnionBodyMembers($className) !== null
            );
    }

    /**
     * The schema a `$ref` union branch names, for the interpreter: an enum as its members, an object
     * as its class's checks. Resolved by class name — the branch may sit in another file, whose
     * relative references mean nothing against the root document. Anything else stays unspelled,
     * and the union is left to the rules as before.
     *
     * @return array<string, mixed>
     */
    private function laravelSpelledOutRefBranch(string $ref): array
    {
        $className = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
        if (array_key_exists($className, $this->enumSchemas)) {
            return [
                'type' => $this->enumSchemas[$className]['type'] === 'int' ? 'integer' : 'string',
                'enum' => array_values($this->enumSchemas[$className]['values']),
            ];
        }
        if (!array_key_exists($className, $this->dtoSchemas)) {
            return [];
        }
        // A union component as one branch of a wider union (`oneOf: [$ref Shape, string]`) is its fold,
        // pointed at the way a bare `$ref` to it is. Spelled as nothing, the branch matched everything,
        // the whole union was pruned and the rules said only `sometimes`: `5` reached the constructor
        // as a TypeError, and two matching members of a `oneOf` went through.
        if ($this->laravelDiscriminatorBaseIsInterface($className)) {
            $this->laravelRecordRecursiveFold($className);

            return ['x-openapi-recurse' => $className];
        }

        return ['type' => 'object', ...$this->laravelInterpreterSchemaForClass(
            className: $className,
            visitedClasses: [$className],
            insideRecursiveFold: true,
        )];
    }

    /**
     * `array<array<string, V>>` — a list whose members are maps, so each member has to be cast on the
     * way out or an empty one encodes as `[]` (the same defect section 12 fixed in runtime mode).
     */
    private function laravelDescribesListOfMaps(string $phpType): bool
    {
        return preg_match('/^\??array<\??array<string, /', $phpType) === 1;
    }

    /**
     * Whether the class needs the deep map-to-object cast helper.
     *
     * @param array<int, SchemaProperty> $properties
     */
    private function laravelNeedsJsonObjectHelper(array $properties): bool
    {
        foreach ($properties as $property) {
            if (($property['isMap'] ?? null) === true || $this->laravelDescribesListOfMaps($property['type']) || $this->laravelObjectItemWriter($property) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The expression emitted where a payload would have to become an undiscriminated union member.
     *
     * It throws, and it says the same thing the generation-time warning said — which is the point. The
     * previous expression called a factory the interface does not declare, so the request died on
     * `Error: Call to undefined method X::fromValidated()`: a PHP-level accident naming an internal
     * artefact, for a document-level limitation the generator already knew about and had reported.
     */
    private function laravelUnhydratableUnionThrow(string $unionClass, string $wireName): string
    {
        return sprintf(
            'throw new \RuntimeException(%s)',
            $this->phpStringLiteral(sprintf(
                'Property "%s" is typed as %s, a union with no discriminator: the document does not say '
                    . 'which member a payload is, so it cannot be hydrated. Add a discriminator to %s, or '
                    . 'type the property as one member.',
                $wireName,
                $this->shortClassName($unionClass),
                $this->shortClassName($unionClass),
            )),
        );
    }

    /**
     * How a nested DTO value is built.
     *
     * A discriminated union is an INTERFACE here, so there is no `fromValidated()` to call: the payload
     * decides which member it is, exactly as the runtime deserializer and Symfony's `#[DiscriminatorMap]`
     * do. Without this the property came out empty and the data was silently lost — the normalization
     * parity suite caught it.
     */
    private function laravelNestedDtoExpression(string $dtoClass, string $rawAccess, string $wireName): string
    {
        // A union COMPONENT of `$ref` classes (or of unions of them) with no discriminator — a list item,
        // a map value — is built as the first member whose own schema the value matches, as a property of
        // that type is; the validator has already decided one does. It used to throw below, a 500 for every valid item.
        $members = $this->laravelUnionComponentMembers($dtoClass);
        if ($members !== []) {
            $lastMember = array_pop($members);
            $arms = [];
            foreach ($members as $memberClass) {
                $arms[] = sprintf(
                    '%s => %s::fromValidated(%s)',
                    $this->laravelUnionMemberMatchCondition($memberClass, $rawAccess),
                    $this->shortClassName($memberClass),
                    $rawAccess,
                );
            }
            $arms[] = sprintf('default => %s::fromValidated(%s)', $this->shortClassName($lastMember), $rawAccess);

            return sprintf('match (true) { %s }', implode(', ', $arms));
        }

        // A union of objects with no discriminator has no member to choose, so there is nothing to call.
        // Emitting `Interface::fromValidated()` anyway is what produced `Error: Call to undefined method`
        // at request time, for a payload the document allows and the generator had already warned about.
        if ($this->isUnhydratableUnionClass($dtoClass)) {
            return $this->laravelUnhydratableUnionThrow($dtoClass, $wireName);
        }

        // A plain `type: object` base with a discriminator is a class of its own and hydrates itself;
        // only a union base has no `fromValidated()` to call.
        if (
            !array_key_exists($dtoClass, $this->discriminatorSchemas)
            || !$this->laravelDiscriminatorBaseIsInterface($dtoClass)
        ) {
            return sprintf('%s::fromValidated(%s)', $this->shortClassName($dtoClass), $rawAccess);
        }

        $discriminator = $this->discriminatorSchemas[$dtoClass];
        $mapping = $discriminator['mapping'];
        if ($mapping === []) {
            return sprintf('%s::fromValidated(%s)', $this->shortClassName($dtoClass), $rawAccess);
        }

        return $this->laravelDiscriminatorMatch($rawAccess, $discriminator['propertyName'], $mapping);
    }

    /**
     * The variant a payload's discriminator names, built from that payload.
     *
     * @param array<array-key, string> $mapping discriminator value => class; a numeric value is an int key
     */
    private function laravelDiscriminatorMatch(string $rawAccess, string $propertyName, array $mapping): string
    {
        $arms = [];
        foreach ($mapping as $discriminatorValue => $targetClass) {
            $arms[] = sprintf(
                '%s => %s::fromValidated(%s)',
                $this->phpStringLiteral((string)$discriminatorValue),
                $this->shortClassName($targetClass),
                $rawAccess,
            );
        }

        return sprintf(
            'match (%s[%s] ?? null) { %s, default => throw new \InvalidArgumentException(sprintf(\'Unknown %s "%%s".\', (string)(%s[%s] ?? \'\'))) }',
            $rawAccess,
            $this->phpStringLiteral($propertyName),
            implode(', ', $arms),
            // Inside the single-quoted sprintf format: quote-escaped, and `%` doubled so a name like
            // `rate%` stays text instead of becoming a conversion sprintf has no argument for.
            str_replace('%', '%%', $this->escapeSingleQuoted($propertyName)),
            $rawAccess,
            $this->phpStringLiteral($propertyName),
        );
    }

    /**
     * Whether a declared type can hold an int, and therefore may receive a JSON `42.0` that the spec
     * counts as an integer. A `float` type needs no coercion — it accepts the value as it is.
     */
    private function laravelPropertyAcceptsInt(string $declaredType): bool
    {
        $members = explode('|', str_replace('?', '', $declaredType));

        return in_array('int', $members, true) && !in_array('float', $members, true);
    }

    /**
     * The one scalar a declared type holds besides null — `int`, `float` or `bool` — or null for
     * anything else, a union included.
     */
    private function laravelScalarCoercion(string $declaredType): ?string
    {
        $members = array_values(array_diff(explode('|', str_replace('?', '', $declaredType)), ['null']));

        return count($members) === 1 && in_array($members[0], ['int', 'float', 'bool'], true) ? $members[0] : null;
    }

    /**
     * The same answer for the ITEMS of a one-level list or map of one scalar type — `array<int>`,
     * `array<string, ?bool>` — or null for anything else.
     */
    private function laravelItemScalarCoercion(string $phpType): ?string
    {
        if (preg_match('/^\??array<(?:(?:int|string),\s*)?(\??[a-z]+)>$/', $phpType, $match) !== 1) {
            return null;
        }

        return $this->laravelScalarCoercion($match[1]);
    }

    /**
     * The scalar coercion helpers the class's hydrator calls.
     *
     * @param array<int, array<string, mixed>> $params
     * @return array<int, string>
     */
    private function laravelScalarCoercionHelpers(array $params): array
    {
        $kinds = [];
        foreach ($params as $param) {
            foreach ([$param['scalarCoercion'] ?? null, $param['itemScalarCoercion'] ?? null] as $kind) {
                if (is_string($kind) && !in_array($kind, $kinds, true)) {
                    $kinds[] = $kind;
                }
            }
        }

        return $kinds;
    }

    /**
     * Whether any property of the class can receive a JSON integer, and therefore needs the coercion
     * helper emitted.
     *
     * @param array<int, array<string, mixed>> $params
     */
    private function laravelNeedsIntCoercionHelper(array $params): bool
    {
        foreach ($params as $param) {
            if (($param['needsIntCoercion'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }
}
