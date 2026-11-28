<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Schema;

use OpenapiPhpDtoGenerator\Schema\SchemaNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each rule of {@see SchemaNormalizer} on its own, and each site it must leave as written.
 */
final class SchemaNormalizerTest extends TestCase
{
    private const string REF = '#/components/schemas/';

    /**
     * @param array<string, mixed> $property
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $components
     */
    #[DataProvider('propertyRewrites')]
    public function testAPropertySchemaComesOutCanonical(string $openApi, array $property, array $expected, array $components = []): void
    {
        $normalized = (new SchemaNormalizer())->normalize(self::document(openApi: $openApi, property: $property, components: $components));

        $this->assertSame($expected, $normalized['components']['schemas']['Probe']['properties']['shade']);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3?: array<string, mixed>}>
     */
    public static function propertyRewrites(): iterable
    {
        $teal = ['Teal' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'string']]]];

        yield 'one-member allOf merged with its annotations' => [
            '3.1.0',
            ['description' => 'A shade.', 'allOf' => [['type' => 'string', 'maxLength' => 4]]],
            ['type' => 'string', 'maxLength' => 4, 'description' => 'A shade.'],
        ];
        yield 'nested one-member wrappers' => [
            '3.1.0',
            ['allOf' => [['oneOf' => [['anyOf' => [['type' => 'integer']]]]]]],
            ['type' => 'integer'],
        ];
        yield 'bare wrapper around a reference is the reference' => [
            '3.1.0',
            ['allOf' => [['$ref' => self::REF . 'Teal']]],
            ['$ref' => self::REF . 'Teal'],
            $teal,
        ];
        yield 'one-member union of a reference is kept (a union, not a class, to the generator)' => [
            '3.1.0',
            ['type' => 'array', 'items' => ['oneOf' => [['$ref' => self::REF . 'Teal']]]],
            ['type' => 'array', 'items' => ['oneOf' => [['$ref' => self::REF . 'Teal']]]],
            $teal,
        ];
        yield 'annotated wrapper around a reference is kept' => [
            '3.1.0',
            ['allOf' => [['$ref' => self::REF . 'Teal']], 'description' => 'Kept.'],
            ['allOf' => [['$ref' => self::REF . 'Teal']], 'description' => 'Kept.'],
            $teal,
        ];
        yield '3.0 nullable reference idiom is kept' => [
            '3.0.3',
            ['allOf' => [['$ref' => self::REF . 'Teal']], 'nullable' => true],
            ['allOf' => [['$ref' => self::REF . 'Teal']], 'nullable' => true],
            $teal,
        ];
        yield '3.0 nullable beside a reference becomes the idiom' => [
            '3.0.3',
            ['$ref' => self::REF . 'Teal', 'nullable' => true, 'description' => 'D.'],
            ['allOf' => [['$ref' => self::REF . 'Teal']], 'nullable' => true, 'description' => 'D.'],
            $teal,
        ];
        yield 'scalar fragments folded' => [
            '3.1.0',
            ['title' => 'T', 'allOf' => [['enum' => ['a', 'b']], ['type' => 'string'], ['maxLength' => 1]]],
            ['enum' => ['a', 'b'], 'type' => 'string', 'maxLength' => 1, 'title' => 'T'],
        ];
        yield '3.0 nullable fragment beside an enum without null is kept' => [
            '3.0.3',
            ['allOf' => [['type' => 'string', 'nullable' => true], ['enum' => ['a']]]],
            ['allOf' => [['type' => 'string', 'nullable' => true], ['enum' => ['a']]]],
        ];
        yield '3.0 nullable fragment beside a bound folded' => [
            '3.0.3',
            ['allOf' => [['type' => 'string', 'nullable' => true], ['minLength' => 2]]],
            ['type' => 'string', 'nullable' => true, 'minLength' => 2],
        ];
        yield 'fragments with two different types kept' => [
            '3.1.0',
            ['allOf' => [['type' => 'string'], ['type' => 'integer']]],
            ['allOf' => [['type' => 'string'], ['type' => 'integer']]],
        ];
        yield '3.0 boolean exclusive bound split from its bound kept' => [
            '3.0.3',
            ['allOf' => [['type' => 'number', 'minimum' => 1], ['exclusiveMinimum' => true]]],
            ['allOf' => [['type' => 'number', 'minimum' => 1], ['exclusiveMinimum' => true]]],
        ];
        yield 'additionalProperties never meets foreign properties' => [
            '3.1.0',
            ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], 'additionalProperties' => false],
            ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], 'additionalProperties' => false],
        ];
        yield 'disagreeing keyword kept' => [
            '3.1.0',
            ['allOf' => [['type' => 'string', 'maxLength' => 3]], 'maxLength' => 5],
            ['allOf' => [['type' => 'string', 'maxLength' => 3]], 'maxLength' => 5],
        ];
        yield '3.1 null union folded into a type list' => [
            '3.1.0',
            ['oneOf' => [['type' => 'null'], ['type' => 'string', 'enum' => ['a']]], 'description' => 'D.'],
            ['type' => ['string', 'null'], 'enum' => ['a', null], 'description' => 'D.'],
        ];
        yield '3.1 null union around a reference spelled anyOf, null last' => [
            '3.1.0',
            ['oneOf' => [['type' => 'null'], ['$ref' => self::REF . 'Teal']]],
            ['anyOf' => [['$ref' => self::REF . 'Teal'], ['type' => 'null']]],
            $teal,
        ];
        yield '3.1 null union around a nullable reference keeps oneOf' => [
            '3.1.0',
            ['oneOf' => [['$ref' => self::REF . 'Maybe'], ['type' => 'null']]],
            ['oneOf' => [['$ref' => self::REF . 'Maybe'], ['type' => 'null']]],
            ['Maybe' => ['type' => ['string', 'null']]],
        ];
        yield '3.1 type list puts null last' => [
            '3.1.0',
            ['type' => ['null', 'integer']],
            ['type' => ['integer', 'null']],
        ];
        yield '3.0 nullable enum lists null' => [
            '3.0.3',
            ['type' => 'string', 'nullable' => true, 'enum' => ['a']],
            ['type' => 'string', 'nullable' => true, 'enum' => ['a', null]],
        ];
        yield 'alias chain followed to its end' => [
            '3.1.0',
            ['$ref' => self::REF . 'First', 'description' => 'D.'],
            ['$ref' => self::REF . 'Teal', 'description' => 'D.'],
            [...$teal, 'First' => ['$ref' => self::REF . 'Second'], 'Second' => ['$ref' => self::REF . 'Teal', 'title' => 'S']],
        ];
        yield 'nullable alias not followed' => [
            '3.0.3',
            ['$ref' => self::REF . 'First'],
            ['$ref' => self::REF . 'First'],
            [...$teal, 'First' => ['$ref' => self::REF . 'Teal', 'nullable' => true]],
        ];
        yield 'data values and extensions untouched' => [
            '3.1.0',
            ['type' => 'object', 'default' => ['allOf' => [['type' => 'string']]], 'x-meta' => ['allOf' => [1]]],
            ['type' => 'object', 'default' => ['allOf' => [['type' => 'string']]], 'x-meta' => ['allOf' => [1]]],
        ];
        yield 'a property named like a keyword is a property' => [
            '3.1.0',
            ['type' => 'object', 'properties' => ['allOf' => ['allOf' => [['type' => 'string']]]]],
            ['type' => 'object', 'properties' => ['allOf' => ['type' => 'string']]],
        ];
    }

    public function testTheGenerationRulesFoldFragmentsAndFollowAliasesOnly(): void
    {
        $property = ['type' => 'object', 'properties' => [
            'wrapped' => ['allOf' => [['type' => 'string']]],
            'split' => ['allOf' => [['type' => 'string'], ['maxLength' => 3]]],
            'aliased' => ['$ref' => self::REF . 'Alias'],
            'nullPair' => ['oneOf' => [['type' => 'null'], ['type' => 'integer']]],
        ]];
        $components = ['Alias' => ['$ref' => self::REF . 'Teal'], 'Teal' => ['type' => 'object']];
        $normalized = (new SchemaNormalizer(SchemaNormalizer::GENERATION_RULES))->normalize(self::document(openApi: '3.1.0', property: $property, components: $components));
        $properties = $normalized['components']['schemas']['Probe']['properties']['shade']['properties'];

        $this->assertSame(['allOf' => [['type' => 'string']]], $properties['wrapped']);
        $this->assertSame(['type' => 'string', 'maxLength' => 3], $properties['split']);
        $this->assertSame(['$ref' => self::REF . 'Teal'], $properties['aliased']);
        $this->assertSame($property['properties']['nullPair'], $properties['nullPair']);
    }

    public function testAWrapperAtTheTopOfAComponentIsAClassOfItsOwn(): void
    {
        $components = [
            'Teal' => ['type' => 'object'],
            'Child' => ['allOf' => [['$ref' => self::REF . 'Teal']]],
            'Facade' => ['oneOf' => [['$ref' => self::REF . 'Teal']]],
            'Nullish' => ['$ref' => self::REF . 'Teal', 'nullable' => true],
            'Inline' => ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], 'title' => 'I'],
        ];
        $normalized = (new SchemaNormalizer())->normalize(self::document(openApi: '3.0.3', property: ['type' => 'string'], components: $components));
        $schemas = $normalized['components']['schemas'];

        $this->assertSame($components['Child'], $schemas['Child']);
        $this->assertSame($components['Facade'], $schemas['Facade']);
        $this->assertSame($components['Nullish'], $schemas['Nullish']);
        $this->assertSame(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'title' => 'I'], $schemas['Inline']);
    }

    public function testADiscriminatedMemberKeepsItsSpelling(): void
    {
        $union = [
            'oneOf' => [['$ref' => self::REF . 'CatAlias'], ['allOf' => [['$ref' => self::REF . 'Dog']]]],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => self::REF . 'CatAlias']],
        ];
        $components = [
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'CatAlias' => ['$ref' => self::REF . 'Cat'],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ];
        $normalized = (new SchemaNormalizer())->normalize(self::document(openApi: '3.1.0', property: $union, components: $components));

        $this->assertSame($union, $normalized['components']['schemas']['Probe']['properties']['shade']);
    }

    public function testAnAliasChainIsFollowedIntoAnotherFile(): void
    {
        $document = self::document(openApi: '3.1.0', property: ['$ref' => 'other.json' . self::REF . 'Alias'], components: []);
        $other = [
            'openapi' => '3.1.0',
            'components' => ['schemas' => [
                'Alias' => ['$ref' => self::REF . 'Teal'],
                'Teal' => ['type' => 'object'],
                'Inner' => ['$ref' => self::REF . 'Alias'],
            ]],
        ];
        $normalized = (new SchemaNormalizer())->normalizeDocuments(document: $document, externals: ['other.json' => $other]);

        $this->assertSame(['$ref' => 'other.json' . self::REF . 'Teal'], $normalized['document']['components']['schemas']['Probe']['properties']['shade']);
        $this->assertSame(['$ref' => self::REF . 'Teal'], $normalized['externals']['other.json']['components']['schemas']['Inner']);
    }

    public function testAnOperationSchemaIsNormalizedAndItsNamesKept(): void
    {
        $document = [
            'openapi' => '3.1.0',
            'paths' => ['/api/test/widgets' => ['post' => [
                'requestBody' => ['content' => ['application/json' => [
                    'schema' => ['allOf' => [['type' => 'object']]],
                    'example' => ['allOf' => [['x' => 1]]],
                ]]],
            ]]],
        ];
        $normalized = (new SchemaNormalizer())->normalize($document);
        $media = $normalized['paths']['/api/test/widgets']['post']['requestBody']['content']['application/json'];

        $this->assertSame(['type' => 'object'], $media['schema']);
        $this->assertSame(['allOf' => [['x' => 1]]], $media['example']);
    }

    /**
     * @param array<string, mixed> $property
     * @param array<string, mixed> $components
     * @return array<string, mixed>
     */
    private static function document(string $openApi, array $property, array $components): array
    {
        return [
            'openapi' => $openApi,
            'info' => ['title' => 'Normalizer', 'version' => '1.0.0'],
            'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => ['shade' => $property]]] + $components],
        ];
    }
}
