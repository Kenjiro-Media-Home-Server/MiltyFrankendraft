<?php

declare(strict_types=1);

namespace App\Franken;

use App\Franken\Exceptions\InvalidFrankenSettingsException;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FrankenSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FrankenDraftFactory::useSyntheticComponents();
    }

    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    /**
     * Standard Franken: 33 items per bag and a 22-item hand when every category is in play.
     * These two numbers are the whole shape of the draft, so they are worth pinning down.
     */
    #[Test]
    public function standardFrankenDealsThirtyThreeItemsAndFillsATwentyTwoItemHand(): void
    {
        $settings = FrankenDraftFactory::settings(6);
        $settings->activeCategories = array_map(
            static fn (DraftCategory $category) => $category->value,
            DraftCategory::displayOrder(),
        );

        $this->assertSame(33, $settings->bagSize());
        $this->assertSame(22, $settings->handSize());
    }

    #[Test]
    public function droppingBreakthroughsCostsTwoBagSlotsAndOneHandSlot(): void
    {
        $settings = FrankenDraftFactory::settings(6);
        $settings->activeCategories = array_values(array_filter(
            array_map(static fn (DraftCategory $c) => $c->value, DraftCategory::displayOrder()),
            static fn (string $value) => $value !== DraftCategory::BREAKTHROUGH->value,
        ));

        $this->assertSame(31, $settings->bagSize());
        $this->assertSame(21, $settings->handSize());
    }

    #[Test]
    public function aPresetSeatingOrderRemovesTheDraftOrderCategory(): void
    {
        $settings = FrankenDraftFactory::settings(6, ['draftOrderMode' => DraftOrderMode::PRESET]);

        $this->assertFalse($settings->allowsCategory(DraftCategory::DRAFTORDER));
        $this->assertSame(0, $settings->bagCount(DraftCategory::DRAFTORDER));
        $this->assertSame(0, $settings->handLimit(DraftCategory::DRAFTORDER));
        $this->assertNotContains(DraftCategory::DRAFTORDER, $settings->categories());
    }

    #[Test]
    public function theFirstRoundGrantsThreePicksAndLaterRoundsTwo(): void
    {
        $settings = FrankenDraftFactory::settings(6);

        $this->assertSame(3, $settings->quotaForRound(1));
        $this->assertSame(2, $settings->quotaForRound(2));
        $this->assertSame(2, $settings->quotaForRound(11));
    }

    #[Test]
    public function perCategoryOverridesBeatTheDefaults(): void
    {
        $settings = FrankenDraftFactory::settings(6);
        $settings->limits = ['ABILITY' => ['bag' => 5, 'hand' => 4, 'final' => 3]];

        $this->assertSame(5, $settings->bagCount(DraftCategory::ABILITY));
        $this->assertSame(4, $settings->handLimit(DraftCategory::ABILITY));
        $this->assertSame(3, $settings->finalLimit(DraftCategory::ABILITY));
        $this->assertSame(2, $settings->bagCount(DraftCategory::TECH));
    }

    #[Test]
    public function itRejectsBlankPlayerNames(): void
    {
        $settings = FrankenDraftFactory::settings(3, ['playerNames' => ['Ann', '', 'Cleo']]);

        $this->expectException(InvalidFrankenSettingsException::class);
        $settings->validate();
    }

    #[Test]
    public function itRejectsDuplicatePlayerNames(): void
    {
        $settings = FrankenDraftFactory::settings(3, ['playerNames' => ['Ann', 'Ben', 'Ann']]);

        $this->expectException(InvalidFrankenSettingsException::class);
        $settings->validate();
    }

    #[Test]
    public function itRejectsAnEmptySourceSelection(): void
    {
        $settings = FrankenDraftFactory::settings(4, ['sources' => []]);

        $this->expectException(InvalidFrankenSettingsException::class);
        $settings->validate();
    }

    #[Test]
    public function itRejectsUnknownSources(): void
    {
        $settings = FrankenDraftFactory::settings(4, ['sources' => ['no_such_source']]);

        $this->expectException(InvalidFrankenSettingsException::class);
        $settings->validate();
    }

    #[Test]
    public function itSurvivesARoundTripThroughJson(): void
    {
        $settings = FrankenDraftFactory::settings(5);
        $settings->activeCategories = ['ABILITY', 'TECH'];
        $settings->limits = ['ABILITY' => ['bag' => 4, 'hand' => 3, 'final' => 3]];

        $restored = FrankenSettings::fromJson($settings->toArray());

        $this->assertSame($settings->playerNames, $restored->playerNames);
        $this->assertSame($settings->seed->getValue(), $restored->seed->getValue());
        $this->assertSame($settings->sources, $restored->sources);
        $this->assertSame($settings->activeCategories, $restored->activeCategories);
        $this->assertSame(4, $restored->bagCount(DraftCategory::ABILITY));
        $this->assertSame($settings->draftOrderMode, $restored->draftOrderMode);
    }
}
