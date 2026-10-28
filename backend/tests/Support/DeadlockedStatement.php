<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Les exceptions de la base qu'un test unitaire fait lever, sans base : un
 * interblocage (`40P01`, que DBAL range en {@see DeadlockException}) et une
 * autre erreur du pilote (`55P03`, l'attente d'un verrou dépassée, que DBAL
 * ne range dans aucune sous-classe).
 *
 * Le message est celui d'un pilote sans paramètre : aucune donnée n'y figure.
 */
final class DeadlockedStatement
{
    /** SQLSTATE `deadlock_detected`. */
    public const string SQLSTATE = '40P01';

    /** Une nouvelle instance à chaque appel : deux interblocages se distinguent par identité. */
    public static function exception(): DeadlockException
    {
        return new DeadlockException(self::driverException('deadlock detected', self::SQLSTATE), null);
    }

    /** Une erreur du pilote que DBAL ne range pas en {@see DeadlockException}. */
    public static function otherDriverException(): DriverException
    {
        return new DriverException(self::driverException('lock timeout', '55P03'), null);
    }

    private static function driverException(string $message, string $sqlState): AbstractException
    {
        return new class($message, $sqlState) extends AbstractException {
        };
    }
}
