<?php

declare(strict_types=1);

namespace App\Testing\Factories;

use App\Draft\Name;
use App\Draft\PlayerId;
use App\Draft\Seed;
use App\Franken\Commands\FrankenPick;
use App\Franken\Commands\FrankenSetReady;
use App\Franken\Commands\GenerateFrankenDraft;
use App\Franken\Component;
use App\Franken\ComponentId;
use App\Franken\ComponentRepository;
use App\Franken\ComponentSource;
use App\Franken\DraftCategory;
use App\Franken\DraftOrderMode;
use App\Franken\DraftStage;
use App\Franken\Errata;
use App\Franken\FrankenDraft;
use App\Franken\FrankenSettings;

/**
 * Builds Franken drafts from a small synthetic component set, so the rules tests stay fast and do
 * not change meaning when the generated component database is re-synced.
 */
class FrankenDraftFactory
{
    public const SOURCE = 'test';

    /**
     * Installs a synthetic component database: enough items in every category to fill bags for the
     * given number of players, with predictable ids like "ABILITY:ability-1".
     */
    public static function useSyntheticComponents(int $playerCount = 6, array $categories = []): void
    {
        $categories = $categories === [] ? DraftCategory::displayOrder() : $categories;
        $components = [];

        foreach ($categories as $category) {
            if ($category === DraftCategory::DRAFTORDER) {
                // Draft order items are synthesised at runtime, never stored.
                continue;
            }

            // Generous headroom so a test can hand out extras without running a pool dry.
            $count = $category->defaultBagCount() * $playerCount + 4;

            for ($index = 1; $index <= $count; $index++) {
                $id = ComponentId::make($category, strtolower($category->value) . '-' . $index);
                $components[$id->value()] = new Component(
                    $id,
                    ucfirst(strtolower($category->value)) . ' ' . $index,
                    'testfaction',
                    'The Test Faction',
                    self::SOURCE,
                    'Rules text for ' . $id->value(),
                );
            }
        }

        ComponentRepository::useComponents($components);
        ComponentSource::useSources([
            self::SOURCE => new ComponentSource(self::SOURCE, 'Test content', 'official', count($components), true),
        ]);
        Errata::useInstance(new Errata());
    }

    /** Puts the real generated data back, for tests that need it. */
    public static function useRealComponents(): void
    {
        ComponentRepository::useComponents(null);
        ComponentSource::useSources(null);
        Errata::useInstance(null);
    }

    public static function settings(int $playerCount = 6, array $overrides = []): FrankenSettings
    {
        $names = [];

        for ($index = 1; $index <= $playerCount; $index++) {
            $names[] = 'Player ' . $index;
        }

        return new FrankenSettings(
            $overrides['playerNames'] ?? $names,
            $overrides['name'] ?? new Name('Test draft'),
            $overrides['seed'] ?? new Seed(4242),
            $overrides['presetDraftOrder'] ?? true,
            $overrides['sources'] ?? [self::SOURCE],
            $overrides['draftOrderMode'] ?? DraftOrderMode::DRAFTED,
        );
    }

    /** A draft dealt from the synthetic component set and ready for its first pick. */
    public static function draft(int $playerCount = 6, array $overrides = []): FrankenDraft
    {
        self::useSyntheticComponents($playerCount);

        return (new GenerateFrankenDraft(self::settings($playerCount, $overrides)))->handle();
    }

    /** Takes everything this player may still legally take from the bag in front of them. */
    public static function spendPicks(FrankenDraft $draft, PlayerId $playerId): FrankenDraft
    {
        $player = $draft->playerById($playerId);

        while ($draft->remainingPicksFor($player) > 0) {
            $choice = self::firstTakeableItem($draft, $player);

            if ($choice === null) {
                break;
            }

            $draft = (new FrankenPick($draft, $player->id, $choice, $draft->round))->handle();
            $player = $draft->playerById($player->id);
        }

        return $draft;
    }

    /**
     * Spends the round's picks and then hands the bag on, which since the no-early-pass rule is
     * the only way a player can legally pass while there is anything left they could take.
     */
    public static function passBagFor(FrankenDraft $draft, PlayerId $playerId): FrankenDraft
    {
        $draft = self::spendPicks($draft, $playerId);

        return (new FrankenSetReady($draft, $playerId))->handle();
    }

    /**
     * Plays the whole bag draft: each player takes what they can, then passes the bag on. Passing
     * is explicit now, so the helper has to do it too — the barrier no longer trips on its own when
     * a player runs out of picks.
     */
    public static function playBagDraft(FrankenDraft $draft): FrankenDraft
    {
        $guard = 0;

        while ($draft->stage === DraftStage::BAG_DRAFT && $guard < 500) {
            $guard++;
            $roundBefore = $draft->round;

            foreach ($draft->playersInSeatOrder() as $seated) {
                $player = $draft->playerById($seated->id);

                while ($draft->remainingPicksFor($player) > 0) {
                    $choice = self::firstTakeableItem($draft, $player);

                    if ($choice === null) {
                        break;
                    }

                    $draft = (new FrankenPick($draft, $player->id, $choice, $draft->round))->handle();
                    $player = $draft->playerById($player->id);
                }

                if ($draft->stage !== DraftStage::BAG_DRAFT || $draft->round !== $roundBefore) {
                    break;
                }

                if (! $draft->playerById($player->id)->ready) {
                    $draft = (new FrankenSetReady($draft, $player->id))->handle();
                }

                if ($draft->stage !== DraftStage::BAG_DRAFT || $draft->round !== $roundBefore) {
                    break;
                }
            }

            if ($draft->round === $roundBefore && $draft->stage === DraftStage::BAG_DRAFT) {
                break;
            }
        }

        return $draft;
    }

    private static function firstTakeableItem(FrankenDraft $draft, $player): ?ComponentId
    {
        $bag = $draft->bagFor($player->id);

        if ($bag === null) {
            return null;
        }

        foreach ($bag->items() as $item) {
            if ($draft->canTake($player, $item)) {
                return $item;
            }
        }

        return null;
    }
}
