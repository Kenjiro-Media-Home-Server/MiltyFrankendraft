<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\ClaimPlayer;
use App\Draft\Commands\UnclaimPlayer;
use App\Draft\Draft;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Franken\Commands\SetFrankenPlayerClaim;
use App\Franken\FrankenDraft;
use App\Http\ErrorResponse;
use App\Http\HttpResponse;

class HandleClaimOrUnclaimPlayerRequest extends DraftRequestHandler
{
    public function handle(): HttpResponse
    {
        try {
            $draft = $this->loadPersistableDraftByUrlId('draft');

            if ($draft == null) {
                return $this->error('Draft not found', 404);
            }

            $playerId = PlayerId::fromString($this->request->get('player'));
            $unclaim = $this->request->get('unclaim') == 1;

            if ($draft instanceof FrankenDraft) {
                return $this->handleFranken($draft, $playerId, $unclaim);
            }

            if (! $draft instanceof Draft) {
                return $this->error('Unsupported draft type', 500);
            }

            $draft->playerById($playerId);

            if ($unclaim) {
                dispatch(new UnclaimPlayer($draft, $playerId));
                $secret = null;
            } else {
                $secret = dispatch(new ClaimPlayer($draft, $playerId));
            }

            return $this->json([
                'draft' => $draft->toArray(),
                'player' => $playerId->value,
                'success' => true,
                'secret' => $unclaim ? null : $secret,
            ]);

        } catch (DraftRepositoryException $e) {
            return new ErrorResponse('Draft not found', 404);
        }
    }

    /**
     * Franken claims go through the atomic mutation, because two people can be claiming different
     * seats at the same moment.
     */
    private function handleFranken(FrankenDraft $draft, PlayerId $playerId, bool $unclaim): HttpResponse
    {
        if (! $draft->hasPlayer($playerId)) {
            return $this->error('No player in this draft with id ' . $playerId->value, 404);
        }

        $secret = null;

        $updated = app()->repository->mutate(
            $draft->id,
            function (PersistableDraft $fresh) use ($playerId, $unclaim, &$secret): PersistableDraft {
                if (! $fresh instanceof FrankenDraft) {
                    throw new DraftRepositoryException('That draft is not a Franken draft');
                }

                $secret = (new SetFrankenPlayerClaim($fresh, $playerId, ! $unclaim))->handle();

                return $fresh;
            },
        );

        if (! $updated instanceof FrankenDraft) {
            return $this->error('That draft is not a Franken draft', 400);
        }

        // The claimer now holds a passkey, so they may see the bag in front of them straight away.
        $viewer = $unclaim ? null : $playerId;

        return $this->json([
            'draft' => $updated->toArray($viewer, $updated->secrets()->checkAdminSecret($this->request->get('admin'))),
            'player' => $playerId->value,
            'success' => true,
            'secret' => $secret,
        ]);
    }
}
