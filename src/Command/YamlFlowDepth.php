<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

/**
 * How deep a YAML text nests its flow collections (`[ ]`, `{ }`), measured without parsing it.
 *
 * symfony/yaml lexes a flow collection by recursing once per opening bracket, and its own nesting limit
 * only applies after the lexer is done — so 100 000 `[` in a 200 KB document overflowed the C stack and
 * the process died with SIGSEGV, saying nothing. This scan walks the text the way that lexer does,
 * iteratively, and stops counting as soon as the limit is passed.
 *
 * It follows the lexer where the lexer goes: a flow collection starts where a block VALUE starts with a
 * bracket; inside it, quoted strings, comments and plain tokens are skipped as the lexer skips them.
 * Brackets in quoted scalars, block scalars, comments and plain text (`values in [0, 1)`) nest nothing,
 * and are not counted.
 */
final class YamlFlowDepth
{
    /**
     * The deepest flow nesting in `$yaml`, or the first depth past `$limit` — the scan stops there.
     */
    public static function measure(string $yaml, int $limit): int
    {
        $depth = 0;
        $deepest = 0;
        // The quote of a quoted scalar still open at the end of a line: both kinds may span lines.
        $quote = null;
        // The indentation of the line that opened a block scalar (`|`, `>`); the lines indented deeper
        // are its text.
        $blockScalarIndent = null;

        $lines = preg_split('/\r\n|\r|\n/', $yaml);
        foreach (is_array($lines) ? $lines : [] as $line) {
            $length = strlen($line);
            $indent = strspn($line, ' ');
            if ($blockScalarIndent !== null) {
                if ($indent === $length || $indent > $blockScalarIndent) {
                    continue;
                }
                $blockScalarIndent = null;
            }

            $cursor = 0;
            if ($quote === null && $depth === 0) {
                $cursor = self::blockValueStart(line: $line, indent: $indent);
                $first = $line[$cursor] ?? '';
                if ($first === '"' || $first === "'") {
                    $quote = $first;
                    $cursor++;
                } elseif ($first !== '[' && $first !== '{') {
                    if (preg_match('/\G[|>][-+0-9]*[ \t]*(?:#.*)?$/', $line, offset: $cursor) === 1) {
                        $blockScalarIndent = $indent;
                    }

                    continue;
                }
            }

            while ($cursor < $length) {
                $char = $line[$cursor];

                if ($quote !== null) {
                    if ($quote === '"' && $char === '\\') {
                        $cursor += 2;
                        continue;
                    }
                    $cursor++;
                    if ($char !== $quote) {
                        continue;
                    }
                    if ($quote === "'" && ($line[$cursor] ?? '') === "'") {
                        $cursor++;
                        continue;
                    }
                    $quote = null;
                    if ($depth === 0) {
                        // A quoted block value ends the line's interest: what follows it is no value.
                        break;
                    }
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $cursor++;
                } elseif ($char === '[' || $char === '{') {
                    $depth++;
                    $deepest = max($deepest, $depth);
                    if ($deepest > $limit) {
                        return $deepest;
                    }
                    $cursor++;
                } elseif ($char === ']' || $char === '}') {
                    $depth--;
                    $cursor++;
                    if ($depth <= 0) {
                        // The collection closed; the rest of the line belongs to the block around it.
                        $depth = 0;
                        break;
                    }
                } elseif ($char === '#') {
                    break;
                } elseif ($char === ' ' || $char === "\t" || $char === ',' || $char === ':') {
                    $cursor++;
                } else {
                    $cursor = self::plainTokenEnd(line: $line, cursor: $cursor);
                }
            }
        }

        return $deepest;
    }

    /**
     * Where a block line's value starts: past the indentation, any `- ` sequence entries, a `key:`
     * (plain or quoted) and any anchors and tags. A comment line has no value, and its offset is the
     * line's end.
     */
    private static function blockValueStart(string $line, int $indent): int
    {
        $cursor = $indent;
        if (preg_match('/\G(?:-(?:[ \t]+|$))+/', $line, $entries, offset: $cursor) === 1) {
            $cursor += strlen($entries[0]);
        }
        $key = '/\G(?:"(?:[^"\\\]|\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s\'"\[\]{}#,][^#]*?)[ \t]*:(?:[ \t]+|$)/';
        if (preg_match($key, $line, $keyMatch, offset: $cursor) === 1) {
            $cursor += strlen($keyMatch[0]);
        }
        if (preg_match('/\G(?:[&!]\S*[ \t]+)+/', $line, $properties, offset: $cursor) === 1) {
            $cursor += strlen($properties[0]);
        }

        return ($line[$cursor] ?? '') === '#' ? strlen($line) : $cursor;
    }

    /**
     * The end of a plain token inside a flow collection — where symfony/yaml's lexer ends it: at a
     * bracket, a comma, a colon, or a space that starts a comment.
     */
    private static function plainTokenEnd(string $line, int $cursor): int
    {
        $length = strlen($line);
        while ($cursor < $length) {
            $char = $line[$cursor];
            if (str_contains('[]{},:', $char)) {
                break;
            }
            if (($char === ' ' || $char === "\t") && ($line[$cursor + 1] ?? '') === '#') {
                break;
            }
            $cursor++;
        }

        return $cursor;
    }
}
