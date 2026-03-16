<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\ResponseService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class ResponseServiceTest extends TestCase
{
    private ResponseService $service;

    protected function setUp(): void
    {
        $this->service = new ResponseService();
    }

    public function testCreateResponseWithValidDto(): void
    {
        $dto = new SimpleResponseDto(1, 'Test Name');

        $response = $this->service->createResponse($dto);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertSame(1, $content['id']);
        $this->assertSame('Test Name', $content['name']);
    }

    public function testCreateResponseWithCustomStatus(): void
    {
        $dto = new SimpleResponseDto(1, 'Created');

        $response = $this->service->createResponse($dto, Response::HTTP_CREATED);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCreateResponseWithCustomHeaders(): void
    {
        $dto = new SimpleResponseDto(1, 'Test');

        $response = $this->service->createResponse($dto, 200, ['X-Custom-Header' => 'value']);

        $this->assertSame('value', $response->headers->get('X-Custom-Header'));
    }

    public function testCreateResponseWithNullableFields(): void
    {
        $dto = new NullableResponseDto(1, 'Test', null);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertSame(1, $content['id']);
        $this->assertSame('Test', $content['name']);
        $this->assertNull($content['description']);
    }

    public function testCreateResponseWithNestedDto(): void
    {
        $nested = new SimpleResponseDto(2, 'Nested');
        $dto = new NestedResponseDto(1, 'Parent', $nested);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertSame(1, $content['id']);
        $this->assertSame('Parent', $content['name']);
        $this->assertIsArray($content['nested']);
        $this->assertSame(2, $content['nested']['id']);
        $this->assertSame('Nested', $content['nested']['name']);
    }

    public function testCreateResponseWithArray(): void
    {
        $dto = new ArrayResponseDto(['tag1', 'tag2', 'tag3']);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertSame(['tag1', 'tag2', 'tag3'], $content['tags']);
    }

    public function testCreateResponseWithEnum(): void
    {
        $dto = new EnumResponseDto(TestEnum::VALUE_A);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertSame('a', $content['status']);
    }

    public function testCreateResponseWithDateTime(): void
    {
        $date = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $dto = new DateTimeResponseDto($date);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertStringContainsString('2024-01-15', $content['createdAt']);
    }

    public function testCreateResponseThrowsExceptionForInvalidDto(): void
    {
        $dto = new InvalidResponseDto();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DTO validation failed');

        $this->service->createResponse($dto);
    }

    public function testCreateResponseWithArrayOfDtos(): void
    {
        $items = [
            new SimpleResponseDto(1, 'First'),
            new SimpleResponseDto(2, 'Second'),
        ];
        $dto = new ArrayOfDtosResponseDto($items);

        $response = $this->service->createResponse($dto);

        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content['items']);
        $this->assertSame(1, $content['items'][0]['id']);
        $this->assertSame('First', $content['items'][0]['name']);
        $this->assertSame(2, $content['items'][1]['id']);
        $this->assertSame('Second', $content['items'][1]['name']);
    }

    public function testCreateResponseStreamsSingleFileDto(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sog_file_');
        self::assertNotFalse($tempPath);
        file_put_contents($tempPath, 'hello-file');

        try {
            $uploadedFile = new UploadedFile($tempPath, 'hello.txt', 'text/plain', null, true);
            $dto = new FileOnlyResponseDto($uploadedFile);

            $response = $this->service->createResponse($dto);

            $this->assertInstanceOf(BinaryFileResponse::class, $response);
            $this->assertSame(200, $response->getStatusCode());
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function testCreateResponseSerializesFileMetadataInJson(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sog_file_');
        self::assertNotFalse($tempPath);
        file_put_contents($tempPath, 'hello-file');

        try {
            $uploadedFile = new UploadedFile($tempPath, 'hello.txt', 'text/plain', null, true);
            $dto = new FileWithExtraDataResponseDto(10, $uploadedFile);

            $response = $this->service->createResponse($dto);

            $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
            $content = json_decode((string)$response->getContent(), true);

            $this->assertSame(10, $content['id']);
            $this->assertSame('hello.txt', $content['file']['originalName']);
            $this->assertSame('text/plain', $content['file']['clientMimeType']);
            $this->assertArrayHasKey('filename', $content['file']);
            $this->assertArrayHasKey('size', $content['file']);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function testCreateStreamResponseAsAttachment(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sog_download_');
        self::assertNotFalse($tempPath);
        file_put_contents($tempPath, 'download-content');

        try {
            $response = $this->service->createStreamResponse(
                $tempPath,
                true,
                'report.txt',
            );

            $this->assertInstanceOf(BinaryFileResponse::class, $response);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('attachment', (string)$response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('report.txt', (string)$response->headers->get('Content-Disposition'));
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function testCreateResponseSkipsStaticDiscriminatorMetadata(): void
    {
        $dto = new DiscriminatorLikeResponseDto(123, 'discriminator1');

        $response = $this->service->createResponse($dto);

        $content = json_decode((string)$response->getContent(), true);
        $this->assertSame(123, $content['id']);
        $this->assertSame('discriminator1', $content['type']);
        $this->assertArrayNotHasKey('discriminatorPropertyName', $content);
        $this->assertArrayNotHasKey('discriminatorMapping', $content);
    }

    public function testCreateResponseValidatesOpenApiConstraints(): void
    {
        $dto = new ConstrainedResponseDto(1, 'not-an-email');

        try {
            $this->service->createResponse($dto);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('field "amount" must be greater than 1', $e->getMessage());
            $this->assertStringContainsString('field "email" must match format email', $e->getMessage());
        }
    }

    public function testCreateResponseSupportsUnionGetterReturnType(): void
    {
        $dto = new UnionGetterResponseDto(42);

        $response = $this->service->createResponse($dto);
        $content = json_decode((string)$response->getContent(), true);

        $this->assertSame(42, $content['id']);
    }

    public function testCreateResponseUsesOpenApiAliasKeys(): void
    {
        $dto = new AliasedResponseDto('yes', 'safe');

        $response = $this->service->createResponse($dto);
        $content = json_decode((string)$response->getContent(), true);

        $this->assertIsArray($content);
        $this->assertSame('yes', $content['test-process']);
        $this->assertSame('safe', $content['processed']);
        $this->assertArrayNotHasKey('test_process', $content);
    }

    public function testCreateResponseUsesAliasKeysWhenGetterThrowsProvidedInRequestGuard(): void
    {
        $dto = new AliasedGuardedResponseDto('queued');

        $response = $this->service->createResponse($dto);
        $content = json_decode((string)$response->getContent(), true);

        $this->assertIsArray($content);
        $this->assertSame('queued', $content['test-process']);
        $this->assertArrayNotHasKey('test_process', $content);
    }
}

// Test DTOs
final class SimpleResponseDto
{
    public function __construct(
        private int $id,
        private string $name,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

final class NullableResponseDto
{
    public function __construct(
        private int $id,
        private string $name,
        private ?string $description,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}

final class NestedResponseDto
{
    public function __construct(
        private int $id,
        private string $name,
        private SimpleResponseDto $nested,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNested(): SimpleResponseDto
    {
        return $this->nested;
    }
}

final class ArrayResponseDto
{
    /** @param array<string> $tags */
    public function __construct(
        private array $tags,
    ) {
    }

    /** @return array<string> */
    public function getTags(): array
    {
        return $this->tags;
    }
}

enum TestEnum: string
{
    case VALUE_A = 'a';
    case VALUE_B = 'b';
}

final class EnumResponseDto
{
    public function __construct(
        private TestEnum $status,
    ) {
    }

    public function getStatus(): TestEnum
    {
        return $this->status;
    }
}

final class DateTimeResponseDto
{
    public function __construct(
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

final class InvalidResponseDto
{
    // Returns wrong type
    public function getId(): int
    {
        return 'not-an-int'; // @phpstan-ignore-line
    }
}

final class ArrayOfDtosResponseDto
{
    /** @param array<SimpleResponseDto> $items */
    public function __construct(
        private array $items,
    ) {
    }

    /** @return array<SimpleResponseDto> */
    public function getItems(): array
    {
        return $this->items;
    }
}

final class FileOnlyResponseDto
{
    public function __construct(
        private UploadedFile $file,
    ) {
    }

    public function getFile(): UploadedFile
    {
        return $this->file;
    }
}

final class FileWithExtraDataResponseDto
{
    public function __construct(
        private int $id,
        private UploadedFile $file,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFile(): UploadedFile
    {
        return $this->file;
    }
}

final class DiscriminatorLikeResponseDto
{
    public function __construct(
        private int $id,
        private string $type,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public static function getDiscriminatorPropertyName(): string
    {
        return 'type';
    }

    /** @return array<string, class-string> */
    public static function getDiscriminatorMapping(): array
    {
        return [
            'discriminator1' => self::class,
        ];
    }
}

final class ConstrainedResponseDto
{
    public function __construct(
        private float $amount,
        private string $email,
    ) {
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getOpenApiConstraints(): array
    {
        return [
            'amount' => [
                'minimum' => 1,
                'exclusiveMinimum' => true,
            ],
            'email' => [
                'format' => 'email',
            ],
        ];
    }
}

final class UnionGetterResponseDto
{
    public function __construct(
        private string|int $id,
    ) {
    }

    public function getId(): string|int
    {
        return $this->id;
    }
}

final class AliasedResponseDto
{
    public function __construct(
        private string $test_process,
        private string $processed,
    ) {
    }

    public function getTest_process(): string
    {
        return $this->test_process;
    }

    public function getProcessed(): string
    {
        return $this->processed;
    }

    /** @return array<string, string> */
    public static function getOpenApiPropertyAliases(): array
    {
        return [
            'test_process' => 'test-process',
            'processed' => 'processed',
        ];
    }
}

final class AliasedGuardedResponseDto
{
    public function __construct(
        private string $test_process,
    ) {
    }

    public function getTest_process(): string
    {
        throw new \LogicException('Field "test-process" wasn\'t provided in request');
    }

    /** @return array<string, string> */
    public static function getOpenApiPropertyAliases(): array
    {
        return [
            'test_process' => 'test-process',
        ];
    }
}

