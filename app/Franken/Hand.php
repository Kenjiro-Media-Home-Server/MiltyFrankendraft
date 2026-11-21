<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * What a player has drafted so far. Immutable; every change returns a new Hand.
 *
 * The Hand knows what it holds but not what it is allowed to hold — the per-category limits belong
 * to the draft's settings, so that a draft keeps playing by the rules it was created with.
 */
class Hand
{
    public function __construct(
        /** @var array<int, ComponentId> */
        private readonly array $items = [],
    ) {
    }

    /** @return array<int, ComponentId> */
    public function items(): array
    {
        return $this->items;
    }

    public function size(): int
    {
        return count($this->items);
    }

    public function has(ComponentId $id): bool
    {
        foreach ($this->items as $item) {
            if ($item->equals($id)) {
                return true;
            }
        }

        return false;
    }

    public function countIn(DraftCategory $category): int
    {
        return count($this->itemsIn($category));
    }

    /** @return array<int, ComponentId> */
    public function itemsIn(DraftCategory $category): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ComponentId $item) => $item->category === $category,
        ));
    }

    /**
     * Everything held, grouped by category and in the mode's display order.
     *
     * @return array<string, array<int, ComponentId>>
     */
    public function groupedByCategory(): array
    {
        $grouped = [];

        foreach (DraftCategory::displayOrder() as $category) {
            $items = $this->itemsIn($category);

            if ($items !== []) {
                $grouped[$category->value] = $items;
            }
        }

        return $grouped;
    }

    public function add(ComponentId $id): self
    {
        return new self([...$this->items, $id]);
    }

    public function remove(ComponentId $id): self
    {
        $remaining = [];
        $removed = false;

        foreach ($this->items as $item) {
            if (! $removed && $item->equals($id)) {
                $removed = true;

                continue;
            }

            $remaining[] = $item;
        }

        return new self($remaining);
    }

    /** @return array<int, string> */
    public function toArray(): array
    {
        return array_map(static fn (ComponentId $item) => $item->value(), $this->items);
    }

    public static function fromJson(array $data): self
    {
        $items = [];

        foreach ($data as $value) {
            $id = ComponentId::tryFromString((string) $value);

            if ($id !== null) {
                $items[] = $id;
            }
        }

        return new self($items);
    }
}
