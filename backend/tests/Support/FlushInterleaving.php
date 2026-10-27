<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
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
 * l'attente porte sur un état observé, jamais sur une durée. L'attente
 * elle-même — la sonde hors transaction, le pid annoncé par le fils avec son
 * « ready » ({@see ProcessBarrier::sessionPidOf()}), le délai — est celle de
 * {@see SessionLockWait::untilDoneOrBlocked()}, partagée avec les tests
 * d'ordre des verrous.
 *
 * L'écouteur se retire de l'EventManager au premier flush : les suivants,
 * ceux du test comme ceux de l'opération, ne le voient plus.
 */
final readonly class FlushInterleaving
{
    private function __construct(
        private EntityManagerInterface $entityManager,
        private string $event,
        private ProcessBarrier $barrier,
        private SessionLockWait $wait,
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
            SessionLockWait::beside($entityManager->getConnection(), $child, $childSessionPid, $timeoutSeconds),
        );
        $entityManager->getEventManager()->addEventListener([$event], $listener);
    }

    /**
     * Relâche le fils, puis rend la main au flush dès qu'il a terminé — quel
     * que soit son code de sortie : un refus peut être l'issue que le test
     * attend ou dénonce (#364 : « l'invitation concurrente a été refusée »),
     * et c'est à lui de la nommer — ou que sa session attend un verrou. Un
     * fils qui n'a pas pu se mettre en place ne va pas jusqu'ici : la barrière
     * l'a déjà signalé.
     */
    private function interleave(): void
    {
        // Retiré avant tout : un échec de l'attente ne doit pas le laisser
        // armé sur le flush suivant. L'EventManager parcourt une copie de sa
        // liste, le retrait en cours de diffusion est sans risque.
        $this->entityManager->getEventManager()->removeEventListener([$this->event], $this);

        $this->barrier->release();
        $this->wait->untilDoneOrBlocked();
    }
}
