<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use Symfony\Component\HttpFoundation\Request;

interface DtoDeserializerInterface
{
    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object;

    /**
     * Deserializes a top-level JSON array request body (e.g. a bulk endpoint) into a list of items.
     *
     * @template T of object
     * @param class-string<T>|string $itemType
     * @return ($itemType is class-string<T> ? array<int, T> : array<int, mixed>)
     */
    public function deserializeCollection(Request $request, string $itemType): array;

    /**
     * Deserializes a single already-decoded JSON value into $type, bypassing the Request.
     *
     * $data is a value produced by json_decode($json, false): stdClass for an object,
     * a list for an array, a scalar otherwise. Discriminator-aware — behaves exactly like
     * one element of a collection body.
     *
     * Use it for a batch endpoint that must report per-element errors instead of failing
     * the whole request.
     *
     * $path names the value in every error message, so pass the element's position
     * ('3', 'items.3') when there is one; the default reads `param "value"`.
     *
     * @template T of object
     * @param class-string<T>|string $type
     * @return ($type is class-string<T> ? T : mixed)
     */
    public function deserializeValue(mixed $data, string $type, string $path = 'value'): mixed;
}
