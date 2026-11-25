<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use WeakMap;

/**
 * What the validator, the deserializer and the normalizer share, so that none of the three depends on
 * another: the spelling of a message, the ECMA-262 to PCRE pattern translation, one question about a
 * schema's containers, and the note of a `{}` held as `[]`.
 *
 * Pure functions, apart from that one note (a WeakMap keyed by the DTO). Not instantiable.
 *
 * @internal machinery shared by the runtime services and the DTOs generated for them, not an API
 */
final class DtoServiceSupport
{
    /**
     * How many `anyOf`/`oneOf` levels {@see admitsObjectContainerOnly()} reads through.
     */
    private const int MAX_NESTED_UNION_DEPTH = 16;

    /** ECMA-262 ASCII escapes outside a character class. */
    private const array ASCII_ATOM_ESCAPES = [
        'd' => '[0-9]',
        'D' => '[^0-9]',
        'w' => '[A-Za-z0-9_]',
        'W' => '[^A-Za-z0-9_]',
        'b' => '(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]))',
        'B' => '(?:(?<=[A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<![A-Za-z0-9_])(?![A-Za-z0-9_]))',
    ];

    /** The same inside a class, as ranges; `\b` there is a backspace in both dialects. */
    private const array ASCII_CLASS_ESCAPES = [
        'd' => '0-9',
        'D' => '\x{0}-\x{2F}\x{3A}-\x{10FFFF}',
        'w' => 'A-Za-z0-9_',
        'W' => '\x{0}-\x{2F}\x{3A}-\x{40}\x{5B}-\x{5E}\x{60}\x{7B}-\x{10FFFF}',
    ];

    /**
     * ECMA-262's General_Category values that PCRE does not know by that name — the long names and
     * their aliases — mapped to the short name PCRE does know. ECMA-262 accepts both spellings under
     * its `u` flag (`\p{Letter}` and `\p{L}`); PCRE only the short one, and a long name was a pattern
     * that never compiled.
     *
     * `LC` goes to `L&`, the spelling every PCRE version reads; the short names map to themselves only
     * where that is needed, so the rest pass through untouched.
     *
     * @var array<string, string>
     */
    public const array UNICODE_GENERAL_CATEGORIES = [
        'Cased_Letter' => 'L&',
        'LC' => 'L&',
        'Close_Punctuation' => 'Pe',
        'Connector_Punctuation' => 'Pc',
        'Control' => 'Cc',
        'cntrl' => 'Cc',
        'Currency_Symbol' => 'Sc',
        'Dash_Punctuation' => 'Pd',
        'Decimal_Number' => 'Nd',
        'digit' => 'Nd',
        'Enclosing_Mark' => 'Me',
        'Final_Punctuation' => 'Pf',
        'Format' => 'Cf',
        'Initial_Punctuation' => 'Pi',
        'Letter' => 'L',
        'Letter_Number' => 'Nl',
        'Line_Separator' => 'Zl',
        'Lowercase_Letter' => 'Ll',
        'Mark' => 'M',
        'Combining_Mark' => 'M',
        'Math_Symbol' => 'Sm',
        'Modifier_Letter' => 'Lm',
        'Modifier_Symbol' => 'Sk',
        'Nonspacing_Mark' => 'Mn',
        'Number' => 'N',
        'Open_Punctuation' => 'Ps',
        'Other' => 'C',
        'Other_Letter' => 'Lo',
        'Other_Number' => 'No',
        'Other_Punctuation' => 'Po',
        'Other_Symbol' => 'So',
        'Paragraph_Separator' => 'Zp',
        'Private_Use' => 'Co',
        'Punctuation' => 'P',
        'punct' => 'P',
        'Separator' => 'Z',
        'Space_Separator' => 'Zs',
        'Spacing_Mark' => 'Mc',
        'Surrogate' => 'Cs',
        'Symbol' => 'S',
        'Titlecase_Letter' => 'Lt',
        'Unassigned' => 'Cn',
        'Uppercase_Letter' => 'Lu',
    ];

    /**
     * The fields of each built DTO that hold an empty JSON OBJECT as an empty PHP array, by DTO.
     *
     * A union with an `array` member and an object one (`anyOf: [{type: array}, {type: object}]`) is
     * typed `array`, and `{}` and `[]` are the same empty PHP array once it holds them. The deserializer
     * still sees the `stdClass` it decoded and notes the field here; the normalizer, re-validation and
     * a generated `toArray()` write such a field back as `{}`. A WeakMap keyed by the DTO itself, so a
     * note goes when its object goes, and a DTO built by hand is never in it.
     *
     * @var WeakMap<object, array<string, true>>|null
     */
    private static ?WeakMap $emptyJsonObjects = null;

    private function __construct()
    {
    }

    /**
     * One shape for every message this package writes: a full stop at the end, and a capital at the
     * start unless the sentence begins with a name the document chose.
     *
     * The validator, the normalizer and the deserializer all call it for the messages they build, so
     * there is ONE spelling of the rule rather than three.
     *
     * The two halves are not a style preference, they are what the messages already are:
     *
     *     Required parameter "value.id" not found in request.   a sentence with its own subject
     *     param "platforms" expects array, got string.          a SUBJECT LABEL plus a predicate
     *
     * The second kind is shared by three modes and spelled differently on purpose — `param "f"` in
     * runtime mode, `field "f"` in Symfony mode, and in Laravel mode the bare property path, because
     * Laravel keys its error bag by that path and the message sits beside the key. Capitalising there
     * would rewrite an identifier the document owns: `children.leaves.title` is not
     * `Children.leaves.title`. So the subject is left as its owner spelled it, and only a sentence
     * opening with an English word is capitalised.
     *
     * The full stop is unconditional, because these are read as a list — `DtoNormalizer` joins them
     * with `', '` behind `DTO validation failed:` — and half of them ending in one reads as a defect.
     *
     * Idempotent: what has been through already may go through again, which is what lets each class
     * finalise at its own exit without minding what another one did.
     *
     * @param array<int, string> $messages
     * @param string|null $subject the subject label these messages may begin with, when the caller
     *        knows it; a message starting with it keeps its spelling
     * @return array<int, string>
     */
    public static function finalizeMessages(array $messages, ?string $subject = null): array
    {
        // The ordinary verdict is "nothing wrong": no closure to build for an empty list.
        if ($messages === []) {
            return $messages;
        }

        return array_map(
            callback: static fn(string $message): string => self::finalizeMessage($message, $subject),
            array: $messages,
        );
    }

    public static function finalizeMessage(string $message, ?string $subject = null): string
    {
        $message = rtrim($message);
        if ($message === '') {
            return $message;
        }

        // A message that opens with the subject opens with a name, not a word.
        $startsWithSubject = $subject !== null && $subject !== '' && str_starts_with($message, $subject);
        if (!$startsWithSubject) {
            $message = ucfirst($message);
        }

        return str_ends_with($message, '.') ? $message : $message . '.';
    }

    /**
     * Delimit a schema pattern without escaping an already escaped delimiter twice, and keep the
     * ECMA-262 ASCII meaning of `\d` `\w` `\b` under the Unicode modifier.
     */
    public static function delimitPattern(string $pattern, bool $unicode = true): string
    {
        $delimited = '#';
        $length = strlen($pattern);
        $inClass = false;
        $classOpenedAt = -1;
        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];
            if ($character === '\\' && $i + 1 < $length) {
                $next = $pattern[$i + 1];
                // ECMA-262 `\uXXXX` and `\u{X…}` are PCRE's `\x{…}`; PCRE has no `\u` at all.
                if ($next === 'u' && preg_match('/\G(?:\{([0-9A-Fa-f]{1,6})\}|([0-9A-Fa-f]{4}))/', $pattern, $hex, 0, $i + 2) === 1) {
                    $delimited .= '\x{' . ($hex[1] !== '' ? $hex[1] : $hex[2]) . '}';
                    $i += 1 + strlen($hex[0]);
                    continue;
                }
                // ECMA-262 `\p{…}` under the `u` flag names a property as PCRE does not: see
                // `unicodePropertyName()`. Without the flag it is a plain `p`, as it always was here.
                if ($unicode && ($next === 'p' || $next === 'P') && preg_match('/\G\{([A-Za-z0-9_]+(?:=[A-Za-z0-9_]+)?)\}/', $pattern, $property, 0, $i + 2) === 1) {
                    $delimited .= '\\' . $next . '{' . self::unicodePropertyName($property[1]) . '}';
                    $i += 1 + strlen($property[0]);
                    continue;
                }
                $i++;
                // ECMA-262 `\v` is the one vertical TAB; PCRE's is a class that also holds `\n` and `\r`.
                if ($next === 'v') {
                    $delimited .= '\x{B}';
                    continue;
                }
                // ECMA-262 `\d` `\w` `\b` are ASCII, even under its `u` flag. PHP's `u` also turns on
                // Unicode properties, where `\d` matches `٣` and `\w` matches `ж`: spell them out.
                $ascii = $unicode ? ($inClass ? self::ASCII_CLASS_ESCAPES : self::ASCII_ATOM_ESCAPES)[$next] ?? null : null;
                $delimited .= $ascii ?? '\\' . $next;
                continue;
            }
            if ($inClass && $character === '[' && ($pattern[$i + 1] ?? '') === ':') {
                // A POSIX class carries its own `]`; copied whole, it cannot close the outer class.
                $end = strpos($pattern, ':]', $i + 2);
                if ($end !== false) {
                    $delimited .= substr($pattern, $i, $end + 2 - $i);
                    $i = $end + 1;
                    continue;
                }
            }
            // ECMA-262 `[^]` is any character and `[]` none; PCRE reads the `]` as a member and the
            // class never closes, so every value failed as "invalid regex".
            if (!$inClass && $character === '[' && substr($pattern, $i, 3) === '[^]') {
                $delimited .= '[\s\S]';
                $i += 2;
                continue;
            }
            if (!$inClass && $character === '[' && ($pattern[$i + 1] ?? '') === ']') {
                $delimited .= '(?!)';
                $i++;
                continue;
            }
            // ECMA-262 `.` stops at every line terminator; PCRE's stops at `\n` alone.
            if (!$inClass && $character === '.') {
                $delimited .= $unicode ? '[^\n\r\x{2028}\x{2029}]' : '[^\n\r]';
                continue;
            }
            if (!$inClass && $character === '[') {
                $inClass = true;
                $classOpenedAt = ($pattern[$i + 1] ?? '') === '^' ? $i + 1 : $i;
            } elseif ($inClass && $character === ']' && $i > $classOpenedAt + 1) {
                // PCRE reads a `]` right after `[` or `[^` as a literal member, not as the end.
                $inClass = false;
            }
            $delimited .= $character === '#' ? '\#' : $character;
        }

        // `D`: ECMA-262 `$` is the very end. PCRE's also matches before a final `\n`, and
        // `^[a-z]+$` let "abc\n" through to a log line or a header.
        return $delimited . ($unicode ? '#uD' : '#D');
    }

    /**
     * The PCRE spelling of an ECMA-262 Unicode property name — the part between the braces of `\p{…}`.
     *
     * - a General_Category value, alone or as `General_Category=…` / `gc=…`: its short name
     *   (`Letter` → `L`, `gc=Lu` → `Lu`);
     * - `Script=…` / `sc=…`: the bare script name, which is how PCRE has always read a script
     *   (`Script=Greek` → `Greek`);
     * - anything else — a binary property (`Alphabetic`), `Script_Extensions=…` — as written: PCRE
     *   reads those under the same names, and one it does not know stays a pattern that does not
     *   compile, reported as such.
     */
    public static function unicodePropertyName(string $name): string
    {
        $parts = explode('=', $name, 2);
        if (count($parts) === 1) {
            return self::UNICODE_GENERAL_CATEGORIES[$name] ?? $name;
        }

        return match ($parts[0]) {
            'General_Category', 'gc' => self::UNICODE_GENERAL_CATEGORIES[$parts[1]] ?? $parts[1],
            'Script', 'sc' => $parts[1],
            default => $name,
        };
    }

    /**
     * Whether the only JSON container a schema admits is an object, so that a PHP list held for it
     * is that object keyed `0..n-1` (`{}` among them) and never a JSON array.
     *
     * `type` decides when it is there. Without it, a property-level `anyOf`/`oneOf` decides: every
     * branch other than `type: null` that admits a container admits an object, and none admits an
     * array — `[{type: object}, {type: null}]`, `[map, string]`. A branch naming no type admits
     * either, so it answers no. A branch that is a union itself — `oneOf: [{oneOf: [{type: object}]}]`
     * — answers by ITS branches: read one level deep, the nested object member was an untyped branch,
     * and `{}` held as `[]` was refused as "expected object, got array". The deserializer, the
     * normalizer and the emitted `toArray()` read a hydrated union value by it, the way they read a
     * `type: object` map.
     *
     * @param array<array-key, mixed> $constraints
     */
    public static function admitsObjectContainerOnly(array $constraints, int $depth = 0): bool
    {
        if (array_key_exists('type', $constraints)) {
            $type = $constraints['type'];
            $types = is_string($type) ? [$type] : (is_array($type) ? $type : []);

            return in_array('object', $types, true) && !in_array('array', $types, true);
        }

        $sawObject = false;
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $constraints[$keyword] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    return false;
                }
                $type = $branch['type'] ?? null;
                $types = is_string($type) ? [$type] : (is_array($type) ? $type : []);
                if ($types === [] && self::isBareUnion($branch) && $depth < self::MAX_NESTED_UNION_DEPTH) {
                    if (!self::admitsObjectContainerOnly($branch, $depth + 1)) {
                        return false;
                    }
                    $sawObject = true;

                    continue;
                }
                if ($types === [] || in_array('array', $types, true)) {
                    return false;
                }
                $sawObject = $sawObject || in_array('object', $types, true);
            }
        }

        return $sawObject;
    }

    /**
     * Whether a branch names no type and is a union: an `anyOf`/`oneOf` and nothing that could admit
     * a container on its own.
     *
     * @param array<array-key, mixed> $branch
     */
    private static function isBareUnion(array $branch): bool
    {
        return (array_key_exists('anyOf', $branch) || array_key_exists('oneOf', $branch))
            && !array_key_exists('allOf', $branch)
            && !array_key_exists('x-php-instanceof', $branch);
    }

    /**
     * Notes the PHP names of the fields of `$dto` that hold `{}` as `[]`.
     *
     * @param array<string, true> $propertyNames
     */
    public static function rememberEmptyJsonObjects(object $dto, array $propertyNames): void
    {
        if ($propertyNames === []) {
            return;
        }
        self::$emptyJsonObjects ??= new WeakMap();
        self::$emptyJsonObjects[$dto] = $propertyNames;
    }

    /**
     * The PHP names of the fields of `$dto` that hold `{}` as `[]`.
     *
     * @return array<string, true>
     */
    public static function emptyJsonObjectsOf(object $dto): array
    {
        if (self::$emptyJsonObjects === null || !self::$emptyJsonObjects->offsetExists($dto)) {
            return [];
        }

        return self::$emptyJsonObjects[$dto];
    }
}
