<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Ouvre une seconde connexion PostgreSQL, avec les paramètres de celle de
 * l'ORM, pour observer un verrou comme le verrait une requête concurrente
 * (issue #389) : la session qui tient un advisory lock peut toujours le
 * reprendre, seule une autre session le voit pris.
 *
 * @phpstan-import-type Params from DriverManager
 */
trait OpensProbeConnection
{
    private static function openProbeBeside(Connection $connection): Connection
    {
        /** @var Params $params mêmes paramètres, donc même forme, que ceux qu'a reçus DriverManager */
        $params = $connection->getParams();

        return DriverManager::getConnection($params);
    }

    /**
     * La même connexion, sur une autre base du même serveur (issue #634 : la
     * base jetable d'un test qui valide des `ALTER DATABASE`). Le suffixe
     * `_test` de la configuration est déjà dans `dbname` : rien ne le rajoute.
     */
    private static function openProbeOn(Connection $connection, string $database): Connection
    {
        /** @var Params $params mêmes paramètres, donc même forme, que ceux qu'a reçus DriverManager */
        $params = $connection->getParams();
        $params['dbname'] = $database;

        return DriverManager::getConnection($params);
    }
}
