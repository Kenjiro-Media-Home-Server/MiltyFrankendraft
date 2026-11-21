<?php

declare(strict_types=1);

namespace App\Franken;

enum DraftStage: string
{
    /** Players are picking from bags that pass around the table. */
    case BAG_DRAFT = 'bag_draft';

    /** Every hand is full; players are resolving swaps and laying out their slices. */
    case ASSEMBLY = 'assembly';

    /** Every player has confirmed their faction. */
    case DONE = 'done';

    public function label(): string
    {
        return match ($this) {
            self::BAG_DRAFT => 'Bag draft',
            self::ASSEMBLY => 'Building factions',
            self::DONE => 'Done',
        };
    }
}
