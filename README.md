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
- 🎯 **Two generation modes** — **runtime** (DTOs backed by this library's validator/normalizer/deserializer) or **symfony** (plain DTOs decorated with Symfony `#[Assert\*]` / `#[SerializedName]` / `#[Groups]` attributes, validated and (de)serialized by Symfony itself)
- ✅ **OpenAPI request validation** — validate HTTP requests against OpenAPI constraints (required fields, types, enums, formats, etc.)
- 🔄 **Normalization** — convert DTOs to plain arrays or JSON, with or without validation
- 📦 **Symfony Request support** — deserialize Symfony `Request` objects directly into typed PHP DTOs
- 🔌 **Framework-agnostic (PSR-7)** — deserialize any PSR-7 `ServerRequestInterface` via `DtoDeserializerPsr7` (Slim, Mezzio, Laminas, Yii3, …); Symfony `Request` covers Symfony + Laravel
- 🔒 **Immutable by design** — all generated classes are read-only value objects
- ⚡ **Supports OpenAPI 3.0.x and 3.1.x**

## Table of Contents

- [Installation](#installation)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Generate DTOs](#generate-dto-classes-from-yaml-openapi-spec)
- [Generation Modes: Runtime vs Symfony](#generation-modes-runtime-vs-symfony)
- [Validate & Normalize](#validate-and-normalize-generated-dtos)
- [Framework-Agnostic Deserialization (PSR-7)](#framework-agnostic-deserialization-psr-7)
- [CLI Commands](#cli-commands)

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.8.7
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
| `--ref` | | | Explicit output directory for an external `$ref` spec file **or directory**: `<refFileOrDir>=<directory>`. A directory key maps every ref'd file inside it. Repeatable. Requires a matching `--ref-namespace`. Unmatched ref files are ignored. |
| `--ref-namespace` | | | Explicit namespace for an external `$ref` spec file **or directory**: `<refFileOrDir>=<namespace>`. Repeatable. Requires a matching `--ref`. |

## Generation Modes: Runtime vs Symfony

The generator emits DTOs in one of two modes, selected with `--attributes` (default: `runtime`).

### Runtime mode (default)

DTOs implement `GeneratedDtoInterface` and carry the metadata methods (`toArray()`,
`getNormalizationMap()`, `getConstraints()`, …). They are validated, normalized and deserialized
by **this library's own services** — `DtoValidator`, `DtoNormalizer`, `DtoDeserializer` — which
enforce the full OpenAPI vocabulary (including `oneOf`/`anyOf`/`allOf`, `if/then/else`, `not`,
`prefixItems`, object/map constraints) and track which optional fields were actually provided
(PATCH-friendly presence tracking via the `UnsetValue` sentinel).

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test
  # --attributes=runtime is the default
```

```php
// generated in runtime mode (excerpt)
final class User implements GeneratedDtoInterface, Stringable
{
    // presence flags per property: $nameInRequest, $emailInRequest, … (what was actually sent)

    /**
     * @param string $name
     * Constraints: minLength=2, maxLength=50
     * @param string|UnsetValue|null $email
     * Constraints: format=email
     */
    public function __construct(
        private readonly string $name,
        private readonly string|UnsetValue|null $email = UnsetValue::UNSET,
        private readonly Address|UnsetValue|null $address = UnsetValue::UNSET,
    ) {
        $this->emailInRequest = $email !== UnsetValue::UNSET; // presence tracking (PATCH-friendly)
        // …
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email !== UnsetValue::UNSET ? $this->email : null;
    }

    // + isNameInRequest()/isNameRequired()/…, toArray(), jsonSerialize(),
    //   getNormalizationMap(), getAliases(), getConstraints() — consumed by the runtime services
}
```

### Symfony mode (`--attributes=symfony`)

DTOs are plain, immutable data classes with promoted `public readonly` constructor properties
decorated with **Symfony Validator / Serializer attributes**. There is no library runtime: the DTOs
are validated by `symfony/validator` and (de)serialized by `symfony/serializer` (or auto-mapped in a
controller with `#[MapRequestPayload]` / `#[MapQueryString]`).

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test \
  --attributes=symfony
```

```php
// generated in symfony mode
class User
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 2, max: 50)]
        public readonly string $name,
        #[Assert\Email]
        public readonly ?string $email = null,
        #[SerializedName('created_at')]
        public readonly ?DateTimeImmutable $createdAt = null,
        #[Assert\Valid]
        public readonly ?Address $address = null,
    ) {
    }
}
```

In a Symfony controller the DTO is validated and populated automatically:

```php
public function create(#[MapRequestPayload] User $user): Response { /* ... */ }
```

**OpenAPI → Symfony attribute mapping:**

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
| `enum` | generated PHP backed `enum` (type-enforced) |
| `format: email` / `uuid` / `uri` / `ipv4`,`ipv6` / `hostname` | `#[Assert\Email]` / `Uuid` / `Url` / `Ip` / `Hostname` |
| `format: int32` / `uint32` / `uint64` | `#[Assert\Range]` (bounds) |
| `format: date` / `date-time` | `DateTimeImmutable` type |
| `format: binary` | `UploadedFile` type |
| `items` (scalar) / `additionalProperties` | `#[Assert\All([...])]` |
| `anyOf` | `#[Assert\AtLeastOneOf([...])]` |
| nested DTO / array of DTOs | `#[Assert\Valid]` (cascade) |
| property name ≠ OpenAPI name | `#[SerializedName('…')]` |
| `readOnly` / `writeOnly` | `#[Groups(['read'])]` / `#[Groups(['write'])]` |

**Symfony-mode limitations** (no clean Symfony Validator equivalent — these keywords are skipped):
`oneOf`/`discriminator` polymorphism, `not`, `if/then/else`, `prefixItems` (tuples),
`patternProperties`, `propertyNames`, `dependentRequired`/`dependentSchemas`, `contains`. Optional
fields become `?T = null` (no `UnsetValue` presence tracking — use runtime mode if you need
PATCH/partial-update semantics). Note also: `format: uri`/`iri` maps to `#[Assert\Url]`, which
expects an absolute URL (relative URIs would fail); and an `anyOf` branch that is purely
`{type: null}` causes the whole `#[Assert\AtLeastOneOf]` to be dropped (the field stays nullable).

> Requires `symfony/validator` and `symfony/serializer` in the consuming project.

## Framework-Agnostic Deserialization (PSR-7)

`deserialize()` accepts a Symfony `Request` — which also covers **Laravel** (its
`Illuminate\Http\Request` extends the Symfony one). Laravel route parameters
(`/users/{id}`) are bridged automatically: `deserialize()` reads them from
`$request->route()->parameters()` when present, so path params resolve with no extra wiring. For any other stack (Slim, Mezzio, Laminas,
Yii3, …) that speaks **PSR-7**, use `DtoDeserializerPsr7`: it converts a PSR-7
`ServerRequestInterface` into a Symfony `Request` via the official
[`symfony/psr-http-message-bridge`](https://github.com/symfony/psr-http-message-bridge) and
delegates to the core deserializer.

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7;
use Psr\Http\Message\ServerRequestInterface;

/** @var ServerRequestInterface $request */
$deserializer = new DtoDeserializerPsr7();

// Single object body:
$dto = $deserializer->deserializePsr7($request, UserPostRequest::class);

// Top-level JSON array body (bulk endpoints):
$items = $deserializer->deserializeCollectionPsr7($request, Item::class);
```

Path parameters are read from PSR-7 request attributes (`$request->withAttribute('id', …)`), where
routers typically place them — the bridge carries them over to the Symfony request.

PSR-7 support requires the bridge in your project:

```bash
composer require symfony/psr-http-message-bridge
```

When vendoring the runtime into your project (`--dto-generator-directory`), pass `--with-psr7` to
also copy `DtoDeserializerPsr7` alongside the other runtime services.

### Laravel

`Illuminate\Http\Request` is a Symfony `Request`, so the core `DtoDeserializer` takes it directly —
body, query, headers, cookies and uploaded files all work, and `/users/{id}` route parameters are
bridged automatically. No PSR-7 conversion or extra package needed.

```php
use Illuminate\Http\Request;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;

class UserController
{
    public function store(Request $request)
    {
        // route params (/users/{id}), query, JSON body, headers, cookies and files all resolve.
        $dto = (new DtoDeserializer())->deserialize($request, UserPostRequest::class);
        // ... use $dto
    }
}
```

## Validation Notes

A few behaviours worth knowing when validating against the schema:

- **`type: array` means a JSON array (list).** A value passes only when it is a PHP list (sequential integer keys from `0`). An associative array is treated as a JSON object, not an array — so a getter returning `array_filter(...)` (which may leave non-contiguous keys) should wrap the result in `array_values(...)`.
- **`oneOf` / `anyOf` pick the first matching branch.** Branches are tried in declaration order and the first one that validates wins. When several branches accept the same input (e.g. `oneOf: [string, integer]` given `"123"`), order your schema branches from most specific to least specific.
