<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

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
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/gap1-probe.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, $namespace);

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
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/optional-field-validation.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, $namespace);

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
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/int-format.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, $namespace);

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
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/array-datetime.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, $namespace);

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

    public function testArrayOfDateTimeItemsAreParsedThroughGeneratedDto(): void
    {
        // Regression: array<DateTimeImmutable> items used to fail deserialization —
        // (1) resolveFileImports dropped the first char of imported short names, and
        // (2) castArrayItemValue routed datetime items through the nested-DTO branch.
        $cls = $this->generateEventModel('GapArrDt');

        $dto = new DtoDeserializer()->deserialize(
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
        new DtoDeserializer()->deserialize($this->jsonPostRequest('{"dates":[123]}'), $cls);
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
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/array-enum-dto.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, $namespace);

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

        $dto = new DtoDeserializer()->deserialize(
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
        new DtoDeserializer()->deserialize(
            $this->jsonPostRequest('{"colors":["magenta"],"tags":[]}'),
            $cls,
        );
    }

    public function testIntBackedEnumAcceptsQueryStringValue(): void
    {
        // Regression: int-backed enum case (value 1) never strict-equalled the incoming "1"
        // from a query parameter (Symfony delivers query/path/form as strings).
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/int-enum-query.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapIntEnum');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapIntEnum\\Filter';
        // status arrives from the query bag as the string "2"; body is empty.
        $request = Request::create('/?status=2', 'GET');
        $dto = new DtoDeserializer()->deserialize($request, $cls);
        $this->assertSame(2, $dto->getStatus()->value);
    }

    public function testIntOverflowStringFromQueryIsRejected(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/int-enum-query.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapIntOverflow');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapIntOverflow\\Filter';
        // count = 23 nines: (int) cast would saturate to PHP_INT_MAX — must be rejected instead.
        $this->expectException(RuntimeException::class);
        new DtoDeserializer()->deserialize(Request::create('/?status=1&count=99999999999999999999999', 'GET'), $cls);
    }

    public function testIfWithRefConditionDoesNotForceThen(): void
    {
        // Regression: if:{$ref} extracted to an empty (vacuously-true) schema, so `then`
        // (discountCode required) was applied to EVERY value. The unvalidatable if/then is dropped.
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/if-ref.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapIfRef');
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

    public function testCyclicDtoGraphSerializesWithoutInfiniteRecursion(): void
    {
        // Regression: toArray()/normalizeValue had no cycle guard (unlike validate()).
        // Mutual references built via array adders used to recurse until stack exhaustion.
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/cyclic-node.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapCycle');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapCycle\\Node';
        $a = new $cls('A');
        $b = new $cls('B');
        $a->addItemToChildren($b);
        $b->addItemToChildren($a); // A → B → A cycle

        $array = new DtoNormalizer()->toArray($a);

        // Terminates; A is seeded in the cycle guard, so the back-reference to A inside B is
        // cut (null) instead of recursing forever.
        $this->assertSame('A', $array['name']);
        $this->assertSame('B', $array['children'][0]['name']);
        $this->assertSame([null], $array['children'][0]['children']);
    }

    public function testCyclicDtoGraphIsReportedByValidateAndRejectedByValidateAndNormalize(): void
    {
        // Serialization cuts a cycle to null; validation must AGREE (report the cycle) so a
        // caller of validateAndNormalizeToArray() isn't told "valid" alongside corrupted output.
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/cyclic-node.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapCycleValidate');
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
        // Root node that contains itself — the root is marked in $visited (parity with
        // validateDtoRecursive) so the self-reference is cut at one level.
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/cyclic-node.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapSelfRef');
        $files = glob($this->outputDirectory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require $file;
        }

        $cls = '\\GapSelfRef\\Node';
        $node = new $cls('root');
        $node->addItemToChildren($node);

        $array = new DtoNormalizer()->toArray($node);
        $this->assertSame('root', $array['name']);
        $this->assertSame([null], $array['children']);
    }

    public function testOneOfWithRefVariantDoesNotEmitUnvalidatableConstraint(): void
    {
        // Regression: a oneOf with a $ref variant extracted an empty branch that the
        // validator treated as always-matching → false "matches more than one oneOf
        // branch". The unvalidatable union must be dropped from getConstraints().
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/oneof-ref.yaml');
        new GenerateDtoCommand()->generateFromArray($openApi, $this->outputDirectory, 'GapOneOfRef');
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
        $dto = new DtoDeserializer()->deserialize($this->jsonPostRequest('{"value":"hello"}'), $cls);
        $this->assertSame([], new DtoNormalizer()->validate($dto));
    }

    public function testInt32FormatRangeIsEnforcedThroughGeneratedDto(): void
    {
        $cls = $this->generateIntFormatModel('GapInt32');
        $deserializer = new DtoDeserializer();

        // In-range value deserializes fine.
        $dto = $deserializer->deserialize($this->jsonPostRequest('{"small":100}'), $cls);
        $this->assertSame([], new DtoNormalizer()->validate($dto));

        // Over-range int32 is rejected during deserialization constraint checks.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('within int32 range');
        $deserializer->deserialize($this->jsonPostRequest('{"small":5000000000}'), $cls);
    }

    public function testOmittedOptionalNonNullableFieldDoesNotProduceFalseValidationError(): void
    {
        $cls = $this->generateOptionalFieldModel('GapOptOmitted');

        // Request provides only the required field; optional non-nullable string/int omitted.
        $dto = new DtoDeserializer()->deserialize($this->jsonPostRequest('{"id":5}'), $cls);

        $errors = new DtoNormalizer()->validate($dto);

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
}
