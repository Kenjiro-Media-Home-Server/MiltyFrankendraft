<?php

declare(strict_types=1);

namespace App\Franken;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SliceLayoutTest extends TestCase
{
    #[Test]
    public function ringOneIsExactlyOnePosition(): void
    {
        $this->assertSame([4], SliceLayout::positionsInRing(1));
    }

    #[Test]
    public function ringsTwoAndThreeAreExactlyTwoPositionsEach(): void
    {
        $this->assertCount(2, SliceLayout::positionsInRing(2));
        $this->assertCount(2, SliceLayout::positionsInRing(3));
    }

    #[Test]
    public function everyPositionBelongsToExactlyOneRing(): void
    {
        $seen = [];

        foreach ([1, 2, 3] as $ring) {
            foreach (SliceLayout::positionsInRing($ring) as $position) {
                $this->assertArrayNotHasKey($position, $seen, 'position ' . $position . ' is in two rings');
                $seen[$position] = $ring;
                $this->assertSame($ring, SliceLayout::ringOf($position));
            }
        }

        $this->assertCount(5, $seen, 'every one of the five slice positions should belong to a ring');
    }

    #[Test]
    public function displayNumbersCoverOneThroughFiveWithNoRepeats(): void
    {
        $numbers = [];

        for ($position = 0; $position <= 4; $position++) {
            $numbers[] = SliceLayout::displayNumber($position);
        }

        sort($numbers);

        $this->assertSame([1, 2, 3, 4, 5], $numbers);
    }

    #[Test]
    public function displayNumberOneIsTheRingOnePosition(): void
    {
        [$ringOnePosition] = SliceLayout::positionsInRing(1);

        $this->assertSame(1, SliceLayout::displayNumber($ringOnePosition));
    }

    #[Test]
    public function anUnknownPositionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SliceLayout::ringOf(5);
    }
}
