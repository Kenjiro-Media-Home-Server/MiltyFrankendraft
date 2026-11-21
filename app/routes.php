<?php

declare(strict_types=1);

return [
    '/' => App\Http\RequestHandlers\HandleViewFormRequest::class,
    '/d/{id}' => App\Http\RequestHandlers\HandleViewDraftRequest::class,
    '/api/generate' => App\Http\RequestHandlers\HandleGenerateDraftRequest::class,
    '/api/regenerate' => App\Http\RequestHandlers\HandleRegenerateDraftRequest::class,
    '/api/pick' => App\Http\RequestHandlers\HandlePickRequest::class,
    '/api/claim' => App\Http\RequestHandlers\HandleClaimOrUnclaimPlayerRequest::class,
    '/api/restore' => App\Http\RequestHandlers\HandleRestoreClaimRequest::class,
    '/api/undo' => App\Http\RequestHandlers\HandleUndoRequest::class,
    '/api/draft/{id}' => App\Http\RequestHandlers\HandleGetDraftRequest::class,
    // Franken mode: simultaneous picking, so these go through the repository's atomic mutate
    '/api/franken/pick' => App\Http\RequestHandlers\HandleFrankenPickRequest::class,
    '/api/franken/unpick' => App\Http\RequestHandlers\HandleFrankenUnpickRequest::class,
    '/api/franken/ready' => App\Http\RequestHandlers\HandleFrankenReadyRequest::class,
    '/api/franken/assembly' => App\Http\RequestHandlers\HandleFrankenAssemblyRequest::class,
    '/api/franken/force-complete' => App\Http\RequestHandlers\HandleFrankenForceCompleteRequest::class,
];
