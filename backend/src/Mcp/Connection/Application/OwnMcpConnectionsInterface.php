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
     * Les connexions du compte avec leur état à l'instant de l'horloge, la plus
     * récemment octroyée d'abord : **toutes les non révoquées** (actives, et
     * échues que la purge n'a pas encore marquées) et **les
     * {@see OwnMcpConnections::TERMINATED_LIMIT} dernières terminées**
     * (révoquées, expirées marquées) que la purge n'a pas encore supprimées.
     * `terminatedTruncated` dit s'il en existe davantage. Deux requêtes au plus,
     * quel que soit le nombre de connexions.
     */
    public function of(AccountReference $account): OwnMcpConnectionList;

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
