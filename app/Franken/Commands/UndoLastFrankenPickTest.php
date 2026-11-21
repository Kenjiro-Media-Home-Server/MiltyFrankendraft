<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\ComponentId;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UndoLastFrankenPickTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function itPutsTheLastPickBackIntoTheBagItCameFrom(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        $draft = (new UndoLastFrankenPick($draft))->handle();

        $this->assertFalse($draft->playerById($player->id)->hand->has($item));
        $this->assertTrue($draft->bagFor($player->id)->contains($item));
    }

    #[Test]
    public function itClearsTheReadyFlagSoThePlayerCanPickAgain(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        foreach (array_slice($draft->bagFor($player->id)->items(), 0, 3) as $item) {
            $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        }

        $draft = (new FrankenSetReady($draft, $player->id))->handle();
        $this->assertTrue($draft->playerById($player->id)->ready);

        $draft = (new UndoLastFrankenPick($draft))->handle();

        $this->assertFalse($draft->playerById($player->id)->ready);
        $this->assertSame(1, $draft->remainingPicksFor($draft->playerById($player->id)));
    }

    #[Test]
    public function itRecordsTheUndoInTheLog(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        $draft = (new UndoLastFrankenPick($draft))->handle();

        $actions = array_column($draft->log, 'action');
        $this->assertNotContains('pick', $actions);
        $this->assertContains('undo', $actions);
    }

    /**
     * The interesting case: the bags have moved on, so the item has to go back into the bag that
     * was in front of that player at the time, not the one they are holding now.
     */
    #[Test]
    public function itFindsTheRightBagAfterTheBagsHaveMovedOn(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $players = $draft->playersInSeatOrder();
        $player = $players[0];

        $bagIdAtPickTime = $draft->bagHolders[$player->id->value];

        // The first seat picks last, so the pick that gets undone is theirs. A bag may not be
        // handed on while its holder can still draft from it, so everybody spends their picks.
        $draft = FrankenDraftFactory::spendPicks($draft, $players[1]->id);
        $draft = FrankenDraftFactory::spendPicks($draft, $players[2]->id);
        $draft = FrankenDraftFactory::spendPicks($draft, $player->id);

        $picks = array_values(array_filter(
            $draft->log,
            static fn (array $entry) => ($entry['action'] ?? '') === 'pick',
        ));
        $item = ComponentId::tryFromString((string) end($picks)['item']);

        // everyone passes, so the bags rotate
        foreach ($players as $seated) {
            $draft = (new FrankenSetReady($draft, $seated->id))->handle();
        }

        $this->assertSame(2, $draft->round);
        $this->assertNotSame($bagIdAtPickTime, $draft->bagHolders[$player->id->value]);

        $draft = (new UndoLastFrankenPick($draft))->handle();

        $this->assertFalse($draft->playerById($player->id)->hand->has($item));
        $this->assertTrue(
            $draft->bags[$bagIdAtPickTime]->contains($item),
            'the item went back into the wrong bag',
        );
    }

    #[Test]
    public function itRefusesWhenNothingHasBeenPicked(): void
    {
        $draft = FrankenDraftFactory::draft(3);

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('nothing to undo');

        (new UndoLastFrankenPick($draft))->handle();
    }

    #[Test]
    public function itRefusesOnceTheBagDraftIsOver(): void
    {
        $draft = FrankenDraftFactory::playBagDraft(FrankenDraftFactory::draft(3));

        $this->assertSame(DraftStage::ASSEMBLY, $draft->stage);

        $this->expectException(InvalidFrankenPickException::class);

        (new UndoLastFrankenPick($draft))->handle();
    }
}
