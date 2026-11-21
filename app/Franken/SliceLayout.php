<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * The physical shape of a Franken slice: five tile positions (the same 0-4 indices Assembly::slice
 * and js/generate-map.js's hex coordinates already use — nothing about the stored format or the
 * map's coordinate tables changes here) grouped into three rings by distance from Mecatol Rex.
 *
 * Ring 1 (closest to the centre) is a single tile; rings 2 and 3 hold two tiles each. This grouping
 * is what the "tile draft" slice-assembly variant fills a whole ring at a time (see
 * SliceDraftOrder), and what the friendlier 1-5 numbering shown to players (instead of the raw
 * position index) is built from.
 */
final class SliceLayout
{
    /** @var array<int, int> position index => ring (1, 2 or 3) */
    private const RING_BY_POSITION = [4 => 1, 1 => 2, 3 => 2, 0 => 3, 2 => 3];

    /**
     * The friendlier number shown to players instead of the raw position index: 1 for the ring-1
     * slot, 2/3 for the ring-2 slots, 4/5 for the ring-3 slots.
     *
     * @var array<int, int> position index => display number
     */
    private const DISPLAY_NUMBER_BY_POSITION = [4 => 1, 1 => 2, 3 => 3, 0 => 4, 2 => 5];

    public static function ringOf(int $position): int
    {
        return self::RING_BY_POSITION[$position]
            ?? throw new \InvalidArgumentException('Not a slice position: ' . $position);
    }

    public static function displayNumber(int $position): int
    {
        return self::DISPLAY_NUMBER_BY_POSITION[$position]
            ?? throw new \InvalidArgumentException('Not a slice position: ' . $position);
    }

    /** @return array<int, int> the position indices belonging to this ring */
    public static function positionsInRing(int $ring): array
    {
        return array_keys(array_filter(
            self::RING_BY_POSITION,
            static fn (int $candidate) => $candidate === $ring,
        ));
    }
}
