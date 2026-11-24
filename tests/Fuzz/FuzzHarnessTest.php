<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * The harness's own judgement: which failures it files under a documented row, which shrink steps it
 * accepts, and the reference oracle it asks about a shrunk document. Cheap — nothing is generated.
 */
final class FuzzHarnessTest extends TestCase
{
    public function testAUnionWithAClassMemberIsCoveredByTheClassRows(): void
    {
        $union = ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'object', 'properties' => []], ['t' => 'string']]];
        $scalarUnion = ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'integer'], ['t' => 'string']]];

        $this->assertSame(['unionClassMember'], InstanceGenerator::classMemberTags($union));
        $this->assertSame([], InstanceGenerator::classMemberTags($scalarUnion));
        $this->assertSame([], InstanceGenerator::classMemberTags(['t' => 'object', 'properties' => []]));

        $tags = ['site:unionWithString', 'unionClassMember'];
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'wrongType:integer', tags: $tags));
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'wrongType:array', tags: $tags));
        // Narrow still: yii3 has no scalar row, and a union without a class member is no class site.
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'wrongType:integer', tags: $tags));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'wrongType:integer', tags: ['site:unionWithString']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Runtime, category: 'wrong-accept', kind: 'wrongType:array', tags: $tags));
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('objectShapedTopLevels')]
    public function testAShrinkIntoAnObjectShapedTopLevelIsDocumented(array $propertySchema, bool $documented): void
    {
        $document = ['components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['fox' => $propertySchema]],
            'Shape' => ['type' => 'object', 'properties' => ['edges' => ['type' => 'integer']]],
            'Word' => ['type' => 'string'],
        ]]];
        $method = new ReflectionMethod(FuzzRunner::class, 'landsInDocumentedTopLevel');

        $this->assertSame($documented, $method->invoke(null, $document, 'fox', [1, 2], GenerationMode::Yii3));
        $this->assertFalse($method->invoke(null, $document, 'fox', [1, 2], GenerationMode::Runtime));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function objectShapedTopLevels(): iterable
    {
        $shape = ['$ref' => '#/components/schemas/Shape'];

        yield 'a $ref' => [$shape, true];
        yield 'a single-member anyOf' => [['anyOf' => [$shape]], true];
        yield 'a single-member oneOf' => [['oneOf' => [$shape]], true];
        yield 'objects or null' => [['oneOf' => [$shape, ['type' => 'object'], ['type' => 'null']]], true];
        // The row's own last sentences: a union of a class, or a free-form object, and a scalar.
        yield 'an object beside a string' => [['anyOf' => [$shape, ['$ref' => '#/components/schemas/Word']]], true];
        yield 'a free-form object beside a boolean' => [['anyOf' => [['type' => 'object'], ['type' => 'boolean']]], true];
        yield 'a list of objects, one level down' => [['type' => 'array', 'items' => $shape], false];
        yield 'a string' => [['type' => 'string'], false];
        yield 'a string or a list' => [['anyOf' => [['type' => 'string'], ['type' => 'array']]], false];
    }

    /**
     * Every scalar mutation of a branch beside a class member is tagged as such, whatever constraint of
     * its own branch it breaks: symfony/serializer 7.4.10 builds the scalar into an empty class first.
     */
    public function testAScalarBranchMutationBesideAClassMemberIsTagged(): void
    {
        $object = ['t' => 'object', 'props' => ['newt' => ['t' => 'integer']], 'required' => [], 'additional' => null];
        $union = ['t' => 'union', 'op' => 'anyOf', 'branches' => [
            $object,
            ['t' => 'integer', 'enum' => [4]],
            ['t' => 'string', 'pattern' => array_key_first(SchemaGrammar::PATTERNS), 'maxLength' => 3],
        ]];
        $method = new ReflectionMethod(InstanceGenerator::class, 'unionMutations');
        /** @var array<int, array{kind: string, value: mixed, tags: array<int, string>}> $mutations */
        $mutations = $method->invoke(new InstanceGenerator(new FuzzRandom(7)), $union);

        $scalarKinds = [];
        foreach ($mutations as $mutation) {
            $isScalar = !is_array($mutation['value']) && !$mutation['value'] instanceof stdClass && $mutation['value'] !== null;
            $this->assertSame($isScalar, in_array('unionClassMember', $mutation['tags'], true), $mutation['kind']);
            if ($isScalar) {
                $scalarKinds[] = $mutation['kind'];
                $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: $mutation['kind'], tags: $mutation['tags']), $mutation['kind']);
            }
        }
        $this->assertContains('notInEnum', $scalarKinds);
        $this->assertContains('tooLong', $scalarKinds);
        // Not a yii3 row, and not without the class member.
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'notInEnum', tags: ['in:union', 'unionClassMember', 'site:enum']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'notInEnum', tags: ['in:union', 'site:enum']));
    }

    /**
     * The row's shapes read off the shrunk document, not off the tags the original mutation carried.
     *
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('documentedShapes')]
    public function testADocumentedShapeIsMatchedOnTheSchema(array $schemas, string $json, GenerationMode $mode, bool $documented): void
    {
        $document = ['components' => ['schemas' => $schemas]];
        $method = new ReflectionMethod(FuzzRunner::class, 'documentedShapeRow');
        $value = json_decode($json);
        $property = (string)array_key_first(get_object_vars($value));

        $this->assertSame($documented, $method->invoke(null, $document, $property, $value->{$property}, $mode) !== null);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: GenerationMode, 3: bool}>
     */
    public static function documentedShapes(): iterable
    {
        $probe = static fn(array $properties, array $more = []): array => ['Probe' => ['type' => 'object', 'properties' => $properties]] + $more;
        $lime = ['Lime' => ['type' => 'object', 'properties' => ['llama' => ['type' => 'string']]]];
        $limeRef = ['$ref' => '#/components/schemas/Lime'];
        $freeBesideBoolean = $probe(['raven' => ['anyOf' => [['type' => 'object'], ['type' => 'boolean']]]]);

        yield 'JSON array beside a free-form object, symfony' => [$freeBesideBoolean, '{"raven":[7]}', GenerationMode::Symfony, true];
        yield 'JSON array beside a free-form object, yii3' => [$freeBesideBoolean, '{"raven":[null]}', GenerationMode::Yii3, true];
        yield 'JSON array beside a free-form object, runtime' => [$freeBesideBoolean, '{"raven":[7]}', GenerationMode::Runtime, false];
        yield 'JSON array beside a string object' => [$probe(['macaw' => ['anyOf' => [['type' => 'string'], ['type' => 'object']]]]), '{"macaw":["x"]}', GenerationMode::Symfony, true];
        yield 'scalar beside a class' => [$probe(['otter' => ['anyOf' => [['type' => 'object', 'properties' => ['newt' => ['type' => 'integer']]], ['type' => 'integer', 'enum' => [4]]]]]), '{"otter":29}', GenerationMode::Symfony, true];
        yield 'scalar beside a closed object' => [$probe(['vole' => ['oneOf' => [['type' => 'boolean'], ['type' => 'object', 'additionalProperties' => false]]]]), '{"vole":"cjh"}', GenerationMode::Symfony, true];
        yield 'scalar beside a free-form object is no class' => [$freeBesideBoolean, '{"raven":7}', GenerationMode::Symfony, false];
        yield 'scalar beside a class, yii3' => [$probe(['otter' => ['anyOf' => [$limeRef, ['type' => 'integer']]]], $lime), '{"otter":29}', GenerationMode::Yii3, false];
        yield 'scalar item of a list of classes' => [$probe(['lemur' => ['type' => 'array', 'items' => $limeRef]], $lime), '{"lemur":["x"]}', GenerationMode::Symfony, true];
        yield 'null item of a list of classes' => [$probe(['lemur' => ['type' => 'array', 'items' => $limeRef]], $lime), '{"lemur":[null]}', GenerationMode::Symfony, true];
        yield 'null value of a map of classes' => [$probe(['zebu' => ['type' => 'object', 'additionalProperties' => $limeRef]], $lime), '{"zebu":{"ka":null}}', GenerationMode::Symfony, true];
        yield 'null for a class property is no item' => [$probe(['lemur' => $limeRef], $lime), '{"lemur":null}', GenerationMode::Symfony, false];
        yield 'scalar below a class property' => [$probe(['lemur' => $limeRef], $lime), '{"lemur":{"llama":5}}', GenerationMode::Symfony, false];
        yield 'string item of a list of strings' => [$probe(['lemur' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 1]]]), '{"lemur":["xyz"]}', GenerationMode::Symfony, false];
    }

    /**
     * yii3 reads an integer beside a `format: date` member as a Unix timestamp when the union also
     * admits `null` or an array, whatever branch the integer was meant for.
     */
    public function testAnIntegerBesideADateAndNullIsTheTimestampRow(): void
    {
        $date = ['t' => 'string', 'format' => 'date'];
        $method = new ReflectionMethod(InstanceGenerator::class, 'unionMutations');
        $generator = new InstanceGenerator(new FuzzRandom(3));
        $unions = [
            'nullable integer' => ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'integer', 'maximum' => -36, 'exclusiveMaximum' => false, 'nullable' => true], $date]],
            'number enum and null' => ['t' => 'union', 'op' => 'oneOf', 'branches' => [['t' => 'number', 'enum' => [-5]], $date, ['t' => 'null']]],
        ];
        foreach ($unions as $label => $union) {
            /** @var array<int, array{kind: string, value: mixed, tags: array<int, string>}> $mutations */
            $mutations = $method->invoke($generator, $union);
            $integers = array_values(array_filter($mutations, static fn(array $mutation): bool => is_int($mutation['value'])));
            $this->assertNotSame([], $integers, $label);
            foreach ($integers as $mutation) {
                $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: $mutation['kind'], tags: $mutation['tags']), $label);
            }
        }
        // A date beside an integer alone is not the row.
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'aboveMaximum', tags: ['in:union', 'unionWithString', 'integerBesideTemporal', 'site:integer']));
    }

    /**
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('referenceVerdicts')]
    public function testTheReferenceOracleJudgesTheShrunkDocument(array $schemas, string $json, ?bool $accepted): void
    {
        $document = ['components' => ['schemas' => $schemas]];

        $this->assertSame($accepted, (new ReferenceOracle($document))->accepts($json));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: bool|null}>
     */
    public static function referenceVerdicts(): iterable
    {
        $probe = static fn(array $fox, array $more = []): array => ['Probe' => ['type' => 'object', 'properties' => ['fox' => $fox]]] + $more;

        yield 'enum refuses a stranger' => [$probe(['type' => 'string', 'enum' => ['red']]), '{"fox":"zzq"}', false];
        // The `notInEnum` case whose enum was shrunk away: the payload is valid now.
        yield 'enum gone' => [$probe(['type' => 'string']), '{"fox":"zzq"}', true];
        // `{}` admits everything: no refusal can be expected of it.
        yield 'empty component' => [$probe(['$ref' => '#/components/schemas/Crimson'], ['Crimson' => []]), '{"fox":{"zz":1}}', true];
        yield 'JSON array for an object' => [$probe(['type' => 'object']), '{"fox":[1]}', false];
        yield '3.0 nullable' => [$probe(['type' => 'string', 'nullable' => true]), '{"fox":null}', true];
        yield 'oneOf counts matches' => [$probe(['oneOf' => [['type' => 'integer'], ['type' => 'number']]]), '{"fox":2}', false];
        yield 'required' => [$probe(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]), '{"fox":{}}', false];
        yield 'closed object' => [$probe(['type' => 'object', 'additionalProperties' => false]), '{"fox":{"b":1}}', false];
        yield 'impossible date' => [$probe(['type' => 'string', 'format' => 'date']), '{"fox":"2026-02-30"}', false];
        yield 'loose date-time' => [$probe(['type' => 'string', 'format' => 'date-time']), '{"fox":"2026-01-02"}', false];
        yield 'exclusive bound' => [$probe(['type' => 'integer', 'minimum' => 3, 'exclusiveMinimum' => true]), '{"fox":3}', false];
        yield 'multipleOf' => [$probe(['type' => 'number', 'multipleOf' => 0.5]), '{"fox":1.5}', true];
        yield 'unknown format is no answer' => [$probe(['type' => 'string', 'format' => 'hostname']), '{"fox":"x"}', null];
    }
}
