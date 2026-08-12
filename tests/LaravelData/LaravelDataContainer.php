<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Spatie\LaravelData\Resolvers\ContextResolver;
use Spatie\LaravelData\Support\Creation\ValidationStrategy;
use Spatie\LaravelData\Support\DataConfig;

/**
 * The container `spatie/laravel-data` needs, without a Laravel application.
 *
 * laravel-data resolves `DataConfig`, its resolvers and its pipeline out of the container, so its
 * `from()` / `validate()` paths cannot run against nothing. The obvious route is
 * `orchestra/testbench`, and it is the wrong one for a framework-agnostic package: it installs a whole
 * framework to reach a pipeline that needs a container, a config repository and two singletons.
 *
 * What this class does NOT do is register `LaravelDataServiceProvider`. That provider extends
 * `PackageServiceProvider`, which needs a Foundation `Application` — `runningUnitTests()`,
 * `environment()`, `publishes()`. Its `packageRegistered()` binds exactly two things this harness cares
 * about, so they are bound here directly.
 *
 * The package's own `config/data.php` is not loaded either: it calls `app_path()`, `base_path()` and
 * `env()`, the last of which needs `vlucas/phpdotenv`. Its defaults are transcribed in `dataConfig()`
 * instead, which also documents what the generated classes are being measured against.
 */
final class LaravelDataContainer
{
    /**
     * A FRESH container per call, installed as the global instance.
     *
     * Reusing one across tests would carry `DataConfig`'s per-class reflection cache — and generated
     * classes are what these tests keep replacing, so a stale entry would measure the previous case.
     */
    public static function boot(): Container
    {
        require_once __DIR__ . '/helpers.php';

        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $repository = new Repository(['data' => self::dataConfig()]);
        $container->instance('config', $repository);
        $container->instance(ConfigContract::class, $repository);

        // No translations loaded on purpose: a rule reports `validation.min.string` rather than an
        // English sentence, exactly as the Laravel-mode suites already measure it.
        $translator = new Translator(new ArrayLoader(), 'en');
        $container->instance('translator', $translator);
        $container->instance(TranslatorContract::class, $translator);

        $validationFactory = new ValidationFactory($translator, $container);
        $container->instance('validator', $validationFactory);
        $container->instance(ValidationFactoryContract::class, $validationFactory);

        $container->singleton(
            DataConfig::class,
            static fn(): DataConfig => DataConfig::createFromConfig(self::dataConfig()),
        );
        $container->singleton(ContextResolver::class);

        return $container;
    }

    /**
     * Run $work with $json bound as the current request.
     *
     * Two reasons the request has to be there. `validation_strategy` defaults to `OnlyRequests`, so
     * `Data::from($array)` does not validate at all and only `from($request)` exercises the rules a
     * generated class emits. And the emitted interpreter reads the RAW body — `{"m":{"0":1}}` and
     * `{"m":[1]}` decode to the same PHP array — which is reachable through the request and nowhere
     * else, since laravel-data calls `withValidator($validator)` with no second argument.
     *
     * @param callable(Request): mixed $work
     */
    public static function withRequest(string $json, callable $work): mixed
    {
        $container = Container::getInstance();
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $container->instance('request', $request);

        try {
            return $work($request);
        } finally {
            $container->forgetInstance('request');
        }
    }

    /**
     * laravel-data's shipped defaults, minus the branches this harness has no use for (structure
     * caching, reflection discovery, the artisan commands, Livewire synths).
     *
     * @return array<string, mixed>
     */
    private static function dataConfig(): array
    {
        return [
            'date_format' => DATE_ATOM,
            'date_timezone' => null,
            'features' => [
                'cast_and_transform_iterables' => false,
                'ignore_exception_when_trying_to_set_computed_property_value' => false,
            ],
            'transformers' => [
                DateTimeInterface::class => \Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer::class,
                Arrayable::class => \Spatie\LaravelData\Transformers\ArrayableTransformer::class,
                BackedEnum::class => \Spatie\LaravelData\Transformers\EnumTransformer::class,
            ],
            'casts' => [
                DateTimeInterface::class => \Spatie\LaravelData\Casts\DateTimeInterfaceCast::class,
                BackedEnum::class => \Spatie\LaravelData\Casts\EnumCast::class,
            ],
            'rule_inferrers' => [
                \Spatie\LaravelData\RuleInferrers\SometimesRuleInferrer::class,
                \Spatie\LaravelData\RuleInferrers\NullableRuleInferrer::class,
                \Spatie\LaravelData\RuleInferrers\RequiredRuleInferrer::class,
                \Spatie\LaravelData\RuleInferrers\BuiltInTypesRuleInferrer::class,
                \Spatie\LaravelData\RuleInferrers\AttributesRuleInferrer::class,
            ],
            'normalizers' => [
                \Spatie\LaravelData\Normalizers\ArrayableNormalizer::class,
                \Spatie\LaravelData\Normalizers\ObjectNormalizer::class,
                \Spatie\LaravelData\Normalizers\ArrayNormalizer::class,
                \Spatie\LaravelData\Normalizers\JsonNormalizer::class,
            ],
            'wrap' => null,
            'var_dumper_caster_mode' => 'disabled',
            'structure_caching' => ['enabled' => false],
            // The shipped default, kept rather than relaxed: it is the behaviour a generated class has
            // to be correct under, and relaxing it here would measure a setup no application runs.
            'validation_strategy' => ValidationStrategy::OnlyRequests->value,
            'name_mapping_strategy' => ['input' => null, 'output' => null],
            'ignore_invalid_partials' => false,
            'max_transformation_depth' => null,
            'throw_when_max_transformation_depth_reached' => true,
            'livewire' => ['enable_synths' => false],
        ];
    }
}
