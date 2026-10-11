<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

use UnexpectedValueException;

/**
 * Une valeur relue d'un pool de cache ne porte pas un MAC valable (issue #468) :
 * altérée, écrite hors de l'application, ou signée sous une autre clé.
 *
 * Les adaptateurs de Symfony attrapent toute `\Exception` levée à la lecture et
 * rendent un cache vide, journalisé en `warning` par CacheItem::log : la valeur
 * n'est jamais désérialisée, la lecture continue. C'est voulu, une absence de
 * cache se reconstruit toute seule. Le message nomme la cause, jamais la valeur.
 */
final class CacheValueMacRefusedException extends UnexpectedValueException
{
    public static function invalid(): self
    {
        return new self('Valeur de cache refusée : MAC absent ou faux (issue #468).');
    }
}
