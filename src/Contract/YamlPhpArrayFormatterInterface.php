<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

interface YamlPhpArrayFormatterInterface
{
    /**
     * Parses a YAML file and returns its content formatted as a PHP array literal string.
     */
    public function formatFile(string $filePath): string;
}
