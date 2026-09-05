<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TypeError;

enum TestStringBackedEnum: string
{
    case INTEGER = 'integer';
}

enum TestIntBackedEnum: int
{
    case ONE = 1;
}

/**
 * A DTO whose `toArray()` fails for the ONE documented reason: an optional field was never provided.
 * The validator has to skip the object keywords for such a value — the deserializer already reports
 * the absence, and a second, vaguer message would be noise.
 */
final class NotProvidedDtoStub implements GeneratedDtoInterface
{
    public function toArray(): array
    {
        throw new LogicException('field "x" ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }

    public function toJson(): string
    {
        return '{}';
    }

    public static function getNormalizationMap(): array
    {
        return [];
    }

    public static function getAliases(): array
    {
        return [];
    }

    public static function getConstraints(): array
    {
        return [];
    }
}

/**
 * A DTO whose `toArray()` fails for a reason that is a DEFECT — here the `TypeError` that the
 * map-item cast used to throw on a nullable item. Swallowing it made a broken response look like a
 * payload with no object rules, so it must surface.
 */
final class BrokenDtoStub implements GeneratedDtoInterface
{
    public function toArray(): array
    {
        throw new TypeError('Argument #1 ($item) must be of type array, null given');
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }

    public function toJson(): string
    {
        return '{}';
    }

    public static function getNormalizationMap(): array
    {
        return [];
    }

    public static function getAliases(): array
    {
        return [];
    }

    public static function getConstraints(): array
    {
        return [];
    }
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

    public function testTypeStringAcceptsStringBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['type' => 'string'],
        );

        $this->assertSame([], $errors);
    }

    public function testTypeStringRejectsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'string'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type must be of type string', $errors[0]);
    }

    public function testTypeIntegerAcceptsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'integer'],
        );

        $this->assertSame([], $errors);
    }

    public function testTypeIntegerRejectsStringBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['type' => 'integer'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type must be of type integer', $errors[0]);
    }

    public function testTypeNumberAcceptsIntBackedEnum(): void
    {
        $errors = $this->validator->validate(
            subject: 'type',
            value: TestIntBackedEnum::ONE,
            constraints: ['type' => 'number'],
        );

        $this->assertSame([], $errors);
    }

    public function testEnumAcceptsBackedEnumWhoseValueMatches(): void
    {
        // The getter returns a backed enum object; the schema enum holds raw scalars.
        // The match must compare by ->value, not reject the object outright.
        $this->assertSame([], $this->validator->validate(
            subject: 'status',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['enum' => ['integer', 'other']],
        ));
        $this->assertSame([], $this->validator->validate(
            subject: 'priority',
            value: TestIntBackedEnum::ONE,
            constraints: ['enum' => [1, 2]],
        ));
    }

    public function testEnumRejectsBackedEnumWhoseValueIsNotAllowed(): void
    {
        $errors = $this->validator->validate(
            subject: 'status',
            value: TestStringBackedEnum::INTEGER,
            constraints: ['enum' => ['active', 'inactive']],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('status must be one of', $errors[0]);
    }

    public function testTypeArrayRejectsAssociativeArrayWithClearMessage(): void
    {
        // An associative array is a JSON object, not a JSON array (list) — the message must
        // explain that rather than the confusing bare "must be of type array".
        $errors = $this->validator->validate(
            subject: 'tags',
            value: ['a' => 1, 'b' => 2],
            constraints: ['type' => 'array'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('tags must be a JSON array (list', $errors[0]);
    }

    public function testTypeArrayAcceptsList(): void
    {
        $errors = $this->validator->validate(subject: 'tags', value: ['a', 'b'], constraints: ['type' => 'array']);

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

    public function testExclusiveMaximumNumericRejectsEqualValue(): void
    {
        // OpenAPI 3.1: exclusiveMaximum IS the exclusive upper boundary
        $errors = $this->validator->validate(subject: 'price', value: 50.0, constraints: ['exclusiveMaximum' => 50]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than 50', $errors[0]);
    }

    public function testExclusiveMaximumNumericAcceptsBelowBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'price', value: 49.0, constraints: ['exclusiveMaximum' => 50]);
        $this->assertSame([], $errors);
    }

    public function testExclusiveMaximumBooleanRejectsEqualToMaximum(): void
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

    public function testExclusiveMaximumBooleanAcceptsBelowMaximum(): void
    {
        $errors = $this->validator->validate(
            subject: 'score',
            value: 99.0,
            constraints: ['maximum' => 100, 'exclusiveMaximum' => true],
        );
        $this->assertSame([], $errors);
    }

    public function testMaximumInclusiveAcceptsExactBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 100.0, constraints: ['maximum' => 100]);
        $this->assertSame([], $errors);
    }

    public function testMaximumInclusiveRejectsAboveBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 101.0, constraints: ['maximum' => 100]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be less than or equal to 100', $errors[0]);
    }

    // =========================================================================
    // Numeric — multipleOf with float divisor
    // =========================================================================

    public function testMultipleOfFloatRejectsNonMultiple(): void
    {
        $errors = $this->validator->validate(subject: 'amount', value: 10.1, constraints: ['multipleOf' => 0.5]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be a multiple of 0.5', $errors[0]);
    }

    /**
     * `multipleOf` holds at any scale, because the tolerance is on the RATIO, not on the value.
     *
     * A review flagged the `1e-9` epsilon as a false-positive risk for a very small divisor. It is not:
     * `value / multipleOf` is 3 or 2.5 whether the divisor is 0.5 or 1e-10, so the comparison is
     * scale-free. Pinned rather than argued.
     */
    public function testMultipleOfHoldsForVerySmallDivisors(): void
    {
        foreach ([1e-8, 1e-10] as $divisor) {
            $this->assertSame(
                [],
                $this->validator->validate('f', 3 * $divisor, ['type' => 'number', 'multipleOf' => $divisor]),
                sprintf('3x%s is a multiple', var_export($divisor, true)),
            );
            $this->assertNotEmpty(
                $this->validator->validate('f', 2.5 * $divisor, ['type' => 'number', 'multipleOf' => $divisor]),
                sprintf('2.5x%s is not', var_export($divisor, true)),
            );
        }
    }

    public function testMultipleOfFloatAcceptsExactMultiple(): void
    {
        $errors = $this->validator->validate(subject: 'amount', value: 10.5, constraints: ['multipleOf' => 0.5]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // String formats
    // =========================================================================

    public function testFormatUriRejectsPlainText(): void
    {
        $errors = $this->validator->validate(subject: 'url', value: 'not a url', constraints: ['format' => 'uri']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format uri', $errors[0]);
    }

    public function testFormatUriAcceptsHttpsUrl(): void
    {
        $errors = $this->validator->validate(
            subject: 'url',
            value: 'https://example.com/path?q=1',
            constraints: ['format' => 'uri'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv4RejectsOutOfRangeOctets(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '999.999.999.999',
            constraints: ['format' => 'ipv4'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatIpv4AcceptsValidAddress(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '192.168.1.1',
            constraints: ['format' => 'ipv4'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv6AcceptsValidAddress(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '2001:db8::1',
            constraints: ['format' => 'ipv6'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIpv6RejectsIpv4Address(): void
    {
        $errors = $this->validator->validate(
            subject: 'ip',
            value: '192.168.1.1',
            constraints: ['format' => 'ipv6'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatByteAcceptsValidBase64(): void
    {
        $errors = $this->validator->validate(
            subject: 'data',
            value: base64_encode('hello world'),
            constraints: ['format' => 'byte'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatByteRejectsStringWithIllegalChars(): void
    {
        $errors = $this->validator->validate(
            subject: 'data',
            value: '!!!invalid!!!',
            constraints: ['format' => 'byte'],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format byte', $errors[0]);
    }

    public function testFormatIdnEmailAcceptsUnicodeLocalPart(): void
    {
        $errors = $this->validator->validate(
            subject: 'email',
            value: 'jöhn@example.com',
            constraints: ['format' => 'idn-email'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIdnEmailRejectsGarbage(): void
    {
        $errors = $this->validator->validate(
            subject: 'email',
            value: 'not-an-email',
            constraints: ['format' => 'idn-email'],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format idn-email', $errors[0]);
    }

    public function testFormatIriAcceptsUnicodeUri(): void
    {
        $errors = $this->validator->validate(
            subject: 'iri',
            value: 'https://example.com/ümlaut/路径',
            constraints: ['format' => 'iri'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatIriRejectsSchemelessOrWhitespace(): void
    {
        // 'a:' is a scheme with no body — not a usable IRI.
        foreach (['no-scheme/path', 'http://has space.com', 'a:'] as $bad) {
            $errors = $this->validator->validate(subject: 'iri', value: $bad, constraints: ['format' => 'iri']);
            $this->assertNotEmpty($errors, "expected rejection for '{$bad}'");
        }
    }

    public function testFormatDurationAcceptsIso8601(): void
    {
        foreach (['P3Y6M4DT12H30M5S', 'PT15M', 'P1W', 'P1D', 'PT0.5S'] as $good) {
            $errors = $this->validator->validate(
                subject: 'dur',
                value: $good,
                constraints: ['format' => 'duration'],
            );
            $this->assertSame([], $errors, "expected accept for '{$good}'");
        }
    }

    public function testFormatDurationRejectsInvalid(): void
    {
        // Last three: the week form (PnW) is mutually exclusive with Y/M/D/T components.
        foreach (['P', 'PT', '3Y', '1H', 'P1S', 'P1W2D', 'P1W1Y', 'P1WT1H'] as $bad) {
            $errors = $this->validator->validate(
                subject: 'dur',
                value: $bad,
                constraints: ['format' => 'duration'],
            );
            $this->assertNotEmpty($errors, "expected reject for '{$bad}'");
        }
    }

    public function testFormatJsonPointerAcceptsValidPointers(): void
    {
        foreach (['', '/foo', '/foo/0', '/a~1b', '/m~0n'] as $good) {
            $errors = $this->validator->validate(
                subject: 'ptr',
                value: $good,
                constraints: ['format' => 'json-pointer'],
            );
            $this->assertSame([], $errors, "expected accept for '{$good}'");
        }
    }

    public function testFormatJsonPointerRejectsMissingLeadingSlashAndBadEscape(): void
    {
        foreach (['foo', '/foo~', '/foo~2'] as $bad) {
            $errors = $this->validator->validate(
                subject: 'ptr',
                value: $bad,
                constraints: ['format' => 'json-pointer'],
            );
            $this->assertNotEmpty($errors, "expected reject for '{$bad}'");
        }
    }

    public function testFormatRegexAcceptsCompilablePattern(): void
    {
        $errors = $this->validator->validate(
            subject: 'pat',
            value: '^[a-z]+\d{2,4}$',
            constraints: ['format' => 'regex'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatRegexRejectsUncompilablePattern(): void
    {
        $errors = $this->validator->validate(
            subject: 'pat',
            value: '([unclosed',
            constraints: ['format' => 'regex'],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format regex', $errors[0]);
    }

    public function testFormatRegexAcceptsByteOrientedPattern(): void
    {
        // A pattern with a lone high byte (invalid UTF-8) but a compilable byte-oriented
        // PCRE must be accepted — the validator must not force the `u` modifier.
        $errors = $this->validator->validate(
            subject: 'pat',
            value: "a\xFFb",
            constraints: ['format' => 'regex'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatBinaryAcceptsStringValue(): void
    {
        $errors = $this->validator->validate(
            subject: 'file',
            value: 'raw-bytes',
            constraints: ['format' => 'binary'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatBinaryRejectsIntegerValue(): void
    {
        $errors = $this->validator->validate(
            subject: 'file',
            value: 12345,
            constraints: ['format' => 'binary'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('expects binary data', $errors[0]);
    }

    public function testFormatHostnameRejectsLeadingHyphen(): void
    {
        $errors = $this->validator->validate(
            subject: 'host',
            value: '-invalid-',
            constraints: ['format' => 'hostname'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testFormatHostnameAcceptsValidDomain(): void
    {
        $errors = $this->validator->validate(
            subject: 'host',
            value: 'example.com',
            constraints: ['format' => 'hostname'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatPasswordAlwaysValid(): void
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

    public function testAnyOfAcceptsWhenOneBranchMatches(): void
    {
        $errors = $this->validator->validate('v', 'hello', [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string', 'minLength' => 3],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAnyOfAcceptsWhenMultipleBranchesMatch(): void
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

    public function testAnyOfRejectsWhenValueTypeMatchesNoBranch(): void
    {
        // Boolean doesn't match 'integer' or 'string' type → no branch matches at all
        $errors = $this->validator->validate('v', true, [
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        // With every branch gated out there is no branch reason to report, so the sentence names the
        // types the union accepts instead of leaving the caller to open the spec.
        $this->assertSame(
            'v does not match any anyOf branch (expected integer or string, got boolean).',
            $errors[0],
        );
    }

    public function testAnyOfReturnsConstraintErrorsWhenTypeMatchesButConstraintFails(): void
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

    public function testOneOfAcceptsExactlyOneMatch(): void
    {
        $errors = $this->validator->validate('id', 42, [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testOneOfRejectsWhenMultipleBranchesMatch(): void
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

    public function testOneOfRejectsWhenNoBranchMatches(): void
    {
        $errors = $this->validator->validate('v', true, [
            'oneOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame(
            'v does not match any oneOf branch (expected integer or string, got boolean).',
            $errors[0],
        );
    }

    public function testAUnionOfObjectAndIntegerNamesBothTypesWhenNeitherMatches(): void
    {
        $errors = $this->validator->validate('f', true, [
            'oneOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
                ['type' => 'integer'],
            ],
        ]);

        // The same sentence the emitted interpreter writes, subject prefix aside.
        $this->assertSame(
            ['f does not match any oneOf branch (expected object or integer, got boolean).'],
            $errors,
        );
    }

    public function testAUnionWithNoDeclaredTypesKeepsTheBareSentence(): void
    {
        // Nothing to name: with no `type` on either branch the branches are always evaluated, and a
        // parenthesis listing nothing would be noise.
        $errors = $this->validator->validate('f', true, [
            'oneOf' => [
                ['const' => 'a'],
                ['const' => 'b'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsString('expected', $errors[0]);
    }

    public function testOneOfAcceptsWhenOneOfTwoTypeMatchingBranchesFullyValidates(): void
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

    public function testItemsRejectsInvalidItemsWithCorrectPath(): void
    {
        $errors = $this->validator->validate('emails', ['a@b.com', 'bad-email'], [
            'items' => ['type' => 'string', 'format' => 'email'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('emails.1', $errors[0]);
    }

    public function testItemsAcceptsAllValidItems(): void
    {
        $errors = $this->validator->validate('emails', ['a@b.com', 'c@d.com'], [
            'items' => ['type' => 'string', 'format' => 'email'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testItemsCollectsErrorsForMultipleInvalidItems(): void
    {
        $errors = $this->validator->validate('nums', [1, 200, 300], [
            'items' => ['type' => 'integer', 'maximum' => 100],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('nums.1', $errors[0]);
        $this->assertStringContainsString('nums.2', $errors[1]);
    }

    public function testItemsWithAnyOfAcceptsElementsMatchingAtLeastOneBranch(): void
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

    public function testItemsWithAnyOfRejectsElementMatchingNoBranch(): void
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

    public function testItemsWithOneOfAcceptsElementMatchingExactlyOneBranch(): void
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

    public function testItemsWithOneOfRejectsElementMatchingMoreThanOneBranch(): void
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

    public function testItemsWithOneOfRejectsElementMatchingNoBranch(): void
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

    public function testContainsAcceptsWhenAtLeastOneItemMatches(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 42, 'world'], [
            'contains' => ['type' => 'integer'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testContainsRejectsWhenNoItemMatches(): void
    {
        $errors = $this->validator->validate('tags', ['hello', 'world'], [
            'contains' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('contains', $errors[0]);
    }

    public function testContainsWithMinContainsRequiresEnoughMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 'a', 2], [
            'contains' => ['type' => 'integer'],
            'minContains' => 3,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 3', $errors[0]);
    }

    public function testContainsWithMinContainsAcceptsEnoughMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 2, 3, 'x'], [
            'contains' => ['type' => 'integer'],
            'minContains' => 3,
        ]);
        $this->assertSame([], $errors);
    }

    public function testContainsWithMaxContainsRejectsTooManyMatches(): void
    {
        $errors = $this->validator->validate('nums', [1, 2, 3], [
            'contains' => ['type' => 'integer'],
            'maxContains' => 2,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at most 2', $errors[0]);
    }

    public function testContainsWithMaxContainsAcceptsWithinLimit(): void
    {
        $errors = $this->validator->validate('nums', [1, 'a', 'b'], [
            'contains' => ['type' => 'integer'],
            'maxContains' => 2,
        ]);
        $this->assertSame([], $errors);
    }

    public function testContainsWithMinAndMaxContainsAcceptsExactRange(): void
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

    public function testDateTimeInterfaceIsNormalizedToDateStringForDateFormat(): void
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

    public function testDateTimeInterfaceIsNormalizedToAtomStringForDateTimeFormat(): void
    {
        $dt = new DateTimeImmutable('2024-06-15T12:00:00+00:00');
        $errors = $this->validator->validate(
            subject: 'ts',
            value: $dt,
            constraints: ['format' => 'date-time'],
        );
        $this->assertSame([], $errors);
    }

    public function testDateTimeInterfaceIsNormalizedToAtomStringWhenNoFormatGiven(): void
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

    public function testMinimumInclusiveAcceptsExactBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 5.0, constraints: ['minimum' => 5]);
        $this->assertSame([], $errors);
    }

    public function testMinimumInclusiveRejectsBelowBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 4.9, constraints: ['minimum' => 5]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than or equal to 5', $errors[0]);
    }

    public function testExclusiveMinimumNumericRejectsEqualValue(): void
    {
        // OpenAPI 3.1: exclusiveMinimum IS the exclusive lower boundary
        $errors = $this->validator->validate(subject: 'n', value: 5.0, constraints: ['exclusiveMinimum' => 5]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be greater than 5', $errors[0]);
    }

    public function testExclusiveMinimumNumericAcceptsAboveBoundary(): void
    {
        $errors = $this->validator->validate(subject: 'n', value: 5.1, constraints: ['exclusiveMinimum' => 5]);
        $this->assertSame([], $errors);
    }

    public function testExclusiveMinimumBooleanRejectsEqualToMinimum(): void
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

    public function testExclusiveMinimumBooleanAcceptsAboveMinimum(): void
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

    public function testMinLengthAcceptsExactLength(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc', constraints: ['minLength' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMinLengthRejectsTooShort(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'ab', constraints: ['minLength' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('length must be at least 3', $errors[0]);
    }

    public function testMaxLengthAcceptsExactLength(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc', constraints: ['maxLength' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxLengthRejectsTooLong(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abcd', constraints: ['maxLength' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('length must be at most 3', $errors[0]);
    }

    public function testPatternAcceptsMatchingString(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'abc123', constraints: ['pattern' => '^[a-z0-9]+$']);
        $this->assertSame([], $errors);
    }

    public function testPatternRejectsNonMatchingString(): void
    {
        $errors = $this->validator->validate(subject: 's', value: 'ABC!', constraints: ['pattern' => '^[a-z0-9]+$']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match pattern', $errors[0]);
    }

    public function testPatternAcceptsPatternContainingForwardSlash(): void
    {
        // Patterns with `/` must not double-escape when `#` delimiter is used
        $errors = $this->validator->validate(subject: 'url', value: 'https://example.com/path', constraints: ['pattern' => '^https?://.+']);
        $this->assertSame([], $errors);
    }

    public function testPatternRejectsNonMatchingPatternWithForwardSlash(): void
    {
        $errors = $this->validator->validate(subject: 'url', value: 'ftp://example.com', constraints: ['pattern' => '^https?://.+']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match pattern', $errors[0]);
    }

    public function testFormatEmailAcceptsValidAddress(): void
    {
        $errors = $this->validator->validate(subject: 'e', value: 'user@example.com', constraints: ['format' => 'email']);
        $this->assertSame([], $errors);
    }

    public function testFormatEmailRejectsPlainText(): void
    {
        $errors = $this->validator->validate(subject: 'e', value: 'not-an-email', constraints: ['format' => 'email']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format email', $errors[0]);
    }

    public function testFormatUuidAcceptsValidUuid(): void
    {
        $errors = $this->validator->validate(
            subject: 'id',
            value: '550e8400-e29b-41d4-a716-446655440000',
            constraints: ['format' => 'uuid'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatUuidRejectsNonUuidString(): void
    {
        $errors = $this->validator->validate(subject: 'id', value: 'not-a-uuid', constraints: ['format' => 'uuid']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format uuid', $errors[0]);
    }

    public function testFormatDateAcceptsValidDate(): void
    {
        $errors = $this->validator->validate(subject: 'd', value: '2024-06-15', constraints: ['format' => 'date']);
        $this->assertSame([], $errors);
    }

    public function testFormatDateRejectsInvalidDate(): void
    {
        $errors = $this->validator->validate(subject: 'd', value: '15-06-2024', constraints: ['format' => 'date']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format date', $errors[0]);
    }

    public function testFormatDateTimeAcceptsAtomString(): void
    {
        $errors = $this->validator->validate(
            subject: 'ts',
            value: '2024-06-15T12:00:00+00:00',
            constraints: ['format' => 'date-time'],
        );
        $this->assertSame([], $errors);
    }

    public function testFormatDateTimeRejectsDateOnlyString(): void
    {
        $errors = $this->validator->validate(subject: 'ts', value: '2024-06-15', constraints: ['format' => 'date-time']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format date-time', $errors[0]);
    }

    // =========================================================================
    // Array — minItems / maxItems / uniqueItems
    // =========================================================================

    public function testMinItemsAcceptsEnoughItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2, 3], constraints: ['minItems' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMinItemsRejectsTooFewItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2], constraints: ['minItems' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must contain at least 3 items', $errors[0]);
    }

    public function testMaxItemsAcceptsFewEnoughItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2], constraints: ['maxItems' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxItemsRejectsTooManyItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 2, 3, 4], constraints: ['maxItems' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must contain at most 3 items', $errors[0]);
    }

    public function testUniqueItemsAcceptsDistinctItems(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: ['a', 'b', 'c'], constraints: ['uniqueItems' => true]);
        $this->assertSame([], $errors);
    }

    public function testUniqueItemsRejectsDuplicateScalar(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: ['x', 'x', 'y'], constraints: ['uniqueItems' => true]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique items', $errors[0]);
    }

    public function testUniqueItemsFalseAllowsDuplicates(): void
    {
        $errors = $this->validator->validate(subject: 'a', value: [1, 1, 1], constraints: ['uniqueItems' => false]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // enum
    // =========================================================================

    public function testEnumAcceptsValueInList(): void
    {
        $errors = $this->validator->validate(
            subject: 'status',
            value: 'active',
            constraints: ['enum' => ['active', 'inactive', 'pending']],
        );
        $this->assertSame([], $errors);
    }

    public function testEnumRejectsValueNotInList(): void
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

    public function testEnumUsesStrictComparison(): void
    {
        // "1" (string) must not match 1 (int)
        $errors = $this->validator->validate(
            subject: 'n',
            value: '1',
            constraints: ['enum' => [1, 2, 3]],
        );
        $this->assertNotEmpty($errors);
    }

    public function testEnumAcceptsIntegerValue(): void
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

    public function testConstAcceptsMatchingValue(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 'fixed', constraints: ['const' => 'fixed']);
        $this->assertSame([], $errors);
    }

    public function testConstRejectsNonMatchingValue(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 'other', constraints: ['const' => 'fixed']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must equal', $errors[0]);
        $this->assertStringContainsString('"fixed"', $errors[0]);
    }

    public function testConstUsesStrictComparison(): void
    {
        // 0 (int) must not match false (bool)
        $errors = $this->validator->validate(subject: 'v', value: 0, constraints: ['const' => false]);
        $this->assertNotEmpty($errors);
    }

    public function testConstAcceptsMatchingInteger(): void
    {
        $errors = $this->validator->validate(subject: 'v', value: 42, constraints: ['const' => 42]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // allOf
    // =========================================================================

    public function testAllOfAcceptsWhenAllBranchesPass(): void
    {
        $errors = $this->validator->validate('n', 7, [
            'allOf' => [
                ['minimum' => 1],
                ['maximum' => 10],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAllOfRejectsWhenOneBranchFails(): void
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

    public function testAllOfCollectsErrorsFromAllFailingBranches(): void
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

    public function testAllOfCanCombineStringConstraints(): void
    {
        $errors = $this->validator->validate('s', 'hi', [
            'allOf' => [
                ['minLength' => 2],
                ['maxLength' => 5],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAllOfWithAnyOfAcceptsWhenBothSatisfied(): void
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

    public function testAllOfWithAnyOfRejectsWhenAllOfFails(): void
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

    public function testAllOfWithAnyOfRejectsWhenAnyOfFails(): void
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

    public function testNotAcceptsWhenSchemaDoesNotMatch(): void
    {
        // Value is not a multiple of 3 → passes 'not' constraint
        $errors = $this->validator->validate('n', 7, [
            'not' => ['multipleOf' => 3],
        ]);
        $this->assertSame([], $errors);
    }

    public function testNotRejectsWhenSchemaMatches(): void
    {
        // Value IS a multiple of 3 → violates 'not' constraint
        $errors = $this->validator->validate('n', 9, [
            'not' => ['multipleOf' => 3],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("must not match the 'not' schema", $errors[0]);
    }

    public function testNotWithTypeConstraintRejectsMatchingType(): void
    {
        // 'not integer' → string passes, integer fails
        $errors = $this->validator->validate('v', 42, [
            'not' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testNotWithTypeConstraintAcceptsNonMatchingType(): void
    {
        $errors = $this->validator->validate('v', 'hello', [
            'not' => ['type' => 'integer'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testNotWithAllOfRejectsWhenAllBranchesMatch(): void
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

    public function testNotWithAllOfAcceptsWhenAnyBranchFails(): void
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

    public function testMinPropertiesAcceptsEnoughProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2], ['minProperties' => 2]);
        $this->assertSame([], $errors);
    }

    public function testMinPropertiesRejectsTooFewProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1], ['minProperties' => 2]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must have at least 2 properties', $errors[0]);
    }

    public function testMinPropertiesSingularWordWhenOne(): void
    {
        $errors = $this->validator->validate('obj', [], ['minProperties' => 1]);
        $this->assertStringContainsString('at least 1 property', $errors[0]);
    }

    public function testMaxPropertiesAcceptsFewEnoughProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1], ['maxProperties' => 3]);
        $this->assertSame([], $errors);
    }

    public function testMaxPropertiesRejectsTooManyProperties(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], ['maxProperties' => 3]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must have at most 3 properties', $errors[0]);
    }

    public function testMaxPropertiesSingularWordWhenOne(): void
    {
        $errors = $this->validator->validate('obj', ['a' => 1, 'b' => 2], ['maxProperties' => 1]);
        $this->assertStringContainsString('at most 1 property', $errors[0]);
    }

    public function testMinAndMaxPropertiesCombinedRangeAcceptsValid(): void
    {
        $errors = $this->validator->validate('obj', ['x' => 1, 'y' => 2], ['minProperties' => 1, 'maxProperties' => 3]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // additionalProperties: false
    // =========================================================================

    public function testAdditionalPropertiesFalseRejectsExtraKey(): void
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

    public function testAdditionalPropertiesFalseAcceptsExactlyDefinedKeys(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $this->assertSame([], $errors);
    }

    public function testAdditionalPropertiesFalseRejectsMultipleExtraKeys(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Bob', 'foo' => 1, 'bar' => 2], [
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);
        $this->assertCount(2, $errors);
    }

    public function testAdditionalPropertiesFalseWithoutPropertiesAllowsNothing(): void
    {
        // additionalProperties: false without 'properties' → no defined keys → every key is
        // additional and must be rejected (previously skipped due to a null-guard bug).
        $errors = $this->validator->validate('obj', ['key' => 'value'], ['additionalProperties' => false]);
        $this->assertContains('obj has additional property "key" which is not allowed.', $errors);
    }

    public function testAdditionalPropertiesAsSchemaValidatesExtraPropertyValues(): void
    {
        $errors = $this->validator->validate('obj', ['dynamic' => 'not-a-number'], [
            'additionalProperties' => ['type' => 'integer'],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('obj.dynamic', $errors[0]);
        $this->assertStringContainsString('type integer', $errors[0]);
    }

    public function testAdditionalPropertiesAsSchemaAcceptsValidExtraPropertyValues(): void
    {
        $errors = $this->validator->validate('obj', ['count' => 42, 'total' => 100], [
            'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAdditionalPropertiesAsSchemaSkipsDefinedProperties(): void
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

    public function testPropertiesValidatesDefinedPropertyConstraints(): void
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

    public function testPropertiesAcceptsValidNestedValues(): void
    {
        $errors = $this->validator->validate('obj', ['age' => 25, 'name' => 'Bob'], [
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0],
                'name' => ['type' => 'string', 'minLength' => 1],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPropertiesSkipsAbsentOptionalProperties(): void
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

    public function testPropertiesCollectsErrorsFromMultipleInvalidFields(): void
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

    public function testRequiredMissingRequiredPropertyReturnsError(): void
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

    public function testRequiredAllRequiredPresentReturnsNoErrors(): void
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

    public function testRequiredMultipleMissingFieldsReportsAll(): void
    {
        $errors = $this->validator->validate('obj', [], [
            'required' => ['name', 'email', 'age'],
        ]);
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('obj.name', $errors[0]);
        $this->assertStringContainsString('obj.email', $errors[1]);
        $this->assertStringContainsString('obj.age', $errors[2]);
    }

    public function testRequiredWithoutPropertiesConstraintStillValidates(): void
    {
        // required without properties is valid OpenAPI — just checks key presence
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'required' => ['name', 'email'],
        ]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('obj.email', $errors[0]);
    }

    public function testRequiredNullValueForRequiredKeyPassesPresenceCheck(): void
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

    public function testMultipleOfZeroDoesNotDivideByZero(): void
    {
        // multipleOf: 0 is invalid per OpenAPI spec, but must not throw division by zero
        $errors = $this->validator->validate('n', 5, ['multipleOf' => 0]);
        $this->assertSame([], $errors);
    }

    public function testUniqueItemsWithComplexObjectsDetectsDuplicates(): void
    {
        $errors = $this->validator->validate('arr', [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 1, 'name' => 'Alice'],
        ], ['uniqueItems' => true]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique items', $errors[0]);
    }

    public function testUniqueItemsWithComplexObjectsAcceptsDistinct(): void
    {
        $errors = $this->validator->validate('arr', [
            ['id' => 1],
            ['id' => 2],
        ], ['uniqueItems' => true]);
        $this->assertSame([], $errors);
    }

    public function testFormatDatetimeAliasForDateTime(): void
    {
        // 'datetime' is an alias for 'date-time' in the validator
        $errors = $this->validator->validate('ts', '2024-06-15T12:00:00+00:00', ['format' => 'datetime']);
        $this->assertSame([], $errors);
    }

    public function testFormatDatetimeAliasRejectsDateOnlyString(): void
    {
        $errors = $this->validator->validate('ts', '2024-06-15', ['format' => 'datetime']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match format datetime', $errors[0]);
    }

    public function testTypeNullMatchesNullValue(): void
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

    public function testIfThenConditionPassesThenApplied(): void
    {
        // if type=string → then minLength=5; value 'hi' fails then
        $errors = $this->validator->validate('v', 'hi', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 5', $errors[0]);
    }

    public function testIfThenConditionPassesThenSatisfied(): void
    {
        $errors = $this->validator->validate('v', 'hello world', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfThenConditionFailsThenSkipped(): void
    {
        // value is integer, if=string fails → then not applied → no errors
        $errors = $this->validator->validate('v', 42, [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfElseConditionFailsElseApplied(): void
    {
        // if type=string fails → else minimum=10; value 3 fails else
        $errors = $this->validator->validate('v', 3, [
            'if' => ['type' => 'string'],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('greater than or equal to 10', $errors[0]);
    }

    public function testIfElseConditionFailsElseSatisfied(): void
    {
        $errors = $this->validator->validate('v', 42, [
            'if' => ['type' => 'string'],
            'else' => ['minimum' => 10],
        ]);
        $this->assertSame([], $errors);
    }

    public function testIfThenElseConditionPassesThenAppliedElseSkipped(): void
    {
        $errors = $this->validator->validate('v', 'hi', [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 5', $errors[0]);
    }

    public function testIfThenElseConditionFailsElseAppliedThenSkipped(): void
    {
        $errors = $this->validator->validate('v', 3, [
            'if' => ['type' => 'string'],
            'then' => ['minLength' => 5],
            'else' => ['minimum' => 10],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('greater than or equal to 10', $errors[0]);
    }

    public function testIfWithoutThenOrElseNoErrors(): void
    {
        // if alone: only evaluates condition, no then/else → always no errors
        $errors = $this->validator->validate('v', 'hello', [
            'if' => ['type' => 'string'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testArrayTypeOas31AcceptsMatchingType(): void
    {
        $errors = $this->validator->validate('v', 'hello', ['type' => ['string', 'null']]);
        $this->assertSame([], $errors);
    }

    public function testArrayTypeOas31RejectsNonMatchingType(): void
    {
        $errors = $this->validator->validate('v', 42, ['type' => ['string', 'null']]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be of type', $errors[0]);
        $this->assertStringContainsString('string', $errors[0]);
    }

    public function testArrayTypeOas31MultiTypeAcceptsEither(): void
    {
        $errorsStr = $this->validator->validate('v', 'hello', ['type' => ['string', 'integer']]);
        $errorsInt = $this->validator->validate('v', 42, ['type' => ['string', 'integer']]);
        $this->assertSame([], $errorsStr);
        $this->assertSame([], $errorsInt);
    }

    public function testArrayTypeOas31MultiTypeRejectsMismatch(): void
    {
        $errors = $this->validator->validate('v', 3.14, ['type' => ['string', 'integer']]);
        $this->assertNotEmpty($errors);
    }

    // =========================================================================
    // nested oneOf/anyOf inside union branch
    // =========================================================================

    public function testNestedOneOfInBranchPassesWhenInnerBranchMatches(): void
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

    public function testNestedOneOfInBranchRejectsWhenInnerBranchFails(): void
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

    public function testNestedAnyOfInBranchPassesWhenInnerBranchMatches(): void
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

    public function testTripleNestedOneOfValidatesToInnermostBranch(): void
    {
        // oneOf → oneOf → oneOf, three levels deep. The value must satisfy exactly one
        // branch at every level for the outermost oneOf to pass.
        $schema = [
            'oneOf' => [
                [
                    'type' => 'integer',
                    'oneOf' => [
                        [
                            'oneOf' => [
                                ['minimum' => 100],
                                ['maximum' => -100],
                            ],
                        ],
                        ['multipleOf' => 7],
                    ],
                ],
                ['type' => 'string'],
            ],
        ];

        // 150 → integer, inner-inner minimum:100 matches (and not maximum:-100, and 150 % 7 != 0):
        // exactly one at each level.
        $this->assertSame([], $this->validator->validate('v', 150, $schema));
        // 5 → integer, but fails minimum:100 AND maximum:-100 AND multipleOf:7 → all inner fail.
        $this->assertNotEmpty($this->validator->validate('v', 5, $schema));
    }

    public function testOneOfNestedInsideAllOfInsideAnyOfValidatesRecursively(): void
    {
        // anyOf → allOf → oneOf composition on a numeric value.
        $schema = [
            'anyOf' => [
                [
                    'type' => 'integer',
                    'allOf' => [
                        ['minimum' => 0],
                        [
                            'oneOf' => [
                                ['maximum' => 10],
                                ['minimum' => 1000],
                            ],
                        ],
                    ],
                ],
                ['type' => 'string'],
            ],
        ];

        // 5 → integer, minimum:0 ok, and exactly one of (maximum:10 | minimum:1000) → pass.
        $this->assertSame([], $this->validator->validate('v', 5, $schema));
        // 50 → integer, minimum:0 ok, but neither maximum:10 nor minimum:1000 → inner oneOf fails
        // → allOf fails → integer branch fails → not a string either → anyOf fails.
        $this->assertNotEmpty($this->validator->validate('v', 50, $schema));
    }

    // =========================================================================
    // toIntOrNull ignores non-integer float constraints
    // =========================================================================

    public function testMinLengthWithFloatConstraintIsIgnored(): void
    {
        // minLength: 2.9 is invalid schema (must be integer) — constraint is skipped
        $errors = $this->validator->validate('s', 'ab', ['minLength' => 2.9]);
        $this->assertSame([], $errors);
    }

    public function testMaxItemsWithFloatConstraintIsIgnored(): void
    {
        $errors = $this->validator->validate('a', [1, 2, 3], ['maxItems' => 2.7]);
        $this->assertSame([], $errors);
    }

    // =========================================================================
    // dependentRequired
    // =========================================================================

    public function testDependentRequiredFieldPresentMissingDepReturnsError(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('billingAddress', $errors[0]);
        $this->assertStringContainsString('creditCard', $errors[0]);
    }

    public function testDependentRequiredFieldPresentDepAlsoPresentPasses(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234', 'billingAddress' => 'Main St'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentRequiredFieldAbsentDepNotRequired(): void
    {
        $errors = $this->validator->validate('obj', ['name' => 'Alice'], [
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentRequiredMultipleDepsReportsAllMissing(): void
    {
        $errors = $this->validator->validate('obj', ['creditCard' => '1234'], [
            'dependentRequired' => ['creditCard' => ['billingAddress', 'billingCity']],
        ]);
        $this->assertCount(2, $errors);
    }

    // =========================================================================
    // dependentSchemas
    // =========================================================================

    public function testDependentSchemasFieldPresentSchemaAppliedFails(): void
    {
        $errors = $this->validator->validate('obj', ['premium' => true, 'score' => 50], [
            'dependentSchemas' => [
                'premium' => ['properties' => ['score' => ['minimum' => 100]]],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('score', $errors[0]);
    }

    public function testDependentSchemasFieldAbsentSchemaNotApplied(): void
    {
        $errors = $this->validator->validate('obj', ['score' => 50], [
            'dependentSchemas' => [
                'premium' => ['properties' => ['score' => ['minimum' => 100]]],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testDependentSchemasFieldPresentSchemaSatisfiedPasses(): void
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

    public function testPrefixItemsValidTuplePasses(): void
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

    public function testPrefixItemsInvalidItemAtIndexFails(): void
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

    public function testPrefixItemsShorterArrayThanSchemaPasses(): void
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

    public function testPrefixItemsExtraItemsBeyondSchemaNotValidated(): void
    {
        $errors = $this->validator->validate('t', ['hello', 42, 'extra', 'more'], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPrefixItemsWithConstraintsOnItemsFails(): void
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

    public function testPrefixItemsWithItemsSuffixDoesNotApplyItemsToPrefixIndices(): void
    {
        // JSON Schema 2020-12 tuple-with-rest: prefixItems covers [0,1]; items (boolean)
        // applies only to index >= 2. The string/int at 0/1 must NOT be checked against
        // the boolean items schema, and the boolean at index 2 must pass.
        $errors = $this->validator->validate('t', ['hello', 42, true], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            'items' => ['type' => 'boolean'],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPrefixItemsWithItemsSuffixValidatesOnlySuffixIndices(): void
    {
        // Suffix element at index 2 violates the items (boolean) schema → exactly one error,
        // and it must reference index 2, not the prefix positions.
        $errors = $this->validator->validate('t', ['hello', 42, 'not-bool'], [
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            'items' => ['type' => 'boolean'],
        ]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('t.2', $errors[0]);
    }

    // =========================================================================
    // Numeric formats: int32 / int64 range
    // =========================================================================

    public function testInt32FormatAcceptsValueInRange(): void
    {
        $this->assertSame([], $this->validator->validate('f', 100, ['type' => 'integer', 'format' => 'int32']));
        $this->assertSame([], $this->validator->validate('f', 2147483647, ['type' => 'integer', 'format' => 'int32']));
        $this->assertSame([], $this->validator->validate('f', -2147483648, ['type' => 'integer', 'format' => 'int32']));
    }

    public function testInt32FormatRejectsOverflow(): void
    {
        $errors = $this->validator->validate('f', 2147483648, ['type' => 'integer', 'format' => 'int32']);
        $this->assertContains('f must be within int32 range (-2147483648 to 2147483647).', $errors);

        $errors = $this->validator->validate('f', -2147483649, ['type' => 'integer', 'format' => 'int32']);
        $this->assertContains('f must be within int32 range (-2147483648 to 2147483647).', $errors);
    }

    public function testInt32FormatRejectsFractionalValue(): void
    {
        $errors = $this->validator->validate('f', 2.5, ['type' => 'number', 'format' => 'int32']);
        $this->assertContains('f must be an integer (int32).', $errors);
    }

    public function testInt64FormatAcceptsNativeIntButRejectsFloatOverflow(): void
    {
        $this->assertSame([], $this->validator->validate('f', 9000000000, ['type' => 'integer', 'format' => 'int64']));

        $errors = $this->validator->validate('f', 1.0e30, ['type' => 'number', 'format' => 'int64']);
        $this->assertContains('f must be within int64 range (-9223372036854775808 to 9223372036854775807).', $errors);
    }

    public function testUint32FormatEnforcesBothBounds(): void
    {
        $this->assertSame([], $this->validator->validate('f', 4294967295, ['type' => 'integer', 'format' => 'uint32']));

        $this->assertContains(
            'f must be within uint32 range (0 to 4294967295).',
            $this->validator->validate('f', -1, ['type' => 'integer', 'format' => 'uint32']),
        );
        $this->assertContains(
            'f must be within uint32 range (0 to 4294967295).',
            $this->validator->validate('f', 4294967296, ['type' => 'integer', 'format' => 'uint32']),
        );
    }

    public function testUint64FormatEnforcesBothBounds(): void
    {
        $this->assertSame([], $this->validator->validate('f', 9223372036854775807, ['type' => 'integer', 'format' => 'uint64']));

        // 2^64-1 exceeds PHP_INT_MAX, so the upper bound is expressed (and reported) as a literal.
        $this->assertContains(
            'f must be within uint64 range (0 to 18446744073709551615).',
            $this->validator->validate('f', -1, ['type' => 'integer', 'format' => 'uint64']),
        );
        $this->assertContains(
            'f must be within uint64 range (0 to 18446744073709551615).',
            $this->validator->validate('f', 2.0e19, ['type' => 'number', 'format' => 'uint64']),
        );
        $this->assertContains(
            'f must be an integer (uint64).',
            $this->validator->validate('f', 1.5, ['type' => 'number', 'format' => 'uint64']),
        );
    }

    public function testFloatAndDoubleFormatsCarryNoExtraRange(): void
    {
        $this->assertSame([], $this->validator->validate('f', 1.5, ['type' => 'number', 'format' => 'float']));
        $this->assertSame([], $this->validator->validate('f', 1.5e300, ['type' => 'number', 'format' => 'double']));
    }

    // =========================================================================
    // UUID format: nil / max special cases
    // =========================================================================

    public function testUuidFormatAcceptsRegularV4(): void
    {
        $this->assertSame([], $this->validator->validate('f', '550e8400-e29b-41d4-a716-446655440000', ['format' => 'uuid']));
    }

    public function testUuidFormatAcceptsNilAndMaxUuid(): void
    {
        $this->assertSame([], $this->validator->validate('f', '00000000-0000-0000-0000-000000000000', ['format' => 'uuid']));
        $this->assertSame([], $this->validator->validate('f', 'ffffffff-ffff-ffff-ffff-ffffffffffff', ['format' => 'uuid']));
        $this->assertSame([], $this->validator->validate('f', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', ['format' => 'uuid']));
    }

    public function testUuidFormatRejectsGarbage(): void
    {
        $errors = $this->validator->validate('f', 'not-a-uuid', ['format' => 'uuid']);
        $this->assertContains('f must match format uuid.', $errors);
    }

    // =========================================================================
    // pattern: single-compile invalid vs no-match
    // =========================================================================

    public function testInvalidSchemaPatternReportsDistinctError(): void
    {
        // Unbalanced group → invalid regex pattern in schema.
        $errors = $this->validator->validate('f', 'abc', ['pattern' => '(']);
        $this->assertContains('f has invalid regex pattern in schema: (.', $errors);
    }

    public function testValidPatternNoMatchReportsMustMatch(): void
    {
        $errors = $this->validator->validate('f', 'abc', ['pattern' => '^[0-9]+$']);
        $this->assertContains('f must match pattern ^[0-9]+$.', $errors);
    }

    public function testInvalidUtf8SubjectBlamesInputNotSchema(): void
    {
        // preg_match with `u` returns false for invalid UTF-8 in the subject too
        // (e.g. raw bytes from a query param) — must not blame the schema pattern.
        $errors = $this->validator->validate('f', "\xFF\xFE", ['pattern' => '^[0-9]+$']);
        $this->assertContains('f contains invalid UTF-8 characters.', $errors);
        $this->assertNotContains('f has invalid regex pattern in schema: ^[0-9]+$', $errors);
    }

    public function testValidPatternMatchPasses(): void
    {
        $this->assertSame([], $this->validator->validate('f', '123', ['pattern' => '^[0-9]+$']));
    }

    // =========================================================================
    // Union branch selection: oneOf / anyOf incl. 3.1 type-array
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
        $this->assertContains('f matches more than one allowed oneOf branch.', $errors);
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

    // =========================================================================
    // format: time
    // =========================================================================

    public function testTimeFormatAcceptsValidTimes(): void
    {
        $this->assertSame([], $this->validator->validate('f', '23:59:59Z', ['format' => 'time']));
        $this->assertSame([], $this->validator->validate('f', '08:30:00+02:00', ['format' => 'time']));
        $this->assertSame([], $this->validator->validate('f', '08:30:00.123-05:00', ['format' => 'time']));
    }

    public function testTimeFormatRejectsInvalid(): void
    {
        // Missing offset, bad hour, not a time.
        $this->assertContains('f must match format time.', $this->validator->validate('f', '08:30:00', ['format' => 'time']));
        $this->assertContains('f must match format time.', $this->validator->validate('f', '24:00:00Z', ['format' => 'time']));
        $this->assertContains('f must match format time.', $this->validator->validate('f', 'noon', ['format' => 'time']));
    }

    // =========================================================================
    // patternProperties / propertyNames
    // =========================================================================

    public function testPatternPropertiesValidatesMatchingKeys(): void
    {
        $constraints = ['type' => 'object', 'patternProperties' => [
            '^x-' => ['type' => 'integer'],
        ]];
        $this->assertSame([], $this->validator->validate('f', ['x-count' => 5, 'other' => 'free'], $constraints));

        $errors = $this->validator->validate('f', ['x-count' => 'not-int'], $constraints);
        $this->assertContains('f.x-count must be of type integer.', $errors);
    }

    public function testPropertyNamesValidatesEveryKey(): void
    {
        $constraints = ['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$']];
        $this->assertSame([], $this->validator->validate('f', ['foo' => 1, 'bar' => 2], $constraints));

        $errors = $this->validator->validate('f', ['Foo1' => 1], $constraints);
        $this->assertContains('f key "Foo1" must match pattern ^[a-z]+$.', $errors);
    }

    public function testAdditionalPropertiesFalseAllowsPatternMatchedKeys(): void
    {
        // 'x-foo' matches patternProperties → not flagged as additional; 'other' is.
        $constraints = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'patternProperties' => ['^x-' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $this->assertSame([], $this->validator->validate('f', ['id' => 1, 'x-foo' => 'ok'], $constraints));

        $errors = $this->validator->validate('f', ['id' => 1, 'other' => 'no'], $constraints);
        $this->assertContains('f has additional property "other" which is not allowed.', $errors);
    }

    public function testAdditionalPropertiesFalseWithoutPropertiesRejectsAnyKey(): void
    {
        // Bare additionalProperties:false (no 'properties') must still reject every key.
        $errors = $this->validator->validate('o', ['bad' => 1], ['type' => 'object', 'additionalProperties' => false]);
        $this->assertContains('o has additional property "bad" which is not allowed.', $errors);
    }

    public function testAdditionalPropertiesFalseWithOnlyPatternPropertiesRejectsUnmatched(): void
    {
        $constraints = ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']], 'additionalProperties' => false];
        $this->assertSame([], $this->validator->validate('o', ['x-a' => 'ok'], $constraints));
        $this->assertContains(
            'o has additional property "bad" which is not allowed.',
            $this->validator->validate('o', ['bad' => 1], $constraints),
        );
    }

    public function testInt64FormatRejectsFloatBeyondBoundary(): void
    {
        // (float)PHP_INT_MAX rounds to 2^63 = PHP_INT_MAX + 1 — must be rejected.
        $errors = $this->validator->validate('f', 9223372036854775808.0, ['format' => 'int64']);
        $this->assertContains('f must be within int64 range (-9223372036854775808 to 9223372036854775807).', $errors);
    }

    public function testInt64FormatAcceptsLargeValidInteger(): void
    {
        $this->assertSame([], $this->validator->validate('f', 9000000000000000000, ['format' => 'int64']));
    }

    public function testDateTimeFormatRejectsRolloverCalendarDates(): void
    {
        // createFromFormat silently rolls Feb 30 → Mar 2; these must be rejected, not accepted.
        $this->assertContains('f must match format date-time.', $this->validator->validate('f', '2026-02-30T12:00:00Z', ['format' => 'date-time']));
        $this->assertContains('f must match format date-time.', $this->validator->validate('f', '2026-13-01T12:00:00Z', ['format' => 'date-time']));
        // A real date still validates.
        $this->assertSame([], $this->validator->validate('f', '2026-03-30T12:00:00Z', ['format' => 'date-time']));
    }

    public function testDateTimeFormatAcceptsMicrosecondPrecision(): void
    {
        // RFC3339 allows arbitrary fractional digits; previously only 1-3 (milliseconds) parsed.
        $this->assertSame([], $this->validator->validate('f', '2026-03-10T12:00:00.123456Z', ['format' => 'date-time']));
        $this->assertSame([], $this->validator->validate('f', '2026-03-10T12:00:00.123Z', ['format' => 'date-time']));
    }

    public function testDeeplyNestedSchemaIsRejectedNotStackOverflowed(): void
    {
        // 300 nested allOf levels would exhaust the stack without the depth guard.
        $constraints = ['minLength' => 1];
        for ($i = 0; $i < 300; $i++) {
            $constraints = ['allOf' => [$constraints]];
        }

        $errors = $this->validator->validate('f', 'x', $constraints);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('schema nesting exceeds 256 levels', implode(' | ', $errors));
    }

    public function testAllOfSkipsNonArrayBranchAndValidatesRest(): void
    {
        // A non-array allOf branch is skipped; array branches are enforced.
        $errors = $this->validator->validate('v', 3, ['allOf' => [['minimum' => 5], 'not-a-branch']]);
        $this->assertNotEmpty($errors);
    }

    public function testUnionSkipsNonArrayBranch(): void
    {
        // A non-array oneOf branch is skipped; the string branch still matches exactly once.
        $this->assertSame([], $this->validator->validate('v', 'x', ['oneOf' => ['not-a-branch', ['type' => 'string']]]));
    }

    public function testMatchesOpenApiTypeEmptyListPlacesNoConstraint(): void
    {
        // An empty `type` list matches any value (no constraint).
        $matches = new ReflectionMethod($this->validator, 'matchesOpenApiType');
        $this->assertTrue($matches->invoke($this->validator, 5, []));
        $this->assertTrue($matches->invoke($this->validator, 'x', []));
    }

    public function testObjectPropertiesAreValidatedRecursively(): void
    {
        $errors = $this->validator->validate('o', ['a' => 'not-int'], ['properties' => ['a' => ['type' => 'integer']]]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('o.a', $errors[0]);
    }

    public function testObjectRequiredPropertyMissingReported(): void
    {
        $errors = $this->validator->validate('o', ['a' => 1], ['required' => ['b']]);
        $this->assertContains('o.b is required.', $errors);
    }

    public function testObjectPatternPropertiesValidated(): void
    {
        $errors = $this->validator->validate(
            'o',
            ['x1' => 'not-int'],
            ['patternProperties' => ['^x' => ['type' => 'integer']]],
        );
        $this->assertNotEmpty($errors);
    }

    public function testUniqueItemsFallsBackToSerializeOnJsonException(): void
    {
        // A non-scalar item that cannot be JSON-encoded (contains INF) must not crash uniqueItems;
        // it falls back to serialize() for the fingerprint.
        $errors = $this->validator->validate('a', [['x' => INF]], ['type' => 'array', 'uniqueItems' => true]);
        $this->assertSame([], $errors);
    }

    public function testFormatDateAndDateTimeRejectEmptyString(): void
    {
        $this->assertNotEmpty($this->validator->validate('d', '', ['format' => 'date']));
        $this->assertNotEmpty($this->validator->validate('dt', '', ['format' => 'date-time']));
    }

    public function testFormatByteAcceptsEmptyString(): void
    {
        $this->assertSame([], $this->validator->validate('b', '', ['format' => 'byte']));
    }

    public function testMinimumInclusiveBoundary(): void
    {
        $c = ['minimum' => 10];
        $this->assertSame([], $this->validator->validate('v', 10, $c));       // exact boundary passes
        $this->assertSame([], $this->validator->validate('v', 11, $c));
        $this->assertNotEmpty($this->validator->validate('v', 9, $c));        // below fails
    }

    public function testExclusiveMinimumNumericStyle(): void
    {
        // OpenAPI 3.1: exclusiveMinimum as a number.
        $c = ['exclusiveMinimum' => 10];
        $this->assertNotEmpty($this->validator->validate('v', 10, $c));       // equal fails
        $this->assertSame([], $this->validator->validate('v', 10.0001, $c));  // above passes
    }

    public function testExclusiveMinimumBooleanStyle(): void
    {
        // OpenAPI 3.0: exclusiveMinimum boolean paired with minimum.
        $c = ['minimum' => 10, 'exclusiveMinimum' => true];
        $this->assertNotEmpty($this->validator->validate('v', 10, $c));       // equal fails
        $this->assertSame([], $this->validator->validate('v', 11, $c));
    }

    /**
     * JSON Schema 2020-12 §6.1.1: `integer` matches any NUMBER with a zero fractional part. A payload of
     * `{"v": 1.0}` decodes to a PHP float, and rejecting it — which this test used to demand — refused a
     * value the spec calls valid. A real fractional part is still not an integer.
     */
    public function testIntegerTypeAcceptsAnIntegralFloatButNotAFractionalOne(): void
    {
        $this->assertSame([], $this->validator->validate('v', 1.0, ['type' => 'integer']));
        $this->assertSame([], $this->validator->validate('v', -7.0, ['type' => 'integer']));
        $this->assertSame([], $this->validator->validate('v', 1, ['type' => 'integer']));

        $this->assertNotEmpty($this->validator->validate('v', 1.5, ['type' => 'integer']));
        $this->assertNotEmpty($this->validator->validate('v', INF, ['type' => 'integer']));
        $this->assertNotEmpty($this->validator->validate('v', NAN, ['type' => 'integer']));

        // number accepts both int and float.
        $this->assertSame([], $this->validator->validate('v', 1.5, ['type' => 'number']));
        $this->assertSame([], $this->validator->validate('v', 2, ['type' => 'number']));
    }

    public function testMultipleOfIntegerAndZeroGuard(): void
    {
        $this->assertSame([], $this->validator->validate('v', 9, ['multipleOf' => 3]));
        $this->assertNotEmpty($this->validator->validate('v', 10, ['multipleOf' => 3]));
        // multipleOf <= 0 is ignored (no division-by-zero, no error).
        $this->assertSame([], $this->validator->validate('v', 7, ['multipleOf' => 0]));
    }

    public function testStringLengthBoundaries(): void
    {
        $c = ['minLength' => 3, 'maxLength' => 5];
        $this->assertSame([], $this->validator->validate('s', 'abc', $c));    // exact min
        $this->assertSame([], $this->validator->validate('s', 'abcde', $c));  // exact max
        $this->assertNotEmpty($this->validator->validate('s', 'ab', $c));     // below min
        $this->assertNotEmpty($this->validator->validate('s', 'abcdef', $c)); // above max
    }

    public function testStringLengthCountsUnicodeCharactersNotBytes(): void
    {
        // 'ñññ' is 3 characters but 6 bytes; mb_strlen must count 3.
        $this->assertSame([], $this->validator->validate('s', 'ñññ', ['minLength' => 3, 'maxLength' => 3]));
        $this->assertNotEmpty($this->validator->validate('s', 'ñññ', ['minLength' => 4]));
    }

    public function testArrayItemCountBoundaries(): void
    {
        $c = ['type' => 'array', 'minItems' => 2, 'maxItems' => 3];
        $this->assertSame([], $this->validator->validate('a', [1, 2], $c));      // exact min
        $this->assertSame([], $this->validator->validate('a', [1, 2, 3], $c));   // exact max
        $this->assertNotEmpty($this->validator->validate('a', [1], $c));         // below min
        $this->assertNotEmpty($this->validator->validate('a', [1, 2, 3, 4], $c)); // above max
    }

    // ---- Cosmetic format sub-variants (characterization: locks current behavior) ----

    public function testFormatByteRejectsUrlSafeAlphabet(): void
    {
        // format: byte is standard base64 only; url-safe chars (-_) are rejected.
        $this->assertNotEmpty($this->validator->validate('b', 'ab-_cd==', ['format' => 'byte']));
    }

    public function testFormatEmailQuotedAndIpLiteralVariants(): void
    {
        // filter_var rejects a quoted-string local part but accepts an IP-address-literal domain.
        $this->assertNotEmpty($this->validator->validate('e', '"a b"@x.com', ['format' => 'email']));
        $this->assertSame([], $this->validator->validate('e', 'user@[1.2.3.4]', ['format' => 'email']));
    }

    public function testFormatIpv6ZoneIdAndV4Mapped(): void
    {
        // Zone id (%eth0) is rejected; an IPv4-mapped address is accepted.
        $this->assertNotEmpty($this->validator->validate('ip', 'fe80::1%eth0', ['format' => 'ipv6']));
        $this->assertSame([], $this->validator->validate('ip', '::ffff:1.2.3.4', ['format' => 'ipv6']));
    }

    public function testFormatHostnameTrailingDotAndPunycode(): void
    {
        $this->assertSame([], $this->validator->validate('h', 'example.com.', ['format' => 'hostname']));
        $this->assertSame([], $this->validator->validate('h', 'xn--e1afmkfd.xn--p1ai', ['format' => 'hostname']));
    }

    public function testFormatUuidRejectsUrnPrefix(): void
    {
        $this->assertNotEmpty($this->validator->validate(
            'u',
            'urn:uuid:12345678-1234-4234-8234-123456789012',
            ['format' => 'uuid'],
        ));
    }

    public function testFormatDateTimeLeapSecondAndLowercaseSeparatorsRejected(): void
    {
        // Leap second (:60) and lowercase t/z separators are rejected.
        $this->assertNotEmpty($this->validator->validate('t', '2026-06-30T23:59:60Z', ['format' => 'date-time']));
        $this->assertNotEmpty($this->validator->validate('t', '2026-03-10t12:00:00z', ['format' => 'date-time']));
    }

    public function testFormatDateTimeAcceptsExtremeYears(): void
    {
        $this->assertSame([], $this->validator->validate('t', '0000-01-01T00:00:00Z', ['format' => 'date-time']));
        $this->assertSame([], $this->validator->validate('t', '9999-12-31T23:59:59Z', ['format' => 'date-time']));
    }

    // ---- Cosmetic numeric / array edges ----

    public function testMinimumEqualsMaximumAllowsSingleValue(): void
    {
        $c = ['minimum' => 5, 'maximum' => 5];
        $this->assertSame([], $this->validator->validate('v', 5, $c));
        $this->assertNotEmpty($this->validator->validate('v', 6, $c));
        $this->assertNotEmpty($this->validator->validate('v', 4, $c));
    }

    public function testMultipleOfDetectsTinyOffset(): void
    {
        // 0.30000001 is not a multiple of 0.1 (beyond the 1e-9 tolerance).
        $this->assertNotEmpty($this->validator->validate('v', 0.30000001, ['multipleOf' => 0.1]));
    }

    /**
     * `uniqueItems` compares objects the way JSON Schema defines equality: by content, not by key order.
     *
     * This test asserted the OPPOSITE until 2.15.18 — "object fingerprints are order-sensitive" — and it
     * was codifying an implementation detail rather than a rule. The fingerprint was `json_encode()` of
     * the item as it arrived, so `{"a":1,"b":2}` and `{"b":2,"a":1}` produced two different strings and
     * the duplicate went unreported. Key order is not part of an object's identity; the items are
     * canonicalized before the comparison now, and the list case below is the half that proves the
     * canonicalization did not overshoot — for a LIST, order IS the value.
     */
    public function testUniqueItemsComparesObjectsByContentNotKeyOrder(): void
    {
        $this->assertNotEmpty(
            $this->validator->validate(
                'a',
                [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
                ['type' => 'array', 'uniqueItems' => true],
            ),
            'the same object written in another key order is the same item',
        );

        $this->assertNotEmpty(
            $this->validator->validate(
                'a',
                [['o' => ['a' => 1, 'b' => 2]], ['o' => ['b' => 2, 'a' => 1]]],
                ['type' => 'array', 'uniqueItems' => true],
            ),
            'and that holds however deep the object sits',
        );

        $this->assertSame(
            [],
            $this->validator->validate('a', [[1, 2], [2, 1]], ['type' => 'array', 'uniqueItems' => true]),
            'two lists in different order are two different values',
        );
    }

    public function testContainsWithMinContainsZeroPassesWithoutMatch(): void
    {
        // minContains: 0 means "no minimum" — a value with zero matching items still passes.
        $this->assertSame([], $this->validator->validate(
            'a',
            [1, 2],
            ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 0],
        ));
    }

    public function testPrefixItemsLongerThanValueValidatesPresentPositionsOnly(): void
    {
        // Fewer items than prefixItems: only the present positions are checked.
        $this->assertSame([], $this->validator->validate(
            'a',
            [1],
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'integer']]],
        ));
    }

    public function testBinaryFormatReportsActualTypeName(): void
    {
        $cases = [
            [5, 'int'],
            [1.5, 'float'],
            [true, 'bool'],
            [[1], 'array'],
            [(object)[], 'object'],
        ];
        foreach ($cases as [$value, $typeName]) {
            $errors = $this->validator->validate('f', $value, ['format' => 'binary']);
            $this->assertNotEmpty($errors);
            $this->assertStringContainsString("got {$typeName}", $errors[0]);
        }
    }

    // =========================================================================
    // unevaluatedProperties (JSON Schema 2019-09/2020-12)
    // =========================================================================

    public function testUnevaluatedPropertiesFalseWithAllOfAcceptsCombinedProperties(): void
    {
        // The canonical case additionalProperties cannot express: name/age live in
        // separate allOf branches, yet both count as evaluated.
        $constraints = [
            'allOf' => [
                ['properties' => ['name' => ['type' => 'string']]],
                ['properties' => ['age' => ['type' => 'integer']]],
            ],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['name' => 'Bob', 'age' => 30], $constraints));
    }

    public function testUnevaluatedPropertiesFalseRejectsKeyNotCoveredByAnyBranch(): void
    {
        $constraints = [
            'allOf' => [
                ['properties' => ['name' => ['type' => 'string']]],
                ['properties' => ['age' => ['type' => 'integer']]],
            ],
            'unevaluatedProperties' => false,
        ];

        $errors = $this->validator->validate('o', ['name' => 'Bob', 'age' => 30, 'extra' => 1], $constraints);
        $this->assertContains('o has unevaluated property "extra" which is not allowed.', $errors);
    }

    public function testUnevaluatedPropertiesFalseWithOwnProperties(): void
    {
        $constraints = [
            'properties' => ['id' => ['type' => 'integer']],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['id' => 1], $constraints));
        $this->assertContains(
            'o has unevaluated property "nope" which is not allowed.',
            $this->validator->validate('o', ['id' => 1, 'nope' => 2], $constraints),
        );
    }

    public function testUnevaluatedPropertiesFalseCountsPatternProperties(): void
    {
        $constraints = [
            'patternProperties' => ['^x_' => ['type' => 'string']],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['x_a' => 'v', 'x_b' => 'w'], $constraints));
        $this->assertContains(
            'o has unevaluated property "y" which is not allowed.',
            $this->validator->validate('o', ['x_a' => 'v', 'y' => 'z'], $constraints),
        );
    }

    public function testUnevaluatedPropertiesFalseCountsAdditionalPropertiesSchema(): void
    {
        // additionalProperties as a schema evaluates every remaining key → nothing is left
        // "unevaluated", so unevaluatedProperties: false never fires.
        $constraints = [
            'properties' => ['id' => ['type' => 'integer']],
            'additionalProperties' => ['type' => 'string'],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['id' => 1, 'extra' => 'ok'], $constraints));
    }

    public function testUnevaluatedPropertiesSchemaValidatesLeftovers(): void
    {
        // unevaluatedProperties as a schema: leftover keys must satisfy it.
        $constraints = [
            'properties' => ['id' => ['type' => 'integer']],
            'unevaluatedProperties' => ['type' => 'string'],
        ];

        $this->assertSame([], $this->validator->validate('o', ['id' => 1, 'extra' => 'ok'], $constraints));
        $this->assertContains(
            'o.extra must be of type string.',
            $this->validator->validate('o', ['id' => 1, 'extra' => 99], $constraints),
        );
    }

    public function testUnevaluatedPropertiesTrueIsNoOp(): void
    {
        $constraints = [
            'properties' => ['id' => ['type' => 'integer']],
            'unevaluatedProperties' => true,
        ];

        $this->assertSame([], $this->validator->validate('o', ['id' => 1, 'whatever' => [1, 2, 3]], $constraints));
    }

    public function testUnevaluatedPropertiesCountsPassingIfThenBranch(): void
    {
        // When `if` matches, both `if` and `then` properties count as evaluated.
        $constraints = [
            'properties' => ['kind' => ['type' => 'string']],
            'if' => ['properties' => ['kind' => ['const' => 'a']]],
            'then' => ['properties' => ['aValue' => ['type' => 'integer']]],
            'else' => ['properties' => ['bValue' => ['type' => 'integer']]],
            'unevaluatedProperties' => false,
        ];

        // if matches (kind=a) → aValue is evaluated, bValue is not.
        $this->assertSame([], $this->validator->validate('o', ['kind' => 'a', 'aValue' => 1], $constraints));
        $this->assertContains(
            'o has unevaluated property "bValue" which is not allowed.',
            $this->validator->validate('o', ['kind' => 'a', 'bValue' => 1], $constraints),
        );

        // if fails (kind=b) → else branch applies, bValue is evaluated.
        $this->assertSame([], $this->validator->validate('o', ['kind' => 'b', 'bValue' => 1], $constraints));
    }

    public function testUnevaluatedPropertiesIgnoresFailingAnyOfBranch(): void
    {
        // Only a *passing* anyOf branch contributes evaluated keys. Here the value has
        // extra=5 (int); the string branch fails, so `extra` stays unevaluated.
        $constraints = [
            'anyOf' => [
                ['properties' => ['extra' => ['type' => 'string']]],
                ['properties' => ['other' => ['type' => 'integer']]],
            ],
            'unevaluatedProperties' => false,
        ];

        $errors = $this->validator->validate('o', ['other' => 1, 'extra' => 5], $constraints);
        $this->assertContains('o has unevaluated property "extra" which is not allowed.', $errors);
    }

    // =========================================================================
    // unevaluatedItems (JSON Schema 2019-09/2020-12)
    // =========================================================================

    public function testUnevaluatedItemsFalseWithPrefixItemsAcceptsExactTuple(): void
    {
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
            'unevaluatedItems' => false,
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1], $constraints));
    }

    public function testUnevaluatedItemsFalseRejectsExtraTailItem(): void
    {
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
            'unevaluatedItems' => false,
        ];

        $errors = $this->validator->validate('a', ['x', 1, 'extra'], $constraints);
        $this->assertContains('a has an unevaluated item at index 2 which is not allowed.', $errors);
    }

    public function testUnevaluatedItemsFalseWithItemsSuffixIsNoOp(): void
    {
        // `items` covers every index beyond the prefix → nothing is left unevaluated.
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => ['type' => 'integer'],
            'unevaluatedItems' => false,
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1, 2, 3], $constraints));
    }

    public function testUnevaluatedItemsSchemaValidatesLeftovers(): void
    {
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'unevaluatedItems' => ['type' => 'integer'],
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1, 2], $constraints));
        $this->assertContains(
            'a.1 must be of type integer.',
            $this->validator->validate('a', ['x', 'not-int'], $constraints),
        );
    }

    public function testUnevaluatedItemsFalseWithAllOfPrefixBranch(): void
    {
        // prefixItems lives in an allOf branch; unevaluatedItems still sees it.
        $constraints = [
            'type' => 'array',
            'allOf' => [
                ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
            ],
            'unevaluatedItems' => false,
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1], $constraints));
        $this->assertContains(
            'a has an unevaluated item at index 2 which is not allowed.',
            $this->validator->validate('a', ['x', 1, true], $constraints),
        );
    }

    public function testUnevaluatedItemsCountsContainsMatches(): void
    {
        // `contains` evaluates only matching indices; a non-matching leftover is rejected.
        $constraints = [
            'type' => 'array',
            'contains' => ['type' => 'integer'],
            'unevaluatedItems' => false,
        ];

        // All ints → all evaluated by contains.
        $this->assertSame([], $this->validator->validate('a', [1, 2, 3], $constraints));

        // 'str' matches neither contains nor anything else → unevaluated.
        $this->assertContains(
            'a has an unevaluated item at index 1 which is not allowed.',
            $this->validator->validate('a', [1, 'str'], $constraints),
        );
    }

    public function testUnevaluatedItemsTrueIsNoOp(): void
    {
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'unevaluatedItems' => true,
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1, 2, 3], $constraints));
    }

    // =========================================================================
    // unevaluated* — recursive composition (allOf/anyOf/oneOf nested N-deep)
    // =========================================================================

    public function testUnevaluatedPropertiesSeesNestedAllOf(): void
    {
        // allOf inside allOf: `a` is defined two levels down, `b` one level down.
        $constraints = [
            'allOf' => [
                [
                    'allOf' => [
                        ['properties' => ['a' => ['type' => 'string']]],
                    ],
                ],
                ['properties' => ['b' => ['type' => 'integer']]],
            ],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['a' => 'x', 'b' => 1], $constraints));
        $this->assertContains(
            'o has unevaluated property "c" which is not allowed.',
            $this->validator->validate('o', ['a' => 'x', 'b' => 1, 'c' => true], $constraints),
        );
    }

    public function testUnevaluatedPropertiesSeesAnyOfNestedInsideAllOf(): void
    {
        // anyOf nested inside allOf: the passing anyOf branch (matching by value) contributes
        // its property as evaluated.
        $constraints = [
            'allOf' => [
                ['properties' => ['base' => ['type' => 'string']]],
                [
                    'anyOf' => [
                        ['properties' => ['x' => ['type' => 'string']], 'required' => ['x']],
                        ['properties' => ['y' => ['type' => 'integer']], 'required' => ['y']],
                    ],
                ],
            ],
            'unevaluatedProperties' => false,
        ];

        // y-branch passes (y present, int) → base + y evaluated.
        $this->assertSame([], $this->validator->validate('o', ['base' => 's', 'y' => 1], $constraints));

        // z not covered by any branch → unevaluated.
        $this->assertContains(
            'o has unevaluated property "z" which is not allowed.',
            $this->validator->validate('o', ['base' => 's', 'y' => 1, 'z' => 9], $constraints),
        );
    }

    public function testUnevaluatedPropertiesSeesPassingOneOfBranch(): void
    {
        // oneOf: exactly the one passing branch contributes evaluated keys.
        $constraints = [
            'oneOf' => [
                ['properties' => ['cat' => ['type' => 'string']], 'required' => ['cat']],
                ['properties' => ['dog' => ['type' => 'string']], 'required' => ['dog']],
            ],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame([], $this->validator->validate('o', ['dog' => 'rex'], $constraints));
        $this->assertContains(
            'o has unevaluated property "fish" which is not allowed.',
            $this->validator->validate('o', ['dog' => 'rex', 'fish' => 'nemo'], $constraints),
        );
    }

    public function testUnevaluatedPropertiesIgnoresNonMatchingOneOfBranch(): void
    {
        // The cat-branch does not apply (no `cat` key) → its `cat` property is NOT counted;
        // a stray `cat` key present without the branch matching stays unevaluated only if the
        // matching branch didn't already evaluate it. Here dog-branch matches and does not
        // cover `cat`, so `cat` is unevaluated.
        $constraints = [
            'oneOf' => [
                ['properties' => ['cat' => ['type' => 'string']], 'required' => ['dog'], 'additionalProperties' => false],
                ['properties' => ['dog' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['dog']],
            ],
            'unevaluatedProperties' => false,
        ];

        // dog-branch matches (has dog+note), cat-branch fails → only dog,note evaluated.
        $this->assertSame([], $this->validator->validate('o', ['dog' => 'rex', 'note' => 'hi'], $constraints));
    }

    public function testUnevaluatedPropertiesSeesDependentSchemas(): void
    {
        // dependentSchemas: when the trigger key is present, its schema's properties count.
        $constraints = [
            'properties' => ['creditCard' => ['type' => 'string']],
            'dependentSchemas' => [
                'creditCard' => ['properties' => ['billingAddress' => ['type' => 'string']]],
            ],
            'unevaluatedProperties' => false,
        ];

        // creditCard present → billingAddress evaluated via dependentSchemas.
        $this->assertSame(
            [],
            $this->validator->validate('o', ['creditCard' => '4111', 'billingAddress' => 'Main St'], $constraints),
        );

        // Without the trigger, billingAddress is not evaluated → rejected.
        $this->assertContains(
            'o has unevaluated property "billingAddress" which is not allowed.',
            $this->validator->validate('o', ['billingAddress' => 'Main St'], $constraints),
        );
    }

    public function testUnevaluatedItemsSeesNestedAllOfPrefixItems(): void
    {
        // prefixItems nested two allOf levels deep is still seen by unevaluatedItems.
        $constraints = [
            'type' => 'array',
            'allOf' => [
                [
                    'allOf' => [
                        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
                    ],
                ],
            ],
            'unevaluatedItems' => false,
        ];

        $this->assertSame([], $this->validator->validate('a', ['x', 1], $constraints));
        $this->assertContains(
            'a has an unevaluated item at index 2 which is not allowed.',
            $this->validator->validate('a', ['x', 1, 'extra'], $constraints),
        );
    }

    public function testUnevaluatedItemsSeesItemsInsidePassingAnyOfBranch(): void
    {
        // A passing anyOf branch supplying an `items` suffix evaluates the tail positions.
        $constraints = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'anyOf' => [
                ['prefixItems' => [['type' => 'string']], 'items' => ['type' => 'integer']],
                ['prefixItems' => [['type' => 'string']], 'items' => ['type' => 'boolean']],
            ],
            'unevaluatedItems' => false,
        ];

        // Tail ints → first anyOf branch passes → all tail evaluated.
        $this->assertSame([], $this->validator->validate('a', ['x', 1, 2, 3], $constraints));

        // Tail bools → second branch passes.
        $this->assertSame([], $this->validator->validate('a', ['x', true, false], $constraints));
    }

    // =========================================================================
    // Additional string formats (uri-reference / iri-reference / uri-template /
    // relative-json-pointer / idn-hostname)
    // =========================================================================

    public function testFormatUriReferenceAcceptsAbsoluteAndRelative(): void
    {
        foreach (['http://a.com/x', '/path?x=1', '../rel/path', '', 'foo/bar#frag'] as $ref) {
            $this->assertSame(
                [],
                $this->validator->validate('u', $ref, ['type' => 'string', 'format' => 'uri-reference']),
                "expected '{$ref}' to be a valid uri-reference",
            );
        }
    }

    public function testFormatUriReferenceRejectsWhitespaceAndControl(): void
    {
        $this->assertNotEmpty(
            $this->validator->validate('u', 'has space', ['type' => 'string', 'format' => 'uri-reference']),
        );
        $this->assertNotEmpty(
            $this->validator->validate('u', "tab\there", ['type' => 'string', 'format' => 'uri-reference']),
        );
    }

    /**
     * `idn-email` accepts an internationalized DOMAIN, which is most of what the format is for.
     *
     * PHP's `FILTER_FLAG_EMAIL_UNICODE` permits Unicode in the local part and then validates what
     * follows the `@` as an ASCII host, so `ф@example.com` passed while `a@пример.рф` — an address
     * whose whole point is the internationalized domain — was refused. The domain now goes to the
     * same RFC 5890 check `idn-hostname` uses, which works with or without the intl extension.
     *
     * Everything the filter already accepted is accepted first and unchanged, so the forms this
     * method does not model — a bracketed IP domain, a quoted local part — cannot regress.
     */
    #[DataProvider('idnEmailProvider')]
    public function testIdnEmailAcceptsInternationalizedDomains(string $value, bool $expectValid): void
    {
        $errors = $this->validator->validate('f', $value, ['type' => 'string', 'format' => 'idn-email']);

        if ($expectValid) {
            $this->assertSame([], $errors, $value);

            return;
        }

        $this->assertNotEmpty($errors, $value);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function idnEmailProvider(): array
    {
        return [
            'ascii' => ['a@b.co', true],
            'unicode local and domain' => ['ф@пример.рф', true],
            'unicode domain only' => ['a@пример.рф', true],
            'unicode local only' => ['ф@example.com', true],
            'unicode subdomain' => ['a.b+c@sub.пример.рф', true],
            'already punycode' => ['a@xn--e1afmkfd.xn--p1ai', true],
            // Not modelled by the IDN path — kept valid by the untouched filter that runs first.
            'bracketed ip domain' => ['a@[192.168.0.1]', true],
            'double at' => ['a@@b', false],
            'no domain' => ['a@', false],
            'no local part' => ['@b.co', false],
            'no at sign' => ['nope', false],
            'domain label with leading hyphen' => ['a@-bad-.рф', false],
            'space in local part' => ['a b@пример.рф', false],
            'space in domain' => ['a@пример .рф', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Plain `email` stays ASCII-only, which is the difference between the two formats.
     *
     * A document that writes `format: email` and gets Unicode accepted has lost the distinction it
     * asked for, so this is pinned from the other side of the same change.
     */
    public function testPlainEmailStaysAsciiOnly(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate('f', 'a@b.co', ['type' => 'string', 'format' => 'email']),
        );
        $this->assertNotEmpty(
            $this->validator->validate('f', 'a@пример.рф', ['type' => 'string', 'format' => 'email']),
            'an internationalized domain is not a plain email',
        );
        $this->assertNotEmpty(
            $this->validator->validate('f', 'ф@example.com', ['type' => 'string', 'format' => 'email']),
            'nor is a Unicode local part',
        );
    }

    /**
     * `if`/`then`/`else` contribute to the unevaluated bookkeeping, and only when they apply.
     *
     * `unevaluatedItems` counts what the schema OWNED, and a conditional owns whatever the branch it
     * took owned: `if` when it succeeds (a failed `if` produces no annotations at all), then `then`
     * or `else`. This walk had no test — the whole `if` block of `collectEvaluatedItems()` was
     * unexecuted — so all four combinations are pinned here.
     */
    public function testUnevaluatedItemsCountsWhatIfThenElseEvaluated(): void
    {
        $ifThen = [
            'type' => 'array',
            'if' => ['prefixItems' => [['const' => 'tag']]],
            'then' => ['prefixItems' => [['const' => 'tag'], ['type' => 'string']]],
            'unevaluatedItems' => false,
        ];

        $this->assertSame(
            [],
            $this->validator->validate('a', ['tag', 'x'], $ifThen),
            'if applies, so both positions its then declared are evaluated',
        );
        $this->assertNotEmpty(
            $this->validator->validate('a', ['tag', 'x', 'extra'], $ifThen),
            'the third position is nobody\'s, so unevaluatedItems refuses it',
        );
        $this->assertNotEmpty(
            $this->validator->validate('a', ['other'], $ifThen),
            'if failed, so it evaluated nothing — not even the position it looked at',
        );

        $ifElse = [
            'type' => 'array',
            'if' => ['prefixItems' => [['const' => 'tag']]],
            'else' => ['prefixItems' => [['type' => 'integer']]],
            'unevaluatedItems' => false,
        ];

        $this->assertSame(
            [],
            $this->validator->validate('a', [7], $ifElse),
            'if failed, so else applies and owns position 0',
        );
        $this->assertNotEmpty(
            $this->validator->validate('a', [7, 8], $ifElse),
            'else declared one position, so the second is unevaluated',
        );
    }

    /**
     * The same bookkeeping for properties, so the object half of the walk is measured too.
     */
    public function testUnevaluatedPropertiesCountsWhatIfThenEvaluated(): void
    {
        $constraints = [
            'type' => 'object',
            'if' => ['properties' => ['kind' => ['const' => 'a']], 'required' => ['kind']],
            'then' => ['properties' => ['extra' => ['type' => 'string']]],
            'unevaluatedProperties' => false,
        ];

        $this->assertSame(
            [],
            $this->validator->validate('a', ['kind' => 'a', 'extra' => 's'], $constraints),
            'if owns kind, then owns extra',
        );
        $this->assertNotEmpty(
            $this->validator->validate('a', ['kind' => 'a', 'nope' => 1], $constraints),
            'a key neither branch declared is unevaluated',
        );
    }

    /**
     * `true` and `false` stand where a schema stands, and both now mean what JSON Schema says.
     *
     * `true` is the empty schema and constrains nothing; `false` is satisfied by no value at all.
     * Every reader here tested `is_array()` first, so a boolean was silently DROPPED and the keyword
     * carrying it did nothing: `items: false` failed to close a `prefixItems` tuple, `properties: {x:
     * false}` failed to forbid `x`, and `anyOf: [false, true]` refused a value its `true` branch
     * accepts — a boolean read in the STRICT direction, which is the worse half.
     *
     * `additionalProperties` and `unevaluated*` are absent from this list on purpose: the boolean is
     * their ordinary spelling and they always read it.
     *
     * @param array<string, mixed> $constraints
     */
    #[DataProvider('booleanSubschemaProvider')]
    public function testABooleanStandsForTheSchemaItIsShorthandFor(
        string $label,
        array $constraints,
        mixed $value,
        bool $expectValid,
    ): void {
        $errors = $this->validator->validate('field', $value, $constraints);

        if ($expectValid) {
            $this->assertSame([], $errors, $label);

            return;
        }

        $this->assertNotEmpty($errors, $label);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, mixed, bool}>
     */
    public static function booleanSubschemaProvider(): array
    {
        $tuple = ['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'integer']]];

        return [
            // items — the one that shows up in real documents: closing a tuple.
            'items false closes the tuple' => ['an item past prefixItems is refused', $tuple + ['items' => false], ['a', 1, 'extra'], false],
            'items false at exact arity' => ['the declared positions still pass', $tuple + ['items' => false], ['a', 1], true],
            'items false short tuple' => ['fewer items than prefixItems is not an extra item', $tuple + ['items' => false], ['a'], true],
            'items false alone' => ['with no prefixItems the array must be empty', ['type' => 'array', 'items' => false], [1], false],
            'items false alone, empty' => ['and the empty array satisfies it', ['type' => 'array', 'items' => false], [], true],
            'items true' => ['items: true constrains nothing', ['type' => 'array', 'items' => true], ['a', 1], true],

            // the other applicators, same rule
            'contains false' => ['no value can match, so nothing is contained', ['type' => 'array', 'contains' => false], [1], false],
            'contains true, empty' => ['every value matches, but there is none to match', ['type' => 'array', 'contains' => true], [], false],
            'contains true, non-empty' => ['and one item is enough', ['type' => 'array', 'contains' => true], [1], true],
            'not true' => ['not of "everything" leaves nothing', ['not' => true], 1, false],
            'not false' => ['not of "nothing" leaves everything', ['not' => false], 1, true],
            'properties false, present' => ['a property forbidden by false is refused', ['type' => 'object', 'properties' => ['x' => false]], ['x' => 1], false],
            'properties false, absent' => ['and not required to be there', ['type' => 'object', 'properties' => ['x' => false]], ['y' => 1], true],
            'properties true' => ['properties: {x: true} constrains nothing', ['type' => 'object', 'properties' => ['x' => true]], ['x' => 1], true],
            'patternProperties false' => ['a matching key is refused', ['type' => 'object', 'patternProperties' => ['^x' => false]], ['xy' => 1], false],
            'propertyNames false' => ['no key can be valid, so no key may exist', ['type' => 'object', 'propertyNames' => false], ['a' => 1], false],
            'propertyNames false, empty' => ['the empty object has no key to refuse', ['type' => 'object', 'propertyNames' => false], [], true],
            'allOf false' => ['one unsatisfiable branch fails the whole', ['allOf' => [false]], 1, false],
            'allOf true' => ['a true branch adds nothing', ['allOf' => [true]], 1, true],
            'anyOf false true' => ['the true branch matches, so the value is accepted', ['anyOf' => [false, true]], 1, true],
            'anyOf all false' => ['no branch can match', ['anyOf' => [false, false]], 1, false],
            'oneOf one true' => ['exactly one branch matches', ['oneOf' => [false, true]], 1, true],
            'if true then applies' => ['if: true always applies, so then is enforced', ['type' => 'object', 'if' => true, 'then' => ['required' => ['r']]], [], false],
            'if false else applies' => ['if: false never applies, so else is enforced', ['type' => 'object', 'if' => false, 'else' => ['required' => ['e']]], [], false],
            'dependentSchemas false' => ['the trigger key makes an unsatisfiable schema apply', ['type' => 'object', 'dependentSchemas' => ['a' => false]], ['a' => 1], false],
            'dependentSchemas false, no trigger' => ['without the key it does not apply', ['type' => 'object', 'dependentSchemas' => ['a' => false]], ['b' => 1], true],
        ];
    }

    /**
     * The refusal says what the document said, and the document said `false`, not `not`.
     *
     * `false` has no array spelling, so it is rewritten as `not` of the empty schema — an internal
     * reduction the reader never wrote and must not be told about. Pinned because the obvious
     * implementation leaks it as "must not match the 'not' schema".
     */
    public function testAFalseSchemaRefusesWithoutMentioningNot(): void
    {
        $errors = $this->validator->validate(
            'field',
            ['x' => 1],
            ['type' => 'object', 'properties' => ['x' => false]],
        );

        $this->assertSame(['field.x is not allowed by the schema.'], $errors);

        $this->assertSame(
            ["field must not match the 'not' schema."],
            $this->validator->validate('field', 1, ['not' => ['type' => 'integer']]),
            'a real `not` keeps its own sentence',
        );
    }

    /**
     * `oneOf` says WHICH way it failed, and two matches is not "no match".
     *
     * Boolean branches are the shortest way to state the case: `[true, true]` matches twice, and the
     * message used to read "does not match any oneOf branch" — the opposite of what happened. Fixed
     * as a side effect of reading the booleans at all, and pinned so it stays fixed.
     */
    public function testOneOfDistinguishesTwoMatchesFromNone(): void
    {
        $this->assertSame(
            ['field matches more than one allowed oneOf branch.'],
            $this->validator->validate('field', 1, ['oneOf' => [true, true]]),
        );

        // Not the generic sentence: when a branch is applicable and fails, its OWN reason is what
        // the reader gets, and `false` refuses in its own words. The generic one is reserved for a
        // value no branch could even apply to.
        $this->assertSame(
            ['field is not allowed by the schema.'],
            $this->validator->validate('field', 1, ['oneOf' => [false, false]]),
        );

        $this->assertSame(
            ['field does not match any oneOf branch (expected string or boolean, got integer).'],
            $this->validator->validate('field', 1, ['oneOf' => [['type' => 'string'], ['type' => 'boolean']]]),
            'a value outside every declared type gets the no-match sentence, with the types named',
        );
    }

    /**
     * An EMPTY schema is not an absent one — `{}` matches every value.
     *
     * `{}` decodes to an empty PHP array, and three guards read that as "the keyword is not there":
     * `items: {}` marked no index as evaluated, so `unevaluatedItems: false` cut a valid array;
     * `contains: {}` found no match, so `minContains` could not be satisfied; `additionalProperties: {}`
     * left extra keys unevaluated for `unevaluatedProperties: false` to reject. All three measured
     * before the guards came out.
     */
    public function testAnEmptySchemaMatchesEveryValue(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate('a', [1, 2], ['type' => 'array', 'items' => [], 'unevaluatedItems' => false]),
            'items: {} evaluates every index',
        );

        $this->assertNotEmpty(
            $this->validator->validate('a', [], ['type' => 'array', 'contains' => [], 'minContains' => 1]),
            'contains: {} needs an item to match, and an empty array has none',
        );
        $this->assertSame(
            [],
            $this->validator->validate('a', [1], ['type' => 'array', 'contains' => [], 'minContains' => 1]),
            'and one item is enough',
        );

        $this->assertSame(
            [],
            $this->validator->validate(
                'o',
                ['x' => 1],
                ['type' => 'object', 'additionalProperties' => [], 'unevaluatedProperties' => false],
            ),
            'additionalProperties: {} evaluates every extra key',
        );
    }

    /**
     * An integer bound holds past 2^53, where a float stops telling neighbours apart.
     *
     * Every value used to be cast to float before the comparison, and `9007199254740992` and
     * `9007199254740993` are ONE float — so `maximum: 9007199254740992` accepted the value above it.
     * The comparison stays on integers while both sides are integers; the float path is unchanged, and
     * the mixed cases below are what says so.
     */
    public function testIntegerBoundsAreExactBeyondFloatPrecision(): void
    {
        $this->assertNotEmpty(
            $this->validator->validate('f', 9007199254740993, ['type' => 'integer', 'maximum' => 9007199254740992]),
        );
        $this->assertSame(
            [],
            $this->validator->validate('f', 9007199254740992, ['type' => 'integer', 'maximum' => 9007199254740992]),
        );
        $this->assertNotEmpty(
            $this->validator->validate('f', 9007199254740992, ['type' => 'integer', 'minimum' => 9007199254740993]),
        );
        $this->assertNotEmpty(
            $this->validator->validate(
                'f',
                9007199254740993,
                ['type' => 'integer', 'exclusiveMaximum' => 9007199254740993],
            ),
        );

        // Floats still behave as floats.
        $this->assertNotEmpty($this->validator->validate('f', 10.5, ['type' => 'number', 'maximum' => 10]));
        $this->assertSame([], $this->validator->validate('f', 0.5, ['type' => 'number', 'minimum' => 0.5]));
    }

    /**
     * `format: uri` accepts a URI, which is more than a URL.
     *
     * `FILTER_VALIDATE_URL` knows only the authority-based shapes, so `urn:isbn:0451450523` and
     * `urn:uuid:…` — valid URIs under RFC 3986 and ordinary identifiers in a real document — were
     * refused. The scheme-only branch added for them stops at `//`: an authority-based URI is still
     * judged by the filter, so `http://[` stays refused rather than sneaking in through the new door.
     */
    public function testFormatUriAcceptsSchemeOnlyUrisAndStillRefusesBrokenAuthorities(): void
    {
        foreach (['urn:isbn:0451450523', 'urn:uuid:12345678-1234-1234-1234-123456789abc', 'mailto:a@b.test', 'tel:+1-816-555-1212'] as $uri) {
            $this->assertSame(
                [],
                $this->validator->validate('u', $uri, ['type' => 'string', 'format' => 'uri']),
                $uri,
            );
        }

        foreach (['http://[', 'not a uri', '/relative/only', 'nocolon'] as $notAUri) {
            $this->assertNotEmpty(
                $this->validator->validate('u', $notAUri, ['type' => 'string', 'format' => 'uri']),
                $notAUri,
            );
        }
    }

    /**
     * Where the `uri-reference` check stops, stated as a test so the README line cannot drift from it.
     *
     * A reference may be RELATIVE, so most of what reads like garbage is legal and any conforming
     * validator accepts it. What this check does not do is parse the grammar: a broken percent-escape
     * or a malformed host passes here while the stricter `uri` refuses both. That asymmetry is the
     * documented behaviour, not an oversight, and it is pinned from both sides.
     */
    public function testFormatUriReferenceIsNoStricterThanWhitespaceAndControlCharacters(): void
    {
        foreach (['not_a_uri', '###', '%zz', 'http://['] as $accepted) {
            $this->assertSame(
                [],
                $this->validator->validate('u', $accepted, ['type' => 'string', 'format' => 'uri-reference']),
                sprintf("'%s' is accepted as a uri-reference", $accepted),
            );
            $this->assertNotEmpty(
                $this->validator->validate('u', $accepted, ['type' => 'string', 'format' => 'uri']),
                sprintf("'%s' is refused as a uri", $accepted),
            );
        }
    }

    public function testFormatIriReferenceAcceptsUnicode(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate('u', '/café/münchen', ['type' => 'string', 'format' => 'iri-reference']),
        );
    }

    public function testFormatUriTemplateAcceptsWellFormedExpressions(): void
    {
        foreach (['http://x.com/{id}', '/users/{id}/posts{?page,limit}', '/a/{var:3}', 'no/braces/here'] as $tpl) {
            $this->assertSame(
                [],
                $this->validator->validate('t', $tpl, ['type' => 'string', 'format' => 'uri-template']),
                "expected '{$tpl}' to be a valid uri-template",
            );
        }
    }

    public function testFormatUriTemplateRejectsMalformedBraces(): void
    {
        foreach (['open{brace', 'close}brace', '{}', 'x{ bad }'] as $tpl) {
            $this->assertNotEmpty(
                $this->validator->validate('t', $tpl, ['type' => 'string', 'format' => 'uri-template']),
                "expected '{$tpl}' to be rejected",
            );
        }
    }

    public function testFormatRelativeJsonPointer(): void
    {
        foreach (['0', '1/foo', '2#', '10/a/b'] as $ok) {
            $this->assertSame(
                [],
                $this->validator->validate('p', $ok, ['type' => 'string', 'format' => 'relative-json-pointer']),
                "expected '{$ok}' valid",
            );
        }
        foreach (['-1', '01', '#', '1.5', '/foo'] as $bad) {
            $this->assertNotEmpty(
                $this->validator->validate('p', $bad, ['type' => 'string', 'format' => 'relative-json-pointer']),
                "expected '{$bad}' invalid",
            );
        }
    }

    public function testFormatIdnHostname(): void
    {
        foreach (['münchen.de', 'example.com', 'a.b.c'] as $ok) {
            $this->assertSame(
                [],
                $this->validator->validate('h', $ok, ['type' => 'string', 'format' => 'idn-hostname']),
                "expected '{$ok}' valid",
            );
        }
        foreach (['', 'has space', '-bad.com', 'bad-.com'] as $bad) {
            $this->assertNotEmpty(
                $this->validator->validate('h', $bad, ['type' => 'string', 'format' => 'idn-hostname']),
                "expected '{$bad}' invalid",
            );
        }
    }

    // =========================================================================
    // content* (contentEncoding / contentMediaType / contentSchema)
    // =========================================================================

    public function testContentEncodingBase64(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate('c', base64_encode('hello'), ['contentEncoding' => 'base64']),
        );
        $this->assertContains(
            'c is not valid base64-encoded content.',
            $this->validator->validate('c', 'not valid base64 !!!', ['contentEncoding' => 'base64']),
        );
    }

    public function testContentEncodingBase16(): void
    {
        $this->assertSame([], $this->validator->validate('c', bin2hex('hi'), ['contentEncoding' => 'base16']));
        // Odd length / non-hex → invalid.
        $this->assertNotEmpty($this->validator->validate('c', 'abc', ['contentEncoding' => 'base16']));
        $this->assertNotEmpty($this->validator->validate('c', 'zz', ['contentEncoding' => 'base16']));
    }

    public function testContentEncodingUnknownIsLenient(): void
    {
        // base32 has no native codec → accepted leniently (annotation, not assertion).
        $this->assertSame([], $this->validator->validate('c', 'anything', ['contentEncoding' => 'base32']));
    }

    public function testContentMediaTypeJson(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate('c', '{"a":1}', ['contentMediaType' => 'application/json']),
        );
        $this->assertContains(
            'c is not valid application/json content.',
            $this->validator->validate('c', '{bad json', ['contentMediaType' => 'application/json']),
        );
    }

    public function testContentSchemaValidatesDecodedJson(): void
    {
        $constraints = [
            'contentMediaType' => 'application/json',
            'contentSchema' => [
                'type' => 'object',
                'required' => ['a'],
                'properties' => ['a' => ['type' => 'integer']],
            ],
        ];

        $this->assertSame([], $this->validator->validate('c', '{"a":1}', $constraints));
        // a is a string → contentSchema fails.
        $this->assertContains('c.a must be of type integer.', $this->validator->validate('c', '{"a":"x"}', $constraints));
    }

    public function testContentEncodingPlusMediaTypePlusSchema(): void
    {
        $constraints = [
            'contentEncoding' => 'base64',
            'contentMediaType' => 'application/json',
            'contentSchema' => [
                'type' => 'object',
                'required' => ['n'],
                'properties' => ['n' => ['type' => 'integer']],
            ],
        ];

        $this->assertSame([], $this->validator->validate('c', base64_encode('{"n":42}'), $constraints));
        $this->assertContains(
            'c.n must be of type integer.',
            $this->validator->validate('c', base64_encode('{"n":"nope"}'), $constraints),
        );
        // Bad base64 short-circuits before the JSON/schema checks.
        $this->assertContains(
            'c is not valid base64-encoded content.',
            $this->validator->validate('c', '!!!bad!!!', $constraints),
        );
    }

    /**
     * Object keywords are evaluated against a generated DTO by reading its `toArray()`. That call was
     * wrapped in `catch (Throwable)`, which silently turned ANY failure into "no object rules apply".
     * Only the documented one — an absent optional field, which the deserializer reports itself — may
     * be swallowed; a defect has to reach the caller.
     */
    public function testAbsentFieldInADtoValueSkipsObjectKeywordsQuietly(): void
    {
        $constraints = ['type' => 'object', 'required' => ['a'], 'minProperties' => 2];

        $this->assertSame([], $this->validator->validate('f', new NotProvidedDtoStub(), $constraints));
    }

    public function testADefectInsideADtoValueSurfacesInsteadOfSkippingValidation(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be of type array, null given');

        $this->validator->validate('f', new BrokenDtoStub(), ['type' => 'object', 'required' => ['a']]);
    }

    /**
     * A composition keyword INSIDE `items` must still be enforced on every element.
     *
     * `validateArray()` answers "does this item schema use composition?" once and hands the answer
     * to each element, because the schema is the same for all of them. If that answer misses a
     * keyword, the elements silently stop being checked against it — no error, no failure, just a
     * payload that should have been refused coming back valid. Mutation-tested: dropping `enum` or
     * `const` from that list left the whole suite green before these cases existed.
     *
     * @param array<string, mixed> $itemSchema
     * @param array<int, mixed> $accepted
     * @param array<int, mixed> $refused
     */
    #[DataProvider('itemCompositionProvider')]
    public function testCompositionInsideItemsIsEnforcedOnEveryElement(
        array $itemSchema,
        array $accepted,
        array $refused,
    ): void {
        $constraints = ['type' => 'array', 'items' => $itemSchema];

        self::assertSame(
            [],
            $this->validator->validate('f', $accepted, $constraints),
            'the accepted list must pass',
        );
        self::assertNotSame(
            [],
            $this->validator->validate('f', $refused, $constraints),
            'the refused list must not pass — the keyword was dropped for the elements',
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>, 2: array<int, mixed>}>
     */
    public static function itemCompositionProvider(): array
    {
        return [
            'enum' => [['enum' => ['a', 'b']], ['a', 'b'], ['a', 'zz']],
            'const' => [['const' => 7], [7, 7], [7, 8]],
            'oneOf' => [['oneOf' => [['type' => 'integer', 'minimum' => 5], ['type' => 'string']]], [9, 'x'], [9, 1]],
            'anyOf' => [['anyOf' => [['type' => 'boolean'], ['type' => 'integer', 'maximum' => 3]]], [true, 2], [true, 9]],
            'allOf' => [['allOf' => [['type' => 'integer'], ['minimum' => 3]]], [3, 4], [3, 1]],
            'not' => [['not' => ['const' => 'bad']], ['ok', 'fine'], ['ok', 'bad']],
            'if' => [['if' => ['type' => 'integer'], 'then' => ['minimum' => 10]], [10, 'x'], [10, 5]],
        ];
    }

    /**
     * The same for `contains`, which also applies ONE schema to every element and got the same
     * hoisted answer.
     */
    public function testCompositionInsideContainsIsEnforced(): void
    {
        $constraints = ['type' => 'array', 'contains' => ['enum' => ['hit']]];

        self::assertSame([], $this->validator->validate('f', ['miss', 'hit'], $constraints));
        self::assertNotSame([], $this->validator->validate('f', ['miss', 'other'], $constraints));
    }
}
