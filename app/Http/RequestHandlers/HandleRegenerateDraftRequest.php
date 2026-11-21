<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\RegenerateDraft;
use App\Draft\Draft;
use App\Draft\PersistableDraft;
use App\Franken\Commands\RegenerateFrankenDraft;
use App\Franken\Exceptions\InvalidFrankenPickException;
use App\Franken\FrankenDraft;
use App\Http\HttpResponse;

class HandleRegenerateDraftRequest extends DraftRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadPersistableDraftByUrlId();

        if ($draft == null) {
            return $this->error('Draft not found', 404);
        }

        $adminSecret = $this->request->get('admin');

        if (! $draft->secrets()->checkAdminSecret($adminSecret)) {
            return $this->error('Only the admin can regenerate', 403);
        }

        if ($draft instanceof FrankenDraft) {
            return $this->regenerateFranken($draft);
        }

        if (! $draft instanceof Draft) {
            return $this->error('Unsupported draft type', 500);
        }

        dispatch(new RegenerateDraft(
            $draft,
            $this->request->get('slices', false) === 'true',
            $this->request->get('factions', false) === 'true',
            $this->request->get('order', false) === 'true',
        ));

        return $this->json([
            'draft' => $draft->toArray(),
        ]);
    }

    /** A Franken draft has one thing to re-deal — the bags — plus an optional reshuffle of seats. */
    private function regenerateFranken(FrankenDraft $draft): HttpResponse
    {
        $regenerateOrder = $this->request->get('order', false) === 'true';

        try {
            $updated = app()->repository->mutate(
                $draft->id,
                static function (PersistableDraft $fresh) use ($regenerateOrder): PersistableDraft {
                    if (! $fresh instanceof FrankenDraft) {
                        throw new \RuntimeException('That draft is not a Franken draft');
                    }

                    (new RegenerateFrankenDraft($fresh, $regenerateOrder))->handle();

                    return $fresh;
                },
            );
        } catch (InvalidFrankenPickException $e) {
            return $this->error($e->getMessage(), 400);
        }

        if (! $updated instanceof FrankenDraft) {
            return $this->error('That draft is not a Franken draft', 400);
        }

        return $this->json([
            'draft' => $updated->toArray(null, true),
        ]);
    }
}
