# Performance

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md)

How long a payload takes to bind, validate and normalize in each mode — measured, with the benchmark in
the repository so you can re-measure on your own hardware instead of believing this page.

```bash
php bin/benchmark                       # 20000 iterations, plain output
php bin/benchmark --iterations=100000
php bin/benchmark --markdown            # the tables below
```


## Method

One payload, one schema, all three modes:

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

Comparing `bind` to `bind` is fair. Comparing the ORDER is not — a Laravel FormRequest validates before
anything is hydrated, by design.

Numbers below: **PHP 8.4.14, Apple M3 Pro, opcache off, JIT off, 20000 iterations.** Absolute values will
differ on your machine; the ratios have been stable across runs and across opcache on/off (opcache buys
roughly 10% everywhere).


## Per step

| Step | runtime | symfony | laravel |
|---|---:|---:|---:|
| bind / hydrate | 0.1385 ms | 0.2425 ms | **0.0028 ms** |
| validate | 0.1135 ms | 0.1443 ms | 1.1317 ms |
| normalize | 0.0135 ms | 0.1105 ms | **0.0018 ms** |
| normalize + validate | 0.1323 ms | 0.2548 ms | 1.1335 ms |

## Round trip

Bind + normalize, then the same with validation added:

| | runtime | symfony | laravel |
|---|---:|---:|---:|
| without validation | 0.1520 ms | 0.3530 ms | **0.0047 ms** |
| with validation | **0.2655 ms** | 0.4973 ms | 1.1363 ms |

So validation roughly **doubles** a runtime round trip, adds ~40% to a Symfony one, and dominates a
Laravel one completely. Which is worth one more measurement before drawing a conclusion.


## Where Laravel's millisecond goes

Splitting the Laravel validate step:

| | ms |
|---|---:|
| the framework alone, empty rule set | 0.0117 ms |
| the framework + the generated `rules()` | 1.0825 ms |
| the generated interpreter alone (`withValidator()`) | **0.0319 ms** |

**The cost is `illuminate/validation` evaluating the rules, not the generated code.** Building a
`Validator` is free; running ~20 rule entries — parsing rule strings, resolving messages, expanding the
dotted and `*` wildcard paths, `Rule::enum` — is what takes the millisecond. The emitted interpreter, the
part this library actually wrote, is 3% of the step.

That also makes the interpreter the fastest validator of the three for the same schema — 0.032 ms against
runtime's 0.114 and Symfony's 0.144 — for a boring reason: it is a specialized walk over a literal
`const` array, with no reflection, no metadata cache and no per-property object graph.

If Laravel validation cost matters for your throughput, the lever is the rule set, not this library: fewer
paths in `rules()` (Laravel evaluates each one) has a far bigger effect than anything the generator emits.


## Reading the rest of the table

- **Laravel hydration is ~50× faster than the other two** because `fromValidated()` is straight-line
  generated code over an array it already has. Runtime binds from an HTTP request (sources, casts,
  presence tracking); Symfony's serializer resolves types by reflection every time.
- **`toArray()` is ~60× faster than the Symfony serializer** for the same object, and ~7× faster than
  runtime's normalizer. Same reason: emitted code versus a generic, reflective one.
- **Runtime pays for what only it does.** Its extra row — binding from a freshly built request, 0.1547 ms
  against 0.1385 ms — is parameter sources, `style`/`explode` and multipart encoding. The other two modes
  cannot do that at any price; see the [support matrix](README.support-matrix.md).


## What this is not

- **Not a throughput claim.** These are per-operation costs of one payload shape. Your schema, payload
  size and PHP build decide your numbers — a 3-item list is not a 300-item one, and cost grows with the
  payload, not with the size of the document.
- **Not a reason to pick a mode.** Every figure here is a fraction of a millisecond except the Laravel
  rule evaluation, and that is the framework's own validator doing the work you asked it to do. Pick a
  mode by what the [support matrix](README.support-matrix.md) says it can enforce and by which error
  envelope your application already speaks.
- **Not asserted by the test suite.** `bin/benchmark` measures; it does not fail. A performance regression
  will not break your build — re-run it when you care.
