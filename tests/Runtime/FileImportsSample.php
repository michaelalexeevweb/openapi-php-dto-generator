<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer as Norm;
use Stringable;

/**
 * Fixture whose file-level `use` statements exercise every branch of
 * DtoDeserializer::resolveFileImports: a plain import, an aliased import (`as`), and a
 * namespace-less (global) import. The imports are referenced as constructor parameter types so
 * they are genuinely used (cs-fixer would otherwise strip them).
 */
final class FileImportsSample
{
    public function __construct(
        public readonly DtoDeserializer $plain,
        public readonly Norm $aliased,
        public readonly Stringable $global,
    ) {
    }
}
