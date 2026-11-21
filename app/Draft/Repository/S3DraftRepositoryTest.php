<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\Draft;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exercises the conditional-write path with a mocked S3 handler, so no credentials are needed.
 *
 * What this cannot prove is that DigitalOcean Spaces itself honours If-Match on PutObject and
 * answers a losing write with 412 — that only shows up against the real service. The retry logic
 * here is written against both signals (HTTP 412 and the PreconditionFailed error code).
 */
class S3DraftRepositoryTest extends TestCase
{
    private const BUCKET = 'test-bucket';

    /** @var array<int, CommandInterface> */
    private array $issued = [];

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itLoadsADraftFromTheBucket(array $data): void
    {
        $draft = Draft::fromJson($data);
        $repository = $this->repositoryWith([
            new Result([]),
            $this->storedObject($draft->toFileContent()),
        ]);

        $loaded = $repository->load($draft->id);

        $this->assertSame($draft->id, $loaded->id());
        $this->assertSame('milty', $loaded->mode());
    }

    #[Test]
    public function itReportsAMissingDraft(): void
    {
        // doesObjectExist answers a 404 for the HeadObject probe
        $repository = $this->repositoryWith([
            $this->notFound(),
        ]);

        $this->expectException(DraftRepositoryException::class);

        $repository->load('nope');
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itWritesTheDraftUnderTheExpectedKey(array $data): void
    {
        $draft = Draft::fromJson($data);
        $repository = $this->repositoryWith([new Result([])]);

        $repository->save($draft);

        $put = $this->issued[0];
        $this->assertSame('PutObject', $put->getName());
        $this->assertSame(self::BUCKET, $put['Bucket']);
        $this->assertSame('draft_' . $draft->id . '.json', $put['Key']);
        $this->assertSame('private', $put['ACL']);
    }

    /** The whole point of mutate(): the write must be conditional on the version we read. */
    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function aMutationWritesConditionallyOnTheEtagItRead(array $data): void
    {
        $draft = Draft::fromJson($data);
        $repository = $this->repositoryWith([
            new Result([]),
            $this->storedObject($draft->toFileContent(), '"version-one"'),
            new Result([]),
        ]);

        $repository->mutate($draft->id, static fn ($fresh) => $fresh);

        $put = $this->issued[2];
        $this->assertSame('PutObject', $put->getName());
        $this->assertSame('"version-one"', $put['IfMatch']);
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function aLosingWriteIsRetriedAgainstTheNewerVersion(array $data): void
    {
        $draft = Draft::fromJson($data);
        $repository = $this->repositoryWith([
            // first attempt: read, then lose the race
            new Result([]),
            $this->storedObject($draft->toFileContent(), '"version-one"'),
            $this->preconditionFailed(),
            // second attempt: read the winner's version and succeed
            new Result([]),
            $this->storedObject($draft->toFileContent(), '"version-two"'),
            new Result([]),
        ]);

        $mutations = 0;

        $result = $repository->mutate($draft->id, static function ($fresh) use (&$mutations) {
            $mutations++;

            return $fresh;
        });

        $this->assertSame($draft->id, $result->id());
        $this->assertSame(2, $mutations, 'the mutation should be re-applied to the newer version');
        $this->assertSame('"version-one"', $this->issued[2]['IfMatch']);
        $this->assertSame('"version-two"', $this->issued[5]['IfMatch']);
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itGivesUpAfterRepeatedlyLosingTheRace(array $data): void
    {
        $draft = Draft::fromJson($data);
        $queue = [];

        // four attempts in total: the initial one plus three retries
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $queue[] = new Result([]);
            $queue[] = $this->storedObject($draft->toFileContent());
            $queue[] = $this->preconditionFailed();
        }

        $repository = $this->repositoryWith($queue);

        $this->expectException(DraftRepositoryException::class);
        $this->expectExceptionMessage('keeps being modified concurrently');

        $repository->mutate($draft->id, static fn ($fresh) => $fresh);
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function anyOtherS3FailureIsNotSwallowed(array $data): void
    {
        $draft = Draft::fromJson($data);
        $repository = $this->repositoryWith([
            new Result([]),
            $this->storedObject($draft->toFileContent()),
            $this->accessDenied(),
        ]);

        $this->expectException(S3Exception::class);

        $repository->mutate($draft->id, static fn ($fresh) => $fresh);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<int, mixed> $queue */
    private function repositoryWith(array $queue): S3DraftRepository
    {
        $this->issued = [];
        $handler = new MockHandler();

        foreach ($queue as $entry) {
            $handler->append($entry);
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);

        $client->getHandlerList()->appendInit(function (callable $next) {
            return function (CommandInterface $command, $request = null) use ($next) {
                $this->issued[] = $command;

                return $next($command, $request);
            };
        }, 'record-commands');

        return new S3DraftRepository($client, self::BUCKET);
    }

    private function storedObject(string $body, string $etag = '"an-etag"'): Result
    {
        return new Result([
            'Body' => Utils::streamFor($body),
            'ETag' => $etag,
        ]);
    }

    private function preconditionFailed(): \Closure
    {
        return static fn (CommandInterface $command) => new S3Exception(
            'At least one of the pre-conditions you specified did not hold',
            $command,
            ['code' => 'PreconditionFailed'],
        );
    }

    private function accessDenied(): \Closure
    {
        return static fn (CommandInterface $command) => new S3Exception(
            'Access Denied',
            $command,
            ['code' => 'AccessDenied'],
        );
    }

    private function notFound(): \Closure
    {
        return static fn (CommandInterface $command) => new S3Exception(
            'Not Found',
            $command,
            ['code' => 'NotFound'],
        );
    }
}
