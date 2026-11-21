<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\Draft;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

class FakeDraftRepositoryTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itCanSaveAndLoadADraft($data): void
    {
        $repository = new FakeDraftRepository();
        $draft = Draft::fromJson($data);

        $repository->save($draft);
        $loaded = $repository->load($draft->id());

        $this->assertInstanceOf(Draft::class, $loaded);
        $this->assertSame($draft->id, $loaded->id);
        $this->assertSame(PersistableDraft::MODE_MILTY, $loaded->mode());
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itCanDeleteADraft($data): void
    {
        $repository = new FakeDraftRepository();
        $draft = Draft::fromJson($data);
        $repository->save($draft);

        $repository->delete($draft->id());

        $this->assertFalse($repository->has($draft->id()));
        $this->expectException(DraftRepositoryException::class);
        $repository->load($draft->id());
    }

    #[Test]
    public function itLetsTwoSequentialMutationsBothLand(): void
    {
        $data = $this->loadFixture(TestDrafts::ALLIANCE_MODE);
        $repository = new FakeDraftRepository();
        $repository->save(Draft::fromJson($data));
        $playerIds = array_keys($data['draft']['players']);
        $first = PlayerId::fromString($playerIds[0]);
        $second = PlayerId::fromString($playerIds[1]);

        $repository->mutate($data['id'], function (Draft $draft) use ($first): void {
            $draft->updatePlayerData($draft->playerById($first)->claim());
        });
        $returned = $repository->mutate($data['id'], function (Draft $draft) use ($second): Draft {
            $draft->updatePlayerData($draft->playerById($second)->claim());

            return $draft;
        });

        $this->assertTrue($returned->playerById($first)->claimed);

        $reloaded = $repository->load($data['id']);
        $this->assertInstanceOf(Draft::class, $reloaded);
        $this->assertTrue($reloaded->playerById($first)->claimed);
        $this->assertTrue($reloaded->playerById($second)->claimed);
    }

    #[Test]
    public function itThrowsWhenAMutationReturnsSomethingElseThanADraft(): void
    {
        $data = $this->loadFixture(TestDrafts::ALLIANCE_MODE);
        $repository = new FakeDraftRepository();
        $repository->save(Draft::fromJson($data));

        $this->expectException(DraftRepositoryException::class);

        $repository->mutate($data['id'], fn (Draft $draft) => 'not a draft');
    }

    #[Test]
    public function itThrowsWhenMutatingADraftThatDoesNotExist(): void
    {
        $repository = new FakeDraftRepository();

        $this->expectException(DraftRepositoryException::class);

        $repository->mutate('this-draft-does-not-exist', fn (Draft $draft) => $draft);
    }

    private function loadFixture(TestDrafts $draft): array
    {
        return json_decode(file_get_contents('data/test-drafts/' . $draft->value), true);
    }
}
