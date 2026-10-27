<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
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
     * Ce par quoi la connexion se désigne — son compte, son client public —,
     * révoquée ou non (#725). Une instruction SQL, hors de l'unité de
     * travail : valable après une transaction annulée qui a fermé
     * l'EntityManager, comme {@see self::revokeActive()}.
     *
     * @return ConnectionReference|null `null` si la connexion n'existe pas (ou plus)
     */
    public function referenceOf(Uuid $connectionId): ?ConnectionReference;

    /**
     * La connexion relue **sous le verrou de sa ligne** (`FOR UPDATE`), pour
     * la transaction en cours (#649) : le premier pas de qui juge ou touche
     * ses jetons — l'échange d'un code, un rafraîchissement, la révocation
     * d'un rafraîchissement au point de révocation —, l'ordre C → A → R de
     * `.claude/rules/mcp-oauth-cycle-de-vie.md`. Deux rafraîchissements
     * simultanés d'un même jeton se suivent au lieu de se croiser — le second
     * relit ce que le premier a validé, la rotation faite, et reconnaît la
     * réutilisation. Une instance déjà chargée est rafraîchie sur place ;
     * `null` si la ligne a disparu (l'instance périmée détachée). Exige une
     * transaction ouverte.
     */
    public function lockForUpdate(Uuid $connectionId): ?McpConnection;

    /**
     * Écrit en base l'état courant de la connexion (le dernier usage, #649),
     * comme le `save()` des autres dépôts du projet. Une connexion déjà
     * connue du dépôt — relue sous verrou — est mise à jour, jamais insérée
     * une seconde fois ; l'écriture emporte, comme partout, tout ce que le
     * dépôt a encore en attente.
     */
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

    /**
     * Révoque toute **autre** connexion active du même couple compte/client
     * que `$kept` (#649, décision 2 : un nouvel accord remplace l'ancien) —
     * même compte, même client, autre identifiant, pas encore révoquée ; une
     * connexion déjà révoquée garde sa première révocation. Une instruction
     * SQL, comme `revokeActive()`, servie par l'index partiel des couples
     * actifs ; appelée **dans** la transaction du premier échange du code de
     * `$kept`, au point de jeton (#726, jamais à l'approbation), l'audit
     * revenant à l'appelant après le COMMIT. `$kept` doit déjà être écrite
     * (son compte et son client sont lus sur l'instance). Les lignes des codes et jetons des
     * connexions révoquées ne sont pas touchées — tout lecteur juge la
     * connexion.
     *
     * @return list<RevokedConnection> ce qui a été révoqué, une entrée par connexion remplacée
     */
    public function revokeOtherActiveOf(McpConnection $kept, RevocationCause $cause, DateTimeImmutable $at): array;

    /**
     * Les connexions actives **peut-être** expirées, verrouillées (`FOR
     * UPDATE`) jusqu'à la fin de la transaction en cours, que la méthode exige
     * (#649, la purge) : non révoquées, et dont le dernier usage (ou l'octroi)
     * n'est pas postérieur à `$idleBefore`, ou l'octroi à `$maximumBefore`.
     *
     * Une présélection **large**, jamais le jugement : l'appelant passe des
     * bornes reculées d'une marge, puis juge chaque connexion par
     * McpConnection::isExpiredAt(), la seule définition de l'échéance. Le
     * verrou est celui du point de jeton (la connexion d'abord) : un
     * rafraîchissement en cours se valide avant la lecture, qui relit alors
     * son dernier usage (`HINT_REFRESH`) au lieu de juger une ligne périmée.
     * La connexion seule est lue — ni compte ni client, dont les lignes ne
     * doivent pas être verrouillées avec elle.
     *
     * @return list<McpConnection>
     */
    public function findActiveCandidatesForExpiry(DateTimeImmutable $idleBefore, DateTimeImmutable $maximumBefore): array;

    /**
     * Révoque, cause `expired`, celles de ces connexions qui ne le sont pas
     * encore (#649, la purge) : une instruction SQL, comme `revokeActive()`,
     * dont `RETURNING` rend ce qui a été révoqué — une connexion déjà révoquée
     * garde sa première révocation et n'est pas rendue. Appelée **dans** la
     * transaction de la purge, l'audit revenant à l'appelant après le COMMIT.
     * Les lignes des codes et jetons ne sont pas touchées.
     *
     * @param list<Uuid> $connectionIds
     *
     * @return list<RevokedConnection> une entrée par connexion révoquée par cet appel
     */
    public function revokeExpired(array $connectionIds, DateTimeImmutable $at): array;

    /**
     * Supprime les connexions révoquées (ou expirées) **avant** `$threshold`
     * (#649, rétention de la purge) ; leurs codes et jetons partent avec elles
     * (`ON DELETE CASCADE`). Rend le nombre de connexions supprimées.
     */
    public function deleteRevokedBefore(DateTimeImmutable $threshold): int;
}
