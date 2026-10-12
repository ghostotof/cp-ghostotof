<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * Le point d'entrelacement des tests de concurrence à processus fils, écrit
 * une seule fois (issue #510, suite de #403) : un écouteur de flush à usage
 * unique qui relâche le fils au moment choisi, puis rend la main au flush
 * dès que le fils a **fini** ou que **sa** session PostgreSQL attend un verrou.
 *
 * Deux moments, un par constructeur nommé :
 *
 *  - {@see self::releaseBeforeNextFlush()} (`preFlush`) : l'opération du test
 *    a tout lu et tout calculé, rien n'est encore écrit (#364, #389, #458) ;
 *  - {@see self::releaseAfterNextFlush()} (`postFlush`) : l'écriture est faite
 *    dans la transaction englobante, pas encore validée (#435).
 *
 * Sans le correctif qu'un test pince, le fils termine pendant la pause et le
 * résultat est celui de la course ; avec, il attend le verrou du test, et le
 * flush reprend. Dans les deux cas le verdict est le même à chaque passage :
 * l'attente porte sur un état observé, jamais sur une durée.
 *
 * La sonde est une connexion à part ({@see OpensProbeConnection}), hors de
 * toute transaction : pg_stat_activity est figé pour la durée d'une
 * transaction, celle du test le serait. Elle ne regarde que le pid annoncé
 * par le fils avec son « ready » ({@see ProcessBarrier::sessionPidOf()}) :
 * une autre session en attente ne doit pas passer pour lui. Toute attente
 * de type `Lock` compte (ligne, advisory lock, relation) ; une attente d'un
 * autre type (`LWLock`, `IO`, `Client`…) n'est pas un blocage par le test.
 *
 * L'écouteur se retire de l'EventManager au premier flush : les suivants,
 * ceux du test comme ceux de l'opération, ne le voient plus.
 */
final readonly class FlushInterleaving
{
    use OpensProbeConnection;

    /** Vrai tant que la session du pid donné attend un verrou. */
    private const string SESSION_WAITS_FOR_A_LOCK = "SELECT wait_event_type IS NOT DISTINCT FROM 'Lock' FROM pg_stat_activity WHERE pid = ?";

    /** Intervalle entre deux lectures de pg_stat_activity. */
    private const int POLL_MICROSECONDS = 10_000;

    private function __construct(
        private EntityManagerInterface $entityManager,
        private string $event,
        private ProcessBarrier $barrier,
        private Process $child,
        private int $childSessionPid,
        private Connection $probe,
        private int $timeoutSeconds,
    ) {
    }

    /**
     * Attend que le fils, déjà démarré, s'annonce prêt avec le pid de sa
     * session, puis arme l'écouteur sur le prochain `preFlush` de cet
     * EntityManager : au dernier instant avant que l'écriture n'atteigne la
     * base, le fils est relâché.
     */
    public static function releaseBeforeNextFlush(EntityManagerInterface $entityManager, ProcessBarrier $barrier, Process $child, int $timeoutSeconds): void
    {
        self::arm(Events::preFlush, $entityManager, $barrier, $child, $timeoutSeconds);
    }

    /**
     * Comme {@see self::releaseBeforeNextFlush()}, sur le prochain `postFlush` :
     * l'écriture est faite, mais pas encore validée si le flush s'imbrique
     * dans une transaction que l'opération tient (un verrou de compte, #435).
     */
    public static function releaseAfterNextFlush(EntityManagerInterface $entityManager, ProcessBarrier $barrier, Process $child, int $timeoutSeconds): void
    {
        self::arm(Events::postFlush, $entityManager, $barrier, $child, $timeoutSeconds);
    }

    /** Appelée par l'EventManager de Doctrine, sur le seul événement armé ; ses arguments ne servent pas. */
    public function preFlush(): void
    {
        $this->interleave();
    }

    /** Appelée par l'EventManager de Doctrine, sur le seul événement armé ; ses arguments ne servent pas. */
    public function postFlush(): void
    {
        $this->interleave();
    }

    private static function arm(string $event, EntityManagerInterface $entityManager, ProcessBarrier $barrier, Process $child, int $timeoutSeconds): void
    {
        $childSessionPid = $barrier->sessionPidOf($child, $timeoutSeconds);
        $listener = new self(
            $entityManager,
            $event,
            $barrier,
            $child,
            $childSessionPid,
            self::openProbeBeside($entityManager->getConnection()),
            $timeoutSeconds,
        );
        $entityManager->getEventManager()->addEventListener([$event], $listener);
    }

    private function interleave(): void
    {
        // Retiré avant tout : un échec de l'attente ne doit pas le laisser
        // armé sur le flush suivant. L'EventManager parcourt une copie de sa
        // liste, le retrait en cours de diffusion est sans risque.
        $this->entityManager->getEventManager()->removeEventListener([$this->event], $this);

        try {
            $this->barrier->release();
            $this->waitUntilDoneOrBlocked();
        } finally {
            $this->probe->close();
        }
    }

    /**
     * Rend la main dès que le fils a terminé ou que sa session attend un
     * verrou ; fait échouer le test au-delà du délai, plutôt que de le
     * suspendre sur un verrou jamais relâché, en citant ce que le fils a
     * écrit.
     *
     * Terminé, quel que soit son code de sortie : un refus peut être l'issue
     * que le test attend ou dénonce (#364 : « l'invitation concurrente a été
     * refusée »), et c'est à lui de la nommer — chaque appelant vérifie le
     * code de sortie du fils. Un fils qui n'a pas pu se mettre en place ne
     * va pas jusqu'ici : la barrière l'a déjà signalé.
     */
    private function waitUntilDoneOrBlocked(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while ($this->child->isRunning()) {
            if (true === $this->probe->fetchOne(self::SESSION_WAITS_FOR_A_LOCK, [$this->childSessionPid])) {
                return;
            }
            if (microtime(true) > $deadline) {
                Assert::fail(\sprintf(
                    "Le processus fils n'a ni fini ni attendu un verrou après %d s :\n%s\n%s%s",
                    $this->timeoutSeconds,
                    $this->child->getCommandLine(),
                    $this->child->getOutput(),
                    $this->child->getErrorOutput(),
                ));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }
}
