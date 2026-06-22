<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

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
 */
final class DtoDeserializerPsr7
{
    private readonly HttpFoundationFactory $httpFoundationFactory;

    public function __construct(
        private readonly DtoDeserializer $deserializer = new DtoDeserializer(),
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
     * See {@see DtoDeserializer::deserializeCollection()}.
     *
     * @template T of object
     * @param class-string<T>|string $itemType
     * @return ($itemType is class-string<T> ? array<int, T> : array<int, mixed>)
     */
    public function deserializeCollectionPsr7(ServerRequestInterface $request, string $itemType): array
    {
        return $this->deserializer->deserializeCollection(
            request: $this->httpFoundationFactory->createRequest($request),
            itemType: $itemType,
        );
    }
}
