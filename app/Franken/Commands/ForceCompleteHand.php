<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\Bag;
use App\Franken\ComponentId;
use App\Franken\DraftCategory;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Admin rescue for a player who cannot finish their faction from the bag in front of them.
 *
 * Bag composition makes this unlikely — every bag holds two of most categories — but a run of
 * unlucky picks can leave somebody one home system short with nothing takeable in reach. Rather
 * than stall the whole table, the admin can hand them a legal item for each category they are
 * still missing, taken from whatever is left in circulation.
 *
 * Does not save; the caller runs it inside DraftRepository::mutate().
 */
class ForceCompleteHand implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
    ) {
    }

    /** @return array<int, string> the component ids that were handed over */
    public function handle(): array
    {
        if ($this->draft->stage !== DraftStage::BAG_DRAFT) {
            throw InvalidFrankenPickException::notInBagDraft();
        }

        $player = $this->draft->playerById($this->playerId);

        if ($this->draft->handIsFull($player)) {
            throw InvalidFrankenPickException::handIsFull();
        }

        $granted = [];

        foreach ($this->draft->settings->categories() as $category) {
            $limit = $this->draft->settings->handLimit($category);

            while ($player->hand->countIn($category) < $limit) {
                $found = $this->takeFromCirculation($category);

                if ($found === null) {
                    // Nothing of this category is left anywhere, so nothing more can be done here.
                    break;
                }

                [$bag, $item] = $found;

                $this->draft->replaceBag($bag->remove($item));
                $player = $player->withHand($player->hand->add($item));
                $this->draft->updatePlayer($player);

                $granted[] = $item->value();

                $this->draft->logEntry([
                    'round' => $this->draft->round,
                    'player' => $this->playerId->value,
                    'action' => 'force_complete',
                    'item' => $item->value(),
                ]);
            }
        }

        if ($granted === []) {
            throw InvalidFrankenPickException::nothingLeftToGrant();
        }

        // The hand may now be full, which can finish the bag draft outright.
        (new AdvanceRound($this->draft))->handle();
        $this->draft->refreshStage();

        return $granted;
    }

    /**
     * The first item of this category sitting in any bag, searched from the fullest bag down so the
     * grant is taken from wherever it is least missed.
     *
     * @return array{0: Bag, 1: ComponentId}|null
     */
    private function takeFromCirculation(DraftCategory $category): ?array
    {
        $bags = array_values($this->draft->bags);

        usort($bags, static fn (Bag $a, Bag $b) => $b->count() <=> $a->count());

        foreach ($bags as $bag) {
            $items = $bag->itemsIn($category);

            if ($items !== []) {
                return [$bag, $items[0]];
            }
        }

        return null;
    }
}
