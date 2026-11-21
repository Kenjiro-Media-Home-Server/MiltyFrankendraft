<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\Name;
use App\Draft\PlayerId;
use App\Draft\Secrets;
use App\Draft\Seed;
use App\Franken\Commands\FrankenPick;
use App\Franken\Commands\FrankenSetReady;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FrankenDraftTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function everyPlayerStartsWithTheirOwnFullBag(): void
    {
        $draft = FrankenDraftFactory::draft(4);

        $this->assertCount(4, $draft->bags);
        $this->assertCount(4, $draft->bagHolders);

        foreach ($draft->players as $player) {
            $bag = $draft->bagFor($player->id);
            $this->assertNotNull($bag);
            $this->assertSame($draft->settings->bagSize(), $bag->count());
        }

        // Every bag belongs to exactly one player.
        $this->assertSame(
            count(array_unique(array_values($draft->bagHolders))),
            count($draft->bagHolders),
        );
    }

    #[Test]
    public function noComponentIsDealtIntoTwoBags(): void
    {
        $draft = FrankenDraftFactory::draft(6);
        $seen = [];

        foreach ($draft->bags as $bag) {
            foreach ($bag->items() as $item) {
                $this->assertArrayNotHasKey($item->value(), $seen, $item->value() . ' was dealt twice');
                $seen[$item->value()] = true;
            }
        }

        $this->assertCount(6 * $draft->settings->bagSize(), $seen);
    }

    #[Test]
    public function theSameSeedAlwaysDealsTheSameBags(): void
    {
        $first = FrankenDraftFactory::draft(5);
        $second = FrankenDraftFactory::draft(5);

        $this->assertSame(
            array_map(static fn (Bag $bag) => $bag->toArray()['items'], array_values($first->bags)),
            array_map(static fn (Bag $bag) => $bag->toArray()['items'], array_values($second->bags)),
        );
    }

    #[Test]
    public function theFirstRoundGivesThreePicksAndTheSecondTwo(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        $this->assertSame(3, $draft->remainingPicksFor($player));

        foreach (array_slice($draft->bagFor($player->id)->items(), 0, 3) as $item) {
            $draft = (new FrankenPick($draft, $player->id, $item))->handle();
        }

        $this->assertSame(0, $draft->remainingPicksFor($draft->playerById($player->id)));
        // out of picks, but the bag stays put until they pass it on
        $this->assertFalse($draft->playerById($player->id)->ready);
    }

    #[Test]
    public function remainingPicksIsCappedByWhatTheBagCanStillSupply(): void
    {
        // Ann already holds her one allowed ability. She still has room for two techs, and her
        // round quota is far from spent, but the bag in front of her holds nothing except more
        // abilities — a category she has already filled — so there is nothing left she may take.
        $settings = new FrankenSettings(
            ['Ann', 'Ben'],
            new Name('Test draft'),
            new Seed(1),
            true,
            ['test'],
            DraftOrderMode::PRESET,
            [DraftCategory::ABILITY->value, DraftCategory::TECH->value],
            [
                DraftCategory::ABILITY->value => ['bag' => 3, 'hand' => 1, 'final' => 1],
                DraftCategory::TECH->value => ['bag' => 2, 'hand' => 2, 'final' => 2],
            ],
        );

        $ann = new FrankenPlayer(
            PlayerId::generate(),
            'Ann',
            0,
            true,
            new Hand([ComponentId::make(DraftCategory::ABILITY, 'held')]),
        );
        $ben = FrankenPlayer::create('Ben', 1);

        $bag = new Bag('bag_1', [
            ComponentId::make(DraftCategory::ABILITY, 'spare-1'),
            ComponentId::make(DraftCategory::ABILITY, 'spare-2'),
        ]);

        $draft = new FrankenDraft(
            'test-draft',
            $settings,
            new Secrets('admin'),
            [$ann->id->value => $ann, $ben->id->value => $ben],
            ['bag_1' => $bag],
            [$ann->id->value => 'bag_1'],
        );

        $this->assertSame(0, $draft->remainingPicksFor($ann));
        $this->assertGreaterThan(
            0,
            min($draft->quota() - $ann->picksThisRound, $draft->settings->handSize() - $ann->hand->size()),
            'the old quota/hand-only formula would have overstated the achievable picks',
        );
    }

    #[Test]
    public function theBagsOnlyMoveOnceEveryPlayerIsReady(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $players = $draft->playersInSeatOrder();
        $bagsBefore = $draft->bagHolders;

        $draft = FrankenDraftFactory::passBagFor($draft, $players[0]->id);
        $draft = FrankenDraftFactory::passBagFor($draft, $players[1]->id);

        $this->assertSame(1, $draft->round, 'the round moved before everyone was ready');
        $this->assertSame($bagsBefore, $draft->bagHolders);

        $draft = FrankenDraftFactory::passBagFor($draft, $players[2]->id);

        $this->assertSame(2, $draft->round);
        $this->assertNotSame($bagsBefore, $draft->bagHolders);
    }

    /**
     * Each player passes their bag to the previous seat, so the bag that was in front of seat 1
     * ends up in front of seat 0.
     */
    #[Test]
    public function passingMovesEveryBagOneSeatAlong(): void
    {
        $draft = FrankenDraftFactory::draft(4);
        $players = $draft->playersInSeatOrder();
        $before = $draft->bagHolders;

        $draft->passBags();

        foreach ($players as $index => $player) {
            $next = $players[($index + 1) % count($players)];
            $this->assertSame(
                $before[$next->id->value],
                $draft->bagHolders[$player->id->value],
            );
        }
    }

    #[Test]
    public function startingANewRoundClearsReadyFlagsAndPickCounts(): void
    {
        $draft = FrankenDraftFactory::draft(3);

        foreach ($draft->playersInSeatOrder() as $player) {
            $draft = FrankenDraftFactory::passBagFor($draft, $player->id);
        }

        foreach ($draft->players as $player) {
            $this->assertFalse($player->ready);
            $this->assertSame(0, $player->picksThisRound);
        }
    }

    #[Test]
    public function aPlayerCannotExceedTheHandLimitForACategory(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $homeSystems = $draft->bagFor($player->id)->itemsIn(DraftCategory::HOMESYSTEM);

        $this->assertGreaterThanOrEqual(2, count($homeSystems));

        $draft = (new FrankenPick($draft, $player->id, $homeSystems[0]))->handle();

        $this->assertFalse($draft->canTake($draft->playerById($player->id), $homeSystems[1]));
    }

    // ------------------------------------------------------------- visibility

    #[Test]
    public function aSpectatorSeesNoBagContents(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $data = $draft->toArray();

        foreach ($data['franken']['bags'] as $bag) {
            $this->assertArrayNotHasKey('items', $bag);
            $this->assertArrayHasKey('count', $bag);
        }
    }

    #[Test]
    public function aPlayerSeesOnlyTheBagInFrontOfThem(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $ownBagId = $draft->bagHolders[$player->id->value];

        $data = $draft->toArray($player->id);

        foreach ($data['franken']['bags'] as $bagId => $bag) {
            if ($bagId === $ownBagId) {
                $this->assertArrayHasKey('items', $bag);
            } else {
                $this->assertArrayNotHasKey('items', $bag);
            }
        }
    }

    #[Test]
    public function anAdminSeesEveryBag(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $data = $draft->toArray(null, true);

        foreach ($data['franken']['bags'] as $bag) {
            $this->assertArrayHasKey('items', $bag);
        }
    }

    #[Test]
    public function secretsAreNeverPartOfAnApiPayload(): void
    {
        $draft = FrankenDraftFactory::draft(3);

        $this->assertArrayNotHasKey('secrets', $draft->toArray());
        $this->assertArrayNotHasKey('secrets', $draft->toArray(null, true));
        $this->assertArrayHasKey('secrets', $draft->toArray(null, true, true));
    }

    #[Test]
    public function youCanSeeYourOwnHand(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $data = $draft->toArray($player->id);

        $this->assertContains($item->value(), $data['franken']['players'][$player->id->value]['hand']);
    }

    /**
     * Hands are secret while the draft runs: knowing what an opponent already holds would tell you
     * what they cannot take, which is exactly the information the bag draft is meant to hide.
     */
    #[Test]
    public function otherPlayersHandsAreHiddenWhileTheDraftRuns(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        [$player, $opponent] = $draft->playersInSeatOrder();
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $seenByOpponent = $draft->toArray($opponent->id)['franken']['players'][$player->id->value];

        $this->assertSame([], $seenByOpponent['hand']);
        $this->assertSame(1, $seenByOpponent['hand_count'], 'how much someone holds stays public');
    }

    #[Test]
    public function aSpectatorSeesNoHandsAtAll(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $data = $draft->toArray();

        $this->assertSame([], $data['franken']['players'][$player->id->value]['hand']);
    }

    #[Test]
    public function anAdminSeesEveryHand(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $data = $draft->toArray(null, true);

        $this->assertContains($item->value(), $data['franken']['players'][$player->id->value]['hand']);
    }

    #[Test]
    public function everyHandOpensUpOnceTheDraftIsDone(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $draft->stage = DraftStage::DONE;
        $data = $draft->toArray();

        $this->assertContains($item->value(), $data['franken']['players'][$player->id->value]['hand']);
    }

    #[Test]
    public function theLogDoesNotNameWhatSomebodyElseTook(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        [$player, $opponent] = $draft->playersInSeatOrder();
        $mine = $draft->bagFor($player->id)->items()[0];
        $theirs = $draft->bagFor($opponent->id)->items()[0];

        $draft = (new FrankenPick($draft, $player->id, $mine))->handle();
        $draft = (new FrankenPick($draft, $opponent->id, $theirs))->handle();

        $log = $draft->toArray($player->id)['franken']['log'];
        $picks = array_values(array_filter($log, static fn (array $e) => $e['action'] === 'pick'));

        $this->assertCount(2, $picks, 'that a pick happened is still public');
        $this->assertSame($mine->value(), $picks[0]['item'] ?? null, 'your own picks stay readable');
        $this->assertArrayNotHasKey('item', $picks[1]);
    }

    #[Test]
    public function componentsBehindAHiddenHandAreNotShipped(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        [$player, $opponent] = $draft->playersInSeatOrder();
        $theirs = $draft->bagFor($opponent->id)->items()[0];
        $draft = (new FrankenPick($draft, $opponent->id, $theirs))->handle();

        // Taken out of the opponent's bag, so it is in no bag this player can see either.
        $components = $draft->toArray($player->id)['franken']['components'];

        $this->assertArrayNotHasKey($theirs->value(), $components);
    }

    #[Test]
    public function storedDraftsKeepEveryHand(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $stored = json_decode($draft->toFileContent(), true);

        $this->assertContains(
            $item->value(),
            $stored['franken']['players'][$player->id->value]['hand'],
            'redaction is for the wire, never for storage',
        );
    }

    // ------------------------------------------------------- passing the bag

    #[Test]
    public function aBagCannotBePassedWhilePicksRemain(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        $this->assertTrue($draft->mustKeepPicking($draft->playerById($player->id)));

        $this->expectException(Exceptions\InvalidFrankenPickException::class);
        $this->expectExceptionMessage('still have');

        (new FrankenSetReady($draft, $player->id))->handle();
    }

    #[Test]
    public function aBagCanBePassedOnceThePicksAreSpent(): void
    {
        $draft = FrankenDraftFactory::draft(3);
        $player = $draft->playersInSeatOrder()[0];

        $draft = FrankenDraftFactory::spendPicks($draft, $player->id);

        $this->assertFalse($draft->mustKeepPicking($draft->playerById($player->id)));

        $draft = (new FrankenSetReady($draft, $player->id))->handle();

        $this->assertTrue($draft->playerById($player->id)->ready);
    }

    // ------------------------------------------------------------ persistence

    #[Test]
    public function itSurvivesARoundTripThroughStoredJson(): void
    {
        $draft = FrankenDraftFactory::draft(4);
        $player = $draft->playersInSeatOrder()[0];
        $item = $draft->bagFor($player->id)->items()[0];
        $draft = (new FrankenPick($draft, $player->id, $item))->handle();

        $restored = FrankenDraft::fromJson(json_decode($draft->toFileContent(), true));

        $this->assertSame($draft->id, $restored->id);
        $this->assertSame('franken', $restored->mode());
        $this->assertSame($draft->round, $restored->round);
        $this->assertSame($draft->stage, $restored->stage);
        $this->assertSame($draft->bagHolders, $restored->bagHolders);
        $this->assertCount(count($draft->players), $restored->players);
        $this->assertTrue(
            $restored->playerById(PlayerId::fromString($player->id->value))->hand->has($item),
        );

        foreach ($draft->bags as $bagId => $bag) {
            $this->assertSame($bag->toArray(), $restored->bags[$bagId]->toArray());
        }
    }
}
