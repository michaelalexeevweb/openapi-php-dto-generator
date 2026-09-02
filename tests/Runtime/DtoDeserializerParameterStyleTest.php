<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class DtoDeserializerParameterStyleTest extends TestCase
{
    private DtoDeserializer $deserializer;
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->deserializer = new DtoDeserializer();
        $this->outputDirectory = __DIR__ . '/output-param-style';

        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }

        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Style runtime', 'version' => '1.0.0'],
            'paths' => [
                '/styles/{ids}/{meta}/{matrixFlags}/{labelTags}' => [
                    'get' => [
                        'operationId' => 'styles',
                        'parameters' => [
                            [
                                'name' => 'ids',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'matrix',
                                'explode' => false,
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                            ],
                            [
                                'name' => 'meta',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'label',
                                'explode' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => ['type' => 'string'],
                                ],
                            ],
                            [
                                'name' => 'matrixFlags',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'matrix',
                                'explode' => true,
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                            ],
                            [
                                'name' => 'labelTags',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'label',
                                'explode' => false,
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                            [
                                'name' => 'filter',
                                'in' => 'query',
                                'required' => false,
                                'style' => 'deepObject',
                                'explode' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => ['type' => 'string'],
                                ],
                            ],
                            [
                                'name' => 'reserved',
                                'in' => 'query',
                                'required' => false,
                                'allowReserved' => true,
                                'schema' => [
                                    'type' => 'string',
                                ],
                            ],
                            [
                                'name' => 'notReserved',
                                'in' => 'query',
                                'required' => false,
                                'schema' => [
                                    'type' => 'string',
                                ],
                            ],
                            [
                                'name' => 'emptyAllowed',
                                'in' => 'query',
                                'required' => false,
                                'allowEmptyValue' => true,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'emptyForbidden',
                                'in' => 'query',
                                'required' => false,
                                'allowEmptyValue' => false,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'emptySilent',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'StyleRuntime');

        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require_once $file;
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

    public function testMatrixLabelAndDeepObjectDeserialize(): void
    {
        $request = Request::create(
            uri: '/styles/;ids=1,2,3/.kind=dog.name=Rex/;matrixFlags=4;matrixFlags=5/.labelTags=a,b?filter[name]=Bob&filter[role]=admin&reserved=a+b',
            method: 'GET',
        );
        $request->attributes->set('ids', ';ids=1,2,3');
        $request->attributes->set('meta', '.kind=dog.name=Rex');
        $request->attributes->set('matrixFlags', ';matrixFlags=4;matrixFlags=5');
        $request->attributes->set('labelTags', '.labelTags=a,b');

        $dto = $this->deserializer->deserialize($request, 'StyleRuntime\StylesGetQueryParams');

        $this->assertSame([1, 2, 3], $dto->getIds());
        $this->assertSame(['kind' => 'dog', 'name' => 'Rex'], $dto->getMeta());
        $this->assertSame([4, 5], $dto->getMatrixFlags());
        $this->assertSame(['a', 'b'], $dto->getLabelTags());
        $this->assertSame(['name' => 'Bob', 'role' => 'admin'], $dto->getFilter());
        $this->assertSame('a+b', $dto->getReserved());
    }

    /**
     * The fixture DTO also has required path parameters; only the query string varies here.
     */
    private function styleRequest(string $queryString): Request
    {
        $request = Request::create('/styles/;ids=1/.kind=dog/;matrixFlags=4/.labelTags=a?' . $queryString, 'GET');
        $request->attributes->set('ids', ';ids=1');
        $request->attributes->set('meta', '.kind=dog');
        $request->attributes->set('matrixFlags', ';matrixFlags=4');
        $request->attributes->set('labelTags', '.labelTags=a');

        return $request;
    }

    /**
     * `testMatrixLabelAndDeepObjectDeserialize` covers the exploded spellings. The NON-exploded ones
     * take different code: per the spec `;p=k,v,k,v` is an object and `.a,,b` is a list whose empty
     * member counts, and a scalar arrives with the whole `;p=`/`.` prefix still attached. None of
     * those paths were exercised.
     */
    public function testNonExplodedMatrixAndLabelPathValuesParse(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/flat/{mScalar}/{lScalar}/{mObj}/{lList}' => [
                    'get' => [
                        'operationId' => 'flat',
                        'parameters' => [
                            [
                                'name' => 'mScalar',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'matrix',
                                'explode' => false,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'lScalar',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'label',
                                'explode' => false,
                                'schema' => ['type' => 'integer'],
                            ],
                            [
                                'name' => 'mObj',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'matrix',
                                'explode' => false,
                                'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                            ],
                            [
                                'name' => 'lList',
                                'in' => 'path',
                                'required' => true,
                                'style' => 'label',
                                'explode' => false,
                                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        $namespace = 'FlatStyleRuntime';
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        $request = Request::create('/flat/;mScalar=abc/.7/;mObj=kind,dog,name,Rex/.a,,b', 'GET');
        $request->attributes->set('mScalar', ';mScalar=abc');
        $request->attributes->set('lScalar', '.7');
        $request->attributes->set('mObj', ';mObj=kind,dog,name,Rex');
        $request->attributes->set('lList', '.a,,b');

        $dto = $this->deserializer->deserialize($request, $namespace . '\FlatGetQueryParams');

        // The style prefix is stripped, not treated as part of the value.
        $this->assertSame('abc', $dto->getMScalar());
        $this->assertSame(7, $dto->getLScalar());
        // Non-exploded object: consecutive tokens pair up into key/value.
        $this->assertSame(['kind' => 'dog', 'name' => 'Rex'], $dto->getMObj());
        // An empty member is kept — dropping it would silently change minItems/maxItems.
        $this->assertSame(['a', '', 'b'], $dto->getLList());
    }

    public function testEncodingObjectDecodesJsonPartAndSplitsDelimitedPart(): void
    {
        $spec = [
            'openapi' => '3.1.0',
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
                                        'required' => ['meta', 'tags'],
                                        'properties' => [
                                            'meta' => [
                                                'type' => 'object',
                                                'required' => ['title'],
                                                'properties' => ['title' => ['type' => 'string', 'minLength' => 3]],
                                            ],
                                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        ],
                                    ],
                                    'encoding' => [
                                        'meta' => ['contentType' => 'application/json'],
                                        'tags' => ['style' => 'form', 'explode' => false],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        (new GenerateDtoCommand())->generateFromArray($spec, $this->outputDirectory, 'EncodingRuntime');
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        $class = 'EncodingRuntime\UploadPostRequest';
        $this->assertSame(
            ['meta' => ['style' => 'json', 'explode' => false], 'tags' => ['style' => 'form', 'explode' => false]],
            call_user_func([$class, 'getParameterStyles']),
        );

        // A multipart body: the JSON part arrives as a string, the non-exploded array as "a,b".
        $dto = $this->deserializer->deserialize(
            Request::create('/upload', 'POST', ['meta' => '{"title":"Report"}', 'tags' => 'a,b']),
            $class,
        );

        $this->assertSame('Report', $dto->getMeta()->getTitle());
        $this->assertSame(['a', 'b'], $dto->getTags());

        // Constraints inside the decoded part are still enforced.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 3 characters/');
        $this->deserializer->deserialize(
            Request::create('/upload', 'POST', ['meta' => '{"title":"no"}', 'tags' => 'a']),
            $class,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generateQueryStringDto(string $namespace, string $mediaType): array
    {
        $spec = [
            'openapi' => '3.2.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [
                '/search' => [
                    'get' => [
                        'operationId' => 'search',
                        'parameters' => [
                            [
                                'name' => 'criteria',
                                'in' => 'querystring',
                                'content' => [
                                    $mediaType => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['q'],
                                            'properties' => [
                                                'q' => ['type' => 'string', 'minLength' => 2],
                                                'page' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ];

        // Own directory per namespace: the file names repeat and require_once keys on path.
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        $class = $namespace . '\SearchGetQueryParams';

        return [$class, call_user_func([$class, 'getParameterSources'])];
    }

    public function testQuerystringParameterBindsTheWholeFormEncodedQueryString(): void
    {
        [$class, $sources] = $this->generateQueryStringDto('QueryStringForm', 'application/x-www-form-urlencoded');

        $this->assertSame(['criteria' => 'querystring'], $sources);

        // The object schema of the parameter becomes its own DTO, populated from the query string.
        $dto = $this->deserializer->deserialize(Request::create('/search?q=cats&page=2', 'GET'), $class);
        $this->assertSame('cats', $dto->getCriteria()->getQ());
        $this->assertSame('2', $dto->getCriteria()->getPage());

        // No query string at all: an optional parameter stays unset instead of failing.
        $empty = $this->deserializer->deserialize(Request::create('/search', 'GET'), $class);
        $this->assertNull($empty->getCriteria());
    }

    public function testQuerystringParameterDecodesAJsonQueryString(): void
    {
        [$class] = $this->generateQueryStringDto('QueryStringJson', 'application/json');

        $dto = $this->deserializer->deserialize(
            Request::create('/search?' . rawurlencode('{"q":"cats","page":"2"}'), 'GET'),
            $class,
        );

        $this->assertSame('cats', $dto->getCriteria()->getQ());
        $this->assertSame('2', $dto->getCriteria()->getPage());
    }

    public function testQuerystringParameterStillValidatesTheDecodedValue(): void
    {
        [$class] = $this->generateQueryStringDto('QueryStringInvalid', 'application/x-www-form-urlencoded');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 2 characters/');
        $this->deserializer->deserialize(Request::create('/search?q=x', 'GET'), $class);
    }

    public function testAllowEmptyValueTrueAcceptsAnEmptyQueryValue(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->styleRequest('emptyAllowed='),
            'StyleRuntime\StylesGetQueryParams',
        );

        $this->assertSame('', $dto->getEmptyAllowed());
    }

    public function testAllowEmptyValueFalseRejectsAnEmptyQueryValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parameter "emptyForbidden" does not allow an empty value.');

        $this->deserializer->deserialize(
            $this->styleRequest('emptyForbidden='),
            'StyleRuntime\StylesGetQueryParams',
        );
    }

    public function testAllowEmptyValueFalseStillAcceptsANonEmptyValue(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->styleRequest('emptyForbidden=x'),
            'StyleRuntime\StylesGetQueryParams',
        );

        $this->assertSame('x', $dto->getEmptyForbidden());
    }

    public function testParameterWithoutTheKeywordKeepsTheLegacyEmptyStringBehaviour(): void
    {
        // The spec says nothing, so `?emptySilent=` stays an empty string instead of being
        // rejected — tightening that would change every existing DTO.
        $dto = $this->deserializer->deserialize(
            $this->styleRequest('emptySilent='),
            'StyleRuntime\StylesGetQueryParams',
        );

        $this->assertSame('', $dto->getEmptySilent());
    }

    public function testAllowEmptyValueMetadataIsTriState(): void
    {
        $map = call_user_func(['StyleRuntime\StylesGetQueryParams', 'getParameterAllowEmptyValue']);

        $this->assertSame(['emptyAllowed' => true, 'emptyForbidden' => false], $map);
    }

    /**
     * An `allowReserved` parameter reads a second, raw parse of the query string — the only one that
     * keeps a literal `+`. That parse must produce the SAME SHAPE as Symfony's query bag, or a
     * parameter would see a different structure depending on one unrelated flag. Two defects made it
     * differ: a greedy bracket regex read `foo[bar][baz]` as the single key `foo[bar]` with subkey
     * `baz`, and the type-narrowing pass dropped integer keys, so every `foo[]` list arrived empty.
     *
     * The expectations are `parse_str()` itself, which is what Symfony uses.
     */
    #[DataProvider('rawQueryShapeProvider')]
    public function testRawQueryParsingMatchesTheDefaultQueryShape(string $queryString): void
    {
        $parse = new ReflectionMethod(DtoDeserializer::class, 'getRawQueryData');

        /** @var array<array-key, mixed> $actual */
        $actual = $parse->invoke($this->deserializer, Request::create('/?' . $queryString, 'GET'));

        parse_str($queryString, $expected);
        $this->assertSame($expected, $actual, $queryString);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rawQueryShapeProvider(): array
    {
        return [
            'flat' => ['a=1&b=2'],
            'list' => ['foo[]=1&foo[]=2'],
            'one level' => ['foo[bar]=1'],
            'two levels' => ['foo[bar][baz]=1'],
            'siblings at two levels' => ['foo[bar][baz]=1&foo[bar][qux]=2&foo[other]=3'],
            'four levels' => ['deep[a][b][c][d]=x'],
            'list of objects' => ['foo[][bar]=1&foo[][bar]=2'],
            'scalar then container' => ['a=1&a[b]=2'],
            'explicit numeric keys' => ['arr[2]=x&arr[0]=y'],
            'valueless key' => ['k'],
            'empty value' => ['k='],
            // Malformed keys: parse_str has its own rules and they are matched, not guessed.
            'unterminated bracket' => ['noclose[a=1'],
            'garbage after a group' => ['weird[a]x[b]=1'],
        ];
    }

    /**
     * The reason the raw parse exists at all: `+` stays a plus for an `allowReserved` parameter and
     * becomes a space for every other one, from the same request.
     */
    public function testAllowReservedKeepsThePlusSignWhileOtherParametersDecodeIt(): void
    {
        $dto = $this->deserializer->deserialize(
            $this->styleRequest('reserved=a+b&notReserved=a+b'),
            'StyleRuntime\StylesGetQueryParams',
        );

        $this->assertSame('a+b', $dto->getReserved());
        $this->assertSame('a b', $dto->getNotReserved());
    }
}
