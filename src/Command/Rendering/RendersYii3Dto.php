<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * yii3-mode emitter: one `yiisoft/input-http` input class per schema, decorated with
 * `yiisoft/validator` attributes and hydrated by `yiisoft/hydrator`.
 *
 *     final class UpdatePostInput extends AbstractInput { … }   // action type-hints it; it is populated
 *     $input->getValidationResult();                            // …and the ACTION reads the verdict
 *
 * The second line is the honest difference from every other framework mode. Symfony, Laravel and
 * laravel-data all turn a failed validation into a 422 before the action body runs; Yii3 hydrates
 * automatically but reports through `ValidatedInputInterface::getValidationResult()`, so turning a
 * failure into a response stays application code. The emitted class cannot close that gap.
 *
 * Four facts were measured against the real packages before any of this was written, and each one
 * decided a shape rather than a detail:
 *
 * - `#[Callback]` is `TARGET_CLASS` and fires ONCE per object with the DTO itself as its value, so the
 *   interpreter uses the SYMFONY packaging (one entry point per object, paths set by the callback)
 *   rather than the Laravel one. `Result::addError($message, $parameters, $valuePath)` sets nested
 *   paths, which is the `atPath()` equivalent.
 * - a bare `#[Nested]` cascades into the nested class's own attributes, so recursive schemas need no
 *   repeated rule set — the single most expensive thing in Laravel mode does not exist here.
 * - the hydrator reports NOTHING about which keys the payload carried (`hydrate`/`create` is its whole
 *   public API), so presence tracking is emitted bookkeeping, the Laravel way, not laravel-data's
 *   `Optional`.
 * - `#[FromBody]`/`#[FromQuery]` are class-level only, there is no `#[FromRoute]`, and per-property
 *   sources are `#[Body]`, `#[Query]`, `#[Request]` (request attributes — where routers put path
 *   parameters) and `#[UploadedFiles]`. There is no header or cookie attribute at all, which is why
 *   those two OpenAPI sources are unsupported in this mode.
 *
 * @phpstan-import-type SchemaProperty from \OpenapiPhpDtoGenerator\Command\GenerateDtoCommand
 */
trait RendersYii3Dto
{
    /** Base class every emitted input extends; carries `ValidatedInputTrait`. */
    private const string YII3_ABSTRACT_INPUT = 'Yiisoft\Input\Http\AbstractInput';

    /**
     * OpenAPI `format` values this mode enforces with a native rule, and the rule that does it.
     *
     * Everything absent from here is left to the interpreter — an unknown `format` is an annotation
     * rather than an assertion, and a format the validator has no rule for must not be silently
     * dropped.
     *
     * @var array<string, string>
     */
    private const array YII3_FORMAT_RULES = [
        'email' => 'Email',
        'uuid' => 'Uuid',
        'uri' => 'Url',
        'url' => 'Url',
        'ipv4' => 'Ip',
        'ipv6' => 'Ip',
        'date' => 'Date',
        'date-time' => 'DateTime',
        'time' => 'Time',
    ];

    /**
     * OpenAPI `type` → the rule that enforces it.
     *
     * `object` and `array` are absent on purpose: the first is a map or a nested DTO, both already
     * shaped by the PHP type, and the second is covered by `#[Each]`/`#[Count]`.
     *
     * @var array<string, string>
     */
    private const array YII3_TYPE_RULES = [
        'string' => 'StringValue',
        'integer' => 'Integer',
        'number' => 'Number',
        'boolean' => 'BooleanValue',
    ];

    /**
     * What a free-form (`mixed`) property is spelled as.
     *
     * `mixed` itself cannot be used: `yiisoft/hydrator` leaves such a property uninitialised and drops
     * the value. The union is the same set written out, and it hydrates.
     */
    private const string YII3_FREE_FORM_UNION = 'string|int|float|bool|array|null';

    /**
     * The wire patterns a temporal property is hydrated from, per OpenAPI `format`.
     *
     * `#[ToDateTime]` takes ONE format, so several are STACKED on the property: measured, the hydrator
     * tries each attribute in turn and the first that parses wins. One rigid pattern was the bug —
     * `2026-03-10T12:00:00.123456+03:00` failed to parse, the hydrator skipped the property, and a
     * value the client DID send came back as "field is required".
     *
     * `date-time` lists exactly `GeneratedDtoInterface::DATE_TIME_FORMATS`, so this mode accepts what
     * every other mode accepts — and, just as importantly, still refuses what they refuse: a loose
     * `"yesterday"` matches none of them and is rejected.
     *
     * @var array<string, array<int, string>>
     */
    private const array YII3_TEMPORAL_WIRE_FORMATS = [
        'date' => ['Y-m-d'],
        'time' => ['H:i:sP', 'H:i:s'],
        'date-time' => ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.up', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'],
    ];

    /** Rules that live under `Rule\Date\`, not directly under `Rule\`. */
    private const array YII3_DATE_NAMESPACE_RULES = ['Date', 'DateTime', 'Time'];

    /**
     * The namespace the yii3 file being rendered lives in, for {@see yii3Lib()}.
     *
     * State rather than a parameter because the library short names it answers about are named in a
     * dozen places across this renderer, several of them deep inside string builders that have no
     * business carrying a namespace through.
     */
    private string $yii3RenderNamespace = '';

    /**
     * The framework classes this mode's emitted code names, by the short name it uses for them.
     *
     * A document may call a schema `Result`, `Data`, `Query` or `Nested` — ordinary words for an API —
     * and the emitted file would then carry `use Yiisoft\Validator\Result;` beside `class Result`,
     * which does not load at all, or let the import shadow the document's class in a sibling file.
     * {@see NamesLibraryClasses}
     */
    private const array YII3_LIBRARY_CLASSES = [
        'AbstractInput' => 'Yiisoft\Input\Http\AbstractInput',
        'BackedEnum' => 'BackedEnum',
        'Callback' => 'Yiisoft\Validator\Rule\Callback',
        'Collection' => 'Yiisoft\Hydrator\Attribute\Parameter\Collection',
        'Data' => 'Yiisoft\Hydrator\Attribute\Parameter\Data',
        'DataSetInterface' => 'Yiisoft\Validator\DataSetInterface',
        'DateTimeImmutable' => 'DateTimeImmutable',
        'DateTimeInterface' => 'DateTimeInterface',
        'ObjectParser' => 'Yiisoft\Validator\Helper\ObjectParser',
        'Query' => 'Yiisoft\Input\Http\Attribute\Parameter\Query',
        'ReflectionProperty' => 'ReflectionProperty',
        'Request' => 'Yiisoft\Input\Http\Attribute\Parameter\Request',
        'Result' => 'Yiisoft\Validator\Result',
        'RulesProviderInterface' => 'Yiisoft\Validator\RulesProviderInterface',
        'ToDateTime' => 'Yiisoft\Hydrator\Attribute\Parameter\ToDateTime',
        'UploadedFileInterface' => 'Psr\Http\Message\UploadedFileInterface',
        'UploadedFiles' => 'Yiisoft\Input\Http\Attribute\Parameter\UploadedFiles',
        'ValidationContext' => 'Yiisoft\Validator\ValidationContext',
        'WhenNull' => 'Yiisoft\Validator\EmptyCondition\WhenNull',
    ];

    /**
     * How the file being rendered must spell a framework class: its short name, or fully qualified
     * when the document owns that name.
     *
     * Answers for every name in {@see YII3_LIBRARY_CLASSES}, and for a `Yiisoft\Validator\Rule\*`
     * rule, whose short name is the attribute the emitted code writes.
     */
    private function yii3Lib(string $shortName, ?string $fqcn = null): string
    {
        $fqcn ??= self::YII3_LIBRARY_CLASSES[$shortName] ?? $shortName;

        return $this->namespaceDeclaresClass($shortName, $this->yii3RenderNamespace)
            ? '\\' . $fqcn
            : $shortName;
    }

    /**
     * Renders one schema as a Yii3 input class.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<string> $unionTypes
     * @param array{propertyName: string, mapping: array<string, string>}|null $discriminator
     */
    private function renderYii3DtoClass(
        string $namespace,
        string $className,
        array $properties,
        array $unionTypes,
        ?array $discriminator = null,
        ?string $extends = null,
        bool $isAbstract = false,
    ): string {
        $this->yii3RenderNamespace = $namespace;

        // Same reasoning as the other attribute modes: a oneOf/anyOf schema is a TYPE, not a data
        // class, so it becomes an interface its members implement.
        if ($unionTypes !== [] || ($isAbstract && $discriminator !== null)) {
            return $this->renderYii3UnionInterface(
                namespace: $namespace,
                className: $className,
                unionTypes: $unionTypes,
                discriminator: $discriminator,
            );
        }

        $useStatements = [self::YII3_ABSTRACT_INPUT];
        $ruleImports = [];
        $sourceImports = [];

        $parameters = [];
        foreach ($properties as $property) {
            $parameters[] = $this->renderYii3Parameter(
                property: $property,
                namespace: $namespace,
                ruleImports: $ruleImports,
                sourceImports: $sourceImports,
                useStatements: $useStatements,
            );
        }

        $phpToOpenApiNameMap = [];
        foreach ($properties as $property) {
            $phpToOpenApiNameMap[$property['name']] = $property['openApiName'];
        }

        // `allowScalarKeywords: true`, unlike Symfony mode. That mode passes false because it has a
        // native `#[Assert\*]` for every scalar keyword; this one does not — `multipleOf`, `const` and
        // most `format` values have no yiisoft rule at all, and with false they were dropped from the
        // interpreter too and enforced NOWHERE. Measured: `{"f":7}` against `multipleOf: 3` was
        // accepted. What the emitted rules DO cover is pruned below instead, so one mistake is still
        // reported once.
        $interpreter = $this->renderYii3InterpreterBlock(
            constraints: $this->yii3PruneNativelyCovered(
                $this->filterSymfonyValidationConstraints(
                    $this->extractValidationConstraints($this->dtoSchemas[$className] ?? []),
                    true,
                    [],
                ),
                $properties,
            ),
            phpToOpenApiNameMap: $phpToOpenApiNameMap,
            // NOT hardcoded to false. The payload view hands the interpreter what the object HOLDS —
            // a generated backed enum, a DateTimeImmutable — not the scalar the client sent, and
            // without these flags the interpreter compares an enum instance against `enum: ['a','b']`
            // and rejects a perfectly valid member. Measured: `{"f":"a"}` came back invalid.
            valueKinds: $this->symfonyCallbackValueKinds($className, array_map(
                static fn(array $parameter): array => [
                    'name' => $parameter['name'],
                    'declaredType' => $parameter['propertyType'],
                    'docType' => $parameter['docType'],
                ],
                $parameters,
            )),
            parameters: $parameters,
        );

        // ALWAYS emitted when the interpreter does not already carry one: `getData()` and
        // `getPropertyValue()` read through it, and an enclosing DTO's callback reads a nested one
        // through it too.
        $standalonePayload = $interpreter['entered'] || $parameters === []
            ? ''
            : $this->renderYii3StandalonePayloadMethod($parameters);

        $classAttributes = $this->yii3ClassAttributes(
            className: $className,
            properties: $properties,
            sourceImports: $sourceImports,
            entersInterpreter: $interpreter['entered'],
        );
        if ($interpreter['entered']) {
            $ruleImports[] = 'Yiisoft\Validator\Result';
            $ruleImports[] = 'Yiisoft\Validator\ValidationContext';
            foreach ($interpreter['imports'] as $import) {
                $ruleImports[] = $import;
            }
        }

        // Computed BEFORE the imports are written: without this the union interface is emitted and
        // nothing implements it, so a property typed by the union could never hold a branch.
        $implements = [];
        foreach ($this->symfonyImplementedUnionInterfaces($className, $extends) as $unionInterface) {
            $this->appendImportForClass($useStatements, $unionInterface, $namespace, $className);
            $implements[] = $this->formatClassNameForNamespace($unionInterface, $namespace);
        }

        // The framework's own contracts, and they are what make presence idiomatic: `hasProperty()`
        // feeds the validator's `$isPropertyMissing` and `getPropertyValue()` answers null for a key
        // the payload never carried, so the framework's own empty conditions work and no invented
        // one is needed. `RulesProviderInterface` is REQUIRED alongside it — measured: with a
        // data set provided, `ObjectDataSet::getRules()` returns early and attribute parsing is
        // "skipped intentionally", so a class implementing only `DataSetInterface` validates NOTHING
        // (a missing required property was accepted). `getRules()` re-exposes the attributes, so the
        // class still reads as attribute-driven.
        // The globals the data-set methods use are IMPORTED, not spelled `\Foo` inline: every other
        // name in the emitted file is imported, and a bare `ReflectionProperty` inside a namespaced
        // class would resolve locally.
        $useStatements[] = 'BackedEnum';
        $useStatements[] = 'DateTimeInterface';
        $useStatements[] = 'ReflectionProperty';
        $useStatements[] = 'Yiisoft\Validator\DataSetInterface';
        $useStatements[] = 'Yiisoft\Validator\RulesProviderInterface';
        $useStatements[] = 'Yiisoft\Validator\Helper\ObjectParser';
        $implements[] = $this->yii3Lib('DataSetInterface');
        $implements[] = $this->yii3Lib('RulesProviderInterface');

        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace ' . $namespace . ';', ''];
        foreach ($this->yii3SortedImports($useStatements, $ruleImports, $sourceImports) as $import) {
            $lines[] = 'use ' . $import . ';';
        }
        $lines[] = '';
        foreach ($classAttributes as $attribute) {
            $lines[] = $attribute;
        }
        // The two framework contracts are always in the list, so there is always something to
        // implement — a union interface simply adds to it.
        $lines[] = 'final class ' . $className . ' extends ' . $this->yii3Lib('AbstractInput')
            . ' implements ' . implode(', ', $implements);
        $lines[] = '{';

        // The wire-name map belongs with the other class constants, before the properties — the same
        // question `#[SerializedName]` answers in Symfony mode, and only interesting when an OpenAPI
        // name and a PHP property name differ.
        $lines[] = '    /** OpenAPI name => PHP property name. */';
        $lines[] = '    private const array OPENAPI_PROPERTY_NAMES = [';
        foreach ($parameters as $parameter) {
            $lines[] = sprintf(
                "        '%s' => '%s',",
                str_replace("'", "\\'", $parameter['openApiName']),
                $parameter['name'],
            );
        }
        $lines[] = '    ];';
        $lines[] = '';

        // `writeOnly` means "accepted on input, never echoed back" — the same thing laravel-data says
        // with `#[Hidden]`. `readOnly` is the mirror: server-to-client only, so a client that sends it
        // anyway must not see it reflected. This mode emits INPUT classes, so both are dropped from
        // the wire form and Yii3 has no attribute for either.
        $lines[] = '    /** Names not written out: writeOnly by definition, readOnly because a client may not set them. */';
        $lines[] = '    private const array OPENAPI_WRITE_ONLY = [';
        foreach ($parameters as $parameter) {
            if (!$parameter['writeOnly']) {
                continue;
            }
            $lines[] = sprintf("        '%s',", str_replace("'", "\\'", $parameter['openApiName']));
        }
        $lines[] = '    ];';
        $lines[] = '';

        // What `getData()` needs to write a temporal property in the shape its schema asked for.
        // Emitted only when there IS one: an empty map would be read by nobody.
        $temporalLines = [];
        foreach ($parameters as $parameter) {
            if ($parameter['temporalFormat'] === null) {
                continue;
            }
            $temporalLines[] = sprintf(
                "        '%s' => '%s',",
                str_replace("'", "\\'", $parameter['openApiName']),
                $parameter['temporalFormat'],
            );
        }
        if ($temporalLines !== []) {
            $lines[] = '    /** OpenAPI name => the `format` its temporal value is written back as. */';
            $lines[] = '    private const array OPENAPI_TEMPORAL_FORMATS = [';
            foreach ($temporalLines as $temporalLine) {
                $lines[] = $temporalLine;
            }
            $lines[] = '    ];';
            $lines[] = '';
        }

        // The interpreter's own constants belong with these, not with the methods that read them
        // 200 lines below: constants come before properties, as in every other mode's output.
        if ($interpreter['entered']) {
            $lines[] = rtrim($interpreter['consts'], "\n");
            $lines[] = '';
        }

        foreach ($parameters as $index => $parameter) {
            if ($parameter['docType'] !== null) {
                $lines[] = '    /** @var ' . $parameter['docType'] . ' */';
            }
            foreach ($parameter['attributes'] as $attribute) {
                $lines[] = '    ' . $attribute;
            }
            $lines[] = '    ' . $parameter['declaration'];
            if ($index !== array_key_last($parameters)) {
                $lines[] = '';
            }
        }

        foreach ($parameters as $parameter) {
            $name = $parameter['name'];
            $lines[] = '';
            if ($parameter['docType'] !== null) {
                $lines[] = '    /** @return ' . $parameter['docType'] . ' */';
            }
            $lines[] = '    public function get' . ucfirst($name) . '(): ' . $parameter['propertyType'];
            $lines[] = '    {';
            $lines[] = $parameter['tracksPresence']
                ? '        return $this->hasProperty(\'' . $parameter['openApiName'] . '\') ? $this->' . $name . ' : null;'
                : '        return $this->' . $name . ';';
            $lines[] = '    }';

            if (!$parameter['tracksPresence']) {
                continue;
            }

            $lines[] = '';
            $lines[] = '    /** Whether the payload carried `' . $parameter['openApiName'] . '` at all. */';
            $lines[] = '    public function is' . ucfirst($name) . 'Provided(): bool';
            $lines[] = '    {';
            $lines[] = '        return $this->hasProperty(\'' . $parameter['openApiName'] . '\');';
            $lines[] = '    }';
        }

        foreach ($this->renderYii3DataSetMethods($parameters) as $line) {
            $lines[] = $line;
        }

        if ($interpreter['entered']) {
            $lines[] = '';
            $lines[] = rtrim($interpreter['methods'], "\n");
        }
        if ($standalonePayload !== '') {
            $lines[] = '';
            $lines[] = rtrim($standalonePayload, "\n");
        }
        $lines[] = '}';

        return GlobalFunctionImports::apply(implode("\n", $lines) . "\n");
    }

    /**
     * One constructor parameter: its source attribute, its validation attributes and its declaration.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $ruleImports
     * @param array<int, string> $sourceImports
     * @param array<int, string> $useStatements
     * @return array{attributes: array<int, string>, declaration: string, docType: string|null,
     *     tracksPresence: bool, propertyType: string, name: string, openApiName: string, writeOnly: bool,
     *     temporalFormat: string|null}
     */
    private function renderYii3Parameter(
        array $property,
        string $namespace,
        array &$ruleImports,
        array &$sourceImports,
        array &$useStatements,
    ): array {
        $attributes = [];

        $source = $this->yii3SourceAttribute($property, $sourceImports);
        if ($source !== null) {
            $attributes[] = $source;
        }

        // The hydrator fills by PHP PROPERTY NAME. A schema whose name differs (`first_name`,
        // `x-trace-id`) therefore bound nothing at all — every such property came back empty.
        // `#[Data]` names the key to read, which is the question `#[SerializedName]` answers in
        // Symfony mode. A per-property SOURCE attribute already carries the wire name itself.
        if ($source === null && $property['openApiName'] !== $property['name']) {
            $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\Data';
            $attributes[] = '#[' . $this->yii3Lib('Data') . "('"
                . str_replace("'", "\\'", $property['openApiName']) . "')]";
        }

        foreach ($this->yii3ValidationAttributes($property, $ruleImports) as $attribute) {
            $attributes[] = $attribute;
        }

        // A generic (`array<Tag>`) is not a PHP type. It declares `array` and keeps the item type in a
        // docblock, exactly as the other attribute modes do — emitting the generic verbatim produced
        // `?array<Tag> $tags`, which does not parse.
        $type = $property['type'];
        $docType = null;
        if (str_contains($type, '<')) {
            $docType = $this->formatDocblockTypeForNamespace($type, $namespace);
            $type = 'array';
        } else {
            $type = $this->formatPhpTypeForNamespace($type, $namespace);
        }

        if ($type === 'DateTimeImmutable' && !in_array('DateTimeImmutable', $useStatements, true)) {
            $useStatements[] = 'DateTimeImmutable';
        }

        // A binary property is PSR-7 here, not Symfony's `UploadedFile`: this mode's output may not
        // depend on symfony/http-foundation, and `input-http` binds uploads with `#[UploadedFiles]`
        // onto exactly that interface. Emitting the bare name was also a straight bug — with no
        // import it resolved inside the DTO's own namespace and the file could not even load.
        if ($type === 'UploadedFile') {
            $type = $this->yii3Lib('UploadedFileInterface');
            $useStatements[] = 'Psr\Http\Message\UploadedFileInterface';
            $sourceImports[] = 'Yiisoft\Input\Http\Attribute\Parameter\UploadedFiles';
            $attributes[] = '#[' . $this->yii3Lib('UploadedFiles') . "('"
                . str_replace("'", "\\'", $property['openApiName']) . "')]";
        }

        // A generated class from ANOTHER namespace has to be imported, or the emitted file names a
        // class that does not exist there. Measured on the demo spec: `TestPostRequest` referenced
        // `Test`, which lives in the `Common` namespace, and every hydration failed with
        // "Argument #5 ($test) not passed" because the type could not resolve. Both the property type
        // and a list's item type need it.
        foreach ([$property['type'], $this->yii3ArrayItemType($property) ?? ''] as $referenced) {
            $referenced = ltrim(preg_replace('/^\??(?:array|list)<|>$/', '', $referenced) ?? '', '\?');
            if (
                $referenced === ''
                || (!array_key_exists($referenced, $this->dtoSchemas) && !array_key_exists($referenced, $this->enumSchemas))
            ) {
                continue;
            }
            $this->appendImportForClass($useStatements, $referenced, $namespace, $property['name']);
        }

        // `mixed` is refused by the hydrator — measured: a `mixed` property, readonly or not, is left
        // UNINITIALISED and the value is dropped, while an explicit union of the same members is
        // filled. A free-form property therefore spells the union out; it also keeps the property
        // typed, which is what makes `isInitialized()` a presence answer at all.
        if ($type === 'mixed') {
            $type = self::YII3_FREE_FORM_UNION;
        }

        // An OPTIONAL property is nullable in PHP even when the schema forbids null, and the schema
        // still wins — the interpreter carries `nullable` and rejects a null the document does not
        // allow. Typing it non-nullable instead looked stricter and was the opposite: the hydrator
        // could not cast null into `string`, left the property uninitialised, and an explicit null
        // then read as "absent" and passed silently.
        //
        // `?` cannot be combined with a union: `?int|string` is a parse error, which a nullable
        // oneOf produced. A union takes an explicit `|null` instead.
        $nullable = $property['nullable'] || !$property['required'];
        // A union that already admits null takes nothing: `string|int|null` plus `|null` is
        // "Duplicate type null is redundant", a fatal at load time — which the free-form union hit
        // the moment it was introduced.
        $alreadyNullable = preg_match('/(^|\|)null(\||$)/', $type) === 1;
        $propertyType = match (true) {
            !$nullable, $alreadyNullable => $type,
            str_contains($type, '|') => $type . '|null',
            default => '?' . $type,
        };

        // A DECLARED, uninitialised typed property — no promotion, no constructor, no sentinel of ours.
        //
        // PHP itself answers the PATCH question: with no constructor the hydrator fills properties
        // directly, so a key the payload did not carry leaves its property UNINITIALISED, and
        // `ReflectionProperty::isInitialized()` reports exactly that. An explicit null initialises it.
        // That is the whole "absent vs sent-as-null" distinction, in the language, with nothing added.
        //
        // Two earlier shapes are recorded in the todo and were both measured: a sentinel enum of our
        // own (worked, but put a type no Yii3 user has ever seen into every optional property), and
        // promoted constructor parameters (the hydrator stops filling a constructor the moment the
        // class declares properties of its own).
        return [
            'attributes' => $attributes,
            'declaration' => 'public readonly ' . $propertyType . ' $' . $property['name'] . ';',
            'docType' => $docType,
            'tracksPresence' => !$property['required'],
            'propertyType' => $propertyType,
            'name' => $property['name'],
            'openApiName' => $property['openApiName'],
            'writeOnly' => ($property['writeOnly'] ?? false) === true || ($property['readOnly'] ?? false) === true,
            'temporalFormat' => $this->yii3TemporalFormat($property, $propertyType),
        ];
    }

    /**
     * Whether a `format` rule can actually judge this property's value.
     *
     * The `Rule\Date\*` family needs a `DateTimeInterface` (or an `IntlDateFormatter` pattern to parse
     * with). Measured against the real validator: over a plain string every one of them fails — `Time`
     * rejected `13:45:00Z`, a value the document allows. Only `format: date` and `date-time` become a
     * `DateTimeImmutable` here, so `format: time` stays a string and its rule is not emitted at all.
     * The interpreter owns that format instead, and refuses `99:99` exactly as the other modes do.
     *
     * @param SchemaProperty $property
     */
    private function yii3FormatRuleApplies(array $property, string $rule): bool
    {
        if (!in_array($rule, self::YII3_DATE_NAMESPACE_RULES, true)) {
            return true;
        }

        return str_contains($property['type'], 'DateTimeImmutable');
    }

    /**
     * The OpenAPI `format` of a temporal property, or null when the property is not one.
     *
     * `getData()` needs it: `format: date` writes `2026-03-10`, not a whole timestamp, and writing the
     * same ISO-8601 string for every temporal was measured as a normalization divergence from every
     * other mode.
     *
     * @param SchemaProperty $property
     */
    private function yii3TemporalFormat(array $property, string $propertyType): ?string
    {
        // The declared PHP type of an array property is just `array` — the item type lives in the
        // generator's own type string, which is where an `array<DateTimeImmutable>` says so.
        if (!str_contains($propertyType, 'DateTimeImmutable') && !str_contains($property['type'], 'DateTimeImmutable')) {
            return null;
        }

        $constraints = $property['constraints'] ?? [];
        $format = $constraints['format'] ?? null;
        if (!is_string($format)) {
            // An ARRAY or MAP of temporal values carries the format one level down. `getData()`
            // hands the same format to every item it walks, so naming it here is all the wire form
            // needs — without it a `date` array is written back as a list of date-times.
            $items = $constraints['items'] ?? $constraints['additionalProperties'] ?? null;
            $format = is_array($items) ? ($items['format'] ?? null) : null;
        }

        return is_string($format) && array_key_exists($format, self::YII3_TEMPORAL_WIRE_FORMATS)
            ? $format
            : null;
    }

    /**
     * The per-property source attribute, or null when the property comes from the class-level source.
     *
     * `in: header` and `in: cookie` have no attribute in `yiisoft/input-http`, so a property declaring
     * either is emitted WITHOUT a source rather than with a wrong one — the application binds it.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $sourceImports
     */
    private function yii3SourceAttribute(array $property, array &$sourceImports): ?string
    {
        if (($property['inPath'] ?? false) === true) {
            $sourceImports[] = 'Yiisoft\Input\Http\Attribute\Parameter\Request';
            return '#[' . $this->yii3Lib('Request') . '(\'' . $property['openApiName'] . '\')]';
        }

        if (($property['inQuery'] ?? false) === true) {
            $sourceImports[] = 'Yiisoft\Input\Http\Attribute\Parameter\Query';
            return '#[' . $this->yii3Lib('Query') . '(\'' . $property['openApiName'] . '\')]';
        }

        return null;
    }

    /**
     * Validation attributes for one property, native rules only.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $ruleImports
     * @return array<int, string>
     */
    private function yii3ValidationAttributes(array $property, array &$ruleImports): array
    {
        $attributes = [];
        $constraints = $property['constraints'] ?? [];

        // NO `#[Required]`, ever. Yii's rule means "not blank", OpenAPI's keyword means "the key is
        // present", and the two disagree on every empty value: an explicit null was rejected with
        // "Name cannot be blank." and a legal `{}` for a free-form object with "F cannot be blank." —
        // payloads every other mode accepts. Presence needs no rule here: an absent required key
        // leaves its property uninitialised, and the interpreter reports that once.

        // The PHP type declaration is NOT a type check here: the hydrator's `PhpNativeTypeCaster`
        // COERCES, so `{"f":5}` filled a `string $f` with "5" and the payload was accepted. Measured
        // against the other modes, which all reject it. The type rule is what restores the verdict.
        // Only when the PHP type is the scalar the schema names. `type: string` also describes a
        // `format: date-time` (a `DateTimeImmutable`) and an `enum` (a generated backed enum), and
        // `#[StringValue]` on either fails against the very value the schema allows — measured on the
        // demo spec, where every payload was rejected with "Type must be a string." for a property
        // holding a perfectly valid enum member.
        // The lookup is guarded rather than defaulted: a schema with no `type` at all gives null, and
        // a UNION type gives an array — both are legal OpenAPI and both are an illegal array offset
        // ("Using null as an array offset is deprecated" on every schema without a `type`).
        $declaredType = $constraints['type'] ?? null;
        $typeRule = is_string($declaredType)
            ? (self::YII3_TYPE_RULES[$declaredType] ?? null)
            : null;
        if ($typeRule !== null && $this->yii3TypeRuleApplies($property)) {
            $attributes[] = $this->yii3Rule($typeRule, [], $ruleImports);
        }

        $min = $constraints['minLength'] ?? null;
        $max = $constraints['maxLength'] ?? null;
        if ($min !== null || $max !== null) {
            $args = [];
            if ($min !== null) {
                $args[] = 'min: ' . (int)$min;
            }
            if ($max !== null) {
                $args[] = 'max: ' . (int)$max;
            }
            $attributes[] = $this->yii3Rule('Length', $args, $ruleImports);
        }

        foreach (
            [
                'minimum' => 'GreaterThanOrEqual',
                'maximum' => 'LessThanOrEqual',
                'exclusiveMinimum' => 'GreaterThan',
                'exclusiveMaximum' => 'LessThan',
            ] as $keyword => $rule
        ) {
            $bound = $constraints[$keyword] ?? null;
            if (is_numeric($bound) && !$this->yii3BoundIsExclusiveByModifier($constraints, $keyword)) {
                $attributes[] = $this->yii3Rule($rule, [$this->yii3NumberLiteral($bound)], $ruleImports);
            }
        }

        $pattern = $constraints['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $attributes[] = $this->yii3Rule('Regex', [$this->yii3RegexLiteral($pattern)], $ruleImports);
        }

        $format = $constraints['format'] ?? null;
        if (
            is_string($format)
            && array_key_exists($format, self::YII3_FORMAT_RULES)
            && $this->yii3FormatRuleApplies($property, self::YII3_FORMAT_RULES[$format])
        ) {
            $attributes[] = $this->yii3Rule(self::YII3_FORMAT_RULES[$format], [], $ruleImports);
        }

        // A `DateTimeImmutable` property needs a HYDRATOR attribute as well as a rule: without it the
        // string never becomes a date, the parameter falls back to its default and a value the client
        // DID send reads back as "not provided". Measured on the demo spec.
        if (str_contains($property['type'], 'DateTimeImmutable')) {
            $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\ToDateTime';
            // A `php:` format keeps this off ext-intl: a bare `#[ToDateTime]` goes through
            // `IntlDateFormatter`, and measured with the extension present it parses NONE of the
            // ISO-8601 shapes a document writes, so it is not an option either way.
            $wireFormats = self::YII3_TEMPORAL_WIRE_FORMATS[is_string($format) ? $format : '']
                ?? self::YII3_TEMPORAL_WIRE_FORMATS['date-time'];
            foreach ($wireFormats as $wireFormat) {
                $attributes[] = sprintf(
                    "#[%s(format: 'php:%s')]",
                    $this->yii3Lib('ToDateTime'),
                    $wireFormat,
                );
            }
        }

        $minItems = $constraints['minItems'] ?? null;
        $maxItems = $constraints['maxItems'] ?? null;
        if ($minItems !== null || $maxItems !== null) {
            $args = [];
            if ($minItems !== null) {
                $args[] = 'min: ' . (int)$minItems;
            }
            if ($maxItems !== null) {
                $args[] = 'max: ' . (int)$maxItems;
            }
            $attributes[] = $this->yii3Rule('Count', $args, $ruleImports);
        }

        // NO rule for `uniqueItems`. `#[UniqueIterable]` was emitted here and measured not to reject
        // duplicates at all — `['a','a']` on a plain `array $f` validated clean, scalars and objects
        // alike. It goes to the interpreter in full, exactly as in Laravel mode.

        foreach ($this->yii3NestingAttributes($property, $ruleImports) as $attribute) {
            $attributes[] = $attribute;
        }

        // An absent optional property is null, and every rule above would otherwise fire on it —
        // measured: `Count`, `Each`, `Nested` and the comparisons all reported errors for a property
        // the payload simply did not carry. `WhenNull` skips exactly null, unlike `skipOnEmpty: true`,
        // which would also skip an empty string and silently disable `minLength`.
        // EVERY property, required ones included. A missing required property is already reported once
        // by the interpreter as `field "name" is required`; without this the rules fire against it as
        // well and bury that message — measured, one absent property produced five extra lines
        // ("Name must be a string.", "… null given.", "The allowed types for age are …"). One mistake,
        // one message, which is the same rule the other modes follow.
        $attributes = $this->yii3SkipNullOnEach($attributes, $ruleImports);

        return $attributes;
    }

    /**
     * Adds `skipOnEmpty: new WhenNull()` to every rule that is not `#[Required]`.
     *
     * @param array<int, string> $attributes
     * @param array<int, string> $ruleImports
     * @return array<int, string>
     */
    private function yii3SkipNullOnEach(array $attributes, array &$ruleImports): array
    {
        $skipped = [];
        foreach ($attributes as $attribute) {
            // `Required` is exempt by meaning; `ToDateTime` and `Collection` are HYDRATOR attributes
            // and have no such parameter at all — passing it to them killed every request with
            // "Unknown named parameter $skipOnEmpty".
            if (
                str_starts_with($attribute, '#[' . $this->yii3Lib('Required', 'Yiisoft\Validator\Rule\Required'))
                || str_starts_with($attribute, '#[' . $this->yii3Lib('ToDateTime'))
                || str_starts_with($attribute, '#[' . $this->yii3Lib('Collection'))
            ) {
                $skipped[] = $attribute;
                continue;
            }

            // The FRAMEWORK's own condition, not one of ours — and `WhenNull` for every property,
            // nullable or not. `getPropertyValue()` returns null for a property the payload never
            // carried, so this one condition covers absence and an explicit null alike, and the
            // interpreter is left as the single judge of both. `WhenMissing` here instead let the
            // rules fire on an explicit null and report the same mistake twice more: an optional
            // `string` sent as null produced "must be of type string", "must be a string" and
            // "must be a string. null given." — one mistake, one message is the rule everywhere
            // else in this package.
            $ruleImports[] = 'Yiisoft\Validator\EmptyCondition\WhenNull';
            $condition = 'new ' . $this->yii3Lib('WhenNull') . '()';
            $skipped[] = str_ends_with($attribute, ')]')
                ? substr($attribute, 0, -2) . ', skipOnEmpty: ' . $condition . ')]'
                : substr($attribute, 0, -1) . '(skipOnEmpty: ' . $condition . ')]';
        }

        return $skipped;
    }

    /**
     * `#[Nested]` for a nested DTO, `#[Each(new Nested())]` for a list of them.
     *
     * Both are argument-free on purpose: a bare `#[Nested]` reads the nested class's own attributes
     * (measured), so the rule set is never repeated and a self-recursive schema terminates on its own.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $ruleImports
     * @return array<int, string>
     */
    private function yii3NestingAttributes(array $property, array &$ruleImports): array
    {
        if ($this->isGeneratedDtoType($property['type'])) {
            return [$this->yii3Rule('Nested', [], $ruleImports)];
        }

        $itemType = $this->yii3ArrayItemType($property);
        if ($itemType !== null && $this->isGeneratedDtoType($itemType)) {
            $this->yii3Rule('Nested', [], $ruleImports);

            // `#[Collection]` is a HYDRATOR attribute, not a rule, and without it the elements arrive
            // as plain arrays and the constructor is never satisfied: "cannot be instantiated because
            // it has 2 required parameters in constructor, but passed only 1". The docblock item type
            // is not read by the hydrator — only this attribute names the element class.
            $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\Collection';

            return [
                '#[' . $this->yii3Lib('Collection') . '(' . $this->shortClassName($itemType) . '::class)]',
                $this->yii3Rule('Each', ['new Nested()'], $ruleImports),
            ];
        }

        return [];
    }

    /**
     * Class-level attributes: the request source and the interpreter entry point.
     *
     * `#[FromQuery]` only when EVERY property is a query parameter — the attribute is class-wide, so a
     * mixed operation keeps `#[FromBody]` and lets the per-property attributes place the rest.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $sourceImports
     * @return array<int, string>
     */
    private function yii3ClassAttributes(
        string $className,
        array $properties,
        array &$sourceImports,
        bool $entersInterpreter,
    ): array {
        $allQuery = $properties !== [] && array_reduce(
            $properties,
            static fn(bool $carry, array $property): bool => $carry && ($property['inQuery'] ?? false) === true,
            true,
        );

        $attributes = [];

        // ONLY on a class that IS a request payload. Putting it on every schema was a real bug: a
        // nested `Tag` carrying `#[FromBody]` re-read the WHOLE request body instead of the nested
        // value it was being hydrated from, so its constructor was never satisfied and the enclosing
        // object failed with "cannot be instantiated because it has 2 required parameters".
        if (array_key_exists($className, $this->requestPayloadClasses)) {
            $attribute = $allQuery ? 'FromQuery' : 'FromBody';
            $sourceImports[] = 'Yiisoft\Input\Http\Attribute\Data\\' . $attribute;
            $attributes[] = '#[' . $attribute . ']';
        }

        // Class-level, so the interpreter is entered ONCE with the whole object — measured, and the
        // reason this mode takes the Symfony packaging rather than the Laravel one.
        if ($entersInterpreter) {
            $sourceImports[] = 'Yiisoft\Validator\Rule\Callback';
            $attributes[] = '#[' . $this->yii3Lib('Callback') . "(method: 'validateOpenApiConstraints')]";
        }

        return $attributes;
    }

    /**
     * A oneOf/anyOf schema as an interface its members implement.
     *
     * NOT `renderSymfonyUnionInterface()`, which is what this mode called at first: that one carries
     * `#[DiscriminatorMap]` and imports `Symfony\Component\Serializer\Attribute\DiscriminatorMap`, so
     * a yii3-mode file ended up depending on symfony/serializer — the one thing a framework mode's
     * output must never do. Yii3 has no discriminator-mapping attribute at all; picking the branch is
     * the interpreter's job, exactly as it is in Laravel mode.
     *
     * @param array<string> $unionTypes
     * @param array{propertyName: string, mapping: array<string, string>}|null $discriminator
     */
    private function renderYii3UnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
    ): string {
        $members = $unionTypes !== [] ? $unionTypes : array_values($discriminator['mapping'] ?? []);
        $shortMembers = array_map(
            fn(string $member): string => $this->shortClassName($member),
            $members,
        );

        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace ' . $namespace . ';', ''];
        $lines[] = '/**';
        $lines[] = ' * NOTE: This DTO is auto-generated from an OpenAPI schema.';
        $lines[] = ' * Do not edit this file manually.';
        if ($shortMembers !== []) {
            $lines[] = ' *';
            $lines[] = ' * Members: ' . implode('|', $shortMembers);
        }
        if ($discriminator !== null) {
            $lines[] = ' *';
            $lines[] = ' * Discriminated by `' . $discriminator['propertyName']
                . '`; the branch is chosen by the emitted interpreter, not by an attribute — Yii3 has';
            $lines[] = ' * no discriminator mapping of its own.';
        }
        $lines[] = ' */';
        $lines[] = 'interface ' . $className;
        $lines[] = '{';
        $lines[] = '}';

        return GlobalFunctionImports::apply(implode("\n", $lines) . "\n");
    }

    /**
     * The interpreter, packaged for Yii3 — the THIRD packaging of the one implementation emitted by
     * {@see RendersSymfonyDto::renderSymfonyValidationBlock()}.
     *
     * Yii3 validates an OBJECT, so this takes the Symfony packaging (`payloadIsHydratedObject: true`,
     * one entry per object, `toOpenApiValidationPayload()` as the view) and changes only the failure
     * reporter and the attribute that enters it:
     *
     *     Symfony   #[Assert\Callback]      $context->buildViolation($error)->addViolation()
     *     Laravel   withValidator(), static $validator->errors()->add(...)
     *     Yii3      #[Callback(method:)]    $result->addError($error)
     *
     * The interpreter itself is untouched: it yields error STRINGS, which is exactly the seam that
     * lets three packagings share one implementation.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, string> $phpToOpenApiNameMap
     * @param array{enum: bool, temporal: bool} $valueKinds
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, writeOnly: bool, temporalFormat: string|null}> $parameters
     * @return array{consts: string, methods: string, imports: array<int, string>, entered: bool}
     */
    private function renderYii3InterpreterBlock(
        array $constraints,
        array $phpToOpenApiNameMap,
        array $valueKinds,
        array $parameters,
    ): array {
        if ($constraints === []) {
            return ['consts' => '', 'methods' => '', 'imports' => [], 'entered' => false];
        }

        $block = $this->renderSymfonyValidationBlock(
            constraints: $constraints,
            phpToOpenApiNameMap: $phpToOpenApiNameMap,
            // No presence flags: Symfony records presence in a boolean property, this mode reads it
            // off an uninitialised one. The payload view written below asks `hasProperty()` instead.
            providedFlags: [],
            valueKinds: $valueKinds,
            // This mode swaps in its own payload view below, so the name map and the presence flags
            // the Symfony one reads are dead the moment it is written. They were emitted into every
            // generated class and read by nothing.
            ownsPayloadView: true,
        );

        if ($block['methods'] === '') {
            return ['consts' => '', 'methods' => '', 'imports' => [], 'entered' => false];
        }

        return [
            'consts' => $block['consts'],
            'methods' => $this->yii3RepackageInterpreterEntry($block['methods'], $parameters),
            'imports' => $block['imports'],
            'entered' => true,
        ];
    }

    /**
     * Swaps the Symfony entry method for the Yii3 one.
     *
     * A string rewrite rather than a parameter on the shared renderer: with two callers a seam did not
     * pay (which is why `EmitsOpenApiInterpreter` was folded away), and with three the entry point is
     * still the ONLY difference — the interpreter body below it is identical, character for character.
     *
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, writeOnly: bool, temporalFormat: string|null}> $parameters
     */
    private function yii3RepackageInterpreterEntry(string $methods, array $parameters): string
    {
        $symfonyEntry = <<<'PHP'
    #[Assert\Callback]
    public function validateOpenApiConstraints(ExecutionContextInterface $context): void
    {
        foreach ($this->validateOpenApiNode($this->toOpenApiValidationPayload(), self::OPENAPI_VALIDATION_CONSTRAINTS, 'payload', 0) as $error) {
            $context->buildViolation($error)->addViolation();
        }
    }
PHP;

        // The three framework classes named in this signature go through yii3Lib() like every other,
        // so a document that owns `Result`, `Callback` or `ValidationContext` still gets a file that
        // loads. {@see NamesLibraryClasses}
        $yii3Entry = <<<PHP
    /**
     * Entered once per object by the class-level #[Callback]: `\$value` IS this DTO, and the paths are
     * set by the interpreter rather than by a property label.
     */
    private function validateOpenApiConstraints(mixed \$value, {$this->yii3Lib('Callback')} \$rule, {$this->yii3Lib('ValidationContext')} \$context): {$this->yii3Lib('Result')}
    {
        \$result = new {$this->yii3Lib('Result')}();
        foreach (\$this->validateOpenApiNode(\$this->toOpenApiValidationPayload(), self::OPENAPI_VALIDATION_CONSTRAINTS, 'payload', 0) as \$error) {
            \$result->addError(\$error);
        }

        return \$result;
    }
PHP;

        $methods = str_replace($symfonyEntry, $yii3Entry, $methods);

        // Symfony's payload view reads BOOLEAN presence flags (`self::OPENAPI_PROVIDED_FLAGS`), which
        // this mode does not emit — presence here is an uninitialised property. Left in place it put
        // an absent optional into the payload anyway, and the interpreter then reported nonsense like
        // `field "children" must be of type array` for a payload that simply omitted `children`.
        // Measured; it broke every recursive-schema case at every depth.
        $start = strpos($methods, '    /**');
        $payloadAt = strpos($methods, 'public function toOpenApiValidationPayload(): array');
        if ($payloadAt === false || $start === false) {
            return $methods;
        }

        $docStart = strrpos(substr($methods, 0, $payloadAt), '    /**');
        $bodyEnd = strpos($methods, "\n    }", $payloadAt);
        if ($docStart === false || $bodyEnd === false) {
            return $methods;
        }

        return substr($methods, 0, $docStart)
            . $this->renderYii3StandalonePayloadMethod($parameters)
            . substr($methods, $bodyEnd + strlen("\n    }"));
    }

    /**
     * Emits one rule attribute and records its import.
     *
     * @param array<int, string> $arguments
     * @param array<int, string> $ruleImports
     */
    private function yii3Rule(string $rule, array $arguments, array &$ruleImports): string
    {
        $fqcn = in_array($rule, self::YII3_DATE_NAMESPACE_RULES, true)
            ? 'Yiisoft\Validator\Rule\Date\\' . $rule
            : 'Yiisoft\Validator\Rule\\' . $rule;
        $ruleImports[] = $fqcn;

        return '#[' . $this->yii3Lib($rule, $fqcn)
            . ($arguments === [] ? '' : '(' . implode(', ', $arguments) . ')') . ']';
    }

    /**
     * @param array<int, string> $useStatements
     * @param array<int, string> $ruleImports
     * @param array<int, string> $sourceImports
     * @return array<int, string>
     */
    private function yii3SortedImports(array $useStatements, array $ruleImports, array $sourceImports): array
    {
        $imports = array_values(array_unique([...$useStatements, ...$sourceImports, ...$ruleImports]));

        // An import whose short name the document also declares is dropped, and {@see yii3Lib()} has
        // already spelled that class out in the body. Both ask `namespaceDeclaresClass()`, so the list
        // and the code cannot disagree about which names are the document's.
        $imports = array_values(array_filter(
            $imports,
            fn(string $import): bool => !$this->namespaceDeclaresClass(
                $this->shortClassName($import),
                $this->yii3RenderNamespace,
            ),
        ));
        sort($imports);

        return $imports;
    }

    private function yii3NumberLiteral(mixed $value): string
    {
        return is_float($value) || str_contains((string)$value, '.')
            ? (string)(float)$value
            : (string)(int)$value;
    }

    private function yii3RegexLiteral(string $pattern): string
    {
        return "'/" . str_replace(['\\', "'", '/'], ['\\\\', "\\'", '\/'], $pattern) . "/'";
    }

    /**
     * The three methods that make the class a first-class Yii3 data set, plus the rules re-export.
     *
     * Presence is `ReflectionProperty::isInitialized()` — the language's own answer. No sentinel, no
     * flag array, nothing a Yii3 developer has not seen before.
     *
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, writeOnly: bool, temporalFormat: string|null}> $parameters
     * @return array<int, string>
     */
    private function renderYii3DataSetMethods(array $parameters): array
    {
        // Only a class that HAS a temporal property carries the format map, so only that shape may
        // read it — the other one would name a constant the class does not declare.
        $hasTemporal = false;
        foreach ($parameters as $parameter) {
            if ($parameter['temporalFormat'] !== null) {
                $hasTemporal = true;
                break;
            }
        }

        return [
            '',
            '    /**',
            '     * Rules come from the attributes above; this only re-exposes them. A class that provides a',
            '     * data set has its own attribute parsing skipped by the validator, so without this method',
            '     * NOTHING would be validated — not even a missing required property.',
            '     */',
            '    public function getRules(): iterable',
            '    {',
            '        return (new ' . $this->yii3Lib('ObjectParser') . '($this))->getRules();',
            '    }',
            '',
            '    /**',
            '     * Whether the payload carried this property at all.',
            '     *',
            '     * An unhydrated typed property is UNINITIALISED, which is the language\'s own record of',
            '     * what the payload did not contain. A property sent as null IS initialised, so the two',
            '     * are distinguishable without any sentinel value.',
            '     */',
            '    public function hasProperty(string $property): bool',
            '    {',
            '        $name = self::OPENAPI_PROPERTY_NAMES[$property] ?? $property;',
            '',
            '        return property_exists($this, $name)',
            '            && (new ' . $this->yii3Lib('ReflectionProperty') . '($this, $name))->isInitialized($this);',
            '    }',
            '',
            '    public function getPropertyValue(string $property): mixed',
            '    {',
            '        return $this->hasProperty($property)',
            '            ? $this->{self::OPENAPI_PROPERTY_NAMES[$property] ?? $property}',
            '            : null;',
            '    }',
            '',
            '    /**',
            '     * What the object holds, under the names the schema uses, in WIRE form.',
            '     *',
            '     * Yii3 ships no response normalizer, so this doubles as the array form: a backed enum',
            '     * becomes its value and a date its ISO-8601 string, which is what every other mode',
            '     * produces. The interpreter reads `toOpenApiValidationPayload()` instead and sees the',
            '     * values as held.',
            '     *',
            '     * @return array<string, mixed>',
            '     */',
            '    public function getData(): ?array',
            '    {',
            '        $payload = $this->toOpenApiValidationPayload();',
            '        foreach (self::OPENAPI_WRITE_ONLY as $writeOnly) {',
            '            unset($payload[$writeOnly]);',
            '        }',
            '',
            ...$hasTemporal
                ? [
                    '        $wire = [];',
                    '        foreach ($payload as $name => $value) {',
                    '            $wire[$name] = self::openApiWireValue($value, self::OPENAPI_TEMPORAL_FORMATS[$name] ?? null);',
                    '        }',
                    '',
                    '        return $wire;',
                ]
                : ['        return array_map(self::openApiWireValue(...), $payload);'],
            '    }',
            '',
            '    /**',
            '     * One value in wire form. Recurses, because a nested DTO and a list of them are values',
            '     * too — without it `getData()` handed back objects where every other mode gives arrays.',
            '     */',
            '    private static function openApiWireValue(mixed $value, ?string $temporalFormat = null): mixed',
            '    {',
            '        if ($value instanceof ' . $this->yii3Lib('BackedEnum') . ') {',
            '            return $value->value;',
            '        }',
            '        if ($value instanceof ' . $this->yii3Lib('DateTimeInterface') . ') {',
            '            // The schema decides the shape: `format: date` is a DATE, and writing a whole',
            '            // timestamp for it diverged from every other mode. Sub-second precision is kept',
            '            // only when the value carries it, so a plain timestamp is not widened with a',
            '            // `.000000` the client never sent.',
            '            if ($temporalFormat === \'date\') {',
            '                return $value->format(\'Y-m-d\');',
            '            }',
            '            if ($temporalFormat === \'time\') {',
            '                return $value->format(\'H:i:sP\');',
            '            }',
            '',
            '            return $value->format(\'u\') === \'000000\'',
            '                ? $value->format(\'c\')',
            '                : $value->format(\'Y-m-d\TH:i:s.uP\');',
            '        }',
            '        if (is_object($value) && method_exists($value, \'getData\')) {',
            '            return $value->getData();',
            '        }',
            '        if (is_array($value)) {',
            '            return array_map(',
            '                static fn(mixed $item): mixed => self::openApiWireValue($item, $temporalFormat),',
            '                $value,',
            '            );',
            '        }',
            '',
            '        return $value;',
            '    }',
        ];
    }

    /**
     * The payload view: what the object received, under the names the schema uses.
     *
     * An enclosing DTO's callback reads a nested one through here, and `getData()` returns it as the
     * data set. An absent optional is left OUT — `hasProperty()` decides, so the interpreter never
     * sees a property the payload did not carry.
     *
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, writeOnly: bool, temporalFormat: string|null}> $parameters
     */
    private function renderYii3StandalonePayloadMethod(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $parameter) {
            $key = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $parameter['openApiName']) . "'";

            // EVERY property is guarded, required ones included. Reading an uninitialised typed
            // property throws `must not be accessed before initialization`, and with no constructor a
            // missing REQUIRED key leaves exactly that — so an unguarded read turned a payload the
            // interpreter should have rejected with "Required parameter … not found" into an uncaught
            // PHP Error. Measured on six shapes; every one of them crashed.
            $lines[] = sprintf('        if ($this->hasProperty(%s)) {', $key);
            $lines[] = sprintf('            $payload[%s] = $this->%s;', $key, $parameter['name']);
            $lines[] = '        }';
        }

        $body = implode("\n", $lines);

        return <<<PHP
    /**
     * This DTO as an OpenAPI-named payload: what it received, under the names the schema uses.
     * Machinery for the generated validation, not an API to call.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toOpenApiValidationPayload(): array
    {
        \$payload = [];
{$body}

        return \$payload;
    }
PHP;
    }

    /**
     * Drops the keywords the emitted RULES already enforce, so one mistake is reported once.
     *
     * The mirror of Laravel mode's pruning, and needed for the same reason: the interpreter now
     * receives every scalar keyword (Symfony mode can drop them because it has a constraint for each;
     * this mode cannot), so anything a native rule covers would otherwise produce two messages for
     * one payload — Yii's and ours.
     *
     * Only the keywords actually emitted are pruned. `uniqueItems` over OBJECTS, an unmapped `format`
     * and a `type` on a union all stay, because no rule was emitted for them.
     *
     * @param array<string, mixed> $constraints
     * @param array<int, SchemaProperty> $properties
     * @return array<string, mixed>
     */
    private function yii3PruneNativelyCovered(array $constraints, array $properties): array
    {
        $propertyConstraints = $constraints['properties'] ?? null;
        if (!is_array($propertyConstraints)) {
            return $constraints;
        }

        foreach ($properties as $property) {
            $name = $property['openApiName'];
            if (!is_array($propertyConstraints[$name] ?? null)) {
                continue;
            }

            $covered = $this->yii3NativelyCoveredKeywords($property);
            $kept = [];
            foreach ($propertyConstraints[$name] as $keyword => $value) {
                if (in_array($keyword, $covered, true)) {
                    continue;
                }
                $kept[$keyword] = $value;
            }

            // Put `nullable` BACK. The filter drops it because Symfony expresses nullability in the
            // PHP type and its interpreter never needs it; here the interpreter is the only thing
            // that sees the value, and without this a property the schema explicitly marks nullable
            // had its own null rejected — `field "f" must be of type array`.
            if ($this->yii3SchemaAllowsNull($property)) {
                $kept['nullable'] = true;
            }

            $propertyConstraints[$name] = $kept;
        }

        $constraints['properties'] = $propertyConstraints;

        return $constraints;
    }

    /**
     * The keywords a rule was emitted for on this property.
     *
     * @param SchemaProperty $property
     * @return array<int, string>
     */
    private function yii3NativelyCoveredKeywords(array $property): array
    {
        $schema = $property['constraints'] ?? [];
        $covered = [];

        foreach (['minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems'] as $keyword) {
            if (array_key_exists($keyword, $schema) && !$this->yii3BoundIsExclusiveByModifier($schema, $keyword)) {
                $covered[] = $keyword;
            }
        }

        // Only when a rule was actually EMITTED for it. Marking `format` covered on the strength of
        // the table alone left `format: time` enforced nowhere: the rule is skipped for a string
        // property, and the interpreter had already had the keyword taken away from it.
        $format = $schema['format'] ?? null;
        if (
            is_string($format)
            && array_key_exists($format, self::YII3_FORMAT_RULES)
            && $this->yii3FormatRuleApplies($property, self::YII3_FORMAT_RULES[$format])
        ) {
            $covered[] = 'format';
        }

        // `type` stays in the interpreter unless the schema allows null. The rule alone cannot catch
        // an explicit null on a non-nullable property: it carries `WhenNull`, so it steps aside for
        // exactly that value. The interpreter has the wire shape and judges it once.
        if (
            array_key_exists($schema['type'] ?? '', self::YII3_TYPE_RULES)
            && $this->yii3TypeRuleApplies($property)
            && ($property['required'] || $this->yii3SchemaAllowsNull($property))
        ) {
            $covered[] = 'type';
        }

        return $covered;
    }

    /**
     * Whether this bound is the OpenAPI 3.0 EXCLUSIVE spelling: `minimum: 3` next to
     * `exclusiveMinimum: true`.
     *
     * The rule for it would be `GreaterThanOrEqual`, which is the wrong comparison, and emitting it
     * also pruned `minimum` from the interpreter — the one place that implements the pairing. So the
     * whole bound is left to the interpreter. Measured on `{"f":3}` against
     * `minimum: 3, exclusiveMinimum: true`: accepted before, refused by every other mode.
     *
     * @param array<string, mixed> $schema
     */
    private function yii3BoundIsExclusiveByModifier(array $schema, string $keyword): bool
    {
        $modifier = match ($keyword) {
            'minimum', 'exclusiveMinimum' => 'exclusiveMinimum',
            'maximum', 'exclusiveMaximum' => 'exclusiveMaximum',
            default => null,
        };

        return $modifier !== null && ($schema[$modifier] ?? null) === true;
    }

    /**
     * Whether the SCHEMA allows null — not whether the PHP type happens to.
     *
     * `$property['nullable']` is true for every OPTIONAL property, because the generated PHP type has
     * to admit null for an unsent key. The document's own `nullable` is a different question, and
     * confusing the two let an explicit null through for a property whose schema forbids it.
     *
     * @param SchemaProperty $property
     */
    private function yii3SchemaAllowsNull(array $property): bool
    {
        $schema = $property['constraints'] ?? [];
        if (($schema['nullable'] ?? false) === true) {
            return true;
        }

        $type = $schema['type'] ?? null;
        if (is_array($type) && in_array('null', $type, true)) {
            return true;
        }

        // For a REQUIRED property the generator's own flag is trustworthy — nothing else can have set
        // it — and it is the only place nullability declared through a `$ref` shows up, since the
        // property's own constraints carry the reference rather than the referenced keywords.
        return $property['required'] && $property['nullable'];
    }

    /**
     * Whether a `type` rule may be emitted for this property.
     *
     * Only when the PHP type IS the scalar the schema names. A union, a list, a temporal and a
     * generated enum all satisfy `type: string` or `type: integer` in the document while holding
     * something a scalar rule rejects — measured on the demo spec, where every payload came back
     * with "Type must be a string." for a property holding a perfectly valid enum member.
     *
     * @param SchemaProperty $property
     */
    private function yii3TypeRuleApplies(array $property): bool
    {
        $type = ltrim($property['type'], '?');

        return !str_contains($type, '|')
            && !str_contains($type, '<')
            && $type !== 'DateTimeImmutable'
            && !array_key_exists($type, $this->enumSchemas)
            && !array_key_exists($type, $this->dtoSchemas);
    }

    /**
     * The item class of a list property, read from the generic in its TYPE (`array<Tag>`) — the
     * constraints array carries the items SCHEMA, which never names the generated class.
     *
     * @param SchemaProperty $property
     */
    private function yii3ArrayItemType(array $property): ?string
    {
        $type = ltrim($property['type'], '?');
        if (!str_starts_with($type, 'array<') || !str_ends_with($type, '>')) {
            return null;
        }

        $item = substr($type, 6, -1);

        // A map (`array<string, V>`) has no single item class to nest into.
        return $item === '' || str_contains($item, ',') ? null : ltrim($item, '\\');
    }

    private function isGeneratedDtoType(string $type): bool
    {
        return array_key_exists(ltrim($type, '\\'), $this->dtoSchemas);
    }
}
