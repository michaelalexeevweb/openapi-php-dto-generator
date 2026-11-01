# How this compares, and how that was measured

The claims on the [README](README.md) are verdicts, not readings. Every tool below was **downloaded,
installed and run**; every tool that reads an OpenAPI document got the same one, and the same payloads.
`php-collective/dto` is the exception and is counted apart throughout — it generates from its own XML
config and never saw the spec, so it holds no cell in the vocabulary or verdict tables. Nothing here is
quoted from anyone's documentation — where a table says a tool accepts something, it accepted it.

Measured 2026-08-19/20. Spec: `OpenApiExamples/test.yaml` as it stood that day (19 schemas, 589 lines; the file
has grown since — a discriminated union,
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

Three more were added on 2026-11-01 and run on the reduced schema quoted under
[Verdicts on real payloads](#verdicts-on-real-payloads):

| Tool | Version | Ran | What it produced on that schema |
|---|---|:--:|---|
| `wol-soft/php-json-schema-model-generator` | 0.26.2 | ✅² | 1 class / 641 lines: an immutable model whose constructor runs the checks as emitted PHP |
| `onmoon/openapi-server-bundle` | 6.0.0 | ✅³ | DTOs + handler interfaces; the request itself is validated by `league/openapi-psr7-validator` 0.22 |
| `ensi/laravel-openapi-server-generator` | 4.0.5 | ✅ | a `FormRequest` whose `rules()` is filled from the spec, plus routes, controllers and resources |

<sub>² out of the box it does NOT generate this schema: it ships no format validator at all
(`GeneratorConfiguration::initFormatValidator()` is empty) and throws `Unsupported format uuid for property
code` on the first `format` it meets. Every format has to be supplied by the user as a
`FormatValidatorInterface`. It was therefore run on the same schema with the three `format` keywords
removed — which is why it accepts `"nope"` and the two malformed URIs below.<br>
³ onmoon's `LeaguePSR7RequestSchemaValidator` builds a PSR-7 request and calls league's
`getRoutedRequestValidator()`; the payloads were run through exactly that call. The DTOs were not
generated here — that needs a booted Symfony kernel — so nothing below is claimed about them.</sub>

## The OpenAPI vocabulary

Checked on a schema carrying `minimum: 10`, `maximum: 100`, plus `pattern`, `format` and `enum` elsewhere.

| | `minimum`/`maximum` | `pattern` | `format` | composition (`oneOf`) |
|---|:--:|:--:|:--:|:--:|
| Jane, `validation: true` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-symfony` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-dt` | ❌ | ❌ | ❌ | ❌ |
| OpenAPI Generator `php-nextgen` | ❌ | ❌ | ❌ | ❌ |
| `maxbeckers/php-openapi-generator` | ❌ | ❌ | ❌ | ❌ |
| `ensi/laravel-openapi-server-generator` | ❌ | ❌ | partly: `uuid`, `email`, `date`, `ipv4`…; not `uri` | ❌ |
| `wol-soft/php-json-schema-model-generator` | ✅ | ✅ | ❌ none built in — generation **fails** on one | ✅ |
| `onmoon/openapi-server-bundle` | ✅ | ✅ | ✅ except `uri-template` | ✅ |
| `league/openapi-psr7-validator` | ✅ | ✅ | ✅ except `uri-template` | ✅ |
| **this library**, all five modes | ✅ | ✅ | ✅ incl. `uri-template`; symfony accepts a loose `date-time` | ✅ |

**Most generators in the set check the type at most, and stop there** — and two do not even hold
presence: maxbeckers invents `''` or `0` for a missing required key (footnote ⁴ below), and OG
`php-symfony` cannot tell an absent key from `null` (footnote ³). Two go further. wol-soft emits bounds,
`pattern` and `oneOf` as PHP, as this library does, but has no `format` of its own. onmoon holds everything
league holds, because it is league: the generated server hands each request to the runtime validator, so
it inherits league's `uri-template` gap and its messages. Unlike plain league it does not re-parse the
YAML per process — `SpecificationLoader` keeps the parsed document in Symfony's `cache.app` — so the
21.8 ms in [Startup cost](#startup-cost) is not its number.

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
| `code = 42.0` — an integer per §6.1.1 | ✅ accepted | ❌ **refused**⁶ | ✅ accepted² | ✅ accepted | — |
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
there is no message and no path — the request 500s instead of 422-ing.<br>
⁶ corrected on 2026-09-06: this cell read "accepted" and does not reproduce. league's type keyword tests
`integer` as `is_int($data)`, and a JSON `42.0` decodes to a PHP float — so it refuses, on a plain
`type: integer` property as well as inside the `oneOf`, in both the 3.0 and 3.1 dialects. That is the
source, not a reading: `Schema/Keywords/Type.php`.</sub>

**Jane catches exactly three things:** a missing required key, `null`, and a wrong scalar type. All of
`oneOf`, every `format`, every bound — through.

The three added on 2026-11-01, same schema, same payloads:

| payload | wol-soft 0.26.2⁷ | onmoon 6.0.0 | ensi 4.0.5 |
|---|---|---|---|
| baseline `{id:1, name:"n", code:42}` | ✅ accepted | ✅ accepted | ✅ accepted |
| `code = 42.0` — an integer per §6.1.1 | ❌ **refused** | ❌ **refused** | ✅ accepted⁸ |
| `code = 42.5` — not an integer | ✅ refused | ✅ refused | ❌ **accepted** |
| `code = 5` — breaks `minimum: 10` | ✅ refused | ✅ refused | ❌ **accepted** |
| `code = <uuid>` — second `oneOf` branch | ✅ accepted | ✅ accepted | ✅ accepted |
| `code = "nope"` — no branch matches | ❌ **accepted**⁷ | ✅ refused | ❌ **accepted** |
| required `name` missing | ✅ refused | ✅ refused | ✅ refused |
| `name = null` | ✅ refused | ✅ refused | ✅ refused |
| `id = "1"` — string for an integer | ✅ refused | ✅ refused | ❌ **accepted**⁹ |
| an undeclared key (schema allows it) | ✅ accepted | ✅ accepted | ✅ accepted |
| `homepage` not a URI (`format: uri`) | ❌ **accepted**⁷ | ✅ refused | ❌ **accepted** |
| `endpoint` a malformed `uri-template` | ❌ **accepted**⁷ | ❌ **accepted** | ❌ **accepted** |

<sub>⁷ run without the `format` keywords, because with them it does not generate at all (footnote ² of
[What ran](#what-ran)). The three `format` rows are what a user gets until they write those validators
themselves.<br>
⁸ accepted, but not because it was checked: ensi emits `'code' => ['required']` and nothing else for a
`oneOf` property.<br>
⁹ the rule ensi emits is Laravel's `integer`, which accepts a numeric string. The whole `rules()` it
generated for this schema is `id: [required, integer]`, `name: [required, string]`, `code: [required]`,
`homepage: [string]`, `endpoint: [string]` — no bound, no `pattern` (the mapping has no case for either),
and `format: uri` is not recognised because ensi maps only its own spelling, `url`.</sub>

onmoon's column is league's column, verdict for verdict, and its messages are league's too:
`Keyword validation failed: Data must match exactly one schema, but matched none`. wol-soft's are its own
and do name the property and the reason — `Value for code must not be smaller than 10`, nested under the
`oneOf` branch that produced it.

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
| **this library** | `private readonly int $id` + `getId()` (runtime, laravel); `public readonly int $id` (laravel-data, yii3) | immutable, except symfony's optional properties, which have setters | PHP 8 attributes |

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
Here the document is compiled into PHP array literals in the generated class (`getConstraints()` in
runtime, a `private const` in the framework modes), so the cost does not exist.

Current per-step numbers for all five modes are in [README.performance.md](README.performance.md); the
figures above are kept as measured on the day, beside the competitors, rather than refreshed.

## Where we are behind

- **No HTTP client generation.** Jane emits `Endpoint/` plus a `Client.php` — 96 files on our spec — and
  OpenAPI Generator is what most published PHP SDKs come out of. This library generates the server side
  only. That is the largest gap in the set.
- **No TypeScript output.** None of the seven OpenAPI competitors generates it either, so it is not a gap
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
  Re-run before quoting it anywhere that matters. **Re-measured 2026-09-06**, four of the five tools
  reinstalled from scratch and re-run on the schema quoted above:

  | | rows reproduced | notes |
  |---|---|---|
  | this library (runtime) | 12 / 12 | both quoted messages byte-identical |
  | `league` 0.24 | 11 / 12 | the twelfth is corrected above (footnote ⁶) |
  | Jane 7.13.0 | 12 / 12 | footnote ² verbatim: `'code' => Required([NotNull])`, no `Type` |
  | maxbeckers 0.1.6 | 12 / 12 | footnotes ¹, ⁴ and ⁵ all verbatim, down to `$data['name'] ?? ''` |
  | OG 7.24.0 `php-symfony` | source-level | `TestPostRequestCode` has zero properties; required properties are `?T $x = null`; no bound, format or composition assertion is emitted |

  `php-collective/dto` was not re-run — it generates from its own XML config, holds no cell in the tables
  above, and re-authoring that config measures the config rather than the tool.

  Two things about the re-run itself are worth writing down. maxbeckers still does not start out of the
  box, for the reason footnote ¹ gives, and still needs `symfony/console` pinned below v8. And the full
  `OpenApiExamples/test.yaml` no longer loads in a fresh league install at all: composer now resolves its
  parser to `devizzent/cebe-php-openapi`, which refuses a boolean subschema our corpus carries on purpose.
  That is why league was re-measured on the reduced schema.

  **Re-measured 2026-11-01**, after three of the tools shipped new releases — installed fresh and run on
  the same schema and the same twelve payloads:

  | | rows reproduced | notes |
  |---|---|---|
  | Jane 8.1.1 | 12 / 12 | the generated constraint is unchanged: `'code' => Required([NotNull])`, no `Type`, no bound, no format |
  | maxbeckers 1.0.0 | 12 / 12 | `fromArray()` still reads `$data['name'] ?? ''` and `$data['id'] ?? 0`; the new `--framework=symfony` and `--framework=laravel` targets emit no validation either |
  | OG 7.26.0 `php-symfony` | source-level | unchanged: `TestPostRequestCode` has zero properties, required properties are `?T $x = null`; no bound, format or composition assertion in `php-symfony`, `php-dt` or `php-nextgen` |

  `league` (0.24) and `php-collective/dto` (0.1.20) had no new release. maxbeckers 1.0.0 now declares
  `symfony/console: ^7.0|^8.0`, yet still calls `Application::add()`, so out of the box it still dies on v8
  and still needs `symfony/console:^7.0` pinned — footnote ¹ holds for 1.0.0 as well.
- **Nothing here is reproducible from this repository.** The competitors are not vendored and there is no
  harness: the tables are a record of a run, not a test that fails when a verdict changes. Treat them as
  dated evidence, and re-measure rather than trust them.
