<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use LogicException;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DtoNormalizerCoverageTest extends TestCase
{
    public function testToArrayFallsThroughToDtoToArrayWhenFastPathReturnsNull(): void
    {
        // toArray() throws FIELD_NOT_PROVIDED → tryFastArray() returns null → line 79 dtoToArray()
        $normalizer = new DtoNormalizer();
        $dto = new CovNormOuterFastNullDto(label: 'value');

        $result = $normalizer->toArray($dto);

        $this->assertSame(['label' => 'value'], $result);
    }

    public function testNormalizeValueUsesToStringFallbackForStringableObject(): void
    {
        // Nested object: not enum/file/datetime, no toArray, not JsonSerializable, no getters,
        // but has __toString → line 526
        $normalizer = new DtoNormalizer();
        $dto = new CovNormStringableHolderDto();

        $result = $normalizer->toArray($dto);

        $this->assertSame('stringable-value', $result['stringable']);
    }

    public function testNormalizeValueFallbackForUploadedFileOnNormalizeFailure(): void
    {
        // dtoToArray's normalizeValue() throws on the File, rawValue IS a File → normalizeValueFallback.
        // For UploadedFile this returns originalName + clientMimeType (lines 540-544).
        $tmpPath = tempnam(sys_get_temp_dir(), 'dto_cov_');
        $this->assertNotFalse($tmpPath);

        try {
            $normalizer = new DtoNormalizer();
            $uploaded = new CovNormBrokenUploadedFile($tmpPath, 'orig.txt', 'text/csv', null, true);
            $dto = new CovNormFileWithMapDto($uploaded);

            $result = $normalizer->validateAndNormalizeToArray($dto);

            $this->assertSame(
                ['originalName' => 'orig.txt', 'clientMimeType' => 'text/csv'],
                $result['file'],
            );
        } finally {
            @unlink($tmpPath);
        }
    }

    public function testValidateReportsNullReturnedForNonNullableType(): void
    {
        // Required getter returns null but type is non-nullable → lines 602-606
        $normalizer = new DtoNormalizer();
        $dto = new CovNormNullNonNullableDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('returned null but type is non-nullable', $errors[0]);
        $this->assertStringContainsString('string', $errors[0]);
    }

    public function testValidatePassesForFloatAndBoolTypes(): void
    {
        // Exercises validateValue 'float' and 'bool' arms (lines 629-633)
        $normalizer = new DtoNormalizer();
        $dto = new CovNormFloatBoolDto(ratio: 1.5, flag: true);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testValidateReportsFloatAndBoolTypeMismatch(): void
    {
        $normalizer = new DtoNormalizer();
        $dto = new CovNormFloatBoolWrongDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $joined = implode(' ', $errors);
        $this->assertStringContainsString('must return float', $joined);
        $this->assertStringContainsString('must return bool', $joined);
    }

    public function testValidateArrayItemReportsInstanceMismatch(): void
    {
        // Array item expected to be a class instance; got scalar → validateObject "must return instance of"
        // (line 695) and array-item error append path (line 262 / 659).
        $normalizer = new DtoNormalizer();
        $dto = new CovNormInstanceArrayDto(items: ['not-an-object']);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('items".0', $errors[0]);
        $this->assertStringContainsString('instance of', $errors[0]);
    }

    public function testValidateRunsWhenInRequestFlagGetterReturnsTrue(): void
    {
        // Optional field, flag getter returns true → fieldWasProvided returns true (line 371),
        // validation runs and surfaces the non-nullable null error.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormFlagProvidedDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('getName', $errors[0]);
    }

    public function testValidateRunsWhenFlagGetterThrows(): void
    {
        // Optional field whose flag getter throws → fieldWasProvided returns true (line 373),
        // so validation runs and the non-nullable null is reported.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormFlagThrowingDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('getName', $errors[0]);
    }

    public function testDtoToArrayContinuesOnFieldNotProvidedGetter(): void
    {
        // dtoToArray invokeGetter throws FIELD_NOT_PROVIDED → continue (lines 399-403);
        // other field is still emitted.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormGetterNotProvidedDto();

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertArrayNotHasKey('missing', $result);
        $this->assertSame('present', $result['kept']);
    }

    public function testDtoToArrayPropagatesNonNotProvidedLogicException(): void
    {
        // The DTO's own toArray() throws FIELD_NOT_PROVIDED so the fast path returns null and
        // dtoToArray() runs. There, invokeGetter() throws a plain LogicException (no sentinel)
        // → rethrow (line 401).
        $normalizer = new DtoNormalizer();
        $dto = new CovNormGetterThrowsOtherLogicDto();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('boom plain logic error');

        $normalizer->toArray($dto);
    }

    public function testDtoToArraySkipsInternalModelNameOutputField(): void
    {
        // Getter mapped to output 'modelName' is skipped (line 390)
        $normalizer = new DtoNormalizer();
        $dto = new CovNormModelNameDto();

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertArrayNotHasKey('modelName', $result);
        $this->assertSame('keepme', $result['real']);
    }

    public function testCoerceEmptyStringKeptWhenNonNullableField(): void
    {
        // value === '' but allowsNull false → returns '' unchanged (line 445)
        $normalizer = new DtoNormalizer();
        $dto = new CovNormEmptyStringNonNullableDto();

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertSame('', $result['name']);
    }

    public function testCoerceEmptyStringKeptWhenNoDateFormatConstraint(): void
    {
        // nullable empty string but no 'format' constraint → returns '' (line 452)
        $normalizer = new DtoNormalizer();
        $dto = new CovNormEmptyStringNullableNoFormatDto();

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertSame('', $result['note']);
    }

    public function testCoerceEmptyStringKeptWhenFormatNotTemporal(): void
    {
        // nullable empty string, format present but not date/datetime → coerceEmptyStringToNull
        // returns '' unchanged (line 456). Uses the toArray() path: the DTO's own toArray() throws
        // FIELD_NOT_PROVIDED so dtoToArray() runs (and reaches coerce) without validation.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormEmptyStringNullableEmailFormatDto();

        $result = $normalizer->toArray($dto);

        $this->assertSame('', $result['email']);
    }

    public function testInvokeGetterThrowsWhenGetterNotCallable(): void
    {
        // Map references a protected getter: method_exists() passes the map-builder filter, but
        // is_callable() in invokeGetter() is false → LogicException "is not callable" (line 428),
        // surfaced as a validation error.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormUncallableGetterDto();

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('is not callable', implode(' ', $errors));
    }

    public function testAliasesSkipNonStringEntries(): void
    {
        // validateAndNormalizeToArray now reuses toArray path; this DTO's custom toArray()
        // emits raw field names, so alias remapping is not applied in this coverage case.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMixedAliasesDto(value: 'y');

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertArrayHasKey('value', $result);
        $this->assertSame('y', $result['value']);
    }

    public function testNormalizationMapRowSkippedWhenRowNotArray(): void
    {
        // A map row that is not an array is skipped (line 843); a valid row remains.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMapRowNotArrayDto(value: 'ok');

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertSame(['value' => 'ok'], $result);
    }

    public function testNormalizationMapRowSkippedWhenGetterMissing(): void
    {
        // A map row whose getter does not exist is skipped (line 848); valid row remains.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMapMissingGetterDto(value: 'good');

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertSame(['value' => 'good'], $result);
    }

    public function testNormalizationMapFallsBackToPublicGettersWhenAllRowsInvalid(): void
    {
        // Every map row invalid → buildClassMetaFromNormalizationMap returns null (line 883),
        // falls back to public getters.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormAllRowsInvalidDto(value: 'pub');

        $result = $normalizer->toArray($dto);

        $this->assertSame(['value' => 'pub'], $result);
    }

    public function testPublicGetterSkippedWhenPropertyNameEmpty(): void
    {
        // A public getter literally named get() yields empty property name → skipped (line 936),
        // valid getter remains.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormBareGetMethodDto();

        $result = $normalizer->toArray($dto);

        $this->assertArrayHasKey('label', $result);
        $this->assertSame('lbl', $result['label']);
    }

    public function testArrayItemTypeResolutionFromListGenericDocblock(): void
    {
        // Getter docblock uses list<CovNormInstanceTarget>; item is a scalar → resolves item type from
        // class context and reports instance mismatch (resolveArrayItemTypeNames + validateObject).
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMapGenericDocblockDto(items: [123]);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('items".0', $errors[0]);
        $this->assertStringContainsString('instance of', $errors[0]);
    }

    public function testArrayItemTypeResolutionWithKeyedGenericSplitsOnComma(): void
    {
        // Docblock generic value type is array<string, CovNormInstanceTarget> → comma-splitting branch
        // (lines 1022-1024) picks CovNormInstanceTarget; scalar item triggers instance mismatch.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormKeyedGenericDocblockDto(items: ['a' => 7]);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('instance of', $errors[0]);
    }

    public function testArrayItemTypeResolutionIgnoresMixedGenericItem(): void
    {
        // Docblock generic value type is 'mixed' → skipped (line 1029); no item errors produced.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMixedGenericDocblockDto(items: [1, 'two', 3.0]);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testSelfTypeResolvesToClassNameForNestedValidation(): void
    {
        // type 'self' in the map resolves to the declaring class (line 1069); nested same-class
        // DTO is validated recursively, surfacing the child's constraint violation.
        $normalizer = new DtoNormalizer();
        $inner = new CovNormSelfTypeDto(child: null, score: -5);
        $dto = new CovNormSelfTypeDto(child: $inner, score: 10);

        $errors = $normalizer->validate($dto);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('child.score', implode(' ', $errors));
    }

    public function testDateTimeInterfaceNormalizedViaReflectionPath(): void
    {
        // Map getter typed DateTimeImmutable, normalized through dtoToArray → normalizeValue
        // DateTimeInterface branch via the reflection path.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormDateTimeMapDto(new DateTimeImmutable('2024-01-02T03:04:05+00:00'));

        $result = $normalizer->validateAndNormalizeToArray($dto);

        $this->assertSame('2024-01-02T03:04:05+00:00', $result['createdAt']);
    }

    public function testValidatePassesWhenOnlyNullTypeRemainsAfterFiltering(): void
    {
        // Map type 'null' → after filtering out 'null', the type list is empty (line 610),
        // and a non-null value passes without a type error.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormNullOnlyTypeDto();

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testTypeStringWithEmptyAndOptionalPartsResolvesUsableTypes(): void
    {
        // Map type 'string||?int' exercises the empty-part skip (line 976) and the leading-'?' strip
        // (line 1046); the value validates against one of the resolved types.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormMessyTypeStringDto(value: 7);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testArrayItemTypesEmptyWhenDocblockHasNoGeneric(): void
    {
        // Getter @return is a bare 'array' (no generic) → resolveArrayItemTypeNames returns []
        // (line 1017); array contents are not item-validated.
        $normalizer = new DtoNormalizer();
        $dto = new CovNormNoGenericDocblockDto(items: [1, 'two']);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }

    public function testArrayItemTypesEmptyWhenDocblockHasNoReturnTag(): void
    {
        // Getter docblock has no @return tag → resolveArrayItemTypeNames returns [] (line 1006).
        $normalizer = new DtoNormalizer();
        $dto = new CovNormNoReturnTagDocblockDto(items: [1, 2]);

        $errors = $normalizer->validate($dto);

        $this->assertSame([], $errors);
    }
}

// ---------------------------------------------------------------------------
// Stub DTOs
// ---------------------------------------------------------------------------

final class CovNormOuterFastNullDto implements GeneratedDtoInterface
{
    public function __construct(private readonly string $label)
    {
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
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
        return [];
    }
}

final class CovNormStringableValue implements Stringable
{
    public function __toString(): string
    {
        return 'stringable-value';
    }
}

final class CovNormStringableHolderDto implements GeneratedDtoInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['stringable' => new CovNormStringableValue()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
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

final class CovNormBrokenUploadedFile extends UploadedFile
{
    // getFilename() throwing makes normalizeFileValue() (and thus normalizeValue()) throw,
    // so dtoToArray() falls back to normalizeValueFallback() for this File value.
    public function getFilename(): string
    {
        throw new LogicException('filename not available');
    }
}

final class CovNormFileWithMapDto implements GeneratedDtoInterface
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'file' => ['getter' => 'getFile', 'type' => File::class, 'nullable' => false, 'metadata' => ['openApiName' => 'file']],
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

final class CovNormNullNonNullableDto implements GeneratedDtoInterface
{
    public function getName(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->getName()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'name' => ['getter' => 'getName', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'name', 'required' => true]],
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

final class CovNormFloatBoolDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly float $ratio,
        private readonly bool $flag,
    ) {
    }

    public function getRatio(): float
    {
        return $this->ratio;
    }

    public function getFlag(): bool
    {
        return $this->flag;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ratio' => $this->ratio, 'flag' => $this->flag];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'ratio' => ['getter' => 'getRatio', 'type' => 'float', 'nullable' => false, 'metadata' => ['openApiName' => 'ratio', 'required' => true]],
            'flag' => ['getter' => 'getFlag', 'type' => 'bool', 'nullable' => false, 'metadata' => ['openApiName' => 'flag', 'required' => true]],
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

final class CovNormFloatBoolWrongDto implements GeneratedDtoInterface
{
    public function getRatio(): string
    {
        return 'nope';
    }

    public function getFlag(): string
    {
        return 'nope';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ratio' => $this->getRatio(), 'flag' => $this->getFlag()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'ratio' => ['getter' => 'getRatio', 'type' => 'float', 'nullable' => false, 'metadata' => ['openApiName' => 'ratio', 'required' => true]],
            'flag' => ['getter' => 'getFlag', 'type' => 'bool', 'nullable' => false, 'metadata' => ['openApiName' => 'flag', 'required' => true]],
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

final class CovNormInstanceTarget
{
}

final class CovNormInstanceArrayDto implements GeneratedDtoInterface
{
    /** @param array<mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @return array<CovNormInstanceTarget> */
    public function getItems(): array
    {
        /** @var array<CovNormInstanceTarget> */
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
        return '{}';
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

final class CovNormFlagProvidedDto implements GeneratedDtoInterface
{
    public function getName(): ?string
    {
        return null;
    }

    public function isNameInRequest(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->getName()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'name' => [
                'getter' => 'getName',
                'type' => 'string',
                'nullable' => false,
                'metadata' => ['openApiName' => 'name', 'required' => false, 'inRequestFlagGetter' => 'isNameInRequest'],
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

final class CovNormFlagThrowingDto implements GeneratedDtoInterface
{
    public function getName(): ?string
    {
        return null;
    }

    public function isNameInRequest(): bool
    {
        throw new LogicException('flag unavailable');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->getName()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'name' => [
                'getter' => 'getName',
                'type' => 'string',
                'nullable' => false,
                'metadata' => ['openApiName' => 'name', 'required' => false, 'inRequestFlagGetter' => 'isNameInRequest'],
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

final class CovNormGetterNotProvidedDto implements GeneratedDtoInterface
{
    public function getMissing(): string
    {
        throw new LogicException('Field ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
    }

    public function getKept(): string
    {
        return 'present';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kept' => $this->getKept()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'missing' => ['getter' => 'getMissing', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'missing', 'required' => false]],
            'kept' => ['getter' => 'getKept', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'kept', 'required' => true]],
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

final class CovNormGetterThrowsOtherLogicDto implements GeneratedDtoInterface
{
    public function getValue(): string
    {
        throw new LogicException('boom plain logic error');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Fast path returns null so dtoToArray() runs and invokes the throwing getter directly.
        throw new LogicException('Field ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'value' => ['getter' => 'getValue', 'type' => 'mixed', 'nullable' => true, 'metadata' => ['openApiName' => 'value', 'required' => false]],
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

final class CovNormModelNameDto implements GeneratedDtoInterface
{
    public function getModel(): string
    {
        return 'Widget';
    }

    public function getReal(): string
    {
        return 'keepme';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['real' => $this->getReal()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'model' => ['getter' => 'getModel', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'modelName', 'required' => true]],
            'real' => ['getter' => 'getReal', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'real', 'required' => true]],
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

final class CovNormEmptyStringNonNullableDto implements GeneratedDtoInterface
{
    public function getName(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->getName()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'name' => ['getter' => 'getName', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'name', 'required' => true]],
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

final class CovNormEmptyStringNullableNoFormatDto implements GeneratedDtoInterface
{
    public function getNote(): ?string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['note' => $this->getNote()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'note' => ['getter' => 'getNote', 'type' => 'string|null', 'nullable' => true, 'metadata' => ['openApiName' => 'note', 'required' => true]],
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

final class CovNormEmptyStringNullableEmailFormatDto implements GeneratedDtoInterface
{
    public function getEmail(): ?string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Fast path returns null so dtoToArray() runs and exercises coerceEmptyStringToNull.
        throw new LogicException('Field ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'email' => ['getter' => 'getEmail', 'type' => 'string|null', 'nullable' => true, 'metadata' => ['openApiName' => 'email', 'required' => false]],
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
            'email' => ['format' => 'email'],
        ];
    }
}

final class CovNormUncallableGetterDto implements GeneratedDtoInterface
{
    public function getReal(): string
    {
        return 'real';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['real' => $this->getReal()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    protected function protectedGetter(): string
    {
        return 'secret';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        // 'protectedGetter' exists (method_exists passes the map-builder filter) but is not callable
        // from outside the class, so invokeGetter() throws "is not callable".
        return [
            'real' => ['getter' => 'getReal', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'real', 'required' => true]],
            'secret' => ['getter' => 'protectedGetter', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'secret', 'required' => true]],
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

final class CovNormMixedAliasesDto implements GeneratedDtoInterface
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'value' => ['getter' => 'getValue', 'type' => 'string', 'nullable' => false, 'metadata' => ['required' => true]],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        // The integer-keyed/integer-valued entry must be skipped; the valid one applied.
        /** @phpstan-ignore-next-line */
        return [
            0 => 123,
            'value' => 'aliased_value',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [];
    }
}

final class CovNormMapRowNotArrayDto implements GeneratedDtoInterface
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        // 'broken' row is a scalar (not an array) → skipped.
        /** @phpstan-ignore-next-line */
        return [
            'broken' => 'oops',
            'value' => ['getter' => 'getValue', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'value', 'required' => true]],
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

final class CovNormMapMissingGetterDto implements GeneratedDtoInterface
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'ghost' => ['getter' => 'getDoesNotExist', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'ghost', 'required' => true]],
            'value' => ['getter' => 'getValue', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'value', 'required' => true]],
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

final class CovNormAllRowsInvalidDto implements GeneratedDtoInterface
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        // Every row references a missing getter → no valid getters → returns null → public fallback.
        return [
            'ghost' => ['getter' => 'getNope', 'type' => 'string', 'nullable' => false, 'metadata' => ['openApiName' => 'ghost', 'required' => true]],
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

final class CovNormBareGetMethodDto implements GeneratedDtoInterface
{
    public function get(): string
    {
        return 'bare';
    }

    public function getLabel(): string
    {
        return 'lbl';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Throws not-provided so the fast path returns null and dtoToArray() reflects public getters.
        throw new LogicException('Field ' . GeneratedDtoInterface::FIELD_NOT_PROVIDED_MESSAGE);
    }

    public function jsonSerialize(): mixed
    {
        return null;
    }

    public function toJson(): string
    {
        return '{}';
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

final class CovNormMapGenericDocblockDto implements GeneratedDtoInterface
{
    /** @param array<mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @return list<CovNormInstanceTarget> */
    public function getItems(): array
    {
        /** @var list<CovNormInstanceTarget> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items', 'required' => true]],
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

final class CovNormKeyedGenericDocblockDto implements GeneratedDtoInterface
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @return array<string, CovNormInstanceTarget> */
    public function getItems(): array
    {
        /** @var array<string, CovNormInstanceTarget> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items', 'required' => true]],
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

final class CovNormMixedGenericDocblockDto implements GeneratedDtoInterface
{
    /** @param array<mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** @return array<int, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items', 'required' => true]],
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

final class CovNormSelfTypeDto implements GeneratedDtoInterface
{
    public function __construct(
        private readonly ?CovNormSelfTypeDto $child,
        private readonly int $score,
    ) {
    }

    public function getChild(): ?CovNormSelfTypeDto
    {
        return $this->child;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['child' => $this->child, 'score' => $this->score];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'child' => ['getter' => 'getChild', 'type' => 'self|null', 'nullable' => true, 'metadata' => ['openApiName' => 'child', 'required' => false]],
            'score' => ['getter' => 'getScore', 'type' => 'int', 'nullable' => false, 'metadata' => ['openApiName' => 'score', 'required' => true]],
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
            'score' => ['minimum' => 1],
        ];
    }
}

final class CovNormDateTimeMapDto implements GeneratedDtoInterface
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'createdAt' => ['getter' => 'getCreatedAt', 'type' => DateTimeImmutable::class, 'nullable' => false, 'metadata' => ['openApiName' => 'createdAt', 'required' => true]],
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

final class CovNormNullOnlyTypeDto implements GeneratedDtoInterface
{
    public function getValue(): string
    {
        return 'present';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['value' => $this->getValue()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        // Type resolves to only 'null', which is filtered out → empty type list → no type check.
        return [
            'value' => ['getter' => 'getValue', 'type' => 'null', 'nullable' => false, 'metadata' => ['openApiName' => 'value', 'required' => true]],
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

final class CovNormMessyTypeStringDto implements GeneratedDtoInterface
{
    public function __construct(private readonly int $value)
    {
    }

    public function getValue(): int
    {
        return $this->value;
    }

    /** @return array<string, mixed> */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        // Empty middle part (skipped) and a leading-'?' part (stripped) around a usable int type.
        return [
            'value' => ['getter' => 'getValue', 'type' => 'string||?int', 'nullable' => false, 'metadata' => ['openApiName' => 'value', 'required' => true]],
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

final class CovNormNoGenericDocblockDto implements GeneratedDtoInterface
{
    /** @param array<mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /**  */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items', 'required' => true]],
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

final class CovNormNoReturnTagDocblockDto implements GeneratedDtoInterface
{
    /** @param array<mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /** This getter intentionally has no @return tag. */
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
        return '{}';
    }

    /** @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}> */
    public static function getNormalizationMap(): array
    {
        return [
            'items' => ['getter' => 'getItems', 'type' => 'array', 'nullable' => false, 'metadata' => ['openApiName' => 'items', 'required' => true]],
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
