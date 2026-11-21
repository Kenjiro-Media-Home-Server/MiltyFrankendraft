<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * A player's choices after the bag draft: which optional swaps they took, which surplus components
 * they dropped, and how they laid their drafted tiles out into a slice.
 *
 * Granted components are deliberately NOT stored. They follow from what the player holds plus the
 * errata, so deriving them keeps a single source of truth; see grantedComponents().
 */
class Assembly
{
    /** A slice is five tiles, in the same layout the Milty generator uses. */
    public const SLICE_SIZE = 5;

    public function __construct(
        public readonly bool $confirmed = false,
        /** @var array<string, string> component id => the id taken in its place */
        public readonly array $swaps = [],
        /** @var array<int, string> component ids trimmed away when a category was over its final limit */
        public readonly array $dropped = [],
        /** @var array<int, string> five tile ids, in slice positions 0..4 */
        public readonly array $slice = [],
    ) {
    }

    public function hasSlice(): bool
    {
        return count($this->slice) === self::SLICE_SIZE;
    }

    public function swapFor(ComponentId $id): ?ComponentId
    {
        $chosen = $this->swaps[$id->value()] ?? null;

        return $chosen === null ? null : ComponentId::tryFromString((string) $chosen);
    }

    public function hasDropped(ComponentId $id): bool
    {
        return in_array($id->value(), $this->dropped, true);
    }

    public function withSwaps(array $swaps): self
    {
        return new self($this->confirmed, $swaps, $this->dropped, $this->slice);
    }

    public function withDropped(array $dropped): self
    {
        return new self($this->confirmed, $this->swaps, $dropped, $this->slice);
    }

    public function withSlice(array $slice): self
    {
        return new self($this->confirmed, $this->swaps, $this->dropped, array_values($slice));
    }

    public function confirm(): self
    {
        return new self(true, $this->swaps, $this->dropped, $this->slice);
    }

    public function reopen(): self
    {
        return new self(false, $this->swaps, $this->dropped, $this->slice);
    }

    public function toArray(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'swaps' => (object) $this->swaps,
            'dropped' => array_values($this->dropped),
            'slice' => array_values($this->slice),
        ];
    }

    public static function fromJson(array $data): self
    {
        return new self(
            (bool) ($data['confirmed'] ?? false),
            (array) ($data['swaps'] ?? []),
            array_values((array) ($data['dropped'] ?? [])),
            array_values((array) ($data['slice'] ?? [])),
        );
    }
}
