<?php

declare(strict_types=1);

namespace App\Franken\Exceptions;

/**
 * Raised when the chosen content sources cannot fill every bag. The message names each category
 * that fell short so the user knows which sources to add, rather than getting a bare failure.
 */
class InsufficientComponentsException extends \Exception
{
    public function __construct(
        string $message,
        /** @var array<string, array{needed: int, available: int}> */
        public readonly array $shortfalls = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, array{needed: int, available: int}> $shortfalls */
    public static function forCategories(array $shortfalls, int $playerCount): self
    {
        $parts = [];

        foreach ($shortfalls as $category => $shortfall) {
            $parts[] = sprintf(
                '%s needs %d items but only %d are available',
                $category,
                $shortfall['needed'],
                $shortfall['available'],
            );
        }

        return new self(
            sprintf(
                'The selected sources cannot fill %d bags: %s. Enable more content sources.',
                $playerCount,
                implode('; ', $parts),
            ),
            $shortfalls,
        );
    }
}
