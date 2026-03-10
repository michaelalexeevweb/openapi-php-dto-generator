<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use OpenapiPhpDtoGenerator\Service\RequestValidationService;
use Symfony\Component\HttpFoundation\Request;

final class RequestValidationServiceTest extends TestCase
{
    private RequestValidationService $service;

    protected function setUp(): void
    {
        $this->service = new RequestValidationService();
    }

    public function testValidateReturnsSuccessResultForValidData(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 'Test User',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->getErrors());

        $dto = $result->getDto();
        $this->assertInstanceOf(SimpleValidationDto::class, $dto);
        $this->assertSame(123, $dto->getId());
        $this->assertSame('Test User', $dto->getName());
    }

    public function testValidateReturnsFailureResultForInvalidData(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'not-an-integer',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('param "id" expects int, got string', $result->getFirstError());
    }

    public function testValidateReturnsMultipleErrors(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'not-int',
            'name' => 123,
            'enabled' => 'not-bool',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, MultiFieldValidationDto::class);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());

        $errors = $result->getErrors();
        $this->assertCount(3, $errors);

        $errorString = $result->getErrorsAsString();
        $this->assertStringContainsString('param "id" expects int, got string', $errorString);
        $this->assertStringContainsString('param "name" expects string, got int', $errorString);
        $this->assertStringContainsString('param "enabled" expects bool, got string', $errorString);
    }

    public function testGetDtoThrowsExceptionForFailedValidation(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot get DTO from failed validation result');

        $result->getDto();
    }

    public function testGetDtoOrNullReturnsNullForFailedValidation(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $this->assertNull($result->getDtoOrNull());
    }

    public function testGetDtoOrNullReturnsDtoForSuccessfulValidation(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $dto = $result->getDtoOrNull();
        $this->assertNotNull($dto);
        $this->assertInstanceOf(SimpleValidationDto::class, $dto);
    }

    public function testValidateOrThrowReturnsDto(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->service->validateOrThrow($request, SimpleValidationDto::class);

        $this->assertInstanceOf(SimpleValidationDto::class, $dto);
        $this->assertSame(123, $dto->getId());
    }

    public function testValidateOrThrowThrowsExceptionForInvalidData(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('param "id" expects int, got string');

        $this->service->validateOrThrow($request, SimpleValidationDto::class);
    }

    public function testGetFirstErrorReturnsFirstError(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 123,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $firstError = $result->getFirstError();
        $this->assertNotNull($firstError);
        $this->assertStringContainsString('param "id" expects int, got string', $firstError);
    }

    public function testGetErrorsAsStringWithCustomSeparator(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 123,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->service->validate($request, SimpleValidationDto::class);

        $errorString = $result->getErrorsAsString(' | ');
        $this->assertStringContainsString('param "id" expects int, got string', $errorString);
        $this->assertStringContainsString(' | ', $errorString);
    }
}
