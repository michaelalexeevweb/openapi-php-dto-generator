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
  | runtime | `param "tags" must contain unique items` |
  | symfony | `field "tags" must contain unique items` |
  | laravel | `tags must contain unique items` — keyed by `tags` in the error bag |

  This holds for every keyword the interpreter owns (`oneOf`, `anyOf`, `not`, `contains`, `if`/`then`, `propertyNames`, `unevaluated*`, …) and is pinned by `tests/Parity/InterpreterMessageParityTest`. A keyword the framework has its own rule for keeps the FRAMEWORK's message — `exclusiveMinimum` reads *"This value should be greater than 3."* in Symfony mode and `multipleOf` resolves `validation.multiple_of` in Laravel mode — so your own translations still apply.
- **Extended string formats.** Beyond the common set, these are validated: `uri-reference`/`iri-reference`, `uri-template` (RFC 6570), `idn-hostname`, `relative-json-pointer`. Unknown formats are accepted (per spec, an unknown `format` is an annotation, not an assertion).