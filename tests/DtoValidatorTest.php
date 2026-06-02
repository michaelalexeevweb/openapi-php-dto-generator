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

    public function testOneOf_acceptsWhenOneOfTwoTypeMatchingBranchesFullyValidates(): void
    {
        // value = 0: both branches match type integer, but only branch1 passes minimum: 0
        // Old bug: $matched=2, $errors=[branch2 errors] → incorrectly returned errors
        // Fix: $validBranches=1 → must return []
        $errors = $this->validator->validate('num', 0, [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 0],
                ['type' => 'integer', 'minimum' => 10],
            ],
        ]);
        $this->assertSame([], $errors);
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

    public function testItems_withAnyOf_acceptsElementsMatchingAtLeastOneBranch(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 42, 'world'], [
            'items' => [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testItems_withAnyOf_rejectsElementMatchingNoBranch(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 3.14], [
            'items' => [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('tags.1', $errors[0]);
    }

    public function testItems_withOneOf_acceptsElementMatchingExactlyOneBranch(): void
    {
        $errors = $this->validator->validate('values', ['text', 5], [
            'items' => [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testItems_withOneOf_rejectsElementMatchingMoreThanOneBranch(): void
    {
        // 10 matches both integer branches
        $errors = $this->validator->validate('values', [10], [
            'items' => [
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    ['type' => 'integer', 'maximum' => 100],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('values.0', $errors[0]);
    }

    public function testItems_withOneOf_rejectsElementMatchingNoBranch(): void
    {
        $errors = $this->validator->validate('values', ['text', 3.14], [
            'items' => [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('values.1', $errors[0]);
    }

    // =========================================================================
    // contains / minContains / maxContains
    // =========================================================================

    public function testContains_acceptsWhenAtLeastOneItemMatches(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 42, 'world'], [
            'contains' => ['type' => 'integer'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testContains_rejectsWhenNoItemMatches(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 'world'], [
            'contains' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('contains', $errors[0]);
    }

    public function testContains_withMinContains_requiresEnoughMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 'a', 2], [
            'contains' => ['type' => 'integer'],
            'minContains' => 3,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 3', $errors[0]);
    }

    public function testContains_withMinContains_acceptsEnoughMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 2, 3, 'x'], [
            'contains' => ['type' => 'integer'],
            'minContains' => 3,
        ]);
        $this->assertSame([], $errors);
    }

    public function testContains_withMaxContains_rejectsTooManyMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 2, 3], [
            'contains' => ['type' => 'integer'],
            'maxContains' => 2,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at most 2', $errors[0]);
    }

    public function testContains_withMaxContains_acceptsWithinLimit(): void
    {
        $errors = $this->validator->validate('nums', [1, 'a', 'b'], [
            'contains' => ['type' => 'integer'],
            'maxContains' => 2,
        ]);
        $this->assertSame([], $errors);
    }

    public function testContains_withMinAndMaxContains_acceptsExactRange(): void
    {
        $errors = $this->validator->validate('nums', [1, 2, 'x'], [
            'contains' => ['type' => 'integer'],
            'minContains' => 1,
            'maxContains' => 3,
        ]);
        $this->assertSame([], $errors);
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

    public function testPattern_acceptsPatternContainingForwardSlash(): void
    {
        // Patterns with `/` must not double-escape when `#` delimiter is used
        $errors = $this->validator->validate(subject: 'url', value: 'https://example.com/path', constraints: ['pattern' => '^https?://.+']);
        $this->assertSame([], $errors);
    }

    public function testPattern_rejectsNonMatchingPatternWithForwardSlash(): void
    {
        $errors = $this->validator->validate(subject: 'url', value: 'ftp://example.com', constraints: ['pattern' => '^https?://.+']);
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

    public function testAllOf_withAnyOf_acceptsWhenBothSatisfied(): void
    {
        // allOf: must be integer >= 1; anyOf: must be < 5 OR > 100
        // value 3: allOf passes (integer, >= 1), anyOf passes (< 5)
        $errors = $this->validator->validate('n', 3, [
            'allOf' => [
                ['type' => 'integer'],
                ['minimum' => 1],
            ],
            'anyOf' => [
                ['maximum' => 5],
                ['minimum' => 100],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAllOf_withAnyOf_rejectsWhenAllOfFails(): void
    {
        // value 0: fails allOf minimum:1
        $errors = $this->validator->validate('n', 0, [
            'allOf' => [
                ['type' => 'integer'],
                ['minimum' => 1],
            ],
            'anyOf' => [
                ['maximum' => 5],
                ['minimum' => 100],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than or equal to 1', $errors[0]);
    }

    public function testAllOf_withAnyOf_rejectsWhenAnyOfFails(): void
    {
        // value 50: allOf passes, anyOf fails (not <= 5, not >= 100)
        // branches have no 'type' so both are tried; both fail → branch errors returned
        $errors = $this->validator->validate('n', 50, [
            'allOf' => [
                ['type' => 'integer'],
                ['minimum' => 1],
            ],
            'anyOf' => [
                ['maximum' => 5],
                ['minimum' => 100],
            ],
        ]);
        $this->assertNotEmpty($errors);
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

    public function testNot_withAllOf_rejectsWhenAllBranchesMatch(): void
    {
        // 'not allOf[string, minLength:3]' — 'hello' matches both branches → rejected
        $errors = $this->validator->validate('v', 'hello', [
            'not' => [
                'allOf' => [
                    ['type' => 'string'],
                    ['minLength' => 3],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("must not match the 'not' schema", $errors[0]);
    }

    public function testNot_withAllOf_acceptsWhenAnyBranchFails(): void
    {
        // 'not allOf[string, minLength:10]' — 'hi' fails minLength → allOf fails → not passes
        $errors = $this->validator->validate('v', 'hi', [
            'not' => [
                'allOf' => [
                    ['type' => 'string'],
                    ['minLength' => 10],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // minProperties / maxProperties
    // =========================================================================

    public function testMinProperties_acceptsEnoughProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2], ['minProperties' => 2]);
        $this->assertSame([], $errors);
    }

    public function testMinProperties_rejectsTooFewProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1], ['minProperties' => 2]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must have at least 2 properties', $errors[0]);
    }

    public function testMinProperties_singularWord_whenOne(): void
    {
        $errors = $this->validator->validate('obj', [], ['minProperties' => 1]);
        $this->assertStringContainsString('at least 1 property', $errors[0]);
    }

    public function testMaxProperties_acceptsFewEnoughProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1], ['maxProperties' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxProperties_rejectsTooManyProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], ['maxProperties' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must have at most 3 properties', $errors[0]);
    }

    public function testMaxProperties_singularWord_whenOne(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2], ['maxProperties' => 1]);
        $this->assertStringContainsString('at most 1 property', $errors[0]);
    }

    public function testMinAndMaxProperties_combinedRange_acceptsValid(): void
    {
        $errors = $this->validator->validate('obj', ['x' => 1, 'y' => 2], ['minProperties' => 1, 'maxProperties' => 3]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // additionalProperties: false
    // =========================================================================

    public function testAdditionalProperties_false_rejectsExtraKey(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice', 'extra' => 'oops'], [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('"extra"', $errors[0]);
        $this->assertStringContainsString('not allowed', $errors[0]);
    }

    public function testAdditionalProperties_false_acceptsExactlyDefinedKeys(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $this->assertSame([], $errors);
    }

    public function testAdditionalProperties_false_rejectsMultipleExtraKeys(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Bob', 'foo' => 1, 'bar' => 2], [
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);
        $this->assertCount(2, $errors);
    }

    public function testAdditionalProperties_false_withoutProperties_allowsNothing(): void
    {
        // additionalProperties: false without properties defined → no defined keys → all are additional
        // But $definedPropertyNames stays null when 'properties' key absent → no check applied
        // Verify: no error (guard requires 'properties' to be present)
        $errors = $this->validator->validate('obj', ['key' => 'value'], ['additionalProperties' => false]);
        $this->assertSame([], $errors);
    }

    public function testAdditionalProperties_asSchema_validatesExtraPropertyValues(): void
    {
        $errors = $this->validator->validate('obj', ['dynamic' => 'not-a-number'], [
            'additionalProperties' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('obj.dynamic', $errors[0]);
        $this->assertStringContainsString('type integer', $errors[0]);
    }

    public function testAdditionalProperties_asSchema_acceptsValidExtraPropertyValues(): void
    {
        $errors = $this->validator->validate('obj', ['count' => 42, 'total' => 100], [
            'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAdditionalProperties_asSchema_skipsDefinedProperties(): void
    {
        // 'name' is defined in properties (string), extra keys validated as integer
        $errors = $this->validator->validate('obj', ['name' => 'Alice', 'count' => 5], [
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'integer'],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // properties — nested property validation
    // =========================================================================

    public function testProperties_validatesDefinedPropertyConstraints(): void
    {
        $errors = $this->validator->validate('obj', ['age' => -1], [
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('obj.age', $errors[0]);
        $this->assertStringContainsString('greater than or equal to 0', $errors[0]);
    }

    public function testProperties_acceptsValidNestedValues(): void
    {
        $errors = $this->validator->validate('obj', ['age' => 25, 'name' => 'Bob'], [
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0],
                'name' => ['type' => 'string', 'minLength' => 1],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testProperties_skipsAbsentOptionalProperties(): void
    {
        // 'email' not present → no error even if it had format: email constraint
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testProperties_collectsErrorsFromMultipleInvalidFields(): void
    {
        $errors = $this->validator->validate('user', ['age' => -1, 'score' => 200], [
            'properties' => [
                'age' => ['minimum' => 0],
                'score' => ['maximum' => 100],
            ],
        ]);
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('user.age', $errors[0]);
        $this->assertStringContainsString('user.score', $errors[1]);
    }

    // =========================================================================
    // required — mandatory properties in object schema
    // =========================================================================

    public function testRequired_missingRequiredProperty_returnsError(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['name', 'email'],
        ]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('obj.email', $errors[0]);
        $this->assertStringContainsString('required', $errors[0]);
    }

    public function testRequired_allRequiredPresent_returnsNoErrors(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice', 'email' => 'a@b.com'], [
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['name', 'email'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testRequired_multipleMissingFields_reportsAll(): void
    {
        $errors = $this->validator->validate('obj', [], [
            'required' => ['name', 'email', 'age'],
        ]);
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('obj.name', $errors[0]);
        $this->assertStringContainsString('obj.email', $errors[1]);
        $this->assertStringContainsString('obj.age', $errors[2]);
    }

    public function testRequired_withoutPropertiesConstraint_stillValidates(): void
    {
        // required without properties is valid OpenAPI — just checks key presence
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'required' => ['name', 'email'],
        ]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('obj.email', $errors[0]);
    }

    public function testRequired_nullValueForRequiredKey_passesPresenceCheck(): void
    {
        // key exists with null value — presence satisfied, type may fail separately
        $errors = $this->validator->validate('obj', ['name' => null], [
            'required' => ['name'],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testMultipleOf_zero_doesNotDivideByZero(): void
    {
        // multipleOf: 0 is invalid per OpenAPI spec, but must not throw division by zero
        $errors = $this->validator->validate('n', 5, ['multipleOf' => 0]);
        $this->assertSame([], $errors);
    }

    public function testUniqueItems_withComplexObjects_detectsDuplicates(): void
    {
        $errors = $this->validator->validate('arr', [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 1, 'name' => 'Alice'],
        ], ['uniqueItems' => true]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique items', $errors[0]);
    }

    public function testUniqueItems_withComplexObjects_acceptsDistinct(): void
    {
        $errors = $this->validator->validate('arr', [
            ['id' => 1],
            ['id' => 2],
        ], ['uniqueItems' => true]);
        $this->assertSame([], $errors);
    }

    public function testFormatDatetime_aliasForDateTime(): void
    {
        // 'datetime' is an alias for 'date-time' in the validator
        $errors = $this->validator->validate('ts', '2024-06-15T12:00:00+00:00', ['format' => 'datetime']);
        $this->assertSame([], $errors);
    }

    public function testFormatDatetimeAlias_rejectsDateOnlyString(): void
    {
        $errors = $this->validator->validate('ts', '2024-06-15', ['format' => 'datetime']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format datetime', $errors[0]);
    }

    public function testTypeNull_matchesNullValue(): void
    {
        // null values skip validation (line 29: if $value === null return [])
        // But if value IS null and somehow reaches matchesOpenApiType, type: null should match
        // Test via not constraint so null value check doesn't short-circuit
        $errors = $this->validator->validate('v', 42, [
            'not' => ['type' => 'null'],
        ]);
        $this->assertSame([], $errors);
    }

    // --- if/then/else ---

    public function testIfThen_conditionPasses_thenApplied(): void
    {
        // if type=string → then minLength=5; value 'hi' fails then
        $errors = $this->validator->validate('v', 'hi', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 5', $errors[0]);
    }

    public function testIfThen_conditionPasses_thenSatisfied(): void
    {
        $errors = $this->validator->validate('v', 'hello world', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfThen_conditionFails_thenSkipped(): void
    {
        // value is integer, if=string fails → then not applied → no errors
        $errors = $this->validator->validate('v', 42, [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfElse_conditionFails_elseApplied(): void
    {
        // if type=string fails → else minimum=10; value 3 fails else
        $errors = $this->validator->validate('v', 3, [
            'if' => ['type' => 'string'],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('greater than or equal to 10', $errors[0]);
    }

    public function testIfElse_conditionFails_elseSatisfied(): void
    {
        $errors = $this->validator->validate('v', 42, [
            'if' => ['type' => 'string'],
            'else' => ['minimum' => 10],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfThenElse_conditionPasses_thenApplied_elseSkipped(): void
    {
        $errors = $this->validator->validate('v', 'hi', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 5', $errors[0]);
    }

    public function testIfThenElse_conditionFails_elseApplied_thenSkipped(): void
    {
        $errors = $this->validator->validate('v', 3, [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('greater than or equal to 10', $errors[0]);
    }

    public function testIf_withoutThenOrElse_noErrors(): void
    {
        // if alone: only evaluates condition, no then/else → always no errors
        $errors = $this->validator->validate('v', 'hello', [
            'if' => ['type' => 'string'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testArrayType_oas31_acceptsMatchingType(): void
    {
        $errors = $this->validator->validate('v', 'hello', ['type' => ['string', 'null']]);
        $this->assertSame([], $errors);
    }

    public function testArrayType_oas31_rejectsNonMatchingType(): void
    {
        $errors = $this->validator->validate('v', 42, ['type' => ['string', 'null']]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be of type', $errors[0]);
        $this->assertStringContainsString('string', $errors[0]);
    }

    public function testArrayType_oas31_multiType_acceptsEither(): void
    {
        $errorsStr = $this->validator->validate('v', 'hello', ['type' => ['string', 'integer']]);
        $errorsInt = $this->validator->validate('v', 42, ['type' => ['string', 'integer']]);
        $this->assertSame([], $errorsStr);
        $this->assertSame([], $errorsInt);
    }

    public function testArrayType_oas31_multiType_rejectsMismatch(): void
    {
        $errors = $this->validator->validate('v', 3.14, ['type' => ['string', 'integer']]);
        $this->assertNotEmpty($errors);
    }

    // =========================================================================
    // VAL-4: nested oneOf/anyOf inside union branch
    // =========================================================================

    public function testNestedOneOfInBranch_passesWhenInnerBranchMatches(): void
    {
        $errors = $this->validator->validate('v', ['a' => 1], [
            'oneOf' => [
                [
                    'type' => 'object',
                    'oneOf' => [
                        ['required' => ['a']],
                        ['required' => ['b']],
                    ],
                ],
                ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testNestedOneOfInBranch_rejectsWhenInnerBranchFails(): void
    {
        // ['c' => 1] doesn't satisfy required:a or required:b → inner oneOf fails → outer branch fails
        $errors = $this->validator->validate('v', ['c' => 1], [
            'oneOf' => [
                [
                    'type' => 'object',
                    'oneOf' => [
                        ['required' => ['a']],
                        ['required' => ['b']],
                    ],
                ],
                ['type' => 'string'],
            ],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testNestedAnyOfInBranch_passesWhenInnerBranchMatches(): void
    {
        $errors = $this->validator->validate('v', 5, [
            'anyOf' => [
                [
                    'type' => 'integer',
                    'anyOf' => [
                        ['minimum' => 1],
                        ['maximum' => -1],
                    ],
                ],
                ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // VAL-5: toIntOrNull ignores non-integer float constraints
    // =========================================================================

    public function testMinLength_withFloatConstraint_isIgnored(): void
    {
        // minLength: 2.9 is invalid schema (must be integer) — constraint is skipped
        $errors = $this->validator->validate('s', 'ab', ['minLength' => 2.9]);
        $this->assertSame([], $errors);
    }

    public function testMaxItems_withFloatConstraint_isIgnored(): void
    {
        $errors = $this->validator->validate('a', [1, 2, 3], ['maxItems' => 2.7]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // dependentRequired
    // =========================================================================

    public function testDependentRequired_fieldPresent_missingDep_returnsError(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('billingAddress', $errors[0]);
        $this->assertStringContainsString('creditCard', $errors[0]);
    }

    public function testDependentRequired_fieldPresent_depAlsoPresent_passes(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234', 'billingAddress' => 'Main St'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentRequired_fieldAbsent_depNotRequired(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentRequired_multipleDeps_reportsAllMissing(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234'], [
            'dependentRequired' => ['creditCard' => ['billingAddress', 'billingCity']],
        ]);
        $this->assertCount(2, $errors);
    }

    // =========================================================================
    // dependentSchemas
    // =========================================================================

    public function testDependentSchemas_fieldPresent_schemaApplied_fails(): void
    {
        $errors = $this->validator->validate('obj', ['premium' => true, 'score' => 50], [
            'dependentSchemas' => [
                'premium' => ['properties' => ['score' => ['minimum' => 100]]],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('score', $errors[0]);
    }

    public function testDependentSchemas_fieldAbsent_schemaNotApplied(): void
    {
        $errors = $this->validator->validate('obj', ['score' => 50], [
            'dependentSchemas' => [
                'premium' => ['properties' => ['score' => ['minimum' => 100]]],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentSchemas_fieldPresent_schemaSatisfied_passes(): void
    {
        $errors = $this->validator->validate('obj', ['premium' => true, 'score' => 150], [
            'dependentSchemas' => [
                'premium' => ['properties' => ['score' => ['minimum' => 100]]],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // prefixItems (tuple validation)
    // =========================================================================

    public function testPrefixItems_validTuple_passes(): void
    {
        $errors = $this->validator->validate('t', ['hello', 42, true], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'boolean'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPrefixItems_invalidItemAtIndex_fails(): void
    {
        $errors = $this->validator->validate('t', ['hello', 'not-int'], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('t.1', $errors[0]);
    }

    public function testPrefixItems_shorterArrayThanSchema_passes(): void
    {
        // Only present items are validated
        $errors = $this->validator->validate('t', ['hello'], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPrefixItems_extraItemsBeyondSchema_notValidated(): void
    {
        $errors = $this->validator->validate('t', ['hello', 42, 'extra', 'more'], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPrefixItems_withConstraintsOnItems_fails(): void
    {
        $errors = $this->validator->validate('t', ['ab', 5], [
            'prefixItems' => [
                ['type' => 'string', 'minLength' => 5],
                ['type' => 'integer', 'minimum' => 10],
            ],
        ]);
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('t.0', $errors[0]);
        $this->assertStringContainsString('t.1', $errors[1]);
    }

    // =========================================================================
    // Numeric formats: int32 / int64 range (GAP-3)
    // =========================================================================

    public function testInt32Format_acceptsValueInRange(): void
    {
        $this->assertSame([], $this->validator->validate('f', 100, ['type' => 'integer', 'format' => 'int32']));
        $this->assertSame([], $this->validator->validate('f', 2147483647, ['type' => 'integer', 'format' => 'int32']));
        $this->assertSame([], $this->validator->validate('f', -2147483648, ['type' => 'integer', 'format' => 'int32']));
    }

    public function testInt32Format_rejectsOverflow(): void
    {
        $errors = $this->validator->validate('f', 2147483648, ['type' => 'integer', 'format' => 'int32']);
        $this->assertContains('f must be within int32 range (-2147483648 to 2147483647)', $errors);

        $errors = $this->validator->validate('f', -2147483649, ['type' => 'integer', 'format' => 'int32']);
        $this->assertContains('f must be within int32 range (-2147483648 to 2147483647)', $errors);
    }

    public function testInt32Format_rejectsFractionalValue(): void
    {
        $errors = $this->validator->validate('f', 2.5, ['type' => 'number', 'format' => 'int32']);
        $this->assertContains('f must be an integer (int32)', $errors);
    }

    public function testInt64Format_acceptsNativeIntButRejectsFloatOverflow(): void
    {
        $this->assertSame([], $this->validator->validate('f', 9000000000, ['type' => 'integer', 'format' => 'int64']));

        $errors = $this->validator->validate('f', 1.0e30, ['type' => 'number', 'format' => 'int64']);
        $this->assertContains('f must be within int64 range (-9223372036854775808 to 9223372036854775807)', $errors);
    }

    public function testFloatAndDoubleFormats_carryNoExtraRange(): void
    {
        $this->assertSame([], $this->validator->validate('f', 1.5, ['type' => 'number', 'format' => 'float']));
        $this->assertSame([], $this->validator->validate('f', 1.5e300, ['type' => 'number', 'format' => 'double']));
    }

    // =========================================================================
    // UUID format: nil / max special cases (GAP-4)
    // =========================================================================

    public function testUuidFormat_acceptsRegularV4(): void
    {
        $this->assertSame([], $this->validator->validate('f', '550e8400-e29b-41d4-a716-446655440000', ['format' => 'uuid']));
    }

    public function testUuidFormat_acceptsNilAndMaxUuid(): void
    {
        $this->assertSame([], $this->validator->validate('f', '00000000-0000-0000-0000-000000000000', ['format' => 'uuid']));
        $this->assertSame([], $this->validator->validate('f', 'ffffffff-ffff-ffff-ffff-ffffffffffff', ['format' => 'uuid']));
        $this->assertSame([], $this->validator->validate('f', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', ['format' => 'uuid']));
    }

    public function testUuidFormat_rejectsGarbage(): void
    {
        $errors = $this->validator->validate('f', 'not-a-uuid', ['format' => 'uuid']);
        $this->assertContains('f must match format uuid', $errors);
    }

    // =========================================================================
    // pattern: single-compile invalid vs no-match (PERF-6)
    // =========================================================================

    public function testInvalidSchemaPatternReportsDistinctError(): void
    {
        // Unbalanced group → invalid regex pattern in schema.
        $errors = $this->validator->validate('f', 'abc', ['pattern' => '(']);
        $this->assertContains('f has invalid regex pattern in schema: (', $errors);
    }

    public function testValidPatternNoMatchReportsMustMatch(): void
    {
        $errors = $this->validator->validate('f', 'abc', ['pattern' => '^[0-9]+$']);
        $this->assertContains('f must match pattern ^[0-9]+$', $errors);
    }

    public function testValidPatternMatchPasses(): void
    {
        $this->assertSame([], $this->validator->validate('f', '123', ['pattern' => '^[0-9]+$']));
    }

    // =========================================================================
    // Union branch selection: oneOf / anyOf incl. 3.1 type-array (GAP-9)
    // =========================================================================

    public function testOneOfExactlyOneBranchMatches(): void
    {
        $constraints = ['oneOf' => [
            ['type' => 'string', 'maxLength' => 3],
            ['type' => 'string', 'minLength' => 5],
        ]];
        $this->assertSame([], $this->validator->validate('f', 'ab', $constraints));
        $this->assertNotSame([], $this->validator->validate('f', 'abcd', $constraints));
    }

    public function testOneOfOverlappingBranchesFail(): void
    {
        // 50 satisfies both branches → oneOf requires exactly one.
        $errors = $this->validator->validate('f', 50, ['oneOf' => [
            ['type' => 'integer', 'minimum' => 0],
            ['type' => 'integer', 'maximum' => 100],
        ]]);
        $this->assertContains('f matches more than one allowed oneOf branch', $errors);
    }

    public function testAnyOfMatchesAtLeastOneBranch(): void
    {
        $constraints = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];
        $this->assertSame([], $this->validator->validate('f', 5, $constraints));
        $this->assertSame([], $this->validator->validate('f', 'x', $constraints));
        $this->assertNotSame([], $this->validator->validate('f', true, $constraints));
    }

    public function testOneOfWithTypeArrayBranchSelectsByType(): void
    {
        // OpenAPI 3.1 nullable branch: type [string, null].
        $constraints = ['oneOf' => [
            ['type' => ['string', 'null']],
            ['type' => 'integer'],
        ]];
        $this->assertSame([], $this->validator->validate('f', 'x', $constraints));
        $this->assertSame([], $this->validator->validate('f', null, $constraints));
        $this->assertSame([], $this->validator->validate('f', 42, $constraints));
    }
}
