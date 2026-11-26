<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\Parity\PayloadVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * The harness's own judgement: which failures it files under a documented row, which shrink steps it
 * accepts, and the reference oracle it asks about a shrunk document. Cheap — only the member-order test
 * generates, two small documents in symfony mode.
 */
final class FuzzHarnessTest extends TestCase
{
    /**
     * The laravel-data 422 guard of README.support-matrix's "a discriminated union inside the FIRST member
     * of an inline union of classes": a valid object of the second member whose same-named property is no
     * object. Nothing filed it, so every such seed was a finding. Narrow: the other modes, a member order
     * that puts the union second, a sibling whose property is an object too, and a value whose property is
     * an object are not that row.
     */
    public function testTheFirstMemberDiscriminatedGuardIsFiledUnderItsRow(): void
    {
        $ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $document = ['components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => [
                'macaw' => ['anyOf' => [$ref('Scarlet'), $ref('Sienna')], 'discriminator' => ['propertyName' => 'kind']],
                'flock' => ['type' => 'array', 'items' => ['oneOf' => [$ref('Scarlet'), $ref('Sienna')]]],
                'swapped' => ['anyOf' => [$ref('Sienna'), $ref('Scarlet')]],
            ]],
            'Olive' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['Olive']]], 'required' => ['kind']],
            'Coral' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['Coral']]], 'required' => ['kind']],
            'Azure' => ['oneOf' => [$ref('Olive'), $ref('Coral')], 'discriminator' => ['propertyName' => 'kind']],
            'Scarlet' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['Scarlet']], 'robin' => $ref('Azure')], 'required' => ['kind', 'robin']],
            'Sienna' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['Sienna']], 'robin' => ['type' => 'integer']], 'required' => ['kind']],
        ]]];
        $sienna = json_decode('{"kind":"Sienna","robin":30}');
        $explain = static fn(GenerationMode $mode, string $property, mixed $value, array $document, string $category = 'wrong-refuse'): ?string => KnownDivergences::explainFirstMemberDiscriminatedGuard(
            mode: $mode,
            category: $category,
            document: $document,
            property: $property,
            value: $value,
        );

        $this->assertNotNull($explain(GenerationMode::LaravelData, 'macaw', $sienna, $document));
        $this->assertNotNull($explain(GenerationMode::LaravelData, 'flock', [$sienna], $document));

        $this->assertNull($explain(GenerationMode::Laravel, 'macaw', $sienna, $document));
        $this->assertNull($explain(GenerationMode::LaravelData, 'macaw', $sienna, $document, 'wrong-accept'));
        $this->assertNull($explain(GenerationMode::LaravelData, 'swapped', $sienna, $document));
        $this->assertNull($explain(GenerationMode::LaravelData, 'macaw', json_decode('{"kind":"Sienna"}'), $document));
        $this->assertNull($explain(GenerationMode::LaravelData, 'macaw', json_decode('{"kind":"Scarlet","robin":{"kind":"Olive"}}'), $document));
        $objectSibling = $document;
        $objectSibling['components']['schemas']['Sienna']['properties']['robin'] = ['type' => 'object'];
        $this->assertNull($explain(GenerationMode::LaravelData, 'macaw', $sienna, $objectSibling));
    }

    public function testAUnionWithAClassMemberIsCoveredByTheClassRows(): void
    {
        $union = ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'object', 'properties' => []], ['t' => 'string']]];
        $scalarUnion = ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'integer'], ['t' => 'string']]];

        $this->assertSame(['unionClassMember'], InstanceGenerator::classMemberTags($union));
        $this->assertSame([], InstanceGenerator::classMemberTags($scalarUnion));
        $this->assertSame([], InstanceGenerator::classMemberTags(['t' => 'object', 'properties' => []]));

        $tags = ['site:unionWithString', 'unionClassMember', 'classRequiresNothing'];
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
                // The object member requires nothing: the runner tags it so ({@see FuzzRunner::tagClassesRequiringNothing()}).
                $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: $mutation['kind'], tags: [...$mutation['tags'], 'classRequiresNothing']), $mutation['kind']);
            }
        }
        $this->assertContains('notInEnum', $scalarKinds);
        $this->assertContains('tooLong', $scalarKinds);
        // Not a yii3 row, and not without the class member.
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'notInEnum', tags: ['in:union', 'unionClassMember', 'site:enum']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'notInEnum', tags: ['in:union', 'site:enum']));
    }

    /**
     * `{}` for an object that requires a key beside an `array` member, or for a bare list, is the `[]`
     * symfony and yii3 decode it to: tagged, and filed under the empty-container row for those two
     * (`anyOf: [{type: object, minProperties: 1}, {type: array}]` took `{}` in symfony too).
     */
    public function testAnEmptyObjectBesideAListIsTheEmptyContainerRow(): void
    {
        $object = ['t' => 'object', 'props' => ['dingo' => ['t' => 'string']], 'required' => ['dingo'], 'additional' => null];
        $unions = [
            'beside a list' => [['t' => 'union', 'op' => 'anyOf', 'branches' => [$object, ['t' => 'array', 'items' => ['t' => 'integer'], 'unique' => false]]], true],
            'beside an integer' => [['t' => 'union', 'op' => 'anyOf', 'branches' => [$object, ['t' => 'integer']]], false],
        ];
        foreach ($unions as $label => [$union, $besideList]) {
            // `mutations()` samples: a few seeds between them draw the missing key.
            $empty = [];
            foreach (range(1, 12) as $seed) {
                foreach ((new InstanceGenerator(new FuzzRandom($seed)))->mutations($union) as $mutation) {
                    if ($mutation['kind'] === 'missingRequired') {
                        $empty[] = $mutation;
                    }
                }
            }
            $this->assertNotSame([], $empty, $label);
            foreach ($empty as $mutation) {
                $this->assertSame($besideList, in_array('emptyBesideArray', $mutation['tags'], true), $label);
                foreach ([GenerationMode::Symfony, GenerationMode::Yii3] as $mode) {
                    $row = KnownDivergences::explain(mode: $mode, category: 'wrong-accept', kind: $mutation['kind'], tags: $mutation['tags']);
                    $this->assertSame($besideList, $row !== null, $label . ', ' . $mode->value);
                }
            }
        }
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'tooFewProperties', tags: ['in:union', 'site:map', 'emptyBesideArray']));
        $tags = ['in:union', 'site:dto', 'emptyBesideArray'];
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Runtime, category: 'wrong-accept', kind: 'missingRequired', tags: $tags));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Laravel, category: 'wrong-accept', kind: 'missingRequired', tags: $tags));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-refuse', kind: 'missingRequired', tags: $tags));
    }

    /**
     * `{}` is also drawn as a VALID instance of an object member that asks for no key beside an `array`
     * member — the runtime once refused it on re-validation, judged as the list member's `minItems`,
     * and the fuzzer never sent it. Symfony and yii3 still read it as `[]`: their refusal is the
     * empty-container row; every other mode must accept it.
     */
    public function testAValidEmptyObjectIsDrawnBesideAListMember(): void
    {
        $object = ['t' => 'object', 'props' => ['dingo' => ['t' => 'string']], 'required' => [], 'additional' => null];
        $requiring = ['required' => ['dingo']] + $object;
        $list = ['t' => 'array', 'items' => ['t' => 'integer'], 'minItems' => 1, 'unique' => false];
        $unions = [
            'beside a list' => [['t' => 'union', 'op' => 'oneOf', 'branches' => [$object, $list]], true],
            'requiring a key, beside a list' => [['t' => 'union', 'op' => 'anyOf', 'branches' => [$requiring, $list]], false],
            'beside an integer' => [['t' => 'union', 'op' => 'anyOf', 'branches' => [$object, ['t' => 'integer']]], false],
        ];
        foreach ($unions as $label => [$union, $drawn]) {
            $tagged = [];
            foreach (range(1, 40) as $seed) {
                $instance = (new InstanceGenerator(new FuzzRandom($seed)))->validInstance($union);
                if (in_array('emptyBesideArray', $instance['tags'], true)) {
                    $this->assertEquals(expected: new stdClass(), actual: $instance['value'], message: $label);
                    $tagged[] = $instance['tags'];
                }
            }
            $this->assertSame($drawn, $tagged !== [], $label);
        }

        $tags = ['emptyBesideArray'];
        foreach ([GenerationMode::Symfony, GenerationMode::Yii3] as $mode) {
            $this->assertNotNull(KnownDivergences::explain(mode: $mode, category: 'wrong-refuse', kind: 'valid', tags: $tags), $mode->value);
        }
        foreach ([GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData] as $mode) {
            $this->assertNull(KnownDivergences::explain(mode: $mode, category: 'wrong-refuse', kind: 'valid', tags: $tags), $mode->value);
        }
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
        $puffin = ['anyOf' => [
            ['type' => 'object', 'properties' => ['dingo' => ['type' => 'string']], 'required' => ['dingo']],
            ['type' => 'array', 'items' => ['type' => 'integer']],
        ]];
        yield 'empty object beside a list, yii3' => [$probe(['puffin' => $puffin]), '{"puffin":{}}', GenerationMode::Yii3, true];
        yield 'empty object for a list, yii3' => [$probe(['toucan' => ['type' => 'array']]), '{"toucan":{}}', GenerationMode::Yii3, true];
        yield 'empty object beside a list, runtime' => [$probe(['puffin' => $puffin]), '{"puffin":{}}', GenerationMode::Runtime, false];
        yield 'non-empty object beside a list, yii3' => [$probe(['puffin' => $puffin]), '{"puffin":{"zz":1}}', GenerationMode::Yii3, false];
        yield 'empty object without a list member, yii3' => [$probe(['puffin' => ['type' => 'object', 'required' => ['dingo']]]), '{"puffin":{}}', GenerationMode::Yii3, false];
        yield 'string item of a list of strings' => [$probe(['lemur' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 1]]]), '{"lemur":["xyz"]}', GenerationMode::Symfony, false];

        // The misfiled shapes of the 2.15.160 audit: each met a row the framework does not take there,
        // so a real defect was filed as documented.
        $objectOrShortList = ['anyOf' => [
            ['type' => 'object', 'properties' => ['newt' => ['type' => 'integer']]],
            ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 1],
        ]];
        yield 'JSON array a list member beside an object takes, symfony' => [$probe(['heron' => $objectOrShortList]), '{"heron":[1,2]}', GenerationMode::Symfony, false];
        yield 'JSON array a list member beside an object takes, yii3' => [$probe(['heron' => $objectOrShortList]), '{"heron":[1,2]}', GenerationMode::Yii3, false];
        yield 'JSON array an untyped member beside an object takes' => [$probe(['heron' => ['anyOf' => [$limeRef, ['description' => 'any']]]], $lime), '{"heron":[1]}', GenerationMode::Symfony, false];
        yield 'JSON array a nested list member beside an object takes' => [$probe(['heron' => ['anyOf' => [$limeRef, ['oneOf' => [['type' => 'string'], ['type' => 'array', 'maxItems' => 0]]]]]], $lime), '{"heron":[1]}', GenerationMode::Symfony, false];
        $tally = ['Tally' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]];
        $tallyRef = ['$ref' => '#/components/schemas/Tally'];
        yield 'scalar beside a component map' => [$probe(['ibis' => ['anyOf' => [$tallyRef, ['type' => 'integer', 'minimum' => 3]]]], $tally), '{"ibis":1}', GenerationMode::Symfony, false];
        yield 'null item of a list of component maps' => [$probe(['ibis' => ['type' => 'array', 'items' => $tallyRef]], $tally), '{"ibis":[null]}', GenerationMode::Symfony, false];
        $figure = ['Figure' => ['oneOf' => [
            ['type' => 'object', 'properties' => ['radius' => ['type' => 'number']], 'required' => ['radius']],
            ['type' => 'object', 'properties' => ['side' => ['type' => 'number']], 'required' => ['side']],
        ]]];
        yield 'scalar beside a component union of objects' => [$probe(['ibis' => ['anyOf' => [['$ref' => '#/components/schemas/Figure'], ['type' => 'boolean']]]], $figure), '{"ibis":"x"}', GenerationMode::Symfony, false];
        $requiringLime = ['Lime' => ['required' => ['llama']] + $lime['Lime']];
        yield 'scalar beside a class requiring a key' => [$probe(['otter' => ['anyOf' => [['type' => 'object', 'properties' => ['newt' => ['type' => 'integer']], 'required' => ['newt']], ['type' => 'integer', 'enum' => [4]]]]]), '{"otter":29}', GenerationMode::Symfony, false];
        yield 'scalar item of a list of classes requiring a key' => [$probe(['lemur' => ['type' => 'array', 'items' => $limeRef]], $requiringLime), '{"lemur":["x"]}', GenerationMode::Symfony, false];
        yield 'null item of a list of classes requiring a key' => [$probe(['lemur' => ['type' => 'array', 'items' => $limeRef]], $requiringLime), '{"lemur":[null]}', GenerationMode::Symfony, false];
        yield 'null item of a list of allOf classes requiring a key' => [$probe(['lemur' => ['type' => 'array', 'items' => ['allOf' => [$limeRef]]]], $requiringLime), '{"lemur":[null]}', GenerationMode::Symfony, false];
    }

    /**
     * The `classRequiresNothing` tag the class rows of {@see KnownDivergences} need is set by the runner
     * on a scalar or `null` that meets a class whose `required` is empty — and on nothing else.
     */
    public function testAClassRequiringNothingIsTaggedOffTheSchema(): void
    {
        $method = new ReflectionMethod(FuzzRunner::class, 'tagClassesRequiringNothing');
        $schemas = [
            'Probe' => ['type' => 'object', 'properties' => [
                'loose' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Loose']],
                'strict' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Strict']],
                'either' => ['anyOf' => [['$ref' => '#/components/schemas/Loose'], ['type' => 'string', 'maxLength' => 2]]],
            ]],
            'Loose' => ['type' => 'object', 'properties' => ['petal' => ['type' => 'string']]],
            'Strict' => ['type' => 'object', 'properties' => ['petal' => ['type' => 'string']], 'required' => ['petal']],
        ];
        $payload = static fn(string $property, string $json): array => ['property' => $property, 'kind' => 'wrongType:string', 'json' => $json, 'expected' => PayloadVerdict::Refused, 'tags' => ['in:items']];
        $payloads = [
            $payload('loose', '{"loose":["x"]}'),
            $payload('loose', '{"loose":[null]}'),
            $payload('strict', '{"strict":["x"]}'),
            $payload('either', '{"either":"long"}'),
            $payload('loose', '{"loose":[{"petal":5}]}'),
            ['property' => null, 'kind' => 'empty', 'json' => '{}', 'expected' => PayloadVerdict::Accepted, 'tags' => []],
        ];

        $tagged = array_map(
            callback: static fn(array $payload): bool => in_array('classRequiresNothing', $payload['tags'], true),
            array: $method->invoke(null, $payloads, ['components' => ['schemas' => $schemas]]),
        );

        $this->assertSame([true, true, false, true, false, false], $tagged);
    }

    /**
     * The class rows hold only where the class requires nothing (support matrix: a class that requires a
     * property refuses the empty object symfony/serializer 7.4.10 builds), and the date rows only on a
     * temporal site.
     */
    public function testTheClassAndDateRowsAreNarrow(): void
    {
        $scalarBesideClass = ['in:union', 'unionClassMember', 'site:enum'];
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'notInEnum', tags: $scalarBesideClass));
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'notInEnum', tags: [...$scalarBesideClass, 'classRequiresNothing']));

        foreach (['itemsOf:dto', 'valuesOf:dto'] as $container) {
            $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'nullForbidden', tags: [$container, 'site:dto']), $container);
            $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'nullForbidden', tags: [$container, 'site:dto', 'classRequiresNothing']), $container);
        }

        foreach (['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal'] as $site) {
            $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'impossibleDate:date', tags: [$site]), $site);
        }
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'impossibleDate:date', tags: ['in:union', 'unionWithString']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'looseDateTime', tags: []));
    }

    /**
     * A format mutation is more than one unparseable word: a date or date-time is also sent as a
     * string PHP's free-form parser takes (`now`, `@0`, `2020-1-2`), a date-time without an offset or
     * with one RFC 3339 does not spell; a uuid or email as a near miss. Each kind keeps the site tag the
     * rows are keyed on.
     */
    public function testFormatMutationsIncludeParseableStringsAndNearMisses(): void
    {
        $expected = [
            'date' => ['parseableNonDate:date' => ['x', 'now', 'tomorrow', '@0', '2020-1-2', '+1 day']],
            'date-time' => ['parseableNonDate:date-time' => ['x', 'now', 'tomorrow', '@0', '2020-1-2', '+1 day', '2024-01-02T10:00:00', '2024-01-02T10:00:00+0200']],
            'uuid' => ['nearMissFormat:uuid' => ['123e4567e89b42d3a456426614174000', '{123e4567-e89b-42d3-a456-426614174000}']],
            'email' => ['nearMissFormat:email' => ['@example.com', 'a.@example.com', 'a..b@example.com', 'a@example..com']],
        ];
        $offsets = [];
        $dates = [];
        foreach ($expected as $format => $kinds) {
            $node = ['t' => 'string', 'format' => $format];
            $seen = [];
            for ($seed = 1; $seed <= 200; $seed++) {
                foreach ((new InstanceGenerator(new FuzzRandom($seed)))->mutations($node) as $mutation) {
                    if (!array_key_exists($mutation['kind'], $kinds)) {
                        continue;
                    }
                    $site = $format === 'date' || $format === 'date-time' ? 'site:temporal' : 'site:string';
                    $this->assertContains($site, $mutation['tags'], $mutation['kind']);
                    $seen[$mutation['kind']][$mutation['value']] = $mutation['tags'];
                }
            }
            foreach ($kinds as $kind => $values) {
                $this->assertEqualsCanonicalizing($values, array_keys($seen[$kind] ?? []), $kind);
            }
            if ($format === 'date-time') {
                $offsets = $seen['parseableNonDate:date-time'];
            }
            if ($format === 'date') {
                $dates = $seen['parseableNonDate:date'];
            }
        }

        $this->assertContains('dateOneDigit', $dates['2020-1-2'] ?? []);
        $this->assertNotContains('dateOneDigit', $dates['now'] ?? []);
        $this->assertNotContains('dateOneDigit', $offsets['2020-1-2'] ?? []);
        $this->assertContains('dateTimeNoOffset', $offsets['2024-01-02T10:00:00'] ?? []);
        $this->assertContains('dateTimeOffsetWithoutColon', $offsets['2024-01-02T10:00:00+0200'] ?? []);
        $this->assertNotContains('dateTimeNoOffset', $offsets['now'] ?? []);
    }

    /**
     * A valid date-time is spelled every way RFC 3339 allows: `t` / `z` in lower case (tagged, for the
     * modes the support matrix documents as refusing it) and fractional seconds — three or six digits,
     * the lengths Laravel's `date_format` round-trips.
     */
    public function testValidDateTimesUseTheRfc3339Spellings(): void
    {
        $oracle = new ReferenceOracle(['components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['fox' => ['type' => 'string', 'format' => 'date-time']]]]]]);
        $lowercase = 0;
        $fraction = 0;
        for ($seed = 1; $seed <= 200; $seed++) {
            $instance = (new InstanceGenerator(new FuzzRandom($seed)))->validInstance(['t' => 'string', 'format' => 'date-time']);
            $value = $instance['value'];
            $this->assertTrue($oracle->accepts((string)json_encode(['fox' => $value])), $value);
            $isLower = preg_match('/^\d{4}-\d{2}-\d{2}t.*[z\d]$/', $value) === 1 && !str_contains($value, 'Z');
            $this->assertSame($isLower, in_array('lowercaseDateTime', $instance['tags'], true), $value);
            $lowercase += $isLower ? 1 : 0;
            $fraction += preg_match('/:\d{2}\.(\d{3}|\d{6})(?:z|Z|[+-])/', $value) === 1 ? 1 : 0;
        }
        $this->assertGreaterThan(0, $lowercase);
        $this->assertGreaterThan(0, $fraction);
    }

    /**
     * The date-time rows stay keyed on what they document: symfony's free-form parse on a temporal site,
     * the no-offset pattern every mode shares, yii3's `p` for an offset without a colon, and the four
     * modes that refuse a lower-case `t` / `z`. `format: date` is strict in symfony since 2.15.160, so
     * no row covers a parseable string sent for it.
     */
    public function testTheParseableDateTimeRowsAreNarrow(): void
    {
        $explain = static fn(GenerationMode $mode, string $category, string $kind, array $tags): ?string => KnownDivergences::explain(mode: $mode, category: $category, kind: $kind, tags: $tags);

        foreach (['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'valuesOf:temporal'] as $site) {
            $this->assertNotNull($explain(GenerationMode::Symfony, 'wrong-accept', 'parseableNonDate:date-time', [$site]), $site);
        }
        $this->assertNull($explain(GenerationMode::Symfony, 'wrong-accept', 'parseableNonDate:date-time', ['site:string']));
        $this->assertNull($explain(GenerationMode::Symfony, 'wrong-accept', 'parseableNonDate:date', ['site:temporal']));
        $this->assertNull($explain(GenerationMode::Yii3, 'wrong-accept', 'parseableNonDate:date', ['site:temporal']));
        // A one-digit month or day is read by `!Y-m-d` in symfony alone; yii3 checks the digits.
        foreach (['site:temporal', 'site:nullable-temporal', 'itemsOf:temporal', 'valuesOf:temporal'] as $site) {
            $this->assertNotNull($explain(GenerationMode::Symfony, 'wrong-accept', 'parseableNonDate:date', [$site, 'dateOneDigit']), $site);
        }
        $this->assertNull($explain(GenerationMode::Symfony, 'wrong-accept', 'parseableNonDate:date', ['site:string', 'dateOneDigit']));
        $this->assertNull($explain(GenerationMode::Yii3, 'wrong-accept', 'parseableNonDate:date', ['site:temporal', 'dateOneDigit']));

        foreach (GenerationMode::cases() as $mode) {
            $this->assertNotNull($explain($mode, 'wrong-accept', 'parseableNonDate:date-time', ['site:temporal', 'dateTimeNoOffset']), $mode->value);
            $this->assertNull($explain($mode, 'wrong-accept', 'parseableNonDate:date', ['site:temporal', 'dateTimeNoOffset']), $mode->value);
        }
        $this->assertNotNull($explain(GenerationMode::Yii3, 'wrong-accept', 'parseableNonDate:date-time', ['site:temporal', 'dateTimeOffsetWithoutColon']));
        $this->assertNull($explain(GenerationMode::Yii3, 'wrong-accept', 'parseableNonDate:date-time', ['site:temporal']));
        $this->assertNull($explain(GenerationMode::Laravel, 'wrong-accept', 'parseableNonDate:date-time', ['site:temporal', 'dateTimeOffsetWithoutColon']));

        foreach ([GenerationMode::Runtime, GenerationMode::Laravel, GenerationMode::LaravelData, GenerationMode::Yii3] as $mode) {
            $this->assertNotNull($explain($mode, 'wrong-refuse', 'valid', ['lowercaseDateTime']), $mode->value);
        }
        $this->assertNull($explain(GenerationMode::Symfony, 'wrong-refuse', 'valid', ['lowercaseDateTime']));
        $this->assertNull($explain(GenerationMode::Runtime, 'wrong-refuse', 'valid', []));
        $this->assertNull($explain(GenerationMode::Laravel, 'wrong-accept', 'nearMissFormat:email', ['site:string']));

        // A scalar beside a class member that requires nothing is built into the class, whatever it breaks.
        foreach (['parseableNonDate:date', 'nearMissFormat:uuid'] as $kind) {
            $this->assertNotNull($explain(GenerationMode::Symfony, 'wrong-accept', $kind, ['in:union', 'unionClassMember', 'classRequiresNothing']), $kind);
        }
    }

    /**
     * The key a failure is deduplicated by before its shrink carries the shape it was met in: two
     * defects of one mode, kind and descriptor in different shapes were filed under the first one's
     * shrunk case. A renamed property or component is the same shape.
     */
    public function testThePreliminaryKeyTellsShapesApart(): void
    {
        $method = new ReflectionMethod(FuzzRunner::class, 'preliminaryKey');
        $document = static fn(array $property, array $components = []): array => [
            'openapi' => '3.1.0',
            'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['crane' => $property]]] + $components],
        ];
        $parts = ['symfony', 'wrong-accept', 'tooManyItems', 'array', 'site:array'];
        $ofIntegers = $document(['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 1]);
        $ofLists = $document(['type' => 'array', 'items' => ['type' => 'array'], 'maxItems' => 1]);
        $renamed = [
            'openapi' => '3.1.0',
            'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['stork' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 4]]]]],
        ];
        $viaGold = $document(['$ref' => '#/components/schemas/Gold'], ['Gold' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 1]]);
        $viaSilver = $document(['$ref' => '#/components/schemas/Silver'], ['Silver' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 1]]);
        $viaTin = $document(['$ref' => '#/components/schemas/Tin'], ['Tin' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1]]);

        $this->assertNotSame($method->invoke(null, $parts, $ofIntegers), $method->invoke(null, $parts, $ofLists));
        $this->assertSame($method->invoke(null, $parts, $ofIntegers), $method->invoke(null, $parts, $renamed));
        $this->assertSame($method->invoke(null, $parts, $viaGold), $method->invoke(null, $parts, $viaSilver));
        $this->assertNotSame($method->invoke(null, $parts, $viaGold), $method->invoke(null, $parts, $viaTin));
        $this->assertNotSame($method->invoke(null, $parts, $ofIntegers), $method->invoke(null, ['runtime', ...array_slice($parts, 1)], $ofIntegers));
    }

    /**
     * yii3 reads an integer beside a `format: date` member as a Unix timestamp when the union also
     * admits `null` or an array, whatever branch the integer was meant for.
     */
    public function testAnIntegerBesideADateAndNullIsTheTimestampRow(): void
    {
        $date = ['t' => 'string', 'format' => 'date'];
        $method = new ReflectionMethod(InstanceGenerator::class, 'unionMutations');
        $unions = [
            'nullable integer' => ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'integer', 'maximum' => -36, 'exclusiveMaximum' => false, 'nullable' => true], $date]],
            'number enum and null' => ['t' => 'union', 'op' => 'oneOf', 'branches' => [['t' => 'number', 'enum' => [-5]], $date, ['t' => 'null']]],
        ];
        foreach ($unions as $label => $union) {
            // Several draws: a node keeps a sample of its mutations, and one draw may hold no integer.
            $integers = [];
            for ($seed = 1; $seed <= 10; $seed++) {
                /** @var array<int, array{kind: string, value: mixed, tags: array<int, string>}> $mutations */
                $mutations = $method->invoke(new InstanceGenerator(new FuzzRandom($seed)), $union);
                $integers = [...$integers, ...array_filter($mutations, static fn(array $mutation): bool => is_int($mutation['value']))];
            }
            $this->assertNotSame([], $integers, $label);
            foreach ($integers as $mutation) {
                $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: $mutation['kind'], tags: $mutation['tags']), $label);
            }
        }
        // A date beside an integer alone is not the row.
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'aboveMaximum', tags: ['in:union', 'unionWithString', 'integerBesideTemporal', 'site:integer']));
    }

    /**
     * A shrink keeps the constraint the mutation broke: the report keeps the mutation's `kind`, so a
     * document that no longer holds the keyword reports a defect it does not show (a `tooLong` whose
     * `maxLength` was dropped, and the failure carried on by an `enum` beside it).
     */
    #[DataProvider('shrinkKinds')]
    public function testAShrinkKeepsTheKeywordTheMutationBroke(string $kind, bool $kept): void
    {
        $document = ['components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['ocelot' => ['$ref' => '#/components/schemas/Ivorya']]],
            'Ivorya' => ['type' => 'array', 'maxItems' => 2, 'items' => ['$ref' => '#/components/schemas/Umbera']],
            'Umbera' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['Umbera']],
                'egret' => ['type' => 'string', 'maxLength' => 0],
            ]],
        ]]];
        $runner = new FuzzRunner(outputDirectory: sys_get_temp_dir() . '/fuzz-harness-test', modes: [], propertiesPerSpec: 1);
        $method = new ReflectionMethod(FuzzRunner::class, 'shrinkSchema');

        $value = json_decode('[{"kind":"Umbera","egret":"x"}]');

        // Every candidate "still fails": only the guard stands between the shrink and an empty schema.
        $shrunk = $method->invoke($runner, $document, 'ocelot', static fn(array $candidate): bool => true, [], $kind, $value);

        $this->assertSame($kept, str_contains((string)json_encode($shrunk), '"maxLength"'), $kind);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function shrinkKinds(): iterable
    {
        yield 'tooLong keeps maxLength' => ['tooLong', true];
        // A kind whose keyword the document never held is not held back, nor is a type mutation.
        yield 'wrongType shrinks freely' => ['wrongType:array', false];
        yield 'a keyword the document lacks' => ['patternMismatch', false];
    }

    /**
     * The keyword is held where the payload goes, not anywhere in the document: with a `format` on the
     * mutated property and another on an unrelated one, a shrink that drops the first was kept — the
     * second still "held" it — and a `looseDateTime` was reported for a string sent where only
     * `type: null` was left.
     */
    public function testAShrinkKeepsTheKeywordOnThePathThePayloadTakes(): void
    {
        $document = ['components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['quokka' => ['$ref' => '#/components/schemas/Ochre']]],
            'Ochre' => ['type' => 'object', 'properties' => [
                'tern' => ['type' => 'string', 'format' => 'date-time'],
                'puffin' => ['oneOf' => [['$ref' => '#/components/schemas/Teal'], ['type' => 'null']]],
            ]],
            'Teal' => ['type' => 'string', 'format' => 'date-time'],
        ]]];
        $value = json_decode('{"puffin":"2026-01-02"}');
        $runner = new FuzzRunner(outputDirectory: sys_get_temp_dir() . '/fuzz-harness-test', modes: [], propertiesPerSpec: 1);
        $method = new ReflectionMethod(FuzzRunner::class, 'shrinkSchema');

        $shrunk = $method->invoke($runner, $document, 'quokka', static fn(array $candidate): bool => true, ['puffin'], 'looseDateTime', $value);

        // The same shrink step the old guard let through: the mutated property's format gone, the
        // unrelated one kept.
        $guard = new ReflectionMethod(FuzzRunner::class, 'holdsKeywordAlong');
        $this->assertTrue($guard->invoke(null, $shrunk, 'quokka', $value, ['format']), (string)json_encode($shrunk));
        $stale = $document;
        $stale['components']['schemas']['Ochre']['properties']['puffin'] = ['type' => 'null'];
        $this->assertFalse($guard->invoke(null, $stale, 'quokka', $value, ['format']));
        $this->assertTrue($guard->invoke(null, $document, 'quokka', $value, ['format']));
    }

    /**
     * A union member that refuses a body only for a key it does not declare — `additionalProperties:
     * false`, or a `{schema}` the key's value breaks — is built by the Symfony serializer without that
     * key, so symfony accepts what the document refuses. Matched by kind alone (`extraKeyClosed` on a
     * DTO site, `in:additional`), the refusals that landed there under another kind — the member the
     * mutation broke missing a required key, a sibling closed — were reported as symfony defects.
     */
    #[DataProvider('unionMembersRefusingOnlyAnUndeclaredKey')]
    public function testAUnionMemberRefusingOnlyAnUndeclaredKeyIsTheClosedObjectRow(array $schemas, string $json): void
    {
        $oracle = new ReferenceOracle(['components' => ['schemas' => $schemas]]);

        $this->assertFalse($oracle->accepts($json));
        $this->assertTrue($oracle->acceptsWithUnionMembersOpen($json));

        $tags = ['in:union', 'unionObjectPair', FuzzSpec::REFUSED_ONLY_BY_UNDECLARED_KEY];
        foreach (['missingRequired', 'wrongType:object', 'patternMismatch', 'extraKeyClosed'] as $kind) {
            $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: $kind, tags: $tags), $kind);
            // yii3 holds the union decoded and checks it against every member: it refuses.
            $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: $kind, tags: $tags), $kind);
            $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Runtime, category: 'wrong-accept', kind: $kind, tags: $tags), $kind);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unionMembersRefusingOnlyAnUndeclaredKey(): iterable
    {
        $red = ['type' => 'object', 'properties' => ['hue' => ['type' => 'integer'], 'tint' => ['type' => 'number']], 'required' => ['hue', 'tint']];
        $blue = ['type' => 'object', 'properties' => ['tint' => ['type' => 'number']], 'required' => ['tint'], 'additionalProperties' => false];
        $closed = [
            'Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [['$ref' => '#/components/schemas/Red'], ['$ref' => '#/components/schemas/Blue']]]]],
            'Red' => $red,
            'Blue' => $blue,
        ];
        yield 'a stray key, closed sibling' => [$closed, '{"fox":{"tint":-2.5,"wolf":1}}'];
        yield 'a key of the other member, closed sibling' => [$closed, '{"fox":{"tint":-2.5,"hue":"x"}}'];
        $green = ['type' => 'object', 'properties' => ['shade' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'minLength' => 3]];
        yield 'an additional value the sibling\'s schema refuses' => [[
            'Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['hue' => ['type' => 'boolean'], 'tint' => ['type' => 'string']], 'required' => ['tint']],
                ['$ref' => '#/components/schemas/Green'],
            ]]]],
            'Green' => $green,
        ], '{"fox":{"hue":false}}'];
    }

    /**
     * Opening union members opens nothing else: a closed object that is no union member, and a map's
     * values, still refuse. Only the class-built view the shrinker guards with opens every class.
     */
    public function testOnlyUnionMembersAreOpened(): void
    {
        $closed = ['type' => 'object', 'properties' => ['tint' => ['type' => 'number']], 'additionalProperties' => false];
        $oracle = new ReferenceOracle(['components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['fox' => $closed]]]]]);
        $this->assertFalse($oracle->acceptsWithUnionMembersOpen('{"fox":{"tint":1,"wolf":1}}'));
        $this->assertTrue($oracle->acceptsWithUndeclaredKeysDropped('{"fox":{"tint":1,"wolf":1}}'));

        $map = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];
        $mapOracle = new ReferenceOracle(['components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [$map, ['type' => 'integer']]]]]]]]);
        $this->assertFalse($mapOracle->acceptsWithUnionMembersOpen('{"fox":{"wolf":1}}'));
        $this->assertFalse($mapOracle->acceptsWithUndeclaredKeysDropped('{"fox":{"wolf":1}}'));

        // The neighbouring rows keep their own keys.
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'extraKeyClosed', tags: ['site:dto']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'missingRequired', tags: ['in:union', 'unionObjectPair']));
    }

    /**
     * The draws of a union of two objects carry the tag when, and only when, a member refuses them for an
     * undeclared key alone.
     */
    public function testTheDrawsAreTaggedByTheReferenceOracle(): void
    {
        $schemas = self::unionMembersRefusingOnlyAnUndeclaredKey()->current()[0];
        $document = ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]];
        $case = [
            'node' => [],
            'schema' => $schemas['Probe']['properties']['fox'],
            'valid' => ['value' => json_decode('{"tint":1.5}'), 'tags' => ['unionObjectPair']],
            'mutations' => [
                ['kind' => 'extraKeyClosed', 'value' => json_decode('{"tint":-2.5,"wolf":1}'), 'tags' => ['in:union']],
                ['kind' => 'missingRequired', 'value' => json_decode('{"hue":1}'), 'tags' => ['in:union']],
            ],
        ];
        $method = new ReflectionMethod(FuzzSpec::class, 'confirmedByReference');

        $confirmed = $method->invoke(null, 'fox', $case, $document, new InstanceGenerator(new FuzzRandom(1)));

        $this->assertIsArray($confirmed);
        $this->assertContains(FuzzSpec::REFUSED_ONLY_BY_UNDECLARED_KEY, $confirmed['mutations'][0]['tags']);
        $this->assertNotContains(FuzzSpec::REFUSED_ONLY_BY_UNDECLARED_KEY, $confirmed['mutations'][1]['tags']);
    }

    /**
     * A valid draw the reference oracle refuses is a `matchesTwoMembers` refusal only when two `oneOf`
     * members take it. Every refusal was labelled so: seed 17090 drew a valid 3.1 tuple the oracle,
     * blind to `prefixItems`, refused, and runtime, laravel and laravel-data were reported for taking it.
     * One no member takes is a disagreement of the instance generator and the oracle, not a refusal to
     * expect, and is drawn again.
     */
    public function testOnlyAValueTwoMembersTakeIsAMatchesTwoMembersRefusal(): void
    {
        $schemas = [
            'Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['hyena' => ['type' => 'string'], 'lemur' => ['type' => 'integer']], 'required' => ['hyena']],
                ['type' => 'object', 'properties' => ['hyena' => ['type' => 'string'], 'otter' => ['type' => 'boolean']], 'required' => ['otter']],
                ['type' => 'boolean'],
            ]]]],
        ];
        $document = ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]];
        $method = new ReflectionMethod(FuzzSpec::class, 'confirmedByReference');
        $kinds = static function (string $validJson) use ($schemas, $document, $method): array {
            $case = [
                // What a redraw draws from: a boolean, which the third member takes.
                'node' => ['t' => 'boolean'],
                'schema' => $schemas['Probe']['properties']['fox'],
                'valid' => ['value' => json_decode($validJson), 'tags' => ['unionObjectPair']],
                'mutations' => [],
            ];
            $confirmed = $method->invoke(null, 'fox', $case, $document, new InstanceGenerator(new FuzzRandom(1)));
            self::assertIsArray($confirmed);

            return array_column($confirmed['mutations'], 'kind');
        };

        $this->assertSame(['matchesTwoMembers'], $kinds('{"hyena":"a","otter":true}'));
        $this->assertSame([], $kinds('{"lemur":1}'));
        $this->assertSame([], $kinds('{"hyena":"a","otter":"no"}'));
    }

    /**
     * Symfony's PropertyInfo reports a union type's classes sorted by NAME (`Ant|Zebra` for a property
     * declared `Zebra|Ant`), and the serializer builds the first it tries. `objectOfPair` was set on
     * every object of the pair — a payload of `Ant`, which symfony builds, was filed under the row too,
     * and the row read "a LATER member", which `Zebra`, declared first, is not.
     */
    public function testAnObjectOfThePairIsTaggedByTheClassThatSortsFirst(): void
    {
        $runner = new FuzzRunner(outputDirectory: sys_get_temp_dir() . '/fuzz-harness-pair', modes: [GenerationMode::Symfony], propertiesPerSpec: 1);
        $generate = new ReflectionMethod(FuzzRunner::class, 'generateChecked');
        $tag = new ReflectionMethod(FuzzRunner::class, 'tagObjectsOfAPair');
        $tagged = static function (array $document, string $json) use ($runner, $generate, $tag): bool {
            $generation = $generate->invoke($runner, $document, GenerationMode::Symfony);
            $payload = ['property' => 'fox', 'kind' => 'valid', 'json' => $json, 'expected' => PayloadVerdict::Accepted, 'tags' => ['unionObjectPair']];

            return in_array('objectOfPair', $tag->invoke(null, [$payload], $document, $generation['fqcn'])[0]['tags'], true);
        };

        $byName = ['openapi' => '3.1.0', 'info' => ['title' => 'Fuzz', 'version' => '1.0.0'], 'components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [['$ref' => '#/components/schemas/Zebra'], ['$ref' => '#/components/schemas/Ant']]]]],
            'Zebra' => ['type' => 'object', 'properties' => ['stripe' => ['type' => 'integer']], 'required' => ['stripe']],
            'Ant' => ['type' => 'object', 'properties' => ['leg' => ['type' => 'integer']], 'required' => ['leg']],
        ]]];
        $this->assertTrue($tagged($byName, '{"fox":{"stripe":1}}'), 'the member declared first, sorted last');
        $this->assertFalse($tagged($byName, '{"fox":{"leg":1}}'), 'the member that sorts first');

        // An inline member's generated name takes part: `ProbeFox` sorts after `Green`.
        $inline = ['openapi' => '3.1.0', 'info' => ['title' => 'Fuzz', 'version' => '1.0.0'], 'components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['fox' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['hue' => ['type' => 'boolean'], 'tint' => ['type' => 'string']], 'required' => ['tint']],
                ['$ref' => '#/components/schemas/Green'],
            ]]]],
            'Green' => ['type' => 'object', 'properties' => ['shade' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string', 'minLength' => 3]],
        ]]];
        $this->assertTrue($tagged($inline, '{"fox":{"tint":"ok"}}'), 'the inline member');
        $this->assertFalse($tagged($inline, '{"fox":{"shade":"x"}}'), 'Green');

        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-refuse', kind: 'valid', tags: ['unionObjectPair', 'objectOfPair']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-refuse', kind: 'valid', tags: ['unionObjectPair']));
        // An object both members of the pair accept: symfony builds one and drops the other's keys, so
        // the second match is never seen (row 165; seeds 16012, 16032). Runtime and yii3 see it.
        $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'matchesTwoMembers', tags: ['unionObjectPair']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Symfony, category: 'wrong-accept', kind: 'matchesTwoMembers', tags: []));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Runtime, category: 'wrong-accept', kind: 'matchesTwoMembers', tags: ['unionObjectPair']));
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Yii3, category: 'wrong-accept', kind: 'matchesTwoMembers', tags: ['unionObjectPair']));
    }

    /**
     * An enum member beside the pair is no class an object could be built into: sorted in with the
     * classes, an enum whose name sorts first (`Amber` beside `Indigo` and `ProbeMacawOneOf3`) matched no
     * object member, and the object of the pair went untagged — filed as a defect although it is the
     * row-166 boundary (seeds 22009, 22039).
     */
    public function testAnEnumMemberDoesNotTakePartInTheSortOfThePair(): void
    {
        $runner = new FuzzRunner(outputDirectory: sys_get_temp_dir() . '/fuzz-harness-pair-enum', modes: [GenerationMode::Symfony], propertiesPerSpec: 1);
        $generate = new ReflectionMethod(FuzzRunner::class, 'generateChecked');
        $tag = new ReflectionMethod(FuzzRunner::class, 'tagObjectsOfAPair');
        $document = ['openapi' => '3.0.3', 'info' => ['title' => 'Fuzz', 'version' => '1.0.0'], 'components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['macaw' => ['oneOf' => [
                ['$ref' => '#/components/schemas/Amber'],
                ['$ref' => '#/components/schemas/Indigo'],
                ['type' => 'object', 'properties' => ['puffin' => ['type' => 'string']]],
            ]]]],
            'Amber' => ['type' => 'string', 'enum' => ['blue']],
            'Indigo' => ['type' => 'object', 'properties' => ['plover' => ['type' => 'integer']], 'required' => ['plover']],
        ]]];
        $generation = $generate->invoke($runner, $document, GenerationMode::Symfony);
        $tagged = static function (string $json) use ($tag, $document, $generation): bool {
            $payload = ['property' => 'macaw', 'kind' => 'valid', 'json' => $json, 'expected' => PayloadVerdict::Accepted, 'tags' => ['unionObjectPair']];

            return in_array('objectOfPair', $tag->invoke(null, [$payload], $document, $generation['fqcn'])[0]['tags'], true);
        };

        $this->assertTrue($tagged('{"macaw":{"puffin":"x"}}'), 'a value of the member that sorts after Indigo');
        $this->assertFalse($tagged('{"macaw":{"plover":1}}'), 'a value of Indigo, which sorts first');
    }

    /**
     * A member spelled as an `allOf` with no `type` of its own is an object of the pair too — it is
     * generated as a class (`ProbeGibbonOneOf2`). `isObjectShaped()` read only `$ref`, `type` and
     * `anyOf`/`oneOf`, so the pair counted one member, and a value of the second, refused by symfony
     * through the class that sorts first (row 165), was filed as undocumented (seed 14030).
     */
    public function testAnAllOfMemberIsAnObjectOfThePair(): void
    {
        $runner = new FuzzRunner(outputDirectory: sys_get_temp_dir() . '/fuzz-harness-pair-allof', modes: [GenerationMode::Symfony], propertiesPerSpec: 1);
        $generate = new ReflectionMethod(FuzzRunner::class, 'generateChecked');
        $tag = new ReflectionMethod(FuzzRunner::class, 'tagObjectsOfAPair');
        $tagged = static function (array $document, string $json) use ($runner, $generate, $tag): bool {
            $generation = $generate->invoke($runner, $document, GenerationMode::Symfony);
            $payload = ['property' => 'gibbon', 'kind' => 'valid', 'json' => $json, 'expected' => PayloadVerdict::Accepted, 'tags' => ['unionObjectPair']];

            return in_array('objectOfPair', $tag->invoke(null, [$payload], $document, $generation['fqcn'])[0]['tags'], true);
        };
        $document = static fn(array $second): array => ['openapi' => '3.1.0', 'info' => ['title' => 'Fuzz', 'version' => '1.0.0'], 'components' => ['schemas' => [
            'Probe' => ['type' => 'object', 'properties' => ['gibbon' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['red' => ['type' => 'boolean']], 'required' => ['red']],
                $second,
            ]]]],
        ]]];
        $blue = ['type' => 'object', 'properties' => ['blue' => ['type' => 'integer']], 'required' => ['blue']];

        $allOf = $document(['allOf' => [$blue]]);
        $this->assertTrue($tagged($allOf, '{"gibbon":{"blue":3}}'), 'a value of the allOf member');
        $this->assertTrue($tagged($allOf, '{"gibbon":{"plover":-27}}'), 'the seed-14030 shrink');
        $this->assertFalse($tagged($allOf, '{"gibbon":{"red":true}}'), 'a value of the member that sorts first');

        $plain = $document($blue);
        $this->assertTrue($tagged($plain, '{"gibbon":{"blue":3}}'), 'the plain control');
        $this->assertFalse($tagged($plain, '{"gibbon":{"red":true}}'), 'the plain control, first member');
    }

    /**
     * laravel-data refuses the `null` VALUE of a map of nullable objects (row 163) as it refuses the null
     * item of such a list; the generator tags the map value `nullMapValue:…` (seed 14000).
     */
    public function testANullValueOfAMapOfNullableDtosIsTheNullItemRow(): void
    {
        foreach (['nullMapValue:nullable-dto', 'nullMapValue:nullable-discriminated', 'nullItem:nullable-dto'] as $tag) {
            $this->assertNotNull(KnownDivergences::explain(mode: GenerationMode::LaravelData, category: 'wrong-refuse', kind: 'valid', tags: [$tag]), $tag);
            $this->assertNull(KnownDivergences::explain(mode: GenerationMode::Laravel, category: 'wrong-refuse', kind: 'valid', tags: [$tag]), $tag);
        }
        $this->assertNull(KnownDivergences::explain(mode: GenerationMode::LaravelData, category: 'wrong-refuse', kind: 'valid', tags: ['nullMapValue:nullable-temporal']));

        // The seed-14000 shape: `flowers: {additionalProperties: {type: object, nullable: true, …}}`.
        $this->assertSame('nullable-dto', InstanceGenerator::descriptor(['t' => 'object', 'nullable' => true, 'props' => ['petals' => ['t' => 'number']], 'required' => []]));
    }

    /**
     * The valid draw of a union of two objects no longer guesses which member symfony builds.
     */
    public function testTheGeneratorLeavesTheMemberOrderToTheRunner(): void
    {
        foreach (range(1, 40) as $seed) {
            foreach (FuzzSpec::generate(seed: $seed, propertyCount: 4)->cases as $case) {
                $this->assertNotContains('objectOfPair', $case['valid']['tags']);
            }
        }
    }

    /**
     * Unions of two object members — `$ref` components and inline ones, keys shared and not, `oneOf` and
     * `anyOf` — are drawn now. The grammar kept every union's branches disjoint by JSON type, so the
     * class-union defects in every mode went unfuzzed.
     */
    public function testTheGrammarDrawsUnionsOfTwoObjectMembers(): void
    {
        $seen = [];
        foreach (range(1, 80) as $seed) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: 4);
            $document = $spec->document();
            foreach ($spec->cases as $name => $case) {
                if (!SchemaGrammar::holdsObjectPair($case['node'])) {
                    continue;
                }
                $pair = self::objectPairIn($case['node']);
                $this->assertNotNull($pair);
                $seen['overlap:' . var_export($pair['overlap'], true)] = true;
                $seen[$pair['op']] = true;
                $objects = array_filter($pair['branches'], static fn(array $branch): bool => $branch['t'] === 'object');
                $this->assertCount(2, $objects);
                $required = array_map(static fn(array $branch): array => $branch['required'], array_values($objects));
                $seen['sharedRequired:' . var_export(array_intersect(...$required) !== [], true)] = true;

                $schema = $document['components']['schemas']['Probe']['properties'][$name];
                foreach (self::unionBranchListsIn($schema) as $branches) {
                    foreach ($branches as $branch) {
                        $seen[array_key_exists('$ref', $branch) ? 'refMember' : 'inlineMember'] = true;
                    }
                }
                // A component union of its own (`Shape: {oneOf: [Circle, Square]}`) when the grammar says so.
                if ($pair === $case['node']) {
                    $this->assertSame($pair['asComponent'], self::componentUnionIn(schema: $schema, document: $document) !== null);
                    $seen[$pair['asComponent'] ? 'componentPair' : 'inlinePair'] = true;
                }
            }
        }

        foreach (['overlap:true', 'overlap:false', 'oneOf', 'anyOf', 'sharedRequired:true', 'sharedRequired:false', 'refMember', 'inlineMember', 'componentPair', 'inlinePair'] as $expected) {
            $this->assertArrayHasKey($expected, $seen, $expected);
        }
    }

    /**
     * Every draw for a union of two objects says what it means on the finished document: a valid value
     * the reference oracle takes, a mutation it refuses — one of the other member is dropped, and a
     * value both members take under `oneOf` is kept as a refusal.
     */
    public function testADrawForAUnionOfTwoObjectsIsConfirmedByTheReferenceOracle(): void
    {
        $checked = 0;
        foreach (range(1, 80) as $seed) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: 4);
            foreach ($spec->cases as $name => $case) {
                if (!SchemaGrammar::holdsObjectPair($case['node'])) {
                    continue;
                }
                $oracle = new ReferenceOracle($spec->document([$name]));
                $this->assertNotFalse($oracle->accepts(FuzzSpec::payload($name, $case['valid']['value'])), 'seed ' . $seed);
                foreach ($case['mutations'] as $mutation) {
                    $this->assertNotTrue($oracle->accepts(FuzzSpec::payload($name, $mutation['value'])), 'seed ' . $seed . ' ' . $mutation['kind']);
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Without the stream that decides on them no union of two objects is drawn — which is what keeps a
     * seed that draws none on the document it drew before.
     */
    public function testNoUnionOfTwoObjectsIsDrawnWithoutItsOwnStream(): void
    {
        foreach (range(1, 40) as $seed) {
            $grammar = new SchemaGrammar(random: new FuzzRandom($seed), openApi31: true, names: new NameAllocator());
            $this->assertFalse(SchemaGrammar::holdsObjectPair($grammar->node(depth: 1)));
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function objectPairIn(array $node): ?array
    {
        if (($node['objectPair'] ?? false) === true) {
            return $node;
        }
        foreach ($node as $child) {
            if (is_array($child) && ($found = self::objectPairIn($child)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The component a property's schema names as a union of its own — through a 3.1 nullable wrapper,
     * `allOf: [$ref]` and the 3.0 `nullable` beside it — or null when the union is spelled inline.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $document
     */
    private static function componentUnionIn(array $schema, array $document): ?string
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (is_array($members) && count($members) === 2 && $members[1] === ['type' => 'null']) {
                $schema = $members[0];
            }
        }
        if (is_array($schema['allOf'] ?? null) && count($schema['allOf']) === 1) {
            $schema = $schema['allOf'][0];
        }
        if (!is_string($schema['$ref'] ?? null)) {
            return null;
        }
        $name = substr($schema['$ref'], strlen('#/components/schemas/'));
        $component = $document['components']['schemas'][$name] ?? [];

        return (array_key_exists('oneOf', $component) || array_key_exists('anyOf', $component)) && !array_key_exists('discriminator', $component) ? $name : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<array<int, mixed>>
     */
    private static function unionBranchListsIn(array $schema): array
    {
        $lists = [];
        foreach ($schema as $key => $value) {
            if (($key === 'oneOf' || $key === 'anyOf') && is_array($value)) {
                $lists[] = array_values(array_filter($value, is_array(...)));
            }
            if (is_array($value)) {
                $lists = [...$lists, ...self::unionBranchListsIn($value)];
            }
        }

        return $lists;
    }

    /**
     * @param array<string, mixed> $schemas
     */
    #[DataProvider('referenceVerdicts')]
    public function testTheReferenceOracleJudgesTheShrunkDocument(array $schemas, string $json, ?bool $accepted, string $openApi = '3.1.0'): void
    {
        $document = ['openapi' => $openApi, 'components' => ['schemas' => $schemas]];

        $this->assertSame($accepted, (new ReferenceOracle($document))->accepts($json));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: bool|null, 3?: string}>
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
        // Two object members that share a key: a value both take is no `oneOf` instance, one only the
        // second takes is; `anyOf` takes both.
        $teal = ['Teal' => ['type' => 'object', 'properties' => ['hyena' => ['type' => 'string'], 'lemur' => ['type' => 'integer']], 'required' => ['hyena']]];
        $overlapping = static fn(string $keyword): array => $probe([$keyword => [
            ['$ref' => '#/components/schemas/Teal'],
            ['type' => 'object', 'properties' => ['hyena' => ['type' => 'string'], 'otter' => ['type' => 'boolean']], 'required' => ['otter']],
        ]], $teal);
        yield 'oneOf over overlapping objects refuses a value both take' => [$overlapping('oneOf'), '{"fox":{"hyena":"a","otter":true}}', false];
        yield 'oneOf over overlapping objects takes a value one takes' => [$overlapping('oneOf'), '{"fox":{"otter":true}}', true];
        yield 'anyOf over overlapping objects takes a value both take' => [$overlapping('anyOf'), '{"fox":{"hyena":"a","otter":true}}', true];
        yield 'oneOf over overlapping objects refuses a value neither takes' => [$overlapping('oneOf'), '{"fox":{"lemur":1}}', false];
        yield 'required' => [$probe(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]), '{"fox":{}}', false];
        yield 'closed object' => [$probe(['type' => 'object', 'additionalProperties' => false]), '{"fox":{"b":1}}', false];
        yield 'impossible date' => [$probe(['type' => 'string', 'format' => 'date']), '{"fox":"2026-02-30"}', false];
        yield 'loose date-time' => [$probe(['type' => 'string', 'format' => 'date-time']), '{"fox":"2026-01-02"}', false];
        yield 'exclusive bound' => [$probe(['type' => 'integer', 'minimum' => 3, 'exclusiveMinimum' => true]), '{"fox":3}', false];
        yield 'multipleOf' => [$probe(['type' => 'number', 'multipleOf' => 0.5]), '{"fox":1.5}', true];
        yield 'unknown format is no answer' => [$probe(['type' => 'string', 'format' => 'hostname']), '{"fox":"x"}', null];
        // 3.1 `prefixItems`: element i is checked against prefixItems[i], `items` only past them.
        $tuple = $probe(['type' => 'array', 'prefixItems' => [['type' => ['boolean', 'null']]], 'items' => ['type' => 'string', 'format' => 'date']]);
        yield 'prefixItems checks the head, items the rest' => [$tuple, '{"fox":[null,"2026-10-25"]}', true];
        yield 'prefixItems alone' => [$tuple, '{"fox":[true]}', true];
        yield 'prefixItems refuses an items value at its index' => [$tuple, '{"fox":["2026-10-25","2026-10-25"]}', false];
        yield 'prefixItems refuses a head value past it' => [$tuple, '{"fox":[true,false]}', false];
        $closedTuple = $probe(['type' => 'array', 'prefixItems' => [['type' => 'integer']], 'items' => false]);
        yield 'items false takes the prefix' => [$closedTuple, '{"fox":[1]}', true];
        yield 'items false refuses an extra element' => [$closedTuple, '{"fox":[1,2]}', false];
        // 3.0 has no `prefixItems`: `items` holds for every element.
        yield '3.0 items holds for every element' => [$tuple, '{"fox":[null,"2026-10-25"]}', false, '3.0.3'];
        yield '3.0 items takes a list of its own' => [$tuple, '{"fox":["2026-10-25"]}', true, '3.0.3'];
        // Instance equality is JSON equality: object members in any order, `1` and `1.0` one number.
        $pair = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]];
        yield 'enum ignores the key order of an object' => [$probe(['enum' => [['a' => 1, 'b' => 2]]]), '{"fox":{"b":2,"a":1}}', true];
        yield 'const ignores the key order of an object' => [$probe(['const' => ['a' => 1, 'b' => [1, 2]]]), '{"fox":{"b":[1,2],"a":1}}', true];
        yield 'const keeps the order of a list' => [$probe(['const' => [1, 2]]), '{"fox":[2,1]}', false];
        yield 'uniqueItems sees one object in two key orders' => [$probe(['type' => 'array', 'uniqueItems' => true, 'items' => $pair]), '{"fox":[{"a":1,"b":2},{"b":2,"a":1}]}', false];
        yield 'uniqueItems sees one number in 1 and 1.0' => [$probe(['type' => 'array', 'uniqueItems' => true]), '{"fox":[1,1.0]}', false];
        yield 'uniqueItems takes distinct objects' => [$probe(['type' => 'array', 'uniqueItems' => true, 'items' => $pair]), '{"fox":[{"a":1,"b":2},{"a":2,"b":1}]}', true];
        // RFC 3339 `full-time`: an hour, minute and offset of a time of day; `T` and `Z` in either case.
        $dateTime = $probe(['type' => 'string', 'format' => 'date-time']);
        yield 'date-time lower case t and z' => [$dateTime, '{"fox":"2026-01-02t10:00:00.25z"}', true];
        yield 'date-time hour out of range' => [$dateTime, '{"fox":"2026-01-02T24:00:00Z"}', false];
        yield 'date-time minute out of range' => [$dateTime, '{"fox":"2026-01-02T10:60:00Z"}', false];
        yield 'date-time offset hour out of range' => [$dateTime, '{"fox":"2026-01-02T10:00:00+25:00"}', false];
        yield 'date-time offset minute out of range' => [$dateTime, '{"fox":"2026-01-02T10:00:00+02:60"}', false];
        yield 'date-time with no offset' => [$dateTime, '{"fox":"2026-01-02T10:00:00"}', false];
        // A leap second is valid only at the end of a UTC day — out of the oracle's reach.
        yield 'date-time leap second is no answer' => [$dateTime, '{"fox":"2026-06-30T23:59:60Z"}', null];
        // An assertion keyword the oracle does not evaluate is no answer, never a silent pass.
        $unevaluated = [
            'not' => ['not' => ['type' => 'string']],
            'contains' => ['type' => 'array', 'contains' => ['type' => 'string']],
            'propertyNames' => ['type' => 'object', 'propertyNames' => ['maxLength' => 1]],
            'patternProperties' => ['type' => 'object', 'patternProperties' => ['^a' => ['type' => 'string']]],
            'unevaluatedProperties' => ['type' => 'object', 'unevaluatedProperties' => false],
            'dependentRequired' => ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
            'if' => ['if' => ['type' => 'integer'], 'then' => ['minimum' => 5]],
        ];
        foreach ($unevaluated as $keyword => $schema) {
            yield $keyword . ' is no answer' => [$probe($schema), '{"fox":1}', null];
        }
    }

    /**
     * A union or a discriminated union is drawn as a BRANCH of a union, beside a scalar — a union of two
     * objects among them — and the branches still take pairwise disjoint JSON type groups.
     */
    public function testTheGrammarDrawsUnionsAsUnionBranches(): void
    {
        $seen = [];
        foreach (range(1, 120) as $seed) {
            foreach (FuzzSpec::generate(seed: $seed, propertyCount: 6)->cases as $case) {
                foreach (self::unionNodesIn($case['node']) as $union) {
                    // Two object members share their group by design.
                    $groups = ($union['objectPair'] ?? false) ? ['pairMembers'] : [];
                    foreach ($union['branches'] as $branch) {
                        $branchGroups = ($union['objectPair'] ?? false) && $branch['t'] === 'object' ? [] : SchemaGrammar::groupsOf($branch);
                        $this->assertSame([], array_intersect($groups, $branchGroups), 'seed ' . $seed);
                        $groups = [...$groups, ...$branchGroups];
                        if ($branch['t'] === 'discriminated') {
                            $seen['discriminatedBranch'] = true;
                        }
                        if ($branch['t'] === 'union') {
                            $seen[($branch['objectPair'] ?? false) ? 'pairBranch' : 'unionBranch'] = true;
                        }
                    }
                    $this->assertGreaterThanOrEqual(2, count($union['branches']), 'seed ' . $seed);
                }
            }
        }

        foreach (['discriminatedBranch', 'unionBranch', 'pairBranch'] as $expected) {
            $this->assertArrayHasKey($expected, $seen, $expected);
        }
    }

    /**
     * The members of a union nested as a branch count as the outer union's for the tags about siblings.
     */
    public function testANestedUnionsMembersCountForTheSiblingTags(): void
    {
        $object = ['t' => 'object', 'props' => ['otter' => ['t' => 'integer', 'nullable' => false]], 'required' => ['otter'], 'additional' => null, 'nullable' => false];
        $nested = ['t' => 'union', 'op' => 'oneOf', 'branches' => [$object, ['t' => 'string', 'nullable' => false]], 'nullable' => false];
        $union = ['t' => 'union', 'op' => 'anyOf', 'branches' => [['t' => 'boolean', 'nullable' => false], $nested], 'nullable' => false];

        $this->assertSame(['unionClassMember'], InstanceGenerator::classMemberTags($union));
        $this->assertSame('unionWithString', InstanceGenerator::descriptor($union));
        $this->assertCount(3, InstanceGenerator::memberBranches($union));
    }

    /**
     * Every draw of every case means what it says on the finished document — a valid value the reference
     * oracle does not refuse, a mutation it does not accept — whatever production made the case: a union
     * branch that is a union, `allOf: [$ref]` around an object or a union, a 3.0 nullable `allOf`,
     * annotation siblings, a list of `format` strings.
     */
    public function testEveryDrawMeansWhatItSaysOnTheDocument(): void
    {
        $checked = 0;
        foreach (range(1, 60) as $seed) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: 6);
            foreach ($spec->cases as $name => $case) {
                $oracle = new ReferenceOracle($spec->document([$name]));
                $this->assertNotFalse($oracle->accepts(FuzzSpec::payload($name, $case['valid']['value'])), 'seed ' . $seed . ' ' . $name);
                foreach ($case['mutations'] as $mutation) {
                    $this->assertNotTrue(
                        condition: $oracle->accepts(FuzzSpec::payload($name, $mutation['value'])),
                        message: 'seed ' . $seed . ' ' . $name . ' ' . $mutation['kind'],
                    );
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(300, $checked);
    }

    /**
     * `allOf: [$ref]` spells an object, a list, a map, a union and a discriminated union too, not only a
     * scalar; 3.0 writes a nullable union component as `{allOf: [$ref Union], nullable: true}`; and
     * annotation-only siblings sit on `$ref` and `allOf` wrappers.
     */
    public function testTheRendererSpellsWrappersBeyondScalars(): void
    {
        $seen = [];
        foreach (range(1, 120) as $seed) {
            $document = FuzzSpec::generate(seed: $seed, propertyCount: 6)->document();
            $schemas = $document['components']['schemas'];
            foreach (self::schemasIn($schemas) as $schema) {
                if (array_intersect(['description', 'title', 'deprecated', 'default', 'example', 'examples'], array_keys($schema)) !== []
                    && (array_key_exists('$ref', $schema) || array_key_exists('allOf', $schema))) {
                    $seen['annotated'] = true;
                }
                $allOf = $schema['allOf'] ?? null;
                if (!is_array($allOf) || count($allOf) !== 1 || !is_string($allOf[0]['$ref'] ?? null)) {
                    continue;
                }
                $target = $schemas[substr($allOf[0]['$ref'], strlen('#/components/schemas/'))] ?? [];
                $kind = match (true) {
                    array_key_exists('discriminator', $target) => 'discriminated',
                    array_key_exists('oneOf', $target) || array_key_exists('anyOf', $target) => 'union',
                    ($target['type'] ?? null) === 'array' => 'array',
                    is_array($target['additionalProperties'] ?? null) && !array_key_exists('properties', $target) => 'map',
                    array_key_exists('properties', $target) => 'object',
                    default => 'other',
                };
                $seen[$kind] = true;
                if ($kind === 'union' && ($schema['nullable'] ?? false) === true && $document['openapi'] === '3.0.3') {
                    $seen['nullableUnion30'] = true;
                }
            }
        }

        foreach (['annotated', 'object', 'array', 'map', 'union', 'discriminated', 'nullableUnion30'] as $expected) {
            $this->assertArrayHasKey($expected, $seen, $expected);
        }
    }

    /**
     * A `default` annotation never sits on a `required` property: the documented "`required` with a
     * `default`" contradiction (the default wins in four modes, the generator warns) is no finding to draw.
     */
    public function testNoDefaultIsAnnotatedOnARequiredProperty(): void
    {
        $defaults = 0;
        foreach (range(1, 120) as $seed) {
            $document = FuzzSpec::generate(seed: $seed, propertyCount: 6)->document();
            foreach (self::schemasIn($document['components']['schemas']) as $schema) {
                $defaults += array_key_exists('default', $schema) ? 1 : 0;
                if (!is_array($schema['properties'] ?? null)) {
                    continue;
                }
                foreach ($schema['required'] ?? [] as $required) {
                    $property = $schema['properties'][$required] ?? [];
                    $this->assertArrayNotHasKey('default', $property, 'seed ' . $seed . ' ' . $required);
                    foreach ($property['anyOf'] ?? $property['oneOf'] ?? [] as $member) {
                        $this->assertArrayNotHasKey('default', $member, 'seed ' . $seed . ' ' . $required);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $defaults);
    }

    /**
     * The variety stream raises the share of `format` strings from about 20% to about 30% — the list items
     * among them, which get an extra chance of their own.
     */
    public function testFormatStringsAreAboutAThirdOfStrings(): void
    {
        $plain = self::formatShares(withVariety: false);
        $varied = self::formatShares(withVariety: true);

        $this->assertLessThan(0.24, $plain['strings']);
        $this->assertGreaterThan(0.28, $varied['strings']);
        $this->assertLessThan(0.36, $varied['strings']);
        $this->assertGreaterThan($plain['items'] + 0.08, $varied['items']);
    }

    /**
     * The share of `format` among the strings a grammar draws (a single-member `enum`, a discriminator's
     * tag, left out), and among the items of its lists.
     *
     * @return array{strings: float, items: float}
     */
    private static function formatShares(bool $withVariety): array
    {
        $strings = 0;
        $formats = 0;
        $lists = 0;
        $formatItems = 0;
        foreach (range(1, 300) as $seed) {
            $grammar = new SchemaGrammar(
                random: new FuzzRandom($seed),
                openApi31: true,
                names: new NameAllocator(),
                pairs: new FuzzRandom($seed + 1),
                variety: $withVariety ? new FuzzRandom($seed + 2) : null,
            );
            foreach (range(1, 6) as $ignored) {
                foreach (self::nodesIn($grammar->node(depth: 1)) as $node) {
                    if ($node['t'] === 'string' && count($node['enum'] ?? []) !== 1) {
                        $strings++;
                        $formats += array_key_exists('format', $node) ? 1 : 0;
                    }
                    if ($node['t'] === 'array') {
                        $lists++;
                        $formatItems += array_key_exists('format', $node['items']) ? 1 : 0;
                    }
                }
            }
        }

        return ['strings' => $formats / $strings, 'items' => $formatItems / $lists];
    }

    /**
     * A crash or a refusal at an undiscriminated union COMPONENT of objects is the support-matrix row only
     * in laravel and laravel-data, only when the generator warned about a component the property reaches.
     */
    public function testAnUnhydratableUnionComponentIsDocumentedOnlyWhereWarned(): void
    {
        $warnings = ['Property "llama" of Probe refers to Ivory, an undiscriminated anyOf union: this mode cannot hydrate a payload into it (runtime mode can). Add a discriminator to Ivory, or type the property as one member.'];
        $tags = ['unionObjectPair', 'undiscriminatedComponent'];

        $this->assertNotNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Laravel, category: 'crash', tags: $tags, warnings: $warnings, reachable: ['Ivory']));
        $this->assertNotNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::LaravelData, category: 'wrong-refuse', tags: $tags, warnings: $warnings, reachable: ['Mauve', 'Ivory']));
        // Narrow: another mode, an inline pair, a component the property does not reach, no warning, a wrong accept.
        $this->assertNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Yii3, category: 'crash', tags: $tags, warnings: $warnings, reachable: ['Ivory']));
        $this->assertNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Laravel, category: 'crash', tags: ['unionObjectPair'], warnings: $warnings, reachable: ['Ivory']));
        $this->assertNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Laravel, category: 'crash', tags: $tags, warnings: $warnings, reachable: ['Teal']));
        $this->assertNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Laravel, category: 'crash', tags: $tags, warnings: [], reachable: ['Ivory']));
        $this->assertNull(KnownDivergences::explainUnhydratableUnion(mode: GenerationMode::Laravel, category: 'wrong-accept', tags: $tags, warnings: $warnings, reachable: ['Ivory']));
    }

    /**
     * Every draw at a union of two objects spelled as a component is tagged `undiscriminatedComponent`,
     * and no draw at an inline one is.
     */
    public function testAComponentUnionOfObjectsIsTagged(): void
    {
        $member = static fn(string $key): array => ['t' => 'object', 'props' => [$key => ['t' => 'integer', 'nullable' => false]], 'required' => [$key], 'additional' => null, 'nullable' => false];
        $pair = ['t' => 'union', 'op' => 'oneOf', 'branches' => [$member('otter'), $member('heron')], 'objectPair' => true, 'overlap' => false, 'nullable' => false];
        $instances = new InstanceGenerator(new FuzzRandom(5));

        foreach ([true, false] as $asComponent) {
            $node = [...$pair, 'asComponent' => $asComponent];
            $this->assertSame($asComponent, in_array('undiscriminatedComponent', $instances->validInstance($node)['tags'], true));
            foreach ($instances->mutations($node) as $mutation) {
                if (in_array('in:union', $mutation['tags'], true)) {
                    $this->assertSame($asComponent, in_array('undiscriminatedComponent', $mutation['tags'], true));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function nodesIn(array $node): array
    {
        $found = array_key_exists('t', $node) ? [$node] : [];
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = [...$found, ...self::nodesIn($child)];
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function unionNodesIn(array $node): array
    {
        return array_values(array_filter(self::nodesIn($node), static fn(array $found): bool => $found['t'] === 'union'));
    }

    /**
     * Every schema object in a tree of schemas, the root included.
     *
     * @param array<int|string, mixed> $tree
     * @return list<array<string, mixed>>
     */
    private static function schemasIn(array $tree): array
    {
        $found = [$tree];
        foreach ($tree as $child) {
            if (is_array($child)) {
                $found = [...$found, ...self::schemasIn($child)];
            }
        }

        return $found;
    }
}
