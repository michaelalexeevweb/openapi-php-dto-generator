# Changelog

This file starts at 2.9.0. Notes for every earlier tag are the
[GitHub releases](https://github.com/michaelalexeevweb/openapi-php-dto-generator/releases).

## 2.15.32 — 2026-09-05

- Path Item parameters reach every operation of the path
- an operation overrides one by name and location
- a callback written as a `$ref` generates its class
- a Path Item written as a `$ref` is resolved

**A parameter declared on the PATH ITEM was dropped entirely.** OpenAPI lets a path carry
`parameters` that apply to all of its operations — it is how `/items/{id}` is normally written, with
`id` declared once instead of once per method:

```yaml
/items/{id}:
  parameters:
    - { in: path,  name: id, required: true, schema: { type: integer } }
    - { in: query, name: shared, schema: { type: string } }
  get:
    parameters:
      - { in: query, name: own, schema: { type: string } }
  delete: { responses: { '200': { description: ok } } }
```

The generator read the operation's own list alone, so `ItemsGetQueryParams` held `own` and nothing
else — no `id`, no `shared`, and `getParameterSources()` listed one entry. The application had no way
to read the path parameter at all, and `delete`, declaring nothing of its own, produced no class.
Nothing was reported: the properties were simply absent from a class the document says should have
them.

Both lists are merged now, the operation winning on a clash. The identity is the pair the
specification names — (name, in) — and an override replaces the definition **where the path declared
it** rather than moving it to the end, so the argument order does not depend on which methods happen
to override what. A `$ref` to `components.parameters` resolves on the path level exactly as it does on
the operation level; that case failed worse than the others, producing no class at all.

**If any path of yours declares `parameters`, the classes for its operations gain properties** — the
ones the document always described. Named arguments are unaffected; positional construction of a
generated `*QueryParams` class is not, since new parameters take their declared place in the list.
An operation that declared nothing of its own now has a class where it previously had none.

**The corpus carried zero path-level parameters**, which is why this survived every release: the whole
suite stayed green with the feature entirely absent. A path declaring two of them — one shared, one
overridden, one operation inheriting both — is in the corpus now, so the emission is snapshotted.

Unchanged and worth stating, because the merge brushes against it: two parameters that share a name
in DIFFERENT locations are kept apart by the merge, as the specification says they should be, but
still collapse onto one PHP property downstream, the last one winning. That behaviour is older than
this fix and is not addressed here.

**A callback written as a `$ref` generated nothing.** A Callback Object may be a Reference Object —
`{$ref: '#/components/callbacks/Shared'}` — and that spelling emitted no class at all, while the
identical callback written inline emitted one. Support for one half of a two-way choice, silent about
the other: the console said `[OK] Generated N classes` and the callback payload was not among them.
References resolve now, including a short chain of them, and the class is named after the callback's
name in the OPERATION rather than the component's, which is what keeps the two spellings
interchangeable. The corpus carries one of each.

**A Path Item written as a `$ref` was ignored.** The Path Item Object carries a `$ref` of its own, and
3.1 adds `components.pathItems` as the place such a reference usually points. Neither was read, so a
document that factors a shared path into a component had that part of itself silently dropped — no
classes, no message. Both local shapes resolve now:

```yaml
/twin:   { $ref: '#/paths/~1real~1{id}' }        # a pointer at another path, `~1` being a slash
/shared: { $ref: '#/components/pathItems/Listing' }
```

Keys the referencing item declares itself win over the ones it inherits. The specification calls that
case undefined in 3.0 and asks for siblings to be ignored in 3.1; overlaying is the reading that
cannot quietly lose an operation someone wrote down.

Two failures are told apart rather than merged, because a reader fixes them differently. A pointer at
nothing is a broken document and stops generation, as a broken schema `$ref` has since 2.15.30. A
pointer OUT of the document is valid OpenAPI this generator does not implement, and says exactly that:
`points outside this document … move the path item into #/components/pathItems, or inline it`. **A
document relying on external path item references now fails instead of generating silently
incomplete output** — the same trade the unresolvable-`$ref` rule made, and the reason it is worth
making twice.

Gates: 1779 tests (up from 1772), phpstan / phpcs / cs-fixer clean. Every previously generated corpus
class is byte-identical — checked file by file, not read off the diff, because inserting classes in
the middle of a snapshot realigns hundreds of unrelated lines.

## 2.15.31 — 2026-09-05

- the `default` response class is `JobsDefault`, not `Jobsdefault`
- non-Latin property names are documented as unsupported

**A `default` response produced a class name with a lowercase word in it.** Every numeric sibling read
`Jobs200`, `Jobs404` — and the default one read `Jobsdefault`. The name normaliser lowercases each
segment and capitalises its first letter, finding segment boundaries at non-alphanumerics or a
lower-to-upper camel step; `Jobsdefault` offers neither, so the word stayed as typed.

**If any document of yours declares `responses.default`, that class is renamed.** `Jobsdefault`
becomes `JobsDefault`, and an import of the old spelling has to be updated. On a case-insensitive
filesystem (macOS by default) the FILE is the same either way, so the change shows up as a class name,
not a missing file — which is also why the test for it asserts on the class declaration rather than
the file name.

A wildcard range keeps the spelling it always had: `4XX` already carries a digit for the normaliser to
split on, so `Jobs4Xx` is untouched. The boundary is inserted only for a purely alphabetic status,
which in OpenAPI means `default` and nothing else — pinned from both sides.

**Non-Latin property names are documented rather than fixed.** A property written in Cyrillic, Greek
or CJK has nothing to normalise into and collapses to `value`, with the wire name carried in
`$aliases`. One per schema works; two collide, and generation stops with
`Property name collision in X: "имя" and "фамилия" normalize to "$value"` rather than emitting a class
that silently holds one of them. That behaviour is unchanged and correct — it was simply written down
nowhere, so a reader met it as a surprise. `README.md` now says so, alongside what IS handled:
`kebab-case`, `dot.name`, `UPPER`, `123numeric`, `with space`, `$dollar` and PHP's reserved words all
become clean identifiers.

Also in this release, with no behaviour change: webhooks, callbacks and per-status response classes
have tests and corpus entries at last. All three were generated correctly and pinned by nothing —
deleting the support would have left the suite green. The audit that found them had to probe the
generator by hand to learn they worked.

Gates: 1772 tests (up from 1768), phpstan / phpcs / cs-fixer clean.

## 2.15.30 — 2026-09-05

- an integer past the 64-bit range is refused, not wrapped into its own negative
- a `$ref` that names nothing stops generation instead of emitting an unusable class
- every media type of an operation gets its own class; JSON keeps the plain name
- the `int64` note in the validation guide was wrong and is corrected

**A number one past `int64` used to change sign, silently.** `json_decode()` hands over anything that
does not fit an int as a float, and the guard deciding whether such a float is still an integer
compared it against `(float)PHP_INT_MAX` — which is not `PHP_INT_MAX`. 2^63-1 has no double, so the
cast rounds UP to 2^63, and a `<=` comparison therefore admitted exactly the first ILLEGAL value for
`(int)` to wrap:

```
sent 9223372036854775808   ->  stored -9223372036854775808,  validate() returned []
sent -9223372036854775809  ->  stored -9223372036854775808,  validate() returned []
sent 18446744073709551616  ->  refused  (the guard did work further out)
```

A client sent a positive number, the DTO held a large negative one, and validation approved it. The
range check downstream could not catch it: by the time it ran, the value was a wrapped `int` sitting
comfortably inside int64. The corruption existed only in the chain decode → cast → validate, which is
why a guard-level test would not have found it and the end-to-end test added here does.

The comparisons are strict now, and **refusing the boundary loses nothing** — measured, not assumed:
both legal extremes fit an int, so `json_decode()` hands them over as `integer` and they never reach
the float branch. A float equal to ±2^63 can only have come from a number outside the range. An
integral float that ROUNDS onto the boundary (`9223372036854775806.0`) is refused with them, because
it carries no information separating a legal value from an illegal one — casting it blind is how the
original corruption happened.

Out-of-range numbers now say `param "n" must be within integer range (…)`. They used to say
`expects int, got float`, which answers someone who did send an integer. A real fractional part keeps
the type message, because there the type IS the problem; both are pinned so neither swallows the other.

**A `$ref` that names nothing used to generate.** A typo — or a schema someone deleted — passed the
whole way through:

```
$ref: '#/components/schemas/Missing'      # no such schema anywhere in the document

[OK] Generated 1 DTO class(es)            # no error, no warning
private readonly Missing $thing           # emitted; no such class is ever written
```

The result passes `php -l`, passes autoloading — a parameter type is resolved lazily — and dies at the
first construction with `must be of type …\Missing`. So the console said the generation succeeded and
a staging server said otherwise. Every shape is covered, because they resolve through different paths:
the property itself, an array's `items`, a map's `additionalProperties`, and each branch of `allOf` /
`oneOf`. The external twin is fixed with it: a reference into a file that DOES exist but does not
declare the schema returned quietly after the caller had already turned it into a type name. (A
missing external FILE was always reported; only the missing schema inside a present one was not.)

The question is asked ONCE, after generation, and that timing is the design rather than convenience.
Schemas are registered both up front and WHILE rendering — an inline object becomes its own schema, a
discriminator variant is synthesised — so a reference can legitimately resolve to a class that does
not exist yet at the moment it is read. Asking at resolution time reported 68 false positives on this
repository's own corpus; asking at the end reports none, and a test pins a document that leans on both
mechanisms so the earlier, wrong shape cannot come back.

**All but one media type of an operation used to be dropped.** `content` is a MAP keyed by media
type, so an operation may legitimately describe several representations of the same payload —
`application/json` beside `application/xml`, a form fallback beside JSON, `text/csv` beside JSON on a
response. The class name was derived from the operation alone, so every media type resolved to the
SAME name and each overwrote the last:

```yaml
/three:
  post:
    requestBody:
      content:
        application/json: { schema: {...} }   # lost
        application/xml:  { schema: {...} }   # lost
        application/x-www-form-urlencoded: { schema: {...} }   # the only one emitted
```

The document's FINAL media type won, which made the choice effectively arbitrary, and a client posting
JSON got `Required parameter "f" not found in request` because the emitted class described the form.

Each one gets its own class now — and **JSON keeps the plain name**:

```
ThreePostRequest      (application/json)          ThreePostRequestXml   (application/xml)
Three200              (application/json)          ThreePostRequestForm  (…/x-www-form-urlencoded)
                                                  Three200Csv           (text/csv)
```

**This is not a renaming release.** Nearly every document describes one JSON body per operation, and
those class names are already generated, imported and committed in consumers — they do not move, which
is pinned from the other side by a test covering a lone `application/json`, a lone `application/xml`
and a lone form. A structured `+json` suffix counts as JSON and takes the plain name even when declared
second; a document with no JSON at all gives the plain name to its first inline schema, which is the
name that document produces today. Only the additional representations gain one.

The suffix comes from the subtype — `Xml`, `Csv` — with shorthands where the literal subtype makes an
unusable identifier (`Form` rather than `XWwwFormUrlencoded`, plus `Multipart`, `Binary`, `Any`).
Two media types that shorten to the same word are separated by a counter.

Narrower than it first looked, and worth stating: a media type whose schema is a `$ref` was never
dropped — the component has its own class and no per-operation one is emitted for it. Only two or more
INLINE object schemas on one operation collided. The corpus now declares such an operation, so the
emission is snapshotted rather than resting on a unit test.

**The correction.** `README.validation.md` stated that `int64` "has no such gap", written when the
`uint64` boundary was ruled unfixable in 2.15.17. The measurement above says otherwise, and the two
cases differ in the one way that matters: `uint64`'s legal maximum does NOT fit an int, so it arrives
as the same double as the first illegal value and no comparison can separate them. `int64`'s does fit.
`uint64` is unchanged and still documented as accepting its boundary.

Gates: 1768 tests (up from 1738), phpstan / phpcs / cs-fixer clean. The snapshots grow by the corpus's
new multi-representation operation and by nothing else: every deletion in the diff is a file-count
header line.

## 2.15.29 — 2026-09-05

- a `readOnly` property no longer makes its class impossible to deserialize
- **the emitted constructor changes shape for those classes — read the migration note**
- the corpus carries `readOnly` at last, so that shape is snapshotted

**Read this before upgrading if any schema of yours marks a property `readOnly`.** The change is a
fix, and it alters an emitted constructor. It ships as a patch by explicit decision; the paragraph on
positional construction below is the part that matters.

**The bug.** OpenAPI says: "If the property is marked as readOnly being true and is in the required
list, the required will take effect on the RESPONSE only." A request that omits such a property is
therefore valid. The generator emitted it as a required, non-nullable constructor parameter anyway,
and `DtoDeserializer` refuses to accept a `readOnly` value from a client on principle — so it had
nothing to pass and nothing to fall back on, and reported

```
Parameter "serverId" is readOnly and non-nullable with no default value.
```

for EVERY request, including one that sent the field. `id: {type: integer, readOnly: true}` inside
`required` is an everyday shape, and it made the whole class undeserializable. No warning was emitted
at generation time either.

Such a property now carries the `UnsetValue` sentinel, which is how "required of a response, not of a
request" is expressed in a constructor. What did NOT change: a `readOnly` value sent by a client is
still ignored, `isXRequired()` still answers true, `toArray()` still writes the key, and `validate()`
still refuses a response that never set one. The requirement moved to where the document put it — the
response — instead of blocking the request.

**Migration: construct with NAMED arguments.** A defaulted parameter must come after every required
one — PHP deprecates the alternative — so a `readOnly` property declared before a required one moves
behind it in the emitted parameter list:

```php
// before                                    // after
__construct(int $serverId, string $title)    __construct(string $title, int|UnsetValue|null $serverId = UnsetValue::UNSET)
```

Positional construction therefore binds different arguments than it did. Where the swapped types
differ you get a `TypeError`; **where they coincide you get nothing at all** — measured, with two
`string` properties:

```php
new Product('C-1', 'Widget');
// before: {"code":"C-1","name":"Widget"}
// after:  {"code":"Widget","name":"C-1"}
```

Named arguments are unaffected and are the fix: `new Product(name: 'Widget', code: 'C-1')`. Two other
signatures widen for the same properties — the getter returns `?int` where it returned `int`, and the
constructor parameter accepts the sentinel — so a caller that assigns a getter result into a
non-nullable type needs a null check that static analysis will point at.

**The corpus now carries `readOnly`, and it never did.** That is why the entire suite stayed green
while the constructor shape changed underneath it: nothing generated a `readOnly` property, so nothing
could see it. A `ReadOnlyFields` schema was added, declaring its `readOnly` property BEFORE the
required one so the parameter order is what the snapshot pins; the emitted metadata, the deserializer
behaviour and the constructor order each have a test, and all three fail if the fix is reverted.

Gates: 1738 tests (up from 1735), phpstan / phpcs / cs-fixer clean. The snapshot diff is additive —
which says the corpus had no coverage here before, not that the change is invisible to callers.

## 2.15.28 — 2026-09-05

- a urlencoded form BODY accepts the repeated-key array the same way a query string does
- `format: idn-email` accepts an internationalized domain, which is what the format is for
- the corpus stops advertising a UUID its own validator refuses, and a test keeps it honest
- the demo corpus declares every parameter style, so their emission is snapshotted at last

2.15.27 fixed `?ids=1&ids=2`. Two of the three fixes here are the rest of that same finding: the
loss had a second half in the request body, and a format check had the mirror-image gap — Unicode
allowed on one side of the `@` only. No generated class changes shape; the golden snapshots move by
36 lines, all of them one example value.

**A form body loses repeated keys exactly like a query string.** `$_POST` is built by the same parser
as `$_GET`, so `tags=a&tags=b` in an `application/x-www-form-urlencoded` body arrived as the string
`"b"` and the cast reported `param "tags" expects array, got string`. The Encoding Object gives a
form-encoded array the same default as a query one — `style: form, explode: true` — so that IS the
documented spelling, and only PHP's own `tags[]=` worked. The repeats are read back off the raw body
now, under the same conditions as the query half: an array-typed property, no delimiter to split on,
scalars left alone (`name=x&name=y` is still `y`).

The parser is now ONE function with two callers rather than two copies. A query string and a form body
are the same text in the same encoding, and the four decisions it makes — skip empty pairs, skip
bracketed keys, treat a valueless key as empty, decode `+` as a space — have to agree between them.
Two copies is how the source waterfall ended up with a half that nothing executed (see 2.15.27).

**A multipart body is deliberately left alone, including its error.** Its parts are not
`&`-separated pairs; Symfony has already separated what it could. The tempting shortcut — treat "no
repeats found" as "wrap the single value" — would turn a repeated multipart field into a one-element
array holding the LAST part, silently, where the reader used to get a loud `expects array, got
string`. Quiet truncation is worse than the error, so the recovery declines that body outright, and
the error is pinned by a test that says why.

**`idn-email` accepts an internationalized domain.** PHP's `FILTER_FLAG_EMAIL_UNICODE` permits Unicode
in the LOCAL part and then validates what follows the `@` as an ASCII host, so `ф@example.com` passed
while `a@пример.рф` — an address whose whole point is the domain — was refused. The domain now goes
through the same RFC 5890 check `idn-hostname` uses, which works with or without the intl extension.

Whatever the filter already accepted is accepted first and unchanged, so the address forms the new
path does not model — a bracketed IP domain, a quoted local part — cannot regress. **This only ever
accepts more.** Plain `format: email` stays ASCII-only, pinned from the other side: a document that
writes `email` and gets Unicode has lost the distinction it asked for.

**The corpus advertised a UUID it would itself refuse.** `00000000-1111-2222-3333-444455556666` under
`format: uuid` has `3` where RFC 4122 allows only 8/9/a/b in the variant nibble, and it appeared six
times — copied verbatim into the generated docblocks, so the package printed to every reader a value
`DtoValidator` rejects. Replaced with `00000000-1111-4222-8333-444455556666`.

Rather than fix six lines and move on, a test now walks the corpus and validates every `example`
against the schema that states it — the corpus grows, and an example is exactly the kind of value
nobody runs through a validator by hand. It was checked against the old value first: it reports all
six.

**The corpus now declares the parameter styles.** `OpenApiExamples/test.yaml` carried none of
`style`, `explode`, `deepObject`, `allowEmptyValue` or `allowReserved`, so what the generator emits
for them was never snapshotted — and the tests that did cover them handed ready-made arrays to the
`Request` constructor rather than parsing a URL. Between those two gaps, `?ids=1&ids=2` was refused by
every release up to 2.15.26 with nothing to notice it.

Two paths were added: one query operation carrying all of it at once (including a `content:
application/json` parameter and a header array), and one path operation for `matrix` and `label`. The
corpus grew by three classes in every mode, purely additive — no existing line of any snapshot moved.
Two request-level tests drive the emitted metadata off one real URL, so the snapshot pins what the
generator WRITES and the tests pin that it WORKS. Nothing was broken: every style passed on the first
run.

Gates: 1733 tests (up from 1706 before this release), phpstan / phpcs / cs-fixer clean. The snapshots
grow by the new corpus paths; apart from that the diff is 36 lines, and every one of them is that
example value.

## 2.15.27 — 2026-09-04

- a query array in OpenAPI's DEFAULT serialization (`?ids=1&ids=2`) is accepted
- `true` / `false` in a subschema position mean what JSON Schema says they mean
- an empty DTO encodes as `{}` through `DtoNormalizer`, not `[]`
- `oneOf` no longer reports two matches as none
- the source waterfall, the conditional annotation walk and the collection entry checks are measured

Three fixes from one review, all of them the same shape: something the document is allowed to say,
which this package read and then dropped. None changes a generated class — the emitted output is
byte-identical in all five modes, and the golden snapshots did not move.

**`?ids=1&ids=2` is a query array again.** `style: form, explode: true` is what OpenAPI applies to a
query array when the document says nothing else, and it puts each element under its own copy of the
key. PHP does not keep the repeats: `$_GET` — and `$request->query`, which reads it — is built the way
`parse_str()` builds it, where the last occurrence wins, so the value arrived as the string `"2"` and
the cast reported `param "ids" expects array, got string`. Only PHP's own `?ids[]=1&ids[]=2` spelling
arrayified, and that spelling is a PHP convention no OpenAPI document can state — so a client
generated from the very same spec sent the documented form and got a 400.

The repeats are still in the raw `QUERY_STRING`, and they are read back from there for exactly one
shape: an array-typed parameter, read from the query, with no delimiter to split on. Everything else
keeps the behaviour it had, and each exclusion is a test:

- a SCALAR keeps `parse_str()` semantics — `?page=1&page=2` is 2. The document declared one value and
  cannot say which repeat was meant; collecting them would hand an array to an `int` cast and turn a
  request that always worked into a type error;
- `?ids[]=1&ids[]=2` is already an array by the time it arrives, and is not collected twice;
- a delimited style carries its elements in ONE value: `form`+`explode: false` (comma),
  `spaceDelimited`, `pipeDelimited` are split, exactly as before;
- `deepObject` and maps arrive through the bracket spelling and are left alone.

A single occurrence is a one-element array and `?ids=` is the empty one, both of which follow from the
style rather than from a special case. Decoding matches `$request->query`, so `+` is a space
(`?tags=a+b` is one element `a b`) while `%2B` stays a literal plus — deliberately unlike the raw view
`allowReserved` needs.

**Why no test caught it:** the one test for `explode: true` handed `['p', 'q']` to the `Request`
constructor — a query array PHP would never have produced from the documented URL. It tested the cast
and could not see the parse. The new tests build every request from a URI string, and the golden
corpus's blind spot is recorded in the todo: `OpenApiExamples/test.yaml` declares no `style`,
`explode`, `deepObject` or `allowEmptyValue` at all.

**A boolean is a schema.** JSON Schema lets `true` and `false` stand where an object schema stands:
`true` accepts every value, `false` accepts none. Every reader here tested `is_array()` first, so the
boolean was silently dropped and the keyword carrying it did nothing at all:

```yaml
tuple:
  type: array
  prefixItems: [{ type: string }, { type: integer }]
  items: false        # closed the tuple in the document, closed nothing in the code
```

`['a', 1, 'extra']` was accepted. So was a property forbidden with `properties: {x: false}`, and
`contains: false`, `not: true`, `if: true`, `allOf: [false]`, `patternProperties: {'^x': false}`,
`propertyNames: false`, `dependentSchemas: {a: false}`. One case failed in the STRICT direction, which
is the worse half: `anyOf: [false, true]` refused a value its `true` branch accepts.

The fix is one rewrite at the entry every schema level already passes through, rather than ten
separate ones: `true` becomes the empty schema, and `false` becomes `not` of the empty schema — which
is exactly "no value satisfies this", since the inner schema accepts the value and `not` then rejects
it. Every reader below sees a shape it already understood. `additionalProperties` and `unevaluated*`
are untouched: the boolean is their ordinary spelling and they always read it.

A document that wrote `false` never mentioned `not`, so the sentence does not either — it reads
`field.1 is not allowed by the schema`, and a real `not` keeps its own wording. **A payload that was
accepted only because the boolean was dropped is now refused**; that is the point, and it is the same
kind of tightening as the `uniqueItems` canonicalisation in 2.15.18.

`oneOf` came out of this correct too. `[true, true]` matches twice and used to report `does not match
any oneOf branch` — the opposite of what happened. It now says `matches more than one allowed oneOf
branch`.

**An empty DTO encodes as `{}`.** `toArray()` returns the honest PHP view, and an empty one is `[]`,
which `json_encode()` writes as an ARRAY where the schema promised an object. The emitted
`jsonSerialize()` has cast that case since it was written; `DtoNormalizer` did not, so a DTO whose
every property was optional and absent went out as `{}` from `$dto->toJson()` and as `[]` from the
normalizer — and the normalizer is the documented path for a response body. Both now agree, through
one private encoder that owns the rule. `toArray()` is unchanged and still returns `[]`: the array
view is not wrong, the ENCODING of an empty one was.

**Three unmeasured paths, now measured** — no behaviour changed, all three were verified correct
before the tests were written:

- the source waterfall in `resolveRawRequestValue()` — path, body, files, query, form — had never
  executed. It exists twice (inlined for a plain-body class, and there for everything else), and a
  GENERATED parameter class binds every property, so the resolver always returned from its first
  branch. A hand-written DTO with a PARTIAL `getParameterSources()` is the shape that reaches it, and
  the files-before-query precedence is pinned on that route too — it was once fixed in the resolver
  alone and the measurement did not budge, because nothing ran it;
- `if`/`then`/`else` in the unevaluated bookkeeping: a conditional owns what the branch it took
  owned, and a FAILED `if` owns nothing. Four combinations, items and properties;
- `deserializeCollection()`'s two entry checks — the media type must be JSON, the text must parse.

Gates: 1704 tests (up from 1666), phpstan / phpcs / cs-fixer clean, generated output unchanged in all
five modes.

## 2.15.26 — 2026-09-03

- the generated DTO's own `toJson()` and `__toString()` stop escaping slashes — the encoded string changes
- an array adder no longer re-raises a presence flag the constructor already pinned

2.15.17 stopped `DtoNormalizer::toJson()` escaping forward slashes and called that method "the
outlier". It was not the only one. The DTO template emits its own `toJson()` and `__toString()`, and
both kept `json_encode()`'s default, so the same DTO encoded two different strings depending on the
route out:

```php
$dto->toJson();                 // {"url":"https:\/\/example.com\/a"}
(new DtoNormalizer())->toJson($dto);  // {"url":"https://example.com/a"}
```

Both are valid JSON and decode identically; only the emitted STRING differed, and it differed
between two methods that a caller reasonably expects to agree. They now pass
`JSON_UNESCAPED_SLASHES` like everything else in the package — the normalizer, `DtoValidator`, the
emitted query-string builders, the generated doc examples — and the standalone enum's `toJson()` with
them. **Anything matching on the escaped spelling of `$dto->toJson()` or `(string)$dto` from 2.15.25 or
earlier needs updating**; the decoded value is unchanged.

The drift had a cause worth naming: the test written in 2.15.17 covered the normalizer only, so the
two template methods were free to stay behind. It now asserts all four exits — `toJson()`,
`validateAndNormalizeToJson()`, `$dto->toJson()` and `(string)$dto` — on one DTO.

**The array adder's flag guard is emitted only where the flag can still be false.** Every array
property gets `addItemToX()`, and it ended with

```php
if (!$this->xInRequest) {
    $this->xInRequest = true;
}
```

For a REQUIRED property the constructor assigns that flag `true` unconditionally, so the branch could
never be taken — dead code in every generated DTO carrying a required array, and a reader has to
reconstruct the constructor to know it. The template now asks the renderer
(`presenceAlwaysTrue`, mirroring the three-way branch in `resolveConstructorParameterData()`) and
emits the guard only for a property whose flag really can still be false at add time: one carrying the
`UnsetValue` sentinel, or an optional path/query/header/cookie parameter with a default, where the
deserializer flips the flag. Across the golden corpus that is 12 adders losing the branch and 23
keeping it. No behaviour changes — the flag ends up true either way.

## 2.15.25 — 2026-09-03

- seven deserializer messages join the shape the other messages have had since 2.15.6
- the discriminator type message reads as a sentence, not as a PHP type union

`DtoValidator::finalizeMessage()` says the full stop is unconditional, and 2.15.6 applied that to
everything the validator writes. Seven messages built inside the deserializer never passed through it:
an unparsable date, an unparsable date-time, an empty date, a nested DTO handed a scalar, and the two
discriminator failures. They are not a separate list — `hydrateFast()` rethrows whatever the cast
closure collected, joined by newlines — so ONE response could read

```
param "count" expects int, got string.
param "begin" expects a date in Y-m-d format (e.g. 2026-03-10), got "nonsense"
```

with the shape changing between two lines about the same body.

They go through the one exit now. It is applied where the sentence is WRITTEN, next to
`expectsTypeMessage()` which has always done it, rather than at the throw in `hydrateFast()`: that code
is emitted into the DTO, and a generated DTO owes nothing to the validator. A message opening with
`param "` keeps the spelling the document chose — `finalizeMessage()` capitalises only a sentence that
starts with an English word.

`param "x" expects string|int discriminator value, got array` also became
`expects a string or int discriminator value, got object.` — the old spelling read as a type union
lifted out of the code rather than as a phrase, and `array` was the wrong word for a decoded JSON
object anyway.

A payload-level test pins the shape mechanically: every line of every deserializer message must end in
a full stop, asserted across seven failure kinds, so the eighth such message cannot slip out
unfinalised. Nothing else changes — the same failures, the same fields, the same order.

## 2.15.24 — 2026-09-02

- an `allOf` child hydrates its inherited array items and tracking flags

The deserializer reads the item type of an array off the docblock of the PROPERTY, and looked it up
with `ReflectionClass::hasProperty()` — which does not see a private property of a parent. Every
generated property is private, and an `allOf` child extends the class its composition resolved to, so
on such a child the inherited properties were invisible. Two consequences, both silent:

- the elements of an inherited array were handed to the constructor exactly as `json_decode()`
  produced them — a `string` where the DTO declares a backed enum, a `stdClass` where it declares a
  nested DTO, a `string` where it declares a date-time. The declaration promised one thing and the
  property held another, and the failure surfaced later, in consumer code that trusted the type;
- the `…InRequest` / `…InPath` / `…InQuery` flag property was not found either, so it was never reset:
  `isXInRequest()` answered TRUE for a field the payload never carried. That is the more dangerous
  half — an absent field reported as sent.

An EMPTY array hides all of it, which is how this passed a test suite and failed on a stand.

The lookup now walks the class hierarchy (`findPropertyInHierarchy()`), and the four places that
needed it use it: `resolveArrayItemType()`, `resolveNestedArrayItemType()` (the second level of
`array<array<T>>`), `resolveTrackingFlagPropertyName()` and `resolveReflectionProperty()` — the last
one because `getProperty()` on the child THROWS for an inherited private property, so fixing only the
flag name would have turned a wrong answer into a `ReflectionException`. A `ReflectionProperty` taken
from the declaring class reads and writes the value on an instance of the child unchanged.

An inherited docblock is also qualified against the class that DECLARES the property rather than the
one being deserialized: a short type name resolves through the imports of the file it was written in,
which is the parent's file when the base came from another namespace.

This is a RUNTIME fix: upgrading the package is enough, the generated DTOs need no regeneration.

## 2.15.23 — 2026-09-02

- a generated enum no longer carries the two identity alias rows

DTOs stopped emitting `$aliases['id'] = 'id'` in 2.15.20 — an alias that renames nothing is a line that
says nothing, every reader spells the lookup `$aliases[$name] ?? $name`. Enums kept theirs: every
runtime-mode enum declared `['name' => 'name', 'value' => 'value']`, the same two dead lines on each
one.

`getAliases()` now returns `[]` there too. The METHOD stays — `GeneratedDtoInterface` declares it — and
the normalized output does not move: `name` and `value` are the keys `getNormalizationMap()` gives, and
the aliases were never what produced them. Standalone enums (symfony, laravel, laravel-data, yii3) never
had the method at all.

## 2.15.22 — 2026-09-01

- a `oneOf`/`anyOf` whose branches disagree about the wire shape is no longer reindexed

`toArray()` decided "this is a list" from the PHP type, and the PHP type cannot carry that decision for
a union: every generic branch widens to `array` before the branches fold into one, so
`anyOf: [{type: array}, {type: object}]` comes out a bare `array` — the same spelling a plain list has,
while `isMap` only recognises `array<string, V>`. Such a property fell through to the list branch and
was written with `array_values()`, which erased the keys of the map form: `{"a":"Alpha"}` went out as
`["Alpha"]`.

Neither cast is right for both branches — `array_values()` breaks the map, `(object)` breaks the list,
and only the value knows which one it is — so a union that admits a non-array branch is now written RAW,
and `json_encode()` reads the shape off the value: string keys make a JSON object, sequential keys a
JSON array. A union whose branches ALL say `type: array` is still a list and is still reindexed;
`type: array` and `additionalProperties` maps are untouched.

The branch shapes are read off the SCHEMA, not the resolved type: `type: [array, object]` (OAS 3.1),
`allOf: [{oneOf: […]}]`, a nested union and a local `$ref` to an array alias all resolve. A branch whose
shape cannot be established — an external `$ref`, a `$ref` cycle — counts as non-array, which is the
safe side: pass-through can only lose a reindex, never a key.

## 2.15.21 — 2026-09-01

- a temporal-item list is reindexed on the way out, matching every other list

`toArray()` has reindexed list properties with `array_values()` since 2.15.13, so a list with holes
never encodes as a JSON object. An array of dates or date-times was the exception: the value goes
through the formatting getter (`getDates()`, `getTimestamps()`, …), and `toArray()` used that result
directly — `array_map` preserves keys, so holes survived.

The getter still returns the array as `array_map` produces it; `toArray()` applies `array_values()`
to the result before putting it in the payload, the same way it handles every other list.

## 2.15.20 — 2026-09-01

- a body-only class no longer carries four empty parameter maps
- `getAliases()` no longer lists the properties it does not rename
- `jsonSerialize()` builds nothing of its own, and stops disagreeing with `toArray()`
- a second `DtoDeserializer` validates with its own validator again

`getParameterSources()`, `getParameterStyles()`, `getParameterAllowReserved()` and
`getParameterAllowEmptyValue()` were emitted on every class, returning `[]` on the great majority of
them: a class whose properties all come from the body has nothing to say about query styles. Fifteen
lines each, four each, fifty classes in the demo corpus alone.

They are simply absent now where they would be empty, and nothing downstream notices: the deserializer
looks them up through `is_callable()` and falls back to `[]` when the method is not there, which is the
same value it read before. A class that EXTENDS or IS EXTENDED keeps all four, because a child's
`parent::getParameterSources()` needs something to reach — that one is measured rather than assumed, the
corpus's discriminated unions and a plain `allOf` base having failed loudly before the rule accounted
for them.

`getAliases()` went the same way, one row at a time. An alias that renames nothing is a line that says
nothing — every reader spells the lookup `$aliases[$name] ?? $name`, so `$aliases['id'] = 'id'` and no
entry at all are the same answer — and the demo corpus carried 119 of those against a single real
rename. The identity rows are gone; the METHOD stays even when the map comes out empty, because unlike
the four above it is declared on `GeneratedDtoInterface` and omitting it leaves every DTO abstract. That
one is measured too: the first attempt to drop it made the whole corpus fatal on load.

`jsonSerialize()` used to carry its own copy of the property loop, and the copy had fallen behind:
`toArray()` reindexes a list with `array_values()` and `jsonSerialize()` did not, so a list with holes
went out of `toJson()` and `__toString()` — the paths that actually reach the wire — as a JSON object
instead of an array. It calls `toArray()` now and adds only the one fact PHP cannot hold, that an object
with nothing to write is `{}` and not `[]`. One builder cannot drift from itself.

**Runtime output is 11% smaller**: 18 513 lines to 16 444 for the demo corpus.

Last, the deserializer's fast path cached the wrong thing. It kept the CASTING CLOSURE per class, and
that closure is bound to the `DtoDeserializer` that built it — so a second instance, constructed with a
different validator, silently validated through the first one's for every class the first had already
seen. A host swapping in a stricter validator got the lenient answer. What is genuinely per-class — the
hydrator check and the parameter-name map — is still cached; the closure is rebuilt per call, which is
not what the original measurement was about.

Emitted blank lines are also normalized once, centrally, rather than per template. A conditional block
that emits nothing still leaves its separator behind, and chasing that whitespace tag by tag is how one
gets fixed and the next four appear — the tail of this change produced three such rounds. Two mechanical
rules now run over every generated file after rendering: never two blank lines in a row, never a blank
line before a closing brace. They are exactly what `EmittedCodeSpacingTest` asserts.

## 2.15.19 — 2026-09-01

- the emitted code had four spacing defects, none of which any build could see

`public function __construct() {}` was followed immediately by
`public static function getDiscriminatorPropertyName()`, with nothing between them. The separating line
was emitted only when the class had properties to declare, and an abstract base whose members all live
in its variants has none — so exactly the class that needs the least code came out misformatted.

It shipped in the golden snapshots from the day the feature landed, and no build ever objected: `composer
cs` reads `src/` and `tests/`, and nothing at all reads the code this generator WRITES.

That absence is the real defect, so the corpus was put through PSR-12 to see what else it had been
hiding. Three more, in four modes:

- **a blank line before a closing brace inside a method**, in every mode carrying the shared interpreter
  (Symfony, Laravel, laravel-data, yii3): its sections are joined with a blank line between them, and one
  section was nothing but the brace closing the block above it, so the separator landed inside the block.
  Six occurrences in yii3 alone.
- **a `use function` group running straight into the class docblock**, on every Laravel file importing a
  function — thirteen of them. Inserting the group consumed the blank line the template had written and
  never put one back.
- **an empty constructor spread over three lines** — `__construct(\n) {` — in Laravel and laravel-data,
  where PSR-12 wants `__construct()`.

With those fixed the emitted corpus reports **zero** PSR-12 errors in runtime, Symfony, laravel-data and
yii3, and one in Laravel: a `match` expression written on a single line inside a closure, which is a
formatting choice about generated expressions rather than a stray character, and is left alone.

The rules that were actually broken are now checked on every run. The snapshots ARE the emitted code,
byte for byte, so the checks read them — no generation, no fixtures, no sniff configuration, all five
modes at once: no member may follow a closing brace without a blank line, no blank line may sit before a
brace closing something inside a method, and no import group may touch what follows it. Run against the
snapshots as they were before this release, they report 6 and 13 offences respectively.

## 2.15.18 — 2026-08-31

- a JSON body under `application/*+json` was never read
- a `discriminator` without `mapping` refused to generate
- Laravel reported one duplicate twice
- a relative `$ref` in `allOf` broke yii3 generation
- `type: [string, object]` generated a file PHP refuses to load
- an empty schema (`{}`) was treated as an absent one
- `uniqueItems` missed a duplicate written in another key order
- an `integer` bound lost precision past 2^53
- an uploaded file was shadowed by a query string of the same name
- `format: uri` refused valid URNs

A second review arrived, and unlike the first one every finding it made held up: eight were checked
against the code, eight were real. What follows is what each one cost and what it costs now.

**A PATCH endpoint could be entirely dead.** The body was read only when the `Content-Type` contained
`application/json`, so `application/merge-patch+json` — RFC 7396, what a PATCH usually sends — decoded
to nothing and every required property came back "not found in request". `application/hal+json` and any
vendor `+json` type behaved the same. `DtoValidator` already had the right rule for `contentMediaType`;
the deserializer now shares it rather than carrying a second one.

**`type: [string, object]` emitted a compile error.** It produced `public readonly string|mixed $v`, and
PHP refuses `mixed` inside a union, so the generated file could not be loaded at all — not a missed
check, a fatal. A member that maps to nothing narrower now collapses the whole union to `mixed`, which
already admits every other member. The unions that are legal keep their narrow form.

**`{}` is a schema, not a hole.** It decodes to an empty PHP array, and three guards read that as "the
keyword is absent": `items: {}` marked no index as evaluated, so `unevaluatedItems: false` cut a valid
array; `contains: {}` matched nothing, so `minContains` could not be met; `additionalProperties: {}` left
extra keys unevaluated for `unevaluatedProperties: false` to reject. All three measured, all three fixed,
including the twins inside the evaluated-set collection.

**`uniqueItems` compares content, not key order.** `[{"a":1,"b":2},{"b":2,"a":1}]` was accepted, because
the fingerprint was the item encoded as it arrived. Objects are canonicalized before comparison now, at
any depth; a LIST is left alone, because there order IS the value. The test that had asserted the old
behaviour — "object fingerprints are order-sensitive" — was codifying an implementation detail, and now
states the rule with the list case beside it.

**An integer bound is exact again.** Every value was cast to float first, and `9007199254740992` and
`9007199254740993` are one float, so `maximum: 9007199254740992` accepted the value above it. The
comparison stays on integers while both sides are integers.

**A query string no longer shadows an upload.** `POST /upload?avatar=ignored` with `avatar` in the
multipart body handed a `string` to a property typed `UploadedFile`. Files come before query now, and
only for files — a query value still beats a form field, because for a scalar the document cannot say
which was meant, while a query string is never an upload. The swap had to be made in two places, the
resolver and the inlined fast path, and the measurement did not move until both were done.

**`format: uri` accepts URIs that are not URLs.** `urn:isbn:…`, `urn:uuid:…`, `mailto:`, `tel:` were
refused by the URL filter. A scheme-only branch accepts them; anything with an authority still goes
through the filter, so `http://[` stays refused rather than slipping in behind the new rule.

**`uniqueItems` is one message again in Laravel mode.** It was emitted as Laravel's `distinct` rule, and
that rule is right about WHETHER and wrong about HOW MANY: it reports once per offending element, so one
duplicated pair produced two `validation.distinct` messages where every other mode reports the array's
single violation once. Measured on scalar, union-valued and object items — the object case already
belonged to the interpreter, because `distinct` cannot compare objects, so the keyword had two owners
depending on the item type. It has one now, in every mode, with the same sentence. The cost is the native
translatable string for the scalar case, and it is the right trade: a rule that miscounts breaks the
invariant every `InterpreterMessageParityTest` case exists to protect. The test case moved with the
keyword — out of "the rules alone must reject this" and into "the interpreter enforces what rules
cannot".

Two more of the review's findings were measured and fixed in the same release. A `discriminator`
**without** an explicit `mapping` used to stop generation dead — `Discriminator mapping must be a
non-empty map` — although OAS says the implicit value for each member is the schema NAME its `$ref`
points at, which is how most documents are written. The implicit map is built from the union's members
now; an explicit mapping still wins, and a hand-written empty one is still an error.

And the flag that says "nothing materializes here, keep the composition" was lost one hop into
`contentSchema`, `not`, `if`/`then` and `contains`: the four modes sharing one constraint filter emitted
`{}` for a branch carrying `required` and a bound, while runtime kept it. Measured per keyword, threaded
through, pinned by `ValidationParityTest`.

**A relative `$ref` inside an `allOf` now resolves against the file that WROTE it**, in every mode.
`sub/child.yaml` naming `../common/base.yaml#/…` means a sibling of `sub/`; yii3 mode resolved it from
the ROOT document and generation died with `Referenced OpenAPI file not found`. That one is a regression
this project introduced in 2.15.12, in the helper that merges an `allOf` class schema, and it stayed
invisible because no fixture had three files. It has one now, driven through all five modes.

Two findings did not reproduce and are recorded rather than "fixed": the type-kind cache storing
`unknown` (a real autoloader is what `class_exists()` triggers, so the entry is never written for a
loadable class) and two deserializer instances sharing a cached closure (both answered identically).
Reproducing either needs a setup no application has by accident.

One finding could not be fixed and says so in the code: `uint64` at its own boundary. Its maximum and the
first value past it are the same double after `json_decode()`, so a guard that refuses the boundary
refuses a legal value. Both directions were tried; the boundary is accepted, and a field needing the
exact range belongs in a `type: string` with a `pattern`. `int64` has no such gap.

## 2.15.17 — 2026-08-31

- `toJson()` no longer escapes slashes — the encoded string changes
- three limits an external review found are now written down
- two suspected defects measured and pinned as correct behaviour

Nothing changes about what a payload is accepted or refused for; one thing changes about how an accepted
one is ENCODED, and it is the first bullet. This release answers a code review by
checking each of its findings against the code and then writing down what is true, because a limit a
reader cannot see is indistinguishable from a bug.

**`uri-reference` is no stricter than whitespace.** A reference may be relative, so `not_a_uri` and
`###` are legal and accepted — any conforming validator accepts them. What the check does not do is
parse the grammar: `%zz` and `http://[` pass here while the stricter `uri` refuses both. The asymmetry
was documented for `uri`/`iri` and not for the reference forms; it is documented now, and pinned from
both sides by `DtoValidatorTest`.

**`toJson()` stopped escaping forward slashes.** A URL now reads `https://example.com/a` where it read
`https:\/\/example.com\/a`. Both are valid JSON and decode identically, and the first instinct was to
document the escaping rather than change it — until the codebase answered the question: `DtoValidator`,
the emitted query-string builders and the generated doc examples all pass `JSON_UNESCAPED_SLASHES`
already, so this one method was the outlier, not the convention. No test or snapshot depended on the
escaped spelling. **Anything matching on the encoded STRING from 2.15.16 or earlier needs updating**; the
decoded value is unchanged, and `validateAndNormalizeToJson()` follows the same rule.

**The static per-class caches are safe without locking**, and the reason is idempotence rather than a
mutex: every entry is derived from the class alone, so two coroutines racing on the first request for one
class compute and store the same value. `DtoValidator` said so already; `DtoDeserializer`, which holds
more of them, did not.

Two smaller notes are answered where the code is, not in a changelog: `contentEncoding: base64` accepts
the empty string (empty content is validly encoded as nothing — pair it with `minLength: 1` when the
field must carry something), and the yii3 renderer's namespace field now states its invariant, because
the review was right that a new caller of `yii3Lib()` forgetting to set it would silently stop finding
name collisions. Two other findings needed nothing: the Request attribute mirroring already documents
that it never overwrites an existing attribute, and the narrow `ReflectionException | Error` catch
already says which failures are artifacts of a constructor-less instance and which propagate.

Two review findings turned out not to be defects, and both are now tests rather than assurances.
`multipleOf` holds at any scale — the tolerance is on the ratio, so `1e-10` behaves exactly like `0.5`.
A collection error carries ONE `Element N:` prefix however deep the violation sits, because the inner
half of the sentence is a dotted path rather than a second prefix. The third, a "dirty generation state"
risk, was measured across two documents from one Command instance with identical output either way; the
render state is cleared anyway, as hygiene next to the Symfony reset that already existed.

## 2.15.16 — 2026-08-31

- a `$ref` under ANY applicator was checked by nothing
- runtime mode never checked what a document says about the OBJECT

`not` was held back on purpose the release before: an empty subschema matches
everything, so a dropped `not` accepts what it should reject — while a wrongly inlined one would reject
what it should accept, and being wrong that way costs valid requests rather than silent passes. Measured
before it was added: `not: {$ref Forbidden}` accepted the very payload the reference forbids, on a
property and under a container alike, while the same subschema written inline refused it. The VALID
payload was accepted in all four cases, which is what said the inlining cannot overshoot here — and an
unresolvable `$ref` is still left alone, because the inlining only ever replaces a reference it resolved.

Sweeping the rest of the vocabulary then found the same loss in five more keywords: `if`, `then`, `else`,
`dependentSchemas`, `patternProperties` and `propertyNames` all dropped the reference and the keyword
with it. They are walked now too, so a `$ref` under ANY applicator carries what it names.

`if` is the one worth reading twice. An empty condition matches EVERYTHING, so before this the choice was
between `then` applying to every value — a false rejection, which is why the conditional used to be
dropped outright — and no conditional at all. With the reference resolved the condition is real: `then`
fires where the document says and nowhere else. The regression test that had codified the old workaround
by asserting the ABSENCE of the `if` key now asserts the behaviour it was protecting, from both sides.

**Runtime mode had one schema-level slot and it carried the closed-object flag alone.** Everything else a
document states about the object as a whole — `minProperties` / `maxProperties`, `dependentRequired`,
`dependentSchemas`, `not`, `propertyNames`, `patternProperties`, a top-level `if` / `then` / `else` — was
emitted nowhere and checked by nothing, while Symfony and yii3 refused the same payloads. The slot now
carries them, and both entrances ask: the deserializer of the RAW body, and `validate()` of a DTO built
by hand, which never passed through the deserializer. `properties` and `required` stay out of that slot
by construction — the per-property map and the required flags already own them — so one violation is
still one message.

**All five modes answer now, and the last two took their own shapes.** Laravel and laravel-data enter
their interpreter per property, so the object's own rules ride in the same map under a reserved key that
no payload can carry — `withValidator()` skips it in the loop and runs it against the whole payload
instead. The pass is emitted only where a document states something object-wide, so every other class
stays byte-identical; the golden diff is one block per mode.

Symfony carried three of the four measured keywords and lost `minProperties`: on a PROPERTY that one
becomes `#[Assert\Count]`, so the interpreter drops it to avoid doubling — and at the class level there
is no attribute to take over, because `Assert\Count` measures an array-typed value rather than the DTO.
It is kept for the class schema alone, which is where nothing else can assert it.

`properties` and `required` stay out of the object-wide set in every mode by construction: the
per-property map, the required flags and Laravel's rule map already own them. `ValidationParityTest`
holds one case per keyword across all five modes, so a mode drifting back to silence fails rather than
passes.

## 2.15.15 — 2026-08-31

- `then` and `else` carrying `properties` checked nothing

A generated DTO validates its own fields against its own constraints, so the enclosing schema strips the
`properties` RULES before re-checking the value — without that, every message arrives twice. The strip
was applied to a CONDITIONAL branch as well, and there it is wrong: `then` and `else` are rules the
parent applies on top of the class, and the class has never heard of them. Measured:
`then: {properties: {code: {minLength: 7}}}` accepted `"short"`, while `then: {required: [code]}` —
which the strip leaves alone — had been enforced all along, which is why the gap looked like support for
the keyword rather than the absence of it.

Only runtime mode was affected; the other four already enforced it, and `allOf`, `anyOf`, `oneOf` and
`dependentSchemas` were right before this change. All six branch kinds are now asserted side by side, so
a future fix that quietly accepts less shows up as a failure rather than as silence.

**A limit is now documented rather than implied.** A conditional written on a PROPERTY is enforced in
every mode; written at the TOP LEVEL of a component, `if` / `then` / `else` is carried into the
constraint map by Symfony and yii3 only — runtime, laravel and laravel-data accept the object unchecked.
Measured in all five modes. The neighbouring object-wide keywords (`minProperties`, `dependentRequired`,
`not`) are emitted just as unevenly at that level, only yii3 carrying all of them, and their enforcement
per mode is not yet measured — so the row says that rather than implying a clean answer. Workaround:
state the rule on the property, or nest the object one level.

## 2.15.14 — 2026-08-31

- a `$ref` under `contains` / `prefixItems` / `unevaluatedItems` was checked by nothing
- `unevaluatedItems` as a schema is enforced, not only as `false`

Three applicators describe a value nothing materializes for: no property to type, no class to write. A
`$ref` under them was therefore left to the scrubber, and dropping it dropped the whole KEYWORD — a
subschema that extracts to `[]` matches everything, so `contains` stopped requiring, `prefixItems`
stopped constraining the position and `unevaluatedItems` stopped guarding the tail. Measured against the
identical document with the subschema written INLINE, which refused what the `$ref` spelling accepted.
This is the 2.15.6 union-branch bug and the 2.15.11 `allOf` bug in a third spelling, and unlike `allOf`
it is not confined to containers: nothing materializes for these keywords at any level.

`not` is deliberately left alone. An empty subschema matches everything, so a dropped `not` accepts
where it should reject — but an inlined one would reject where it should accept, and being wrong there
costs valid requests rather than silent passes. It gets its own measurement.

**Symfony, Laravel, laravel-data and yii3 needed a second fix.** The interpreter they share read
`unevaluatedItems` only in its `false` spelling — "no extra items allowed" — and ignored the SCHEMA
spelling entirely, which is the one a document uses to type the tail after `prefixItems`. It was carried
in the emitted constants and read by nothing, so every item past the prefix was accepted whatever it
held. Runtime had always read both; now all five agree, per keyword, pinned by `ValidationParityTest`.

## 2.15.13 — 2026-08-31

- a list with gaps in its PHP keys was written as a JSON object

`type: array` promises an array on the wire and says nothing about PHP keys — but `json_encode()` does:
hand the constructor the result of an `array_filter()` and `[0 => "a", 2 => "c"]` goes out as
`{"0":"a","2":"c"}`, an object where the document said list. `validate()` has always reported this
(`field "tags" must be a JSON array (list with sequential keys)…`), so a consumer who validates was
never in the dark — but one who only serializes had nothing to notice.

The emitted `toArray()` now reindexes a property declared as a LIST. It is unconditional rather than
opt-in, because for `type: array` the keys are never part of the contract, and an opt-in keyword would
have left every document that does not know about it emitting the broken form. A MAP is untouched:
`additionalProperties` makes the keys the data, and both halves are asserted side by side.

Nothing is silenced. `validate()` reads the property, not this view, so a payload assembled with holes
is still reported — the wire form is corrected, the mistake is still named.

Two modes emit their own `toArray()` and both got it: runtime and laravel. Symfony, laravel-data and
yii3 serialize through their framework or library, which this generator does not emit, so the same
document in those modes still depends on what that serializer does with a keyed array.

## 2.15.12 — 2026-08-31

- yii3: a class written as `allOf` asserted nothing at all

`Cat: {allOf: [$ref Pet, {required: [meow], …}]}` is how a document spells a class with a parent, and
the generator has always read it that way for SHAPE: `analyzeSchema()` merges the inline branches, so
`Cat` is emitted carrying `$meow` and extending nothing it should not. The CONSTRAINT path read the raw
schema instead, where the only top-level keyword is `allOf` — which the yii3 interpreter does not read.
The class came out with no constraints at all, so a payload missing `meow` was accepted, and so was one
missing the parent's `name`. The other four modes reported each once; this was measured against them.

The constraint path now sees the same object the class is: inline branches merged, and a `$ref` branch
resolved and merged too, so a parent's `required` is asserted for the child that inherits it. It does
not double-report, and the reason is the pruning that was already there — `minLength` on an inherited
property is covered by the emitted native rule and subtracted, so what reaches the interpreter is the
`required` list no rule expresses. One violation, one message, measured per case.

Only yii3 changed: `runtime`, `symfony`, `laravel` and `laravel-data` snapshots are byte-identical, and
in yii3 exactly the six `allOf`-shaped classes of the corpus gained their checks.

A WRONG TYPE inside such a class is still accepted in yii3, and that is the framework, not this: the
hydrator's `PhpNativeTypeCaster` turns `5` into `"5"` before any rule runs. It is declared for every
scalar case in `ValidationParityTest` and pinned again here.

## 2.15.11 — 2026-08-28

- `items: {allOf: [...]}` was checked by nothing, in every mode
- Symfony's interpreter learnt `allOf`; Laravel stopped repeating what its rules already say

`allOf` was the one composition keyword the inlining skipped, on a rule that reads reasonably: a
single-`$ref` `allOf` is how this generator spells inheritance, and that ref DOES become a class which
checks itself. Measured, the rule holds on a PROPERTY and inside a MAP, and fails under `items`: there
the value collapses to a bare `array`, no class is written for the item, and the emitted constraints
were `['type' => 'array']` and nothing else. `required`, every bound, and every level below them were
accepted in silence, while the identical chain spelled with `oneOf` was refused. Branches are walked
now under the same guard as a union branch — only where nothing materializes — so inheritance on a
property is untouched.

Symfony needed a second half: its emitted interpreter had no `allOf` at all, so the keyword reached the
generated constants and was read by nothing. It has the section now, and a `$ref` chain under `items`
is refused in all five modes — pinned per level and per spelling by `ValidationParityTest`.

**Laravel says less, not more.** Teaching the interpreter a keyword the rule map also covers is how one
violation becomes two messages, and `bag.test` did report both `validation.string` and
`bag.test must be of type string`. The rule-coverage pruning now descends into `allOf` branches, so a
branch keeps only what no rule expresses — a `required` list, typically — and each violation is
reported once. `InterpreterMessageParityTest` holds that per mode, yii3 included, where a property-level
`allOf` still reports nothing: its constraints never carried the keyword, and that gap is pinned as it
is rather than left to be rediscovered.

## 2.15.10 — 2026-08-28

- the inline ceiling now says so instead of truncating in silence
- the union chain is pinned in all five modes, not just runtime
- both orders of the 3.1 nullable type array are pinned

2.15.9 documented a limit: a `$ref` chain below materialization is inlined five hops deep, and anything
past that is accepted unchecked. Documented is not the same as visible — the generated code does not
mention those levels at all, so a consumer whose document is that deep had nothing to notice. The
generator now warns, once per cut point, naming the `$ref` where checking stops and what it costs.

A recursive schema does NOT warn. It is stopped by the other guard, has no finite inline form to ask
for, and there is nothing for the reader to act on. Measured before the warning was written: across the
demo corpus and the whole test suite the ceiling is reached ZERO times and the cycle guard 107 — which
is the difference between a warning that means something and noise on every recursive document.

The chain fixed in 2.15.8 and 2.15.9 had only ever been measured in runtime mode. Measured now in all
five at the old ceiling, the fourth hop was accepted in silence in every one of them, so neither the gap
nor the fix was ever runtime-specific. `ValidationParityTest` carries a case per level of the chain.

No behaviour changed for `type: ["null", "string"]` — it was already read exactly like
`["string", "null"]`, at the property level and inside a container. It had simply never been pinned:
every test in the repository wrote `null` last. A permission a document may spell two ways is two
chances to diverge later, so both orders now carry a case, in all five modes.

## 2.15.9 — 2026-08-28

- a `$ref` chain under a union branch is checked at every hop, not the first two

2.15.8 moved the border and left it in place: the inlining that carries a `$ref`'s rules into the
emitted constraints counted HOPS, so `A -> B -> C` was checked and `A -> B -> C -> D` was not. Under a
`oneOf` below a container nothing materializes — the property type collapses to `array` — so there is
no class on the path to catch what the constraints miss, and the level past the count passed in
silence. Raising the count would have moved the border again.

What sets it is asking the right question. A hop count was standing in for a CYCLE guard, and the two
are not the same: a chain that names something new at every hop is not a cycle and is now inlined for
as long as it keeps doing so. The guard proper is a per-path count of what has already been inlined,
which unrolls a self-referential component twice and stops — the depth the hop budget gave it, so the
recursive forms in the corpus are byte-identical in four modes and gain a level in the fifth.

That count was tried in 2.15.5 and exhausted memory. The reason is gone: back then the inlining ran on
every re-entry of `extractValidationConstraints()` — once per `oneOf` branch, once for `not`, once more
from the scrubber — and each re-entry started a fresh set, so a recursive component peeled off one more
level per pass. Since 2.15.6 it runs once, in the outermost extraction, and walks the whole tree itself.
Measured on the recursive corpus forms: 12 MB and 0.04s, the same numbers a hop budget gave.

One test had to be rewritten rather than re-expected. `testAUnionBranchUnderAContainerCarriesItsConstraints()`
asserted a SUBSTRING and called it "exactly one level deep" — but the innermost level satisfies that
substring at any depth, so it passed whether the component was unrolled once or ten times. It counts the
unrolls now, and fails at one and at three.

**A hop ceiling remains, at five, and it is now documented.** A count of repeats cannot see the other
runaway shape — an ACYCLIC document that branches, where nothing ever repeats. Measured on three
distinct components per level, each referring to all three of the next, the emitted constraints grow
about 2.9x per level: 70 KB at depth five, 194 KB at six, 568 KB and 68 MB at seven. Five hops is where
that curve is still cheap and deeper than any chain measured in a real document. Past it, values are
accepted unchecked — see "Not generated in any mode" in the support matrix.

## 2.15.8 — 2026-08-28

- a `$ref` chain under a union branch went unchecked past its first hop

2.15.6 taught the branch of a `oneOf`/`anyOf` below a container to carry its `$ref`'s constraints, one
level and no further. One level is enough wherever the chain materializes: `items: {$ref: B}` becomes a
DTO, that DTO runs the same rule for itself, and C is reached the next level down. Under a union branch
below a container nothing materializes — the property type collapses to `array` — so the chain stopped
at B. `A -> B -> C` with `minLength: 3` on `C.code` reported nothing at all for
`{"chain":[{"links":[{"code":"x"}]}]}`, while the same payload through the plain `$ref` chain was
refused. Validation passing where it should fail, in silence.

The one-level stop was not an oversight: a per-path "already seen" set had exhausted memory on a
recursive component in 2.15.5, because the extraction re-enters itself per union branch and each entry
started a fresh set. What replaces it is a BUDGET of two — it counts down along the path and cannot be
refilled by descending, so a self-referential schema still terminates. Raising it further multiplies
work per nesting level, so it moves only against a measured miss.

## 2.15.7 — 2026-08-28

- a container value the schema lets be null was refused
- an object two containers deep is hydrated, not a `stdClass`
- `nullable` alone no longer emits a Symfony interpreter
- a nested item's error path is quoted whole

**A message changed shape.** An error inside a `$ref`ed object two containers deep now reads
`param "tagRows.0.0.id" must be greater than or equal to 5.` where it read `tagRows".0.0.id must be
greater than or equal to 5.` The report moved to the cast that already handles a DTO one level up, and
that one has always quoted the whole path. Anything matching on the old spelling needs updating.

`nullable` on a container value is a permission, and each of the four ways to write it was wrong in its
own way. The 3.0 map value died in the cast with `expects string, got null`, because only `items` was
consulted for the permission and never `additionalProperties`. The 3.0 list declared `array<?string>`
honestly, but the docblock parser split on `|` alone and read `?string` as one unknown name, so the null
came back as `returned null but type is non-nullable string`. The 3.1 list lost its item type outright
and declared a bare `array`, whose items are never checked at all. The 3.1 map declared
`array<string, string>`, forbidding the null the document had just allowed. All four agree now, and a
non-null value of the wrong type is still refused, once.

Hydration reaches two containers deep in all four spellings — list of lists, list of maps, map of lists,
map of maps. `array<array<Tag>>` holds real `Tag` instances; until now it held the `stdClass`
`json_decode()` produced, which is why 2.15.4 had replaced the declaration with the true-but-poorer
`mixed`. Checking already reached that depth in 2.15.5, so what was missing was the typed object, not
the checks. Three containers deep nothing is hydrated and the declaration still says `mixed`. A nested
SCALAR is deliberately left where it was: it is already the value it claims to be, and casting it would
only reword a message consumers have had for three releases.

## 2.15.6 — 2026-08-27

- a `$ref` in a `oneOf`/`anyOf` branch under a container was checked by nothing
- a `$ref` to a CONTAINER component crashed the deserializer, or lost the keys in silence
- one shape for every message: a full stop, and a capital only where a sentence starts
- a map and a list of the same schema now answer alike, `42.0` included
- the docs stopped showing messages the code no longer writes

Two of these came from a real document that a consumer had been running for a year with its response
validation switched off by hand, because the generated checks were unusable on that schema.

**A union branch under a container.** `additionalProperties: {oneOf: [{$ref: Leaf}, …]}` emitted
`['type' => 'object']` and nothing else: every value under it was accepted in silence. The inlining
that turns a `$ref` into constraints walked containers and properties but not union BRANCHES, so the
branch stayed a bare `$ref`, extracted to nothing, and — because one empty branch makes a union
unenforceable — took the whole subschema with it. Branches are walked now, and only where it is true
that nothing materializes: on a PROPERTY an inline union becomes a PHP union type (`Circle|Square`),
the value is a generated DTO and validates itself, so that case is left alone. `allOf` is not walked
either, because a single-`$ref` `allOf` is how this generator spells inheritance and that ref does get
a class.

The first attempt at this exhausted memory on a self-referential component. `extractValidationConstraints()`
re-enters itself once per union branch, once for `not`, and four more times from the scrubber, and an
inlining that ran on every entry peeled one more level off the recursion per pass. It runs in the
OUTERMOST extraction now and walks the whole tree itself, one level per path.

**A `$ref` to a container component.** A component whose top level is `type: array` or a map is a type
ALIAS, not an object — which the property level has known for releases, comment and all. Under `items`
it was named as a class instead, and the two shapes failed differently: `array<StringList>` for a
`type: array` component, where no `StringList.php` is ever written, so a VALID payload died with
`unknown type` and the endpoint could not be called; `array<CountMap>` for a map component, where the
class IS written and is empty, so `{"f":[{"a":7}]}` came back as `{"f":[{}]}` with the keys gone and
nothing reported. Both branches now ask the same resolver the property level asks. A map VALUE that is
such a `$ref` gains the same precision — `array<string, array<string>>` where it used to say `mixed`.

**One shape for every message.** Half of them ended in a full stop and half did not, which reads as a
defect in a list joined behind `DTO validation failed:`. All of them do now. Capitalisation follows the
grammar rather than a blanket rule: `Required parameter "id" not found in request.` is a sentence with
its own subject and takes a capital, while `param "f" expects int, got string.` opens with a subject
LABEL and does not. That distinction is load-bearing — the same messages are spelled `field "f"` in
Symfony mode and, in Laravel mode, as the bare property path, because Laravel keys its error bag by
that path. Capitalising there would rewrite an identifier the document owns: `children.leaves.title`
is not `Children.leaves.title`. The rule lives in one place (`DtoValidator::finalizeMessage()`) and is
applied at each class's single exit, plus once per emitted interpreter packaging.

**A map and a list of the same schema.** `additionalProperties: {type: integer}` emitted a native
`#[Assert\Type('int')]` for its values while `items: {type: integer}` did not, so a map refused `42.0`
and a list accepted it — and JSON Schema 2020-12 §6.1.1 says a zero-fraction float IS an integer, so
the list was right. The attribute is no longer emitted for a SCALAR map value and `type` goes back to
the callback, exactly as in the list spelling; a map of a CLASS keeps its `Assert\Type`, because
nothing else can say "this is that enum". The support matrix said the `42.0` divergence reached map
values; it no longer does, and now says so.

**Docs.** Eleven examples across six files showed messages in the old shape the moment the shape
changed. A `$ref` to a container component is now a row in both depth tables.

Not changed, deliberately: a wrong TYPE inside a container still produces two messages in Symfony mode,
and the second one is Symfony's own — `Assert\Range` applied to a non-number adds "This value should be
a valid number." A hand-written `#[Assert\All([new Assert\Range(min: 5)])]` produces it too, the user's
translations apply to it, and `InterpreterMessageParityTest` has always held that a framework's own
sentences are not ours to overwrite.


## 2.15.5 — 2026-08-27

- a property name with a quote produced PHP that did not parse
- a `$ref`ed object two containers deep was validated by nothing
- one mistake, one message: Symfony reported container items two and three times
- a bound below ten containers deep was enforced by neither half in Laravel mode
- `bin/benchmark` could not find its autoloader in a dist install
- declarations two deep now say what they hold, not `mixed`

Six defects, five of them found by MEASURING the thing the last release had just documented rather
than by reading it. Three had been shipping for releases.

**A quote in a property name.** `it's` is a name a document is entitled to, and both characters that
end a single-quoted PHP literal — the quote and the backslash — were emitted raw in five places. The
generated file did not parse at all, in runtime and yii3 modes; the other three survived by accident.
One value was being escaped four different ways: the full form in a `|replace(…)` repeated
thirty-seven times across the templates, the quote alone in the yii3 ones, `str_replace` in the
renderers, and nothing whatsoever in five literals. There is now ONE spelling — a `php_string` Twig
filter over the `escapeSingleQuoted()` the renderers already used — and `laravelStringLiteral()`, a
second implementation of the same idea, is gone. The corpus gained the names `it's`, `back\slash` and
`both\'s`, so `testEveryGeneratedCorpusFileParses` answers for all five modes at once; the corpus had
held nothing more awkward than camelCase, which is why this was invisible.

**An object two containers deep.** `array<array<Tag>>` was checked by nothing: a value below its
`minimum`, a missing `required` property, even a bare string where the object belonged — all accepted
in silence. Three separate halves were missing, and each only became visible once the one before it
was fixed. The generator now inlines an object `$ref`'s own `properties`/`required` into the emitted
constraints below the level where materialization stops (with a per-path guard, so a self-referential
component inlines once instead of forever). `DtoValidator` gives a plain `stdClass` the array view the
emitted interpreter already took, without which every object keyword was skipped for a value that was
not a generated DTO — that half was runtime-only, and the parity suite said so by failing on exactly
one column. And Laravel's dotted rules now descend into an item's PROPERTIES (`tagRows.*.*.id`), which
the prune had already been assuming they did.

Hydration still stops there: the value remains the `stdClass` `json_decode()` produced, which is why
the declaration says `mixed`. The support matrix said "shape-checking" was missing; it was more than
the shape, and it now says what is actually absent.

**One mistake, one message, in Symfony mode.** `filterSymfonyValidationConstraints()` states that it
removes what the attributes already enforce "so the callback does not duplicate attribute-based
violations" — and then handed the callback every scalar keyword of every container item, while
`#[Assert\All]` was enforcing them too. Measured: three messages for one wrong map value, two for
every bound. The specs and the keywords they consume are now reported from the same branches
(`scalarConstraintSpecList()`, the shape `laravelSchemaRuleSpec()` has always had), and exactly those
are subtracted from the FIRST container hop. `Assert\All` does not nest, so from the second hop down
the callback keeps everything — which is what makes the paragraph above possible. `type` is never
subtracted for a list: no attribute asserts it, and the callback accepts the integral `42.0` that
JSON Schema calls an integer and `Assert\Type` would refuse. A map whose values are containers now
emits no `#[Assert\All]` at all, because the only thing it had to say was already the callback's.

**A bound ten containers deep, in Laravel mode.** The dotted rules and the coverage test counted depth
differently, so between them they left a hole at one end and an overlap at the other: at twelve
containers the rules had stopped while the coverage test still reported "a rule covers this", and
nothing enforced the bound — runtime rejected the payload, Laravel accepted it; at eight and nine both
claimed it and one mistake was reported twice. Both halves now count `.*` HOPS from the property
against one constant, and a sweep from one to twenty containers has them agreeing everywhere.

**`bin/benchmark` in a dist install.** It resolved its autoloader as `__DIR__ . '/../vendor/autoload.php'`,
a path that does not exist once the package sits in a consumer's `vendor/` — while
`README.performance.md`, which ships in the archive, tells the reader to run exactly that command. It
now walks up for the autoloader the way `bin/console` always has. There were no tests for `bin/` at
all; there are now, driving both binaries from a faked Composer layout.

**Declarations below the first level of items.** They said `mixed` where the value was perfectly
ordinary: a `format: date` two deep is the plain string the payload carried, so is an enum member, and
a `type: number` holds an int and a float in the same array. Each now says so — `array<array<string>>`,
`array<array<float|int>>` — and a `$ref` to a scalar or enum component resolves to its backing type.
`mixed` is left for exactly one shape, the object, where it is the only true answer.

**Also.** Three cross-document links pointed at headings a restructuring had removed, and six
docblocks had been stranded above the declaration that displaced them, leaving their owners with none
— which is how a `missingType.iterableValue` reached PHPStan, and how one comment survived describing
a constant the file no longer had. Both rules are mechanical and are now tests; PHPStan analyses `src`
only, so nothing had been looking at the second one in `tests/`.

No behaviour changed for a document that uses none of the shapes above: what a mode ACCEPTS is
unchanged everywhere except the four cases named here, all of which were payloads the schema forbade.


## 2.15.4 — 2026-08-26

- six bugs in one family: "container plus type"
- laravel casts temporal ITEMS; laravel-data and yii3 declare the strings they hold
- a container inside a container declares what it holds, and its values are checked
- yii3 renders from a template like the other four modes
- one mistake, one message: a map's value schema no longer reports twice
- the Packagist archive drops from 4.3 MB to 1.2 MB

One class of bug, found six times, each faster than the last because the parity matrix and the golden
corpus kept growing: the SCALAR path of a keyword gets its treatment and the ITEMS path does not.

**Temporal container items.** `items: {type: string, format: date}` types the item as
`DateTimeImmutable`, and three modes now really hold one there: runtime and symfony already did, laravel
casts each element in `fromValidated()` and gained the same getter pair the scalar has
(`getDates(): array<string>`, `getDatesAsDateTime()`). Before this its property held the strings
`validated()` handed over while the docblock promised objects — a lie a consumer's PHPStan was believing.
laravel-data and yii3 cannot convert without emitting a class of ours into generated code that depends on
nothing from this package (`#[WithCast]` casts the property and never its items — measured against the
package; the yii3 `#[ToDateTime]` resolver fails on an array outright), so they declare `array<string>`,
which is what they hold. The WIRE form was already identical in all five and still is. A yii3 schema whose
only temporal values are container items no longer pulls in `ext-intl`.

**Containers inside containers.** `array<array<X>>` collapsed to `array<mixed>` — the one item type
`DtoNormalizer::validate()` skips — so a matrix with a scalar where a row belonged passed in silence.
Worse, the map spelling did the opposite: `array<array<string, Tag>>` NAMED a class while holding the
`stdClass` `json_decode()` produced. Both are declarations now: a scalar keeps its type at any depth
(`array<array<int>>`, `array<string, array<string, int>>`) and anything that would need converting down
there says `mixed`. The values are checked in every mode — the emitted interpreter carries the depth-2
`enum` and `$ref`ed enum members (they were dropped by the constraint extractor, which four modes out of
five entered through the class schema rather than the property one), and laravel's dotted rules go as deep
as the schema does (`matrix.*.*`). A `$ref`ed OBJECT two containers deep is still not hydrated in any
mode, and the support matrix says so.

**One mistake, one message.** The Laravel interpreter prune tested `items` and not
`additionalProperties`, so a map's value schema travelled to the interpreter whole and every violation in
it was reported twice — `validation.string` plus `plainMap.k must be of type string`. Symmetric now.

**yii3 renders from a template.** `RendersYii3Dto` was the only mode assembling its class from strings —
59 `$lines[]` sites and five `implode()`s, no `.twig` at all — and that is where the container bug in this mode hid: four dead
`#[ToDateTime]` attributes on array properties, invisible in a diff that a template would have shown. The
markup moved to `dto.yii3.php.twig`; the renderer keeps only the decisions, the same border the other four
draw. Proven neutral block by block: all 40 classes of the yii3 snapshot are byte-identical.
`enum.symfony.php.twig` is now `enum.standalone.php.twig` and `$isSymfony` is
`$rendersStandaloneEnum` — there is one axis, "does the enum carry this package's runtime interface", and
the old names were false for laravel, laravel-data and yii3 alike. A test now holds that axis: the four
non-runtime modes must emit the same enum, byte for byte.

**Also fixed this release.** `DtoNormalizer::extractArrayItemTypeNames()` parses nesting instead of
matching a regex, which had reported the INNER type of a list of maps for any member type. The temporal
mapper tolerates a hand-built DTO holding a string where a date belongs, instead of a `TypeError` from
inside the getter that took `validate()` — the call whose job is to report it — down with it. Laravel
hydrates an array of a discriminated union through the discriminator (it called `fromValidated()` on the
base interface and died with `Call to undefined method`) and a MAP of DTOs at all (a list-only regex fed
nine places); laravel-data gained `#[DataCollectionOf]` on a map from the same fix. An empty nested object
writes `{}` rather than `[]` in every position, in runtime, laravel and yii3. A temporal `default`
emits `new DateTimeImmutable('…')` parsed at generation time, not the unusable
`DateTimeImmutable::VALUE_2020_01_01`. The interpreter shared by symfony and yii3 refuses an overflowing
`date-time` (`2026-13-45T99:99:99+00:00` used to pass `createFromFormat()` silently). The yii3 `ext-intl`
skip is now proof-based — a trial hydration rather than a regex over the spec — which takes the parity
suites from 5 and 9 skips down to 1 and 5, so the yii3 half of 2.15.3 is verified locally and not only on
CI.

**Distribution.** A `.gitattributes` marks `tests/`, `.github/`, `OpenApiExamples/` and the tooling
config `export-ignore`. The Packagist archive goes from 4.3 MB to 1.2 MB (275 KB gzipped); `.claude/` also
stops travelling to consumers. Tags 2.10.0…2.15.3 keep what they shipped — history is not rewritten.

## 2.15.3 — 2026-08-24

- an array of `format: date` items is written back as dates
- the item getter formats, an `AsDateTime` twin keeps the objects
- symfony and yii3 write the same shape
- pinned as normalization parity, across every mode

`items: {type: string, format: date}` types the item as `DateTimeImmutable` — exactly what the scalar
case does — and there the treatment stopped. The scalar has always been READ as the string the schema
asks for (`getSingle(): string` formatting `Y-m-d`); the array handed its objects straight back, and
whatever normalized them printed RFC 3339. So a document declaring an array of dates produced
`["2026-03-10T00:00:00+00:00"]` on the wire: a response contradicting the schema it was generated from,
silently, since nothing validates an item's `format` on the way out.

Runtime and symfony modes now format the items the same way the scalar is formatted. `getDates()` returns
`array<string>`, `getDatesAsDateTime()` returns the `array<DateTimeImmutable>` — the same pairing the
scalar getter has, with the same `#[Ignore]` on the symfony twin so the serializer does not emit both.
`toArray()`/`jsonSerialize()` read the formatting getter, and the `arrayItemType` in the normalization map
says `array<string>` because that is what the getter now hands over. A MAP of temporal values
(`additionalProperties: {format: date}`) is covered by the same path and still encodes as a JSON object;
nullable items and a nullable array keep their nulls.

yii3 already walked arrays in `openApiWireValue()` passing the temporal format down — its
`OPENAPI_TEMPORAL_FORMATS` map simply never listed an array property, because the format sits one level
down in `items`. It is read from there now.

laravel and laravel-data are unchanged: neither converts temporal ITEMS on the way in, so their arrays
were already strings on the way out.

Two cases in `NormalizationParityTest` pin it for all five modes at once — a date array and a date-time
array — so no mode can drift back. Regenerate to pick this up.

## 2.15.2 — 2026-08-21

- yii3 resolves a schema named like a framework class
- one predicate behind the spelling and the import list
- DateTimeImmutable stays reserved, and why
- pin it by hydrating, not by reading the source

A document may call a schema `Result`, `Data`, `Query` or `Nested` — ordinary words for an API — and every
emitted yii3 input carried imports with exactly those short names. PHP then failed two ways, neither
visible from the document: the file DECLARING it did not load at all (`Cannot redeclare X\Result`), and in
a SIBLING file the `use` silently won over the same-namespace class, so a property was typed the
framework's and the payload filling it a `TypeError`. Until now yii3 only warned about this; runtime and
laravel-data have resolved it for a release.

Every framework short name the yii3 renderer writes now goes through `yii3Lib()`, which answers with the
short name or with the fully qualified one, and `yii3SortedImports()` drops the import that would have
collided. Both ask `namespaceDeclaresClass()`, so the body and the import list cannot disagree about which
names belong to the document. Eighteen names are covered, including every `Yiisoft\Validator\Rule\*`
through the one place that emits a rule attribute.

`DateTimeImmutable` is NOT covered and stays in the reserved list. The type a `format: date-time` resolves
to comes from the mode-neutral type mapper, which does not know the namespace, so a schema of that name
silently TYPES the property as its own class — a file that loads and then fails at hydration, which is
worse than one that does not load. Runtime mode has the same gap for the same reason, and both are warned
about at generation time.

Pinned by hydrating rather than by reading the emitted source: both failures are invisible to a source
assertion — one is a parse error, the other a type that reads perfectly fine. Reverting the import filter
makes the new case fail with the original `Cannot redeclare`, which is how it was checked.

Generated output is unchanged for any document that does not take one of those names — all five golden
snapshots are byte-identical.

## 2.15.1 — 2026-08-21

- laravel says why an undiscriminated union cannot hydrate
- declare toArray() on the union interface
- pin what the request sees, not only the warning

`UnhydratableUnionParityTest` has pinned since it was written that a `oneOf`/`anyOf` over objects with no
`discriminator` cannot be hydrated in any mode, and that the generator says so at build time. What it did
not pin is what the REQUEST sees, and laravel mode — the one that writes its own hydrator — wrote
`Shape::fromValidated($data['shape'])` against an interface declaring no such method. A payload the
document allows died on `Error: Call to undefined method UnionWarnLv\Shape::fromValidated()`: a PHP-level
accident naming an internal artefact, for a document-level limitation already reported at generation.

It now throws the sentence the warning carries — *Property "shape" is typed as Shape, a union with no
discriminator: the document does not say which member a payload is, so it cannot be hydrated. Add a
discriminator to Shape, or type the property as one member.* — and a new case in that suite pins it,
verified to fail with the old `Error` when the emitter is reverted.

The generated union interface also declares `toArray(): array` in laravel mode, where an owning DTO's
`toArray()` calls `$this->prop?->toArray()` on it. Every member is a generated DTO and has the method, so
this only writes down what was already true; PHPStan level 8 over the laravel corpus reported the call as
undefined until now. Only laravel mode: nothing calls it through the interface in the other four, and
their union members do not all carry `toArray()`.

Regenerate to pick either up. Runtime, symfony, laravel-data and yii3 output is byte-identical.

## 2.15.0 — 2026-08-21

- a generated fast hydrator for a plain-body schema
- inline the source-resolution decision tree
- one short-circuit chain, not five varargs calls
- hoist the type tests out of the element loops
- decide composition once per element list
- import every global name the emitted code uses
- runtime enforces `additionalProperties: false`
- `unevaluatedProperties: false` closes an object the same way
- emitted only for a schema that says so
- name the Content-Type when the body was not read
- runtime resolves a schema named like a class it imports
- yii3 reports the clash it cannot resolve
- named arguments in the generated constructor call
- state the type the throw already guaranteed
- split the README into comparison / cli / validation
- add README.comparison.md — five tools, run, not read
- maxbeckers runs after all, and invents required values
- re-measure every published figure, mean of two runs

### Faster, with the same verdicts

Three before/after pairs were run against the 2.14.0 tag on the same corpus — two on the host, one in
the project container. The runtime path came out faster in every one, and never slower:

| runtime step | 2.14.0 → 2.15.0 |
|---|---|
| `bind` | 26–39% faster |
| `validate` | 14–21% faster |
| round trip | 19–30% faster |

A range rather than a figure, deliberately: two runs of the SAME build differ by up to 16% on this
benchmark, so any single number inside that band would be a coincidence quoted as a result. Current
absolutes for all five modes are in [README.performance.md](README.performance.md). The other four modes
move much less, and where they move it is their own generated code getting the same treatment — symfony
`normalize` and yii3 `normalize` are the two that show it.

Nothing about what is accepted or refused moved, and that is asserted rather than asserted-to:
`testTheFastHydratorAndTheGeneralLoopAgree` drives thirteen payloads through BOTH routes and compares the
resulting array, every `isXInRequest()` flag and the error text, so the two cannot answer differently.
`testAFormEncodedRequestDoesNotTakeTheFastHydrator` pins which route a request takes, and
`testPlainBodyDtoKeepsTheDeclaredSourcePrecedence` pins the order of the five source checks the inline
copy makes — router attribute, body, query, uploaded file, form.

The generated constructor call now passes NAMED arguments. This is not cosmetic: the plan is built from
the constructor's own parameter list precisely because reading it off the property list once passed a
slug where a status was expected, and a named argument cannot make that mistake at all. Each required
local also carries the type the throw above already guaranteed — the `$cast` closure returns `mixed`, so
without it an analyser reads `$id` as `int|null` against a non-nullable `int` parameter and reports a
`null` no execution can reach.

Four changes carry it. A DTO whose every property is a plain body field — no bound parameter source, no
serialization style, no delimiter, no default, no `readOnly`, no inheritance, no discriminator — now
carries a generated `hydrateFast()`, and the deserializer hands it a closure that owns every decision
ABOUT a value. That division is the whole design: the generated method decides only which keys are
present and in which order the constructor takes them, so it cannot disagree with the general loop about
a cast, a field constraint or the wording of an error. The first attempt had the generated code
re-implement those semantics and broke nineteen tests.

The resolver's decision tree is inlined for that same class of DTO rather than called. `DtoValidator`
lost a varargs helper that was called five times per value in favour of short-circuiting
`array_key_exists` chains, tests the value's type once instead of once per keyword group, and computes
`hasComposition` once per element list instead of once per element. And every global name the emitted
code uses — classes AND functions — is now imported rather than called unqualified: an unqualified call
inside a namespace makes PHP look for a namespaced twin that never exists, which measured ~29 ns a call
and, isolated on the generated hydrator, about 10%.

### A closed object is closed in runtime mode too

Runtime mode is the one mode still holding the RAW body when generated code runs, so it is the one mode
that can see a key the schema never declared — a hydrating mode drops it into no property and never
learns it was there. Generated DTOs of a closed schema now carry `getObjectConstraints()` and the
deserializer refuses the undeclared key; `unevaluatedProperties: false` closes an object the same way.
The method is emitted ONLY for a schema that closes itself, so three modes now agree where two did, and
output for a document that closes nothing is unchanged by this.

### The body that was never read

A request with a JSON body but a `Content-Type` of `text/plain` — or none — was read as an empty payload,
so the client got `param "id" not found in request` for a field it had plainly sent. Only
`application/json` is decoded as a JSON body, deliberately: form and multipart values arrive through
their own sources. The verdict is unchanged, but when the body is the only thing that could have carried
the missing values and it was not read, the exception now says so:

```
Required parameter "id" not found in request.
The request body was not read: Content-Type is "text/plain", and only application/json is decoded
as a JSON body (form and multipart values are read from their own sources).
```

### A schema named like a class the emitted code imports

A document may call a schema `Stringable`, `RuntimeException` or `Result`, and the emitted file carries
imports with exactly those short names. PHP then fails two ways, neither visible from the document: the
file DECLARING it does not load at all (`Cannot redeclare X\Stringable`), and in any SIBLING file the
`use` silently wins over the same-namespace class, so a property is typed the library's class and the
payload that should fill it is a `TypeError`.

laravel-data has resolved this since it was written. That mechanism is now a shared trait,
`NamesLibraryClasses`, and runtime mode uses it: such a schema gets `\Stringable` written out and no
import. `UnsetValue` stays reserved, and not because of the emitter — `DtoDeserializer` recognises the
sentinel by SHORT name on purpose, so that a sentinel copied into your own namespace by
`--dto-generator-namespace` is still recognised, and a schema of that name falls under the same test.
yii3 names roughly forty library short names across its renderer and does not resolve them yet; it is
now in the reserved-name table, so generation reports the clash instead of emitting a file that cannot
load.

### The comparison is in the repository now

The front page makes measured claims about other generators, so the method that produced them belongs
beside it: [README.comparison.md](README.comparison.md) names the versions, the payloads and the
verdicts, and carries a *where we are behind* section — no HTTP client generation, no TypeScript, and
several generation modes is not a distinction (OpenAPI Generator ships nine PHP generators).

One earlier finding was wrong and is corrected there. `maxbeckers/php-openapi-generator` was recorded as
unable to start; it starts fine once `symfony/console` is pinned to `^7`, which composer resolves without
complaint. Having run it: its `fromArray()` reads `$data['id'] ?? 0`, `$data['name'] ?? ''`, so an empty
payload produces a well-formed object with `id = 0` and `name = ''`. That is worse than the missing
validation it shares with the others — nothing downstream can tell invented values from sent ones.

### Upgrading

`composer update`, then **regenerate**: emitted output changed in all five modes. Runtime-mode DTOs gain
`hydrateFast()` and, for a closed schema, `getObjectConstraints()`; every mode's files gain the import
group. No contract, constructor signature or public method changed.

DTOs generated by 2.14.0 keep working against 2.15.0 services unchanged — both new methods are optional
and the services fall back when they are absent. Measured, not assumed: the 2.14.0 corpus was generated
from that tag and driven through 2.15.0's deserializer, validator and normalizer, with the same accept /
refuse verdicts and the same normalized output. You lose the speed-up until you regenerate, nothing else.

## 2.14.0 — 2026-08-19

- add yii3 generation mode
- one `AbstractInput` per schema
- presence from uninitialised typed properties
- no sentinel, no helper class of ours
- `DataSetInterface` + `RulesProviderInterface` together
- `getRules()` or nothing is validated
- yiisoft rules for what they express
- class-level `#[Callback]` for the rest
- keep scalar keywords for the interpreter
- prune what a rule already covers
- `skipOnEmpty: WhenNull` on every rule
- one message for an absent required property
- one message for an explicit null
- `#[Data]` binds an aliased wire name
- `#[Collection]` hydrates lists of DTOs
- `#[ToDateTime]` hydrates temporal properties
- `#[UploadedFiles]` with PSR-7, not Symfony
- `#[FromBody]` only on a request payload
- query and path bind to their own attributes
- `nullable` is the document's, not the type's
- free-form property as a written-out union
- `readOnly` and `writeOnly` out of `getData()`
- interpreter honours an explicit `nullable`
- yii3 keeps sub-second precision, like runtime
- fifth column in every parity suite
- fifth golden corpus snapshot
- fifth column in the benchmark
- add README.yii3.md
- yii3 rows in the support matrix
- fix an exclusive bound in laravel mode
- emit only the numeric checks a schema uses
- drop two constants nothing read
- constants before properties in yii3 output
- no deprecation on a schema without a type
- stack the wire formats a temporal hydrates from
- write a temporal back in its schema's shape
- leave `format: time` to the interpreter

`--attributes=yii3` emits one `yiisoft/input-http` input per schema: `yiisoft/hydrator` fills it from the
request and `yiisoft/validator` reads the emitted attributes. It is the only mode that does NOT turn a
failed validation into a 422 — Yii3 hands the action a `Result` and the action decides, which is how the
framework works and is left alone on purpose. Presence needs no invention either: the class has no
constructor, so an unsent key leaves a typed property uninitialised and `hasProperty()` answers from
`ReflectionProperty::isInitialized()`. The interpreter behind the class-level `#[Callback]` is the same
code Symfony and Laravel mode run, so the messages are identical:
[README.yii3.md](README.yii3.md).

Three changes reach the FOUR existing modes. `minimum: 3` next to `exclusiveMinimum: true` — the
OpenAPI 3.0 spelling of an exclusive bound — was translated to Laravel's inclusive `min:3`, and that
also took the keyword away from the interpreter, so laravel mode ACCEPTED the boundary value every
other mode refused; laravel-data inherited it. The emitted interpreter now steps aside for a `null`
the document explicitly allows instead of adding a second message about it, and it emits only the
numeric checks a schema actually uses rather than all five whenever one is present. runtime-mode
output is byte-identical.

Three temporal bugs in yii3 mode were found by CI, which has **ext-intl** where the development machine
did not, so the cases that catch them were skipped locally. `#[ToDateTime]` takes ONE format and was
emitted with one pattern: `2026-03-10T12:00:00.123456+03:00` parsed as none of it, the hydrator skipped
the property, and the request came back as `field "at" is required` for a value the client had sent.
Several are now STACKED — measured, the hydrator tries each in turn — covering exactly
`GeneratedDtoInterface::DATE_TIME_FORMATS`. `getData()` also wrote a full timestamp for a `format: date`,
where every other mode writes `2026-03-10`. And `format: time` carried a `#[Time]` rule that rejects any
plain string, including the legal `13:45:00Z`; that format goes to the interpreter instead.

## 2.13.0 — 2026-08-18

- carry the items schema into per-element casts
- `format: date` and nullable elements now deserialize
- fix `deserializeCollection()` the same way
- put the two flags on the contract
- return type follows the nullable flag
- pin object-vs-array under assoc input
- say what "in request" means without a request

**`format: date` elements and nullable elements were undeserializable.** `castArrayItemValue()` takes
`itemsNullable` and `arrayItemTemporalFormat`; neither `deserializeValue()` nor `deserializeCollection()`
passed them, so a `type: string, format: date` collection was rejected element by element
(`expects a valid date-time …, got "2026-03-10"`) and a nullable element was rejected as a type error.
A bare type name has no owning DTO property, so the two facts cannot be inferred at these call sites —
they are now parameters:

```php
$deserializer->deserializeValue('2026-03-10', DateTimeImmutable::class, '3', false, 'Y-m-d');
$deserializer->deserializeValue(null, 'int', '3', true);
$deserializer->deserializeCollection($request, DateTimeImmutable::class, false, 'Y-m-d');
$deserializer->deserializeCollection($request, 'int', true);
```

**The declared return follows the nullable flag, so static analysis sees the null.** With
`$nullable`/`$itemsNullable` on, these methods really can hand back `null` (or a list containing
`null`). The types now say it, and this stops passing PHPStan:

```php
$dt = $deserializer->deserializeValue(null, DateTimeImmutable::class, '3', true);
$dt->format('c');   // DateTimeImmutable|null — reported, was silently accepted
```

Leave the flag off and the type stays non-null, resolving to the exact class as before.

Both flags default to the strict behaviour, so existing calls are unchanged. `'Y-m-d'` narrows the cast
rather than disabling it — an invalid date still fails, naming the narrowed format. Not a 2.12.0
regression: `deserializeCollection()` had the same two holes since it was written, which is why both
entry points are fixed together. `deserializeCollectionPsr7()` forwards the two arguments.

**Breaking for implementors of `DtoDeserializerInterface`.** The new parameters are on the CONTRACT,
not on the service alone, so a class of your own still declaring the 2.12.0 arity fatals at autoload:
*"Declaration … must be compatible with …"*. Copy the signatures from the interface. Consumers, type
hints and DI are unaffected, and no existing CALL site changes — every added parameter is optional.

Putting them on the service alone was built first and abandoned, because it cannot be made honest.
A conditional return needs the flag in scope, and an implementation may not widen a return type its
interface narrows: with the flag only on the service, either the service lies about null
(`method.childReturnType` if it does not) or the contract admits a null that its own signature makes
unreachable, taxing every interface-typed call site with checks it can never need. Both were measured;
the contract change is the only shape where each declaration says exactly what its caller can get.
`tests/Runtime/GeneratedConstraintsIntegrationTest` pins interface and service to the same signatures
so they cannot drift apart again.

Generated DTOs are unchanged — output is byte-identical to 2.12.0 in all four modes.

Two behaviours are now pinned rather than changed. A missing required field on the Request-free path
still reports `Required parameter "3.id" not found in request.`; the text comes from the shared
deserialization core, where it is accurate and where changing it would change the Request paths too, so
it stays and the docblocks say to read "in request" as "in the payload". And `deserializeValue()` still
accepts a plain assoc array beside the promised `json_decode($json, false)` output: under assoc input
the object-vs-array check errs toward refusing, so nothing array-shaped is silently accepted — the cost
is one exotic document, an object with sequential integer keys (`{"0":1,"1":2}`), which survives as an
object only in the stdClass decoding. A test now names that asymmetry.

## 2.12.0 — 2026-08-17

- add `deserializeValue()`
- deserialize one decoded JSON value
- per-element errors for batch endpoints
- new method on `DtoDeserializerInterface`

`deserializeCollection()` is all-or-nothing: it aggregates every element's error into one exception, so
a single malformed element fails the whole body. `deserializeValue($data, $type, $path)` exposes the
per-element cast on its own — no `Request`, one already-decoded JSON value in, one DTO (or scalar, enum,
date) out, discriminator resolution included. A batch endpoint loops over the decoded body itself,
catches each `RuntimeException` and answers "element 3 was rejected, the rest were accepted". `$path`
names the value in the error message, so pass the element's position; it defaults to `value`. Example:
[README.runtime.md](README.runtime.md#batch-endpoints-accepting-the-good-elements-reporting-the-bad-ones).

**Breaking for implementors of `DtoDeserializerInterface`.** The method was added to the interface, not
only to `DtoDeserializer`, so a class of your own implementing that interface must add it. Consumers of
the interface (type hints, DI) are unaffected, and generated DTOs are unchanged — output is byte-identical
to 2.11.0 in all four modes.

## 2.11.0 — 2026-08-13

- add laravel-data generation mode
- one Data class per schema
- Optional for presence, no emitted flags
- null and Optional as independent types
- reuse the laravel rule translator and interpreter
- no MergeValidationRules: rules() is the truth
- morph base for discriminated unions
- #[Hidden] for writeOnly
- schema formats on the date cast
- suppress inferred nested rules
- one message per mistake in laravel-data mode
- 39% off the laravel-data validate step
- mode list is data, not three columns
- fourth column in every parity suite
- fourth golden corpus snapshot
- guard every emitted attribute resolves
- laravel-data container without testbench
- add README.laravel-data.md
- fourth column in the benchmark
- list every mode in the --attributes error
- warn on an undiscriminated object union
- accept null beside a union
- fix `mixed` inside a union type
- map a discriminator's wire name
- enforce a self-recursive root schema
- drop an unused generated import
- survive a schema named like an import
- warn on a schema named like a used class
- pin what a schema `default` normalizes to

`--attributes=laravel-data` emits ONE `spatie/laravel-data` class per schema instead of the FormRequest +
DTO pair `--attributes=laravel` emits. It is the only mode whose generated code needs a third-party
package, which is why it is opt-in and why first-party Laravel stays the default. What it buys is presence
as a language-level fact: an optional property is `string|Optional`, an unprovided one IS an `Optional`,
and laravel-data omits it from `toArray()` — no flag array, no `fromValidated()` factory, no hydration code
of ours. `null` and `Optional` are separate union members, so `nullable` follows the document alone. The
rule translator and the interpreter are the SAME code laravel mode uses, so the messages are identical:
[README.laravel-data.md](README.laravel-data.md).

Discriminated unions are the one place the emitted class SHAPE differs between modes. laravel-data cannot
hydrate an interface, so the base is an abstract `Data` implementing `PropertyMorphableData`, its `morph()`
maps the discriminator value to a member, and members `extends` it and forward the discriminator. An
unmapped value comes back as a 422 rather than an exception to translate.

Two decisions are measurements, not preferences. The class carries no `#[MergeValidationRules]`: with it,
laravel-data's inferred rules are merged into ours, duplicating them and prepending a `nullable` the schema
never asked for. And a nested-`Data` property carries `#[WithoutValidation]`: overriding `rules()` does not
stop laravel-data injecting its own nested rule resolution, which reported one missing nested key twice —
once as `validation.present`, once as the interpreter's `tags[0].id is required`. Removing it also took 39%
off the validate step and 45% off `from($request)`.

A property whose schema is a union of OBJECTS with no `discriminator` is now named at GENERATION time.
Nothing can hydrate one — the document does not say which member a payload is — and every mode used to
find that out at request time, late and differently: `Unsupported type: Shape` in runtime mode, a
`NotNormalizableValueException` in symfony, and in the two Laravel modes a `Call to undefined method
Shape::fromValidated()` and a `TypeError`, both of which read as bugs in the generated code rather than as
a gap in the document. Generation still succeeds — the interface is a useful type, and a union referenced
only in a response is never hydrated — and the warning names the property and the remedy. The demo corpus
has one such property, which is how ordinary the shape turns out to be.

`nullable: true` NEXT TO a `oneOf`/`anyOf` now means what it says. It is the same statement as spelling a
`{type: null}` variant INSIDE the union, but only the spelled form reached the emitted interpreter — so a
document written the first way had its own `null` refused with "does not match any oneOf branch (expected
integer or string, got null)" in symfony, laravel and laravel-data mode. Runtime mode reads the schema
directly and always accepted it, so three modes were wrong about a value the document explicitly allows.
Both spellings are now held to one verdict by `ValidationParityTest`.

A property with NO type in its schema — an empty schema, or one carrying only a description or an
extension keyword — no longer breaks RUNTIME mode. It resolves to `mixed`, `mixed` cannot take part in a
union or be marked nullable, and the emitted class said `mixed|UnsetValue|null` and `?mixed`: two
compile-time fatals, so the file could not be loaded at all. `mixed` already admits the sentinel and null,
so it now stands alone and presence tracking is unaffected. laravel-data mode has the same constraint from
the other side — it is the one property shape with no `|Optional` to mark absence with, so an unprovided
one is echoed as `null`, a divergence now declared in the parity suite.

A `discriminator` whose `propertyName` is not already a PHP identifier — `pet_type`, the name OpenAPI's own
Pet example uses — works in laravel-data mode now. The morph base reads the discriminator BEFORE there is
an object to read it from, and `DataMorphClassResolver` looks the value up by the property name and by its
INPUT-MAPPED name; the base carried `#[PropertyForMorph]` but no `#[MapName]`, so neither name matched the
payload and a document the other three modes hydrate came back as `validation.required` on a key nobody
sent. `NormalizationParityTest` now runs the union under both spellings, and `MorphDiscriminatorTest`
every case twice.

A schema that refers to ITSELF from the root class is enforced at every depth in the two Laravel modes.
Both halves of the pair had a hole exactly where they met: the flat rules cannot expand a cycle, so no
`children.*` path was emitted, while the interpreter treated the first `$ref` back to the root as a fresh
class — expanded one level and PRUNED of everything the rules were assumed to cover. Measured: a child
violating `minimum: 1` was ACCEPTED, and a child sending a string for an integer surfaced as a `TypeError`
from the constructor rather than a 422. The cycle guard is now seeded with the owner class, so the root
folds like any other recursive class. The recursion suite only ever reached such a schema through a
non-recursive root, which is why it saw none of this; it now covers both entries.

A document may call a schema `Data`, `Optional`, `Request`, `Validator` or `Container`, and laravel-data
mode imports classes with all five of those short names. PHP then resolved the clash two ways, neither of
them the document's: the file DECLARING that class did not load at all (`Cannot redeclare X\Data,
previously declared as local import`), and in a SIBLING file the `use` quietly won over the
same-namespace class, so a property typed `Request` was Illuminate's and the payload meant for it a
`TypeError`. Every class this mode needs is now spelled fully qualified when the document has taken its
name, and imported only when it has not — `EmissionEdgeCasesTest` drives all five through the package.

An eleventh divergence was there all along and nothing measured it: an unprovided optional property is
left out of `toArray()` by runtime, laravel and laravel-data, and comes back as `null` from Symfony —
as the schema's `default` where the document declared one, because the constructor default IS that
property's value there. `isXxxProvided()` still answers the question on the object. Pinned by
`NormalizationParityTest` and listed in the support matrix, which had claimed "everything else about the
response shape is identical in all four".

The same clash in the OTHER modes is now named at generation time instead of being discovered at
request time. Only laravel-data can resolve it from the import list; a generator that carries a PHP type
as a SHORT name cannot tell `format: binary` from a schema the document called `UploadedFile`, and no
import list fixes that. So each mode declares the names its emitted code already uses — `UnsetValue`,
`GeneratedDtoInterface`, `JsonException`, `Stringable` in runtime; `Assert`, `Ignore`, `SerializedName`,
`ExecutionContextInterface` in symfony; `Validator`, `Rule`, `FormRequest`, `stdClass` in laravel;
`DateTimeImmutable` and `UploadedFile` in all four — and a schema of that name gets a warning naming the
clash and the remedy. Generation still succeeds, as with the undiscriminated-union warning above.

The mode list stopped being three hardcoded columns. `tests/GenerationMode` is data, the parity suites
iterate it, and a `match` over modes has no `default` arm anywhere — so a mode is either measured in every
case or it fails loudly. Adding the fourth column surfaced one thing the old shape had hidden:
`additionalProperties: false` on a DTO-shaped schema is enforced by the two rule-based modes and invisible
to the two that hydrate first.

### Upgrading from 2.10.0

For the new mode, `composer require spatie/laravel-data` — the generated classes need `^4.0`.

One change reaches LARAVEL mode as well, because both modes read the same predicate for "is this property
nullable per the document". `$property['nullable']` conflates schema-nullable with optional (the walker sets
it for every optional property so the other modes have somewhere to put "absent"), and the keyword check
that replaced it cannot see a nullable `$ref` — its constraint map carries no `type`. So for a REQUIRED
nullable property written as `$ref` or `oneOf: [$ref, {type: null}]`, laravel mode now emits `nullable`
where it emitted nothing:

    'child' => ['present']              // 2.10.0
    'child' => ['present', 'nullable']  // 2.11.0

`nullable` only tells the validator to skip the remaining rules when the value IS null, which the schema
says is allowed — so this widens nothing that was refused before. The demo corpus contains no property of
that shape, which is why every golden snapshot but the new mode's is unchanged byte for byte. Regenerate
anyway if your document has one.

The recursion fix reaches LARAVEL mode the same way, and it NARROWS what is accepted: a document whose
ROOT schema refers to itself gets an interpreter fold it did not have, so payloads that used to slip
through below the first level are now 422s. That is the point — they violate the schema — but a client
relying on the old leniency will notice. The demo corpus has no self-recursive root either, so the laravel
snapshot is unchanged byte for byte here too.

## 2.10.0 — 2026-08-06

- add laravel generation mode
- emit FormRequest per request payload
- rules() for illuminate/validation
- enforce composition via withValidator()
- presence from validated() keys
- third column in parity suites
- third golden corpus snapshot
- add README.laravel.md
- add README.support-matrix.md and README.performance.md
- add bin/benchmark
- accept `42.0` for `type: integer`
- name accepted types on a union mismatch
- pin message wording across modes
- name the path in nested deserialization errors
- rename the parity suites for three modes
- state the Laravel 11 floor
- refuse a JSON array for `type: object`
- keep the payload of an object that only constrains its keys
- absolute `format: uri` / `iri`, real `uri-template` grammar
- enforce a recursive schema at every depth in laravel mode
- optional is not nullable in laravel mode
- one message per mistake in laravel mode
- FormRequest for a `$ref`-ed request body

`--attributes=laravel` emits a plain DTO carrying `rules()` plus a `FormRequest` that Laravel resolves
and validates before the controller body runs. Nothing to install: `FormRequest` and
`illuminate/validation` ship with the framework, and the generated code has no runtime dependency on
this package. What Laravel's rule vocabulary cannot express — composition, conditionals, `contains`,
`unevaluated*`, `content*`, `propertyNames`, `discriminator` — is enforced by the same interpreter
Symfony mode uses, entered from `withValidator()`; a schema that needs nothing beyond rules gets no
interpreter at all. Details and the full rule mapping: [README.laravel.md](README.laravel.md).

Two conformance fixes shared by all modes. `type: integer` now matches a number with a zero fractional
part, as JSON Schema 2020-12 §6.1.1 says it must: `42.0` is accepted (and hydrated into an `int`),
`42.5` still rejected. And a union that gates every branch out by type no longer answers with a bare
"does not match any oneOf branch" — it names what it would take: `(expected integer or string, got
boolean)`. `tests/Parity/InterpreterMessageParityTest` now pins interpreter-owned messages to the same
sentence in all three modes.

Runtime mode also names the path of a nested failure. A nested DTO is deserialized by its own pass, so
its errors carried the bare key: a payload missing `discriminator.id` reported `Required parameter "id"
not found in request.`, which reads as the ROOT object missing an `id` it may not even declare. Messages
now come out as `Required parameter "discriminator.id" not found in request.` and
`param "tags.0.name" expects string, got int`, at any depth.

A JSON array is no longer accepted for a `type: object` property. It used to be read as a map keyed
`0..n-1` — in every mode. The distinction exists only in the raw body (`{"0":1,"1":2}` and `[1,2]` decode
to the same PHP list), so the check sits where the raw body is reachable: the runtime deserializer, and
`withValidator(Validator $validator, ?string $rawJson = null)` in Laravel mode, which the generated
FormRequest now feeds `$this->getContent()`. Symfony mode still accepts it — the serializer denormalizes
before any constraint runs — and that boundary is pinned by a test.

An object that constrains only its KEYS keeps its payload. `{type: object, dependentRequired: {…}}` with
no `properties` — and the same for a lone `required`, `propertyNames` or `unevaluatedProperties` — used to
be materialized into a DTO class with no properties, which accepted the whole payload and returned `[]`.
Those four keywords name keys without declaring a schema for any of them, so nothing a DTO could hold:
the schema now stays a map (`array<string, mixed>`) and the keyword is enforced over it. **All three
modes.**

Two format checks were wrong in the emitted interpreter, so Symfony and Laravel mode accepted values the
runtime validator had always refused. `format: uri` and `format: iri` are ABSOLUTE — only the
`*-reference` forms take a relative value — and were both mapped to the reference check, accepting
`/rel/path`. And `uri-template` compared `preg_match(...) !== false`, which is true for zero matches as
well as one, so the brace check asserted nothing and `/a{unclosed` passed. Both now match runtime.

Laravel mode got three correctness fixes of its own. A recursive schema is enforced at every depth: the
fold is emitted once into `OPENAPI_RECURSIVE_SCHEMAS` and re-entered through a marker, where before the
walk stopped at the first turn of the cycle and a child violating `minimum` was accepted. An OPTIONAL
property is no longer treated as nullable — `sometimes` covers the absent key, and a key carrying `null`
that the schema never marked `nullable` is refused, as runtime mode does. And one mistake now produces one
message: the interpreter schema is pruned down to what the rules did not already take.

A request body written as `$ref: '#/components/schemas/X'` now gets a FormRequest. Only INLINE bodies were
recorded before, so the most idiomatic way to write a spec produced the one shape the mode exists for and
then withheld it.

### Upgrading from 2.9.0

**Regenerate.** Unlike earlier releases this one can change emitted output in ANY mode, not just the new
one, and two of the changes are visible in your own code:

- **types change** where a schema constrains only its keys: a property that was a generated nested class
  (`?FooBag`) becomes `?array`, and the class is no longer emitted. If you type-hinted it or called
  methods on it, that code needs updating — it was returning an empty object before;
- **payloads that used to pass may now be rejected**: a relative value for `format: uri`/`iri`, a
  malformed `uri-template`, and in Laravel mode `null` for an optional non-nullable property or a
  violation nested inside a recursive schema. All four were conformance gaps, so a payload that starts
  failing was always invalid against the document;
- **error messages changed** in three places (the union mismatch, nested deserialization paths, and one
  message per mistake in Laravel mode). Only code that STRING-MATCHES a message is affected;
- `42.0` is now accepted for `type: integer` in runtime and Laravel mode. Symfony mode still rejects it —
  the serializer decides before any constraint runs.

In Laravel mode the generated constructor no longer takes a `$providedOpenApiKeys` parameter: it takes the
schema's properties and nothing else, and `fromValidated()` — the only hydrator — records presence itself.
Hand-built instances keep working and report nothing as provided.

What each mode enforces, and the six places they differ, is now written down and generated from the test
suites: [README.support-matrix.md](README.support-matrix.md). Timings, with the benchmark to re-run them:
[README.performance.md](README.performance.md).

## 2.9.0 — 2026-08-05

- support matrix, label
- support allowReserved, allowEmptyValue
- support in: querystring
- support additionalOperations, QUERY method
- generate webhooks, callbacks
- dereference component requestBodies, responses
- fold Encoding Object into body
- refuse Swagger 2.0 clearly
- symfony mode: presence tracking
- symfony mode: serialization groups
- symfony mode: temporal string getters
- symfony mode: discriminated union interfaces
- keep free-form object payloads
- named scalar schemas become types
- suffix reserved class names
- distinguish case-only sibling keys
- refuse contradictory enum members
- fix nullable map item crash
- fix list-of-maps deserialization
- validate unevaluatedProperties on DTO values
- surface defects inside DTO values
- lazy raw query parsing
- raw query matches parse_str
- support uint32, uint64 formats
- golden corpus snapshot tests
- runtime vs symfony parity suites
- split README per mode
- add upgrade notes

### Upgrading from 2.8.x

The library is a drop-in replacement: **DTOs generated by 2.8.x keep working unchanged against 2.9.0
services** — measured on 55 specs, same accept/reject verdicts and the same normalized output. The two
metadata methods added here are simply absent on older DTOs and the services fall back.

What changes is the code the generator EMITS, so read this before regenerating.

**Runtime mode**

- `matrix` / `label` path parameters now bind. 2.8.x rejected them outright (`param "ids" expects
  array, got string`). This one comes from the services, so it applies before you regenerate.
- A bare `type: object` property is `array<string, mixed>` instead of a synthesized class that
  dropped the payload. `$dto->getMeta()->toArray()` becomes
  `Call to a member function toArray() on array` — use `$dto->getMeta()`.
- A named scalar schema (`Uuid: {type: string, format: uuid}`) is a type alias: no class is generated
  and the property is `string` with the alias's own `format` / `minLength` / `enum` as constraints.
  Referencing the class gives `Class "…\Uuid" not found`. Such a property used to reject every
  request (`Cannot deserialize nested DTO … from non-array value`), so no working code depended on
  it.
- **Response bodies change** for those fields: they used to serialize as `[]` with the data dropped,
  now they carry the object — `{}` when empty, as `type: object` says.
- A schema named after a PHP keyword (`Parent`, `Self`, `Int`, `List`, `Match`, `Readonly`, `Static`)
  gets a `Schema` suffix. Those files could not be loaded at all before.
- Two keys differing only in case (`name` + `NAme`) get distinct accessors (`getNAme2()`), because
  PHP method names are case-insensitive. The wire names are unchanged.
- `type: integer, enum: [1, "two"]` is refused at generation, naming the schema and the value. 2.8.18
  refused it too, with a vaguer message.

**Symfony mode — needs code changes**

The shape changed so that `PATCH` can tell "field absent" from "field sent as null"
(see [README.symfony.md](README.symfony.md)):

- properties are private with accessors: `$dto->name` becomes `$dto->getName()`;
- a temporal property reads as the string the schema declares (`getAt(): string`), with the
  `DateTimeImmutable` available from `getAtAsDateTime()`;
- optional properties gain `isXxxProvided()`;
- the class is no longer `final readonly` — `readonly` cannot be written twice, which is what the
  optional-half setters need.

**Recommended order**

1. `composer update` alone, keeping the old generated code: behaviour is unchanged and
   `matrix`/`label` start working.
2. Regenerate into a scratch directory and diff it against the committed output.
3. Fix the call sites, then commit the regenerated code.

