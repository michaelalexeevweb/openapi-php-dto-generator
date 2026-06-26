<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Behavioural coverage for the Symfony attribute mode: property typing (enum, date-time),
 * default values, flattened inheritance, collection cascade and the CLI entry point.
 */
final class SymfonyDtoBehaviorTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-symfony-behavior';

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
        return new Serializer(
            [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(),
                new ArrayDenormalizer(),
            ],
        );
    }

    public function testEnumPropertyDenormalizesAndRejectsUnknownValue(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'x-enum-varnames' => ['Off', 'On', 'Ban'],
                    ],
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['status'],
                        'properties' => ['status' => ['$ref' => '#/components/schemas/Status']],
                    ],
                ],
            ],
        ];

        $ns = 'SymEnum';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        require_once $this->outputDirectory . '/Status.php';
        require_once $this->outputDirectory . '/Holder.php';

        $holderClass = $ns . '\Holder';
        $statusClass = $ns . '\Status';
        $serializer = $this->serializer();

        $holder = $serializer->denormalize(['status' => 1], $holderClass);
        $this->assertSame($statusClass::from(1), $holder->status);

        // An unknown enum value is rejected when coercing to the backed enum.
        $this->expectExceptionMessageMatches('/backed enum|not a valid backing value/i');
        $serializer->denormalize(['status' => 5], $holderClass);
    }

    public function testSymfonyEnumIsPlainBackedEnumWithoutRuntimeArtifacts(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'x-enum-varnames' => ['Off', 'On'],
                        'x-enum-descriptions' => ['Disabled.', 'Enabled'],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymEnumPlain', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Status.php');

        // Symfony mode: a plain backed enum — no library runtime interface or methods.
        $this->assertStringContainsString('enum Status: int', $content);
        $this->assertStringNotContainsString('implements GeneratedDtoInterface', $content);
        $this->assertStringNotContainsString('GeneratedDtoInterface', $content);
        $this->assertStringNotContainsString('function getNormalizationMap', $content);
        $this->assertStringNotContainsString('function jsonSerialize', $content);
        // x-enum-varnames / x-enum-descriptions still apply.
        $this->assertStringContainsString('case Off = 0;', $content);
        $this->assertStringContainsString('Disabled', $content);
    }

    public function testDateTimePropertyRoundTrips(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Event' => [
                        'type' => 'object',
                        'required' => ['at'],
                        'properties' => ['at' => ['type' => 'string', 'format' => 'date-time']],
                    ],
                ],
            ],
        ];

        $ns = 'SymDt';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Event.php');
        $this->assertStringContainsString('public readonly DateTimeImmutable $at', $content);

        require_once $this->outputDirectory . '/Event.php';
        $eventClass = $ns . '\Event';
        $serializer = $this->serializer();

        $event = $serializer->denormalize(['at' => '2026-01-02T03:04:05+00:00'], $eventClass);
        $this->assertInstanceOf(DateTimeImmutable::class, $event->at);
        $this->assertSame('2026-01-02T03:04:05+00:00', $event->at->format('c'));
    }

    public function testScalarAndEnumDefaultsAreRendered(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'x-enum-varnames' => ['Off', 'On', 'Ban'],
                    ],
                    'Conf' => [
                        'type' => 'object',
                        'properties' => [
                            'level' => ['type' => 'integer', 'default' => 5],
                            'status' => ['allOf' => [['$ref' => '#/components/schemas/Status']], 'default' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymDef', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Conf.php');

        $this->assertStringContainsString('public readonly ?int $level = 5,', $content);
        $this->assertStringContainsString('= Status::On,', $content);
    }

    public function testInheritanceIsFlattenedIntoStandaloneClass(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Base' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'string', 'minLength' => 2]],
                    ],
                    'Child' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Base'],
                            ['type' => 'object', 'properties' => ['extra' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymInherit';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Child.php');

        // Flattened: own + inherited props in one constructor, no extends / parent::__construct.
        $this->assertStringNotContainsString('extends', $content);
        $this->assertStringContainsString('public readonly string $id', $content);
        $this->assertStringContainsString('public readonly ?string $extra', $content);

        require_once $this->outputDirectory . '/Child.php';
        $childClass = $ns . '\Child';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // Inherited constraint (minLength on id) is enforced on the flattened child.
        $this->assertCount(0, $validator->validate(new $childClass(id: 'ok', extra: 'x')));
        $this->assertGreaterThan(0, count($validator->validate(new $childClass(id: 'x'))));
    }

    public function testRequiredNullableOmitsNotNull(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'N' => [
                        'type' => 'object',
                        'required' => ['note'],
                        'properties' => ['note' => ['type' => 'string', 'nullable' => true]],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymReqNull', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/N.php');

        // required + nullable: present-but-nullable, so no NotNull (a null value is permitted).
        $this->assertStringContainsString('?string $note', $content);
        $this->assertStringNotContainsString('#[Assert\NotNull]', $content);
    }

    public function testArrayOfDtosCascadesValidationToInvalidItem(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['label'],
                        'properties' => ['label' => ['type' => 'string', 'minLength' => 2]],
                    ],
                    'Post' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymCascade';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        require_once $this->outputDirectory . '/Tag.php';
        require_once $this->outputDirectory . '/Post.php';

        $postClass = $ns . '\Post';
        $tagClass = $ns . '\Tag';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validator->validate(new $postClass(tags: [new $tagClass(label: 'x')]));
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        // Assert\Valid cascades into each array element.
        $this->assertContains('tags[0].label', $paths);
    }

    public function testCliSymfonyFlagGeneratesAttributeDecoratedDto(): void
    {
        $specPath = $this->outputDirectory . '/spec.json';
        file_put_contents($specPath, (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Cli' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string', 'minLength' => 2]],
                    ],
                ],
            ],
        ]));

        $outDir = $this->outputDirectory . '/out';

        $application = new Application();
        $application->add(new GenerateDtoCommand());
        $tester = new CommandTester($application->find('openapi:generate-dto'));
        $exit = $tester->execute([
            '--file' => $specPath,
            '--directory' => $outDir,
            '--namespace' => 'CliNs',
            '--attributes' => 'symfony',
        ]);

        $this->assertSame(0, $exit);
        $content = (string)file_get_contents($outDir . '/Cli.php');
        $this->assertStringContainsString('use Symfony\Component\Validator\Constraints as Assert;', $content);
        $this->assertStringContainsString('#[Assert\Length(min: 2)]', $content);
    }

    public function testNumberAndBooleanTypesAreMappedAndEnforced(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Num' => [
                        'type' => 'object',
                        'required' => ['ratio', 'active'],
                        'properties' => [
                            'ratio' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'active' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymNum';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Num.php');
        $this->assertStringContainsString('public readonly float $ratio', $content);
        $this->assertStringContainsString('public readonly bool $active', $content);

        require_once $this->outputDirectory . '/Num.php';
        $numClass = $ns . '\Num';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $numClass(ratio: 0.5, active: true)));
        $this->assertGreaterThan(0, count($validator->validate(new $numClass(ratio: 2.0, active: false))));
    }

    public function testBinaryFormatMapsToUploadedFile(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Upload' => [
                        'type' => 'object',
                        'properties' => ['file' => ['type' => 'string', 'format' => 'binary']],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymBin', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Upload.php');
        $this->assertStringContainsString('use Symfony\Component\HttpFoundation\File\UploadedFile;', $content);
        $this->assertStringContainsString('public readonly ?UploadedFile $file', $content);
    }

    public function testNullableArrayItemsRenderInDocBlock(): void
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Nums' => [
                        'type' => 'object',
                        'properties' => [
                            'nums' => ['type' => 'array', 'items' => ['type' => 'integer', 'nullable' => true]],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($spec, $this->outputDirectory, 'SymNullItems', 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Nums.php');
        $this->assertStringContainsString('@param ?array<?int> $nums', $content);
    }

    public function testStackedConstraintsAreAllEnforced(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Combo' => [
                        'type' => 'object',
                        'required' => ['code'],
                        'properties' => [
                            'code' => ['type' => 'string', 'minLength' => 2, 'pattern' => '^[a-z]+$'],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymCombo';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Combo.php');
        $this->assertStringContainsString('#[Assert\Length(min: 2)]', $content);
        $this->assertStringContainsString('#[Assert\Regex(', $content);

        require_once $this->outputDirectory . '/Combo.php';
        $comboClass = $ns . '\Combo';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $comboClass(code: 'abc')));
        // 'A' violates both length (too short) and pattern (uppercase): both constraints fire.
        $this->assertSame(2, count($validator->validate(new $comboClass(code: 'A'))));
    }

    public function testInlineMapEnforcesSizeAndValueConstraints(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Counters' => [
                        'type' => 'object',
                        'required' => ['counts'],
                        'properties' => [
                            'counts' => [
                                'type' => 'object',
                                'minProperties' => 1,
                                'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymMap';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Counters.php');
        $this->assertStringContainsString('#[Assert\Count(min: 1)]', $content);
        $this->assertStringContainsString("#[Assert\\All([new Assert\\Type('int'), new Assert\\Range(min: 0)])]", $content);

        require_once $this->outputDirectory . '/Counters.php';
        $cls = $ns . '\Counters';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $cls(counts: ['a' => 1, 'b' => 2])));
        // minProperties: empty map fails Count.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: []))));
        // additionalProperties value constraint: negative value fails Range inside All.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: ['a' => -1]))));
        // additionalProperties value type: a string value fails Type inside All.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: ['a' => 'x']))));
    }

    public function testAnyOfMapsToAtLeastOneOf(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Mix' => [
                        'type' => 'object',
                        'required' => ['v'],
                        'properties' => [
                            'v' => [
                                'anyOf' => [
                                    ['type' => 'string', 'minLength' => 2],
                                    ['type' => 'integer', 'minimum' => 10],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymAnyOf';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Mix.php');
        $this->assertStringContainsString('#[Assert\AtLeastOneOf([', $content);

        require_once $this->outputDirectory . '/Mix.php';
        $cls = $ns . '\Mix';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // Satisfies the string branch.
        $this->assertCount(0, $validator->validate(new $cls(v: 'ab')));
        // Satisfies the integer branch.
        $this->assertCount(0, $validator->validate(new $cls(v: 15)));
        // Satisfies neither branch (too-short string / out-of-range int).
        $this->assertGreaterThan(0, count($validator->validate(new $cls(v: 'a'))));
        $this->assertGreaterThan(0, count($validator->validate(new $cls(v: 5))));
    }

    public function testRequiredParamsAreOrderedBeforeOptionalRegardlessOfSchemaOrder(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        // Optional 'a' is declared BEFORE required 'b' in the schema. The constructor must still
        // place required params first, otherwise PHP throws on construction by required args alone.
        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Order' => [
                        'type' => 'object',
                        'required' => ['b'],
                        'properties' => [
                            'a' => ['type' => 'string'],
                            'b' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymOrder';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Order.php');

        // Required $b appears before optional $a in the constructor.
        $this->assertLessThan(
            (int)strpos($content, 'string $a'),
            (int)strpos($content, 'string $b'),
            'required $b must be declared before optional $a',
        );

        require_once $this->outputDirectory . '/Order.php';
        $cls = $ns . '\Order';

        // Construction by the single required arg must work (no ArgumentCountError).
        $object = new $cls(b: 'x');
        $this->assertSame('x', $object->b);
        $this->assertNull($object->a);
    }

    public function testArrayOfScalarsWithFormatValidatesEachItem(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Mailing' => [
                        'type' => 'object',
                        'required' => ['emails'],
                        'properties' => [
                            'emails' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymEmails';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Mailing.php');
        $this->assertStringContainsString('#[Assert\All([new Assert\Email()])]', $content);

        require_once $this->outputDirectory . '/Mailing.php';
        $cls = $ns . '\Mailing';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $cls(emails: ['a@b.com', 'c@d.com'])));
        // One bad item fails the per-item Email constraint.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(emails: ['a@b.com', 'nope']))));
    }

    public function testWriteOnlyExposedInWriteGroupAndReadOnlyHidden(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Acct' => [
                        'type' => 'object',
                        'required' => ['id', 'password'],
                        'properties' => [
                            'id' => ['type' => 'string', 'readOnly' => true],
                            'password' => ['type' => 'string', 'writeOnly' => true],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymWriteGroup';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        require_once $this->outputDirectory . '/Acct.php';
        $fqcn = $ns . '\Acct';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($classMetadataFactory)]);

        $object = new $fqcn(id: 'u1', password: 'secret');

        // 'write' group exposes the write-only password and hides the read-only id.
        $writeView = $serializer->normalize($object, null, ['groups' => ['write']]);
        $this->assertSame(['password' => 'secret'], $writeView);
    }

    public function testFormatDateMapsToDateTimeImmutable(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Day' => [
                        'type' => 'object',
                        'required' => ['on'],
                        'properties' => ['on' => ['type' => 'string', 'format' => 'date']],
                    ],
                ],
            ],
        ];

        $ns = 'SymDate';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Day.php');
        $this->assertStringContainsString('DateTimeImmutable $on', $content);

        require_once $this->outputDirectory . '/Day.php';
        $cls = $ns . '\Day';
        $object = $this->serializer()->denormalize(['on' => '2026-03-04'], $cls);
        $this->assertInstanceOf(DateTimeImmutable::class, $object->on);
        $this->assertSame('2026-03-04', $object->on->format('Y-m-d'));
    }

    public function testReservedWordAndKebabPropertyNamesGenerateValidDto(): void
    {
        if (!class_exists(Serializer::class)) {
            $this->markTestSkipped('symfony/serializer not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Reserved' => [
                        'type' => 'object',
                        'properties' => [
                            'class' => ['type' => 'string'],
                            'list' => ['type' => 'string'],
                            'foo-bar' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymReserved';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Reserved.php');

        // Reserved words are valid PHP variable names; kebab-case is camelCased with SerializedName.
        $this->assertStringContainsString('$class', $content);
        $this->assertStringContainsString('$list', $content);
        $this->assertStringContainsString("#[SerializedName('foo-bar')]", $content);
        $this->assertStringContainsString('$fooBar', $content);

        require_once $this->outputDirectory . '/Reserved.php';
        $fqcn = $ns . '\Reserved';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $serializer = new Serializer([new ObjectNormalizer($classMetadataFactory, $nameConverter)]);

        $object = $serializer->denormalize(['class' => 'x', 'foo-bar' => 'y'], $fqcn);
        $this->assertSame('x', $object->class);
        $this->assertSame('y', $object->fooBar);
    }

    public function testCliRejectsUnknownAttributesValue(): void
    {
        $specPath = $this->outputDirectory . '/spec2.json';
        file_put_contents($specPath, (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => ['X' => ['type' => 'object']]],
        ]));

        $application = new Application();
        $application->add(new GenerateDtoCommand());
        $tester = new CommandTester($application->find('openapi:generate-dto'));
        $exit = $tester->execute([
            '--file' => $specPath,
            '--directory' => $this->outputDirectory . '/out2',
            '--attributes' => 'banana',
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('must be "runtime" or "symfony"', $tester->getDisplay());
    }
}
