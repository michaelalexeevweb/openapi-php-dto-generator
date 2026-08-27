# How this compares, and how that was measured

The claims on the [README](README.md) are verdicts, not readings. Every tool below was **downloaded,
installed and run** on the same OpenAPI document, and fed the same payloads. Nothing here is quoted from
anyone's documentation — where a table says a tool accepts something, it accepted it.

Measured 2026-08-19/20. Spec: `OpenApiExamples/test.yaml` (19 schemas, 589 lines — a discriminated union,
an undiscriminated `anyOf`, a scalar `oneOf`, recursion, `format`). Machine: Apple M3 Pro.

## What ran

| Tool | Version | Ran | What it produced on our spec |
|---|---|:--:|---|
| JanePHP `open-api-3` | 7.13.0 | ✅ | 96 files / 4 913 lines: DTOs + normalizers + an HTTP client |
| OpenAPI Generator CLI | 7.24.0 | ✅ | 36–68 files / 1.8k–18.5k lines, nine PHP generators to choose from |
| `league/openapi-psr7-validator` | 0.24 | ✅ | nothing — it is a validator, not a generator |
| `php-collective/dto` | 0.1.20 | ✅ | 11 classes / 3 928 lines, from its own XML config |
| `maxbeckers/php-openapi-generator` | 0.1.6 | ✅¹ | 20 files / 1 310 lines: `readonly` models with `fromArray()` |

<sub>¹ out of the box it does NOT start — composer resolves its `symfony/console` constraint to v8.1.4,
where `Application::add()` was removed, and the binary dies on `Call to undefined method`. The first pass
of this research recorded it as unusable, which was wrong: `composer require symfony/console:^7.0 -W`
downgrades cleanly and the tool then runs. No patched vendor code was needed, and the results below are
from a normal run.</sub>

## The OpenAPI vocabulary

Checked on a schema carrying `minimum: 10`, `maximum: 100`, plus `pattern`, `format` and `enum` elsewhere.

| | `minimum`/`maximum` | `pattern` | `format` | composition (`oneOf`) |
|---|:--:|:--:|:--:|:--:|
| Jane, `validation: true` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-symfony` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-dt` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-nextgen` | ❌ | ❌ | ❌ | ❌ |
| `maxbeckers/php-openapi-generator` | ❌ | ❌ | ❌ | ❌ |
| `league/openapi-psr7-validator` | ✅ | ✅ | ✅ except `uri-template` | ✅ |
| **this library**, all five modes | ✅ | ✅ | ✅ incl. `uri-template` | ✅ |

**Every generator in the set checks the type and whether the key is present, and stops there.** The only
tool that holds the vocabulary is the runtime validator, and it produces no classes.

Worth stating plainly because it is easy to overclaim: Jane's validation is **off by default** — the table
above already gives it `'validation' => true`.

## Verdicts on real payloads

Operation `POST /api/test`, schema `TestPostRequest`:

```yaml
required: [id, name, code]
id:       {type: integer}
name:     {type: string}
code:     {oneOf: [{type: integer, minimum: 10, maximum: 100}, {type: string, format: uuid}]}
homepage: {type: string, format: uri}
endpoint: {type: string, format: uri-template}
```

`✅` = the answer the document calls for, `❌` = not.

| payload | this library (runtime) | league 0.24 | Jane 7.13 | maxbeckers 0.1.6 | OG `php-symfony` |
|---|---|---|---|---|---|
| baseline `{id:1, name:"n", code:42}` | ✅ accepted | ✅ accepted | ✅ accepted | ✅ accepted | ❌ refused¹ |
| `code = 42.0` — an integer per §6.1.1 | ✅ accepted | ✅ accepted | ✅ accepted² | ✅ accepted | — |
| `code = 42.5` — not an integer | ✅ refused | ✅ refused | ❌ **accepted** | ❌ **accepted** | — |
| `code = 5` — breaks `minimum: 10` | ✅ refused | ✅ refused | ❌ **accepted** | ❌ **accepted** | — |
| `code = <uuid>` — second `oneOf` branch | ✅ accepted | ✅ accepted | ✅ accepted² | ✅ accepted | — |
| `code = "nope"` — no branch matches | ✅ refused | ✅ refused | ❌ **accepted** | ❌ **accepted** | — |
| required `name` missing | ✅ refused | ✅ refused | ✅ refused | ❌ **accepted as `""`**⁴ | ➖ cannot tell³ |
| `name = null` | ✅ refused | ✅ refused | ✅ refused | ❌ **accepted as `""`**⁴ | ✅ refused |
| `id = "1"` — string for an integer | ✅ refused | ✅ refused | ✅ refused | ✅ refused⁵ | — |
| an undeclared key (schema allows it) | ✅ accepted | ✅ accepted | ✅ accepted | ✅ accepted | — |
| `homepage` not a URI (`format: uri`) | ✅ refused | ✅ refused | ❌ **accepted** | ❌ **accepted** | — |
| `endpoint` a malformed `uri-template` | ✅ refused | ❌ **accepted** | ❌ **accepted** | ❌ **accepted** | — |

<sub>¹ the `php-symfony` model validates an OBJECT, and `code` there is an empty class
`TestPostRequestCode`; a valid object cannot be built from the array by ordinary means, so even the
baseline does not get through.<br>
² accepted, but not because it was checked: Jane's constraint for `code` is `Required([NotNull])` with no
`Type` at all. Anything non-empty passes.<br>
³ its required properties are initialised to `null`, so "the key was not sent" and "the key was sent as
null" are one state. Presence cannot be expressed.<br>
⁴ maxbeckers does not merely miss the check — its `fromArray()` INVENTS a value: `$data['id'] ?? 0`,
`$data['name'] ?? ''`. An entirely empty payload produces a well-formed object with `id = 0` and
`name = ''`, which is worse than a refusal because nothing downstream can tell it apart from real data.<br>
⁵ and only by accident: the promoted `public int $id` throws a `TypeError`, not a validation error, so
there is no message and no path — the request 500s instead of 422-ing.</sub>

**Jane catches exactly three things:** a missing required key, `null`, and a wrong scalar type. All of
`oneOf`, every `format`, every bound — through.

### The message the client gets

`code = "nope"`:

| tool | message |
|---|---|
| this library | `param "code" must match format uuid.` |
| league | `Keyword validation failed: Data must match exactly one schema` |
| Jane | *(accepted — no message)* |

`name = null`:

| tool | message |
|---|---|
| this library | `param "name" expects string, got null.` |
| league | `Keyword validation failed: Value cannot be null` |
| Jane | `This value should not be null.` |

Ours name the path and the expected type. That is not decoration: `tests/Parity/InterpreterMessageParityTest`
fails if the wording drifts apart between modes.

## Presence, and therefore PATCH

| | how |
|---|---|
| Jane | `$initialized[]` + `isInitialized($prop)` |
| `php-collective/dto` | `_touchedFields[]` |
| **this library**, runtime | the `UnsetValue` sentinel |
| **this library**, laravel-data | `Optional` in the property's own union type |
| **this library**, yii3 | an uninitialised typed property |
| OpenAPI Generator | **no mechanism** — everything is nullable with `= null` |
| maxbeckers | **worse than none** — a missing required key becomes `0` / `''` |
| league | no concept of it |

## An undiscriminated `anyOf`

| | what it does | honest? |
|---|---|---|
| Jane | no class, property is `mixed` | the type is thrown away |
| OG `php-dt` | branches merged into one flat class | a cat carrying `bark` passes |
| league | validates against the schema, no classes | ✅, but there is no object |
| **this library** | interface + a class per branch, no hydration, a warning at generation | the type survives and the limit is named |

## Shape of the generated class

| | property types | mutability | metadata style |
|---|---|---|---|
| Jane | docblock only, `protected $id` | setters, `extends \ArrayObject` | — |
| OG `php-dt` | `public ?int $id = null` | public fields | `@DTA\…` annotations |
| OG `php-symfony` | `protected ?int $id = null` | setters | `#[Assert\…]` attributes |
| `php-collective/dto` | `protected int\|string $code` | configurable | `_metadata` arrays |
| maxbeckers | `public int $id` in a `readonly class` | immutable | — |
| **this library** | `public readonly int $id` | immutable | PHP 8 attributes |

## Startup cost

2000 iterations, 200 warm-up, PHP 8.4 on the same machine:

| | ms |
|---|---:|
| `league`: parsing the YAML once per process | **21.777** |
| `league`: validating one request | 0.2731 |
| this library, runtime: `bind` from a fresh request | 0.1662 |
| this library, runtime: `validate` | 0.1090 |
| this library, laravel: `bind` (straight-line generated code) | 0.0030 |

The unit of work differs — `league` does not build an object and we do. What is not a matter of
interpretation is the 21.8 ms: it is paid on every process start because the document is read at runtime.
Here the document is compiled into a literal `const` in the generated class, so the cost does not exist.

Current per-step numbers for all five modes are in [README.performance.md](README.performance.md); the
figures above are kept as measured on the day, beside the competitors, rather than refreshed.

## Where we are behind

- **No HTTP client generation.** Jane emits `Endpoint/` plus a `Client.php` — 96 files on our spec — and
  OpenAPI Generator is what most published PHP SDKs come out of. This library generates the server side
  only. That is the largest gap in the set.
- **No TypeScript output.** None of the four measured competitors generates it either, so it is not a gap
  against them — but it is a thing this does not do.
- **Several generation modes is not unique.** OpenAPI Generator ships nine PHP generators. What differs is
  what the emitted code enforces, not the number of choices.

## Caveats, without which the tables lie

- **OG `php-symfony` is not measured under equal conditions.** It validates an object, not an array, and an
  object with a `oneOf` property cannot be built by ordinary means. The dashes in its column mean "this
  shape never reaches the validator", not "not tested".
- **The tools validate at different moments.** Jane checks the raw array before denormalization; OG checks
  the already-built object; this library and league check the raw input. The comparison is fair on the
  question *what does the client get back*, not on internal design.
- **`getRoutedRequestValidator()` was used for league**, because ordinary path matching trips over
  `servers: [{url: 'https'}]` in our own example spec.
- **Versions move.** Everything above is the version named in the first table, on the date named at the top.
  Re-run before quoting it anywhere that matters.
