<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\Exceptions\InvalidClaimException;
use App\Draft\PlayerId;

/**
 * A seat at a Franken draft. Immutable, mirroring App\Draft\Player: every change returns a new
 * instance and the draft swaps it in.
 */
class FrankenPlayer
{
    public function __construct(
        public readonly PlayerId $id,
        public readonly string $name,
        public readonly int $seat,
        public readonly bool $claimed = false,
        public readonly Hand $hand = new Hand(),
        public readonly int $picksThisRound = 0,
        public readonly bool $ready = false,
        public readonly Assembly $assembly = new Assembly(),
    ) {
    }

    public static function create(string $name, int $seat): self
    {
        return new self(PlayerId::generate(), $name, $seat);
    }

    public function claim(): self
    {
        if ($this->claimed) {
            throw InvalidClaimException::playerAlreadyClaimed();
        }

        return $this->with(claimed: true);
    }

    public function unclaim(): self
    {
        if (! $this->claimed) {
            throw InvalidClaimException::playerNotClaimed();
        }

        return $this->with(claimed: false);
    }

    /** Takes an item into the hand and counts it against this round's quota. */
    public function pick(ComponentId $id): self
    {
        return $this->with(
            hand: $this->hand->add($id),
            picksThisRound: $this->picksThisRound + 1,
        );
    }

    /** Puts an item back; only legal while the player's round is still open. */
    public function unpick(ComponentId $id): self
    {
        return $this->with(
            hand: $this->hand->remove($id),
            picksThisRound: max(0, $this->picksThisRound - 1),
        );
    }

    public function markReady(bool $ready = true): self
    {
        return $this->with(ready: $ready);
    }

    /** Clears the per-round bookkeeping when the bags move on. */
    public function startNewRound(): self
    {
        return $this->with(picksThisRound: 0, ready: false);
    }

    public function withAssembly(Assembly $assembly): self
    {
        return $this->with(assembly: $assembly);
    }

    public function withHand(Hand $hand): self
    {
        return $this->with(hand: $hand);
    }

    /** Only used when an admin re-deals a draft that has not started yet. */
    public function withSeat(int $seat): self
    {
        return new self(
            $this->id,
            $this->name,
            $seat,
            $this->claimed,
            $this->hand,
            $this->picksThisRound,
            $this->ready,
            $this->assembly,
        );
    }

    /** The seating position this player drafted, if any. */
    public function draftedPosition(): ?int
    {
        $positions = $this->hand->itemsIn(DraftCategory::DRAFTORDER);

        return $positions === [] ? null : (int) $positions[0]->itemId;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'seat' => $this->seat,
            'claimed' => $this->claimed,
            'hand' => $this->hand->toArray(),
            'picks_this_round' => $this->picksThisRound,
            'ready' => $this->ready,
            'assembly' => $this->assembly->toArray(),
        ];
    }

    public static function fromJson(array $data): self
    {
        return new self(
            PlayerId::fromString((string) $data['id']),
            (string) $data['name'],
            (int) ($data['seat'] ?? 0),
            (bool) ($data['claimed'] ?? false),
            Hand::fromJson((array) ($data['hand'] ?? [])),
            (int) ($data['picks_this_round'] ?? 0),
            (bool) ($data['ready'] ?? false),
            Assembly::fromJson((array) ($data['assembly'] ?? [])),
        );
    }

    private function with(
        ?bool $claimed = null,
        ?Hand $hand = null,
        ?int $picksThisRound = null,
        ?bool $ready = null,
        ?Assembly $assembly = null,
    ): self {
        return new self(
            $this->id,
            $this->name,
            $this->seat,
            $claimed ?? $this->claimed,
            $hand ?? $this->hand,
            $picksThisRound ?? $this->picksThisRound,
            $ready ?? $this->ready,
            $assembly ?? $this->assembly,
        );
    }
}
