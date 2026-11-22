<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which classes runtime mode marks as arriving in JSON bodies only, in the spellings that are not a
 * plain local `$ref`: a component generated into a place several documents share, a body that names
 * a union, a body reached through a `requestBodies` or `mediaTypes` component of the same or another
 * file. A form post the document allows must be read wherever its class is generated; a class every
 * operation names under JSON only keeps refusing the form fields.
 */
final class JsonOnlyAttributionTest extends TestCase
{
    private const string FORM = 'application/x-www-form-urlencoded';

    private string $root;
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opg-json-only-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $workingDirectory = getcwd();
        $this->assertIsString($workingDirectory);
        $this->workingDirectory = $workingDirectory;
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->root);
    }

    // --- a component generated into a place several documents share ----------------------------

    /**
     * The README's setup for a component two documents share: one mask run, the shared directory
     * mapped with `--ref`. One document posts the component as JSON, the other as a form (or only
     * returns it). The shared file must come out the same from both, so the run succeeds, and the
     * class reads the form post the second document declares.
     */
    #[DataProvider('secondDocumentUses')]
    public function testMaskRunSharingARefTargetGeneratesTheSharedClassOnce(string $usersUse): void
    {
        $this->writeSharedDocuments(usersUse: $usersUse);
        $namespace = 'JsonOnly' . bin2hex(random_bytes(6));

        $tester = $this->generate(options: [
            '--file' => 'specs/*/api.json',
            '--directory' => 'out/{1}',
            '--namespace' => $namespace . '\{1}',
            '--ref' => ['specs/shared=out/Shared=' . $namespace . '\Shared'],
        ]);

        $this->assertSame(expected: 0, actual: $tester->getStatusCode(), message: $tester->getDisplay());
        $this->autoload(namespace: $namespace, directory: $this->root . '/out');
        $widget = $namespace . '\Shared\Widget';
        $this->assertSame('{"name":"ab"}', $this->deserializeForm(class: $widget, body: 'name=ab', fields: ['name' => 'ab'])->toJson());
        $this->assertSame('{"name":"ab"}', $this->deserializeJson(class: $widget, body: '{"name":"ab"}')->toJson());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secondDocumentUses(): iterable
    {
        yield 'a form body' => ['form'];
        yield 'a response only' => ['response'];
    }

    /**
     * The same documents in one mask run, in every mode: the shared file is one file, whatever mode
     * writes it.
     */
    #[DataProvider('modes')]
    public function testMaskRunSharingARefTargetSucceedsInEveryMode(GenerationMode $mode): void
    {
        $this->writeSharedDocuments(usersUse: 'form');

        $tester = $this->generate(options: [
            '--file' => 'specs/*/api.json',
            '--directory' => 'out/{1}',
            '--namespace' => 'Acme\{1}',
            '--attributes' => $mode->value,
            '--ref' => ['specs/shared=out/Shared=Acme\Shared'],
        ]);

        $this->assertSame(expected: 0, actual: $tester->getStatusCode(), message: $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{GenerationMode}>
     */
    public static function modes(): iterable
    {
        foreach (GenerationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    /**
     * One run per document: whichever document runs last used to decide what the shared class
     * accepts. The file is the same in either order.
     */
    public function testSeparateRunsWriteTheSameSharedFileInEitherOrder(): void
    {
        $this->writeSharedDocuments(usersUse: 'form');

        $written = [];
        foreach (['users-first' => ['users', 'billing'], 'billing-first' => ['billing', 'users']] as $order => $services) {
            foreach ($services as $service) {
                $tester = $this->generate(options: [
                    '--file' => 'specs/' . $service . '/api.json',
                    '--directory' => $order . '/' . ucfirst($service),
                    '--namespace' => 'Acme\\' . ucfirst($service),
                    '--ref' => ['specs/shared=' . $order . '/Shared=Acme\Shared'],
                ]);
                $this->assertSame(expected: 0, actual: $tester->getStatusCode(), message: $tester->getDisplay());
            }
            $written[$order] = (string)file_get_contents($order . '/Shared/Widget.php');
        }

        $this->assertNotSame('', $written['users-first']);
        $this->assertSame(expected: $written['users-first'], actual: $written['billing-first']);
    }

    /**
     * Only the shared class gives the mark up: a component of the document's own directory, posted
     * as JSON only in the same run, still refuses the form fields.
     */
    public function testLocalJsonOnlyClassInTheSameMaskRunStaysStrict(): void
    {
        $this->writeSharedDocuments(usersUse: 'form');
        $namespace = 'JsonOnly' . bin2hex(random_bytes(6));

        $tester = $this->generate(options: [
            '--file' => 'specs/*/api.json',
            '--directory' => 'out/{1}',
            '--namespace' => $namespace . '\{1}',
            '--ref' => ['specs/shared=out/Shared=' . $namespace . '\Shared'],
        ]);

        $this->assertSame(expected: 0, actual: $tester->getStatusCode(), message: $tester->getDisplay());
        $this->autoload(namespace: $namespace, directory: $this->root . '/out');
        $gadget = $namespace . '\Billing\Gadget';
        $this->assertRefused(
            call: fn(): mixed => $this->deserializeForm(class: $gadget, body: 'name=ab', fields: ['name' => 'ab']),
            message: 'Required parameter "name" not found',
        );
        $this->assertSame('{"name":"ab"}', $this->deserializeJson(class: $gadget, body: '{"name":"ab"}')->toJson());
    }

    // --- a body that names a union --------------------------------------------------------------

    /**
     * A form body naming a union arrives as one of its members — the base itself is abstract or an
     * interface and is never what a caller deserializes. So each member reads the form post, even
     * when another operation posts it as JSON, and still reads JSON. The union written inline under
     * the form type is the same body in another spelling.
     */
    #[DataProvider('unionFormBodies')]
    public function testFormBodyNamingAUnionLetsEveryMemberReadTheForm(string $keyword, bool $discriminator, bool $inline): void
    {
        $namespace = $this->generateUnionBodies(keyword: $keyword, discriminator: $discriminator, formBodyInline: $inline);

        foreach (['Cat' => 'cat', 'Dog' => 'dog'] as $class => $kind) {
            $form = $this->deserializeForm(
                class: $namespace . '\\' . $class,
                body: 'name=ab&kind=' . $kind,
                fields: ['name' => 'ab', 'kind' => $kind],
            );
            $this->assertSame(expected: '{"name":"ab","kind":"' . $kind . '"}', actual: $form->toJson(), message: $class);
            $json = $this->deserializeJson(class: $namespace . '\\' . $class, body: '{"name":"ab","kind":"' . $kind . '"}');
            $this->assertSame(expected: '{"name":"ab","kind":"' . $kind . '"}', actual: $json->toJson(), message: $class);
        }
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function unionFormBodies(): iterable
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ([true, false] as $discriminator) {
                foreach ([false, true] as $inline) {
                    $label = sprintf('%s %s discriminator, %s', $keyword, $discriminator ? 'with' : 'without', $inline ? 'inline' : '$ref');
                    yield $label => [$keyword, $discriminator, $inline];
                }
            }
        }
    }

    /**
     * The other half: a union every operation names under JSON only. Cat, also posted directly as
     * JSON, refuses the form fields as before.
     */
    #[DataProvider('unionJsonBodies')]
    public function testJsonBodyNamingAUnionKeepsADirectlyPostedMemberStrict(string $keyword, bool $discriminator): void
    {
        $this->assertMembersRefuseTheForm(keyword: $keyword, discriminator: $discriminator, members: ['Cat' => 'cat']);
    }

    /**
     * Dog is reached through the JSON union alone. It is what that body is hydrated into, so it is
     * posted as JSON only, and refuses the form fields like Cat — before, the union recorded nothing
     * for its members and Dog read a form no operation declared.
     */
    #[DataProvider('unionJsonBodies')]
    public function testJsonBodyNamingAUnionMarksAMemberReachedOnlyThroughIt(string $keyword, bool $discriminator): void
    {
        $this->assertMembersRefuseTheForm(keyword: $keyword, discriminator: $discriminator, members: ['Dog' => 'dog']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function unionJsonBodies(): iterable
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ([true, false] as $discriminator) {
                yield sprintf('%s %s discriminator', $keyword, $discriminator ? 'with' : 'without') => [$keyword, $discriminator];
            }
        }
    }

    /**
     * @param array<string, string> $members class => its `kind`
     */
    private function assertMembersRefuseTheForm(string $keyword, bool $discriminator, array $members): void
    {
        $namespace = $this->generateUnionBodies(keyword: $keyword, discriminator: $discriminator, formBodyInline: null);

        foreach ($members as $class => $kind) {
            $this->assertRefused(
                call: fn(): mixed => $this->deserializeForm(
                    class: $namespace . '\\' . $class,
                    body: 'name=ab&kind=' . $kind,
                    fields: ['name' => 'ab', 'kind' => $kind],
                ),
                message: 'Required parameter "name" not found',
            );
            $json = $this->deserializeJson(class: $namespace . '\\' . $class, body: '{"name":"ab","kind":"' . $kind . '"}');
            $this->assertSame(expected: '{"name":"ab","kind":"' . $kind . '"}', actual: $json->toJson(), message: $class);
        }
    }

    // --- a body reached through a requestBodies or mediaTypes component -------------------------

    /**
     * A form body may reach its class through a `requestBodies` component or an OAS 3.2 `mediaTypes`
     * component, spelled locally, through the document's own file name, or in another file. Each is
     * the same form body, so the class reads the form post — and the JSON one another operation sends.
     */
    #[DataProvider('referencedFormBodies')]
    public function testFormBodyThroughAComponentReferenceIsAttributed(string $class, string $path): void
    {
        $namespace = $this->generateComponentBodies();

        $form = $this->deserializeForm(class: $namespace . '\\' . $class, body: 'name=ab', fields: ['name' => 'ab'], uri: $path);
        $this->assertSame(expected: '{"name":"ab"}', actual: $form->toJson(), message: $path);
        $json = $this->deserializeJson(class: $namespace . '\\' . $class, body: '{"name":"ab"}');
        $this->assertSame(expected: '{"name":"ab"}', actual: $json->toJson(), message: $path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referencedFormBodies(): iterable
    {
        yield 'local requestBodies' => ['Alpha', '/alpha/form'];
        yield 'self-prefixed requestBodies' => ['Beta', '/beta/form'];
        yield 'requestBodies of another file' => ['Gamma', '/gamma/form'];
        yield 'requestBodies of another file, schema local to it' => ['Gadget', '/gadget/form'];
        yield 'local mediaTypes' => ['Delta', '/delta/form'];
        yield 'self-prefixed mediaTypes' => ['Epsilon', '/epsilon/form'];
        yield 'mediaTypes of another file' => ['Zeta', '/zeta/form'];
    }

    /**
     * The same component spellings under a JSON type: the class is still posted as JSON only, and
     * refuses the form fields.
     */
    public function testJsonBodyThroughAComponentReferenceStaysStrict(): void
    {
        $namespace = $this->generateComponentBodies();

        $this->assertRefused(
            call: fn(): mixed => $this->deserializeForm(class: $namespace . '\Lambda', body: 'name=ab', fields: ['name' => 'ab']),
            message: 'Required parameter "name" not found',
        );
        $this->assertSame('{"name":"ab"}', $this->deserializeJson(class: $namespace . '\Lambda', body: '{"name":"ab"}')->toJson());
    }

    // --- documents ------------------------------------------------------------------------------

    /**
     * `specs/shared/common.json` declares Widget. `billing` posts it as JSON and has a JSON-only
     * Gadget of its own; `users` posts Widget as a form, or only returns it.
     */
    private function writeSharedDocuments(string $usersUse): void
    {
        $fields = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $widget = ['$ref' => '../shared/common.json#/components/schemas/Widget'];
        $this->writeJson(path: 'specs/shared/common.json', document: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Shared', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => ['Widget' => $fields]],
        ]);
        $this->writeJson(path: 'specs/billing/api.json', document: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Billing', 'version' => '1.0.0'],
            'paths' => [
                '/widgets' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => $widget]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/gadgets' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Gadget']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
            'components' => ['schemas' => ['Gadget' => $fields]],
        ]);
        $usersPath = match ($usersUse) {
            'form' => ['post' => [
                'requestBody' => ['content' => [self::FORM => ['schema' => $widget]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]],
            'response' => ['get' => [
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => $widget]]]],
            ]],
        };
        $this->writeJson(path: 'specs/users/api.json', document: [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Users', 'version' => '1.0.0'],
            'paths' => ['/widgets' => $usersPath],
        ]);
    }

    /**
     * Cat and Dog, and Pet, a union of the two. Cat is posted as JSON on its own; Pet is posted as a
     * form — by `$ref`, or written inline — or, with `$formBodyInline` null, as JSON only.
     */
    private function generateUnionBodies(string $keyword, bool $discriminator, ?bool $formBodyInline): string
    {
        $animal = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string']]];
        $members = [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']];
        $union = [$keyword => $members];
        if ($discriminator) {
            $union['discriminator'] = ['propertyName' => 'kind', 'mapping' => [
                'cat' => '#/components/schemas/Cat',
                'dog' => '#/components/schemas/Dog',
            ]];
        }
        $petBody = match ($formBodyInline) {
            null => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
            false => [self::FORM => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
            true => [self::FORM => ['schema' => $union]],
        };

        return $this->generateArray(openApi: [
            'paths' => [
                '/cats' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Cat']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/pets' => ['post' => [
                    'requestBody' => ['content' => $petBody],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
            'components' => ['schemas' => ['Cat' => $animal, 'Dog' => $animal, 'Pet' => $union]],
        ]);
    }

    /**
     * Each class from Alpha to Zeta (and Gadget, a component of `bodies.json`) is posted as JSON
     * directly and as a form through one `requestBodies` / `mediaTypes` spelling; Lambda is posted
     * as JSON only, through those spellings.
     */
    private function generateComponentBodies(): string
    {
        $fields = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $post = static fn(array $requestBody): array => ['post' => [
            'requestBody' => $requestBody,
            'responses' => ['200' => ['description' => 'ok']],
        ]];
        $jsonOf = static fn(string $ref): array => ['content' => ['application/json' => ['schema' => ['$ref' => $ref]]]];
        $formOf = static fn(string $ref): array => ['content' => [self::FORM => ['schema' => ['$ref' => $ref]]]];

        $this->writeJson(path: 'specs/bodies.json', document: [
            'openapi' => '3.2.0',
            'info' => ['title' => 'Bodies', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => ['Gadget' => $fields],
                'requestBodies' => [
                    'GammaForm' => $formOf('main.json#/components/schemas/Gamma'),
                    'GadgetForm' => $formOf('#/components/schemas/Gadget'),
                    'LambdaJson' => $jsonOf('main.json#/components/schemas/Lambda'),
                ],
                'mediaTypes' => [
                    'ZetaForm' => ['schema' => ['$ref' => 'main.json#/components/schemas/Zeta']],
                ],
            ],
        ]);
        $this->writeJson(path: 'specs/main.json', document: [
            'openapi' => '3.2.0',
            'info' => ['title' => 'Components', 'version' => '1.0.0'],
            'paths' => [
                '/alpha' => $post($jsonOf('#/components/schemas/Alpha')),
                '/alpha/form' => $post(['$ref' => '#/components/requestBodies/AlphaForm']),
                '/beta' => $post($jsonOf('#/components/schemas/Beta')),
                '/beta/form' => $post(['$ref' => 'main.json#/components/requestBodies/BetaForm']),
                '/gamma' => $post($jsonOf('#/components/schemas/Gamma')),
                '/gamma/form' => $post(['$ref' => 'bodies.json#/components/requestBodies/GammaForm']),
                '/gadget' => $post($jsonOf('bodies.json#/components/schemas/Gadget')),
                '/gadget/form' => $post(['$ref' => 'bodies.json#/components/requestBodies/GadgetForm']),
                '/delta' => $post($jsonOf('#/components/schemas/Delta')),
                '/delta/form' => $post(['content' => [self::FORM => ['$ref' => '#/components/mediaTypes/DeltaForm']]]),
                '/epsilon' => $post($jsonOf('#/components/schemas/Epsilon')),
                '/epsilon/form' => $post(['content' => [self::FORM => ['$ref' => 'main.json#/components/mediaTypes/EpsilonForm']]]),
                '/zeta' => $post($jsonOf('#/components/schemas/Zeta')),
                '/zeta/form' => $post(['content' => [self::FORM => ['$ref' => 'bodies.json#/components/mediaTypes/ZetaForm']]]),
                '/lambda' => $post($jsonOf('#/components/schemas/Lambda')),
                '/lambda/self' => $post(['$ref' => 'main.json#/components/requestBodies/LambdaJson']),
                '/lambda/other' => $post(['$ref' => 'bodies.json#/components/requestBodies/LambdaJson']),
                '/lambda/media' => $post(['content' => ['application/json' => ['$ref' => 'main.json#/components/mediaTypes/LambdaJson']]]),
            ],
            'components' => [
                'schemas' => [
                    'Alpha' => $fields,
                    'Beta' => $fields,
                    'Gamma' => $fields,
                    'Delta' => $fields,
                    'Epsilon' => $fields,
                    'Zeta' => $fields,
                    'Lambda' => $fields,
                ],
                'requestBodies' => [
                    'AlphaForm' => $formOf('#/components/schemas/Alpha'),
                    'BetaForm' => $formOf('#/components/schemas/Beta'),
                    'LambdaJson' => $jsonOf('#/components/schemas/Lambda'),
                ],
                'mediaTypes' => [
                    'DeltaForm' => ['schema' => ['$ref' => '#/components/schemas/Delta']],
                    'EpsilonForm' => ['schema' => ['$ref' => '#/components/schemas/Epsilon']],
                    'LambdaJson' => ['schema' => ['$ref' => '#/components/schemas/Lambda']],
                ],
            ],
        ]);

        $namespace = 'JsonOnly' . bin2hex(random_bytes(6));
        (new GenerateDtoCommand())->generateFromFile(
            filePath: $this->root . '/specs/main.json',
            outputDirectory: $this->root . '/' . $namespace,
            namespace: $namespace,
        );
        $this->autoload(namespace: $namespace, directory: $this->root . '/' . $namespace);

        return $namespace;
    }

    // --- plumbing -------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $openApi
     */
    private function generateArray(array $openApi): string
    {
        $namespace = 'JsonOnly' . bin2hex(random_bytes(6));
        $directory = $this->root . '/' . $namespace;
        (new GenerateDtoCommand())->generateFromArray(
            openApi: ['openapi' => '3.1.0', 'info' => ['title' => 'Unions', 'version' => '1.0.0'], ...$openApi],
            outputDirectory: $directory,
            namespace: $namespace,
        );
        $this->autoload(namespace: $namespace, directory: $directory);

        return $namespace;
    }

    /**
     * @param array<string, string|list<string>> $options
     */
    private function generate(array $options): CommandTester
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $tester->execute($options);

        return $tester;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeJson(string $path, array $document): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(directory: dirname($path), recursive: true);
        }
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function autoload(string $namespace, string $directory): void
    {
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            $file = $directory . '/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            if (str_starts_with($class, $namespace . '\\') && is_file($file)) {
                require_once $file;
            }
        });
    }

    private function deserializeJson(string $class, string $body): mixed
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    /**
     * @param array<string, mixed> $fields what PHP itself parsed into `$_POST`
     */
    private function deserializeForm(string $class, string $body, array $fields, string $uri = '/'): mixed
    {
        $request = Request::create(
            uri: $uri,
            method: 'POST',
            parameters: $fields,
            server: ['CONTENT_TYPE' => self::FORM],
            content: $body,
        );

        return (new DtoDeserializer())->deserialize($request, $class);
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail('The form fields must not fill a class posted as JSON only.');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
