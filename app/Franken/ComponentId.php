<?php

declare(strict_types=1);

namespace App\Franken;

/**
 * Addresses one draftable thing as "CATEGORY:itemId", e.g. "ABILITY:mitosis" or "BLUETILE:26".
 *
 * This is the same notation the upstream Franken errata uses to point at components, so errata
 * references need no translation.
 */
class ComponentId implements \Stringable
{
    private function __construct(
        public readonly DraftCategory $category,
        public readonly string $itemId,
    ) {
    }

    public static function make(DraftCategory $category, string $itemId): self
    {
        return new self($category, trim($itemId));
    }

    /**
     * @throws \InvalidArgumentException when the string is malformed or names a category this mode
     *                                   does not draft
     */
    public static function fromString(string $value): self
    {
        $id = self::tryFromString($value);

        if ($id === null) {
            throw new \InvalidArgumentException('Not a draftable component id: ' . $value);
        }

        return $id;
    }

    /**
     * Returns null instead of throwing for ids this mode cannot represent. Errata reference plot
     * cards and Mahact kings, which Franken v1 does not draft; those references are skipped rather
     * than treated as broken data.
     */
    public static function tryFromString(string $value): ?self
    {
        $parts = explode(':', trim($value), 2);

        if (count($parts) !== 2 || trim($parts[1]) === '') {
            return null;
        }

        $category = DraftCategory::tryFrom($parts[0]);

        if ($category === null) {
            return null;
        }

        return new self($category, trim($parts[1]));
    }

    public function value(): string
    {
        return $this->category->value . ':' . $this->itemId;
    }

    public function __toString(): string
    {
        return $this->value();
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
    }
}
