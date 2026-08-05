<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Laravel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Laravel mode (`--attributes=laravel`) — first increment.
 *
 * The mode emits a plain DTO carrying `rules()` for the framework's own validator plus
 * `fromValidated()` to build the object from `$request->validated()`. Nothing to install: no
 * `spatie/laravel-data`, and no runtime dependency on this package either.
 *
 * `rules()` is asserted as SOURCE rather than invoked, because `illuminate/validation` is not
 * installable here yet (composer blocks it: `illuminate/http` requires a guzzle version that the
 * local audit config flags). Driving the rules through a real `Validator` is the next step — see
 * `.todo.codegeneration_laravel`, M5.
 */
final class GenerateLaravelDtoTest extends TestCase
{
    private GenerateDtoCommand $generator;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->generator = new GenerateDtoCommand();
        $this->outputDirectory = __DIR__ . '/output-laravel';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*.php') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($entry);
                continue;
            }
            @unlink($entry);
        }
        @rmdir($this->outputDirectory);
    }

    /**
     * @return array<string, mixed>
     */
    private static function articleSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Status' => ['type' => 'string', 'enum' => ['draft', 'live']],
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string', 'minLength' => 2]],
                    ],
                    'Article' => [
                        'type' => 'object',
                        'required' => ['id', 'title', 'status'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 80],
                            // A `|` inside the pattern is exactly why the rule list must be an array.
                            'slug' => ['type' => 'string', 'pattern' => '^[a-z|0-9-]+$'],
                            'status' => ['$ref' => '#/components/schemas/Status'],
                            'published_at' => ['type' => 'string', 'format' => 'date-time'],
                            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                            'scores' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function generateArticle(string $namespace): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $this->generator->generateFromArray(self::articleSpec(), $target, $namespace, 'laravel');

        foreach (['Status', 'Tag', 'Article'] as $class) {
            require_once $target . '/' . $class . '.php';
        }

        return $target;
    }

    public function testRulesUseTheOpenApiNamesAndTheArrayForm(): void
    {
        $target = $this->generateArticle('LvRules');
        $article = (string)file_get_contents($target . '/Article.php');

        // Keyed by the OpenAPI names — that is what the payload carries.
        // `present`, not `required`: Laravel's `required` rejects `""`, `[]`, `{}` and null, which are
        // all legal values for a required property. See the parity suite.
        $this->assertStringContainsString("'id' => ['present', 'integer', 'min:1']", $article);
        $this->assertStringContainsString("'title' => ['present', 'string', 'min:3', 'max:80']", $article);
        // Optional: `sometimes` is what makes PATCH work. NO `nullable` — the schema never said so,
        // and optionality is about the key being absent, not about null being a legal value.
        $this->assertStringContainsString("'slug' => ['sometimes', 'string', 'regex:/^[a-z|0-9-]+\$/']", $article);
        // One rule per enum, pinning backing type and members together.
        $this->assertStringContainsString("'status' => ['present', Rule::enum(Status::class)]", $article);
        $this->assertStringContainsString('use Illuminate\Validation\Rule;', $article);
        // `array` alone accepts an associative array, so a JSON array needs `list` too.
        $this->assertStringContainsString("'tags' => ['sometimes', 'array', 'list']", $article);
        // The date-time formats are the ones every mode accepts.
        $this->assertStringContainsString("'date_format:Y-m-d\\TH:i:sP,", $article);

        $this->assertNull($this->lintError($target . '/Article.php'));
    }

    /**
     * Laravel has no `#[Assert\Valid]` cascade — the rule set is one flat map, so a nested DTO has to
     * be expanded into dotted paths or its payload is typed but never validated.
     */
    public function testNestedAndItemRulesAreExpandedIntoDottedPaths(): void
    {
        $target = $this->generateArticle('LvNested');
        $article = (string)file_get_contents($target . '/Article.php');

        // A nested property's presence belongs to the interpreter — no rule expresses "required only
        // if the parent has a value" — so only the value rules are emitted for the dotted path.
        $this->assertStringContainsString("'tags.*.name' => ['string', 'min:2']", $article);
        $this->assertStringContainsString("'scores.*' => ['integer', 'min:0']", $article);
    }

    public function testFromValidatedHydratesEnumsDatesAndNestedDtos(): void
    {
        $this->generateArticle('LvHydrate');

        /** @var object $dto */
        $dto = call_user_func(['LvHydrate\Article', 'fromValidated'], [
            'id' => 7,
            'title' => 'Hello',
            'status' => 'live',
            'published_at' => '2026-03-10T12:00:00+00:00',
            'tags' => [['name' => 'php'], ['name' => 'api']],
            'scores' => [1, 2],
        ]);

        $this->assertSame(7, $dto->getId());
        $this->assertSame('live', $dto->getStatus()->value);
        // A temporal property is read as the string the schema declares, like every other mode.
        $this->assertSame('2026-03-10T12:00:00+00:00', $dto->getPublishedAt());
        $this->assertInstanceOf('DateTimeImmutable', $dto->getPublishedAtAsDateTime());
        $this->assertSame(['php', 'api'], array_map(static fn(object $tag): string => $tag->getName(), $dto->getTags()));
        $this->assertSame([1, 2], $dto->getScores());
    }

    /**
     * `validated()` returns only the keys the payload carried, which is where presence comes from —
     * so "absent" and "sent as null" stay distinguishable without a sentinel.
     */
    public function testPresenceComesFromTheValidatedKeys(): void
    {
        $this->generateArticle('LvPresence');
        $base = ['id' => 1, 'title' => 'Hello', 'status' => 'draft'];

        $absent = call_user_func(['LvPresence\Article', 'fromValidated'], $base);
        $this->assertFalse($absent->isSlugProvided());
        $this->assertNull($absent->getSlug());
        $this->assertSame($base, $absent->toArray());

        $explicitNull = call_user_func(['LvPresence\Article', 'fromValidated'], $base + ['slug' => null]);
        $this->assertTrue($explicitNull->isSlugProvided());
        $this->assertNull($explicitNull->getSlug());
        $this->assertSame($base + ['slug' => null], $explicitNull->toArray());

        $sent = call_user_func(['LvPresence\Article', 'fromValidated'], $base + ['slug' => 'hello']);
        $this->assertTrue($sent->isSlugProvided());
        $this->assertSame('hello', $sent->getSlug());
    }

    public function testUnknownModeMessageListsAllThreeModes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected one of: "runtime", "symfony", "laravel"');

        $this->generator->generateFromArray(
            ['openapi' => '3.0.3', 'info' => ['title' => 'T', 'version' => '1.0.0']],
            $this->outputDirectory,
            'LvBad',
            'banana',
        );
    }

    private function lintError(string $file): ?string
    {
        $output = [];
        $status = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $status);

        return $status === 0 ? null : implode("\n", $output);
    }

    /**
     * A FormRequest is emitted for the classes that describe an INCOMING payload — a request body and
     * an operation's parameters — and for nothing else. A response DTO has no request to validate.
     */
    public function testFormRequestIsEmittedForRequestPayloadsOnly(): void
    {
        $target = $this->generateEndpointSpec('LvForm');

        $this->assertFileExists($target . '/ArticlesPostRequestFormRequest.php');
        $this->assertFileExists($target . '/ArticlesGetQueryParamsFormRequest.php');
        // The 200 response DTO is not an incoming payload.
        $this->assertFileExists($target . '/Articles200.php');
        $this->assertFileDoesNotExist($target . '/Articles200FormRequest.php');

        $formRequest = (string)file_get_contents($target . '/ArticlesPostRequestFormRequest.php');

        // Thin by design: all three methods delegate, so the two files cannot disagree.
        $this->assertStringContainsString('final class ArticlesPostRequestFormRequest extends FormRequest', $formRequest);
        $this->assertStringContainsString('return ArticlesPostRequest::rules();', $formRequest);
        $this->assertStringContainsString('return ArticlesPostRequest::fromValidated($validated);', $formRequest);
        // An OpenAPI document says nothing about policy, so no authorize() is invented.
        $this->assertStringNotContainsString('function authorize', $formRequest);

        $this->assertNull($this->lintError($target . '/ArticlesPostRequestFormRequest.php'));
        $this->assertNull($this->lintError($target . '/ArticlesGetQueryParamsFormRequest.php'));
    }

    /**
     * A request body written as `$ref: '#/components/schemas/X'` is still a request payload. It used to
     * get no FormRequest at all — the walker only recorded INLINE bodies — so the most idiomatic way to
     * write a spec produced the one shape the mode exists for and then withheld it, leaving the
     * application to wire `rules()`, `withValidator()` and `fromValidated()` by hand.
     *
     * A schema referenced from a response as well still gets one: an unused FormRequest costs a file,
     * a missing one costs the ergonomics.
     */
    public function testAComponentUsedAsARequestBodyGetsAFormRequest(): void
    {
        $namespace = 'LvFormRef';
        $target = $this->outputDirectory . '/' . $namespace;
        mkdir($target, 0o755, true);

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/articles' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/ArticleInput'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/ArticleOutput'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'ArticleInput' => [
                        'type' => 'object',
                        'required' => ['title'],
                        'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 3],
                            'mode' => ['anyOf' => [
                                ['type' => 'string', 'minLength' => 2],
                                ['type' => 'integer'],
                            ]],
                        ],
                    ],
                    'ArticleOutput' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ], $target, $namespace, 'laravel');

        $this->assertFileExists($target . '/ArticleInputFormRequest.php');
        // A component reached only from a response is not an incoming payload.
        $this->assertFileExists($target . '/ArticleOutput.php');
        $this->assertFileDoesNotExist($target . '/ArticleOutputFormRequest.php');

        // The interpreter forwarding still resolves — the `anyOf` needs it, and the FormRequest is
        // rendered from the same fact the DTO was.
        $formRequest = (string)file_get_contents($target . '/ArticleInputFormRequest.php');
        $this->assertStringContainsString('return ArticleInput::rules();', $formRequest);
        $this->assertStringContainsString('ArticleInput::withValidator($validator, $this->getContent());', $formRequest);
        $this->assertStringContainsString('return ArticleInput::fromValidated($validated);', $formRequest);

        $this->assertNull($this->lintError($target . '/ArticleInputFormRequest.php'));
    }

    /**
     * `withValidator()` is forwarded only when the DTO actually has an interpreter — a schema that rules
     * fully express must not gain a method that does nothing.
     */
    public function testWithValidatorIsForwardedOnlyWhenTheDtoHasAnInterpreter(): void
    {
        $target = $this->generateEndpointSpec('LvFormValidator');

        // The request body has an `anyOf` property, so its DTO carries the interpreter.
        $withComposition = (string)file_get_contents($target . '/ArticlesPostRequestFormRequest.php');
        $this->assertStringContainsString('public function withValidator(Validator $validator): void', $withComposition);
        $this->assertStringContainsString('use Illuminate\Validation\Validator;', $withComposition);

        // The query params are a plain integer — nothing for the interpreter to do.
        $plain = (string)file_get_contents($target . '/ArticlesGetQueryParamsFormRequest.php');
        $this->assertStringNotContainsString('withValidator', $plain);
        $this->assertStringNotContainsString('use Illuminate\Validation\Validator;', $plain);
    }

    /**
     * `type: object` given a JSON array has to be refused, and the decoded payload cannot answer that
     * question — a JSON object keyed 0..n-1 decodes to the very same PHP list. So the FormRequest hands
     * `withValidator()` the UNDECODED body, and this drives the emitted code through a real
     * `illuminate/validation` validator: array refused, dense-key object accepted.
     */
    public function testTheFormRequestForwardsTheRawBodySoATypeObjectRefusesAJsonArray(): void
    {
        $target = $this->outputDirectory . '/LvRawShape';
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/settings' => [
                    'post' => [
                        'operationId' => 'settingsSave',
                        'requestBody' => [
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['flags'],
                                'properties' => ['flags' => [
                                    'type' => 'object',
                                    'additionalProperties' => ['type' => 'integer'],
                                ]],
                            ]]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ], $target, 'LvRawShape', 'laravel');

        $formRequestFile = $target . '/SettingsPostRequestFormRequest.php';
        $this->assertNull($this->lintError($formRequestFile));
        $this->assertStringContainsString(
            'SettingsPostRequest::withValidator($validator, $this->getContent());',
            (string)file_get_contents($formRequestFile),
        );

        if (!class_exists(FormRequest::class)) {
            // `laravel/framework` is not a dependency of this package — the emitted code needs it, the
            // generator does not. The stub is a Laravel `Request`, which is all this test drives.
            require_once __DIR__ . '/Stub/form-request.php';
        }
        require_once $target . '/SettingsPostRequest.php';
        require_once $formRequestFile;

        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $verdict = static function (string $json) use ($factory): array {
            /** @var array<string, mixed> $payload */
            $payload = (array)json_decode($json, true);
            $formRequest = \LvRawShape\SettingsPostRequestFormRequest::create(
                '/settings',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $json,
            );

            /** @var array<string, mixed> $rules */
            $rules = $formRequest->rules();
            $validator = $factory->make($payload, $rules);
            $formRequest->withValidator($validator);

            /** @var array<int, string> $errors */
            $errors = $validator->errors()->all();

            return $errors;
        };

        $this->assertSame([], $verdict('{"flags":{"a":1}}'));
        // A JSON object whose keys are 0..n-1 is still an object — the check must not overreach.
        $this->assertSame([], $verdict('{"flags":{"0":1,"1":2}}'));
        $this->assertSame(['flags expects object, got array'], $verdict('{"flags":[1,2]}'));
    }

    private function generateEndpointSpec(string $namespace): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/articles' => [
                    'post' => [
                        'operationId' => 'articleCreate',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['title'],
                                        'properties' => [
                                            'title' => ['type' => 'string', 'minLength' => 3],
                                            'mode' => ['anyOf' => [
                                                ['type' => 'string', 'minLength' => 2],
                                                ['type' => 'integer'],
                                            ]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['id'],
                                            'properties' => ['id' => ['type' => 'integer']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/articles/{id}' => [
                    'get' => [
                        'operationId' => 'articleGet',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ], $target, $namespace, 'laravel');

        return $target;
    }

    /**
     * The presence array exists only to answer `isXxxProvided()`. A class whose every property is
     * required has no such question, so it must not carry a non-schema constructor parameter that
     * nothing reads — nothing fills it reflectively either, `fromValidated()` is the only writer.
     */
    public function testThePresenceArrayIsEmittedOnlyWhenSomethingIsOptional(): void
    {
        $target = $this->outputDirectory . '/LvPresenceShape';
        mkdir($target, 0o755, true);

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'AllRequired' => [
                        'type' => 'object',
                        'required' => ['a', 'b'],
                        'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
                    ],
                    'HasOptional' => [
                        'type' => 'object',
                        'required' => ['a'],
                        'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
                    ],
                ],
            ],
        ], $target, 'LvPresenceShape', 'laravel');

        $allRequired = (string)file_get_contents($target . '/AllRequired.php');
        $this->assertStringNotContainsString('providedOpenApiKeys', $allRequired);
        // Not even in the docblock: a class with nothing optional has no presence to explain.
        $this->assertStringNotContainsString('isXxxProvided', $allRequired);
        $this->assertStringNotContainsString('public function is', $allRequired);
        $this->assertNull($this->lintError($target . '/AllRequired.php'));

        $hasOptional = (string)file_get_contents($target . '/HasOptional.php');
        // A plain private property, NOT a constructor parameter: the constructor takes the schema's
        // properties and nothing else, and `fromValidated()` — the only hydrator — fills this in.
        $this->assertStringContainsString('private array $providedOpenApiKeys = [];', $hasOptional);
        $this->assertStringNotContainsString('$providedOpenApiKeys = [],', $hasOptional);
        $this->assertStringContainsString('$dto->providedOpenApiKeys = array_keys($data);', $hasOptional);
        $this->assertStringContainsString('public function isBProvided(): bool', $hasOptional);
        $this->assertNull($this->lintError($target . '/HasOptional.php'));

        // Hand-construction stays possible and stays honest: nothing was sent, so nothing is provided.
        require_once $target . '/HasOptional.php';
        /** @var object $handBuilt */
        $handBuilt = new ('LvPresenceShape\HasOptional')(a: 'x', b: 7);
        $this->assertFalse($handBuilt->isBProvided());
    }

    /**
     * `format: binary` resolves to `UploadedFile`. Two things went wrong at once and both surfaced only
     * against a real payload: the import was decided from the `format` keyword, which is pruned from the
     * constraints BECAUSE the type carries it, so the emitted hint referred to a class that does not
     * exist in the DTO's namespace; and no `file` rule was emitted, so a string passed validation and
     * the constructor's TypeError surfaced as a 500 instead of a 422.
     */
    public function testABinaryPropertyIsTypedAndValidatedAsAFile(): void
    {
        $target = $this->outputDirectory . '/LvFile';
        mkdir($target, 0o755, true);

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/upload' => [
                    'post' => [
                        'operationId' => 'upload',
                        'requestBody' => [
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['doc'],
                                        'properties' => ['doc' => ['type' => 'string', 'format' => 'binary']],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ], $target, 'LvFile', 'laravel');

        $dto = (string)file_get_contents($target . '/UploadPostRequest.php');

        $this->assertStringContainsString('use Symfony\Component\HttpFoundation\File\UploadedFile;', $dto);
        $this->assertStringContainsString('private readonly UploadedFile $doc', $dto);
        // The payload must BE a file, or the type hint would be enforced by a TypeError at 500.
        $this->assertStringContainsString("'doc' => ['present', 'file']", $dto);
        $this->assertNull($this->lintError($target . '/UploadPostRequest.php'));
    }

    /**
     * `Rule::enum(...)` can come from the NESTED expansion (`child.kind`) while the outer property's own
     * rules need no facade at all. The import decision missed that, so the first call to `rules()` died
     * with `Class "…\Rule" not found` — found by running the demo controller, not by a unit test.
     */
    public function testTheRuleFacadeIsImportedWhenOnlyANestedRuleNeedsIt(): void
    {
        $target = $this->outputDirectory . '/LvNestedEnum';
        mkdir($target, 0o755, true);

        $this->generator->generateFromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Kind' => ['type' => 'string', 'enum' => ['a', 'b']],
                    'Child' => [
                        'type' => 'object',
                        'required' => ['kind'],
                        'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']],
                    ],
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['child'],
                        'properties' => ['child' => ['$ref' => '#/components/schemas/Child']],
                    ],
                ],
            ],
        ], $target, 'LvNestedEnum', 'laravel');

        $holder = (string)file_get_contents($target . '/Holder.php');

        $this->assertStringContainsString('Rule::enum(Kind::class)', $holder);
        $this->assertStringContainsString('use Illuminate\Validation\Rule;', $holder);
        // The enum lives in the same namespace, so it needs no import — only the facade does.
        $this->assertNull($this->lintError($target . '/Holder.php'));
    }
}
