<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use WeakMap;

/**
 * The fields of a built DTO that hold an empty JSON OBJECT as an empty PHP array.
 *
 * A union with an `array` member and an object one (`anyOf: [{type: array}, {type: object}]`) is
 * typed `array`, and `{}` and `[]` are the same empty PHP array once it holds them — so `{}` was
 * written back as `[]`. {@see DtoDeserializer} still sees the `stdClass` it decoded and notes the
 * field here; {@see DtoNormalizer} writes such a field back as `{}`. Keyed by the DTO itself in a
 * WeakMap, so a note goes when its object goes, and a DTO built by hand is never in it.
 *
 * @internal machinery shared by the deserializer and the normalizer, not an API
 */
final class EmptyJsonObjectFields
{
    /**
     * @var WeakMap<object, array<string, true>>|null
     */
    private static ?WeakMap $fields = null;

    /**
     * @param array<string, true> $propertyNames
     */
    public static function remember(object $dto, array $propertyNames): void
    {
        if ($propertyNames === []) {
            return;
        }
        self::$fields ??= new WeakMap();
        self::$fields[$dto] = $propertyNames;
    }

    /**
     * The PHP names of the fields of `$dto` that hold `{}` as `[]`.
     *
     * @return array<string, true>
     */
    public static function of(object $dto): array
    {
        if (self::$fields === null || !self::$fields->offsetExists($dto)) {
            return [];
        }

        return self::$fields[$dto];
    }
}
