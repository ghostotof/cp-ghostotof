<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * L'attente « la session du fils attend un verrou », écrite une seule fois
 * (lot C des relectures de #649, suite de #510) : depuis une sonde hors de
 * toute transaction, lire dans `pg_stat_activity` si la session PostgreSQL
 * annoncée par le fils ({@see ProcessBarrier::sessionPidOf()}) attend un
 * verrou, et rendre la main dès que c'est le cas — ou, selon l'attente, dès
 * que le fils a fini.
 *
 * Deux attentes, un appelant chacune :
 *
 *  - {@see self::untilDoneOrBlocked()} : le point d'entrelacement au flush
 *    ({@see FlushInterleaving}) — le fils a **fini** (quel que soit son code de
 *    sortie : un refus peut être l'issue que le test attend ou dénonce,
 *    chaque appelant le vérifie) ou **sa** session attend un verrou ;
 *  - {@see self::untilBlocked()} : les tests d'ordre des verrous dont
 *    l'opération ne passe par aucun flush de l'ORM (la révocation, la purge :
 *    tout est du SQL natif) — la fenêtre y est ouverte par un verrou que le
 *    test tient, et le fils **doit** s'y bloquer ; un fils qui finit sans
 *    attendre n'a rien mis en concurrence, l'attente échoue aussitôt.
 *
 * Au-delà du délai, l'attente fait échouer le test en citant ce que le fils a
 * écrit, plutôt que de le suspendre sur un verrou jamais relâché. Elle ne
 * regarde que le pid annoncé : une autre session en attente ne doit pas
 * passer pour lui. Toute attente de type `Lock` compte (ligne, advisory lock,
 * relation) ; une attente d'un autre type (`LWLock`, `IO`, `Client`…) n'est
 * pas un blocage par le test.
 *
 * La sonde est une connexion à part ({@see OpensProbeConnection}) : hors
 * transaction, car pg_stat_activity est figé pour la durée d'une transaction,
 * celle du test le serait. Elle est fermée au retour de l'attente, échec
 * compris.
 */
final readonly class SessionLockWait
{
    use OpensProbeConnection;

    /** Vrai tant que la session du pid donné attend un verrou (`Lock`). */
    private const string SESSION_WAITS_FOR_A_LOCK = "SELECT wait_event_type IS NOT DISTINCT FROM 'Lock' FROM pg_stat_activity WHERE pid = ?";

    /** Intervalle entre deux lectures de pg_stat_activity. */
    private const int POLL_MICROSECONDS = 10_000;

    private function __construct(
        private Connection $probe,
        private Process $child,
        private int $childSessionPid,
        private int $timeoutSeconds,
    ) {
    }

    /**
     * Ouvre la sonde à côté de la connexion donnée (celle de l'ORM, ou toute
     * connexion à la même base) : mêmes paramètres, autre session.
     */
    public static function beside(Connection $connection, Process $child, int $childSessionPid, int $timeoutSeconds): self
    {
        return new self(self::openProbeBeside($connection), $child, $childSessionPid, $timeoutSeconds);
    }

    /**
     * Rend la main dès que le fils a terminé ou que sa session attend un
     * verrou ; fait échouer le test au-delà du délai.
     */
    public function untilDoneOrBlocked(): void
    {
        $this->poll(childMustBlock: false);
    }

    /**
     * Rend la main dès que la session du fils attend un verrou ; fait échouer
     * le test si le fils s'arrête avant, ou au-delà du délai.
     */
    public function untilBlocked(): void
    {
        $this->poll(childMustBlock: true);
    }

    private function poll(bool $childMustBlock): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        try {
            while (true) {
                if (true === $this->probe->fetchOne(self::SESSION_WAITS_FOR_A_LOCK, [$this->childSessionPid])) {
                    return;
                }
                if (!$this->child->isRunning()) {
                    if (!$childMustBlock) {
                        return;
                    }
                    Assert::fail(\sprintf("Le processus fils s'est arrêté sans attendre un verrou :\n%s", $this->written()));
                }
                if (microtime(true) > $deadline) {
                    Assert::fail(\sprintf(
                        $childMustBlock ? "Le processus fils n'attend aucun verrou après %d s :\n%s" : "Le processus fils n'a ni fini ni attendu un verrou après %d s :\n%s",
                        $this->timeoutSeconds,
                        $this->written(),
                    ));
                }
                usleep(self::POLL_MICROSECONDS);
            }
        } finally {
            $this->probe->close();
        }
    }

    /**
     * La ligne de commande du fils et ce qu'il a écrit : un fils lit tout
     * secret sur son entrée standard, jamais en argument, et n'en écrit
     * aucun ({@see ChildPhpProcess}).
     */
    private function written(): string
    {
        return $this->child->getCommandLine()."\n".$this->child->getOutput().$this->child->getErrorOutput();
    }
}
