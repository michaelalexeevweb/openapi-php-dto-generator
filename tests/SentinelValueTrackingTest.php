<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use OpenapiPhpDtoGenerator\Contract\UnsetValue;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for sentinel-value (UnsetValue::UNSET) based optional parameter tracking.
 *
 * Generated DTOs use UnsetValue as the constructor default for optional fields so that
 * the deserializer can distinguish "not provided" from "provided as null".
 * The deserializer then sets the inRequest flag property to reflect which parameters
 * were actually present in the HTTP request.
 */
final class SentinelValueTrackingTest extends TestCase
{
    private DtoDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new DtoDeserializer();
    }

    public function testOptionalUnsetValueParam_notInRequestWhenAbsent(): void
    {
        // Only 'required' is provided; 'optional' is absent from the request body.
        $request = new Request([], [], [], [], [], [], json_encode(['required' => 'hello']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SentinelDto::class);

        $this->assertSame('hello', $dto->getRequired());
        $this->assertNull($dto->getOptional());
        $this->assertTrue($dto->isRequiredInRequest());
        $this->assertFalse($dto->isOptionalInRequest());
    }

    public function testOptionalUnsetValueParam_inRequestWhenExplicitlyProvided(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'required' => 'hello',
            'optional' => 'world',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SentinelDto::class);

        $this->assertSame('world', $dto->getOptional());
        $this->assertTrue($dto->isOptionalInRequest());
    }

    public function testOptionalUnsetValueParam_inRequestWhenProvidedAsNull(): void
    {
        // Explicit null in request body with nullable: true — field IS present, value IS null
        $request = new Request([], [], [], [], [], [], json_encode([
            'required' => 'hello',
            'optional' => null,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SentinelNullableDto::class);

        $this->assertNull($dto->getOptional());
        $this->assertTrue($dto->isOptionalInRequest());
    }

    public function testMultipleOptionalParams_independentlyTracked(): void
    {
        // Provide 'a' but not 'b'
        $request = new Request([], [], [], [], [], [], json_encode(['a' => 1]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SentinelMultiDto::class);

        $this->assertTrue($dto->isAInRequest());
        $this->assertFalse($dto->isBInRequest());
    }
}

// DTO with one required and one optional (UnsetValue-defaulted) field
final class SentinelDto
{
    private bool $requiredInRequest = false;
    private bool $optionalInRequest = false;
    private ?string $optional;

    public function __construct(
        private readonly string $required,
        string|UnsetValue $optional = UnsetValue::UNSET,
    ) {
        $this->optional = $optional !== UnsetValue::UNSET ? $optional : null;
    }

    public function getRequired(): string
    {
        return $this->required;
    }

    public function getOptional(): ?string
    {
        return $this->optional;
    }

    public function isRequiredInRequest(): bool
    {
        return $this->requiredInRequest;
    }

    public function isOptionalInRequest(): bool
    {
        return $this->optionalInRequest;
    }

    public function isRequiredRequired(): bool
    {
        return true;
    }

    public function isOptionalRequired(): bool
    {
        return false;
    }
}

// Variant where 'optional' is nullable with nullable: true in schema
final class SentinelNullableDto
{
    private bool $requiredInRequest = false;
    private bool $optionalInRequest = false;
    private ?string $optional;

    public function __construct(
        private readonly string $required,
        string|null|UnsetValue $optional = UnsetValue::UNSET,
    ) {
        $this->optional = ($optional !== UnsetValue::UNSET) ? $optional : null;
    }

    public function getRequired(): string
    {
        return $this->required;
    }

    public function getOptional(): ?string
    {
        return $this->optional;
    }

    public function isRequiredInRequest(): bool
    {
        return $this->requiredInRequest;
    }

    public function isOptionalInRequest(): bool
    {
        return $this->optionalInRequest;
    }

    public function isRequiredRequired(): bool
    {
        return true;
    }

    public function isOptionalRequired(): bool
    {
        return true; // required=true + nullable PHP type → schema nullable:true → null is valid
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'optional' => ['nullable' => true],
        ];
    }
}

final class SentinelMultiDto
{
    private bool $aInRequest = false;
    private bool $bInRequest = false;
    private ?int $a;
    private ?int $b;

    public function __construct(
        int|UnsetValue $a = UnsetValue::UNSET,
        int|UnsetValue $b = UnsetValue::UNSET,
    ) {
        $this->a = $a !== UnsetValue::UNSET ? $a : null;
        $this->b = $b !== UnsetValue::UNSET ? $b : null;
    }

    public function getA(): ?int
    {
        return $this->a;
    }

    public function getB(): ?int
    {
        return $this->b;
    }

    public function isAInRequest(): bool
    {
        return $this->aInRequest;
    }

    public function isBInRequest(): bool
    {
        return $this->bInRequest;
    }

    public function isARequired(): bool
    {
        return false;
    }

    public function isBRequired(): bool
    {
        return false;
    }
}
