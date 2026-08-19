# Runtime mode

[← back to the main README](README.md) · other modes: [symfony](README.symfony.md) · [laravel](README.laravel.md) · [laravel-data](README.laravel-data.md) · [yii3](README.yii3.md) · [support matrix](README.support-matrix.md) · [performance](README.performance.md)

The default mode (`--attributes=runtime`, or simply omitted). DTOs implement `GeneratedDtoInterface`
and carry metadata methods; **this library's own services** do the work:

| Service | Job |
|---|---|
| `DtoDeserializer` | a Symfony/PSR-7 `Request` → a validated DTO (body, query, path, headers, cookies, files) |
| `DtoValidator` | the OpenAPI vocabulary, enforced against a DTO or a payload |
| `DtoNormalizer` | a DTO → array/JSON for a response, with or without validating first |

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test
  # --attributes=runtime is the default
```

```php
// generated in runtime mode (excerpt)
final class User implements GeneratedDtoInterface, Stringable
{
    // presence flags per property: $nameInRequest, $emailInRequest, … (what was actually sent)

    /**
     * @param string $name
     * Constraints: minLength=2, maxLength=50
     * @param string|UnsetValue|null $email
     * Constraints: format=email
     */
    public function __construct(
        private readonly string $name,
        private readonly string|UnsetValue|null $email = UnsetValue::UNSET,
        private readonly Address|UnsetValue|null $address = UnsetValue::UNSET,
    ) {
        $this->emailInRequest = $email !== UnsetValue::UNSET; // presence tracking (PATCH-friendly)
        // …
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email !== UnsetValue::UNSET ? $this->email : null;
    }

    // + isNameInRequest()/isNameRequired()/…, toArray(), jsonSerialize(),
    //   getNormalizationMap(), getAliases(), getConstraints() — consumed by the runtime services
}
```

## Request in, response out

```php
$deserializer = new DtoDeserializer();
$normalizer   = new DtoNormalizer();

// request: deserialize + validate in one step (throws on the first schema violation)
$dto = $deserializer->deserialize($request, UserPostRequest::class);

// a top-level JSON array body (bulk endpoints)
$items = $deserializer->deserializeCollection($request, Item::class);

// one already-decoded JSON value, without a Request (batch endpoints, see below)
$item = $deserializer->deserializeValue($decodedElement, Item::class, '3');

// response: validate then normalize, or normalize alone when the data is already trusted
$payload = $normalizer->validateAndNormalizeToArray($dto);
$payload = $normalizer->toArray($dto);
$json    = $normalizer->toJson($dto);

// validation without normalizing — returns a list of messages, empty when valid
$errors = $normalizer->validate($dto);
```

Errors are thrown as a `RuntimeException` whose message aggregates every violation, each naming the
OpenAPI rule that failed (`field "tags"[0] length must be at least 3 characters`).

### Batch endpoints: accepting the good elements, reporting the bad ones

`deserializeCollection()` is all-or-nothing — it collects every element's error and throws them as one
exception, so a single malformed element fails the whole request. A batch endpoint that must answer
"element 3 was rejected, the rest were accepted" loops over the decoded body itself and takes one
element at a time with `deserializeValue()`:

```php
$elements = json_decode($request->getContent(), false);

$accepted = [];
$errors   = [];
foreach ($elements as $index => $element) {
    try {
        // Same cast one element of a collection body goes through: scalars, enums, dates,
        // nested DTOs, discriminator resolution. $index names the element in the message.
        $accepted[] = $deserializer->deserializeValue($element, Item::class, (string)$index);
    } catch (RuntimeException $e) {
        $errors[$index] = $e->getMessage();   // param "3.id" expects int, got string
    }
}
```

The third argument is what every error message names the value by; omit it and the message reads
`param "value"`. `$data` is a value produced by `json_decode($json, false)` — a `stdClass` for an
object, a list for an array, a scalar otherwise.

One wording caveat on this path: a missing required field on a nested DTO reports `Required parameter
"3.id" not found in request.` even though no `Request` is involved — the text comes from the shared
deserialization core, where it is accurate. Read "in request" as "in the payload".

#### Element shapes the items schema knows and a bare type name does not

A DTO property infers `nullable` items and a `format: date` item type from its own schema. `$itemType`
and `$type` here are bare type names with no owning property, so nothing is there to infer from and the
two facts are passed in — without them a date-only element or a `null` element cannot be deserialized
at all:

```php
// items: {type: string, format: date}       — 'Y-m-d' for format: date, null (default) for date-time
$day = $deserializer->deserializeValue('2026-03-10', DateTimeImmutable::class, '3', false, 'Y-m-d');

// items: {type: integer, nullable: true}    — a null element is kept instead of being rejected
$maybe = $deserializer->deserializeValue(null, 'int', '3', true);

// the same two arguments on the all-or-nothing path
$days = $deserializer->deserializeCollection($request, DateTimeImmutable::class, false, 'Y-m-d');
$ints = $deserializer->deserializeCollection($request, 'int', true);
```

Both are opt-in and both default to the strict behaviour, so a call that omits them is unchanged.
`'Y-m-d'` narrows the cast rather than disabling it: `2026-13-99` still fails, and the message names
the narrowed format.

The declared return follows the nullable flag, so leaving it off keeps elements non-null for your
static analysis and turning it on makes it insist on a null check:

```php
$items = $deserializer->deserializeCollection($request, Item::class);        // array<int, Item>
$items = $deserializer->deserializeCollection($request, Item::class, true);  // array<int, Item|null>
```

Both arguments are on `DtoDeserializerInterface` as well as on the service, so the types behave the
same whichever you type-hint. That is why they are there: a conditional return needs the flag in
scope to condition on. Adding them was breaking for classes implementing the contract — see
[Upgrading](README.md#upgrading).

PSR-7 applications call `deserializeValue()` on `DtoDeserializer` directly — there is no
`deserializeValuePsr7()`, because the method takes an already-decoded value and no request, so there
would be nothing for the wrapper to convert.

## What sets this mode apart

### Presence tracking (PATCH / partial updates)

An optional property defaults to the `UnsetValue::UNSET` sentinel, so the DTO distinguishes
"the client did not send this key" from "the client sent `null`":

```php
if ($dto->isEmailInRequest()) {
    $user->setEmail($dto->getEmail()); // only touch what was actually sent
}
```

Symfony mode answers the same question with
[`isXxxProvided()`](README.symfony.md#presence-tracking-patch--partial-updates), recorded by the
setter; here it is a property of the object itself, and the DTO stays immutable.

### Binding parameters the way the spec says

The deserializer reads each property from the source the spec declares and applies the OpenAPI
serialization rules before casting:

| Spec | Behaviour |
|---|---|
| `in: path` / `query` / `header` / `cookie` | the property is read ONLY from that source (`getParameterSources()`) |
| `style` + `explode` (`form`, `simple`, `matrix`, `label`, `spaceDelimited`, `pipeDelimited`, `deepObject`) | the raw string is split accordingly (`getParameterStyles()`) |
| `allowReserved` | literal `+` and reserved characters are preserved in the raw query string |
| `allowEmptyValue: false` | an empty value is rejected; `true` and silence both accept it |
| `in: querystring` (OAS 3.2) | the whole raw query string is decoded per its single `content` media type |
| `content: {application/json: …}` on a parameter | the JSON string is decoded before validation and casting |
| Encoding Object on a multipart body | a JSON part is decoded, a `style`-carrying part is split |

Laravel route parameters (`/users/{id}`) resolve automatically; see below.

### readOnly / writeOnly, enforced

`DtoNormalizer` drops `writeOnly` fields from the response, and `DtoDeserializer` refuses a
`readOnly` value coming from the client. Nothing to configure. (In Symfony mode the same keywords
become serialization groups the application has to pass — see
[the Symfony guide](README.symfony.md#serialization-groups-readonly--writeonly).)

### Free-form objects

`{type: object}` with no `properties` — and its longer spelling `{type: object, additionalProperties: true}`
— becomes `array<string, mixed>` and keeps whatever the client sent, at property level, inside
`items`, and through a `$ref` to such a component.

## Framework-agnostic deserialization (PSR-7)

`deserialize()` accepts a Symfony `Request` — which also covers **Laravel** (its
`Illuminate\Http\Request` extends the Symfony one). Laravel route parameters (`/users/{id}`) are
bridged automatically: `deserialize()` reads them from `$request->route()->parameters()` when
present, so path params resolve with no extra wiring. For any other stack (Slim, Mezzio, Laminas,
Yii3, …) that speaks **PSR-7**, use `DtoDeserializerPsr7`: it converts a PSR-7
`ServerRequestInterface` into a Symfony `Request` via the official
[`symfony/psr-http-message-bridge`](https://github.com/symfony/psr-http-message-bridge) and
delegates to the core deserializer.

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7;
use Psr\Http\Message\ServerRequestInterface;

/** @var ServerRequestInterface $request */
$deserializer = new DtoDeserializerPsr7();

// Single object body:
$dto = $deserializer->deserializePsr7($request, UserPostRequest::class);

// Top-level JSON array body (bulk endpoints):
$items = $deserializer->deserializeCollectionPsr7($request, Item::class);

// …carrying the two items-schema facts, exactly as on the Symfony path:
$days = $deserializer->deserializeCollectionPsr7($request, DateTimeImmutable::class, false, 'Y-m-d');
```

There is no `deserializeValuePsr7()`. Every method here exists to convert a PSR-7 request into a
Symfony one, and `deserializeValue()` takes an already-decoded value and no request at all — call it
on `DtoDeserializer` directly.

Path parameters are read from PSR-7 request attributes (`$request->withAttribute('id', …)`), where
routers typically place them — the bridge carries them over to the Symfony request.

PSR-7 support requires the bridge in your project:

```bash
composer require symfony/psr-http-message-bridge
```

When vendoring the runtime into your project (`--dto-generator-directory`), pass `--with-psr7` to
also copy `DtoDeserializerPsr7` alongside the other runtime services.

### Laravel

`Illuminate\Http\Request` is a Symfony `Request`, so the core `DtoDeserializer` takes it directly —
body, query, headers, cookies and uploaded files all work, and `/users/{id}` route parameters are
bridged automatically. No PSR-7 conversion or extra package needed.

```php
use Illuminate\Http\Request;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;

class UserController
{
    public function store(Request $request)
    {
        // route params (/users/{id}), query, JSON body, headers, cookies and files all resolve.
        $dto = (new DtoDeserializer())->deserialize($request, UserPostRequest::class);
        // ... use $dto
    }
}
```

## Trade-offs

- The DTOs depend on this package at runtime (or on a vendored copy of the services — see
  `--dto-generator-directory`).
- Validation failures are one aggregated exception, not a `ConstraintViolationList`. A Symfony
  application that wants violations should use [Symfony mode](README.symfony.md).
- A map normalizes to `stdClass` so it always encodes as a JSON object — except a map nested inside
  a list, which stays a PHP array and therefore encodes as `[]` when empty.

The schema semantics every mode shares (list vs object, branch order in `oneOf`/`anyOf`,
`unevaluated*`, `content*`, `$defs`, extended formats) are in
[Validation Notes](README.md#validation-notes).
