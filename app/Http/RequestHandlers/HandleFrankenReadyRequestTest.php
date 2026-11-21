<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleFrankenReadyRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleFrankenReadyRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/franken/ready');
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
    public function aPlayerMayNotPassABagTheyCanStillDraftFrom(): void
    {
        $response = $this->handleRequest([...$this->playerCredentials(), 'ready' => 'on']);

        $this->assertResponseCode(400, $response);
        $this->assertFalse($this->reloadFrankenDraft()->playerById($this->testPlayerId)->ready);
    }

    #[Test]
    public function aPlayerCanPassOnceTheirPicksAreSpent(): void
    {
        $this->spendMyPicks();

        $response = $this->handleRequest([...$this->playerCredentials(), 'ready' => 'on']);

        $this->assertResponseOk($response);
        $this->assertTrue($this->reloadFrankenDraft()->playerById($this->testPlayerId)->ready);
    }

    #[Test]
    public function aPlayerCanTakeThatBack(): void
    {
        $this->spendMyPicks();
        $this->handleRequest([...$this->playerCredentials(), 'ready' => 'on']);
        $this->assertTrue($this->reloadFrankenDraft()->playerById($this->testPlayerId)->ready);

        $response = $this->handleRequest([...$this->playerCredentials(), 'ready' => 'off']);

        $this->assertResponseOk($response);
        $this->assertFalse($this->reloadFrankenDraft()->playerById($this->testPlayerId)->ready);
    }

    /**
     * A click made on a page that has not caught up must not land in a round the player never saw:
     * readying up in a fresh round can trip the barrier and move the bags for everybody.
     */
    #[Test]
    public function aStaleRoundIsRefused(): void
    {
        $this->spendMyPicks();

        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'ready' => 'on',
            'round' => 99,
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertFalse($this->reloadFrankenDraft()->playerById($this->testPlayerId)->ready);
    }

    /** Takes everything the claimed seat may take, which is what the new rule requires first. */
    private function spendMyPicks(): void
    {
        $draft = FrankenDraftFactory::spendPicks($this->reloadFrankenDraft(), $this->testPlayerId);
        app()->repository->save($draft);
    }

    /**
     * The barrier is the whole point of the mode: one player passing must not move the bags while
     * the others are still picking.
     */
    #[Test]
    public function oneReadyPlayerDoesNotMoveTheBags(): void
    {
        $before = $this->reloadFrankenDraft();

        $this->handleRequest([...$this->playerCredentials(), 'ready' => 'on']);

        $after = $this->reloadFrankenDraft();
        $this->assertSame($before->round, $after->round);
        $this->assertSame($before->bagHolders, $after->bagHolders);
    }
}
