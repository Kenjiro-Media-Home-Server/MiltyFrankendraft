<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Franken\ComponentId;
use App\Franken\Exceptions\InvalidAssemblyException;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Http\ErrorResponse;
use App\Http\HttpResponse;
use App\Http\JsonResponse;

/**
 * Shared plumbing for the Franken write endpoints: identify the caller, check they are allowed to
 * act for the player they claim to be, and run the change inside an atomic mutation so that
 * simultaneous picks from different players cannot overwrite one another.
 */
abstract class FrankenRequestHandler extends DraftRequestHandler
{
    protected function isAdmin(FrankenDraft $draft): bool
    {
        return $draft->secrets->checkAdminSecret($this->request->get('admin'));
    }

    /**
     * The player this request is acting for. An admin may act on any player's behalf; a player may
     * only act for themselves, proven by their own secret.
     */
    protected function actingPlayer(FrankenDraft $draft): ?PlayerId
    {
        $submitted = $this->request->get('player');

        if ($submitted === null || $submitted === '') {
            return null;
        }

        $playerId = PlayerId::fromString((string) $submitted);

        if (! $draft->hasPlayer($playerId)) {
            return null;
        }

        if ($this->isAdmin($draft)) {
            return $playerId;
        }

        if ($draft->secrets->checkPlayerSecret($playerId, $this->request->get('secret'))) {
            return $playerId;
        }

        return null;
    }

    protected function itemFromRequest(): ?ComponentId
    {
        $item = $this->request->get('item');

        return $item === null ? null : ComponentId::tryFromString((string) $item);
    }

    protected function expectedRound(): ?int
    {
        $round = $this->request->get('round');

        return $round === null || $round === '' ? null : (int) $round;
    }

    /**
     * Runs a change against the stored draft under a lock, then answers with the draft as this
     * caller is allowed to see it.
     *
     * @param callable(FrankenDraft): void $change
     */
    protected function mutateAndRespond(FrankenDraft $draft, callable $change): HttpResponse
    {
        $viewer = $this->actingPlayer($draft);
        $isAdmin = $this->isAdmin($draft);

        try {
            $updated = app()->repository->mutate(
                $draft->id,
                static function (PersistableDraft $fresh) use ($change): PersistableDraft {
                    // The draft was loaded as a Franken draft a moment ago; re-reading it under
                    // the lock cannot change its mode.
                    if (! $fresh instanceof FrankenDraft) {
                        throw new \RuntimeException('That draft is not a Franken draft');
                    }

                    $change($fresh);

                    return $fresh;
                },
            );
        } catch (\Exception $exception) {
            return $this->errorFor($exception);
        }

        if (! $updated instanceof FrankenDraft) {
            return $this->error('That draft is not a Franken draft', 400);
        }

        return new JsonResponse([
            'draft' => $updated->toArray($viewer, $isAdmin),
            'success' => true,
        ]);
    }

    /**
     * Rules violations are the caller's problem (400); anything else is ours (500). The domain
     * exceptions already carry messages written for the player.
     */
    protected function errorFor(\Exception $exception): ErrorResponse
    {
        $isPlayerError = $exception instanceof InvalidFrankenPickException
            || $exception instanceof InvalidAssemblyException;

        return $this->error($exception->getMessage(), $isPlayerError ? 400 : 500);
    }

    protected function notAFrankenDraft(): ErrorResponse
    {
        return $this->error('Draft not found', 404);
    }

    protected function notAllowed(): ErrorResponse
    {
        return $this->error('You are not allowed to do this.', 403);
    }
}
