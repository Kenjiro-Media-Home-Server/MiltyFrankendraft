<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\Component;
use App\Franken\ComponentId;
use App\Franken\ComponentRepository;
use App\Franken\DraftCategory;
use App\Franken\Errata;
use App\Franken\Exceptions\InsufficientComponentsException;
use App\Franken\FrankenSettings;
use App\Shared\Command;

/**
 * Turns "these content sources" into one shuffled pool of component ids per category, with the
 * Franken errata applied.
 *
 * Pools are drawn from without replacement when the bags are filled, so no component can ever
 * appear in two bags.
 */
class BuildComponentPools implements Command
{
    public function __construct(
        private readonly FrankenSettings $settings,
        private readonly ?Errata $errata = null,
    ) {
    }

    /**
     * @return array<string, array<int, ComponentId>> category value => shuffled pool
     *
     * @throws InsufficientComponentsException
     */
    public function handle(): array
    {
        $errata = $this->errata ?? Errata::load();
        $pools = [];
        $shortfalls = [];
        $playerCount = $this->settings->playerCount();

        foreach ($this->settings->categories() as $index => $category) {
            $pool = $category === DraftCategory::DRAFTORDER
                ? $this->draftOrderPool($playerCount)
                : $this->poolFor($category, $errata);

            $this->settings->seed->setForFrankenPool($index);
            shuffle($pool);

            $needed = $this->settings->bagCount($category) * $playerCount;

            if (count($pool) < $needed) {
                $shortfalls[$category->value] = [
                    'needed' => $needed,
                    'available' => count($pool),
                ];
            }

            $pools[$category->value] = $pool;
        }

        if ($shortfalls !== []) {
            throw InsufficientComponentsException::forCategories($shortfalls, $playerCount);
        }

        return $pools;
    }

    /**
     * Categories that the enabled sources leave empty are simply not drafted — breakthroughs, for
     * instance, do not exist without Thunder's Edge era content.
     *
     * @return array<int, string>
     */
    public function activeCategoryValues(): array
    {
        $errata = $this->errata ?? Errata::load();
        $active = [];

        foreach ($this->settings->categories() as $category) {
            if ($category === DraftCategory::DRAFTORDER) {
                $active[] = $category->value;

                continue;
            }

            if ($this->poolFor($category, $errata) !== []) {
                $active[] = $category->value;
            }
        }

        return $active;
    }

    /** @return array<int, ComponentId> */
    private function poolFor(DraftCategory $category, Errata $errata): array
    {
        $candidates = [];

        foreach (ComponentRepository::byCategory($category) as $component) {
            if ($this->isAvailable($component, $errata)) {
                $candidates[$component->id->value()] = $component;
            }
        }

        // A homebrew set that reprints a base component replaces it rather than adding a duplicate.
        foreach ($candidates as $component) {
            if ($component->replaces === null) {
                continue;
            }

            unset($candidates[$category->value . ':' . $component->replaces]);
        }

        return array_values(array_map(
            static fn (Component $component) => $component->id,
            $candidates,
        ));
    }

    private function isAvailable(Component $component, Errata $errata): bool
    {
        // Franken-only replacement cards belong in the pool whatever the source selection says:
        // they exist precisely because the printed version they stand in for is undraftable.
        if ($errata->isAlwaysAddedToPool($component->id)) {
            return true;
        }

        if ($errata->isUndraftable($component->id)) {
            return false;
        }

        return in_array($component->source, $this->settings->sources, true);
    }

    /**
     * One seating position per player, so the positions come out exactly even across the bags.
     *
     * @return array<int, ComponentId>
     */
    private function draftOrderPool(int $playerCount): array
    {
        $positions = [];

        for ($position = 1; $position <= $playerCount; $position++) {
            $positions[] = ComponentId::make(DraftCategory::DRAFTORDER, (string) $position);
        }

        return $positions;
    }
}
