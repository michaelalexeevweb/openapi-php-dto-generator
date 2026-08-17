# OpenAPI PHP DTO Generator

[![MIT License](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/LICENSE)
[![CI](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)

**Generate PHP DTOs from OpenAPI and validate incoming HTTP requests against OpenAPI schema.**

Stop writing boilerplate PHP data transfer objects by hand. This library reads your OpenAPI 3.x YAML specification and automatically generates strictly-typed, immutable PHP 8.3 DTO classes. On top of that, it provides runtime services to **deserialize** Symfony `Request` objects into those DTOs, **validate HTTP requests** against the original OpenAPI schema rules (OpenAPI request validation), and **normalize** them back to arrays or JSON — all in one package.

## Features

- 🚀 **Code generation** — generate immutable PHP DTO classes directly from OpenAPI 3.0 / 3.1 YAML specs
- 🎯 **Four generation modes** — **[runtime](README.runtime.md)** (DTOs backed by this library's validator/normalizer/deserializer), **[symfony](README.symfony.md)** (plain DTOs decorated with Symfony `#[Assert\*]` / `#[SerializedName]` / `#[Groups]` attributes), **[laravel](README.laravel.md)** (a plain DTO plus a `FormRequest` carrying `rules()` — nothing to install beyond the framework) or **[laravel-data](README.laravel-data.md)** (one `spatie/laravel-data` class per schema, with `Optional` for presence)
- ✅ **OpenAPI request validation** — validate HTTP requests against OpenAPI constraints (required fields, types, enums, formats, etc.)
- 🔄 **Normalization** — convert DTOs to plain arrays or JSON, with or without validation
- 📦 **Symfony Request support** — deserialize Symfony `Request` objects directly into typed PHP DTOs
- 🔌 **Framework-agnostic (PSR-7)** — deserialize any PSR-7 `ServerRequestInterface` via `DtoDeserializerPsr7` (Slim, Mezzio, Laminas, Yii3, …); Symfony `Request` covers Symfony + Laravel
- 🔒 **Immutable by design** — runtime-mode DTOs are read-only value objects; in Symfony mode the required half is `readonly` and the optional half has setters, which is what powers `isXxxProvided()`
- ⚡ **Supports OpenAPI 3.0.x and 3.1.x**

## Table of Contents

- [Installation](#installation)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Generate DTOs](#generate-dto-classes-from-yaml-openapi-spec)
- [Generation Modes](#generation-modes)
  - [Runtime mode guide](README.runtime.md) — request binding, presence tracking, PSR-7
  - [Symfony mode guide](README.symfony.md) — attribute mapping, serialization groups, error codes
  - [Laravel mode guide](README.laravel.md) — FormRequest, rules(), what the interpreter adds
  - [laravel-data mode guide](README.laravel-data.md) — `Data` classes, `Optional` presence, morph unions
- [Support matrix](README.support-matrix.md) — every keyword per mode, the eleven divergences, what is not generated at all
- [Performance](README.performance.md) — bind / validate / normalize per mode, measured, with the benchmark to re-run it
- [Validation Notes](#validation-notes)
- [Upgrading](#upgrading)

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.12.0
```

## Requirements

- PHP 8.3+
- Symfony 7.4 components (`console`, `http-foundation`, `mime`, `yaml`)

## Quick Start

1. **Generate DTOs** from your OpenAPI YAML spec
2. **Deserialize** and **validate** an incoming HTTP request into a generated DTO
3. **Validate** and **normalize** the DTO for response

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use Symfony\Component\HttpFoundation\Request;
use YourApp\Generated\UserPostRequest; // generated DTO from OpenAPI spec
use YourApp\Generated\UserViewResponse; // generated DTO from OpenAPI spec

$deserializer = new DtoDeserializer();
$normalizer   = new DtoNormalizer();

/** @var Request $request */
// request: deserialize -> validate
$requestDto = $deserializer->deserialize($request, UserPostRequest::class);

// response: validate -> normalize
$responseData = $normalizer->validateAndNormalizeToArray($requestDto);
// response: normalize without validation for faster response
$responseData = $normalizer->toArray(new UserViewResponse(name: 'John', surname: 'Doe'));
```

## Usage

### Add script in your project `composer.json`

```json
{
  "scripts": {
    "openapi:generate-dto": "php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto"
  }
}
```

### Generate DTO classes from YAML OpenAPI spec

**Default — use the runtime services straight from the installed package.** Omit the
`--dto-generator-*` options: the generated DTOs reference the runtime classes from
`vendor/` (`OpenapiPhpDtoGenerator\Contract\…`), so nothing is copied and updates come
through `composer update`:

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test
```

**Optional — vendor a private copy of the runtime services** into your project (e.g. to
commit them or decouple from the package). Pass `--dto-generator-directory`; the generated
DTOs then reference that copied namespace instead of `vendor/`:

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test \
  --dto-generator-directory=Common \
  --dto-generator-namespace=Generated\\Common
```

Parameters:

| Option | Alias | Required | Description |
|---|---|---|---|
| `--file` | `-f` | ✅ | Path to OpenAPI spec file (YAML or JSON) |
| `--directory` | `-d` | ✅ | Output directory for generated DTOs |
| `--namespace` | | | Explicit DTO namespace (derived from `--directory` if omitted) |
| `--dto-generator-directory` | | | **Omit** to use the runtime services from `vendor/` (no copy — the default). Pass it to copy them into the given directory instead; the flag without a value defaults to `Common`. |
| `--dto-generator-namespace` | | | Namespace for the copied runtime services. Only has effect together with `--dto-generator-directory`. |
| `--attributes` | | | Generation mode: `runtime` (default — DTOs use this library's runtime), `symfony` (DTOs decorated with Symfony Validator/Serializer attributes) or `laravel` (a plain DTO plus a `FormRequest` with `rules()`). See [Generation Modes](#generation-modes). |
| `--with-psr7` | | | Also copy the PSR-7 deserializer (`DtoDeserializerPsr7`) when vendoring the runtime via `--dto-generator-directory`. Requires `symfony/psr-http-message-bridge` in the consuming project. |
| `--ref` | | | Explicit output directory for an external `$ref` spec file **or directory**: `<refFileOrDir>=<directory>`. A directory key maps every ref'd file inside it. Repeatable. Requires a matching `--ref-namespace`. Unmatched ref files are ignored. |
| `--ref-namespace` | | | Explicit namespace for an external `$ref` spec file **or directory**: `<refFileOrDir>=<namespace>`. Repeatable. Requires a matching `--ref`. |

## Generation Modes

The generator emits DTOs in one of four modes, selected with `--attributes` (default: `runtime`).
All four enforce the same OpenAPI vocabulary on a payload — they differ in what surrounds it.

| | **[Runtime](README.runtime.md)** (default) | **[Symfony](README.symfony.md)** (`--attributes=symfony`) | **[Laravel](README.laravel.md)** (`--attributes=laravel`) | **[laravel-data](README.laravel-data.md)** (`--attributes=laravel-data`) |
|---|---|---|---|---|
| Generated class | `implements GeneratedDtoInterface`, getters, metadata methods | plain class with getters, `#[Assert\*]` attributes | plain class with getters, `rules()`, `fromValidated()`, plus a `FormRequest` for every request payload | one `Data` subclass: promoted `public readonly`, `rules()`, `withValidator()` |
| Depends on | this package (or a vendored copy of its services) | `symfony/validator` + `symfony/serializer` | nothing to install — `FormRequest` and the validator ship with Laravel | `spatie/laravel-data` |
| Validation runs in | `DtoValidator` | Symfony constraints + a generated `#[Assert\Callback]` | Laravel rules + a generated `withValidator()` | the same rules and the same `withValidator()`, run by laravel-data |
| Errors come out as | one aggregated exception | `ConstraintViolationList` (422 through `#[MapRequestPayload]`) | the framework's own 422 with its error bag | the framework's own 422 with its error bag |
| Validated before the controller runs | you call the deserializer | yes, via `#[MapRequestPayload]` | yes, the FormRequest is resolved first | yes, on `Data::from($request)` |
| Request binding | done here: sources, `style`/`explode`, `allowReserved`, multipart Encoding | done by Symfony, so those OpenAPI rules do not apply | done by Laravel, same limitation | done by Laravel, same limitation |
| PATCH / partial updates | yes — `UnsetValue` presence tracking | yes — `isXxxProvided()`, recorded by the setter | yes — `isXxxProvided()`, from the validated keys | yes — `Optional`, the property's own type |
| `readOnly` / `writeOnly` | enforced | serialization groups you have to pass | enforced | `writeOnly` enforced (`#[Hidden]`), `readOnly` input echoed back |
| `additionalProperties: false` on a DTO-shaped schema | not enforced (the payload is bound first) | not enforced | **enforced** — the interpreter sees the raw payload | **enforced** — same interpreter |

Rule of thumb: **runtime** when the request itself must follow the spec (parameter styles, partial
updates, one library end to end); **symfony** or **laravel** when you want plain DTOs your framework
owns, validated by the framework, with errors in the shape it already speaks; **laravel-data** when your
application already runs that package and you want generated classes to match the ones you write.

Each mode has its own guide — what it can do, how to wire it, where it stops:

- **[README.runtime.md](README.runtime.md)**
- **[README.symfony.md](README.symfony.md)**
- **[README.laravel.md](README.laravel.md)**
- **[README.laravel-data.md](README.laravel-data.md)**

For the keyword-by-keyword answer — what every mode enforces, the eleven places they differ and why, and
what is not generated in any of them — see the **[support matrix](README.support-matrix.md)**. It is
derived from the parity suites, so a row that stops being true fails a test.


## Validation Notes

A few behaviours worth knowing when validating against the schema:

- **`type: array` means a JSON array (list).** A value passes only when it is a PHP list (sequential integer keys from `0`). An associative array is treated as a JSON object, not an array — so a getter returning `array_filter(...)` (which may leave non-contiguous keys) should wrap the result in `array_values(...)`.
- **`oneOf` / `anyOf` pick the first matching branch.** Branches are tried in declaration order and the first one that validates wins. When several branches accept the same input (e.g. `oneOf: [string, integer]` given `"123"`), order your schema branches from most specific to least specific.
- **`unevaluatedProperties` / `unevaluatedItems` (JSON Schema 2019-09/2020-12, OpenAPI 3.1).** Like `additionalProperties: false` / a suffix `items`, but annotation-aware: a key or index counts as "evaluated" when it is covered by this schema *or* by any in-place applicator that actually applies (`allOf`, a passing `anyOf`/`oneOf` branch, the taken `if`/`then`/`else` arm, a triggered `dependentSchemas`) — recursively, to any nesting depth. Only what is left over is checked. They are enforced on the non-materialized paths (raw lists, inline maps); a composed object with named properties is materialized into a dedicated nested DTO where unknown keys are impossible by construction.
- **`contentEncoding` / `contentMediaType` / `contentSchema` (JSON Schema 2019-09/2020-12, OpenAPI 3.1).** Enforced as assertions on strings: the value must decode under `contentEncoding` (`base64`, `base16`, `quoted-printable`, `7bit`/`8bit`/`binary`; an unknown codec such as `base32` is accepted leniently), the decoded bytes must parse when `contentMediaType` is a JSON type (`application/json` or any `+json`), and the parsed document must satisfy `contentSchema`.
- **`$defs` (JSON Schema) is folded into `components.schemas`.** A `$defs` map (in the root document or an external file) and any `#/$defs/X` pointer — local `#/$defs/X` or cross-file `other.yaml#/$defs/X` — are normalized to `components.schemas` at load time, so `$defs`-style specs generate the same as `components`-style ones. (Subschema-local `$defs`, e.g. `#/components/schemas/Foo/$defs/Bar`, is not folded — prefer top-level `components.schemas`/`$defs` for shared types.)
- **Parameters serialized via `content`.** A parameter that uses `content: {application/json: {schema}}` instead of a plain `schema` is supported: the schema is extracted and its JSON-string value is decoded before validation and casting (malformed JSON is a clear error).
- **`type: integer` accepts a number with a zero fractional part** (JSON Schema 2020-12 §6.1.1), so a payload of `42.0` is a valid integer while `42.5` is not. Runtime and Laravel mode follow this end to end, hydration included. Symfony mode cannot: its serializer type-checks the `int` property before any generated constraint runs, and rejects `42.0` with a denormalization error.
- **`type: object` refuses a JSON array.** `{"tags":[1,2]}` where the schema says object is rejected — it used to be accepted and read as a map keyed `0..n-1`. The distinction lives in the RAW body: once PHP decodes it, a JSON object whose keys are exactly `0..n-1` and a JSON array are the same value. So the check runs where the raw body is still reachable — the runtime deserializer decodes it itself, and the generated Laravel `FormRequest` hands `withValidator()` the undecoded body. Symfony mode cannot: `#[MapRequestPayload]` denormalizes first, so there it stays accepted.
- **Error messages are the same sentence in every mode**, differing only in how the subject is named:

  | Mode | Sentence |
  |---|---|
  | runtime | `param "tags" must contain unique items` |
  | symfony | `field "tags" must contain unique items` |
  | laravel | `tags must contain unique items` — keyed by `tags` in the error bag |

  This holds for every keyword the interpreter owns (`oneOf`, `anyOf`, `not`, `contains`, `if`/`then`, `propertyNames`, `unevaluated*`, …) and is pinned by `tests/Parity/InterpreterMessageParityTest`. A keyword the framework has its own rule for keeps the FRAMEWORK's message — `exclusiveMinimum` reads *"This value should be greater than 3."* in Symfony mode and `multipleOf` resolves `validation.multiple_of` in Laravel mode — so your own translations still apply.
- **Extended string formats.** Beyond the common set, these are validated: `uri-reference`/`iri-reference`, `uri-template` (RFC 6570), `idn-hostname`, `relative-json-pointer`. Unknown formats are accepted (per spec, an unknown `format` is an annotation, not an assertion).

## Upgrading

**2.11.0 → 2.12.0** adds one method and changes nothing else: generated output is byte-identical in all
four modes, and `DtoDeserializer::deserializeValue()` deserializes a single already-decoded JSON value so
a batch endpoint can report per-element errors. The one caveat: the method was added to
`DtoDeserializerInterface`, so a class of YOUR OWN implementing that interface must add it — type hints on
the interface are unaffected. See the [CHANGELOG](CHANGELOG.md).

The library itself is a drop-in replacement: **DTOs generated by 2.8.x keep working unchanged against
2.10.0 services** (measured on 55 specs — same accept/reject verdicts, same normalized output; the two
metadata methods added in 2.9.0 are simply absent on old DTOs and the services fall back).

**2.9.0 → 2.10.0** adds a mode and changes nothing else for existing users: runtime-mode output is
byte-identical, Symfony-mode output differs in two lines of the emitted interpreter, and the whole 2.9.0
test suite passes against 2.10.0 services with one intentional difference — `type: integer` now accepts
`42.0`, which the spec always called an integer. Code that string-matches an error message should read
the three message changes in the [CHANGELOG](CHANGELOG.md).

What changes is the code the generator EMITS — a bare `type: object` property becomes a map, a named
scalar schema becomes a type alias, and Symfony-mode DTOs expose accessors instead of public
properties. Every change, what it breaks and what to do about it:
**[CHANGELOG.md](CHANGELOG.md#upgrading-from-28x)**.
