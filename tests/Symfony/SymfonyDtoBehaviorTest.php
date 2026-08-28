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
        $this->assertSame($statusClass::from(1), $holder->getStatus());

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
        $this->assertStringContainsString('private readonly DateTimeImmutable $at', $content);

        require_once $this->outputDirectory . '/Event.php';
        $eventClass = $ns . '\Event';
        $serializer = $this->serializer();

        $event = $serializer->denormalize(['at' => '2026-01-02T03:04:05+00:00'], $eventClass);
        $this->assertInstanceOf(DateTimeImmutable::class, $event->getAtAsDateTime());
        $this->assertSame('2026-01-02T03:04:05+00:00', $event->getAt());
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

        $this->assertStringContainsString('private ?int $level = 5;', $content);
        $this->assertStringContainsString('= Status::On;', $content);
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
        $this->assertStringContainsString('private readonly string $id', $content);
        $this->assertStringContainsString('private ?string $extra', $content);

        require_once $this->outputDirectory . '/Child.php';
        $childClass = $ns . '\Child';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // Inherited constraint (minLength on id) is enforced on the flattened child. Optional
        // properties are set, not passed: only required ones are constructor arguments.
        $withExtra = new $childClass(id: 'ok');
        $withExtra->setExtra('x');
        $this->assertCount(0, $validator->validate($withExtra));
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

    /**
     * A map whose VALUES are containers gets no `#[Assert\All]` of its own — one mistake, one message.
     *
     * `valueConstraintExpressions()` has nothing to say about a container value but "it is an array",
     * and the emitted interpreter already asserts that from `additionalProperties.type`. With both, a
     * map of lists given `{"a":"nope"}` came back twice: `field "byKey".a must be of type array` from
     * the interpreter and `This value should be of type array.` from the attribute. The LIST spelling
     * of the same schema never emitted an attribute, so the two spellings disagreed as well.
     *
     * The nested values are still checked — by the interpreter, which is the one voice this package
     * uses for what a framework constraint cannot express.
     */
    public function testAMapOfContainersReportsAnItemShapeOnce(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'MapOfLists' => [
                        'type' => 'object',
                        'properties' => [
                            'byKey' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer', 'minimum' => 5],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymMapOfLists';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $file = $this->outputDirectory . '/MapOfLists.php';
        require_once $file;

        $this->assertStringNotContainsString(
            "#[Assert\\All([new Assert\\Type('array')])]",
            (string)file_get_contents($file),
            'the interpreter owns the item shape here; a native duplicate reports it twice',
        );

        /** @var class-string $fqcn */
        $fqcn = $ns . '\MapOfLists';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $wrongShape = new $fqcn();
        $wrongShape->setByKey(['a' => 'nope']);
        $messages = [];
        foreach ($validator->validate($wrongShape) as $violation) {
            $messages[] = (string)$violation->getMessage();
        }
        $this->assertCount(1, $messages, (string)json_encode($messages));
        $this->assertStringContainsString('byKey".a must be of type array', $messages[0]);

        // And the value INSIDE the nested list is still reached.
        $badInner = new $fqcn();
        $badInner->setByKey(['a' => [1]]);
        $innerMessages = [];
        foreach ($validator->validate($badInner) as $violation) {
            $innerMessages[] = (string)$violation->getMessage();
        }
        $this->assertCount(1, $innerMessages, (string)json_encode($innerMessages));
        $this->assertStringContainsString('byKey".a[0] must be greater than or equal to 5', $innerMessages[0]);
    }

    /**
     * A bound on the ITEMS of a container is enforced once, by the attribute.
     *
     * `filterSymfonyValidationConstraints()` says it removes "supported scalar / count / regex
     * constraints … so the callback does not duplicate attribute-based violations" — and then its
     * `items` recursion handed the callback every scalar keyword anyway, while `#[Assert\All]` was
     * enforcing them too. Measured before this: `{"scoresByKey":{"a":"x"}}` produced THREE messages
     * for one mistake and every bound produced two.
     *
     * What the callback KEEPS is what no attribute asserts: `type` for a list (the list branch emits
     * the scalar specs alone), and everything below the first level, because `Assert\All` does not
     * nest.
     */
    public function testAnItemBoundIsReportedOnce(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'ItemBounds' => [
                        'type' => 'object',
                        'properties' => [
                            'scores' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 5]],
                            'scoresByKey' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'integer', 'minimum' => 5],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymItemBounds';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $file = $this->outputDirectory . '/ItemBounds.php';
        require_once $file;
        $source = (string)file_get_contents($file);

        // The attribute owns the bound; the callback literal must not repeat it.
        $this->assertStringContainsString('new Assert\Range(min: 5)', $source);
        $this->assertStringNotContainsString("'minimum' => 5", $source);
        // `type` for a LIST item stays with the callback — the list branch emits no Assert\Type, and
        // the callback accepts the integral float (42.0) that Assert\Type would refuse.
        $this->assertStringContainsString("'type' => 'integer'", $source);

        /** @var class-string $fqcn */
        $fqcn = $ns . '\ItemBounds';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        foreach (['setScores' => [1], 'setScoresByKey' => ['a' => 1]] as $setter => $value) {
            $dto = new $fqcn();
            $dto->{$setter}($value);
            $messages = [];
            foreach ($validator->validate($dto) as $violation) {
                $messages[] = (string)$violation->getMessage();
            }
            $this->assertCount(1, $messages, $setter . ': ' . (string)json_encode($messages));
        }
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
        $this->assertStringContainsString('private readonly float $ratio', $content);
        $this->assertStringContainsString('private readonly bool $active', $content);

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
        $this->assertStringContainsString('private ?UploadedFile $file', $content);
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
        // No `Assert\Type` for a SCALAR map value, and that is the point: it would refuse `42.0`,
        // which JSON Schema calls an integer. `type` is left to the callback, exactly as the LIST
        // spelling of the same schema has always left it.
        $this->assertStringContainsString('#[Assert\All([new Assert\Range(min: 0)])]', $content);
        $this->assertStringNotContainsString("new Assert\\Type('int')", $content);

        require_once $this->outputDirectory . '/Counters.php';
        $cls = $ns . '\Counters';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate(new $cls(counts: ['a' => 1, 'b' => 2])));
        // minProperties: empty map fails Count.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: []))));
        // additionalProperties value constraint: negative value fails Range inside All.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: ['a' => -1]))));
        // additionalProperties value type: a string value is still refused — by the callback now,
        // which is also what lets an integral float through where `Assert\Type` would not.
        $this->assertGreaterThan(0, count($validator->validate(new $cls(counts: ['a' => 'x']))));
        $this->assertCount(
            0,
            $validator->validate(new $cls(counts: ['a' => 42.0])),
            'a zero-fraction float IS an integer (JSON Schema 2020-12 §6.1.1)',
        );
    }

    /**
     * `nullable` on a container value grants permission; it asserts nothing. Both spellings of that
     * permission — an `items` one and an `additionalProperties` one — must let the null through, and
     * neither may drag an interpreter into a class that has nothing else to check.
     */
    public function testANullableContainerValueIsAllowedInBothSpellings(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Holes' => [
                        'type' => 'object',
                        'required' => ['items', 'values', 'bare'],
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'nullable' => true],
                            ],
                            'values' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string', 'nullable' => true],
                            ],
                            // Permission with nothing beside it. `type` above legitimately keeps the
                            // callback busy; here there is nothing left to assert, and a subschema
                            // holding only permissions must not survive the filter.
                            'bare' => [
                                'type' => 'array',
                                'items' => ['nullable' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ns = 'SymNullableValues';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $ns, 'symfony');
        $content = (string)file_get_contents($this->outputDirectory . '/Holes.php');
        $this->assertStringContainsString("'bare' => [],", $content);

        require_once $this->outputDirectory . '/Holes.php';
        $cls = $ns . '\Holes';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        // The null the document allows goes through in BOTH spellings. Before 2.15.7 the map one was
        // refused: only `items` was consulted for the permission, so `additionalProperties` never saw it.
        $this->assertCount(0, $validator->validate(new $cls(items: [null], values: ['a' => null], bare: [null])));
        $this->assertCount(0, $validator->validate(new $cls(items: ['ok'], values: ['a' => 'ok'], bare: [1])));
        // Permission is not a licence: a wrong non-null type is still refused, once, in both spellings.
        $this->assertCount(1, $validator->validate(new $cls(items: [7], values: ['a' => 'ok'], bare: [])));
        $this->assertCount(1, $validator->validate(new $cls(items: ['ok'], values: ['a' => 7], bare: [])));
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

        // Required $b is a constructor parameter; optional $a is a property with a setter, so the
        // two live in different parts of the class rather than in one argument list.
        $this->assertStringContainsString('private readonly string $b,', $content);
        $this->assertStringContainsString('private ?string $a = null;', $content);
        $this->assertStringNotContainsString('string $a = null,', $content);

        require_once $this->outputDirectory . '/Order.php';
        $cls = $ns . '\Order';

        // Construction by the single required arg must work (no ArgumentCountError).
        $object = new $cls(b: 'x');
        $this->assertSame('x', $object->getB());
        $this->assertNull($object->getA());
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
        $this->assertInstanceOf(DateTimeImmutable::class, $object->getOnAsDateTime());
        $this->assertSame('2026-03-04', $object->getOn());
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
        $this->assertSame('x', $object->getClass());
        $this->assertSame('y', $object->getFooBar());
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

        // The message lists ATTRIBUTE_MODES rather than spelling two of them out: it used to say
        // "runtime" or "symfony" long after laravel mode shipped, so a rejected mode name was told the
        // wrong set of alternatives.
        $display = $tester->getDisplay();
        foreach (GenerateDtoCommand::ATTRIBUTE_MODES as $mode) {
            $this->assertStringContainsString('"' . $mode . '"', $display);
        }
    }

    /**
     * The distinction a PATCH endpoint lives on: an optional property is `?T = null`, so its value
     * alone cannot say whether the client sent the key. The generated setter records it, which is
     * why the optional half of a Symfony DTO is not readonly.
     */
    public function testOptionalPropertiesReportWhetherThePayloadCarriedThem(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Patch' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'nickname' => ['type' => ['string', 'null']],
                            'first_name' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymPresence';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        require_once $this->outputDirectory . '/Patch.php';

        // #[Ignore] is attribute metadata, so the flags only stay out of the output with a
        // metadata-aware normalizer — the wiring README.symfony.md already requires.
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer(
            [new ObjectNormalizer($classMetadataFactory), new ArrayDenormalizer()],
        );
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Patch';

        $dto = $serializer->denormalize(['id' => 1, 'nickname' => null], $fqcn);

        // Both are null; only one of them was sent.
        $this->assertNull($dto->getNickname());
        $this->assertNull($dto->getFirstName());
        $this->assertTrue($dto->isNicknameProvided());
        $this->assertFalse($dto->isFirstNameProvided());

        // A DTO built by hand answers the same way, and the flags stay out of the output.
        $byHand = new $fqcn(id: 2);
        $this->assertFalse($byHand->isNicknameProvided());
        $byHand->setNickname('nick');
        $this->assertTrue($byHand->isNicknameProvided());
        $this->assertArrayNotHasKey('nicknameProvided', $serializer->normalize($byHand));
    }

    /**
     * Symfony's DateTimeNormalizer has one fixed pattern: it would turn `format: date` into a full
     * timestamp and drop the sub-second precision of a date-time. The generated getter formats the
     * value itself instead — the same rule runtime mode uses — and an #[Ignore]d companion still
     * hands out the object.
     */
    public function testTemporalGettersFormatAsTheSchemaDeclares(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Stamp' => [
                        'type' => 'object',
                        'required' => ['at', 'on'],
                        'properties' => [
                            'at' => ['type' => 'string', 'format' => 'date-time'],
                            'on' => ['type' => 'string', 'format' => 'date'],
                        ],
                    ],
                ],
            ],
        ];

        $namespace = 'SymTemporalFormat';
        $this->generator->generateFromArray($spec, $this->outputDirectory, $namespace, 'symfony');
        require_once $this->outputDirectory . '/Stamp.php';

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer(
            [new DateTimeNormalizer(), new ObjectNormalizer($classMetadataFactory), new ArrayDenormalizer()],
        );
        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Stamp';

        $withMicroseconds = $serializer->denormalize(
            ['at' => '2026-03-10T12:00:00.123456+03:00', 'on' => '2026-03-10'],
            $fqcn,
        );
        $this->assertSame('2026-03-10T12:00:00.123456+03:00', $withMicroseconds->getAt());
        $this->assertSame('2026-03-10', $withMicroseconds->getOn(), 'a date must not grow a time part');

        $withoutMicroseconds = $serializer->denormalize(
            ['at' => '2026-03-10T12:00:00+00:00', 'on' => '2026-03-10'],
            $fqcn,
        );
        $this->assertSame('2026-03-10T12:00:00+00:00', $withoutMicroseconds->getAt());

        // The object is still reachable, and stays out of the serialized output.
        $this->assertInstanceOf(DateTimeImmutable::class, $withMicroseconds->getAtAsDateTime());
        $this->assertSame(
            ['at' => '2026-03-10T12:00:00.123456+03:00', 'on' => '2026-03-10'],
            $serializer->normalize($withMicroseconds),
        );
    }
}
