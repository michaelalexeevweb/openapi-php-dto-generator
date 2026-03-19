<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\RequestDeserializerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestDeserializerServiceTest extends TestCase
{
    private RequestDeserializerService $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new RequestDeserializerService();
    }

    public function testDeserializeSimpleDto(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 123,
            'name' => 'Test User',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SimpleTestDto::class);

        $this->assertInstanceOf(SimpleTestDto::class, $dto);
        $this->assertSame(123, $dto->id);
        $this->assertSame('Test User', $dto->name);
    }

    public function testDeserializeWithQueryParameters(): void
    {
        $request = new Request(['page' => '5', 'limit' => '20'], [], [], [], [], []);

        $dto = $this->deserializer->deserialize($request, QueryParamsDto::class);

        $this->assertInstanceOf(QueryParamsDto::class, $dto);
        $this->assertSame(5, $dto->page);
        $this->assertSame(20, $dto->limit);
    }

    public function testDeserializeDoesNotFailForMissingOptionalQueryParameter(): void
    {
        $request = new Request(['page' => '5'], [], [], [], [], []);

        $dto = $this->deserializer->deserialize($request, OptionalQueryParamsDto::class);

        $this->assertInstanceOf(OptionalQueryParamsDto::class, $dto);
        $this->assertSame(5, $dto->getPage());
        $this->assertNull($dto->getLimit());
    }

    public function testDeserializeWithPathParameters(): void
    {
        $request = new Request([], [], ['userId' => '42', 'postId' => '7'], [], [], []);

        $dto = $this->deserializer->deserialize($request, PathParamsDto::class);

        $this->assertInstanceOf(PathParamsDto::class, $dto);
        $this->assertSame(42, $dto->userId);
        $this->assertSame(7, $dto->postId);
    }

    public function testDeserializeWithDateTimeImmutable(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'createdAt' => '2024-01-15T10:30:00+00:00',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DateTimeDto::class);

        $this->assertInstanceOf(DateTimeDto::class, $dto);
        $this->assertSame(1, $dto->id);
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->createdAt);
        $this->assertSame('2024-01-15', $dto->createdAt->format('Y-m-d'));
    }

    public function testDeserializeWithNullableField(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, NullableFieldDto::class);

        $this->assertInstanceOf(NullableFieldDto::class, $dto);
        $this->assertSame(1, $dto->id);
        $this->assertSame('Test', $dto->name);
        $this->assertNull($dto->description);
    }

    public function testDeserializeWithBoolean(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'enabled' => true,
            'verified' => false,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, BooleanDto::class);

        $this->assertInstanceOf(BooleanDto::class, $dto);
        $this->assertTrue($dto->enabled);
        $this->assertFalse($dto->verified);
    }

    public function testDeserializeThrowsExceptionForInvalidJsonScalarTypes(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'test',
            'name' => 1,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int, got string');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeThrowsExceptionForMissingRequiredParameter(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "id" not found in request');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeTracksProvidedFields(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, TrackableFieldsDto::class);

        $this->assertInstanceOf(TrackableFieldsDto::class, $dto);
        $this->assertTrue($dto->isIdInRequest());
        $this->assertTrue($dto->isNameInRequest());
        $this->assertFalse($dto->isDescriptionInRequest());
    }

    public function testDeserializeTracksQueryAndPathParameters(): void
    {
        $request = new Request(['page' => '5'], [], ['userId' => '10'], [], [], []);

        $dto = $this->deserializer->deserialize($request, TrackableMixedDto::class);

        $this->assertInstanceOf(TrackableMixedDto::class, $dto);
        $this->assertTrue($dto->isUserIdInRequest());
        $this->assertTrue($dto->isPageInRequest());
        $this->assertFalse($dto->isLimitInRequest());
    }

    public function testDeserializeUsesConstructorDefaultForMissingOptionalField(): void
    {
        $request = new Request(['page' => '5'], [], ['userId' => '10'], [], [], []);

        $dto = $this->deserializer->deserialize($request, DefaultValueMixedDto::class);

        $this->assertInstanceOf(DefaultValueMixedDto::class, $dto);
        $this->assertSame(10, $dto->getLimit());
        $this->assertTrue($dto->isLimitInRequest());
    }

    public function testDeserializeThrowsExceptionWhenObjectPassedInsteadOfArray(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'name',
            'tags' => ['id' => 1, 'name' => 'tag1'], // JSON object, not array
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('param "tags" expects array, got object');

        $this->deserializer->deserialize($request, NestedArrayDto::class);
    }

    public function testDeserializeThrowsExceptionForInvalidNestedArrayItems(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'name',
            'tags' => [
                ['id' => 1, 'name' => 'tag1'],
                ['id' => 'two', 'name' => 'tag2'],
                ['id' => 3, 'name' => 3],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "param \"tags.1.id\" expects int, got string\nparam \"tags.2.name\" expects string, got int",
        );

        $this->deserializer->deserialize($request, NestedArrayDto::class);
    }

    public function testDeserializeResolvesDiscriminatorSubtype(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => [
                'animalType' => 'dog',
                'bark' => 'woof',
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DiscriminatorWrapperDto::class);

        $this->assertInstanceOf(DiscriminatorWrapperDto::class, $dto);
        $this->assertInstanceOf(DiscriminatorDogDto::class, $dto->animal);
        $this->assertSame('dog', $dto->animal->getAnimalType()->value);
    }

    public function testDeserializeThrowsExceptionForInvalidDiscriminatorValue(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => [
                'animalType' => 'bird',
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'param "animal.animalType" has invalid discriminator value "bird". Allowed: dog, cat',
        );

        $this->deserializer->deserialize($request, DiscriminatorWrapperDto::class);
    }

    public function testDeserializeThrowsExceptionForInvalidEnumValue(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animalType' => 'bird',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('param "animalType" expects enum');

        $this->deserializer->deserialize($request, EnumOnlyAnimalDto::class);
    }

    public function testDeserializeValidatesNumericStringAndArrayConstraints(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'amount' => 1,
            'email' => 'bad-email',
            'code' => '12',
            'tags' => ['a', 'a'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "param \"amount\" must be greater than 1\nparam \"amount\" must be a multiple of 2.5\nparam \"email\" must match format email\nparam \"code\" length must be at least 3 characters\nparam \"code\" must match pattern ^\\d{3}-\\d{2}-\\d{4}$\nparam \"tags\" must contain unique items",
        );

        $this->deserializer->deserialize($request, ConstraintsDto::class);
    }

    public function testDeserializeAcceptsValidConstraintValues(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'amount' => 7.5,
            'email' => 'user@example.com',
            'code' => '123-45-6789',
            'tags' => ['a', 'b'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, ConstraintsDto::class);

        $this->assertInstanceOf(ConstraintsDto::class, $dto);
        $this->assertSame(7.5, $dto->amount);
    }

    public function testDeserializeSupportsUnionTypeIntegerBranch(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 10,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, UnionIdDto::class);

        $this->assertInstanceOf(UnionIdDto::class, $dto);
        $this->assertSame(10, $dto->id);
    }

    public function testDeserializeUsesOpenApiAliasForHyphenatedFieldName(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'test-process' => 'yes',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, AliasRequestDto::class);

        $this->assertInstanceOf(AliasRequestDto::class, $dto);
        $this->assertSame('yes', $dto->getTest_process());
        $this->assertTrue($dto->isTest_processInRequest());
    }

    public function testDeserializeMarksCamelCaseFlagForUppercaseSpecName(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'TEST_NAME' => 'test - name',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, UppercaseFlagRequestDto::class);

        $this->assertInstanceOf(UppercaseFlagRequestDto::class, $dto);
        $this->assertSame('test - name', $dto->getTEST_NAME());
        $this->assertTrue($dto->isTestNameInRequest());
    }

    public function testDeserializeKeepsAdditionalPropertiesMapValues(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'testMap' => [
                'test1' => 'value1',
                'test2' => 'value2',
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, AdditionalPropertiesRequestDto::class);

        $this->assertInstanceOf(AdditionalPropertiesRequestDto::class, $dto);
        $this->assertSame('value1', $dto->getTestMap()['test1']);
        $this->assertSame('value2', $dto->getTestMap()['test2']);
    }

    public function testDeserializeKeepsAdditionalPropertiesObjectMapKeys(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'namesById' => [
                '1' => [
                    'id' => 1,
                    'name1' => 'test1',
                    'flag1' => true,
                ],
                '2' => [
                    'id' => 2,
                    'name1' => 'test2',
                    'flag1' => false,
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, AdditionalPropertiesObjectMapRequestDto::class);

        $this->assertInstanceOf(AdditionalPropertiesObjectMapRequestDto::class, $dto);
        $map = $dto->getNamesById();
        $this->assertArrayHasKey('1', $map);
        $this->assertArrayHasKey('2', $map);
        $this->assertSame('test1', $map['1']->getName1());
        $this->assertSame('test2', $map['2']->getName1());
    }

    public function testDeserializeThrowsForUnionTypeWhenNoBranchMatches(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => true,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects string, got bool');

        $this->deserializer->deserialize($request, UnionIdDto::class);
    }
}

// Test DTOs
final class SimpleTestDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

final class QueryParamsDto
{
    public function __construct(
        public int $page,
        public int $limit,
    ) {
    }
}

final class OptionalQueryParamsDto
{
    public function __construct(
        private int $page,
        private ?int $limit,
    ) {
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function isPageRequired(): bool
    {
        return true;
    }

    public function isPageInQuery(): bool
    {
        return true;
    }

    public function isLimitRequired(): bool
    {
        return false;
    }

    public function isLimitInQuery(): bool
    {
        return true;
    }
}

final class PathParamsDto
{
    public function __construct(
        public int $userId,
        public int $postId,
    ) {
    }
}

final class DateTimeDto
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
    ) {
    }
}

final class NullableFieldDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
    ) {
    }
}

final class BooleanDto
{
    public function __construct(
        public bool $enabled,
        public bool $verified,
    ) {
    }
}

final class TrackableFieldsDto
{
    private int $id;
    private bool $idWasProvidedInRequest = false;
    private string $name;
    private bool $nameWasProvidedInRequest = false;
    private ?string $description;
    private bool $descriptionWasProvidedInRequest = false;

    public function __construct(
        int $id,
        string $name,
        ?string $description,
    ) {
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

    public function isIdInRequest(): bool
    {
        return $this->idWasProvidedInRequest;
    }

    public function markAsIdProvidedInRequest(): void
    {
        $this->idWasProvidedInRequest = true;
    }

    public function isNameInRequest(): bool
    {
        return $this->nameWasProvidedInRequest;
    }

    public function markAsNameProvidedInRequest(): void
    {
        $this->nameWasProvidedInRequest = true;
    }

    public function isDescriptionInRequest(): bool
    {
        return $this->descriptionWasProvidedInRequest;
    }

    public function markAsDescriptionProvidedInRequest(): void
    {
        $this->descriptionWasProvidedInRequest = true;
    }
}

final class TrackableMixedDto
{
    private int $userId;
    private bool $userIdWasProvidedInRequest = false;
    private int $page;
    private bool $pageWasProvidedInRequest = false;
    private ?int $limit;
    private bool $limitWasProvidedInRequest = false;

    public function __construct(
        int $userId,
        int $page,
        ?int $limit,
    ) {
        $this->userId = $userId;
        $this->page = $page;
        $this->limit = $limit;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function isUserIdInRequest(): bool
    {
        return $this->userIdWasProvidedInRequest;
    }

    public function markAsUserIdProvidedInRequest(): void
    {
        $this->userIdWasProvidedInRequest = true;
    }

    public function isPageInRequest(): bool
    {
        return $this->pageWasProvidedInRequest;
    }

    public function markAsPageProvidedInRequest(): void
    {
        $this->pageWasProvidedInRequest = true;
    }

    public function isLimitInRequest(): bool
    {
        return $this->limitWasProvidedInRequest;
    }

    public function markAsLimitProvidedInRequest(): void
    {
        $this->limitWasProvidedInRequest = true;
    }
}

final class NestedArrayDto
{
    /** @var array<NestedArrayItemDto> */
    private array $tags;

    /** @param array<NestedArrayItemDto> $tags */
    public function __construct(
        private int $id,
        private string $name,
        array $tags,
    ) {
        $this->tags = $tags;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<NestedArrayItemDto> */
    public function getTags(): array
    {
        return $this->tags;
    }
}

final class DefaultValueMixedDto
{
    private bool $limitWasProvidedInRequest = false;

    public function __construct(
        private int $userId,
        private int $page,
        private ?int $limit = 10,
    ) {
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function isLimitInRequest(): bool
    {
        return $this->limitWasProvidedInRequest;
    }

    public function markAsLimitProvidedInRequest(): void
    {
        $this->limitWasProvidedInRequest = true;
    }
}

final class NestedArrayItemDto
{
    public function __construct(
        private int $id,
        private string $name,
    ) {
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

enum DiscriminatorAnimalType: string
{
    case DOG = 'dog';
    case CAT = 'cat';
}

class DiscriminatorAnimalDto
{
    public function __construct(
        private DiscriminatorAnimalType $animalType,
    ) {
    }

    public function getAnimalType(): DiscriminatorAnimalType
    {
        return $this->animalType;
    }

    public static function getDiscriminatorPropertyName(): string
    {
        return 'animalType';
    }

    /** @return array<string, class-string> */
    public static function getDiscriminatorMapping(): array
    {
        return [
            'dog' => DiscriminatorDogDto::class,
            'cat' => DiscriminatorCatDto::class,
        ];
    }
}

final class DiscriminatorDogDto extends DiscriminatorAnimalDto
{
    public function __construct(
        DiscriminatorAnimalType $animalType,
        private string $bark,
    ) {
        parent::__construct($animalType);
    }
}

final class DiscriminatorCatDto extends DiscriminatorAnimalDto
{
    public function __construct(
        DiscriminatorAnimalType $animalType,
        private string $meow,
    ) {
        parent::__construct($animalType);
    }
}

final class DiscriminatorWrapperDto
{
    public function __construct(
        public DiscriminatorAnimalDto $animal,
    ) {
    }
}

final class EnumOnlyAnimalDto
{
    public function __construct(
        public DiscriminatorAnimalType $animalType,
    ) {
    }
}

final class ConstraintsDto
{
    /** @param array<string> $tags */
    public function __construct(
        public float $amount,
        public string $email,
        public string $code,
        public array $tags,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'amount' => [
                'minimum' => 1,
                'exclusiveMinimum' => true,
                'maximum' => 20,
                'multipleOf' => 2.5,
            ],
            'email' => [
                'format' => 'email',
            ],
            'code' => [
                'minLength' => 3,
                'maxLength' => 20,
                'pattern' => '^\d{3}-\d{2}-\d{4}$',
            ],
            'tags' => [
                'minItems' => 1,
                'maxItems' => 10,
                'uniqueItems' => true,
            ],
        ];
    }
}

final class UnionIdDto
{
    public function __construct(
        public string|int $id,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'id' => [
                'oneOf' => [
                    [
                        'type' => 'integer',
                        'minimum' => 10,
                        'maximum' => 100,
                    ],
                    [
                        'type' => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
        ];
    }
}

final class AliasRequestDto
{
    private bool $test_processInRequest = false;

    public function __construct(
        private string $test_process,
    ) {
    }

    public function getTest_process(): string
    {
        return $this->test_process;
    }

    public function isTest_processInRequest(): bool
    {
        return $this->test_processInRequest;
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return [
            'test_process' => 'test-process',
        ];
    }
}

final class UppercaseFlagRequestDto
{
    private bool $testNameInRequest = false;

    public function __construct(
        private string $TEST_NAME,
    ) {
    }

    public function getTEST_NAME(): string
    {
        return $this->TEST_NAME;
    }

    public function isTestNameInRequest(): bool
    {
        return $this->testNameInRequest;
    }
}

final class AdditionalPropertiesRequestDto
{
    /** @param array<string, string> $testMap */
    public function __construct(
        private array $testMap,
    ) {
    }

    /** @return array<string, string> */
    public function getTestMap(): array
    {
        return $this->testMap;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'testMap' => [
                'type' => 'object',
            ],
        ];
    }
}

final class AdditionalPropertiesObjectMapRequestDto
{
    /** @var array<NameByIdItemRequestDto> */
    private array $namesById;

    /** @param array<NameByIdItemRequestDto> $namesById */
    public function __construct(
        array $namesById,
    ) {
        $this->namesById = $namesById;
    }

    /** @return array<NameByIdItemRequestDto> */
    public function getNamesById(): array
    {
        return $this->namesById;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'namesById' => [
                'type' => 'object',
            ],
        ];
    }
}

final class NameByIdItemRequestDto
{
    public function __construct(
        private int $id,
        private string $name1,
        private bool $flag1,
    ) {
    }

    public function getName1(): string
    {
        return $this->name1;
    }
}
