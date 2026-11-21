<?php

declare(strict_types=1);

namespace App\Testing;

use App\Draft\PlayerId;
use App\Franken\FrankenDraft;
use App\Testing\Factories\FrankenDraftFactory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Sets up a saved Franken draft with one seat already claimed, which is what the write endpoints
 * need in order to be exercised.
 */
trait UsesTestFrankenDraft
{
    protected FrankenDraft $testDraft;

    protected PlayerId $testPlayerId;

    protected string $testPlayerSecret;

    #[Before]
    protected function setupTestFrankenDraft(): void
    {
        $this->testDraft = FrankenDraftFactory::draft(3);

        $player = $this->testDraft->playersInSeatOrder()[0];
        $this->testPlayerId = $player->id;
        $this->testDraft->updatePlayer($player->claim());
        $this->testPlayerSecret = $this->testDraft->secrets->generateSecretForPlayer($player->id);

        app()->repository->save($this->testDraft);
    }

    #[After]
    protected function deleteTestFrankenDraft(): void
    {
        app()->repository->delete($this->testDraft->id);
        FrankenDraftFactory::useRealComponents();
        unset($this->testDraft);
    }

    protected function reloadFrankenDraft(): FrankenDraft
    {
        $draft = app()->repository->load($this->testDraft->id);

        if (! $draft instanceof FrankenDraft) {
            $this->fail('the stored draft came back as something other than a Franken draft');
        }

        return $draft;
    }

    /** Credentials for the claimed seat, as the browser would send them. */
    protected function playerCredentials(): array
    {
        return [
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
            'secret' => $this->testPlayerSecret,
        ];
    }

    protected function adminCredentials(): array
    {
        return [
            'id' => $this->testDraft->id,
            'player' => $this->testPlayerId->value,
            'admin' => $this->testDraft->secrets->adminSecret,
        ];
    }

    /** The first item in the bag in front of the claimed player. */
    protected function firstBagItem(): string
    {
        return $this->testDraft->bagFor($this->testPlayerId)->items()[0]->value();
    }
}
