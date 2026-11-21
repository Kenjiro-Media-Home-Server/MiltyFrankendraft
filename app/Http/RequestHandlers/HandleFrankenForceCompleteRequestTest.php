<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleFrankenForceCompleteRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleFrankenForceCompleteRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/franken/force-complete');
    }

    #[Test]
    public function itReturnsNotFoundForAnUnknownDraft(): void
    {
        $this->assertResponseNotFound($this->handleRequest(['id' => 'no-such-draft']));
    }

    /** This is an admin rescue: a player must not be able to hand themselves a full faction. */
    #[Test]
    public function itRefusesAPlayersOwnCredentials(): void
    {
        $this->assertForbidden($this->handleRequest($this->playerCredentials()));
    }

    #[Test]
    public function itRefusesWithoutAnyCredentials(): void
    {
        $this->assertForbidden($this->handleRequest([
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
        ]));
    }

    #[Test]
    public function itRejectsAnUnknownPlayer(): void
    {
        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'admin' => $this->testDraft->secrets->adminSecret,
            'player' => 'p_nobody',
        ]);

        $this->assertResponseNotFound($response);
    }

    #[Test]
    public function itRejectsAMissingPlayer(): void
    {
        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'admin' => $this->testDraft->secrets->adminSecret,
        ]);

        $this->assertResponseCode(400, $response);
    }

    #[Test]
    public function theAdminCanFillAHand(): void
    {
        $response = $this->handleRequest($this->adminCredentials());

        $this->assertResponseOk($response);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertNotEmpty($body['granted']);

        $stored = $this->reloadFrankenDraft();
        $this->assertSame(
            $stored->settings->handSize(),
            $stored->playerById($this->testPlayerId)->hand->size(),
        );
    }

    #[Test]
    public function itRefusesWhenTheHandIsAlreadyFull(): void
    {
        $this->handleRequest($this->adminCredentials());

        $response = $this->handleRequest($this->adminCredentials());

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'already full',
            json_decode($response->getBody(), true)['error'],
        );
    }
}
