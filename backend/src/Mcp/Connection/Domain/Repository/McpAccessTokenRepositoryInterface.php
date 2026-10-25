<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use DateTimeImmutable;

/**
 * Les jetons d'accès des connexions MCP, désignés par leur seule empreinte.
 * L'implémentation vit dans Infrastructure\Doctrine\McpAccessTokenRepository.
 */
interface McpAccessTokenRepositoryInterface
{
    /**
     * Une empreinte déjà prise est refusée par la base, sous la contrainte
     * McpAccessToken::HASH_UNIQUE_INDEX.
     */
    public function add(McpAccessToken $token): void;

    /**
     * Le jeton, par l'empreinte SHA-256 de son identifiant, avec sa connexion,
     * le compte et le client de celle-ci, en une seule requête (ADR 0006 D4) :
     * l'authentificateur de `/api/mcp` les lit à chaque appel, sans autre
     * aller-retour vers la base.
     */
    public function findOneByHash(string $tokenHash): ?McpAccessToken;

    /**
     * Révoque en base le jeton de cette empreinte, s'il ne l'est pas déjà : la
     * première révocation garde son instant. Une empreinte inconnue n'est pas
     * une erreur. Une instance déjà chargée n'est pas rafraîchie.
     */
    public function revokeByHash(string $tokenHash, DateTimeImmutable $at): void;
}
