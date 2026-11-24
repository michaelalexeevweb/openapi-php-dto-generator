<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        yield 'an object beside a string' => [['anyOf' => [$shape, ['$ref' => '#/components/schemas/Word']]], false];
        yield 'a string' => [['type' => 'string'], false];
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
