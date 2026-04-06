<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Comprehensive test suite for DtoDeserializer covering:
 *   1. Numeric constraints
 *   2. String constraints
 *   3. Format constraints
 *   4. Array constraints
 *   5. Parameter sources (query, path, body, mixed)
 *   6. Date deserialization
 *   7. File uploads
 */
final class DtoFeaturesTest extends TestCase
{
    private DtoDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new DtoDeserializer();
    }

    // =========================================================================
    // 1. Numeric constraints
    // =========================================================================

    public function testMinimumConstraint_rejectsValueBelowMinimum(): void
    {
        $request = $this->jsonRequest(['amount' => 9]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be greater than or equal to 10');

        $this->deserializer->deserialize($request, NumericConstraintsDto::class);
    }

    public function testMinimumConstraint_acceptsExactMinimum(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['amount' => 10, 'multiplier' => 3]),
            NumericConstraintsDto::class,
        );

        $this->assertSame(10, $dto->amount);
    }

    public function testExclusiveMinimumConstraint_rejectsEqualValue(): void
    {
        $request = $this->jsonRequest(['amount' => 10, 'multiplier' => 3, 'score' => 5]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be greater than 5');

        $this->deserializer->deserialize($request, NumericConstraintsDto::class);
    }

    public function testExclusiveMinimumConstraint_acceptsAbove(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['amount' => 10, 'multiplier' => 3, 'score' => 6]),
            NumericConstraintsDto::class,
        );

        $this->assertSame(6, $dto->score);
    }

    public function testMaximumConstraint_rejectsValueAboveMaximum(): void
    {
        $request = $this->jsonRequest(['amount' => 10, 'multiplier' => 3, 'score' => 6, 'limit' => 101]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be less than or equal to 100');

        $this->deserializer->deserialize($request, NumericConstraintsDto::class);
    }

    public function testMaximumConstraint_acceptsExactMaximum(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['amount' => 10, 'multiplier' => 3, 'score' => 6, 'limit' => 100]),
            NumericConstraintsDto::class,
        );

        $this->assertSame(100, $dto->limit);
    }

    public function testMultipleOfConstraint_rejectsNonMultiple(): void
    {
        $request = $this->jsonRequest(['amount' => 10, 'multiplier' => 7]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a multiple of 3');

        $this->deserializer->deserialize($request, NumericConstraintsDto::class);
    }

    public function testMultipleOfConstraint_acceptsMultiple(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['amount' => 12, 'multiplier' => 9]),
            NumericConstraintsDto::class,
        );

        $this->assertSame(9, $dto->multiplier);
    }

    // =========================================================================
    // 2. String constraints
    // =========================================================================

    public function testMinLengthConstraint_rejectsShortString(): void
    {
        $request = $this->jsonRequest(['username' => 'hi', 'code' => 'abc123']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('length must be at least 5 characters');

        $this->deserializer->deserialize($request, StringConstraintsDto::class);
    }

    public function testMinLengthConstraint_acceptsExactLength(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['username' => 'hello', 'code' => '123456']),
            StringConstraintsDto::class,
        );

        $this->assertSame('hello', $dto->username);
    }

    public function testMaxLengthConstraint_rejectsTooLong(): void
    {
        $request = $this->jsonRequest(['username' => 'hello', 'code' => 'toolongvalue']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('length must be at most 6 characters');

        $this->deserializer->deserialize($request, StringConstraintsDto::class);
    }

    public function testPatternConstraint_rejectsNonMatchingString(): void
    {
        $request = $this->jsonRequest(['username' => 'hello', 'code' => 'abc']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match pattern');

        $this->deserializer->deserialize($request, StringConstraintsDto::class);
    }

    public function testPatternConstraint_acceptsMatchingString(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['username' => 'hello', 'code' => '123456']),
            StringConstraintsDto::class,
        );

        $this->assertSame('123456', $dto->code);
    }

    // =========================================================================
    // 3. Format constraints
    // =========================================================================

    public function testFormatEmail_rejectsInvalidEmail(): void
    {
        $request = $this->jsonRequest(['email' => 'not-an-email']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match format email');

        $this->deserializer->deserialize($request, EmailFormatDto::class);
    }

    public function testFormatEmail_acceptsValidEmail(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['email' => 'user@example.com']),
            EmailFormatDto::class,
        );

        $this->assertSame('user@example.com', $dto->email);
    }

    public function testFormatUuid_rejectsInvalidUuid(): void
    {
        $request = $this->jsonRequest(['uuid' => 'not-a-uuid']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match format uuid');

        $this->deserializer->deserialize($request, UuidFormatDto::class);
    }

    public function testFormatUuid_acceptsValidUuid(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['uuid' => '550e8400-e29b-41d4-a716-446655440000']),
            UuidFormatDto::class,
        );

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $dto->uuid);
    }

    public function testFormatDate_rejectsInvalidDate(): void
    {
        // 2024-13-01 is invalid (month 13)
        $request = $this->jsonRequest(['dateStr' => '2024-13-01']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match format date');

        $this->deserializer->deserialize($request, DateFormatDto::class);
    }

    public function testFormatDate_acceptsValidDate(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['dateStr' => '2024-06-15']),
            DateFormatDto::class,
        );

        $this->assertSame('2024-06-15', $dto->dateStr);
    }

    public function testFormatDateTime_rejectsInvalidDateTime(): void
    {
        $request = $this->jsonRequest(['dateTimeStr' => 'not-a-datetime']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match format date-time');

        $this->deserializer->deserialize($request, DateTimeFormatDto::class);
    }

    public function testFormatDateTime_acceptsValidDateTime(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['dateTimeStr' => '2024-01-15T10:30:00+00:00']),
            DateTimeFormatDto::class,
        );

        $this->assertSame('2024-01-15T10:30:00+00:00', $dto->dateTimeStr);
    }

    // =========================================================================
    // 4. Array constraints
    // =========================================================================

    public function testMinItemsConstraint_rejectsTooFewItems(): void
    {
        $request = $this->jsonRequest(['tags' => ['a']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain at least 2 items');

        $this->deserializer->deserialize($request, ArrayConstraintsDto::class);
    }

    public function testMinItemsConstraint_acceptsEnoughItems(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['tags' => ['a', 'b']]),
            ArrayConstraintsDto::class,
        );

        $this->assertCount(2, $dto->tags);
    }

    public function testMaxItemsConstraint_rejectsTooManyItems(): void
    {
        $request = $this->jsonRequest(['tags' => ['a', 'b', 'c', 'd', 'e', 'f']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain at most 5 items');

        $this->deserializer->deserialize($request, ArrayConstraintsDto::class);
    }

    public function testUniqueItemsConstraint_rejectsDuplicates(): void
    {
        $request = $this->jsonRequest(['tags' => ['a', 'a']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain unique items');

        $this->deserializer->deserialize($request, ArrayConstraintsDto::class);
    }

    public function testUniqueItemsConstraint_acceptsUnique(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->jsonRequest(['tags' => ['a', 'b']]),
            ArrayConstraintsDto::class,
        );

        $this->assertSame(['a', 'b'], $dto->tags);
    }

    // =========================================================================
    // 5. Parameter sources
    // =========================================================================

    public function testGetRequest_readsFromQueryString(): void
    {
        $request = new Request(['search' => 'hello', 'page' => '3'], [], [], [], [], []);

        $dto = $this->deserializer->deserialize($request, QuerySourceDto::class);

        $this->assertSame('hello', $dto->search);
        $this->assertSame(3, $dto->page);
    }

    public function testPathParameters_readFromAttributes(): void
    {
        $request = new Request([], [], ['userId' => '42', 'slug' => 'my-post'], [], [], []);

        $dto = $this->deserializer->deserialize($request, PathSourceDto::class);

        $this->assertSame(42, $dto->userId);
        $this->assertSame('my-post', $dto->slug);
    }

    public function testPostRequest_readsFromJsonBody(): void
    {
        $request = $this->jsonRequest(['title' => 'My Post', 'body' => 'Some content']);

        $dto = $this->deserializer->deserialize($request, JsonBodyDto::class);

        $this->assertSame('My Post', $dto->title);
        $this->assertSame('Some content', $dto->body);
    }

    public function testMixedRequest_pathPlusBody(): void
    {
        // Simulates PATCH /users/{id} — id comes from path, name from JSON body
        $request = new Request([], [], ['id' => '99'], [], [], [], json_encode(['name' => 'Alice']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, MixedSourceDto::class);

        $this->assertSame(99, $dto->id);
        $this->assertSame('Alice', $dto->name);
    }

    public function testInRequestTracking_tracksProvidedFields(): void
    {
        // Only provide 'id' and 'name', leave 'description' absent
        $request = $this->jsonRequest(['id' => 7, 'name' => 'Bob']);

        $dto = $this->deserializer->deserialize($request, TrackableBodyDto::class);

        $this->assertTrue($dto->isIdInRequest());
        $this->assertTrue($dto->isNameInRequest());
        $this->assertFalse($dto->isDescriptionInRequest());
    }

    public function testInPathTracking(): void
    {
        $request = new Request([], [], ['resourceId' => '55'], [], [], []);

        $dto = $this->deserializer->deserialize($request, TrackablePathDto::class);

        $this->assertSame(55, $dto->resourceId);
        $this->assertTrue($dto->isResourceIdInRequest());
        $this->assertTrue($dto->isResourceIdInPath());
    }

    public function testInQueryTracking(): void
    {
        $request = new Request(['filter' => 'active'], [], [], [], [], []);

        $dto = $this->deserializer->deserialize($request, TrackableQueryDto::class);

        $this->assertSame('active', $dto->filter);
        $this->assertTrue($dto->isFilterInRequest());
        $this->assertTrue($dto->isFilterInQuery());
    }

    // =========================================================================
    // 6. Date deserialization
    // =========================================================================

    public function testDeserializeDate_parsesYmd(): void
    {
        // openApiFormat = 'date' makes the deserializer use Y-m-d temporal format
        $request = $this->jsonRequest(['dateField' => '2024-03-15']);

        $dto = $this->deserializer->deserialize($request, DateDeserializeDto::class);

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->dateField);
        $this->assertSame('2024-03-15', $dto->dateField->format('Y-m-d'));
    }

    public function testDeserializeDateTime_parsesIso8601(): void
    {
        $request = $this->jsonRequest(['createdAt' => '2024-01-15T10:30:00+00:00']);

        $dto = $this->deserializer->deserialize($request, DateTimeDeserializeDto::class);

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->createdAt);
        $this->assertSame('2024-01-15', $dto->createdAt->format('Y-m-d'));
        $this->assertSame('10:30:00', $dto->createdAt->format('H:i:s'));
    }

    public function testDeserializeDate_throwsForInvalidDate(): void
    {
        $request = $this->jsonRequest(['dateField' => 'not-a-date']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"dateField"');

        $this->deserializer->deserialize($request, DateDeserializeDto::class);
    }

    public function testDeserializeDate_throwsForEmptyString(): void
    {
        $request = $this->jsonRequest(['dateField' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('got empty string');

        $this->deserializer->deserialize($request, DateDeserializeDto::class);
    }

    // =========================================================================
    // 7. File upload
    // =========================================================================

    public function testFileUpload_deserializesUploadedFile(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpPath, 'fake file content');

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'document.txt',
            'text/plain',
            null,
            true, // test mode
        );

        // Multipart form upload: files in $request->files
        $request = new Request([], [], [], [], ['document' => $uploadedFile], []);

        $dto = $this->deserializer->deserialize($request, FileUploadDto::class);

        $this->assertInstanceOf(UploadedFile::class, $dto->document);
        $this->assertSame('document.txt', $dto->document->getClientOriginalName());

        @unlink($tmpPath);
    }

    public function testFileUpload_throwsForMissingFile(): void
    {
        // No file provided, field is required
        $request = new Request([], [], [], [], [], []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"document"');

        $this->deserializer->deserialize($request, FileUploadDto::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function jsonRequest(array $data): Request
    {
        $request = new Request([], [], [], [], [], [], json_encode($data));
        $request->headers->set('Content-Type', 'application/json');
        return $request;
    }
}

// =============================================================================
// Test DTOs — all defined inline
// =============================================================================

// --- 1. Numeric constraints --------------------------------------------------

/**
 * DTO covering minimum, exclusiveMinimum (numeric form), maximum, and multipleOf.
 *
 * Fields:
 *   amount    — minimum: 10
 *   multiplier — multipleOf: 3
 *   score     — exclusiveMinimum: 5 (numeric form)
 *   limit     — maximum: 100
 */
final class NumericConstraintsDto
{
    public function __construct(
        public int $amount,
        public int $multiplier,
        public ?int $score = null,
        public ?int $limit = null,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'amount' => [
                'minimum' => 10,
            ],
            'multiplier' => [
                'multipleOf' => 3,
            ],
            'score' => [
                // Numeric exclusiveMinimum (OpenAPI 3.1 style): value itself is the exclusive lower bound
                'exclusiveMinimum' => 5,
            ],
            'limit' => [
                'maximum' => 100,
            ],
        ];
    }
}

// --- 2. String constraints ---------------------------------------------------

/**
 * DTO covering minLength, maxLength, and pattern constraints.
 *
 * Fields:
 *   username — minLength: 5
 *   code     — maxLength: 6, pattern: ^\d+$
 */
final class StringConstraintsDto
{
    public function __construct(
        public string $username,
        public string $code,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'username' => [
                'minLength' => 5,
            ],
            'code' => [
                'maxLength' => 6,
                'pattern' => '^\d+$',
            ],
        ];
    }
}

// --- 3. Format constraints ---------------------------------------------------

final class EmailFormatDto
{
    public function __construct(
        public string $email,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'email' => ['format' => 'email'],
        ];
    }
}

final class UuidFormatDto
{
    public function __construct(
        public string $uuid,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'uuid' => ['format' => 'uuid'],
        ];
    }
}

final class DateFormatDto
{
    public function __construct(
        public string $dateStr,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'dateStr' => ['format' => 'date'],
        ];
    }
}

final class DateTimeFormatDto
{
    public function __construct(
        public string $dateTimeStr,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'dateTimeStr' => ['format' => 'date-time'],
        ];
    }
}

// --- 4. Array constraints ----------------------------------------------------

/**
 * DTO covering minItems, maxItems, and uniqueItems.
 */
final class ArrayConstraintsDto
{
    /** @param array<string> $tags */
    public function __construct(
        public array $tags,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'tags' => [
                'minItems' => 2,
                'maxItems' => 5,
                'uniqueItems' => true,
            ],
        ];
    }
}

// --- 5. Parameter sources ----------------------------------------------------

final class QuerySourceDto
{
    public function __construct(
        public string $search,
        public int $page,
    ) {
    }
}

final class PathSourceDto
{
    public function __construct(
        public int $userId,
        public string $slug,
    ) {
    }
}

final class JsonBodyDto
{
    public function __construct(
        public string $title,
        public string $body,
    ) {
    }
}

final class MixedSourceDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

final class TrackableBodyDto
{
    private bool $idInRequest = false;
    private bool $nameInRequest = false;
    private bool $descriptionInRequest = false;

    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
    ) {
    }

    public function isIdInRequest(): bool
    {
        return $this->idInRequest;
    }

    public function isNameInRequest(): bool
    {
        return $this->nameInRequest;
    }

    public function isDescriptionInRequest(): bool
    {
        return $this->descriptionInRequest;
    }

    public function isDescriptionRequired(): bool
    {
        return false;
    }
}

final class TrackablePathDto
{
    private bool $resourceIdInRequest = false;
    private bool $resourceIdInPath = false;

    public function __construct(
        public int $resourceId,
    ) {
    }

    public function isResourceIdInRequest(): bool
    {
        return $this->resourceIdInRequest;
    }

    public function isResourceIdInPath(): bool
    {
        return $this->resourceIdInPath;
    }
}

final class TrackableQueryDto
{
    private bool $filterInRequest = false;
    private bool $filterInQuery = false;

    public function __construct(
        public string $filter,
    ) {
    }

    public function isFilterInRequest(): bool
    {
        return $this->filterInRequest;
    }

    public function isFilterInQuery(): bool
    {
        return $this->filterInQuery;
    }
}

// --- 6. Date deserialization -------------------------------------------------

/**
 * DTO for date deserialization tests.
 *
 * The deserializer uses openApiFormat='date' to apply Y-m-d temporal format.
 * We communicate this via getConstraints() → format: 'date'.
 */
final class DateDeserializeDto
{
    public function __construct(
        public DateTimeImmutable $dateField,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'dateField' => ['format' => 'date'],
        ];
    }
}

/**
 * DTO for datetime deserialization tests (ISO 8601).
 *
 * The deserializer uses openApiFormat='date-time' to apply ISO8601 temporal format.
 */
final class DateTimeDeserializeDto
{
    public function __construct(
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'createdAt' => ['format' => 'date-time'],
        ];
    }
}

// --- 7. File upload ----------------------------------------------------------

final class FileUploadDto
{
    public function __construct(
        public UploadedFile $document,
    ) {
    }
}
