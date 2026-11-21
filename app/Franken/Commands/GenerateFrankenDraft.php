<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\DraftId;
use App\Draft\Secrets;
use App\Franken\DraftStage;
use App\Franken\FrankenDraft;
use App\Franken\FrankenPlayer;
use App\Franken\FrankenSettings;
use App\Shared\Command;

/**
 * Creates a ready-to-play Franken draft: seats the players, works out which categories the chosen
 * sources can actually fill, deals the bags, and hands every player their first one.
 */
class GenerateFrankenDraft implements Command
{
    public function __construct(
        private readonly FrankenSettings $settings,
    ) {
    }

    public function handle(): FrankenDraft
    {
        // Which categories are playable depends on the source selection, and the bag and hand
        // sizes depend on that in turn, so this has to be settled before anything is dealt.
        $this->settings->activeCategories = (new BuildComponentPools($this->settings))
            ->activeCategoryValues();

        $pools = (new BuildComponentPools($this->settings))->handle();
        $bags = (new BuildBags($this->settings, $pools))->handle();

        $players = $this->seatPlayers();
        $bagHolders = $this->dealBags($players, $bags);

        return new FrankenDraft(
            DraftId::generate(),
            $this->settings,
            new Secrets(Secrets::generateSecret()),
            $players,
            $bags,
            $bagHolders,
            DraftStage::BAG_DRAFT,
            1,
            [],
        );
    }

    /** @return array<string, FrankenPlayer> */
    private function seatPlayers(): array
    {
        $names = [...$this->settings->playerNames];

        if (! $this->settings->presetDraftOrder) {
            $this->settings->seed->setForPlayerOrder();
            shuffle($names);
        }

        $players = [];

        foreach ($names as $seat => $name) {
            $player = FrankenPlayer::create($name, $seat);
            $players[$player->id->value] = $player;
        }

        return $players;
    }

    /**
     * @param  array<string, FrankenPlayer> $players
     * @param  array<string, \App\Franken\Bag> $bags
     * @return array<string, string>
     */
    private function dealBags(array $players, array $bags): array
    {
        $bagIds = array_keys($bags);
        $holders = [];

        foreach (array_values($players) as $index => $player) {
            $holders[$player->id->value] = $bagIds[$index];
        }

        return $holders;
    }
}
