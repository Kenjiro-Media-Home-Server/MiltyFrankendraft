<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * The kinds of thing a Franken draft deals out.
 *
 * The case names match the category names AsyncTI4 uses, because the Franken errata in
 * data/franken/errata.json addresses components as "CATEGORY:itemId". Keeping them identical
 * means errata data maps onto components with no translation layer.
 */
enum DraftCategory: string
{
    case ABILITY = 'ABILITY';
    case TECH = 'TECH';
    case AGENT = 'AGENT';
    case COMMANDER = 'COMMANDER';
    case HERO = 'HERO';
    case MECH = 'MECH';
    case FLAGSHIP = 'FLAGSHIP';
    case PN = 'PN';
    case HOMESYSTEM = 'HOMESYSTEM';
    case STARTINGTECH = 'STARTINGTECH';
    case STARTINGFLEET = 'STARTINGFLEET';
    case COMMODITIES = 'COMMODITIES';
    case BLUETILE = 'BLUETILE';
    case REDTILE = 'REDTILE';
    case BREAKTHROUGH = 'BREAKTHROUGH';
    case DRAFTORDER = 'DRAFTORDER';

    /**
     * Categories that appear in the upstream errata but that this mode does not draft.
     * Errata may point a swap or a grant at one of these; the errata engine skips such
     * references instead of treating them as broken data.
     *
     * @return array<int, string>
     */
    public static function outOfScopeCategories(): array
    {
        return ['PLOT', 'MAHACTKING', 'UNIT', 'FACTION'];
    }

    /**
     * Draft order in the UI: the faction sheet first, then the leaders, then the map pieces.
     *
     * @return array<int, self>
     */
    public static function displayOrder(): array
    {
        return [
            self::ABILITY,
            self::TECH,
            self::STARTINGTECH,
            self::STARTINGFLEET,
            self::COMMODITIES,
            self::AGENT,
            self::COMMANDER,
            self::HERO,
            self::MECH,
            self::FLAGSHIP,
            self::BREAKTHROUGH,
            self::PN,
            self::HOMESYSTEM,
            self::BLUETILE,
            self::REDTILE,
            self::DRAFTORDER,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::ABILITY => 'Abilities',
            self::TECH => 'Faction Techs',
            self::AGENT => 'Agents',
            self::COMMANDER => 'Commanders',
            self::HERO => 'Heroes',
            self::MECH => 'Mechs',
            self::FLAGSHIP => 'Flagships',
            self::PN => 'Promissory Notes',
            self::HOMESYSTEM => 'Home Systems',
            self::STARTINGTECH => 'Starting Techs',
            self::STARTINGFLEET => 'Starting Fleets',
            self::COMMODITIES => 'Commodities',
            self::BLUETILE => 'Blue-backed Tiles',
            self::REDTILE => 'Red-backed Tiles',
            self::BREAKTHROUGH => 'Breakthroughs',
            self::DRAFTORDER => 'Draft Order',
        };
    }

    /** Singular label, for "you already have a home system" style messages. */
    public function singularLabel(): string
    {
        return match ($this) {
            self::ABILITY => 'ability',
            self::TECH => 'faction tech',
            self::AGENT => 'agent',
            self::COMMANDER => 'commander',
            self::HERO => 'hero',
            self::MECH => 'mech',
            self::FLAGSHIP => 'flagship',
            self::PN => 'promissory note',
            self::HOMESYSTEM => 'home system',
            self::STARTINGTECH => 'starting tech',
            self::STARTINGFLEET => 'starting fleet',
            self::COMMODITIES => 'commodity value',
            self::BLUETILE => 'blue tile',
            self::REDTILE => 'red tile',
            self::BREAKTHROUGH => 'breakthrough',
            self::DRAFTORDER => 'draft order',
        };
    }

    /**
     * How many items of this category go into every bag, in standard Franken.
     * Bag size is the sum of these: 33 with every category active.
     */
    public function defaultBagCount(): int
    {
        return match ($this) {
            self::ABILITY, self::BLUETILE => 3,
            self::DRAFTORDER => 1,
            default => 2,
        };
    }

    /** How many items of this category a player may hold, in standard Franken. */
    public function defaultHandLimit(): int
    {
        return match ($this) {
            self::ABILITY, self::BLUETILE => 3,
            self::TECH, self::REDTILE => 2,
            default => 1,
        };
    }

    /**
     * How many items of this category end up on the finished faction sheet.
     *
     * In standard Franken this equals the hand limit, so the assembly stage has nothing to trim.
     * It is a separate number because the classic "draft 4 abilities, discard 1" phrasing and the
     * powered/overdraft variants hand out more than the player finally uses.
     */
    public function defaultFinalLimit(): int
    {
        return $this->defaultHandLimit();
    }

    /** Categories whose items are map tiles rather than cards. */
    public function isTile(): bool
    {
        return $this === self::BLUETILE || $this === self::REDTILE || $this === self::HOMESYSTEM;
    }

    /** Tiles that make up a player's slice (the home system sits outside the slice). */
    public function isSliceTile(): bool
    {
        return $this === self::BLUETILE || $this === self::REDTILE;
    }

    /**
     * Breakthroughs only exist in Thunder's Edge era content, and draft order is only drafted when
     * the game is not using a preset seating order. Both are decided per draft, so the settings
     * make the final call; this is the static part of that decision.
     */
    public function requiresBreakthroughContent(): bool
    {
        return $this === self::BREAKTHROUGH;
    }
}
