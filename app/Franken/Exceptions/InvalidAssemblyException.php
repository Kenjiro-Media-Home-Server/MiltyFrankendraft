<?php

declare(strict_types=1);

namespace App\Franken\Exceptions;

use App\Franken\DraftCategory;

class InvalidAssemblyException extends \Exception
{
    public static function notInAssembly(): self
    {
        return new self('The draft is not at the faction-building stage yet.');
    }

    public static function alreadyConfirmed(): self
    {
        return new self('You have already confirmed your faction.');
    }

    public static function notHeld(string $item): self
    {
        return new self('You did not draft that component: ' . $item);
    }

    public static function swapNotOffered(string $item, string $replacement): self
    {
        return new self(sprintf('%s cannot be exchanged for %s.', $item, $replacement));
    }

    public static function wrongNumberOfTiles(int $expected, int $given): self
    {
        return new self(sprintf('A slice needs exactly %d tiles, %d given.', $expected, $given));
    }

    public static function tileNotDrafted(string $tile): self
    {
        return new self('You did not draft tile ' . $tile . '.');
    }

    public static function duplicateTile(string $tile): self
    {
        return new self('Tile ' . $tile . ' is placed more than once.');
    }

    public static function wrongCategoryCount(DraftCategory $category, int $expected, int $given): self
    {
        return new self(sprintf(
            'Your faction needs %d %s, but %d are selected.',
            $expected,
            $expected === 1 ? $category->singularLabel() : $category->label(),
            $given,
        ));
    }

    public static function sliceRequired(): self
    {
        return new self('Lay out your five drafted tiles before confirming.');
    }

    public static function wrongRingForPosition(string $tile, int $displayNumber): self
    {
        return new self(sprintf(
            'Tile %s was drawn for a different ring and cannot go in slot %d.',
            $tile,
            $displayNumber,
        ));
    }
}
