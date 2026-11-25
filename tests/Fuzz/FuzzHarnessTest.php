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
                // Never a component union of its own: that one is warned about at generation time.
                $this->assertArrayNotHasKey('$ref', $schema);
            }
        }

        foreach (['overlap:true', 'overlap:false', 'oneOf', 'anyOf', 'sharedRequired:true', 'sharedRequired:false', 'refMember', 'inlineMember'] as $expected) {
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
    }
}
