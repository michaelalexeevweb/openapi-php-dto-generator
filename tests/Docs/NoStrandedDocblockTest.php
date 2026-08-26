<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * A docblock belongs to the declaration under it, and to exactly one.
 *
 * Two docblocks in a row means a declaration was inserted in front of another and took over its
 * position: the newcomer then carries two, and the method the first one describes carries none. Six of
 * them were live at once, and one turned out to describe a constant the file no longer had — a comment
 * that could not be wrong about anything because it was about nothing.
 *
 * It reads as harmless and it is not: the owner loses its `@param`/`@return`, which is how a
 * `missingType.iterableValue` reached PHPStan from `DtoValidator`, and a reader attributes the text to
 * the wrong declaration. The rule is mechanical, so it is checked mechanically.
 */
final class NoStrandedDocblockTest extends TestCase
{
    public function testNoDocblockIsFollowedImmediatelyByAnother(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            ...(glob($root . '/src/*.php') ?: []),
            ...(glob($root . '/src/*/*.php') ?: []),
            ...(glob($root . '/src/*/*/*.php') ?: []),
            ...(glob($root . '/tests/*.php') ?: []),
            ...(glob($root . '/tests/*/*.php') ?: []),
        ];
        self::assertNotSame([], $files, 'no PHP files found — the globs are wrong, not the sources');

        $stranded = [];
        foreach ($files as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                if (trim($line) !== '*/') {
                    continue;
                }
                if (trim($lines[$index + 1] ?? '') !== '/**') {
                    continue;
                }

                // Report the OPENING line of the stranded block: that is what has to move.
                $opening = $index;
                while ($opening > 0 && trim($lines[$opening]) !== '/**') {
                    $opening--;
                }

                $stranded[] = sprintf(
                    '%s:%d — this docblock is followed by another, so the declaration it describes has none',
                    basename(dirname($path)) . '/' . basename($path),
                    $opening + 1,
                );
            }
        }

        self::assertSame([], $stranded, "Stranded docblocks:\n" . implode("\n", $stranded));
    }
}
