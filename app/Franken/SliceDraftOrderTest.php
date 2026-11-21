<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\PlayerId;
use App\Draft\Seed;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SliceDraftOrderTest extends TestCase
{
    #[Test]
    public function exactlyOneTileGoesToRingOneAndTwoEachToRingsTwoAndThree(): void
    {
        $rings = SliceDraftOrder::ringsFor(new Seed(1), PlayerId::generate(), $this->fiveTiles());

        $counts = array_count_values($rings);

        $this->assertSame(1, $counts[1] ?? 0);
        $this->assertSame(2, $counts[2] ?? 0);
        $this->assertSame(2, $counts[3] ?? 0);
    }

    #[Test]
    public function theSameSeedAndPlayerAlwaysGiveTheSameOrder(): void
    {
        $seed = new Seed(12345);
        $player = PlayerId::generate();

        $this->assertSame(
            SliceDraftOrder::ringsFor($seed, $player, $this->fiveTiles()),
            SliceDraftOrder::ringsFor($seed, $player, $this->fiveTiles()),
        );
    }

    #[Test]
    public function differentPlayersGetIndependentOrders(): void
    {
        $seed = new Seed(12345);
        $tiles = $this->fiveTiles();

        $ann = SliceDraftOrder::ringsFor($seed, PlayerId::fromString('ann'), $tiles);
        $ben = SliceDraftOrder::ringsFor($seed, PlayerId::fromString('ben'), $tiles);

        $this->assertNotSame($ann, $ben);
    }

    #[Test]
    public function theOrderContainsEveryTileExactlyOnce(): void
    {
        $tiles = $this->fiveTiles();
        $order = SliceDraftOrder::orderFor(new Seed(1), PlayerId::generate(), $tiles);

        $this->assertCount(5, $order);
        $this->assertSame(
            array_map(static fn (ComponentId $id) => $id->itemId, $tiles),
            $this->sorted($order),
        );
    }

    #[Test]
    public function theOrderAndTheRingsAgreeOnWhichTileFillsWhichRing(): void
    {
        $seed = new Seed(1);
        $player = PlayerId::generate();
        $tiles = $this->fiveTiles();

        $order = SliceDraftOrder::orderFor($seed, $player, $tiles);
        $rings = SliceDraftOrder::ringsFor($seed, $player, $tiles);

        foreach ($order as $index => $tileId) {
            $expectedRing = match (true) {
                $index === 0 => 1,
                $index <= 2 => 2,
                default => 3,
            };

            $this->assertSame($expectedRing, $rings[$tileId]);
        }
    }

    /** @return array<int, string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @return array<int, ComponentId> */
    private function fiveTiles(): array
    {
        return [
            ComponentId::make(DraftCategory::BLUETILE, '1'),
            ComponentId::make(DraftCategory::BLUETILE, '2'),
            ComponentId::make(DraftCategory::BLUETILE, '3'),
            ComponentId::make(DraftCategory::REDTILE, '4'),
            ComponentId::make(DraftCategory::REDTILE, '5'),
        ];
    }
}
