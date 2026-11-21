<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\Commands\FrankenPick;
use App\Franken\FrankenDraft;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestFrankenDraft;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Franken branch of HandleRegenerateDraftRequest. It lives in its own class because the Milty
 * test for the same handler sets up a Milty draft, and the two setup traits cannot coexist.
 */
class HandleRegenerateFrankenDraftRequestTest extends RequestHandlerTestCase
{
    use UsesTestFrankenDraft;

    protected string $requestHandlerClass = HandleRegenerateDraftRequest::class;

    #[Test]
    public function itRefusesWithoutTheAdminPasskey(): void
    {
        $this->assertForbidden($this->handleRequest(['id' => $this->testDraft->id]));
    }

    #[Test]
    public function theAdminCanRedealTheBags(): void
    {
        $before = $this->bagContents($this->reloadFrankenDraft());

        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'admin' => $this->testDraft->secrets->adminSecret,
        ]);

        $this->assertResponseOk($response);
        $this->assertNotSame($before, $this->bagContents($this->reloadFrankenDraft()));
    }

    #[Test]
    public function redealingKeepsTheClaimedSeat(): void
    {
        $this->handleRequest([
            'id' => $this->testDraft->id,
            'admin' => $this->testDraft->secrets->adminSecret,
        ]);

        $stored = $this->reloadFrankenDraft();

        $this->assertTrue($stored->playerById($this->testPlayerId)->claimed);
        $this->assertSame(
            $this->testPlayerSecret,
            $stored->secrets->secretById($this->testPlayerId),
        );
    }

    #[Test]
    public function itRefusesOnceSomebodyHasPicked(): void
    {
        $draft = $this->reloadFrankenDraft();
        $item = $draft->bagFor($this->testPlayerId)->items()[0];
        app()->repository->save((new FrankenPick($draft, $this->testPlayerId, $item))->handle());

        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'admin' => $this->testDraft->secrets->adminSecret,
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString(
            'already started',
            json_decode($response->getBody(), true)['error'],
        );
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
}
