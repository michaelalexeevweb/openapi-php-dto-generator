<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

/**
 * End-to-end "controller" flow for Symfony attribute mode: an incoming JSON Request is
 * denormalized into the generated DTO by a real Symfony serializer and then validated by the
 * native Symfony validator only (no DtoValidator, no manual call of validateOpenApiConstraints).
 * Asserts that the generated #[Assert\Callback] method runs as part of that native pass.
 */
final class SymfonyControllerCallbackValidationTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony-controller-callback';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    private function deleteRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $objectNormalizer = new ObjectNormalizer($classMetadataFactory, $nameConverter, null, $typeExtractor);

        // DateTimeNormalizer is mandatory: without it ObjectNormalizer instantiates
        // DateTimeImmutable from the constructor and silently yields "now", discarding the payload
        // date — which would make date/date-time assertions pass or fail depending on the clock.
        return new Serializer(
            [new BackedEnumNormalizer(), new DateTimeNormalizer(), $objectNormalizer, new ArrayDenormalizer()],
            [new JsonEncoder()],
        );
    }

    /**
     * The same two steps a controller performs with #[MapRequestPayload] — body -> DTO -> validator
     * — driven directly. The attribute itself (and the status codes it produces) is exercised in
     * SymfonySerializerContractTest.
     *
     * @param class-string $dtoClass
     */
    private function handleRequest(Request $request, string $dtoClass): ConstraintViolationListInterface
    {
        $dto = $this->serializer()->deserialize((string)$request->getContent(), $dtoClass, 'json');
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return $validator->validate($dto);
    }

    private static function jsonRequest(string $json): Request
    {
        return Request::create('/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    // Nested schema carrying its own unsupported keyword -> own callback.
                    'Meta' => [
                        'type' => 'object',
                        'required' => ['kind'],
                        'properties' => [
                            'kind' => ['type' => 'string'],
                            'flags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['const' => 'primary'],
                                'minContains' => 1,
                            ],
                        ],
                    ],
                    'Payload' => [
                        'type' => 'object',
                        'required' => ['id', 'tags'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'contains' => ['const' => 'hit'],
                                'minContains' => 1,
                                'maxContains' => 2,
                            ],
                            'meta' => ['$ref' => '#/components/schemas/Meta'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return class-string
     */
    private function generatePayload(string $namespace): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        $this->generator->generateFromArray($this->spec(), $target, $namespace, 'symfony');

        foreach (['Meta', 'Payload'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Payload';

        return $fqcn;
    }

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generateFromSpec(array $spec, string $namespace, string $rootClass): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $this->generator->generateFromArray($spec, $target, $namespace, 'symfony');
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\\' . $rootClass;

        return $fqcn;
    }

    public function testNativeValidatorRunsCallbackForValidRequest(): void
    {
        $fqcn = $this->generatePayload('SymCtrlOk');

        $violations = $this->handleRequest(
            self::jsonRequest('{"id":7,"tags":["hit","other"],"meta":{"kind":"a","flags":["primary"]}}'),
            $fqcn,
        );

        $this->assertCount(0, $violations, (string)$violations);
    }

    public function testNativeValidatorReportsCallbackViolationFromRequestBody(): void
    {
        $fqcn = $this->generatePayload('SymCtrlContains');

        $violations = $this->handleRequest(
            self::jsonRequest('{"id":7,"tags":["miss"]}'),
            $fqcn,
        );

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }

        $this->assertNotSame([], $messages);
        $this->assertStringContainsString(
            'field "tags" must contain at least 1 item(s) matching the \'contains\' schema',
            implode("\n", $messages),
        );
    }

    public function testNativeValidatorMixesAttributeAndCallbackViolations(): void
    {
        $fqcn = $this->generatePayload('SymCtrlMixed');

        $violations = $this->handleRequest(
            self::jsonRequest('{"id":0,"tags":["hit","hit","hit"]}'),
            $fqcn,
        );

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath() . ': ' . (string)$violation->getMessage();
        }
        $joined = implode("\n", $messages);

        // minimum -> plain Assert attribute; maxContains -> callback. Both must appear in one pass.
        $this->assertStringContainsString('id:', $joined);
        $this->assertStringContainsString('field "tags" must contain at most 2 item(s) matching the \'contains\' schema', $joined);
    }

    /**
     * @dataProvider containsBoundsProvider
     */
    public function testContainsBoundsAreEnforcedByCallback(
        ?int $minContains,
        ?int $maxContains,
        string $tagsJson,
        bool $expectedValid,
    ): void {
        $tags = ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['const' => 'hit']];
        if ($minContains !== null) {
            $tags['minContains'] = $minContains;
        }
        if ($maxContains !== null) {
            $tags['maxContains'] = $maxContains;
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Bounded' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => ['tags' => $tags],
                    ],
                ],
            ],
        ];

        $namespace = 'SymCtrlBounds' . md5($tagsJson . (string)$minContains . (string)$maxContains);
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);
        $this->generator->generateFromArray($spec, $target, $namespace, 'symfony');
        require_once $target . '/Bounded.php';

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Bounded';
        $violations = $this->handleRequest(self::jsonRequest('{"tags":' . $tagsJson . '}'), $fqcn);

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }

        $expectedValid
            ? $this->assertSame([], $messages)
            : $this->assertNotSame([], $messages);
    }

    /**
     * minContains / maxContains modify `contains` and must reach the callback constants, otherwise
     * the runtime falls back to "at least 1, no upper bound" and disagrees with the spec.
     *
     * @return array<string, array{0: int|null, 1: int|null, 2: string, 3: bool}>
     */
    public static function containsBoundsProvider(): array
    {
        return [
            'minContains 0, no match -> valid' => [0, null, '["miss","other"]', true],
            'minContains 2, one match -> invalid' => [2, null, '["hit","miss"]', false],
            'minContains 2, two matches -> valid' => [2, null, '["hit","hit"]', true],
            'maxContains 2, three matches -> invalid' => [null, 2, '["hit","hit","hit"]', false],
            'maxContains 2, two matches -> valid' => [null, 2, '["hit","hit"]', true],
        ];
    }

    public function testNestedDtoCallbackRunsViaAssertValidCascade(): void
    {
        $fqcn = $this->generatePayload('SymCtrlNested');

        $violations = $this->handleRequest(
            self::jsonRequest('{"id":7,"tags":["hit"],"meta":{"kind":"a","flags":["secondary"]}}'),
            $fqcn,
        );

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath() . ': ' . (string)$violation->getMessage();
        }

        $this->assertStringContainsString(
            'field "flags" must contain at least 1 item(s) matching the \'contains\' schema',
            implode("\n", $messages),
        );
    }

    public function testCallbackAcceptsGeneratedEnumItemsAfterSerializerDenormalization(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'uniqueItems' => true,
                                'items' => ['type' => 'string', 'enum' => ['ab', 'abc'], 'minLength' => 3],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlEnumItems', 'Holder');
        $violations = $this->handleRequest(self::jsonRequest('{"f":["abc"]}'), $fqcn);
        $this->assertCount(0, $violations, (string)$violations);

        $invalid = $this->handleRequest(self::jsonRequest('{"f":["ab"]}'), $fqcn);
        $messages = [];
        foreach ($invalid as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $matched = array_values(array_filter(
            $messages,
            static fn(string $message): bool => str_contains($message, 'field "f"[0] length must be at least 3 characters'),
        ));
        $this->assertCount(1, $matched);
    }

    public function testCallbackAcceptsGeneratedEnumMapValuesAfterSerializerDenormalization(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'MapHolder' => [
                        'type' => 'object',
                        'required' => ['m'],
                        'properties' => [
                            'm' => [
                                'type' => 'object',
                                'minProperties' => 1,
                                'additionalProperties' => ['type' => 'string', 'enum' => ['ab', 'abc'], 'minLength' => 3],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlEnumMap', 'MapHolder');
        $violations = $this->handleRequest(self::jsonRequest('{"m":{"k":"abc"}}'), $fqcn);
        $this->assertCount(0, $violations, (string)$violations);

        $invalid = $this->handleRequest(self::jsonRequest('{"m":{"k":"ab"}}'), $fqcn);
        $messages = [];
        foreach ($invalid as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $matched = array_values(array_filter(
            $messages,
            static fn(string $message): bool => str_contains($message, 'field "m".k length must be at least 3 characters'),
        ));
        $this->assertCount(1, $matched);
    }

    public function testCallbackAcceptsDateTimeItemsAfterSerializerDenormalization(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'DateHolder' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'uniqueItems' => true,
                                'items' => ['type' => 'string', 'format' => 'date-time', 'pattern' => '^2026'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlDateItems', 'DateHolder');
        $violations = $this->handleRequest(self::jsonRequest('{"f":["2026-01-01T00:00:00Z"]}'), $fqcn);
        $this->assertCount(0, $violations, (string)$violations);

        $invalid = $this->handleRequest(self::jsonRequest('{"f":["2025-01-01T00:00:00Z"]}'), $fqcn);
        $messages = [];
        foreach ($invalid as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $matched = array_values(array_filter(
            $messages,
            static fn(string $message): bool => str_contains($message, 'field "f"[0] must match pattern ^2026'),
        ));
        $this->assertCount(1, $matched);
    }

    public function testRequiredInThenSubschemaIsEnforced(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Conditional' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                                'if' => ['properties' => ['kind' => ['const' => 'x']]],
                                'then' => ['required' => ['extra']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlReqThen', 'Conditional');
        $violations = $this->handleRequest(self::jsonRequest('{"f":{"kind":"x"}}'), $fqcn);
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString('field "f".extra is required', implode("\n", $messages));
    }

    public function testRequiredInItemsSubschemaIsEnforced(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ListHolder' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'array',
                                'items' => [
                                    'required' => ['a'],
                                    'properties' => [
                                        'a' => ['type' => 'integer'],
                                        'b' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlReqItems', 'ListHolder');
        $violations = $this->handleRequest(self::jsonRequest('{"f":[{"b":1}]}'), $fqcn);
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString('field "f"[0].a is required', implode("\n", $messages));
    }

    public function testRequiredInContentSchemaIsEnforced(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ContentHolder' => [
                        'type' => 'object',
                        'required' => ['payload'],
                        'properties' => [
                            'payload' => [
                                'type' => 'string',
                                'contentEncoding' => 'base64',
                                'contentMediaType' => 'application/json',
                                'contentSchema' => [
                                    'type' => 'object',
                                    'required' => ['a'],
                                    'properties' => ['a' => ['type' => 'integer']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlReqContent', 'ContentHolder');
        $encoded = base64_encode('{"b":9}');
        $violations = $this->handleRequest(self::jsonRequest('{"payload":"' . $encoded . '"}'), $fqcn);
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString('field "payload".a is required', implode("\n", $messages));
    }

    public function testNestedDtoNotConstraintDoesNotFalsePositiveAndDoesNotDuplicate(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ParentNode' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'object',
                                'required' => ['x'],
                                'properties' => [
                                    'x' => ['type' => 'string'],
                                    'forbidden' => ['type' => 'string'],
                                ],
                                'not' => ['required' => ['forbidden']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlNestedNot', 'ParentNode');
        $valid = $this->handleRequest(self::jsonRequest('{"f":{"x":"y"}}'), $fqcn);
        $this->assertCount(0, $valid, (string)$valid);

        $invalid = $this->handleRequest(self::jsonRequest('{"f":{"x":"y","forbidden":"z"}}'), $fqcn);
        $messages = [];
        foreach ($invalid as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $matched = array_values(array_filter(
            $messages,
            static fn(string $message): bool => str_contains($message, 'must not match the \'not\' schema'),
        ));
        $this->assertCount(1, $matched);
    }

    public function testPatternPropertiesOnlyObjectRemainsReachable(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'PatternContainer' => [
                        'type' => 'object',
                        'required' => ['bag'],
                        'properties' => [
                            'bag' => [
                                'type' => 'object',
                                'patternProperties' => [
                                    '^x-' => ['type' => 'string', 'minLength' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generateFromSpec($spec, 'SymCtrlPatternOnly', 'PatternContainer');
        $valid = $this->handleRequest(self::jsonRequest('{"bag":{"x-key":"ok"}}'), $fqcn);
        $this->assertCount(0, $valid, (string)$valid);

        $invalid = $this->handleRequest(self::jsonRequest('{"bag":{"x-key":"a"}}'), $fqcn);
        $messages = [];
        foreach ($invalid as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertStringContainsString(
            'field "bag".x-key length must be at least 2 characters',
            implode("\n", $messages),
        );
    }
}
