<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * How an emitted file must spell a class from a LIBRARY, given what the document named its own schemas.
 *
 * A document is free to call a schema `Data`, `Request`, `Stringable` or `RuntimeException`, and the
 * generated files carry imports with exactly those short names. PHP then fails in two different ways,
 * and neither is reachable from the document's side:
 *
 *     the file DECLARING it     Fatal error: Cannot redeclare X\Stringable
 *                               (previously declared as local import) — the file never loads
 *     any SIBLING file          the `use` silently wins over the same-namespace class, so a property
 *                               typed `Request` is the framework's, and the payload that should
 *                               hydrate it is a TypeError
 *
 * Dropping the import and naming the class outright is the only spelling that cannot be shadowed. So
 * every library class an emitter names goes through {@see libraryClassRef()}, which answers with the
 * short name and records the import, or with the fully qualified name and records nothing.
 *
 * laravel-data mode has done this since it was written; runtime and yii3 did not, and a schema named
 * `Stringable` was a fatal error in both until the two joined.
 */
trait NamesLibraryClasses
{
    /**
     * @param array<int, string> $useStatements collects the import when one is safe to add
     *
     * @return string the short name, or the fully qualified name when the document owns that name
     */
    private function libraryClassRef(string $fqcn, string $namespace, array &$useStatements): string
    {
        $shortName = $this->shortClassName($fqcn);
        if ($this->namespaceDeclaresClass($shortName, $namespace)) {
            return '\\' . $fqcn;
        }

        $useStatements[] = $fqcn;

        return $shortName;
    }

    /**
     * Whether a class the generator itself emits into this namespace already owns that name.
     */
    private function namespaceDeclaresClass(string $shortName, string $namespace): bool
    {
        foreach ($this->dtoSchemas as $generatedClass => $ignored) {
            if ($generatedClass === $shortName && ($this->schemaNamespaces[$generatedClass] ?? $this->baseNamespace) === $namespace) {
                return true;
            }
        }

        foreach ($this->enumSchemas as $generatedEnum => $ignored) {
            if ($generatedEnum === $shortName && ($this->enumNamespaces[$generatedEnum] ?? $this->baseNamespace) === $namespace) {
                return true;
            }
        }

        return false;
    }
}
