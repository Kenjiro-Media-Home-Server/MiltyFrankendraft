<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\UndoLastPick;
use App\Draft\Draft;
use App\Draft\PersistableDraft;
use App\Franken\Commands\UndoLastFrankenPick;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;
use App\Http\JsonResponse;

class HandleUndoRequest extends DraftRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadPersistableDraftByUrlId();

        if ($draft == null) {
            return $this->error('Draft not found', 404);
        }

        $adminSecret = $this->request->get('admin');

        if (! $draft->secrets()->checkAdminSecret($adminSecret)) {
            return $this->error('Only the admin can undo picks', 403);
        }

        if ($draft instanceof FrankenDraft) {
            return $this->undoFranken($draft);
        }

        if (! $draft instanceof Draft) {
            return $this->error('Unsupported draft type', 500);
        }

        dispatch(new UndoLastPick($draft));

        return new JsonResponse([
            'draft' => $draft->toArray(),
            'success' => true,
        ]);
    }

    /**
     * Franken undo goes through the atomic mutation, since players may still be picking from other
     * bags while the admin rewinds this one.
     */
    private function undoFranken(FrankenDraft $draft): HttpResponse
    {
        try {
            $updated = app()->repository->mutate(
                $draft->id,
                static function (PersistableDraft $fresh): PersistableDraft {
                    if (! $fresh instanceof FrankenDraft) {
                        throw new \RuntimeException('That draft is not a Franken draft');
                    }

                    (new UndoLastFrankenPick($fresh))->handle();

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
            'success' => true,
        ]);
    }
}
