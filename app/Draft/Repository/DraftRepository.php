<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\PersistableDraft;

interface DraftRepository
{
    public function load(string $id): PersistableDraft;

    public function save(PersistableDraft $draft): void;

    public function delete(string $id): void;

    /**
     * Atomic read-modify-write.
     *
     * The draft is loaded under an exclusive lock, handed to $mutation, and the result is
     * stored before the lock is released. A mutation may return the mutated draft or null,
     * in which case the draft it was given is stored. Milty is turn based, but Franken picks
     * are simultaneous, so every Franken write has to go through here or picks get lost.
     *
     * @param callable(PersistableDraft): ?PersistableDraft $mutation
     */
    public function mutate(string $id, callable $mutation): PersistableDraft;
}
