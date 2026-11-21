<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Draft;
use App\Draft\PlayerId;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;
use App\Http\JsonResponse;

class HandleGetDraftRequest extends DraftRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadPersistableDraftByUrlId('id');

        if ($draft == null) {
            return $this->error('Draft not found', 404);
        }

        // A Franken draft is only partly public: the bag in front of you is yours to see, and
        // everyone else's is just a count. Callers identify themselves with the same credentials
        // they already hold for picking.
        if ($draft instanceof FrankenDraft) {
            return new JsonResponse(
                $draft->toArray($this->viewerFor($draft), $this->isAdminOf($draft)),
            );
        }

        if ($draft instanceof Draft) {
            return new JsonResponse(
                $draft->toArray(),
            );
        }

        return $this->error('Unsupported draft type', 500);
    }

    private function viewerFor(FrankenDraft $draft): ?PlayerId
    {
        $submitted = $this->request->get('player');

        if ($submitted === null || $submitted === '') {
            return null;
        }

        $playerId = PlayerId::fromString((string) $submitted);

        if (! $draft->hasPlayer($playerId)) {
            return null;
        }

        return $draft->secrets->checkPlayerSecret($playerId, $this->request->get('secret'))
            ? $playerId
            : null;
    }

    private function isAdminOf(FrankenDraft $draft): bool
    {
        return $draft->secrets->checkAdminSecret($this->request->get('admin'));
    }
}
