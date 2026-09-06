<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The support matrix says of itself: "If a row here stops being true, one of those fails."
 *
 * That sentence is what makes the table trustworthy, and it rests on a second one above the
 * vocabulary section — "each row a case in the all-mode comparison". Nothing checked either. A keyword
 * could be listed as enforced in all five modes with no case behind it at all, and the table would go
 * on saying so while the suite stayed green: the row is prose, and prose does not fail.
 *
 * So this walks the vocabulary table and asks, of every keyword it names, whether the parity suites
 * mention it anywhere. That is deliberately a WEAK check — presence, not enforcement — because the
 * strong one is already there: `ValidationParityTest` compares the verdict of all five modes for each
 * case it holds. What was missing was the link between the table and that suite, so a row added
 * without a case is caught here rather than believed.
 *
 * A keyword appears in a case either as an array KEY (`'minLength' => 5`) or, for the `format` family,
 * as a VALUE (`'format' => 'email'`). Both spellings count — looking for only the first reported every
 * format as missing when this check was first written by hand.
 */
final class SupportMatrixTest extends TestCase
{
    public function testEveryKeywordTheMatrixClaimsHasACaseInTheParitySuites(): void
    {
        $matrix = (string)file_get_contents(__DIR__ . '/../../README.support-matrix.md');
        $parity = '';
        foreach (glob(__DIR__ . '/../Parity/*.php') ?: [] as $file) {
            $parity .= (string)file_get_contents($file);
        }

        $missing = [];
        foreach ($this->vocabularyKeywords($matrix) as $keyword) {
            $asKey = substr_count($parity, "'" . $keyword . "' =>");
            $asValue = substr_count($parity, "=> '" . $keyword . "'");

            if ($asKey + $asValue === 0) {
                $missing[] = $keyword;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'the vocabulary table names these, and no parity case mentions them',
        );
    }

    /**
     * Keywords named in the first column of the "Validation vocabulary" table.
     *
     * `false` is excluded by name: it appears in one row's prose — "`unevaluatedItems` as a SCHEMA (not
     * just `false`)" — as an English word about a spelling, not as a keyword of its own.
     *
     * @return array<int, string>
     */
    private function vocabularyKeywords(string $matrix): array
    {
        $lines = explode("\n", $matrix);
        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '## Validation vocabulary')) {
                $start = $index;

                continue;
            }
            if ($start !== null && str_starts_with($line, '## ')) {
                $end = $index;

                break;
            }
        }

        $this->assertNotNull($start, 'the matrix still has a "Validation vocabulary" section');

        $keywords = [];
        for ($index = $start; $index < $end; $index++) {
            $line = $lines[$index];
            if (!str_starts_with($line, '|') || str_starts_with($line, '|---') || str_contains($line, '| runtime |')) {
                continue;
            }

            $cells = explode('|', $line);
            $first = $cells[1] ?? '';

            preg_match_all('/`([A-Za-z][\w-]*)`/', $first, $matches);
            foreach ($matches[1] as $keyword) {
                if ($keyword !== 'false') {
                    $keywords[] = $keyword;
                }
            }
        }

        $this->assertNotSame([], $keywords, 'the vocabulary table still names keywords');

        return array_values(array_unique($keywords));
    }
}
