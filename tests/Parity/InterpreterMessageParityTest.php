<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Tests\GenerationMode;
use OpenapiPhpDtoGenerator\Tests\LaravelData\LaravelDataContainer;
use OpenapiPhpDtoGenerator\Tests\Yii3\Yii3Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Throwable;

/**
 * The parity suites compare VERDICTS. This one compares the SENTENCE.
 *
 * Keywords Laravel and Symfony have no rule for are enforced by the same interpreter in all three
 * modes, so their message is written by this package — and must therefore read identically wherever
 * it comes from. Only the subject differs, by design and per mode:
 *
 *     runtime   param "f" must contain unique items
 *     symfony   field "f" must contain unique items
 *     laravel   f must contain unique items
 *
 * Laravel keys its error bag by path, so the message carries a bare path; the other two carry the
 * subject inside the sentence because they surface as a single exception / violation message.
 *
 * Keywords a framework DOES have a rule for are deliberately absent here: `exclusiveMinimum` reads
 * "This value should be greater than 3." in Symfony mode and `multipleOf` reads `validation.multiple_of`
 * in Laravel mode, because those are the framework's own constraints — a user's translations apply, and
 * overriding them would be worse than the divergence.
 */
final class InterpreterMessageParityTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-message-parity';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->outputDirectory);
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    #[DataProvider('interpreterOwnedProvider')]
    public function testTheSentenceIsTheSameInEveryMode(
        string $key,
        array $propertySchema,
        string $invalidJson,
        string $expectedSentence,
    ): void {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => ['f' => $propertySchema],
                    ],
                ],
            ],
        ];

        foreach (GenerationMode::cases() as $mode) {
            $reported = $this->messages($mode, $spec, $key, $invalidJson);
            $this->assertNotSame([], $reported, sprintf('%s mode reported nothing for %s', $mode->value, $key));

            $sentences = array_map(
                fn(string $message): string => $this->withoutSubject($message, $mode),
                $reported,
            );
            $this->assertContains(
                $expectedSentence,
                $sentences,
                sprintf(
                    "%s mode words %s differently\n expected: %s\n reported: %s",
                    $mode->value,
                    $key,
                    $expectedSentence,
                    implode(' | ', $reported),
                ),
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function interpreterOwnedProvider(): array
    {
        $cases = [
            'contains' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'contains' => ['type' => 'integer', 'minimum' => 5],
                ],
                '{"f":[1,2]}',
                "must contain at least 1 item(s) matching the 'contains' schema",
            ],
            'minContains' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'contains' => ['type' => 'integer', 'minimum' => 5],
                    'minContains' => 2,
                ],
                '{"f":[5,1]}',
                "must contain at least 2 item(s) matching the 'contains' schema",
            ],
            'maxContains' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'contains' => ['type' => 'integer', 'minimum' => 5],
                    'maxContains' => 1,
                ],
                '{"f":[5,6]}',
                "must contain at most 1 item(s) matching the 'contains' schema",
            ],
            'not' => [
                ['not' => ['type' => 'string', 'pattern' => '^a']],
                '{"f":"ab"}',
                "must not match the 'not' schema",
            ],
            'uniqueItems over objects' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
                    'uniqueItems' => true,
                ],
                '{"f":[{"a":1},{"a":1}]}',
                'must contain unique items',
            ],
            'propertyNames' => [
                [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                    'propertyNames' => ['pattern' => '^x'],
                ],
                '{"f":{"y":1}}',
                'key "y" must match pattern ^x',
            ],
        ];

        $withKey = [];
        foreach ($cases as $key => [$schema, $json, $sentence]) {
            $withKey[$key] = [$key, $schema, $json, $sentence];
        }

        return $withKey;
    }

    /**
     * A nested `required` is the one place the subject itself differs beyond the prefix: runtime reports
     * `param "f".b`, Laravel `f.b`, and Symfony just `field "b"` — its constraint sits on the nested
     * DTO's own class, so the parent path is carried by the violation's `getPropertyPath()` rather than
     * by the sentence. The claim is the same in all three; where it hangs is not.
     */
    public function testANestedRequiredNamesTheMissingKeyInEveryMode(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'type' => 'object',
                                'properties' => [
                                    'a' => ['type' => 'integer'],
                                    'b' => ['type' => 'integer'],
                                ],
                                'dependentRequired' => ['a' => ['b']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $key = 'nested dependentRequired';
        $json = '{"f":{"a":1}}';

        foreach (GenerationMode::cases() as $mode) {
            $messages = $this->messages($mode, $spec, $key, $json);

            $matching = array_filter(
                $messages,
                static fn(string $message): bool => str_ends_with($message, 'b is required when a is present')
                    || str_ends_with($message, '"b" is required when a is present'),
            );
            $this->assertNotSame(
                [],
                $matching,
                sprintf('%s mode worded it differently: %s', $mode->value, implode(' | ', $messages)),
            );
        }
    }

    /**
     * A union that gates every branch out by type has no branch reason to report, and the bare
     * "does not match any oneOf branch" left the caller guessing. Naming the accepted types and the one
     * received is the same sentence in the runtime validator and in the emitted interpreter.
     */
    public function testAUnionMismatchNamesTheAcceptedTypes(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['f'],
                        'properties' => [
                            'f' => [
                                'oneOf' => [
                                    ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
                                    ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Only Laravel mode reaches the interpreter here: the other two bind the payload into typed PHP
        // first, and a bool is refused by the property type before any constraint runs.
        $laravel = $this->messages(GenerationMode::Laravel, $spec, 'union mismatch', '{"f":true}');
        $this->assertContains(
            'f does not match any oneOf branch (expected object or integer, got boolean)',
            $laravel,
        );

        // The runtime validator writes the same sentence when it is handed the value directly — asserted
        // against its own API in DtoValidatorTest, which is where that entry point is covered.
    }

    /**
     * ONE violation, ONE message — in every mode.
     *
     * The suite's other tests ask whether a sentence is PRESENT, which cannot see a second copy of it. Two
     * modes have already shipped a duplicate: Laravel mode reported a rule-expressible keyword twice (the
     * framework's message plus the interpreter's, fixed by `laravelPruneRuleCoveredKeywords()`), and
     * laravel-data mode reported a missing nested key twice — `{"tags.0.id": ["validation.present"]}` from
     * the nested rule resolution it injects on top of the emitted `rules()`, plus `tags[0].id is required`
     * from the interpreter. Neither was visible to a verdict comparison: both modes correctly REJECTED the
     * payload, just twice over.
     *
     * A nested `required` is the probe because it is the one keyword every mode enforces from a different
     * layer, which is exactly where two layers can both speak up.
     */
    public function testOneViolationIsReportedOnceInEveryMode(): void
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('symfony/validator not installed');
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Probe' => [
                        'type' => 'object',
                        'required' => ['tags'],
                        'properties' => [
                            'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                        ],
                    ],
                    'Tag' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        foreach (GenerationMode::cases() as $mode) {
            $messages = $this->messages($mode, $spec, 'one violation one message', '{"tags":[{}]}');

            $this->assertCount(
                1,
                $messages,
                sprintf(
                    "%s mode reports one missing nested key %d times:\n %s",
                    $mode->value,
                    count($messages),
                    implode("\n ", $messages),
                ),
            );
        }
    }

    /**
     * How each mode names the subject of the sentence. Stripping it is what lets the CLAIM be compared
     * across modes whose surfaces differ by design (a single exception message, a violation, an error
     * bag keyed by path).
     */
    private function withoutSubject(string $message, GenerationMode $mode): string
    {
        $prefix = match ($mode) {
            GenerationMode::Runtime => 'param "f" ',
            GenerationMode::Symfony => 'field "f" ',
            GenerationMode::Laravel => 'f ',
            // Same as Laravel mode: the error bag is keyed by path, so the sentence carries a bare path.
            GenerationMode::LaravelData => 'f ',
            // The interpreter is entered from a class-level #[Callback] with the OBJECT payload — the
            // same view Symfony mode feeds it — so the sentence names the field the same way.
            GenerationMode::Yii3 => 'field "f" ',
        };

        return str_starts_with($message, $prefix) ? substr($message, strlen($prefix)) : $message;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function messages(GenerationMode $mode, array $spec, string $key, string $json): array
    {
        return match ($mode) {
            GenerationMode::Runtime => $this->runtimeMessages($spec, $key, $json),
            GenerationMode::Symfony => $this->symfonyMessages($spec, $key, $json),
            GenerationMode::Laravel => $this->laravelMessages($spec, $key, $json),
            GenerationMode::LaravelData => $this->laravelDataMessages($spec, $key, $json),
            GenerationMode::Yii3 => $this->yii3Messages($spec, $key, $json),
        };
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function runtimeMessages(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Runtime, $key), 'runtime');
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);

        try {
            (new DtoNormalizer())->validateAndNormalizeToArray(
                (new DtoDeserializer())->deserialize($request, $fqcn),
            );
        } catch (Throwable $exception) {
            // The runtime validator joins its errors into one exception message, one per line.
            return array_values(array_filter(array_map('trim', explode("\n", $exception->getMessage()))));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function symfonyMessages(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Symfony, $key), 'symfony');

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            [
                new ObjectNormalizer(
                    $classMetadataFactory,
                    new MetadataAwareNameConverter($classMetadataFactory),
                    null,
                    $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            [new JsonEncoder()],
        );
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        try {
            $dto = $serializer->deserialize($json, $fqcn, 'json');
        } catch (Throwable $exception) {
            return [$exception->getMessage()];
        }

        $messages = [];
        foreach ($validator->validate($dto) as $violation) {
            $messages[] = (string)$violation->getMessage();
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function laravelMessages(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Laravel, $key), 'laravel');

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $rules */
        $rules = call_user_func([$fqcn, 'rules']);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
        if (method_exists($fqcn, 'withValidator')) {
            call_user_func([$fqcn, 'withValidator'], $validator, $json);
        }

        /** @var array<int, string> $all */
        $all = $validator->errors()->all();

        return $all;
    }

    /**
     * The same interpreter as Laravel mode, reached through laravel-data's own validation. The messages
     * must read identically — that is the claim this suite exists to hold — and the only reason a
     * separate implementation is needed is the entry point: a request, because
     * `validation_strategy` is `OnlyRequests`, and because the interpreter reads the raw body from it.
     *
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    /**
     * The messages the emitted interpreter produces in yii3 mode.
     *
     * Only the interpreter's own are compared: a native `yiisoft/validator` rule speaks Yii's language
     * (and your translations), exactly as the other framework modes speak theirs, so those are not
     * parity material. The interpreter's sentences ARE ours in every mode and must match.
     *
     * @param array<string, mixed> $spec
     * @return array<int, string>
     */
    private function yii3Messages(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::Yii3, $key), 'yii3');
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return [];
        }

        $container = new Yii3Container();

        try {
            $result = $container->validate($container->hydrate($fqcn, $payload));
        } catch (Throwable) {
            return [];
        }

        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }

    private function laravelDataMessages(array $spec, string $key, string $json): array
    {
        $fqcn = $this->generate($spec, $this->namespaceFor(GenerationMode::LaravelData, $key), 'laravel-data');
        LaravelDataContainer::boot();

        /** @var array<int, string> $messages */
        $messages = LaravelDataContainer::withRequest($json, static function (LaravelRequest $request) use ($fqcn): array {
            try {
                $fqcn::from($request);
            } catch (ValidationException $exception) {
                $flattened = [];
                foreach ($exception->errors() as $perPath) {
                    foreach ($perPath as $message) {
                        $flattened[] = $message;
                    }
                }

                return $flattened;
            }

            return [];
        });

        return $messages;
    }

    /**
     * @param array<string, mixed> $spec
     * @return class-string
     */
    private function generate(array $spec, string $namespace, string $mode): string
    {
        $target = $this->outputDirectory . '/' . $namespace;
        if (!is_dir($target)) {
            mkdir($target, 0o755, true);
        }

        (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        return $fqcn;
    }

    private function namespaceFor(GenerationMode $mode, string $key): string
    {
        return 'Msg' . $mode->tag() . $this->namespaceSuffix($key);
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
