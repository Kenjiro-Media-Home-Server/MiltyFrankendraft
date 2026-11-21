<?php

declare(strict_types=1);

namespace App\Franken\Exceptions;

class InvalidFrankenSettingsException extends \Exception
{
    public static function notAllPlayerNamesAreFilled(): self
    {
        return new self('Not all player names are filled in');
    }

    public static function playerNamesNotUnique(): self
    {
        return new self('Player names are not unique');
    }

    public static function notEnoughPlayers(int $minimum): self
    {
        return new self(sprintf('A Franken draft needs at least %d players', $minimum));
    }

    public static function tooManyPlayers(int $maximum): self
    {
        return new self(sprintf('A Franken draft supports at most %d players', $maximum));
    }

    public static function noSourcesSelected(): self
    {
        return new self('Select at least one content source to draft from');
    }

    public static function unknownSource(string $source): self
    {
        return new self('Unknown content source: ' . $source);
    }

    public static function invalidSeed(): self
    {
        return new self('Seed is out of range');
    }

    public static function invalidPickCounts(): self
    {
        return new self('Picks per round must be at least 1');
    }
}
