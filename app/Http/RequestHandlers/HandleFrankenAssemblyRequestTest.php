<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\DraftCategory;
use App\Franken\DraftStage;
use App\Franken\FrankenDraft;
use App\Franken\SliceAssemblyMode;
use App\Franken\SliceDraftOrder;
use App\Franken\SliceLayout;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleFrankenAssemblyRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleFrankenAssemblyRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/franken/assembly');
    }

    #[Test]
    public function itReturnsNotFoundForAnUnknownDraft(): void
    {
        $this->assertResponseNotFound($this->handleRequest(['id' => 'no-such-draft']));
    }

    #[Test]
    public function itRefusesACallerWithoutCredentials(): void
    {
        $this->assertForbidden($this->handleRequest([
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
        ]));
    }

    #[Test]
    public function itRefusesWhileTheBagDraftIsStillRunning(): void
    {
        $response = $this->handleRequest($this->playerCredentials());

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'not at the faction-building stage',
            json_decode($response->getBody(), true)['error'],
        );
    }

    #[Test]
    public function aPlayerCanConfirmAFinishedFaction(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => $tiles,
            'confirm' => 'on',
        ]);

        $this->assertResponseOk($response);

        $stored = $this->reloadFrankenDraft();
        $assembly = $stored->playerById($this->testPlayerId)->assembly;
        $this->assertTrue($assembly->confirmed);
        $this->assertSame($tiles, $assembly->slice);
    }

    #[Test]
    public function itRefusesToConfirmAnIncompleteSlice(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => array_slice($tiles, 0, 3),
            'confirm' => 'on',
        ]);

        $this->assertResponseCode(400, $response);
    }

    #[Test]
    public function itRefusesATileThePlayerNeverDrafted(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);
        $tiles[0] = 'not-a-drafted-tile';

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => $tiles,
            'confirm' => 'on',
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'did not draft',
            json_decode($response->getBody(), true)['error'],
        );
    }

    #[Test]
    public function itRefusesATileInTheWrongRingUnderTheDefaultTileDraftMode(): void
    {
        $draft = $this->playToAssembly();
        $this->assertSame(SliceAssemblyMode::DRAFT, $draft->settings->sliceAssemblyMode);

        $tiles = $this->sliceTilesFor($draft);
        // Swaps the ring-1 tile with one of its ring-2 neighbours, which the free layout would
        // allow but the tile-draft variant should not.
        [$ringOnePosition] = SliceLayout::positionsInRing(1);
        [$ringTwoPosition] = SliceLayout::positionsInRing(2);
        [$tiles[$ringOnePosition], $tiles[$ringTwoPosition]] = [$tiles[$ringTwoPosition], $tiles[$ringOnePosition]];

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => $tiles,
            'confirm' => 'on',
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'drawn for a different ring',
            json_decode($response->getBody(), true)['error'],
        );
    }

    #[Test]
    public function aRingCorrectSliceIsAcceptedUnderTheDefaultTileDraftMode(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => $tiles,
            'confirm' => 'on',
        ]);

        $this->assertResponseOk($response);
    }

    #[Test]
    public function itRefusesTheSameTileTwice(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);
        $tiles[1] = $tiles[0];

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => $tiles,
            'confirm' => 'on',
        ]);

        $this->assertResponseCode(400, $response);
    }

    #[Test]
    public function progressCanBeSavedWithoutConfirming(): void
    {
        $draft = $this->playToAssembly();
        $tiles = $this->sliceTilesFor($draft);

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'slice' => array_slice($tiles, 0, 5),
            'confirm' => 'off',
        ]);

        $this->assertResponseOk($response);
        $this->assertFalse($this->reloadFrankenDraft()->playerById($this->testPlayerId)->assembly->confirmed);
    }

    /** Runs the bag draft out so the assembly endpoint has something to work with. */
    private function playToAssembly(): FrankenDraft
    {
        $draft = FrankenDraftFactory::playBagDraft($this->reloadFrankenDraft());

        $this->assertSame(DraftStage::ASSEMBLY, $draft->stage);

        app()->repository->save($draft);

        return $draft;
    }

    /**
     * A slice arrangement built the way the default "tile draft" assembly mode requires: each tile
     * sits in a slot belonging to the ring it was drawn for, not just any of the five positions.
     *
     * @return array<int, string>
     */
    private function sliceTilesFor(FrankenDraft $draft): array
    {
        $player = $draft->playerById($this->testPlayerId);

        $sliceTiles = array_values(array_filter(
            $player->hand->items(),
            static fn ($item) => $item->category === DraftCategory::BLUETILE
                || $item->category === DraftCategory::REDTILE,
        ));

        $rings = SliceDraftOrder::ringsFor($draft->settings->seed, $this->testPlayerId, $sliceTiles);

        $byRing = [1 => [], 2 => [], 3 => []];

        foreach ($sliceTiles as $item) {
            $byRing[$rings[$item->itemId]][] = $item->itemId;
        }

        $slice = array_fill(0, 5, '');

        foreach ([1, 2, 3] as $ring) {
            foreach (SliceLayout::positionsInRing($ring) as $index => $position) {
                $slice[$position] = $byRing[$ring][$index];
            }
        }

        return $slice;
    }
}
