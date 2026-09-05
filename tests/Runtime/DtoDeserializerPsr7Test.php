<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile as Psr7UploadedFile;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Throwable;

/**
 * PSR-7 support is provided by {@see DtoDeserializerPsr7}, which converts any PSR-7
 * ServerRequest into a Symfony Request via symfony/psr-http-message-bridge and delegates to the
 * core {@see DtoDeserializer}. These tests prove a PSR-7 request deserializes identically to a
 * Symfony Request (parity), so the runtime works outside Symfony.
 */
final class DtoDeserializerPsr7Test extends TestCase
{
    private DtoDeserializer $deserializer;
    private DtoDeserializerPsr7 $psr7Deserializer;
    private string $outputDirectory;

    protected function setUp(): void
    {
        if (!class_exists(ServerRequest::class)) {
            $this->markTestSkipped('nyholm/psr7 not installed');
        }
        if (!class_exists(HttpFoundationFactory::class)) {
            $this->markTestSkipped('symfony/psr-http-message-bridge not installed');
        }

        $this->deserializer = new DtoDeserializer();
        $this->psr7Deserializer = new DtoDeserializerPsr7($this->deserializer);
        $this->outputDirectory = __DIR__ . '/output-psr7';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        $spec = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/items/{id}' => [
                    'post' => [
                        'operationId' => 'createItem',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['name'],
                                'properties' => [
                                    'name' => ['type' => 'string', 'minLength' => 2],
                                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ]]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
                '/upload' => [
                    'post' => [
                        'operationId' => 'upload',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['multipart/form-data' => ['schema' => [
                                'type' => 'object',
                                'required' => ['avatar'],
                                'properties' => [
                                    'avatar' => ['type' => 'string', 'format' => 'binary'],
                                    'caption' => ['type' => 'string'],
                                ],
                            ]]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
                '/sources/{id}' => [
                    'get' => [
                        'operationId' => 'sources',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'sid', 'in' => 'cookie', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'Psr7Ns');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require_once $file;
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outputDirectory);
    }

    public function testBodyDtoDeserializesFromPsr7Request(): void
    {
        $psr = new ServerRequest(
            'POST',
            '/items/42',
            ['Content-Type' => 'application/json'],
            (string)json_encode(['name' => 'Widget', 'tags' => ['a', 'b']]),
        );

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\ItemsPostRequest');

        $this->assertSame('Widget', $dto->getName());
        $this->assertSame(['a', 'b'], $dto->getTags());
    }

    public function testQueryAndPathDeserializeFromPsr7Request(): void
    {
        // Query via PSR-7 query params; path via PSR-7 request attribute (where routers place it).
        // The bridge copies PSR-7 attributes into the Symfony Request attributes.
        $psr = (new ServerRequest('POST', '/items/42'))
            ->withQueryParams(['limit' => '5'])
            ->withAttribute('id', '42');

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\ItemsPostQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getLimit());
    }

    public function testDeserializeCollectionFromPsr7Request(): void
    {
        $json = (string)json_encode([['name' => 'Alpha', 'tags' => []], ['name' => 'Beta', 'tags' => ['x']]]);

        $psr = new ServerRequest('POST', '/items', ['Content-Type' => 'application/json'], $json);
        $fromPsr = $this->psr7Deserializer->deserializeCollectionPsr7($psr, 'Psr7Ns\ItemsPostRequest');

        $this->assertCount(2, $fromPsr);
        $this->assertSame('Alpha', $fromPsr[0]->getName());
        $this->assertSame('Beta', $fromPsr[1]->getName());

        // Parity: same wire input through the Symfony entrypoint yields an equal list.
        $symfony = SymfonyRequest::create('/items', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $this->assertEquals(
            $this->deserializer->deserializeCollection($symfony, 'Psr7Ns\ItemsPostRequest'),
            $fromPsr,
        );
    }

    public function testPsr7AndSymfonyProduceEquivalentDto(): void
    {
        $json = (string)json_encode(['name' => 'Widget', 'tags' => ['x']]);

        $psr = new ServerRequest('POST', '/items/7', ['Content-Type' => 'application/json'], $json);
        $fromPsr = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\ItemsPostRequest');

        // Same wire input through the BC Symfony entrypoint yields an equal DTO.
        $symfony = SymfonyRequest::create('/items/7', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $this->assertEquals(
            $this->deserializer->deserialize($symfony, 'Psr7Ns\ItemsPostRequest'),
            $fromPsr,
        );
    }

    /**
     * Parity on the REJECTION path.
     *
     * The equivalence test above cannot see it: it compares two DTOs, and a request that is refused
     * produces no DTO to compare. So the entry point that every non-Symfony application uses had its
     * happy path pinned and its error path pinned by nothing — a bridge that lost the body, the
     * query string or the content type would still deserialize a valid payload correctly and only
     * diverge on what it REPORTS, which is the half a caller builds error handling on.
     *
     * The exception class and the message are compared verbatim, both directions of the assertion:
     * the Symfony arm must actually reject, or the case proves nothing about the PSR-7 arm.
     */
    #[DataProvider('rejectedRequestProvider')]
    public function testPsr7AndSymfonyRejectTheSameRequestIdentically(
        string $path,
        string $body,
        string $dtoClass,
        array $attributes,
    ): void {
        $contentType = 'application/json';

        $psr = new ServerRequest('POST', $path, ['Content-Type' => $contentType], $body);
        foreach ($attributes as $name => $attributeValue) {
            $psr = $psr->withAttribute($name, $attributeValue);
        }

        $symfony = SymfonyRequest::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
        foreach ($attributes as $name => $attributeValue) {
            $symfony->attributes->set($name, $attributeValue);
        }

        $fromSymfony = $this->rejection(fn(): object => $this->deserializer->deserialize($symfony, $dtoClass));
        $fromPsr = $this->rejection(fn(): object => $this->psr7Deserializer->deserializePsr7($psr, $dtoClass));

        $this->assertNotNull($fromSymfony, 'the case has to be rejected at all, or it pins nothing');
        $this->assertSame($fromSymfony, $fromPsr);
    }

    /**
     * One case per REASON a request is refused: the schema, the value, the container, the body
     * itself — and one outside the body entirely, because a parameter reaches the deserializer down a
     * different road through the bridge (query string, and a path value carried as a request
     * ATTRIBUTE, which is where a PSR-7 router puts it).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, string>}>
     */
    public static function rejectedRequestProvider(): array
    {
        $body = 'Psr7Ns\ItemsPostRequest';
        $params = 'Psr7Ns\ItemsPostQueryParams';

        return [
            'required property missing' => ['/items/7', '{"tags":["x"]}', $body, []],
            'minLength violated' => ['/items/7', '{"name":"a"}', $body, []],
            'array item of the wrong type' => ['/items/7', '{"name":"Widget","tags":[5]}', $body, []],
            'body is not JSON at all' => ['/items/7', 'not json', $body, []],
            'query parameter of the wrong type' => ['/items/42?limit=abc', '', $params, ['id' => '42']],
        ];
    }

    /**
     * The thrown class and message as one comparable string, or null when nothing was thrown.
     */
    private function rejection(callable $call): ?string
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown::class . ': ' . $thrown->getMessage();
        }

        return null;
    }

    public function testDefaultConstructorWorksWithoutInjectedDeserializer(): void
    {
        $deserializer = new DtoDeserializerPsr7();

        $psr = new ServerRequest(
            'POST',
            '/items/1',
            ['Content-Type' => 'application/json'],
            (string)json_encode(['name' => 'Solo', 'tags' => []]),
        );

        $dto = $deserializer->deserializePsr7($psr, 'Psr7Ns\ItemsPostRequest');
        $this->assertSame('Solo', $dto->getName());
    }

    public function testFileUploadFromPsr7Request(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dto_psr7_file_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'binary-content');

        try {
            // The bridge converts the PSR-7 UploadedFile into a Symfony UploadedFile (temp file).
            $uploaded = new Psr7UploadedFile($tmp, (int)filesize($tmp), UPLOAD_ERR_OK, 'avatar.png', 'image/png');
            $psr = (new ServerRequest('POST', '/upload'))
                ->withUploadedFiles(['avatar' => $uploaded])
                ->withParsedBody(['caption' => 'hi']);

            $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\UploadPostRequest');

            $this->assertInstanceOf(UploadedFile::class, $dto->getAvatar());
            $this->assertSame('avatar.png', $dto->getAvatar()->getClientOriginalName());
            $this->assertSame('hi', $dto->getCaption());
        } finally {
            @unlink($tmp);
        }
    }

    public function testPathQueryHeaderCookieFromPsr7Request(): void
    {
        $psr = (new ServerRequest('GET', '/sources/42?page=5'))
            ->withAttribute('id', '42')
            ->withCookieParams(['sid' => 'cookie-1'])
            ->withHeader('token', 'tok-1');

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\SourcesGetQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getPage());
        $this->assertSame('tok-1', $dto->getToken());
        $this->assertSame('cookie-1', $dto->getSid());
    }
}
