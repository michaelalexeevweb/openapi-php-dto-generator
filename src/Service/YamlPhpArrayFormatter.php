<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use Symfony\Component\Yaml\Yaml;

final class YamlPhpArrayFormatter
{
    public function formatFile(string $filePath): string
    {
        $data = Yaml::parseFile($filePath);

        return $this->toPhpArraySyntax($data);
    }

    private function toPhpArraySyntax(mixed $value): string
    {
        if (is_array($value)) {
            return $this->formatArray($value);
        }

        return $this->formatScalar($value);
    }

    private function formatArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        $parts = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $parts[] = $this->toPhpArraySyntax($item);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        foreach ($value as $key => $item) {
            $parts[] = sprintf('%s => %s', $this->formatKey($key), $this->toPhpArraySyntax($item));
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function formatKey(int|string $key): string
    {
        if (is_int($key)) {
            return (string)$key;
        }

        return $this->quoteString($key);
    }

    private function formatScalar(mixed $value): string
    {
        if (is_string($value)) {
            return $this->quoteString($value);
        }

        if (is_bool($value)) {
            if ($value) {
                return 'true';
            }

            return 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $this->quoteString((string)$value);
    }

    private function quoteString(string $value): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return "'" . $escaped . "'";
    }
}
