<?php

declare(strict_types=1);

namespace App\Franken;

/** How a player turns their five drafted slice tiles into an arranged slice. */
enum SliceAssemblyMode: string
{
    /**
     * The standard variant: tiles are drawn one ring at a time from a shuffled bag (see
     * SliceDraftOrder) — the ring-1 slot is forced, the two ring-2 slots and the two ring-3 slots
     * are each freely assignable between that ring's pair.
     */
    case DRAFT = 'draft';

    /** The classic click-and-place builder: any of the five tiles may go in any of the five slots. */
    case FREE = 'free';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Tile draft (draw one ring at a time)',
            self::FREE => 'Free build (place any tile anywhere)',
        };
    }
}
