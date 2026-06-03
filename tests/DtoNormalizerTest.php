<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Contract\UnsetValue;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
        $dto = new NormalizerDateTimeDto(new DateTimeImmutable('2024-06-15T10:30:00+00:00'));

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

    public function testToArray_emptyNormalizationMap_returnsEmptyArrayWithoutReflectionFallback(): void
    {
        // getNormalizationMap() returns [] (not null) — toArray() returns [] via fast path,
        // does NOT fall through to reflection-based dtoToArray
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerEmptyMapDto();

        $result = $normalizer->toArray($dto);

        $this->assertSame([], $result);
    }

    public function testValidate_getterThrowsNonLogicException_returnsErrorInstead(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerThrowingGetterDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('getExploding', $errors[0]);
        $this->assertStringContainsString('database is down', $errors[0]);
    }

    public function testValidate_anyOfConstraint_passesWhenOneBranchMatches(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerAnyOfConstraintDto(value: 3);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testValidate_anyOfConstraint_failsWhenNoBranchMatches(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerAnyOfConstraintDto(value: 50);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('value', $errors[0]);
    }

    public function testValidate_oneOfConstraint_passesWhenExactlyOneBranchMatches(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOneOfConstraintDto(value: 150);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testValidate_oneOfConstraint_failsWhenMoreThanOneBranchMatches(): void
    {
        $normalizer = new DtoNormalizer();
        // value 50: matches both branches (>= 1 AND <= 100)
        $dto = new NormalizerOneOfConstraintDto(value: 50);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('value', $errors[0]);
    }

    public function testToArrayNormalizesSimpleDtoWithoutNormalizationMap(): void
    {
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

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot normalize object of class/');
        $normalizer->toArray($dto);
    }

    public function testTryFastArrayPropagatesNonWasProvidedLogicException(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerDtoWithThrowingToArray();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('toArray exploded');
        $normalizer->toArray($dto);
    }

    public function testDtoToArrayPropagatesExceptionForUnnormalizableGetterValue(): void
    {
        // dtoToArray path (validateAndNormalizeToArray always uses reflection-based dtoToArray).
        // Before fix: catch (Throwable) silently wrote null. After fix: exception propagates.
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerDtoWithOpaqueGetter();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot normalize object of class/');
        $normalizer->validateAndNormalizeToArray($dto);
    }

    public function testNullableDateFieldEmptyStringSkipsConstraintValidation(): void
    {
        // normalizeNullableTemporalValue in validateDtoRecursive converts '' → null
        // so empty string on nullable date field does not trigger format:date error
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerNullableDateDto(createdAt: '');

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testNullableDateFieldEmptyStringNormalizedToNullViaReflectionPath(): void
    {
        // validateAndNormalizeToArray uses dtoToArray (reflection path), not tryFastArray,
        // so normalizeNullableTemporalValue converts '' → null there too
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerNullableDateDto(createdAt: '');

        $payload = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertNull($payload['createdAt']);
    }

    public function testNullableDateFieldNonEmptyStringPreserved(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerNullableDateDto(createdAt: '2024-06-15');

        $errors = $normalizer->validate($dto);
        $this->assertSame([], $errors);

        $payload = $normalizer->validateAndNormalizeToArray($dto);
        $this->assertSame('2024-06-15', $payload['createdAt']);
    }

    public function testToArrayNormalizesArrayOfNestedDtos(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerListResponseDto(items: [
            new NormalizerItemDto(id: 1, label: 'first'),
            new NormalizerItemDto(id: 2, label: 'second'),
        ]);

        $payload = $normalizer->toArray($dto);

        $this->assertIsArray($payload['items']);
        $this->assertCount(2, $payload['items']);
        $this->assertSame(1, $payload['items'][0]['id']);
        $this->assertSame('second', $payload['items'][1]['label']);
    }

    public function testValidateCollectsConstraintErrorsOnNestedDtoInArray(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerListResponseDto(items: [
            new NormalizerItemDto(id: 1, label: 'ok'),
            new NormalizerItemDto(id: -1, label: 'bad'),
        ]);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            array_reduce($errors, fn(bool $carry, string $e) => $carry || str_contains($e, 'items.1'), false),
            'Expected error path containing items.1',
        );
    }

    public function testValidate_circularReference_doesNotInfiniteLoop(): void
    {
        $normalizer = new DtoNormalizer();
        $a = new NormalizerCircularA();
        $b = new NormalizerCircularB();
        $a->child = $b;
        $b->parent = $a;

        // Must not throw / infinite loop — guard returns [] on revisit
        $errors = $normalizer->validate($a);
        $this->assertIsArray($errors);
    }

    public function testNormalizeFileValue_plainFile_hasFilenameWithoutClientFields(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerFileDto(new File('/tmp/normalizer-test.txt', false));

        $result = $normalizer->toArray($dto);

        $this->assertIsArray($result['file']);
        $this->assertArrayHasKey('filename', $result['file']);
        $this->assertArrayHasKey('mimeType', $result['file']);
        $this->assertArrayHasKey('size', $result['file']);
        $this->assertArrayNotHasKey('originalName', $result['file']);
        $this->assertArrayNotHasKey('clientMimeType', $result['file']);
        $this->assertSame('normalizer-test.txt', $result['file']['filename']);
    }

    public function testNormalizeFileValue_uploadedFile_includesClientFields(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'dto_normalizer_test_');
        $this->assertNotFalse($tmpPath);

        try {
            $normalizer = new DtoNormalizer();
            $dto = new NormalizerFileDto(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', null, true));

            $result = $normalizer->toArray($dto);

            $this->assertIsArray($result['file']);
            $this->assertArrayHasKey('filename', $result['file']);
            $this->assertArrayHasKey('originalName', $result['file']);
            $this->assertArrayHasKey('clientMimeType', $result['file']);
            $this->assertSame('upload.txt', $result['file']['originalName']);
            $this->assertSame('text/plain', $result['file']['clientMimeType']);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function testWriteOnlyField_excludedFromValidateAndNormalizeToArray(): void
    {
        // validateAndNormalizeToArray() uses dtoToArray() which checks writeOnly from normalization map
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerWriteOnlyDto(name: 'Alice', password: 'secret');

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Alice', $result['name']);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testWriteOnlyField_excludedFromGeneratedToArray(): void
    {
        // Simulates what generator produces: toArray() itself omits writeOnly fields
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerWriteOnlyGeneratedDto(name: 'Alice', password: 'secret');

        $result = $normalizer->toArray($dto);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Alice', $result['name']);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testNestedDtoWhoseToArrayThrowsNotProvided_fallsBackToReflectionNotJsonSerialize(): void
    {
        // Nested DTO's toArray() throws "wasn't provided in request".
        // jsonSerialize() in generated DTOs delegates to toArray() and would re-throw uncaught.
        // normalizeValue must fall through to reflection-based dtoToArray(), not JsonSerializable.
        $normalizer = new DtoNormalizer();
        $dto = new NormalizerOuterWithThrowingInnerDto(
            inner: new NormalizerInnerThrowingToArrayDto(label: 'kept'),
        );

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertArrayHasKey('inner', $result);
        $this->assertSame(['label' => 'kept'], $result['inner']);
    }

    public function testArrayItemTypeResolvedFromMapWithoutGetterDocblock(): void
    {
        // The getter has NO @return docblock, so reflection yields no item type — the
        // normalizer must take it from the map's metadata.arrayItemType ('array<int>') and
        // still type-check the items. A string item must therefore be rejected.
        $normalizer = new DtoNormalizer();

        $errors = $normalizer->validate(new NormalizerMapItemTypeDto(['ok' => 1, 'bad' => 'x']));
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('must return int', implode(' | ', $errors));
    }

    public function testEmptyArrayItemTypeInMapSuppressesReflection(): void
    {
        // The getter HAS a "@return array<int>" docblock, but the map carries arrayItemType ''
        // (a non-array field shape). Presence of the key means the map is authoritative, so the
        // docblock is NOT reflected — a string item is therefore NOT type-checked/rejected.
        $normalizer = new DtoNormalizer();

        $this->assertSame([], $normalizer->validate(new NormalizerEmptyItemTypeDto(['x'])));
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
        array|UnsetValue|null $availableFilters = UnsetValue::UNSET,
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
        array|UnsetValue|null $availableFilters = UnsetValue::UNSET,
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
    public function __construct(private readonly DateTimeImmutable $createdAt)
    {
    }

    public function getCreatedAt(): DateTimeImmutable
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
        return ['id' => $this->id, 'label' => $this->label];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
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

final class NormalizerDtoWithThrowingToArray implements GeneratedDtoInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        throw new LogicException('toArray exploded');
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

// DTO with a nullable string field typed as date — tests normalizeNullableTemporalValue
final class NormalizerNullableDateDto implements GeneratedDtoInterface
{
    public function __construct(private readonly ?string $createdAt)
    {
    }

    public function getCreatedAt(): ?string
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
        return [
            'createdAt' => [
                'getter' => 'getCreatedAt',
                'type' => 'string|null',
                'nullable' => true,
                'metadata' => ['openApiName' => 'createdAt'],
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
        return [
            'createdAt' => ['format' => 'date'],
        ];
    }
}

final class NormalizerItemDto implements GeneratedDtoInterface
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
        return ['id' => $this->id, 'label' => $this->label];
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
            'id' => ['getter' => 'getId', 'type' => 'int', 'nullable' => false, 'metadata' => ['openApiName' => 'id']],
            'label' => ['getter' => 'getLabel', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'label']],
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
            'id' => ['minimum' => 1],
        ];
    }
}

final class NormalizerListResponseDto implements GeneratedDtoInterface
{
    /** @param list<NormalizerItemDto> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @return list<NormalizerItemDto> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items];
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
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items']],
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

final class NormalizerFileDto implements GeneratedDtoInterface
{
    public function __construct(private readonly File $file)
    {
    }

    public function getFile(): File
    {
        return $this->file;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['file' => $this->file];
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

final class NormalizerWriteOnlyDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $password,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'password' => $this->password];
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
            'name' => ['getter' => 'getName', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'name', 'writeOnly' => false]],
            'password' => ['getter' => 'getPassword', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'password', 'writeOnly' => true]],
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

final class NormalizerWriteOnlyGeneratedDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $password,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // writeOnly field 'password' is excluded — simulates generator output
        return ['name' => $this->name];
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
            'name' => ['getter' => 'getName', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'name', 'writeOnly' => false]],
            'password' => ['getter' => 'getPassword', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'password', 'writeOnly' => true]],
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

final class NormalizerCircularA implements GeneratedDtoInterface
{
    public ?NormalizerCircularB $child = null;

    public function getChild(): ?NormalizerCircularB
    {
        return $this->child;
    }

    public function toArray(): array
    {
        return ['child' => $this->child];
    }
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
    public function toJson(): string
    {
        return json_encode($this) ?: '{}';
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

final class NormalizerCircularB implements GeneratedDtoInterface
{
    public ?NormalizerCircularA $parent = null;

    public function getParent(): ?NormalizerCircularA
    {
        return $this->parent;
    }

    public function toArray(): array
    {
        return ['parent' => $this->parent];
    }
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
    public function toJson(): string
    {
        return json_encode($this) ?: '{}';
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

// anyOf: value <= 5 OR >= 100
final class NormalizerAnyOfConstraintDto implements GeneratedDtoInterface
{
    public function __construct(private readonly int $value)
    {
    }
    public function getValue(): int
    {
        return $this->value;
    }
    public function toArray(): array
    {
        return ['value' => $this->value];
    }
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
    public static function getNormalizationMap(): array
    {
        return ['value' => ['getter' => 'getValue', 'type' => 'int', 'nullable' => false, 'metadata' => ['openApiName' => 'value']]];
    }
    public static function getAliases(): array
    {
        return [];
    }
    public static function getConstraints(): array
    {
        return ['value' => ['anyOf' => [['maximum' => 5], ['minimum' => 100]]]];
    }
}

// oneOf: value >= 100 (only) OR value <= 5 (only) — 50 matches none strictly, but 150 only >= 100
final class NormalizerOneOfConstraintDto implements GeneratedDtoInterface
{
    public function __construct(private readonly int $value)
    {
    }
    public function getValue(): int
    {
        return $this->value;
    }
    public function toArray(): array
    {
        return ['value' => $this->value];
    }
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
    public static function getNormalizationMap(): array
    {
        return ['value' => ['getter' => 'getValue', 'type' => 'int', 'nullable' => false, 'metadata' => ['openApiName' => 'value']]];
    }
    public static function getAliases(): array
    {
        return [];
    }
    public static function getConstraints(): array
    {
        // branches overlap: value 50 matches both (>= 1 and <= 100)
        // value 150 matches only branch 1 (>= 1, not <= 100)
        return ['value' => ['oneOf' => [['minimum' => 1], ['maximum' => 100]]]];
    }
}

final class NormalizerThrowingGetterDto implements GeneratedDtoInterface
{
    public function getExploding(): string
    {
        throw new RuntimeException('database is down');
    }

    public function toArray(): array
    {
        return [];
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

final class NormalizerEmptyMapDto implements GeneratedDtoInterface
{
    public function toArray(): array
    {
        return [];
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
 * Inner DTO whose toArray() throws the "wasn't provided in request" sentinel,
 * and whose jsonSerialize() delegates to toArray() (as generated DTOs do).
 * Reflection getter getLabel() still works for the dtoToArray() fallback.
 */
final class NormalizerInnerThrowingToArrayDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly string $label,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        throw new LogicException('Field ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
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
            'label' => [
                'getter' => 'getLabel',
                'type' => 'string',
                'nullable' => false,
                'metadata' => ['openApiName' => 'label'],
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

final class NormalizerOuterWithThrowingInnerDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly NormalizerInnerThrowingToArrayDto $inner,
    ) {
    }

    public function getInner(): NormalizerInnerThrowingToArrayDto
    {
        return $this->inner;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['inner' => $this->inner];
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
            'inner' => [
                'getter' => 'getInner',
                'type' => NormalizerInnerThrowingToArrayDto::class,
                'nullable' => false,
                'metadata' => ['openApiName' => 'inner'],
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

final class NormalizerMapItemTypeDto implements GeneratedDtoInterface
{
    /** @param array<string, int> $items */
    public function __construct(private array $items)
    {
    }

    // Intentionally NO @return docblock: the array item type must come from the map.
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items];
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
                'metadata' => [
                    'openApiName' => 'items',
                    'required' => true,
                    'writeOnly' => false,
                    'readOnly' => false,
                    'arrayItemType' => 'array<int>',
                ],
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

final class NormalizerEmptyItemTypeDto implements GeneratedDtoInterface
{
    /** @param array<int, string> $items */
    public function __construct(private array $items)
    {
    }

    /** @return array<int> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items];
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
                'metadata' => [
                    'openApiName' => 'items',
                    'required' => true,
                    'writeOnly' => false,
                    'readOnly' => false,
                    'arrayItemType' => '',
                ],
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
