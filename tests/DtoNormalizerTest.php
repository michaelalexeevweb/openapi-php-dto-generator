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

    public function testValidateAndNormalizeToArrayValidatesNestedObjectWhenMapTypeIsUnknownAlias(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOuterAliasDto(
            body: new NormalizerInnerItemsDto(items: [1, 2, 3]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed:');
        $this->expectExceptionMessage('field "body.items".0 must return enum');

        $normalizer->validateAndNormalizeToArray($dto);
    }

    public function testValidateAndNormalizeToArrayValidatesNestedObjectWhenMapTypeIsFqcn(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOuterFqcnDto(
            body: new NormalizerInnerItemsDto(items: [1, 2, 3]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed:');
        $this->expectExceptionMessage('field "body.items".0 must return enum');

        $normalizer->validateAndNormalizeToArray($dto);
    }

    public function testToArrayNormalizesDateTimeInterfaceToIsoString(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerDateTimeDto(new \DateTimeImmutable('2024-06-15T10:30:00+00:00'));

        $payload = $normalizer->toArray($dto);

        $this->assertSame('2024-06-15T10:30:00+00:00', $payload['createdAt']);
    }

    public function testToArrayNormalizesStringBackedEnumToValue(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerEnumValueDto(NormalizerStatusEnum::ACTIVE);

        $payload = $normalizer->toArray($dto);

        $this->assertSame('active', $payload['status']);
    }

    public function testToArrayNormalizesIntBackedEnumToIntValue(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerIntEnumDto(NormalizerPriorityEnum::HIGH);

        $payload = $normalizer->toArray($dto);

        $this->assertSame(2, $payload['priority']);
    }

    public function testToArrayNormalizesUnitEnumToName(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerUnitEnumDto(NormalizerDirectionEnum::NORTH);

        $payload = $normalizer->toArray($dto);

        $this->assertSame('NORTH', $payload['direction']);
    }

    public function testValidateReturnsEmptyArrayForValidDto(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerValidDto(name: 'alice', score: 42);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testValidateReturnsConstraintErrorsForInvalidDto(): void
    {
        $normalizer = new DtoNormalizer();
        // score must be >= 1, but we pass 0
        $dto = new NormalizerValidDto(name: 'alice', score: 0);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('score', $errors[0]);
    }

    public function testToArrayViaReflectionGettersWhenNoNormalizationMap(): void
    {
        // DTO has no toArray() / getNormalizationMap() — falls back to public getter discovery
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerGetterOnlyDto(id: 7, label: 'hello');

        $payload = $normalizer->toArray($dto);

        $this->assertSame(7, $payload['id']);
        $this->assertSame('hello', $payload['label']);
    }

    public function testToJsonMatchesToArray(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerGetterOnlyDto(id: 3, label: 'test');

        $json = $normalizer->toJson($dto);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($normalizer->toArray($dto), $decoded);
    }

    public function testNormalizationThrowsLogicExceptionForOpaqueObject(): void
    {
        // An object with no getters, no __toString, not backed enum, not DateTimeInterface — must throw
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerWithOpaquePropertyDto();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot normalize object of class/');
        $normalizer->toArray($dto);
    }

    public function testDtoToArrayPropagatesExceptionForUnnormalizableGetterValue(): void
    {
        // dtoToArray path (validateAndNormalizeToArray always uses reflection-based dtoToArray).
        // Before fix: catch (Throwable) silently wrote null. After fix: exception propagates.
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerDtoWithOpaqueGetter();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot normalize object of class/');
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

enum NormalizerItemStateEnum: string implements GeneratedDtoInterface
{
    case ONE = 'one';

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

final class NormalizerInnerItemsDto implements GeneratedDtoInterface
{
    /** @var array<mixed> */
    private array $items;

    /** @param array<NormalizerItemStateEnum|int> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /** @return array<NormalizerItemStateEnum> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
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
            'items' => [
                'getter' => 'getItems',
                'type' => 'array',
                'nullable' => false,
                'metadata' => ['openApiName' => 'items'],
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

final class NormalizerOuterAliasDto implements GeneratedDtoInterface
{
    public function __construct(private readonly NormalizerInnerItemsDto $body)
    {
    }

    public function getBody(): NormalizerInnerItemsDto
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['body' => $this->body];
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
            // Intentionally unknown alias to reproduce class_exists/enum_exists false branch.
            'body' => ['getter' => 'getBody', 'type' => 'UnknownAliasType', 'nullable' => false, 'metadata' => ['openApiName' => 'body']],
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

final class NormalizerOuterFqcnDto implements GeneratedDtoInterface
{
    public function __construct(private readonly NormalizerInnerItemsDto $body)
    {
    }

    public function getBody(): NormalizerInnerItemsDto
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['body' => $this->body];
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
            'body' => ['getter' => 'getBody', 'type' => NormalizerInnerItemsDto::class, 'nullable' => false, 'metadata' => ['openApiName' => 'body']],
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

// DTO with a DateTimeImmutable field — normalized to 'c' ISO string by the normalizer
final class NormalizerDateTimeDto implements GeneratedDtoInterface
{
    public function __construct(private readonly \DateTimeImmutable $createdAt)
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['createdAt' => $this->createdAt];
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

enum NormalizerStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

enum NormalizerPriorityEnum: int
{
    case LOW = 1;
    case HIGH = 2;
}

enum NormalizerDirectionEnum
{
    case NORTH;
    case SOUTH;
}

final class NormalizerEnumValueDto implements GeneratedDtoInterface
{
    public function __construct(private readonly NormalizerStatusEnum $status)
    {
    }

    public function getStatus(): NormalizerStatusEnum
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status];
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

final class NormalizerIntEnumDto implements GeneratedDtoInterface
{
    public function __construct(private readonly NormalizerPriorityEnum $priority)
    {
    }

    public function getPriority(): NormalizerPriorityEnum
    {
        return $this->priority;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['priority' => $this->priority];
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

final class NormalizerUnitEnumDto implements GeneratedDtoInterface
{
    public function __construct(private readonly NormalizerDirectionEnum $direction)
    {
    }

    public function getDirection(): NormalizerDirectionEnum
    {
        return $this->direction;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['direction' => $this->direction];
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

// DTO with constraint metadata — used to test validate() with/without violations
final class NormalizerValidDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly string $name,
        private readonly int $score,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'score' => $this->score];
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
            'name' => ['getter' => 'getName', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'name']],
            'score' => ['getter' => 'getScore', 'type' => 'int', 'nullable' => false, 'metadata' => ['openApiName' => 'score']],
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
        return [
            'score' => ['minimum' => 1, 'maximum' => 100],
        ];
    }
}

// DTO that exercises the public-getter discovery path in dtoToArray.
// toArray() throws so tryFastArray() returns null and dtoToArray() falls back to
// reflecting over public getters (buildClassMetaFromPublicGetters).
final class NormalizerGetterOnlyDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $label,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Throw so the normalizer falls back to the reflection-based getter path
        throw new \LogicException('toArray not implemented — use reflection path');
    }

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'label' => $this->label];
    }

    public function toJson(): string
    {
        return json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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


// Opaque object: no getters, no __toString — triggers LogicException in normalizeValue
final class OpaqueObject
{
}

// DTO whose toArray() returns an OpaqueObject nested value
final class NormalizerWithOpaquePropertyDto implements GeneratedDtoInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['nested' => new OpaqueObject()];
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

// DTO that goes through reflection-based dtoToArray and has a getter returning OpaqueObject
final class NormalizerDtoWithOpaqueGetter implements GeneratedDtoInterface
{
    public function getNestedValue(): OpaqueObject
    {
        return new OpaqueObject();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['nestedValue' => $this->getNestedValue()];
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
            'nestedValue' => [
                'getter' => 'getNestedValue',
                'type' => OpaqueObject::class,
                'nullable' => false,
                'metadata' => ['openApiName' => 'nestedValue', 'required' => true, 'inPathFlagGetter' => '', 'inQueryFlagGetter' => '', 'inRequestFlagGetter' => '', 'constraints' => []],
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
