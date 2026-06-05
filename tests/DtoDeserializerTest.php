<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class DtoDeserializerTest extends TestCase
{
    private DtoDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new DtoDeserializer();
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

    public function testDeserializeJsonBodyWithCharsetInContentType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'héllo wörld 🌍',
        ]));
        $request->headers->set('Content-Type', 'application/json; charset=utf-8');

        $dto = $this->deserializer->deserialize($request, SimpleTestDto::class);

        $this->assertSame(1, $dto->id);
        $this->assertSame('héllo wörld 🌍', $dto->name);
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

    public function testDeserializeDateTimeRejectsRelativeStrings(): void
    {
        foreach (['now', '+1 year', 'yesterday', 'next Monday', 'tomorrow'] as $relative) {
            $request = new Request([], [], [], [], [], [], json_encode([
                'id' => 1,
                'createdAt' => $relative,
            ]));
            $request->headers->set('Content-Type', 'application/json');

            try {
                $this->deserializer->deserialize($request, DateTimeDto::class);
                $this->fail("Expected exception for relative date string '{$relative}' but none thrown");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('createdAt', $e->getMessage());
            }
        }
    }

    public function testDeserializeDateTimeRejectsEmptyString(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'createdAt' => '',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('createdAt');
        $this->expectExceptionMessage('empty string');

        $this->deserializer->deserialize($request, DateTimeDto::class);
    }

    public function testDeserializeDateTimeRejectsGarbage(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'createdAt' => 'not-a-date',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('createdAt');

        $this->deserializer->deserialize($request, DateTimeDto::class);
    }

    public function testDeserializeDateOnlyFormat_acceptsValidDate(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'date' => '2024-06-15',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DateOnlyDto::class);

        $this->assertSame('2024-06-15', $dto->date->format('Y-m-d'));
    }

    public function testDeserializeDateOnlyFormat_rejectsDateTime(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'date' => '2024-06-15T12:00:00+00:00',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('date');
        $this->expectExceptionMessage('Y-m-d format');

        $this->deserializer->deserialize($request, DateOnlyDto::class);
    }

    public function testDeserializeDateOnlyFormat_rejectsInvalidDate(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'date' => '2024-13-45',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('date');
        $this->expectExceptionMessage('Y-m-d format');

        $this->deserializer->deserialize($request, DateOnlyDto::class);
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

    public function testDeserializeBoolFromQueryStringRecognizedValues(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'YES'] as $trueVal) {
            $request = new Request(['enabled' => $trueVal, 'verified' => 'false']);
            $dto = $this->deserializer->deserialize($request, BooleanDto::class);
            $this->assertTrue($dto->enabled, "Expected true for query value '{$trueVal}'");
        }

        foreach (['0', 'false', 'no', 'off', '', 'FALSE', 'NO'] as $falseVal) {
            $request = new Request(['enabled' => 'true', 'verified' => $falseVal]);
            $dto = $this->deserializer->deserialize($request, BooleanDto::class);
            $this->assertFalse($dto->verified, "Expected false for query value '{$falseVal}'");
        }
    }

    public function testDeserializeBoolFromQueryStringThrowsForInvalidValue(): void
    {
        $request = new Request(['enabled' => 'oops', 'verified' => 'false']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expects bool/');
        $this->deserializer->deserialize($request, BooleanDto::class);
    }

    public function testDeserializeThrowsExceptionForInvalidJsonScalarTypes(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'test',
            'name' => 1,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int, got string');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeThrowsRuntimeExceptionForMalformedJson(): void
    {
        // Malformed JSON is user-controlled input → must be RuntimeException (→ 400), not
        // InvalidArgumentException (extends LogicException → would escape RuntimeException catch → 500).
        $request = new Request([], [], [], [], [], [], '{ bad json');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Json is not valid');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeThrowsRuntimeExceptionForJsonArrayBody(): void
    {
        // JSON array (not object) as body root — user-controlled → RuntimeException, not 500.
        $request = new Request([], [], [], [], [], [], json_encode([1, 2, 3]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON body must be an object');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeThrowsExceptionForMissingRequiredParameter(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Test',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
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
        $this->assertTrue($dto->isUserIdInPath());
        $this->assertTrue($dto->isPageInQuery());
        $this->assertFalse($dto->isLimitInQuery());
    }

    public function testDeserializeUsesPHPDefaultForMissingNullableField(): void
    {
        $request = new Request(['page' => '5'], [], ['userId' => '10'], [], [], []);

        $dto = $this->deserializer->deserialize($request, DefaultValueMixedDto::class);

        $this->assertInstanceOf(DefaultValueMixedDto::class, $dto);
        $this->assertSame(10, $dto->getLimit());
        $this->assertFalse($dto->isLimitInRequest());
    }

    public function testDeserializeThrowsExceptionWhenObjectPassedInsteadOfArray(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 1,
            'name' => 'name',
            'tags' => ['id' => 1, 'name' => 'tag1'], // JSON object, not array
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "param \"tags.1.id\" expects int, got string\nparam \"tags.2.name\" expects string, got int",
        );

        $this->deserializer->deserialize($request, NestedArrayDto::class);
    }

    public function testDeserializeThrowsExceptionForInvalidNestedArrayAtDepth3(): void
    {
        // depth: OuterDto.items[0].tags[0].id — path must be fully qualified
        $request = new Request([], [], [], [], [], [], json_encode([
            'items' => [
                [
                    'tags' => [
                        ['id' => 'bad', 'label' => 'ok'],
                    ],
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "items.0.tags.0.id" expects int, got string');

        $this->deserializer->deserialize($request, DepthOuterDto::class);
    }

    public function testDeserializeAllErrorPathsQualifiedForMultipleFieldsInDepth3Item(): void
    {
        // Single depth-3 item with TWO invalid fields — both paths must get full prefix.
        // Old prependParamPath (str_replace on first match) would leave second path un-prefixed.
        $request = new Request([], [], [], [], [], [], json_encode([
            'items' => [
                [
                    'tags' => [
                        ['id' => 'bad', 'label' => 999],
                    ],
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        try {
            $this->deserializer->deserialize($request, DepthOuterDto::class);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('param "items.0.tags.0.id" expects int', $e->getMessage());
            $this->assertStringContainsString('param "items.0.tags.0.label" expects string', $e->getMessage());
        }
    }

    public function testDeserializeDepth3AcceptsValidData(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'items' => [
                ['tags' => [['id' => 1, 'label' => 'a'], ['id' => 2, 'label' => 'b']]],
                ['tags' => [['id' => 3, 'label' => 'c']]],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DepthOuterDto::class);

        $this->assertCount(2, $dto->getItems());
        $this->assertCount(2, $dto->getItems()[0]->getTags());
        $this->assertSame(1, $dto->getItems()[0]->getTags()[0]->id);
        $this->assertSame('c', $dto->getItems()[1]->getTags()[0]->label);
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'param "animal.animalType" has invalid discriminator value "bird". Allowed: dog, cat',
        );

        $this->deserializer->deserialize($request, DiscriminatorWrapperDto::class);
    }

    public function testDeserializeResolvesDiscriminatorSubtypeInArrayItems(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animals' => [
                ['animalType' => 'dog', 'bark' => 'woof'],
                ['animalType' => 'cat', 'meow' => 'purr'],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DiscriminatorArrayWrapperDto::class);

        $this->assertCount(2, $dto->getAnimals());
        $this->assertInstanceOf(DiscriminatorDogDto::class, $dto->getAnimals()[0]);
        $this->assertInstanceOf(DiscriminatorCatDto::class, $dto->getAnimals()[1]);
        $this->assertSame('dog', $dto->getAnimals()[0]->getAnimalType()->value);
        $this->assertSame('cat', $dto->getAnimals()[1]->getAnimalType()->value);
    }

    public function testDeserializeDiscriminatorArrayAllSameSubtype(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animals' => [
                ['animalType' => 'dog', 'bark' => 'woof'],
                ['animalType' => 'dog', 'bark' => 'arf'],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DiscriminatorArrayWrapperDto::class);

        $this->assertCount(2, $dto->getAnimals());
        $this->assertInstanceOf(DiscriminatorDogDto::class, $dto->getAnimals()[0]);
        $this->assertInstanceOf(DiscriminatorDogDto::class, $dto->getAnimals()[1]);
    }

    public function testDeserializeThrowsExceptionForInvalidDiscriminatorValueInArrayItem(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animals' => [
                ['animalType' => 'dog', 'bark' => 'woof'],
                ['animalType' => 'bird'],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'param "animals.1.animalType" has invalid discriminator value "bird". Allowed: dog, cat',
        );

        $this->deserializer->deserialize($request, DiscriminatorArrayWrapperDto::class);
    }

    public function testDeserializeThrowsExceptionForUnknownArrayItemType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'items' => [['foo' => 'bar']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown type');

        $this->deserializer->deserialize($request, UnknownItemTypeDto::class);
    }

    public function testDeserializeThrowsExceptionForInvalidEnumValue(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animalType' => 'bird',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
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

        $this->expectException(RuntimeException::class);
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects string, got bool');

        $this->deserializer->deserialize($request, UnionIdDto::class);
    }

    public function testDeserializeFloatFromJsonBody(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'price' => 9.99,
            'discount' => 2,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, FloatFieldDto::class);

        $this->assertSame(9.99, $dto->price);
        $this->assertSame(2.0, $dto->discount);
    }

    public function testDeserializeThrowsForFloatFieldReceivingString(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'price' => 'not-a-number',
            'discount' => 0,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "price" expects float, got string');

        $this->deserializer->deserialize($request, FloatFieldDto::class);
    }

    public function testDeserializeExclusiveMaximumNumericConstraint(): void
    {
        // OpenAPI 3.1 style: exclusiveMaximum is the exclusive upper bound value itself
        $request = new Request([], [], [], [], [], [], json_encode(['score' => 100]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be less than 100');

        $this->deserializer->deserialize($request, ExclusiveMaxDto::class);
    }

    public function testDeserializeExclusiveMaximumBooleanConstraint(): void
    {
        // OpenAPI 3.0 style: maximum + exclusiveMaximum: true
        $request = new Request([], [], [], [], [], [], json_encode(['rating' => 5]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be less than 5');

        $this->deserializer->deserialize($request, ExclusiveMaxBoolDto::class);
    }

    public function testDeserializeAcceptsExplicitNullForSchemaNullableField(): void
    {
        // nullable: true in schema + isXxxRequired() = true → explicit null in JSON is valid
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => null,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, SchemaNullableDto::class);

        $this->assertNull($dto->getName());
    }

    public function testDeserializeThrowsWhenRequiredNullableFieldIsOmitted(): void
    {
        // nullable: true in schema + isXxxRequired() = true → the key MUST be present;
        // omitting it entirely is an error even though the PHP type is nullable.
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "name" not found in request');

        $this->deserializer->deserialize($request, SchemaNullableDto::class);
    }

    public function testDeserializeRejectsExplicitNullForNonNullableJsonField(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => null,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int, got null');

        $this->deserializer->deserialize($request, SimpleTestDto::class);
    }

    public function testDeserializeOas31ArrayTypeNullable_acceptsExplicitNull(): void
    {
        // OpenAPI 3.1: type: [string, null] in getConstraints() — no 'nullable' key
        $request = new Request([], [], [], [], [], [], json_encode(['name' => null]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, Oas31NullableDto::class);

        $this->assertNull($dto->getName());
    }

    public function testDeserializeOas31ArrayTypeNullable_acceptsStringValue(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['name' => 'hello']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, Oas31NullableDto::class);

        $this->assertSame('hello', $dto->getName());
    }

    public function testDeserializeFromFormData(): void
    {
        // Form-encoded POST: values are in $request->request (not $request->query or JSON body)
        $request = new Request([], ['username' => 'alice', 'age' => '30'], [], [], [], []);

        $dto = $this->deserializer->deserialize($request, FormDataDto::class);

        $this->assertSame('alice', $dto->username);
        $this->assertSame(30, $dto->age);
    }

    public function testDeserializeUnitEnumByName(): void
    {
        // UnitEnum (non-backed): matched by case name
        $request = new Request([], [], [], [], [], [], json_encode([
            'direction' => 'NORTH',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, DirectionDto::class);

        $this->assertSame(DirectionEnum::NORTH, $dto->direction);
    }

    public function testDeserializeIntBackedEnum(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'priority' => 2,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, PriorityDto::class);

        $this->assertSame(PriorityEnum::HIGH, $dto->priority);
    }

    public function testDeserializeNestedDtoFromJsonBody(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'user' => ['id' => 5, 'name' => 'Bob'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, WrapperDto::class);

        $this->assertInstanceOf(SimpleTestDto::class, $dto->user);
        $this->assertSame(5, $dto->user->id);
        $this->assertSame('Bob', $dto->user->name);
    }

    public function testIsRequiredDetectionIgnoresCommentContainingReturnTrue(): void
    {
        // isTitleRequired() body has "return true;" only in a comment → field must be treated as optional
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, RequiredFlagCommentFalsePositiveDto::class);

        $this->assertNull($dto->getTitle());
        $this->assertFalse($dto->isTitleInRequest());
    }

    public function testIsRequiredDetectionIgnoresDeadBranchReturnTrue(): void
    {
        // isTitleRequired() has `if (false) { return true; }` → field must be optional
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, RequiredFlagDeadBranchFalsePositiveDto::class);

        $this->assertNull($dto->getTitle());
        $this->assertFalse($dto->isTitleInRequest());
    }

    public function testDeserializeFloatFromQueryString(): void
    {
        $request = new Request(['price' => '9.99', 'discount' => '2.0']);

        $dto = $this->deserializer->deserialize($request, FloatFieldDto::class);

        $this->assertSame(9.99, $dto->price);
        $this->assertSame(2.0, $dto->discount);
    }

    public function testDeserializeFloatIntegerStringFromQueryString(): void
    {
        // Query string "2" → float field → (float)"2" = 2.0
        $request = new Request(['price' => '3', 'discount' => '1']);

        $dto = $this->deserializer->deserialize($request, FloatFieldDto::class);

        $this->assertSame(3.0, $dto->price);
        $this->assertSame(1.0, $dto->discount);
    }

    public function testDeserializeEmptyJsonBodyWithAllOptionalFields(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, AllOptionalDto::class);

        $this->assertNull($dto->name);
        $this->assertNull($dto->score);
    }

    public function testDeserializePathParamTakesPriorityOverJsonBody(): void
    {
        // Path param 'userId' must win over JSON body key 'userId'
        $request = new Request([], [], ['userId' => '99'], [], [], [], json_encode(['userId' => 1, 'name' => 'Alice']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, PathBodyConflictDto::class);

        $this->assertSame(99, $dto->userId);
        $this->assertSame('Alice', $dto->name);
    }

    public function testDeserializeScalarForArrayQueryParam_throwsException(): void
    {
        // Symfony returns scalar string for ?tags=only-one — deserializer must reject, not wrap
        $request = new Request(['tags' => 'only-one']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tags');
        $this->expectExceptionMessage('array');

        $this->deserializer->deserialize($request, ScalarAsArrayDto::class);
    }

    public function testDeserializeArrayQueryParam_acceptsActualArray(): void
    {
        // Symfony returns array for ?tags[]=a&tags[]=b
        $request = new Request(['tags' => ['a', 'b']]);

        $dto = $this->deserializer->deserialize($request, ScalarAsArrayDto::class);

        $this->assertSame(['a', 'b'], $dto->tags);
    }

    public function testDeserializeValidatesObjectConstraintsViaValidator(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'meta' => ['name' => 'Alice', 'extra' => 'forbidden'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"extra"');
        $this->expectExceptionMessage('not allowed');

        $this->deserializer->deserialize($request, ObjectConstraintsDto::class);
    }

    public function testDeserializeValidatesMinMaxPropertiesViaValidator(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'meta' => [],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must have at least 1 property');

        $this->deserializer->deserialize($request, MinPropertiesDto::class);
    }

    public function testDeserializeIgnoresReadOnlyFieldFromRequest(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Alice',
            'id' => 999,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, ReadOnlyFieldDto::class);

        $this->assertSame('Alice', $dto->name);
        // readOnly field — not read from request, uses default
        $this->assertNull($dto->id);
    }

    public function testDeserializeReadOnlyFieldUsesDefaultWhenProvided(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Bob',
            'id' => 42,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, ReadOnlyFieldWithDefaultDto::class);

        $this->assertSame('Bob', $dto->name);
        // readOnly field — client cannot override it, default value preserved
        $this->assertSame(0, $dto->id);
    }

    public function testDeserializeMultipartArrayOfFiles(): void
    {
        $tmp1 = tempnam(sys_get_temp_dir(), 'dto_file_');
        $tmp2 = tempnam(sys_get_temp_dir(), 'dto_file_');
        $this->assertNotFalse($tmp1);
        $this->assertNotFalse($tmp2);

        try {
            $file1 = new UploadedFile($tmp1, 'photo1.jpg', 'image/jpeg', null, true);
            $file2 = new UploadedFile($tmp2, 'photo2.jpg', 'image/jpeg', null, true);

            $request = new Request([], [], [], [], ['photos' => [$file1, $file2]]);

            $dto = $this->deserializer->deserialize($request, MultipartFilesDto::class);

            $this->assertCount(2, $dto->getPhotos());
            $this->assertSame($file1, $dto->getPhotos()[0]);
            $this->assertSame($file2, $dto->getPhotos()[1]);
        } finally {
            @unlink($tmp1);
            @unlink($tmp2);
        }
    }

    public function testDeserializeMultipartArrayOfFiles_rejectsNonUploadedFile(): void
    {
        $request = new Request([], [], [], [], ['photos' => ['not-a-file', 'also-not']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('photos.0');

        $this->deserializer->deserialize($request, MultipartFilesDto::class);
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

final class DateOnlyDto
{
    public function __construct(
        public DateTimeImmutable $date,
    ) {
    }

    public static function getConstraints(): array
    {
        return ['date' => ['format' => 'date']];
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
    private bool $userIdInPath = false;
    private bool $userIdInQuery = false;
    private int $page;
    private bool $pageWasProvidedInRequest = false;
    private bool $pageInPath = false;
    private bool $pageInQuery = false;
    private ?int $limit;
    private bool $limitWasProvidedInRequest = false;
    private bool $limitInPath = false;
    private bool $limitInQuery = false;

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

    public function isUserIdInPath(): bool
    {
        return $this->userIdInPath;
    }

    public function isPageInQuery(): bool
    {
        return $this->pageInQuery;
    }

    public function isLimitInQuery(): bool
    {
        return $this->limitInQuery;
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

final class DiscriminatorArrayWrapperDto
{
    /** @var array<DiscriminatorAnimalDto> */
    private array $animals;

    /** @param array<DiscriminatorAnimalDto> $animals */
    public function __construct(array $animals)
    {
        $this->animals = $animals;
    }

    /** @return array<DiscriminatorAnimalDto> */
    public function getAnimals(): array
    {
        return $this->animals;
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

final class FloatFieldDto
{
    public function __construct(
        public float $price,
        public float $discount,
    ) {
    }
}

final class ExclusiveMaxDto
{
    public function __construct(
        public int $score,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            // OpenAPI 3.1 numeric form: exclusiveMaximum is the exclusive upper bound
            'score' => ['exclusiveMaximum' => 100],
        ];
    }
}

final class ExclusiveMaxBoolDto
{
    public function __construct(
        public int $rating,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            // OpenAPI 3.0 boolean form: maximum + exclusiveMaximum: true
            'rating' => ['maximum' => 5, 'exclusiveMaximum' => true],
        ];
    }
}

final class SchemaNullableDto
{
    private ?string $name;

    public function __construct(?string $name)
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function isNameRequired(): bool
    {
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'name' => ['nullable' => true],
        ];
    }
}

final class FormDataDto
{
    public function __construct(
        public string $username,
        public int $age,
    ) {
    }
}

enum DirectionEnum
{
    case NORTH;
    case SOUTH;
    case EAST;
    case WEST;
}

final class DirectionDto
{
    public function __construct(
        public DirectionEnum $direction,
    ) {
    }
}

enum PriorityEnum: int
{
    case LOW = 1;
    case HIGH = 2;
    case CRITICAL = 3;
}

final class PriorityDto
{
    public function __construct(
        public PriorityEnum $priority,
    ) {
    }
}

final class WrapperDto
{
    public function __construct(
        public SimpleTestDto $user,
    ) {
    }
}

// DTO where isXRequired() returns true, but body contains "return true;" in a comment
// Old str_contains: false-positive (sees "return true;" in comment)
// New token-based: correctly detects NO top-level return true; → treats field as optional
final class RequiredFlagCommentFalsePositiveDto
{
    private bool $titleInRequest = false;

    public function __construct(
        private ?string $title = null,
    ) {
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function isTitleInRequest(): bool
    {
        return $this->titleInRequest;
    }

    public function isTitleRequired(): bool
    {
        // We do NOT return true; here — this comment used to trick str_contains
        return false;
    }
}

// DTO where isXRequired() has a dead-branch "return true;" inside if (false)
// Old str_contains: false-positive
// New token-based: correctly skips depth > 1 → treats field as not-required
final class RequiredFlagDeadBranchFalsePositiveDto
{
    private bool $titleInRequest = false;

    public function __construct(
        private ?string $title = null,
    ) {
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function isTitleInRequest(): bool
    {
        return $this->titleInRequest;
    }

    public function isTitleRequired(): bool
    {
        if (false) {
            return true;
        }
        return false;
    }
}

final readonly class AllOptionalDto
{
    public function __construct(
        public ?string $name = null,
        public ?int $score = null,
    ) {
    }
}

final readonly class PathBodyConflictDto
{
    public function __construct(
        public int $userId,
        public string $name,
    ) {
    }
}

final readonly class ScalarAsArrayDto
{
    /** @param array<string> $tags */
    public function __construct(
        public array $tags,
    ) {
    }
}

final readonly class ObjectConstraintsDto
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public array $meta,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'meta' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }
}

final readonly class MinPropertiesDto
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public array $meta,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'meta' => [
                'type' => 'object',
                'minProperties' => 1,
            ],
        ];
    }
}

// Depth-3 nested array DTOs
final readonly class DepthInnerDto
{
    public function __construct(
        public int $id,
        public string $label,
    ) {
    }
}

final class DepthMiddleDto
{
    /** @var array<DepthInnerDto> */
    private array $tags;

    /** @param array<DepthInnerDto> $tags */
    public function __construct(array $tags)
    {
        $this->tags = $tags;
    }

    /** @return array<DepthInnerDto> */
    public function getTags(): array
    {
        return $this->tags;
    }
}

final class DepthOuterDto
{
    /** @var array<DepthMiddleDto> */
    private array $items;

    /** @param array<DepthMiddleDto> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /** @return array<DepthMiddleDto> */
    public function getItems(): array
    {
        return $this->items;
    }
}

final class ReadOnlyFieldDto
{
    public function __construct(
        public string $name,
        public ?int $id = null,
    ) {
    }

    public static function getConstraints(): array
    {
        return [
            'id' => ['readOnly' => true],
        ];
    }
}

final class UnknownItemTypeDto
{
    public function __construct(
        /** @var array<NonExistentClass> */
        private array $items,
    ) {
    }

    public function getItems(): array
    {
        return $this->items;
    }
}

final class ReadOnlyFieldWithDefaultDto
{
    public function __construct(
        public string $name,
        public int $id = 0,
    ) {
    }

    public static function getConstraints(): array
    {
        return [
            'id' => ['readOnly' => true],
        ];
    }
}

final class MultipartFilesDto
{
    public function __construct(
        /** @var array<UploadedFile> */
        private array $photos,
    ) {
    }

    public function getPhotos(): array
    {
        return $this->photos;
    }
}

/** OpenAPI 3.1: type: [string, null] via getConstraints (no nullable key) */
final class Oas31NullableDto
{
    private ?string $name;

    public function __construct(?string $name)
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function isNameRequired(): bool
    {
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'name' => ['type' => ['string', 'null']],
        ];
    }
}
