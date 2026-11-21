<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\DraftFactory;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;

class LocalDraftRepository implements DraftRepository
{
    private readonly string $storagePath;

    public function __construct()
    {
        $this->storagePath = env('STORAGE_PATH');
    }

    private function pathToDraft(string $draftId)
    {
        return $this->storagePath . '/' . 'draft_' . $draftId . '.json';
    }

    public function load(string $id): PersistableDraft
    {
        $path = $this->pathToDraft($id);

        if(! file_exists($path)) {
            throw DraftRepositoryException::notFound($id);
        }

        return DraftFactory::fromFileContent(file_get_contents($path));
    }

    public function save(PersistableDraft $draft): void
    {
        file_put_contents($this->pathToDraft($draft->id()), $draft->toFileContent());
    }

    public function delete(string $id): void
    {
        unlink($this->pathToDraft($id));
    }

    /**
     * @param callable(PersistableDraft): ?PersistableDraft $mutation
     */
    public function mutate(string $id, callable $mutation): PersistableDraft
    {
        $path = $this->pathToDraft($id);

        if (! file_exists($path)) {
            throw DraftRepositoryException::notFound($id);
        }

        $handle = fopen($path, 'r+');

        if ($handle === false) {
            throw DraftRepositoryException::notFound($id);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new DraftRepositoryException('Could not lock draft ' . $id . ' for writing');
            }

            $draft = DraftFactory::fromFileContent((string) stream_get_contents($handle));

            $mutated = DraftFactory::fromMutationResult($mutation($draft), $draft);

            // we are still holding the handle we read from, so rewind and drop the old content
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $mutated->toFileContent());
            fflush($handle);

            return $mutated;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
