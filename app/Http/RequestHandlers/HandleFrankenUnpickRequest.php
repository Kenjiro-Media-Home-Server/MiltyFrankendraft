<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\Commands\FrankenUnpick;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;

class HandleFrankenUnpickRequest extends FrankenRequestHandler
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

        $item = $this->itemFromRequest();

        if ($item === null) {
            return $this->error('No draftable item given', 400);
        }

        return $this->mutateAndRespond(
            $draft,
            static function (FrankenDraft $fresh) use ($playerId, $item): void {
                (new FrankenUnpick($fresh, $playerId, $item))->handle();
            },
        );
    }
}
