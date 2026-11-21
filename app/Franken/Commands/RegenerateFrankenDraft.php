<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\Seed;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Re-deals the bags before anybody has picked, for when the admin does not like the look of the
 * draw. Mirrors the Milty "regenerate" escape hatch.
 *
 * Players keep their seats, their claims and their passkeys — only the contents of the bags change,
 * and optionally the seating order.
 *
 * Does not save; the caller runs it inside DraftRepository::mutate().
 */
class RegenerateFrankenDraft implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly bool $regenerateOrder = false,
    ) {
    }

    public function handle(): FrankenDraft
    {
        if (! $this->draft->canRegenerate()) {
            throw InvalidFrankenPickException::cannotRegenerateStartedDraft();
        }

        $settings = $this->draft->settings;
        $settings->seed = new Seed();

        if ($this->regenerateOrder) {
            $settings->seed->setForPlayerOrder();
            $seats = array_keys($this->draft->players);
            shuffle($seats);

            foreach ($seats as $seat => $playerId) {
                $this->draft->updatePlayer($this->draft->players[$playerId]->withSeat($seat));
            }
        }

        // Which categories are playable can only have changed if the data was re-synced under a
        // live draft, but resolving it again keeps bag and hand sizes honest either way.
        $settings->activeCategories = (new BuildComponentPools($settings))->activeCategoryValues();

        $pools = (new BuildComponentPools($settings))->handle();
        $this->draft->bags = (new BuildBags($settings, $pools))->handle();

        $bagIds = array_keys($this->draft->bags);
        $holders = [];

        foreach ($this->draft->playersInSeatOrder() as $index => $player) {
            $holders[$player->id->value] = $bagIds[$index];
            $this->draft->updatePlayer($player->startNewRound());
        }

        $this->draft->bagHolders = $holders;
        $this->draft->round = 1;
        $this->draft->stage = DraftStage::BAG_DRAFT;
        $this->draft->log = [];

        return $this->draft;
    }
}
