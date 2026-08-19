# yii3 mode

[← back to the main README](README.md) · [support matrix](README.support-matrix.md) · other mode guides: [runtime](README.runtime.md) · [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md)

```bash
vendor/bin/console openapi:generate-dto \
    --file=openapi.yaml \
    --directory=src/Input \
    --namespace='App\Input' \
    --attributes=yii3
```

One [`yiisoft/input-http`](https://github.com/yiisoft/input-http) class per schema, decorated with
[`yiisoft/validator`](https://github.com/yiisoft/validator) attributes and populated by
[`yiisoft/hydrator`](https://github.com/yiisoft/hydrator).

```php
public function store(UpdatePost $input): ResponseInterface
{
    $result = $input->getValidationResult();      // Yii3 does NOT turn this into a 422 for you
    if (!$result->isValid()) {
        return $this->unprocessable($result);
    }

    return $this->ok($input->getTitle());
}
```

```bash
composer require yiisoft/validator yiisoft/hydrator yiisoft/hydrator-validator yiisoft/input-http
```

**Yii3 applications are already supported without this mode.** [Runtime mode](README.runtime.md)
covers any PSR-7 framework through `DtoDeserializerPsr7`, Yii3 included. What this mode buys is
native-attribute ergonomics — the generated class looks like one you would write by hand, and your own
translations apply to the messages — not capability.

## The one honest difference from the other framework modes

| Mode | What happens before the action body runs |
|---|---|
| symfony | `#[MapRequestPayload]` denormalizes AND validates; a failure IS a 422 |
| laravel | the FormRequest is resolved and validated; a failure IS a 422 |
| laravel-data | the `Data` object is resolved and validated; a failure IS a 422 |
| **yii3** | **hydrated, and validated — but the ACTION reads the verdict and decides** |

`input-http` hydrates automatically; `hydrator-validator` validates through `ValidatedInputInterface`,
which the emitted class implements via `AbstractInput`. Turning a failed result into a response is
application code, or one middleware you write once. No generated code can close that gap.

## Setup you must not skip: the enum type caster

**Register `EnumTypeCaster`, or every generated enum property is unfillable.** The default hydrator
uses `CompositeTypeCaster(PhpNativeTypeCaster, HydratorTypeCaster)` — no enum support — and a string
never becomes a backed enum.

Nothing throws. The hydrator simply skips the property, which leaves it uninitialised, and that is
exactly how this mode records "the client did not send this key". So a request that DID carry
`{"status":"queued"}` comes back as:

```
field "status" is required
```

The symptom accuses the client of omitting a value it sent, and says nothing about enums — which is
exactly why this section exists.

```php
new Hydrator(
    new CompositeTypeCaster(
        new PhpNativeTypeCaster(),
        new EnumTypeCaster(),      // ← generated enum properties need this
        new HydratorTypeCaster(),
    ),
);
```

## Setup you must not skip, part two: ext-intl for dates

A `format: date` or `format: date-time` property is emitted with `#[ToDateTime]`, the hydrator's own
casting attribute — without it the string never becomes a `DateTimeImmutable`, the hydrator skips the
property, and a value the client DID send reads back as "not provided", exactly as with enums above.

The attribute takes ONE format, so several are **stacked** on the property, one per wire pattern:

```php
#[ToDateTime(format: 'php:Y-m-d\TH:i:sP')]
#[ToDateTime(format: 'php:Y-m-d\TH:i:s.up')]
#[ToDateTime(format: 'php:Y-m-d H:i:s')]
#[ToDateTime(format: 'php:Y-m-d\TH:i:s')]
public readonly DateTimeImmutable $at;
```

Measured: the hydrator tries each in turn and the first that parses wins. The four are exactly
`GeneratedDtoInterface::DATE_TIME_FORMATS`, so this mode accepts what every other mode accepts — and
still refuses what they refuse, since a loose `"yesterday"` matches none of them. One rigid pattern was
a real bug: `2026-03-10T12:00:00.123456+03:00` failed to parse, the property was skipped, and the
request came back as `field "at" is required` for a value the client had sent.

`format: time` is NOT one of these. It stays a `string`, because the `Rule\Date\*` family needs a
`DateTimeInterface` — measured over a plain string, `#[Time]` rejected `13:45:00Z`, a value the
document allows. The interpreter owns that format instead and refuses `99:99` like every other mode.

**That attribute needs ext-intl.** `yiisoft/hydrator`'s own `composer.json` says so
(`"ext-intl": "Allows using ToDateTime parameter attribute"`), and without the extension every request
carrying a temporal property fails at hydration with `Class "IntlDateFormatter" not found`.

```bash
# Debian/Ubuntu
apt-get install php-intl
# macOS, Homebrew PHP
pecl install intl
```

If you cannot install it, a spec with no `format: date`/`date-time` property is unaffected.

## What the generated class looks like

```php
#[FromBody]
#[Callback(method: 'validateOpenApiConstraints')]
final class UpdatePost extends AbstractInput implements DataSetInterface, RulesProviderInterface
{
    #[StringValue(skipOnEmpty: new WhenNull())]
    #[Length(min: 3, max: 120, skipOnEmpty: new WhenNull())]
    public readonly string $title;

    #[StringValue(skipOnEmpty: new WhenNull())]
    #[Email(skipOnEmpty: new WhenNull())]
    public readonly string $email;

    /** @var array<Tag> */
    #[Collection(Tag::class)]
    #[Each(new Nested(), skipOnEmpty: new WhenNull())]
    public readonly array $tags;

    #[StringValue(skipOnEmpty: new WhenNull())]
    #[Length(min: 3, skipOnEmpty: new WhenNull())]
    public readonly ?string $subtitle;

    public function getTitle(): string { … }
    public function getSubtitle(): ?string { … }
    public function isSubtitleProvided(): bool { … }

    // The framework's own data-set contract: this is what makes presence idiomatic.
    public function getRules(): iterable { return (new ObjectParser($this))->getRules(); }
    public function hasProperty(string $property): bool { … }
    public function getPropertyValue(string $property): mixed { … }
    public function getData(): ?array { … }
}
```

`skipOnEmpty: new WhenNull()` is on EVERY rule, required and nullable properties included. It is the
framework's own condition, and `getPropertyValue()` answers null for a key the payload never carried,
so this one condition covers absence and an explicit null alike.

That leaves the interpreter as the single judge of both, which is the point. Without it an absent
required key produced a message from every rule the property carries — five lines for one missing key —
and an optional `string` sent as null produced three: the interpreter's "must be of type string" plus
"must be a string" and "must be a string. null given." from the rules. One mistake, one message.

There is no `#[Required]`. Yii's rule means "not blank" while OpenAPI's keyword means "the key is
present", and the two disagree on every empty value — an explicit `null` and a legal `{}` were both
rejected. Presence needs no rule here either: an absent required key leaves its property uninitialised,
and the emitted interpreter reports that as `field "title" is required` — once, and only for that key.

**The class has no constructor, and that is the point.** With none, the hydrator fills PROPERTIES
directly — so a key the payload did not carry leaves its property uninitialised, which is PHP's own
record of what was absent. A property sent as `null` IS initialised. That single fact gives PATCH
semantics with nothing invented.

`#[FromBody]` (or `#[FromQuery]`) appears only on classes that ARE a request payload. A nested schema
carries no source attribute — with one, it would re-read the whole request body instead of the value
it is being hydrated from.

## Presence tracking (PATCH / partial updates)

Yii3 has no `Optional`, and the hydrator says nothing about which keys arrived. Presence is therefore
read off the one signal PHP already keeps: a key the payload did not carry leaves its typed property
uninitialised, while a key sent as `null` initialises it to `null`. `ReflectionProperty::isInitialized()`
tells the two apart, and nothing else is needed.

```php
if ($input->isSubtitleProvided()) {
    $post->setSubtitle($input->getSubtitle());   // only touch what was actually sent
}
```

`isXxxProvided()` is a friendly alias; the framework's own answer is `hasProperty('subtitle')`, which
the validator reads to decide whether a property is missing. `getPropertyValue()` answers null for the
same property, and that is what makes `skipOnEmpty: new WhenNull()` cover absence as well as an
explicit null.

**Why the class implements two interfaces, and why it must be both.** `DataSetInterface` alone is a
trap that was measured before this shape was chosen:

| | required key missing | optional present but invalid |
|---|---|---|
| attributes only | rejected | rejected |
| **`DataSetInterface` only** | **accepted** | **accepted** |
| `DataSetInterface` + `RulesProviderInterface` | rejected | rejected |

The middle row is not "an absent field is fine" — it is validation switched off entirely, in the
library's own words (`ObjectDataSet::getRules()`):

```php
// Providing data set assumes object has its own rules getting logic.
// So further parsing of rules is skipped intentionally.
```

So `getRules()` re-exposes the attributes through the validator's public `ObjectParser`, and the class
stays attribute-driven while gaining a first-class presence answer.

**Nothing is generated beside the DTOs.** No sentinel type, no marker class, no presence flags —
`hasProperty()` is `property_exists()` plus `ReflectionProperty::isInitialized()`, and both come from
the language.

An earlier version of this mode did emit a sentinel enum, and it worked; it was removed because it put
a type no Yii3 developer has ever seen into every optional property. The uninitialised-property route
gives the same answer using only what the framework and PHP already provide.

## Rule mapping

Natively expressed by `yiisoft/validator`:

| OpenAPI | Rule |
|---|---|
| `type: string` / `integer` / `number` / `boolean` | `#[StringValue]`, `#[Integer]`, `#[Number]`, `#[BooleanValue]` — NOT redundant with the PHP type: the hydrator's `PhpNativeTypeCaster` coerces, so `{"f":5}` filled a `string $f` with `"5"` until these were emitted |
| `minLength` / `maxLength` | `#[Length(min:, max:)]` |
| `minimum` / `maximum` | `#[GreaterThanOrEqual]` / `#[LessThanOrEqual]` |
| `exclusiveMinimum` / `exclusiveMaximum` as a NUMBER | `#[GreaterThan]` / `#[LessThan]` |
| `exclusiveMinimum: true` beside `minimum` (OpenAPI 3.0) | no rule — `#[GreaterThanOrEqual]` is the wrong comparison, so the whole bound goes to the interpreter |
| `pattern` | `#[Regex]` |
| `minItems` / `maxItems` | `#[Count(min:, max:)]` |
| `format: email` / `uuid` / `uri` / `ipv4` / `ipv6` | `#[Email]`, `#[Uuid]`, `#[Url]`, `#[Ip]` |
| `format: date` / `date-time` | `#[Date]` / `#[DateTime]`, plus stacked `#[ToDateTime]` for hydration |
| nested object | `#[Nested]` — argument-free; it cascades into the nested class's own attributes |
| list of nested objects | `#[Each(new Nested())]` plus `#[Collection(…)]` for hydration |
| `enum` | the generated backed enum becomes the property TYPE |

Everything else reaches the emitted interpreter through the class-level `#[Callback]`: `oneOf`, `not`,
`if`/`then`/`else`, `contains`, `prefixItems`, `unevaluated*`, `propertyNames`, `patternProperties`,
`dependentRequired`, `dependentSchemas`, `discriminator`, `content*`, `minProperties`/`maxProperties`,
`multipleOf`, `const`, every `format` absent from the table above — and **`uniqueItems`**.

`uniqueItems` is in that list rather than the table on purpose. `#[UniqueIterable]` was emitted for it
and then measured: `['a','a']` came back VALID. The rule guards the ITEM TYPES an iterable may hold
(scalars, `Stringable`, `DateTimeInterface`), not their distinctness, so it enforced nothing the
keyword asks for and was dropped.

## Divergences from the other modes

- **no 422 for free** — the action reads `getValidationResult()`, as above;
- **`in: header` and `in: cookie` are not bound.** `input-http` ships `#[Body]`, `#[Query]`,
  `#[Request]` and `#[UploadedFiles]` and has no header or cookie attribute at all. Path parameters
  arrive through `#[Request]`, which reads PSR-7 request attributes — where routers put them;
- **an out-of-range enum value is reported as a MISSING key.** Elsewhere it is a message naming the
  allowed values. Here the hydrator cannot cast `"nope"` to the generated enum, so it skips the
  property — and a skipped property is indistinguishable from one the payload never carried, which is
  the same signal this mode reads for presence. The verdict is still "invalid"; the sentence is
  `field "status" is required`. Nothing in generated code can separate the two cases, because the raw
  value never reaches the object;
- **a discriminated union in a PAYLOAD is not built.** The hydrator casts to the DECLARED type, and
  the declared type is the union interface. The interface is still the right type for a response and
  for code that constructs a member itself — see the [support matrix](README.support-matrix.md#divergences).

## Testing generated classes without a Yii3 application

A bare `new Hydrator()` cannot create these classes: `#[FromBody]` resolves through a resolver whose
constructor requires a `RequestProviderInterface`, and the default reflection-based resolver factory
refuses anything with required constructor arguments. Supply the resolvers from a container instead —
`tests/Yii3/Yii3Container.php` in this repository is the smallest wiring that works, and it is what
the mode's own tests drive the generated code through.
