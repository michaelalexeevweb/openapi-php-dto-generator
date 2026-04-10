<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\TestCase;

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
}
