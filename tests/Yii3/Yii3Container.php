<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use Nyholm\Psr7\ServerRequest;
use OpenapiPhpDtoGenerator\Tests\Yii3\Recipe\NestedPathTypeCaster;
use OpenapiPhpDtoGenerator\Tests\Yii3\Recipe\PathAwareCollectionResolver;
use OpenapiPhpDtoGenerator\Tests\Yii3\Recipe\PathAwareValidatingHydrator;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Yiisoft\Hydrator\Attribute\Parameter\CollectionResolver;
use Yiisoft\Hydrator\AttributeHandling\ResolverFactory\ContainerAttributeResolverFactory;
use Yiisoft\Hydrator\Hydrator;
use Yiisoft\Hydrator\TypeCaster\CompositeTypeCaster;
use Yiisoft\Hydrator\TypeCaster\EnumTypeCaster;
use Yiisoft\Hydrator\TypeCaster\HydratorTypeCaster;
use Yiisoft\Hydrator\TypeCaster\PhpNativeTypeCaster;
use Yiisoft\Hydrator\Validator\Attribute\ValidateResolver;
use Yiisoft\Hydrator\Validator\ValidatingHydrator;
use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\RequestProvider\RequestProvider;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\Validator;
use Yiisoft\Validator\ValidatorInterface;

/**
 * The wiring a generated yii3 input needs, without a Yii3 application.
 *
 * Two measured facts make this class necessary, and neither is optional:
 *
 * - **the emitted class cannot be hydrated by a bare `new Hydrator()`.** `#[FromBody]` resolves through
 *   `FromBodyResolver`, whose constructor requires a `RequestProviderInterface`, and the default
 *   `ReflectionAttributeResolverFactory` refuses anything with required constructor arguments:
 *   `AttributeResolverNonInstantiableException: … cannot be instantiated because it has 1 required
 *   parameters in constructor`. So the resolvers come from a container instead.
 * - **the default type-caster set cannot fill an enum property.** `new Hydrator()` uses
 *   `CompositeTypeCaster(PhpNativeTypeCaster, HydratorTypeCaster)`, with no `EnumTypeCaster`, and a
 *   string never becomes a backed enum — the constructor is then unsatisfied and the whole object
 *   fails to build. Applications hit this too, which is why `README.yii3.md` has to say so.
 *
 * Everything here is what a real Yii3 application already has configured; the harness is not a
 * substitute for the framework, only the smallest wiring that lets a generated class be measured.
 */
final class Yii3Container implements ContainerInterface
{
    private readonly RequestProvider $requestProvider;

    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(?ServerRequest $request = null)
    {
        $this->requestProvider = new RequestProvider($request ?? new ServerRequest('POST', '/'));
        $this->instances[RequestProviderInterface::class] = $this->requestProvider;
        // The emitted `#[Validate]` resolves through `ValidateResolver`, which needs the validator; ONE
        // instance, because `ValidatingHydrator` hands it the result to fill and the attribute
        // resolution must find that same one.
        $validator = new Validator();
        $this->instances[ValidatorInterface::class] = $validator;
        $this->instances[ValidateResolver::class] = new ValidateResolver($validator);
    }

    /**
     * Hydrates through `ValidatingHydrator`, as an application must for the RAW-value checks, and
     * returns the verdict it stored on the input — the raw checks' when one failed, the object's
     * otherwise. `$nestedPaths` wires README.yii3's recipe for raw checks in a nested object instead.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     */
    public function hydrateValidated(
        string $class,
        array $payload,
        string $method = 'POST',
        string $uri = '/',
        array $query = [],
        bool $nestedPaths = false,
    ): Result {
        $result = $this->hydrateValidatedInput(
            class: $class,
            payload: $payload,
            method: $method,
            uri: $uri,
            query: $query,
            nestedPaths: $nestedPaths,
        )->getValidationResult();
        if ($result === null) {
            throw new RuntimeException('The validating hydrator stored no verdict.');
        }

        return $result;
    }

    /**
     * The same hydration, handing back the input itself — for what its getters answer.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     */
    public function hydrateValidatedInput(
        string $class,
        array $payload,
        string $method = 'POST',
        string $uri = '/',
        array $query = [],
        bool $nestedPaths = false,
    ): AbstractInput {
        $this->requestProvider->set(
            (new ServerRequest($method, $uri))->withParsedBody($payload)->withQueryParams($query),
        );
        $validator = $this->instances[ValidatorInterface::class];
        $resolver = $this->instances[ValidateResolver::class];
        if (!$validator instanceof ValidatorInterface || !$resolver instanceof ValidateResolver) {
            throw new RuntimeException('The validator is wired in the constructor.');
        }

        if ($nestedPaths) {
            $paths = new NestedPathTypeCaster($resolver);
            $this->instances[CollectionResolver::class] = new PathAwareCollectionResolver($paths);
            $hydrator = new PathAwareValidatingHydrator(
                hydrator: new Hydrator(
                    typeCaster: new CompositeTypeCaster(new PhpNativeTypeCaster(), new EnumTypeCaster(), $paths),
                    attributeResolverFactory: new ContainerAttributeResolverFactory($this),
                ),
                validator: $validator,
                paths: $paths,
            );
        } else {
            $hydrator = new ValidatingHydrator($this->hydrator(), $validator, $resolver);
        }
        $input = $hydrator->create($class, $payload);
        if (!$input instanceof AbstractInput) {
            throw new RuntimeException(sprintf('%s is not an input class.', $class));
        }

        return $input;
    }

    /**
     * Hydrates a generated input class from a decoded payload, the way the framework would.
     *
     * The payload goes into the REQUEST, not into the hydrator's data argument: `#[FromBody]` REPLACES
     * whatever data it was given with the request's parsed body (`FromBodyResolver::prepareData()`),
     * so an array passed straight to `create()` is discarded and the constructor receives nothing —
     * "cannot be instantiated because it has 2 required parameters in constructor, but passed only 0".
     * Feeding the request is what the attribute actually means, and what an application does.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $payload
     * @return T
     */
    public function hydrate(string $class, array $payload, string $method = 'POST', string $uri = '/'): object
    {
        $this->requestProvider->set(
            (new ServerRequest($method, $uri))->withParsedBody($payload),
        );

        // Fed BOTH ways on purpose. A class that IS a request payload carries `#[FromBody]`, which
        // discards the data argument and reads the request; a nested schema carries no source
        // attribute at all — correctly, since `#[FromBody]` on a nested class made it re-read the whole
        // request body instead of its own value — and is hydrated from the argument.
        return $this->hydrator()->create($class, $payload);
    }

    /**
     * The verdict the ACTION would read — Yii3 does not turn it into a 422 by itself.
     */
    public function validate(object $input): Result
    {
        return (new Validator())->validate($input);
    }

    public function hydrator(): Hydrator
    {
        return new Hydrator(
            // EnumTypeCaster is NOT in the default set; without it every generated enum property is
            // unfillable. Ordered so the native caster runs first, exactly as the default does.
            typeCaster: new CompositeTypeCaster(
                new PhpNativeTypeCaster(),
                new EnumTypeCaster(),
                new HydratorTypeCaster(),
            ),
            attributeResolverFactory: new ContainerAttributeResolverFactory($this),
        );
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || class_exists($id);
    }

    /**
     * Builds a resolver on demand, injecting the request provider wherever one is asked for.
     *
     * A three-line autowirer rather than a DI package: the only dependency any `input-http` resolver
     * declares is `RequestProviderInterface`, so anything more general would be scaffolding nobody
     * reads.
     */
    public function get(string $id): object
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!class_exists($id)) {
            throw new RuntimeException(sprintf('Yii3 test container cannot build "%s".', $id));
        }

        $constructor = (new ReflectionClass($id))->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                // An optional argument keeps its default — `ToDateTimeResolver` declares several
                // (`$format`, `$timeZone`, …) and choosing them is the application's business.
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new RuntimeException(sprintf(
                    'Yii3 test container cannot supply $%s of %s — only %s is wired.',
                    $parameter->getName(),
                    $id,
                    RequestProviderInterface::class,
                ));
            }
            $arguments[] = $this->get($type->getName());
        }

        return $this->instances[$id] = new $id(...$arguments);
    }
}
