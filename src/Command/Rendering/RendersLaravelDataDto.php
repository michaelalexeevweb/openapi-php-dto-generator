<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use RuntimeException;

/**
 * laravel-data-mode emitter: ONE `spatie/laravel-data` class per schema, instead of the FormRequest +
 * DTO pair the first-party Laravel mode emits.
 *
 *     $dto = UserPostRequestData::from($request);   // validates AND hydrates
 *
 * This is the only mode whose output needs a third-party package installed, which is why it is opt-in.
 * What it buys is presence tracking as a language-level fact rather than emitted bookkeeping: an
 * optional property is `string|Optional`, an unprovided one IS an `Optional` instance, and laravel-data
 * omits it from `toArray()` on its own. No flag array, no `fromValidated()` factory, no hydration code
 * of ours — its `from()` pipeline does the casting and the nesting.
 *
 * Two facts about the package shape everything below, both measured in `LaravelDataSemanticsTest`:
 *
 * - `rules()` OVERRIDES laravel-data's own rule inferrers for every property it mentions, so the class
 *   carries NO `#[MergeValidationRules]`. With that attribute the inferred rules are merged in — the
 *   same duplicate messages and the same spurious `nullable` that were real bugs in Laravel mode.
 * - `withValidator()` runs on the ROOT object only and receives the validator alone. Laravel mode gets
 *   the raw body as a second argument; here it has to be fetched from the request, which is also the
 *   only place the wire shape (`type: object` versus `type: array`) still exists.
 *
 * The rule translator and the interpreter are NOT reimplemented: `laravelRulesForProperty()`,
 * `laravelNestedRules()`, `laravelItemRulesForProperty()` and `renderLaravelInterpreterBlock()` come
 * from `RendersLaravelValidation` and produce exactly the same rule array and the same messages, which is what
 * lets the parity suites hold both modes to one expectation.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 */
trait RendersLaravelDataDto
{
    /**
     * `mixed` cannot take part in a union type, so a free-form property has no room for `|Optional` and
     * its presence is not observable. The alternative — narrowing the property to something `Optional`
     * fits into — would refuse payloads the schema allows, which is worse.
     */
    private const string LARAVEL_DATA_UNTYPED = 'mixed';

    /**
     * The classes a discriminated union can be reached from, see `laravelDataMorphReach()`; null until
     * asked, reset with every generation.
     *
     * @var array<string, true>|null
     */
    private ?array $laravelDataMorphReachCache = null;

    /**
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $unionTypes
     * @param array<string, mixed>|null $discriminator
     */
    private function renderLaravelDataDtoClass(
        string $namespace,
        string $className,
        array $properties,
        array $unionTypes,
        ?array $discriminator,
        bool $isAbstract = false,
    ): string {
        // A DISCRIMINATED union is the one place this mode does NOT follow the others. They emit an
        // interface, and laravel-data cannot hydrate one — it has a first-class mechanism instead: an
        // abstract `Data` base implementing `PropertyMorphableData`, whose `morph()` picks the member
        // class from the discriminator value. So the base is an abstract class here and the members
        // `extends` it.
        if (($isAbstract && is_array($discriminator)) || $this->laravelIsAllOfDiscriminatorBase($className)) {
            return $this->renderLaravelDataMorphBase($namespace, $className);
        }

        // A union WITHOUT a discriminator has nothing to switch on, so it stays the interface the other
        // modes emit. Nothing hydrates such a property automatically in any mode.
        if ($unionTypes !== []) {
            return $this->renderLaravelDataUnionInterface($namespace, $className, $unionTypes, $discriminator);
        }

        // A member of a discriminated union extends its abstract base, which already declares the
        // discriminator property. Redeclaring a readonly property in a child is a fatal, so the member
        // takes it as a plain constructor parameter and forwards it.
        // Unless the base is an `allOf` one that declares its properties for the members to redeclare —
        // see `laravelDataMorphBaseProperties()`.
        $morphBase = $this->laravelDataMorphBaseFor($className);
        $morphDiscriminator = $morphBase === null || $this->laravelDataMorphBaseProperties($morphBase, $namespace) !== null
            ? null
            : ($this->discriminatorSchemas[$morphBase]['propertyName'] ?? null);

        $useStatements = [];
        $dataBase = $morphBase === null
            ? $this->libraryClassRef('Spatie\LaravelData\Data', $namespace, $useStatements)
            : null;
        $params = [];
        $rules = [];
        $objectShapePaths = [];
        $scalarTypePaths = [];
        $nestedChildren = [];
        $parentArguments = [];
        $inputAliases = [];
        // A parameter class is read from a query string or a route, where nothing is a JSON boolean.
        $checksScalarPaths = !array_key_exists($className, $this->parameterPayloadClasses);

        foreach ($properties as $property) {
            $param = $this->resolveLaravelDataParam($property, $namespace);
            if ($property['openApiName'] !== $property['name']) {
                $inputAliases[$property['openApiName']] = $property['name'];
            }
            if ($morphBase !== null && $morphDiscriminator !== null && $property['openApiName'] === $morphDiscriminator) {
                $param['promoted'] = false;
                // laravel-data fills the property the BASE declares, so it hands this parameter the
                // base's type: a member typing its one-value `enum` tag as its own enum got the raw
                // string from the payload and died in the constructor, a 500 on every valid payload.
                // Its rule still holds the value to the member's tag.
                $param['declaredType'] = $this->laravelDataMorphDiscriminatorType(
                    baseClass: $morphBase,
                    namespace: $namespace,
                    useStatements: $useStatements,
                );
                $param['parameterType'] = $param['declaredType'];
                $parentArguments[] = $param['name'];
            }
            $params[] = $param;

            $rules[] = [
                'path' => $property['openApiName'],
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
            // Only the numeric paths: a boolean path holding a boolean is what the schema asks for.
            $scalarCandidates = $checksScalarPaths ? $this->laravelScalarTypePaths($property, '', [$className], recurse: $child === null) : [];
            foreach ($scalarCandidates as $scalarPath => $scalarType) {
                if ($scalarType !== 'boolean') {
                    $scalarTypePaths[$scalarPath] = $scalarType;
                }
            }

            foreach ($this->laravelImportsForProperty($property, $namespace, $className) as $import) {
                // Laravel mode imports it for the `match` in its own hydrator, which throws on an
                // unmapped discriminator value. This mode emits no hydrator — laravel-data resolves the
                // morph — so the same import would sit in the file unused.
                if ($import === 'InvalidArgumentException') {
                    continue;
                }

                $useStatements[] = $import;
            }
            foreach ($param['attributeImports'] as $attributeImport) {
                $useStatements[] = $attributeImport;
            }
        }

        $objectShapePaths = array_values(array_unique($objectShapePaths));
        // The numeric paths are checked for a JSON boolean, which Laravel's `integer` rule passes as 1 and
        // laravel-data then casts or stores as it came. A numeric string stays accepted: that leniency is
        // this mode's documented one, and refusing it would need the raw body Laravel mode reads.
        $nestedVars = $this->laravelNestedTemplateVars(
            className: $className,
            nestedChildren: $nestedChildren,
            objectPaths: $objectShapePaths,
            scalarPaths: $scalarTypePaths,
            checksScalarPaths: $checksScalarPaths,
        );

        // A wire name PHP reads as an integer key — `2` — loses itself on the way out: laravel-data
        // `array_merge()`s the transformed array with the additional data, which renumbers integer keys,
        // and `{"2":"view","4":"edit"}` came out `["view","edit"]`. The class puts the names back in its
        // own `transform()`. A `writeOnly` property is `#[Hidden]` and never written, so it takes no slot.
        $integerWireNames = [];
        foreach ($properties as $property) {
            $wireName = $property['openApiName'];
            if ((string)(int)$wireName === $wireName && ($property['writeOnly'] ?? null) !== true) {
                $integerWireNames[$property['name']] = $wireName;
            }
        }
        $wireNameRestore = null;
        if ($integerWireNames !== []) {
            $wireNameRestore = [
                'namesLiteral' => $this->renderPhpArrayLiteral($integerWireNames, 2),
                'contextRef' => $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Support\Transformation\TransformationContext',
                    namespace: $namespace,
                    useStatements: $useStatements,
                ),
                'contextFactoryRef' => $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Support\Transformation\TransformationContextFactory',
                    namespace: $namespace,
                    useStatements: $useStatements,
                ),
                'optionalRef' => $this->libraryClassRef('Spatie\LaravelData\Optional', $namespace, $useStatements),
                'reflectionRef' => $this->libraryClassRef('ReflectionObject', $namespace, $useStatements),
                'propertyRef' => $this->libraryClassRef('ReflectionProperty', $namespace, $useStatements),
            ];
        }

        // An EMPTY object goes out as `[]`: laravel-data transforms a `Data` into a PHP array, and an
        // empty one cannot say it was an object — `{"owl":{}}` came back `{"owl":[]}`, and a root with
        // nothing set as `[]`. The class puts a `\stdClass` back in its own `transform()` where a nested
        // `Data` came out empty, and in `toJson()` / `toResponse()` where it is empty itself; Laravel
        // mode writes `(object)[]` in the same places.
        $emptyObjectWrite = $this->laravelDataEmptyObjectWrite(
            properties: $properties,
            namespace: $namespace,
            useStatements: $useStatements,
        );
        if ($wireNameRestore === null && $emptyObjectWrite['writesNested']) {
            $wireNameRestore = [
                'namesLiteral' => null,
                'contextRef' => $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Support\Transformation\TransformationContext',
                    namespace: $namespace,
                    useStatements: $useStatements,
                ),
                'contextFactoryRef' => $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Support\Transformation\TransformationContextFactory',
                    namespace: $namespace,
                    useStatements: $useStatements,
                ),
                'optionalRef' => null,
                'reflectionRef' => null,
                'propertyRef' => null,
            ];
        }

        // A date beside a non-string scalar is cast only when a string arrives — see the template.
        // A date beside a string enum (`BoxA|DateTimeImmutable`) is cast only when a string arrives
        // that is no case of the enum: the constructor turns the case into the enum, and the date
        // cast threw `CannotCastDate` on `red` — and, without the formats, on every date too.
        $temporalUnionNames = [];
        $enumBesideDateEntries = [];
        foreach ($properties as $property) {
            $enumsBesideDate = $this->laravelStringEnumsBesideDate($property['type']);
            if ($this->isTemporalScalarUnion($property['type']) || $enumsBesideDate !== []) {
                $temporalUnionNames[] = $property['name'];
            }
            if ($enumsBesideDate !== []) {
                $enumBesideDateEntries[] = sprintf(
                    '%s => [%s]',
                    $this->phpStringLiteral($property['name']),
                    implode(', ', array_map(static fn(string $enum): string => $enum . '::class', $enumsBesideDate)),
                );
            }
        }
        // A union of classes (`Circle|Oval`) is built by the constructor, as the member the value
        // matches. laravel-data's cast pipe otherwise builds the FIRST class from every value, and a
        // member's own enum cast threw `CannotCastEnum` on the other member's tag — a 500.
        // Beside a date (`DateTimeImmutable|Olive|Teal`) the date list above already keeps every
        // non-string away from the cast, and the string a date arrives as still needs it: left out
        // of the cast altogether, the raw string reached the constructor, a `TypeError` 500.
        // ONE class beside a plain `array` (`array|Heron`) is built by the constructor too, and only
        // from the object the class accepts: the pipe built Heron from every object, so `{"yak": 1,
        // "egret": "x"}` the free-form member takes died on Heron's `int $egret`, and `{"yak": 1,
        // "llama": 3}` came back as Heron's `{"llama": "3"}`.
        $uncastUnionNames = [];
        foreach ($properties as $property) {
            if (in_array($property['name'], $temporalUnionNames, true)) {
                continue;
            }
            if (
                $this->laravelUndiscriminatedUnionClasses($property) !== []
                || $this->laravelInlineDiscriminator($property) !== null
                || ($this->unionHasArrayMember($property['type']) && $this->laravelDataUnionClassToBuild($property['type']) !== null)
            ) {
                $uncastUnionNames[] = $property['name'];
            }
        }
        $temporalUnionCast = null;
        if ($temporalUnionNames !== [] || $uncastUnionNames !== []) {
            $temporalUnionCast = [
                'namesLiteral' => $temporalUnionNames === []
                    ? null
                    : '[' . implode(', ', array_map($this->phpStringLiteral(...), $temporalUnionNames)) . ']',
                'uncastLiteral' => $uncastUnionNames === []
                    ? null
                    : '[' . implode(', ', array_map($this->phpStringLiteral(...), $uncastUnionNames)) . ']',
                'enumsLiteral' => $enumBesideDateEntries === [] ? null : '[' . implode(', ', $enumBesideDateEntries) . ']',
                'pipelineRef' => $this->libraryClassRef('Spatie\LaravelData\DataPipeline', $namespace, $useStatements),
                'castPipeRef' => $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\DataPipes\CastPropertiesDataPipe',
                    namespace: $namespace,
                    useStatements: $useStatements,
                ),
                'containerRef' => $this->libraryClassRef('Illuminate\Container\Container', $namespace, $useStatements),
                'configRef' => $this->libraryClassRef('Spatie\LaravelData\Support\DataConfig', $namespace, $useStatements),
                'propertyRef' => $this->libraryClassRef('Spatie\LaravelData\Support\DataProperty', $namespace, $useStatements),
            ];
        }

        // laravel-data writes `toArray()` in the order the PROPERTIES are declared, and a promoted property
        // is declared where its parameter is — required ones first, as PHP needs. Where that differs from
        // the schema's order, the properties are declared above the constructor in the schema's order
        // instead: `{success, payload, message}` came out for a schema declaring `success, message,
        // payload`, where the other modes keep the schema's order.
        $schemaOrder = array_column($this->inSchemaOrder($className, $params), 'name');
        $constructorOrder = [
            ...array_column(array_filter($params, static fn(array $param): bool => $param['required']), 'name'),
            ...array_column(array_filter($params, static fn(array $param): bool => !$param['required']), 'name'),
        ];
        // A property whose constructor picks its enum case is declared apart from the constructor whatever
        // the order; a nullable one with a schema default then stays promoted — see `nullableWithDefault`.
        $declaresProperties = array_filter($params, static fn(array $param): bool => $param['assignment'] !== null) !== []
            || ($schemaOrder !== $constructorOrder
                && array_filter($params, static fn(array $param): bool => $param['nullableWithDefault']) === []);

        $interpreterConstraints = $this->laravelInterpreterConstraints($properties, $className);
        $recursiveSchemas = array_filter($this->laravelRecursiveFolds, static fn(mixed $fold): bool => $fold !== []);
        $readsEmptyContainers = $this->laravelReadsEmptyContainers(
            className: $className,
            interpreterConstraints: $interpreterConstraints,
            recursiveSchemas: $recursiveSchemas,
        );
        $interpreter = $this->renderLaravelInterpreterBlock(
            constraints: $interpreterConstraints,
            recursiveSchemas: $recursiveSchemas,
            readsRawBody: $readsEmptyContainers,
        );
        // A request body is an object, and only the raw body still says whether `[]` was sent for it.
        $rootIsObject = array_key_exists($className, $this->requestPayloadClasses)
            && !array_key_exists($className, $this->parameterPayloadClasses);
        $hasWithValidator = $interpreter['methods'] !== ''
            || $nestedVars['checksObjectPaths']
            || $nestedVars['checksScalarPaths']
            || $rootIsObject
            || $readsEmptyContainers;

        $validatorRef = $hasWithValidator
            ? $this->libraryClassRef('Illuminate\Validation\Validator', $namespace, $useStatements)
            : null;
        $containerRef = null;
        $requestRef = null;
        $stdClassRef = null;
        if ($nestedVars['checksObjectPaths'] || $rootIsObject || $readsEmptyContainers) {
            $containerRef = $this->libraryClassRef('Illuminate\Container\Container', $namespace, $useStatements);
            $requestRef = $this->libraryClassRef('Illuminate\Http\Request', $namespace, $useStatements);
        }
        if ($nestedVars['checksObjectPaths'] || $readsEmptyContainers) {
            $stdClassRef = $this->libraryClassRef('stdClass', $namespace, $useStatements);
        }

        $implementedInterfaces = [];
        foreach ($this->laravelImplementedUnionInterfaces($className) as $unionInterface) {
            // The discriminated base is `extends`, not `implements` — it is a class in this mode.
            if ($unionInterface === $morphBase) {
                continue;
            }
            $this->appendImportForClass($useStatements, $unionInterface, $namespace, $className);
            $implementedInterfaces[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        $extends = null;
        if ($morphBase !== null) {
            $this->appendImportForClass($useStatements, $morphBase, $namespace, $className);
            $extends = $this->formatClassNameForNamespace($morphBase, $namespace);
        }
        $morphGuard = $this->laravelDataMorphGuard($className, $namespace, $useStatements);

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'extends' => $extends ?? $dataBase,
            'validatorRef' => $validatorRef,
            'containerRef' => $containerRef,
            'requestRef' => $requestRef,
            'stdClassRef' => $stdClassRef,
            'parentArguments' => $parentArguments,
            'declaresProperties' => $declaresProperties,
            'declaredParams' => $this->inSchemaOrder($className, $params),
            'wireNameRestore' => $wireNameRestore,
            'emptyObjectWrite' => $emptyObjectWrite,
            'temporalUnionCast' => $temporalUnionCast,
            'parameterReads' => $this->laravelParameterReads($properties),
            'rulesKeyType' => $this->laravelRulesKeyType(array_column($rules, 'path')),
            'morphBase' => null,
            'implementedInterfaces' => $implementedInterfaces,
            'interpreterConstsBlock' => $interpreter['consts'],
            'hasObjectConstraints' => $interpreterConstraints['object'] !== [],
            'inputAliasesLiteral' => $inputAliases === [] ? null : $this->renderPhpArrayLiteral($inputAliases, 3),
            'interpreterMethodsBlock' => $interpreter['methods'],
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => $params,
            'rules' => $rules,
            'objectShapePaths' => $objectShapePaths,
            'scalarTypePaths' => $scalarTypePaths,
            'morphGuard' => $morphGuard,
            ...$nestedVars,
            'rootIsObject' => $rootIsObject,
            'readsEmptyContainers' => $readsEmptyContainers,
            'readsRawJsonShapes' => $interpreter['readsRawJsonShapes'],
            'unionMembers' => null,
            'interfaceExtends' => [],
            'discriminatorProperty' => null,
        ]);
    }

    /**
     * The abstract base of a discriminated union: `PropertyMorphableData::morph()` maps the discriminator
     * value to a member class, and `#[PropertyForMorph]` tells laravel-data which property to read before
     * it has an object to read it from.
     *
     * This is the mechanism the other modes have no equivalent of — runtime mode switches inside its own
     * generated hydrator, Symfony mode uses the serializer's discriminator map — so it is also the one
     * place where the emitted class SHAPE differs between modes rather than just its attributes.
     */
    private function renderLaravelDataMorphBase(string $namespace, string $className): string
    {
        // Both halves are guaranteed by the time this runs, so there is no fallback branch here: the
        // caller only reaches it for an abstract schema WITH a discriminator, and a discriminator that
        // names no mapping is refused when the schema is registered ("Discriminator mapping must be a
        // non-empty map in X"). A defensive `if` for either would be unreachable code pretending to be a
        // safety net.
        $discriminator = $this->discriminatorSchemas[$className];
        $propertyName = $discriminator['propertyName'];
        $mapping = $discriminator['mapping'];

        $useStatements = [];
        $morphForRef = $this->libraryClassRef(
            fqcn: 'Spatie\LaravelData\Attributes\PropertyForMorph',
            namespace: $namespace,
            useStatements: $useStatements,
        );
        $morphableRef = $this->libraryClassRef(
            fqcn: 'Spatie\LaravelData\Contracts\PropertyMorphableData',
            namespace: $namespace,
            useStatements: $useStatements,
        );
        $dataBase = $this->libraryClassRef('Spatie\LaravelData\Data', $namespace, $useStatements);

        // The discriminator needs its wire name here as much as any other property, and for one more
        // reason: `DataMorphClassResolver` looks the value up by the property's name and by its INPUT
        // MAPPED name, so without the attribute a `pet_type` payload reaches neither. It then finds no
        // value at all, `morph()` is never asked, and a payload every other mode hydrates comes back as
        // `validation.required` on a property name the document never used.
        $property = $this->normalizePropertyName($propertyName);
        $attributes = [sprintf('#[%s]', $morphForRef)];
        if ($property !== $propertyName) {
            $attributes[] = sprintf(
                '#[%s(%s)]',
                $this->libraryClassRef('Spatie\LaravelData\Attributes\MapName', $namespace, $useStatements),
                $this->phpStringLiteral($propertyName),
            );
        }

        $propertyType = $this->laravelDataMorphDiscriminatorType(
            baseClass: $className,
            namespace: $namespace,
            useStatements: $useStatements,
        );
        $enumTyped = false;
        foreach ($this->getSchemaProperties($className) as $baseProperty) {
            if ($baseProperty['openApiName'] !== $propertyName) {
                continue;
            }
            $enumClass = $this->laravelEnumClass($baseProperty);
            if ($enumClass !== null) {
                $this->appendImportForClass($useStatements, $enumClass, $namespace, $className);
                $enumTyped = true;
            }
        }

        // An `allOf` base declares what its schema declares, so code typed with the base reads the common
        // properties too. The discriminator carries `#[PropertyForMorph]` on its declaration.
        $declaredProperties = $this->laravelDataMorphBaseProperties($className, $namespace);
        if ($declaredProperties !== null) {
            foreach ($this->getSchemaProperties($className) as $baseProperty) {
                foreach ($this->laravelImportsForProperty($baseProperty, $namespace, $className) as $import) {
                    if ($import !== 'InvalidArgumentException') {
                        $useStatements[] = $import;
                    }
                }
            }
            foreach ($declaredProperties as $index => $declared) {
                foreach ($declared['attributeImports'] as $attributeImport) {
                    $useStatements[] = $attributeImport;
                }
                if ($declared['openApiName'] === $propertyName) {
                    $declaredProperties[$index]['attributes'] = [sprintf('#[%s]', $morphForRef), ...$declared['attributes']];
                }
            }
        }

        // `DataMorphClassResolver` casts an enum-typed discriminator to its case before `morph()` sees
        // it, so the match has to read the value back out.
        $enumRef = $enumTyped ? $this->libraryClassRef('BackedEnum', $namespace, $useStatements) : null;

        // laravel-data resolves the member class BEFORE it validates anything, so a root payload with an
        // unknown discriminator never reached a rule: `CannotCreateAbstractClass`, a 500. The base checks
        // the discriminator with the framework's validator first.
        $guard = [
            'arrayableRef' => $this->libraryClassRef('Illuminate\Contracts\Support\Arrayable', $namespace, $useStatements),
            'requestRef' => $this->libraryClassRef('Illuminate\Http\Request', $namespace, $useStatements),
            'ruleRef' => $this->libraryClassRef('Illuminate\Validation\Rule', $namespace, $useStatements),
            'validatorRef' => $this->libraryClassRef('Illuminate\Support\Facades\Validator', $namespace, $useStatements),
            'exceptionRef' => $this->libraryClassRef('Illuminate\Validation\ValidationException', $namespace, $useStatements),
            // `strval`: a numeric discriminator value is an INT key once PHP has the mapping.
            'values' => '[' . implode(', ', array_map(
                callback: $this->phpStringLiteral(...),
                array: array_map('strval', array_keys($mapping)),
            )) . ']',
        ];

        $members = [];
        $reachingMembers = [];
        foreach ($mapping as $discriminatorValue => $memberClass) {
            $this->appendImportForClass($useStatements, $memberClass, $namespace, $className);
            $memberRef = $this->formatClassNameForNamespace($memberClass, $namespace);
            $members[] = [
                'value' => $this->phpStringLiteral((string)$discriminatorValue),
                'class' => $memberRef,
            ];
            if (array_key_exists($memberClass, $this->laravelDataMorphReach())) {
                $reachingMembers[$memberRef] = true;
            }
        }

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'extends' => null,
            'validatorRef' => null,
            'containerRef' => null,
            'requestRef' => null,
            'stdClassRef' => null,
            'parentArguments' => [],
            'morphBase' => [
                'propertyName' => $propertyName,
                'property' => $property,
                // laravel-data reads the discriminator by both names, so the guard looks under both.
                'discriminatorKeys' => array_values(array_unique([$propertyName, $property])),
                'propertyType' => $propertyType,
                'enumRef' => $enumRef,
                'guard' => $guard,
                'attributes' => $attributes,
                'declaredProperties' => $declaredProperties,
                'members' => $members,
                // Only these carry `openApiMorphNodeErrors()` of their own; asked of any other member,
                // the base's static method would resolve back to itself.
                'reachingMembers' => array_keys($reachingMembers),
                'dataBase' => $dataBase,
                'morphable' => $morphableRef,
            ],
            'implementedInterfaces' => [],
            'interpreterConstsBlock' => '',
            'hasObjectConstraints' => false,
            'interpreterMethodsBlock' => '',
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => [],
            'rules' => [],
            'objectShapePaths' => [],
            'nestedChildren' => [],
            'nestedValidation' => null,
            'selfReachable' => false,
            'reachesRecursion' => false,
            'rawPathsSelectMembers' => false,
            'checksObjectPaths' => false,
            'unionMembers' => null,
            'interfaceExtends' => [],
            'discriminatorProperty' => $propertyName,
        ]);
    }

    /**
     * The type a morph base declares its discriminator with: the base's own schema type when it declares
     * the property — a discriminator the base types as an enum is that enum in every member, and
     * `parent::__construct()` handing it to a `string` was a TypeError — and `string` for a union base,
     * which declares no properties of its own.
     *
     * `|Optional` beside it where a member does not require the discriminator: the member is a class of
     * its own too, and used on its own (`wolf: {$ref: Kit}`, or as the root class) it may come without
     * the tag. Its constructor takes `string|Optional $kind = new Optional()` and forwards it, and a
     * plain `string` in the base was a TypeError — a 500 for a payload the member's own schema accepts.
     * Through the base the tag is still required: `from()` checks it before a member is chosen.
     *
     * @param array<int, string> $useStatements
     */
    private function laravelDataMorphDiscriminatorType(string $baseClass, string $namespace, array &$useStatements): string
    {
        $propertyName = $this->discriminatorSchemas[$baseClass]['propertyName'] ?? null;
        $type = 'string';
        foreach ($this->getSchemaProperties($baseClass) as $baseProperty) {
            if ($baseProperty['openApiName'] === $propertyName) {
                $param = $this->resolveLaravelDataParam($baseProperty, $namespace);
                if ($param['tracksPresence']) {
                    // Already `…|Optional`, and imported with the base's own properties.
                    return $param['declaredType'];
                }
                $type = $param['declaredType'];
                break;
            }
        }
        if ($type === self::LARAVEL_DATA_UNTYPED || !$this->laravelDataMorphMemberMayOmitDiscriminator($baseClass)) {
            return $type;
        }

        return $type . '|' . $this->libraryClassRef('Spatie\LaravelData\Optional', $namespace, $useStatements);
    }

    /**
     * Whether a member of the morph base declares the discriminator without requiring it.
     */
    private function laravelDataMorphMemberMayOmitDiscriminator(string $baseClass): bool
    {
        $propertyName = $this->discriminatorSchemas[$baseClass]['propertyName'] ?? null;
        foreach (array_unique(array_values($this->discriminatorSchemas[$baseClass]['mapping'] ?? [])) as $memberClass) {
            foreach ($this->getSchemaProperties($memberClass) as $memberProperty) {
                if ($memberProperty['openApiName'] === $propertyName && !$this->propertyIsRequiredOnInput($memberProperty)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The properties an `allOf` discriminator base declares itself, or null when it keeps the constructor
     * that takes the discriminator alone.
     *
     * Declared, not promoted, and every member redeclares them in the schema's order with its own
     * constructor. A property the base only inherited down was missing from code typed with the base —
     * `$event->uuid` on the abstract class — and PHP lists a subclass's own properties before the
     * inherited ones, so `toArray()` wrote the discriminator last. Redeclaring needs the SAME type in every
     * member, so a property some member types differently stays the members' alone; a discriminator typed
     * differently keeps the old layout for the whole union.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function laravelDataMorphBaseProperties(string $baseClass, string $namespace): ?array
    {
        if (!$this->laravelIsAllOfDiscriminatorBase($baseClass)) {
            return null;
        }

        $memberTypes = [];
        foreach (array_unique(array_values($this->discriminatorSchemas[$baseClass]['mapping'])) as $memberClass) {
            $memberTypes[$memberClass] = [];
            foreach ($this->getSchemaProperties($memberClass) as $memberProperty) {
                $memberTypes[$memberClass][$memberProperty['openApiName']] = $this->resolveLaravelDataParam(
                    property: $memberProperty,
                    namespace: $namespace,
                )['declaredType'];
            }
        }

        $declared = [];
        foreach ($this->getSchemaProperties($baseClass) as $baseProperty) {
            $param = $this->resolveLaravelDataParam($baseProperty, $namespace);
            $shared = array_filter(
                array: $memberTypes,
                callback: static fn(array $types): bool => ($types[$baseProperty['openApiName']] ?? null) !== $param['declaredType'],
            ) === [];
            if ($shared) {
                $declared[] = $param;
                continue;
            }
            if ($baseProperty['openApiName'] === $this->discriminatorSchemas[$baseClass]['propertyName']) {
                return null;
            }
        }

        return $declared;
    }

    /**
     * Whether this class is emitted as an abstract morph base — the shape `renderLaravelDataMorphBase()`
     * writes.
     */
    private function laravelDataIsMorphBase(string $className): bool
    {
        return array_key_exists($className, $this->discriminatorSchemas)
            && $this->laravelDiscriminatorBaseIsInterface($className);
    }

    /**
     * Every concrete class a discriminated union can be reached from through its properties — directly,
     * as list or map items, through another such class, or through a member of a union. A fixpoint
     * rather than a recursion with a visited list: an answer cut short by a cycle would be cached as
     * "no" for a class whose only route runs through the class still being asked.
     *
     * @return array<string, true>
     */
    private function laravelDataMorphReach(): array
    {
        if ($this->laravelDataMorphReachCache !== null) {
            return $this->laravelDataMorphReachCache;
        }

        $reach = [];
        do {
            $grown = false;
            foreach (array_keys($this->dtoSchemas) as $className) {
                if (array_key_exists($className, $reach) || $this->laravelDataIsMorphBase($className)) {
                    continue;
                }
                foreach ($this->getSchemaProperties($className) as $property) {
                    $target = $this->laravelDataMorphStepTarget($property)['class'] ?? null;
                    if ($this->laravelDataRefusesNullItems($property) || ($target !== null && ($this->laravelDataIsMorphBase($target) || array_key_exists($target, $reach)))) {
                        $reach[$className] = true;
                        $grown = true;

                        break;
                    }
                }
            }
        } while ($grown);

        return $this->laravelDataMorphReachCache = $reach;
    }

    /**
     * The generated class a property holds — itself, or as the items of a list or map — when that class
     * is a union base or a concrete class; null for an enum or a scalar.
     *
     * A morph base counts as reaching a union when one of its members does, which is why the members of
     * a base are asked here too.
     *
     * @param SchemaProperty $property
     * @return array{class: string, many: bool}|null
     */
    private function laravelDataMorphStepTarget(array $property): ?array
    {
        $nestedClass = $this->laravelDtoClass($property);
        if ($nestedClass !== null && !$this->laravelIsEnumClass($nestedClass)) {
            return ['class' => $nestedClass, 'many' => false];
        }
        $itemClass = $this->laravelDtoItemClass($property);
        if ($itemClass !== null && !$this->laravelIsEnumClass($itemClass)) {
            return ['class' => $itemClass, 'many' => true];
        }

        // A union of classes written on the property (`Cat|Dog`): laravel-data takes the FIRST data
        // class of the type as the property's class (`DataTypeFactory`, `$dataClass ??=`) and resolves
        // its rules against whatever object arrived — a `Dog` payload included. A discriminated union
        // inside that first member then read its discriminator out of the dog's string `toy`: a
        // `TypeError` in `DataMorphClassResolver`, a 500. Walking the first member refuses that value
        // first. A first member that is itself a union base is left alone: its own check would refuse
        // the other members' payloads outright. It holds where an inline `discriminator` names the
        // member as well: the constructor builds that one, but laravel-data's messages-and-attributes
        // resolver still walks the first data class of the type, whatever the tag says.
        foreach ($this->unionMembersBesideNull($property['type']) as $member) {
            $memberClass = $this->shortClassName($member);
            if (!array_key_exists($memberClass, $this->dtoSchemas) || $this->laravelIsEnumClass($memberClass)) {
                continue;
            }

            return $this->laravelDataIsMorphBase($memberClass) ? null : ['class' => $memberClass, 'many' => false];
        }

        return null;
    }

    /**
     * Whether this property is a list or map of a CONCRETE class whose items admit null
     * (`items: {oneOf: [$ref Fox, null]}`, a `nullable: true` item object). laravel-data's
     * `#[DataCollectionOf(Fox::class)]` builds a `Fox` out of every element and has no null case: a
     * null item was `CannotCreateData` (a 500) when the class requires a property, and an empty `Fox`
     * — accepted, written back as `{}` — when it requires none. The guard refuses the null item before
     * that, as it refuses one of a discriminated union.
     *
     * The items of an undiscriminated union (`items: {$ref: Shade}`, Shade a `oneOf` with a null
     * member) have no `#[DataCollectionOf]`: they stay the decoded arrays, checked by the interpreter,
     * so nothing builds an object out of a null item and the document's null is accepted.
     *
     * @param SchemaProperty $property
     */
    private function laravelDataRefusesNullItems(array $property): bool
    {
        $target = $this->laravelDataMorphStepTarget($property);

        return $target !== null
            && $target['many']
            && !$this->laravelDataIsMorphBase($target['class'])
            && !$this->laravelDataIsUnionInterface($target['class'])
            && $this->laravelItemsNullable($property);
    }

    /**
     * The properties of a concrete class through which a union is reached, or that hold a null item the
     * container cannot build (`laravelDataRefusesNullItems()`), for its `openApiMorphNodeErrors()` walk,
     * and the imports that walk needs. Null when neither is reached.
     *
     * @param array<int, string> $useStatements
     * @return array{steps: list<array{wireName: string, class: string, many: bool, union: bool, recurse: bool, nullItems: bool}>, arrayableRef: string, exceptionRef: string}|null
     */
    private function laravelDataMorphGuard(string $className, string $namespace, array &$useStatements): ?array
    {
        if (!array_key_exists($className, $this->laravelDataMorphReach())) {
            return null;
        }

        $steps = [];
        foreach ($this->getSchemaProperties($className) as $property) {
            $target = $this->laravelDataMorphStepTarget($property);
            if ($target === null) {
                continue;
            }
            $union = $this->laravelDataIsMorphBase($target['class']);
            $recurse = !$union && array_key_exists($target['class'], $this->laravelDataMorphReach());
            $nullItems = $this->laravelDataRefusesNullItems($property);
            if (!$union && !$recurse && !$nullItems) {
                continue;
            }
            if ($union || $recurse) {
                $this->appendImportForClass($useStatements, $target['class'], $namespace, $className);
            }
            $steps[] = [
                'wireName' => $property['openApiName'],
                'class' => $this->formatClassNameForNamespace($target['class'], $namespace),
                'many' => $target['many'],
                'union' => $union,
                'recurse' => $recurse,
                'nullItems' => $nullItems,
            ];
        }

        return [
            'steps' => $steps,
            'arrayableRef' => $this->libraryClassRef('Illuminate\Contracts\Support\Arrayable', $namespace, $useStatements),
            'exceptionRef' => $this->libraryClassRef('Illuminate\Validation\ValidationException', $namespace, $useStatements),
        ];
    }

    /**
     * The discriminated union base this class is a member of, or null.
     */
    private function laravelDataMorphBaseFor(string $className): ?string
    {
        $bases = [];
        foreach ($this->discriminatorSchemas as $baseClass => $discriminator) {
            if (!$this->laravelDiscriminatorBaseIsInterface($baseClass)) {
                // A discriminator on a plain object schema already uses ordinary inheritance.
                continue;
            }
            if (in_array($className, array_values($discriminator['mapping']), true)) {
                $bases[] = $baseClass;
            }
        }
        if (count($bases) > 1) {
            throw new RuntimeException(
                message: 'Laravel-data member ' . $className . ' belongs to multiple morph bases: '
                . implode(', ', $bases) . '. PHP cannot extend multiple classes; use a separate member schema for each union.',
            );
        }

        return $bases[0] ?? null;
    }

    /**
     * @param array<int, string> $unionTypes
     * @param array<string, mixed>|null $discriminator
     */
    private function renderLaravelDataUnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
    ): string {
        $interfaceExtends = [];
        foreach ($this->implementedUnionInterfaces($className, null) as $unionInterface) {
            $interfaceExtends[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => [],
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'extends' => null,
            'validatorRef' => null,
            'containerRef' => null,
            'requestRef' => null,
            'stdClassRef' => null,
            'parentArguments' => [],
            'morphBase' => null,
            'implementedInterfaces' => [],
            'interpreterConstsBlock' => '',
            'interpreterMethodsBlock' => '',
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => [],
            'rules' => [],
            'objectShapePaths' => [],
            'nestedChildren' => [],
            'nestedValidation' => null,
            'selfReachable' => false,
            'reachesRecursion' => false,
            'rawPathsSelectMembers' => false,
            'checksObjectPaths' => false,
            'unionMembers' => $unionTypes,
            'interfaceExtends' => $interfaceExtends,
            'discriminatorProperty' => is_array($discriminator) && is_string($discriminator['propertyName'] ?? null)
                ? $discriminator['propertyName']
                : null,
        ]);
    }

    /**
     * A promoted `public readonly` parameter, plus the attributes laravel-data needs to hydrate and
     * normalize it.
     *
     * @param SchemaProperty $property
     * @return array{declaredType: string, propertyType: string, assignment: ?string, docType: ?string,
     *     name: string, required: bool, openApiName: string, docDescription: ?string, attributes: array<int, string>,
     *     attributeImports: array<int, string>, tracksPresence: bool, nullableWithDefault: bool,
     *     defaultExpression: ?string, promoted: bool}
     */
    private function resolveLaravelDataParam(array $property, string $namespace): array
    {
        $phpType = $property['type'];
        $docType = null;

        if (str_contains($phpType, '<')) {
            $docType = $this->formatDocblockTypeForNamespace($phpType, $namespace);
            $phpType = 'array';
        } else {
            $phpType = $this->formatPhpTypeForNamespace($phpType, $namespace);
        }

        // What the ITEMS of a temporal container really are here: strings.
        //
        // `#[WithCast]` casts the PROPERTY, not the items — measured, not assumed: with the attribute on
        // an `array` property, `['2020-01-01']` arrives as `['2020-01-01']`. laravel-data has no built-in
        // per-item date cast (`#[DataCollectionOf]` wants a `Data` class), so converting would mean
        // emitting a `Cast` class of ours into a mode whose generated code currently depends on NOTHING
        // of this package. The declaration is made honest instead, and the divergence from runtime,
        // Symfony and Laravel modes is stated in the support matrix.
        if ($docType !== null && $this->propertyHasTemporalItems($property)) {
            $docType = str_replace('DateTimeImmutable', 'string', $docType);
        }

        $attributes = [];
        $attributeImports = [];

        // The wire name in both directions from one attribute: MapName defaults its output to its input.
        if ($property['openApiName'] !== $property['name']) {
            $attributes[] = sprintf(
                '#[%s(%s)]',
                $this->libraryClassRef('Spatie\LaravelData\Attributes\MapName', $namespace, $attributeImports),
                $this->phpStringLiteral($property['openApiName']),
            );
        }

        // An ENUM item is not a `Data` class, and `#[DataCollectionOf]` on one fails at hydration with
        // "does not implement BaseData::class". laravel-data casts a backed enum through its own
        // EnumCast from the docblock item type, so the attribute is only for nested DTOs.
        // `writeOnly` means the value goes IN and never comes back out, which is exactly what
        // `#[Hidden]` says: hydrated from the payload, excluded from `toArray()`. Runtime mode drops it
        // unconditionally too; Symfony mode can only do it through serialization groups.
        if (($property['writeOnly'] ?? null) === true) {
            $attributes[] = sprintf(
                '#[%s]',
                $this->libraryClassRef('Spatie\LaravelData\Attributes\Hidden', $namespace, $attributeImports),
            );
        }

        $itemClass = $this->laravelDtoItemClass($property);
        $enumItemClass = null;
        if ($itemClass !== null && $this->laravelIsEnumClass($itemClass)) {
            $enumItemClass = $itemClass;
            $itemClass = null;
        }
        // Nor is a union with no discriminator, which stays an interface: spatie resolves the attribute
        // while it builds the class's config, so every request to the holder was a `CannotFindDataClass`
        // 500, `{}` included. The items stay the decoded arrays, checked by the emitted interpreter.
        if ($itemClass !== null && $this->laravelDataIsUnionInterface($itemClass)) {
            $itemClass = null;
        }

        if ($this->laravelDataSuppressesInferredNestedRules($property, $itemClass)) {
            $attributes[] = sprintf(
                '#[%s]',
                $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Attributes\WithoutValidation',
                    namespace: $namespace,
                    useStatements: $attributeImports,
                ),
            );
        }

        if ($itemClass !== null) {
            $attributes[] = sprintf(
                '#[%s(%s::class)]',
                $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Attributes\DataCollectionOf',
                    namespace: $namespace,
                    useStatements: $attributeImports,
                ),
                $this->formatClassNameForNamespace($itemClass, $namespace),
            );
        }

        // A path parameter is not in `$request->all()`, which is all `Data::from($request)` reads: the
        // `present` rule failed on every request. laravel-data's own attribute brings it in from the
        // route before the rules run.
        if (($property['inPath'] ?? null) === true) {
            $attributes[] = sprintf(
                '#[%s(%s)]',
                $this->libraryClassRef(
                    fqcn: 'Spatie\LaravelData\Attributes\FromRouteParameter',
                    namespace: $namespace,
                    useStatements: $attributeImports,
                ),
                $this->phpStringLiteral($property['openApiName']),
            );
        }

        foreach ($this->laravelDataTemporalAttributes($property, $namespace) as $temporal) {
            $attributes[] = $temporal['source'];
            foreach ($temporal['imports'] as $temporalImport) {
                $attributeImports[] = $temporalImport;
            }
        }

        // `$property['nullable']` is the PHP type's nullability, which the walker sets for every
        // optional property — see `laravelSchemaDeclaresNullable()`. Here `null` and `Optional` are
        // separate union members, so only the document's answer will do.
        $nullable = $this->laravelSchemaDeclaresNullable($property);

        // A readOnly property is required of a RESPONSE only, so on the way IN it is `Optional` like
        // any other absent key — the `Optional` union member is this mode's answer to what runtime
        // mode spells `UnsetValue`.
        $requiredOnInput = $this->propertyIsRequiredOnInput($property);

        // A schema default is the constructor's default, and laravel-data takes that for an absent key —
        // as runtime, Symfony and Laravel mode do. The property then always holds a value, so it is not
        // `Optional`: absent was reported as `Optional` and the default had to be applied by hand.
        $schemaDefault = null;
        $default = $property['default'] ?? null;
        if (!$requiredOnInput && $default !== null && $phpType !== self::LARAVEL_DATA_UNTYPED) {
            $plainType = $this->laravelDataDeclaredType($phpType, required: true, nullable: $nullable)[0];
            $literal = $this->renderDefaultValue($default, ltrim($phpType, '?'), $plainType);
            $schemaDefault = $literal === '' ? null : substr($literal, strlen(' = '));
        }
        $typedAsRequired = $requiredOnInput || $schemaDefault !== null;

        // Resolved before the type is composed: `Optional` is spelled INTO the union, so a document with
        // a schema of that name has to get the fully qualified spelling in the type itself.
        $optionalRef = $typedAsRequired
            ? 'Optional'
            : $this->libraryClassRef('Spatie\LaravelData\Optional', $namespace, $attributeImports);

        [$declaredType, $tracksPresence] = $this->laravelDataDeclaredType(
            phpType: $phpType,
            required: $typedAsRequired,
            nullable: $nullable,
            optionalRef: $optionalRef,
        );

        // A scalar union with an enum member — `Tone|string|int` — is the enum case when the value is one
        // of its members and the scalar otherwise, as in Laravel mode. laravel-data's own EnumCast reads
        // the PROPERTY type and takes every scalar for the enum: `"8812"` died in `Tone::from()`, a 500.
        // Spelled `UnitEnum` on the property, the type is no `BackedEnum` to that cast, and the
        // constructor picks the case itself; `@var` keeps the real union, and the parameter takes the
        // backing value the payload carries as well.
        $variable = '$' . $property['name'];
        $enumCast = $this->laravelWithUnionEnumCast($property['type'], $variable, $variable);

        // The items of a list or map of enum values, likewise: laravel-data casts container items only
        // behind `data.features.cast_and_transform_iterables`, which ships off, so `["warm"]` stayed a
        // list of strings under an `array<Tone>` docblock. A value already a case — the flag on, or a
        // caller's own — passes through.
        if ($enumItemClass !== null) {
            $enumCast = sprintf(
                'is_array(%1$s) ? array_map(static fn(mixed $item): mixed => %2$s($item) ? %3$s::from($item) : $item, %1$s) : %1$s',
                $variable,
                $this->enumSchemas[$enumItemClass]['type'] === 'int' ? 'is_int' : 'is_string',
                $enumItemClass,
            );
        }
        // A `Data` class beside an enum, a date or a list — `Level|Shade`, `DateTimeImmutable|Shade`,
        // `array|Shade` — is a union laravel-data does not cast: the payload array reached the
        // constructor as it came, a TypeError on every valid object. It is built here from the array
        // that only the class can be (beside a list: the array with keys), as the enum gets its case.
        $unionDataClass = $enumItemClass === null ? $this->laravelDataUnionClassToBuild($property['type']) : null;
        if ($unionDataClass !== null) {
            // Beside a free-form object member, only the object the class accepts is the class.
            $classMatch = $this->laravelClassMatchCondition($property, $variable);
            $enumCast = sprintf(
                '%s ? %s : (%s)',
                $this->unionHasArrayMember($property['type'])
                    ? sprintf('is_array(%1$s) && !array_is_list(%1$s)', $variable) . ($classMatch === null ? '' : ' && ' . $classMatch)
                    : sprintf('is_array(%s)', $variable),
                $this->laravelDataBuildExpression(className: $unionDataClass, variable: $variable),
                $enumCast,
            );
        }
        // `Olive|Teal`: laravel-data casts such a union to ONE of its classes whatever the payload says,
        // so `{"dog": 1}` was refused for Olive's `cat`. The member is built here instead, as the first
        // class whose own schema the value matches — the validator has already decided one does.
        $unionDataClasses = $enumItemClass === null && $unionDataClass === null ? $this->laravelUndiscriminatedUnionClasses($property) : [];
        if ($unionDataClasses !== []) {
            $lastDataClass = array_pop($unionDataClasses);
            $arms = [];
            // A union interface among them (`Shape|Disc`) is chosen as a whole, then built as its member.
            foreach ($unionDataClasses as $memberClass) {
                $arms[] = sprintf(
                    '%s => %s',
                    $this->laravelUnionMemberMatchCondition($memberClass, $variable),
                    $this->laravelDataBuildExpression(className: $memberClass, variable: $variable),
                );
            }
            $arms[] = 'default => ' . $this->laravelDataBuildExpression(className: $lastDataClass, variable: $variable);
            $enumCast = sprintf(
                '%s ? match (true) { %s } : (%s)',
                $this->laravelUnionClassesObjectCondition(phpType: $property['type'], valueAccess: $variable),
                implode(', ', $arms),
                $enumCast,
            );
            $unionDataClass = $lastDataClass;
        }
        // `Cat|Dog` with an inline `discriminator`: the tag names the member — the mapping's, else each
        // member's own name. Built by the first member that validates instead, `{"kind": "Dog", "bark":
        // 5}` came out a `Cat` — members that overlap apart from the tag both accept it — and `bark` was
        // dropped. A tag the mapping does not name is refused by the validator first; built without
        // one, it throws as Laravel mode's hydrator does.
        $inlineDiscriminator = $enumItemClass === null && $unionDataClass === null ? $this->laravelInlineDiscriminator($property) : null;
        if ($inlineDiscriminator !== null) {
            $tagAccess = sprintf('%s[%s]', $variable, $this->phpStringLiteral($inlineDiscriminator['propertyName']));
            $arms = [];
            foreach ($inlineDiscriminator['mapping'] as $tagValue => $taggedClass) {
                $arms[] = sprintf('%s => %s::from(%s)', $this->phpStringLiteral((string)$tagValue), $this->shortClassName($taggedClass), $variable);
                // Any member: what matters below is that the parameter takes the payload array.
                $unionDataClass = $this->shortClassName($taggedClass);
            }
            $arms[] = sprintf(
                'default => throw new %s(sprintf(\'Unknown %s "%%s".\', (string)(%s ?? \'\')))',
                $this->libraryClassRef('InvalidArgumentException', $namespace, $attributeImports),
                str_replace('%', '%%', $this->escapeSingleQuoted($inlineDiscriminator['propertyName'])),
                $tagAccess,
            );
            $enumCast = sprintf(
                'is_array(%1$s) ? match (%2$s ?? null) { %3$s } : (%4$s)',
                $variable,
                $tagAccess,
                implode(', ', $arms),
                $enumCast,
            );
        }
        // `Pet|string`, `Pet` the abstract morph base: laravel-data resolves the morph of such a property
        // for its messages whatever `#[WithoutValidation]` says, and `DataMorphClassResolver` died on the
        // string — a TypeError 500 on a valid `"s"`. Spelled `PropertyMorphableData` on the property, it
        // is no `Data` to that walk; the constructor builds the morph from an object, the interpreter has
        // judged it, and `@var` keeps the real union.
        $morphBase = $enumItemClass === null ? $this->laravelDataMorphBesideScalars($property) : null;
        if ($morphBase !== null && $unionDataClass === null) {
            $enumCast = sprintf('is_array(%1$s) ? %2$s::from(%1$s) : (%3$s)', $variable, $morphBase, $enumCast);
            $unionDataClass = $morphBase;
        }
        $propertyType = $declaredType;
        $parameterType = $declaredType;
        if ($enumCast !== $variable) {
            $unitEnumRef = $this->libraryClassRef('UnitEnum', $namespace, $attributeImports);
            $morphableRef = $morphBase === null
                ? null
                : $this->libraryClassRef('Spatie\LaravelData\Contracts\PropertyMorphableData', $namespace, $attributeImports);
            $propertyType = implode('|', array_unique(array_map(
                callback: fn(string $member): string => match (true) {
                    array_key_exists($this->shortClassName($member), $this->enumSchemas) => $unitEnumRef,
                    $morphableRef !== null && $this->shortClassName($member) === $morphBase => $morphableRef,
                    default => $member,
                },
                array: explode('|', $declaredType),
            )));
        }
        if ($enumCast !== $variable && $enumItemClass === null) {
            $parameterType = $this->laravelDataWithEnumBackingTypes($declaredType);
        }
        if ($unionDataClass !== null && !in_array('array', explode('|', $parameterType), true)) {
            $parameterType .= '|array';
        }

        return [
            'declaredType' => $declaredType,
            'propertyType' => $propertyType,
            'parameterType' => $parameterType,
            'assignment' => $enumCast === $variable ? null : $enumCast,
            'docType' => $docType === null
                ? null
                : $this->laravelDataDeclaredType($docType, $typedAsRequired, $nullable, $optionalRef)[0],
            'name' => $property['name'],
            'required' => $requiredOnInput,
            'openApiName' => $property['openApiName'],
            'docDescription' => $this->resolveDocDescription($property),
            'attributes' => $attributes,
            'attributeImports' => $attributeImports,
            'tracksPresence' => $tracksPresence,
            // An optional argument is optional in PHP too: without a default, `new X(success: true)` was an
            // ArgumentCountError for every optional field left out. Absent is `Optional`, as when
            // laravel-data hydrates a payload without the key.
            // An untyped one has no `Optional` member; laravel-data hands it `null` when absent, so that is
            // its default — and it keeps a defaulted argument from preceding one without a default.
            // A nullable property with a schema default cannot be declared apart from the constructor:
            // laravel-data reads a declared property's default from the declaration, which a readonly
            // property cannot have, and fills an absent nullable one with null instead.
            'nullableWithDefault' => $schemaDefault !== null && $nullable,
            'defaultExpression' => match (true) {
                $schemaDefault !== null => $schemaDefault,
                $tracksPresence => sprintf('new %s()', $optionalRef),
                !$requiredOnInput => 'null',
                default => null,
            },
            // A promoted property unless the class inherits it from a morph base, where redeclaring a
            // readonly property would be a fatal.
            'promoted' => true,
        ];
    }

    /**
     * What the class needs to write an empty object as `{}`: the properties whose value is a nested
     * `Data` (`owl` => wire name), and those whose ITEMS are one (`array<Shape>`), for `transform()`;
     * and, where no property is always written, the refs for its own `toJson()` and `toResponse()`.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $useStatements
     * @return array{writesNested: bool, nestedLiteral: string|null, itemsLiteral: string|null, rootMayBeEmpty: bool, stdClassRef: string, dataRef: string, jsonResponseRef: string|null}
     */
    private function laravelDataEmptyObjectWrite(array $properties, string $namespace, array &$useStatements): array
    {
        $nested = [];
        $items = [];
        $rootMayBeEmpty = true;
        foreach ($properties as $property) {
            if (($property['writeOnly'] ?? null) === true) {
                continue;
            }
            if ($this->propertyIsRequiredOnInput($property)) {
                $rootMayBeEmpty = false;
            }
            $nestedClass = $this->laravelDtoClass($property);
            $itemClass = $this->laravelDtoItemClass($property);
            if (
                ($nestedClass !== null && !$this->laravelIsEnumClass($nestedClass))
                || $this->unionClassMembers($property['type']) !== []
                || $this->unionClassBesideScalars($property['type']) !== null
            ) {
                $nested[$property['name']] = $property['openApiName'];
            } elseif ($itemClass !== null && !$this->laravelIsEnumClass($itemClass)) {
                $items[$property['name']] = $property['openApiName'];
            }
        }

        $anyEmpty = $nested !== [] || $items !== [] || $rootMayBeEmpty;

        return [
            'writesNested' => $nested !== [] || $items !== [],
            'nestedLiteral' => $nested === [] ? null : $this->renderPhpArrayLiteral($nested, 2),
            'itemsLiteral' => $items === [] ? null : $this->renderPhpArrayLiteral($items, 2),
            'rootMayBeEmpty' => $rootMayBeEmpty,
            'stdClassRef' => $anyEmpty ? $this->libraryClassRef('stdClass', $namespace, $useStatements) : 'stdClass',
            'dataRef' => $nested !== [] || $items !== []
                ? $this->libraryClassRef('Spatie\LaravelData\Contracts\BaseData', $namespace, $useStatements)
                : 'BaseData',
            'jsonResponseRef' => $rootMayBeEmpty
                ? $this->libraryClassRef('Illuminate\Http\JsonResponse', $namespace, $useStatements)
                : null,
        ];
    }

    /**
     * The constructor's half of a scalar union with an enum member: the type it declares, widened by the
     * enum's backing type where the union does not already carry it.
     *
     * laravel-data hands the constructor the payload value as it came, and the assignment picks the case
     * from it. `Hue|int` refused `"red"` before that could happen — a TypeError on a valid payload, a 500
     * — while `Hue|string|int` worked only because the document happened to list a string branch too.
     */
    private function laravelDataWithEnumBackingTypes(string $declaredType): string
    {
        $members = explode('|', $declaredType);
        $widened = [];
        foreach ($members as $member) {
            $widened[] = $member;
            $enumName = $this->shortClassName(ltrim($member, '?'));
            if (!array_key_exists($enumName, $this->enumSchemas)) {
                continue;
            }
            $backingType = $this->enumSchemas[$enumName]['type'] === 'int' ? 'int' : 'string';
            if (!in_array($backingType, $members, true)) {
                $widened[] = $backingType;
            }
        }

        return implode('|', array_unique($widened));
    }

    /**
     * Whether a generated class is emitted as an INTERFACE in this mode: a `oneOf` / `anyOf` component
     * with no discriminator. A discriminated one is the abstract morph base `renderLaravelDataMorphBase()`
     * writes, a `Data` class like any other.
     */
    private function laravelDataIsUnionInterface(string $className): bool
    {
        $schema = $this->dtoSchemas[$className] ?? null;

        return is_array($schema)
            && (array_key_exists('oneOf', $schema) || array_key_exists('anyOf', $schema))
            && !array_key_exists($className, $this->discriminatorSchemas)
            && !$this->laravelIsAllOfDiscriminatorBase($className);
    }

    /**
     * The one `Data` class of a union laravel-data leaves uncast: beside it another class (an enum, a
     * `DateTimeImmutable`) or a plain `array`. Beside scalars alone (`Shade|string`) laravel-data casts
     * the class member itself, and null is returned — unless that member is a union INTERFACE
     * (`Shape|string`, `Shape: {anyOf: [$ref Circle, $ref Square]}`), which it cannot cast: the payload
     * array reached the constructor as it came, a TypeError on every valid object.
     */
    private function laravelDataUnionClassToBuild(string $phpType): ?string
    {
        $dataClass = $this->unionClassBesideScalars($phpType);
        if ($dataClass === null) {
            return null;
        }
        if ($this->laravelDataIsUnionInterface($dataClass) && $this->laravelUnionComponentMembers($dataClass) !== []) {
            return $dataClass;
        }
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            if (!in_array($member, ['string', 'int', 'float', 'bool'], true) && $this->shortClassName($member) !== $dataClass) {
                return $dataClass;
            }
        }

        return null;
    }

    /**
     * The discriminated morph base of a union beside members that are no `Data` (`Pet|string`,
     * `Pet|Hue`); null for anything else, a bare `Pet` included — there laravel-data's own morph walk
     * is what refuses an unmapped tag.
     *
     * @param SchemaProperty $property
     */
    private function laravelDataMorphBesideScalars(array $property): ?string
    {
        $target = $this->unionClassBesideScalars($property['type']);

        return $target !== null && $this->laravelDtoClass($property) === null && $this->laravelDataIsMorphBase($target)
            ? $target
            : null;
    }

    /**
     * The expression that builds `$className` from the array `$variable`: `from()` on a `Data` class, and
     * on a union interface — which has no `from()` — the first of its members whose own schema the value
     * matches, as the bare `$ref` to it is built. `Shape|Disc` called `Shape::from()`, an `Error` 500 on
     * every Shape the validator had accepted.
     */
    private function laravelDataBuildExpression(string $className, string $variable): string
    {
        $members = $this->laravelDataIsUnionInterface($className) ? $this->laravelUnionComponentMembers($className) : [];
        if ($members === []) {
            return sprintf('%s::from(%s)', $className, $variable);
        }
        $lastMember = array_pop($members);
        $arms = [];
        foreach ($members as $member) {
            $arms[] = sprintf('%s => %s::from(%s)', $this->laravelUnionMemberMatchCondition($member, $variable), $member, $variable);
        }
        $arms[] = sprintf('default => %s::from(%s)', $lastMember, $variable);

        return sprintf('match (true) { %s }', implode(', ', $arms));
    }

    /**
     * Whether to take a nested-`Data` property away from laravel-data's OWN rule resolution.
     *
     * Emitting `rules()` stops its inferrers from guessing rules for a property, but it does not stop it
     * from treating a nested `Data` object or collection as one: it injects a `Closure` on `tags.*` that
     * resolves the nested class's rules again. Measured on a generated class, one missing nested key then
     * produced TWO messages for one mistake:
     *
     *     {"tags.0.id": ["validation.present"]}   <- laravel-data's injected nested resolution
     *     {"tags":      ["tags[0].id is required"]}   <- the emitted interpreter
     *
     * Same bug shape as the duplicate messages fixed in Laravel mode by
     * `laravelPruneRuleCoveredKeywords()`, arriving from the other side. `#[WithoutValidation]` is the
     * package's own escape hatch (`DataValidationRulesResolver` skips a property whose `validate` is
     * false), and it removes ONLY the injected resolution: the paths this generator emits are applied
     * afterwards as overwritten rules, so the verdict does not change — measured, and the parity suites
     * hold it.
     *
     * NOT applied when the target is a discriminated-union base. There the nested walk is also what adds
     * `EnsurePropertyMorphable`, which is why an unmapped discriminator value comes back as a 422 instead
     * of dying in the morph resolver — see `MorphDiscriminatorTest`. Except beside a scalar member
     * (`Pet|string`), where that walk is what dies: the interpreter judges the morph there.
     *
     * @param SchemaProperty $property
     */
    private function laravelDataSuppressesInferredNestedRules(array $property, ?string $itemClass): bool
    {
        // `Shade|string` as well: laravel-data takes the union for its class member and resolved the
        // class's rules on every value, so a valid string was refused for the object's `required`.
        $target = $itemClass ?? $this->laravelDtoClass($property) ?? $this->unionClassBesideScalars($property['type']);
        // `Olive|Teal` as well: laravel-data resolved the FIRST class's rules on every value, so a valid
        // Teal was refused for Olive's `required`. The emitted union interpreter judges the members.
        if ($target === null && ($this->laravelUndiscriminatedUnionClasses($property) !== [] || $this->laravelInlineDiscriminator($property) !== null)) {
            return true;
        }
        if ($target === null) {
            return false;
        }
        // `Pet|string`: beside a member that is no `Data`, laravel-data's walk resolved the morph on
        // every value, and `DataMorphClassResolver` died on the string — a TypeError 500 on a valid
        // `"s"` and on an invalid `5` alike. The emitted interpreter checks the morph there instead,
        // the discriminator included.
        if ($itemClass === null && $this->laravelDataMorphBesideScalars($property) !== null) {
            return true;
        }

        return !array_key_exists($target, $this->discriminatorSchemas)
            || !$this->laravelDiscriminatorBaseIsInterface($target);
    }

    /**
     * The property type, with `null` and `Optional` as INDEPENDENT facts.
     *
     * This is the whole reason the mode is attractive. In every other mode an optional property has to
     * be declared nullable, because "absent" needs some inhabitable value — and Laravel mode then
     * emitted a `nullable` rule to match, which accepted an explicit null the schema never allowed
     * (fixed 2026-08-11). Here absence has its own type, so `nullable` follows the schema alone.
     *
     * Returns the type and whether presence is observable, which is not the same as "optional": a
     * free-form `mixed` property cannot join a union at all.
     *
     * @return array{0: string, 1: bool}
     */
    private function laravelDataDeclaredType(
        string $phpType,
        bool $required,
        bool $nullable,
        string $optionalRef = 'Optional',
    ): array {
        // `?T` cannot appear in a union, so nullability is always spelled out as a member. The `?` is
        // stripped and DISCARDED: upstream marks every optional property's type nullable because the
        // other modes need somewhere to put "absent", and reading it back here would put `null` on
        // properties the schema never allowed it on. `$nullable` — which follows the document — is the
        // only source, and it is the same one the rule builder reads for its `nullable` rule.
        $base = ltrim($phpType, '?');

        if ($base === self::LARAVEL_DATA_UNTYPED) {
            return [self::LARAVEL_DATA_UNTYPED, false];
        }

        // The base may already be a union (a scalar `oneOf`), and may already carry `null`.
        $members = explode('|', $base);
        if ($nullable && !in_array('null', $members, true)) {
            $members[] = 'null';
        }
        if (!$required) {
            $members[] = $optionalRef;
        }

        return [implode('|', $members), !$required];
    }

    /**
     * A temporal property needs its schema's format on BOTH sides: the cast parses the incoming string,
     * the transformer writes the outgoing one. Without the cast a `format: date` payload fails to parse
     * at all — laravel-data's default is `config('data.date_format')`, which is `DATE_ATOM`.
     *
     * Only `date` is pinned. A `date-time` keeps laravel-data's ATOM default, which is what the other
     * modes emit too for a value with no sub-second part; where the payload carries microseconds the
     * modes diverge, and that is declared in the normalization parity suite rather than papered over
     * here — a single format string cannot express "keep the precision the payload had".
     *
     * Each entry carries EVERY import its source needs — the attribute class as well as the class named
     * in its argument. Missing the attribute's own import is not a syntax error and not a crash: PHP
     * resolves `#[WithCast]` against the DTO's namespace, and laravel-data skips an attribute whose class
     * does not exist (`if (! class_exists(...)) continue`). The property then silently fell back to the
     * global ATOM cast, and only driving a generated class through the real package showed it.
     *
     * @param SchemaProperty $property
     * @return array<int, array{source: string, imports: array<int, string>}>
     */
    private function laravelDataTemporalAttributes(array $property, string $namespace): array
    {
        // A date beside a non-string scalar — `int|DateTimeImmutable` — is cast only when a string
        // arrives (see `pipeline()`), and then with the formats its branches name: the global ATOM cast
        // refused a `format: date` branch's `2018-06-18` after the rule had accepted it.
        $temporalUnion = $this->isTemporalScalarUnion($property['type']) || $this->laravelStringEnumsBesideDate($property['type']) !== [];
        if ($this->shortClassName(ltrim($property['type'], '?')) !== 'DateTimeImmutable' && !$temporalUnion) {
            return [];
        }

        // `!` zeroes what the format does not name: without it `createFromFormat()` fills in the CURRENT time
        // of day, and `2022-01-01` became 2022-01-01T13:43:14 where every other mode reads midnight — an
        // interval filter then dropped the rows dated exactly on its bound. The cast formats a mutable
        // `DateTime` value with the same pattern before parsing it, which `!` cannot round-trip, so the plain
        // pattern stays behind it for that path alone.
        $dateCastFormats = ["'!Y-m-d'", "'Y-m-d'"];
        // The same patterns every other mode accepts (`GeneratedDtoInterface::DATE_TIME_FORMATS`, and the
        // `date_format:` rule this same schema emits). laravel-data's default is the single
        // `config('data.date_format')`, which cannot parse a value carrying microseconds — a payload its own
        // rule had just accepted then died in the cast with `CannotCastDate`.
        //
        // Both offset letters, to stay identical to the rule beside it. The cast only PARSES, where `P`
        // already reads `Z`, so this half was never wrong on its own — but a cast list and a rule list that
        // disagree is exactly how the `Z` refusal survived unnoticed.
        $dateTimeCastFormats = [
            "'Y-m-d\\TH:i:sP'",
            "'Y-m-d\\TH:i:s.uP'",
            "'Y-m-d\\TH:i:sp'",
            "'Y-m-d\\TH:i:s.up'",
            "'Y-m-d H:i:s'",
            "'Y-m-d\\TH:i:s'",
        ];

        $temporalFormat = $property['temporalFormat'] ?? null;
        if ($temporalUnion) {
            $unionFormats = $property['unionTemporalFormats'] ?? [];
            $castFormats = [
                ...(in_array('date', $unionFormats, true) ? $dateCastFormats : []),
                ...($unionFormats === [] || in_array('date-time', $unionFormats, true) ? $dateTimeCastFormats : []),
            ];
            // The transformer below writes any value it is handed as a date, a `true` included.
            $temporalFormat = null;
        } elseif (is_string($temporalFormat)) {
            $castFormats = $temporalFormat === 'Y-m-d' ? $dateCastFormats : $dateTimeCastFormats;
        } elseif (($property['unionTemporalFormats'] ?? []) === ['date']) {
            // `anyOf: [{format: date}]`, or the 3.1 nullable spelling `anyOf: [{format: date}, {type: null}]`:
            // the global ATOM cast refused `2018-06-18` after the rule had accepted it — a 500. Written back
            // as a date too, as the other modes write it.
            $castFormats = $dateCastFormats;
            $temporalFormat = 'Y-m-d';
        } elseif (($property['unionTemporalFormats'] ?? []) === ['date-time']) {
            // `anyOf: [{format: date-time}]` and its 3.1 nullable spelling: the global ATOM cast took it, and
            // a fraction, a space separator or no offset — all accepted by the rule — crashed the cast with
            // `CannotCastDate`. Cast as the plain date-time property is; written back as ATOM like it.
            $castFormats = $dateTimeCastFormats;
        } elseif ($this->isDateOrDateTimeUnion($property['constraints'] ?? [])) {
            // A `oneOf` of a `format: date` and a `format: date-time` string: both shapes are this one
            // property, and the global ATOM cast refused `2026-12-01` after its own rule had accepted it.
            // The cast takes the first pattern that parses, and a date never parses as a date-time.
            $castFormats = [...$dateCastFormats, ...$dateTimeCastFormats];
        } else {
            return [];
        }

        $castImports = [];
        $withCastRef = $this->libraryClassRef('Spatie\LaravelData\Attributes\WithCast', $namespace, $castImports);
        $castRef = $this->libraryClassRef(
            fqcn: 'Spatie\LaravelData\Casts\DateTimeInterfaceCast',
            namespace: $namespace,
            useStatements: $castImports,
        );

        $attributes = [
            [
                'source' => sprintf(
                    '#[%s(%s::class, format: [%s])]',
                    $withCastRef,
                    $castRef,
                    implode(', ', $castFormats),
                ),
                'imports' => $castImports,
            ],
        ];

        // Output format: a date is pinned, a date-time keeps laravel-data's ATOM default. A single format
        // string cannot say "keep the sub-second precision the payload carried", which is what the other
        // modes do — the difference is declared in the normalization parity suite.
        if ($temporalFormat === 'Y-m-d') {
            $transformerImports = [];
            $withTransformerRef = $this->libraryClassRef(
                fqcn: 'Spatie\LaravelData\Attributes\WithTransformer',
                namespace: $namespace,
                useStatements: $transformerImports,
            );
            $transformerRef = $this->libraryClassRef(
                fqcn: 'Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer',
                namespace: $namespace,
                useStatements: $transformerImports,
            );

            $attributes[] = [
                'source' => sprintf(
                    "#[%s(%s::class, format: 'Y-m-d')]",
                    $withTransformerRef,
                    $transformerRef,
                ),
                'imports' => $transformerImports,
            ];
        }

        return $attributes;
    }
}
