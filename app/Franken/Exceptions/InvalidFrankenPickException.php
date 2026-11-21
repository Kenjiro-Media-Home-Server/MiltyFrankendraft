<?php

declare(strict_types=1);

namespace App\Franken\Exceptions;

use App\Franken\DraftCategory;

class InvalidFrankenPickException extends \Exception
{
    public static function notInBagDraft(): self
    {
        return new self('The bag draft is over.');
    }

    public static function roundHasMovedOn(int $currentRound): self
    {
        return new self(sprintf(
            'This round is already over — the bags have moved on to round %d.',
            $currentRound,
        ));
    }

    public static function itemNotInBag(string $item): self
    {
        return new self('That item is not in your bag any more: ' . $item);
    }

    public static function quotaSpent(): self
    {
        return new self('You have already taken everything you may take from this bag.');
    }

    public static function categoryFull(DraftCategory $category, int $limit): self
    {
        return new self(sprintf(
            'You already hold %d %s and cannot take another.',
            $limit,
            $limit === 1 ? $category->singularLabel() : $category->label(),
        ));
    }

    public static function handIsFull(): self
    {
        return new self('Your hand is already full.');
    }

    public static function itemNotPickedThisRound(string $item): self
    {
        return new self('You did not take that item this round: ' . $item);
    }

    public static function stillHasPicks(int $remaining): self
    {
        return new self(sprintf(
            'You still have %d pick%s available from this bag and must take %s before passing it on.',
            $remaining,
            $remaining === 1 ? '' : 's',
            $remaining === 1 ? 'it' : 'them',
        ));
    }

    public static function cannotUnpickAfterPassing(): self
    {
        return new self('You have already passed your bag on; ask the admin to undo the pick.');
    }

    public static function playerNotInDraft(string $playerId): self
    {
        return new self('No player in this draft with id ' . $playerId);
    }

    public static function nothingToUndo(): self
    {
        return new self('There is nothing to undo.');
    }

    public static function nothingLeftToGrant(): self
    {
        return new self('Nothing left in circulation can fill the gaps in that hand.');
    }

    public static function cannotRegenerateStartedDraft(): self
    {
        return new self('The draft has already started, so it cannot be re-dealt.');
    }
}
