<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les connexions MCP. L'implémentation vit dans
 * Infrastructure\Doctrine\McpConnectionRepository.
 */
interface McpConnectionRepositoryInterface
{
    /** La connexion, avec son compte et son client, en une requête. */
    public function findOneById(Uuid $id): ?McpConnection;

    public function add(McpConnection $connection): void;

    /**
     * Révoque la connexion si elle ne l'est pas encore (#649) : l'instant et
     * la cause écrits ensemble, une seule fois — une connexion déjà révoquée
     * garde sa première révocation. Une instruction SQL, hors de l'unité de
     * travail : valable après une transaction annulée qui a fermé
     * l'EntityManager. Une instance déjà chargée n'est pas rafraîchie (comme
     * `revokeByHash()` des jetons) ; les lignes des codes et jetons de la
     * connexion ne sont pas touchées — tout lecteur juge la connexion.
     *
     * @return RevokedConnection|null ce qui a été révoqué ; `null` si la connexion est inconnue ou déjà révoquée
     */
    public function revokeActive(Uuid $connectionId, RevocationCause $cause, DateTimeImmutable $at): ?RevokedConnection;
}
