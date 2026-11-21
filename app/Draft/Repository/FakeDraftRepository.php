<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\DraftFactory;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;

/**
 * In-memory repository for tests. Drafts are kept as stored file content so they go
 * through the same encode/decode round trip as the real repositories.
 */
class FakeDraftRepository implements DraftRepository
{
    /**
     * @var array<string, string>
     */
    private array $drafts = [];

    public function load(string $id): PersistableDraft
    {
        if (! isset($this->drafts[$id])) {
            throw DraftRepositoryException::notFound($id);
        }

        return DraftFactory::fromFileContent($this->drafts[$id]);
    }

    public function save(PersistableDraft $draft): void
    {
        $this->drafts[$draft->id()] = $draft->toFileContent();
    }

    public function delete(string $id): void
    {
        unset($this->drafts[$id]);
    }

    /**
     * Nothing to lock: a single PHP process owns this repository for the length of a test.
     *
     * @param callable(PersistableDraft): ?PersistableDraft $mutation
     */
    public function mutate(string $id, callable $mutation): PersistableDraft
    {
        $draft = $this->load($id);

        $mutated = DraftFactory::fromMutationResult($mutation($draft), $draft);

        $this->save($mutated);

        return $mutated;
    }

    public function has(string $id): bool
    {
        return isset($this->drafts[$id]);
    }
}
