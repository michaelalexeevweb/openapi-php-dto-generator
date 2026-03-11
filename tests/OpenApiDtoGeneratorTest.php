<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use PHPUnit\Framework\TestCase;
use OpenapiPhpDtoGenerator\Service\OpenApiDtoGeneratorService;
use Symfony\Component\Yaml\Yaml;

final class OpenApiDtoGeneratorTest extends TestCase
{
    private OpenApiDtoGeneratorService $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new OpenApiDtoGeneratorService();
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
        $this->assertStringContainsString('private int $userId', $content);
        $this->assertStringContainsString('private string $postId', $content);
        $this->assertStringContainsString('private ?int $page', $content);
        $this->assertStringContainsString('private ?int $limit', $content);
        $this->assertStringContainsString('public function getUserId(): int', $content);
        $this->assertStringContainsString('public function getPostId(): string', $content);
        $this->assertStringContainsString('public function getPage(): ?int', $content);
        $this->assertStringContainsString('public function getLimit(): ?int', $content);
        $this->assertStringContainsString('public function isUserIdInPath(): bool', $content);
        $this->assertStringContainsString('public function isPostIdInPath(): bool', $content);
        $this->assertStringContainsString('public function isPageInQuery(): bool', $content);
        $this->assertStringContainsString('public function isLimitInQuery(): bool', $content);
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
        $this->assertStringContainsString('private string $title', $content);
        $this->assertStringContainsString('private ?string $content', $content);
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
        $this->assertStringContainsString('private ?string $title', $content);
        $this->assertStringContainsString('private ?bool $published', $content);
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
        $this->assertStringContainsString('private ?string $status', $content);
        $this->assertStringContainsString('private ?int $timestamp', $content);
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
        $this->assertStringContainsString("case DRAFT = 'draft'", $content);
        $this->assertStringContainsString("case PUBLISHED = 'published'", $content);
        $this->assertStringContainsString("case ARCHIVED = 'archived'", $content);

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
        $this->assertStringContainsString('private ?string $createdAt', $content);
        $this->assertStringContainsString('private ?string $updatedAt', $content);

        // Check array helper on Article.tags
        $articleFile = $this->outputDirectory . '/Article.php';
        $this->assertFileExists($articleFile);
        $articleContent = file_get_contents($articleFile);
        $this->assertStringContainsString('public function addItemToTags(ArticleTagsItem $item): void', $articleContent);
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
        $this->assertStringContainsString('private string $updatedAt', $content);
        $this->assertStringContainsString('string $name,', $content);
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
        $this->assertStringContainsString('private AnimalAnimalType $animalType', $animalContent);
        $this->assertStringContainsString('public static function getDiscriminatorPropertyName(): string', $animalContent);
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
        $this->assertStringContainsString('private string $bark', $dogContent);

        // Check Cat extends Animal
        $catFile = $this->outputDirectory . '/Cat.php';
        $this->assertFileExists($catFile);
        $catContent = file_get_contents($catFile);
        $this->assertStringContainsString('extends Animal', $catContent);
        $this->assertStringContainsString('private string $meow', $catContent);
    }

    public function testDiscriminatorDuplicateMappingTargetThrowsException(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'test', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'BaseAnimal' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string'],
                        ],
                        'discriminator' => [
                            'propertyName' => 'kind',
                            'mapping' => [
                                'dog' => '#/components/schemas/DogAnimal',
                                'dogAlias' => '#/components/schemas/DogAnimal',
                            ],
                        ],
                    ],
                    'DogAnimal' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/BaseAnimal'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'bark' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate target "DogAnimal"');

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');
    }

    public function testGeneratesOpenApiConstraintsMetadata(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'test', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'ConstraintSample' => [
                        'type' => 'object',
                        'required' => ['email', 'amount', 'tags'],
                        'properties' => [
                            'email' => [
                                'type' => 'string',
                                'format' => 'email',
                                'minLength' => 3,
                            ],
                            'amount' => [
                                'type' => 'number',
                                'minimum' => 1,
                                'exclusiveMinimum' => true,
                                'multipleOf' => 2.5,
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 1,
                                'maxItems' => 10,
                                'uniqueItems' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/ConstraintSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('public static function getOpenApiConstraints(): array', $content);
        $this->assertStringContainsString('Constraints: minLength=3, format=email', $content);
        $this->assertStringContainsString("'format' => 'email'", $content);
        $this->assertStringContainsString("'minimum' => 1", $content);
        $this->assertStringContainsString("'exclusiveMinimum' => true", $content);
        $this->assertStringContainsString("'multipleOf' => 2.5", $content);
        $this->assertStringContainsString("'uniqueItems' => true", $content);
    }

    public function testGeneratesUnionTypeForPropertyOneOf(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'test', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'UnionSample' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => [
                                'oneOf' => [
                                    ['type' => 'string', 'format' => 'uuid'],
                                    ['type' => 'integer', 'minimum' => 10, 'maximum' => 100],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/UnionSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private string|int $id', $content);
        $this->assertStringContainsString('Constraints: oneOf=(type=string, format=uuid) | (type=integer, minimum=10, maximum=100)', $content);
        $this->assertStringContainsString('public function __construct(', $content);
        $this->assertStringContainsString('string|int $id,', $content);
        $this->assertStringContainsString('public function getId(): string|int', $content);
    }

    public function testGeneratesExternalRefSchemasIntoSubdirectoryAndImportsThem(): void
    {
        $count = $this->generator->generateFromFile(
            __DIR__ . '/fixtures/external-ref/root.yaml',
            $this->outputDirectory,
            'TestNamespace'
        );

        $this->assertGreaterThanOrEqual(2, $count);

        $externalFile = $this->outputDirectory . '/common/Test.php';
        $this->assertFileExists($externalFile);
        $externalContent = file_get_contents($externalFile);
        $this->assertStringContainsString('namespace TestNamespace\\Common;', $externalContent);
        $this->assertStringContainsString('class Test', $externalContent);

        $localFile = $this->outputDirectory . '/LocalResponse.php';
        $this->assertFileExists($localFile);
        $localContent = file_get_contents($localFile);
        $this->assertStringContainsString('namespace TestNamespace;', $localContent);
        $this->assertStringContainsString('use TestNamespace\\Common\\Test;', $localContent);
        $this->assertStringContainsString('private Test $test', $localContent);
    }

    public function testAllOfLastTypeWinsForProperty(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'test', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'AllOfSample' => [
                        'type' => 'object',
                        'required' => ['value'],
                        'properties' => [
                            'value' => [
                                'allOf' => [
                                    ['type' => 'integer'],
                                    ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $file = $this->outputDirectory . '/AllOfSample.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);

        $this->assertStringContainsString('private string $value', $content);
        $this->assertStringNotContainsString('private int $value', $content);
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
        $this->assertStringContainsString('private string $id', $content);
        $this->assertStringContainsString('private int $actionId', $content);
        $this->assertStringNotContainsString('private ?string $id', $content);

        // Query required flags from malformed specs still map as required/non-nullable.
        $this->assertStringContainsString('private int $page', $content);
        $this->assertStringContainsString('private ?int $limit', $content);
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
        $this->assertStringContainsString('private ?Cat $pet', $content);
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
        $this->assertStringContainsString('private ?UserWithMultipleRefsPet $pet', $userContent);

        // Check merged DTO exists and contains all properties
        $petFile = $this->outputDirectory . '/UserWithMultipleRefsPet.php';
        $this->assertFileExists($petFile);

        $petContent = file_get_contents($petFile);
        $this->assertStringContainsString('class UserWithMultipleRefsPet', $petContent);

        // Should have properties from Cat (meow + name from Pet)
        $this->assertStringContainsString('private string $meow', $petContent);
        $this->assertStringContainsString('private string $name', $petContent);

        // Should have properties from Dog (bark)
        $this->assertStringContainsString('private string $bark', $petContent);

        // Should have extraProperty (last definition wins)
        $this->assertStringContainsString('private string $extraProperty', $petContent);

        // Should have description from last definition
        $this->assertStringContainsString('This should win (last definition)', $petContent);

        // Should NOT have inheritance (multiple $refs means merge, not extend)
        $this->assertStringNotContainsString('extends', $petContent);
    }

    public function testMultipartBinaryFileSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../OpenApiExamples/test.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        // Inline multipart request body
        $inlineFile = $this->outputDirectory . '/ApiTestFilePostRequest.php';
        $this->assertFileExists($inlineFile);
        $inlineContent = file_get_contents($inlineFile);
        $this->assertStringContainsString('use Symfony\\Component\\HttpFoundation\\File\\UploadedFile;', $inlineContent);
        $this->assertStringContainsString('private UploadedFile $file', $inlineContent);
        $this->assertStringContainsString('public function getFile(): UploadedFile', $inlineContent);

        // Referenced multipart request body schema
        $fileRequest = $this->outputDirectory . '/FileRequest.php';
        $this->assertFileExists($fileRequest);
        $requestContent = file_get_contents($fileRequest);
        $this->assertStringContainsString('private UploadedFile $file', $requestContent);
    }

    public function testDateAndDateTimeFormatSupport(): void
    {
        $openApi = Yaml::parseFile(__DIR__ . '/../OpenApiExamples/test.yaml');
        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $userFile = $this->outputDirectory . '/User.php';
        $this->assertFileExists($userFile);
        $userContent = file_get_contents($userFile);
        $this->assertStringContainsString('use DateTimeImmutable;', $userContent);
        $this->assertStringContainsString('private DateTimeImmutable $createdAt', $userContent);
        $this->assertStringContainsString('public function getCreatedAt(): string', $userContent);
        $this->assertStringContainsString('return $this->createdAt->format(\'c\');', $userContent);
        $this->assertStringContainsString('Expected format: yyyy-MM-dd HH:mm:ss', $userContent);

        $user2File = $this->outputDirectory . '/User2.php';
        $this->assertFileExists($user2File);
        $user2Content = file_get_contents($user2File);
        $this->assertStringContainsString('use DateTimeImmutable;', $user2Content);
        $this->assertStringContainsString('private DateTimeImmutable $createdAt', $user2Content);
        $this->assertStringContainsString('public function getCreatedAt(): string', $user2Content);
        $this->assertStringContainsString('return $this->createdAt->format(\'Y-m-d\');', $user2Content);
        $this->assertStringContainsString('Expected format: Y-m-d', $user2Content);
    }

    public function testDatetimeAliasFormatSupport(): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'components' => [
                'schemas' => [
                    'AliasDateTimeModel' => [
                        'type' => 'object',
                        'required' => ['createdAt'],
                        'properties' => [
                            'createdAt' => [
                                'type' => 'string',
                                'format' => 'datetime',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->generator->generateFromArray($openApi, $this->outputDirectory, 'TestNamespace');

        $aliasFile = $this->outputDirectory . '/AliasDateTimeModel.php';
        $this->assertFileExists($aliasFile);
        $aliasContent = file_get_contents($aliasFile);
        $this->assertStringContainsString('use DateTimeImmutable;', $aliasContent);
        $this->assertStringContainsString('private DateTimeImmutable $createdAt', $aliasContent);
        $this->assertStringContainsString('public function getCreatedAt(): string', $aliasContent);
        $this->assertStringContainsString('return $this->createdAt->format(\'c\');', $aliasContent);
        $this->assertStringContainsString('Expected format: yyyy-MM-dd HH:mm:ss', $aliasContent);
    }
}
