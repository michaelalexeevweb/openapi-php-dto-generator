<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

use OpenapiPhpDtoGenerator\Service\DtoServiceSupport;
use RuntimeException;

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
     * dropped. `uri` and `uuid` are the interpreter's on purpose: yiisoft's `Url` knows web schemes only,
     * and its `Uuid` pattern let a value with a trailing newline through.
     *
     * @var array<string, string>
     */
    private const array YII3_FORMAT_RULES = [
        'email' => 'Email',
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

    /** Keywords beside a `$ref` that assert nothing about the value (`nullable` is the reference's own). */
    private const array YII3_ANNOTATION_KEYWORDS = [
        'nullable', 'description', 'title', 'example', 'examples', 'deprecated', 'readOnly', 'writeOnly', 'default', 'externalDocs',
    ];

    /**
     * The namespace the yii3 file being rendered lives in, for {@see yii3Lib()}.
     *
     * State rather than a parameter because the library short names it answers about are named in a
     * dozen places across this renderer, several of them deep inside string builders that have no
     * business carrying a namespace through.
     *
     * THE INVARIANT: `renderYii3DtoClass()` sets it before anything reads it, and every caller of
     * `yii3Lib()` is reached from there. An empty value therefore means "not rendering", and
     * `namespaceDeclaresClass()` answers false for it — the short name is used, which is the correct
     * answer for a document that declares no colliding schema. A review flagged the missing guard as
     * fragile under refactoring, and that is the right reading: whoever calls `yii3Lib()` from a new
     * entry point must set this first, or the collision check silently stops finding collisions.
     */
    private string $yii3RenderNamespace = '';

    /**
     * The public static check a list-of-objects property's raw `#[Validate]` calls — see
     * `yii3ValidationAttributes()`.
     */
    private const string YII3_LIST_SHAPE_METHOD = 'validateOpenApiListShape';

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
        'JsonSerializable' => 'JsonSerializable',
        'ObjectParser' => 'Yiisoft\Validator\Helper\ObjectParser',
        'Query' => 'Yiisoft\Input\Http\Attribute\Parameter\Query',
        'ReflectionProperty' => 'ReflectionProperty',
        'Request' => 'Yiisoft\Input\Http\Attribute\Parameter\Request',
        'Result' => 'Yiisoft\Validator\Result',
        'RulesProviderInterface' => 'Yiisoft\Validator\RulesProviderInterface',
        'ToDateTime' => 'Yiisoft\Hydrator\Attribute\Parameter\ToDateTime',
        'UploadedFileInterface' => 'Psr\Http\Message\UploadedFileInterface',
        'UploadedFiles' => 'Yiisoft\Input\Http\Attribute\Parameter\UploadedFiles',
        'Validate' => 'Yiisoft\Hydrator\Validator\Attribute\Validate',
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
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
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
        // The allOf pattern too: a variant `allOf`s the base, and flattened it cannot extend it. As a class
        // of its own the base was unrelated to every variant, so a property typed by it could hold none.
        if ($unionTypes !== [] || ($isAbstract && $discriminator !== null) || $this->isAllOfDiscriminatorBase($className)) {
            return $this->renderYii3UnionInterface(
                namespace: $namespace,
                className: $className,
                unionTypes: $unionTypes,
                discriminator: $discriminator,
            );
        }

        // Declared in the order the schema lists them, which is the order `getData()` writes the keys
        // in. They arrived required-first, and `{success, message, payload}` came out
        // `{success, payload, message}`, where every other mode keeps the schema's order.
        $properties = $this->inSchemaOrder($className, $properties);

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

        // A getter named like a method the class already has is a fatal at load time: a property `data`
        // gave `getData(): ?string` beside `DataSetInterface::getData()`, and the whole class failed with
        // "Cannot redeclare". Such a getter takes a `Value` suffix, repeated until the name is free.
        $takenGetters = ['getData', 'getRules', 'getPropertyValue', 'getValidationResult'];
        foreach ($parameters as $parameter) {
            $takenGetters[] = $parameter['getter'];
        }
        foreach ($parameters as $index => $parameter) {
            if (!in_array($parameter['getter'], ['getData', 'getRules', 'getPropertyValue', 'getValidationResult'], true)) {
                continue;
            }
            $getter = $parameter['getter'];
            while (in_array($getter, $takenGetters, true)) {
                $getter .= 'Value';
            }
            $takenGetters[] = $getter;
            $parameters[$index]['getter'] = $getter;
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
        $interpreterConstraints = $this->yii3PruneNativelyCovered(
            constraints: $this->filterInterpreterConstraints(
                constraints: $this->withConstraintSourceOf(
                    className: $className,
                    extraction: fn(): array => $this->extractValidationConstraints(
                        propertySchema: $this->yii3WithNullableClassItemsSpelledOut(
                            schema: $this->yii3WithHeldArrayBranchesSpelledOut(
                                schema: $this->yii3WithMapValuesInlined(
                                    schema: $this->yii3ClassSchemaWithInlineAllOfMerged($this->dtoSchemas[$className] ?? [], $className),
                                ),
                                className: $className,
                                properties: $properties,
                            ),
                            properties: $properties,
                        ),
                    ),
                ),
                allowScalarKeywords: true,
                unmaterializedProperties: $this->mixedPropertyNames($properties),
            ),
            properties: $properties,
        );

        $interpreter = $this->renderYii3InterpreterBlock(
            constraints: $this->yii3PruneReadOnlyFromRequired(
                constraints: $this->withUnevaluatedPropertiesEvaluated(constraints: $interpreterConstraints, className: $className),
                properties: $properties,
            ),
            phpToOpenApiNameMap: $phpToOpenApiNameMap,
            // NOT hardcoded to false. The payload view hands the interpreter what the object HOLDS —
            // a generated backed enum, a DateTimeImmutable — not the scalar the client sent, and
            // without these flags the interpreter compares an enum instance against `enum: ['a','b']`
            // and rejects a perfectly valid member. Measured: `{"f":"a"}` came back invalid.
            valueKinds: $this->interpreterValueKinds($className, array_map(
                callback: static fn(array $parameter): array => [
                    'name' => $parameter['name'],
                    'declaredType' => $parameter['propertyType'],
                    'docType' => $parameter['docType'],
                ],
                array: $parameters,
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
        }

        // Computed BEFORE the imports are written: without this the union interface is emitted and
        // nothing implements it, so a property typed by the union could never hold a branch.
        $implements = [];
        foreach ($this->implementedUnionInterfaces($className, $extends) as $unionInterface) {
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
        // `json_encode()` of a list holding these wrote the PHP objects — a date as
        // `{"date": …, "timezone_type": 3}`, an enum as `{}` — where every other mode writes the wire form.
        $useStatements[] = 'JsonSerializable';
        $useStatements[] = 'stdClass';
        $implements[] = $this->yii3Lib('JsonSerializable');

        // Every DECISION is above this line; what follows is the shape of the file, and the shape
        // lives in the template — the same border the other four modes draw. It was the odd one out
        // for a while, and a `$lines[]` builder hides in a diff what a template shows: both
        // container-plus-type bugs in Laravel mode were found by READING a golden snapshot, and the
        // one that sat in this mode (a dead `#[ToDateTime]` on a container) was not.
        $temporalParams = [];
        foreach ($parameters as $parameter) {
            if ($parameter['temporalFormat'] !== null) {
                $temporalParams[] = $parameter;
            }
        }

        // `create()` builds into a local variable, which must not share its name with an argument.
        $createVariable = 'dto';
        $argumentNames = array_column($parameters, 'name');
        for ($suffix = 2; in_array($createVariable, $argumentNames, true); $suffix++) {
            $createVariable = 'dto' . $suffix;
        }

        $listShapeBlock = $this->yii3ListShapeMethod($parameters, $ruleImports);

        return $this->renderPhpTemplate('dto.yii3.php.twig', [
            'namespace' => $namespace,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            'imports' => $this->yii3SortedImports($useStatements, $ruleImports, $sourceImports),
            'classAttributes' => $classAttributes,
            'libAbstractInput' => $this->yii3Lib('AbstractInput'),
            'implements' => $implements,
            'params' => $parameters,
            'createVariable' => $createVariable,
            'temporalParams' => $temporalParams,
            'interpreterConstsBlock' => $interpreter['entered'] ? rtrim($interpreter['consts'], "\n") : '',
            'interpreterMethodsBlock' => $interpreter['entered'] ? rtrim($interpreter['methods'], "\n") : '',
            // The payload view is a BLOCK rather than markup in the template, because the interpreter
            // repackaging splices the very same method into its own string — one source, two places it
            // can land.
            'standalonePayloadBlock' => rtrim($standalonePayload, "\n"),
            'listShapeBlock' => $listShapeBlock,
            'libObjectParser' => $this->yii3Lib('ObjectParser'),
            'libReflectionProperty' => $this->yii3Lib('ReflectionProperty'),
            'libBackedEnum' => $this->yii3Lib('BackedEnum'),
            'libDateTimeInterface' => $this->yii3Lib('DateTimeInterface'),
            'libStdClass' => $this->yii3Lib('stdClass', 'stdClass'),
            // Only the class branch of the template; the interface branch is entered above.
            'unionMembers' => null,
            'discriminatorProperty' => null,
        ]);
    }

    /**
     * The check behind the raw `Callback` rule of a list of objects, or an empty string when no
     * property calls it. Public and static: the hydrator runs the rule outside the object, so the
     * callback is the class method itself.
     *
     * @param array<int, array{attributes: array<int, string>}> $parameters
     * @param array<int, string> $ruleImports
     */
    private function yii3ListShapeMethod(array $parameters, array &$ruleImports): string
    {
        $called = false;
        foreach ($parameters as $parameter) {
            foreach ($parameter['attributes'] as $attribute) {
                if (str_contains($attribute, "'" . self::YII3_LIST_SHAPE_METHOD . "'")) {
                    $called = true;
                }
            }
        }
        if (!$called) {
            return '';
        }
        $ruleImports[] = 'Yiisoft\Validator\Result';
        $ruleImports[] = 'Yiisoft\Validator\ValidationContext';
        $method = self::YII3_LIST_SHAPE_METHOD;

        return <<<PHP
                /**
                 * Whether a list of objects arrived as a JSON array: the hydrator builds the members of a JSON
                 * object into the same list, and its keys are gone before any other check sees them.
                 */
                public static function {$method}(mixed \$value, {$this->yii3Lib('Callback')} \$rule, {$this->yii3Lib('ValidationContext')} \$context): {$this->yii3Lib('Result')}
                {
                    \$result = new {$this->yii3Lib('Result')}();
                    if (is_array(\$value) && !array_is_list(\$value)) {
                        \$result->addError('{Property} must be an array. Object given.', ['Property' => \$context->getCapitalizedTranslatedProperty()]);
                    }

                    return \$result;
                }
            PHP;
    }

    /**
     * `string|float` widened to `string|int|float`. `PhpNativeTypeCaster` keeps a value only where a
     * member is its exact type, and otherwise casts it to the FIRST member that takes it — and PHP lists
     * `string` before `float`. A JSON integer is no float, so `5` became `"5"`: a `oneOf` of
     * `{type: string, minLength: 3}` and `{type: number}` refused it as a short string, and
     * `anyOf: [{type: number, enum: [5]}, {type: string}]` accepted `6` as the string `"6"`. With `int`
     * declared the integer is kept as it came.
     */
    private function yii3IntegerKeptBesideFloatAndString(string $type): string
    {
        $members = explode('|', $type);
        if (!in_array('float', $members, true) || !in_array('string', $members, true) || in_array('int', $members, true)) {
            return $type;
        }

        $widened = [];
        foreach ($members as $member) {
            if ($member === 'float') {
                $widened[] = 'int';
            }
            $widened[] = $member;
        }

        return implode('|', $widened);
    }

    /**
     * One constructor parameter: its source attribute, its validation attributes and its declaration.
     *
     * @param SchemaProperty $property
     * @param array<int, string> $ruleImports
     * @param array<int, string> $sourceImports
     * @param array<int, string> $useStatements
     * @return array{attributes: array<int, string>, declaration: string, docType: string|null,
     *     tracksPresence: bool, propertyType: string, name: string, openApiName: string,
     *     getter: string, getterType: string, payloadValue: string, getterValue: string,
     *     getterDocType: string|null, default: string|null, createType: string, createValue: string,
     *     createDocType: string|null, createMarker: bool, createMarkerType: string|null,
     *     providedGetter: string, writeOnly: bool, temporalFormat: string|null}
     */
    private function renderYii3Parameter(
        array $property,
        string $namespace,
        array &$ruleImports,
        array &$sourceImports,
        array &$useStatements,
    ): array {
        $property['type'] = $this->yii3IntegerKeptBesideFloatAndString($property['type']);
        $attributes = [];

        $source = $this->yii3SourceAttribute($property, $sourceImports);
        if ($source !== null) {
            $attributes[] = $source;
        }

        // The hydrator fills by PHP PROPERTY NAME. A schema whose name differs (`first_name`,
        // `x-trace-id`) therefore bound nothing at all — every such property came back empty.
        // `#[Data]` names the key to read, which is the question `#[SerializedName]` answers in
        // Symfony mode. A per-property SOURCE attribute already carries the wire name itself.
        // The key is a PATH to it: `ArrayData` splits on `.`, so `priority.note` read `['priority']['note']`
        // and the literal key was never found. `parsePath()` takes `\` as its escape character.
        if ($source === null && $property['openApiName'] !== $property['name']) {
            $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\Data';
            $attributes[] = '#[' . $this->yii3Lib('Data')
                . '(' . $this->phpStringLiteral(addcslashes($property['openApiName'], '\.')) . ')]';
        }

        foreach ($this->yii3ValidationAttributes($property, $ruleImports) as $attribute) {
            $attributes[] = $attribute;
        }

        // A generic (`array<Tag>`) is not a PHP type. It declares `array` and keeps the item type in a
        // docblock, exactly as the other attribute modes do — emitting the generic verbatim produced
        // `?array<Tag> $tags`, which does not parse.
        $type = $property['type'];
        $docType = null;
        $createDocType = null;
        $heldEnumType = null;
        if (str_contains($type, '<')) {
            // The ITEMS of a temporal container stay strings in this mode — `#[ToDateTime]` cannot
            // convert them (see the attribute emitter), so promising `DateTimeImmutable` here would
            // be a docblock the object never honours.
            $docType = $this->formatDocblockTypeForNamespace($this->yii3HeldDocType($type), $namespace);
            $createDocType = $this->formatDocblockTypeForNamespace($this->yii3HeldDocType($type, asBuilt: true), $namespace);
            $type = 'array';
        } else {
            $type = $this->formatPhpTypeForNamespace($type, $namespace);
            // A union interface cannot be built by the hydrator: a property typed by one stayed unfilled
            // and a request carrying it was refused as missing it. It holds the decoded array instead —
            // the interpreter picks the branch and checks it — or the member `create()` was given.
            // So can a union of two generated classes with nothing to tell them apart (`Olive|Teal`):
            // the hydrator built the FIRST one from any object, a Teal payload became an Olive with
            // its `dog` dropped, and neither class was checked. Held as the decoded array, the
            // interpreter judges it against the union, the way it does a union component.
            // So can a union interface beside other members (`Shape|string`): only the WHOLE type was
            // looked at, the hydrator could not build `Shape`, and the property was left unset —
            // `{"swan": {"radius": "a"}}` passed, a valid object was dropped.
            if (
                $this->yii3IsUnionInterface($property['type'])
                || $this->yii3IsUnionOfClasses($property['type'])
                || $this->yii3HasUnionInterfaceMember($property['type'])
            ) {
                $docType = $type . '|array<string, mixed>';
                $createDocType = $type;
                $type = $this->yii3WithArrayMember($type);
            }
            // An enum beside a scalar keeps the value AS SENT: with `Scope|int` the hydrator, finding no
            // builtin member for `"all"`, fell back to casting it — `"all"` and `"nope"` both became 0, and
            // passed. With `string` and `int` both declared the value is kept, the interpreter checks it
            // against the union, and the getter turns a listed one into the case.
            if ($this->yii3UnionEnumMember($property['type']) !== null) {
                foreach (['string', 'int'] as $scalar) {
                    if (!in_array($scalar, explode('|', $type), true)) {
                        $type .= '|' . $scalar;
                    }
                }
            }
            // An enum also admits the decoded array: handed `{"f": {...}}`, `HydratorTypeCaster` asked the
            // hydrator to BUILD the enum class from it, and `new` on an enum is an `Error` — a 500 where
            // every other mode refuses. With `array` in the type the native caster keeps the array, the
            // checks refuse it, and a member still becomes its case. The getter and `create()` keep the
            // type without it. Not beside a generated class, which is what an array is built into.
            if ($this->yii3HoldsArrayForEnum($property['type'])) {
                $heldEnumType = $type;
                $type = $this->yii3WithArrayMember($type);
            }
            $type = $this->yii3WithUnionClassFirst(type: $type, phpType: $property['type']);
            // Beside a `bool` member the class is never built: the native caster runs first and makes
            // any array `true`, so `{"owl": {"egret": 5}}` was held — and validated — as `true`. With
            // `array` declared the array is kept as sent, and the interpreter judges it against the
            // class's branch.
            if ($this->yii3UnionClassBesideBool($property['type'])) {
                $type = $this->yii3WithArrayMember($type);
            }
        }
        $wireBoolean = $this->yii3IsWireBoolean($property);
        if ($wireBoolean) {
            $type = 'bool|string';
        }

        // A union names it too (`DateTimeImmutable|int`); the import used to arrive with the
        // interpreter's whatever the class needed.
        if (in_array('DateTimeImmutable', explode('|', ltrim($type, '?')), true) && !in_array('DateTimeImmutable', $useStatements, true)) {
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
            $attributes[] = '#[' . $this->yii3Lib('UploadedFiles')
                . '(' . $this->phpStringLiteral($property['openApiName']) . ')]';
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
        // The value class of a map of maps sits deeper than the item-type strip above reaches.
        foreach ($this->generatedClassesNamedByType($property['type']) as $nested) {
            $this->appendImportForClass($useStatements, $nested, $namespace, $property['name']);
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
        $nullable = $property['nullable'] || !$this->propertyIsRequiredOnInput($property);
        // A union that already admits null takes nothing: `string|int|null` plus `|null` is
        // "Duplicate type null is redundant", a fatal at load time — which the free-form union hit
        // the moment it was introduced.
        $alreadyNullable = preg_match('/(^|\|)null(\||$)/', $type) === 1;
        $propertyType = match (true) {
            !$nullable, $alreadyNullable => $type,
            str_contains($type, '|') => $type . '|null',
            default => '?' . $type,
        };
        if ($createDocType !== null && $nullable && !str_contains($type, '<') && !str_starts_with($createDocType, '?')) {
            $createDocType = '?' . $createDocType;
        }

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
        $getterValue = $wireBoolean
            ? sprintf("in_array(\$this->%s, [true, 'true', '1'], true)", $property['name'])
            : '$this->' . $property['name'];
        $getterDocType = $docType;
        $createValue = '$' . $property['name'];

        // A list in a query string, a path or a header holds TEXT: `?ids[]=11` is `"11"`, and the strict
        // check refused it as not an integer, where a lone `?id=11` is cast by the hydrator. Nothing casts
        // the items, so they are held as sent and read as the schema types them — the getter and the
        // interpreter alike; an item that does not read as one stays as it came, for the check to refuse.
        $itemCast = $this->yii3WireItemCast($property);
        $wireItems = null;
        if ($itemCast !== null) {
            $wireItems = sprintf('array_map(%s, $this->%s)', $itemCast['cast'], $property['name']);
            if (str_starts_with($propertyType, '?')) {
                $wireItems = sprintf('($this->%s === null ? null : %s)', $property['name'], $wireItems);
            }
            $getterValue = $wireItems;
            $docType = 'array<' . $itemCast['held'] . '>';
        }

        // A union of an enum and a scalar (`oneOf: [{enum: [all, team]}, {type: integer}]`): the hydrator
        // casts to the first builtin member the value matches, so `"all"` stayed the STRING and code
        // comparing against the case took the other branch. The getter hands out the case — a value the
        // enum does not list stays as it came. Every other mode gives the case.
        $unionEnum = $this->yii3UnionEnumMember($property['type']);
        if ($unionEnum !== null && !$wireBoolean) {
            $enumClass = $this->formatPhpTypeForNamespace($unionEnum, $namespace);
            $getterValue = sprintf(
                '(is_%1$s($this->%2$s) ? (%3$s::tryFrom($this->%2$s) ?? $this->%2$s) : $this->%2$s)',
                $this->enumSchemas[$unionEnum]['type'] === 'int' ? 'int' : 'string',
                $property['name'],
                $enumClass,
            );
        }
        $default = $this->yii3DefaultExpression($property, $heldEnumType ?? $type, $docType);
        // The enum's own type, as the getter hands it out: an array is held only for the rule to refuse.
        $enumPropertyType = $heldEnumType === null ? $propertyType : str_replace('|array', '', $propertyType);
        $getterType = $wireBoolean ? ($nullable ? '?bool' : 'bool') : $enumPropertyType;

        // A default the schema does not let be null makes the getter total: it answers either what was
        // sent or the default, so its type says so and the caller needs no `?? …` of its own. A null the
        // schema forbids is refused by the interpreter; the getter still answers the default for it.
        $schemaNullable = $property['schemaNullable'] ?? $property['nullable'];
        if ($default !== null && !$schemaNullable) {
            $getterType = $wireBoolean ? 'bool' : (string)preg_replace('/^\?|\|null$/', '', $getterType);
            if (!$wireBoolean) {
                $getterValue .= ' ?? ' . $default;
            }
        }

        // A list or map of enum members HOLDS the backing values — `#[Collection]` would drop a member
        // the enum lacks before any rule could report it — so the getter is where they become cases,
        // and `create()` takes cases and stores their values. Every other mode hands out cases.
        $enumItem = $this->yii3EnumContainerItem($property['type']);
        if ($enumItem !== null) {
            $enumClass = $this->formatPhpTypeForNamespace($enumItem['enum'], $namespace);
            $backing = $this->enumSchemas[$enumItem['enum']]['type'];
            $getterDocType = $this->formatDocblockTypeForNamespace(ltrim($property['type'], '?'), $namespace);
            $createDocType = $getterDocType;
            $getterValue = $enumItem['nullableItem']
                ? sprintf('array_map(static fn(?%2$s $item): ?%3$s => $item === null ? null : %3$s::from($item), $this->%1$s)', $property['name'], $backing, $enumClass)
                : sprintf('array_map(static fn(%2$s $item): %3$s => %3$s::from($item), $this->%1$s)', $property['name'], $backing, $enumClass);
            $createValue = $enumItem['nullableItem']
                ? sprintf('array_map(static fn(?%2$s $item): ?%3$s => $item?->value, $%1$s)', $property['name'], $enumClass, $backing)
                : sprintf('array_map(static fn(%2$s $item): %3$s => $item->value, $%1$s)', $property['name'], $enumClass, $backing);
            // In parentheses: the getter puts this inside a ternary of its own, and PHP refuses to nest
            // one unparenthesised. `create()` needs no guard — it stores an optional argument only when
            // it is not null.
            if (str_starts_with($propertyType, '?')) {
                $getterValue = sprintf(
                    '($this->%s === null ? %s : %s)',
                    $property['name'],
                    $default !== null && !$schemaNullable ? $default : 'null',
                    $getterValue,
                );
            }
        }

        // The payload the interpreter reads. A map of objects holds decoded arrays after the hydrator but
        // objects after `create()`, and the interpreter skips a generated object — elsewhere it validates
        // itself through a cascade, which a map does not have here. Its wire form is what the hydrated
        // array is too, so both are checked alike. A wire boolean reads as the boolean its string names;
        // anything else stays as it came, for the interpreter to refuse.
        $payloadValue = $wireBoolean
            ? sprintf("match (\$this->%1\$s) { 'true', '1' => true, 'false', '0' => false, default => \$this->%1\$s }", $property['name'])
            : $wireItems ?? '$this->' . $property['name'];
        $held = $this->yii3HoldsUnbuiltObjects($property['type']);
        $dataSet = $this->yii3Lib('DataSetInterface');
        if ($held === 'value') {
            $payloadValue = sprintf('$this->%1$s instanceof %2$s ? $this->%1$s->getData() : $this->%1$s', $property['name'], $dataSet);
        } elseif ($held === 'items') {
            $payloadValue = sprintf(
                'array_map(static fn(mixed $item): mixed => $item instanceof %2$s ? $item->getData() : $item, $this->%1$s)',
                $property['name'],
                $dataSet,
            );
            if (str_contains($propertyType, 'null') || str_starts_with($propertyType, '?')) {
                $payloadValue = sprintf('$this->%s === null ? null : %s', $property['name'], $payloadValue);
            }
        }

        // An optional property the SCHEMA lets be null has two things to say a `null` default cannot tell
        // apart: `"photo": null` (code written for runtime mode passes `photo: null` meaning exactly that)
        // and no key at all (`create(success: true)` with no `message`, which runtime mode never wrote).
        // Its default is therefore a fresh `stdClass` — a value no such property can hold, and no class of
        // ours: left out, the key stays absent; `null` is written. The docblock keeps the real type.
        $createType = $wireBoolean ? ($nullable ? '?bool' : 'bool') : $enumPropertyType;
        $createMarker = $schemaNullable && !$this->propertyIsRequiredOnInput($property);
        $createMarkerType = null;
        if ($createMarker) {
            $useStatements[] = 'stdClass';
            $createMarkerType = (str_starts_with($createType, '?') ? substr($createType, 1) . '|null' : $createType)
                . '|' . $this->yii3Lib('stdClass', 'stdClass');
        }

        return [
            'attributes' => $attributes,
            'declaration' => 'public readonly ' . $propertyType . ' $' . $property['name'] . ';',
            'docType' => $docType,
            'tracksPresence' => !$this->propertyIsRequiredOnInput($property),
            'propertyType' => $propertyType,
            'name' => $property['name'],
            'openApiName' => $property['openApiName'],
            'getter' => 'get' . ucfirst($property['name']),
            'getterType' => $getterType,
            'payloadValue' => $payloadValue,
            'getterValue' => $getterValue,
            'getterDocType' => $getterDocType,
            // What an absent key reads as: the schema `default`, as every other mode gives it.
            'default' => $default,
            // `create()` takes what the getter hands out, and stores what the property holds.
            'createType' => $createType,
            // Set wherever the getter's docblock is: a container, a union, a list of enum members.
            'createDocType' => $createMarker ? ($createDocType ?? $createType) : $createDocType,
            'createValue' => $createValue,
            'createMarker' => $createMarker,
            'createMarkerType' => $createMarkerType,
            'providedGetter' => 'is' . ucfirst($property['name']) . 'Provided',
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
     * The others — `Email`, `Url`, `Ip` — refuse every value that is not a string, while a `format`
     * ignores one (JSON Schema 2020-12 §7.1): `{type: integer, format: email}` refused `5`. So they
     * judge a string property only, and the interpreter, which reads a format for strings alone,
     * takes the keyword anywhere else.
     *
     * @param SchemaProperty $property
     */
    private function yii3FormatRuleApplies(array $property, string $rule): bool
    {
        if (!in_array($rule, self::YII3_DATE_NAMESPACE_RULES, true)) {
            return ltrim($property['type'], '?') === 'string';
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
        if (!is_string($format)) {
            // A union names it in its branches — `oneOf: [{format: date}, {type: null}]` — and a date
            // was written back as a date-time. Only one format can say how to write the value; a
            // `date` beside a `date-time` keeps the full timestamp, as before.
            $branchFormats = $this->yii3TemporalFormats($constraints);
            $format = count($branchFormats) === 1 ? $branchFormats[0] : null;
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
            return '#[' . $this->yii3Lib('Request') . '(' . $this->phpStringLiteral($property['openApiName']) . ')]';
        }

        if (($property['inQuery'] ?? false) === true) {
            $sourceImports[] = 'Yiisoft\Input\Http\Attribute\Parameter\Query';
            return '#[' . $this->yii3Lib('Query') . '(' . $this->phpStringLiteral($property['openApiName']) . ')]';
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
        // Read through the folded `allOf` too: `allOf: [{}, {type: number}]` types the property `float`
        // exactly as `type: number` does, and without the rule the caster turned "foo" into 0.0.
        // And through a `oneOf` / `anyOf` of ONE typed member and `{type: null}`, the 3.1 spelling of
        // `type: [string, "null"]`: it is declared `?string` the same way, and without the rule the cast
        // turned `7` into `"7"` and `true` into `"1"`.
        // And through an `allOf` whose parts disagree on `type`: no value satisfies it, and the part the
        // property is declared as is what lets the raw rule below refuse what the cast would drop.
        $declaredType = $constraints['type']
            ?? $this->foldScalarAllOfConstraints($constraints)['type']
            ?? $this->yii3TypeBesideANullMember($constraints)
            ?? $this->yii3TypeOfAnAllOfPartDeclared(constraints: $constraints, phpType: $property['type']);
        $typeRule = is_string($declaredType)
            ? (self::YII3_TYPE_RULES[$declaredType] ?? null)
            : null;
        if ($typeRule !== null && $this->yii3TypeRuleApplies($property)) {
            $attributes[] = $this->yii3Rule($typeRule, [], $ruleImports);
        }

        // The length, bound, pattern, format and count rules refuse a value of any other type, where the
        // keyword says nothing about it — `Length` refused `12` beside no `type`. Only what `type` pins
        // gets a rule; `yii3NativelyCoveredKeywords()` reads the same view, so the rest stays with the
        // interpreter, which applies each keyword to its own type.
        $ruleConstraints = $this->withoutKeywordsTheTypeDoesNotPin(schema: $constraints, phpType: $property['type']);
        // The scalar rules are collected first and rendered below: on a property the hydrator turns into
        // a generated backed enum they would judge the enum OBJECT, and `GreaterThanOrEqual(0)` or
        // `Length(min: 1)` refused every case of `{type: integer, minimum: 0, enum: [1, 2]}` — the
        // valid ones included. There they join the raw `In` check instead, which sees the scalar.
        $scalarRules = [];
        $min = $ruleConstraints['minLength'] ?? null;
        $max = $ruleConstraints['maxLength'] ?? null;
        if (($min !== null || $max !== null) && $this->yii3LimitsAreUsable($min, $max)) {
            $scalarRules[] = ['rule' => 'Length', 'arguments' => $this->yii3LimitArguments($min, $max)];
        }

        foreach (
            [
                'minimum' => 'GreaterThanOrEqual',
                'maximum' => 'LessThanOrEqual',
                'exclusiveMinimum' => 'GreaterThan',
                'exclusiveMaximum' => 'LessThan',
            ] as $keyword => $rule
        ) {
            $bound = $ruleConstraints[$keyword] ?? null;
            if (is_numeric($bound) && !$this->yii3BoundIsExclusiveByModifier($ruleConstraints, $keyword)) {
                $scalarRules[] = ['rule' => $rule, 'arguments' => [$this->yii3NumberLiteral($bound), "type: 'original'"]];
            }
        }

        $pattern = $ruleConstraints['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '' && $this->patternCompiles($pattern)) {
            $scalarRules[] = ['rule' => 'Regex', 'arguments' => [$this->yii3RegexLiteral($pattern)]];
        }

        $format = $ruleConstraints['format'] ?? null;
        if (
            is_string($format)
            && array_key_exists($format, self::YII3_FORMAT_RULES)
            && $this->yii3FormatRuleApplies($property, self::YII3_FORMAT_RULES[$format])
        ) {
            $scalarRules[] = ['rule' => self::YII3_FORMAT_RULES[$format], 'arguments' => []];
        }

        $enum = $this->enumSchemas[ltrim($property['type'], '?')] ?? null;
        if ($enum === null) {
            foreach ($scalarRules as ['rule' => $rule, 'arguments' => $arguments]) {
                $attributes[] = $this->yii3Rule($rule, $arguments, $ruleImports);
            }
        }

        // Hydrator attributes that CONVERT the value, kept apart and emitted last: the hydrator runs its
        // parameter attributes in order, each seeing what the one before resolved, so a `#[Validate]`
        // after `#[ToDateTime]` judged the date object and not the string — an int had already become
        // a timestamp by then, and passed.
        $conversions = [];
        // Whether an integer member is read as a Unix timestamp: a date member beside it, and no
        // `#[ToString]` to keep it from `#[ToDateTime]`. The raw union check then carries the numeric
        // members' own keywords, as nothing after the hydration sees the integer any more.
        $readsIntegerAsTimestamp = false;

        // A `DateTimeImmutable` property needs a HYDRATOR attribute as well as a rule: without it the
        // string never becomes a date, the parameter falls back to its default and a value the client
        // DID send reads back as "not provided". Measured on the demo spec.
        // A CONTAINER of temporal values gets none: `ToDateTimeResolver::getParameterValue()` accepts
        // a `DateTimeInterface`, an int or a non-empty string and returns `Result::fail()` for
        // anything else, so on an `array` property every one of these attributes fails and the items
        // stay strings. Four attributes that cannot fire read like conversion that happens; they also
        // drag in the `ToDateTime` import, and with it ext-intl, for a schema that needs neither. The
        // items' `format` is still enforced — the emitted interpreter carries `items`/
        // `additionalProperties` and checks it there.
        if (str_contains($property['type'], 'DateTimeImmutable') && !str_contains($property['type'], '<')) {
            $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\ToDateTime';
            // A `php:` format keeps this off ext-intl: a bare `#[ToDateTime]` goes through
            // `IntlDateFormatter`, and measured with the extension present it parses NONE of the
            // ISO-8601 shapes a document writes, so it is not an option either way.
            // A union carries the format in its branches, not beside them: `oneOf: [{format: date},
            // {type: null}]` got the date-time patterns alone, which left every date unparsed and took a
            // date-time in its place. A `date` and a `date-time` branch need both sets; the attributes
            // are tried in order, and a date never parses as a date-time.
            $wireFormats = [];
            foreach ($this->yii3TemporalFormats($constraints) as $temporalFormat) {
                $wireFormats = [...$wireFormats, ...self::YII3_TEMPORAL_WIRE_FORMATS[$temporalFormat]];
            }
            // `ToDateTime` reads an INT as a Unix timestamp, whatever its format says, so in a union with
            // an integer member `7` came back as 1970-01-01T00:00:07. Made a string first, it parses as
            // no date and is left to the cast, which turns it back into the int it was. Not where the
            // schema lets the union be null: `ToString` makes null `""`, and the cast reads that as 0 —
            // elsewhere the raw check below refuses the null before it gets that far. Nor beside an
            // `array` member: `ToString` makes every array `""`, and `oneOf: [{type: array}, {format:
            // date}]` refused `[]` as missing.
            // Nor where it cannot be undone: the cast only turns `"7"` back into 7 when the declared
            // type has no `string` to keep it as `"7"`, and never turns `"1"` back into `true` while
            // `string` is there — `true` was read back as `"1"` and refused by the enum and the date.
            // A boolean needs no protection (`ToDateTime` leaves it alone); an integer is then read
            // as a timestamp, the README.support-matrix row on an integer beside a date.
            // Nor beside two of `bool`, `int` and `float`: the cast tries the declared members in
            // PHP's fixed order, `int` before `float` before `bool`, so `"1"` from `true` became 1 or
            // 1.0 and `"2.5"` became 2 — refused by the integer's own bounds, or held truncated.
            // `ToDateTime` leaves a boolean and a float alone, so without `ToString` both are held
            // as sent, and only an integer beside them is read as a timestamp — checked on the raw
            // value first, see `$readsIntegerAsTimestamp`.
            $unionMembers = $this->yii3TypeMembers($property['type']);
            // An enum member declares `string` and `int` beside it too (see `renderYii3Parameter()`).
            $declaresString = in_array('string', $unionMembers, true) || $this->yii3UnionEnumMember($property['type']) !== null;
            $stringifiedScalarStaysAString = $declaresString
                && (in_array('bool', $unionMembers, true) || in_array('int', $unionMembers, true));
            $castCannotTellScalarsApart = count(array_intersect(['bool', 'int', 'float'], $unionMembers)) > 1;
            if (
                count($unionMembers) > 1
                && !$stringifiedScalarStaysAString
                && !$castCannotTellScalarsApart
                && !in_array('array', $unionMembers, true)
                // Nor beside a generated class — one or several — which arrives as an array `ToString`
                // would empty. The `array` the property declares beside two classes is added after
                // `$property['type']` was made, so the check above does not see it.
                && !$this->unionHasGeneratedClassMember($property['type'])
                && !($property['schemaNullable'] ?? $property['nullable'])
            ) {
                $ruleImports[] = 'Yiisoft\Hydrator\Attribute\Parameter\ToString';
                $conversions[] = '#[' . $this->yii3Lib('ToString', 'Yiisoft\Hydrator\Attribute\Parameter\ToString') . ']';
            } else {
                $readsIntegerAsTimestamp = count($unionMembers) > 1;
            }
            foreach ($wireFormats as $wireFormat) {
                $conversions[] = sprintf(
                    "#[%s(format: 'php:%s')]",
                    $this->yii3Lib('ToDateTime'),
                    $wireFormat,
                );
            }
        }

        $minItems = $ruleConstraints['minItems'] ?? null;
        $maxItems = $ruleConstraints['maxItems'] ?? null;
        if (($minItems !== null || $maxItems !== null) && $this->yii3LimitsAreUsable($minItems, $maxItems)) {
            $attributes[] = $this->yii3Rule('Count', $this->yii3LimitArguments($minItems, $maxItems), $ruleImports);
        }

        // NO rule for `uniqueItems`. `#[UniqueIterable]` was emitted here and measured not to reject
        // duplicates at all — `['a','a']` on a plain `array $f` validated clean, scalars and objects
        // alike. It goes to the interpreter in full, exactly as in Laravel mode.

        foreach ($this->yii3NestingAttributes($property, $ruleImports) as $attribute) {
            // `#[Collection]` CONVERTS, so it goes last with the other conversions: ahead of the raw
            // `#[Validate]` it handed that check the list it had built, and a JSON object's keys were
            // gone before the check could see them.
            if (str_starts_with($attribute, '#[' . $this->yii3Lib('Collection'))) {
                $conversions[] = $attribute;

                continue;
            }
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

        // The type rule above checks the property, which the hydrator has already CAST: `"abc"` reached
        // it as 0 and `2.5` as 2, so both validated and were stored. `#[Validate]` is hydrator-validator's
        // check of the RAW value, before the cast. The same lenient rule, so a numeric string from a
        // query, a form or a database row still passes; what it refuses is a value the cast would
        // corrupt. Not for a boolean parameter: `?active=true` is how OpenAPI writes one, and
        // `BooleanValue` takes only `"1"` and `"0"`.
        $isParameter = ($property['inQuery'] ?? false) === true
            || ($property['inPath'] ?? false) === true
            || ($property['inHeader'] ?? false) === true
            || ($property['inCookie'] ?? false) === true;
        // A REQUIRED property the schema does not let be null is declared non-nullable, and the cast
        // turns a null into `''` or 0 before anything else sees it — `{"name": null}` created a record
        // with an empty name, every rule having skipped the null. Here only a MISSING key is skipped
        // (the interpreter reports that one), so the raw null is refused. An optional property is
        // nullable in PHP and keeps its null, which the interpreter refuses itself.
        $schemaNullable = $property['schemaNullable'] ?? $property['nullable'];
        $refusesNull = $this->propertyIsRequiredOnInput($property) && !$schemaNullable;
        // An OPTIONAL object or enum the schema does not let be null is the exception: the interpreter
        // leaves both to their class, so nothing refused `{"widget": null}` and it was written back as
        // null. The raw rule is the one check that sees it; a missing key is still skipped.
        // A union of scalars, or of a date and a scalar, has no single type rule, and the cast picks a
        // member for any value: `"x"` became 0.0 for `float|bool`, `[1]` became true, and a date became
        // the int 2024 for `DateTimeImmutable|int`. The raw value is checked against the members
        // instead — any one of them taking it is enough. Not for a parameter, which arrives as text
        // and is a different conversion.
        $heldClass = ltrim($property['type'], '?');
        $memberRules = !$isParameter && str_contains($heldClass, '|')
            ? $this->yii3RawUnionMemberRules(
                type: $property['type'],
                temporalFormats: $this->yii3TemporalFormats($constraints),
                ruleImports: $ruleImports,
                numericKeywordsOf: $readsIntegerAsTimestamp ? $constraints : [],
            )
            : null;
        // An OPTIONAL object, enum or such a union the schema does not let be null is the exception:
        // the interpreter leaves the first two to their class and the union to these members, so
        // nothing refused `{"widget": null}` — it was written back as null, and a union cast it to 0.
        // The raw rule is the one check that sees it; a missing key is still skipped.
        if (
            !$refusesNull
            && !$schemaNullable
            && !$isParameter
            && ($memberRules !== null
                || array_key_exists($heldClass, $this->enumSchemas)
                || (array_key_exists($heldClass, $this->dtoSchemas) && !$this->yii3IsUnionInterface($heldClass))
                // An object held as an `array` — `properties: {}`, `anyOf: [$ref Closed]` — no less.
                || $this->yii3SchemaPinsObject($constraints))
        ) {
            $refusesNull = true;
        }
        $skip = $refusesNull ? 'WhenMissing' : 'WhenNull';
        if ($typeRule !== null && $this->yii3TypeRuleApplies($property) && !($isParameter && $typeRule === 'BooleanValue')) {
            $attributes[] = $this->yii3RawRule(rule: $typeRule, arguments: [], skip: $skip, ruleImports: $ruleImports);
        }

        if ($memberRules !== null) {
            $memberNames = implode(' or ', array_unique(array_values($memberRules)));
            $message = sprintf('%s must be %s.', ucfirst($property['name']), $memberNames);
            $attributes[] = $this->yii3RawRuleChain(
                rules: [[
                    'rule' => 'AnyRule',
                    'arguments' => [
                        '[' . implode(', ', array_keys($memberRules)) . ']',
                        'message: ' . $this->phpStringLiteral($message),
                    ],
                ]],
                skip: $skip,
                ruleImports: $ruleImports,
            );
        }

        // `EnumTypeCaster` cannot cast a value outside the list, so the hydrator skips the property and
        // it stays uninitialised — for an OPTIONAL one that reads as "not sent": `?level=nonsense`
        // answered as if the filter were absent. The raw value is checked against the cases instead.
        // Strict in a body, which is JSON and says `1`; a parameter arrives as text and says `"1"`.
        if ($enum !== null) {
            $arguments = ['[' . implode(', ', $this->yii3EnumInValues(values: $enum['values'], strict: !$isParameter)) . ']'];
            if (!$isParameter) {
                $arguments[] = 'strict: true';
            }
            $attributes[] = $this->yii3RawRuleChain(
                rules: [['rule' => 'In', 'arguments' => $arguments], ...$scalarRules],
                skip: $skip,
                ruleImports: $ruleImports,
            );
        }

        // The same loss for a date: `#[ToDateTime]` that parses nothing leaves the property uninitialised,
        // and `{"begin": "nonsense"}` read as "no filter". The raw string is checked against the formats
        // the hydrator is told to parse — one rule for `date`, any of them for the others. A STRING
        // first: the date rules and `ToDateTime` both read an int as a Unix timestamp, so `{"day": 5}`
        // was stored as 1970-01-01T00:00:05 where the schema asks for a string.
        if ($heldClass === 'DateTimeImmutable') {
            $attributes[] = $this->yii3RawRuleChain(
                rules: [
                    ['rule' => 'StringValue', 'arguments' => []],
                    $this->yii3TemporalParserRule(
                        temporalFormats: $this->yii3TemporalFormats($constraints),
                        propertyName: $property['name'],
                        ruleImports: $ruleImports,
                    ),
                ],
                skip: $skip,
                ruleImports: $ruleImports,
            );
        }

        // And for a container or an object: nothing casts `"not-an-array"` to either, so the property was
        // skipped and an optional one read as "not sent". A body only — a query string spells a one-item
        // list `?ids=1`, which arrives as a string and is still a list to the document.
        $heldType = ltrim($property['type'], '?');
        // Not for a union of types (`string|array<int>`): a scalar is one of its members. The `|` is
        // looked for outside the generics only — `array<int, array<int, float|int>>` is one list.
        $holdsArray = count($this->unionMembersBesideNull($heldType)) === 1 && (
            str_contains($heldType, '<')
            || $heldType === 'array'
            || array_key_exists($heldType, $this->dtoSchemas)
            || $this->yii3IsUnionInterface($heldType)
        );
        $rawRules = [];
        if ($holdsArray && !$isParameter) {
            $isList = str_contains($heldType, '<') && !$this->isMapType($heldType);
            $rawRules[] = [
                'rule' => 'Each',
                'arguments' => ['[]', sprintf("incorrectInputMessage: '{Property} must be %s. {type} given.'", $isList ? 'an array' : 'an object')],
            ];
            // A list of generated objects is built by `#[Collection]`, which takes the members of
            // `{"a": {…}}` as readily as those of `[{…}]` and keeps no key — the interpreter then saw a
            // list. The raw value is asked whether it is one.
            $itemType = $this->yii3ArrayItemType($property);
            if ($isList && $itemType !== null && $this->isGeneratedDtoType($itemType) && !$this->yii3IsUnionInterface($itemType)) {
                $rawRules[] = ['rule' => 'Callback', 'arguments' => [sprintf("callback: [self::class, '%s']", self::YII3_LIST_SHAPE_METHOD)]];
            }
        }

        // A `required` name the held class declares no property for — `{$ref: Shape, required: [bee]}` —
        // is never hydrated, so only the raw value can say whether it was sent: `{"owl": {"ant": false}}`
        // passed. Kept off a closed class, which can never carry such a key.
        $presence = $isParameter ? null : $this->yii3UndeclaredRequiredRule(
            names: $this->yii3UndeclaredRequiredNames($heldType, $constraints),
            ruleImports: $ruleImports,
        );
        if ($presence !== null) {
            $rawRules[] = $presence;
        }
        if ($rawRules !== []) {
            $attributes[] = $this->yii3RawRuleChain(rules: $rawRules, skip: $skip, ruleImports: $ruleImports);
        }

        return [...$attributes, ...$conversions];
    }

    /**
     * The raw check that an object carries the names its class declares no property for: `Nested` with
     * an empty `Composite` per name — `Nested` asks for a path only where it has a rule to run there,
     * and that one passes any value, so presence is the whole check. Null when there is no such name.
     *
     * @param list<string> $names
     * @param array<int, string> $ruleImports
     * @return array{rule: string, arguments: array<int, string>}|null
     */
    private function yii3UndeclaredRequiredRule(array $names, array &$ruleImports): ?array
    {
        if ($names === []) {
            return null;
        }
        $compositeFqcn = $this->yii3RuleClass('Composite');
        $ruleImports[] = $compositeFqcn;
        $anyValue = sprintf('new %s([])', $this->yii3Lib('Composite', $compositeFqcn));

        return [
            'rule' => 'Nested',
            'arguments' => [
                '[' . implode(', ', array_map(fn(string $name): string => $this->phpStringLiteral($name) . ' => ' . $anyValue, $names)) . ']',
                'requirePropertyPath: true',
                "noPropertyPathMessage: 'Property \"{path}\" is required in {property}.'",
            ],
        ];
    }

    /**
     * The names a property's value is required to carry that its class declares no property for, read
     * from the property's own `required` (and its `allOf` members') and from the class's. Empty for a
     * value that is no generated class, and for a closed one.
     *
     * @param array<string, mixed> $constraints
     * @return list<string>
     */
    private function yii3UndeclaredRequiredNames(string $heldType, array $constraints): array
    {
        $class = $this->shortClassName($heldType);
        $definition = $this->dtoSchemas[$class] ?? null;
        if (!is_array($definition) || $this->yii3IsUnionInterface($class) || ($definition['additionalProperties'] ?? null) === false) {
            return [];
        }
        $required = [];
        foreach ([$constraints, $definition] as $schema) {
            $parts = [$schema, ...(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [])];
            foreach ($parts as $part) {
                foreach (is_array($part) && is_array($part['required'] ?? null) ? $part['required'] : [] as $name) {
                    if (is_string($name)) {
                        $required[] = $name;
                    }
                }
            }
        }
        $declared = array_column($this->getSchemaProperties($class), 'openApiName');

        return array_values(array_unique(array_diff($required, $declared)));
    }

    /**
     * Whether a schema admits objects only: `type: object`, or a `oneOf` / `anyOf` whose every branch
     * but a `{type: null}` one is such a schema.
     *
     * @param array<array-key, mixed> $schema
     */
    private function yii3SchemaPinsObject(array $schema): bool
    {
        if (array_key_exists('type', $schema)) {
            return $schema['type'] === 'object';
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches) || $branches === []) {
                continue;
            }
            $objects = 0;
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    return false;
                }
                if (($branch['type'] ?? null) === 'null') {
                    continue;
                }
                if (!$this->yii3SchemaPinsObject($branch)) {
                    return false;
                }
                $objects++;
            }

            return $objects > 0;
        }

        return false;
    }

    /**
     * One `#[Validate]`: hydrator-validator's check of the RAW value, before the hydrator casts it.
     *
     * @param array<int, string> $arguments
     * @param array<int, string> $ruleImports
     */
    private function yii3RawRule(string $rule, array $arguments, string $skip, array &$ruleImports): string
    {
        return $this->yii3RawRuleChain(
            rules: [['rule' => $rule, 'arguments' => $arguments]],
            skip: $skip,
            ruleImports: $ruleImports,
        );
    }

    /**
     * One `#[Validate]` holding several rules, run in order. Every rule after the first carries
     * `skipOnError`, so one wrong value is reported once: `1.5` for a date is "not a string", not that
     * AND "not a date".
     *
     * @param array<int, array{rule: string, arguments: array<int, string>}> $rules
     * @param array<int, string> $ruleImports
     */
    private function yii3RawRuleChain(array $rules, string $skip, array &$ruleImports): string
    {
        $ruleImports[] = 'Yiisoft\Hydrator\Validator\Attribute\Validate';
        $ruleImports[] = 'Yiisoft\Validator\EmptyCondition\\' . $skip;
        $skipCondition = sprintf('skipOnEmpty: new %s()', $this->yii3Lib($skip, 'Yiisoft\Validator\EmptyCondition\\' . $skip));

        $rendered = [];
        foreach ($rules as $index => ['rule' => $rule, 'arguments' => $arguments]) {
            $fqcn = $this->yii3RuleClass($rule);
            $ruleImports[] = $fqcn;
            $arguments[] = $skipCondition;
            if ($index > 0) {
                $arguments[] = 'skipOnError: true';
            }
            $rendered[] = sprintf('new %s(%s)', $this->yii3Lib($rule, $fqcn), implode(', ', $arguments));
        }

        return sprintf('#[%s(%s)]', $this->yii3Lib('Validate'), implode(', ', $rendered));
    }

    private function yii3RuleClass(string $rule): string
    {
        return in_array($rule, self::YII3_DATE_NAMESPACE_RULES, true)
            ? 'Yiisoft\Validator\Rule\Date\\' . $rule
            : 'Yiisoft\Validator\Rule\\' . $rule;
    }

    /**
     * The temporal formats a property is written in, in the order the hydrator should try them: its
     * own `format`, or those of its `oneOf`/`anyOf`/`allOf` branches — a composition carries it there. `date-time`
     * when it names none.
     *
     * @param array<string, mixed> $constraints
     * @return non-empty-array<int, string>
     */
    private function yii3TemporalFormats(array $constraints): array
    {
        $named = $this->yii3NamedTemporalFormats(schema: $constraints, depth: 0);

        $formats = array_values(array_filter(
            array: array_keys(self::YII3_TEMPORAL_WIRE_FORMATS),
            callback: static fn(string $temporalFormat): bool => in_array($temporalFormat, $named, true),
        ));

        return $formats === [] ? ['date-time'] : $formats;
    }

    /**
     * The `format`s a schema names, itself or down its composition branches. An `allOf` carries it in
     * a branch too — `allOf: [{format: date}]` was parsed as a date-time — and so does an `allOf` inside
     * a `oneOf` branch: `oneOf: [{allOf: [{format: date}]}]` refused a valid `2012-03-26`.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function yii3NamedTemporalFormats(array $schema, int $depth): array
    {
        $format = $schema['format'] ?? null;
        if (is_string($format)) {
            return [$format];
        }
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return [];
        }
        $named = [];
        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            $branches = is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [];
            foreach ($branches as $branch) {
                if (is_array($branch)) {
                    $named = [...$named, ...$this->yii3NamedTemporalFormats(schema: $branch, depth: $depth + 1)];
                }
            }
        }

        return $named;
    }

    /**
     * The rule that parses a raw temporal string the way `#[ToDateTime]` will: one `Date`/`DateTime`/
     * `Time`, or `AnyRule` over each pattern. `AnyRule` hands its message to the result with no
     * parameters, so a `{Property}` placeholder reached the client unreplaced — the property is
     * named here instead, as the framework capitalizes it.
     *
     * @param array<int, string> $temporalFormats
     * @param array<int, string> $ruleImports
     * @return array{rule: string, arguments: array<int, string>}
     */
    private function yii3TemporalParserRule(array $temporalFormats, string $propertyName, array &$ruleImports): array
    {
        $parsers = [];
        foreach ($temporalFormats as $temporalFormat) {
            $dateRule = self::YII3_FORMAT_RULES[$temporalFormat];
            foreach (self::YII3_TEMPORAL_WIRE_FORMATS[$temporalFormat] as $wireFormat) {
                $parsers[] = ['rule' => $dateRule, 'format' => "format: 'php:" . $wireFormat . "'"];
            }
        }
        if (count($parsers) === 1) {
            return ['rule' => $parsers[0]['rule'], 'arguments' => [$parsers[0]['format']]];
        }

        $rendered = [];
        foreach ($parsers as ['rule' => $dateRule, 'format' => $formatArgument]) {
            $fqcn = $this->yii3RuleClass($dateRule);
            $ruleImports[] = $fqcn;
            $rendered[] = sprintf('new %s(%s)', $this->yii3Lib($dateRule, $fqcn), $formatArgument);
        }

        $kind = $temporalFormats === ['time'] ? 'time' : 'date';

        return [
            'rule' => 'AnyRule',
            'arguments' => [
                '[' . implode(', ', $rendered) . ']',
                'message: ' . $this->phpStringLiteral(sprintf('%s must be a %s.', ucfirst($propertyName), $kind)),
            ],
        ];
    }

    /**
     * The raw rule for each member of a union type, keyed by the rule expression, valued by how the
     * message names the member — or null when a member has none (a class, a container, `mixed`), in
     * which case the union is left to the interpreter as before.
     *
     * @param array<int, string> $temporalFormats
     * @param array<int, string> $ruleImports
     * @param array<string, mixed> $numericKeywordsOf the property's schema where its numeric members' own
     *                                                 keywords are checked here, its branches carrying them
     * @return array<string, string>|null
     */
    private function yii3RawUnionMemberRules(string $type, array $temporalFormats, array &$ruleImports, array $numericKeywordsOf = []): ?array
    {
        $members = [];
        // Counted apart from the rules: a class and an `array` member share one `new Each()`, and
        // keyed by rule they collapsed into a single member — the union went unchecked as if it had
        // one, and `{"fox": 7}` passed `array|Fox`.
        $memberCount = 0;
        foreach ($this->yii3TypeMembers($type) as $member) {
            $memberCount++;
            if ($member === 'DateTimeImmutable') {
                $parser = $this->yii3TemporalParserRule(
                    temporalFormats: $temporalFormats,
                    propertyName: 'value',
                    ruleImports: $ruleImports,
                );
                // Inside `AnyRule` only whether a member passes matters, so the parser's own message
                // never reaches anyone; the date is a string AND one of the patterns.
                $parserFqcn = $this->yii3RuleClass($parser['rule']);
                $ruleImports[] = $parserFqcn;
                $ruleImports[] = $this->yii3RuleClass('Composite');
                $ruleImports[] = $this->yii3RuleClass('StringValue');
                $composite = $this->yii3Lib('Composite', $this->yii3RuleClass('Composite'));
                $string = $this->yii3Lib('StringValue', $this->yii3RuleClass('StringValue'));
                $parserRule = $this->yii3Lib($parser['rule'], $parserFqcn);
                $parserArguments = implode(', ', $parser['arguments']);
                $members[sprintf('new %s([new %s(), new %s(%s)])', $composite, $string, $parserRule, $parserArguments)]
                    = $temporalFormats === ['time'] ? 'a time' : 'a date';
                continue;
            }

            // An enum member is one of its values, sent as the JSON scalar it is: without it the whole
            // union went unchecked and the cast turned `true` into the `"1"` a string branch accepts.
            $enum = $this->enumSchemas[$member] ?? null;
            if ($enum !== null) {
                $inFqcn = $this->yii3RuleClass('In');
                $ruleImports[] = $inFqcn;
                $values = $this->yii3EnumInValues(values: $enum['values'], strict: true);
                $members[sprintf('new %s([%s], strict: true)', $this->yii3Lib('In', $inFqcn), implode(', ', $values))] = 'one of ' . $member;
                continue;
            }

            // A class or a list member is an array on the wire, and nothing else is: a scalar the cast
            // would turn into the class, or into `true` for `array|bool`, is refused here. The class's own
            // keywords are `Nested`'s, the list's the interpreter's.
            $isGeneratedClass = array_key_exists($this->shortClassName($member), $this->dtoSchemas) && !$this->yii3IsUnionInterface($member);
            // A union interface member is held as the decoded object (`renderYii3Parameter()`): an
            // object on the wire as well, its members the interpreter's. Without a rule for it the
            // union went unchecked, and the cast made `7` the `"7"` a string member accepts.
            $isUnionInterface = array_key_exists($this->shortClassName($member), $this->dtoSchemas) && $this->yii3IsUnionInterface($member);
            $scalar = match (true) {
                $member === 'int' => ['Integer', 'an integer'],
                $member === 'float' => ['Number', 'a number'],
                $member === 'string' => ['StringValue', 'a string'],
                $member === 'bool' => ['BooleanValue', 'a boolean'],
                $isGeneratedClass, $isUnionInterface => ['Each', 'an object'],
                $member === 'array' => ['Each', 'an array'],
                default => null,
            };
            if ($scalar === null) {
                return null;
            }
            // A class carrying a `required` name it declares no property for asks the raw object for it.
            $presence = $isGeneratedClass
                ? $this->yii3UndeclaredRequiredRule(
                    names: $this->yii3UndeclaredRequiredNames($member, []),
                    ruleImports: $ruleImports,
                )
                : null;
            if ($presence !== null) {
                $nestedFqcn = $this->yii3RuleClass('Nested');
                $ruleImports[] = $nestedFqcn;
                $members[sprintf('new %s(%s)', $this->yii3Lib('Nested', $nestedFqcn), implode(', ', $presence['arguments']))] = $scalar[1];

                continue;
            }
            $fqcn = $this->yii3RuleClass($scalar[0]);
            $ruleImports[] = $fqcn;
            // A boolean member takes the JSON `true` and `false` alone. The rule's defaults take `1`,
            // `0`, `"1"` and `"0"` as well: beside a date, an integer out of its branch's bounds
            // passed as a boolean, was held as a timestamp date, and nothing after the hydration
            // saw the integer again.
            $memberRule = $member === 'bool'
                ? 'new ' . $this->yii3Lib($scalar[0], $fqcn) . '(trueValue: true, falseValue: false, strict: true)'
                : 'new ' . $this->yii3Lib($scalar[0], $fqcn) . '()';
            // A numeric member carries its branch's enum and bounds: beside a date member and an
            // array-typed one no `#[ToString]` protects the int, `#[ToDateTime]` reads it as a Unix
            // timestamp, and `{"raven": 4}` was held as 1970-01-01T00:00:04 against `enum: [3]`.
            $keywordRules = in_array($member, ['int', 'float'], true)
                ? $this->yii3NumericBranchKeywordRules(
                    branchType: $member === 'int' ? 'integer' : 'number',
                    constraints: $numericKeywordsOf,
                    ruleImports: $ruleImports,
                )
                : [];
            if ($keywordRules !== []) {
                $compositeFqcn = $this->yii3RuleClass('Composite');
                $ruleImports[] = $compositeFqcn;
                $memberRule = sprintf('new %s([%s])', $this->yii3Lib('Composite', $compositeFqcn), implode(', ', [$memberRule, ...$keywordRules]));
            }
            $members[$memberRule] = $scalar[1];
        }

        return $memberCount > 1 ? $members : null;
    }

    /**
     * The enum and bound rules of the one `oneOf` / `anyOf` branch of this numeric type, rendered for
     * a `Composite` beside the type rule — none when no branch, or more than one, has the type.
     *
     * @param array<string, mixed> $constraints
     * @param array<int, string> $ruleImports
     * @return list<string>
     */
    private function yii3NumericBranchKeywordRules(string $branchType, array $constraints, array &$ruleImports): array
    {
        $matching = [];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($constraints[$keyword] ?? null) ? $constraints[$keyword] : [] as $branch) {
                $declared = is_array($branch) ? $branch['type'] ?? null : null;
                if ($declared === $branchType || (is_array($declared) && in_array($branchType, $declared, true))) {
                    $matching[] = $branch;
                }
            }
        }
        if (count($matching) !== 1) {
            return [];
        }
        $branch = $matching[0];

        $rules = [];
        foreach (
            [
                'minimum' => 'GreaterThanOrEqual',
                'maximum' => 'LessThanOrEqual',
                'exclusiveMinimum' => 'GreaterThan',
                'exclusiveMaximum' => 'LessThan',
            ] as $keyword => $rule
        ) {
            $bound = $branch[$keyword] ?? null;
            if (is_numeric($bound) && !$this->yii3BoundIsExclusiveByModifier($branch, $keyword)) {
                $fqcn = $this->yii3RuleClass($rule);
                $ruleImports[] = $fqcn;
                $rules[] = sprintf("new %s(%s, type: 'original')", $this->yii3Lib($rule, $fqcn), $this->yii3NumberLiteral($bound));
            }
        }
        $enum = $branch['enum'] ?? null;
        $values = is_array($enum) ? array_values(array_filter(
            array: $enum,
            callback: static fn(mixed $value): bool => is_int($value) || is_float($value),
        )) : [];
        if ($values !== []) {
            $listed = [];
            foreach ($values as $value) {
                $listed[] = $this->yii3NumberLiteral($value);
                // JSON has one number type: `3.0` IS the 3 the enum lists, decoded to a float.
                if (is_int($value)) {
                    $listed[] = $this->numericLiteral((float)$value);
                }
            }
            $inFqcn = $this->yii3RuleClass('In');
            $ruleImports[] = $inFqcn;
            $rules[] = sprintf('new %s([%s], strict: true)', $this->yii3Lib('In', $inFqcn), implode(', ', $listed));
        }

        return $rules;
    }

    /**
     * The PHP type with `array` among its members — once: a union of classes beside a list member
     * (`oneOf: [{type: object}, {type: object}, {type: array}]`) already declares one, and a second
     * `array` is a compile error, `Duplicate type array is redundant`, that kept the class from loading.
     */
    private function yii3WithArrayMember(string $type): string
    {
        if (in_array('array', explode('|', ltrim($type, '?')), true)) {
            return $type;
        }

        return str_starts_with($type, '?') ? substr($type, 1) . '|array|null' : $type . '|array';
    }

    /**
     * The members of a PHP type, `null` left out: `?DateTimeImmutable|int` is `DateTimeImmutable`, `int`.
     *
     * @return array<int, string>
     */
    private function yii3TypeMembers(string $type): array
    {
        return array_values(array_filter(
            array: explode('|', ltrim($type, '?')),
            callback: static fn(string $member): bool => $member !== 'null' && $member !== '',
        ));
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
                || str_starts_with($attribute, '#[' . $this->yii3Lib('ToString', 'Yiisoft\Hydrator\Attribute\Parameter\ToString'))
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
        // A union interface is held as the decoded array, which `Nested` refuses; the interpreter checks it.
        if ($this->isGeneratedDtoType($property['type'])) {
            return $this->yii3IsUnionInterface($property['type']) ? [] : [$this->yii3Rule('Nested', [], $ruleImports)];
        }

        // `Shade|string`: the class checks itself when it is what the property holds, and `Nested` alone
        // refused the string. Nothing checked the object before — `{}` passed a `required` of the class.
        // A `DateTimeImmutable` is an object without rules of its own, so `Nested` passes it as it is and
        // judges the class by its attributes; an enum member is held as its backing scalar, which `In`
        // takes; a plain `array` member is the list `Each` takes. Before, any of the three beside the
        // class left the class unchecked: `{"elk": {"cat": "ga"}}` passed a `maxLength: 1` of the class.
        $unionClass = $this->unionClassBesideScalars($property['type']);
        // Beside `bool` the class is held as the decoded array (see `renderYii3Parameter()`), which
        // `Nested` refuses; the interpreter checks it against the class's branch.
        if ($unionClass !== null && $this->yii3UnionClassBesideBool($property['type'])) {
            return [];
        }
        if ($unionClass !== null && !$this->yii3IsUnionInterface($unionClass)) {
            $alternatives = [];
            foreach ($this->yii3TypeMembers($property['type']) as $member) {
                // The hydrator keeps an enum member as its backing scalar, which `Nested` refused:
                // `oneOf: [{type: string}, {type: integer, enum: [8]}, {type: object}]` turned `8` away.
                $enum = $this->enumSchemas[$member] ?? null;
                if ($enum !== null) {
                    $inFqcn = $this->yii3RuleClass('In');
                    $ruleImports[] = $inFqcn;
                    $values = $this->yii3EnumInValues(values: $enum['values'], strict: true);
                    $alternatives[] = sprintf('new %s([%s], strict: true)', $this->yii3Lib('In', $inFqcn), implode(', ', $values));
                    continue;
                }
                $rule = match ($member) {
                    'int' => 'Integer',
                    'float' => 'Number',
                    'string' => 'StringValue',
                    'bool' => 'BooleanValue',
                    'array' => 'Each',
                    default => 'Nested',
                };
                $fqcn = $this->yii3RuleClass($rule);
                $ruleImports[] = $fqcn;
                $alternatives[] = sprintf('new %s()', $this->yii3Lib($rule, $fqcn));
            }
            $alternatives = array_values(array_unique($alternatives));
            if (count($alternatives) === 1) {
                return [$this->yii3Rule('Nested', [], $ruleImports)];
            }

            return [$this->yii3Rule('AnyRule', ['[' . implode(', ', $alternatives) . ']'], $ruleImports)];
        }

        $itemType = $this->yii3ArrayItemType($property);
        if ($itemType !== null && $this->isGeneratedDtoType($itemType) && !$this->yii3IsUnionInterface($itemType)) {
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
            array: $properties,
            callback: static fn(bool $carry, array $property): bool => $carry && ($property['inQuery'] ?? false) === true,
            initial: true,
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
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
     */
    private function renderYii3UnionInterface(
        string $namespace,
        string $className,
        array $unionTypes,
        ?array $discriminator,
    ): string {
        $members = $unionTypes !== [] ? $unionTypes : array_values($discriminator['mapping'] ?? []);

        // What a caller needs to pick the member for a payload itself — the hydrator cannot build an
        // interface, so a body that IS a union had nothing to go by. Emitted only where every member
        // implements this one union interface: a class inheriting the same constant from two interfaces
        // is a fatal error at load time.
        $imports = [];
        $mapping = [];
        $soleUnion = $members !== [];
        foreach ($members as $member) {
            if (count($this->implementedUnionInterfaces($member, $this->discriminatorBaseForMember($member))) !== 1) {
                $soleUnion = false;
            }
        }
        if ($soleUnion) {
            foreach ($discriminator['mapping'] ?? [] as $value => $member) {
                $this->appendImportForClass($imports, $member, $namespace, $className);
                $mapping[$value] = $this->shortClassName($member);
            }
        }
        // Every member is a data set, a rules provider and JSON-serializable, so the interface says so: code taking "any
        // generated object" by `DataSetInterface` refused a union-typed response, which is one of them.
        $extends = [];
        foreach (['DataSetInterface' => 'Yiisoft\Validator\DataSetInterface', 'RulesProviderInterface' => 'Yiisoft\Validator\RulesProviderInterface', 'JsonSerializable' => 'JsonSerializable'] as $short => $fqcn) {
            $extends[] = $this->yii3Lib($short);
            if (!$this->namespaceDeclaresClass($short, $this->yii3RenderNamespace)) {
                $imports[] = $fqcn;
            }
        }
        $interfaceGetters = $this->yii3SharedGetters(
            members: array_values(array_unique([...$members, ...$this->yii3ClassesImplementing($className)])),
            namespace: $namespace,
            imports: $imports,
        );
        sort($imports);

        return $this->renderPhpTemplate('dto.yii3.php.twig', [
            'imports' => array_values(array_unique($imports)),
            'interfaceExtends' => $extends,
            'interfaceGetters' => $interfaceGetters,
            'discriminatorMapping' => $mapping,
            'namespace' => $namespace,
            'className' => $className,
            'classDeprecated' => $this->deprecatedByClass[$className] ?? false,
            'sourceEndpoint' => $this->endpointByClass[$className] ?? null,
            'sourceSpecLink' => $this->resolveSpecLink($className),
            'sourceRelated' => $this->relatedByClass[$className] ?? null,
            // A non-null `unionMembers` is what selects the interface branch of the template — the
            // same switch `dto.symfony.php.twig` uses, so the two modes read alike.
            'unionMembers' => array_map(
                callback: fn(string $member): string => $this->shortClassName($member),
                array: $members,
            ),
            'discriminatorProperty' => $discriminator === null ? null : $discriminator['propertyName'],
        ]);
    }

    /**
     * The getters every member of a union declares alike — same name, same return type, same docblock —
     * so code typed by the interface can read `$event->getUuid()` instead of `getPropertyValue('uuid')`.
     *
     * Worked out from the MEMBERS, each rendered the way its own class is: an interface method whose
     * signature differs from the member's is a fatal at load time, so a getter is declared only where
     * all of them agree. A member that is itself a union declares no getters, and then neither does this.
     * "Members" are every class implementing the interface: a discriminator base is implemented by an
     * `allOf` child the mapping never names too, and a getter it declares otherwise was a fatal there.
     *
     * @param array<int, string> $members
     * @param array<int, string> $imports
     * @return array<int, array{getter: string, getterType: string, docType: string|null}>
     */
    private function yii3SharedGetters(array $members, string $namespace, array &$imports): array
    {
        $shared = null;
        $sharedImports = [];
        foreach ($members as $member) {
            if (!array_key_exists($member, $this->dtoSchemas) || $this->yii3IsUnionInterface($member)) {
                return [];
            }

            $getters = [];
            foreach ($this->flattenedProperties($member, []) as $property) {
                $ruleImports = [];
                $sourceImports = [];
                $useStatements = [];
                $parameter = $this->renderYii3Parameter(
                    property: $property,
                    namespace: $namespace,
                    ruleImports: $ruleImports,
                    sourceImports: $sourceImports,
                    useStatements: $useStatements,
                );
                // A getter renamed for a clash with a method of the class takes a suffix there; left out.
                if (in_array($parameter['getter'], ['getData', 'getRules', 'getPropertyValue', 'getValidationResult'], true)) {
                    continue;
                }
                $docType = $parameter['getterDocType'] === null
                    ? null
                    : (str_starts_with($parameter['getterType'], '?') && !str_starts_with($parameter['getterDocType'], '?') ? '?' : '')
                        . $parameter['getterDocType'];
                $getters[$parameter['getter']] = [
                    'getter' => $parameter['getter'],
                    'getterType' => $parameter['getterType'],
                    'docType' => $docType,
                ];
                $sharedImports[$parameter['getter']] = array_values(array_filter(
                    array: $useStatements,
                    callback: static fn(string $import): bool => $import !== 'stdClass',
                ));
            }

            $shared = $shared === null
                ? $getters
                : array_filter(
                    array: $shared,
                    callback: static fn(array $getter, string $name): bool => ($getters[$name] ?? null) === $getter,
                    mode: ARRAY_FILTER_USE_BOTH,
                );
        }

        foreach (array_keys($shared ?? []) as $name) {
            foreach ($sharedImports[$name] ?? [] as $import) {
                $imports[] = $import;
            }
        }

        return array_values($shared ?? []);
    }

    /**
     * Every generated class that implements the union interface `$interface`, as its own class
     * declaration works it out — by the parent `analyzeSchema()` gives it.
     *
     * @return array<int, string>
     */
    private function yii3ClassesImplementing(string $interface): array
    {
        $implementing = [];
        foreach (array_keys($this->dtoSchemas) as $className) {
            if (in_array($interface, $this->implementedUnionInterfaces($className, $this->allOfParentOf($className)), true)) {
                $implementing[] = $className;
            }
        }

        return $implementing;
    }

    /**
     * The interpreter, packaged for Yii3 — the THIRD packaging of the one implementation emitted by
     * {@see RendersSchemaInterpreter::renderInterpreterBlock()}.
     *
     * Yii3 validates an OBJECT, so this takes the Symfony packaging (`payloadIsHydratedObject: true`,
     * one entry per object, `toOpenApiValidationPayload()` as the view) and changes only the failure
     * reporter and the attribute that enters it:
     *
     *     Symfony   #[Assert\Callback]      $context->buildViolation($error)->addViolation()
     *     Laravel   withValidator(), static $validator->errors()->add(...)
     *     Yii3      #[Callback(method:)]    $result->addErrorWithoutPostProcessing($error)
     *
     * The interpreter itself is untouched: it yields error STRINGS, which is exactly the seam that
     * lets three packagings share one implementation.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, string> $phpToOpenApiNameMap
     * @param array{enum: bool, temporal: bool} $valueKinds
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, getter: string, getterType: string, payloadValue: string, getterValue: string, providedGetter: string, writeOnly: bool, temporalFormat: string|null}> $parameters
     * @return array{consts: string, methods: string, entered: bool}
     */
    private function renderYii3InterpreterBlock(
        array $constraints,
        array $phpToOpenApiNameMap,
        array $valueKinds,
        array $parameters,
    ): array {
        if ($constraints === []) {
            return ['consts' => '', 'methods' => '', 'entered' => false];
        }

        $block = $this->renderInterpreterBlock(
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
            // The union around a nested class is not judged on its payload view here: see the parameter.
            judgesUnionsAroundNestedDtos: false,
        );

        if ($block['methods'] === '') {
            return ['consts' => '', 'methods' => '', 'entered' => false];
        }

        return [
            'consts' => $block['consts'],
            'methods' => $this->yii3RepackageInterpreterEntry($block['methods'], $parameters),
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
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, getter: string, getterType: string, payloadValue: string, getterValue: string, providedGetter: string, writeOnly: bool, temporalFormat: string|null}> $parameters
     */
    private function yii3RepackageInterpreterEntry(string $methods, array $parameters): string
    {
        $symfonyEntry = <<<'PHP'
    #[Assert\Callback]
    public function validateOpenApiConstraints(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
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
            \$path = self::openApiViolationPath(\$error);
            // `ids[0].name` is the keys `ids`, 0, `name` — the value path `Each` writes for an item.
            // The sentence is final text: it quotes the schema (`must equal {"a":1}`, a `^a{2}$`) and
            // the payload's own keys, and the validator's formatters read every `{…}` as a placeholder
            // — the one picked without ext-intl threw on it, mid-validation.
            \$result->addErrorWithoutPostProcessing(
                message: str_ends_with(\$error, '.') ? \$error : \$error . '.',
                valuePath: \$path === null ? [] : array_map(
                    callback: static fn (string \$key): int|string => ctype_digit(\$key) ? (int)\$key : \$key,
                    array: preg_split('/[.\\[\\]]+/', \$path, -1, \\PREG_SPLIT_NO_EMPTY) ?: [],
                ),
            );
        }

        return \$result;
    }
PHP;

        // A string rewrite has one failure mode, and it is silent: edit the Symfony entry over there
        // and the needle stops matching here, so the Yii3 class keeps Symfony's `#[Assert\Callback]`
        // and its `ExecutionContextInterface` — a file that does not even load. That happened, and the
        // parity suite caught it two steps later. Say so at the source instead.
        if (!str_contains($methods, $symfonyEntry)) {
            throw new RuntimeException(
                message: 'The Symfony interpreter entry this mode swaps out has changed shape. Update the '
                . '`$symfonyEntry` heredoc in ' . __METHOD__ . ' to match '
                . 'RendersSchemaInterpreter::renderInterpreterBlock() again.',
            );
        }

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
     * The `min:`/`max:` arguments of a `Length` or `Count` rule, or `exactly:` when the two are equal.
     *
     * yiisoft refuses `min` equal to `max` with `InvalidArgumentException('Use $exactly instead.')`, and
     * it throws while VALIDATING, so `minItems: 2, maxItems: 2` refused every payload, valid ones too.
     *
     * @return array<int, string>
     */
    private function yii3LimitArguments(mixed $min, mixed $max): array
    {
        if ($min !== null && $max !== null && (int)$min === (int)$max) {
            return ['exactly: ' . (int)$min];
        }

        $arguments = [];
        if ($min !== null) {
            $arguments[] = 'min: ' . (int)$min;
        }
        if ($max !== null) {
            $arguments[] = 'max: ' . (int)$max;
        }

        return $arguments;
    }

    /**
     * Whether yiisoft accepts these bounds. It throws while VALIDATING on a negative bound or on `min`
     * above `max`, so such a pair gets no rule and stays with the interpreter, which reads it as written.
     */
    private function yii3LimitsAreUsable(mixed $min, mixed $max): bool
    {
        if (($min !== null && (int)$min < 0) || ($max !== null && (int)$max < 0)) {
            return false;
        }

        return $min === null || $max === null || (int)$min <= (int)$max;
    }

    /**
     * Emits one rule attribute and records its import.
     *
     * @param array<int, string> $arguments
     * @param array<int, string> $ruleImports
     */
    private function yii3Rule(string $rule, array $arguments, array &$ruleImports): string
    {
        $fqcn = $this->yii3RuleClass($rule);
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
            array: $imports,
            callback: fn(string $import): bool => !$this->namespaceDeclaresClass(
                shortName: $this->shortClassName($import),
                namespace: $this->yii3RenderNamespace,
            ),
        ));
        sort($imports);

        return $imports;
    }

    private function yii3NumberLiteral(mixed $value): string
    {
        return is_float($value) || str_contains((string)$value, '.')
            ? $this->numericLiteral((float)$value)
            : (string)(int)$value;
    }

    private function yii3RegexLiteral(string $pattern): string
    {
        return $this->phpStringLiteral(DtoServiceSupport::delimitPattern($pattern));
    }

    /**
     * The payload view: what the object received, under the names the schema uses.
     *
     * An enclosing DTO's callback reads a nested one through here, and `getData()` returns it as the
     * data set. An absent optional is left OUT — `hasProperty()` decides, so the interpreter never
     * sees a property the payload did not carry.
     *
     * @param array<int, array{name: string, openApiName: string, tracksPresence: bool, propertyType: string, docType: string|null, getter: string, getterType: string, payloadValue: string, getterValue: string, providedGetter: string, writeOnly: bool, temporalFormat: string|null}> $parameters
     */
    private function renderYii3StandalonePayloadMethod(array $parameters): string
    {
        $lines = [];
        foreach ($parameters as $parameter) {
            $key = $this->phpStringLiteral($parameter['openApiName']);

            // EVERY property is guarded, required ones included. Reading an uninitialised typed
            // property throws `must not be accessed before initialization`, and with no constructor a
            // missing REQUIRED key leaves exactly that — so an unguarded read turned a payload the
            // interpreter should have rejected with "Required parameter … not found" into an uncaught
            // PHP Error. Measured on six shapes; every one of them crashed.
            $lines[] = sprintf('        if ($this->hasProperty(%s)) {', $key);
            $lines[] = sprintf('            $payload[%s] = %s;', $key, $parameter['payloadValue']);
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
     * A class schema written as `allOf`, seen as the object the emitted class actually is.
     *
     * `analyzeSchema()` merges the INLINE branches of an `allOf` into the class's own properties and
     * leaves a single `$ref` branch as the parent to extend. The constraint path read the raw schema
     * instead, so a property written `bag: {allOf: [{type: object, required: [test], …}]}` produced a
     * synthesized class carrying `$test` and NO constraints at all: `allOf` is not a keyword this
     * mode's interpreter reads, and nothing else asserted the `required`. Measured 2026-08-31 — the
     * other four modes report the missing key once, yii3 reported nothing.
     *
     * Only the inline branches are merged. A `$ref` branch is inheritance: the parent class carries its
     * own constraints and enforces them for itself, so merging them here would report every violation
     * of a parent rule twice.
     *
     * @param array<mixed> $schemaDefinition
     * @return array<mixed>
     */
    private function yii3ClassSchemaWithInlineAllOfMerged(array $schemaDefinition, string $ownerClass): array
    {
        $allOf = $schemaDefinition['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === []) {
            return $schemaDefinition;
        }

        $merged = $schemaDefinition;
        unset($merged['allOf']);

        foreach ($allOf as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            if (array_key_exists('$ref', $branch)) {
                $branch = is_string($branch['$ref'])
                    ? $this->yii3ResolvedRefSchema($branch['$ref'], $ownerClass)
                    : [];
                if ($branch === []) {
                    continue;
                }
            }

            $required = [
                ...(is_array($merged['required'] ?? null) ? $merged['required'] : []),
                ...(is_array($branch['required'] ?? null) ? $branch['required'] : []),
            ];
            $properties = $this->withRestatedPropertySchemas(
                properties: is_array($merged['properties'] ?? null) ? $merged['properties'] : [],
                restated: is_array($branch['properties'] ?? null) ? $branch['properties'] : [],
            );

            $merged = [...$merged, ...$branch];
            if ($required !== []) {
                $merged['required'] = array_values(array_unique($required));
            }

            if ($properties !== []) {
                $merged['properties'] = $properties;
            }
        }

        return $merged;
    }

    /**
     * The object a `$ref` names, as a schema, for merging a parent's own assertions into a child.
     *
     * @return array<mixed>
     */
    private function yii3ResolvedRefSchema(string $ref, string $ownerClass): array
    {
        // Resolved against the file the `$ref` was WRITTEN IN, not against the root document. A child
        // schema in `sub/child.yaml` naming `../common/base.yaml#/…` means a sibling of `sub/`, and
        // resolving it from the root made generation fail outright with `Referenced OpenAPI file not
        // found`. Introduced with this helper in 2.15.12, measured with a three-file fixture in 2.15.18;
        // runtime mode always used the owning file, which is why only yii3 broke.
        $className = $this->schemaRefToClassName(
            ref: $ref,
            currentSourceFile: $this->getSchemaSourceFile($ownerClass),
        );
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return [];
        }

        return $this->yii3ClassSchemaWithInlineAllOfMerged($definition, $className);
    }

    /**
     * Takes a `readOnly` property name — and one with a schema `default` — out of the schema-level
     * `required` list.
     *
     * The interpreter runs over `toOpenApiValidationPayload()`, which is what the object HOLDS after
     * hydration — and a readOnly property is excluded from the input by `OPENAPI_WRITE_ONLY`, so that
     * payload never carries it. Left in `required`, the class contradicted itself: it both refused to
     * read the field from a request and then demanded the field be there. OpenAPI settles which half
     * is wrong — readOnly plus required takes effect on the RESPONSE only.
     *
     * A name the class declares no property for goes too, for the same reason: that payload can never
     * carry it, so every object — a correct response included — failed `field "x" is required`. The
     * generator warns that such a requirement is enforced nowhere, which is what the other four modes
     * do; this one enforced it everywhere. Kept where `additionalProperties` or `patternProperties` may
     * supply the key.
     *
     * Top level only. A nested `required` belongs to a nested schema, whose own properties are not
     * these.
     *
     * @param array<string, mixed> $constraints
     * @param array<int, SchemaProperty> $properties
     * @return array<string, mixed>
     */
    private function yii3PruneReadOnlyFromRequired(array $constraints, array $properties): array
    {
        $required = $constraints['required'] ?? null;
        if (!is_array($required)) {
            return $constraints;
        }

        $waivedNames = [];
        $declaredNames = [];
        foreach ($properties as $property) {
            $declaredNames[] = $property['openApiName'];
            // A required property with a `default` is waived the same way: the default answers for an
            // absent key, as in every other mode — a body without `perPage` was refused as missing it.
            if (($property['readOnly'] ?? false) === true || ($property['default'] ?? null) !== null) {
                $waivedNames[] = $property['openApiName'];
            }
        }
        $opensTheObject = ($constraints['additionalProperties'] ?? false) !== false
            || array_key_exists('patternProperties', $constraints);
        $undeclaredNames = $opensTheObject
            ? []
            : array_filter($required, static fn(mixed $name): bool => !in_array($name, $declaredNames, true));

        if ($waivedNames === [] && $undeclaredNames === []) {
            return $constraints;
        }

        $kept = array_values(array_diff($required, $waivedNames, $undeclaredNames));

        // Rebuilt rather than unset: the result says what the constraints ARE, and "nothing is
        // required any more" is expressed by not writing the key. Walked in place so `required`
        // keeps its position — appending it instead would move it in every emitted constant.
        $rebuilt = [];
        foreach ($constraints as $keyword => $value) {
            if ($keyword !== 'required') {
                $rebuilt[$keyword] = $value;

                continue;
            }
            if ($kept !== []) {
                $rebuilt[$keyword] = $kept;
            }
        }

        return $rebuilt;
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
            // No `properties` left at all when every one of them is a nested DTO — the names still
            // have to be listed beside `additionalProperties` (below).
            if (!array_key_exists('additionalProperties', $constraints) || $properties === []) {
                return $constraints;
            }
            $propertyConstraints = [];
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

        // A property the class holds as a nested DTO (or a `$ref` it otherwise materializes) leaves no
        // schema behind — its own class validates it — and with it went its NAME. Beside
        // `additionalProperties` the interpreter then read the key the payload carries as an
        // undeclared one: `{"leaf": {...}}` was refused as "additional property \"leaf\"" for every
        // value. Every declared name stays listed, with nothing to check where nothing is left.
        if (array_key_exists('additionalProperties', $constraints)) {
            foreach ($properties as $property) {
                if (!array_key_exists($property['openApiName'], $propertyConstraints)) {
                    $propertyConstraints[$property['openApiName']] = [];
                }
            }
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
        // The view `yii3ValidationAttributes()` builds its rules from: a keyword `type` does not pin got
        // no rule, so it is not covered.
        $schema = $this->withoutKeywordsTheTypeDoesNotPin(schema: $property['constraints'] ?? [], phpType: $property['type']);
        $covered = [];

        $unusableLimits = [];
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems']] as [$minKey, $maxKey]) {
            if (!$this->yii3LimitsAreUsable($schema[$minKey] ?? null, $schema[$maxKey] ?? null)) {
                array_push($unusableLimits, $minKey, $maxKey);
            }
        }

        foreach (['minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems'] as $keyword) {
            if (in_array($keyword, $unusableLimits, true)) {
                continue;
            }
            if (array_key_exists($keyword, $schema) && !$this->yii3BoundIsExclusiveByModifier($schema, $keyword)) {
                // An uncompilable pattern got no rule (see `patternCompiles()`): the interpreter keeps it.
                if ($keyword === 'pattern' && (!is_string($schema['pattern']) || $schema['pattern'] === '' || !$this->patternCompiles($schema['pattern']))) {
                    continue;
                }
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
        // A `type` list got no rule (see the emission above), so it is not covered either: as an
        // array offset it was a TypeError that stopped the whole generation.
        if (
            is_string($schema['type'] ?? null)
            && array_key_exists($schema['type'], self::YII3_TYPE_RULES)
            && $this->yii3TypeRuleApplies($property)
            && ($this->propertyIsRequiredOnInput($property) || $this->yii3SchemaAllowsNull($property))
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
        return $this->propertyIsRequiredOnInput($property) && $property['nullable'];
    }

    /**
     * The `type` of an `allOf` part that names the scalar the property is declared as, or null. Parts
     * that disagree on `type` do not fold, yet the property is still declared as one of them — `allOf:
     * [{type: object}, {type: string}]` is a `?string` — and every part applies to the value, so that
     * part's type rule refuses only what the schema refuses. Without it the hydrator dropped `{}` it
     * could not cast to a string, and the payload read as one without the key.
     *
     * @param array<string, mixed> $constraints
     */
    private function yii3TypeOfAnAllOfPartDeclared(array $constraints, string $phpType): ?string
    {
        $declared = match (ltrim($phpType, '?')) {
            'string' => 'string',
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            default => null,
        };
        $parts = $constraints['allOf'] ?? null;
        if ($declared === null || !is_array($parts)) {
            return null;
        }
        foreach ($parts as $part) {
            if (is_array($part) && ($part['type'] ?? null) === $declared) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * The `type` of the one member a `oneOf` / `anyOf` holds beside `{type: null}` members, or null.
     *
     * @param array<string, mixed> $constraints
     */
    private function yii3TypeBesideANullMember(array $constraints): ?string
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $constraints[$keyword] ?? null;
            if (!is_array($members)) {
                continue;
            }
            $types = [];
            foreach ($members as $member) {
                $type = is_array($member) ? $this->yii3MemberScalarType($member) : null;
                if (!is_string($type)) {
                    return null;
                }
                if ($type !== 'null') {
                    $types[] = $type;
                }
            }

            // A lone member (`anyOf: [{type: string}]`) is that type as much as one beside `null` is.
            return count($types) === 1 ? $types[0] : null;
        }

        return null;
    }

    /**
     * The one `type` a union member names: its own, or the one it reaches through an `allOf` whose
     * parts agree on it or a `$ref` to a scalar alias. `anyOf: [{allOf: [{type: integer}]}]` is declared `?int`
     * like `anyOf: [{type: integer}]`, and without the type rule the caster turned `[2]` into `2`.
     *
     * @param array<mixed> $member
     */
    private function yii3MemberScalarType(array $member, int $depth = 0): ?string
    {
        $type = $member['type'] ?? null;
        if (is_string($type) || $depth > 8) {
            return is_string($type) ? $type : null;
        }
        $ref = $member['$ref'] ?? null;
        if (is_string($ref)) {
            $alias = $this->nestedScalarRefDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile);

            return is_array($alias) ? $this->yii3MemberScalarType($alias, $depth + 1) : null;
        }
        $allOf = $member['allOf'] ?? null;

        return is_array($allOf) ? $this->yii3AllOfScalarType($allOf, $depth + 1) : null;
    }

    /**
     * The one `type` the parts of an `allOf` agree on. A part that names no type at all
     * (`{maxLength: 3}`, `{format: int64}`) narrows the value without changing its kind, so it is
     * skipped; a part that names one indirectly but resolves to no scalar makes the answer unknown.
     * `integer` beside `number` is the narrower `integer`.
     *
     * @param array<mixed> $allOf
     */
    private function yii3AllOfScalarType(array $allOf, int $depth): ?string
    {
        $agreed = null;
        foreach ($allOf as $part) {
            if (!is_array($part)) {
                return null;
            }
            $type = $this->yii3MemberScalarType($part, $depth);
            if ($type === null) {
                foreach (['type', '$ref', 'allOf', 'anyOf', 'oneOf', 'not', 'properties', 'items'] as $keyword) {
                    if (array_key_exists($keyword, $part)) {
                        return null;
                    }
                }

                continue;
            }
            if ($agreed === null || $agreed === $type) {
                $agreed = $type;

                continue;
            }
            $pair = [$agreed, $type];
            sort($pair);
            if ($pair !== ['integer', 'number']) {
                return null;
            }
            $agreed = 'integer';
        }

        return $agreed;
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

        return !$this->yii3IsWireBoolean($property)
            && !str_contains($type, '|')
            && !str_contains($type, '<')
            && $type !== 'DateTimeImmutable'
            && !array_key_exists($type, $this->enumSchemas)
            && !array_key_exists($type, $this->dtoSchemas);
    }

    /**
     * A boolean parameter: a query, path, header or cookie value, so a STRING, and OpenAPI writes it
     * `true` / `false`. The hydrator casts to `bool` with `(bool)`, which made `?force=false` true — and
     * `BooleanValue` takes only `"1"` and `"0"`. Such a property is declared `bool|string`, which the
     * cast leaves alone; the getter and the validation payload read the string as the boolean it names.
     *
     * @param SchemaProperty $property
     */
    private function yii3IsWireBoolean(array $property): bool
    {
        return ltrim($property['type'], '?') === 'bool'
            && (($property['inQuery'] ?? false) === true
                || ($property['inPath'] ?? false) === true
                || ($property['inHeader'] ?? false) === true
                || ($property['inCookie'] ?? false) === true);
    }

    /**
     * The container type as the yii3 hydrator actually fills it.
     *
     * It builds objects in ONE place: the items of a list of DTOs, through `#[Collection]`. Everything
     * else in a container stays the decoded JSON — an enum member is its backing string or int, a DTO
     * in a map or two containers deep is an array, a date is a string (`#[ToDateTime]` refuses an
     * array). `#[Collection]` is not used for the rest on purpose: for an enum it drops a member the
     * enum lacks before any rule can report it, and it renumbers a map's keys.
     *
     * Declaring the class there was a promise the object never kept: `getKinds()[0]->value` passed
     * PHPStan and failed at runtime. A DTO there is declared `X|array<string, mixed>`: the hydrator
     * leaves the array, `create()` stores the object it is given. `$asBuilt` is the type `create()`
     * takes — the object alone.
     */
    private function yii3HeldDocType(string $type, bool $asBuilt = false): string
    {
        $builtItem = $this->yii3ListItemTypeOf($type);
        $builtItem = $builtItem !== null && $this->isGeneratedDtoType(ltrim($builtItem, '?')) && !$this->yii3IsUnionInterface($builtItem)
            ? ltrim($builtItem, '?')
            : null;

        $depth = 0;
        $held = '';
        $length = strlen($type);
        for ($i = 0; $i < $length;) {
            if (preg_match('/\G[A-Za-z_\\\][A-Za-z0-9_\\\]*/', $type, $match, 0, $i) !== 1) {
                $character = $type[$i];
                $depth += $character === '<' ? 1 : ($character === '>' ? -1 : 0);
                $held .= $character;
                $i++;
                continue;
            }

            $name = $match[0];
            $i += strlen($name);
            $short = $this->shortClassName(ltrim($name, '\\'));
            if ($short === 'DateTimeImmutable') {
                $held .= 'string';
            } elseif (array_key_exists($short, $this->enumSchemas)) {
                $held .= $this->enumSchemas[$short]['type'];
            } elseif ($this->isGeneratedDtoType($short) && !($depth === 1 && $short === $builtItem)) {
                // The hydrator leaves it the decoded JSON; `create()` stores the object it is given.
                $held .= $asBuilt ? $name : $name . '|array<string, mixed>';
            } else {
                $held .= $name;
            }
        }

        return $held;
    }

    /**
     * The item class of a list property, read from the generic in its TYPE (`array<Tag>`) — the
     * constraints array carries the items SCHEMA, which never names the generated class.
     *
     * @param SchemaProperty $property
     */
    private function yii3ArrayItemType(array $property): ?string
    {
        return $this->yii3ListItemTypeOf($property['type']);
    }

    /**
     * `yii3ArrayItemType()` for a bare type string.
     */
    private function yii3ListItemTypeOf(string $type): ?string
    {
        $type = ltrim($type, '?');
        if (!str_starts_with($type, 'array<') || !str_ends_with($type, '>')) {
            return null;
        }

        $item = substr($type, 6, -1);

        // A map (`array<string, V>`) has no single item class to nest into.
        return $item === '' || str_contains($item, ',') ? null : ltrim($item, '\\');
    }

    /**
     * The items of a list of a generated class that admits null (`array<?Crate>`) written out for the
     * interpreter. `#[Collection]` drops what is not an array, the null among it, so such a list is
     * hydrated without it and its items stay the decoded arrays — which nothing checked: `[{"lid": 1}]`
     * passed a `type: string` of the class. The interpreter judges them against the class instead.
     *
     * @param array<string, mixed> $schema
     * @param array<int, SchemaProperty> $properties
     * @return array<string, mixed>
     */
    private function yii3WithNullableClassItemsSpelledOut(array $schema, array $properties): array
    {
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }
        foreach ($properties as $property) {
            $name = $property['openApiName'];
            $items = $schema['properties'][$name]['items'] ?? null;
            $itemType = $this->yii3ArrayItemType($property);
            if (
                !is_array($items)
                || $itemType === null
                || !str_starts_with($itemType, '?')
                || !$this->isGeneratedDtoType(substr($itemType, 1))
                || $this->yii3IsUnionInterface(substr($itemType, 1))
            ) {
                continue;
            }
            $schema['properties'][$name]['items'] = $this->inlineNestedContainerValidation(schema: $items, belowMaterialization: true);
        }

        return $schema;
    }

    /**
     * The class schema with the object branch of every union the hydrator keeps as the decoded array
     * written out the way a value nothing materializes is: its `allOf` merged into one object and its
     * enums marked for inline validation.
     *
     * A union of a generated class beside `array` or `bool` (`ProbeDingo|bool|array`) is never built
     * into the class — the native caster takes the array first — so the interpreter judges the array
     * against the branch. That branch was still read as if the class checked it: an `allOf` of object
     * fragments was left for the class cascade and reduced to `{type: object}`, and a property enum was
     * dropped as one its PHP enum type checks. `{"dingo": {"tern": 5}}` and `{"macaw": {"llama": 60}}`
     * against `enum: [-5]` passed. A `$ref` branch is spelled out already (`unionRefTarget()`).
     *
     * @param array<string, mixed> $schema
     * @param array<int, SchemaProperty> $properties
     * @return array<string, mixed>
     */
    private function yii3WithHeldArrayBranchesSpelledOut(array $schema, string $className, array $properties): array
    {
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }
        foreach ($properties as $property) {
            $name = $property['openApiName'];
            $propertySchema = $schema['properties'][$name] ?? null;
            // A union of two or more classes is held as the decoded array too: its `allOf`-wrapped
            // object branches (`anyOf: [{allOf: [Olive…]}, {allOf: [Teal…]}]`) were left for classes
            // never built, and `{"ibex": {}}` passed both members' `required`.
            // An untyped union (`mixed`, emitted `string|int|float|bool|array|null`) holds the object as
            // decoded just the same: a member `allOf: [{type: string}, {$ref: Indigo}]` beside the object
            // branch types the property that way, and the branch's enum went into an enum type nothing
            // builds, its `$ref` to an enum component with it.
            if (
                !is_array($propertySchema)
                || (
                    !$this->yii3HoldsUnionClassAsArray($property['type'])
                    && !$this->yii3IsUnionOfClasses($property['type'])
                    && $property['type'] !== 'mixed'
                )
            ) {
                continue;
            }
            $propertySchema = $this->yii3WithUnionComponentInlined($propertySchema, $className);
            $schema['properties'][$name] = $this->yii3WithHeldObjectBranchesSpelledOut($propertySchema, $className);
        }

        return $schema;
    }

    /**
     * Every object branch of a union held as the decoded array, written out — the branches of a union
     * NESTED in a branch as well (`oneOf: [{oneOf: [{…}, {type: object, allOf: [{…}]}]}, {type: null}]`).
     * The nested union is held as the same array, and its `allOf` branch was reduced to `{type: object}`
     * or to a branch "the class checks": `{}` passed a `required`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function yii3WithHeldObjectBranchesSpelledOut(array $schema, string $className, int $depth = 0): array
    {
        if ($depth >= 8) {
            return $schema;
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }
            foreach ($schema[$keyword] as $index => $branch) {
                // A bare `$ref` to a component built by `allOf` is the same branch behind a name:
                // spelled out by its reference, the fragments were still left for the class.
                $branchRef = is_array($branch) && count($branch) === 1 && is_string($branch['$ref'] ?? null) ? $branch['$ref'] : null;
                $referencedClass = $branchRef === null
                    ? null
                    : $this->schemaRefToClassName(ref: $branchRef, currentSourceFile: $this->getSchemaSourceFile($className));
                if ($branchRef !== null && is_array($this->dtoSchemas[$referencedClass]['allOf'] ?? null)) {
                    $branch = $this->yii3ResolvedRefSchema($branchRef, $className);
                }
                if (!is_array($branch) || array_key_exists('$ref', $branch)) {
                    continue;
                }
                $branch = $this->yii3WithHeldObjectBranchesSpelledOut($branch, $className, $depth + 1);
                if ($this->interpreterShadowType($branch) !== 'object') {
                    $schema[$keyword][$index] = $branch;

                    continue;
                }
                $schema[$keyword][$index] = $this->inlineNestedContainerValidation(
                    schema: $this->yii3WithAllOfFragmentsMerged($branch, $className),
                    belowMaterialization: true,
                );
            }
        }

        return $schema;
    }

    /**
     * A property `$ref` to a union COMPONENT, written out as that component's union.
     *
     * The inline spelling (`anyOf: [{…object…}, {type: array}]`) has its object branch checked against
     * the held array; behind a name the same union reached the interpreter as the component's, with the
     * object branch left to a class that is never built — reduced to `x-php-checked-by-class`, or with
     * its property `enum` moved into an enum type the array does not hold. `{"toucan": {"kiwi": 3}}`
     * passed a `multipleOf: 2`. Only a bare `$ref` (siblings beside it are left as they are).
     *
     * A member written `{$ref: Hen, properties: {…}}` is spelled out as `allOf: [{$ref: Hen}, {…}]`, the
     * way the same member inline on a property is read: left as the reference it was, the branch went
     * to a class the property never holds and was reduced to `x-php-checked-by-class`, so
     * `{"b": {"tern": "abc"}}` passed Hen's `maxLength: 2` beside a `null` member.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function yii3WithUnionComponentInlined(array $propertySchema, string $className): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        $nullable = ($propertySchema['nullable'] ?? null) === true;
        if (!is_string($ref) || array_keys($propertySchema) !== ($nullable ? ['$ref', 'nullable'] : ['$ref'])) {
            return $propertySchema;
        }
        $componentClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
        $definition = $this->dtoSchemas[$componentClass] ?? null;
        if (!is_array($definition) || (!is_array($definition['oneOf'] ?? null) && !is_array($definition['anyOf'] ?? null))) {
            return $propertySchema;
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!is_array($definition[$keyword] ?? null)) {
                continue;
            }
            $members = [];
            foreach ($definition[$keyword] as $member) {
                $members[] = is_array($member) ? $this->yii3RefWithSiblingsAsAllOf($member) : $member;
            }
            $definition[$keyword] = $members;
        }

        return $definition + ($nullable ? ['nullable' => true] : []);
    }

    /**
     * A `{$ref: X, <assertions>}` union member as `allOf: [{$ref: X}, {<assertions>}]`: both judge the
     * value, and the spelling is one the object-branch inlining merges. Any other member as it is — a
     * bare reference, or one beside annotations and `nullable` only.
     *
     * @param array<mixed> $member
     * @return array<mixed>
     */
    private function yii3RefWithSiblingsAsAllOf(array $member): array
    {
        if (!is_string($member['$ref'] ?? null)) {
            return $member;
        }
        $siblings = [];
        $asserts = false;
        foreach ($member as $keyword => $value) {
            if ($keyword === '$ref') {
                continue;
            }
            $siblings[$keyword] = $value;
            if (!in_array($keyword, self::YII3_ANNOTATION_KEYWORDS, true) && !str_starts_with((string)$keyword, 'x-')) {
                $asserts = true;
            }
        }
        if (!$asserts) {
            return $member;
        }

        return ['allOf' => [['$ref' => $member['$ref']], $siblings]];
    }

    /**
     * An object schema with its `allOf` fragments merged into it, fragments of fragments included — the
     * way `yii3ClassSchemaWithInlineAllOfMerged()` reads a class, as deep as the fragments nest.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function yii3WithAllOfFragmentsMerged(array $schema, string $ownerClass): array
    {
        for ($depth = 0; $depth < 8 && is_array($schema['allOf'] ?? null) && $schema['allOf'] !== []; $depth++) {
            $schema = $this->yii3ClassSchemaWithInlineAllOfMerged($schema, $ownerClass);
        }

        return $schema;
    }

    /**
     * Whether a union's generated class member is never built and the property holds the decoded array
     * instead: beside an `array` member the hydrator keeps the array as sent, beside a `bool` one the
     * property is typed with `array` added for the same reason (`yii3UnionClassBesideBool()`).
     */
    private function yii3HoldsUnionClassAsArray(string $phpType): bool
    {
        $unionClass = $this->unionClassBesideScalars($phpType);

        return $unionClass !== null
            && !$this->yii3IsUnionInterface($unionClass)
            && ($this->unionHasArrayMember($phpType) || $this->yii3UnionClassBesideBool($phpType));
    }

    /**
     * The class schema with every value the hydrator does NOT build written out in place, so the
     * interpreter checks it.
     *
     * The other modes build an object per value and validate it through it. The yii3 hydrator builds one
     * only for a property typed by a class and for the items of a list (`#[Collection]`) — so a map of
     * objects, and anything typed by a union interface, holds the decoded arrays. With the `$ref` left as
     * it was, the constraints kept at most `type: object` and NOTHING checked the values:
     * `{"byId": {"k": {"id": 0}}}` passed a `minimum: 1`. Inlined, the interpreter checks each value, an
     * array or an object `create()` was given alike.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function yii3WithMapValuesInlined(array $schema): array
    {
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }

        $properties = [];
        foreach ($schema['properties'] as $name => $property) {
            if (is_array($property)) {
                $union = $this->yii3NullableUnionValueSchema($property);
                if ($union !== null) {
                    $property = $union;
                }
                $property = $this->yii3WithEnumBranchesInlined($property);
                // A property `$ref` to a MAP component is the map: its values are held as decoded as
                // the inline spelling's, and were checked by nothing behind the reference. A bare `$ref`
                // to a LIST component likewise: its items, a discriminated union held as decoded arrays,
                // were left `type: array` where the inline `items: {$ref: Shape}` got the members. Not
                // beside sibling keywords, which read the target as an in-place applicator.
                $mapRef = $this->referenceOrSingleRefAllOf($property);
                $bareListRef = $mapRef !== null && array_keys($property) === ['$ref'] && $this->arrayAliasDefinition($mapRef) !== null;
                if ($mapRef !== null && ($this->mapComponentDefinition($mapRef) !== null || $bareListRef)) {
                    $property = $this->inlineArrayAliasForConstraints($property);
                } elseif ($mapRef !== null && !array_key_exists('items', $property) && $this->arrayAliasDefinition($mapRef) !== null) {
                    // Beside sibling keywords the reference stays the applicator it is, but its `items`
                    // judge every item all the same: `{$ref: Shelf, maxItems: 2}` of a list of a nullable
                    // union held its items as decoded arrays, and checked none of them.
                    $aliasItems = $this->inlineArrayAliasForConstraints($property)['items'] ?? null;
                    if (is_array($aliasItems)) {
                        $property['items'] = $aliasItems;
                    }
                }
                foreach (['items', 'additionalProperties'] as $container) {
                    if (!is_array($property[$container] ?? null)) {
                        continue;
                    }
                    $union = $this->yii3NullableUnionValueSchema($property[$container]);
                    if ($union !== null) {
                        $property[$container] = $union;
                        continue;
                    }
                    $ref = $this->referenceOrSingleRefAllOf($property[$container]);
                    // An inline object value is held as decoded too, so an enum or a `$ref` under it
                    // has no class to check it either.
                    $heldRaw = $ref !== null
                        ? $this->nestedObjectRefDefinition($ref, $this->constraintSourceFile) !== null
                        : is_array($property[$container]['properties'] ?? null);
                    if ($container === 'additionalProperties' && $heldRaw) {
                        // Depth two: below where anything is materialized, which is what makes it
                        // inline the `$ref` itself and everything under it.
                        $property[$container] = $this->inlineNestedContainerValidation(
                            schema: $property[$container],
                            depth: 2,
                        );
                    }
                }
            }
            $properties[$name] = $property;
        }
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * A `oneOf`/`anyOf` with an enum `$ref` among its branches, the enum written out in place.
     *
     * Such a branch extracts to nothing — elsewhere the enum CLASS is the check — and a branch that
     * asserts nothing makes the whole keyword unenforceable, so it was dropped: `Scope|int` accepted
     * `"nope"`. This mode keeps the value as sent (see the property type), so the branches have to
     * judge it.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function yii3WithEnumBranchesInlined(array $schema): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }
            $branches = [];
            foreach ($schema[$keyword] as $branch) {
                $ref = is_array($branch) ? $this->referenceOrSingleRefAllOf($branch) : null;
                $definition = $ref !== null ? $this->nestedScalarRefDefinition($ref, $this->rootSpecFile) : null;
                // The branch's 3.0 `nullable` stays with the members: `{allOf: [{$ref: Kind}], nullable:
                // true}` written out as the bare enum refused the very null the branch admits.
                $branches[] = is_array($definition) && is_array($definition['enum'] ?? null)
                    ? $definition + ['x-php-inline-enum' => true] + (($branch['nullable'] ?? null) === true ? ['nullable' => true] : [])
                    : $branch;
            }
            $schema[$keyword] = $branches;
        }

        return $schema;
    }

    /**
     * `yii3UnionValueSchema()` for every spelling of "this union, or null": `nullable` beside the
     * reference, and a `oneOf`/`anyOf` of the reference and a null branch.
     *
     * The second spelling has no `$ref` of its own, so it was not recognised as the union at all; its
     * branches extracted to nothing — the reference is a class check elsewhere — and the property kept
     * no constraint, so a member missing a required field, or a discriminator value no member answers
     * to, was hydrated as given.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|null
     */
    private function yii3NullableUnionValueSchema(array $schema): ?array
    {
        $union = $this->yii3UnionValueSchema($schema);
        if ($union !== null) {
            return $union + (($schema['nullable'] ?? false) === true ? ['nullable' => true] : []);
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches) || count($branches) !== 2) {
                continue;
            }
            $member = null;
            $nullBranches = 0;
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if ($this->isNullUnionBranch($branch)) {
                    $nullBranches++;
                    continue;
                }
                // Marked nullable, so a union without a discriminator lists null as a member of its
                // own — `nullable` beside a `oneOf` admits nothing the members refuse.
                $member = $this->yii3UnionValueSchema($branch + ['nullable' => true]);
            }
            if ($member !== null && $nullBranches === 1) {
                return $member + ['nullable' => true];
            }
        }

        return null;
    }

    /**
     * The schema a value typed by a union interface is checked against, or null when it is not one.
     *
     * A discriminated union becomes an `if`/`then`/`else` chain on the discriminator — the interpreter
     * picks the branch on an OBJECT only, and here the value is the decoded array. Without a
     * discriminator the members are a `oneOf` (or `anyOf`) of their schemas.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|null
     */
    private function yii3UnionValueSchema(array $schema): ?array
    {
        $ref = $this->referenceOrSingleRefAllOf($schema);
        $prefix = '#/components/schemas/';
        if ($ref === null || !str_starts_with($ref, $prefix)) {
            return null;
        }
        $className = $this->schemaClassName(substr($ref, strlen($prefix)));
        if (!$this->yii3IsUnionInterface($className)) {
            return null;
        }
        $union = $this->dtoSchemas[$className] ?? [];

        $discriminator = is_array($union['discriminator'] ?? null) ? $union['discriminator'] : [];
        $property = $discriminator['propertyName'] ?? null;
        $mapping = is_array($discriminator['mapping'] ?? null) ? $discriminator['mapping'] : [];
        // A union that leaves `mapping` out still has one: OAS names each member by the schema name
        // behind its `$ref`. Reading only the written map left the chain empty, so the members fell
        // through to a bare `oneOf` and neither the value nor the member it names was checked.
        if ($mapping === [] && is_string($property) && $this->hasUnionMembers($union)) {
            $mapping = $this->implicitDiscriminatorMapping($union);
        }
        $memberSchemas = [];
        foreach ($mapping as $value => $memberRef) {
            $memberSchemas[$value] = is_string($memberRef) ? $this->yii3MemberSchema($memberRef) : [];
        }
        // An object base that leaves `mapping` out has its variants named by the schemas that `allOf`
        // it; the document spells none of them, so the map the generator built is read instead.
        if ($mapping === [] && !$this->hasUnionMembers($union)) {
            foreach ($this->discriminatorSchemas[$className]['mapping'] ?? [] as $value => $memberClass) {
                $memberSchemas[$value] = $this->yii3MemberSchemaOfClass($memberClass);
            }
        }
        if (is_string($property) && $memberSchemas !== []) {
            $chain = null;
            foreach (array_reverse($memberSchemas, true) as $value => $memberSchema) {
                // `type` and `required` in the condition, not only the value: `properties` asserts
                // nothing about a value that is not an object, so a null — or an object without the
                // property — matched the first member and was checked as it. Now neither matches any
                // member, and the keywords around the chain report what is wrong.
                $link = [
                    'if' => ['type' => 'object', 'required' => [$property], 'properties' => [$property => ['const' => (string)$value]]],
                    'then' => $memberSchema,
                ];
                if ($chain !== null) {
                    $link['else'] = $chain;
                }
                $chain = $link;
            }

            $dispatched = [
                'type' => 'object',
                'required' => [$property],
                // Marked inline: an `enum` is otherwise taken for one a generated enum class enforces.
                'properties' => [$property => ['enum' => array_map('strval', array_keys($memberSchemas)), 'x-php-inline-enum' => true]],
            ] + $chain;
            // The component's own `properties` / `required` judge the value as much as the member the
            // tag names does: left out of the chain, `{"kind": "mail", "value": "x"}` passed the
            // component's `value: {type: integer}`. Merged beside the tag's own entry; a schema the
            // component gives the tag itself joins the tag's `enum` under an `allOf`.
            $own = $this->yii3UnionOwnKeywords($union, $className);
            $ownRequired = is_array($own['required'] ?? null) ? $own['required'] : [];
            $properties = is_array($own['properties'] ?? null) ? $own['properties'] : [];
            $tagSchema = $dispatched['properties'][$property];
            $properties[$property] = is_array($properties[$property] ?? null) && $properties[$property] !== []
                ? ['allOf' => [$tagSchema, $properties[$property]]]
                : $tagSchema;
            $dispatched['properties'] = $properties;
            $dispatched['required'] = array_values(array_unique([$property, ...$ownRequired]));

            return $dispatched;
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (is_array($union[$keyword] ?? null) && $union[$keyword] !== []) {
                $nullable = ($schema['nullable'] ?? false) === true;
                $members = [];
                foreach ($union[$keyword] as $member) {
                    $member = is_array($member) ? $member : [];
                    // A bare reference is the member's class. Anything else — inline keywords, or a
                    // reference with assertions beside it (`allOf: [{$ref: Teal}, {required: [zebu]}]`)
                    // — written out in full: left as it was, the reference went to a class the
                    // property never holds, and `{"fox": {"marten": "x"}}` passed both members.
                    $bareRef = is_string($member['$ref'] ?? null) && $this->yii3RefWithSiblingsAsAllOf($member) === $member;
                    $memberRef = $bareRef || ($this->isSingleRefAllOf($member) && array_keys($member) === ['allOf'])
                        ? $this->referenceOrSingleRefAllOf($member)
                        : null;
                    $member = $memberRef !== null
                        ? $this->yii3MemberSchema($memberRef)
                        : $this->inlineNestedContainerValidation(
                            schema: $this->yii3WithAllOfFragmentsMerged($this->yii3RefWithSiblingsAsAllOf($member), $className),
                            depth: 2,
                        );
                    // Every member is a generated class, so an object: said outright, a member of
                    // object keywords alone no longer matches the null the branch below admits.
                    $members[] = $nullable && !array_key_exists('type', $member) ? ['type' => 'object'] + $member : $member;
                }
                // `nullable` beside the reference is lost on a list of member schemas, every one of which
                // refuses null — so null is a member of its own. Unless a member already is one: the
                // component's own `{type: 'null'}` made null match two `oneOf` branches, and refused it.
                if ($nullable && !$this->yii3SomeMemberAdmitsNull($members)) {
                    $members[] = ['type' => 'null'];
                }

                return $this->yii3UnionOwnKeywords($union, $className) + [$keyword => $members];
            }
        }

        return null;
    }

    /**
     * Whether a member schema of a union admits null by itself: `{type: 'null'}`, a type list with
     * `null` in it, or `nullable: true`.
     *
     * @param list<array<mixed>> $members
     */
    private function yii3SomeMemberAdmitsNull(array $members): bool
    {
        foreach ($members as $member) {
            $type = $member['type'] ?? null;
            if ($type === 'null' || (is_array($type) && in_array('null', $type, true)) || ($member['nullable'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The object keywords a union component carries beside its `oneOf`/`anyOf` — `type`, `required`,
     * `properties` — which judge every member alike. A union checked as its members' list lost them:
     * `{"plain": {"value": "x", "kind": "mail"}}` passed the component's `value: {type: integer}`.
     *
     * @param array<mixed> $union
     * @return array<string, mixed>
     */
    private function yii3UnionOwnKeywords(array $union, string $className): array
    {
        $own = [];
        foreach (['type', 'required', 'properties'] as $keyword) {
            if (array_key_exists($keyword, $union)) {
                $own[$keyword] = $union[$keyword];
            }
        }

        return $own === [] ? [] : $this->inlineNestedContainerValidation(schema: $own, depth: 2);
    }

    /**
     * One member of a union, written out: its own properties with the inline `allOf` branches merged,
     * and every `$ref` below it inlined.
     *
     * @return array<string, mixed>
     */
    private function yii3MemberSchema(string $ref): array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return [];
        }

        return $this->yii3MemberSchemaOfClass($this->schemaClassName(substr($ref, strlen($prefix))));
    }

    /**
     * @return array<string, mixed>
     */
    private function yii3MemberSchemaOfClass(string $className): array
    {
        return $this->inlineNestedContainerValidation(
            schema: $this->yii3ClassSchemaWithInlineAllOfMerged($this->dtoSchemas[$className] ?? [], $className),
            depth: 2,
        );
    }

    /**
     * Whether a union holds two or more generated classes — `Olive|Teal`, `ProbeBison|Teal|null` — the
     * shape an inline undiscriminated `oneOf` / `anyOf` of objects is typed with.
     */
    private function yii3IsUnionOfClasses(string $phpType): bool
    {
        $classes = 0;
        foreach ($this->unionMembersBesideNull($phpType) as $member) {
            $short = $this->shortClassName($member);
            if (array_key_exists($short, $this->dtoSchemas) && !array_key_exists($short, $this->enumSchemas) && !$this->yii3IsUnionInterface($short)) {
                $classes++;
            }
        }

        return $classes >= 2;
    }

    /**
     * Whether a union names a union interface beside other members — `Shape|string`, `Figure|Circle`.
     * The hydrator cannot build the interface, so the property holds the decoded object instead.
     */
    private function yii3HasUnionInterfaceMember(string $phpType): bool
    {
        $members = $this->unionMembersBesideNull($phpType);
        if (count($members) < 2) {
            return false;
        }
        foreach ($members as $member) {
            if ($this->yii3IsUnionInterface($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a union holds a generated class beside a `bool` member — `Olive|bool`.
     */
    private function yii3UnionClassBesideBool(string $phpType): bool
    {
        return $this->unionClassBesideScalars($phpType) !== null
            && in_array('bool', $this->unionMembersBesideNull($phpType), true);
    }

    /**
     * A union's generated class declared FIRST: the hydrator tries the members in order, so with
     * `Level|Shade` it asked to build the enum from the object — `new` on an enum, an `Error` — and
     * with `bool|Shade` the native caster made the object `true`. An array is the class; any other
     * value is not, and falls through to the members after it.
     */
    private function yii3WithUnionClassFirst(string $type, string $phpType): string
    {
        $unionClass = $this->unionClassBesideScalars($phpType);
        if ($unionClass === null || $this->yii3IsUnionInterface($unionClass)) {
            return $type;
        }
        $nullable = str_starts_with($type, '?');
        $members = explode('|', ltrim($type, '?'));
        $first = [];
        $rest = [];
        foreach ($members as $member) {
            if ($this->shortClassName(ltrim($member, '\\')) === $unionClass) {
                $first[] = $member;
            } else {
                $rest[] = $member;
            }
        }
        $reordered = implode('|', [...$first, ...$rest]);

        return $nullable ? '?' . $reordered : $reordered;
    }

    /**
     * Whether the property is an enum, or a union of enums and scalars, with no generated class and no
     * `array` among its members — the shapes an array payload could only be handed to the enum for.
     */
    private function yii3HoldsArrayForEnum(string $type): bool
    {
        if (str_contains($type, '<')) {
            return false;
        }
        $hasEnum = false;
        foreach (explode('|', ltrim($type, '?')) as $member) {
            $member = ltrim($member, '\\');
            if (array_key_exists($member, $this->enumSchemas)) {
                $hasEnum = true;
            } elseif (!in_array($member, ['string', 'int', 'float', 'bool', 'null'], true)) {
                return false;
            }
        }

        return $hasEnum;
    }

    /**
     * The generated enum in a union with builtin members (`Scope|string|int`), or null. A union of
     * several enums is left alone: a value may name a case of more than one.
     */
    private function yii3UnionEnumMember(string $type): ?string
    {
        if (str_contains($type, '<') || !str_contains($type, '|')) {
            return null;
        }
        $enums = [];
        $hasScalar = false;
        foreach (explode('|', ltrim($type, '?')) as $member) {
            $member = ltrim($member, '\\');
            if (array_key_exists($member, $this->enumSchemas)) {
                $enums[] = $member;
            } elseif (in_array($member, ['string', 'int', 'float', 'bool'], true)) {
                $hasScalar = true;
            }
        }

        return $hasScalar && count($enums) === 1 ? $enums[0] : null;
    }

    /**
     * Whether a type names an interface this mode emits for a union: a `oneOf`/`anyOf` schema, or an
     * `allOf` discriminated base.
     */
    private function yii3IsUnionInterface(string $type): bool
    {
        $className = ltrim($type, '?\\');
        foreach ($this->unionInterfacesByClass as $interfaces) {
            if (in_array($className, $interfaces, true)) {
                return true;
            }
        }

        // A DISCRIMINATED oneOf is not in that map — its members are linked through the discriminator —
        // yet it is rendered as an interface all the same, and a property typed by it was never filled.
        return $this->isOneOfDiscriminatorBase($className) || $this->isAllOfDiscriminatorBase($className);
    }

    /**
     * Where a property holds generated objects the hydrator did not build: `value` for the property
     * itself (typed by a union interface), `items` for the values of a list or map one level down,
     * null for neither.
     */
    private function yii3HoldsUnbuiltObjects(string $type): ?string
    {
        if (!str_contains($type, '<')) {
            return $this->yii3IsUnionInterface($type) ? 'value' : null;
        }
        if (preg_match('/^\??array<(string,\s*)?\??\\\?([A-Za-z_][A-Za-z0-9_\\\]*)>$/', $type, $match) !== 1) {
            return null;
        }
        $item = $this->shortClassName($match[2]);
        if (!$this->isGeneratedDtoType($item)) {
            return null;
        }

        // A list of plain classes is built by `#[Collection]`; a map of them, and a container of a union,
        // is not.
        return $match[1] !== '' || $this->yii3IsUnionInterface($item) ? 'items' : null;
    }

    /**
     * The enum a list or map holds the members of, one level deep: `array<Kind>`, `array<string, ?Kind>`.
     *
     * @return array{enum: string, nullableItem: bool}|null
     */
    private function yii3EnumContainerItem(string $type): ?array
    {
        if (preg_match('/^\??array<(?:(?:string|int|array-key),\s*)?(\?)?\\\?([A-Za-z_][A-Za-z0-9_\\\]*)>$/', $type, $match) !== 1) {
            return null;
        }
        $enum = $this->shortClassName($match[2]);

        return array_key_exists($enum, $this->enumSchemas)
            ? ['enum' => $enum, 'nullableItem' => $match[1] === '?']
            : null;
    }

    /**
     * The schema `default` of an optional property as a PHP expression, or null when there is none to
     * give. A container's is written as it is held: `[]`, or a list of scalars as listed — a default
     * naming objects, enum members or dates is not, since the property holds those in another form.
     *
     * @param SchemaProperty $property
     */
    private function yii3DefaultExpression(array $property, string $type, ?string $docType): ?string
    {
        if ($property['default'] === null || $this->propertyIsRequiredOnInput($property)) {
            return null;
        }
        if ($docType !== null) {
            return str_contains($property['type'], '<') ? $this->yii3ContainerDefault($property) : null;
        }

        $raw = ltrim($property['type'], '?\\');
        $rendered = $this->renderDefaultValue($property['default'], $raw, $type);
        if ($rendered === '') {
            return null;
        }
        $expression = substr($rendered, strlen(' = '));

        // The case is resolved against the enum's short name; the file may have to spell it otherwise.
        return array_key_exists($raw, $this->enumSchemas) && str_starts_with($expression, $raw . '::')
            ? $type . substr($expression, strlen($raw))
            : $expression;
    }

    /**
     * A container default as the property holds it: `[]` always, a list of scalars when the items are
     * plain scalars too; null for anything held in another form than the document writes it.
     *
     * @param SchemaProperty $property
     */
    private function yii3ContainerDefault(array $property): ?string
    {
        $default = $property['default'];
        if (!is_array($default)) {
            return null;
        }
        if ($default === []) {
            return '[]';
        }

        $item = ltrim($this->yii3ListItemTypeOf($property['type']) ?? '', '?');
        if (!array_is_list($default) || !in_array($item, ['int', 'float', 'string', 'bool'], true)) {
            return null;
        }
        $values = [];
        foreach ($default as $value) {
            if (!is_scalar($value)) {
                return null;
            }
            $values[] = var_export($value, true);
        }

        return '[' . implode(', ', $values) . ']';
    }

    /**
     * How one item of a list held as TEXT — in a query string, a path, a header or a cookie — reads as
     * the type its schema gives it, as a closure; `held` is the item type the property really holds.
     * Null for a body, and for items that are text anyway.
     *
     * @param SchemaProperty $property
     * @return array{cast: string, held: string}|null
     */
    private function yii3WireItemCast(array $property): ?array
    {
        $fromText = ($property['inQuery'] ?? false) === true
            || ($property['inPath'] ?? false) === true
            || ($property['inHeader'] ?? false) === true
            || ($property['inCookie'] ?? false) === true;
        $item = $this->yii3ListItemTypeOf($property['type']);
        if (!$fromText || $item === null) {
            return null;
        }

        $nullableItem = str_starts_with($item, '?');
        // A list of an enum holds its backing values: `?levels[]=2` is `"2"`, and the interpreter, which
        // checks the items' `enum`, found no `"2"` among `[1, 2, 3]`. The items read as the backing type;
        // a string-backed enum needs nothing.
        $readAs = ltrim($item, '?');
        if (array_key_exists($readAs, $this->enumSchemas)) {
            $readAs = $this->enumSchemas[$readAs]['type'];
        }
        $cast = match ($readAs) {
            'int' => "is_string(\$item) && preg_match('/^-?\\d+$/D', \$item) === 1 ? (int)\$item : \$item",
            'float' => 'is_string($item) && is_numeric($item) ? (float)$item : $item',
            'bool' => "match (\$item) { 'true', '1' => true, 'false', '0' => false, default => \$item }",
            default => null,
        };
        if ($cast === null) {
            return null;
        }

        return [
            'cast' => 'static fn(mixed $item): mixed => ' . $cast,
            'held' => $readAs . '|string' . ($nullableItem ? '|null' : ''),
        ];
    }

    /**
     * The values an `In` lists for an enum. A strict comparison holds `1` and `1.0` apart, yet JSON has
     * one number type and `1.0` IS the integer 1, decoded to a float: an int member is listed as both.
     * A lenient one (a parameter, sent as text) compares loosely and needs only the member.
     *
     * @param array<int, int|string> $values
     * @return list<string>
     */
    private function yii3EnumInValues(array $values, bool $strict): array
    {
        $listed = [];
        foreach ($values as $value) {
            $listed[] = var_export($value, true);
        }
        if ($strict) {
            foreach ($values as $value) {
                if (is_int($value)) {
                    $listed[] = var_export((float)$value, true);
                }
            }
        }

        return $listed;
    }

    private function isGeneratedDtoType(string $type): bool
    {
        return array_key_exists(ltrim($type, '\\'), $this->dtoSchemas);
    }
}
