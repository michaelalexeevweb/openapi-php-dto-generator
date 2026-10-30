# Performance

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md) · [yii3](README.yii3.md)

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
| yii3 | hydrate the OBJECT → validate it → `getData()` |

Comparing `bind` to `bind` is fair. Comparing the ORDER is not — a Laravel FormRequest validates before
anything is hydrated, by design.

Numbers below: **PHP 8.4.14 (CLI) on an Apple M3 Pro, opcache off, JIT off, 20000 iterations**, all
five modes from `bin/benchmark` so the columns are comparable, measured with 2.15.116. Each figure is the
mean of TWO runs of the same command, because one run cannot tell a real change from its own spread:
between the two, `symfony validate` moved 18% and everything else at most 6% with nothing changed. yii3
needs **ext-intl** — the shared spec has a `date-time` property, yii3 emits it with `#[ToDateTime]`, and
`yiisoft/hydrator` lists the extension as what that attribute needs. Without it `bin/benchmark` drops the
yii3 column rather than faking it.

Earlier editions of this page were measured on PHP 8.5.9 in a Linux container, so the two sets are not
comparable figure for figure. Absolute values will differ on your machine, and some ratios move between
runs. Re-measure rather than quote.


## Per step

| Step | runtime | symfony | laravel | laravel-data | yii3 |
|---|---:|---:|---:|---:|---:|
| bind / hydrate | 0.1459 ms | 0.2363 ms | **0.0033 ms** | 0.0880 ms | 0.1172 ms |
| validate | 0.1353 ms | **0.0882 ms** | 1.4672 ms | 1.7335 ms | 0.2023 ms |
| normalize | 0.0123 ms | 0.1100 ms | **0.0019 ms** | 0.0474 ms | 0.0105 ms |
| normalize + validate | 0.1496 ms | 0.1983 ms | 1.4691 ms | 1.7810 ms | 0.2128 ms |

yii3 is the one mode whose `bind` does NOT validate — that is its defining difference, so the two rows
are separate code paths rather than one split in two. What an action actually pays — `ValidatingHydrator`,
which runs the raw `#[Validate]` checks before the cast and then the object's rules, plus reading the
verdict — is measured as its own row: **0.4432 ms**.


## Round trip

Bind + normalize, then the same with validation added:

| | runtime | symfony | laravel | laravel-data | yii3 |
|---|---:|---:|---:|---:|---:|
| without validation | 0.1582 ms | 0.3463 ms | **0.0052 ms** | 0.1354 ms | 0.1278 ms |
| with validation | **0.2935 ms** | 0.4345 ms | 1.4724 ms | 1.8689 ms | 0.3300 ms |

So validation adds ~86% to a runtime round trip, ~25% to a Symfony one, and ~2.6× to a yii3 one —
and dominates both rule-based modes completely. Which is worth one more measurement before drawing a
conclusion.


## Where Laravel's millisecond goes

Splitting the Laravel validate step:

| | ms |
|---|---:|
| the framework alone, empty rule set | 0.0111 ms |
| the framework + the generated `rules()` | 1.4101 ms |
| the generated interpreter alone (`withValidator()`) | **0.0382 ms** |

**The cost is `illuminate/validation` evaluating the rules, not the generated code.** Building a
`Validator` is free; running ~20 rule entries — parsing rule strings, resolving messages, expanding the
dotted and `*` wildcard paths, `Rule::enum` — is what takes the millisecond. The emitted interpreter, the
part this library actually wrote, is 2.6% of the step.

That also makes the interpreter the fastest validator here for the same schema — 0.038 ms against
Symfony's 0.088, runtime's 0.135 and yii3's 0.202 — for a boring reason: it is a specialized walk over a
literal `const` array, with no reflection, no metadata cache and no per-property object graph.

If Laravel validation cost matters for your throughput, the lever is the rule set, not this library: fewer
paths in `rules()` (Laravel evaluates each one) has a far bigger effect than anything the generator emits.


## What laravel-data costs, and why

It runs the SAME rule array and the SAME emitted interpreter as laravel mode, so nothing extra is being
checked:

| | laravel | laravel-data | ratio |
|---|---:|---:|---:|
| hydrate | 0.0033 ms | 0.0880 ms | 27× |
| validate | 1.4672 ms | 1.7335 ms | 1.18× |
| `toArray()` | 0.0019 ms | 0.0474 ms | 25× |
| `from($request)` — validate + hydrate in one call | — | 1.8959 ms | — |

The hydration and normalization ratios are the expected shape of the trade: laravel mode runs
straight-line generated code over an array, laravel-data runs a reflective pipeline over a cached
`DataClass` — casts, pipes, `Optional` resolution, nested `Data` objects. That is exactly what buys the
things the generator no longer has to emit, and both numbers are still well under a tenth of a
millisecond.

The VALIDATE step used to be 2.0× and the reason was not extra checking either. Splitting it is how that
was found and then closed.

**The five rows below are a SEPARATE, older measurement** — a one-off split during the 2.11.0
investigation, 4000 iterations on the host rather than in the container, so read them against each other
and not against the tables above. They are kept because the shape of the gap is the point, not its
absolute size:

| | ms |
|---|---:|
| the emitted `rules()` call itself | 0.0004 ms |
| laravel-data resolving its own rule set (`getValidationRules()`) | 0.1820 ms |
| `illuminate/validation` over the EMITTED rules | 1.0640 ms |
| `illuminate/validation` over the RESOLVED rules | 1.0586 ms |
| `validate()` end to end | 1.3556 ms |

The two rule sets now have the same entries and cost the same to evaluate, so what is left is
laravel-data's own resolution — 13% of that step — plus its pipeline. Before `#[WithoutValidation]` was
emitted on nested-`Data` properties the resolved set was ours PLUS a `Closure` on `tags.*`, its nested-data
rule resolution, which Laravel ran once per item of the collection while the `tags.*.id` paths this
generator emitted were still evaluated on top: 1.9635 ms to evaluate instead of 1.0586 ms. That was found
as a correctness bug — one missing nested key produced two messages — and fixing it took 39% off this step
and 45% off `from($request)`. See [README.laravel-data.md](README.laravel-data.md) for the attribute.

So the lever is the same as in laravel mode: **the rule set, not the generated code.**

`from($request)` (1.8959 ms) is not cheaper than doing the two steps separately (0.0880 + 1.7335 =
1.8215 ms) — it is the same work plus reading and decoding the request body. It is one call, not a faster
one.


## On real schemas

The benchmark spec is one shape. The same three steps over SEVEN request bodies of a production
application — list filters, a body with a discriminated union, nested lists of objects, enum-typed
filters — generated in all five modes with 2.15.116. Each payload is a full valid one built from the
schema (every property set, lists of three), accepted by all five modes before timing; 5000 iterations,
mean of two runs, same host. Round trip = bind + validate + normalize, in ms:

| Body (payload size) | runtime | symfony | laravel | laravel-data | yii3 | yii3 action path |
|---|---:|---:|---:|---:|---:|---:|
| a union behind a nullable `allOf` (49 B) | 0.037 | 0.072 | 0.165 | 0.322 | **0.029** | 0.038 |
| a list of objects (97 B) | **0.087** | 0.174 | 0.487 | 0.615 | 0.149 | 0.187 |
| report filters (216 B) | **0.108** | 0.186 | 0.535 | 0.704 | 0.140 | 0.199 |
| nested lists of objects (408 B) | **0.280** | 0.548 | 1.035 | 1.452 | 0.512 | 0.644 |
| a create body, 13 fields (460 B) | 0.271 | 0.277 | 2.043 | 2.586 | **0.194** | 0.286 |
| a contact with nested lists (639 B) | **0.570** | 1.066 | 2.415 | 3.300 | 0.988 | 1.245 |
| report filters with id lists (1170 B) | 0.616 | 0.528 | 2.233 | 2.887 | **0.402** | 0.638 |

"yii3 action path" is `ValidatingHydrator` plus the verdict, which is what an action runs; the yii3
column is the three steps alone, like the others.

What the real bodies add to the picture above:

- **The ordering holds.** Runtime, Symfony and yii3 stay within ~2× of each other on every body; the two
  Laravel modes are 3–9× behind on every one, and laravel-data a further 1.2–1.9× behind Laravel.
- **Laravel's cost is still the rule evaluation.** Hydrate and `toArray()` never pass 0.01 ms; validate
  is 0.16–2.4 ms and grows with the number of rule paths — the `.*` wildcard rules of nested lists most.
- **Size is not the driver; shape is.** The 1170-byte filter body is cheaper than the 639-byte contact in
  every mode: a list of integers is one rule, a list of objects is a rule per field per item.


## What a process pays before the first request

Every number above is steady state. A freshly booted PHP process pays some of it once, and that
cost is worth naming because it is where a spec-driven library can quietly become expensive.

**The document is not read at runtime.** It is parsed once, when you run the generator, and ends up
as a literal `const` array inside the generated class. There is no YAML to load per process, no
schema tree to build, no cache to warm or invalidate — the constraints are already PHP, and opcache
treats them like any other code.

What a process does pay, measured on the same corpus (three runs, PHP 8.4.14, Apple M3 Pro):

| | ms |
|---|---:|
| `require_once` of the 4 generated classes | 0.64 – 0.80 |
| first `deserialize()` — per-class metadata, built once | 0.60 – 0.87 |
| every `deserialize()` after that | 0.125 – 0.128 |

That first row is the noisiest number on this page: one run in five came back at 1.77 ms rather
than 0.6. Treat it as an order of magnitude, not a figure.

The metadata step reflects over the DTO once per class per process and reads its source file to
resolve the short class names used in docblocks; both results are cached for the lifetime of the
process. On a long-lived worker this happens on the first request and never again. On a
process-per-request setup it happens every time, so budget roughly a millisecond for a corpus this
size — it grows with the number of DISTINCT DTO classes a request touches, not with payload size.

The lever, if this matters to you, is the number of distinct DTO classes on the hot path — not
anything in the generator's flags. Nothing here has been measured against opcache's file cache or a
preloader; both would plausibly help, and neither is a claim this page is willing to make without
numbers.

## Reading the rest of the table

- **Laravel hydration is ~44× faster than runtime and ~72× faster than Symfony** because
  `fromValidated()` is straight-line generated code over an array it already has. Runtime binds from an
  HTTP request (sources, casts, presence tracking); the Symfony serializer and laravel-data both
  resolve types by reflection.
- **`toArray()` is ~58× faster than the Symfony serializer** for the same object, ~6× faster than
  runtime's normalizer and ~25× faster than laravel-data's. Same reason: emitted code versus a generic,
  reflective one. yii3's `getData()` is the same shape of code and lands beside runtime's, at 0.011 ms.
- **Runtime pays for what only it does.** Its extra row — binding from a freshly built request, 0.1577 ms
  against 0.1459 ms — is parameter sources, `style`/`explode` and multipart encoding. No other mode can do
  that at any price; see the [support matrix](README.support-matrix.md).
- **yii3 sits between runtime and Symfony end to end**, and ~4.5× under Laravel. It has no serializer in
  the path and no rule array to walk: the work is `yiisoft/validator` attributes over an object, plus the
  one `#[Callback]` the interpreter lives behind.


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
