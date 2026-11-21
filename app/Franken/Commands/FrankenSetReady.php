<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Marks a player done with the bag in front of them. When the last player readies up, the bags
 * move.
 *
 * A bag may only be handed on once there is nothing left to take from it: either the round's
 * picks are spent, or nothing still in the bag is something this player may legally hold. Passing
 * early to deny the table a card is not allowed.
 */
class FrankenSetReady implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
        private readonly bool $ready = true,
        /** The round the player believed they were in; guards against a stale page. */
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

        if ($this->ready && $this->draft->mustKeepPicking($player)) {
            throw InvalidFrankenPickException::stillHasPicks($this->draft->remainingPicksFor($player));
        }

        $this->draft->updatePlayer($player->markReady($this->ready));

        $this->draft->logEntry([
            'round' => $this->draft->round,
            'player' => $this->playerId->value,
            'action' => $this->ready ? 'ready' : 'unready',
        ]);

        (new AdvanceRound($this->draft))->handle();
        $this->draft->refreshStage();

        return $this->draft;
    }
}
