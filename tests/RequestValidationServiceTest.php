<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use OpenapiPhpDtoGenerator\Contract\OpenApiFormatHandlerInterface;
use OpenapiPhpDtoGenerator\Service\OpenApiFormatRegistry;
use OpenapiPhpDtoGenerator\Service\RequestValidationService;
use OpenapiPhpDtoGenerator\Service\ValidationMessageKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
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

    public function testValidateSupportsCustomErrorMessages(): void
    {
        $service = new RequestValidationService(messageOverrides: [
            ValidationMessageKey::PARAM_EXPECTS_TYPE->value => 'custom param "{paramPath}" must be {expectedType}, {actualType} given',
        ]);

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'invalid',
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $service->validate($request, SimpleValidationDto::class);

        $this->assertFalse($result->isValid());
        $this->assertSame('custom param "id" must be int, string given', $result->getFirstError());
    }

    public function testValidateSupportsCustomFormatValidationAndDeserialization(): void
    {
        $registry = new OpenApiFormatRegistry([
            'upper-code' => new class implements OpenApiFormatHandlerInterface {
                public function validate(string $subject, mixed $value): ?string
                {
                    if (!is_string($value)) {
                        return sprintf('%s expects uppercase code string', $subject);
                    }

                    return preg_match('/^[A-Z0-9\-]+$/', $value) === 1
                        ? null
                        : sprintf('%s must match custom format upper-code', $subject);
                }

                public function deserialize(mixed $value, string $typeName, string $paramPath, bool $allowsNull): mixed
                {
                    if (!is_string($value)) {
                        return $value;
                    }

                    return strtoupper($value);
                }
            },
        ]);

        $service = new RequestValidationService(formatRegistry: $registry);

        $request = new Request([], [], [], [], [], [], json_encode([
            'code' => 'ab-12',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $service->validate($request, CustomFormatValidationDto::class);

        $this->assertTrue($result->isValid());
        $this->assertSame('AB-12', $result->getDto()->getCode());
    }
}

final class CustomFormatValidationDto
{
    public function __construct(private string $code)
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getConstraints(): array
    {
        return [
            'code' => ['type' => 'string', 'format' => 'upper-code'],
        ];
    }
}
