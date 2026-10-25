<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * Turns every fully-qualified class and constant name in the emitted code into an import: `\LogicException`
 * becomes `LogicException` under `use LogicException;`, `\FILTER_VALIDATE_INT` becomes
 * `FILTER_VALIDATE_INT` under `use const FILTER_VALIDATE_INT;`. A file then names each thing one way —
 * the Laravel DTO imported `Illuminate\Validation\Validator` and still spelled it out in full three times
 * below, inside the blank-string rule.
 *
 * Like {@see GlobalFunctionImports} it reads the RENDERED PHP, tokenised, so strings and comments are out
 * of reach and every render path is covered by one call.
 *
 * Unlike a function import, a class import shares its symbol table with the classes of the namespace:
 * `use Illuminate\Validation\Validator;` in a file whose spec also has a schema called `Validator`
 * would silently retarget every unqualified `Validator` there. So a short name is taken only when the
 * file does not already mention it — in code or in a docblock — under another meaning; otherwise the
 * import is aliased with the namespace segments in front of it (`ValidationValidator`), and a name no
 * alias can free is left fully qualified.
 */
final class QualifiedNameImports
{
    public static function apply(string $php): string
    {
        $tokens = token_get_all($php);
        $imports = self::existingClassImports($php);
        $mentioned = self::mentionedNames($tokens, $imports);

        /** @var array<string, string> $classAliases fully-qualified name (no leading `\`) => alias */
        $classAliases = [];
        /** @var array<string, true> $constants */
        $constants = [];
        $rewritten = '';
        $changed = false;

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAME_FULLY_QUALIFIED) {
                $rewritten .= is_array($token) ? $token[1] : $token;

                continue;
            }

            $name = substr($token[1], 1);
            $kind = self::kindOf($tokens, $index, $name);
            $short = null;

            if ($kind === 'constant') {
                $short = $name;
                $constants[$name] = true;
            } elseif ($kind === 'class') {
                $short = self::aliasFor($name, $imports, $mentioned);
                if ($short !== null) {
                    $imports[$short] = $name;
                    $classAliases[$name] = $short;
                }
            }

            if ($short === null) {
                $rewritten .= $token[1];

                continue;
            }

            $rewritten .= $short;
            $changed = true;
        }

        if (!$changed) {
            return $php;
        }

        return self::withImports($rewritten, $classAliases, array_keys($constants));
    }

    /**
     * `class` for a type, `constant` for a global constant, `null` for a function call or a namespaced
     * name this pass leaves alone. The emitted code holds no namespaced function or constant, so a name
     * with a separator in it is always a class unless it is called.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function kindOf(array $tokens, int $index, string $name): ?string
    {
        $next = self::significantNeighbour($tokens, $index, 1);
        $previous = self::significantNeighbour($tokens, $index, -1);
        $instantiated = is_array($previous) && $previous[0] === T_NEW;

        if ($next === '(' && !$instantiated) {
            return null;
        }

        if (!str_contains($name, '\\') && !$instantiated && $next !== '::' && defined($name)) {
            return 'constant';
        }

        return 'class';
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significantNeighbour(array $tokens, int $index, int $step): array|string|null
    {
        for ($at = $index + $step; array_key_exists($at, $tokens); $at += $step) {
            $token = $tokens[$at];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // `::` is its own token; a bare string compare keeps the callers simple.
            return is_array($token) && $token[0] === T_DOUBLE_COLON ? '::' : $token;
        }

        return null;
    }

    /**
     * The name the file can use for `$name`: an existing import of it, else the shortest tail of its
     * segments that no import and no other mention in the file claims. `null` when there is none.
     *
     * @param array<string, string> $imports alias => fully-qualified name
     * @param array<string, true> $mentioned lowercased short names the file uses for something else
     */
    private static function aliasFor(string $name, array $imports, array $mentioned): ?string
    {
        foreach ($imports as $alias => $imported) {
            if (strcasecmp($imported, $name) === 0) {
                return $alias;
            }
        }

        $segments = explode('\\', $name);
        $alias = '';
        while ($segments !== []) {
            $alias = array_pop($segments) . $alias;
            $taken = array_key_exists(strtolower($alias), $mentioned)
                || array_filter(array_keys($imports), static fn(string $used): bool => strcasecmp($used, $alias) === 0) !== [];
            if (!$taken) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * The class imports at the top of the file — `use A\B;` and `use A\B as C;` at the start of a line,
     * which is where a template writes them. A closure's `use (...)` and a trait's `use` are indented.
     *
     * @return array<string, string> alias => fully-qualified name
     */
    private static function existingClassImports(string $php): array
    {
        preg_match_all('/^use (?!function |const )([\w\\\]+)(?: as (\w+))?;$/m', $php, $matches, PREG_SET_ORDER);

        $imports = [];
        foreach ($matches as $match) {
            $alias = ($match[2] ?? '') !== '' ? $match[2] : substr((string)strrchr('\\' . $match[1], '\\'), 1);
            $imports[$alias] = $match[1];
        }

        return $imports;
    }

    /**
     * Every short name the file may use unqualified for a class — one it declares, a sibling it names, a
     * type in a docblock — lowercased, since PHP resolves class names that way. An import under one of
     * these names would change what that mention means.
     *
     * A member (`$this->validator`), a function or constant being declared or called, and a named
     * argument's label are not class names and do not count; nor does a lowercase word in docblock prose
     * ("the framework's validator"). Anything else is counted rather than proven: a false hit only costs
     * that import a longer alias.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string> $imports alias => fully-qualified name
     *
     * @return array<string, true>
     */
    private static function mentionedNames(array $tokens, array $imports): array
    {
        $mentioned = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                $previous = self::significantNeighbour($tokens, $index, -1);
                $next = self::significantNeighbour($tokens, $index, 1);
                $isMember = $previous === '::' || (is_array($previous) && in_array(
                    $previous[0],
                    [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_CONST],
                    true,
                ));
                $isCallOrLabel = ($next === '(' || $next === ':') && !(is_array($previous) && $previous[0] === T_NEW);
                if (!$isMember && !$isCallOrLabel) {
                    $mentioned[strtolower($token[1])] = true;
                }
            } elseif ($token[0] === T_NAME_QUALIFIED) {
                $mentioned[strtolower(explode('\\', $token[1])[0])] = true;
            } elseif ($token[0] === T_DOC_COMMENT && preg_match_all('/(?<![\w\\\$])[A-Z]\w*/', $token[1], $words) > 0) {
                foreach ($words[0] as $word) {
                    $mentioned[strtolower($word)] = true;
                }
            }
        }

        // An existing import is the meaning the file already gives that name, not a competing one.
        foreach (array_keys($imports) as $alias) {
            unset($mentioned[strtolower($alias)]);
        }

        return $mentioned;
    }

    /**
     * Rewrites the class import group with the new imports merged in, sorted the way the templates sort
     * theirs, and adds the `use const` group after it. The function group is
     * {@see GlobalFunctionImports}' to add, between the two.
     *
     * @param array<string, string> $classAliases fully-qualified name => alias
     * @param list<string> $constants
     */
    private static function withImports(string $php, array $classAliases, array $constants): string
    {
        preg_match('/^(namespace [^;]+;\n\n)((?:use (?!function |const )[^;]+;\n)*)\n*/m', $php, $match);
        if ($match === []) {
            return $php;
        }

        $lines = $match[2] === '' ? [] : explode("\n", rtrim($match[2], "\n"));
        foreach ($classAliases as $name => $alias) {
            $tail = substr((string)strrchr('\\' . $name, '\\'), 1);
            $lines[] = 'use ' . $name . ($alias === $tail ? '' : ' as ' . $alias) . ';';
        }
        $lines = array_values(array_unique($lines));
        sort($lines);

        $constantLines = [];
        preg_match_all('/^use const (\w+);\n/m', $php, $existing);
        foreach ([...$existing[1], ...$constants] as $constant) {
            $constantLines[$constant] = 'use const ' . $constant . ';';
        }
        ksort($constantLines);
        $php = (string)preg_replace('/^use const \w+;\n\n?/m', '', $php);

        $classGroup = $lines === [] ? '' : implode("\n", $lines) . "\n\n";
        $constGroup = $constantLines === [] ? '' : implode("\n", $constantLines) . "\n\n";

        // Classes, functions, constants — php-cs-fixer's order. A function group already in the file
        // keeps its place between the other two; without one the constants follow the classes.
        $hasFunctions = preg_match('/^use function \w+;$/m', $php) === 1;
        $php = (string)preg_replace_callback(
            '/^(namespace [^;]+;\n\n)((?:use (?!function |const )[^;]+;\n)*)\n*/m',
            static fn(array $found): string => $found[1] . $classGroup . ($hasFunctions ? '' : $constGroup),
            $php,
            1,
        );

        return $hasFunctions
            ? (string)preg_replace_callback(
                '/^(?:use function \w+;\n)+\n*/m',
                static fn(array $found): string => $found[0] . $constGroup,
                $php,
                1,
            )
            : $php;
    }
}
