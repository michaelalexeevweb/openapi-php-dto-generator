# Support matrix

[← back to the main README](README.md) · [performance](README.performance.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md) · [yii3](README.yii3.md)

What each generation mode enforces, and the handful of places where they differ.

**This table is measured, not maintained by hand.** Every row in the vocabulary section is a case in
`tests/Parity/ValidationParityTest`, which generates the same spec in EVERY mode, feeds each one a VALID
and an INVALID payload, and fails unless all verdicts are identical *and* the two payloads get different
answers — a keyword that accepted everything would otherwise pass unnoticed. The mode list is data
(`tests/GenerationMode`), so a mode is either measured in every case or it does not exist; a `match` with
no arm for it fails loudly rather than skipping. Every divergence below is pinned by a named test that
also states the reason; `NormalizationParityTest` additionally fails if a divergence exists without a
written explanation.

So: a row saying "yes" means a payload violating that keyword was rejected by that mode when this was last
run, not that someone believes it works.


## Validation vocabulary

**All five modes enforce every keyword below**, each row a case in the all-mode comparison. The mode
changes who reports it, not whether it is caught.

| Keyword | runtime | symfony | laravel | laravel-data | yii3 |
|---|:---:|:---:|:---:|:---:|:---:|
| `type` (string, integer, number, boolean, array, object, null) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `type` as a union, incl. `[T, null]` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `enum` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `const` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `minLength` / `maxLength` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `pattern` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `minimum` / `maximum` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `exclusiveMinimum` / `exclusiveMaximum` (2020-12: a number) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `exclusiveMinimum` / `exclusiveMaximum` (OpenAPI 3.0: a boolean beside `minimum` / `maximum`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `multipleOf` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `minItems` / `maxItems` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `uniqueItems` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `items` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `prefixItems` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `contains` / `minContains` / `maxContains` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `unevaluatedItems: false` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `minProperties` / `maxProperties` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `additionalProperties` (schema) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `patternProperties` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `propertyNames` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `dependentRequired` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `dependentSchemas` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `required` on a map-shaped object | ✅ | ✅ | ✅ | ✅ | ✅ |
| `unevaluatedProperties: false` on a map-shaped object | ✅ | ✅ | ✅ | ✅ | ✅ |
| `oneOf` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `anyOf` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `allOf` of scalar fragments | ✅ | ✅ | ✅ | ✅ | ✅ |
| `allOf` of `$ref`s or objects UNDER a container | ✅ | ✅ | ✅ | ✅ | ✅ |
| `not` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `if` / `then` / `else` | ✅ | ✅ | ✅ | ✅ | ✅ |
| composition nested inside `items` / `additionalProperties` / `not` | ✅ | ✅ | ✅ | ✅ | ✅ |
| a self-referential schema, at every depth | ✅ | ✅ | ✅ | ✅ | ✅ |
| mutually recursive schemas (`A → B → A`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `contentEncoding` / `contentMediaType` / `contentSchema` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `email`, `idn-email` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `uuid` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri`, `iri` (absolute) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri-reference`, `iri-reference` (relative allowed) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri-template` (RFC 6570) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `hostname`, `idn-hostname` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `ipv4`, `ipv6` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `date`, `time`, `duration` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `byte` (base64) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `regex` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `json-pointer`, `relative-json-pointer` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `format`: `int32`, `int64`, `uint32`, `uint64` bounds | ✅ | ✅ | ✅ | ✅ | ✅ |

`format: date-time` is enforced in all five too, but not to the same strictness — see the divergences.

An unknown or custom `format` (`uppercase`, `slug`, …) is an ANNOTATION, not an assertion: accepted in
every mode, exactly as the spec says. Do not expect it to validate anything.

Two keywords are enforced everywhere but measured outside the keyword matrix, because a probe for them
needs more than one schema:

| Keyword | Measured in |
|---|---|
| `discriminator` (mapping, `allOf` variants, hydration to the mapped class) | `tests/Runtime/GenerateDtoCommandTest`, `tests/Symfony/SymfonyConstraintMatrixTest`, `tests/Parity/NormalizationParityTest`, and per mode: `tests/Laravel/GenerateLaravelDtoTest`, `tests/LaravelData/MorphDiscriminatorTest`, `tests/Yii3/Yii3RequestShapeTest` (typing only — see the divergences) |
| `required` / `properties` on a schema that becomes a DTO | the class itself: in four modes a required property is a constructor parameter with no default, so no payload can omit it; yii3 has no constructor, so absence is a property left uninitialised and the interpreter reports it — `tests/Yii3/Yii3RuleCoverageTest`. Nested `required` is measured by the recursion cases above and `tests/Laravel/LaravelRulesEnforcementTest` |

### Which layer does it

Same vocabulary, five deliveries — and this is what the modes are actually choosing between:

| | How |
|---|---|
| runtime | one place: `DtoValidator` walks the schema it holds |
| symfony | `#[Assert\*]` attributes for what the constraint set can express, one generated `#[Assert\Callback]` for the rest |
| laravel | `rules()` for what Laravel's rule vocabulary can express, a generated `withValidator()` for the rest — the per-keyword split is the table in [README.laravel.md](README.laravel.md#two-layers-one-vocabulary) |
| laravel-data | the same `rules()` array and the same `withValidator()` interpreter, emitted onto a `Data` class and run by laravel-data's own pipeline. Identical messages, because it is literally the same emitter |
| yii3 | `yiisoft/validator` attributes for what its rule set can express, one class-level `#[Callback]` for the rest — the same interpreter again, packaged as a method returning a `Result` |

A keyword the framework has a native rule for keeps the FRAMEWORK's message, so your own translations
still apply. Everything the generated interpreter owns produces the same sentence in every mode, differing
only in how the subject is named — pinned by `tests/Parity/InterpreterMessageParityTest`.


## Divergences

Fourteen, all deliberate, all pinned by a test that names the cause. Each is one of eight things: Symfony
mode's serializer or the yii3 hydrator deciding before generated code gets a say, that serializer having no
way to say ABSENT on the way out, a mode not holding the raw body, laravel-data's own normalizer having no
notion of the wire shape, the yii3 hydrator casting only to DECLARED types, a mode having no per-ITEM cast to
give a container, or PHP itself refusing to let `mixed` join a union.

**Reading the icons:** ✅ means the mode does what the DOCUMENT asks for that value, ❌ means it does
not — not "accepted" versus "refused". `42.0` for `type: integer` is a conformant payload (JSON Schema
2020-12 §6.1.1), so accepting it is ✅ and refusing it is ❌; `42.5` is not, so the marks flip.

| Behaviour | runtime | symfony | laravel | laravel-data | yii3 | Why |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| `42.0` for `type: integer` (JSON Schema 2020-12 §6.1.1) | ✅ accepted | ❌ 422 | ✅ accepted | ✅ accepted | ✅ accepted | A number with a ZERO fractional part IS an integer, so accepting it is the conformant answer. Symfony's serializer type-checks the `int` property before any generated constraint runs, so it refuses the one value the spec calls an integer — a gap it cannot close from generated code — `ValidationParityTest::testAnIntegralFloatIsAnIntegerWhereverTheGeneratorOwnsTheCheck`. It is the PROPERTY that is refused: inside a container the value goes to the callback, which reads the spec's answer, so a list AND a map of `type: integer` both accept `42.0` |
| `42.5` for `type: integer` | ✅ refused | ✅ refused | ✅ refused | ✅ refused | ❌ accepted as `42` | The mirror of the row above, and the reason it is two rows: `yiisoft/hydrator` casts to the DECLARED type before the validator sees the object, so a fractional number arrives as an `int` and clears every rule. Symfony refuses it for the same reason it refuses `42.0` — the serializer, not the document — which happens to be right here. Same test |
| a loose `format: date-time` string (`"yesterday"`) | ✅ refused | ❌ accepted | ✅ refused | ✅ refused | ✅ refused | The property is a `DateTimeImmutable`, so the serializer parses the string first, and PHP's parser is generous. Runtime and laravel accept only the four patterns every mode agrees on (`GeneratedDtoInterface::DATE_TIME_FORMATS`) — `testALooseDateTimeStringIsRefusedWhereverTheGeneratorOwnsTheCheck` |
| a JSON array for a `type: object` property | ✅ refused | ❌ accepted | ✅ refused | ✅ refused¹ | ❌ accepted | The distinction lives in the RAW body: once decoded, `{"0":1,"1":2}` and `[1,2]` are the same PHP value. Runtime decodes the body itself, the generated Laravel FormRequest hands `withValidator()` the undecoded body; `#[MapRequestPayload]` denormalizes first, and the yii3 hydrator likewise builds the object before the validator runs — `testAJsonArrayIsRefusedForATypeObjectPropertyWhereverTheRawBodyIsReachable` |
| an OPTIONAL property sent as `null` when the schema is not `nullable` | ✅ refused | ❌ accepted | ✅ refused | ✅ refused | ✅ refused | An optional property's PHP type is nullable so it can default to `null`, and no `#[Assert\NotNull]` is emitted — `testAnOptionalPropertyIsNotNullable` |
| `additionalProperties: false` / `unevaluatedProperties: false` on a **DTO-shaped** schema | ✅ | ❌ | ✅ | ✅ | ❌ | Enforceable only where the RAW body is still reachable. Runtime holds it, and the two rule-based modes run their interpreter over it. Symfony's serializer and the yii3 hydrator both fill a typed object first, and a key the schema never declared is gone before generated code runs — `testAClosedObjectRefusesAnUndeclaredKeyWhereverTheRawBodyIsReachable` |
| an empty map on the wire | `{}` | `[]` | `{}` | `[]` | `[]` | `type: object` says object. Runtime and laravel cast maps to `stdClass`; the Symfony serializer, laravel-data and the yii3 hydrator all keep the PHP array — `NormalizationParityTest` |
| sub-second precision in a `format: date-time` RESPONSE | kept | kept | kept | dropped | kept | A laravel-data transformer takes ONE format string, so it cannot say "keep the precision the payload carried". The emitted `#[WithCast]` accepts all four patterns on the way IN; the way out is ATOM. yii3 writes the wire value itself, so it keeps the precision the payload carried, like runtime — `NormalizationParityTest`, case "date-time with microseconds" |
| a `readOnly` property sent BY the client | ignored | echoed² | ignored | echoed | ignored | `#[Hidden]` is laravel-data's exact counterpart to `writeOnly`, and it has none for `readOnly`: nothing says "hydrate everything except this key". yii3 keeps its own list of names to leave out, so it drops both — `NormalizationParityTest`, case "readOnly property sent by the client" |
| an unprovided optional property in `toArray()`, with or without a schema `default` | omitted | echoed as `null`, or as the `default` | omitted | omitted | omitted | The four modes that carry presence in the property itself leave the key out; the Symfony serializer normalizes every property it has, and the constructor default IS that property's value — so an absent key and a `default` the document declared come back the same way. `isXxxProvided()` still answers the question on the object — `NormalizationParityTest`, cases "optional property missing" and "optional property with a default, absent" |
| an unprovided optional property with NO type in its schema | omitted | echoed as `null`² | omitted | echoed as `null` | omitted | `mixed` cannot take part in a union type, so this is the one property shape with no `\|Optional` (laravel-data) and no sentinel union (runtime) to mark absence with. laravel-data fills the missing key with `null` and echoes it; yii3 leaves the property uninitialised, which is absence without a type to express it — `NormalizationParityTest`, case "untyped optional property missing" |
| a discriminated union arriving in a PAYLOAD | ✅ built | ✅ built | ✅ built | ✅ built | ❌ left unset | `yiisoft/hydrator` casts to a DECLARED type, and the declared type here is the union interface. Reading the discriminator to choose a member would need a type caster of ours in the generated output, which this mode does not emit — the interface is still the right type for a response and for code that builds a member itself. Pinned by `Yii3RequestShapeTest::testAUnionMemberImplementsTheUnionInterface` |
| the ITEMS of an array or map of `format: date` / `date-time` | `DateTimeImmutable` | `DateTimeImmutable` | `DateTimeImmutable` | `string` | `string` | See [Temporal container items](#temporal-container-items) below. Runtime and Laravel cast each item themselves, Symfony's `ArrayDenormalizer` does it; laravel-data's `#[WithCast]` casts the PROPERTY and never reaches an item, and the yii3 `#[ToDateTime]` resolver refuses an array outright. The two that hold strings SAY `array<string>` — `GenerateLaravelDtoTest::testTemporalContainerItemsAreCastAndReadAsStrings`, `LaravelDataSemanticsTest::testACastOnAnArrayPropertyDoesNotReachItsItems` |
| key order of a discriminated union member in `toArray()` | discriminator first | discriminator first | discriminator first | discriminator last | discriminator first | The base is an abstract `Data` class here, so the discriminator is an INHERITED property, and PHP reflection lists a class's own properties before its parent's. Same keys, same values; JSON object order carries no meaning — `NormalizationParityTest`, case "discriminated union" |

¹ laravel-data reads the raw body from the current request. On the `validateAndCreate($array)` entry
point there is no request, so that one check is skipped and everything else still runs.

² symfony needs serialization groups passed by the caller (`['groups' => 'read']` / `'write'`) for
`writeOnly` omission and `readOnly` input; runtime and laravel enforce both unconditionally, and
laravel-data enforces `writeOnly` unconditionally via `#[Hidden]`.

Everything else about the response shape is identical in all five.

### Temporal container items

`items: {type: string, format: date}` asks for a container whose ELEMENTS are dates. Three modes deliver
objects there and two deliver strings — and every mode's DECLARATION now says which:

| | holds | declares | reader |
|---|---|---|---|
| runtime | `DateTimeImmutable` | `array<DateTimeImmutable>` | `getX()` formats, `getXAsDateTime()` gives the objects |
| symfony | `DateTimeImmutable` | `array<DateTimeImmutable>` | same pair, the object getter `#[Ignore]`d |
| laravel | `DateTimeImmutable` | `array<DateTimeImmutable>` | same pair |
| laravel-data | `string` | `array<string>` | the public property |
| yii3 | `string` | `array<string>` | `getX()` |

For laravel-data and yii3 the honest declaration is the whole fix: converting would mean emitting a
`Cast` class (laravel-data) or a `ParameterAttributeResolverInterface` plus a hydrator registration
(yii3) into generated code that today depends on **nothing** from this package — a dependency for a
convenience, given that every mode already writes the right STRING to the wire.

What does not differ: a `format: date` element leaves as a date and a `date-time` element keeps its
offset, in all five. Only the type of the value you read off the object does.

### Containers inside containers

Below the first level of items nothing is materialized — no DTO, no enum class — and nothing casts a
value. All five modes declare that rather than promising otherwise:

| schema two containers deep | declared, every mode |
|---|---|
| array of arrays / maps of scalars | `array<array<int>>`, `array<array<string, string>>` |
| map of arrays / map of maps | `array<string, array<string>>`, `array<string, array<string, int>>` |
| `format: date` / `binary`, or an `enum` | `array<array<string>>` |
| `type: number` | `array<array<float\|int>>` |
| a `$ref` to a scalar or enum component | that component's backing type |
| a `$ref` to a CONTAINER component | the container it aliases — `array<array<string>>` |
| a `$ref` to an OBJECT component | that component's DTO — `array<array<Tag>>` |

#### A container value the schema lets be null

`nullable` on a container value is a permission, and it is honoured in BOTH spellings — under `items`
and under `additionalProperties` — in all five modes:

```yaml
listOfNullable: { type: array,  items:                { type: string, nullable: true } }
mapOfNullable:  { type: object, additionalProperties: { type: string, nullable: true } }
```

declares `array<?string>` and `array<string, ?string>`, deserializes `[null]` and `{"k": null}`
without complaint, and still refuses a non-null value of the wrong type — once, naming the element.
The 3.1 spelling of the same permission, `type: [string, "null"]`, declares and behaves identically.

Before 2.15.7 each of the four combinations was wrong in its own way: the 3.0 map value died in the
cast with `param "mapOfNullable.k" expects string, got null`; the 3.0 list was declared `array<?string>`
but `?string` was read as one unknown name, so the null it permits came back as
`returned null but type is non-nullable string`; the 3.1 list lost its item type outright and was
declared a bare `array`, whose items are never checked at all; and the 3.1 map declared
`array<string, string>`, forbidding the null the document had just allowed.

Every row is the value that is really there, measured rather than reasoned. A date and an enum member
are the plain `string` the payload carried — one level up they would be a `DateTimeImmutable` and an
enum case, at this depth nothing converts them. `type: number` is BOTH: JSON hands `1` over as an int
and `1.5` as a float, and a single array holds both, so `float` alone would be wrong half the time.

The object row is the one that moved. It said `array<array<string, Tag>>` while holding a `stdClass`,
which was a lie; 2.15.4 replaced it with the true-but-poorer `mixed`; 2.15.7 hydrates the value, so the
name is what is true again. The rows above it used to say `mixed` while holding a perfectly ordinary
string, which said less than was known.

The VALUES are still checked, by whichever layer the mode uses: `DtoValidator` (runtime), the emitted
interpreter (symfony, yii3) or dotted `field.*.*` rules (laravel, laravel-data). A scalar keeps its
type, bounds and pattern at any depth, an enum's members are checked, and an OBJECT is checked against
the component it references — its `required` properties, its property types and bounds, and that it is
an object at all:

```
array<array<Tag>>, Tag = {required: [id], properties: {id: {type: integer, minimum: 5}}}

[[{"id": 9}]]     accepted
[[{"id": 1}]]     param "tagRows.0.0.id" must be greater than or equal to 5.
[[{}]]            Required parameter "tagRows.0.0.id" not found in request.
[["zzz"]]         param "tagRows.0.0" expects object, got string.
```

HYDRATION reaches that depth too, as of 2.15.7: the value IS the DTO the declaration names, in all four
spellings of two-deep — list of lists, list of maps, map of lists, map of maps. The path is quoted whole
because the report comes from the same cast that reports a DTO one level up; while the value was a bare
`stdClass` the container interpreter reported it instead and quoted only the property, `tagRows".0.0.id`.

THREE containers deep nothing is hydrated and the declaration says `mixed` — see
[Not generated in any mode](#not-generated-in-any-mode). A nested SCALAR is deliberately left to the
validator: it is already the value it claims to be, and casting it would only reword a message.


## Beyond validation

| | runtime | symfony | laravel | laravel-data | yii3 |
|---|:---:|:---:|:---:|:---:|:---:|
| Typed immutable DTO | ✅ | ✅ | ✅ | ✅ | ✅ |
| Generated backed enums | ✅ | ✅ | ✅ | ✅ | ✅ |
| Temporal properties (`DateTimeImmutable` + the schema's own string form) | ✅ | ✅ | ✅ | ✅ (ATOM out for `date-time`) | ✅ (needs `ext-intl`) |
| Temporal container ITEMS as objects | ✅ | ✅ | ✅ | ➖ strings, declared as such | ➖ strings, declared as such |
| Containers inside containers | ✅ declared, values checked | ✅ | ✅ | ✅ | ✅ |
| Nested DTOs, lists of DTOs, maps | ✅ | ✅ | ✅ | ✅ | ✅ |
| Discriminated unions | ✅ interface + members | ✅ interface + members | ✅ interface + members | ✅ abstract `Data` + `morph()` | ➖ interface + members, no hydration |
| PATCH / partial updates | ✅ `UnsetValue` | ✅ `isXxxProvided()` via the setter | ✅ `isXxxProvided()` from the validated keys | ✅ `Optional` — the property's own type | ✅ `isXxxProvided()` from `hasProperty()` |
| `toArray()` / response shape | ✅ | serializer | ✅ | laravel-data's own | `getData()` |
| Uploaded files (`format: binary`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Validated before the controller body runs | you call the deserializer | ✅ `#[MapRequestPayload]` | ✅ the FormRequest is resolved first | ✅ the container resolves `Data::from($request)` | ✅ hydrated and validated; the action reads the verdict |
| Framework-native error envelope | aggregated exception | `ConstraintViolationList` → 422 | the framework's own 422 + error bag | the framework's own 422 + error bag | a `Result` you turn into a response |
| Request binding: parameter sources (`path`/`query`/`header`/`cookie`) | ✅ | ❌ | ❌ | ❌ | ➖ body/query/path only |
| Request binding: `style` / `explode` / `allowReserved` / `allowEmptyValue` | ✅ | ❌ | ❌ | ❌ | ❌ |
| Request binding: multipart `encoding` | ✅ | ❌ | ❌ | ❌ | ❌ |
| Runtime dependency of the generated code | this package (or a vendored copy) | `symfony/validator` + `symfony/serializer` | none — ships with the framework | `spatie/laravel-data` | `yiisoft/input-http` + `yiisoft/validator` |

The ❌ rows on the request-binding lines have one cause: by the time a `#[MapRequestPayload]` argument, a
`FormRequest`, a `Data` object or a yii3 input exists, the framework has already parsed the request its own
way. Those OpenAPI rules are about the request itself, so **use runtime mode when the request must follow
the spec**. yii3 is the only framework mode that reaches part of the way: `in: query` and `in: path` bind
through `#[Query]` and `#[Request]`, but `in: header` and `in: cookie` have no attribute in `input-http`
at all, so a property declaring either is emitted with no source rather than a wrong one.


## Not generated in any mode

| | Why |
|---|---|
| hydration of a union of OBJECTS with no `discriminator` | the document does not say which member a given object is, and choosing by structure would be a guess two overlapping branches could not settle. The union is emitted as an interface — useful as a type, and fine for a response — but a payload cannot be turned back into a member in ANY mode. The generator names the property at generation time instead of letting the request find out; add a `discriminator` and every mode resolves it. Pinned by `tests/Parity/UnhydratableUnionParityTest` |
| hydration of a `$ref`ed OBJECT THREE containers deep | hydration reaches two deep as of 2.15.7 — `array<array<Tag>>` holds real `Tag` instances in all four spellings. Below that it stops: `array<array<array<Tag>>>` holds the `stdClass` `json_decode()` produced, and the declaration says `array<array<array<mixed>>>` rather than naming a class that is not there. VALIDATION does reach it — the referenced component's own rules are inlined into the emitted constraints, see [Containers inside containers](#containers-inside-containers) — so what is missing is the typed object, not the checks. Use a named component for the ROW (`array<Row>` where `Row` wraps the list) when you want instances that deep |
| checking past SIX `$ref` hops on one path below materialization | where nothing materializes — a `$ref` chain under a `oneOf` below a container — the referenced components' rules are inlined into the emitted constraints, and that inlining stops after five hops. A chain deeper than that is accepted at the levels past the fifth without a word. The ceiling is what keeps a branching document finite: measured on an acyclic schema of three components per level, the constraints grow ~2.9x PER LEVEL — 70 KB at five, 568 KB and 68 MB at seven. A self-referential component is unrolled twice and stops there, whatever the ceiling allows. The truncation is not silent: the generator warns once per cut point, naming the `$ref` where checking stops — a recursive schema does NOT warn, having no finite inline form to ask for. Pinned by `GeneratedConstraintsIntegrationTest::testInliningStopsAtACycleAndAtTheHopCeiling` and `GenerateDtoCommandTest::testTheInlineCeilingIsWarnedAboutAndTheCycleGuardIsNot` |
| `authorize()` / any policy | an OpenAPI document describes payload shape, not authorization |
| server, security scheme and callback objects | out of scope: this generates DTOs, not a router or an auth layer |
| subschema-local `$defs` (`#/components/schemas/Foo/$defs/Bar`) | top-level `components.schemas` / `$defs` are folded and supported; a nested `$defs` pointer is not — prefer top-level shared types |


## Reproducing this table

```bash
vendor/bin/phpunit --filter Parity     # the vocabulary rows and every divergence
vendor/bin/phpunit --filter Golden     # the emitted corpus, snapshotted per mode
vendor/bin/phpunit                     # everything
```

If a row here stops being true, one of those fails. That is the point of writing it down this way.
