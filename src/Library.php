<?php

declare(strict_types=1);

/**
 * Application logic shared by the endpoints: how the library is split into the
 * five "adventure" sections and how a random selection is drawn from each.
 */
final class Library
{
    /**
     * Section name => fraction of the ranked library it covers.
     * Most-played artists live at the top of the library.
     *
     * @var array<string,float>
     */
    private const SECTIONS = [
        'classics'    => 0.05,
        'known'       => 0.15,
        'adventurous' => 0.25,
        'wild'        => 0.25,
        'insane'      => 0.30,
    ];

    /**
     * Translate each section into an inclusive [start, end] 1-based rank window
     * within a library of $totalArtists artists.
     *
     * @return array<string,array{0:int,1:int}>
     */
    public static function sections(int $totalArtists): array
    {
        $totalArtists = max(1, $totalArtists);

        $sections = [];
        $start = 1;

        foreach (self::SECTIONS as $name => $fraction) {
            $size = max(1, (int) floor($totalArtists * $fraction));
            $end = min($totalArtists, $start + $size - 1);

            // Subsequent sections continue after this one, clamped to the end.
            $sections[$name] = [$start, $end];
            $start = $end + 1;
        }

        return $sections;
    }

    /**
     * Pick $quantity distinct random ranks inside an inclusive [start, end] range.
     *
     * @return int[]
     */
    public static function randomRanks(int $start, int $end, int $quantity): array
    {
        $start = max(1, $start);
        $end = max($start, $end);
        $quantity = max(0, min($quantity, $end - $start + 1));

        $pool = range($start, $end);
        shuffle($pool);

        return array_slice($pool, 0, $quantity);
    }

    /**
     * The five section names, in library order.
     *
     * @return string[]
     */
    public static function sectionNames(): array
    {
        return array_keys(self::SECTIONS);
    }
}
