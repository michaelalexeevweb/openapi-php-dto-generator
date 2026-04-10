<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\TestCase;

enum TestStringBackedEnum: string
{
    case INTEGER = 'integer';
}

enum TestIntBackedEnum: int
{
    case ONE = 1;
}

/**
 * Unit tests for DtoValidator.
 *
 * Covers every constraint category directly, without going through the deserializer:
 *   - Null / empty shortcut
 *   - Numeric: minimum/maximum (inclusive + exclusive in both OpenAPI 3.0 and 3.1 styles), multipleOf
 *   - String: minLength, maxLength, pattern, formats (email, uuid, date, date-time, uri, ipv4, ipv6,
 *             byte, binary, hostname, password)
 *   - Array: minItems, maxItems, uniqueItems, items (recursive)
 *   - anyOf / oneOf union branches
 *   - DateTimeInterface normalization before validation
 */
final class DtoValidatorTest extends TestCase
{
    private DtoValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DtoValidator();
    }

    // =========================================================================
    // Null / empty shortcut
    // =========================================================================

    public function testNullValueSkipsAllValidation(): void
    {
        $errors = $this->validator->validate(
            subject: 'f',
            value: null,
            constraints: ['minimum' => 10, 'format' => 'email', 'minLength' => 5],
        );
        $this->assertSame([], $errors);
    }

    public function testTypeString_acceptsStringBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['type' => 'string'],
        );

        $this->assertSame([], $errors);
    }

    public function testTypeString_rejectsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'string'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type must be of type string', $errors[0]);
    }

    public function testTypeInteger_acceptsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'integer'],
        );

        $this->assertSame([], $errors);
    }

    public function testTypeInteger_rejectsStringBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['type' => 'integer'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type must be of type integer', $errors[0]);
    }

    public function testTypeNumber_acceptsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'number'],
        );

        $this->assertSame([], $errors);
    }

    public function testEmptyConstraintsReturnNoErrors(): void
    {
        $errors = $this->validator->validate(subject: 'f', value: 'anything', constraints: []);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // Numeric — exclusiveMaximum
    // =========================================================================

    public function testExclusiveMaximumNumeric_rejectsEqualValue(): void
    {
        // OpenAPI 3.1: exclusiveMaximum IS the exclusive upper boundary
        $errors = $this->validator->validate(subject: 'price', value: 50.0, constraints: ['exclusiveMaximum' => 50]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than 50', $errors[0]);
    }

    public function testExclusiveMaximumNumeric_acceptsBelowBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'price', value: 49.0, constraints: ['exclusiveMaximum' => 50]);
        $this->assertSame([], $errors);
    }

    public function testExclusiveMaximumBoolean_rejectsEqualToMaximum(): void
    {
        // OpenAPI 3.0: maximum + exclusiveMaximum: true
        $errors = $this->validator->validate(
            subject: 'score',
            value: 100.0,
            constraints: ['maximum' => 100, 'exclusiveMaximum' => true],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than 100', $errors[0]);
    }

    public function testExclusiveMaximumBoolean_acceptsBelowMaximum(): void
    {
        $errors = $this->validator->validate(
            subject: 'score',
            value: 99.0,
            constraints: ['maximum' => 100, 'exclusiveMaximum' => true],
        );
        $this->assertSame([], $errors);
    }

    public function testMaximumInclusive_acceptsExactBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 100.0, constraints: ['maximum' => 100]);
        $this->assertSame([], $errors);
    }

    public function testMaximumInclusive_rejectsAboveBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 101.0, constraints: ['maximum' => 100]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than or equal to 100', $errors[0]);
    }

    // =========================================================================
    // Numeric — multipleOf with float divisor
    // =========================================================================

    public function testMultipleOfFloat_rejectsNonMultiple(): void
    {
        $errors = $this->validator->validate(subject: 'amount', value: 10.1, constraints: ['multipleOf' => 0.5]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be a multiple of 0.5', $errors[0]);
    }

    public function testMultipleOfFloat_acceptsExactMultiple(): void
    {
        $errors = $this->validator->validate(subject: 'amount', value: 10.5, constraints: ['multipleOf' => 0.5]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // String formats
    // =========================================================================

    public function testFormatUri_rejectsPlainText(): void
    {
        $errors = $this->validator->validate(subject: 'url', value: 'not a url', constraints: ['format' => 'uri']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format uri', $errors[0]);
    }

    public function testFormatUri_acceptsHttpsUrl(): void
    {
        $errors = $this->validator->validate(
            subject: 'url',
            value: 'https://example.com/path?q=1',
            constraints: ['format' => 'uri'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv4_rejectsOutOfRangeOctets(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '999.999.999.999',
            constraints: ['format' => 'ipv4'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatIpv4_acceptsValidAddress(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '192.168.1.1',
            constraints: ['format' => 'ipv4'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv6_acceptsValidAddress(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '2001:db8::1',
            constraints: ['format' => 'ipv6'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv6_rejectsIpv4Address(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '192.168.1.1',
            constraints: ['format' => 'ipv6'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatByte_acceptsValidBase64(): void
    {
        $errors = $this->validator->validate(
            subject: 'data',
            value: base64_encode('hello world'),
            constraints: ['format' => 'byte'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatByte_rejectsStringWithIllegalChars(): void
    {
        $errors = $this->validator->validate(
            subject: 'data',
            value: '!!!invalid!!!',
            constraints: ['format' => 'byte'],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format byte', $errors[0]);
    }

    public function testFormatBinary_acceptsStringValue(): void
    {
        $errors = $this->validator->validate(
            subject: 'file',
            value: 'raw-bytes',
            constraints: ['format' => 'binary'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatBinary_rejectsIntegerValue(): void
    {
        $errors = $this->validator->validate(
            subject: 'file',
            value: 12345,
            constraints: ['format' => 'binary'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('expects binary data', $errors[0]);
    }

    public function testFormatHostname_rejectsLeadingHyphen(): void
    {
        $errors = $this->validator->validate(
            subject: 'host',
            value: '-invalid-',
            constraints: ['format' => 'hostname'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatHostname_acceptsValidDomain(): void
    {
        $errors = $this->validator->validate(
            subject: 'host',
            value: 'example.com',
            constraints: ['format' => 'hostname'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatPassword_alwaysValid(): void
    {
        $errors = $this->validator->validate(
            subject: 'pw',
            value: 'any-value-is-ok',
            constraints: ['format' => 'password'],
        );
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // anyOf
    // =========================================================================

    public function testAnyOf_acceptsWhenOneBranchMatches(): void
    {
        $errors = $this->validator->validate('v', 'hello', [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string', 'minLength' => 3],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAnyOf_acceptsWhenMultipleBranchesMatch(): void
    {
        // anyOf succeeds as long as at least one branch is satisfied
        $errors = $this->validator->validate('v', 42, [
            'anyOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'integer', 'maximum' => 100],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAnyOf_rejectsWhenValueTypeMatchesNoBranch(): void
    {
        // Boolean doesn't match 'integer' or 'string' type → no branch matches at all
        $errors = $this->validator->validate('v', true, [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not match any anyOf branch', $errors[0]);
    }

    public function testAnyOf_returnsConstraintErrorsWhenTypeMatchesButConstraintFails(): void
    {
        // The string type branch matches, but minLength constraint fails → branch errors returned
        $errors = $this->validator->validate('v', 'hi', [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string', 'minLength' => 5],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('length must be at least 5', $errors[0]);
    }

    // =========================================================================
    // oneOf
    // =========================================================================

    public function testOneOf_acceptsExactlyOneMatch(): void
    {
        $errors = $this->validator->validate('id', 42, [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testOneOf_rejectsWhenMultipleBranchesMatch(): void
    {
        // Both integer branches match value 10 → oneOf violation
        $errors = $this->validator->validate('num', 10, [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'integer', 'maximum' => 100],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('matches more than one allowed oneOf branch', $errors[0]);
    }

    public function testOneOf_rejectsWhenNoBranchMatches(): void
    {
        $errors = $this->validator->validate('v', true, [
            'oneOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not match any oneOf branch', $errors[0]);
    }

    // =========================================================================
    // items (recursive)
    // =========================================================================

    public function testItems_rejectsInvalidItemsWithCorrectPath(): void
    {
        $errors = $this->validator->validate('emails', ['a@b.com', 'bad-email'], [
            'items' => ['type' => 'string', 'format' => 'email'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('emails.1', $errors[0]);
    }

    public function testItems_acceptsAllValidItems(): void
    {
        $errors = $this->validator->validate('emails', ['a@b.com', 'c@d.com'], [
            'items' => ['type' => 'string', 'format' => 'email'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testItems_collectsErrorsForMultipleInvalidItems(): void
    {
        $errors = $this->validator->validate('nums', [1, 200, 300], [
            'items' => ['type' => 'integer', 'maximum' => 100],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('nums.1', $errors[0]);
        $this->assertStringContainsString('nums.2', $errors[1]);
    }

    // =========================================================================
    // DateTimeInterface normalization before validation
    // =========================================================================

    public function testDateTimeInterface_isNormalizedToDateStringForDateFormat(): void
    {
        $dt = new DateTimeImmutable('2024-06-15');
        // Normalized to '2024-06-15', which passes minLength: 10
        $errors = $this->validator->validate(
            subject: 'since',
            value: $dt,
            constraints: ['format' => 'date', 'minLength' => 10],
        );
        $this->assertSame([], $errors);
    }

    public function testDateTimeInterface_isNormalizedToAtomStringForDateTimeFormat(): void
    {
        $dt = new DateTimeImmutable('2024-06-15T12:00:00+00:00');
        $errors = $this->validator->validate(
            subject: 'ts',
            value: $dt,
            constraints: ['format' => 'date-time'],
        );
        $this->assertSame([], $errors);
    }

    public function testDateTimeInterface_isNormalizedToAtomStringWhenNoFormatGiven(): void
    {
        $dt = new DateTimeImmutable('2024-06-15');
        // No format → normalized to ATOM string → minLength check applies to the full ISO string
        $isoLength = strlen($dt->format(DateTimeInterface::ATOM));
        $errors = $this->validator->validate(
            subject: 'ts',
            value: $dt,
            constraints: ['minLength' => $isoLength],
        );
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // Numeric — minimum / exclusiveMinimum
    // =========================================================================

    public function testMinimumInclusive_acceptsExactBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 5.0, constraints: ['minimum' => 5]);
        $this->assertSame([], $errors);
    }

    public function testMinimumInclusive_rejectsBelowBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 4.9, constraints: ['minimum' => 5]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than or equal to 5', $errors[0]);
    }

    public function testExclusiveMinimumNumeric_rejectsEqualValue(): void
    {
        // OpenAPI 3.1: exclusiveMinimum IS the exclusive lower boundary
        $errors = $this->validator->validate(subject: 'n', value: 5.0, constraints: ['exclusiveMinimum' => 5]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than 5', $errors[0]);
    }

    public function testExclusiveMinimumNumeric_acceptsAboveBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 5.1, constraints: ['exclusiveMinimum' => 5]);
        $this->assertSame([], $errors);
    }

    public function testExclusiveMinimumBoolean_rejectsEqualToMinimum(): void
    {
        // OpenAPI 3.0: minimum + exclusiveMinimum: true
        $errors = $this->validator->validate(
            subject: 'n',
            value: 1.0,
            constraints: ['minimum' => 1, 'exclusiveMinimum' => true],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than 1', $errors[0]);
    }

    public function testExclusiveMinimumBoolean_acceptsAboveMinimum(): void
    {
        $errors = $this->validator->validate(
            subject: 'n',
            value: 2.0,
            constraints: ['minimum' => 1, 'exclusiveMinimum' => true],
        );
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // String — minLength / maxLength / pattern / format (email, uuid, date, datetime)
    // =========================================================================

    public function testMinLength_acceptsExactLength(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc', constraints: ['minLength' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMinLength_rejectsTooShort(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'ab', constraints: ['minLength' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('length must be at least 3', $errors[0]);
    }

    public function testMaxLength_acceptsExactLength(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc', constraints: ['maxLength' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxLength_rejectsTooLong(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abcd', constraints: ['maxLength' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('length must be at most 3', $errors[0]);
    }

    public function testPattern_acceptsMatchingString(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc123', constraints: ['pattern' => '^[a-z0-9]+$']);
        $this->assertSame([], $errors);
    }

    public function testPattern_rejectsNonMatchingString(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'ABC!', constraints: ['pattern' => '^[a-z0-9]+$']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match pattern', $errors[0]);
    }

    public function testFormatEmail_acceptsValidAddress(): void
    {
        $errors = $this->validator->validate(subject: 'e', value: 'user@example.com', constraints: ['format' => 'email']);
        $this->assertSame([], $errors);
    }

    public function testFormatEmail_rejectsPlainText(): void
    {
        $errors = $this->validator->validate(subject: 'e', value: 'not-an-email', constraints: ['format' => 'email']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format email', $errors[0]);
    }

    public function testFormatUuid_acceptsValidUuid(): void
    {
        $errors = $this->validator->validate(
            subject: 'id',
            value: '550e8400-e29b-41d4-a716-446655440000',
            constraints: ['format' => 'uuid'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatUuid_rejectsNonUuidString(): void
    {
        $errors = $this->validator->validate(subject: 'id', value: 'not-a-uuid', constraints: ['format' => 'uuid']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format uuid', $errors[0]);
    }

    public function testFormatDate_acceptsValidDate(): void
    {
        $errors = $this->validator->validate(subject: 'd', value: '2024-06-15', constraints: ['format' => 'date']);
        $this->assertSame([], $errors);
    }

    public function testFormatDate_rejectsInvalidDate(): void
    {
        $errors = $this->validator->validate(subject: 'd', value: '15-06-2024', constraints: ['format' => 'date']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format date', $errors[0]);
    }

    public function testFormatDateTime_acceptsAtomString(): void
    {
        $errors = $this->validator->validate(
            subject: 'ts',
            value: '2024-06-15T12:00:00+00:00',
            constraints: ['format' => 'date-time'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatDateTime_rejectsDateOnlyString(): void
    {
        $errors = $this->validator->validate(subject: 'ts', value: '2024-06-15', constraints: ['format' => 'date-time']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format date-time', $errors[0]);
    }

    // =========================================================================
    // Array — minItems / maxItems / uniqueItems
    // =========================================================================

    public function testMinItems_acceptsEnoughItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2, 3], constraints: ['minItems' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMinItems_rejectsTooFewItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2], constraints: ['minItems' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must contain at least 3 items', $errors[0]);
    }

    public function testMaxItems_acceptsFewEnoughItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2], constraints: ['maxItems' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxItems_rejectsTooManyItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2, 3, 4], constraints: ['maxItems' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must contain at most 3 items', $errors[0]);
    }

    public function testUniqueItems_acceptsDistinctItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: ['a', 'b', 'c'], constraints: ['uniqueItems' => true]);
        $this->assertSame([], $errors);
    }

    public function testUniqueItems_rejectsDuplicateScalar(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: ['x', 'x', 'y'], constraints: ['uniqueItems' => true]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique items', $errors[0]);
    }

    public function testUniqueItems_falseAllowsDuplicates(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 1, 1], constraints: ['uniqueItems' => false]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // enum
    // =========================================================================

    public function testEnum_acceptsValueInList(): void
    {
        $errors = $this->validator->validate(
            subject: 'status',
            value: 'active',
            constraints: ['enum' => ['active', 'inactive', 'pending']],
        );
        $this->assertSame([], $errors);
    }

    public function testEnum_rejectsValueNotInList(): void
    {
        $errors = $this->validator->validate(
            subject: 'status',
            value: 'deleted',
            constraints: ['enum' => ['active', 'inactive', 'pending']],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be one of', $errors[0]);
        $this->assertStringContainsString('"active"', $errors[0]);
    }

    public function testEnum_usesStrictComparison(): void
    {
        // "1" (string) must not match 1 (int)
        $errors = $this->validator->validate(
            subject: 'n',
            value: '1',
            constraints: ['enum' => [1, 2, 3]],
        );
        $this->assertNotEmpty($errors);
    }

    public function testEnum_acceptsIntegerValue(): void
    {
        $errors = $this->validator->validate(
            subject: 'priority',
            value: 2,
            constraints: ['enum' => [1, 2, 3]],
        );
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // const
    // =========================================================================

    public function testConst_acceptsMatchingValue(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 'fixed', constraints: ['const' => 'fixed']);
        $this->assertSame([], $errors);
    }

    public function testConst_rejectsNonMatchingValue(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 'other', constraints: ['const' => 'fixed']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must equal', $errors[0]);
        $this->assertStringContainsString('"fixed"', $errors[0]);
    }

    public function testConst_usesStrictComparison(): void
    {
        // 0 (int) must not match false (bool)
        $errors = $this->validator->validate(subject: 'v', value: 0, constraints: ['const' => false]);
        $this->assertNotEmpty($errors);
    }

    public function testConst_acceptsMatchingInteger(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 42, constraints: ['const' => 42]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // allOf
    // =========================================================================

    public function testAllOf_acceptsWhenAllBranchesPass(): void
    {
        $errors = $this->validator->validate('n', 7, [
            'allOf' => [
                ['minimum' => 1],
                ['maximum' => 10],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAllOf_rejectsWhenOneBranchFails(): void
    {
        $errors = $this->validator->validate('n', 15, [
            'allOf' => [
                ['minimum' => 1],
                ['maximum' => 10],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than or equal to 10', $errors[0]);
    }

    public function testAllOf_collectsErrorsFromAllFailingBranches(): void
    {
        // Both branches fail: value 0 is below minimum 1 and below minimum 5
        $errors = $this->validator->validate('n', 0, [
            'allOf' => [
                ['minimum' => 1],
                ['minimum' => 5],
            ],
        ]);
        $this->assertCount(2, $errors);
    }

    public function testAllOf_canCombineStringConstraints(): void
    {
        $errors = $this->validator->validate('s', 'hi', [
            'allOf' => [
                ['minLength' => 2],
                ['maxLength' => 5],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // not
    // =========================================================================

    public function testNot_acceptsWhenSchemaDoesNotMatch(): void
    {
        // Value is not a multiple of 3 → passes 'not' constraint
        $errors = $this->validator->validate('n', 7, [
            'not' => ['multipleOf' => 3],
        ]);
        $this->assertSame([], $errors);
    }

    public function testNot_rejectsWhenSchemaMatches(): void
    {
        // Value IS a multiple of 3 → violates 'not' constraint
        $errors = $this->validator->validate('n', 9, [
            'not' => ['multipleOf' => 3],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("must not match the 'not' schema", $errors[0]);
    }

    public function testNot_withTypeConstraint_rejectsMatchingType(): void
    {
        // 'not integer' → string passes, integer fails
        $errors = $this->validator->validate('v', 42, [
            'not' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testNot_withTypeConstraint_acceptsNonMatchingType(): void
    {
        $errors = $this->validator->validate('v', 'hello', [
            'not' => ['type' => 'integer'],
        ]);
        $this->assertSame([], $errors);
    }
}
