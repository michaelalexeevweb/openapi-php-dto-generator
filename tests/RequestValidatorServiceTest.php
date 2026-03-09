<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use OpenapiPhpDtoGenerator\Exception\RequestValidationException;
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

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Field "id" must be an integer');

        $this->validator->validate($request, SimpleValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidStringType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 456,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Field "name" must be a string');

        $this->validator->validate($request, SimpleValidationDto::class);
    }

    public function testValidateThrowsExceptionForNullInNonNullableField(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => null,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Field "id" cannot be null');

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

    public function testValidateThrowsExceptionForInvalidBooleanType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'enabled' => 'yes',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Field "enabled" must be a boolean');

        $this->validator->validate($request, BooleanValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidArrayType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'tags' => 'not-an-array',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Field "tags" must be an array');

        $this->validator->validate($request, ArrayValidationDto::class);
    }

    public function testValidateThrowsExceptionForInvalidDateTimeType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'createdAt' => 'invalid-date',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RequestValidationException::class);
        $this->expectExceptionMessage('Failed to deserialize request');

        $this->validator->validate($request, DateTimeValidationDto::class);
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
            $this->fail('Expected RequestValidationException was not thrown');
        } catch (RequestValidationException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Field "id" must be an integer', $message);
            $this->assertStringContainsString('Field "name" must be a string', $message);
            $this->assertStringContainsString('Field "enabled" must be a boolean', $message);
            $this->assertStringContainsString("\n", $message); // Check errors are separated by newline
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
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

