<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Draft\Secrets;
use App\Franken\Exceptions\InvalidFrankenPickException;

/**
 * A Franken draft: per-player bags that pass around the table in synchronised rounds, then an
 * assembly stage where everyone builds their faction out of what they drafted.
 *
 * Deliberately a separate aggregate from App\Draft\Draft rather than a subclass: the two modes
 * share only their id, their players' secrets and their storage, and Milty must keep behaving
 * exactly as it does today.
 */
class FrankenDraft implements PersistableDraft
{
    public const MODE = 'franken';

    public function __construct(
        public readonly string $id,
        public FrankenSettings $settings,
        public Secrets $secrets,
        /** @var array<string, FrankenPlayer> keyed by player id */
        public array $players,
        /** @var array<string, Bag> keyed by bag id */
        public array $bags,
        /** @var array<string, string> player id => bag id */
        public array $bagHolders,
        public DraftStage $stage = DraftStage::BAG_DRAFT,
        public int $round = 1,
        /** @var array<int, array<string, mixed>> */
        public array $log = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mode(): string
    {
        return self::MODE;
    }

    public function secrets(): Secrets
    {
        return $this->secrets;
    }

    public function isDone(): bool
    {
        return $this->stage === DraftStage::DONE;
    }

    /** The bags can be re-dealt until the first pick is made, as in the Milty draft. */
    public function canRegenerate(): bool
    {
        foreach ($this->log as $entry) {
            if (($entry['action'] ?? '') === 'pick') {
                return false;
            }
        }

        foreach ($this->players as $player) {
            if ($player->hand->size() > 0) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ players

    public function playerById(PlayerId $id): FrankenPlayer
    {
        $player = $this->players[$id->value] ?? null;

        if ($player === null) {
            throw InvalidFrankenPickException::playerNotInDraft($id->value);
        }

        return $player;
    }

    public function hasPlayer(PlayerId $id): bool
    {
        return isset($this->players[$id->value]);
    }

    public function updatePlayer(FrankenPlayer $player): void
    {
        $this->players[$player->id->value] = $player;
    }

    /** Players in seating order, which is also the bag-passing order. */
    public function playersInSeatOrder(): array
    {
        $players = array_values($this->players);
        usort($players, static fn (FrankenPlayer $a, FrankenPlayer $b) => $a->seat <=> $b->seat);

        return $players;
    }

    // --------------------------------------------------------------------- bags

    public function bagFor(PlayerId $playerId): ?Bag
    {
        $bagId = $this->bagHolders[$playerId->value] ?? null;

        return $bagId === null ? null : ($this->bags[$bagId] ?? null);
    }

    public function replaceBag(Bag $bag): void
    {
        $this->bags[$bag->id] = $bag;
    }

    /**
     * Move every bag one seat along. Mirrors the reference implementation: the player in each seat
     * receives the bag from the next seat, and the last seat receives the first seat's bag.
     */
    public function passBags(): void
    {
        $players = $this->playersInSeatOrder();
        $count = count($players);

        if ($count < 2) {
            return;
        }

        $holders = [];

        foreach ($players as $index => $player) {
            $next = $players[($index + 1) % $count];
            $holders[$player->id->value] = $this->bagHolders[$next->id->value];
        }

        $this->bagHolders = $holders;
    }

    // ------------------------------------------------------------------- rounds

    /** How many picks this round grants before hand capacity is taken into account. */
    public function quota(): int
    {
        return $this->settings->quotaForRound($this->round);
    }

    /**
     * How many more items this player may take from the bag in front of them right now.
     *
     * Bounded by the round quota and by overall hand capacity, but also by what the bag in front of
     * them can actually still supply: a bag holding only items from categories this player has
     * already filled out is worth zero picks, however much quota or hand room is left.
     */
    public function remainingPicksFor(FrankenPlayer $player): int
    {
        $fromQuota = $this->quota() - $player->picksThisRound;
        $fromHand = $this->settings->handSize() - $player->hand->size();
        $cap = max(0, min($fromQuota, $fromHand));

        $bag = $this->bagFor($player->id);

        if ($bag === null) {
            return $cap;
        }

        $inBag = [];

        foreach ($bag->items() as $item) {
            $inBag[$item->category->value] = ($inBag[$item->category->value] ?? 0) + 1;
        }

        $achievable = 0;

        foreach ($inBag as $categoryValue => $count) {
            $category = DraftCategory::from($categoryValue);
            $capacityLeft = max(0, $this->settings->handLimit($category) - $player->hand->countIn($category));
            $achievable += min($capacityLeft, $count);
        }

        return min($cap, $achievable);
    }

    /** Whether this player could still legally take the given item. */
    public function canTake(FrankenPlayer $player, ComponentId $id): bool
    {
        $limit = $this->settings->handLimit($id->category);

        return $limit > 0 && $player->hand->countIn($id->category) < $limit;
    }

    /** Whether anything left in this player's bag is still useful to them. */
    public function bagHasTakeableItemFor(FrankenPlayer $player): bool
    {
        $bag = $this->bagFor($player->id);

        if ($bag === null) {
            return false;
        }

        foreach ($bag->items() as $item) {
            if ($this->canTake($player, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The only reason a player is carried past the barrier without saying so themselves: the bag in
     * front of them holds nothing they could legally take, so there is nothing to decide.
     *
     * Spending every pick does NOT count. A player who is out of picks still has to pass the bag on
     * deliberately, so nobody is rushed off a bag they were still reading.
     */
    public function isAutomaticallyDone(FrankenPlayer $player): bool
    {
        return ! $this->bagHasTakeableItemFor($player);
    }

    /**
     * Rule: you may not hand on a bag you could still legally draft from. Passing early is only
     * allowed once your picks for the round are spent, or once nothing left in the bag is
     * something you may take — which is the case isAutomaticallyDone() already covers.
     */
    public function mustKeepPicking(FrankenPlayer $player): bool
    {
        return $this->remainingPicksFor($player) > 0 && $this->bagHasTakeableItemFor($player);
    }

    /**
     * Hands are secret for the length of the draft. Everyone can see how much someone has taken,
     * but not what, until the last faction is confirmed and the draft is done.
     */
    public function handsAreHidden(): bool
    {
        return $this->stage !== DraftStage::DONE;
    }

    public function allPlayersReady(): bool
    {
        foreach ($this->players as $player) {
            if (! $player->ready && ! $this->isAutomaticallyDone($player)) {
                return false;
            }
        }

        return true;
    }

    public function handIsFull(FrankenPlayer $player): bool
    {
        return $player->hand->size() >= $this->settings->handSize();
    }

    /** The bag draft is over once every hand is full. */
    public function bagDraftIsComplete(): bool
    {
        foreach ($this->players as $player) {
            if (! $this->handIsFull($player)) {
                return false;
            }
        }

        return true;
    }

    public function assemblyIsComplete(): bool
    {
        foreach ($this->players as $player) {
            if (! $player->assembly->confirmed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recalculates which stage the draft is in. Called after every state change so the stage can
     * never drift out of step with the players' hands.
     */
    public function refreshStage(): void
    {
        if ($this->stage === DraftStage::BAG_DRAFT && $this->bagDraftIsComplete()) {
            $this->stage = DraftStage::ASSEMBLY;

            return;
        }

        if ($this->stage === DraftStage::ASSEMBLY && $this->assemblyIsComplete()) {
            $this->stage = DraftStage::DONE;

            return;
        }

        // An admin undo can reopen a stage that had already closed.
        if ($this->stage === DraftStage::DONE && ! $this->assemblyIsComplete()) {
            $this->stage = DraftStage::ASSEMBLY;
        }

        if ($this->stage === DraftStage::ASSEMBLY && ! $this->bagDraftIsComplete()) {
            $this->stage = DraftStage::BAG_DRAFT;
        }
    }

    public function logEntry(array $entry): void
    {
        $this->log[] = $entry;
    }

    // ---------------------------------------------------------------- persisting

    public function toFileContent(): string
    {
        return (string) json_encode($this->toArray(null, true, true));
    }

    /**
     * @param PlayerId|null $viewer        the player asking, if they proved who they are
     * @param bool          $isAdmin       admins see every bag
     * @param bool          $includeSecrets only ever true when writing to storage
     */
    public function toArray(?PlayerId $viewer = null, bool $isAdmin = false, bool $includeSecrets = false): array
    {
        $data = [
            'id' => $this->id,
            'mode' => self::MODE,
            'done' => $this->isDone(),
            'config' => $this->settings->toArray(),
            'franken' => [
                'stage' => $this->stage->value,
                'round' => $this->round,
                'quota' => $this->quota(),
                'bag_size' => $this->settings->bagSize(),
                'hand_size' => $this->settings->handSize(),
                // Sent so the client never has to reimplement the limits: the server stays the
                // authority on what a legal hand looks like.
                'limits' => $this->limitsToArray(),
                'bags' => $this->bagsToArray($viewer, $isAdmin, $includeSecrets),
                'bag_holders' => $this->bagHolders,
                'players' => $this->playersToArray($viewer, $isAdmin, $includeSecrets),
                'log' => $this->logToArray($viewer, $isAdmin, $includeSecrets),
            ],
        ];

        if ($includeSecrets) {
            // Stored drafts hold ids only; component details are regenerated data and would just
            // bloat the file.
            $data['secrets'] = $this->secrets->toArray();
        } else {
            $data['franken']['components'] = $this->visibleComponents($viewer, $isAdmin);
        }

        return $data;
    }

    /**
     * Everyone's seat, but only your own hand while the draft is running. The count is always
     * public — following the draft means knowing how full everyone is — and so is whether a
     * faction has been confirmed; the contents are not.
     *
     * @return array<string, array<string, mixed>>
     */
    private function playersToArray(?PlayerId $viewer, bool $isAdmin, bool $includeSecrets): array
    {
        $players = [];

        foreach ($this->players as $key => $player) {
            $data = $player->toArray();
            $data['hand_count'] = count($data['hand']);

            if ($this->maySeeHandOf($player, $viewer, $isAdmin, $includeSecrets)) {
                if ($this->settings->sliceAssemblyMode === SliceAssemblyMode::DRAFT) {
                    // Both derived straight from the hand that is already visible here, so shipping
                    // them does not reveal anything new — it just saves the client from having to
                    // replicate the seeded shuffle. The order is what lets the client draw one tile
                    // at a time instead of a whole ring at once: index 0 is the ring-1 tile, 1-2 are
                    // ring 2 in draw order, 3-4 are ring 3 in draw order.
                    $sliceTiles = array_values(array_filter(
                        $player->hand->items(),
                        static fn (ComponentId $item) => $item->category->isSliceTile(),
                    ));

                    $data['slice_draw_order'] = SliceDraftOrder::orderFor(
                        $this->settings->seed,
                        $player->id,
                        $sliceTiles,
                    );
                    $data['slice_rings'] = SliceDraftOrder::ringsFor(
                        $this->settings->seed,
                        $player->id,
                        $sliceTiles,
                    );
                }

                $players[$key] = $data;

                continue;
            }

            $data['hand'] = [];
            // The assembly is built out of the hand, so publishing it would publish the hand.
            $data['assembly'] = ['confirmed' => $player->assembly->confirmed];
            $players[$key] = $data;
        }

        return $players;
    }

    /**
     * The log with other players' picks reduced to the fact that a pick happened. Your own entries
     * stay intact: the client reads them back to work out what you may still put back this round.
     *
     * @return array<int, array<string, mixed>>
     */
    private function logToArray(?PlayerId $viewer, bool $isAdmin, bool $includeSecrets): array
    {
        if ($includeSecrets || $isAdmin || ! $this->handsAreHidden()) {
            return $this->log;
        }

        $log = [];

        foreach ($this->log as $entry) {
            $isSomebodyElsesPick = ($entry['action'] ?? '') === 'pick'
                && ($viewer === null || ($entry['player'] ?? null) !== $viewer->value);

            if ($isSomebodyElsesPick) {
                unset($entry['item']);
            }

            $log[] = $entry;
        }

        return $log;
    }

    private function maySeeHandOf(
        FrankenPlayer $player,
        ?PlayerId $viewer,
        bool $isAdmin,
        bool $includeSecrets,
    ): bool {
        if ($includeSecrets || $isAdmin || ! $this->handsAreHidden()) {
            return true;
        }

        return $viewer !== null && $viewer->value === $player->id->value;
    }

    /** @return array<string, array<string, int>> */
    private function limitsToArray(): array
    {
        $limits = [];

        foreach ($this->settings->categories() as $category) {
            $limits[$category->value] = [
                'bag' => $this->settings->bagCount($category),
                'hand' => $this->settings->handLimit($category),
                'final' => $this->settings->finalLimit($category),
            ];
        }

        return $limits;
    }

    /**
     * The component details behind every id this caller can see, so the client never has to ship
     * the whole 2400-item database or guess at what a card does.
     *
     * @return array<string, array<string, mixed>>
     */
    private function visibleComponents(?PlayerId $viewer, bool $isAdmin): array
    {
        $errata = Errata::load();
        $ids = [];

        // Only hands this caller may see: shipping the components would name the cards.
        foreach ($this->players as $player) {
            if (! $this->maySeeHandOf($player, $viewer, $isAdmin, false)) {
                continue;
            }

            foreach ($player->hand->items() as $item) {
                $ids[$item->value()] = $item;

                // Whatever a held card hands out or can be exchanged for is needed to explain it.
                foreach ($errata->grantsFor($item) as $granted) {
                    $ids[$granted->value()] = $granted;
                }

                foreach ($errata->swapsFor($item) as $swap) {
                    $ids[$swap->value()] = $swap;
                }
            }
        }

        $visibleBagId = $viewer === null ? null : ($this->bagHolders[$viewer->value] ?? null);

        foreach ($this->bags as $bagId => $bag) {
            if (! $isAdmin && $bagId !== $visibleBagId) {
                continue;
            }

            foreach ($bag->items() as $item) {
                $ids[$item->value()] = $item;

                // The box says what this grants and what it can be exchanged for, so those parts
                // have to travel with it or the client can only print their raw ids.
                foreach ($errata->grantsFor($item) as $granted) {
                    $ids[$granted->value()] = $granted;
                }

                foreach ($errata->swapsFor($item) as $swap) {
                    $ids[$swap->value()] = $swap;
                }
            }
        }

        $components = [];

        foreach ($ids as $key => $id) {
            $component = ComponentRepository::find($id);

            if ($component === null) {
                continue;
            }

            $components[$key] = [
                ...$component->toArray(),
                'text' => $component->displayText($errata),
                'swaps' => array_map(
                    static fn (ComponentId $swap) => $swap->value(),
                    $errata->swapsFor($id),
                ),
                'grants' => array_map(
                    static fn (ComponentId $grant) => $grant->value(),
                    $errata->grantsFor($id),
                ),
            ];
        }

        ksort($components);

        return $components;
    }

    /**
     * Bag contents are private to whoever holds the bag. Everyone else sees only how many items
     * are left in it, which is all they need to follow the draft.
     */
    private function bagsToArray(?PlayerId $viewer, bool $isAdmin, bool $includeSecrets): array
    {
        $visibleBagId = $viewer === null ? null : ($this->bagHolders[$viewer->value] ?? null);
        $bags = [];

        foreach ($this->bags as $bagId => $bag) {
            if ($includeSecrets || $isAdmin || $bagId === $visibleBagId) {
                $bags[$bagId] = $bag->toArray();

                continue;
            }

            $bags[$bagId] = ['id' => $bagId, 'count' => $bag->count()];
        }

        return $bags;
    }

    public static function fromJson(array $data): self
    {
        $players = [];

        foreach ((array) ($data['franken']['players'] ?? []) as $playerData) {
            $player = FrankenPlayer::fromJson((array) $playerData);
            $players[$player->id->value] = $player;
        }

        $bags = [];

        foreach ((array) ($data['franken']['bags'] ?? []) as $bagData) {
            $bag = Bag::fromJson((array) $bagData);
            $bags[$bag->id] = $bag;
        }

        return new self(
            (string) $data['id'],
            FrankenSettings::fromJson((array) ($data['config'] ?? [])),
            Secrets::fromJson((array) ($data['secrets'] ?? [])),
            $players,
            $bags,
            (array) ($data['franken']['bag_holders'] ?? []),
            DraftStage::tryFrom((string) ($data['franken']['stage'] ?? 'bag_draft'))
                ?? DraftStage::BAG_DRAFT,
            (int) ($data['franken']['round'] ?? 1),
            array_values((array) ($data['franken']['log'] ?? [])),
        );
    }
}
