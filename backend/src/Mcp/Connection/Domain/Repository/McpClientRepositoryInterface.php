<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use Symfony\Component\Uid\Uuid;

/**
 * Les clients MCP enregistrés. L'implémentation vit dans
 * Infrastructure\Doctrine\McpClientRepository.
 */
interface McpClientRepositoryInterface
{
    /** Par l'identifiant public du client (`client_id` d'OAuth), jamais par sa clé UUID. */
    public function findOneByClientId(string $clientId): ?McpClient;

    /**
     * Enregistre un nouveau client. Un identifiant public déjà pris est refusé
     * par la base, sous la contrainte McpClient::CLIENT_ID_UNIQUE_INDEX.
     */
    public function add(McpClient $client): void;

    /**
     * Dépose un client déclaré par son document (#651) : le crée, ou, s'il est
     * déjà déclaré (mode `cimd`), rafraîchit son nom et ses redirections, en
     * gardant son identifiant et sa date d'enregistrement (les connexions y
     * sont attachées). Rend le client tel que la base le porte.
     *
     * Rend `null` quand l'identifiant désigne un client **pré-enregistré** :
     * saisi par une personne, il n'est jamais réécrit par un document distant.
     * La ligne n'est pas touchée dans ce cas.
     *
     * Une seule requête atomique (`INSERT … ON CONFLICT DO UPDATE … WHERE`) :
     * deux autorisations simultanées du même client ne se disputent pas la
     * clé d'unicité, et la ligne reste verrouillée jusqu'à la fin de la
     * transaction de l'appelant. **Ordre des verrous** : l'appelant (la
     * complétion d'une autorisation) la lance avant toute écriture dans
     * `mcp_connection`, jamais après — `mcp_client` avant `mcp_connection`, comme
     * partout (voir `.claude/rules/mcp-oauth-purge.md`).
     *
     * L'argument n'est qu'un porteur de valeurs : il n'est jamais persisté
     * lui-même, le client rendu est l'entité gérée.
     */
    public function saveDiscovered(McpClient $discovered): ?McpClient;

    /**
     * Vrai si l'identifiant désigne un client déclaré par document (mode
     * `cimd`). Un client pré-enregistré, ou absent, n'en est pas un : c'est ce
     * qui choisit la voie du quota de récupération
     * (ClientMetadataFetchQuotaInterface::consumeForKnownClient()).
     */
    public function isDiscovered(McpClientId $clientId): bool;

    /**
     * Tous les clients, pré-enregistrés et déclarés par document (#652), le
     * plus récemment enregistré d'abord ; à date égale, l'identifiant (UUID
     * v7) décroissant, pour un ordre stable d'une lecture à l'autre.
     *
     * Aucune pagination : la liste est celle de l'administration, bornée par
     * le nombre de clients qu'une personne saisit ou que le quota de
     * récupération des documents (#651) laisse déclarer.
     *
     * @return list<McpClient>
     */
    public function all(): array;

    /**
     * Le client désigné par sa clé UUID (jamais par son identifiant public :
     * {@see self::findOneByClientId()}), en **lecture simple** : aucun verrou,
     * aucune transaction exigée. C'est la lecture du détail du backoffice
     * (#652), et de tout appelant qui se contente de **regarder** le client.
     * Rend `null` si aucune ligne ne porte cette clé.
     *
     * Qui va **écrire** sur le client (révision, suppression) n'utilise pas
     * celle-ci mais {@see self::findOneByIdForUpdate()}, dans sa transaction :
     * une lecture simple suivie d'une écriture laisse la ligne changer ou
     * disparaître entre les deux, et le verrou est aussi ce qui fixe l'ordre
     * `mcp_client` avant `mcp_connection`.
     */
    public function findOneById(Uuid $id): ?McpClient;

    /**
     * La ligne du client relue et verrouillée en écriture (`FOR UPDATE`), pour
     * le restant de la transaction de l'appelant — qui doit l'avoir ouverte.
     * Pour une simple lecture, voir {@see self::findOneById()}.
     * Rend `null` si la ligne a disparu depuis qu'elle a été lue (l'instance
     * périmée est alors détachée). **Ordre des verrous** : `mcp_client` avant
     * `mcp_connection`, comme partout.
     */
    public function findOneByIdForUpdate(Uuid $id): ?McpClient;

    /**
     * Le client désigné par son identifiant public, relu sous un verrou
     * **partagé** (`FOR SHARE`) pour le restant de la transaction de
     * l'appelant — qui doit l'avoir ouverte. Le consentement s'y appuie : tant
     * qu'il court, ni la suppression ni la révision du client (`FOR UPDATE`)
     * ne passent, et deux consentements ne s'attendent pas. Le verrou ne
     * couvre que ce qui suit sa prise : une révision commise avant elle est
     * lue (l'instance rendue est l'état courant), et l'appelant revérifie sur
     * elle ce qu'il avait validé plus tôt, la redirection demandée en tête.
     */
    public function findOneByClientIdForShare(string $clientId): ?McpClient;

    /**
     * La nature de l'enregistrement du client désigné par son identifiant
     * public, par une instruction SQL hors de l'unité de travail : valable
     * après une transaction annulée qui a fermé l'EntityManager (l'audit d'un
     * refus au point de jeton, #652).
     *
     * @return ClientRegistration|null `null` si aucun client n'a cet identifiant
     */
    public function registrationOf(string $clientId): ?ClientRegistration;

    /**
     * Supprime le client **pour de bon** : les clés étrangères portent
     * `ON DELETE CASCADE`, la base emporte ses connexions, et par elles leurs
     * codes, jetons d'accès et jetons de rafraîchissement.
     */
    public function remove(McpClient $client): void;
}
