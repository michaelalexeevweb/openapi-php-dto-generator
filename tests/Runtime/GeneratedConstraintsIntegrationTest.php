<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Contract\GeneratedDtoInterface;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use UnitEnum;

/**
 * GAP-1 regression coverage.
 *
 * This is the "through the generator" integration layer that was missing: it
 * generates a DTO from an OpenAPI spec, loads it, and asserts that the validation
 * constraints actually survive into the generated getConstraints() AND are enforced
 * by DtoNormalizer::validate(). Before the allowlist was widened, keys such as
 * const/not/allOf/properties/required/additionalProperties/min|maxProperties/
 * dependentRequired/dependentSchemas/prefixItems were silently stripped by
 * GenerateDtoCommand::extractValidationConstraints, so these features only ever
 * worked when DtoValidator was called directly — never through a real DTO.
 */
final class GeneratedConstraintsIntegrationTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-gap1';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->outputDirectory)) {
            return;
        }

        $entries = scandir($this->outputDirectory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            unlink($this->outputDirectory . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($this->outputDirectory);
    }

    /**
     * Generates ProbeModel from the GAP-1 fixture into a unique namespace (so each
     * test method gets its own class and PHP never sees a redeclaration) and returns
     * the fully-qualified class name after requiring every generated file.
     *
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateProbeModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/gap1-probe.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\ProbeModel';
        return $fqcn;
    }

    /**
     * Generates OptionalFieldModel and returns its FQCN after requiring it.
     *
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateOptionalFieldModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/optional-field-validation.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\OptionalFieldModel';
        return $fqcn;
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateIntFormatModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-format.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\IntFormatModel';
        return $fqcn;
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateEventModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/array-datetime.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\EventModel';
        return $fqcn;
    }

    private function jsonPostRequest(string $body): Request
    {
        return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    public function testAdditionalPropertiesMapSerializesAsObjectAndRoundTrips(): void
    {
        // A `type: object` + `additionalProperties` map ({id: name}) must serialize as a JSON
        // object even when its keys are dense integers (0, 1, 2, …) — a raw array would become a
        // JSON list and lose the keys. Deserialization casts each value to the items type.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Map', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['fieldTypes'],
                        'properties' => [
                            'fieldTypes' => [
                                'type' => 'object',
                                'additionalProperties' => ['$ref' => '#/components/schemas/FieldTypesEnumView'],
                            ],
                        ],
                    ],
                    'FieldTypesEnumView' => ['type' => 'string', 'enum' => ['text', 'number', 'date']],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenMap');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenMap\\Holder';
        $enum = '\\GenMap\\FieldTypesEnumView';
        $normalizer = new DtoNormalizer();

        // Dense integer keys must still produce a JSON object on every output path.
        $dense = new $cls([0 => $enum::TEXT, 1 => $enum::NUMBER, 2 => $enum::DATE]);
        $expected = '{"fieldTypes":{"0":"text","1":"number","2":"date"}}';
        $this->assertSame($expected, $dense->toJson());
        $this->assertSame($expected, (string)json_encode($normalizer->toArray($dense)));
        $this->assertSame($expected, (string)json_encode($normalizer->validateAndNormalizeToArray($dense)));

        // Deserialize a JSON object body → keys preserved, values cast to the enum.
        $deserialized = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"fieldTypes":{"0":"text","5":"date"}}'),
            $cls,
        );
        $map = $deserialized->getFieldTypes();
        $this->assertSame([0, 5], array_keys($map));
        $this->assertSame($enum::TEXT, $map[0]);
        $this->assertSame($enum::DATE, $map[5]);

        // A map exposes a keyed adder ($key, $item) that preserves keys in the object output.
        $built = new $cls([0 => $enum::TEXT]);
        $built->addItemToFieldTypes('5', $enum::DATE);
        $built->addItemToFieldTypes('label', $enum::NUMBER);
        $this->assertSame(
            '{"fieldTypes":{"0":"text","5":"date","label":"number"}}',
            $built->toJson(),
        );
    }

    public function testTemporalFieldExposesObjectGetterAlongsideStringGetter(): void
    {
        // A scalar temporal field keeps its string getter (formatted per the OpenAPI format) and
        // gains a getXAsDateTime() that returns the underlying DateTimeImmutable. Covers a required
        // (non-null) and a nullable field.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Temporal', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Event' => [
                        'type' => 'object',
                        'required' => ['startDate'],
                        'properties' => [
                            'startDate' => ['type' => 'string', 'format' => 'date'],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenTemporal');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenTemporal\\Event';

        /** @var object{getStartDate: callable, getStartDateAsDateTime: callable, getCreatedAt: callable, getCreatedAtAsDateTime: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"startDate":"2026-01-15","createdAt":"2026-02-20T08:30:00+00:00"}'),
            $cls,
        );

        // String getter: formatted per the OpenAPI format.
        $this->assertSame('2026-01-15', $dto->getStartDate());
        // Object getter: the underlying DateTimeImmutable.
        $startDateObject = $dto->getStartDateAsDateTime();
        $this->assertInstanceOf(DateTimeImmutable::class, $startDateObject);
        $this->assertSame('2026-01-15', $startDateObject->format('Y-m-d'));

        // Nullable field present → both getters return the value.
        $this->assertSame('2026-02-20T08:30:00+00:00', $dto->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->getCreatedAtAsDateTime());

        // Reflect the declared return types.
        $this->assertSame(
            'DateTimeImmutable',
            (string)(new ReflectionMethod($cls, 'getStartDateAsDateTime'))->getReturnType(),
        );
        $this->assertSame(
            '?DateTimeImmutable',
            (string)(new ReflectionMethod($cls, 'getCreatedAtAsDateTime'))->getReturnType(),
        );
    }

    public function testArrayOfDateTimeItemsAreParsedThroughGeneratedDto(): void
    {
        // Regression: array<DateTimeImmutable> items used to fail deserialization —
        // (1) resolveFileImports dropped the first char of imported short names, and
        // (2) castArrayItemValue routed datetime items through the nested-DTO branch.
        $cls = $this->generateEventModel('GapArrDt');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"dates":["2026-01-15T12:00:00+00:00","2026-02-20T08:30:00+00:00"]}'),
            $cls,
        );

        $dates = $dto->getDates();
        $this->assertCount(2, $dates);
        $this->assertInstanceOf(DateTimeImmutable::class, $dates[0]);
        $this->assertSame('2026-01-15T12:00:00+00:00', $dates[0]->format('c'));
    }

    public function testArrayOfDateTimeRejectsNonStringItem(): void
    {
        $cls = $this->generateEventModel('GapArrDtBad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dates.0" expects date string');
        (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"dates":[123]}'), $cls);
    }

    public function testResolveFileImportsKeepsFirstCharOfImportedNames(): void
    {
        // Direct lock for the resolveFileImports off-by-one: imported names without a
        // namespace separator (e.g. the global `use DateTimeImmutable;`) must keep their
        // first character. The bug produced 'ateTimeImmutable' => 'DateTimeImmutable'.
        $this->generateEventModel('GapImports');

        $deserializer = new DtoDeserializer();
        $method = new ReflectionMethod($deserializer, 'resolveFileImports');
        /** @var array<string, string> $imports */
        $imports = $method->invoke($deserializer, new ReflectionClass('\\GapImports\\EventModel'));

        $this->assertArrayHasKey('DateTimeImmutable', $imports);
        $this->assertSame('DateTimeImmutable', $imports['DateTimeImmutable']);
        $this->assertArrayNotHasKey('ateTimeImmutable', $imports);
    }

    /**
     * @return class-string<GeneratedDtoInterface>
     */
    private function generateBoxModel(string $namespace): string
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/array-enum-dto.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $fqcn */
        $fqcn = '\\' . $namespace . '\\Box';
        return $fqcn;
    }

    public function testArrayOfEnumsAndNestedDtosDeserialize(): void
    {
        $cls = $this->generateBoxModel('GapBox');

        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"colors":["red","blue"],"tags":[{"name":"a"},{"name":"b"}]}'),
            $cls,
        );

        $colors = $dto->getColors();
        $this->assertCount(2, $colors);
        $this->assertSame('red', $colors[0]->value);

        $tags = $dto->getTags();
        $this->assertCount(2, $tags);
        $this->assertSame('a', $tags[0]->getName());
    }

    public function testArrayOfEnumsRejectsInvalidMember(): void
    {
        $cls = $this->generateBoxModel('GapBoxBad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('colors.0');
        (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"colors":["magenta"],"tags":[]}'),
            $cls,
        );
    }

    public function testIntBackedEnumAcceptsQueryStringValue(): void
    {
        // Regression: int-backed enum case (value 1) never strict-equalled the incoming "1"
        // from a query parameter (Symfony delivers query/path/form as strings).
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-enum-query.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIntEnum');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapIntEnum\\Filter';
        // status arrives from the query bag as the string "2"; body is empty.
        $request = Request::create('/?status=2', 'GET');
        $dto = (new DtoDeserializer())->deserialize($request, $cls);
        $this->assertSame(2, $dto->getStatus()->value);
    }

    public function testIntOverflowStringFromQueryIsRejected(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/int-enum-query.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIntOverflow');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapIntOverflow\\Filter';
        // count = 23 nines: (int) cast would saturate to PHP_INT_MAX — must be rejected instead.
        $this->expectException(RuntimeException::class);
        (new DtoDeserializer())->deserialize(Request::create('/?status=1&count=99999999999999999999999', 'GET'), $cls);
    }

    public function testIfWithRefConditionDoesNotForceThen(): void
    {
        // Regression: if:{$ref} extracted to an empty (vacuously-true) schema, so `then`
        // (discountCode required) was applied to EVERY value. The unvalidatable if/then is dropped.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/if-ref.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapIfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapIfRef\\Account';
        $profile = $cls::getConstraints()['profile'] ?? [];
        // The $ref `if` extracted to empty and must be dropped — otherwise it is vacuously true
        // and `then` applies to every value. (`then` may remain but is inert without `if`.)
        $this->assertArrayNotHasKey('if', $profile);
        // items:{$ref} likewise extracts to empty and is dropped (would otherwise silently skip).
        $this->assertArrayNotHasKey('items', $profile);
    }

    public function testGeneratedNormalizationMapCarriesArrayItemType(): void
    {
        // The map must carry the array item type so DtoNormalizer needn't reflect getter docblocks.
        $cls = $this->generateBoxModel('GapMapItemType');
        $map = $cls::getNormalizationMap();

        $this->assertSame('array<Color>', $map['colors']['metadata']['arrayItemType']);
        $this->assertSame('array<Tag>', $map['tags']['metadata']['arrayItemType']);
    }

    public function testNestedDtoWriteOnlyFieldIsNotSerialized(): void
    {
        // write-only fields of a NESTED DTO must not leak into the parent's serialized output.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nested-writeonly.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapNestedWo');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapNestedWo\\Wrap';
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"child":{"name":"Bob","secret":"sekret"}}'),
            $cls,
        );

        $array = (new DtoNormalizer())->toArray($dto);
        $this->assertSame('Bob', $array['child']['name']);
        $this->assertArrayNotHasKey('secret', $array['child']);
    }

    public function testCyclicDtoGraphSerializesWithoutInfiniteRecursion(): void
    {
        // Cycles are now explicit serialization errors (instead of silent null truncation).
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapCycle');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapCycle\\Node';
        $a = new $cls('A');
        $b = new $cls('B');
        $a->addItemToChildren($b);
        $b->addItemToChildren($a); // A -> B -> A cycle

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected');

        (new DtoNormalizer())->toArray($a);
    }

    public function testCyclicDtoGraphIsReportedByValidateAndRejectedByValidateAndNormalize(): void
    {
        // Validation must reject circular graphs, and validateAndNormalize* must fail too.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapCycleValidate');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapCycleValidate\\Node';
        $a = new $cls('A');
        $b = new $cls('B');
        $a->addItemToChildren($b);
        $b->addItemToChildren($a);

        $normalizer = new DtoNormalizer();
        $errors = $normalizer->validate($a);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('circular reference', implode(' | ', $errors));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('circular reference');
        $normalizer->validateAndNormalizeToArray($a);
    }

    public function testSelfReferentialRootSerializesWithoutInfiniteRecursion(): void
    {
        // Root self-reference is now an explicit serialization error.
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/cyclic-node.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSelfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapSelfRef\\Node';
        $node = new $cls('root');
        $node->addItemToChildren($node);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected');

        (new DtoNormalizer())->toArray($node);
    }

    public function testOneOfWithRefVariantDoesNotEmitUnvalidatableConstraint(): void
    {
        // Regression: a oneOf with a $ref variant extracted an empty branch that the
        // validator treated as always-matching → false "matches more than one oneOf
        // branch". The unvalidatable union must be dropped from getConstraints().
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/oneof-ref.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapOneOfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GapOneOfRef\\Holder';
        $constraints = $cls::getConstraints();
        // The whole unvalidatable oneOf is dropped → no 'oneOf' (the 'value' entry may be absent entirely).
        $this->assertArrayNotHasKey('oneOf', $constraints['value'] ?? []);

        // A value matching the inline string branch must validate cleanly (no false positive).
        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"value":"hello"}'), $cls);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));
    }

    public function testInt32FormatRangeIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateIntFormatModel('GapInt32');
        $deserializer = new DtoDeserializer();

        // In-range value deserializes fine.
        $dto = $deserializer->deserialize($this->jsonPostRequest('{"small":100}'), $cls);
        $this->assertSame([], (new DtoNormalizer())->validate($dto));

        // Over-range int32 is rejected during deserialization constraint checks.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('within int32 range');
        $deserializer->deserialize($this->jsonPostRequest('{"small":5000000000}'), $cls);
    }

    public function testOmittedOptionalNonNullableFieldDoesNotProduceFalseValidationError(): void
    {
        $cls = $this->generateOptionalFieldModel('GapOptOmitted');

        // Request provides only the required field; optional non-nullable string/int omitted.
        $dto = (new DtoDeserializer())->deserialize($this->jsonPostRequest('{"id":5}'), $cls);

        $errors = (new DtoNormalizer())->validate($dto);

        // Before the fix: ['field "note" must be of type string', 'field "count" must be of type integer'].
        $this->assertSame([], $errors);
    }

    public function testProvidedOptionalFieldIsStillValidated(): void
    {
        $cls = $this->generateOptionalFieldModel('GapOptProvided');
        $normalizer = new DtoNormalizer();

        // A valid optional value passes (minLength: 3 satisfied).
        $this->assertSame([], $normalizer->validate(new $cls(5, note: 'hello')));

        // Skipping unprovided fields must NOT disable validation of provided ones:
        // a provided value violating a schema constraint must still be reported.
        $invalid = $normalizer->validate(new $cls(5, note: 'hi'));
        $this->assertContains('field "note" length must be at least 3 characters', $invalid);
    }

    public function testAllNewlyAllowedConstraintKeysSurviveIntoGetConstraints(): void
    {
        $model = $this->generateProbeModel('GapPresence');
        $constraints = $model::getConstraints();

        // const — scalar equality constraint.
        $this->assertArrayHasKey('const', $constraints['constField']);
        $this->assertSame('locked', $constraints['constField']['const']);

        // not — recursively extracted subschema (a $ref-only `not` would be dropped).
        $this->assertArrayHasKey('not', $constraints['notField']);
        $this->assertSame(['const' => 'forbidden'], $constraints['notField']['not']);

        // object-level keys on a non-materialized map type.
        $this->assertArrayHasKey('minProperties', $constraints['mapField']);
        $this->assertArrayHasKey('maxProperties', $constraints['mapField']);
        $this->assertArrayHasKey('additionalProperties', $constraints['mapField']);

        $this->assertArrayHasKey('properties', $constraints['strictMap']);
        $this->assertArrayHasKey('additionalProperties', $constraints['strictMap']);
        $this->assertFalse($constraints['strictMap']['additionalProperties']);

        $this->assertArrayHasKey('dependentRequired', $constraints['depReqMap']);
        $this->assertArrayHasKey('dependentSchemas', $constraints['depSchemaMap']);

        // prefixItems — positional tuple validation.
        $this->assertArrayHasKey('prefixItems', $constraints['tupleField']);

        // allOf — inline branches kept, fully-unresolvable ($ref-only) ones dropped.
        $this->assertArrayHasKey('allOf', $constraints['intersection']);
        $this->assertSame(
            [['minLength' => 2], ['maxLength' => 5]],
            $constraints['intersection']['allOf'],
        );
    }

    public function testConstConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapConst');
        $normalizer = new DtoNormalizer();

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1]));
        $this->assertNotContains('field "constField" must equal "locked"', $valid);

        $invalid = $normalizer->validate(new $cls('WRONG', ['a' => 1]));
        $this->assertContains('field "constField" must equal "locked"', $invalid);
    }

    public function testNotConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapNot');
        $normalizer = new DtoNormalizer();

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1], notField: 'allowed'));
        $this->assertNotContains("field \"notField\" must not match the 'not' schema", $valid);

        $invalid = $normalizer->validate(new $cls('locked', ['a' => 1], notField: 'forbidden'));
        $this->assertContains("field \"notField\" must not match the 'not' schema", $invalid);
    }

    public function testMapObjectConstraintsAreEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapMap');
        $normalizer = new DtoNormalizer();

        // minProperties: 1 — an empty map must fail.
        $tooFew = $normalizer->validate(new $cls('locked', []));
        $this->assertContains('field "mapField" must have at least 1 property', $tooFew);

        // additionalProperties: { type: integer } — a string value must fail.
        $wrongItemType = $normalizer->validate(new $cls('locked', ['a' => 'not-an-int']));
        $this->assertContains('field "mapField".a must be of type integer', $wrongItemType);

        $ok = $normalizer->validate(new $cls('locked', ['a' => 1, 'b' => 2]));
        $this->assertNotContains('field "mapField" must have at least 1 property', $ok);
    }

    public function testPrefixItemsConstraintIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateProbeModel('GapPrefix');
        $normalizer = new DtoNormalizer();

        // prefixItems: [string, integer] — index 0 must be a string.
        $invalid = $normalizer->validate(new $cls('locked', ['a' => 1], tupleField: [123, 7]));
        $this->assertContains('field "tupleField".0 must be of type string', $invalid);

        $valid = $normalizer->validate(new $cls('locked', ['a' => 1], tupleField: ['ok', 7]));
        $this->assertNotContains('field "tupleField".0 must be of type string', $valid);
    }

    public function testHeaderAndCookieParamsAreDeserializedThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/source-params.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSrc');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        $this->assertNotEmpty($queryParamFiles);
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GapSrc\\' . basename((string)$queryParamFiles[0], '.php');

        // The generator emitted the per-source binding map.
        $this->assertSame(
            ['id' => 'path', 'page' => 'query', 'token' => 'header', 'sid' => 'cookie'],
            $cls::getParameterSources(),
        );

        // Each value arrives only from its declared source.
        $request = new Request(
            query: ['page' => '5'],
            request: [],
            attributes: ['id' => 'abc'],
            cookies: ['sid' => 'cookie-1'],
            files: [],
            server: ['HTTP_TOKEN' => 'tok-1'],
        );

        $dto = (new DtoDeserializer())->deserialize($request, $cls);

        $this->assertSame('abc', $dto->getId());
        $this->assertSame(5, $dto->getPage());
        $this->assertSame('tok-1', $dto->getToken());
        $this->assertSame('cookie-1', $dto->getSid());
        $this->assertTrue($dto->isIdInPath());
        $this->assertTrue($dto->isPageInQuery());
        $this->assertTrue($dto->isTokenInHeader());
        $this->assertTrue($dto->isSidInCookie());

        // Parameter-bound fields are request transport, never serialized into the
        // payload — neither via the DTO's own toArray() nor through the normalizer.
        $this->assertSame([], $dto->toArray());
        $this->assertSame([], (new DtoNormalizer())->toArray($dto));
    }

    public function testRequiredHeaderParamMissingThrowsThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/source-params.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GapSrcMissing');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GapSrcMissing\\' . basename((string)($queryParamFiles[0] ?? ''), '.php');

        // token is a required header; omitting the header must fail even though a
        // same-named body field is present (strict source binding).
        $request = new Request(
            query: ['page' => '5'],
            request: [],
            attributes: ['id' => 'abc'],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"token":"from-body"}',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required parameter "token" not found in request');

        (new DtoDeserializer())->deserialize($request, $cls);
    }

    public function testDelimitedArrayParamsSplitByStyleThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/parameter-style.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenStyle');

        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');
        foreach ($queryParamFiles === false ? [] : $queryParamFiles as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenStyle\\' . basename((string)($queryParamFiles[0] ?? ''), '.php');

        // form/explode=false → comma, spaceDelimited → space, pipeDelimited → pipe,
        // form/explode=true → arrives as a repeated-key array (no re-splitting).
        $request = new Request(
            query: [
                'tags' => 'a,b,c',
                'codes' => 'x y z',
                'ids' => '1|2|3',
                'exploded' => ['p', 'q'],
            ],
        );

        /** @var object{getTags: callable, getCodes: callable, getIds: callable, getExploded: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize($request, $cls);

        $this->assertSame(['a', 'b', 'c'], $dto->getTags());
        $this->assertSame(['x', 'y', 'z'], $dto->getCodes());
        $this->assertSame(['1', '2', '3'], $dto->getIds());
        $this->assertSame(['p', 'q'], $dto->getExploded());
    }

    public function testNullableArrayItemsAreAcceptedThroughGeneratedDto(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/nullable-array-items.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenNullItems');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenNullItems\\TagBag';

        // items: {type: string, nullable: true} — a null element must be accepted, not rejected.
        /** @var object{getTags: callable} $dto */
        $dto = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"tags":["a",null,"b"]}'),
            $cls,
        );

        $this->assertSame(['a', null, 'b'], $dto->getTags());
    }

    public function testDefaultValuedParamPresenceFlagReflectsActualProvision(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/default-param-presence.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenDefaultParam');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }
        $queryParamFiles = glob($this->outputDirectory . '/*QueryParams.php');

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenDefaultParam\\' . basename((string)(($queryParamFiles ?: [])[0] ?? ''), '.php');

        // Direct construction without scope → flag false (it was never "in the query").
        /** @var object{isScopeInQuery: callable} $direct */
        $direct = new $cls();
        $this->assertFalse($direct->isScopeInQuery());

        // Deserialize with scope actually present → flag flipped true via reflection.
        /** @var object{isScopeInQuery: callable} $provided */
        $provided = (new DtoDeserializer())->deserialize(
            Request::create('/things?scope=active', 'GET'),
            $cls,
        );
        $this->assertTrue($provided->isScopeInQuery());
    }

    public function testOptionalBodyFieldWithDefaultCanBeOmittedViaUnsetSentinel(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Body default omission', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'SampleRequest' => [
                        'type' => 'object',
                        'required' => ['itemIds'],
                        'properties' => [
                            'stage' => ['$ref' => '#/components/schemas/SampleEnumView'],
                            'itemIds' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'SampleEnumView' => [
                        'type' => 'string',
                        'enum' => ['alpha', 'beta'],
                        'default' => 'alpha',
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBodyDefault');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenBodyDefault\\SampleRequest';
        $enum = '\\GenBodyDefault\\SampleEnumView';

        // Plain construction → declared default applies and is serialized (intent preserved).
        /** @var object{toArray: callable, getStage: callable, isStageInRequest: callable} $withDefault */
        $withDefault = new $cls([1, 2]);
        $this->assertTrue($withDefault->isStageInRequest());
        $this->assertSame($enum::ALPHA, $withDefault->getStage());
        $this->assertArrayHasKey('stage', $withDefault->toArray());

        // Explicit UnsetValue::UNSET → omitted from the payload (new capability).
        /** @var object{toArray: callable, getStage: callable, isStageInRequest: callable} $omitted */
        $omitted = new $cls([1, 2], \OpenapiPhpDtoGenerator\Contract\UnsetValue::UNSET);
        $this->assertFalse($omitted->isStageInRequest());
        $this->assertNull($omitted->getStage());
        $this->assertArrayNotHasKey('stage', $omitted->toArray());

        // Deserialization of a payload without the field → default applied, presence stays false.
        $deserialized = (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                parameters: [],
                cookies: [],
                files: [],
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string)json_encode(['itemIds' => [1]]),
            ),
            $cls,
        );
        $this->assertSame($enum::ALPHA, $deserialized->getStage());
        $this->assertFalse($deserialized->isStageInRequest());

        // Deserialization of a payload WITH the field → value taken from payload, presence true.
        $provided = (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                parameters: [],
                cookies: [],
                files: [],
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string)json_encode(['itemIds' => [1], 'stage' => 'beta']),
            ),
            $cls,
        );
        $this->assertSame($enum::BETA, $provided->getStage());
        $this->assertTrue($provided->isStageInRequest());
    }

    public function testDeserializeCollectionParsesTopLevelJsonArrayBody(): void
    {
        // A bulk endpoint whose requestBody schema is `type: array` sends a top-level JSON array
        // (`[{...}, {...}]`). deserializeCollection() turns it into a list of item DTOs.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulk');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $itemClass */
        $itemClass = '\\GenBulk\\Item';
        $deserializer = new DtoDeserializer();

        // Happy path: top-level array of objects → list of DTOs.
        /** @var array<int, object{getId: callable}> $items */
        $items = $deserializer->deserializeCollection(
            $this->jsonPostRequest('[{"id":1},{"id":2},{"id":3}]'),
            $itemClass,
        );
        $this->assertCount(3, $items);
        $this->assertSame([1, 2, 3], array_map(static fn(object $i): int => $i->getId(), $items));

        // Empty array → empty list.
        $this->assertSame([], $deserializer->deserializeCollection($this->jsonPostRequest('[]'), $itemClass));
    }

    public function testDeserializeCollectionOfScalars(): void
    {
        // requestBody: {type: array, items: {type: <scalar>}} → top-level array of scalars.
        $deserializer = new DtoDeserializer();

        $this->assertSame(
            [1, 2, 3],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,2,3]'), 'int'),
        );
        $this->assertSame(
            [1.5, 2.5],
            $deserializer->deserializeCollection($this->jsonPostRequest('[1.5,2.5]'), 'float'),
        );
        $this->assertSame(
            ['test1', 'test2'],
            $deserializer->deserializeCollection($this->jsonPostRequest('["test1","test2"]'), 'string'),
        );
        $this->assertSame(
            [true, false, true],
            $deserializer->deserializeCollection($this->jsonPostRequest('[true,false,true]'), 'bool'),
        );

        // A wrong-typed element is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('[1,"x",3]'), 'int');
            $this->fail('Expected a type error for the non-int element.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('expects int', $e->getMessage());
        }
    }

    public function testDeserializeCollectionOfEnums(): void
    {
        // requestBody: {type: array, items: {$ref: SomeEnum}} → top-level array of enum values.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk enum', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'StageEnum' => [
                        'type' => 'string',
                        'enum' => ['early', 'late'],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulkEnum');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string $enumClass */
        $enumClass = '\\GenBulkEnum\\StageEnum';
        $deserializer = new DtoDeserializer();

        /** @var array<int, UnitEnum> $enums */
        $enums = $deserializer->deserializeCollection(
            $this->jsonPostRequest('["early","late","early"]'),
            $enumClass,
        );
        $this->assertCount(3, $enums);
        $this->assertSame(
            [$enumClass::EARLY, $enumClass::LATE, $enumClass::EARLY],
            $enums,
        );

        // An unknown enum value is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('["early","WRONG"]'), $enumClass);
            $this->fail('Expected an enum error for the unknown value.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
            $this->assertStringContainsString('Allowed: early, late', $e->getMessage());
        }
    }

    public function testDeserializeCollectionOfDiscriminatedMixedObjects(): void
    {
        // A heterogeneous collection — requestBody: {type: array, items: {$ref: Pet}} where Pet
        // carries a discriminator. Each element resolves to its concrete subtype by the
        // discriminator property; you pass the BASE class, not a list of candidate classes.
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk mixed', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['petType'],
                        'properties' => ['petType' => ['type' => 'string']],
                        'discriminator' => [
                            'propertyName' => 'petType',
                            'mapping' => [
                                'dog' => '#/components/schemas/Dog',
                                'cat' => '#/components/schemas/Cat',
                            ],
                        ],
                    ],
                    'Dog' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'string']]],
                        ],
                    ],
                    'Cat' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];
        $namespace = 'GenBulkMixed';
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, $namespace);

        // Autoload by class name so parent classes load before their subtypes (require-order safe).
        spl_autoload_register(function (string $class) use ($namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $this->outputDirectory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        /** @var class-string<GeneratedDtoInterface> $baseClass */
        $baseClass = '\\' . $namespace . '\\Pet';

        $pets = (new DtoDeserializer())->deserializeCollection(
            $this->jsonPostRequest('[{"petType":"dog","bark":"woof"},{"petType":"cat","meow":"mew"}]'),
            $baseClass,
        );

        $this->assertCount(2, $pets);
        $this->assertInstanceOf('\\' . $namespace . '\\Dog', $pets[0]);
        $this->assertInstanceOf('\\' . $namespace . '\\Cat', $pets[1]);
    }

    public function testDeserializeCollectionValidatesEachElement(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Bulk', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenBulkBad');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $itemClass */
        $itemClass = '\\GenBulkBad\\Item';
        $deserializer = new DtoDeserializer();

        // An element missing a required field is reported with its index.
        try {
            $deserializer->deserializeCollection($this->jsonPostRequest('[{"id":1},{}]'), $itemClass);
            $this->fail('Expected a validation error for the invalid element.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('element 1', $e->getMessage());
        }

        // An object root (not an array) is rejected by the collection path.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON body must be an array');
        $deserializer->deserializeCollection($this->jsonPostRequest('{"id":1}'), $itemClass);
    }

    public function testDateTimeSubSecondPrecisionRoundTrips(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/datetime-precision.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenMoment');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenMoment\\Moment';

        // Microseconds are preserved on output (not silently dropped by 'c').
        /** @var object{getAt: callable} $withMicros */
        $withMicros = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"at":"2026-01-01T12:00:00.123456+00:00"}'),
            $cls,
        );
        $this->assertSame('2026-01-01T12:00:00.123456+00:00', $withMicros->getAt());

        // Whole-second values keep the plain RFC 3339 ('c') form — no spurious fraction.
        /** @var object{getAt: callable} $wholeSecond */
        $wholeSecond = (new DtoDeserializer())->deserialize(
            $this->jsonPostRequest('{"at":"2026-01-01T12:00:00+00:00"}'),
            $cls,
        );
        $this->assertSame('2026-01-01T12:00:00+00:00', $wholeSecond->getAt());
    }

    public function testValidateAndNormalizeOmitsUnprovidedOptionalField(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../fixtures/normalize-unprovided.yaml');
        (new GenerateDtoCommand())->generateFromArray($openApi, $this->outputDirectory, 'GenNormUnprov');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require $file;
        }

        /** @var class-string<GeneratedDtoInterface> $cls */
        $cls = '\\GenNormUnprov\\GenericResponse';

        // message is optional and not provided → must be omitted (not emitted as null),
        // matching the DTO's own inRequest-gated toArray().
        $dto = new $cls(true);
        $normalizer = new DtoNormalizer();

        $this->assertSame(['success' => true], $normalizer->validateAndNormalizeToArray($dto));
        $this->assertSame('{"success":true}', $normalizer->validateAndNormalizeToJson($dto));
    }
}
