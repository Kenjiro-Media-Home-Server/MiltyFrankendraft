<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\Assembly;
use App\Franken\ComponentId;
use App\Franken\DraftStage;
use App\Franken\Errata;
use App\Franken\Exceptions\InvalidAssemblyException;
use App\Franken\FrankenDraft;
use App\Franken\SliceAssemblyMode;
use App\Franken\SliceDraftOrder;
use App\Franken\SliceLayout;
use App\Shared\Command;

/**
 * Records a player's faction-building choices: the optional swaps they took, the surplus they
 * dropped, and the slice they laid their drafted tiles out into.
 *
 * Saving and confirming are the same operation with a flag, so a player can come back and adjust
 * until they commit.
 */
class SubmitAssembly implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
        /** @var array<string, string> component id => the id taken in its place */
        private readonly array $swaps = [],
        /** @var array<int, string> component ids to drop when a category is over its final limit */
        private readonly array $dropped = [],
        /** @var array<int, string> five tile ids, in slice positions 0..4 */
        private readonly array $slice = [],
        private readonly bool $confirm = false,
        private readonly ?Errata $errata = null,
    ) {
    }

    public function handle(): FrankenDraft
    {
        if ($this->draft->stage === DraftStage::BAG_DRAFT) {
            throw InvalidAssemblyException::notInAssembly();
        }

        $player = $this->draft->playerById($this->playerId);
        $errata = $this->errata ?? Errata::load();

        $this->validateSwaps($player->hand->items(), $errata);
        $this->validateDropped($player->hand->items());
        $this->validateSlice($player->hand->items());

        if ($this->confirm) {
            $this->validateReadyToConfirm($player->hand->items());
        }

        $assembly = new Assembly(
            $this->confirm,
            $this->swaps,
            $this->dropped,
            $this->slice,
        );

        $this->draft->updatePlayer($player->withAssembly($assembly));

        $this->draft->logEntry([
            'round' => $this->draft->round,
            'player' => $this->playerId->value,
            'action' => $this->confirm ? 'confirm_faction' : 'save_faction',
        ]);

        $this->draft->refreshStage();

        return $this->draft;
    }

    /** @param array<int, ComponentId> $held */
    private function validateSwaps(array $held, Errata $errata): void
    {
        foreach ($this->swaps as $from => $to) {
            $fromId = ComponentId::tryFromString((string) $from);

            if ($fromId === null || ! $this->holds($held, $fromId)) {
                throw InvalidAssemblyException::notHeld((string) $from);
            }

            $offered = false;

            foreach ($errata->swapsFor($fromId) as $option) {
                if ($option->value() === (string) $to) {
                    $offered = true;

                    break;
                }
            }

            if (! $offered) {
                throw InvalidAssemblyException::swapNotOffered((string) $from, (string) $to);
            }
        }
    }

    /** @param array<int, ComponentId> $held */
    private function validateDropped(array $held): void
    {
        foreach ($this->dropped as $item) {
            $id = ComponentId::tryFromString((string) $item);

            if ($id === null || ! $this->holds($held, $id)) {
                throw InvalidAssemblyException::notHeld((string) $item);
            }
        }
    }

    /**
     * The slice must be exactly the five map tiles this player drafted, each placed once.
     *
     * @param array<int, ComponentId> $held
     */
    private function validateSlice(array $held): void
    {
        if ($this->slice === []) {
            return;
        }

        if (count($this->slice) !== Assembly::SLICE_SIZE) {
            throw InvalidAssemblyException::wrongNumberOfTiles(
                Assembly::SLICE_SIZE,
                count($this->slice),
            );
        }

        $draftedTiles = $this->draftedSliceTiles($held);
        $seen = [];

        foreach ($this->slice as $tile) {
            $tile = (string) $tile;

            if (! in_array($tile, $draftedTiles, true)) {
                throw InvalidAssemblyException::tileNotDrafted($tile);
            }

            if (isset($seen[$tile])) {
                throw InvalidAssemblyException::duplicateTile($tile);
            }

            $seen[$tile] = true;
        }

        if ($this->draft->settings->sliceAssemblyMode === SliceAssemblyMode::DRAFT) {
            $this->validateSliceDraftOrder($held);
        }
    }

    /**
     * In the "tile draft" variant a tile may only go where its shuffled draw order allows: the
     * ring-1 slot is forced, and each pair of ring-2 / ring-3 slots may only take the two tiles
     * drawn for that ring, in either order.
     *
     * @param array<int, ComponentId> $held
     */
    private function validateSliceDraftOrder(array $held): void
    {
        $sliceTiles = array_values(array_filter(
            $held,
            static fn (ComponentId $item) => $item->category->isSliceTile(),
        ));

        $rings = SliceDraftOrder::ringsFor($this->draft->settings->seed, $this->playerId, $sliceTiles);

        foreach ($this->slice as $position => $tile) {
            $expectedRing = SliceLayout::ringOf((int) $position);
            $tileRing = $rings[(string) $tile] ?? null;

            if ($tileRing !== $expectedRing) {
                throw InvalidAssemblyException::wrongRingForPosition(
                    (string) $tile,
                    SliceLayout::displayNumber((int) $position),
                );
            }
        }
    }

    /** @param array<int, ComponentId> $held */
    private function validateReadyToConfirm(array $held): void
    {
        if ($this->draftedSliceTiles($held) !== [] && count($this->slice) !== Assembly::SLICE_SIZE) {
            throw InvalidAssemblyException::sliceRequired();
        }

        // After dropping the surplus, every category must sit at exactly its final limit.
        foreach ($this->draft->settings->categories() as $category) {
            $expected = $this->draft->settings->finalLimit($category);

            if ($expected === 0) {
                continue;
            }

            $kept = 0;

            foreach ($held as $item) {
                if ($item->category === $category && ! in_array($item->value(), $this->dropped, true)) {
                    $kept++;
                }
            }

            if ($kept !== $expected) {
                throw InvalidAssemblyException::wrongCategoryCount($category, $expected, $kept);
            }
        }
    }

    /**
     * The tile ids behind this player's drafted blue and red tiles.
     *
     * @param  array<int, ComponentId> $held
     * @return array<int, string>
     */
    private function draftedSliceTiles(array $held): array
    {
        $tiles = [];

        foreach ($held as $item) {
            if ($item->category->isSliceTile()) {
                $tiles[] = $item->itemId;
            }
        }

        return $tiles;
    }

    /** @param array<int, ComponentId> $held */
    private function holds(array $held, ComponentId $id): bool
    {
        foreach ($held as $item) {
            if ($item->equals($id)) {
                return true;
            }
        }

        return false;
    }
}
