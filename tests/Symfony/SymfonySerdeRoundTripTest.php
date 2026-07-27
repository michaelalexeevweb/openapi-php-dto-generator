<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Verifies that the Symfony-mode DTOs actually deserialize (denormalize) and serialize (normalize)
 * through a real Symfony serializer in agreement with the OpenAPI schema: snake_case mapping, enum
 * coercion, date-time parsing, nested DTOs and arrays of DTOs, round-trip identity.
 */
final class SymfonySerdeRoundTripTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony-serde';

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

        return new Serializer(
            [new BackedEnumNormalizer(), new DateTimeNormalizer(), $objectNormalizer, new ArrayDenormalizer()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSpec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'x-enum-varnames' => ['Pending', 'Paid', 'Shipped'],
                    ],
                    'Customer' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                    ],
                    'Item' => [
                        'type' => 'object',
                        'required' => ['sku', 'qty'],
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'qty' => ['type' => 'integer'],
                        ],
                    ],
                    'Order' => [
                        'type' => 'object',
                        'required' => ['id', 'status', 'customer', 'items'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'status' => ['$ref' => '#/components/schemas/Status'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'customer' => ['$ref' => '#/components/schemas/Customer'],
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function generateOrder(string $namespace): void
    {
        // Per-namespace subdir so each test's files have a unique path (require_once dedups by path).
        $dir = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $this->generator->generateFromArray($this->orderSpec(), $dir, $namespace, 'symfony');
        foreach (['Status', 'Customer', 'Item', 'Order'] as $class) {
            require_once $dir . '/' . $class . '.php';
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<int, string> $classes
     */
    private function generateAndRequire(string $namespace, array $spec, array $classes): void
    {
        $dir = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $this->generator->generateFromArray($spec, $dir, $namespace, 'symfony');
        foreach ($classes as $class) {
            require_once $dir . '/' . $class . '.php';
        }
    }

    public function testMultiTypeUnionDenormalizesBothScalars(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeMulti';
        $this->generateAndRequire($ns, [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'M' => [
                        'type' => 'object',
                        'required' => ['v'],
                        'properties' => ['v' => ['type' => ['string', 'integer']]],
                    ],
                ],
            ],
        ], ['M']);

        $content = (string)file_get_contents($this->outputDirectory . '/' . $ns . '/M.php');
        $this->assertStringContainsString('string|int $v', $content);

        $serializer = $this->serializer();
        $cls = $ns . '\M';
        $this->assertSame('hi', $serializer->denormalize(['v' => 'hi'], $cls)->getV());
        $this->assertSame(7, $serializer->denormalize(['v' => 7], $cls)->getV());
    }

    public function testArrayOfEnumsDenormalizesEachItem(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeEnumArr';
        $this->generateAndRequire($ns, [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => ['type' => 'integer', 'enum' => [0, 1], 'x-enum-varnames' => ['Off', 'On']],
                    'Bag' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Status']],
                        ],
                    ],
                ],
            ],
        ], ['Status', 'Bag']);

        $serializer = $this->serializer();
        $bag = $serializer->denormalize(['tags' => [0, 1, 1]], $ns . '\Bag');
        $statusClass = $ns . '\Status';
        $this->assertCount(3, $bag->getTags());
        $this->assertSame($statusClass::from(0), $bag->getTags()[0]);
        $this->assertSame($statusClass::from(1), $bag->getTags()[2]);
    }

    public function testNullableNestedDtoAndEnumDenormalizeNull(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeNullable';
        $this->generateAndRequire($ns, [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => ['type' => 'integer', 'enum' => [0, 1], 'x-enum-varnames' => ['Off', 'On']],
                    'Inner' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'string']]],
                    'Holder' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['oneOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]],
                            'inner' => ['allOf' => [['$ref' => '#/components/schemas/Inner']], 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ], ['Status', 'Inner', 'Holder']);

        $serializer = $this->serializer();
        $cls = $ns . '\Holder';

        $withNulls = $serializer->denormalize(['status' => null, 'inner' => null], $cls);
        $this->assertNull($withNulls->getStatus());
        $this->assertNull($withNulls->getInner());

        $withValues = $serializer->denormalize(['status' => 1, 'inner' => ['x' => 'hi']], $cls);
        $this->assertSame(($ns . '\Status')::from(1), $withValues->getStatus());
        $this->assertInstanceOf($ns . '\Inner', $withValues->getInner());
        $this->assertSame('hi', $withValues->getInner()->getX());
    }

    public function testDeeplyNestedGraphDenormalizes(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeDeep';
        $this->generateAndRequire($ns, [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'C' => ['type' => 'object', 'required' => ['val'], 'properties' => ['val' => ['type' => 'string']]],
                    'B' => ['type' => 'object', 'required' => ['c'], 'properties' => ['c' => ['$ref' => '#/components/schemas/C']]],
                    'A' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
                ],
            ],
        ], ['C', 'B', 'A']);

        $serializer = $this->serializer();
        $a = $serializer->denormalize(['b' => ['c' => ['val' => 'deep']]], $ns . '\A');
        $this->assertInstanceOf($ns . '\B', $a->getB());
        $this->assertInstanceOf($ns . '\C', $a->getB()->getC());
        $this->assertSame('deep', $a->getB()->getC()->getVal());
    }

    public function testSelfReferentialDtoDenormalizesFiniteTreeWithoutInfiniteLoop(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeSelfRef';
        $this->generateAndRequire($ns, [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
                            'parent' => ['allOf' => [['$ref' => '#/components/schemas/Node']], 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ], ['Node']);

        $serializer = $this->serializer();
        $cls = $ns . '\Node';

        $root = $serializer->denormalize([
            'name' => 'root',
            'parent' => null,
            'children' => [
                ['name' => 'a', 'children' => [['name' => 'a1']]],
                ['name' => 'b'],
            ],
        ], $cls);

        $this->assertSame('root', $root->getName());
        $this->assertNull($root->getParent());
        $this->assertCount(2, $root->getChildren());
        $this->assertInstanceOf($cls, $root->getChildren()[0]);
        $this->assertSame('a1', $root->getChildren()[0]->getChildren()[0]->getName());

        // Normalizing a finite tree must terminate.
        $payload = $serializer->normalize($root);
        $this->assertSame('root', $payload['name']);
        $this->assertSame('a1', $payload['children'][0]['children'][0]['name']);
    }

    public function testDenormalizesWirePayloadIntoTypedObjectGraph(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeIn';
        $this->generateOrder($ns);
        $serializer = $this->serializer();

        $payload = [
            'id' => 'o-1',
            'status' => 1,
            'created_at' => '2026-01-02T03:04:05+00:00',
            'customer' => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'items' => [
                ['sku' => 'A', 'qty' => 2],
                ['sku' => 'B', 'qty' => 5],
            ],
        ];

        $order = $serializer->denormalize($payload, $ns . '\Order');

        $this->assertSame('o-1', $order->getId());
        $this->assertSame(($ns . '\Status')::from(1), $order->getStatus());
        $this->assertInstanceOf(DateTimeImmutable::class, $order->getCreatedAtAsDateTime());
        $this->assertSame('2026-01-02T03:04:05+00:00', $order->getCreatedAt());
        // Nested DTO denormalized into a typed object.
        $this->assertInstanceOf($ns . '\Customer', $order->getCustomer());
        $this->assertSame('Alice', $order->getCustomer()->getName());
        // Array of DTOs denormalized via the @param array<Item> generic.
        $this->assertCount(2, $order->getItems());
        $this->assertInstanceOf($ns . '\Item', $order->getItems()[0]);
        $this->assertSame('A', $order->getItems()[0]->getSku());
        $this->assertSame(5, $order->getItems()[1]->getQty());
        // Omitted optional defaults to null.
        $this->assertNull($order->getNote());
    }

    public function testNormalizesObjectGraphBackToSpecShapedPayload(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeOut';
        $this->generateOrder($ns);
        $serializer = $this->serializer();

        $orderClass = $ns . '\Order';
        $customerClass = $ns . '\Customer';
        $itemClass = $ns . '\Item';
        $statusClass = $ns . '\Status';

        // created_at and note are optional, so they are set rather than passed.
        $order = new $orderClass(
            id: 'o-2',
            status: $statusClass::from(2),
            customer: $customer = new $customerClass(name: 'Bob'),
            items: [new $itemClass(sku: 'X', qty: 1)],
        );
        $order->setCreatedAt(new DateTimeImmutable('2026-05-06T07:08:09+00:00'));
        $order->setNote('gift');

        $payload = $serializer->normalize($order);

        // enum -> backing value, date-time -> ISO string, snake_case key, nested DTO -> nested array.
        $this->assertSame(2, $payload['status']);
        $this->assertSame('gift', $payload['note']);
        $this->assertSame('o-2', $payload['id']);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertStringStartsWith('2026-05-06T07:08:09', (string)$payload['created_at']);
        $this->assertSame(['email' => null, 'name' => 'Bob'], $payload['customer']);
        $this->assertSame([['sku' => 'X', 'qty' => 1]], $payload['items']);
    }

    public function testRoundTripPreservesData(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeRound';
        $this->generateOrder($ns);
        $serializer = $this->serializer();

        $payload = [
            'id' => 'o-9',
            'status' => 0,
            'created_at' => '2026-07-08T09:10:11+00:00',
            'customer' => ['name' => 'Cleo', 'email' => 'cleo@example.com'],
            'items' => [['sku' => 'Z', 'qty' => 3]],
            'note' => 'fragile',
        ];

        $order = $serializer->denormalize($payload, $ns . '\Order');
        $roundTripped = $serializer->normalize($order);

        // created_at may re-serialize with a normalized offset; compare the instant separately.
        $this->assertSame(
            (new DateTimeImmutable($payload['created_at']))->getTimestamp(),
            (new DateTimeImmutable((string)$roundTripped['created_at']))->getTimestamp(),
        );
        unset($payload['created_at'], $roundTripped['created_at']);
        // Key order is not part of the contract — optional properties are declared before the
        // constructor, so they come out first — but the data and its types are.
        $this->assertSame(self::sortedByKey($payload), self::sortedByKey($roundTripped));
    }

    /**
     * Symfony mode flattens `allOf`, so a child's `NAme` and the parent's `name` land in the SAME
     * class — and PHP method names are case-insensitive, so the two getters used to be one method:
     * the file did not even parse ("Cannot redeclare Discriminator2::getNAme()"), which is how the
     * demo corpus shipped. The PHP identifier of the second spelling is suffixed; the wire keys must
     * both survive a real serializer in both directions.
     */
    public function testCaseOnlySiblingKeysRoundTripThroughTheSerializer(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $ns = 'SerdeCaseOnly';
        $this->generateAndRequire($ns, [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'CaseBase' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                    'CaseChild' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/CaseBase'],
                            [
                                'type' => 'object',
                                'required' => ['NAme'],
                                'properties' => ['NAme' => ['type' => 'string']],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['CaseBase', 'CaseChild']);

        $child = (string)file_get_contents($this->outputDirectory . '/' . $ns . '/CaseChild.php');
        $this->assertStringContainsString('#[SerializedName(\'NAme\')]', $child);
        $this->assertStringContainsString('public function getNAme2(): string', $child);

        $serializer = $this->serializer();
        $payload = ['name' => 'parent value', 'NAme' => 'child value'];

        $dto = $serializer->denormalize($payload, $ns . '\CaseChild');
        $this->assertSame('parent value', $dto->getName());
        $this->assertSame('child value', $dto->getNAme2());

        // The suffix is a PHP identifier only: `nAme2` must not leak onto the wire.
        $this->assertSame(self::sortedByKey($payload), self::sortedByKey($serializer->normalize($dto)));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function sortedByKey(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortedByKey($item);
            }
        }

        return $value;
    }
}
