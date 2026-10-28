<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use OpenapiPhpDtoGenerator\Service\DtoValidator;

/**
 * Laravel validation rules for a schema: the `rules()` array, the nested and item rules, and the
 * interpreter constraints left for what no rule expresses.
 *
 * Laravel mode and laravel-data mode validate through the same Laravel validator, so both emit these
 * rules. They lived in {@see RendersLaravelDto}, and laravel-data called into that mode for them; now
 * both modes call this trait and neither calls the other.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 *
 * @phpstan-ignore trait.unused
 */
trait RendersLaravelValidation
{
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
     * The deepest `.*` HOP the dotted rules reach: `field.*` is hop 1, `field.*.*` is hop 2.
     *
     * A guard against a pathological schema, not a limit anyone reaches — but both halves count in the
     * same unit on purpose, because they have to agree exactly:
     *
     *  - when only the rule generator had a limit, the coverage test kept recursing to the bottom and
     *    reported the keywords down there as "a rule covers this". Neither half then enforced them —
     *    measured on a list nested twelve deep, runtime rejected the out-of-range value and Laravel
     *    accepted it;
     *  - when the two counted from different places, the hops in between were claimed by BOTH, and the
     *    sweep showed Laravel reporting one mistake twice at depths 8 and 9.
     */
    private const int LARAVEL_MAX_DOTTED_RULE_HOP = 9;

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

    /**
     * Keywords that assert nothing — documentation, defaults and access flags. They are neither emitted
     * as rules nor handed to the interpreter, so they must not make a schema "unenforced".
     *
     * @var array<int, string>
     */
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
     * The object-wide keywords this mode carries. `properties` and `required` are excluded: the rule
     * map owns them, and repeating them here would report every violation twice.
     */
    private const array LARAVEL_OBJECT_LEVEL_KEYWORDS = [
        'minProperties',
        'maxProperties',
        'dependentRequired',
        'dependentSchemas',
        'propertyNames',
        'patternProperties',
        'not',
        'if',
        'then',
        'else',
    ];

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
        if ($this->propertyIsRequiredOnInput($property)) {
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
        $nullable = $this->laravelSchemaDeclaresNullable($property);

        // OpenAPI `required` means "the key is there". Laravel's `required` means "present and NOT
        // EMPTY": it rejects null, `""`, `[]` and `{}`. Those are all legal values for a required
        // property, so the faithful translation is `present` — the key must exist, its value is the
        // type rules' business. Measured through the real validator against runtime mode:
        // `{"s":""}`, `{"list":[]}`, `{"map":{}}` and `{"child":null}` were all rejected under
        // `required` while the other two modes accepted them.
        // A readOnly property is required OF A RESPONSE, never of a request. `present` here demanded
        // that the client send a server-owned field, so it is `sometimes` like any optional key.
        $rules = [$this->propertyIsRequiredOnInput($property) ? "'present'" : "'sometimes'"];
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
        // A nullable INLINE enum goes to the interpreter: its member list decides whether null is one
        // of them, which `nullable` + `Rule::enum()` cannot say. A nullable `$ref` enum has no inline
        // list to hand over, so `Rule::enum()` stays its only member check.
        if ($enumClass !== null && (!$nullable || !array_key_exists('enum', $constraints))) {
            // `Rule::enum()` pins the backing type AND the members in one first-party rule; the
            // generated enum is the single source of the allowed values.
            // Fully qualified: a nested or inlined rule names an enum of ANOTHER namespace (a shared
            // file's), which a short name resolved into the wrong one — "class not found", a 500 on
            // the first `rules()` call. The emitted file turns it into an import.
            $rules[] = sprintf('Rule::enum(\%s::class)', $this->laravelEnumFqcn($enumClass));

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
            $prefix = $this->laravelPathSegment($property['openApiName']);
        } elseif ($itemClass !== null && !$this->laravelIsEnumClass($itemClass)) {
            $prefix = $this->laravelPathSegment($property['openApiName']) . '.*';
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
            $path = $prefix . '.' . $this->laravelPathSegment($nestedProperty['openApiName']);
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
     * An ITEM's rules: its schema's, and `nullable` first when that schema admits null. At the
     * property level presence decides that; an item has no presence, so `[null, "a"]` against
     * `type: [string, null]` failed the item's `string` rule.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function laravelItemRules(array $schema): array
    {
        $rules = $this->laravelRulesForSchema($schema);

        return $rules !== [] && $this->schemaAllowsNull($schema) ? ["'nullable'", ...$rules] : $rules;
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
            // `list` alone: it is `is_array() && array_is_list()`, so `array` beside it only doubled
            // the message for a scalar — "must be an array" and "must be a list" for one mistake. A
            // JSON object is `array` only — the object-vs-list distinction is the interpreter's job.
            'array' => "'list'",
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

        if (array_key_exists('pattern', $schema) && is_string($schema['pattern']) && $this->patternCompiles($schema['pattern'])) {
            // Array form + explicit delimiters: a pattern containing `|` must not split the list.
            $rules[] = $this->phpStringLiteral('regex:' . DtoValidator::delimitPattern($schema['pattern']));
            $consumed[] = 'pattern';
        }

        if (array_key_exists('multipleOf', $schema) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))) {
            $rules[] = sprintf("'multiple_of:%s'", $this->laravelNumberLiteral($schema['multipleOf']));
            $consumed[] = 'multipleOf';
        }

        // const and inline enum need JSON equality; Rule::in coerces scalars and loses structures.

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
        // (`laravelUnconsumedKeywords()` picks it up, through `laravelInterpreterSchemaFor()`).
        if ($itemClass !== null) {
            if ($this->laravelIsEnumClass($itemClass)) {
                if (!$this->laravelItemEnumIsRuleChecked($property)) {
                    return [];
                }

                // The same rule a scalar enum property gets. Left to the interpreter, the members were
                // compared strictly, so `?levels[]=2` — a query value is always a string — was refused
                // by an int-backed enum that lists 2.
                $enumRule = sprintf('Rule::enum(\%s::class)', $this->laravelEnumFqcn($itemClass));

                return [
                    $this->laravelPathSegment($property['openApiName']) . '.*' => $this->laravelItemsNullable($property)
                        ? ["'nullable'", $enumRule]
                        : [$enumRule],
                ];
            }

            // The item ITSELF must be an object. With no rule on `f.*` a string or a null item reached
            // `fromValidated()` — which skipped it, so `["x"]` hydrated to `[]` without a word.
            return [
                $this->laravelPathSegment($property['openApiName']) . '.*' => $this->laravelItemsNullable($property)
                    ? ["'nullable'", "'array'"]
                    : ["'array'"],
            ];
        }

        $rules = is_array($itemSchema) ? $this->laravelItemRules($itemSchema) : [];

        // `uniqueItems` is NOT emitted as `distinct`, and that is measured rather than assumed. The rule
        // works — but it reports per offending ELEMENT, so one duplicated pair produced TWO
        // `validation.distinct` messages where every other mode reports the array's single violation
        // once. The keyword stays with the interpreter, which already owned it for object items because
        // `distinct` cannot compare those: one owner, one message, the same sentence in all five modes.
        //
        // The cost is the native translatable string for the scalar case, and it is the right trade here:
        // a rule that is right about WHETHER and wrong about HOW MANY breaks the invariant every
        // `InterpreterMessageParityTest` case exists to protect.
        unset($unique);

        $itemPath = $this->laravelPathSegment($property['openApiName']) . '.*';
        $paths = $rules === [] ? [] : [$itemPath => $rules];

        // And a container INSIDE the container. Laravel's dotted paths go as deep as the schema does;
        // stopping at one `.*` said `matrix.*` is an array and then checked its members with nothing —
        // while `laravelUnconsumedKeywords()` was already pruning those keywords from the interpreter
        // on the grounds that a rule covered them. Two halves that disagreed; the rules were the half
        // that was missing.
        foreach ($this->laravelDeepItemRules($itemSchema, $itemPath) as $deepPath => $deepRules) {
            $paths[$deepPath] = $deepRules;
        }

        return $paths;
    }

    /**
     * The `x.*.*` rules and deeper: one entry per container level the schema declares.
     *
     * @param array<string, mixed>|null $schema the container whose items these are
     * @return array<string, array<int, string>>
     */
    private function laravelDeepItemRules(?array $schema, string $pathPrefix, int $hop = 2): array
    {
        // `$hop` is the hop the path about to be built would occupy, counted the way
        // `LARAVEL_MAX_DOTTED_RULE_HOP` counts — the caller starts at 2 because it has already emitted
        // `field.*` itself.
        if ($schema === null || $hop > self::LARAVEL_MAX_DOTTED_RULE_HOP) {
            return [];
        }

        $nested = match (true) {
            is_array($schema['items'] ?? null) => $schema['items'],
            is_array($schema['additionalProperties'] ?? null) => $schema['additionalProperties'],
            default => null,
        };
        if ($nested === null) {
            return [];
        }

        $path = $pathPrefix . '.*';
        $rules = $this->laravelItemRules($nested);
        $paths = $rules === [] ? [] : [$path => $rules];

        // The item's own PROPERTIES, where it is an object no class was generated for — a `$ref` two
        // containers deep, inlined into the constraints by
        // `GenerateDtoCommand::inlineNestedContainerValidation()`. Without these the prune took
        // `properties` away from the interpreter (a rule covers them) while no rule reached them, so
        // `tagRows.*.*.id` below its `minimum` was accepted by both halves.
        //
        // `present`/`sometimes` are dropped for the same reason `laravelNestedRules()` drops them: a
        // nested property's PRESENCE is not expressible as a rule — both candidates fire when the
        // parent is absent or null — so `required` stays with the interpreter.
        foreach ($nested['properties'] ?? [] as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || !is_array($propertySchema)) {
                continue;
            }

            $propertyPath = $path . '.' . $propertyName;
            $propertyRules = array_values(array_filter(
                $this->laravelRulesForSchema($propertySchema),
                static fn(string $rule): bool => $rule !== "'present'" && $rule !== "'sometimes'",
            ));
            if ($propertyRules !== []) {
                $paths[$propertyPath] = $propertyRules;
            }

            foreach ($this->laravelDeepItemRules($propertySchema, $propertyPath, $hop + 1) as $p => $r) {
                $paths[$p] = $r;
            }
        }

        foreach ($this->laravelDeepItemRules($nested, $path, $hop + 1) as $deepPath => $deepRules) {
            $paths[$deepPath] = $deepRules;
        }

        return $paths;
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
        // Laravel casts numeric values to strings before comparing their size. PHP's precision
        // setting rounds floats there, so numeric bounds on non-integer values stay in the callback.
        $type = $schema['type'] ?? null;
        $type = is_array($type) ? $this->laravelSoleNonNullType($type) : $type;
        if ($minKey === 'minimum' && $type !== 'integer') {
            return ['rules' => [], 'consumed' => []];
        }

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
            // BOTH offset spellings, and that is not redundancy. Laravel's `date_format` does not
            // merely parse: it formats the parsed value back and compares the STRING. `P` writes a UTC
            // offset as `+00:00` and `p` writes it as `Z`, so each pattern round-trips exactly one of
            // them and refuses the other. With `P` alone — which is what this emitted until 2.15.40 —
            // `2026-01-02T03:04:05Z` was refused, the commonest spelling there is, on a payload every
            // other mode accepts. The canonical list this claims to mirror
            // (`GeneratedDtoInterface::DATE_TIME_FORMATS`) uses `p`, and the deserializer only PARSES
            // with it, where either letter reads either spelling — which is why the disagreement could
            // sit here unnoticed.
            //
            // And `.v` beside `.u` for the same reason: `.u` formats back to SIX digits, so `.123Z` —
            // what JavaScript's `toISOString()` writes — was refused while every other mode accepted it.
            // `.v` round-trips exactly three. One, two, four or five digits still need `.u`'s six.
            'date-time', 'datetime' => "'date_format:Y-m-d\\TH:i:sP,Y-m-d\\TH:i:s.uP,Y-m-d\\TH:i:s.vP,"
                . "Y-m-d\\TH:i:sp,Y-m-d\\TH:i:s.up,Y-m-d\\TH:i:s.vp,Y-m-d H:i:s,Y-m-d\\TH:i:s'",
            default => null,
        };
    }

    private function laravelNumberLiteral(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        $rendered = json_encode($value);

        return is_string($rendered) ? $rendered : (string)$value;
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
        return $rules === [] ? '[]' : 'self::withBlankStringValidation([' . implode(', ', $rules) . '])';
    }

    /**
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function laravelImportsForProperty(array $property, string $namespace, string $className): array
    {
        $imports = [];

        if ($this->temporalGetterBody($property) !== null || $this->temporalArrayGetterBody($property) !== null) {
            $imports[] = 'DateTimeImmutable';
        }
        if ($this->laravelIsUploadedFileProperty($property)) {
            // Laravel's own UploadedFile extends this one, so the Symfony class is the right hint.
            $imports[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
        }
        if ($this->laravelRulesNeedRuleFacade($property)) {
            $imports[] = 'Illuminate\Validation\Rule';
        }
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL && $this->laravelInlineDiscriminator($property) !== null) {
            $imports[] = 'InvalidArgumentException';
        }

        foreach ([$this->laravelEnumClass($property), $this->laravelDtoClass($property), $this->laravelDtoItemClass($property)] as $referenced) {
            if ($referenced !== null) {
                $this->appendImportForClass($imports, $referenced, $namespace, $className);
            }

            // The hydrator throws where such a union would have to be built — but only laravel mode has
            // a hydrator of ours. laravel-data shares this import collector and hydrates through spatie,
            // so importing there would leave a `use` nothing uses.
            // {@see laravelUnhydratableUnionThrow()}
            if (
                $referenced !== null
                && $this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL
                && $this->isUnhydratableUnionClass($referenced)
            ) {
                $imports[] = 'RuntimeException';
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
        // A MAP counts, not only a list: `array<string, Tag>` carries the same class in the same
        // position as `array<Tag>`, and every caller asks the same question — "what class sits
        // inside this container". Matching lists alone left a map of DTOs unhydrated AND
        // unvalidated, with the docblock still promising the class. The value type is the last
        // segment either way; a nested generic cannot match, because `<` is not in the class
        // character set.
        if (preg_match('/^\??array<(?:[A-Za-z_][A-Za-z0-9_]*,\s*)?\??([A-Za-z_][A-Za-z0-9_\\\]*)>$/', $property['type'], $matches) !== 1) {
            return null;
        }

        $itemClass = $this->shortClassName($matches[1]);

        return array_key_exists($itemClass, $this->dtoSchemas) || $this->laravelIsEnumClass($itemClass)
            ? $itemClass
            : null;
    }

    /**
     * Whether the container's items admit null: `array<?X>` or `array<string, ?X>`.
     *
     * @param SchemaProperty $property
     */
    private function laravelItemsNullable(array $property): bool
    {
        return preg_match('/^\??array<(?:[A-Za-z_][A-Za-z0-9_]*,\s*)?\?/', $property['type']) === 1;
    }

    /**
     * A property name as ONE segment of a Laravel path. Laravel reads every `.` of a rule key as a step
     * into a nested array, and `\.` as a literal dot — so `invoice.note` validates the key the client
     * sent rather than `{"invoice": {"note": …}}`.
     */
    private function laravelPathSegment(string $openApiName): string
    {
        return str_replace('.', '\.', $openApiName);
    }

    /**
     * The query and path parameters to read as the document serializes them before the rules run, one
     * entry per property: its wire name, whether it is a list, the delimiter its `style` joins the items
     * with (null for `form` exploded, where a lone value is a list of one), and what a string item or
     * value stands for.
     *
     * Everything there arrives as a string. Laravel's `boolean` rule refuses `"true"`, which is how
     * OpenAPI writes a boolean in a query; its `list` rule refuses `?ids=1,2`, which is how
     * `explode: false` writes a list; and nothing turned `?levels[]=2` into the int an `array<int>` or an
     * int-backed enum promises. Symfony mode reads them this way since 2.15.76.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{wireName: string, list: bool, delimiter: ?string, kind: string}>
     */
    private function laravelParameterReads(array $properties): array
    {
        $reads = [];
        foreach ($properties as $property) {
            if (($property['inQuery'] ?? false) !== true && ($property['inPath'] ?? false) !== true) {
                continue;
            }

            $type = ltrim($property['type'], '?');
            if (preg_match('/^array<\??([A-Za-z_\\\][\w\\\]*)(?:\|null)?>$/', $type, $itemMatch) !== 1) {
                if ($type === 'bool') {
                    $reads[] = ['wireName' => $property['openApiName'], 'list' => false, 'delimiter' => null, 'kind' => 'bool'];
                }
                continue;
            }

            $style = $property['parameterStyle'] ?? (($property['inPath'] ?? false) === true ? 'simple' : 'form');
            $explode = $property['parameterExplode'] ?? $style === 'form';
            $delimiter = match ($style) {
                'form' => $explode ? null : ',',
                'spaceDelimited' => ' ',
                'pipeDelimited' => '|',
                'simple' => ',',
                default => false,
            };
            if ($delimiter === false) {
                continue;
            }

            $itemType = $itemMatch[1];
            $enum = $this->enumSchemas[$this->shortClassName($itemType)] ?? null;
            $kind = match (true) {
                in_array($itemType, ['int', 'float', 'bool', 'string'], true) => $itemType,
                $enum !== null => $enum['type'] === 'int' ? 'int' : 'string',
                default => null,
            };
            if ($kind === null) {
                continue;
            }

            $reads[] = ['wireName' => $property['openApiName'], 'list' => true, 'delimiter' => $delimiter, 'kind' => $kind];
        }

        return $reads;
    }

    /**
     * The key type `rules()` declares. A property named like an integer — `2` — is an INT key once PHP
     * has the array, so `array<string, …>` was a lie static analysis reported in the generated class.
     *
     * @param array<int, string> $paths
     */
    private function laravelRulesKeyType(array $paths): string
    {
        foreach ($paths as $path) {
            if ((string)(int)$path === $path) {
                return 'array-key';
            }
        }

        return 'string';
    }

    private function laravelIsEnumClass(string $className): bool
    {
        return array_key_exists($className, $this->enumSchemas);
    }

    private function laravelEnumFqcn(string $enumClass): string
    {
        $short = $this->shortClassName($enumClass);
        $namespace = $this->enumNamespaces[$short] ?? $this->baseNamespace;

        return ltrim($namespace . '\\' . $short, '\\');
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

    /**
     * The emitted OpenAPI interpreter, packaged for this mode: everything no rule vocabulary can express
     * — `oneOf`, `anyOf`, `allOf`, `not`, `if`/`then`/`else`, `contains`, `prefixItems`, `unevaluated*`,
     * `content*`, `propertyNames`, `patternProperties`, `dependentSchemas`, `discriminator`.
     *
     * There is ONE implementation of it, generated by `RendersSchemaInterpreter::renderInterpreterMethods()`
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
     * @param array{fields: array<string, mixed>, object: array<string, mixed>} $constraints
     * @param array<string, mixed> $recursiveSchemas self-referential schemas a marker re-enters
     * @return array{consts: string, methods: string, imports: array<int, string>}
     */
    private function renderLaravelInterpreterBlock(array $constraints, array $recursiveSchemas = []): array
    {
        if ($constraints['fields'] === [] && $constraints['object'] === []) {
            return ['consts' => '', 'methods' => '', 'imports' => []];
        }

        // The envelope separates payload names from root assertions. Its slots are storage, not
        // schema keywords, so expand each schema before handing the envelope to the shared renderer.
        foreach ($constraints['fields'] as $property => $schema) {
            if (is_array($schema)) {
                $constraints['fields'][$property] = $this->expandBooleanSubschemasForInterpreter($schema);
            }
        }
        $constraints['object'] = $this->expandBooleanSubschemasForInterpreter($constraints['object']);

        // No enum/temporal normalization: the validator runs on the raw request payload, where those
        // values are still the scalars the client sent.
        $block = $this->renderInterpreterBlock(
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
     * @return array{fields: array<string, mixed>, object: array<string, mixed>}
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

        // Root assertions and property assertions occupy separate slots. Every JSON property name
        // is legal here, including the old sentinel and the names of these storage slots themselves.
        return [
            'fields' => $constraints,
            'object' => $this->laravelObjectLevelConstraints($ownerClass),
        ];
    }

    /**
     * The object-wide keywords of a class schema, pruned of what the rule map already covers.
     *
     * @return array<string, mixed>
     */
    private function laravelObjectLevelConstraints(string $className): array
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        if ($schema === []) {
            return [];
        }

        $extracted = $this->extractValidationConstraints($schema);
        $objectLevel = [];
        foreach (self::LARAVEL_OBJECT_LEVEL_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $extracted)) {
                $objectLevel[$keyword] = $extracted[$keyword];
            }
        }

        return $this->laravelWithClosedObjectFlag($schema, $objectLevel);
    }

    /**
     * `additionalProperties: false` written at the TOP of a component, carried into the object slot.
     *
     * It used to be carried nowhere. Nested one level down the same keyword was enforced — that schema
     * reaches the interpreter as a property's own — so a document closing its root object was refused
     * by runtime and accepted here, silently, with nothing in the emitted class mentioning it.
     *
     * The declared NAMES have to travel with the flag: the interpreter decides what counts as
     * additional from the `properties` of the same schema, so the flag alone would make every key
     * additional and refuse every payload. Their schemas are deliberately EMPTY — Laravel's rule map
     * already validates each property, and repeating it here is how one violation becomes two
     * messages, which is the same reason `properties` is otherwise kept out of this slot.
     *
     * `unevaluatedProperties: false` closes the object the same way, and is spelled as the keyword the
     * document used so the message says what the reader wrote.
     *
     * @param array<string, mixed> $schema the class schema as the document declared it
     * @param array<string, mixed> $objectLevel the slot built from the object-level keywords
     * @return array<string, mixed>
     */
    private function laravelWithClosedObjectFlag(array $schema, array $objectLevel): array
    {
        $closesWithAdditional = ($schema['additionalProperties'] ?? null) === false;
        $closesWithUnevaluated = ($schema['unevaluatedProperties'] ?? null) === false;

        if (!$closesWithAdditional && !$closesWithUnevaluated) {
            return $objectLevel;
        }

        $declaredProperties = $schema['properties'] ?? [];
        $declaredNames = [];
        if (is_array($declaredProperties)) {
            foreach (array_keys($declaredProperties) as $declaredName) {
                $declaredNames[(string)$declaredName] = [];
            }
        }

        $objectLevel['properties'] = $declaredNames;

        if ($closesWithAdditional) {
            $objectLevel['additionalProperties'] = false;
        }

        if ($closesWithUnevaluated) {
            $objectLevel['unevaluatedProperties'] = false;
        }

        return $objectLevel;
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
    private function laravelUnconsumedKeywords(array $schema, int $hop = 0): array
    {
        // An annotation asserts nothing at any depth. A RULE, on the other hand, only covers what
        // `laravelDeepItemRules()` actually emitted — past its limit there is no dotted path, so
        // nothing down here is covered and every assertion belongs to the interpreter.
        $consumed = $this->laravelInertKeywords($schema);
        if ($hop <= self::LARAVEL_MAX_DOTTED_RULE_HOP) {
            $consumed = [...$consumed, ...$this->laravelSchemaRuleSpec($schema)['consumed']];
        }
        $unconsumed = [];

        foreach (array_keys($schema) as $keyword) {
            if (in_array($keyword, $consumed, true) || in_array($keyword, self::LARAVEL_ANNOTATION_KEYWORDS, true)) {
                continue;
            }

            // A container is unenforced only when its CONTENTS are.
            if ($keyword === 'items' || $keyword === 'additionalProperties') {
                if (is_array($schema[$keyword]) && $this->laravelUnconsumedKeywords($schema[$keyword], $hop + 1) === []) {
                    continue;
                }
            }

            if ($keyword === 'properties' && is_array($schema['properties'])) {
                $nestedUnenforced = false;
                foreach ($schema['properties'] as $nested) {
                    if (is_array($nested) && $this->laravelUnconsumedKeywords($nested, $hop) !== []) {
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

        // `nullable` qualifies `type`: the rule covers it only together with a type rule. A `type` left
        // to the interpreter (an enum-typed property gets no `string` rule) needs its `nullable` too,
        // or the interpreter's type check refuses the null the `nullable` rule just let through.
        if (array_key_exists('type', $schema) && !in_array('type', $consumed, true)) {
            $consumed = array_values(array_filter(
                $consumed,
                static fn(string $keyword): bool => $keyword !== 'nullable',
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
        // itself needs nothing more than rules. `additionalProperties` is the MAP spelling of the same
        // thing and reaches the same `field.*` rules — left out of this test, a map's value schema was
        // handed to the interpreter whole and every mistake in it was reported twice: once as
        // `validation.string`, once as `plainMap.k must be of type string`.
        $containerCoveredByRules = [];
        foreach (['items', 'additionalProperties'] as $containerKey) {
            // Depth 1: the ITEM is one container hop from the property, and coverage is judged by
            // distance from the PROPERTY — that is where the dotted path starts counting.
            $containerCoveredByRules[$containerKey] = is_array($schema[$containerKey] ?? null)
                && $this->laravelUnconsumedKeywords($schema[$containerKey], 1) === [];
        }

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

            if (($keyword === 'items' || $keyword === 'additionalProperties') && is_array($value)) {
                if ($containerCoveredByRules[$keyword]) {
                    continue;
                }

                $pruned[$keyword] = $this->laravelPruneNestedRuleCoveredKeywords($value, 1);
                continue;
            }

            // An `allOf` BRANCH describes the same value as the property that carries it, so the same
            // dotted rules cover it and the same pruning applies. Copied whole — which is what happened
            // until 2.15.11, when the interpreter learnt the keyword — a branch repeated every check
            // the rules already make: `bag.test` reported `validation.string` AND
            // `bag.test must be of type string`. What no rule expresses (a `required` list inside the
            // branch) survives the pruning and is reported once.
            if ($keyword === 'allOf' && is_array($value)) {
                // Unfolded scalar branches have independent assertions, without native rules.
                if ($this->allOfHasPlainBranches($value)) {
                    $pruned[$keyword] = $value;
                    continue;
                }

                $branches = [];
                foreach ($value as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }

                    $prunedBranch = $this->laravelPruneNestedRuleCoveredKeywords($branch);
                    if ($prunedBranch !== []) {
                        $branches[] = $prunedBranch;
                    }
                }

                if ($branches !== []) {
                    $pruned[$keyword] = $branches;
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
    private function laravelPruneNestedRuleCoveredKeywords(array $schema, int $hop = 0): array
    {
        // Same unit and same rule as the coverage test: past the last hop the dotted rules reach, a
        // rule covers nothing, so every assertion from here down has to survive into the interpreter.
        $consumed = $this->laravelInertKeywords($schema);
        if ($hop <= self::LARAVEL_MAX_DOTTED_RULE_HOP) {
            $consumed = [...$consumed, ...$this->laravelSchemaRuleSpec($schema)['consumed']];
        }

        $pruned = [];
        foreach ($schema as $keyword => $value) {
            if (($keyword === 'items' || $keyword === 'additionalProperties') && is_array($value)) {
                if ($this->laravelUnconsumedKeywords($value, $hop + 1) === []) {
                    continue;
                }

                $pruned[$keyword] = $this->laravelPruneNestedRuleCoveredKeywords($value, $hop + 1);
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
            // A `$ref` branch arrives with its members already spelled out; a sibling string branch
            // given them too would stop matching what it was written for.
            $members = array_values($enum['values']);
            foreach ($branches as $branch) {
                if (is_array($branch) && ($branch['enum'] ?? null) === $members) {
                    continue 2;
                }
            }
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
     * A union with an enum member — `oneOf: [$ref Palette, string, integer]` is `Palette|string|int` — takes
     * the enum case when the value is one of its members, and the scalar otherwise. Handed over as it
     * came, `"crimson"` stayed a string where the type promised `Palette`.
     *
     * The validator has already decided which branch the value matched, so `tryFrom()` on the backing
     * type is the whole decision; a value of the other scalar type is never passed to it, which under
     * strict types would be a TypeError.
     */
    private function laravelWithUnionEnumCast(string $declaredType, string $raw, string $fallback): string
    {
        $members = explode('|', str_replace('?', '', $declaredType));
        if (count($members) < 2) {
            return $fallback;
        }

        $casts = [];
        foreach ($members as $member) {
            $enumName = $this->shortClassName(trim($member));
            if (!array_key_exists($enumName, $this->enumSchemas)) {
                continue;
            }
            $casts[] = sprintf(
                '(%s(%s) ? %s::tryFrom(%s) : null)',
                $this->enumSchemas[$enumName]['type'] === 'int' ? 'is_int' : 'is_string',
                $raw,
                $enumName,
                $raw,
            );
        }

        return $casts === [] ? $fallback : implode(' ?? ', [...$casts, $fallback]);
    }

    /**
     * The discriminator of a `oneOf` written inline on the property — `Circle|Square`, no base class to
     * carry it — once `laravelDiscriminatorDispatch()` has turned it into checks per variant.
     *
     * @param SchemaProperty $property
     * @return array{propertyName: string, mapping: array<string, string>}|null
     */
    private function laravelInlineDiscriminator(array $property): ?array
    {
        $constraints = $property['constraints'] ?? [];
        $propertyName = $constraints['x-discriminator-property'] ?? null;
        $mapping = $constraints['x-discriminator-map'] ?? null;
        if (!is_string($propertyName) || !is_array($mapping) || array_key_exists('oneOf', $constraints)) {
            return null;
        }

        $classes = [];
        foreach ($mapping as $value => $class) {
            if (is_string($class)) {
                $classes[(string)$value] = $class;
            }
        }

        return $classes === [] ? null : ['propertyName' => $propertyName, 'mapping' => $classes];
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
        $interfaces = $this->implementedUnionInterfaces($className, null);

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
     * Whether a discriminator base is emitted as an interface: a `oneOf`/`anyOf` schema, and in Laravel
     * mode the object base of the allOf pattern too.
     */
    private function laravelDiscriminatorBaseIsInterface(string $baseClass): bool
    {
        $schema = $this->dtoSchemas[$baseClass] ?? null;

        return (is_array($schema) && (array_key_exists('oneOf', $schema) || array_key_exists('anyOf', $schema)))
            || $this->laravelIsAllOfDiscriminatorBase($baseClass);
    }

    /**
     * The allOf pattern: an object base with a discriminator whose variants `allOf` it. Generated as a
     * class of its own it was a type no variant belonged to — a property typed with it could not hold
     * a variant, and a body bound to it lost every field a variant adds. It is the interface the
     * variants implement instead, as in Symfony mode, and hydration picks the variant by the
     * discriminator. laravel-data makes it the abstract morph base its variants extend, as it does for a
     * `oneOf` — kept a plain class there, a property typed with it hydrated the base and dropped every
     * field a variant adds.
     */
    private function laravelIsAllOfDiscriminatorBase(string $className): bool
    {
        return in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true)
            && $this->isAllOfDiscriminatorBase($className);
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
            schema: $this->laravelConstraintsFor($property),
            phpType: $property['type'],
        );
        $schema = $pruneByOwnRules
            ? $this->laravelPruneRuleCoveredKeywords($constraints, $property)
            : $constraints;

        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $nestedClass === null ? $this->laravelDtoItemClass($property) : null;
        $target = $nestedClass ?? $itemClass;

        if ($target !== null && $itemClass !== null && $this->laravelIsEnumClass($target)) {
            return $this->laravelItemEnumIsRuleChecked($property)
                ? $this->laravelWithoutItemEnumKeywords($schema, $property)
                : $this->laravelWithItemEnumMembers($schema, $property, $target);
        }

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
                : [...$schema, $this->laravelContainerValueKey($property) => ['x-openapi-recurse' => $target]];
        }

        $nested = $this->laravelInterpreterSchemaForClass($target, [...$visitedClasses, $target], $insideRecursiveFold);
        if ($nested === []) {
            return $schema;
        }

        if ($nestedClass !== null) {
            $merged = [...$schema, ...$nested];

            // A nullable nested value must survive: `{"child": null}` is legal, and the interpreter
            // skips a null only when the schema says it may be one.
            if ($property['nullable'] === true || !$this->propertyIsRequiredOnInput($property)) {
                $merged['nullable'] = true;
            }

            return $merged;
        }

        // A map's values live under `additionalProperties`, a list's under `items`. Writing `items`
        // for both put a map's value schema where the walk only looks for a LIST — `array_is_list()`
        // is false for a populated map, so every keyword folded in from the value class was skipped
        // in silence.
        $containerKey = $this->laravelContainerValueKey($property);
        $schema[$containerKey] = array_key_exists($containerKey, $schema) && is_array($schema[$containerKey])
            ? [...$schema[$containerKey], ...$nested]
            : $nested;

        return $schema;
    }

    /**
     * Where the VALUE schema of a container belongs: `additionalProperties` for a map, `items` for a
     * list. The emitted walk reads the two through different branches.
     *
     * @param SchemaProperty $property
     */
    private function laravelContainerValueKey(array $property): string
    {
        return ($property['isMap'] ?? null) === true ? 'additionalProperties' : 'items';
    }

    /**
     * Whether the items of an enum-valued container are checked by `Rule::enum()` on `field.*`. A
     * nullable INLINE enum stays with the interpreter, as it does for a property: its member list
     * decides whether null is one of them, which `nullable` + `Rule::enum()` cannot say.
     *
     * @param SchemaProperty $property
     */
    private function laravelItemEnumIsRuleChecked(array $property): bool
    {
        if (!$this->laravelItemsNullable($property)) {
            return true;
        }

        $items = $this->laravelConstraintsFor($property)[$this->laravelContainerValueKey($property)] ?? null;

        return !is_array($items) || !array_key_exists('enum', $items);
    }

    /**
     * The container schema with what `Rule::enum()` on `field.*` already checks taken out of its items.
     *
     * @param array<string, mixed> $schema
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function laravelWithoutItemEnumKeywords(array $schema, array $property): array
    {
        $key = $this->laravelContainerValueKey($property);
        if (!is_array($schema[$key] ?? null)) {
            return $schema;
        }

        $kept = [];
        foreach ($schema[$key] as $keyword => $value) {
            if (!in_array($keyword, ['type', 'enum', 'nullable'], true)) {
                $kept[$keyword] = $value;
            }
        }

        $pruned = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== $key) {
                $pruned[$keyword] = $value;
            } elseif ($kept !== []) {
                $pruned[$keyword] = $kept;
            }
        }

        return $pruned;
    }

    /**
     * The members of an enum-valued container's items, for the interpreter. A local `$ref` arrives here
     * already resolved, members included; one into ANOTHER file does not, and there was no rule on
     * `field.*` either — so `["bad"]` passed validation and `fromValidated()` died on `Enum::from()`, a
     * 500. The generated enum knows its members, wherever its schema lives.
     *
     * @param array<string, mixed> $schema
     * @param SchemaProperty $property
     * @return array<string, mixed>
     */
    private function laravelWithItemEnumMembers(array $schema, array $property, string $enumClass): array
    {
        $key = $this->laravelContainerValueKey($property);
        $items = is_array($schema[$key] ?? null) ? $schema[$key] : [];
        if (array_key_exists('enum', $items)) {
            return $schema;
        }

        $members = array_values($this->enumSchemas[$enumClass]['values']);
        if ($this->laravelItemsNullable($property)) {
            $members[] = null;
        }
        $schema[$key] = [...$items, 'enum' => $members];

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
        if ($property['nullable'] === true || !$this->propertyIsRequiredOnInput($property)) {
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
            className: $className,
            visitedClasses: [$className],
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
        if ($this->laravelIsAllOfDiscriminatorBase($className)) {
            return $this->laravelAllOfDiscriminatorConstraints($className, $visitedClasses);
        }

        $definition = $this->dtoSchemas[$className] ?? [];
        if (array_key_exists('oneOf', $definition) || array_key_exists('anyOf', $definition)) {
            // Dotted rules cannot select a union member. Keep each branch's complete schema,
            // including its enum, required keys and closed-object constraints, in the interpreter.
            return $this->extractValidationConstraints($this->inlineNestedContainerValidation(
                schema: $definition,
                belowMaterialization: true,
            ));
        }

        $properties = [];
        $required = [];

        foreach ($this->getSchemaProperties($className) as $property) {
            if ($this->propertyIsRequiredOnInput($property)) {
                $required[] = $property['openApiName'];
            }

            // Inside a fold there are no dotted rules at any depth — `laravelNestedRules()` cannot expand
            // a cycle — so nothing may be pruned on their behalf. That is exactly what left a `minimum`
            // inside a recursive schema enforced by nobody.
            $nested = $this->laravelInterpreterSchemaFor(
                property: $property,
                visitedClasses: $visitedClasses,
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
     * A value typed with an allOf base is one of its variants, chosen by the discriminator. The dotted
     * rules only know the base's own properties, so each variant's complete schema goes to the
     * interpreter behind an `if` on its discriminator value — a `oneOf` of the variants would match
     * twice wherever two of them accept the same payload.
     *
     * A discriminator value the mapping does not name is refused here unless the base's property lists
     * its members itself, in which case the rules already say so; past this point it reached the
     * hydrator's `match` and came out as a 500.
     *
     * @param array<int, string> $visitedClasses
     * @return array<string, mixed>
     */
    private function laravelAllOfDiscriminatorConstraints(string $className, array $visitedClasses): array
    {
        $discriminator = $this->discriminatorSchemas[$className];
        $propertyName = $discriminator['propertyName'];

        $branches = [];
        foreach ($discriminator['mapping'] as $value => $variant) {
            // Built from the variant's CLASS, not by inlining its definition: a variant declared in
            // another file names its own neighbours relative to that file, and inlining resolved them
            // against the root document — `'other.yml#/…'` landed on a different file of the same name.
            $variantSchema = in_array($variant, $visitedClasses, true)
                ? []
                : $this->laravelInterpreterSchemaForClass(
                    className: $variant,
                    visitedClasses: [...$visitedClasses, $variant],
                    insideRecursiveFold: true,
                );
            if ($variantSchema === []) {
                continue;
            }
            $branches[] = [
                'if' => ['properties' => [$propertyName => ['const' => $value]], 'required' => [$propertyName]],
                'then' => $variantSchema,
            ];
        }

        $schema = $branches === [] ? [] : ['allOf' => $branches];
        $baseProperty = $this->dtoSchemas[$className]['properties'][$propertyName] ?? null;
        if (!is_array($baseProperty) || !array_key_exists('enum', $baseProperty)) {
            $schema['properties'] = [$propertyName => ['enum' => array_map('strval', array_keys($discriminator['mapping']))]];
        }

        return $schema;
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
        $segment = $this->laravelPathSegment($property['openApiName']);
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
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
