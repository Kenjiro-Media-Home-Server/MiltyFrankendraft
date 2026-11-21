<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\Bag;
use App\Franken\DraftCategory;
use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ForceCompleteHandTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function itFillsEveryGapInTheHand(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        $granted = (new ForceCompleteHand($draft, $player->id))->handle();

        $filled = $draft->playerById($player->id);

        $this->assertSame($draft->settings->handSize(), $filled->hand->size());
        $this->assertCount($draft->settings->handSize(), $granted);

        foreach ($draft->settings->categories() as $category) {
            $this->assertSame(
                $draft->settings->handLimit($category),
                $filled->hand->countIn($category),
                $category->value . ' was not filled',
            );
        }
    }

    #[Test]
    public function whatItGrantsIsTakenOutOfCirculation(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $itemsBefore = $this->itemsInCirculation($draft);

        $granted = (new ForceCompleteHand($draft, $player->id))->handle();

        $itemsAfter = $this->itemsInCirculation($draft);

        $this->assertSame(count($itemsBefore) - count($granted), count($itemsAfter));

        foreach ($granted as $item) {
            $this->assertNotContains($item, $itemsAfter, $item . ' is still in a bag');
        }
    }

    #[Test]
    public function itFinishesTheBagDraftWhenItWasTheLastGap(): void
    {
        $draft = FrankenDraftFactory::draft(3);

        foreach ($draft->playersInSeatOrder() as $seated) {
            (new ForceCompleteHand($draft, $seated->id))->handle();
        }

        $this->assertSame(DraftStage::ASSEMBLY, $draft->stage);
    }

    #[Test]
    public function itRecordsEachGrantInTheLog(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        $granted = (new ForceCompleteHand($draft, $player->id))->handle();

        $logged = array_values(array_filter(
            $draft->log,
            static fn (array $entry) => ($entry['action'] ?? '') === 'force_complete',
        ));

        $this->assertCount(count($granted), $logged);
        $this->assertSame($player->id->value, $logged[0]['player']);
    }

    #[Test]
    public function itRefusesWhenTheHandIsAlreadyFull(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        (new ForceCompleteHand($draft, $player->id))->handle();

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('already full');

        (new ForceCompleteHand($draft, $player->id))->handle();
    }

    #[Test]
    public function itRefusesOnceTheBagDraftIsOver(): void
    {
        $draft = FrankenDraftFactory::playBagDraft(FrankenDraftFactory::draft(3));

        $this->expectException(InvalidFrankenPickException::class);

        (new ForceCompleteHand($draft, $draft->playersInSeatOrder()[0]->id))->handle();
    }

    /** The rescue is best-effort: an empty circulation leaves the hand as it is. */
    #[Test]
    public function itSaysSoWhenNothingIsLeftToHandOut(): void
    {
        $draft = FrankenDraftFactory::draft(3);

        foreach ($draft->bags as $bag) {
            $draft->replaceBag(new Bag($bag->id, []));
        }

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('Nothing left in circulation');

        (new ForceCompleteHand($draft, $draft->playersInSeatOrder()[0]->id))->handle();
    }

    #[Test]
    public function itFillsOnlyWhatIsMissing(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $flagship = $draft->bagFor($player->id)->itemsIn(DraftCategory::FLAGSHIP)[0];

        $draft = (new FrankenPick($draft, $player->id, $flagship))->handle();

        (new ForceCompleteHand($draft, $player->id))->handle();

        $filled = $draft->playerById($player->id);

        // the flagship they chose themselves is still the one they hold
        $this->assertTrue($filled->hand->has($flagship));
        $this->assertSame(1, $filled->hand->countIn(DraftCategory::FLAGSHIP));
    }

    /** @return array<int, string> */
    private function itemsInCirculation(FrankenDraft $draft): array
    {
        $items = [];

        foreach ($draft->bags as $bag) {
            foreach ($bag->items() as $item) {
                $items[] = $item->value();
            }
        }

        return $items;
    }
}
