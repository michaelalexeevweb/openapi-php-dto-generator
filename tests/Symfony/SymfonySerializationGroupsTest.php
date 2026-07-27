<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * `readOnly` / `writeOnly` in Symfony mode.
 *
 * Runtime mode enforces both itself (the normalizer drops writeOnly fields, the deserializer
 * ignores a readOnly value from the client). Symfony mode has no runtime of its own, so the only
 * mechanism is serialization groups — and groups are all-or-nothing per class: the moment one
 * attribute of a class carries a group, every attribute without one is dropped. These tests pin
 * both halves of that: the groups are emitted on EVERY property of EVERY class of a document that
 * uses the keywords, and on nothing at all in a document that does not.
 */
final class SymfonySerializationGroupsTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony-groups';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * @return array<string, mixed>
     */
    private static function accountSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Account' => [
                        'type' => 'object',
                        'required' => ['login', 'password', 'child'],
                        'properties' => [
                            'id' => ['type' => ['integer', 'null'], 'readOnly' => true],
                            'login' => ['type' => 'string'],
                            'password' => ['type' => 'string', 'writeOnly' => true],
                            'child' => ['$ref' => '#/components/schemas/Child'],
                        ],
                    ],
                    // No readOnly/writeOnly of its own — it still needs groups, otherwise it
                    // normalizes to [] as soon as the parent is filtered by group.
                    'Child' => [
                        'type' => 'object',
                        'required' => ['note'],
                        'properties' => ['note' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    public function testWriteOnlyFieldIsDroppedFromTheReadGroup(): void
    {
        $fqcn = $this->generate(self::accountSpec(), 'GroupsRead');
        $serializer = $this->serializer();
        $dto = $serializer->deserialize(self::PAYLOAD, $fqcn, 'json');

        $this->assertSame(
            ['id' => 99, 'login' => 'u', 'child' => ['note' => 'n']],
            $serializer->normalize($dto, null, ['groups' => 'read']),
        );
    }

    public function testNestedDtoSurvivesGroupFiltering(): void
    {
        $fqcn = $this->generate(self::accountSpec(), 'GroupsNested');
        $serializer = $this->serializer();
        $dto = $serializer->deserialize(self::PAYLOAD, $fqcn, 'json');

        /** @var array<string, mixed> $normalized */
        $normalized = $serializer->normalize($dto, null, ['groups' => 'read']);

        // The regression this guards against: marking only the readOnly/writeOnly properties made
        // every other property groupless, and a groupless class normalizes to [] under a filter.
        $this->assertSame(['note' => 'n'], $normalized['child']);
    }

    public function testReadOnlyFieldIsIgnoredWhenDenormalizingWithTheWriteGroup(): void
    {
        $fqcn = $this->generate(self::accountSpec(), 'GroupsWrite');
        $serializer = $this->serializer();

        $dto = $serializer->deserialize(self::PAYLOAD, $fqcn, 'json', ['groups' => 'write']);

        $this->assertNull($dto->getId(), 'a readOnly value sent by the client must not be accepted');
        $this->assertSame('u', $dto->getLogin());
        $this->assertSame('secret', $dto->getPassword());
    }

    public function testWithoutGroupsInTheContextNothingIsFilteredOut(): void
    {
        $fqcn = $this->generate(self::accountSpec(), 'GroupsDefault');
        $serializer = $this->serializer();
        $dto = $serializer->deserialize(self::PAYLOAD, $fqcn, 'json');

        // The documented default: Symfony only applies groups when asked to, so an application
        // that passes no context still sees the writeOnly field. This is why the generated class
        // docblock spells the context out.
        $this->assertSame(
            ['id' => 99, 'login' => 'u', 'password' => 'secret', 'child' => ['note' => 'n']],
            $serializer->normalize($dto),
        );
    }

    public function testGeneratedClassesExplainTheRequiredContext(): void
    {
        $this->generate(self::accountSpec(), 'GroupsDocblock');

        $child = (string)file_get_contents($this->outputDirectory . '/GroupsDocblock/Child.php');
        $this->assertStringContainsString("normalize(\$dto, null, ['groups' => 'read'])", $child);
        $this->assertStringContainsString("['groups' => 'write']", $child);
    }

    public function testDocumentWithoutReadOrWriteOnlyGetsNoGroupsAtAll(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Plain' => [
                        'type' => 'object',
                        'required' => ['a'],
                        'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        $this->generate($spec, 'PlainDocument', className: 'Plain');

        $code = (string)file_get_contents($this->outputDirectory . '/PlainDocument/Plain.php');
        $this->assertStringNotContainsString('#[Groups(', $code, 'groups would be pure noise here');
        $this->assertStringNotContainsString('serialization groups', $code);
    }

    public function testReadOnlyAndWriteOnlyTogetherBecomeIgnore(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Odd' => [
                        'type' => 'object',
                        'required' => ['visible'],
                        'properties' => [
                            'visible' => ['type' => 'string'],
                            'neither' => ['type' => ['string', 'null'], 'readOnly' => true, 'writeOnly' => true],
                        ],
                    ],
                ],
            ],
        ];

        $fqcn = $this->generate($spec, 'GroupsIgnore', className: 'Odd');

        $code = (string)file_get_contents($this->outputDirectory . '/GroupsIgnore/Odd.php');
        $this->assertStringContainsString('#[Ignore]', $code);
        $this->assertStringContainsString('use Symfony\Component\Serializer\Attribute\Ignore;', $code);

        // The contradiction resolves the way runtime mode resolves it: the field is out of both
        // directions.
        $serializer = $this->serializer();
        $dto = $serializer->deserialize('{"visible":"v","neither":"x"}', $fqcn, 'json');
        $this->assertNull($dto->getNeither());
        $this->assertSame(['visible' => 'v'], $serializer->normalize($dto));
    }

    private const PAYLOAD = '{"id":99,"login":"u","password":"secret","child":{"note":"n"}}';

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generate(array $spec, string $namespace, string $className = 'Account'): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $this->generator->generateFromArray($spec, $target, $namespace, 'symfony');

        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\\' . $className;

        return $fqcn;
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        return new Serializer(
            [
                new ObjectNormalizer(
                    $classMetadataFactory,
                    new MetadataAwareNameConverter($classMetadataFactory),
                    null,
                    $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
    }
}
