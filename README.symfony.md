# Symfony mode

[← back to the main README](README.md) · other modes: [runtime](README.runtime.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md) · [support matrix](README.support-matrix.md) · [performance](README.performance.md)

Generated with `--attributes=symfony`. DTOs are plain data classes decorated with **Symfony
Validator / Serializer attributes**. There is no library runtime: `symfony/validator` validates them
and `symfony/serializer` (de)serializes them — or a controller maps them automatically with
`#[MapRequestPayload]` / `#[MapQueryString]`.

Required properties are constructor arguments and stay `readonly`. Optional ones are set by the
serializer through a setter, and that setter records that the payload carried the key — which is
what makes PATCH semantics work (see [below](#presence-tracking-patch--partial-updates)).

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
    /**
     * @var ?string The display name Example: John
     */
    #[Assert\Length(min: 2, max: 50)]
    private ?string $name = null;

    #[Ignore]
    private bool $nameProvided = false;

    public function __construct(
        #[Assert\NotNull]
        private readonly int $id,
    ) {
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

Building one by hand: required fields go through the constructor, optional ones through setters.

```php
$user = new User(id: 1);
$user->setName('John');
```

In a Symfony controller the DTO is validated and populated automatically:

```php
public function create(#[MapRequestPayload] User $user): Response { /* ... */ }
```

## OpenAPI → Symfony attribute mapping

| OpenAPI | Symfony attribute |
|---|---|
| `required` (non-nullable) | `#[Assert\NotNull]` |
| `minLength` / `maxLength` | `#[Assert\Length(min:, max:)]` |
| `minimum` / `maximum` | `#[Assert\Range(min:, max:)]` |
| `exclusiveMinimum` / `exclusiveMaximum` | `#[Assert\GreaterThan]` / `#[Assert\LessThan]` |
| `multipleOf` | `#[Assert\DivisibleBy]` |
| `pattern` | `#[Assert\Regex]` |
| `minItems` / `maxItems`, `minProperties` / `maxProperties` | `#[Assert\Count]` |
| `uniqueItems` | `#[Assert\Unique]` |
| `const` | `#[Assert\EqualTo]` |
| `enum` | generated PHP backed `enum` when values are string/int only; otherwise inline `#[Assert\Choice]` |
| `format: email` / `uuid` / `url` / `ipv4`,`ipv6` / `hostname` | `#[Assert\Email]` / `Uuid` / `Url` / `Ip` / `Hostname` |
| `format: int32` / `uint32` | `#[Assert\Range]` (bounds) |
| `format: date` / `date-time` | `DateTimeImmutable` property; the getter returns the formatted string and `getXAsDateTime()` the object (see [dates](#dates-are-formatted-by-the-dto-not-the-normalizer)) |
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
| `const` (inside a subschema) | equality |
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
at `OPENAPI_MAX_VALIDATION_DEPTH = 256`.

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
| wrong scalar type, unknown enum member, missing required field | **422**, as a violation: `This value should be of type int.` — the generated constraints never run for that field |
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

An optional property the client never sent normalizes as an explicit `null`:

```json
{"id": 1, "note": null}
```

Runtime mode omits such a key instead. There is no way to match that with the stock serializer: the
DTO knows the difference (`isNoteProvided()`), but normalization goes through the getter, which
answers `null` either way. Both available knobs drop **explicit** nulls too, so neither is a
parity fix:

```php
// drops every null — the ones the client sent as well
$serializer->normalize($dto, null, [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
```

Matching runtime exactly would mean implementing `NormalizableInterface` on every DTO, registering
`CustomNormalizer` (not enabled by default) and re-implementing `#[SerializedName]`, `#[Groups]` and
the date-format `#[Context]` inside the generated code. Not worth it for a null in the payload —
this mode is meant to run on the stock serializer.

### Dates are formatted by the DTO, not the normalizer

Symfony's `DateTimeNormalizer` has one fixed pattern per context, which cannot express what OpenAPI
asks for: `format: date` must stay a date, and a `date-time` must keep the sub-second precision the
payload carried. So the generated getter formats the value itself — the same rule runtime mode uses:

```php
$dto->getAt();             // "2026-03-10T12:00:00.123456+03:00" — precision preserved
$dto->getOn();             // "2026-03-10" — a date, not a timestamp
$dto->getAtAsDateTime();   // the DateTimeImmutable, #[Ignore]d so it stays out of the output
```

The property itself is still a `DateTimeImmutable`, so denormalization, `#[Assert\*]` and the
`#[Assert\Valid]` cascade are unchanged — only the read side differs.

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
`#[DiscriminatorMap]`, so the serializer picks the concrete class natively.

An `anyOf` branch that is purely `{type: null}` causes the whole `#[Assert\AtLeastOneOf]` to be
dropped (the field stays nullable).

The schema semantics every mode shares (list vs object, branch order in `oneOf`/`anyOf`,
`unevaluated*`, `content*`, `$defs`, extended formats) are in
[Validation Notes](README.md#validation-notes).

> Requires `symfony/validator` and `symfony/serializer` in the consuming project.
