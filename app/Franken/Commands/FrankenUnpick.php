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
 * Puts an item a player took this round back into the bag they took it from.
 *
 * Only legal while the player's own round is still open: once the bags have moved on, the item is
 * in a bag that somebody else is holding, and only an admin undo can unwind that.
 */
class FrankenUnpick implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
        private readonly ComponentId $item,
    ) {
    }

    public function handle(): FrankenDraft
    {
        if ($this->draft->stage !== DraftStage::BAG_DRAFT) {
            throw InvalidFrankenPickException::notInBagDraft();
        }

        $player = $this->draft->playerById($this->playerId);

        if ($player->ready) {
            throw InvalidFrankenPickException::cannotUnpickAfterPassing();
        }

        if (! $player->hand->has($this->item) || ! $this->pickedThisRound()) {
            throw InvalidFrankenPickException::itemNotPickedThisRound($this->item->value());
        }

        $bag = $this->draft->bagFor($this->playerId);

        if ($bag === null) {
            throw InvalidFrankenPickException::itemNotInBag($this->item->value());
        }

        $this->draft->replaceBag($bag->add($this->item));
        $this->draft->updatePlayer($player->unpick($this->item));

        $this->removeLastPickFromLog();
        $this->draft->refreshStage();

        return $this->draft;
    }

    private function pickedThisRound(): bool
    {
        foreach ($this->draft->log as $entry) {
            if (
                ($entry['action'] ?? '') === 'pick'
                && ($entry['round'] ?? null) === $this->draft->round
                && ($entry['player'] ?? '') === $this->playerId->value
                && ($entry['item'] ?? '') === $this->item->value()
            ) {
                return true;
            }
        }

        return false;
    }

    private function removeLastPickFromLog(): void
    {
        for ($index = count($this->draft->log) - 1; $index >= 0; $index--) {
            $entry = $this->draft->log[$index];

            if (
                ($entry['action'] ?? '') === 'pick'
                && ($entry['round'] ?? null) === $this->draft->round
                && ($entry['player'] ?? '') === $this->playerId->value
                && ($entry['item'] ?? '') === $this->item->value()
            ) {
                unset($this->draft->log[$index]);
                $this->draft->log = array_values($this->draft->log);

                return;
            }
        }
    }
}
