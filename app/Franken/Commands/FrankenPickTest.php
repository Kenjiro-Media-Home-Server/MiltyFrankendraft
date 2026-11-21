<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\Bag;
use App\Franken\DraftCategory;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FrankenPickTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function aPickMovesAnItemFromTheBagIntoTheHand(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $bagSizeBefore = $draft->bagFor($player->id)->count();

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $this->assertTrue($draft->playerById($player->id)->hand->has($item));
        $this->assertFalse($draft->bagFor($player->id)->contains($item));
        $this->assertSame($bagSizeBefore - 1, $draft->bagFor($player->id)->count());
        $this->assertSame(1, $draft->playerById($player->id)->picksThisRound);
    }

    #[Test]
    public function itRecordsThePickInTheLog(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $this->assertSame([
            'round' => 1,
            'player' => $player->id->value,
            'action' => 'pick',
            'item' => $item->value(),
        ], $draft->log[0]);
    }

    #[Test]
    public function itRefusesAnItemThatIsNotInThePlayersBag(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $players = $draft->playersInSeatOrder();
        $someoneElsesItem = $draft->bagFor($players[1]->id)->items()[0];

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('not in your bag');

        (new FrankenPick($draft, $players[0]->id, $someoneElsesItem))->handle();
    }

    #[Test]
    public function itRefusesAThirdItemOfASingleItemCategory(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $flagships = $draft->bagFor($player->id)->itemsIn(DraftCategory::FLAGSHIP);

        $draft = (new FrankenPick($draft, $player->id, $flagships[0]))->handle();

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('already hold 1 flagship');

        (new FrankenPick($draft, $player->id, $flagships[1]))->handle();
    }

    #[Test]
    public function itRefusesAFourthPickInTheFirstRound(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        // Take three items in three different single-item categories so the quota, not a
        // category limit, is what stops the fourth pick.
        $categories = [DraftCategory::FLAGSHIP, DraftCategory::MECH, DraftCategory::AGENT];

        foreach ($categories as $category) {
            $item = $draft->bagFor($player->id)->itemsIn($category)[0];
            $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        }

        $extra = $draft->bagFor($player->id)->itemsIn(DraftCategory::COMMANDER)[0];

        $this->expectException(InvalidFrankenPickException::class);

        (new FrankenPick($draft, $player->id, $extra))->handle();
    }

    #[Test]
    public function itRefusesAPickSubmittedForARoundThatIsOver(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('bags have moved on');

        (new FrankenPick($draft, $player->id, $item, 99))->handle();
    }

    #[Test]
    public function itRefusesPicksOnceTheBagDraftIsOver(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft->stage = DraftStage::ASSEMBLY;

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('bag draft is over');

        (new FrankenPick($draft, $player->id, $item))->handle();
    }

    /**
     * Passing is deliberate: running out of picks leaves the bag in front of the player so they can
     * still read what is in it.
     */
    #[Test]
    public function spendingTheLastPickDoesNotPassTheBagOn(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        foreach (array_slice($draft->bagFor($player->id)->items(), 0, 3) as $item) {
            $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        }

        $this->assertSame(0, $draft->remainingPicksFor($draft->playerById($player->id)));
        $this->assertFalse(
            $draft->playerById($player->id)->ready,
            'the bag should stay put until the player passes it on',
        );
    }

    /** The one exception: a bag with nothing takeable in it is passed on without being asked. */
    #[Test]
    public function aBagWithNothingLeftToTakeIsPassedOnAutomatically(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $bag = $draft->bagFor($player->id);
        $onlyItem = $bag->itemsIn(DraftCategory::FLAGSHIP)[0];

        // leave a single flagship in the bag; taking it fills that category and empties the bag
        $draft->replaceBag(new Bag($bag->id, [$onlyItem]));

        $draft = (new FrankenPick($draft, $player->id, $onlyItem))->handle();

        $this->assertTrue($draft->playerById($player->id)->ready);
    }

    // ------------------------------------------------------------------ unpick

    #[Test]
    public function aPlayerCanPutBackSomethingTheyJustTook(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        $draft = (new FrankenUnpick($draft, $player->id, $item))->handle();

        $this->assertFalse($draft->playerById($player->id)->hand->has($item));
        $this->assertTrue($draft->bagFor($player->id)->contains($item));
        $this->assertSame(0, $draft->playerById($player->id)->picksThisRound);
        $this->assertSame([], $draft->log);
    }

    #[Test]
    public function aPlayerCannotPutBackSomethingAfterPassingTheirBagOn(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        $draft = FrankenDraftFactory::passBagFor($draft, $player->id);

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('already passed');

        (new FrankenUnpick($draft, $player->id, $item))->handle();
    }

    #[Test]
    public function aPlayerCannotPutBackSomethingTheyDidNotTakeThisRound(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $this->expectException(InvalidFrankenPickException::class);

        (new FrankenUnpick($draft, $player->id, $item))->handle();
    }
}
