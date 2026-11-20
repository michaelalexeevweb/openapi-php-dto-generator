<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use PhpToken;
use RuntimeException;

/**
 * Keeps the part of a generation `--only` asks for and drops what `--exclude` names, without ever
 * leaving a class that points at one that is not there.
 *
 * Masks are `fnmatch()` patterns matched against the generated class's short name, ignoring case.
 * `--only` picks the classes a run starts from and keeps everything they reach: a property's class,
 * an item class, a union interface, a parent. `--exclude` drops what it names, and a kept class that
 * still needs a dropped one is an error, not a broken file. A Laravel `<Dto>FormRequest` belongs to
 * its DTO: it is matched by the DTO's name and goes wherever the DTO goes.
 *
 * What a class needs is read from the emitted code itself — every class name its tokens, strings and
 * docblock tags spell — because that is the only list that cannot miss a reference an emitter adds
 * later. A word that only happens to look like a class name keeps one class too many, never too few.
 */
final class GeneratedClassFilter
{
    private const string COMPANION_SUFFIX = 'FormRequest';

    /**
     * The tags whose first word is a type. The rest — `@see`, `@deprecated` — carry text, and the text
     * of a generated docblock is the document's own description.
     */
    private const string TYPE_TAG = '/^(?:(?:phpstan|psalm)-)?(?:var|param|return|throws|property(?:-read|-write)?|extends|implements|mixin|template(?:-\w+)?)$/';

    /** @var array<string, true> */
    private array $matchedMasks = [];

    /**
     * @param list<string> $only
     * @param list<string> $exclude
     */
    public function __construct(
        private readonly array $only,
        private readonly array $exclude,
    ) {
    }

    public function isActive(): bool
    {
        return $this->only !== [] || $this->exclude !== [];
    }

    /**
     * @param array<string, string> $files absolute path => contents
     * @return array<string, string> the files to keep
     */
    public function filter(array $files): array
    {
        if (!$this->isActive()) {
            return $files;
        }

        /** @var array<string, array{path: string, shortName: string, interface: bool}> $classes fqcn => declaration */
        $classes = [];
        // Tokenized once per file: the declaration and the references are read from the same tokens.
        $tokensByPath = [];
        foreach ($files as $path => $content) {
            $tokensByPath[$path] = PhpToken::tokenize($content);
            $declared = $this->declaredClass($tokensByPath[$path]);
            if ($declared !== null) {
                $classes[$declared['fqcn']] = ['path' => $path, 'shortName' => $declared['shortName'], 'interface' => $declared['interface']];
            }
        }

        // A union is an interface its members implement: the interface names none of them, yet a
        // property typed with it is useless without them, so a kept interface keeps its implementers.
        $needs = [];
        $implementers = [];
        foreach ($classes as $fqcn => $class) {
            ['all' => $needs[$fqcn], 'header' => $header] = $this->referencedClasses(tokens: $tokensByPath[$class['path']], classes: $classes);
            foreach ($header as $parent) {
                if ($parent !== $fqcn && $classes[$parent]['interface']) {
                    $implementers[$parent][] = $fqcn;
                }
            }
        }

        $excluded = [];
        $roots = [];
        foreach ($classes as $fqcn => $class) {
            $name = $this->matchName(fqcn: $fqcn, shortName: $class['shortName'], classes: $classes);
            if ($this->matchesAny(option: '--exclude', masks: $this->exclude, name: $name)) {
                $excluded[$fqcn] = true;
                continue;
            }
            if ($this->only === [] || $this->matchesAny(option: '--only', masks: $this->only, name: $name)) {
                $roots[] = $fqcn;
            }
        }

        $kept = [];
        $queue = $roots;
        while ($queue !== []) {
            $fqcn = array_shift($queue);
            if (array_key_exists($fqcn, $kept)) {
                continue;
            }
            $kept[$fqcn] = true;
            foreach ([...$needs[$fqcn], ...$implementers[$fqcn] ?? []] as $needed) {
                if (array_key_exists($needed, $excluded)) {
                    throw new RuntimeException(message: sprintf(
                        '%s needs %s, which --exclude removes. Exclude %s as well, or narrow the --exclude mask.',
                        $fqcn,
                        $needed,
                        $classes[$fqcn]['shortName'],
                    ));
                }
                if (!array_key_exists($needed, $kept)) {
                    $queue[] = $needed;
                }
            }
        }

        $dropped = [];
        foreach ($classes as $fqcn => $class) {
            if (!array_key_exists($fqcn, $kept)) {
                $dropped[$class['path']] = true;
            }
        }

        return array_filter(
            array: $files,
            callback: static fn(string $path): bool => !array_key_exists($path, $dropped),
            mode: ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * A mask that matched no class in the whole run is a typo or a stale name; saying nothing would
     * leave the reader believing it did something.
     */
    public function assertEveryMaskMatched(): void
    {
        foreach (['--only' => $this->only, '--exclude' => $this->exclude] as $option => $masks) {
            foreach ($masks as $mask) {
                if (!array_key_exists($option . '=' . $mask, $this->matchedMasks)) {
                    throw new RuntimeException(sprintf('%s "%s" matches no generated class.', $option, $mask));
                }
            }
        }
    }

    /**
     * @param list<string> $masks
     */
    private function matchesAny(string $option, array $masks, string $name): bool
    {
        $matched = false;
        foreach ($masks as $mask) {
            if (fnmatch($mask, $name, FNM_CASEFOLD)) {
                $this->matchedMasks[$option . '=' . $mask] = true;
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, array{path: string, shortName: string}> $classes
     */
    private function matchName(string $fqcn, string $shortName, array $classes): string
    {
        if (!str_ends_with($shortName, self::COMPANION_SUFFIX)) {
            return $shortName;
        }
        $owner = substr($fqcn, 0, -strlen(self::COMPANION_SUFFIX));

        return array_key_exists($owner, $classes)
            ? substr($shortName, 0, -strlen(self::COMPANION_SUFFIX))
            : $shortName;
    }

    /**
     * @param array<int, PhpToken> $tokens
     * @return array{fqcn: string, shortName: string, interface: bool}|null
     */
    private function declaredClass(array $tokens): ?array
    {
        $namespace = '';
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $name = $this->nextSignificant(tokens: $tokens, index: $index);
                if ($name !== null && in_array($name->id, [T_NAME_QUALIFIED, T_STRING], true)) {
                    $namespace = $name->text;
                }
                continue;
            }
            if (!in_array($token->id, [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
                continue;
            }
            $previous = $this->previousSignificant(tokens: $tokens, index: $index);
            if ($previous !== null && in_array($previous->id, [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $name = $this->nextSignificant(tokens: $tokens, index: $index);
            if ($name !== null && $name->id === T_STRING) {
                return [
                    'fqcn' => ltrim($namespace . '\\' . $name->text, '\\'),
                    'shortName' => $name->text,
                    'interface' => $token->id === T_INTERFACE,
                ];
            }
        }

        return null;
    }

    /**
     * Every generated class the source names: a qualified name (a `use` line, an inline `\Fqcn`), a
     * short name — imported or of the same namespace —, a class string, and the type in a
     * `@var`/`@param`/… tag (not the description after it), which the runtime mode reads to know a
     * list's item class. `header` is what the declaration line itself names: the parent and the
     * implemented interfaces.
     *
     * @param array<int, PhpToken> $tokens
     * @param array<string, array{path: string, shortName: string, interface: bool}> $classes
     * @return array{all: list<string>, header: list<string>}
     */
    private function referencedClasses(array $tokens, array $classes): array
    {
        $namespace = '';
        $imports = [];
        $names = [];
        $header = [];
        $inHeader = false;
        $headerSeen = false;
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $name = $this->nextSignificant(tokens: $tokens, index: $index);
                $namespace = $name !== null ? $name->text : '';
                continue;
            }
            if ($token->id === T_USE) {
                $imported = $this->nextSignificant(tokens: $tokens, index: $index);
                if ($imported !== null && in_array($imported->id, [T_NAME_QUALIFIED, T_STRING], true)) {
                    $segments = explode('\\', $imported->text);
                    $imports[end($segments)] = $imported->text;
                }
            }
            if (!$headerSeen && in_array($token->id, [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                $previous = $this->previousSignificant(tokens: $tokens, index: $index);
                $inHeader = $previous === null || !in_array($previous->id, [T_DOUBLE_COLON, T_NEW], true);
                $headerSeen = $inHeader;
            } elseif ($inHeader && $token->text === '{') {
                $inHeader = false;
            }

            $spelled = [];
            if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $spelled[] = $token->text;
            } elseif ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                $spelled[] = str_replace('\\\\', '\\', substr($token->text, 1, -1));
            } elseif ($token->id === T_DOC_COMMENT) {
                preg_match_all('/@([\w-]+)[ \t]+([^\n]*)/', $token->text, $tags, PREG_SET_ORDER);
                foreach ($tags as [, $tagName, $tagText]) {
                    if (preg_match(self::TYPE_TAG, $tagName) !== 1) {
                        continue;
                    }
                    preg_match_all('/\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\[A-Za-z_][A-Za-z0-9_]*)*/', $this->leadingType($tagText), $words);
                    array_push($spelled, ...$words[0]);
                }
            }
            foreach ($spelled as $name) {
                $resolved = $this->resolveName(name: $name, namespace: $namespace, imports: $imports, classes: $classes);
                if ($resolved === null) {
                    continue;
                }
                $names[$resolved] = true;
                if ($inHeader) {
                    $header[] = $resolved;
                }
            }
        }

        return [
            'all' => array_keys($names),
            'header' => $header,
        ];
    }

    /**
     * The type a tag opens with — `array<string, list<Item>>|null` — and not the description after it:
     * `@var string a Holder's note` names no class. The type ends at the first blank outside `<>`, `()`,
     * `{}` and `[]`, so a generic's `, ` stays inside it.
     */
    private function leadingType(string $tagText): string
    {
        $depth = 0;
        $length = strlen($tagText);
        for ($index = 0; $index < $length; $index++) {
            $character = $tagText[$index];
            if (str_contains('<({[', $character)) {
                $depth++;
            } elseif (str_contains('>)}]', $character)) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ctype_space($character)) {
                return substr($tagText, 0, $index);
            }
        }

        return $tagText;
    }

    /**
     * @param array<string, string> $imports
     * @param array<string, array{path: string, shortName: string, interface: bool}> $classes
     */
    private function resolveName(string $name, string $namespace, array $imports, array $classes): ?string
    {
        $qualified = ltrim($name, '\\');
        $candidates = [$qualified, ltrim($namespace . '\\' . $qualified, '\\')];
        if (array_key_exists($qualified, $imports)) {
            array_unshift($candidates, $imports[$qualified]);
        }
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $classes)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function nextSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($next = $index + 1; $next < count($tokens); $next++) {
            if (!$tokens[$next]->isIgnorable()) {
                return $tokens[$next];
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function previousSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            if (!$tokens[$previous]->isIgnorable()) {
                return $tokens[$previous];
            }
        }

        return null;
    }
}
