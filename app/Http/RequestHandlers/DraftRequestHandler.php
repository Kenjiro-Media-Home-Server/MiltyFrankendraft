<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Draft;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;
use App\Franken\FrankenDraft;
use App\Http\RequestHandler;

abstract class DraftRequestHandler extends RequestHandler
{
    /** Either kind of draft, for the handlers that serve both modes. */
    public function loadPersistableDraftByUrlId($urlKey = 'id'): ?PersistableDraft
    {
        try {
            return app()->repository->load($this->request->get($urlKey));
        } catch (DraftRepositoryException $e) {
            return null;
        }
    }

    /**
     * A Milty draft, or null when the id is unknown or names a draft of another mode. Keeping the
     * narrow return type means the existing Milty handlers did not have to change.
     */
    public function loadDraftByUrlId($urlKey = 'id'): ?Draft
    {
        $draft = $this->loadPersistableDraftByUrlId($urlKey);

        return $draft instanceof Draft ? $draft : null;
    }

    public function loadFrankenDraftByUrlId($urlKey = 'id'): ?FrankenDraft
    {
        $draft = $this->loadPersistableDraftByUrlId($urlKey);

        return $draft instanceof FrankenDraft ? $draft : null;
    }
}
