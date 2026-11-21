<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * One draft bag. Bags are passed around the table between rounds, so a bag keeps its own identity
 * while changing hands; which player holds which bag is tracked by the draft, not by the bag.
 *
 * Immutable, like App\Draft\Player: every change returns a new Bag.
 */
class Bag
{
    public function __construct(
        public readonly string $id,
        /** @var array<int, ComponentId> */
        private readonly array $items = [],
    ) {
    }

    /** @return array<int, ComponentId> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function contains(ComponentId $id): bool
    {
        foreach ($this->items as $item) {
            if ($item->equals($id)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, ComponentId> */
    public function itemsIn(DraftCategory $category): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ComponentId $item) => $item->category === $category,
        ));
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

        return new self($this->id, $remaining);
    }

    public function add(ComponentId $id): self
    {
        return new self($this->id, [...$this->items, $id]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'items' => array_map(static fn (ComponentId $item) => $item->value(), $this->items),
        ];
    }

    public static function fromJson(array $data): self
    {
        $items = [];

        foreach ((array) ($data['items'] ?? []) as $value) {
            $id = ComponentId::tryFromString((string) $value);

            if ($id !== null) {
                $items[] = $id;
            }
        }

        return new self((string) $data['id'], $items);
    }
}
