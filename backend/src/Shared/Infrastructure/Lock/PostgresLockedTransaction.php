<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Le squelette commun des verrous PostgreSQL de l'application (issue #512) :
 * une transaction ouverte et close à la main, l'attente du verrou bornée, son
 * `55P03` renommé, puis l'opération. Trois adaptateurs le partagent — le
 * périmètre d'ordre (#389), la ligne du compte (#435, #458) et l'attribution
 * des identifiants (#364) — qui en avaient chacun leur copie, déjà divergente.
 *
 * Patron **Execute Around** (la méthode qui encadre une opération reçue) : le
 * squelette est écrit une fois, et chaque adaptateur lui passe ce qui varie
 * réellement entre eux — l'instruction qui prend le verrou, l'exception qui
 * nomme son attente dépassée, et, pour le seul verrou d'ordre, le refus du
 * COMMIT qu'il sait nommer. Rejetés : une classe abstraite (Template Method),
 * qui lierait par héritage deux contextes (Portfolio, Security) et forcerait
 * à faire transiter par des crochets ce que chaque appel reçoit (le périmètre,
 * le compte) ; un service injecté, sans implémentation alternative à offrir,
 * qui aurait changé le câblage et les constructeurs pour rien. C'est un objet
 * immuable, pas un service : chaque adaptateur le construit sur la connexion
 * de son EntityManager, avec son délai.
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
 * **L'attente est bornée quand rien ne la borne** : si la session n'a aucun
 * `lock_timeout` (`0`, le cas de la console — worker Messenger, `app:*:seed`
 * —, qui n'a pas le `PGOPTIONS` de FPM), la transaction reçoit celui de
 * l'adaptateur, par `set_config(…, true)`, l'équivalent de `SET LOCAL` : il
 * s'éteint avec elle. Un délai que la session a déjà choisi (`PGOPTIONS` de
 * www.prod.conf, un test) est gardé tel quel, ni rallongé ni raccourci. Il
 * vaut pour toute la transaction, donc aussi pour les attentes de l'opération
 * elle-même, comme sous FPM. Imbriqué dans une transaction déjà ouverte, il
 * vaut jusqu'à la fin de celle-ci (portée de `SET LOCAL`), comme le
 * `SET LOCAL` des verrous d'avant #512. Paramètre lié plutôt qu'interpolé :
 * le délai n'entre jamais dans le texte SQL.
 *
 * **L'imbrication** : le squelette la tolère, sans la garantir. Imbriqué, son
 * COMMIT n'est qu'un `RELEASE SAVEPOINT` : le verrou tient jusqu'au COMMIT de
 * l'appelant, et un refus différé tombe à ce COMMIT-là, hors de portée de
 * `$onCommitRefused`. Le verrou d'ordre, le seul qui nomme un tel refus, la
 * refuse donc lui-même (issue #526,
 * {@see \App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockInsideTransactionException}).
 * Les deux verrous du compte ne la refusent pas encore, et aucun appelant ne
 * les imbrique (issue #532).
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
     * Le délai donné à une session qui n'en a aucun : celui de FPM
     * (`PGOPTIONS` de www.prod.conf, épinglé par FpmLockWaitBoundTest), pour
     * qu'une attente se borne de même sous la console et sous FPM.
     */
    public const string FALLBACK_LOCK_TIMEOUT = '5s';

    /**
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
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = self::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): mixed                          $acquire         prend le verrou ; peut refuser (la ligne a disparu)
     * @param Closure(DriverException): Throwable       $onTimeout       nomme l'attente dépassée de `$acquire`
     * @param Closure(): T                              $operation       tourne sous le verrou, dans la transaction
     * @param (Closure(DriverException): ?Throwable)|null $onCommitRefused nomme un refus du COMMIT reconnu ; `null` le laisse tel quel
     *
     * @return T
     */
    public function run(Closure $acquire, Closure $onTimeout, Closure $operation, ?Closure $onCommitRefused = null): mixed
    {
        $this->connection->beginTransaction();
        // Le niveau que ce squelette a ouvert : un COMMIT refusé ne laisse à
        // annuler que celui-ci, jamais celui d'un appelant qui en tient un.
        $ownLevel = $this->connection->getTransactionNestingLevel();

        try {
            $this->boundTheWait();
            $this->acquire($acquire, $onTimeout);
            $result = $operation();
        } catch (ConnectionLost $exception) {
            // Plus de session à annuler : PostgreSQL a déjà abandonné la
            // transaction (même choix que Connection::transactional()).
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBackAfter($exception, $ownLevel);

            throw $exception;
        }

        try {
            $this->connection->commit();
        } catch (Throwable $refusal) {
            $this->rollBackIfStillOpen($ownLevel, $refusal);

            if ($refusal instanceof DriverException && null !== $onCommitRefused) {
                throw $onCommitRefused($refusal) ?? $refusal;
            }

            throw $refusal;
        }

        return $result;
    }

    /**
     * Après un COMMIT refusé, n'annule que ce qui est encore ouvert. Refusé
     * par la base, le COMMIT a déjà ramené DBAL au niveau de l'appelant — et
     * PostgreSQL a annulé la transaction, verrou compris : un `rollBack()` y
     * lèverait « There is no active transaction » par-dessus le vrai refus.
     * Refusé par DBAL lui-même avant d'écrire (`CommitFailedRollbackOnly`,
     * après un `setRollbackOnly()`), il laisse la transaction ouverte, et le
     * verrou pris : elle est annulée, comme le faisait `transactional()`.
     */
    private function rollBackIfStillOpen(int $ownLevel, Throwable $refusal): void
    {
        if ($this->connection->getTransactionNestingLevel() === $ownLevel) {
            $this->rollBackAfter($refusal, $ownLevel);
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
     * - Au premier niveau, `$cause` remonte telle quelle (relancée par
     *   l'appelant de cette méthode) : elle dit déjà que rien n'a été validé.
     * - Imbriqué, la transaction de l'appelant disparaît avec la session.
     *   Relancer la cause seule le laisserait poursuivre et valider dans le
     *   vide. Une exception nommée l'en avertit, avec la cause en `previous`.
     *
     * Seule une `DriverException` (un refus de la base, une session perdue)
     * est rattrapée. Une erreur de programmation, comme `NoActiveTransaction`
     * pour une opération qui a validé elle-même la transaction du squelette,
     * remonte comme avant : rien ne doit la cacher derrière un refus métier.
     *
     * Rejeté : chaîner les deux exceptions au premier niveau aussi. La cause
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
    private function rollBackAfter(Throwable $cause, int $ownLevel): void
    {
        try {
            $this->connection->rollBack();
        } catch (DriverException $rollbackFailure) {
            $nested = $ownLevel > 1;
            $this->logger->error('ROLLBACK refusé après un échec sous verrou PostgreSQL : session fermée, cause relancée.', [
                'cause' => $cause::class,
                'rollbackFailure' => $rollbackFailure::class,
                'rollbackSqlState' => $rollbackFailure->getSQLState(),
                'nested' => $nested,
            ]);
            $this->connection->close();

            if ($nested) {
                throw LockedTransactionRollbackFailedException::insideOuterTransaction($cause, $rollbackFailure);
            }
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
