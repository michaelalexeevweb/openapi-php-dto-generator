<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use DateTimeImmutable;
use Exception;
use FilesystemIterator;
use JsonException;
use OpenapiPhpDtoGenerator\Command\Rendering\GlobalFunctionImports;
use OpenapiPhpDtoGenerator\Command\Rendering\NamesLibraryClasses;
use OpenapiPhpDtoGenerator\Command\Rendering\QualifiedNameImports;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersCommonDtoParts;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersLaravelDataDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersLaravelDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersLaravelValidation;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersRuntimeDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersSchemaInterpreter;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersSymfonyDto;
use OpenapiPhpDtoGenerator\Command\Rendering\RendersYii3Dto;
use OpenapiPhpDtoGenerator\Schema\SchemaNormalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * @phpstan-type SchemaProperty array{
 *   name: string,
 *   openApiName: string,
 *   type: string,
 *   nullable: bool,
 *   schemaNullable?: bool,
 *   required: bool,
 *   default: mixed,
 *   description: string|null,
 *   example: string|null,
 *   temporalFormat?: string|null,
 *   unionTemporalFormats?: list<string>,
 *   itemsTemporalFormat?: string|null,
 *   inPath?: bool,
 *   inQuery?: bool,
 *   inHeader?: bool,
 *   inCookie?: bool,
 *   inQueryString?: bool,
 *   parameterStyle?: string|null,
 *   parameterExplode?: bool|null,
 *   allowReserved?: bool,
 *   allowEmptyValue?: bool|null,
 *   constraints?: array<string, mixed>,
 *   readOnly?: bool,
 *   writeOnly?: bool,
 *   deprecated?: bool,
 *   isMap?: bool,
 *   isShapeUnion?: bool
 * }
 * @phpstan-type SchemaMetadata array{
 *   properties: array<int, SchemaProperty>,
 *   extends: string|null,
 *   unionTypes: array<string>,
 *   discriminator: array{propertyName: string, mapping: array<array-key, string>}|null,
 *   abstract?: bool
 * }
 */
#[AsCommand(name: 'openapi:generate-dto', description: 'Generate readonly DTO classes from OpenAPI components.schemas')]
final class GenerateDtoCommand extends Command
{
    // One emitter per mode, each in its own file; everything they need (schema registries, type
    // resolution, naming, templates) stays on this class and is shared by all of them. The schema
    // INTERPRETER is shared too, and by four of the five: `RendersSchemaInterpreter` holds the one
    // walker, and symfony, laravel, laravel-data and yii3 each wrap it in an entry point of their own.
    use NamesLibraryClasses;
    use RendersCommonDtoParts;
    use RendersLaravelDataDto;
    use RendersLaravelDto;
    use RendersLaravelValidation;
    use RendersRuntimeDto;
    use RendersSchemaInterpreter;
    use RendersSymfonyDto;
    use RendersYii3Dto;

    /** OAS 3.2 `$self`: the document's own URI, used to recognise self-addressing `$ref`s. */
    private ?string $documentSelfUri = null;

    /**
     * Warnings collected during generation and reported by the CLI.
     *
     * @var array<int, string>
     */
    private array $generationWarnings = [];

    /**
     * Classes that describe a REQUEST payload (a request body or the query/path parameters of an
     * operation), as opposed to a response or a plain component. Laravel mode emits a FormRequest for
     * these and for nothing else.
     *
     * @var array<string, true>
     */
    private array $requestPayloadClasses = [];

    /**
     * The media types each class is declared as a REQUEST BODY under, across every operation that
     * names it — an inline body's own type, or each type a body `$ref` to a component appears under.
     * Runtime mode emits them, so the deserializer can tell a JSON-only body from one the document
     * also lets arrive as form fields; see `getRequestBodyMediaTypes()` in the runtime template.
     *
     * @var array<string, list<string>>
     */
    private array $requestBodyMediaTypes = [];

    /**
     * Request bodies that name no class of their own — a `$ref`, or any schema that is not an inline
     * `type: object` — with the media type each is declared under. Which class such a body arrives
     * as is only known once aliases are redirected, so they are attributed then; see
     * `attributeReferencedRequestBodies()`. `sourceFile` is the document the body was written in —
     * another file's `requestBodies` component resolves its own `$ref`s against that file.
     *
     * @var list<array{schema: array<mixed>, mediaType: string, sourceFile: string|null}>
     */
    private array $referencedRequestBodies = [];

    /**
     * Request bodies written at an operation that are no inline object and no inline union of references —
     * a `$ref`, a value, a union of a class and a value — by the operation that declares them. A union of
     * a class and a value among them gets no class in any mode, so nothing validates or builds it; see
     * `warnAboutValueUnionBodies()`.
     *
     * @var list<array{operation: string, schema: array<mixed>}>
     */
    private array $valueRequestBodies = [];

    /**
     * The root document's `components.schemas`, as written — what a body's local `$ref` is resolved
     * against when it is attributed. Kept because a root component that only points into another
     * file is never registered under its own name.
     *
     * @var array<mixed>
     */
    private array $requestBodyComponents = [];

    /**
     * The subset of {@see $requestPayloadClasses} built from an operation's PARAMETERS. Their values come
     * from the path, query, headers and cookies — never from the JSON body — so nothing read off the raw
     * body may be checked against them.
     *
     * @var array<string, true>
     */
    private array $parameterPayloadClasses = [];

    /** Generation mode backed by this library's runtime (DtoValidator/Normalizer/Deserializer). */
    public const string ATTRIBUTE_MODE_RUNTIME = 'runtime';

    /** The most array nodes a parsed YAML document may hold — see `fitsNodeBudget()`. */
    private const int MAX_DOCUMENT_NODES = 5_000_000;

    /** The node budget of a small YAML document, whatever its size — see `parseSpecFile()`. */
    private const int MIN_DOCUMENT_NODE_BUDGET = 100_000;

    /** How many nodes each byte of YAML text may expand to through aliases — see `parseSpecFile()`. */
    private const int DOCUMENT_NODES_PER_BYTE = 4;

    /** Generation mode emitting Symfony Validator/Serializer attributes (no library runtime). */
    public const string ATTRIBUTE_MODE_SYMFONY = 'symfony';

    /**
     * Generation mode emitting first-party Laravel artefacts: a plain DTO carrying a `rules()` array
     * for `illuminate/validation` (no library runtime, and nothing to install — `FormRequest` and the
     * validator ship with the framework).
     */
    public const string ATTRIBUTE_MODE_LARAVEL = 'laravel';

    /**
     * Generation mode emitting `spatie/laravel-data` classes: ONE `Data` subclass per schema instead of
     * the FormRequest + DTO pair, hydrated and normalized by that package's own pipeline.
     *
     * Opt-in rather than default, and the only mode whose output needs a third-party package installed.
     * What it buys is presence tracking as a language-level fact: an unprovided optional property IS a
     * `Spatie\LaravelData\Optional`, so PATCH semantics need no emitted flag array.
     */
    public const string ATTRIBUTE_MODE_LARAVEL_DATA = 'laravel-data';

    /**
     * Generation mode emitting `yiisoft/validator` attributes on a `yiisoft/input-http` input class,
     * hydrated by `yiisoft/hydrator` and validated through `yiisoft/hydrator-validator`.
     *
     * Yii3 applications are already served by runtime mode over PSR-7 (`DtoDeserializerPsr7`), so this
     * mode buys native-attribute ergonomics rather than capability — with one honest limit the other
     * framework modes do not have: Yii3 does NOT turn a failed validation into a 422 by itself. The
     * emitted class implements `ValidatedInputInterface`, and the action reads `getValidationResult()`.
     *
     * Two measured facts shape the emitter, both verified against the real packages rather than the
     * docs: `#[Callback]` is `TARGET_CLASS`, so the interpreter is entered ONCE per object with the DTO
     * as its value (the Symfony packaging, not the Laravel one); and a bare `#[Nested]` cascades into a
     * nested class's own attributes, so recursive schemas need no repeated rule set.
     */
    public const string ATTRIBUTE_MODE_YII3 = 'yii3';

    /** @var array<int, string> */
    public const array ATTRIBUTE_MODES = [
        self::ATTRIBUTE_MODE_RUNTIME,
        self::ATTRIBUTE_MODE_SYMFONY,
        self::ATTRIBUTE_MODE_LARAVEL,
        self::ATTRIBUTE_MODE_LARAVEL_DATA,
        self::ATTRIBUTE_MODE_YII3,
    ];

    /**
     * Names PHP refuses as a class name, lowercased. Two groups, one symptom: the hard keywords
     * (`list`, `match`, `readonly`, `static`, …) make the `class X` line a parse error, while the
     * soft-reserved type names (`int`, `string`, `mixed`, `never`, …) and `self`/`parent` fail at
     * load time with "Cannot use X as a class name as it is reserved". A schema is free to be called
     * any of them, so `avoidReservedPhpClassName()` renames the class, not the schema.
     *
     * @var array<int, string>
     */
    private const array PHP_RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface',
        'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw',
        'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        // Soft-reserved: legal as an identifier elsewhere, rejected as a class name.
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'numeric', 'object',
        'parent', 'resource', 'self', 'string', 'true', 'void',
    ];

    /** A PHP namespace name: identifier segments joined by single backslashes. */
    private const string NAMESPACE_NAME_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    /**
     * Keywords that give an object schema a shape even when it declares no `properties` — their
     * presence means the schema is not free-form. See `isFreeFormObjectSchema()`.
     *
     * @var array<int, string>
     */
    private const array OBJECT_SHAPING_KEYWORDS = [
        '$ref',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'discriminator',
        'patternProperties',
        'propertyNames',
        'dependentSchemas',
        'dependentRequired',
        'unevaluatedProperties',
        'required',
    ];

    /**
     * What makes an `allOf` member beside a union a schema in its own right rather than keywords that
     * hold at the union's level — see `allOfWrappedUnionWithSiblings()`.
     */
    private const array ALLOF_UNION_MEMBER_SCHEMA_KEYWORDS = [
        '$ref', 'properties', 'allOf', 'oneOf', 'anyOf', 'discriminator', 'unevaluatedProperties', 'unevaluatedItems',
    ];

    /** Keywords beside a `$ref` to a map that keep it a map: they count or name its keys, never shape a value. */
    private const array MAP_KEY_COUNT_KEYWORDS = ['minProperties', 'maxProperties', 'propertyNames', 'required', 'dependentRequired'];

    /** Keywords that stay on a property whose inline discriminated union is hoisted — they describe the property. */
    private const array HOISTED_UNION_ANNOTATIONS = ['description', 'title', 'deprecated', 'readOnly', 'writeOnly', 'example', 'examples'];

    /**
     * A hoisted pointer's name is cut past this length — see `hoistedSchemaName()`.
     */
    private const int HOISTED_NAME_MAX_LENGTH = 64;

    /**
     * Where a schema sits below another, by the keyword that holds it: one schema, a map of them
     * by name, a list of them by index — see `isSchemaPosition()`.
     */
    private const array SCHEMA_VALUED_KEYWORDS = [
        'items', 'additionalItems', 'additionalProperties', 'unevaluatedItems', 'unevaluatedProperties',
        'not', 'if', 'then', 'else', 'contains', 'propertyNames', 'contentSchema',
    ];
    private const array SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions'];
    private const array SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Keywords whose value is data, never a schema — see `rewriteDefsRefs()`.
     *
     * @var list<string>
     */
    private const array DATA_VALUED_KEYWORDS = ['enum', 'const', 'default', 'example', 'examples'];

    /**
     * Keywords an inner `allOf` wrapper may carry and still be peeled into the outer one — see
     * `peeledAllOfWrapper()`: annotations and the 3.0 `nullable`, nothing that asserts.
     *
     * @var list<string>
     */
    private const array PEELABLE_WRAPPER_KEYWORDS = [
        'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', 'nullable', '$comment',
    ];

    /**
     * Keywords (and OpenAPI containers) whose value maps NAMES to schemas or objects — see
     * `rewriteDefsRefs()`. `responses` is among them for its `default` response.
     *
     * @var list<string>
     */
    private const array NAME_KEYED_KEYWORDS = ['properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions', 'schemas', 'responses'];

    /**
     * Keywords that describe an object's KEYS. Beside them an untyped schema is still generated as an
     * object DTO — the OpenAPI convention `shapesAnObject()` keeps.
     *
     * @var list<string>
     */
    private const array KEY_DESCRIBING_KEYWORDS = [
        'properties',
        'patternProperties',
        'additionalProperties',
        'unevaluatedProperties',
        'propertyNames',
        'required',
        'minProperties',
        'maxProperties',
        'dependentRequired',
        'dependentSchemas',
        'discriminator',
    ];

    /**
     * Keywords that assert nothing about a value beyond the annotations an alias may carry.
     *
     * @var list<string>
     */
    private const array NON_ASSERTING_KEYWORDS = ['default', 'readOnly', 'writeOnly', '$comment', '$id', '$anchor', '$schema'];

    /**
     * Keywords beside a `oneOf` / `anyOf` that bound the value as a whole and give it no shape of its
     * own: a union of values with one of them is still a value alias, inlined where it is referenced
     * with the keyword beside it — see `isScalarUnionSchema()`, `isMixedValueUnionSchema()`.
     *
     * @var list<string>
     */
    private const array VALUE_UNION_SIBLING_KEYWORDS = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern', 'format',
        'minItems', 'maxItems', 'uniqueItems',
        'minProperties', 'maxProperties', 'required', 'dependentRequired', 'propertyNames',
    ];

    /**
     * Keywords that bound a number or a string and nothing else — see `isValueBoundReference()`.
     *
     * @var list<string>
     */
    private const array SCALAR_BOUND_KEYWORDS = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern', 'format',
    ];

    /**
     * Keywords that bound the size of a list or a map and nothing else — see `allOfWrappedReference()`.
     *
     * @var list<string>
     */
    private const array CONTAINER_BOUND_KEYWORDS = [
        'minItems', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties',
    ];

    /**
     * Keywords that make a union branch name a type, a class or a shape — see `isTypeLessUnionBranch()`.
     *
     * @var list<string>
     */
    private const array TYPE_NAMING_BRANCH_KEYWORDS = [
        'type', '$ref', 'allOf', 'oneOf', 'anyOf', 'not', 'if', 'enum', 'const', 'properties', 'patternProperties',
        'additionalProperties', 'unevaluatedProperties', 'dependentSchemas', 'discriminator', 'items', 'prefixItems',
    ];

    /** How many `$ref`/applicator hops `shapesAnObject()` follows before it keeps the class. */
    private const int SHAPE_PROBE_DEPTH = 16;

    /**
     * The subset of `OBJECT_SHAPING_KEYWORDS` that constrains WHICH KEYS a payload may or must carry
     * without declaring a schema for any of them. On an object with no `properties` these do not shape
     * anything a DTO could hold, and materializing one produced a class with no properties that
     * silently swallowed the whole payload — measured at four keywords, in every mode:
     *
     *   - `required: [a]` — `a` must be there, its value is unconstrained;
     *   - `dependentRequired` — the same, conditionally;
     *   - `propertyNames` — constrains key names, values unconstrained;
     *   - `unevaluatedProperties: false` — with no `properties` to evaluate, forbids every key. The
     *     empty class was almost right and yet wrong in the way that matters: it ACCEPTED the keys and
     *     dropped them, instead of reporting them.
     *
     * So such a schema stays a map, and the keyword is enforced over the map by the validator (runtime)
     * or the emitted interpreter (symfony, laravel) — which is where every other key-level keyword,
     * `additionalProperties` and `patternProperties` included, is already enforced.
     *
     * They stay in `OBJECT_SHAPING_KEYWORDS` because `isScalarAliasSchema()` reads that list for a
     * different question: whether a `type: string` schema is a plain alias. A `required` beside a scalar
     * type is not, whatever it means.
     *
     * @var array<int, string>
     */
    private const array PROPERTY_KEY_CONSTRAINT_KEYWORDS = [
        'required',
        'dependentRequired',
        'propertyNames',
        'unevaluatedProperties',
    ];

    /**
     * Numeric bounds two `allOf` restatements of one property can meet in by taking the stricter
     * value — see `mergeRestatedConstraints()`.
     *
     * @var list<string>
     */
    private const array LOWER_BOUND_KEYWORDS = ['minLength', 'minimum', 'exclusiveMinimum', 'minItems', 'minProperties', 'minContains'];

    /** @var list<string> */
    private const array UPPER_BOUND_KEYWORDS = ['maxLength', 'maximum', 'exclusiveMaximum', 'maxItems', 'maxProperties', 'maxContains'];

    private bool $deferOutputPublication = false;

    /** @var array<string, string> */
    private array $pendingOutputFiles = [];

    /** @var list<string> */
    private array $ownedOutputDirectories = [];

    /**
     * Directories the user chose for this run besides `--directory` and the `--ref` targets — the
     * runtime services' `--dto-generator-directory`. The `common` convention may place classes inside
     * them, see `assertCommonTargetChosen()`.
     *
     * @var list<string>
     */
    private array $extraOutputRoots = [];

    public ?Environment $twig = null;

    /** @var array<string, array<mixed>> */
    public array $dtoSchemas = [];

    /** @var array<string, array{type: string, values: array<int, string|int>, caseNames: array<int, string>, descriptions: array<int, ?string>}> */
    public array $enumSchemas = [];

    /**
     * Local `#/components/schemas/…` references seen while resolving types, keyed by the class name
     * they resolve to. Read once at the end by {@see assertEveryLocalReferenceResolved()}.
     *
     * @var array<string, string>
     */
    private array $localSchemaReferences = [];

    /**
     * References into another document's components seen while resolving types, keyed by the class
     * name they resolve to. Read once at the end by {@see assertEveryLocalReferenceResolved()}: such a
     * class has to be written, not only registered.
     *
     * @var array<string, string>
     */
    private array $externalSchemaReferences = [];

    /** @var array<string, true> canonical external document and schema pointer */
    private array $registeredExternalSchemas = [];

    /** @var array<string, true> */
    public array $parentClasses = [];

    /** The `openapi` version of the root document, for a referenced file that names none. */
    private ?string $rootOpenApiVersion = null;

    /** @var array<string, array<int, string>> */
    public array $unionInterfacesByClass = [];

    /**
     * The PHP type members each branch of an inline union contributed, by owner class and the union's
     * type — see `withUnionBranchMembersGrouped()`.
     *
     * @var array<string, array<int, list<string>>>
     */
    private array $unionMembersByBranch = [];

    /** @var array<string, array{propertyName: string, mapping: array<array-key, string>}> */
    public array $discriminatorSchemas = [];

    /**
     * The name each component schema has in its document, by class name — what an implicit
     * discriminator value is, while the class name is normalized (`cat_v2` becomes `CatV2`).
     *
     * @var array<string, string>
     */
    private array $componentNames = [];

    /**
     * Object bases with a `discriminator` and no `mapping`, by class name, waiting for their variants:
     * those are the schemas that `allOf` the base, and they are only all known once registration ends.
     *
     * @var array<string, string> class name => discriminator property name
     */
    private array $pendingAllOfDiscriminators = [];

    /** @var array<string, string|null> */
    public array $schemaSourceFiles = [];

    /** @var array<string, string> */
    public array $schemaNamespaces = [];

    /** @var array<string, string> */
    public array $schemaOutputDirectories = [];

    /** @var array<string, string|null> */
    public array $enumSourceFiles = [];

    /** @var array<string, string> */
    public array $enumNamespaces = [];

    /** @var array<string, string> */
    public array $enumOutputDirectories = [];

    /**
     * Source endpoint (e.g. "GET /api/orders/shipment-report/{date}") of a DTO derived from an
     * operation — path/query parameters, an inline request body, or an inline response schema —
     * keyed by generated class name. Emitted as a `Route:` doc line so the endpoint a DTO belongs
     * to is discoverable.
     *
     * @var array<string, string>
     */
    public array $endpointByClass = [];

    /**
     * Classes whose schema — or the operation that produced it — is marked `deprecated`.
     *
     * Read by every renderer when it builds the class docblock, the same way `endpointByClass` is.
     *
     * @var array<string, true>
     */
    public array $deprecatedByClass = [];

    /**
     * Origin of a DTO the generator synthesised itself (an inline nested object/array-item/allOf
     * schema that has no name in the spec), keyed by generated class name and expressed as
     * "OwnerClass.property" (with a "[]" suffix for array-item types). Emitted as a `From:` doc line
     * so the field a nameless DTO was inlined from is discoverable.
     *
     * @var array<string, string>
     */
    public array $relatedByClass = [];

    /**
     * Cache of parsed external documents: canonical file path => [schemaName => schemaDefinition].
     *
     * @var array<string, array<string, mixed>>
     */
    public array $externalDocSchemas = [];

    /**
     * The same external documents whole, for the `requestBodies` and `mediaTypes` components a body
     * may point at in another file; filled beside {@see $externalDocSchemas}, so every file read is
     * still listed there.
     *
     * @var array<string, array<mixed>>
     */
    private array $externalDocuments = [];

    /**
     * The component each schema `$ref` that addresses a file whole, or a place outside its
     * `components.schemas`, was given: canonical file path => pointer ('' for the whole file) =>
     * component name. See `pointedSchemaComponent()`.
     *
     * @var array<string, array<string, string>>
     */
    private array $pointedSchemaComponents = [];

    /**
     * The schemas `rewriteSchemaPointers()` lifted out of the document it is rewriting, by the
     * pointer they were found at; merged into that document's `components.schemas` when it is done.
     *
     * @var array<string, array{name: string, schema: mixed}>
     */
    private array $localPointedSchemas = [];

    /**
     * Raw, unmodified schema definitions keyed by generated class name, captured for every
     * registered schema (DTOs and enums alike). Lets a property that only carries a $ref recover
     * keywords declared on the referenced schema itself — notably `default`, which lives on the
     * target schema, not on the inline `{$ref: ...}` node.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $rawSchemasByClass = [];

    public ?string $rootSpecFile = null;

    /**
     * The first spelling seen for each FILE, keyed by device and inode. `realpath()` keeps the case it
     * was given, so on a case-insensitive filesystem `items.json` and `ITEMS.json` — one file — came
     * back as two, and their schemas collided with themselves. A hard link is the same question.
     *
     * @var array<string, string>
     */
    private array $canonicalSourceFiles = [];

    /**
     * Components that only name another one (`X: {$ref: Y}`), redirected to what they name and given
     * no class of their own — see `redirectPureAliasReferences()`. The value is the `$ref` the chain
     * ends at.
     *
     * @var array<string, string>
     */
    private array $pureAliasClasses = [];

    /**
     * The same redirections with the `nullable` an alias on the way adds, as
     * `withRedirectedAliases()` applies them — for a definition read RAW, before they were applied.
     *
     * @var array<string, array{ref: string, nullable: bool}>
     */
    private array $pureAliasRedirects = [];

    /**
     * The `$ref` union branches being spelled out right now, innermost last. Spelling one out extracts
     * the schema it names, which may hold the same branch again; a branch already on the stack is left
     * unvalidatable, as it was before it was spelled out at all.
     *
     * @var array<string, true>
     */
    private array $spelledOutUnionRefs = [];

    /**
     * The file whose property constraints are being extracted, so a `$ref` in them resolves against
     * the document that wrote it. Null outside that extraction — the root document.
     */
    private ?string $constraintSourceFile = null;

    /** Set once the registered schemas went through `inlineNullSchemaReferences()`. */
    private bool $referencesNormalized = false;

    /** @var array<string, true> classes whose `allOf` properties are being collected, see `allOfBranchProperties()` */
    private array $allOfPropertiesInProgress = [];

    /**
     * How many `extractValidationConstraints()` frames are on the stack — see the note there. Zero
     * means the next call is the outermost one and owns the `$ref` inlining.
     */
    private int $constraintExtractionDepth = 0;

    /**
     * Whether the property whose constraints are being extracted is typed `mixed` — nothing is
     * materialized below it, so the outermost extraction inlines every `$ref` it can resolve there.
     */
    private bool $constraintRootUnmaterialized = false;

    /**
     * The same, for the properties of a whole CLASS whose constraints are extracted at once: the ones
     * typed `mixed`, by wire name. A `$ref` union branch under one names a class nothing hydrates.
     *
     * @var array<string, true>
     */
    private array $constraintUnmaterializedProperties = [];

    /**
     * Whether a union component below the materialization boundary is inlined in Symfony mode too.
     * That serializer builds the variant objects of a typed container through the discriminator map,
     * so by default the reference is left for the class; a container a property holds as the decoded
     * `array` gets no variant built, and only its schema can judge it.
     */
    private bool $constraintInlinesUnionComponents = false;
    public string $baseOutputDirectory = '';
    public string $baseNamespace = '';

    /**
     * Generation mode. 'runtime' (default) emits DTOs backed by this library's
     * DtoValidator/DtoNormalizer/DtoDeserializer. 'symfony' emits plain data classes
     * decorated with Symfony Validator/Serializer attributes, validated and mapped by
     * Symfony itself (no copy-runtime).
     */
    public string $attributeMode = self::ATTRIBUTE_MODE_RUNTIME;

    /**
     * Explicit per-external-ref placement, keyed by the canonical (realpath) ref file. When an
     * external $ref resolves to a mapped file, its schemas go to the mapped output directory /
     * namespace instead of the default spec-name-derived placement. Set via --ref / --ref-namespace.
     *
     * @var array<string, string>
     */
    public array $refOutputDirectoryMap = [];

    /** @var array<string, string> */
    public array $refNamespaceMap = [];

    /**
     * Symfony mode: also emit `<Op>PathParams`, a DTO of the operation's `in: path` parameters. Symfony
     * has no attribute that binds route parameters to an object, so the class is only of use to an
     * application that registers a value resolver of its own — README.symfony.md shows one.
     */
    public bool $symfonyPathParams = false;

    /**
     * Laravel mode: whether every request payload DTO gets its `<Dto>FormRequest`. Off for a project with
     * `illuminate/validation` but no `laravel/framework`, where `FormRequest` does not exist and the DTO's
     * `rules()`, `withValidator()` and `fromValidated()` are driven by the project's own code.
     */
    public bool $laravelFormRequest = true;

    public string $generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface';
    public string $unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\Contract\UnsetValue';

    protected function configure(): void
    {
        $this->addOption(
            name: 'file',
            shortcut: 'f',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Path to the OpenAPI document, or a mask ("specs/*/api.yml") that generates every matching document, with {n} in --directory and --namespace standing for the n-th wildcard segment',
        );
        $this->addOption(
            name: 'directory',
            shortcut: 'd',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Output directory for generated DTO classes',
        );
        $this->addOption(
            name: 'namespace',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Namespace for generated DTO classes (overrides directory-derived namespace)',
        );
        $this->addOption(
            name: 'dto-generator-directory',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Copy DTO generator services to specified subdirectory (can be absolute path)',
            default: false,
        );
        $this->addOption(
            name: 'dto-generator-namespace',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Custom namespace for DTO generator services',
        );
        $this->addOption(
            name: 'attributes',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Generation mode: "runtime" (default, library runtime), "symfony" (Symfony Validator/Serializer attributes), "laravel" (plain DTO + Laravel validation rules), "laravel-data" (spatie/laravel-data Data classes) or "yii3" (yiisoft/validator attributes + hydrator input)',
            default: self::ATTRIBUTE_MODE_RUNTIME,
        );
        $this->addOption(
            name: 'with-psr7',
            mode: InputOption::VALUE_NONE,
            description: 'Also copy the PSR-7 deserializer (DtoDeserializerPsr7) when vendoring the runtime. Requires symfony/psr-http-message-bridge in the consuming project.',
        );
        $this->addOption(
            name: 'symfony-path-params',
            mode: InputOption::VALUE_NONE,
            description: 'With --attributes=symfony: also generate <Operation>PathParams DTOs of the path parameters, for a value resolver of your own (see README.symfony.md).',
        );
        $this->addOption(
            name: 'laravel-form-request',
            mode: InputOption::VALUE_NEGATABLE,
            description: 'With --attributes=laravel: generate a <Dto>FormRequest next to every request payload DTO (default). --no-laravel-form-request generates the DTOs only, for a project without laravel/framework (see README.laravel.md).',
        );
        $this->addOption(
            name: 'ref',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Placement for an external $ref file or directory: "<refFileOrDir>=<directory>=<namespace>". A directory key maps every ref\'d file inside it. Repeatable. The two-part form "<refFileOrDir>=<directory>" takes its namespace from a matching --ref-namespace.',
        );
        $this->addOption(
            name: 'ref-namespace',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Namespace for a two-part --ref: "<refFileOrDir>=<namespace>". Repeatable. Not needed with the three-part --ref, which names the namespace itself.',
        );
        $this->addOption(
            name: 'only',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Generate only the classes whose name matches this mask ("Pet", "Order*"), and every class they need. Repeatable.',
        );
        $this->addOption(
            name: 'exclude',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Do not generate the classes whose name matches this mask; a kept class that needs one is an error. Repeatable.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // File path must be provided only via --file
        $fileOption = $input->getOption('file');
        $file = is_string($fileOption) ? trim($fileOption) : '';
        $directoryOption = $input->getOption('directory');
        $directory = is_string($directoryOption) ? trim($directoryOption) : '';
        $namespaceOption = $input->getOption('namespace');
        $namespaceOption = is_string($namespaceOption) ? trim($namespaceOption) : '';
        $attributesOption = $input->getOption('attributes');
        $mode = is_string($attributesOption) && $attributesOption !== ''
            ? $attributesOption
            : self::ATTRIBUTE_MODE_RUNTIME;

        if (!in_array($mode, self::ATTRIBUTE_MODES, true)) {
            $io->error(sprintf(
                'Option --attributes must be one of: %s.',
                implode(', ', array_map(static fn(string $known): string => '"' . $known . '"', self::ATTRIBUTE_MODES)),
            ));
            return Command::FAILURE;
        }

        if ($file === '') {
            $io->error('Option --file is required. Example: --file=OpenApiExamples/test.yaml');
            return Command::FAILURE;
        }

        if ($directory === '') {
            $io->error('Option --directory is required. Example: --directory=generated/test');
            return Command::FAILURE;
        }

        if ($input->hasParameterOption('--namespace') && $namespaceOption === '') {
            $io->error('Option --namespace cannot be empty. Example: --namespace=Generated\Test');
            return Command::FAILURE;
        }

        try {
            $documents = $this->resolveDocuments(file: $file, directory: $directory, namespace: $namespaceOption);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $dtoGeneratorDirectoryOption = $input->getOption('dto-generator-directory');
        $dtoGeneratorDirectory = null;
        $dtoGeneratorNamespace = null;

        if ($dtoGeneratorDirectoryOption !== false) {
            $dtoGeneratorDirectory = is_string($dtoGeneratorDirectoryOption)
                ? $dtoGeneratorDirectoryOption
                : 'Common';
            $dtoGeneratorNamespaceOption = $input->getOption('dto-generator-namespace');
            $dtoGeneratorNamespace = is_string($dtoGeneratorNamespaceOption)
                ? $dtoGeneratorNamespaceOption
                : null;
            if ($dtoGeneratorNamespace !== null && trim($dtoGeneratorNamespace) !== '') {
                try {
                    $this->assertNamespaceName(namespace: trim($dtoGeneratorNamespace, '\\'), source: '--dto-generator-namespace');
                } catch (RuntimeException $exception) {
                    $io->error($exception->getMessage());

                    return Command::FAILURE;
                }
            }

            // Keep existing CLI behavior: when custom directory is provided without namespace,
            // derive namespace from directory path itself.
            if ($dtoGeneratorNamespace === null && $dtoGeneratorDirectory !== 'Common') {
                $dtoGeneratorNamespace = $this->directoryToNamespace($dtoGeneratorDirectory);
            }
        }

        $refPairs = $this->stringListOption($input, 'ref');
        $refNamespacePairs = $this->stringListOption($input, 'ref-namespace');
        $filter = new GeneratedClassFilter(
            only: $this->stringListOption($input, 'only'),
            exclude: $this->stringListOption($input, 'exclude'),
        );

        $withPsr7 = $input->getOption('with-psr7') === true;
        $this->symfonyPathParams = $input->getOption('symfony-path-params') === true;
        $this->laravelFormRequest = $input->getOption('laravel-form-request') !== false;

        $files = [];
        $ownedDirectories = [];
        $warnings = [];
        $readFiles = array_column($documents, 'file');
        $count = 0;
        try {
            $this->setExternalRefMappings($refPairs, $refNamespacePairs);

            foreach ($documents as $document) {
                MemoryExhaustionReport::watch($document['file']);
                try {
                    $generated = $this->generateDocument(
                        document: $document,
                        mode: $mode,
                        filter: $filter,
                        dtoGeneratorDirectory: $dtoGeneratorDirectory,
                        dtoGeneratorNamespace: $dtoGeneratorNamespace,
                        withPsr7: $withPsr7,
                    );
                } catch (RuntimeException $exception) {
                    if (!$this->isRefMask($file)) {
                        throw $exception;
                    }
                    throw new RuntimeException(
                        message: sprintf('%s: %s', $document['file'], $exception->getMessage()),
                        previous: $exception,
                    );
                }

                // Two documents may emit the same file — a --ref'd document both of them use, the
                // services of a shared --dto-generator-directory — and the same bytes twice are one
                // file. Different bytes at one path would leave whichever came last.
                foreach ($generated['files'] as $path => $content) {
                    if (array_key_exists($path, $files) && $files[$path] !== $content) {
                        throw new RuntimeException(sprintf(
                            '%s: generates %s differently from a document before it.',
                            $document['file'],
                            $path,
                        ));
                    }
                    $files[$path] = $content;
                }
                $ownedDirectories = [...$ownedDirectories, ...$generated['ownedDirectories']];
                $warnings = [...$warnings, ...$generated['warnings']];
                $readFiles = [...$readFiles, ...$generated['readFiles']];
                $count += $generated['count'];
            }
            MemoryExhaustionReport::stopWatching();
            $filter->assertEveryMaskMatched();

            // The copied services are the runtime mode's own deserializer, normalizer and validator;
            // a DTO of any other mode never references them, so copying them there only left
            // dead code in the consumer's tree.
            if ($this->symfonyPathParams && $mode !== self::ATTRIBUTE_MODE_SYMFONY) {
                $warnings[] = sprintf(
                    'Option --symfony-path-params is ignored with --attributes=%s: path parameters are already part of its DTO.',
                    $mode,
                );
            }
            if (!$this->laravelFormRequest && $mode !== self::ATTRIBUTE_MODE_LARAVEL) {
                $warnings[] = sprintf(
                    'Option --no-laravel-form-request is ignored with --attributes=%s: only laravel mode generates a FormRequest.',
                    $mode,
                );
            }
            if ($dtoGeneratorDirectory !== null && $mode !== self::ATTRIBUTE_MODE_RUNTIME) {
                $warnings[] = sprintf(
                    'Option --dto-generator-directory is ignored with --attributes=%s: those DTOs use no runtime services.',
                    $mode,
                );
            }
            (new GeneratedFilePublisher())->publish(
                files: $files,
                ownedDirectories: $ownedDirectories,
                inputFiles: $readFiles,
            );
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        } finally {
            MemoryExhaustionReport::stopWatching();
        }

        if ($withPsr7 && $mode === self::ATTRIBUTE_MODE_RUNTIME && $dtoGeneratorDirectory === null) {
            // Nothing is copied without a directory to copy into; the note said "copied" all the same.
            $io->warning('Option --with-psr7 is ignored without --dto-generator-directory: the runtime services are used from vendor/, where DtoDeserializerPsr7 already is.');
        } elseif ($withPsr7 && $mode === self::ATTRIBUTE_MODE_RUNTIME) {
            $io->note(
                message: 'PSR-7 deserializer copied. Install the bridge in your project: '
                . 'composer require symfony/psr-http-message-bridge',
            );
        }

        foreach (array_unique($warnings) as $warning) {
            $io->warning($warning);
        }

        if (!$this->isRefMask($file)) {
            $io->success(sprintf(
                'Generated %d DTO class(es) in %s with namespace %s.',
                $count,
                $this->resolveOutputDirectory($documents[0]['directory']),
                $documents[0]['namespace'],
            ));

            return Command::SUCCESS;
        }

        $io->listing(array_map(
            callback: fn(array $document): string => sprintf(
                '%s → %s (%s)',
                $document['file'],
                $this->resolveOutputDirectory($document['directory']),
                $document['namespace'],
            ),
            array: $documents,
        ));
        $io->success(sprintf('Generated %d DTO class(es) from %d document(s).', $count, count($documents)));

        return Command::SUCCESS;
    }

    /**
     * The documents a run generates, each with its own output directory and namespace. A plain
     * `--file` is one document. A `--file` mask is every file it matches, and `{n}` in `--directory`
     * and `--namespace` is what its n-th wildcard segment matched, in class-name case — the file name's
     * without its extension — the same word in both, so PSR-4 finds the class.
     *
     * @return list<array{file: string, directory: string, namespace: string}>
     */
    private function resolveDocuments(string $file, string $directory, string $namespace): array
    {
        $maskSegments = explode('/', str_replace('\\', '/', $file));
        $wildcards = array_keys(array_filter(
            array: $maskSegments,
            callback: fn(string $segment): bool => $this->isRefMask($segment),
        ));
        foreach (['--directory' => $directory, '--namespace' => $namespace] as $option => $value) {
            preg_match_all('/\{(\d+)\}/', $value, $placeholders);
            foreach ($placeholders[1] as $number) {
                if ((int)$number < 1 || (int)$number > count($wildcards)) {
                    throw new RuntimeException(message: sprintf(
                        '%s "%s" uses {%s}, but --file "%s" has %d wildcard segment(s) to fill it from.',
                        $option,
                        $value,
                        $number,
                        $file,
                        count($wildcards),
                    ));
                }
            }
        }

        if ($wildcards === []) {
            if (!is_file($file)) {
                throw new RuntimeException(sprintf('File not found: %s', $file));
            }

            return [[
                'file' => $file,
                'directory' => $directory,
                'namespace' => $namespace !== ''
                    ? $this->assertNamespaceName(namespace: $this->normalizeExplicitNamespace($namespace), source: '--namespace')
                    : $this->directoryToNamespace($directory),
            ]];
        }

        $matches = in_array('**', $maskSegments, true) ? $this->filesBelow($maskSegments) : glob($file);
        $matches = array_values(array_filter(is_array($matches) ? $matches : [], 'is_file'));
        sort($matches);

        $documents = [];
        foreach ($matches as $match) {
            $captures = $this->matchMaskSegments(
                mask: $maskSegments,
                path: explode('/', str_replace('\\', '/', $match)),
                wholePath: true,
            );
            if ($captures === null) {
                continue;
            }
            // The file name stands for itself without its extension: `specs/*.yml` gives `Billing`.
            if ($this->isRefMask($maskSegments[count($maskSegments) - 1]) && $captures !== []) {
                $last = array_key_last($captures);
                $fileName = array_pop($captures[$last]);
                $captures[$last][] = pathinfo((string)$fileName, PATHINFO_FILENAME);
            }
            $documentDirectory = $this->fillRefPlaceholders(value: $directory, captures: $captures, separator: '/');
            $documents[] = [
                'file' => $match,
                'directory' => $documentDirectory,
                'namespace' => $namespace !== ''
                    ? $this->assertNamespaceName(
                        namespace: $this->normalizeExplicitNamespace($this->fillRefPlaceholders(value: $namespace, captures: $captures, separator: '\\')),
                        source: '--namespace',
                    )
                    : $this->directoryToNamespace($documentDirectory),
            ];
        }
        if ($documents === []) {
            throw new RuntimeException(sprintf('--file "%s" matches no file.', $file));
        }

        return $documents;
    }

    /**
     * Every file below the literal directory a `**` mask starts from, spelled the way the mask is, so
     * its segments line up with the mask's. glob() has no `**`.
     *
     * @param list<string> $maskSegments
     * @return list<string>
     */
    private function filesBelow(array $maskSegments): array
    {
        $literal = [];
        foreach ($maskSegments as $segment) {
            if ($this->isRefMask($segment)) {
                break;
            }
            $literal[] = $segment;
        }
        $base = $literal === [] ? '.' : implode('/', $literal);
        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', (string)$file);
            $files[] = $literal === [] ? substr($path, 2) : $path;
        }

        return $files;
    }

    /**
     * One document, generated on a copy of the command: the generation state — the schema registry,
     * the render caches, the warnings — lives on the command, and nothing of one document may reach
     * the next, so a mask run gives exactly what one run per document would.
     *
     * @param array{file: string, directory: string, namespace: string} $document
     * @return array{files: array<string, string>, ownedDirectories: list<string>, warnings: list<string>, count: int, readFiles: list<string>}
     */
    private function generateDocument(
        array $document,
        string $mode,
        GeneratedClassFilter $filter,
        ?string $dtoGeneratorDirectory,
        ?string $dtoGeneratorNamespace,
        bool $withPsr7,
    ): array {
        $worker = clone $this;
        $worker->deferOutputPublication = true;
        $outputDirectory = $this->resolveOutputDirectory($document['directory']);

        $servicesNamespace = null;
        if ($dtoGeneratorDirectory !== null) {
            $servicesNamespace = $this->resolveDtoGeneratorTargetNamespace(
                namespace: $document['namespace'],
                dtoGeneratorDirectory: $dtoGeneratorDirectory,
                dtoGeneratorNamespace: $dtoGeneratorNamespace,
            );
            $worker->generatedDtoInterfaceImportFqcn = $servicesNamespace . '\GeneratedDtoInterface';
            $worker->unsetValueImportFqcn = $servicesNamespace . '\UnsetValue';
            // Only runtime mode copies the services; in any other mode the option is ignored, and a
            // directory nothing is written to grants nothing.
            $worker->extraOutputRoots = $mode === self::ATTRIBUTE_MODE_RUNTIME
                ? [$this->dtoGeneratorDirectoryPath(outputDirectory: $outputDirectory, dtoGeneratorDirectory: $dtoGeneratorDirectory)]
                : [];
        } else {
            $worker->generatedDtoInterfaceImportFqcn = 'OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface';
            $worker->unsetValueImportFqcn = 'OpenapiPhpDtoGenerator\Contract\UnsetValue';
        }

        $count = $worker->generateFromFile(
            filePath: $document['file'],
            outputDirectory: $outputDirectory,
            namespace: $document['namespace'],
            mode: $mode,
        );
        $generatedFiles = count($worker->pendingOutputFiles);
        $worker->pendingOutputFiles = $filter->filter($worker->pendingOutputFiles);
        $count -= $generatedFiles - count($worker->pendingOutputFiles);

        if ($dtoGeneratorDirectory !== null && $mode === self::ATTRIBUTE_MODE_RUNTIME) {
            $worker->copyCommonServices(
                outputDirectory: $outputDirectory,
                namespace: $document['namespace'],
                dtoGeneratorDirectory: $dtoGeneratorDirectory,
                dtoGeneratorNamespace: $servicesNamespace,
                withPsr7: $withPsr7,
            );
        }

        return [
            'files' => $worker->pendingOutputFiles,
            'ownedDirectories' => $worker->ownedOutputDirectories,
            'warnings' => array_values($worker->generationWarnings),
            'count' => $count,
            // Every document a `$ref` led to, so an output directory holding one is refused like
            // one holding the root document.
            'readFiles' => array_keys($worker->externalDocSchemas),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringListOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function generateFromFile(string $filePath, string $outputDirectory, string $namespace, string $mode = self::ATTRIBUTE_MODE_RUNTIME): int
    {
        $this->setAttributeMode($mode);

        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File not found: %s', $filePath));
        }

        $data = $this->parseSpecFile($filePath);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            // A LIST root is caught here and nowhere else: every OpenAPI document is a mapping, so a
            // file that parsed into a sequence is not one — and it used to reach the end reporting
            // `Generated 0 DTO class(es)`, which is a success message for a file nobody could read.
            throw new RuntimeException('OpenAPI root must be an object/array.');
        }

        $realFilePath = realpath($filePath);
        if ($realFilePath === false) {
            throw new RuntimeException(sprintf('Cannot resolve real path for file: %s', $filePath));
        }
        // Before the `$defs` fold: it rewrites pointers by their text, and an anchor or an `$id`
        // reference has to be resolved against the document as written.
        $data = (new SchemaResourceResolver())->resolve(document: $data, documentBase: $this->fileUri($realFilePath));
        $this->rootOpenApiVersion = is_string($data['openapi'] ?? null) ? $data['openapi'] : null;
        $data = $this->withNestedAllOfWrappersPeeled($this->normalizedDocument($this->hoistComponentSubschemas($this->foldJsonSchemaDefs($data))));

        $this->initializeGeneration(
            outputDirectory: $outputDirectory,
            namespace: $namespace,
            rootSpecFile: $realFilePath,
        );
        // After initializeGeneration: it resets the per-run document state this reads into.
        $this->readDocumentLevelFields($data);
        $data = $this->rewriteSchemaPointers(document: $data, sourceFile: $this->rootSpecFile, isRootDocument: true);
        $this->registerDocumentSchemas(openApi: $data, sourceFile: $realFilePath, includeInlineSchemas: true);
        $this->scanExternalSchemaRefs(node: $data, currentSourceFile: $realFilePath);

        return $this->finalizeGeneration();
    }

    /**
     * Parses an OpenAPI document from disk. A `.json` spec is decoded with json_decode
     * for strict, fast parsing and clear JSON error messages; every other extension is
     * parsed as YAML (which also accepts JSON, so unknown extensions still work).
     *
     * A REFERENCED file is named by the document, not by the user, so a failure to parse it reports
     * where, never what: a `$ref` at a file that is no OpenAPI document printed its offending line
     * into the CI log.
     */
    private function parseSpecFile(string $filePath, bool $referenced = false): mixed
    {
        // Strip common env/sample suffixes so `openapi.json.dist` still takes the JSON path.
        $effectivePath = preg_replace('/\.(dist|example|local|sample)$/i', '', $filePath) ?? $filePath;

        if (strtolower(pathinfo($effectivePath, PATHINFO_EXTENSION)) === 'json') {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new RuntimeException(sprintf('Cannot read file: %s', $filePath));
            }

            try {
                // Depth well above JSON's default 512: deeply nested (but valid) specs must
                // not be misreported as malformed JSON.
                return json_decode($contents, associative: true, depth: 4096, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(
                    message: sprintf('Invalid JSON in %s: %s', $filePath, $e->getMessage()),
                    previous: $e,
                );
            }
        }

        $text = @file_get_contents($filePath);
        try {
            // Tried in a child process first, where it can: deep flow nesting makes the parser's
            // recursion kill the process, and a dead process reports nothing at all. A parse error the
            // child met is thrown from the probe, so it is reported here like one met in this process.
            $crashStatus = is_string($text) ? (new YamlParserProbe())->crashStatus(filePath: $filePath, text: $text) : null;
            if ($crashStatus !== null) {
                throw new RuntimeException(message: sprintf(
                    '%s crashed the YAML parser (status %d), as a document that nests flow collections ([ ] or { }) thousands of levels deep does. Nothing was generated.',
                    $filePath,
                    $crashStatus,
                ));
            }

            $parsed = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            // symfony/yaml from 7.4.14 / 8.0.x counts alias expansion itself and stops the bomb before
            // the budget below sees it. Same refusal, same words, whichever release did the counting.
            if (str_starts_with($e->getMessage(), 'Maximum number of nodes expanded by collection aliases')) {
                throw new RuntimeException(
                    message: sprintf(
                        '%s expands to more than the YAML parser\'s alias limit allows from %d bytes of text — YAML aliases nested into one another? Nothing was generated.',
                        $filePath,
                        is_string($text) ? strlen($text) : 0,
                    ),
                    previous: $e,
                );
            }
            if (!$referenced) {
                throw $e;
            }
            throw new RuntimeException(sprintf('Invalid YAML in referenced file %s at line %d.', $filePath, $e->getParsedLine()));
        }

        // The budget grows with the text: written out, a document holds about one node per twenty
        // bytes, so four per byte leaves an honest document's anchors far more room than they use,
        // while a few hundred bytes of nested aliases standing for millions of nodes — a 600-byte
        // document took 16 s and 856 MB under the old fixed budget — is refused before anything is built.
        $textBytes = is_string($text) ? strlen($text) : 0;
        $limit = min(self::MAX_DOCUMENT_NODES, max(self::MIN_DOCUMENT_NODE_BUDGET, self::DOCUMENT_NODES_PER_BYTE * $textBytes));
        $budget = $limit;
        if (!$this->fitsNodeBudget(node: $parsed, budget: $budget)) {
            throw new RuntimeException(message: sprintf(
                '%s expands to more than %d nodes from %d bytes of text — YAML aliases nested into one another? Nothing was generated.',
                $filePath,
                $limit,
                $textBytes,
            ));
        }

        return $parsed;
    }

    /**
     * YAML aliases share one parsed array between every place that names it, so a few hundred bytes
     * of anchors nested ten deep stand for 10^10 nodes — and every pass that rebuilds the document
     * (`rewriteDefsRefs()` first) materialises them, until memory runs out. Counting stops at the
     * budget, so the check itself stays cheap.
     */
    private function fitsNodeBudget(mixed $node, int &$budget): bool
    {
        if (!is_array($node)) {
            return true;
        }
        $budget -= count($node);
        if ($budget < 0) {
            return false;
        }
        foreach ($node as $child) {
            if (is_array($child) && !$this->fitsNodeBudget(node: $child, budget: $budget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A `$ref` that points INSIDE a component — `#/components/schemas/Pet/properties/name` — names a
     * schema no class is written for. It read as a class named `Pet/properties/name` and stopped
     * generation as unresolvable. Each such schema becomes a component of its own, named after its
     * path (`PetPropertiesName`), and every reference to it is rewritten there; references to one place
     * stay one component. A boolean component — `true`, the schema everything satisfies, `false`, the
     * one nothing does — is written in an object spelling the readers know — see
     * `booleanSchemaAsObject()`.
     *
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function hoistComponentSubschemas(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? null;
        if (!is_array($schemas)) {
            return $openApi;
        }
        foreach ($schemas as $name => $schema) {
            if (is_bool($schema)) {
                $schemas[$name] = self::booleanSchemaAsObject($schema);
            }
        }
        $openApi['components']['schemas'] = $schemas;

        $prefix = '#/components/schemas/';
        $hoisted = [];
        // A hoisted schema may point inside another component in turn; a few rounds reach the end.
        for ($round = 0; $round < 8; $round++) {
            $pointers = [];
            $this->collectComponentSubschemaPointers(node: $openApi, prefix: $prefix, pointers: $pointers);
            $fresh = array_diff_key($pointers, $hoisted);
            if ($fresh === []) {
                break;
            }
            /** @var array<string, mixed> $schemas */
            $schemas = $openApi['components']['schemas'];
            // A name is taken when its CLASS is: `widgetPropertiesInner` and `WidgetPropertiesInner`
            // are one class, and a pointer that took the second beside the first stopped generation.
            $takenClassNames = [];
            foreach (array_keys($schemas) as $schemaName) {
                $takenClassNames[strtolower($this->schemaClassName($schemaName))] = true;
            }
            /** @var array<string, array<mixed>> $copies */
            $copies = [];
            foreach (array_keys($fresh) as $pointer) {
                $segments = self::componentPointerSegments($pointer, $prefix);
                $node = $schemas;
                foreach ($segments as $segment) {
                    if (!is_array($node) || !array_key_exists($segment, $node)) {
                        $node = null;
                        break;
                    }
                    $node = $node[$segment];
                }
                if (is_bool($node)) {
                    $node = self::booleanSchemaAsObject($node);
                }
                if (!is_array($node)) {
                    $hoisted[$pointer] = null;
                    continue;
                }
                $name = $this->hoistedSchemaName(pointer: $pointer, segments: $segments, takenClassNames: $takenClassNames);
                $takenClassNames[strtolower($this->schemaClassName($name))] = true;
                $copies[$pointer] = $node;
                $hoisted[$pointer] = $name;
            }
            $renames = array_filter($hoisted, is_string(...));
            foreach ($copies as $pointer => $node) {
                $schemas[$renames[$pointer]] = $this->referenceHoistedDescendants(
                    node: $node,
                    pointer: $pointer,
                    prefix: $prefix,
                    renames: $renames,
                );
            }
            $openApi['components']['schemas'] = $schemas;
            $rewritten = $this->rewriteComponentSubschemaPointers(node: $openApi, prefix: $prefix, renames: $renames);
            $openApi = is_array($rewritten) ? $rewritten : $openApi;
        }

        return $this->hoistInlineDiscriminatedUnions($this->flattenNullableDiscriminatedUnions($openApi));
    }

    /**
     * `oneOf: [{oneOf: [$ref Square, $ref Hexagon], discriminator}, {type: null}]` — a discriminated
     * union made nullable by wrapping it — is the union with `null` beside its members, the spelling
     * `oneOf: [$ref Square, $ref Hexagon, {type: null}], discriminator` that every mode dispatches on.
     * Wrapped, the discriminator sat one level down where no mode looked: the property was typed
     * `Square|Hexagon|null`, Symfony and yii3 built the first member for every payload, and laravel
     * could not build it at all.
     *
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function flattenNullableDiscriminatedUnions(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? null;
        if (!is_array($schemas)) {
            return $openApi;
        }
        foreach ($schemas as $schemaName => $schema) {
            if (is_array($schema)) {
                $schemas[$schemaName] = $this->withNullableDiscriminatedUnionsFlattened(schema: $schema, components: $openApi['components']['schemas'], depth: 0);
            }
        }
        $openApi['components']['schemas'] = $schemas;

        return $openApi;
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $components
     * @return array<mixed>
     */
    private function withNullableDiscriminatedUnionsFlattened(array $schema, array $components, int $depth): array
    {
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return $schema;
        }
        // An inline object is a class wherever it is written — a `oneOf` / `anyOf` / `allOf` branch, a
        // list item, a tuple slot, a map value — and its properties are walked like a component's. The
        // walk followed `properties` alone, so a wrapped union one branch down kept its wrapper and lost
        // its discriminator: symfony built the first member, laravel built every tag as the first one.
        $schema = $this->withInlineSubschemasMapped(
            schema: $schema,
            map: fn(array $subschema, string $segment): array => $this->withNullableDiscriminatedUnionsFlattened(schema: $subschema, components: $components, depth: $depth + 1),
        );
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }
        foreach ($schema['properties'] as $propertyName => $property) {
            if (is_array($property)) {
                $schema['properties'][$propertyName] = $this->withNullableDiscriminatedUnionsFlattened(
                    schema: $this->nullableDiscriminatedUnionFlattened($property, $components),
                    components: $components,
                    depth: $depth + 1,
                );
            }
        }

        return $schema;
    }

    /**
     * The schema with each INLINE subschema an object can sit in — a `oneOf` / `anyOf` / `allOf` branch,
     * `items`, a `prefixItems` slot, an `additionalProperties` schema — passed through `$map` with the
     * path segment it sits at (`oneOf0`, `items`, `prefixItems1`, `additionalProperties`). A bare `$ref`
     * is left alone: the component it names is walked as a component. A `$ref` with `properties` beside
     * it is mapped like an inline object — those properties are its own, as they are on a direct property
     * (`{$ref: Base, properties: {…}}`), which the property walk already follows: as a union branch, a
     * list item or a map value it was skipped, and a discriminated union on one of its properties was
     * never hoisted — symfony built its first member.
     *
     * @param array<mixed> $schema
     * @param callable(array<mixed>, string): array<mixed> $map
     * @return array<mixed>
     */
    private function withInlineSubschemasMapped(array $schema, callable $map): array
    {
        $inline = static fn(mixed $subschema): bool => is_array($subschema)
            && (!array_key_exists('$ref', $subschema) || is_array($subschema['properties'] ?? null));
        foreach (['oneOf', 'anyOf', 'allOf', 'prefixItems'] as $listKey) {
            if (!is_array($schema[$listKey] ?? null)) {
                continue;
            }
            $mapped = [];
            foreach ($schema[$listKey] as $index => $subschema) {
                $mapped[$index] = $inline($subschema) ? $map($subschema, $listKey . $index) : $subschema;
            }
            $schema[$listKey] = $mapped;
        }
        foreach (['items', 'additionalProperties'] as $schemaKey) {
            if ($inline($schema[$schemaKey] ?? null)) {
                $schema[$schemaKey] = $map($schema[$schemaKey], $schemaKey);
            }
        }

        return $schema;
    }

    /**
     * @param array<mixed> $property
     * @param array<mixed> $components
     * @return array<mixed>
     */
    private function nullableDiscriminatedUnionFlattened(array $property, array $components): array
    {
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($property)));
        if (count($keywords) !== 1 || array_key_exists('discriminator', $property) || !is_array($property[$keywords[0]])) {
            return $property;
        }
        $union = null;
        $nullBranches = 0;
        foreach ($property[$keywords[0]] as $branch) {
            if (!is_array($branch)) {
                return $property;
            }
            /** @var array<string, mixed> $branch */
            if (array_keys($branch) === ['type'] && $this->isNullUnionBranch($branch)) {
                ++$nullBranches;

                continue;
            }
            if ($union !== null || !$this->isHoistableDiscriminatedUnion($branch) || $this->discriminatedUnionAdmitsNull($branch, $components)) {
                return $property;
            }
            $union = $branch;
        }
        if ($union === null || $nullBranches === 0) {
            return $property;
        }
        $innerKeyword = array_key_exists('oneOf', $union) ? 'oneOf' : 'anyOf';
        $flattened = [];
        foreach ($property as $keyword => $value) {
            if ($keyword !== $keywords[0]) {
                $flattened[$keyword] = $value;
            }
        }
        foreach ($union as $keyword => $value) {
            if ($keyword !== $innerKeyword && !array_key_exists($keyword, $flattened)) {
                $flattened[$keyword] = $value;
            }
        }
        $flattened[$innerKeyword] = [...$union[$innerKeyword], ['type' => 'null']];

        return $flattened;
    }

    /**
     * Whether a member of a discriminated union admits `null` itself — then `null` beside the union is
     * not the extra value it is otherwise, and the wrapper is left as written.
     *
     * @param array<mixed> $union
     * @param array<mixed> $components
     */
    private function discriminatedUnionAdmitsNull(array $union, array $components): bool
    {
        foreach ($union['oneOf'] ?? $union['anyOf'] ?? [] as $member) {
            $ref = is_array($member) ? ($member['$ref'] ?? null) : null;
            if (!is_string($ref)) {
                continue;
            }
            if (!str_starts_with($ref, '#/components/schemas/')) {
                return true;
            }
            $definition = $components[$this->componentNameInPointer($ref)] ?? null;
            if (!is_array($definition) || $this->schemaAllowsNull($definition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A discriminated union written inline on a property — a component's, or an inline object's — in the
     * two modes that need a TYPE to carry the discriminator:
     * made a component of its own — `<Schema><Property>` — and referenced, so it takes exactly the path
     * the component spelling takes.
     *
     * Symfony's serializer reads a discriminator only from `#[DiscriminatorMap]` on the type it builds,
     * and under `#[MapRequestPayload]` (`collect_denormalization_errors`) a `Circle|Oval` property was
     * built as the FIRST member every time, its failures collected rather than thrown: a valid `oval`
     * was a 422. The yii3 hydrator likewise built the first member, so a valid `oval` was refused and
     * `{}` passed. The component spelling worked in both. Only a union of plain references moves: a
     * member written inline, or a `nullable` beside the union, keeps the property as it was.
     *
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function hoistInlineDiscriminatedUnions(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? null;
        if (!in_array($this->attributeMode, [self::ATTRIBUTE_MODE_SYMFONY, self::ATTRIBUTE_MODE_YII3], true) || !is_array($schemas)) {
            return $openApi;
        }

        $takenClassNames = [];
        foreach (array_keys($schemas) as $schemaName) {
            $takenClassNames[strtolower($this->schemaClassName((string)$schemaName))] = true;
        }
        $hoisted = [];
        foreach ($schemas as $schemaName => $schema) {
            if (is_array($schema)) {
                $schemas[$schemaName] = $this->withDiscriminatedUnionsHoisted(
                    schema: $schema,
                    segments: [(string)$schemaName],
                    takenClassNames: $takenClassNames,
                    hoisted: $hoisted,
                );
            }
        }
        $openApi['components']['schemas'] = $schemas + $hoisted;

        return $openApi;
    }

    /**
     * The walk under one schema: its properties and its composition branches, at any depth — an inline
     * object's property is a class's property as much as a component's is. The schema itself is never replaced: a component that IS a
     * union already has the component spelling, and so does a union that IS a list item or a map value:
     * there the inline spelling is the one each mode already checks item by item. An inline object as
     * the item or the value is walked like any other — its properties are a class's.
     *
     * @param array<mixed> $schema
     * @param list<string> $segments the path so far, spelled into the hoisted name
     * @param array<string, true> $takenClassNames
     * @param array<string, array<mixed>> $hoisted
     * @return array<mixed>
     */
    private function withDiscriminatedUnionsHoisted(array $schema, array $segments, array &$takenClassNames, array &$hoisted): array
    {
        // An inline object written as a `oneOf` / `anyOf` / `allOf` branch, a list item or a map value
        // is a class too, and its properties are that class's: a discriminated union there was left
        // inline, and symfony built its first member while yii3 checked the first member's tag.
        $schema = $this->withInlineSubschemasMapped(
            schema: $schema,
            map: function (array $subschema, string $segment) use ($segments, &$takenClassNames, &$hoisted): array {
                return $this->withDiscriminatedUnionsHoisted(
                    schema: $subschema,
                    segments: [...$segments, $segment],
                    takenClassNames: $takenClassNames,
                    hoisted: $hoisted,
                );
            },
        );
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }

        foreach ($schema['properties'] as $propertyName => $property) {
            if (!is_array($property)) {
                continue;
            }
            $propertySegments = [...$segments, (string)$propertyName];
            // `null` beside the members — `oneOf: [$ref Square, $ref Hexagon, {type: null}]`, or 3.0's
            // `nullable: true` — is the hoisted union, made nullable where it is referenced.
            [$withoutNull, $nullable] = $this->discriminatedUnionWithoutNull($property);
            if (!$this->isHoistableDiscriminatedUnion($withoutNull)) {
                $schema['properties'][$propertyName] = $this->withDiscriminatedUnionsHoisted(
                    schema: $property,
                    segments: $propertySegments,
                    takenClassNames: $takenClassNames,
                    hoisted: $hoisted,
                );
                continue;
            }

            $union = [];
            $annotations = [];
            foreach ($withoutNull as $keyword => $value) {
                if (in_array($keyword, self::HOISTED_UNION_ANNOTATIONS, true)) {
                    $annotations[$keyword] = $value;
                } else {
                    $union[$keyword] = $value;
                }
            }
            // The same union hoisted twice would be two interfaces over the same members, and a member
            // of two discriminated unions has no single base to implement: the second one was written
            // with no members at all. One union, one type.
            ksort($union);
            $name = array_search($union, $hoisted, true);
            if (!is_string($name)) {
                $name = $this->hoistedSchemaName(
                    pointer: '#/components/schemas/' . implode('/', $propertySegments),
                    segments: $propertySegments,
                    takenClassNames: $takenClassNames,
                );
                $takenClassNames[strtolower($this->schemaClassName($name))] = true;
                $hoisted[$name] = $union;
            }
            $reference = $nullable
                ? ['oneOf' => [['$ref' => '#/components/schemas/' . $name], ['type' => 'null']]]
                : ['$ref' => '#/components/schemas/' . $name];
            $schema['properties'][$propertyName] = $reference + $annotations;
        }

        return $schema;
    }

    /**
     * A discriminated union with its `null` taken out — the `{type: null}` members and a `nullable: true`
     * beside it — and whether there was one. Anything else comes back as it was, with `false`.
     *
     * @param array<mixed> $property
     * @return array{0: array<mixed>, 1: bool}
     */
    private function discriminatedUnionWithoutNull(array $property): array
    {
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($property)));
        if (!is_string($property['discriminator']['propertyName'] ?? null) || count($keywords) !== 1 || !is_array($property[$keywords[0]])) {
            return [$property, false];
        }
        $nullable = false;
        $members = [];
        foreach ($property[$keywords[0]] as $member) {
            if (is_array($member) && array_keys($member) === ['type'] && $member['type'] === 'null') {
                $nullable = true;

                continue;
            }
            $members[] = $member;
        }
        $withoutNull = [];
        foreach ($property as $keyword => $value) {
            if ($keyword === 'nullable' && $value === true) {
                $nullable = true;

                continue;
            }
            $withoutNull[$keyword] = $keyword === $keywords[0] ? $members : $value;
        }

        return $nullable && $members !== [] ? [$withoutNull, true] : [$property, false];
    }

    /**
     * `oneOf` or `anyOf` of `$ref`s alone, a discriminator naming its property, and nothing else beside
     * them but annotations and what a component union may carry as well: `type: object` (every member
     * is one), and `properties` / `required` of its own. With `type: object` beside it the union stayed
     * inline: symfony built a member by guessing and refused a valid `mail` as the wrong class, and
     * yii3 counted both members as a match. The component spelling checks the rest.
     *
     * @param array<mixed> $property
     */
    private function isHoistableDiscriminatedUnion(array $property): bool
    {
        if (!is_string($property['discriminator']['propertyName'] ?? null)) {
            return false;
        }
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($property)));
        if (count($keywords) !== 1 || !is_array($property[$keywords[0]]) || $property[$keywords[0]] === []) {
            return false;
        }
        foreach ($property[$keywords[0]] as $member) {
            if (!$this->isAnnotatedReference($member)) {
                return false;
            }
        }

        if (array_key_exists('type', $property) && $property['type'] !== 'object') {
            return false;
        }

        return array_diff(array_keys($property), [$keywords[0], 'discriminator', 'type', 'properties', 'required', ...self::HOISTED_UNION_ANNOTATIONS]) === [];
    }

    /**
     * The name a hoisted pointer takes: its path spelled as a class (`PetPropertiesName`), or the next
     * free suffix when that class exists. A path deep enough spells a name past what a file system
     * takes as a file name (255 bytes) — twenty-odd `properties/a` levels — so a long one is cut and
     * told apart from its neighbours by a checksum of the pointer.
     *
     * @param list<string> $segments
     * @param array<string, true> $takenClassNames lower-cased class names already in use
     */
    private function hoistedSchemaName(string $pointer, array $segments, array $takenClassNames): string
    {
        $base = implode('', array_map(
            callback: static fn(string $segment): string => $segment === ''
                ? 'Empty'
                : ucfirst((string)preg_replace('/[^A-Za-z0-9]+/', '', $segment)),
            array: $segments,
        ));
        if ($base === '') {
            $base = 'Pointed';
        }
        if (strlen($base) > self::HOISTED_NAME_MAX_LENGTH) {
            $base = substr($base, 0, self::HOISTED_NAME_MAX_LENGTH - 10) . sprintf('%010u', crc32($pointer));
        }
        $name = $base;
        $suffix = 2;
        while (array_key_exists(strtolower($this->schemaClassName($name)), $takenClassNames)) {
            $name = $base . $suffix++;
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private static function componentPointerSegments(string $pointer, string $prefix): array
    {
        return array_map(
            callback: static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array: explode('/', substr($pointer, strlen($prefix))),
        );
    }

    /**
     * A hoisted schema is a copy of the subtree it points at. A schema below it that another pointer
     * names is that pointer's component already, so the copy refers to it instead of carrying a second
     * copy — with its own set of classes. Copied whole, N pointers down one chain of depth D wrote
     * N·D classes from a document of D + N lines. The nearest such schema is replaced first; one below
     * it is inside that component already, and its path no longer leads anywhere here.
     *
     * Only a schema position is replaced: a pointer at a `properties` map is hoisted as written, but
     * putting a `$ref` in place of the map would empty the object that holds it.
     *
     * @param array<mixed> $node
     * @param array<string, string> $renames pointer => hoisted component name
     * @return array<mixed>
     */
    private function referenceHoistedDescendants(array $node, string $pointer, string $prefix, array $renames): array
    {
        $descendants = array_filter(
            array: array_keys($renames),
            callback: static fn(string $candidate): bool => str_starts_with($candidate, $pointer . '/'),
        );
        usort($descendants, static fn(string $left, string $right): int => strlen($left) <=> strlen($right));
        $depth = count(self::componentPointerSegments($pointer, $prefix));
        foreach ($descendants as $descendant) {
            $segments = self::componentPointerSegments($descendant, $prefix);
            if (!self::isSchemaPosition($segments)) {
                continue;
            }
            $node = self::replacedAtPath(
                node: $node,
                path: array_slice($segments, $depth),
                replacement: ['$ref' => $prefix . $renames[$descendant]],
            ) ?? $node;
        }

        return $node;
    }

    /**
     * Whether a component path (`Pet`, `properties`, `name`) ends at a schema rather than at a keyword's
     * map or list of schemas, read from the component down.
     *
     * @param list<string> $segments
     */
    private static function isSchemaPosition(array $segments): bool
    {
        $count = count($segments);
        $position = 1;
        while ($position < $count) {
            $keyword = $segments[$position];
            $next = $segments[$position + 1] ?? null;
            $indexed = $next !== null && ctype_digit($next);
            // `items` holds one schema, or — the draft-4 tuple form — a list of them.
            if ($keyword === 'items' && $indexed) {
                $position += 2;
                continue;
            }
            if (in_array($keyword, self::SCHEMA_LIST_KEYWORDS, true)) {
                if (!$indexed) {
                    return false;
                }
                $position += 2;
                continue;
            }
            if (in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true)) {
                if ($next === null) {
                    return false;
                }
                $position += 2;
                continue;
            }
            if (!in_array($keyword, self::SCHEMA_VALUED_KEYWORDS, true)) {
                return false;
            }
            $position++;
        }

        return true;
    }

    /**
     * `$node` with the value at `$path` replaced, or null when the path does not lead there.
     *
     * @param array<mixed> $node
     * @param list<string> $path
     * @param array<string, string> $replacement
     * @return array<mixed>|null
     */
    private static function replacedAtPath(array $node, array $path, array $replacement): ?array
    {
        $segment = $path[0] ?? null;
        if ($segment === null || !array_key_exists($segment, $node)) {
            return null;
        }
        $rest = array_slice($path, 1);
        if ($rest === []) {
            $node[$segment] = $replacement;

            return $node;
        }
        $child = $node[$segment];
        if (!is_array($child)) {
            return null;
        }
        $replaced = self::replacedAtPath(node: $child, path: $rest, replacement: $replacement);
        if ($replaced === null) {
            return null;
        }
        $node[$segment] = $replaced;

        return $node;
    }

    /**
     * A boolean schema in an object spelling. `false` is `{not: {}}`. `true` is not `{}`: an empty
     * component is how a document names a free-form OBJECT here, and keeps a class — so it is
     * `{not: {not: {}}}`, which admits every value and, asserting something, is a value of any type
     * with no class of its own.
     *
     * @return array<string, mixed>
     */
    private static function booleanSchemaAsObject(bool $schema): array
    {
        return $schema ? ['not' => ['not' => []]] : ['not' => []];
    }

    /**
     * @param array<string, true> $pointers
     */
    private function collectComponentSubschemaPointers(mixed $node, string $prefix, array &$pointers): void
    {
        if (!is_array($node)) {
            return;
        }
        $ref = $node['$ref'] ?? null;
        if (is_string($ref) && str_starts_with($ref, $prefix) && str_contains(substr($ref, strlen($prefix)), '/')) {
            $pointers[$ref] = true;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectComponentSubschemaPointers(node: $value, prefix: $prefix, pointers: $pointers);
            }
        }
    }

    /**
     * @param array<string, string> $renames
     */
    private function rewriteComponentSubschemaPointers(mixed $node, string $prefix, array $renames): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && array_key_exists($value, $renames)) {
                $node[$key] = $prefix . $renames[$value];
                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->rewriteComponentSubschemaPointers(node: $value, prefix: $prefix, renames: $renames);
            }
        }

        return $node;
    }

    /**
     * Folds JSON Schema's `$defs` (2019-09/2020-12) into OpenAPI's `components.schemas`:
     * every root-level `$defs` entry is copied into `components.schemas` (an existing
     * component of the same name wins) and every `#/$defs/X` pointer (local `#/$defs/X`
     * OR external `file.yaml#/$defs/X`) is rewritten to the `#/components/schemas/X` form.
     * The rest of the generator resolves only `#/components/schemas/`, so it then handles
     * `$defs`-based specs unchanged. Applied to the root document AND each external file
     * (see externalSchemasOf), so cross-file `$defs` references resolve too.
     *
     * Not folded: subschema-LOCAL `$defs` (e.g. `#/components/schemas/Foo/$defs/Bar`) —
     * rare; prefer `components.schemas` for shared types.
     *
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function foldJsonSchemaDefs(array $openApi): array
    {
        $defs = $openApi['$defs'] ?? null;
        $hasDefs = is_array($defs) && $defs !== [];

        if ($hasDefs) {
            $components = is_array($openApi['components'] ?? null) ? $openApi['components'] : [];
            $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
            foreach ($defs as $name => $schema) {
                if (is_string($name) && !array_key_exists($name, $schemas)) {
                    $schemas[$name] = $schema;
                }
            }
            $components['schemas'] = $schemas;
            $openApi['components'] = $components;
            unset($openApi['$defs']);
        }

        $rewritten = $this->rewriteDefsRefs($openApi);

        return is_array($rewritten) ? $rewritten : $openApi;
    }

    /**
     * The document in the canonical spelling of `SchemaNormalizer` — the same schema written one way
     * whatever wrapper the document put around it — under the rules the generator already reads alike
     * (`SchemaNormalizer::GENERATION_RULES`). A referenced file without an `openapi` field is read as the
     * root document's version.
     *
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private function normalizedDocument(array $document): array
    {
        return (new SchemaNormalizer(SchemaNormalizer::GENERATION_RULES))->normalize(document: $document, assumedVersion: $this->rootOpenApiVersion);
    }

    /**
     * A document with every nested `allOf` wrapper peeled to one layer: `{allOf: [{allOf: [X],
     * nullable: true}]}` — the 3.0 idiom `{allOf: [X], nullable: true}` wrapped once more — is that idiom,
     * and the inner wrapper's annotations and `nullable` move out beside the outer `allOf`
     * (`peeledAllOfWrapper()`). Every reader of a wrapper — a reference, a list alias, an item class,
     * a union member — unwraps one layer, so the second one hid X from all of them: a list alias was
     * typed by its class, which is never written, and an object item lost its `null`.
     *
     * The walk follows `rewriteDefsRefs()`: a data value is left as written, and below a map of names a
     * key spelled `allOf` is a property name.
     */
    private function withNestedAllOfWrappersPeeled(mixed $node, bool $namesKeys = false): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            $result[$key] = !$namesKeys && in_array($key, self::DATA_VALUED_KEYWORDS, true)
                ? $value
                : $this->withNestedAllOfWrappersPeeled(
                    node: $value,
                    namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED_KEYWORDS, true),
                );
        }
        if ($namesKeys) {
            return $result;
        }
        while (($peeled = $this->peeledAllOfWrapper($result)) !== null) {
            $result = $peeled;
        }

        return $result;
    }

    /**
     * `{allOf: [{allOf: [X], <inner>}], <outer>}` as `{allOf: [X], <outer>, <inner>}`, when the inner
     * wrapper says nothing but annotations and `nullable` (`PEELABLE_WRAPPER_KEYWORDS`, `x-`
     * extensions) and the two sides share no keyword with different values; null otherwise. An `allOf`
     * of one member holds what that member holds, so the inner layer's keywords hold beside the outer
     * `allOf` as well — its `nullable: true` too: `allOf: [S]` with S admitting null is S.
     *
     * @param array<mixed> $schema
     * @return array<mixed>|null
     */
    private function peeledAllOfWrapper(array $schema): ?array
    {
        $outer = $schema['allOf'] ?? null;
        if (!is_array($outer) || count($outer) !== 1 || !array_is_list($outer) || !is_array($outer[0])) {
            return null;
        }
        $inner = $outer[0];
        $innerAllOf = $inner['allOf'] ?? null;
        if (!is_array($innerAllOf) || count($innerAllOf) !== 1 || !array_is_list($innerAllOf)) {
            return null;
        }
        $peeled = $schema;
        $peeled['allOf'] = $innerAllOf;
        foreach ($inner as $keyword => $value) {
            if ($keyword === 'allOf') {
                continue;
            }
            if (!in_array($keyword, self::PEELABLE_WRAPPER_KEYWORDS, true) && !str_starts_with((string)$keyword, 'x-')) {
                return null;
            }
            if (array_key_exists($keyword, $peeled) && $peeled[$keyword] !== $value) {
                return null;
            }
            $peeled[$keyword] = $value;
        }

        return $peeled;
    }

    /**
     * The URI a document read from disk is retrieved by — the base its relative `$id`s resolve
     * against when it declares no `$self`.
     */
    private function fileUri(string $realPath): string
    {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $realPath);

        return 'file://' . (str_starts_with($path, '/') ? '' : '/') . implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * A `$ref` fragment is a URI fragment, so its JSON pointer arrives percent-encoded (RFC 6901 §6):
     * `#/components/schemas/Big%20Cat` names `Big Cat`. Read as written, it named nothing.
     */
    private function decodeRefFragment(string $ref): string
    {
        $hash = strpos($ref, '#');
        if ($hash === false || !str_contains($ref, '%')) {
            return $ref;
        }

        return substr($ref, 0, $hash + 1) . rawurldecode(substr($ref, $hash + 1));
    }

    /**
     * Recursively rewrites every `$ref` pointer containing `#/$defs/` (local or
     * external-file) to the `#/components/schemas/` form. Subschema-local `$defs`
     * pointers (`.../Foo/$defs/Bar`, no leading `#/`) are intentionally left untouched.
     * Other keys/values are returned unchanged.
     *
     * The value of `enum`, `const`, `default`, `example` and `examples` is DATA: a `{"$ref": …}` in
     * it is a value a payload must equal, and rewritten it named a pointer no payload ever sends —
     * `enum: [{$ref: '#/$defs/a'}]` refused the very object it lists. Below a map of NAMES
     * (`properties`, `$defs`, …) a key spelled `enum` is a property name, and its value a schema.
     */
    private function rewriteDefsRefs(mixed $node, bool $namesKeys = false): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $result = [];
        foreach ($node as $key => $value) {
            if (!$namesKeys && in_array($key, self::DATA_VALUED_KEYWORDS, true)) {
                $result[$key] = $value;
                continue;
            }
            if (!$namesKeys && $key === '$ref' && is_string($value)) {
                $value = $this->decodeRefFragment($value);
                $result[$key] = str_contains($value, '#/$defs/')
                    ? str_replace('#/$defs/', '#/components/schemas/', $value)
                    : $value;
                continue;
            }
            $result[$key] = $this->rewriteDefsRefs(
                node: $value,
                namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED_KEYWORDS, true),
            );
        }

        return $result;
    }

    /**
     * @param array<mixed> $openApi
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace, string $mode = self::ATTRIBUTE_MODE_RUNTIME): int
    {
        $this->assertNamespaceName(namespace: $namespace, source: 'namespace');
        $this->setAttributeMode($mode);
        $this->initializeGeneration(outputDirectory: $outputDirectory, namespace: $namespace, rootSpecFile: null);
        $this->readDocumentLevelFields($openApi);
        $openApi = (new SchemaResourceResolver())->resolve(document: $openApi, documentBase: SchemaResourceResolver::ARRAY_DOCUMENT_BASE);
        $this->rootOpenApiVersion = is_string($openApi['openapi'] ?? null) ? $openApi['openapi'] : null;
        $openApi = $this->withNestedAllOfWrappersPeeled($this->normalizedDocument($this->hoistComponentSubschemas($this->foldJsonSchemaDefs($openApi))));
        $openApi = $this->rewriteSchemaPointers(document: $openApi, sourceFile: null, isRootDocument: true);
        $schemas = $this->withComponentBodiesNormalized($this->extractSchemas($openApi));
        foreach ($this->extractInlineResponseSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }
        foreach ($this->extractInlineRequestSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }
        foreach ($this->extractParameterSchemas($openApi) as $name => $schema) {
            $schemas[$name] = $schema;
        }

        foreach ($schemas as $schemaName => $schemaDefinition) {
            // A component name of digits — `0`, `2024` — is legal by the specification's own key
            // pattern, and every parser hands such a key back as an INT. It reached
            // `schemaClassName(string $schemaName)` unconverted and killed the run with an uncaught
            // TypeError and a stack trace, on a document that was never wrong.
            $schemaName = (string)$schemaName;
            if (!is_array($schemaDefinition)) {
                continue;
            }

            $className = $this->schemaClassName($schemaName);
            $this->registerSchema(
                className: $className,
                schemaDefinition: $schemaDefinition,
                sourceFile: null,
                componentName: $schemaName,
            );
        }

        return $this->finalizeGeneration();
    }

    private function setAttributeMode(string $mode): void
    {
        if (!in_array($mode, self::ATTRIBUTE_MODES, true)) {
            throw new RuntimeException(sprintf(
                'Unknown generation mode: %s (expected one of: %s).',
                $mode,
                implode(', ', array_map(static fn(string $known): string => '"' . $known . '"', self::ATTRIBUTE_MODES)),
            ));
        }

        $this->attributeMode = $mode;
    }

    /**
     * Configures explicit per-external-ref placement: an output directory and a namespace for each
     * mapped ref file or directory. Keys are canonicalised (realpath) so they match the resolved ref
     * target regardless of how the `$ref` is written. When a ref file is not mapped, the default
     * placement is used.
     *
     * A `--ref` entry is either the three-part `<refFile>=<directory>=<namespace>`, which is the whole
     * mapping, or the two-part `<refFile>=<directory>`, whose namespace comes from the
     * `<refFile>=<namespace>` entry of `--ref-namespace` with the same key. The two-part form split one
     * mapping across two options matched by key, so which namespace belonged to which directory was a
     * question the reader had to answer by lining up paths; the three-part one cannot be misread that way.
     *
     * @param array<int, string> $refDirectoryPairs `<refFile>=<directory>=<namespace>` or `<refFile>=<directory>` entries
     * @param array<int, string> $refNamespacePairs `<refFile>=<namespace>` entries for the two-part form
     */
    public function setExternalRefMappings(array $refDirectoryPairs, array $refNamespacePairs): void
    {
        $twoPart = [];
        $threePart = [];
        foreach ($refDirectoryPairs as $entry) {
            if (substr_count($entry, '=') >= 2) {
                $threePart[] = $entry;
            } else {
                $twoPart[] = $entry;
            }
        }

        $directoryMap = $this->parseRefPairs($twoPart, '--ref');
        $refNamespaces = $this->parseRefPairs($refNamespacePairs, '--ref-namespace');
        $namespaceMap = $refNamespaces;

        foreach ($threePart as $entry) {
            [$file, $directory, $namespace] = $this->parseRefTriple($entry);
            if (array_key_exists($file, $directoryMap)) {
                throw new RuntimeException(sprintf('--ref maps "%s" more than once.', $file));
            }
            if (array_key_exists($file, $refNamespaces)) {
                throw new RuntimeException(message: sprintf(
                    '--ref "%s" already names its namespace; drop the --ref-namespace for "%s", or use the two-part --ref.',
                    $entry,
                    $file,
                ));
            }
            $directoryMap[$file] = $directory;
            $namespaceMap[$file] = $namespace;
        }

        foreach (array_keys($directoryMap) as $file) {
            if (!array_key_exists($file, $namespaceMap)) {
                throw new RuntimeException(sprintf('--ref for "%s" requires a matching --ref-namespace.', $file));
            }
        }
        foreach (array_keys($namespaceMap) as $file) {
            if (!array_key_exists($file, $directoryMap)) {
                throw new RuntimeException(sprintf('--ref-namespace for "%s" requires a matching --ref.', $file));
            }
        }

        foreach ([$directoryMap, $namespaceMap] as $map) {
            foreach ($map as $key => $value) {
                $this->assertRefPlaceholdersResolvable(key: $key, value: $value);
            }
        }
        foreach ($namespaceMap as $file => $namespace) {
            // A `{n}` is filled from a path segment later, and a path segment is normalized into a
            // namespace segment, so any one stands in for it here.
            $this->assertNamespaceName(
                namespace: trim((string)preg_replace('/\{\d+\}/', 'Segment', $namespace), '\\'),
                source: array_key_exists($file, $refNamespaces) ? '--ref-namespace' : '--ref',
            );
        }

        $this->refOutputDirectoryMap = $directoryMap;
        $this->refNamespaceMap = $namespaceMap;
    }

    /**
     * A `{n}` has to name a wildcard segment of its own key: on a key without one there is nothing to
     * fill it from, and past the last one it would quietly become an empty segment.
     */
    private function assertRefPlaceholdersResolvable(string $key, string $value): void
    {
        if (preg_match_all('/\{(\d+)\}/', $value, $placeholders) === 0) {
            return;
        }

        $wildcards = count(array_filter(
            array: explode(DIRECTORY_SEPARATOR, $key),
            callback: fn(string $segment): bool => $this->isRefMask($segment),
        ));
        foreach ($placeholders[1] as $number) {
            if ((int)$number < 1 || (int)$number > $wildcards) {
                throw new RuntimeException(message: sprintf(
                    '--ref "%s" uses {%s}, but its key has %d wildcard segment(s) to fill it from.',
                    $key,
                    $number,
                    $wildcards,
                ));
            }
        }
    }

    /**
     * `<refFile>=<directory>=<namespace>`: exactly three non-empty parts. A path or namespace holding an
     * `=` of its own cannot be told apart from the separator, so a fourth part is refused, not guessed.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseRefTriple(string $entry): array
    {
        $parts = array_map('trim', explode('=', $entry));
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new RuntimeException(message: sprintf(
                'Invalid --ref value "%s" (expected "<refFile>=<directory>=<namespace>" or "<refFile>=<directory>").',
                $entry,
            ));
        }

        return [$this->canonicalizeRefPath($parts[0]), $parts[1], trim($parts[2], '\\')];
    }

    /**
     * @param array<int, string> $pairs
     * @return array<string, string>
     */
    private function parseRefPairs(array $pairs, string $optionName): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            $position = strpos($pair, '=');
            if ($position === false || $position === 0) {
                throw new RuntimeException(sprintf('Invalid %s value "%s" (expected "<refFile>=<value>").', $optionName, $pair));
            }

            $file = trim(substr($pair, 0, $position));
            $value = trim(substr($pair, $position + 1));
            if ($file === '' || $value === '') {
                throw new RuntimeException(sprintf('Invalid %s value "%s" (empty file or value).', $optionName, $pair));
            }

            $key = $this->canonicalizeRefPath($file);
            // The same mapping repeated is harmless; a second, DIFFERENT one used to replace the first
            // without a word, so which directory a ref went to depended on the order of the options.
            if (array_key_exists($key, $map) && $map[$key] !== $value) {
                throw new RuntimeException(sprintf('%s maps "%s" more than once.', $optionName, $file));
            }
            $map[$key] = $value;
        }

        return $map;
    }

    private function canonicalizeRefPath(string $path): string
    {
        if (!$this->isRefMask($path)) {
            $real = realpath($path);

            return $real !== false ? $real : $path;
        }

        // A mask names no file, so realpath() cannot take it whole: the literal segments before the
        // first wildcard are resolved, the rest is kept as written.
        $segments = explode('/', str_replace('\\', '/', $path));
        $literal = [];
        foreach ($segments as $segment) {
            if ($this->isRefMask($segment)) {
                break;
            }
            $literal[] = $segment;
        }
        $prefix = implode('/', $literal);
        $realPrefix = $prefix === '' ? false : realpath($prefix);
        $tail = array_slice($segments, count($literal));

        return implode(DIRECTORY_SEPARATOR, [$realPrefix !== false ? $realPrefix : $prefix, ...$tail]);
    }

    /**
     * Whether a --ref key is a mask: `*`, `?` or a `[…]` class, matched one path segment at a time.
     */
    private function isRefMask(string $path): bool
    {
        return strpbrk($path, '*?[') !== false;
    }

    /**
     * Resolves an external $ref source file against a --ref map. A map key is a single file (exact
     * match), a directory (matches any file inside it, recursively) or a mask (a path with `*`, `?` or
     * `[…]` in it, see `matchRefMask()`). An exact file wins, then the DEEPEST containing directory, then a mask.
     *
     * The deepest directory used to be the FIRST one in option order, so `specs` and `specs/shared`
     * mapped together sent `specs/shared/x.yml` wherever the earlier option said.
     *
     * @param array<string, string> $map
     * @param string $separator what joins the segments a `**` matched: `/` in a directory, `\` in a namespace
     */
    private function matchRefMapping(string $sourceFile, array $map, string $separator): ?string
    {
        if ($map === []) {
            return null;
        }

        $canonical = $this->canonicalizeRefPath($sourceFile);

        if (array_key_exists($canonical, $map)) {
            return $map[$canonical];
        }

        // `realpath()` keeps the case it was given, so on a filesystem that folds case `../SHARED/x.yaml`
        // reaches the mapped `shared` under letters no key starts with. A key is judged by the file or
        // directory it names, by inode, the way `assertReferenceReadable()` judges the root tree: a
        // comparison of letters refused the read, and before that guard put the class below
        // `--directory`, in a namespace spelled after the reference.
        $publisher = new GeneratedFilePublisher();
        foreach ($map as $key => $value) {
            if (!$this->isRefMask($key) && is_file($key) && $this->isSameFile(path: $canonical, other: $key)) {
                return $value;
            }
        }

        $deepest = null;
        foreach ($map as $key => $value) {
            if (
                !$this->isRefMask($key)
                && is_dir($key)
                && $publisher->isWithin(path: $canonical, directory: $key)
                && ($deepest === null || strlen($key) > strlen($deepest))
            ) {
                $deepest = $key;
            }
        }
        if ($deepest !== null) {
            return $map[$deepest];
        }

        $matched = [];
        foreach ($map as $key => $value) {
            $captures = $this->isRefMask($key) ? $this->matchRefMask(mask: $key, path: $canonical) : null;
            if ($captures !== null) {
                $matched[$key] = $this->fillRefPlaceholders(value: $value, captures: $captures, separator: $separator);
            }
        }
        if (count($matched) > 1) {
            throw new RuntimeException(message: sprintf(
                '"%s" matches more than one --ref mask: %s. Map it with an exact --ref, or narrow the masks.',
                $sourceFile,
                implode(', ', array_keys($matched)),
            ));
        }

        return $matched === [] ? null : reset($matched);
    }

    /**
     * Whether two paths name one file: by device and inode, or — where the filesystem reports no
     * inode — by the path itself.
     */
    private function isSameFile(string $path, string $other): bool
    {
        $pathStat = @stat($path);
        $otherStat = @stat($other);
        if ($pathStat === false || $otherStat === false || $pathStat['ino'] === 0) {
            return $path === $other;
        }

        return $pathStat['dev'] === $otherStat['dev'] && $pathStat['ino'] === $otherStat['ino'];
    }

    /**
     * Matches a mask against a file's path one segment at a time, `fnmatch()` per segment, so `*`
     * never crosses a `/`. Like a directory key, a mask that matches the leading segments covers
     * everything below them. Returns what each wildcard segment matched, in order — what `{1}`,
     * `{2}`… stand for — or null when the mask does not match.
     *
     * @return list<list<string>>|null
     */
    private function matchRefMask(string $mask, string $path): ?array
    {
        return $this->matchMaskSegments(
            mask: explode(DIRECTORY_SEPARATOR, $mask),
            path: explode(DIRECTORY_SEPARATOR, $path),
            wholePath: false,
        );
    }

    /**
     * A `**` segment matches any number of whole segments, none included, and takes as FEW as it can:
     * a `**` between `specs` and `common`, against `specs/a/common/b/common/x.yml`, stops at the first
     * `common`. Every other
     * wildcard segment matches exactly one. Each capture is the list of segments its wildcard matched —
     * one, or for `**` any number.
     *
     * @param list<string> $mask
     * @param list<string> $path
     * @param bool $wholePath false: the mask may stop above the path's end, as a --ref key covers what is below it
     * @return list<list<string>>|null
     */
    private function matchMaskSegments(array $mask, array $path, bool $wholePath): ?array
    {
        if ($mask === []) {
            return $path === [] || !$wholePath ? [] : null;
        }

        $segment = array_shift($mask);
        if ($segment === '**') {
            for ($taken = 0; $taken <= count($path); $taken++) {
                $rest = $this->matchMaskSegments(mask: $mask, path: array_slice($path, $taken), wholePath: $wholePath);
                if ($rest !== null) {
                    return [array_slice($path, 0, $taken), ...$rest];
                }
            }

            return null;
        }

        if ($path === [] || !fnmatch($segment, $path[0])) {
            return null;
        }
        $rest = $this->matchMaskSegments(mask: $mask, path: array_slice($path, 1), wholePath: $wholePath);
        if ($rest === null) {
            return null;
        }

        return $this->isRefMask($segment) ? [[$path[0]], ...$rest] : $rest;
    }

    /**
     * `{n}` in a mask's directory or namespace becomes what the n-th wildcard segment matched, in
     * class-name case — `catalog` → `Catalog`, `order_history`… → `OrderHistory` — in both, so
     * the directory and the namespace segment are the same word and PSR-4 finds the class. A `**` that
     * matched several segments gives each its own: `Billing/Invoices` in a directory,
     * `Billing\Invoices` in a namespace; one that matched none leaves no empty segment behind.
     *
     * @param list<list<string>> $captures
     */
    private function fillRefPlaceholders(string $value, array $captures, string $separator): string
    {
        $filled = (string)preg_replace_callback(
            pattern: '/\{(\d+)\}/',
            callback: fn(array $placeholder): string => implode(
                separator: $separator,
                array: array_map($this->normalizeClassName(...), $captures[(int)$placeholder[1] - 1] ?? []),
            ),
            subject: $value,
        );
        $quoted = preg_quote($separator, '/');

        return rtrim((string)preg_replace('/(?<=.)' . $quoted . '{2,}/', $separator, $filled), $separator);
    }

    public function copyCommonServices(
        string $outputDirectory,
        string $namespace,
        ?string $dtoGeneratorDirectory = null,
        ?string $dtoGeneratorNamespace = null,
        bool $withPsr7 = false,
    ): void {
        $dtoGeneratorDirectory ??= 'Common';
        $commonDir = $this->dtoGeneratorDirectoryPath(outputDirectory: $outputDirectory, dtoGeneratorDirectory: $dtoGeneratorDirectory);

        $pendingFiles = [];

        $filesToCopy = [
            'Contract/GeneratedDtoInterface.php',
            'Contract/UnsetValue.php',
            'Contract/DtoNormalizerInterface.php',
            'Contract/DtoValidatorInterface.php',
            'Contract/DtoDeserializerInterface.php',
            'Contract/JsonShapeAwareValidatorInterface.php',
            'Service/DtoNormalizer.php',
            'Service/DtoValidator.php',
            'Service/DtoDeserializer.php',
            'Service/DtoServiceSupport.php',
        ];

        // PSR-7 deserializer is optional: only vendored when explicitly requested, so the
        // default copy stays free of the symfony/psr-http-message-bridge dependency.
        if ($withPsr7) {
            $filesToCopy[] = 'Service/DtoDeserializerPsr7.php';
        }

        $sourceBase = dirname(__DIR__);
        $targetNamespace = $this->resolveDtoGeneratorTargetNamespace(
            namespace: $namespace,
            dtoGeneratorDirectory: $dtoGeneratorDirectory,
            dtoGeneratorNamespace: $dtoGeneratorNamespace,
        );

        foreach ($filesToCopy as $relativePath) {
            $sourcePath = realpath($sourceBase . '/' . $relativePath);
            if ($sourcePath === false) {
                throw new RuntimeException(sprintf('Runtime service source not found: %s', $relativePath));
            }

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new RuntimeException(sprintf('Cannot read runtime service: %s', $sourcePath));
            }

            // Move all files to a single Common namespace, removing Contract/Service separation
            $content = preg_replace_callback(
                pattern: '/namespace OpenapiPhpDtoGenerator\\\(Contract|Service);/',
                callback: static fn(): string => 'namespace ' . $targetNamespace . ';',
                subject: $content,
            ) ?? $content;

            $content = preg_replace_callback(
                pattern: '/use OpenapiPhpDtoGenerator\\\(Contract|Service)\\\/',
                callback: static fn(): string => 'use ' . $targetNamespace . '\\',
                subject: $content,
            ) ?? $content;

            // Remove self-namespace imports (same namespace as target)
            $content = preg_replace(
                pattern: '/^use ' . preg_quote($targetNamespace, '/') . '\\\[^;]+;\n/m',
                replacement: '',
                subject: $content,
            ) ?? $content;

            $fileName = basename($relativePath);
            $pendingFiles[$commonDir . '/' . $fileName] = $content;
        }
        // The default `<output>/Common` is the generator's own and is regenerated whole. A directory
        // the user names is shared, not owned: it may hold the consumer's own classes, or be the
        // project itself (`--dto-generator-directory=.`), so each service file replaces only itself,
        // and a service a later run no longer copies stays behind.
        $this->publishGeneratedFiles(
            files: $pendingFiles,
            ownedDirectories: $dtoGeneratorDirectory === 'Common' ? [$commonDir] : [],
        );
    }

    /**
     * Where `--dto-generator-directory` puts the runtime services: an absolute path as is, the default
     * `Common` inside the output directory, any other relative path from the working directory.
     */
    private function dtoGeneratorDirectoryPath(string $outputDirectory, string $dtoGeneratorDirectory): string
    {
        if (
            str_starts_with($dtoGeneratorDirectory, '/') || (strlen(
                string: $dtoGeneratorDirectory,
            ) > 1 && $dtoGeneratorDirectory[1] === ':')
        ) {
            return rtrim($dtoGeneratorDirectory, '/');
        }
        if ($dtoGeneratorDirectory === 'Common') {
            // Special case: if default 'Common' value is used,
            // maintain backward compatibility and copy it inside $outputDirectory
            return rtrim($outputDirectory, '/') . '/Common';
        }
        $cwd = getcwd();
        $workingDirectory = $cwd !== false ? $cwd : '.';

        return rtrim($workingDirectory . '/' . ltrim($dtoGeneratorDirectory, '/'), '/');
    }

    private function initializeGeneration(string $outputDirectory, string $namespace, ?string $rootSpecFile): void
    {
        $this->pendingOutputFiles = [];
        $this->ownedOutputDirectories = [];
        $this->interpreterPropertyTypes = [];
        $this->laravelComposedTargetCache = null;
        $this->laravelDataMorphReachCache = null;
        $this->laravelNestedPathsCache = [];
        $this->laravelReachableCache = [];
        $this->dtoSchemas = [];
        $this->enumSchemas = [];
        $this->foldedClassNames = [];
        $this->foldedClassNameCount = -1;
        $this->localSchemaReferences = [];
        $this->externalSchemaReferences = [];
        $this->registeredExternalSchemas = [];
        $this->rawSchemasByClass = [];
        $this->documentSelfUri = null;
        $this->generationWarnings = [];
        $this->requestPayloadClasses = [];
        $this->requestBodyMediaTypes = [];
        $this->referencedRequestBodies = [];
        $this->valueRequestBodies = [];
        $this->requestBodyComponents = [];
        $this->parameterPayloadClasses = [];
        $this->parentClasses = [];
        $this->unionInterfacesByClass = [];
        $this->unionMembersByBranch = [];
        $this->discriminatorSchemas = [];
        $this->componentNames = [];
        $this->pendingAllOfDiscriminators = [];
        $this->schemaSourceFiles = [];
        $this->schemaNamespaces = [];
        $this->schemaOutputDirectories = [];
        $this->enumSourceFiles = [];
        $this->enumNamespaces = [];
        $this->enumOutputDirectories = [];
        $this->externalDocSchemas = [];
        $this->externalDocuments = [];
        $this->pointedSchemaComponents = [];
        $this->localPointedSchemas = [];
        $this->endpointByClass = [];
        $this->deprecatedByClass = [];
        $this->relatedByClass = [];
        $this->canonicalSourceFiles = [];
        $this->pureAliasClasses = [];
        $this->pureAliasRedirects = [];
        $this->referencesNormalized = false;
        $this->rootSpecFile = $rootSpecFile === null ? null : $this->canonicalSourceFile($rootSpecFile);
        $this->baseOutputDirectory = $outputDirectory;
        $this->baseNamespace = $namespace;
    }

    private function resolveDtoGeneratorTargetNamespace(
        string $namespace,
        string $dtoGeneratorDirectory,
        ?string $dtoGeneratorNamespace,
    ): string {
        if (is_string($dtoGeneratorNamespace) && trim($dtoGeneratorNamespace) !== '') {
            return trim($dtoGeneratorNamespace, '\\');
        }

        // Keep BC for copyCommonServices direct calls.
        return rtrim($namespace, '\\') . '\\' . $this->directoryToNamespace($dtoGeneratorDirectory);
    }

    /**
     * A property that is a `$ref` to the null schema gets that schema inline.
     *
     * Such a component materializes nothing and no framework rule carries `type: null`, so only the
     * interpreter checks it — and the interpreter reads the extracted constraints, where a bare
     * `$ref` extracts to nothing. Resolved against the OWNER's file, which is what makes a reference
     * from an external document land on the right one.
     */
    private function inlineNullSchemaReferences(): void
    {
        foreach ($this->dtoSchemas as $className => $schema) {
            $this->dtoSchemas[$className] = $this->withNormalizedReferences($schema, $this->getSchemaSourceFile($className));
        }
        // From here on every registration is normalized on the way in — see `registerSchema()`.
        $this->referencesNormalized = true;
    }

    /**
     * The schema with each property's reference normalized: a nullable-reference union in a
     * container, the `nullable` of a referenced 3.0 component, a reference to the null schema
     * inlined. Idempotent — applied to its own result it changes nothing — which is what lets
     * `registerSchema()` compare a re-registration against the stored, already normalized copy.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withNormalizedReferences(array $schema, ?string $sourceFile): array
    {
        // A reader that takes no file — `arrayAliasAllowsNull()` — resolves a reference into another
        // document against the one being read, so the schema's own document is the one in effect.
        $sourceFileBefore = $this->constraintSourceFile;
        $this->constraintSourceFile = $sourceFile ?? $sourceFileBefore;
        try {
            return $this->withNormalizedMemberProperties(
                schema: $this->symfonyArrayComponentUnionItemsUnwrapped($schema),
                sourceFile: $sourceFile,
            );
        } finally {
            $this->constraintSourceFile = $sourceFileBefore;
        }
    }

    /**
     * The schema with its own properties normalized (`normalizedPropertySchema()`), and those of each
     * inline `allOf` branch, nested `allOf` included. A branch's properties are merged into the class
     * as written (`classSchemaWithInlineAllOfMerged()`), so a `$ref` there to a component admitting
     * null lost the null the same property outside the `allOf` got. A `$ref` branch is left alone:
     * it is a component, normalized as one.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withNormalizedMemberProperties(array $schema, ?string $sourceFile): array
    {
        if (is_array($schema['properties'] ?? null)) {
            $properties = [];
            foreach ($schema['properties'] as $name => $propertySchema) {
                $properties[$name] = is_array($propertySchema)
                    ? $this->normalizedPropertySchema(propertySchema: $propertySchema, sourceFile: $sourceFile)
                    : $propertySchema;
            }
            $schema['properties'] = $properties;
        }
        if (is_array($schema['allOf'] ?? null)) {
            $branches = [];
            foreach ($schema['allOf'] as $index => $branch) {
                $branches[$index] = is_array($branch) && !array_key_exists('$ref', $branch)
                    ? $this->withNormalizedMemberProperties(schema: $branch, sourceFile: $sourceFile)
                    : $branch;
            }
            $schema['allOf'] = $branches;
        }

        return $schema;
    }

    /**
     * One property's schema with its reference normalized — see `withNormalizedReferences()`.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function normalizedPropertySchema(array $propertySchema, ?string $sourceFile): array
    {
        $propertySchema = $this->canonicalNullableRefContainers($propertySchema);
        $propertySchema = $this->nullableScalarAliasUnion(schema: $propertySchema, sourceFile: $sourceFile);
        $sourceFileBefore = $this->constraintSourceFile;
        $this->constraintSourceFile = $sourceFile ?? $sourceFileBefore;
        try {
            $propertySchema = $this->objectReferenceWithItsSiblings($propertySchema);
        } finally {
            $this->constraintSourceFile = $sourceFileBefore;
        }
        $propertySchema = $this->withNullableAllOfWrappedUnionItemsFolded($propertySchema);
        $ref = $propertySchema['$ref'] ?? null;
        // A `$ref` to a component that is itself `nullable: true` (3.0) admits null like the
        // component does; the flag lived on the target, where the property never looked.
        // A union component with a null branch, `oneOf: [A, B, {type: 'null'}]`, is the 3.1 way of
        // writing the same thing.
        // `allOf: [$ref Olive]` is Olive, its null included.
        $wrappedRef = $ref ?? $this->referenceOrSingleRefAllOf($propertySchema);
        if (
            is_string($wrappedRef)
            && !array_key_exists('nullable', $propertySchema)
            && $this->componentAdmitsNull($this->localComponentDefinition($wrappedRef) ?? [])
        ) {
            $propertySchema['nullable'] = true;
        }
        // The same for an array alias reached through other components or spelled
        // `type: [array, "null"]`: it is typed as the list, so its null has to arrive here.
        if (!array_key_exists('nullable', $propertySchema) && $this->arrayAliasAllowsNull($ref)) {
            $propertySchema['nullable'] = true;
        }
        if (!array_key_exists('nullable', $propertySchema) && $this->unionHasNullAdmittingRefBranch($propertySchema)) {
            $propertySchema['nullable'] = true;
        }
        $alias = is_string($ref) ? $this->scalarAliasDefinition($ref, $sourceFile) : null;
        if ($alias === null || !in_array($alias['type'] ?? null, ['null', ['null']], true)) {
            return $propertySchema;
        }
        $inlined = [];
        foreach ($propertySchema as $keyword => $value) {
            if ($keyword !== '$ref') {
                $inlined[$keyword] = $value;
            }
        }

        return $inlined + $alias;
    }

    /**
     * A `oneOf` / `anyOf` with a bare `$ref` branch to a component that admits null —
     * `oneOf: [{$ref: Saffron}, {type: integer}]` with `Saffron: {type: array, nullable: true}`, or
     * `anyOf: [{$ref: Mauve}, {type: string}]` with `Mauve: {type: object, nullable: true}` (3.1:
     * `type: [object, "null"]`). The branch admits null, so the null has to reach the property the
     * same way it does for a property that IS that reference; left on the target, runtime refused the
     * null at deserialization, symfony refused it at deserialization or in its not-null callback, yii3
     * in its type rule, laravel in its union rule, and laravel-data died on the constructor.
     *
     * @param array<string, mixed> $schema
     */
    private function unionHasNullAdmittingRefBranch(array $schema): bool
    {
        if (array_key_exists('$ref', $schema)) {
            return false;
        }
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            foreach (is_array($schema[$unionKey] ?? null) ? $schema[$unionKey] : [] as $branch) {
                if (!$this->isAnnotatedReference($branch)) {
                    continue;
                }
                if ($this->arrayAliasAllowsNull($branch['$ref']) || $this->componentAdmitsNull($this->localComponentDefinition($branch['$ref']) ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A `$ref` whose siblings annotate it or bound the value it names — `enum`, `const`, a bound of a
     * number or a string — and give it no shape of its own: whatever the target is, the branch is.
     *
     * @param array<mixed> $branch
     */
    private function isValueBoundReference(array $branch): bool
    {
        foreach (array_keys($branch) as $keyword) {
            if (
                $keyword === '$ref'
                || $keyword === 'enum'
                || $keyword === 'const'
                || in_array($keyword, self::SCALAR_BOUND_KEYWORDS, true)
                || in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                || str_starts_with((string)$keyword, 'x-')
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * A `$ref` member whose siblings only annotate it — `{$ref: Olive, title: Fern}`, a `description`,
     * an `example`, `readOnly`, an `x-` extension — is that reference: the 3.0 siblings of a `$ref`
     * are ignored, and in 3.1 these assert nothing. Read as the bare `{$ref: Olive}`, it passes the
     * null its target admits on to the union. A sibling that asserts (3.1 `{$ref: Olive, type:
     * object}`) keeps the member out.
     *
     * @phpstan-assert-if-true array{'$ref': string, ...} $branch
     */
    private function isAnnotatedReference(mixed $branch): bool
    {
        if (!is_array($branch) || !is_string($branch['$ref'] ?? null)) {
            return false;
        }
        foreach (array_keys($branch) as $keyword) {
            if (
                $keyword === '$ref'
                || in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                || str_starts_with((string)$keyword, 'x-')
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Whether the component a `$ref` names admits null, following a chain of bare aliases
     * (`Azure: {$ref: Crate}`) to the component that says so.
     *
     * @param list<string> $visited
     */
    private function referenceAdmitsNull(string $ref, array $visited = []): bool
    {
        if (in_array($ref, $visited, true)) {
            return false;
        }
        if ($this->arrayAliasAllowsNull($ref)) {
            return true;
        }
        $definition = $this->localComponentDefinition($ref) ?? [];
        if ($this->componentAdmitsNull(definition: $definition, visited: [...$visited, $ref])) {
            return true;
        }
        $next = $definition['$ref'] ?? null;

        return is_string($next) && $this->referenceAdmitsNull(ref: $next, visited: [...$visited, $ref]);
    }

    /**
     * Symfony: an ARRAY COMPONENT whose items are `{allOf: [{$ref: U}]}` over a UNION component U — `Olive:
     * {type: array, items: {allOf: [$ref Scarlet]}}` — at any depth of lists, as the list of `{$ref: U}`,
     * which a property's own items already are (`withAllOfWrappedReferenceItemsUnwrapped()`). Left
     * wrapped, a `$ref` to Olive was typed a bare list with no item class and no item checks: `["zzq"]`
     * and an item that broke U passed. An object class keeps its wrapper, as on a property; the other
     * modes judge the wrapped items already.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function symfonyArrayComponentUnionItemsUnwrapped(array $schema): array
    {
        $items = $schema['items'] ?? null;
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_SYMFONY || !is_array($items)) {
            return $schema;
        }
        $unwrapped = $this->allOfWrappedReference($items);
        $target = is_string($unwrapped['$ref'] ?? null) ? $this->localComponentDefinition($unwrapped['$ref']) : null;
        if (
            $unwrapped !== null
            && is_array($target)
            && !array_key_exists('properties', $target)
            && (is_array($target['oneOf'] ?? null) || is_array($target['anyOf'] ?? null))
        ) {
            $items = $unwrapped;
        }
        /** @var array<string, mixed> $items */
        $schema['items'] = $this->symfonyArrayComponentUnionItemsUnwrapped($items);

        return $schema;
    }

    /**
     * A list whose items are `{allOf: [{$ref: U}], nullable: true}` over a UNION component U, at any depth
     * of lists, as the list of `{$ref: U, nullable: true}`. Left wrapped, symfony held the list as a bare
     * list and an item that broke U passed. An object class keeps its wrapper: there it already worked,
     * and laravel-data takes the bare nullable reference as a `#[DataCollectionOf]`, which refuses a
     * `null` item (README.support-matrix).
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function withNullableAllOfWrappedUnionItemsFolded(array $schema): array
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return $schema;
        }
        $folded = $this->nullableAllOfWrappedReference($items);
        $target = is_string($folded['$ref'] ?? null) ? $this->localComponentDefinition($folded['$ref']) : null;
        if (
            $folded !== null
            && is_array($target)
            && !array_key_exists('properties', $target)
            && (is_array($target['oneOf'] ?? null) || is_array($target['anyOf'] ?? null))
        ) {
            $items = $folded;
        }
        $schema['items'] = $this->withNullableAllOfWrappedUnionItemsFolded($items);

        return $schema;
    }

    /**
     * The `#/components/schemas/X` pointer a reference names its component by: a local one as written,
     * one into another document's components as the pointer its class is registered under
     * (`registeredComponentPointer()`); null when it names no component.
     */
    private function componentPointer(string $ref): ?string
    {
        if (str_starts_with($ref, '#/components/schemas/')) {
            return $ref;
        }
        if (!str_contains($ref, '#/components/schemas/')) {
            return null;
        }

        return $this->registeredComponentPointer($ref);
    }

    /**
     * The schema a `#/components/schemas/X` reference names, as the document wrote it — a reference
     * into another document's components read by its registered pointer (`componentPointer()`).
     *
     * @return array<string, mixed>|null
     */
    private function localComponentDefinition(string $ref): ?array
    {
        $prefix = '#/components/schemas/';
        $ref = $this->componentPointer($ref);
        if ($ref === null) {
            return null;
        }
        $className = $this->schemaClassName(substr($ref, strlen($prefix)));
        // The RAW schema: an enum component's registered form keeps its members, not its `nullable`.
        $definition = $this->rawSchemasByClass[$className] ?? $this->dtoSchemas[$className] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Inside a container, `anyOf` / `oneOf` of a `$ref` and `{type: null}` is the nullable reference
     * — the same schema as `{$ref: X, nullable: true}`. Only that spelling was typed, so the union
     * one left the items `mixed` and they stayed the decoded `stdClass`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function canonicalNullableRefContainers(array $schema): array
    {
        foreach (['items', 'additionalProperties'] as $key) {
            $value = $schema[$key] ?? null;
            if (!is_array($value)) {
                continue;
            }
            // Beside properties of its own the object is a class, not a map: its `additionalProperties`
            // are the class's extra keys, and the class stored under its own name keeps them as written.
            // Rewritten here only, the owner's copy of the inline object no longer equalled the stored
            // one — a name collision that stopped generation in every mode.
            if ($key === 'additionalProperties' && !$this->isMapLikeObjectSchema($schema)) {
                $schema[$key] = $this->canonicalNullableRefContainers($value);
                continue;
            }
            foreach (['anyOf', 'oneOf'] as $unionKey) {
                $branches = $value[$unionKey] ?? null;
                if (!is_array($branches) || count($branches) !== 2) {
                    continue;
                }
                $refs = array_values(array_filter($branches, fn(mixed $b): bool => $this->isAnnotatedReference($b)));
                $nulls = array_values(array_filter($branches, static fn(mixed $b): bool => is_array($b) && ($b['type'] ?? null) === 'null' && count($b) === 1));
                if (count($refs) === 1 && count($nulls) === 1) {
                    $rest = [];
                    foreach ($value as $keyword => $member) {
                        if ($keyword !== $unionKey) {
                            $rest[$keyword] = $member;
                        }
                    }
                    $value = ['$ref' => $refs[0]['$ref'], 'nullable' => true] + $rest;
                    break;
                }
            }
            // A bare `$ref` item or value to a component that admits null — `nullable: true` on it,
            // `null` in its 3.1 `type`, a null branch, or any of those at the end of an alias chain —
            // admits null as the item, the way the same reference as a whole property already did.
            // Left on the target, the list was typed `array<Crate>` and every null item refused.
            $valueRef = $value['$ref'] ?? null;
            if (is_string($valueRef) && !array_key_exists('nullable', $value) && $this->referenceAdmitsNull($valueRef)) {
                $value['nullable'] = true;
            }
            $schema[$key] = $this->canonicalNullableRefContainers($value);
        }

        return $schema;
    }

    /**
     * A component `allOf` member that is a union of objects — `{$ref: Plum}` where Plum is `oneOf: [Cat,
     * Dog]`, or an inline `{oneOf: […]}` — becomes that union written BESIDE the `allOf`: the object as a
     * whole must be one of the branches, and the class is built from the other members. Every mode
     * already reads a union there (`classLevelUnionsBesideAllOf()`).
     *
     * Left inside, the `$ref` was the parent class: runtime wrote `final class Sepia extends Plum`, a
     * class extending an INTERFACE, which PHP refuses at load — every request touching it a 500; laravel
     * and laravel-data merged the members into a class of the sibling's keys alone, so a payload of no
     * branch, or of both, was accepted and a branch's key dropped.
     *
     * Hoisted only where nothing is lost: one union per keyword, the keyword not already written beside
     * the `allOf`, every branch an object; a union from another file only when its branches are bare
     * references, which are rebased onto the class name. Anything else stays a member — and is still
     * never extended (`refClassNamesToExtend()`).
     */
    private function hoistUnionMembersOfComponentAllOf(): void
    {
        foreach ($this->dtoSchemas as $className => $schema) {
            $allOf = $schema['allOf'] ?? null;
            if (!is_array($allOf) || !array_is_list($allOf)) {
                continue;
            }
            $kept = [];
            $hoisted = [];
            foreach ($allOf as $member) {
                $union = is_array($member) ? $this->allOfMemberUnion(member: $member, className: $className) : null;
                if ($union === null || array_key_exists($union['keyword'], $schema) || array_key_exists($union['keyword'], $hoisted)) {
                    $kept[] = $member;

                    continue;
                }
                $hoisted[$union['keyword']] = $union['branches'];
                if ($union['remainder'] !== []) {
                    $kept[] = $union['remainder'];
                }
            }
            if ($hoisted === []) {
                continue;
            }
            // An `allOf` left with no member still makes the schema a class (`rendersAsUnion()`), and the
            // union beside it judges an object.
            $schema['allOf'] = $kept === [] ? [['type' => 'object']] : $kept;
            $this->dtoSchemas[$className] = [...$schema, ...$hoisted];
        }
    }

    /**
     * The union an `allOf` member stands for — see `hoistUnionMembersOfComponentAllOf()` — with what is
     * left of an inline member once its union is taken out (its discriminator goes with the union).
     * Null when the member is no union of objects that can move.
     *
     * @param array<mixed> $member
     * @return array{keyword: string, branches: list<mixed>, remainder: array<mixed>}|null
     */
    private function allOfMemberUnion(array $member, string $className): ?array
    {
        $ref = $member['$ref'] ?? null;
        if (is_string($ref)) {
            if (array_diff(array_keys($member), ['$ref', 'description', 'title']) !== []) {
                return null;
            }
            $targetClass = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className));
            $target = $this->dtoSchemas[$targetClass] ?? null;
            if (!is_array($target) || !$this->rendersAsUnion($target) || $this->isScalarAliasSchema($target)) {
                return null;
            }
            $union = $this->allOfMemberUnion(member: $target, className: $targetClass);
            // The component is the union and nothing else: keywords of its own would be lost on the move.
            if ($union === null) {
                return null;
            }
            foreach (array_keys($union['remainder']) as $keyword) {
                if (!in_array($keyword, ['type', 'example', 'examples', 'externalDocs', 'deprecated'], true) && !str_starts_with((string)$keyword, 'x-')) {
                    return null;
                }
            }
            if ($this->getSchemaSourceFile($targetClass) === $this->getSchemaSourceFile($className)) {
                return [...$union, 'remainder' => []];
            }
            $rebased = [];
            foreach ($union['branches'] as $branch) {
                $branchRef = is_array($branch) ? $branch['$ref'] ?? null : null;
                if (!is_string($branchRef) || count($branch) !== 1) {
                    return null;
                }
                $rebased[] = ['$ref' => '#/components/schemas/' . $this->schemaRefToClassName(
                    ref: $branchRef,
                    currentSourceFile: $this->getSchemaSourceFile($targetClass),
                )];
            }

            return ['keyword' => $union['keyword'], 'branches' => $rebased, 'remainder' => []];
        }

        /** @var array<string, mixed> $member */
        if (!$this->rendersAsUnion($member)) {
            return null;
        }
        $keywords = array_values(array_filter(
            array: ['oneOf', 'anyOf'],
            callback: static fn(string $keyword): bool => is_array($member[$keyword] ?? null),
        ));
        if (count($keywords) !== 1 || !array_is_list($member[$keywords[0]]) || $member[$keywords[0]] === []) {
            return null;
        }
        foreach ($member[$keywords[0]] as $branch) {
            if (!is_array($branch) || !$this->shapesAnObject($branch)) {
                return null;
            }
        }
        $remainder = [];
        foreach ($member as $keyword => $value) {
            if (!in_array($keyword, [$keywords[0], 'discriminator', 'description', 'title'], true)) {
                $remainder[$keyword] = $value;
            }
        }

        return ['keyword' => $keywords[0], 'branches' => $member[$keywords[0]], 'remainder' => $remainder];
    }

    /**
     * `objectReferenceWithItsSiblings()` over every component's properties, BEFORE the inline objects
     * are expanded into classes of their own: the `allOf` it writes is one of them.
     */
    private function mergeObjectReferenceSiblings(): void
    {
        foreach ($this->dtoSchemas as $className => $schema) {
            // Read against the document that declares the class: a reference into a third document is
            // relative to it, not to the root one (`objectReferenceWithItsSiblings()` resolves it).
            $this->dtoSchemas[$className] = $this->withConstraintSourceOf(
                className: $className,
                extraction: fn(): array => $this->componentWithObjectReferenceSiblings($schema),
            );
        }
    }

    /**
     * One component with `objectReferenceWithItsSiblings()` applied — see `mergeObjectReferenceSiblings()`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function componentWithObjectReferenceSiblings(array $schema): array
    {
        $schema = $this->propertiesWithObjectReferenceSiblings($schema, depth: 0);
        // A union component's branches are value positions too: `Mix: {oneOf: [{type: string},
        // {$ref: Base, properties: {hue: …}}]}` typed the second branch as the bare `Base`, and the
        // `hue` its `required` insists on was dropped on the way in — every valid object refused.
        // An `allOf` fragment's properties are the component's own: `Beige: {allOf: [{properties:
        // {raven: {$ref: Ochre, properties: {puffin: …}}}}]}` typed `raven` as the bare `Ochre`.
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches) || !array_is_list($branches)) {
                continue;
            }
            $rewritten = [];
            foreach ($branches as $branch) {
                $rewritten[] = is_array($branch) ? $this->objectReferencesWithTheirSiblings($branch) : $branch;
            }
            $schema[$keyword] = $rewritten;
        }
        if (is_array($schema['allOf'] ?? null) && array_is_list($schema['allOf'])) {
            $schema['allOf'] = $this->allOfFragmentsWithObjectReferenceSiblings($schema['allOf'], depth: 0);
        }
        // So are the items of an array alias and the values of a map component: `Ring: {type:
        // array, items: {$ref: Circle, properties: {tail: …}}}` kept the bare `Circle` as its item,
        // where the same list written inline on a property judged both sides — `tail` went
        // unchecked and was dropped on the way through, and as a union branch the alias kept the
        // siblings only, so the target's own properties went unchecked instead.
        if ($this->isArrayAliasSchema($schema) && is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->objectReferencesWithTheirSiblings($schema['items'], depth: 1);
        }
        if ($this->isMapLikeObjectSchema($schema) && is_array($schema['additionalProperties'] ?? null)) {
            $schema['additionalProperties'] = $this->objectReferencesWithTheirSiblings($schema['additionalProperties'], depth: 1);
        }
        return $schema;
    }

    /**
     * `objectReferencesWithTheirSiblings()` over every property of a schema.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function propertiesWithObjectReferenceSiblings(array $schema, int $depth): array
    {
        if (!is_array($schema['properties'] ?? null) || array_is_list($schema['properties'])) {
            return $schema;
        }
        $properties = [];
        foreach ($schema['properties'] as $name => $propertySchema) {
            $properties[$name] = is_array($propertySchema)
                ? $this->objectReferencesWithTheirSiblings($propertySchema, $depth)
                : $propertySchema;
        }
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * `objectReferenceWithItsSiblings()` at the property and at every place inside it that holds a
     * schema of its own value: the items of a list, the values of a map, a branch of a union. Applied
     * at the property alone, `items: {$ref: Inner, properties: {foo: …}}` kept the bare `Inner`: a
     * wrong `foo` passed, a valid one was dropped on the way through, and a `required: [foo]` beside
     * it refused every map value, judged against the `Inner` that had dropped `foo`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function objectReferencesWithTheirSiblings(array $schema, int $depth = 0): array
    {
        $schema = $this->objectReferenceWithItsSiblings($schema);
        if ($depth > 32) {
            return $schema;
        }
        // The properties of an inline object one level down are properties all the same: below
        // `box: {type: object, properties: {owl: {$ref: Base, properties: {bee: …}}}}` the owl was
        // typed by a merged class that was never registered, so never written.
        /** @var array<string, mixed> $schema */
        $schema = $this->propertiesWithObjectReferenceSiblings($schema, $depth + 1);
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = $this->objectReferencesWithTheirSiblings($schema[$keyword], $depth + 1);
            }
        }
        // An `allOf` fragment's properties merge into the object it builds: left out, a property
        // declared in a fragment kept the bare `$ref` while the same property declared directly got
        // the merged class, and the keys beside the `$ref` were dropped and checked nowhere.
        foreach (['prefixItems', 'anyOf', 'oneOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches) || !array_is_list($branches)) {
                continue;
            }
            $rewritten = [];
            foreach ($branches as $branch) {
                $rewritten[] = is_array($branch) ? $this->objectReferencesWithTheirSiblings($branch, $depth + 1) : $branch;
            }
            $schema[$keyword] = $rewritten;
        }
        if (is_array($schema['allOf'] ?? null) && array_is_list($schema['allOf'])) {
            $schema['allOf'] = $this->allOfFragmentsWithObjectReferenceSiblings($schema['allOf'], $depth + 1);
        }

        return $schema;
    }

    /**
     * `objectReferencesWithTheirSiblings()` over the fragments of an `allOf`: a fragment's properties
     * are the properties of the object the `allOf` builds. Left out, `allOf: [{properties: {raven:
     * {$ref: Ochre, properties: {puffin: …}}}}]` typed `raven` as the bare `Ochre` — `puffin` was
     * dropped on the way in and checked nowhere — where the same property declared directly got the
     * merged class. A fragment that is itself `{$ref: Ochre, properties: {puffin: …}}` becomes the two
     * fragments `{$ref: Ochre}` and `{properties: {puffin: …}}` side by side: nested one `allOf`
     * deeper, both the target's keys and the ones beside it were dropped.
     *
     * @param list<mixed> $fragments
     * @return list<mixed>
     */
    private function allOfFragmentsWithObjectReferenceSiblings(array $fragments, int $depth): array
    {
        $rewritten = [];
        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) {
                $rewritten[] = $fragment;
                continue;
            }
            $wasReference = array_key_exists('$ref', $fragment);
            $fragment = $this->objectReferencesWithTheirSiblings($fragment, $depth);
            $inner = $fragment['allOf'] ?? null;
            // Spliced only when what stays on top beside the new `allOf` is pure annotation: an
            // `unevaluated*` has to see both halves together, and a `nullable` or `default` means
            // something to the fragment as a whole — those keep their own scope.
            $annotations = ['allOf', 'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', '$comment'];
            $onTop = array_diff(array_keys($fragment), $annotations);
            if ($wasReference && $onTop === [] && is_array($inner) && array_is_list($inner)) {
                foreach ($inner as $innerFragment) {
                    $rewritten[] = $innerFragment;
                }
                continue;
            }
            // An inline fragment that is an `allOf` itself — `{type: object, allOf: [{properties: {kiwi}}]}`
            // — is its keywords and its fragments side by side, one level up: every reader of a class's
            // `allOf` looks at its fragments' own `properties` and `required`, so a nested one built a
            // class with no properties that took any object. The fragments below were spliced already
            // (the recursion above runs first), so any depth arrives flat. `unevaluated*` reads what the
            // fragments evaluated together and keeps its scope, as above.
            if (
                !$wasReference
                && !array_key_exists('$ref', $fragment)
                && is_array($inner)
                && array_is_list($inner)
                && !array_key_exists('unevaluatedProperties', $fragment)
                && !array_key_exists('unevaluatedItems', $fragment)
            ) {
                $own = [];
                foreach ($fragment as $keyword => $value) {
                    if ($keyword !== 'allOf') {
                        $own[$keyword] = $value;
                    }
                }
                if ($onTop !== []) {
                    $rewritten[] = $own;
                }
                foreach ($inner as $innerFragment) {
                    $rewritten[] = $innerFragment;
                }
                continue;
            }
            $rewritten[] = $fragment;
        }

        return $rewritten;
    }

    /**
     * A `$ref` to an object class with keywords beside it that describe more keys — `{$ref: Inner,
     * properties: {foo: …}}` — is both (JSON Schema 2020-12, OpenAPI 3.1): the class it names, and the
     * keys beside it. The property was typed by the named class alone and the keys beside it were
     * dropped, unchecked and unread. Written as `allOf: [{$ref}, {the keys}]`, it becomes the class
     * an `allOf` makes. An `unevaluated*` beside a `$ref` is handled where it is checked, and the
     * annotations stay where they are.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function objectReferenceWithItsSiblings(array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        $prefix = '#/components/schemas/';
        if (!is_string($ref) || !str_contains($ref, $prefix)) {
            return $schema;
        }
        $keys = ['properties', 'patternProperties', 'additionalProperties', 'propertyNames', 'required', 'dependentRequired', 'minProperties', 'maxProperties'];
        if (array_intersect_key($schema, array_flip($keys)) === []) {
            return $schema;
        }
        // A reference into ANOTHER document's components names the class that document's schema is
        // registered as (`componentPointer()`). Only the local spelling was merged, so `{$ref:
        // other.json#/components/schemas/Fox, properties: {egret: …}}` was typed the bare `Fox` and
        // symfony and yii3 never checked `egret`. The `allOf` keeps the reference as written: it is
        // read against the same document as the schema around it.
        $pointer = $this->componentPointer($ref);
        if ($pointer === null) {
            return $schema;
        }
        $targetClass = $this->schemaClassName(substr($pointer, strlen($prefix)));
        if (!$this->isObjectDtoComponent($targetClass)) {
            return $schema;
        }
        // A target with an `unevaluated*` of its own judges its own keys only — the `$ref` is a new
        // scope — and a class extending it would widen that scope to the keys beside: left as it was.
        $target = $this->dtoSchemas[$targetClass] ?? [];
        if (array_key_exists('unevaluatedProperties', $target) || array_key_exists('unevaluatedItems', $target)) {
            return $schema;
        }
        if ($this->mapReferenceNarrowedByKeyCount($schema, $target)) {
            return $schema;
        }

        $kept = [];
        $beside = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword === '$ref' || $keyword === '$defs') {
                continue;
            }
            // On top, where an `unevaluated*` sees what BOTH branches evaluate.
            if (
                in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                || in_array($keyword, ['unevaluatedProperties', 'unevaluatedItems'], true)
            ) {
                $kept[$keyword] = $value;
                continue;
            }
            $beside[$keyword] = $value;
        }

        return $kept + ['allOf' => [['$ref' => $ref], ['type' => 'object'] + $beside]];
    }

    /**
     * `{$ref: Weights, maxProperties: 1}` where `Weights` is a map (`additionalProperties: {type: number}`):
     * the keywords beside the reference only count or name keys the map already admits, so the property
     * stays the map — `inlineFreeFormObjectRef()` lays them over it. Made an `allOf` class, the property
     * became an empty DTO: the map's values went unchecked, `null` and too many keys passed in Symfony,
     * and nested one level down the class was never written at all.
     *
     * Only keywords the map does not carry itself: one of its own beside the reference must hold
     * TOGETHER with it, which overlaying would not do.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $target
     */
    private function mapReferenceNarrowedByKeyCount(array $schema, array $target): bool
    {
        if (!$this->isFreeFormObjectSchema($target)) {
            return false;
        }
        foreach (array_keys($schema) as $keyword) {
            if ($keyword === '$ref' || in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true) || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)) {
                continue;
            }
            if (!in_array($keyword, self::MAP_KEY_COUNT_KEYWORDS, true) || array_key_exists($keyword, $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `anyOf` / `oneOf` of a scalar alias and `{type: null}` — the OpenAPI 3.1 spelling of "this alias,
     * or null" — rewritten to `{$ref: Alias, nullable: true}`, the reference every reader below knows.
     * Left a union, a property was typed by the alias's class, which is never written (a value has no
     * class), and a component became an empty union interface: the runtime refused every string with
     * `Unsupported type`, laravel could not hydrate it, and laravel-data died on the constructor.
     * A union around an OBJECT is left alone: it is typed `?Obj` already.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function nullableScalarAliasUnion(array $schema, ?string $sourceFile): array
    {
        if (array_key_exists('$ref', $schema)) {
            return $schema;
        }
        foreach (['anyOf', 'oneOf'] as $unionKey) {
            $branches = $schema[$unionKey] ?? null;
            if (!is_array($branches) || count($branches) !== 2) {
                continue;
            }
            $refs = array_values(array_filter($branches, fn(mixed $b): bool => $this->isAnnotatedReference($b)));
            $nulls = array_values(array_filter($branches, static fn(mixed $b): bool => is_array($b) && array_keys($b) === ['type'] && $b['type'] === 'null'));
            if (count($refs) !== 1 || count($nulls) !== 1) {
                continue;
            }
            $ref = $refs[0]['$ref'];
            if ($this->scalarAliasDefinition($ref, $sourceFile) === null) {
                continue;
            }
            $rest = [];
            foreach ($schema as $keyword => $value) {
                if ($keyword !== $unionKey) {
                    $rest[$keyword] = $value;
                }
            }

            return ['$ref' => $ref, 'nullable' => true] + $rest;
        }

        return $schema;
    }

    /**
     * Keywords that describe a schema without constraining it, so a component carrying only these
     * next to its `$ref` is still nothing but that reference.
     */
    private const array ALIAS_ANNOTATION_KEYWORDS = [
        'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', 'nullable',
    ];

    /**
     * A component that only names another one — `X: {$ref: Y}` — is the schema it names, and every
     * reference to it is redirected there before anything is analyzed.
     *
     * Left alone it was materialized as an EMPTY class and properties were typed with it: a scalar or
     * enum alias refused every valid value in four modes while Symfony accepted anything, an object
     * alias validated nothing, and yii3 died calling a method the empty class did not have.
     *
     * `allOf: [{$ref: Y}]` is redirected the same way when Y is not an object DTO (a scalar, an enum,
     * an array alias or a map). Around an OBJECT that spelling is inheritance — the class extends Y — and that
     * has always worked, so it stays.
     *
     * A `nullable: true` on the alias is carried onto each reference it replaces.
     */
    private function redirectPureAliasReferences(): void
    {
        $prefix = '#/components/schemas/';
        $targets = [];
        foreach (array_keys($this->rawSchemasByClass + $this->dtoSchemas) as $className) {
            $definition = $this->rawSchemasByClass[$className] ?? $this->dtoSchemas[$className] ?? null;
            if (!is_array($definition)) {
                continue;
            }
            $definition = $this->nullableScalarAliasUnion(
                schema: $definition,
                sourceFile: $this->getSchemaSourceFile($className),
            );
            $target = $this->pureAliasTarget($definition);
            if ($target !== null && !str_starts_with($target, $prefix)) {
                // An alias of another document's schema is an alias of the class that schema is
                // registered as, read by its local pointer.
                $target = $this->registeredComponentPointer(ref: $target, sourceFile: $this->getSchemaSourceFile($className));
            }
            if ($target !== null && str_starts_with($target, $prefix)) {
                $targets[$className] = [
                    'ref' => $target,
                    'nullable' => ($definition['nullable'] ?? null) === true,
                    'allOf' => array_key_exists('allOf', $definition),
                ];
            }
        }

        // Follow chains to the end, and drop an `allOf` alias whose end is an object DTO.
        $resolved = [];
        foreach ($targets as $className => $alias) {
            $ref = $alias['ref'];
            $nullable = $alias['nullable'];
            $seen = [$className => true];
            while (true) {
                $next = $this->schemaClassName(substr($ref, strlen($prefix)));
                if (!array_key_exists($next, $targets) || array_key_exists($next, $seen)) {
                    break;
                }
                $seen[$next] = true;
                $nullable = $nullable || $targets[$next]['nullable'];
                $ref = $targets[$next]['ref'];
            }
            $endClass = $this->schemaClassName(substr($ref, strlen($prefix)));
            if (array_key_exists($endClass, $seen) || $this->localComponentDefinition($ref) === null) {
                continue;
            }
            // A MAP is typed as the map wherever it is named, never as a class to extend: wrapped, the
            // alias became an empty class, and symfony and yii3 took any map, or a list, unchecked.
            if ($alias['allOf'] && $this->isObjectDtoComponent($endClass) && $this->mapComponentDefinition($ref) === null) {
                continue;
            }
            $resolved[$className] = ['ref' => $ref, 'nullable' => $nullable];
            $this->pureAliasClasses[$className] = $ref;
        }

        if ($resolved === []) {
            return;
        }
        $this->pureAliasRedirects = $resolved;

        foreach ($this->dtoSchemas as $className => $schema) {
            $this->dtoSchemas[$className] = $this->withRedirectedAliases($schema, $resolved);
        }
    }

    /**
     * A `$ref` into another document's components that names a MAP, an ARRAY ALIAS or an ALIAS of
     * another component there is read by the local pointer its class is registered under — what a
     * reference of the root document to such a component is, so it is typed and redirected the same
     * way. A map component is typed as the map wherever a local reference names it; named across files,
     * it stayed its class, which symfony and yii3 never checked the values of: `{"k": 1}` passed a map
     * of strings. An alias (`Ring: {$ref: Circle}` in the other document) was typed as the class `Ring`,
     * which is never written.
     */
    private function localizeExternalValueComponentReferences(): void
    {
        foreach ($this->dtoSchemas as $className => $schema) {
            $this->constraintSourceFile = $this->getSchemaSourceFile($className);
            try {
                $this->dtoSchemas[$className] = $this->withExternalValueComponentsLocalized($schema);
            } finally {
                $this->constraintSourceFile = null;
            }
        }
    }

    private function withExternalValueComponentsLocalized(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $ref = $node['$ref'] ?? null;
        $prefix = '#/components/schemas/';
        if (is_string($ref) && !str_starts_with($ref, $prefix) && str_contains($ref, $prefix)) {
            $pointer = $this->registeredComponentPointer($ref);
            $definition = $pointer === null ? null : $this->localSchemaDefinitionForRef($pointer);
            if (
                $pointer !== null
                && $definition !== null
                && (
                    $this->mapComponentDefinition($pointer) !== null
                    || $this->arrayAliasDefinition($pointer) !== null
                    || $this->pureAliasTarget($definition) !== null
                )
            ) {
                $node['$ref'] = $pointer;
            }
        }

        $localized = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::DATA_VALUED_KEYWORDS, true)) {
                $localized[$key] = $value;
            } elseif (in_array($key, ['properties', 'patternProperties', 'dependentSchemas', '$defs'], true) && is_array($value)) {
                // Keyed by names, which may spell a data keyword: `properties: {default: {$ref: …}}`.
                $localized[$key] = array_map($this->withExternalValueComponentsLocalized(...), $value);
            } else {
                $localized[$key] = $this->withExternalValueComponentsLocalized($value);
            }
        }

        return $localized;
    }

    /**
     * The `$ref` a component consists of, plain or as a single-reference `allOf`, when nothing but
     * annotations stands beside it.
     *
     * @param array<string, mixed> $definition
     */
    private function pureAliasTarget(array $definition): ?string
    {
        $ref = $definition['$ref'] ?? null;
        $spelling = '$ref';
        if (!is_string($ref) && $this->isSingleRefAllOf($definition)) {
            /** @var array<int, array<string, mixed>> $allOf */
            $allOf = $definition['allOf'];
            if (array_keys($allOf[0]) !== ['$ref']) {
                return null;
            }
            $ref = $allOf[0]['$ref'];
            $spelling = 'allOf';
        }
        if (!is_string($ref)) {
            return null;
        }

        foreach (array_keys($definition) as $keyword) {
            if (
                $keyword !== $spelling
                && !in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                && !in_array($keyword, self::RESOURCE_KEYWORDS, true)
                && !str_starts_with($keyword, 'x-')
            ) {
                return null;
            }
        }

        return $ref;
    }

    /**
     * The keywords that make a schema a resource or a container of definitions without asserting
     * anything of a value. `{$id: …, $defs: {inner: …}, $ref: '#/$defs/inner'}` — how a document that is
     * one schema names its entry point — only names `inner`; read as more than an alias, it became an
     * empty class that validated nothing, and yii3 died calling a method that class did not have.
     */
    private const array RESOURCE_KEYWORDS = ['$id', '$anchor', '$schema', '$comment', '$defs'];

    /**
     * Whether a component is generated as an object DTO: not a scalar, enum or array alias.
     */
    private function isObjectDtoComponent(string $className): bool
    {
        if (array_key_exists($className, $this->enumSchemas)) {
            return false;
        }
        $definition = $this->dtoSchemas[$className] ?? null;

        return is_array($definition)
            && !$this->isScalarAliasSchema($definition)
            && !$this->isArrayAliasComponent($definition);
    }

    /**
     * @param array<string, array{ref: string, nullable: bool}> $aliases keyed by the alias's class name
     */
    private function withRedirectedAliases(mixed $node, array $aliases): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $alias = $this->aliasNamedBy($node['$ref'] ?? null, $aliases);
        if ($alias !== null) {
            $node['$ref'] = $alias['ref'];
            if ($alias['nullable'] && !array_key_exists('nullable', $node)) {
                $node['nullable'] = true;
            }
        }

        $mapping = $node['discriminator']['mapping'] ?? null;
        if (is_array($mapping)) {
            foreach ($mapping as $value => $target) {
                $mapped = $this->aliasNamedBy($target, $aliases);
                if ($mapped !== null) {
                    $node['discriminator']['mapping'][$value] = $mapped['ref'];
                }
            }
        }

        foreach ($node as $key => $child) {
            if ($key !== 'discriminator' && is_array($child)) {
                $node[$key] = $this->withRedirectedAliases($child, $aliases);
            }
        }

        return $node;
    }

    /**
     * @param array<string, array{ref: string, nullable: bool}> $aliases
     * @return array{ref: string, nullable: bool}|null
     */
    private function aliasNamedBy(mixed $ref, array $aliases): ?array
    {
        $prefix = '#/components/schemas/';
        if (!is_string($ref) || !str_starts_with($ref, $prefix)) {
            return null;
        }

        return $aliases[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? null;
    }

    /**
     * A component that is an `allOf` around one union of values, with members that only constrain —
     * `{allOf: [{anyOf: [<map>, {type: integer}]}]}`, `{allOf: [{anyOf: [string, integer]}, {maxProperties:
     * 3}]}` — is that union with the keywords beside it (`allOfWrappedUnionWithSiblings()`), and so the
     * value alias the union is. The same `allOf` written on a property was unwrapped this way already;
     * as a component it was merged into a class of no properties: a number was refused as no object,
     * a map accepted unchecked and written back `{}`.
     *
     * Only a union that IS a value is unwrapped here; an `allOf` around a union of classes keeps the
     * class it always had.
     */
    private function foldAllOfWrappedValueUnionComponents(): void
    {
        foreach ($this->dtoSchemas as $className => $schema) {
            if (!is_array($schema['allOf'] ?? null)) {
                continue;
            }
            $merged = $this->allOfWrappedUnionWithSiblings($schema);
            if ($merged === null || !$this->isScalarAliasSchema($merged)) {
                continue;
            }
            /** @var array<string, mixed> $merged */
            $this->dtoSchemas[$className] = $merged;
        }
    }

    private function finalizeGeneration(): int
    {
        $this->localizeExternalValueComponentReferences();
        $this->redirectPureAliasReferences();
        $this->foldAllOfWrappedValueUnionComponents();
        $this->attributeReferencedRequestBodies();
        $this->mergeObjectReferenceSiblings();
        $this->hoistUnionMembersOfComponentAllOf();
        $this->expandNestedSchemas();
        $this->inlineNullSchemaReferences();
        $this->resolvePendingAllOfDiscriminators();
        $this->detectParentClasses();
        $this->detectUnionInterfaces();
        // Again: the union members `detectUnionInterfaces()` registered may extend a component —
        // `anyOf: [{type: object, allOf: [{$ref: Base}]}]` — and a parent nobody listed was emitted
        // `final`, a fatal the moment the member class loads.
        $this->detectParentClasses();

        // Every schema is registered by now (including nested and external ones), which is what
        // makes the document-wide decision below reliable.
        $this->serializationGroupsRequired = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY
            && $this->documentNeedsSerializationGroups();
        $this->resetSymfonyReachabilityCache();
        $this->resetLaravelRenderState();

        $this->warnAboutClassNamesTheEmittedCodeAlsoUses();

        // Render everything into memory BEFORE touching the output directory. A document can only
        // be proven fully renderable once every synthesised schema is registered, and that happens
        // during rendering — so an unresolvable `$ref` is detected at the very end. Emptying the
        // directory up front meant such a run printed its error having ALREADY replaced the
        // application's working classes with a half-generated set: a DTO type-hinting a class that
        // was never emitted, which fatals on the next request. Buffering makes a failed generation
        // leave the previous output exactly as it was.
        //
        // The publisher stages these bytes, then replaces the output with rollback on failure.
        // Multiple output roots still require an inactive build directory during publication.
        /** @var array<string, string> $pendingFiles absolute path => file contents */
        $pendingFiles = [];

        $generatedCount = 0;
        /** @var array<string, true> $generatedClasses */
        $generatedClasses = [];

        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            // A component schema whose top-level type is `array` is a type alias (a list), not an
            // object — references to it resolve to the aliased array type, so no empty DTO class
            // file is emitted for it.
            //
            // A free-form `{type: object}` component keeps its class on purpose. Where it is USED
            // as a value (a property, an array item, a `$ref` target) it resolves to a map so the
            // payload survives — but the class itself is also how a response schema is named, so
            // deleting it would take away a type the application may reference.
            // Everything read while this class is analysed and rendered resolves its references
            // against the document that declares it — whether it is an alias at all included.
            $this->constraintSourceFile = $this->getSchemaSourceFile($className);
            if (
                array_key_exists($className, $this->pureAliasClasses)
                || $this->isArrayAliasComponent($schemaDefinition)
                || $this->isScalarAliasSchema($schemaDefinition)
            ) {
                $this->constraintSourceFile = null;
                continue;
            }

            try {
                $schemaMetadata = $this->analyzeSchema(className: $className, schemaDefinition: $schemaDefinition);
                $this->warnAboutUnhydratableUnionProperties($className, $schemaMetadata['properties']);
                $namespace = $this->schemaNamespaces[$className] ?? $this->baseNamespace;
                $outputDirectory = $this->schemaOutputDirectories[$className] ?? $this->baseOutputDirectory;
                $classCode = $this->renderDtoClass(
                    namespace: $namespace,
                    className: $className,
                    schemaMetadata: $schemaMetadata,
                );
            } finally {
                $this->constraintSourceFile = null;
            }
            $filePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $className . '.php';
            $this->queueGeneratedClass(
                namespace: $namespace,
                className: $className,
                filePath: $filePath,
                content: $classCode,
                files: $pendingFiles,
                classes: $generatedClasses,
            );
            $generatedCount++;

            // Laravel mode also emits the first-party entry point for an INCOMING payload, so the
            // application type-hints it and gets a validated, typed object without writing anything.
            if ($this->laravelEmitsFormRequestFor($className)) {
                $formRequestPath = rtrim($outputDirectory, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . $className . 'FormRequest.php';
                $this->queueGeneratedClass(
                    namespace: $namespace,
                    className: $className . 'FormRequest',
                    filePath: $formRequestPath,
                    content: $this->renderLaravelFormRequestClass($namespace, $className),
                    files: $pendingFiles,
                    classes: $generatedClasses,
                );
                $generatedCount++;
            }

            // laravel-data's counterpart for a body that is a union of classes, which it cannot build.
            $resolverCode = $this->renderLaravelDataUnionResolverClass($namespace, $className);
            if ($resolverCode !== null) {
                $this->queueGeneratedClass(
                    namespace: $namespace,
                    className: $className . 'Resolver',
                    filePath: rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $className . 'Resolver.php',
                    content: $resolverCode,
                    files: $pendingFiles,
                    classes: $generatedClasses,
                );
                $generatedCount++;
            }
            $this->warnAboutUnionBodyWithoutEntryPoint($className);
        }
        $this->warnAboutValueUnionBodies();

        foreach ($this->enumSchemas as $enumName => $enumDefinition) {
            $namespace = $this->enumNamespaces[$enumName] ?? $this->baseNamespace;
            $outputDirectory = $this->enumOutputDirectories[$enumName] ?? $this->baseOutputDirectory;
            $enumCode = $this->renderEnum(
                namespace: $namespace,
                enumName: $enumName,
                backingType: $enumDefinition['type'],
                values: $enumDefinition['values'],
                caseNames: $enumDefinition['caseNames'],
                descriptions: $enumDefinition['descriptions'],
            );
            $filePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $enumName . '.php';
            $this->queueGeneratedClass(
                namespace: $namespace,
                className: $enumName,
                filePath: $filePath,
                content: $enumCode,
                files: $pendingFiles,
                classes: $generatedClasses,
            );
            $generatedCount++;
        }

        // Last, when every schema — declared, extracted and synthesised — is registered, and still
        // before a single byte has been written.
        $this->assertEveryLocalReferenceResolved();
        $this->warnAboutImportsShadowingGeneratedClasses($pendingFiles);

        $this->publishGeneratedFiles($pendingFiles, [$this->baseOutputDirectory]);

        return $generatedCount;
    }

    /**
     * @param array<string, string> $files
     * @param array<string, true> $classes
     */
    private function queueGeneratedClass(
        string $namespace,
        string $className,
        string $filePath,
        string $content,
        array &$files,
        array &$classes,
    ): void {
        $fqcn = strtolower(trim($namespace, '\\') . '\\' . $className);
        if (array_key_exists($filePath, $files) || array_key_exists($fqcn, $classes)) {
            throw new RuntimeException(sprintf('Generated class/output collision for %s at %s.', $className, $filePath));
        }
        $classes[$fqcn] = true;
        $files[$filePath] = $content;
    }

    /**
     * @param array<mixed> $openApi
     */
    private function registerDocumentSchemas(array $openApi, ?string $sourceFile, bool $includeInlineSchemas): void
    {
        $schemas = $this->withComponentBodiesNormalized($this->extractSchemas($openApi));
        if ($includeInlineSchemas) {
            foreach ($this->extractInlineResponseSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
            foreach ($this->extractInlineRequestSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
            foreach ($this->extractParameterSchemas($openApi) as $name => $schema) {
                $schemas[$name] = $schema;
            }
        }

        foreach ($schemas as $schemaName => $schemaDefinition) {
            // A component name of digits — `0`, `2024` — is legal by the specification's own key
            // pattern, and every parser hands such a key back as an INT. It reached
            // `schemaClassName(string $schemaName)` unconverted and killed the run with an uncaught
            // TypeError and a stack trace, on a document that was never wrong.
            $schemaName = (string)$schemaName;
            if (!is_array($schemaDefinition)) {
                continue;
            }

            if ($this->namesExternalSchemaOfItsOwnName($schemaDefinition, $schemaName)) {
                $externalRef = $schemaDefinition['$ref'];
                if (is_string($externalRef)) {
                    $this->ensureSchemaRefRegistered(ref: $externalRef, currentSourceFile: $sourceFile);
                }
                continue;
            }

            $className = $this->schemaClassName($schemaName);
            $this->registerSchema(
                className: $className,
                schemaDefinition: $schemaDefinition,
                sourceFile: $sourceFile,
                componentName: $schemaName,
            );
        }
    }

    /**
     * A component that only names a schema of another document BY THAT SCHEMA'S OWN NAME — `Circle:
     * {$ref: other.yaml#/components/schemas/Circle}` — re-exports it: the class of that name is the
     * other document's, so the component registers nothing of its own. Under ANOTHER name (`LocalCircle:
     * {$ref: …Circle}`) it is an alias, registered as one and redirected to its target by
     * `redirectPureAliasReferences()`: skipped as well, the local name named no class at all and every
     * reference to it stopped generation as unresolvable.
     *
     * @param array<string, mixed> $schemaDefinition
     */
    private function namesExternalSchemaOfItsOwnName(array $schemaDefinition, string $schemaName): bool
    {
        if (!$this->isPureExternalSchemaAlias($schemaDefinition)) {
            return false;
        }
        $pointer = explode('#', (string)$schemaDefinition['$ref'], 2)[1] ?? '';
        $targetName = $this->externalPointerSchemaName('#' . $pointer);

        return $targetName === null || $this->schemaClassName($targetName) === $this->schemaClassName($schemaName);
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function isPureExternalSchemaAlias(array $schemaDefinition): bool
    {
        if (count($schemaDefinition) !== 1) {
            return false;
        }

        $ref = $schemaDefinition['$ref'] ?? null;

        return is_string($ref)
            && $ref !== ''
            && !str_starts_with($ref, '#/components/schemas/');
    }

    /**
     * A named `type: string`/`type: integer` schema whose `enum` holds a member of another type
     * cannot become a backed enum, and the fall-through is worse than a refusal: the schema is
     * synthesized into a DTO class with no properties, so generation "succeeds" and then EVERY
     * request carrying the field dies with `Cannot deserialize nested DTO X from non-array value`.
     * The message here names the schema and the offending value instead.
     *
     * A `null` member of a `nullable` enum is not such a member — that is the ordinary way to spell
     * an optional enum, and the property is rendered nullable.
     *
     * @param array<string, mixed> $schemaDefinition
     */
    private function assertEnumMembersMatchDeclaredType(string $className, array $schemaDefinition): void
    {
        $enum = $schemaDefinition['enum'] ?? null;
        $type = $schemaDefinition['type'] ?? null;
        if (!is_array($enum) || $enum === [] || !in_array($type, ['string', 'integer'], true)) {
            return;
        }

        $nullable = ($schemaDefinition['nullable'] ?? false) === true;

        foreach ($enum as $value) {
            if ($value === null && $nullable) {
                continue;
            }
            if ($type === 'integer' ? is_int($value) : is_string($value) || is_int($value)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Enum schema %s declares type %s but contains the %s value %s. '
                . 'A backed enum cannot represent it: fix the spec, or drop the `type` so the '
                . 'values are validated as a plain enum constraint.',
                $className,
                $type,
                get_debug_type($value),
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ));
        }
    }

    /** The seven type names JSON Schema and OpenAPI define between them. `null` is 3.1's. */
    private const array KNOWN_SCHEMA_TYPES = [
        'string',
        'number',
        'integer',
        'boolean',
        'array',
        'object',
        'null',
    ];

    /**
     * Keywords that assert nothing. A subschema made only of these (or of nothing, `{}`) is the
     * always-true schema — which is not the same thing as a subschema the validator cannot read.
     */
    private const array ANNOTATION_ONLY_KEYWORDS = [
        'title',
        'description',
        '$comment',
        'examples',
        'example',
        'default',
        'deprecated',
        'readOnly',
        'writeOnly',
        'externalDocs',
        'xml',
        '$id',
        '$anchor',
        '$schema',
    ];

    /** Keys whose value is a single subschema. */
    private const array SUBSCHEMA_KEYS = [
        'items',
        'contains',
        'not',
        'propertyNames',
        'if',
        'then',
        'else',
        'contentSchema',
        'additionalProperties',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /** Keys whose value is a MAP of subschemas. */
    private const array SUBSCHEMA_MAP_KEYS = [
        'properties',
        'patternProperties',
        'dependentSchemas',
        '$defs',
    ];

    /** Keys whose value is a LIST of subschemas. */
    private const array SUBSCHEMA_LIST_KEYS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /**
     * Every `type` a schema declares has to be one JSON Schema defines. A typo is not a subtle one.
     *
     * `type: strng` cost the property its checking, twice over and silently: the emitted property
     * became `mixed` instead of `string`, and the validator — which matches a value against a type it
     * recognises — accepted every value there is. Neighbouring keywords stayed, so `minLength` still
     * fired when the value happened to be a string and never otherwise. A document with one letter
     * missing validated nothing and said nothing.
     *
     * The walk visits SCHEMA POSITIONS only, never values. That distinction is the whole reason it is
     * written out by key rather than recursing over everything: `example: {type: banana}` is DATA — a
     * sample payload with a field called `type` — and a walker that could not tell the difference
     * would refuse valid documents.
     *
     * 3.1 allows a list (`type: [string, 'null']`), so each member is checked. `null` is accepted
     * whatever the document's version claims: refusing it in a 3.0 document would reject something no
     * parser or reader would misunderstand, and this is a check for typos, not for version policing.
     *
     * @param array<mixed> $schema
     */
    private function assertDeclaredTypesAreKnown(array $schema, string $ownerClassName): void
    {
        $type = $schema['type'] ?? null;
        if ($type !== null) {
            foreach (is_array($type) ? $type : [$type] as $member) {
                if (is_string($member) && in_array($member, self::KNOWN_SCHEMA_TYPES, true)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Schema "%s" declares type %s, which is not a JSON Schema type. Allowed: %s. '
                    . 'An unrecognised type is dropped, leaving the value unchecked.',
                    $ownerClassName,
                    is_string($member) ? '"' . $member . '"' : get_debug_type($member),
                    implode(', ', self::KNOWN_SCHEMA_TYPES),
                ));
            }
        }

        foreach (self::SUBSCHEMA_KEYS as $key) {
            $child = $schema[$key] ?? null;
            if (is_array($child)) {
                $this->assertDeclaredTypesAreKnown($child, $ownerClassName);
            }
        }

        foreach ([...self::SUBSCHEMA_MAP_KEYS, ...self::SUBSCHEMA_LIST_KEYS] as $key) {
            $group = $schema[$key] ?? null;
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $child) {
                if (is_array($child)) {
                    $this->assertDeclaredTypesAreKnown($child, $ownerClassName);
                }
            }
        }
    }

    /**
     * The component bodies of one document with each body that only NARROWS another schema written as
     * the schema it narrows to, before anything classifies them — the scalar-alias, map and class
     * predicates then read the plain spelling they already know:
     *
     * - `{$ref: Crimson, enum: [-0.5]}` over a scalar `Crimson: {type: number}` is the scalar
     *   `{type: number, enum: [-0.5]}` (an `enum`, a `const` or a value bound beside the reference, the
     *   two merged as `besideReferencedSchema()` merges them). Read as written it had no `type` and an
     *   `enum`, so it was no untyped value either, and became an EMPTY class: every mode refused the
     *   one valid number as "must be an object";
     * - `{allOf: [{type: object, additionalProperties: …}]}` with at most annotations beside it is its
     *   one member. The map predicates did not look into the `allOf`, so the map became an empty class
     *   too, and symfony and yii3 took any map unchecked.
     *
     * A reference into another document is left as written.
     *
     * @param array<array-key, mixed> $schemas the document's components, by name
     * @return array<array-key, mixed>
     */
    private function withComponentBodiesNormalized(array $schemas): array
    {
        $normalized = [];
        foreach ($schemas as $name => $schema) {
            $body = is_array($schema)
                ? $this->normalizedComponentBody(schema: $schema, components: $schemas, visited: [(string)$name])
                : $schema;
            // The narrowed body is spelled as a component enum is, as `extractSchemas()` spelled the rest.
            $normalized[$name] = $body === $schema ? $schema : $this->normalizeEnumSpelling($body);
        }

        return $normalized;
    }

    /**
     * See `withComponentBodiesNormalized()`.
     *
     * @param array<mixed> $schema
     * @param array<array-key, mixed> $components
     * @param list<string> $visited the components already being normalized, against a cycle
     * @return array<mixed>
     */
    private function normalizedComponentBody(array $schema, array $components, array $visited): array
    {
        $allOf = $schema['allOf'] ?? null;
        if (
            is_array($allOf)
            && count($allOf) === 1
            && is_array($allOf[0] ?? null)
            && !array_key_exists('$ref', $allOf[0])
            && $this->onlyAnnotates(schema: $schema, except: 'allOf')
        ) {
            $member = $allOf[0];
            foreach ($schema as $keyword => $value) {
                if ($keyword !== 'allOf' && !array_key_exists($keyword, $member)) {
                    $member[$keyword] = $value;
                }
            }

            return $member;
        }

        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref) || !str_starts_with($ref, '#/components/schemas/') || $this->isAnnotatedReference($schema)) {
            return $schema;
        }
        $own = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword === '$ref') {
                continue;
            }
            if (
                !in_array($keyword, ['enum', 'const'], true)
                && !in_array($keyword, self::SCALAR_BOUND_KEYWORDS, true)
                && !in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                && !in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                && !str_starts_with((string)$keyword, 'x-')
            ) {
                return $schema;
            }
            $own[$keyword] = $value;
        }
        $targetName = $this->componentNameInPointer($ref);
        $target = $components[$targetName] ?? null;
        if (!is_array($target) || in_array($targetName, $visited, true)) {
            return $schema;
        }
        $target = $this->normalizedComponentBody(schema: $target, components: $components, visited: [...$visited, $targetName]);
        if (!$this->isPlainScalarSchema($target)) {
            return $schema;
        }
        /** @var array<string, mixed> $own */
        /** @var array<string, mixed> $target */
        return $this->besideReferencedSchema(own: $own, target: $target);
    }

    /**
     * Whether every keyword of a schema but one only annotates it — `title`, `description`, a 3.0
     * `nullable`, an `x-` extension.
     *
     * @param array<mixed> $schema
     */
    private function onlyAnnotates(array $schema, string $except): bool
    {
        foreach (array_keys($schema) as $keyword) {
            if (
                $keyword !== $except
                && !in_array($keyword, self::ANNOTATION_ONLY_KEYWORDS, true)
                && !in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                && !str_starts_with((string)$keyword, 'x-')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * A schema of a scalar `type` (or a list of them) and nothing that names or composes another schema.
     *
     * @param array<mixed> $schema
     */
    private function isPlainScalarSchema(array $schema): bool
    {
        $types = $schema['type'] ?? null;
        $types = is_array($types) ? $types : [$types];
        foreach ($types as $type) {
            if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'null'], true)) {
                return false;
            }
        }
        if (array_key_exists('properties', $schema) || array_key_exists('items', $schema)) {
            return false;
        }
        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A component union whose branch is one schema wrapped in an `allOf` — `oneOf: [{allOf: [{$ref:
     * Coral}]}, …]`, with at most annotations beside it — is a union of that schema, as the same union
     * written on a property already is. Left wrapped, the branch was a member of no class: with every
     * branch wrapped the union became an EMPTY class that accepted anything and dropped the payload,
     * and a wrapped member beside a bare one was missing from the union, so the valid payload it alone
     * accepts was refused as "Unsupported type". Unwrapped where the component is registered, every
     * reader — interface linking, the owner's constraints, each mode's typing — sees the same union.
     *
     * @param array<string, mixed> $schemaDefinition
     * @return array<string, mixed>
     */
    private function withAllOfWrappedUnionMembersUnwrapped(array $schemaDefinition): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schemaDefinition[$keyword] ?? null;
            if (!is_array($branches) || !array_is_list($branches)) {
                continue;
            }
            $unwrapped = [];
            foreach ($branches as $branch) {
                $unwrapped[] = is_array($branch)
                    ? $this->allOfWrappedSchema($branch) ?? $this->nullableAllOfWrappedReference($branch) ?? $branch
                    : $branch;
            }
            $schemaDefinition[$keyword] = $unwrapped;
        }

        return $schemaDefinition;
    }

    /**
     * The one schema a branch `{allOf: [X]}` wraps, when nothing beside the `allOf` asserts anything —
     * a title, a description, `deprecated` — otherwise null. A `nullable: true` beside it admits a null
     * X does not, so that branch stays as written.
     *
     * @param array<mixed> $branch
     * @return array<mixed>|null
     */
    private function allOfWrappedSchema(array $branch): ?array
    {
        $allOf = $branch['allOf'] ?? null;
        if (!is_array($allOf) || count($allOf) !== 1) {
            return null;
        }
        $wrapped = array_values($allOf)[0];
        if (!is_array($wrapped)) {
            return null;
        }
        foreach ($branch as $keyword => $value) {
            $annotation = $keyword === 'nullable'
                ? $value === false
                : in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                    || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                    || str_starts_with((string)$keyword, 'x-');
            if ($keyword !== 'allOf' && !$annotation) {
                return null;
            }
        }

        return $wrapped;
    }

    /**
     * The properties of a schema with an `allOf` that wraps one union — `vole: {allOf: [{anyOf: [{type:
     * array}, <object>]}]}`, with or without siblings (`allOfWrappedUnionWithSiblings()`) — written as
     * that union, and the items of a
     * list written `{allOf: [{$ref: Pet}]}` as `{$ref: Pet}`. Both are the schema they wrap, and only some
     * readers unwrapped them: symfony and yii3 judged the wrapped union by a fraction of its members'
     * rules — an object member's enum dropped, a `$ref` member never built — and symfony held the wrapped
     * list items as a bare list, so an item that was no `Pet` passed. Unwrapped where the schema is
     * registered, every mode sees what the bare spelling already gave it.
     *
     * @param array<string, mixed> $schemaDefinition
     * @return array<string, mixed>
     */
    private function withAllOfWrappedPropertiesUnwrapped(array $schemaDefinition): array
    {
        $properties = $schemaDefinition['properties'] ?? null;
        if (!is_array($properties)) {
            return $schemaDefinition;
        }
        $unwrapped = [];
        foreach ($properties as $name => $propertySchema) {
            if (!is_array($propertySchema)) {
                $unwrapped[$name] = $propertySchema;

                continue;
            }
            $propertySchema = $this->withInnerAllOfUnionsMerged($this->withWrappedReferencesUnwrapped($propertySchema));
            $unwrapped[$name] = $this->withAllOfWrappedReferenceItemsUnwrapped(
                $this->allOfWrappedUnionWithSiblings($propertySchema) ?? $propertySchema,
            );
        }
        $schemaDefinition['properties'] = $unwrapped;

        return $schemaDefinition;
    }

    /**
     * The list items, map values and union members below a property, at any depth, with an `allOf` of
     * one union and members that only constrain or annotate written as that union
     * (`allOfWrappedUnionWithSiblings()`): `items: {allOf: [{anyOf: […]}, {maxProperties: 3}]}` became
     * an item class of no properties, as the property itself did. A one-member `allOf` stays as written
     * there — those positions already read it as the union.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function withInnerAllOfUnionsMerged(array $schema): array
    {
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = $this->innerAllOfUnionMerged($schema[$keyword]);
            }
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || !array_is_list($members)) {
                continue;
            }
            $schema[$keyword] = array_map(
                callback: fn(mixed $member): mixed => is_array($member) ? $this->innerAllOfUnionMerged($member) : $member,
                array: $members,
            );
        }

        return $schema;
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function innerAllOfUnionMerged(array $schema): array
    {
        $schema = $this->withInnerAllOfUnionsMerged($schema);
        $allOf = $schema['allOf'] ?? null;
        if (!is_array($allOf) || count($allOf) < 2) {
            return $schema;
        }

        return $this->allOfWrappedUnionWithSiblings($schema) ?? $schema;
    }

    /**
     * `{allOf: [{oneOf: […]}], <siblings>}` — one union wrapped, anything beside it — as `{oneOf: […],
     * <siblings>}`: an `allOf` of one member says what that member says, and the siblings keep asserting
     * beside it. A `nullable: true` or `minProperties` beside the wrapper once kept it wrapped, and yii3,
     * which spells out object branches only on a top-level union, held the union's object branch as a raw
     * array nothing checked.
     *
     * The same for an `allOf` of the union and members that only constrain or annotate —
     * `{allOf: [{anyOf: […]}, {maxProperties: 3}]}` is `{anyOf: […], maxProperties: 3}`, the keywords of
     * every member holding at one level. With the extra member the `allOf` was merged into a class of no
     * properties instead, in every mode: a scalar branch was refused as no object, an object broke the
     * union and the member unseen, and what passed was written back as `{}`. A `true` member constrains
     * nothing and is dropped.
     *
     * Null when no member or more than one is a union, the union carries a `$ref` (a 3.0 `$ref` takes its
     * siblings differently), another member is a schema in its own right (`$ref`, `properties`, a
     * composition, `false`) or reads what its own branch evaluated (`unevaluated*`), or two of them share
     * a keyword, whose two values would both have to hold.
     *
     * @param array<mixed> $schema
     * @return array<mixed>|null
     */
    private function allOfWrappedUnionWithSiblings(array $schema): ?array
    {
        $allOf = $schema['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === []) {
            return null;
        }
        $union = null;
        $constraintMembers = [];
        foreach ($allOf as $member) {
            if ($member === true) {
                continue;
            }
            if (!is_array($member)) {
                return null;
            }
            if (is_array($member['oneOf'] ?? null) || is_array($member['anyOf'] ?? null)) {
                if ($union !== null || array_key_exists('$ref', $member)) {
                    return null;
                }
                $union = $member;

                continue;
            }
            foreach (self::ALLOF_UNION_MEMBER_SCHEMA_KEYWORDS as $keyword) {
                if (array_key_exists($keyword, $member)) {
                    return null;
                }
            }
            $constraintMembers[] = $member;
        }
        if ($union === null) {
            return null;
        }
        if ($constraintMembers !== [] && (array_key_exists('unevaluatedProperties', $union) || array_key_exists('unevaluatedItems', $union))) {
            return null;
        }
        $besideAllOf = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== 'allOf') {
                $besideAllOf[$keyword] = $value;
            }
        }
        $merged = $union;
        foreach ([...$constraintMembers, $besideAllOf] as $part) {
            foreach ($part as $keyword => $value) {
                if (array_key_exists($keyword, $merged)) {
                    return null;
                }
                $merged[$keyword] = $value;
            }
        }

        return $merged;
    }

    /**
     * A list whose items are `{allOf: [{$ref: X}]}`, at any depth of lists, as the list of `{$ref: X}` —
     * with the annotations beside the wrapper kept beside the reference. A description or title beside
     * the wrapper once kept it, and symfony held the list as a bare list: an item that was no X, or broke
     * X's union, passed. A 3.0 `nullable: true` beside it is kept beside the reference too: left wrapped,
     * the list was typed with no item class and runtime handed out the decoded `stdClass`. Except in
     * laravel-data, whose `#[DataCollectionOf]` of the bare nullable reference refuses a `null` item
     * (README.support-matrix); there the wrapped list keeps answering it, as it did — a union target is
     * folded once every component is known, by `withNullableAllOfWrappedUnionItemsFolded()`.
     *
     * The lists inside the branches of a union are unwrapped the same way: `{anyOf: [{type: array, items:
     * {allOf: [{$ref: Shape}]}}, {type: null}]}`, Shape a union component, was held by symfony as a bare
     * nullable list, and any item — `2.5`, a member missing its required keys — passed.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function withAllOfWrappedReferenceItemsUnwrapped(array $schema): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || !array_is_list($members)) {
                continue;
            }
            $schema[$keyword] = array_map(
                callback: fn(mixed $member): mixed => is_array($member) ? $this->withAllOfWrappedReferenceItemsUnwrapped($member) : $member,
                array: $members,
            );
        }
        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return $schema;
        }
        $unwrapped = $this->allOfWrappedReference($items);
        if (($unwrapped['nullable'] ?? null) === true && $this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL_DATA) {
            $unwrapped = null;
        }
        $schema['items'] = $this->withAllOfWrappedReferenceItemsUnwrapped($unwrapped ?? $items);

        return $schema;
    }

    /**
     * The reference a schema consists of: `{allOf: [{$ref: X, <bounds>}], <annotations>}` as the bare
     * `{$ref: X, <bounds>, <annotations>}` (`allOfWrappedReference()`), when a bound beside the wrapped
     * reference asserts something; null otherwise. Without one the wrapper is the plain reference every
     * reader already follows (`isSingleRefAllOf()`); with one — `{allOf: [{$ref: Coral, maxItems: 1}]}`
     * — no reader saw through it, and the wrapper was typed by the class of the list or map it names,
     * which is no class: never written, so runtime refused every list as an unsupported type and laravel
     * died on any payload, symfony refused every list, yii3 lost the bound, and a map lost its values.
     *
     * @param array<mixed> $schema
     * @return array<mixed>|null
     */
    private function referenceThisSchemaConsistsOf(array $schema): ?array
    {
        $allOf = $schema['allOf'] ?? null;
        $reference = $this->allOfWrappedReference($schema);
        if ($reference === null || !is_array($allOf) || $this->isAnnotatedReference(array_values($allOf)[0] ?? null)) {
            return null;
        }

        return $reference;
    }

    /**
     * A property's schema with every reference it consists of unwrapped (`referenceThisSchemaConsistsOf()`):
     * the property itself, its union branches, its list items and its map values, at any depth of them.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function withWrappedReferencesUnwrapped(array $schema): array
    {
        $schema = $this->referenceThisSchemaConsistsOf($schema) ?? $schema;
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = $this->withWrappedReferencesUnwrapped($schema[$keyword]);
            }
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members) || !array_is_list($members)) {
                continue;
            }
            $schema[$keyword] = array_map(
                callback: fn(mixed $member): mixed => is_array($member) ? $this->withWrappedReferencesUnwrapped($member) : $member,
                array: $members,
            );
        }

        return $schema;
    }

    /**
     * `{allOf: [{$ref: X, <bounds>}], <annotations>}` as `{$ref: X, <bounds>, <annotations>}`, a 3.0
     * `nullable: true` beside the `allOf` included; null when the `allOf` wraps anything else,
     * something beside it asserts, or the two sides name one keyword. The bounds beside the wrapped
     * reference — of a value (`{$ref: Mauve, multipleOf: 2}`) or of a container (`{$ref: Coral,
     * maxItems: 1}`) — are the reference's as they are bare; required to be a bare reference, the list
     * items `{allOf: [{$ref: Nonnull}], nullable: true}` lost their class.
     *
     * @param array<mixed> $schema
     * @return array<mixed>|null
     */
    private function allOfWrappedReference(array $schema): ?array
    {
        $withoutNullable = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== 'nullable' || $value !== true) {
                $withoutNullable[$keyword] = $value;
            }
        }
        $wrapped = $this->allOfWrappedSchema($withoutNullable);
        if ($wrapped === null || !is_string($wrapped['$ref'] ?? null)) {
            return null;
        }
        $valueBounds = [];
        foreach ($wrapped as $keyword => $value) {
            if (!in_array($keyword, self::CONTAINER_BOUND_KEYWORDS, true)) {
                $valueBounds[$keyword] = $value;
            }
        }
        if (!$this->isValueBoundReference($valueBounds)) {
            return null;
        }
        $reference = $wrapped;
        foreach ($schema as $keyword => $value) {
            if ($keyword === 'allOf') {
                continue;
            }
            if (array_key_exists($keyword, $reference)) {
                return null;
            }
            $reference[$keyword] = $value;
        }

        return $reference;
    }

    /**
     * `{allOf: [{$ref: Amber}], nullable: true}` — one reference wrapped, the 3.0 `nullable` and at most
     * annotations beside it — as the reference with its `nullable`: `{$ref: Amber, nullable: true}`,
     * which every reader takes as Amber or null (README.validation: a keyword beside a 3.0 `$ref` is
     * honoured). Left wrapped, the branch was a member of no class, so the union lost Amber: an object
     * only Amber describes was refused as "Unsupported type", one both members match passed a `oneOf`.
     * Null otherwise.
     *
     * @param array<mixed> $branch
     * @return array<string, mixed>|null
     */
    private function nullableAllOfWrappedReference(array $branch): ?array
    {
        if (($branch['nullable'] ?? null) !== true) {
            return null;
        }
        $withoutNullable = [];
        foreach ($branch as $keyword => $value) {
            if ($keyword !== 'nullable') {
                $withoutNullable[$keyword] = $value;
            }
        }
        $wrapped = $this->allOfWrappedSchema($withoutNullable);
        if ($wrapped === null || array_keys($wrapped) !== ['$ref'] || !is_string($wrapped['$ref'])) {
            return null;
        }

        return ['$ref' => $wrapped['$ref'], 'nullable' => true];
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @param string|null $componentName the schema's name in its document, for a component; null for a
     *                                   schema met inline, which has none
     */
    private function registerSchema(string $className, array $schemaDefinition, ?string $sourceFile, ?string $componentName = null): void
    {
        if ($componentName !== null) {
            // A component alias with a bound, `Kiwi: {allOf: [{$ref: Coral, maxItems: 1}]}`, is the bare
            // alias `Kiwi: {$ref: Coral, maxItems: 1}`, which every reader already follows.
            $schemaDefinition = $this->referenceThisSchemaConsistsOf($schemaDefinition) ?? $schemaDefinition;
            $schemaDefinition = $this->withAllOfWrappedUnionMembersUnwrapped($schemaDefinition);
        }
        $schemaDefinition = $this->withAllOfWrappedPropertiesUnwrapped($schemaDefinition);
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);
        if (in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true)) {
            $this->assertLaravelPropertyNamesAreUnambiguous($schemaDefinition);
        }
        $this->warnAboutPcreOnlyEscapes($schemaDefinition, $className);
        $this->warnAboutRequiredNamesWithoutProperty($schemaDefinition, $className);
        $this->warnAboutRequiredPropertiesWithDefault($schemaDefinition, $className);

        // Registries are keyed by generated class name. A different document is not the same
        // schema, even when its definition happens to look identical or its namespace is mapped.
        foreach ([$this->schemaSourceFiles, $this->enumSourceFiles] as $sources) {
            if (array_key_exists($className, $sources) && $sources[$className] !== $sourceFile) {
                $previousSource = $sources[$className] ?? 'the in-memory document';
                $nextSource = $sourceFile ?? 'the in-memory document';
                throw new RuntimeException(
                    message: 'Schema source collision for ' . $className . ': ' . $previousSource . ' and '
                    . $nextSource . '. Use distinct schema names; namespace mappings do not disambiguate the registry.',
                );
            }
        }

        // Keep the raw definition (including enums, which otherwise return early below) so a
        // referencing property can later read keywords declared on the target, e.g. `default`.
        $this->rawSchemasByClass[$className] = $schemaDefinition;

        $this->assertDeclaredTypesAreKnown($schemaDefinition, $className);

        // `deprecated` on the schema ITSELF, which until 2.15.33 reached nothing: the keyword was
        // honoured on a property and dropped on the class the property belonged to. An operation
        // marked deprecated stamps the same keyword onto the schemas it produces, so its request,
        // parameter and response classes carry the notice too.
        if (($schemaDefinition['deprecated'] ?? false) === true) {
            $this->deprecatedByClass[$className] = true;
        }

        $this->assertEnumMembersMatchDeclaredType($className, $schemaDefinition);

        if ($this->isEnumSchema($schemaDefinition)) {
            $type = $this->resolveEnumBackingType($schemaDefinition);
            /** @var array<int, string|int> $values */
            $values = $schemaDefinition['enum'];
            $this->registerEnum(
                enumName: $className,
                type: $type,
                values: $values,
                sourceFile: $sourceFile,
                varnames: $this->extractEnumVarnames($schemaDefinition, $values),
                descriptions: $this->extractEnumDescriptions($schemaDefinition, $values),
            );
            return;
        }

        if (array_key_exists($className, $this->enumSchemas)) {
            throw new RuntimeException(sprintf('Enum/DTO name collision for %s.', $className));
        }

        // Once the stored schemas are normalized (`inlineNullSchemaReferences()`), a schema registered
        // later — an inline object met again while rendering, or for the first time — is normalized
        // the same way, so the same schema compares equal and a new one is not left raw.
        if ($this->referencesNormalized) {
            $schemaDefinition = $this->withNormalizedReferences($schemaDefinition, $sourceFile);
        }

        if (array_key_exists($className, $this->dtoSchemas)) {
            if ($this->dtoSchemas[$className] !== $schemaDefinition) {
                throw new RuntimeException(sprintf('DTO schema name collision for %s.', $className));
            }

            if (($this->schemaNamespaces[$className] ?? $namespace) !== $namespace) {
                throw new RuntimeException(sprintf('DTO schema namespace collision for %s.', $className));
            }
            return;
        }

        $this->dtoSchemas[$className] = $schemaDefinition;
        $this->schemaSourceFiles[$className] = $sourceFile;
        $this->schemaNamespaces[$className] = $namespace;
        $this->schemaOutputDirectories[$className] = $outputDirectory;
        if ($componentName !== null) {
            $this->componentNames[$className] = $componentName;
        }
        $this->collectDiscriminatorMetadata(className: $className, schemaDefinition: $schemaDefinition);
    }

    /** @param array<string, mixed> $schema */
    private function assertLaravelPropertyNamesAreUnambiguous(array $schema): void
    {
        // Every place a property is NAMED, not only where it is declared: `required` and both sides
        // of `dependentRequired` become Laravel paths too, and `a.b` there read as a nested key.
        $names = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            $names[] = $name;
        }
        foreach (is_array($schema['dependentRequired'] ?? null) ? $schema['dependentRequired'] : [] as $trigger => $dependents) {
            $names[] = $trigger;
            foreach (is_array($dependents) ? $dependents : [] as $name) {
                $names[] = $name;
            }
        }
        // Laravel mode writes a dot as `\.` in a rule key, which Laravel reads as a literal dot. An
        // asterisk has no such escape, and laravel-data maps a dotted name as a nested path.
        $unsafe = $this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL ? '*' : '.*';
        foreach ($names as $name) {
            if (is_scalar($name) && strpbrk((string)$name, $unsafe) !== false) {
                throw new RuntimeException(
                    message: 'Laravel validation cannot safely represent property name "' . $name . '". '
                    . ($unsafe === '*'
                        ? 'Rename properties containing asterisks, or use another generation mode.'
                        : 'Rename properties containing dots or asterisks, or use another generation mode.'),
                );
            }
        }
        foreach (self::SUBSCHEMA_MAP_KEYS as $key) {
            if (is_array($schema[$key] ?? null)) {
                foreach ($schema[$key] as $child) {
                    if (is_array($child)) {
                        $this->assertLaravelPropertyNamesAreUnambiguous($child);
                    }
                }
            }
        }
        foreach (self::SUBSCHEMA_LIST_KEYS as $key) {
            if (is_array($schema[$key] ?? null)) {
                foreach ($schema[$key] as $child) {
                    if (is_array($child)) {
                        $this->assertLaravelPropertyNamesAreUnambiguous($child);
                    }
                }
            }
        }
        foreach (['items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                $this->assertLaravelPropertyNamesAreUnambiguous($schema[$key]);
            }
        }
    }

    private function resolveNamespaceForSourceFile(?string $sourceFile): string
    {
        if ($sourceFile === null || $this->rootSpecFile === null || $sourceFile === $this->rootSpecFile) {
            return $this->baseNamespace;
        }

        // Explicit --ref-namespace mapping takes highest precedence.
        $explicit = $this->matchRefMapping(sourceFile: $sourceFile, map: $this->refNamespaceMap, separator: '\\');
        if ($explicit !== null) {
            return $explicit;
        }

        $sharedNamespace = $this->resolveCommonNamespaceForSourceFile($sourceFile);
        if ($sharedNamespace !== null) {
            return $sharedNamespace;
        }

        $segments = $this->relativeSpecDirectorySegments($sourceFile);
        if ($segments === []) {
            return $this->baseNamespace;
        }

        return $this->baseNamespace . '\\' . implode('\\', $segments);
    }

    private function resolveOutputDirectoryForSourceFile(?string $sourceFile): string
    {
        if ($sourceFile === null || $this->rootSpecFile === null || $sourceFile === $this->rootSpecFile) {
            return $this->baseOutputDirectory;
        }

        // Explicit --ref mapping takes highest precedence.
        $explicit = $this->matchRefMapping(sourceFile: $sourceFile, map: $this->refOutputDirectoryMap, separator: '/');
        if ($explicit !== null) {
            return $explicit;
        }

        $sharedOutputDirectory = $this->resolveCommonOutputDirectoryForSourceFile($sourceFile);
        if ($sharedOutputDirectory !== null) {
            return $sharedOutputDirectory;
        }

        // The directory is built from the SAME segments as the namespace, so the file lands where PSR-4
        // looks for its class. It used to append the raw relative path instead: `../b/schema.yml` wrote
        // `<output>/../../b/ReportRow.php` — outside the output directory — for a class declared in
        // `<namespace>\\B`, and `./sub/deep.yml` wrote `<output>/../sub/Deep.php` for `<namespace>\\Sub`.
        // Neither file could be autoloaded, and the first is a write the output directory does not contain.
        $segments = $this->relativeSpecDirectorySegments($sourceFile);
        if ($segments === []) {
            return $this->baseOutputDirectory;
        }

        return rtrim($this->baseOutputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * The namespace segments — and directory names — of a document outside the root one: its directory
     * relative to the root document, `.`/`..` dropped, each segment normalized like a class name.
     *
     * @return array<int, string>
     */
    private function relativeSpecDirectorySegments(string $sourceFile): array
    {
        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return [];
        }

        $segments = array_filter(
            array: explode('/', $relativeDirectory),
            callback: static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        return array_values(array_map(fn(string $segment): string => $this->normalizeClassName($segment), $segments));
    }

    private function resolveRelativeSpecDirectory(string $sourceFile): string
    {
        if ($this->rootSpecFile === null || $this->rootSpecFile === '') {
            return '';
        }

        // Relative path is calculated from root spec file path (not its directory),
        // so external refs naturally go one level up (../common, ../../test/common, etc.).
        $relativeFile = $this->makeRelativePath(fromDirectory: $this->rootSpecFile, toPath: $sourceFile);
        $relativeDirectory = dirname($relativeFile);

        return $relativeDirectory === '.' ? '' : trim(str_replace('\\', '/', $relativeDirectory));
    }

    private function resolveCommonNamespaceForSourceFile(string $sourceFile): ?string
    {
        $relativeSegments = $this->resolveNormalizedRelativeDirectorySegments($sourceFile);
        if ($relativeSegments === [] || strtolower($relativeSegments[0]) !== 'common') {
            return null;
        }

        $baseNamespaceParts = array_values(
            array: array_filter(explode('\\', $this->baseNamespace), static fn(string $part): bool => $part !== ''),
        );
        if ($baseNamespaceParts === []) {
            return null;
        }

        $rebasedNamespaceParts = $this->rebasePartsForCommonRoot(parts: $baseNamespaceParts, commonSegment: 'Common');
        if ($rebasedNamespaceParts === null) {
            return null;
        }

        $suffixParts = array_map(
            callback: fn(string $segment): string => $this->normalizeClassName($segment),
            array: array_slice($relativeSegments, 1),
        );

        return implode('\\', [...$rebasedNamespaceParts, ...$suffixParts]);
    }

    private function resolveCommonOutputDirectoryForSourceFile(string $sourceFile): ?string
    {
        $relativeSegments = $this->resolveNormalizedRelativeDirectorySegments($sourceFile);
        if ($relativeSegments === [] || strtolower($relativeSegments[0]) !== 'common') {
            return null;
        }

        $baseOutputParts = $this->splitPathSegments($this->baseOutputDirectory);
        if ($baseOutputParts === []) {
            return null;
        }

        $rebasedOutputParts = $this->rebasePartsForCommonRoot(parts: $baseOutputParts, commonSegment: 'Common');
        if ($rebasedOutputParts === null) {
            return null;
        }

        $targetParts = [...$rebasedOutputParts, ...array_slice($relativeSegments, 1)];
        if ($targetParts === []) {
            return $this->baseOutputDirectory;
        }

        $prefix = str_starts_with($this->baseOutputDirectory, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        $target = $prefix . implode(DIRECTORY_SEPARATOR, $targetParts);
        $this->assertCommonTargetChosen(sourceFile: $sourceFile, target: $target);

        return $target;
    }

    /**
     * The `common` convention rebases the output segment named after the root document, and the
     * document's file name — not the user — chose that segment: `api.yaml` against
     * `src/Api/Generated/Petstore` rebased `Api`, two levels above `--directory`, and replaced another
     * tree's generated class. So the rebased directory has to lie inside one the user chose:
     * `--directory`, `--dto-generator-directory` or a `--ref` target. Otherwise the run stops before
     * anything is written, naming the `--ref` that places the classes exactly there.
     */
    private function assertCommonTargetChosen(string $sourceFile, string $target): void
    {
        $absoluteTarget = $this->lexicalAbsolutePath($target);
        $roots = [$this->baseOutputDirectory, ...$this->extraOutputRoots];
        foreach ($this->refOutputDirectoryMap as $refTarget) {
            // A target with a `{n}` is filled per file; what it covers is not known here, so it
            // allows nothing it did not map itself.
            if (preg_match('/\{\d+\}/', $refTarget) === 0) {
                $roots[] = $refTarget;
            }
        }
        foreach ($roots as $root) {
            $absoluteRoot = $this->lexicalAbsolutePath($root);
            if ($absoluteTarget === $absoluteRoot || str_starts_with($absoluteTarget, $absoluteRoot . '/')) {
                return;
            }
        }

        throw new RuntimeException(message: sprintf(
            '%s would place its classes in %s by the `common` convention, outside --directory %s. '
            . "To write them there, map its directory with --ref='%s=%s=%s'.",
            $sourceFile,
            $target,
            $this->baseOutputDirectory,
            dirname($sourceFile),
            $target,
            $this->resolveCommonNamespaceForSourceFile($sourceFile) ?? $this->baseNamespace,
        ));
    }

    /**
     * An absolute path with `.` and `..` folded, without touching the filesystem — the directories a
     * run writes to need not exist yet.
     */
    private function lexicalAbsolutePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $this->resolveOutputDirectory($path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @return array<int, string>
     */
    private function resolveNormalizedRelativeDirectorySegments(string $sourceFile): array
    {
        $relativeDirectory = $this->resolveRelativeSpecDirectory($sourceFile);
        if ($relativeDirectory === '') {
            return [];
        }

        return array_values(
            array: array_filter(
                array: explode('/', $relativeDirectory),
                callback: static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
            ),
        );
    }

    /**
     * @param array<int, string> $parts
     * @return array<int, string>|null
     */
    private function rebasePartsForCommonRoot(array $parts, string $commonSegment): ?array
    {
        $rootSpecSegment = $this->getRootSpecSegmentName();
        if ($rootSpecSegment === null) {
            return null;
        }

        for ($index = count($parts) - 1; $index >= 0; $index--) {
            if ($this->normalizeClassName($parts[$index]) !== $rootSpecSegment) {
                continue;
            }

            $parts[$index] = $commonSegment;
            return $parts;
        }

        return null;
    }

    private function getRootSpecSegmentName(): ?string
    {
        if ($this->rootSpecFile === null || $this->rootSpecFile === '') {
            return null;
        }

        $rootSpecName = pathinfo($this->rootSpecFile, PATHINFO_FILENAME);
        if ($rootSpecName === '') {
            return null;
        }

        return $this->normalizeClassName($rootSpecName);
    }

    /**
     * @return array<int, string>
     */
    private function splitPathSegments(string $path): array
    {
        return array_values(
            array: array_filter(
                array: explode('/', str_replace('\\', '/', $path)),
                callback: static fn(string $part): bool => $part !== '',
            ),
        );
    }

    private function makeRelativePath(string $fromDirectory, string $toPath): string
    {
        $fromParts = array_values(
            array: array_filter(
                array: explode('/', str_replace('\\', '/', rtrim($fromDirectory, '/'))),
                callback: static fn(string $part): bool => $part !== '',
            ),
        );
        $toParts = array_values(
            array: array_filter(explode('/', str_replace('\\', '/', $toPath)), static fn(string $part): bool => $part !== ''),
        );

        $length = min(count($fromParts), count($toParts));
        $commonLength = 0;
        while ($commonLength < $length && $fromParts[$commonLength] === $toParts[$commonLength]) {
            $commonLength++;
        }

        $up = array_fill(0, count($fromParts) - $commonLength, '..');
        $down = array_slice($toParts, $commonLength);
        $relativeParts = array_merge($up, $down);

        return $relativeParts === [] ? basename($toPath) : implode('/', $relativeParts);
    }

    /**
     * @param array<mixed> $node
     */
    private function scanExternalSchemaRefs(array $node, ?string $currentSourceFile): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $this->ensureSchemaRefRegistered(ref: $value, currentSourceFile: $currentSourceFile);
                continue;
            }

            // Discriminator children are addressed as plain string values in `discriminator.mapping`,
            // not as `$ref` nodes, so the generic walk below would miss them. Register each mapping
            // target (and its transitive refs) so subtype DTOs are emitted, not just referenced.
            if ($key === 'discriminator' && is_array($value)) {
                $mapping = $value['mapping'] ?? null;
                if (is_array($mapping)) {
                    foreach ($mapping as $mappingRef) {
                        if (is_string($mappingRef)) {
                            $this->ensureSchemaRefRegistered(ref: $mappingRef, currentSourceFile: $currentSourceFile);
                        }
                    }
                }
            }

            if (is_array($value)) {
                $this->scanExternalSchemaRefs(node: $value, currentSourceFile: $currentSourceFile);
            }
        }
    }

    private function ensureSchemaRefRegistered(string $ref, ?string $currentSourceFile): void
    {
        $ref = $this->stripDocumentSelfPrefix($ref);

        if (str_starts_with($ref, '#/components/schemas/')) {
            // A local pointer inside an external document addresses a sibling schema of THAT file,
            // not the root spec. Register it (and its own transitive refs) against the external
            // file so same-file children of a cross-file ref target are emitted too. Local pointers
            // of the root document are already handled by registerDocumentSchemas.
            if ($currentSourceFile !== null && $currentSourceFile !== $this->rootSpecFile) {
                $schemaName = $this->externalPointerSchemaName($ref);
                if ($schemaName !== null) {
                    $this->registerExternalSchema(externalFile: $currentSourceFile, schemaName: $schemaName);
                }
            }

            return;
        }

        $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $currentSourceFile);
        if ($resolved === null) {
            return;
        }

        [$externalFile, $pointer] = $resolved;
        $schemaName = $this->externalPointerSchemaName($pointer);
        if ($schemaName === null) {
            // Deeper pointer (e.g. .../properties/id). A scalar target is inlined at type-resolution
            // time (see resolvePropertyType); nothing to register here, so we do NOT pull the whole
            // external document and its unrelated schema graph.
            return;
        }

        // Register only the referenced schema (plus its transitive refs), not every schema in the file.
        $this->registerExternalSchema(externalFile: $externalFile, schemaName: $schemaName);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveExternalSchemaPointer(string $ref, ?string $currentSourceFile, ?string $reportedRef = null): ?array
    {
        $reportedRef ??= $ref;
        if ($currentSourceFile === null || !str_contains($ref, '#/components/schemas/')) {
            return null;
        }

        [$filePart, $pointerPart] = explode('#', $ref, 2) + [1 => ''];
        $filePart = rtrim($filePart, '/');
        if ($filePart === '') {
            return null;
        }

        // A document names the files it reads, so it may name only documents: a `$ref` at any
        // other local file would have the generator read it into its output or its error message.
        $effectivePart = preg_replace('/\.(dist|example|local|sample)$/i', '', $filePart) ?? $filePart;
        if (!in_array(strtolower(pathinfo($effectivePart, PATHINFO_EXTENSION)), ['yaml', 'yml', 'json'], true)) {
            throw new RuntimeException(sprintf('Referenced file must be a .yaml, .yml or .json document: %s', $reportedRef));
        }

        $absoluteFile = realpath(dirname($currentSourceFile) . DIRECTORY_SEPARATOR . $filePart);
        if ($absoluteFile === false) {
            throw new RuntimeException(sprintf('Referenced OpenAPI file not found: %s', $reportedRef));
        }
        $this->assertReferenceReadable(ref: $reportedRef, realFile: $absoluteFile);

        return [$this->canonicalSourceFile($absoluteFile), '#' . $pointerPart];
    }

    /**
     * The document and the files beside it may be someone else's; the directories around them are the
     * user's. So a `$ref` reads below the root document's directory, or from a file a `--ref` maps —
     * the user named that one. `../secret/creds.yaml` used to be read like any neighbour, and its
     * schema's defaults and enums were copied into the generated class.
     *
     * `$realFile` is a `realpath()`, so a link inside the tree is judged by where it leads; the
     * containment is compared by inode, so a spelling in another case on a case-folding filesystem is
     * judged by the directory it reaches, not by its letters.
     */
    private function assertReferenceReadable(string $ref, string $realFile): void
    {
        if ($this->rootSpecFile === null) {
            return;
        }

        $rootDirectory = dirname($this->rootSpecFile);
        if ((new GeneratedFilePublisher())->isWithin(path: $realFile, directory: $rootDirectory)) {
            return;
        }
        if ($this->matchRefMapping(sourceFile: $realFile, map: $this->refOutputDirectoryMap, separator: '/') !== null) {
            return;
        }

        // The reference is named, not the file it reached: what a document points at outside its tree
        // is the user's, and an error message is output too.
        throw new RuntimeException(message: sprintf(
            '$ref "%s" leaves the directory of the root document, %s. To read it, map the directory it points into with --ref.',
            $ref,
            $rootDirectory,
        ));
    }

    /**
     * One name per file, whatever spelling reached it — see `$canonicalSourceFiles`.
     */
    private function canonicalSourceFile(string $realPath): string
    {
        $stat = @stat($realPath);
        // No inode (Windows reports 0): the path is all there is to go on.
        if ($stat === false || $stat['ino'] === 0) {
            return $realPath;
        }

        return $this->canonicalSourceFiles[$stat['dev'] . ':' . $stat['ino']] ??= $realPath;
    }

    /**
     * Parse (once, cached) the components.schemas map of an external document.
     *
     * @return array<string, mixed>
     */
    private function externalSchemasOf(string $filePath): array
    {
        if (array_key_exists($filePath, $this->externalDocSchemas)) {
            return $this->externalDocSchemas[$filePath];
        }

        $data = $this->parseSpecFile(filePath: $filePath, referenced: true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('OpenAPI root must be an object/array in %s.', $filePath));
        }
        // Its anchor and `$id` references resolve against the file itself, as its pointers do.
        $data = (new SchemaResourceResolver())->resolve(document: $data, documentBase: $this->fileUri($filePath));
        // Fold this external document's own `$defs` into its components.schemas (and rewrite
        // its internal `#/$defs/` pointers) so cross-file `file#/$defs/X` references resolve.
        $data = $this->withNestedAllOfWrappersPeeled($this->normalizedDocument($this->hoistComponentSubschemas($this->foldJsonSchemaDefs($data))));
        // Cached before its references are rewritten: a file they lead to may point back here, and
        // finds the components it declares.
        $this->externalDocuments[$filePath] = $data;
        $this->externalDocSchemas[$filePath] = $this->extractSchemas($data);

        $data = $this->rewriteSchemaPointers(document: $data, sourceFile: $filePath, isRootDocument: false);
        // The components given to pointers into this file meanwhile — its own, and those of files
        // that point back into it — are kept beside what it declares.
        $components = is_array($data['components'] ?? null) ? $data['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        foreach ($this->pointedSchemaComponents[$filePath] ?? [] as $name) {
            $schemas[$name] = $this->externalDocuments[$filePath]['components']['schemas'][$name] ?? null;
        }
        $components['schemas'] = $schemas;
        $data['components'] = $components;
        $this->externalDocuments[$filePath] = $data;

        return $this->externalDocSchemas[$filePath] = $this->extractSchemas($data);
    }

    /**
     * A schema `$ref` may address a file whole (`./Pet.yaml`, a JSON Schema document) or any place
     * in a document (`shapes.yaml#/Side`, `#/definitions/Corner`), not only a component. The rest of
     * the generator resolves `#/components/schemas/Name` and nothing else, and a reference in any
     * other spelling read as "no constraint": the property became `mixed`, every mode accepted
     * whatever arrived, and generation reported success — a missing file included.
     *
     * Each such target becomes a component of the document it lives in, ONCE (every reference to it
     * names the same class), and each reference is rewritten to point at it; a target that is not
     * there stops generation. Only references in SCHEMA positions are rewritten: a path item, a
     * parameter or a request body is referenced by file as well, and is not a schema.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function rewriteSchemaPointers(array $document, ?string $sourceFile, bool $isRootDocument): array
    {
        // A file read meanwhile is rewritten in a walk of its own; this one's lifts are kept apart.
        $outerLocal = $this->swapLocalPointedSchemas([]);
        $context = ['document' => $document, 'sourceFile' => $sourceFile, 'isRootDocument' => $isRootDocument];
        $rewritten = $this->rewriteDocumentSchemaPointers(node: $document, path: [], context: $context);
        $rewritten = is_array($rewritten) ? $rewritten : $document;

        $liftedSchemas = $this->swapLocalPointedSchemas($outerLocal);
        if ($liftedSchemas !== []) {
            $components = is_array($rewritten['components'] ?? null) ? $rewritten['components'] : [];
            $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
            foreach ($liftedSchemas as $lifted) {
                $schemas[$lifted['name']] = $lifted['schema'];
            }
            $components['schemas'] = $schemas;
            $rewritten['components'] = $components;
        }

        /** @var array<string, mixed> $rewritten */
        return $rewritten;
    }

    /**
     * The walk outside schemas: a schema starts under `schema` (of a parameter, a header or a media
     * type), as a component of `components.schemas`, or as an entry of a root `$defs`/`definitions`.
     *
     * @param list<int|string> $path
     * @param array{document: array<string, mixed>, sourceFile: ?string, isRootDocument: bool} $context
     */
    private function rewriteDocumentSchemaPointers(mixed $node, array $path, array $context): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            $childPath = [...$path, $key];
            $isSchemaMap = $childPath === ['components', 'schemas']
                || $childPath === ['$defs']
                || $childPath === ['definitions'];
            if ($isSchemaMap && is_array($value)) {
                $schemas = [];
                foreach ($value as $name => $schema) {
                    $schemas[$name] = $this->rewriteSchemaNodePointers(node: $schema, context: $context);
                }
                $result[$key] = $schemas;
                continue;
            }
            // `schema` of a parameter, a header or a media type — not a component, a response, a
            // header or a path that happens to be called `schema`.
            $parentKey = $path === [] ? null : $path[array_key_last($path)];
            $namesObjects = (count($path) === 2 && $path[0] === 'components')
                || in_array($parentKey, ['paths', 'webhooks', 'responses', 'headers', 'content', 'callbacks', 'links', 'encoding'], true);
            if ($key === 'schema' && !$namesObjects) {
                $result[$key] = $this->rewriteSchemaNodePointers(node: $value, context: $context);
                continue;
            }
            if (in_array($key, ['example', 'examples'], true) || (is_string($key) && str_starts_with($key, 'x-'))) {
                $result[$key] = $value;
                continue;
            }
            $result[$key] = $this->rewriteDocumentSchemaPointers(node: $value, path: $childPath, context: $context);
        }

        return $result;
    }

    /**
     * The walk inside a schema, where every `$ref` names a schema — except under the keywords whose
     * value is data (`enum`, `default`, …) and in extensions.
     *
     * @param array{document: array<string, mixed>, sourceFile: ?string, isRootDocument: bool} $context
     */
    private function rewriteSchemaNodePointers(mixed $node, array $context, bool $namesKeys = false): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            if (!$namesKeys && (in_array($key, self::DATA_VALUED_KEYWORDS, true) || (is_string($key) && str_starts_with($key, 'x-')))) {
                $result[$key] = $value;
                continue;
            }
            if (!$namesKeys && $key === '$ref' && is_string($value)) {
                $result[$key] = $this->pointedSchemaReference(ref: $value, context: $context);
                continue;
            }
            if (!$namesKeys && $key === 'discriminator' && is_array($value) && is_array($value['mapping'] ?? null)) {
                $mapping = [];
                foreach ($value['mapping'] as $wireValue => $target) {
                    // A bare name is a component name, not a reference.
                    $isReference = is_string($target)
                        && (str_contains($target, '#') || preg_match('/\.(ya?ml|json)$/i', $target) === 1);
                    $mapping[$wireValue] = $isReference ? $this->pointedSchemaReference(ref: $target, context: $context) : $target;
                }
                $value['mapping'] = $mapping;
                $result[$key] = $value;
                continue;
            }
            $result[$key] = $this->rewriteSchemaNodePointers(
                node: $value,
                context: $context,
                namesKeys: !$namesKeys && in_array($key, self::NAME_KEYED_KEYWORDS, true),
            );
        }

        return $result;
    }

    /**
     * The `$ref` in the spelling the generator resolves: a component pointer, in the same file.
     *
     * @param array{document: array<string, mixed>, sourceFile: ?string, isRootDocument: bool} $context
     */
    private function pointedSchemaReference(string $ref, array $context): string
    {
        $ref = $this->stripDocumentSelfPrefix($ref);
        $hash = strpos($ref, '#');
        $filePart = $hash === false ? $ref : substr($ref, 0, $hash);
        $pointer = $hash === false ? '' : rawurldecode(substr($ref, $hash + 1));

        // An anchor, a remote URI and a component are someone else's to resolve.
        $isAnchor = $pointer !== '' && !str_starts_with($pointer, '/');
        $isRemote = preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $filePart) === 1;
        if ($isAnchor || $isRemote || str_starts_with($pointer, '/components/schemas/')) {
            return $ref;
        }

        if ($filePart === '') {
            if ($pointer === '' && $context['isRootDocument']) {
                // The whole OpenAPI document is not a schema; left as it was.
                return $ref;
            }
            if ($context['isRootDocument'] || $context['sourceFile'] === null) {
                return '#/components/schemas/' . $this->localPointedSchema(ref: $ref, pointer: $pointer, context: $context);
            }

            return '#/components/schemas/' . $this->pointedSchemaComponent(
                file: $context['sourceFile'],
                pointer: $pointer,
                ref: $ref,
            );
        }

        if ($context['sourceFile'] === null) {
            // A document given as an array names no directory its files could be read from.
            return $ref;
        }
        $resolved = $this->resolveExternalSchemaPointer(
            ref: $filePart . '#/components/schemas/',
            currentSourceFile: $context['sourceFile'],
            reportedRef: $ref,
        );
        if ($resolved === null) {
            return $ref;
        }

        return $filePart . '#/components/schemas/' . $this->pointedSchemaComponent(file: $resolved[0], pointer: $pointer, ref: $ref);
    }

    /**
     * A pointer into the document being rewritten, which is not cached anywhere yet: its target is
     * lifted into the document's own components.
     *
     * @param array{document: array<string, mixed>, sourceFile: ?string, isRootDocument: bool} $context
     */
    private function localPointedSchema(string $ref, string $pointer, array $context): string
    {
        if (array_key_exists($pointer, $this->localPointedSchemas)) {
            return $this->localPointedSchemas[$pointer]['name'];
        }
        $schema = $this->schemaAtPointer(document: $context['document'], pointer: $pointer, ref: $ref);
        $taken = array_keys(is_array($context['document']['components']['schemas'] ?? null) ? $context['document']['components']['schemas'] : []);
        foreach ($this->localPointedSchemas as $lifted) {
            $taken[] = $lifted['name'];
        }
        $name = $this->pointedSchemaName(pointer: $pointer, file: null, taken: $taken);
        // Named before its own references are rewritten, so one that leads back here terminates.
        $this->localPointedSchemas[$pointer] = ['name' => $name, 'schema' => $schema];
        $this->localPointedSchemas[$pointer]['schema'] = $this->rewriteSchemaNodePointers(node: $schema, context: $context);

        return $name;
    }

    /**
     * The component a schema of another file — the file whole, or the place a pointer names in it —
     * is generated as; added to that file's components the first time.
     */
    private function pointedSchemaComponent(string $file, string $pointer, string $ref): string
    {
        if (array_key_exists($pointer, $this->pointedSchemaComponents[$file] ?? [])) {
            return $this->pointedSchemaComponents[$file][$pointer];
        }

        $declared = $this->externalSchemasOf($file);
        // Reading the file may have reached this very pointer from inside it.
        $knownAfterReading = $this->pointedSchemaComponentsOf($file);
        if (array_key_exists($pointer, $knownAfterReading)) {
            return $knownAfterReading[$pointer];
        }
        $document = $this->externalDocuments[$file] ?? [];
        if ($pointer === '') {
            if (array_key_exists('openapi', $document) || array_key_exists('swagger', $document) || array_key_exists('paths', $document)) {
                throw new RuntimeException(sprintf(
                    'Unresolvable reference "%s": the file is an OpenAPI document, not a schema. Point at one of its schemas instead.',
                    $ref,
                ));
            }
            // A JSON Schema document whose `$defs` were folded into components: those stay components.
            $schema = [];
            foreach ($document as $keyword => $value) {
                if ($keyword !== 'components') {
                    $schema[$keyword] = $value;
                }
            }
        } else {
            $schema = $this->schemaAtPointer(document: $document, pointer: $pointer, ref: $ref);
        }

        $name = $this->pointedSchemaName(pointer: $pointer, file: $file, taken: array_map(strval(...), array_keys($declared)));
        $this->pointedSchemaComponents[$file][$pointer] = $name;
        $context = ['document' => $document, 'sourceFile' => $file, 'isRootDocument' => false];
        $schema = $this->normalizeEnumSpelling($this->rewriteSchemaNodePointers(node: $schema, context: $context));

        $this->externalDocSchemas[$file][$name] = $schema;
        $components = is_array($this->externalDocuments[$file]['components'] ?? null) ? $this->externalDocuments[$file]['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        $schemas[$name] = $schema;
        $components['schemas'] = $schemas;
        $this->externalDocuments[$file]['components'] = $components;

        return $name;
    }

    /**
     * @param array<string, array{name: string, schema: mixed}> $replacement
     * @return array<string, array{name: string, schema: mixed}>
     */
    private function swapLocalPointedSchemas(array $replacement): array
    {
        $previous = $this->localPointedSchemas;
        $this->localPointedSchemas = $replacement;

        return $previous;
    }

    /**
     * @return array<string, string>
     */
    private function pointedSchemaComponentsOf(string $file): array
    {
        return $this->pointedSchemaComponents[$file] ?? [];
    }

    /**
     * @param array<mixed> $document
     * @return array<mixed>
     */
    private function schemaAtPointer(array $document, string $pointer, string $ref): array
    {
        $node = $document;
        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $node = null;
                break;
            }
            $node = $node[$segment];
        }
        if (is_bool($node)) {
            return self::booleanSchemaAsObject($node);
        }
        if (!is_array($node)) {
            throw new RuntimeException(sprintf('Unresolvable reference "%s": the pointer leads to no schema.', $ref));
        }

        return $node;
    }

    /**
     * A class name for a pointed schema: the file's name for a whole file, the last segment of the
     * pointer otherwise — the whole path when that segment is a keyword or an index. Never one the
     * document already uses, in any case.
     *
     * @param list<string> $taken
     */
    private function pointedSchemaName(string $pointer, ?string $file, array $taken): string
    {
        $words = static function (string $text): string {
            $parts = preg_split('/[^A-Za-z0-9]+/', $text);

            return implode('', array_map(ucfirst(...), $parts === false ? [] : $parts));
        };
        $segments = array_map(
            static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array_slice(explode('/', $pointer), 1),
        );
        if ($pointer === '') {
            $base = $words(pathinfo((string)preg_replace('/\.(dist|example|local|sample)$/i', '', basename((string)$file)), PATHINFO_FILENAME));
        } else {
            $last = (string)end($segments);
            $structural = ['schema', 'items', 'not', 'additionalProperties', 'contains', 'if', 'then', 'else', 'propertyNames'];
            $base = in_array($last, $structural, true) || preg_match('/^\d*$/', $last) === 1
                ? implode('', array_map($words, $segments))
                : $words($last);
        }
        $base = $base === '' || ctype_digit($base[0]) ? 'Pointed' . $base : $base;

        $folded = array_map(strtolower(...), $taken);
        foreach ($this->pointedSchemaComponents[(string)$file] ?? [] as $name) {
            $folded[] = strtolower($name);
        }
        $name = $base;
        $suffix = 2;
        while (in_array(strtolower($name), $folded, true)) {
            $name = $base . $suffix++;
        }

        return $name;
    }

    /**
     * Returns the schema name when the pointer addresses a top-level schema exactly
     * (#/components/schemas/Name), or null for an internal pointer or a deeper pointer
     * (e.g. #/components/schemas/Name/properties/id).
     */
    private function externalPointerSchemaName(string $pointer): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($pointer, $prefix)) {
            return null;
        }

        $path = substr($pointer, strlen($prefix));
        if ($path === '' || str_contains($path, '/')) {
            return null;
        }

        return $path;
    }

    /**
     * Walk a JSON pointer (#/components/schemas/Name/properties/x/...) inside an external document
     * and return the addressed node, or null when it cannot be resolved.
     */
    private function resolveExternalPointerNode(string $filePath, string $pointer): mixed
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($pointer, $prefix)) {
            return null;
        }

        $segments = explode('/', substr($pointer, strlen($prefix)));
        $schemaName = array_shift($segments);
        if ($schemaName === '') {
            return null;
        }

        $node = $this->externalSchemasOf($filePath)[$schemaName] ?? null;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isScalarSchemaDefinition(array $schema): bool
    {
        if (array_key_exists('$ref', $schema)) {
            return false;
        }

        return in_array($schema['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            && !array_key_exists('properties', $schema)
            && !array_key_exists('items', $schema);
    }

    /**
     * Inline (B): when a $ref points deeper than a top-level external schema and the target is a
     * scalar, return that scalar sub-schema so the caller can treat it as if it were declared inline.
     * Avoids dragging the whole external document in just to reuse a primitive property type.
     *
     * @return array<string, mixed>|null
     */
    private function tryInlineExternalScalarRef(string $ref, ?string $currentSourceFile): ?array
    {
        if (str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $currentSourceFile);
        if ($resolved === null) {
            return null;
        }

        [$externalFile, $pointer] = $resolved;
        if ($this->externalPointerSchemaName($pointer) !== null) {
            return null;
        }

        $node = $this->resolveExternalPointerNode($externalFile, $pointer);

        return is_array($node) && $this->isScalarSchemaDefinition($node) ? $node : null;
    }

    /**
     * Register a single schema from an external document (plus its transitive refs), instead of
     * registering every schema declared in that file.
     */
    private function registerExternalSchema(string $externalFile, string $schemaName): void
    {
        $reference = $externalFile . '#/components/schemas/' . $schemaName;
        if (array_key_exists($reference, $this->registeredExternalSchemas)) {
            return;
        }
        $className = $this->schemaClassName($schemaName);

        $definition = $this->externalSchemasOf($externalFile)[$schemaName] ?? null;
        if (!is_array($definition)) {
            // The pointer names a schema the external document does not define. Returning quietly
            // here is what made a typo invisible: the CALLER has already turned the reference into a
            // TYPE NAME, so the emitted class type-hints a class nobody generates. That output passes
            // `php -l` and autoloading and dies on the first request with
            // `must be of type …\Missing`. A reference that resolves to nothing is a broken document,
            // and the place to say so is here, not on a staging server.
            throw new RuntimeException(sprintf(
                'Referenced schema "%s" not found in %s. The reference resolves to a file that does '
                . 'not define it, so the generated code would name a class that is never emitted.',
                $schemaName,
                $externalFile,
            ));
        }

        // Mark before walking children so local and cross-document cycles terminate.
        $this->registeredExternalSchemas[$reference] = true;

        if ($this->namesExternalSchemaOfItsOwnName($definition, $schemaName)) {
            $aliasRef = $definition['$ref'];
            if (is_string($aliasRef)) {
                $this->ensureSchemaRefRegistered(ref: $aliasRef, currentSourceFile: $externalFile);
            }
            return;
        }

        $this->registerSchema(
            className: $className,
            schemaDefinition: $definition,
            sourceFile: $externalFile,
            componentName: $schemaName,
        );
        $this->scanExternalSchemaRefs(node: $definition, currentSourceFile: $externalFile);
    }

    /**
     * The mapping a `discriminator` implies when the document does not spell one out.
     *
     * OAS: the implicit value for each member is the schema name the `$ref` points at, so
     * `oneOf: [$ref Cat, $ref Dog]` with `propertyName: kind` means `kind: "Cat"` selects `Cat`. Only
     * `$ref` members can take part — an inline branch has no name to be selected by, and a document
     * mixing the two needs an explicit `mapping` for the inline half.
     *
     * @param array<mixed> $schemaDefinition
     * @return array<string, string>
     */
    private function implicitDiscriminatorMapping(array $schemaDefinition): array
    {
        $mapping = [];

        foreach (['oneOf', 'anyOf', 'allOf'] as $unionKey) {
            $members = $schemaDefinition[$unionKey] ?? null;
            if (!is_array($members)) {
                continue;
            }

            foreach ($members as $member) {
                if (!is_array($member) || !is_string($member['$ref'] ?? null)) {
                    continue;
                }

                $ref = $member['$ref'];
                $name = substr($ref, (int)strrpos($ref, '/') + 1);
                if ($name !== '') {
                    $mapping[$name] = $ref;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     */
    private function collectDiscriminatorMetadata(string $className, array $schemaDefinition): void
    {
        $discriminator = $schemaDefinition['discriminator'] ?? null;
        if (!is_array($discriminator)) {
            return;
        }

        $propertyName = $discriminator['propertyName'] ?? null;
        $mapping = $discriminator['mapping'] ?? null;

        if (!is_string($propertyName) || trim($propertyName) === '') {
            throw new RuntimeException(
                message: sprintf('Discriminator propertyName must be a non-empty string in %s.', $className),
            );
        }

        // An ABSENT mapping is not an error. OAS says the implicit mapping is the schema NAME behind each
        // `$ref` of the union, and documents lean on that — writing `mapping` out is the exception, not
        // the rule. Refusing it stopped generation dead on a document that is perfectly valid; the
        // implicit map is built here from the union's own members. A mapping that IS written wins, and
        // an EMPTY one written by hand is still an error, because it says "no member matches anything".
        if ($mapping === null) {
            $mapping = $this->implicitDiscriminatorMapping($schemaDefinition);

            // An object base has no union members to name: its variants point at it, each through an
            // `allOf`, which is the polymorphism example of the specification itself. They are not all
            // registered yet, so the map is built once they are — `resolvePendingAllOfDiscriminators()`.
            if ($mapping === [] && !$this->hasUnionMembers($schemaDefinition)) {
                $this->pendingAllOfDiscriminators[$className] = $propertyName;

                return;
            }
        }

        if (!is_array($mapping) || $mapping === []) {
            throw new RuntimeException(sprintf('Discriminator mapping must be a non-empty map in %s.', $className));
        }

        $normalizedMapping = [];
        $targetToSource = [];

        foreach ($mapping as $mappingKey => $ref) {
            // A numeric key — `{"1": Red}`, valid OpenAPI — is an integer once the document is decoded.
            // The tag on the wire is still the string, and every consumer compares it as one.
            $mappingValue = (string)$mappingKey;
            if ($mappingValue === '') {
                throw new RuntimeException(
                    message: sprintf('Discriminator mapping key must be a non-empty string in %s.', $className),
                );
            }

            if (!is_string($ref)) {
                throw new RuntimeException(
                    message: sprintf(
                        'Discriminator mapping value for "%s" in %s must be a schema $ref string.',
                        $mappingValue,
                        $className,
                    ),
                );
            }

            $targetClass = $this->schemaRefToClassName(
                ref: $ref,
                currentSourceFile: $this->getSchemaSourceFile($className),
            );
            if ($targetClass === 'mixed') {
                throw new RuntimeException(
                    message: sprintf(
                        'Discriminator mapping value for "%s" in %s must reference #/components/schemas/*.',
                        $mappingValue,
                        $className,
                    ),
                );
            }

            if (array_key_exists($targetClass, $targetToSource)) {
                throw new RuntimeException(
                    message: sprintf(
                        'Discriminator mapping in %s has duplicate target "%s" for values "%s" and "%s".',
                        $className,
                        $targetClass,
                        $targetToSource[$targetClass],
                        $mappingValue,
                    ),
                );
            }

            $targetToSource[$targetClass] = $mappingValue;
            $normalizedMapping[$mappingValue] = $targetClass;
        }

        $this->discriminatorSchemas[$className] = [
            'propertyName' => $propertyName,
            'mapping' => $normalizedMapping,
        ];
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function hasUnionMembers(array $schemaDefinition): bool
    {
        return is_array($schemaDefinition['oneOf'] ?? null) || is_array($schemaDefinition['anyOf'] ?? null);
    }

    /**
     * The implicit mapping of an object base whose discriminator names no variant: OAS makes each schema
     * that `allOf`s the base a variant, selected by its own schema name. Runs once every schema is
     * registered, before anything asks which class extends which.
     */
    private function resolvePendingAllOfDiscriminators(): void
    {
        foreach ($this->pendingAllOfDiscriminators as $baseClass => $propertyName) {
            $mapping = [];
            foreach ($this->dtoSchemas as $candidate => $definition) {
                foreach (is_array($definition['allOf'] ?? null) ? $definition['allOf'] : [] as $branch) {
                    if (!is_array($branch) || !is_string($branch['$ref'] ?? null)) {
                        continue;
                    }
                    $parent = $this->schemaRefToClassName(
                        ref: $branch['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($candidate),
                    );
                    if ($parent === $baseClass) {
                        $mapping[$this->componentNames[$candidate] ?? $candidate] = $candidate;
                        break;
                    }
                }
            }

            if ($mapping === []) {
                throw new RuntimeException(sprintf('Discriminator mapping must be a non-empty map in %s.', $baseClass));
            }

            $this->discriminatorSchemas[$baseClass] = ['propertyName' => $propertyName, 'mapping' => $mapping];
        }
        $this->pendingAllOfDiscriminators = [];
    }

    /**
     * Find the discriminator base class among a variant's allOf $refs.
     *
     * Returns the referenced class that declares a discriminator whose mapping lists
     * $variantClass as a target, so the variant can `extends` it. Returns null when no such
     * ref exists, or when more than one qualifies (ambiguous — fall back to the ref-count rule).
     *
     * @param array<int, string> $refClassNames
     */
    private function findDiscriminatorBaseAmongRefs(string $variantClass, array $refClassNames): ?string
    {
        $matches = [];
        foreach ($refClassNames as $refClassName) {
            $meta = $this->discriminatorSchemas[$refClassName] ?? null;
            if ($meta === null) {
                continue;
            }

            if (in_array($variantClass, $meta['mapping'], true)) {
                $matches[$refClassName] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * The parent of an `allOf` with exactly one `$ref` — the historical inheritance rule — unless that
     * `$ref` names a union INTERFACE: a class cannot extend one, and `final class Sepia extends Plum`
     * was a fatal at load. Such a member is merged instead (it declares no property), and its union is
     * normally lifted beside the `allOf` before this runs (`hoistUnionMembersOfComponentAllOf()`).
     *
     * @param array<int, string> $refClassNames
     */
    private function singleRefParent(array $refClassNames): ?string
    {
        if (count($refClassNames) !== 1 || $this->rendersAsUnionInterface($refClassNames[0])) {
            return null;
        }

        return $refClassNames[0];
    }

    /**
     * Whether a component is emitted as a union interface — a union that is neither a value alias nor
     * a discriminator base (that one is an abstract class).
     */
    private function rendersAsUnionInterface(string $className): bool
    {
        $schema = $this->dtoSchemas[$className] ?? null;

        return is_array($schema)
            && $this->rendersAsUnion($schema)
            && !$this->isScalarAliasSchema($schema)
            && !array_key_exists($className, $this->discriminatorSchemas);
    }

    /**
     * Find the discriminator base a schema is a mapping target of, regardless of how it composes.
     * Used to link oneOf/anyOf-discriminator members (plain objects that carry no allOf $ref back
     * to the base) to their abstract base via `extends`. Returns null when the class is not a
     * mapping target, or is a target of more than one base (ambiguous).
     */
    private function discriminatorBaseForMember(string $memberClass): ?string
    {
        $matches = [];
        foreach ($this->discriminatorSchemas as $baseClass => $meta) {
            if ($baseClass === $memberClass) {
                continue;
            }
            if (in_array($memberClass, $meta['mapping'], true)) {
                $matches[$baseClass] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * True when a schema is a discriminator base expressed with `oneOf`/`anyOf` (the composition
     * pattern). Such a base has no shared properties to inherit, so it is emitted as an abstract
     * class carrying the discriminator methods, and its members `extends` it. (The allOf pattern,
     * by contrast, makes the base a concrete class the variants already extend.)
     */
    private function isOneOfDiscriminatorBase(string $className): bool
    {
        if (!array_key_exists($className, $this->discriminatorSchemas)) {
            return false;
        }

        $schema = $this->dtoSchemas[$className] ?? null;

        return is_array($schema)
            && (
                (array_key_exists('oneOf', $schema) && is_array($schema['oneOf']))
                || (array_key_exists('anyOf', $schema) && is_array($schema['anyOf']))
            );
    }

    /**
     * The class a schema's `allOf` would make its parent on its own — the rule `analyzeSchema()`
     * applies before a discriminated union claims the member: the discriminator base among the refs,
     * else the single ref. Null for a schema without `allOf`, or one whose refs are merged.
     */
    private function ownAllOfParent(string $className): ?string
    {
        $schema = $this->dtoSchemas[$className] ?? null;
        if (!is_array($schema) || !is_array($schema['allOf'] ?? null)) {
            return null;
        }
        $refClassNames = [];
        foreach ($schema['allOf'] as $allOfItem) {
            if (is_array($allOfItem) && is_string($allOfItem['$ref'] ?? null)) {
                $refClassNames[] = $this->schemaRefToClassName(
                    ref: $allOfItem['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
            }
        }

        return $this->findDiscriminatorBaseAmongRefs($className, $refClassNames) ?? $this->singleRefParent($refClassNames);
    }

    /**
     * Runtime: the parent class of a `oneOf` / `anyOf` discriminator base — the `allOf` parent every one
     * of its members names, so `Tile <- AnyTile <- StarTile` keeps each member an instance of both.
     *
     * The members extend the abstract base, and a class has one parent: an `allOf: [$ref Tile, …]` member
     * was emitted `extends AnyTile`, its `Tile` dropped, and code that typed with `Tile` broke at load.
     * Null when the members name no common parent (each keeps its own then, and is no member class of
     * the base — {@see memberExtendsUnionBase()}), or in a flattened mode, where no parent is kept.
     */
    private function unionBaseParent(string $baseClass): ?string
    {
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME || !$this->isOneOfDiscriminatorBase($baseClass)) {
            return null;
        }
        $parents = [];
        foreach ($this->discriminatorSchemas[$baseClass]['mapping'] as $memberClass) {
            $parents[(string)$this->ownAllOfParent($memberClass)] = true;
        }
        $parent = count($parents) === 1 ? array_key_first($parents) : '';
        // A parent that is a discriminator base itself declares a discriminator of its own, which the
        // base's would shadow for every member: the members keep that parent instead.
        if ($parent === '' || !array_key_exists($parent, $this->dtoSchemas) || array_key_exists($parent, $this->discriminatorSchemas)) {
            return null;
        }
        // The base may not sit above its own parent: `AnyTile extends Tile extends AnyTile` is a cycle.
        $ancestor = $parent;
        for ($guard = 0; $ancestor !== null && $guard < 32; $guard++) {
            if ($ancestor === $baseClass) {
                return null;
            }
            $ancestor = $this->ownAllOfParent($ancestor);
        }

        return $parent;
    }

    /**
     * Whether a mapping target of a `oneOf` / `anyOf` discriminator base is emitted `extends` that base.
     *
     * Runtime keeps a member out when linking it would change the parent it has on its own:
     * - its `allOf` parent is not the base's own parent ({@see unionBaseParent()}), so extending the base
     *   would drop it;
     * - it is a class of another document written where that document's own run writes it too (a
     *   `--ref` placement): that run knows nothing of this union, and the two runs must agree on it.
     * Such a member keeps its parent; a property typed with the base is spelled as the union of its
     * members and dispatched by the tag ({@see inlineSharedMemberUnionRef()}), as for a member two
     * unions share.
     */
    private function memberExtendsUnionBase(string $memberClass, string $baseClass): bool
    {
        if ($this->discriminatorBaseForMember($memberClass) !== $baseClass || !$this->isOneOfDiscriminatorBase($baseClass)) {
            return false;
        }
        if ($this->memberIsWrittenByItsOwnDocument($memberClass, $baseClass)) {
            return false;
        }
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME) {
            return true;
        }
        $ownParent = $this->ownAllOfParent($memberClass);

        return $ownParent === null || $ownParent === $this->unionBaseParent($baseClass);
    }

    /**
     * Whether a union member is a class of another document written where that document's own run
     * writes it too (a `--ref` placement, the `common` convention). That run knows nothing of the union,
     * so the member may not name it — no `extends`, no `implements` — or the two runs write two classes
     * into one file, and whichever wins breaks the other's holders.
     */
    private function memberIsWrittenByItsOwnDocument(string $memberClass, string $baseClass): bool
    {
        return $this->getSchemaSourceFile($memberClass) !== $this->getSchemaSourceFile($baseClass)
            && $this->isGeneratedIntoASharedPlace($memberClass);
    }

    private function expandNestedSchemas(): void
    {
        $processed = [];

        while (true) {
            $unprocessed = array_diff(array_keys($this->dtoSchemas), array_keys($processed));
            if ($unprocessed === []) {
                return;
            }

            foreach ($unprocessed as $className) {
                $processed[$className] = true;
                $schemaDefinition = $this->dtoSchemas[$className];
                // Its references resolve against the document that declares it, as when it is rendered.
                $this->constraintSourceFile = $this->getSchemaSourceFile($className);
                try {
                    $this->collectNestedFromSchema(ownerClassName: $className, schemaDefinition: $schemaDefinition);
                } finally {
                    $this->constraintSourceFile = null;
                }
            }
        }
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function collectNestedFromSchema(string $ownerClassName, array $schemaDefinition): void
    {
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem)) {
                    continue;
                }

                $this->collectNestedFromObjectSchema($ownerClassName, $allOfItem);
            }

            return;
        }

        $this->collectNestedFromObjectSchema($ownerClassName, $schemaDefinition);
    }

    /**
     * @param array<mixed> $schemaDefinition
     */
    private function collectNestedFromObjectSchema(string $ownerClassName, array $schemaDefinition): void
    {
        $properties = $schemaDefinition['properties'] ?? null;
        if (!is_array($properties)) {
            return;
        }

        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_array($propertySchema)) {
                continue;
            }

            $this->resolvePropertyType(
                propertySchema: $this->withAliasesInlined($propertySchema, $ownerClassName),
                ownerClassName: $ownerClassName,
                propertyName: (string)$propertyName,
            );
        }
    }

    private function detectParentClasses(): void
    {
        foreach ($this->dtoSchemas as $className => $schemaDefinition) {
            // A oneOf/anyOf discriminator base is an abstract parent its members extend.
            if ($this->isOneOfDiscriminatorBase($className)) {
                $this->parentClasses[$className] = true;
            }

            if (!array_key_exists('allOf', $schemaDefinition) || !is_array($schemaDefinition['allOf'])) {
                continue;
            }

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem) || !array_key_exists('$ref', $allOfItem) || !is_string($allOfItem['$ref'])) {
                    continue;
                }

                $parentClass = $this->schemaRefToClassName(
                    ref: $allOfItem['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
                // An interface is never extended — see `singleRefParent()`.
                if ($this->rendersAsUnionInterface($parentClass)) {
                    continue;
                }
                $this->parentClasses[$parentClass] = true;
            }
        }
    }

    private function detectUnionInterfaces(): void
    {
        // Worklist to a fixpoint: collectUnionTypes may register new nested-union schemas
        // (inline oneOf/anyOf branches), which must in turn have their own members linked.
        // A plain foreach over dtoSchemas would not revisit schemas added mid-iteration.
        $processed = [];

        while (true) {
            $unprocessed = array_diff(array_keys($this->dtoSchemas), array_keys($processed));
            if ($unprocessed === []) {
                $this->assertUnionMembersImplementInterfaces();

                return;
            }

            foreach ($unprocessed as $schemaName) {
                $processed[$schemaName] = true;
                $schemaDefinition = $this->dtoSchemas[$schemaName];
                // A union that is an alias of a value gets no file, so nothing may implement it.
                if (!$this->rendersAsUnion($schemaDefinition) || $this->isScalarAliasSchema($schemaDefinition)) {
                    continue;
                }

                // A oneOf/anyOf schema with a discriminator is an abstract base, not a marker
                // interface: its members extend it (see analyzeSchema), so skip interface linking.
                if (array_key_exists($schemaName, $this->discriminatorSchemas)) {
                    continue;
                }

                $className = $this->schemaClassName($schemaName);

                foreach (
                    $this->collectUnionTypes(
                        ownerClassName: $className,
                        variants: $schemaDefinition['oneOf'] ?? [],
                        keyword: 'oneOf',
                    ) as $unionClass
                ) {
                    $this->unionInterfacesByClass[$unionClass][] = $className;
                }

                foreach (
                    $this->collectUnionTypes(
                        ownerClassName: $className,
                        variants: $schemaDefinition['anyOf'] ?? [],
                        keyword: 'anyOf',
                    ) as $unionClass
                ) {
                    $this->unionInterfacesByClass[$unionClass][] = $className;
                }
            }
        }
    }

    /**
     * Every `implements` the members will be emitted with names a union INTERFACE.
     *
     * A member that implements a class is not a validation gap but a file PHP refuses to link: `X cannot
     * implement Y - it is not an interface` is a fatal error at class load, which no `catch` in the
     * application sees and which takes the request down with it. rendersAsUnion() is what keeps that
     * from happening; this is the net under it, so a shape it does not foresee stops generation with a
     * sentence instead of writing classes that cannot load.
     */
    private function assertUnionMembersImplementInterfaces(): void
    {
        foreach ($this->unionInterfacesByClass as $memberClass => $interfaces) {
            foreach ($interfaces as $interface) {
                $schema = $this->dtoSchemas[$interface] ?? null;
                if (is_array($schema) && $this->rendersAsUnion($schema) && !array_key_exists($interface, $this->discriminatorSchemas)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Union member %s would implement %s, which is not generated as an interface: the classes '
                    . 'could not be loaded. Give the union a schema of its own and reference it.',
                    $memberClass,
                    $interface,
                ));
            }
        }
    }

    /**
     * Whether a registered schema becomes a union INTERFACE rather than a class — the one answer
     * analyzeSchema() gives, asked before it runs.
     *
     * An `allOf` beside the `oneOf`/`anyOf` makes the schema a CLASS: analyzeSchema() merges the allOf
     * branches into properties and never reaches the union arms. Linking the union members anyway made
     * each of them `implements` that class — `X cannot implement Y - it is not an interface`, a fatal on
     * load that no `catch` sees — and laravel mode, asking the same question its own way, wrote a
     * "union with no discriminator" throw for a class that hydrates itself. The union stays what it is
     * for a class: a validation constraint (see extractValidationConstraints()).
     *
     * @param array<mixed> $schemaDefinition
     */
    private function rendersAsUnion(array $schemaDefinition): bool
    {
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            return false;
        }
        if ($this->hasClassLevelUnionArmsOnly($schemaDefinition)) {
            return false;
        }

        return (array_key_exists('oneOf', $schemaDefinition) && is_array($schemaDefinition['oneOf']))
            || (array_key_exists('anyOf', $schemaDefinition) && is_array($schemaDefinition['anyOf']));
    }

    /**
     * Keywords a `oneOf` / `anyOf` arm may hold and still say nothing about WHAT the value is — only
     * which of the keys declared beside the union it must carry.
     */
    private const array CLASS_LEVEL_UNION_ARM_KEYWORDS = [
        'required', 'dependentRequired', 'minProperties', 'maxProperties', 'description', 'title',
    ];

    /**
     * An object with `properties` of its own whose `oneOf` / `anyOf` arms only say which of them are
     * required — `{properties: {red, blue}, anyOf: [{required: [red]}, {required: [blue]}]}`. The arms
     * name no type and no class, so the schema is the CLASS of its properties, and the union is a check
     * on that object as a whole. Read as a union instead, the class had no properties at all in runtime
     * mode — every key dropped, `{}` accepted — and Symfony kept the properties but lost the arms.
     *
     * @param array<mixed> $schemaDefinition
     */
    private function hasClassLevelUnionArmsOnly(array $schemaDefinition): bool
    {
        if (!is_array($schemaDefinition['properties'] ?? null) || $schemaDefinition['properties'] === []) {
            return false;
        }
        $arms = 0;
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schemaDefinition[$keyword] ?? null;
            if ($branches === null) {
                continue;
            }
            if (!is_array($branches) || $branches === []) {
                return false;
            }
            foreach ($branches as $branch) {
                if (!is_array($branch) || !$this->isClassLevelUnionArm($branch)) {
                    return false;
                }
                $arms++;
            }
        }

        return $arms > 0;
    }

    /**
     * One arm `hasClassLevelUnionArmsOnly()` accepts: no `type`, no `$ref` — only requirements, or
     * `properties` that narrow a key without shaping a class of their own
     * (`{properties: {size: {maximum: 3}}}`). Such an arm is an assertion on the object the schema
     * already is; read as a union member, it became a class of its own, the owner a union interface
     * without its properties, and a requirement-only sibling arm was dropped from the union.
     * An arm with `type: object` stays a union member — that is a class, not a fragment.
     *
     * @param array<mixed> $branch
     */
    private function isClassLevelUnionArm(array $branch): bool
    {
        if ($branch === []) {
            return false;
        }
        foreach ($branch as $keyword => $value) {
            if (in_array($keyword, self::CLASS_LEVEL_UNION_ARM_KEYWORDS, true)) {
                continue;
            }
            if ($keyword !== 'properties' || !is_array($value) || $value === []) {
                return false;
            }
            foreach ($value as $fragment) {
                if (!is_array($fragment) || !$this->isPropertyConstraintFragment($fragment)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A property subschema inside a class-level arm that only constrains the value — a scalar `type`,
     * bounds, `enum`, `const`, `pattern` — and names no class: no `$ref`, no object or array shape, no
     * nested composition.
     *
     * @param array<mixed> $fragment
     */
    private function isPropertyConstraintFragment(array $fragment): bool
    {
        foreach (['$ref', 'properties', 'items', 'allOf', 'anyOf', 'oneOf', 'additionalProperties', 'patternProperties'] as $shaping) {
            if (array_key_exists($shaping, $fragment)) {
                return false;
            }
        }
        $types = $fragment['type'] ?? [];

        return !in_array('object', (array)$types, true) && !in_array('array', (array)$types, true);
    }

    /**
     * @return array<int, string>
     */
    private function collectUnionTypes(string $ownerClassName, mixed $variants, string $keyword): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $result = [];

        foreach (array_values($variants) as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            if (array_key_exists('$ref', $variant) && is_string($variant['$ref'])) {
                $result[] = $this->schemaRefToClassName(
                    ref: $variant['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                continue;
            }

            // A nested union that is no union of its own — one object `$ref`, nullable or not:
            // `oneOf: [{anyOf: [$ref Amber]}, $ref Scarlet]` — gets no interface file (it is an alias of
            // that object), so registered as one its member implemented nothing: Amber was not an
            // Olive, the runtime never built it, and the docblock named a class that does not exist.
            // Its `$ref`s are members of THIS union, as a bare `$ref` branch is.
            $nestedReferences = $this->nestedAliasUnionReferences($variant);
            if ($nestedReferences !== null) {
                foreach ($nestedReferences as $nestedReference) {
                    $result[] = $this->schemaRefToClassName(
                        ref: $nestedReference,
                        currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                    );
                }
                continue;
            }

            // A branch that is itself a nested union (inline oneOf/anyOf) must become its own
            // union interface; otherwise its members are silently dropped from this union.
            // Register it as a member — detectUnionInterfaces' worklist then links the nested
            // union's own members, and the nested interface extends this owner (see the interface
            // branch in renderDtoClass).
            if (
                (array_key_exists('oneOf', $variant) && is_array($variant['oneOf']))
                || (array_key_exists('anyOf', $variant) && is_array($variant['anyOf']))
            ) {
                $nestedUnionName = $this->inlineUnionMemberClassName(ownerClassName: $ownerClassName, keyword: $keyword, index: $index);
                $this->registerSchema(
                    className: $nestedUnionName,
                    schemaDefinition: $variant,
                    sourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                $result[] = $nestedUnionName;
                continue;
            }

            // An object the branch spells by an `allOf` — `{$ref: Teal, required: [zebu]}` arrives
            // written as one — is a member class too. Skipped, the member was missing from the union
            // and every payload was refused as "Unsupported type".
            if (!$this->isObjectUnionMemberOfItsOwn($variant, $ownerClassName)) {
                continue;
            }

            $variantClassName = $this->inlineUnionMemberClassName(ownerClassName: $ownerClassName, keyword: $keyword, index: $index);
            $this->registerSchema(
                className: $variantClassName,
                schemaDefinition: $variant,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $result[] = $variantClassName;
        }

        return $result;
    }

    /**
     * The class `collectUnionTypes()` registers an inline branch of a union under — an object, an
     * `allOf` object or a nested union: the owner's name, `OneOf` / `AnyOf`, and the branch's 1-based
     * position (`RedAnyOf2`).
     */
    private function inlineUnionMemberClassName(string $ownerClassName, string $keyword, int $index): string
    {
        return $ownerClassName . ($keyword === 'oneOf' ? 'OneOf' : 'AnyOf') . ($index + 1);
    }

    /**
     * Whether a union branch is an object `collectUnionTypes()` makes a member class of its own: an
     * inline object, or one an `allOf` (a `$ref` with siblings is written as one) merges into a class.
     *
     * @param array<mixed> $variant
     */
    private function isObjectUnionMemberOfItsOwn(array $variant, string $ownerClassName): bool
    {
        /** @var array<string, mixed> $variant */
        return $this->isInlineObjectVariant($variant) || $this->unionBranchTypeOfItsOwn($variant, $ownerClassName) === 'class';
    }

    /**
     * The `$ref`s of a nested union branch that renders no interface of its own — a union that is an
     * alias (`isScalarAliasSchema()`: one object member beside `null` at most) — or null for any other
     * branch, which keeps its own interface. An inline object member is left to that path.
     *
     * @param array<mixed> $variant
     * @return list<string>|null
     */
    private function nestedAliasUnionReferences(array $variant): ?array
    {
        $branches = $this->bareUnionBranches($variant);
        if ($branches === null || !$this->isScalarAliasSchema($variant)) {
            return null;
        }
        $references = [];
        foreach ($branches as $branch) {
            if (!is_array($branch) || $this->isNullUnionBranch($branch)) {
                continue;
            }
            $ref = $branch['$ref'] ?? null;
            if (!is_string($ref) || !$this->shapesAnObject($branch)) {
                return null;
            }
            $references[] = $ref;
        }

        return $references === [] ? null : $references;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isInlineObjectVariant(array $variant): bool
    {
        if (($variant['type'] ?? null) === 'object') {
            return true;
        }

        // OpenAPI often omits type when object-like structure is obvious.
        return array_key_exists('properties', $variant) && is_array($variant['properties']);
    }

    /**
     * The `anyOf` / `oneOf` written beside the `allOf` a class is built from, as extracted. Such a class
     * is analyzed through its `allOf` (`analyzeSchema()`), so the union beside it is not a union type:
     * it judges the object as a whole, and only a class-level check can carry it.
     *
     * @param array<string, mixed> $extracted
     * @return array<string, mixed>
     */
    private function classLevelUnionsBesideAllOf(string $className, array $extracted): array
    {
        $schema = $this->dtoSchemas[$className] ?? [];
        // Or beside the class's own properties, when the arms only name requirements — see
        // `hasClassLevelUnionArmsOnly()`: that schema is a class too.
        if ((!is_array($schema['allOf'] ?? null) || $schema['allOf'] === []) && !$this->hasClassLevelUnionArmsOnly($schema)) {
            return [];
        }
        $unions = [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null) && is_array($extracted[$keyword] ?? null)) {
                $unions[$keyword] = $this->unionHasReferenceBranch($schema[$keyword])
                    ? $this->classLevelUnionWithReferencesInlined(className: $className, keyword: $keyword, branches: $schema[$keyword])
                    : $extracted[$keyword];
            }
        }

        return $unions;
    }

    /**
     * @param array<mixed> $branches
     */
    private function unionHasReferenceBranch(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && array_key_exists('$ref', $branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A class-level union's branches with each `$ref` spelled out. The union judges the class's OWN
     * payload — a raw object no branch class was ever built from — so "is a Cat" can never hold there:
     * Symfony's callback matched no branch and refused every payload, and runtime counted each class
     * branch undecided, so a payload of both branches went through a `oneOf`.
     *
     * @param array<mixed> $branches
     */
    private function classLevelUnionWithReferencesInlined(string $className, string $keyword, array $branches): mixed
    {
        return $this->withConstraintSourceOf(
            className: $className,
            extraction: fn(): mixed => $this->extractValidationConstraints(
                $this->inlineNestedContainerValidation(schema: [$keyword => $branches], belowMaterialization: true),
            )[$keyword] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $schemaDefinition
     * @return SchemaMetadata
     */
    private function analyzeSchema(string $className, array $schemaDefinition): array
    {
        $extends = null;
        $unionTypes = [];

        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            $allProperties = [];

            // Referenced class names, in declaration order, used to detect a discriminator base.
            $refClassNames = [];
            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (is_array($allOfItem) && array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $refClassNames[] = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );
                }
            }

            // A discriminator variant is usually declared as `allOf: [$ref base, $ref mixin, ...]`.
            // The plain ref-count rule below would flatten every branch and drop the `extends base`
            // link, so `is_a($variant, $base)` fails at runtime and a property typed as the base
            // cannot hold the variant. When this schema is a mapping target of one of its own allOf
            // refs, make that ref the parent and merge the remaining branches as own properties.
            // Otherwise keep the historical rule: a single $ref means inheritance, multiple $refs
            // are merged.
            $discriminatorParent = $this->findDiscriminatorBaseAmongRefs($className, $refClassNames);
            $extends = $discriminatorParent ?? $this->singleRefParent($refClassNames);
            // A mapping target of a `oneOf` / `anyOf` discriminator base composed with `allOf` is a
            // member of that base as much as a plain object is: it extends the abstract base, and a
            // `$ref` it would otherwise extend is merged like any other branch. Unlinked, runtime
            // refused its payload — "Discriminator mapping class Kit must extend or implement Hue" —
            // and a property typed with the base could not hold it.
            // Runtime keeps the `$ref` parent: the base extends it when every member names it
            // (`unionBaseParent()`), and a member with a parent of its own does not extend the base.
            $memberBase = $discriminatorParent === null ? $this->discriminatorBaseForMember($className) : null;
            if ($memberBase !== null && $this->memberExtendsUnionBase($className, $memberBase)) {
                $extends = $memberBase;
            }
            // The base's parent arrives through the base, like the parent branch itself.
            $inheritedRef = $extends === null ? null : $this->unionBaseParent($extends);

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $refClassName = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );

                    // The parent branch is inherited (its properties arrive via getParentProperties);
                    // every other branch is merged as own properties.
                    if ($refClassName === $extends || $refClassName === $inheritedRef) {
                        continue;
                    }

                    foreach ($this->getSchemaProperties($refClassName) as $property) {
                        $allProperties[] = $property;
                    }
                    continue;
                }

                foreach (
                    $this->extractProperties(
                        schemaDefinition: $allOfItem,
                        ownerClassName: $className,
                        siblingProperties: $this->allOfBranchProperties($schemaDefinition, $className),
                    ) as $property
                ) {
                    $allProperties[] = $property;
                }
            }

            // The `properties` beside the `allOf` are the class's own as well — see `getSchemaProperties()`.
            if (is_array($schemaDefinition['properties'] ?? null) && $schemaDefinition['properties'] !== []) {
                foreach (
                    $this->extractProperties(
                        schemaDefinition: $schemaDefinition,
                        ownerClassName: $className,
                        siblingProperties: $this->allOfBranchProperties($schemaDefinition, $className),
                    ) as $property
                ) {
                    $allProperties[] = $property;
                }
            }

            $allProperties = [
                ...$allProperties,
                ...$this->unionBranchOnlyProperties(
                    schemaDefinition: $schemaDefinition,
                    className: $className,
                    declared: [...$allProperties, ...($extends === null ? [] : $this->getSchemaProperties($extends))],
                ),
            ];

            // Several branches may declare the same property: each narrows it, so they are merged, and
            // only types no value can satisfy at once are refused.
            $allProperties = $this->mergeRestatedProperties(
                properties: $this->withSiblingRequired($allProperties, $schemaDefinition),
                className: $className,
            );
            if ($extends !== null) {
                $allProperties = $this->withInheritedRestatements(
                    properties: $allProperties,
                    parentClassName: $extends,
                    schemaDefinition: $schemaDefinition,
                    className: $className,
                );
            }

            // Own properties must not collide — case-insensitively — with the inherited accessors.
            $inheritedNames = $extends === null
                ? []
                : array_map(
                    callback: static fn(array $property): string => $property['name'],
                    array: $this->getSchemaProperties($extends),
                );
            $allProperties = $this->dedupeCaseInsensitivePropertyNames($allProperties, $inheritedNames);

            return [
                'properties' => $allProperties,
                'extends' => $extends,
                'unionTypes' => [],
                'discriminator' => $this->discriminatorSchemas[$className] ?? null,
            ];
        }

        // Requirement-only arms beside the properties: a class, the arms checked at the class level.
        if ($this->hasClassLevelUnionArmsOnly($schemaDefinition)) {
            return [
                'properties' => $this->classLevelUnionObjectProperties(schemaDefinition: $schemaDefinition, className: $className),
                'extends' => null,
                'unionTypes' => [],
                'discriminator' => $this->discriminatorSchemas[$className] ?? null,
            ];
        }

        if (array_key_exists('oneOf', $schemaDefinition) && is_array($schemaDefinition['oneOf'])) {
            if (array_key_exists($className, $this->discriminatorSchemas)) {
                return $this->discriminatorBaseMetadata($className, $schemaDefinition);
            }

            $unionTypes = $this->collectUnionTypes(
                ownerClassName: $className,
                variants: $schemaDefinition['oneOf'],
                keyword: 'oneOf',
            );

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        if (array_key_exists('anyOf', $schemaDefinition) && is_array($schemaDefinition['anyOf'])) {
            if (array_key_exists($className, $this->discriminatorSchemas)) {
                return $this->discriminatorBaseMetadata($className, $schemaDefinition);
            }

            $unionTypes = $this->collectUnionTypes(
                ownerClassName: $className,
                variants: $schemaDefinition['anyOf'],
                keyword: 'anyOf',
            );

            return [
                'properties' => [],
                'extends' => null,
                'unionTypes' => $unionTypes,
                'discriminator' => null,
            ];
        }

        // A plain-object schema that is a mapping target of a oneOf/anyOf discriminator base has no
        // allOf $ref linking it to the base, so link it here: it extends the abstract base.
        $memberBase = $this->discriminatorBaseForMember($className);
        $memberExtends = ($memberBase !== null && $this->memberExtendsUnionBase($className, $memberBase)) ? $memberBase : null;

        return [
            'properties' => $this->extractProperties(schemaDefinition: $schemaDefinition, ownerClassName: $className),
            'extends' => $memberExtends,
            'unionTypes' => [],
            'discriminator' => $this->discriminatorSchemas[$className] ?? null,
        ];
    }

    /**
     * Metadata for a oneOf/anyOf discriminator base: an abstract class that carries the
     * discriminator methods (its members extend it). It has no union members and no shared
     * properties of its own; in runtime it extends the parent all its members name (`unionBaseParent()`).
     *
     * @param array<string, mixed> $schemaDefinition
     * @return SchemaMetadata
     */
    private function discriminatorBaseMetadata(string $className, array $schemaDefinition): array
    {
        return [
            'properties' => $this->extractProperties($schemaDefinition, $className),
            'extends' => $this->unionBaseParent($className),
            'unionTypes' => [],
            'discriminator' => $this->discriminatorSchemas[$className],
            'abstract' => true,
        ];
    }

    /**
     * A node the specification says is an object has to be one, and a scalar there stops generation.
     *
     * `paths: "none"`, `components: {schemas: 5}`, `properties: 7` — each was skipped in silence and
     * the run ended with `[OK] Generated 0 DTO class(es)` and exit 0. That is a SUCCESS message for a
     * document nobody could read: a green CI, an empty output directory, and no sentence connecting
     * the two.
     *
     * Absent is fine — `paths` is optional in 3.1, and an empty object is a legitimate way to say
     * "nothing here". Only a value of the wrong KIND is refused.
     *
     * Deliberately NOT a "must be a map, not a list" check. A property named with digits comes back
     * from any parser as an integer key, so `properties: {0: …, 1: …}` is a PHP list by shape while
     * being a perfectly good object in the document. Only the root is checked for that, where a
     * sequence cannot be right under any reading.
     */
    private function assertDocumentNodeIsAnObject(mixed $node, string $path): void
    {
        if ($node === null || is_array($node)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The document declares `%s` as %s; it must be an object. A value of the wrong kind is '
            . 'read as nothing at all, and generation would report success having produced nothing.',
            $path,
            get_debug_type($node),
        ));
    }

    /**
     * The document's `components.schemas`, keyed as the parser handed them over.
     *
     * `array-key`, not `string`: a component name of digits — `0`, `2024` — is legal by the
     * specification's own key pattern and comes back as an INT from YAML and JSON alike. Promising
     * `string` here was how such a name reached `schemaClassName(string …)` and killed the run with
     * an uncaught TypeError, on a document that was never wrong.
     *
     * @param array<mixed> $openApi
     * @return array<array-key, mixed>
     */
    private function extractSchemas(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? [];
        $this->assertDocumentNodeIsAnObject($schemas, 'components.schemas');

        return is_array($schemas) ? array_map($this->normalizeEnumSpelling(...), $schemas) : [];
    }

    /**
     * One spelling for a component enum: the 3.0 one, `type: string` plus `nullable: true`, which is
     * the one that becomes a backed enum. OpenAPI 3.1's `type: [string, 'null']`, and an enum with no
     * `type` at all, were taken for an object and generated as an EMPTY DTO — every value, null
     * included, then failed with "Cannot deserialize nested DTO … from non-array value".
     */
    private function normalizeEnumSpelling(mixed $schema): mixed
    {
        if (!is_array($schema) || !is_array($schema['enum'] ?? null) || $schema['enum'] === []) {
            return $schema;
        }
        $members = array_values(array_filter($schema['enum'], static fn(mixed $member): bool => $member !== null));
        $nullable = count($members) !== count($schema['enum']);

        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return $schema;
        }
        if (is_array($type)) {
            $nonNullTypes = array_values(array_filter($type, static fn(mixed $name): bool => $name !== 'null'));
            if (count($nonNullTypes) !== 1 || !in_array($nonNullTypes[0], ['string', 'integer'], true)) {
                return $schema;
            }
            $nullable = $nullable || in_array('null', $type, true);
            $type = $nonNullTypes[0];
        } elseif ($members !== [] && array_filter($members, 'is_string') === $members) {
            $type = 'string';
        } elseif ($members !== [] && array_filter($members, 'is_int') === $members) {
            $type = 'integer';
        } else {
            return $schema;
        }

        $schema['type'] = $type;
        if ($nullable) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * A schema's `required` is a list of property NAMES, and anything else is a typo worth stopping for.
     *
     * `foreach` over a non-array in PHP does nothing at all, so `required: "s"` — one missing pair of
     * brackets, and YAML invites it — produced a class where `s` was OPTIONAL. The document said the
     * field must be sent, the emitted constructor said it need not be, and nothing anywhere said the
     * two disagreed. `required: true` is the same slip made by someone thinking of a Parameter Object,
     * where `required` IS a boolean.
     *
     * The empty list stays legal: a schema may declare `required: []`, and documents do.
     *
     * Only a SCHEMA's `required` reaches here. The Parameter and Header Objects have their own
     * boolean field of that name, read elsewhere, and it is untouched by this.
     */
    private function assertSchemaRequiredIsAListOfNames(mixed $required, string $ownerClassName): void
    {
        if (!is_array($required)) {
            throw new RuntimeException(sprintf(
                'Schema "%s" declares `required` as %s; it must be a list of property names. A bare '
                . '"name" is read as no requirement at all, which silently makes the property optional.',
                $ownerClassName,
                get_debug_type($required),
            ));
        }

        foreach ($required as $name) {
            // `int` belongs here as much as `string`: a property NAMED with digits (`"1": {...}`)
            // comes back from a YAML or JSON parser as an integer key, and the document listing it
            // under `required` is equally integer. That shape is supported and tested — measured the
            // moment this check refused it. What is left to reject is a value that cannot name a
            // property at all: a boolean, a null, a nested structure.
            if (is_string($name) || is_int($name)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Schema "%s" lists %s in `required`; every entry must name a property.',
                $ownerClassName,
                get_debug_type($name),
            ));
        }
    }

    /**
     * A property schema with the aliases it names inlined — a free-form object, a scalar alias (as the
     * property, an item, a union branch or a map value), an array alias behind `allOf` — which is the
     * schema the property is typed by. `extractProperties()` and the nested-class pre-pass both read it:
     * a class the inlined schema synthesizes (the value class of a map component a property names) was
     * registered only while rendering, after the classes to write had been listed, so it was named in
     * the code and never written.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function withAliasesInlined(array $propertySchema, string $ownerClassName): array
    {
        // `allOf: [{$ref: Alias}]` is unwrapped FIRST, so every inlining below reads the plain `$ref` it
        // is: a map component behind it was unwrapped only after the map had been inlined for the bare
        // spelling, so the wrapped one stayed a `$ref` — typed `array<string, mixed>`, its values
        // unchecked, no value class, no value enum.
        $propertySchema = $this->unwrapArrayAliasAllOf($propertySchema);
        $propertySchema = $this->withoutNullTheReferenceRefuses($propertySchema);
        $propertySchema = $this->inlineFreeFormObjectRef($propertySchema);
        $propertySchema = $this->inlineSharedMemberUnionRef($propertySchema);
        $propertySchema = $this->inlineScalarAliasRef($propertySchema, $ownerClassName);
        if (is_array($propertySchema['items'] ?? null)) {
            // An `items: {$ref: Alias}` names the same alias one level down.
            $propertySchema['items'] = $this->inlineScalarAliasRef($propertySchema['items'], $ownerClassName);
        }

        return $this->inlineScalarAliasBranches($propertySchema, $ownerClassName);
    }

    /**
     * A property's `$ref` is relative to the file that declares its class, and EVERY reader of the
     * property resolves it — its type, its default, its temporal format, the null its target admits —
     * not only the constraint extraction. Read outside that file, a reference from another document
     * resolved against the root one, and a file of the same name beside the root, which does not
     * declare the schema, failed the whole generation. An inline object has no file of its own and
     * keeps the one it was found in.
     *
     * @param array<string, mixed> $schemaDefinition
     * @param array<string, string> $siblingProperties PHP name => wire name of what the `allOf` branches of the class declare
     * @return array<int, SchemaProperty>
     */
    private function extractProperties(array $schemaDefinition, string $ownerClassName, array $siblingProperties = []): array
    {
        return $this->withConstraintSourceOf(
            className: $ownerClassName,
            extraction: fn(): array => $this->extractPropertiesInOwnerFile(
                schemaDefinition: $schemaDefinition,
                ownerClassName: $ownerClassName,
                siblingProperties: $siblingProperties,
            ),
        );
    }

    /**
     * `extractProperties()` with the owner's file in force.
     *
     * @param array<string, mixed> $schemaDefinition
     * @param array<string, string> $siblingProperties PHP name => wire name of what the `allOf` branches of the class declare
     * @return array<int, SchemaProperty>
     */
    private function extractPropertiesInOwnerFile(array $schemaDefinition, string $ownerClassName, array $siblingProperties): array
    {
        $properties = $schemaDefinition['properties'] ?? [];
        $required = $schemaDefinition['required'] ?? [];

        $this->assertDocumentNodeIsAnObject($properties, sprintf('%s.properties', $ownerClassName));
        if (!is_array($properties)) {
            return [];
        }

        $this->assertSchemaRequiredIsAListOfNames($required, $ownerClassName);

        $requiredMap = [];
        foreach ($required as $requiredProperty) {
            $requiredMap[(string)$requiredProperty] = true;
        }

        $result = [];
        /** @var array<string, string> $normalizedToOpenApiName */
        $normalizedToOpenApiName = [];

        foreach ($properties as $propertyName => $propertySchema) {
            // A property whose WHOLE schema is a boolean. `true` is the empty schema and constrains
            // nothing; `false` admits no value at all, so the document is saying the key may never
            // carry one. Both were skipped here, which dropped the property from the emitted class
            // altogether — and a key the class does not declare is a key nothing checks, so
            // `{"f": "x"}` against `f: false` was accepted. `not: true` is the array spelling of
            // `false`: `true` matches every value, so "must NOT match it" holds for none.
            if (is_bool($propertySchema)) {
                $propertySchema = $propertySchema ? [] : ['not' => true];
            }

            if (!is_array($propertySchema)) {
                continue;
            }

            $openApiPropertyName = (string)$propertyName;

            $propertySchema = $this->applyDiscriminatorPropertyEnumIfNeeded(
                ownerClassName: $ownerClassName,
                propertyName: $openApiPropertyName,
                propertySchema: $propertySchema,
            );
            // Aliases are inlined FIRST: everything below reads the property's own keywords, and an
            // `enum` that arrives with the alias has to be marked for constraint validation like any
            // inline one — otherwise it is silently dropped and the values are never checked.
            $propertySchema = $this->withAliasesInlined($propertySchema, $ownerClassName);
            $propertySchema = $this->markInlineEnumValidation($propertySchema);

            [$type, $nullableBySchema] = $this->resolvePropertyType(
                propertySchema: $propertySchema,
                ownerClassName: $ownerClassName,
                propertyName: $openApiPropertyName,
            );
            $isRequired = array_key_exists($openApiPropertyName, $requiredMap);
            $nullable = $nullableBySchema || !$isRequired;
            $default = $this->extractDefaultValue($propertySchema, $type);
            if (!array_key_exists('default', $propertySchema)) {
                // No inline default on the property itself: a pure `$ref` (or single-ref allOf)
                // inherits the `default` declared on the referenced schema. OpenAPI 3.0/3.1 both
                // treat this as valid — the default belongs to the target schema.
                $referencedDefault = $this->resolveReferencedDefault($propertySchema, $ownerClassName);
                if ($referencedDefault !== null) {
                    $default = $referencedDefault;
                }
            }
            $description = $this->extractDescription($propertySchema);
            $example = $this->extractExample($propertySchema);
            $temporalFormat = $this->resolveTemporalPhpDocFormat($propertySchema);
            $itemsTemporalFormat = $this->resolveItemsTemporalPhpDocFormat($propertySchema);
            // A `$ref` the extraction resolves is relative to the file that declares this property; an
            // inline object has no file of its own and keeps the one it was found in.
            //
            // A property is the ROOT of its own extraction, even when its class is read in the middle of
            // another one — Laravel spells a `$ref` union member out from its class's properties while the
            // union is still being extracted. Read as a nested entry, the property skipped the inlining,
            // and a `$ref` to a list alias kept `type: array` and lost its `items.maxLength`.
            $sourceFileBefore = $this->constraintSourceFile;
            $rootUnmaterializedBefore = $this->constraintRootUnmaterialized;
            $extractionDepthBefore = $this->constraintExtractionDepth;
            $this->constraintSourceFile = $this->getSchemaSourceFile($ownerClassName) ?? $sourceFileBefore;
            $this->constraintRootUnmaterialized = ltrim($type, '?') === 'mixed';
            $this->constraintExtractionDepth = 0;
            try {
                $constraints = $this->extractValidationConstraints($propertySchema);
            } finally {
                $this->constraintSourceFile = $sourceFileBefore;
                $this->constraintRootUnmaterialized = $rootUnmaterializedBefore;
                $this->constraintExtractionDepth = $extractionDepthBefore;
            }
            $constraints = $this->withOwnUnionBranchClassMarked(
                constraints: $constraints,
                propertySchema: $propertySchema,
                type: $type,
                ownerClassName: $ownerClassName,
            );
            $constraints = $this->withUnionBranchMembersGrouped(
                constraints: $constraints,
                propertySchema: $propertySchema,
                type: $type,
                ownerClassName: $ownerClassName,
            );

            $paramIn = $propertySchema['x-parameter-in'] ?? null;
            $isInPath = $paramIn === 'path';
            $isInQuery = $paramIn === 'query';
            $isInHeader = $paramIn === 'header';
            $isInCookie = $paramIn === 'cookie';
            // OAS 3.2 `in: querystring` binds the raw query string, so it is a source of its own.
            $isInQueryString = $paramIn === 'querystring';
            $parameterStyle = is_string($propertySchema['x-parameter-style'] ?? null)
                ? $propertySchema['x-parameter-style']
                : null;
            $parameterExplode = is_bool($propertySchema['x-parameter-explode'] ?? null)
                ? $propertySchema['x-parameter-explode']
                : null;
            $allowReserved = (bool)($propertySchema['x-parameter-allow-reserved'] ?? false);
            $allowEmptyValue = is_bool($propertySchema['x-parameter-allow-empty-value'] ?? null)
                ? $propertySchema['x-parameter-allow-empty-value']
                : null;

            // A parameter whose name collides with another parameter's IN A DIFFERENT LOCATION is
            // keyed by a disambiguated string so both survive — `id` and `idQuery` — while the name
            // the REQUEST carries stays `id` for both. The PHP name comes from the key; the wire
            // name, which the alias map and the deserializer's field lookup want, comes from here.
            $wireName = is_string($propertySchema['x-parameter-name'] ?? null)
                ? $propertySchema['x-parameter-name']
                : $openApiPropertyName;

            $normalizedName = $this->normalizePropertyName($openApiPropertyName);
            $alreadyMappedOpenApiName = $normalizedToOpenApiName[$normalizedName] ?? null;
            if ($alreadyMappedOpenApiName !== null && $alreadyMappedOpenApiName !== $openApiPropertyName) {
                throw new RuntimeException(sprintf(
                    'Property name collision in %s: "%s" and "%s" normalize to "$%s".',
                    $ownerClassName,
                    $alreadyMappedOpenApiName,
                    $openApiPropertyName,
                    $normalizedName,
                ));
            }
            $caseName = $this->disambiguateCaseInsensitiveName($normalizedName, $openApiPropertyName, $normalizedToOpenApiName);
            $classPropertyNames = [
                ...array_map(
                    callback: $this->normalizePropertyName(...),
                    array: array_map('strval', array_keys($properties)),
                ),
                ...array_map('strval', array_keys($siblingProperties)),
            ];
            $reserved = $this->generatedMemberNames($classPropertyNames);
            // Another `allOf` branch emits this PHP name for a DIFFERENT wire key — its own rename of
            // `this`, say — so the two must not merge as an override; the same wire key is one.
            $siblingWireName = $siblingProperties[$caseName] ?? null;
            if ($siblingWireName !== null && $siblingWireName !== $openApiPropertyName) {
                $reserved[strtolower($caseName)] = true;
            }
            // The suffixed name must miss the properties still to come as well: `this` renamed to
            // `this2` beside a real `this2` later in the list was a collision the order decided. A
            // case-only rename (`NAme` to `nAme2`) is a new name too, with members of its own.
            $normalizedName = $this->avoidGeneratedMemberName(
                name: $caseName,
                reserved: $reserved,
                taken: [...array_keys($normalizedToOpenApiName), ...$classPropertyNames],
                renamed: $caseName !== $normalizedName,
            );
            $normalizedToOpenApiName[$normalizedName] = $openApiPropertyName;

            $result[] = [
                'name' => $normalizedName,
                'openApiName' => $wireName,
                'type' => $type,
                'nullable' => $nullable,
                'schemaNullable' => $nullableBySchema,
                'required' => $isRequired,
                'default' => $default,
                'description' => $description,
                'example' => $example,
                'temporalFormat' => $temporalFormat,
                'unionTemporalFormats' => $this->unionTemporalFormats($propertySchema),
                'itemsTemporalFormat' => $itemsTemporalFormat,
                'inPath' => $isInPath,
                'inQuery' => $isInQuery,
                'inHeader' => $isInHeader,
                'inCookie' => $isInCookie,
                'inQueryString' => $isInQueryString,
                'parameterStyle' => $parameterStyle,
                'parameterExplode' => $parameterExplode,
                'allowReserved' => $allowReserved,
                'allowEmptyValue' => $allowEmptyValue,
                'constraints' => $constraints,
                'readOnly' => (bool)($propertySchema['readOnly'] ?? false),
                'writeOnly' => (bool)($propertySchema['writeOnly'] ?? false),
                'deprecated' => (bool)($propertySchema['deprecated'] ?? false),
                'isMap' => $this->isMapType($type),
                'isShapeUnion' => $this->unionAdmitsNonListShape($propertySchema),
            ];
        }

        return $result;
    }

    /**
     * True when a property must be present in a REQUEST.
     *
     * `required` in the document does not answer that. OpenAPI: "If the property is marked as
     * readOnly being true and is in the required list, the required will take effect on the response
     * only." A readOnly property is server-owned, so a request that omits it is valid, and every
     * input-facing decision — constructor optionality, a `present` rule, a NotNull assertion, an
     * entry in a validation schema's `required` list — has to ask THIS instead.
     *
     * Runtime mode expresses the same rule through the `UnsetValue` sentinel; no other mode has one,
     * so there the property becomes plainly optional and nullable. Emitted as required it was worse
     * than wrong: laravel mode fed the constructor `serverId: null` for a `readonly int`, which is a
     * TypeError on every hydration, and symfony mode could not denormalize the class at all.
     *
     * @param SchemaProperty $property
     */
    private function propertyIsRequiredOnInput(array $property): bool
    {
        // Laravel, laravel-data and yii3 mode: a required property with a schema default takes the default
        // when the key is absent, as runtime and Symfony mode do — `present` refused a body without
        // `perPage` there and nowhere else. The generation warning names the schema.
        if (
            in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA, self::ATTRIBUTE_MODE_YII3], true)
            && ($property['default'] ?? null) !== null
        ) {
            return false;
        }

        return $property['required'] === true && ($property['readOnly'] ?? false) !== true;
    }

    /**
     * A `type: object` + `additionalProperties` schema (a string-keyed map) is represented as the
     * generic type `array<string, V>`. Such a field must serialize as a JSON object, not a JSON
     * array — otherwise dense integer-like keys (0, 1, 2, …) make json_encode emit a list and the
     * map's keys are lost.
     */
    private function isMapType(string $type): bool
    {
        return str_starts_with($type, 'array<string, ');
    }

    /**
     * True when a `oneOf`/`anyOf` property admits a wire shape that is NOT a JSON array — a map, an
     * object or a scalar. The PHP type cannot answer this: a union of `type: array` and
     * `type: object` collapses to a bare `array` (every generic branch is widened to `array` and the
     * duplicates fold into one), which is indistinguishable from a plain list, while `isMap` only
     * recognises `array<string, V>`. Such a property therefore fell through to "list" and was
     * reindexed on the way out, which destroys a map's keys. It must be serialized RAW instead:
     * `array_values()` breaks the map branch, `(object)` breaks the list branch, and only the value
     * itself knows which branch it is.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function unionAdmitsNonListShape(array $propertySchema): bool
    {
        $propertySchema = $this->unwrapSingleUnionAllOf($propertySchema);

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $variants = $propertySchema[$keyword] ?? null;
            if (!is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (!is_array($variant) || $this->isNullUnionBranch($variant)) {
                    continue;
                }

                if (!$this->unionBranchIsList($variant, [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether ONE union branch promises a JSON array. Unknown shapes (an external `$ref`, a
     * `$ref` cycle) answer false: raw pass-through is the safe side — it can only lose a
     * reindex, never a key.
     *
     * @param array<string, mixed> $branch
     * @param array<int, string> $visitedRefs
     */
    private function unionBranchIsList(array $branch, array $visitedRefs): bool
    {
        $branch = $this->unwrapSingleUnionAllOf($branch);

        $isNestedUnion = false;
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $variants = $branch[$keyword] ?? null;
            if (!is_array($variants)) {
                continue;
            }

            $isNestedUnion = true;
            foreach ($variants as $variant) {
                if (!is_array($variant) || $this->isNullUnionBranch($variant)) {
                    continue;
                }

                if (!$this->unionBranchIsList($variant, $visitedRefs)) {
                    return false;
                }
            }
        }

        if ($isNestedUnion) {
            return true;
        }

        $ref = $branch['$ref'] ?? null;
        if (is_string($ref)) {
            $definition = $this->localSchemaDefinitionForRef($ref);
            if ($definition === null || in_array($ref, $visitedRefs, true)) {
                return false;
            }

            $visitedRefs[] = $ref;

            return $this->unionBranchIsList($definition, $visitedRefs);
        }

        $type = $branch['type'] ?? null;
        if (is_array($type)) {
            // OAS 3.1 type union (`type: [array, object]`): a list only when every member says so.
            $sawArray = false;
            foreach ($type as $member) {
                if ($member === 'null') {
                    continue;
                }

                if ($member !== 'array') {
                    return false;
                }

                $sawArray = true;
            }

            return $sawArray;
        }

        return $type === 'array';
    }

    /**
     * A branch that only adds null to the union: `type: null`, or the OpenAPI 3.0 `{nullable: true}`
     * idiom. It says nothing about the wire shape of the other branches.
     *
     * @param array<string, mixed> $variant
     */
    private function isNullUnionBranch(array $variant): bool
    {
        if (($variant['type'] ?? null) === 'null') {
            return true;
        }

        return ($variant['nullable'] ?? false) === true && $this->isNullOnlyBranch($variant);
    }

    /**
     * `allOf: [{oneOf: [...]}]` is the union itself, wrapped — the same unwrapping
     * `resolvePropertyType()` does before it resolves the composed type.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function unwrapSingleUnionAllOf(array $schema): array
    {
        $allOf = $schema['allOf'] ?? null;
        if (!is_array($allOf) || count($allOf) !== 1 || !is_array($allOf[0] ?? null)) {
            return $schema;
        }

        $only = $allOf[0];
        if (is_array($only['oneOf'] ?? null) || is_array($only['anyOf'] ?? null)) {
            return $only;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function localSchemaDefinitionForRef(string $ref): ?array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $definition = $this->dtoSchemas[$this->schemaClassName($schemaName)] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Runtime, yii3, and a union Symfony holds as the decoded array: an inline object union branch with
     * the enums of its properties kept as members — an inline `enum`, a `$ref` to an enum
     * component, and an `enum` beside a `$ref` — at any depth of inline objects.
     *
     * Each such property becomes a PHP enum, so the extraction drops its members as the type's
     * business. A branch spelled out rather than marked as its class (`withOwnUnionBranchClassMarked()`)
     * is the check the payload meets as a plain array, where no enum type was ever built: the branch
     * read `{egret: {type: integer}}` and passed `-84` for `enum: [-18]`. Kept, the members also judge
     * a built class, whose enum value the validator compares by its backing value.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withPropertyEnumsSpelledOut(array $schema): array
    {
        if (!is_array($schema['properties'] ?? null)) {
            return $schema;
        }
        $properties = [];
        foreach ($schema['properties'] as $name => $property) {
            if (!is_array($property)) {
                $properties[$name] = $property;

                continue;
            }
            $ref = $property['$ref'] ?? null;
            if (is_string($ref)) {
                $className = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
                $enum = $this->enumSchemas[$className] ?? null;
                $hasSiblingEnum = is_array($property['enum'] ?? null) && $property['enum'] !== [];
                // The keywords beside the reference — a `description`, a `title` — go along with the
                // members. Kept as the raw `$ref` instead, the property was scrubbed with it, and a raw
                // held nested-union member took `{"walrus": "zzq"}` for `enum: [amber, teal]`.
                $siblings = [];
                foreach ($property as $keyword => $value) {
                    if ($keyword !== '$ref') {
                        $siblings[$keyword] = $value;
                    }
                }
                $properties[$name] = is_array($enum) && !$hasSiblingEnum
                    ? [
                        ...$siblings,
                        'type' => $enum['type'] === 'int' ? 'integer' : 'string',
                        'enum' => array_values($enum['values']),
                        'x-php-inline-enum' => true,
                        ...($this->componentAdmitsNull($this->localComponentDefinition($ref) ?? []) ? ['nullable' => true] : []),
                    ]
                    : $property;
                // A `$ref` with an `enum` beside it (an OpenAPI 3.1 sibling, which asserts) is a PHP enum
                // of its own as well: its members are kept the same way as an inline `enum`'s.
                if ($hasSiblingEnum) {
                    $properties[$name]['x-php-inline-enum'] = true;
                }

                continue;
            }
            if (is_array($property['enum'] ?? null) && $property['enum'] !== []) {
                $property['x-php-inline-enum'] = true;
            }
            $properties[$name] = $this->withPropertyEnumsSpelledOut($property);
        }
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * Runtime: a `oneOf` branch that is itself an `anyOf` stands for every class its members became, and
     * says so — `x-php-union-members` lists them — so `DtoDeserializer` counts them as ONE branch.
     *
     * The members of the nested union are members of the property's PHP type beside the other branches'
     * (`ProbeNewt|ProbeNewtAnyOf1|ProbeNewtAnyOf2`). Counted one by one, a payload two members of the
     * same `anyOf` accept matched "more than one oneOf branch", and the valid payload was refused. A
     * nested `oneOf` is left alone: two of its members accepting a payload is a refusal there too.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function withUnionBranchMembersGrouped(array $constraints, array $propertySchema, string $type, string $ownerClassName): array
    {
        $variants = $propertySchema['oneOf'] ?? null;
        $branches = $constraints['oneOf'] ?? null;
        if (
            $this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME
            || !is_array($variants)
            || !array_is_list($variants)
            || !is_array($branches)
            || !array_is_list($branches)
            || count($branches) < count($variants)
        ) {
            return $constraints;
        }
        $typeMembers = array_values(array_filter(
            array: explode('|', ltrim($type, '?')),
            callback: static fn(string $member): bool => $member !== 'null',
        ));
        $membersByBranch = $this->unionMembersByBranch[$ownerClassName . '|' . implode('|', $typeMembers)] ?? [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant) || !is_array($variant['anyOf'] ?? null) || !is_array($branches[$index])) {
                continue;
            }
            $classes = [];
            foreach ($membersByBranch[$index] ?? [] as $member) {
                if (array_key_exists($member, $this->dtoSchemas)) {
                    $classes[] = ltrim(($this->schemaNamespaces[$member] ?? $this->baseNamespace) . '\\' . $member, '\\');
                }
            }
            if (count($classes) > 1) {
                $branches[$index]['x-php-union-members'] = $classes;
            }
        }
        $constraints['oneOf'] = $branches;

        return $constraints;
    }

    /**
     * Runtime: a union branch that synthesizes a class of its own — an inline object, a `$ref` with
     * keywords beside it, an `allOf` merged into one object — is "is that class", as a bare `$ref` is.
     *
     * `DtoDeserializer` builds the one class that accepts the payload, and the validator counts the
     * branches again against that BUILT object. Spelled out, a branch judged the object of another
     * class: an open `{type: object, properties: {stoat: …}}` matched a built `{marten: 1}` whose
     * `stoat` the other class had dropped, and an `allOf` around a `$ref` lost its `$ref` half and
     * matched any object — a valid payload was refused as matching two `oneOf` branches. Beside an
     * `array` member the spelling read `type: object`, so the JSON object was taken as a map and the
     * class was never built. Marked, each class judges the value itself, inherited properties, enums
     * and nested references included.
     *
     * The branches that become classes are matched to the classes of the PHP type in order — the type
     * lists its members in branch order, a bare `$ref` naming its own target — and only when the two
     * counts agree. A branch that `require`s a name it does not declare stays spelled out: the class
     * holds no such key, and only the spelling can ask the payload for it.
     *
     * @param array<string, mixed> $constraints
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function withOwnUnionBranchClassMarked(array $constraints, array $propertySchema, string $type, string $ownerClassName): array
    {
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME) {
            return $constraints;
        }
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            $variants = $propertySchema[$unionKey] ?? null;
            $branches = $constraints[$unionKey] ?? null;
            if (!is_array($variants) || !array_is_list($variants) || !is_array($branches) || !array_is_list($branches) || count($branches) < count($variants)) {
                continue;
            }
            $typeMembers = explode('|', ltrim($type, '?'));
            $classIndexes = [];
            $namedByOthers = [];
            foreach ($variants as $index => $variant) {
                if (is_bool($variant)) {
                    continue;
                }
                // A non-schema branch is skipped by the extraction, and the indexes no longer line up.
                if (!is_array($variant)) {
                    continue 2;
                }
                if ($this->unionBranchTypeOfItsOwn($variant, $ownerClassName) === 'class') {
                    $classIndexes[] = $index;

                    continue;
                }
                // `allOf: [{$ref: Teal}]` is Teal wrapped, typed and marked as the bare `$ref`: read as a
                // branch of no class, its untyped `allOf` admitted an object and the union stayed
                // spelled out, so the open object beside it matched the built Teal as well.
                $ref = $this->referenceOrSingleRefAllOf($variant);
                $referencedClass = is_string($ref)
                    ? $this->schemaRefToClassName($ref, $this->getSchemaSourceFile($ownerClassName) ?? $this->rootSpecFile)
                    : null;
                if ($referencedClass !== null) {
                    $namedByOthers[] = $referencedClass;
                }
                // A branch that holds an object as the decoded array — a map, a free-form object, a
                // nested union — leaves a payload no class was built from, where a marked branch is
                // undecided: only the spelling can still refuse it.
                $heldAs = $referencedClass !== null && in_array($referencedClass, $typeMembers, true)
                    ? null
                    : (is_string($ref) ? $this->localComponentDefinition($ref) : $variant);
                if ($heldAs !== null && $this->unionBranchAdmitsAnObject($heldAs)) {
                    continue 2;
                }
            }
            if ($classIndexes === []) {
                continue;
            }
            $candidates = array_values(array_filter(
                array: $typeMembers,
                callback: fn(string $member): bool => array_key_exists($member, $this->dtoSchemas) && !in_array($member, $namedByOthers, true),
            ));
            if (count($candidates) !== count($classIndexes)) {
                continue;
            }
            foreach ($classIndexes as $position => $branchIndex) {
                $variant = $variants[$branchIndex];
                if (!is_array($variant)) {
                    continue;
                }
                $marker = ['x-php-instanceof' => ltrim(($this->schemaNamespaces[$candidates[$position]] ?? $this->baseNamespace) . '\\' . $candidates[$position], '\\')];
                // A `required` name the branch does not declare has no slot in its class: building the
                // class says nothing about it, so the marker keeps it, for the raw payload to answer.
                // Dropped, `{$ref: Teal, required: [zebu]}` matched `{"marten": 1}`.
                $undeclaredRequired = $this->undeclaredRequiredNames($variant);
                if ($undeclaredRequired !== []) {
                    $marker['required'] = $undeclaredRequired;
                }
                $branch = $branches[$branchIndex];
                if (is_array($branch) && ($branch['nullable'] ?? null) === true) {
                    $marker['nullable'] = true;
                }
                $branches[$branchIndex] = $marker;
            }
            $constraints[$unionKey] = $branches;
        }

        return $constraints;
    }

    /**
     * Whether a union branch that is no class of its own may still take a JSON object: `type: object`
     * (a map, a free-form object), or no type at all beside no `enum` / `const`.
     *
     * An untyped branch composed of others is typed by them, as `DtoDeserializer::declaredBranchTypes()`
     * and `unionBranchTypes()` read it: every `allOf` member constrains, so one that admits no object
     * excludes it, and a nested `oneOf` / `anyOf` admits one only through a branch that does. Read by
     * the top-level `type` alone, `allOf: [{type: string}]` and `oneOf: [string, integer]` counted as
     * admitting an object, the union beside them was never marked, and a class branch spelled out by
     * its siblings let `{"tern": "c0"}` past the `maxLength: 1` of the class it extends.
     *
     * @param array<string, mixed> $branch
     */
    private function unionBranchAdmitsAnObject(array $branch, int $depth = 0): bool
    {
        $type = $branch['type'] ?? null;
        if ($type !== null) {
            return $type === 'object' || (is_array($type) && in_array('object', $type, true));
        }
        if (array_key_exists('enum', $branch) || array_key_exists('const', $branch) || $this->isNullOnlyBranch($branch)) {
            return false;
        }
        // Past the probe depth nothing more is known, and an untyped branch admits.
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return true;
        }
        foreach (is_array($branch['allOf'] ?? null) ? $branch['allOf'] : [] as $member) {
            if ($member === false) {
                return false;
            }
            if (!is_array($member)) {
                continue;
            }
            /** @var array<string, mixed> $member */
            if (!$this->unionBranchAdmitsAnObject($member, $depth + 1)) {
                return false;
            }
        }
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            $subBranches = $branch[$unionKey] ?? null;
            if (!is_array($subBranches) || $subBranches === []) {
                continue;
            }
            $someAdmits = false;
            foreach ($subBranches as $subBranch) {
                if (!is_bool($subBranch) && !is_array($subBranch)) {
                    continue;
                }
                /** @var array<string, mixed>|bool $subBranch */
                if ($subBranch === true || (is_array($subBranch) && $this->unionBranchAdmitsAnObject($subBranch, $depth + 1))) {
                    $someAdmits = true;

                    break;
                }
            }
            if (!$someAdmits) {
                return false;
            }
        }

        return true;
    }

    /**
     * The names a branch lists in `required` — its own and its `allOf` members' — that none of its
     * `properties` declares, the properties of a `$ref` target and of `allOf` members counted. A
     * reference this document cannot resolve declares nothing that is known, so its names all count.
     *
     * @param array<mixed> $branch
     * @return list<string>
     */
    private function undeclaredRequiredNames(array $branch): array
    {
        $declared = $this->declaredPropertyNames($branch);
        $undeclared = [];
        foreach ($this->requiredNamesThroughAllOf($branch) as $name) {
            if (!in_array($name, $declared, true) && !in_array($name, $undeclared, true)) {
                $undeclared[] = $name;
            }
        }

        return $undeclared;
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function requiredNamesThroughAllOf(array $schema, int $depth = 0): array
    {
        $names = array_values(array_filter(
            array: is_array($schema['required'] ?? null) ? $schema['required'] : [],
            callback: is_string(...),
        ));
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return $names;
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $member) {
            if (is_array($member)) {
                $names = [...$names, ...$this->requiredNamesThroughAllOf($member, $depth + 1)];
            }
        }

        return $names;
    }

    /**
     * The property names a schema declares: its `properties`, its `allOf` members' and those of the
     * component a `$ref` names, at any depth.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function declaredPropertyNames(array $schema, int $depth = 0): array
    {
        $names = array_map(strval(...), array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []));
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return $names;
        }
        $ref = $schema['$ref'] ?? null;
        $target = is_string($ref) ? $this->localComponentDefinition($ref) : null;
        if ($target !== null) {
            $names = [...$names, ...$this->declaredPropertyNames($target, $depth + 1)];
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $member) {
            if (is_array($member)) {
                $names = [...$names, ...$this->declaredPropertyNames($member, $depth + 1)];
            }
        }

        return $names;
    }

    /**
     * Whether an inline object branch lists in `required` a name its `properties` do not declare.
     *
     * @param array<mixed> $branch
     */
    private function requiresAnUndeclaredName(array $branch): bool
    {
        $required = is_array($branch['required'] ?? null) ? $branch['required'] : [];
        $declared = is_array($branch['properties'] ?? null) ? $branch['properties'] : [];
        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $declared)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs `$extraction` with references resolved against the document that declares `$className`.
     *
     * A renderer reads OTHER classes' constraints while one class is being rendered — Symfony's
     * callback reachability walks every schema, the object-level and interpreter readers take any
     * class name. Those ran in the context of the class being rendered, so a relative `$ref` written
     * in a schema of `b/schema.yml` was looked up next to whichever document that happened to be:
     * `Referenced OpenAPI file not found: rows.yml#/components/schemas/Cell`.
     *
     * @template T
     * @param callable(): T $extraction
     * @return T
     */
    private function withConstraintSourceOf(string $className, callable $extraction): mixed
    {
        $sourceFileBefore = $this->constraintSourceFile;
        $this->constraintSourceFile = $this->getSchemaSourceFile($className) ?? $sourceFileBefore;
        try {
            return $extraction();
        } finally {
            $this->constraintSourceFile = $sourceFileBefore;
        }
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function extractValidationConstraints(array $propertySchema): array
    {
        // The inlining runs in the OUTERMOST extraction only, and then walks the whole tree itself.
        //
        // This function re-enters itself a lot — once per `oneOf`/`anyOf` branch, for `not`, and four
        // more times from `scrubUnvalidatableSubschemas()` — and an inlining that ran on every entry
        // peeled one more level off a self-referential component per pass. A recursive report tree
        // exhausted memory before it finished generating. The walk below is recursive on its own, so
        // one pass reaches everything a hundred passes would.
        $outermost = $this->constraintExtractionDepth === 0;
        $this->constraintExtractionDepth++;

        try {
            return $this->extractValidationConstraintsFrom($propertySchema, $outermost);
        } finally {
            $this->constraintExtractionDepth--;
        }
    }

    /**
     * The documented 3.0 deviation — `nullable: true` admits null beside an `enum` or `const` that does
     * not list it — applied below the property too. Only the property level had it, so a list item
     * `{$ref: Kind, nullable: true}` and the union `anyOf: [{$ref: Kind}, {type: null}]` (canonicalized
     * to that) refused the null item in every mode.
     *
     * It runs on the schema as written (with references inlined), before a 3.1 `type: [x, "null"]` is
     * normalized into `nullable`, so that spelling keeps its JSON Schema strictness.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function withNestedNullableEnumRelaxed(array $node, bool $isRoot = false): array
    {
        if (!$isRoot && ($node['nullable'] ?? null) === true) {
            // `{$ref: Kind, nullable: true}`: the members arrive with the reference, later. Brought in
            // here, beside the `$ref`, they win over the referenced ones when that is inlined.
            $ref = $node['$ref'] ?? null;
            if (is_string($ref) && !array_key_exists('enum', $node) && !array_key_exists('const', $node)) {
                $referenced = $this->nestedScalarRefDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
                if (is_array($referenced['enum'] ?? null)) {
                    $node['enum'] = $referenced['enum'];
                } elseif (is_array($referenced) && array_key_exists('const', $referenced)) {
                    $node['const'] = $referenced['const'];
                }
            }
            if (is_array($node['enum'] ?? null) && !in_array(null, $node['enum'], true)) {
                $node['enum'] = [...$node['enum'], null];
            }
            if (array_key_exists('const', $node) && $node['const'] !== null) {
                $node['enum'] = [$node['const'], null];
                unset($node['const']);
            }
        }

        foreach (['items', 'additionalProperties', 'contains', 'propertyNames', 'unevaluatedItems', 'unevaluatedProperties'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $node[$key] = $this->withNestedNullableEnumRelaxed($node[$key]);
            }
        }
        foreach (['properties', 'patternProperties'] as $key) {
            if (is_array($node[$key] ?? null)) {
                foreach ($node[$key] as $name => $child) {
                    // A property node is a PROPERTY: its own `nullable` may be a normalized 3.1 type
                    // list, and the property level decides that from the raw keyword. Only its
                    // containers are below it.
                    if (is_array($child)) {
                        $node[$key][$name] = $this->withNestedNullableEnumRelaxed($child, isRoot: true);
                    }
                }
            }
        }

        return $node;
    }

    /**
     * The documented 3.0 deviation (README.validation) applied to extracted constraints that carry
     * `nullable: true`: an `enum` gains the null member, a `const` becomes the enum of it and null.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function withNullAdmittedBesideEnum(array $constraints): array
    {
        if (is_array($constraints['enum'] ?? null) && !in_array(null, $constraints['enum'], true)) {
            $constraints['enum'] = [...$constraints['enum'], null];
        }
        if (array_key_exists('const', $constraints) && $constraints['const'] !== null) {
            $const = $constraints['const'];
            $withoutConst = [];
            foreach ($constraints as $keyword => $value) {
                if ($keyword !== 'const') {
                    $withoutConst[$keyword] = $value;
                }
            }
            $constraints = $withoutConst + ['enum' => [$const, null]];
        }

        return $constraints;
    }

    /**
     * Runtime: a map whose values are `{allOf: [$ref Shape]}`, Shape a union component of classes, is
     * typed `array<string, Shape>` like `additionalProperties: {$ref: Shape}`, and is read as that
     * spelling, whose constraints name the members "is Circle", "is Square". The wrapper was inlined as Shape's schemas
     * instead, which name no class, so `DtoDeserializer` had nothing to build a value from and refused
     * every map as "unknown type". A list keeps the wrapper: its items stay untyped arrays there.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function runtimeUnionMapValueUnwrapped(array $propertySchema): array
    {
        $valueSchema = $propertySchema['additionalProperties'] ?? null;
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME || !is_array($valueSchema) || !$this->isSingleRefAllOf($valueSchema)) {
            return $propertySchema;
        }
        $beside = [];
        foreach ($valueSchema as $keyword => $value) {
            if ($keyword === 'allOf') {
                continue;
            }
            if (!in_array($keyword, [...self::ALIAS_ANNOTATION_KEYWORDS, ...self::NON_ASSERTING_KEYWORDS], true)) {
                return $propertySchema;
            }
            $beside[$keyword] = $value;
        }
        $ref = $this->referenceOrSingleRefAllOf($valueSchema);
        if ($ref === null || array_key_exists('$ref', $this->runtimeClassUnionSpelledOut(['$ref' => $ref]))) {
            return $propertySchema;
        }

        return ['additionalProperties' => [...$beside, '$ref' => $ref]] + $propertySchema;
    }

    /**
     * Runtime: a `$ref` to a component that is a union of classes and no `discriminator`
     * (`Shade: {oneOf: [$ref Olive, $ref Teal]}`) is checked like the same union written inline — each
     * branch "is that class". The property is typed with the union's interface, which names no members;
     * these branches in the owner's `getConstraints()` are what `DtoDeserializer` builds the payload from,
     * and `oneOf` says a payload two members accept is refused. Applied at every level the extractor
     * reaches, so list items and map values of the union carry them too.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function runtimeClassUnionSpelledOut(array $propertySchema): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME || !is_string($ref) || !str_starts_with($ref, '#/components/schemas/')) {
            return $propertySchema;
        }
        $interface = $this->schemaClassName($this->componentNameInPointer($ref));
        $target = $this->dtoSchemas[$interface] ?? null;
        if (!is_array($target) || array_key_exists('discriminator', $target) || !$this->rendersAsUnion($target)) {
            return $propertySchema;
        }
        $unionKeywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($target)));
        if (count($unionKeywords) !== 1 || !is_array($target[$unionKeywords[0]]) || $target[$unionKeywords[0]] === []) {
            return $propertySchema;
        }
        $unionKeyword = $unionKeywords[0];
        // A `required` name an inline branch does not declare has no slot in the class written for it
        // (`collectUnionTypes()` names it `<Union>OneOf<n>`), so it stays beside the member's marker.
        $undeclaredByMember = [];
        // A 3.0 `{$ref: Amber, nullable: true}` member admits null beside Amber, and keeps saying so.
        $nullableMembers = [];
        foreach (array_values(is_array($target[$unionKeyword] ?? null) ? $target[$unionKeyword] : []) as $index => $variant) {
            $names = is_array($variant) && !array_key_exists('$ref', $variant) ? $this->undeclaredRequiredNames($variant) : [];
            if ($names !== []) {
                $undeclaredByMember[$interface . ($unionKeyword === 'oneOf' ? 'OneOf' : 'AnyOf') . ($index + 1)] = $names;
            }
            if (is_array($variant) && is_string($variant['$ref'] ?? null) && ($variant['nullable'] ?? null) === true) {
                $nullableMembers[] = $this->schemaRefToClassName($variant['$ref'], $this->getSchemaSourceFile($interface) ?? $this->rootSpecFile);
            }
        }
        // The members the interface was given — `$ref` branches and the classes written for inline
        // object branches (`AnimalOneOf3`) alike, in declaration order.
        $branches = [];
        foreach ($this->unionInterfacesByClass as $member => $interfaces) {
            if (in_array($interface, $interfaces, true) && array_key_exists($member, $this->dtoSchemas)) {
                $branches[] = ['$ref' => '#/components/schemas/' . $member]
                    + (array_key_exists($member, $undeclaredByMember) ? ['required' => $undeclaredByMember[$member]] : [])
                    + (in_array($member, $nullableMembers, true) ? ['nullable' => true] : []);
            }
        }
        if (count($branches) < 2) {
            return $propertySchema;
        }
        $spelled = [];
        foreach ($propertySchema as $keyword => $value) {
            if ($keyword !== '$ref') {
                $spelled[$keyword] = $value;
            }
        }
        // What the component says beside its union — `type: object`, `properties`, `required` — applies
        // to the same value (JSON Schema), so it goes along: `DtoDeserializer` checks it on the raw
        // value, which still holds the keys no member class declares. Left behind, the component was
        // not spelled out at all, and the interface named no member to build: every payload was
        // refused as "Unsupported type".
        foreach ($target as $keyword => $value) {
            if (
                $keyword === $unionKeyword
                || array_key_exists($keyword, $spelled)
                || in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                || str_starts_with((string)$keyword, 'x-')
            ) {
                continue;
            }
            $spelled[$keyword] = $value;
        }

        return [...$spelled, $unionKeyword => $branches];
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function extractValidationConstraintsFrom(array $propertySchema, bool $mayInline): array
    {
        // `enum: []` lists no member, so no value is one: the schema is `false`, whatever stands beside
        // it. Every reader below skips an empty `enum` as "no enum", which made it admit EVERY value.
        // Spelled as `not: true` — the array form of `false` every mode already enforces. The 3.0
        // `nullable: true` keeps its documented meaning (README.validation: it admits null even where
        // the enum does not list it), so beside it the one member left is null.
        if (($propertySchema['enum'] ?? null) === []) {
            return ($propertySchema['nullable'] ?? null) === true ? ['enum' => [null]] : ['not' => true];
        }

        $propertySchema = $this->normalizeNullableBranchInAllOf($propertySchema);
        $propertySchema = $this->runtimeClassUnionSpelledOut($propertySchema);
        // A discriminated union one of whose members does not extend it is checked — at every level, a
        // list item and a map value as much as the property — as the same union written inline: the
        // value is typed by its members, and the tag picks the member to build.
        $propertySchema = $this->inlineSharedMemberUnionRef($propertySchema);
        if ($mayInline && !$this->constraintRootUnmaterialized) {
            $propertySchema = $this->runtimeUnionMapValueUnwrapped($propertySchema);
        }
        // Applied HERE rather than where the property schemas are prepared, because that is not the
        // only entrance: Symfony, Laravel and Yii3 modes extract the constraints of a whole CLASS
        // straight from its registered schema, so anything done to the prepared property schema was
        // invisible to four modes out of five. Runtime mode alone saw it, which is exactly how the
        // hole looked when measured.
        if ($mayInline) {
            $propertySchema = $this->withUnmaterializedPropertiesInlined($propertySchema);
            $propertySchema = $this->inlineNestedContainerValidation(
                schema: $propertySchema,
                belowMaterialization: $this->constraintRootUnmaterialized,
            );
            // On the inlined but not yet normalized schema: `nullable` here is still the KEYWORD, and a
            // 3.1 `type: [x, "null"]` is still a type list, so the two stay apart.
            $propertySchema = $this->withNestedNullableEnumRelaxed($propertySchema, isRoot: true);
        }

        $allowedKeys = [
            'type',
            'nullable',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
            'pattern',
            'format',
            'minItems',
            'maxItems',
            'uniqueItems',
            'items',
            'contains',
            'minContains',
            'maxContains',
            'prefixItems',
            'const',
            'if',
            'then',
            'else',
            // Object-level constraints. DtoValidator enforces these against inline
            // objects / map types (additionalProperties: {schema}) that are NOT
            // materialized into a dedicated nested DTO.
            'required',
            'properties',
            'additionalProperties',
            'minProperties',
            'maxProperties',
            'dependentRequired',
            'dependentSchemas',
            'patternProperties',
            'propertyNames',
            // JSON Schema 2019-09/2020-12 (OpenAPI 3.1): enforced against inline composed
            // objects/arrays that are not materialized into a dedicated nested DTO. May be a
            // bool (kept verbatim) or a schema (scrubbed in scrubUnvalidatableSubschemas).
            'unevaluatedProperties',
            'unevaluatedItems',
            // String content assertions (JSON Schema 2019-09/2020-12, OpenAPI 3.1).
            // contentEncoding/contentMediaType are scalars; contentSchema is a subschema
            // (scrubbed in scrubUnvalidatableSubschemas).
            'contentEncoding',
            'contentMediaType',
            'contentSchema',
        ];

        // NOTE: property-level enums are usually materialized to PHP backed enums and therefore
        // don't need an explicit enum validator here. If enum synthesis is not possible
        // (`x-php-inline-enum=true`, e.g. bool/null members), keep enum as an inline constraint.
        // Nullable PHP enum types also need it: permitting null by type does not add an enum member.

        $constraints = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $propertySchema)) {
                continue;
            }

            $constraints[$key] = $propertySchema[$key];
        }

        if (
            (
                ($propertySchema['x-php-inline-enum'] ?? false) === true
                || $this->schemaAllowsNull($propertySchema)
                || !$this->canGenerateBackedEnumFromSchema($propertySchema)
            )
            && is_array($propertySchema['enum'] ?? null)
            && $propertySchema['enum'] !== []
        ) {
            $constraints['enum'] = $propertySchema['enum'];
        }

        // A DELIBERATE DEVIATION from OpenAPI 3.0.3 (README.validation): `nullable: true` admits null
        // even where an `enum` or `const` beside it does not list it. The 3.0.3 text lets those keywords
        // refuse null, but a document writing `nullable` next to them means "or null" — and 2.15.48
        // read it that way. Only the 3.0 KEYWORD: the 3.1 spelling `type: [x, "null"]` is JSON Schema,
        // where `enum` decides on its own, so the member has to be listed there.
        if (($propertySchema['nullable'] ?? null) === true) {
            $constraints = $this->withNullAdmittedBesideEnum($constraints);
        }

        // A `string` + `format: binary` property is materialized as an UploadedFile, not a
        // string. Forwarding `type: string` would make the validator reject the uploaded file
        // ("param must be of type string") at deserialization time; the file is validated by its
        // PHP type instead. Drop the string type/format so no string constraint applies.
        if (($propertySchema['type'] ?? null) === 'string' && ($propertySchema['format'] ?? null) === 'binary') {
            unset($constraints['type'], $constraints['format']);
        }

        foreach (['oneOf', 'anyOf'] as $unionKey) {
            $variants = $propertySchema[$unionKey] ?? null;
            if (!is_array($variants) || $variants === []) {
                continue;
            }

            // If any variant extracts to [] (e.g. a bare $ref the validator can't resolve),
            // the oneOf/anyOf match count can't be enforced soundly — an empty branch is
            // vacuously satisfied, which would over-count oneOf (false "more than one") and
            // make anyOf always pass. In that case drop the whole keyword.
            $branchConstraints = [];
            // The member each kept branch names, by its position among the kept branches: the inlined
            // branch carries no class of its own, so the discriminator check finds the member by index.
            $branchMembers = [];
            $hasUnvalidatableBranch = false;
            foreach ($variants as $variant) {
                // The 3.0 null branch, `{nullable: true}` and nothing else, is what types the property
                // `?X`. It extracts to the bare permission, which the interpreter drops as asserting
                // nothing — so the branch vanished and the `null` it stands for matched no branch.
                if (is_array($variant) && $this->isNullUnionBranch($variant)) {
                    $branchConstraints[] = ['type' => 'null'];

                    continue;
                }
                // The always-true branch COUNTS: in `oneOf` it matches alongside any other branch.
                if ($this->isVacuousSubschema($variant)) {
                    $branchConstraints[] = true;

                    continue;
                }
                // The never-true branch counts as well, by matching nothing. Skipped with the non-arrays,
                // `anyOf: [false, false]` lost every branch, the keyword went with them, and a schema that
                // admits no value admitted every one.
                if ($variant === false) {
                    $branchConstraints[] = false;

                    continue;
                }
                if (!is_array($variant)) {
                    continue;
                }

                // A branch's own `enum` is the branch: the generated PHP enum it also becomes types
                // the property, not the branch, so dropped as a property-level enum is, the branch
                // read `{type: string}` and matched every string. Kept as the members it lists.
                //
                // The `$ref` the branch names — bare, beside sibling keywords, or as an `allOf`
                // fragment — is no constraint keyword, so it is taken apart from what stands beside
                // it and judged by `unionRefBranch()`. Extracted together, the reference was lost
                // whenever a sibling extracted to something: `{$ref: Count, maximum: 30}` read
                // `{maximum: 30}` and matched every string beside a `type: string` branch.
                [$branchRef, $besideRef] = $this->splitBranchReference(
                    is_array($variant['enum'] ?? null) && $variant['enum'] !== [] ? [...$variant, 'x-php-inline-enum' => true] : $variant,
                );
                // Yii3 too: a union it cannot type is held raw (`string|int|float|bool|array`), and the
                // branch class whose enum types took the members is never built — `{"newt": -10}`
                // passed `enum: [-9]`.
                // An arm of a class-level union (`hasClassLevelUnionArmsOnly()`) in every mode: its
                // `{properties: {kind: {enum: [phone]}}}` types nothing, and with the members dropped the
                // arm extracted to nothing, which took the whole `oneOf` with it.
                if (
                    $branchRef === null
                    && (
                        in_array($this->attributeMode, [self::ATTRIBUTE_MODE_RUNTIME, self::ATTRIBUTE_MODE_YII3], true)
                        || $this->hasClassLevelUnionArmsOnly($propertySchema)
                    )
                ) {
                    $besideRef = $this->withPropertyEnumsSpelledOut($besideRef);
                }
                $extracted = $this->extractValidationConstraints(propertySchema: $besideRef);
                if ($branchRef !== null) {
                    $extracted = $this->unionRefBranch(ref: $branchRef, beside: $extracted, branch: $variant);
                    // The 3.0 deviation once more, for `{allOf: [{$ref: Kind}], nullable: true}` as a
                    // branch: the members arrive with the reference, AFTER the branch's own extraction
                    // relaxed what it had, so the null its `nullable` admits was refused by the enum.
                    if (($variant['nullable'] ?? null) === true && $extracted !== [] && !array_key_exists('allOf', $extracted)) {
                        $extracted = $this->withNullAdmittedBesideEnum([...$extracted, 'nullable' => true]);
                    }
                }
                // A branch that extracted to the bare `nullable` permission asserts nothing either: it
                // stood for a member the extraction could not read (an annotated reference in an `allOf`
                // once), and kept as a branch it matched every value — `anyOf` accepted anything.
                if ($extracted === [] || array_keys($extracted) === ['nullable']) {
                    $hasUnvalidatableBranch = true;
                    break;
                }
                $inlinedMember = $variant['x-php-inlined-member'] ?? null;
                if ($branchRef !== null || is_string($inlinedMember)) {
                    $branchMembers[count($branchConstraints)] = $branchRef !== null ? $this->schemaRefToClassName($branchRef, $this->constraintSourceFile ?? $this->rootSpecFile) : $inlinedMember;
                }
                $branchConstraints[] = $extracted;
            }

            if (!$hasUnvalidatableBranch && $branchConstraints !== []) {
                // `nullable: true` NEXT TO a union says the same thing as spelling a `{type: null}`
                // variant inside it, and only the spelled form reached the emitted interpreter. So a
                // document written the first way had its own `null` refused — "does not match any oneOf
                // branch (expected integer or string, got null)" — in every mode but runtime, which reads
                // the schema directly and always accepted it. Normalising the one spelling into the other
                // is what closes that, and it reuses the branch matching that already worked.
                if ($this->schemaAllowsNull($propertySchema) && !$this->unionBranchesAcceptNull($branchConstraints)) {
                    $branchConstraints[] = ['type' => 'null'];
                }

                $constraints[$unionKey] = $branchConstraints;
                // The discriminator picks the variant under `anyOf` as much as under `oneOf`: without it an
                // inline `anyOf` was hydrated by nothing in laravel mode — every payload a TypeError.
                if (is_array($propertySchema['discriminator'] ?? null)) {
                    $discriminator = $propertySchema['discriminator'];
                    $propertyName = $discriminator['propertyName'] ?? null;
                    $mapping = $discriminator['mapping'] ?? null;
                    // No mapping means the implicit one, each member named by its schema — as the
                    // component spelling already reads it. laravel mode hydrates an inline union only
                    // through the dispatch below, so without it every payload, valid ones included,
                    // reached the constructor as a bare array: a TypeError.
                    // The runtime builds by the map as well: with the tag alone it built the first member
                    // that took the payload, so `{"kind": "Blue", "wing": "x"}` became a Red under `anyOf`
                    // — `wing` an extra key there — and passed.
                    $laravelMode = in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true);
                    if ($mapping === null && ($laravelMode || $this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME)) {
                        $mapping = $this->implicitDiscriminatorMapping(['oneOf' => $variants]);
                    }
                    // The runtime deserializer tries every class member of a bare `oneOf` and refuses a
                    // second match; a declared discriminator lets the tag choose instead, even with no
                    // map to check it against — members that overlap apart from the tag are its point.
                    if ($mapping === [] && is_string($propertyName) && $this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME) {
                        $constraints['x-discriminator-property'] = $propertyName;
                    }
                    if (is_string($propertyName) && is_array($mapping) && $mapping !== []) {
                        $classMap = [];
                        foreach ($mapping as $discriminatorValue => $ref) {
                            // A numeric key — `{"1": Red}` — is an integer once the document is decoded,
                            // and was dropped with the discriminator it belonged to.
                            if (!is_string($ref)) {
                                continue;
                            }
                            $mappedClass = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
                            if ($mappedClass === 'mixed') {
                                continue;
                            }
                            $classMap[(string)$discriminatorValue] = $mappedClass;
                        }
                        // laravel-data too: matched branch by branch, members that overlap apart from
                        // the tag refused every valid payload as matching twice.
                        $dispatched = $laravelMode && $classMap !== []
                            ? $this->laravelDiscriminatorDispatch(
                                variants: $variants,
                                branchConstraints: $branchConstraints,
                                propertyName: $propertyName,
                                classMap: $classMap,
                            )
                            : null;
                        if ($dispatched !== null) {
                            $constraints = [...array_diff_key($constraints, [$unionKey => true]), ...$dispatched];
                        } elseif ($classMap !== []) {
                            $constraints['x-discriminator-property'] = $propertyName;
                            $constraints['x-discriminator-php-property'] = $this->normalizePropertyName($propertyName);
                            $constraints['x-discriminator-map'] = $classMap;
                            // symfony and yii3 judge a member held as the decoded array, not as its class:
                            // the tag must name the branch that matched, found here by its position.
                            if (in_array($this->attributeMode, [self::ATTRIBUTE_MODE_SYMFONY, self::ATTRIBUTE_MODE_YII3], true)) {
                                $memberBranches = [];
                                foreach ($classMap as $tag => $mappedClass) {
                                    $branchIndex = array_search($mappedClass, $branchMembers, true);
                                    if (is_int($branchIndex)) {
                                        $memberBranches[$tag] = $branchIndex;
                                    }
                                }
                                if ($memberBranches !== []) {
                                    $constraints['x-discriminator-branches'] = $memberBranches;
                                }
                            }
                        }
                    }
                }
            }
        }

        // allOf: every branch must pass. Branches are usually `$ref`s the validator
        // cannot resolve, so recurse and keep only branches that carry actionable
        // constraints; a fully-unresolvable allOf is dropped entirely (no-op noise).
        $allOf = $propertySchema['allOf'] ?? null;
        if (is_array($allOf) && $allOf !== []) {
            $branches = [];
            foreach ($allOf as $branch) {
                // `false` is the subschema nothing satisfies, so an `allOf` holding one can never
                // pass and no sibling branch can rescue it. Skipping it the way a `true` member is
                // skipped turned `allOf: [false, {type: string}]` into a plain `type: string`: the
                // document said no value is valid and the emitted class accepted every string.
                // Kept in the spelling `DtoValidator` already reads — there is nothing to translate.
                if ($branch === false) {
                    $branches = [false];
                    break;
                }
                if (!is_array($branch)) {
                    continue;
                }
                $extracted = $this->extractValidationConstraints($branch);
                // These branches do not own PHP enum types; each membership assertion must survive.
                if (is_array($branch['enum'] ?? null)) {
                    $extracted['enum'] = $branch['enum'];
                }
                if ($extracted !== []) {
                    $branches[] = $extracted;
                }
            }
            if ($branches !== []) {
                // Beside a discriminated union's dispatch, which is an `allOf` of its own.
                $constraints['allOf'] = [...(is_array($constraints['allOf'] ?? null) ? $constraints['allOf'] : []), ...$branches];
            }
        }

        // not: value must NOT match the subschema. Forwarded only when the recursively
        // extracted subschema has actionable constraints — a `$ref`-only `not` would
        // extract to an empty schema that every value vacuously satisfies, making the
        // validator's "must not match" check fire a false positive on every value.
        $not = $propertySchema['not'] ?? null;
        // `not: true` forbids every value: `true` is the schema everything matches, so "must NOT
        // match it" can never hold. It was dropped, and the property accepted anything. `not: false`
        // is the mirror — nothing matches `false`, so the keyword constrains nothing — and it stays
        // dropped, which is the same answer the empty-extraction guard below gives.
        if ($this->isVacuousSubschema($not)) {
            // `not: {}` is `not: true`: the empty schema matches everything, so nothing passes.
            $constraints['not'] = true;
        } elseif (is_array($not)) {
            $extractedNot = $this->extractValidationConstraints($not);
            if ($extractedNot !== []) {
                $constraints['not'] = $extractedNot;
            }
        }

        // Recursively scrub subschema-bearing keys: a $ref (or any unvalidatable subschema)
        // extracts to [], which the validator can't resolve. Forwarding it verbatim is unsafe
        // — most dangerously `if: {$ref}` would extract to a vacuously-true schema and apply
        // `then` to every value. Drop what becomes empty (same guard as oneOf/anyOf/allOf/not).
        $constraints = $this->scrubUnvalidatableSubschemas(
            constraints: $constraints,
            propertiesBesideReference: $this->hasReferenceBranch($propertySchema),
        );

        if (($propertySchema['readOnly'] ?? false) === true) {
            $constraints['readOnly'] = true;
        }

        // OpenAPI 3.1: type: [string, null] → normalize to type: string, nullable: true
        if (array_key_exists('type', $constraints) && is_array($constraints['type'])) {
            $nonNullTypes = array_values(
                array: array_filter($constraints['type'], static fn(mixed $t): bool => is_string($t) && $t !== 'null'),
            );
            if (count($nonNullTypes) < count($constraints['type'])) {
                $constraints['nullable'] = true;
            }
            $constraints['type'] = count($nonNullTypes) === 1 ? $nonNullTypes[0] : $nonNullTypes;
            if ($nonNullTypes === [] && in_array('null', $propertySchema['type'], true)) {
                $constraints['type'] = 'null';
            }
        }

        // The union spelling of the same permission, `anyOf: [{$ref: X}, {type: "null"}]`. The property
        // is typed `?X` from it, but the null branch was all the constraints knew of the permission and
        // it extracts to nothing, so an OPTIONAL property refused the null the document allows.
        if (!array_key_exists('nullable', $constraints) && $this->unionHasNullBranch($propertySchema)) {
            $constraints['nullable'] = true;
        }

        return $constraints;
    }

    /**
     * The class schema with every property `constraintUnmaterializedProperties` names inlined as a
     * schema nothing materializes — its `$ref`s, union branches included, spelled out — and the list
     * cleared, so the class's nested schemas do not read it.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withUnmaterializedPropertiesInlined(array $schema): array
    {
        $unmaterialized = $this->constraintUnmaterializedProperties;
        $this->constraintUnmaterializedProperties = [];
        if ($unmaterialized === [] || !is_array($schema['properties'] ?? null)) {
            return $schema;
        }
        $properties = [];
        foreach ($schema['properties'] as $name => $property) {
            $properties[$name] = is_array($property) && array_key_exists((string)$name, $unmaterialized)
                ? $this->inlineNestedContainerValidation(schema: $property, belowMaterialization: true)
                : $property;
        }
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * The `$ref` a union branch names, and what stands beside it: `{$ref: X, …siblings}` and
     * `{allOf: [{$ref: X}, …fragments], …}` alike. A branch naming no reference, or more than one
     * inside its `allOf`, comes back whole with no reference.
     *
     * @param array<string, mixed> $branch
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function splitBranchReference(array $branch): array
    {
        $ref = $branch['$ref'] ?? null;
        if (is_string($ref)) {
            $beside = [];
            foreach ($branch as $keyword => $value) {
                if ($keyword !== '$ref') {
                    $beside[$keyword] = $value;
                }
            }

            return [$ref, $beside];
        }

        $allOf = $branch['allOf'] ?? null;
        if (!is_array($allOf) || !array_is_list($allOf)) {
            return [null, $branch];
        }
        $refFragments = array_filter(
            array: $allOf,
            callback: fn(mixed $fragment): bool => $this->isAnnotatedReference($fragment),
        );
        if (count($refFragments) !== 1) {
            return [null, $branch];
        }
        $refIndex = array_key_first($refFragments);
        /** @var string $ref */
        $ref = $refFragments[$refIndex]['$ref'];
        $fragments = [];
        foreach ($allOf as $index => $fragment) {
            if ($index !== $refIndex) {
                $fragments[] = $fragment;
            }
        }
        $beside = [];
        foreach ($branch as $keyword => $value) {
            if ($keyword !== 'allOf') {
                $beside[$keyword] = $value;
            } elseif ($fragments !== []) {
                $beside['allOf'] = $fragments;
            }
        }

        return [$ref, $beside];
    }

    /**
     * A union branch naming `$ref`, with the constraints of what stands beside the reference.
     *
     * Both apply (OpenAPI 3.1 reads siblings of a `$ref` as one more `allOf` member). A branch "is that
     * class" is flat, because the interpreters read `x-php-instanceof` only on the branch itself;
     * runtime judges the siblings next to it and Symfony leaves them to the class. A spelled-out target
     * and its siblings become an `allOf`. A target no mode can judge leaves the siblings alone, as
     * before — or nothing, and the union is unvalidatable.
     *
     * @param array<string, mixed> $beside
     * @param array<string, mixed> $branch the branch as written, to tell whether it is a class of its own
     * @return array<string, mixed>
     */
    private function unionRefBranch(string $ref, array $beside, array $branch): array
    {
        $target = $this->unionRefTarget($ref);
        if ($target === []) {
            return $beside;
        }
        if ($beside === []) {
            return $target;
        }

        $asserting = [];
        foreach ($beside as $keyword => $value) {
            if (!in_array($keyword, ['nullable', 'readOnly'], true)) {
                $asserting[$keyword] = $value;
            }
        }
        if (array_key_exists('x-php-instanceof', $target)) {
            // `{$ref: X, nullable: true}` is "X or null": the class check stays, and the `nullable`
            // beside it lets the null through. Reduced to the bare `nullable`, the branch admitted any
            // value — a `oneOf` then refused every value of its other branch as matching twice.
            if ($asserting === []) {
                return [...$target, ...$beside];
            }
            // A branch typed by a class of its own — an object reference beside keys that describe more
            // of it — need not extend X, so it is not "is X" either: that class checks itself. Siblings
            // that write no such class (`{type: object, allOf: [{$ref: X}]}`, a `maximum` beside the
            // reference) leave the branch typed `X`: kept alone, they matched every object, and a
            // `oneOf` refused each valid value of its other branch as matching twice.
            if (!$this->unionRefBranchIsAClassOfItsOwn($ref, $branch)) {
                // Both apply: a `type` beside the reference that leaves null out (`{$ref: Olive, type:
                // object}`) refuses the null Olive admits. Merged flat, the target's `nullable` won,
                // and runtime took a null no other mode did.
                $siblingType = $asserting['type'] ?? null;
                if (
                    $siblingType !== null
                    && ($beside['nullable'] ?? null) !== true
                    && $siblingType !== 'null'
                    && !(is_array($siblingType) && in_array('null', $siblingType, true))
                ) {
                    return [...$beside, ...array_diff_key($target, ['nullable' => true])];
                }

                return [...$beside, ...$target];
            }

            return $this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME
                ? $this->runtimeSpelledOutClassBranch(ref: $ref, branch: $branch, beside: $beside)
                : $beside;
        }
        if ($asserting === []) {
            return [...$target, ...$beside];
        }

        return ['allOf' => [$target, $asserting], ...array_diff_key($beside, $asserting)];
    }

    /**
     * Runtime: a `$ref` branch with keywords beside it that is a class of its own, spelled out in full —
     * the target's checks and the siblings', every enum as its members.
     *
     * `withOwnUnionBranchClassMarked()` replaces the spelling with "is that class" where it can. It
     * cannot beside a member that admits an object — a map, a free-form object, an untyped branch: a
     * payload the class refuses stays the raw array that member holds, and only the spelling still
     * judges it. Spelled by its siblings alone, the branch lost the target's `required`, types and
     * lengths, and a sibling enum had moved into an enum class: `{$ref: Sienna, properties: {raven:
     * {enum: [0]}}}` beside a map of booleans let `{"tern": 5}` and `{"raven": 3}` through as that map.
     *
     * @param array<string, mixed> $branch the branch as written
     * @param array<string, mixed> $beside the siblings as extracted, kept where the target cannot be spelled
     * @return array<string, mixed>
     */
    private function runtimeSpelledOutClassBranch(string $ref, array $branch, array $beside): array
    {
        $inlinedTarget = $this->inlineNestedContainerValidation(schema: ['$ref' => $ref], belowMaterialization: true);
        $target = array_key_exists('$ref', $inlinedTarget) ? [] : $this->extractValidationConstraints(propertySchema: $inlinedTarget);
        if ($target === []) {
            return $beside;
        }
        [, $siblings] = $this->splitBranchReference($branch);
        $asserting = [];
        foreach ($siblings as $keyword => $value) {
            if (!in_array($keyword, ['nullable', 'readOnly'], true)) {
                $asserting[$keyword] = $value;
            }
        }
        $spelledSiblings = $this->extractValidationConstraints(
            propertySchema: $this->inlineNestedContainerValidation(schema: $asserting, belowMaterialization: true),
        );
        $permissions = array_intersect_key($beside, ['nullable' => true]);

        return $spelledSiblings === []
            ? [...$target, ...$permissions]
            : ['allOf' => [$target, $spelledSiblings], ...$permissions];
    }

    /**
     * Whether a union branch naming `$ref` is typed by a class written for the branch itself, rather than
     * by the class the reference names: what `unionBranchTypeOfItsOwn()` decides, with a bare reference
     * resolved against the document whose constraints are being extracted.
     *
     * @param array<string, mixed> $branch
     */
    private function unionRefBranchIsAClassOfItsOwn(string $ref, array $branch): bool
    {
        if (!array_key_exists('$ref', $branch)) {
            return $this->unionBranchTypeOfItsOwn($branch, '') === 'class';
        }
        $refClass = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
        $definition = $this->dtoSchemas[$refClass] ?? null;

        // `referencedObjectSharedWithSiblings()`: only an `unevaluatedProperties` beside an object
        // reference merges the two into a class of the branch's own.
        return array_key_exists('unevaluatedProperties', $branch)
            && !array_key_exists('allOf', $branch)
            && is_array($definition)
            && $this->isObjectDtoComponent($refClass)
            && !array_key_exists('oneOf', $definition)
            && !array_key_exists('anyOf', $definition);
    }

    /**
     * What a `$ref` union branch is checked by, in this mode.
     *
     * Laravel and yii3 validate the raw payload, so the branch is spelled out: an enum as its members,
     * an object as its properties. Symfony and runtime hydrate the class the reference names, so the
     * branch is "is that class" — except where no class is hydrated: a free-form object is typed
     * `array`, and is spelled out in every mode. `[]` when nothing can judge it.
     *
     * A target that admits null itself — `nullable: true`, or 3.1's `type: [object, "null"]` — carries
     * that `nullable` onto the branch, as an inline branch does: the class check and the spelled-out
     * object both refused the null the reference allows.
     *
     * @return array<string, mixed>
     */
    private function unionRefTarget(string $ref): array
    {
        // A branch naming an alias (`Alias: {$ref: Hen}`) is the class the alias names: the alias gets
        // no class of its own, and a marker naming it refused every payload (symfony).
        $target = $this->unionRefTargetChecks($this->pureAliasEndRef($ref));
        if (
            $target === []
            || (
                !$this->componentAdmitsNull($this->localComponentDefinition($ref) ?? [])
                && !$this->componentAdmitsNull($this->localComponentDefinition($this->pureAliasEndRef($ref)) ?? [])
            )
        ) {
            return $target;
        }

        return [...$target, 'nullable' => true];
    }

    /**
     * The `$ref` a local alias component's chain ends at (`redirectPureAliasReferences()`); any other
     * `$ref` as it is.
     */
    private function pureAliasEndRef(string $ref): string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return $ref;
        }

        return $this->pureAliasClasses[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? $ref;
    }

    /**
     * The checks of `unionRefTarget()`, before the target's own null is carried.
     *
     * @return array<string, mixed>
     */
    private function unionRefTargetChecks(string $ref): array
    {
        if (array_key_exists($ref, $this->spelledOutUnionRefs)) {
            return [];
        }
        $spelledOutBefore = $this->spelledOutUnionRefs;
        $this->spelledOutUnionRefs[$ref] = true;
        try {
            $branchClass = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
            $definition = $this->dtoSchemas[$branchClass] ?? null;
            if (is_array($definition) && $this->isFreeFormObjectSchema($definition)) {
                return $this->extractValidationConstraints($definition);
            }
            if (in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true)) {
                return $this->laravelSpelledOutRefBranch($ref);
            }
            if ($this->attributeMode === self::ATTRIBUTE_MODE_YII3) {
                $inlined = $this->inlineNestedContainerValidation(schema: ['$ref' => $ref], belowMaterialization: true);

                return array_key_exists('$ref', $inlined) ? [] : $this->extractValidationConstraints($inlined);
            }
            // Runtime gets the name only of a class that is emitted: a reference to anything else stays
            // as unjudgeable as before.
            $isEmittedClass = array_key_exists($branchClass, $this->dtoSchemas) || array_key_exists($branchClass, $this->enumSchemas);
            if ($branchClass === 'mixed' || (!$isEmittedClass && $this->attributeMode !== self::ATTRIBUTE_MODE_SYMFONY)) {
                return [];
            }
            // Runtime: a union component of classes is spelled as its members, as the bare `$ref` is
            // (`runtimeClassUnionSpelledOut()`). "Is that interface" named no member to build, and
            // `DtoDeserializer` refused every object as "Unsupported type".
            if ($this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME) {
                $spelled = $this->runtimeClassUnionSpelledOut(['$ref' => $ref]);
                if (!array_key_exists('$ref', $spelled)) {
                    return $this->extractValidationConstraints($spelled);
                }
            }

            // The qualified name in every mode: Symfony's interpreter resolved a short one against its
            // own namespace, so a member of an external document (`--ref`, another namespace) matched
            // nothing — `{"sable": {"robin": false}}` "does not match any oneOf branch".
            $qualifiedBranchClass = ltrim(($this->schemaNamespaces[$branchClass] ?? $this->enumNamespaces[$branchClass] ?? $this->baseNamespace) . '\\' . $branchClass, '\\');

            // Symfony: a union component of classes keeps "is that interface" for a built member, and
            // carries its members written out for the decoded array a `mixed` property holds.
            if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && is_array($definition) && $this->rendersAsUnion($definition)) {
                return $this->symfonyUnionComponentBranch(ref: $ref, interface: $qualifiedBranchClass);
            }

            return ['x-php-instanceof' => $qualifiedBranchClass];
        } finally {
            $this->spelledOutUnionRefs = $spelledOutBefore;
        }
    }

    /**
     * Whether a `oneOf`/`anyOf` has a branch that is only null: `{type: "null"}`, `{type: ["null"]}`, or
     * the 3.0 `{nullable: true}` with nothing else.
     *
     * @param array<string, mixed> $schema
     */
    private function unionHasNullBranch(array $schema): bool
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $type = $branch['type'] ?? null;
                if ($type === 'null' || $type === ['null']) {
                    return true;
                }
                if (($branch['nullable'] ?? null) === true && $this->isNullOnlyBranch($branch)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The hop ceiling for `$ref` inlining below materialization — the BLOWUP guard, not the cycle one.
     *
     * A cycle is stopped by `REF_REPEAT_LIMIT`; this is what stops the other shape, an ACYCLIC schema
     * that branches, where nothing ever repeats and there is no cycle to catch.
     * Measured on a DAG of three distinct components per level, each referring to all three of the
     * next: the emitted constraints grow about 2.9x PER LEVEL — 70 KB at depth five, 194 KB at six,
     * 568 KB and 68 MB at seven. Five hops is where that curve is still cheap (36 KB for the same
     * depth-seven document) and deeper than any chain measured in a real one.
     *
     * On a linear chain the cost is linear instead — the whole demo corpus moves by a few hundred
     * bytes across every value from two to twenty — so this ceiling is about the branching case only.
     */
    private const int REF_INLINE_BUDGET = 5;

    /**
     * How often ONE `$ref` may repeat on a single path — the CYCLE guard.
     *
     * Two, so a self-referential component is unrolled to the same depth the hop budget of 2.15.6-8
     * gave it: the block-wise diff of the golden corpus shows `TreeNode` byte-identical in four modes
     * and GAINING a level in the fifth. One would have cost the recursive forms a level of checking,
     * which is a regression paid for nothing — the chain case this release fixes is about refs that
     * DON'T repeat.
     */
    private const int REF_REPEAT_LIMIT = 2;

    /**
     * Applicators holding ONE subschema, walked so a `$ref` inside them is inlined.
     *
     * Each of these describes a value nothing materializes for — no property to type, no class to
     * write — so a reference under them was left for the scrubber, and dropping it dropped the whole
     * keyword: a subschema that extracts to `[]` matches everything. `contains`, `unevaluatedItems`
     * and `prefixItems` were added in 2.15.14, `not` in 2.15.16 once it was measured that inlining a
     * RESOLVED reference cannot turn into a false rejection, and the conditional trio plus
     * `propertyNames` in the same release after the sweep found them losing references too.
     */
    private const array SINGLE_SUBSCHEMA_APPLICATORS = [
        'contains',
        'unevaluatedItems',
        'not',
        'if',
        'then',
        'else',
        'propertyNames',
    ];

    /**
     * A discriminated `oneOf` / `anyOf` of `$ref`s held as the decoded value — a list item, a map
     * value, anything below where a class is built — as the dispatch its tag makes: the tag one of
     * the mapped values, and the member it names checked by an `if` / `then` on it.
     *
     * Left a `oneOf` of the members' schemas, a value both members accept apart from the tag
     * (`{"kind": "mail", "stamp": "s"}` beside a `Crate` that requires only `kind`) matched twice and
     * was refused in every mode, and one of the other member (`{"kind": "crate", "slats": "x"}`)
     * passed as a `Mail`: the tag was read only once exactly one branch had matched. A value without
     * the tag is refused at the tag, as the runtime and a root laravel-data union refuse it: until
     * 2.15.164 it was judged by the union as written, passed when one member took it, and the
     * hydration — which dispatches by the tag alone — then had no class to build. Anything else —
     * a member written inline, a mapping naming a schema the union does not list — comes back as it
     * was.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function discriminatedUnionAsTagDispatch(array $schema): array
    {
        $tag = $schema['discriminator']['propertyName'] ?? null;
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($schema)));
        if (!is_string($tag) || count($keywords) !== 1 || !is_array($schema[$keywords[0]]) || array_key_exists('$ref', $schema)) {
            return $schema;
        }
        $refs = [];
        $nullable = ($schema['nullable'] ?? null) === true;
        foreach ($schema[$keywords[0]] as $member) {
            if (is_array($member) && array_keys($member) === ['type'] && $member['type'] === 'null') {
                $nullable = true;

                continue;
            }
            if (!$this->isAnnotatedReference($member)) {
                return $schema;
            }
            $refs[] = $member['$ref'];
        }
        $mapping = $schema['discriminator']['mapping'] ?? null;
        $mapping = is_array($mapping) && $mapping !== [] ? $mapping : $this->implicitDiscriminatorMapping(['oneOf' => array_map(static fn(string $ref): array => ['$ref' => $ref], $refs)]);
        if ($mapping === []) {
            return $schema;
        }
        // An object must carry the tag, one of the mapped values — the discriminator is what picks the
        // class that gets built, so a value without it is refused at the tag even where a member does
        // not require it: the hydration has nothing to dispatch on. Asked of an object only: what else
        // the value may be is the `type` below — a `nullable` written beside the whole (a container's
        // null item) must not meet the tag.
        $allOf = [
            ...(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []),
            [
                'if' => ['type' => 'object'],
                'then' => [
                    'required' => [$tag],
                    'properties' => [$tag => ['enum' => array_map('strval', array_keys($mapping)), 'x-php-inline-enum' => true]],
                ],
            ],
        ];
        foreach ($mapping as $value => $ref) {
            if (!is_string($ref) || !in_array($ref, $refs, true)) {
                return $schema;
            }
            $allOf[] = [
                'if' => ['type' => 'object', 'required' => [$tag], 'properties' => [$tag => ['const' => (string)$value]]],
                'then' => ['$ref' => $ref],
            ];
        }
        $dispatched = [];
        foreach ($schema as $keyword => $value) {
            if (!in_array($keyword, [$keywords[0], 'discriminator', 'nullable', 'allOf'], true)) {
                $dispatched[$keyword] = $value;
            }
        }
        if (!array_key_exists('type', $dispatched)) {
            $dispatched['type'] = $nullable ? ['object', 'null'] : 'object';
        }

        return [...$dispatched, 'allOf' => $allOf];
    }

    /**
     * Everything at depth two or deeper inside a container, made validatable.
     *
     * At depth ONE the generator materializes: `items: {$ref: StrEnum}` becomes a PHP enum and
     * `items: {enum: [...]}` becomes a synthesized one, so the value's own type is the check and the
     * constraints deliberately carry no `enum` (see `extractValidationConstraints()`).
     *
     * Below that it materializes nothing — `resolveNestedContainerDocType()` declares `mixed` for
     * exactly that reason — and the same two spellings were then checked by NOTHING: a `$ref` is not
     * a constraint keyword, so `scrubUnvalidatableSubschemas()` dropped the whole subschema, and an
     * unmarked `enum` was filtered out of it. `array<array<StrEnum>>` holding the string `"zzz"`, or
     * an integer, came back from `validate()` with no problems at all.
     *
     * So at depth two and below: a `$ref` is inlined even when it names a backed-enum schema (there is
     * no class here to carry the check), and every `enum` is marked for inline validation.
     *
     * @param array<string, mixed> $schema
     * @param int $depth how many containers were entered to reach this schema
     * @param bool $belowMaterialization whether no class is generated for anything from here down
     * @param int $refInlineBudget how many more `$ref`s may be inlined on this path — see below
     * @param array<string, int> $seenRefs how often each `$ref` is already inlined on THIS path
     * @return array<string, mixed>
     */
    private function inlineNestedContainerValidation(
        array $schema,
        int $depth = 0,
        bool $belowMaterialization = false,
        int $refInlineBudget = self::REF_INLINE_BUDGET,
        array $seenRefs = [],
    ): array {
        // Two containers down, nothing is materialized any more — and that stays true for everything
        // INSIDE what gets inlined here, however many `properties` hops away it is. A depth counter
        // alone said otherwise: a property of an inlined object read as depth 0, so its own `$ref`
        // was left for the scrubber to drop and the values under it went unchecked again.
        $belowMaterialization = $belowMaterialization || $depth >= 2;

        // A `$ref` is an in-place applicator like an `allOf` branch: what its target evaluates counts for
        // an `unevaluated*` written beside it. The reference itself is the PHP type — a class or a list
        // — and no constraint, so the extraction dropped it and the keyword beside it saw none of the
        // target's keys: `{$ref: Pair, prefixItems: [{…}], unevaluatedItems: false}` refused the second
        // item Pair evaluates. Beside such a keyword the target is carried as one more `allOf` branch,
        // inlined as a schema nothing materializes, on this path's budget and cycle count.
        $ref = $schema['$ref'] ?? null;
        if (
            is_string($ref)
            && (array_key_exists('unevaluatedProperties', $schema) || array_key_exists('unevaluatedItems', $schema))
            && $refInlineBudget > 0
            && ($seenRefs[$ref] ?? 0) < self::REF_REPEAT_LIMIT
        ) {
            $target = $this->inlineNestedContainerValidation(
                schema: ['$ref' => $ref],
                depth: $depth,
                belowMaterialization: true,
                refInlineBudget: $refInlineBudget,
                seenRefs: $seenRefs,
            );
            if (!array_key_exists('$ref', $target)) {
                $schema['allOf'] = [...(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []), $target];
            }
        }

        // A `$ref` to a `type: array` component is inlined at EVERY level, materialized or not: such a
        // reference is typed as the aliased list and never becomes a class, so no class validates it
        // either. Left as a bare `$ref` it was scrubbed, and the property, list item or map value lost
        // its `type`, `items` and bounds in every mode — Laravel emitted no `array`/`list` rule at all.
        // Counted in `$seenRefs`, so an alias whose items lead back to it stops at `REF_REPEAT_LIMIT`.
        $ref = $this->referenceOrSingleRefAllOf($schema);
        if ($ref !== null && ($seenRefs[$ref] ?? 0) < self::REF_REPEAT_LIMIT) {
            $inlined = $this->inlineArrayAliasForConstraints($schema);
            if ($inlined !== $schema) {
                $seenRefs[$ref] = ($seenRefs[$ref] ?? 0) + 1;
                $schema = $inlined;
            }
        }

        if ($belowMaterialization) {
            $ref = $schema['$ref'] ?? null;
            if (is_string($ref) && $refInlineBudget <= 0) {
                $this->reportInlineCeilingReached($ref);
            }

            if (is_string($ref) && $refInlineBudget > 0 && ($seenRefs[$ref] ?? 0) < self::REF_REPEAT_LIMIT) {
                $definition = $this->nestedScalarRefDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile)
                    ?? $this->nestedObjectRefDefinition($ref, $this->constraintSourceFile);
                if ($definition !== null) {
                    // TWO guards, because there are two ways this runs away, and neither one catches
                    // the other's shape.
                    //
                    // `$seenRefs` stops a CYCLE: a component that refers to itself, however many hops
                    // around, is unrolled `REF_REPEAT_LIMIT` times and no more. It counts per PATH —
                    // the array is copied into every branch below, so two siblings never see each
                    // other's marks — and it is what lets a plain chain be inlined for as long as it
                    // keeps naming something NEW.
                    //
                    // A per-path set was tried in 2.15.5 and exhausted memory, which is why 2.15.6
                    // fell back to inlining a single level. The reason it failed then is gone now:
                    // `extractValidationConstraints()` re-enters itself for every `oneOf` branch, for
                    // `not`, and once more from the scrubber, and back then the inlining ran on every
                    // entry with a FRESH set, so a self-referential component peeled off one more
                    // level per pass. Since 2.15.6 it runs once, in the outermost extraction, and
                    // walks the whole tree itself, so the set is no longer refilled behind its own
                    // back. Measured on the recursive corpus forms: 12 MB and 0.04s, the same numbers
                    // a plain hop budget gave.
                    //
                    // `$refInlineBudget` stops the other shape — see `REF_INLINE_BUDGET`. Nothing
                    // repeats on an acyclic branching path, so the set never fires there, and the
                    // constraints grow exponentially with depth if nothing else says stop.
                    //
                    // What this replaced: one hop and no more, which was enough wherever the chain
                    // MATERIALIZES (`items: {$ref: B}` becomes a DTO and that DTO applies the same
                    // rule to C) and one hop short wherever it does not. Under a union branch below a
                    // container nothing materializes, so A -> B -> C -> D was checked at B, at C from
                    // 2.15.8, and never at D. Now it is checked as deep as the document is named.
                    $seenRefs[$ref] = ($seenRefs[$ref] ?? 0) + 1;
                    $refInlineBudget--;
                    unset($schema['$ref']);
                    $schema += $definition;
                    // Symfony checks an inlined member of a discriminated union by the tag, which names
                    // the member by its class: the branch keeps that name, as the extraction reads it.
                    if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
                        $schema['x-php-inlined-member'] = $this->schemaRefToClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
                    }

                    // What was just inlined is written in ITS document: a relative `$ref` inside
                    // `rows.yml#/…/Cell` is relative to `rows.yml`, not to the file that referred to
                    // it. Walked in the referrer's context, `enums.yml` next to `rows.yml` was looked
                    // up next to the referrer instead — "Referenced OpenAPI file not found".
                    $className = $this->nestedRefClassName($ref, $this->constraintSourceFile);
                    $definitionSource = $className === null ? null : $this->getSchemaSourceFile($className);
                    if ($definitionSource !== null && $definitionSource !== $this->constraintSourceFile) {
                        $sourceFileBefore = $this->constraintSourceFile;
                        $this->constraintSourceFile = $definitionSource;
                        try {
                            return $this->inlineNestedContainerValidation(
                                schema: $schema,
                                depth: $depth,
                                belowMaterialization: $belowMaterialization,
                                refInlineBudget: $refInlineBudget,
                                seenRefs: $seenRefs,
                            );
                        } finally {
                            $this->constraintSourceFile = $sourceFileBefore;
                        }
                    }
                }
            }

            if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
                $schema['x-php-inline-enum'] = true;
            }
        }

        foreach (['items', 'additionalProperties'] as $containerKey) {
            if (is_array($schema[$containerKey] ?? null)) {
                $schema[$containerKey] = $this->inlineNestedContainerValidation(
                    schema: $schema[$containerKey],
                    depth: $depth + 1,
                    // A tuple is typed a bare list: `items` beside `prefixItems` is the tail, and no
                    // item class is written for it — the `$ref` there named a class nothing hydrates.
                    belowMaterialization: $belowMaterialization || ($containerKey === 'items' && array_key_exists('prefixItems', $schema)),
                    refInlineBudget: $refInlineBudget,
                    seenRefs: $seenRefs,
                );
            }
        }

        // A BRANCH of an inline `oneOf`/`anyOf`, once there is a container above it.
        //
        // On a PROPERTY such a union becomes a PHP union type — `Circle|Square` — so the value is a
        // generated DTO and validates itself; nothing to do. Under a container the property type
        // collapses to `array` and the branch type is gone with it, so a `$ref` there materializes
        // NOTHING. It also never reached the inlining above, which only walks containers and
        // properties, so the branch stayed a bare `$ref`, `extractValidationConstraints()` returned
        // `[]` for it, and — because one empty branch makes a union unenforceable — the WHOLE
        // subschema was dropped. Measured: a map of `oneOf[$ref Leaf | map of $ref Leaf]` emitted
        // `['type' => 'object']` and nothing below it was checked by anything.
        //
        // `allOf` is walked too, and only for the same reason. Until 2.15.11 it was skipped outright,
        // on the rule that a single-`$ref` `allOf` is how this generator spells inheritance and that
        // ref DOES get a class. Measured, that rule holds on a PROPERTY (`f: {allOf: [$ref M]}` is
        // hydrated as `M` and `M` checks itself) and inside a MAP, and fails under `items`: there the
        // property collapses to a bare `array`, no class is written for the item, and the emitted
        // constraints were `['type' => 'array']` and nothing else — `required`, bounds and every level
        // below them silently gone. The guard is `$belowMaterialization`, which is false on a property,
        // so inheritance is untouched; a branch is walked only where nothing materializes to carry it.
        //
        // An `unevaluated*` in the tree is the other reason: it reads what every branch EVALUATED, and
        // a `$ref` branch left for the class it names is a branch it cannot read — `allOf: [{$ref: A}],
        // unevaluatedProperties: false` refused the keys A declares.
        if ($depth >= 1 || $belowMaterialization) {
            $schema = $this->discriminatedUnionAsTagDispatch($schema);
        }
        $evaluatesInPlace = $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedProperties')
            || $this->inPlaceTreeUses(schema: $schema, keyword: 'unevaluatedItems');
        foreach (['oneOf', 'anyOf', 'allOf'] as $unionKey) {
            if (!is_array($schema[$unionKey] ?? null)) {
                continue;
            }

            foreach ($schema[$unionKey] as $index => $branch) {
                if (is_array($branch)) {
                    $schema[$unionKey][$index] = $this->inlineNestedContainerValidation(
                        schema: $branch,
                        depth: $depth,
                        belowMaterialization: $belowMaterialization
                            || $depth >= 1
                            || $evaluatesInPlace
                            || ($unionKey !== 'allOf' && $this->isContainerBranch($branch))
                            || ($unionKey !== 'allOf' && $this->laravelValidatesInlineBranch($branch)),
                        refInlineBudget: $refInlineBudget,
                        seenRefs: $seenRefs,
                    );
                }
            }
        }

        // An APPLICATOR whose subschema is a `$ref`, at ANY level.
        //
        // `contains`, `prefixItems` and `unevaluatedItems` each describe a value nothing materializes
        // for: there is no property to type and no class to write, so a `$ref` under them is left for
        // `scrubUnvalidatableSubschemas()` to drop — and dropping it drops the whole keyword, because a
        // subschema that extracts to `[]` is a subschema that matches everything. Measured on one
        // component and one payload: with the schema written INLINE the violating payload is refused,
        // written as a `$ref` it is accepted, in silence. That is the 2.15.6 union-branch bug and the
        // 2.15.11 `allOf` bug in a third spelling.
        //
        // `belowMaterialization: true` unconditionally, and that is the whole point: unlike `allOf`,
        // these keywords never materialize anything, not even on a property, so there is no level at
        // which the inlining would be redundant.
        //
        // `not` was held back until 2.15.16 for a reason worth keeping: an empty subschema matches
        // EVERYTHING, so a dropped `not` accepts what it should reject, while a WRONGLY inlined one
        // would reject what it should accept — and that costs valid requests rather than silent passes.
        // Measured before it was added: `not: {$ref Forbidden}` accepted the very payload the reference
        // forbids, on a property and under a container alike, while the same subschema written inline
        // refused it — and the VALID payload was accepted in all four, which is what says inlining the
        // reference cannot turn into a false rejection here. The empty-subschema danger is unchanged:
        // an unresolvable `$ref` is left alone and dropped exactly as before, because the inlining only
        // ever replaces a reference it could resolve.
        foreach (self::SINGLE_SUBSCHEMA_APPLICATORS as $applicatorKey) {
            if (is_array($schema[$applicatorKey] ?? null)) {
                $schema[$applicatorKey] = $this->inlineNestedContainerValidation(
                    schema: $schema[$applicatorKey],
                    depth: $depth,
                    belowMaterialization: true,
                    refInlineBudget: $refInlineBudget,
                    seenRefs: $seenRefs,
                );
            }
        }

        // The applicators that hold a MAP of subschemas rather than one. Same loss, same fix: a `$ref`
        // under `dependentSchemas` or `patternProperties` extracted to `[]` and took its entry with it.
        foreach (['dependentSchemas', 'patternProperties'] as $mapApplicatorKey) {
            if (!is_array($schema[$mapApplicatorKey] ?? null)) {
                continue;
            }

            foreach ($schema[$mapApplicatorKey] as $entryKey => $entrySchema) {
                if (is_array($entrySchema)) {
                    $schema[$mapApplicatorKey][$entryKey] = $this->inlineNestedContainerValidation(
                        schema: $entrySchema,
                        depth: $depth,
                        belowMaterialization: true,
                        refInlineBudget: $refInlineBudget,
                        seenRefs: $seenRefs,
                    );
                }
            }
        }

        if (is_array($schema['prefixItems'] ?? null)) {
            foreach ($schema['prefixItems'] as $index => $positionSchema) {
                if (is_array($positionSchema)) {
                    $schema['prefixItems'][$index] = $this->inlineNestedContainerValidation(
                        schema: $positionSchema,
                        depth: $depth,
                        belowMaterialization: true,
                        refInlineBudget: $refInlineBudget,
                        seenRefs: $seenRefs,
                    );
                }
            }
        }

        // A PROPERTY restarts the container count — it is a value of its own, and where an item DID
        // become a DTO that class runs this for itself. What it does not restart is
        // `$belowMaterialization`: below the first level of items there is no class to run it. Nor
        // below a property typed `mixed`: a `$ref` under an untyped schema names a class nothing
        // hydrates, and left for the scrubber it took its subschema with it.
        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (is_array($propertySchema)) {
                    $schema['properties'][$name] = $this->inlineNestedContainerValidation(
                        schema: $propertySchema,
                        depth: 0,
                        belowMaterialization: $belowMaterialization || $this->staysUnmaterialized($propertySchema),
                        refInlineBudget: $refInlineBudget,
                        seenRefs: $seenRefs,
                    );
                }
            }
        }

        return $this->scalarAllOfFlattened($this->foldNullableReferenceAllOf($schema));
    }

    /**
     * Whether an INLINE `oneOf`/`anyOf` branch is checked from its spelled-out schema rather than by the
     * class it becomes. Laravel validates the raw payload before anything is hydrated, and it never runs
     * a union member's own rules, so the branch class checks nothing: an inline `{type: object,
     * properties: {hue: {enum: [amber]}}}` moved its enum into the PHP enum type, the map kept `type:
     * string`, and `{"hue": "teal"}` reached the enum cast — a 500. A `$ref` branch is spelled out by
     * `unionRefTarget()` already.
     *
     * @param array<string, mixed> $branch
     */
    private function laravelValidatesInlineBranch(array $branch): bool
    {
        return in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true)
            && $this->referenceOrSingleRefAllOf($branch) === null;
    }

    /**
     * Whether a `oneOf`/`anyOf` branch is a LIST or a MAP — inline or behind a `$ref` to an array alias
     * or a map component. A union holding one is typed a bare `array` (`?array` beside a null branch)
     * in every mode, so nothing hydrates its items or values: `oneOf: [{type: array, items: {$ref:
     * Cell}}, {type: null}]` left `items` for the class that is never built, and `[{}]` passed where
     * the same list without the null branch was refused.
     *
     * @param array<string, mixed> $branch
     */
    private function isContainerBranch(array $branch): bool
    {
        $ref = $this->referenceOrSingleRefAllOf($branch);
        if ($ref !== null) {
            return $this->arrayAliasDefinition($ref) !== null || $this->mapComponentDefinition($ref) !== null;
        }
        $type = $branch['type'] ?? null;
        if ($type === 'array' || $type === ['array'] || array_key_exists('items', $branch)) {
            return true;
        }

        return !array_key_exists('properties', $branch)
            && (is_array($branch['additionalProperties'] ?? null) || is_array($branch['patternProperties'] ?? null));
    }

    /**
     * A scalar written as an `allOf` of plain fragments is checked as the flat scalar it spells, in
     * every mode: `{allOf: [{type: integer}, {maximum: 15}]}` is `{type: integer, maximum: 15}`. Left
     * split, a split alias inlined as one member of a wider `allOf` (`{allOf: [{allOf: [{type: number},
     * {maximum: 0}]}, {minimum: -5}]}`) was dropped from the symfony and yii3 table as if a nested DTO
     * checked it. Only a merge that ends a scalar: an object's `allOf` keeps its shape.
     *
     * A node's own 3.0 `nullable` beside a split `allOf` is not merged in: it relaxes the node's own
     * `type`, and each fragment's `type` still refuses `null` (README.validation: folding must not spread
     * `nullable` into another branch). A `nullable` beside a REFERENCE to a split scalar alias does
     * relax it: `aliasChainInlined()` inlines the alias as the flat scalar first.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function scalarAllOfFlattened(array $schema): array
    {
        $merged = $this->allOfFragmentsMerged(schema: $schema, depth: 0);
        $type = $merged['type'] ?? null;
        if ($merged !== null && in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
            return $this->withInlineEnumMarked($merged);
        }

        return $schema;
    }

    /**
     * The `enum` of a merged split-scalar fragment was checked where it stood; merged up, it would read
     * as the property's backed enum, which a split scalar's PHP type is not.
     *
     * @param array<string, mixed> $merged
     * @return array<string, mixed>
     */
    private function withInlineEnumMarked(array $merged): array
    {
        return is_array($merged['enum'] ?? null) && $merged['enum'] !== [] ? [...$merged, 'x-php-inline-enum' => true] : $merged;
    }

    /**
     * OpenAPI 3.0 has no nullable `$ref`, so a document writes one as `nullable: true` beside a
     * one-branch `allOf` — and the generator reads it that way, declaring the property `?Person`. Once
     * the branch is inlined, though, `nullable` sat one level above the `type: object` it was meant to
     * relax: the null passed the outer schema and failed the branch, "must be of type object". The
     * branch is lifted next to its `nullable`, where it means what the document meant. Only an inlined
     * branch, and only when no keyword appears on both levels — anything else stays as written.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function foldNullableReferenceAllOf(array $schema): array
    {
        $allOf = $schema['allOf'] ?? null;
        if (($schema['nullable'] ?? false) !== true || !is_array($allOf) || count($allOf) !== 1) {
            return $schema;
        }
        $branch = $allOf[array_key_first($allOf)];
        if (!is_array($branch) || array_key_exists('$ref', $branch)) {
            return $schema;
        }
        $outer = [];
        foreach ($schema as $keyword => $value) {
            if ($keyword !== 'allOf') {
                $outer[$keyword] = $value;
            }
        }

        return array_intersect_key($branch, $outer) === [] ? [...$outer, ...$branch] : $schema;
    }

    /**
     * Reports a `$ref` chain cut off by `REF_INLINE_BUDGET`, so the silence has a voice.
     *
     * Everything below the ceiling is accepted unchecked. That is a deliberate trade — the alternative
     * is an emitted file that grows ~2.9x per level on a branching document — but a consumer whose
     * document is that deep has no way to notice: the generated code simply does not mention those
     * levels, and a payload violating them passes.
     *
     * Only the CEILING is reported. The other stop, `REF_REPEAT_LIMIT` on a self-referential component,
     * is not a truncation a consumer can act on — a recursive schema has no finite inline form, and
     * warning about it would fire on every recursive document. Measured before this was written: across
     * the whole corpus and test suite the ceiling is reached ZERO times and the cycle guard 107, which
     * is the difference between a warning that means something and noise.
     */
    private function reportInlineCeilingReached(string $ref): void
    {
        $definition = $this->nestedScalarRefDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile)
            ?? $this->nestedObjectRefDefinition($ref, $this->constraintSourceFile);
        if ($definition === null) {
            return;
        }

        $warning = sprintf(
            'A `$ref` chain reached the %d-hop inline limit at "%s". Nothing materializes into a class '
            . 'on this path — it runs under a union branch or below the second container — so the rules '
            . 'of that component and of everything under it are NOT in the generated checks, and a '
            . 'payload violating them is accepted. Shorten the chain, or lift the deep part to a '
            . 'property of its own, where a class is generated and checks itself.',
            self::REF_INLINE_BUDGET,
            $ref,
        );

        if (!in_array($warning, $this->generationWarnings, true)) {
            $this->generationWarnings[] = $warning;
        }
    }

    /**
     * The class a `$ref` met while building a constraint names, in whichever document it points to:
     * resolved against the document of the schema being rendered, the root spec otherwise.
     *
     * The constraint builders that asked "is this a component?" read `#/components/schemas/` only, so
     * a reference into another document was nothing to them — yii3 left a discriminated union of
     * another document undispatched and an enum of another document unchecked below a container.
     */
    private function constraintRefClassName(string $ref): ?string
    {
        return $this->nestedRefClassName($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
    }

    /**
     * {@see nestedScalarRefDefinition()} for a `$ref` met while building a constraint, in whichever
     * document it points to — see {@see constraintRefClassName()}.
     *
     * @return array<string, mixed>|null
     */
    private function constraintRefScalarDefinition(string $ref): ?array
    {
        return $this->nestedScalarRefDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile);
    }

    /**
     * The class a nested `$ref` names, without registering anything: a local pointer by its schema
     * name, a pointer into another document resolved against `$currentSourceFile`. Null for anything
     * that does not name a component schema.
     *
     * Only local pointers were understood here, so a `$ref: 'rows.yml#/components/schemas/Cell'` below
     * a map of maps typed its values `mixed` and checked nothing in them, where the same component
     * declared locally gave `array<string, array<string, Cell>>` and validated every cell.
     */
    private function nestedRefClassName(string $ref, ?string $currentSourceFile): ?string
    {
        $prefix = '#/components/schemas/';
        if (str_starts_with($ref, $prefix)) {
            return $this->schemaClassName($this->componentNameInPointer($ref));
        }

        $resolved = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
        $schemaName = $resolved === null ? null : $this->externalPointerSchemaName($resolved[1]);

        return $schemaName === null ? null : $this->schemaClassName($schemaName);
    }

    /**
     * The definition behind a `$ref` to an OBJECT-shaped component, for a position where no class is
     * generated for it.
     *
     * At the first level of items that ref becomes a DTO and the DTO checks itself. Below it nothing
     * does: `array<array<Tag>>` accepted an item with `id` missing, with `id` below its `minimum`, and
     * even a bare string where the object belonged — every one of them silently, because `$ref` is not
     * a constraint keyword and `scrubUnvalidatableSubschemas()` dropped the whole subschema.
     *
     * Inlining the component's own `properties`/`required` — or the `allOf`/`oneOf`/`anyOf` that carry
     * them — puts those checks back. The VALUE is still
     * not hydrated — it stays the `stdClass` `json_decode()` produced, which is why the declared type
     * remains `array<array<mixed>>`.
     *
     * A free-form object is skipped: it declares nothing to check, and inlining `{type: object}` would
     * only add a type assertion the container already makes.
     *
     * @return array<string, mixed>|null
     */
    private function nestedObjectRefDefinition(string $ref, ?string $currentSourceFile = null): ?array
    {
        $className = $this->nestedRefClassName($ref, $currentSourceFile);
        if ($className === null) {
            return null;
        }
        // An alias (`Amber: {$ref: Azure}`) is the component it names: its own definition is only the
        // reference, so nothing was inlined, and a Symfony union branch naming it stayed "is that
        // class" against a value that is never hydrated.
        $aliasEnd = $this->pureAliasClasses[$className] ?? null;
        if (is_string($aliasEnd)) {
            $className = $this->schemaClassName(substr($aliasEnd, strlen('#/components/schemas/')));
        }

        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition) || $this->isEnumSchema($definition)) {
            return null;
        }

        $hasProperties = is_array($definition['properties'] ?? null) && $definition['properties'] !== [];
        $hasRequired = is_array($definition['required'] ?? null) && $definition['required'] !== [];
        // A composed component declares its fields in its branches: an `allOf` child, a `oneOf` of
        // variants. It has neither keyword at the top, so it was skipped as free-form and a list of lists
        // of them checked nothing below the inner list. Its branches are walked once it is inlined.
        //
        // Not a union in Symfony mode by default: where its serializer builds the variant objects —
        // through the discriminator map — an inlined branch matched against such an object reads its
        // private state as missing, so a valid payload was refused. Below a container held as the
        // decoded array nothing is built, and there the Symfony emitter asks for the union written
        // out (`constraintInlinesUnionComponents`, see `symfonyContainerHoldsUndiscriminatedUnion()`).
        $compositionKeys = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && !$this->constraintInlinesUnionComponents
            ? ['allOf']
            : ['allOf', 'oneOf', 'anyOf'];
        $isComposed = false;
        foreach ($compositionKeys as $compositionKey) {
            $isComposed = $isComposed || (is_array($definition[$compositionKey] ?? null) && $definition[$compositionKey] !== []);
        }

        return $hasProperties || $hasRequired || $isComposed ? $definition : null;
    }

    /**
     * The definition behind a `$ref` used at depth two or deeper: any scalar-typed schema, an enum
     * included.
     *
     * `scalarAliasDefinition()` refuses a backed-enum schema because at depth one that ref DOES get a
     * class. Here it does not, so refusing would leave the values unchecked.
     *
     * @return array<string, mixed>|null
     */
    private function nestedScalarRefDefinition(string $ref, ?string $currentSourceFile): ?array
    {
        $alias = $this->scalarAliasDefinition($ref, $currentSourceFile);
        if ($alias !== null) {
            return $alias;
        }

        $pointed = $this->schemaInsideComponent($ref);
        if ($pointed !== null) {
            return $pointed;
        }

        // An enum component in ANOTHER document counts too: only local pointers were looked up here, so
        // `unit: {$ref: '../d/units.yml#/components/schemas/Unit'}` inside a value two maps down was
        // dropped from the inlined constraints and a unit outside the enum was accepted.
        $className = $this->nestedRefClassName($ref, $currentSourceFile);
        if ($className === null) {
            return null;
        }

        $definition = $this->dtoSchemas[$className] ?? null;
        if (
            is_array($definition)
            && $this->isEnumSchema($definition)
            && in_array($definition['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
        ) {
            return $definition;
        }

        // A named enum COMPONENT is registered as an enum rather than a schema, and its members live
        // under `values`. Spelled back into `enum` here, which is the keyword a constraint carries.
        $enum = $this->enumSchemas[$className] ?? null;
        if (
            $enum !== null
            && $enum['values'] !== []
            && in_array($enum['type'], ['string', 'int', 'integer', 'number', 'boolean'], true)
        ) {
            // A registered enum carries its PHP backing type: `int`, not the schema's `integer`. Only
            // `string` was recognised, so an integer enum two containers down was validated by nothing.
            return ['type' => $enum['type'] === 'int' ? 'integer' : $enum['type'], 'enum' => $enum['values']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function markInlineEnumValidation(array $schema): array
    {
        if (
            is_array($schema['enum'] ?? null)
            && $schema['enum'] !== []
            && !$this->canGenerateBackedEnumFromSchema($schema)
        ) {
            $schema['x-php-inline-enum'] = true;
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->markInlineEnumValidation($schema['items']);
        }

        return $schema;
    }

    /**
     * An item's enum members, kept in the constraints although the item became a PHP enum.
     *
     * The enum TYPE is the check only where something casts to it, and not every mode does: yii3's
     * hydrator left the items strings, so `["z"]` passed, and Laravel refused it at hydration — a
     * ValueError, a 500 — because no `field.*` rule existed. The members let the interpreter judge an
     * item whatever it holds; a hydrated enum compares by its backing value.
     *
     * @param array<string, mixed> $extracted
     * @param array<string, mixed> $itemSchema
     * @return array<string, mixed>
     */
    private function withItemEnumMembers(array $extracted, array $itemSchema): array
    {
        if (array_key_exists('enum', $extracted)) {
            return $extracted;
        }
        $members = $itemSchema['enum'] ?? null;
        $ref = $itemSchema['$ref'] ?? null;
        // Any document, not only local pointers: an enum of another document as a map value or a list
        // item kept nothing, and yii3 accepted `{"ka": 5}` and `{"ka": null}` against `enum: [-6]`. The
        // members alone: the target's `type` beside them reported a query value that is no member
        // twice, once as the wrong type it stayed uncast.
        if (!is_array($members) && is_string($ref)) {
            $members = $this->constraintRefScalarDefinition($ref)['enum'] ?? null;
        }

        return is_array($members) && $members !== [] ? ['enum' => $members] + $extracted : $extracted;
    }

    /**
     * `{}`, `true`, or a subschema of annotations only: it matches every value.
     *
     * Such a subschema extracts to `[]` exactly like an unreadable `$ref`, and every guard that drops
     * an empty extraction dropped it too — `not: {}` then refused nothing and `if: {}` never applied its
     * `then`. The two have to be told apart BEFORE the extraction erases the difference.
     */
    private function isVacuousSubschema(mixed $schema): bool
    {
        if ($schema === true) {
            return true;
        }
        if (!is_array($schema)) {
            return false;
        }
        foreach (array_keys($schema) as $keyword) {
            if (
                !is_string($keyword)
                || (!in_array($keyword, self::ANNOTATION_ONLY_KEYWORDS, true) && !str_starts_with($keyword, 'x-'))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a schema holds a `$ref`, or an `allOf` branch that is one: its `properties` then narrow
     * the referenced class at the use site rather than describe a class of their own.
     *
     * @param array<string, mixed> $schema
     */
    private function hasReferenceBranch(array $schema): bool
    {
        if (is_string($schema['$ref'] ?? null)) {
            return true;
        }
        foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $branch) {
            if (is_array($branch) && is_string($branch['$ref'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively re-extracts subschema-bearing constraint keys so that $ref-only / otherwise
     * unvalidatable subschemas don't survive verbatim into the generated constraints (the
     * validator can't resolve $ref and would either silently skip or — for `if` — falsely match).
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private function scrubUnvalidatableSubschemas(array $constraints, bool $propertiesBesideReference = false): array
    {
        // Single-subschema keys: drop the key entirely when it extracts to nothing.
        foreach (['items', 'contains', 'propertyNames', 'if', 'then', 'else', 'contentSchema'] as $key) {
            if (!array_key_exists($key, $constraints) || !is_array($constraints[$key])) {
                continue;
            }
            // The always-true schema is not "nothing": `if: {}` always applies `then`, `contains: {}`
            // still wants one item, `items: {}` evaluates every item for `unevaluatedItems`.
            if ($this->isVacuousSubschema($constraints[$key])) {
                $constraints[$key] = true;

                continue;
            }
            $extracted = $this->extractValidationConstraints($constraints[$key]);
            if ($key === 'items') {
                $extracted = $this->withItemEnumMembers($extracted, $constraints[$key]);
            }
            if ($extracted === []) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $extracted;
            }
        }

        // additionalProperties / unevaluatedProperties / unevaluatedItems may be a bool
        // (keep verbatim) or a schema (scrub). A schema that scrubs to [] means "allow
        // anything" — a no-op for these keywords — so the key is dropped.
        foreach (['additionalProperties', 'unevaluatedProperties', 'unevaluatedItems'] as $boolOrSchemaKey) {
            if (!array_key_exists($boolOrSchemaKey, $constraints) || !is_array($constraints[$boolOrSchemaKey])) {
                continue;
            }
            // `additionalProperties: {}` evaluates the extra keys, which `unevaluatedProperties` reads.
            if ($this->isVacuousSubschema($constraints[$boolOrSchemaKey])) {
                $constraints[$boolOrSchemaKey] = true;

                continue;
            }
            $extracted = $this->extractValidationConstraints($constraints[$boolOrSchemaKey]);
            if ($boolOrSchemaKey === 'additionalProperties') {
                $extracted = $this->withItemEnumMembers($extracted, $constraints[$boolOrSchemaKey]);
            }
            if ($extracted === []) {
                unset($constraints[$boolOrSchemaKey]);
            } else {
                $constraints[$boolOrSchemaKey] = $extracted;
            }
        }

        // Schema-map keys: scrub each value, drop empties; drop the whole key if none remain.
        $judgesOtherKeys = array_key_exists('additionalProperties', $constraints) || array_key_exists('unevaluatedProperties', $constraints);
        foreach (['properties', 'patternProperties', 'dependentSchemas'] as $key) {
            if (!array_key_exists($key, $constraints) || !is_array($constraints[$key])) {
                continue;
            }
            $scrubbed = [];
            foreach ($constraints[$key] as $name => $subSchema) {
                // `false` admits nothing, which makes that entry the entire point of the keyword —
                // `dependentSchemas: {a: false}` says "an object carrying `a` is invalid". Skipped
                // with the unvalidatable subschemas, it said nothing at all. `true` constrains
                // nothing and is dropped like any subschema that scrubs to empty.
                if ($subSchema === false) {
                    $scrubbed[$name] = false;

                    continue;
                }
                // `true` / `{}` checks nothing but still EVALUATES the key: `additionalProperties:
                // false` and `unevaluatedProperties` must not see it as an extra one.
                if ($this->isVacuousSubschema($subSchema)) {
                    $scrubbed[$name] = true;

                    continue;
                }
                if (!is_array($subSchema)) {
                    continue;
                }
                $extracted = $this->extractValidationConstraints($subSchema);
                // A property's enum moved into a PHP enum only where its object became a class that
                // declares it. Beside a reference the object is the referenced class, whose property
                // never heard of the narrowing: `allOf: [$ref: Coral], properties: {bee: {enum: [3]}}`
                // lost its members and accepted any `bee`. A hydrated enum compares by its value.
                if ($propertiesBesideReference && $key === 'properties') {
                    $extracted = $this->withItemEnumMembers($extracted, $subSchema);
                }
                if ($extracted !== []) {
                    $scrubbed[$name] = $extracted;
                } elseif ($key !== 'dependentSchemas' && $judgesOtherKeys) {
                    // A DECLARED key whose schema extracts to nothing — a `$ref` to the class that
                    // checks it — is still declared: dropped, `additionalProperties` judged it as an
                    // extra key, and `{lid: {$ref: Shape}}, additionalProperties: false` refused `lid`.
                    $scrubbed[$name] = true;
                }
            }
            if ($scrubbed === []) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $scrubbed;
            }
        }

        // prefixItems is positional — keep an empty slot as [] (a no-op constraint) rather than
        // shifting indices.
        if (array_key_exists('prefixItems', $constraints) && is_array($constraints['prefixItems'])) {
            $scrubbed = [];
            foreach ($constraints['prefixItems'] as $subSchema) {
                // A BOOLEAN member turned into `[]` here, and for `false` that is its exact opposite:
                // `[]` is the empty schema and the empty schema accepts everything, so a document
                // closing a tuple position emitted a class that took anything there. `true` really is
                // the empty schema, so only `false` needs saying — kept in the spelling
                // `DtoValidator::expandBooleanSubschemas()` already reads.
                if (is_bool($subSchema)) {
                    $scrubbed[] = $subSchema === false ? false : [];

                    continue;
                }
                $scrubbed[] = is_array($subSchema) ? $this->extractValidationConstraints($subSchema) : [];
            }
            $constraints['prefixItems'] = $scrubbed;
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function applyDiscriminatorPropertyEnumIfNeeded(
        string $ownerClassName,
        string $propertyName,
        array $propertySchema,
    ): array {
        $discriminator = $this->discriminatorSchemas[$ownerClassName] ?? null;
        if ($discriminator === null || $discriminator['propertyName'] !== $propertyName) {
            return $propertySchema;
        }

        $propertySchema['type'] = 'string';
        $propertySchema['enum'] = array_keys($discriminator['mapping']);

        return $propertySchema;
    }

    /**
     * A property's type, its references read against the document that declares its owner: a class is
     * typed while ANOTHER is rendered too (a value class of another document's map), and a reference
     * into a third document is relative to the owner's, not to the class being written.
     *
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolvePropertyType(array $propertySchema, string $ownerClassName, string $propertyName): array
    {
        $sourceFileBefore = $this->constraintSourceFile;
        $this->constraintSourceFile = $this->getSchemaSourceFile($ownerClassName) ?? $sourceFileBefore;
        try {
            return $this->resolvePropertyTypeOfOwner($propertySchema, $ownerClassName, $propertyName);
        } finally {
            $this->constraintSourceFile = $sourceFileBefore;
        }
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolvePropertyTypeOfOwner(array $propertySchema, string $ownerClassName, string $propertyName): array
    {
        $propertySchema = $this->normalizeNullableBranchInAllOf($propertySchema);
        $nullable = $this->schemaAllowsNull($propertySchema);

        // B: a $ref pointing to a scalar property of an external schema is inlined as that scalar,
        // so we neither emit a bogus class name nor pull in the whole external document.
        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            $inlinedScalar = $this->tryInlineExternalScalarRef(
                ref: $propertySchema['$ref'],
                currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            if ($inlinedScalar !== null) {
                if ($nullable) {
                    $inlinedScalar['nullable'] = true;
                }

                return $this->resolvePropertyType(
                    propertySchema: $inlinedScalar,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
            }
        }

        if (array_key_exists('allOf', $propertySchema) && is_array($propertySchema['allOf'])) {
            // A BOOLEAN member is a legal subschema — `true` is the empty schema, `false` is the one
            // nothing satisfies — and neither carries a type. Every reader below assumes an array:
            // `array_key_exists('$ref', false)` is a TypeError, and it was an uncaught one, so a
            // document with `allOf: [false]` anywhere took the whole run down with a stack trace and
            // wrote no files at all, in all five modes.
            //
            // The members are dropped HERE, for the type decision only. The constraints keep the
            // boolean untouched, which is what makes the document still mean something:
            // `DtoValidator` reads `allOf: [false]` in that spelling already and refuses every value
            // against it. Nothing to expand, nothing to translate.
            $schemaMembers = array_values(array_filter(
                array: $propertySchema['allOf'],
                callback: static fn(mixed $member): bool => is_array($member),
            ));

            // Only booleans were there. `false` admits nothing and `true` constrains nothing, so
            // either way no type can be read off the keyword and the property stays `mixed`.
            if ($schemaMembers === []) {
                return ['mixed', $nullable];
            }

            $propertySchema['allOf'] = $schemaMembers;

            $normalizedAllOf = $this->normalizeAllOfPropertySchema($propertySchema);
            if ($normalizedAllOf !== null) {
                return $this->resolvePropertyType(
                    propertySchema: $normalizedAllOf,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
            }

            // Keep legacy allOf behavior for refs/objects: single ref -> ref type, multi-part -> merged DTO.
            if (count($propertySchema['allOf']) === 1 && array_key_exists('$ref', $propertySchema['allOf'][0])) {
                $binaryType = $this->resolveBinaryRefType((string)$propertySchema['allOf'][0]['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($binaryType !== null) {
                    return [$binaryType, $nullable];
                }

                $temporalType = $this->resolveTemporalRefType((string)$propertySchema['allOf'][0]['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($temporalType !== null) {
                    return [$temporalType, $nullable];
                }

                // A scalar alias has no class: the wrapped member itself, with what stands beside the
                // `allOf` merged into it (`{allOf: [{$ref: Mauve, multipleOf: 2}], nullable: true}`), is
                // the value. Typed by the alias's name, the property named a class never written.
                $member = $this->allOfWrappedReference($propertySchema);
                if (
                    is_string($member['$ref'] ?? null)
                    && $this->scalarAliasDefinition($member['$ref'], $this->getSchemaSourceFile($ownerClassName)) !== null
                ) {
                    /** @var array<string, mixed> $member */
                    [$memberType, $memberNullable] = $this->resolvePropertyType(
                        propertySchema: $this->inlineScalarAliasRef($member, $ownerClassName),
                        ownerClassName: $ownerClassName,
                        propertyName: $propertyName,
                    );

                    return [$memberType, $nullable || $memberNullable];
                }

                $refType = $this->schemaRefToClassName(
                    ref: $propertySchema['allOf'][0]['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                return [$refType, $nullable];
            }

            // allOf wrapping a single composed union (e.g. `allOf: [{ oneOf: [...] }]`) resolves to
            // the union type, not an empty merged object. Without this the property collapses to a
            // standalone empty DTO whose toArray() is always [].
            // No `is_array()` on the member any more — the filter above left only arrays here, and
            // that is the whole point of it: every reader below this line used to assume as much.
            if (count($propertySchema['allOf']) === 1) {
                $only = $propertySchema['allOf'][0];
                foreach (['oneOf', 'anyOf'] as $unionKeyword) {
                    if (array_key_exists($unionKeyword, $only) && is_array($only[$unionKeyword])) {
                        [$unionType, $unionNullable] = $this->resolveComposedUnionPropertyType(
                            propertySchema: $only,
                            keyword: $unionKeyword,
                            ownerClassName: $ownerClassName,
                            propertyName: $propertyName,
                        );
                        return [$unionType, $nullable || $unionNullable];
                    }
                }
            }

            // Branches that describe no key — `allOf: [{maximum: 30}, {minimum: 20}]`, an `if` in one
            // branch and its `then` in another — admit any value. Merged into a class, they refused
            // the number they were written for with `Cannot deserialize nested DTO … from non-array
            // value`; the value stays `mixed` and the `allOf` is checked as the constraint it is.
            if (!$this->shapesAnObject($propertySchema)) {
                return ['mixed', $nullable];
            }

            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $mergedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $this->recordSynthesizedOrigin($mergedClassName, $ownerClassName, $propertyName);
            return [$mergedClassName, $nullable];
        }

        if (array_key_exists('oneOf', $propertySchema) && is_array($propertySchema['oneOf'])) {
            return $this->resolveComposedUnionPropertyType(
                propertySchema: $propertySchema,
                keyword: 'oneOf',
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
        }

        if (array_key_exists('anyOf', $propertySchema) && is_array($propertySchema['anyOf'])) {
            return $this->resolveComposedUnionPropertyType(
                propertySchema: $propertySchema,
                keyword: 'anyOf',
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
        }

        // `{$ref: Swatch, properties: {alpha}, unevaluatedProperties: false}` describes ONE object the
        // reference and the keywords beside it share — the same object `allOf: [$ref Swatch]` with those
        // keywords beside it describes. Typed as Swatch, the value lost every key Swatch does not
        // declare on hydration: `alpha` went unchecked and an undeclared key could no longer be refused.
        $sharedObject = $this->referencedObjectSharedWithSiblings($propertySchema, $ownerClassName);
        if ($sharedObject !== null) {
            $mergedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $mergedClassName,
                schemaDefinition: $sharedObject,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $this->recordSynthesizedOrigin($mergedClassName, $ownerClassName, $propertyName);

            return [$mergedClassName, $nullable];
        }

        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            $binaryType = $this->resolveBinaryRefType($propertySchema['$ref'], $this->getSchemaSourceFile($ownerClassName));
            if ($binaryType !== null) {
                return [$binaryType, $nullable];
            }

            $temporalType = $this->resolveTemporalRefType($propertySchema['$ref'], $this->getSchemaSourceFile($ownerClassName));
            if ($temporalType !== null) {
                return [$temporalType, $nullable];
            }

            // A $ref to a component schema whose top-level type is `array` is a type alias, not an
            // object: resolve it to the aliased array type (e.g. array<Item>) so the property
            // is typed as a list, instead of pointing at an empty generated class.
            $aliasArrayType = $this->resolveArrayAliasRefType(
                ref: $propertySchema['$ref'],
                ownerClassName: $ownerClassName,
                propertyName: $propertyName,
            );
            if ($aliasArrayType !== null) {
                return [$aliasArrayType, $nullable];
            }

            return [
                $this->schemaRefToClassName(
                    ref: $propertySchema['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                ),
                $nullable,
            ];
        }

        if (
            array_key_exists('enum', $propertySchema) && is_array(
                value: $propertySchema['enum'],
            ) && $propertySchema['enum'] !== []
            && $this->canGenerateBackedEnumFromSchema($propertySchema)
        ) {
            $parentEnumType = $this->resolveParentEnumTypeForOverride($ownerClassName, $propertyName, $propertySchema);
            if ($parentEnumType !== null) {
                return [$parentEnumType, $nullable];
            }

            $enumName = $ownerClassName . $this->normalizeClassName($propertyName);
            $type = $this->resolveEnumBackingType($propertySchema);
            /** @var array<int, string|int> $values */
            $values = $propertySchema['enum'];
            $this->registerEnum(
                enumName: $enumName,
                type: $type,
                values: $values,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
                varnames: $this->extractEnumVarnames($propertySchema, $values),
                descriptions: $this->extractEnumDescriptions($propertySchema, $values),
            );
            $this->recordSynthesizedOrigin($enumName, $ownerClassName, $propertyName);
            return [$enumName, $nullable];
        }

        $type = $propertySchema['type'] ?? null;

        if (is_array($type)) {
            $nonNullTypes = array_values(
                array: array_filter($type, static fn(mixed $item): bool => is_string($item) && $item !== 'null'),
            );
            $nullable = count($nonNullTypes) !== count($type);

            if (count($nonNullTypes) > 1) {
                // OAS 3.1 multi-type: type: [string, integer]  →  string|int
                $phpUnionParts = array_values(
                    array: array_unique(
                        array: array_map(
                            callback: static fn(string $t): string => match ($t) {
                                'integer' => 'int',
                                'number' => 'float',
                                'string' => 'string',
                                'boolean' => 'bool',
                                'array' => 'array',
                                default => 'mixed',
                            },
                            array: $nonNullTypes,
                        ),
                    ),
                );

                // `mixed` cannot stand in a union — PHP refuses `string|mixed` at COMPILE time, so a
                // document writing `type: [string, object]` produced a file that could not be loaded at
                // all. One member that maps to nothing narrower therefore collapses the whole union:
                // `mixed` already includes every other member, so nothing is lost but the declaration.
                // It also swallows the nullability, because `?mixed` is a fatal for the same reason.
                if (in_array('mixed', $phpUnionParts, true)) {
                    return ['mixed', false];
                }

                return [implode('|', $phpUnionParts), $nullable];
            }

            $type = $nonNullTypes[0] ?? 'mixed';
        }

        if (!is_string($type)) {
            return ['mixed', $nullable];
        }

        if ($type === 'string') {
            $formatType = $this->mapStringFormatType($propertySchema['format'] ?? null);
            if ($formatType !== null) {
                return [$formatType, $nullable];
            }
        }

        if ($type === 'object') {
            if ($this->isPatternPropertiesOnlyObjectSchema($propertySchema)) {
                $mapValueType = $this->resolvePatternPropertiesValueType(
                    propertySchema: $propertySchema,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
                return ['array<string, ' . $mapValueType . '>', $nullable];
            }

            if ($this->isMapLikeObjectSchema($propertySchema)) {
                $mapValueType = $this->resolveAdditionalPropertiesValueType(
                    propertySchema: $propertySchema,
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName,
                );
                return ['array<string, ' . $mapValueType . '>', $nullable];
            }

            $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName);
            $this->registerSchema(
                className: $nestedClassName,
                schemaDefinition: $propertySchema,
                sourceFile: $this->getSchemaSourceFile($ownerClassName),
            );
            $this->recordSynthesizedOrigin($nestedClassName, $ownerClassName, $propertyName);
            return [$nestedClassName, $nullable];
        }

        if ($type === 'array') {
            $items = $propertySchema['items'] ?? null;

            // `items` beside `prefixItems` judges only the positions past the tuple: typed by it, the
            // list made `[-75, "a"]` of `prefixItems: [{type: integer}], items: {type: string}` a list
            // of strings, and position 0 was refused. The members are told apart by the validator.
            if (!is_array($items) || $this->hasPrefixItems($propertySchema)) {
                return ['array', $nullable];
            }

            // An item that names a nullable array alias admits null like the alias does.
            $itemNullable = $this->schemaAllowsNull($items) || $this->arrayAliasAllowsNull(
                ref: is_string($items['$ref'] ?? null) ? $items['$ref'] : null,
            );
            $itemPrefix = $itemNullable ? '?' : '';

            if (array_key_exists('$ref', $items) && is_string($items['$ref'])) {
                $binaryItemType = $this->resolveBinaryRefType($items['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($binaryItemType !== null) {
                    return ['array<' . $itemPrefix . $binaryItemType . '>', $nullable];
                }

                $temporalItemType = $this->resolveTemporalRefType($items['$ref'], $this->getSchemaSourceFile($ownerClassName));
                if ($temporalItemType !== null) {
                    return ['array<' . $itemPrefix . $temporalItemType . '>', $nullable];
                }

                // A component whose top level is a CONTAINER is a type alias, not an object — the
                // same thing the property level says two hundred lines up, and it has to be said
                // here too. Naming the class instead produced code that could not run: a `$ref` to
                // a `type: array` component wrote `array<StrList>` and no `StrList.php`, so a VALID
                // payload died with `unknown type "…\StrList"`; a `$ref` to a map component wrote
                // `array<IntMap>` and an EMPTY class, so `{"f":[{"a":7}]}` came back as `{"f":[{}]}`
                // — the keys silently gone.
                $aliasItemType = $this->resolveArrayAliasRefType(
                    ref: $items['$ref'],
                    ownerClassName: $ownerClassName,
                    propertyName: $propertyName . 'Item',
                    inAContainer: true,
                );
                if ($aliasItemType !== null) {
                    return ['array<' . $itemPrefix . $aliasItemType . '>', $nullable];
                }

                $memberUnion = $this->memberUnionOfRef($items['$ref']);
                if ($memberUnion !== null) {
                    return ['array<' . $this->composePhpTypeHint($memberUnion, $itemNullable) . '>', $nullable];
                }

                return [
                    'array<' . $itemPrefix . $this->schemaRefToClassName(
                        ref: $items['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                    ) . '>',
                    $nullable,
                ];
            }

            if (array_key_exists('enum', $items) && is_array($items['enum']) && $items['enum'] !== []) {
                if ($this->canGenerateBackedEnumFromSchema($items)) {
                    $enumName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                    $enumType = $this->resolveEnumBackingType($items);
                    /** @var array<int, string|int> $values */
                    $values = $items['enum'];
                    $this->registerEnum(
                        enumName: $enumName,
                        type: $enumType,
                        values: $values,
                        sourceFile: $this->getSchemaSourceFile($ownerClassName),
                        varnames: $this->extractEnumVarnames($items, $values),
                        descriptions: $this->extractEnumDescriptions($items, $values),
                    );
                    $this->recordSynthesizedOrigin($enumName, $ownerClassName, $propertyName, arrayItem: true);
                    return ['array<' . $itemPrefix . $enumName . '>', $nullable];
                }

                $itemEnumType = $items['type'] ?? null;
                if ($itemEnumType === 'boolean') {
                    return ['array<' . $itemPrefix . 'bool>', $nullable];
                }
            }

            $itemsType = $this->soleTypeBesidesNull($items['type'] ?? null);

            // An object ITEM behind a composition — `items: {allOf: [{type: object, …}]}`, `{anyOf:
            // [{type: object, …}, {type: null}]}` — is an object with its own class, as the same schema
            // as a property or a map value is. Left to the type mapping below it became `mixed` and
            // stayed the decoded array: an empty item `{}` was written back as `[]`.
            $composedItemClass = $itemsType === null
                ? $this->composedObjectItemClass($items, $ownerClassName, $propertyName)
                : null;
            if ($composedItemClass !== null) {
                [$itemClassName, $itemClassNullable] = $composedItemClass;

                return ['array<' . $this->composePhpTypeHint($itemClassName, $itemNullable || $itemClassNullable) . '>', $nullable];
            }

            // A list of lists. Left to the scalar mapping below it landed on `default => 'mixed'`, so
            // `array<array<int>>` was declared `array<mixed>` — and `mixed` is the one item type
            // `DtoNormalizer::validate()` skips, which is why a matrix with a scalar where a row
            // belonged passed in silence.
            if ($itemsType === 'array') {
                return [
                    'array<' . $itemPrefix . $this->resolveNestedContainerDocType(
                        schema: $items,
                        currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                    ) . '>',
                    $nullable,
                ];
            }

            if ($itemsType === 'object') {
                // Free-form / pattern-keyed item objects have no fixed properties: synthesizing a
                // DTO for them would yield a class with an empty constructor and the payload data
                // would be dropped at deserialization. Keep them as maps instead.
                if ($this->isPatternPropertiesOnlyObjectSchema($items) || $this->isMapLikeObjectSchema($items)) {
                    return [
                        'array<' . $itemPrefix . $this->resolveNestedContainerDocType(
                            schema: $items,
                            currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                        ) . '>',
                        $nullable,
                    ];
                }

                $nestedClassName = $ownerClassName . $this->normalizeClassName($propertyName) . 'Item';
                $this->registerSchema(
                    className: $nestedClassName,
                    schemaDefinition: $items,
                    sourceFile: $this->getSchemaSourceFile($ownerClassName),
                );
                $this->recordSynthesizedOrigin($nestedClassName, $ownerClassName, $propertyName, arrayItem: true);
                return ['array<' . $itemPrefix . $nestedClassName . '>', $nullable];
            }

            if ($itemsType === 'string') {
                $itemsFormatType = $this->mapStringFormatType($items['format'] ?? null);
                if ($itemsFormatType !== null) {
                    return ['array<' . $itemPrefix . $itemsFormatType . '>', $nullable];
                }
            }

            if (is_string($itemsType)) {
                $mapped = match ($itemsType) {
                    'integer' => 'int',
                    'number' => 'float',
                    'string' => 'string',
                    'boolean' => 'bool',
                    default => 'mixed',
                };

                return ['array<' . $this->composePhpTypeHint($mapped, $itemNullable) . '>', $nullable];
            }

            return ['array', $nullable];
        }

        return [
            match ($type) {
                'integer' => 'int',
                'number' => 'float',
                'string' => 'string',
                'boolean' => 'bool',
                default => 'mixed',
            },
            $nullable,
        ];
    }

    /**
     * The class of a list item that is ONE object behind a composition: an `allOf` that merges into a
     * class, or an `anyOf` / `oneOf` whose only branch besides `null` is such an object. Resolved the
     * way a map value is — through `resolvePropertyType()` under the name `<Owner><Property>Item` —
     * and null for anything else (a union of two objects, a `$ref` that names its own class, a
     * scalar), which keeps the item type it had.
     *
     * @param array<string, mixed> $items
     * @return array{string, bool}|null the class name and whether the composition admits null
     */
    private function composedObjectItemClass(array $items, string $ownerClassName, string $propertyName): ?array
    {
        if (is_array($items['allOf'] ?? null)) {
            $isOneObject = $this->unionBranchTypeOfItsOwn($items, $ownerClassName) === 'class';
        } else {
            $keyword = is_array($items['anyOf'] ?? null) ? 'anyOf' : (is_array($items['oneOf'] ?? null) ? 'oneOf' : null);
            if ($keyword === null) {
                return null;
            }
            $branches = array_values(array_filter(
                array: $items[$keyword],
                callback: fn(mixed $branch): bool => !is_array($branch) || !$this->isNullBranch($branch),
            ));
            $isOneObject = count($branches) === 1 && is_array($branches[0])
                && $this->unionBranchTypeOfItsOwn($branches[0], $ownerClassName) === 'class';
        }
        if (!$isOneObject) {
            return null;
        }

        $itemPropertyName = $propertyName . 'Item';
        [$itemType, $itemNullable] = $this->resolvePropertyType($items, $ownerClassName, $itemPropertyName);
        if ($itemType !== $ownerClassName . $this->normalizeClassName($itemPropertyName)) {
            return null;
        }
        $this->recordSynthesizedOrigin($itemType, $ownerClassName, $propertyName, arrayItem: true);

        return [$itemType, $itemNullable];
    }

    /**
     * A union branch that only adds `null`: `{type: null}`, or the 3.0 idiom `{nullable: true}` with
     * nothing else that would type it — the two `resolveComposedUnionPropertyType()` skips.
     *
     * @param array<string, mixed> $branch
     */
    private function isNullBranch(array $branch): bool
    {
        return ($branch['type'] ?? null) === 'null'
            || (($branch['nullable'] ?? false) === true && $this->isNullOnlyBranch($branch));
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function normalizeNullableBranchInAllOf(array $propertySchema): array
    {
        if (!array_key_exists('allOf', $propertySchema) || !is_array($propertySchema['allOf'])) {
            return $propertySchema;
        }

        $nullable = (bool)($propertySchema['nullable'] ?? false);
        $filteredAllOf = [];
        $hadNullableBranch = false;

        foreach ($propertySchema['allOf'] as $item) {
            if (is_array($item) && count($item) === 1 && ($item['nullable'] ?? null) === true) {
                $nullable = true;
                $hadNullableBranch = true;
                continue;
            }

            $filteredAllOf[] = $item;
        }

        if (!$hadNullableBranch) {
            return $propertySchema;
        }

        $normalized = $propertySchema;
        $normalized['allOf'] = $filteredAllOf;
        $normalized['nullable'] = $nullable;

        return $normalized;
    }

    /**
     * If child schema overrides inherited enum with subset values,
     * reuse parent enum type to keep constructor/parent signature compatible.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveParentEnumTypeForOverride(
        string $ownerClassName,
        string $propertyName,
        array $propertySchema,
    ): ?string {
        $childValues = $propertySchema['enum'] ?? null;
        if (!is_array($childValues) || $childValues === []) {
            return null;
        }

        $parentClassName = $this->resolveSingleParentClassName($ownerClassName);
        if ($parentClassName === null) {
            return null;
        }

        $parentProperties = $this->indexPropertiesByName(
            properties: $this->deduplicatePropertiesByLastDefinition($this->getParentProperties($parentClassName)),
        );
        $parentProperty = $parentProperties[$this->normalizePropertyName($propertyName)] ?? null;
        if (!is_array($parentProperty)) {
            return null;
        }

        $parentType = $parentProperty['type'];
        if (!array_key_exists($parentType, $this->enumSchemas)) {
            return null;
        }

        $parentEnum = $this->enumSchemas[$parentType];
        $parentValues = $parentEnum['values'];
        if ($parentValues === []) {
            return null;
        }

        foreach ($childValues as $childValue) {
            if (!in_array($childValue, $parentValues, true)) {
                return null;
            }
        }

        return $parentType;
    }

    private function resolveSingleParentClassName(string $className): ?string
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($schemaDefinition)) {
            return null;
        }

        $allOf = $schemaDefinition['allOf'] ?? null;
        if (!is_array($allOf)) {
            return null;
        }

        $ref = null;
        foreach ($allOf as $item) {
            if (!is_array($item) || !array_key_exists('$ref', $item) || !is_string($item['$ref'])) {
                continue;
            }

            if ($ref !== null) {
                return null;
            }

            $ref = $item['$ref'];
        }

        if ($ref === null) {
            return null;
        }

        return $this->schemaRefToClassName(
            ref: $ref,
            currentSourceFile: $this->getSchemaSourceFile($className),
        );
    }

    /**
     * The schema of one object a `$ref` to an object class and an `unevaluatedProperties` beside it
     * describe together, written as the `allOf` this generator already merges — or null when the
     * reference stands alone, names no object class, or nothing beside it reads its keys.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>|null
     */
    private function referencedObjectSharedWithSiblings(array $propertySchema, string $ownerClassName): ?array
    {
        $ref = $propertySchema['$ref'] ?? null;
        if (!is_string($ref) || !array_key_exists('unevaluatedProperties', $propertySchema) || array_key_exists('allOf', $propertySchema)) {
            return null;
        }

        $refClassName = $this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($ownerClassName));
        $definition = $this->dtoSchemas[$refClassName] ?? null;
        if (
            !is_array($definition)
            || !$this->isObjectDtoComponent($refClassName)
            || array_key_exists('oneOf', $definition)
            || array_key_exists('anyOf', $definition)
        ) {
            return null;
        }

        $shared = [];
        foreach ($propertySchema as $keyword => $value) {
            if ($keyword !== '$ref') {
                $shared[$keyword] = $value;
            }
        }
        // The target's schema itself rather than its reference: one `$ref` branch is how this generator
        // spells inheritance, and the property's class has no business extending the component.
        $shared['allOf'] = [$definition];

        return $shared;
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>|null
     */
    private function normalizeAllOfPropertySchema(array $propertySchema): ?array
    {
        $allOf = $propertySchema['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === []) {
            return null;
        }

        // A part that is an `allOf` of its own — a scalar alias written `{allOf: [{type: string}]}`,
        // inlined beside `{format: date}` — is its parts: `allOf` nests associatively. Kept whole, the
        // part was no flat fragment and the date lost its type (`mixed` in symfony).
        $allOf = $this->nestedAllOfPartsFlattened(parts: $allOf, depth: 0);

        // `unevaluatedProperties` reads what each branch evaluated ON ITS OWN, so the branches cannot be
        // poured into the one class schema the object path registers: last-wins turned
        // `{unevaluatedProperties: false, allOf: [{unevaluatedProperties: true}]}` into one `false` and
        // the class refused the keys the branch had let through. An array merges into nothing but its
        // PHP type — its constraints keep the `allOf` — so `unevaluatedItems` needs no such care.
        foreach ([$propertySchema, ...$allOf] as $part) {
            if (is_array($part) && array_key_exists('unevaluatedProperties', $part)) {
                return null;
            }
        }

        $resolved = [];
        foreach ($allOf as $item) {
            if (!is_array($item) || !$this->canFlattenAllOfPropertyItem($item)) {
                return null;
            }

            // last-wins: each next allOf part overwrites previous keys
            $resolved = array_replace_recursive($resolved, $item);
        }

        $topLevel = $propertySchema;
        unset($topLevel['allOf']);
        $resolved = array_replace_recursive($resolved, $topLevel);

        // Only a merge that ends up describing a concrete value is usable here: a branch set of
        // pure constraints (`allOf: [{minLength: 3}]`) says nothing about the PHP type, so leave
        // such a schema to the regular object path instead of inventing a scalar.
        $describesValue = array_key_exists('type', $resolved)
            || array_key_exists('enum', $resolved)
            || array_key_exists('format', $resolved);

        return ($resolved !== [] && $describesValue) ? $resolved : null;
    }

    /**
     * The parts of an `allOf` with every part that is a bare `allOf` of its own — nothing beside it but
     * keywords that merge as fragments — replaced by its parts and its siblings.
     *
     * @param array<mixed> $parts
     * @return array<mixed>
     */
    private function nestedAllOfPartsFlattened(array $parts, int $depth): array
    {
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return $parts;
        }
        $flattened = [];
        foreach ($parts as $part) {
            $nested = is_array($part) ? $part['allOf'] ?? null : null;
            if (!is_array($part) || !is_array($nested) || !array_is_list($nested) || $nested === []) {
                $flattened[] = $part;

                continue;
            }
            $beside = [];
            foreach ($part as $keyword => $value) {
                if ($keyword !== 'allOf') {
                    $beside[$keyword] = $value;
                }
            }
            if (!$this->canFlattenAllOfPropertyItem($beside)) {
                $flattened[] = $part;

                continue;
            }
            foreach ($this->nestedAllOfPartsFlattened(parts: $nested, depth: $depth + 1) as $nestedPart) {
                $flattened[] = $nestedPart;
            }
            if ($beside !== []) {
                $flattened[] = $beside;
            }
        }

        return $flattened;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function canFlattenAllOfPropertyItem(array $item): bool
    {
        // Composition or an inline object means the branch is a schema in its own right — those
        // keep the class-synthesis path. Everything else (a type, an enum, or plain constraint
        // keywords such as `minLength` split into their own branch) merges into one property.
        return !array_key_exists('$ref', $item)
            && !array_key_exists('properties', $item)
            && !array_key_exists('allOf', $item)
            && !array_key_exists('oneOf', $item)
            && !array_key_exists('anyOf', $item);
    }

    /**
     * @param array<string, mixed> $propertySchema
     * @return array{0: string, 1: bool}
     */
    private function resolveComposedUnionPropertyType(
        array $propertySchema,
        string $keyword,
        string $ownerClassName,
        string $propertyName,
    ): array {
        $variants = $propertySchema[$keyword] ?? null;
        if (!is_array($variants)) {
            return ['mixed', (bool)($propertySchema['nullable'] ?? false)];
        }

        $nullable = (bool)($propertySchema['nullable'] ?? false);
        $types = [];
        $membersByBranch = [];
        $branchKinds = [];
        foreach ($variants as $index => $variant) {
            $branchKinds[$index] = is_array($variant)
                ? $this->unionBranchTypeOfItsOwn($variant, $ownerClassName) ?? $this->nestedUnionTypeOfItsOwn($variant, $ownerClassName, 0)
                : null;
        }
        // Two or more inline branches that each become a type — two objects too — each take a name of
        // their own; sharing the property's name stopped a valid document on a name collision.
        $nameBranchesApart = count(array_filter($branchKinds, static fn(?string $kind): bool => $kind !== null)) > 1;

        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            // Two inline branches that each become a type — two enums of different backing types, an
            // enum beside an object — would both take the property's name, and the second was a name
            // collision that stopped generation in every mode. Then each takes the branch's own name,
            // as a nested union member does (`collectUnionTypes()`); one such branch keeps the
            // property's name, as it always had.
            $branchPropertyName = $nameBranchesApart && $branchKinds[$index] !== null
                ? $propertyName . ($keyword === 'oneOf' ? 'OneOf' : 'AnyOf') . ((int)$index + 1)
                : $propertyName;

            if (($variant['type'] ?? null) === 'null') {
                $nullable = true;
                continue;
            }

            // OpenAPI 3.0 idiom for "X or null": a bare `{nullable: true}` branch (no type/$ref/
            // composition) just adds null to the union. Treat it as the null branch instead of
            // letting it resolve to `mixed` and collapse the whole union to mixed.
            if (($variant['nullable'] ?? false) === true && $this->isNullOnlyBranch($variant)) {
                $nullable = true;
                continue;
            }

            [$variantType, $variantNullable] = $this->resolvePropertyType($variant, $ownerClassName, $branchPropertyName);
            if ($variantNullable) {
                $nullable = true;
            }

            // A null branch LATER in the list still admits null: `anyOf: [{}, {type: null}]` stopped at
            // the `{}` and read as not nullable, so Symfony's `NotNull` refused the null it allows.
            // Under `oneOf` the null matching twice is the `oneOf`'s to refuse, which it checks itself.
            if ($variantType === 'mixed') {
                return ['mixed', $nullable || $this->unionListsANullBranch($variants)];
            }

            if (str_contains($variantType, '<')) {
                $variantType = 'array';
            }

            // A nested union comes back as one `string|array` string: split, so a member it shares with
            // a sibling (`array` beside it) is named once — PHP refuses `string|array|array` at load time.
            foreach (explode('|', $variantType) as $member) {
                $types[] = $member;
                $membersByBranch[$index][] = $member;
            }
        }

        $types = array_values(array_unique($types));
        $this->unionMembersByBranch[$ownerClassName . '|' . implode('|', $types)] = $membersByBranch;
        if ($types === []) {
            return ['mixed', $nullable];
        }

        if (count($types) === 1) {
            return [$types[0], $nullable];
        }

        return [implode('|', $types), $nullable];
    }

    /**
     * The type an inline union branch synthesizes under the name it is handed — `'enum'` for a backed
     * enum, `'class'` for an object with keys of its own — or null when it names none. A plain `$ref`
     * names its own type, and a map or a scalar is typed by PHP's own types; a `$ref` with keywords
     * beside it (written as an `allOf` by `objectReferenceWithItsSiblings()`, or shared with an
     * `unevaluatedProperties`) and an `allOf` around one object are classes of their own, merged
     * under the property's name like the bare object spelling.
     *
     * @param array<mixed> $branch
     * @return 'enum'|'class'|null
     */
    private function unionBranchTypeOfItsOwn(array $branch, string $ownerClassName, int $depth = 0): ?string
    {
        /** @var array<string, mixed> $branch */
        if (array_key_exists('$ref', $branch)) {
            return $this->referencedObjectSharedWithSiblings($branch, $ownerClassName) !== null ? 'class' : null;
        }
        if (is_array($branch['allOf'] ?? null)) {
            if ($depth >= self::SHAPE_PROBE_DEPTH) {
                return null;
            }
            // `allOf: [{type: integer, enum: [3, 4]}]` merges into the enum it wraps, and
            // `resolvePropertyType()` registers that enum under the name it is handed — so it is named
            // apart like the bare spelling, or it took the property's name beside another enum branch
            // and generation stopped on the collision.
            $merged = $this->normalizeAllOfPropertySchema($branch);
            if ($merged !== null) {
                return array_key_exists('allOf', $merged) ? null : $this->unionBranchTypeOfItsOwn($merged, $ownerClassName, $depth + 1);
            }

            return $this->allOfBranchMergesIntoAClass($branch) ? 'class' : null;
        }
        if ($this->isEnumBranch($branch)) {
            return 'enum';
        }

        return $this->soleTypeBesidesNull($branch['type'] ?? null) === 'object'
            && !$this->isMapLikeObjectSchema($branch)
            && !$this->isPatternPropertiesOnlyObjectSchema($branch)
            ? 'class'
            : null;
    }

    /**
     * What a branch that is itself an inline `oneOf`/`anyOf` synthesizes under the name it is handed:
     * the nested union passes that name on to its own inline enum or class, so the branch is named apart
     * like one that is that enum or class. A sibling object took the property's name too, and generation
     * stopped on the collision.
     *
     * @param array<string, mixed> $branch
     * @return 'enum'|'class'|null
     */
    private function nestedUnionTypeOfItsOwn(array $branch, string $ownerClassName, int $depth): ?string
    {
        $members = is_array($branch['oneOf'] ?? null) ? $branch['oneOf'] : ($branch['anyOf'] ?? null);
        if (!is_array($members) || $depth >= self::SHAPE_PROBE_DEPTH) {
            return null;
        }

        $kinds = [];
        foreach ($members as $member) {
            if (is_array($member)) {
                $kinds[] = $this->unionBranchTypeOfItsOwn($member, $ownerClassName, $depth + 1)
                    ?? $this->nestedUnionTypeOfItsOwn($member, $ownerClassName, $depth + 1);
            }
        }
        if (in_array('class', $kinds, true)) {
            return 'class';
        }

        return in_array('enum', $kinds, true) ? 'enum' : null;
    }

    /**
     * Whether `resolvePropertyType()` merges an `allOf` it cannot flatten into a class registered under
     * the property's name — the same steps it takes: not one `$ref` (typed as the target), not one union
     * (typed as the union), and describing an object.
     *
     * @param array<string, mixed> $branch
     */
    private function allOfBranchMergesIntoAClass(array $branch): bool
    {
        $members = array_values(array_filter(
            array: is_array($branch['allOf'] ?? null) ? $branch['allOf'] : [],
            callback: static fn(mixed $member): bool => is_array($member),
        ));
        if ($members === []) {
            return false;
        }
        if (count($members) === 1 && (array_key_exists('$ref', $members[0]) || array_key_exists('oneOf', $members[0]) || array_key_exists('anyOf', $members[0]))) {
            return false;
        }

        return $this->shapesAnObject($branch);
    }

    /**
     * Whether an inline union branch becomes a backed enum of its own.
     *
     * @param array<mixed> $branch
     */
    private function isEnumBranch(array $branch): bool
    {
        /** @var array<string, mixed> $branch */
        return !array_key_exists('$ref', $branch)
            && is_array($branch['enum'] ?? null)
            && $branch['enum'] !== []
            && $this->canGenerateBackedEnumFromSchema($branch);
    }

    /**
     * True when a union variant carries no type-bearing keyword — i.e. it only constrains
     * nullability (the OpenAPI 3.0 `{nullable: true}` idiom for adding null to a oneOf/anyOf).
     *
     * @param array<string, mixed> $variant
     */
    private function isNullOnlyBranch(array $variant): bool
    {
        foreach (
            ['type', '$ref', 'enum', 'const', 'oneOf', 'anyOf', 'allOf', 'not',
                'properties', 'items', 'additionalProperties', 'prefixItems', 'if'] as $key
        ) {
            if (array_key_exists($key, $variant)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function isMapLikeObjectSchema(array $propertySchema): bool
    {
        $properties = $propertySchema['properties'] ?? null;
        if (is_array($properties) && $properties !== []) {
            return false;
        }

        if (array_key_exists('additionalProperties', $propertySchema)) {
            return ($propertySchema['additionalProperties'] ?? null) !== false;
        }

        return $this->isFreeFormObjectSchema($propertySchema);
    }

    /**
     * A `type` list beside a `$ref` narrows the referenced schema, as every sibling keyword does under
     * JSON Schema 2020-12 — it cannot widen it. `{$ref: Swatch, type: [object, "null"]}` against a
     * `type: object` component admits no null: both have to hold. Read as a nullable reference, it
     * admitted `null` in every mode, and a scalar alias spelled so admitted it in yii3. `null` is
     * dropped from the list where the component's own `type` excludes it. 3.0's `nullable: true`
     * beside a `$ref` is untouched — README.validation explains that deliberate deviation.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function withoutNullTheReferenceRefuses(array $propertySchema): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        $type = $propertySchema['type'] ?? null;
        if (!is_string($ref) || !is_array($type) || !in_array('null', $type, true)) {
            return $propertySchema;
        }
        $definition = $this->localSchemaDefinitionForRef($ref);
        $referencedType = $definition['type'] ?? null;
        if ($definition === null || $referencedType === null || $this->schemaAllowsNull($definition) || $this->componentAdmitsNull($definition)) {
            return $propertySchema;
        }

        $narrowed = array_values(array_filter($type, static fn(mixed $member): bool => $member !== 'null'));
        $propertySchema['type'] = count($narrowed) === 1 ? $narrowed[0] : $narrowed;

        return $propertySchema;
    }

    /**
     * A property that is a pure `$ref` to a free-form object component gets the referenced schema
     * inlined. Resolving the type alone is not enough: the constraints are read from the property
     * schema, and a bare `$ref` carries none, so the map would lose its `type: object` and the
     * deserializer would then reject a JSON object as "expects array".
     *
     * Sibling keywords on the property win over the referenced schema, per JSON Schema 2020-12.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineFreeFormObjectRef(array $propertySchema): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        if (!is_string($ref)) {
            return $propertySchema;
        }

        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return $propertySchema;
        }

        $definition = $this->dtoSchemas[$this->schemaClassName(substr($ref, strlen($prefix)))] ?? null;
        if (!is_array($definition) || !$this->isFreeFormObjectSchema($definition)) {
            return $propertySchema;
        }

        unset($propertySchema['$ref']);

        // Its values read as the type reads them, as for a list alias (`inlineArrayAliasForConstraints()`):
        // a value `$ref` to a class component that admits null — `type: [object, null]`, 3.0 `nullable:
        // true` — admits a null value. Inlined as written, the property was typed `array<string, ?Fern>`
        // while its constraints said only `type: object`, and runtime refused `{"ka": null}`.
        return $propertySchema + $this->canonicalNullableRefContainers($definition);
    }

    /**
     * Runtime: a `$ref` to a discriminated `oneOf`/`anyOf` component one of whose members does not extend
     * it, spelled as that union inline: a member another discriminated union maps as well, or one that
     * keeps a parent of its own ({@see memberExtendsUnionBase()}).
     *
     * Such a member extends neither base — a class has one parent — so it is no instance of the
     * abstract class the reference names, and a property typed with that class could hold none of its
     * members: every payload was refused as "Discriminator mapping class Circle must extend or
     * implement ShapeA". Inline, the property is typed by its members (`Circle|Square`) and the
     * deserializer picks the member by the tag (`x-discriminator-map`), as for a discriminator written
     * on the property itself. Siblings of the `$ref` (`description`, `nullable`) win.
     *
     * The reference the schema consists of counts as the bare one ({@see unionReferenceTheSchemaConsistsOf()}):
     * `allOf: [$ref Teal]` and `anyOf: [$ref Teal, {type: null}]` kept the base, and a member placed by
     * `--ref` — never linked to it — was refused by runtime and a `TypeError` in laravel and laravel-data.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineSharedMemberUnionRef(array $propertySchema): array
    {
        $reference = $this->unionReferenceTheSchemaConsistsOf($propertySchema);
        if (!in_array($this->attributeMode, [self::ATTRIBUTE_MODE_RUNTIME, self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true) || $reference === null || !str_starts_with($reference['ref'], '#/components/schemas/')) {
            return $propertySchema;
        }
        $ref = $reference['ref'];
        $baseClass = $this->schemaClassName(substr($ref, strlen('#/components/schemas/')));
        $definition = $this->dtoSchemas[$baseClass] ?? null;
        $mapping = $this->discriminatorSchemas[$baseClass]['mapping'] ?? null;
        if (!is_array($definition) || !is_array($mapping) || !$this->isOneOfDiscriminatorBase($baseClass)) {
            return $propertySchema;
        }
        $sharesAMember = false;
        foreach ($mapping as $memberClass) {
            if (!$this->memberExtendsUnionBase($memberClass, $baseClass)) {
                $sharesAMember = true;
            }
        }
        if (!$sharesAMember) {
            return $propertySchema;
        }
        $inlined = [];
        foreach ($propertySchema as $keyword => $value) {
            if (!in_array($keyword, $reference['wrappers'], true)) {
                $inlined[$keyword] = $value;
            }
        }
        if ($reference['nullable']) {
            $inlined['nullable'] = true;
        }

        return $inlined + $definition;
    }

    /**
     * The reference a property's schema consists of, read through the spellings that only wrap it: the
     * bare `$ref`, a one-member `allOf: [$ref X]`, and `anyOf` / `oneOf: [$ref X, {type: null}]` — X with
     * null admitted (`nullable`). `wrappers` are the keywords that spelled it, the ones an inlined X
     * replaces. Null for any other shape.
     *
     * @param array<mixed> $schema
     * @return array{ref: string, nullable: bool, wrappers: list<string>}|null
     */
    private function unionReferenceTheSchemaConsistsOf(array $schema): ?array
    {
        if (is_string($schema['$ref'] ?? null)) {
            return ['ref' => $schema['$ref'], 'nullable' => false, 'wrappers' => ['$ref']];
        }
        $wrappers = array_values(array_intersect(['allOf', 'anyOf', 'oneOf'], array_keys($schema)));
        if (count($wrappers) !== 1) {
            return null;
        }
        $members = $schema[$wrappers[0]];
        if (!is_array($members) || !array_is_list($members)) {
            return null;
        }
        $references = [];
        $nullable = false;
        foreach ($members as $member) {
            if (!is_array($member)) {
                return null;
            }
            if ($wrappers[0] !== 'allOf' && $this->isNullBranch($member)) {
                $nullable = true;

                continue;
            }
            $references[] = $member;
        }
        if (count($references) !== 1 || ($wrappers[0] !== 'allOf' && !$nullable)) {
            return null;
        }
        $reference = $references[0];
        if ($this->isSingleRefAllOf($reference)) {
            $reference = $reference['allOf'][0];
        }
        if (!$this->isAnnotatedReference($reference)) {
            return null;
        }

        return ['ref' => $reference['$ref'], 'nullable' => $nullable, 'wrappers' => $wrappers];
    }

    /**
     * The members a `$ref` to a discriminated union stands for, as a PHP union (`Cat|Dog`), where one of
     * them does not extend the union ({@see inlineSharedMemberUnionRef()}) — the type of a list item or a
     * map value that names it. Typed with the union's class, the list held members that are no instance
     * of it: runtime's `addItemToPets(new Cat())` was a `TypeError`, laravel's `fromValidated()` closure
     * returning `Pet` one too. Null where the class holds them all.
     *
     * laravel-data keeps the base: it builds a list item by the class its docblock names, and the morph
     * base picks the member by the tag there, extended or not; a union names no class it could build.
     */
    private function memberUnionOfRef(string $ref): ?string
    {
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL_DATA || array_key_exists('$ref', $this->inlineSharedMemberUnionRef(['$ref' => $ref]))) {
            return null;
        }
        $baseClass = $this->schemaClassName(substr($ref, strlen('#/components/schemas/')));
        $mapping = $this->discriminatorSchemas[$baseClass]['mapping'] ?? [];

        return implode('|', array_values(array_unique($mapping)));
    }

    /**
     * A component whose top-level `type` is a scalar is a TYPE ALIAS, exactly like a `type: array`
     * component — `Uuid: {type: string, format: uuid}` names a string, it does not describe an
     * object. Materializing it produced a class with no properties, the referencing property was
     * typed with that class, and then every request carrying the field failed with
     * `Cannot deserialize nested DTO Uuid from non-array value`. The endpoint could not be used at
     * all, in either mode.
     *
     * The reference is therefore replaced by the referenced schema itself (siblings on the property
     * win, so a local `description` or `nullable` still overrides), which puts the property back on
     * the ordinary scalar path: the right PHP type AND the alias's own `format`/`minLength`/`enum`
     * as constraints.
     *
     * A named enum that CAN become a backed enum keeps its class — `isScalarAliasSchema()` excludes
     * it. One that cannot (a `nullable` enum carrying `null`, a `type: number` enum) is inlined and
     * validated as an enum constraint on a scalar property, which is what the inline spelling of the
     * same schema has always done.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineScalarAliasRef(array $propertySchema, string $ownerClassName): array
    {
        $ref = $propertySchema['$ref'] ?? null;
        $sourceFile = $this->getSchemaSourceFile($ownerClassName);

        // `allOf: [{$ref: Alias}]` with nothing else is the same reference, spelled longer.
        if (!is_string($ref) && $this->isSingleRefAllOf($propertySchema)) {
            /** @var array<int, array<string, mixed>> $allOf */
            $allOf = $propertySchema['allOf'];
            $onlyRef = $allOf[0]['$ref'] ?? null;
            if (is_string($onlyRef) && $this->scalarAliasDefinition($onlyRef, $sourceFile) !== null) {
                unset($propertySchema['allOf']);
                $propertySchema['$ref'] = $onlyRef;
                $ref = $onlyRef;
            }
        }

        if (!is_string($ref)) {
            return $propertySchema;
        }

        $definition = $this->scalarAliasDefinition($ref, $sourceFile);
        if ($definition === null) {
            return $propertySchema;
        }

        unset($propertySchema['$ref']);

        return $this->besideReferencedSchema(
            own: $propertySchema,
            target: $this->aliasChainInlined(definition: $definition, sourceFile: $sourceFile, depth: 0),
        );
    }

    /**
     * A scalar alias named where a value sits one level down — a branch of an `anyOf` / `oneOf` /
     * `allOf` beside other branches, the value schema of a map (`additionalProperties`) — is the schema
     * it names, exactly as `inlineScalarAliasRef()` makes it for the property itself and for `items`.
     * Left a reference, the property was typed by the alias's class, which is never written: `Code|Count`,
     * `Code|int|null`, `array<string, Code>`. The runtime refused every value with `Unsupported type` /
     * `unknown type`, laravel died on the constructor or on `Class not found` before reading the
     * payload, laravel-data could not find the data class, and the alias's keywords went unchecked
     * where a value was let through. A branch or map value that names an object class is left alone.
     *
     * The two-branch "alias or null" union never gets here — `nullableScalarAliasUnion()` has made it
     * the alias, nullable, already.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function inlineScalarAliasBranches(array $propertySchema, string $ownerClassName, int $depth = 0): array
    {
        foreach (['anyOf', 'oneOf', 'allOf'] as $compositionKey) {
            $branches = $propertySchema[$compositionKey] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            $inlinedBranches = [];
            foreach ($branches as $index => $branch) {
                $inlinedBranches[$index] = is_array($branch)
                    ? $this->inlineScalarAliasValue(schema: $branch, ownerClassName: $ownerClassName, depth: $depth)
                    : $branch;
            }
            $propertySchema[$compositionKey] = $inlinedBranches;
        }

        $items = $propertySchema['items'] ?? null;
        if (is_array($items)) {
            $propertySchema['items'] = $this->inlineScalarAliasValue(schema: $items, ownerClassName: $ownerClassName, depth: $depth);
        }
        $valueSchema = $propertySchema['additionalProperties'] ?? null;
        if (is_array($valueSchema) && $this->staysAMap($propertySchema)) {
            $propertySchema['additionalProperties'] = $this->inlineScalarAliasValue(schema: $valueSchema, ownerClassName: $ownerClassName, depth: $depth);
        }

        return $propertySchema;
    }

    /**
     * Whether an object schema is typed as a map rather than extracted into a class of its own. Only
     * then may its `additionalProperties` be rewritten here: a class is extracted from the schema as
     * the document wrote it, and a rewritten copy would no longer be the same schema — the two
     * collide on the class name.
     *
     * @param array<string, mixed> $schema
     */
    private function staysAMap(array $schema): bool
    {
        if (!$this->isMapLikeObjectSchema($schema)) {
            return false;
        }
        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (
                array_key_exists($keyword, $schema)
                && !in_array($keyword, self::PROPERTY_KEY_CONSTRAINT_KEYWORDS, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * One value position of `inlineScalarAliasBranches()`: the alias it names inlined, then the same
     * done one level further down — a union component inlined here brings branches that name aliases
     * of their own.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function inlineScalarAliasValue(array $schema, string $ownerClassName, int $depth): array
    {
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return $schema;
        }
        /** @var array<string, mixed> $schema */
        if (!is_string($schema['$ref'] ?? null) && !$this->isSingleRefAllOf($schema)) {
            // A bare `oneOf` / `anyOf` one level down — a union inside a union, a union as a map value
            // — names its aliases one level further down still: `anyOf: [{oneOf: [$ref Word, bool]},
            // null]` was typed `Word|bool|null`, `additionalProperties: {anyOf: [$ref Count]}` was
            // `array<string, Count>`, and neither class is ever written.
            if ($this->isBareUnionSchema($schema)) {
                return $this->unwrapSingleBranchUnion(
                    inlined: $this->inlineScalarAliasBranches(propertySchema: $schema, ownerClassName: $ownerClassName, depth: $depth + 1),
                    original: $schema,
                );
            }

            // A list one level down names its items' alias the way the property's own list does
            // (`withAliasesInlined()`): `anyOf: [{type: array, items: {$ref: Spoke}}]` with `Spoke` a
            // scalar union was typed `array<Spoke>`, a class that is never written — Symfony refused
            // every list at deserialization, runtime died on `unknown type`. A list is no class, so
            // rewriting its items collides with nothing.
            if ($this->soleTypeBesidesNull($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
                $schema['items'] = $this->inlineScalarAliasValue(schema: $schema['items'], ownerClassName: $ownerClassName, depth: $depth + 1);

                return $schema;
            }

            // A map one level down the same: `allOf: [{type: object, additionalProperties: {$ref:
            // Shade}}]` with `Shade` a scalar union is the bare map spelled longer, and was typed
            // `array<string, Shade>`, a class that is never written — runtime refused to load the DTO,
            // the other modes named a missing type. A map is no class either (`staysAMap()`).
            if (is_array($schema['additionalProperties'] ?? null) && $this->staysAMap($schema)) {
                $schema['additionalProperties'] = $this->inlineScalarAliasValue(
                    schema: $schema['additionalProperties'],
                    ownerClassName: $ownerClassName,
                    depth: $depth + 1,
                );

                return $schema;
            }

            // An `allOf` that folds to a scalar names its alias in a fragment — `allOf: [{$ref: Num},
            // {multipleOf: 0.5}]` — and is inlined fragment by fragment, as the property's own `allOf`
            // is: left a reference, the branch had no type, and beside a union of classes the property
            // was `mixed` in symfony and any value in yii3. Such an `allOf` shapes no object, so no
            // class is extracted from it to collide with.
            if (array_key_exists('allOf', $schema) && $this->allOfFoldsToScalar(branch: $schema, depth: $depth)) {
                return $this->inlineScalarAliasBranches(propertySchema: $schema, ownerClassName: $ownerClassName, depth: $depth + 1);
            }

            // Any other inline subschema is left exactly as written: one that shapes an object is
            // extracted into a class of its own from the document, and a copy rewritten here would no
            // longer be the same schema — the two collide on the class name.
            return $schema;
        }
        // `allOf: [{$ref: Alias}]` is the reference spelled longer — 3.0's way to put keywords beside
        // it. Left wrapped, a branch or map value naming a scalar or array alias was typed by the
        // alias's class, which is never written: `Weight|string`, `array<string, Num>`.
        $schema = $this->unwrapArrayAliasAllOf($schema);
        $inlined = $this->inlineScalarAliasRef($schema, $ownerClassName);
        if (array_key_exists('$ref', $inlined)) {
            return $inlined;
        }

        return $this->inlineScalarAliasBranches(propertySchema: $inlined, ownerClassName: $ownerClassName, depth: $depth + 1);
    }

    /**
     * A schema that is only a `oneOf` or an `anyOf` — no keyword beside it that shapes an object, so no
     * class is ever extracted from it as the document wrote it, and its branches may be rewritten.
     *
     * @param array<mixed> $schema
     */
    private function isBareUnionSchema(array $schema): bool
    {
        if (!is_array($schema['oneOf'] ?? null) && !is_array($schema['anyOf'] ?? null)) {
            return false;
        }
        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (!in_array($keyword, ['oneOf', 'anyOf'], true) && array_key_exists($keyword, $schema)) {
                return false;
            }
        }

        return !array_key_exists('properties', $schema)
            && !array_key_exists('additionalProperties', $schema)
            && !array_key_exists('items', $schema)
            && !array_key_exists('type', $schema);
    }

    /**
     * `anyOf: [$ref Count]` with nothing beside it is the reference spelled longer. Once the alias is
     * inlined the one branch IS the value schema, and a map typed by it reads `array<string, int>` the
     * way `additionalProperties: {$ref: Count}` always did.
     *
     * @param array<mixed> $inlined
     * @param array<mixed> $original
     * @return array<mixed>
     */
    private function unwrapSingleBranchUnion(array $inlined, array $original): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $original[$keyword] ?? null;
            if (
                array_keys($original) === [$keyword]
                && is_array($branches)
                && count($branches) === 1
                && is_array($branches[0] ?? null)
                && is_string($branches[0]['$ref'] ?? null)
                && is_array($inlined[$keyword][0] ?? null)
                && !array_key_exists('$ref', $inlined[$keyword][0])
            ) {
                return $inlined[$keyword][0];
            }
        }

        return $inlined;
    }

    /**
     * A `$ref`'s target together with the keywords written beside the `$ref` — both apply (JSON Schema
     * 2020-12 §8.2.3.1). Merged key by key, a keyword on both sides kept one and lost the other:
     * `{$ref: Bar, prefixItems: [A]}` over `Bar: {prefixItems: [B, C]}` checked one tuple, and the
     * `unevaluatedItems: false` beside it then refused the item the other one evaluates. So where the
     * two share an asserting keyword, or the referring side carries an `unevaluated*`, the asserting
     * keywords of each become a branch of an `allOf`; the annotations and the `unevaluated*` stay on
     * top, where they see what both branches evaluate. Otherwise the plain merge stands — it is the
     * same schema, and the generated code does not change.
     *
     * @param array<string, mixed> $own the referring schema, its `$ref` removed
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function besideReferencedSchema(array $own, array $target): array
    {
        $top = [];
        $ownAsserting = [];
        foreach ($own as $keyword => $value) {
            if (
                in_array($keyword, ['unevaluatedItems', 'unevaluatedProperties'], true)
                || in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                || in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                || str_starts_with($keyword, 'x-')
            ) {
                $top[$keyword] = $value;
                continue;
            }
            $ownAsserting[$keyword] = $value;
        }

        $sharesAKeyword = array_intersect_key($ownAsserting, $target) !== [];
        $seesThrough = array_key_exists('unevaluatedItems', $own) || array_key_exists('unevaluatedProperties', $own);
        if ((!$sharesAKeyword && !$seesThrough) || $ownAsserting === []) {
            return $own + $target;
        }

        return $top + ['allOf' => [$ownAsserting, $target]];
    }

    /**
     * An alias definition whose own `$ref` names another alias, with that one inlined too. One level
     * left the inner `$ref` in place, and the property was typed by the class of an alias — a class
     * that is never written. The two apply together, as a `$ref` beside other keywords does in JSON
     * Schema 2020-12: merged key by key, `{$ref: Bar, prefixItems: [A]}` over `Bar: {prefixItems: [B, C]}`
     * would keep one `prefixItems` and lose the other, so they become two `allOf` branches.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function aliasChainInlined(array $definition, ?string $sourceFile, int $depth): array
    {
        $ref = $definition['$ref'] ?? null;
        if (!is_string($ref) || $depth >= self::SHAPE_PROBE_DEPTH) {
            // A split scalar alias (`Ivory: {allOf: [{type: integer}, {maximum: 15}]}`) is inlined as the
            // flat scalar it spells, so a `nullable` beside the reference relaxes that scalar's `type`:
            // inlined split, the `nullable` stood beside fragments whose `type` refused the null.
            return $this->scalarAllOfFlattened($definition);
        }
        $inner = $this->scalarAliasDefinition($ref, $sourceFile);
        if ($inner === null) {
            return $definition;
        }

        $own = [];
        foreach ($definition as $keyword => $value) {
            if ($keyword !== '$ref') {
                $own[$keyword] = $value;
            }
        }
        $innerInlined = $this->aliasChainInlined(definition: $inner, sourceFile: $sourceFile, depth: $depth + 1);

        return $this->besideReferencedSchema(own: $own, target: $innerInlined);
    }

    /**
     * `allOf: [{$ref: Alias}]`: one fragment, and that fragment is the reference — bare, or with only
     * annotations beside it (`{$ref: Count, description: d}`, see `isAnnotatedReference()`). Accepting
     * the bare spelling only left the annotated one wrapped, and a union branch, a map value or a
     * nullable property naming a scalar or list alias that way was typed by the alias's class, which
     * is never written. A sibling that asserts (3.1 `{$ref: Count, type: integer}`) still keeps it out.
     *
     * @param array<string, mixed> $schema
     */
    private function isSingleRefAllOf(array $schema): bool
    {
        $allOf = $schema['allOf'] ?? null;

        return is_array($allOf)
            && count($allOf) === 1
            && $this->isAnnotatedReference($allOf[0] ?? null);
    }

    /**
     * The definition behind a `$ref` when it names a scalar alias, otherwise null.
     *
     * A ref into another FILE is resolved too, the same way `resolveBinaryRefType()` does it: the
     * alias gets no class file either, so leaving an external one on class-name typing produced a
     * property typed with a class that is never written — a missing-class fatal instead of the
     * previous (useless but loadable) empty class.
     *
     * @return array<string, mixed>|null
     */
    private function scalarAliasDefinition(string $ref, ?string $currentSourceFile = null): ?array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;

            if (!str_starts_with($ref, $prefix)) {
                return null;
            }
        }

        $definition = $this->dtoSchemas[$this->schemaClassName($this->componentNameInPointer($ref))] ?? null;

        return is_array($definition) && $this->isScalarAliasSchema($definition) ? $definition : null;
    }

    /**
     * The component a `#/components/schemas/…` pointer starts in, its segment unescaped (RFC 6901):
     * `slash~1field` names `slash/field` and `tilde~0field` names `tilde~field`. Read as written,
     * the reference named no schema, and the subschema under it was dropped unchecked. A pointer
     * that goes on below the component keeps only its first segment here — see
     * `schemaInsideComponent()`.
     */
    private function componentNameInPointer(string $ref): string
    {
        $path = substr($ref, strlen('#/components/schemas/'));
        if (str_contains($path, '/')) {
            return $path;
        }

        return str_replace(['~1', '~0'], ['/', '~'], $path);
    }

    /**
     * The schema a pointer INTO a component names — `#/components/schemas/Pair/prefixItems/0`, the
     * spelling a relative `#/prefixItems/0` takes once its root is a component. No class is ever
     * generated for such a target, so it is only ever inlined; left unresolved, the subschema that
     * held it extracted to nothing and its position went unchecked.
     *
     * @return array<string, mixed>|null
     */
    private function schemaInsideComponent(string $ref): ?array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }
        $segments = array_map(
            callback: static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array: explode('/', substr($ref, strlen($prefix))),
        );
        if (count($segments) < 2) {
            return null;
        }

        $className = $this->schemaClassName(array_shift($segments));
        $node = $this->rawSchemasByClass[$className] ?? $this->dtoSchemas[$className] ?? null;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }

    /**
     * An untyped schema that says nothing about an object's keys — `{minimum: 5}`, `{if: …, then: …}`,
     * `{allOf: [{maximum: 30}, {minimum: 20}]}`, `{prefixItems: […]}`. JSON Schema lets it hold ANY
     * value, so it is an alias of `mixed`, exactly as `{type: string}` is an alias of a string: no class
     * is written for it, a reference to it is replaced by the schema itself, and the property it types
     * stays `mixed` and is checked by the validator or the emitted interpreter in every mode.
     *
     * Materialized as a class instead, as it was, a `$ref` to it refused every number with `Cannot
     * deserialize nested DTO … from non-array value` and every list with `expects object`, and the
     * keywords it carries were checked by nothing.
     *
     * The schema that writes no keyword at all (`{}`, a bare `description`) is one too, as the inline
     * `{}` is `mixed`. It used to keep an empty final class, which held no scalar and lost every key of
     * an object (symfony wrote `3` back as `[]`, yii3 died calling a method the class did not have).
     *
     * Kept a class: a union, an enum, and anything `shapesAnObject()` recognises.
     *
     * @param array<string, mixed> $schema
     */
    private function isUntypedValueSchema(array $schema): bool
    {
        return !array_key_exists('type', $schema)
            && !array_key_exists('enum', $schema)
            && !$this->rendersAsUnion($schema)
            && !$this->shapesAnObject($schema);
    }

    /**
     * Whether a property with this schema is typed `mixed`, the answer `resolvePropertyType()` gives
     * for an untyped schema: no class is written for it or for anything below it. A `type`, an enum
     * and a union materialize; an `allOf` does when it shapes an object; a `$ref` unless it names an
     * untyped value schema.
     *
     * @param array<string, mixed> $schema
     */
    private function staysUnmaterialized(array $schema): bool
    {
        if (
            array_key_exists('type', $schema)
            || array_key_exists('enum', $schema)
            || array_key_exists('oneOf', $schema)
            || array_key_exists('anyOf', $schema)
        ) {
            return false;
        }

        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $alias = $this->scalarAliasDefinition($ref, $this->constraintSourceFile ?? $this->rootSpecFile);

            return $alias !== null && $this->isUntypedValueSchema($alias);
        }

        return !array_key_exists('allOf', $schema) || !$this->shapesAnObject($schema);
    }

    /**
     * Whether a schema is generated as an OBJECT — a DTO class — although it may carry no `type`.
     *
     * JSON Schema answers "never without `type: object`": `properties` and its siblings ignore a value
     * that is not an object, so `{properties: {a: …}}` admits `12` and `[]`. This generator keeps a
     * narrower convention, because ordinary OpenAPI documents rely on it: a component or an `allOf`
     * that describes KEYS (`properties`, `required`, `additionalProperties`, …, a `discriminator`) is
     * an object, written with `type: object` or not — `Pet: {properties: …}` and `allOf: [{$ref:
     * Base}, {properties: …}]` are classes, and turning every such class into `mixed` would take the
     * typed DTO away from documents that never meant anything else. That part is a declared
     * divergence from the suite (README.support-matrix).
     *
     * What the convention never covered is a schema that describes no key at all: an `allOf` of
     * bounds, an `if`/`then`/`else`, a tuple. Those are values of any type and are not objects here.
     * An `allOf`/`oneOf`/`anyOf` is an object when one of its branches is; a `$ref` when its target
     * is; a reference this method cannot follow (another document, a cycle past the depth cap) keeps
     * the class it always had.
     */
    private function shapesAnObject(mixed $schema, int $depth = 0): bool
    {
        if (!is_array($schema)) {
            return false;
        }
        if ($depth > self::SHAPE_PROBE_DEPTH) {
            return true;
        }

        $type = $schema['type'] ?? null;
        if ($type !== null) {
            $types = is_array($type) ? array_values(array_diff($type, ['null'])) : [$type];

            return $types !== [] && array_diff($types, ['object']) === [];
        }

        foreach (self::KEY_DESCRIBING_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return true;
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $applicator) {
            foreach (is_array($schema[$applicator] ?? null) ? $schema[$applicator] : [] as $branch) {
                if ($this->shapesAnObject(schema: $branch, depth: $depth + 1)) {
                    return true;
                }
            }
        }

        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return false;
        }
        $prefix = '#/components/schemas/';
        // A component of another document is read by the pointer its class is registered under: taken
        // for an object, an ENUM of another document in an `allOf` became the parent of a class that
        // could never extend it.
        $ref = $this->componentPointer($ref);
        if ($ref === null) {
            return true;
        }
        $className = $this->schemaClassName(substr($ref, strlen($prefix)));
        if (array_key_exists($className, $this->enumSchemas)) {
            return false;
        }
        $target = $this->dtoSchemas[$className] ?? null;

        return !is_array($target) || $this->shapesAnObject(schema: $target, depth: $depth + 1);
    }

    /**
     * A schema that describes a scalar VALUE rather than an object: a plain top-level scalar `type`
     * and no object shape. A backed-enum schema is excluded — it has a generated class of its own —
     * and so is anything carrying `properties` or a composition keyword, which would make the
     * "scalar type" a contradiction the caller should not silently resolve.
     *
     * @param array<string, mixed> $schema
     */
    private function isScalarAliasSchema(array $schema): bool
    {
        if (
            $this->isUntypedValueSchema($schema)
            || $this->isScalarUnionSchema($schema, depth: 0)
            || $this->isMixedValueUnionSchema($schema)
        ) {
            return true;
        }

        // `null` is a scalar too: a component `{type: null}` was materialized as an empty DTO, and a
        // `$ref` to it then refused the one value the schema allows.
        $scalarTypes = ['string', 'integer', 'number', 'boolean', 'null'];
        // A type LIST of scalars — `[integer, "null"]`, the 3.1 spelling of a nullable count — names a
        // scalar value just the same. Read as no scalar, the component became an empty class and a
        // property naming it refused every number ("must be an object").
        $types = $schema['type'] ?? null;
        $types = is_array($types) ? $types : [$types];
        $nonScalarTypes = array_filter(
            array: $types,
            callback: static fn(mixed $member): bool => !in_array($member, $scalarTypes, true),
        );
        if ($types === [] || $nonScalarTypes !== []) {
            return false;
        }

        if (array_key_exists('properties', $schema) || $this->isEnumSchema($schema)) {
            return false;
        }

        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A union whose every branch is a scalar value — `anyOf: [{$ref: Code}, {$ref: Count}]`,
     * `oneOf: [{$ref: Count}, {type: string}, {type: null}]` — is a scalar alias of its own: a value of
     * one of those types, never an object. Kept a class, it became a union interface no value can
     * implement, and a property naming it refused every string and number (laravel died on the
     * constructor). Referenced, it is inlined like any scalar alias, and its branches with it.
     *
     * Only the two union keywords, annotations and keywords that bound the value as a whole
     * (`VALUE_UNION_SIBLING_KEYWORDS`: `{anyOf: [string, integer], minimum: 3}` is the scalar, checked
     * for its minimum where it is inlined): a `type`, a `discriminator`, an `allOf` or a keyword that
     * shapes an object beside the union keeps the schema what it was.
     *
     * @param array<string, mixed> $schema
     */
    private function isScalarUnionSchema(array $schema, int $depth): bool
    {
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return false;
        }
        $branches = $this->bareUnionBranches(schema: $schema, valueSiblings: true);
        if ($branches === null) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!is_array($branch) || !$this->isScalarUnionBranch(branch: $branch, depth: $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A union with a branch that is a value but no object — a number, a list — beside branches that
     * are objects: `oneOf: [{type: integer}, {type: object, …}]`, `oneOf: [{type: array}, {type: string}]`.
     * No class can stand for it: kept one, it became a union interface (or an empty class) that the
     * number or the list can never be, so a property naming it refused every valid value, laravel died
     * on hydration and the runtime accepted an object it should not have. Written inline, the same union
     * has always worked — so a reference to it is inlined like a scalar alias, and its object branches
     * get their classes where it is used.
     *
     * The same restriction as `isScalarUnionSchema()`: the union keyword, annotations and keywords that
     * bound the value — `{anyOf: [<map>, {type: integer}], maxProperties: 3}` is inlined with its key
     * count, which then holds for the map and says nothing about the number. A union of two classes
     * with a key count beside it still has two object branches and stays an interface.
     *
     * A union with ONE object member and nothing else but `null` — `oneOf: [{$ref: Cat}, {type: null}]`,
     * `anyOf: [{type: object, properties: …}]`, `oneOf: [{type: object}]` — is not ambiguous either: it
     * is that object, nullable or not. Kept a class, it became a union interface with one member and the
     * warning that no mode can hydrate it — true, and every payload refused or crashed. Inlined, the
     * property is `?Cat` / its own class, as the same union written on the property has always been.
     *
     * @param array<string, mixed> $schema
     */
    private function isMixedValueUnionSchema(array $schema): bool
    {
        $branches = $this->bareUnionBranches(schema: $schema, valueSiblings: true);
        if ($branches === null) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return false;
            }
        }
        // A union that names itself — `Cyclic: {anyOf: [{$ref: Cyclic}, {type: array}]}` — cannot be
        // written out where it is used: every inlining finds the reference again. It stays a class.
        if ($this->unionReachesItself($schema)) {
            return false;
        }
        $objectBranches = 0;
        foreach ($branches as $branch) {
            // `allOf: [{$ref: Str}]`, `allOf: [{type: string}, {minLength: 1}]`: a scalar, wrapped.
            if (array_key_exists('allOf', $branch) && $this->allOfFoldsToScalar(branch: $branch, depth: 1)) {
                return true;
            }
            /** @var array<string, mixed> $branch */
            if ($this->isNullUnionBranch($branch) || ($branch['type'] ?? null) === ['null']) {
                continue;
            }
            // An object the union holds as the decoded array — no properties of its own, only keys it
            // requires or counts (`{type: object, required: [yak]}`), or a map — is a value as well: no
            // member class can stand for it, and one was written that the owner's constraints never
            // named, so the payload it alone accepts was refused as "Unsupported type".
            if ($this->isDecodedObjectBranch($branch)) {
                return true;
            }
            // A branch that names no type — `{required: [yak]}`, `{minProperties: 1}` — admits every
            // value that is no object, so the union is a loose value, as the same union written on the
            // property is (`mixed`). Kept an interface, a number was refused as no array and an object
            // only that branch takes crashed the constructor.
            if ($this->isTypeLessUnionBranch($branch)) {
                return true;
            }
            if ($this->shapesAnObject($branch)) {
                ++$objectBranches;

                continue;
            }
            if (array_key_exists('type', $branch) || array_key_exists('$ref', $branch) || $this->isScalarUnionSchema($branch, depth: 1)) {
                return true;
            }
            // Anything else — `{}`, an untyped enum — is no second object either.
        }

        return $objectBranches === 1;
    }

    /**
     * An inline union branch that says nothing about the value's type: no `type`, no reference, no
     * composition, no keyword that declares a schema for a key — only keys it requires or counts, or
     * bounds of another type. The same branch written on the property types it `mixed`.
     *
     * @param array<string, mixed> $branch
     */
    private function isTypeLessUnionBranch(array $branch): bool
    {
        foreach (self::TYPE_NAMING_BRANCH_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An inline union branch that is an object held as the decoded array — a free-form object, one
     * that only requires or counts its keys, a map — rather than a class of its own.
     *
     * @param array<string, mixed> $branch
     */
    private function isDecodedObjectBranch(array $branch): bool
    {
        return !array_key_exists('$ref', $branch)
            && $this->isInlineObjectVariant($branch)
            && ($this->isMapLikeObjectSchema($branch) || $this->isPatternPropertiesOnlyObjectSchema($branch));
    }

    /**
     * Whether a `$ref` branch of this union, followed through the bare unions it names, leads back to
     * this same schema.
     *
     * @param array<string, mixed> $schema
     */
    private function unionReachesItself(array $schema): bool
    {
        $pending = [$schema];
        $seen = [];
        while ($pending !== []) {
            $current = array_shift($pending);
            foreach ($this->bareUnionBranches($current) ?? [] as $branch) {
                $ref = is_array($branch) ? ($branch['$ref'] ?? null) : null;
                if (!is_string($ref) || !str_starts_with($ref, '#/components/schemas/')) {
                    continue;
                }
                $className = $this->schemaClassName($this->componentNameInPointer($ref));
                $target = $this->dtoSchemas[$className] ?? null;
                if (!is_array($target) || in_array($className, $seen, true)) {
                    continue;
                }
                if ($target === $schema) {
                    return true;
                }
                $seen[] = $className;
                $pending[] = $target;
            }
        }

        return false;
    }

    /**
     * The branches of a schema that is ONE union keyword and annotations, otherwise null.
     *
     * With `$valueSiblings`, keywords that bound the VALUE beside the union are allowed too — see
     * `VALUE_UNION_SIBLING_KEYWORDS`: the question whether a union is a value alias asks it.
     *
     * @param array<string, mixed> $schema
     * @return array<mixed>|null
     */
    private function bareUnionBranches(array $schema, bool $valueSiblings = false): ?array
    {
        $unionKeys = array_values(array_intersect(['anyOf', 'oneOf'], array_keys($schema)));
        if (count($unionKeys) !== 1) {
            return null;
        }
        foreach (array_keys($schema) as $keyword) {
            if (
                $keyword !== $unionKeys[0]
                && !in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                && !in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                && !str_starts_with($keyword, 'x-')
                && (!$valueSiblings || !in_array($keyword, self::VALUE_UNION_SIBLING_KEYWORDS, true))
            ) {
                return null;
            }
        }
        $branches = $schema[$unionKeys[0]];

        return is_array($branches) && $branches !== [] ? $branches : null;
    }

    /**
     * A `$ref` branch is a scalar when its target is one and the keywords beside it only annotate or
     * bound that value — `{$ref: Amount, enum: [-5, -3.5], exclusiveMinimum: -5.5}` (3.1) is still a
     * number. Read as no scalar, a union holding it beside classes became a union interface, and every
     * number, boolean or string its scalar members accept was refused as no object.
     *
     * @param array<mixed> $branch
     */
    private function isScalarUnionBranch(array $branch, int $depth): bool
    {
        $ref = $branch['$ref'] ?? null;
        if (is_string($ref)) {
            $pointer = $this->componentPointer($ref);
            if (!$this->isValueBoundReference($branch) || $pointer === null) {
                return false;
            }
            $definition = $this->dtoSchemas[$this->schemaClassName($this->componentNameInPointer($pointer))] ?? null;

            // A component that is a scalar wrapped in an `allOf` — `{allOf: [{type: string}]}` — is
            // that scalar as much as its bare spelling.
            return is_array($definition)
                && !$this->isEnumSchema($definition)
                && (
                    $this->isScalarUnionSchema($definition, depth: $depth + 1)
                    || (array_key_exists('type', $definition) && $this->isScalarAliasSchema($definition))
                    || (array_key_exists('allOf', $definition) && $this->allOfFoldsToScalar(branch: $definition, depth: $depth + 1))
                );
        }
        /** @var array<string, mixed> $branch */
        if ($this->isScalarUnionSchema($branch, depth: $depth + 1)) {
            return true;
        }
        if (array_key_exists('allOf', $branch)) {
            return $this->allOfFoldsToScalar(branch: $branch, depth: $depth + 1);
        }

        return array_key_exists('type', $branch) && $this->isScalarAliasSchema($branch);
    }

    /**
     * Whether the `enum` and `const` of a schema, where it has them, hold scalar values only — a list or
     * an object among them is no bound a scalar fragment can carry.
     *
     * @param array<mixed> $schema
     */
    private function hasScalarValues(array $schema): bool
    {
        $values = [];
        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum'])) {
                return false;
            }
            $values = array_values($schema['enum']);
        }
        if (array_key_exists('const', $schema)) {
            $values[] = $schema['const'];
        }
        foreach ($values as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an `allOf` branch merges into a scalar: every member a scalar fragment — `{type:
     * integer}`, `{multipleOf: 5}` — or the one member a reference to a scalar alias (3.0's way to put
     * `nullable` beside it), and at least one of them naming the scalar type. Read as no scalar, a union
     * of such branches became an EMPTY class that refused every number and string the inline spelling
     * accepts, and one beside an object became a union interface nothing hydrates.
     *
     * @param array<mixed> $branch
     */
    private function allOfFoldsToScalar(array $branch, int $depth): bool
    {
        $allOf = $branch['allOf'] ?? null;
        if (!is_array($allOf) || $allOf === [] || $depth >= self::SHAPE_PROBE_DEPTH) {
            return false;
        }
        $beside = [];
        foreach ($branch as $keyword => $value) {
            if ($keyword !== 'allOf') {
                $beside[$keyword] = $value;
            }
        }

        $typed = false;
        foreach ([...$allOf, $beside] as $fragment) {
            if (!is_array($fragment)) {
                return false;
            }
            if (array_key_exists('$ref', $fragment)) {
                // A reference to a scalar folds beside keyword-only fragments too — `allOf: [{$ref:
                // Num}, {multipleOf: 0.5}]` is the scalar `allOf: [{type: number}, {multipleOf: 0.5}]`
                // spells inline. An annotation beside the reference asserts nothing, and a bound or an
                // `enum` beside it (`{$ref: Num, multipleOf: 2}`) bounds that scalar: read as no scalar,
                // the wrapped reference named the alias's class, which is never written.
                if (
                    !is_string($fragment['$ref'])
                    || !$this->isValueBoundReference($fragment)
                    || !$this->hasScalarValues($fragment)
                    || !$this->isScalarUnionBranch(branch: ['$ref' => $fragment['$ref']], depth: $depth)
                ) {
                    return false;
                }
                $typed = true;

                continue;
            }
            foreach ([...self::OBJECT_SHAPING_KEYWORDS, 'properties', 'items', 'prefixItems', 'additionalProperties'] as $keyword) {
                if (array_key_exists($keyword, $fragment)) {
                    return false;
                }
            }
            // An `enum` or `const` of scalar values is a bound of the scalar the other fragments type —
            // `allOf: [{type: integer}, {enum: [6, 9, 0]}]` is the integer `{type: integer, enum: [6, 9,
            // 0]}` spells inline. Kept out, a union of such branches became an EMPTY class.
            if (!$this->hasScalarValues($fragment)) {
                return false;
            }
            if (!array_key_exists('type', $fragment)) {
                continue;
            }
            $types = is_array($fragment['type']) ? $fragment['type'] : [$fragment['type']];
            foreach ($types as $type) {
                if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'null'], true)) {
                    return false;
                }
            }
            $typed = true;
        }

        return $typed;
    }

    /**
     * `{type: object}` with nothing else on it allows any properties — it is the same free-form
     * object as `{type: object, additionalProperties: true}`, only spelled shorter. Materializing
     * it into a DTO class produced a class with no properties, which silently swallowed the whole
     * payload, so it is treated as a map instead.
     *
     * Any keyword that gives the object a shape (composition, a reference, conditional or
     * pattern-based subschemas) disqualifies it: those schemas do describe properties, just not
     * inline.
     *
     * A keyword that constrains the KEYS without declaring a schema for any of them does NOT —
     * see `PROPERTY_KEY_CONSTRAINT_KEYWORDS`.
     *
     * @param array<string, mixed> $schema
     */
    private function isFreeFormObjectSchema(array $schema): bool
    {
        // `type: [object, null]` too, the OpenAPI 3.1 spelling of `{type: object, nullable: true}`:
        // read as `object` alone, it became an empty class and every key the client sent was dropped.
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = array_values(array_diff($type, ['null'])) === ['object'] ? 'object' : null;
        }
        if ($type !== 'object') {
            return false;
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && $properties !== []) {
            return false;
        }

        foreach (self::OBJECT_SHAPING_KEYWORDS as $keyword) {
            if (
                array_key_exists($keyword, $schema)
                && !in_array($keyword, self::PROPERTY_KEY_CONSTRAINT_KEYWORDS, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * `patternProperties`-only object schemas are dictionary-shaped and should keep payload data as
     * a map; materializing them to an empty DTO makes the dynamic keys unreachable.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function isPatternPropertiesOnlyObjectSchema(array $propertySchema): bool
    {
        if (($propertySchema['type'] ?? null) !== 'object') {
            return false;
        }

        if (!is_array($propertySchema['patternProperties'] ?? null) || $propertySchema['patternProperties'] === []) {
            return false;
        }

        return !is_array($propertySchema['properties'] ?? null) || $propertySchema['properties'] === [];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolvePatternPropertiesValueType(
        array $propertySchema,
        string $ownerClassName,
        string $propertyName,
    ): string {
        $patternProperties = $propertySchema['patternProperties'] ?? null;
        if (!is_array($patternProperties) || $patternProperties === []) {
            return 'mixed';
        }

        $valueTypes = [];
        foreach ($patternProperties as $schema) {
            if (!is_array($schema)) {
                continue;
            }
            [$resolvedType] = $this->resolvePropertyType(
                propertySchema: $schema,
                ownerClassName: $ownerClassName,
                propertyName: $propertyName . 'PatternValue',
            );
            $valueTypes[$resolvedType] = true;
        }

        $resolved = array_keys($valueTypes);
        if ($resolved === []) {
            return 'mixed';
        }

        return count($resolved) === 1 ? $resolved[0] : 'mixed';
    }

    /**
     * The recursion budget for a nested container's declared type, and — read as an equality — the
     * marker for the FIRST hop below the property. That hop is the one the deserializer hydrates, so
     * it is the one allowed to name a DTO; every hop under it counts down and stays `mixed`.
     */
    private const int NESTED_CONTAINER_DOC_DEPTH = 8;

    /**
     * The docblock type of a container nested inside another container: `array<int>` for the items of
     * `array<array<int>>`, `array<string, int>` for the values of `array<string, array<string, int>>`.
     *
     * Its own resolver rather than a recursion into `resolvePropertyType()`, for two reasons.
     *
     * That one REGISTERS a synthesized class for an object-shaped item — and at this depth nothing
     * hydrates one, so the class would be emitted into the output and never referenced by anything.
     *
     * And it must promise no more than the generated code delivers. Casting reaches the SECOND level
     * of items and stops: a `$ref` to an object component two containers deep is hydrated into its DTO
     * and named here, a third container deep it is the `stdClass` `json_decode()` produced and is
     * named `mixed`. Everything else that would need CONVERTING is `mixed` at any nested depth — an
     * enum, a date, an uploaded file, and `number` too, because JSON hands `1` over as an int and
     * nothing widens it here.
     *
     * What survives is what `json_decode()` already produces in the declared form — `string`, `int`,
     * `bool` — plus containers, recursively.
     *
     * @param array<string, mixed> $schema the nested container's OWN schema
     */
    private function resolveNestedContainerDocType(
        array $schema,
        int $remainingDepth = self::NESTED_CONTAINER_DOC_DEPTH,
        ?string $currentSourceFile = null,
    ): string {
        if ($remainingDepth <= 0) {
            return 'mixed';
        }

        // `type: [array, "null"]` is a list too; the null is the caller's `?`. Read as `mixed`, a list
        // of nullable lists became `array<?mixed>` and runtime mode emitted `?mixed`, a fatal error.
        $type = $this->soleTypeBesidesNull($schema['type'] ?? null);

        if ($type === 'array') {
            $items = $schema['items'] ?? null;

            // No `items` is a list of anything, and says so: a bare `array` in item position is the MAP
            // form to `DtoDeserializer`, which then refused every JSON array the list held. Beside
            // `prefixItems`, `items` is not the schema of every member either — a tuple in a list was
            // `array<string>`, and Symfony 8's serializer refused its leading integer.
            return is_array($items) && !$this->hasPrefixItems($schema)
                ? 'array<' . $this->nestedContainerValueDocType($items, $remainingDepth, $currentSourceFile) . '>'
                : 'array<mixed>';
        }

        if ($type === 'object' || $this->isMapLikeObjectSchema($schema)) {
            // Runtime mode casts JSON objects to arrays two containers deep and no further: from the
            // third container on, an object or map is the `stdClass` `json_decode()` produced (kept so
            // an empty one still writes back as `{}`). Declared an array, `$x[0][0]['k']` passed PHPStan
            // and was a fatal error. `mixed`, not `stdClass`: the deserializer reads this declaration
            // to decide what to cast, and a class name there would be hydrated as a DTO. The other
            // modes hold an array there, as declared.
            if (
                $this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME
                && $remainingDepth < self::NESTED_CONTAINER_DOC_DEPTH
            ) {
                return 'mixed';
            }

            $valueSchema = $schema['additionalProperties'] ?? null;

            // `patternProperties` is the other dictionary spelling. One value schema is a type; several
            // are a union nothing here can narrow, so `mixed`.
            if (!is_array($valueSchema) && is_array($schema['patternProperties'] ?? null)) {
                $patterned = array_values(array_filter($schema['patternProperties'], 'is_array'));
                $valueSchema = count($patterned) === 1 ? $patterned[0] : null;
            }

            return is_array($valueSchema)
                ? 'array<string, ' . $this->nestedContainerValueDocType($valueSchema, $remainingDepth, $currentSourceFile) . '>'
                : 'array<string, mixed>';
        }

        return 'mixed';
    }

    /**
     * One value of a nested container: another container, a scalar JSON already delivers in that form,
     * or `mixed`.
     *
     * @param array<string, mixed> $schema
     */
    private function nestedContainerValueDocType(
        array $schema,
        int $remainingDepth,
        ?string $currentSourceFile = null,
    ): string {
        // The null is this value's own `?`: stripped from the type below and given back here. A `$ref`
        // to a component that admits null (`Mauve: {type: object, nullable: true}`) admits it here too —
        // a map of them through a map component declared the value non-null and refused `{"ka": null}`.
        $ref = $schema['$ref'] ?? null;
        $referenced = is_string($ref) && str_starts_with($ref, '#/components/schemas/')
            ? $this->dtoSchemas[$this->schemaClassName($this->componentNameInPointer($ref))] ?? null
            : null;

        return $this->composePhpTypeHint(
            type: $this->nestedContainerValueBaseDocType($schema, $remainingDepth, $currentSourceFile),
            nullable: $this->schemaAllowsNull($schema) || (is_array($referenced) && $this->componentAdmitsNull($referenced)),
        );
    }

    /**
     * `nestedContainerValueDocType()` without the value's own null.
     *
     * @param array<string, mixed> $schema
     */
    private function nestedContainerValueBaseDocType(
        array $schema,
        int $remainingDepth,
        ?string $currentSourceFile,
    ): string {
        $type = $this->soleTypeBesidesNull($schema['type'] ?? null);

        if ($type === 'array' || $type === 'object' || $this->isMapLikeObjectSchema($schema)) {
            return $this->resolveNestedContainerDocType($schema, $remainingDepth - 1, $currentSourceFile);
        }

        // A `$ref` naming a scalar alias or an enum COMPONENT: the value here is that scalar, because
        // nothing casts it at this depth — an `enum` two containers deep arrives as the plain string
        // the payload carried, measured.
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $definition = $this->nestedScalarRefDefinition($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($definition !== null) {
                return $this->nestedScalarDocType($definition['type'] ?? null);
            }

            // A `$ref` to an OBJECT, on the FIRST hop below the property — the one hop the
            // deserializer now hydrates. Naming the class here used to be exactly the lie 2.15.4
            // removed, because the value was the `stdClass` `json_decode()` produced; it is the DTO
            // now, so the name is what is true. Deeper the hydration stops and so does the name.
            if ($remainingDepth === self::NESTED_CONTAINER_DOC_DEPTH && $this->nestedObjectRefDefinition($ref, $currentSourceFile) !== null) {
                return $this->schemaRefToClassName(ref: $ref, currentSourceFile: $currentSourceFile);
            }

            return 'mixed';
        }

        // `format: date` and an inline `enum` used to answer `mixed` here on the grounds that the
        // value is "really" a date or an enum case. It is not: one level up it becomes one, at this
        // depth nothing converts it, and `mixed` said less than was known — a consumer's PHPStan got
        // nothing where `string` was both true and useful.
        return $this->nestedScalarDocType($type);
    }

    /**
     * The PHP type of a scalar JSON value at a depth where nothing casts it.
     */
    private function nestedScalarDocType(mixed $type): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            // JSON hands `1` over as an int and `1.5` as a float, and neither is widened here.
            // Measured: ONE `type: number` array held both in the same payload, so `float` alone
            // would have been a lie half the time.
            'number' => 'float|int',
            default => 'mixed',
        };
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolveAdditionalPropertiesValueType(
        array $propertySchema,
        string $ownerClassName,
        string $propertyName,
    ): string {
        $additionalProperties = $propertySchema['additionalProperties'] ?? true;

        if ($additionalProperties === true) {
            return 'mixed';
        }

        if (!is_array($additionalProperties)) {
            return 'mixed';
        }

        // `nullable` on the VALUE, spelled the way `items` spells it one branch over. Without this the
        // map declared `array<string, string>` for a schema that permits nulls, and the declaration is
        // what `DtoNormalizer::validate()` reads: the null the document allows was reported as one the
        // type forbids. The list spelling of the same schema had always been honest here.
        $valueNullable = $this->schemaAllowsNull($additionalProperties);
        // A `$ref` to a component that admits null (`Mauve: {type: object, nullable: true}`) admits it as
        // a value too: declared non-null, `{"ka": null}` was refused for a map of them.
        $valueRef = $additionalProperties['$ref'] ?? null;
        if (!$valueNullable && is_string($valueRef) && str_starts_with($valueRef, '#/components/schemas/')) {
            $valueComponent = $this->dtoSchemas[$this->schemaClassName($this->componentNameInPointer($valueRef))] ?? null;
            $valueNullable = is_array($valueComponent) && $this->componentAdmitsNull($valueComponent);
        }

        // A map whose values are themselves containers. Resolved WITHOUT the general resolver, which
        // would both synthesize a class nothing at that depth hydrates and name types the generated
        // code does not deliver — see `resolveNestedContainerDocType()`. It used to collapse to a bare
        // `mixed`, the one item type `DtoNormalizer::validate()` skips entirely.
        // A `$ref` to a CONTAINER component names an alias, not a class — the same reading the
        // property level and the `items` branch take. Without it the value collapsed to `mixed`,
        // which was true but said less than was known.
        if (is_string($additionalProperties['$ref'] ?? null)) {
            $aliasValueType = $this->resolveArrayAliasRefType(
                ref: $additionalProperties['$ref'],
                ownerClassName: $ownerClassName,
                propertyName: $propertyName . 'Value',
                inAContainer: true,
            );
            if ($aliasValueType !== null) {
                // A nullable array alias admits null as a value, as it does as a property or an item.
                $valueNullable = $valueNullable || $this->arrayAliasAllowsNull($additionalProperties['$ref']);

                return $this->composeMapValueTypeHint($this->itemsLessListAsList($aliasValueType), $valueNullable);
            }
            $memberUnion = $this->memberUnionOfRef($additionalProperties['$ref']);
            if ($memberUnion !== null) {
                return $this->composeMapValueTypeHint($memberUnion, $valueNullable);
            }
        }

        // A value with fixed `properties` is a DTO and stays one: that IS hydrated at this depth, and
        // the map-like predicate says no to it for exactly that reason.
        if (
            $this->soleTypeBesidesNull($additionalProperties['type'] ?? null) === 'array'
            || $this->isPatternPropertiesOnlyObjectSchema($additionalProperties)
            || $this->isMapLikeObjectSchema($additionalProperties)
        ) {
            return $this->composeMapValueTypeHint(
                type: $this->resolveNestedContainerDocType(
                    schema: $additionalProperties,
                    currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
                ),
                nullable: $valueNullable,
            );
        }

        [$valueType, $resolvedNullable] = $this->resolvePropertyType($additionalProperties, $ownerClassName, $propertyName . 'Value');

        // A union is a claim no single item type can carry.
        if (str_contains($valueType, '<') || str_contains($valueType, '|')) {
            return 'mixed';
        }
        // Only where the value IS a list: a union of free-form objects (`anyOf: [{type: object}]`)
        // resolves to the same bare `array`, and turned into the list form it refused every object.
        // It is declared `mixed`, as a union of an object and a scalar already is: the decoded JSON
        // object stays itself and the union constraint judges it. As `array<string, mixed>` the
        // empty `{}` arrived as an empty list, which the `type: object` branch then refused.
        $valueType = $this->admitsAList($additionalProperties)
            ? $this->itemsLessListAsList($valueType)
            : ($valueType === 'array' ? 'mixed' : $valueType);

        // And null where the resolver found it, as the property itself is: `anyOf: [{enum: [violet]},
        // {type: null}]` is the enum class or null, and declared without the `?` the null value the
        // document allows was refused in four modes.
        return $this->composeMapValueTypeHint($valueType, $valueNullable || $resolvedNullable);
    }

    /**
     * Whether a schema admits a JSON list: `type: array` itself, or in a `oneOf` / `anyOf` / `allOf`
     * branch at any depth. A schema that names no type at all is not counted — its bare `array` is
     * the object a union of free-form objects stands for.
     *
     * @param array<mixed> $schema
     */
    private function admitsAList(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if ($type === 'array' || (is_array($type) && in_array('array', $type, true))) {
            return true;
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                if (is_array($branch) && $this->admitsAList($branch)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A bare `array` as a map VALUE is the map form to `DtoDeserializer`, which then refused the JSON
     * list an items-less `type: array` holds — `{"shelf": {"a": ["blue"]}}` came back "expects object,
     * got array". A list of anything says so, as `resolveNestedContainerDocType()` does inline.
     */
    private function itemsLessListAsList(string $valueType): string
    {
        return $valueType === 'array' ? 'array<mixed>' : $valueType;
    }

    /**
     * The declared type of a map VALUE. Symfony mode leaves the `?` off a nullable LIST whose items
     * the serializer builds — a class, an enum, a date: symfony/serializer's `ArrayDenormalizer` does
     * not reset the context's key type for a nullable value, so `?array<string, ?array<Fox>>` had the
     * keys of the inner list checked as strings and refused every non-empty list. Without the `?` the
     * list is read; a null value is then refused at deserialization (README.support-matrix, "a null
     * VALUE of a nullable map"). A list of builtin scalars is never denormalized item by item and
     * keeps it.
     */
    private function composeMapValueTypeHint(string $type, bool $nullable): string
    {
        if ($nullable && $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && $this->isListOfBuiltObjects($type)) {
            return $type;
        }

        return $this->composePhpTypeHint($type, $nullable);
    }

    /**
     * Whether a docblock type is a list (possibly nested) whose innermost items are not builtin.
     */
    private function isListOfBuiltObjects(string $type): bool
    {
        if (!str_starts_with($type, 'array<') || str_starts_with($type, 'array<string, ')) {
            return false;
        }
        $item = $type;
        while (preg_match('/^\??array<(?:string, |int, )?(.+)>$/', $item, $matches) === 1) {
            $item = $matches[1];
        }
        foreach (explode('|', ltrim($item, '?')) as $member) {
            if (!in_array(strtolower($member), ['int', 'float', 'string', 'bool', 'true', 'false', 'null', 'mixed', 'array', 'object'], true)) {
                return true;
            }
        }

        return false;
    }

    private function mapStringFormatType(mixed $format): ?string
    {
        if (!is_string($format)) {
            return null;
        }

        return match ($format) {
            'binary' => 'UploadedFile',
            'date', 'date-time', 'datetime' => 'DateTimeImmutable',
            default => null,
        };
    }

    /**
     * The temporal formats of a `oneOf` / `anyOf`'s branches — `[date]`, `[date-time]` or both — which
     * decide how the union's `DateTimeImmutable` goes back out. A union has no `format` of its own, so
     * the value left as the PHP object, and `toJson()` wrote `{"date": …, "timezone": …}`.
     *
     * @param array<string, mixed> $propertySchema
     * @return list<string>
     */
    private function unionTemporalFormats(array $propertySchema): array
    {
        $branches = $propertySchema['oneOf'] ?? $propertySchema['anyOf'] ?? null;
        if (!is_array($branches)) {
            return [];
        }
        $formats = [];
        foreach ($branches as $branch) {
            foreach (is_array($branch) ? $this->unionBranchTemporalFormats(branch: $branch, depth: 0) : [] as $format) {
                $formats[$format] = true;
            }
        }

        return array_keys($formats);
    }

    /**
     * The `date` / `date-time` formats one union branch can hold — its own `format`, the one format the
     * members of an `allOf` branch agree on, or every format of a nested `oneOf` / `anyOf`:
     * `oneOf: [{allOf: [{type: string, format: date}]}]` is typed
     * `DateTimeImmutable` from the member, and without its format a valid `2012-03-26` was read as a
     * date-time and refused. A nested union, `oneOf: [{anyOf: [{format: date}]}]`, was not looked
     * into, and laravel-data cast the date with the date-time format — a 500.
     *
     * @param array<mixed> $branch
     * @return list<string>
     */
    private function unionBranchTemporalFormats(array $branch, int $depth): array
    {
        $format = $branch['format'] ?? null;
        if (in_array($format, ['date', 'date-time'], true)) {
            return [$format];
        }
        if ($depth >= self::SHAPE_PROBE_DEPTH) {
            return [];
        }
        $formats = [];
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            foreach (is_array($branch[$unionKey] ?? null) ? $branch[$unionKey] : [] as $member) {
                foreach (is_array($member) ? $this->unionBranchTemporalFormats(branch: $member, depth: $depth + 1) : [] as $memberFormat) {
                    $formats[$memberFormat] = true;
                }
            }
        }
        if ($formats !== []) {
            return array_keys($formats);
        }
        $agreed = [];
        foreach (is_array($branch['allOf'] ?? null) ? $branch['allOf'] : [] as $member) {
            foreach (is_array($member) ? $this->unionBranchTemporalFormats(branch: $member, depth: $depth + 1) : [] as $memberFormat) {
                $agreed[$memberFormat] = true;
            }
        }

        return count($agreed) === 1 ? array_keys($agreed) : [];
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function resolveTemporalPhpDocFormat(array $propertySchema): ?string
    {
        $format = $propertySchema['format'] ?? null;
        if (is_string($format)) {
            return $this->mapTemporalPhpDocFormat($format);
        }

        if (array_key_exists('$ref', $propertySchema) && is_string($propertySchema['$ref'])) {
            return $this->resolveTemporalRefPhpDocFormat($propertySchema['$ref']);
        }

        return $this->resolveCompositionTemporalPhpDocFormat($propertySchema);
    }

    /**
     * The temporal format an `allOf` carries in its branches: `allOf: [{type: string, format: date}]`.
     * The property is typed `DateTimeImmutable` from the branch, so the format must come from there too
     * — without it a `date` was read and written as a date-time, and a valid `2018-06-18` was refused.
     * Every branch applies, so the one temporal format among them is the property's.
     *
     * A `oneOf` / `anyOf` is left alone: its formats travel as `unionTemporalFormats`, and its getter
     * hands out the `DateTimeImmutable` itself.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveCompositionTemporalPhpDocFormat(array $propertySchema): ?string
    {
        $branches = $propertySchema['allOf'] ?? null;
        if (!is_array($branches)) {
            return null;
        }
        $formats = [];
        foreach ($branches as $branch) {
            $format = is_array($branch) ? $this->resolveTemporalPhpDocFormat($branch) : null;
            if ($format !== null) {
                $formats[$format] = true;
            }
        }

        return count($formats) === 1 ? array_key_first($formats) : null;
    }

    /**
     * The temporal format of an ARRAY ITEM (or map value), or null when the items are not temporal.
     *
     * `items: {type: string, format: date}` types the item as `DateTimeImmutable` exactly like the
     * scalar case, so the getter owes the reader the same `Y-m-d` string. Without this the format is
     * known only to the schema and the item leaves as an RFC 3339 date-time — a response that
     * contradicts the spec it was generated from.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveItemsTemporalPhpDocFormat(array $propertySchema, int $depth = 0): ?string
    {
        // A `$ref` to a list component (`on: $ref Saffron`, `Saffron: {type: array, items: {format:
        // date}}`) holds the same dates: read through it, its items were written out as date-times.
        $ref = $propertySchema['$ref'] ?? null;
        if (is_string($ref) && $depth < 8 && !array_key_exists('items', $propertySchema) && !array_key_exists('additionalProperties', $propertySchema)) {
            $definition = $this->localComponentDefinition($ref);

            return is_array($definition) ? $this->resolveItemsTemporalPhpDocFormat($definition, $depth + 1) : null;
        }
        // A list or map wrapped in `allOf` (`allOf: [{type: array, items: {format: date}}]`) holds the
        // same dates, as `resolveCompositionTemporalPhpDocFormat()` reads a wrapped scalar: every part
        // applies, so the one temporal item format among them is the property's. Without it symfony
        // typed the values `DateTimeImmutable` but parsed `+1 day` freely, and the runtime read a valid
        // `2020-01-02` as a date-time.
        $parts = $propertySchema['allOf'] ?? null;
        if (is_array($parts) && $depth < 8 && !array_key_exists('items', $propertySchema) && !array_key_exists('additionalProperties', $propertySchema)) {
            $formats = [];
            foreach ($parts as $part) {
                $format = is_array($part) ? $this->resolveItemsTemporalPhpDocFormat($part, $depth + 1) : null;
                if ($format !== null) {
                    $formats[$format] = true;
                }
            }

            return count($formats) === 1 ? array_key_first($formats) : null;
        }
        $items = $propertySchema['items'] ?? $propertySchema['additionalProperties'] ?? null;
        if (!is_array($items) || $this->hasPrefixItems($propertySchema)) {
            return null;
        }

        return $this->resolveTemporalPhpDocFormat($items) ?? $this->nullableUnionTemporalPhpDocFormat($items);
    }

    /**
     * The temporal format of an item that is a date OR null: `anyOf: [{type: string, format: date},
     * {type: 'null'}]`, the date also behind an `allOf` wrapper. The item is typed `?DateTimeImmutable`,
     * so the getter owes the reader the `Y-m-d` string the plain `format: date` item gets — without it a
     * map value went out as an RFC 3339 date-time, and the runtime parsed a valid date as one.
     *
     * @param array<string, mixed> $items
     */
    private function nullableUnionTemporalPhpDocFormat(array $items): ?string
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $items[$keyword] ?? null;
            if (!is_array($branches) || !array_is_list($branches)) {
                continue;
            }
            $formats = [];
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    return null;
                }
                $type = $branch['type'] ?? null;
                if ($type === 'null' || $type === ['null']) {
                    continue;
                }
                $format = $this->resolveTemporalPhpDocFormat($branch);
                if ($format === null) {
                    return null;
                }
                $formats[$format] = true;
            }

            return count($formats) === 1 ? array_key_first($formats) : null;
        }

        return null;
    }

    /**
     * Whether an array schema names its leading positions with `prefixItems`, so its `items` is not the
     * schema of every member.
     *
     * @param array<string, mixed> $schema
     */
    private function hasPrefixItems(array $schema): bool
    {
        return is_array($schema['prefixItems'] ?? null) && $schema['prefixItems'] !== [];
    }

    /**
     * The temporal format of the component a `$ref` names, following a chain of bare aliases
     * (`Jade: {$ref: Sienna}`, `Sienna: {type: string, format: date}`) to the one that writes it. Read
     * one hop deep, a list of `Jade` lost its `Y-m-d`, and symfony took `"now"` for a date.
     *
     * @param list<string> $visited the references already followed, against a cycle
     */
    private function resolveTemporalRefPhpDocFormat(string $ref, array $visited = []): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix) || in_array($ref, $visited, true)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? $this->rawSchemasByClass[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $format = $definition['format'] ?? null;
        if (is_string($format)) {
            return $this->mapTemporalPhpDocFormat($format);
        }
        $next = $definition['$ref'] ?? null;

        return is_string($next) ? $this->resolveTemporalRefPhpDocFormat(ref: $next, visited: [...$visited, $ref]) : null;
    }

    private function mapTemporalPhpDocFormat(string $format): ?string
    {
        return match ($format) {
            'date' => 'Y-m-d',
            'date-time', 'datetime' => 'yyyy-MM-dd HH:mm:ss',
            default => null,
        };
    }

    /**
     * If $ref points at a component schema whose top-level type is `array`, returns the aliased
     * array type (e.g. `array<Item>`) so a property referencing it is typed as a list rather
     * than an empty generated class. Returns null for object schemas. A reference into another
     * document is redirected only when it names an array alias there; its other kinds keep the
     * class-name typing.
     *
     * `$inAContainer` is set where the alias is a list ITEM or map VALUE — see
     * `symfonyAliasInAContainerType()`.
     */
    private function resolveArrayAliasRefType(string $ref, string $ownerClassName, string $propertyName, bool $inAContainer = false): ?string
    {
        $prefix = '#/components/schemas/';
        $isLocal = str_starts_with($ref, $prefix);
        // An array alias of another document is the list too; it is read by the pointer it is
        // registered under. Its other kinds keep their class.
        $ref = $this->registeredComponentPointer(ref: $ref, sourceFile: $this->getSchemaSourceFile($ownerClassName)) ?? '';
        if ($ref === '' || (!$isLocal && $this->arrayAliasDefinition($ref) === null)) {
            return null;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        // A free-form object component is an alias too — for a map. Without this a `$ref` to
        // `{type: object}` points at a generated class with no properties, which drops the payload.
        if ($this->isFreeFormObjectSchema($definition)) {
            return 'array<string, mixed>';
        }

        $definition = $this->arrayAliasDefinition($ref);
        if ($definition === null) {
            return null;
        }

        // Resolve relative to the alias schema itself so its `items` $ref resolves correctly. An item
        // naming a scalar alias is that alias, as on a property: `items: {$ref: Flag}` over
        // `Flag: {type: boolean}` typed the list `array<Flag>`, a class that is never written.
        $definition = $this->inlineScalarAliasBranches(propertySchema: $definition, ownerClassName: $className);
        // Items of `anyOf: [{$ref: Shape}, {type: null}]` are the nullable reference, read as such the
        // way a property's own items are (`withNormalizedReferences()`); left a union, the alias was
        // typed a bare list and its discriminated items were never built or checked.
        $definition = $this->canonicalNullableRefContainers($definition);
        [$type] = $this->resolvePropertyType($definition, $className, $propertyName);

        return $inAContainer
            ? $this->symfonyAliasInAContainerType(type: $type, definition: $definition, sourceFile: $this->getSchemaSourceFile($className))
            : $type;
    }

    /**
     * Symfony: an array alias held one container deep — `items: {$ref: Days}` over `Days: {type: array,
     * nullable: true, items: {format: date}}` — is typed as the same list written inline there
     * (`resolveNestedContainerDocType()`): `array<?array<string>>`, the callback checking the format and
     * the enum. Typed as the alias's top-level list, `array<?array<DateTimeImmutable>>`,
     * symfony/serializer's `ArrayDenormalizer` handed a null item to the inner `DateTimeImmutable[]`
     * denormalization, which refused the null the document allows; and a non-nullable alias left the
     * date to `DateTimeNormalizer`, which reads `"x"` as a military time zone, where the callback
     * refuses it. Only dates and enums move to the strings they arrive as: a class stays a class
     * (README.support-matrix, "a null VALUE of … a map of nullable LISTS of classes"), and a union the
     * nested typing cannot name keeps its own type.
     *
     * @param array<string, mixed> $definition
     */
    private function symfonyAliasInAContainerType(string $type, array $definition, ?string $sourceFile): string
    {
        if ($this->attributeMode !== self::ATTRIBUTE_MODE_SYMFONY || !$this->isListOfBuiltObjects($type)) {
            return $type;
        }
        $nested = $this->resolveNestedContainerDocType(schema: $definition, currentSourceFile: $sourceFile);

        return $this->isListOfBuiltObjects($nested) || str_contains($nested, 'mixed') ? $type : $nested;
    }

    /**
     * A `$ref` to a `type: array` component, replaced by that component — for the CONSTRAINTS only.
     *
     * `resolveArrayAliasRefType()` types such a reference as the aliased list, but the constraints were
     * still read from the bare `$ref`, which carries none. Laravel and laravel-data then emitted no
     * `array`/`list` rule and no item rules: `{"f": "x"}` passed validation and hydration died on
     * `array_map()`, and Symfony mode accepted a list of the wrong scalars.
     *
     * The type and the item class names keep coming from the alias itself, so a document that relied
     * on them generates the same classes as before. Sibling keywords win over the referenced schema,
     * as in `inlineFreeFormObjectRef()`. `allOf: [{$ref: Alias}]` is the same reference, spelled longer.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function inlineArrayAliasForConstraints(array $schema): array
    {
        $ref = $this->referenceOrSingleRefAllOf($schema);
        $definition = $ref === null ? null : ($this->arrayAliasDefinition($ref) ?? $this->mapComponentDefinition($ref));
        if ($definition === null && $ref !== null) {
            // An alias of a value — `{type: integer}`, `{prefixItems: […]}`, `{$ref: Pair, maxItems: 2}` —
            // is no class, so no class checks it: its keywords are carried here, the whole alias chain
            // with them. One level was, and the bound kept while the tuple behind it was dropped; and as
            // a list's `items`, an `int` alias left the item type to the serializer, which Symfony 7
            // does not check inside a collection: `["a"]` passed for a list of `$ref: Num`. A keyword the
            // PHP type already enforces is pruned later, as for any inline schema.
            $sourceFile = $this->constraintSourceFile ?? $this->rootSpecFile;
            $alias = $this->scalarAliasDefinition($ref, $sourceFile);
            if ($alias !== null) {
                if (array_key_exists('$ref', $schema)) {
                    unset($schema['$ref']);
                } else {
                    unset($schema['allOf']);
                }

                return $this->besideReferencedSchema(
                    own: $schema,
                    target: $this->aliasChainInlined(definition: $alias, sourceFile: $sourceFile, depth: 0),
                );
            }
        }
        if ($definition === null) {
            return $schema;
        }

        if (array_key_exists('$ref', $schema)) {
            unset($schema['$ref']);
        } else {
            unset($schema['allOf']);
        }

        // Its items read as the type reads them: `anyOf: [{$ref: Shape}, {type: null}]` is the nullable
        // reference — see `resolveArrayAliasRefType()`.
        return $schema + $this->canonicalNullableRefContainers($definition);
    }

    /**
     * The component a local `$ref` names when it is a MAP — `additionalProperties` or `patternProperties`
     * and no `properties` — for the constraints only. Such a reference is typed as the map wherever it
     * is used, and nothing validates it as a class, so as a list item its values went unchecked in every
     * mode while the same schema written inline was enforced.
     *
     * @return array<string, mixed>|null
     */
    private function mapComponentDefinition(string $ref): ?array
    {
        $definition = $this->localSchemaDefinitionForRef($ref);
        if ($definition === null || array_key_exists('properties', $definition)) {
            return null;
        }

        return $this->isMapLikeObjectSchema($definition) || $this->isPatternPropertiesOnlyObjectSchema($definition)
            ? $definition
            : null;
    }

    /**
     * `allOf: [{$ref: ArrayAlias}]` rewritten to the plain `$ref`, so the property is typed and validated
     * through the alias exactly as the short spelling is. Left wrapped, a required property never learned
     * that the alias is nullable: it was typed `array`, the rules lacked `nullable`, and a valid `null`
     * was refused.
     *
     * @param array<string, mixed> $propertySchema
     * @return array<string, mixed>
     */
    private function unwrapArrayAliasAllOf(array $propertySchema): array
    {
        if (array_key_exists('$ref', $propertySchema) || !$this->isSingleRefAllOf($propertySchema)) {
            return $propertySchema;
        }

        $ref = $this->referenceOrSingleRefAllOf($propertySchema);
        // A map component the same: a plain `$ref` to it is typed as the map, while the `allOf`
        // spelling — 3.0's way to write a nullable one — named an empty class of that name, which
        // dropped every value: `{"a": "x"}` passed a map of integers in symfony and yii3.
        $definition = $ref === null ? null : ($this->arrayAliasDefinition($ref) ?? $this->mapComponentDefinition($ref));
        if ($ref === null || $definition === null) {
            return $propertySchema;
        }

        unset($propertySchema['allOf']);
        $propertySchema['$ref'] = $ref;
        // `withNormalizedReferences()` marks only a plain `$ref`, so the unwrapped one is marked here.
        if (!array_key_exists('nullable', $propertySchema) && $this->schemaAllowsNull($definition)) {
            $propertySchema['nullable'] = true;
        }

        return $propertySchema;
    }

    /**
     * The `type: array` component a `$ref` names — of this document or of another one (see
     * `registeredComponentPointer()`) — following references between components.
     *
     * Two spellings were missed when only `type === 'array'` counted, and both lost far more than
     * their constraints: the component became an empty class, the property was typed with it, and
     * valid payloads were refused in four modes while Symfony accepted invalid ones.
     *   - `type: [array, "null"]`, the OpenAPI 3.1 way to write a nullable list;
     *   - an alias of an alias, `Contacts: {$ref: ContactList}`.
     * Keywords on an outer component win over the one it names, as sibling keywords do on a property.
     *
     * @return array<string, mixed>|null
     */
    private function arrayAliasDefinition(string $ref): ?array
    {
        $outer = [];
        $visited = [];
        $sourceFile = null;
        while (!in_array($ref, $visited, true)) {
            $visited[] = $ref;
            $pointer = $this->registeredComponentPointer(ref: $ref, sourceFile: $sourceFile);
            $definition = $pointer === null ? null : $this->localSchemaDefinitionForRef($pointer);
            if ($pointer === null || $definition === null) {
                return null;
            }

            $next = $definition['$ref'] ?? null;
            if (!is_string($next)) {
                return $this->isArrayAliasSchema($definition) ? $outer + $definition : null;
            }

            $outer += array_diff_key($definition, ['$ref' => true]);
            // The next link is read against the document that declares this one.
            $sourceFile = $this->getSchemaSourceFile($this->schemaClassName($this->componentNameInPointer($pointer)));
            $ref = $next;
        }

        return null;
    }

    /**
     * The `#/components/schemas/…` pointer a `$ref` names its component by. A reference into ANOTHER
     * document's components (`other.yaml#/components/schemas/Colours`) names the class that document's
     * schema is registered as — class names are one namespace across the documents of a run — so it
     * is registered here and read by its local pointer, as `scalarAliasDefinition()` reads one. Left to
     * the local reading, an array alias of another document was typed as a class no run writes.
     *
     * `$sourceFile` is the document the reference is written in; by default the one being read, else
     * the root document. Null when the reference names no component — or, read against such a default,
     * names none from there: a reader that knows no file only asks what kind of schema a reference
     * names, and a reference that is broken is reported where the documents are walked
     * (`scanExternalSchemaRefs()`), against the right file.
     */
    private function registeredComponentPointer(string $ref, ?string $sourceFile = null): ?string
    {
        $prefix = '#/components/schemas/';
        if (str_starts_with($ref, $prefix)) {
            return $ref;
        }

        try {
            $resolved = $this->resolveExternalSchemaPointer($ref, $sourceFile ?? $this->constraintSourceFile ?? $this->rootSpecFile);
        } catch (RuntimeException $exception) {
            if ($sourceFile !== null) {
                throw $exception;
            }

            return null;
        }
        $schemaName = $resolved === null ? null : $this->externalPointerSchemaName($resolved[1]);
        if ($resolved === null || $schemaName === null) {
            return null;
        }
        $this->registerExternalSchema(externalFile: $resolved[0], schemaName: $schemaName);

        return $resolved[1];
    }

    /**
     * Whether a component is an array alias and so gets no class of its own.
     *
     * @param array<string, mixed> $definition
     */
    private function isArrayAliasComponent(array $definition): bool
    {
        if ($this->isArrayAliasSchema($definition)) {
            return true;
        }

        $ref = $definition['$ref'] ?? null;

        return is_string($ref) && $this->arrayAliasDefinition($ref) !== null;
    }

    /**
     * Whether the array alias a `$ref` names admits null, in either spelling and through any chain.
     */
    private function arrayAliasAllowsNull(?string $ref): bool
    {
        $definition = $ref === null ? null : $this->arrayAliasDefinition($ref);

        return $definition !== null && $this->schemaAllowsNull($definition);
    }

    /**
     * `type: array`, or `type: [array, "null"]` in either order.
     *
     * @param array<string, mixed> $schema
     */
    private function isArrayAliasSchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return $type === 'array' || (is_array($type) && $this->soleTypeBesidesNull($type) === 'array');
    }

    /**
     * The `$ref` a schema consists of: its own, or the one inside a single-reference `allOf`.
     *
     * @param array<string, mixed> $schema
     */
    private function referenceOrSingleRefAllOf(array $schema): ?string
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref) && $this->isSingleRefAllOf($schema)) {
            /** @var array<int, array<string, mixed>> $allOf */
            $allOf = $schema['allOf'];
            $ref = $allOf[0]['$ref'];
        }

        return is_string($ref) ? $ref : null;
    }

    private function resolveBinaryRefType(string $ref, ?string $currentSourceFile = null): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (($definition['type'] ?? null) === 'string' && (($definition['format'] ?? null) === 'binary')) {
            return 'UploadedFile';
        }

        return null;
    }

    private function resolveTemporalRefType(string $ref, ?string $currentSourceFile = null): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile ?? $this->rootSpecFile);
            if ($resolvedExternal === null) {
                return null;
            }

            [$externalFile, $pointer] = $resolvedExternal;
            $externalSchemaName = $this->externalPointerSchemaName($pointer);
            if ($externalSchemaName !== null) {
                $this->registerExternalSchema(externalFile: $externalFile, schemaName: $externalSchemaName);
            }
            $ref = $pointer;
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            return null;
        }

        $className = $this->schemaClassName($schemaName);
        $definition = $this->dtoSchemas[$className] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (($definition['type'] ?? null) !== 'string') {
            return null;
        }

        $formatType = $this->mapStringFormatType($definition['format'] ?? null);
        return $formatType === 'DateTimeImmutable' ? 'DateTimeImmutable' : null;
    }

    private function schemaRefToClassName(string $ref, ?string $currentSourceFile = null): string
    {
        $prefix = '#/components/schemas/';

        if (str_starts_with($ref, $prefix)) {
            $schemaName = $this->componentNameInPointer($ref);
            if ($schemaName === '') {
                return 'mixed';
            }

            $className = $this->schemaClassName($schemaName);
            // Recorded, not checked: schemas are also registered WHILE rendering — an inline object
            // becomes its own schema, a discriminator variant gets synthesised — so a reference can
            // legitimately be resolved before its target exists. The question is answered once, at
            // the end, by {@see assertEveryLocalReferenceResolved()}.
            $this->localSchemaReferences[$className] ??= $ref;

            return $className;
        }

        $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolvedExternal === null) {
            return 'mixed';
        }

        [$externalFile, $pointer] = $resolvedExternal;

        $schemaName = $this->externalPointerSchemaName($pointer);
        if ($schemaName === null) {
            // Deeper pointer (e.g. .../properties/id): not a class. Scalars are inlined upstream
            // (see resolvePropertyType); anything else is unsupported as a class reference.
            return 'mixed';
        }

        $this->registerExternalSchema(externalFile: $externalFile, schemaName: $schemaName);
        $className = $this->schemaClassName($schemaName);
        // An alias of another component there names the class its chain ends at — see
        // `redirectPureAliasReferences()`; the alias itself is never written.
        $aliasEnd = $this->pureAliasRedirects[$className]['ref'] ?? null;
        if (is_string($aliasEnd)) {
            return $this->schemaRefToClassName($aliasEnd);
        }
        $this->externalSchemaReferences[$className] ??= $ref;

        return $className;
    }

    /**
     * Every local `$ref` seen while resolving types has to have named a schema that exists.
     *
     * Asked at the END, and that timing is the whole design: schemas are registered both up front
     * (the document's own `components.schemas`) and DURING rendering — an inline object is extracted
     * into its own schema, a discriminator variant is synthesised — so a reference resolved early may
     * name a class that only appears later. Checking at resolution time reports 68 false positives on
     * this repository's own corpus; checking once at the end reports none.
     *
     * The silence this replaces: `$ref: '#/components/schemas/Missing'` reported
     * `[OK] Generated 1 DTO class(es)`, emitted `private readonly Missing $thing`, generated no such
     * class, and the result passed `php -l` and autoloading before dying at the first construction
     * with `must be of type …\Missing`. Generation is the only moment where the document and the code
     * are both in hand.
     *
     * Enums count as resolved: a `$ref` to an enum schema names a generated enum, not a DTO.
     */
    private function assertEveryLocalReferenceResolved(): void
    {
        $unresolved = [];
        foreach ($this->localSchemaReferences as $className => $ref) {
            if (
                array_key_exists($className, $this->dtoSchemas)
                || array_key_exists($className, $this->enumSchemas)
            ) {
                continue;
            }

            $unresolved[] = sprintf('%s (would name class "%s")', $ref, $className);
        }
        // A schema of another document is registered as it is reached, so it is there — but an array
        // alias is written as no class: one of another document was typed as one, and generation
        // reported success. (A scalar alias is named here by readers that never write the name, and
        // is inlined where a type is written — see `scalarAliasDefinition()`.)
        foreach ($this->externalSchemaReferences as $className => $ref) {
            $definition = $this->dtoSchemas[$className] ?? null;
            if (
                array_key_exists($className, $this->enumSchemas)
                || (is_array($definition) && !$this->isArrayAliasComponent($definition))
            ) {
                continue;
            }

            $unresolved[] = sprintf('%s (would name class "%s", which is never written)', $ref, $className);
        }

        if ($unresolved === []) {
            return;
        }

        sort($unresolved);

        throw new RuntimeException(sprintf(
            'Unresolvable reference%s: the document declares no such schema, so the generated code '
            . 'would name a class that is never emitted. %s',
            count($unresolved) === 1 ? '' : 's',
            implode('; ', $unresolved),
        ));
    }

    private function getSchemaSourceFile(string $className): ?string
    {
        return $this->schemaSourceFiles[$className] ?? null;
    }

    /**
     * Spec path for a DTO's `Spec:` doc line, as a PhpStorm-navigable `@link` target relative to the
     * generated file's own directory (so Ctrl+B / Ctrl+Click opens the source yaml). The relative
     * geometry between the output directory and the spec file is stable across machines, so
     * generated output stays deterministic. Returns null when no source file is known (e.g.
     * generateFromArray).
     */
    private function resolveSpecLink(string $className): ?string
    {
        $specFile = $this->schemaSourceFiles[$className] ?? $this->enumSourceFiles[$className] ?? null;
        if ($specFile === null) {
            return null;
        }

        $outputDirectory = $this->schemaOutputDirectories[$className]
            ?? $this->enumOutputDirectories[$className]
            ?? $this->baseOutputDirectory;
        if ($outputDirectory === '') {
            return null;
        }

        return $this->makeRelativePath(
            fromDirectory: $this->toAbsolutePath($outputDirectory),
            toPath: $this->toAbsolutePath($specFile),
        );
    }

    /**
     * Resolves a path to absolute form for relative-path math. An already-absolute path is returned
     * unchanged; a relative one is anchored at the current working directory. The shared CWD prefix
     * cancels out in makeRelativePath, so the resulting relative link is independent of where the
     * generator ran.
     */
    private function toAbsolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();

        return $cwd === false ? $path : rtrim($cwd, '/') . '/' . $path;
    }

    /**
     * Records the field a nameless nested DTO was inlined from, as "OwnerClass.property" (with a
     * "[]" suffix when the DTO is the item type of an array property). Surfaced as a `From:` doc
     * line so a synthesised DTO's origin is discoverable.
     */
    private function recordSynthesizedOrigin(
        string $className,
        string $ownerClassName,
        string $propertyName,
        bool $arrayItem = false,
    ): void {
        $this->relatedByClass[$className] = $ownerClassName . '.' . $propertyName . ($arrayItem ? '[]' : '');

        // A synthesised DTO belongs to exactly one operation, so it inherits its owner's endpoint
        // (the `Route:` line). The owner's entry is already set by the time its properties resolve;
        // for deeper nesting the owner is itself a synthesised class that inherited it in turn.
        if (array_key_exists($ownerClassName, $this->endpointByClass)) {
            $this->endpointByClass[$className] = $this->endpointByClass[$ownerClassName];
        }
    }

    private function getClassNamespace(string $className): ?string
    {
        return $this->schemaNamespaces[$className] ?? $this->enumNamespaces[$className] ?? null;
    }

    /**
     * @param SchemaMetadata $schemaMetadata
     */
    private function renderDtoClass(string $namespace, string $className, array $schemaMetadata): string
    {
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL) {
            // Laravel DTOs are flattened for the same reason Symfony ones are: the payload is
            // validated as one flat rule set and hydrated by a single factory, so a partially
            // initialised parent would have nothing to hydrate it.
            return $this->renderLaravelDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL_DATA) {
            // Flattened for the same reason, and one more: laravel-data reads the CONSTRUCTOR to learn
            // a class's properties, so an inherited property that never reaches this constructor would
            // not be hydrated at all.
            return $this->renderLaravelDataDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_YII3) {
            // Flattened for the same reason as Symfony: the hydrator populates ONE constructor, so an
            // inherited property that never reaches it would never be hydrated.
            return $this->renderYii3DtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                extends: $schemaMetadata['extends'],
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
            // Symfony DTOs are flattened: inherited properties are merged into a single standalone
            // constructor (no `extends`/parent::__construct chaining), which maps cleanly onto the
            // Symfony serializer/validator without partially-initialised parent state.
            return $this->renderSymfonyDtoClass(
                namespace: $namespace,
                className: $className,
                properties: $this->flattenedProperties($className, $schemaMetadata['properties']),
                unionTypes: $schemaMetadata['unionTypes'],
                discriminator: $schemaMetadata['discriminator'] ?? null,
                extends: $schemaMetadata['extends'],
                isAbstract: $schemaMetadata['abstract'] ?? false,
            );
        }

        return $this->renderRuntimeDtoClass($namespace, $className, $schemaMetadata);
    }

    /**
     * The single-quoted PHP literal for a value, quotes included — the ONE spelling every renderer and
     * every template goes through (templates via the `php_string` Twig filter).
     *
     * It is one function because it was three, and they disagreed: the Laravel helper escaped every
     * backslash, the yii3 renderer escaped only the quote in one place and both in another, and four
     * literals escaped nothing at all. A schema property named `it's` then produced a file that did not
     * parse — in runtime and yii3 modes.
     */
    private function phpStringLiteral(string $value): string
    {
        return "'" . $this->escapeSingleQuoted($value) . "'";
    }

    /**
     * Escapes a string for a single-quoted PHP literal using the minimal form: in single quotes
     * only `\` and `'` are special, and a backslash is literal unless it precedes another
     * backslash or a quote (or terminates the string). Emitting `\\` for every backslash is valid
     * but php-cs-fixer's string_implicit_backslashes rule strips the redundant ones — producing
     * this minimal form up front keeps generated code a fixed point of that rule.
     */
    private function escapeSingleQuoted(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === "'") {
                $result .= "\\'";
            } elseif ($char === '\\') {
                $next = $i + 1 < $length ? $value[$i + 1] : '';
                $result .= ($next === '\\' || $next === "'" || $next === '') ? '\\\\' : '\\';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderPhpTemplate(string $templateName, array $context): string
    {
        $content = $this->renderTwig($templateName, $context);
        // Every class generated into the file's namespace owns its short name there, whether this file
        // mentions it or not: no import may take it, or a sibling's class is shadowed by a library one.
        $namespace = preg_match('/^namespace ([\w\\\]+);$/m', $content, $declared) === 1 ? $declared[1] : '';

        return GlobalFunctionImports::apply(
            php: QualifiedNameImports::apply(
                php: self::normalizeEmittedBlankLines(rtrim($content) . "\n"),
                reservedShortNames: $this->generatedClassNamesIn($namespace),
            ),
        );
    }

    /**
     * The two blank-line rules every emitted file obeys, applied once instead of per template.
     *
     * A conditional block that emits nothing still leaves its separator behind, and the templates have
     * enough of those that chasing the whitespace tag by tag is how one gets fixed and the next four
     * appear. Only whitespace tokens outside literals and comments are rewritten:
     *
     *   - never two blank lines in a row;
     *   - never a blank line right before a closing brace.
     *
     * They are exactly what `EmittedCodeSpacingTest` checks, so a template can be edited without also
     * having to reason about which of its neighbours happened to be skipped.
     */
    private static function normalizeEmittedBlankLines(string $php): string
    {
        $tokens = token_get_all($php);
        $result = '';
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }
            $text = $token[1];
            if ($token[0] === T_WHITESPACE) {
                $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
                if (($tokens[$index + 1] ?? null) === '}') {
                    $text = (string)preg_replace("/\n[ \t]*\n([ \t]*)$/", "\n$1", $text);
                }
            }
            $result .= $text;
        }

        return $result;
    }

    /**
     * Document text as it may stand on one docblock line. Descriptions were folded onto one line where
     * they were read; `format`, `pattern`, a wire name were not, and a line break in them started a new
     * docblock line — one that begins with `@` is a TAG to every static analyser, so a `format` of
     * `x\n * @var \Other` rewrote the type a reviewer saw. Folding here, in the one filter every
     * docblock interpolation goes through, covers the text no reader thought of. Only the breaks are
     * folded: a run of spaces inside a pattern is part of what it says.
     */
    private function docBlockText(string $value): string
    {
        // `\R` under `/u` is every line break a phpDoc parser may split on, U+2028 and U+2029 included.
        // Text that is not UTF-8 has no such characters, and the byte-wise breaks are folded instead.
        $folded = preg_replace('/[ \t]*\R[ \t]*/u', ' ', $value)
            ?? str_replace(["\r\n", "\r", "\n", "\v", "\f"], ' ', $value);

        return str_replace('*/', '* /', $folded);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTwig(string $templateName, array $context): string
    {
        return $this->getTwig()->render($templateName, $context);
    }

    private function getTwig(): Environment
    {
        if ($this->twig instanceof Environment) {
            return $this->twig;
        }

        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        $this->twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape' => false,
            'cache' => false,
            'trim_blocks' => true,
            'lstrip_blocks' => true,
        ]);

        // A template writes `'{{ name|php_string }}'` and gets the SAME escaping the renderers use.
        // Before this each template spelled the rule itself — `|replace({'\\':'\\\\','\'':'\\\''})` in about
        // thirty places, only the quote half in the yii3 one, and nothing at all in four literals —
        // so a property named `it's` produced a file that did not parse in runtime and yii3 modes.
        // One filter is the only way the answer cannot differ between two templates.
        $this->twig->addFilter(new TwigFilter(
            name: 'php_string',
            callable: fn(string $value): string => $this->escapeSingleQuoted($value),
        ));

        $this->twig->addFilter(new TwigFilter(
            name: 'php_doc',
            callable: fn(string $value): string => $this->docBlockText($value),
        ));

        return $this->twig;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @param array<string> $unionTypes
     * @param array{propertyName: string, mapping: array<array-key, string>}|null $discriminator
     * @return array<int, string>
     */
    private function collectGeneratedClassImports(
        string $namespace,
        string $className,
        array $properties,
        ?string $extends,
        array $unionTypes,
        ?array $discriminator,
    ): array {
        $imports = [];

        if ($extends !== null) {
            $this->appendImportForClass(
                imports: $imports,
                className: $extends,
                currentNamespace: $namespace,
                currentClassName: $className,
            );
        }

        foreach ($unionTypes as $unionType) {
            $this->appendImportForClass(
                imports: $imports,
                className: $unionType,
                currentNamespace: $namespace,
                currentClassName: $className,
            );
        }

        // The union interfaces this class implements (a member) or extends (a nested union interface):
        // a member a referenced document's run writes into another namespace names an interface of
        // THIS run's namespace, and without the import `implements Shape` resolved next to the member.
        foreach ($this->unionInterfacesByClass[$className] ?? [] as $unionInterface) {
            $this->appendImportForClass(
                imports: $imports,
                className: $unionInterface,
                currentNamespace: $namespace,
                currentClassName: $className,
            );
        }

        foreach ($properties as $property) {
            foreach ($this->extractReferencedClassNamesFromType($property['type']) as $typeClass) {
                $this->appendImportForClass(
                    imports: $imports,
                    className: $typeClass,
                    currentNamespace: $namespace,
                    currentClassName: $className,
                );
            }
        }

        if ($discriminator !== null) {
            foreach ($discriminator['mapping'] as $targetClass) {
                $this->appendImportForClass(
                    imports: $imports,
                    className: $targetClass,
                    currentNamespace: $namespace,
                    currentClassName: $className,
                );
            }
        }

        sort($imports);

        return array_values(array_unique($imports));
    }

    /**
     * @param array<int, string> $imports
     */
    private function appendImportForClass(
        array &$imports,
        string $className,
        string $currentNamespace,
        string $currentClassName,
    ): void {
        $typeNamespace = $this->getClassNamespace($className);
        if ($typeNamespace === null || $typeNamespace === '' || $typeNamespace === $currentNamespace || $className === $currentClassName) {
            return;
        }

        $imports[] = $typeNamespace . '\\' . $className;
    }

    /**
     * The generated classes — DTOs and enums — a property type names at any depth. What a mode's import
     * collector has to `use` beyond the property's own class and item class: the value class of a map of
     * maps (`array<string, array<string, Tile>>`) is neither, and from another namespace it went
     * unimported in every mode.
     *
     * @return array<int, string>
     */
    private function generatedClassesNamedByType(string $type): array
    {
        return array_values(array_filter(
            array: $this->extractReferencedClassNamesFromType($type),
            callback: fn(string $class): bool => array_key_exists($class, $this->dtoSchemas)
                || array_key_exists($class, $this->enumSchemas),
        ));
    }

    /**
     * Every class a property type names, however deep: `?Tile`, `Tile|Shade`, `array<string, Tile>`,
     * `array<string, array<string, Tile>>`, `array<array<Tile>>`.
     *
     * It used to strip `array<`, `>` and `?` and split on `|`, which left `string, Tile` for a map — no
     * class at all — and `string, string, Tile` for a map of maps. A map of maps whose values are a class
     * from ANOTHER namespace therefore got no `use`: the docblock and the hydration code named `Tile`, and
     * PHP resolved it in the current namespace. A map of one level only escaped because the item type is
     * imported on another path. Reading the type as identifiers covers every nesting at once.
     *
     * @return array<int, string>
     */
    private function extractReferencedClassNamesFromType(string $type): array
    {
        preg_match_all('/[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/', $type, $identifiers);
        $result = [];

        $notGenerated = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'];
        foreach ($identifiers[0] as $identifier) {
            if (in_array($identifier, $notGenerated, true)) {
                continue;
            }

            $result[] = $identifier;
        }

        return array_values(array_unique($result));
    }

    private function formatClassNameForNamespace(string $className, string $currentNamespace): string
    {
        $typeNamespace = $this->getClassNamespace($className);

        return ($typeNamespace === null || $typeNamespace === '' || $typeNamespace === $currentNamespace)
            ? $className
            : $className;
    }

    private function formatPhpTypeForNamespace(string $type, string $currentNamespace): string
    {
        $splitResult = preg_split('/\|/', $type);
        $parts = $splitResult !== false ? $splitResult : [];
        $formatted = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (
                $part === '' || in_array(
                    needle: $part,
                    haystack: ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'DateTimeImmutable', 'UploadedFile'],
                    strict: true,
                )
            ) {
                $formatted[] = $part;
                continue;
            }

            $formatted[] = $this->formatClassNameForNamespace($part, $currentNamespace);
        }

        return implode('|', $formatted);
    }

    private function formatDocblockTypeForNamespace(string $type, string $currentNamespace): string
    {
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            $inner = substr($type, 6, -1);
            return 'array<' . $this->formatPhpTypeForNamespace($inner, $currentNamespace) . '>';
        }

        return $this->formatPhpTypeForNamespace($type, $currentNamespace);
    }

    /**
     * @param array<int, SchemaProperty> $properties
     */
    private function needsDateTimeImmutableImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = $property['type'];
            if ($type === 'DateTimeImmutable' || str_contains($type, 'DateTimeImmutable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     */
    private function needsUploadedFileImport(array $properties): bool
    {
        foreach ($properties as $property) {
            $type = $property['type'];
            if ($type === 'UploadedFile' || str_contains($type, 'UploadedFile')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function deduplicatePropertiesByLastDefinition(array $properties): array
    {
        $latestByName = [];
        foreach ($properties as $property) {
            $latestByName[$property['name']] = $property;
        }

        $result = [];
        $seen = [];

        for ($i = count($properties) - 1; $i >= 0; $i--) {
            $name = $properties[$i]['name'];
            if (array_key_exists($name, $seen)) {
                continue;
            }

            $result[] = $latestByName[$name];
            $seen[$name] = true;
        }

        return array_reverse($result);
    }

    /**
     * @param array<int, SchemaProperty> $properties
     * @return array<string, SchemaProperty>
     */
    private function indexPropertiesByName(array $properties): array
    {
        $result = [];
        foreach ($properties as $property) {
            $result[$property['name']] = $property;
        }

        return $result;
    }

    /**
     * One property per name for a class built from several `allOf` branches. A branch that restates a
     * property another branch (or the parent) declares NARROWS it — under `allOf` both schemas hold —
     * so the two are merged rather than the later one replacing the earlier: the parent's `required`,
     * `maxLength` and enum stayed behind while only the branch's `minLength` was checked. Only a real
     * conflict, two types no value can satisfy at once, is refused.
     *
     * The merged property keeps the position of the first declaration, which is where the class lists it.
     *
     * @param array<int, SchemaProperty> $properties
     * @return array<int, SchemaProperty>
     */
    private function mergeRestatedProperties(array $properties, string $className): array
    {
        /** @var array<string, int> $positionByName */
        $positionByName = [];
        $merged = [];
        foreach ($properties as $property) {
            $position = $positionByName[$property['name']] ?? null;
            if ($position === null) {
                $positionByName[$property['name']] = count($merged);
                $merged[] = $property;
                continue;
            }

            $merged[$position] = $this->mergePropertyRestatement($merged[$position], $property, $className);
        }

        return $merged;
    }

    /**
     * The property both declarations describe at once.
     *
     * @param SchemaProperty $earlier
     * @param SchemaProperty $later
     * @return SchemaProperty
     */
    private function mergePropertyRestatement(array $earlier, array $later, string $className): array
    {
        $type = $this->narrowerRestatedType($earlier['type'], $later['type']);
        if ($type === null) {
            throw new RuntimeException(sprintf(
                'Property merge conflict in %s for $%s: type %s vs %s.',
                $className,
                $earlier['name'],
                $this->describePropertyType($earlier),
                $this->describePropertyType($later),
            ));
        }

        // The declaration that carries the narrower type also carries what belongs to it — a temporal
        // format, an item type; with equal types the later one does, as a restatement always did.
        $property = $type === $later['type'] ? $later : $earlier;
        $required = $earlier['required'] || $later['required'];
        $schemaNullable = ($earlier['schemaNullable'] ?? $earlier['nullable']) && ($later['schemaNullable'] ?? $later['nullable']);
        $property['required'] = $required;
        $property['schemaNullable'] = $schemaNullable;
        $property['nullable'] = $schemaNullable || !$required;
        $property['default'] = $later['default'] ?? $earlier['default'];
        $property['description'] = $later['description'] ?? $earlier['description'];
        $property['example'] = $later['example'] ?? $earlier['example'];
        $property['constraints'] = $this->mergeRestatedConstraints($earlier['constraints'] ?? [], $later['constraints'] ?? []);
        foreach (['readOnly', 'writeOnly', 'deprecated'] as $flag) {
            $property[$flag] = ($earlier[$flag] ?? false) || ($later[$flag] ?? false);
        }

        return $property;
    }

    /**
     * The PHP type a value of both declarations has, or null when no value has both. An untyped
     * restatement (`{minLength: 2}`) is `mixed` and adds no type of its own; an enum narrows the
     * scalar it is backed by.
     */
    private function narrowerRestatedType(string $earlier, string $later): ?string
    {
        if ($earlier === $later || $later === 'mixed') {
            return $earlier;
        }
        if ($earlier === 'mixed') {
            return $later;
        }
        if (($this->enumSchemas[$earlier]['type'] ?? null) === $later) {
            return $earlier;
        }
        if (($this->enumSchemas[$later]['type'] ?? null) === $earlier) {
            return $later;
        }

        return null;
    }

    /**
     * Both declarations' rules. Keywords only one side sets are simply joined, which keeps the result
     * the flat map every mode reads; a bound both set keeps the stricter value, which is what both
     * together allow. Any other keyword both set to different values cannot be joined that way, so the
     * two maps are kept whole under an `allOf`, which every mode's interpreter enforces.
     *
     * @param array<string, mixed> $earlier
     * @param array<string, mixed> $later
     * @return array<string, mixed>
     */
    private function mergeRestatedConstraints(array $earlier, array $later): array
    {
        $merged = $earlier;
        foreach ($later as $keyword => $value) {
            $current = $merged[$keyword] ?? null;
            if ($current === null || $current === $value) {
                $merged[$keyword] = $value;
                continue;
            }

            $bothNumeric = (is_int($current) || is_float($current)) && (is_int($value) || is_float($value));
            if ($bothNumeric && in_array($keyword, self::LOWER_BOUND_KEYWORDS, true)) {
                $merged[$keyword] = max($current, $value);
                continue;
            }
            if ($bothNumeric && in_array($keyword, self::UPPER_BOUND_KEYWORDS, true)) {
                $merged[$keyword] = min($current, $value);
                continue;
            }

            return ['allOf' => [$earlier, $later]];
        }

        return $merged;
    }

    /**
     * @param SchemaProperty $property
     */
    private function describePropertyType(array $property): string
    {
        return $this->composePhpTypeHint($property['type'], $property['nullable']);
    }

    private function composePhpTypeHint(string $type, bool $nullable): string
    {
        if (!$nullable) {
            return $type;
        }

        // `mixed` already includes null — `?mixed` is a fatal error
        // ("Type mixed cannot be marked as nullable since mixed already includes null").
        if ($type === 'mixed') {
            return 'mixed';
        }

        // Only the TOP-LEVEL members decide: `array<string, float|null>` is one member, a list, and a
        // `null` inside its brackets is the value's, not the property's. Read as a whole the string
        // "contained null", so a nullable list of such maps was documented non-nullable and Symfony
        // refused the null at deserialization.
        $members = $this->topLevelTypeMembers($type);
        if (count($members) > 1) {
            return in_array('null', $members, true) ? $type : $type . '|null';
        }

        return '?' . $type;
    }

    /**
     * The members of a PHP / phpdoc union type at its top level, generic arguments and array shapes
     * left whole: `array<int|null>|string` is `array<int|null>` and `string`.
     *
     * @return list<string>
     */
    private function topLevelTypeMembers(string $type): array
    {
        $members = [];
        $depth = 0;
        $current = '';
        foreach (str_split($type) as $character) {
            if (in_array($character, ['<', '{', '('], true)) {
                $depth++;
            } elseif (in_array($character, ['>', '}', ')'], true)) {
                $depth--;
            } elseif ($character === '|' && $depth === 0) {
                $members[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $members[] = trim($current);

        return $members;
    }

    /**
     * @return array<int, SchemaProperty>
     */
    private function getParentProperties(string $parentClassName): array
    {
        // Resolve recursively: a parent may itself be an allOf composition (deep inheritance
        // chain, e.g. a discriminator variant that is also a discriminator base). A flat read
        // of `properties` would return nothing for such a parent, so the child would drop the
        // grandparent's constructor arguments and omit parent::__construct().
        return $this->getSchemaProperties($parentClassName);
    }

    /**
     * Recursively get all properties from a schema, including inherited ones.
     *
     * @param array<string, true> $visiting allOf ancestry on the current recursion path, used to
     *                                       detect (and reject) circular allOf inheritance
     * @return array<int, SchemaProperty>
     */
    private function getSchemaProperties(string $className, array $visiting = []): array
    {
        $schemaDefinition = $this->dtoSchemas[$className] ?? null;
        if ($schemaDefinition === null) {
            return [];
        }

        // A discriminated union that extends its members' common parent holds that parent's properties.
        $unionParent = $this->unionBaseParent($className);
        if ($unionParent !== null) {
            return $this->dedupeCaseInsensitivePropertyNames(properties: $this->mergeRestatedProperties(
                properties: [
                    ...$this->getSchemaProperties($unionParent, $visiting),
                    ...$this->extractProperties($schemaDefinition, $className),
                ],
                className: $className,
            ));
        }

        // If schema has allOf with inheritance, collect parent properties first
        if (array_key_exists('allOf', $schemaDefinition) && is_array($schemaDefinition['allOf'])) {
            if (array_key_exists($className, $visiting)) {
                throw new RuntimeException(sprintf(
                    'Circular allOf inheritance detected involving %s (cycle: %s). '
                    . 'A schema cannot transitively compose itself via allOf.',
                    $className,
                    implode(' -> ', [...array_keys($visiting), $className]),
                ));
            }
            $visiting[$className] = true;

            $allProperties = [];

            foreach ($schemaDefinition['allOf'] as $allOfItem) {
                if (!is_array($allOfItem)) {
                    continue;
                }

                if (array_key_exists('$ref', $allOfItem) && is_string($allOfItem['$ref'])) {
                    $parentClass = $this->schemaRefToClassName(
                        ref: $allOfItem['$ref'],
                        currentSourceFile: $this->getSchemaSourceFile($className),
                    );
                    // Recursively get parent properties
                    foreach ($this->getSchemaProperties($parentClass, $visiting) as $prop) {
                        $allProperties[] = $prop;
                    }
                    continue;
                }

                foreach (
                    $this->extractProperties(
                        schemaDefinition: $allOfItem,
                        ownerClassName: $className,
                        siblingProperties: $this->allOfBranchProperties($schemaDefinition, $className),
                    ) as $property
                ) {
                    $allProperties[] = $property;
                }
            }

            // `properties` written BESIDE the `allOf` belong to the same object as the branches': the
            // keyword sits in the same schema. Read only through the branches, they were dropped from
            // the class — `{properties: {alpha}, allOf: [{properties: {beta}}]}` hydrated `beta` alone,
            // `alpha` went unchecked, and a closed object refused the very key its document declared.
            if (is_array($schemaDefinition['properties'] ?? null) && $schemaDefinition['properties'] !== []) {
                foreach (
                    $this->extractProperties(
                        schemaDefinition: $schemaDefinition,
                        ownerClassName: $className,
                        siblingProperties: $this->allOfBranchProperties($schemaDefinition, $className),
                    ) as $property
                ) {
                    $allProperties[] = $property;
                }
            }

            $allProperties = [
                ...$allProperties,
                ...$this->unionBranchOnlyProperties(schemaDefinition: $schemaDefinition, className: $className, declared: $allProperties),
            ];

            return $this->dedupeCaseInsensitivePropertyNames(properties: $this->mergeRestatedProperties(
                properties: $this->withSiblingRequired($allProperties, $schemaDefinition),
                className: $className,
            ));
        }

        return $this->classLevelUnionObjectProperties(schemaDefinition: $schemaDefinition, className: $className);
    }

    /**
     * The properties of a class that is no `allOf`: its own, and — when its `anyOf` / `oneOf` arms are
     * class-level checks (`hasClassLevelUnionArmsOnly()`) — the keys only those arms name, untyped, as
     * `unionBranchOnlyProperties()` adds them beside an `allOf`. Left out, `{properties: {size},
     * anyOf: [{required: [size]}, {required: [tag]}]}` dropped `tag` on hydration and refused
     * `{"tag": 1}` that its second arm accepts. A closed object (`additionalProperties: false`) admits
     * no key it does not declare, so nothing is added there.
     *
     * @param array<string, mixed> $schemaDefinition
     * @return array<int, SchemaProperty>
     */
    private function classLevelUnionObjectProperties(array $schemaDefinition, string $className): array
    {
        $declared = $this->extractProperties($schemaDefinition, $className);
        if (!$this->hasClassLevelUnionArmsOnly($schemaDefinition) || ($schemaDefinition['additionalProperties'] ?? null) === false) {
            return $declared;
        }

        return [
            ...$declared,
            ...$this->unionBranchOnlyProperties(schemaDefinition: $schemaDefinition, className: $className, declared: $declared),
        ];
    }

    /**
     * The keys only an `anyOf` / `oneOf` beside the `allOf` names — in a branch's `properties` or its
     * `required` — as optional properties of any value. The union judges the object as a whole
     * (`classLevelUnionsBesideAllOf()`), and a key the class does not declare is dropped on hydration
     * before it runs: `anyOf: [{properties: {a: {minLength: 3}}}, {required: [z]}]` refused
     * `{"a": "x", "z": 2}`, its `z` gone. Typed by nothing here: which branch holds is the union's to say.
     *
     * @param array<string, mixed> $schemaDefinition
     * @param array<int, SchemaProperty> $declared
     * @return array<int, SchemaProperty>
     */
    private function unionBranchOnlyProperties(array $schemaDefinition, string $className, array $declared): array
    {
        $declaredNames = array_map(static fn(array $property): string => $property['openApiName'], $declared);
        $names = [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schemaDefinition[$keyword] ?? null) ? $schemaDefinition[$keyword] : [] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                foreach ($this->unionBranchKeyNames(branch: $branch, className: $className) as $name) {
                    if (is_string($name) && !in_array($name, $declaredNames, true)) {
                        $names[$name] = [];
                    }
                }
            }
        }
        if ($names === []) {
            return [];
        }

        return $this->extractProperties(schemaDefinition: ['type' => 'object', 'properties' => $names], ownerClassName: $className);
    }

    /**
     * The keys one branch of a class-level union names: its `properties` and its `required`. A `$ref`
     * branch names those of the component it points to — its own and its inline `allOf` members',
     * read off the schema and not resolved further, so a branch referring back to the class cannot
     * loop. Skipped, the union `oneOf: [$ref Cat, $ref Dog]` beside an `allOf` refused every payload:
     * Cat's `paw` was dropped on hydration before the union looked for it.
     *
     * @param array<mixed> $branch
     * @return list<mixed>
     */
    private function unionBranchKeyNames(array $branch, string $className): array
    {
        $ref = $branch['$ref'] ?? null;
        $schemas = [$branch];
        if (is_string($ref)) {
            $target = $this->dtoSchemas[$this->schemaRefToClassName(ref: $ref, currentSourceFile: $this->getSchemaSourceFile($className))] ?? null;
            $schemas = is_array($target) && !$this->rendersAsUnion($target) ? [$target] : [];
            foreach (is_array($target['allOf'] ?? null) ? $target['allOf'] : [] as $member) {
                if (is_array($member) && !array_key_exists('$ref', $member)) {
                    $schemas[] = $member;
                }
            }
        }
        $names = [];
        foreach ($schemas as $schema) {
            $names = [
                ...$names,
                ...array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []),
                ...array_values(is_array($schema['required'] ?? null) ? $schema['required'] : []),
            ];
        }

        return $names;
    }

    /**
     * `required` written BESIDE an `allOf` binds the properties its branches declare — it is a keyword
     * of the same schema. Each branch was read on its own, so a property listed there came out optional:
     * typed nullable and tracked for presence, while the validation, built from the whole schema, did
     * require it. The flag is set here, where the branches meet.
     *
     * A `required` inside one inline branch binds the whole object just the same: `allOf: [$ref Base,
     * {required: [name]}]` requires the `name` Base declares. Reading only the outer list left it
     * optional, and `{}` went through.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<string, mixed> $schemaDefinition
     * @return array<int, SchemaProperty>
     */
    private function withSiblingRequired(array $properties, array $schemaDefinition): array
    {
        $required = $this->allOfRequiredNames($schemaDefinition);
        if ($required === []) {
            return $properties;
        }

        $marked = [];
        foreach ($properties as $property) {
            if (!$property['required'] && in_array($property['openApiName'], $required, true)) {
                $property['required'] = true;
                $property['nullable'] = $property['schemaNullable'] ?? $property['nullable'];
            }
            $marked[] = $property;
        }

        return $marked;
    }

    /**
     * Every name a `required` of an `allOf` class lists: the one beside the `allOf` and the one in each
     * inline branch. A `$ref` branch's list is its own class's business and arrives with its properties.
     *
     * @param array<string, mixed> $schemaDefinition
     * @return list<string>
     */
    private function allOfRequiredNames(array $schemaDefinition): array
    {
        $lists = [$schemaDefinition['required'] ?? null];
        foreach (is_array($schemaDefinition['allOf'] ?? null) ? $schemaDefinition['allOf'] : [] as $branch) {
            if (is_array($branch) && !array_key_exists('$ref', $branch)) {
                $lists[] = $branch['required'] ?? null;
            }
        }

        $names = [];
        foreach ($lists as $list) {
            foreach (is_array($list) ? $list : [] as $name) {
                if (is_string($name) || is_int($name)) {
                    $names[(string)$name] = true;
                }
            }
        }

        // A digit name comes back from the keys as an int; `openApiName` is always a string.
        return array_map('strval', array_keys($names));
    }

    /**
     * The properties a child inherits but narrows: restated in a branch, or named by one of its
     * `required` lists. The parent keeps the storage and the accessors; the child's entry is the
     * merged view of the property, which is what its constructor and its own rules have to follow —
     * without it the inherited property stayed as optional and as loosely checked as the parent left it.
     *
     * @param array<int, SchemaProperty> $properties the child's own, already merged
     * @param array<string, mixed> $schemaDefinition
     * @return array<int, SchemaProperty>
     */
    private function withInheritedRestatements(array $properties, string $parentClassName, array $schemaDefinition, string $className): array
    {
        $inherited = $this->indexPropertiesByName(
            properties: $this->deduplicatePropertiesByLastDefinition($this->getSchemaProperties($parentClassName)),
        );

        $result = [];
        $restated = [];
        foreach ($properties as $property) {
            $parentProperty = $inherited[$property['name']] ?? null;
            // Types no value satisfies at once are left as the child wrote them: the mode that cannot
            // emit such an override says so in its own words ("Property override conflict").
            if ($parentProperty !== null) {
                $restated[$property['name']] = true;
                if ($this->narrowerRestatedType($parentProperty['type'], $property['type']) !== null) {
                    $property = $this->mergePropertyRestatement($parentProperty, $property, $className);
                }
            }
            $result[] = $property;
        }

        $required = $this->allOfRequiredNames($schemaDefinition);
        foreach ($inherited as $name => $parentProperty) {
            if (
                array_key_exists($name, $restated)
                || $parentProperty['required']
                || !in_array($parentProperty['openApiName'], $required, true)
            ) {
                continue;
            }
            $parentProperty['required'] = true;
            $parentProperty['nullable'] = $parentProperty['schemaNullable'] ?? $parentProperty['nullable'];
            $result[] = $parentProperty;
        }

        return $result;
    }

    /**
     * The class name for a whole schema name, safe to declare in PHP.
     *
     * `normalizeClassName()` is also used for FRAGMENTS that are concatenated into a larger name
     * (`$ownerClassName . normalizeClassName($propertyName)`, route segments, `…Item`). A fragment
     * may legitimately be a keyword — `ProbeList` is a fine class name — so the reserved-word guard
     * belongs here, where the name stands on its own, and not in the normalizer.
     *
     * Every schema-name-to-class-name conversion must go through this method: `$ref` resolution looks
     * the class up by the same derived name, so a site left on the raw normalizer would not find it.
     */
    private function schemaClassName(string $schemaName): string
    {
        return $this->avoidReservedPhpClassName($this->normalizeClassName($schemaName));
    }

    /**
     * A schema named `Parent`, `List` or `Int` produced a file PHP cannot load at all — either
     * `Cannot use "Parent" as a class name as it is reserved` or a parse error on the class keyword.
     * Such a name gets a `Schema` suffix (`ParentSchema`), which is neutral about the kind: the same
     * name may end up a DTO, an enum or a union interface.
     *
     * If the document ALSO declares a schema that normalizes to the suffixed name, `registerSchema()`
     * reports it as a name collision — loudly, and with both names in the message.
     */
    private function avoidReservedPhpClassName(string $className): string
    {
        return in_array(strtolower($className), self::PHP_RESERVED_CLASS_NAMES, true)
            ? $className . 'Schema'
            : $className;
    }

    /**
     * The last segment of a class name, which is how an emitted file spells a class it has imported.
     *
     * Lived in `RendersSymfonyDto` until it was moved here: five files call it, none of them about
     * Symfony, and its three siblings — `normalizeClassName()`, `normalizePropertyName()` and
     * `formatPhpTypeForNamespace()` — were already on this class. Not to be confused with
     * {@see NamesLibraryClasses::libraryClassRef()}, which answers the harder question of WHETHER the
     * short name is safe to use at all when the document has a schema of that name.
     */
    private function shortClassName(string $type): string
    {
        $parts = explode('\\', $type);

        return end($parts);
    }

    private function normalizeClassName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $name);
        $parts = array_values(array_filter($splitResult !== false ? $splitResult : [], static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'GeneratedDto';
        }

        $normalized = '';
        foreach ($parts as $part) {
            $normalized .= ucfirst(strtolower($part));
        }

        if (is_numeric($normalized[0])) {
            return 'Value' . $normalized;
        }

        return $normalized;
    }

    /**
     * The same rule as `disambiguateCaseInsensitiveName()`, applied to an ALREADY assembled property
     * list — an `allOf` merge builds its list from several branches, each with its own naming pass,
     * so a case-only clash between two branches (or against an inherited property) only becomes
     * visible here.
     *
     * `$reservedNames` are the PHP names a subclass cannot use because its parent already defines the
     * accessor: `Discriminator` declares `name`, so `Discriminator2`'s own `NAme` must not render as
     * `getNAme()`, which PHP would treat as an override of `getName()`.
     *
     * @param array<int, SchemaProperty> $properties
     * @param array<int, string> $reservedNames
     * @return array<int, SchemaProperty>
     */
    private function dedupeCaseInsensitivePropertyNames(array $properties, array $reservedNames = []): array
    {
        /** @var array<string, string> $assigned normalized PHP name => OpenAPI name */
        $assigned = [];
        foreach ($reservedNames as $reservedName) {
            $assigned[$reservedName] = "\0reserved";
        }

        /** @var array<string, string> $namesByOpenApiName */
        $namesByOpenApiName = [];

        foreach ($properties as $index => $property) {
            $openApiName = $property['openApiName'];

            // A property repeated by two merged branches keeps one PHP name (the branches are
            // asserted compatible elsewhere), otherwise the duplicate would be renamed to `x2`.
            if (array_key_exists($openApiName, $namesByOpenApiName)) {
                $properties[$index]['name'] = $namesByOpenApiName[$openApiName];
                continue;
            }

            $name = $this->disambiguateCaseInsensitiveName($property['name'], $openApiName, $assigned);
            $name = $this->avoidGeneratedMemberName(
                name: $name,
                reserved: $this->generatedMemberNames(array_column($properties, 'name')),
                taken: array_keys($assigned),
            );
            $assigned[$name] = $openApiName;
            $namesByOpenApiName[$openApiName] = $name;
            $properties[$index]['name'] = $name;
        }

        return $properties;
    }

    /**
     * Every property the `allOf` branches of a class declare — inline, or on a referenced component
     * with whatever it composes and renames itself — as PHP name => wire name. Each branch is read on
     * its own, so a rename in one (`this` to `this2`) could not see a real `this2` in another, and
     * the two were merged into one property.
     *
     * @param array<mixed> $schemaDefinition
     * @return array<string, string>
     */
    private function allOfBranchProperties(array $schemaDefinition, string $className): array
    {
        $properties = [];
        // The `properties` beside the `allOf` are one more branch for this purpose: the class declares them too.
        $branches = [
            ...(is_array($schemaDefinition['allOf'] ?? null) ? $schemaDefinition['allOf'] : []),
            ['properties' => $schemaDefinition['properties'] ?? []],
        ];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            if (is_string($branch['$ref'] ?? null)) {
                $refClassName = $this->schemaRefToClassName(
                    ref: $branch['$ref'],
                    currentSourceFile: $this->getSchemaSourceFile($className),
                );
                // A cycle through `allOf` is reported where the class is built; here its raw names do.
                if (array_key_exists($refClassName, $this->allOfPropertiesInProgress)) {
                    $branch = $this->dtoSchemas[$refClassName] ?? [];
                } else {
                    $this->allOfPropertiesInProgress[$refClassName] = true;
                    try {
                        foreach ($this->getSchemaProperties($refClassName) as $property) {
                            $properties[$property['name']] = $property['openApiName'];
                        }
                    } finally {
                        unset($this->allOfPropertiesInProgress[$refClassName]);
                    }
                    continue;
                }
            }
            foreach (array_keys(is_array($branch['properties'] ?? null) ? $branch['properties'] : []) as $propertyName) {
                $properties[$this->normalizePropertyName((string)$propertyName)] ??= (string)$propertyName;
            }
        }

        return $properties;
    }

    /**
     * The names the emitted class takes for itself beside the document's properties: `$this`, which
     * no parameter may be called; runtime's bag of undeclared keys and its `<property>InPath`-style
     * source flags; Laravel's presence bookkeeping;
     * Symfony's `<property>Provided` flag for each property. A document property spelled like one of them generated a class PHP refused
     * to compile — "Cannot redeclare", "Cannot use $this as parameter".
     *
     * @param list<string> $propertyNames the PHP names of the class's properties
     * @return array<string, true> lower-cased
     */
    private function generatedMemberNames(array $propertyNames): array
    {
        $reserved = ['this' => true];
        if ($this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME) {
            $reserved['undeclaredproperties'] = true;
        }
        if ($this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL) {
            $reserved['providedopenapikeys'] = true;
            $reserved['omitsunsetnulls'] = true;
        }
        foreach ($propertyNames as $propertyName) {
            foreach ($this->memberNamesOfProperty($propertyName) as $memberName) {
                $reserved[$memberName] = true;
            }
        }

        return $reserved;
    }

    /**
     * The members the emitted class declares for ONE property beside the property itself: runtime's
     * source flags, Symfony's `<property>Provided`.
     *
     * @return list<string> lower-cased
     */
    private function memberNamesOfProperty(string $propertyName): array
    {
        return match ($this->attributeMode) {
            self::ATTRIBUTE_MODE_RUNTIME => array_map(
                callback: fn(string $source): string => strtolower($this->normalizeTrackingFlagName($propertyName, $source)),
                array: ['InRequest', 'InPath', 'InQuery', 'InHeader', 'InCookie'],
            ),
            self::ATTRIBUTE_MODE_SYMFONY => [strtolower($propertyName . 'Provided')],
            default => [],
        };
    }

    /**
     * A property named like a generated member gets a numeric suffix, as a case-only collision does:
     * only the PHP name changes, the wire name stays the document's.
     *
     * @param array<string, true> $reserved
     * @param list<string> $taken
     * @param bool $renamed the name is already a rename, so its own generated members are new as well
     */
    private function avoidGeneratedMemberName(string $name, array $reserved, array $taken, bool $renamed = false): string
    {
        $takenLower = array_flip(array_map('strtolower', $taken));
        if (
            !array_key_exists(strtolower($name), $reserved)
            && !($renamed && $this->renamedMemberCollides(candidate: $name, reserved: [], takenLower: $takenLower))
        ) {
            return $name;
        }
        $suffix = 2;
        while ($this->renamedMemberCollides(candidate: $name . $suffix, reserved: $reserved, takenLower: $takenLower)) {
            $suffix++;
        }

        return $name . $suffix;
    }

    /**
     * The new name and the members it brings along: `this` renamed to `this2` declares `$this2InPath`,
     * and a real `this2InPath` beside it was "Cannot redeclare".
     *
     * @param array<string, true> $reserved
     * @param array<string, int> $takenLower
     */
    private function renamedMemberCollides(string $candidate, array $reserved, array $takenLower): bool
    {
        foreach ([strtolower($candidate), ...$this->memberNamesOfProperty($candidate)] as $memberName) {
            if (array_key_exists($memberName, $reserved) || array_key_exists($memberName, $takenLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two OpenAPI keys that differ only in case (`name` and `NAme`) produce two DISTINCT PHP
     * properties — property names are case-sensitive — but their accessors are not: PHP method names
     * are case-insensitive, so `getName()` and `getNAme()` are the same method. In Symfony mode that
     * is a fatal "Cannot redeclare"; in runtime mode the child's `getNAme()` silently OVERRIDES the
     * parent's `getName()` (covariant return type, so it parses), and reading `name` returns the
     * value of `NAme`. Both are wrong and both are reachable from a perfectly valid document.
     *
     * The second and later spellings therefore get a numeric suffix (`$nAme2`, `getNAme2()`). Only
     * the PHP identifier changes: the wire name is carried by `#[SerializedName]` in Symfony mode
     * and by the php-to-OpenAPI name map in runtime mode, so payloads are unaffected.
     *
     * An EXACT normalized collision (`first_name` and `firstName` both become `$firstName`) still
     * throws in the caller — there the two keys are indistinguishable in PHP, and silently renaming
     * one would hide a modelling mistake.
     *
     * @param array<string, string> $normalizedToOpenApiName normalized PHP name => OpenAPI name
     */
    private function disambiguateCaseInsensitiveName(
        string $normalizedName,
        string $openApiPropertyName,
        array $normalizedToOpenApiName,
    ): string {
        $taken = [];
        $takenExactly = [];
        foreach ($normalizedToOpenApiName as $takenName => $takenOpenApiName) {
            if ($takenOpenApiName === $openApiPropertyName) {
                continue;
            }
            $taken[strtolower($takenName)] = true;
            $takenExactly[$takenName] = true;
        }

        // An identically spelled name is a property OVERRIDE — a subclass redeclaring a parent
        // property, or two allOf branches declaring the same one. That is a different question
        // (answered by the override/merge compatibility checks) and must not be renamed away.
        if (array_key_exists($normalizedName, $takenExactly)) {
            return $normalizedName;
        }

        if (!array_key_exists(strtolower($normalizedName), $taken)) {
            return $normalizedName;
        }

        $suffix = 2;
        while (array_key_exists(strtolower($normalizedName . $suffix), $taken)) {
            $suffix++;
        }

        return $normalizedName . $suffix;
    }

    private function normalizePropertyName(string $name): string
    {
        $normalized = trim($this->withoutFormatCharacters($name));
        if ($normalized === '') {
            return 'value';
        }

        // Split camelCase/PascalCase and keep arbitrary separators from OpenAPI keys.
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $normalized) ?? $normalized;
        // `\x80-\xFF` is kept because PHP identifiers accept it: `$имя` and `$名前` are legal property
        // names, and stripping those bytes left NOTHING behind — every non-ASCII name collapsed to the
        // `value` fallback below, so one such property was emitted under a name that says nothing and
        // two were a hard generation failure: "Property name collision: "имя" and "名前" normalize to
        // "$value"". The wire name is unaffected either way; `openApiName` carries it.
        $splitResult = preg_split('/[^A-Za-z0-9\x80-\xFF]+/', $normalized);
        $parts = array_values(array_filter($splitResult !== false ? $splitResult : [], static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'value';
        }

        $first = strtolower($parts[0]);
        $propertyName = $first;

        for ($index = 1, $count = count($parts); $index < $count; $index++) {
            $propertyName .= ucfirst(strtolower($parts[$index]));
        }

        if (is_numeric($propertyName[0])) {
            return 'value' . $propertyName;
        }

        return $propertyName;
    }

    /**
     * A name with its Unicode format characters (category Cf) taken out, for use as a PHP identifier.
     * They render as nothing or reorder the text around them — U+200B, U+2060, U+FEFF, the bidi
     * controls — so `$is<U+200B>admin` beside `$isAdmin` was two properties that read as one, and a
     * U+202E made a line display differently from what it does. They are dropped, not turned into a
     * separator: a reader sees no boundary there either. Two names that meet afterwards are a collision
     * like any other. The wire name keeps them; it is data.
     *
     * Text that is not UTF-8 cannot be read character by character, so every non-ASCII byte goes.
     */
    private function withoutFormatCharacters(string $name): string
    {
        return preg_replace('/\p{Cf}+/u', '', $name) ?? (string)preg_replace('/[\x80-\xFF]+/', '', $name);
    }

    /**
     * Reports a directory whose DTOs were generated in another mode and are about to be replaced.
     *
     * Schemas shared through `--ref` land in one directory for every spec that references them, so
     * generating one spec in a new mode rewrote them under the specs still generated in the old one,
     * and those broke at runtime (a runtime DTO passing `UnsetValue` to a Symfony one) with nothing
     * said at generation time. The modes cannot share such a directory; this names the clash.
     *
     * @param array<string, string> $files
     */
    private function warnAboutReplacedModes(array $files): void
    {
        $reported = [];
        foreach ($files as $path => $content) {
            $directory = dirname($path);
            if (array_key_exists($directory, $reported) || !is_file($path)) {
                continue;
            }
            $existing = file_get_contents($path);
            $previousMode = is_string($existing) ? self::generatedModeOf($existing) : null;
            $nextMode = self::generatedModeOf($content);
            if ($previousMode === null || $nextMode === null || $previousMode === $nextMode) {
                continue;
            }
            $reported[$directory] = true;
            $this->generationWarnings[] = sprintf(
                '%s holds DTOs generated with --attributes=%s; they are being replaced by %s ones. '
                . 'Every spec that shares this directory (e.g. through --ref) must be generated in one mode.',
                $directory,
                $previousMode,
                $nextMode,
            );
        }
    }

    /**
     * The mode a generated DTO class was emitted in, read from what only that mode imports; null for
     * a file that says nothing mode-specific, an enum for instance.
     */
    private static function generatedModeOf(string $source): ?string
    {
        if (!str_contains($source, 'auto-generated from an OpenAPI schema')) {
            return null;
        }

        return match (true) {
            str_contains($source, 'GeneratedDtoInterface;') => self::ATTRIBUTE_MODE_RUNTIME,
            str_contains($source, 'use Spatie\LaravelData\\') => self::ATTRIBUTE_MODE_LARAVEL_DATA,
            str_contains($source, 'use Illuminate\\') => self::ATTRIBUTE_MODE_LARAVEL,
            str_contains($source, 'use Yiisoft\\') => self::ATTRIBUTE_MODE_YII3,
            // A prefix, not the alias: beside a schema named `Assert` the constraints are imported one by one.
            str_contains($source, 'use Symfony\Component\Validator\Constraints') => self::ATTRIBUTE_MODE_SYMFONY,
            default => null,
        };
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $ownedDirectories
     */
    private function publishGeneratedFiles(array $files, array $ownedDirectories): void
    {
        $this->warnAboutReplacedModes($files);
        if (!$this->deferOutputPublication) {
            (new GeneratedFilePublisher())->publish($files, $ownedDirectories);
            return;
        }
        foreach ($files as $path => $content) {
            if (array_key_exists($path, $this->pendingOutputFiles)) {
                throw new RuntimeException(sprintf('Generated output file collision: %s', $path));
            }
            $this->pendingOutputFiles[$path] = $content;
        }
        $this->ownedOutputDirectories = [...$this->ownedOutputDirectories, ...$ownedDirectories];
    }

    /**
     * Swagger 2.0 describes payloads with a different model (`definitions`, `in: body`/`formData`,
     * `collectionFormat`), none of which this generator reads — it would quietly emit nothing
     * useful. Refuse such a document instead, and flag anything that is not an OpenAPI 3.x version.
     *
     * @param array<mixed> $openApi
     */
    private function assertSupportedSpecVersion(array $openApi): void
    {
        $swagger = $openApi['swagger'] ?? null;
        if ($swagger !== null) {
            throw new RuntimeException(sprintf(
                'Swagger %s documents are not supported: this generator reads OpenAPI 3.0+ '
                . '(components.schemas, requestBody, parameter schema/content). '
                . 'Convert the document first, e.g. with swagger2openapi.',
                is_scalar($swagger) ? (string)$swagger : '2.0',
            ));
        }

        $version = $openApi['openapi'] ?? null;
        if (!is_string($version) || $version === '') {
            $this->generationWarnings[] = 'Document has no "openapi" version field; it is read as OpenAPI 3.x.';

            return;
        }

        if (!str_starts_with($version, '3.')) {
            $this->generationWarnings[] = sprintf(
                'OpenAPI version "%s" is newer than this generator knows; it is read with the 3.x rules.',
                $version,
            );
        }
    }

    /**
     * Reads the document-level OAS 3.1/3.2 fields that affect how the rest is interpreted:
     * `$self` (the document's own URI, so a `$ref` addressing it is really a local pointer) and
     * `jsonSchemaDialect` (an unfamiliar dialect means the keyword vocabulary may differ from the
     * one this generator implements, which is worth saying out loud rather than guessing).
     *
     * @param array<mixed> $openApi
     */
    private function readDocumentLevelFields(array $openApi): void
    {
        $this->assertSupportedSpecVersion($openApi);

        $self = $openApi['$self'] ?? null;
        $this->documentSelfUri = is_string($self) && $self !== '' ? rtrim($self, '#') : null;

        $dialect = $openApi['jsonSchemaDialect'] ?? null;
        if (!is_string($dialect) || $dialect === '') {
            return;
        }

        $known = [
            'https://spec.openapis.org/oas/3.1/dialect/base',
            'https://spec.openapis.org/oas/3.1/dialect/2024-11-10',
            'https://spec.openapis.org/oas/3.2/dialect/2024-11-10',
            'https://json-schema.org/draft/2020-12/schema',
        ];

        if (!in_array(rtrim($dialect, '#'), $known, true)) {
            $this->generationWarnings[] = sprintf(
                'Unknown jsonSchemaDialect "%s": schemas are interpreted with the OAS 3.1 dialect (JSON Schema 2020-12).',
                $dialect,
            );
        }
    }

    /**
     * Rewrites a `$ref` that addresses this very document by its `$self` URI into the equivalent
     * local pointer, so it resolves against the loaded document instead of being treated as an
     * unreachable external file.
     */
    private function stripDocumentSelfPrefix(string $ref): string
    {
        if ($this->documentSelfUri === null || !str_starts_with($ref, $this->documentSelfUri)) {
            return $ref;
        }

        $remainder = substr($ref, strlen($this->documentSelfUri));

        return $remainder === '' || $remainder[0] !== '#' ? $ref : $remainder;
    }

    /**
     * Whether the DOCUMENT allows null for this schema — either spelling.
     *
     * @param array<string, mixed> $schema
     */
    private function schemaAllowsNull(array $schema): bool
    {
        if (($schema['nullable'] ?? null) === true) {
            return true;
        }

        $type = $schema['type'] ?? null;

        return $type === 'null' || (is_array($type) && in_array('null', $type, true));
    }

    /**
     * Whether a `oneOf`/`anyOf` branch list has a branch that is the null schema and nothing else.
     *
     * @param array<mixed> $branches
     */
    private function unionListsANullBranch(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && in_array($branch['type'] ?? null, ['null', ['null']], true) && count($branch) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A component admits null as a whole: `nullable: true` on it, `null` in its 3.1 `type` list, or a
     * `oneOf`/`anyOf` with a branch that is the null schema and nothing else — or a branch that is a
     * `$ref` to a component admitting null (`Teal: {anyOf: [$ref Maroon]}`, `Maroon: {type: array,
     * nullable: true}`), or the 3.0 `{$ref: Amber, nullable: true}`. Only the literal null branch was
     * read, so a property `$ref Teal` refused the null its member takes, in every mode but runtime.
     * An inline branch that admits null counts too (`{type: object, nullable: true, …}`, the 3.1
     * `type: [object, 'null']`), unless its `enum` / `const` leaves null out: a property `$ref Shape`
     * to such a union was typed without null — a `TypeError` in laravel-data, a refusal elsewhere. And
     * a one-member `allOf` of a reference admits what that reference admits.
     *
     * @param array<mixed> $definition
     * @param list<string> $visited the references already followed, against a cycle
     */
    private function componentAdmitsNull(array $definition, array $visited = []): bool
    {
        if (($definition['nullable'] ?? null) === true) {
            return true;
        }
        if (is_array($definition['type'] ?? null) && in_array('null', $definition['type'], true)) {
            return true;
        }
        // `Teal: {allOf: [{$ref: Ochre}]}` is Ochre, its null included; only a `$ref` was followed, so a
        // valid null was refused for a nullable Ochre spelled through the wrapper.
        $wrapped = $this->allOfWrappedReference($definition);
        if (is_string($wrapped['$ref'] ?? null) && $this->referenceAdmitsNull(ref: $wrapped['$ref'], visited: $visited)) {
            return true;
        }
        foreach (['oneOf', 'anyOf'] as $unionKey) {
            foreach (is_array($definition[$unionKey] ?? null) ? $definition[$unionKey] : [] as $branch) {
                if (is_array($branch) && in_array($branch['type'] ?? null, ['null', ['null']], true) && count($branch) === 1) {
                    return true;
                }
                if (
                    is_array($branch)
                    && $this->isAnnotatedReference($branch)
                    && (($branch['nullable'] ?? null) === true || $this->referenceAdmitsNull(ref: $branch['$ref'], visited: $visited))
                ) {
                    return true;
                }
                if (
                    is_array($branch)
                    && !array_key_exists('$ref', $branch)
                    && $this->branchValuesLeaveNullIn($branch)
                    && $this->componentAdmitsNull($branch, $visited)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether an inline union branch's `enum` / `const`, if it has one, still lets null through — a
     * `{type: string, nullable: true, enum: [alpha]}` branch matches no null, whatever `nullable` says.
     *
     * @param array<mixed> $branch
     */
    private function branchValuesLeaveNullIn(array $branch): bool
    {
        if (array_key_exists('const', $branch) && $branch['const'] !== null) {
            return false;
        }

        return !is_array($branch['enum'] ?? null) || in_array(null, $branch['enum'], true);
    }

    /**
     * The 3.1 spelling of a nullable container value, `type: [string, "null"]`, read as the 3.0 one.
     *
     * `schemaAllowsNull()` above already answers the permission half for both spellings. This answers
     * the other half: what the value IS once the permission is set aside. Without it a list under 3.1
     * declared a bare `array` — the item type gone, so `DtoNormalizer::validate()` skipped the items
     * altogether — and a map declared `array<string, string>`, which forbade the very null the
     * document had just allowed. Only a single named type survives; a genuine 3.1 union of two types
     * is a claim no one item type can carry, and it is left alone for the caller to fall through on.
     */
    private function soleTypeBesidesNull(mixed $type): mixed
    {
        if (!is_array($type)) {
            return $type;
        }

        $named = array_values(array_filter($type, static fn(mixed $t): bool => $t !== 'null'));

        return count($named) === 1 ? $named[0] : $type;
    }

    /**
     * Whether a union's branches already have one that accepts null, so it is not added twice.
     *
     * @param array<int, array<string, mixed>|bool> $branches
     */
    private function unionBranchesAcceptNull(array $branches): bool
    {
        foreach ($branches as $branch) {
            // The always-true branch accepts null like everything else; the never-true one accepts nothing.
            if ($branch === true) {
                return true;
            }
            if ($branch === false) {
                continue;
            }
            $type = $branch['type'] ?? null;
            if ($type === 'null' || (is_array($type) && in_array('null', $type, true))) {
                return true;
            }
            // A branch carrying `nullable` — an inline one, or a `$ref` to a component that admits
            // null — lets the null through itself; a `{type: null}` beside it matched it twice.
            if (($branch['nullable'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports a schema whose CLASS NAME is one the emitted code already uses for a library class.
     *
     * The generator carries a PHP type as a short name — `UploadedFile` is what `format: binary`
     * resolves to, `DateTimeImmutable` what `format: date-time` does — so a component schema of that
     * name is, from there on, indistinguishable from the library class. What the emitted file then does
     * depends on where the name lands, and none of it is what the document asked for:
     *
     *     the file DECLARING it     `use X;` beside `class X` is a fatal — the file never loads
     *     any SIBLING file          the import wins over the same-namespace class, so the property is
     *                               typed with the library's class and its payload is a TypeError
     *     a resolved TYPE           `format: binary` and a `$ref` to a schema named UploadedFile
     *                               produce the same string, so the property is treated as an upload
     *
     * Only the first two are fixable from the import list, and laravel-data mode does exactly that
     * (`libraryClassRef()`). The third is not: it would take a type representation that keeps a
     * class's namespace all the way through, which this generator does not have. So the collision is
     * named at BUILD time instead of surfacing as a fatal or a wrong type at request time — the same
     * bargain as `warnAboutUnhydratableUnionProperties()`.
     */
    private function warnAboutClassNamesTheEmittedCodeAlsoUses(): void
    {
        // Looked up case-insensitively, as PHP resolves class names: the schema `stdClass` is generated
        // as class `StdClass`, which a case-sensitive lookup never matched against `use stdClass;`.
        $reserved = array_change_key_case($this->classNamesTheEmittedCodeAlsoUses());

        foreach ([...array_keys($this->dtoSchemas), ...array_keys($this->enumSchemas)] as $generatedClass) {
            $usedFor = $reserved[strtolower($generatedClass)] ?? null;
            if ($usedFor === null) {
                continue;
            }

            $warning = sprintf(
                'Schema "%s" is generated as class %s, which is also %s in %s mode. The emitted code '
                    . 'cannot tell the two apart — rename the schema, or move it to a namespace of its '
                    . 'own with --ref-namespace.',
                $generatedClass,
                $generatedClass,
                $usedFor,
                $this->attributeMode,
            );

            if (!in_array($warning, $this->generationWarnings, true)) {
                $this->generationWarnings[] = $warning;
            }
        }
    }

    /**
     * The short names {@see warnAboutClassNamesTheEmittedCodeAlsoUses()} knows in advance, with what the
     * emitted code uses each for. What a mode's file imports beyond these is found in the rendered files
     * themselves, by {@see warnAboutImportsShadowingGeneratedClasses()}.
     *
     * @return array<string, string>
     */
    private function classNamesTheEmittedCodeAlsoUses(): array
    {
        // Keyed by the short name a document must not take, valued by what the emitted code uses it for.
        // Two are shared by every mode because they are what a schema KEYWORD resolves to; the rest are
        // the classes a mode's own emitted file imports.
        $reserved = [
            'DateTimeImmutable' => 'the type `format: date-time` and `format: date` resolve to',
            'UploadedFile' => 'the type `format: binary` resolves to',
        ];

        $byMode = [
            // runtime resolves its own imports against the document (`libraryClassRef()`), so a
            // schema named `GeneratedDtoInterface`, `JsonException`, `Stringable`, `Closure` or
            // `RuntimeException` is spelled `\Foo` in the emitted file and needs no warning.
            //
            // `UnsetValue` is the exception, and not because of the emitter: DtoDeserializer
            // recognises the sentinel by SHORT name (`str_ends_with($typeName, '\UnsetValue')`),
            // deliberately, so that a sentinel copied into the user's own namespace by
            // `--dto-generator-namespace` is still recognised. A document schema of that name falls
            // under the same test and is skipped instead of hydrated, whatever the emitted file says.
            self::ATTRIBUTE_MODE_RUNTIME => [
                'UnsetValue' => 'the sentinel every optional property defaults to, which the '
                    . 'deserializer recognises by short name',
            ],
            // Symfony writes every library class out in full, and the rendered file imports it only under a
            // name no class of the document owns — the `Assert` alias included, whose constraints are then
            // imported one by one. So a schema named `Context` or `Assert` loads, as in runtime mode.
            self::ATTRIBUTE_MODE_SYMFONY => [],
            // Laravel names every library class in full as well — `Rule`, `stdClass`, the `Validator`
            // withValidator() takes, the `FormRequest` base — and imports each only when the document
            // owns no class of that name, so such a schema loads.
            self::ATTRIBUTE_MODE_LARAVEL => [],
            // laravel-data resolves its own imports against the document (`libraryClassRef()`),
            // so only the two shared type names are left to warn about there.
            self::ATTRIBUTE_MODE_LARAVEL_DATA => [],
            // yii3 resolves its own imports against the document too, since 2.15.2: every framework
            // short name its renderer writes goes through `yii3Lib()`, and `yii3SortedImports()` drops
            // the import the document's class would collide with. Both ask `namespaceDeclaresClass()`,
            // so the body and the import list cannot disagree.
            //
            // `DateTimeImmutable` is not in reach of that and stays in the shared list above: the type
            // a `format: date-time` resolves to is produced by the mode-neutral type mapper, which does
            // not know the namespace, so a schema of that name silently TYPES the property as its own
            // class. Runtime mode has the same gap for the same reason.
            self::ATTRIBUTE_MODE_YII3 => [],
        ];

        return [...$reserved, ...$byMode[$this->attributeMode] ?? []];
    }

    /**
     * Reports a generated class whose short name a generated file imports from somewhere else.
     *
     * The list in {@see classNamesTheEmittedCodeAlsoUses()} is kept by hand, and it fell behind what
     * the modes actually import — `Context` for a date-time in Symfony, `JsonSerializable` in Laravel,
     * `stdClass` and `Rule` in laravel-data and yii3. Each of those was a file that fatals on load
     * behind a run that reported success. Read from the rendered files, the check cannot fall behind:
     * an import whose short name is a class generated into the same namespace is either the class's own
     * file, a fatal, or a sibling whose type silently becomes the library's class.
     *
     * @param array<string, string> $files absolute path => contents, as about to be published
     */
    private function warnAboutImportsShadowingGeneratedClasses(array $files): void
    {
        $generated = [];
        foreach (array_keys($this->dtoSchemas) as $className) {
            $generated[strtolower(trim(($this->schemaNamespaces[$className] ?? $this->baseNamespace) . '\\' . $className, '\\'))] = $className;
        }
        foreach (array_keys($this->enumSchemas) as $enumName) {
            $generated[strtolower(trim(($this->enumNamespaces[$enumName] ?? $this->baseNamespace) . '\\' . $enumName, '\\'))] = $enumName;
        }
        $alreadyListed = array_change_key_case($this->classNamesTheEmittedCodeAlsoUses());

        $reported = [];
        foreach ($files as $path => $contents) {
            if (preg_match('/^namespace ([^;\s]+);$/m', $contents, $namespaceMatch) !== 1) {
                continue;
            }
            preg_match_all('/^use ([A-Za-z0-9_\\\]+)(?: as ([A-Za-z0-9_]+))?;$/m', $contents, $imports, PREG_SET_ORDER);
            foreach ($imports as $import) {
                $imported = ltrim($import[1], '\\');
                $alias = ($import[2] ?? '') !== '' ? $import[2] : substr((string)strrchr('\\' . $imported, '\\'), 1);
                $shadowed = strtolower($namespaceMatch[1] . '\\' . $alias);
                $className = $generated[$shadowed] ?? null;
                if (
                    $className === null
                    || strtolower($imported) === $shadowed
                    || array_key_exists(strtolower($className), $alreadyListed)
                    || array_key_exists($shadowed, $reported)
                ) {
                    continue;
                }
                $reported[$shadowed] = true;

                $this->generationWarnings[] = sprintf(
                    'Schema "%s" is generated as class %s, which %s also imports as %s in %s mode. The '
                        . 'emitted code cannot tell the two apart — rename the schema, or move it to a '
                        . 'namespace of its own with --ref-namespace.',
                    $this->componentNames[$className] ?? $className,
                    $className,
                    basename($path),
                    $imported,
                    $this->attributeMode,
                );
            }
        }
    }

    /**
     * Reports a request body that is an undiscriminated union with no entry point in this mode. Each mode
     * reaches such a body through something the union interface names or comes with — runtime its
     * `OPENAPI_UNION_CONSTRAINTS`, laravel `<Union>FormRequest`, laravel-data `<Union>Resolver`
     * (`laravelUnionBodyMembers()`), symfony and yii3 the `OPENAPI_UNION_MEMBERS` an application tries
     * the payload against itself — and none has it when a member is not a plain class (`oneOf: [$ref
     * Circle, $ref Pet]`, `Pet` itself a discriminated union): the interface is all there is, and nothing
     * validates or builds the payload.
     *
     * Symfony is told even with the member list: the serializer cannot build an interface, so under
     * `#[MapRequestPayload]` every payload is a 500 until the application tries the members itself.
     */
    private function warnAboutUnionBodyWithoutEntryPoint(string $className): void
    {
        if (!array_key_exists($className, $this->requestPayloadClasses) || !$this->rendersAsUnionInterface($className)) {
            return;
        }

        $hasMemberList = $this->undiscriminatedUnionMemberList($className, $this->unionInterfaceMembers($className)) !== null;
        $missing = match ($this->attributeMode) {
            self::ATTRIBUTE_MODE_LARAVEL => $this->laravelFormRequest && $this->laravelUnionBodyMembers($className) === null
                ? $className . 'FormRequest'
                : null,
            self::ATTRIBUTE_MODE_LARAVEL_DATA => $this->laravelUnionBodyMembers($className) === null ? $className . 'Resolver' : null,
            self::ATTRIBUTE_MODE_RUNTIME => $hasMemberList ? null : 'OPENAPI_UNION_CONSTRAINTS',
            default => $hasMemberList ? null : 'OPENAPI_UNION_MEMBERS',
        };

        $warning = null;
        if ($missing !== null) {
            $warning = sprintf(
                'The request body %s is an undiscriminated union whose members are not all plain classes: %s mode '
                    . 'generates no entry point for it (no %s), so nothing validates or builds the payload. '
                    . 'Add a discriminator, or make every member an object schema.',
                $className,
                $this->attributeMode,
                $missing,
            );
        } elseif ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
            $warning = sprintf(
                'The request body %s is an undiscriminated union: the Symfony serializer cannot build the '
                    . 'interface, so #[MapRequestPayload] answers every payload with a 500. Try the members in '
                    . '%s::OPENAPI_UNION_MEMBERS yourself (README.symfony.md), or add a discriminator.',
                $className,
                $className,
            );
        }
        if ($warning !== null && !in_array($warning, $this->generationWarnings, true)) {
            $this->generationWarnings[] = $warning;
        }
    }

    /**
     * Reports a request body that is a union of a class and a value — `oneOf: [$ref Circle, {type:
     * string}]`, written in the body or as a component it `$ref`s. Such a union is a value alias: no
     * class stands for it in any mode, so there is no FormRequest, resolver, input class or DTO to
     * type-hint, and generation used to report success with only `Circle` written. A union of classes
     * gets an interface instead (`warnAboutUnionBodyWithoutEntryPoint()`), and a plain value body — a
     * string, a list — is not a union of a class and a value.
     */
    private function warnAboutValueUnionBodies(): void
    {
        $prefix = '#/components/schemas/';
        foreach ($this->valueRequestBodies as $body) {
            $schema = $body['schema'];
            $component = null;
            $bodyRef = $schema['$ref'] ?? null;
            if (is_string($bodyRef)) {
                if (!str_starts_with($bodyRef, $prefix)) {
                    continue;
                }
                $component = $this->schemaClassName(substr($bodyRef, strlen($prefix)));
                $schema = $this->dtoSchemas[$component] ?? null;
                if (!is_array($schema) || !$this->isScalarAliasSchema($schema)) {
                    continue;
                }
            }
            $members = $this->valueUnionBodyMembers($schema);
            if ($members === null) {
                continue;
            }

            $entryPoint = match ($this->attributeMode) {
                self::ATTRIBUTE_MODE_LARAVEL => $this->laravelFormRequest ? 'FormRequest' : null,
                self::ATTRIBUTE_MODE_LARAVEL_DATA => 'Data class or resolver',
                self::ATTRIBUTE_MODE_SYMFONY => 'payload class',
                self::ATTRIBUTE_MODE_YII3 => 'input class',
                default => 'DTO',
            };
            if ($entryPoint === null) {
                continue;
            }
            $warning = sprintf(
                'The request body of %s%s is a union of a class and a value (%s): %s mode generates no %s for it, '
                    . 'so nothing validates or builds the payload%s. Make the body an object schema, or wrap the '
                    . 'value in a property.',
                $body['operation'],
                $component === null ? '' : ' (' . $component . ')',
                implode('|', $members),
                $this->attributeMode,
                $entryPoint,
                $this->attributeMode === self::ATTRIBUTE_MODE_RUNTIME
                    ? ' — DtoDeserializer::deserializeValue() over the decoded body with the PHP union type checks the member types only'
                    : '',
            );
            if (!in_array($warning, $this->generationWarnings, true)) {
                $this->generationWarnings[] = $warning;
            }
        }
    }

    /**
     * The members of a `oneOf` / `anyOf` body that holds a class beside a value — the class names and the
     * value types, in document order — or null for any other body.
     *
     * @param array<mixed> $schema
     * @return list<string>|null
     */
    private function valueUnionBodyMembers(array $schema): ?array
    {
        $keywords = array_values(array_intersect(['oneOf', 'anyOf'], array_keys($schema)));
        $branches = count($keywords) === 1 ? $schema[$keywords[0]] : null;
        if (!is_array($branches) || $branches === []) {
            return null;
        }
        $prefix = '#/components/schemas/';
        $members = [];
        $classes = 0;
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return null;
            }
            $ref = $branch['$ref'] ?? null;
            if (is_string($ref) && str_starts_with($ref, $prefix)) {
                $class = $this->schemaClassName(substr($ref, strlen($prefix)));
                $target = $this->dtoSchemas[$class] ?? null;
                if (is_array($target) && !$this->isScalarAliasSchema($target) && !$this->isArrayAliasComponent($target)) {
                    $classes++;
                }
                $members[] = $class;
                continue;
            }
            $type = $branch['type'] ?? null;
            if ($type === 'object' && is_array($branch['properties'] ?? null) && $branch['properties'] !== []) {
                $classes++;
                $members[] = 'object';
                continue;
            }
            $members[] = is_string($type) ? $type : (is_array($type) ? implode('|', array_map(strval(...), $type)) : 'value');
        }

        return $classes > 0 && $classes < count($branches) ? $members : null;
    }

    /**
     * Reports a property whose schema is a union of OBJECTS with no `discriminator`.
     *
     * Such a union is emitted as an interface its members implement, and the framework modes cannot turn a
     * payload back into one of them: the document does not say which member a given object is, and their
     * hydrators build a DECLARED type, which an interface is not. Each of them fails on a payload the
     * document allows, differently and late — measured:
     *
     *     symfony       NotNormalizableValueException: … must be one of "Shape" ("array" given)
     *     laravel       Error: Call to undefined method Shape::fromValidated()
     *     laravel-data  TypeError: Argument #1 ($shape) must be of type Shape, array given
     *
     * Runtime mode is not warned: the owner's `getConstraints()` names the members as branches, and
     * `DtoDeserializer` builds the payload into the member it matches, as it does for an inline
     * `Olive|Teal` — the first under `anyOf`, the only one under `oneOf`. It used to refuse every payload
     * with `Unsupported type: Shape`. Nor is symfony mode since 2.15.155: it holds such a property, list
     * item or map value as the decoded object, checked against the members by the callback
     * (`RendersSymfonyDto::symfonyWithUndiscriminatedUnionsHeldDecoded()`).
     *
     * A 500 at request time for a shape the generator could see at build time is the actual defect, so it
     * is named here instead. Generation still succeeds: the interface and its members are useful as types,
     * and a document may reference the union only in a response, which never gets hydrated.
     *
     * The remedy is in the document — add a `discriminator` — and then every mode resolves it: runtime and
     * laravel switch on the mapping in generated hydration, Symfony uses the serializer's discriminator
     * map, and laravel-data gets an abstract `Data` base with `morph()`.
     *
     * @param array<int, SchemaProperty> $properties
     */
    private function warnAboutUnhydratableUnionProperties(string $className, array $properties): void
    {
        if (in_array($this->attributeMode, [self::ATTRIBUTE_MODE_RUNTIME, self::ATTRIBUTE_MODE_SYMFONY], true)) {
            return;
        }
        foreach ($properties as $property) {
            $target = $this->undiscriminatedUnionTypeOf($property);
            if ($target === null) {
                continue;
            }

            $warning = sprintf(
                'Property "%s" of %s refers to %s, an undiscriminated %s union: this mode cannot hydrate a '
                    . 'payload into it (runtime mode can). Add a discriminator to %s, or type the property as one member.',
                $property['openApiName'],
                $className,
                $target['class'],
                $target['keyword'],
                $target['class'],
            );

            if (!in_array($warning, $this->generationWarnings, true)) {
                $this->generationWarnings[] = $warning;
            }
        }
    }

    /**
     * The union base a property (or its array items) resolves to, when that base has no discriminator.
     *
     * @param SchemaProperty $property
     * @return array{class: string, keyword: string}|null
     */
    private function undiscriminatedUnionTypeOf(array $property): ?array
    {
        // The Laravel modes build a PROPERTY typed as such a component into the member it matches, when
        // every member is a class — a `$ref`, an inline or `allOf` object — or a union of them (`laravelUnionComponentMembers()`). Laravel builds a list item or map value of it the same way;
        // laravel-data holds those items as arrays, so they stay warned about there.
        $builtAsMember = in_array($this->attributeMode, [self::ATTRIBUTE_MODE_LARAVEL, self::ATTRIBUTE_MODE_LARAVEL_DATA], true)
            && $this->laravelUndiscriminatedUnionClasses($property) !== [];
        $itemClass = $this->laravelDtoItemClass($property);
        $itemBuiltAsMember = $itemClass !== null
            && $this->attributeMode === self::ATTRIBUTE_MODE_LARAVEL
            && $this->laravelUnionComponentMembers($itemClass) !== [];
        foreach ([$builtAsMember ? null : $this->laravelDtoClass($property), $itemBuiltAsMember ? null : $itemClass] as $candidate) {
            if ($candidate === null || array_key_exists($candidate, $this->discriminatorSchemas)) {
                continue;
            }

            $schema = $this->dtoSchemas[$candidate] ?? null;
            if (!is_array($schema) || !$this->rendersAsUnion($schema)) {
                continue;
            }

            foreach (['oneOf', 'anyOf'] as $keyword) {
                if (array_key_exists($keyword, $schema) && $this->collectsObjectUnionMembers($schema[$keyword], $candidate)) {
                    return ['class' => $candidate, 'keyword' => $keyword];
                }
            }
        }

        return null;
    }

    /**
     * Whether a generated class is a union interface NOTHING can hydrate a payload into: a `oneOf`/`anyOf`
     * over object members with no `discriminator` to choose between them.
     *
     * The same condition {@see warnAboutUnhydratableUnionProperties()} reports at generation time, asked
     * about the class rather than about a property, so an emitter can decline to write a call that cannot
     * work. {@see RendersLaravelDto::laravelNestedDtoExpression()} is the one that used to write it.
     */
    private function isUnhydratableUnionClass(string $class): bool
    {
        if (array_key_exists($class, $this->discriminatorSchemas)) {
            return false;
        }

        $schema = $this->dtoSchemas[$class] ?? null;
        if (!is_array($schema) || !$this->rendersAsUnion($schema)) {
            return false;
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (array_key_exists($keyword, $schema) && $this->collectsObjectUnionMembers($schema[$keyword], $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a union has referenced, inline or nested object members that become an interface.
     * A union of SCALARS becomes a PHP union type (`int|string`) instead, which hydrates fine.
     *
     * `$ownerClassName` is the union's class, which an `allOf` member (and a `$ref` with siblings,
     * written as one) resolves its references against — as `collectUnionTypes()` does.
     */
    private function collectsObjectUnionMembers(mixed $variants, string $ownerClassName): bool
    {
        if (!is_array($variants)) {
            return false;
        }

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            // Inline objects, `allOf` objects and nested unions are materialized by collectUnionTypes()
            // too. They produce the same interface as referenced members, so hydration needs the same
            // treatment instead of calling a factory that interface does not have. An `allOf` member
            // was not counted: symfony typed the property as the bare interface and refused every
            // object, laravel called the interface's missing `fromValidated()`.
            if (
                $this->isObjectUnionMemberOfItsOwn($variant, $ownerClassName)
                || $this->collectsObjectUnionMembers($variant['oneOf'] ?? null, $ownerClassName)
                || $this->collectsObjectUnionMembers($variant['anyOf'] ?? null, $ownerClassName)
            ) {
                return true;
            }

            if (!is_string($variant['$ref'] ?? null)) {
                continue;
            }

            $memberClass = $this->schemaRefToClassName($variant['$ref']);
            if (array_key_exists($memberClass, $this->dtoSchemas)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getGenerationWarnings(): array
    {
        return $this->generationWarnings;
    }

    /**
     * A Path Item written as a reference, resolved into the item it points at.
     *
     * The Path Item Object carries a `$ref` field of its own, and 3.1 adds `components.pathItems` as
     * the place such a reference usually points. Neither was read: `/viaref: {$ref: …}` produced no
     * classes and no message, so a document that factors a shared path out into a component simply
     * had part of itself ignored.
     *
     * Two pointer shapes, both local:
     *   `#/components/pathItems/Name` — the 3.1 home for shared path items;
     *   `#/paths/~1a~1{id}`          — a pointer at another path, escaped per RFC 6901.
     *
     * Keys the referencing item declares ITSELF win over the ones it inherits. The specification
     * calls that case undefined for 3.0 and asks for siblings to be ignored in 3.1; overlaying is the
     * reading that cannot silently lose an operation someone wrote down.
     *
     * An unresolvable pointer stops generation, for the same reason a broken schema `$ref` has since
     * 2.15.30: the alternative is emitting a document whose parts are missing without saying so.
     *
     * @param array<mixed> $pathItem
     * @param array<mixed> $openApi
     * @return array<mixed>
     */
    private function resolvePathItemRef(array $pathItem, string $key, array $openApi): array
    {
        $ref = $pathItem['$ref'] ?? null;
        if (!is_string($ref)) {
            return $pathItem;
        }

        // An external pointer is VALID OpenAPI that this generator does not implement, which is a
        // different thing from a pointer that leads nowhere. Saying so plainly beats one message for
        // two situations a reader would fix differently.
        if (!str_starts_with($ref, '#/')) {
            throw new RuntimeException(sprintf(
                'Path item reference "%s" on "%s" points outside this document. External path item '
                . 'references are not supported; move the path item into #/components/pathItems, or '
                . 'inline it.',
                $ref,
                $key,
            ));
        }

        $target = $this->resolveLocalPathItemPointer($ref, $openApi);
        if ($target === null) {
            throw new RuntimeException(sprintf(
                'Unresolvable path item reference "%s" on "%s": nothing at that pointer. Supported '
                . 'targets are #/components/pathItems/<name> and #/paths/<escaped path>.',
                $ref,
                $key,
            ));
        }

        unset($pathItem['$ref']);

        return $pathItem + $target;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<mixed>|null
     */
    private function resolveLocalPathItemPointer(string $ref, array $openApi): ?array
    {
        foreach (['#/components/pathItems/' => ['components', 'pathItems'], '#/paths/' => ['paths']] as $prefix => $segments) {
            if (!str_starts_with($ref, $prefix)) {
                continue;
            }

            // RFC 6901: `~1` is a slash and `~0` a tilde, in that order — the reverse un-escapes
            // `~01` into `~1` instead of leaving it alone.
            $name = str_replace(['~1', '~0'], ['/', '~'], substr($ref, strlen($prefix)));

            $node = $openApi;
            foreach ([...$segments, $name] as $segment) {
                $node = is_array($node) ? ($node[$segment] ?? null) : null;
            }

            return is_array($node) ? $node : null;
        }

        return null;
    }

    /**
     * Path Item Objects to walk: `paths` plus, since OAS 3.1, `webhooks`. A webhook entry is an
     * incoming request our own endpoint receives, so its body/parameters deserve DTOs exactly like
     * a path operation's. The key is a name, not a URL — it is used verbatim for class naming.
     *
     * @param array<mixed> $openApi
     * @return array<string, array<mixed>>
     */
    private function collectPathItems(array $openApi): array
    {
        $items = [];

        foreach (['paths', 'webhooks'] as $section) {
            $group = $openApi[$section] ?? [];
            $this->assertDocumentNodeIsAnObject($group, $section);
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $key => $pathItem) {
                if (!is_string($key) || !is_array($pathItem)) {
                    continue;
                }

                $pathItem = $this->resolvePathItemRef($pathItem, $key, $openApi);
                // A webhook named like an existing path would otherwise overwrite it.
                $items[$section === 'webhooks' ? 'webhook:' . $key : $key] = $pathItem;
            }
        }

        foreach ($items as $key => $pathItem) {
            $items[$key] = $this->foldAdditionalOperations($pathItem);
        }

        foreach ($items as $pathItem) {
            foreach ($this->collectCallbackPathItems($pathItem, $openApi) as $callbackKey => $callbackPathItem) {
                // Two operations may declare the same callback name with different payloads.
                $uniqueKey = $callbackKey;
                $counter = 2;
                while (array_key_exists($uniqueKey, $items)) {
                    $uniqueKey = $callbackKey . $counter;
                    $counter++;
                }
                $items[$uniqueKey] = $callbackPathItem;
            }
        }

        return $items;
    }

    /**
     * OAS 3.2 `additionalOperations` holds operations for methods that have no fixed field (QUERY
     * and friends). They are operations like any other, so they are folded into the Path Item under
     * their method name — lowercased, since every walker matches methods case-insensitively.
     *
     * @param array<mixed> $pathItem
     * @return array<mixed>
     */
    private function foldAdditionalOperations(array $pathItem): array
    {
        $additional = $pathItem['additionalOperations'] ?? null;
        unset($pathItem['additionalOperations']);

        if (!is_array($additional)) {
            return $pathItem;
        }

        foreach ($additional as $method => $operation) {
            if (!is_string($method) || $method === '' || !is_array($operation)) {
                continue;
            }
            // The spec forbids re-declaring a fixed-field method here; if one shows up anyway the
            // fixed field wins rather than being silently overwritten.
            $normalizedMethod = strtolower($method);
            if (!array_key_exists($normalizedMethod, $pathItem)) {
                $pathItem[$normalizedMethod] = $operation;
            }
        }

        return $pathItem;
    }

    /**
     * Path Item Objects nested under an operation's `callbacks`. The map key there is a runtime
     * expression (`{$request.body#/callbackUrl}`), useless for naming, so the callback name is used
     * instead — the payload is what matters here, not the URL it will be delivered to.
     *
     * A callback may be written as a Reference Object — `{$ref: '#/components/callbacks/Shared'}` —
     * and that spelling produced nothing at all: the payload class was never emitted, while the same
     * callback written inline was. Support existed for one half of a two-way choice, silently.
     *
     * @param array<mixed> $pathItem
     * @param array<mixed> $openApi
     * @return array<string, array<mixed>>
     */
    private function collectCallbackPathItems(array $pathItem, array $openApi): array
    {
        $collected = [];

        foreach ($pathItem as $method => $operation) {
            if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                continue;
            }

            $callbacks = $operation['callbacks'] ?? null;
            if (!is_array($callbacks)) {
                continue;
            }

            foreach ($callbacks as $callbackName => $callback) {
                if (!is_string($callbackName)) {
                    continue;
                }

                // Passes an inline callback through untouched and follows a reference (including a
                // short chain of them) into `components.callbacks`.
                $callback = $this->resolveComponentRef($callback, 'callbacks', $openApi);
                if (!is_array($callback)) {
                    continue;
                }

                foreach ($callback as $expression => $callbackPathItem) {
                    if (!is_string($expression) || !is_array($callbackPathItem)) {
                        continue;
                    }
                    $collected['callback:' . $callbackName] = $callbackPathItem;
                }
            }
        }

        return $collected;
    }

    /**
     * Naming input for a Path Item key: a `webhook:`/`callback:` marker becomes a path segment so
     * the class name reads `WebhookNewPetPostRequest` / `CallbackOnDataPostRequest` instead of
     * colliding with a same-named path.
     */
    private function pathItemNamingKey(string $key): string
    {
        foreach (['webhook:', 'callback:'] as $marker) {
            if (str_starts_with($key, $marker)) {
                return rtrim($marker, ':') . '/' . substr($key, strlen($marker));
            }
        }

        return $key;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractInlineResponseSchemas(array $openApi): array
    {
        $paths = $this->collectPathItems($openApi);

        $inlineSchemas = [];
        $inlineOwners = [];

        foreach ($paths as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $responses = $operation['responses'] ?? [];
                if (!is_array($responses)) {
                    continue;
                }

                foreach ($responses as $statusCode => $response) {
                    $response = $this->resolveComponentRef($response, 'responses', $openApi);
                    if (!is_array($response)) {
                        continue;
                    }

                    $content = $response['content'] ?? [];
                    if (!is_array($content)) {
                        continue;
                    }

                    $mediaTypeSuffixes = $this->mediaTypeNameSuffixes($content, $openApi);

                    foreach ($content as $mediaType => $mediaTypeObject) {
                        $mediaTypeObject = $this->resolveComponentRef($mediaTypeObject, 'mediaTypes', $openApi);
                        if (!is_array($mediaTypeObject)) {
                            continue;
                        }

                        $schema = $mediaTypeObject['schema'] ?? null;
                        if (!is_array($schema) || array_key_exists('$ref', $schema)) {
                            continue;
                        }

                        if (($schema['type'] ?? null) !== 'object') {
                            continue;
                        }

                        $ownerKey = strtoupper($method) . ' ' . $path;
                        $schemaName = $this->uniqueEndpointSchemaName(
                            path: $this->pathItemNamingKey($path),
                            tail: $this->responseNameTail((string)$statusCode)
                                . (is_string($mediaType) ? ($mediaTypeSuffixes[$mediaType] ?? '') : ''),
                            ownerKey: $ownerKey,
                            owners: $inlineOwners,
                        );
                        $inlineSchemas[$schemaName] = $this->markSchemaDeprecatedByOperation($schema, $operation);
                        $inlineOwners[$schemaName] = $ownerKey;
                        $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
                    }
                }
            }
        }

        return $inlineSchemas;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractInlineRequestSchemas(array $openApi): array
    {
        $paths = $this->collectPathItems($openApi);

        $inlineSchemas = [];
        $inlineOwners = [];
        $this->requestBodyComponents = $this->extractSchemas($openApi);

        foreach ($paths as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                // A body reached through a `requestBodies` component spelled any other way than
                // `#/components/requestBodies/X` — by the document's own file name, or in another
                // file — generated nothing and recorded nothing, so a form it declares was refused
                // by the class a JSON body names. It is now resolved too, but only attributed: the
                // classes it may arrive as are generated from their own declarations.
                $requestBody = $this->resolveComponentRef($operation['requestBody'] ?? null, 'requestBodies', $openApi);
                $bodySourceFile = $this->rootSpecFile;
                $attributionOnly = false;
                if (!is_array($requestBody)) {
                    $reached = $this->resolveComponentAcrossFiles(
                        node: $operation['requestBody'] ?? null,
                        section: 'requestBodies',
                        openApi: $openApi,
                        sourceFile: $this->rootSpecFile,
                    );
                    if ($reached === null) {
                        continue;
                    }
                    [$requestBody, $bodySourceFile] = $reached;
                    $attributionOnly = true;
                }

                $content = $requestBody['content'] ?? [];
                if (!is_array($content)) {
                    continue;
                }

                $mediaTypeSuffixes = $attributionOnly ? [] : $this->mediaTypeNameSuffixes($content, $openApi);

                foreach ($content as $mediaType => $mediaTypeObject) {
                    $schemaSourceFile = $bodySourceFile;
                    $mediaTypeAttributionOnly = false;
                    $resolvedMediaType = $attributionOnly ? null : $this->resolveComponentRef($mediaTypeObject, 'mediaTypes', $openApi);
                    if (!is_array($resolvedMediaType)) {
                        $reached = $this->resolveComponentAcrossFiles(
                            node: $mediaTypeObject,
                            section: 'mediaTypes',
                            openApi: $openApi,
                            sourceFile: $bodySourceFile,
                        );
                        if ($reached === null) {
                            continue;
                        }
                        [$resolvedMediaType, $schemaSourceFile] = $reached;
                        // Reached only here: by a body of another spelling, or by a `mediaTypes`
                        // reference of one — either way, the walk below never saw it.
                        $mediaTypeAttributionOnly = true;
                    }
                    $mediaTypeObject = $resolvedMediaType;

                    $schema = $mediaTypeObject['schema'] ?? null;
                    if (!is_array($schema)) {
                        continue;
                    }

                    if ($mediaTypeAttributionOnly) {
                        // An inline object here would be a class of its own that this walk never
                        // generated, so nothing arrives as it; everything else names classes.
                        if (
                            (is_string($schema['$ref'] ?? null) || ($schema['type'] ?? null) !== 'object')
                            && is_string($mediaType)
                            && $mediaType !== ''
                        ) {
                            $this->referencedRequestBodies[] = ['schema' => $schema, 'mediaType' => $mediaType, 'sourceFile' => $schemaSourceFile];
                        }
                        continue;
                    }

                    // A body that points at a named component declares no NEW class — the component
                    // already has one — but it is still a request payload, and that is what decides
                    // whether the class gets a FormRequest. Only a LOCAL pointer: a component in an
                    // external file belongs to whichever run generates that file.
                    $bodyRef = $schema['$ref'] ?? null;
                    if (is_string($bodyRef)) {
                        $referencedSchema = $this->externalPointerSchemaName($bodyRef);
                        if ($referencedSchema !== null) {
                            $this->requestPayloadClasses[$this->schemaClassName($referencedSchema)] = true;
                        }
                    }

                    // The class such a body arrives as is not the name it spells: an alias component
                    // is redirected to what it names, a single-reference `allOf` gets no class at
                    // all. Recording its media type under the spelled name put a form type on a class
                    // nobody generates and left the real one marked JSON-only — so it waits until
                    // the aliases are known.
                    // An inline union of references is the exception: it names no class, yet it is the
                    // class the body arrives as — an interface of its members, as the same union written
                    // as a component is. Left out, nothing was generated for the operation at all, and
                    // no mode had an entry point for the body. Its media type still goes to the members,
                    // which are what a payload is built as.
                    $isInlineUnion = !is_string($bodyRef) && $this->isInlineUnionOfReferences($schema);
                    if (!$isInlineUnion && (is_string($bodyRef) || ($schema['type'] ?? null) !== 'object')) {
                        $this->valueRequestBodies[] = ['operation' => strtoupper($method) . ' ' . $path, 'schema' => $schema];
                    }
                    if (is_string($bodyRef) || ($schema['type'] ?? null) !== 'object') {
                        if (is_string($mediaType) && $mediaType !== '') {
                            $this->referencedRequestBodies[] = ['schema' => $schema, 'mediaType' => $mediaType, 'sourceFile' => $this->rootSpecFile];
                        }
                        if (!$isInlineUnion) {
                            continue;
                        }
                    }

                    $schema = $this->applyEncodingToBodySchema($schema, $mediaTypeObject['encoding'] ?? null);
                    $schema = $this->markSchemaDeprecatedByOperation($schema, $operation);

                    $ownerKey = strtoupper($method) . ' ' . $path;
                    $schemaName = $this->uniqueEndpointSchemaName(
                        path: $this->pathItemNamingKey($path),
                        tail: ucfirst(strtolower($method)) . 'Request'
                            . (is_string($mediaType) ? ($mediaTypeSuffixes[$mediaType] ?? '') : ''),
                        ownerKey: $ownerKey,
                        owners: $inlineOwners,
                    );
                    $inlineSchemas[$schemaName] = $schema;
                    $inlineOwners[$schemaName] = $ownerKey;
                    $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
                    $this->requestPayloadClasses[$this->schemaClassName($schemaName)] = true;
                    if (!$isInlineUnion) {
                        $this->recordRequestBodyMediaType(className: $this->schemaClassName($schemaName), mediaType: $mediaType);
                    }
                }
            }
        }

        return $inlineSchemas;
    }

    /**
     * An inline `oneOf` / `anyOf` of two or more references with no discriminator, beside nothing but
     * annotations: the shape a component union of classes has, written in the body instead.
     *
     * @param array<array-key, mixed> $schema
     */
    private function isInlineUnionOfReferences(array $schema): bool
    {
        $keywords = array_values(array_intersect(array_keys($schema), ['oneOf', 'anyOf']));
        if (count($keywords) !== 1) {
            return false;
        }
        $branches = $schema[$keywords[0]];
        if (!is_array($branches) || !array_is_list($branches) || count($branches) < 2) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!$this->isAnnotatedReference($branch)) {
                return false;
            }
        }
        foreach (array_keys($schema) as $keyword) {
            if (
                $keyword !== $keywords[0]
                && !in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true)
                && !in_array($keyword, self::NON_ASSERTING_KEYWORDS, true)
                && !str_starts_with((string)$keyword, 'x-')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gives each request body that names no class of its own to the class actually generated for it:
     * a `$ref` followed through alias components (a chain of them, one in another file, one that
     * points back), and a single-reference `allOf` / `oneOf` / `anyOf` unwrapped.
     *
     * A body declared under a form or multipart type that cannot be pinned to ONE class — a union of
     * several, a reference that leads nowhere generated — takes the JSON-only mark off every class it
     * may arrive as, and when even those are unknown, off every class: wrongly refusing a request the
     * document allows breaks valid traffic, while a missing mark only keeps the permissive lookup every
     * class had before the mark existed. A JSON body needs no such care: it adds only a JSON type,
     * which cannot make a class look JSON-only that is not.
     *
     * A body naming a union component arrives as one of its members, never as the abstract base or
     * the interface generated for the union itself, which nothing can deserialize — so the members
     * are what its media type is attributed to.
     *
     * Last, a class generated into a place several documents share — a `--ref` target, the `common`
     * convention — gives the mark up: each document sees only its own operations, so the mark would
     * differ by document, a mask run refuses the second one for writing the shared file differently,
     * and with one run per document the last one decides what every other accepts. Unmarked, the
     * class keeps the permissive lookup it had before the mark existed, whichever document writes it.
     */
    private function attributeReferencedRequestBodies(): void
    {
        $uncertain = [];
        foreach ($this->referencedRequestBodies as $body) {
            $mediaType = $body['mediaType'];
            $wrapped = $this->singleReferenceOfBody($body['schema']);
            $classNames = $wrapped === null
                ? null
                : $this->requestBodyArrivalClasses(ref: $wrapped, sourceFile: $body['sourceFile']);
            if ($classNames !== null) {
                foreach ($classNames as $className) {
                    $this->recordRequestBodyMediaType(className: $className, mediaType: $mediaType);
                }
                continue;
            }
            if ($this->isJsonMediaTypeName($mediaType)) {
                continue;
            }
            foreach ($this->composedReferencesOfBody($body['schema']) as $reference) {
                $candidates = $this->requestBodyArrivalClasses(ref: $reference, sourceFile: $body['sourceFile']);
                if ($candidates === null) {
                    $this->requestBodyMediaTypes = [];

                    return;
                }
                foreach ($candidates as $candidate) {
                    $uncertain[$candidate] = true;
                }
            }
        }

        $attributed = [];
        foreach ($this->requestBodyMediaTypes as $className => $mediaTypes) {
            if (!array_key_exists($className, $uncertain) && !$this->isGeneratedIntoASharedPlace($className)) {
                $attributed[$className] = $mediaTypes;
            }
        }
        $this->requestBodyMediaTypes = $attributed;
    }

    /**
     * Whether the class is written where other documents write it too: a component of another file
     * that a `--ref` maps, or that the `common` convention places beside the document's own
     * directory. A component of another file without either lands below the document's own
     * directory, which is its own.
     */
    private function isGeneratedIntoASharedPlace(string $className): bool
    {
        $sourceFile = $this->schemaSourceFiles[$className] ?? null;
        if ($sourceFile === null || $sourceFile === $this->rootSpecFile) {
            return false;
        }

        return $this->matchRefMapping(sourceFile: $sourceFile, map: $this->refOutputDirectoryMap, separator: '/') !== null
            || $this->resolveCommonOutputDirectoryForSourceFile($sourceFile) !== null;
    }

    /**
     * Every generated class a body `$ref` may arrive as: the class it names, and when that class is a
     * union (`oneOf` / `anyOf` with no properties of its own), each class its members name, at any
     * depth. Null when any of them cannot be found.
     *
     * @return list<string>|null
     */
    private function requestBodyArrivalClasses(string $ref, ?string $sourceFile): ?array
    {
        $classNames = [];
        $pending = [[$ref, $sourceFile]];
        while ($pending !== []) {
            [$nextRef, $nextSourceFile] = array_shift($pending);
            $target = $this->requestBodyTarget(ref: $nextRef, sourceFile: $nextSourceFile);
            if ($target === null) {
                return null;
            }
            if (in_array($target['className'], $classNames, true)) {
                continue;
            }
            $classNames[] = $target['className'];

            $definition = $target['definition'];
            if (array_key_exists('properties', $definition) || array_key_exists('allOf', $definition)) {
                continue;
            }
            foreach (['oneOf', 'anyOf'] as $keyword) {
                $members = $definition[$keyword] ?? null;
                if (!is_array($members)) {
                    continue;
                }
                foreach ($members as $member) {
                    $memberRef = is_array($member) ? ($member['$ref'] ?? null) : null;
                    if (is_string($memberRef)) {
                        $pending[] = [$memberRef, $target['sourceFile']];
                    }
                }
            }
        }

        return $classNames;
    }

    /**
     * The one `$ref` a body consists of: plain (whatever stands beside it, the class is still the one
     * it names), or wrapped in a single-member `allOf` / `oneOf` / `anyOf` with nothing but annotations
     * beside the wrapper. Null for any other shape.
     *
     * @param array<mixed> $schema
     */
    private function singleReferenceOfBody(array $schema): ?string
    {
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            return $ref;
        }

        $wrapper = null;
        foreach (array_keys($schema) as $keyword) {
            if (in_array($keyword, ['allOf', 'oneOf', 'anyOf'], true) && $wrapper === null) {
                $wrapper = $keyword;
                continue;
            }
            if (!in_array($keyword, self::ALIAS_ANNOTATION_KEYWORDS, true) && !str_starts_with((string)$keyword, 'x-')) {
                return null;
            }
        }
        $members = $wrapper === null ? null : $schema[$wrapper];
        if (!is_array($members) || count($members) !== 1 || !is_array($members[0] ?? null)) {
            return null;
        }

        return $this->singleReferenceOfBody($members[0]);
    }

    /**
     * Every `$ref` a body may arrive as: its own, and those of its `allOf` / `oneOf` / `anyOf` members
     * at any depth. A reference under a property names a nested value, not the body's class.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function composedReferencesOfBody(array $schema): array
    {
        $references = [];
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $references[] = $ref;
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (!is_array($members)) {
                continue;
            }
            foreach ($members as $member) {
                if (is_array($member)) {
                    $references = [...$references, ...$this->composedReferencesOfBody($member)];
                }
            }
        }

        return $references;
    }

    /**
     * The generated class a body `$ref` arrives as, followed through every alias that gets no class
     * of its own: a local one (`redirectPureAliasReferences()`) and one that only points into another
     * file — with the definition it was generated from and the file that declares it, against which
     * that definition's own `$ref`s resolve. `$sourceFile` is the document the `$ref` is written in.
     * Null when the chain ends anywhere but at a registered component.
     *
     * @return array{className: string, definition: array<mixed>, sourceFile: string|null}|null
     */
    private function requestBodyTarget(string $ref, ?string $sourceFile): ?array
    {
        $prefix = '#/components/schemas/';
        // Bounded: a chain of aliases that loops back on itself names no class.
        for ($hop = 0; $hop < 64; ++$hop) {
            $ref = $this->stripDocumentSelfPrefix($ref);
            if (!str_starts_with($ref, $prefix)) {
                $resolved = $this->resolveExternalSchemaPointer(ref: $ref, currentSourceFile: $sourceFile);
                if ($resolved === null) {
                    return null;
                }
                [$sourceFile, $ref] = $resolved;
            }
            $schemaName = $this->externalPointerSchemaName($ref);
            if ($schemaName === null) {
                return null;
            }
            $definition = $sourceFile === null || $sourceFile === $this->rootSpecFile
                ? ($this->requestBodyComponents[$schemaName] ?? null)
                : ($this->externalSchemasOf($sourceFile)[$schemaName] ?? null);
            if (!is_array($definition)) {
                return null;
            }

            $className = $this->schemaClassName($schemaName);
            $next = null;
            if ($this->isPureExternalSchemaAlias($definition)) {
                $next = $definition['$ref'];
            } elseif (array_key_exists($className, $this->pureAliasClasses)) {
                $next = $this->pureAliasTarget($definition);
            }
            if (!is_string($next)) {
                return array_key_exists($className, $this->dtoSchemas) || array_key_exists($className, $this->enumSchemas)
                    ? ['className' => $className, 'definition' => $definition, 'sourceFile' => $sourceFile]
                    : null;
            }
            $ref = $next;
        }

        return null;
    }

    private function recordRequestBodyMediaType(string $className, mixed $mediaType): void
    {
        if (!is_string($mediaType) || $mediaType === '') {
            return;
        }

        $known = $this->requestBodyMediaTypes[$className] ?? [];
        if (!in_array($mediaType, $known, true)) {
            $known[] = $mediaType;
        }
        $this->requestBodyMediaTypes[$className] = $known;
    }

    private function isHttpMethod(string $method): bool
    {
        // QUERY (and other custom methods) reach a Path Item through OAS 3.2 additionalOperations,
        // which is folded in before the walkers run — so accept any method token, not just the
        // eight with a fixed field. Non-method keys of a Path Item are excluded explicitly.
        if (in_array($method, ['parameters', 'servers', 'summary', 'description', '$ref', 'additionalOperations'], true)) {
            return false;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $method) === 1;
    }

    /**
     * Name suffixes for the media types of ONE `content` map — empty for the one that keeps the
     * plain name, distinct for the rest.
     *
     * `content` is a MAP keyed by media type, so an operation may legitimately describe several
     * representations of the same payload: `application/json` beside `application/xml`, a form
     * fallback beside JSON, `text/csv` beside JSON on a response. The name was derived from the
     * operation alone, so every one of them resolved to the SAME class name and each overwrote the
     * last — the document's final media type won and the others vanished with no warning. A client
     * posting JSON to an operation that also declared a form got `Required parameter "f" not found`,
     * because the emitted class described the form.
     *
     * Only media types carrying an INLINE object schema are numbered here. One pointing at a
     * component declares no new class (the component has its own) and one that is not an object
     * produces none either, so neither can collide.
     *
     * JSON keeps the plain name. That is deliberate and it is the whole reason this is not a renaming
     * release: the overwhelming majority of documents describe one JSON body per operation, those
     * names are already generated, imported and committed in consumers, and they do not move. A
     * document with no JSON media type at all gives the plain name to its FIRST inline schema, which
     * is likewise the name it has today.
     *
     * @param array<mixed> $content
     * @param array<mixed> $openApi
     * @return array<string, string>
     */
    private function mediaTypeNameSuffixes(array $content, array $openApi): array
    {
        $candidates = [];
        foreach ($content as $mediaType => $mediaTypeObject) {
            if (!is_string($mediaType)) {
                continue;
            }

            $mediaTypeObject = $this->resolveComponentRef($mediaTypeObject, 'mediaTypes', $openApi);
            if (!is_array($mediaTypeObject)) {
                continue;
            }

            $schema = $mediaTypeObject['schema'] ?? null;
            if (
                !is_array($schema)
                || array_key_exists('$ref', $schema)
                || ($schema['type'] ?? null) !== 'object'
            ) {
                continue;
            }

            $candidates[] = $mediaType;
        }

        if (count($candidates) < 2) {
            return $candidates === [] ? [] : [$candidates[0] => ''];
        }

        $primary = null;
        foreach ($candidates as $mediaType) {
            if ($this->isJsonMediaTypeName($mediaType)) {
                $primary = $mediaType;
                break;
            }
        }
        $primary ??= $candidates[0];

        $suffixes = [];
        $taken = [];
        foreach ($candidates as $mediaType) {
            if ($mediaType === $primary) {
                $suffixes[$mediaType] = '';
                continue;
            }

            $suffix = $this->mediaTypeNameSuffix($mediaType);
            $candidate = $suffix;
            $counter = 2;
            while (array_key_exists($candidate, $taken)) {
                $candidate = $suffix . $counter;
                $counter++;
            }

            $taken[$candidate] = true;
            $suffixes[$mediaType] = $candidate;
        }

        return $suffixes;
    }

    /**
     * A readable class-name suffix for a media type: `application/xml` -> `Xml`.
     *
     * Derived from the SUBTYPE, because the type half is `application` almost everywhere and would
     * say nothing. The three shorthands are the media types whose literal subtype makes an unusable
     * identifier — `XWwwFormUrlencoded` for a form is noise, not information.
     */
    private function mediaTypeNameSuffix(string $mediaType): string
    {
        $type = strtolower(trim(explode(';', $mediaType)[0]));

        $shorthands = [
            'application/x-www-form-urlencoded' => 'Form',
            'multipart/form-data' => 'Multipart',
            'application/octet-stream' => 'Binary',
            '*/*' => 'Any',
        ];
        if (array_key_exists($type, $shorthands)) {
            return $shorthands[$type];
        }

        $slash = strpos($type, '/');
        $subtype = $slash === false ? $type : substr($type, $slash + 1);
        // A vendor tree says who owns the type, not what it is.
        $subtype = preg_replace('/^vnd\./', '', $subtype) ?? $subtype;

        $segments = preg_split('/[^a-z0-9]+/', $subtype);
        $name = '';
        foreach ($segments === false ? [] : $segments as $segment) {
            if ($segment !== '') {
                $name .= ucfirst($segment);
            }
        }

        return $name !== '' ? $name : 'Alt';
    }

    /**
     * Builds a collision-free schema name for an endpoint-derived DTO
     * (inline request/response bodies and path/query parameter DTOs).
     *
     * The base name strips path parameters, so distinct endpoints differing only
     * by a path parameter (e.g. `/x/{a}` and `/x`) would otherwise collapse to the
     * same name and silently overwrite each other. When a different endpoint wants
     * an already-owned name, the path-parameter names are injected as a
     * `By<Param...>` segment; a numeric suffix is appended only as a last resort.
     *
     * The same endpoint reclaiming its own name (e.g. several media types under one
     * response) keeps the plain name — that is an intentional single DTO, not a
     * collision.
     *
     * @param array<string, string> $owners map of already-assigned name => owner key
     */
    private function uniqueEndpointSchemaName(string $path, string $tail, string $ownerKey, array $owners): string
    {
        $base = $this->normalizePathForSchemaName($path);
        $name = $base . $tail;

        if (($owners[$name] ?? $ownerKey) === $ownerKey) {
            return $name;
        }

        $paramSuffix = '';
        foreach ($this->pathParameterNames($path) as $parameterName) {
            $paramSuffix .= $this->pascalizeSegment($parameterName);
        }

        $prefix = $base . ($paramSuffix !== '' ? 'By' . $paramSuffix : '');
        $candidate = $prefix . $tail;
        $counter = 2;
        while (array_key_exists($candidate, $owners) && $owners[$candidate] !== $ownerKey) {
            $candidate = $prefix . $tail . $counter;
            $counter++;
        }

        return $candidate;
    }

    /**
     * The name part a response status contributes: `200` stays `200`, `default` becomes `Default`.
     *
     * `normalizeClassName()` lowercases each segment and capitalises its first letter, and it finds
     * segment boundaries at non-alphanumerics or a lower-to-upper camel step. `Jobsdefault` has
     * neither, so the word sat there in lowercase while every numeric sibling read as `Jobs200`. The
     * underscore is not part of any emitted name — it exists only to give the normaliser the boundary
     * it needs, and it is gone by the time a class name is built.
     *
     * Applied ONLY to a purely alphabetic status, which in OpenAPI means `default` and nothing else.
     * A wildcard range like `4XX` is deliberately untouched: it has a digit, so the normaliser already
     * splits it, and adding a boundary would rewrite `Jobs4Xx` into `Jobs4xx` — a rename for no gain.
     */
    private function responseNameTail(string $statusCode): string
    {
        return ctype_alpha($statusCode) ? '_' . $statusCode : $statusCode;
    }

    private function normalizePathForSchemaName(string $path): string
    {
        $pathPart = trim($path, '/');
        $splitResult = preg_split('/[\/\-_]+/', $pathPart);
        $segments = $splitResult !== false ? $splitResult : [];

        $normalizedPath = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            // Skip path parameter placeholders like {id}, {userId}, etc.
            if (preg_match('/^\{[^}]+\}$/', $segment) === 1) {
                continue;
            }

            $normalizedPath .= ucfirst($segment);
        }

        return $normalizedPath;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isEnumSchema(array $schema): bool
    {
        return array_key_exists('enum', $schema)
            && is_array($schema['enum'])
            && $schema['enum'] !== []
            && array_key_exists('type', $schema)
            && in_array($schema['type'], ['string', 'integer'], true)
            && $this->canGenerateBackedEnumFromSchema($schema, true);
    }

    /**
     * Backed enums can represent only string/int values. Enums containing bool/null/objects
     * stay inline on the property and are validated via constraints instead of enum synthesis.
     *
     * @param array<string, mixed> $schema
     */
    private function canGenerateBackedEnumFromSchema(array $schema, bool $requireExplicitType = false): bool
    {
        $enum = $schema['enum'] ?? null;
        if (!is_array($enum) || $enum === []) {
            return false;
        }

        foreach ($enum as $value) {
            if (!is_string($value) && !is_int($value)) {
                return false;
            }
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            return false;
        }
        if ($type !== null && !is_string($type)) {
            return false;
        }

        if ($requireExplicitType && !in_array($type, ['string', 'integer'], true)) {
            return false;
        }

        if ($type === 'integer') {
            foreach ($enum as $value) {
                if (!is_int($value)) {
                    return false;
                }
            }
        }

        if ($type !== null && !in_array($type, ['string', 'integer'], true)) {
            return false;
        }

        // Without a `type`, the members ARE the type — and a backed enum has room for one kind only. `[1,
        // "a"]` used to become a STRING-backed enum, which hydrates `1` as `"1"` and refuses the integer
        // the document lists; with no shared kind there is no PHP enum to make, and the members stay an
        // inline constraint compared as JSON values.
        return $type !== null || $this->untypedEnumBackingType($enum) !== null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function resolveEnumBackingType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if ($type === null && is_array($schema['enum'] ?? null)) {
            return $this->untypedEnumBackingType($schema['enum']) ?? 'string';
        }

        return $type === 'integer' ? 'int' : 'string';
    }

    /**
     * The backing type an enum with no `type` gets from its members: `int` when every member is an
     * integer, `string` when every member is a string, otherwise none.
     *
     * An untyped all-integer enum was backed by STRING — `enum: [1, 2, 3]` became `case VALUE_1 = '1'`
     * — so runtime refused `1` ("expects enum ProbeF, got 1") and laravel died in `ProbeF::from()` with a
     * TypeError, string expected, int given. A component reads the same members the same way already
     * (normalizeEnumSpelling()); a property now agrees with it.
     *
     * @param array<mixed> $members
     */
    private function untypedEnumBackingType(array $members): ?string
    {
        if ($members === []) {
            return null;
        }
        if (array_filter($members, is_int(...)) === $members) {
            return 'int';
        }
        if (array_filter($members, is_string(...)) === $members) {
            return 'string';
        }

        return null;
    }

    /**
     * @param array<int, string|int> $values
     * @param array<int, string>|null $varnames mapped positionally onto $values (x-enum-varnames)
     * @param array<int, ?string>|null $descriptions mapped positionally onto $values (x-enum-descriptions)
     */
    private function registerEnum(
        string $enumName,
        string $type,
        array $values,
        ?string $sourceFile,
        ?array $varnames = null,
        ?array $descriptions = null,
    ): void {
        $namespace = $this->resolveNamespaceForSourceFile($sourceFile);
        $outputDirectory = $this->resolveOutputDirectoryForSourceFile($sourceFile);

        // A REPEATED member is a document repeating itself, and PHP will not have it: two cases
        // carrying one backed value make `from()` and `tryFrom()` throw `Duplicate value in enum` —
        // the two methods every generated hydration goes through. The class declares fine and
        // `cases()` answers, so nothing fails until a payload arrives, and then it fails as an `Error`
        // rather than as a validation message.
        //
        // JSON Schema says the members SHOULD be unique, not MUST, so the document is not wrong enough
        // to refuse. The repeat is dropped instead, and the FIRST occurrence keeps its name, its
        // `x-enum-varnames` entry and its description — all three are indexed together.
        $seen = [];
        $uniqueValues = [];
        $uniqueVarnames = $varnames === null ? null : [];
        $uniqueDescriptions = $descriptions === null ? null : [];

        foreach (array_values($values) as $index => $value) {
            // `1` and `"1"` are different members of a mixed-type document, so the kind is part of
            // the key rather than left to PHP's array-key juggling.
            $key = is_int($value) ? 'i:' . $value : 's:' . $value;
            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $uniqueValues[] = $value;
            if ($uniqueVarnames !== null) {
                $uniqueVarnames[] = $varnames[$index] ?? '';
            }
            if ($uniqueDescriptions !== null) {
                $uniqueDescriptions[] = $descriptions[$index] ?? null;
            }
        }

        $values = $uniqueValues;
        $varnames = $uniqueVarnames;
        $descriptions = $uniqueDescriptions;

        if (array_key_exists($enumName, $this->enumSchemas)) {
            $existing = $this->enumSchemas[$enumName];
            if ($existing['type'] !== $type || $existing['values'] !== $values) {
                throw new RuntimeException(sprintf('Enum schema name collision for %s.', $enumName));
            }

            if (($this->enumNamespaces[$enumName] ?? $namespace) !== $namespace) {
                throw new RuntimeException(sprintf('Enum schema namespace collision for %s.', $enumName));
            }
            return;
        }

        if (array_key_exists($enumName, $this->dtoSchemas)) {
            throw new RuntimeException(sprintf('Enum/DTO name collision for %s.', $enumName));
        }

        $caseNames = $this->buildEnumCaseNames($values, $varnames);
        $normalizedDescriptions = [];
        foreach (array_keys($values) as $i) {
            $description = $descriptions[$i] ?? null;
            $normalizedDescriptions[$i] = is_string($description) && $description !== ''
                ? $this->normalizeEnumCaseDescription($description)
                : null;
        }

        $this->enumSchemas[$enumName] = [
            'type' => $type,
            'values' => $values,
            'caseNames' => $caseNames,
            'descriptions' => $normalizedDescriptions,
        ];
        $this->enumSourceFiles[$enumName] = $sourceFile;
        $this->enumNamespaces[$enumName] = $namespace;
        $this->enumOutputDirectories[$enumName] = $outputDirectory;
    }

    /**
     * @param array<int, string|int> $values
     * @param array<int, string> $caseNames
     * @param array<int, ?string> $descriptions
     */
    private function renderEnum(
        string $namespace,
        string $enumName,
        string $backingType,
        array $values,
        array $caseNames,
        array $descriptions,
    ): string {
        $cases = [];

        foreach (array_values($values) as $index => $value) {
            $cases[] = [
                'name' => $caseNames[$index],
                'value' => $this->renderEnumValue($value, $backingType),
                'description' => $descriptions[$index] ?? null,
            ];
        }

        // There is ONE axis here, and it is not the framework: does the enum carry this package's
        // runtime interface and its methods, or is it a plain backed enum?
        //
        // Runtime mode needs the interface — `DtoNormalizer` reads it. The other four do not: the
        // Symfony serializer handles a backed enum natively through `BackedEnumNormalizer`, Laravel's
        // and laravel-data's generated hydration map the value themselves, and Yii3's `ObjectParser`
        // does too. So all four emit the same enum, byte for byte — which is why there are two
        // templates and not five.
        //
        // The old spelling of this was `$isSymfony` plus `enum.symfony.php.twig`, and both names were
        // false four times out of five: nothing about either is Symfony's.
        $rendersStandaloneEnum = $this->attributeMode !== self::ATTRIBUTE_MODE_RUNTIME;

        $imports = [];
        $generatedDtoInterfaceRef = 'GeneratedDtoInterface';
        $jsonExceptionRef = 'JsonException';
        if (!$rendersStandaloneEnum) {
            // Import the runtime interface only when it lives in another namespace (avoid a
            // self-import), and JsonException for the enum's toJson() (@throws + json_encode with
            // JSON_THROW_ON_ERROR). Sort so the output matches php-cs-fixer's ordered_imports.
            $fqcnNamespace = implode('\\', array_slice(explode('\\', $this->generatedDtoInterfaceImportFqcn), 0, -1));
            $generatedDtoInterfaceRef = $fqcnNamespace === $namespace
                ? 'GeneratedDtoInterface'
                : $this->libraryClassRef($this->generatedDtoInterfaceImportFqcn, $namespace, $imports);
            $jsonExceptionRef = $this->libraryClassRef('JsonException', $namespace, $imports);
            sort($imports);
        }

        return $this->renderPhpTemplate(
            templateName: $rendersStandaloneEnum ? 'enum.standalone.php.twig' : 'enum.php.twig',
            context: [
                'namespace' => $namespace,
                'imports' => $imports,
                'enumName' => $enumName,
                'backingType' => $backingType,
                'cases' => $cases,
                'sourceEndpoint' => $this->endpointByClass[$enumName] ?? null,
                'sourceSpecLink' => $this->resolveSpecLink($enumName),
                'sourceRelated' => $this->relatedByClass[$enumName] ?? null,
                'generatedDtoInterfaceRef' => $generatedDtoInterfaceRef,
                'jsonExceptionRef' => $jsonExceptionRef,
            ],
        );
    }

    /**
     * Reads the x-enum-varnames vendor extension. Returns positional case names only when the
     * extension is a list of non-empty strings matching the value count, otherwise null (fallback
     * to value-derived names). x-enum-varnames is not part of the OpenAPI spec; it is a de-facto
     * codegen convention.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string|int> $values
     * @return array<int, string>|null
     */
    private function extractEnumVarnames(array $schema, array $values): ?array
    {
        $varnames = $schema['x-enum-varnames'] ?? null;
        if (!is_array($varnames) || count($varnames) !== count($values)) {
            return null;
        }

        $result = [];
        foreach (array_values($varnames) as $name) {
            if (!is_string($name) || $name === '') {
                return null;
            }
            $result[] = $name;
        }

        return $result;
    }

    /**
     * Reads the x-enum-descriptions vendor extension. Returns a positional list aligned with the
     * value count (entries may be null), otherwise null.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string|int> $values
     * @return array<int, ?string>|null
     */
    private function extractEnumDescriptions(array $schema, array $values): ?array
    {
        $descriptions = $schema['x-enum-descriptions'] ?? null;
        if (!is_array($descriptions) || count($descriptions) !== count($values)) {
            return null;
        }

        $result = [];
        foreach (array_values($descriptions) as $description) {
            $result[] = is_string($description) ? $description : null;
        }

        return $result;
    }

    /**
     * Builds the final enum case names (deduplicated) for a value list. When $varnames is provided
     * (from x-enum-varnames) each name is sanitised into a valid identifier; otherwise names are
     * derived from the values. This is the single source of truth shared by enum rendering and
     * enum default-value resolution.
     *
     * @param array<int, string|int> $values
     * @param array<int, string>|null $varnames
     * @return array<int, string>
     */
    private function buildEnumCaseNames(array $values, ?array $varnames): array
    {
        $usedCaseNames = [];
        $caseNames = [];

        foreach (array_values($values) as $index => $value) {
            $caseNames[] = $varnames !== null
                ? $this->sanitizeEnumCaseName($varnames[$index], $usedCaseNames)
                : $this->buildEnumCaseName($value, $usedCaseNames);
        }

        return $caseNames;
    }

    /**
     * Turns an x-enum-varnames entry into a valid, unique PHP enum case identifier, preserving the
     * author's intended casing where possible.
     *
     * @param array<string, true> $usedCaseNames
     */
    private function sanitizeEnumCaseName(string $varname, array &$usedCaseNames): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '_', $varname) ?? $varname;
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'VALUE';
        }

        if (is_numeric($base[0])) {
            $base = 'VALUE_' . $base;
        }

        // `class` is the one name PHP refuses for a class constant, and it refuses it case-insensitively
        // — so a document whose enum member is "class" emitted `case CLASS = 'class';`, a file that does
        // not COMPILE. The digit rule above already owns the "prefix it" idiom, so this follows it.
        if (strcasecmp($base, 'CLASS') === 0) {
            $base = 'VALUE_' . $base;
        }

        $name = $base;
        $i = 2;

        while (array_key_exists($name, $usedCaseNames)) {
            $name = $base . '_' . $i;
            $i++;
        }

        $usedCaseNames[$name] = true;

        return $name;
    }

    /**
     * Resolves the generated case name for an enum default value by matching it against the
     * registered enum's value list, so defaults reference the same case name the enum declares
     * (including any x-enum-varnames mapping). Returns null when the enum is not registered.
     */
    private function resolveEnumCaseNameForValue(string $enumName, string|int $value): ?string
    {
        $enum = $this->enumSchemas[$enumName] ?? null;
        if ($enum === null) {
            return null;
        }

        $index = array_search($value, $enum['values'], true);
        if ($index === false) {
            return null;
        }

        return $enum['caseNames'][$index] ?? null;
    }

    /**
     * Collapses an x-enum-descriptions entry to a single docblock-safe line.
     */
    private function normalizeEnumCaseDescription(string $description): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($description)) ?? $description;

        return str_replace('*/', '* /', $normalized);
    }

    /**
     * @param array<string, true> $usedCaseNames
     */
    private function buildEnumCaseName(string|int $value, array &$usedCaseNames): string
    {
        $base = (string)$value;
        $base = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $base) ?? $base;
        $base = preg_replace('/[^A-Za-z0-9]+/', '_', $base) ?? $base;
        $base = strtoupper(trim($base, '_'));

        if ($base === '') {
            $base = 'VALUE';
        }

        if (is_numeric($base[0])) {
            $base = 'VALUE_' . $base;
        }

        // `class` is the one name PHP refuses for a class constant, and it refuses it case-insensitively
        // — so a document whose enum member is "class" emitted `case CLASS = 'class';`, a file that does
        // not COMPILE. The digit rule above already owns the "prefix it" idiom, so this follows it.
        if (strcasecmp($base, 'CLASS') === 0) {
            $base = 'VALUE_' . $base;
        }

        $name = $base;
        $i = 2;

        while (array_key_exists($name, $usedCaseNames)) {
            $name = $base . '_' . $i;
            $i++;
        }

        $usedCaseNames[$name] = true;

        return $name;
    }

    private function renderEnumValue(string|int $value, string $backingType): string
    {
        if ($backingType === 'int') {
            if (!is_int($value)) {
                throw new RuntimeException('Integer enum contains non-integer value.');
            }

            return $this->numericLiteral($value);
        }

        $escaped = $this->escapeSingleQuoted((string)$value);
        return "'" . $escaped . "'";
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractDefaultValue(array $propertySchema, string $type): mixed
    {
        if (!array_key_exists('default', $propertySchema)) {
            return null;
        }

        return $propertySchema['default'];
    }

    /**
     * Recovers the `default` declared on a referenced schema when the property itself only carries
     * a `$ref` (optionally wrapped in a single-ref allOf). Returns null when the property is not a
     * pure reference or the target declares no default.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function resolveReferencedDefault(array $propertySchema, string $ownerClassName): mixed
    {
        $ref = $this->singleRefOf($propertySchema);
        if ($ref === null) {
            return null;
        }

        return $this->defaultFromRef(
            ref: $ref,
            currentSourceFile: $this->getSchemaSourceFile($ownerClassName),
            depth: 0,
        );
    }

    /**
     * Returns the `$ref` string when the schema is a pure reference — either a direct `$ref` or an
     * allOf with exactly one ref-only branch — otherwise null.
     *
     * @param array<string, mixed> $schema
     */
    private function singleRefOf(array $schema): ?string
    {
        if (array_key_exists('$ref', $schema) && is_string($schema['$ref'])) {
            return $schema['$ref'];
        }

        if (array_key_exists('allOf', $schema) && is_array($schema['allOf']) && count($schema['allOf']) === 1) {
            $first = $schema['allOf'][0];
            if (is_array($first) && array_key_exists('$ref', $first) && is_string($first['$ref'])) {
                return $first['$ref'];
            }
        }

        return null;
    }

    /**
     * Walks a $ref (internal or external) to the target schema node and returns its `default`,
     * following one further level of pure-reference indirection. Bounded by $depth to guard
     * against reference cycles.
     */
    private function defaultFromRef(string $ref, ?string $currentSourceFile, int $depth): mixed
    {
        if ($depth > 10) {
            return null;
        }

        $resolved = $this->resolveSchemaNodeByRef($ref, $currentSourceFile);
        if ($resolved === null) {
            return null;
        }

        [$node, $nodeSourceFile] = $resolved;

        if (array_key_exists('default', $node)) {
            return $node['default'];
        }

        $nestedRef = $this->singleRefOf($node);
        if ($nestedRef !== null) {
            return $this->defaultFromRef(ref: $nestedRef, currentSourceFile: $nodeSourceFile, depth: $depth + 1);
        }

        return null;
    }

    /**
     * Resolves a $ref (internal `#/components/schemas/Name` or external `file#/...`) to its raw
     * schema node and the source file that node belongs to (for chained ref resolution). Returns
     * null when the target cannot be located.
     *
     * @return array{0: array<string, mixed>, 1: ?string}|null
     */
    private function resolveSchemaNodeByRef(string $ref, ?string $currentSourceFile): ?array
    {
        $prefix = '#/components/schemas/';

        if (str_starts_with($ref, $prefix)) {
            $schemaName = substr($ref, strlen($prefix));
            if ($schemaName === '' || str_contains($schemaName, '/')) {
                return null;
            }

            $className = $this->schemaClassName($schemaName);
            $node = $this->rawSchemasByClass[$className] ?? null;

            return is_array($node) ? [$node, $this->getSchemaSourceFile($className)] : null;
        }

        $resolvedExternal = $this->resolveExternalSchemaPointer($ref, $currentSourceFile);
        if ($resolvedExternal === null) {
            return null;
        }

        [$externalFile, $pointer] = $resolvedExternal;
        $node = $this->resolveExternalPointerNode($externalFile, $pointer);

        return is_array($node) ? [$node, $externalFile] : null;
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractDescription(array $propertySchema): ?string
    {
        if (!array_key_exists('description', $propertySchema)) {
            return null;
        }

        $description = $propertySchema['description'];
        if (!is_string($description) || trim($description) === '') {
            return null;
        }

        // Normalize multiline descriptions
        $description = trim($description);
        return str_replace('*/', '* /', preg_replace('/\s+/', ' ', $description) ?? $description);
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function extractExample(array $propertySchema): ?string
    {
        // OAS 3.2 adds `serializedExample` (the encoded form); use it when no plain example exists.
        $exampleKey = match (true) {
            array_key_exists('example', $propertySchema) => 'example',
            array_key_exists('serializedExample', $propertySchema) => 'serializedExample',
            default => null,
        };
        if ($exampleKey === null) {
            return null;
        }

        $example = $propertySchema[$exampleKey];

        if (is_string($example)) {
            $normalized = trim($example);
            if ($normalized === '') {
                return null;
            }

            return str_replace('*/', '* /', preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        }

        if (is_int($example) || is_float($example) || is_bool($example) || is_array($example)) {
            $encoded = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? str_replace('*/', '* /', $encoded) : null;
        }

        return null;
    }

    /**
     * A `format: date` / `date-time` default as a constructor-parameter default expression.
     *
     * Parsed here rather than trusted: a document may carry anything under `default`, and a value
     * `DateTimeImmutable` cannot read would move the failure from generation time (where the spec
     * author can see it) to every request. An unusable default is simply not emitted.
     */
    private function renderTemporalDefaultValue(mixed $defaultValue): string
    {
        if (!is_string($defaultValue) || $defaultValue === '') {
            return '';
        }

        try {
            new DateTimeImmutable($defaultValue);
        } catch (Exception) {
            return '';
        }

        return " = new DateTimeImmutable('" . $this->escapeSingleQuoted($defaultValue) . "')";
    }

    private function renderDefaultValue(mixed $defaultValue, string $phpType, string $fullType): string
    {
        if ($defaultValue === null) {
            return '';
        }

        // A temporal default is NOT an enum case, and the branch below would name one that does not
        // exist: `format: date, default: "2020-01-01"` produced
        // `DateTimeImmutable::VALUE_2020_01_01` — a fatal on class load in symfony mode and on the
        // first defaulted construction in runtime and laravel. Measured in all three. `new` is legal
        // in a PARAMETER default (PHP 8.1), which is where those two put it.
        if ($phpType === 'DateTimeImmutable') {
            return $this->renderTemporalDefaultValue($defaultValue);
        }

        // Handle enum types - need to use the enum case
        if (
            !str_contains($phpType, '|') && !in_array(
                needle: $phpType,
                haystack: ['int', 'float', 'string', 'bool', 'array', 'mixed'],
                strict: true,
            )
        ) {
            // It's an enum or custom type. Resolve the case name against the registered enum so the
            // default references the same case the enum declares (honouring x-enum-varnames). Fall
            // back to value-derived naming for enums not registered here (e.g. external types).
            if (is_string($defaultValue) || is_int($defaultValue)) {
                $enumCaseName = $this->resolveEnumCaseNameForValue($phpType, $defaultValue);
                if ($enumCaseName === null) {
                    $usedNames = [];
                    $enumCaseName = $this->buildEnumCaseName($defaultValue, $usedNames);
                }
                return ' = ' . $phpType . '::' . $enumCaseName;
            }
        }

        // Handle scalar types
        if ($phpType === 'int') {
            return ' = ' . $this->numericLiteral((int)$defaultValue);
        }

        if ($phpType === 'float') {
            return ' = ' . (float)$defaultValue;
        }

        if ($phpType === 'bool') {
            return $defaultValue === true ? ' = true' : ' = false';
        }

        if ($phpType === 'string') {
            $escaped = $this->escapeSingleQuoted((string)$defaultValue);
            return " = '" . $escaped . "'";
        }

        if ($phpType === 'array') {
            return ' = []';
        }

        return '';
    }

    /**
     * Folds an Encoding Object into the body schema's properties.
     *
     * A part declared `contentType: application/json` arrives as a JSON string, so it is marked
     * with the same `json` serialization sentinel a `content:` parameter uses — the deserializer
     * decodes it before casting. `style`/`explode` on a form-urlencoded part describe how an array
     * or object part is flattened into the field, i.e. exactly what the parameter style machinery
     * already resolves. Per-part `headers` are transport metadata and carry no payload shape.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyEncodingToBodySchema(array $schema, mixed $encoding): array
    {
        if (!is_array($encoding) || $encoding === [] || !is_array($schema['properties'] ?? null)) {
            return $schema;
        }

        foreach ($encoding as $propertyName => $partEncoding) {
            if (!is_string($propertyName) || !is_array($partEncoding)) {
                continue;
            }
            if (!array_key_exists($propertyName, $schema['properties']) || !is_array($schema['properties'][$propertyName])) {
                continue;
            }

            $contentType = $partEncoding['contentType'] ?? null;
            if (is_string($contentType) && $this->isJsonMediaTypeName($contentType)) {
                $schema['properties'][$propertyName]['x-parameter-style'] = 'json';
                $schema['properties'][$propertyName]['x-parameter-explode'] = false;
                continue;
            }

            $style = $partEncoding['style'] ?? null;
            if (!is_string($style) || $style === '') {
                continue;
            }

            $schema['properties'][$propertyName]['x-parameter-style'] = $style;
            $schema['properties'][$propertyName]['x-parameter-explode'] = is_bool($partEncoding['explode'] ?? null)
                ? $partEncoding['explode']
                : $style === 'form';
        }

        return $schema;
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>
     */
    private function extractParameterSchemas(array $openApi): array
    {
        $paths = $this->collectPathItems($openApi);

        $parameterSchemas = [];
        $parameterOwners = [];

        foreach ($paths as $path => $pathItem) {
            // Declared once for the whole path and applying to every operation on it — the ordinary
            // way to write `/items/{id}` rather than repeating `id` under get, put and delete.
            $pathLevelParameters = $this->resolveParameters(
                parameters: is_array($pathItem['parameters'] ?? null) ? $pathItem['parameters'] : [],
                openApi: $openApi,
            );

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !$this->isHttpMethod($method) || !is_array($operation)) {
                    continue;
                }

                $operationParameters = $this->resolveParameters(
                    parameters: is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [],
                    openApi: $openApi,
                );

                $resolvedParameters = $this->mergePathItemParameters(
                    pathLevel: $pathLevelParameters,
                    operationLevel: $operationParameters,
                );
                $this->warnAboutPathParameterMismatches($path, $resolvedParameters);
                $pathAndQueryParameters = $this->filterPathAndQueryParameters($resolvedParameters);
                $ownerKey = strtoupper($method) . ' ' . $path;

                // Symfony binds route, header and cookie values as controller arguments of their own,
                // so they are no part of the query DTO — but an inline `enum` on one still deserves
                // its class: a route argument typed with it is resolved and checked by Symfony itself.
                // With `<Op>PathParams` on, a path parameter's enum is that class's own, of the same name.
                $symfonyPathParams = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY && $this->symfonyPathParams;
                if ($this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY) {
                    foreach ($resolvedParameters as $parameter) {
                        $location = $parameter['in'] ?? null;
                        $parameterName = $parameter['name'] ?? null;
                        $schema = $parameter['schema'] ?? null;
                        if (
                            !in_array($location, $symfonyPathParams ? ['header', 'cookie'] : ['path', 'header', 'cookie'], true)
                            || !is_string($parameterName)
                            || !is_array($schema)
                            || !is_array($schema['enum'] ?? null)
                            || array_key_exists('$ref', $schema)
                        ) {
                            continue;
                        }
                        $enumName = $this->uniqueEndpointSchemaName(
                            path: $this->pathItemNamingKey($path),
                            tail: ucfirst(strtolower($method)) . ucfirst($location) . 'Params' . $this->pascalizeSegment($parameterName),
                            ownerKey: $ownerKey,
                            owners: $parameterOwners,
                        );
                        $parameterSchemas[$enumName] = $schema;
                        $parameterOwners[$enumName] = $ownerKey;
                    }
                }

                $pathParameters = $symfonyPathParams
                    ? array_values(array_filter(
                        array: $resolvedParameters,
                        callback: static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'path',
                    ))
                    : [];
                if ($pathParameters !== []) {
                    $pathSchemaName = $this->uniqueEndpointSchemaName(
                        path: $this->pathItemNamingKey($path),
                        tail: ucfirst(strtolower($method)) . 'PathParams',
                        ownerKey: $ownerKey,
                        owners: $parameterOwners,
                    );
                    $parameterSchemas[$pathSchemaName] = $this->markSchemaDeprecatedByOperation(
                        schema: $this->buildParameterSchema($pathParameters),
                        operation: $operation,
                    );
                    $parameterOwners[$pathSchemaName] = $ownerKey;
                    $this->endpointByClass[$this->schemaClassName($pathSchemaName)] = $ownerKey;
                    $this->requestPayloadClasses[$this->schemaClassName($pathSchemaName)] = true;
                    $this->parameterPayloadClasses[$this->schemaClassName($pathSchemaName)] = true;
                }

                if ($pathAndQueryParameters === []) {
                    continue;
                }

                $schemaName = $this->uniqueEndpointSchemaName(
                    path: $this->pathItemNamingKey($path),
                    tail: ucfirst(strtolower($method)) . 'QueryParams',
                    ownerKey: $ownerKey,
                    owners: $parameterOwners,
                );
                $parameterSchemas[$schemaName] = $this->markSchemaDeprecatedByOperation(
                    schema: $this->buildParameterSchema($pathAndQueryParameters),
                    operation: $operation,
                );
                $parameterOwners[$schemaName] = $ownerKey;
                $this->endpointByClass[$this->schemaClassName($schemaName)] = $ownerKey;
                $this->requestPayloadClasses[$this->schemaClassName($schemaName)] = true;
                $this->parameterPayloadClasses[$this->schemaClassName($schemaName)] = true;
            }
        }

        return $parameterSchemas;
    }

    /**
     * Path Item parameters plus the operation's own, the operation winning on a clash.
     *
     * OpenAPI: a Path Item may declare `parameters` that apply to EVERY operation on that path, and
     * an operation may override one of them by matching name AND location. It is how `/items/{id}` is
     * normally written — `id` once, not once per method. The generator read the operation's list
     * alone, so a path-level parameter never reached the emitted class: `/items/{id}` with `id`
     * declared on the path produced a class without `id`, and the application had no way to read the
     * path parameter at all. Silently — the property was simply absent.
     *
     * A path-level parameter that the operation overrides keeps its POSITION and takes the
     * operation's definition, so the argument order does not depend on which methods happen to
     * override what. The identity is (name, in) exactly as the specification defines it: two
     * parameters may share a name if they sit in different places.
     *
     * @param array<int, array<string, mixed>> $pathLevel
     * @param array<int, array<string, mixed>> $operationLevel
     * @return array<int, array<string, mixed>>
     */
    private function mergePathItemParameters(array $pathLevel, array $operationLevel): array
    {
        $merged = [];
        foreach ($pathLevel as $parameter) {
            $merged[$this->parameterIdentity($parameter)] = $parameter;
        }

        foreach ($operationLevel as $parameter) {
            // Assigning to an existing key replaces the value and keeps the slot, which is what makes
            // an override sit where the path declared it.
            $merged[$this->parameterIdentity($parameter)] = $parameter;
        }

        return array_values($merged);
    }

    /**
     * The (name, in) pair the specification uses to tell two parameters apart, as one string.
     *
     * A parameter missing either half cannot collide with anything, so it gets a key of its own
     * rather than being merged into the group of all such parameters.
     *
     * @param array<string, mixed> $parameter
     */
    private function parameterIdentity(array $parameter): string
    {
        $name = $parameter['name'] ?? null;
        $in = $parameter['in'] ?? null;
        if (!is_string($name) || !is_string($in)) {
            return 'anonymous:' . spl_object_id((object)$parameter);
        }

        return $in . ':' . $name;
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed> $openApi
     * @return array<mixed>
     */
    private function resolveParameters(array $parameters, array $openApi): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            // If parameter is a reference, resolve it
            if (array_key_exists('$ref', $parameter) && is_string($parameter['$ref'])) {
                $resolvedParam = $this->resolveParameterRef($parameter['$ref'], $openApi);
                if ($resolvedParam !== null) {
                    $resolved[] = $resolvedParam;
                }
                continue;
            }

            $resolved[] = $parameter;
        }

        return $resolved;
    }

    /**
     * Resolves a `$ref` to a reusable component of the given section (`requestBodies`,
     * `responses`, …) so a referenced body/response contributes its schema like an inline one.
     * Non-references pass through; unresolvable references yield null and are skipped by callers.
     *
     * @param array<mixed> $openApi
     * @return array<mixed>|null
     */
    private function resolveComponentRef(mixed $node, string $section, array $openApi): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        $ref = $node['$ref'] ?? null;
        if (!is_string($ref)) {
            return $node;
        }

        $prefix = '#/components/' . $section . '/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $component = $openApi['components'][$section][substr($ref, strlen($prefix))] ?? null;

        // A component may itself be a reference; follow a short chain, guarding against cycles.
        $guard = 0;
        while (is_array($component) && is_string($component['$ref'] ?? null) && $guard++ < 10) {
            $nestedRef = $component['$ref'];
            if (!str_starts_with($nestedRef, $prefix)) {
                return null;
            }
            $component = $openApi['components'][$section][substr($nestedRef, strlen($prefix))] ?? null;
        }

        return is_array($component) ? $component : null;
    }

    /**
     * A `requestBodies` / `mediaTypes` component however its `$ref` is spelled — `#/components/…`,
     * through the document's own file name or `$self`, or in another file — with the file that
     * declares it, against which its own `$ref`s resolve. A node that is no reference is returned
     * as it is, with `$sourceFile`. Null for anything that cannot be followed, as before: a body the
     * walk cannot read was always skipped, and a reference out of the tree is not read at all.
     *
     * @param array<mixed> $openApi the root document
     * @return array{0: array<mixed>, 1: string|null}|null
     */
    private function resolveComponentAcrossFiles(mixed $node, string $section, array $openApi, ?string $sourceFile): ?array
    {
        $prefix = '#/components/' . $section . '/';
        // Bounded: a chain of components that loops back on itself names nothing.
        for ($hop = 0; $hop < 16; ++$hop) {
            if (!is_array($node)) {
                return null;
            }
            $ref = $node['$ref'] ?? null;
            if (!is_string($ref)) {
                return [$node, $sourceFile];
            }

            $ref = $sourceFile === $this->rootSpecFile ? $this->stripDocumentSelfPrefix($ref) : $ref;
            [$filePart, $pointer] = explode('#', $ref, 2) + [1 => ''];
            if ($filePart !== '') {
                $sourceFile = $this->readableReferencedFile(filePart: $filePart, currentSourceFile: $sourceFile);
                if ($sourceFile === null) {
                    return null;
                }
            }
            $pointer = '#' . $pointer;
            $name = str_starts_with($pointer, $prefix) ? substr($pointer, strlen($prefix)) : '';
            if ($name === '' || str_contains($name, '/')) {
                return null;
            }

            $document = $sourceFile === null || $sourceFile === $this->rootSpecFile
                ? $openApi
                : $this->externalDocumentOf($sourceFile);
            $node = $document['components'][$section][$name] ?? null;
        }

        return null;
    }

    /**
     * The canonical path of the document a `$ref`'s file part names, relative to the document it is
     * written in, or null when it is not one the generator reads: not a document, missing, or out of
     * the tree with no `--ref` to it.
     */
    private function readableReferencedFile(string $filePart, ?string $currentSourceFile): ?string
    {
        $filePart = rtrim($filePart, '/');
        if ($currentSourceFile === null || $filePart === '') {
            return null;
        }
        $effectivePart = preg_replace('/\.(dist|example|local|sample)$/i', '', $filePart) ?? $filePart;
        if (!in_array(strtolower(pathinfo($effectivePart, PATHINFO_EXTENSION)), ['yaml', 'yml', 'json'], true)) {
            return null;
        }
        $absoluteFile = realpath(dirname($currentSourceFile) . DIRECTORY_SEPARATOR . $filePart);
        if ($absoluteFile === false) {
            return null;
        }
        try {
            $this->assertReferenceReadable(ref: $filePart, realFile: $absoluteFile);
        } catch (RuntimeException) {
            return null;
        }

        return $this->canonicalSourceFile($absoluteFile);
    }

    /**
     * An external document whole, parsed once — read through {@see externalSchemasOf()}, so the run
     * lists it among the files it read.
     *
     * @return array<mixed>
     */
    private function externalDocumentOf(string $filePath): array
    {
        $this->externalSchemasOf($filePath);

        return $this->externalDocuments[$filePath] ?? [];
    }

    /**
     * @param array<mixed> $openApi
     * @return array<string, mixed>|null
     */
    private function resolveParameterRef(string $ref, array $openApi): ?array
    {
        $prefix = '#/components/parameters/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $parameterName = substr($ref, strlen($prefix));
        $componentsParameters = $openApi['components']['parameters'] ?? [];

        if (!is_array($componentsParameters) || !array_key_exists($parameterName, $componentsParameters)) {
            return null;
        }

        $parameter = $componentsParameters[$parameterName];

        return is_array($parameter) ? $parameter : null;
    }

    /**
     * @param array<mixed> $parameters
     * @return array<mixed>
     */
    private function filterPathAndQueryParameters(array $parameters): array
    {
        $filtered = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $paramIn = $parameter['in'] ?? null;
            // Symfony binds a DTO from the query string alone (`#[MapQueryString]`); route, header
            // and cookie values reach the controller as arguments of their own. A path parameter in
            // that DTO made it unbindable: the query never carries it, so a required one failed
            // every request.
            $locations = $this->attributeMode === self::ATTRIBUTE_MODE_SYMFONY
                ? ['query']
                : ['path', 'query', 'header', 'cookie', 'querystring'];
            if (in_array($paramIn, $locations, true)) {
                $filtered[] = $parameter;
            }
        }

        return $filtered;
    }

    /**
     * Reports a path template and its `in: path` parameters disagreeing, and a path parameter that
     * says it is optional.
     *
     * None of this changes what is emitted, and that is on purpose. A path parameter is required by
     * the specification whether or not the document remembers to say so, and emitting a nullable one
     * from a careless `required: false` would make the DTO lie in the other direction. What was
     * missing is the sentence.
     *
     * The shape that prompted it:
     *
     *     /warehouse/{shelfId}/{?cursor}
     *       - { in: path, name: cursor, required: false }
     *
     * `{?cursor}` is not OpenAPI path templating — that spelling is RFC 6570 query expansion, which
     * `paths` does not use — so the placeholder is literally named `?cursor` and no router will ever
     * fill it. Meanwhile `cursor` was emitted as a REQUIRED path property, so every request to that
     * endpoint would fail on a parameter that cannot arrive. Generation said nothing; the failure
     * waited for the first call.
     *
     * Three separate sentences, because the fixes differ: a placeholder nobody declared, a parameter
     * that is in no placeholder, and a path parameter marked optional.
     *
     * @param array<int, array<string, mixed>> $parameters
     */
    private function warnAboutPathParameterMismatches(string $path, array $parameters): void
    {
        $declared = [];
        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) !== 'path') {
                continue;
            }

            $name = $parameter['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $declared[$name] = true;

            if (($parameter['required'] ?? null) !== true) {
                $this->addGenerationWarning(sprintf(
                    'Path parameter "%s" on "%s" is not marked `required: true`. A path parameter is '
                    . 'always required, so the generated property is mandatory regardless — the '
                    . 'document and the code disagree only on paper.',
                    $name,
                    $path,
                ));
            }
        }

        $placeholders = $this->pathParameterNames($path);
        foreach ($placeholders as $placeholder) {
            if (array_key_exists($placeholder, $declared)) {
                continue;
            }

            $this->addGenerationWarning(sprintf(
                'The path "%s" contains the placeholder {%s}, which no `in: path` parameter declares. '
                . 'OpenAPI path templating is {name} — a spelling such as {?name} is RFC 6570 query '
                . 'expansion and is not read here, so nothing will ever fill it.',
                $path,
                $placeholder,
            ));
        }

        foreach (array_keys($declared) as $name) {
            if (in_array($name, $placeholders, true)) {
                continue;
            }

            $this->addGenerationWarning(sprintf(
                'Parameter "%s" on "%s" is declared `in: path`, but the path template has no {%s}. '
                . 'It is emitted as a required property that no request can supply.',
                $name,
                $path,
                $name,
            ));
        }
    }

    /**
     * Extracts the `{placeholder}` parameter names from a path, in order.
     *
     * @return array<int, string>
     */
    private function pathParameterNames(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return $matches[1];
    }

    private function pascalizeSegment(string $segment): string
    {
        $splitResult = preg_split('/[\/\-_]+/', $segment);
        $parts = $splitResult !== false ? $splitResult : [];

        $pascalized = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $pascalized .= ucfirst($part);
        }

        return $pascalized;
    }

    /**
     * Carries an operation's `deprecated` down onto the schema its body, parameters or response
     * produce, so the emitted class says so too.
     *
     * The keyword was honoured on a PROPERTY and dropped everywhere else: an endpoint marked
     * deprecated generated classes that looked exactly like a current endpoint's. A schema that
     * already declares the keyword itself is left alone — it cannot become less deprecated by
     * belonging to a live operation.
     *
     * @param array<string, mixed> $schema
     * @param array<mixed> $operation
     * @return array<string, mixed>
     */
    private function markSchemaDeprecatedByOperation(array $schema, array $operation): array
    {
        if (($operation['deprecated'] ?? false) === true) {
            $schema['deprecated'] = true;
        }

        return $schema;
    }

    /**
     * Escapes PCRE defines and ECMA-262 does not. A schema `pattern` is ECMA-262 — under its `u` flag
     * `\h` is a syntax error, without it a plain `h` — while PHP reads `\h` as horizontal whitespace.
     * The PCRE meaning is almost certainly what the author meant, so it is kept; the warning says the
     * pattern would be refused or read differently by a JavaScript validator.
     */
    private const string PCRE_ONLY_ESCAPES = 'hHVRNKAzZGXCQEeago';

    /**
     * A `required` property that also declares a `default`. OpenAPI applies a default only to an
     * ABSENT key, which `required` forbids, so the two contradict each other — and the modes settle it
     * differently. Said here, so the document can be fixed.
     *
     * @param array<array-key, mixed> $schema
     */
    private function warnAboutRequiredPropertiesWithDefault(array $schema, string $className): void
    {
        $properties = $schema['properties'] ?? null;
        $required = $schema['required'] ?? null;
        if (!is_array($properties) || !is_array($required)) {
            return;
        }
        foreach ($required as $name) {
            $property = is_string($name) ? ($properties[$name] ?? null) : null;
            if (is_array($property) && array_key_exists('default', $property) && $property['default'] !== null) {
                $this->addGenerationWarning(sprintf(
                    'Schema "%s" lists "%s" in `required` and gives it a `default`. A default applies only '
                    . 'to an absent key, which `required` forbids. Every mode lets the default win and accepts '
                    . 'the key left out — remove one of the two.',
                    $className,
                    $name,
                ));
            }
        }
    }

    /**
     * A `required` name the schema declares no property for. The class is generated without it, so
     * the requirement the document states is enforced nowhere — usually a leftover of a renamed or
     * removed property. Only a closed, plain object is judged: composition or additionalProperties
     * can supply the key from elsewhere.
     *
     * @param array<array-key, mixed> $schema
     */
    private function warnAboutRequiredNamesWithoutProperty(array $schema, string $className): void
    {
        if (array_key_exists('$ref', $schema) || array_key_exists('allOf', $schema)) {
            $this->warnAboutRequiredNamesBesideAReference($schema, $className);

            return;
        }
        $properties = $schema['properties'] ?? null;
        $required = $schema['required'] ?? null;
        if (!is_array($properties) || !is_array($required)) {
            return;
        }
        foreach (['allOf', 'oneOf', 'anyOf', 'patternProperties', '$ref'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return;
            }
        }
        $additional = $schema['additionalProperties'] ?? false;
        if ($additional !== false) {
            return;
        }
        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $properties)) {
                $this->addGenerationWarning(sprintf(
                    'Schema "%s" lists "%s" in `required`, but declares no such property. The class is '
                    . 'generated without it, so the requirement is enforced nowhere — remove it from '
                    . '`required` or declare the property.',
                    $className,
                    $name,
                ));
            }
        }
    }

    /**
     * The same warning for a schema composed from references — `{$ref: Shape, required: [bee]}`, read
     * as `allOf: [$ref Shape, {required: [bee]}]`: a name no member declares is held by no class, and
     * only a mode that reads the raw body can tell whether it was sent.
     *
     * @param array<array-key, mixed> $schema
     */
    private function warnAboutRequiredNamesBesideAReference(array $schema, string $className): void
    {
        $members = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
        $members[] = $schema;
        $declared = [];
        $required = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $ref = $member['$ref'] ?? null;
            $resolved = is_string($ref) ? $this->resolveSchemaNodeByRef($ref, $this->getSchemaSourceFile($className)) : null;
            $target = $resolved === null ? null : $resolved[0];
            foreach ([$member, $target ?? []] as $part) {
                foreach (['oneOf', 'anyOf', 'patternProperties', 'allOf'] as $keyword) {
                    if ($part !== $schema && array_key_exists($keyword, $part)) {
                        return;
                    }
                }
                if (($part['additionalProperties'] ?? false) !== false) {
                    return;
                }
                foreach (is_array($part['properties'] ?? null) ? array_keys($part['properties']) : [] as $name) {
                    $declared[] = (string)$name;
                }
                foreach (is_array($part['required'] ?? null) ? $part['required'] : [] as $name) {
                    if (is_string($name)) {
                        $required[] = $name;
                    }
                }
            }
            if (is_string($ref) && $target === null) {
                return;
            }
        }
        foreach (array_unique(array_diff($required, $declared)) as $name) {
            $this->addGenerationWarning(sprintf(
                'Schema "%s" lists "%s" in `required`, but neither it nor the schema it references declares '
                . 'such a property. The class is generated without it, so only a mode that reads the raw '
                . 'body (laravel, laravel-data, yii3) can tell whether it was sent — remove it from '
                . '`required` or declare the property.',
                $className,
                $name,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function warnAboutPcreOnlyEscapes(array $schema, string $className): void
    {
        foreach ($schema as $keyword => $value) {
            $patterns = match (true) {
                $keyword === 'pattern' && is_string($value) => [$value],
                $keyword === 'patternProperties' && is_array($value) => array_map('strval', array_keys($value)),
                default => [],
            };
            foreach ($patterns as $pattern) {
                if (preg_match('/(?<!\\\)(?:\\\\\\\)*\\\([' . self::PCRE_ONLY_ESCAPES . '])/', $pattern, $match) === 1) {
                    $this->addGenerationWarning(sprintf(
                        'Pattern "%s" in %s uses \%s, which ECMA-262 does not define; it is checked with its PCRE meaning.',
                        $pattern,
                        $className,
                        $match[1],
                    ));
                }
            }
            if (is_array($value)) {
                $this->warnAboutPcreOnlyEscapes($value, $className);
            }
        }
    }

    /**
     * Warns when a parameter describes its value in a media type nothing downstream can decode.
     *
     * A Parameter Object may carry `content` instead of `schema`, and the runtime decodes exactly two
     * things: any JSON media type, and — for OAS 3.2 `in: querystring` — a form-encoded body. Given
     * `content: {application/xml: {...}}` the generator still emits a class for the schema and the
     * deserializer still receives the raw string, so the class is one nothing can fill: a request
     * carrying it fails on the cast, and the reader is left wondering which half is wrong.
     *
     * A warning rather than an error, deliberately: the document is VALID and the class may be of use
     * on the response side or to hand-written code. What was missing is the sentence saying the
     * runtime will not fill it.
     */
    private function addGenerationWarning(string $warning): void
    {
        if (!in_array($warning, $this->generationWarnings, true)) {
            $this->generationWarnings[] = $warning;
        }
    }

    private function warnAboutUndecodableParameterContent(
        string $name,
        ?string $mediaType,
        bool $isQueryString,
    ): void {
        if ($mediaType === null || $this->isJsonMediaTypeName($mediaType)) {
            return;
        }

        $normalized = strtolower(trim(explode(';', $mediaType)[0]));
        if ($isQueryString && $normalized === 'application/x-www-form-urlencoded') {
            return;
        }

        $warning = sprintf(
            'Parameter "%s" describes its value with media type "%s", which the runtime cannot '
                . 'decode. Its class is generated, but the deserializer sees the raw string and no '
                . 'value will fill it. Only JSON media types are decoded for a parameter%s.',
            $name,
            $mediaType,
            $isQueryString ? ', plus application/x-www-form-urlencoded for `in: querystring`' : '',
        );

        if (!in_array($warning, $this->generationWarnings, true)) {
            $this->generationWarnings[] = $warning;
        }
    }

    /**
     * @param array<mixed> $pathParameters
     * @return array<string, mixed>
     */
    private function buildParameterSchema(array $pathParameters): array
    {
        $properties = [];
        $required = [];

        foreach ($pathParameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            $schema = $parameter['schema'] ?? null;

            // A parameter may serialize a complex value via `content` (e.g.
            // content: {application/json: {schema}}) instead of a plain `schema`. Extract the
            // (single) media type's schema; JSON media types are decoded at deserialization.
            $contentJson = false;
            $contentMediaType = null;
            if (!is_array($schema) && is_array($parameter['content'] ?? null)) {
                foreach ($parameter['content'] as $mediaType => $mediaTypeObject) {
                    if (is_array($mediaTypeObject) && is_array($mediaTypeObject['schema'] ?? null)) {
                        $schema = $mediaTypeObject['schema'];
                        $contentMediaType = is_string($mediaType) ? $mediaType : null;
                        $contentJson = $contentMediaType !== null && $this->isJsonMediaTypeName($contentMediaType);
                        break;
                    }
                }
            }

            if (!is_string($name) || !is_array($schema)) {
                continue;
            }

            // `deprecated`, `description` and `example` live on the Parameter Object, not on its
            // schema; carry them over (without overwriting the schema's own) so the generated DTO
            // documents a parameter the same way it documents a body property.
            foreach (['deprecated', 'description', 'example'] as $annotation) {
                if (!array_key_exists($annotation, $schema) && array_key_exists($annotation, $parameter)) {
                    $schema[$annotation] = $parameter[$annotation];
                }
            }

            $paramIn = $parameter['in'] ?? null;
            if ($paramIn === 'querystring') {
                // OAS 3.2: the value is the entire query string, always described through
                // `content`. The deserializer reads the raw string and decodes it per media type.
                $schema['x-parameter-in'] = 'querystring';
                $schema['x-parameter-style'] = $contentJson ? 'json' : 'querystring';
                $schema['x-parameter-explode'] = false;
                $this->warnAboutUndecodableParameterContent($name, $contentMediaType, true);
            } elseif (in_array($paramIn, ['path', 'query', 'header', 'cookie'], true)) {
                $this->warnAboutUndecodableParameterContent($name, $contentMediaType, false);
                $schema['x-parameter-in'] = $paramIn;
                if ($contentJson) {
                    // A content:application/json parameter arrives as a JSON string; the
                    // deserializer json_decodes it before casting. Reuse the style channel with
                    // a 'json' sentinel (not a real OpenAPI style) to carry that signal.
                    $schema['x-parameter-style'] = 'json';
                    $schema['x-parameter-explode'] = false;
                } else {
                    $schema['x-parameter-style'] = $this->resolveParameterStyle($parameter, $paramIn);
                    $schema['x-parameter-explode'] = $this->resolveParameterExplode(
                        parameter: $parameter,
                        style: $schema['x-parameter-style'],
                    );
                }
                $schema['x-parameter-allow-reserved'] = $this->toBoolean($parameter['allowReserved'] ?? false);
                // Tri-state on purpose: only an explicit `allowEmptyValue` reaches the DTO, so the
                // deserializer can tell "spec forbids an empty value" from "spec says nothing".
                if (array_key_exists('allowEmptyValue', $parameter)) {
                    $schema['x-parameter-allow-empty-value'] = $this->toBoolean($parameter['allowEmptyValue']);
                }
            }

            // "A unique parameter is defined by a combination of a name and location", so `id` in the
            // path and `id` in the query are two parameters — but they cannot both be `$id`. Keyed by
            // the name alone, the second silently overwrote the first and one of them vanished from
            // the class. The later one takes a key suffixed with its location instead, carrying the
            // real wire name in `x-parameter-name` for the alias and the request lookup to use. The
            // FIRST one keeps the plain key, so nothing moves for the documents where the question
            // never arises.
            $propertyKey = $name;
            if (array_key_exists($propertyKey, $properties)) {
                $suffix = is_string($paramIn) ? ucfirst($paramIn) : 'Duplicate';
                $propertyKey = $name . $suffix;
                $counter = 2;
                while (array_key_exists($propertyKey, $properties)) {
                    $propertyKey = $name . $suffix . $counter;
                    $counter++;
                }

                $schema['x-parameter-name'] = $name;
            }

            $properties[$propertyKey] = $schema;

            $isPathParam = $paramIn === 'path';
            $isRequired = $this->toBoolean($parameter['required'] ?? false);

            // OpenAPI path parameters are always required even in malformed specs.
            if ($isPathParam || $isRequired) {
                $required[] = $propertyKey;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values(array_unique($required)),
        ];
    }

    /**
     * Resolves the OpenAPI serialization `style` for a parameter, applying the
     * spec defaults when absent: `form` for query/cookie, `simple` for path/header.
     *
     * @param array<string, mixed> $parameter
     */
    private function resolveParameterStyle(array $parameter, string $paramIn): string
    {
        $style = $parameter['style'] ?? null;
        if (is_string($style) && $style !== '') {
            return $style;
        }

        return in_array($paramIn, ['query', 'cookie'], true) ? 'form' : 'simple';
    }

    /**
     * Whether a media type carries JSON (application/json or any structured +json suffix),
     * so a content-typed parameter's string value should be JSON-decoded before validation.
     */
    private function isJsonMediaTypeName(string $mediaType): bool
    {
        $normalized = strtolower(trim(explode(';', $mediaType)[0]));

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }

    /**
     * Resolves the OpenAPI `explode` flag. The spec default is `true` only when the
     * style is `form` or OAS 3.2's `cookie`, and `false` for every other style.
     *
     * @param array<string, mixed> $parameter
     */
    private function resolveParameterExplode(array $parameter, string $style): bool
    {
        if ($style === 'deepObject') {
            // Per RFC6570, deepObject is always exploded (nested brackets like filter[name]=value).
            // OpenAPI spec allows explicit explode: false for deepObject, but it's ignored in practice.
            // deepObject cannot be non-exploded per the spec.
            return true;
        }

        if (array_key_exists('explode', $parameter)) {
            return $this->toBoolean($parameter['explode']);
        }

        return $style === 'form' || $style === 'cookie';
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function resolveOutputDirectory(string $directory): string
    {
        $normalized = str_replace('\\', '/', $directory);

        if (str_starts_with($normalized, '/')) {
            return rtrim($normalized, '/');
        }

        $cwd = getcwd();
        $workingDirectory = $cwd !== false ? $cwd : '.';
        return rtrim($workingDirectory . '/' . ltrim($normalized, '/'), '/');
    }

    private function directoryToNamespace(string $directory): string
    {
        $normalized = trim(str_replace('\\', '/', $directory), '/');

        if ($normalized === '') {
            return 'Generated';
        }

        $segments = array_filter(explode('/', $normalized), static fn(string $segment): bool => $segment !== '');
        $namespaceParts = [];

        foreach ($segments as $segment) {
            $namespaceParts[] = $this->normalizeNamespaceSegment($segment);
        }

        return implode('\\', $namespaceParts);
    }

    private function normalizeNamespaceSegment(string $segment): string
    {
        $splitResult = preg_split('/[^A-Za-z0-9]+/', $segment);
        $parts = $splitResult !== false ? $splitResult : [];
        $normalized = implode(
            separator: '',
            array: array_map(static fn(string $part): string => ucfirst(strtolower($part)), array_filter($parts)),
        );

        if ($normalized === '') {
            return 'Generated';
        }

        if (is_numeric($normalized[0])) {
            return 'Value' . $normalized;
        }

        return $normalized;
    }

    /**
     * The namespace is written verbatim into `namespace …;` and every `use …;` of the generated files,
     * so anything that is not a namespace name — `Zoo;echo "x";//` — became code that ran on autoload.
     * The empty name is the global namespace.
     */
    private function assertNamespaceName(string $namespace, string $source): string
    {
        if ($namespace !== '' && preg_match(self::NAMESPACE_NAME_PATTERN, $namespace) !== 1) {
            throw new RuntimeException(sprintf(
                '%s "%s" is not a PHP namespace name: segments of letters, digits and underscores, not starting with a digit, separated by single backslashes.',
                $source,
                $namespace,
            ));
        }

        return $namespace;
    }

    private function normalizeExplicitNamespace(string $namespace): string
    {
        return trim(trim($namespace), '\\');
    }
}
