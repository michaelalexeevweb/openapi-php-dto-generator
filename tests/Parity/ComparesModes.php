<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use OpenapiPhpDtoGenerator\Tests\GenerationMode;

/**
 * One comparison shape for every parity suite: run the same observation in every `GenerationMode` and
 * expect the same answer, except where the case states a mode-specific answer AND why.
 *
 * The `$diverges` map is what makes the mode list additive. A case lists the modes that cannot give
 * the common answer, so a mode absent from the map is asserted against the common one — including a
 * mode added later, which is the whole point. Declaring a divergence whose expectation matches the
 * common answer fails too: a stale reason is worse than none, because it reads as a known boundary.
 */
trait ComparesModes
{
    /**
     * @param mixed $expected the answer every mode must give unless it appears in $diverges
     * @param callable(GenerationMode): mixed $observe
     * @param array<string, array{expected: mixed, reason: string}> $diverges keyed by mode value
     */
    protected function assertEveryModeYields(
        mixed $expected,
        callable $observe,
        array $diverges = [],
        string $context = '',
    ): void {
        $where = $context === '' ? '' : ' on "' . $context . '"';

        foreach (GenerationMode::cases() as $mode) {
            $divergence = $diverges[$mode->value] ?? null;
            if ($divergence === null) {
                $this->assertSame(
                    $expected,
                    $observe($mode),
                    sprintf('%s mode must agree with the other modes%s', $mode->value, $where),
                );

                continue;
            }

            $this->assertNotSame(
                $expected,
                $divergence['expected'],
                sprintf(
                    '%s mode declares a divergence%s but expects the common answer — drop the reason: %s',
                    $mode->value,
                    $where,
                    $divergence['reason'],
                ),
            );
            $this->assertSame(
                $divergence['expected'],
                $observe($mode),
                sprintf(
                    "%s mode diverges%s, and the documented divergence no longer holds.\n reason: %s",
                    $mode->value,
                    $where,
                    $divergence['reason'],
                ),
            );
        }
    }

    /**
     * A divergence entry for `$diverges`, so a case reads as prose rather than as an array literal.
     */
    protected static function diverges(GenerationMode $mode, mixed $expected, string $reason): array
    {
        return [$mode->value => ['expected' => $expected, 'reason' => $reason]];
    }
}
