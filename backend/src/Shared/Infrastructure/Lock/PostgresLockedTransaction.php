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
 * le délai n'entre jamais dans le texte SQL. Le verrou d'ordre refuse d'être
 * imbriqué (#526,
 * {@see \App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockInsideTransactionException}) :
 * son COMMIT
 * n'y serait qu'un point de sauvegarde, et le refus qu'il sait nommer
 * tomberait au COMMIT de l'appelant. Les deux verrous du compte l'admettent ;
 * aucun appelant ne s'en sert.
 *
 * Seule l'attente de l'acquisition est renommée : un `55P03` levé par
 * l'opération (un verrou de ligne pris plus loin) remonte tel quel.
 *
 * **Un ROLLBACK qui échoue ne masque jamais la cause** (issue #526) : il est
 * journalisé en `error` (classes et SQLSTATE, jamais un message), et c'est
 * l'échec de l'opération ou le refus du COMMIT qui remonte, tel quel.
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
            $this->rollBackAfter($exception);

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
            $this->rollBackAfter($refusal);
        }
    }

    /**
     * Annule la transaction après `$cause`, sans jamais la masquer (issue
     * #526) : un ROLLBACK qui échoue à son tour — la session coupée sous un
     * SQLSTATE que DBAL ne range pas en `ConnectionLost`, `57P01
     * admin_shutdown` d'un redémarrage de PostgreSQL — est journalisé, puis
     * l'appelant relance `$cause` telle quelle. C'est elle qui dit ce qui
     * s'est passé, et elle que les appelants reconnaissent : un refus métier
     * (409), le compte disparu que le handler d'invitation écarte, un `55P03`
     * renommé.
     *
     * Rien n'est perdu à ne pas relancer l'échec du ROLLBACK : PostgreSQL
     * annule de lui-même la transaction d'une session qui disparaît, verrous
     * compris, et la cause, refus de l'opération ou du COMMIT, dit déjà que
     * rien n'a été validé.
     *
     * Rejeté : chaîner les deux dans une exception nommée. Elle remplacerait
     * la cause, et changerait ce que voit chaque appelant — un 409 devenu
     * 500, un `CpgUserNotFoundException` que le handler ne reconnaîtrait plus,
     * relancé par Messenger au lieu d'être écarté.
     *
     * Le journal ne nomme que des classes et le SQLSTATE : le message d'une
     * `DriverException` peut citer les paramètres de la requête (une adresse,
     * un identifiant), celui d'un refus métier une donnée reçue. La cause,
     * relancée, est journalisée par qui la rattrape, au niveau qui est le sien.
     */
    private function rollBackAfter(Throwable $cause): void
    {
        try {
            $this->connection->rollBack();
        } catch (Throwable $rollbackFailure) {
            $this->logger->error('ROLLBACK en échec après un échec sous verrou PostgreSQL : la cause est relancée telle quelle.', [
                'cause' => $cause::class,
                'rollbackFailure' => $rollbackFailure::class,
                'rollbackSqlState' => $rollbackFailure instanceof DriverException ? $rollbackFailure->getSQLState() : null,
            ]);
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
