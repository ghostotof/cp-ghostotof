<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Closure;

/**
 * Une transaction de base de données, vue de l'application (#647) : tout ce
 * que l'opération écrit est validé ensemble, ou rien. L'accord d'un
 * consentement y crée la connexion et, par league, son code : jamais l'une
 * sans l'autre ; le point de jeton y émet et révoque (TokenEndpoint) ; la
 * révocation d'un rafraîchissement y emporte son jeton d'accès.
 *
 * Une interface plutôt que l'EntityManager : la couche Application ne dépend
 * pas de Doctrine (Infrastructure\Doctrine\DoctrineTransaction).
 */
interface TransactionInterface
{
    /**
     * Exécute `$operation` dans une transaction, validée à son retour,
     * annulée si elle lève (l'exception est relancée telle quelle).
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function run(Closure $operation): mixed;
}
