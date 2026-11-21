<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * One draftable component, as generated into data/franken/components.json by
 * bin/sync-franken-data.php.
 */
class Component
{
    public function __construct(
        public readonly ComponentId $id,
        public readonly string $name,
        public readonly ?string $faction,
        public readonly ?string $factionName,
        public readonly string $source,
        public readonly string $text,
        /** The wiki page for the faction this came from; there are no per-component pages. */
        public readonly ?string $wiki = null,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
        /**
         * Homebrew sets sometimes reprint a base component. When such a set is enabled, its
         * version replaces the one it names here instead of both being draftable.
         */
        public readonly ?string $replaces = null,
    ) {
    }

    /**
     * @param  array<string, mixed> $data
     * @return self|null null when the record names a category this mode does not draft
     */
    public static function fromJson(array $data): ?self
    {
        $id = ComponentId::tryFromString((string) $data['id']);

        if ($id === null) {
            return null;
        }

        return new self(
            $id,
            (string) $data['name'],
            $data['faction'] ?? null,
            $data['faction_name'] ?? null,
            (string) $data['source'],
            (string) ($data['text'] ?? ''),
            isset($data['wiki']) ? (string) $data['wiki'] : null,
            (array) ($data['meta'] ?? []),
            isset($data['replaces']) ? (string) $data['replaces'] : null,
        );
    }

    public function category(): DraftCategory
    {
        return $this->id->category;
    }

    /** The tile this component sits on, for the three tile categories. */
    public function tileId(): ?string
    {
        $tile = $this->meta['tile'] ?? null;

        return $tile === null ? null : (string) $tile;
    }

    /**
     * The rules text to show. The Franken errata rewrites a handful of cards (Imperia, Riftmeld,
     * the Cabal hero, Muaat's starting fleet), and that rewrite wins over the printed text.
     */
    public function displayText(Errata $errata): string
    {
        return $errata->textFor($this->id) ?? $this->text;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->value(),
            'category' => $this->id->category->value,
            'item_id' => $this->id->itemId,
            'name' => $this->name,
            'faction' => $this->faction,
            'faction_name' => $this->factionName,
            'source' => $this->source,
            'text' => $this->text,
            'wiki' => $this->wiki,
            'meta' => $this->meta,
        ];
    }
}
