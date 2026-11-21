<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\ComponentId;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleFrankenUnpickRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleFrankenUnpickRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/franken/unpick');
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
            'item' => $this->firstBagItem(),
        ]));
    }

    #[Test]
    public function aPlayerCanPutBackSomethingTheyJustTook(): void
    {
        $item = $this->firstBagItem();

        (new HandleFrankenPickRequest(
            new \App\Http\HttpRequest([...$this->playerCredentials(), 'item' => $item], [], []),
        ))->handle();

        $response = $this->handleRequest([...$this->playerCredentials(), 'item' => $item]);

        $this->assertResponseOk($response);

        $stored = $this->reloadFrankenDraft();
        $this->assertSame(0, $stored->playerById($this->testPlayerId)->hand->size());
        $this->assertTrue(
            $stored->bagFor($this->testPlayerId)->contains(ComponentId::fromString($item)),
        );
    }

    #[Test]
    public function itRefusesToPutBackSomethingNeverTaken(): void
    {
        $response = $this->handleRequest([...$this->playerCredentials(), 'item' => $this->firstBagItem()]);

        $this->assertResponseCode(400, $response);
    }
}
