<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\DtoDeserializerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

/**
 * PSR-7 entry point for the deserializer. Converts any PSR-7 ServerRequest (Slim, Mezzio,
 * Laminas, Yii3, …) into a Symfony Request via the official symfony/psr-http-message-bridge,
 * then delegates to {@see DtoDeserializer}. This keeps the core deserializer free of any PSR-7
 * coupling: the bridge dependency lives only in this optional class.
 *
 * Requires symfony/psr-http-message-bridge (a `suggest` dependency — not pulled in by default).
 *
 * There is deliberately NO `deserializeValuePsr7()`. Every method here exists to convert a PSR-7
 * request into a Symfony one; {@see DtoDeserializer::deserializeValue()} takes an already-decoded
 * value and no request at all, so there is nothing to convert and a delegate would only forward.
 * PSR-7 applications call that method on {@see DtoDeserializer} directly.
 */
final class DtoDeserializerPsr7
{
    private readonly HttpFoundationFactory $httpFoundationFactory;

    public function __construct(
        private readonly DtoDeserializerInterface $deserializer = new DtoDeserializer(),
    ) {
        if (!class_exists(HttpFoundationFactory::class)) {
            throw new RuntimeException(
                'PSR-7 support requires symfony/psr-http-message-bridge. '
                . 'Install it with: composer require symfony/psr-http-message-bridge',
            );
        }

        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    /**
     * Deserializes a PSR-7 ServerRequest into the given DTO. See {@see DtoDeserializer::deserialize()}.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserializePsr7(ServerRequestInterface $request, string $dtoClass): object
    {
        return $this->deserializer->deserialize(
            request: $this->httpFoundationFactory->createRequest($request),
            dtoClass: $dtoClass,
        );
    }

    /**
     * Deserializes a top-level JSON array PSR-7 ServerRequest body into a list of items.
     * See {@see DtoDeserializer::deserializeCollection()}, including why the two items-schema
     * facts are parameters rather than inference.
     *
     * The return type follows `$itemsNullable`, identically to the method this delegates to.
     *
     * @template T of object
     * @param class-string<T>|string $itemType
     * @return ($itemType is class-string<T>
     *     ? ($itemsNullable is true ? array<int, T|null> : array<int, T>)
     *     : array<int, mixed>)
     */
    public function deserializeCollectionPsr7(
        ServerRequestInterface $request,
        string $itemType,
        bool $itemsNullable = false,
        ?string $itemTemporalFormat = null,
    ): array {
        return $this->deserializer->deserializeCollection(
            request: $this->httpFoundationFactory->createRequest($request),
            itemType: $itemType,
            itemsNullable: $itemsNullable,
            itemTemporalFormat: $itemTemporalFormat,
        );
    }
}
