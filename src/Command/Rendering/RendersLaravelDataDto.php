<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

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
 * from `RendersLaravelDto` and produce exactly the same rule array and the same messages, which is what
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
        if ($isAbstract && is_array($discriminator)) {
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
        $morphBase = $this->laravelDataMorphBaseFor($className);
        $morphDiscriminator = $morphBase === null
            ? null
            : ($this->discriminatorSchemas[$morphBase]['propertyName'] ?? null);

        $useStatements = [];
        $dataBase = $morphBase === null
            ? $this->libraryClassRef('Spatie\LaravelData\Data', $namespace, $useStatements)
            : null;
        $params = [];
        $rules = [];
        $objectShapePaths = [];
        $parentArguments = [];

        foreach ($properties as $property) {
            $param = $this->resolveLaravelDataParam($property, $namespace);
            if ($morphDiscriminator !== null && $property['openApiName'] === $morphDiscriminator) {
                $param['promoted'] = false;
                $parentArguments[] = $param['name'];
            }
            $params[] = $param;

            $rules[] = [
                'path' => $property['openApiName'],
                'rules' => $this->laravelRulesLiteral($this->laravelRulesForProperty($property)),
            ];
            foreach ($this->laravelItemRulesForProperty($property) as $path => $itemRules) {
                $rules[] = ['path' => $path, 'rules' => $this->laravelRulesLiteral($itemRules)];
            }
            foreach ($this->laravelNestedRules($property, [$className]) as $path => $nestedRules) {
                $rules[] = ['path' => $path, 'rules' => $this->laravelRulesLiteral($nestedRules)];
            }
            foreach ($this->laravelObjectShapePaths($property, '', [$className]) as $objectPath) {
                $objectShapePaths[] = $objectPath;
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

        $interpreter = $this->renderLaravelInterpreterBlock(
            $this->laravelInterpreterConstraints($properties, $className),
            array_filter($this->laravelRecursiveFolds, static fn(mixed $fold): bool => $fold !== []),
        );
        $hasWithValidator = $interpreter['methods'] !== '' || $objectShapePaths !== [];
        foreach ($interpreter['imports'] as $interpreterImport) {
            $useStatements[] = $interpreterImport;
        }

        $validatorRef = $hasWithValidator
            ? $this->libraryClassRef('Illuminate\Validation\Validator', $namespace, $useStatements)
            : null;
        $containerRef = null;
        $requestRef = null;
        $stdClassRef = null;
        if ($objectShapePaths !== []) {
            $containerRef = $this->libraryClassRef('Illuminate\Container\Container', $namespace, $useStatements);
            $requestRef = $this->libraryClassRef('Illuminate\Http\Request', $namespace, $useStatements);
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

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'extends' => $extends ?? $dataBase,
            'validatorRef' => $validatorRef,
            'containerRef' => $containerRef,
            'requestRef' => $requestRef,
            'stdClassRef' => $stdClassRef,
            'parentArguments' => $parentArguments,
            'morphBase' => null,
            'implementedInterfaces' => $implementedInterfaces,
            'interpreterConstsBlock' => $interpreter['consts'],
            'interpreterMethodsBlock' => $interpreter['methods'],
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => $params,
            'rules' => $rules,
            'objectShapePaths' => $objectShapePaths,
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

        $members = [];
        foreach ($mapping as $discriminatorValue => $memberClass) {
            $this->appendImportForClass($useStatements, $memberClass, $namespace, $className);
            $members[] = [
                'value' => $this->phpStringLiteral($discriminatorValue),
                'class' => $this->formatClassNameForNamespace($memberClass, $namespace),
            ];
        }

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'extends' => null,
            'validatorRef' => null,
            'containerRef' => null,
            'requestRef' => null,
            'stdClassRef' => null,
            'parentArguments' => [],
            'morphBase' => [
                'propertyName' => $propertyName,
                'property' => $property,
                'attributes' => $attributes,
                'members' => $members,
                'dataBase' => $dataBase,
                'morphable' => $morphableRef,
            ],
            'implementedInterfaces' => [],
            'interpreterConstsBlock' => '',
            'interpreterMethodsBlock' => '',
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => [],
            'rules' => [],
            'objectShapePaths' => [],
            'unionMembers' => null,
            'interfaceExtends' => [],
            'discriminatorProperty' => $propertyName,
        ]);
    }

    /**
     * The discriminated union base this class is a member of, or null.
     */
    private function laravelDataMorphBaseFor(string $className): ?string
    {
        foreach ($this->discriminatorSchemas as $baseClass => $discriminator) {
            if (!$this->laravelDiscriminatorBaseIsInterface($baseClass)) {
                // A discriminator on a plain `type: object` schema is already a class the children
                // extend in every mode; there is no union base to morph.
                continue;
            }

            if (in_array($className, array_values($discriminator['mapping']), true)) {
                return $baseClass;
            }
        }

        return null;
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
        foreach ($this->symfonyImplementedUnionInterfaces($className, null) as $unionInterface) {
            $interfaceExtends[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        return $this->renderPhpTemplate('dto.laravel-data.php.twig', [
            'namespace' => $namespace,
            'imports' => [],
            'className' => $className,
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
     * @return array{declaredType: string, docType: ?string, name: string, required: bool,
     *     openApiName: string, docDescription: ?string, attributes: array<int, string>,
     *     attributeImports: array<int, string>, tracksPresence: bool, promoted: bool}
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
        if ($itemClass !== null && $this->laravelIsEnumClass($itemClass)) {
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

        // Resolved before the type is composed: `Optional` is spelled INTO the union, so a document with
        // a schema of that name has to get the fully qualified spelling in the type itself.
        $optionalRef = $property['required']
            ? 'Optional'
            : $this->libraryClassRef('Spatie\LaravelData\Optional', $namespace, $attributeImports);

        [$declaredType, $tracksPresence] = $this->laravelDataDeclaredType(
            phpType: $phpType,
            required: $property['required'],
            nullable: $nullable,
            optionalRef: $optionalRef,
        );

        return [
            'declaredType' => $declaredType,
            'docType' => $docType === null
                ? null
                : $this->laravelDataDeclaredType($docType, $property['required'], $nullable, $optionalRef)[0],
            'name' => $property['name'],
            'required' => $property['required'],
            'openApiName' => $property['openApiName'],
            'docDescription' => $this->resolveSymfonyDocDescription($property),
            'attributes' => $attributes,
            'attributeImports' => $attributeImports,
            'tracksPresence' => $tracksPresence,
            // A promoted property unless the class inherits it from a morph base, where redeclaring a
            // readonly property would be a fatal.
            'promoted' => true,
        ];
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
     * of dying in the morph resolver — see `MorphDiscriminatorTest`.
     *
     * @param SchemaProperty $property
     */
    private function laravelDataSuppressesInferredNestedRules(array $property, ?string $itemClass): bool
    {
        $target = $itemClass ?? $this->laravelDtoClass($property);
        if ($target === null) {
            return false;
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
        $temporalFormat = $property['temporalFormat'] ?? null;
        if (!is_string($temporalFormat)) {
            return [];
        }

        if ($this->shortClassName(ltrim($property['type'], '?')) !== 'DateTimeImmutable') {
            return [];
        }

        $castFormats = $temporalFormat === 'Y-m-d'
            ? ["'Y-m-d'"]
            // The same four patterns every other mode accepts (`GeneratedDtoInterface::DATE_TIME_FORMATS`,
            // and the `date_format:` rule this same schema emits). laravel-data's default is the single
            // `config('data.date_format')`, which cannot parse a value carrying microseconds — a payload
            // its own rule had just accepted then died in the cast with `CannotCastDate`.
            : ["'Y-m-d\\TH:i:sP'", "'Y-m-d\\TH:i:s.uP'", "'Y-m-d H:i:s'", "'Y-m-d\\TH:i:s'"];

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
