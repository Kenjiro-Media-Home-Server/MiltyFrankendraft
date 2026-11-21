<?php

declare(strict_types=1);

namespace App\Draft;

/**
 * Implemented by every draft type that can be stored through a DraftRepository.
 *
 * The mode is written into the stored JSON so the repository knows which aggregate
 * to rebuild. Drafts stored before Franken mode existed carry no mode and are read
 * as Milty drafts.
 */
interface PersistableDraft
{
    public const MODE_MILTY = 'milty';
    public const MODE_FRANKEN = 'franken';

    public function id(): string;

    public function mode(): string;

    public function toFileContent(): string;

    /**
     * Both modes authenticate players and the admin the same way, so the handlers that only need
     * to check a passkey (claim, restore) can work against the interface.
     */
    public function secrets(): Secrets;
}
