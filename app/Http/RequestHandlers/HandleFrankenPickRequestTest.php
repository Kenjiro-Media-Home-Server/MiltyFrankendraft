<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleFrankenPickRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleFrankenPickRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/franken/pick');
    }

    #[Test]
    public function itReturnsNotFoundForAnUnknownDraft(): void
    {
        $response = $this->handleRequest(['id' => 'no-such-draft']);

        $this->assertResponseNotFound($response);
        $this->assertResponseJson($response);
    }

    #[Test]
    public function itRefusesACallerWithoutCredentials(): void
    {
        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
            'item' => $this->firstBagItem(),
        ]);

        $this->assertForbidden($response);
    }

    #[Test]
    public function itRefusesTheWrongSecret(): void
    {
        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
            'secret' => 'not-my-passkey',
            'item' => $this->firstBagItem(),
        ]);

        $this->assertForbidden($response);
    }

    #[Test]
    public function aPlayerCanTakeSomethingFromTheirOwnBag(): void
    {
        $item = $this->firstBagItem();

        $response = $this->handleRequest([...$this->playerCredentials(), 'item' => $item, 'round' => 1]);

        $this->assertResponseOk($response);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertContains(
            $item,
            $body['draft']['franken']['players'][$this->testPlayerId->value]['hand'],
        );

        // and it was actually persisted, not just echoed back
        $stored = $this->reloadFrankenDraft();
        $this->assertSame(1, $stored->playerById($this->testPlayerId)->hand->size());
    }

    #[Test]
    public function anAdminCanPickOnAPlayersBehalf(): void
    {
        $response = $this->handleRequest([...$this->adminCredentials(), 'item' => $this->firstBagItem()]);

        $this->assertResponseOk($response);
    }

    #[Test]
    public function itRejectsAPickForARoundThatHasMovedOn(): void
    {
        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'item' => $this->firstBagItem(),
            'round' => 99,
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString('round', json_decode($response->getBody(), true)['error']);
    }

    #[Test]
    public function itRejectsAnItemThatIsNotADraftableId(): void
    {
        $response = $this->handleRequest([...$this->playerCredentials(), 'item' => 'PLOT:assail']);

        $this->assertResponseCode(400, $response);
    }

    #[Test]
    public function itRejectsAMissingItem(): void
    {
        $response = $this->handleRequest($this->playerCredentials());

        $this->assertResponseCode(400, $response);
    }

    /** A player must not be able to reach into the bag in front of somebody else. */
    #[Test]
    public function itRejectsAnItemFromAnotherPlayersBag(): void
    {
        $other = $this->testDraft->playersInSeatOrder()[1];
        $item = $this->testDraft->bagFor($other->id)->items()[0]->value();

        $response = $this->handleRequest([...$this->playerCredentials(), 'item' => $item]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'not in your bag',
            json_decode($response->getBody(), true)['error'],
        );
    }

    #[Test]
    public function theResponseOnlyShowsTheCallersOwnBag(): void
    {
        $response = $this->handleRequest([
            ...$this->playerCredentials(),
            'item' => $this->firstBagItem(),
        ]);

        $body = json_decode($response->getBody(), true);
        $ownBagId = $body['draft']['franken']['bag_holders'][$this->testPlayerId->value];

        foreach ($body['draft']['franken']['bags'] as $bagId => $bag) {
            if ($bagId === $ownBagId) {
                $this->assertArrayHasKey('items', $bag);
            } else {
                $this->assertArrayNotHasKey('items', $bag);
            }
        }

        $this->assertArrayNotHasKey('secrets', $body['draft']);
    }
}
