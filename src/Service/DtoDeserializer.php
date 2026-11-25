<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use JsonException;
use JsonSerializable;
use OpenapiPhpDtoGenerator\Contract\DtoDeserializerInterface;
use OpenapiPhpDtoGenerator\Contract\DtoValidatorInterface;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Contract\JsonShapeAwareValidatorInterface;
use PhpToken;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use UnitEnum;
use WeakMap;

use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_pop;
use function array_unique;
use function array_values;
use function call_user_func;
use function class_exists;
use function count;
use function ctype_space;
use function end;
use function enum_exists;
use function explode;
use function file_get_contents;
use function floor;
use function gettype;
use function implode;
use function in_array;
use function ini_get;
use function is_a;
use function is_array;
use function is_bool;
use function is_callable;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function lcfirst;
use function ltrim;
use function max;
use function method_exists;
use function ord;
use function parse_str;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function preg_split;
use function rawurldecode;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strpbrk;
use function strpos;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function ucfirst;
use function urldecode;

final class DtoDeserializer implements DtoDeserializerInterface
{
    private const string PREDECODED_BODY_ATTRIBUTE = '__opg_predecoded_body_data';

    // A union whose branches are `format: date` and `format: date-time` — one getter, two wire shapes.
    // Not an OpenAPI format: it only ever travels from resolveOpenApiFormat() to the temporal parser.
    private const string FORMAT_DATE_OR_DATE_TIME = 'date|date-time';

    // How deep the union readers below follow a branch into its own `allOf`/`oneOf`/`anyOf`: far
    // beyond any document written by hand, and a bound on a schema that nests without end.
    private const int MAX_UNION_BRANCH_DEPTH = 16;

    /**
     * The characters PHP's request parser turns into `_` in a top-level name, for `$_GET`, `$_POST`
     * and `$_COOKIE` alike: `page.size` arrives as `page_size`.
     */
    private const string PHP_RENAMED_NAME_CHARACTERS = '. ';

    /**
     * PHP's own defaults for the two input limits the raw re-reads honour, for an ini that reports
     * none — see `inputPairs()` and `assignRawQueryValue()`.
     */
    private const int DEFAULT_MAX_INPUT_VARS = 1000;

    private const int DEFAULT_MAX_INPUT_NESTING_LEVEL = 64;

    // -----------------------------------------------------------------------
    // Static per-class reflection caches (populated once, shared across all
    // instances and requests within the same PHP worker process).
    //
    // Safe under a long-running runtime (Swoole / RoadRunner / FrankenPHP), and the
    // reason is idempotence rather than locking: every entry is derived from the
    // CLASS alone — reflection, the emitted metadata constants, the schema literals —
    // so two coroutines racing on the first request for the same class compute the
    // same value and write the same value. There is no read-modify-write anywhere in
    // here, so a lost update cannot lose anything. What must NOT move into this block
    // is anything derived from a REQUEST; decoded payloads are local to each call.
    // -----------------------------------------------------------------------

    /** @var array<class-string, ReflectionClass<object>> */
    private static array $reflectionCache = [];

    /**
     * Comprehensive per-class DTO metadata: constructor params + inRequest flag properties.
     * Key: class-string
     *
     * @var array<class-string, array{
     *   hasConstructor: bool,
     *   plainBodyOnly: bool,
     *   jsonOnlyBody: bool,
     *   params: list<array{
     *     name: string,
     *     requestFieldName: string,
     *     typeNames: list<string>,
     *     allowsNull: bool,
     *     isRequired: bool,
     *     hasDefaultValue: bool,
     *     defaultValue: mixed,
     *     schemaAllowsNull: bool,
     *     arrayItemType: string|null,
     *     nestedArrayItemType: string|null,
     *     openApiFormat: string|null,
     *     allowsAssociativeArray: bool,
     *     alsoAllowsJsonList: bool,
     *     temporalFormat: string|null,
     *     arrayItemTemporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *     readOnly: bool,
     *     sourceConstraint: string|null,
     *     arrayDelimiter: non-empty-string|null,
     *     parameterStyle: string|null,
     *     parameterExplode: bool|null,
     *     allowReserved: bool,
     *     allowEmptyValue: bool|null,
     *     contentJson: bool,
     *     formEncodedString: bool,
     *     arrayItemsNullable: bool,
     *     nestedArrayItemsNullable: bool,
     *   }>,
     *   inRequestProperties: array<string, ReflectionProperty|null>,
     *   inPathProperties: array<string, ReflectionProperty|null>,
     *   inQueryProperties: array<string, ReflectionProperty|null>,
     *   inHeaderProperties: array<string, ReflectionProperty|null>,
     *   inCookieProperties: array<string, ReflectionProperty|null>,
     *   requestFieldNames: array<string, true>,
     * }>
     */
    private static array $dtoMetaCache = [];

    /**
     * Enum cases cache to avoid repeated reflection per castToEnum call.
     * Key: enum class-string → list of UnitEnum cases
     *
     * @var array<class-string, list<UnitEnum>>
     */
    private static array $enumCasesCache = [];

    /** @var array<class-string, array<string, array<string, mixed>>> */
    private static array $constraintsCache = [];

    /**
     * What the fast path can work out ONCE per class: whether the class has its own hydrator, and the
     * parameter metadata keyed by request field name. Deliberately not the casting closure — that one
     * is bound to a DtoDeserializer, and a process-wide cache handed the second instance's work to the
     * first instance's validator.
     *
     * @var array<class-string, array{byName: array<string, mixed>}|null>
     */
    private static array $fastCastMetaCache = [];

    /** @var array<class-string, array<string, mixed>> Schema-level keywords, per class. */
    private static array $objectConstraintsCache = [];

    /** @var array<class-string, array<string, string>> */
    private static array $aliasesCache = [];

    /**
     * Maps a declared type name to its cast "kind", computed once per type name per
     * process. Avoids enum_exists()/class_exists() calls on every cast value/element.
     *
     * @var array<string, string>
     */
    private static array $typeKindCache = [];

    /**
     * Cache of `use` imports parsed from each DTO source file, keyed by absolute filename.
     *
     * @var array<string, array<string, string>>
     */
    private static array $fileImportsCache = [];

    private DtoValidatorInterface $constraintValidator;

    /**
     * How many validations so far ended with the regex engine giving up on a check. A list walk
     * compares it before and after a member: a member that raised it settles the request, and each
     * member after it would burn the whole backtrack limit again. Counted from the validator's flag,
     * at any depth the member's hydration reached — the refusal's MESSAGE carries the member names the
     * request chose, so a key spelling the give-up stopped the walk on an ordinary mismatch.
     */
    private int $engineGaveUpCount = 0;

    /**
     * How many union trials are open on the stack — see `castUnionValue()`.
     */
    private int $unionTrialDepth = 0;

    /**
     * What each JSON object came to when hydrated as a given class, while a union trial is open.
     *
     * A union with no discriminator is resolved by trying its branches in turn, and a branch that
     * fails still hydrates everything below it — errors are collected, not raised on the first one.
     * In a recursive union (`A.c` and `B.c` both `A|B`) every level therefore hydrated its subtree
     * once per branch of every level above it: 2^depth, a 300-byte body tying a worker up for
     * minutes. The answer for one object as one class does not depend on which outer branch asked,
     * so it is kept and reused, and each (object, class) is hydrated once — linear again, and the
     * branch chosen is the one chosen before, because every answer is the one it would have been.
     *
     * Keyed by the decoded `stdClass` itself, in a WeakMap: an object id can be reused once the
     * object is gone, an object key cannot. Dropped when the outermost trial ends, so nothing is
     * carried from one value — or one request — to the next.
     *
     * @var WeakMap<stdClass, array<string, object>>|null
     */
    private ?WeakMap $unionTrialResults = null;

    /**
     * The same memory for an object that arrived as a PHP array — form fields, a `deepObject` query
     * parameter, a pre-decoded body — where PHP nests a name up to 64 levels and a recursive union
     * cost 2^depth all the same.
     *
     * An array has no identity to key by, and two sibling values with the same content must still
     * become two DTOs, so the key is where the object sits: the path of nested objects opened since
     * the outermost trial began (`$unionTrialPath`), its own path, the class. Paths are joined with
     * dots, and a map key the client chose may hold a dot itself, so the remembered input is
     * compared as well: an answer is reused only for the very content it was given — which is
     * also why reuse cannot change an answer.
     *
     * @var array<string, array{data: array<string, mixed>, outcome: object}>
     */
    private array $unionTrialArrayResults = [];

    /**
     * The paths of the nested objects being hydrated inside the open union trials, outermost first.
     *
     * @var list<string>
     */
    private array $unionTrialPath = [];

    /**
     * Whether the values being cast come from a body this class decoded itself. It decodes with every
     * JSON object kept a `stdClass`, so an empty PHP array there can only be the JSON array `[]`:
     * refused where an object is asked for, and no object to the object keywords. A value handed in
     * already decoded ({@see deserializeValue()}) may be `{}` decoded associatively, and keeps the
     * old reading. Saved and restored around each body, so a nested call leaves it as it was.
     */
    private bool $jsonShapesKnown = false;

    /**
     * {@see $constraintValidator} reading an empty array as `[]`, built on first use.
     */
    private ?JsonShapeAwareValidatorInterface $jsonShapesValidator = null;

    public function __construct(
        ?DtoValidatorInterface $constraintValidator = null,
    ) {
        $this->constraintValidator = $constraintValidator ?? new DtoValidator();
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object
    {
        $this->convertParametersToAttributes($request);

        // The raw query string is only needed by `allowReserved` parameters, which most documents
        // never declare — parsing it eagerly cost every request a second pass over the query string
        // (measured: +50% on a small DTO with a ten-parameter query). Hence a memoized provider:
        // built at most once per request, and only if a parameter actually asks for it.
        /** @var array<string, mixed>|null $rawQueryData */
        $rawQueryData = null;
        $rawQueryProvider = function () use ($request, &$rawQueryData): array {
            return $rawQueryData ??= $this->getRawQueryData($request);
        };

        // Pre-fetch the remaining request data sources once — avoids N per-parameter allocations.
        // The body is decoded here, its JSON objects kept as `stdClass`: an empty array in it can only
        // be `[]` — see `$jsonShapesKnown`.
        $outerShapesKnown = $this->jsonShapesKnown;
        $this->jsonShapesKnown = true;
        try {
            /** @var T $dto */
            $dto = $this->deserializeInternal(
                dtoClass: $dtoClass,
                bodyData: $this->getBodyData($request),
                queryData: $request->query->all(),
                rawQueryProvider: $rawQueryProvider,
                formData: $request->request->all(),
                request: $request,
                bodySource: 'json',
            );
        } finally {
            $this->jsonShapesKnown = $outerShapesKnown;
        }

        return $dto;
    }

    /**
     * The deserializer reads path parameters from the request attribute bag. Some Request
     * implementations instead expose them on a route object via a `route()->parameters()` API
     * (e.g. frameworks built on top of the Symfony Request) and leave the attribute bag untouched.
     * Mirror those into the attribute bag so path params resolve the same way everywhere.
     *
     * Detected purely by duck-typing (`method_exists`) so no extra package is required: a plain
     * Symfony Request has no `route()` method and this is a single no-op check. Existing attributes
     * are never overwritten — a value already in the bag keeps precedence.
     */
    private function convertParametersToAttributes(Request $request): void
    {
        if (!method_exists($request, 'route')) {
            return;
        }

        $route = $request->route();
        if (!is_object($route) || !method_exists($route, 'parameters')) {
            return;
        }

        $parameters = $route->parameters();
        if (!is_array($parameters)) {
            return;
        }

        foreach ($parameters as $name => $value) {
            if (is_string($name) && !$request->attributes->has($name)) {
                $request->attributes->set($name, $value);
            }
        }
    }

    /**
     * Whether a `Content-Type` names a JSON document.
     *
     * `application/json` and every structured-suffix type built on it: `application/merge-patch+json`
     * (RFC 7396, which is what a PATCH endpoint usually sends), `application/hal+json`,
     * `application/vnd.acme.v1+json`. Until 2.15.18 the check was `str_contains(…, 'application/json')`,
     * so all of those read as "not JSON": the body was never decoded and every required property came
     * back "not found in request" — a whole endpoint dead for a header the document never forbade.
     * `DtoValidator` already had this rule for `contentMediaType`; this is the same rule, not a second
     * one.
     */
    private function isJsonMediaType(string $contentType): bool
    {
        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }

    /**
     * Deserializes a top-level JSON array request body (e.g. a bulk endpoint whose
     * `requestBody` schema is `type: array`) into a list of items, one per element.
     *
     * The standard {@see deserialize()} entry point requires the JSON root to be an
     * object; a collection body has an array root instead, so it needs this dedicated
     * path. `$itemType` is the OpenAPI items type and may be a scalar keyword
     * (`int`/`float`/`string`/`bool`), an enum class, a DTO class (discriminator
     * aware) or `DateTimeImmutable`; each element is cast and validated exactly like a
     * nested array item. An empty array yields `[]`.
     *
     * `$itemsNullable` mirrors `items: {nullable: true}`; `$itemTemporalFormat` mirrors the items
     * `format` — 'Y-m-d' for `format: date`, null for date-time. A bare `$itemType` has no owning
     * DTO property to infer either from, so both are passed in.
     *
     * The return type follows `$itemsNullable`, so leaving it off keeps the elements non-null for
     * your static analysis and turning it on forces you to null-check them. Keep the `$itemType`
     * condition OUTERMOST — nesting the other way loses the template binding and degrades T to
     * `object`.
     *
     * @template T of object
     * @param class-string<T>|string $itemType
     * @return ($itemType is class-string<T>
     *     ? ($itemsNullable is true ? array<int, T|null> : array<int, T>)
     *     : array<int, mixed>)
     */
    public function deserializeCollection(
        Request $request,
        string $itemType,
        bool $itemsNullable = false,
        ?string $itemTemporalFormat = null,
    ): array {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $contentType = (string)$request->headers->get('Content-Type', '');
        if (!$this->isJsonMediaType($contentType)) {
            throw new RuntimeException('Collection body must be application/json.');
        }

        // Decode without assoc so JSON objects stay stdClass — lets each element reuse
        // the same object/array distinction the per-item caster relies on.
        $decoded = json_decode($content, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Json is not valid: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('JSON body must be an array, got ' . gettype($decoded));
        }

        $result = [];
        $errors = [];
        // Decoded here, so an empty array in it is `[]` — see `$jsonShapesKnown`.
        $outerShapesKnown = $this->jsonShapesKnown;
        $this->jsonShapesKnown = true;
        try {
            foreach ($decoded as $index => $element) {
                $gaveUpBefore = $this->engineGaveUpCount;
                try {
                    // Reuse the array-item caster so scalars, enums, datetimes and DTOs
                    // (with discriminator support) all behave like nested array elements.
                    $result[] = $this->castArrayItemValue(
                        itemValue: $element,
                        arrayItemType: $itemType,
                        itemPath: (string)$index,
                        source: 'json',
                        itemsNullable: $itemsNullable,
                        arrayItemTemporalFormat: $itemTemporalFormat,
                    );
                } catch (RuntimeException $e) {
                    foreach (explode("\n", $e->getMessage()) as $message) {
                        $errors[] = sprintf('element %d: %s', $index, $message);
                    }
                    // The stop {@see castArrayItems()} makes, for the same reason: the body is refused
                    // either way, and each further element would burn the backtrack limit again.
                    if ($this->engineGaveUpCount > $gaveUpBefore) {
                        break;
                    }
                }
            }
        } finally {
            $this->jsonShapesKnown = $outerShapesKnown;
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique(DtoServiceSupport::finalizeMessages($errors, 'param "'))));
        }

        return $result;
    }

    /**
     * Deserializes a single already-decoded JSON value into $type, bypassing the Request.
     *
     * The same cast one element of a collection body goes through, reachable on its own:
     * $data is a value produced by json_decode($json, false) — stdClass for an object, a
     * list for an array, a scalar otherwise — and $type may be a scalar keyword, an enum
     * class, a DTO class (discriminator aware) or DateTimeImmutable.
     *
     * An empty PHP array is read as the empty OBJECT here: a caller that decoded associatively
     * hands `{}` over that way, and nothing tells it from `[]`. A body {@see deserialize()} or
     * {@see deserializeCollection()} decodes itself refuses `[]` where an object is asked for.
     *
     * {@see deserializeCollection()} aggregates every element error into one exception, so
     * a single bad element fails the whole body. A batch endpoint that must accept the good
     * elements and report the bad ones by index calls this per element instead and catches
     * each RuntimeException itself.
     *
     * $path names the value in every error message, so pass the element's position ('3',
     * 'items.3') when there is one; the default reads `param "value"`.
     *
     * $nullable and $temporalFormat are the per-value form of the two on
     * {@see deserializeCollection()}, and the return type follows $nullable the same way.
     *
     * Errors on this path read `Required parameter "value.id" not found in request.` — there is no
     * request here; the text comes from the shared core, where it is correct. Read it as "in the
     * payload".
     *
     * @template T of object
     * @param class-string<T>|string $type
     * @return ($type is class-string<T> ? ($nullable is true ? T|null : T) : mixed)
     */
    public function deserializeValue(
        mixed $data,
        string $type,
        string $path = 'value',
        bool $nullable = false,
        ?string $temporalFormat = null,
    ): mixed {
        return $this->castArrayItemValue(
            itemValue: $data,
            arrayItemType: $type,
            itemPath: $path,
            source: 'json',
            itemsNullable: $nullable,
            arrayItemTemporalFormat: $temporalFormat,
        );
    }

    /**
     * Deserializes a nested DTO directly from an already-split array, bypassing the
     * Symfony Request entirely. A nested object has no path/query/form/file sources of its own
     * — this avoids allocating a fresh Request + initialize() for every nested object.
     *
     * `$source` is where the OUTER value came from, and it decides how the fields are read. A JSON
     * body carries its own types, so `"1"` stays a string there. Everything else — a deepObject
     * `?rng[lo]=1`, a `r=1,g=2` path or header object, a form body's `s[lo]=1` — reaches here as
     * strings, because a URL, a header and a form field have no other type. Reading those as JSON
     * refused every typed field one level down (`rng.lo expects int, got string`), while the same
     * value one level up was converted. So a non-JSON source keeps its conversion all the way in;
     * it travels as `form`, the one string source with no style of its own, so no path or query
     * style rule meant for the parameter is applied a second time to its fields.
     *
     * @template T of object
     * @param array<string, mixed> $data
     * @param class-string<T> $dtoClass
     * @return T
     */
    private function deserializeFromArray(array $data, string $dtoClass, string $source): object
    {
        /** @var T $dto */
        $dto = $this->deserializeInternal(
            dtoClass: $dtoClass,
            bodyData: $data,
            queryData: [],
            rawQueryProvider: static fn(): array => [],
            formData: [],
            request: null,
            bodySource: $source === 'json' ? 'json' : 'form',
        );

        return $dto;
    }

    /**
     * Shared deserialization core. `$request` is null for nested array deserialization
     * (no path attributes or uploaded files in that case).
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param Closure(): array<string, mixed> $rawQueryProvider raw (plus-preserving) query data,
     *        built on first use — see `deserialize()`
     * @param array<string, mixed> $formData
     * @param class-string $dtoClass
     * @param string $bodySource what a `$bodyData` value is cast as: `json` for a body, `form` for
     *        the fields of a nested object that came from a string source — see `deserializeFromArray()`
     */
    private function deserializeInternal(
        string $dtoClass,
        array $bodyData,
        array $queryData,
        Closure $rawQueryProvider,
        array $formData,
        ?Request $request,
        string $bodySource,
    ): object {
        // Use cached ReflectionClass – avoids repeated object instantiation.
        $reflection = self::$reflectionCache[$dtoClass] ??= new ReflectionClass($dtoClass);

        // Build (or retrieve from cache) all class-level metadata so that the
        // expensive reflection + file-reading work is done only on the very first
        // deserialization of a given DTO class within this PHP worker process.
        if (!array_key_exists($dtoClass, self::$dtoMetaCache)) {
            self::$dtoMetaCache[$dtoClass] = $this->buildDtoMeta(reflection: $reflection, dtoClass: $dtoClass);
        }
        $classMeta = self::$dtoMetaCache[$dtoClass];

        // A body the document accepts as JSON only is read from the JSON body only. The lookup below
        // otherwise falls back to the query string and the form fields for a key the body lacks —
        // right for a class nothing says more about, and a hole for this one: a cross-site
        // `<form method="post">` is a CORS "simple" request, sent with the victim's cookies and no
        // preflight, and it filled an endpoint whose only declared body was `application/json`,
        // defeating the "we only take JSON" CSRF defence. With no other source, such a request now
        // fails as what it is — a JSON body that is not there. Clearing both is safe because the
        // class binds nothing to the query (`jsonOnlyBody` excludes any bound parameter); path
        // attributes still lead, as they do for every class. Uploaded files go with the form fields
        // they arrive beside: a JSON body holds no file, and a property typed as one (`format:
        // binary`) was otherwise filled by the same cross-site multipart post.
        $readsUploadedFiles = true;
        if ($request !== null && $classMeta['jsonOnlyBody']) {
            $queryData = [];
            $formData = [];
            $readsUploadedFiles = false;
        }

        if (!$classMeta['hasConstructor']) {
            throw new RuntimeException(
                message: "DTO {$dtoClass} has no constructor.",
            );
        }

        $args = [];
        $providedParamSources = [];
        $errors = [];
        /** @var array<string, true> $emptyJsonObjectFields */
        $emptyJsonObjectFields = [];

        // `additionalProperties: false` closes the object. Runtime mode is the one mode holding the
        // RAW body at this point, so it is the one mode that can see a key the schema never
        // declared — a hydrating mode drops it on the way into a typed property and never learns it
        // was there. Only body keys are compared: query, header and cookie carry their own keys by
        // definition, and the document does not close those.
        $closingConstraints = $this->resolveOpenApiObjectConstraints($reflection);
        $undeclared = [];
        $extraPropertySchema = $closingConstraints['additionalProperties'] ?? null;
        if ($extraPropertySchema === false || is_array($extraPropertySchema)) {
            $declared = [];
            foreach ($classMeta['params'] as $declaredParam) {
                $declared[$declaredParam['requestFieldName']] = true;
            }

            // A key matched by `patternProperties` is NOT additional — JSON Schema evaluates the
            // patterns before deciding, and `DtoValidator` has said so all along for a nested object.
            // The root check here compared against the declared parameters alone, so a document
            // pairing `additionalProperties: false` with `patternProperties` had its own conformant
            // payload refused: `{"s":"a","x_ok":"b"}` against `^x_` came back with
            // `Unknown property "x_ok"`. A false rejection, and the one shape where being strict is
            // simply wrong.
            $patterns = [];
            $declaredPatterns = $closingConstraints['patternProperties'] ?? null;
            if (is_array($declaredPatterns)) {
                foreach (array_keys($declaredPatterns) as $pattern) {
                    if (is_string($pattern)) {
                        $patterns[] = $pattern;
                    }
                }
            }

            // No guard on the key type: a JSON key that looks like an integer arrives as one
            // (`{"0":…}` becomes `0`), and `%s` renders it the way the client wrote it.
            foreach (array_keys($bodyData) as $bodyKey) {
                if (array_key_exists($bodyKey, $declared)) {
                    continue;
                }

                if ($this->bodyKeyMatchesPattern($bodyKey, $patterns)) {
                    continue;
                }

                // A schema rather than `false`: the undeclared key is allowed, its value must match,
                // and the DTO keeps it — see `withUndeclaredProperties()`.
                if (is_array($extraPropertySchema)) {
                    $extraDtoClass = $extraPropertySchema['x-php-dto'] ?? null;
                    if (is_string($extraDtoClass) && $bodyData[$bodyKey] === null && ($extraPropertySchema['nullable'] ?? null) === true) {
                        // The component admits null: nothing to hydrate.
                    } elseif (is_string($extraDtoClass) && class_exists($extraDtoClass)) {
                        // A generated class checks the value itself, at every depth it holds itself.
                        try {
                            $hydrated = $this->castArrayItemValue(
                                itemValue: $bodyData[$bodyKey],
                                arrayItemType: $extraDtoClass,
                                // A digit key arrives as an int, whatever the array is typed as.
                                itemPath: "{$bodyKey}",
                                source: $bodySource,
                            );
                            // A PHP array spells every empty object `[]`, at any depth: kept as given,
                            // the inner ones went out as JSON lists. The class writes them as it reads them.
                            if (is_array($bodyData[$bodyKey]) && $hydrated instanceof JsonSerializable) {
                                $extraValue = json_decode(json_encode($hydrated, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), flags: JSON_THROW_ON_ERROR);
                            }
                        } catch (RuntimeException $extraException) {
                            $errors[] = $extraException->getMessage();
                        }
                    } else {
                        foreach ($this->listsWhereObjectsBelong(value: $bodyData[$bodyKey], constraints: $extraPropertySchema, paramPath: "{$bodyKey}") as $extraError) {
                            $errors[] = $extraError;
                        }
                        foreach ($this->validateValue('param "' . $bodyKey . '"', $bodyData[$bodyKey], $extraPropertySchema) as $extraError) {
                            $errors[] = $extraError;
                        }
                    }
                    $undeclared[$bodyKey] = $this->keptExtraValue(value: $extraValue ?? $bodyData[$bodyKey], schema: $extraPropertySchema);
                    $extraValue = null;
                    continue;
                }

                $errors[] = sprintf('Unknown property "%s" is not allowed by the schema.', $bodyKey);
            }
        }

        // The rest of what the document says about the OBJECT rather than about one property:
        // `minProperties`, `dependentRequired`, `not`, a top-level conditional. The RAW body is the
        // only thing these can be asked about — a per-property rule cannot express any of them — and
        // until 2.15.16 this mode emitted them nowhere and checked none, while Symfony and yii3
        // refused the same payloads. `properties` and `required` are not among them by construction
        // (see `RendersRuntimeDto::resolveObjectConstraints()`), so nothing here reports twice.
        $objectConstraints = $this->resolveOpenApiObjectConstraints($reflection);
        unset($objectConstraints['additionalProperties']);
        if ($objectConstraints !== []) {
            // `body`, not an empty subject. These messages are composed as `"{subject}.{name}"` and
            // `"{subject} is …"`, so an empty one produced `.b is required.` and
            // ` is not allowed by the schema.` — a stray separator where the name of the thing should
            // be. The root of a request has no property name of its own, so it needs one given to it,
            // and `body` is the word the neighbouring messages already use
            // ("JSON body must be an object").
            // As an OBJECT: the body is one by construction, and an empty or 0..n-keyed one is a PHP
            // list once decoded, which the object keywords would read as a JSON array and ignore.
            foreach ($this->validateValue('body', (object)$bodyData, $objectConstraints) as $objectError) {
                $errors[] = $objectError;
            }
        }

        // A class whose every property is a plain body field — no bound source, no serialization
        // style, no delimiter, no content/form decoding, no empty-value rule. Computed once per
        // class; see `buildDtoMeta()`.
        $plainBodyOnly = $classMeta['plainBodyOnly'];

        // The generated straight-line hydrator, when this class has one AND the body is provably the
        // only source this request carries. It holds structure only — every decision about a VALUE
        // travels back here through the closure, so the two routes cannot disagree. Placed AFTER the
        // closed-object check and seeded with whatever it found: an undeclared key is a property of
        // the PAYLOAD, not of any one value, so neither route should have to rediscover it.
        // The generated hydrator casts as JSON, so it is for a JSON body only.
        if ($plainBodyOnly && $bodySource === 'json' && $queryData === [] && $formData === []) {
            $fast = $this->resolveFastHydrator($reflection, $classMeta, $request, $bodyData);
            if ($fast !== null) {
                try {
                    return $this->withUndeclaredKeysNoted(
                        dto: $this->withUndeclaredProperties(dto: $fast($errors), undeclared: $undeclared),
                        bodyData: $bodyData,
                        requestFieldNames: $classMeta['requestFieldNames'],
                    );
                } catch (RuntimeException $e) {
                    throw $this->withUnreadableBodyHint($e, $request, $bodyData, $queryData, $formData);
                }
            }
        }

        // Parsed at most once each, and only if some array-typed parameter actually reads that
        // source.
        /** @var array<string, list<string>>|null $queryValueLists */
        $queryValueLists = null;
        /** @var array<string, list<string>>|null $reservedQueryValueLists */
        $reservedQueryValueLists = null;
        // `false` is the memoized "this body cannot be recovered from" — distinct from `null`,
        // which still means "not looked at yet".
        /** @var array<string, list<string>>|false|null $formValueLists */
        $formValueLists = null;

        foreach ($classMeta['params'] as $paramMeta) {
            $requestFieldName = $paramMeta['requestFieldName'];

            $rawSource = '';
            $rawWasProvided = false;
            if ($plainBodyOnly) {
                // The SAME five checks `resolveRawRequestValue()` makes, in the same order, minus
                // the call and its by-reference plumbing. Inlined rather than guarded by
                // preconditions on purpose: an earlier attempt enumerated "when is the body the
                // only source" and missed `$request->files`, which is exactly the kind of silent
                // behaviour change this shape cannot produce. `plainBodyOnly` guarantees
                // `sourceConstraint === null` for every property, so the resolver's first branch —
                // the only one omitted here — is unreachable.
                if ($request !== null && $request->attributes->has($requestFieldName)) {
                    $rawWasProvided = true;
                    $rawSource = 'path';
                    $rawValue = $request->attributes->get($requestFieldName);
                } elseif (array_key_exists($requestFieldName, $bodyData)) {
                    $rawWasProvided = true;
                    $rawSource = $bodySource;
                    $rawValue = $bodyData[$requestFieldName];
                } elseif ($readsUploadedFiles && $request !== null && $request->files->has($requestFieldName)) {
                    // Files before query, for the reason spelled out in `resolveRawRequestValue()`:
                    // a query string is never an upload, so `?avatar=x` must not stand in for the
                    // multipart `avatar`. The two routes MUST agree, which is why the same swap is made
                    // in both — an earlier fix that touched only the resolver left this branch taking
                    // the query string, and the measurement did not budge.
                    $rawWasProvided = true;
                    $rawSource = 'files';
                    $rawValue = $request->files->get($requestFieldName);
                } elseif (array_key_exists($requestFieldName, $queryData)) {
                    $rawWasProvided = true;
                    $rawSource = 'query';
                    $rawValue = $queryData[$requestFieldName];
                } elseif (array_key_exists($requestFieldName, $formData)) {
                    $rawWasProvided = true;
                    $rawSource = 'form';
                    $rawValue = $formData[$requestFieldName];
                } else {
                    $rawValue = null;
                }
            } else {
                $rawValue = $this->resolveRawRequestValue(
                    request: $request,
                    paramName: $requestFieldName,
                    bodyData: $bodyData,
                    queryData: $queryData,
                    rawQueryProvider: $rawQueryProvider,
                    formData: $formData,
                    sourceConstraint: $paramMeta['sourceConstraint'],
                    allowReserved: $paramMeta['allowReserved'],
                    wasProvided: $rawWasProvided,
                    source: $rawSource,
                    bodySource: $bodySource,
                    readsUploadedFiles: $readsUploadedFiles,
                );
            }

            // OAS 3.2 `style: cookie` sends a value as RFC 6265 spells it — never percent-encoded — and
            // an exploded list by repeating the name: `ids=1; ids=2`. `$_COOKIE`, and Symfony's cookie
            // bag with it, decodes every value and keeps only the first repeat, so both are read back
            // from the `Cookie` header itself. A request without the header — one built from a cookie
            // bag — has only the bag's value to offer.
            if ($request !== null && $rawWasProvided && $rawSource === 'cookie' && $paramMeta['parameterStyle'] === 'cookie') {
                $headerValues = $this->findRawCookieValues(request: $request, name: $requestFieldName);
                if ($paramMeta['arrayItemType'] !== null && !$paramMeta['allowsAssociativeArray'] && $paramMeta['parameterExplode'] === true) {
                    $rawValue = $headerValues !== [] ? $headerValues : (is_string($rawValue) ? [$rawValue] : $rawValue);
                    if ($rawValue === ['']) {
                        $rawValue = [];
                    }
                } elseif ($headerValues !== []) {
                    $rawValue = $headerValues[0];
                }
            }

            // OpenAPI's DEFAULT array serialization is `style: form, explode: true` — the key
            // REPEATED: `?ids=1&ids=2` in a query string, `tags=a&tags=b` in a form body. PHP does
            // not keep the repeats. `$_GET` and `$_POST` — and Symfony's `$request->query` and
            // `$request->request`, which read them — are built the way `parse_str()` builds them,
            // where the LAST occurrence wins, so the value arrives as the string `"2"`. Only PHP's
            // own `ids[]=1&ids[]=2` spelling arrayifies, and that spelling is a PHP convention no
            // OpenAPI document can state. So a conformant client — anything generated from the
            // spec — sent the documented form and got back `expects array, got string`.
            //
            // The repeats are still in the raw text (the QUERY_STRING, or the body for a urlencoded
            // form), so they are read back from there for exactly this shape: an array-typed
            // parameter with no delimiter to split on (`form`+`explode: true`, or a class that
            // declares no styles at all). Excluded on purpose:
            //   - a SCALAR parameter keeps `parse_str()` semantics (`?page=1&page=2` is 2). The
            //     document cannot say which repeat was meant, and a list would break its cast;
            //   - a MAP (`allowsAssociativeArray`) and `deepObject` arrive through the bracket
            //     spelling, already arrayified — re-reading would double them;
            //   - a delimited style (`form`+`explode: false`, `spaceDelimited`, `pipeDelimited`,
            //     `simple`) carries its elements in ONE value and is split below instead;
            //   - a MULTIPART body, whose parts Symfony has already separated — see
            //     {@see getFormValueLists()}.
            // A single occurrence still yields a one-element list, which is what the style means;
            // a valueless `?ids=` yields the empty array, matching what the delimiter branch does
            // with `''`.
            if (
                $request !== null
                && $rawWasProvided
                && ($rawSource === 'query' || $rawSource === 'form')
                && is_string($rawValue)
                && $paramMeta['arrayItemType'] !== null
                && !$paramMeta['allowsAssociativeArray']
                && $paramMeta['arrayDelimiter'] === null
                && ($paramMeta['parameterStyle'] === null || $paramMeta['parameterStyle'] === 'form')
            ) {
                if ($rawSource === 'query') {
                    if ($paramMeta['allowReserved']) {
                        $reservedQueryValueLists ??= $this->getQueryValueLists($request, allowReserved: true);
                        $lists = $reservedQueryValueLists;
                    } else {
                        $queryValueLists ??= $this->getQueryValueLists($request);
                        $lists = $queryValueLists;
                    }
                } else {
                    $formValueLists ??= $this->getFormValueLists($request) ?? false;
                    $lists = $formValueLists === false ? null : $formValueLists;
                }

                if ($lists !== null) {
                    $rawValue = $lists[$requestFieldName] ?? [$rawValue];
                    if ($rawValue === ['']) {
                        $rawValue = [];
                    }
                }
            }

            // What the CAST treats the value as. It is the source, except for a JSON-content parameter:
            // once decoded that value is JSON and must be cast as strictly as the body — a query
            // source would accept `"42"` for an integer. `$rawSource` keeps the provenance flags.
            $castSource = $rawSource;

            // Every branch in here is gated on a parameter source, a serialization style or a
            // delimiter. `plainBodyOnly` means the class declares none of those, so not one of
            // them can fire — skipping the group is a tautology, not an assumption.
            if (!$plainBodyOnly) {
                // OpenAPI allowEmptyValue (query parameters only). The metadata is tri-state: `false`
                // means the spec explicitly forbids `?flag=`, `true` explicitly permits it, and a
                // missing entry means the spec is silent — those keep the historical behaviour of
                // casting the empty string (e.g. `?verified=` -> false for a bool).
                if (
                    $rawWasProvided
                    && $rawSource === 'query'
                    && $rawValue === ''
                    && $paramMeta['allowEmptyValue'] === false
                ) {
                    $errors[] = sprintf('Parameter "%s" does not allow an empty value.', $requestFieldName);
                    // Keep positional arg alignment; the collected error aborts before use.
                    $args[] = null;
                    continue;
                }

                // OAS 3.2 `in: querystring` described with a form media type: the raw query string is
                // parsed into the array the schema expects.
                if ($rawWasProvided && $paramMeta['formEncodedString'] && is_string($rawValue)) {
                    parse_str($rawValue, $parsedQueryString);
                    $rawValue = $parsedQueryString;
                }

                // content: application/json parameter: the raw value is a JSON document encoded as
                // a string. Decode it before casting so an object/array schema validates against the
                // parsed structure rather than the literal string.
                if ($rawWasProvided && $paramMeta['contentJson'] && is_string($rawValue)) {
                    // A JSON `in: querystring` value arrives percent-encoded inside the URL.
                    if ($rawSource === 'querystring') {
                        $rawValue = rawurldecode($rawValue);
                    }

                    try {
                        // Objects stay stdClass, so `{"0":1}` is still an object and `[1]` a list.
                        $rawValue = json_decode($rawValue, flags: JSON_THROW_ON_ERROR);
                        $castSource = 'json';
                    } catch (JsonException) {
                        $errors[] = sprintf('Parameter "%s" must be valid JSON.', $requestFieldName);
                        // Keep positional arg alignment; the collected error aborts before use.
                        $args[] = null;
                        continue;
                    }
                }

                // OpenAPI path serialization styles (matrix/label) embed the value in the path
                // segment itself. Decode those raw strings before normal type casting.
                if ($rawWasProvided && $castSource !== 'json' && is_string($rawValue)) {
                    try {
                        $rawValue = $this->normalizeSerializedParameterValue(
                            rawValue: $rawValue,
                            paramName: $requestFieldName,
                            source: $rawSource,
                            paramMeta: $paramMeta,
                            parameterStyle: $paramMeta['parameterStyle'],
                            parameterExplode: $paramMeta['parameterExplode'],
                        );
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                        // Keep positional arg alignment; the collected error aborts before use.
                        $args[] = null;
                        continue;
                    }
                }

                // OpenAPI delimited-array serialization: a single query/header/cookie string
                // (e.g. "1,2,3" or "1 2 3") is split into elements per the parameter's style.
                // Already-arrayified values (form+explode repeated keys, deepObject brackets)
                // skip this and are cast as-is.
                if (
                    $rawWasProvided
                    && $castSource !== 'json'
                    && $paramMeta['arrayDelimiter'] !== null
                    && is_string($rawValue)
                ) {
                    $rawValue = $rawValue === ''
                        ? []
                        : explode($paramMeta['arrayDelimiter'], $rawValue);

                    // For non-string scalar item types, whitespace around delimiters (e.g.
                    // "1, 2, 3") is insignificant — trim so strict int/float/bool/enum casts
                    // accept it. String items are left untouched (their whitespace is data).
                    $itemType = $paramMeta['arrayItemType'];
                    if (
                        $itemType !== null
                        && in_array($this->resolveTypeKind($itemType), ['int', 'float', 'bool', 'enum'], true)
                    ) {
                        $rawValue = array_map(trim(...), $rawValue);
                    }
                }
            }

            if ($paramMeta['readOnly']) {
                if ($paramMeta['hasDefaultValue']) {
                    $args[] = $paramMeta['defaultValue'];
                } elseif ($paramMeta['allowsNull']) {
                    $args[] = null;
                } else {
                    $errors[] = sprintf(
                        'Parameter "%s" is readOnly and non-nullable with no default value.',
                        $requestFieldName,
                    );
                    $args[] = null;
                }
                continue;
            }

            if (!$rawWasProvided && $paramMeta['hasDefaultValue']) {
                $args[] = $paramMeta['defaultValue'];
                continue;
            }

            // Missing optional parameter should not raise an error.
            if (!$rawWasProvided && !$paramMeta['isRequired']) {
                // A non-nullable optional param with no default cannot accept null —
                // pushing null would make newInstanceArgs() throw an opaque TypeError, so
                // surface a clear validation error instead.
                if (!$paramMeta['allowsNull']) {
                    $errors[] = sprintf(
                        'Optional parameter "%s" is non-nullable with no default value and was not provided.',
                        $requestFieldName,
                    );
                }
                $args[] = null;
                continue;
            }

            // Cast the pre-resolved raw value to the declared type.
            try {
                if (count($paramMeta['typeNames']) === 1) {
                    // Optional missing params were already handled above, so a missing
                    // value here always belongs to a required parameter — even when the
                    // PHP type is nullable (required + nullable means the key must be
                    // present, its value may be null).
                    if (!$rawWasProvided) {
                        throw new RuntimeException(
                            message: "Required parameter \"{$requestFieldName}\" not found in request.",
                        );
                    }
                    $value = $this->castValue(
                        value: $rawValue,
                        paramName: $requestFieldName,
                        typeName: $paramMeta['typeNames'][0],
                        allowsNull: $paramMeta['allowsNull'],
                        source: $castSource,
                        arrayItemType: $paramMeta['arrayItemType'],
                        paramPath: $requestFieldName,
                        schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                        temporalFormat: $paramMeta['temporalFormat'],
                        openApiFormat: $paramMeta['openApiFormat'],
                        allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                        alsoAllowsJsonList: $paramMeta['alsoAllowsJsonList'],
                        arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                        arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
                        nestedArrayItemType: $paramMeta['nestedArrayItemType'],
                        nestedArrayItemsNullable: $paramMeta['nestedArrayItemsNullable'],
                        constraints: is_array($paramMeta['fieldConstraints']) ? $paramMeta['fieldConstraints'] : [],
                    );
                } else {
                    $value = $this->castUnionValue(
                        paramName: $requestFieldName,
                        typeNames: $this->discriminatedUnionMember(typeNames: $paramMeta['typeNames'], value: $rawValue, constraints: $paramMeta['fieldConstraints']),
                        rawValue: $rawValue,
                        rawWasProvided: $rawWasProvided,
                        exactlyOne: $this->unionMustMatchExactlyOne($paramMeta['fieldConstraints']),
                        unionConstraints: is_array($paramMeta['fieldConstraints']) ? $paramMeta['fieldConstraints'] : [],
                        rawSource: $castSource,
                        arrayItemType: $paramMeta['arrayItemType'],
                        schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                        dtoReflection: $reflection,
                        openApiFormat: $paramMeta['openApiFormat'],
                        allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                        alsoAllowsJsonList: $paramMeta['alsoAllowsJsonList'],
                        arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                        arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
                        nestedArrayItemType: $paramMeta['nestedArrayItemType'],
                        nestedArrayItemsNullable: $paramMeta['nestedArrayItemsNullable'],
                    );
                }
            } catch (RuntimeException $e) {
                // Collect errors and use null as placeholder so we can continue validating other params.
                foreach (explode("\n", $e->getMessage()) as $msg) {
                    $errors[] = $msg;
                }
                $args[] = null;
                continue;
            }

            $args[] = $value;
            if ($this->isEmptyJsonObjectHeldAsArray(cast: $value, raw: $rawValue)) {
                $emptyJsonObjectFields[$paramMeta['name']] = true;
            }

            if ($rawWasProvided && $rawSource === 'json' && is_array($paramMeta['fieldConstraints'])) {
                foreach ($this->listsWhereObjectsBelong(value: $rawValue, constraints: $paramMeta['fieldConstraints'], paramPath: $requestFieldName) as $shapeError) {
                    $errors[] = $shapeError;
                }
            }

            if ($rawWasProvided && is_array($paramMeta['fieldConstraints']) && $paramMeta['fieldConstraints'] !== []) {
                foreach (
                    $this->validateValue(
                        subject: sprintf('param "%s"', $requestFieldName),
                        value: $this->asTheObjectItArrivedAs(cast: $value, raw: $rawValue),
                        constraints: $paramMeta['fieldConstraints'],
                    ) as $constraintError
                ) {
                    $errors[] = $constraintError;
                }
            }

            if ($rawWasProvided) {
                $providedParamSources[$paramMeta['name']] = $rawSource;
            }
        }

        if ($errors !== []) {
            throw $this->withUnreadableBodyHint(
                exception: new RuntimeException(implode("\n", array_unique(array_filter(DtoServiceSupport::finalizeMessages($errors, 'param "'))))),
                request: $request,
                bodyData: $bodyData,
                queryData: $queryData,
                formData: $formData,
            );
        }

        $dto = $reflection->newInstanceArgs($args);
        DtoServiceSupport::rememberEmptyJsonObjects($dto, $emptyJsonObjectFields);

        // Set tracking flags: all to false first, then mark provided sources.
        foreach ($classMeta['params'] as $paramMeta) {
            $paramName = $paramMeta['name'];
            $source = $providedParamSources[$paramName] ?? null;

            if (($prop = $classMeta['inRequestProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source !== null);
            }
            if (($prop = $classMeta['inPathProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'path');
            }
            if (($prop = $classMeta['inQueryProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'query');
            }
            if (($prop = $classMeta['inHeaderProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'header');
            }
            if (($prop = $classMeta['inCookieProperties'][$paramName] ?? null) !== null) {
                $prop->setValue($dto, $source === 'cookie');
            }
        }

        return $this->withUndeclaredKeysNoted(
            dto: $this->withUndeclaredProperties(dto: $dto, undeclared: $undeclared),
            bodyData: $bodyData,
            requestFieldNames: $classMeta['requestFieldNames'],
        );
    }

    /**
     * `$dto`, with the body keys it declares no property for noted beside it — see
     * {@see DtoServiceSupport::rememberUndeclaredKeysSent()}. A `required` name the class lacks is
     * then met by the key that was sent, instead of being refused because the built object cannot
     * hold it.
     *
     * @param array<array-key, mixed> $bodyData
     * @param array<string, true> $requestFieldNames
     */
    private function withUndeclaredKeysNoted(object $dto, array $bodyData, array $requestFieldNames): object
    {
        $sent = array_diff_key($bodyData, $requestFieldNames);
        if ($sent !== []) {
            DtoServiceSupport::rememberUndeclaredKeysSent($dto, array_fill_keys(array_keys($sent), true));
        }

        return $dto;
    }

    /**
     * The keys `additionalProperties: {schema}` admitted beside the declared ones, handed to a DTO
     * generated to keep them. A class generated before it, or one without such a schema, has no
     * `withUndeclaredProperties()` and keeps nothing, as before.
     *
     * @param array<array-key, mixed> $undeclared
     */
    private function withUndeclaredProperties(object $dto, array $undeclared): object
    {
        if ($undeclared === [] || !method_exists($dto, 'withUndeclaredProperties')) {
            return $dto;
        }

        $kept = $dto->withUndeclaredProperties($undeclared);

        return is_object($kept) ? $kept : $dto;
    }

    /**
     * Builds and returns the complete per-class metadata array used by deserialize().
     * Called exactly once per DTO class per PHP process; result is stored in $dtoMetaCache.
     *
     * @param ReflectionClass<object> $reflection
     * @return array{
     *   hasConstructor: bool,
     *   plainBodyOnly: bool,
     *   jsonOnlyBody: bool,
     *   params: list<array{
     *     name: string,
     *     requestFieldName: string,
     *     typeNames: list<string>,
     *     allowsNull: bool,
     *     isRequired: bool,
     *     hasDefaultValue: bool,
     *     defaultValue: mixed,
     *     schemaAllowsNull: bool,
     *     arrayItemType: string|null,
     *     nestedArrayItemType: string|null,
     *     openApiFormat: string|null,
     *     allowsAssociativeArray: bool,
     *     alsoAllowsJsonList: bool,
     *     temporalFormat: string|null,
     *     arrayItemTemporalFormat: string|null,
     *     fieldConstraints: array<string,mixed>|null,
     *     readOnly: bool,
     *     sourceConstraint: string|null,
     *     arrayDelimiter: non-empty-string|null,
     *     parameterStyle: string|null,
     *     parameterExplode: bool|null,
     *     allowReserved: bool,
     *     allowEmptyValue: bool|null,
     *     contentJson: bool,
     *     formEncodedString: bool,
     *     arrayItemsNullable: bool,
     *     nestedArrayItemsNullable: bool,
     *   }>,
     *   inRequestProperties: array<string, ReflectionProperty|null>,
     *   inPathProperties: array<string, ReflectionProperty|null>,
     *   inQueryProperties: array<string, ReflectionProperty|null>,
     *   inHeaderProperties: array<string, ReflectionProperty|null>,
     *   inCookieProperties: array<string, ReflectionProperty|null>,
     *   requestFieldNames: array<string, true>,
     * }
     */
    private function buildDtoMeta(ReflectionClass $reflection, string $dtoClass): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [
                'hasConstructor' => false,
                'plainBodyOnly' => true,
                'jsonOnlyBody' => false,
                'params' => [],
                'inRequestProperties' => [],
                'inPathProperties' => [],
                'inQueryProperties' => [],
                'inHeaderProperties' => [],
                'inCookieProperties' => [],
                'requestFieldNames' => [],
            ];
        }

        $aliases = $this->resolveOpenApiPropertyAliases($reflection);
        $constraints = $this->resolveOpenApiConstraints($reflection);
        // Per-property OpenAPI source binding (path/query/header/cookie). Properties
        // absent from the map — request-body and plain component-schema fields — keep
        // the permissive waterfall. Null when the DTO predates this metadata.
        $parameterSources = $this->resolveParameterSources($reflection) ?? [];
        // Per-property OpenAPI serialization style/explode — drives delimited-array
        // splitting for query/header/cookie params. Empty for legacy DTOs.
        $parameterStyles = $this->resolveParameterStyles($reflection);
        $parameterAllowReserved = $this->resolveParameterAllowReserved($reflection);
        $parameterAllowEmptyValue = $this->resolveParameterAllowEmptyValue($reflection);

        $params = [];
        $inRequestProperties = [];
        $inPathProperties = [];
        $inQueryProperties = [];
        $inHeaderProperties = [];
        $inCookieProperties = [];

        foreach ($constructor->getParameters() as $param) {
            $paramName = $param->getName();
            $requestFieldName = $aliases[$paramName] ?? $paramName;
            $paramType = $param->getType();
            $hasDefaultValue = $param->isDefaultValueAvailable();
            $fieldConstraints = $constraints[$paramName] ?? null;
            $allowsAssociativeArray = $this->allowsAssociativeArray($fieldConstraints);
            $alsoAllowsJsonList = $allowsAssociativeArray && is_array($fieldConstraints) && !DtoServiceSupport::admitsObjectContainerOnly($fieldConstraints);
            $openApiFormat = $this->resolveOpenApiFormat($fieldConstraints);
            $allowsNull = $paramType?->allowsNull() ?? false;
            $isRequired = $this->resolveParameterRequiredFlag(
                reflection: $reflection,
                paramName: $paramName,
                allowsNull: $allowsNull,
                hasDefaultValue: $hasDefaultValue,
            );
            $schemaAllowsNull = $this->resolveSchemaAllowsNull(
                reflection: $reflection,
                paramName: $paramName,
                phpAllowsNull: $allowsNull,
            );

            $typeNames = [];
            if ($paramType instanceof ReflectionNamedType) {
                if (!$this->isInternalUnsetValueType($paramType->getName())) {
                    $typeNames[] = $paramType->getName();
                }
            } elseif ($paramType instanceof ReflectionUnionType) {
                foreach ($paramType->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType) {
                        continue;
                    }
                    if ($unionType->getName() === 'null' || $this->isInternalUnsetValueType($unionType->getName())) {
                        continue;
                    }
                    $typeNames[] = $unionType->getName();
                }
            } else {
                // Unsupported intersection / no-type-hint param – surface the error immediately.
                throw new RuntimeException(
                    message: "Parameter \${$paramName} in {$dtoClass} has unsupported type.",
                );
            }

            if ($typeNames === []) {
                $typeNames[] = 'mixed';
            }

            // A schema with no `type` admits every JSON value, null included: optionality has no say.
            // What else it asserts — `enum`, `const`, `not` — the constraint check still runs on null.
            if (
                $typeNames === ['mixed']
                && !array_key_exists('type', $fieldConstraints ?? [])
                && !array_key_exists('nullable', $fieldConstraints ?? [])
            ) {
                $schemaAllowsNull = true;
            }

            $arrayItemType = in_array('array', $typeNames, true)
                ? $this->resolveArrayItemType(reflection: $reflection, paramName: $paramName)
                : null;
            $nestedArrayItemType = in_array($arrayItemType, ['array', 'list', 'container'], true)
                ? $this->resolveNestedArrayItemType(reflection: $reflection, paramName: $paramName)
                : null;
            $arrayItemsNullable = $this->resolveArrayItemsNullable($fieldConstraints);
            // One hop further: the `?Node` of `array<array<?Node>>`. Without it the inner null was cast
            // as an object and refused before the constraints, which allow it, were ever read.
            $itemSchema = $fieldConstraints['items'] ?? $fieldConstraints['additionalProperties'] ?? null;
            // An item the schema lets be EITHER container — `anyOf: [{type: array}, {type: object}]` — is
            // read as either, whichever form the declaration spelled: `array<mixed>` refused the object.
            if (
                in_array($arrayItemType, ['array', 'list'], true)
                && is_array($itemSchema)
                && $this->allowsAssociativeArray($itemSchema)
                && !DtoServiceSupport::admitsObjectContainerOnly($itemSchema)
            ) {
                $arrayItemType = 'container';
            }
            $nestedArrayItemsNullable = is_array($itemSchema) && $this->resolveArrayItemsNullable($itemSchema);

            // Pre-compute temporal format for DateTimeImmutable fields.
            $temporalFormat = in_array(DateTimeImmutable::class, $typeNames, true)
                ? $this->resolveTemporalFormat(
                    reflection: $reflection,
                    paramName: $paramName,
                    openApiFormat: $openApiFormat,
                )
                : null;

            // Pre-compute temporal format for DateTimeImmutable[] array items and map values.
            // The items schema (fieldConstraints['items']) or the value schema of a map
            // (fieldConstraints['additionalProperties']) may carry format: date, which must be
            // propagated so date-only strings are accepted without a time component. Read from the
            // getter's docblock alone, a map value `anyOf: [{format: date}, {type: 'null'}]` — which
            // carries none — was parsed as a date-time: every date refused, every date-time accepted.
            $itemsConstraints = is_array($itemSchema) ? $itemSchema : null;
            $arrayItemTemporalFormat = ($arrayItemType === DateTimeImmutable::class)
                ? $this->resolveTemporalFormat(
                    reflection: $reflection,
                    paramName: $paramName,
                    openApiFormat: $this->resolveOpenApiFormat($itemsConstraints),
                )
                : null;

            $inRequestProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInRequestFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inPathProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInPathFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inQueryProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInQueryFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inHeaderProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInHeaderFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );
            $inCookieProperties[$paramName] = $this->resolveReflectionProperty(
                reflection: $reflection,
                propName: $this->resolveInCookieFlagPropertyName(reflection: $reflection, paramName: $paramName),
            );

            $sourceConstraint = $parameterSources[$paramName] ?? null;
            $styleEntry = $parameterStyles[$paramName] ?? null;
            $arrayDelimiter = $arrayItemType !== null && $styleEntry !== null
                ? $this->resolveStyleDelimiter($styleEntry['style'], $styleEntry['explode'])
                : null;
            // A content:application/json parameter carries the 'json' style sentinel: its raw
            // string value is JSON-decoded before casting (see the deserialize() loop).
            $contentJson = ($styleEntry['style'] ?? null) === 'json';
            // OAS 3.2 `in: querystring` with a form media type: the raw string is form-decoded.
            $formEncodedString = ($styleEntry['style'] ?? null) === 'querystring';

            $params[] = [
                'name' => $paramName,
                'requestFieldName' => $requestFieldName,
                'typeNames' => $typeNames,
                'allowsNull' => $allowsNull,
                'isRequired' => $isRequired,
                'hasDefaultValue' => $hasDefaultValue,
                'defaultValue' => $hasDefaultValue ? $param->getDefaultValue() : null,
                'schemaAllowsNull' => $schemaAllowsNull,
                'arrayItemType' => $arrayItemType,
                'nestedArrayItemType' => $nestedArrayItemType,
                'openApiFormat' => $openApiFormat,
                'allowsAssociativeArray' => $allowsAssociativeArray,
                'alsoAllowsJsonList' => $alsoAllowsJsonList,
                'temporalFormat' => $temporalFormat,
                'arrayItemTemporalFormat' => $arrayItemTemporalFormat,
                'fieldConstraints' => $fieldConstraints,
                'readOnly' => ($fieldConstraints['readOnly'] ?? false) === true,
                'sourceConstraint' => $sourceConstraint,
                'arrayDelimiter' => $arrayDelimiter,
                'parameterStyle' => $styleEntry['style'] ?? null,
                'parameterExplode' => $styleEntry['explode'] ?? null,
                'allowReserved' => ($parameterAllowReserved[$paramName] ?? false) === true,
                'allowEmptyValue' => $parameterAllowEmptyValue[$paramName] ?? null,
                'contentJson' => $contentJson,
                'formEncodedString' => $formEncodedString,
                'arrayItemsNullable' => $arrayItemsNullable,
                'nestedArrayItemsNullable' => $nestedArrayItemsNullable,
            ];
        }

        // Whether every property is a PLAIN BODY field: no bound source, no serialization style, no
        // delimiter, no content/form decoding, no empty-value rule. `deserializeInternal()` uses it
        // to skip a branch group those flags gate — provably dead for such a class. Computed once
        // per class, with the rest of the metadata.
        // Belt and braces, deliberately. Every OpenAPI parameter carries a source (`in:` is
        // mandatory), so the first clause already excludes each property the others describe —
        // mutation testing showed the remaining six can be dropped one at a time without any test
        // noticing, which is a property of the DATA, not a gap in coverage. They stay because they
        // state what makes a class ineligible, and because the first clause resting alone would make
        // "a parameter always has a source" an invariant nothing checks.
        $plainBodyOnly = true;
        foreach ($params as $param) {
            if (
                $param['sourceConstraint'] !== null
                || $param['allowReserved']
                || $param['allowEmptyValue'] === false
                || $param['formEncodedString']
                || $param['contentJson']
                || $param['parameterStyle'] !== null
                || $param['arrayDelimiter'] !== null
            ) {
                $plainBodyOnly = false;
                break;
            }
        }

        // A class whose every declared request-body media type is JSON, and which reads no parameter
        // from a bound source. Such a body has exactly one place to come from — see
        // `declaresJsonOnlyBody()` and its use in `deserializeInternal()`.
        $jsonOnlyBody = $this->declaresJsonOnlyBody($reflection);
        foreach ($params as $param) {
            if ($param['sourceConstraint'] !== null) {
                $jsonOnlyBody = false;
                break;
            }
        }

        $requestFieldNames = [];
        foreach ($params as $param) {
            $requestFieldNames[$param['requestFieldName']] = true;
        }

        return [
            'hasConstructor' => true,
            'plainBodyOnly' => $plainBodyOnly,
            'jsonOnlyBody' => $jsonOnlyBody,
            'params' => $params,
            'inRequestProperties' => $inRequestProperties,
            'inPathProperties' => $inPathProperties,
            'inQueryProperties' => $inQueryProperties,
            'inHeaderProperties' => $inHeaderProperties,
            'inCookieProperties' => $inCookieProperties,
            'requestFieldNames' => $requestFieldNames,
        ];
    }

    /**
     * Whether the class says it arrives as a request body in JSON media types only.
     *
     * Read from `getRequestBodyMediaTypes()`, which runtime mode emits on the class an operation names
     * as its body — and only where the class declares it itself: a subclass inherits the method
     * without being anyone's body. A class with no such method (a nested or response component, a
     * hand-written DTO, one generated before the method existed) says nothing, and keeps the
     * permissive lookup. So does one also declared under a form or multipart type, whose fields ARE
     * form fields.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function declaresJsonOnlyBody(ReflectionClass $reflection): bool
    {
        if (!$reflection->hasMethod('getRequestBodyMediaTypes')) {
            return false;
        }

        $method = $reflection->getMethod('getRequestBodyMediaTypes');
        if (!$method->isStatic() || $method->getDeclaringClass()->getName() !== $reflection->getName()) {
            return false;
        }

        $mediaTypes = $method->invoke(null);
        if (!is_array($mediaTypes) || $mediaTypes === []) {
            return false;
        }

        foreach ($mediaTypes as $mediaType) {
            if (!is_string($mediaType) || !$this->isJsonMediaType($mediaType)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads the optional getParameterSources() metadata that binds properties to a
     * single OpenAPI request source. Returns null when the DTO does not declare it
     * (legacy / hand-written DTOs) so callers can fall back to the permissive waterfall.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>|null
     */
    private function resolveParameterSources(ReflectionClass $reflection): ?array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterSources'],
        );
        if ($method === null) {
            return null;
        }

        $sources = call_user_func($method);
        if (!is_array($sources)) {
            return null;
        }

        $result = [];
        foreach ($sources as $propertyName => $source) {
            if (is_string($propertyName) && is_string($source)) {
                $result[$propertyName] = $source;
            }
        }

        return $result;
    }

    /**
     * Reads the optional getParameterStyles() metadata describing each parameter's
     * OpenAPI serialization style/explode. Returns an empty map when the DTO does not
     * declare it (legacy / hand-written DTOs) so no array splitting is attempted.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, array{style: string, explode: bool}>
     */
    private function resolveParameterStyles(ReflectionClass $reflection): array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterStyles'],
        );
        if ($method === null) {
            return [];
        }

        $styles = call_user_func($method);
        if (!is_array($styles)) {
            return [];
        }

        $result = [];
        foreach ($styles as $propertyName => $entry) {
            if (!is_string($propertyName) || !is_array($entry)) {
                continue;
            }
            $style = $entry['style'] ?? null;
            $explode = $entry['explode'] ?? null;
            if (is_string($style) && is_bool($explode)) {
                $result[$propertyName] = ['style' => $style, 'explode' => $explode];
            }
        }

        return $result;
    }

    /**
     * Reads the optional getParameterAllowReserved() metadata that marks query params
     * whose raw value should preserve literal plus signs.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, bool>
     */
    private function resolveParameterAllowReserved(ReflectionClass $reflection): array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterAllowReserved'],
        );
        if ($method === null) {
            return [];
        }

        $allowReserved = call_user_func($method);
        if (!is_array($allowReserved)) {
            return [];
        }

        $result = [];
        foreach ($allowReserved as $propertyName => $enabled) {
            if (is_string($propertyName) && $enabled === true) {
                $result[$propertyName] = true;
            }
        }

        return $result;
    }

    /**
     * Reads the optional getParameterAllowEmptyValue() metadata that marks query params
     * whose empty string value is explicitly allowed by the spec.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, bool>
     */
    private function resolveParameterAllowEmptyValue(ReflectionClass $reflection): array
    {
        $method = $this->resolveMetadataMethod(
            className: $reflection->getName(),
            candidateMethods: ['getParameterAllowEmptyValue'],
        );
        if ($method === null) {
            return [];
        }

        $allowEmptyValue = call_user_func($method);
        if (!is_array($allowEmptyValue)) {
            return [];
        }

        $result = [];
        foreach ($allowEmptyValue as $propertyName => $enabled) {
            // Both states are meaningful here: `false` is an explicit prohibition, not a default.
            if (is_string($propertyName) && is_bool($enabled)) {
                $result[$propertyName] = $enabled;
            }
        }

        return $result;
    }

    /**
     * Resolves the delimiter used to split a single string into array elements for the
     * given OpenAPI style/explode, or null when no splitting applies (the value is
     * already an array from Symfony query parsing, or the style is object-shaped).
     *
     * @return non-empty-string|null
     */
    private function resolveStyleDelimiter(?string $style, ?bool $explode): ?string
    {
        return match ($style) {
            // simple (header default): comma-separated regardless of explode.
            'simple' => ',',
            'spaceDelimited' => ' ',
            'pipeDelimited' => '|',
            // form with explode=true arrives as repeated keys (Symfony already
            // arrayifies); only the non-exploded form uses comma separation. OAS 3.2 `cookie` is
            // form spelled as a `Cookie` header, so it splits the same way.
            'form', 'cookie' => $explode === true ? null : ',',
            // deepObject and unknown styles: no scalar splitting.
            default => null,
        };
    }

    /**
     * Decodes matrix/label path serialization into native PHP values before type casting.
     *
     * Matrix and label styles are the only OpenAPI styles that need manual path-segment
     * parsing here; query deepObject already arrives from Symfony as a nested array.
     *
     * @param array{typeNames: list<string>, allowsAssociativeArray: bool, arrayItemType: string|null} $paramMeta
     */
    private function normalizeSerializedParameterValue(
        string $rawValue,
        string $paramName,
        string $source,
        array $paramMeta,
        ?string $parameterStyle,
        ?bool $parameterExplode,
    ): mixed {
        // An OBJECT in `simple` or non-exploded `form` style is one comma list: `role,admin,name,Rex`
        // alternates key and value, exploded `simple` spells pairs `role=admin,name=Rex`. Left to the
        // array delimiter below, a map became the list of its tokens and a DTO did not hydrate.
        // Exploded `form` spreads the keys over the query itself, so there is no value to parse here.
        // OAS 3.2 `cookie` is `form` spelled as a `Cookie` header, and its object reads the same way.
        if (
            ($parameterStyle === 'simple' || (in_array($parameterStyle, ['form', 'cookie'], true) && $parameterExplode !== true))
            && $this->parameterExpectsAssociativeValue($paramMeta)
        ) {
            return $this->parseDelimitedObjectValue(rawValue: $rawValue, keyValuePairs: $parameterStyle === 'simple' && $parameterExplode === true)
                ?? throw new RuntimeException($this->finalizeParamMessage(
                    message: "param \"{$paramName}\" expects an object serialized as comma-separated key/value pairs",
                ));
        }

        if ($source !== 'path' || !is_string($parameterStyle)) {
            return $rawValue;
        }

        if (!in_array($parameterStyle, ['matrix', 'label'], true)) {
            return $rawValue;
        }

        $expectsAssociative = $this->parameterExpectsAssociativeValue($paramMeta);
        $expectsArray = !$expectsAssociative && ($paramMeta['arrayItemType'] ?? null) !== null;

        if (!$expectsArray && !$expectsAssociative) {
            return $this->stripSerializedScalarPrefix($rawValue, $paramName, $parameterStyle);
        }

        $tokens = $this->splitSerializedPathTokens($rawValue, $parameterStyle, $parameterExplode === true);
        if ($tokens === []) {
            return [];
        }

        if ($expectsAssociative) {
            return $this->parseSerializedPathAssociativeValue(
                tokens: $this->stripParameterNameFromFirstToken($tokens, $paramName, $parameterExplode === true),
                paramName: $paramName,
            );
        }

        return $this->parseSerializedPathListValue($tokens, $paramName);
    }

    /**
     * The comma spelling of an object; null when the string cannot be one — an odd token count, a
     * pair without `=`, an empty key.
     *
     * @return array<string, string>|null
     */
    private function parseDelimitedObjectValue(string $rawValue, bool $keyValuePairs): ?array
    {
        if ($rawValue === '') {
            return [];
        }

        $tokens = explode(',', $rawValue);
        $object = [];
        if ($keyValuePairs) {
            foreach ($tokens as $token) {
                $pair = explode('=', $token, 2);
                if (count($pair) !== 2 || $pair[0] === '') {
                    return null;
                }
                $object[$pair[0]] = $pair[1];
            }

            return $object;
        }

        if (count($tokens) % 2 !== 0) {
            return null;
        }
        for ($i = 0, $count = count($tokens); $i < $count; $i += 2) {
            if ($tokens[$i] === '') {
                return null;
            }
            $object[$tokens[$i]] = $tokens[$i + 1];
        }

        return $object;
    }

    /**
     * @param array{typeNames: list<string>, allowsAssociativeArray: bool} $paramMeta
     */
    private function parameterExpectsAssociativeValue(array $paramMeta): bool
    {
        if ($paramMeta['allowsAssociativeArray'] === true) {
            return true;
        }

        foreach ($paramMeta['typeNames'] as $typeName) {
            if ($this->resolveTypeKind($typeName) === 'dto') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function splitSerializedPathTokens(
        string $rawValue,
        string $parameterStyle,
        bool $explode,
    ): array {
        // Strip style prefix: matrix (;), label (.)
        // Do NOT strip a `<paramName>.=` / `<paramName>.,` prefix — those are not array/object
        // prefixes, which is why the parameter name is not needed here at all.
        $value = match (true) {
            $parameterStyle === 'matrix' && str_starts_with($rawValue, ';') => substr($rawValue, 1),
            $parameterStyle === 'label' && str_starts_with($rawValue, '.') => substr($rawValue, 1),
            default => $rawValue,
        };

        if ($value === '') {
            return [];
        }

        $delimiter = match ($parameterStyle) {
            'matrix' => $explode ? ';' : ',',
            'label' => $explode ? '.' : ',',
            default => ',',
        };

        // Split by delimiter; preserve empty strings (important for minItems/maxItems validation)
        return explode($delimiter, $value);
    }

    private function stripSerializedScalarPrefix(string $rawValue, string $paramName, string $parameterStyle): string
    {
        $value = match (true) {
            $parameterStyle === 'matrix' && str_starts_with($rawValue, ';') => substr($rawValue, 1),
            $parameterStyle === 'label' && str_starts_with($rawValue, '.') => substr($rawValue, 1),
            default => $rawValue,
        };

        return match (true) {
            str_starts_with($value, $paramName . '=') => substr($value, strlen($paramName . '=')),
            str_starts_with($value, $paramName . ',') => substr($value, strlen($paramName . ',')),
            default => $value,
        };
    }

    /**
     * A NON-exploded matrix/label object carries the parameter name once, in front of the first key:
     * `;id=kind,dog,name,Rex` (RFC 6570 matrix expansion). Left in place, `id=kind` looks like an
     * explicit key/value pair, so the whole object collapsed to `['id' => 'kind']` and every real
     * pair was thrown away.
     *
     * Only for `explode: false`: in the exploded spelling (`;kind=dog;name=Rex`) each token already
     * IS a pair, and a key that happens to match the parameter name must not be stripped.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function stripParameterNameFromFirstToken(array $tokens, string $paramName, bool $explode): array
    {
        if ($explode || $tokens === []) {
            return $tokens;
        }

        $prefix = $paramName . '=';
        if (!str_starts_with($tokens[0], $prefix)) {
            return $tokens;
        }

        $tokens[0] = substr($tokens[0], strlen($prefix));

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     * @return array<string, mixed>
     */
    private function parseSerializedPathAssociativeValue(array $tokens, string $paramName): array
    {
        $result = [];
        $hasExplicitKeys = false;

        // First pass: look for key=value pairs (empty tokens are preserved)
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $pair = explode('=', $token, 2);
            if (count($pair) === 2) {
                $hasExplicitKeys = true;
                [$key, $value] = $pair;
                if ($key !== '') {
                    $result[$key] = $value;
                }
            }
        }

        if ($hasExplicitKeys) {
            return $result;
        }

        // Second pass: pair up consecutive tokens (skip empty tokens)
        $nonEmptyTokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
        for ($i = 0, $count = count($nonEmptyTokens); $i < $count - 1; $i += 2) {
            $key = $nonEmptyTokens[$i];
            $value = $nonEmptyTokens[$i + 1] ?? null;
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        if ($result !== []) {
            return $result;
        }

        return [$paramName => implode(',', $tokens)];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function parseSerializedPathListValue(array $tokens, string $paramName): array
    {
        if ($tokens === []) {
            return [];
        }

        $values = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                // Preserve empty strings for count validation (minItems/maxItems)
                $values[] = '';
                continue;
            }

            // Check if this token has paramName=value pattern
            // (happens in explode mode: ;paramName=val1;paramName=val2)
            if (str_contains($token, '=')) {
                $pair = explode('=', $token, 2);
                if ($pair[0] === $paramName) {
                    // This is our parameter's value, strip the key
                    $values[] = $pair[1];
                    continue;
                }
            }

            // Otherwise, keep the token as-is (it's a plain value, not key=value)
            $values[] = $token;
        }

        return $values;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveParameterRequiredFlag(
        ReflectionClass $reflection,
        string $paramName,
        bool $allowsNull,
        bool $hasDefaultValue,
    ): bool {
        $requiredMethodName = 'is' . ucfirst($paramName) . 'Required';

        if (!$reflection->hasMethod($requiredMethodName)) {
            return !$allowsNull && !$hasDefaultValue;
        }

        return $this->invokeRequiredMethod($reflection, $requiredMethodName)
            ?? (!$allowsNull && !$hasDefaultValue);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function invokeRequiredMethod(ReflectionClass $reflection, string $methodName): ?bool
    {
        try {
            $method = $reflection->getMethod($methodName);
            $instance = $method->isStatic() ? null : $reflection->newInstanceWithoutConstructor();
            return (bool)$method->invoke($instance);
        } catch (ReflectionException | Error) {
            // Fall back to PHP type inference when:
            //  - reflection itself failed (missing method, uninstantiable class), or
            //  - the method touched an uninitialized typed property on the constructor-less
            //    instance (a PHP Error) — an artifact of newInstanceWithoutConstructor(), not
            //    a user bug, so it must not bubble up as a 500.
            // Genuine app-level exceptions (RuntimeException etc.) from the body still
            // propagate so real logic faults surface.
            return null;
        }
    }

    /**
     * Resolves the raw value for a single parameter from the request, honouring the
     * declared OpenAPI source when one is known. Sets $wasProvided and $source by reference.
     *
     * Resolution modes:
     *  - $sourceConstraint !== null: the property is bound to one OpenAPI source (path/
     *    query/header/cookie) and is read ONLY from there — a same-named body field cannot
     *    shadow it, and header/cookie are never consulted for any other property.
     *  - otherwise (request-body / plain component-schema field): the permissive waterfall
     *    path → JSON → query → files → form (preserves pre-existing behaviour). Header and
     *    cookie are intentionally absent here so they only ever feed their bound params.
     *    `$readsUploadedFiles` is false for a JSON-only body, whose caller also empties the query
     *    and form data, leaving path → JSON.
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param Closure(): array<string, mixed> $rawQueryProvider
     * @param array<string, mixed> $formData
     */
    private function resolveRawRequestValue(
        ?Request $request,
        string $paramName,
        array $bodyData,
        array $queryData,
        Closure $rawQueryProvider,
        array $formData,
        ?string $sourceConstraint,
        bool $allowReserved,
        bool &$wasProvided,
        string &$source,
        string $bodySource,
        bool $readsUploadedFiles,
    ): mixed {
        if ($sourceConstraint !== null) {
            return $this->resolveFromBoundSource(
                request: $request,
                paramName: $paramName,
                sourceConstraint: $sourceConstraint,
                queryData: $queryData,
                rawQueryProvider: $rawQueryProvider,
                allowReserved: $allowReserved,
                wasProvided: $wasProvided,
                source: $source,
            );
        }

        // Path attributes carry router-verified values and must take highest precedence
        // to prevent a malicious request body from overriding trusted path parameters.
        // ($request is null for nested array deserialization — JSON body only.)
        if ($request !== null && $request->attributes->has($paramName)) {
            $wasProvided = true;
            $source = 'path';
            return $request->attributes->get($paramName);
        }

        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            $source = $bodySource;
            return $bodyData[$paramName];
        }

        // An UPLOADED FILE wins over a query string of the same name, and only for that case is the
        // waterfall order stepped over. `POST /upload?avatar=ignored` with `avatar` also present in the
        // multipart body took the query string, handed a `string` to a property typed `UploadedFile` and
        // died with `expects UploadedFile, got string` — a request the document describes perfectly.
        // Nothing else changes order: a query value still beats a form field, because for a scalar the
        // document cannot say which one was meant, while for a file it can — a query string is never a
        // file.
        if ($readsUploadedFiles && $request !== null && $request->files->has($paramName)) {
            $wasProvided = true;
            $source = 'files';
            return $request->files->get($paramName);
        }

        if (array_key_exists($paramName, $queryData)) {
            $wasProvided = true;
            $source = 'query';
            return $queryData[$paramName];
        }

        if (array_key_exists($paramName, $formData)) {
            $wasProvided = true;
            $source = 'form';
            return $formData[$paramName];
        }

        $wasProvided = false;
        $source = '';
        return null;
    }

    /**
     * Reads a parameter strictly from its declared OpenAPI source.
     * ($request is null for nested array deserialization, where only body data exists,
     * so path/query/header/cookie-bound params are simply absent.)
     *
     * @param array<string, mixed> $queryData
     * @param Closure(): array<string, mixed> $rawQueryProvider
     */
    private function resolveFromBoundSource(
        ?Request $request,
        string $paramName,
        string $sourceConstraint,
        array $queryData,
        Closure $rawQueryProvider,
        bool $allowReserved,
        bool &$wasProvided,
        string &$source,
    ): mixed {
        if ($sourceConstraint === 'querystring') {
            // OAS 3.2: the value is the whole query string. An absent query string counts as
            // "not provided" so optional parameters keep their default.
            // The raw server value keeps the string exactly as sent; getQueryString() normalizes it
            // (it appends `=` to a valueless key, which would corrupt a JSON payload).
            $queryString = $request?->server->get('QUERY_STRING');
            if (!is_string($queryString) || $queryString === '') {
                $queryString = $request?->getQueryString();
            }
            if ($queryString === null || $queryString === '') {
                $wasProvided = false;
                $source = '';
                return null;
            }

            $wasProvided = true;
            $source = 'querystring';
            return $queryString;
        }

        if ($sourceConstraint === 'query') {
            $sourceData = $allowReserved ? ($rawQueryProvider)() : $queryData;

            if (array_key_exists($paramName, $sourceData)) {
                $wasProvided = true;
                $source = 'query';
                return $sourceData[$paramName];
            }

            // `$request->query` is built by `parse_str()`, which files `page.size` and `a b` under
            // `page_size` and `a_b` — a name PHP made up, not the one the document declares, so the
            // parameter came back "not found" or silently empty. The raw query string still has the
            // name as sent; it is parsed only on this miss, and only for a name PHP would rename.
            if ($request !== null && strpbrk($paramName, self::PHP_RENAMED_NAME_CHARACTERS) !== false) {
                $unrenamed = $this->getRawQueryData(request: $request, preservePlus: $allowReserved);
                if (array_key_exists($paramName, $unrenamed)) {
                    $wasProvided = true;
                    $source = 'query';
                    return $unrenamed[$paramName];
                }
            }

            $wasProvided = false;
            $source = '';
            return null;
        }

        if ($request !== null) {
            if ($sourceConstraint === 'path' && $request->attributes->has($paramName)) {
                $wasProvided = true;
                $source = 'path';
                return $request->attributes->get($paramName);
            }

            if ($sourceConstraint === 'header' && $request->headers->has($paramName)) {
                $wasProvided = true;
                $source = 'header';
                return $request->headers->get($paramName);
            }

            if ($sourceConstraint === 'cookie' && $request->cookies->has($paramName)) {
                $wasProvided = true;
                $source = 'cookie';
                return $request->cookies->get($paramName);
            }

            // `$_COOKIE` is renamed the way `$_GET` is (see the query branch above); the `Cookie`
            // header is not.
            if ($sourceConstraint === 'cookie' && strpbrk($paramName, self::PHP_RENAMED_NAME_CHARACTERS) !== false) {
                $cookieValue = $this->findCookieInHeader(request: $request, name: $paramName);
                if ($cookieValue !== null) {
                    $wasProvided = true;
                    $source = 'cookie';
                    return $cookieValue;
                }
            }
        }

        $wasProvided = false;
        $source = '';
        return null;
    }

    /**
     * Casts a pre-resolved raw value to one of the given union types.
     *
     * @param array<int, string> $typeNames
     * @param ReflectionClass<object>|null $dtoReflection null for the members of a union interface,
     *     which are classes and need no temporal format read off the owner
     * @param array<array-key, mixed> $unionConstraints the union's own constraints, whose class branches
     *     may list `required` names the class does not declare
     */
    private function castUnionValue(
        string $paramName,
        array $typeNames,
        mixed $rawValue,
        bool $rawWasProvided,
        string $rawSource,
        ?string $arrayItemType,
        bool $schemaAllowsNull,
        ?ReflectionClass $dtoReflection,
        ?string $openApiFormat,
        bool $allowsAssociativeArray,
        bool $alsoAllowsJsonList = false,
        bool $arrayItemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
        ?string $nestedArrayItemType = null,
        bool $nestedArrayItemsNullable = false,
        bool $exactlyOne = false,
        array $unionConstraints = [],
    ): mixed {
        // A missing value here always belongs to a required parameter: optional
        // missing params are short-circuited in deserialize() before this call,
        // so nullability must not suppress the error (required + nullable means
        // the key must be present, its value may be null).
        if (!$rawWasProvided) {
            throw new RuntimeException(
                message: "Required parameter \"{$paramName}\" not found in request.",
            );
        }

        // Opens a trial: nested objects hydrated from here down are remembered, see
        // `$unionTrialResults`. Closed in `finally`, so a branch that throws still closes it.
        $this->unionTrialDepth++;
        try {
            return $this->castFirstMatchingUnionBranch(
                paramName: $paramName,
                typeNames: $typeNames,
                rawValue: $rawValue,
                rawSource: $rawSource,
                arrayItemType: $arrayItemType,
                schemaAllowsNull: $schemaAllowsNull,
                dtoReflection: $dtoReflection,
                openApiFormat: $openApiFormat,
                allowsAssociativeArray: $allowsAssociativeArray,
                alsoAllowsJsonList: $alsoAllowsJsonList,
                arrayItemsNullable: $arrayItemsNullable,
                arrayItemTemporalFormat: $arrayItemTemporalFormat,
                nestedArrayItemType: $nestedArrayItemType,
                nestedArrayItemsNullable: $nestedArrayItemsNullable,
                exactlyOne: $exactlyOne,
                unionConstraints: $unionConstraints,
            );
        } finally {
            $this->unionTrialDepth--;
            if ($this->unionTrialDepth === 0) {
                $this->unionTrialResults = null;
                $this->unionTrialArrayResults = [];
            }
        }
    }

    /**
     * The first name the union branch "is `$typeName`" lists in `required` — the names the generator
     * keeps beside the marker because the class does not declare them — that the raw value lacks.
     *
     * @param array<array-key, mixed> $unionConstraints
     */
    private function missingUndeclaredRequiredName(string $typeName, array $unionConstraints, mixed $rawValue): ?string
    {
        $fields = $rawValue instanceof stdClass ? get_object_vars($rawValue) : $rawValue;
        if (!is_array($fields)) {
            return null;
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($unionConstraints[$keyword] ?? null) ? $unionConstraints[$keyword] : [] as $branch) {
                $branchClass = is_array($branch) ? ($branch['x-php-instanceof'] ?? null) : null;
                if (!is_string($branchClass) || ltrim($branchClass, '\\') !== ltrim($typeName, '\\')) {
                    continue;
                }
                foreach (is_array($branch['required'] ?? null) ? $branch['required'] : [] as $name) {
                    if (is_string($name) && !array_key_exists($name, $fields)) {
                        return $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether a union property's constraints are a `oneOf` with no `discriminator` to pick the member:
     * the payload must then match exactly one class member, not merely the first. Where a discriminator
     * is declared, its tag chooses — the validator checks the built member against it — and members that
     * overlap apart from the tag are the norm, not a second match.
     */
    private function unionMustMatchExactlyOne(mixed $constraints): bool
    {
        return is_array($constraints)
            && is_array($constraints['oneOf'] ?? null)
            && !array_key_exists('x-discriminator-map', $constraints)
            && !array_key_exists('x-discriminator-property', $constraints);
    }

    /**
     * Whether a union member is built from an object payload — a generated class, or a union interface
     * nested in this one — and so counts toward a `oneOf`'s single match.
     */
    private function isObjectUnionMember(string $typeName): bool
    {
        return $this->resolveTypeKind($typeName) === 'dto' || interface_exists($typeName);
    }

    /**
     * The member classes a property or item typed with a generated union INTERFACE is built from, and
     * whether exactly one may accept the payload — read from the owner's `getConstraints()`, where the
     * generator spells such a union as `oneOf` / `anyOf` branches "is that class". Null for any other
     * type, or when the constraints name no member of it.
     *
     * @param array<array-key, mixed> $constraints
     * @return array{members: list<string>, exactlyOne: bool}|null
     */
    private function unionMembersFromConstraints(string $typeName, array $constraints): ?array
    {
        if (!interface_exists($typeName)) {
            return null;
        }
        $branches = is_array($constraints['oneOf'] ?? null) ? $constraints['oneOf'] : ($constraints['anyOf'] ?? null);
        if (!is_array($branches)) {
            return null;
        }
        $members = [];
        foreach ($branches as $branch) {
            $member = is_array($branch) ? ($branch['x-php-instanceof'] ?? null) : null;
            if (is_string($member) && is_subclass_of($member, $typeName)) {
                $members[] = $member;
            }
        }

        return $members === [] ? null : ['members' => $members, 'exactlyOne' => $this->unionMustMatchExactlyOne($constraints)];
    }

    /**
     * The constraints of a container's members: its `items`, or its `additionalProperties` schema.
     *
     * @param array<array-key, mixed> $constraints
     * @return array<array-key, mixed>
     */
    private static function containerItemConstraints(array $constraints): array
    {
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (is_array($constraints[$keyword] ?? null)) {
                return $constraints[$keyword];
            }
        }

        return [];
    }

    /**
     * The member an inline `discriminator` names, as the only type to build — or every type, when the
     * union has no discriminator or the tag names none of them.
     *
     * Tried in declaration order instead, members that overlap (each requires only the tag, and takes
     * the other's keys as extra ones) were all built as the FIRST: `{"kind": "square", "side": 2}`
     * became a `Circle`, and the validator refused it as not the class its tag names. The map holds
     * class names as the generator wrote them, relative to the DTO's own namespace.
     *
     * @param array<int, string> $typeNames
     * @return array<int, string>
     */
    private function discriminatedUnionMember(array $typeNames, mixed $value, mixed $constraints): array
    {
        $map = is_array($constraints) ? ($constraints['x-discriminator-map'] ?? null) : null;
        $property = is_array($constraints) ? ($constraints['x-discriminator-property'] ?? null) : null;
        if (!is_array($map) || !is_string($property)) {
            return $typeNames;
        }
        $fields = $value instanceof stdClass ? get_object_vars($value) : $value;
        $tag = is_array($fields) ? ($fields[$property] ?? null) : null;
        $mapped = is_string($tag) || is_int($tag) ? ($map[$tag] ?? null) : null;
        if (!is_string($mapped) || $mapped === '') {
            return $typeNames;
        }
        foreach ($typeNames as $typeName) {
            if (str_ends_with('\\' . $typeName, '\\' . ltrim($mapped, '\\'))) {
                return [$typeName];
            }
        }

        return $typeNames;
    }

    /**
     * The branch loop of `castUnionValue()`: the first type the value casts to wins, in declaration
     * order; when none does, every branch's complaint is reported.
     *
     * Under `oneOf` (`$exactlyOne`) a CLASS that accepted the value does not end the loop: every later
     * class member is tried on the same raw value, and a second one that accepts it refuses the value.
     * Building the first and stopping let `{"cat": 1, "dog": 2}` through `oneOf: [$ref Olive, $ref Teal]`
     * as an Olive, `dog` dropped — the validator judges a class branch by the object it is handed and
     * never saw the raw value Teal would also have taken. A scalar member keeps first-match: a query
     * string casts to more than one of them, and the validator counts those on the JSON value.
     *
     * @param array<int, string> $typeNames
     * @param ReflectionClass<object>|null $dtoReflection
     * @param array<array-key, mixed> $unionConstraints
     */
    private function castFirstMatchingUnionBranch(
        string $paramName,
        array $typeNames,
        mixed $rawValue,
        string $rawSource,
        ?string $arrayItemType,
        bool $schemaAllowsNull,
        ?ReflectionClass $dtoReflection,
        ?string $openApiFormat,
        bool $allowsAssociativeArray,
        bool $alsoAllowsJsonList,
        bool $arrayItemsNullable,
        ?string $arrayItemTemporalFormat,
        ?string $nestedArrayItemType,
        bool $nestedArrayItemsNullable,
        bool $exactlyOne = false,
        array $unionConstraints = [],
    ): mixed {
        $errors = [];
        $matched = false;
        $match = null;
        foreach ($typeNames as $typeName) {
            if ($matched && !$this->isObjectUnionMember($typeName)) {
                continue;
            }
            $temporalFormat = $typeName === DateTimeImmutable::class && $dtoReflection !== null
                ? $this->resolveTemporalFormat(
                    reflection: $dtoReflection,
                    paramName: $paramName,
                    openApiFormat: $openApiFormat,
                )
                : null;

            try {
                $cast = $this->castValue(
                    value: $rawValue,
                    paramName: $paramName,
                    typeName: $typeName,
                    allowsNull: false,
                    source: $rawSource,
                    arrayItemType: $arrayItemType,
                    paramPath: $paramName,
                    schemaAllowsNull: $schemaAllowsNull,
                    temporalFormat: $temporalFormat,
                    openApiFormat: $openApiFormat,
                    allowsAssociativeArray: $allowsAssociativeArray,
                    alsoAllowsJsonList: $alsoAllowsJsonList,
                    arrayItemsNullable: $arrayItemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
                    nestedArrayItemType: $nestedArrayItemType,
                    nestedArrayItemsNullable: $nestedArrayItemsNullable,
                );
            } catch (RuntimeException $e) {
                if (!$matched) {
                    $errors[] = $e->getMessage();
                }

                continue;
            }
            // A class has no slot for a `required` name its branch does not declare: built, it says
            // nothing about that name, and the raw value answers instead.
            $missingName = is_object($cast) ? $this->missingUndeclaredRequiredName($typeName, $unionConstraints, $rawValue) : null;
            if ($missingName !== null) {
                if (!$matched) {
                    $errors[] = "param \"{$paramName}\".{$missingName} is required";
                }

                continue;
            }
            if ($matched) {
                throw new RuntimeException(
                    message: $this->finalizeParamMessage("param \"{$paramName}\" matches more than one allowed oneOf branch"),
                );
            }
            // A null, where the schema allows one, is no member at all: every class "accepts" it.
            if (!$exactlyOne || !is_object($cast) || !$this->isObjectUnionMember($typeName)) {
                return $cast;
            }
            $matched = true;
            $match = $cast;
        }
        if ($matched) {
            return $match;
        }

        throw new RuntimeException(implode("\n", array_values(array_unique(DtoServiceSupport::finalizeMessages($errors, 'param "')))));
    }

    /**
     * @return array<string, mixed>
     */
    private function getBodyData(Request $request): array
    {
        $preDecoded = $request->attributes->get(self::PREDECODED_BODY_ATTRIBUTE);
        if (is_array($preDecoded)) {
            return $preDecoded;
        }

        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $contentType = (string)$request->headers->get('Content-Type', '');
        if (!$this->isJsonMediaType($contentType)) {
            return [];
        }

        // Decode without assoc flag so JSON objects become stdClass,
        // allowing us to distinguish {} (object) from [] (array).
        $decoded = json_decode($content, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Json is not valid: ' . json_last_error_msg());
        }

        if (!$decoded instanceof stdClass) {
            throw new RuntimeException(
                message: 'JSON body must be an object, got ' . gettype($decoded),
            );
        }

        return $this->stdClassToArray($decoded);
    }

    /**
     * Every occurrence of each bare query key, in order: `?ids=1&ids=2` → `['ids' => ['1', '2']]`.
     *
     * `parse_str()` — and therefore `$_GET` and `$request->query` — keeps only the last, which
     * loses OpenAPI's default array serialization entirely. This reads the repeats back off the raw
     * QUERY_STRING for the one caller that needs them (see the deserialize() loop).
     *
     * Bracketed keys are skipped: `ids[]=1&ids[]=2` is PHP's own spelling and `parse_str()` has
     * already built that array, so collecting it again would double it. Decoding normally matches
     * `$request->query`; allowReserved instead preserves literal plus signs, like getRawQueryData().
     *
     * @return array<string, list<string>>
     */
    private function getQueryValueLists(Request $request, bool $allowReserved = false): array
    {
        return self::collectRepeatedValues((string)$request->server->get('QUERY_STRING', ''), $allowReserved);
    }

    /**
     * The same, for an `application/x-www-form-urlencoded` BODY.
     *
     * A form body is the same `key=value&key=value` text as a query string, and PHP loses repeats
     * there for the same reason — `$_POST` is built by the same parser. The Encoding Object gives a
     * form-encoded array the same default as a query one, `style: form, explode: true`, so
     * `tags=a&tags=b` is the documented spelling and was refused with `expects array, got string`.
     *
     * Only urlencoded, and NULL for anything else — which the caller reads as "leave the value
     * alone", not as "no repeats found". A multipart body is not `&`-separated pairs but parts with
     * boundaries, already separated by Symfony into `$request->request` and `$request->files`;
     * nothing here would parse it. Returning the empty list for it would be worse than doing
     * nothing: the caller falls back to wrapping the single value it has, so a multipart field that
     * really was repeated would come back as a one-element array — the last part, silently, where
     * the reader used to get a loud `expects array, got string`. Quiet truncation is the one
     * outcome worth avoiding here.
     *
     * @return array<string, list<string>>|null
     */
    private function getFormValueLists(Request $request): ?array
    {
        $contentType = strtolower(trim(explode(';', (string)$request->headers->get('Content-Type', ''))[0]));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            return null;
        }

        return self::collectRepeatedValues($request->getContent());
    }

    /**
     * Every occurrence of each bare key in one `a=1&b=2` string, in order.
     *
     * Query and form callers share the same pair handling. Only allowReserved query parameters
     * preserve literal plus signs; ordinary query parameters and urlencoded bodies decode spaces.
     *
     * @return array<string, list<string>>
     */
    private static function collectRepeatedValues(string $encoded, bool $allowReserved = false): array
    {
        if ($encoded === '') {
            return [];
        }

        $lists = [];
        foreach (self::inputPairs($encoded) as $pair) {
            $eqPos = strpos($pair, '=');
            $key = $eqPos === false ? $pair : substr($pair, 0, $eqPos);
            if (str_contains($key, '[')) {
                continue;
            }

            $value = $eqPos === false ? '' : substr($pair, $eqPos + 1);
            // `allowReserved` is about the VALUE; a parameter name is decoded the way a form decodes it.
            $decodedKey = urldecode($key);
            $lists[$decodedKey][] = $allowReserved ? rawurldecode($value) : urldecode($value);
        }

        return $lists;
    }

    /**
     * The `&`-separated pairs of a query string or urlencoded body, as far as PHP itself reads them.
     *
     * Both re-reads above walk text PHP has already parsed once, into `$_GET` or `$_POST`, and that
     * parser stops after `max_input_vars` pairs — the limit exists because array keys are hashed
     * without a random seed, so a client can send thousands of keys sharing one bucket and make
     * every insert walk all the others. The raw body knows no limit: a 2 MB form of colliding names
     * cost seconds of CPU here, while PHP's own parse of the same body stopped at the limit. The
     * re-read stops at the same pair, so it costs what PHP allowed and sees what PHP saw — a pair
     * PHP dropped is not one the caller ever had.
     *
     * Empty pairs (`a=1&&b=2`) are skipped and not counted, as PHP skips them. Walked with `strpos()`
     * rather than `explode()`: a body of nothing but `&` would otherwise become an array of millions
     * of empty strings before the first one was looked at.
     *
     * @return list<string>
     */
    private static function inputPairs(string $encoded): array
    {
        $limit = (int)ini_get('max_input_vars');
        if ($limit <= 0) {
            $limit = self::DEFAULT_MAX_INPUT_VARS;
        }

        $pairs = [];
        $length = strlen($encoded);
        $offset = 0;
        while ($offset < $length && count($pairs) < $limit) {
            $end = strpos($encoded, '&', $offset);
            if ($end === false) {
                $end = $length;
            }
            if ($end > $offset) {
                $pairs[] = substr($encoded, $offset, $end - $offset);
            }
            $offset = $end + 1;
        }

        return $pairs;
    }

    /**
     * The value of one cookie, read from the `Cookie` header under the name it was sent with.
     *
     * PHP keeps the FIRST of two same-named cookies — the most specific path, by the order browsers
     * send them — and decodes the value as a form value; both are mirrored so the two readings agree.
     */
    private function findCookieInHeader(Request $request, string $name): ?string
    {
        foreach (explode(';', (string)$request->headers->get('cookie', '')) as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos !== false && urldecode(trim(substr($pair, 0, $eqPos))) === $name) {
                return urldecode(trim(substr($pair, $eqPos + 1)));
            }
        }

        return null;
    }

    /**
     * Every value of one cookie as the `Cookie` header carries it — OAS 3.2 `style: cookie`, where
     * neither the name nor the value is percent-encoded — in the order sent.
     *
     * @return list<string>
     */
    private function findRawCookieValues(Request $request, string $name): array
    {
        $values = [];
        foreach (explode(';', (string)$request->headers->get('cookie', '')) as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos !== false && trim(substr($pair, 0, $eqPos)) === $name) {
                $values[] = trim(substr($pair, $eqPos + 1));
            }
        }

        return $values;
    }

    /**
     * Parses the raw query string, keeping every parameter name exactly as sent.
     *
     * With `$preservePlus` a literal plus sign in a value stays a plus — the allowReserved reading.
     * Without it a value decodes the way `$request->query` decodes it, and the only difference left
     * is the names: `parse_str()` renames `page.size` to `page_size`, this does not.
     *
     * @return array<string, mixed>
     */
    private function getRawQueryData(Request $request, bool $preservePlus = true): array
    {
        $queryString = (string)$request->server->get('QUERY_STRING', '');
        if ($queryString === '') {
            return [];
        }

        // Parse query string while preserving + signs in values (allowReserved support).
        // Use rawurldecode to preserve encoded plus signs (%2B) and allow literal + in values.
        $result = [];
        foreach (self::inputPairs($queryString) as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                // No value: ?key → key => ''
                $this->assignRawQueryValue($result, urldecode($pair), '');
                continue;
            }

            $this->assignRawQueryValue(
                result: $result,
                // The name decodes as in a form (`+` is a space); only the value keeps a literal `+`.
                key: urldecode(substr($pair, 0, $eqPos)),
                value: $preservePlus ? rawurldecode(substr($pair, $eqPos + 1)) : urldecode(substr($pair, $eqPos + 1)),
            );
        }

        return $this->normalizeParsedQueryData($result);
    }

    /**
     * Places one raw query pair, following the bracket path in its key.
     *
     * This has to agree with `parse_str()` — the default (non-`allowReserved`) source is Symfony's
     * own query bag — because the two must differ ONLY in how `+` is decoded, never in the shape
     * they produce. The earlier version matched brackets with a greedy regex, so it saw
     * `foo[bar][baz]` as one key `foo[bar]` with subkey `baz`: nesting deeper than one level came out
     * with a bracket inside a key name. Every level is now walked.
     *
     * `foo[]` appends, `foo[k]` sets a key, `foo[a][b]` nests, and a key with no closing bracket is
     * kept verbatim (as `parse_str()` also refuses to interpret it).
     *
     * @param array<string, mixed> $result
     */
    private function assignRawQueryValue(array &$result, string $key, string $value): void
    {
        $bracketPos = strpos($key, '[');
        if ($bracketPos === false) {
            $result[$key] = $value;

            return;
        }

        $baseKey = substr($key, 0, $bracketPos);
        $path = substr($key, $bracketPos);

        // Only the LEADING run of well-formed groups is a path; `parse_str()` ignores whatever
        // follows (`weird[a]x[b]` is `weird => [a => …]`), and turns a `[` that opens nothing into
        // an underscore (`noclose[a` is `noclose_a`).
        if (preg_match('/^((?:\[[^\[\]]*\])+)/', $path, $leading) !== 1) {
            $result[str_replace('[', '_', $key)] = $value;

            return;
        }

        preg_match_all('/\[([^\[\]]*)\]/', $leading[1], $matches);
        /** @var array<int, string> $segments an empty segment means "append" */
        $segments = $matches[1];

        // PHP drops a variable nested deeper than `max_input_nesting_level` — the whole name, with
        // whatever earlier pairs had already put under it — so `$request->query` does not have it.
        // The re-read drops it too: the two readings must differ in how `+` decodes, not in shape.
        $nestingLimit = (int)ini_get('max_input_nesting_level');
        if (count($segments) > ($nestingLimit > 0 ? $nestingLimit : self::DEFAULT_MAX_INPUT_NESTING_LEVEL)) {
            $result = array_diff_key($result, [$baseKey => true]);

            return;
        }

        /** @var array<array-key, mixed> $cursor */
        $cursor = &$result;
        $cursorKey = $baseKey;

        foreach ($segments as $segment) {
            if (!is_array($cursor[$cursorKey] ?? null)) {
                // A scalar already sitting here is replaced by the container it turns out to be —
                // `?a=1&a[b]=2` ends up as `a => [b => 2]`, which is what parse_str() does too.
                $cursor[$cursorKey] = [];
            }
            /** @var array<array-key, mixed> $next */
            $next = &$cursor[$cursorKey];
            $cursor = &$next;
            unset($next);

            if ($segment === '') {
                $cursor[] = null;
                /** @var int $lastKey */
                $lastKey = array_key_last($cursor);
                $cursorKey = $lastKey;
                continue;
            }

            $cursorKey = $segment;
        }

        $cursor[$cursorKey] = $value;
    }

    /**
     * Walks the parsed tree so the nested arrays carry a declared type.
     *
     * It used to SKIP non-string keys, which silently emptied every list: `?foo[]=1&foo[]=2` reached
     * an `allowReserved` parameter as `[]`, because `foo`'s members are integer-keyed. Integer keys
     * are kept now; only the top level is string-keyed, and that holds by construction (a query
     * parameter always has a name).
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function normalizeParsedQueryData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? $this->normalizeParsedQueryData($value) : $value;
        }

        return $result;
    }

    /**
     * Converts stdClass tree to array, but keeps stdClass leaves as-is
     * so castValue can detect JSON objects vs JSON arrays.
     *
     * @return array<string, mixed>
     */
    private function stdClassToArray(stdClass $obj): array
    {
        $result = [];
        foreach ((array)$obj as $key => $value) {
            if ($value instanceof stdClass) {
                $result[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->normalizeArrayValues($value);
                continue;
            }

            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveReflectionProperty(ReflectionClass $reflection, ?string $propName): ?ReflectionProperty
    {
        return $propName !== null ? $this->findPropertyInHierarchy($reflection, $propName) : null;
    }

    /**
     * A property of the class OR of any class it extends.
     *
     * `ReflectionClass::hasProperty()` and `::getProperty()` do not see a PRIVATE property of a
     * parent, and every generated property is private — so on an `allOf` child, which extends the
     * base class the composition resolved to, the base's properties were invisible: the item type
     * read off their docblock came back null and the elements of an array were handed to the
     * constructor exactly as `json_decode()` produced them (a string where the DTO declares an enum,
     * a `stdClass` where it declares a nested DTO). The tracking flags went the same way: the
     * `…InRequest` property could not be found, so it was never reset and `isXInRequest()` answered
     * true for a field the payload never carried.
     *
     * A `ReflectionProperty` obtained from the DECLARING class reads and writes the value on an
     * instance of the child perfectly well; it is only the lookup that stops at the class itself.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function findPropertyInHierarchy(ReflectionClass $reflection, string $name): ?ReflectionProperty
    {
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name);
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInRequestFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(reflection: $reflection, paramName: $paramName, suffixes: [
            'InRequest',
            'WasProvidedInRequest',
        ]);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInPathFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InPath'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInQueryFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InQuery'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInHeaderFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InHeader'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveInCookieFlagPropertyName(ReflectionClass $reflection, string $paramName): ?string
    {
        return $this->resolveTrackingFlagPropertyName(
            reflection: $reflection,
            paramName: $paramName,
            suffixes: ['InCookie'],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<string> $suffixes
     */
    private function resolveTrackingFlagPropertyName(
        ReflectionClass $reflection,
        string $paramName,
        array $suffixes,
    ): ?string {
        $candidates = [];
        foreach ($suffixes as $suffix) {
            $candidates[] = $this->normalizeTrackingFlagName(propertyName: $paramName, suffix: $suffix);
            $candidates[] = $paramName . $suffix;
        }

        foreach ($candidates as $candidate) {
            if ($this->findPropertyInHierarchy($reflection, $candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeTrackingFlagName(string $propertyName, string $suffix): string
    {
        $split = preg_split('/[^A-Za-z0-9]+/', $propertyName);
        $parts = $split !== false ? $split : [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            $camel = 'value';
        } else {
            $first = $parts[0];
            $camel = strtoupper($first) === $first ? strtolower($first) : lcfirst($first);

            for ($i = 1, $count = count($parts); $i < $count; $i++) {
                $part = $parts[$i];
                $camel .= ucfirst(strtolower($part));
            }
        }

        if (is_numeric($camel[0])) {
            $camel = '_' . $camel;
        }

        return $camel . $suffix;
    }

    /**
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    private function normalizeArrayValues(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if ($v instanceof stdClass) {
                // Keep as stdClass so castValue can flag it as object
                $arr[$k] = $v;
            } elseif (is_array($v)) {
                $arr[$k] = $this->normalizeArrayValues($v);
            }
        }
        return $arr;
    }

    /**
     * Resolves (and memoizes) the cast "kind" for a declared type name.
     * One of: int, float, string, bool, array, mixed, datetime, file, enum, dto, unknown.
     */
    private function resolveTypeKind(string $typeName): string
    {
        return self::$typeKindCache[$typeName] ??= match ($typeName) {
            'int' => 'int',
            'float' => 'float',
            'string' => 'string',
            'bool' => 'bool',
            'array' => 'array',
            // The LIST form of a nested container. Same cast as `array`, different wire shape: its
            // items must arrive as JSON arrays, not objects.
            'list' => 'list',
            // A bare `array` item: either container, as the declaration says nothing more.
            'container' => 'container',
            'mixed' => 'mixed',
            DateTimeImmutable::class => 'datetime',
            UploadedFile::class => 'file',
            // A DTO is a class of the application, never one of PHP's own. A type name is read from
            // the DTO's source, and a built-in class named there would be constructed with values
            // straight from the request.
            default => enum_exists($typeName)
                ? 'enum'
                : (class_exists($typeName) && !(new ReflectionClass($typeName))->isInternal() ? 'dto' : 'unknown'),
        };
    }

    /**
     * @param array<array-key, mixed> $constraints the field's constraints, which name the members of a union interface
     */
    private function castValue(
        mixed $value,
        string $paramName,
        string $typeName,
        bool $allowsNull,
        string $source,
        ?string $arrayItemType = null,
        ?string $paramPath = null,
        bool $schemaAllowsNull = false,
        ?string $temporalFormat = null,
        ?string $openApiFormat = null,
        bool $allowsAssociativeArray = false,
        bool $arrayItemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
        ?string $nestedArrayItemType = null,
        bool $nestedArrayItemsNullable = false,
        bool $alsoAllowsJsonList = false,
        array $constraints = [],
    ): mixed {
        $paramPath ??= $paramName;
        if ($value === null) {
            if ($source === 'json') {
                // Explicit null in JSON body: only allowed if schema declares nullable: true
                if ($schemaAllowsNull) {
                    return null;
                }
                throw new RuntimeException(DtoServiceSupport::finalizeMessage(
                    message: "param \"{$paramPath}\" expects {$typeName}, got null",
                    subject: 'param "',
                ));
            }
            if ($allowsNull) {
                return null;
            }
            throw new RuntimeException(
                message: "Cannot cast null to non-nullable type {$typeName}.",
            );
        }

        // Free-form value (schema-less property → `mixed`): accept any non-null value as-is. A JSON
        // object stays stdClass: as an array `{}` became `[]` and `{"0":"x"}` a list on the way back.
        if ($typeName === 'mixed') {
            return $value;
        }

        // JSON should stay strict: no implicit scalar conversions.
        if ($source === 'json') {
            if ($typeName === 'int') {
                // Strict still means "an integer per the spec": JSON Schema 2020-12 §6.1.1 counts a
                // number with a zero fractional part as one, and PHP decodes `42.0` to a float. A real
                // fractional part, an out-of-range magnitude and a numeric STRING are all still refused.
                if (!is_int($value) && !(is_float($value) && $this->isStrictIntValue($value))) {
                    // An integer too large for PHP arrives as a float, so the type message would
                    // answer "expects int, got float" to someone who did send an integer. Say what
                    // actually happened instead; a float with a real fractional part keeps the
                    // type message, because there the type IS the problem.
                    throw new RuntimeException(
                        message: is_float($value) && is_finite($value) && floor($value) === $value
                            ? sprintf(
                                'param "%s" must be within integer range (%s to %s), got %s',
                                $paramPath,
                                (string)PHP_INT_MIN,
                                (string)PHP_INT_MAX,
                                // `%.0F` writes no decimal point, so every trailing zero is significant: 1e20 is
                                // `100000000000000000000`, not `1`.
                                sprintf('%.0F', $value),
                            )
                            : $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'int', value: $value),
                    );
                }

                return (int)$value;
            }

            if ($typeName === 'float') {
                if (!is_float($value) && !is_int($value)) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'float', value: $value),
                    );
                }
                // `1e400` decodes to INF, which toJson() cannot write back: a bad request, not a 500.
                if (is_float($value) && !is_finite($value)) {
                    throw new RuntimeException(
                        message: $this->finalizeParamMessage("param \"{$paramPath}\" must be a finite number"),
                    );
                }
                return (float)$value;
            }

            if ($typeName === 'string') {
                if (!is_string($value)) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'string', value: $value),
                    );
                }

                return $value;
            }

            if ($typeName === 'bool') {
                if (!is_bool($value)) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'bool', value: $value),
                    );
                }
                return $value;
            }

            if ($typeName === 'array') {
                // Remembered BEFORE the conversion: `{"0":1,"1":2}` is a JSON object and decodes to a
                // PHP list, so after `stdClassToArray()` it is indistinguishable from `[1,2]`.
                $cameInAsJsonObject = $value instanceof stdClass;

                if ($value instanceof stdClass) {
                    if (!$allowsAssociativeArray) {
                        throw new RuntimeException(
                            message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: 'object'),
                        );
                    }
                    $value = $this->stdClassToArray($value);
                }

                if (!is_array($value)) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: $value),
                    );
                }

                if (!array_is_list($value) && !$allowsAssociativeArray) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: 'object'),
                    );
                }

                // The mirror case: `type: object` means a JSON OBJECT, and a JSON array decoded into a
                // list would silently become a map keyed 0..n-1. Here — unlike after hydration — the two
                // are still distinguishable, so the wire shape is checked at the source. An empty array
                // is refused only in a body decoded here, where `{}` would have stayed a `stdClass`;
                // a pre-decoded value reports `{}` exactly that way.
                // A union naming an array branch beside the object one takes either.
                if (
                    $allowsAssociativeArray
                    && !$alsoAllowsJsonList
                    && !$cameInAsJsonObject
                    && array_is_list($value)
                    && ($value !== [] || $this->jsonShapesKnown)
                ) {
                    throw new RuntimeException(
                        message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'object', value: 'array'),
                    );
                }

                if ($arrayItemType === null) {
                    return $value;
                }

                return $this->castArrayItems(
                    items: $value,
                    arrayItemType: $arrayItemType,
                    paramPath: $paramPath,
                    source: $source,
                    itemsNullable: $arrayItemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
                    nestedArrayItemType: $nestedArrayItemType,
                    nestedArrayItemsNullable: $nestedArrayItemsNullable,
                    itemConstraints: self::containerItemConstraints($constraints),
                );
            }
        }

        // Handle scalar types
        if ($typeName === 'int') {
            if (!$this->isStrictIntValue($value)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'int', value: $value),
                );
            }

            return (int)$value;
        }

        if ($typeName === 'float') {
            if ((is_float($value) || is_string($value)) && is_numeric($value) && !is_finite((float)$value)) {
                throw new RuntimeException(
                    message: $this->finalizeParamMessage("param \"{$paramPath}\" must be a finite number"),
                );
            }
            if (!$this->isStrictFloatValue($value)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'float', value: $value),
                );
            }

            return (float)$value;
        }

        if ($typeName === 'string') {
            if (is_array($value) || $value instanceof stdClass || is_object($value)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'string', value: $value),
                );
            }

            return (string)$value;
        }

        if ($typeName === 'bool') {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                $lower = strtolower($value);
                if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                    return false;
                }
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'bool', value: $value),
                );
            }
            // Numeric scalars coerce (0/1 → bool) to match the lenient string handling above.
            // Arrays, objects and other non-scalars are NOT booleans — reject instead of the
            // silent (bool) cast, which would turn e.g. a multi-value form field into true.
            if (is_int($value) || is_float($value)) {
                return (bool)$value;
            }
            throw new RuntimeException(
                message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'bool', value: $value),
            );
        }

        if ($typeName === 'array') {
            if (!is_array($value)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'array', value: $value),
                );
            }
            $arrayValue = $value;

            if ($arrayItemType === null) {
                return $arrayValue;
            }

            return $this->castArrayItems(
                items: $arrayValue,
                arrayItemType: $arrayItemType,
                paramPath: $paramPath,
                source: $source,
                itemsNullable: $arrayItemsNullable,
                arrayItemTemporalFormat: $arrayItemTemporalFormat,
                nestedArrayItemType: $nestedArrayItemType,
                nestedArrayItemsNullable: $nestedArrayItemsNullable,
                itemConstraints: self::containerItemConstraints($constraints),
            );
        }

        // Object/class kinds: resolved once per type (cached), avoiding enum_exists()/
        // class_exists() on every value. Scalars are already handled above.
        $kind = $this->resolveTypeKind($typeName);

        if ($kind === 'datetime') {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if (is_string($value)) {
                return $this->parseDateTimeStrict(
                    value: $value,
                    paramPath: $paramPath,
                    temporalFormat: $temporalFormat,
                );
            }
            throw new RuntimeException(
                message: $this->finalizeParamMessage("param \"{$paramPath}\" expects a date string, got {$this->getTypeString($value)}"),
            );
        }

        if ($kind === 'file') {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            throw new RuntimeException('Expected UploadedFile but got something else.');
        }

        if ($kind === 'enum') {
            return $this->castToEnum(value: $value, enumClass: $typeName, paramPath: $paramPath, source: $source);
        }

        if ($kind === 'dto') {
            $decodedObject = $value instanceof stdClass ? $value : null;
            if ($value instanceof stdClass) {
                $value = $this->stdClassToArray($value);
            } elseif (is_array($value) && ($value !== [] || ($source === 'json' && $this->jsonShapesKnown)) && array_is_list($value)) {
                // Same rule as a map: a nested object is a JSON OBJECT, and a JSON array reaching here
                // used to be deserialized as one — every key missing, so a schema with no required
                // property accepted `[1,2]` as an object. `[]` is left alone only where it may be `{}`:
                // in a pre-decoded value, not in a body decoded here — see `$jsonShapesKnown`.
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'object', value: 'array'),
                );
            }
            if (is_array($value)) {
                $targetDtoClass = $this->resolveDiscriminatorTargetClass(
                    baseClass: $typeName,
                    value: $value,
                    paramPath: $paramPath,
                ) ?? $typeName;
                // Recursively deserialize nested DTO.
                // $targetDtoClass existence already guaranteed: resolveDiscriminatorTargetClass()
                // either throws on unknown class, or returns null and we fall back to $typeName
                // (already validated by the 'dto' kind resolution above).
                /** @var class-string<object> $targetDtoClass */
                return $this->hydrateNestedObject(
                    decoded: $decodedObject,
                    data: $value,
                    dtoClass: $targetDtoClass,
                    source: $source,
                    paramPath: $paramPath,
                );
            }
            throw new RuntimeException(
                message: $this->finalizeParamMessage(
                    message: "param \"{$paramPath}\": Cannot deserialize nested DTO {$typeName} from non-array value",
                ),
            );
        }

        $union = $this->unionMembersFromConstraints(typeName: $typeName, constraints: $constraints);
        if ($union !== null) {
            return $this->castUnionValue(
                paramName: $paramPath,
                typeNames: $union['members'],
                rawValue: $value,
                rawWasProvided: true,
                rawSource: $source,
                arrayItemType: null,
                schemaAllowsNull: $schemaAllowsNull,
                dtoReflection: null,
                openApiFormat: null,
                allowsAssociativeArray: false,
                exactlyOne: $union['exactlyOne'],
                unionConstraints: $constraints,
            );
        }

        throw new RuntimeException(
            message: "Unsupported type: {$typeName}",
        );
    }

    /**
     * @param array<array-key, mixed> $items
     * @param array<array-key, mixed> $itemConstraints
     * @return array<array-key, mixed>
     */
    private function castArrayItems(
        array $items,
        string $arrayItemType,
        string $paramPath,
        string $source,
        bool $itemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
        ?string $nestedArrayItemType = null,
        bool $nestedArrayItemsNullable = false,
        array $itemConstraints = [],
    ): array {
        $normalized = [];
        $errors = [];
        $isJson = $source === 'json';
        foreach ($items as $index => $itemValue) {
            if ($isJson && self::isJsonScalarAlready($arrayItemType, $itemValue)) {
                $normalized[$index] = $itemValue;
                continue;
            }

            $itemPath = $paramPath . '.' . $index;
            $gaveUpBefore = $this->engineGaveUpCount;
            try {
                $normalized[$index] = $this->castArrayItemValue(
                    itemValue: $itemValue,
                    arrayItemType: $arrayItemType,
                    itemPath: $itemPath,
                    source: $source,
                    itemsNullable: $itemsNullable,
                    arrayItemTemporalFormat: $arrayItemTemporalFormat,
                    nestedArrayItemType: $nestedArrayItemType,
                    nestedArrayItemsNullable: $nestedArrayItemsNullable,
                    itemConstraints: $itemConstraints,
                );
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
                // A member the regex engine gave up on settles the request, and every member after it
                // would burn the whole backtrack limit again — once per member, ~2 ms each, seconds for
                // a list of a few thousand. A list nested in this member stopped the same way, and the
                // count it raised is seen here as well, so the stop holds at any depth. An ordinary
                // mismatch is a verdict, not this, and every member that has one is still reported.
                if ($this->engineGaveUpCount > $gaveUpBefore) {
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", DtoServiceSupport::finalizeMessages($errors, 'param "')));
        }

        return $normalized;
    }

    /**
     * Whether a decoded JSON value already IS the scalar type it is to be cast to — the one case where
     * {@see castValue()} returns it untouched. Asked first on the two hottest paths, a body field and
     * a list element, so the common value skips a fifteen-argument call to learn nothing. Anything
     * else (a null, `42.0` for an int, an int for a float, a mismatch) still takes the full cast, which
     * keeps every conversion and every message where it was.
     */
    private static function isJsonScalarAlready(string $typeName, mixed $value): bool
    {
        return match ($typeName) {
            'int' => is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            // INF is a float, and not one the wire can carry back: it goes to the full cast to be refused.
            'float' => is_float($value) && is_finite($value),
            default => false,
        };
    }

    /**
     * @param array<array-key, mixed> $itemConstraints
     */
    private function castArrayItemValue(
        mixed $itemValue,
        string $arrayItemType,
        string $itemPath,
        string $source,
        bool $itemsNullable = false,
        ?string $arrayItemTemporalFormat = null,
        ?string $nestedArrayItemType = null,
        bool $nestedArrayItemsNullable = false,
        array $itemConstraints = [],
    ): mixed {
        // A null element is accepted only when the items schema declares it nullable
        // (items: {nullable: true} or type containing null); otherwise it falls through
        // to the per-kind casts, which reject it with a clear type error.
        // A `mixed` item is the empty schema `{}`, and null is one of the values it admits; the
        // constraints still judge it. Refusing it here sent `{"j": null}` back as "expects mixed".
        if ($itemValue === null && ($itemsNullable || $arrayItemType === 'mixed')) {
            return null;
        }

        // Type kind resolved once per type (cached) instead of enum_exists()/class_exists()
        // per array element.
        $kind = $this->resolveTypeKind($arrayItemType);

        if (in_array($kind, ['int', 'float', 'string', 'bool', 'array', 'list', 'container', 'mixed'], true)) {
            return $this->castValue(
                value: $itemValue,
                paramName: $itemPath,
                // `list` and `container` are not PHP types: they are this method's names for the shape,
                // and the cast that follows is the `array` one either way.
                typeName: in_array($kind, ['list', 'container'], true) ? 'array' : $arrayItemType,
                allowsNull: false,
                source: $source,
                // NOT $arrayItemType: that one names THIS item, and forwarding it would cast the
                // item's own elements to the container they sit in. What belongs here is the type the
                // nested generic was holding — the `Tag` of `array<array<Tag>>` — which the item type
                // dropped when it collapsed to a shape. Null below that hop, and the declaration says
                // `mixed` there, which stays true.
                arrayItemType: $nestedArrayItemType,
                paramPath: $itemPath,
                arrayItemsNullable: $nestedArrayItemsNullable,
                // An `array` item is the MAP form — a list of maps is `array<array<string, V>>` — and
                // JSON decodes a map to stdClass, so the item must accept one and must not be a JSON
                // array. A `list` item is the opposite: `array<array<int>>` items arrive as JSON
                // arrays, and it is `resolveTypeKind()` that tells the two apart now.
                allowsAssociativeArray: $kind === 'array' || $kind === 'container',
                // A bare `array` item names no shape, so a JSON array is as good as an object: forced
                // into the map form, `{"ka": ["blue"]}` was refused as "expects object, got array".
                alsoAllowsJsonList: $kind === 'container',
            );
        }

        if ($kind === 'file') {
            if (!$itemValue instanceof UploadedFile) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'UploadedFile', value: $itemValue),
                );
            }
            return $itemValue;
        }

        if ($kind === 'enum') {
            return $this->castToEnum(value: $itemValue, enumClass: $arrayItemType, paramPath: $itemPath, source: $source);
        }

        if ($kind === 'datetime') {
            if ($itemValue instanceof DateTimeImmutable) {
                return $itemValue;
            }
            if (!is_string($itemValue)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'date string', value: $itemValue),
                );
            }
            // Array items carry the temporal format resolved from the items schema
            // (e.g. format: date → 'Y-m-d'); null means full date-time is expected.
            return $this->parseDateTimeStrict(value: $itemValue, paramPath: $itemPath, temporalFormat: $arrayItemTemporalFormat);
        }

        if ($kind === 'dto') {
            $decodedObject = $itemValue instanceof stdClass ? $itemValue : null;
            if ($itemValue instanceof stdClass) {
                $itemValue = $this->stdClassToArray($itemValue);
            } elseif (is_array($itemValue) && ($itemValue !== [] || ($source === 'json' && $this->jsonShapesKnown)) && array_is_list($itemValue)) {
                // The rule the single nested DTO already follows: a JSON array is not an object. Items
                // and `deserializeValue()` took `[1,2]` as an empty DTO and dropped the numbers. `[]`
                // stays allowed where it may be `{}` — a pre-decoded value, not a body decoded here.
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'object', value: 'array'),
                );
            }
            if (!is_array($itemValue)) {
                throw new RuntimeException(
                    message: $this->expectsTypeMessage(paramPath: $itemPath, expectedType: 'object', value: $itemValue),
                );
            }

            // resolveDiscriminatorTargetClass already uses $itemPath in its messages — don't re-wrap
            $targetClass = $this->resolveDiscriminatorTargetClass(baseClass: $arrayItemType, value: $itemValue, paramPath: $itemPath, mustExtendBase: false) ?? $arrayItemType;
            if (!class_exists($targetClass)) {
                throw new RuntimeException(
                    message: "Discriminator mapping for \"{$itemPath}\" points to unknown class \"{$targetClass}\".",
                );
            }

            /** @var class-string<object> $targetClass */
            return $this->hydrateNestedObject(
                decoded: $decodedObject,
                data: $itemValue,
                dtoClass: $targetClass,
                source: $source,
                paramPath: $itemPath,
            );
        }

        $union = $this->unionMembersFromConstraints(typeName: $arrayItemType, constraints: $itemConstraints);
        if ($union !== null) {
            return $this->castUnionValue(
                paramName: $itemPath,
                typeNames: $union['members'],
                rawValue: $itemValue,
                rawWasProvided: true,
                rawSource: $source,
                arrayItemType: null,
                schemaAllowsNull: false,
                dtoReflection: null,
                openApiFormat: null,
                allowsAssociativeArray: false,
                exactlyOne: $union['exactlyOne'],
                unionConstraints: $itemConstraints,
            );
        }

        throw new RuntimeException(
            message: "Cannot deserialize array item at \"{$itemPath}\": unknown type \"{$arrayItemType}\".",
        );
    }

    /**
     * One nested object hydrated as `$dtoClass`, its errors reported under `$paramPath`.
     *
     * Inside a union trial the outcome — the DTO, or the refusal — is remembered for the decoded
     * object it came from, or for the position of an array, and reused when another branch above
     * asks the same question; see `$unionTrialResults` and `$unionTrialArrayResults`. The refusal
     * is kept WITHOUT the path, which belongs to the asker.
     *
     * @template T of object
     * @param array<string, mixed> $data
     * @param class-string<T> $dtoClass
     * @return T
     */
    private function hydrateNestedObject(
        ?stdClass $decoded,
        array $data,
        string $dtoClass,
        string $source,
        string $paramPath,
    ): object {
        if ($this->unionTrialDepth === 0) {
            try {
                return $this->deserializeFromArray(data: $data, dtoClass: $dtoClass, source: $source);
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    message: $this->prependParamPath(message: $e->getMessage(), prefix: $paramPath),
                    previous: $e,
                );
            }
        }

        $resultKey = $dtoClass . '|' . $source;
        $positionKey = implode("\0", [...$this->unionTrialPath, $paramPath, $resultKey]);
        $known = null;
        if ($decoded !== null) {
            $known = $this->unionTrialResults !== null
                ? ($this->unionTrialResults[$decoded] ?? [])[$resultKey] ?? null
                : null;
        } else {
            $remembered = $this->unionTrialArrayResults[$positionKey] ?? null;
            // `===` on two arrays is a pointer compare when both are the one array the parent was
            // given, which is the case every branch above asks about; a full compare only on a
            // dotted-key clash.
            if ($remembered !== null && $remembered['data'] === $data) {
                $known = $remembered['outcome'];
            }
        }
        if ($known instanceof RuntimeException) {
            throw new RuntimeException(
                message: $this->prependParamPath(message: $known->getMessage(), prefix: $paramPath),
                previous: $known,
            );
        }
        if ($known instanceof $dtoClass) {
            return $known;
        }

        $this->unionTrialPath[] = $paramPath;
        try {
            $outcome = $this->deserializeFromArray(data: $data, dtoClass: $dtoClass, source: $source);
        } catch (RuntimeException $e) {
            $outcome = $e;
        } finally {
            array_pop($this->unionTrialPath);
        }

        if ($decoded !== null) {
            $results = $this->unionTrialResults ??= new WeakMap();
            $results[$decoded] = [...($results[$decoded] ?? []), $resultKey => $outcome];
        } else {
            $this->unionTrialArrayResults[$positionKey] = ['data' => $data, 'outcome' => $outcome];
        }

        if ($outcome instanceof RuntimeException) {
            throw new RuntimeException(
                message: $this->prependParamPath(message: $outcome->getMessage(), prefix: $paramPath),
                previous: $outcome,
            );
        }

        return $outcome;
    }

    private function prependParamPath(string $message, string $prefix): string
    {
        // Both spellings carry a subject: `param "x" expects int` and `Required parameter "x" not found
        // in request.` — a nested DTO's message named the bare key, so a missing `discriminator.id`
        // read exactly like a missing root `id`. A closed object's `Unknown property "x"` too: without
        // the path, a stray key in `closed` or in the second item of a list read as one at the root.
        return preg_replace_callback(
            pattern: '/\b(param|parameter|Unknown property) "([^"]+)"/i',
            callback: static fn(array $m): string => sprintf('%s "%s.%s"', $m[1], $prefix, $m[2]),
            subject: $message,
        ) ?? $message;
    }

    /**
     * Parses a date/time string strictly according to the schema format.
     * Rejects empty strings and strings that don't match the expected format.
     */
    private function parseDateTimeStrict(string $value, string $paramPath, ?string $temporalFormat): DateTimeImmutable
    {
        if ($value === '') {
            $hint = $this->temporalFormatHint($temporalFormat);
            throw new RuntimeException(
                message: $this->finalizeParamMessage("param \"{$paramPath}\" expects a valid date{$hint}, got empty string"),
            );
        }

        // date or date-time: a value in the date shape is the date branch, anything else must be a date-time.
        if ($temporalFormat === self::FORMAT_DATE_OR_DATE_TIME) {
            // In UTC: the union goes back out as a date-time, and a date read in the server's zone came
            // back as a different instant on every server — `+09:00` under Asia/Tokyo.
            $dt = DateTimeImmutable::createFromFormat('Y-m-d|', $value, new DateTimeZone('UTC'));
            if ($dt !== false && $dt->format('Y-m-d') === $value) {
                return $dt;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                throw new RuntimeException(
                    message: $this->finalizeParamMessage(
                        message: "param \"{$paramPath}\" expects a date in Y-m-d format or a valid date-time"
                        . " (e.g. 2026-03-10 or 2026-03-10T12:00:00+00:00), got \"{$value}\"",
                    ),
                );
            }
        }

        // date format: only Y-m-d
        if ($temporalFormat === 'Y-m-d') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d|', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                throw new RuntimeException(
                    message: $this->finalizeParamMessage(
                        message: "param \"{$paramPath}\" expects a date in Y-m-d format (e.g. 2026-03-10), got \"{$value}\"",
                    ),
                );
            }
            return $dt;
        }

        $expected = $temporalFormat === self::FORMAT_DATE_OR_DATE_TIME
            ? 'a date in Y-m-d format or a valid date-time (e.g. 2026-03-10 or 2026-03-10T12:00:00+00:00)'
            : 'a valid date-time (e.g. 2026-03-10T12:00:00+00:00)';

        // Reject structural mismatches before createFromFormat: it silently accepts trailing characters.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            throw new RuntimeException(
                message: $this->finalizeParamMessage("param \"{$paramPath}\" expects {$expected}, got \"{$value}\""),
            );
        }

        // date-time: try known RFC3339/ISO8601 formats; rejects relative strings like "now", "+1 year".
        foreach (GeneratedDtoInterface::DATE_TIME_FORMATS as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            // createFromFormat rolls overflowing components forward (e.g. Feb 30 → Mar 2) and
            // only flags it via warnings — reject those so invalid calendar dates aren't
            // silently accepted/mutated.
            $errors = DateTimeImmutable::getLastErrors();
            $hasWarnings = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
            if ($dt !== false && !$hasWarnings) {
                return $dt;
            }
        }

        throw new RuntimeException(
            message: $this->finalizeParamMessage("param \"{$paramPath}\" expects {$expected}, got \"{$value}\""),
        );
    }

    private function temporalFormatHint(?string $temporalFormat): string
    {
        return match ($temporalFormat) {
            'Y-m-d' => ' in Y-m-d format',
            self::FORMAT_DATE_OR_DATE_TIME => ' in Y-m-d format or a valid date-time',
            default => '-time',
        };
    }

    /**
     * Reads the temporal format for a DateTimeImmutable field from the getter docblock.
     * Looks for "Expected format: ..." comment generated by the DTO generator.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolveTemporalFormat(
        ReflectionClass $reflection,
        string $paramName,
        ?string $openApiFormat = null,
    ): ?string {
        if ($openApiFormat === 'date') {
            return 'Y-m-d';
        }

        if ($openApiFormat === 'date-time' || $openApiFormat === 'datetime') {
            return null;
        }

        if ($openApiFormat === self::FORMAT_DATE_OR_DATE_TIME) {
            return self::FORMAT_DATE_OR_DATE_TIME;
        }

        $getterName = 'get' . ucfirst($paramName);
        if (!$reflection->hasMethod($getterName)) {
            return null;
        }

        $docComment = $reflection->getMethod($getterName)->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // The last line wins: the generator writes it after the getter's description, which may say
        // "Expected format:" itself.
        if (preg_match_all('/^[ \t]*\*[ \t]*Expected format:[ \t]*(\S.*)$/miD', $docComment, $matches) < 1) {
            return null;
        }

        return trim((string)end($matches[1]));
    }

    /**
     * @param array<string, mixed>|null $constraints
     */
    private function resolveOpenApiFormat(?array $constraints): ?string
    {
        $format = $constraints['format'] ?? null;
        if (is_string($format) && $format !== '') {
            return $format;
        }

        return $this->resolveTemporalUnionFormat($constraints['oneOf'] ?? $constraints['anyOf'] ?? $constraints['allOf'] ?? null);
    }

    /**
     * The `date` / `date-time` format a union branch carries, itself or through the members of an
     * `allOf`, `oneOf` or `anyOf` inside it: `oneOf: [{allOf: [{type: string, format: date}]}]` is a
     * date, and read one level deep it was parsed as a date-time and every date refused.
     *
     * @param array<mixed> $branch
     * @return array<string, true>
     */
    private function temporalBranchFormats(array $branch, int $depth = 0): array
    {
        if (($branch['type'] ?? 'string') !== 'string') {
            return [];
        }
        $branchFormat = $branch['format'] ?? null;
        if (in_array($branchFormat, ['date', 'date-time'], true)) {
            return [$branchFormat => true];
        }
        if ($depth >= self::MAX_UNION_BRANCH_DEPTH) {
            return [];
        }
        $formats = [];
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach (is_array($branch[$keyword] ?? null) ? $branch[$keyword] : [] as $member) {
                if (is_array($member)) {
                    $formats += $this->temporalBranchFormats($member, $depth + 1);
                }
            }
        }

        return $formats;
    }

    /**
     * The format of a `oneOf`/`anyOf` whose every non-null branch is a `format: date` or `format: date-time`
     * string — the shape that hydrates into a single DateTimeImmutable. Any other branch means the union is
     * not purely temporal, and the answer is null, as before.
     */
    private function resolveTemporalUnionFormat(mixed $branches): ?string
    {
        if (!is_array($branches) || $branches === []) {
            return null;
        }

        $formats = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return null;
            }
            if (($branch['type'] ?? null) === 'null') {
                continue;
            }
            // A branch of another kind — `integer`, `boolean` — is tried by the union cast on its own;
            // it used to make the whole union "not temporal", and a date was then parsed as date-time.
            $formats += $this->temporalBranchFormats($branch);
        }

        if (count($formats) === 2) {
            return self::FORMAT_DATE_OR_DATE_TIME;
        }

        return array_key_first($formats);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveSchemaAllowsNull(ReflectionClass $reflection, string $paramName, bool $phpAllowsNull): bool
    {
        if (!$phpAllowsNull) {
            return false;
        }

        $constraints = $this->resolveOpenApiConstraints($reflection);
        $fieldConstraints = $constraints[$paramName] ?? null;
        if (is_array($fieldConstraints)) {
            if (array_key_exists('nullable', $fieldConstraints)) {
                return $fieldConstraints['nullable'] === true;
            }
            // OpenAPI 3.1: type: [string, null]
            $typeConstraint = $fieldConstraints['type'] ?? null;
            if ($typeConstraint === 'null' || (is_array($typeConstraint) && in_array('null', $typeConstraint, true))) {
                return true;
            }
            // A `oneOf` / `anyOf` member that admits null — `{type: number, nullable: true}`, `{type: null}`
            // — admits it for the union. An OPTIONAL property was read as "just optional" and its explicit
            // null refused as `expects string, got null`, where the same union on a required one took it.
            if ($this->aUnionMemberAdmitsNull($fieldConstraints)) {
                return true;
            }
        }

        $requiredMethodName = 'is' . ucfirst($paramName) . 'Required';
        if (!$reflection->hasMethod($requiredMethodName)) {
            return $phpAllowsNull;
        }

        // required + PHP-nullable → schema nullable:true → null is valid in JSON.
        // not required + PHP-nullable → just optional → null is NOT valid in JSON.
        return $this->invokeRequiredMethod($reflection, $requiredMethodName)
            ?? $phpAllowsNull;
    }

    /**
     * Whether a member of the schema's `oneOf` / `anyOf` admits null: OpenAPI 3.0 `nullable: true`, or a
     * `null` type of its own.
     *
     * @param array<array-key, mixed> $fieldConstraints
     */
    private function aUnionMemberAdmitsNull(array $fieldConstraints): bool
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $fieldConstraints[$keyword] ?? null;
            if (!is_array($members)) {
                continue;
            }
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $type = $member['type'] ?? null;
                if (($member['nullable'] ?? false) === true || $type === 'null' || (is_array($type) && in_array('null', $type, true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the array's items schema permits null elements (`items: {nullable: true}`
     * or an OpenAPI 3.1 type array containing "null").
     *
     * @param array<string, mixed>|null $fieldConstraints
     */
    private function resolveArrayItemsNullable(?array $fieldConstraints): bool
    {
        // `additionalProperties` as well as `items`: a map's VALUE schema is spelled the other way,
        // and reading only one of them made the two spellings of one schema disagree — a nullable
        // ITEM was accepted, a nullable map VALUE died in the cast with `expects string, got null`.
        $itemSchema = $fieldConstraints['items'] ?? $fieldConstraints['additionalProperties'] ?? null;
        if (!is_array($itemSchema)) {
            return false;
        }

        if (($itemSchema['nullable'] ?? false) === true) {
            return true;
        }

        $type = $itemSchema['type'] ?? null;

        return $type === 'null' || (is_array($type) && in_array('null', $type, true));
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveArrayItemType(ReflectionClass $reflection, string $paramName): ?string
    {
        $property = $this->findPropertyInHierarchy($reflection, $paramName);
        if ($property === null) {
            return null;
        }

        $docComment = $property->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $varType = $this->docVarType($docComment);
        $rawType = $varType === null ? null : $this->itemTypeFromDocComment($varType);
        if ($rawType === null) {
            return null;
        }

        // Qualified against the class that DECLARES the property, not the one being deserialized: a
        // short name in the docblock is resolved through the imports of the file it was written in,
        // and an inherited docblock was written in the parent's file. The two are the same file until
        // the base lives in another namespace (`--ref` output), and then the child's imports answer
        // for a name it never imported.
        return $this->qualifyDocTypeName($property->getDeclaringClass(), $rawType);
    }

    /**
     * The item type of a NESTED container — the `Tag` of `array<array<Tag>>`, and of the three other
     * ways to spell two containers deep.
     *
     * One hop only. `resolveArrayItemType()` above collapses the nested generic to the SHAPE it is,
     * because that is what decides the wire form the item must arrive in; the type it was holding is
     * dropped there, and this puts it back so the elements below can be hydrated instead of left as
     * the `stdClass` `json_decode()` produced. Below this hop nothing is cast and the declaration
     * says `mixed`, which stays true.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolveNestedArrayItemType(ReflectionClass $reflection, string $paramName): ?string
    {
        $property = $this->findPropertyInHierarchy($reflection, $paramName);
        if ($property === null) {
            return null;
        }

        $docComment = $property->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $varType = $this->docVarType($docComment);
        $itemType = $varType === null ? null : $this->genericValuePart($varType);
        if ($itemType === null || preg_match('/^\??(?:array|list)</i', $itemType) !== 1) {
            return null;
        }

        $nested = $this->genericValuePart($itemType);
        if ($nested === null || preg_match('/^\??([A-Za-z_\\\][A-Za-z0-9_\\\]*)$/D', $nested, $matches) !== 1) {
            return null;
        }

        $nestedType = $this->qualifyDocTypeName($property->getDeclaringClass(), $matches[1]);

        // A DTO and nothing else. What this hop adds is HYDRATION — turning the `stdClass`
        // `json_decode()` produced into the class the declaration names — and only a generated DTO has
        // one to be turned into. A nested scalar is already the value it claims to be and is already
        // checked, by `DtoValidator`, in the message shape three releases of consumers have; casting
        // it here would move the report to another layer and reword it for no gain. `mixed` is the
        // declaration for a depth where nothing is cast at all, and casting to it is not even a no-op:
        // the `stdClass` would come back an array, a shape the consumer never had.
        return $this->resolveTypeKind($nestedType) === 'dto' ? $nestedType : null;
    }

    /**
     * A doc-comment type name as the emitted file means it: a built-in stays itself, a short class
     * name is resolved through the file's `use` imports and then its namespace.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function qualifyDocTypeName(ReflectionClass $reflection, string $rawType): string
    {
        if (in_array($rawType, ['int', 'float', 'string', 'bool', 'array', 'list', 'container', 'mixed'], true)) {
            return $rawType;
        }

        if (str_contains($rawType, '\\')) {
            return $rawType;
        }

        // Resolve via file-level use imports (e.g. short name imported at top of file)
        $fileImports = $this->resolveFileImports($reflection);
        if (array_key_exists($rawType, $fileImports)) {
            return $fileImports[$rawType];
        }

        return $reflection->getNamespaceName() !== ''
            ? $reflection->getNamespaceName() . '\\' . $rawType
            : $rawType;
    }

    /**
     * The VALUE part of the first `array<…>` / `list<…>` in a string: `array<V>` and the map form
     * `array<string, V>` both yield `V`, with the key prefix dropped. Returns the part raw — a nested
     * generic comes back whole, which is what lets a caller descend into it.
     *
     * Bracket-aware on purpose. A flat regex matches the INNERMOST generic, so a list of maps,
     * `array<array<string, int>>`, used to report `int` as its item type and every item then failed
     * to cast with "expects int, got object" — the whole payload was undeserializable.
     */
    private function genericValuePart(string $type): ?string
    {
        if (preg_match('/\b(?:array|list)</i', $type, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $inner = '';
        $depth = 1;
        $length = strlen($type);
        for ($i = $match[0][1] + strlen($match[0][0]); $i < $length; $i++) {
            $character = $type[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $inner .= $character;
        }

        if ($depth !== 0 || $inner === '') {
            return null;
        }

        // Split key from value at depth 0 only: the comma inside `array<string, int>` is not ours.
        $depth = 0;
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            $character = $inner[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $inner = substr($inner, $i + 1);
                break;
            }
        }

        return trim($inner);
    }

    /**
     * The type of a docblock's `@var` tag — `?array<Gear>` — and nothing of the text around it. The
     * docblock opens with the document's own description, and a description that happened to say
     * `array<Plain>` used to decide what the items were. The last tag wins: the generator writes it
     * after the description, so a description line that begins with `@var` comes before it. The type
     * ends at the first blank outside `<>`, `()`, `{}` and `[]`.
     */
    private function docVarType(string $docComment): ?string
    {
        if (preg_match_all('~^[ \t]*(?:/\*\*|\*)?[ \t]*@var[ \t]+(\S.*)$~mD', $docComment, $matches) < 1) {
            return null;
        }
        $tag = (string)end($matches[1]);

        $depth = 0;
        $length = strlen($tag);
        for ($index = 0; $index < $length; $index++) {
            $character = $tag[$index];
            if (str_contains('<({[', $character)) {
                $depth++;
            } elseif (str_contains('>)}]', $character)) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ctype_space($character)) {
                return substr($tag, 0, $index);
            }
        }

        return rtrim($tag, " \t*/");
    }

    /**
     * The item type a doc comment declares, as a name the cast can use: a nested generic collapses to
     * the SHAPE it is, a plain name comes back as itself.
     */
    private function itemTypeFromDocComment(string $docComment): ?string
    {
        $inner = $this->genericValuePart($docComment);
        if ($inner === null) {
            return null;
        }

        // A generic item is a container as far as casting goes, but WHICH container decides the wire
        // shape the item must have: `array<array<string, V>>` items are JSON objects,
        // `array<array<int>>` items are JSON arrays. Collapsing both to `array` made the map form the
        // only one, so a matrix `[[1,2]]` was rejected with "expects object, got array".
        if (preg_match('/^\??(?:array|list)</i', $inner) === 1) {
            return $this->nestedContainerItemTypeName($inner);
        }
        if (strtolower(ltrim($inner, '?')) === 'array') {
            return 'container';
        }

        return preg_match('/^\??([A-Za-z_\\\][A-Za-z0-9_\\\]*)$/D', $inner, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Which container a nested generic is: `array` for the MAP form (`array<string, V>`), `list` for
     * the LIST form (`array<V>`, `list<V>`).
     *
     * Only the shape matters here — the item's own items are not cast at this depth, which is why the
     * generator stops naming types down there (`resolveNestedContainerDocType()`), and why
     * `DtoNormalizer::validate()` is the one that checks them.
     */
    private function nestedContainerItemTypeName(string $nestedType): string
    {
        $nestedType = ltrim($nestedType, '?');

        if (preg_match('/^list</i', $nestedType) === 1) {
            return 'list';
        }

        $open = strpos($nestedType, '<');
        if ($open === false) {
            return 'array';
        }

        // A comma at depth 0 of the generic's own content is the key/value separator, and a key is
        // what makes it a map.
        $depth = 0;
        $length = strlen($nestedType);
        for ($i = $open + 1; $i < $length; $i++) {
            $character = $nestedType[$i];
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                // `array<int, V>` is keyed by position: the list form, as `array<V>` is.
                return strtolower(trim(substr($nestedType, $open + 1, $i - $open - 1))) === 'int' ? 'list' : 'array';
            }
        }

        return 'list';
    }

    private function getTypeString(mixed $value): string
    {
        if ($value instanceof stdClass) {
            return 'object';
        }

        if (is_object($value)) {
            return 'object';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return 'array';
            }

            return 'object';
        }

        return match (gettype($value)) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            'NULL' => 'null',
            default => gettype($value),
        };
    }

    private function castToEnum(mixed $value, string $enumClass, ?string $paramPath = null, string $source = 'json'): UnitEnum
    {
        if (!enum_exists($enumClass)) {
            throw new RuntimeException(
                message: "Unsupported type: {$enumClass}",
            );
        }

        // Fetch and cache enum cases – avoids new ReflectionClass + cases() call on every cast.
        if (!array_key_exists($enumClass, self::$enumCasesCache)) {
            /** @var class-string<UnitEnum> $enumClass */
            $reflection = new ReflectionClass($enumClass);
            self::$enumCasesCache[$enumClass] = $reflection->getMethod('cases')->invoke(null);
        }

        /** @var list<UnitEnum> $cases */
        $cases = self::$enumCasesCache[$enumClass];

        $allowed = [];
        foreach ($cases as $case) {
            if ($case instanceof BackedEnum) {
                if ($case->value === $value) {
                    return $case;
                }
                // JSON has one number type: `1.0` is the integer 1 (JSON Schema 2020-12 §6.1.1), and
                // PHP decodes it to a float that never strict-equals the int case. A real fraction and
                // an out-of-range magnitude still match nothing.
                if (is_int($case->value) && is_float($value) && $this->isStrictIntValue($value) && (int)$value === $case->value) {
                    return $case;
                }
                // Non-JSON sources (query/path/form) deliver every value as a string, so an
                // int-backed enum case (value 1) never strict-equals the incoming "1". Allow a
                // string<->scalar match for those sources; JSON stays strict.
                if ($source !== 'json' && is_scalar($value) && (string)$case->value === (string)$value) {
                    return $case;
                }
                $allowed[] = (string)$case->value;
            } else {
                // Pure UnitEnum: match by name only
                if ($case->name === $value) {
                    return $case;
                }
                $allowed[] = $case->name;
            }
        }

        if ($paramPath !== null) {
            $allowedStr = implode(', ', $allowed);
            $actualValue = $this->formatValueForError($value);
            throw new RuntimeException(
                message: DtoServiceSupport::finalizeMessage(
                    message: "param \"{$paramPath}\" expects enum {$enumClass}, got {$actualValue}. Allowed: {$allowedStr}",
                    subject: 'param "',
                ),
            );
        }

        $actualValue = $this->formatValueForError($value);
        throw new RuntimeException(
            message: "Invalid enum value {$actualValue} for {$enumClass}.",
        );
    }

    private function isInternalUnsetValueType(string $typeName): bool
    {
        return $typeName === 'UnsetValue' || str_ends_with($typeName, '\UnsetValue');
    }

    private function formatValueForError(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return $this->getTypeString($value);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (array_key_exists($className, self::$constraintsCache)) {
            return self::$constraintsCache[$className];
        }

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getConstraints']);
        if ($method === null) {
            return self::$constraintsCache[$className] = [];
        }

        $constraints = call_user_func($method);
        return self::$constraintsCache[$className] = is_array($constraints) ? $constraints : [];
    }

    /**
     * The generated straight-line hydrator for this class, or null when it cannot be used here.
     *
     * Null whenever anything but the body could supply a value: an uploaded file, or a
     * router-verified request attribute carrying one of the declared names. Both outrank or join
     * the body in `resolveRawRequestValue()`, and the generated method sees only the body.
     *
     * @param ReflectionClass<object> $reflection
     * @param array<string, mixed> $classMeta
     * @param array<string, mixed> $bodyData
     * @return (callable(array<int, string>): object)|null
     */
    private function resolveFastHydrator(
        ReflectionClass $reflection,
        array $classMeta,
        ?Request $request,
        array $bodyData,
    ): ?callable {
        $className = $reflection->getName();

        // Built once per class: the reflection check and the name map. Doing this per CALL was
        // measured to eat more than half the win — the point of the fast path is that nothing is
        // rediscovered on the hot path. The closure itself is NOT cached: it casts through THIS
        // deserializer and validates with THIS instance's validator, and a per-class cache would
        // hand every later instance the first one's validator. Allocating a closure is not what
        // the measurement was about.
        if (!array_key_exists($className, self::$fastCastMetaCache)) {
            self::$fastCastMetaCache[$className] = self::resolveFastCastMeta($reflection, $classMeta);
        }
        $meta = self::$fastCastMetaCache[$className];
        if ($meta === null) {
            return null;
        }

        /** @var array<string, true> $emptyJsonObjectFields */
        $emptyJsonObjectFields = [];
        $cast = $this->buildFastCast(reflection: $reflection, byName: $meta['byName'], emptyJsonObjectFields: $emptyJsonObjectFields);

        // Anything but the body could supply a value: an uploaded file, or a router-verified
        // request attribute carrying one of the declared names. Both outrank or join the body in
        // `resolveRawRequestValue()`, and the generated method sees only the body.
        if ($request !== null) {
            if ($request->files->count() > 0) {
                return null;
            }
            foreach ($classMeta['params'] as $paramMeta) {
                if ($request->attributes->has($paramMeta['requestFieldName'])) {
                    return null;
                }
            }
        }

        // `hydrateFast()` is emitted only for eligible classes, so PHPStan cannot see it on the
        // `class-string<object>` this method holds; `buildFastCast()` has already proved the method
        // exists and is declared on this very class.
        /** @var callable(array<string, mixed>, Closure, array<int, string>): object $hydrator */
        $hydrator = [$className, 'hydrateFast'];

        return static function (array $errors) use ($hydrator, $bodyData, $cast, &$emptyJsonObjectFields): object {
            $dto = $hydrator($bodyData, $cast, $errors);
            DtoServiceSupport::rememberEmptyJsonObjects($dto, $emptyJsonObjectFields);

            return $dto;
        };
    }

    /**
     * The per-class half of the fast path: null when the class has no usable generated hydrator,
     * otherwise the parameter metadata keyed by the name the request uses. Nothing here depends on a
     * DtoDeserializer instance, which is what makes it safe to cache for the whole process.
     *
     * @param ReflectionClass<object> $reflection
     * @param array<string, mixed> $classMeta
     * @return array{byName: array<string, mixed>}|null
     */
    private static function resolveFastCastMeta(ReflectionClass $reflection, array $classMeta): ?array
    {
        // Declared ON this class, not inherited. A subclass inherits its parent's `hydrateFast()`,
        // whose `new self(...)` builds the PARENT — a silently wrong object type, caught by
        // `testCaseOnlySiblingOfAnInheritedPropertyGetsItsOwnAccessor`.
        if (
            !$reflection->hasMethod('hydrateFast')
            || $reflection->getMethod('hydrateFast')->getDeclaringClass()->getName() !== $reflection->getName()
        ) {
            return null;
        }

        $byName = [];
        foreach ($classMeta['params'] as $paramMeta) {
            $byName[$paramMeta['requestFieldName']] = $paramMeta;
        }

        return ['byName' => $byName];
    }

    /**
     * The casting closure for one class, bound to THIS deserializer.
     *
     * Everything the general loop decides about one value lives here: the cast, its failure wording,
     * and the OpenAPI field constraints. The generated method never inspects a value, so the two
     * routes have nothing to disagree about.
     *
     * @param ReflectionClass<object> $reflection
     * @param array<string, mixed> $byName
     * @param array<string, true> $emptyJsonObjectFields filled with the fields an empty JSON object went into as `[]`
     */
    private function buildFastCast(ReflectionClass $reflection, array $byName, array &$emptyJsonObjectFields): Closure
    {
        return function (string $name, mixed $value, array &$errors) use ($byName, $reflection, &$emptyJsonObjectFields): mixed {
            $paramMeta = $byName[$name];
            try {
                if (count($paramMeta['typeNames']) === 1 && self::isJsonScalarAlready($paramMeta['typeNames'][0], $value)) {
                    $cast = $value;
                } else {
                    $cast = count($paramMeta['typeNames']) === 1
                        ? $this->castValue(
                            value: $value,
                            paramName: $name,
                            typeName: $paramMeta['typeNames'][0],
                            allowsNull: $paramMeta['allowsNull'],
                            source: 'json',
                            arrayItemType: $paramMeta['arrayItemType'],
                            paramPath: $name,
                            schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                            temporalFormat: $paramMeta['temporalFormat'],
                            openApiFormat: $paramMeta['openApiFormat'],
                            allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                            alsoAllowsJsonList: $paramMeta['alsoAllowsJsonList'],
                            arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                            arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
                            nestedArrayItemType: $paramMeta['nestedArrayItemType'],
                            nestedArrayItemsNullable: $paramMeta['nestedArrayItemsNullable'],
                            constraints: is_array($paramMeta['fieldConstraints']) ? $paramMeta['fieldConstraints'] : [],
                        )
                        : $this->castUnionValue(
                            paramName: $name,
                            typeNames: $this->discriminatedUnionMember(typeNames: $paramMeta['typeNames'], value: $value, constraints: $paramMeta['fieldConstraints']),
                            rawValue: $value,
                            rawWasProvided: true,
                            exactlyOne: $this->unionMustMatchExactlyOne($paramMeta['fieldConstraints']),
                            unionConstraints: is_array($paramMeta['fieldConstraints']) ? $paramMeta['fieldConstraints'] : [],
                            rawSource: 'json',
                            arrayItemType: $paramMeta['arrayItemType'],
                            schemaAllowsNull: $paramMeta['schemaAllowsNull'],
                            dtoReflection: $reflection,
                            openApiFormat: $paramMeta['openApiFormat'],
                            allowsAssociativeArray: $paramMeta['allowsAssociativeArray'],
                            alsoAllowsJsonList: $paramMeta['alsoAllowsJsonList'],
                            arrayItemsNullable: $paramMeta['arrayItemsNullable'],
                            arrayItemTemporalFormat: $paramMeta['arrayItemTemporalFormat'],
                            nestedArrayItemType: $paramMeta['nestedArrayItemType'],
                            nestedArrayItemsNullable: $paramMeta['nestedArrayItemsNullable'],
                        );
                }
            } catch (RuntimeException $e) {
                foreach (explode("\n", $e->getMessage()) as $message) {
                    $errors[] = $message;
                }

                return null;
            }
            if ($this->isEmptyJsonObjectHeldAsArray(cast: $cast, raw: $value)) {
                $emptyJsonObjectFields[$paramMeta['name']] = true;
            }

            if (is_array($paramMeta['fieldConstraints'])) {
                foreach ($this->listsWhereObjectsBelong(value: $value, constraints: $paramMeta['fieldConstraints'], paramPath: $name) as $shapeError) {
                    $errors[] = $shapeError;
                }
            }

            if (is_array($paramMeta['fieldConstraints']) && $paramMeta['fieldConstraints'] !== []) {
                foreach (
                    $this->validateValue(
                        subject: sprintf('param "%s"', $name),
                        value: $this->asTheObjectItArrivedAs(cast: $cast, raw: $value),
                        constraints: $paramMeta['fieldConstraints'],
                    ) as $constraintError
                ) {
                    $errors[] = $constraintError;
                }
            }

            return $cast;
        };
    }

    /**
     * The validator's errors for one value, counting a validation the regex engine gave up in — see
     * {@see $engineGaveUpCount}. A validator other than this package's has no such flag to give, and
     * a list walk then simply goes on to the end, as it did before the stop existed.
     *
     * @param array<string, mixed> $constraints
     * @return array<string>
     */
    private function validateValue(string $subject, mixed $value, array $constraints): array
    {
        if (!$this->constraintValidator instanceof JsonShapeAwareValidatorInterface) {
            return $this->constraintValidator->validate($subject, $value, $constraints);
        }

        $validator = $this->constraintValidator;
        if ($this->jsonShapesKnown) {
            $validator = $this->jsonShapesValidator ??= $validator->readingEmptyArraysAsJsonArrays();
        }
        $verdict = $validator->validateReportingEngine(subject: $subject, value: $value, constraints: $constraints);
        if ($verdict['engineGaveUp']) {
            $this->engineGaveUpCount++;
        }

        return $verdict['errors'];
    }

    /**
     * Adds a line naming the likely cause when the request carried a body nobody could read.
     *
     * `getBodyData()` decodes `application/json` and nothing else; form and multipart values arrive
     * through their own sources. So a client that sends JSON with `text/plain` — or forgets the
     * header entirely, which is the common version — gets every field reported as missing, which
     * blames them for the wrong thing. The hint is only ever ADDED to an exception that was already
     * being thrown: a request that succeeds today still succeeds.
     *
     * @param array<string, mixed> $bodyData
     * @param array<string, mixed> $queryData
     * @param array<string, mixed> $formData
     */
    private function withUnreadableBodyHint(
        RuntimeException $exception,
        ?Request $request,
        array $bodyData,
        array $queryData,
        array $formData,
    ): RuntimeException {
        if (
            $request === null
            // Redundant with the `application/json` check below — `getBodyData()` fills this for no
            // other content type — and kept for the same reason: it states the condition the hint
            // is really about, which is "we read nothing".
            || $bodyData !== []
            || $queryData !== []
            || $formData !== []
            || $request->files->count() > 0
            || $request->getContent() === ''
        ) {
            return $exception;
        }

        $contentType = (string)$request->headers->get('Content-Type', '');
        if ($this->isJsonMediaType($contentType)) {
            return $exception;
        }

        return new RuntimeException(
            message: $exception->getMessage() . "\n" . sprintf(
                'The request body was not read: Content-Type is %s, and only application/json is '
                . 'decoded as a JSON body (form and multipart values are read from their own sources).',
                $contentType === '' ? 'absent' : sprintf('"%s"', $contentType),
            ),
            code: $exception->getCode(),
            previous: $exception,
        );
    }

    /**
     * Whether a body key is claimed by one of the object's `patternProperties`.
     *
     * Uses the validator's delimiter handling so an escaped delimiter means the same thing during
     * hydration and validation. An invalid pattern matches nothing instead of raising.
     *
     * The key is typed `string|int` because that is what a decoded body hands back: a JSON key of
     * digits (`{"0":…}`) becomes an int the moment PHP holds the array, whatever the PHPDoc says.
     *
     * @param array<int, string> $patterns the raw JSON Schema patterns, undelimited
     */
    private function bodyKeyMatchesPattern(string|int $key, array $patterns): bool
    {
        $subject = (string)$key;

        foreach ($patterns as $pattern) {
            $regex = DtoServiceSupport::delimitPattern($pattern);
            set_error_handler(static fn(): bool => true);
            try {
                $matched = preg_match($regex, $subject) === 1;
            } finally {
                restore_error_handler();
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schema-LEVEL keywords of a generated DTO, or `[]` when the class predates them.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, mixed>
     */
    private function resolveOpenApiObjectConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (array_key_exists($className, self::$objectConstraintsCache)) {
            return self::$objectConstraintsCache[$className];
        }

        $method = $this->resolveMetadataMethod(
            className: $className,
            candidateMethods: ['getObjectConstraints'],
        );
        if ($method === null) {
            return self::$objectConstraintsCache[$className] = [];
        }

        $constraints = call_user_func($method);

        return self::$objectConstraintsCache[$className] = is_array($constraints) ? $constraints : [];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function resolveOpenApiPropertyAliases(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (array_key_exists($className, self::$aliasesCache)) {
            return self::$aliasesCache[$className];
        }

        $method = $this->resolveMetadataMethod(className: $className, candidateMethods: ['getAliases']);
        if ($method === null) {
            return self::$aliasesCache[$className] = [];
        }

        $aliases = call_user_func($method);
        if (!is_array($aliases)) {
            return self::$aliasesCache[$className] = [];
        }

        $result = [];
        foreach ($aliases as $propertyName => $openApiName) {
            if (!is_string($propertyName) || !is_string($openApiName)) {
                continue;
            }
            $result[$propertyName] = $openApiName;
        }

        return self::$aliasesCache[$className] = $result;
    }

    /**
     * @param array<string, mixed>|null $constraints
     */
    private function allowsAssociativeArray(?array $constraints): bool
    {
        if (!is_array($constraints)) {
            return false;
        }

        $type = $constraints['type'] ?? null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
            return true;
        }

        // A property-level `anyOf`/`oneOf` with an object branch — `[map, null]`, `[{type: object},
        // string]` — is declared `array` too, and a body decoded here holds that object as a
        // `stdClass`: with only `type` read, every JSON object was refused as "expects array, got
        // object" while the other four modes took it.
        foreach ($this->unionBranchTypes($constraints) as $branchTypes) {
            if (in_array('object', $branchTypes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The types each `anyOf`/`oneOf` branch names, an empty list for a branch that names none; a
     * `type: null` branch is left out, it admits neither container.
     *
     * A branch that is a union itself — `oneOf: [{anyOf: [integer, object]}]` — stands for ITS branches,
     * and an untyped branch takes the type of its first typed `allOf` member: read one level deep, the
     * object member was never seen and a JSON object was refused as "expects array, got object". A
     * branch naming a generated object class is `class`, which admits no PHP array.
     *
     * @param array<string, mixed> $constraints
     * @return list<list<string>>
     */
    private function unionBranchTypes(array $constraints, int $depth = 0): array
    {
        $branchTypes = [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $constraints[$keyword] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $types = $this->declaredBranchTypes($branch);
                if ($types === [] && $depth < self::MAX_UNION_BRANCH_DEPTH) {
                    /** @var array<string, mixed> $branch */
                    $nested = $this->unionBranchTypes($branch, $depth + 1);
                    if ($nested !== []) {
                        $branchTypes = [...$branchTypes, ...$nested];

                        continue;
                    }
                }
                if ($types === ['null']) {
                    continue;
                }
                $branchTypes[] = $types;
            }
        }

        return $branchTypes;
    }

    /**
     * The types a schema names itself, through its `allOf` members or as the generated class it is.
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function declaredBranchTypes(array $schema, int $depth = 0): array
    {
        $type = $schema['type'] ?? null;
        $types = is_string($type) ? [$type] : (is_array($type) ? array_values(array_filter($type, is_string(...))) : []);
        if ($types !== []) {
            return $types;
        }
        // A generated class is its own branch, not an `object` one: the class takes a JSON object
        // itself, and counted as an object it let an `array` member beside it read that object as a
        // map, so the class's rules were never checked.
        $className = $schema['x-php-instanceof'] ?? null;
        if (is_string($className) && class_exists($className) && !enum_exists($className)) {
            return ['class'];
        }
        if ($depth < self::MAX_UNION_BRANCH_DEPTH) {
            foreach (is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $member) {
                $memberTypes = is_array($member) ? $this->declaredBranchTypes($member, $depth + 1) : [];
                if ($memberTypes !== []) {
                    return $memberTypes;
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, string> $candidateMethods
     */
    private function resolveMetadataMethod(string $className, array $candidateMethods): ?callable
    {
        foreach ($candidateMethods as $methodName) {
            $callable = [$className, $methodName];
            if (is_callable($callable)) {
                return $callable;
            }
        }

        return null;
    }

    /**
     * A JSON object cast to a PHP list — `{}`, or `{"0": 1}` — is handed to the validator as the
     * object it was. Where `type` pins `object` the validator reads a list as one itself; a union
     * with an object branch pins nothing, so `{}` became `[]` there and matched no branch.
     */
    private function asTheObjectItArrivedAs(mixed $cast, mixed $raw): mixed
    {
        return $raw instanceof stdClass && is_array($cast) && array_is_list($cast) ? (object)$cast : $cast;
    }

    /**
     * Whether `{}` went into a field as the empty PHP array, which reads as `[]` from then on — noted
     * in {@see DtoServiceSupport::rememberEmptyJsonObjects()} so that the write-back says `{}` again.
     */
    private function isEmptyJsonObjectHeldAsArray(mixed $cast, mixed $raw): bool
    {
        return $cast === [] && $raw instanceof stdClass && get_object_vars($raw) === [];
    }

    /**
     * A JSON array found where the schema allows only an object, at any depth of a value the request
     * decoded itself.
     *
     * The validator cannot answer this: a typed map is held as a PHP array, and one whose keys are
     * `0, 1, …` is a list, so it reads any PHP array as a possible object. The cast refuses a JSON
     * array for the levels it types — a property, the items of a typed list — and nothing looked
     * deeper: an undeclared key under `additionalProperties: {type: object}`, or the items of a list of
     * lists, took `[1, 2]` for an object and wrote it back as `{"0":1,"1":2}`. The RAW value still
     * knows, because a body decoded here keeps every JSON object a `stdClass` (see
     * `$jsonShapesKnown`); a value handed over already decoded does not, and is left alone. Only
     * `type` is read, walking `items`, `prefixItems`, `properties` and `additionalProperties`.
     *
     * @param array<array-key, mixed> $constraints
     * @return list<string>
     */
    private function listsWhereObjectsBelong(mixed $value, array $constraints, string $paramPath, int $depth = 0): array
    {
        if (!$this->jsonShapesKnown || $depth > 64) {
            return [];
        }

        if (is_array($value)) {
            if (DtoServiceSupport::admitsObjectContainerOnly($constraints)) {
                return [$this->expectsTypeMessage(paramPath: $paramPath, expectedType: 'object', value: $value)];
            }

            $items = $constraints['items'] ?? null;
            $prefixItems = $constraints['prefixItems'] ?? null;
            if (!is_array($items) && !is_array($prefixItems)) {
                return [];
            }

            $errors = [];
            foreach ($value as $index => $item) {
                $itemSchema = is_array($prefixItems) && is_array($prefixItems[$index] ?? null) ? $prefixItems[$index] : $items;
                if (is_array($itemSchema) && (is_array($item) || $item instanceof stdClass)) {
                    $errors = [
                        ...$errors,
                        ...$this->listsWhereObjectsBelong(value: $item, constraints: $itemSchema, paramPath: "{$paramPath}.{$index}", depth: $depth + 1),
                    ];
                }
            }

            return $errors;
        }

        if (!$value instanceof stdClass) {
            return [];
        }

        $properties = $constraints['properties'] ?? null;
        $additional = $constraints['additionalProperties'] ?? null;
        if (!is_array($properties) && !is_array($additional)) {
            return [];
        }

        $errors = [];
        foreach (get_object_vars($value) as $key => $member) {
            if (!is_array($member) && !$member instanceof stdClass) {
                continue;
            }
            $memberSchema = is_array($properties) && is_array($properties[$key] ?? null) ? $properties[$key] : $additional;
            if (is_array($memberSchema)) {
                $errors = [
                    ...$errors,
                    ...$this->listsWhereObjectsBelong(value: $member, constraints: $memberSchema, paramPath: "{$paramPath}.{$key}", depth: $depth + 1),
                ];
            }
        }

        return $errors;
    }

    /**
     * An extra value whose schema is an object, as an object: a PHP array payload spells `{}` as `[]`,
     * and kept as it came, the value was written back as a JSON list.
     *
     * @param array<array-key, mixed> $schema
     */
    private function keptExtraValue(mixed $value, array $schema): mixed
    {
        $isObject = array_key_exists('x-php-dto', $schema) || ($schema['type'] ?? null) === 'object';

        return $isObject && is_array($value) && array_is_list($value) ? (object)$value : $value;
    }

    /**
     * The class the discriminator of `$baseClass` maps the value to.
     *
     * A list item may be a member that does not extend the base: one that two discriminated unions
     * share extends neither, since a class has one parent, and nothing in PHP types a list item. The
     * mapping names it as a member of the union, so it is the class to build (`$mustExtendBase:
     * false`); a property typed with the base can hold only a subclass, and keeps the requirement.
     *
     * @param array<array-key, mixed> $value
     */
    private function resolveDiscriminatorTargetClass(string $baseClass, array $value, string $paramPath, bool $mustExtendBase = true): ?string
    {
        if (!class_exists($baseClass)) {
            return null;
        }

        /** @var class-string $baseClass */
        if (
            !method_exists($baseClass, 'getDiscriminatorPropertyName') || !method_exists(
                object_or_class: $baseClass,
                method: 'getDiscriminatorMapping',
            )
        ) {
            return null;
        }

        // A class that inherits the discriminator — a union MEMBER, an `allOf` child — only narrows to
        // its OWN subclasses. A property typed with the member, `cat: $ref Cat`, refused every `kind`
        // but the mapped ones, and a mapped `dog` with "Dog must extend or implement Cat"; yet a mapped
        // `lion` below Cat must still become a Lion, or its own fields are dropped.
        $inherited = (new ReflectionMethod($baseClass, 'getDiscriminatorMapping'))->getDeclaringClass()->getName() !== ltrim($baseClass, '\\');

        $discriminatorProperty = $baseClass::getDiscriminatorPropertyName();
        $mapping = $baseClass::getDiscriminatorMapping();
        if ($inherited) {
            $inheritedKey = is_string($discriminatorProperty) ? $value[$discriminatorProperty] ?? null : null;
            $inheritedTarget = is_array($mapping) && (is_string($inheritedKey) || is_int($inheritedKey))
                ? $mapping[(string)$inheritedKey] ?? null
                : null;

            return is_string($inheritedTarget) && class_exists($inheritedTarget) && is_a($inheritedTarget, $baseClass, true)
                ? $inheritedTarget
                : null;
        }

        if (
            !is_string($discriminatorProperty) || $discriminatorProperty === '' || !is_array(
                value: $mapping,
            ) || $mapping === []
        ) {
            throw new RuntimeException(
                message: "DTO {$baseClass} has invalid discriminator metadata.",
            );
        }

        $fullDiscriminatorPath = $paramPath . '.' . $discriminatorProperty;

        if (!array_key_exists($discriminatorProperty, $value)) {
            throw new RuntimeException(
                message: $this->finalizeParamMessage("param \"{$fullDiscriminatorPath}\" wasn't provided"),
            );
        }

        $discriminatorValue = $value[$discriminatorProperty];
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            throw new RuntimeException(
                message: $this->finalizeParamMessage(
                    message: "param \"{$fullDiscriminatorPath}\" expects a string or int discriminator value, "
                    . "got {$this->getTypeString($discriminatorValue)}",
                ),
            );
        }

        $discriminatorKey = (string)$discriminatorValue;
        if (!array_key_exists($discriminatorKey, $mapping)) {
            $allowedKeys = implode(', ', array_keys($mapping));
            throw new RuntimeException(
                message: DtoServiceSupport::finalizeMessage(
                    message: "param \"{$fullDiscriminatorPath}\" has invalid discriminator value \"{$discriminatorKey}\". Allowed: {$allowedKeys}",
                    subject: 'param "',
                ),
            );
        }

        $targetClass = $mapping[$discriminatorKey];
        if (!is_string($targetClass) || !class_exists($targetClass)) {
            throw new RuntimeException(
                message: "Discriminator mapping for \"{$fullDiscriminatorPath}\" points to unknown class \"{$targetClass}\".",
            );
        }

        if ($mustExtendBase && !is_a($targetClass, $baseClass, true)) {
            throw new RuntimeException(
                message: "Discriminator mapping class {$targetClass} must extend or implement {$baseClass}.",
            );
        }

        return $targetClass;
    }

    private function expectsTypeMessage(string $paramPath, string $expectedType, mixed $value): string
    {
        $actualType = is_string($value) && in_array(
            needle: $value,
            haystack: ['int', 'float', 'string', 'bool', 'array', 'object', 'null'],
            strict: true,
        )
            ? $value
            : $this->getTypeString($value);

        return DtoServiceSupport::finalizeMessage(
            message: "param \"{$paramPath}\" expects {$expectedType}, got {$actualType}",
            subject: 'param "',
        );
    }

    /**
     * The single exit for a message this class writes about a FIELD.
     *
     * `expectsTypeMessage()` above finalises the message it builds, so its `param "x" expects int,
     * got string.` has always carried the full stop the convention asks for. The messages built at
     * the other places did not, and they reach the client in the same list: `hydrateFast()` collects
     * whatever the cast closure threw and rethrows it joined by newlines, without a finalising step
     * of its own — deliberately, since a generated DTO owes nothing to the validator. So the full
     * stop is applied where the sentence is WRITTEN, and this is the one place that says how.
     *
     * The subject is passed so the leading `param "…"` keeps the spelling the document chose:
     * `finalizeMessage()` capitalises only a sentence that opens with an English word.
     */
    private function finalizeParamMessage(string $message): string
    {
        return DtoServiceSupport::finalizeMessage($message, 'param "');
    }

    private function isStrictIntValue(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        // JSON Schema 2020-12 §6.1.1: `integer` matches any NUMBER with a zero fractional part, so a
        // payload of `42.0` is an integer — PHP just decodes it to a float. The magnitude still has to
        // fit an int, or the cast would saturate silently.
        if (is_float($value)) {
            // The comparisons are STRICT, and that is the whole boundary. `(float)PHP_INT_MAX` is not
            // PHP_INT_MAX: 2^63-1 has no double, so the cast rounds UP to 2^63 — and `<=` therefore
            // admitted exactly the first ILLEGAL value, which `(int)` then wrapped to PHP_INT_MIN. A
            // client sent 9223372036854775808 and the DTO held -9223372036854775808, with `validate()`
            // approving it, because the range check downstream only ever saw the wrapped int.
            //
            // Refusing the boundary costs nothing, and that is measured rather than assumed: both
            // legal extremes FIT an int, so `json_decode()` hands them over as `integer` and never
            // reaches this branch —
            //     json_decode('9223372036854775807')  -> integer
            //     json_decode('-9223372036854775808') -> integer
            // A float equal to ±2^63 can only have come from a number outside the range. (This is
            // where `uint64` differs and stays unfixable: its legal maximum does NOT fit, so it
            // arrives as the same double as the first illegal value. See `DtoValidator`.)
            return is_finite($value)
                && floor($value) === $value
                && $value > (float)PHP_INT_MIN
                && $value < (float)PHP_INT_MAX;
        }

        if (!is_string($value)) {
            return false;
        }

        if (preg_match('/^[+-]?\d+$/D', $value) !== 1) {
            return false;
        }

        // Reject magnitudes outside PHP's int range: (int) cast would silently saturate to
        // PHP_INT_MAX/MIN, corrupting the value (and then passing int64 range validation).
        return $this->intStringInRange($value);
    }

    private function intStringInRange(string $value): bool
    {
        $negative = $value[0] === '-';
        $digits = ltrim(ltrim($value, '+-'), '0');
        if ($digits === '') {
            return true; // value is zero (any number of leading zeros)
        }

        // |PHP_INT_MIN| = 9223372036854775808, |PHP_INT_MAX| = 9223372036854775807.
        $bound = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) !== strlen($bound)) {
            return strlen($digits) < strlen($bound);
        }

        return strcmp($digits, $bound) <= 0;
    }

    private function isStrictFloatValue(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        // `1e400` overflows to INF: accepted, it came back out of toJson() as "Inf and NaN cannot be
        // JSON encoded" — a 500 for what is a bad request.
        if (is_float($value)) {
            return is_finite($value);
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[+-]?(?:\d+\.\d+|\d+|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) === 1
            && is_finite((float)$value);
    }

    /**
     * Parses file-level `use` imports so short class names in docblocks can be resolved
     * without relying on the class namespace alone.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, string> map of short name → FQCN
     */
    private function resolveFileImports(ReflectionClass $reflection): array
    {
        $filename = $reflection->getFileName();
        if ($filename === false) {
            return [];
        }

        // Cache parsed imports per file (like reflection/meta caches) — the DTO source never
        // changes within a process, so the file_get_contents + preg_match_all runs once.
        if (array_key_exists($filename, self::$fileImportsCache)) {
            return self::$fileImportsCache[$filename];
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return self::$fileImportsCache[$filename] = [];
        }

        // Read as PHP tokens, not as lines: a string literal of the DTO holds document text — a
        // default, a pattern — and a line of it reading `use Other as Gear;` was taken for an
        // import, so the request's items were constructed as `Other`. Only the class imports before
        // the class itself count; `use function` / `use const` name no class.
        $imports = [];
        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                break;
            }
            if (!$token->is(T_USE)) {
                continue;
            }
            $statement = [];
            for ($index++; $index < $count && $tokens[$index]->text !== ';'; $index++) {
                if (!$tokens[$index]->isIgnorable()) {
                    $statement[] = $tokens[$index];
                }
            }
            if ($statement === [] || $statement[0]->is([T_FUNCTION, T_CONST])) {
                continue;
            }
            foreach ($this->importClauses($statement) as $alias => $fqcn) {
                $imports[$alias] = $fqcn;
            }
        }

        return self::$fileImportsCache[$filename] = $imports;
    }

    /**
     * The clauses of one `use` statement — `A\B`, `A\B as C`, several separated by commas — as
     * alias → FQCN.
     *
     * @param list<PhpToken> $statement
     * @return array<string, string>
     */
    private function importClauses(array $statement): array
    {
        $imports = [];
        $name = null;
        $alias = null;
        $expectAlias = false;
        foreach ([...$statement, new PhpToken(ord(','), ',')] as $token) {
            if ($token->text === ',') {
                if ($name !== null) {
                    $fqcn = ltrim($name, '\\');
                    $backslashPos = strrpos($fqcn, '\\');
                    $imports[$alias ?? ($backslashPos === false ? $fqcn : substr($fqcn, $backslashPos + 1))] = $fqcn;
                }
                $name = null;
                $alias = null;
                $expectAlias = false;
            } elseif ($token->is(T_AS)) {
                $expectAlias = true;
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if ($expectAlias) {
                    $alias = $token->text;
                } else {
                    $name = $token->text;
                }
            }
        }

        return $imports;
    }
}
