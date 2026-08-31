# OpenAPI PHP DTO Generator

[![MIT License](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/LICENSE)
[![CI](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)

### Your OpenAPI document, enforced by the PHP it generates.

Point it at an OpenAPI 3.0 / 3.1 spec. Get immutable, strictly-typed PHP 8.3 DTOs whose **generated code
enforces the schema, not just the types** — `oneOf`, `minimum`, `pattern`, `format`,
`unevaluatedProperties` and the rest of a broad, tested vocabulary — for Symfony, Laravel,
`spatie/laravel-data`, Yii3, or standalone.

Generate DTOs from OpenAPI, deserialize a Symfony `Request` or any PSR-7 request straight into them,
validate incoming HTTP requests against the OpenAPI schema, and normalize back to arrays or JSON — one
package, and no spec parsing at runtime.

- **In:** an OpenAPI 3.0 / 3.1 document, YAML or JSON.
- **Out:** one PHP class per schema — from `components.schemas` **and** from each operation's body,
  query and path parameters.
- **Use it for:** server-side request DTOs, PATCH-safe presence, response normalization.
- **Not for:** generating an API client or SDK — see [what it does not do](#what-it-does-not-do).
- **Start with:** `runtime` mode, the default. Reach for a framework mode only when you want that
  framework's own validation output.

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.15.18
```

---

## 60 seconds

This document:

```yaml
components:
  schemas:
    UserPostRequest:
      type: object
      required: [email]
      properties:
        email:    {type: string, format: email}
        age:      {type: integer, minimum: 18}
        nickname: {type: [string, 'null']}
```

Through this command:

```bash
php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto \
  --file=openapi.yaml --directory=src/Generated --namespace='App\Generated'
```

Becomes this class — an optional property defaults to a sentinel, not to `null`, which is what keeps
"absent" and "sent as `null`" apart:

```php
final class UserPostRequest implements GeneratedDtoInterface, Stringable
{
    public function __construct(
        private readonly string $email,
        private readonly int|UnsetValue|null $age = UnsetValue::UNSET,
        private readonly string|UnsetValue|null $nickname = UnsetValue::UNSET,
    ) { /* … */ }

    public function getEmail(): string { /* … */ }
    public function getAge(): ?int { /* … */ }
    public function getNickname(): ?string { /* … */ }
    public function isNicknameInRequest(): bool { /* … */ }   // was the key sent at all?
}
```

Which you use like this:

```php
// IN — the HTTP request becomes a typed DTO, or this throws with every problem at once.
$input = (new DtoDeserializer())->deserialize($request, UserPostRequest::class);
$user = $users->find($id);

$user->setEmail($input->getEmail());          // required by the document, so always there

// A PATCH must touch only what the client actually sent, and `null` IS something the client can
// send — it clears the nickname. `!== null` cannot tell those two apart; the presence check can.
if ($input->isNicknameInRequest()) {
    $user->setNickname($input->getNickname()); // may be null, and that is the point
}

// OUT — the response DTO is checked against its own schema before it is serialized, so a broken
// response fails here rather than at the client.
$responseBody = (new DtoNormalizer())->validateAndNormalizeToArray(
    new UserResponse(email: $user->getEmail(), nickname: $user->getNickname()),
);
```

And which answers like this — the messages below are the real output, not a paraphrase:

| request body | `getNickname()` | `isNicknameInRequest()` |
|---|---|---|
| `{"email":"a@b.test"}` | `null` | **`false`** — the key never came |
| `{"email":"a@b.test","nickname":null}` | `null` | **`true`** — the client sent `null` on purpose |
| `{"email":"a@b.test","nickname":"neo"}` | `"neo"` | `true` |

Same getter, different answer: that distinction is what a PATCH endpoint needs and what a plain `?string`
cannot express. And when the document is not satisfied:

| request body | error |
|---|---|
| `{"age":30}` | `Required parameter "email" not found in request.` |
| `{"email":"nope","age":12}` | `param "email" must match format email.`<br>`param "age" must be greater than or equal to 18.` |

`format: email` and `minimum: 18` are enforced by code the generator wrote, and both problems are
reported together rather than one at a time.

<sub>The presence accessor is named for its mode: `isNicknameInRequest()` in runtime, `isNicknameProvided()`
in symfony, laravel and yii3. laravel-data needs neither — there the property's own type is
`string|Optional`.</sub>

Add `--attributes=symfony|laravel|laravel-data|yii3` to get the same enforcement in your framework's own
shape instead.
[Every CLI option, and how to vendor the runtime services →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.cli.md)

---

---

## Why this one

Four PHP tools that read an OpenAPI document were downloaded and run on the **same spec** with the
**same payloads** — a fifth, `php-collective/dto`, generates from its own XML config and never saw the
spec, so it is [reported separately](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.comparison.md)
and holds no cell here. Nothing below is quoted from anyone's README — every cell is a verdict the tool
actually returned, on the versions and date named there.

| enforced on the payload | `minimum` / `maximum` | `pattern` | `format` | `oneOf` | builds a typed object |
|---|:--:|:--:|:--:|:--:|:--:|
| JanePHP `open-api-3` 7.13, `validation: true` | ❌ | ❌ | ❌ | ❌ | ✅ |
| OpenAPI Generator 7.24 — `php-symfony`, `php-dt`, `php-nextgen` | ❌ | ❌ | ❌ | ❌ | ✅ |
| `maxbeckers/php-openapi-generator` 0.1.6 | ❌ | ❌ | ❌ | ❌ | ✅ |
| `league/openapi-psr7-validator` 0.24² | ✅ | ✅ | ✅¹ | ✅ | ❌ |
| **this library** | **✅** | **✅** | **✅** | **✅** | **✅** |

<sub>¹ everything except `uri-template`. ² at runtime — league generates no code at all, which is what the
last column says. Every other row is enforced by the code the tool generated.</sub>

**Every other generator in the set checks the type and whether the key is present, and stops there.** In
the whole set the OpenAPI vocabulary is enforced by exactly one tool — a runtime validator that generates
no classes at all. This is the only one that does both.

On real payloads that means `code: 5` against `minimum: 10` is refused here and accepted by Jane; a
malformed `uri-template` is refused here and accepted by both Jane and league; and where league answers
`Keyword validation failed: Data must match exactly one schema`, this answers
`param "code" must match format uuid.`
**[Every payload, every verdict, the versions, and where we are behind →](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.comparison.md)**

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

## Five modes

All five enforce the same **schema validation** vocabulary — the keywords above hold in every one of them.
What differs is everything around it: who validates, what the errors look like, what you install, and
**how much of the REQUEST the document still governs**.

| Mode | What it emits | Needs | Errors arrive as |
|---|---|---|---|
| **[runtime](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.runtime.md)** *(default)* | immutable DTO + this library's validator / normalizer / deserializer | this package | one aggregated exception |
| **[symfony](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.symfony.md)** | plain DTO with `#[Assert\*]`, `#[SerializedName]`, `#[Groups]` | `symfony/validator` + `symfony/serializer` | `ConstraintViolationList`, 422 via `#[MapRequestPayload]` |
| **[laravel](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel.md)** | plain DTO + a `FormRequest` carrying `rules()` | nothing beyond the framework | the framework's own 422 error bag |
| **[laravel-data](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.laravel-data.md)** | one `Data` class per schema, `Optional` for presence | `spatie/laravel-data` | the framework's own 422 error bag |
| **[yii3](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.yii3.md)** | one `AbstractInput` per schema, `yiisoft/validator` attributes | `yiisoft/validator` + `yiisoft/hydrator` + `yiisoft/input-http` | a `Result` your action reads |

**Request binding is not the same in every mode, and that is the one difference worth knowing before you
choose.** A framework mode hands binding to its own serializer or hydrator, which has already decided what
the payload is before any generated code runs:

| | runtime | symfony | laravel | laravel-data | yii3 |
|---|:--:|:--:|:--:|:--:|:--:|
| parameter sources — `path` / `query` / `header` / `cookie` | ✅ | ❌ | ❌ | ❌ | body, query, path only |
| `style` / `explode` / `allowReserved` / `allowEmptyValue` | ✅ | ❌ | ❌ | ❌ | ❌ |
| multipart `encoding` | ✅ | ❌ | ❌ | ❌ | ❌ |

**Rule of thumb.** Not sure? Take **runtime** — it is the default, it needs no framework, and it is the
only mode where the REQUEST itself follows the document. Take **symfony** or **laravel** when you want
plain DTOs your framework owns and errors in the shape it already speaks. Take **laravel-data** or
**yii3** when your application already runs that package.

There are **thirteen** further divergences, all deliberate and each pinned by a test that names its cause.
They are written down rather than left to be discovered:
**[the support matrix](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.support-matrix.md)**
gives the keyword-by-keyword answer per mode and lists what is not generated in any of them. It is derived
from the parity test suites, so a row that stops being true fails a test.

---

## Documentation

| | what is in it |
|---|---|
| [How it compares](https://github.com/michaelalexeevweb/openapi-php-dto-generator/blob/master/README.comparison.md) | five tools downloaded and run, the four OpenAPI ones on the same spec and payloads |
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
