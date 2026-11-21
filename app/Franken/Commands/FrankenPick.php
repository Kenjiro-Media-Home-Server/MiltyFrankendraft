<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\ComponentId;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Takes one item out of the bag in front of a player and into their hand.
 *
 * Unlike the Milty draft there is no "whose turn is it": every player picks from their own bag at
 * the same time. What makes a pick legal is therefore about the player and their bag, not about
 * a turn order — see docs/frankendraft-spec.md §8.1.
 *
 * Does not save. Callers run this inside DraftRepository::mutate() so that simultaneous picks
 * cannot overwrite one another.
 */
class FrankenPick implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
        private readonly ComponentId $item,
        /** Guards against a pick submitted from a page that was open when the bags moved on. */
        private readonly ?int $expectedRound = null,
    ) {
    }

    public function handle(): FrankenDraft
    {
        if ($this->draft->stage !== DraftStage::BAG_DRAFT) {
            throw InvalidFrankenPickException::notInBagDraft();
        }

        if ($this->expectedRound !== null && $this->expectedRound !== $this->draft->round) {
            throw InvalidFrankenPickException::roundHasMovedOn($this->draft->round);
        }

        $player = $this->draft->playerById($this->playerId);
        $bag = $this->draft->bagFor($this->playerId);

        if ($bag === null || ! $bag->contains($this->item)) {
            throw InvalidFrankenPickException::itemNotInBag($this->item->value());
        }

        if ($this->draft->handIsFull($player)) {
            throw InvalidFrankenPickException::handIsFull();
        }

        if (! $this->draft->canTake($player, $this->item)) {
            throw InvalidFrankenPickException::categoryFull(
                $this->item->category,
                $this->draft->settings->handLimit($this->item->category),
            );
        }

        if ($this->draft->remainingPicksFor($player) < 1) {
            throw InvalidFrankenPickException::quotaSpent();
        }

        $this->draft->replaceBag($bag->remove($this->item));
        $player = $player->pick($this->item);

        $this->draft->updatePlayer($player);

        // Running the bag dry is the one thing that readies a player for them; spending their last
        // pick does not, because they may still want to read what is left before passing it on.
        if ($this->draft->isAutomaticallyDone($player)) {
            $this->draft->updatePlayer($player->markReady());
        }

        $this->draft->logEntry([
            'round' => $this->draft->round,
            'player' => $this->playerId->value,
            'action' => 'pick',
            'item' => $this->item->value(),
        ]);

        (new AdvanceRound($this->draft))->handle();
        $this->draft->refreshStage();

        return $this->draft;
    }
}
