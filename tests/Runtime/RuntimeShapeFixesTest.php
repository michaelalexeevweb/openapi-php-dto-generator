<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime-mode shapes a document may legally write that were generated, read or written back wrong.
 * Each case generates a small document and drives a payload through the real deserializer.
 */
final class RuntimeShapeFixesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-shapes-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testComponentEnumSpelledTheOpenApi31WayIsAnEnum(): void
    {
        $class = $this->generate(
            components: [
                'Tone' => ['type' => ['string', 'null'], 'enum' => ['red', 'green']],
                'Shade' => ['enum' => ['light', 'dark']],
                'Size' => ['enum' => [1, 2]],
            ],
            properties: [
                'tone' => ['$ref' => '#/components/schemas/Tone'],
                'shade' => ['$ref' => '#/components/schemas/Shade'],
                'size' => ['$ref' => '#/components/schemas/Size'],
            ],
            openapi: '3.1.0',
        );

        $dto = $this->deserialize(class: $class, body: '{"tone":"red","shade":"dark","size":2}');
        $this->assertSame('red', $dto->getTone()?->value);
        $this->assertSame('dark', $dto->getShade()?->value);
        $this->assertSame(2, $dto->getSize()?->value);
        $this->assertNull($this->deserialize(class: $class, body: '{"tone":null}')->getTone());
        $this->assertRejected(class: $class, body: '{"tone":"blue"}', message: 'Allowed: red, green');
    }

    public function testDateOrNullUnionAcceptsADate(): void
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $class = $this->generate(properties: [
                'day' => [$keyword => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
            ], openapi: '3.1.0');

            $this->assertSame('2024-01-02', $this->deserialize(class: $class, body: '{"day":"2024-01-02"}')->getDay()?->format('Y-m-d'), $keyword);
            $this->assertNull($this->deserialize(class: $class, body: '{"day":null}')->getDay(), $keyword);
        }
    }

    public function testDateOrIntegerUnionParsesTheDateAsADate(): void
    {
        $class = $this->generate(properties: [
            'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'integer']]],
        ]);

        $this->assertSame('2024-01-02', $this->deserialize(class: $class, body: '{"day":"2024-01-02"}')->getDay()->format('Y-m-d'));
        $this->assertSame(7, $this->deserialize(class: $class, body: '{"day":7}')->getDay());
    }

    public function testDateOrDateTimeUnionDoesNotDependOnTheServerTimezone(): void
    {
        $class = $this->generate(properties: [
            'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date-time']]],
        ]);
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $dto = $this->deserialize(class: $class, body: '{"day":"2024-01-02"}');
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertSame('2024-01-02T00:00:00+00:00', $dto->getDay()->format(DATE_ATOM));
    }

    /**
     * A temporal union's value went out as the PHP object — `{"date": …, "timezone": …}` from the DTO's
     * own `toJson()` — and a date as a date-time. Each branch now goes out as its own string.
     */
    public function testTemporalUnionGoesOutAsTheBranchItCameIn(): void
    {
        $class = $this->generate(properties: [
            'when' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'string', 'format' => 'date-time']]],
            'day' => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
        ], openapi: '3.1.0');

        foreach (['{"when":"2024-01-02","day":"2024-03-04"}', '{"when":"2024-01-02T10:00:00+02:00","day":null}', '{"when":"2024-01-02T10:00:00.250000+02:00"}'] as $body) {
            $dto = $this->deserialize(class: $class, body: $body);
            $this->assertSame($body, $dto->toJson());
            $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        }
    }

    public function testListOfListsOfAnIntegerEnumIsValidated(): void
    {
        $class = $this->generate(
            components: ['Size' => ['type' => 'integer', 'enum' => [1, 2]]],
            properties: ['sizes' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Size']]]],
        );

        $this->assertSame([[1, 2]], $this->deserialize(class: $class, body: '{"sizes":[[1,2]]}')->getSizes());
        $this->assertRejected(class: $class, body: '{"sizes":[[9]]}', message: 'sizes');
    }

    public function testMapOfMapsGoesOutAsObjectsAndComesBackIn(): void
    {
        $class = $this->generate(properties: [
            'grid' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]],
        ]);
        $body = '{"grid":{"a":{"0":1,"1":2},"b":{}}}';

        $json = (new DtoNormalizer())->toJson($this->deserialize(class: $class, body: $body));

        $this->assertSame($body, $json);
        $this->assertSame($body, (new DtoNormalizer())->toJson($this->deserialize(class: $class, body: $json)));
    }

    public function testAdditionalPropertiesBesideNamedOnesAreValidatedAndKept(): void
    {
        $class = $this->generate(properties: [
            'extra' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']],
        ]);

        $body = '{"extra":{"count":1,"color":"red"}}';
        $dto = $this->deserialize(class: $class, body: $body);
        $this->assertSame(1, $dto->getExtra()->getCount());
        $this->assertSame(['color' => 'red'], $dto->getExtra()->undeclaredProperties());
        $this->assertSame($body, $dto->toJson());
        $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        $this->assertRejected(class: $class, body: '{"extra":{"count":1,"color":2}}', message: 'extra.color');
    }

    public function testUnionMemberTypedDirectlyIgnoresItsParentsDiscriminator(): void
    {
        $class = $this->generate(
            components: [
                'Animal' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                    'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']],
                ],
                'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
                'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
            ],
            properties: ['cat' => ['$ref' => '#/components/schemas/Cat']],
        );

        $this->assertSame('tabby', $this->deserialize(class: $class, body: '{"cat":{"kind":"tabby","meow":"m"}}')->getCat()->getKind());
    }

    public function testNumberThatOverflowsIsRejected(): void
    {
        $class = $this->generate(properties: ['price' => ['type' => 'number']]);

        $this->assertRejected(class: $class, body: '{"price":1e400}', message: 'must be a finite number');
    }

    public function testSubtypeBelowAnIntermediateClassKeepsItsOwnFields(): void
    {
        $class = $this->generate(
            components: [
                'Pet' => [
                    'type' => 'object',
                    'required' => ['kind'],
                    'properties' => ['kind' => ['type' => 'string']],
                    'discriminator' => ['propertyName' => 'kind', 'mapping' => [
                        'cat' => '#/components/schemas/Cat',
                        'lion' => '#/components/schemas/Lion',
                    ]],
                ],
                'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]]]],
                'Lion' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['type' => 'object', 'properties' => ['mane' => ['type' => 'string']]]]],
            ],
            properties: ['cat' => ['$ref' => '#/components/schemas/Cat']],
        );

        $body = '{"cat":{"kind":"lion","mane":"big"}}';
        $dto = $this->deserialize(class: $class, body: $body);
        $this->assertStringEndsWith('\Lion', get_class($dto->getCat()));
        $this->assertSame($body, $dto->toJson());
        $this->assertStringEndsWith('\Cat', get_class($this->deserialize(class: $class, body: '{"cat":{"kind":"cat"}}')->getCat()));
    }

    public function testUndeclaredKeysSpelledAsDigitsGoOutAsAnObject(): void
    {
        $class = $this->generate(properties: [
            'extra' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']],
        ]);

        foreach (['{"extra":{"0":"a"}}', '{"extra":{"0":"a","1":"b"}}'] as $body) {
            $dto = $this->deserialize(class: $class, body: $body);
            $this->assertSame($body, $dto->toJson());
            $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        }
    }

    public function testDateOrIntegerUnionGoesOutAsTheBranchItCameIn(): void
    {
        $class = $this->generate(properties: [
            'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'integer']]],
        ]);

        foreach (['{"day":"2024-01-02"}', '{"day":7}'] as $body) {
            $dto = $this->deserialize(class: $class, body: $body);
            $this->assertSame($body, $dto->toJson());
            $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        }
    }

    public function testAdditionalPropertiesNamingAComponentAreValidatedAndKept(): void
    {
        $class = $this->generate(
            components: ['Gear' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => false]],
            properties: [
                'extra' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Gear']],
            ],
        );

        $body = '{"extra":{"alpha":"a","x":{"name":"n"}}}';
        $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson());
        $this->assertRejected(class: $class, body: '{"extra":{"alpha":"a","x":{"bogus":1}}}', message: 'bogus');
        $this->assertRejected(class: $class, body: '{"extra":{"alpha":"a","x":5}}', message: 'object');
    }

    public function testAdditionalPropertiesNamingAnEnumOrAScalarAliasAreValidatedAndKept(): void
    {
        $class = $this->generate(
            components: [
                'Tone' => ['type' => 'string', 'enum' => ['red', 'green']],
                'Label' => ['type' => 'string', 'maxLength' => 3],
            ],
            properties: [
                'tones' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Tone']],
                'labels' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Label']],
            ],
        );

        $body = '{"tones":{"alpha":"a","x":"red"},"labels":{"alpha":"a","y":"abc"}}';
        $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson());
        $this->assertRejected(class: $class, body: '{"tones":{"x":"blue"}}', message: 'x');
        $this->assertRejected(class: $class, body: '{"labels":{"y":"abcd"}}', message: 'y');
    }

    /**
     * A component that holds more of itself under `additionalProperties` was checked through an inlined
     * copy cut off after a few levels: a wrong value deeper down was accepted and written back.
     */
    public function testSelfReferencingAdditionalPropertiesAreValidatedAtEveryDepth(): void
    {
        $class = $this->generate(
            components: ['Node' => ['type' => 'object', 'properties' => ['v' => ['type' => 'integer']], 'additionalProperties' => ['$ref' => '#/components/schemas/Node']]],
            properties: ['root' => ['$ref' => '#/components/schemas/Node']],
        );

        foreach ([1, 3, 6, 9] as $depth) {
            $valid = '{"v":1}';
            $invalid = '{"v":"bad"}';
            for ($i = 0; $i < $depth; $i++) {
                $valid = '{"v":1,"k":' . $valid . '}';
                $invalid = '{"v":1,"k":' . $invalid . '}';
            }
            $this->assertSame('{"root":' . $valid . '}', $this->deserialize(class: $class, body: '{"root":' . $valid . '}')->toJson(), (string)$depth);
            $this->assertRejected(class: $class, body: '{"root":' . $invalid . '}', message: 'root');
        }
    }

    /**
     * An extra key holding an object arrives as `[]` when it is empty and the payload is a PHP array
     * rather than a decoded body; it goes out as the object its schema says it is.
     */
    public function testEmptyObjectExtraFromAnArrayGoesOutAsAnObject(): void
    {
        $class = $this->generate(
            components: ['Gear' => ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]]],
            properties: [
                'extra' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Gear']],
                'inline' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]]],
            ],
        );

        $dto = (new DtoDeserializer())->deserializeValue(['extra' => ['name' => 'a', 'g' => []], 'inline' => ['h' => []]], $class);
        $this->assertSame('{"extra":{"name":"a","g":{}},"inline":{"h":{}}}', $dto->toJson());
        $this->assertSame('{"extra":{"name":"a","g":{}},"inline":{"h":{}}}', (new DtoNormalizer())->toJson($dto));
    }

    /**
     * An extra value of a component that admits null — `nullable: true`, or `type: [object, null]` —
     * may be null; one of a component that does not is refused.
     */
    public function testExtraValueOfANullableComponentMayBeNull(): void
    {
        foreach (['3.0.3' => ['type' => 'object', 'nullable' => true], '3.1.0' => ['type' => ['object', 'null']]] as $openapi => $shape) {
            $class = $this->generate(
                components: [
                    'Gear' => [...$shape, 'properties' => ['size' => ['type' => 'integer']]],
                    'Cog' => ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]],
                ],
                properties: [
                    'gears' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Gear']],
                    'cogs' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => ['$ref' => '#/components/schemas/Cog']],
                ],
                openapi: $openapi,
            );

            $body = '{"gears":{"name":"a","g":null,"h":{"size":1}}}';
            $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson(), $openapi);
            $this->assertRejected(class: $class, body: '{"gears":{"g":{"size":"big"}}}', message: 'size');
            $this->assertRejected(class: $class, body: '{"cogs":{"c":null}}', message: 'c');
        }
    }

    /**
     * `{$ref: Node, description: …}` is the same reference: annotations beside it do not change what
     * it points at, so it is checked by `Node` at every depth like a bare one.
     */
    public function testAnnotatedSelfReferenceInAdditionalPropertiesIsValidatedAtEveryDepth(): void
    {
        $class = $this->generate(
            components: ['Node' => [
                'type' => 'object',
                'properties' => ['v' => ['type' => 'integer']],
                'additionalProperties' => ['$ref' => '#/components/schemas/Node', 'description' => 'child node', 'title' => 'Child'],
            ]],
            properties: ['root' => ['$ref' => '#/components/schemas/Node']],
            openapi: '3.1.0',
        );

        foreach ([1, 6] as $depth) {
            $valid = '{"v":1}';
            $invalid = '{"v":"bad"}';
            for ($i = 0; $i < $depth; $i++) {
                $valid = '{"v":1,"k":' . $valid . '}';
                $invalid = '{"v":1,"k":' . $invalid . '}';
            }
            $this->assertSame('{"root":' . $valid . '}', $this->deserialize(class: $class, body: '{"root":' . $valid . '}')->toJson(), (string)$depth);
            $this->assertRejected(class: $class, body: '{"root":' . $invalid . '}', message: 'root');
        }
    }

    /**
     * The objects inside a kept extra value go out as objects too, at any depth, when the payload was a
     * PHP array that spelled them `[]`.
     */
    public function testEmptyObjectsInsideAnExtraFromAnArrayGoOutAsObjects(): void
    {
        $class = $this->generate(
            components: [
                'Sub' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
                'Gear' => ['type' => 'object', 'properties' => [
                    'sub' => ['$ref' => '#/components/schemas/Sub'],
                    'meta' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]],
            ],
            properties: [
                'extra' => ['type' => 'object', 'properties' => ['gear' => ['$ref' => '#/components/schemas/Gear']], 'additionalProperties' => ['$ref' => '#/components/schemas/Gear']],
            ],
        );

        $dto = (new DtoDeserializer())->deserializeValue(
            data: ['extra' => ['gear' => ['sub' => [], 'meta' => [], 'tags' => []], 'g' => ['sub' => [], 'meta' => [], 'tags' => []]]],
            type: $class,
        );
        $expected = '{"extra":{"gear":{"sub":{},"meta":{},"tags":[]},"g":{"sub":{},"meta":{},"tags":[]}}}';
        $this->assertSame($expected, $dto->toJson());
        $this->assertSame($expected, (new DtoNormalizer())->toJson($dto));
    }

    public function testMapComponentOfAnotherComponentRoundTrips(): void
    {
        $class = $this->generate(
            components: [
                'Span' => ['type' => 'object', 'required' => ['from'], 'properties' => ['from' => ['type' => 'string', 'format' => 'date-time']]],
                'SpanMap' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Span']],
            ],
            properties: ['spans' => ['$ref' => '#/components/schemas/SpanMap']],
        );

        $body = '{"spans":{"a":{"from":"2024-01-02T10:00:00+00:00"},"b":{"from":"2024-02-03T10:00:00+00:00"}}}';
        $dto = $this->deserialize(class: $class, body: $body);
        $this->assertSame($body, $dto->toJson());
        $this->assertSame($body, (new DtoNormalizer())->toJson($dto));
        $this->assertRejected(class: $class, body: '{"spans":{"a":{}}}', message: 'from');
        $this->assertRejected(class: $class, body: '{"spans":{"a":{"from":"later"}}}', message: 'from');
    }

    public function testNullableOnThePropertyAndOnABranchAdmitsNullOnce(): void
    {
        $class = $this->generate(properties: [
            'b' => ['nullable' => true, 'oneOf' => [['type' => 'string', 'nullable' => true], ['type' => 'integer']]],
        ]);

        $this->assertNull($this->deserialize(class: $class, body: '{"b":null}')->getB());
        $this->assertSame('x', $this->deserialize(class: $class, body: '{"b":"x"}')->getB());
    }

    public function testUuidIsCheckedForItsShapeOnly(): void
    {
        $class = $this->generate(properties: ['id' => ['type' => 'string', 'format' => 'uuid']]);

        foreach (['3f2504e0-4f89-41d3-da0c-0305e82c3301', '3f2504e0-4f89-01d3-9a0c-0305e82c3301', '00000000-0000-0000-0000-000000000000'] as $uuid) {
            $this->assertSame($uuid, $this->deserialize(class: $class, body: sprintf('{"id":"%s"}', $uuid))->getId());
        }
        $this->assertRejected(class: $class, body: '{"id":"3f2504e0-4f89-41d3-da0c-0305e82c330"}', message: 'uuid');
    }

    public function testPropertiesNamedLikeGeneratedMembersCompileInAnyOrder(): void
    {
        foreach ([['this', 'this2'], ['this2', 'this'], ['alpha', 'alphaInPath'], ['this', 'this2InPath'], ['alpha', 'alphaInPath', 'alphaInPath2InPath']] as $names) {
            $class = $this->generate(properties: array_fill_keys($names, ['type' => 'string']));
            $this->assertGeneratedClassCompiles($class);
            $body = (string)json_encode(array_combine($names, array_slice(['a', 'b', 'c'], 0, count($names))));

            $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson(), implode(', ', $names));
        }
    }

    /**
     * Renamed in one `allOf` branch, `this` became `this2` beside a real `this2` in another branch, and
     * the two were merged into one property: the `this` key was gone from the class.
     */
    public function testPropertiesNamedLikeGeneratedMembersInSeparateAllOfBranchesStayApart(): void
    {
        $class = $this->generate(
            components: ['Pair' => ['allOf' => [
                ['type' => 'object', 'properties' => ['this' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['this2' => ['type' => 'integer']]],
            ]]],
            properties: ['pair' => ['$ref' => '#/components/schemas/Pair']],
        );

        $this->assertGeneratedClassCompiles(substr($class, 0, -strlen('Probe')) . 'Pair');
        $body = '{"pair":{"this":"a","this2":2}}';
        $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson());
    }

    /**
     * Names a `$ref` branch brings — through its own `allOf`, or renamed by it — are seen by the inline
     * branch beside it: `this` and a real `this2` stay two properties with their own wire keys.
     */
    public function testRenamesSeeTheNamesAReferencedBranchEmits(): void
    {
        $cases = [
            'parent composed through allOf' => [
                'Base' => ['allOf' => [['type' => 'object', 'properties' => ['this2' => ['type' => 'integer']]]]],
                'Other' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]],
                'Pair' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['$ref' => '#/components/schemas/Other'], ['type' => 'object', 'properties' => ['this' => ['type' => 'string']]]]],
            ],
            'parent renamed its own property' => [
                'Base' => ['type' => 'object', 'properties' => ['this' => ['type' => 'string']]],
                'Other' => ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string']]],
                'Pair' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['$ref' => '#/components/schemas/Other'], ['type' => 'object', 'properties' => ['this2' => ['type' => 'integer']]]]],
            ],
        ];

        foreach ($cases as $case => $components) {
            $class = $this->generate(components: $components, properties: ['pair' => ['$ref' => '#/components/schemas/Pair']]);
            $this->assertGeneratedClassCompiles(substr($class, 0, -strlen('Probe')) . 'Pair');

            // The parent's properties come first, so the key order is the class's, not the body's.
            $body = '{"pair":{"this":"a","this2":2}}';
            $this->assertJsonStringEqualsJsonString($body, $this->deserialize(class: $class, body: $body)->toJson(), $case);
        }
    }

    /**
     * A property a child redeclares — same wire name as the parent's — is an override, not a second
     * property: the renames must leave it alone.
     */
    public function testRedeclaredParentPropertyStaysOneProperty(): void
    {
        $class = $this->generate(
            components: [
                'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'this' => ['type' => 'string']]],
                'Child' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'maxLength' => 3], 'this' => ['type' => 'string']]]]],
            ],
            properties: ['child' => ['$ref' => '#/components/schemas/Child']],
        );

        $body = '{"child":{"id":"abc","this":"t"}}';
        $this->assertSame($body, $this->deserialize(class: $class, body: $body)->toJson());
        $this->assertRejected(class: $class, body: '{"child":{"id":"abcd"}}', message: 'id');
    }

    /**
     * A case-only rename (`NAme` beside `name` becomes `nAme2`) brings its own generated members too.
     */
    public function testCaseOnlyRenameAvoidsTheGeneratedMembersOfAnotherProperty(): void
    {
        foreach (['runtime' => 'nAme2InPath', 'symfony' => 'nAme2Provided'] as $mode => $clash) {
            $directory = $this->root . '/CaseNames' . ucfirst($mode);
            (new GenerateDtoCommand())->generateFromArray(
                openApi: [
                    'openapi' => '3.0.3',
                    'info' => ['title' => 'Names', 'version' => '1.0.0'],
                    'paths' => [],
                    'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'NAme' => ['type' => 'string'],
                        $clash => ['type' => 'string'],
                    ]]]],
                ],
                outputDirectory: $directory,
                namespace: 'CaseNames' . ucfirst($mode),
                mode: $mode,
            );

            $this->assertFileCompiles($directory . '/Probe.php');
        }
    }

    /**
     * Symfony declares `<property>Provided` for each property; `this` renamed to `this2` declared
     * `$this2Provided` beside a real `this2Provided` property.
     */
    public function testSymfonyRenameMissesTheProvidedFlagOfAnotherProperty(): void
    {
        $directory = $this->root . '/SymfonyNames';
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.3',
                'info' => ['title' => 'Names', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => ['Probe' => ['type' => 'object', 'properties' => [
                    'this' => ['type' => 'string'],
                    'this2Provided' => ['type' => 'string'],
                ]]]],
            ],
            outputDirectory: $directory,
            namespace: 'SymfonyNames',
            mode: 'symfony',
        );

        $this->assertFileCompiles($directory . '/Probe.php');
    }

    /**
     * An `allOf` child built in code sends only what it was given. Its constructor took every inherited
     * optional property as `= null` and handed that null to the parent as if it had been passed, so
     * `new Probe(extra: true)` wrote `{"name":null,"size":null,"extra":true}` and failed its own
     * validation. Deserialized children looked right only because the deserializer resets the presence
     * flags afterwards. The parent alone, the child given an inherited value, and the child of a child
     * are pinned beside it, and so is the trip back in: what the object writes reads back as the same.
     */
    public function testAnAllOfChildBuiltInCodeSendsOnlyWhatItWasGiven(): void
    {
        $components = [
            'Base' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer']]],
            'Middle' => ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]]]],
        ];
        $child = $this->generate(
            properties: [],
            components: $components,
            probe: ['allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['extra' => ['type' => 'boolean']]]]],
        );
        $grandchild = $this->generate(
            properties: [],
            components: $components,
            probe: ['allOf' => [['$ref' => '#/components/schemas/Middle'], ['type' => 'object', 'properties' => ['extra' => ['type' => 'boolean']]]]],
        );
        $namespace = substr($child, 0, (int)strrpos($child, '\\'));
        $base = $namespace . '\Base';

        $cases = [
            'the parent alone' => [new $base(name: 'x'), '{"name":"x"}'],
            'only its own property' => [new $child(extra: true), '{"extra":true}'],
            'an inherited property too' => [new $child(size: 3, extra: false), '{"size":3,"extra":false}'],
            'nothing at all' => [new $child(), '{}'],
            'a child of a child' => [new $grandchild(color: 'red'), '{"color":"red"}'],
        ];
        $normalizer = new DtoNormalizer();
        foreach ($cases as $label => [$dto, $json]) {
            $this->assertSame($json, $dto->toJson(), $label);
            $this->assertSame([], $normalizer->validate($dto), $label);
            $this->assertSame($json, $normalizer->validateAndNormalizeToJson($dto), $label);
            $this->assertSame($json, $this->deserialize(class: $dto::class, body: $json)->toJson(), $label . ' read back');
        }
        $this->assertFalse((new $child(extra: true))->isNameInRequest());
        $this->assertTrue((new $child(name: 'x'))->isNameInRequest());
    }

    /**
     * The constructor of a child that requires an inherited property — `required` in one of its
     * branches — takes that property without a default, so the object cannot be built without it.
     */
    public function testAnAllOfChildRequiringAnInheritedPropertyTakesIt(): void
    {
        $class = $this->generate(
            properties: [],
            components: ['Base' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer']]]],
            probe: ['allOf' => [['$ref' => '#/components/schemas/Base'], ['required' => ['name']]]],
        );

        $this->assertSame('{"name":"a"}', (new $class(name: 'a'))->toJson());
        $this->assertSame('{"name":"a","size":2}', $this->deserialize(class: $class, body: '{"name":"a","size":2}')->toJson());
        $this->assertRejected(class: $class, body: '{"size":2}', message: 'name');
        $parameter = (new ReflectionMethod($class, '__construct'))->getParameters()[0];
        $this->assertSame('name', $parameter->getName());
        $this->assertFalse($parameter->isOptional());
    }

    /**
     * Before the class is loaded: a "Cannot redeclare" is a fatal error that would end the whole run.
     */
    private function assertGeneratedClassCompiles(string $class): void
    {
        $this->assertFileCompiles($this->root . '/' . str_replace('\\', '/', $class) . '.php');
    }

    private function assertFileCompiles(string $file): void
    {
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    /**
     * A DTO built in code with an enum case for `oneOf: [$ref Tint, {string, pattern}, integer]`: the
     * case is the enum branch's alone. The string branch took it for a string and skipped its pattern,
     * so `Tint::BLUE` matched two branches and the DTO failed its own validation.
     */
    public function testAnEnumCaseBuiltInCodeMatchesOnlyTheEnumBranch(): void
    {
        $class = $this->generate(
            components: ['Tint' => ['type' => 'string', 'enum' => ['red', 'blue']]],
            properties: ['shade' => ['oneOf' => [
                ['$ref' => '#/components/schemas/Tint'],
                ['type' => 'string', 'pattern' => '^\d+$'],
                ['type' => 'integer'],
            ]]],
        );
        $tint = substr($class, 0, -strlen('Probe')) . 'Tint';

        foreach ([$tint::from('blue'), $tint::from('red'), '123', 42] as $value) {
            $this->assertSame([], (new DtoNormalizer())->validate(new $class(shade: $value)), var_export($value, true));
        }
        $this->assertNotSame([], (new DtoNormalizer())->validate(new $class(shade: 'abc')));
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $components
     * @param array<string, mixed>|null $probe the whole `Probe` schema, for a shape `$properties` cannot spell
     */
    private function generate(array $properties, array $components = [], string $openapi = '3.0.3', ?array $probe = null): string
    {
        $namespace = 'Shapes' . bin2hex(random_bytes(8));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => $openapi,
                'info' => ['title' => 'Shapes', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [...$components, 'Probe' => $probe ?? ['type' => 'object', 'properties' => $properties]]],
            ],
            outputDirectory: $directory,
            namespace: $namespace,
        );
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });

        return $namespace . '\Probe';
    }

    private function deserialize(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function assertRejected(string $class, string $body, string $message): void
    {
        try {
            $this->deserialize(class: $class, body: $body);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail(sprintf('%s must be rejected.', $body));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
