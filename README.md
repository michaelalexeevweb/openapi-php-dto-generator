# OpenAPI PHP DTO Generator

Generate immutable PHP DTO classes from OpenAPI `components.schemas`.

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^1.1.2
```

## Requirements

- PHP 8.3+

## Version

**Version 1.1.5** - Supports **OpenAPI 3.0.\*** and **OpenAPI 3.1.\***.


### OpenAPI 3.1 features supported

| Feature | Notes |
|---|---|
| `type: [string, null]` | Nullable scalar via array-type syntax |
| `type: [string, integer]` | Multi-type union → `string\|int` property |
| `exclusiveMinimum: 5` / `exclusiveMaximum: 100` | Numeric bounds (not the OAS 3.0 boolean flag) |
| `oneOf: [{$ref: …}, {type: null}]` | Nullable `$ref` via the recommended 3.1 pattern |
| `type: null` variant inside `oneOf` / `anyOf` | Treated as nullable |
| Sibling keywords alongside `$ref` | `description` placed next to `$ref` is preserved in the docblock |

The OAS 3.0 `nullable: true` shorthand is still accepted for backwards compatibility.

## Usage

### Add script in your project `composer.json`

```json
{
  "scripts": {
    "openapi:generate-dto": "php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto"
  }
}
```

### Generate DTO classes

Run command with flags:

```bash
composer openapi:generate-dto -- --file=OpenApiExamples/test.yaml --directory=generated/test
```

Provide explicit namespace (optional):

```bash
composer openapi:generate-dto -- --file=OpenApiExamples/test.yaml --directory=generated/test --namespace=Generated\\Test
```

Or with positional file argument:

```bash
composer openapi:generate-dto -- OpenApiExamples/test.yaml --directory=generated/test
```

If `--namespace` is not provided, namespace is derived from `--directory`.

### Using generated DTOs in your code

Once you've generated your DTO classes, you can use them to validate and deserialize requests:

```php
use OpenapiPhpDtoGenerator\Service\RequestValidationService;
use Symfony\Component\HttpFoundation\Request;
use YourApp\Generated\UserDto;

$validator = new RequestValidationService();

// Validate request and get result with validation info
$result = $validator->validate($request, UserDto::class);

if ($result->isValid()) {
    $dto = $result->getDto();
    echo "User ID: " . $dto->getId();
} else {
    $errors = $result->getErrors();
    echo "Validation failed: " . $result->getFirstError();
}
```

Or throw an exception on validation failure:

```php
use OpenapiPhpDtoGenerator\Service\RequestValidationService;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

$validator = new RequestValidationService();

try {
    $dto = $validator->validateOrThrow($request, UserDto::class);
    // Use $dto here
} catch (BadRequestException $e) {
    echo "Validation error: " . $e->getMessage();
}
```

You can also customize validation error texts:

```php
use OpenapiPhpDtoGenerator\Service\RequestValidationService;
use OpenapiPhpDtoGenerator\Service\ValidationMessageKey;

$validator = new RequestValidationService(messageOverrides: [
    ValidationMessageKey::PARAM_EXPECTS_TYPE->value => 'Field "{paramPath}" must be {expectedType}, got {actualType}',
]);
```

You can also register custom OpenAPI `format` handlers (validation + deserialization):

```php
use OpenapiPhpDtoGenerator\Contract\OpenApiFormatHandlerInterface;
use OpenapiPhpDtoGenerator\Service\OpenApiFormatRegistry;
use OpenapiPhpDtoGenerator\Service\RequestValidationService;

$registry = new OpenApiFormatRegistry([
    'upper-code' => new class implements OpenApiFormatHandlerInterface {
        public function validate(string $subject, mixed $value): ?string
        {
            if (!is_string($value) || preg_match('/^[A-Z0-9\-]+$/', $value) !== 1) {
                return sprintf('%s must match custom format upper-code', $subject);
            }

            return null;
        }

        public function deserialize(mixed $value, string $typeName, string $paramPath, bool $allowsNull): mixed
        {
            return is_string($value) ? strtoupper($value) : $value;
        }
    },
]);

$validator = new RequestValidationService(formatRegistry: $registry);
```

## CLI commands

- `openapi:generate-dto` - generate DTO classes from OpenAPI schemas
- `yaml:echo-properties` - print YAML as nested PHP array syntax

## Features

- Generate DTO classes from `components.schemas`
- Generate enums from schema `enum` (string and int backed)
- Support nested object schemas and nested enums
- Support `allOf` (inheritance or property merge)
- Support `oneOf` and `anyOf` (interface unions)
- Support `discriminator` with `propertyName` / `mapping`
- Generate DTOs for inline request/response schemas without a named `$ref`
- Handle path and query parameter schemas (with `isInPath` / `isInQuery` helpers)
- Preserve schema `description` as PHPDoc comments
- Preserve `default` values
- Preserve OpenAPI constraints (`minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `minLength`, `maxLength`, `pattern`, `minItems`, `maxItems`, `uniqueItems`, `format`) via `getOpenApiConstraints()`
- Validate and deserialize incoming Symfony `Request` objects against generated DTOs
- Custom validation error messages
- Custom `format` handlers (validation + deserialization)
- Support for external `$ref` across multiple YAML files
- **OpenAPI 3.1**: `type: [string, null]`, multi-type unions, numeric `exclusiveMinimum` / `exclusiveMaximum`, `oneOf` + `{type: null}` nullable references, sibling keywords alongside `$ref`
