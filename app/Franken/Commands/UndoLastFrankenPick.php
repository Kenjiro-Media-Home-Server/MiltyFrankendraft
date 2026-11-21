<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\Bag;
use App\Franken\ComponentId;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Admin undo: takes the most recent pick back out of a player's hand and returns it to the bag it
 * came from.
 *
 * A player can already put back their own pick while their round is open (FrankenUnpick). This
 * exists for the cases they cannot fix themselves — they have already passed the bag on, or the
 * round has moved on entirely.
 */
class UndoLastFrankenPick implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
    ) {
    }

    public function handle(): FrankenDraft
    {
        // Rewinding a draft that has moved on to faction building would leave rounds and ready
        // flags in a state nobody can reason about, so it is refused rather than guessed at.
        if ($this->draft->stage !== DraftStage::BAG_DRAFT) {
            throw InvalidFrankenPickException::notInBagDraft();
        }

        $index = $this->lastPickIndex();

        if ($index === null) {
            throw InvalidFrankenPickException::nothingToUndo();
        }

        $entry = $this->draft->log[$index];
        $item = ComponentId::tryFromString((string) $entry['item']);
        $player = $this->draft->playerById(
            PlayerId::fromString((string) $entry['player']),
        );

        if ($item === null || ! $player->hand->has($item)) {
            throw InvalidFrankenPickException::nothingToUndo();
        }

        $bag = $this->bagHeldInRound($player->seat, (int) $entry['round']);

        if ($bag === null) {
            throw InvalidFrankenPickException::nothingToUndo();
        }

        $this->draft->replaceBag($bag->add($item));
        $this->draft->updatePlayer($player->unpick($item)->markReady(false));

        unset($this->draft->log[$index]);
        $this->draft->log = array_values($this->draft->log);

        $this->draft->logEntry([
            'round' => $this->draft->round,
            'player' => $player->id->value,
            'action' => 'undo',
            'item' => $item->value(),
        ]);

        $this->draft->refreshStage();

        return $this->draft;
    }

    private function lastPickIndex(): ?int
    {
        for ($index = count($this->draft->log) - 1; $index >= 0; $index--) {
            if (($this->draft->log[$index]['action'] ?? '') === 'pick') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Which bag sat in front of a given seat in an earlier round.
     *
     * Bags move one seat per round — each seat hands its bag to the seat before it — so a bag that
     * is in front of seat s now was in front of seat s + n a further n rounds back. Inverting that
     * gives the bag a seat was holding then.
     */
    private function bagHeldInRound(int $seat, int $round): ?Bag
    {
        $players = $this->draft->playersInSeatOrder();
        $count = count($players);

        if ($count === 0) {
            return null;
        }

        $roundsSince = $this->draft->round - $round;
        $holderSeat = (($seat - $roundsSince) % $count + $count) % $count;

        return $this->draft->bagFor($players[$holderSeat]->id);
    }
}
