<?php

declare(strict_types=1);

namespace data;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Data integrity tests for the generated Frankendraft data (docs/frankendraft-spec.md §5).
 *
 * Everything asserted here is produced by bin/sync-franken-data.php; when one of these fails the
 * fix belongs in the sync script (or in the errata allowlist below), never in the JSON by hand.
 */
class FrankenDataTest extends TestCase
{
    /**
     * The 15 categories that live in components.json.
     *
     * Spec §3.1 names 16 draft categories; the 16th, DRAFTORDER, is generated at runtime from the
     * player count and must never appear as a stored component.
     */
    private const COMPONENT_CATEGORIES = [
        'ABILITY',
        'TECH',
        'AGENT',
        'COMMANDER',
        'HERO',
        'MECH',
        'FLAGSHIP',
        'PN',
        'HOMESYSTEM',
        'STARTINGTECH',
        'STARTINGFLEET',
        'COMMODITIES',
        'BLUETILE',
        'REDTILE',
        'BREAKTHROUGH',
    ];

    private const RUNTIME_CATEGORY = 'DRAFTORDER';

    /** Category => tile type in data/tiles.json (spec §5, category derivation table). */
    private const TILE_CATEGORY_TYPES = [
        'BLUETILE' => 'blue',
        'REDTILE' => 'red',
        'HOMESYSTEM' => 'green',
    ];

    /** Items per bag, per category — standard Franken (spec §3.3). */
    private const BAG_COUNTS = [
        'ABILITY' => 3,
        'BLUETILE' => 3,
        'TECH' => 2,
        'REDTILE' => 2,
        'AGENT' => 2,
        'COMMANDER' => 2,
        'HERO' => 2,
        'MECH' => 2,
        'FLAGSHIP' => 2,
        'PN' => 2,
        'HOMESYSTEM' => 2,
        'STARTINGTECH' => 2,
        'STARTINGFLEET' => 2,
        'COMMODITIES' => 2,
        'BREAKTHROUGH' => 2,
    ];

    /** The official core box: what the generate form ticks by default (spec §2.1). */
    private const CORE_SOURCES = [
        'base',
        'pok',
        'codex1',
        'codex2',
        'codex3',
        'codex4',
    ];

    /** Franken's largest supported table. */
    private const MAX_PLAYERS = 8;

    /**
     * Factions whose components never enter a pool (spec §3.5), plus the franken<number>
     * placeholder sheets, which are matched by pattern in isExcludedFaction().
     */
    private const EXCLUDED_FACTIONS = [
        'lazax',
        'admins',
        'franken',
        'keleresm',
        'keleresx',
        'miltymod',
        'qulane',
        'neutral',
        'kaltrim',
        'xin',
        'sarcosa',
        'obsidian',
    ];

    /**
     * Categories the errata references but Franken v1 does not implement (spec §12).
     *
     * PLOT is Twilight's Fall plot cards, referenced by the Firmament's breakthrough, hero and
     * tech errata. MAHACTKING, UNIT and FACTION are AsyncTI4 addressing schemes that are not
     * draft categories; they are listed so that upstream re-introducing them does not fail this
     * test for the wrong reason.
     */
    private const OUT_OF_SCOPE_CATEGORIES = [
        'PLOT',
        'MAHACTKING',
        'UNIT',
        'FACTION',
    ];

    /**
     * Errata entries in a drafted category that deliberately have no component behind them.
     *
     * This list is exhaustive on purpose: the test asserts the unresolved set is EXACTLY this,
     * so any new dangling errata id — which would mean the sync script dropped a component it
     * should have kept — fails the test.
     *
     * Group 1 — the Obsidian faction (Thunder's Edge) is on the excluded-faction list, so the
     * sync script never emits its components, but upstream still ships errata for all of them.
     * "bladesorchestra", "marionettes", "nocturne" and PN "malevolency" are Obsidian's cards
     * under names that do not carry the faction alias.
     *
     * Group 2 — keleresm / keleresx are excluded faction aliases (the Mentak and Xxcha flavours
     * of The Council Keleres); only their home-system errata survives upstream.
     *
     * Group 3 — home systems whose tile is not in data/tiles.json (spec §4.1). The faction's
     * other components stay draftable; only its HOMESYSTEM item is dropped. keleresa's tile is
     * "93new", uydai's "br1" and pharadn's "br2" (Blue Reverie).
     *
     * Group 4 — the Aeterna Reclamation's five moon phases (Lost Legacies). They are sub-cards
     * of the ability "cycle_of_reclamation" and are not listed on any faction sheet, so walking
     * faction records never produces them. All five are marked undraftable upstream anyway.
     */
    private const UNRESOLVED_ERRATA_IDS = [
        // Group 1 — excluded faction: obsidian
        'ABILITY:bladesorchestra',
        'ABILITY:marionettes',
        'ABILITY:nocturne',
        'AGENT:obsidianagent',
        'BREAKTHROUGH:obsidianbt',
        'COMMANDER:obsidiancommander',
        'COMMODITIES:obsidian',
        'FLAGSHIP:obsidian_flagship',
        'HERO:obsidianhero',
        'HOMESYSTEM:obsidian',
        'MECH:obsidian_mech',
        'PN:malevolency',
        'STARTINGFLEET:obsidian',
        'STARTINGTECH:obsidian',
        'TECH:parasite-obs',
        'TECH:planesplitter-obs',
        // Group 2 — excluded faction aliases: keleresm, keleresx
        'HOMESYSTEM:keleresm',
        'HOMESYSTEM:keleresx',
        // Group 3 — home system with no tile in data/tiles.json
        'HOMESYSTEM:keleresa',
        'HOMESYSTEM:pharadn',
        'HOMESYSTEM:uydai',
        // Group 4 — Aeterna moon phases, sub-cards of ABILITY:cycle_of_reclamation
        'ABILITY:full_moon_phase',
        'ABILITY:lunar_eclipse_phase',
        'ABILITY:new_moon_phase',
        'ABILITY:waning_moon_phase',
        'ABILITY:waxing_moon_phase',
    ];

    // ------------------------------------------------------------------ data

    /**
     * components.json is ~1.7 MB and every data-provider case re-reads it, so the decoded files
     * are cached for the lifetime of the process (phpunit.xml sets backupStaticProperties=false).
     *
     * @var array<string, array<mixed>>
     */
    private static array $json = [];

    /** @return array<mixed> */
    protected static function getJsonData(string $path): array
    {
        return self::$json[$path] ??= json_decode(file_get_contents($path), true);
    }

    /** @return array<int, array<string, mixed>> */
    protected static function getComponents(): array
    {
        return self::getJsonData('data/franken/components.json');
    }

    /** @return array<int, array<string, mixed>> */
    protected static function getErrata(): array
    {
        return self::getJsonData('data/franken/errata.json');
    }

    /** @return array<int, array<string, mixed>> */
    protected static function getSources(): array
    {
        return self::getJsonData('data/franken/sources.json');
    }

    /** @return array<string, array<string, mixed>> */
    protected static function getTiles(): array
    {
        return self::getJsonData('data/tiles.json');
    }

    /** @return array<string, array<string, mixed>> */
    protected static function componentsById(): array
    {
        static $indexed = null;

        if ($indexed === null) {
            $indexed = [];

            foreach (self::getComponents() as $component) {
                $indexed[$component['id']] = $component;
            }
        }

        return $indexed;
    }

    /** @return array<string, array<string, mixed>> */
    protected static function errataById(): array
    {
        static $indexed = null;

        if ($indexed === null) {
            $indexed = [];

            foreach (self::getErrata() as $entry) {
                $indexed[$entry['id']] = $entry;
            }
        }

        return $indexed;
    }

    // ------------------------------------------------------------- providers

    public static function allComponents(): iterable
    {
        foreach (self::getComponents() as $component) {
            yield 'Component ' . ($component['id'] ?? '?') => ['component' => $component];
        }
    }

    public static function allTileComponents(): iterable
    {
        foreach (self::getComponents() as $component) {
            if (! isset(self::TILE_CATEGORY_TYPES[$component['category']])) {
                continue;
            }

            yield 'Component ' . $component['id'] => ['component' => $component];
        }
    }

    public static function allErrataEntries(): iterable
    {
        foreach (self::getErrata() as $entry) {
            yield 'Errata ' . ($entry['id'] ?? '?') => ['entry' => $entry];
        }
    }

    public static function allErrataReferences(): iterable
    {
        foreach (self::getErrata() as $entry) {
            $references = array_merge(
                $entry['optional_swaps'] ?? [],
                $entry['additional_components'] ?? [],
            );

            foreach ($references as $reference) {
                yield $entry['id'] . ' -> ' . $reference => [
                    'source' => $entry['id'],
                    'reference' => $reference,
                ];
            }
        }
    }

    public static function alwaysAddToPoolErrata(): iterable
    {
        foreach (self::getErrata() as $entry) {
            if (empty($entry['always_add_to_pool'])) {
                continue;
            }

            yield 'Errata ' . $entry['id'] => ['entry' => $entry];
        }
    }

    public static function draftedCategories(): iterable
    {
        foreach (self::BAG_COUNTS as $category => $bagCount) {
            if ($category === 'BREAKTHROUGH') {
                continue;
            }

            yield 'Category ' . $category => [
                'category' => $category,
                'bagCount' => $bagCount,
            ];
        }
    }

    // --------------------------------------------------- 1. component integrity

    #[Test]
    #[DataProvider('allComponents')]
    public function eachComponentHasTheRequiredFields(array $component): void
    {
        foreach (['id', 'category', 'item_id', 'name', 'source'] as $field) {
            $this->assertArrayHasKey($field, $component);
            $this->assertNotEmpty($component[$field], $field . ' is empty');
        }
    }

    #[Test]
    #[DataProvider('allComponents')]
    public function eachComponentIdIsCategoryColonItemId(array $component): void
    {
        $this->assertSame(
            $component['category'] . ':' . $component['item_id'],
            $component['id'],
        );
    }

    #[Test]
    public function componentIdsAreUnique(): void
    {
        $ids = array_column(self::getComponents(), 'id');

        $duplicates = array_keys(array_filter(
            array_count_values($ids),
            static fn (int $count) => $count > 1,
        ));

        $this->assertSame([], $duplicates, 'Duplicate component ids');
    }

    #[Test]
    #[DataProvider('allComponents')]
    public function eachComponentHasAKnownCategory(array $component): void
    {
        $this->assertContains($component['category'], self::COMPONENT_CATEGORIES);
    }

    /**
     * DRAFTORDER positions are generated per draft from the player count (spec §3.1), so a
     * DRAFTORDER row in components.json would end up dealt twice.
     */
    #[Test]
    public function componentsNeverContainTheRuntimeDraftOrderCategory(): void
    {
        $categories = array_unique(array_column(self::getComponents(), 'category'));

        $this->assertNotContains(self::RUNTIME_CATEGORY, $categories);
    }

    #[Test]
    #[DataProvider('allComponents')]
    public function eachComponentSourceIsDescribedInSourcesJson(array $component): void
    {
        $this->assertContains(
            $component['source'],
            array_column(self::getSources(), 'source'),
        );
    }

    // ------------------------------------------------------------------ 2. tiles

    #[Test]
    #[DataProvider('allTileComponents')]
    public function eachTileComponentResolvesToARealTile(array $component): void
    {
        $tileId = $component['meta']['tile'] ?? null;

        $this->assertNotNull($tileId, $component['id'] . ' has no meta.tile');
        $this->assertArrayHasKey(
            $tileId,
            self::getTiles(),
            $component['id'] . ' points at a tile that is not in data/tiles.json',
        );
    }

    #[Test]
    #[DataProvider('allTileComponents')]
    public function eachTileComponentHasTheRightTileType(array $component): void
    {
        $tile = self::getTiles()[$component['meta']['tile']];

        $this->assertSame(
            self::TILE_CATEGORY_TYPES[$component['category']],
            $tile['type'],
            $component['id'] . ' points at a tile of the wrong type',
        );
    }

    /**
     * Mecatol Rex, the Muaat supernova and Mallice are flagged nonDraftable in data/tiles.json
     * and must never reach a Franken pool either.
     */
    #[Test]
    #[DataProvider('allTileComponents')]
    public function noComponentPointsAtANonDraftableTile(array $component): void
    {
        $tile = self::getTiles()[$component['meta']['tile']];

        $this->assertFalse(
            (bool) ($tile['nonDraftable'] ?? false),
            $component['id'] . ' points at a nonDraftable tile',
        );
    }

    /**
     * A tile with no art in img/tiles renders as a bare id, which is worse than not drafting it at
     * all — so bin/sync-franken-data.php leaves it out of the pool entirely (see
     * docs/frankendraft-plan.md's "Known gaps" for the two Discordant Stars anomalies currently
     * missing art). This guards against that exclusion silently regressing.
     */
    #[Test]
    #[DataProvider('allTileComponents')]
    public function everyTileComponentHasAnImageFile(array $component): void
    {
        $image = $component['meta']['tile_image'] ?? null;

        $this->assertNotNull($image, $component['id'] . ' was generated with no tile image');
        $this->assertFileExists('img/tiles/' . $image, $component['id'] . "'s image is missing on disk");
    }

    // ----------------------------------------------------------------- 3. errata

    #[Test]
    #[DataProvider('allErrataEntries')]
    public function eachErrataEntryHasTheRequiredFields(array $entry): void
    {
        foreach (['id', 'category', 'item_id'] as $field) {
            $this->assertArrayHasKey($field, $entry);
            $this->assertNotEmpty($entry[$field], $field . ' is empty');
        }

        $this->assertSame($entry['category'] . ':' . $entry['item_id'], $entry['id']);
    }

    /**
     * Errata targeting a drafted category must hit a real component, apart from the documented
     * allowlist. Asserting on the exact set (rather than "each one is allowed") means a
     * component the sync script newly loses shows up here as a failure.
     */
    #[Test]
    public function errataInDraftedCategoriesResolveExceptForTheKnownAllowlist(): void
    {
        $components = self::componentsById();
        $unresolved = [];

        foreach (self::getErrata() as $entry) {
            if (! in_array($entry['category'], self::COMPONENT_CATEGORIES, true)) {
                continue;
            }

            if (! isset($components[$entry['id']])) {
                $unresolved[] = $entry['id'];
            }
        }

        sort($unresolved);

        $allowlist = self::UNRESOLVED_ERRATA_IDS;
        sort($allowlist);

        $this->assertSame($allowlist, $unresolved);
    }

    #[Test]
    #[DataProvider('allErrataReferences')]
    public function eachErrataReferenceResolvesOrIsOutOfScope(string $source, string $reference): void
    {
        $category = explode(':', $reference)[0];

        if (in_array($category, self::OUT_OF_SCOPE_CATEGORIES, true)) {
            // Twilight's Fall plot cards and friends are deliberately not implemented in v1.
            $this->assertContains($category, self::OUT_OF_SCOPE_CATEGORIES);

            return;
        }

        if (in_array($reference, self::UNRESOLVED_ERRATA_IDS, true)) {
            // Covered, with a reason, by errataInDraftedCategoriesResolveExceptForTheKnownAllowlist().
            $this->assertContains($reference, self::UNRESOLVED_ERRATA_IDS);

            return;
        }

        $this->assertArrayHasKey(
            $reference,
            self::componentsById(),
            $source . ' references a component that does not exist',
        );
    }

    /**
     * The Franken-only replacement cards (Primacy, Edict Y, Imperia Y, Il Na Viroset Y,
     * Starlancer Y) are attached to Mahact but are absent from Mahact's faction sheet, so
     * walking faction records alone never finds them. If this fails, the sync script stopped
     * harvesting the "franken" source and those pools silently lose their replacements.
     */
    #[Test]
    #[DataProvider('alwaysAddToPoolErrata')]
    public function eachAlwaysAddToPoolItemExists(array $entry): void
    {
        $this->assertArrayHasKey(
            $entry['id'],
            self::componentsById(),
            $entry['id'] . ' is alwaysAddToPool but has no component',
        );
    }

    // ------------------------------------------------------- 4. excluded factions

    /**
     * The exclusion is on the faction alias, not the source: components whose *source* is
     * "franken" are the legitimate Franken-only replacement cards and stay in.
     */
    #[Test]
    #[DataProvider('allComponents')]
    public function noComponentBelongsToAnExcludedFaction(array $component): void
    {
        $faction = $component['faction'] ?? null;

        if ($faction === null) {
            // Blue and red tiles belong to no faction.
            $this->expectNotToPerformAssertions();

            return;
        }

        $this->assertFalse(
            self::isExcludedFaction($faction),
            $component['id'] . ' belongs to excluded faction "' . $faction . '"',
        );
    }

    // -------------------------------------------------------- 5. pool sufficiency

    #[Test]
    #[DataProvider('draftedCategories')]
    public function theCoreSourcesFillEveryCategoryForEightPlayers(string $category, int $bagCount): void
    {
        $pool = self::poolSizes(self::CORE_SOURCES);

        $this->assertGreaterThanOrEqual(
            $bagCount * self::MAX_PLAYERS,
            $pool[$category] ?? 0,
            $category . ' cannot fill 8 bags from the official core sources',
        );
    }

    /** Breakthroughs are Thunder's Edge era content, so the core box yields none (spec §3.1). */
    #[Test]
    public function breakthroughsAreEmptyWithoutThundersEdge(): void
    {
        $pool = self::poolSizes(self::CORE_SOURCES);

        $this->assertSame(0, $pool['BREAKTHROUGH'] ?? 0);
    }

    #[Test]
    public function breakthroughsAreSufficientWithThundersEdge(): void
    {
        $pool = self::poolSizes([...self::CORE_SOURCES, 'thunders_edge']);

        $this->assertGreaterThanOrEqual(
            self::BAG_COUNTS['BREAKTHROUGH'] * self::MAX_PLAYERS,
            $pool['BREAKTHROUGH'] ?? 0,
        );
    }

    // ---------------------------------------------------------------- helpers

    private static function isExcludedFaction(string $alias): bool
    {
        if (in_array($alias, self::EXCLUDED_FACTIONS, true)) {
            return true;
        }

        // franken1..frankenN are the blank placeholder sheets; they hold no components.
        return (bool) preg_match('/^franken\d+$/', $alias);
    }

    /**
     * Draftable items per category for a given source set — the same filter BuildComponentPools
     * applies: enabled source, faction not excluded, and not marked undraftable by the errata
     * unless the errata also forces it back in with alwaysAddToPool.
     *
     * @param  array<int, string> $sources
     * @return array<string, int>
     */
    private static function poolSizes(array $sources): array
    {
        $errata = self::errataById();
        $sizes = [];

        foreach (self::getComponents() as $component) {
            if (! in_array($component['source'], $sources, true)) {
                continue;
            }

            $faction = $component['faction'] ?? null;

            if ($faction !== null && self::isExcludedFaction($faction)) {
                continue;
            }

            $entry = $errata[$component['id']] ?? null;

            if ($entry !== null && ! empty($entry['undraftable']) && empty($entry['always_add_to_pool'])) {
                continue;
            }

            $category = $component['category'];
            $sizes[$category] = ($sizes[$category] ?? 0) + 1;
        }

        return $sizes;
    }
}
