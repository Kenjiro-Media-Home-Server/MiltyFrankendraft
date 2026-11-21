<?php

declare(strict_types=1);

namespace App\Franken\Commands;

use App\Draft\Name;
use App\Draft\Seed;
use App\Franken\Component;
use App\Franken\ComponentId;
use App\Franken\ComponentRepository;
use App\Franken\ComponentSource;
use App\Franken\DraftCategory;
use App\Franken\DraftOrderMode;
use App\Franken\Errata;
use App\Franken\Exceptions\InsufficientComponentsException;
use App\Franken\FrankenSettings;
use App\Testing\Factories\FrankenDraftFactory;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BuildComponentPoolsTest extends TestCase
{
    protected function tearDown(): void
    {
        FrankenDraftFactory::useRealComponents();
        parent::tearDown();
    }

    #[Test]
    public function itOnlyDrawsFromTheSelectedSources(): void
    {
        $this->useComponents([
            $this->component('ABILITY', 'wanted', 'chosen_source'),
            $this->component('ABILITY', 'unwanted', 'other_source'),
        ]);

        $pool = $this->poolFor(DraftCategory::ABILITY, ['chosen_source']);

        $this->assertSame(['ABILITY:wanted'], $pool);
    }

    #[Test]
    public function itNeverDealsAnUndraftableItem(): void
    {
        $this->useComponents([
            $this->component('ABILITY', 'fine', 'src'),
            $this->component('ABILITY', 'mitosis', 'src'),
        ]);

        Errata::useInstance(new Errata([
            'ABILITY:mitosis' => ['id' => 'ABILITY:mitosis', 'undraftable' => true],
        ]));

        $this->assertSame(['ABILITY:fine'], $this->poolFor(DraftCategory::ABILITY, ['src']));
    }

    /**
     * The Franken-only replacement cards exist precisely because the printed version is
     * undraftable, so they belong in the pool even when their own source was not selected.
     */
    #[Test]
    public function itAlwaysDealsItemsTheErrataForcesIntoThePool(): void
    {
        $this->useComponents([
            $this->component('ABILITY', 'edict_y', 'franken'),
        ]);

        Errata::useInstance(new Errata([
            'ABILITY:edict_y' => ['id' => 'ABILITY:edict_y', 'always_add_to_pool' => true],
        ]));

        $this->assertSame(
            ['ABILITY:edict_y'],
            $this->poolFor(DraftCategory::ABILITY, ['some_other_source']),
        );
    }

    #[Test]
    public function aHomebrewReprintReplacesTheComponentItNames(): void
    {
        $this->useComponents([
            $this->component('TECH', 'amd', 'base'),
            $this->component('TECH', 'absol_amd', 'absol', 'amd'),
        ]);

        $pool = $this->poolFor(DraftCategory::TECH, ['base', 'absol']);

        $this->assertSame(['TECH:absol_amd'], $pool);
    }

    #[Test]
    public function aHomebrewReprintIsIgnoredWhenItsSourceIsOff(): void
    {
        $this->useComponents([
            $this->component('TECH', 'amd', 'base'),
            $this->component('TECH', 'absol_amd', 'absol', 'amd'),
        ]);

        $this->assertSame(['TECH:amd'], $this->poolFor(DraftCategory::TECH, ['base']));
    }

    #[Test]
    public function itDealsExactlyOneSeatingPositionPerPlayer(): void
    {
        FrankenDraftFactory::useSyntheticComponents(4);
        $settings = FrankenDraftFactory::settings(4);
        $settings->activeCategories = [DraftCategory::DRAFTORDER->value];

        $pools = (new BuildComponentPools($settings))->handle();
        $positions = array_map(static fn (ComponentId $id) => $id->itemId, $pools['DRAFTORDER']);
        sort($positions);

        $this->assertSame(['1', '2', '3', '4'], $positions);
    }

    #[Test]
    public function itSaysWhichCategoriesFellShort(): void
    {
        $this->useComponents([
            $this->component('ABILITY', 'only-one', 'src'),
        ]);

        $settings = $this->settings(['src'], 4);
        $settings->activeCategories = [DraftCategory::ABILITY->value];

        try {
            (new BuildComponentPools($settings))->handle();
            $this->fail('expected the pool build to fail');
        } catch (InsufficientComponentsException $exception) {
            $this->assertArrayHasKey('ABILITY', $exception->shortfalls);
            $this->assertSame(12, $exception->shortfalls['ABILITY']['needed']);
            $this->assertSame(1, $exception->shortfalls['ABILITY']['available']);
            $this->assertStringContainsString('ABILITY needs 12 items', $exception->getMessage());
        }
    }

    /**
     * A category the selected sources cannot fill at all is simply not drafted — that is how
     * breakthroughs stay out of a game without Thunder's Edge content.
     */
    #[Test]
    public function anEmptyCategoryIsDroppedInsteadOfFailing(): void
    {
        $this->useComponents([
            $this->component('ABILITY', 'a1', 'src'),
            $this->component('ABILITY', 'a2', 'src'),
            $this->component('ABILITY', 'a3', 'src'),
            $this->component('ABILITY', 'a4', 'src'),
            $this->component('ABILITY', 'a5', 'src'),
            $this->component('ABILITY', 'a6', 'src'),
        ]);

        $active = (new BuildComponentPools($this->settings(['src'], 2)))->activeCategoryValues();

        $this->assertContains(DraftCategory::ABILITY->value, $active);
        $this->assertNotContains(DraftCategory::BREAKTHROUGH->value, $active);
        $this->assertNotContains(DraftCategory::HOMESYSTEM->value, $active);
        // Seating positions are generated, so they are always available.
        $this->assertContains(DraftCategory::DRAFTORDER->value, $active);
    }

    #[Test]
    public function theSameSeedShufflesThePoolsTheSameWay(): void
    {
        FrankenDraftFactory::useSyntheticComponents(4);

        $first = (new BuildComponentPools($this->activeSettings(4)))->handle();
        $second = (new BuildComponentPools($this->activeSettings(4)))->handle();

        $this->assertSame($this->flatten($first), $this->flatten($second));
    }

    #[Test]
    public function differentSeedsShuffleThePoolsDifferently(): void
    {
        FrankenDraftFactory::useSyntheticComponents(4);

        $first = (new BuildComponentPools($this->activeSettings(4, new Seed(1))))->handle();
        $second = (new BuildComponentPools($this->activeSettings(4, new Seed(999))))->handle();

        $this->assertNotSame($this->flatten($first), $this->flatten($second));
    }

    // ------------------------------------------------------------------ helpers

    private function component(
        string $category,
        string $itemId,
        string $source,
        ?string $replaces = null,
    ): Component {
        return new Component(
            ComponentId::make(DraftCategory::from($category), $itemId),
            $itemId,
            'testfaction',
            'The Test Faction',
            $source,
            'text',
            replaces: $replaces,
        );
    }

    /** @param array<int, Component> $components */
    private function useComponents(array $components): void
    {
        $keyed = [];
        $sources = [];

        foreach ($components as $component) {
            $keyed[$component->id->value()] = $component;
            $sources[$component->source] = new ComponentSource(
                $component->source,
                $component->source,
                'community',
                1,
                true,
            );
        }

        ComponentRepository::useComponents($keyed);
        ComponentSource::useSources($sources);
        Errata::useInstance(new Errata());
    }

    private function settings(array $sources, int $playerCount): FrankenSettings
    {
        $names = [];

        for ($index = 1; $index <= $playerCount; $index++) {
            $names[] = 'Player ' . $index;
        }

        return new FrankenSettings(
            $names,
            new Name('Pool test'),
            new Seed(777),
            true,
            $sources,
            DraftOrderMode::DRAFTED,
        );
    }

    /** @return array<int, string> */
    private function poolFor(DraftCategory $category, array $sources): array
    {
        // One player and one item per bag keeps the required pool down to a single component, so
        // these tests can assert on the exact contents rather than fighting the sufficiency check.
        $settings = $this->settings($sources, 1);
        $settings->activeCategories = [$category->value];
        $settings->limits = [$category->value => ['bag' => 1, 'hand' => 1, 'final' => 1]];

        $pools = (new BuildComponentPools($settings))->handle();
        $ids = array_map(static fn (ComponentId $id) => $id->value(), $pools[$category->value]);
        sort($ids);

        return $ids;
    }

    private function activeSettings(int $playerCount, ?Seed $seed = null): FrankenSettings
    {
        $settings = FrankenDraftFactory::settings($playerCount);

        if ($seed !== null) {
            $settings->seed = $seed;
        }

        $settings->activeCategories = (new BuildComponentPools($settings))->activeCategoryValues();

        return $settings;
    }

    private function flatten(array $pools): array
    {
        $flat = [];

        foreach ($pools as $category => $ids) {
            $flat[$category] = array_map(static fn (ComponentId $id) => $id->value(), $ids);
        }

        return $flat;
    }
}
