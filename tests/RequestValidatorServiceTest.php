<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use OpenapiPhpDtoGenerator\Service\RequestValidatorService;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidatorServiceTest extends TestCase
{
    private RequestValidatorService $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidatorService();
    }

    public function testValidateSuccessfullyWithValidData(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 'Test User',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->validator->validate($request, SimpleValidationDto::class);

        $this->assertInstanceOf(SimpleValidationDto::class, $dto);
        $this->assertSame(123, $dto->getId());
        $this->assertSame('Test User', $dto->getName());
    }

    public function testValidateThrowsExceptionForInvalidIntegerType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'not-an-integer',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "id" expects int, got string');

        $this->validator->validate($request, SimpleValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidStringType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 456,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "name" expects string, got int');

        $this->validator->validate($request, SimpleValidationDto::class);
    }

    public function testValidateThrowsExceptionForNullInNonNullableField(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => null,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "id" expects int, got null');

        $this->validator->validate($request, SimpleValidationDto::class);
    }

    public function testValidateAcceptsNullForNullableField(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'Test',
            'description' => null,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->validator->validate($request, NullableValidationDto::class);

        $this->assertInstanceOf(NullableValidationDto::class, $dto);
        $this->assertNull($dto->getDescription());
    }

    public function testValidateThrowsExceptionForOptionalFieldSentAsExplicitNull(): void
    {
        // description is optional (not required) but not schema-nullable,
        // so sending null explicitly should fail.
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'Test',
            'optional' => null,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "optional" expects string, got null');

        $this->validator->validate($request, OptionalNotNullableDto::class);
    }

    public function testValidateThrowsExceptionForInvalidBooleanType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'enabled' => 'yes',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "enabled" expects bool, got string');

        $this->validator->validate($request, BooleanValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidArrayType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'tags' => 'not-an-array',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "tags" expects array, got string');

        $this->validator->validate($request, ArrayValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidDateTimeType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'createdAt' => 'invalid-date',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "createdAt" expects a valid date-time');

        $this->validator->validate($request, DateTimeValidationDto::class);
    }

    public function testValidateThrowsExceptionForEmptyStringDateTime(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'createdAt' => '',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "createdAt" expects a valid date-time, got empty string');

        $this->validator->validate($request, DateTimeValidationDto::class);
    }

    public function testValidateThrowsExceptionForEmptyStringDate(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'date' => '',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "date" expects a valid date in Y-m-d format, got empty string');

        $this->validator->validate($request, DateOnlyValidationDto::class);
    }

    public function testValidateThrowsExceptionForWrongDateFormat(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'date' => '10-03-2026',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "date" expects a date in Y-m-d format');

        $this->validator->validate($request, DateOnlyValidationDto::class);
    }

    public function testValidateWithMultipleErrors(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'not-int',
            'name' => 123,
            'enabled' => 'not-bool',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        try {
            $this->validator->validate($request, MultiFieldValidationDto::class);
            $this->fail('Expected BadRequestException was not thrown');
        } catch (BadRequestException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('param "id" expects int, got string', $message);
        }
    }

    public function testValidateAcceptsFloatForIntegerField(): void
    {
        // Note: PHP strict type checking - deserializer should cast '5' to int
        $request = new Request(['page' => '5'], [], [], [], [], []);

        $dto = $this->validator->validate($request, IntegerValidationDto::class);

        $this->assertInstanceOf(IntegerValidationDto::class, $dto);
        $this->assertSame(5, $dto->getPage());
    }
}

// Test DTOs
final class SimpleValidationDto
{
    private int $id;
    private string $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

final class NullableValidationDto
{
    private int $id;
    private string $name;
    private ?string $description;

    public function __construct(int $id, string $name, ?string $description)
    {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // description is required + nullable in schema → schema-level nullable
    public function isDescriptionRequired(): bool
    {
        return true;
    }
}

final class OptionalNotNullableDto
{
    private int $id;
    private string $name;
    private ?string $optional;

    public function __construct(int $id, string $name, ?string $optional)
    {
        $this->id = $id;
        $this->name = $name;
        $this->optional = $optional;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOptional(): ?string
    {
        return $this->optional;
    }

    // optional is not required and not schema-nullable → explicit null should fail
    public function isOptionalRequired(): bool
    {
        return false;
    }
}

final class DateOnlyValidationDto
{
    private DateTimeImmutable $date;

    public function __construct(DateTimeImmutable $date)
    {
        $this->date = $date;
    }

    /**
     * Expected format: Y-m-d
     */
    public function getDate(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function isDateRequired(): bool
    {
        return true;
    }
}

final class BooleanValidationDto
{
    private bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }
}

final class ArrayValidationDto
{
    /** @var array<string> */
    private array $tags;

    public function __construct(array $tags)
    {
        $this->tags = $tags;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}

final class DateTimeValidationDto
{
    private DateTimeImmutable $createdAt;

    public function __construct(DateTimeImmutable $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Expected format: yyyy-MM-dd HH:mm:ss
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt->format('c');
    }

    public function isCreatedAtRequired(): bool
    {
        return true;
    }
}

final class MultiFieldValidationDto
{
    private int $id;
    private string $name;
    private bool $enabled;

    public function __construct(int $id, string $name, bool $enabled)
    {
        $this->id = $id;
        $this->name = $name;
        $this->enabled = $enabled;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }
}

final class IntegerValidationDto
{
    private int $page;

    public function __construct(int $page)
    {
        $this->page = $page;
    }

    public function getPage(): int
    {
        return $this->page;
    }
}

