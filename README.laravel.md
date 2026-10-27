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

### A body that is one of several variants

A schema with a `discriminator` — a `oneOf`/`anyOf` of objects, or an object base its variants `allOf`
— is an interface the variants implement. The base of the allOf pattern declares the getters of its
own properties, so code that only reads the shared fields needs no `instanceof`:

```php
interface Shape
{
    public function getKind(): ShapeKind;
    public function toArray(): array;
}

final class Circle implements Shape { /* kind, radius */ }
```

A property typed with it holds the variant its discriminator names, and every field of that variant is
checked — the fields of THAT variant only, also for a `oneOf` of `$ref`s with a discriminator written on
the property itself (`Circle|Square`), which is built as the variant too. A request body typed with it gets a `ShapeFormRequest` that reads the discriminator and takes
the rules, the schema checks and the DTO from that variant; an unknown or missing value is a 422 on the
discriminator itself:

```php
public function store(ShapeFormRequest $request)
{
    $shape = $request->toDto(); // Circle or Square, typed Shape
}
```

The interface also publishes its discriminator, so one variant can be validated on its own — an item
of a batch, say — with that class's own `rules()`, `withValidator()` and `fromValidated()`:

```php
$class = Shape::VARIANTS[$item[Shape::DISCRIMINATOR_PROPERTY]] ?? null; // Circle::class, …
```

A union member that is an enum (`oneOf: [$ref Palette, string, integer]`) is checked against its
members and built as the case, so `getFilter()` answers `Palette::CRIMSON` for `"crimson"` and the
string or integer otherwise.

### Without `laravel/framework`

A project that has `illuminate/validation` but not the framework — a Symfony application, say — has no
`FormRequest` to extend. Generate with `--no-laravel-form-request` and only the DTOs are written; drive
the same three methods yourself. The translations ship with `illuminate/translation`:

```php
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

$factory = new Factory(new Translator(
    new FileLoader(new Filesystem(), $projectDir . '/vendor/illuminate/translation/lang'),
    'en',
));

// What FormRequest::validationData() would gather: the query string and the body, plus every path,
// header or cookie parameter the operation declares, under its OpenAPI name.
$data = [...$query, ...$body, 'articleId' => $routeParameters['articleId']];

$validator = $factory->make($data, ArticlesPostRequest::rules());
ArticlesPostRequest::withValidator($validator, $rawBody);

try {
    $dto = ArticlesPostRequest::fromValidated($validator->validate());
} catch (ValidationException $error) {
    // $error->errors(): ['title' => ['The title field must be at least 3 characters.']]
}
```

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
  `withValidator()`, nothing to forward. 6 of the 47 files in the demo corpus carry one;
- **an error sits under the field it is about**, as a rule's error does: a key missing inside a
  variant is `shape.radius`, a list item `levels.0`. A claim a rule also makes — a missing key, a
  wrong type, a value outside an enum — is reported as that rule (`The shape.radius field must be
  present.`), so your translations apply; any other keeps the interpreter's sentence.

### One thing no rule can express: object vs array

`type: object` and `type: array` differ in the WIRE shape, and PHP loses it — `{"0":1,"1":2}` and `[1,2]`
both decode to the same list, so no rule (and no check over `$validator->getData()`) can tell them apart.
That is why `withValidator()` takes a second argument:

```php
UserPostRequest::withValidator($validator, $this->getContent());
```

The generated FormRequest passes it for you. With the raw body in hand, a non-empty JSON array sent
for a `type: object` property is refused (`flags expects object, got array.`) while a JSON object keyed
`0..n-1` still passes. An EMPTY array passes as the empty object: it is what `json_encode([])` writes,
so every PHP client sends `[]` for `{}`, and runtime and Symfony mode accept it too. Omit the argument
and only that one check is skipped — everything else runs.

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
request — except one the schema lets be null, which is always written, `null` included, as in Symfony
mode.

An optional property with a schema `default` is that default when the key is absent —
`getKind()` returns `Kind::All`, not `null` — and its type admits `null` only if the schema does.
`isKindProvided()` still says the key was not sent, and `toArray()` still leaves it out unless the schema
lets the property be null. A REQUIRED
property with a default stays required: the default does not make the key optional, as in Symfony mode.

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
- keys come out in the order the schema declares the properties, optional ones in their place — not
  required-first, the order the constructor needs;
- a map encodes as a JSON object at every level, empty ones included (`{}`, never `[]`);
- an enum comes out as its backing value, a nested DTO as its own array;
- `writeOnly` properties are dropped, and a `readOnly` key sent by the client is ignored on the way in
  and absent on the way out;
- every DTO is `JsonSerializable` — PHP's own interface — so `json_encode($dto)` and
  `response()->json($dto)` write `toArray()`, an object with nothing to write as `{}`. A discriminated
  union's interface extends it, so a value typed as the union encodes the same way.

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
from its default counts as provided (before 2.15.55 a hand-built instance wrote no optional field at all).
A property the schema lets be null is written whether it was given or not — its default is that same
`null`, and the constructor cannot tell `photo: null` from no argument, so before 2.15.83 the key left
the response. A property with a schema default is written always too: absent IS the default, so the
value is known either way, and before 2.15.89 `rank: 1` passed explicitly left the response because it
equalled the default:

```php
// `url` and `notes` nullable, `rank` an integer with default 1
(new JobResponse(id: 1, title: 'A', url: 'https://example.test'))->toArray(); // id, title, url, notes: null, rank: 1
(new JobResponse(id: 1, title: 'A', rank: 1))->toArray();                     // ..., rank: 1
```

Where a nullable optional field must stay OUT unless it was given — a client that treats any key as
set — build it with `create()`. It takes the same arguments, and a nullable optional one left `null`
means "not given": its key is omitted. A class gets `create()` when it has such a field.

```php
JobResponse::create(id: 1, title: 'A', url: 'https://example.test')->toArray(); // id, title, url, rank: 1
JobResponse::create(id: 1, title: 'A')->withProvided('notes')->toArray();       // id, title, notes: null, rank: 1
```

A container the generator cannot type — a list whose items are a union of classes, or a union of a list
and a map — is written as its value is: a DTO item as its own array, a keyed array as an object, a list
as a list.

### Path, header and cookie parameters

Laravel validates the query string and the body. The generated FormRequest adds a parameter declared
`in: path`, `in: header` or `in: cookie` to its validation data under the OpenAPI name — a path parameter
as it appeared in the URL, before route model binding — so its rules see it; before 2.15.55 its `present`
rule failed on every request. A value of one scalar type (`integer`, `number`, `boolean`) arrives as a
string and is hydrated into the declared PHP type.

Laravel's own type rules are lenient in the BODY too: `integer` passes `"5"`, `boolean` passes `0`, `1`,
`"0"` and `"1"`. For a JSON body `withValidator()` reads the raw body and refuses those with Laravel's own
message (`The score field must be an integer.`), as every other strict mode does; `42.0` stays a valid
integer, as JSON Schema says. The strict rule variants are not used: they arrived in Laravel 12.22, above
this mode's floor, and `integer:strict` refuses `42.0`. A form body has no JSON types, so there a value is
accepted and hydrated as the declared type.

### Query parameters as the document serializes them

The FormRequest hands its validation data through the DTO's `normalizeOpenApiParameters()` first, which
reads a query or path parameter the way OpenAPI writes it: `?ids=1,2` for `explode: false` (`|` and space
for `pipeDelimited` and `spaceDelimited`), a lone `?tags=3` as a list of one, `?active=true` for a
boolean, and `"7"` as the int an `array<int>` or an int-backed enum item holds. Laravel's `list` and
`boolean` rules refused those spellings before 2.15.101. Validating without the FormRequest, call it on
the data first:

```php
$data = WidgetsQueryParams::normalizeOpenApiParameters($request->query());
$validator = Validator::make($data, WidgetsQueryParams::rules());
```

### A JSON array where the body is an object

A request body sent as a non-empty JSON array is refused under `payload` with `JSON body must be an
object, got array.` It decodes to the PHP array an object keyed 0..n-1 does, so with every property
optional it passed before 2.15.101. `[]` is the empty object, at the root as nested: `json_encode([])` is
how a PHP client sends one, and Laravel's `postJson()` with no data sends it too. Runtime mode refuses
`[]` at the root as well — the one place the two differ.

### Errors about the object itself

A keyword about the whole payload — `additionalProperties: false`, `minProperties`, `dependentRequired`,
a root `not` or conditional — reports under the property it names (`"bad"`, `"region"`) or under
`payload`, with the sentence starting `payload …` as in Symfony mode.

### `required` with a `default`

The two contradict each other — a default applies only to an absent key, which `required` forbids. As
in runtime and Symfony mode the default wins: the rule is `sometimes`, and an absent key hydrates to the
default. The generator warns about every such property, so the document can be fixed.

## Layers your application builds

The generator writes DTOs and the FormRequests that delegate to them, and stops there. What sits
around them is a few lines of your own code; these are the shapes that work.

### One request that is one of several variants, without a discriminator

With a `discriminator` the generated FormRequest picks the variant (see
[A body that is one of several variants](#a-body-that-is-one-of-several-variants)). Without one there
is no rule for which variant is meant, so the choice is yours — typically the first whose rules pass:

```php
foreach ([AddNote::class, AddTag::class] as $variant) {
    $validator = Validator::make($request->all(), $variant::rules());
    $variant::withValidator($validator, $request->getContent());
    if ($validator->passes()) {
        return $variant::fromValidated($validator->validated());
    }
}
throw ValidationException::withMessages(['payload' => 'matches no variant']);
```

### A body that is a list

`requestBody: {type: array, items: $ref Item}` validates and builds each item through `Item`. Prefix the
item's rules with `*.` and the errors come out as `0.name`, `1.name`:

```php
$rules = ['*' => ['array']];
foreach (Item::rules() as $path => $rule) {
    $rules['*.' . $path] = $rule;
}
$items = array_map(Item::fromValidated(...), Validator::make($request->json()->all(), $rules)->validate());
```

### One FormRequest for path, query and body

Each DTO validates its own part, so an operation needs the two or three FormRequests it has — or one
of your own that merges them. Path parameters are brought in the way the generated one does it:

```php
final class UpdateWidget extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['widgetId' => $this->route('widgetId')]);
    }

    public function rules(): array
    {
        return [...WidgetPathParams::rules(), ...UpdateWidgetRequest::rules()];
    }
}

$path = WidgetPathParams::fromValidated($request->validated());
$body = UpdateWidgetRequest::fromValidated($request->validated());
```

A key that both parts declare collides in the merged rules; keep those operations on separate requests.

### A response with its status code

`toArray()` is the payload; the status code belongs to the controller. To return a DTO directly, wrap
it in a `Responsable` of your own:

```php
final readonly class JsonDto implements Responsable
{
    public function __construct(private JsonSerializable $dto, private int $status = 200) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->dto, $this->status);
    }
}
```

### `null` or not sent

Three ways to build a DTO, one per intent:

| you want | build with |
|---|---|
| every field you pass written, a nullable one you left out written as `null` | `new X(...)` |
| a nullable optional field you left `null` absent from the output | `X::create(...)` |
| one more key written although it was not given | `->withProvided('key')` on either |

A single constructor cannot tell `null` from "not passed" without a marker type of its own, which the
generated code does not add — so pick by intent, per builder.

### Copying a date from one DTO to another

The getter returns the string the schema declares; the constructor takes the object. Copy through the
object getter:

```php
new WidgetView(createdAt: $source->getCreatedAtAsDateTime());
```

### Checking permissions before validation

Laravel runs a FormRequest's `authorize()` before its rules. The generated FormRequests define none, so
a request without permission that is also invalid answers 422, not 403, when the check lives in the
controller. Put the check where it runs first — a route middleware (`->can('update', 'widget')`) — or
use a FormRequest of your own with `authorize()` that delegates to the DTO's `rules()`,
`withValidator()` and `fromValidated()`.

## What this mode does not do

| | Why |
|---|---|
| parameter `style` / `explode` / `allowReserved` | Laravel has already parsed the request by the time a FormRequest exists. Use [runtime mode](README.runtime.md) when the request itself must follow the spec |
| multipart `encoding` parts | same reason |
| generating `authorize()` | policy is not in the document |
| a `spatie/laravel-data` class | possible as an opt-in target later; the default stays first-party |
| a top-level array request body | `type: array` at the root of a body is a list, not a class: its ITEM gets a DTO, the list gets no FormRequest. Validate each item with that DTO's `rules()`, `withValidator()` and `fromValidated()` — the generator emits DTOs only, the framework does the rest |
| one FormRequest per operation | the parameters and the body are separate DTOs, so an operation with both gets `<Operation>QueryParamsFormRequest` and `<Operation>RequestFormRequest`, and the action takes two arguments. The `QueryParams` class also carries the path parameters; its name is shared by every mode and stays until a major version |

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

A property name containing `.` is one literal key, not a path: `rules()` writes it as `invoice\.note`,
which Laravel reads as a literal dot, so `{"invoice.note": "…"}` validates and
`{"invoice": {"note": "…"}}` does not stand in for it. A name containing `*` is rejected during
generation — Laravel has no escape for its wildcard. Rename such wire properties or select another
generation mode. Generation fails before replacing existing output.
