<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function preg_match;
use function sprintf;

/**
 * The code this generator WRITES obeys the same spacing rule as the code it is written in.
 *
 * `composer cs` reads `src/` and `tests/`; nothing reads the emitted files, so a template that forgets
 * a blank line ships a style bug to every consumer and never fails a build here. One did: a
 * discriminator base with no properties of its own came out as
 *
 *     public function __construct()
 *     {
 *     }
 *     public static function getDiscriminatorPropertyName(): string
 *
 * because the separating line was emitted only when the class had properties. It had been in the golden
 * snapshots since the feature landed, read by nobody.
 *
 * The golden snapshots are the emitted code, byte for byte, so the rule is checked there — no
 * generation, no fixtures, and every mode at once.
 */
final class EmittedCodeSpacingTest extends TestCase
{
    public function testNoMemberFollowsAClosingBraceWithoutABlankLine(): void
    {
        $offences = [];

        foreach (['runtime', 'symfony', 'laravel', 'laravel-data', 'yii3'] as $mode) {
            $snapshot = dirname(__DIR__) . '/Golden/snapshots/' . $mode . '.snapshot.txt';
            $lines = explode("\n", (string)file_get_contents($snapshot));

            foreach ($lines as $index => $line) {
                if ($index === 0 || $lines[$index - 1] !== '    }') {
                    continue;
                }

                // A member declaration, an attribute or a docblock opening right after a method's
                // closing brace. A nested `}` or the class's own `}` is not one.
                if (preg_match('/^    (?:\/\*\*|#\[|(?:final |abstract )?(?:public|protected|private)\s)/', $line) !== 1) {
                    continue;
                }

                $offences[] = sprintf('%s.snapshot.txt:%d — %s', $mode, $index + 1, trim($line));
            }
        }

        $this->assertSame([], $offences, "emitted code needs a blank line between members:\n " . implode("\n ", $offences));
    }

    /**
     * The other two spacings PSR-12 objects to, and both shipped for months.
     *
     * A blank line right before a closing brace INSIDE a method is `Blank line found at end of control
     * structure` — it came from joining the interpreter's sections with a blank line while one section
     * was nothing but the brace that closed the block above it. And a `use function` group running
     * straight into the class docblock is `Header blocks must be separated by a single blank line` — the
     * group's insertion consumed the template's blank line and never put one back.
     *
     * Checked here rather than by running PSR-12 over the corpus, so the suite keeps no dependency on a
     * sniff configuration; the two rules that were actually broken are spelled out instead.
     */
    public function testNoStrayBlankLineBeforeAClosingBraceAndNoGluedImportGroup(): void
    {
        $offences = [];

        foreach (['runtime', 'symfony', 'laravel', 'laravel-data', 'yii3'] as $mode) {
            $snapshot = dirname(__DIR__) . '/Golden/snapshots/' . $mode . '.snapshot.txt';
            $lines = explode("\n", (string)file_get_contents($snapshot));

            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    continue;
                }

                $previous = $lines[$index - 1];

                // A blank line then a brace closing something nested inside a method body.
                if ($previous === '' && preg_match('/^ {8,}\}/', $line) === 1) {
                    $offences[] = sprintf('%s.snapshot.txt:%d — blank line before %s', $mode, $index + 1, trim($line));
                }

                // The `use function` group must not touch what follows it.
                if (str_starts_with($previous, 'use function ') && $line !== '' && !str_starts_with($line, 'use function ')) {
                    $offences[] = sprintf('%s.snapshot.txt:%d — import group runs into %s', $mode, $index + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offences, "emitted code spacing:\n " . implode("\n ", $offences));
    }
}
