<?php

declare(strict_types=1);

/**
 * Rebuilds data/franken/*.json from the AsyncTI4 component database.
 *
 * Usage:
 *   php bin/sync-franken-data.php [--ref=master] [--cache=tmp/franken-sync] [--check] [--offline]
 *
 *   --ref      Git ref of AsyncTI4/TI4_map_generator_bot to read (default: master).
 *   --cache    Where downloaded upstream files are kept between runs.
 *   --check    Do not write; exit 1 if the generated data differs from what is committed.
 *   --offline  Use whatever is already in the cache, do not hit the network.
 *
 * Upstream licence: AsyncTI4 releases its software and data into the public domain (Unlicense),
 * explicitly EXCLUDING art assets. This script therefore imports rules text and identifiers only.
 * No card art is downloaded, and none may be added to the generated files.
 *
 * The transformation is documented in docs/frankendraft-spec.md §5.
 */

// Composer's autoloader is only needed to download; --offline runs on the plain standard library.

final class FrankenDataSync
{
    private const REPO = 'AsyncTI4/TI4_map_generator_bot';

    private const DATA_PREFIX = 'src/main/resources/data/';

    /** Upstream directories we read. */
    private const DIRECTORIES = [
        'abilities',
        'breakthroughs',
        'factions',
        'franken_errata',
        'leaders',
        'promissory_notes',
        'sources',
        'technologies',
        'units',
    ];

    /**
     * Factions whose components never enter a Franken pool.
     * Mirrors FrankenDraft.excludedFactions in the AsyncTI4 bot.
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
     * Faction aliases whose display name does not match the name miltydraft uses on the
     * corresponding green tile in data/tiles.json. Maps faction alias -> local tile id.
     */
    private const HOME_SYSTEM_TILE_ALIASES = [
        // upstream "The L1Z1X Mindnet" vs local "The Lizix Mindnet"
        'l1z1x' => '6',
        // upstream "Augers of Ilyxum" vs local "Augurs of Ilyxum"
        'augers' => '4215',
    ];

    /** Maps the tile "set" values in data/tiles.json onto Franken component sources. */
    private const TILE_SET_SOURCES = [
        'BaseGame' => 'base',
        'PoK' => 'pok',
        'TE' => 'thunders_edge',
        'DS' => 'ds',
        'DSPlus' => 'uncharted_space',
    ];

    private const OUTPUT_DIR = 'data/franken';

    private string $ref = 'master';

    private string $cacheDir = 'tmp/franken-sync';

    private bool $check = false;

    private bool $offline = false;

    private string $rootDir;

    /** @var array<string, array<int, array<string, mixed>>> upstream directory => records */
    private array $upstream = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<string, string>|null faction display name => miltydraft faction id */
    private ?array $localFactionsByName = null;

    /** @var array<string, string>|null faction display name => wiki url */
    private ?array $localFactionWikiByName = null;

    /** @var array<string, array<string, mixed>> */
    private array $components = [];

    /** @var array<string, array<string, mixed>> */
    private array $errata = [];

    /** @var array<int, array<string, mixed>> */
    private array $sources = [];

    /** @var array<string, array<string, mixed>> */
    private array $factions = [];

    /** @param array<int, string> $argv */
    public function __construct(array $argv)
    {
        $this->rootDir = dirname(__DIR__);

        foreach (array_slice($argv, 1) as $argument) {
            if (str_starts_with($argument, '--ref=')) {
                $this->ref = substr($argument, 6);
            } elseif (str_starts_with($argument, '--cache=')) {
                $this->cacheDir = substr($argument, 8);
            } elseif ($argument === '--check') {
                $this->check = true;
            } elseif ($argument === '--offline') {
                $this->offline = true;
            } else {
                throw new RuntimeException('Unknown argument: ' . $argument);
            }
        }
    }

    public function run(): int
    {
        $this->download();
        $this->load();

        $tiles = $this->loadLocalTiles();

        $this->buildFactionIndex();
        $this->buildFactionComponents($tiles);
        $this->buildFrankenOnlyComponents();
        $this->buildTileComponents($tiles);
        $this->buildErrata();
        $this->buildSources();

        return $this->write();
    }

    // ---------------------------------------------------------------- download

    private function download(): void
    {
        $cachePath = $this->path($this->cacheDir . '/' . $this->safeRef());

        if ($this->offline) {
            if (! is_dir($cachePath)) {
                throw new RuntimeException('--offline given but no cache at ' . $cachePath);
            }
            $this->out('Using cached upstream data at ' . $cachePath);

            return;
        }

        $autoloader = $this->path('vendor/autoload.php');

        if (! is_file($autoloader)) {
            throw new RuntimeException(
                'Downloading needs composer dependencies. Run "composer install", '
                . 'or re-run with --offline to rebuild from the cache.',
            );
        }

        require_once $autoloader;

        $client = new GuzzleHttp\Client([
            'timeout' => 30,
            'headers' => ['User-Agent' => 'miltydraft-franken-sync'],
        ]);

        $this->out('Listing ' . self::REPO . '@' . $this->ref . ' ...');
        $treeUrl = sprintf(
            'https://api.github.com/repos/%s/git/trees/%s?recursive=1',
            self::REPO,
            rawurlencode($this->ref),
        );
        $tree = json_decode((string) $client->get($treeUrl)->getBody(), true);

        if (! is_array($tree) || ! isset($tree['tree'])) {
            throw new RuntimeException('Could not read the upstream file tree.');
        }

        if (! empty($tree['truncated'])) {
            throw new RuntimeException('Upstream file tree came back truncated; cannot sync reliably.');
        }

        $wanted = [];
        foreach ($tree['tree'] as $entry) {
            $path = (string) $entry['path'];

            if (! str_starts_with($path, self::DATA_PREFIX) || ! str_ends_with($path, '.json')) {
                continue;
            }

            $relative = substr($path, strlen(self::DATA_PREFIX));
            $directory = strtok($relative, '/');

            if (in_array($directory, self::DIRECTORIES, true)) {
                $wanted[$relative] = $path;
            }
        }

        $this->out('Downloading ' . count($wanted) . ' files ...');

        $requests = function () use ($wanted) {
            foreach ($wanted as $relative => $path) {
                yield $relative => new GuzzleHttp\Psr7\Request('GET', sprintf(
                    'https://raw.githubusercontent.com/%s/%s/%s',
                    self::REPO,
                    $this->ref,
                    $path,
                ));
            }
        };

        $failures = [];
        $pool = new GuzzleHttp\Pool($client, $requests(), [
            'concurrency' => 8,
            'fulfilled' => function ($response, $relative) use ($cachePath): void {
                $target = $cachePath . '/' . $relative;
                $this->ensureDirectory(dirname($target));
                file_put_contents($target, (string) $response->getBody());
            },
            'rejected' => function ($reason, $relative) use (&$failures): void {
                $failures[] = $relative;
            },
        ]);

        $pool->promise()->wait();

        if ($failures !== []) {
            throw new RuntimeException('Failed to download: ' . implode(', ', $failures));
        }
    }

    private function load(): void
    {
        $cachePath = $this->path($this->cacheDir . '/' . $this->safeRef());

        foreach (self::DIRECTORIES as $directory) {
            $records = [];

            foreach (glob($cachePath . '/' . $directory . '/*.json') ?: [] as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Could not decode ' . $file);
                }

                // sources.json is a list; every other file is a list of records too.
                foreach ($decoded as $record) {
                    if (is_array($record)) {
                        $records[] = $record;
                    }
                }
            }

            $this->upstream[$directory] = $records;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function loadLocalTiles(): array
    {
        $decoded = json_decode((string) file_get_contents($this->path('data/tiles.json')), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Could not read data/tiles.json');
        }

        return $decoded;
    }

    // ------------------------------------------------------------- transforms

    private function buildFactionIndex(): void
    {
        foreach ($this->upstream['factions'] as $faction) {
            $alias = (string) ($faction['alias'] ?? '');

            if ($alias === '' || $this->isExcludedFaction($alias)) {
                continue;
            }

            $this->factions[$alias] = [
                'alias' => $alias,
                'name' => (string) ($faction['factionName'] ?? $alias),
                'source' => (string) ($faction['source'] ?? 'unknown'),
                'home_system' => (string) ($faction['homeSystem'] ?? ''),
                'home_planets' => array_values((array) ($faction['homePlanets'] ?? [])),
                'commodities' => (int) ($faction['commodities'] ?? 0),
                'starting_fleet' => (string) ($faction['startingFleet'] ?? ''),
                'starting_tech' => array_values((array) ($faction['startingTech'] ?? [])),
                'starting_tech_options' => array_values((array) ($faction['startingTechOptions'] ?? [])),
                'starting_tech_amount' => (int) ($faction['startingTechAmount'] ?? 0),
                'faction_tech' => array_values((array) ($faction['factionTech'] ?? [])),
                'abilities' => array_values((array) ($faction['abilities'] ?? [])),
                'leaders' => array_values((array) ($faction['leaders'] ?? [])),
                'promissory_notes' => array_values((array) ($faction['promissoryNotes'] ?? [])),
                'units' => array_values((array) ($faction['units'] ?? [])),
                'wiki' => $this->wikiUrlFor((string) ($faction['factionName'] ?? ''), $faction['wikiURL'] ?? null),
                'replaces' => $faction['homebrewReplacesID'] ?? null,
            ];
        }

        ksort($this->factions);
    }

    /** @param array<string, array<string, mixed>> $tiles */
    private function buildFactionComponents(array $tiles): void
    {
        $abilities = $this->indexBy($this->upstream['abilities'], 'id');
        $leaders = $this->indexBy($this->upstream['leaders'], 'id');
        $technologies = $this->indexBy($this->upstream['technologies'], 'alias');
        $notes = $this->indexBy($this->upstream['promissory_notes'], 'alias');
        $units = $this->indexBy($this->upstream['units'], 'id');
        $breakthroughs = $this->indexBy($this->upstream['breakthroughs'], 'alias');
        $homeSystems = $this->indexHomeSystemTiles($tiles);

        foreach ($this->factions as $alias => $faction) {
            foreach ($faction['abilities'] as $id) {
                $record = $abilities[$id] ?? null;

                if ($record === null) {
                    $this->warn('ABILITY', (string) $id, $alias, 'not found upstream');

                    continue;
                }

                $this->addComponent('ABILITY', (string) $id, $record, $faction, [
                    'window' => $record['window'] ?? null,
                    'window_effect' => $record['windowEffect'] ?? null,
                    'permanent_effect' => $record['permanentEffect'] ?? null,
                ]);
            }

            foreach ($faction['faction_tech'] as $id) {
                $record = $technologies[$id] ?? null;

                if ($record === null) {
                    $this->warn('TECH', (string) $id, $alias, 'not found upstream');

                    continue;
                }

                $this->addComponent('TECH', (string) $id, $record, $faction, [
                    'types' => array_values((array) ($record['types'] ?? [])),
                    'requirements' => $record['requirements'] ?? null,
                    'base_upgrade' => $record['baseUpgrade'] ?? null,
                ]);
            }

            foreach ($faction['leaders'] as $id) {
                $record = $leaders[$id] ?? null;

                if ($record === null) {
                    $this->warn('LEADER', (string) $id, $alias, 'not found upstream');

                    continue;
                }

                $category = match ((string) ($record['type'] ?? '')) {
                    'agent' => 'AGENT',
                    'commander' => 'COMMANDER',
                    'hero' => 'HERO',
                    default => null,
                };

                // "envoy" leaders are not a Franken draft category.
                if ($category === null) {
                    continue;
                }

                $this->addComponent($category, (string) $id, $record, $faction, [
                    'title' => $record['title'] ?? null,
                    'ability_name' => $record['abilityName'] ?? null,
                    'ability_window' => $record['abilityWindow'] ?? null,
                    'ability_text' => $record['abilityText'] ?? null,
                    'unlock_condition' => $record['unlockCondition'] ?? null,
                ]);
            }

            foreach ($faction['promissory_notes'] as $id) {
                $record = $notes[$id] ?? null;

                if ($record === null) {
                    $this->warn('PN', (string) $id, $alias, 'not found upstream');

                    continue;
                }

                $this->addComponent('PN', (string) $id, $record, $faction, [
                    'play_area' => (bool) ($record['playArea'] ?? false),
                    'play_immediately' => (bool) ($record['playImmediately'] ?? false),
                ]);
            }

            foreach ($faction['units'] as $id) {
                $record = $units[$id] ?? null;

                if ($record === null) {
                    continue;
                }

                $category = match ((string) ($record['baseType'] ?? '')) {
                    'flagship' => 'FLAGSHIP',
                    'mech' => 'MECH',
                    default => null,
                };

                if ($category === null) {
                    continue;
                }

                $this->addComponent($category, (string) $id, $record, $faction, [
                    'cost' => $record['cost'] ?? null,
                    'combat_hits_on' => $record['combatHitsOn'] ?? null,
                    'combat_die_count' => $record['combatDieCount'] ?? null,
                    'move_value' => $record['moveValue'] ?? null,
                    'capacity_value' => $record['capacityValue'] ?? null,
                    'sustain_damage' => (bool) ($record['sustainDamage'] ?? false),
                    'ability' => $record['ability'] ?? null,
                ]);
            }

            foreach ($this->breakthroughsFor($alias, $breakthroughs) as $id => $record) {
                $this->addComponent('BREAKTHROUGH', (string) $id, $record, $faction, [
                    'synergy' => array_values((array) ($record['synergy'] ?? [])),
                ]);
            }

            // One aggregate item per faction for the three "sheet" categories.
            $this->addSyntheticComponent('COMMODITIES', $alias, $faction, (string) $faction['commodities'], [
                'value' => $faction['commodities'],
            ]);

            if ($faction['starting_fleet'] !== '') {
                $fleet = $this->parseStartingFleet((string) $faction['starting_fleet']);

                $this->addSyntheticComponent(
                    'STARTINGFLEET',
                    $alias,
                    $faction,
                    $this->startingFleetText($fleet),
                    [
                        'fleet' => $fleet,
                        'fleet_raw' => $faction['starting_fleet'],
                    ],
                );
            }

            // Every faction gets a starting-tech item, including the ones that start with none
            // (Sardakk N'orr). The errata marks those undraftable, but they must still exist
            // because other components hand them out as additional components.
            {
                $names = [];
                foreach ($faction['starting_tech'] as $techId) {
                    $names[] = (string) ($technologies[$techId]['name'] ?? $techId);
                }

                $optionNames = [];
                foreach ($faction['starting_tech_options'] as $techId) {
                    $optionNames[] = (string) ($technologies[$techId]['name'] ?? $techId);
                }

                $this->addSyntheticComponent(
                    'STARTINGTECH',
                    $alias,
                    $faction,
                    $this->startingTechText($names, $optionNames, (int) $faction['starting_tech_amount']),
                    [
                        'tech' => $faction['starting_tech'],
                        'tech_names' => $names,
                        'options' => $faction['starting_tech_options'],
                        'option_names' => $optionNames,
                        'choose' => $faction['starting_tech_amount'],
                    ],
                );
            }

            $tileId = $homeSystems[$alias] ?? null;

            if ($tileId === null) {
                $this->warn('HOMESYSTEM', $alias, $alias, 'no matching tile in data/tiles.json');
            } else {
                $tile = $tiles[$tileId];
                $tileImage = $this->tileImage($tileId, $alias);

                // As with blue/red tiles: no art means it is left out rather than shown as a bare
                // id. tileImage() has already logged why. The faction's other components are
                // unaffected — see the "Known content limitation — tiles" section of the README.
                if ($tileImage === null) {
                    continue;
                }

                $this->addSyntheticComponent('HOMESYSTEM', $alias, $faction, $this->tileText($tile), [
                    'tile' => $tileId,
                    'tile_image' => $tileImage,
                    'planets' => $tile['planets'] ?? [],
                    'stations' => $tile['stations'] ?? [],
                    'wormhole' => $tile['wormhole'] ?? null,
                    'anomaly' => $tile['anomaly'] ?? null,
                ]);
            }
        }
    }

    /**
     * The "franken" source holds Franken-only replacement cards (Primacy, Edict Y, Imperia Y,
     * Il Na Viroset Y, Starlancer Y). They name the faction they belong to but are deliberately
     * not listed on that faction's sheet, so walking faction records alone never finds them.
     * The errata marks them alwaysAddToPool.
     */
    private function buildFrankenOnlyComponents(): void
    {
        $categoryByDirectory = [
            'abilities' => ['id', static fn (array $r) => 'ABILITY'],
            'technologies' => ['alias', static fn (array $r) => 'TECH'],
            'promissory_notes' => ['alias', static fn (array $r) => 'PN'],
            'breakthroughs' => ['alias', static fn (array $r) => 'BREAKTHROUGH'],
            'leaders' => ['id', static fn (array $r) => match ((string) ($r['type'] ?? '')) {
                'agent' => 'AGENT',
                'commander' => 'COMMANDER',
                'hero' => 'HERO',
                default => null,
            }],
            'units' => ['id', static fn (array $r) => match ((string) ($r['baseType'] ?? '')) {
                'flagship' => 'FLAGSHIP',
                'mech' => 'MECH',
                default => null,
            }],
        ];

        foreach ($categoryByDirectory as $directory => [$idKey, $resolveCategory]) {
            foreach ($this->upstream[$directory] as $record) {
                if ((string) ($record['source'] ?? '') !== 'franken') {
                    continue;
                }

                $category = $resolveCategory($record);
                $itemId = (string) ($record[$idKey] ?? '');

                if ($category === null || $itemId === '') {
                    continue;
                }

                if (isset($this->components[$category . ':' . $itemId])) {
                    continue;
                }

                $factionAlias = (string) ($record['faction'] ?? '');

                // Franken-only cards still belong to a faction, and an excluded faction's
                // components must never reach a pool — Obsidian ships one of these.
                if ($factionAlias !== '' && $this->isExcludedFaction($factionAlias)) {
                    continue;
                }

                $faction = $this->factions[$factionAlias] ?? [
                    'alias' => $factionAlias !== '' ? $factionAlias : null,
                    'name' => null,
                    'source' => 'franken',
                ];

                $this->addComponent($category, $itemId, $record, $faction, [
                    'franken_only' => true,
                ]);
            }
        }
    }

    /**
     * Blue and red tiles come from miltydraft's own tile data, so drafted tiles keep working with
     * the existing map renderer and tile images.
     *
     * @param array<string, array<string, mixed>> $tiles
     */
    private function buildTileComponents(array $tiles): void
    {
        foreach ($tiles as $tileId => $tile) {
            $category = match ((string) ($tile['type'] ?? '')) {
                'blue' => 'BLUETILE',
                'red' => 'REDTILE',
                default => null,
            };

            if ($category === null) {
                continue;
            }

            // Mecatol Rex, the Muaat supernova and Mallice are never part of a draft pool.
            // Tile::all() gives them TileTier::NONE for the same reason.
            if (! empty($tile['nonDraftable'])) {
                continue;
            }

            $set = (string) ($tile['set'] ?? '');
            $source = self::TILE_SET_SOURCES[$set] ?? null;

            if ($source === null) {
                $this->warnings[] = sprintf('Tile %s has unmapped set "%s"; skipped.', $tileId, $set);

                continue;
            }

            // A tile with no art renders as a bare id on the map and the slice builder, which is
            // worse than not offering it at all — so it is left out of the pool entirely rather
            // than dealt with a text placeholder. tileImage() has already logged why.
            $tileImage = $this->tileImage((string) $tileId, null);

            if ($tileImage === null) {
                continue;
            }

            $this->components[$category . ':' . $tileId] = [
                'id' => $category . ':' . $tileId,
                'category' => $category,
                'item_id' => (string) $tileId,
                'name' => $this->tileName($tile, (string) $tileId),
                'faction' => null,
                'faction_name' => null,
                'source' => $source,
                'wiki' => null,
                'text' => $this->tileText($tile),
                'meta' => (object) [
                    'tile' => (string) $tileId,
                    'tile_image' => $tileImage,
                    'planets' => $tile['planets'] ?? [],
                    'stations' => $tile['stations'] ?? [],
                    'wormhole' => $tile['wormhole'] ?? null,
                    'anomaly' => $tile['anomaly'] ?? null,
                ],
                'replaces' => null,
            ];
        }
    }

    private function buildErrata(): void
    {
        foreach ($this->upstream['franken_errata'] as $entry) {
            $category = (string) ($entry['itemCategory'] ?? '');
            $itemId = (string) ($entry['itemId'] ?? '');

            if ($category === '' || $itemId === '') {
                continue;
            }

            $id = $category . ':' . $itemId;

            $this->errata[$id] = array_filter([
                'id' => $id,
                'category' => $category,
                'item_id' => $itemId,
                'undraftable' => ! empty($entry['undraftable']),
                'always_add_to_pool' => ! empty($entry['alwaysAddToPool']),
                'optional_swaps' => array_values((array) ($entry['optionalSwaps'] ?? [])),
                'additional_components' => array_values((array) ($entry['additionalComponents'] ?? [])),
                'alternate_text' => $entry['alternateText'] ?? null,
                'source' => $entry['source'] ?? null,
            ], static fn ($value) => $value !== null && $value !== false && $value !== []);

            // Keep the identity keys even when they were filtered out above.
            $this->errata[$id]['id'] = $id;
            $this->errata[$id]['category'] = $category;
            $this->errata[$id]['item_id'] = $itemId;
        }

        ksort($this->errata);
    }

    private function buildSources(): void
    {
        $counts = [];
        foreach ($this->components as $component) {
            $source = (string) $component['source'];
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }

        foreach ($this->upstream['sources'] as $source) {
            $alias = (string) ($source['source'] ?? '');

            if ($alias === '') {
                continue;
            }

            $this->sources[] = [
                'source' => $alias,
                'name' => trim((string) ($source['name'] ?? $alias)),
                'canal' => (string) ($source['canal'] ?? 'community'),
                'components' => $counts[$alias] ?? 0,
                'contributes' => ($counts[$alias] ?? 0) > 0,
            ];
        }

        // Sources that produce components but are not described in sources.json.
        $described = array_column($this->sources, 'source');
        foreach ($counts as $alias => $count) {
            if (! in_array($alias, $described, true)) {
                $this->sources[] = [
                    'source' => $alias,
                    'name' => $alias,
                    'canal' => 'community',
                    'components' => $count,
                    'contributes' => true,
                ];
            }
        }

        usort($this->sources, static function (array $a, array $b): int {
            return [$a['canal'], $a['source']] <=> [$b['canal'], $b['source']];
        });
    }

    // ------------------------------------------------------------ small parts

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $faction
     * @param array<string, mixed> $meta
     */
    private function addComponent(
        string $category,
        string $itemId,
        array $record,
        array $faction,
        array $meta,
    ): void {
        $id = $category . ':' . $itemId;

        $this->components[$id] = [
            'id' => $id,
            'category' => $category,
            'item_id' => $itemId,
            'name' => trim((string) ($record['name'] ?? $itemId)),
            'faction' => $faction['alias'],
            'faction_name' => $faction['name'],
            'source' => (string) ($record['source'] ?? $faction['source']),
            'wiki' => $faction['wiki'] ?? null,
            'text' => $this->textFor($category, $record),
            // cast to object so an empty meta encodes as {} and never as []
            'meta' => (object) array_filter($meta, static fn ($value) => $value !== null && $value !== []),
            'replaces' => $record['homebrewReplacesID'] ?? null,
        ];
    }

    /**
     * Categories that are a property of the faction sheet rather than a card of their own.
     *
     * @param array<string, mixed> $faction
     * @param array<string, mixed> $meta
     */
    private function addSyntheticComponent(
        string $category,
        string $itemId,
        array $faction,
        string $text,
        array $meta,
    ): void {
        $id = $category . ':' . $itemId;

        $this->components[$id] = [
            'id' => $id,
            'category' => $category,
            'item_id' => $itemId,
            'name' => $faction['name'],
            'faction' => $faction['alias'],
            'faction_name' => $faction['name'],
            'source' => (string) $faction['source'],
            'wiki' => $faction['wiki'] ?? null,
            'text' => $text,
            'meta' => (object) $meta,
            'replaces' => null,
        ];
    }

    /** @param array<string, mixed> $record */
    private function textFor(string $category, array $record): string
    {
        $lines = [];

        switch ($category) {
            case 'ABILITY':
                if (! empty($record['permanentEffect'])) {
                    $lines[] = (string) $record['permanentEffect'];
                }
                if (! empty($record['window'])) {
                    $lines[] = rtrim((string) $record['window'], ':') . ':';
                }
                if (! empty($record['windowEffect'])) {
                    $lines[] = (string) $record['windowEffect'];
                }
                break;

            case 'TECH':
                $types = implode(', ', (array) ($record['types'] ?? []));
                if ($types !== '') {
                    $lines[] = $types;
                }
                if (! empty($record['requirements'])) {
                    $lines[] = 'Requires: ' . (string) $record['requirements'];
                }
                if (! empty($record['text'])) {
                    $lines[] = (string) $record['text'];
                }
                break;

            case 'AGENT':
            case 'COMMANDER':
            case 'HERO':
                if (! empty($record['title'])) {
                    $lines[] = (string) $record['title'];
                }
                if (! empty($record['unlockCondition'])) {
                    $lines[] = 'Unlock: ' . (string) $record['unlockCondition'];
                }
                if (! empty($record['abilityName'])) {
                    $lines[] = (string) $record['abilityName'];
                }
                if (! empty($record['abilityWindow'])) {
                    $lines[] = (string) $record['abilityWindow'];
                }
                if (! empty($record['abilityText'])) {
                    $lines[] = (string) $record['abilityText'];
                }
                break;

            case 'MECH':
            case 'FLAGSHIP':
                if (! empty($record['ability'])) {
                    $lines[] = (string) $record['ability'];
                }
                $lines[] = $this->unitStatLine($record);
                break;

            case 'BREAKTHROUGH':
                $synergy = implode(', ', (array) ($record['synergy'] ?? []));
                if ($synergy !== '') {
                    $lines[] = 'Synergy: ' . $synergy;
                }
                if (! empty($record['text'])) {
                    $lines[] = (string) $record['text'];
                }
                break;

            default:
                if (! empty($record['text'])) {
                    $lines[] = (string) $record['text'];
                }
                break;
        }

        return trim(implode("\n", array_filter($lines, static fn (string $line) => trim($line) !== '')));
    }

    /** @param array<string, mixed> $record */
    private function unitStatLine(array $record): string
    {
        $parts = [];

        if (isset($record['cost'])) {
            $parts[] = 'Cost ' . $record['cost'];
        }
        if (isset($record['combatHitsOn'])) {
            $dice = (int) ($record['combatDieCount'] ?? 1);
            $parts[] = 'Combat ' . $record['combatHitsOn'] . ($dice > 1 ? ' (x' . $dice . ')' : '');
        }
        if (isset($record['moveValue'])) {
            $parts[] = 'Move ' . $record['moveValue'];
        }
        if (isset($record['capacityValue'])) {
            $parts[] = 'Capacity ' . $record['capacityValue'];
        }
        if (! empty($record['sustainDamage'])) {
            $parts[] = 'Sustain Damage';
        }

        return implode(' · ', $parts);
    }

    /** Unit codes as they appear in a startingFleet string, in the order a player reads them. */
    private const FLEET_UNIT_NAMES = [
        'ws' => ['War Sun', 'War Suns'],
        'fs' => ['Flagship', 'Flagships'],
        'dn' => ['Dreadnought', 'Dreadnoughts'],
        'cv' => ['Carrier', 'Carriers'],
        'cr' => ['Cruiser', 'Cruisers'],
        // upstream's own asyncId for a cruiser; the fleet strings use both spellings
        'ca' => ['Cruiser', 'Cruisers'],
        'dd' => ['Destroyer', 'Destroyers'],
        'ff' => ['Fighter', 'Fighters'],
        'mf' => ['Mech', 'Mechs'],
        'mech' => ['Mech', 'Mechs'],
        'inf' => ['Infantry', 'Infantry'],
        'gf' => ['Infantry', 'Infantry'],
        'pds' => ['PDS', 'PDS'],
        'pd' => ['PDS', 'PDS'],
        'sd' => ['Space Dock', 'Space Docks'],
    ];

    /**
     * Turns "1 dn, 1 cv, 4 ff, 2 inf l, 2 inf v, sd l" into countable entries.
     *
     * The trailing letter on an entry names which home planet the unit starts on. In Franken the
     * home system is drafted separately, so that letter means nothing to the player and is dropped;
     * entries for the same unit are added together instead (2 infantry here plus 2 there is 4).
     *
     * @return array<int, array{count: int, unit: string}>
     */
    private function parseStartingFleet(string $fleet): array
    {
        $entries = [];

        foreach (preg_split('/[,;]/', $fleet) ?: [] as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (preg_match('/^(\d+)?\s*([a-z]+)(?:\s+[a-z])?$/i', $token, $matches) !== 1) {
                // anything that does not fit the pattern is kept verbatim rather than dropped
                $entries[$token] = ['count' => 1, 'unit' => $token];

                continue;
            }

            $count = ($matches[1] ?? '') === '' ? 1 : (int) $matches[1];
            $code = strtolower($matches[2]);
            $unit = self::FLEET_UNIT_NAMES[$code][0] ?? $code;

            if (isset($entries[$unit])) {
                $entries[$unit]['count'] += $count;
            } else {
                $entries[$unit] = ['count' => $count, 'unit' => $unit];
            }
        }

        return array_values($entries);
    }

    /**
     * Written out one per line, so a bag shows "2 Dreadnoughts" rather than "2 dn".
     *
     * @param array<int, array{count: int, unit: string}> $fleet
     */
    private function startingFleetText(array $fleet): string
    {
        $lines = [];

        foreach ($fleet as $entry) {
            $label = $entry['unit'];

            foreach (self::FLEET_UNIT_NAMES as $names) {
                if ($names[0] === $entry['unit']) {
                    $label = $entry['count'] === 1 ? $names[0] : $names[1];

                    break;
                }
            }

            $lines[] = sprintf('- %d %s', $entry['count'], $label);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $names
     * @param array<int, string> $options
     */
    private function startingTechText(array $names, array $options, int $choose): string
    {
        $lines = [];

        if ($names !== []) {
            $lines[] = implode(', ', $names);
        }

        if ($options !== [] && $choose > 0) {
            $lines[] = sprintf('Choose %d of: %s', $choose, implode(', ', $options));
        }

        if ($lines === []) {
            $lines[] = 'None';
        }

        return implode("\n", $lines);
    }

    /**
     * The image file in img/tiles for a tile. Most tiles are "ST_<id>.png", but the Discordant
     * Stars home systems are filed under their faction instead, so the name is resolved here once
     * rather than guessed at in the browser.
     */
    private function tileImage(string $tileId, ?string $factionAlias): ?string
    {
        $candidates = ['ST_' . $tileId . '.png'];

        if ($factionAlias !== null) {
            $candidates[] = 'DS_' . $factionAlias . '.png';
        }

        // Upstream and miltydraft do not always agree on a faction's alias (augers/ilyxum,
        // rohdhna/rohdina), so fall back to the id miltydraft files the image under.
        $localAlias = $this->localFactionAliasForTile($tileId);

        if ($localAlias !== null) {
            $candidates[] = 'DS_' . $localAlias . '.png';
        }

        foreach ($candidates as $candidate) {
            if (is_file($this->path('img/tiles/' . $candidate))) {
                return $candidate;
            }
        }

        $this->warnings[] = sprintf('Tile %s has no image in img/tiles.', $tileId);

        return null;
    }


    /**
     * The wiki page for a faction. miltydraft's own faction data points at the community fandom
     * wiki, which is what players expect; upstream's ti4ultimate link is the fallback for factions
     * this repo does not ship.
     *
     * Neither source has per-component pages — there is no canonical page for one agent or one
     * starting fleet — so a component links to the faction it came from.
     */
    private function wikiUrlFor(string $factionName, ?string $upstreamUrl): ?string
    {
        if ($this->localFactionWikiByName === null) {
            $decoded = json_decode((string) file_get_contents($this->path('data/factions.json')), true);
            $byName = [];

            foreach ((array) $decoded as $faction) {
                if (! empty($faction['wiki'])) {
                    $byName[$this->normaliseName((string) $faction['name'])] = (string) $faction['wiki'];
                }
            }

            $this->localFactionWikiByName = $byName;
        }

        $local = $this->localFactionWikiByName[$this->normaliseName($factionName)] ?? null;

        return $local ?? ($upstreamUrl === null ? null : (string) $upstreamUrl);
    }

    /** Maps a tile id to the faction id miltydraft's own data/factions.json uses. */
    private function localFactionAliasForTile(string $tileId): ?string
    {
        if ($this->localFactionsByName === null) {
            $decoded = json_decode((string) file_get_contents($this->path('data/factions.json')), true);
            $byName = [];

            foreach ((array) $decoded as $faction) {
                $byName[$this->normaliseName((string) $faction['name'])] = (string) $faction['id'];
            }

            $this->localFactionsByName = $byName;
        }

        $tiles = $this->loadLocalTiles();
        $factionName = $tiles[$tileId]['faction'] ?? null;

        if ($factionName === null) {
            return null;
        }

        return $this->localFactionsByName[$this->normaliseName((string) $factionName)] ?? null;
    }

    /** @param array<string, mixed> $tile */
    private function tileName(array $tile, string $tileId): string
    {
        $planets = (array) ($tile['planets'] ?? []);
        $names = array_map(static fn (array $planet) => (string) $planet['name'], $planets);

        if ($names !== []) {
            return implode(' / ', $names);
        }

        return 'Tile ' . $tileId;
    }

    /** @param array<string, mixed> $tile */
    private function tileText(array $tile): string
    {
        $lines = [];

        foreach ((array) ($tile['planets'] ?? []) as $planet) {
            $line = sprintf(
                '%s %d/%d',
                (string) $planet['name'],
                (int) $planet['resources'],
                (int) $planet['influence'],
            );

            $extras = [];
            // a planet's trait is a string, or a list of strings for the multi-trait TE planets
            foreach ((array) ($planet['trait'] ?? []) as $trait) {
                if ((string) $trait !== '') {
                    $extras[] = (string) $trait;
                }
            }
            foreach ((array) ($planet['specialties'] ?? []) as $specialty) {
                $extras[] = (string) $specialty;
            }
            if (! empty($planet['legendary'])) {
                $extras[] = 'legendary';
            }

            if ($extras !== []) {
                $line .= ' (' . implode(', ', $extras) . ')';
            }

            $lines[] = $line;
        }

        foreach ((array) ($tile['stations'] ?? []) as $station) {
            $lines[] = sprintf(
                '%s %d/%d (station)',
                (string) $station['name'],
                (int) $station['resources'],
                (int) $station['influence'],
            );
        }

        if (! empty($tile['wormhole'])) {
            $lines[] = ucfirst((string) $tile['wormhole']) . ' wormhole';
        }

        if (! empty($tile['anomaly'])) {
            $lines[] = 'Anomaly: ' . (string) $tile['anomaly'];
        }

        if ($lines === []) {
            $lines[] = 'Empty system';
        }

        return implode("\n", $lines);
    }

    /**
     * Maps faction alias -> local green tile id, by faction name with a small alias table for the
     * handful of names that differ between the two datasets.
     *
     * @param  array<string, array<string, mixed>> $tiles
     * @return array<string, string>
     */
    private function indexHomeSystemTiles(array $tiles): array
    {
        $byName = [];
        foreach ($tiles as $tileId => $tile) {
            if (($tile['type'] ?? '') === 'green' && ! empty($tile['faction'])) {
                $byName[$this->normaliseName((string) $tile['faction'])] = (string) $tileId;
            }
        }

        $map = [];
        foreach ($this->factions as $alias => $faction) {
            if (isset(self::HOME_SYSTEM_TILE_ALIASES[$alias])) {
                $map[$alias] = self::HOME_SYSTEM_TILE_ALIASES[$alias];

                continue;
            }

            $key = $this->normaliseName((string) $faction['name']);

            if (isset($byName[$key])) {
                $map[$alias] = $byName[$key];
            }
        }

        return $map;
    }

    private function normaliseName(string $name): string
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $name) ?? '');

        return str_starts_with($key, 'the') ? substr($key, 3) : $key;
    }

    /**
     * @param  array<string, array<string, mixed>> $breakthroughs
     * @return array<string, array<string, mixed>>
     */
    private function breakthroughsFor(string $alias, array $breakthroughs): array
    {
        $found = [];

        foreach ($breakthroughs as $id => $record) {
            if ((string) ($record['faction'] ?? '') === $alias) {
                $found[(string) $id] = $record;
            }
        }

        return $found;
    }

    private function isExcludedFaction(string $alias): bool
    {
        if (in_array($alias, self::EXCLUDED_FACTIONS, true)) {
            return true;
        }

        // franken1..frankenN are the blank placeholder sheets the bot hands out; they hold no
        // components of their own.
        return (bool) preg_match('/^franken\d+$/', $alias);
    }

    /**
     * @param  array<int, array<string, mixed>> $records
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $records, string $key): array
    {
        $indexed = [];

        foreach ($records as $record) {
            if (isset($record[$key])) {
                $indexed[(string) $record[$key]] = $record;
            }
        }

        return $indexed;
    }

    private function warn(string $category, string $itemId, string $faction, string $reason): void
    {
        $this->warnings[] = sprintf('%s "%s" (%s): %s', $category, $itemId, $faction, $reason);
    }

    // ------------------------------------------------------------------ write

    private function write(): int
    {
        ksort($this->components);

        $files = [
            'components.json' => array_values($this->components),
            'errata.json' => array_values($this->errata),
            'sources.json' => $this->sources,
            'factions.json' => array_values($this->factions),
        ];

        $this->report();

        $differences = [];

        foreach ($files as $name => $payload) {
            $target = $this->path(self::OUTPUT_DIR . '/' . $name);
            $encoded = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";

            if ($this->check) {
                $current = is_file($target) ? (string) file_get_contents($target) : '';

                if ($current !== $encoded) {
                    $differences[] = $name;
                }

                continue;
            }

            $this->ensureDirectory(dirname($target));
            file_put_contents($target, $encoded);
            $this->out(sprintf('Wrote %s (%d entries)', self::OUTPUT_DIR . '/' . $name, count($payload)));
        }

        if ($this->check) {
            if ($differences !== []) {
                $this->out('Out of date: ' . implode(', ', $differences));

                return 1;
            }

            $this->out('Generated data matches what is committed.');
        }

        return 0;
    }

    private function report(): void
    {
        $byCategory = [];
        foreach ($this->components as $component) {
            $category = (string) $component['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }
        ksort($byCategory);

        $this->out('');
        $this->out('Components per category:');
        foreach ($byCategory as $category => $count) {
            $this->out(sprintf('  %-14s %5d', $category, $count));
        }

        $this->out(sprintf('  %-14s %5d', 'TOTAL', count($this->components)));
        $this->out(sprintf('Factions: %d, errata entries: %d', count($this->factions), count($this->errata)));

        if ($this->warnings !== []) {
            $this->out('');
            $this->out(sprintf('%d items were skipped:', count($this->warnings)));
            foreach ($this->warnings as $warning) {
                $this->out('  - ' . $warning);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create ' . $directory);
        }
    }

    private function path(string $relative): string
    {
        return $this->rootDir . '/' . $relative;
    }

    private function safeRef(): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '-', $this->ref) ?? 'master';
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

try {
    exit((new FrankenDataSync($argv))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'sync-franken-data failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
