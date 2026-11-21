<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\Bag;
use App\Franken\ComponentId;
use App\Franken\DraftCategory;
use App\Franken\FrankenSettings;
use App\Shared\Command;

/**
 * Deals the shuffled pools into one bag per player.
 *
 * Items are taken from the front of each pool without replacement, so every component in the draft
 * exists exactly once across all bags.
 */
class BuildBags implements Command
{
    public function __construct(
        private readonly FrankenSettings $settings,
        /** @var array<string, array<int, ComponentId>> */
        private array $pools,
    ) {
    }

    /** @return array<string, Bag> keyed by bag id */
    public function handle(): array
    {
        $bags = [];

        for ($index = 0; $index < $this->settings->playerCount(); $index++) {
            $items = [];

            foreach ($this->settings->categories() as $category) {
                for ($taken = 0; $taken < $this->settings->bagCount($category); $taken++) {
                    $item = $this->take($category);

                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }

            $bag = new Bag('bag_' . ($index + 1), $items);
            $bags[$bag->id] = $bag;
        }

        return $bags;
    }

    private function take(DraftCategory $category): ?ComponentId
    {
        if (($this->pools[$category->value] ?? []) === []) {
            return null;
        }

        return array_shift($this->pools[$category->value]);
    }
}
