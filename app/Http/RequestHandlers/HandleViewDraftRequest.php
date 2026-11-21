<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Franken\FrankenDraft;
use App\Http\HttpResponse;

class HandleViewDraftRequest extends DraftRequestHandler
{
    public function handle(): HttpResponse
    {
        $draft = $this->loadPersistableDraftByUrlId();

        if ($draft == null) {
            return $this->error('Draft not found', 404, true);
        }

        $template = $draft instanceof FrankenDraft
            ? 'templates/franken-draft.php'
            : 'templates/draft.php';

        return $this->html(
            $template,
            [
                 'draft' => $draft,
             ],
        );
    }
}
