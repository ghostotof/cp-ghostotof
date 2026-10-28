<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\McpConnectionNotFoundException;
use App\Shared\Domain\Security\AccountReference;
use Symfony\Component\Uid\Uuid;

/**
 * « Mes connexions MCP » (#650) : ce que la personne du palier de confiance
 * fait de ses propres connexions. Le contrôleur n'en connaît que cette
 * interface.
 */
interface OwnMcpConnectionsInterface
{
    /**
     * Toutes les connexions du compte — actives, expirées, révoquées que la
     * purge n'a pas encore supprimées —, la plus récemment octroyée d'abord,
     * avec leur état à l'instant de l'horloge. Une requête.
     *
     * @return list<OwnMcpConnectionView>
     */
    public function of(AccountReference $account): array;

    /**
     * La personne révoque l'une de ses connexions (#650), cause `person` : le
     * client MCP est refusé à sa requête suivante.
     *
     * - révoquée par cet appel : écrite, puis auditée (`mcp-connection-revoked`) ;
     * - **déjà révoquée, mais à elle** : rien n'est écrit ni audité, la
     *   première révocation garde son instant et sa cause (DELETE idempotent) ;
     * - échue mais pas encore marquée par la purge : révoquée `person`, auditée
     *   (décision de l'auteur, question 6 du plan).
     *
     * @throws McpConnectionNotFoundException la connexion n'est pas à ce compte, ou n'existe pas — le même refus pour les deux
     */
    public function revoke(AccountReference $account, Uuid $connectionId): void;
}
