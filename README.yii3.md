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
field "status" is required.
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
request came back as `field "at" is required.` for a value the client had sent.

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

### A container of dates holds strings, and says so

`#[ToDateTime]` is emitted on a temporal SCALAR only. Its resolver takes a `DateTimeInterface`, an int
or a non-empty string and fails on an array, so on a container the attribute converted nothing — four of
them sat on `array<DateTimeImmutable>` properties that held plain strings. They are gone, and the
docblock reads `array<string>`, which is what the property holds:

```php
/** @var array<string> */
public readonly array $dates;        // ["2026-03-10", "2026-03-11"]
```

`getData()` still writes the schema's own string form either way, so the wire is identical to every
other mode. Converting would mean emitting a `ParameterAttributeResolverInterface` of ours plus a
hydrator registration into code whose whole point is to depend only on yiisoft — see
[Temporal container items](README.support-matrix.md#temporal-container-items).

A schema whose ONLY temporal values are container items needs no `ext-intl` at all now, for the same
reason: no `#[ToDateTime]` is emitted for it.

### A container of enum members holds their values; the getter gives cases

A list or map of enum members (`array<Kind>`, `array<string, Kind>`) holds the backing values, typed
`array<string>`. `#[Collection(Kind::class)]` is not emitted: it drops a value the enum lacks before any
rule can report it, and it renumbers a map's keys. The getter returns the cases, and `create()` takes
them:

```php
/** @var array<string> */
public readonly array $kinds;      // ["round", "square"]

$filter->getKinds();               // [Kind::Round, Kind::Square]
```

## Setup you must not skip, part three: the validating hydrator

**Hydrate through `ValidatingHydrator`, and register `ValidateResolver` as an attribute resolver.** The
hydrator casts BEFORE any rule runs — `PhpNativeTypeCaster` turns `"abc"` into `0` and `2.5` into `2` for
an `int` property — so a rule on the property judges the cast value and passes it. Each scalar property
therefore also carries hydrator-validator's `#[Validate]`, which checks the RAW value before the cast:

```php
#[Integer(skipOnEmpty: new WhenNull())]
#[Validate(new Integer(skipOnEmpty: new WhenNull()))]
public readonly ?int $score;
```

The raw rule is the same lenient one — a numeric string from a query, a form or a database row passes
and the cast reads it right; what it refuses is a value the cast would corrupt.

```php
$validator = new Validator();
$validateResolver = new ValidateResolver($validator);   // the SAME instance the factory hands out
$hydrator = new ValidatingHydrator(
    hydrator: new Hydrator(
        new CompositeTypeCaster(new PhpNativeTypeCaster(), new EnumTypeCaster(), new HydratorTypeCaster()),
        new ContainerAttributeResolverFactory($container),   // resolves ValidateResolver to that instance
    ),
    validator: $validator,
    validateResolver: $validateResolver,
);

$input = $hydrator->create(ScoreRequest::class);
$result = $input->getValidationResult();   // the raw checks' verdict when one failed, the object's otherwise
```

Without the resolver registered, hydration fails loudly — the attribute factory cannot build it. With a
plain `Hydrator`, the raw checks are skipped silently and a corrupted value is stored, as before 2.15.104.

### Raw checks in a nested object

`ValidateResolver` writes an error under the PROPERTY name and nothing else — it is not told where the
object sits. So `{"range": {"begin": "nonsense"}}` is reported at `begin`, the same path a top-level
`begin` has, and `ValidatingHydrator` then skips the object's own validation, whose paths are complete.
A generated class cannot know the path either: one schema may be nested in several places.

The application can. Two classes replace `HydratorTypeCaster` and `ValidatingHydrator`: the caster
collects the errors of each object it builds separately, and puts the key it built the object for in
front of their paths.

```php
use ReflectionProperty;
use Yiisoft\Hydrator\Attribute\Parameter\Data;
use Yiisoft\Hydrator\Result;
use Yiisoft\Hydrator\TypeCaster\HydratorTypeCaster;
use Yiisoft\Hydrator\TypeCaster\TypeCastContext;
use Yiisoft\Hydrator\TypeCaster\TypeCasterInterface;
use Yiisoft\Hydrator\Validator\Attribute\ValidateResolver;
use Yiisoft\Validator\Result as ValidationResult;

final class NestedPathTypeCaster implements TypeCasterInterface
{
    private ?ValidationResult $result = null;

    public function __construct(
        private readonly ValidateResolver $validateResolver,
        private readonly TypeCasterInterface $caster = new HydratorTypeCaster(),
    ) {
    }

    /** Where `ValidateResolver` writes; `null` when nothing collects raw errors. */
    public function collectInto(?ValidationResult $result): void
    {
        $this->result = $result;
        $this->validateResolver->setResult($result);
    }

    public function cast(mixed $value, TypeCastContext $context): Result
    {
        $outer = $this->result;
        if ($outer === null || !is_array($value)) {
            return $this->caster->cast($value, $context);
        }

        $inner = new ValidationResult();
        $this->collectInto($inner);
        try {
            return $this->caster->cast($value, $context);
        } finally {
            $this->collectInto($outer);
            $key = $this->wireName($context);
            foreach ($inner->getErrors() as $error) {
                $outer->addError($error->getMessage(), $error->getParameters(), [$key, ...$error->getValuePath()]);
            }
        }
    }

    private function wireName(TypeCastContext $context): string
    {
        $reflection = $context->getReflection();
        $data = $reflection instanceof ReflectionProperty ? $reflection->getAttributes(Data::class) : [];
        $name = $data === [] ? null : ($data[0]->getArguments()[0] ?? null);

        return is_string($name) ? str_replace('\.', '.', $name) : $reflection->getName();
    }
}
```

```php
use Yiisoft\Hydrator\DataInterface;
use Yiisoft\Hydrator\HydratorInterface;
use Yiisoft\Hydrator\Validator\ValidatedInputInterface;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\ValidatorInterface;

final class PathAwareValidatingHydrator implements HydratorInterface
{
    public function __construct(
        private readonly HydratorInterface $hydrator,
        private readonly ValidatorInterface $validator,
        private readonly NestedPathTypeCaster $paths,
    ) {
    }

    public function hydrate(object $object, array|DataInterface $data = []): void
    {
        $result = new Result();
        $this->paths->collectInto($result);
        try {
            $this->hydrator->hydrate($object, $data);
        } finally {
            $this->paths->collectInto(null);
        }
        $this->validated($object, $result);
    }

    public function create(string $class, array|DataInterface $data = []): object
    {
        $result = new Result();
        $this->paths->collectInto($result);
        try {
            $object = $this->hydrator->create($class, $data);
        } finally {
            $this->paths->collectInto(null);
        }
        $this->validated($object, $result);

        return $object;
    }

    private function validated(object $object, Result $result): void
    {
        if (!$object instanceof ValidatedInputInterface) {
            return;
        }

        $result->isValid()
            ? $this->validator->validate($object)
            : $object->processValidationResult($result);
    }
}
```

```php
$validator = new Validator();
$paths = new NestedPathTypeCaster(new ValidateResolver($validator));   // that resolver in the container too
$hydrator = new PathAwareValidatingHydrator(
    hydrator: new Hydrator(
        new CompositeTypeCaster(new PhpNativeTypeCaster(), new EnumTypeCaster(), $paths),
        new ContainerAttributeResolverFactory($container),
    ),
    validator: $validator,
    paths: $paths,
);
```

`range.begin` and `range.inner.begin` come out whole. An item of a LIST is not covered: `#[Collection]`
builds items through the hydrator rather than a type caster, so a raw error in one keeps the property
path without the list and its index.

## What the generated class looks like

```php
#[FromBody]
#[Callback(method: 'validateOpenApiConstraints')]
final class UpdatePost extends AbstractInput implements DataSetInterface, RulesProviderInterface, JsonSerializable
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

    // `json_encode()` writes what `getData()` returns: dates and enums in wire form, `{}` when empty.
    public function jsonSerialize(): mixed { … }
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
and the emitted interpreter reports that as `field "title" is required.` — once, and only for that key.

**The class has no constructor, and that is the point.** With none, the hydrator fills PROPERTIES
directly — so a key the payload did not carry leaves its property uninitialised, which is PHP's own
record of what was absent. A property sent as `null` IS initialised. That single fact gives PATCH
semantics with nothing invented. To build one in code, use the static `create()` — see
[Building a response](#building-a-response).

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

## Schema defaults

An optional property with a schema `default` reads as that default when the key is absent:
`getSize()` answers `10` while `isSizeProvided()` stays false. The property itself stays uninitialised
— it records what was sent — so `getData()` leaves the key out, as runtime and Laravel mode do. A list or
map default applies too when it is `[]`, or a list of plain scalars (`default: [1, 2]`); one naming
objects, enum members or dates is not, since the property holds those in another form. Where the schema
does not let the property be null, the getter cannot answer null either, and its return type says so:
`getSize(): int`, `getIds(): array`.

A `required` property with a `default` is the same: the key may be left out and the getter answers the
default, as in every other mode. OpenAPI applies a default only to an absent key, which `required`
forbids, so the generator warns about the pair.

## Rule mapping

Natively expressed by `yiisoft/validator`:

| OpenAPI | Rule |
|---|---|
| `type: string` / `integer` / `number` / `boolean` | `#[StringValue]`, `#[Integer]`, `#[Number]`, `#[BooleanValue]` — NOT redundant with the PHP type: the hydrator's `PhpNativeTypeCaster` coerces, so `{"f":5}` filled a `string $f` with `"5"` until these were emitted |
| `minLength` / `maxLength` | `#[Length(min:, max:)]` |
| `minimum` / `maximum` | `#[GreaterThanOrEqual]` / `#[LessThanOrEqual]` with `type: original`, preserving integer and float comparisons |
| `exclusiveMinimum` / `exclusiveMaximum` as a NUMBER | `#[GreaterThan]` / `#[LessThan]` |
| `exclusiveMinimum: true` beside `minimum` (OpenAPI 3.0) | no rule — `#[GreaterThanOrEqual]` is the wrong comparison, so the whole bound goes to the interpreter |
| `pattern` | `#[Regex]` |
| `minItems` / `maxItems` | `#[Count(min:, max:)]` |
| `format: email` / `uuid` / `uri` / `ipv4` / `ipv6` | `#[Email]`, `#[Uuid]`, `#[Url]`, `#[Ip]` |
| `format: date` / `date-time` | `#[Date]` / `#[DateTime]`, plus stacked `#[ToDateTime]` for hydration |
| nested object | `#[Nested]` — argument-free; it cascades into the nested class's own attributes |
| list of nested objects | `#[Each(new Nested())]` plus `#[Collection(…)]` for hydration |
| `enum` | backed enums become the property TYPE; inline enums use JSON equality in the interpreter |

Everything else reaches the emitted interpreter through the class-level `#[Callback]`: `oneOf`, `not`,
`if`/`then`/`else`, `contains`, `prefixItems`, `unevaluated*`, `propertyNames`, `patternProperties`,
`dependentRequired`, `dependentSchemas`, `discriminator`, `content*`, `minProperties`/`maxProperties`,
`multipleOf`, `const`, every `format` absent from the table above — and **`uniqueItems`**.

`uniqueItems` is in that list rather than the table on purpose. `#[UniqueIterable]` was emitted for it
and then measured: `['a','a']` came back VALID. The rule guards the ITEM TYPES an iterable may hold
(scalars, `Stringable`, `DateTimeInterface`), not their distinctness, so it enforced nothing the
keyword asks for and was dropped.

## `readOnly` inside `required`

A `readOnly` property is server-owned: it is already in `OPENAPI_WRITE_ONLY`, the list of names the
hydrator leaves out of the input. Listing it under `required` puts that requirement on the RESPONSE
alone — OpenAPI is explicit — so the property is typed nullable and its name is taken out of the
interpreter's `required` list. Until 2.15.36 the emitted class contradicted itself: it refused to read
the field from a request and then demanded the field be there.

## Building a response

The class has no constructor — the hydrator fills its properties, and an uninitialised one is how
"absent" is recorded. To build one in code there is `create()`, with an argument per property, so a
typo or a wrong type is caught by the IDE and PHPStan:

```php
$response = PersonView::create(
    name: $name,
    photo: $photoOrNull,              // `nullable: true` — passed null, it is written as `"photo": null`
    kind: PersonKind::Guest,          // enums, dates and nested objects as the getters return them
    tags: [Tag::create(name: 'a')],   // a list, or a map, of objects built beforehand is kept
    // an optional argument left out is "not sent": its key is not written
);
```

Required properties are the leading arguments, optional ones follow. What an optional argument means:

| the property | left out | passed `null` |
|---|---|---|
| `nullable: true` | not sent | written as `null` |
| not nullable | not sent | not sent — `null` is not a value it may have |

That is runtime mode's behaviour. A `null` default could not tell the first row's two columns apart, so a
nullable argument defaults to a fresh `stdClass` instead — a value no such property can hold, and no
class of this package; the docblock keeps the real type (`@param ?string $photo`). Two helpers return a
copy: `withoutProperty('<openapi name>')` without a key, and `withProvided('<openapi name>')` with a key
sent as `null` — for a property that is not nullable that is a payload meant to fail.

Building from an array through the hydrator still works, keyed by the OpenAPI names (`first_name`, not
`firstName`). Two things to know about that path:

- a typo in an optional key is silent — the property stays absent — so validate responses in
  development and tests (`$validator->validate($response)`);
- a list of nested objects must be given as ARRAYS. yiisoft's `CollectionResolver` skips every item that
  is not an array or a `DataInterface`, so a list of instances built beforehand comes back empty. Pass
  arrays, use `create()`, or register a resolver of your own under `CollectionResolver::class` that
  keeps instances of the item class.

### A map of objects

`additionalProperties: {$ref: Detail}` is not built by the hydrator — `#[Collection]` would renumber the
keys — so after hydration it holds the decoded arrays, and after `create()` the objects it was given. It
is declared `array<string, Detail|array<string, mixed>>` for that reason; `create()` takes
`array<string, Detail>`. Its values are validated either way.

Yii3 has no attribute that builds a map: `#[Collection]` appends (`$collection[] = …`), and a resolver of
your own cannot be named on a generated property. Build the values where you read them — the hydrator
that built the input builds them, and `array_map()` keeps the keys:

```php
$byId = array_map(
    static fn(Detail|array $detail): Detail => $detail instanceof Detail
        ? $detail
        : $hydrator->create(Detail::class, $detail),
    $input->getById() ?? [],
);
```

The values were already validated with the input, so a plain `Hydrator` is enough here.

### A union: `oneOf`, `anyOf` and a discriminated `allOf` base

A union is an INTERFACE here, as in Symfony mode, and its members implement it — an `allOf` variant too:
the classes are flattened, so it cannot extend its base. The hydrator cannot build an interface, so a
property typed by one is declared `Report|array`:

- from a payload it holds the decoded array, and the interpreter checks it against the member the
  discriminator names (or against every member of a `oneOf`) — `getMeta()` hands you that array; build
  the member yourself when you need the object: `$hydrator->create(AlphaReport::class, $input->getMeta())`;
- `create()` takes the member object, `AlphaReport::create(...)`.

The interface declares the getters its members share — same name, same type in every one — so code
typed by it reads `$event->getUuid()` directly. A property whose type differs between members is left
to the member.

The same holds for the items of a list or map of a union.

A union of an enum and a scalar (`oneOf: [{$ref: Scope}, {type: integer}]`) is declared with `string`
and `int` both — `Scope|int|string` — so the value is kept as sent and the interpreter checks it; the
getter returns the case for a listed value (`Scope::All`) and the scalar otherwise.

### A body that is a union

The hydrator cannot build the interface an action argument would be typed by. A discriminated union
names its members instead, so a resolver of yours can pick one:

```php
interface Ident
{
    public const string OPENAPI_DISCRIMINATOR = 'entityType';
    public const array OPENAPI_DISCRIMINATOR_MAPPING = [
        'city' => CityIdent::class,
        'region' => RegionIdent::class,
    ];
}

$body = $request->getParsedBody();
$class = Ident::OPENAPI_DISCRIMINATOR_MAPPING[$body[Ident::OPENAPI_DISCRIMINATOR] ?? ''] ?? null;
if ($class === null) {
    // 400: unknown or missing entityType
}
$input = $validatingHydrator->create($class, $body);
```

The constants are emitted only where every member implements this one union interface: a class that
inherits the same constant from two interfaces does not load.

## A body that is a list

`requestBody: {type: array, items: {$ref: Widget}}` has no class of its own — only `Widget` does — and
an action argument is one input class. Read the list yourself and hydrate each item through the
validating hydrator:

```php
$body = $request->getParsedBody();
if (!is_array($body) || !array_is_list($body)) {
    // 400: the body must be a JSON array
}

$widgets = [];
$errors = [];
foreach ($body as $index => $item) {
    $widget = $validatingHydrator->create(Widget::class, is_array($item) ? $item : []);
    foreach ($widget->getValidationResult()?->getErrorMessagesIndexedByPath() ?? [] as $path => $messages) {
        $errors["$index.$path"] = $messages;   // `1.title`, as the other modes report it
    }
    $widgets[] = $widget;
}
```

## A property named like a method of the class

The class implements `DataSetInterface` and `RulesProviderInterface`, so a property `data`, `rules`,
`propertyValue` or `validationResult` cannot have the getter its name suggests — `getData()` is the data
set's. Its getter takes a `Value` suffix instead: `getDataValue()`, `getRulesValue()`.

## Boolean parameters

A query, path, header or cookie parameter arrives as a string, and OpenAPI writes a boolean as
`true` / `false`. The hydrator's cast is `(bool)`, which made `?force=false` TRUE, and `BooleanValue`
takes only `"1"` and `"0"`. Such a property is declared `bool|string`, which the cast leaves alone; the
getter returns the boolean the string names (`true`/`1` and `false`/`0`), and anything else is refused as
`field "force" must be of type boolean.` Read it through `getForce()` — the property itself holds what
was sent.

A list of integers, numbers or booleans in a parameter — `?ids[]=11&ids[]=22` — is the same: nothing
casts the items, so the property holds the strings and the getter hands out `[11, 22]`. The check reads
the items the same way, and one that does not read as its type is refused under its index (`ids.1`).

## Divergences from the other modes

- **no 422 for free** — the action reads `getValidationResult()`, as above;
- **`in: header` and `in: cookie` are not bound.** `input-http` ships `#[Body]`, `#[Query]`,
  `#[Request]` and `#[UploadedFiles]` and has no header or cookie attribute at all. Path parameters
  arrive through `#[Request]`, which reads PSR-7 request attributes — where routers put them;
- **an out-of-range enum value is refused with Yii's own `In` message**, not one naming the allowed
  values. The hydrator cannot cast `"nope"` to the generated enum, so it skips the property, and a
  skipped property is indistinguishable from one the payload never carried — for an OPTIONAL property
  the value was simply lost and the request answered as if it had no filter. Every enum property
  therefore carries `#[Validate(new In([...]))]`, a check of the RAW value before the cast: strict in a
  body, which is JSON, lenient for a parameter, which arrives as text (`?level=1`). A date the
  hydrator cannot parse was lost the same way, and is checked the same way — against the formats
  `#[ToDateTime]` is given, with Yii's "must be a date" message. So is a list, a map or an object sent
  as a scalar in a BODY — `{"ids": "x"}` — with `#[Validate(new Each([]))]` and "must be an array" /
  "must be an object". Not for a parameter: a query string writes a one-item list `?ids=1`, which
  arrives as a string;
- **a raw-check error in a nested object has only the property's own path** unless the application
  wires the recipe in [Raw checks in a nested object](#raw-checks-in-a-nested-object);
- **a map of objects in a PAYLOAD is held as the decoded arrays** — `#[Collection]` would renumber
  the keys; see [A map of objects](#a-map-of-objects) for building them;
- **a union in a PAYLOAD is held as the decoded array**, not built — a `oneOf`/`anyOf` and an `allOf`
  discriminated base alike. The hydrator casts to the DECLARED type, an interface it cannot instantiate,
  so the property is `Union|array` and the interpreter checks the array — see
  [A union](#a-union-oneof-anyof-and-a-discriminated-allof-base). The interface is still the type for a
  response and for code that constructs a member itself — see the [support matrix](README.support-matrix.md#divergences).

## Testing generated classes without a Yii3 application

A bare `new Hydrator()` cannot create these classes: `#[FromBody]` resolves through a resolver whose
constructor requires a `RequestProviderInterface`, and the default reflection-based resolver factory
refuses anything with required constructor arguments. Supply the resolvers from a container instead —
`tests/Yii3/Yii3Container.php` in this repository is the smallest wiring that works, and it is what
the mode's own tests drive the generated code through.
