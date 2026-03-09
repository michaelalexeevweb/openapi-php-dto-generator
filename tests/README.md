# Tests

This directory contains PHPUnit tests for the OpenAPI DTO Generator.

## Running Tests

### Local (macOS/Linux)
```bash
php vendor/bin/phpunit
```

### Docker
```bash
make test
```

### Run specific test
```bash
php vendor/bin/phpunit --filter testPathAndQueryParameters
```

## Test Coverage

The test suite covers all major features:

### ✅ Path and Query Parameters
- **testPathAndQueryParameters** - Verifies path and query params DTO generation
- **testPathParametersAreAlwaysRequiredAndQueryRequiredSupportsStringFlags** - Regression test ensuring path params are always required (non-nullable)

### ✅ Nullable allOf Support
- **testNullableAllOfWithSingleRef** - Single $ref in nullable allOf produces `?TypeName`
- **testNullableAllOfWithMultipleRefs** - Multiple $refs in allOf create merged DTO with all properties (last definition wins)

### ✅ Request Body Generation
- **testRequestBodyPostGeneration** - POST request body DTOs
- **testRequestBodyPatchGeneration** - PATCH request body DTOs

### ✅ Response Schema Generation
- **testInlineResponseSchemaGeneration** - Inline response schemas without $ref

### ✅ Description Support
- **testDescriptionSupport** - Property descriptions in PHPDoc comments

### ✅ Default Values
- **testDefaultValuesSupport** - Default values for properties and parameters
- **testQueryParametersWithDefaults** - Default values in query parameters

### ✅ Enum Generation
- **testEnumGeneration** - String and integer enum generation
- **testNestedEnumGeneration** - Enums nested in properties and array items

### ✅ Nested Schemas
- **testNestedSchemaGeneration** - Nested object schemas without their own definitions

### ✅ Inheritance (allOf)
- **testAllOfWithInheritance** - Parent/child class relationships with property override detection

### ✅ Union Types
- **testOneOfGeneration** - oneOf unions as interfaces
- **testAnyOfGeneration** - anyOf unions as interfaces

### ✅ Discriminator
- **testDiscriminatorSupport** - Polymorphic schemas with discriminator

### ✅ Utility
- **testGeneratedFilesCount** - Ensures correct number of files generated
- **testNamespaceIsCorrect** - Custom namespace handling
- **testOutputDirectoryIsCleanedBeforeGeneration** - Directory cleanup

## Test Fixtures

Test YAML files are located in `tests/fixtures/`:

- **test-all-features.yaml** - Comprehensive test covering all features
- **path-query-required-coercion.yaml** - Edge cases for parameter requirements

## Requirements

- PHP 8.3+
- PHPUnit 10.5+
- Symfony Console 7.x
- Symfony YAML 7.x

## Notes

- Path parameters are **always required** in generated DTOs, regardless of `required` flag in spec (OpenAPI standard)
- Query parameters respect `required` flag and support tolerant parsing (`'true'`, `'1'`, `'yes'`, `'on'`)
- Generated DTOs use `final readonly` classes for immutability
- Parent classes in inheritance chains don't have `final` modifier

