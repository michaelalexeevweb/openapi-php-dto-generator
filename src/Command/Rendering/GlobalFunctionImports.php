<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command\Rendering;

/**
 * Names every global function the generated code calls the same way its classes are named: imported.
 *
 * An unqualified call inside a namespace makes PHP look for a namespaced twin that never exists before
 * it falls back to the global one. Measured on this package's own hot path that lookup costs roughly
 * 29 ns a call, which the fast hydrator pays once per property; `use function` resolves at compile time
 * and measures the same as writing the leading backslash. So the import is both the consistent spelling
 * and the free one, and there is no reason for the two forms to coexist in one file. Isolated on the
 * generated hydrator, the imports are worth about 10%.
 *
 * `function_exists()` answers for the GENERATING process, not the running one. That asymmetry adds no
 * failure mode in either direction: a generator missing an extension leaves those calls unqualified,
 * which is what they were before this class existed, and an import naming a function the runtime does
 * not have is not an error until the call runs — which would have failed regardless.
 *
 * The list is read back off the RENDERED PHP rather than predicted from template context. Most of these
 * calls sit behind template conditions, and the emitter has four render paths — two Twig call sites for
 * DTOs, one for enums, and yii3's string builder. A predicted list would have to mirror every one of
 * those conditions and would drift the first time one changed; reading the output cannot.
 *
 * Reading it means TOKENISING it, not matching it. A regex over the source cannot tell `#[Date(...)]`
 * from a call to `date()`, and PHP matches function names case-insensitively, so the first version of
 * this class imported `Date` from a yii3 validation attribute. The tokeniser also puts strings and
 * docblocks out of reach for free.
 *
 * Function imports live in their own symbol table, so none of them can shadow a generated class — this
 * is safe in a way the class imports are not.
 */
final class GlobalFunctionImports
{
    /**
     * Tokens that make the name after them something other than a call: a class being instantiated or
     * declared, a member of something, an already-qualified name, or a function being defined.
     */
    private const array NAME_IS_NOT_A_CALL_AFTER = [
        T_CLASS,
        T_DOUBLE_COLON,
        T_ENUM,
        T_EXTENDS,
        T_FUNCTION,
        T_IMPLEMENTS,
        T_INTERFACE,
        T_NEW,
        T_NS_SEPARATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_OBJECT_OPERATOR,
        T_TRAIT,
        T_USE,
    ];

    /**
     * @return list<string> the global functions $php calls unqualified, sorted, without duplicates
     */
    public static function detect(string $php): array
    {
        $tokens = self::significantTokens($php);
        $declared = self::declaredFunctionNames($tokens);

        $found = [];
        $attributeDepth = 0;
        $bracketStack = [];

        foreach ($tokens as $index => $token) {
            // Attributes read like calls and are not. Everything between `#[` and its matching `]`
            // is a constant expression, so nothing in there can be one.
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $bracketStack[] = true;
                $attributeDepth++;

                continue;
            }

            if ($token === '[') {
                $bracketStack[] = false;

                continue;
            }

            if ($token === ']') {
                if (array_pop($bracketStack) === true) {
                    $attributeDepth--;
                }

                continue;
            }

            if ($attributeDepth > 0 || !is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];
            if (($tokens[$index + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], self::NAME_IS_NOT_A_CALL_AFTER, true)) {
                continue;
            }

            $lowered = strtolower($name);
            if (
                array_key_exists($lowered, $declared)
                || array_key_exists($name, $found)
                || !function_exists($name)
            ) {
                continue;
            }

            $found[$name] = true;
        }

        $detected = array_keys($found);
        sort($detected);

        return $detected;
    }

    /**
     * Adds the `use function` group under the class imports, as its own group the way php-cs-fixer
     * separates the two kinds. A file that imports no class gets the group directly under the
     * namespace, with one blank line either side.
     */
    public static function apply(string $php): string
    {
        // No template writes a `use function` line, so any group already present is one of ours from
        // an earlier pass. Dropping it first makes this idempotent and self-healing rather than
        // stacking a second group under the first. The blank line the group left behind goes with it,
        // and only then — an untouched file keeps whatever spacing its template asked for.
        $stripped = (string)preg_replace('/^use function \w+;\n/m', '', $php);
        if ($stripped !== $php) {
            $php = (string)preg_replace('/^(use [^;]+;\n)\n+(?=\S)/m', "$1\n", $stripped);
        }

        $functions = self::detect($php);
        if ($functions === []) {
            return $php;
        }

        $group = '';
        foreach ($functions as $function) {
            $group .= 'use function ' . $function . ";\n";
        }

        // The blank line AFTER the group is put back, because inserting the group consumes the one the
        // template had written under its class imports. Without it the group ran straight into the
        // class docblock — `Header blocks must be separated by a single blank line`, on every Laravel
        // file that imported a function. Measured with PSR-12 over the emitted corpus; nothing else
        // reads the style of the code this generator writes.
        return (string)preg_replace_callback(
            '/^(namespace [^;]+;\n\n)((?:use [^;]+;\n)*)\n*/m',
            static fn(array $match): string => $match[1]
                . ($match[2] === '' ? '' : $match[2] . "\n")
                . $group
                . "\n",
            $php,
            1,
        );
    }

    /**
     * The token stream with whitespace and comments dropped, reindexed, so "the token before this one"
     * is a plain `$tokens[$index - 1]` rather than a scan backwards past formatting.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significantTokens(string $php): array
    {
        $significant = [];
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * Methods and functions the file declares itself. A generated DTO is free to declare `getData()`
     * or `toArray()`, and one day it may declare something that shares a name with a global function —
     * importing that name would then point the call at the wrong body.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<string, true> keyed by the lowercased name, because PHP matches them that way
     */
    private static function declaredFunctionNames(array $tokens): array
    {
        $declared = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // `use function implode;` is the same two tokens as a declaration. Reading it as one made
            // every already-imported function invisible to detect(), so applying the group twice
            // erased it — and the invariant test, which runs detect() over the finished file, saw a
            // file that imported everything and called nothing.
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && $previous[0] === T_USE) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next === '&') {
                $next = $tokens[$index + 2] ?? null;
            }

            if (is_array($next) && $next[0] === T_STRING) {
                $declared[strtolower($next[1])] = true;
            }
        }

        return $declared;
    }
}
