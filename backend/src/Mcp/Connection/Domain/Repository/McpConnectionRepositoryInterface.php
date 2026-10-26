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
     * La connexion relue **sous le verrou de sa ligne** (`FOR UPDATE`), pour
     * la transaction en cours, avant qu'un jeton en soit jugé ou émis (#649) :
     * deux rafraîchissements simultanés d'un même jeton se suivent au lieu de
     * se croiser — le second relit ce que le premier a validé, la rotation
     * faite, et reconnaît la réutilisation. Une instance déjà chargée est
     * rafraîchie sur place ; `null` si la ligne a disparu (l'instance périmée
     * détachée). Exige une transaction ouverte.
     */
    public function lockForIssuance(Uuid $connectionId): ?McpConnection;

    /** Écrit en base ce qui a changé sur la connexion (le dernier usage). */
    public function save(McpConnection $connection): void;

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
