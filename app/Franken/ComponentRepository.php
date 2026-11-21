<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * Loads the generated component database once per request, in the same statically-cached style as
 * App\TwilightImperium\Faction::all().
 */
class ComponentRepository
{
    private const DATA_FILE = 'data/franken/components.json';

    /** @var array<string, Component>|null */
    private static ?array $components = null;

    /** @var array<string, array<int, Component>>|null */
    private static ?array $byCategory = null;

    /** @return array<string, Component> keyed by component id */
    public static function all(): array
    {
        if (self::$components === null) {
            $raw = json_decode((string) file_get_contents(self::DATA_FILE), true);
            $components = [];

            foreach ((array) $raw as $record) {
                $component = Component::fromJson($record);

                if ($component !== null) {
                    $components[$component->id->value()] = $component;
                }
            }

            self::$components = $components;
            self::$byCategory = null;
        }

        return self::$components;
    }

    /** @return array<int, Component> */
    public static function byCategory(DraftCategory $category): array
    {
        if (self::$byCategory === null) {
            $grouped = [];

            foreach (self::all() as $component) {
                $grouped[$component->category()->value][] = $component;
            }

            self::$byCategory = $grouped;
        }

        return self::$byCategory[$category->value] ?? [];
    }

    public static function find(ComponentId $id): ?Component
    {
        // Draft order positions are pure seating numbers rather than published cards, so they are
        // made up on the spot instead of living in the generated data.
        if ($id->category === DraftCategory::DRAFTORDER) {
            return self::draftOrderComponent($id);
        }

        return self::all()[$id->value()] ?? null;
    }

    public static function findByString(string $id): ?Component
    {
        $componentId = ComponentId::tryFromString($id);

        return $componentId === null ? null : self::find($componentId);
    }

    /**
     * The seating positions for a table of the given size, as draftable components.
     *
     * @return array<int, Component>
     */
    public static function draftOrderComponents(int $playerCount): array
    {
        $positions = [];

        for ($position = 1; $position <= $playerCount; $position++) {
            $positions[] = self::draftOrderComponent(
                ComponentId::make(DraftCategory::DRAFTORDER, (string) $position),
            );
        }

        return $positions;
    }

    /** Lets tests run against a small hand-built component set. */
    public static function useComponents(?array $components): void
    {
        self::$components = $components;
        self::$byCategory = null;
    }

    private static function draftOrderComponent(ComponentId $id): Component
    {
        $position = (int) $id->itemId;

        return new Component(
            $id,
            $position === 1 ? 'Speaker' : ordinal($position) . ' pick',
            null,
            null,
            'base',
            $position === 1
                ? 'You are the speaker and pick first.'
                : sprintf('You are %s in the draft order.', ordinal($position)),
            meta: ['position' => $position],
        );
    }
}
