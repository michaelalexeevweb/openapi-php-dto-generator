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
}
