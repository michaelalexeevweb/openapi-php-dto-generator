<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Golden;

use PHPUnit\Framework\TestCase;

/**
 * Where a template output lands in the PHP it writes, and whether the template escapes it there.
 *
 * Twig runs with autoescape off, so `{{ … }}` copies its value into the generated class as it is. A
 * value inside a single-quoted PHP string is safe only if it is escaped for one, and that used to be
 * decided in two places: most outputs carried `|php_string`, about twenty relied on the renderer having
 * escaped (or sanitized) the value before it reached the template. One that relied on it and was not —
 * a parameter `style` — closed the quotes and ran document-supplied code on every request. So there is
 * ONE rule, and this test is its enforcement:
 *
 * - every output inside a single-quoted string ends in `|php_string`, and `|php_string` is used nowhere
 *   else — the renderers hand templates RAW values, so nothing is escaped twice;
 * - no output lands inside a double-quoted string or a heredoc, where `$` and `{$` interpolate;
 * - every `|raw` output — a PHP fragment a renderer built — is listed below with what it is built from,
 *   so a new one is a decision someone wrote down, not an accident.
 *
 * The template is read as PHP: its literal text drives a small lexer state (code, '…', "…", comments),
 * and each output is recorded with the state it sits in. Twig tags contribute no text, which is right
 * for the shapes the templates use — a branch that opens a quote in one arm and not the other would be
 * misread, and none does.
 */
final class TemplateEscapingTest extends TestCase
{
    private const string CONTEXT_CODE = 'code';
    private const string CONTEXT_SINGLE_QUOTED = 'single-quoted';
    private const string CONTEXT_DOUBLE_QUOTED = 'double-quoted';
    private const string CONTEXT_BLOCK_COMMENT = 'block-comment';
    private const string CONTEXT_LINE_COMMENT = 'line-comment';

    /**
     * Every `|raw` output: template => expression => what the fragment is built from.
     *
     * `|raw` changes nothing at runtime (autoescape is off); it marks a value that is PHP CODE, built by
     * a renderer, and therefore must reach the template already safe. Each reason names the builder, so
     * a reviewer can check that every document string inside it went through `phpStringLiteral()` /
     * `renderPhpLiteral()` or is an identifier the generator sanitized.
     *
     * @var array<string, array<string, string>>
     */
    private const array RAW_OUTPUTS = [
        'dto.php.twig' => [
            'entry.value|raw' => 'object constraints as a PHP array literal from renderPhpLiteral()',
            'requestBodyMediaTypes|raw' => 'the media-type list as a PHP array literal from renderPhpLiteral()',
            'unionConstraints|raw' => 'oneOf/anyOf of generated member class names, built by runtimeUnionInterfaceConstraints()',
        ],
        'dto.symfony.php.twig' => [
            'validationConstsBlock|raw' => 'the emitted interpreter constants (RendersSchemaInterpreter), literals via renderPhpLiteral()',
            'validationMethodsBlock|raw' => 'the emitted interpreter methods (RendersSchemaInterpreter), fixed code around sanitized names',
            'param.contextAttribute|raw' => 'a #[Context] attribute from symfonyContextAttribute(), format strings via phpStringLiteral()',
            'param.temporalDefault|raw' => 'a `new DateTimeImmutable(…)` default, the date via escapeSingleQuoted() in the renderer',
            'param.constructorValue|raw' => 'the constructor assignment expression: sanitized property names and fixed code',
            'param.assignedValue|raw' => 'the property assignment expression: sanitized property names and fixed code',
            'param.factoryDefault|raw' => 'a ` = <literal>` default from the shared default-literal builder (escapeSingleQuoted())',
        ],
        'dto.laravel.php.twig' => [
            'interpreterConstsBlock|raw' => 'the emitted interpreter constants (RendersSchemaInterpreter), literals via renderPhpLiteral()',
            'interpreterMethodsBlock|raw' => 'the emitted interpreter methods (RendersSchemaInterpreter), fixed code around sanitized names',
            'rule.rules|raw' => 'a Laravel rules array literal from laravelRulesLiteral(), strings via phpStringLiteral()',
            'param.defaultExpression|raw' => 'the default literal from the shared default-literal builder (escapeSingleQuoted())',
            'param.temporalGetterBody|raw' => 'the temporal getter body from temporalGetterBody(): fixed code, formats via phpStringLiteral()',
            'param.toArrayExpression|raw' => 'the toArray() expression from laravelToArrayExpression(): sanitized names and fixed code',
            'hydrator.expression|raw' => 'the fromValidated() hydration expression: sanitized names, wire keys via phpStringLiteral()',
        ],
        'dto.laravel-data.php.twig' => [
            'attribute|raw' => 'a laravel-data attribute built with sprintf(), every document string via phpStringLiteral()',
            'member.value|raw' => 'a morph-map key literal via phpStringLiteral() (or an int for a numeric value)',
            'morphBase.guard.values|raw' => 'the discriminator values as an array literal, each via phpStringLiteral()',
            'interpreterConstsBlock|raw' => 'the emitted interpreter constants (RendersSchemaInterpreter), literals via renderPhpLiteral()',
            'wireNameRestore.namesLiteral|raw' => 'integer-like wire names as an array literal from renderPhpArrayLiteral()',
            'temporalUnionCast.enumsLiteral|raw' => 'property name (phpStringLiteral()) => list of generated enum class references (`Name::class`) as a PHP array literal',
            'temporalUnionCast.namesLiteral|raw' => 'sanitized PHP property names as an array literal, each via phpStringLiteral()',
            'temporalUnionCast.uncastLiteral|raw' => 'sanitized PHP property names as an array literal, each via phpStringLiteral()',
            'untypedAbsence.namesLiteral|raw' => 'sanitized PHP property names as an array literal, each via phpStringLiteral()',
            'untypedAbsence.defaultedLiteral|raw' => 'sanitized PHP property names as an array literal, each via phpStringLiteral()',
            'emptyObjectWrite.nestedLiteral|raw' => 'PHP property name => wire name as an array literal from renderPhpArrayLiteral()',
            'emptyObjectWrite.itemsLiteral|raw' => 'PHP property name => wire name as an array literal from renderPhpArrayLiteral()',
            'emptyObjectWrite.shapesLiteral|raw' => 'wire name => empty-object shape as an array literal from renderPhpArrayLiteral()',
            'rule.rules|raw' => 'a Laravel rules array literal from laravelRulesLiteral(), strings via phpStringLiteral()',
            'interpreterMethodsBlock|raw' => 'the emitted interpreter methods (RendersSchemaInterpreter), fixed code around sanitized names',
        ],
        'dto.yii3.php.twig' => [
            'interpreterConstsBlock|raw' => 'the emitted interpreter constants (RendersSchemaInterpreter), literals via renderPhpLiteral()',
            'param.getterValue|raw' => 'the getter expression from the yii3 renderer: sanitized property names and fixed code',
            "(param.default ?? 'null')|raw" => 'the schema default as a PHP literal from the shared default-literal builder',
            'param.createValue|raw' => 'the create() assignment expression: sanitized parameter names and fixed code',
            'interpreterMethodsBlock|raw' => 'the emitted interpreter methods (RendersSchemaInterpreter), fixed code around sanitized names',
            'standalonePayloadBlock|raw' => 'the standalone payload methods: fixed code around sanitized names',
            'listShapeBlock|raw' => 'the list-shape check method: fixed code, library names via yii3Lib()',
        ],
        'laravel.nested-validation.php.twig' => [
            'rule.rules|raw' => 'a Laravel rules array literal from laravelRulesLiteral(), strings via phpStringLiteral()',
        ],
    ];

    public function testEverySingleQuotedOutputIsEscapedByTheTemplate(): void
    {
        $unescaped = [];
        $singleQuoted = 0;
        foreach ($this->outputs() as $output) {
            if ($output['context'] !== self::CONTEXT_SINGLE_QUOTED) {
                continue;
            }
            $singleQuoted++;
            if (!$this->endsInPhpString($output['expression'])) {
                $unescaped[] = $output['where'] . ' {{ ' . $output['expression'] . ' }}';
            }
        }

        // The scanner itself is under test here too: if it stopped finding quoted outputs, every
        // assertion below would pass on nothing.
        $this->assertGreaterThan(expected: 100, actual: $singleQuoted);
        $this->assertSame(
            expected: [],
            actual: $unescaped,
            message: 'An output inside a single-quoted PHP string must end in |php_string — escape it in the template, not in the renderer.',
        );
    }

    /**
     * `|php_string` outside quotes is either a value written as bare code — escaped for a context it is
     * not in — or a quote built inside the expression, which hides the context from this test. Both
     * are spelled as `'{{ value|php_string }}'` instead.
     */
    public function testPhpStringIsUsedOnlyInsideSingleQuotes(): void
    {
        $misplaced = [];
        foreach ($this->outputs() as $output) {
            if ($output['context'] !== self::CONTEXT_SINGLE_QUOTED && str_contains($output['expression'], 'php_string')) {
                $misplaced[] = $output['where'] . ' (' . $output['context'] . ') {{ ' . $output['expression'] . ' }}';
            }
        }

        $this->assertSame(expected: [], actual: $misplaced);
    }

    /**
     * In a double-quoted string or a heredoc PHP interpolates `$name` and `{$expr}`, so no escape for
     * single quotes makes a value safe there. No template writes one around an output; this keeps it so.
     */
    public function testNoOutputLandsInAnInterpolatingString(): void
    {
        $interpolated = [];
        foreach ($this->outputs() as $output) {
            if ($output['context'] === self::CONTEXT_DOUBLE_QUOTED) {
                $interpolated[] = $output['where'] . ' {{ ' . $output['expression'] . ' }}';
            }
        }
        foreach ($this->templates() as $name => $source) {
            if (str_contains($source, '<<<')) {
                $interpolated[] = $name . ' writes a heredoc or nowdoc, which this scanner does not read';
            }
        }

        $this->assertSame(expected: [], actual: $interpolated);
    }

    public function testEveryRawOutputIsListedWithItsReason(): void
    {
        $found = [];
        foreach ($this->outputs() as $output) {
            if (preg_match('/\|\s*raw\s*$/', $output['expression']) === 1) {
                $found[$output['template']][$output['expression']] = true;
            }
        }

        $listed = [];
        foreach (self::RAW_OUTPUTS as $template => $expressions) {
            foreach ($expressions as $expression => $reason) {
                $this->assertNotSame(expected: '', actual: trim($reason), message: $template . ' ' . $expression . ' needs a reason');
                $listed[$template][$expression] = true;
            }
        }

        ksort($found);
        ksort($listed);
        $this->assertSame(
            expected: array_map(callback: $this->sortedKeys(...), array: $listed),
            actual: array_map(callback: $this->sortedKeys(...), array: $found),
            message: 'A |raw output was added or removed: list it in RAW_OUTPUTS with what the fragment is built from, or drop the stale entry.',
        );
    }

    /**
     * The scanner's reading of the shapes the rule is about, on input written for it.
     */
    public function testTheScannerReadsEachContext(): void
    {
        $template = <<<'TWIG'
            <?php
            // it's a line comment {{ inLineComment }}
            /** it's a docblock {{ inDocblock }} */
            $a = '{{ quoted|php_string }}' . {{ bare }};
            $b = 'it\'s {{ afterEscapedQuote|php_string }}';
            $c = "{{ doubleQuoted }}";
            #[Attribute('{{ inAttribute|php_string }}')]
            {% if x %}'{{ inBranch|php_string }}'{% endif %}
            TWIG;

        $contexts = [];
        foreach ($this->scan(template: 'probe', source: $template) as $output) {
            $contexts[$output['expression']] = $output['context'];
        }

        $this->assertSame(
            expected: [
                'inLineComment' => self::CONTEXT_LINE_COMMENT,
                'inDocblock' => self::CONTEXT_BLOCK_COMMENT,
                'quoted|php_string' => self::CONTEXT_SINGLE_QUOTED,
                'bare' => self::CONTEXT_CODE,
                'afterEscapedQuote|php_string' => self::CONTEXT_SINGLE_QUOTED,
                'doubleQuoted' => self::CONTEXT_DOUBLE_QUOTED,
                'inAttribute|php_string' => self::CONTEXT_SINGLE_QUOTED,
                'inBranch|php_string' => self::CONTEXT_SINGLE_QUOTED,
            ],
            actual: $contexts,
        );
    }

    private function endsInPhpString(string $expression): bool
    {
        return preg_match('/\|\s*php_string\s*$/', $expression) === 1;
    }

    /**
     * @param array<string, true> $set
     * @return list<string>
     */
    private function sortedKeys(array $set): array
    {
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, string> template file name => source
     */
    private function templates(): array
    {
        $templates = [];
        foreach (glob(dirname(__DIR__, 2) . '/templates/*.twig') ?: [] as $file) {
            $templates[basename($file)] = (string)file_get_contents($file);
        }
        $this->assertNotSame(expected: [], actual: $templates);

        return $templates;
    }

    /**
     * @return list<array{template: string, where: string, context: string, expression: string}>
     */
    private function outputs(): array
    {
        $outputs = [];
        foreach ($this->templates() as $name => $source) {
            foreach ($this->scan(template: $name, source: $source) as $output) {
                $outputs[] = $output;
            }
        }

        return $outputs;
    }

    /**
     * @return list<array{template: string, where: string, context: string, expression: string}>
     */
    private function scan(string $template, string $source): array
    {
        $outputs = [];
        $context = self::CONTEXT_CODE;
        $line = 1;
        $length = strlen($source);
        $position = 0;

        while ($position < $length) {
            $pair = substr($source, $position, 2);
            $closer = match ($pair) {
                '{{' => '}}',
                '{%' => '%}',
                '{#' => '#}',
                default => null,
            };
            if ($closer !== null) {
                $end = strpos($source, $closer, $position + 2);
                if ($end === false) {
                    $this->fail(sprintf('%s:%d: unclosed %s', $template, $line, $pair));
                }
                $body = substr($source, $position + 2, $end - $position - 2);
                if ($pair === '{{') {
                    $outputs[] = [
                        'template' => $template,
                        'where' => $template . ':' . $line,
                        'context' => $context,
                        'expression' => trim(string: $body, characters: " \t\n-~"),
                    ];
                }
                $line += substr_count($body, "\n");
                $position = $end + 2;

                continue;
            }

            $char = $source[$position];
            if ($char === "\n") {
                $line++;
            }

            if ($context === self::CONTEXT_CODE) {
                if ($char === "'") {
                    $context = self::CONTEXT_SINGLE_QUOTED;
                } elseif ($char === '"') {
                    $context = self::CONTEXT_DOUBLE_QUOTED;
                } elseif ($pair === '/*') {
                    $context = self::CONTEXT_BLOCK_COMMENT;
                    $position++;
                } elseif ($pair === '//' || ($char === '#' && $pair !== '#[')) {
                    $context = self::CONTEXT_LINE_COMMENT;
                }
            } elseif ($context === self::CONTEXT_SINGLE_QUOTED || $context === self::CONTEXT_DOUBLE_QUOTED) {
                $quote = $context === self::CONTEXT_SINGLE_QUOTED ? "'" : '"';
                $next = $source[$position + 1] ?? '';
                if ($char === '\\' && ($next === '\\' || $next === $quote)) {
                    // Only `\\` and an escaped quote are escapes the string's END depends on; any other
                    // backslash is literal, and skipping its neighbour could swallow the `{` of an output.
                    $position++;
                } elseif ($char === $quote) {
                    $context = self::CONTEXT_CODE;
                }
            } elseif ($context === self::CONTEXT_BLOCK_COMMENT) {
                if ($pair === '*/') {
                    $context = self::CONTEXT_CODE;
                    $position++;
                }
            } elseif ($char === "\n") {
                $context = self::CONTEXT_CODE;
            }

            $position++;
        }

        return $outputs;
    }
}
