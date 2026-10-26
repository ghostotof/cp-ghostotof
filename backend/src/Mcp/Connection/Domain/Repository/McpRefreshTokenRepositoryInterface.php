<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use DateTimeImmutable;

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

    /** Le jeton, avec sa connexion, par l'empreinte SHA-256 de son identifiant. */
    public function findOneByHash(string $tokenHash): ?McpRefreshToken;

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
