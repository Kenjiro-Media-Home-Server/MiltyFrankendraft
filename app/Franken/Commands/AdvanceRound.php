<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\DraftStage;
use App\Franken\FrankenDraft;
use App\Franken\FrankenPlayer;
use App\Shared\Command;

/**
 * The round barrier: while anyone still has picks to make, nothing moves. Once every player is
 * done, the bags all shift one seat and the next round opens.
 *
 * Safe to call after any state change — it does nothing unless the barrier is actually met.
 */
class AdvanceRound implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
    ) {
    }

    /** @return bool whether the bags actually moved */
    public function handle(): bool
    {
        if ($this->draft->stage !== DraftStage::BAG_DRAFT) {
            return false;
        }

        if ($this->draft->bagDraftIsComplete()) {
            $this->draft->refreshStage();

            return false;
        }

        if (! $this->draft->allPlayersReady()) {
            return false;
        }

        // Normally one pass is enough. But if the bag that lands in front of a player holds
        // nothing they can still take, everyone is instantly ready again and the draft would
        // stall. Keep passing until somebody can act, and give up after a full rotation, because
        // by then every player has seen every bag.
        $rotations = 0;
        $maxRotations = max(1, count($this->draft->players));

        do {
            $this->pass();
            $rotations++;
        } while (
            ! $this->draft->bagDraftIsComplete()
            && ! $this->anyoneCanTakeSomething()
            && $rotations < $maxRotations
        );

        $this->draft->refreshStage();

        return true;
    }

    private function pass(): void
    {
        $this->draft->passBags();
        $this->draft->round++;

        foreach ($this->draft->players as $player) {
            $this->draft->updatePlayer($player->startNewRound());
        }

        // A player whose new bag holds nothing they can legally take is done before they start.
        foreach ($this->draft->players as $player) {
            if ($this->draft->isAutomaticallyDone($player)) {
                $this->draft->updatePlayer($player->markReady());
            }
        }

        $this->draft->logEntry([
            'round' => $this->draft->round,
            'action' => 'pass_bags',
        ]);
    }

    private function anyoneCanTakeSomething(): bool
    {
        foreach ($this->draft->players as $player) {
            if ($this->draft->bagHasTakeableItemFor($player)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Players who cannot fill their hand from anything left in circulation. The UI warns about
     * these and an admin can hand out a replacement.
     *
     * @return array<int, FrankenPlayer>
     */
    public function stuckPlayers(): array
    {
        $stuck = [];

        foreach ($this->draft->players as $player) {
            if ($this->draft->handIsFull($player)) {
                continue;
            }

            $canTakeSomething = false;

            foreach ($this->draft->bags as $bag) {
                foreach ($bag->items() as $item) {
                    if ($this->draft->canTake($player, $item)) {
                        $canTakeSomething = true;

                        break 2;
                    }
                }
            }

            if (! $canTakeSomething) {
                $stuck[] = $player;
            }
        }

        return $stuck;
    }
}
