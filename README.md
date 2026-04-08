# OpenAPI PHP DTO Generator

[![MIT License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml)

Generate immutable PHP DTO classes from OpenAPI YAML specs (`.yaml` / `.yml`) and validate/normalize request data with generated DTO constraints.

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.0.11
```

## Requirements

- PHP 8.4+
- Symfony 7.4 components (`console`, `http-foundation`, `mime`, `yaml`)

## Supports

**OpenAPI 3.0.x** and **OpenAPI 3.1.x**.


## Usage

### Add script in your project `composer.json`

```json
{
  "scripts": {
    "openapi:generate-dto": "php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto"
  }
}
```

### Generate DTO classes from YAML OpenAPI spec

Use one canonical command:

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test \
  --dto-generator-directory=Common \
  --dto-generator-namespace=Generated\\Common
```

Parameters:

- `--file` (`-f`) - required, path to OpenAPI YAML spec file.
- `--directory` (`-d`) - required, output directory for generated DTOs.
- `--namespace` - optional, explicit DTO namespace (if omitted, derived from `--directory`).
- `--dto-generator-directory` - optional, copy runtime services into this directory (`Common` by default when option is present without value).
- `--dto-generator-namespace` - optional, explicit namespace for copied runtime services.

### Validate and normalize generated DTOs

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use Symfony\Component\HttpFoundation\Request;
use YourApp\Generated\UserPostRequest;

$deserializer = new DtoDeserializer();
$normalizer = new DtoNormalizer();

/** @var Request $request */
$dto = $deserializer->deserialize($request, UserPostRequest::class);

// normalize only
$array = $normalizer->toArray($dto);
$json = $normalizer->toJson($dto);

// validate against OpenAPI constraints + normalize
$validatedArray = $normalizer->validateAndNormalizeToArray($dto);
$validatedJson = $normalizer->validateAndNormalizeToJson($dto);
```

## CLI commands

- `openapi:generate-dto` - generate DTO classes from OpenAPI schemas
