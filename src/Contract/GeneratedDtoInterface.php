<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use JsonException;
use JsonSerializable;

interface GeneratedDtoInterface extends JsonSerializable
{
    /**
     * Sentinel substring carried by a LogicException thrown from a DTO getter / toArray()
     * to signal that an optional field was absent from the request and must be skipped
     * during normalization (instead of emitting null). Normalizers match against this
     * constant rather than a hard-coded literal so the wording stays rename-proof.
     */
    public const string FIELD_NOT_PROVIDED_MESSAGE = "wasn't provided in request";

    /**
     * Accepted date-time input formats, shared by the deserializer (parsing) and the
     * validator (format check) so the two never drift out of sync. Includes the RFC 3339
     * `T` separator with offset/`Z`, fractional seconds, and the space-separated variant.
     *
     * @var list<string>
     */
    public const array DATE_TIME_FORMATS = [
        'Y-m-d\TH:i:sp',
        'Y-m-d\TH:i:s.up',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public function jsonSerialize(): mixed;

    /**
     * @throws JsonException
     */
    public function toJson(): string;

    /**
     * @return array<string, array{getter: string, type: string, nullable: bool, metadata: array<string, mixed>}>
     */
    public static function getNormalizationMap(): array;

    /**
     * @return array<string, string>
     */
    public static function getAliases(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getConstraints(): array;
}
