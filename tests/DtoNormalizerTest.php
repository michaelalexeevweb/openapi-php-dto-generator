<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Contract\UnsetValue;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DtoNormalizerTest extends TestCase
{
    public function testToArraySerializesEnumsAsValues(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerEnumArrayDto(
            availableFilters: [NormalizerFilterEnum::AVAILABLE_FILTERS],
            primaryFilter: NormalizerFilterEnum::AVAILABLE_FILTERS,
        );

        $payload = $normalizer->toArray($dto);

        $this->assertSame(
            [
                'availableFilters' => ['availableFilters'],
                'primaryFilter' => 'availableFilters',
            ],
            $payload,
        );
    }

    public function testJsonNormalizationUsesEnumValuesForToJsonAndValidateAndNormalizeToJson(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerEnumArrayDto(
            availableFilters: [NormalizerFilterEnum::AVAILABLE_FILTERS],
            primaryFilter: NormalizerFilterEnum::AVAILABLE_FILTERS,
        );

        $toJsonPayload = json_decode($normalizer->toJson($dto), true, 512, JSON_THROW_ON_ERROR);
        $validateJsonPayload = json_decode($normalizer->validateAndNormalizeToJson($dto), true, 512, JSON_THROW_ON_ERROR);

        $expected = [
            'availableFilters' => ['availableFilters'],
            'primaryFilter' => 'availableFilters',
        ];

        $this->assertSame($expected, $toJsonPayload);
        $this->assertSame($expected, $validateJsonPayload);
    }

    public function testValidateAndNormalizeToArrayRejectsInvalidEnumArrayItems(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOptionalEnumArrayDto(
            availableFilters: [1, 2, 3],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed:');
        $this->expectExceptionMessage('field "availableFilters".0 must return enum');

        $normalizer->validateAndNormalizeToArray($dto);
    }

    public function testValidateAndNormalizeToJsonRejectsInvalidEnumArrayItems(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOptionalEnumArrayDto(
            availableFilters: [1, 2, 3],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed:');
        $this->expectExceptionMessage('field "availableFilters".0 must return enum');

        $normalizer->validateAndNormalizeToJson($dto);
    }

    public function testValidateAndNormalizeToArrayValidatesNestedPayloadDto(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerDictionaryResponse(
            payload: new NormalizerDictionaryPayload(
                availableFilters: [1, 2, 3],
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed:');
        $this->expectExceptionMessage('field "payload.availableFilters".0 must return enum');

        $normalizer->validateAndNormalizeToArray($dto);
    }
}

enum NormalizerFilterEnum: string implements GeneratedDtoInterface
{
    case AVAILABLE_FILTERS = 'availableFilters';

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

final class NormalizerEnumArrayDto implements GeneratedDtoInterface
{
    /** @param array<NormalizerFilterEnum> $availableFilters */
    public function __construct(
        private readonly array $availableFilters,
        private readonly NormalizerFilterEnum $primaryFilter,
    ) {
    }

    /** @return array<NormalizerFilterEnum> */
    public function getAvailableFilters(): array
    {
        return $this->availableFilters;
    }

    public function getPrimaryFilter(): NormalizerFilterEnum
    {
        return $this->primaryFilter;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'availableFilters' => $this->availableFilters,
            'primaryFilter' => $this->primaryFilter,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'availableFilters' => [
                'getter' => 'getAvailableFilters',
                'type' => 'array',
                'nullable' => false,
                'metadata' => ['openApiName' => 'availableFilters'],
            ],
            'primaryFilter' => [
                'getter' => 'getPrimaryFilter',
                'type' => NormalizerFilterEnum::class,
                'nullable' => false,
                'metadata' => ['openApiName' => 'primaryFilter'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

final class NormalizerOptionalEnumArrayDto implements GeneratedDtoInterface
{
    /** @var ?array<mixed> */
    private ?array $availableFilters;

    /** @param array<NormalizerFilterEnum|int>|null|UnsetValue $availableFilters */
    public function __construct(
        array|null|UnsetValue $availableFilters = UnsetValue::UNSET,
    ) {
        $this->availableFilters = $availableFilters !== UnsetValue::UNSET ? $availableFilters : null;
    }

    /** @return ?array<NormalizerFilterEnum> */
    public function getAvailableFilters(): ?array
    {
        return $this->availableFilters;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'availableFilters' => $this->availableFilters,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'availableFilters' => [
                'getter' => 'getAvailableFilters',
                'type' => 'array|null',
                'nullable' => true,
                'metadata' => ['openApiName' => 'availableFilters'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

final class NormalizerDictionaryResponse implements GeneratedDtoInterface
{
    public function __construct(
        private readonly NormalizerDictionaryPayload $payload,
    ) {
    }

    public function getPayload(): NormalizerDictionaryPayload
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'payload' => [
                'getter' => 'getPayload',
                // Keep short class name to verify class-context resolution.
                'type' => 'NormalizerDictionaryPayload',
                'nullable' => false,
                'metadata' => ['openApiName' => 'payload'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

final class NormalizerDictionaryPayload implements GeneratedDtoInterface
{
    /** @var ?array<mixed> */
    private ?array $availableFilters;

    /** @param array<NormalizerFilterEnum|int>|null|UnsetValue $availableFilters */
    public function __construct(
        array|null|UnsetValue $availableFilters = UnsetValue::UNSET,
    ) {
        $this->availableFilters = $availableFilters !== UnsetValue::UNSET ? $availableFilters : null;
    }

    /** @return ?array<NormalizerFilterEnum> */
    public function getAvailableFilters(): ?array
    {
        return $this->availableFilters;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'availableFilters' => $this->availableFilters,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'availableFilters' => [
                'getter' => 'getAvailableFilters',
                'type' => 'array|null',
                'nullable' => true,
                'metadata' => ['openApiName' => 'availableFilters'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

