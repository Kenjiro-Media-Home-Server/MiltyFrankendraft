<?php

declare(strict_types=1);

namespace App\Franken;

enum DraftOrderMode: string
{
    /** Seating order is one of the drafted categories: every bag holds one position. */
    case DRAFTED = 'drafted';

    /** Seating order is fixed when the draft is generated; the category is not drafted. */
    case PRESET = 'preset';

    public function label(): string
    {
        return match ($this) {
            self::DRAFTED => 'Drafted (each bag holds one seating position)',
            self::PRESET => 'Preset (seating order fixed at generation)',
        };
    }
}
