# OpenAPI PHP DTO Generator

[![MIT License](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/LICENSE)
[![CI](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)

### Your OpenAPI document, enforced by the PHP it generates.

Point it at an OpenAPI 3.0 / 3.1 spec. Get immutable, strictly-typed PHP 8.3 DTOs whose **generated code
enforces the whole schema** — `oneOf`, `minimum`, `pattern`, `format`, `unevaluatedProperties`, all of it —
for Symfony, Laravel, `spatie/laravel-data`, Yii3, or standalone.

Generate DTOs from OpenAPI, deserialize a Symfony `Request` or any PSR-7 request straight into them,
validate incoming HTTP requests against the OpenAPI schema, and normalize back to arrays or JSON — one
package, and no spec parsing at runtime.

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.15.0
```

---

## Why this one

Five PHP tools were downloaded and run on the **same spec** with the **same payloads**. Nothing below is
quoted from anyone's README — every cell is a verdict the tool actually returned.
[The method, the versions, and where we are behind →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.comparison.md)

Schema under test: `code` is `oneOf [integer 10..100, string uuid]`, plus `pattern`, `format` and `enum`
elsewhere in the document.

| | `minimum` / `maximum` | `pattern` | `format` | `oneOf` | builds a typed object |
|---|:--:|:--:|:--:|:--:|:--:|
| JanePHP `open-api-3` 7.13, `validation: true` | ❌ | ❌ | ❌ | ❌ | ✅ |
| OpenAPI Generator 7.24 — `php-symfony`, `php-dt`, `php-nextgen` | ❌ | ❌ | ❌ | ❌ | ✅ |
| `league/openapi-psr7-validator` 0.24 | ✅ | ✅ | ✅¹ | ✅ | ❌ |
| **this library — all five modes** | **✅** | **✅** | **✅** | **✅** | **✅** |

<sub>¹ everything except `uri-template`.</sub>

**Every other generator in the set checks the type and whether the key is present, and stops there.** In the whole set, the OpenAPI
vocabulary is enforced by exactly one tool — a runtime validator that generates no classes at all. This is
the only one that does both.

What that costs you on real payloads:

| payload | this library | league | Jane |
|---|---|---|---|
| `code: 42.5` — not an integer | rejected | rejected | **accepted** |
| `code: 5` — breaks `minimum: 10` | rejected | rejected | **accepted** |
| `code: "nope"` — matches no branch | rejected | rejected | **accepted** |
| `homepage: "not a uri"` — `format: uri` | rejected | rejected | **accepted** |
| `endpoint` — malformed `uri-template` | rejected | **accepted** | **accepted** |

And the sentence your client gets back:

| | message for `code: "nope"` |
|---|---|
| **this library** | `param "code" must match format uuid` |
| league | `Keyword validation failed: Data must match exactly one schema` |
| Jane | *(accepted — no message)* |

### Three things that only fall out of generating the checks

- **Nothing is parsed at boot.** The spec is compiled into a literal `const` inside the generated class.
  `league` re-reads the YAML in every PHP process — **21.8 ms** before it answers the first request. Here
  that cost does not exist.
  [Performance →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.performance.md)
- **PATCH is expressible.** "Absent" and "sent as `null`" stay different values — a sentinel, an `Optional`
  member, or an uninitialised typed property, depending on the mode. OpenAPI Generator makes every property
  nullable with `= null`, so the two collapse into one and a partial update cannot be written correctly.
- **An undiscriminated `anyOf` stays honest.** You get an interface plus one class per branch, and a warning
  at generation time saying it will not be hydrated. Others flatten the branches into a single class — where
  a cat carrying `bark` passes — or drop the property to `mixed`.

### Fast, because the checks are emitted code

`bin/benchmark`, PHP 8.5 in the project container, opcache off, 20 000 iterations, mean of two runs:

| | bind | validate | normalize | round trip |
|---|---:|---:|---:|---:|
| runtime | 0.1232 ms | 0.1177 ms | 0.0178 ms | **0.2588 ms** |
| laravel (`FormRequest`) | **0.0037 ms** | 1.7163 ms | **0.0032 ms** | 1.7233 ms |

The generated Laravel interpreter — the part this library actually writes — is **1.9%** of that Laravel
validate step; the millisecond is `illuminate/validation` walking the rule array.
[Full numbers, and the benchmark to re-run them →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.performance.md)

---

## 60 seconds

Generate:

```bash
php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto \
  --file=openapi.yaml --directory=src/Generated --namespace='App\Generated'
```

Use:

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use App\Generated\UserPostRequest;

// request: read it according to the document, or throw naming what is wrong
$dto = (new DtoDeserializer())->deserialize($request, UserPostRequest::class);

$dto->getName();              // string, guaranteed
$dto->isEmailInRequest();     // was the key sent at all? — PATCH without guesswork

// response: validate against the same document, then normalize
$body = (new DtoNormalizer())->validateAndNormalizeToArray($responseDto);
```

That is runtime mode. Add `--attributes=symfony|laravel|laravel-data|yii3` to get the same enforcement in
your framework's own shape instead.

[Every CLI option, and how to vendor the runtime services →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.cli.md)

---

## Five modes, one vocabulary

All five enforce the same OpenAPI vocabulary. They differ in what surrounds it — who validates, what the
errors look like, and what you have to install.

| Mode | What it emits | Needs | Errors arrive as |
|---|---|---|---|
| **[runtime](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.runtime.md)** *(default)* | immutable DTO + this library's validator / normalizer / deserializer | this package | one aggregated exception |
| **[symfony](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.symfony.md)** | plain DTO with `#[Assert\*]`, `#[SerializedName]`, `#[Groups]` | `symfony/validator` + `symfony/serializer` | `ConstraintViolationList`, 422 via `#[MapRequestPayload]` |
| **[laravel](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel.md)** | plain DTO + a `FormRequest` carrying `rules()` | nothing beyond the framework | the framework's own 422 error bag |
| **[laravel-data](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel-data.md)** | one `Data` class per schema, `Optional` for presence | `spatie/laravel-data` | the framework's own 422 error bag |
| **[yii3](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.yii3.md)** | one `AbstractInput` per schema, `yiisoft/validator` attributes | `yiisoft/validator` + `yiisoft/hydrator` + `yiisoft/input-http` | a `Result` your action reads |

**Rule of thumb.** Pick **runtime** when the request itself must follow the document — parameter styles,
`allowReserved`, multipart encoding, partial updates, one library end to end. Pick **symfony** or
**laravel** when you want plain DTOs your framework owns and errors in the shape it already speaks. Pick
**laravel-data** or **yii3** when your application already runs that package.

The modes are not interchangeable in every corner, and the corners are written down rather than left to be
discovered: **[the support matrix](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.support-matrix.md)**
gives the keyword-by-keyword answer per mode, names every place they diverge and why, and lists what is not
generated in any of them. It is derived from the parity test suites, so a row that stops being true fails a
test.

---

## Documentation

| | |
|---|---|
| [How it compares](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.comparison.md) | five tools downloaded and run on the same spec, same payloads |
| [Support matrix](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.support-matrix.md) | every keyword per mode, every divergence, what is out of scope |
| [Performance](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.performance.md) | bind / validate / normalize per mode, measured, with the benchmark |
| [Validation notes](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.validation.md) | where a careless reading of the spec and a correct one disagree |
| [CLI reference](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.cli.md) | every option, vendoring the runtime, external `$ref` mapping |
| [runtime](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.runtime.md) · [symfony](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.symfony.md) · [laravel](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel.md) · [laravel-data](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel-data.md) · [yii3](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.yii3.md) | one guide per mode: what it does, how to wire it, where it stops |
| [CHANGELOG](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/CHANGELOG.md) | every release, what it breaks, and what to do about it |

---

## Requirements

PHP 8.3+ and the Symfony 7.4 components `console`, `http-foundation`, `mime`, `yaml`. Each mode's own
dependencies are in its guide.

## What it does not do

It generates the **server** side: the classes an incoming request becomes, and the checks that request must
pass. It does not generate an HTTP client or an SDK for calling someone else's API — if that is what you
need, JanePHP and OpenAPI Generator do it and this does not.

## Upgrading

`composer update`, then regenerate. Every release says what changed in the emitted code, what breaks and
what to do about it: **[CHANGELOG.md](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/CHANGELOG.md)**.

The one signature change to watch for is 2.12.0 → 2.13.0, and only if a class of **your own** implements
`DtoDeserializerInterface` — two methods gained optional parameters there.

## License

MIT.
