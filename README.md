# OpenAPI PHP DTO Generator

Generate immutable PHP DTO classes from OpenAPI `components.schemas`.

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator
```

## Requirements

- PHP 8.3+

## Version

**Version 1.0** - Supports OpenAPI 3.0.* only.

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

Or with positional file argument:

```bash
composer openapi:generate-dto -- OpenApiExamples/test.yaml --directory=generated/test
```

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
use OpenapiPhpDtoGenerator\Exception\RequestValidationException;

$validator = new RequestValidationService();

try {
    $dto = $validator->validateOrThrow($request, UserDto::class);
    // Use $dto here
} catch (RequestValidationException $e) {
    echo "Validation error: " . $e->getMessage();
}
```

## CLI commands

- `openapi:generate-dto` - generate DTO classes from OpenAPI schemas
- `yaml:echo-properties` - print YAML as nested PHP array syntax

## Features

- Generate readonly DTO classes from `components.schemas`
- Generate enums from schema `enum`
- Support nested object schemas and nested enums
- Support `allOf` inheritance
- Support `oneOf` and `anyOf` unions
- Generate request/response inline schemas
- Handle path and query parameter schemas
- Preserve schema descriptions and default values

## Testing

```bash
php vendor/bin/phpunit
```

## License

MIT - see `LICENSE`.
