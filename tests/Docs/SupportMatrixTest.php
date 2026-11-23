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
            expected: [],
            actual: $missing,
            message: 'the vocabulary table names these, and no parity case mentions them',
        );
    }

    /**
     * The "Divergences" section opens by counting its own table — "Twenty-six, … Twenty-one are
     * deliberate; the five rows …" — and that sentence is prose: rows were added under it for several
     * releases while it went on saying twenty-three, nineteen and four. So the number is read back
     * here and held against the table.
     *
     * A row is a divergence only when the five mode cells are not all the same. One row once said
     * "read in the server's timezone" in every column; it describes something all modes share, so it
     * lives beside the vocabulary table instead, and this test keeps any such row out.
     */
    public function testTheDivergenceCountMatchesTheTable(): void
    {
        $matrix = (string)file_get_contents(__DIR__ . '/../../README.support-matrix.md');
        $section = $this->section($matrix, '## Divergences');

        $this->assertSame(
            expected: 1,
            actual: preg_match(
                '/^(?<total>[A-Z][a-z-]+), all pinned by a test that names the cause\. (?<deliberate>[A-Z][a-z-]+) are deliberate; the (?<limits>[a-z-]+) rows/m',
                $section,
                $counts,
            ),
            message: 'the Divergences section still opens with its count',
        );

        $rows = [];
        $tableStarted = false;
        foreach (explode("\n", $section) as $line) {
            if (str_starts_with($line, '| Behaviour |')) {
                $tableStarted = true;

                continue;
            }
            if (!$tableStarted || str_starts_with($line, '|---')) {
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            $rows[] = $line;
        }

        $alike = [];
        foreach ($rows as $row) {
            // `\|` inside a cell is an escaped pipe, not a column border.
            $cells = array_map('trim', explode('|', str_replace('\|', "\0", $row)));
            $modes = array_slice($cells, 2, 5);
            if (count(array_unique($modes)) === 1) {
                $alike[] = $cells[1];
            }
        }

        $this->assertSame(expected: [], actual: $alike, message: 'these rows read the same in every mode');
        $this->assertSame(expected: count($rows), actual: $this->numberWord($counts['total']), message: 'the opening count');
        $this->assertSame(
            expected: count($rows),
            actual: $this->numberWord($counts['deliberate']) + $this->numberWord($counts['limits']),
            message: 'deliberate rows plus framework limits',
        );
    }

    private function section(string $markdown, string $heading): string
    {
        $start = strpos($markdown, "\n" . $heading . "\n");
        $this->assertNotFalse($start, sprintf('the matrix still has a "%s" section', $heading));

        $end = strpos($markdown, "\n## ", $start + 1);

        return $end === false ? substr($markdown, $start) : substr($markdown, $start, $end - $start);
    }

    private function numberWord(string $word): int
    {
        $units = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
            'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
            'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19,
        ];
        $tens = ['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50];

        $parts = explode('-', strtolower($word));
        $value = $tens[$parts[0]] ?? $units[$parts[0]] ?? null;
        $this->assertNotNull($value, sprintf('"%s" is a number word', $word));
        if (count($parts) === 2) {
            $unit = $units[$parts[1]] ?? null;
            $this->assertNotNull($unit, sprintf('"%s" is a number word', $word));
            $value += $unit;
        }

        return $value;
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
