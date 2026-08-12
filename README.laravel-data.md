# laravel-data mode

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · other mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md)

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
        public readonly string|Optional $nickname,
        public readonly string|null|Optional $middleName,
        #[MapName('born_on')]
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d'])]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public readonly DateTimeImmutable|Optional $bornOn,
        #[DataCollectionOf(Address::class)]
        public readonly array|Optional $addresses,
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

  On `validateAndCreate($array)` there is no request, so that single check is skipped and everything else
  still runs.

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


## Divergences from the other modes

All measured and pinned; the full table with reasons is in
[README.support-matrix.md](README.support-matrix.md#divergences). In short, laravel-data:

- keeps the PHP array where runtime casts a map to `stdClass`, so an empty map encodes as `[]` rather
  than `{}` — the same difference Symfony mode has, for the same reason;
- drops sub-second precision from a `format: date-time` **response** (its transformer takes one format
  string, which cannot say "keep the precision the payload carried"); the emitted `#[WithCast]` accepts
  all four patterns on the way in;
- echoes a `readOnly` property the client sent — `#[Hidden]` is an exact counterpart to `writeOnly` and
  there is none for `readOnly`;
- normalizes a discriminated union member with the discriminator LAST, because it is inherited from the
  abstract base and PHP lists a class's own properties first. Same keys, same values.

Everything in the validation vocabulary is identical to the other three modes, including
`additionalProperties: false` on a DTO-shaped schema, which the two hydrating modes cannot see at all.


## Testing generated classes without a Laravel application

laravel-data resolves `DataConfig`, its resolvers and its pipeline out of the container, so its
`from()` / `validate()` paths need one. This package is framework-agnostic and does **not** pull
`orchestra/testbench` to get it: `tests/LaravelData/LaravelDataContainer` builds what is actually needed —
a plain `Illuminate\Container\Container`, a config repository, the two singletons
`LaravelDataServiceProvider::packageRegistered()` binds, and four global helpers
(`app()`, `resolve()`, `config()`, `rescue()`) that `illuminate/foundation` would otherwise define.

Copy it if you want the same in your own package's tests; in a real application none of it applies, since
the framework provides all of it.
