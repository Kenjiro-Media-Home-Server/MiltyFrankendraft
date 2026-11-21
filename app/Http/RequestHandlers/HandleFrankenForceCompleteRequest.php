<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Franken\Commands\ForceCompleteHand;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;
use App\Http\JsonResponse;

/**
 * Admin only: fills the gaps in a player's hand from whatever is left in circulation, for the rare
 * case where nothing they can legally take is still within reach.
 */
class HandleFrankenForceCompleteRequest extends FrankenRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadFrankenDraftByUrlId();

        if ($draft === null) {
            return $this->notAFrankenDraft();
        }

        if (! $this->isAdmin($draft)) {
            return $this->error('Only the admin can complete a hand', 403);
        }

        $submitted = $this->request->get('player');

        if ($submitted === null || $submitted === '') {
            return $this->error('No player given', 400);
        }

        $playerId = PlayerId::fromString((string) $submitted);

        if (! $draft->hasPlayer($playerId)) {
            return $this->error('No player in this draft with id ' . $playerId->value, 404);
        }

        $granted = [];

        try {
            $updated = app()->repository->mutate(
                $draft->id,
                static function (PersistableDraft $fresh) use ($playerId, &$granted): PersistableDraft {
                    if (! $fresh instanceof FrankenDraft) {
                        throw new \RuntimeException('That draft is not a Franken draft');
                    }

                    $granted = (new ForceCompleteHand($fresh, $playerId))->handle();

                    return $fresh;
                },
            );
        } catch (InvalidFrankenPickException $e) {
            return $this->error($e->getMessage(), 400);
        }

        if (! $updated instanceof FrankenDraft) {
            return $this->error('That draft is not a Franken draft', 400);
        }

        return new JsonResponse([
            'draft' => $updated->toArray(null, true),
            'granted' => $granted,
            'success' => true,
        ]);
    }
}
