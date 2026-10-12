<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Doctrine\DBAL\Exception\DriverException;
use RuntimeException;
use Throwable;

/**
 * Le point de sauvegarde d'un verrou imbriqué n'a pas pu être annulé, et la
 * session a été fermée (issue #526) : la transaction de l'appelant est
 * perdue avec elle. Elle ne remonte qu'en imbrication. Au premier niveau,
 * la cause remonte telle quelle (voir PostgresLockedTransaction).
 *
 * La cause est en `previous`, pour que le journal dise ce qui s'est passé.
 * Le message ne nomme que des classes et le SQLSTATE du ROLLBACK, jamais un
 * message qui pourrait citer un paramètre de requête. Pas de mapping HTTP :
 * un 500 `critical`.
 */
final class LockedTransactionRollbackFailedException extends RuntimeException
{
    public static function insideOuterTransaction(Throwable $cause, DriverException $rollbackFailure): self
    {
        return new self(\sprintf(
            'Le ROLLBACK du point de sauvegarde a échoué (%s, SQLSTATE %s) après %s : session fermée, la transaction englobante est perdue.',
            $rollbackFailure::class,
            $rollbackFailure->getSQLState() ?? 'inconnu',
            $cause::class,
        ), 0, $cause);
    }
}
