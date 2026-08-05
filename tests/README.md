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

## Golden Corpus Snapshot

`tests/Golden/GoldenCorpusTest.php` generates `OpenApiExamples/test.yaml` in **all three** modes and
compares the whole output, byte for byte, against `tests/Golden/snapshots/<mode>.snapshot.txt`
(one text file per mode: a header with the file count, the total line count and the file inventory,
then every generated file). A second test parses every generated file with `php -l`, so a snapshot
can never pin unparsable PHP.

Every emitter change shows up here as a reviewable diff — including things a fragment assertion
cannot see, such as a file or a constant that stopped being emitted.

To accept a deliberate change, regenerate the snapshots and read the diff before committing:

```bash
UPDATE_GOLDEN_CORPUS=1 php vendor/bin/phpunit --filter GoldenCorpus   # or: make golden
git diff tests/Golden/snapshots
```

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
- Runtime-mode DTOs use `final readonly` classes for immutability; Symfony-mode DTOs keep `readonly` on required (constructor) properties and expose setters for optional ones
- Parent classes in inheritance chains don't have `final` modifier

