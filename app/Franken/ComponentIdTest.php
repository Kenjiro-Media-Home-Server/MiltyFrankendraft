<?php

declare(strict_types=1);

namespace App\Franken;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ComponentIdTest extends TestCase
{
    #[Test]
    public function itParsesACategoryAndItemId(): void
    {
        $id = ComponentId::fromString('ABILITY:mitosis');

        $this->assertSame(DraftCategory::ABILITY, $id->category);
        $this->assertSame('mitosis', $id->itemId);
        $this->assertSame('ABILITY:mitosis', $id->value());
        $this->assertSame('ABILITY:mitosis', (string) $id);
    }

    #[Test]
    public function itKeepsColonsInsideTheItemId(): void
    {
        $id = ComponentId::fromString('PN:some:weird:id');

        $this->assertSame('some:weird:id', $id->itemId);
    }

    #[Test]
    public function itComparesByValue(): void
    {
        $this->assertTrue(
            ComponentId::fromString('TECH:lw2')->equals(ComponentId::make(DraftCategory::TECH, 'lw2')),
        );
        $this->assertFalse(
            ComponentId::fromString('TECH:lw2')->equals(ComponentId::fromString('TECH:bio')),
        );
    }

    /**
     * The errata points at plot cards and Mahact kings, which this mode does not draft. Those
     * references have to be skippable rather than fatal.
     */
    #[Test]
    public function itReturnsNullForCategoriesThisModeDoesNotDraft(): void
    {
        $this->assertNull(ComponentId::tryFromString('PLOT:assail'));
        $this->assertNull(ComponentId::tryFromString('MAHACTKING:someking'));
    }

    #[Test]
    public function itReturnsNullForMalformedIds(): void
    {
        $this->assertNull(ComponentId::tryFromString('nocolon'));
        $this->assertNull(ComponentId::tryFromString('ABILITY:'));
        $this->assertNull(ComponentId::tryFromString(''));
    }

    #[Test]
    public function itThrowsWhenParsingStrictly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComponentId::fromString('PLOT:assail');
    }
}
