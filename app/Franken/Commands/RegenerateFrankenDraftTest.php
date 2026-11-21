<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Franken\DraftStage;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RegenerateFrankenDraftTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function itDealsDifferentBags(): void
    {
        $draft = FrankenDraftFactory::draft(4);
        $before = $this->bagContents($draft);

        (new RegenerateFrankenDraft($draft))->handle();

        $this->assertNotSame($before, $this->bagContents($draft));
        $this->assertCount(4, $draft->bags);

        foreach ($draft->bags as $bag) {
            $this->assertSame($draft->settings->bagSize(), $bag->count());
        }
    }

    #[Test]
    public function everyPlayerStillHoldsExactlyOneBag(): void
    {
        $draft = FrankenDraftFactory::draft(4);

        (new RegenerateFrankenDraft($draft))->handle();

        $this->assertCount(4, $draft->bagHolders);
        $this->assertSame(4, count(array_unique(array_values($draft->bagHolders))));

        foreach ($draft->players as $player) {
            $this->assertNotNull($draft->bagFor($player->id));
        }
    }

    #[Test]
    public function itKeepsPlayersTheirClaimsAndTheirPasskeys(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $draft->updatePlayer($player->claim());
        $secret = $draft->secrets->generateSecretForPlayer($player->id);
        $names = array_map(static fn ($p) => $p->name, $draft->players);

        (new RegenerateFrankenDraft($draft))->handle();

        $this->assertSame($names, array_map(static fn ($p) => $p->name, $draft->players));
        $this->assertTrue($draft->playerById($player->id)->claimed);
        $this->assertSame($secret, $draft->secrets->secretById($player->id));
    }

    #[Test]
    public function itResetsTheRoundAndTheLog(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $draft->logEntry(['round' => 1, 'action' => 'pass_bags']);
        $draft->round = 4;

        (new RegenerateFrankenDraft($draft))->handle();

        $this->assertSame(1, $draft->round);
        $this->assertSame([], $draft->log);
        $this->assertSame(DraftStage::BAG_DRAFT, $draft->stage);
    }

    #[Test]
    public function itLeavesTheSeatingOrderAloneByDefault(): void
    {
        $draft = FrankenDraftFactory::draft(4);
        $seats = $this->seats($draft);

        (new RegenerateFrankenDraft($draft))->handle();

        $this->assertSame($seats, $this->seats($draft));
    }

    #[Test]
    public function itCanReshuffleTheSeatingOrderToo(): void
    {
        $draft = FrankenDraftFactory::draft(6);

        (new RegenerateFrankenDraft($draft, true))->handle();

        $seats = array_values($this->seats($draft));
        sort($seats);

        // still exactly one player per seat, just possibly in a different arrangement
        $this->assertSame([0, 1, 2, 3, 4, 5], $seats);
    }

    #[Test]
    public function itRefusesOnceSomebodyHasPicked(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $this->assertFalse($draft->canRegenerate());

        $this->expectException(InvalidFrankenPickException::class);
        $this->expectExceptionMessage('already started');

        (new RegenerateFrankenDraft($draft))->handle();
    }

    #[Test]
    public function aFreshDraftCanBeRegenerated(): void
    {
        $this->assertTrue(FrankenDraftFactory::draft(3)->canRegenerate());
    }

    /** @return array<string, array<int, string>> */
    private function bagContents(FrankenDraft $draft): array
    {
        $contents = [];

        foreach ($draft->bags as $id => $bag) {
            $contents[$id] = array_map(static fn ($item) => $item->value(), $bag->items());
        }

        return $contents;
    }

    /** @return array<string, int> */
    private function seats(FrankenDraft $draft): array
    {
        $seats = [];

        foreach ($draft->players as $id => $player) {
            $seats[$id] = $player->seat;
        }

        return $seats;
    }
}
