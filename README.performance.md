# Performance

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md)

How long a payload takes to bind, validate and normalize in each mode — measured, with the benchmark in
the repository so you can re-measure on your own hardware instead of believing this page.

```bash
php bin/benchmark                       # 20000 iterations, plain output
php bin/benchmark --iterations=100000
php bin/benchmark --markdown            # the tables below
```


## Method

One payload, one schema, every mode:

- **the schema** mixes what real documents mix — bounded scalars, a `pattern`, a generated enum, a
  `date-time`, a nested DTO, a list of 3 nested DTOs, a string map, an integer list, and one `oneOf` of
  `integer`/`uuid`. That last one is deliberate: a schema the native rules cover entirely would flatter
  the framework modes by never reaching their second layer;
- **the payload** is VALID. A failing payload is a different measurement (both frameworks short-circuit);
- 200 warm-up calls before every timed loop, so autoloading and the serializer's metadata caches are not
  in the numbers;
- `hrtime()` around N iterations, divided by N. Milliseconds per operation.

**What is outside the timer**: building the HTTP `Request`. That is the caller's cost in every mode, and
leaving it in would have measured `symfony/http-foundation` rather than this library. Runtime mode gets a
second row that puts it back, because reading the request according to the document is a thing only that
mode does at all.

**The steps are not the same shape in every mode, and that is the point:**

| | Order |
|---|---|
| runtime | bind the payload into the DTO → validate the OBJECT → normalize |
| symfony | denormalize into the DTO → validate the OBJECT → normalize |
| laravel | validate the ARRAY → hydrate from the validated keys → `toArray()` |
| laravel-data | validate the ARRAY → hydrate through laravel-data's pipeline → its `toArray()` |

Comparing `bind` to `bind` is fair. Comparing the ORDER is not — a Laravel FormRequest validates before
anything is hydrated, by design.

Numbers below: **PHP 8.4.14, Apple M3 Pro, opcache off, JIT off, 20000 iterations.** Absolute values will
differ on your machine; the ratios have been stable across runs and across opcache on/off (opcache buys
roughly 10% everywhere).


## Per step

| Step | runtime | symfony | laravel | laravel-data |
|---|---:|---:|---:|---:|
| bind / hydrate | 0.1369 ms | 0.2330 ms | **0.0029 ms** | 0.0825 ms |
| validate | 0.1111 ms | 0.1436 ms | 1.0854 ms | 1.4128 ms |
| normalize | 0.0121 ms | 0.1077 ms | **0.0019 ms** | 0.0492 ms |
| normalize + validate | 0.1225 ms | 0.2513 ms | 1.0873 ms | 1.4620 ms |

## Round trip

Bind + normalize, then the same with validation added:

| | runtime | symfony | laravel | laravel-data |
|---|---:|---:|---:|---:|
| without validation | 0.1490 ms | 0.3407 ms | **0.0048 ms** | 0.1317 ms |
| with validation | **0.2602 ms** | 0.4844 ms | 1.0902 ms | 1.5445 ms |

So validation roughly **doubles** a runtime round trip, adds ~40% to a Symfony one, and dominates both
rule-based ones completely. Which is worth one more measurement before drawing a conclusion.


## Where Laravel's millisecond goes

Splitting the Laravel validate step:

| | ms |
|---|---:|
| the framework alone, empty rule set | 0.0110 ms |
| the framework + the generated `rules()` | 1.0557 ms |
| the generated interpreter alone (`withValidator()`) | **0.0313 ms** |

**The cost is `illuminate/validation` evaluating the rules, not the generated code.** Building a
`Validator` is free; running ~20 rule entries — parsing rule strings, resolving messages, expanding the
dotted and `*` wildcard paths, `Rule::enum` — is what takes the millisecond. The emitted interpreter, the
part this library actually wrote, is 3% of the step.

That also makes the interpreter the fastest validator here for the same schema — 0.031 ms against
runtime's 0.111 and Symfony's 0.144 — for a boring reason: it is a specialized walk over a literal
`const` array, with no reflection, no metadata cache and no per-property object graph.

If Laravel validation cost matters for your throughput, the lever is the rule set, not this library: fewer
paths in `rules()` (Laravel evaluates each one) has a far bigger effect than anything the generator emits.


## What laravel-data costs, and why

It runs the SAME rule array and the SAME emitted interpreter as laravel mode, so nothing extra is being
checked:

| | laravel | laravel-data | ratio |
|---|---:|---:|---:|
| hydrate | 0.0029 ms | 0.0825 ms | 28× |
| validate | 1.0854 ms | 1.4128 ms | 1.3× |
| `toArray()` | 0.0019 ms | 0.0492 ms | 26× |
| `from($request)` — validate + hydrate in one call | — | 1.4718 ms | — |

The hydration and normalization ratios are the expected shape of the trade: laravel mode runs
straight-line generated code over an array, laravel-data runs a reflective pipeline over a cached
`DataClass` — casts, pipes, `Optional` resolution, nested `Data` objects. That is exactly what buys the
things the generator no longer has to emit, and both numbers are still well under a tenth of a
millisecond.

The VALIDATE step used to be 2.0× — 2.3182 ms — and the reason was not extra checking either. Splitting it
(4000 iterations, same schema and payload) is how that was found and then closed:

| | ms |
|---|---:|
| the emitted `rules()` call itself | 0.0004 ms |
| laravel-data resolving its own rule set (`getValidationRules()`) | 0.1820 ms |
| `illuminate/validation` over the EMITTED rules | 1.0640 ms |
| `illuminate/validation` over the RESOLVED rules | 1.0586 ms |
| `validate()` end to end | 1.3556 ms |

The two rule sets now have the same entries and cost the same to evaluate, so what is left is
laravel-data's own resolution — 13% of the step — plus its pipeline. Before `#[WithoutValidation]` was
emitted on nested-`Data` properties the resolved set was ours PLUS a `Closure` on `tags.*`, its nested-data
rule resolution, which Laravel ran once per item of the collection while the `tags.*.id` paths this
generator emitted were still evaluated on top: 1.9635 ms to evaluate instead of 1.0586 ms. That was found
as a correctness bug — one missing nested key produced two messages — and fixing it took 39% off this step
and 45% off `from($request)`. See [README.laravel-data.md](README.laravel-data.md) for the attribute.

So the lever is the same as in laravel mode: **the rule set, not the generated code.**

`from($request)` (1.4718 ms) is not cheaper than doing the two steps separately (0.0825 + 1.4128 =
1.4953 ms) — it is the same work plus reading and decoding the request body. It is one call, not a faster
one.


## Reading the rest of the table

- **Laravel hydration is ~50× faster than runtime and Symfony** because `fromValidated()` is straight-line
  generated code over an array it already has. Runtime binds from an HTTP request (sources, casts,
  presence tracking); the Symfony serializer and laravel-data both resolve types by reflection.
- **`toArray()` is ~60× faster than the Symfony serializer** for the same object, and ~6× faster than
  runtime's normalizer. Same reason: emitted code versus a generic, reflective one.
- **Runtime pays for what only it does.** Its extra row — binding from a freshly built request, 0.1473 ms
  against 0.1369 ms — is parameter sources, `style`/`explode` and multipart encoding. No other mode can do
  that at any price; see the [support matrix](README.support-matrix.md).


## What this is not

- **Not a throughput claim.** These are per-operation costs of one payload shape. Your schema, payload
  size and PHP build decide your numbers — a 3-item list is not a 300-item one, and cost grows with the
  payload, not with the size of the document.
- **Not a reason to pick a mode.** Every figure here is a fraction of a millisecond except the rule
  evaluation in the two Laravel modes, and that is the framework's own validator doing the work you asked
  it to do. Pick a mode by what the [support matrix](README.support-matrix.md) says it can enforce and by
  which error envelope your application already speaks.
- **Not measured with laravel-data's structure cache on.** The benchmark boots the package with
  `data.structure_caching` disabled, so nothing is measured warm that would be cold in CI. Whether
  `php artisan data:cache-structures` moves these numbers is untested here — the split above suggests a
  little, since producing the rule set is now the only part of the gap left, at 0.18 ms.
- **Not asserted by the test suite.** `bin/benchmark` measures; it does not fail. A performance regression
  will not break your build — re-run it when you care.
