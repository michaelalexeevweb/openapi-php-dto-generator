<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use JsonSerializable;

interface GeneratedDtoInterface extends JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public function jsonSerialize(): mixed;

    /**
     * @throws \JsonException
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

