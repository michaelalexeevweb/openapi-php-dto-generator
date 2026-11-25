<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

/**
 * What the runtime deserializer and normalizer ask of a validator beyond {@see DtoValidatorInterface},
 * when it offers it: reading an empty PHP array the way the raw JSON had it, and saying whether the regex
 * engine gave up. A validator of your own may implement it; one that does not is used through
 * {@see DtoValidatorInterface::validate()} alone.
 */
interface JsonShapeAwareValidatorInterface extends DtoValidatorInterface
{
    /**
     * A copy that reads an empty PHP array as the JSON array it was decoded from, for a body whose JSON
     * shapes are known.
     */
    public function readingEmptyArraysAsJsonArrays(): self;

    /**
     * The errors of a value against its constraints, and whether the regex engine gave up on a check
     * among them.
     *
     * @param array<string, mixed> $constraints
     * @return array{errors: array<string>, engineGaveUp: bool}
     */
    public function validateReportingEngine(string $subject, mixed $value, array $constraints): array;
}
