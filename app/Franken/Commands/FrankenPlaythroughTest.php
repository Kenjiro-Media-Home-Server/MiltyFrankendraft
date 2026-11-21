<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\Name;
use App\Draft\Seed;
use App\Franken\ComponentRepository;
use App\Franken\DraftCategory;
use App\Franken\DraftOrderMode;
use App\Franken\DraftStage;
use App\Franken\FrankenDraft;
use App\Franken\FrankenSettings;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Plays whole Franken drafts against the real generated component database.
 *
 * These are the tests that would catch a rules change quietly making the draft unfinishable, so
 * they deliberately use the shipped data rather than a synthetic fixture.
 */
class FrankenPlaythroughTest extends TestCase
{
    /** Enough content for eight players without Thunder's Edge, so no breakthroughs. */
    private const CORE_SOURCES = ['base', 'pok', 'codex1', 'codex2', 'codex3', 'codex4'];

    private const WITH_THUNDERS_EDGE = [
        'base', 'pok', 'codex1', 'codex2', 'codex3', 'codex4', 'thunders_edge',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FrankenDraftFactory::useRealComponents();
    }

    public static function providePlayerCounts(): array
    {
        return [
            '2 players' => [2],
            '4 players' => [4],
            '6 players' => [6],
            '8 players' => [8],
        ];
    }

    #[DataProvider('providePlayerCounts')]
    #[Test]
    public function everyPlayerEndsWithAFullLegalHand(int $playerCount): void
    {
        $draft = $this->play($playerCount, self::CORE_SOURCES);

        $this->assertSame(
            DraftStage::ASSEMBLY,
            $draft->stage,
            'the bag draft did not finish for ' . $playerCount . ' players',
        );

        foreach ($draft->players as $player) {
            $this->assertSame(
                $draft->settings->handSize(),
                $player->hand->size(),
                $player->name . ' finished with an incomplete hand',
            );

            foreach ($draft->settings->categories() as $category) {
                $this->assertSame(
                    $draft->settings->handLimit($category),
                    $player->hand->countIn($category),
                    $player->name . ' has the wrong number of ' . $category->value,
                );
            }
        }
    }

    #[Test]
    public function withoutThundersEdgeThereAreNoBreakthroughs(): void
    {
        $draft = $this->generate(6, self::CORE_SOURCES);

        $this->assertNotContains(DraftCategory::BREAKTHROUGH, $draft->settings->categories());
        $this->assertSame(31, $draft->settings->bagSize());
        $this->assertSame(21, $draft->settings->handSize());
    }

    /**
     * With every category in play the numbers are the ones the community rules quote: 33 items per
     * bag, a 22-item hand, and eleven rounds at three picks then two.
     */
    #[Test]
    public function withThundersEdgeTheDraftMatchesTheStandardFrankenNumbers(): void
    {
        $draft = $this->generate(6, self::WITH_THUNDERS_EDGE);

        $this->assertContains(DraftCategory::BREAKTHROUGH, $draft->settings->categories());
        $this->assertSame(33, $draft->settings->bagSize());
        $this->assertSame(22, $draft->settings->handSize());

        $played = FrankenDraftFactory::playBagDraft($draft);

        $this->assertSame(DraftStage::ASSEMBLY, $played->stage);
        $this->assertSame(11, $played->round);
    }

    #[Test]
    public function everySeatingPositionGoesToExactlyOnePlayer(): void
    {
        $draft = $this->play(6, self::CORE_SOURCES);
        $positions = [];

        foreach ($draft->players as $player) {
            $position = $player->draftedPosition();
            $this->assertNotNull($position, $player->name . ' drafted no seating position');
            $positions[] = $position;
        }

        sort($positions);

        $this->assertSame([1, 2, 3, 4, 5, 6], $positions);
    }

    #[Test]
    public function aDraftedHandHoldsFiveSliceTilesAndOneHomeSystem(): void
    {
        $draft = $this->play(4, self::CORE_SOURCES);

        foreach ($draft->players as $player) {
            $sliceTiles = array_merge(
                $player->hand->itemsIn(DraftCategory::BLUETILE),
                $player->hand->itemsIn(DraftCategory::REDTILE),
            );

            // Three blue plus two red is exactly the five tiles a Milty slice holds, which is what
            // lets the assembly stage reuse the existing slice layout and map renderer.
            $this->assertCount(5, $sliceTiles);
            $this->assertCount(1, $player->hand->itemsIn(DraftCategory::HOMESYSTEM));
        }
    }

    #[Test]
    public function noItemIsEverHeldByTwoPlayers(): void
    {
        $draft = $this->play(6, self::CORE_SOURCES);
        $seen = [];

        foreach ($draft->players as $player) {
            foreach ($player->hand->items() as $item) {
                $this->assertArrayNotHasKey(
                    $item->value(),
                    $seen,
                    $item->value() . ' ended up in two hands',
                );
                $seen[$item->value()] = true;
            }
        }
    }

    #[Test]
    public function everythingDrawnIsARealComponent(): void
    {
        $draft = $this->play(4, self::CORE_SOURCES);

        foreach ($draft->players as $player) {
            foreach ($player->hand->items() as $item) {
                $this->assertNotNull(
                    ComponentRepository::find($item),
                    $item->value() . ' does not resolve to a component',
                );
            }
        }
    }

    #[Test]
    public function aFinishedDraftStillRoundTripsThroughStoredJson(): void
    {
        $draft = $this->play(4, self::CORE_SOURCES);
        $restored = FrankenDraft::fromJson(json_decode($draft->toFileContent(), true));

        $this->assertSame($draft->stage, $restored->stage);
        $this->assertSame($draft->round, $restored->round);

        foreach ($draft->players as $id => $player) {
            $this->assertSame($player->hand->toArray(), $restored->players[$id]->hand->toArray());
        }
    }

    private function generate(int $playerCount, array $sources): FrankenDraft
    {
        $names = [];

        for ($index = 1; $index <= $playerCount; $index++) {
            $names[] = 'Player ' . $index;
        }

        $settings = new FrankenSettings(
            $names,
            new Name('Playthrough'),
            new Seed(20260906),
            true,
            $sources,
            DraftOrderMode::DRAFTED,
        );

        $settings->validate();

        return (new GenerateFrankenDraft($settings))->handle();
    }

    private function play(int $playerCount, array $sources): FrankenDraft
    {
        return FrankenDraftFactory::playBagDraft($this->generate($playerCount, $sources));
    }
}
