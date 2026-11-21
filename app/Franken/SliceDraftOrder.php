<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\PlayerId;
use App\Draft\Seed;

/**
 * The "tile draft" slice-assembly variant: instead of freely arranging the five drafted slice
 * tiles, the player draws them one at a time from a shuffled bag (SliceLayout defines the rings
 * the drawn tiles fill). Ring 1 gets exactly one tile with no choice. Ring 2 and ring 3 each get
 * two tiles, drawn one after the other: the first of the pair is placed into either of that ring's
 * two slots *before* the second tile is known, and the second tile — now the only tile left for
 * that ring — has nowhere else to go but the slot the first one did not take.
 *
 * The draw order is a pure function of the draft seed, the player's id and which tiles they
 * drafted — nothing about it is stored. It is recomputed identically every time it is needed,
 * offline-safe like the rest of the draft's seeded randomness.
 *
 * Note for validators: because the second tile of a pair always ends up in whichever slot the
 * first one left empty, the set of *final* arrangements this allows is exactly "either of the
 * pair's two tiles may sit in either of the pair's two slots" — the one-at-a-time drawing changes
 * what the player knows when they decide, not which finished slices are legal. Server-side
 * validation (SubmitAssembly::validateSliceDraftOrder) checks ring membership accordingly, via
 * ringsFor(), and needs no knowledge of the draw order itself.
 */
final class SliceDraftOrder
{
    /**
     * The five tiles in the order they are drawn: index 0 fills ring 1, indices 1-2 fill ring 2
     * (in draw order), indices 3-4 fill ring 3 (in draw order).
     *
     * @param  array<int, ComponentId> $sliceTiles the player's drafted BLUETILE/REDTILE ids
     * @return array<int, string> tile ids (ComponentId::itemId), in draw order
     */
    public static function orderFor(Seed $seed, PlayerId $playerId, array $sliceTiles): array
    {
        $ids = array_map(static fn (ComponentId $id) => $id->itemId, $sliceTiles);

        // A fixed starting order before the seeded shuffle, so the result only ever depends on the
        // seed and the player, never on incidental draft-order-of-pick within their hand.
        sort($ids);

        $seed->setForFrankenSliceDraft($playerId->value);
        shuffle($ids);

        return $ids;
    }

    /**
     * @param  array<int, ComponentId> $sliceTiles the player's drafted BLUETILE/REDTILE ids
     * @return array<string, int> tile id (ComponentId::itemId) => ring (1, 2 or 3)
     */
    public static function ringsFor(Seed $seed, PlayerId $playerId, array $sliceTiles): array
    {
        $rings = [];

        foreach (self::orderFor($seed, $playerId, $sliceTiles) as $index => $id) {
            $rings[$id] = match (true) {
                $index === 0 => 1,
                $index <= 2 => 2,
                default => 3,
            };
        }

        return $rings;
    }
}
