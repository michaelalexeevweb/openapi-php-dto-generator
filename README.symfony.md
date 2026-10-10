# Symfony mode

[← back to the main README](README.md) · other modes: [runtime](README.runtime.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md) · [yii3](README.yii3.md) · [support matrix](README.support-matrix.md) · [performance](README.performance.md)

Generated with `--attributes=symfony`. DTOs are plain data classes decorated with **Symfony
Validator / Serializer attributes**. There is no library runtime: `symfony/validator` validates them
and `symfony/serializer` (de)serializes them — or a controller maps them automatically with
`#[MapRequestPayload]` / `#[MapQueryString]`.

**Symfony 7.4.6 or newer, or 8.x** for `symfony/serializer` and `symfony/property-info`. On 7.4.0–7.4.5
a `DateTimeImmutable` constructor argument is handed the raw string and `#[MapRequestPayload]` answers
with other status codes — measured by installing the lowest versions; CI keeps that floor installed.

Required properties are constructor arguments and stay `readonly`. Optional ones are set by the
serializer through a setter, and that setter records that the payload carried the key — which is
what makes PATCH semantics work (see [below](#presence-tracking-patch--partial-updates)). Every
property is declared in the class body in schema order, which is the order the serializer writes the
keys in.

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test \
  --attributes=symfony
```

```php
// generated in symfony mode
final class User
{
    private readonly int $id;

    /**
     * @var ?string The display name Example: John
     */
    #[Assert\Length(min: 2, max: 50)]
    #[Context(normalizationContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
    private ?string $name = null;

    #[Ignore]
    private bool $nameProvided = false;

    public function __construct(
        int $id,
    ) {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
        $this->nameProvided = true;
    }

    #[Ignore]
    public function isNameProvided(): bool
    {
        return $this->nameProvided;
    }
}
```

Building one by hand: `create()` takes every field in one expression — required ones as in the
constructor, optional ones by name. An optional argument left out or passed as `null` is not set;
to send an explicit `null`, call the setter.

A required property with a schema `default` may be left out of `create()`, which fills the default.
The constructor still demands it: that is what the serializer calls, so a request that omits the
field is refused as before.

```php
$receipt = Receipt::create(id: 7);   // status: 'done' from the schema
```

```php
$user = User::create(id: 1, name: 'John');

$user = new User(id: 1);       // the same, step by step
$user->setName('John');
```

In a Symfony controller the DTO is validated and populated automatically:

```php
public function create(#[MapRequestPayload] User $user): Response { /* ... */ }
```

For required nullable fields (including `type: null`), enable `require_all_properties` in the
serializer context. Symfony otherwise supplies `null` for an omitted nullable constructor argument,
so validation cannot distinguish a missing key from an explicit null. Optional fields remain optional:
they are populated through setters.

```php
public function create(
    #[MapRequestPayload(serializationContext: ['require_all_properties' => true])] User $user,
): Response { /* ... */ }
```

For direct Serializer calls, pass `['require_all_properties' => true]` as the fourth argument to
`deserialize()`, or configure it in the normalizer's default context.

## OpenAPI → Symfony attribute mapping

| OpenAPI | Symfony attribute |
|---|---|
| `required` (non-nullable) | a non-nullable PHP type; `#[Assert\NotNull]` only where the type admits null (an untyped property) |
| `minLength` / `maxLength` | `#[Assert\Length(min:, max:)]` |
| `minimum` / `maximum` | `#[Assert\Range(min:, max:)]` |
| `exclusiveMinimum` / `exclusiveMaximum` | `#[Assert\GreaterThan]` / `#[Assert\LessThan]` |
| `multipleOf` | `#[Assert\DivisibleBy]` |
| `pattern` | `#[Assert\Regex]` |
| `minItems` / `maxItems`, `minProperties` / `maxProperties` | `#[Assert\Count]` |
| `uniqueItems` | callback with JSON equality |
| `const` | callback with JSON equality |
| `enum` | generated PHP backed enum when representable; inline Choice for non-nullable string/bool members; callback for numeric, structural and nullable enums |
| `format: email` / `uuid` / `url` / `ipv4`,`ipv6` / `hostname` | `#[Assert\Email]` / `Uuid` / `Url` / `Ip` / `Hostname` |
| `format: int32` / `uint32` | `#[Assert\Range]` (bounds) |
| `format: date` / `date-time` | `DateTimeImmutable` property and getter; written as the schema says through a `#[Context]` callback (see [dates](#dates-are-formatted-by-the-dto-not-the-normalizer)) |
| `items` / `additionalProperties` with `format: date` / `date-time` | `array<DateTimeImmutable>`; written per item the same way (see [dates](#dates-are-formatted-by-the-dto-not-the-normalizer)) |
| `format: binary` | `UploadedFile` type |
| `items` (scalar) / `additionalProperties` | `#[Assert\All([...])]` |
| `anyOf` | `#[Assert\AtLeastOneOf([...])]` |
| nested DTO / array of DTOs | `#[Assert\Valid]` (cascade) |
| property name ≠ OpenAPI name | `#[SerializedName('…')]` |
| `readOnly` / `writeOnly` | `#[Groups(['read'])]` / `#[Groups(['write'])]` (see [Serialization groups](#serialization-groups-readonly--writeonly)) |

**Keywords without a Symfony attribute equivalent** are compiled into a self-contained
`#[Assert\Callback]` method on the DTO — `validateOpenApiConstraints()`, backed by the
`OPENAPI_VALIDATION_CONSTRAINTS` const. It runs as part of the ordinary
`$validator->validate($dto)` pass (so also under `#[MapRequestPayload]`), needs no library runtime,
and reports through `$context->buildViolation()` like any other constraint. Nested DTOs carry their
own callback and are reached via the `#[Assert\Valid]` cascade.

| OpenAPI | Callback check |
|---|---|
| `required` (inside a subschema) | property presence |
| `const` | callback with JSON equality |
| `properties`, `patternProperties`, `propertyNames` | recursion into matching properties / key names |
| `additionalProperties: false` / schema | extra keys rejected / validated |
| `unevaluatedProperties: false`, `unevaluatedItems: false` | keys / indices not covered above rejected |
| `dependentRequired`, `dependentSchemas` | conditional presence / conditional subschema |
| `not`, `if` / `then` / `else` | negation, conditional branch |
| `prefixItems` (tuples), `items` | positional then rest items |
| `contains`, `minContains`, `maxContains` | match count bounds |
| `oneOf` (+ discriminator mapping), callback-only formats (`uri`,`iri`,`uri-reference`,`iri-reference`,`uri-template`,`duration`,`time`,`regex`,`json-pointer`,`relative-json-pointer`,`byte`,`idn-hostname`), `contentEncoding` / `contentMediaType` / `contentSchema`, `format: int64` / `uint64` | self-contained callback checks (branch XOR / discriminator-class agreement, format/content assertions, int64/uint64 bounds) |
| scalar keywords inside callback subschemas (`type`, `enum`, `minLength`/`maxLength`, `pattern`, `minimum`/`maximum`, `exclusiveMinimum`/`exclusiveMaximum`, `multipleOf`, `minItems`/`maxItems`, `uniqueItems`, `minProperties`/`maxProperties`, `format`) | enforced recursively in `not`/`if`/`then`/`else`/`contains`/`items`/`propertyNames`/`patternProperties`/`dependentSchemas` |

Callback code is emitted only for keywords that actually occur in the schema, and recursion is capped
at `OPENAPI_MAX_VALIDATION_DEPTH = 256`. A class whose schema the PHP types already describe
completely — what is left is only `nullable` or an untyped `items` — gets no callback at all.

## Serialization groups (`readOnly` / `writeOnly`)

Runtime mode enforces these keywords itself. Symfony mode has no runtime of its own, so the only
mechanism is serialization groups — **and they only apply when you pass them**:

```php
// response: writeOnly fields (a password, say) are left out
$payload = $serializer->normalize($dto, null, ['groups' => 'read']);

// request: readOnly fields sent by the client are ignored
$dto = $serializer->denormalize($json, OrderRequest::class, null, ['groups' => 'write']);
```

Without a group in the context Symfony filters nothing and a `writeOnly` field **is** serialized
into the response. The generated classes say so in their docblock.

Groups are all-or-nothing per class: as soon as one property of a class carries a group, every
property without one is dropped from the output. That is why a document using either keyword gets
`#[Groups(['read', 'write'])]` on all its ordinary properties, in every generated class — including
nested DTOs that use neither keyword, which would otherwise normalize to `[]` under a filter. A
document that uses neither keyword gets no group attributes at all.

A property declared both `readOnly` and `writeOnly` is a contradiction; it becomes `#[Ignore]`,
which is how runtime mode resolves it too (out of both directions).

A `readOnly` property listed under `required` is **not** a constructor argument. OpenAPI puts that
requirement on the response alone, so a request may omit it — and emitted as required it made the
class undeserializable outright, `MissingConstructorArgumentsException` under every combination of
groups. It is an ordinary optional property instead, filled through its setter, and `isXProvided()`
answers whether the payload carried it (since 2.15.36).

## Serializer wiring the generated DTOs rely on

In Symfony mode the DTO is inert data — the serializer does the work. Two of the pieces below fail
loudly when missing, two fail **silently**, so this is not a "nice to have" list:

| Missing piece | What actually happens |
|---|---|
| `PhpDocExtractor` in the `PropertyInfoExtractor` | **silent.** `array<Line>` items stay plain arrays instead of becoming DTOs, so `#[Assert\Valid]` has nothing to cascade into and nested violations disappear — a payload with 1 nested error validates with 0 violations |
| `DateTimeNormalizer` | **silent.** `"2026-03-10T12:00:00+00:00"` denormalizes to *now* |
| `ClassMetadataFactory` + `MetadataAwareNameConverter` | `#[SerializedName]` is ignored, so any aliased property (`first_name`) throws `MissingConstructorArgumentsException`; `#[Ignore]` is ignored too, so the presence flags leak into the response as `nameProvided` |
| `BackedEnumNormalizer` | an enum property throws `NotNormalizableValueException` ("class … is not instantiable") |

The full framework config (`framework.serializer`) already wires all of this. Standalone:

```php
$classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
$serializer = new Serializer(
    [
        new BackedEnumNormalizer(),
        new DateTimeNormalizer(),
        new ObjectNormalizer(
            $classMetadataFactory,
            new MetadataAwareNameConverter($classMetadataFactory),
            null,
            new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]),
        ),
        new ArrayDenormalizer(),
    ],
    [new JsonEncoder()],
);
```

## What the serializer decides, not us

`#[MapRequestPayload]` denormalizes before validation, so some failures never reach a constraint.
Measured against `RequestPayloadValueResolver` with a validator attached:

| Payload problem | Result |
|---|---|
| wrong scalar type, unknown enum member, missing required field | **422**, as a violation: `This value should be of type int.` — the generated constraints never run for that field. A missing field of an enum or object type is named by its class (`… of type App\Dto\WidgetKind.`), a missing required nullable one reads `of type unknown` — the text is Symfony's; keep it out of a public response if the class names matter |
| `null` for an optional property the schema does not let be null, `?limit=` in the query | **422** / 404 at the field — the setter takes null only where the schema allows it |
| a discriminator value outside the mapping | **500** — `NotNormalizableValueException` is thrown before the resolver collects anything. Map it to 400 yourself |
| `format: date` in another spelling (`tomorrow`, `01/02/2025`, a full timestamp) | **accepted** — DateTimeNormalizer parses anything `DateTime` does. Pinning the format (`DateTimeNormalizer::FORMAT_KEY`) would refuse those but let an impossible date (`2025-13-45`) roll over into another one, which is worse; it is left to the default |
| unparsable body | **400** `Request payload contains invalid "json" data.` |
| unknown JSON key | **accepted** — the key is dropped before validation, which is why `additionalProperties: false` / `unevaluatedProperties: false` are near no-ops here; they still fire on a hand-built payload array |
| unknown JSON key with `ALLOW_EXTRA_ATTRIBUTES => false` | **500** — `ExtraAttributesException` is neither of the two exception types the resolver catches. Map it yourself if you want strict rejection |
| a JSON array for a `type: object` property | **accepted**, read as a map keyed `0..n-1`. The denormalizer turns both a JSON object and a JSON array into a PHP array before any constraint runs, and a map with keys `0..n-1` is a legitimate payload — so the two cannot be told apart here. [runtime](README.runtime.md) and [laravel](README.laravel.md) mode refuse it: both still hold the raw body |
| `42.0` for a `type: integer` property | **422** — the spec counts it as an integer (JSON Schema 2020-12 §6.1.1) and [runtime](README.runtime.md) / [laravel](README.laravel.md) mode accept it, but the serializer refuses the float before any constraint runs. The one conformance gap this mode cannot close from generated code |

The 422 messages for those first three come from Symfony, not from the schema, so they are generic
(an unknown enum member reads `This value should be of type int|string.`). Anything the
denormalizer accepts is then checked by the generated constraints, whose messages do name the
OpenAPI rule.

To apply the `write` group described above in a controller:

```php
#[MapRequestPayload(serializationContext: ['groups' => 'write'])]
```

## Presence tracking (PATCH / partial updates)

An optional property is `?T = null`, so its value alone cannot tell "the client omitted this key"
from "the client sent `null`" — the distinction a PATCH endpoint lives on. The generated setter
records it:

```php
$dto = $serializer->deserialize('{"id":1,"name":null}', User::class, 'json');

$dto->getName();            // null
$dto->isNameProvided();     // true  — sent, explicitly as null
$dto->isEmailProvided();    // false — never sent
```

Nothing to register: the flag is part of the DTO, so it works through `#[MapRequestPayload]`, on
nested DTOs and on arrays of DTOs alike. `#[Ignore]` keeps the flags out of the serialized output.

A DTO you build yourself behaves the same way — a field counts as provided once its setter has been
called.

### Why not the approaches Symfony suggests

Symfony has no notion of "which keys were in the payload" for objects: `#[Assert\Optional]` /
`#[Assert\Required]` only exist inside the `Collection` constraint, i.e. for raw arrays. Two other
routes were measured and rejected:

- **`OBJECT_TO_POPULATE`** (deserialize into the existing object) is Symfony's documented answer for
  PATCH. It cannot work on a `readonly` class — properties may not be written a second time — and it
  fails **silently**: the same instance comes back with its old values and no exception. This is why
  the optional half of these DTOs is not `readonly`;
- **a sentinel default** (`?T = UNSET`) keeps everything `readonly`, but then every `#[Assert\*]`
  meets an enum case instead of `null`: an absent field reports a bogus `This value should be of
  type string.` and normalizes to a leaked enum object.

## Absent optional fields in the response

Each property tells the stock serializer what to do with its own null, through Symfony's
`#[Context]` attribute — nothing to register:

- an optional property the schema does **not** let be null can only hold `null` when it was never
  set, so it carries `SKIP_NULL_VALUES => true` and is left out of the output, as in runtime mode;
- a property the schema **does** let be null carries `SKIP_NULL_VALUES => false`, so its null is
  written even under a parent property that skips nulls, or a caller that passes
  `SKIP_NULL_VALUES => true` for the whole call;
- an optional property with a `default` is never null unless the schema allows it, and is written
  with its default.

```json
{"id": 1}
```

One case stays apart from runtime mode: an optional **and** nullable property that was never set is
written as the `null` the schema allows (`{"id": 1, "note": null}`). The DTO knows the difference
(`isNoteProvided()`), but normalization goes through the getter, which answers `null` either way;
telling the two apart on the wire would need a normalizer of the application's own.

An object with nothing to write leaves as `[]`, not `{}`, unless the call passes
`AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true` — the serializer has no notion of the
schema's shape there.

## File uploads (`multipart/form-data`)

A `format: binary` property is an `UploadedFile`, but `#[MapRequestPayload]` reads the form fields
only (`$request->request`), never `$request->files` — a DTO with a file in it cannot be bound that
way. Bind the file with Symfony's own `#[MapUploadedFile]` and build the DTO from it:

```php
public function upload(
    #[MapUploadedFile(constraints: new Assert\File(mimeTypes: ['text/csv']))] UploadedFile $sheet,
): Response {
    $request = WidgetImportRequest::create(sheet: $sheet);
    // ...
}
```

The multipart `encoding` of the document (`contentType: text/csv`) is not applied; the
`Assert\File` constraint on the attribute is where it belongs.

## Query, path, header and cookie parameters

`#[MapQueryString]` binds a DTO from the query string alone, so the generated `…QueryParams` class
holds the `in: query` parameters only. A path parameter reaches the controller as an argument of its
own (with the route's `requirements` for its pattern), and so do headers and cookies:

```php
#[Route('/widgets/{widgetId}', requirements: ['widgetId' => '\d+'])]
public function show(int $widgetId, #[MapQueryString] ?WidgetsGetQueryParams $query = null): Response
```

An operation with no query parameters gets no class at all. `--dto-generator-directory` is ignored
in this mode: it copies the runtime mode's services, which these DTOs never use.

A list of numbers or booleans in the query (`?ids[]=1&ids[]=22`) arrives from Symfony as strings:
`#[MapQueryString]` casts a single value to the declared type, not the items of a list. The DTO reads
such a list itself, in its constructor and setter, with `filter_var` — as `#[MapQueryParameter]`
does — so `getIds()` returns `[1, 22]`. An item that does not read as the type (`?ids[]=x`) is kept
as the string and refused by validation at its index, `ids[1]`, never turned into a silent `0`.

### Dates are formatted by the DTO, not the normalizer

Symfony's `DateTimeNormalizer` has one fixed pattern per context, which cannot express what OpenAPI
asks for: `format: date` must stay a date, and a `date-time` must keep the sub-second precision the
payload carried. So a date property hands the serializer a formatter of the DTO's own, through
Symfony's `#[Context]` callback — the same rule runtime mode uses — and nothing has to be registered:

```php
#[Context(normalizationContext: [
    AbstractObjectNormalizer::CALLBACKS => ['at' => [self::class, 'formatOpenApiDateTime']],
])]
private readonly DateTimeImmutable $at;
```

```php
$dto->getAt();                       // DateTimeImmutable
$serializer->serialize($dto, 'json'); // {"at":"2026-03-10T12:00:00.123456+03:00","on":"2026-03-10"}
```

A CONTAINER of dates is written item by item, and a map keeps its keys:

```php
/** @return array<string, DateTimeImmutable> */ $dto->getDatesByDay();  // ["mon" => DateTimeImmutable]
// on the wire: {"datesByDay":{"mon":"2026-03-09"}}
```

Where the items are really dates and where they are strings differs by mode — see
[Temporal container items](README.support-matrix.md#temporal-container-items) in the support matrix.

### An empty map serializes as `[]`

A property declared `type: object` with `additionalProperties` becomes a PHP `array`, and PHP cannot
tell an empty map from an empty list — so an empty one encodes as `[]` where the schema says an
object:

```json
{"tags": {"a": 1}}   // identical in runtime and symfony mode
{"tags": {}}         // symfony mode: {"tags": []}   runtime mode: {"tags": {}}
```

Runtime mode casts maps to `stdClass` and emits `{}`. Symfony mode cannot: the serializer turns any
object back into an array on the way out.

`PRESERVE_EMPTY_OBJECTS` does **not** fix this — it only applies to `ArrayObject`, and generated
maps are plain arrays. Making it work would mean typing every map property as `ArrayObject`
(`array_map()` and friends would then need `getArrayCopy()`) *and* requiring every caller to pass
that context option. Not a trade this mode makes.

If a consumer validates your responses strictly, the options are: normalize that response through
[runtime mode](README.runtime.md), or cast the known map keys yourself before encoding:

```php
$payload = $serializer->normalize($dto);
$payload['tags'] = (object)$payload['tags'];
```

## What runtime mode does that this mode does not

| | |
|---|---|
| **Immutability** | only required properties are `readonly`; the optional ones have setters, which is what makes presence tracking possible. Runtime-mode DTOs are immutable throughout |
| **Parameter binding** | `matrix`, `label`, `deepObject`, `spaceDelimited`, `pipeDelimited`, `allowReserved` and multipart Encoding parts are not applied — Symfony parses the query string and the body its own way, before any DTO exists. `allowEmptyValue: false` is the one that survives, as `#[Assert\NotBlank(allowNull: true)]` |
| **readOnly / writeOnly** | advisory unless you pass serialization groups (above); runtime enforces them itself |
| **`additionalProperties: false`** | near a no-op through the serializer, which drops unknown keys before validation |
| **An empty map** | serializes as `[]`, not `{}` — see [below](#an-empty-map-serializes-as-) |

One more asymmetry, in this mode's favour: a polymorphic schema becomes an interface with
`#[DiscriminatorMap]`, so the serializer picks the concrete class natively. That holds for both ways
of writing it — a `oneOf` with a discriminator, and an object base whose variants `allOf` it. In the
second case the base's own properties become getters of the interface, so an argument typed with
the base takes the body as it is:

```php
public function show(#[MapRequestPayload] ShapeRequest $request): Response
{
    // $request is a CircleShapeRequest or a SquareShapeRequest, picked by its discriminator
}
```

An `anyOf` branch that is purely `{type: null}` causes the whole `#[Assert\AtLeastOneOf]` to be
dropped (the field stays nullable).

The schema semantics every mode shares (list vs object, branch order in `oneOf`/`anyOf`,
`unevaluated*`, `content*`, `$defs`, extended formats) are in
[Validation Notes](README.validation.md).

> Requires `symfony/validator` and `symfony/serializer` in the consuming project.
