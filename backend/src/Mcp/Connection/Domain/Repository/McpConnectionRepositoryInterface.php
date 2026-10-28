<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\PreselectionBounds;
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
     * « Mes connexions MCP » (#650, R3), la première moitié de la liste : **toutes**
     * les connexions du compte que personne n'a révoquées (`revoked_at` nul),
     * avec leur client, **en une requête**, sans limite — aussi anciennes
     * soient-elles, et les échues que la purge n'a pas encore marquées
     * comprises : leur état se juge par `McpConnection::isExpiredAt()`, jamais
     * ici. Elles sont bornées par nature : un accord abandonné échoit en une
     * heure et la purge le marque la nuit même, le premier échange d'un code
     * révoque les autres connexions de sa paire (compte, client), et les
     * clients sont une liste fermée que l'administrateur enregistre en ligne
     * de commande. La plus récemment octroyée d'abord, l'identifiant
     * décroissant départageant deux octrois de la même seconde. Aucun verrou :
     * une lecture, que ni un rafraîchissement ni la purge n'attendent.
     *
     * @return list<McpConnection>
     */
    public function findNotRevokedOfAccount(Uuid $accountId): array;

    /**
     * La seconde moitié : les `$limit` connexions **terminées** du compte
     * (`revoked_at` posé, quelle qu'en soit la cause : la personne, le client,
     * la purge, une remplaçante) octroyées le plus récemment, avec leur client,
     * **en une requête**. La purge les garde six mois et chaque approbation
     * abandonnée ou remplacée en laisse une : sans borne, la liste grossirait
     * avec l'âge du compte. Même ordre que {@see findNotRevokedOfAccount()}, et
     * l'identifiant décide *quelles* terminées une limite garde. Demander une
     * ligne de plus que ce qu'on montre dit s'il y en a davantage.
     *
     * @param positive-int $limit
     *
     * @return list<McpConnection>
     */
    public function findTerminatedOfAccount(Uuid $accountId, int $limit): array;

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
     * Révoque la connexion **si elle est à ce compte** et ne l'est pas encore
     * (#650, la révocation par la personne) : la même instruction que
     * {@see self::revokeActive()}, dont le choix est « cette connexion, de ce
     * compte » — le filtre par compte est ce qui garantit qu'on ne révoque que
     * les siennes, la base le décide en une instruction, sans lecture
     * préalable. Une seule écriture : l'instruction est la transaction
     * (autocommit), l'audit revenant à l'appelant après son retour, donc après
     * son COMMIT. La connexion d'un autre compte, inconnue ou déjà révoquée
     * (qui garde sa première révocation) rend `null`.
     *
     * @return RevokedConnection|null ce qui a été révoqué ; `null` si la ligne n'est pas à ce compte, inconnue ou déjà révoquée
     */
    public function revokeActiveOfAccount(Uuid $connectionId, Uuid $accountId, RevocationCause $cause, DateTimeImmutable $at): ?RevokedConnection;

    /**
     * La connexion est-elle à ce compte, révoquée ou non (#650) ? Une requête,
     * sans verrou : elle départage, quand {@see self::revokeActiveOfAccount()}
     * a rendu `null`, une connexion déjà révoquée mais à soi (204) d'une
     * connexion étrangère ou inconnue (404).
     */
    public function belongsToAccount(Uuid $connectionId, Uuid $accountId): bool;

    /**
     * Révoque toutes les connexions actives d'un compte (#650 : son mot de
     * passe a changé, ou il a perdu le palier de confiance) : la même
     * instruction que {@see self::revokeActive()}, sur le compte au lieu d'un
     * identifiant — une connexion déjà révoquée garde sa première révocation
     * et n'est pas rendue, celle d'un autre compte n'est pas touchée.
     *
     * **Dans la transaction de l'appelant, exigée** : l'instruction seule est
     * atomique, mais elle doit valider avec l'écriture qui la motive (le mot
     * de passe, le rôle). Hors transaction, `RevocationOutsideTransactionException`,
     * avant d'écrire. L'audit revient à l'appelant, après le COMMIT. Les
     * lignes sont verrouillées par identifiant, après celle du compte que
     * l'appelant tient déjà (`FOR UPDATE` du verrou du compte).
     *
     * @return list<RevokedConnection> ce qui a été révoqué, une entrée par connexion
     */
    public function revokeAllActiveOfAccount(Uuid $accountId, RevocationCause $cause, DateTimeImmutable $at): array;

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
     * Note le premier échange du code d'empreinte `$codeHash` sur la connexion
     * qu'il désigne (#730, #650 R4) : `code_exchanged_at` reçoit `$at`, **une
     * fois** (`code_exchanged_at IS NULL`, comme `McpConnection::recordCodeExchangeAt()`
     * qu'elle double en base). Une instruction SQL, la connexion trouvée par la
     * ligne du code (`connection_id`) : ni déchiffrement du code, ni relecture
     * de la connexion — rien qui puisse échouer après que league a émis les
     * jetons. Appelée **dans** la transaction de l'échange, par la révocation
     * du code (`AuthCodeRepository::revokeAuthCode()`, le dernier geste de
     * league, une fois les jetons émis) ; la ligne de la connexion est déjà
     * verrouillée par cette transaction (`lockForUpdate()`), l'instruction ne
     * prend aucun autre verrou. Ne touche pas l'instance gérée : rien ne la
     * relit ensuite dans la requête. Un code inconnu, ou déjà noté, n'écrit rien.
     */
    public function recordCodeExchangeOf(string $codeHash, DateTimeImmutable $at): void;

    /**
     * Les connexions actives **peut-être** expirées, verrouillées (`FOR
     * UPDATE`) jusqu'à la fin de la transaction en cours, que la méthode exige
     * (#649, la purge) : non révoquées, et dont le dernier usage (ou l'octroi)
     * n'est pas postérieur à `$bounds->idleBefore()`, ou l'octroi à
     * `$bounds->maximumBefore()`, ou — son code n'ayant jamais été échangé
     * (#730) — l'octroi à `$bounds->unexchangedBefore()`.
     *
     * Les trois bornes sont un objet valeur ({@see PreselectionBounds}, #726)
     * plutôt que trois instants positionnels : de même type, corrélés par
     * l'instant dont elles dérivent, qu'une permutation échangerait sans que
     * rien ne la voie.
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
    public function findActiveCandidatesForExpiry(PreselectionBounds $bounds): array;

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
