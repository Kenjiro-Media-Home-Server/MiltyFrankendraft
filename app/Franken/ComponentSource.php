<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * A body of content components can come from: the base game, an expansion, a codex, or one of the
 * community sets. Generated into data/franken/sources.json alongside the components themselves, so
 * the list always reflects what is actually available to draft.
 *
 * Franken mode deliberately does not reuse App\TwilightImperium\Edition: that enum has five fixed
 * cases for the Milty generator, while Franken draws on every source the component database knows.
 */
class ComponentSource
{
    private const DATA_FILE = 'data/franken/sources.json';

    public const CANAL_OFFICIAL = 'official';

    public const CANAL_COMMUNITY = 'community';

    /** @var array<string, self>|null */
    private static ?array $sources = null;

    public function __construct(
        public readonly string $source,
        public readonly string $name,
        public readonly string $canal,
        public readonly int $componentCount,
        public readonly bool $contributes,
    ) {
    }

    /** @return array<string, self> keyed by source alias */
    public static function all(): array
    {
        if (self::$sources === null) {
            $raw = json_decode((string) file_get_contents(self::DATA_FILE), true);
            $sources = [];

            foreach ((array) $raw as $record) {
                $source = new self(
                    (string) $record['source'],
                    (string) $record['name'],
                    (string) $record['canal'],
                    (int) ($record['components'] ?? 0),
                    (bool) ($record['contributes'] ?? false),
                );

                $sources[$source->source] = $source;
            }

            self::$sources = $sources;
        }

        return self::$sources;
    }

    /**
     * Only sources that actually hold draftable components are worth offering: sources.json also
     * describes action-card and objective packs that contribute nothing to a Franken faction.
     *
     * @return array<string, self>
     */
    public static function draftable(): array
    {
        return array_filter(self::all(), static fn (self $source) => $source->contributes);
    }

    /** @return array<string, self> */
    public static function official(): array
    {
        return array_filter(self::draftable(), static fn (self $source) => $source->isOfficial());
    }

    /** @return array<string, self> */
    public static function community(): array
    {
        return array_filter(self::draftable(), static fn (self $source) => ! $source->isOfficial());
    }

    /** The sources a new draft starts with checked. */
    public static function defaultSelection(): array
    {
        return ['base', 'pok', 'codex1', 'codex2', 'codex3', 'codex4'];
    }

    public static function exists(string $source): bool
    {
        return isset(self::all()[$source]);
    }

    public function isOfficial(): bool
    {
        return $this->canal === self::CANAL_OFFICIAL;
    }

    /** Lets tests run against a small hand-built source list. */
    public static function useSources(?array $sources): void
    {
        self::$sources = $sources;
    }
}
