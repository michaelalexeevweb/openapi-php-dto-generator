# OpenAPI PHP DTO Generator

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/openapi-php-dto-generator)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/openapi-php-dto-generator/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/openapi-php-dto-generator)](https://packagist.org/packages/michaelalexeevweb/openapi-php-dto-generator)

**Generate PHP DTOs from OpenAPI and validate incoming HTTP requests against OpenAPI schema.**

Stop writing boilerplate PHP data transfer objects by hand. This library reads your OpenAPI 3.x YAML specification and automatically generates strictly-typed, immutable PHP 8.4 DTO classes. On top of that, it provides runtime services to **deserialize** Symfony `Request` objects into those DTOs, **validate HTTP requests** against the original OpenAPI schema rules (OpenAPI request validation), and **normalize** them back to arrays or JSON — all in one package.

## Features

- 🚀 **Code generation** — generate immutable PHP DTO classes directly from OpenAPI 3.0 / 3.1 YAML specs
- ✅ **OpenAPI request validation** — validate HTTP requests against OpenAPI constraints (required fields, types, enums, formats, etc.)
- 🔄 **Normalization** — convert DTOs to plain arrays or JSON, with or without validation
- 📦 **Symfony Request support** — deserialize Symfony `Request` objects directly into typed PHP DTOs
- 🔒 **Immutable by design** — all generated classes are read-only value objects
- ⚡ **Supports OpenAPI 3.0.x and 3.1.x**

## Table of Contents

- [Installation](#installation)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Generate DTOs](#generate-dto-classes-from-yaml-openapi-spec)
- [Validate & Normalize](#validate-and-normalize-generated-dtos)
- [CLI Commands](#cli-commands)

## Installation

```bash
composer require michaelalexeevweb/openapi-php-dto-generator:^2.0.23
```

## Requirements

- PHP 8.4+
- Symfony 7.4 components (`console`, `http-foundation`, `mime`, `yaml`)

## Quick Start

1. **Generate DTOs** from your OpenAPI YAML spec
2. **Deserialize** an incoming HTTP request into a generated DTO
3. **Validate** and **normalize** the DTO for further use

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use Symfony\Component\HttpFoundation\Request;
use YourApp\Generated\UserPostRequest;

$deserializer = new DtoDeserializer();
$normalizer   = new DtoNormalizer();

/** @var Request $request */
$dto = $deserializer->deserialize($request, UserPostRequest::class);

// validate against OpenAPI constraints + normalize to array
$data = $normalizer->validateAndNormalizeToArray($dto);
```

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

| Option | Alias | Required | Description |
|---|---|---|---|
| `--file` | `-f` | ✅ | Path to OpenAPI YAML spec file |
| `--directory` | `-d` | ✅ | Output directory for generated DTOs |
| `--namespace` | | | Explicit DTO namespace (derived from `--directory` if omitted) |
| `--dto-generator-directory` | | | Copy runtime services into this directory (`Common` by default) |
| `--dto-generator-namespace` | | | Explicit namespace for copied runtime services |

### Validate and normalize generated DTOs

Once DTOs are generated, use the runtime services to deserialize, validate, and normalize request data:

```php
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use Symfony\Component\HttpFoundation\Request;
use YourApp\Generated\UserPostRequest;

$deserializer = new DtoDeserializer();
$normalizer   = new DtoNormalizer();

/** @var Request $request */

// Deserialize HTTP request into a typed DTO
$dto = $deserializer->deserialize($request, UserPostRequest::class);

// Normalize only (no validation)
$array = $normalizer->toArray($dto);
$json  = $normalizer->toJson($dto);

// Validate against OpenAPI constraints, then normalize
$validatedArray = $normalizer->validateAndNormalizeToArray($dto);
$validatedJson  = $normalizer->validateAndNormalizeToJson($dto);
```

## CLI commands

- `openapi:generate-dto` — generate immutable PHP DTO classes from OpenAPI YAML schemas
