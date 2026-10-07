# Laravel mode

[← back to the main README](README.md) · other modes: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel-data](README.laravel-data.md) · [yii3](README.yii3.md) · [support matrix](README.support-matrix.md) · [performance](README.performance.md)

```bash
composer openapi:generate-dto -- \
  --file=openapi.yaml \
  --directory=app/Generated \
  --namespace=App\\Generated \
  --attributes=laravel
```

**Nothing to install.** The emitted code needs `FormRequest` and `illuminate/validation`, which ship
with the framework — no `spatie/laravel-data`, and no runtime dependency on this package either.

**Laravel 11.1 or newer.** The one rule that pins the floor is `list`, added in 11.1 (not 11.0 — measured
by installing it) — it is the only way to
say "a JSON array, not an associative one", and without it `type: array` has no faithful rule at all.
Everything else the mode emits (`Rule::enum`, `multiple_of`) is older.

> **Empty strings are changed before validation.** Laravel's default middleware converts `""` to
> `null`. A required string with `minLength: 1` can therefore report **"must be a string"** instead of
> a length error. See [blank strings and middleware configuration](#blank-strings-and-literal-property-names)
> before integrating the generated FormRequest.

Every schema becomes a DTO. A DTO that describes an INCOMING payload also gets a `FormRequest`:

| File | What it is |
|---|---|
| `UserPostRequest` | a plain DTO: readonly typed properties, getters, `rules()`, `fromValidated()` and `withValidator()` |
| `UserPostRequestFormRequest` | a thin `FormRequest` delegating to those, plus `toDto()` |

**Which classes get a FormRequest**: the ones an operation reads a payload from — a request body
(inline or `$ref`-ed to a component) and an operation's parameters. A schema reached only from a
response gets none: there is no request to validate. The decision comes from where the walker met the
schema, not from its name, so `User200` is judged by the same rule as everything else.

**`withValidator()` is always there and always forwarded** (since 2.15.64). It runs the interpreter
when the schema needs one, and in every DTO it switches off `excludeUnvalidatedArrayKeys`: otherwise
`validated()` rebuilds a list from the leaves its rules reach, which reorders it when an empty item sits
between non-empty ones and drops an item no rule reaches. If you call `rules()` from your own
FormRequest, call `withValidator()` too.

## Using it

Type-hint the FormRequest. Laravel resolves and validates it **before the controller body runs**, and a
failure comes out as the framework's own 422 with its error bag — nothing to map, in the generated code
or in yours:

```php
use App\Generated\UserPostRequestFormRequest;

final class UserController
{
    public function store(UserPostRequestFormRequest $request)
    {
        $dto = $request->toDto();

        $dto->getEmail();               // string
        $dto->getCreatedAt();           // '2026-03-10T12:00:00+00:00' — as the schema declares it
        $dto->getCreatedAtAsDateTime(); // DateTimeImmutable
        $dto->isNicknameProvided();     // was the key in the payload at all? (PATCH)
    }
}
```

Prefer your own FormRequest? Call the same three methods — the generated one holds no logic of its
own, it only delegates:

```php
final class StoreUser extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return UserPostRequest::rules();
    }

    public function withValidator(Validator $validator): void
    {
        // The raw body matters: see "one thing no rule can express" below.
        UserPostRequest::withValidator($validator, $this->getContent());
    }
}
```

Drop the `withValidator()` method if the generated DTO has none — `method_exists($dto, 'withValidator')`
answers it, and so does reading the DTO: no `withValidator()` there means the rules already say
everything the schema does.

**Authorization is never generated.** An OpenAPI document describes payload shape, not policy, so the
generated FormRequest defines no `authorize()`. Add one where the route needs it.

## Two layers, one vocabulary

The mode validates in two passes, and the split is mechanical: whatever Laravel's rules can express is a
rule, everything else is enforced by an interpreter emitted into the DTO.

```php
public static function rules(): array
{
    return [
        'id' => ['present', 'integer', 'min:1'],
        'title' => ['present', 'string', 'min:3', 'max:80'],
        'status' => ['present', Rule::enum(Status::class)],
        'slug' => ['sometimes', 'string', 'regex:/^[a-z|0-9-]+$/'],
        'summary' => ['sometimes', 'nullable', 'string'],
        'tags' => ['sometimes', 'array', 'list'],
        'tags.*.name' => ['required_with:tags', 'string', 'min:2'],
        'scores.*' => ['integer', 'min:0'],
    ];
}
```

Rules are keyed by the **OpenAPI** names, because that is what the payload carries. Details that are
easy to get wrong and are therefore worth knowing:

| Rule | Why it looks like that |
|---|---|
| `present`, not `required` | Laravel's `required` means "present and NOT empty": it rejects `""`, `[]`, `{}` and `null`, all of which are legal values for a required property. `present` asserts the key exists and leaves the value to the type rules |
| `sometimes` for an optional property | "validate only if the key is there", which is also where presence tracking comes from |
| `nullable` ONLY when the schema says so | optional is not nullable. `sometimes` already covers the absent key; a key that IS there carrying `null` is a value the schema never allowed, so `slug` above rejects it and `summary` (declared `nullable`) accepts it |
| `required_with:<parent>` for a nested required property | a dotted rule is evaluated even when the parent is null, so it must ask its question only when the parent has a value |
| the ARRAY form, never a pipe string | a `\|` inside a `regex` pattern would split the rule list |
| `const` and inline `enum` use the interpreter | JSON equality preserves scalar types and structures; `Rule::in` coerces values |
| `Rule::enum(Status::class)` | pins the backing type and the members in one rule, with the generated enum as the single source of the values |
| `array` **and** `list` | `array` alone accepts an associative array; `list` is what says "JSON array" |
| `min:` / `max:` for integer bounds, lengths and counts | the same rule means length, value or count depending on the type rule beside it, so an unpinned `oneOf` gets no bounds — the interpreter takes them instead. Numeric bounds on `number` also use the interpreter because Laravel rounds floats when converting them to strings |
| …and never for an EXCLUSIVE bound | `min:` is inclusive and Laravel has no exclusive spelling. A `minimum: 3` carrying `exclusiveMinimum: true` (the OpenAPI 3.0 form) therefore goes to the interpreter WHOLE. Emitting `min:3` for it also took the keyword away from the interpreter, and the boundary value was accepted where every other mode refused it |
| no `distinct` for `uniqueItems` | the rule is right about WHETHER and wrong about HOW MANY: it reports once per offending ELEMENT, so one duplicated pair produced two `validation.distinct` messages where every other mode reports the array's single violation once. `uniqueItems` belongs to the interpreter in every mode — which already owned it for object items, since `distinct` cannot compare those |
| an `int` property is hydrated through a coercion | `42.0` IS an integer per JSON Schema 2020-12 §6.1.1, Laravel's `integer` rule agrees, and PHP still decodes it to a float — so `fromValidated()` converts a zero-fraction float rather than dying with a TypeError after a passing validation |

### What the interpreter enforces

Laravel has no rule for composition, and this is where every other spec-first Laravel generator stops.
These keywords are checked by `withValidator()` against the payload the validator already holds:

`oneOf`, `anyOf`, `allOf`, `not`, `if`/`then`/`else`, `contains`, `minContains`/`maxContains`,
`prefixItems`, `unevaluatedProperties`, `unevaluatedItems`, `propertyNames`, `patternProperties`,
`dependentSchemas`, `discriminator`, `exclusiveMinimum`/`exclusiveMaximum`, `dependentRequired`,
`uniqueItems` (over any member type, see the note above), and every string format Laravel has no rule for (`hostname`,
`duration`, `json-pointer`, `uri-template`, `idn-*`, `byte`, …).

Two consequences worth stating plainly:

- **a violation is reported once.** The interpreter only carries what the rules did not take, so a
  payload breaking `minLength` gets Laravel's message, not two messages;
- **a schema that needs nothing beyond rules gets no interpreter at all** — no constants, no
  `withValidator()`, nothing to forward. 6 of the 47 files in the demo corpus carry one.

### One thing no rule can express: object vs array

`type: object` and `type: array` differ in the WIRE shape, and PHP loses it — `{"0":1,"1":2}` and `[1,2]`
both decode to the same list, so no rule (and no check over `$validator->getData()`) can tell them apart.
That is why `withValidator()` takes a second argument:

```php
UserPostRequest::withValidator($validator, $this->getContent());
```

The generated FormRequest passes it for you. With the raw body in hand, a JSON array sent for a
`type: object` property is refused (`flags expects object, got array.`) while a JSON object keyed
`0..n-1` still passes. Omit the argument and only that one check is skipped — everything else runs.

### One thing this mode can do that the others cannot

`additionalProperties: false` and `unevaluatedProperties: false` actually fire here. The interpreter
reads the raw payload, so an undeclared key is still visible; runtime and Symfony mode both bind the
payload into an object first, and by then the key is gone.

## PATCH / partial updates

`validated()` returns only the keys the payload carried, and the DTO records that set:

```php
$dto = $request->toDto();

$dto->isNicknameProvided();   // false — the key was absent
$dto->getNickname();          // null

// vs a payload that sent {"nickname": null}
$dto->isNicknameProvided();   // true — sent, explicitly as null
```

No sentinel value and no setter is involved, which makes this the simplest of the emitted presence
implementations. `toArray()` omits a property that was never provided, so a PATCH response mirrors the
request.

## The response direction

`toArray()` is emitted, not delegated, so it follows the schema rather than a serializer's defaults:

- a temporal property is read as the string the schema declares — a `date` stays a date, a `date-time`
  keeps sub-second precision, and a CONTAINER of dates gets the same pair of getters per item:

  ```php
  $dto->getDates();              // ["2026-03-10", "2026-03-11"]
  $dto->getDatesAsDateTime();    // [DateTimeImmutable, DateTimeImmutable]
  $dto->getDatesByDay();         // ["mon" => "2026-03-09"] — a map keeps its keys
  ```

  `fromValidated()` casts each element, so the property really holds the objects its docblock names —
  it used to name them and hold the strings `validated()` handed over. Two modes hold strings there and
  say so; see [Temporal container items](README.support-matrix.md#temporal-container-items);
- a map encodes as a JSON object at every level, empty ones included (`{}`, never `[]`);
- an enum comes out as its backing value, a nested DTO as its own array;
- `writeOnly` properties are dropped, and a `readOnly` key sent by the client is ignored on the way in
  and absent on the way out.

A `readOnly` property listed under `required` is typed nullable with a `null` default, and its rule is
`sometimes` rather than `present`. OpenAPI puts that requirement on the RESPONSE alone, so demanding it
of a request was wrong twice over: the rule asked the client for a field it does not own, and
`fromValidated()` then fed `null` to a `readonly int` parameter — a `TypeError` on every hydration,
valid payload or not (fixed in 2.15.36).

On the way OUT the same property is written whenever it HAS a value: a response your code builds
carries the field it set, and one hydrated from a request — which never carries a readOnly field —
leaves it out. `toArray()` asks the presence flag for every other optional property, and for this one
that flag is false forever, which is why 2.15.36 through 2.15.41 dropped the field from every response
built in code (fixed in 2.15.42).

A response built with the constructor writes every optional field it was given: an argument that differs
from its default counts as provided. One EQUAL to its default — an explicit `null` where the default is
`null` — cannot be told apart from an omitted one by the constructor, so name it when the output must
carry it (before 2.15.55 a hand-built instance wrote no optional field at all):

```php
(new JobResponse(id: 1, title: 'A', url: 'https://example.test'))->toArray(); // id, title, url
(new JobResponse(id: 1, title: 'A'))->withProvided('notes')->toArray();      // id, title, notes: null
```

### Path, header and cookie parameters

Laravel validates the query string and the body. The generated FormRequest adds a parameter declared
`in: path`, `in: header` or `in: cookie` to its validation data under the OpenAPI name — a path parameter
as it appeared in the URL, before route model binding — so its rules see it; before 2.15.55 its `present`
rule failed on every request. A value of one scalar type (`integer`, `number`, `boolean`) arrives as a
string and is hydrated into the declared PHP type.

Laravel's own type rules are lenient in the BODY too: `integer` passes `"5"`, `boolean` passes `0`, `1`,
`"0"` and `"1"`. Such a value is accepted and hydrated as the declared type rather than failing with a 500;
the strict variants that would refuse it arrived in Laravel 12.22, above this mode's floor.

### Errors about the object itself

A keyword about the whole payload — `additionalProperties: false`, `minProperties`, `dependentRequired`,
a root `not` or conditional — reports under the property it names (`"bad"`, `"region"`) or under
`payload`, with the sentence starting `payload …` as in Symfony mode.

## What this mode does not do

| | Why |
|---|---|
| parameter `style` / `explode` / `allowReserved` | Laravel has already parsed the request by the time a FormRequest exists. Use [runtime mode](README.runtime.md) when the request itself must follow the spec |
| multipart `encoding` parts | same reason |
| generating `authorize()` | policy is not in the document |
| a `spatie/laravel-data` class | possible as an opt-in target later; the default stays first-party |

## Verification

Every claim above is measured, not reasoned:

- the emitted `rules()` are fed to a real `illuminate/validation` validator in
  `tests/Laravel/LaravelRulesEnforcementTest` — 27 keyword cases, 9 interpreter cases and 7 that assert one
  message per mistake, each feeding a valid payload AND an invalid one so a rule that accepts everything
  cannot pass unnoticed;
- the mode is a column in every parity suite (validation verdicts, response shape, presence, message
  wording, binary uploads), so it cannot drift away from runtime and Symfony mode without a test failing — see the
  [support matrix](README.support-matrix.md), which is generated from those suites;
- the demo corpus is snapshotted in this mode too (`tests/Golden/snapshots/laravel.snapshot.txt`), and
  every generated file is parsed.

**One deliberate limit: `laravel/framework` is not a dev dependency of this package.** So the emitted
FormRequest is asserted as source, parsed, and driven through a real `Validator` with a stub base class —
but never resolved through Laravel's container here. Adding `laravel/framework` (or
`orchestra/testbench`) would pull ~50 packages and force `symfony/*` down a major, to cover the last hop:
the container calling `validateResolved()`.

That hop is measured OUTSIDE the package instead, in the demo application, against the real framework:
the controller type-hints the generated `TestPostRequestFormRequest`, the container resolves it, and a
failure comes back as Laravel's own 422 with its error bag — including the cases only the interpreter can
catch and the one that needs the raw body. Everything the FormRequest delegates to is covered here.

### Blank strings and literal property names

Generated rules validate present blank strings against the same native rules as other values. An
unconstrained string can still be empty; a positive minLength, pattern, format or incompatible type
rejects it. Optional absence and schema-declared nullability remain separate from an empty string.

Laravel's default `ConvertEmptyStringsToNull` middleware turns `""` into `null` BEFORE validation, so a
FormRequest never sees the empty string the client sent. For `title: {type: string, minLength: 1}` the
client then reads `The title field must be a string.` — true of the `null` it was turned into, not of the
`""` it sent. The 422 is right either way; for messages about the value as sent, exclude the API routes
from that middleware.

For example, this schema requires a non-empty string:

```yaml
title:
  type: string
  minLength: 1
```

| Input sent by the client | With the default middleware | Without empty-string conversion |
|---|---|---|
| `{"title": ""}` | `null` reaches validation; string-type error | `""` reaches validation; minimum-length error |
| `{"title": null}` | string-type error | string-type error |

Both inputs are rejected. A nullable string behaves differently: conversion can make an empty string
pass as `null`, even when `minLength` would reject the original string.

To preserve empty strings on API routes in Laravel 11.1+, add the following inside the existing
`withMiddleware` callback in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Inside Application::configure(...)->withMiddleware(...):
->withMiddleware(function (Middleware $middleware): void {
    $middleware->convertEmptyStringsToNull(except: [
        fn (Request $request): bool => $request->is('api/*'),
    ]);
})
```

Adjust the path condition to match your API routes. This applies to every field on those routes:
clients must send an explicit JSON `null` when they mean null. The generated rules still reject `null`
for non-nullable strings. Laravel's separate `TrimStrings` middleware continues to trim whitespace.

Property names containing `.` or `*` are rejected during generation because Laravel treats them
as path/wildcard syntax. Rename such wire properties or select another generation mode. Generation
fails before replacing existing output.
