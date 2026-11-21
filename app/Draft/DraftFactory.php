<?php

declare(strict_types=1);

namespace App\Draft;

use App\Draft\Exceptions\DraftRepositoryException;
use App\Franken\FrankenDraft;

/**
 * The single place where stored draft JSON is turned back into a draft aggregate.
 * Every repository implementation goes through here so the mode dispatch only exists once.
 */
class DraftFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): PersistableDraft
    {
        // drafts stored before Franken mode existed have no mode key
        $mode = $data['mode'] ?? PersistableDraft::MODE_MILTY;

        return match ($mode) {
            PersistableDraft::MODE_MILTY => Draft::fromJson($data),
            PersistableDraft::MODE_FRANKEN => FrankenDraft::fromJson($data),
            default => throw new DraftRepositoryException('Unknown draft mode "' . $mode . '"'),
        };
    }

    public static function fromFileContent(string $content): PersistableDraft
    {
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new DraftRepositoryException('Stored draft could not be decoded');
        }

        return self::fromArray($data);
    }

    /**
     * Normalises what a mutation callable handed back: a mutation may return the mutated
     * draft, or null when it modified the draft it was given in place.
     */
    public static function fromMutationResult(mixed $result, PersistableDraft $original): PersistableDraft
    {
        if ($result === null) {
            return $original;
        }

        if (! $result instanceof PersistableDraft) {
            throw new DraftRepositoryException('A draft mutation must return a ' . PersistableDraft::class . ' or null');
        }

        return $result;
    }
}
