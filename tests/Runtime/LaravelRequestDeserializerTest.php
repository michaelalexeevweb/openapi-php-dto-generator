<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Routing\Route;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Laravel's Illuminate\Http\Request extends the Symfony Request, so the core
 * DtoDeserializer accepts it directly — body and query work natively. The only gap is path/route
 * parameters, which Laravel keeps in $request->route()->parameters() rather than the attribute bag;
 * deserialize() bridges them across (duck-typed, no helper class). These tests prove a real Laravel
 * Request deserializes correctly through the unchanged deserialize() entrypoint.
 */
final class LaravelRequestDeserializerTest extends TestCase
{
    private DtoDeserializer $deserializer;
    private string $outputDirectory;

    protected function setUp(): void
    {
        if (!class_exists(LaravelRequest::class) || !class_exists(Route::class)) {
            $this->markTestSkipped('illuminate/http + illuminate/routing not installed');
        }

        $this->deserializer = new DtoDeserializer();
        $this->outputDirectory = __DIR__ . '/output-laravel';
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

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'LaravelNs');
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

    public function testLaravelRequestIsASymfonyRequest(): void
    {
        $request = LaravelRequest::create('/items/1', 'GET');
        $this->assertInstanceOf(SymfonyRequest::class, $request);
    }

    public function testBodyDtoDeserializesFromLaravelRequest(): void
    {
        $request = LaravelRequest::create(
            '/items/42',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string)json_encode(['name' => 'Widget', 'tags' => ['a', 'b']]),
        );

        $dto = $this->deserializer->deserialize($request, 'LaravelNs\\ItemsPostRequest');

        $this->assertSame('Widget', $dto->getName());
        $this->assertSame(['a', 'b'], $dto->getTags());
    }

    public function testRouteAndQueryParametersDeserializeFromLaravelRequest(): void
    {
        // Laravel keeps route params in route()->parameters(), NOT in the attribute bag.
        // limit goes in the query string (a query-bound param is read only from $request->query).
        $request = LaravelRequest::create('/items/42?limit=5', 'POST');

        $route = new Route('POST', '/items/{id}', []);
        $route->bind($request); // parses {id} => '42' out of the request path
        $request->setRouteResolver(static fn(): Route => $route);

        $dto = $this->deserializer->deserialize($request, 'LaravelNs\\ItemsPostQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getLimit());
    }

    public function testRouteParameterDoesNotOverrideExistingAttribute(): void
    {
        // A path param already placed in the attribute bag keeps precedence over the route resolver.
        $request = LaravelRequest::create('/items/99', 'POST');
        $request->attributes->set('id', 'from-attributes');

        $route = new Route('POST', '/items/{id}', []);
        $route->bind($request); // would resolve id => '99'
        $request->setRouteResolver(static fn(): Route => $route);

        $dto = $this->deserializer->deserialize($request, 'LaravelNs\\ItemsPostQueryParams');

        $this->assertSame('from-attributes', $dto->getId());
    }

    public function testFileUploadFromLaravelRequest(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dto_laravel_file_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'binary-content');

        try {
            $file = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
            $request = LaravelRequest::create('/upload', 'POST', ['caption' => 'hi'], [], ['avatar' => $file]);

            $dto = $this->deserializer->deserialize($request, 'LaravelNs\\UploadPostRequest');

            $this->assertInstanceOf(UploadedFile::class, $dto->getAvatar());
            $this->assertSame('avatar.png', $dto->getAvatar()->getClientOriginalName());
            $this->assertSame('hi', $dto->getCaption());
        } finally {
            @unlink($tmp);
        }
    }

    public function testPathQueryHeaderCookieFromLaravelRequest(): void
    {
        // page → query string, sid → cookie, token → header (HTTP_ server key), id → route param.
        $request = LaravelRequest::create('/sources/42?page=5', 'GET', [], ['sid' => 'cookie-1'], [], ['HTTP_TOKEN' => 'tok-1']);

        $route = new Route('GET', '/sources/{id}', []);
        $route->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);

        $dto = $this->deserializer->deserialize($request, 'LaravelNs\\SourcesGetQueryParams');

        $this->assertSame('42', $dto->getId());
        $this->assertSame(5, $dto->getPage());
        $this->assertSame('tok-1', $dto->getToken());
        $this->assertSame('cookie-1', $dto->getSid());
    }
}
