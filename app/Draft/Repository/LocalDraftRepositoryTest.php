<?php

declare(strict_types=1);

namespace App\Draft\Repository;

use App\Draft\Draft;
use App\Draft\Exceptions\DraftRepositoryException;
use App\Draft\PersistableDraft;
use App\Draft\PlayerId;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

class LocalDraftRepositoryTest extends TestCase
{
    protected $draftsToCleanUp = [];

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itCanSaveADraft($data): void
    {
        $d = Draft::fromJson($data);
        $repository = new LocalDraftRepository();

        $repository->save($d);

        $this->draftsToCleanUp[] = $d->id;

        $draftPath = 'tmp/test-drafts/draft_' . $d->id . '.json';

        $this->assertFileExists($draftPath);
        $content = json_decode(file_get_contents($draftPath));

        $this->assertSame($content->id, $d->id);
        $this->assertSame($content->draft->current, $d->currentPlayerId->value);
        $this->assertSame(PersistableDraft::MODE_MILTY, $content->mode);
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itCanLoadADraft($data): void
    {
        $draftPath = 'tmp/test-drafts/draft_' . $data['id'] . '.json';
        file_put_contents($draftPath, json_encode($data));
        $this->draftsToCleanUp[] = $data['id'];
        $repository = new LocalDraftRepository();

        $draft = $repository->load($data['id']);

        $this->assertSame($draft->id, $data['id']);
        $this->assertSame($draft->currentPlayerId->value, $data['draft']['current']);
    }

    /**
     * Every stored draft predating Franken mode lacks a mode key and has to keep loading.
     */
    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideTestDrafts')]
    public function itLoadsADraftWithoutAModeKeyAsAMiltyDraft($data): void
    {
        $this->assertArrayNotHasKey('mode', $data);

        $repository = new LocalDraftRepository();
        $this->storeRawDraft($data);

        $draft = $repository->load($data['id']);

        $this->assertInstanceOf(Draft::class, $draft);
        $this->assertSame(PersistableDraft::MODE_MILTY, $draft->mode());
        $this->assertSame($data['id'], $draft->id());
    }

    #[Test]
    public function itAppliesAndPersistsAMutation(): void
    {
        $data = $this->loadFixture(TestDrafts::ALLIANCE_MODE);
        $this->storeRawDraft($data);
        $repository = new LocalDraftRepository();
        $playerId = PlayerId::fromString(array_keys($data['draft']['players'])[0]);

        $returned = $repository->mutate($data['id'], function (Draft $draft) use ($playerId): void {
            $draft->updatePlayerData($draft->playerById($playerId)->claim());
        });

        $this->assertTrue($returned->playerById($playerId)->claimed);

        $reloaded = $repository->load($data['id']);
        $this->assertInstanceOf(Draft::class, $reloaded);
        $this->assertTrue($reloaded->playerById($playerId)->claimed);
    }

    #[Test]
    public function itStoresTheDraftReturnedByTheMutation(): void
    {
        $data = $this->loadFixture(TestDrafts::ALLIANCE_MODE);
        $this->storeRawDraft($data);
        $repository = new LocalDraftRepository();

        $repository->mutate($data['id'], function (Draft $draft): Draft {
            $draft->isDone = true;

            return $draft;
        });

        $reloaded = $repository->load($data['id']);
        $this->assertInstanceOf(Draft::class, $reloaded);
        $this->assertTrue($reloaded->isDone);
    }

    /**
     * Two writers, each holding their own copy of the draft: with a plain load/save both
     * would write their own version and the first change would be lost. Through mutate()
     * the second writer reads what the first one stored, so both changes survive.
     */
    #[Test]
    public function itLetsTwoSequentialMutationsBothLand(): void
    {
        $data = $this->loadFixture(TestDrafts::ALLIANCE_MODE);
        $this->storeRawDraft($data);
        $repository = new LocalDraftRepository();
        $playerIds = array_keys($data['draft']['players']);
        $first = PlayerId::fromString($playerIds[0]);
        $second = PlayerId::fromString($playerIds[1]);

        // the copy the second writer was already holding, from before the first write
        $stale = $repository->load($data['id']);

        $repository->mutate($data['id'], function (Draft $draft) use ($first): void {
            $draft->updatePlayerData($draft->playerById($first)->claim());
        });
        $repository->mutate($data['id'], function (Draft $draft) use ($second): void {
            $draft->updatePlayerData($draft->playerById($second)->claim());
        });

        $reloaded = $repository->load($data['id']);
        $this->assertInstanceOf(Draft::class, $reloaded);
        $this->assertTrue($reloaded->playerById($first)->claimed);
        $this->assertTrue($reloaded->playerById($second)->claimed);

        $this->assertInstanceOf(Draft::class, $stale);
        $this->assertFalse($stale->playerById($first)->claimed);
    }

    #[Test]
    public function itLeavesNoLeftoversInTheFileWhenTheDraftShrinks(): void
    {
        $data = $this->loadFixture(TestDrafts::WITH_CUSTOM_SLICES);
        $this->storeRawDraft($data);
        $repository = new LocalDraftRepository();

        $repository->mutate($data['id'], function (Draft $draft): void {
            $draft->log = [];
        });

        $content = file_get_contents('tmp/test-drafts/draft_' . $data['id'] . '.json');

        $this->assertNotNull(json_decode($content, true), 'The rewritten file should be valid json');
        $this->assertSame([], json_decode($content, true)['draft']['log']);
    }

    #[Test]
    public function itThrowsWhenMutatingADraftThatDoesNotExist(): void
    {
        $repository = new LocalDraftRepository();

        $this->expectException(DraftRepositoryException::class);

        $repository->mutate('this-draft-does-not-exist', fn (Draft $draft) => $draft);
    }

    private function loadFixture(TestDrafts $draft): array
    {
        return json_decode(file_get_contents('data/test-drafts/' . $draft->value), true);
    }

    private function storeRawDraft(array $data): void
    {
        file_put_contents('tmp/test-drafts/draft_' . $data['id'] . '.json', json_encode($data));
        $this->draftsToCleanUp[] = $data['id'];
    }

    #[After]
    protected function cleanupAfterTests(): void
    {
        $r = new LocalDraftRepository();
        foreach ($this->draftsToCleanUp as $id) {
            $r->delete($id);
        }
    }
}
