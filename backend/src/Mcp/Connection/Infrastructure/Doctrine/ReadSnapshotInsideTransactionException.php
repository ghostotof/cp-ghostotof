<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use LogicException;

/**
 * Un instantané de lecture a été demandé dans une transaction déjà ouverte
 * (#650, CR3 de la seconde relecture) : un défaut de l'appelant.
 *
 * PostgreSQL ne laisse choisir le niveau d'isolation qu'avant la première
 * requête d'une transaction. Imbriqué, l'« instantané » ne serait qu'un point
 * de sauvegarde au niveau de la transaction englobante — lecture validée, donc
 * rien de ce que l'appelant croit obtenir. D'où un refus avant de lire, sous un
 * nom, sans mapping HTTP : un 500 `critical`, comme les verrous de l'application
 * (#526, #532).
 */
final class ReadSnapshotInsideTransactionException extends LogicException
{
    public static function create(): self
    {
        return new self('Un instantané de lecture se prend hors de toute transaction : imbriqué, il ne garantirait pas que les lectures voient le même instant.');
    }
}
