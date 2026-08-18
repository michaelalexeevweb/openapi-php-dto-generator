<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * The published deserialization contract.
 *
 * Every parameter added here is a breaking change for each class implementing it — the declaration
 * stops being compatible and PHP fatals at autoload. 2.13.0 spends that once, deliberately: the two
 * items-schema facts below have to be on the contract, or the return types cannot tell the truth
 * about null (an implementation may not widen a return the contract narrows) and every
 * interface-typed call site pays for a null that its own signature made unreachable.
 */
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
     * All-or-nothing: every element's error is collected and thrown as one exception, so one bad
     * element fails the whole body. {@see deserializeValue()} is the per-element counterpart for
     * batch endpoints that must accept the good elements and report the bad ones by index.
     *
     * $itemsNullable mirrors `items: {nullable: true}`; $itemTemporalFormat mirrors the items
     * `format` — 'Y-m-d' for `format: date`, null for date-time. A bare $itemType has no owning DTO
     * property to infer either from, so both are passed in. The return type follows $itemsNullable.
     *
     * @template T of object
     * @param class-string<T>|string $itemType
     * @return ($itemType is class-string<T>
     *     ? ($itemsNullable is true ? array<int, T|null> : array<int, T>)
     *     : array<int, mixed>)
     */
    public function deserializeCollection(
        Request $request,
        string $itemType,
        bool $itemsNullable = false,
        ?string $itemTemporalFormat = null,
    ): array;

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
     * $nullable and $temporalFormat are the per-value form of the two on
     * {@see deserializeCollection()}, and the return type follows $nullable the same way.
     *
     * @template T of object
     * @param class-string<T>|string $type
     * @return ($type is class-string<T> ? ($nullable is true ? T|null : T) : mixed)
     */
    public function deserializeValue(
        mixed $data,
        string $type,
        string $path = 'value',
        bool $nullable = false,
        ?string $temporalFormat = null,
    ): mixed;
}
