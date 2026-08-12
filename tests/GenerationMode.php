<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

/**
 * The generation modes the suites compare, as data rather than as three hardcoded columns.
 *
 * Every suite used to name the modes one by one — `runtimeVerdict()`, `symfonyVerdict()`,
 * `laravelVerdict()` called side by side at every assertion — so a fourth mode meant editing every
 * call site, and a case that quietly listed only two modes looked exactly like a case that meant to.
 * Now a suite iterates `cases()` and states its divergences by mode, which has two consequences:
 *
 * - adding a case here enrolls the new mode in EVERY parity case at once;
 * - a `match` over modes that has no arm for it fails with `UnhandledMatchError` rather than
 *   silently skipping the column. That is deliberate — there is no `default` arm anywhere in the
 *   suites, so a new mode cannot be half-added.
 */
enum GenerationMode: string
{
    case Runtime = 'runtime';
    case Symfony = 'symfony';
    case Laravel = 'laravel';
    case LaravelData = 'laravel-data';

    /**
     * The mode the others are compared against.
     *
     * Runtime mode owns the schema walk this package implements, so it is the reading of the OpenAPI
     * document the other modes have to match — not merely the first column.
     */
    public static function reference(): self
    {
        return self::Runtime;
    }

    public function isReference(): bool
    {
        return $this === self::reference();
    }

    /**
     * Short tag for the generated-code namespace of a case, keeping one mode's output out of
     * another's autoload path (`ParityRt…` vs `ParityLv…`).
     */
    public function tag(): string
    {
        return match ($this) {
            self::Runtime => 'Rt',
            self::Symfony => 'Sy',
            self::Laravel => 'Lv',
            self::LaravelData => 'Ld',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function others(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $mode): bool => !$mode->isReference()));
    }
}
