<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile as Psr7UploadedFile;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoDeserializerPsr7;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\\ItemsPostRequest');

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

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\\ItemsPostQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getLimit());
    }

    public function testDeserializeCollectionFromPsr7Request(): void
    {
        $json = (string)json_encode([['name' => 'Alpha', 'tags' => []], ['name' => 'Beta', 'tags' => ['x']]]);

        $psr = new ServerRequest('POST', '/items', ['Content-Type' => 'application/json'], $json);
        $fromPsr = $this->psr7Deserializer->deserializeCollectionPsr7($psr, 'Psr7Ns\\ItemsPostRequest');

        $this->assertCount(2, $fromPsr);
        $this->assertSame('Alpha', $fromPsr[0]->getName());
        $this->assertSame('Beta', $fromPsr[1]->getName());

        // Parity: same wire input through the Symfony entrypoint yields an equal list.
        $symfony = SymfonyRequest::create('/items', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $this->assertEquals(
            $this->deserializer->deserializeCollection($symfony, 'Psr7Ns\\ItemsPostRequest'),
            $fromPsr,
        );
    }

    public function testPsr7AndSymfonyProduceEquivalentDto(): void
    {
        $json = (string)json_encode(['name' => 'Widget', 'tags' => ['x']]);

        $psr = new ServerRequest('POST', '/items/7', ['Content-Type' => 'application/json'], $json);
        $fromPsr = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\\ItemsPostRequest');

        // Same wire input through the BC Symfony entrypoint yields an equal DTO.
        $symfony = SymfonyRequest::create('/items/7', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $this->assertEquals(
            $this->deserializer->deserialize($symfony, 'Psr7Ns\\ItemsPostRequest'),
            $fromPsr,
        );
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

        $dto = $deserializer->deserializePsr7($psr, 'Psr7Ns\\ItemsPostRequest');
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

            $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\\UploadPostRequest');

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

        $dto = $this->psr7Deserializer->deserializePsr7($psr, 'Psr7Ns\\SourcesGetQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getPage());
        $this->assertSame('tok-1', $dto->getToken());
        $this->assertSame('cookie-1', $dto->getSid());
    }
}
