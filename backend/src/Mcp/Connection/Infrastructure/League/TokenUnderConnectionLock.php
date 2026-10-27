<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use Closure;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que le jugement d'un code (AuthCodeRepository::isAuthCodeRevoked()) et
 * celui d'un rafraîchissement (RefreshTokenRepository::isRefreshTokenRevoked())
 * ont en commun, écrit une fois (lot C des relectures de #649) : le verrou de
 * la connexion, la relecture du jeton sous ce verrou, la connexion révoquée,
 * le jeton révoqué sur une connexion active. Chaque dépôt garde ce qui lui
 * est propre — chercher le jeton par son empreinte, sa relecture, sa
 * compromission, et après ce tronc commun, pour le rafraîchissement,
 * l'échéance, le palier et le dernier usage.
 *
 * **L'ordre est le jugement** — il ne se réordonne pas :
 *
 *  1. le jeton trouvé par son empreinte, avant le verrou — inconnu, il est
 *     mort ;
 *  2. sa connexion relue `FOR UPDATE`
 *     (McpConnectionRepositoryInterface::lockForUpdate(), le C de l'ordre
 *     C → A → R) : deux échanges d'un même code, deux rafraîchissements d'un
 *     même jeton se suivent au lieu de se croiser — disparue, le jeton est
 *     mort ;
 *  3. le jeton **relu sous ce verrou** : ce qu'une requête concurrente a
 *     validé pendant l'attente (l'échange, la rotation) se voit maintenant —
 *     disparu (purgé pendant l'attente), il est mort ;
 *  4. la connexion révoquée : mort, sans seconde révocation ni audit ;
 *  5. le jeton révoqué sur une connexion active : il a été échangé ou
 *     tourné, et revient — la compromission que le dépôt nomme est levée,
 *     que le point de jeton rattrape après le ROLLBACK pour révoquer la
 *     connexion. Avant toute échéance : un rafraîchissement tourné qui
 *     revient sur une connexion expirée mais pas encore purgée est une
 *     réutilisation aussi (`testAReusedTokenOnAnExpiredConnectionIsStillAReuse`).
 *
 * Exige la transaction ouverte du point de jeton : le verrou tient jusqu'à
 * son COMMIT ou son ROLLBACK.
 */
final readonly class TokenUnderConnectionLock
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
    ) {
    }

    /**
     * @template T of McpAuthorizationCode|McpRefreshToken
     *
     * @param T|null                                           $found       le jeton trouvé par son empreinte, avant le verrou
     * @param Closure(Uuid): (T|null)                          $reread      sa relecture sous le verrou de sa connexion, par son identifiant
     * @param Closure(Uuid): CompromisedMcpConnectionException $compromised la compromission que nomme le jeton révoqué sur une connexion active,
     *                                                                      pour l'identifiant de cette connexion
     *
     * @return LockedToken<T>|null `null` quand le jeton est mort ; sinon sa connexion verrouillée et le jeton relu
     *
     * @throws CompromisedMcpConnectionException quand le jeton, révoqué, revient sur une connexion active
     */
    public function judge(McpAuthorizationCode|McpRefreshToken|null $found, Closure $reread, Closure $compromised): ?LockedToken
    {
        if (null === $found) {
            return null;
        }

        $connection = $this->connections->lockForUpdate($found->connection()->getId());
        if (null === $connection) {
            return null;
        }

        $token = $reread($found->getId());
        if (null === $token) {
            return null;
        }

        if ($connection->isRevoked()) {
            return null;
        }

        if ($token->isRevoked()) {
            throw $compromised($connection->getId());
        }

        return new LockedToken($connection, $token);
    }
}
