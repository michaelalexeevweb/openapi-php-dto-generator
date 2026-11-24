<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

/**
 * Component names for one spec: a colour, then lowercase letters once the colours run out — one leading
 * capital and no inner capitals, so the generator's class-name normalisation leaves them alone.
 */
final class NameAllocator
{
    private const array COLOURS = [
        'Crimson', 'Azure', 'Amber', 'Indigo', 'Ochre', 'Teal', 'Scarlet', 'Olive', 'Coral', 'Umber',
        'Saffron', 'Cobalt', 'Mauve', 'Sienna', 'Jade', 'Ivory',
    ];

    private int $counter = 0;

    public function next(): string
    {
        $index = $this->counter++;
        $colour = self::COLOURS[$index % count(self::COLOURS)];
        $round = intdiv($index, count(self::COLOURS));
        $suffix = '';
        while ($round > 0) {
            $suffix = chr(ord('a') + ($round - 1) % 26) . $suffix;
            $round = intdiv($round - 1, 26);
        }

        return $colour . $suffix;
    }
}
