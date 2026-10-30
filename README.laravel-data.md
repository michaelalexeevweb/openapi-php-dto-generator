# laravel-data mode

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · other mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [yii3](README.yii3.md) · [performance](README.performance.md)

```bash
vendor/bin/console openapi:generate-dto \
    --file=openapi.yaml \
    --directory=app/Data \
    --namespace='App\Data' \
    --attributes=laravel-data
```

One [`spatie/laravel-data`](https://github.com/spatie/laravel-data) class per schema, instead of the
FormRequest + DTO pair the [first-party Laravel mode](README.laravel.md) emits.

```php
public function store(Request $request): JsonResponse
{
    $dto = UserPostRequestData::from($request);   // validates AND hydrates, or throws a 422

    return response()->json($dto->toArray());
}
```

**This is the only mode whose output needs a third-party package.** That is why it is opt-in and why
`--attributes=laravel` remains the default Laravel target: `FormRequest` and the validator ship with the
framework, so first-party output runs with nothing installed. Reach for this mode when your application
already uses laravel-data and you want generated classes to look like the ones you write by hand.

```bash
composer require spatie/laravel-data
```


## What the generated class looks like

```php
final class UserPostRequestData extends Data
{
    public function __construct(
        #[MapName('first_name')]
        public readonly string $firstName,
        public readonly string|Optional $nickname = new Optional(),
        public readonly string|null|Optional $middleName = new Optional(),
        #[MapName('born_on')]
        #[WithCast(DateTimeInterfaceCast::class, format: ['!Y-m-d', 'Y-m-d'])]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public readonly DateTimeImmutable|Optional $bornOn = new Optional(),
        #[WithoutValidation]
        #[DataCollectionOf(Address::class)]
        public readonly array|Optional $addresses = new Optional(),
    ) {
    }

    public static function rules(): array { /* the schema, as Laravel rules */ }

    public static function withValidator(Validator $validator): void { /* everything else */ }
}
```

Four things in there are decisions rather than style, and each is measured in
`tests/LaravelData/LaravelDataSemanticsTest` rather than taken from the documentation.

### `Optional` and `null` are separate facts

An optional property is `string|Optional`. A nullable one is `string|null`. One that is both is
`string|null|Optional`, and the three states stay apart:

| body | property | `toArray()` |
|---|---|---|
| key absent | an `Optional` instance | key omitted |
| `{"nickname": null}` | `null` | `"nickname": null` |
| `{"nickname": "nick"}` | `'nick'` | `"nickname": "nick"` |

```php
if (! $dto->nickname instanceof Optional) {
    $user->nickname = $dto->nickname;   // the client sent the key, null included
}
```

This is the mode's main attraction. Everywhere else, "absent" needs some inhabitable value and `null` is
the only candidate, so an optional property has to be declared nullable — and the Laravel rule builder
then emitted a matching `nullable`, which accepted an explicit `null` the schema never allowed. Here
absence has its own type, so `nullable` follows the document alone: `['sometimes', 'string']` refuses an
explicit null while still allowing the key to be missing.

`mixed` is the one exception. It cannot take part in a union type, so a free-form property is plain
`mixed` and its presence is not observable — narrowing it to something `Optional` fits into would refuse
payloads the schema allows.

An optional argument defaults to `new Optional()`, so code that builds a DTO — a response — passes only
what it has: `new UserResponse(firstName: 'Ada')` leaves the rest absent, exactly as a payload without
those keys does. Before 2.15.93 every optional argument had to be spelled out.

A property with a schema `default` is the exception: its argument defaults to that value
(`int $limit = 25`), and laravel-data takes it for an absent key, so the property holds the default
rather than `Optional` — what the other modes' getters answer. Its type has no `Optional`, and
`toArray()` writes it. A property both `required` and with a `default` is the same: the key may be left
out (`sometimes`), as in runtime, Symfony and Laravel mode. Before 2.15.94 the default was ignored, and
a `required` one demanded the key.

A `readOnly` property listed under `required` is `Optional` too, for the same reason: OpenAPI puts that
requirement on the response alone, so a request may omit it. Until 2.15.36 it was emitted as plainly
required, and the generated `rules()` said `['present', 'integer']` — a rule asking the client to send
a field the client does not own.

### No `#[MergeValidationRules]`

The emitted `rules()` is the whole schema, so laravel-data's own rule inferrers have nothing to add:

```
without the attribute:  required => [required, string, min:2]
with it:                required => [required, string, required, string, min:2]
                        nullableOptional => [nullable, sometimes, string, sometimes, nullable, string]
```

With the attribute, the inferred rules are merged in — duplicating them and prepending a `nullable` the
schema never asked for. Both were real bugs in the first-party Laravel mode. The default behaviour, where
`rules()` wins outright for every property it mentions, is what "our rules are the truth" means.

### `#[WithoutValidation]` on nested properties

Overriding `rules()` stops laravel-data's inferrers from guessing rules for a property, but it does not
stop it from treating a nested `Data` object or collection as one: it injects a `Closure` on `tags.*` that
resolves the nested class's rules all over again. One missing nested key then produced TWO messages for
one mistake:

```
{"tags.0.id": ["validation.present"]}       ← laravel-data's injected nested resolution
{"tags":      ["tags[0].id is required"]}   ← the emitted interpreter
```

A verdict comparison cannot see that — both layers correctly reject the payload, just twice over. So a
nested-`Data` property carries `#[WithoutValidation]`, the package's own escape hatch, which removes ONLY
the injected resolution: the paths this generator emits are applied afterwards as overwritten rules, so
the verdict is unchanged. `InterpreterMessageParityTest::testOneViolationIsReportedOnceInEveryMode` holds
every mode to one message per mistake.

The exception is a discriminated-union base. There the nested walk is also what adds
`EnsurePropertyMorphable`, which is why an unmapped discriminator value comes back as a 422 rather than
dying in the morph resolver — so that one property keeps laravel-data's own resolution.

### `withValidator()` is the same interpreter as Laravel mode

What Laravel's rule vocabulary cannot express — composition, conditionals, `contains`, `unevaluated*`,
`content*`, `propertyNames`, `discriminator` — is enforced by the same emitted interpreter, producing the
same sentences. `tests/Parity/InterpreterMessageParityTest` holds every mode to one wording.

Two consequences of laravel-data's hook signature:

- it runs on the **root** object only, so every nested check is flattened to a path from the root;
- it receives the validator alone, with no raw body. `type: object` versus `type: array` is a property of
  the wire shape that PHP loses (`{"0":1,"1":2}` and `[1,2]` decode to the same value), so that one check
  reads the request:

  ```php
  $container = Container::getInstance();
  $raw = $container->bound('request') ? $container->make('request')->getContent() : null;
  ```

  The body is used only when it IS the data being validated — every key of it in the validator's data,
  unchanged, as `from($request)` gives it. `validate($array)` on a response or `validateAndCreate()` on
  one item of a list runs while a request is bound, and before 2.15.103 that request's body was checked in
  their place. Without a request, or with a different body, that single check is skipped and everything
  else still runs.

A message is keyed by the field it is about, as in Laravel mode: a wrong `value` deep in a union lands on
`edge.value`, a list item on `levels.0`. A claim a Laravel rule also makes — a missing key, a wrong type,
a value outside an enum — is reported as that rule, so it reads like the rest of the error bag and the
application's translations apply; a field a rule has already failed is not reported twice. Before
2.15.100 every interpreter message here was keyed by the top-level property.

### Discriminated unions are an abstract base, not an interface

This is the one place the emitted class *shape* differs between modes. laravel-data cannot hydrate an
interface, and it has its own mechanism:

```php
abstract class Shape extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public readonly string $kind,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['kind'] ?? null) {
            'circle' => Circle::class,
            'square' => Square::class,
            default => null,
        };
    }
}

final class Circle extends Shape
{
    public function __construct(string $kind, public readonly int $r)
    {
        parent::__construct($kind);
    }
}
```

A member takes the discriminator as a plain constructor parameter and forwards it: redeclaring an
inherited readonly property is a fatal, not a test failure. An unmapped discriminator value leaves the
class unresolved and comes back as a 422, not an exception you have to translate.

At the ROOT of a payload laravel-data picks the member before it validates anything, so an unknown or
missing discriminator there was `CannotCreateAbstractClass` — a 500 — and no `rules()` on the base could
run first. The base overrides `from()` and `validateAndCreate()` to check the discriminator with the
framework's validator (`required`, `in:` the mapped values) and then hands over to laravel-data: a 422
on `kind`, `The selected kind is invalid.` Before 2.15.96 only the nested case was a 422.

A member cannot belong to more than one discriminated-union morph base: PHP permits only one parent
class. Such a schema now fails generation with the member and both bases named, before existing
output is replaced. Give each union its own member schema instead of sharing one across morph bases.

The allOf pattern — a base with the discriminator whose variants `allOf` it — gets the same shape: the
base is the abstract morph base, each variant extends it. Before 2.15.93 the base stayed a plain class
its variants did not extend, so a property typed with it lost every field a variant adds. A
discriminator the base declares as an enum is that enum on the base too, and `morph()` reads its value.

Here the base declares every property its own schema declares, not the discriminator alone, so code
typed with the base reads them — `$event->uuid` on the abstract class. Each variant redeclares them, in
the schema's order, and assigns them in its own constructor; that is also what keeps `toArray()` in the
schema's order, since PHP lists a class's own properties before the inherited ones:

```php
abstract class Event extends Data implements PropertyMorphableData
{
    public readonly string $uuid;

    #[PropertyForMorph]
    public readonly EventKind $kind;

    // morph(), from(), validateAndCreate() as above
}

final class Tapped extends Event
{
    public function __construct(
        public readonly string $uuid,
        public readonly EventKind $kind,
        public readonly int $count,
    ) {
    }
}
```

PHP accepts the redeclaration only with the SAME type, so a base property some variant types
differently stays that variant's alone (and a discriminator typed differently keeps the layout above).
Before 2.15.99 the base declared only the discriminator, and every variant wrote it last.

A `propertyName` that is not a PHP identifier — `pet_type` — gets a `#[MapName('pet_type')]` on the base
alongside `#[PropertyForMorph]`. The morph runs before there is an object, and laravel-data looks the
value up by the property name and by its input-mapped name, so both spellings have to be there.


## Where it validates, and where it does not

laravel-data's `validation_strategy` defaults to `OnlyRequests`. That is load-bearing:

| call | validates |
|---|---|
| `Data::from($request)` | ✅ |
| `Data::validateAndCreate($payload)` | ✅ |
| `Data::from($payload)` — a plain array | ❌ hydrates a rule-violating payload silently |

So bind from the request, or use `validateAndCreate()`. `from($array)` is a hydrator, not a gate.

A path parameter is not part of `$request->all()`, which is what `from($request)` reads. Its property
carries laravel-data's `#[FromRouteParameter('widgetId')]`, which takes it from the route before the
rules run (before 2.15.93 a required path parameter failed `present` on every request). A header or
cookie parameter has no such attribute in laravel-data — merge it into the request before `from()`:

```php
$request->merge(['X-Tenant' => $request->header('X-Tenant')]);
$params = WidgetHeaderParams::from($request);
```

A query parameter arrives as a string, and the class reads it the way OpenAPI writes it in
`prepareForPipeline()` — laravel-data's own hook, run before the rules: `?ids=1,2` for `explode: false`
(`|` and space for `pipeDelimited` and `spaceDelimited`), a lone `?tags=3` as a list of one,
`?active=true` for a boolean, and `"7"` as the int an `array<int>` or an int-backed enum item holds.
Before 2.15.101 the `list` and `boolean` rules refused the first three, and the items of a list stayed
strings — `"1"` was no int-backed enum case. A path parameter comes in later, through
`#[FromRouteParameter]`, so a path LIST is not split this way.

A request body sent as a non-empty JSON array is refused under `payload` with `JSON body must be an
object, got array.`; it decodes to the PHP array an object keyed 0..n-1 does. `[]` is the empty object,
at the root as nested — `json_encode([])` is how a PHP client sends one. Runtime mode refuses `[]` at the
root as well.


## Divergences from the other modes

### A property name with a dot does not generate

```
"priority.comment": { "type": "string" }
→ [ERROR] Laravel validation cannot safely represent property name "priority.comment"
```

The whole document fails, before any output is replaced. Laravel's validator reads `.` in a rule key
as a step into a nested array, and laravel-data's `#[MapName('priority.comment')]` reads it the same way
— there is no attribute that makes it a literal key. Laravel mode can: it escapes the dot in its own
rules (`priority\.comment`). So either rename the property in the document (`priorityComment`), or
generate that document in Laravel mode. `*` is refused the same way, in both Laravel modes.

### A property named like an integer is restored on the way out

A wire name PHP reads as an integer key — `"2"` — came out renumbered: laravel-data `array_merge()`s the
transformed array with the class's additional data, and `array_merge()` renumbers integer keys, so
`{"2":"view","4":"edit"}` was written `["view","edit"]`. Reading it in was never affected. A class with
such a name overrides `transform()` — which `toArray()`, `toJson()`, a nested object and a list all go
through — and puts the names back in the order laravel-data writes the properties. A partial
transformation (`only()`, `except()`) that drops one of those properties shifts the rest; name the
property in the document instead if you rely on partials there.

### The rest

All measured and pinned; the full table with reasons is in
[README.support-matrix.md](README.support-matrix.md#divergences). In short, laravel-data:

- keeps the PHP array where runtime casts a map to `stdClass`, so an empty map encodes as `[]` rather
  than `{}` — the same difference Symfony mode has, for the same reason;
- drops sub-second precision from a `format: date-time` **response** (its transformer takes one format
  string, which cannot say "keep the precision the payload carried"); the emitted `#[WithCast]` accepts
  all four patterns on the way in;
- holds STRINGS in a container of dates, and declares `array<string>` rather than
  `array<DateTimeImmutable>`. `#[WithCast]` casts the PROPERTY, never its items — measured against the
  package, `['2026-03-10']` comes back unchanged — and `#[DataCollectionOf]` wants a `Data` class, which
  `DateTimeImmutable` is not. A per-item cast would have to be a `Cast` class of ours emitted into
  generated code that today depends on nothing from this package. Nor does the constructor convert
  them, as it does a list of enum values: laravel-data writes the items back untransformed, and a
  `DateTimeImmutable` encodes as `{"date": …, "timezone_type": …}` — with
  `cast_and_transform_iterables` on, as ATOM rather than the schema's `Y-m-d`. A backed enum encodes as
  its value either way, which is why the two are treated differently. The wire form is unaffected; see
  [Temporal container items](README.support-matrix.md#temporal-container-items);
- echoes a `readOnly` property the client sent — `#[Hidden]` is an exact counterpart to `writeOnly` and
  there is none for `readOnly`;
- writes `toArray()` in the schema's order, as the other modes do, by declaring the properties above
  the constructor in that order where it differs from the constructor's (required arguments first, as
  PHP needs) — laravel-data writes keys in declaration order. One shape keeps the constructor's order: a
  class with a nullable property that has a schema default, since a declared readonly property cannot
  carry the default laravel-data would then read;
- picks the cases of a list or map of enum values in the constructor. laravel-data casts container items
  only behind `data.features.cast_and_transform_iterables`, which ships off, so `["warm"]` stayed a list
  of strings under an `array<Tone>` docblock. With the flag off `toArray()` then holds the cases, not
  their values — `json_encode` and `toJson()` still write `["warm"]`. To get values from `toArray()` as
  well, turn the flag on in `config/data.php`; the constructor leaves a value that is already a case
  alone, so it works either way:

  ```php
  'features' => [
      'cast_and_transform_iterables' => true,
  ],
  ```

- declares a scalar union with an enum member — `oneOf: [$ref Tone, string, integer]` — as
  `UnitEnum|string|int` on the property, with the real `Tone|string|int` in `@var` and on the
  constructor argument. laravel-data's enum cast reads the property type and took every scalar for the
  enum (`"8812"` was a "Could not cast enum" 500); with `UnitEnum` it stands aside, and the constructor
  picks the case — `Tone::tryFrom()` for a value of the enum's backing type, the scalar as sent
  otherwise — as Laravel mode's hydrator does;
- normalizes a member of a `oneOf` discriminated union with the discriminator LAST, because it is
  inherited from the abstract base and PHP lists a class's own properties first. Same keys, same values.
  An `allOf` union keeps the schema's order — its members redeclare the base's properties;
- accepts a numeric string for `integer` / `number`, and `0`, `1`, `"0"`, `"1"` for `boolean`, in a
  JSON body. Laravel's own rules are lenient there, and Laravel mode refuses them only by reading the
  raw body in its `withValidator()`, a check this mode does not make;
- refuses a `null` item in a nullable list of DTOs: `#[DataCollectionOf]` builds a class out of every
  element and has no null case.

Apart from the verdicts listed above, the validation vocabulary is identical to the other four modes,
including `additionalProperties: false` on a DTO-shaped schema, which the two hydrating modes cannot
see at all.


## Testing generated classes without a Laravel application

laravel-data resolves `DataConfig`, its resolvers and its pipeline out of the container, so its
`from()` / `validate()` paths need one. This package is framework-agnostic and does **not** pull
`orchestra/testbench` to get it: `tests/LaravelData/LaravelDataContainer` builds what is actually needed —
a plain `Illuminate\Container\Container`, a config repository, the two singletons
`LaravelDataServiceProvider::packageRegistered()` binds, and six global helpers
(`app()`, `resolve()`, `config()`, `rescue()`, plus the `request()` and `app_path()` shims) that
`illuminate/foundation` would otherwise define.

Copy it if you want the same in your own package's tests; in a real application none of it applies, since
the framework provides all of it.

### Inside another framework (Symfony, …)

The same class is the recipe for running generated classes in an application that is not Laravel. The
generator emits the classes only; the bridge is yours, and it has four parts:

1. **Boot once per request** — a `kernel.request` listener, not every place that touches a class. Build
   what `LaravelDataContainer::boot()` builds: the container set as the global instance and as the facade
   application (laravel-data reaches the validator through the `Validator` facade), `config` with a
   `data` key, a translator, the validation factory, and the `DataConfig` / `ContextResolver` singletons.
   Give `config('data')` the keys `dataConfig()` lists rather than copying the package's `config/data.php`:
   that file calls `app_path()` and `base_path()`. Your app may already define `app()`; the one
   laravel-data calls must resolve from this container, with and without an argument.
2. **The request** — convert the incoming request to `Illuminate\Http\Request` and bind it as `request`.
   The emitted checks that need the raw body read it from there. A path parameter is read through
   `#[FromRouteParameter]`, i.e. `$request->route()`, so give the converted request a route that carries
   the matched parameters:

   ```php
   $route = new \Illuminate\Routing\Route(methods: [$method], uri: $pattern, action: []);
   $route->bind($illuminateRequest);
   $illuminateRequest->setRouteResolver(static fn() => $route);
   ```

3. **The argument** — a value resolver for a `Data` subclass that returns `$class::from($illuminateRequest)`
   and turns `ValidationException::errors()` (keyed by field path) into your error response.
4. **The response** — `$data->toArray()` with the container booted. To hold a response to the document,
   `$class::validate($array)` runs the emitted `rules()` and `withValidator()` — measured on generated
   classes, it adds no rule inferred from the PHP types beside them.

A Laravel-mode application is the same minus step 2's route; see the FormRequest it already has.

### A body that is a list

A `requestBody` whose schema is `type: array` of a component has no class of its own — the item has one.
Validate it item by item and key the errors by index, the way a nested list reports them:

```php
/** @return list<Widget> */
function widgetsFrom(array $items): array
{
    $built = [];
    $errors = [];
    foreach (array_values($items) as $index => $item) {
        try {
            $built[] = Widget::validateAndCreate(is_array($item) ? $item : []);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $path => $messages) {
                $errors[$index . '.' . $path] = $messages;
            }
        }
    }
    if ($errors !== []) {
        throw ValidationException::withMessages($errors);
    }

    return $built;
}
```

### Blank strings and literal property names

Generated rules validate present blank strings against the same native rules as other values. An
unconstrained string can still be empty; a positive minLength, pattern, format or incompatible type
rejects it. Optional absence and schema-declared nullability remain separate from an empty string.

Property names containing `.` or `*` are rejected during generation — see
[A property name with a dot does not generate](#a-property-name-with-a-dot-does-not-generate).

### Coming from Laravel mode

The classes keep their names; what you read from them changes. laravel-data's own surface is public
readonly properties, so the generated class has no getters:

| Laravel mode | laravel-data mode |
|---|---|
| `$dto->getName()` | `$dto->name` |
| `$dto->isNameProvided()` | `! $dto->name instanceof Optional` |
| `$dto->getBornOn()` (the string) | `$dto->bornOn->format('Y-m-d')` |
| `$dto->getBornOnAsDateTime()` | `$dto->bornOn` |
| `UserRequest::fromValidated($request->validated())` via `UserRequestFormRequest` | `UserRequest::from($request)` |
| `$dto->toArray()` | `$dto->toArray()` |

Code that wants "the value, or null when it was not sent" — what a Laravel-mode getter returned — reads
better through a one-line helper of your own than through `instanceof Optional` at every call site:

```php
function valueOrNull(mixed $value): mixed
{
    return $value instanceof Optional ? null : $value;
}
```

It is not generated: a getter per property is exactly the surface laravel-data does without.
