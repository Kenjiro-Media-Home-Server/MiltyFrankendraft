<?php

declare(strict_types=1);

namespace App\Draft;

use App\Draft\Exceptions\DraftRepositoryException;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

class DraftFactoryTest extends TestCase
{
    /**
     * Drafts stored before Franken mode existed carry no mode key at all.
     */
    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideTestDrafts')]
    public function itReadsADraftWithoutAModeKeyAsAMiltyDraft($data): void
    {
        $this->assertArrayNotHasKey('mode', $data);

        $draft = DraftFactory::fromArray($data);

        $this->assertInstanceOf(Draft::class, $draft);
        $this->assertSame(PersistableDraft::MODE_MILTY, $draft->mode());
        $this->assertSame($data['id'], $draft->id());
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itReadsAnExplicitMiltyDraft($data): void
    {
        $draft = DraftFactory::fromArray([...$data, 'mode' => PersistableDraft::MODE_MILTY]);

        $this->assertInstanceOf(Draft::class, $draft);
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itReadsStoredFileContent($data): void
    {
        $draft = DraftFactory::fromFileContent(Draft::fromJson($data)->toFileContent());

        $this->assertInstanceOf(Draft::class, $draft);
        $this->assertSame($data['id'], $draft->id());
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itRefusesAnUnknownMode($data): void
    {
        $this->expectException(DraftRepositoryException::class);

        DraftFactory::fromArray([...$data, 'mode' => 'kwakkeldraft']);
    }

    #[Test]
    public function itRefusesContentThatIsNotJson(): void
    {
        $this->expectException(DraftRepositoryException::class);

        DraftFactory::fromFileContent('this is not a draft');
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itFallsBackToTheOriginalDraftWhenAMutationReturnsNull($data): void
    {
        $draft = Draft::fromJson($data);

        $this->assertSame($draft, DraftFactory::fromMutationResult(null, $draft));
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itUsesTheDraftAMutationReturned($data): void
    {
        $draft = Draft::fromJson($data);
        $other = Draft::fromJson($data);

        $this->assertSame($other, DraftFactory::fromMutationResult($other, $draft));
    }

    #[Test]
    #[DataProviderExternal(TestDrafts::class, 'provideSingleTestDraft')]
    public function itRefusesAMutationResultThatIsNotADraft($data): void
    {
        $draft = Draft::fromJson($data);

        $this->expectException(DraftRepositoryException::class);

        DraftFactory::fromMutationResult(['id' => 'nope'], $draft);
    }
}
