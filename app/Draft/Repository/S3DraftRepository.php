<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\DraftFactory;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class S3DraftRepository implements DraftRepository
{
    /**
     * How often a conditional put may be retried after another writer won the race.
     */
    private const MUTATE_RETRIES = 3;

    private readonly S3Client $client;
    private readonly string $bucket;

    /**
     * The client and bucket can be passed in so the conditional-write behaviour is testable
     * without real credentials; in the app they are always built from the environment.
     */
    public function __construct(?S3Client $client = null, ?string $bucket = null)
    {
        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            // @todo fix this?
            'region' => 'us-east-1',
            'endpoint' => 'https://' . env('REGION') . '.digitaloceanspaces.com',
            'credentials' => [
                'key' => env('ACCESS_KEY'),
                'secret' => env('ACCESS_SECRET'),
            ],
        ]);
        $this->bucket = $bucket ?? (string) env('BUCKET');
    }

    protected function draftKey(string $id): string
    {
        return 'draft_' . $id . '.json';
    }

    public function load(string $id): PersistableDraft
    {
        return DraftFactory::fromFileContent((string) $this->getObject($id)['Body']);
    }

    public function save(PersistableDraft $draft): void
    {
        $this->client->putObject($this->putArguments($draft));
    }

    public function delete(string $id): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->draftKey($id),
        ]);
    }

    /**
     * Read the object plus its ETag, apply the mutation and write it back with an
     * If-Match precondition. A 412 means somebody else stored a new version in the
     * meantime, so we start over from their version.
     *
     * @param callable(PersistableDraft): ?PersistableDraft $mutation
     */
    public function mutate(string $id, callable $mutation): PersistableDraft
    {
        $lastFailure = null;

        for ($attempt = 0; $attempt <= self::MUTATE_RETRIES; $attempt++) {
            $object = $this->getObject($id);
            $etag = (string) $object['ETag'];

            $draft = DraftFactory::fromFileContent((string) $object['Body']);

            $mutated = DraftFactory::fromMutationResult($mutation($draft), $draft);

            try {
                $this->client->putObject([
                    ...$this->putArguments($mutated),
                    'IfMatch' => $etag,
                ]);

                return $mutated;
            } catch (S3Exception $e) {
                if (! $this->isPreconditionFailure($e)) {
                    throw $e;
                }

                $lastFailure = $e;
            }
        }

        throw new DraftRepositoryException(
            'Could not store draft ' . $id . ' after ' . (self::MUTATE_RETRIES + 1) . ' attempts, it keeps being modified concurrently',
            0,
            $lastFailure,
        );
    }

    private function getObject(string $id): Result
    {
        if (! $this->client->doesObjectExist($this->bucket, $this->draftKey($id))) {
            throw DraftRepositoryException::notFound($id);
        }

        return $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->draftKey($id),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function putArguments(PersistableDraft $draft): array
    {
        return [
            'Bucket' => $this->bucket,
            'Key' => $this->draftKey($draft->id()),
            'Body' => $draft->toFileContent(),
            'ACL' => 'private',
        ];
    }

    private function isPreconditionFailure(S3Exception $exception): bool
    {
        return $exception->getStatusCode() === 412 || $exception->getAwsErrorCode() === 'PreconditionFailed';
    }
}
