<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\PlayerId;
use App\Franken\FrankenDraft;
use App\Shared\Command;

/**
 * Claiming a seat is what ties a browser to a player, and it works the same way it does in a Milty
 * draft: the player gets a passkey they can use from another device.
 *
 * Does not save; the caller runs it inside DraftRepository::mutate().
 */
class SetFrankenPlayerClaim implements Command
{
    public function __construct(
        private readonly FrankenDraft $draft,
        private readonly PlayerId $playerId,
        private readonly bool $claim,
    ) {
    }

    /** @return string|null the new passkey when claiming, null when releasing the seat */
    public function handle(): ?string
    {
        $player = $this->draft->playerById($this->playerId);

        if (! $this->claim) {
            $this->draft->updatePlayer($player->unclaim());
            $this->draft->secrets->removeSecretForPlayer($this->playerId);

            return null;
        }

        $this->draft->updatePlayer($player->claim());

        return $this->draft->secrets->generateSecretForPlayer($this->playerId);
    }
}
