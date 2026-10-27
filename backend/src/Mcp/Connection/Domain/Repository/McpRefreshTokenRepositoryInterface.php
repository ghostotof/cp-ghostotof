<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les jetons de rafraîchissement des connexions MCP, désignés par leur seule
 * empreinte. L'implémentation vit dans
 * Infrastructure\Doctrine\McpRefreshTokenRepository.
 */
interface McpRefreshTokenRepositoryInterface
{
    /**
     * Une empreinte déjà prise est refusée par la base, sous la contrainte
     * McpRefreshToken::HASH_UNIQUE_INDEX.
     */
    public function add(McpRefreshToken $token): void;

    /**
     * Le jeton, avec sa connexion et son jeton d'accès (chargé, jamais un
     * proxy : sa ligne peut disparaître sous la purge entre cette lecture et
     * une transaction, et son empreinte doit rester lisible), par l'empreinte
     * SHA-256 de son identifiant, tel que l'EntityManager le tient : une
     * instance déjà chargée n'est pas rafraîchie — la relecture sous verrou
     * est rereadUnderConnectionLock().
     */
    public function findOneByHash(string $tokenHash): ?McpRefreshToken;

    /**
     * Le jeton relu **sous le verrou de sa connexion**, que l'appelant tient
     * (McpConnectionRepositoryInterface::lockForUpdate()), pour la transaction
     * en cours (#649) : la relecture sous verrou du projet, une instance déjà
     * chargée rafraîchie sur place — la rotation qu'une requête concurrente a
     * validée pendant l'attente se voit —, `null` si la ligne a disparu
     * (l'instance périmée détachée, #560). Ne verrouille rien elle-même : le
     * verrou est celui de la connexion.
     */
    public function rereadUnderConnectionLock(Uuid $tokenId): ?McpRefreshToken;

    /**
     * Révoque en base le jeton de cette empreinte, s'il ne l'est pas déjà : la
     * première révocation garde son instant. Une empreinte inconnue n'est pas
     * une erreur. Une instance déjà chargée n'est pas rafraîchie.
     */
    public function revokeByHash(string $tokenHash, DateTimeImmutable $at): void;

    /**
     * Supprime les jetons morts (#649, la purge quotidienne) : échus
     * (`expires_at <= $now`, exclusif comme leur jugement), ou d'une connexion
     * révoquée, que plus rien n'accepte. **Un jeton tourné (révoqué) mais non
     * échu d'une connexion active reste** : sa ligne est ce qui fait reconnaître
     * sa réutilisation jusqu'à son échéance. Rend le nombre de lignes
     * supprimées.
     */
    public function deleteDead(DateTimeImmutable $now): int;
}
