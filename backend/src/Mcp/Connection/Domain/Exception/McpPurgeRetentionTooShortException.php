<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use DomainException;

/**
 * La rétention des connexions MCP révoquées ou expirées demandée à la purge
 * (#649, `app:mcp:purge --older-than`) est inférieure à un jour : le seuil
 * calculé (maintenant moins la durée) est postérieur à « maintenant moins un
 * jour ».
 *
 * Le plancher est celui de la purge des invitations
 * ({@see \App\Security\User\Domain\Exception\InvalidPurgeRetentionException}) :
 * le CronJob n'utilise que la valeur de configuration (six mois), seul un
 * lancement manuel imprudent est concerné, et il supprimerait d'un coup les
 * connexions révoquées dans l'heure — leur trace en base, que l'audit ne
 * remplace pas. Levée par le purgeur avant toute écriture ; la commande la
 * rend en `Command::INVALID`. Jamais une erreur HTTP : aucune entrée
 * `framework.exceptions`.
 */
final class McpPurgeRetentionTooShortException extends DomainException
{
    public static function belowOneDay(): self
    {
        return new self('La rétention des connexions MCP révoquées doit être d\'au moins un jour.');
    }
}
