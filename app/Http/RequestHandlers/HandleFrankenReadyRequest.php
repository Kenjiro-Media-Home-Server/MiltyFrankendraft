<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\Commands\FrankenSetReady;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;

/**
 * Marks a player done with the bag in front of them. This is what lets a player pass early, and
 * readying the last player is what moves the bags on.
 */
class HandleFrankenReadyRequest extends FrankenRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadFrankenDraftByUrlId();

        if ($draft === null) {
            return $this->notAFrankenDraft();
        }

        $playerId = $this->actingPlayer($draft);

        if ($playerId === null) {
            return $this->notAllowed();
        }

        $ready = $this->request->get('ready', 'on') !== 'off';
        // Same guard the pick endpoint uses: a click made against a stale page must not act on a
        // round the player never saw.
        $round = $this->expectedRound();

        return $this->mutateAndRespond(
            $draft,
            static function (FrankenDraft $fresh) use ($playerId, $ready, $round): void {
                (new FrankenSetReady($fresh, $playerId, $ready, $round))->handle();
            },
        );
    }
}
