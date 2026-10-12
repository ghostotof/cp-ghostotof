<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Le squelette commun des verrous PostgreSQL de l'application (issue #512) :
 * une transaction ouverte et close à la main, l'attente du verrou bornée, son
 * `55P03` renommé, puis l'opération. Trois adaptateurs le partageaient à sa
 * création — le périmètre d'ordre (#389), la ligne du compte (#435, #458) et
 * l'attribution des identifiants (#364) — qui en avaient chacun leur copie,
 * déjà divergente ; la ligne d'une technologie (#545) s'y est ajoutée.
 *
 * Patron **Execute Around** (la méthode qui encadre une opération reçue) : le
 * squelette est écrit une fois, et chaque adaptateur lui passe ce qui varie
 * réellement entre eux — l'instruction qui prend le verrou, l'exception qui
 * nomme son attente dépassée, celle qui nomme son imbrication refusée, et,
 * pour le seul verrou d'ordre, le refus du COMMIT qu'il sait nommer (son
 * `$onCommitRefused`, qui ne fait que nommer depuis #547).
 * Rejetés : une classe abstraite (Template Method), qui lierait par héritage
 * deux contextes (Portfolio, Security) et forcerait à faire transiter par des
 * crochets ce que chaque appel reçoit (le périmètre, le compte) ; un service
 * injecté, sans implémentation alternative à offrir, qui aurait changé le
 * câblage et les constructeurs pour rien. C'est un objet immuable, pas un
 * service : chaque adaptateur le construit sur son EntityManager, dont il
 * prend la connexion, avec son délai.
 *
 * Une transaction à la main, ni `EntityManager::wrapInTransaction()` ni
 * `Connection::transactional()`. Le premier **ferme l'EntityManager** sur
 * toute exception, alors qu'un refus métier (lien déjà utilisé, version déjà
 * existante) est une issue ordinaire de l'opération. Le second masque un
 * COMMIT refusé (issues #390, #512) : DBAL a déjà ramené la transaction à
 * zéro quand `commit()` échoue, et son `rollBack()` de repli lève « There is
 * no active transaction » par-dessus, pour tout SQLSTATE hors de sa courte
 * liste (unicité, clé étrangère, interblocage…). Ici, un COMMIT refusé par la
 * base n'est suivi d'aucun `rollBack()` : PostgreSQL a déjà annulé la
 * transaction, et les verrous de transaction avec elle ; seul un COMMIT que
 * DBAL refuse avant d'écrire, transaction encore ouverte, est annulé. Les `flush()` de l'opération s'imbriquent dans la
 * transaction (point de sauvegarde) ; un `flush()` qui échoue ferme
 * l'EntityManager de lui-même, comme hors de tout verrou.
 *
 * **Un COMMIT refusé par la base ferme l'EntityManager** (issues #502, #547) :
 * toute `DriverException` levée par le COMMIT, quel que soit son SQLSTATE. Les
 * `flush()` de l'opération ont réussi au point de sauvegarde ; le refus annule
 * ce qu'ils ont écrit, mais l'UnitOfWork le tient pour écrit — une lecture
 * rendait la valeur refusée, `find()` une ligne jamais validée, et une écriture
 * suivante repartait de là. Il est fermé comme l'échec du COMMIT de Doctrine
 * lui-même le ferme ({@see \Doctrine\ORM\UnitOfWork::commit()}) : carte
 * d'identité vidée, une **nouvelle** lecture relit la base, une écriture par
 * l'ORM échoue en {@see \Doctrine\ORM\Exception\EntityManagerClosed}. Ce que
 * l'appelant tient déjà garde les valeurs refusées (détaché), et une
 * instruction DBAL brute ou un `UPDATE`/`DELETE` DQL passe encore. Une session
 * perdue au COMMIT (`ConnectionLost`, une `DriverException` aussi, le seul
 * refus que les verrous sans contrainte différée puissent recevoir) laisse son
 * issue inconnue : fermer reste la réponse sûre. Un worker Messenger le
 * retrouve neuf au message suivant
 * ({@see \Doctrine\Bundle\DoctrineBundle\Registry::reset()} réinitialise un
 * EntityManager fermé) ; une commande s'arrête sur l'exception.
 * Écrit ici, et non par adaptateur (#547, après #502 qui l'avait mis dans le
 * seul verrou d'ordre) : le squelette reçoit l'EntityManager, un nouveau verrou
 * ne peut donc pas l'oublier, comme `$onNested`. La fermeture précède
 * `$onCommitRefused`, qui ne fait plus que nommer le refus qu'il reconnaît.
 * Un refus métier, levé par l'opération, laisse l'EntityManager ouvert : il
 * n'y a rien de périmé tant qu'il est levé **avant** toute écriture. Un COMMIT
 * que DBAL refuse lui-même avant d'écrire (`CommitFailedRollbackOnly`, après un
 * `setRollbackOnly()` qu'aucun appelant ne fait) n'est pas une
 * `DriverException` et le laisse ouvert (portée de #502 gardée).
 * Rejetés : un crochet `$onCommitRefused` obligatoire, que chaque adaptateur
 * aurait rempli de son propre `close()` — un verrou aurait pu passer un
 * crochet vide ; une fermeture par adaptateur, oubliable comme le délai et le
 * COMMIT refusé avant #512.
 *
 * **L'attente est bornée quand rien ne la borne — un filet** : si la session
 * n'a aucun `lock_timeout` (`0`), la transaction reçoit celui de l'adaptateur,
 * par `set_config(…, true)`, l'équivalent de `SET LOCAL` : il s'éteint avec
 * elle. Depuis #525, les processus que les manifestes lancent ont leur borne —
 * FPM par le `PGOPTIONS` de www.prod.conf, chaque conteneur console de
 * `k8s/base` par le sien (épinglé par ConsoleLockWaitBoundTest) —, et ce filet
 * sert aux sessions qui n'en ont pas : la console du conteneur de dev, la CI,
 * une commande lancée par `kubectl exec` (l'environnement du conteneur
 * `php-fpm` n'a pas PGOPTIONS, seul son pool l'a), un futur pod qui
 * l'oublierait. Gardé (décision du 2026-10-12) : il coûte une
 * requête par transaction verrouillée, et ces verrous sont ceux dont
 * l'attente compte le plus. Un délai que la session a déjà choisi (`PGOPTIONS`,
 * un test) est gardé tel quel, ni rallongé ni raccourci. Il
 * vaut pour toute la transaction, donc aussi pour les attentes de l'opération
 * elle-même, comme sous FPM. Paramètre lié plutôt qu'interpolé :
 * le délai n'entre jamais dans le texte SQL.
 *
 * **Il refuse de s'ouvrir dans une transaction déjà ouverte** (issues #526,
 * #532), celle d'un autre verrou ou la sienne comprise, avant de rien prendre
 * ni lancer : `run()` lève l'exception que l'adaptateur donne à `$onNested`,
 * et la transaction de l'appelant reste intacte. Imbriqué, son COMMIT ne
 * serait qu'un `RELEASE SAVEPOINT`, et ce que chacun des verrous promet
 * « après la validation » ne tiendrait plus : le verrou tiendrait jusqu'au
 * COMMIT de l'appelant, un refus différé (le trigger des groupes de
 * traduction, #390) tomberait à ce COMMIT-là hors de portée de
 * `$onCommitRefused`, une invitation serait publiée — et un lien envoyé —
 * avant que le compte ou le jeton soient visibles du worker ou du lecteur.
 * Aucun appelant n'imbrique (cas d'usage, handlers, commandes, seeds, la
 * suite entière) : le refus rend la règle vraie par construction, au lieu de
 * la confier à la mémoire du prochain appelant.
 *
 * Écrit ici une fois plutôt que dans chaque adaptateur, dès qu'il y en a eu
 * trois à le vouloir (#532 ; #526 l'avait laissé dans le seul verrou d'ordre) :
 * `$onNested` est **obligatoire**, un nouveau verrou ne peut donc pas
 * l'oublier, alors qu'une copie par adaptateur se serait oubliée comme le
 * délai et le COMMIT refusé avant #512. Chacun garde le nom de son refus, qui
 * dit à l'exploitant quel verrou a été mal pris. Rejetée : une option qui
 * laisse un adaptateur tolérer l'imbrication — aucun n'en a l'usage, et elle
 * aurait gardé vivant un chemin que personne n'emprunte.
 *
 * Le juge est DBAL (`isTransactionActive()`), seul à savoir ce que `commit()`
 * émettra : une transaction ouverte en SQL brut (`executeStatement('BEGIN')`)
 * ne se voit pas, et aucune ne doit l'être autour d'un verrou. Une session
 * perdue (`ConnectionLost`) remet son compteur à zéro (`close()`) : le refus
 * ne survit pas à la transaction qu'elle a emportée.
 *
 * Limites acceptées, sans appelant qui les atteigne (relecture de #532) :
 * - Une transaction qu'un code aurait laissée ouverte sur une connexion qui
 *   dure (le worker) fait refuser chaque verrou suivant jusqu'au redémarrage
 *   du processus. Imbriqué en silence, rien n'aurait davantage été validé ;
 *   mais le `failure_transport` (`doctrine://default`) écrit par la même
 *   connexion, donc dans cette transaction : les messages « envoyés en
 *   failed » disparaissent avec elle au redémarrage. Le signal qui reste est
 *   le journal du worker (chaque réessai, puis le rejet, nomment l'exception).
 * - Seule l'entrée est gardée. Une opération qui ouvrirait sa propre
 *   transaction sans la clore ferait du COMMIT ci-dessous un simple
 *   `RELEASE SAVEPOINT`, et `run()` rendrait la main transaction ouverte.
 * - Une opération qui rattraperait un `ConnectionLost` pour lever autre chose
 *   verrait sa cause masquée par le `NoActiveTransaction` du ROLLBACK (DBAL a
 *   déjà fermé la session). Aucune opération ne rattrape d'erreur de la base.
 *
 * Seule l'attente de l'acquisition est renommée : un `55P03` levé par
 * l'opération (un verrou de ligne pris plus loin) remonte tel quel.
 *
 * **Un ROLLBACK refusé par la base ne masque jamais la cause** (issue #526) :
 * voir `rollBackAfter()`.
 */
final readonly class PostgresLockedTransaction
{
    /**
     * SQLSTATE `lock_not_available`, levé à l'expiration de `lock_timeout`.
     * Le convertisseur de DBAL n'en fait pas une `LockWaitTimeoutException`,
     * seulement une `DriverException` : il se reconnaît à son code.
     */
    public const string LOCK_NOT_AVAILABLE = '55P03';

    /**
     * Le délai donné à une session qui n'en a aucun : celui de FPM et des pods
     * console (`PGOPTIONS` de www.prod.conf et de `k8s/base`, épinglés par
     * FpmLockWaitBoundTest et ConsoleLockWaitBoundTest), pour qu'une attente
     * se borne de même partout.
     */
    public const string FALLBACK_LOCK_TIMEOUT = '5s';

    /**
     * @param EntityManagerInterface $entityManager celui dont l'opération écrit : le
     *                                             squelette prend sa connexion, et le
     *                                             ferme sur un COMMIT refusé
     * @param LoggerInterface $logger reçoit le seul échec d'un ROLLBACK, que
     *                                rien d'autre ne verrait : la cause est
     *                                relancée à sa place
     * @param string $fallbackLockTimeout une durée PostgreSQL (`5s`, `100ms`),
     *                                    donnée à la seule session qui n'a
     *                                    aucun `lock_timeout` — sous FPM, elle
     *                                    ne sert donc jamais ; une valeur
     *                                    invalide est refusée par la base à la
     *                                    première transaction qui la pose
     */
    private Connection $connection;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = self::FALLBACK_LOCK_TIMEOUT,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @template T
     *
     * @param Closure(): mixed                          $acquire         prend le verrou ; peut refuser (la ligne a disparu)
     * @param Closure(DriverException): Throwable       $onTimeout       nomme l'attente dépassée de `$acquire`
     * @param Closure(): Throwable                      $onNested        nomme le refus de s'ouvrir dans une transaction déjà ouverte
     * @param Closure(): T                              $operation       tourne sous le verrou, dans la transaction
     * @param (Closure(DriverException): ?Throwable)|null $onCommitRefused appelé pour **toute** `DriverException` du COMMIT, transaction déjà
     *                                                                   annulée et EntityManager déjà fermé (#547) : nomme le refus qu'il
     *                                                                   reconnaît ; `null` le laisse remonter tel quel
     *
     * @return T
     */
    public function run(Closure $acquire, Closure $onTimeout, Closure $onNested, Closure $operation, ?Closure $onCommitRefused = null): mixed
    {
        // Issues #526, #532 : imbriqué, le COMMIT ci-dessous ne serait qu'un
        // RELEASE SAVEPOINT. Refusé avant d'ouvrir quoi que ce soit : la
        // transaction de l'appelant n'est pas touchée.
        if ($this->connection->isTransactionActive()) {
            throw $onNested();
        }

        $this->connection->beginTransaction();

        try {
            $this->boundTheWait();
            $this->acquire($acquire, $onTimeout);
            $result = $operation();
        } catch (ConnectionLost $exception) {
            // Plus de session à annuler : PostgreSQL a déjà abandonné la
            // transaction (même choix que Connection::transactional()).
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBackAfter($exception);

            throw $exception;
        }

        try {
            $this->connection->commit();
        } catch (Throwable $refusal) {
            $this->rollBackIfStillOpen($refusal);

            if (!$refusal instanceof DriverException) {
                throw $refusal;
            }

            // Issues #502, #547 : l'UnitOfWork tient pour écrit ce que le
            // refus vient d'annuler. Fermé avant le crochet, qui ne peut donc
            // pas l'empêcher.
            $this->entityManager->close();

            if (null !== $onCommitRefused) {
                throw $onCommitRefused($refusal) ?? $refusal;
            }

            throw $refusal;
        }

        return $result;
    }

    /**
     * Après un COMMIT refusé, n'annule que ce qui est encore ouvert. Refusé
     * par la base, le COMMIT a déjà ramené DBAL à zéro — et
     * PostgreSQL a annulé la transaction, verrou compris : un `rollBack()` y
     * lèverait « There is no active transaction » par-dessus le vrai refus.
     * Refusé par DBAL lui-même avant d'écrire (`CommitFailedRollbackOnly`,
     * après un `setRollbackOnly()`), il laisse la transaction ouverte, et le
     * verrou pris : elle est annulée, comme le faisait `transactional()`.
     */
    private function rollBackIfStillOpen(Throwable $refusal): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->rollBackAfter($refusal);
        }
    }

    /**
     * Annule la transaction après `$cause`, sans que le refus du ROLLBACK par
     * la base la masque (issue #526).
     *
     * Le cas réel : la session meurt entre l'échec et le ROLLBACK (un
     * redémarrage de PostgreSQL, `57P01 admin_shutdown`, que DBAL range en
     * `ConnectionLost` d'après son message) ; ou la cause en vient elle-même,
     * sous un SQLSTATE que DBAL ne range pas en `ConnectionLost`, ce qui la
     * fait passer ici plutôt que par la branche sans ROLLBACK de `run()`. Le
     * ROLLBACK échoue alors à son tour, et son exception remplaçait la cause :
     * le journal montrait l'échec du ROLLBACK, et l'appelant ne reconnaissait
     * plus son refus (un 409, le compte disparu que le handler d'invitation
     * écarte, un `55P03` renommé).
     *
     * Désormais l'échec est journalisé en `error`, puis la connexion est
     * **fermée** : la session dont le ROLLBACK a échoué n'est jamais
     * réutilisée. PostgreSQL annule la transaction d'une session qui part,
     * verrous compris, et DBAL rouvre une session neuve à la requête suivante.
     * Sans cela, un worker gardait une connexion morte, ou au mieux un état
     * de transaction inconnu.
     * `$cause` remonte telle quelle (relancée par l'appelant de cette
     * méthode) : elle dit déjà que rien n'a été validé. Le squelette ne
     * s'ouvrant jamais dans une transaction déjà ouverte (#532), aucune
     * transaction d'appelant ne disparaît avec la session — le cas qu'une
     * exception nommée signalait depuis #526, retirée avec lui.
     *
     * Seule une `DriverException` (un refus de la base, une session perdue)
     * est rattrapée. Une erreur de programmation, comme `NoActiveTransaction`
     * pour une opération qui a validé elle-même la transaction du squelette,
     * remonte comme avant : rien ne doit la cacher derrière un refus métier.
     *
     * Rejeté : chaîner les deux exceptions. La cause
     * aurait été remplacée, et ce que voit chaque appelant aurait changé (un
     * 409 devenu 500, un `CpgUserNotFoundException` relancé par Messenger au
     * lieu d'être écarté).
     *
     * Le journal ne nomme que des classes et le SQLSTATE : le message d'une
     * `DriverException` peut citer les paramètres de la requête (une adresse,
     * un identifiant), celui d'un refus métier une donnée reçue. La cause,
     * relancée, est journalisée au niveau qui lui est propre par celui qui la
     * rattrape.
     */
    private function rollBackAfter(Throwable $cause): void
    {
        try {
            $this->connection->rollBack();
        } catch (DriverException $rollbackFailure) {
            $this->logger->error('ROLLBACK refusé après un échec sous verrou PostgreSQL : session fermée, cause relancée.', [
                'cause' => $cause::class,
                'rollbackFailure' => $rollbackFailure::class,
                'rollbackSqlState' => $rollbackFailure->getSQLState(),
            ]);
            $this->connection->close();
        }
    }

    /**
     * Donne à la transaction le délai de l'adaptateur si la session n'en a
     * aucun. `set_config(…, true)` ne vaut que pour la transaction en cours.
     */
    private function boundTheWait(): void
    {
        $this->connection->executeQuery(
            "SELECT set_config('lock_timeout', ?, true) WHERE current_setting('lock_timeout') = '0'",
            [$this->fallbackLockTimeout],
        );
    }

    /**
     * @param Closure(): mixed                    $acquire
     * @param Closure(DriverException): Throwable $onTimeout
     */
    private function acquire(Closure $acquire, Closure $onTimeout): void
    {
        try {
            $acquire();
        } catch (DriverException $exception) {
            if (self::LOCK_NOT_AVAILABLE === $exception->getSQLState()) {
                throw $onTimeout($exception);
            }

            throw $exception;
        }
    }
}
