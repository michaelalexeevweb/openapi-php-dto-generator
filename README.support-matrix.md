# Support matrix

[← back to the main README](README.md) · [performance](README.performance.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md)

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

**All four modes enforce every keyword below**, each row a case in the all-mode comparison. The mode
changes who reports it, not whether it is caught.

| Keyword | runtime | symfony | laravel | laravel-data |
|---|:---:|:---:|:---:|:---:|
| `type` (string, integer, number, boolean, array, object, null) | ✅ | ✅ | ✅ | ✅ |
| `type` as a union, incl. `[T, null]` | ✅ | ✅ | ✅ | ✅ |
| `enum` | ✅ | ✅ | ✅ | ✅ |
| `const` | ✅ | ✅ | ✅ | ✅ |
| `minLength` / `maxLength` | ✅ | ✅ | ✅ | ✅ |
| `pattern` | ✅ | ✅ | ✅ | ✅ |
| `minimum` / `maximum` | ✅ | ✅ | ✅ | ✅ |
| `exclusiveMinimum` / `exclusiveMaximum` | ✅ | ✅ | ✅ | ✅ |
| `multipleOf` | ✅ | ✅ | ✅ | ✅ |
| `minItems` / `maxItems` | ✅ | ✅ | ✅ | ✅ |
| `uniqueItems` | ✅ | ✅ | ✅ | ✅ |
| `items` | ✅ | ✅ | ✅ | ✅ |
| `prefixItems` | ✅ | ✅ | ✅ | ✅ |
| `contains` / `minContains` / `maxContains` | ✅ | ✅ | ✅ | ✅ |
| `unevaluatedItems: false` | ✅ | ✅ | ✅ | ✅ |
| `minProperties` / `maxProperties` | ✅ | ✅ | ✅ | ✅ |
| `additionalProperties` (schema) | ✅ | ✅ | ✅ | ✅ |
| `patternProperties` | ✅ | ✅ | ✅ | ✅ |
| `propertyNames` | ✅ | ✅ | ✅ | ✅ |
| `dependentRequired` | ✅ | ✅ | ✅ | ✅ |
| `dependentSchemas` | ✅ | ✅ | ✅ | ✅ |
| `required` on a map-shaped object | ✅ | ✅ | ✅ | ✅ |
| `unevaluatedProperties: false` on a map-shaped object | ✅ | ✅ | ✅ | ✅ |
| `oneOf` | ✅ | ✅ | ✅ | ✅ |
| `anyOf` | ✅ | ✅ | ✅ | ✅ |
| `allOf` of scalar fragments | ✅ | ✅ | ✅ | ✅ |
| `not` | ✅ | ✅ | ✅ | ✅ |
| `if` / `then` / `else` | ✅ | ✅ | ✅ | ✅ |
| composition nested inside `items` / `additionalProperties` / `not` | ✅ | ✅ | ✅ | ✅ |
| a self-referential schema, at every depth | ✅ | ✅ | ✅ | ✅ |
| mutually recursive schemas (`A → B → A`) | ✅ | ✅ | ✅ | ✅ |
| `contentEncoding` / `contentMediaType` / `contentSchema` | ✅ | ✅ | ✅ | ✅ |
| `format`: `email`, `idn-email` | ✅ | ✅ | ✅ | ✅ |
| `format`: `uuid` | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri`, `iri` (absolute) | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri-reference`, `iri-reference` (relative allowed) | ✅ | ✅ | ✅ | ✅ |
| `format`: `uri-template` (RFC 6570) | ✅ | ✅ | ✅ | ✅ |
| `format`: `hostname`, `idn-hostname` | ✅ | ✅ | ✅ | ✅ |
| `format`: `ipv4`, `ipv6` | ✅ | ✅ | ✅ | ✅ |
| `format`: `date`, `time`, `duration` | ✅ | ✅ | ✅ | ✅ |
| `format`: `byte` (base64) | ✅ | ✅ | ✅ | ✅ |
| `format`: `regex` | ✅ | ✅ | ✅ | ✅ |
| `format`: `json-pointer`, `relative-json-pointer` | ✅ | ✅ | ✅ | ✅ |
| `format`: `int32`, `int64`, `uint32`, `uint64` bounds | ✅ | ✅ | ✅ | ✅ |

`format: date-time` is enforced in all four too, but not to the same strictness — see the divergences.

An unknown or custom `format` (`uppercase`, `slug`, …) is an ANNOTATION, not an assertion: accepted in
every mode, exactly as the spec says. Do not expect it to validate anything.

Two keywords are enforced everywhere but measured outside the keyword matrix, because a probe for them
needs more than one schema:

| Keyword | Measured in |
|---|---|
| `discriminator` (mapping, `allOf` variants, hydration to the mapped class) | `tests/Runtime/GenerateDtoCommandTest`, `tests/Symfony/SymfonyConstraintMatrixTest`, `tests/Parity/NormalizationParityTest`, and per mode: `tests/Laravel/GenerateLaravelDtoTest`, `tests/LaravelData/MorphDiscriminatorTest` |
| `required` / `properties` on a schema that becomes a DTO | the class itself: a required property is a constructor parameter with no default, so no payload can omit it. Nested `required` is measured by the recursion cases above and `tests/Laravel/LaravelRulesEnforcementTest` |

### Which layer does it

Same vocabulary, four deliveries — and this is what the modes are actually choosing between:

| | How |
|---|---|
| runtime | one place: `DtoValidator` walks the schema it holds |
| symfony | `#[Assert\*]` attributes for what the constraint set can express, one generated `#[Assert\Callback]` for the rest |
| laravel | `rules()` for what Laravel's rule vocabulary can express, a generated `withValidator()` for the rest — the per-keyword split is the table in [README.laravel.md](README.laravel.md#two-layers-one-vocabulary) |
| laravel-data | the same `rules()` array and the same `withValidator()` interpreter, emitted onto a `Data` class and run by laravel-data's own pipeline. Identical messages, because it is literally the same emitter |

A keyword the framework has a native rule for keeps the FRAMEWORK's message, so your own translations
still apply. Everything the generated interpreter owns produces the same sentence in every mode, differing
only in how the subject is named — pinned by `tests/Parity/InterpreterMessageParityTest`.


## Divergences

Eleven, all deliberate, all pinned by a test that names the cause. Each is one of five things: Symfony
mode's serializer deciding before generated code gets a say, its serializer having no way to say ABSENT on
the way out, a mode not holding the raw body, laravel-data's own normalizer having no notion of the wire
shape, or PHP itself refusing to let `mixed` join a union.

| Behaviour | runtime | symfony | laravel | laravel-data | Why |
|---|:---:|:---:|:---:|:---:|---|
| `42.0` for `type: integer` (JSON Schema 2020-12 §6.1.1) | ✅ accepted | ❌ 422 | ✅ accepted | ✅ accepted | Symfony's serializer type-checks the `int` property before any generated constraint runs. The one conformance gap this mode cannot close from generated code — `ValidationParityTest::testAnIntegralFloatIsAnIntegerWhereverTheGeneratorOwnsTheCheck` |
| a loose `format: date-time` string (`"yesterday"`) | ✅ refused | ❌ accepted | ✅ refused | ✅ refused | The property is a `DateTimeImmutable`, so the serializer parses the string first, and PHP's parser is generous. Runtime and laravel accept only the four patterns every mode agrees on (`GeneratedDtoInterface::DATE_TIME_FORMATS`) — `testALooseDateTimeStringIsRefusedWhereverTheGeneratorOwnsTheCheck` |
| a JSON array for a `type: object` property | ✅ refused | ❌ accepted | ✅ refused | ✅ refused¹ | The distinction lives in the RAW body: once decoded, `{"0":1,"1":2}` and `[1,2]` are the same PHP value. Runtime decodes the body itself, the generated Laravel FormRequest hands `withValidator()` the undecoded body; `#[MapRequestPayload]` denormalizes first — `testAJsonArrayIsRefusedForATypeObjectPropertyWhereverTheRawBodyIsReachable` |
| an OPTIONAL property sent as `null` when the schema is not `nullable` | ✅ refused | ❌ accepted | ✅ refused | ✅ refused | An optional property's PHP type is nullable so it can default to `null`, and no `#[Assert\NotNull]` is emitted — `testAnOptionalPropertyIsNotNullable` |
| `additionalProperties: false` / `unevaluatedProperties: false` on a **DTO-shaped** schema | ❌ | ❌ | ✅ | ✅ | The two hydrating modes bind the payload into a typed object first and an undeclared key has nowhere to go. The two rule-based modes validate before hydration, over the raw payload — `testUnknownPayloadKeysAreDroppedWhereverThePayloadIsHydratedBeforeValidation` |
| an empty map on the wire | `{}` | `[]` | `{}` | `[]` | `type: object` says object. Runtime and laravel cast maps to `stdClass`; the Symfony serializer and laravel-data both keep the PHP array — `NormalizationParityTest` |
| sub-second precision in a `format: date-time` RESPONSE | kept | kept | kept | dropped | A laravel-data transformer takes ONE format string, so it cannot say "keep the precision the payload carried". The emitted `#[WithCast]` accepts all four patterns on the way IN; the way out is ATOM — `NormalizationParityTest`, case "date-time with microseconds" |
| a `readOnly` property sent BY the client | ignored | echoed² | ignored | echoed | `#[Hidden]` is laravel-data's exact counterpart to `writeOnly`, and it has none for `readOnly`: nothing says "hydrate everything except this key" — `NormalizationParityTest`, case "readOnly property sent by the client" |
| an unprovided optional property in `toArray()`, with or without a schema `default` | omitted | echoed as `null`, or as the `default` | omitted | omitted | The three modes that carry presence in the property itself leave the key out; the Symfony serializer normalizes every property it has, and the constructor default IS that property's value — so an absent key and a `default` the document declared come back the same way. `isXxxProvided()` still answers the question on the object — `NormalizationParityTest`, cases "optional property missing" and "optional property with a default, absent" |
| an unprovided optional property with NO type in its schema | omitted | echoed as `null`² | omitted | echoed as `null` | `mixed` cannot take part in a union type, so this is the one property shape with no `\|Optional` (laravel-data) and no sentinel union (runtime) to mark absence with. laravel-data fills the missing key with `null` and echoes it — `NormalizationParityTest`, case "untyped optional property missing" |
| key order of a discriminated union member in `toArray()` | discriminator first | discriminator first | discriminator first | discriminator last | The base is an abstract `Data` class here, so the discriminator is an INHERITED property, and PHP reflection lists a class's own properties before its parent's. Same keys, same values; JSON object order carries no meaning — `NormalizationParityTest`, case "discriminated union" |

¹ laravel-data reads the raw body from the current request. On the `validateAndCreate($array)` entry
point there is no request, so that one check is skipped and everything else still runs.

² symfony needs serialization groups passed by the caller (`['groups' => 'read']` / `'write'`) for
`writeOnly` omission and `readOnly` input; runtime and laravel enforce both unconditionally, and
laravel-data enforces `writeOnly` unconditionally via `#[Hidden]`.

Everything else about the response shape is identical in all four.


## Beyond validation

| | runtime | symfony | laravel | laravel-data |
|---|:---:|:---:|:---:|:---:|
| Typed immutable DTO | ✅ | ✅ | ✅ | ✅ |
| Generated backed enums | ✅ | ✅ | ✅ | ✅ |
| Temporal properties (`DateTimeImmutable` + the schema's own string form) | ✅ | ✅ | ✅ | ✅ (ATOM out for `date-time`) |
| Nested DTOs, lists of DTOs, maps | ✅ | ✅ | ✅ | ✅ |
| Discriminated unions | ✅ interface + members | ✅ interface + members | ✅ interface + members | ✅ abstract `Data` + `morph()` |
| PATCH / partial updates | ✅ `UnsetValue` | ✅ `isXxxProvided()` via the setter | ✅ `isXxxProvided()` from the validated keys | ✅ `Optional` — the property's own type |
| `toArray()` / response shape | ✅ | serializer | ✅ | laravel-data's own |
| Uploaded files (`format: binary`) | ✅ | ✅ | ✅ | ✅ |
| Validated before the controller body runs | you call the deserializer | ✅ `#[MapRequestPayload]` | ✅ the FormRequest is resolved first | ✅ the container resolves `Data::from($request)` |
| Framework-native error envelope | aggregated exception | `ConstraintViolationList` → 422 | the framework's own 422 + error bag | the framework's own 422 + error bag |
| Request binding: parameter sources (`path`/`query`/`header`/`cookie`) | ✅ | ❌ | ❌ | ❌ |
| Request binding: `style` / `explode` / `allowReserved` / `allowEmptyValue` | ✅ | ❌ | ❌ | ❌ |
| Request binding: multipart `encoding` | ✅ | ❌ | ❌ | ❌ |
| Runtime dependency of the generated code | this package (or a vendored copy) | `symfony/validator` + `symfony/serializer` | none — ships with the framework | `spatie/laravel-data` |

The ❌ rows have one cause: by the time a `#[MapRequestPayload]` argument, a `FormRequest` or a `Data`
object exists, the framework has already parsed the request its own way. Those OpenAPI rules are about the
request itself, so **use runtime mode when the request must follow the spec**.


## Not generated in any mode

| | Why |
|---|---|
| hydration of a union of OBJECTS with no `discriminator` | the document does not say which member a given object is, and choosing by structure would be a guess two overlapping branches could not settle. The union is emitted as an interface — useful as a type, and fine for a response — but a payload cannot be turned back into a member in ANY mode. The generator names the property at generation time instead of letting the request find out; add a `discriminator` and every mode resolves it. Pinned by `tests/Parity/UnhydratableUnionParityTest` |
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
