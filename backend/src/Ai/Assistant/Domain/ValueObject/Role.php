<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

/** Auteur d'un message de la conversation ; les valeurs sont celles du corps JSON. */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
