# Support matrix

[← back to the main README](README.md) · [performance](README.performance.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md)

What each generation mode enforces, and the handful of places where they differ.

**This table is measured, not maintained by hand.** Every row in the vocabulary section is a case in
`tests/Parity/ValidationParityTest`, which generates the same spec in all three modes, feeds each one a
VALID and an INVALID payload, and fails unless the three verdicts are identical *and* the two payloads get
different answers — a keyword that accepted everything would otherwise pass unnoticed. Every divergence
below is pinned by a named test that also states the reason; `NormalizationParityTest` additionally fails
if a divergence exists without a written explanation.

So: a row saying "yes" means a payload violating that keyword was rejected by that mode when this was last
run, not that someone believes it works.


## Validation vocabulary

**All three modes enforce every keyword below**, each row a case in the three-mode comparison. The mode
changes who reports it, not whether it is caught.

| Keyword | runtime | symfony | laravel |
|---|:---:|:---:|:---:|
| `type` (string, integer, number, boolean, array, object, null) | ✅ | ✅ | ✅ |
| `type` as a union, incl. `[T, null]` | ✅ | ✅ | ✅ |
| `enum` | ✅ | ✅ | ✅ |
| `const` | ✅ | ✅ | ✅ |
| `minLength` / `maxLength` | ✅ | ✅ | ✅ |
| `pattern` | ✅ | ✅ | ✅ |
| `minimum` / `maximum` | ✅ | ✅ | ✅ |
| `exclusiveMinimum` / `exclusiveMaximum` | ✅ | ✅ | ✅ |
| `multipleOf` | ✅ | ✅ | ✅ |
| `minItems` / `maxItems` | ✅ | ✅ | ✅ |
| `uniqueItems` | ✅ | ✅ | ✅ |
| `items` | ✅ | ✅ | ✅ |
| `prefixItems` | ✅ | ✅ | ✅ |
| `contains` / `minContains` / `maxContains` | ✅ | ✅ | ✅ |
| `unevaluatedItems: false` | ✅ | ✅ | ✅ |
| `minProperties` / `maxProperties` | ✅ | ✅ | ✅ |
| `additionalProperties` (schema) | ✅ | ✅ | ✅ |
| `patternProperties` | ✅ | ✅ | ✅ |
| `propertyNames` | ✅ | ✅ | ✅ |
| `dependentRequired` | ✅ | ✅ | ✅ |
| `dependentSchemas` | ✅ | ✅ | ✅ |
| `required` on a map-shaped object | ✅ | ✅ | ✅ |
| `unevaluatedProperties: false` on a map-shaped object | ✅ | ✅ | ✅ |
| `oneOf` | ✅ | ✅ | ✅ |
| `anyOf` | ✅ | ✅ | ✅ |
| `allOf` of scalar fragments | ✅ | ✅ | ✅ |
| `not` | ✅ | ✅ | ✅ |
| `if` / `then` / `else` | ✅ | ✅ | ✅ |
| composition nested inside `items` / `additionalProperties` / `not` | ✅ | ✅ | ✅ |
| a self-referential schema, at every depth | ✅ | ✅ | ✅ |
| mutually recursive schemas (`A → B → A`) | ✅ | ✅ | ✅ |
| `contentEncoding` / `contentMediaType` / `contentSchema` | ✅ | ✅ | ✅ |
| `format`: `email`, `idn-email` | ✅ | ✅ | ✅ |
| `format`: `uuid` | ✅ | ✅ | ✅ |
| `format`: `uri`, `iri` (absolute) | ✅ | ✅ | ✅ |
| `format`: `uri-reference`, `iri-reference` (relative allowed) | ✅ | ✅ | ✅ |
| `format`: `uri-template` (RFC 6570) | ✅ | ✅ | ✅ |
| `format`: `hostname`, `idn-hostname` | ✅ | ✅ | ✅ |
| `format`: `ipv4`, `ipv6` | ✅ | ✅ | ✅ |
| `format`: `date`, `time`, `duration` | ✅ | ✅ | ✅ |
| `format`: `byte` (base64) | ✅ | ✅ | ✅ |
| `format`: `regex` | ✅ | ✅ | ✅ |
| `format`: `json-pointer`, `relative-json-pointer` | ✅ | ✅ | ✅ |
| `format`: `int32`, `int64`, `uint32`, `uint64` bounds | ✅ | ✅ | ✅ |

`format: date-time` is enforced in all three too, but not to the same strictness — see the divergences.

An unknown or custom `format` (`uppercase`, `slug`, …) is an ANNOTATION, not an assertion: accepted in
every mode, exactly as the spec says. Do not expect it to validate anything.

Two keywords are enforced everywhere but measured outside the three-mode matrix, because a probe for
them needs more than one schema:

| Keyword | Measured in |
|---|---|
| `discriminator` (mapping, `allOf` variants, hydration to the mapped class) | `tests/Runtime/GenerateDtoCommandTest`, `tests/Symfony/SymfonyConstraintMatrixTest`, `tests/Parity/NormalizationParityTest`, and for laravel the discriminated-union hydration in `tests/Laravel/GenerateLaravelDtoTest` |
| `required` / `properties` on a schema that becomes a DTO | the class itself: a required property is a constructor parameter with no default, so no payload can omit it. Nested `required` is measured by the recursion cases above and `tests/Laravel/LaravelRulesEnforcementTest` |

### Which layer does it

Same vocabulary, three deliveries — and this is what the modes are actually choosing between:

| | How |
|---|---|
| runtime | one place: `DtoValidator` walks the schema it holds |
| symfony | `#[Assert\*]` attributes for what the constraint set can express, one generated `#[Assert\Callback]` for the rest |
| laravel | `rules()` for what Laravel's rule vocabulary can express, a generated `withValidator()` for the rest — the per-keyword split is the table in [README.laravel.md](README.laravel.md#two-layers-one-vocabulary) |

A keyword the framework has a native rule for keeps the FRAMEWORK's message, so your own translations
still apply. Everything the generated interpreter owns produces the same sentence in every mode, differing
only in how the subject is named — pinned by `tests/Parity/InterpreterMessageParityTest`.


## Divergences

Six, all deliberate, all pinned by a test that names the cause. Every one of them is Symfony mode's
serializer deciding before generated code gets a say, or a mode not holding the raw body.

| Behaviour | runtime | symfony | laravel | Why |
|---|:---:|:---:|:---:|---|
| `42.0` for `type: integer` (JSON Schema 2020-12 §6.1.1) | ✅ accepted | ❌ 422 | ✅ accepted | Symfony's serializer type-checks the `int` property before any generated constraint runs. The one conformance gap this mode cannot close from generated code — `ValidationParityTest::testAnIntegralFloatIsAnIntegerWhereverTheGeneratorOwnsTheCheck` |
| a loose `format: date-time` string (`"yesterday"`) | ✅ refused | ❌ accepted | ✅ refused | The property is a `DateTimeImmutable`, so the serializer parses the string first, and PHP's parser is generous. Runtime and laravel accept only the four patterns every mode agrees on (`GeneratedDtoInterface::DATE_TIME_FORMATS`) — `testALooseDateTimeStringIsRefusedWhereverTheGeneratorOwnsTheCheck` |
| a JSON array for a `type: object` property | ✅ refused | ❌ accepted | ✅ refused | The distinction lives in the RAW body: once decoded, `{"0":1,"1":2}` and `[1,2]` are the same PHP value. Runtime decodes the body itself, the generated Laravel FormRequest hands `withValidator()` the undecoded body; `#[MapRequestPayload]` denormalizes first — `testAJsonArrayIsRefusedForATypeObjectPropertyWhereverTheRawBodyIsReachable` |
| an OPTIONAL property sent as `null` when the schema is not `nullable` | ✅ refused | ❌ accepted | ✅ refused | An optional property's PHP type is nullable so it can default to `null`, and no `#[Assert\NotNull]` is emitted — `testAnOptionalPropertyIsNotNullable` |
| `additionalProperties: false` / `unevaluatedProperties: false` on a **DTO-shaped** schema | ❌ | ❌ | ✅ | Both other modes bind the payload into a typed object first and an undeclared key has nowhere to go. Laravel validates before hydration, over the raw payload — `testUnknownPayloadKeysAreDroppedBeforeValidationInBothModes` |
| an empty map on the wire | `{}` | `[]` | `{}` | `type: object` says object. Runtime and laravel cast maps to `stdClass`; Symfony's serializer turns any array into an array — `NormalizationParityTest` |

Everything else about the response shape is identical in all three, including `writeOnly` omission and
`readOnly` input being ignored — with one caveat for symfony: those two need serialization groups passed
by the caller (`['groups' => 'read']` / `'write'`), which runtime and laravel enforce unconditionally.


## Beyond validation

| | runtime | symfony | laravel |
|---|:---:|:---:|:---:|
| Typed immutable DTO | ✅ | ✅ | ✅ |
| Generated backed enums | ✅ | ✅ | ✅ |
| Temporal properties (`DateTimeImmutable` + the schema's own string form) | ✅ | ✅ | ✅ |
| Nested DTOs, lists of DTOs, maps | ✅ | ✅ | ✅ |
| Discriminated unions (interface + members) | ✅ | ✅ | ✅ |
| PATCH / partial updates | ✅ `UnsetValue` | ✅ `isXxxProvided()` via the setter | ✅ `isXxxProvided()` from the validated keys |
| `toArray()` / response shape | ✅ | serializer | ✅ |
| Uploaded files (`format: binary`) | ✅ | ✅ | ✅ |
| Validated before the controller body runs | you call the deserializer | ✅ `#[MapRequestPayload]` | ✅ the FormRequest is resolved first |
| Framework-native error envelope | aggregated exception | `ConstraintViolationList` → 422 | the framework's own 422 + error bag |
| Request binding: parameter sources (`path`/`query`/`header`/`cookie`) | ✅ | ❌ | ❌ |
| Request binding: `style` / `explode` / `allowReserved` / `allowEmptyValue` | ✅ | ❌ | ❌ |
| Request binding: multipart `encoding` | ✅ | ❌ | ❌ |
| Runtime dependency of the generated code | this package (or a vendored copy) | `symfony/validator` + `symfony/serializer` | none — ships with the framework |

The three ❌ rows have one cause: by the time a `#[MapRequestPayload]` argument or a `FormRequest` exists,
the framework has already parsed the request its own way. Those OpenAPI rules are about the request
itself, so **use runtime mode when the request must follow the spec**.


## Not generated in any mode

| | Why |
|---|---|
| `authorize()` / any policy | an OpenAPI document describes payload shape, not authorization |
| server, security scheme and callback objects | out of scope: this generates DTOs, not a router or an auth layer |
| a `spatie/laravel-data` class | possible as an opt-in fourth target later; the default stays first-party |
| subschema-local `$defs` (`#/components/schemas/Foo/$defs/Bar`) | top-level `components.schemas` / `$defs` are folded and supported; a nested `$defs` pointer is not — prefer top-level shared types |


## Reproducing this table

```bash
vendor/bin/phpunit --filter Parity     # the vocabulary rows and every divergence
vendor/bin/phpunit --filter Golden     # the emitted corpus, snapshotted per mode
vendor/bin/phpunit                     # everything
```

If a row here stops being true, one of those fails. That is the point of writing it down this way.
