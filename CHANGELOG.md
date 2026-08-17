# Changelog

This file starts at 2.9.0. Notes for every earlier tag are the
[GitHub releases](https://github.com/michaelalexeevweb/openapi-php-dto-generator/releases).

## 2.12.0 — 2026-08-17

- add `deserializeValue()`
- deserialize one decoded JSON value
- per-element errors for batch endpoints
- new method on `DtoDeserializerInterface`

`deserializeCollection()` is all-or-nothing: it aggregates every element's error into one exception, so
a single malformed element fails the whole body. `deserializeValue($data, $type, $path)` exposes the
per-element cast on its own — no `Request`, one already-decoded JSON value in, one DTO (or scalar, enum,
date) out, discriminator resolution included. A batch endpoint loops over the decoded body itself,
catches each `RuntimeException` and answers "element 3 was rejected, the rest were accepted". `$path`
names the value in the error message, so pass the element's position; it defaults to `value`. Example:
[README.runtime.md](README.runtime.md#batch-endpoints-accepting-the-good-elements-reporting-the-bad-ones).

**Breaking for implementors of `DtoDeserializerInterface`.** The method was added to the interface, not
only to `DtoDeserializer`, so a class of your own implementing that interface must add it. Consumers of
the interface (type hints, DI) are unaffected, and generated DTOs are unchanged — output is byte-identical
to 2.11.0 in all four modes.

## 2.11.0 — 2026-08-13

- add laravel-data generation mode
- one Data class per schema
- Optional for presence, no emitted flags
- null and Optional as independent types
- reuse the laravel rule translator and interpreter
- no MergeValidationRules: rules() is the truth
- morph base for discriminated unions
- #[Hidden] for writeOnly
- schema formats on the date cast
- suppress inferred nested rules
- one message per mistake in laravel-data mode
- 39% off the laravel-data validate step
- mode list is data, not three columns
- fourth column in every parity suite
- fourth golden corpus snapshot
- guard every emitted attribute resolves
- laravel-data container without testbench
- add README.laravel-data.md
- fourth column in the benchmark
- list every mode in the --attributes error
- warn on an undiscriminated object union
- accept null beside a union
- fix `mixed` inside a union type
- map a discriminator's wire name
- enforce a self-recursive root schema
- drop an unused generated import
- survive a schema named like an import
- warn on a schema named like a used class
- pin what a schema `default` normalizes to

`--attributes=laravel-data` emits ONE `spatie/laravel-data` class per schema instead of the FormRequest +
DTO pair `--attributes=laravel` emits. It is the only mode whose generated code needs a third-party
package, which is why it is opt-in and why first-party Laravel stays the default. What it buys is presence
as a language-level fact: an optional property is `string|Optional`, an unprovided one IS an `Optional`,
and laravel-data omits it from `toArray()` — no flag array, no `fromValidated()` factory, no hydration code
of ours. `null` and `Optional` are separate union members, so `nullable` follows the document alone. The
rule translator and the interpreter are the SAME code laravel mode uses, so the messages are identical:
[README.laravel-data.md](README.laravel-data.md).

Discriminated unions are the one place the emitted class SHAPE differs between modes. laravel-data cannot
hydrate an interface, so the base is an abstract `Data` implementing `PropertyMorphableData`, its `morph()`
maps the discriminator value to a member, and members `extends` it and forward the discriminator. An
unmapped value comes back as a 422 rather than an exception to translate.

Two decisions are measurements, not preferences. The class carries no `#[MergeValidationRules]`: with it,
laravel-data's inferred rules are merged into ours, duplicating them and prepending a `nullable` the schema
never asked for. And a nested-`Data` property carries `#[WithoutValidation]`: overriding `rules()` does not
stop laravel-data injecting its own nested rule resolution, which reported one missing nested key twice —
once as `validation.present`, once as the interpreter's `tags[0].id is required`. Removing it also took 39%
off the validate step and 45% off `from($request)`.

A property whose schema is a union of OBJECTS with no `discriminator` is now named at GENERATION time.
Nothing can hydrate one — the document does not say which member a payload is — and every mode used to
find that out at request time, late and differently: `Unsupported type: Shape` in runtime mode, a
`NotNormalizableValueException` in symfony, and in the two Laravel modes a `Call to undefined method
Shape::fromValidated()` and a `TypeError`, both of which read as bugs in the generated code rather than as
a gap in the document. Generation still succeeds — the interface is a useful type, and a union referenced
only in a response is never hydrated — and the warning names the property and the remedy. The demo corpus
has one such property, which is how ordinary the shape turns out to be.

`nullable: true` NEXT TO a `oneOf`/`anyOf` now means what it says. It is the same statement as spelling a
`{type: null}` variant INSIDE the union, but only the spelled form reached the emitted interpreter — so a
document written the first way had its own `null` refused with "does not match any oneOf branch (expected
integer or string, got null)" in symfony, laravel and laravel-data mode. Runtime mode reads the schema
directly and always accepted it, so three modes were wrong about a value the document explicitly allows.
Both spellings are now held to one verdict by `ValidationParityTest`.

A property with NO type in its schema — an empty schema, or one carrying only a description or an
extension keyword — no longer breaks RUNTIME mode. It resolves to `mixed`, `mixed` cannot take part in a
union or be marked nullable, and the emitted class said `mixed|UnsetValue|null` and `?mixed`: two
compile-time fatals, so the file could not be loaded at all. `mixed` already admits the sentinel and null,
so it now stands alone and presence tracking is unaffected. laravel-data mode has the same constraint from
the other side — it is the one property shape with no `|Optional` to mark absence with, so an unprovided
one is echoed as `null`, a divergence now declared in the parity suite.

A `discriminator` whose `propertyName` is not already a PHP identifier — `pet_type`, the name OpenAPI's own
Pet example uses — works in laravel-data mode now. The morph base reads the discriminator BEFORE there is
an object to read it from, and `DataMorphClassResolver` looks the value up by the property name and by its
INPUT-MAPPED name; the base carried `#[PropertyForMorph]` but no `#[MapName]`, so neither name matched the
payload and a document the other three modes hydrate came back as `validation.required` on a key nobody
sent. `NormalizationParityTest` now runs the union under both spellings, and `MorphDiscriminatorTest`
every case twice.

A schema that refers to ITSELF from the root class is enforced at every depth in the two Laravel modes.
Both halves of the pair had a hole exactly where they met: the flat rules cannot expand a cycle, so no
`children.*` path was emitted, while the interpreter treated the first `$ref` back to the root as a fresh
class — expanded one level and PRUNED of everything the rules were assumed to cover. Measured: a child
violating `minimum: 1` was ACCEPTED, and a child sending a string for an integer surfaced as a `TypeError`
from the constructor rather than a 422. The cycle guard is now seeded with the owner class, so the root
folds like any other recursive class. The recursion suite only ever reached such a schema through a
non-recursive root, which is why it saw none of this; it now covers both entries.

A document may call a schema `Data`, `Optional`, `Request`, `Validator` or `Container`, and laravel-data
mode imports classes with all five of those short names. PHP then resolved the clash two ways, neither of
them the document's: the file DECLARING that class did not load at all (`Cannot redeclare X\Data,
previously declared as local import`), and in a SIBLING file the `use` quietly won over the
same-namespace class, so a property typed `Request` was Illuminate's and the payload meant for it a
`TypeError`. Every class this mode needs is now spelled fully qualified when the document has taken its
name, and imported only when it has not — `EmissionEdgeCasesTest` drives all five through the package.

An eleventh divergence was there all along and nothing measured it: an unprovided optional property is
left out of `toArray()` by runtime, laravel and laravel-data, and comes back as `null` from Symfony —
as the schema's `default` where the document declared one, because the constructor default IS that
property's value there. `isXxxProvided()` still answers the question on the object. Pinned by
`NormalizationParityTest` and listed in the support matrix, which had claimed "everything else about the
response shape is identical in all four".

The same clash in the OTHER modes is now named at generation time instead of being discovered at
request time. Only laravel-data can resolve it from the import list; a generator that carries a PHP type
as a SHORT name cannot tell `format: binary` from a schema the document called `UploadedFile`, and no
import list fixes that. So each mode declares the names its emitted code already uses — `UnsetValue`,
`GeneratedDtoInterface`, `JsonException`, `Stringable` in runtime; `Assert`, `Ignore`, `SerializedName`,
`ExecutionContextInterface` in symfony; `Validator`, `Rule`, `FormRequest`, `stdClass` in laravel;
`DateTimeImmutable` and `UploadedFile` in all four — and a schema of that name gets a warning naming the
clash and the remedy. Generation still succeeds, as with the undiscriminated-union warning above.

The mode list stopped being three hardcoded columns. `tests/GenerationMode` is data, the parity suites
iterate it, and a `match` over modes has no `default` arm anywhere — so a mode is either measured in every
case or it fails loudly. Adding the fourth column surfaced one thing the old shape had hidden:
`additionalProperties: false` on a DTO-shaped schema is enforced by the two rule-based modes and invisible
to the two that hydrate first.

### Upgrading from 2.10.0

For the new mode, `composer require spatie/laravel-data` — the generated classes need `^4.0`.

One change reaches LARAVEL mode as well, because both modes read the same predicate for "is this property
nullable per the document". `$property['nullable']` conflates schema-nullable with optional (the walker sets
it for every optional property so the other modes have somewhere to put "absent"), and the keyword check
that replaced it cannot see a nullable `$ref` — its constraint map carries no `type`. So for a REQUIRED
nullable property written as `$ref` or `oneOf: [$ref, {type: null}]`, laravel mode now emits `nullable`
where it emitted nothing:

    'child' => ['present']              // 2.10.0
    'child' => ['present', 'nullable']  // 2.11.0

`nullable` only tells the validator to skip the remaining rules when the value IS null, which the schema
says is allowed — so this widens nothing that was refused before. The demo corpus contains no property of
that shape, which is why every golden snapshot but the new mode's is unchanged byte for byte. Regenerate
anyway if your document has one.

The recursion fix reaches LARAVEL mode the same way, and it NARROWS what is accepted: a document whose
ROOT schema refers to itself gets an interpreter fold it did not have, so payloads that used to slip
through below the first level are now 422s. That is the point — they violate the schema — but a client
relying on the old leniency will notice. The demo corpus has no self-recursive root either, so the laravel
snapshot is unchanged byte for byte here too.

## 2.10.0 — 2026-08-06

- add laravel generation mode
- emit FormRequest per request payload
- rules() for illuminate/validation
- enforce composition via withValidator()
- presence from validated() keys
- third column in parity suites
- third golden corpus snapshot
- add README.laravel.md
- add README.support-matrix.md and README.performance.md
- add bin/benchmark
- accept `42.0` for `type: integer`
- name accepted types on a union mismatch
- pin message wording across modes
- name the path in nested deserialization errors
- rename the parity suites for three modes
- state the Laravel 11 floor
- refuse a JSON array for `type: object`
- keep the payload of an object that only constrains its keys
- absolute `format: uri` / `iri`, real `uri-template` grammar
- enforce a recursive schema at every depth in laravel mode
- optional is not nullable in laravel mode
- one message per mistake in laravel mode
- FormRequest for a `$ref`-ed request body

`--attributes=laravel` emits a plain DTO carrying `rules()` plus a `FormRequest` that Laravel resolves
and validates before the controller body runs. Nothing to install: `FormRequest` and
`illuminate/validation` ship with the framework, and the generated code has no runtime dependency on
this package. What Laravel's rule vocabulary cannot express — composition, conditionals, `contains`,
`unevaluated*`, `content*`, `propertyNames`, `discriminator` — is enforced by the same interpreter
Symfony mode uses, entered from `withValidator()`; a schema that needs nothing beyond rules gets no
interpreter at all. Details and the full rule mapping: [README.laravel.md](README.laravel.md).

Two conformance fixes shared by all modes. `type: integer` now matches a number with a zero fractional
part, as JSON Schema 2020-12 §6.1.1 says it must: `42.0` is accepted (and hydrated into an `int`),
`42.5` still rejected. And a union that gates every branch out by type no longer answers with a bare
"does not match any oneOf branch" — it names what it would take: `(expected integer or string, got
boolean)`. `tests/Parity/InterpreterMessageParityTest` now pins interpreter-owned messages to the same
sentence in all three modes.

Runtime mode also names the path of a nested failure. A nested DTO is deserialized by its own pass, so
its errors carried the bare key: a payload missing `discriminator.id` reported `Required parameter "id"
not found in request.`, which reads as the ROOT object missing an `id` it may not even declare. Messages
now come out as `Required parameter "discriminator.id" not found in request.` and
`param "tags.0.name" expects string, got int`, at any depth.

A JSON array is no longer accepted for a `type: object` property. It used to be read as a map keyed
`0..n-1` — in every mode. The distinction exists only in the raw body (`{"0":1,"1":2}` and `[1,2]` decode
to the same PHP list), so the check sits where the raw body is reachable: the runtime deserializer, and
`withValidator(Validator $validator, ?string $rawJson = null)` in Laravel mode, which the generated
FormRequest now feeds `$this->getContent()`. Symfony mode still accepts it — the serializer denormalizes
before any constraint runs — and that boundary is pinned by a test.

An object that constrains only its KEYS keeps its payload. `{type: object, dependentRequired: {…}}` with
no `properties` — and the same for a lone `required`, `propertyNames` or `unevaluatedProperties` — used to
be materialized into a DTO class with no properties, which accepted the whole payload and returned `[]`.
Those four keywords name keys without declaring a schema for any of them, so nothing a DTO could hold:
the schema now stays a map (`array<string, mixed>`) and the keyword is enforced over it. **All three
modes.**

Two format checks were wrong in the emitted interpreter, so Symfony and Laravel mode accepted values the
runtime validator had always refused. `format: uri` and `format: iri` are ABSOLUTE — only the
`*-reference` forms take a relative value — and were both mapped to the reference check, accepting
`/rel/path`. And `uri-template` compared `preg_match(...) !== false`, which is true for zero matches as
well as one, so the brace check asserted nothing and `/a{unclosed` passed. Both now match runtime.

Laravel mode got three correctness fixes of its own. A recursive schema is enforced at every depth: the
fold is emitted once into `OPENAPI_RECURSIVE_SCHEMAS` and re-entered through a marker, where before the
walk stopped at the first turn of the cycle and a child violating `minimum` was accepted. An OPTIONAL
property is no longer treated as nullable — `sometimes` covers the absent key, and a key carrying `null`
that the schema never marked `nullable` is refused, as runtime mode does. And one mistake now produces one
message: the interpreter schema is pruned down to what the rules did not already take.

A request body written as `$ref: '#/components/schemas/X'` now gets a FormRequest. Only INLINE bodies were
recorded before, so the most idiomatic way to write a spec produced the one shape the mode exists for and
then withheld it.

### Upgrading from 2.9.0

**Regenerate.** Unlike earlier releases this one can change emitted output in ANY mode, not just the new
one, and two of the changes are visible in your own code:

- **types change** where a schema constrains only its keys: a property that was a generated nested class
  (`?FooBag`) becomes `?array`, and the class is no longer emitted. If you type-hinted it or called
  methods on it, that code needs updating — it was returning an empty object before;
- **payloads that used to pass may now be rejected**: a relative value for `format: uri`/`iri`, a
  malformed `uri-template`, and in Laravel mode `null` for an optional non-nullable property or a
  violation nested inside a recursive schema. All four were conformance gaps, so a payload that starts
  failing was always invalid against the document;
- **error messages changed** in three places (the union mismatch, nested deserialization paths, and one
  message per mistake in Laravel mode). Only code that STRING-MATCHES a message is affected;
- `42.0` is now accepted for `type: integer` in runtime and Laravel mode. Symfony mode still rejects it —
  the serializer decides before any constraint runs.

In Laravel mode the generated constructor no longer takes a `$providedOpenApiKeys` parameter: it takes the
schema's properties and nothing else, and `fromValidated()` — the only hydrator — records presence itself.
Hand-built instances keep working and report nothing as provided.

What each mode enforces, and the six places they differ, is now written down and generated from the test
suites: [README.support-matrix.md](README.support-matrix.md). Timings, with the benchmark to re-run them:
[README.performance.md](README.performance.md).

## 2.9.0 — 2026-08-05

- support matrix, label
- support allowReserved, allowEmptyValue
- support in: querystring
- support additionalOperations, QUERY method
- generate webhooks, callbacks
- dereference component requestBodies, responses
- fold Encoding Object into body
- refuse Swagger 2.0 clearly
- symfony mode: presence tracking
- symfony mode: serialization groups
- symfony mode: temporal string getters
- symfony mode: discriminated union interfaces
- keep free-form object payloads
- named scalar schemas become types
- suffix reserved class names
- distinguish case-only sibling keys
- refuse contradictory enum members
- fix nullable map item crash
- fix list-of-maps deserialization
- validate unevaluatedProperties on DTO values
- surface defects inside DTO values
- lazy raw query parsing
- raw query matches parse_str
- support uint32, uint64 formats
- golden corpus snapshot tests
- runtime vs symfony parity suites
- split README per mode
- add upgrade notes

### Upgrading from 2.8.x

The library is a drop-in replacement: **DTOs generated by 2.8.x keep working unchanged against 2.9.0
services** — measured on 55 specs, same accept/reject verdicts and the same normalized output. The two
metadata methods added here are simply absent on older DTOs and the services fall back.

What changes is the code the generator EMITS, so read this before regenerating.

**Runtime mode**

- `matrix` / `label` path parameters now bind. 2.8.x rejected them outright (`param "ids" expects
  array, got string`). This one comes from the services, so it applies before you regenerate.
- A bare `type: object` property is `array<string, mixed>` instead of a synthesized class that
  dropped the payload. `$dto->getMeta()->toArray()` becomes
  `Call to a member function toArray() on array` — use `$dto->getMeta()`.
- A named scalar schema (`Uuid: {type: string, format: uuid}`) is a type alias: no class is generated
  and the property is `string` with the alias's own `format` / `minLength` / `enum` as constraints.
  Referencing the class gives `Class "…\Uuid" not found`. Such a property used to reject every
  request (`Cannot deserialize nested DTO … from non-array value`), so no working code depended on
  it.
- **Response bodies change** for those fields: they used to serialize as `[]` with the data dropped,
  now they carry the object — `{}` when empty, as `type: object` says.
- A schema named after a PHP keyword (`Parent`, `Self`, `Int`, `List`, `Match`, `Readonly`, `Static`)
  gets a `Schema` suffix. Those files could not be loaded at all before.
- Two keys differing only in case (`name` + `NAme`) get distinct accessors (`getNAme2()`), because
  PHP method names are case-insensitive. The wire names are unchanged.
- `type: integer, enum: [1, "two"]` is refused at generation, naming the schema and the value. 2.8.18
  refused it too, with a vaguer message.

**Symfony mode — needs code changes**

The shape changed so that `PATCH` can tell "field absent" from "field sent as null"
(see [README.symfony.md](README.symfony.md)):

- properties are private with accessors: `$dto->name` becomes `$dto->getName()`;
- a temporal property reads as the string the schema declares (`getAt(): string`), with the
  `DateTimeImmutable` available from `getAtAsDateTime()`;
- optional properties gain `isXxxProvided()`;
- the class is no longer `final readonly` — `readonly` cannot be written twice, which is what the
  optional-half setters need.

**Recommended order**

1. `composer update` alone, keeping the old generated code: behaviour is unchanged and
   `matrix`/`label` start working.
2. Regenerate into a scratch directory and diff it against the committed output.
3. Fix the call sites, then commit the regenerated code.

