# Validation notes

How this library reads the parts of the OpenAPI and JSON Schema vocabulary where a careless reading and
a correct one give different verdicts. Every item is pinned by a test.

For the keyword-by-keyword answer per mode, see the [support matrix](README.support-matrix.md).
Back to the [README](README.md).

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
  | runtime | `param "tags" must contain unique items.` |
  | symfony | `field "tags" must contain unique items.` |
  | laravel | `tags must contain unique items.` — keyed by `tags` in the error bag |

  This holds for every keyword the interpreter owns (`oneOf`, `anyOf`, `not`, `contains`, `if`/`then`, `propertyNames`, `unevaluated*`, …) and is pinned by `tests/Parity/InterpreterMessageParityTest`. A keyword the framework has its own rule for keeps the FRAMEWORK's message — `exclusiveMinimum` reads *"This value should be greater than 3."* in Symfony mode and `multipleOf` resolves `validation.multiple_of` in Laravel mode — so your own translations still apply.
- **Extended string formats.** Beyond the common set, these are validated: `uri-reference`/`iri-reference`, `uri-template` (RFC 6570), `idn-hostname`, `relative-json-pointer`. Unknown formats are accepted (per spec, an unknown `format` is an annotation, not an assertion).
- **`contentEncoding: base64` accepts the empty string.** Empty content is validly encoded as nothing, so an empty value passes the encoding check; the rest is checked strictly, and `!!!` or a mispadded `QQ=` are refused. Use `minLength: 1` when the field must actually carry content.
- **`format: uri` accepts a URI, not only a URL.** `urn:isbn:0451450523`, `urn:uuid:…`, `mailto:` and `tel:` are valid absolute URIs under RFC 3986 and are accepted. An authority-based URI (anything after the scheme starting with `//`) is still judged as a URL, so `http://[` stays refused.
- **`uint64` cannot be checked at its own boundary.** `18446744073709551615` (the maximum) and `18446744073709551616` (one past it) are the same IEEE-754 double once `json_decode()` is done, so nothing downstream can separate them; the boundary value is accepted rather than refusing a legal maximum. Carry such a field as `type: string` with a `pattern` when the exact range matters. **`int64` was described here as having no such gap, and that was wrong** — it has exactly the same
  one, and unlike `uint64` it is fixable. `(float)PHP_INT_MAX` rounds UP to 2^63 (2^63-1 has no
  double), so a value one past the maximum used to pass the guard and wrap to `PHP_INT_MIN`: sending
  `9223372036854775808` stored `-9223372036854775808` and validated clean. Since 2.15.30 a float at or
  beyond ±2^63 is refused with `must be within integer range (…)`. Nothing legal is lost, because both
  int64 extremes FIT an int and arrive from `json_decode()` as `integer`, never as a float — which is
  precisely what `uint64` cannot say about its own maximum.
- **An `integer` bound is exact past 2^53.** `maximum: 9007199254740992` refuses `9007199254740993`: the comparison stays on integers while both the value and the bound are integers, instead of casting through a float that cannot tell the two apart.
- **`idn-email` means the DOMAIN too.** `a@пример.рф` validates, not just `ф@example.com`. PHP's own
  filter allows Unicode before the `@` and requires ASCII after it, so the domain is checked with the
  same RFC 5890 rule `idn-hostname` uses (with or without the `intl` extension). Plain `format: email`
  stays ASCII-only — that is the difference between the two. Since 2.15.28.
- **In 3.0, keywords beside a `$ref` are honoured, not ignored — a deliberate deviation.** The 3.0
  specification says any sibling of `$ref` is ignored, so `{$ref: …, nullable: true}` should produce a
  non-nullable type. It produces a nullable one here, because that spelling is the standard workaround
  for a nullable reference in 3.0, the ecosystem's tools read it the same way, and obeying the letter
  would quietly change the type of a property thousands of documents describe correctly. In 3.1 the
  siblings are permitted by the specification itself, so there the behaviour needs no defence.
- **A boolean IS a schema.** `true` accepts every value and `false` accepts none, wherever a schema
  may stand: `items`, `contains`, `not`, `propertyNames`, `if`/`then`/`else`, `contentSchema`, each
  entry of `properties` / `patternProperties` / `dependentSchemas`, and each branch of `allOf` /
  `anyOf` / `oneOf` / `prefixItems`. The common one is closing a tuple — `prefixItems: [...]` with
  `items: false` refuses an item past the declared positions. (`additionalProperties: false` and
  `unevaluatedItems` / `unevaluatedProperties` have always read the boolean; for them it is the
  ordinary spelling.) A `false` refusal reads *"is not allowed by the schema"* — the document did not
  write `not`, so neither does the message. Enforced since 2.15.27; before it, a boolean subschema was
  dropped and its keyword did nothing.
- **An empty schema matches everything.** `items: {}`, `contains: {}` and `additionalProperties: {}` apply — and, importantly, mark their targets as evaluated, so a neighbouring `unevaluatedItems: false` or `unevaluatedProperties: false` does not reject a valid payload.
- **How far `uri-reference`/`iri-reference` go.** A reference may be relative, so most of what looks wrong is legal: `not_a_uri` and `###` are valid relative references and are accepted, as any conforming validator accepts them. The check is deliberately no stricter than whitespace and control characters, which means a broken percent-escape (`%zz`) or a malformed host (`http://[`) passes here while the stricter `uri`/`iri` refuse both. Full RFC 3986 grammar is not worth the emitted code; if a field must be an absolute, well-formed URI, declare `format: uri`.