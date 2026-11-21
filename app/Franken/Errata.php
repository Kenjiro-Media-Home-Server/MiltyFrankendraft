<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * The Franken errata: the community's per-component adjustments to what may be drafted and what a
 * card does in a Franken game. Generated into data/franken/errata.json from the upstream
 * franken_errata data.
 *
 * Every rule here is data. Nothing about a specific card is hardcoded.
 */
class Errata
{
    private const DATA_FILE = 'data/franken/errata.json';

    private static ?self $instance = null;

    /** @param array<string, array<string, mixed>> $entries keyed by "CATEGORY:itemId" */
    public function __construct(
        private readonly array $entries = [],
    ) {
    }

    public static function load(): self
    {
        if (self::$instance === null) {
            $raw = json_decode((string) file_get_contents(self::DATA_FILE), true);
            $entries = [];

            foreach ((array) $raw as $entry) {
                $entries[(string) $entry['id']] = $entry;
            }

            self::$instance = new self($entries);
        }

        return self::$instance;
    }

    /** Lets tests swap in a handful of entries instead of the whole generated file. */
    public static function useInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /** The item is never dealt into a bag. */
    public function isUndraftable(ComponentId $id): bool
    {
        return (bool) ($this->entries[$id->value()]['undraftable'] ?? false);
    }

    /**
     * A Franken-only replacement card that belongs in the pool even though the printed version it
     * replaces is undraftable, and regardless of whether its own source was enabled.
     */
    public function isAlwaysAddedToPool(ComponentId $id): bool
    {
        return (bool) ($this->entries[$id->value()]['always_add_to_pool'] ?? false);
    }

    /**
     * Items the holder may take instead of this one, decided during assembly.
     *
     * Only same-category swaps are offered: the assembly stage, the slice builder and the final
     * per-category counts all assume a held item keeps its category, and the upstream errata data
     * occasionally points a swap at a different category (e.g. a home system swapped for an
     * ability). Those cross-category entries are dropped here rather than passed through.
     *
     * @return array<int, ComponentId>
     */
    public function swapsFor(ComponentId $id): array
    {
        return array_values(array_filter(
            $this->referencedIds($id, 'optional_swaps'),
            static fn (ComponentId $option) => $option->category === $id->category,
        ));
    }

    /**
     * Items the holder also receives for free. Example: Letani Warrior II hands out Mitosis.
     *
     * @return array<int, ComponentId>
     */
    public function grantsFor(ComponentId $id): array
    {
        return $this->referencedIds($id, 'additional_components');
    }

    /** A Franken-specific rewrite of the card's rules text, if one exists. */
    public function textFor(ComponentId $id): ?string
    {
        $text = $this->entries[$id->value()]['alternate_text'] ?? null;

        return $text === null ? null : (string) $text;
    }

    public function hasEntryFor(ComponentId $id): bool
    {
        return isset($this->entries[$id->value()]);
    }

    /**
     * Every component that some errata entry forces into a pool.
     *
     * @return array<int, ComponentId>
     */
    public function alwaysAddedIds(): array
    {
        $ids = [];

        foreach ($this->entries as $key => $entry) {
            if (empty($entry['always_add_to_pool'])) {
                continue;
            }

            $id = ComponentId::tryFromString((string) $key);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * References to categories this mode does not draft (plot cards, Mahact kings) are skipped
     * rather than treated as broken data — see DraftCategory::outOfScopeCategories().
     *
     * @return array<int, ComponentId>
     */
    private function referencedIds(ComponentId $id, string $field): array
    {
        $ids = [];

        foreach ((array) ($this->entries[$id->value()][$field] ?? []) as $reference) {
            $referenced = ComponentId::tryFromString((string) $reference);

            if ($referenced !== null) {
                $ids[] = $referenced;
            }
        }

        return $ids;
    }
}
