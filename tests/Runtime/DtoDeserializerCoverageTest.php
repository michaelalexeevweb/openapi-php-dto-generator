<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class DtoDeserializerCoverageTest extends TestCase
{
    private DtoDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new DtoDeserializer();
    }

    public function testDeserializeThrowsForDtoWithoutConstructor(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no constructor');

        $this->deserializer->deserialize($request, CovNoConstructorDto::class);
    }

    public function testDeserializeReadOnlyNullableFieldUsesNull(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['name' => 'Alice', 'id' => 5]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovReadOnlyNullableDto::class);

        $this->assertSame('Alice', $dto->name);
        $this->assertNull($dto->id);
    }

    public function testDeserializeReadOnlyNonNullableNoDefaultThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['name' => 'Alice']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readOnly and non-nullable with no default value');

        $this->deserializer->deserialize($request, CovReadOnlyNonNullableDto::class);
    }

    public function testDeserializeUsesPredecodedBodyAttribute(): void
    {
        $request = new Request();
        $request->attributes->set('__opg_predecoded_body_data', ['id' => 7, 'name' => 'Pre']);

        $dto = $this->deserializer->deserialize($request, CovSimpleDto::class);

        $this->assertSame(7, $dto->id);
        $this->assertSame('Pre', $dto->name);
    }

    public function testDeserializeNonJsonContentTypeIgnoresBody(): void
    {
        // text/plain body must NOT be parsed as JSON; field falls back to query.
        $request = new Request(['id' => '9', 'name' => 'Q'], [], [], [], [], [], '{"id":1,"name":"body"}');
        $request->headers->set('Content-Type', 'text/plain');

        $dto = $this->deserializer->deserialize($request, CovSimpleDto::class);

        $this->assertSame(9, $dto->id);
        $this->assertSame('Q', $dto->name);
    }

    public function testDeserializeBodyCacheReusedForRepeatedReads(): void
    {
        // Two params both read from the same JSON body, exercising the cache hit path.
        $request = new Request([], [], [], [], [], [], json_encode(['id' => 3, 'name' => 'Cached']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovSimpleDto::class);
        $this->assertSame(3, $dto->id);
        $this->assertSame('Cached', $dto->name);
    }

    public function testDeserializeUnionWithDateTimeBranch(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['value' => '2024-05-01T08:00:00+00:00']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovUnionDateTimeDto::class);

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->value);
    }

    public function testDeserializeUnionWithDateTimeBranchFallsBackToInt(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['value' => 42]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovUnionDateTimeDto::class);

        $this->assertSame(42, $dto->value);
    }

    public function testDeserializeNullableUnionMissingReturnsNull(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovNullableUnionDto::class);

        $this->assertNull($dto->value);
    }

    public function testDeserializeRequiredUnionMissingThrows(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "value" not found in request');

        $this->deserializer->deserialize($request, CovUnionDateTimeDto::class);
    }

    public function testDeserializeRequiredNullableUnionMissingThrows(): void
    {
        // Union type is PHP-nullable, but isValueRequired() = true → omitting the key
        // must throw; nullability only allows an explicit null value, not absence.
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "value" not found in request');

        $this->deserializer->deserialize($request, CovRequiredNullableUnionDto::class);
    }

    public function testDeserializeNullableSingleTypeMissingReturnsNull(): void
    {
        // Single type, nullable, NOT required, no default → resolveRawRequestValue missing,
        // isRequired forced true via isXRequired() returning the field's nullability path.
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovNullableRequiredDto::class);

        $this->assertNull($dto->name);
    }

    public function testDeserializeUnsupportedParameterTypeThrows(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has unsupported type');

        $this->deserializer->deserialize($request, CovIntersectionTypeDto::class);
    }

    public function testDeserializeNoTypeHintParameterIsUnsupported(): void
    {
        // A constructor param with NO declared type → $paramType is null → neither NamedType
        // nor UnionType → "has unsupported type" throw (the else branch in buildDtoMeta).
        $request = new Request([], [], [], [], [], [], json_encode(['value' => 'x']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has unsupported type');

        $this->deserializer->deserialize($request, CovNoTypeHintDto::class);
    }

    public function testDeserializeMixedParameterPassesValueThrough(): void
    {
        // A `mixed` type (schema-less / free-form property) accepts any non-null value as-is.
        $request = new Request([], [], [], [], [], [], json_encode(['value' => 'anything']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovUntypedParamDto::class);
        $this->assertSame('anything', $dto->value);
    }

    public function testCastArrayItemValueAcceptsMixedItemType(): void
    {
        // array<mixed>: resolveArrayItemType whitelists 'mixed'; castArrayItemValue must accept
        // it (pass through) rather than throwing "unknown type" (resolver/caster parity).
        $method = new ReflectionMethod($this->deserializer, 'castArrayItemValue');
        $this->assertSame('x', $method->invoke($this->deserializer, 'x', 'mixed', 'p.0', 'json'));
        $this->assertSame(42, $method->invoke($this->deserializer, 42, 'mixed', 'p.1', 'json'));
    }

    public function testDateArrayItemsWithFormatDateAreDeserializedCorrectly(): void
    {
        // Regression: items: {format: date} must propagate to castArrayItemValue so that
        // date-only strings like "2024-01-15" are accepted. Before the fix, parseDateTimeStrict
        // received temporalFormat: null and always threw because "2024-01-15" has no time component.
        $body = json_encode(['dates' => ['2024-01-15', '2025-06-09']]);
        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovDateArrayDto::class);

        $this->assertCount(2, $dto->dates);
        $this->assertSame('2024-01-15', $dto->dates[0]->format('Y-m-d'));
        $this->assertSame('2025-06-09', $dto->dates[1]->format('Y-m-d'));
    }

    public function testDateTimeArrayItemsWithoutFormatAreDeserializedCorrectly(): void
    {
        // Without items format, array<DateTimeImmutable> must still accept full date-time strings.
        $body = json_encode(['dates' => ['2024-01-15T10:30:00+00:00']]);
        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovDateTimeArrayDto::class);

        $this->assertCount(1, $dto->dates);
        $this->assertSame('2024-01-15T10:30:00+00:00', $dto->dates[0]->format('c'));
    }

    public function testRequiredMethodThrowingReflectionFallsBackToTypeInference(): void
    {
        // is...Required is non-static and the class has required constructor args, so
        // newInstanceWithoutConstructor works; here the method is private → ReflectionException
        // path is hit and we fall back to PHP type inference (non-null, no default → required).
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "name" not found');

        $this->deserializer->deserialize($request, CovPrivateRequiredMethodDto::class);
    }

    public function testDeserializeJsonNullForNonNullableThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['id' => 1, 'name' => null]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "name" expects string, got null');

        $this->deserializer->deserialize($request, CovSimpleDto::class);
    }

    public function testDeserializeJsonBoolWrongTypeThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['flag' => 'notbool']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "flag" expects bool, got string');

        $this->deserializer->deserialize($request, CovBoolDto::class);
    }

    public function testDeserializeJsonObjectForArrayWithoutAssociativeThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['tags' => ['k' => 'v']]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "tags" expects array, got object');

        $this->deserializer->deserialize($request, CovListArrayDto::class);
    }

    public function testDeserializeAssociativeArrayObjectAccepted(): void
    {
        // type: object constraint → stdClass allowed and converted to associative array.
        $request = new Request([], [], [], [], [], [], json_encode(['map' => ['a' => 1, 'b' => 2]]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovAssocArrayDto::class);

        $this->assertSame(['a' => 1, 'b' => 2], $dto->map);
    }

    public function testDeserializeNullForNonNullableNonJsonSourceThrows(): void
    {
        // Path attribute carrying explicit null → source !== json → "Cannot cast null" branch.
        $request = new Request();
        $request->attributes->set('id', null);
        $request->attributes->set('name', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot cast null to non-nullable type int');

        $this->deserializer->deserialize($request, CovSimpleDto::class);
    }

    public function testDeserializeNullForNullableNonJsonSourceReturnsNull(): void
    {
        $request = new Request();
        $request->attributes->set('name', null);

        $dto = $this->deserializer->deserialize($request, CovNullableNameDto::class);

        $this->assertNull($dto->name);
    }

    public function testDeserializeStringFromArrayValueThrows(): void
    {
        // Path attribute carrying an array for a string param → string-cast guard throws.
        $request = new Request();
        $request->attributes->set('name', ['a', 'b']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "name" expects string');

        $this->deserializer->deserialize($request, CovNameOnlyDto::class);
    }

    public function testDeserializeBoolPassthroughFromNonJsonSource(): void
    {
        $request = new Request();
        $request->attributes->set('flag', true);

        $dto = $this->deserializer->deserialize($request, CovBoolDto::class);

        $this->assertTrue($dto->flag);
    }

    public function testDeserializeBoolFromNonStringNonBoolValue(): void
    {
        // Path attribute integer 1 → falls through to (bool) cast.
        $request = new Request();
        $request->attributes->set('flag', 1);

        $dto = $this->deserializer->deserialize($request, CovBoolDto::class);

        $this->assertTrue($dto->flag);
    }

    public function testDeserializeDateTimeFromExistingImmutableInstance(): void
    {
        $request = new Request();
        $request->attributes->set('value', new DateTimeImmutable('2024-01-01T00:00:00+00:00'));

        $dto = $this->deserializer->deserialize($request, CovDateTimeOnlyDto::class);

        $this->assertSame('2024-01-01', $dto->value->format('Y-m-d'));
    }

    public function testDeserializeDateTimeFromNonStringNonInstanceThrows(): void
    {
        $request = new Request();
        $request->attributes->set('value', 12345);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "value" expects a date string, got int');

        $this->deserializer->deserialize($request, CovDateTimeOnlyDto::class);
    }

    public function testDeserializeFileFromNonUploadedFileThrows(): void
    {
        $request = new Request();
        $request->attributes->set('file', 'not-a-file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected UploadedFile but got something else');

        $this->deserializer->deserialize($request, CovFileDto::class);
    }

    public function testDeserializeFileFromUploadedFileAccepted(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cov_file_');
        $this->assertNotFalse($tmp);
        try {
            $file = new UploadedFile($tmp, 'doc.txt', 'text/plain', null, true);
            $request = new Request([], [], [], [], ['file' => $file]);

            $dto = $this->deserializer->deserialize($request, CovFileDto::class);

            $this->assertSame($file, $dto->file);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDeserializeNestedDtoFromNonArrayThrows(): void
    {
        // String value where a nested DTO object is expected → "non-array value" throw.
        $request = new Request();
        $request->attributes->set('user', 'just-a-string');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot deserialize nested DTO');
        $this->expectExceptionMessage('from non-array value');

        $this->deserializer->deserialize($request, CovWrapperDto::class);
    }

    public function testDeserializeUnsupportedTypeNameThrows(): void
    {
        // Type "unknown" (resolveTypeKind 'unknown') for a non-existent class hint.
        $request = new Request();
        $request->attributes->set('value', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported type');

        $this->deserializer->deserialize($request, CovUnknownTypeDto::class);
    }

    public function testDeserializeArrayOfDtoWithNonArrayItemThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['items' => ['scalar-not-object']]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "items.0" expects object');

        $this->deserializer->deserialize($request, CovArrayOfDtoDto::class);
    }

    public function testDeserializeDateTimeRejectsValidStructureButUnparsableDate(): void
    {
        // Passes the structural regex (space separator + fractional seconds) but matches
        // none of the four supported createFromFormat patterns → final throw.
        $request = new Request([], [], [], [], [], [], json_encode(['value' => '2024-01-01 12:00:00.123']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects a valid date-time');

        $this->deserializer->deserialize($request, CovDateTimeOnlyDto::class);
    }

    public function testDeserializeTemporalFormatFromGetterDocblock(): void
    {
        // No openApiFormat constraint → resolveTemporalFormat reads "Expected format:" from getter.
        $request = new Request([], [], [], [], [], [], json_encode(['when' => '2024-06-15']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovDocblockFormatDto::class);

        $this->assertSame('2024-06-15', $dto->getWhen()->format('Y-m-d'));
    }

    public function testDeserializeTemporalFormatDocblockRejectsWrongFormat(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['when' => '2024-06-15T10:00:00+00:00']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Y-m-d format');

        $this->deserializer->deserialize($request, CovDocblockFormatDto::class);
    }

    public function testDeserializeArrayItemTypeFqcnWithBackslash(): void
    {
        // docblock array<\Fully\Qualified> → resolveArrayItemType returns it verbatim.
        $request = new Request([], [], [], [], [], [], json_encode(['items' => [['id' => 1, 'name' => 'a']]]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovFqcnArrayItemDto::class);

        $this->assertCount(1, $dto->getItems());
        $this->assertSame(1, $dto->getItems()[0]->id);
    }

    public function testDeserializeScalarArrayItemType(): void
    {
        // array<int> short scalar type → resolveArrayItemType returns 'int'.
        $request = new Request([], [], [], [], [], [], json_encode(['nums' => [1, 2, 3]]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovScalarArrayDto::class);

        $this->assertSame([1, 2, 3], $dto->nums);
    }

    public function testDeserializeUnitEnumInvalidValueReportsAllowedNames(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['direction' => 'UP']));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "direction" expects enum');
        $this->expectExceptionMessage('Allowed: NORTH, SOUTH');

        $this->deserializer->deserialize($request, CovUnitEnumDto::class);
    }

    public function testDeserializeIntFromQueryStringStrictParsing(): void
    {
        $request = new Request(['id' => '123', 'name' => 'x']);

        $dto = $this->deserializer->deserialize($request, CovSimpleDto::class);

        $this->assertSame(123, $dto->id);
    }

    public function testDeserializeIntFromQueryStringNonNumericThrows(): void
    {
        $request = new Request(['id' => 'abc', 'name' => 'x']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int');

        $this->deserializer->deserialize($request, CovSimpleDto::class);
    }

    public function testDeserializeFloatFromQueryStringNonNumericThrows(): void
    {
        $request = new Request(['rate' => 'not-a-float']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "rate" expects float');

        $this->deserializer->deserialize($request, CovFloatDto::class);
    }

    public function testDiscriminatorBaseWithInvalidMetadataThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => ['kind' => 'whatever'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid discriminator metadata');

        $this->deserializer->deserialize($request, CovBadDiscriminatorWrapperDto::class);
    }

    public function testDiscriminatorMissingPropertyThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => ['unrelated' => 'x'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "animal.kind" wasn\'t provided');

        $this->deserializer->deserialize($request, CovGoodDiscriminatorWrapperDto::class);
    }

    public function testDiscriminatorNonStringNonIntValueThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => ['kind' => ['nested' => 'arr']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects string|int discriminator value');

        $this->deserializer->deserialize($request, CovGoodDiscriminatorWrapperDto::class);
    }

    public function testDiscriminatorMappingToUnknownClassThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => ['kind' => 'ghost'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('points to unknown class');

        $this->deserializer->deserialize($request, CovGoodDiscriminatorWrapperDto::class);
    }

    public function testDiscriminatorMappingClassNotSubtypeThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animal' => ['kind' => 'stranger'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must extend or implement');

        $this->deserializer->deserialize($request, CovGoodDiscriminatorWrapperDto::class);
    }

    public function testDeserializeIntFromNativeIntPathAttribute(): void
    {
        // Path attribute carries a native int → isStrictIntValue is_int short-circuit.
        $request = new Request();
        $request->attributes->set('id', 77);
        $request->attributes->set('name', 'x');

        $dto = $this->deserializer->deserialize($request, CovSimpleDto::class);

        $this->assertSame(77, $dto->id);
    }

    public function testDeserializeIntFromNonStringNonIntValueThrows(): void
    {
        // Path attribute carries an array for an int field → isStrictIntValue !is_string → false.
        $request = new Request();
        $request->attributes->set('id', ['nope']);
        $request->attributes->set('name', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int');

        $this->deserializer->deserialize($request, CovSimpleDto::class);
    }

    public function testDeserializeFloatFromNonStringNonNumberValueThrows(): void
    {
        // Path attribute carries a bool for a float field → isStrictFloatValue !is_string → false.
        $request = new Request();
        $request->attributes->set('rate', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "rate" expects float');

        $this->deserializer->deserialize($request, CovFloatDto::class);
    }

    public function testDeserializeFloatFromNativeFloatPathAttribute(): void
    {
        $request = new Request();
        $request->attributes->set('rate', 3.5);

        $dto = $this->deserializer->deserialize($request, CovFloatDto::class);

        $this->assertSame(3.5, $dto->rate);
    }

    public function testEnumErrorFormatsIntValue(): void
    {
        // Non-matching int enum value → castToEnum/formatValueForError int branch.
        $request = new Request([], [], [], [], [], [], json_encode(['priority' => 99]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects enum');
        $this->expectExceptionMessage('got 99');

        $this->deserializer->deserialize($request, CovIntEnumDto::class);
    }

    public function testEnumErrorFormatsBoolValue(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['priority' => true]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects enum');
        $this->expectExceptionMessage('got true');

        $this->deserializer->deserialize($request, CovIntEnumDto::class);
    }

    public function testEnumErrorFormatsNullValueViaSchemaNullable(): void
    {
        // Schema-nullable enum field receiving explicit null is accepted (returns null),
        // but a non-matching object value exercises formatValueForError's object fallback.
        $request = new Request([], [], [], [], [], [], json_encode(['priority' => ['x' => 1]]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects enum');
        $this->expectExceptionMessage('got object');

        $this->deserializer->deserialize($request, CovIntEnumDto::class);
    }

    public function testArrayItemDiscriminatorMappingToUnknownClassThrows(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'animals' => [['kind' => 'ghost']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('points to unknown class');

        $this->deserializer->deserialize($request, CovDiscriminatorArrayWrapperDto::class);
    }

    public function testIntErrorFromNonJsonFloatFormatsActualTypeFloat(): void
    {
        // Float for int field from a path attribute → expectsTypeMessage → getTypeString 'float'.
        $request = new Request();
        $request->attributes->set('id', 3.5);
        $request->attributes->set('name', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "id" expects int, got float');

        $this->deserializer->deserialize($request, CovSimpleDto::class);
    }

    public function testStringErrorFromNonJsonObjectFormatsActualTypeObject(): void
    {
        // A real (non-stdClass) object for a string field → getTypeString is_object branch.
        $request = new Request();
        $request->attributes->set('name', new CovPlainValue());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "name" expects string, got object');

        $this->deserializer->deserialize($request, CovNameOnlyDto::class);
    }

    public function testArrayErrorFromNonJsonAssociativeArrayFormatsObject(): void
    {
        // Non-list array for a string field via path attribute → getTypeString non-list → 'object'.
        $request = new Request();
        $request->attributes->set('name', ['k' => 'v']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('param "name" expects string, got object');

        $this->deserializer->deserialize($request, CovNameOnlyDto::class);
    }

    public function testEnumErrorFormatsNullActualValue(): void
    {
        // Null enum value from a non-json (path) source where field is non-nullable enum:
        // castValue null-handling for non-json allowsNull=false throws before enum, so instead
        // exercise formatValueForError null via an array item enum receiving null.
        $request = new Request([], [], [], [], [], [], json_encode(['priorities' => [null]]));
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects enum');
        $this->expectExceptionMessage('got null');

        $this->deserializer->deserialize($request, CovEnumArrayDto::class);
    }

    public function testBodyDataCacheHitAcrossRepeatedCalls(): void
    {
        // Same deserializer instance + identical body content twice → second getBodyData()
        // returns the cached parsed array (fast path).
        $content = json_encode(['id' => 5, 'name' => 'Cache']);
        $first = new Request([], [], [], [], [], [], $content);
        $first->headers->set('Content-Type', 'application/json');
        $second = new Request([], [], [], [], [], [], $content);
        $second->headers->set('Content-Type', 'application/json');

        $a = $this->deserializer->deserialize($first, CovSimpleDto::class);
        $b = $this->deserializer->deserialize($second, CovSimpleDto::class);

        $this->assertSame(5, $a->id);
        $this->assertSame(5, $b->id);
    }

    public function testNonJsonContentTypeBodyCacheReused(): void
    {
        // Same non-json body twice → empty-array cache fast path on the second read.
        $content = 'plain body content';
        $first = new Request(['id' => '1', 'name' => 'a'], [], [], [], [], [], $content);
        $first->headers->set('Content-Type', 'text/plain');
        $second = new Request(['id' => '2', 'name' => 'b'], [], [], [], [], [], $content);
        $second->headers->set('Content-Type', 'text/plain');

        $a = $this->deserializer->deserialize($first, CovSimpleDto::class);
        $b = $this->deserializer->deserialize($second, CovSimpleDto::class);

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testDateTimeFormatConstraintReturnsNullTemporalFormat(): void
    {
        // openApiFormat 'date-time' → resolveTemporalFormat returns null early.
        $request = new Request([], [], [], [], [], [], json_encode(['value' => '2024-03-10T12:00:00+00:00']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovDateTimeFormatDto::class);

        $this->assertSame('2024-03-10', $dto->value->format('Y-m-d'));
    }

    public function testDateTimeGetterWithDocblockButNoExpectedFormat(): void
    {
        // Getter has a docblock without "Expected format:" → resolveTemporalFormat returns null.
        $request = new Request([], [], [], [], [], [], json_encode(['when' => '2024-03-10T12:00:00+00:00']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovDocblockNoFormatDto::class);

        $this->assertSame('2024-03-10', $dto->getWhen()->format('Y-m-d'));
    }

    public function testArrayItemTypeUnparseableDocblockReturnsNull(): void
    {
        // Property docblock without an array<...> generic → resolveArrayItemType returns null,
        // array passed through untouched.
        $request = new Request([], [], [], [], [], [], json_encode(['items' => ['a', 'b']]));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovNoGenericArrayDto::class);

        $this->assertSame(['a', 'b'], $dto->items);
    }

    public function testAliasesNonArrayReturnIgnored(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['name' => 'ok']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovBadAliasesDto::class);

        $this->assertSame('ok', $dto->name);
    }

    public function testAliasesNonStringEntriesSkipped(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['name' => 'ok']));
        $request->headers->set('Content-Type', 'application/json');

        $dto = $this->deserializer->deserialize($request, CovMixedAliasesDto::class);

        $this->assertSame('ok', $dto->name);
    }
}

// ---------------------------------------------------------------------------
// Test DTOs
// ---------------------------------------------------------------------------

final class CovNoConstructorDto
{
    public int $id = 0;
}

final class CovSimpleDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

final class CovReadOnlyNullableDto
{
    public function __construct(
        public string $name,
        public ?int $id,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return ['id' => ['readOnly' => true]];
    }
}

final class CovReadOnlyNonNullableDto
{
    public function __construct(
        public string $name,
        public int $id,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return ['id' => ['readOnly' => true]];
    }
}

final class CovBoolDto
{
    public function __construct(
        public bool $flag,
    ) {
    }
}

final class CovListArrayDto
{
    /** @param array<string> $tags */
    public function __construct(
        public array $tags,
    ) {
    }
}

final class CovAssocArrayDto
{
    /** @param array<string, int> $map */
    public function __construct(
        public array $map,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return ['map' => ['type' => 'object']];
    }
}

final class CovNullableNameDto
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}

final class CovNameOnlyDto
{
    public function __construct(
        public string $name,
    ) {
    }
}

final class CovDateTimeOnlyDto
{
    public function __construct(
        public DateTimeImmutable $value,
    ) {
    }
}

final class CovFileDto
{
    public function __construct(
        public UploadedFile $file,
    ) {
    }
}

final class CovWrapperDto
{
    public function __construct(
        public CovSimpleDto $user,
    ) {
    }
}

final class CovFloatDto
{
    public function __construct(
        public float $rate,
    ) {
    }
}

final class CovUnionDateTimeDto
{
    public function __construct(
        public DateTimeImmutable|int $value,
    ) {
    }
}

final class CovNullableUnionDto
{
    public function __construct(
        public DateTimeImmutable|int|null $value = null,
    ) {
    }
}

final class CovRequiredNullableUnionDto
{
    public function __construct(
        public DateTimeImmutable|int|null $value,
    ) {
    }

    public function isValueRequired(): bool
    {
        return true;
    }
}

final class CovNullableRequiredDto
{
    public function __construct(
        public ?string $name,
    ) {
    }

    public function isNameRequired(): bool
    {
        return false;
    }
}

final class CovUntypedParamDto
{
    public function __construct(
        public mixed $value,
    ) {
    }
}

interface CovIntersectionA
{
}

interface CovIntersectionB
{
}

final class CovIntersectionTypeDto
{
    public function __construct(
        public CovIntersectionA&CovIntersectionB $value,
    ) {
    }
}

final class CovNoTypeHintDto
{
    /** @phpstan-ignore-next-line missingType.parameter */
    public function __construct(
        public $value,
    ) {
    }
}

/** @phpstan-ignore-next-line */
final class CovUnknownTypeDto
{
    public function __construct(
        public CovNonExistentTarget $value,
    ) {
    }
}

final class CovPrivateRequiredMethodDto
{
    public function __construct(
        public string $name,
    ) {
    }

    private function isNameRequired(): bool
    {
        return true;
    }
}

enum CovUnitEnumDirection
{
    case NORTH;
    case SOUTH;
}

final class CovUnitEnumDto
{
    public function __construct(
        public CovUnitEnumDirection $direction,
    ) {
    }
}

final class CovScalarArrayDto
{
    /** @var array<int> */
    public array $nums;

    /** @param array<int> $nums */
    public function __construct(array $nums)
    {
        $this->nums = $nums;
    }
}

final class CovDocblockFormatDto
{
    public function __construct(
        private DateTimeImmutable $when,
    ) {
    }

    /**
     * Expected format: Y-m-d
     */
    public function getWhen(): DateTimeImmutable
    {
        return $this->when;
    }
}

final class CovFqcnArrayItemDto
{
    /** @var array<CovSimpleDto> */
    private array $items;

    /** @param array<CovSimpleDto> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /** @return array<CovSimpleDto> */
    public function getItems(): array
    {
        return $this->items;
    }
}

final class CovArrayOfDtoDto
{
    /** @var array<CovSimpleDto> */
    private array $items;

    /** @param array<CovSimpleDto> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }
}

// Discriminator base with INVALID metadata (empty property name).
class CovBadDiscriminatorBaseDto
{
    public function __construct(
        public string $kind,
    ) {
    }

    public static function getDiscriminatorPropertyName(): string
    {
        return '';
    }

    /** @return array<string, class-string> */
    public static function getDiscriminatorMapping(): array
    {
        return [];
    }
}

final class CovBadDiscriminatorWrapperDto
{
    public function __construct(
        public CovBadDiscriminatorBaseDto $animal,
    ) {
    }
}

// Discriminator base with valid structure plus a bad mapping target and a non-subtype.
class CovGoodDiscriminatorBaseDto
{
    public function __construct(
        public string $kind,
    ) {
    }

    public static function getDiscriminatorPropertyName(): string
    {
        return 'kind';
    }

    /** @return array<string, class-string> */
    public static function getDiscriminatorMapping(): array
    {
        return [
            'dog' => CovGoodDiscriminatorDogDto::class,
            'ghost' => 'OpenapiPhpDtoGenerator\\Tests\\ThisClassDoesNotExist',
            'stranger' => CovUnrelatedClass::class,
        ];
    }
}

final class CovGoodDiscriminatorDogDto extends CovGoodDiscriminatorBaseDto
{
}

final class CovUnrelatedClass
{
    public function __construct(
        public string $kind,
    ) {
    }
}

final class CovGoodDiscriminatorWrapperDto
{
    public function __construct(
        public CovGoodDiscriminatorBaseDto $animal,
    ) {
    }
}

enum CovIntPriority: int
{
    case LOW = 1;
    case HIGH = 2;
}

final class CovIntEnumDto
{
    public function __construct(
        public CovIntPriority $priority,
    ) {
    }
}

final class CovDiscriminatorArrayWrapperDto
{
    /** @var array<CovGoodDiscriminatorBaseDto> */
    private array $animals;

    /** @param array<CovGoodDiscriminatorBaseDto> $animals */
    public function __construct(array $animals)
    {
        $this->animals = $animals;
    }

    /** @return array<CovGoodDiscriminatorBaseDto> */
    public function getAnimals(): array
    {
        return $this->animals;
    }
}

final class CovPlainValue
{
}

final class CovDateTimeFormatDto
{
    public function __construct(
        public DateTimeImmutable $value,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return ['value' => ['format' => 'date-time']];
    }
}

final class CovDocblockNoFormatDto
{
    public function __construct(
        private DateTimeImmutable $when,
    ) {
    }

    /**
     * Returns the timestamp.
     */
    public function getWhen(): DateTimeImmutable
    {
        return $this->when;
    }
}

final class CovNoGenericArrayDto
{
    /** This docblock has no array generic. */
    public array $items;

    /** @param array<string> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }
}

final class CovEnumArrayDto
{
    /** @var array<CovIntPriority> */
    public array $priorities;

    /** @param array<CovIntPriority> $priorities */
    public function __construct(array $priorities)
    {
        $this->priorities = $priorities;
    }
}

final class CovBadAliasesDto
{
    public function __construct(
        public string $name,
    ) {
    }

    /**  */
    public static function getAliases(): string
    {
        return 'not-an-array';
    }
}

final class CovMixedAliasesDto
{
    public function __construct(
        public string $name,
    ) {
    }

    /** @return array<int|string, mixed> */
    public static function getAliases(): array
    {
        return [
            0 => 'numeric-key-skipped',
            'name' => 123,
        ];
    }
}

final class CovDateArrayDto
{
    /**
     * @var array<DateTimeImmutable>
     */
    public readonly array $dates;

    public function __construct(array $dates)
    {
        $this->dates = $dates;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'dates' => ['items' => ['format' => 'date']],
        ];
    }
}

final class CovDateTimeArrayDto
{
    /**
     * @var array<DateTimeImmutable>
     */
    public readonly array $dates;

    public function __construct(array $dates)
    {
        $this->dates = $dates;
    }
}
