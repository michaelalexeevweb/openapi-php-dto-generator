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
- 🎯 **Two generation modes** — **[runtime](README.runtime.md)** (DTOs backed by this library's validator/normalizer/deserializer) or **[symfony](README.symfony.md)** (plain DTOs decorated with Symfony `#[Assert\*]` / `#[SerializedName]` / `#[Groups]` attributes, validated and (de)serialized by Symfony itself)
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
- [Generation Modes: Runtime vs Symfony](#generation-modes-runtime-vs-symfony)
  - [Runtime mode guide](README.runtime.md) — request binding, presence tracking, PSR-7 / Laravel
  - [Symfony mode guide](README.symfony.md) — attribute mapping, serialization groups, error codes
- [Validation Notes](#validation-notes)
- [Upgrading](#upgrading)

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.9.0
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
| `--attributes` | | | Generation mode: `runtime` (default — DTOs use this library's runtime) or `symfony` (DTOs decorated with Symfony Validator/Serializer attributes). See [Generation Modes](#generation-modes-runtime-vs-symfony). |
| `--with-psr7` | | | Also copy the PSR-7 deserializer (`DtoDeserializerPsr7`) when vendoring the runtime via `--dto-generator-directory`. Requires `symfony/psr-http-message-bridge` in the consuming project. |
| `--ref` | | | Explicit output directory for an external `$ref` spec file **or directory**: `<refFileOrDir>=<directory>`. A directory key maps every ref'd file inside it. Repeatable. Requires a matching `--ref-namespace`. Unmatched ref files are ignored. |
| `--ref-namespace` | | | Explicit namespace for an external `$ref` spec file **or directory**: `<refFileOrDir>=<namespace>`. Repeatable. Requires a matching `--ref`. |

## Generation Modes: Runtime vs Symfony

The generator emits DTOs in one of two modes, selected with `--attributes` (default: `runtime`).
Both enforce the same OpenAPI vocabulary on a payload — they differ in what surrounds it.

| | **[Runtime](README.runtime.md)** (default) | **[Symfony](README.symfony.md)** (`--attributes=symfony`) |
|---|---|---|
| Generated class | `implements GeneratedDtoInterface`, getters, metadata methods | plain class with getters, `#[Assert\*]` attributes, no library dependency |
| Depends on | this package (or a vendored copy of its services) | `symfony/validator` + `symfony/serializer`, nothing of ours |
| Validation runs in | `DtoValidator` | Symfony constraints + a generated `#[Assert\Callback]` |
| Errors come out as | one aggregated exception | `ConstraintViolationList` (422 through `#[MapRequestPayload]`) |
| Request binding | done here: sources, `style`/`explode`, `allowReserved`, multipart Encoding | done by Symfony, so those OpenAPI rules do not apply |
| PATCH / partial updates | yes — `UnsetValue` presence tracking | yes — `isXxxProvided()`, recorded by the setter |
| `readOnly` / `writeOnly` | enforced | serialization groups you have to pass |

Rule of thumb: **runtime** when the request itself must follow the spec (parameter styles, partial
updates, one library end to end); **symfony** when you want plain DTOs your framework owns and
violations in the shape Symfony already speaks.

Each mode has its own guide — what it can do, how to wire it, where it stops:

- **[README.runtime.md](README.runtime.md)**
- **[README.symfony.md](README.symfony.md)**


## Validation Notes

A few behaviours worth knowing when validating against the schema:

- **`type: array` means a JSON array (list).** A value passes only when it is a PHP list (sequential integer keys from `0`). An associative array is treated as a JSON object, not an array — so a getter returning `array_filter(...)` (which may leave non-contiguous keys) should wrap the result in `array_values(...)`.
- **`oneOf` / `anyOf` pick the first matching branch.** Branches are tried in declaration order and the first one that validates wins. When several branches accept the same input (e.g. `oneOf: [string, integer]` given `"123"`), order your schema branches from most specific to least specific.
- **`unevaluatedProperties` / `unevaluatedItems` (JSON Schema 2019-09/2020-12, OpenAPI 3.1).** Like `additionalProperties: false` / a suffix `items`, but annotation-aware: a key or index counts as "evaluated" when it is covered by this schema *or* by any in-place applicator that actually applies (`allOf`, a passing `anyOf`/`oneOf` branch, the taken `if`/`then`/`else` arm, a triggered `dependentSchemas`) — recursively, to any nesting depth. Only what is left over is checked. They are enforced on the non-materialized paths (raw lists, inline maps); a composed object with named properties is materialized into a dedicated nested DTO where unknown keys are impossible by construction.
- **`contentEncoding` / `contentMediaType` / `contentSchema` (JSON Schema 2019-09/2020-12, OpenAPI 3.1).** Enforced as assertions on strings: the value must decode under `contentEncoding` (`base64`, `base16`, `quoted-printable`, `7bit`/`8bit`/`binary`; an unknown codec such as `base32` is accepted leniently), the decoded bytes must parse when `contentMediaType` is a JSON type (`application/json` or any `+json`), and the parsed document must satisfy `contentSchema`.
- **`$defs` (JSON Schema) is folded into `components.schemas`.** A `$defs` map (in the root document or an external file) and any `#/$defs/X` pointer — local `#/$defs/X` or cross-file `other.yaml#/$defs/X` — are normalized to `components.schemas` at load time, so `$defs`-style specs generate the same as `components`-style ones. (Subschema-local `$defs`, e.g. `#/components/schemas/Foo/$defs/Bar`, is not folded — prefer top-level `components.schemas`/`$defs` for shared types.)
- **Parameters serialized via `content`.** A parameter that uses `content: {application/json: {schema}}` instead of a plain `schema` is supported: the schema is extracted and its JSON-string value is decoded before validation and casting (malformed JSON is a clear error).
- **Extended string formats.** Beyond the common set, these are validated: `uri-reference`/`iri-reference`, `uri-template` (RFC 6570), `idn-hostname`, `relative-json-pointer`. Unknown formats are accepted (per spec, an unknown `format` is an annotation, not an assertion).

## Upgrading

The library itself is a drop-in replacement: **DTOs generated by 2.8.x keep working unchanged against
2.9.0 services** (measured on 55 specs — same accept/reject verdicts, same normalized output; the two
metadata methods added in 2.9.0 are simply absent on old DTOs and the services fall back).

What changes is the code the generator EMITS — a bare `type: object` property becomes a map, a named
scalar schema becomes a type alias, and Symfony-mode DTOs expose accessors instead of public
properties. Every change, what it breaks and what to do about it:
**[CHANGELOG.md](CHANGELOG.md#upgrading-from-28x)**.
