<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use RuntimeException;

interface OpenApiDtoGeneratorServiceInterface
{
    /**
     * Generates DTO classes from an OpenAPI YAML/JSON file.
     *
     * @param string $filePath Absolute or relative path to the spec file
     * @param string $outputDirectory Directory where generated PHP files will be written
     * @param string $namespace Base PHP namespace for the generated classes
     * @return int Number of classes generated
     * @throws RuntimeException if the file cannot be found or parsed
     */
    public function generateFromFile(string $filePath, string $outputDirectory, string $namespace): int;

    /**
     * Generates DTO classes from an already-parsed OpenAPI array.
     *
     * @param array<mixed> $openApi Parsed OpenAPI document
     * @param string $outputDirectory Directory where generated PHP files will be written
     * @param string $namespace Base PHP namespace for the generated classes
     * @return int Number of classes generated
     */
    public function generateFromArray(array $openApi, string $outputDirectory, string $namespace): int;
}
