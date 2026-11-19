<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Security\Authentication\Application\AuditOrigin;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Application\DeadlockRetryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistrer, réviser et supprimer un client MCP depuis le backoffice (#652).
 *
 * **D-A — supprimer, pas révoquer.** La suppression retire la ligne
 * `mcp_client` pour de bon ; ses connexions, codes, jetons d'accès et de
 * rafraîchissement suivent par `ON DELETE CASCADE`.
 *  - Avantages : aucune ligne fantôme (`client_id` est libéré, et un client CIMD
 *    peut revenir à la prochaine autorisation) ; ni les dépôts ni la purge n'ont
 *    de filtre « retiré » à ajouter ; le jeton est refusé à la requête suivante,
 *    l'authentificateur Bearer relisant la base (ADR 0006 D4) ; aucune migration.
 *  - Inconvénient : les connexions coupées disparaissent aussi de « Mes
 *    connexions MCP » de leur personne, seul le journal d'audit en garde la
 *    trace.
 *  - Écartés : une suppression logique (`removed_at`, un filtre à chaque
 *    requête, `client_id` resté pris, un upsert CIMD à réanimer) ; révoquer puis
 *    garder des connexions sans client (la clé étrangère l'interdit).
 *
 * **D-B — la cause `client-removed` n'existe qu'au journal** ({@see self::REMOVAL_CAUSE}).
 * Les connexions étant supprimées, aucune ligne ne la porte jamais : pas de cas
 * dans `RevocationCause`, pas d'extension de `chk_mcp_connection_revocation_cause`.
 * Contrepartie assumée : la cause du journal n'est pas une valeur de
 * l'énuméré ({@see RevokedConnectionAudit::record()} reçoit une chaîne).
 *
 * **L'audit suit toujours le COMMIT** : un acte journalisé est un acte validé.
 * `register()` délègue à {@see McpClientRegistrarInterface}, qui audite après
 * son `flush` ; il n'est donc **jamais** enveloppé dans une transaction ici
 * (celle-ci ferait partir l'audit avant le COMMIT).
 */
final readonly class McpClientAdministrator implements McpClientAdministratorInterface
{
    /** La cause écrite au journal pour une connexion coupée par la suppression de son client (D-B). */
    public const string REMOVAL_CAUSE = 'client-removed';

    /** Constants : ils nomment ce qui n'a pas été audité, jamais la panne. */
    private const string REVISION_NOT_AUDITED = 'MCP client revision not audited.';
    private const string REMOVAL_NOT_AUDITED = 'MCP client removal not audited.';

    public function __construct(
        private McpClientRegistrarInterface $registrar,
        private McpClientRepositoryInterface $clients,
        private McpConnectionRepositoryInterface $connections,
        private TransactionInterface $transaction,
        private DeadlockRetryInterface $deadlockRetry,
        private RevokedConnectionAudit $revokedConnectionAudit,
        private FailSafeAudit $failSafeAudit,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris): McpClient
    {
        return $this->registrar->register($clientId, $name, $redirectUris, AuditOrigin::Request);
    }

    /**
     * La ligne est relue sous `FOR UPDATE` : deux révisions ne s'écrasent pas, et
     * un consentement concurrent (`FOR SHARE`) lit l'état d'avant ou d'après,
     * jamais entre les deux. Un client déclaré par son document est refusé
     * par l'entité avant toute écriture : rien n'est écrit, rien n'est audité.
     *
     * L'audit compte les redirections **écrites** (dédupliquées par l'entité), et
     * suit le COMMIT ; sa panne ne fait pas croire que la révision a échoué
     * ({@see FailSafeAudit}).
     */
    public function revise(Uuid $id, McpClientName $name, array $redirectUris): McpClient
    {
        $client = $this->transaction->run(function () use ($id, $name, $redirectUris): McpClient {
            $locked = $this->clients->findOneByIdForUpdate($id) ?? throw McpClientNotFoundException::withId();
            $locked->revise($name, $redirectUris);

            return $locked;
        });

        $this->failSafeAudit->attempt(
            fn () => $this->auditLogger->mcpClientRevised($client->getClientId(), \count($client->redirectUris())),
            self::REVISION_NOT_AUDITED,
        );

        return $client;
    }

    /**
     * Supprime le client et coupe ses connexions actives (D-A).
     *
     * **Ordre des verrous, dans une transaction courte** : la ligne du client
     * (`FOR UPDATE`), puis ses connexions actives (`FOR UPDATE`, par
     * identifiant), puis la suppression en dernier ; l'ordre client puis
     * connexions est celui de tout le module, jamais l'inverse. Ce qui sert à
     * l'audit (identifiant public, mode d'enregistrement, références de
     * connexion) est lu **avant** la suppression sous forme de valeurs : aucune
     * entité périmée n'est relue ensuite.
     *
     * « Active » veut dire « non révoquée » : une connexion échue que la purge
     * n'a pas encore marquée est coupée comme les autres.
     *
     * **Interblocage (T1)** : l'échange d'un code et la supplantation verrouillent
     * la connexion du code, puis les autres de la même paire ; ce chemin prend
     * le client puis les connexions par identifiant. Un cycle (`40P01`) reste
     * possible. La transaction entière est confiée à
     * {@see DeadlockRetryInterface::retryOnce()} : **une seule relance**, sur la
     * ligne relue sous le verrou ; au-delà, l'interblocage remonte. Une attente de
     * verrou trop longue (`55P03`, `lock_timeout`) remonte telle quelle : la
     * transaction est courte, une relance ne ferait que prolonger l'attente.
     *
     * **L'audit suit le COMMIT (T4)** : une ligne `mcp-connection-revoked` de cause
     * {@see self::REMOVAL_CAUSE} par connexion coupée, puis `mcp-client-removed`.
     * Chacune passe par {@see FailSafeAudit} : la panne du journal ne fait pas
     * croire que la suppression, déjà validée, a échoué.
     *
     * **Supprimer un client CIMD est permis (T8)** : ce n'est pas un bannissement,
     * il revient à la prochaine découverte par son document.
     */
    public function remove(Uuid $id): int
    {
        $removal = $this->deadlockRetry->retryOnce(
            fn (): array => $this->transaction->run(function () use ($id): array {
                $client = $this->clients->findOneByIdForUpdate($id) ?? throw McpClientNotFoundException::withId();
                // Les valeurs de l'audit, lues avant la suppression.
                $clientId = $client->getClientId();
                $registration = $client->registration();
                $cut = $this->connections->lockActiveOfClient($id);
                $this->clients->remove($client);

                return ['clientId' => $clientId, 'registration' => $registration, 'cut' => $cut];
            }),
        );

        return $this->auditRemoval($removal['clientId'], $removal['registration'], $removal['cut']);
    }

    /**
     * @param list<ConnectionReference> $cut
     *
     * @return int le nombre de connexions coupées
     */
    private function auditRemoval(string $clientId, ClientRegistration $registration, array $cut): int
    {
        foreach ($cut as $reference) {
            $this->revokedConnectionAudit->record($reference, self::REMOVAL_CAUSE);
        }

        $this->failSafeAudit->attempt(
            fn () => $this->auditLogger->mcpClientRemoved($clientId, $registration->value, \count($cut)),
            self::REMOVAL_NOT_AUDITED,
        );

        return \count($cut);
    }
}
