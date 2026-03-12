<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\OpenApiFormatHandlerInterface;

final class OpenApiFormatRegistry
{
    /**
     * @var array<string, OpenApiFormatHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @param array<string, OpenApiFormatHandlerInterface> $handlers
     */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $format => $handler) {
            $this->handlers[$format] = $handler;
        }
    }

    public function has(string $format): bool
    {
        return isset($this->handlers[$format]);
    }

    public function validate(string $format, string $subject, mixed $value): ?string
    {
        if (!$this->has($format)) {
            return null;
        }

        return $this->handlers[$format]->validate($subject, $value);
    }

    public function deserialize(string $format, mixed $value, string $typeName, string $paramPath, bool $allowsNull): mixed
    {
        return $this->handlers[$format]->deserialize($value, $typeName, $paramPath, $allowsNull);
    }
}

