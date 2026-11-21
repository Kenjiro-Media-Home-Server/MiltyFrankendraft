<?php

declare(strict_types=1);

namespace App\Franken;

use App\Draft\Name;
use App\Draft\Seed;
use App\Franken\Exceptions\InvalidFrankenSettingsException;

/**
 * Everything that is decided before a Franken draft starts.
 *
 * The per-category numbers live here rather than only on DraftCategory because a draft must keep
 * playing by the rules it was created with, even if the defaults change later.
 */
class FrankenSettings
{
    public const MIN_PLAYERS = 2;

    public const MAX_PLAYERS = 8;

    public const DEFAULT_PICKS_FIRST_ROUND = 3;

    public const DEFAULT_PICKS_PER_ROUND = 2;

    public function __construct(
        /** @var array<int, string> */
        public array $playerNames,
        public Name $name,
        public Seed $seed,
        public bool $presetDraftOrder,
        /** @var array<int, string> */
        public array $sources,
        public DraftOrderMode $draftOrderMode = DraftOrderMode::DRAFTED,
        /**
         * Resolved when the draft is generated: the categories that had enough components to be
         * dealt. Empty means "not resolved yet".
         *
         * @var array<int, string>
         */
        public array $activeCategories = [],
        /**
         * Per-category overrides, keyed by category value:
         * ['ABILITY' => ['bag' => 3, 'hand' => 3, 'final' => 3]]
         *
         * @var array<string, array<string, int>>
         */
        public array $limits = [],
        public int $picksFirstRound = self::DEFAULT_PICKS_FIRST_ROUND,
        public int $picksPerRound = self::DEFAULT_PICKS_PER_ROUND,
        public SliceAssemblyMode $sliceAssemblyMode = SliceAssemblyMode::DRAFT,
    ) {
    }

    public function playerCount(): int
    {
        return count($this->playerNames);
    }

    /**
     * The categories this draft deals. Before the pools have been built this is everything the
     * settings allow; afterwards it is what the enabled sources could actually fill.
     *
     * @return array<int, DraftCategory>
     */
    public function categories(): array
    {
        if ($this->activeCategories !== []) {
            $resolved = [];

            foreach ($this->activeCategories as $value) {
                $category = DraftCategory::tryFrom($value);

                if ($category !== null) {
                    $resolved[] = $category;
                }
            }

            return $resolved;
        }

        return array_values(array_filter(
            DraftCategory::displayOrder(),
            fn (DraftCategory $category) => $this->allowsCategory($category),
        ));
    }

    /** Seating order is only a drafted category when the game is not using a preset order. */
    public function allowsCategory(DraftCategory $category): bool
    {
        if ($category === DraftCategory::DRAFTORDER) {
            return $this->draftOrderMode === DraftOrderMode::DRAFTED;
        }

        return true;
    }

    public function isActive(DraftCategory $category): bool
    {
        return in_array($category, $this->categories(), true);
    }

    public function bagCount(DraftCategory $category): int
    {
        return $this->limit($category, 'bag', $category->defaultBagCount());
    }

    public function handLimit(DraftCategory $category): int
    {
        return $this->limit($category, 'hand', $category->defaultHandLimit());
    }

    public function finalLimit(DraftCategory $category): int
    {
        return $this->limit($category, 'final', $category->defaultFinalLimit());
    }

    /** How many items each bag holds: 33 in standard Franken with every category active. */
    public function bagSize(): int
    {
        $size = 0;

        foreach ($this->categories() as $category) {
            $size += $this->bagCount($category);
        }

        return $size;
    }

    /** How many items a finished hand holds: 22 in standard Franken with every category active. */
    public function handSize(): int
    {
        $size = 0;

        foreach ($this->categories() as $category) {
            $size += $this->handLimit($category);
        }

        return $size;
    }

    /** How many picks a player gets from the bag in front of them this round. */
    public function quotaForRound(int $round): int
    {
        return $round <= 1 ? $this->picksFirstRound : $this->picksPerRound;
    }

    /** @throws InvalidFrankenSettingsException */
    public function validate(): bool
    {
        if (! $this->seed->isValid()) {
            throw InvalidFrankenSettingsException::invalidSeed();
        }

        $names = array_map(static fn (string $name) => trim($name), $this->playerNames);

        foreach ($names as $name) {
            if ($name === '') {
                throw InvalidFrankenSettingsException::notAllPlayerNamesAreFilled();
            }
        }

        if (count(array_unique($names)) !== count($names)) {
            throw InvalidFrankenSettingsException::playerNamesNotUnique();
        }

        if (count($names) < self::MIN_PLAYERS) {
            throw InvalidFrankenSettingsException::notEnoughPlayers(self::MIN_PLAYERS);
        }

        if (count($names) > self::MAX_PLAYERS) {
            throw InvalidFrankenSettingsException::tooManyPlayers(self::MAX_PLAYERS);
        }

        if ($this->sources === []) {
            throw InvalidFrankenSettingsException::noSourcesSelected();
        }

        foreach ($this->sources as $source) {
            if (! ComponentSource::exists($source)) {
                throw InvalidFrankenSettingsException::unknownSource($source);
            }
        }

        if ($this->picksFirstRound < 1 || $this->picksPerRound < 1) {
            throw InvalidFrankenSettingsException::invalidPickCounts();
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'mode' => 'franken',
            'players' => array_values($this->playerNames),
            'name' => (string) $this->name,
            'seed' => $this->seed->getValue(),
            'preset_draft_order' => $this->presetDraftOrder,
            'draft_order_mode' => $this->draftOrderMode->value,
            'sources' => array_values($this->sources),
            'active_categories' => array_values($this->activeCategories),
            'limits' => $this->limits,
            'picks_first_round' => $this->picksFirstRound,
            'picks_per_round' => $this->picksPerRound,
            'slice_assembly_mode' => $this->sliceAssemblyMode->value,
        ];
    }

    public static function fromJson(array $data): self
    {
        return new self(
            array_values((array) ($data['players'] ?? [])),
            new Name($data['name'] ?? null),
            new Seed(isset($data['seed']) ? (int) $data['seed'] : null),
            (bool) ($data['preset_draft_order'] ?? false),
            array_values((array) ($data['sources'] ?? [])),
            DraftOrderMode::tryFrom((string) ($data['draft_order_mode'] ?? 'drafted'))
                ?? DraftOrderMode::DRAFTED,
            array_values((array) ($data['active_categories'] ?? [])),
            (array) ($data['limits'] ?? []),
            (int) ($data['picks_first_round'] ?? self::DEFAULT_PICKS_FIRST_ROUND),
            (int) ($data['picks_per_round'] ?? self::DEFAULT_PICKS_PER_ROUND),
            SliceAssemblyMode::tryFrom((string) ($data['slice_assembly_mode'] ?? 'draft'))
                ?? SliceAssemblyMode::DRAFT,
        );
    }

    private function limit(DraftCategory $category, string $key, int $default): int
    {
        if (! $this->allowsCategory($category)) {
            return 0;
        }

        if ($this->activeCategories !== [] && ! in_array($category->value, $this->activeCategories, true)) {
            return 0;
        }

        return (int) ($this->limits[$category->value][$key] ?? $default);
    }
}
