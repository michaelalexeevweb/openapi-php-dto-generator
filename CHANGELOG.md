# Changelog

This file starts at 2.9.0. Notes for every earlier tag are the
[GitHub releases](https://github.com/michaelalexeevweb/openapi-php-dto-generator/releases).

## 2.11.0 — 2026-08-17

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
to 2.10.0 in all three modes.

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

