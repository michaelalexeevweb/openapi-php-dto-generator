<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A seeded source of choices, independent of PHP's global `mt_rand()` state: the same seed draws the
 * same sequence whatever else ran in the process, so a finding is reproducible from its seed alone.
 */
final class FuzzRandom
{
    private readonly Randomizer $randomizer;

    public function __construct(
        public readonly int $seed,
    ) {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * True with the given probability (0..1).
     */
    public function chance(float $probability): bool
    {
        return $this->randomizer->getInt(0, 999) < (int)round($probability * 1000);
    }

    /**
     * @template T
     * @param array<int|string, T> $choices
     * @return T
     */
    public function pick(array $choices): mixed
    {
        $values = array_values($choices);

        return $values[$this->randomizer->getInt(0, count($values) - 1)];
    }

    /**
     * @template T
     * @param array<int, T> $items
     * @return array<int, T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray(array_values($items));
    }

    /**
     * Distinct entries, in random order.
     *
     * @template T
     * @param array<int, T> $items
     * @return array<int, T>
     */
    public function sample(array $items, int $count): array
    {
        return array_slice($this->shuffle($items), 0, max(0, min($count, count($items))));
    }

    public function letters(int $length, string $alphabet = 'abcdefghij'): string
    {
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= $alphabet[$this->randomizer->getInt(0, strlen($alphabet) - 1)];
        }

        return $text;
    }
}
