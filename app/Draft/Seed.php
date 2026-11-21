<?php

declare(strict_types=1);

namespace App\Draft;

class Seed
{
    // Maximum value for random seed generation (2^50)
    // Limited by JavaScript's Number.MAX_SAFE_INTEGER (2^53 - 1) for JSON compatibility
    public const MAX_VALUE = 1125899906842624;
    public const MIN_VALUE = 1;

    private const OFFSET_SLICES = 0;
    private const OFFSET_FACTIONS = 1;
    private const OFFSET_PLAYER_ORDER = 2;
    private const OFFSET_FRANKEN_POOLS = 3;
    private const OFFSET_FRANKEN_SLICE_DRAFT = 4;
    private int $seed;

    public function __construct(?int $seed = null)
    {
        if ($seed == null) {
            $this->seed = self::generate();
        } else {
            $this->seed = $seed;
        }
    }

    protected static function generate(): int
    {
        return mt_rand(1, self::MAX_VALUE);
    }

    public function getValue(): int
    {
        return $this->seed;
    }

    public function setForFactions(): void
    {
        mt_srand($this->seed + self::OFFSET_FACTIONS);
    }

    public function setForSlices($previousTries = 0): void
    {
        mt_srand($this->seed + self::OFFSET_SLICES + $previousTries);
    }

    public function setForPlayerOrder(): void
    {
        mt_srand($this->seed + self::OFFSET_PLAYER_ORDER);
    }

    /**
     * Franken shuffles one pool per category, so each gets its own offset to keep the pools
     * independent of one another while staying reproducible from the seed.
     */
    public function setForFrankenPool(int $categoryIndex = 0): void
    {
        mt_srand($this->seed + self::OFFSET_FRANKEN_POOLS + $categoryIndex);
    }

    /** Each player's slice-tile draw order (SliceDraftOrder) is independent of everyone else's. */
    public function setForFrankenSliceDraft(string $playerId): void
    {
        mt_srand($this->seed + self::OFFSET_FRANKEN_SLICE_DRAFT + crc32($playerId));
    }

    public function isValid()
    {
        return $this->seed >= self::MIN_VALUE && $this->seed <= self::MAX_VALUE;
    }
}