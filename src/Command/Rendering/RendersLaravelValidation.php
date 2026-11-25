<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use OpenapiPhpDtoGenerator\Service\DtoServiceSupport;
use RuntimeException;

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
     * The most nested-DTO rule paths one property may expand to. A nested DTO's rules are inlined per
     * PATH, so a DTO two paths reach is written twice, and a chain of schemas each naming the two before
     * it grows like Fibonacci: twenty of them ended in a PHP out-of-memory fatal after eight seconds.
     */
    private const int LARAVEL_NESTED_RULE_LIMIT = 20_000;

    private int $laravelNestedRuleBudget = 0;

    private int $laravelNestedRuleDepth = 0;

    /**
     * The classes a property hands its validation to, see `laravelComposedTargets()`; null until asked,
     * reset with every generation.
     *
     * @var array<string, true>|null
     */
    private ?array $laravelComposedTargetCache = null;

    /** @var array<string, bool> */
    private array $laravelNestedPathsCache = [];

    /** @var array<string, array<string, true>> */
    private array $laravelReachableCache = [];

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
     * @param bool $patternAsRule whether the property is the class's OWN, see `laravelSchemaRuleSpec()`
     * @return array<int, string> each entry is already PHP source (a quoted rule or a `Rule::` call)
     */
    private function laravelRulesForProperty(array $property, bool $patternAsRule): array
    {
        return $this->laravelRuleSpecFor($property, $patternAsRule)['rules'];
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

        // The walker's own reading of the document as well: a `oneOf` / `anyOf` member with
        // `nullable: true` admits null for the union, and the keywords on the property say nothing
        // of it — an optional `oneOf: [{type: number, nullable: true}, {type: string}]` was typed
        // without `null`, and an explicit null was a TypeError in laravel-data's constructor.
        return ($property['schemaNullable'] ?? false) === true
            || ($constraints['nullable'] ?? null) === true
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
     * @param bool $patternAsRule whether the property is the class's OWN, see `laravelSchemaRuleSpec()`
     * @return array{rules: array<int, string>, consumed: array<int, string>}
     */
    private function laravelRuleSpecFor(array $property, bool $patternAsRule): array
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
            // The facade is spelled out too, for the same reason one level up: a document may own a
            // `Rule` class in this namespace. The emitted file imports it as `Rule`, or under an alias
            // when that short name is taken.
            if ($this->laravelEnumIsIntBacked($enumClass)) {
                $rules[] = "'integer'";
            }
            $rules[] = sprintf('\Illuminate\Validation\Rule::enum(\%s::class)', $this->laravelEnumFqcn($enumClass));

            return ['rules' => $rules, 'consumed' => [...$consumed, 'type', 'enum']];
        }

        $schemaSpec = $this->laravelSchemaRuleSpec(
            schema: $constraints,
            phpType: $property['type'],
            patternAsRule: $patternAsRule,
        );

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
        if ($this->laravelNestedRuleDepth === 0) {
            $this->laravelNestedRuleBudget = self::LARAVEL_NESTED_RULE_LIMIT;
        }
        $visitedClasses[] = $nestedClass;
        $this->laravelNestedRuleDepth++;
        try {
            return $this->laravelNestedRulesBelow(
                property: $property,
                nestedClass: $nestedClass,
                prefix: $prefix,
                visitedClasses: $visitedClasses,
            );
        } finally {
            $this->laravelNestedRuleDepth--;
        }
    }

    /**
     * @param SchemaProperty $property
     * @param array<int, string> $visitedClasses
     * @return array<string, array<int, string>>
     */
    private function laravelNestedRulesBelow(array $property, string $nestedClass, string $prefix, array $visitedClasses): array
    {
        $rules = [];
        foreach ($this->getSchemaProperties($nestedClass) as $nestedProperty) {
            if (--$this->laravelNestedRuleBudget < 0) {
                throw new RuntimeException(sprintf(
                    'Laravel rules for property "%s" expand to more than %d nested paths: the nested DTOs reach one another along too many paths to inline. Use --attributes=runtime or symfony for this document, or flatten the schema chain.',
                    $property['openApiName'],
                    self::LARAVEL_NESTED_RULE_LIMIT,
                ));
            }
            $path = $prefix . '.' . $this->laravelPathSegment($nestedProperty['openApiName']);
            // A nested property is never the class's own: its pattern is the interpreter's.
            $nestedRules = $this->laravelRulesForProperty($nestedProperty, false);

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
                array: $nestedRules,
                callback: static fn(string $rule): bool => $rule !== "'present'" && $rule !== "'sometimes'",
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
     * `pattern` becomes a `regex:` rule only on a property of the class itself (`$patternAsRule`).
     * Anywhere below — a list member, a map value, a nested object's property — the rule sits on a
     * wildcard path (`f.*`, `f.*.name`) or on one a wildcard path shares (a nested class is folded
     * once for every path that reaches it), and Laravel applies a wildcard rule to every member with
     * no way for one member to stop the others. A pattern the regex engine gives up on then burned
     * the whole backtrack limit once per member: ten thousand items held a worker for twenty
     * seconds, refused in the end but at that price. Left unconsumed, the keyword reaches the
     * interpreter, whose walk ends at the first engine failure.
     *
     * @param array<string, mixed> $schema
     * @return array{rules: array<int, string>, consumed: array<int, string>}
     */
    private function laravelSchemaRuleSpec(array $schema, string $phpType = '', bool $patternAsRule = false): array
    {
        // `email`, `regex:` and `multiple_of:` refuse a value of any other type, where the keyword says
        // nothing about it: `{"format": "email"}` must pass `12`. Only what `type` pins becomes a rule;
        // the rest is left unconsumed, so the interpreter applies it to its own type.
        $schema = $this->withoutKeywordsTheTypeDoesNotPin(schema: $schema, phpType: $phpType);
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
            // A `$ref` to a generated DTO carries no `type` of its own, and without one `"x"` passed
            // `rules()` and then broke `fromValidated()` with a TypeError — a 500 for a bad request.
            // An enum or a date is a scalar on the wire and is left alone.
            // A union interface with a scalar branch too: the union accepts the scalar, the property is
            // typed as the interface, and a scalar let through broke `fromValidated()`. A union of
            // objects alone refuses a scalar itself, and the rule beside it said so a second time.
            null => $this->laravelIsNestedDtoType($phpType) && !$this->laravelIsUnionOfObjects($phpType) ? "'array'" : null,
            default => null,
        };
        if ($typeRule !== null) {
            $rules[] = $typeRule;
            $consumed[] = 'type';
        }

        // Only on a string: every rule `laravelFormatRule()` returns refuses any other value, while a
        // `format` ignores one (JSON Schema 2020-12 §7.1) — `{type: integer, format: uuid}` refused
        // `5`. Elsewhere the interpreter takes the keyword and reads it for strings alone.
        if (array_key_exists('format', $schema) && is_string($schema['format']) && $type === 'string') {
            $formatRule = $this->laravelFormatRule($schema['format']);
            if ($formatRule !== null) {
                $rules[] = $formatRule;
                $consumed[] = 'format';
                // Every rule `laravelFormatRule()` returns refuses a non-string, so `type: string` is
                // enforced even where the `string` rule itself was suppressed — which is the case for
                // a DateTimeImmutable property, whose `date_format:` rule would otherwise be reported
                // ALONGSIDE the interpreter's own "must be of type string" — see the "date-time + not"
                // case in `LaravelRulesEnforcementTest::overlapProvider()`.
                if ($typeRule === null) {
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

        if ($patternAsRule && array_key_exists('pattern', $schema) && is_string($schema['pattern']) && $this->patternCompiles($schema['pattern'])) {
            // Array form + explicit delimiters: a pattern containing `|` must not split the list.
            $rules[] = $this->phpStringLiteral('regex:' . DtoServiceSupport::delimitPattern($schema['pattern']));
            $consumed[] = 'pattern';
        }

        if (array_key_exists('multipleOf', $schema) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))) {
            // A JSON number past the float range (`1e400`) decodes to INF, `numeric` takes it, and
            // `multiple_of` then throws `MathException` — a 500 for any client that sends one. The guard
            // refuses it first, and `bail` keeps `multiple_of` from ever seeing it (or anything a rule
            // before it already refused).
            $rules[] = "'bail'";
            $rules[] = "static function (string \$attribute, mixed \$value, \\Closure \$fail): void { if (is_float(\$value) && !is_finite(\$value)) { \$fail('validation.numeric')->translate(); } }";
            $rules[] = sprintf("'multiple_of:%s'", $this->laravelNumberLiteral($schema['multipleOf']));
            $consumed[] = 'multipleOf';
        }

        // const and inline enum need JSON equality; Rule::in coerces scalars and loses structures.

        return ['rules' => $rules, 'consumed' => $consumed];
    }

    /**
     * Whether `field.*` reaches exactly the members `$keyword` (`items` / `additionalProperties`) judges.
     * It reaches EVERY member, so it does not where the schema names some of them itself: `items`
     * beside `prefixItems` judges only the positions past the tuple, and `additionalProperties` beside
     * `properties` / `patternProperties` only the keys they leave over. Emitted there, a rule refused
     * `[-75, "a"]` of `prefixItems: [{type: integer}], items: {type: string}` at position 0, and
     * `{"ibis": true}` of `{ibis: boolean}` beside `additionalProperties: {type: integer}`. The
     * interpreter, which tells the members apart, keeps that keyword whole instead.
     *
     * @param array<string, mixed> $schema
     */
    private function laravelWildcardReaches(array $schema, string $keyword): bool
    {
        if (!is_array($schema[$keyword] ?? null)) {
            return false;
        }
        if ($keyword === 'items') {
            return !$this->hasPrefixItems($schema);
        }
        foreach (['properties', 'patternProperties'] as $keysKeyword) {
            if (is_array($schema[$keysKeyword] ?? null) && $schema[$keysKeyword] !== []) {
                return false;
            }
        }

        return true;
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
        // A `mixed` value may be a list or an object, and `field.*` reaches the members of both: an
        // untyped `items` refused the values of an object it says nothing about, an untyped
        // `additionalProperties` the members of a list and the keys `properties` declares. Each keyword
        // ignores the other shape, which only the interpreter can tell apart.
        if ($property['type'] === 'mixed') {
            return [];
        }

        $constraints = $this->laravelConstraintsFor($property);
        $itemSchema = null;

        if ($this->laravelWildcardReaches($constraints, 'items')) {
            $itemSchema = $constraints['items'];
        } elseif ($this->laravelWildcardReaches($constraints, 'additionalProperties')) {
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
                $enumRule = sprintf('\Illuminate\Validation\Rule::enum(\%s::class)', $this->laravelEnumFqcn($itemClass));

                $itemRules = $this->laravelEnumIsIntBacked($itemClass) ? ["'integer'", $enumRule] : [$enumRule];
                // A scalar keyword beside the members (`minLength`, `minimum`, `pattern`) is the item's
                // rule as well: the interpreter is told the item rules cover it, so left out here it was
                // checked nowhere and `["a"]` passed `minLength: 2`. The type rule is the enum's own.
                if (is_array($itemSchema)) {
                    foreach ($this->laravelRulesForSchema($itemSchema) as $keywordRule) {
                        if (!in_array($keywordRule, ["'string'", "'integer'", "'numeric'", "'boolean'"], true)) {
                            $itemRules[] = $keywordRule;
                        }
                    }
                }

                return [
                    $this->laravelPathSegment($property['openApiName']) . '.*' => $this->laravelItemsNullable($property)
                        ? ["'nullable'", ...$itemRules]
                        : $itemRules,
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

        // The item's own PROPERTIES, where it is an object no class was generated for: `items: {allOf:
        // [{type: object, properties: …}], nullable: true}` folds into such an object. The prune counts
        // them as covered by a rule, so without these `[{"walrus": 5}]` passed both halves.
        if (is_array($itemSchema) && $this->laravelWildcardReaches($constraints, 'items')) {
            foreach ($this->laravelObjectPropertyRules($itemSchema, $itemPath, 2) as $propertyPath => $propertyRules) {
                $paths[$propertyPath] = $propertyRules;
            }
        }

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
            $this->laravelWildcardReaches($schema, 'items') => $schema['items'],
            $this->laravelWildcardReaches($schema, 'additionalProperties') => $schema['additionalProperties'],
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
        foreach ($this->laravelObjectPropertyRules($nested, $path, $hop + 1) as $propertyPath => $propertyRules) {
            $paths[$propertyPath] = $propertyRules;
        }

        foreach ($this->laravelDeepItemRules($nested, $path, $hop + 1) as $deepPath => $deepRules) {
            $paths[$deepPath] = $deepRules;
        }

        return $paths;
    }

    /**
     * The rules of an object's own properties under `$path`, and of the containers below them, for an
     * object no class was generated for.
     *
     * @param array<array-key, mixed> $object
     * @param int $hop the hop of the containers below these properties
     * @return array<string, array<int, string>>
     */
    private function laravelObjectPropertyRules(array $object, string $path, int $hop): array
    {
        $paths = [];
        $properties = $object['properties'] ?? null;
        foreach (is_array($properties) ? $properties : [] as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || !is_array($propertySchema)) {
                continue;
            }

            $propertyPath = $path . '.' . $propertyName;
            $propertyRules = array_values(array_filter(
                array: $this->laravelRulesForSchema($propertySchema),
                callback: static fn(string $rule): bool => $rule !== "'present'" && $rule !== "'sometimes'",
            ));
            if ($propertyRules !== []) {
                $paths[$propertyPath] = $propertyRules;
            }

            foreach ($this->laravelDeepItemRules($propertySchema, $propertyPath, $hop) as $p => $r) {
                $paths[$p] = $r;
            }
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
            // `uri` is the interpreter's: Laravel's `url` knows web schemes only, and refused `urn:…`.
            'url' => "'url'",
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

        // By the type, not by the getter: the hydrator writes `new DateTimeImmutable(…)` for a
        // `date | date-time` union too, which has no temporal getter of its own, and the import used
        // to arrive with the interpreter's whatever the class needed.
        if (
            $this->temporalGetterBody($property) !== null
            || $this->temporalArrayGetterBody($property) !== null
            || str_contains($property['type'], 'DateTimeImmutable')
        ) {
            $imports[] = 'DateTimeImmutable';
        }
        if ($this->laravelIsUploadedFileProperty($property)) {
            // Laravel's own UploadedFile extends this one, so the Symfony class is the right hint.
            $imports[] = 'Symfony\Component\HttpFoundation\File\UploadedFile';
        }
        // Not imported when the document owns a `Rule` here: the rule names the facade in full, and the
        // emitted file's import pass gives it an alias instead of the short name the class declares.
        if ($this->laravelRulesNeedRuleFacade($property) && !$this->namespaceDeclaresClass('Rule', $namespace)) {
            $imports[] = 'Illuminate\Validation\Rule';
        }
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL && $this->laravelInlineDiscriminator($property) !== null) {
            $this->importLibraryClass(fqcn: 'InvalidArgumentException', namespace: $namespace, useStatements: $imports);
        }

        foreach ($this->generatedClassesNamedByType($property['type']) as $nested) {
            $this->appendImportForClass($imports, $nested, $namespace, $className);
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
                $this->importLibraryClass(fqcn: 'RuntimeException', namespace: $namespace, useStatements: $imports);
            }

            // A discriminated union resolves to one of its members, so those are referenced too.
            if ($referenced === null || !array_key_exists($referenced, $this->discriminatorSchemas)) {
                continue;
            }

            $this->importLibraryClass(fqcn: 'InvalidArgumentException', namespace: $namespace, useStatements: $imports);
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
            // `Rule::` never comes from a pattern, so which half owns it does not matter here.
            $this->laravelRulesForProperty($property, false),
            ...array_values($this->laravelItemRulesForProperty($property)),
            // A nested class that writes its own rules imports its own facade.
            ...($this->laravelComposedChild($property) === null ? array_values($this->laravelNestedRules($property)) : []),
        ];

        foreach ($ruleSets as $ruleSet) {
            foreach ($ruleSet as $rule) {
                if (str_starts_with($rule, '\Illuminate\Validation\Rule::')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a generated enum is int-backed. `Rule::enum()` alone asks `tryFrom()`, which PHP feeds a
     * float by truncating it: `1.5` passed as the case 1 and was hydrated as 1. `integer` beside it
     * accepts `1.0` — the integer 1 in JSON — and refuses a real fraction.
     */
    private function laravelEnumIsIntBacked(string $enumClass): bool
    {
        return ($this->enumSchemas[$this->shortClassName($enumClass)]['type'] ?? null) === 'int';
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
     * The query, path, header and cookie parameters to read as the document serializes them before the
     * rules run, one entry per property: its wire name, whether it is a list, the delimiter its `style` joins the items
     * with (null for `form` exploded, where a lone value is a list of one), and what a string item or
     * value stands for.
     *
     * Everything there arrives as a string. Laravel's `boolean` rule refuses `"true"`, which is how
     * OpenAPI writes a boolean in a query; its `list` rule refuses `?ids=1,2`, which is how
     * `explode: false` writes a list; and nothing turned `?levels[]=2` into the int an `array<int>` or an
     * int-backed enum promises. Symfony mode reads them this way since 2.15.76. A header or a cookie is a
     * string just the same: `X-Flag: true` was refused by the `boolean` rule and `X-Ids: 1,2` by `list`.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, array{wireName: string, list: bool, delimiter: ?string, kind: string}>
     */
    private function laravelParameterReads(array $properties): array
    {
        $reads = [];
        foreach ($properties as $property) {
            $location = match (true) {
                ($property['inQuery'] ?? false) === true => 'query',
                ($property['inPath'] ?? false) === true => 'path',
                ($property['inHeader'] ?? false) === true => 'header',
                ($property['inCookie'] ?? false) === true => 'cookie',
                default => null,
            };
            if ($location === null) {
                continue;
            }

            $type = ltrim($property['type'], '?');
            if (preg_match('/^array<\??([A-Za-z_\\\][\w\\\]*)(?:\|null)?>$/', $type, $itemMatch) !== 1) {
                if ($type === 'bool') {
                    $reads[] = ['wireName' => $property['openApiName'], 'list' => false, 'delimiter' => null, 'kind' => 'bool'];
                }
                continue;
            }

            // OpenAPI's defaults: `simple` for a path or a header, `form` for a query or a cookie.
            $style = $property['parameterStyle'] ?? (in_array($location, ['path', 'header'], true) ? 'simple' : 'form');
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

    private function laravelIsNestedDtoType(string $phpType): bool
    {
        $base = $this->laravelBaseTypeName($phpType);

        return $base !== null && array_key_exists($base, $this->dtoSchemas) && !$this->laravelIsEnumClass($base);
    }

    /**
     * A `oneOf`/`anyOf` component whose branches are all objects (or the null schema): the union's own
     * check already refuses a scalar.
     */
    private function laravelIsUnionOfObjects(string $phpType): bool
    {
        $schema = $this->dtoSchemas[$this->laravelBaseTypeName($phpType) ?? ''] ?? [];
        $branches = $schema['oneOf'] ?? $schema['anyOf'] ?? null;
        if (!is_array($branches) || $branches === [] || array_key_exists('properties', $schema)) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return false;
            }
            $ref = $branch['$ref'] ?? null;
            $type = $branch['type'] ?? null;
            $isObject = is_string($ref)
                ? $this->laravelIsNestedDtoType($this->shortClassName($this->schemaRefToClassName(ref: $ref, currentSourceFile: null)))
                : $type === 'object' || in_array($type, ['null', ['null']], true);
            if (!$isObject) {
                return false;
            }
        }

        return true;
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
     * @return array{consts: string, methods: string}
     */
    private function renderLaravelInterpreterBlock(array $constraints, array $recursiveSchemas = []): array
    {
        if ($constraints['fields'] === [] && $constraints['object'] === []) {
            return ['consts' => '', 'methods' => ''];
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
            search: ['    private function ', '$this->'],
            replace: ['    private static function ', 'self::'],
            subject: $code,
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
            $folded = $this->laravelInterpreterSchemaFor(property: $property, visitedClasses: [$ownerClass], ownProperty: true);
            if ($folded === []) {
                continue;
            }

            $constraints[$property['openApiName']] = $folded;
        }

        // The class of a union beside a free-form object is built only from a value it matches, which
        // the hydrator asks the interpreter: its whole fold, the way a cycle's is stored.
        foreach ($properties as $property) {
            $matchedClass = $this->laravelUnionClassBesideFreeFormObject($property);
            if ($matchedClass !== null) {
                $this->laravelRecordRecursiveFold($matchedClass);
            }
            // A union of classes is built as the first member whose own schema the value matches.
            foreach ($this->laravelUndiscriminatedUnionClasses($property) as $memberClass) {
                $this->laravelRecordRecursiveFold($memberClass);
            }
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

        $extracted = $this->withConstraintSourceOf(
            className: $className,
            extraction: fn(): array => $this->extractValidationConstraints($schema),
        );
        $objectLevel = [];
        foreach (self::LARAVEL_OBJECT_LEVEL_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $extracted)) {
                $objectLevel[$keyword] = $extracted[$keyword];
            }
        }
        // The `anyOf` / `oneOf` a class judges itself by as a whole — beside its `allOf`, or arms that
        // only name which declared key is required. Carried nowhere, the root accepted `{}`.
        $objectLevel = [...$objectLevel, ...$this->classLevelUnionsBesideAllOf(className: $className, extracted: $extracted)];

        // A `required` name the class does not declare. The rule map holds the declared properties
        // only, so such a name reached no rule at any level: `{}` passed a root that requires `zed`,
        // and `{"tail": "x"}` a nested class that requires `ear`. The raw payload still has the key.
        $undeclaredRequired = $this->laravelUndeclaredRequiredNames(schema: $schema, sourceClass: $className);
        if ($undeclaredRequired !== []) {
            $objectLevel['required'] = $undeclaredRequired;
        }

        $objectLevel = $this->laravelWithClosedObjectFlag(
            schema: $schema,
            objectLevel: $objectLevel,
            extractedAdditional: $extracted['additionalProperties'] ?? null,
        );

        // Where an in-place applicator evaluates keys too, the interpreter decides `unevaluatedProperties`
        // from the whole tree — see `unevaluatedPropertiesEvaluation()`. `additionalProperties` beside
        // the keyword evaluates every key, which leaves the keyword itself nothing to refuse.
        $evaluation = $this->unevaluatedPropertiesEvaluation(schema: $schema, extracted: $extracted);
        if ($evaluation !== []) {
            $objectLevel = [...$objectLevel, ...$evaluation];
            if (array_key_exists('unevaluatedProperties', $extracted) && !array_key_exists('additionalProperties', $schema)) {
                $objectLevel['unevaluatedProperties'] = $extracted['unevaluatedProperties'];
            }
        }

        return $objectLevel;
    }

    /**
     * The names a class's `required` lists — its own, those of its inline `allOf` branches and those of
     * the components its `allOf` references — that none of those schemas declares in `properties`.
     * A union class judges itself branch by branch elsewhere and gets none here.
     *
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private function laravelUndeclaredRequiredNames(array $schema, string $sourceClass): array
    {
        if (array_key_exists('oneOf', $schema) || array_key_exists('anyOf', $schema)) {
            return [];
        }
        $names = $this->laravelRequiredAndDeclaredNames(schema: $schema, sourceClass: $sourceClass, depth: 0);

        return array_values(array_diff(array_unique($names['required']), $names['declared']));
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array{required: list<string>, declared: list<string>}
     */
    private function laravelRequiredAndDeclaredNames(array $schema, string $sourceClass, int $depth): array
    {
        $required = [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (is_string($name) || is_int($name)) {
                $required[] = (string)$name;
            }
        }
        $declared = [];
        foreach (is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [] as $name) {
            $declared[] = (string)$name;
        }

        $branches = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $branches[] = ['$ref' => $ref];
        }
        // A reference cycle through `allOf` is the document's own error; the depth only keeps it finite.
        if ($depth > 16) {
            return ['required' => $required, 'declared' => $declared];
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $branchRef = $branch['$ref'] ?? null;
            if (is_string($branchRef)) {
                $resolved = $this->resolveSchemaNodeByRef($branchRef, $this->getSchemaSourceFile($sourceClass));
                $branch = $resolved === null ? [] : $resolved[0];
            }
            // A union branch is satisfied by one of its members, which says nothing about all of them.
            if (array_key_exists('oneOf', $branch) || array_key_exists('anyOf', $branch)) {
                continue;
            }
            $below = $this->laravelRequiredAndDeclaredNames(schema: $branch, sourceClass: $sourceClass, depth: $depth + 1);
            $required = [...$required, ...$below['required']];
            $declared = [...$declared, ...$below['declared']];
        }

        return ['required' => $required, 'declared' => $declared];
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
     * An `additionalProperties` SCHEMA travels the same way and for the same reason: `{"name":"x",
     * "red":"s"}` against `additionalProperties: {type: integer}` passed at the root and behind a
     * `$ref`, where runtime refuses the undeclared key's value.
     *
     * @param array<string, mixed> $schema the class schema as the document declared it
     * @param array<string, mixed> $objectLevel the slot built from the object-level keywords
     * @param mixed $extractedAdditional the class's `additionalProperties` as the constraint extractor wrote it
     * @return array<string, mixed>
     */
    private function laravelWithClosedObjectFlag(array $schema, array $objectLevel, mixed $extractedAdditional): array
    {
        $closesWithAdditional = ($schema['additionalProperties'] ?? null) === false;
        $closesWithUnevaluated = ($schema['unevaluatedProperties'] ?? null) === false
            && !array_key_exists('additionalProperties', $schema);
        // `{}` admits every value, so it asserts nothing and needs no slot.
        $additionalSchema = is_array($extractedAdditional) && $extractedAdditional !== [] ? $extractedAdditional : null;

        if (!$closesWithAdditional && !$closesWithUnevaluated && $additionalSchema === null) {
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
        } elseif ($additionalSchema !== null) {
            $objectLevel['additionalProperties'] = $additionalSchema;
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
                if ($this->laravelWildcardReaches($schema, $keyword) && $this->laravelUnconsumedKeywords($schema[$keyword], $hop + 1) === []) {
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
     * @param bool $patternAsRule whether the rules took this property's `pattern` — only a property of
     *        the class itself, see `laravelSchemaRuleSpec()`
     * @return array<string, mixed>
     */
    private function laravelPruneRuleCoveredKeywords(array $schema, array $property, bool $patternAsRule): array
    {
        $consumed = [
            ...$this->laravelRuleSpecFor($property, $patternAsRule)['consumed'],
            ...$this->laravelInertKeywords($schema),
        ];

        // Two different assertions share one keyword name. `$property['required']` is a bool — is the
        // KEY there — and the rule builder consumes it as `present`/`sometimes`. A `required` LIST on
        // the schema is "this object must carry these keys", which no rule expresses. Where the object
        // became a nested DTO its own rules own that; where it stayed a map nothing does, so it has to
        // reach the interpreter.
        //
        // A `mixed` property is the other such place: an untyped schema with `properties` is not
        // materialized — JSON Schema lets it hold any value — so no nested class and no dotted rule
        // reaches the keys it describes, and its `required` and `properties` are the interpreter's.
        $keysUnmaterialized = $property['type'] === 'mixed';
        if (is_array($schema['required'] ?? null) && (!is_array($schema['properties'] ?? null) || $keysUnmaterialized)) {
            $consumed = array_values(array_filter(
                array: $consumed,
                callback: static fn(string $keyword): bool => $keyword !== 'required',
            ));
        }

        // `nullable` qualifies `type`: the rule covers it only together with a type rule. A `type` left
        // to the interpreter (an enum-typed property gets no `string` rule) needs its `nullable` too,
        // or the interpreter's type check refuses the null the `nullable` rule just let through.
        if (array_key_exists('type', $schema) && !in_array('type', $consumed, true)) {
            $consumed = array_values(array_filter(
                array: $consumed,
                callback: static fn(string $keyword): bool => $keyword !== 'nullable',
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
            $containerCoveredByRules[$containerKey] = !$keysUnmaterialized
                && $this->laravelWildcardReaches($schema, $containerKey)
                && $this->laravelUnconsumedKeywords($schema[$containerKey], 1) === [];
        }

        // A nested object's properties are covered by the dotted rules. The NAMES still have to survive
        // when `additionalProperties`/`unevaluatedProperties` is present — those keywords are defined in
        // terms of which keys `properties` declares — so such an entry keeps its key and loses its
        // rules, exactly as `DtoValidator::withPropertyRulesOwnedByTheDto()` does for a DTO value
        // (E2 in `.todo.codegeneration_symfony_vs_runtime`).
        $keptProperties = [];
        if (is_array($schema['properties'] ?? null)) {
            $namesMatter = $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedProperties')
                || array_key_exists('additionalProperties', $schema);

            foreach ($schema['properties'] as $name => $nested) {
                if ($keysUnmaterialized) {
                    $keptProperties[$name] = $nested;
                } elseif (is_array($nested) && $this->laravelUnconsumedKeywords($nested) !== []) {
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
                    // The rules check every item, but `unevaluatedItems` beside them still has to know
                    // that `items` EVALUATED them: the empty schema says so and checks nothing twice.
                    if ($keyword === 'items' && $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedItems')) {
                        $pruned[$keyword] = [];
                    }
                    continue;
                }

                // Where no `field.*` rule reaches the members, nothing below is covered either.
                $pruned[$keyword] = $keysUnmaterialized || !$this->laravelWildcardReaches($schema, $keyword)
                    ? $value
                    : $this->laravelPruneNestedRuleCoveredKeywords($value, 1);
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
                    // `false` is the branch no rule covers: it refuses every value, so the whole `allOf`
                    // is that one branch. Skipped with `true`, it left a schema that admits nothing
                    // admitting everything.
                    if ($branch === false) {
                        $branches = [false];
                        break;
                    }
                    if (!is_array($branch)) {
                        continue;
                    }

                    $prunedBranch = $this->laravelPruneNestedRuleCoveredKeywords(
                        schema: $branch,
                        evaluationMatters: $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedProperties')
                            || $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedItems'),
                    );
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
    private function laravelPruneNestedRuleCoveredKeywords(array $schema, int $hop = 0, bool $evaluationMatters = false): array
    {
        // Same unit and same rule as the coverage test: past the last hop the dotted rules reach, a
        // rule covers nothing, so every assertion from here down has to survive into the interpreter.
        $consumed = $this->laravelInertKeywords($schema);
        if ($hop <= self::LARAVEL_MAX_DOTTED_RULE_HOP) {
            $consumed = [...$consumed, ...$this->laravelSchemaRuleSpec($schema)['consumed']];
        }

        // An `allOf` branch hands what it evaluates to the `unevaluated*` of the schema above it, so with
        // one there its names and its `items` survive the pruning as empty schemas, as they do here.
        $namesMatter = $evaluationMatters
            || $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedProperties')
            || array_key_exists('additionalProperties', $schema);
        $itemsMatter = $evaluationMatters || $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedItems');

        $pruned = [];
        foreach ($schema as $keyword => $value) {
            if (($keyword === 'items' || $keyword === 'additionalProperties') && is_array($value)) {
                // No `field.*` rule reaches these members, so no rule covers anything below them.
                if (!$this->laravelWildcardReaches($schema, $keyword)) {
                    $pruned[$keyword] = $value;
                    continue;
                }
                if ($this->laravelUnconsumedKeywords($value, $hop + 1) === []) {
                    if ($keyword === 'items' && $itemsMatter) {
                        $pruned[$keyword] = [];
                    }
                    continue;
                }

                $pruned[$keyword] = $this->laravelPruneNestedRuleCoveredKeywords($value, $hop + 1);
                continue;
            }

            if ($keyword === 'properties' && is_array($value)) {
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
     * The string-backed enums of a union that also holds a date and no plain `string` —
     * `oneOf: [{enum: [red]}, {format: date}]`, typed `BoxA|DateTimeImmutable`, optionally beside a
     * `bool`, an `int` or a class. Empty for any other type.
     *
     * Such a union is not a `isTemporalScalarUnion()`: a string arriving there is the enum OR the date,
     * so it needs `tryFrom()` first and the date parse only when that finds no case. Treated as a plain
     * enum union, the date string reached the constructor as a string (a TypeError in laravel mode) or
     * went through laravel-data's global ATOM cast with the enum value as well (`CannotCastDate`).
     *
     * @return list<string>
     */
    private function laravelStringEnumsBesideDate(string $type): array
    {
        $members = array_map($this->shortClassName(...), $this->unionMembersBesideNull($type));
        if (!in_array('DateTimeImmutable', $members, true)) {
            return [];
        }

        $stringEnums = [];
        foreach ($members as $member) {
            if (in_array($member, ['DateTimeImmutable', 'int', 'float', 'bool', 'array'], true) || array_key_exists($member, $this->dtoSchemas)) {
                continue;
            }
            if (($this->enumSchemas[$member]['type'] ?? null) === 'int') {
                continue;
            }
            if (($this->enumSchemas[$member]['type'] ?? null) !== 'string') {
                // A plain `string` (or anything unknown) beside the date: the string is a legitimate
                // value of its own, not this shape.
                return [];
            }
            $stringEnums[] = $member;
        }

        return $stringEnums;
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
     * The discriminator of a `oneOf` / `anyOf` written inline on the property — `Circle|Square`, no base class to
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
        if (!is_string($propertyName) || !is_array($mapping) || array_key_exists('oneOf', $constraints) || array_key_exists('anyOf', $constraints)) {
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
     * @param bool $ownProperty the property belongs to the class being rendered rather than to a class
     *        folded into it: only there is a `pattern` a native rule, see `laravelSchemaRuleSpec()`
     * @return array<string, mixed>
     */
    private function laravelInterpreterSchemaFor(
        array $property,
        array $visitedClasses = [],
        bool $pruneByOwnRules = true,
        bool $insideRecursiveFold = false,
        bool $ownProperty = false,
    ): array {
        $constraints = $this->laravelRestoreUnionEnumBranches(
            schema: $this->laravelConstraintsFor($property),
            phpType: $property['type'],
        );
        $schema = $pruneByOwnRules
            ? $this->laravelPruneRuleCoveredKeywords(schema: $constraints, property: $property, patternAsRule: $ownProperty)
            : $constraints;
        // Inside a fold no rule asserts what the PHP type pins, so the schema says it: a `$ref` to an
        // object or to a list alias carried no `type` of its own, and a discriminated member took
        // `{"toy": 7}` and `{"nums": "x"}` to hydration — a TypeError, a 500.
        if ($insideRecursiveFold) {
            $schema = $this->laravelWithPhpPinnedType(
                schema: $schema,
                phpType: $property['type'],
                nullable: ($property['schemaNullable'] ?? false) === true,
            );
        }

        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $nestedClass === null ? $this->laravelDtoItemClass($property) : null;
        $target = $nestedClass ?? $itemClass;

        if ($target !== null && $itemClass !== null && $this->laravelIsEnumClass($target)) {
            // Inside a fold no `field.*` rule checks the items, so the members do.
            return $pruneByOwnRules && $this->laravelItemEnumIsRuleChecked($property)
                ? $this->laravelWithoutItemEnumKeywords($schema, $property)
                : $this->laravelWithItemEnumMembers($schema, $property, $target);
        }

        $enumClass = $this->laravelBaseTypeName($property['type']);
        if ($enumClass !== null && !$pruneByOwnRules && $this->laravelIsEnumClass($enumClass)) {
            // Inside a fold no dotted rule names this field, so its enum class checks nothing before
            // hydration casts the value: `{"hue": "zzq"}` in a spelled-out union branch was a ValueError.
            // The members are the check, and null with them where the property admits it.
            if (array_key_exists('enum', $schema)) {
                return $schema;
            }
            $members = array_values($this->enumSchemas[$enumClass]['values']);
            if ($property['nullable'] && ($property['schemaNullable'] ?? false) === true) {
                $members[] = null;
            }

            return [...$schema, 'enum' => $members];
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
                ? $this->laravelWithNullableNestedMarker(
                    schema: $schema,
                    property: $property,
                    target: $target,
                    className: $target,
                    insideRecursiveFold: $insideRecursiveFold,
                )
                : [...$schema, $this->laravelContainerValueKey($property) => ['x-openapi-recurse' => $target]];
        }

        $shared = $this->laravelSharedFold(
            target: $target,
            visitedClasses: $visitedClasses,
            insideRecursiveFold: $insideRecursiveFold,
            local: $nestedClass !== null ? $schema : (is_array($schema[$this->laravelContainerValueKey($property)] ?? null) ? $schema[$this->laravelContainerValueKey($property)] : []),
        );
        if ($shared !== null) {
            if ($shared['fold'] === []) {
                return $schema;
            }
            if ($nestedClass !== null) {
                return $this->laravelWithNullableNestedMarker(
                    schema: $schema,
                    property: $property,
                    target: $shared['key'],
                    className: $target,
                    insideRecursiveFold: $insideRecursiveFold,
                );
            }
            $containerKey = $this->laravelContainerValueKey($property);
            $marked = [
                ...(is_array($schema[$containerKey] ?? null) ? $schema[$containerKey] : []),
                'x-openapi-recurse' => $shared['key'],
            ];
            // A null item of `array<?Shape>`: the fold of a discriminated union asserts `type: object`,
            // which refused the null the items admit. Let through the way `laravelWithNullableNestedMarker()`
            // lets a property's: `nullable` beside a typed fold, a null branch beside a bare union.
            if ($this->laravelItemsNullable($property)) {
                $marked = $this->laravelFoldIsUnion($shared['fold']) ? $this->laravelUnionOrNull($marked) : [...$marked, 'nullable' => true];
            }
            $schema[$containerKey] = $marked;

            return $schema;
        }

        $nested = $this->laravelInterpreterSchemaForClass($target, [...$visitedClasses, $target], $insideRecursiveFold);
        if ($nested === []) {
            return $schema;
        }

        if ($nestedClass !== null) {
            $merged = $this->laravelMergedFold(local: $schema, nested: $nested);
            $admitsNull = $this->laravelNestedValueAdmitsNull(
                property: $property,
                className: $target,
                insideRecursiveFold: $insideRecursiveFold,
            );
            if ($admitsNull && $this->laravelFoldIsUnion($nested)) {
                return $this->laravelUnionOrNull($merged);
            }

            // A nullable nested value must survive: `{"child": null}` is legal, and the interpreter
            // skips a null only when the schema says it may be one.
            if ($admitsNull) {
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
            ? $this->laravelMergedFold(local: $schema[$containerKey], nested: $nested)
            : $nested;

        return $schema;
    }

    /**
     * A property's own schema with its class's fold laid over it. The two `required` lists are joined,
     * not replaced: `{allOf: [$ref Shape], required: [paw]}` lost `paw` to Shape's own list, and a
     * payload without it passed.
     *
     * @param array<string, mixed> $local
     * @param array<string, mixed> $nested
     * @return array<string, mixed>
     */
    private function laravelMergedFold(array $local, array $nested): array
    {
        $merged = [...$local, ...$nested];
        if (is_array($local['required'] ?? null) && is_array($nested['required'] ?? null)) {
            $merged['required'] = array_values(array_unique([...$local['required'], ...$nested['required']]));
        }

        return $merged;
    }

    /**
     * A schema with the JSON type its PHP type pins spelled out where the schema names none: an object
     * for a generated class, an array of the item type for a list, an object of the value type for a
     * map, and the scalar's type. A union, an enum or `mixed` pins nothing here.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function laravelWithPhpPinnedType(array $schema, string $phpType, bool $nullable): array
    {
        foreach (['type', '$ref', 'allOf', 'anyOf', 'oneOf', 'enum', 'const', 'x-openapi-recurse'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return $schema;
            }
        }
        $members = $this->unionMembersBesideNull($phpType);
        if (count($members) !== 1) {
            return $schema;
        }
        $member = $members[0];
        $short = $this->shortClassName($member);
        $pinned = match (true) {
            $member === 'int' => ['type' => 'integer'],
            $member === 'float' => ['type' => 'number'],
            $member === 'string' => ['type' => 'string'],
            $member === 'bool' => ['type' => 'boolean'],
            preg_match('/^array<string, (.+)>$/s', $member, $map) === 1 => [
                'type' => 'object',
                'additionalProperties' => $this->laravelWithPhpPinnedType(
                    schema: is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : [],
                    phpType: $map[1],
                    nullable: str_starts_with($map[1], '?') || str_contains($map[1], 'null'),
                ),
            ],
            preg_match('/^array<(?:int, )?(.+)>$/s', $member, $list) === 1 => [
                'type' => 'array',
                'items' => $this->laravelWithPhpPinnedType(
                    schema: is_array($schema['items'] ?? null) ? $schema['items'] : [],
                    phpType: $list[1],
                    nullable: str_starts_with($list[1], '?') || str_contains($list[1], 'null'),
                ),
            ],
            array_key_exists($short, $this->dtoSchemas)
                && !array_key_exists('oneOf', $this->dtoSchemas[$short])
                && !array_key_exists('anyOf', $this->dtoSchemas[$short]) => ['type' => 'object'],
            default => [],
        };
        if ($pinned === []) {
            return $schema;
        }
        if ($nullable) {
            $pinned['nullable'] = true;
        }

        return [...$schema, ...$pinned];
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
     * @param string $target the fold the marker points at
     * @param string $className the class behind that fold
     * @return array<string, mixed>
     */
    private function laravelWithNullableNestedMarker(
        array $schema,
        array $property,
        string $target,
        string $className,
        bool $insideRecursiveFold = false,
    ): array {
        $marked = [...$schema, 'x-openapi-recurse' => $target];
        $admitsNull = $this->laravelNestedValueAdmitsNull(
            property: $property,
            className: $className,
            insideRecursiveFold: $insideRecursiveFold,
        );
        if ($admitsNull && $this->laravelFoldIsUnion($this->laravelRecursiveFolds[$target] ?? [])) {
            return $this->laravelUnionOrNull($marked);
        }
        if ($admitsNull) {
            $marked['nullable'] = true;
        }

        return $marked;
    }

    /**
     * Whether the interpreter may let a `null` through for a nested class. For an object class, whenever
     * the PHP property is nullable — an optional one included: its `array` rule already refuses the
     * null, and the permission only keeps the interpreter from repeating that. A union class has no such
     * rule — it may hold a scalar — so the interpreter is the only thing that refuses `null` there, and
     * only the SCHEMA may allow it: an optional non-nullable union property took `null` (the dispatch
     * of a discriminated union skipped it, a bare union got a `null` branch) and built nothing.
     *
     * @param SchemaProperty $property
     */
    private function laravelNestedValueAdmitsNull(array $property, string $className, bool $insideRecursiveFold = false): bool
    {
        $definition = $this->dtoSchemas[$className] ?? [];
        // Inside a fold there is no `array` rule to refuse the null either: only the schema may allow it.
        if ($insideRecursiveFold || array_key_exists('oneOf', $definition) || array_key_exists('anyOf', $definition)) {
            return ($property['schemaNullable'] ?? $property['nullable']) === true;
        }

        return $property['nullable'] === true || !$this->propertyIsRequiredOnInput($property);
    }

    /**
     * Whether a folded class is a bare `oneOf` / `anyOf`. The interpreter reads `nullable` beside a
     * `type` only; a union check counts its own branches and ignores the flag beside it.
     *
     * @param array<string, mixed> $fold
     */
    private function laravelFoldIsUnion(array $fold): bool
    {
        return is_array($fold['oneOf'] ?? null) || is_array($fold['anyOf'] ?? null);
    }

    /**
     * A NULLABLE property whose class folds to a union, as one more branch beside it. With `nullable`
     * beside the union, `{"pet": null}` was refused with "does not match any oneOf branch" in a
     * property that every other mode lets be null. An optional property that is not nullable keeps
     * the union alone, so its null is still refused — no rule beside a union of objects does that.
     *
     * @param array<string, mixed> $union
     * @return array<string, mixed>
     */
    private function laravelUnionOrNull(array $union): array
    {
        return ['anyOf' => [['type' => 'null'], $union]];
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
     * A discriminated union component, folded for the interpreter the way its hydrator reads it: by
     * the variant the discriminator value names. The bare `oneOf` the fold used to carry knew nothing of
     * the discriminator — the branches are inlined by then, so the `$ref`s the property-level dispatch
     * keys on are gone — and a value the mapping does not name, or a cat's fields under `"kind": "Dog"`,
     * passed validation and broke the `match` in `fromValidated()`: a 500.
     *
     * The mapping is the class's own, the implicit one included, so a document that writes none is
     * dispatched by schema name exactly as the hydrator does. laravel mode only: laravel-data builds
     * its members its own way, and its validation must keep agreeing with that.
     *
     * @param array<string, mixed> $definition the union component as the document declared it
     * @param array<string, mixed> $extracted the fold built branch by branch
     * @return array<string, mixed>|null null when the fold stays branch by branch
     */
    private function laravelDispatchedUnionFold(string $className, array $definition, array $extracted): ?array
    {
        $mapping = $this->discriminatorSchemas[$className]['mapping'] ?? [];
        $variants = $definition['oneOf'] ?? null;
        $branches = $extracted['oneOf'] ?? null;
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_LARAVEL || $mapping === [] || !is_array($variants) || !is_array($branches)) {
            return null;
        }

        $dispatched = $this->laravelDiscriminatorDispatch(
            variants: $variants,
            branchConstraints: array_values($branches),
            propertyName: $this->discriminatorSchemas[$className]['propertyName'],
            classMap: $mapping,
        );
        if ($dispatched === null) {
            return null;
        }

        $rest = [];
        foreach ($extracted as $keyword => $value) {
            if (!in_array($keyword, ['oneOf', 'x-discriminator-property', 'x-discriminator-php-property', 'x-discriminator-map'], true)) {
                $rest[$keyword] = $value;
            }
        }

        return [...$rest, ...$dispatched];
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
            $extracted = $this->withConstraintSourceOf(
                className: $className,
                extraction: fn(): array => $this->extractValidationConstraints($this->inlineNestedContainerValidation(
                    schema: $definition,
                    belowMaterialization: true,
                )),
            );

            return $this->laravelDispatchedUnionFold($className, $definition, $extracted) ?? $extracted;
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

        // The class's object-wide keywords travel with it, exactly as the root class carries them in its
        // object slot. Without them a `$ref` one level down kept only its properties: the class validated
        // on its own refused an undeclared key, a fifth property past `maxProperties` or a missing
        // `dependentRequired` partner, and the same class reached through a property accepted all three
        // — the undeclared key then vanished on hydration.
        $objectLevel = $this->laravelObjectLevelConstraints($className);

        if ($properties === [] && $required === [] && $objectLevel === []) {
            return [];
        }

        // No `type` here: the rules already assert `array` for the property, and repeating it would
        // report one mistake twice — and would reject a NULLABLE nested value outright.
        $schema = [];
        foreach ($objectLevel as $keyword => $value) {
            if ($keyword !== 'properties') {
                $schema[$keyword] = $value;
            }
        }
        // A closed class lists every declared name with an empty schema, so the interpreter knows what
        // is not additional; a name that also carries constraints of its own keeps them.
        $declaredNames = is_array($objectLevel['properties'] ?? null) ? $objectLevel['properties'] : [];
        $properties += $declaredNames;
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        // The object slot's `required` names what the class does not declare; the declared ones join it.
        $required = [...$required, ...(is_array($objectLevel['required'] ?? null) ? $objectLevel['required'] : [])];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * A discriminated `oneOf` property checked the way it is hydrated: by the variant its discriminator
     * names. Matched branch by branch instead, a payload that fit none was reported against EVERY
     * branch — `{"kind": "circle"}` was told it lacks `side` as well as `radius` — and once each error
     * is keyed by its own field, a client highlights fields of a variant it never sent.
     *
     * Null when a branch is not a `$ref` the mapping names: then there is no variant to dispatch to,
     * and the branch-by-branch match stays.
     *
     * @param array<int|string, mixed> $variants
     * @param array<int, mixed> $branchConstraints
     * @param array<string, string> $classMap
     * @return array<string, mixed>|null
     */
    private function laravelDiscriminatorDispatch(
        array $variants,
        array $branchConstraints,
        string $propertyName,
        array $classMap,
    ): ?array {
        $branchByClass = [];
        $acceptsNull = false;
        foreach (array_values($variants) as $index => $variant) {
            $branch = $branchConstraints[$index] ?? null;
            if ($branch === ['type' => 'null']) {
                $acceptsNull = true;

                continue;
            }
            $ref = is_array($variant) ? ($variant['$ref'] ?? null) : null;
            $class = is_string($ref) ? $this->schemaRefToClassName($ref) : 'mixed';
            if (!is_array($branch) || !in_array($class, $classMap, true)) {
                return null;
            }
            $branchByClass[$class] = $branch;
        }
        // `nullable: true` beside the union is appended as one more branch, past the variants.
        $acceptsNull = $acceptsNull || in_array(['type' => 'null'], $branchConstraints, true);

        $allOf = [[
            'required' => [$propertyName],
            'properties' => [$propertyName => ['enum' => array_map('strval', array_keys($classMap))]],
        ]];
        foreach ($classMap as $value => $class) {
            $allOf[] = [
                'if' => ['type' => 'object', 'properties' => [$propertyName => ['const' => $value]], 'required' => [$propertyName]],
                'then' => $branchByClass[$class] ?? [],
            ];
        }

        // `type` at the top, where the rule map turns it into `array` — and `nullable` into `nullable`.
        // The discriminator stays for the hydrator, which builds the variant it names.
        $dispatched = [
            'type' => 'object',
            'allOf' => $allOf,
            'x-discriminator-property' => $propertyName,
            'x-discriminator-map' => $classMap,
        ];

        return $acceptsNull ? [...$dispatched, 'nullable' => true] : $dispatched;
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
    private function laravelObjectShapePaths(array $property, string $prefix, array $visitedClasses = [], bool $recurse = true): array
    {
        $segment = $this->laravelPathSegment($property['openApiName']);
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
        $constraints = $this->laravelConstraintsFor($property);
        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);
        $itemDtoClass = $itemClass !== null && !$this->laravelIsEnumClass($itemClass) ? $itemClass : null;

        $paths = [];
        // `Shade|string` too: an array there can only be the class, so a JSON list is refused as one —
        // not beside an `array` member, where the list is the other member.
        if (
            $nestedClass !== null
            || $this->laravelSchemaIsObject($constraints)
            || ($this->unionClassBesideScalars($property['type']) !== null && !$this->unionHasArrayMember($property['type']))
            || $this->laravelUnionAdmitsObjectButNoArray($constraints)
        ) {
            $paths[] = $path;
        }

        // A list of objects, and a map whose VALUES are objects, share the wildcard path.
        $items = is_array($constraints['items'] ?? null) ? $constraints['items'] : null;
        $values = is_array($constraints['additionalProperties'] ?? null) ? $constraints['additionalProperties'] : null;
        if (
            $itemDtoClass !== null
            || ($property['type'] !== 'mixed' && $items !== null && $this->laravelSchemaIsObject($items))
            || ($property['type'] !== 'mixed' && $values !== null && $this->laravelSchemaIsObject($values))
        ) {
            $paths[] = $path . '.*';
        }

        // Each member of a discriminated union, at the union's own path: `{"kind": "cat", "bag": [2]}`
        // reached a cat's `type: object` property unasked. A member's paths sit behind a segment that
        // names its tag (`pet.{kind=cat}.bag`), which the raw check walks past only when the body's
        // tag selects that member: two members may spell one name as an object and as a list, and a
        // dog's list `bag` was refused as the cat's object.
        $discriminated = $recurse ? $this->laravelDiscriminatedMembers($property, $nestedClass) : null;
        foreach ($discriminated['members'] ?? [] as $tagValue => $memberClass) {
            if (in_array($memberClass, $visitedClasses, true)) {
                continue;
            }
            $memberPath = $path . '.' . $this->laravelMemberGuardSegment(
                propertyName: $discriminated['propertyName'] ?? '',
                tagValue: (string)$tagValue,
            );
            foreach ($this->getSchemaProperties($memberClass) as $childProperty) {
                foreach ($this->laravelObjectShapePaths($childProperty, $memberPath, [...$visitedClasses, $memberClass]) as $childPath) {
                    $paths[] = $childPath;
                }
            }
        }

        $recurseClass = $nestedClass ?? $itemDtoClass;
        if (!$recurse || $recurseClass === null || in_array($recurseClass, $visitedClasses, true)) {
            return array_values(array_unique($paths));
        }

        $visitedClasses[] = $recurseClass;
        $childPrefix = $nestedClass !== null ? $path : $path . '.*';
        foreach ($this->getSchemaProperties($recurseClass) as $childProperty) {
            foreach ($this->laravelObjectShapePaths($childProperty, $childPrefix, $visitedClasses) as $childPath) {
                $paths[] = $childPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The members of a discriminated union a property holds as one value — written inline on the
     * property, or a union component it references — keyed by the tag value that selects each, with
     * the name of the tag property.
     *
     * @param SchemaProperty $property
     * @return array{propertyName: string, members: array<array-key, string>}|null
     */
    private function laravelDiscriminatedMembers(array $property, ?string $nestedClass): ?array
    {
        // The map beside a union laravel mode did not dispatch — laravel-data keeps the `oneOf` too.
        $constraints = $this->laravelConstraintsFor($property);
        $mapping = $constraints['x-discriminator-map'] ?? null;
        $propertyName = $constraints['x-discriminator-property'] ?? null;
        if ($mapping === null && $nestedClass !== null) {
            $definition = $this->dtoSchemas[$nestedClass] ?? [];
            $isUnion = array_key_exists('oneOf', $definition) || array_key_exists('anyOf', $definition);
            $mapping = $isUnion ? ($this->discriminatorSchemas[$nestedClass]['mapping'] ?? null) : null;
            $propertyName = $isUnion ? ($this->discriminatorSchemas[$nestedClass]['propertyName'] ?? null) : null;
        }
        if (!is_string($propertyName) || !is_array($mapping)) {
            return null;
        }
        $members = [];
        foreach ($mapping as $tagValue => $class) {
            $short = $this->shortClassName((string)$class);
            if (array_key_exists($short, $this->dtoSchemas)) {
                // A numeric tag ("1") turns into an int key here, which the guard casts back.
                $members[$tagValue] = $short;
            }
        }

        return $members === [] ? null : ['propertyName' => $propertyName, 'members' => $members];
    }

    /**
     * The path segment that stands for "the member this tag value selects": `{kind=cat}`. It names
     * no property, so the raw check reads it as a condition on the node it reached, and adds nothing
     * to the path an error is reported under. A dot in the name or the value is escaped as one in a
     * property name is.
     */
    private function laravelMemberGuardSegment(string $propertyName, string $tagValue): string
    {
        return '{' . $this->laravelPathSegment($propertyName) . '=' . $this->laravelPathSegment($tagValue) . '}';
    }

    /**
     * Whether a raw-check path walks through a member guard (`laravelMemberGuardSegment()`), so the
     * walker that reads it has to know the segment.
     */
    private function laravelPathHasMemberGuard(string $path): bool
    {
        return preg_match('/(?:^|(?<!\\\)\.)\{[^=]*=.*\}(?:(?<!\\\)\.|$)/', $path) === 1;
    }

    /**
     * Whether a subschema describes an object and NOTHING else. A union that also allows an array is
     * ambiguous by design, so it is left to the interpreter rather than refused here. A `oneOf` /
     * `anyOf` whose every member is an object (or `null`) is one: `anyOf: [{type: object}]` refuses
     * `[1,2]` exactly as `type: object` does.
     *
     * @param array<array-key, mixed> $schema
     */
    private function laravelSchemaIsObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            return array_values(array_filter(
                array: $type,
                callback: static fn(mixed $member): bool => $member !== 'null',
            )) === ['object'];
        }
        if ($type !== null) {
            return $type === 'object';
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || $members === []) {
                continue;
            }
            $objectMembers = 0;
            foreach ($members as $member) {
                if (!is_array($member)) {
                    return false;
                }
                if (($member['type'] ?? null) === 'null') {
                    continue;
                }
                if (!$this->laravelSchemaIsObject($member)) {
                    return false;
                }
                $objectMembers++;
            }

            return $objectMembers > 0;
        }

        return false;
    }

    /**
     * Whether a `oneOf` / `anyOf` has an object member and no member a JSON array could match:
     * `anyOf: [{type: object}, {type: string}]`. Decoded, `[2]` satisfied the object member there, so
     * the raw body is asked instead, as for a plain `type: object`. A member's type is read through
     * its nested `allOf` / `anyOf` / `oneOf` too: `anyOf: [{anyOf: [{type: object}, {type: integer}]}]`
     * refuses `[2]` as the flat spelling does. A member whose type cannot be told admits an array, and
     * so does one that names `array`: such a union stays the interpreter's.
     *
     * @param array<array-key, mixed> $schema
     */
    private function laravelUnionAdmitsObjectButNoArray(array $schema): bool
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || $members === []) {
                continue;
            }
            $hasObjectMember = false;
            foreach ($members as $member) {
                if (!is_array($member)) {
                    return false;
                }
                if ($this->laravelSchemaIsObject($member)) {
                    $hasObjectMember = true;

                    continue;
                }
                $types = $this->laravelAdmittedJsonTypes($member);
                if ($types === null || in_array('array', $types, true)) {
                    return false;
                }
                if (in_array('object', $types, true)) {
                    $hasObjectMember = true;
                }
            }

            return $hasObjectMember;
        }

        return false;
    }

    /**
     * The class of a `Lime|array` union whose document also admits an object the class may refuse —
     * `anyOf: [{$ref: Lime}, {type: object}]`. Any array with keys used to be built into the class, and
     * `{"any": 1}`, valid as the free-form member, died in the constructor: a 500. Such a union builds
     * the class only from a value the class's own schema accepts (see `laravelClassMatchCondition()`).
     *
     * @param SchemaProperty $property
     */
    private function laravelUnionClassBesideFreeFormObject(array $property): ?string
    {
        $class = $this->unionClassBesideScalars($property['type']);
        if ($class === null || !$this->unionHasArrayMember($property['type'])) {
            return null;
        }

        // The class member is a `$ref`, or already inlined as one of the object-admitting members — so
        // without a `$ref` it takes two of those for one to be free-form.
        $constraints = $property['constraints'] ?? [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $members = $constraints[$keyword] ?? null;
            if (!is_array($members)) {
                continue;
            }
            $classReferenced = false;
            $objectMembers = 0;
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }
                if (array_key_exists('$ref', $member)) {
                    $classReferenced = true;

                    continue;
                }
                $types = $this->laravelAdmittedJsonTypes($member);
                if ($types === null || in_array('object', $types, true)) {
                    $objectMembers++;
                }
            }
            if ($objectMembers >= ($classReferenced ? 1 : 2)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The PHP condition, beside the "array with keys" test, under which `$valueAccess` is built into
     * the union's class; `null` where every such array is the class.
     *
     * @param SchemaProperty $property
     */
    private function laravelClassMatchCondition(array $property, string $valueAccess): ?string
    {
        $class = $this->laravelUnionClassBesideFreeFormObject($property);

        return $class === null
            ? null
            : sprintf("self::validateOpenApiNode(%s, ['x-openapi-recurse' => %s], '', 0) === []", $valueAccess, $this->phpStringLiteral($class));
    }

    /**
     * The classes of a union of them (see `unionClassMembers()`) that the value alone has to choose
     * between: `[]` where an inline `discriminator` names the member instead — Laravel mode dispatches
     * on it, and laravel-data builds such a union its own way (README.support-matrix, "an inline
     * `discriminator` on a property").
     *
     * A list member beside them is no class (`Olive|Teal|array`): the classes are still chosen between,
     * for a JSON object — the caller tells it from the list by its keys. Left out, laravel-data
     * resolved the FIRST class's rules on every value, and a valid list or Teal was refused for Olive's
     * `required`.
     *
     * @param SchemaProperty $property
     * @return list<string>
     */
    private function laravelUndiscriminatedUnionClasses(array $property): array
    {
        $constraints = $property['constraints'] ?? [];
        // The discriminator may also sit one level down, inside the union a nullable wrapper holds.
        $levels = [$constraints];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($constraints[$keyword] ?? null) ? $constraints[$keyword] : [] as $branch) {
                $levels[] = is_array($branch) ? $branch : [];
            }
        }
        foreach ($levels as $level) {
            if (array_key_exists('x-discriminator-property', $level) || array_key_exists('discriminator', $level)) {
                return [];
            }
        }

        $members = array_filter(
            array: $this->unionMembersBesideNull($property['type']),
            callback: static fn(string $member): bool => $member !== 'array',
        );

        return $this->unionClassMembers(implode('|', $members));
    }

    /**
     * The PHP condition under which `$valueAccess` is a JSON object one of the classes of such a union
     * may be built from: an array, and beside a list member one with keys.
     */
    private function laravelUnionClassesObjectCondition(string $phpType, string $valueAccess): string
    {
        return $this->unionHasArrayMember($phpType)
            ? sprintf('is_array(%1$s) && !array_is_list(%1$s)', $valueAccess)
            : sprintf('is_array(%s)', $valueAccess);
    }

    /**
     * The PHP condition under which `$valueAccess` matches the folded schema of one member of a union of
     * classes (see `laravelUndiscriminatedUnionClasses()`); its fold is recorded by
     * `laravelInterpreterConstraints()`.
     */
    private function laravelUnionMemberMatchCondition(string $className, string $valueAccess): string
    {
        return sprintf("self::validateOpenApiNode(%s, ['x-openapi-recurse' => %s], '', 0) === []", $valueAccess, $this->phpStringLiteral($className));
    }

    /**
     * The JSON types a subschema admits, read through its nested `allOf` (every part must hold, so the
     * types the typed parts share) and `anyOf` / `oneOf` (any member may, so all of them); `null` when
     * that cannot be told — a member with no type, a `$ref`.
     *
     * @param array<array-key, mixed> $schema
     * @return array<int, string>|null
     */
    private function laravelAdmittedJsonTypes(array $schema): ?array
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            $types = [$type];
        } elseif (is_array($type) && $type !== []) {
            $types = array_values(array_filter(array: $type, callback: 'is_string'));
        } else {
            $types = null;
        }
        // `integer` is a `number`; the two only matter here as "not a container".
        $types = $types !== null && in_array('number', $types, true) ? [...$types, 'integer'] : $types;

        foreach (['anyOf', 'oneOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || $members === []) {
                continue;
            }
            $admitted = [];
            foreach ($members as $member) {
                $memberTypes = is_array($member) ? $this->laravelAdmittedJsonTypes($member) : null;
                if ($memberTypes === null) {
                    $admitted = null;

                    break;
                }
                $admitted = [...$admitted, ...$memberTypes];
            }
            if ($admitted !== null) {
                $types = $types === null ? $admitted : array_values(array_intersect($types, $admitted));
            }
        }

        $parts = $schema['allOf'] ?? null;
        foreach (is_array($parts) ? $parts : [] as $part) {
            $partTypes = is_array($part) ? $this->laravelAdmittedJsonTypes($part) : null;
            if ($partTypes !== null) {
                $types = $types === null ? $partTypes : array_values(array_intersect($types, $partTypes));
            }
        }

        return $types === null ? null : array_values(array_unique($types));
    }

    /**
     * Every path whose schema is `integer`, `number` or `boolean` — at the property, inside nested DTOs and
     * in container items (`score`, `author.age`, `scores.*`) — with that type.
     *
     * Laravel's own rules for those types are lenient: `integer` passes `"5"`, `boolean` passes `0`, `1`,
     * `"0"` and `"1"`. Over a query string that is right, everything there is a string; over a JSON body it
     * accepts a value the schema refuses and every other mode rejects. The strict rule variants are no
     * answer: they arrived in Laravel 12.22, above this mode's floor, and `integer:strict` also refuses
     * `42.0`, which JSON Schema counts as an integer. So these paths are checked against the raw body
     * instead, where a string is still a string, from `withValidator()`.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $visitedClasses guards a self-referential schema
     * @return array<string, string>
     */
    private function laravelScalarTypePaths(array $property, string $prefix, array $visitedClasses = [], bool $recurse = true): array
    {
        $segment = $this->laravelPathSegment($property['openApiName']);
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
        // No `field.*` below a `mixed` value — see `laravelItemRulesForProperty()`.
        $paths = $property['type'] === 'mixed'
            ? []
            : $this->laravelScalarTypePathsIn($this->laravelConstraintsFor($property), $path, 1);

        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);

        // An int-backed enum is an integer on the wire, and `Rule::enum()` lets `"2"` through as 2.
        $enumClass = $this->laravelEnumClass($property);
        if ($enumClass !== null && $this->laravelIsIntBackedEnum($enumClass)) {
            $paths[$path] = 'integer';
        } elseif ($itemClass !== null && $this->laravelIsEnumClass($itemClass) && $this->laravelIsIntBackedEnum($itemClass)) {
            $paths[$path . '.*'] = 'integer';
        }
        $recurseClass = $nestedClass ?? ($itemClass !== null && !$this->laravelIsEnumClass($itemClass) ? $itemClass : null);
        if (!$recurse || $recurseClass === null || in_array($recurseClass, $visitedClasses, true)) {
            return $paths;
        }

        $visitedClasses[] = $recurseClass;
        $childPrefix = $nestedClass !== null ? $path : $path . '.*';
        foreach ($this->getSchemaProperties($recurseClass) as $childProperty) {
            foreach ($this->laravelScalarTypePaths($childProperty, $childPrefix, $visitedClasses) as $childPath => $childType) {
                $paths[$childPath] = $childType;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, string>
     */
    private function laravelScalarTypePathsIn(array $schema, string $path, int $hop): array
    {
        $type = $schema['type'] ?? null;
        $type = is_array($type) ? $this->laravelSoleNonNullType($type) : $type;
        if (in_array($type, ['integer', 'number', 'boolean'], true)) {
            return [$path => $type];
        }

        // As deep as the dotted rules go, and no deeper: past that hop nothing is validated by a rule.
        $paths = [];
        if ($hop < self::LARAVEL_MAX_DOTTED_RULE_HOP) {
            foreach (['items', 'additionalProperties'] as $key) {
                if ($this->laravelWildcardReaches($schema, $key)) {
                    $paths = [...$paths, ...$this->laravelScalarTypePathsIn($schema[$key], $path . '.*', $hop + 1)];
                }
            }
        }

        return $paths;
    }

    /**
     * Whether a nested DTO adds its own rules and wire-shape paths, through
     * `openApiNestedValidation()`, instead of having them written out under the property's path.
     *
     * Written out per path, a class two properties reach was written twice, and so was everything below
     * it: twenty schemas each naming the two before them grew like Fibonacci, into a 15 MB class and an
     * out-of-memory fatal. Asked of the nested class, each class is written once. A class this mode emits
     * as an INTERFACE — a union, an allOf discriminator base — cannot carry the method, and is still
     * written out.
     */
    private function laravelComposesNested(string $className): bool
    {
        $schema = $this->dtoSchemas[$className] ?? null;

        return is_array($schema)
            && !array_key_exists('oneOf', $schema)
            && !array_key_exists('anyOf', $schema)
            && !$this->laravelIsAllOfDiscriminatorBase($className);
    }

    /**
     * The nested class a property hands its validation to, and the path below the property it applies
     * at: the property itself for a nested DTO, `<property>.*` for a list or map of them.
     *
     * A union of generated classes counts as its first member only below the root — `$belowRoot` — where
     * laravel-data's own inference never reaches, see `laravelDataFirstUnionMember()`.
     *
     * @param SchemaProperty $property
     * @return array{class: string, suffix: string}|null
     */
    private function laravelComposedChild(array $property, bool $belowRoot = false): ?array
    {
        $segment = $this->laravelPathSegment($property['openApiName']);
        $nestedClass = $this->laravelDtoClass($property);
        $itemClass = $this->laravelDtoItemClass($property);
        $firstMember = $belowRoot ? $this->laravelDataFirstUnionMember($property) : null;
        if ($nestedClass !== null) {
            $child = ['class' => $nestedClass, 'suffix' => $segment];
        } elseif ($firstMember !== null) {
            $child = ['class' => $firstMember, 'suffix' => $segment];
        } elseif ($itemClass !== null && !$this->laravelIsEnumClass($itemClass)) {
            $child = ['class' => $itemClass, 'suffix' => $segment . '.*'];
        } else {
            return null;
        }

        return $this->laravelComposesNested($child['class']) ? $child : null;
    }

    /**
     * laravel-data builds a property typed by a union of generated classes (`Circle|Oval`) as its FIRST
     * member, and checks it as that member too — on the root class, through its own rule inference. Below
     * the root nothing did: the union's interpreter check saw the branches without the enums they had
     * moved into the PHP type, let a tag through, and the cast to the first member's enum was a
     * `CannotCastEnum` 500. The first member's rules are added where the union sits, as on the root.
     *
     * @param SchemaProperty $property
     */
    private function laravelDataFirstUnionMember(array $property): ?string
    {
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_LARAVEL_DATA || str_contains($property['type'], '<')) {
            return null;
        }
        $members = array_values(array_filter(
            array: explode('|', ltrim($property['type'], '?')),
            callback: static fn(string $member): bool => !in_array(strtolower($member), ['null', 'optional'], true),
        ));
        if (count($members) < 2) {
            return null;
        }
        $classes = [];
        foreach ($members as $member) {
            $className = $this->shortClassName(ltrim($member, '\\'));
            if (!array_key_exists($className, $this->dtoSchemas) || $this->laravelIsEnumClass($className)) {
                return null;
            }
            $classes[] = $className;
        }

        return $classes[0];
    }

    /**
     * The classes some property hands its validation to; each of them carries `openApiNestedValidation()`.
     *
     * @return array<string, true>
     */
    private function laravelComposedTargets(): array
    {
        if ($this->laravelComposedTargetCache !== null) {
            return $this->laravelComposedTargetCache;
        }

        $targets = [];
        foreach (array_keys($this->dtoSchemas) as $owner) {
            foreach ($this->getSchemaProperties($owner) as $property) {
                $child = $this->laravelComposedChild(property: $property, belowRoot: true);
                if ($child !== null) {
                    $targets[$child['class']] = true;
                }
            }
        }

        return $this->laravelComposedTargetCache = $targets;
    }

    /**
     * What `openApiNestedValidation()` of a class writes: each property's rules with presence left out —
     * no rule can say "required only if the parent has a value", the interpreter owns it — its item
     * rules, its object and scalar paths, and the nested classes asked for the rest. A nested class that
     * cannot be asked is written out here, as before.
     *
     * @return array{rules: list<array{suffix: string, rules: string}>, objectPaths: list<string>, scalarPaths: array<string, string>, children: list<array{class: string, suffix: string}>}
     */
    private function laravelNestedValidationPlan(string $className): array
    {
        $rules = [];
        $objectPaths = [];
        $scalarPaths = [];
        $children = [];
        foreach ($this->getSchemaProperties($className) as $property) {
            $segment = $this->laravelPathSegment($property['openApiName']);
            $ownRules = array_values(array_filter(
                // Written into a parent's rules under the parent's path, so never the parent's own.
                array: $this->laravelRulesForProperty($property, false),
                callback: static fn(string $rule): bool => $rule !== "'present'" && $rule !== "'sometimes'",
            ));
            if ($ownRules !== []) {
                $rules[] = ['suffix' => $segment, 'rules' => $this->laravelRulesLiteral($ownRules)];
            }
            foreach ($this->laravelItemRulesForProperty($property) as $itemPath => $itemRules) {
                $rules[] = ['suffix' => $itemPath, 'rules' => $this->laravelRulesLiteral($itemRules)];
            }

            $child = $this->laravelComposedChild(property: $property, belowRoot: true);
            if ($child !== null) {
                $children[] = $child;
            } else {
                foreach ($this->laravelNestedRules($property, [$className]) as $nestedPath => $nestedRules) {
                    $rules[] = ['suffix' => $nestedPath, 'rules' => $this->laravelRulesLiteral($nestedRules)];
                }
            }
            foreach ($this->laravelObjectShapePaths($property, '', [$className], recurse: $child === null) as $objectPath) {
                $objectPaths[] = $objectPath;
            }
            foreach ($this->laravelScalarTypePaths($property, '', [$className], recurse: $child === null) as $scalarPath => $scalarType) {
                $scalarPaths[$scalarPath] = $scalarType;
            }
        }

        return [
            'rules' => $rules,
            'objectPaths' => array_values(array_unique($objectPaths)),
            'scalarPaths' => $scalarPaths,
            'children' => $children,
        ];
    }

    /**
     * Whether anything below a class contributes a path of the given kind (`objectPaths` or
     * `scalarPaths`; `escapedDots` and `memberGuards` for a path the walker must split or read with
     * care), so a parent emits the check only where there is something to check.
     *
     * @param array<int, string> $visited
     */
    private function laravelNestedHasPaths(string $className, string $kind, array $visited = []): bool
    {
        $cutShort = false;

        return $this->laravelNestedHasPathsBelow(className: $className, kind: $kind, visited: $visited, cutShort: $cutShort);
    }

    /**
     * An answer that stopped at a class among `$visited` holds only for that `$visited`: cached, it
     * told another parent — one the cycle does not pass through — that nothing below had a path,
     * and that parent dropped the checks. Only an answer the cycle did not touch is cached.
     *
     * @param array<int, string> $visited
     */
    private function laravelNestedHasPathsBelow(string $className, string $kind, array $visited, bool &$cutShort): bool
    {
        $key = $kind . ':' . $className;
        if (array_key_exists($key, $this->laravelNestedPathsCache)) {
            return $this->laravelNestedPathsCache[$key];
        }
        if (in_array($className, $visited, true)) {
            $cutShort = true;

            return false;
        }

        $plan = $this->laravelNestedValidationPlan($className);
        $has = match ($kind) {
            'escapedDots' => array_filter([...$plan['objectPaths'], ...array_keys($plan['scalarPaths'])], static fn(string $path): bool => str_contains($path, '\.')) !== [],
            'memberGuards' => array_filter($plan['objectPaths'], $this->laravelPathHasMemberGuard(...)) !== [],
            default => $plan[$kind] !== [],
        };
        $childCutShort = false;
        foreach ($plan['children'] as $child) {
            if ($has) {
                break;
            }
            $has = $this->laravelNestedHasPathsBelow(
                className: $child['class'],
                kind: $kind,
                visited: [...$visited, $className],
                cutShort: $childCutShort,
            );
        }
        if ($childCutShort && !$has) {
            $cutShort = true;

            return false;
        }

        return $this->laravelNestedPathsCache[$key] = $has;
    }

    /**
     * A class reference for emitted code: fully qualified, which the import pass turns into a `use`.
     */
    private function laravelDtoClassRef(string $className): string
    {
        $short = $this->shortClassName($className);

        return '\\' . ltrim(($this->schemaNamespaces[$short] ?? $this->baseNamespace) . '\\' . $short, '\\');
    }

    /**
     * The template variables of nested composition: the calls `openApiNestedParts()` makes, this class's
     * own `openApiNestedValidation()` when another class holds it, and where the wire-shape checks read
     * their paths from — this class's constant, the nested classes' answers, or both.
     *
     * @param list<array{class: string, suffix: string}> $nestedChildren
     * @param list<string> $objectPaths this class's own object paths
     * @param array<string, string> $scalarPaths this class's own scalar paths
     * @param bool $checksScalarPaths whether the class checks scalar paths at all; a parameter class
     *   does not — a query string has no JSON types
     * @return array{
     *   nestedChildren: list<array{ref: string, suffix: string}>,
     *   nestedValidation: array<string, mixed>|null,
     *   checksObjectPaths: bool,
     *   checksScalarPaths: bool,
     *   objectPathsExpression: string,
     *   scalarPathsExpression: string,
     *   nestedPathsEscapeDots: bool,
     *   rawPathsSelectMembers: bool,
     *   selfReachable: bool,
     *   reachesRecursion: bool,
     * }
     */
    private function laravelNestedTemplateVars(
        string $className,
        array $nestedChildren,
        array $objectPaths,
        array $scalarPaths,
        bool $checksScalarPaths,
    ): array {
        $childObjectPaths = false;
        $childScalarPaths = false;
        $childEscapedDots = false;
        $childMemberGuards = false;
        foreach ($nestedChildren as $child) {
            $childObjectPaths = $childObjectPaths || $this->laravelNestedHasPaths($child['class'], 'objectPaths', [$className]);
            $childScalarPaths = $childScalarPaths || ($checksScalarPaths && $this->laravelNestedHasPaths($child['class'], 'scalarPaths', [$className]));
            $childEscapedDots = $childEscapedDots || $this->laravelNestedHasPaths($child['class'], 'escapedDots', [$className]);
            $childMemberGuards = $childMemberGuards || $this->laravelNestedHasPaths($child['class'], 'memberGuards', [$className]);
        }

        $nestedValidation = null;
        if (array_key_exists($className, $this->laravelComposedTargets())) {
            $plan = $this->laravelNestedValidationPlan($className);
            $nestedValidation = [
                'rules' => $plan['rules'],
                'objectPaths' => $plan['objectPaths'],
                'scalarPaths' => $plan['scalarPaths'],
                'children' => array_map(
                    callback: fn(array $child): array => ['ref' => $this->laravelDtoClassRef($child['class']), 'suffix' => $child['suffix']],
                    array: $plan['children'],
                ),
            ];
        }

        $paths = static fn(string $own, bool $hasOwn, bool $hasNested, string $kind): string => match (true) {
            $hasOwn && $hasNested => sprintf("[...%s, ...self::openApiNestedParts()['%s']]", $own, $kind),
            $hasNested => sprintf("self::openApiNestedParts()['%s']", $kind),
            default => $own,
        };

        return [
            'nestedChildren' => array_map(
                callback: fn(array $child): array => ['ref' => $this->laravelDtoClassRef($child['class']), 'suffix' => $child['suffix']],
                array: $nestedChildren,
            ),
            'nestedValidation' => $nestedValidation,
            'checksObjectPaths' => $objectPaths !== [] || $childObjectPaths,
            'checksScalarPaths' => $scalarPaths !== [] || $childScalarPaths,
            'objectPathsExpression' => $paths('self::OPENAPI_OBJECT_PATHS', $objectPaths !== [], $childObjectPaths, 'objectPaths'),
            'scalarPathsExpression' => $paths('self::OPENAPI_SCALAR_PATHS', $scalarPaths !== [], $childScalarPaths, 'scalarPaths'),
            'nestedPathsEscapeDots' => $childEscapedDots,
            // A member guard below, or in this class's own paths: the walker has to read `{kind=cat}`.
            'rawPathsSelectMembers' => $childMemberGuards || array_filter($objectPaths, $this->laravelPathHasMemberGuard(...)) !== [],
            'selfReachable' => $this->laravelReachesItself(
                className: $className,
                children: array_column($nestedChildren, 'class'),
            ),
            'reachesRecursion' => $this->laravelReachesRecursion(
                className: $className,
                children: array_column($nestedChildren, 'class'),
            ),
        ];
    }

    /**
     * Whether a class, or one anywhere below it, holds itself. Such a subtree meets a cycle guard in
     * `openApiNestedValidation()`, and the wire-shape paths below that point are found by following the
     * payload (`openApiRecursivePaths()`), which needs the re-entry points passed up to this class.
     *
     * @param list<string> $children the classes directly below
     */
    private function laravelReachesRecursion(string $className, array $children): bool
    {
        if ($this->laravelReachesItself(className: $className, children: $children)) {
            return true;
        }

        $pending = $children;
        $seen = [];
        while ($pending !== []) {
            $next = array_shift($pending);
            if (array_key_exists($next, $seen) || !array_key_exists($next, $this->laravelComposedTargets())) {
                continue;
            }
            $seen[$next] = true;
            $grandChildren = array_column($this->laravelNestedValidationPlan($next)['children'], 'class');
            if ($this->laravelReachesItself(className: $next, children: $grandChildren)) {
                return true;
            }
            array_push($pending, ...$grandChildren);
        }

        return false;
    }

    /**
     * Whether a class holds itself somewhere below — a tree. Only such a class meets its own cycle guard
     * in `openApiNestedValidation()`, so only it needs the rule that checks the levels below as the data
     * reaches them, and the rule sets joined by path that carry it.
     *
     * @param list<string> $children the classes directly below
     */
    private function laravelReachesItself(string $className, array $children): bool
    {
        $pending = $children;
        $seen = [];
        while ($pending !== []) {
            $next = array_shift($pending);
            if ($next === $className) {
                return true;
            }
            if (array_key_exists($next, $seen) || !array_key_exists($next, $this->laravelComposedTargets())) {
                continue;
            }
            $seen[$next] = true;
            foreach ($this->laravelNestedValidationPlan($next)['children'] as $child) {
                $pending[] = $child['class'];
            }
        }

        return false;
    }

    /**
     * A nested class's fold, stored once in `OPENAPI_RECURSIVE_SCHEMAS` and pointed at, where written
     * out it was repeated under every path that reaches the class — exponentially, for classes that
     * reach one another along several paths. The pointer is the one cycles already use.
     *
     * Only where the fold cannot depend on the path: a class that reaches back to one ABOVE it folds
     * that one into a cycle marker, and a fold computed elsewhere would expand it instead. And only where
     * the marker means the same: its local keywords win over the stored ones, a written-out fold won over
     * the local ones, so a keyword in both keeps the written-out form.
     *
     * @param array<int, string> $visitedClasses
     * @param array<string, mixed> $local the keywords the marker sits beside
     * @return array{key: string, fold: array<string, mixed>}|null
     */
    private function laravelSharedFold(string $target, array $visitedClasses, bool $insideRecursiveFold, array $local): ?array
    {
        if (array_intersect_key(array_flip($visitedClasses), $this->laravelReachableClasses($target)) !== []) {
            return null;
        }

        // Inside a recursive fold nothing is pruned, which is what a cycle's own fold is: the same entry.
        $key = $insideRecursiveFold ? $target : $target . '@rules';
        if (!array_key_exists($key, $this->laravelRecursiveFolds)) {
            $this->laravelRecursiveFolds[$key] = [];
            $this->laravelRecursiveFolds[$key] = $this->laravelInterpreterSchemaForClass(
                className: $target,
                visitedClasses: [$target],
                insideRecursiveFold: $insideRecursiveFold,
            );
        }
        $fold = $this->laravelRecursiveFolds[$key];
        if (array_intersect_key($fold, $local) !== []) {
            return null;
        }

        return ['key' => $key, 'fold' => $fold];
    }

    /**
     * Every class a class's fold can reach — through its properties, list items and map values, and an
     * allOf base's variants — itself excluded unless a cycle leads back to it.
     *
     * @return array<string, true>
     */
    private function laravelReachableClasses(string $className): array
    {
        if (array_key_exists($className, $this->laravelReachableCache)) {
            return $this->laravelReachableCache[$className];
        }

        $reached = [];
        $queue = [$className];
        while ($queue !== []) {
            $current = array_pop($queue);
            $next = [];
            if ($this->laravelIsAllOfDiscriminatorBase($current)) {
                foreach ($this->discriminatorSchemas[$current]['mapping'] as $variant) {
                    $next[] = $variant;
                }
            }
            foreach ($this->getSchemaProperties($current) as $property) {
                $target = $this->laravelDtoClass($property) ?? $this->laravelDtoItemClass($property);
                if ($target !== null && !$this->laravelIsEnumClass($target)) {
                    $next[] = $target;
                }
            }
            foreach ($next as $class) {
                if (!array_key_exists($class, $reached)) {
                    $reached[$class] = true;
                    $queue[] = $class;
                }
            }
        }

        return $this->laravelReachableCache[$className] = $reached;
    }

    /**
     * Whether `withValidator()` has to tell an EMPTY JSON object from an empty JSON array.
     *
     * Decoded, `{}` and `[]` are one PHP array, so a list rule took `{"ant": {}}` and the `array` member
     * of `oneOf: [{type: object, required: [zebu]}, {type: array}]` took `{"bee": {}}`. The raw body
     * still says which it was: a `list` rule anywhere the class reaches, or an `array` type the
     * interpreter judges, is where the answer matters. A parameter class reads no JSON body.
     *
     * @param array<string, mixed> $interpreterConstraints
     * @param array<string, mixed> $recursiveSchemas
     */
    private function laravelReadsEmptyContainers(string $className, array $interpreterConstraints, array $recursiveSchemas): bool
    {
        if (array_key_exists($className, $this->parameterPayloadClasses)) {
            return false;
        }

        return $this->laravelSchemaNamesArrayType($interpreterConstraints)
            || $this->laravelSchemaNamesArrayType($recursiveSchemas)
            || $this->laravelReachesListRule($className);
    }

    /**
     * Whether a list rule is written for the class or for any class its rules reach.
     */
    private function laravelReachesListRule(string $className): bool
    {
        foreach ([$className, ...array_keys($this->laravelReachableClasses($className))] as $class) {
            foreach ($this->getSchemaProperties($class) as $property) {
                $ruleSets = [
                    $this->laravelRulesForProperty($property, false),
                    ...array_values($this->laravelItemRulesForProperty($property)),
                ];
                foreach ($ruleSets as $rules) {
                    if (in_array("'list'", $rules, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether a `type: array` (alone or in a type list) appears anywhere in a constraint tree.
     */
    private function laravelSchemaNamesArrayType(mixed $schema): bool
    {
        if (!is_array($schema)) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if ($type === 'array' || (is_array($type) && in_array('array', $type, true))) {
            return true;
        }
        foreach ($schema as $value) {
            if ($this->laravelSchemaNamesArrayType($value)) {
                return true;
            }
        }

        return false;
    }
}
