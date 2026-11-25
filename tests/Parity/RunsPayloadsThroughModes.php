<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Composer\InstalledVersions;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\InvalidArgumentException as SerializerInvalidArgumentException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedPropertyException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Throwable;

/**
 * Generate a spec in one mode and run a JSON payload through that mode's real framework flow.
 *
 * Each `*Outcome()` is the request path an application takes — runtime through `DtoDeserializer` +
 * `DtoNormalizer`, Symfony through the serializer + validator, Laravel through the emitted rules,
 * `withValidator()` and `fromValidated()`, laravel-data through `from($request)`, yii3 through the
 * `ValidatingHydrator` — and answers with a three-valued `PayloadOutcome`: accepted, refused the way
 * that framework refuses (a 422), or crashed (anything else: a 500 in production).
 *
 * The using class sets `$outputDirectory` before generating; `$siblingDocuments` is optional.
 */
trait RunsPayloadsThroughModes
{
    private string $outputDirectory;

    /**
     * Documents written beside the spec, by file name: when there are any, the spec is written to a
     * file next to them and generated from there, so its `$ref`s can name them.
     *
     * @var array<string, mixed>
     */
    private array $siblingDocuments = [];

    /**
     * The context `#[MapRequestPayload]` deserializes with (`RequestPayloadValueResolver::CONTEXT_DESERIALIZE`):
     * type errors are collected into one `PartialDenormalizationException`, which the resolver turns into a 422.
     */
    private const array SYMFONY_REQUEST_PAYLOAD_CONTEXT = ['collect_denormalization_errors' => true];

    /**
     * The one place a mode name turns into an implementation. No `default` arm on purpose: a mode added
     * to `GenerationMode` without an outcome here fails with `UnhandledMatchError`.
     *
     * laravel-data needs `LaravelDataContainer::boot()` once after its classes are generated.
     *
     * @param class-string $fqcn
     * @param array<string, mixed> $symfonyContext
     */
    private function payloadOutcome(GenerationMode $mode, string $fqcn, string $json, array $symfonyContext = []): PayloadOutcome
    {
        return match ($mode) {
            GenerationMode::Runtime => $this->runtimeOutcome($fqcn, $json),
            GenerationMode::Symfony => $this->symfonyOutcome($fqcn, $json, $symfonyContext),
            GenerationMode::Laravel => $this->laravelOutcome($fqcn, $json),
            GenerationMode::LaravelData => $this->laravelDataOutcome($fqcn, $json),
            GenerationMode::Yii3 => $this->yii3Outcome($fqcn, $json),
        };
    }

    /**
     * The runtime deserializer validates on the way in, and normalizing re-checks the DTO. Both refuse
     * with a `RuntimeException`; anything else is a crash.
     *
     * @param class-string $fqcn
     */
    private function runtimeOutcome(string $fqcn, string $json): PayloadOutcome
    {
        $request = Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );
        try {
            $dto = (new DtoDeserializer())->deserialize($request, $fqcn);
        } catch (RuntimeException $refusal) {
            return PayloadOutcome::refused(stage: 'deserialize', detail: $refusal->getMessage(), throwable: $refusal);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'deserialize', throwable: $crash);
        }

        try {
            (new DtoNormalizer())->validateAndNormalizeToArray($dto);
        } catch (RuntimeException $refusal) {
            return PayloadOutcome::refused(stage: 'normalize', detail: $refusal->getMessage(), throwable: $refusal);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'normalize', throwable: $crash);
        }

        return PayloadOutcome::accepted();
    }

    /**
     * Whether the installed symfony/serializer refuses a scalar as a member of a union of objects
     * itself (7.4.23 and 8.1.11 do), rather than casting it to `[0 => value]` for the object normalizer.
     */
    private static function symfonySerializerGuardsUnionMembers(): bool
    {
        $version = (string)InstalledVersions::getVersion('symfony/serializer');

        return version_compare($version, str_starts_with($version, '7.') ? '7.4.23' : '8.1.11', '>=');
    }

    /**
     * The serializer, then the validator — what `#[MapRequestPayload]` does. A serializer exception is a
     * refusal only where `RequestPayloadValueResolver` turns it into a 4xx; any other one escapes it as a
     * 500, and so does anything the validator throws.
     *
     * @param class-string $fqcn
     * @param array<string, mixed> $context
     */
    private function symfonyOutcome(string $fqcn, string $json, array $context = []): PayloadOutcome
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $typeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer(
            normalizers: [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                    propertyAccessor: null,
                    propertyTypeExtractor: $typeExtractor,
                ),
                new ArrayDenormalizer(),
            ],
            encoders: [new JsonEncoder()],
        );

        try {
            $dto = $serializer->deserialize($json, $fqcn, 'json', $context);
        } catch (PartialDenormalizationException $refusal) {
            $messages = array_map(static fn(Throwable $error): string => $error->getMessage(), $refusal->getErrors());

            return PayloadOutcome::refused(stage: 'deserialize', detail: implode('; ', $messages), throwable: $refusal);
        } catch (SerializerInvalidArgumentException | UnexpectedPropertyException | NotEncodableValueException $refusal) {
            return PayloadOutcome::refused(stage: 'deserialize', detail: $refusal->getMessage(), throwable: $refusal);
        } catch (ExtraAttributesException $refusal) {
            // A scalar for a union of `array` and a generated class, on a serializer without the
            // union-member guard: README.support-matrix documents the 500 it is there, and
            // `ValidationParityTest::testSymfonyRefusesAScalarBesideAListAndAClass` pins it. A newer
            // serializer refuses the scalar outright, so anywhere else this is a crash.
            if (self::symfonySerializerGuardsUnionMembers()) {
                return PayloadOutcome::crashed(stage: 'deserialize', throwable: $refusal);
            }

            return PayloadOutcome::refused(stage: 'deserialize', detail: 'a 500 on this serializer: ' . $refusal->getMessage(), throwable: $refusal);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'deserialize', throwable: $crash);
        }

        try {
            $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($dto);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'validate', throwable: $crash, fromValidationEngine: true);
        }
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }

            return PayloadOutcome::refused(stage: 'validate', detail: implode('; ', $messages));
        }

        return PayloadOutcome::accepted($dto);
    }

    /**
     * The emitted rules and `withValidator()`, as the FormRequest runs them, then `fromValidated()`, which
     * `toDto()` calls in the controller: a payload the rules accept but the DTO cannot hold is a 500. The
     * DTO is then encoded the way a JSON response encodes it (`jsonSerialize()` is its `toArray()`).
     *
     * @param class-string $fqcn
     */
    private function laravelOutcome(string $fqcn, string $json): PayloadOutcome
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return PayloadOutcome::refused(stage: 'decode', detail: 'the body is not a JSON object');
        }

        try {
            /** @var array<string, mixed> $rules */
            $rules = call_user_func([$fqcn, 'rules']);
            $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($payload, $rules);
            if (method_exists($fqcn, 'withValidator')) {
                // The raw body is passed exactly as the generated FormRequest passes it: `type: object`
                // versus `type: array` is a wire-shape question the decoded payload can no longer answer.
                call_user_func([$fqcn, 'withValidator'], $validator, $json);
            }
            $fails = $validator->fails();
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'validate', throwable: $crash, fromValidationEngine: true);
        }
        if ($fails) {
            return PayloadOutcome::refused(stage: 'validate', detail: $validator->errors()->toJson());
        }

        try {
            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();
            $dto = call_user_func([$fqcn, 'fromValidated'], $validated);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'hydrate', throwable: $crash);
        }

        // The response side, as the controller writes the DTO back: a value the constructor holds but
        // `toArray()` cannot write — a null item of a list of classes — was a `TypeError` here, a 500
        // the request side never showed.
        try {
            $encoded = json_encode($dto, JSON_THROW_ON_ERROR);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'serialize', throwable: $crash);
        }

        return PayloadOutcome::accepted(dto: is_object($dto) ? $dto : null, encoded: $encoded);
    }

    /**
     * laravel-data mode goes through the package's own entry point, and through a REQUEST: its default
     * `validation_strategy` is `OnlyRequests`, so `from($array)` would hydrate without validating anything.
     * The request is also where the emitted interpreter finds the raw body it needs. The built Data is
     * then written back with `toJson()`, as `laravelOutcome()` encodes its DTO.
     *
     * @param class-string $fqcn
     */
    private function laravelDataOutcome(string $fqcn, string $json): PayloadOutcome
    {
        $outcome = LaravelDataContainer::withRequest(
            json: $json,
            work: static function (LaravelRequest $request) use ($fqcn): PayloadOutcome {
                try {
                    $dto = $fqcn::from($request);
                } catch (ValidationException $refusal) {
                    return PayloadOutcome::refused(
                        stage: 'validate',
                        detail: (string)json_encode($refusal->errors()),
                        throwable: $refusal,
                    );
                } catch (Throwable $crash) {
                    return PayloadOutcome::crashed(stage: 'hydrate', throwable: $crash);
                }

                // The response side, as a controller returning the Data writes it: a value the object
                // holds but cannot transform is a 500 the request side never showed, and the JSON is
                // what the write-back assertions compare.
                try {
                    $encoded = $dto->toJson();
                } catch (Throwable $crash) {
                    return PayloadOutcome::crashed(stage: 'serialize', throwable: $crash);
                }

                return PayloadOutcome::accepted(dto: $dto, encoded: $encoded);
            },
        );
        assert($outcome instanceof PayloadOutcome);

        return $outcome;
    }

    /**
     * Through `ValidatingHydrator`, the setup README.yii3 makes mandatory: the hydrator casts before any
     * rule runs, and only the raw `#[Validate]` checks see what the payload actually said.
     *
     * @param class-string $fqcn
     */
    private function yii3Outcome(string $fqcn, string $json): PayloadOutcome
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return PayloadOutcome::refused(stage: 'decode', detail: 'the body is not a JSON object');
        }

        try {
            $result = (new Yii3Container())->hydrateValidated($fqcn, $payload);
        } catch (Throwable $crash) {
            return PayloadOutcome::crashed(stage: 'hydrate', throwable: $crash);
        }
        if (!$result->isValid()) {
            return PayloadOutcome::refused(
                stage: 'validate',
                detail: (string)json_encode($result->getErrorMessagesIndexedByPath()),
            );
        }

        return PayloadOutcome::accepted();
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

        if ($this->siblingDocuments === []) {
            (new GenerateDtoCommand())->generateFromArray($spec, $target, $namespace, $mode);
        } else {
            $documents = $this->outputDirectory . '/documents-' . $namespace;
            if (!is_dir($documents)) {
                mkdir($documents, 0o755, true);
            }
            foreach (['spec.json' => $spec] + $this->siblingDocuments as $fileName => $document) {
                file_put_contents($documents . '/' . $fileName, json_encode($document, JSON_THROW_ON_ERROR));
            }
            (new GenerateDtoCommand())->generateFromFile($documents . '/spec.json', $target, $namespace, $mode);
        }
        $this->registerGeneratedAutoloader(namespace: $namespace, target: $target);
        foreach (glob($target . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        /** @var class-string $fqcn */
        $fqcn = $namespace . '\Probe';

        return $fqcn;
    }

    /**
     * An autoloader, as an application has: a class that extends another (`allOf` with one `$ref`) must
     * find its parent whatever order the files are listed in.
     */
    private function registerGeneratedAutoloader(string $namespace, string $target): void
    {
        spl_autoload_register(static function (string $class) use ($namespace, $target): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $file = $target . '/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }

    private function namespaceFor(GenerationMode $mode, string $key): string
    {
        return 'Parity' . $mode->tag() . $this->namespaceSuffix($key);
    }

    private function namespaceSuffix(string $key): string
    {
        return substr(md5($key), 0, 10);
    }
}
