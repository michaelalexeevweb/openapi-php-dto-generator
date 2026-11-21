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
    /** @var array<string, list<string>> lowercased short name => the generated names spelled so */
    private array $foldedClassNames = [];

    private int $foldedClassNameCount = -1;

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
     * The import half of {@see libraryClassRef()}, for a class a TEMPLATE names in full: the import is
     * written when no class of the document owns the short name, and the rendered file's import pass
     * then shortens the full name to it. Otherwise that pass gives the class an alias of its own.
     *
     * Not left to that pass alone: a docblock mentioning the short name — "This FormRequest is
     * auto-generated" — counts as a use of it there, and would cost the class an alias it does not need.
     *
     * @param array<int, string> $useStatements
     */
    private function importLibraryClass(string $fqcn, string $namespace, array &$useStatements): void
    {
        if (!$this->namespaceDeclaresClass($this->shortClassName($fqcn), $namespace)) {
            $useStatements[] = $fqcn;
        }
    }

    /**
     * Whether a class the generator itself emits into this namespace already owns that name.
     *
     * Compared the way PHP compares class names, ignoring case: the document's `stdClass` is generated
     * as `StdClass`, and `use stdClass;` beside `class StdClass` is the same fatal as an exact match.
     */
    private function namespaceDeclaresClass(string $shortName, string $namespace): bool
    {
        // Keyed by lowercased short name: a lookup, not a scan of every class per reference.
        foreach ($this->generatedClassNamesByFoldedName()[strtolower($shortName)] ?? [] as $declared) {
            if (array_key_exists($declared, $this->dtoSchemas) && ($this->schemaNamespaces[$declared] ?? $this->baseNamespace) === $namespace) {
                return true;
            }
            if (array_key_exists($declared, $this->enumSchemas) && ($this->enumNamespaces[$declared] ?? $this->baseNamespace) === $namespace) {
                return true;
            }
        }

        return false;
    }

    /**
     * The generated classes and enums that live in `$namespace`, by the name their file declares.
     *
     * @return list<string>
     */
    private function generatedClassNamesIn(string $namespace): array
    {
        $names = [];
        foreach ($this->generatedClassNamesByFoldedName() as $folded => $spellings) {
            if ($this->namespaceDeclaresClass($folded, $namespace)) {
                $names[] = $spellings[0];
            }
        }

        return $names;
    }

    /**
     * Every generated class and enum name, grouped by its lowercased spelling.
     *
     * Rebuilt when the number of known classes changes: the generator only ever adds to
     * `$dtoSchemas` and `$enumSchemas` within a run, and {@see initializeGeneration()} resets the index
     * together with them for the next one.
     *
     * @return array<string, list<string>>
     */
    private function generatedClassNamesByFoldedName(): array
    {
        $known = count($this->dtoSchemas) + count($this->enumSchemas);
        if ($known !== $this->foldedClassNameCount) {
            $this->foldedClassNames = [];
            foreach ([...array_keys($this->dtoSchemas), ...array_keys($this->enumSchemas)] as $name) {
                $this->foldedClassNames[strtolower($name)][] = $name;
            }
            $this->foldedClassNameCount = $known;
        }

        return $this->foldedClassNames;
    }
}
