<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<McpClient>
 */
class McpClientRepository extends ServiceEntityRepository implements McpClientRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RereadUnderLock $rereadUnderLock,
    ) {
        parent::__construct($registry, McpClient::class);
    }

    public function findOneByClientId(string $clientId): ?McpClient
    {
        /** @var McpClient|null */
        return $this->createQueryBuilder('client')
            ->where('client.clientId = :clientId')
            ->setParameter('clientId', $clientId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function add(McpClient $client): void
    {
        $this->getEntityManager()->persist($client);
        $this->getEntityManager()->flush();
    }

    /**
     * Une requête atomique : l'`INSERT` crée la ligne, ou, sur le `client_id`
     * déjà pris, ne met à jour que si la ligne existante est `cimd`
     * (`WHERE`). Sur une ligne pré-enregistrée, `RETURNING` ne rend rien : la
     * ligne n'est pas touchée et le résultat est `null`.
     *
     * `id` et `registered_at` ne sont pas dans le `SET` : le client garde sa
     * clé et sa date. Les paramètres sont typés comme les colonnes (`uuid`,
     * `json`, `datetimetz_immutable`) : un horodatage lu dans le fuseau de la
     * session SQL serait faux.
     */
    public function saveDiscovered(McpClient $discovered): ?McpClient
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO mcp_client (id, client_id, name, redirect_uris, registered_at, registration)'
            .' VALUES (:id, :clientId, :name, :redirectUris, :registeredAt, :registration)'
            .' ON CONFLICT (client_id) DO UPDATE'
            .' SET name = EXCLUDED.name, redirect_uris = EXCLUDED.redirect_uris'
            .' WHERE mcp_client.registration = :registration'
            .' RETURNING id',
            [
                'id' => $discovered->getId(),
                'clientId' => $discovered->getClientId(),
                'name' => $discovered->getName(),
                'redirectUris' => $discovered->redirectUris(),
                'registeredAt' => $discovered->registeredAt(),
                'registration' => ClientRegistration::Cimd->value,
            ],
            [
                'id' => UuidType::NAME,
                'redirectUris' => Types::JSON,
                'registeredAt' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        // Aucune ligne rendue : le client est pré-enregistré, la base n'a pas été touchée.
        if (false === $id) {
            return null;
        }

        $managed = $this->getEntityManager()->getUnitOfWork()->tryGetById($id, McpClient::class);

        if ($managed instanceof McpClient) {
            // Déjà gérée dans cette unité de travail : sa copie en mémoire est périmée, la base vient de changer.
            $this->getEntityManager()->refresh($managed);

            return $managed;
        }

        return $this->find($id);
    }

    public function isDiscovered(McpClientId $clientId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM mcp_client WHERE client_id = :clientId AND registration = :registration',
            ['clientId' => (string) $clientId, 'registration' => ClientRegistration::Cimd->value],
        );
    }

    /**
     * Les deux modes d'enregistrement, sans jointure : un client n'en porte
     * aucune ici. Le tri est total (la date, puis la clé) et l'index de la clé
     * primaire départage les égalités sans dépendre du plan de la base.
     */
    public function all(): array
    {
        /** @var list<McpClient> */
        return $this->createQueryBuilder('client')
            ->orderBy('client.registeredAt', 'DESC')
            ->addOrderBy('client.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lecture simple par la clé : un `QueryBuilder` plutôt que `find()`.
     * `find()` répond sans requête quand l'instance est déjà dans l'unité de
     * travail, ce qui rendrait le coût de l'appelant dépendant de ce qui a été
     * chargé avant lui — le détail du backoffice s'engage à deux requêtes, et
     * le prouve. Le `QueryBuilder` est aussi le style du reste du dépôt. Le
     * paramètre est typé comme la colonne (`uuid`). Aucun verrou, aucune
     * transaction : la sœur verrouillée est {@see self::findOneByIdForUpdate()}.
     */
    public function findOneById(Uuid $id): ?McpClient
    {
        /** @var McpClient|null */
        return $this->createQueryBuilder('client')
            ->where('client.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * La relecture sous verrou du projet ({@see RereadUnderLock::row()}) avec
     * `FOR UPDATE` : l'instance déjà gérée est rafraîchie sur place, une
     * ligne disparue rend `null` et détache l'instance périmée. Sans
     * transaction ouverte, Doctrine refuse le verrou (`TransactionRequiredException`).
     */
    public function findOneByIdForUpdate(Uuid $id): ?McpClient
    {
        return $this->rereadUnderLock->row($this->getEntityManager(), McpClient::class, $id, forUpdate: true);
    }

    /**
     * Une requête DQL à `PESSIMISTIC_READ` (`FOR SHARE`) : {@see RereadUnderLock}
     * ne sait prendre que `FOR UPDATE`, trop fort ici — deux consentements du
     * même client ne doivent pas s'attendre. Le verrou ne sert qu'à **bloquer
     * la suppression** (et toute écriture de la ligne) jusqu'au COMMIT de
     * l'appelant : il n'apporte aucune garantie sur ce qui a été validé avant
     * sa prise. `HINT_REFRESH` rend l'état courant de la ligne (jamais la copie
     * périmée de la carte d'identité) ; **la redirection demandée est donc à
     * revérifier par l'appelant sur l'instance relue** (`allowsRedirect()`), une
     * révision validée entre sa validation et ce verrou l'ayant pu retirer.
     * Sans transaction ouverte, Doctrine refuse le verrou.
     */
    public function findOneByClientIdForShare(string $clientId): ?McpClient
    {
        /** @var McpClient|null */
        return $this->createQueryBuilder('client')
            ->where('client.clientId = :clientId')
            ->setParameter('clientId', $clientId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function remove(McpClient $client): void
    {
        $this->getEntityManager()->remove($client);
        $this->getEntityManager()->flush();
    }
}
