<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

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
     * Schemas that refer to themselves, folded once and keyed by class name, filled while the constraint
     * map of the class being rendered is built.
     *
     * A recursive schema has no finite inline form, and the two other modes never needed one: Symfony
     * cascades through `#[Assert\Valid]` into each nested DTO's own constraints, and runtime mode walks
     * the schema it holds in memory. Laravel mode has one flat rule map and one emitted literal, so
     * without this the walk stopped at the first turn of the cycle — measured: a child violating
     * `minimum` was accepted while the same violation at the root was reported.
     *
     * @var array<string, mixed>
     */
    private array $laravelRecursiveFolds = [];

    /**
     * Keywords no Laravel rule can express, so the emitted interpreter owns them. A property carrying
     * any of these — at any depth — is validated by `withValidator()` in addition to its rules.
     *
     * @var array<int, string>
     */
    /**
     * Keywords that assert nothing — documentation, defaults and access flags. They are neither emitted
     * as rules nor handed to the interpreter, so they must not make a schema "unenforced".
     *
     * @var array<int, string>
     */
    /**
     * Keywords JSON Schema applies to an array instance and to nothing else.
     *
     * @var array<int, string>
     */
    private const array LARAVEL_ARRAY_ONLY_KEYWORDS = [
        'items',
        'prefixItems',
        'contains',
        'minContains',
        'maxContains',
        'minItems',
        'maxItems',
        'uniqueItems',
        'unevaluatedItems',
    ];

    /**
     * Keywords JSON Schema applies to an object instance and to nothing else.
     *
     * @var array<int, string>
     */
    private const array LARAVEL_OBJECT_ONLY_KEYWORDS = [
        'properties',
        'patternProperties',
        'additionalProperties',
        'unevaluatedProperties',
        'propertyNames',
        'required',
        'dependentRequired',
        'dependentSchemas',
        'minProperties',
        'maxProperties',
        'discriminator',
    ];

    private const array LARAVEL_ANNOTATION_KEYWORDS = [
        'description',
        'title',
        'example',
        'examples',
        'default',
        'deprecated',
        'readOnly',
        'writeOnly',
        'nullable',
        'discriminator',
        'x-parameter-in',
        'x-parameter-style',
        'x-parameter-explode',
        'x-parameter-allow-reserved',
        'x-parameter-allow-empty-value',
        'x-format-pattern',
    ];

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

        $useStatements = [];
        $params = [];
        $rules = [];
        $hydrators = [];
        $ignoredKeys = [];

        $objectShapePaths = [];

        foreach ($properties as $property) {
            $param = $this->resolveLaravelParam($property, $namespace);
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

        $interpreterConstraints = $this->laravelInterpreterConstraints($properties, $className);
        $interpreter = $this->renderLaravelInterpreterBlock(
            $interpreterConstraints,
            // A class whose fold carries no assertion needs no entry: the marker pointing at it resolves
            // to nothing, which is what "nothing left to check here" means.
            array_filter($this->laravelRecursiveFolds, static fn(mixed $fold): bool => $fold !== []),
        );
        $hasWithValidator = $interpreter['methods'] !== '' || $objectShapePaths !== [];
        $this->laravelClassesWithInterpreter[$className] = $hasWithValidator;
        foreach ($interpreter['imports'] as $interpreterImport) {
            $useStatements[] = $interpreterImport;
        }
        if ($hasWithValidator) {
            $useStatements[] = 'Illuminate\Validation\Validator';
        }
        if ($objectShapePaths !== []) {
            $useStatements[] = 'stdClass';
        }

        // A member of a discriminated union has to implement its interface, or the union-typed property
        // it is hydrated into rejects it.
        $implementedInterfaces = [];
        foreach ($this->laravelImplementedUnionInterfaces($className) as $unionInterface) {
            $this->appendImportForClass($useStatements, $unionInterface, $namespace, $className);
            $implementedInterfaces[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        $useStatements = array_values(array_unique($useStatements));
        sort($useStatements);

        return $this->renderPhpTemplate('dto.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'className' => $className,
            'implementedInterfaces' => $implementedInterfaces,
            'interpreterConstsBlock' => $interpreter['consts'],
            'interpreterMethodsBlock' => $interpreter['methods'],
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => $params,
            'rules' => $rules,
            'hydrators' => $hydrators,
            'ignoredKeys' => $ignoredKeys,
            'needsJsonObjectHelper' => $this->laravelNeedsJsonObjectHelper($properties),
            'needsIntCoercionHelper' => $this->laravelNeedsIntCoercionHelper($params),
            'objectShapePaths' => $objectShapePaths,
            'unionMembers' => null,
            'interfaceExtends' => [],
        ]);
    }

    /**
     * @param array<int, string> $unionTypes
     * @param array<string, mixed>|null $discriminator
     */
    private function renderLaravelUnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
    ): string {
        $interfaceExtends = [];
        foreach ($this->symfonyImplementedUnionInterfaces($className, null) as $unionInterface) {
            $interfaceExtends[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        return $this->renderPhpTemplate('dto.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => [],
            'className' => $className,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'params' => [],
            'rules' => [],
            'hydrators' => [],
            'implementedInterfaces' => [],
            'ignoredKeys' => [],
            'needsJsonObjectHelper' => false,
            'needsIntCoercionHelper' => false,
            'objectShapePaths' => [],
            'interpreterConstsBlock' => '',
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
     * @param SchemaProperty $property
     * @return array{declaredType: string, docType: ?string, name: string, required: bool,
     *     openApiName: string, default: string, docDescription: ?string, getter: string,
     *     providedGetter: string, temporalGetterBody: ?string, temporalObjectGetter: string,
     *     isTemporal: bool, isEnum: bool, isDto: bool, itemClass: ?string, isDtoList: bool,
     *     toArrayExpression: string, needsIntCoercion: bool}
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

        $required = $property['required'];
        $default = $property['default'] ?? null;
        $declaredNullable = $property['nullable'] || (!$required && $default === null);
        $declaredType = $this->composePhpTypeHint($phpType, $declaredNullable);

        if ($required) {
            $defaultLiteral = '';
        } elseif ($default !== null) {
            $defaultLiteral = $this->renderDefaultValue($default, $phpType, $declaredType);
        } else {
            $defaultLiteral = ' = null';
        }

        $itemClass = $this->laravelDtoItemClass($property);

        return [
            'declaredType' => $declaredType,
            'docType' => $docType !== null ? $this->composePhpTypeHint($docType, $declaredNullable) : null,
            'name' => $property['name'],
            'required' => $required,
            'openApiName' => $property['openApiName'],
            'default' => $defaultLiteral,
            'docDescription' => $this->resolveSymfonyDocDescription($property),
            'getter' => 'get' . ucfirst($property['name']),
            'providedGetter' => 'is' . ucfirst($property['name']) . 'Provided',
            'temporalGetterBody' => $this->symfonyTemporalGetterBody($property),
            'temporalObjectGetter' => 'get' . ucfirst($property['name']) . 'AsDateTime',
            'isTemporal' => $this->symfonyTemporalGetterBody($property) !== null,
            'isEnum' => $this->laravelEnumClass($property) !== null,
            'isDto' => $this->laravelDtoClass($property) !== null,
            'itemClass' => $itemClass,
            'isDtoList' => $itemClass !== null,
            'toArrayExpression' => $this->laravelToArrayExpression($property),
            'needsIntCoercion' => $this->laravelPropertyAcceptsInt($declaredType),
        ];
    }

    /**
     * The rules for one property, in the ARRAY form — never a pipe string: a `|` inside a `regex`
     * pattern would split the rule list.
     *
     * `required` vs `sometimes` is where PATCH semantics come from: `sometimes` means "validate only
     * if the key is present", and `validated()` then returns only the keys the payload carried, which
     * is what `isXxxProvided()` reads.
     *
     * @param SchemaProperty $property
     * @return array<int, string> each entry is already PHP source (a quoted rule or a `Rule::` call)
     */
    private function laravelRulesForProperty(array $property): array
    {
        return $this->laravelRuleSpecFor($property)['rules'];
    }

    /**
     * The constraint view both halves of this mode read.
     *
     * `allOf` of scalar fragments is FOLDED first, reusing what Symfony mode does (P3): the emitted
     * interpreter has no `allOf` branch for that shape either, because folding is the correct answer —
     * `allOf: [{type: string}, {minLength: 3}]` describes one string with a length, not a composition.
     * Without this the rules saw no type and no bounds, and the parity suite caught it.
     *
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function laravelConstraintsFor(array $property): array
    {
        return $this->laravelFoldScalarAllOfDeeply($property['constraints'] ?? []);
    }

    /**
     * The same folding at every depth: `items: {allOf: [{type: string}, {minLength: 3}]}` describes one
     * string member, and the parity suite found that folding only the top level left it unchecked.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function laravelFoldScalarAllOfDeeply(array $schema): array
    {
        $folded = $this->foldScalarAllOfConstraints($schema);

        $result = [];
        foreach ($folded as $keyword => $value) {
            if (!is_array($value)) {
                $result[$keyword] = $value;
                continue;
            }

            if ($keyword === 'items' || $keyword === 'additionalProperties' || $keyword === 'contains') {
                $result[$keyword] = $this->laravelFoldScalarAllOfDeeply($value);
                continue;
            }

            if ($keyword === 'properties') {
                $nested = [];
                foreach ($value as $name => $propertySchema) {
                    $nested[$name] = is_array($propertySchema)
                        ? $this->laravelFoldScalarAllOfDeeply($propertySchema)
                        : $propertySchema;
                }
                $result[$keyword] = $nested;
                continue;
            }

            $result[$keyword] = $value;
        }

        return $result;
    }

    /**
     * Whether the SCHEMA declares the property nullable — which is not what `$property['nullable']`
     * says. The walker sets that flag for every OPTIONAL property too, because the other modes need
     * somewhere to put "absent" and use `null` for it (`$nullable = $nullableBySchema || !$isRequired`).
     *
     * Reading the flag instead of the document is a bug with a history: it emitted a `nullable` rule for
     * every optional property, and `sometimes` already covers an absent key — so all `nullable` added
     * was permission to send a null the schema never allowed (fixed 2026-08-11). laravel-data mode asks
     * the same question of its property TYPE, where `null` and `Optional` are separate union members, so
     * the predicate lives here instead of inside its one caller.
     *
     * @param SchemaProperty $property
     */
    private function laravelSchemaDeclaresNullable(array $property): bool
    {
        // For a REQUIRED property the flag is unambiguous: it is `$nullableBySchema || !$isRequired`, and
        // the second half is false, so the flag IS the document. This is the only reading that covers a
        // nullable `$ref` — its constraint map carries no `type`, so the keyword check below cannot see
        // it, and a required nullable nested DTO was typed non-nullable until this branch existed.
        if ($property['required'] === true) {
            return $property['nullable'] === true;
        }

        $constraints = $this->laravelConstraintsFor($property);
        $declaredType = $constraints['type'] ?? null;

        return ($constraints['nullable'] ?? null) === true
            || (is_array($declaredType) && in_array('null', $declaredType, true));
    }

    /**
     * The rules for one property AND the keywords they actually enforce.
     *
     * The second half matters as much as the first: the interpreter is pruned by what the rules
     * consumed, and a static "these are covered" list was wrong for every keyword the translator
     * silently skips — `exclusiveMinimum`, `dependentRequired`, a format Laravel has no rule for. The
     * parity suite caught all three at once (17 disagreements against runtime mode).
     *
     * @param SchemaProperty $property
     * @return array{rules: array<int, string>, consumed: array<int, string>}
     */
    private function laravelRuleSpecFor(array $property): array
    {
        $constraints = $this->laravelConstraintsFor($property);
        $declaredType = $constraints['type'] ?? null;
        $nullable = $this->laravelSchemaDeclaresNullable($property);

        // OpenAPI `required` means "the key is there". Laravel's `required` means "present and NOT
        // EMPTY": it rejects null, `""`, `[]` and `{}`. Those are all legal values for a required
        // property, so the faithful translation is `present` — the key must exist, its value is the
        // type rules' business. Measured through the real validator against runtime mode:
        // `{"s":""}`, `{"list":[]}`, `{"map":{}}` and `{"child":null}` were all rejected under
        // `required` while the other two modes accepted them.
        $rules = [$property['required'] === true ? "'present'" : "'sometimes'"];
        $consumed = ['required'];

        // ONLY when the schema says so. Optional is not nullable: `sometimes` already covers the
        // absent key, while a key that IS there carrying `null` is a value the schema never allowed.
        // Laravel skips a non-implicit rule for an absent attribute but not for a present null, so
        // dropping `nullable` is exactly what makes `{"tags": null}` fail its `array` rule — which is
        // the runtime verdict. Adding it for every optional property accepted null everywhere.
        if ($nullable) {
            $rules[] = "'nullable'";
            $consumed[] = 'nullable';
        }

        if ($this->laravelIsUploadedFileProperty($property)) {
            $rules[] = "'file'";

            return ['rules' => $rules, 'consumed' => [...$consumed, 'type', 'format']];
        }

        $enumClass = $this->laravelEnumClass($property);
        if ($enumClass !== null) {
            // `Rule::enum()` pins the backing type AND the members in one first-party rule; the
            // generated enum is the single source of the allowed values.
            $rules[] = sprintf('Rule::enum(%s::class)', $this->shortClassName($enumClass));

            return ['rules' => $rules, 'consumed' => [...$consumed, 'type', 'enum']];
        }

        $schemaSpec = $this->laravelSchemaRuleSpec($constraints, $property['type']);

        return [
            'rules' => [...$rules, ...$schemaSpec['rules']],
            'consumed' => [...$consumed, ...$schemaSpec['consumed']],
        ];
    }

    /**
     * Rules for the properties of a nested DTO, keyed by the dotted path Laravel expects
     * (`author.name`, `tags.*.name`). Without this the nested payload would be typed but never
     * validated: Laravel has no `#[Assert\Valid]` cascade, the rule set is one flat map.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $visitedClasses guards a self-referential schema
     * @return array<string, array<int, string>>
     */
    private function laravelNestedRules(array $property, array $visitedClasses = []): array
    {
        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);

        if ($nestedClass !== null) {
            $prefix = $property['openApiName'];
        } elseif ($itemClass !== null && !$this->laravelIsEnumClass($itemClass)) {
            $prefix = $property['openApiName'] . '.*';
            $nestedClass = $itemClass;
        } else {
            return [];
        }

        if (in_array($nestedClass, $visitedClasses, true)) {
            // A cycle cannot be expressed as a finite rule map; the nested class validates itself
            // when the application composes its rules().
            return [];
        }
        $visitedClasses[] = $nestedClass;

        $rules = [];
        foreach ($this->getSchemaProperties($nestedClass) as $nestedProperty) {
            $path = $prefix . '.' . $nestedProperty['openApiName'];
            $nestedRules = $this->laravelRulesForProperty($nestedProperty);

            // A nested property's PRESENCE is not expressible as a rule, so the interpreter owns it.
            // Both candidates were measured and both were wrong:
            //   - `present`/`required` fire even when the parent is null, so `{"child": null}` was
            //     rejected for a missing `child.id` that cannot exist;
            //   - `required_with:child` / `present_with:child` fire whenever the parent KEY is there,
            //     null included, so the same payload still failed — and `required_with` additionally
            //     rejects a legal null value, being "present and not empty".
            // The remaining rules are safe to keep: Laravel skips a non-implicit rule (`integer`,
            // `string`, `regex`, …) for an attribute that is not present.
            $nestedRules = array_values(array_filter(
                $nestedRules,
                static fn(string $rule): bool => $rule !== "'present'" && $rule !== "'sometimes'",
            ));

            if ($nestedRules !== []) {
                $rules[$path] = $nestedRules;
            }
            foreach ($this->laravelItemRulesForProperty($nestedProperty) as $itemPath => $itemRules) {
                $rules[$prefix . '.' . $itemPath] = $itemRules;
            }
            foreach ($this->laravelNestedRules($nestedProperty, $visitedClasses) as $deepPath => $deepRules) {
                $rules[$prefix . '.' . $deepPath] = $deepRules;
            }
        }

        return $rules;
    }

    /**
     * Rules for a subschema, without the presence/nullability half. Shared by the property level and
     * the `items` / map-value level, where presence is meaningless.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function laravelRulesForSchema(array $schema, string $phpType = ''): array
    {
        return $this->laravelSchemaRuleSpec($schema, $phpType)['rules'];
    }

    /**
     * Rules for a subschema plus the keywords they enforce. Shared by the property level and the
     * `items` / map-value level, where presence is meaningless.
     *
     * @param array<string, mixed> $schema
     * @return array{rules: array<int, string>, consumed: array<int, string>}
     */
    private function laravelSchemaRuleSpec(array $schema, string $phpType = ''): array
    {
        $rules = [];
        $consumed = [];
        $type = $schema['type'] ?? null;
        $type = is_array($type) ? $this->laravelSoleNonNullType($type) : $type;

        // A generated backed enum or a DateTimeImmutable is already the type; `string` on top of it
        // would fail against the hydrated object exactly as it did in Symfony mode (see G1).
        $isObjectValued = str_contains($phpType, 'DateTimeImmutable') || $this->laravelLooksLikeClassType($phpType);

        $typeRule = match ($type) {
            'string' => $isObjectValued ? null : "'string'",
            'integer' => "'integer'",
            'number' => "'numeric'",
            'boolean' => "'boolean'",
            // `array` also accepts an associative array, so a JSON array needs `list` as well. A
            // JSON object is `array` only — the object-vs-list distinction is the interpreter's job.
            'array' => "'array', 'list'",
            'object' => "'array'",
            default => null,
        };
        if ($typeRule !== null) {
            $rules[] = $typeRule;
            $consumed[] = 'type';
        }

        if (array_key_exists('format', $schema) && is_string($schema['format'])) {
            $formatRule = $this->laravelFormatRule($schema['format']);
            if ($formatRule !== null) {
                $rules[] = $formatRule;
                $consumed[] = 'format';
                // Every rule `laravelFormatRule()` returns refuses a non-string, so `type: string` is
                // enforced even where the `string` rule itself was suppressed — which is the case for
                // a DateTimeImmutable property, whose `date_format:` rule would otherwise be reported
                // ALONGSIDE the interpreter's own "must be of type string" — see the "date-time + not"
                // case in `LaravelRulesEnforcementTest::overlapProvider()`.
                if ($typeRule === null && $type === 'string') {
                    $consumed[] = 'type';
                }
            }
        }

        // `min:`/`max:` mean length, value or count depending on the type rule beside them. Without a
        // pinned scalar type the rule is ambiguous, so it is not emitted at all.
        $boundsSubject = match ($type) {
            'string' => 'length',
            'integer', 'number' => 'value',
            'array', 'object' => 'count',
            default => null,
        };
        if ($boundsSubject === 'length') {
            $bounds = $this->laravelBoundSpec($schema, 'minLength', 'maxLength');
            $rules = [...$rules, ...$bounds['rules']];
            $consumed = [...$consumed, ...$bounds['consumed']];
        } elseif ($boundsSubject === 'value') {
            $bounds = $this->laravelBoundSpec($schema, 'minimum', 'maximum');
            $rules = [...$rules, ...$bounds['rules']];
            $consumed = [...$consumed, ...$bounds['consumed']];
        } elseif ($boundsSubject === 'count') {
            foreach ([['minItems', 'maxItems'], ['minProperties', 'maxProperties']] as [$minKey, $maxKey]) {
                $bounds = $this->laravelBoundSpec($schema, $minKey, $maxKey);
                $rules = [...$rules, ...$bounds['rules']];
                $consumed = [...$consumed, ...$bounds['consumed']];
            }
        }

        if (array_key_exists('pattern', $schema) && is_string($schema['pattern'])) {
            // Array form + explicit delimiters: a pattern containing `|` must not split the list.
            $rules[] = sprintf("'regex:/%s/'", str_replace(['\\', "'"], ['\\\\', "\\'"], $schema['pattern']));
            $consumed[] = 'pattern';
        }

        if (array_key_exists('multipleOf', $schema) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))) {
            $rules[] = sprintf("'multiple_of:%s'", $this->laravelNumberLiteral($schema['multipleOf']));
            $consumed[] = 'multipleOf';
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && $schema['enum'] !== []) {
            // `Rule::in([...])`, never `in:a,b` — a value containing a comma breaks the string form.
            $rules[] = sprintf('Rule::in(%s)', $this->laravelValueListLiteral($schema['enum']));
            $consumed[] = 'enum';
        }

        if (array_key_exists('const', $schema)) {
            $rules[] = sprintf('Rule::in(%s)', $this->laravelValueListLiteral([$schema['const']]));
            $consumed[] = 'const';
        }

        return ['rules' => $rules, 'consumed' => $consumed];
    }

    /**
     * Rules for the members of a list or the values of a map, keyed by the wildcard path Laravel
     * expects (`tags.*`). A DTO-valued item is validated by ITS OWN `rules()`, so nothing is emitted
     * for it here — the application composes the two, which is also how Laravel's own nested
     * validation works.
     *
     * @param SchemaProperty $property
     * @return array<string, array<int, string>>
     */
    private function laravelItemRulesForProperty(array $property): array
    {
        $constraints = $this->laravelConstraintsFor($property);
        $itemSchema = null;

        if (is_array($constraints['items'] ?? null)) {
            $itemSchema = $constraints['items'];
        } elseif (is_array($constraints['additionalProperties'] ?? null)) {
            $itemSchema = $constraints['additionalProperties'];
        }

        $itemClass = $this->laravelDtoItemClass($property);
        $unique = ($property['constraints']['uniqueItems'] ?? null) === true;

        // A DTO-valued item is expanded by `laravelNestedRules()` into dotted paths instead. `distinct`
        // over object items is not expressible either — Laravel compares scalars — so it is reported.
        // `distinct` compares scalars, so uniqueItems over object items belongs to the interpreter
        // (`laravelSchemaNeedsInterpreter()` picks it up).
        if ($itemClass !== null) {
            return [];
        }

        $rules = is_array($itemSchema) ? $this->laravelRulesForSchema($itemSchema) : [];

        // `distinct` belongs on the ITEM path: it compares the sibling values of one array. On the
        // property path the validator accepts it and enforces nothing (measured).
        if ($unique) {
            $rules[] = "'distinct'";
        }

        return $rules === [] ? [] : [$property['openApiName'] . '.*' => $rules];
    }

    /**
     * `min:`/`max:` for a bound Laravel can express, and the keywords those rules consume.
     *
     * A bound carrying the OpenAPI 3.0 EXCLUSIVE modifier (`minimum: 3` next to
     * `exclusiveMinimum: true`) is left alone: `min:` is inclusive and has no exclusive spelling, so
     * emitting it and consuming the keyword accepted the boundary value AND took `minimum` away from
     * the interpreter, which does implement the pairing. Measured on `{"f":3}` against
     * `minimum: 3, exclusiveMinimum: true` — accepted here, refused by every other mode.
     *
     * @param array<string, mixed> $schema
     * @return array{rules: array<int, string>, consumed: array<int, string>}
     */
    private function laravelBoundSpec(array $schema, string $minKey, string $maxKey): array
    {
        $rules = [];
        $consumed = [];
        $min = $schema[$minKey] ?? null;
        $max = $schema[$maxKey] ?? null;

        $exclusiveMin = ($schema['exclusiveMinimum'] ?? null) === true && $minKey === 'minimum';
        $exclusiveMax = ($schema['exclusiveMaximum'] ?? null) === true && $maxKey === 'maximum';

        if (!$exclusiveMin && (is_int($min) || is_float($min))) {
            $rules[] = sprintf("'min:%s'", $this->laravelNumberLiteral($min));
            $consumed[] = $minKey;
        }
        if (!$exclusiveMax && (is_int($max) || is_float($max))) {
            $rules[] = sprintf("'max:%s'", $this->laravelNumberLiteral($max));
            $consumed[] = $maxKey;
        }

        return ['rules' => $rules, 'consumed' => $consumed];
    }

    /**
     * Formats Laravel has a rule for. Everything else (`hostname`, `byte`, `duration`,
     * `json-pointer`, `uri-template`, `idn-*`, `regex`) is an interpreter job — see M2.
     */
    private function laravelFormatRule(string $format): ?string
    {
        return match ($format) {
            'email', 'idn-email' => "'email'",
            'uuid' => "'uuid'",
            'uri', 'url' => "'url'",
            'ipv4' => "'ipv4'",
            'ipv6' => "'ipv6'",
            'date' => "'date_format:Y-m-d'",
            // The same four patterns the deserializer and the validator accept, so every mode takes
            // the same inputs (GeneratedDtoInterface::DATE_TIME_FORMATS).
            'date-time', 'datetime' => "'date_format:Y-m-d\\TH:i:sP,Y-m-d\\TH:i:s.uP,Y-m-d H:i:s,Y-m-d\\TH:i:s'",
            default => null,
        };
    }

    /**
     * @param array<int, mixed> $values
     */
    private function laravelValueListLiteral(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = match (true) {
                is_string($value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'",
                is_int($value) || is_float($value) => $this->laravelNumberLiteral($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => 'null',
            };
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function laravelNumberLiteral(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        $rendered = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $rendered === '' ? '0' : $rendered;
    }

    /**
     * @param array<int, mixed> $types
     */
    private function laravelSoleNonNullType(array $types): ?string
    {
        $nonNull = [];
        foreach ($types as $type) {
            if (is_string($type) && $type !== 'null') {
                $nonNull[] = $type;
            }
        }

        // `type: [string, integer]` pins nothing, so no type rule and no bounds: `min:` would silently
        // change meaning between the two branches.
        return count($nonNull) === 1 ? $nonNull[0] : null;
    }

    /**
     * @param array<int, string> $rules
     */
    private function laravelRulesLiteral(array $rules): string
    {
        return '[' . implode(', ', $rules) . ']';
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
        $key = $this->laravelStringLiteral($property['openApiName']);
        $raw = sprintf('$data[%s]', $key);
        $enumClass = $this->laravelEnumClass($property);
        $dtoClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);

        // A JSON `42.0` is an integer per the spec and passes validation, but PHP decodes it to a float
        // and an `int` property cannot hold it — the constructor threw a TypeError, i.e. a 500 after a
        // successful validation. Coerce exactly the values the spec calls integers.
        if ($this->laravelPropertyAcceptsInt($param['declaredType'])) {
            $raw = sprintf('self::toIntIfIntegral(%s)', $raw);
        }

        $value = match (true) {
            $param['isTemporal'] === true => sprintf('new DateTimeImmutable(%s)', $raw),
            $enumClass !== null => sprintf('%s::from(%s)', $this->shortClassName($enumClass), $raw),
            $dtoClass !== null => $this->laravelNestedDtoExpression($dtoClass, $raw),
            $itemClass !== null && $this->laravelIsEnumClass($itemClass) => sprintf(
                'array_map(static fn(int|string $item): %1$s => %1$s::from($item), %2$s)',
                $this->shortClassName($itemClass),
                $raw,
            ),
            $itemClass !== null => sprintf(
                'array_map(static fn(array $item): %1$s => %1$s::fromValidated($item), %2$s)',
                $this->shortClassName($itemClass),
                $raw,
            ),
            default => $raw,
        };

        // A readOnly property is server-owned: runtime mode ignores whatever the client sent, so the
        // field stays unset and is omitted from the response. Hydration mirrors that by treating the key
        // as absent.
        if (($property['readOnly'] ?? null) === true) {
            return ['name' => $property['name'], 'expression' => 'null'];
        }

        // An optional key is absent from `validated()` when it was not sent, and may be an explicit
        // null when it was — both have to reach the constructor as null without touching the cast.
        if ($property['required'] !== true || $property['nullable'] === true) {
            $value = sprintf('($data[%s] ?? null) === null ? null : %s', $key, $value);
        }

        return ['name' => $property['name'], 'expression' => $value];
    }

    /**
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function laravelImportsForProperty(array $property, string $namespace, string $className): array
    {
        $imports = [];

        if ($this->symfonyTemporalGetterBody($property) !== null) {
            $imports[] = 'DateTimeImmutable';
        }
        if ($this->laravelIsUploadedFileProperty($property)) {
            // Laravel's own UploadedFile extends this one, so the Symfony class is the right hint.
            $imports[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
        }
        if ($this->laravelRulesNeedRuleFacade($property)) {
            $imports[] = 'Illuminate\Validation\Rule';
        }

        foreach ([$this->laravelEnumClass($property), $this->laravelDtoClass($property), $this->laravelDtoItemClass($property)] as $referenced) {
            if ($referenced !== null) {
                $this->appendImportForClass($imports, $referenced, $namespace, $className);
            }

            // A discriminated union resolves to one of its members, so those are referenced too.
            if ($referenced === null || !array_key_exists($referenced, $this->discriminatorSchemas)) {
                continue;
            }

            $imports[] = 'InvalidArgumentException';
            foreach ($this->discriminatorSchemas[$referenced]['mapping'] as $targetClass) {
                $this->appendImportForClass($imports, $targetClass, $namespace, $className);
            }
        }

        return $imports;
    }

    /**
     * @param SchemaProperty $property
     */
    private function laravelRulesNeedRuleFacade(array $property): bool
    {
        // The NESTED expansion counts too: `discriminator.type => Rule::enum(...)` is emitted from the
        // nested schema, so a class whose own rules need no facade still references it. Missing that
        // import is a fatal on the first `rules()` call — found by running the demo controller, not by a
        // unit test.
        $ruleSets = [
            $this->laravelRulesForProperty($property),
            ...array_values($this->laravelItemRulesForProperty($property)),
            ...array_values($this->laravelNestedRules($property)),
        ];

        foreach ($ruleSets as $ruleSet) {
            foreach ($ruleSet as $rule) {
                if (str_starts_with($rule, 'Rule::')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param SchemaProperty $property
     */
    private function laravelEnumClass(array $property): ?string
    {
        $base = $this->laravelBaseTypeName($property['type']);

        return $base !== null && $this->laravelIsEnumClass($base) ? $base : null;
    }

    /**
     * @param SchemaProperty $property
     */
    private function laravelDtoClass(array $property): ?string
    {
        $base = $this->laravelBaseTypeName($property['type']);

        return $base !== null && array_key_exists($base, $this->dtoSchemas) ? $base : null;
    }

    /**
     * The generated class behind `array<X>`, when X is one of ours — a list of DTOs or of enums.
     *
     * @param SchemaProperty $property
     */
    private function laravelDtoItemClass(array $property): ?string
    {
        if (preg_match('/^\??array<\??([A-Za-z_][A-Za-z0-9_\\\]*)>$/', $property['type'], $matches) !== 1) {
            return null;
        }

        $itemClass = $this->shortClassName($matches[1]);

        return array_key_exists($itemClass, $this->dtoSchemas) || $this->laravelIsEnumClass($itemClass)
            ? $itemClass
            : null;
    }

    private function laravelIsEnumClass(string $className): bool
    {
        return array_key_exists($className, $this->enumSchemas);
    }

    private function laravelLooksLikeClassType(string $phpType): bool
    {
        $base = $this->laravelBaseTypeName($phpType);

        return $base !== null
            && (array_key_exists($base, $this->dtoSchemas) || $this->laravelIsEnumClass($base));
    }

    /**
     * The bare class name of a scalar-or-class type hint, or null for a generic/builtin one.
     */
    private function laravelBaseTypeName(string $phpType): ?string
    {
        $base = ltrim($phpType, '?');
        if ($base === '' || str_contains($base, '<') || str_contains($base, '|')) {
            return null;
        }

        $base = $this->shortClassName($base);

        return preg_match('/^[A-Z]/', $base) === 1 ? $base : null;
    }

    private function laravelStringLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
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
        $property_access = '$this->' . $name;
        $nullable = $property['nullable'] === true || $property['required'] !== true;
        $arrow = $nullable ? '?->' : '->';

        if ($this->symfonyTemporalGetterBody($property) !== null) {
            return sprintf('$this->%s()', 'get' . ucfirst($name));
        }

        if ($this->laravelEnumClass($property) !== null) {
            return $property_access . $arrow . 'value';
        }

        // A map must encode as a JSON object, empty or not — `type: object` says so, and runtime mode
        // casts for exactly that reason. Nothing third-party sits in the way here. The cast is deep: a
        // free-form value can nest maps at any level, and each one is an object on the wire.
        if (($property['isMap'] ?? null) === true) {
            return $nullable
                ? sprintf('%s === null ? null : self::toJsonObjects(%s)', $property_access, $property_access)
                : sprintf('self::toJsonObjects(%s)', $property_access);
        }

        if ($this->laravelDtoClass($property) !== null) {
            return $property_access . $arrow . 'toArray()';
        }

        if ($this->laravelDescribesListOfMaps($property['type'])) {
            $mapper = 'static fn(?array $item): ?object => $item === null ? null : self::toJsonObjects($item)';

            return $nullable
                ? sprintf('%s === null ? null : array_map(%s, %s)', $property_access, $mapper, $property_access)
                : sprintf('array_map(%s, %s)', $mapper, $property_access);
        }

        $itemClass = $this->laravelDtoItemClass($property);
        if ($itemClass !== null) {
            $mapper = $this->laravelIsEnumClass($itemClass)
                ? sprintf('static fn(%s $item): int|string => $item->value', $this->shortClassName($itemClass))
                : sprintf('static fn(%s $item): array => $item->toArray()', $this->shortClassName($itemClass));

            return $nullable
                ? sprintf('%s === null ? null : array_map(%s, %s)', $property_access, $mapper, $property_access)
                : sprintf('array_map(%s, %s)', $mapper, $property_access);
        }

        return $property_access;
    }

    /**
     * The emitted OpenAPI interpreter, packaged for this mode: everything no rule vocabulary can express
     * — `oneOf`, `anyOf`, `allOf`, `not`, `if`/`then`/`else`, `contains`, `prefixItems`, `unevaluated*`,
     * `content*`, `propertyNames`, `patternProperties`, `dependentSchemas`, `discriminator`.
     *
     * There is ONE implementation of it, generated by `RendersSymfonyDto::renderSymfonyValidationMethods()`
     * and mode-agnostic in substance: it reads a value, a schema literal and a path, and returns a list of
     * messages. Only the packaging differs —
     *
     *   - Symfony: instance methods on the DTO, entered from `#[Assert\Callback]`, fed the object's own
     *     payload (`toOpenApiValidationPayload()`), reporting through a `ConstraintViolationBuilder`;
     *   - Laravel (here): STATIC methods, entered from `withValidator()`, fed the raw payload the
     *     validator already holds, reporting through `$validator->errors()->add()`.
     *
     * A mechanical rewrite, not a second implementation: the emitted code only ever reads its arguments
     * and its own constants, so `$this->` becomes `self::` and the methods become static. The one
     * instance-bound part — the `#[Assert\Callback]` entry point plus the object-payload view it feeds —
     * is not emitted at all here (`payloadIsHydratedObject: false`), because in Laravel the payload IS an
     * array: validation runs before anything is hydrated.
     *
     * @param array<string, mixed> $constraints the schema literal, keyed by OpenAPI property name
     * @param array<string, mixed> $recursiveSchemas self-referential schemas a marker re-enters
     * @return array{consts: string, methods: string, imports: array<int, string>}
     */
    private function renderLaravelInterpreterBlock(array $constraints, array $recursiveSchemas = []): array
    {
        if ($constraints === []) {
            return ['consts' => '', 'methods' => '', 'imports' => []];
        }

        // No enum/temporal normalization: the validator runs on the raw request payload, where those
        // values are still the scalars the client sent.
        $block = $this->renderSymfonyValidationBlock(
            constraints: $constraints,
            phpToOpenApiNameMap: [],
            providedFlags: [],
            valueKinds: ['enum' => false, 'temporal' => false],
            // No `#[Assert\Callback]`, and no object-payload view for it to feed: this mode enters
            // through `withValidator()` with the raw payload the validator already holds.
            payloadIsHydratedObject: false,
            // Only this mode needs them: Symfony cascades into each nested DTO's own constraints, so a
            // recursive schema never has to be written down in one piece there.
            recursiveSchemas: $recursiveSchemas,
        );

        return [
            'consts' => $this->laravelStaticizeEmittedInterpreter($block['consts']),
            'methods' => $this->laravelStaticizeEmittedInterpreter($block['methods']),
            'imports' => $block['imports'],
        ];
    }

    /**
     * Rewrites the emitted interpreter into static form.
     *
     * Safe because the emitted code is pure: every method takes what it needs as arguments and reads only
     * `self::`-scoped constants. The sole instance-bound helper, `toOpenApiValidationPayload()`, is
     * suppressed at emission — a Laravel payload is already an array, so nothing has to be unwrapped from
     * an object first.
     */
    private function laravelStaticizeEmittedInterpreter(string $code): string
    {
        return str_replace(
            ['    private function ', '$this->'],
            ['    private static function ', 'self::'],
            $code,
        );
    }

    /**
     * The schema the interpreter enforces: every property that carries a keyword the rules cannot
     * express, with its FULL subschema — a composition branch is only meaningful next to the keywords
     * it composes.
     *
     * Properties whose whole schema is expressible as rules are left out, so the emitted interpreter
     * stays as small as the document allows and a plain DTO gets none at all.
     *
     * @param array<int, SchemaProperty> $properties
     * @param string $ownerClass the class these properties belong to, which seeds the cycle guard: a
     *        schema that refers back to ITSELF from the root is a cycle like any other, and treating it
     *        as a fresh class expanded it one level with the pruning of a level the dotted rules cover —
     *        which they do not, because `laravelNestedRules()` cannot expand a cycle either. A child
     *        violating `minimum` was then enforced by nobody.
     * @return array<string, mixed>
     */
    private function laravelInterpreterConstraints(array $properties, string $ownerClass): array
    {
        $this->laravelRecursiveFolds = [];
        $constraints = [];
        foreach ($properties as $property) {
            // The gate is the FOLDED schema, not the property's own: a plain `$ref` carries nothing
            // unenforced by itself, while the class behind it may declare `contains` or
            // `dependentRequired` that only the interpreter can check. An empty result means the rules
            // cover the property after all.
            $folded = $this->laravelInterpreterSchemaFor($property, [$ownerClass]);
            if ($folded === []) {
                continue;
            }

            $constraints[$property['openApiName']] = $folded;
        }

        return $constraints;
    }

    /**
     * Whether a subschema — at any depth — carries an assertion the rules did not take.
     *
     * Driven by what the rule builder actually consumed, not by a list of "hard" keywords: the parity
     * suite showed that a static list silently dropped `exclusiveMinimum`, `dependentRequired` and every
     * format Laravel has no rule for.
     *
     * @param array<string, mixed> $schema
     */
    private function laravelSchemaNeedsInterpreter(array $schema): bool
    {
        return $this->laravelUnconsumedKeywords($schema) !== [];
    }

    /**
     * Keywords this schema carries that CANNOT fire, whatever the payload — noise the interpreter
     * would be emitted to check and then never report.
     *
     * Two sources, both provable from the schema alone:
     *
     *   - a `format` no mode enforces (`uppercase`, `slug`, …): the emitted `match` has no arm for it
     *     and falls through to `default => true`, so the keyword asserts nothing anywhere;
     *   - a keyword that only applies to one JSON type, on a schema that pins a DIFFERENT type —
     *     `contains` beside `type: string`, `required` beside `type: array`. JSON Schema ignores those
     *     by definition, so emitting them buys an interpreter for a property that needs none.
     *
     * Only a PINNED type counts: a union (`type: [string, array]`) or an absent one leaves every
     * keyword reachable.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function laravelInertKeywords(array $schema): array
    {
        $inert = [];

        $format = $schema['format'] ?? null;
        if (is_string($format) && !$this->openApiInterpreterChecksFormat($format)) {
            $inert[] = 'format';
        }

        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            return $inert;
        }

        if ($type !== 'array') {
            $inert = [...$inert, ...self::LARAVEL_ARRAY_ONLY_KEYWORDS];
        }

        if ($type !== 'object') {
            $inert = [...$inert, ...self::LARAVEL_OBJECT_ONLY_KEYWORDS];
        }

        return $inert;
    }

    /**
     * The assertions in a subschema that the rules leave unenforced, at this level or deeper.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function laravelUnconsumedKeywords(array $schema): array
    {
        $consumed = [
            ...$this->laravelSchemaRuleSpec($schema)['consumed'],
            ...$this->laravelInertKeywords($schema),
        ];
        $unconsumed = [];

        foreach (array_keys($schema) as $keyword) {
            if (in_array($keyword, $consumed, true) || in_array($keyword, self::LARAVEL_ANNOTATION_KEYWORDS, true)) {
                continue;
            }

            // `uniqueItems` is `distinct` for scalar members only; over objects it stays here.
            if ($keyword === 'uniqueItems') {
                $itemType = $schema['items']['type'] ?? null;
                if ($itemType !== null && $itemType !== 'object' && !array_key_exists('$ref', $schema['items'] ?? [])) {
                    continue;
                }
            }

            // A container is unenforced only when its CONTENTS are.
            if ($keyword === 'items' || $keyword === 'additionalProperties') {
                if (is_array($schema[$keyword]) && $this->laravelUnconsumedKeywords($schema[$keyword]) === []) {
                    continue;
                }
            }

            if ($keyword === 'properties' && is_array($schema['properties'])) {
                $nestedUnenforced = false;
                foreach ($schema['properties'] as $nested) {
                    if (is_array($nested) && $this->laravelUnconsumedKeywords($nested) !== []) {
                        $nestedUnenforced = true;
                        break;
                    }
                }
                if (!$nestedUnenforced) {
                    continue;
                }
            }

            $unconsumed[] = $keyword;
        }

        return $unconsumed;
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
        $hasInterpreter = $this->laravelClassesWithInterpreter[$dtoClassName] ?? false;

        $imports = ['Illuminate\Foundation\Http\FormRequest'];
        if ($hasInterpreter) {
            $imports[] = 'Illuminate\Validation\Validator';
        }
        sort($imports);

        return $this->renderPhpTemplate('formrequest.laravel.php.twig', [
            'namespace' => $namespace,
            'imports' => $imports,
            'className' => $dtoClassName . 'FormRequest',
            'dtoClassName' => $dtoClassName,
            'hasInterpreter' => $hasInterpreter,
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
            && array_key_exists($className, $this->requestPayloadClasses)
            && ($this->laravelClassesWithInterpreter[$className] ?? null) !== null;
    }

    /**
     * Drops the keywords the emitted rules already enforce, so a payload that breaks one of them is
     * reported ONCE. Measured before doing it: a property that lands in the interpreter and also carries
     * a rule-expressible keyword produced two messages for one mistake — `validation.min.string` from
     * Laravel and `f length must be at least 3 characters` from the interpreter.
     *
     * Only the TOP level is pruned. Inside `anyOf`/`oneOf`/`not`/`if`/`then`/`else`/`items`/`contains`
     * the same keyword is part of a branch, not a standalone assertion: dropping `minLength` from an
     * `anyOf` branch would make the branch match values it must reject.
     *
     * A keyword is only dropped when the rules REALLY took it: `uniqueItems` becomes `distinct` for
     * scalar members but not for object ones, so over objects it stays with the interpreter (measured —
     * pruning it unconditionally made a duplicate-object payload pass).
     *
     * @param array<string, mixed> $schema
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function laravelPruneRuleCoveredKeywords(array $schema, array $property): array
    {
        $consumed = [
            ...$this->laravelRuleSpecFor($property)['consumed'],
            ...$this->laravelInertKeywords($schema),
        ];

        // Two different assertions share one keyword name. `$property['required']` is a bool — is the
        // KEY there — and the rule builder consumes it as `present`/`sometimes`. A `required` LIST on
        // the schema is "this object must carry these keys", which no rule expresses. Where the object
        // became a nested DTO its own rules own that; where it stayed a map nothing does, so it has to
        // reach the interpreter.
        if (is_array($schema['required'] ?? null) && !is_array($schema['properties'] ?? null)) {
            $consumed = array_values(array_filter(
                $consumed,
                static fn(string $keyword): bool => $keyword !== 'required',
            ));
        }

        $itemRules = $this->laravelItemRulesForProperty($property);
        foreach ($itemRules as $ruleSet) {
            if (in_array("'distinct'", $ruleSet, true)) {
                $consumed[] = 'uniqueItems';
                break;
            }
        }

        // `items` at the top level is covered by the `field.*` rules, but only when the item schema
        // itself needs nothing more than rules.
        $itemsCoveredByRules = is_array($schema['items'] ?? null)
            && $this->laravelUnconsumedKeywords($schema['items']) === [];

        // A nested object's properties are covered by the dotted rules. The NAMES still have to survive
        // when `additionalProperties`/`unevaluatedProperties` is present — those keywords are defined in
        // terms of which keys `properties` declares — so such an entry keeps its key and loses its
        // rules, exactly as `DtoValidator::withPropertyRulesOwnedByTheDto()` does for a DTO value
        // (E2 in `.todo.codegeneration_symfony_vs_runtime`).
        $keptProperties = [];
        if (is_array($schema['properties'] ?? null)) {
            $namesMatter = array_key_exists('unevaluatedProperties', $schema)
                || array_key_exists('additionalProperties', $schema);

            foreach ($schema['properties'] as $name => $nested) {
                if (is_array($nested) && $this->laravelUnconsumedKeywords($nested) !== []) {
                    $keptProperties[$name] = $this->laravelPruneNestedRuleCoveredKeywords($nested);
                } elseif ($namesMatter) {
                    $keptProperties[$name] = [];
                }
            }
        }

        // Rebuilt rather than unset key by key: the result is what the interpreter must still check,
        // stated positively.
        $pruned = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword === 'properties') {
                if ($keptProperties !== []) {
                    $pruned['properties'] = $keptProperties;
                }
                continue;
            }

            if ($keyword === 'items' && is_array($value)) {
                if ($itemsCoveredByRules) {
                    continue;
                }

                $pruned['items'] = $this->laravelPruneNestedRuleCoveredKeywords($value);
                continue;
            }

            if (in_array($keyword, $consumed, true)) {
                continue;
            }

            $pruned[$keyword] = $value;
        }

        return $pruned;
    }

    /**
     * The same pruning one level down, for a subschema the dotted and wildcard rules also validate
     * (`test.tags`, `tags.*.name`, `rows.*.id`).
     *
     * Without it a nested subschema that carries ONE keyword the rules cannot express was handed to the
     * interpreter WHOLE, so every keyword beside it was checked twice and reported twice: `test.tags.*`
     * failed both `validation.string` and `test.tags[0] must be of type string`.
     *
     * Only `$consumed` is dropped — an annotation keyword (`nullable`, `discriminator`, `readOnly`) is
     * not an assertion and must survive, or the interpreter would start rejecting a legal null.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function laravelPruneNestedRuleCoveredKeywords(array $schema): array
    {
        $consumed = [
            ...$this->laravelSchemaRuleSpec($schema)['consumed'],
            ...$this->laravelInertKeywords($schema),
        ];

        $pruned = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword === 'items' && is_array($value)) {
                if ($this->laravelUnconsumedKeywords($value) === []) {
                    continue;
                }

                $pruned['items'] = $this->laravelPruneNestedRuleCoveredKeywords($value);
                continue;
            }

            if ($keyword === 'properties' && is_array($value)) {
                $namesMatter = array_key_exists('unevaluatedProperties', $schema)
                    || array_key_exists('additionalProperties', $schema);

                $kept = [];
                foreach ($value as $name => $nested) {
                    if (is_array($nested) && $this->laravelUnconsumedKeywords($nested) !== []) {
                        $kept[$name] = $this->laravelPruneNestedRuleCoveredKeywords($nested);
                    } elseif ($namesMatter) {
                        $kept[$name] = [];
                    }
                }

                if ($kept !== []) {
                    $pruned['properties'] = $kept;
                }
                continue;
            }

            if (in_array($keyword, $consumed, true)) {
                continue;
            }

            $pruned[$keyword] = $value;
        }

        return $pruned;
    }

    /**
     * Puts an enum back into the schema the interpreter sees.
     *
     * A branch like `anyOf: [{type: string, enum: [a, b]}, {type: integer}]` becomes the PHP type
     * `ProbeF|int`: the enum moves into the type and the constraint map keeps only `type: string`.
     * Runtime mode is fine — its deserializer casts through the enum and rejects an unknown member —
     * and Symfony mode fails at denormalization. Laravel validates BEFORE anything is hydrated, so
     * without this the members are never checked at all (measured: `"zz"` was accepted).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function laravelRestoreUnionEnumBranches(array $schema, string $phpType): array
    {
        $branches = $schema['anyOf'] ?? $schema['oneOf'] ?? null;
        if (!is_array($branches) || !str_contains($phpType, '|')) {
            return $schema;
        }

        $key = array_key_exists('anyOf', $schema) ? 'anyOf' : 'oneOf';

        foreach (explode('|', str_replace('?', '', $phpType)) as $member) {
            $enumName = $this->shortClassName(trim($member));
            if (!array_key_exists($enumName, $this->enumSchemas)) {
                continue;
            }

            $enum = $this->enumSchemas[$enumName];
            $backingType = $enum['type'] === 'int' ? 'integer' : 'string';
            foreach ($branches as $index => $branch) {
                if (!is_array($branch) || ($branch['type'] ?? null) !== $backingType || array_key_exists('enum', $branch)) {
                    continue;
                }
                $branches[$index]['enum'] = array_values($enum['values']);
                break;
            }
        }

        $schema[$key] = $branches;

        return $schema;
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
            if (($property['isMap'] ?? null) === true || $this->laravelDescribesListOfMaps($property['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * How a nested DTO value is built.
     *
     * A discriminated union is an INTERFACE here, so there is no `fromValidated()` to call: the payload
     * decides which member it is, exactly as the runtime deserializer and Symfony's `#[DiscriminatorMap]`
     * do. Without this the property came out empty and the data was silently lost — the normalization
     * parity suite caught it.
     */
    private function laravelNestedDtoExpression(string $dtoClass, string $rawAccess): string
    {
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

        $arms = [];
        foreach ($mapping as $discriminatorValue => $targetClass) {
            $arms[] = sprintf(
                '%s => %s::fromValidated(%s)',
                $this->laravelStringLiteral($discriminatorValue),
                $this->shortClassName($targetClass),
                $rawAccess,
            );
        }

        return sprintf(
            'match (%s[%s] ?? null) { %s, default => throw new InvalidArgumentException(sprintf(\'Unknown %s "%%s".\', (string)(%s[%s] ?? \'\'))) }',
            $rawAccess,
            $this->laravelStringLiteral($discriminator['propertyName']),
            implode(', ', $arms),
            $discriminator['propertyName'],
            $rawAccess,
            $this->laravelStringLiteral($discriminator['propertyName']),
        );
    }

    /**
     * The union interfaces a class belongs to: the ones Symfony mode already computes, plus the base of
     * every discriminated union whose mapping names this class. Membership has to be declared or the
     * union-typed property that holds it rejects the value at construction.
     *
     * @return array<int, string>
     */
    private function laravelImplementedUnionInterfaces(string $className): array
    {
        $interfaces = $this->symfonyImplementedUnionInterfaces($className, null);

        foreach ($this->discriminatorSchemas as $baseClass => $discriminator) {
            // Only a UNION base is rendered as an interface. A discriminator declared on a plain
            // `type: object` schema is a class (runtime mode gives it children that `extends` it), and
            // declaring `implements` against it is a fatal: "cannot implement … it is not an interface".
            if (!$this->laravelDiscriminatorBaseIsInterface($baseClass)) {
                continue;
            }

            if (in_array($className, array_values($discriminator['mapping']), true)) {
                $interfaces[] = $baseClass;
            }
        }

        return array_values(array_unique($interfaces));
    }

    /**
     * An uploaded file: `format: binary` resolves to `UploadedFile`, and the resolved TYPE is what has
     * to be consulted — the format keyword itself is pruned from the constraints precisely because the
     * type already carries it.
     *
     * @param SchemaProperty $property
     */
    private function laravelIsUploadedFileProperty(array $property): bool
    {
        return $this->shortClassName(ltrim($property['type'], '?')) === 'UploadedFile';
    }

    /**
     * Whether a discriminator base is emitted as an interface — true only for a `oneOf`/`anyOf` schema.
     */
    private function laravelDiscriminatorBaseIsInterface(string $baseClass): bool
    {
        $schema = $this->dtoSchemas[$baseClass] ?? null;

        return is_array($schema)
            && (array_key_exists('oneOf', $schema) || array_key_exists('anyOf', $schema));
    }

    /**
     * What the interpreter must check for one property: its own unenforced keywords, plus — for a nested
     * DTO or a list of them — the same thing computed for the nested schema.
     *
     * A nested class carries its own `rules()`, but nobody calls it: the parent's rules only expand the
     * rule-expressible keywords into dotted paths. So without folding the nested schema in here, a
     * `contains` or a `dependentRequired` declared INSIDE a referenced schema was never enforced —
     * measured on the demo spec, where `test.tags` accepted a list missing its required member.
     *
     * Nested `required` comes along for the ride, which is exactly where it belongs: no Laravel rule can
     * say "required only if the parent has a value".
     *
     * @param SchemaProperty $property
     * @param array<int, string> $visitedClasses guards a self-referential schema
     * @return array<string, mixed>
     */
    private function laravelInterpreterSchemaFor(
        array $property,
        array $visitedClasses = [],
        bool $pruneByOwnRules = true,
        bool $insideRecursiveFold = false,
    ): array {
        $constraints = $this->laravelRestoreUnionEnumBranches(
            $this->laravelConstraintsFor($property),
            $property['type'],
        );
        $schema = $pruneByOwnRules
            ? $this->laravelPruneRuleCoveredKeywords($constraints, $property)
            : $constraints;

        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $nestedClass === null ? $this->laravelDtoItemClass($property) : null;
        $target = $nestedClass ?? $itemClass;

        if ($target === null || $this->laravelIsEnumClass($target)) {
            return $schema;
        }

        // The cycle. Instead of stopping — which silently dropped every assertion below this point —
        // leave a marker the emitted interpreter follows into the folded schema, stored once.
        if (in_array($target, $visitedClasses, true)) {
            // Emitted unconditionally, including while this very fold is still being computed and its
            // entry is the placeholder: a marker inside the fold is how depth 2 and beyond are reached.
            // A fold that turns out empty is dropped from the map afterwards, and the emitted resolver
            // ignores a marker with nothing behind it.
            $this->laravelRecordRecursiveFold($target);

            return $nestedClass !== null
                ? $this->laravelWithNullableNestedMarker($schema, $property, $target)
                : [...$schema, 'items' => ['x-openapi-recurse' => $target]];
        }

        $nested = $this->laravelInterpreterSchemaForClass($target, [...$visitedClasses, $target], $insideRecursiveFold);
        if ($nested === []) {
            return $schema;
        }

        if ($nestedClass !== null) {
            $merged = [...$schema, ...$nested];

            // A nullable nested value must survive: `{"child": null}` is legal, and the interpreter
            // skips a null only when the schema says it may be one.
            if ($property['nullable'] === true || $property['required'] !== true) {
                $merged['nullable'] = true;
            }

            return $merged;
        }

        $schema['items'] = array_key_exists('items', $schema) && is_array($schema['items'])
            ? [...$schema['items'], ...$nested]
            : $nested;

        return $schema;
    }

    /**
     * The recursion marker for a nested-object property, carrying the nullability the folded schema
     * cannot know about.
     *
     * @param array<string, mixed> $schema
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function laravelWithNullableNestedMarker(array $schema, array $property, string $target): array
    {
        $marked = [...$schema, 'x-openapi-recurse' => $target];
        if ($property['nullable'] === true || $property['required'] !== true) {
            $marked['nullable'] = true;
        }

        return $marked;
    }

    /**
     * Folds a self-referential class once, so a marker has something to point at.
     *
     * The placeholder is registered BEFORE the fold is computed: folding re-enters this very class, and
     * the marker branch has to find the key already there or it would recurse without end.
     */
    private function laravelRecordRecursiveFold(string $className): void
    {
        if (array_key_exists($className, $this->laravelRecursiveFolds)) {
            return;
        }

        $this->laravelRecursiveFolds[$className] = [];
        $this->laravelRecursiveFolds[$className] = $this->laravelInterpreterSchemaForClass(
            $className,
            [$className],
            insideRecursiveFold: true,
        );
    }

    /**
     * The object schema the interpreter needs for a generated class: only the properties that still have
     * something unenforced, plus the required list.
     *
     * @param array<int, string> $visitedClasses
     * @return array<string, mixed>
     */
    private function laravelInterpreterSchemaForClass(
        string $className,
        array $visitedClasses,
        bool $insideRecursiveFold = false,
    ): array {
        $properties = [];
        $required = [];

        foreach ($this->getSchemaProperties($className) as $property) {
            if ($property['required'] === true) {
                $required[] = $property['openApiName'];
            }

            // Inside a fold there are no dotted rules at any depth — `laravelNestedRules()` cannot expand
            // a cycle — so nothing may be pruned on their behalf. That is exactly what left a `minimum`
            // inside a recursive schema enforced by nobody.
            $nested = $this->laravelInterpreterSchemaFor(
                $property,
                $visitedClasses,
                pruneByOwnRules: !$insideRecursiveFold,
                insideRecursiveFold: $insideRecursiveFold,
            );
            if ($nested !== []) {
                $properties[$property['openApiName']] = $nested;
            }
        }

        if ($properties === [] && $required === []) {
            return [];
        }

        // No `type` here: the rules already assert `array` for the property, and repeating it would
        // report one mistake twice — and would reject a NULLABLE nested value outright.
        $schema = [];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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

    /**
     * Every path whose schema is `type: object` — a map, a free-form object or a nested DTO — including
     * the ones inside nested DTOs and array items (`author.meta`, `rows.*`, `rows.*.meta`).
     *
     * Read from the SCHEMA, not from the emitted rules: a nested DTO has no `array` rule at all (the PHP
     * type already says it), so a rule-driven version of this missed exactly the DTO-shaped objects —
     * where `[1,2]` used to hydrate an object with every key absent.
     *
     * Laravel has no rule that can tell a JSON object from a JSON array (both decode to a PHP array), so
     * these paths are checked against the raw body, from `withValidator()`.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $visitedClasses guards a self-referential schema
     * @return array<int, string>
     */
    private function laravelObjectShapePaths(array $property, string $prefix, array $visitedClasses = []): array
    {
        $path = $prefix === '' ? $property['openApiName'] : $prefix . '.' . $property['openApiName'];
        $constraints = $this->laravelConstraintsFor($property);
        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);
        $itemDtoClass = $itemClass !== null && !$this->laravelIsEnumClass($itemClass) ? $itemClass : null;

        $paths = [];
        if ($nestedClass !== null || $this->laravelSchemaIsObject($constraints)) {
            $paths[] = $path;
        }

        // A list of objects, and a map whose VALUES are objects, share the wildcard path.
        $items = is_array($constraints['items'] ?? null) ? $constraints['items'] : null;
        $values = is_array($constraints['additionalProperties'] ?? null) ? $constraints['additionalProperties'] : null;
        if (
            $itemDtoClass !== null
            || ($items !== null && $this->laravelSchemaIsObject($items))
            || ($values !== null && $this->laravelSchemaIsObject($values))
        ) {
            $paths[] = $path . '.*';
        }

        $recurseClass = $nestedClass ?? $itemDtoClass;
        if ($recurseClass === null || in_array($recurseClass, $visitedClasses, true)) {
            return $paths;
        }

        $visitedClasses[] = $recurseClass;
        $childPrefix = $nestedClass !== null ? $path : $path . '.*';
        foreach ($this->getSchemaProperties($recurseClass) as $childProperty) {
            foreach ($this->laravelObjectShapePaths($childProperty, $childPrefix, $visitedClasses) as $childPath) {
                $paths[] = $childPath;
            }
        }

        return $paths;
    }

    /**
     * Whether a subschema describes an object and NOTHING else. A union that also allows an array is
     * ambiguous by design, so it is left to the interpreter rather than refused here.
     *
     * @param array<string, mixed> $schema
     */
    private function laravelSchemaIsObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            return array_values(array_filter(
                $type,
                static fn(mixed $member): bool => $member !== 'null',
            )) === ['object'];
        }

        return $type === 'object';
    }
}
