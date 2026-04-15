<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateDtoCommandTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDirectory)) {
            $this->deleteDirectory($this->outputDirectory);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testPathAndQueryParameters(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $count = $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $this->assertGreaterThan(0, $count);

        // Check that query params DTO was generated
        $queryParamsFile = $this->outputDirectory . '/UsersPostsGetQueryParams.php';
        $this->assertFileExists($queryParamsFile);

        $content = file_get_contents($queryParamsFile);
        $this->assertStringContainsString('class UsersPostsGetQueryParams', $content);
        $this->assertStringContainsString('implements GeneratedDtoInterface', $content);
        $this->assertStringContainsString('use OpenapiPhpDtoGenerator\\Contract\\GeneratedDtoInterface;', $content);
        $this->assertStringContainsString('private readonly int $userId', $content);
        $this->assertStringContainsString('private readonly string $postId', $content);
        $this->assertStringContainsString('private readonly ?int $page', $content);
        $this->assertStringContainsString('private readonly int|null|UnsetValue $limit', $content);
        $this->assertStringContainsString('public function getUserId(): int', $content);
        $this->assertStringContainsString('public function getPostId(): string', $content);
        $this->assertStringContainsString('public function getPage(): ?int', $content);
        $this->assertStringContainsString('public function getLimit(): ?int', $content);
        $this->assertStringContainsString('public function isUserIdInPath(): bool', $content);
        $this->assertStringContainsString('public function isPostIdInPath(): bool', $content);
        $this->assertStringContainsString('public function isPageInQuery(): bool', $content);
        $this->assertStringContainsString('public function isLimitInQuery(): bool', $content);
        $this->assertStringContainsString('return $this->userIdInPath;', $content);
        $this->assertStringContainsString('return $this->pageInQuery;', $content);
        $this->assertStringContainsString('$this->userIdInPath = true;', $content);
        $this->assertStringContainsString('$this->postIdInPath = true;', $content);
        $this->assertStringContainsString('$this->pageInQuery = true;', $content);
        $this->assertStringContainsString('$this->limitInQuery = $limit !== UnsetValue::UNSET;', $content);
        $this->assertStringNotContainsString('$this->limitInRequest = $limit !== UnsetValue::UNSET;', $content);
        $this->assertStringContainsString(
            'if ($this->limitInRequest || $this->limitInPath || $this->limitInQuery) {',
            $content,
        );
    }

    public function testRequestBodyPostGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check POST request DTO
        $postRequestFile = $this->outputDirectory . '/ArticlesPostRequest.php';
        $this->assertFileExists($postRequestFile);

        $content = file_get_contents($postRequestFile);
        $this->assertStringContainsString('class ArticlesPostRequest', $content);
        $this->assertStringContainsString('private readonly string $title', $content);
        $this->assertStringContainsString('private readonly string|null|UnsetValue $content', $content);
    }

    public function testRequestBodyPatchGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check PATCH request DTO
        $patchRequestFile = $this->outputDirectory . '/ArticlesPatchRequest.php';
        $this->assertFileExists($patchRequestFile);

        $content = file_get_contents($patchRequestFile);
        $this->assertStringContainsString('class ArticlesPatchRequest', $content);
        $this->assertStringContainsString('private readonly string|null|UnsetValue $title', $content);
        $this->assertStringContainsString('private readonly bool|null|UnsetValue $published', $content);
    }

    public function testConstructorDocblockKeepsGenericArrayParamAndOmitsRedundantParams(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Docblock test',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'User' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => ['type' => 'string'],
                        ],
                    ],
                    'DocblockExample' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'tags'],
                        'properties' => [
                            'id' => [
                                'type' => 'integer',
                                'description' => 'this is id',
                                'default' => 1,
                            ],
                            'name' => [
                                'type' => 'string',
                                'description' => 'this is name',
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                            ],
                            'user' => [
                                'nullable' => true,
                                'allOf' => [
                                    ['$ref' => '#/components/schemas/User'],
                                ],
                            ],
                            'types_with_ids' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/DocblockExample.php';
        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString('@param int $id this is id', $content);
        $this->assertStringContainsString('@param string $name this is name', $content);
        $this->assertStringContainsString('@param array<Tag> $tags', $content);
        $this->assertStringNotContainsString('@param ?User $user', $content);
        $this->assertStringNotContainsString('@param string $types_with_ids', $content);
    }

    public function testNullableGenericArrayUsesNullableArrayTypeHintInConstructorAndProperty(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Nullable generic array type hints',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'FilterEnumView' => [
                        'type' => 'string',
                        'enum' => ['a', 'b'],
                    ],
                    'SearchRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'availableFilters' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/FilterEnumView'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/SearchRequest.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('@param ?array<FilterEnumView> $availableFilters', $content);
        $this->assertStringContainsString('?array $availableFilters', $content);
        $this->assertStringContainsString('private ?array $availableFilters;', $content);
    }

    public function testPlainArrayPropertyIsDeclaredAndAssignedInConstructor(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Plain array property',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'PlainArrayPayload' => [
                        'type' => 'object',
                        'required' => ['payload'],
                        'properties' => [
                            'payload' => [
                                'type' => 'array',
                                'items' => new \stdClass(),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/PlainArrayPayload.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('private array $payload;', $content);
        $this->assertStringContainsString('array $payload,', $content);
        $this->assertStringContainsString('$this->payload = $payload;', $content);
    }

    public function testConstructorPlacesRequiredParamsBeforeOptionalWithDefaults(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Constructor order test',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'UserTypeEnum' => [
                        'type' => 'string',
                        'enum' => ['all', 'custom'],
                    ],
                    'OrderSensitiveDto' => [
                        'type' => 'object',
                        'required' => ['userIds'],
                        'properties' => [
                            'user' => [
                                '$ref' => '#/components/schemas/UserTypeEnum',
                                'default' => 'all',
                            ],
                            'userIds' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/OrderSensitiveDto.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertMatchesRegularExpression(
            '/public function __construct\(\s*array \$userIds,\s*private readonly \?UserTypeEnum \$user = UserTypeEnum::ALL,/s',
            $content,
        );
    }

    public function testConstructorPlacesRequiredParamsBeforeUnsetSentinelOptionalParams(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Constructor order sentinel test',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'OrderWithSentinel' => [
                        'type' => 'object',
                        'required' => ['payload'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'payload' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/OrderWithSentinel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertMatchesRegularExpression(
            '/public function __construct\(\s*array \$payload,\s*private readonly string\|null\|UnsetValue \$message = UnsetValue::UNSET,/s',
            $content,
        );
    }

    public function testConstructorContainsSentinelToNullAssignmentForOptionalArray(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Constructor sentinel assignment test',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'AssignmentCheckPayload' => [
                        'type' => 'object',
                        'required' => ['requestId'],
                        'properties' => [
                            'requestId' => ['type' => 'string'],
                            'categoryTags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/AssignmentCheckPayload.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString(
            '$this->categoryTags = $categoryTags !== UnsetValue::UNSET ? $categoryTags : null;',
            $content,
        );
    }

    /**
     * Verifies regression case for optional $ref properties: the generator must
     * keep constructor promotion with readonly + UnsetValue sentinel, and must not
     * fall back to separate property declaration/body assignment.
     */
    public function testOptionalRefUsesPromotedReadonlySentinel(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Optional ref sentinel test', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'CompanionPet' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'OwnerWithCompanion' => [
                        'type' => 'object',
                        'required' => ['ownerId'],
                        'properties' => [
                            'ownerId' => ['type' => 'integer'],
                            'age' => ['type' => 'integer'],
                            'score' => ['type' => 'number'],
                            'active' => ['type' => 'boolean'],
                            'companion' => [
                                '$ref' => '#/components/schemas/CompanionPet',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $refFile = $this->outputDirectory . '/CompanionPet.php';
        $this->assertFileExists($refFile);

        $file = $this->outputDirectory . '/OwnerWithCompanion.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        // Required field should stay regular promoted readonly without sentinel.
        $this->assertStringContainsString('private readonly int $ownerId', $content);

        // Optional $ref field should use promoted readonly sentinel pattern.
        $this->assertStringContainsString(
            'private readonly CompanionPet|null|UnsetValue $companion = UnsetValue::UNSET',
            $content,
        );

        // Optional scalar fields should also use promoted readonly sentinel pattern.
        $this->assertStringContainsString(
            'private readonly int|null|UnsetValue $age = UnsetValue::UNSET',
            $content,
        );
        $this->assertStringContainsString(
            'private readonly float|null|UnsetValue $score = UnsetValue::UNSET',
            $content,
        );
        $this->assertStringContainsString(
            'private readonly bool|null|UnsetValue $active = UnsetValue::UNSET',
            $content,
        );

        // Guard against old broken pattern without constructor promotion.
        $this->assertStringNotContainsString('private CompanionPet|null|UnsetValue $companion;', $content);
        $this->assertStringNotContainsString('private ?CompanionPet $companion;', $content);
        $this->assertStringNotContainsString('\n\t\tCompanionPet|null|UnsetValue $companion = UnsetValue::UNSET,', $content);
        $this->assertStringNotContainsString('$this->companion = $companion', $content);
        $this->assertStringNotContainsString('$this->age = $age', $content);
        $this->assertStringNotContainsString('$this->score = $score', $content);
        $this->assertStringNotContainsString('$this->active = $active', $content);

        // inRequest flag must be tracked via sentinel comparison.
        $this->assertStringContainsString(
            '$this->companionInRequest = $companion !== UnsetValue::UNSET',
            $content,
        );
        $this->assertStringContainsString('$this->ageInRequest = $age !== UnsetValue::UNSET', $content);
        $this->assertStringContainsString('$this->scoreInRequest = $score !== UnsetValue::UNSET', $content);
        $this->assertStringContainsString('$this->activeInRequest = $active !== UnsetValue::UNSET', $content);

        // Getter must normalize UnsetValue::UNSET to null for optional fields.
        $this->assertStringContainsString(
            'return $this->companion !== UnsetValue::UNSET ? $this->companion : null;',
            $content,
        );
        $this->assertStringContainsString('return $this->age !== UnsetValue::UNSET ? $this->age : null;', $content);
        $this->assertStringContainsString('return $this->score !== UnsetValue::UNSET ? $this->score : null;', $content);
        $this->assertStringContainsString('return $this->active !== UnsetValue::UNSET ? $this->active : null;', $content);
    }

    public function testInlineResponseSchemaGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check inline response schema DTO
        $responseFile = $this->outputDirectory . '/Status200.php';
        $this->assertFileExists($responseFile);

        $content = file_get_contents($responseFile);
        $this->assertStringContainsString('class Status200', $content);
        $this->assertStringContainsString('private readonly string|null|UnsetValue $status', $content);
        $this->assertStringContainsString('private readonly int|null|UnsetValue $timestamp', $content);
    }

    public function testDescriptionSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $postFile = $this->outputDirectory . '/Post.php';
        $this->assertFileExists($postFile);

        $content = file_get_contents($postFile);
        $this->assertStringContainsString('Unique identifier for the post', $content);
        $this->assertStringContainsString('Title of the post', $content);
        $this->assertStringContainsString('ID of the author who created the post', $content);
    }

    public function testDocCommentsAreGeneratedForDescriptionOnlyExampleOnlyAndCombined(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Description and example docs',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'DocCommentModel' => [
                        'type' => 'object',
                        'required' => ['textOnly', 'sampleOnly', 'textAndSample'],
                        'properties' => [
                            'textOnly' => [
                                'type' => 'string',
                                'description' => 'Text only field',
                            ],
                            'sampleOnly' => [
                                'type' => 'integer',
                                'example' => 42,
                            ],
                            'textAndSample' => [
                                'type' => 'string',
                                'description' => 'Text with sample',
                                'example' => 'demo-value',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/DocCommentModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        // description only
        $this->assertStringContainsString('@param string $textOnly Text only field', $content);

        // example only
        $this->assertStringContainsString('@param int $sampleOnly Example: 42', $content);
        $this->assertStringContainsString('Example: 42', $content);

        // description + example
        $this->assertStringContainsString('@param string $textAndSample Text with sample Example: demo-value', $content);
        $this->assertStringContainsString('Example: demo-value', $content);
    }

    public function testArrayPropertyDocblockKeepsVarTypeWhenOnlyExampleExists(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Array property var doc test',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'PayloadExampleModel' => [
                        'type' => 'object',
                        'required' => ['success', 'payload'],
                        'properties' => [
                            'success' => ['type' => 'boolean'],
                            'payload' => [
                                'type' => 'array',
                                'items' => new \stdClass(),
                                'example' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/PayloadExampleModel.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString('* Example: []', $content);
        $this->assertStringContainsString('* @var array', $content);
        $this->assertStringContainsString('@param array $payload Example: []', $content);
    }

    public function testGenericArrayDocblockScenariosForDescriptionAndExample(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Generic array docblock matrix',
                'version' => '1.0.0',
            ],
            'components' => [
                'schemas' => [
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['label'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                        ],
                    ],
                    'GenericArrayDocMatrix' => [
                        'type' => 'object',
                        'required' => ['plainTags', 'descriptionTags', 'exampleTags', 'fullTags'],
                        'properties' => [
                            'plainTags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                            ],
                            'descriptionTags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                                'description' => 'Description only tags',
                            ],
                            'exampleTags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                                'example' => [['label' => 'demo']],
                            ],
                            'fullTags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                                'description' => 'Description and example tags',
                                'example' => [['label' => 'demo']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/GenericArrayDocMatrix.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        // 1) no description and no example
        $this->assertStringContainsString('@param array<Tag> $plainTags', $content);

        // 2) description only
        $this->assertStringContainsString('@param array<Tag> $descriptionTags Description only tags', $content);

        // 3) example only
        $this->assertStringContainsString('@param array<Tag> $exampleTags Example: [{"label":"demo"}]', $content);

        // 4) description + example
        $this->assertStringContainsString(
            '@param array<Tag> $fullTags Description and example tags Example: [{"label":"demo"}]',
            $content,
        );

        // Ensure property docblocks keep generic @var type in all scenarios
        $this->assertSame(4, substr_count($content, '@var array<Tag>'));
    }

    public function testDefaultValuesSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $settingsFile = $this->outputDirectory . '/PostSettings.php';
        $this->assertFileExists($settingsFile);

        $content = file_get_contents($settingsFile);
        $this->assertStringContainsString('= true', $content); // commentsEnabled default
        $this->assertStringContainsString('= 100', $content); // maxComments default
    }

    public function testEnumGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // String enum
        $statusEnumFile = $this->outputDirectory . '/PostStatus.php';
        $this->assertFileExists($statusEnumFile);

        $content = file_get_contents($statusEnumFile);
        $this->assertStringContainsString('enum PostStatus: string', $content);
        $this->assertStringContainsString('implements GeneratedDtoInterface', $content);
        $this->assertStringContainsString('use OpenapiPhpDtoGenerator\\Contract\\GeneratedDtoInterface;', $content);
        $this->assertStringNotContainsString('public function __toString(): string', $content);
        $this->assertStringContainsString("case DRAFT = 'draft'", $content);
        $this->assertStringContainsString("case PUBLISHED = 'published'", $content);
        $this->assertStringContainsString("case ARCHIVED = 'archived'", $content);
        $this->assertStringContainsString('public function toArray(): array', $content);
        $this->assertStringContainsString('public function toJson(): string', $content);
        $this->assertStringContainsString('public static function getAliases(): array', $content);
        $this->assertStringContainsString('public static function getConstraints(): array', $content);

        // Integer enum
        $typeEnumFile = $this->outputDirectory . '/ArticleType.php';
        $this->assertFileExists($typeEnumFile);

        $typeContent = file_get_contents($typeEnumFile);
        $this->assertStringContainsString('enum ArticleType: int', $typeContent);
    }

    public function testNestedSchemaGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check nested metadata schema
        $metadataFile = $this->outputDirectory . '/ArticleMetadata.php';
        $this->assertFileExists($metadataFile);

        $content = file_get_contents($metadataFile);
        $this->assertStringContainsString('class ArticleMetadata', $content);
        $this->assertStringContainsString('private readonly string|null|UnsetValue $createdAt', $content);
        $this->assertStringContainsString('private readonly string|null|UnsetValue $updatedAt', $content);

        // Check array helper on Article.tags
        $articleFile = $this->outputDirectory . '/Article.php';
        $this->assertFileExists($articleFile);
        $articleContent = file_get_contents($articleFile);
        $this->assertStringContainsString(
            'public function addItemToTags(ArticleTagsItem $item): void',
            $articleContent,
        );
    }

    public function testNestedEnumGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check nested enum in Comment
        $commentStatusFile = $this->outputDirectory . '/CommentStatus.php';
        $this->assertFileExists($commentStatusFile);

        $content = file_get_contents($commentStatusFile);
        $this->assertStringContainsString('enum CommentStatus: string', $content);
        $this->assertStringContainsString("case PENDING = 'pending'", $content);
        $this->assertStringContainsString("case APPROVED = 'approved'", $content);
        $this->assertStringContainsString("case REJECTED = 'rejected'", $content);

        // Check nested enum in array items
        $tagsEnumFile = $this->outputDirectory . '/ArticleTagsItem.php';
        $this->assertFileExists($tagsEnumFile);

        $tagsContent = file_get_contents($tagsEnumFile);
        $this->assertStringContainsString('enum ArticleTagsItem: string', $tagsContent);
    }

    public function testAllOfWithInheritance(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check base entity
        $baseFile = $this->outputDirectory . '/BaseEntity.php';
        $this->assertFileExists($baseFile);
        $baseContent = file_get_contents($baseFile);
        $this->assertStringContainsString('class BaseEntity', $baseContent);
        $this->assertStringNotContainsString('final class', $baseContent);

        // Check extended entity
        $extendedFile = $this->outputDirectory . '/ExtendedEntity.php';
        $this->assertFileExists($extendedFile);

        $content = file_get_contents($extendedFile);
        $this->assertStringContainsString('extends BaseEntity', $content);
        $this->assertStringContainsString('private readonly string $updatedAt', $content);
        $this->assertStringContainsString('string $name,', $content);
        $this->assertStringNotContainsString('private readonly string $name,', $content);
        $this->assertStringContainsString('parent::__construct', $content);
    }

    public function testOneOfGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $petFile = $this->outputDirectory . '/Pet.php';
        $this->assertFileExists($petFile);

        $content = file_get_contents($petFile);
        $this->assertStringContainsString('interface Pet', $content);
        $this->assertStringContainsString('Members: Dog|Cat|PetOneOf3', $content);

        // Check inline oneOf variant
        $variantFile = $this->outputDirectory . '/PetOneOf3.php';
        $this->assertFileExists($variantFile);
    }

    public function testAnyOfGeneration(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $notificationFile = $this->outputDirectory . '/Notification.php';
        $this->assertFileExists($notificationFile);

        $content = file_get_contents($notificationFile);
        $this->assertStringContainsString('interface Notification', $content);
        $this->assertStringContainsString('Members: EmailNotification|SmsNotification|NotificationAnyOf3', $content);

        // Check inline anyOf variant
        $variantFile = $this->outputDirectory . '/NotificationAnyOf3.php';
        $this->assertFileExists($variantFile);
    }

    public function testDiscriminatorSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Check Animal base
        $animalFile = $this->outputDirectory . '/Animal.php';
        $this->assertFileExists($animalFile);
        $animalContent = file_get_contents($animalFile);
        $this->assertStringContainsString('class Animal', $animalContent);
        $this->assertStringNotContainsString('final class', $animalContent);
        $this->assertStringContainsString('private readonly AnimalAnimalType $animalType', $animalContent);
        $this->assertStringContainsString(
            'public static function getDiscriminatorPropertyName(): string',
            $animalContent,
        );
        $this->assertStringContainsString("return 'animalType';", $animalContent);
        $this->assertStringContainsString("'dog' => Dog::class", $animalContent);
        $this->assertStringContainsString("'cat' => Cat::class", $animalContent);

        $animalTypeEnum = $this->outputDirectory . '/AnimalAnimalType.php';
        $this->assertFileExists($animalTypeEnum);
        $animalTypeEnumContent = file_get_contents($animalTypeEnum);
        $this->assertStringContainsString("case DOG = 'dog';", $animalTypeEnumContent);
        $this->assertStringContainsString("case CAT = 'cat';", $animalTypeEnumContent);

        // Check Dog extends Animal
        $dogFile = $this->outputDirectory . '/Dog.php';
        $this->assertFileExists($dogFile);
        $dogContent = file_get_contents($dogFile);
        $this->assertStringContainsString('extends Animal', $dogContent);
        $this->assertStringContainsString('private readonly string $bark', $dogContent);

        // Check Cat extends Animal
        $catFile = $this->outputDirectory . '/Cat.php';
        $this->assertFileExists($catFile);
        $catContent = file_get_contents($catFile);
        $this->assertStringContainsString('extends Animal', $catContent);
        $this->assertStringContainsString('private readonly string $meow', $catContent);
    }

    public function testDiscriminatorDuplicateMappingTargetThrowsException(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/discriminator-duplicate-mapping.yaml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate target "DogAnimal"');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');
    }

    public function testInheritedEnumOverrideSubsetReusesParentEnumType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/discriminator-enum-override-subset.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $parentFile = $this->outputDirectory . '/Test1.php';
        $this->assertFileExists($parentFile);
        $parentContent = file_get_contents($parentFile);
        $this->assertStringContainsString('private readonly Test1TestField $testField', $parentContent);

        $childFile = $this->outputDirectory . '/Test5.php';
        $this->assertFileExists($childFile);
        $childContent = file_get_contents($childFile);
        $this->assertStringContainsString('extends Test1', $childContent);
        $this->assertStringContainsString('Test1TestField $testField', $childContent);
        $this->assertStringNotContainsString('Test5TestField $testField', $childContent);

        $this->assertFileExists($this->outputDirectory . '/Test1TestField.php');
        $this->assertFileDoesNotExist($this->outputDirectory . '/Test5TestField.php');
    }

    public function testGeneratesOpenApiConstraintsMetadata(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/constraints.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/ConstraintSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('public static function getConstraints(): array', $content);
        $this->assertStringContainsString('Constraints: minLength=3, format=email', $content);
        $this->assertStringContainsString("'format' => 'email'", $content);
        $this->assertStringContainsString("'minimum' => 1", $content);
        $this->assertStringContainsString("'exclusiveMinimum' => true", $content);
        $this->assertStringContainsString("'multipleOf' => 2.5", $content);
        $this->assertStringContainsString("'uniqueItems' => true", $content);
    }

    public function testGeneratesUnionTypeForPropertyOneOf(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/union-type.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/UnionSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string|int $id', $content);
        $this->assertStringContainsString(
            'Constraints: oneOf=(type=string, format=uuid) | (type=integer, minimum=10, maximum=100)',
            $content,
        );
        $this->assertStringContainsString('public function __construct(', $content);
        $this->assertStringContainsString('string|int $id,', $content);
        $this->assertStringContainsString('public function getId(): string|int', $content);
    }

    public function testGeneratesExternalRefSchemasIntoSubdirectoryAndImportsThem(): void
    {
        $unique = uniqid('openapi_ext_ref_', true);
        $baseDir = sys_get_temp_dir() . '/openapi_dto_generator_' . $unique;
        $outputDir = $baseDir . '/generated';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            $count = $this->generator->generateFromFile(
                __DIR__ . '/fixtures/external-ref/root.yaml',
                $outputDir,
                'TestNamespace',
            );

            $this->assertGreaterThanOrEqual(2, $count);

            $externalFile = $baseDir . '/common/Test.php';
            $this->assertFileExists($externalFile);
            $externalContent = file_get_contents($externalFile);
            $this->assertStringContainsString('namespace TestNamespace\\Common;', $externalContent);
            $this->assertStringContainsString('class Test', $externalContent);

            $localFile = $outputDir . '/LocalResponse.php';
            $this->assertFileExists($localFile);
            $localContent = file_get_contents($localFile);
            $this->assertStringContainsString('namespace TestNamespace;', $localContent);
            $this->assertStringContainsString('use TestNamespace\\Common\\Test;', $localContent);
            $this->assertStringContainsString('private readonly Test $test', $localContent);
        } finally {
            if (is_dir($baseDir)) {
                $this->deleteDirectory($baseDir);
            }
        }
    }

    public function testGeneratesExternalRefSchemasForNestedRelativePath(): void
    {
        $unique = uniqid('openapi_ext_ref_nested_', true);
        $baseDir = sys_get_temp_dir() . '/openapi_dto_generator_' . $unique;
        $outputDir = $baseDir . '/generated';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            $count = $this->generator->generateFromFile(
                __DIR__ . '/fixtures/external-ref/root-nested.yaml',
                $outputDir,
                'TestNamespace',
            );

            $this->assertGreaterThanOrEqual(2, $count);

            $externalFile = $baseDir . '/test/common/TestCommonResponse.php';
            $this->assertFileExists($externalFile);
            $externalContent = file_get_contents($externalFile);
            $this->assertStringContainsString('namespace TestNamespace\\Test\\Common;', $externalContent);
            $this->assertStringContainsString('class TestCommonResponse', $externalContent);

            $localFile = $outputDir . '/LocalNestedResponse.php';
            $this->assertFileExists($localFile);
            $localContent = file_get_contents($localFile);
            $this->assertStringContainsString('use TestNamespace\\Test\\Common\\TestCommonResponse;', $localContent);
            $this->assertStringContainsString('private readonly TestCommonResponse $response', $localContent);
        } finally {
            if (is_dir($baseDir)) {
                $this->deleteDirectory($baseDir);
            }
        }
    }

    public function testGeneratesCommonExternalRefsIntoSiblingCommonSchemasDirectory(): void
    {
        $unique = uniqid('openapi_common_model_', true);
        $baseDir = sys_get_temp_dir() . '/openapi_dto_generator_' . $unique;
        $specDir = $baseDir . '/specs';
        $commonDir = $baseDir . '/common';
        $outputDir = $baseDir . '/Generated/Module/Schemas';

        mkdir($specDir, 0755, true);
        mkdir($commonDir, 0755, true);

        file_put_contents(
            $specDir . '/module.yml',
            <<<'YAML'
openapi: 3.0.0
info:
  title: Module
  version: 1.0.0
paths: { }
components:
  schemas:
    ModuleResponse:
      type: object
      required:
        - response
      properties:
        response:
          $ref: '../common/common_response.yml#/components/schemas/TestResponse'
YAML,
        );

        file_put_contents(
            $commonDir . '/common_response.yml',
            <<<'YAML'
openapi: 3.0.0
info:
  title: TestResponse
  version: 1.0.0
paths: { }
components:
  schemas:
    TestResponse:
      type: object
      required:
        - success
      properties:
        success:
          type: boolean
YAML,
        );

        try {
            $count = $this->generator->generateFromFile(
                $specDir . '/module.yml',
                $outputDir,
                'TestNamespace\\Module\\Schemas',
            );

            $this->assertGreaterThanOrEqual(2, $count);

            $externalFile = $baseDir . '/Generated/Common/Schemas/TestResponse.php';
            $this->assertFileExists($externalFile);
            $externalContent = file_get_contents($externalFile);
            $this->assertStringContainsString('namespace TestNamespace\\Common\\Schemas;', $externalContent);

            $localFile = $outputDir . '/ModuleResponse.php';
            $this->assertFileExists($localFile);
            $localContent = file_get_contents($localFile);
            $this->assertStringContainsString('namespace TestNamespace\\Module\\Schemas;', $localContent);
            $this->assertStringContainsString('use TestNamespace\\Common\\Schemas\\TestResponse;', $localContent);
            $this->assertStringContainsString('private readonly TestResponse $response', $localContent);
        } finally {
            if (is_dir($baseDir)) {
                $this->deleteDirectory($baseDir);
            }
        }
    }

    public function testExternalCommonSchemaAliasDoesNotGenerateLocalDuplicate(): void
    {
        $unique = uniqid('openapi_common_alias_', true);
        $baseDir = sys_get_temp_dir() . '/openapi_dto_generator_' . $unique;
        $specDir = $baseDir . '/specs';
        $commonDir = $baseDir . '/common';
        $outputDir = $baseDir . '/Generated/Module/Schemas';

        mkdir($specDir, 0755, true);
        mkdir($commonDir, 0755, true);

        file_put_contents(
            $specDir . '/module.yml',
            <<<'YAML'
openapi: 3.0.0
info:
  title: Module
  version: 1.0.0
paths: { }
components:
  schemas:
    TestResponse:
      $ref: '../common/common_response.yml#/components/schemas/TestResponse'
    ModuleResponse:
      type: object
      required:
        - response
      properties:
        response:
          $ref: '#/components/schemas/TestResponse'
YAML,
        );

        file_put_contents(
            $commonDir . '/common_response.yml',
            <<<'YAML'
openapi: 3.0.0
info:
  title: TestResponse
  version: 1.0.0
paths: { }
components:
  schemas:
    TestResponse:
      type: object
      required:
        - success
      properties:
        success:
          type: boolean
YAML,
        );

        try {
            $count = $this->generator->generateFromFile(
                $specDir . '/module.yml',
                $outputDir,
                'TestNamespace\\Module\\Schemas',
            );

            $this->assertGreaterThanOrEqual(2, $count);

            $externalFile = $baseDir . '/Generated/Common/Schemas/TestResponse.php';
            $this->assertFileExists($externalFile);

            $localDuplicate = $outputDir . '/TestResponse.php';
            $this->assertFileDoesNotExist($localDuplicate);

            $localFile = $outputDir . '/ModuleResponse.php';
            $this->assertFileExists($localFile);
            $localContent = file_get_contents($localFile);
            $this->assertStringContainsString('use TestNamespace\\Common\\Schemas\\TestResponse;', $localContent);
            $this->assertStringContainsString('private readonly TestResponse $response', $localContent);
        } finally {
            if (is_dir($baseDir)) {
                $this->deleteDirectory($baseDir);
            }
        }
    }

    public function testAllOfLastTypeWinsForProperty(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/allof-last-type-wins.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/AllOfSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string $value', $content);
        $this->assertStringNotContainsString('private readonly int $value', $content);
    }

    public function testQueryParametersWithDefaults(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $queryParamsFile = $this->outputDirectory . '/UsersPostsGetQueryParams.php';
        $this->assertFileExists($queryParamsFile);

        $content = file_get_contents($queryParamsFile);
        // Page has default value of 1
        $this->assertStringContainsString('= 1', $content);
    }

    public function testGeneratedFilesCount(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $count = $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Should generate many DTOs, enums, and parameter classes
        $this->assertGreaterThan(20, $count);
    }

    public function testNamespaceIsCorrect(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'My\\Custom\\Namespace');

        $postFile = $this->outputDirectory . '/Post.php';
        $this->assertFileExists($postFile);

        $content = file_get_contents($postFile);
        $this->assertStringContainsString('namespace My\\Custom\\Namespace;', $content);
    }

    public function testOutputDirectoryIsCleanedBeforeGeneration(): void
    {
        // Create a dummy file
        file_put_contents($this->outputDirectory . '/dummy.txt', 'test');
        $this->assertFileExists($this->outputDirectory . '/dummy.txt');

        // Generate DTOs
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/test-all-features.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Dummy file should be removed
        $this->assertFileDoesNotExist($this->outputDirectory . '/dummy.txt');
    }

    public function testPathParametersAreAlwaysRequiredAndQueryRequiredSupportsStringFlags(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/path-query-required-coercion.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $queryParamsFile = $this->outputDirectory . '/ApiTestCatsActionsGetQueryParams.php';
        $this->assertFileExists($queryParamsFile);

        $content = file_get_contents($queryParamsFile);
        self::assertIsString($content);

        // Path params must always stay non-nullable.
        $this->assertStringContainsString('private readonly string $id', $content);
        $this->assertStringContainsString('private readonly int $actionId', $content);
        $this->assertStringNotContainsString('private readonly ?string $id', $content);

        // Query required flags from malformed specs still map as required/non-nullable.
        $this->assertStringContainsString('private readonly int $page', $content);
        $this->assertStringContainsString('private readonly int|null|UnsetValue $limit', $content);
        $this->assertStringContainsString('public function isPageInQuery(): bool', $content);
        $this->assertStringContainsString('public function isLimitInQuery(): bool', $content);
        $this->assertStringContainsString('return $this->pageInQuery;', $content);
        $this->assertStringContainsString('return $this->limitInQuery;', $content);
        $this->assertStringNotContainsString("Field \"limit\" wasn\\'t provided in request", $content);
    }

    public function testHyphenatedOpenApiNameKeepsAliasAndRequiredMapping(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Hyphenated fields',
                'version' => '1.0.0',
            ],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'HyphenFieldModel' => [
                        'type' => 'object',
                        'required' => ['test-process'],
                        'properties' => [
                            'test-process' => ['type' => 'string'],
                            'processed' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/HyphenFieldModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // PHP property remains valid identifier while alias keeps original OpenAPI key.
        $this->assertStringContainsString('private readonly string $testProcess', $content);
        $this->assertStringContainsString('$aliases[\'testProcess\'] = \'test-process\';', $content);
        $this->assertStringContainsString('public function isTestProcessRequired(): bool', $content);
        $this->assertStringContainsString('return true;', $content);

        // Getter should not be guarded by inRequest flag.
        $this->assertStringNotContainsString('if (!$this->processedInRequest) {', $content);
        $this->assertStringNotContainsString("Field \"processed\" wasn\\'t provided in request", $content);
    }

    public function testPlaceholderStyleOpenApiNameKeepsAliasAndRequiredMapping(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Placeholder fields',
                'version' => '1.0.0',
            ],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'PlaceholderFieldModel' => [
                        'type' => 'object',
                        'required' => ['<<test name>>'],
                        'properties' => [
                            '<<test name>>' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/PlaceholderFieldModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string $testName', $content);
        $this->assertStringContainsString('$aliases[\'testName\'] = \'<<test name>>\';', $content);
        $this->assertStringContainsString('public function isTestNameRequired(): bool', $content);
    }

    public function testUppercaseUnderscoreNameUsesCamelCaseInRequestFlagAndKeepsAlias(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Uppercase keys',
                'version' => '1.0.0',
            ],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'MeetingTypesDto' => [
                        'type' => 'object',
                        'required' => ['TEST_NAME'],
                        'properties' => [
                            'TEST_NAME' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/MeetingTypesDto.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string $testName', $content);
        $this->assertStringContainsString('private bool $testNameInRequest = false;', $content);
        $this->assertStringContainsString('$aliases[\'testName\'] = \'TEST_NAME\';', $content);
        $this->assertStringContainsString('return $this->testNameInRequest;', $content);
    }

    public function testGenerationFailsWhenTwoPropertiesNormalizeToSameCamelCaseName(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Collision test', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'CollisionModel' => [
                        'type' => 'object',
                        'properties' => [
                            'test_param' => ['type' => 'string'],
                            'test-param' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property name collision in CollisionModel');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');
    }

    public function testGenerationFailsWhenQueryParameterNamesNormalizeToSameCamelCaseName(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Param collision test', 'version' => '1.0.0'],
            'paths' => [
                '/items' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'user_id',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'user-id',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
            'components' => ['schemas' => []],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property name collision in ItemsGetQueryParams');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');
    }

    public function testAdditionalPropertiesMapGeneratesArrayTypeAndNoNestedClass(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/additional-properties.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/TestMapModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private array $testMap', $content);
        $this->assertStringContainsString('@var array<string>', $content);
        $this->assertStringContainsString("'type' => 'object'", $content);
        $this->assertStringContainsString('public static function getAliases(): array', $content);
        $this->assertStringContainsString('public static function getConstraints(): array', $content);
        $this->assertStringContainsString('private bool $testMapInRequest = false;', $content);
        $this->assertFileDoesNotExist($this->outputDirectory . '/TestMapModelTestMap.php');
    }

    public function testNumericPropertyKeysFromReferencedSchemaAreGenerated(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Numeric keys', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'WrapperModel' => [
                        'type' => 'object',
                        'required' => ['names'],
                        'properties' => [
                            'names' => [
                                '$ref' => '#/components/schemas/SubStatusesView',
                            ],
                        ],
                    ],
                    'SubStatusesView' => [
                        'type' => 'object',
                        'required' => [1, 2, 3, 4, 5, 6, 7, 8],
                        'properties' => [
                            1 => ['type' => 'string'],
                            2 => ['type' => 'string'],
                            3 => ['type' => 'string'],
                            4 => ['type' => 'string'],
                            5 => ['type' => 'string'],
                            6 => ['type' => 'string'],
                            7 => ['type' => 'string'],
                            8 => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/SubStatusesView.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string $_1', $content);
        $this->assertStringContainsString('private readonly string $_8', $content);
        $this->assertStringContainsString("\$aliases['_1'] = '1';", $content);
        $this->assertStringContainsString("\$aliases['_8'] = '8';", $content);
    }

    public function testAdditionalPropertiesRefObjectMapGeneratesArrayOfDto(): void
    {
        $openApi = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Map ref', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'WrapperNamesModel' => [
                        'type' => 'object',
                        'required' => ['namesById'],
                        'properties' => [
                            'namesById' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    '$ref' => '#/components/schemas/NameItemView',
                                ],
                            ],
                        ],
                    ],
                    'NameItemView' => [
                        'type' => 'object',
                        'required' => ['id', 'name1', 'flag1'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name1' => ['type' => 'string'],
                            'flag1' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $wrapper = $this->outputDirectory . '/WrapperNamesModel.php';
        $this->assertFileExists($wrapper);
        $wrapperContent = file_get_contents($wrapper);

        $this->assertStringContainsString('private array $namesById', $wrapperContent);
        $this->assertStringContainsString('@var array<NameItemView>', $wrapperContent);
        $this->assertFileExists($this->outputDirectory . '/NameItemView.php');
    }

    public function testNullableAllOfWithSingleRef(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/nullable-allof.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Test User with single $ref in nullable allOf
        $userFile = $this->outputDirectory . '/UserWithSingleRef.php';
        $this->assertFileExists($userFile);

        $content = file_get_contents($userFile);
        $this->assertStringContainsString('class UserWithSingleRef', $content);
        $this->assertStringContainsString('private readonly ?Cat $pet', $content);
        $this->assertStringNotContainsString('private ?mixed $pet', $content);
    }

    public function testNullableAllOfWithMultipleRefs(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/nullable-allof.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Test User with multiple $refs - should create merged DTO
        $userFile = $this->outputDirectory . '/UserWithMultipleRefs.php';
        $this->assertFileExists($userFile);

        $userContent = file_get_contents($userFile);
        $this->assertStringContainsString('class UserWithMultipleRefs', $userContent);
        $this->assertStringContainsString('private readonly ?UserWithMultipleRefsPet $pet', $userContent);

        // Check merged DTO exists and contains all properties
        $petFile = $this->outputDirectory . '/UserWithMultipleRefsPet.php';
        $this->assertFileExists($petFile);

        $petContent = file_get_contents($petFile);
        $this->assertStringContainsString('class UserWithMultipleRefsPet', $petContent);

        // Should have properties from Cat (meow + name from Pet)
        $this->assertStringContainsString('private readonly string $meow', $petContent);
        $this->assertStringContainsString('private readonly string $name', $petContent);

        // Should have properties from Dog (bark)
        $this->assertStringContainsString('private readonly string $bark', $petContent);

        // Should have extraProperty (last definition wins)
        $this->assertStringContainsString('private readonly string $extraProperty', $petContent);

        // Should have description from last definition
        $this->assertStringContainsString('This should win (last definition)', $petContent);

        // Should NOT have inheritance (multiple $refs means merge, not extend)
        $this->assertStringNotContainsString('extends', $petContent);
    }

    public function testNullableInsideAllOfWithSingleRef(): void
    {
        // OAS 3.0 spec-valid alternative: nullable: true as a branch inside allOf
        // allOf: [{$ref: '...'}, {nullable: true}]
        // Must produce the same result as: nullable: true + allOf: [{$ref: '...'}]
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/nullable-allof.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $userFile = $this->outputDirectory . '/UserWithNullableInsideAllOf.php';
        $this->assertFileExists($userFile);

        $content = file_get_contents($userFile);
        $this->assertStringContainsString('class UserWithNullableInsideAllOf', $content);
        // Must generate ?Cat, not a spurious merged DTO
        $this->assertStringContainsString('private readonly ?Cat $pet', $content);
        $this->assertStringNotContainsString('private ?mixed $pet', $content);
        // No extra merged DTO class must be created for this property
        $this->assertFileDoesNotExist($this->outputDirectory . '/UserWithNullableInsideAllOfPet.php');
        $this->assertStringContainsString('$constraints[\'pet\'] = [\'nullable\' => true];', $content);
    }

    public function testNullableInsideAllOfWithMultipleRefs(): void
    {
        // allOf: [{$ref: Cat}, {$ref: Dog}, {nullable: true}]
        // The nullable branch must be stripped before creating the merged DTO,
        // and the merged DTO must be nullable.
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/nullable-allof.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $userFile = $this->outputDirectory . '/UserWithNullableInsideAllOfMultiRefs.php';
        $this->assertFileExists($userFile);

        $content = file_get_contents($userFile);
        $this->assertStringContainsString('class UserWithNullableInsideAllOfMultiRefs', $content);
        // Merged DTO must be nullable
        $this->assertStringContainsString('private readonly ?UserWithNullableInsideAllOfMultiRefsPet $pet', $content);

        // Merged DTO must exist and contain merged properties (Cat + Dog)
        $petFile = $this->outputDirectory . '/UserWithNullableInsideAllOfMultiRefsPet.php';
        $this->assertFileExists($petFile);

        $petContent = file_get_contents($petFile);
        $this->assertStringContainsString('private readonly string $meow', $petContent);
        $this->assertStringContainsString('private readonly string $bark', $petContent);
        $this->assertStringContainsString('$constraints[\'pet\'] = [\'nullable\' => true];', $content);
    }

    public function testMultipartBinaryFileSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../OpenApiExamples/test.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Inline multipart request body
        $inlineFile = $this->outputDirectory . '/ApiTestFilePostRequest.php';
        $this->assertFileExists($inlineFile);
        $inlineContent = file_get_contents($inlineFile);
        $this->assertStringContainsString(
            'use Symfony\\Component\\HttpFoundation\\File\\UploadedFile;',
            $inlineContent,
        );
        $this->assertStringContainsString('private readonly UploadedFile $file', $inlineContent);
        $this->assertStringContainsString('public function getFile(): UploadedFile', $inlineContent);

        // Referenced multipart request body schema
        $fileRequest = $this->outputDirectory . '/FileRequest.php';
        $this->assertFileExists($fileRequest);
        $requestContent = file_get_contents($fileRequest);
        $this->assertStringContainsString('private readonly UploadedFile $file', $requestContent);
    }

    public function testDateAndDateTimeFormatSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../OpenApiExamples/test.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $userFile = $this->outputDirectory . '/User.php';
        $this->assertFileExists($userFile);
        $userContent = file_get_contents($userFile);
        $this->assertStringContainsString('use DateTimeImmutable;', $userContent);
        $this->assertStringContainsString('private readonly DateTimeImmutable $createdAt', $userContent);
        $this->assertStringContainsString('public function getCreatedAt(): string', $userContent);
        $this->assertStringContainsString('return $this->createdAt->format(\'c\');', $userContent);
        $this->assertStringContainsString('Expected format: yyyy-MM-dd HH:mm:ss', $userContent);

        $user2File = $this->outputDirectory . '/User2.php';
        $this->assertFileExists($user2File);
        $user2Content = file_get_contents($user2File);
        $this->assertStringContainsString('use DateTimeImmutable;', $user2Content);
        $this->assertStringContainsString('private readonly DateTimeImmutable $createdAt', $user2Content);
        $this->assertStringContainsString('public function getCreatedAt(): string', $user2Content);
        $this->assertStringContainsString('return $this->createdAt->format(\'Y-m-d\');', $user2Content);
        $this->assertStringContainsString('Expected format: Y-m-d', $user2Content);
    }

    public function testDatetimeAliasFormatSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/datetime-alias.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $aliasFile = $this->outputDirectory . '/AliasDateTimeModel.php';
        $this->assertFileExists($aliasFile);
        $aliasContent = file_get_contents($aliasFile);
        $this->assertStringContainsString('use DateTimeImmutable;', $aliasContent);
        $this->assertStringContainsString('private readonly DateTimeImmutable $createdAt', $aliasContent);
        $this->assertStringContainsString('public function getCreatedAt(): string', $aliasContent);
        $this->assertStringContainsString('return $this->createdAt->format(\'c\');', $aliasContent);
        $this->assertStringContainsString('Expected format: yyyy-MM-dd HH:mm:ss', $aliasContent);
    }

    // -------------------------------------------------------------------------
    // OpenAPI 3.1 support
    // -------------------------------------------------------------------------

    /**
     * OAS 3.1 replaced `nullable: true` with array type syntax: type: [string, null].
     */
    public function testOpenApi31NullableScalarViaArrayType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/NullableScalarModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // id is required and not nullable
        $this->assertStringContainsString('private readonly int $id', $content);
        $this->assertStringNotContainsString('private readonly ?int $id', $content);

        // nickname: type: [string, null]  →  nullable string
        $this->assertStringContainsString('private readonly string|null|UnsetValue $nickname', $content);

        // score: type: [number, null]  →  nullable float
        $this->assertStringContainsString('private readonly float|null|UnsetValue $score', $content);
    }

    /**
     * OAS 3.1 uses oneOf: [{$ref: ...}, {type: null}] to express nullable $refs.
     */
    public function testOpenApi31NullableRefViaOneOfNull(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/NullableRefModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // pet: oneOf: [$ref: SimplePet, type: null]  →  nullable SimplePet
        $this->assertStringContainsString('private readonly SimplePet|null|UnsetValue $pet', $content);
    }

    /**
     * OAS 3.1 allows keywords alongside $ref (sibling keywords).
     * The description placed next to $ref should still be rendered in the docblock.
     */
    public function testOpenApi31RefWithSiblingDescription(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/RefWithSiblingDescription.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // companion is typed as SimplePet
        $this->assertStringContainsString('private readonly SimplePet|null|UnsetValue $companion', $content);

        // The sibling description must appear in the docblock
        $this->assertStringContainsString('The companion pet of this owner', $content);
    }

    /**
     * OAS 3.1 allows multiple non-null types in the type array: type: [string, integer].
     */
    public function testOpenApi31MultipleNonNullTypesInArrayType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/UnionTypeModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // value: type: [string, integer]  →  string|int union
        $this->assertMatchesRegularExpression('/private readonly (string\|int|int\|string) \$value/', $content);
    }

    /**
     * OAS 3.1 changed exclusiveMinimum / exclusiveMaximum from boolean flags
     * (OAS 3.0) to actual numeric bounds.
     * The generator must preserve them in getConstraints() as numbers.
     */
    public function testOpenApi31ExclusiveMinMaxAsNumbers(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/NumericConstraints31Model.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // Numeric exclusiveMinimum/Maximum must be stored as-is, not as booleans
        $this->assertStringContainsString("'exclusiveMinimum' => 0", $content);
        $this->assertStringContainsString("'exclusiveMaximum' => 1000", $content);
        // Note: YAML parses 100.0 as int 100 (whole-number floats lose the decimal)
        $this->assertStringContainsString("'exclusiveMaximum' => 100", $content);
    }

    /**
     * oneOf with type: null  →  the field should be nullable.
     */
    public function testOpenApi31ExplicitNullType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/ExplicitNullModel.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        // value is nullable string (oneOf string | null)
        $this->assertStringContainsString('private readonly string|null|UnsetValue $value', $content);
    }

    /**
     * A complete 3.1 spec with request body using $ref and array-nullable type
     * must generate the expected DTO.
     */
    public function testOpenApi31RequestBodyWithNullableArrayType(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/fixtures/openapi-31.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/CreateOrderRequest.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private readonly string $product', $content);
        $this->assertStringContainsString('private readonly int $quantity', $content);
        // note: type: [string, null]  →  nullable
        $this->assertStringContainsString('private readonly string|null|UnsetValue $note', $content);
    }

    public function testGeneratesQueryArrayItemConstraintsWithUuidFormat(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Query array item constraints',
                'version' => '1.0.0',
            ],
            'paths' => [
                '/items' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tokens',
                                'in' => 'query',
                                'required' => false,
                                'schema' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                        'format' => 'uuid',
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'ok'],
                        ],
                    ],
                ],
            ],
            'components' => ['schemas' => []],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/ItemsGetQueryParams.php';
        $this->assertFileExists($file);
        $content = (string)file_get_contents($file);

        $this->assertStringContainsString(
            '$constraints[\'tokens\'] = [\'type\' => \'array\', \'items\' => [\'type\' => \'string\', \'format\' => \'uuid\']];',
            $content,
        );
    }

    public function testCopyCommonServices(): void
    {
        $namespace = 'MyApp\\Generated';
        $this->generator->copyCommonServices($this->outputDirectory, $namespace);

        $commonDir = $this->outputDirectory . '/Common';
        $this->assertDirectoryExists($commonDir);

        $expectedFiles = [
            'GeneratedDtoInterface.php',
            'DtoNormalizer.php',
            'DtoNormalizerInterface.php',
            'DtoValidator.php',
            'DtoValidatorInterface.php',
            'DtoDeserializer.php',
            'DtoDeserializerInterface.php',
        ];

        foreach ($expectedFiles as $file) {
            $path = $commonDir . '/' . $file;
            $this->assertFileExists($path);
            $content = (string)file_get_contents($path);
            $this->assertStringContainsString('namespace MyApp\\Generated\\Common;', $content);
            $this->assertStringNotContainsString('namespace OpenapiPhpDtoGenerator\\', $content);
        }

        // Check that interfaces and services are now in the same namespace and refer to each other correctly
        $serviceContent = (string)file_get_contents($commonDir . '/DtoDeserializer.php');
        $this->assertStringContainsString('use MyApp\\Generated\\Common\\DtoDeserializerInterface;', $serviceContent);
        $this->assertStringNotContainsString('use OpenapiPhpDtoGenerator\\Contract\\DtoDeserializerInterface;', $serviceContent);
    }

    public function testCopyCommonServicesCustomPath(): void
    {
        $namespace = 'MyApp\\Generated';
        $customDirRelative = 'Shared/Infrastructure';
        $customNamespace = 'MyApp\\Shared\\Infrastructure';

        $workingDirectory = getcwd() ?: '.';
        $customDir = $workingDirectory . '/' . $customDirRelative;

        try {
            $this->generator->copyCommonServices(
                outputDirectory: $this->outputDirectory,
                namespace: $namespace,
                dtoGeneratorDirectory: $customDirRelative,
                dtoGeneratorNamespace: $customNamespace,
            );

            $this->assertDirectoryExists($customDir);

            $servicePath = $customDir . '/DtoDeserializer.php';
            $this->assertFileExists($servicePath);
            $content = (string)file_get_contents($servicePath);

            // Check namespace change
            $this->assertStringContainsString('namespace MyApp\\Shared\\Infrastructure;', $content);
            // Check use statements change
            $this->assertStringContainsString('use MyApp\\Shared\\Infrastructure\\DtoDeserializerInterface;', $content);
        } finally {
            $this->deleteDirectory($customDir);
            $this->deleteDirectory(dirname($customDir)); // Shared
        }
    }

    public function testCopyCommonServicesCustomPathOnly(): void
    {
        $namespace = 'MyApp\\Generated';
        $customDirRelative = 'Core/Common';

        $workingDirectory = getcwd() ?: '.';
        $customDir = $workingDirectory . '/' . $customDirRelative;

        try {
            $this->generator->copyCommonServices(
                outputDirectory: $this->outputDirectory,
                namespace: $namespace,
                dtoGeneratorDirectory: $customDirRelative,
            );

            $this->assertDirectoryExists($customDir);

            $servicePath = $customDir . '/DtoDeserializer.php';
            $this->assertFileExists($servicePath);
            $content = (string)file_get_contents($servicePath);

            // Check namespace change - it should be derived from base namespace + custom directory
            $this->assertStringContainsString('namespace MyApp\\Generated\\Core\\Common;', $content);
        } finally {
            $this->deleteDirectory($customDir);
            $this->deleteDirectory(dirname($customDir)); // Core
        }
    }

    public function testCliGenerationUsesCustomGeneratedDtoInterfaceImportWhenDtoGeneratorNamespaceProvided(): void
    {
        $unique = uniqid('openapi_cli_common_ns_', true);
        $baseDir = sys_get_temp_dir() . '/openapi_dto_generator_' . $unique;
        $outputDir = $baseDir . '/generated';
        $specFile = $baseDir . '/spec.yaml';
        $commonDir = $baseDir . '/shared/common';

        mkdir($outputDir, 0755, true);
        mkdir($commonDir, 0755, true);

        file_put_contents(
            $specFile,
            <<<'YAML'
openapi: 3.0.0
info:
  title: CLI import test
  version: 1.0.0
paths: { }
components:
  schemas:
    SampleResponse:
      type: object
      required:
        - id
      properties:
        id:
          type: integer
YAML,
        );

        try {
            $commandTester = new CommandTester(new GenerateDtoCommand());
            $exitCode = $commandTester->execute([
                '--file' => $specFile,
                '--directory' => $outputDir,
                '--namespace' => 'TestNamespace',
                '--dto-generator-directory' => $commonDir,
                '--dto-generator-namespace' => 'MyApp\\Shared\\DtoTools',
            ]);

            $this->assertSame(0, $exitCode);

            $dtoFile = $outputDir . '/SampleResponse.php';
            $this->assertFileExists($dtoFile);
            $dtoContent = (string)file_get_contents($dtoFile);
            $this->assertStringContainsString('use MyApp\\Shared\\DtoTools\\GeneratedDtoInterface;', $dtoContent);
            $this->assertStringNotContainsString(
                'use OpenapiPhpDtoGenerator\\Contract\\GeneratedDtoInterface;',
                $dtoContent,
            );

            $this->assertFileExists($commonDir . '/GeneratedDtoInterface.php');
        } finally {
            if (is_dir($baseDir)) {
                $this->deleteDirectory($baseDir);
            }
        }
    }

    public function testCopyCommonServicesAbsolutePath(): void
    {
        $namespace = 'MyApp\\Generated';
        $absoluteDir = $this->outputDirectory . '/Absolute/Common';

        $this->generator->copyCommonServices(
            outputDirectory: $this->outputDirectory,
            namespace: $namespace,
            dtoGeneratorDirectory: $absoluteDir,
        );

        $this->assertDirectoryExists($absoluteDir);

        $servicePath = $absoluteDir . '/DtoDeserializer.php';
        $this->assertFileExists($servicePath);
        $content = (string)file_get_contents($servicePath);

        // By default, the namespace is calculated as $namespace . '\' . $commonSubDir
        // But since the path is absolute, it may look strange.
        // In our case, GenerateDtoCommand tries to fix this.
        // Here we are testing the service itself.
        $expectedNamespace = 'namespace MyApp\\Generated\\' . str_replace('/', '\\', ltrim($absoluteDir, '/')) . ';';
        $this->assertStringContainsString($expectedNamespace, $content);

        // Cleanup
        $this->deleteDirectory($this->outputDirectory . '/Absolute');
    }

    public function testCopyCommonServicesCleansUpDirectory(): void
    {
        $namespace = 'MyApp\\Generated';
        $commonDir = $this->outputDirectory . '/Common';
        
        // Ensure directory exists and has some "old" files
        if (!is_dir($commonDir)) {
            mkdir($commonDir, 0775, true);
        }
        $oldFile = $commonDir . '/OldUnusedFile.php';
        file_put_contents($oldFile, '<?php echo "I should be deleted";');
        $this->assertFileExists($oldFile);

        // Run copy common services
        $this->generator->copyCommonServices($this->outputDirectory, $namespace);

        // Check directory exists and contains new files
        $this->assertDirectoryExists($commonDir);
        $this->assertFileExists($commonDir . '/DtoNormalizer.php');

        // Check that old file is deleted
        $this->assertFileDoesNotExist($oldFile);
    }
}
