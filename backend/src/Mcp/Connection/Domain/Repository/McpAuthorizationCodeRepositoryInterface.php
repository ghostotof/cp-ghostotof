<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use DateTimeImmutable;

/**
 * Les codes d'autorisation des connexions MCP, désignés par leur seule
 * empreinte. L'implémentation vit dans
 * Infrastructure\Doctrine\McpAuthorizationCodeRepository.
 */
interface McpAuthorizationCodeRepositoryInterface
{
    /**
     * Une empreinte déjà prise est refusée par la base, sous la contrainte
     * McpAuthorizationCode::HASH_UNIQUE_INDEX.
     */
    public function add(McpAuthorizationCode $code): void;

    /** Le code, avec sa connexion, par l'empreinte SHA-256 de son identifiant. */
    public function findOneByHash(string $codeHash): ?McpAuthorizationCode;

    /**
     * Révoque en base le code de cette empreinte, s'il ne l'est pas déjà : la
     * première révocation garde son instant. Une empreinte inconnue n'est pas
     * une erreur. Une instance déjà chargée n'est pas rafraîchie.
     */
    public function revokeByHash(string $codeHash, DateTimeImmutable $at): void;

    /**
     * Supprime les codes morts (#649, la purge quotidienne) : échus
     * (`expires_at <= $now`), ou d'une connexion révoquée. Un code échangé
     * (révoqué) mais non échu d'une connexion active reste : sa ligne est ce
     * qui fait reconnaître un rejeu dans ses dix minutes. Rend le nombre de
     * lignes supprimées.
     */
    public function deleteDead(DateTimeImmutable $now): int;
}
