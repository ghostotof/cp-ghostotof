<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * La barrière fichier entre un test et ses processus `php` fils (issues #277,
 * #389, mutualisée par #403). Le protocole, écrit ici seul, des deux côtés :
 *
 *  1. le test crée le fichier et le tient en verrou exclusif ({@see self::hold()}) ;
 *  2. le fils fait d'abord tout ce qui est lent (kernel, connexions), puis
 *     ouvre le fichier, écrit « ready » — suivi du pid de sa session
 *     PostgreSQL s'il en annonce une — et demande un verrou partagé, qui le
 *     bloque ({@see self::awaitInChild()}) ;
 *  3. le test attend l'annonce de chaque fils ({@see self::waitUntilReady()},
 *     {@see self::sessionPidOf()}), puis relâche ({@see self::release()}) : tous
 *     partent au même instant, ou au point d'entrelacement que le test a choisi.
 *
 * Par fichier plutôt que par horloge : un démarrage lent en CI retarde la
 * libération au lieu d'étaler les fils, ce qui rendrait un test de course vert
 * sans rien prouver. Le fils ouvre le fichier avant d'annoncer qu'il est prêt :
 * le test peut ainsi le supprimer dès qu'il relâche, le descripteur du fils
 * reste valable.
 *
 * Reste un intervalle que la barrière ne ferme pas : entre son « ready » et
 * son `flock()`, un fils n'attend pas encore. Un test qui libère plusieurs fils
 * à la fois le couvre par un court délai avant {@see self::release()}.
 */
final class ProcessBarrier
{
    /** Ce que le fils écrit sur sa sortie quand il est prêt, avant de bloquer. */
    public const string READY = 'ready';

    /** Code de sortie d'un fils qui n'a pas pu se mettre en place : rien n'a été fait. */
    public const int CHILD_SETUP_FAILED = 2;

    /** Intervalle entre deux lectures de la sortie d'un fils. */
    private const int POLL_MICROSECONDS = 10_000;

    /**
     * @param resource|null $handle le descripteur qui tient le verrou exclusif, null une fois relâché
     */
    private function __construct(
        private readonly string $path,
        private $handle,
    ) {
    }

    /**
     * Crée le fichier-barrière dans le répertoire temporaire et le tient en
     * verrou exclusif : tout fils qui l'attend reste bloqué jusqu'à {@see self::release()}.
     */
    public static function hold(string $prefix): self
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        Assert::assertIsString($path, 'Fichier-barrière impossible à créer.');
        $handle = fopen($path, 'r');
        Assert::assertNotFalse($handle, 'Fichier-barrière illisible : '.$path);
        Assert::assertTrue(flock($handle, \LOCK_EX), "Impossible de tenir la barrière : flock(LOCK_EX) a échoué sur {$path}");

        return new self($path, $handle);
    }

    /** Le chemin à passer au fils, qui le donnera à {@see self::awaitInChild()}. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Attend que chaque fils ait annoncé « ready ». Un fils arrêté avant fait
     * échouer le test aussitôt, avec sa sortie d'erreur ; le délai est commun
     * à tous les fils.
     */
    public function waitUntilReady(int $timeoutSeconds, Process ...$children): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        foreach ($children as $child) {
            $this->announcementOf($child, $deadline, $timeoutSeconds);
        }
    }

    /**
     * Attend le « ready <pid> » du fils et rend le pid de la session
     * PostgreSQL qu'il annonce : celle qui attendra le verrou, que le test
     * reconnaît dans pg_stat_activity. Un « ready » sans pid fait échouer le
     * test : il observerait la session d'un autre.
     */
    public function sessionPidOf(Process $child, int $timeoutSeconds): int
    {
        $pid = $this->announcementOf($child, microtime(true) + $timeoutSeconds, $timeoutSeconds);
        if (null === $pid) {
            Assert::fail(\sprintf("Le processus fils s'est annoncé prêt sans pid de session :\n%s", $child->getCommandLine()));
        }

        return $pid;
    }

    /**
     * Libère les fils, ferme et supprime le fichier. Sans effet la seconde
     * fois : un test peut relâcher au point d'entrelacement puis, par
     * précaution, dans son `finally`.
     */
    public function release(): void
    {
        if (null === $this->handle) {
            return;
        }

        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Côté fils : annonce « ready » (avec le pid de sa session PostgreSQL s'il
     * est donné) et bloque jusqu'à ce que le test relâche la barrière.
     *
     * Un échec arrête le fils avec {@see self::CHILD_SETUP_FAILED} : un fils
     * qui passerait sans synchronisation (système de fichiers sans `flock`)
     * pourrait rendre le test vert sans avoir rien mis en concurrence.
     */
    public static function awaitInChild(string $path, ?int $sessionPid = null): void
    {
        $handle = fopen($path, 'r');
        if (false === $handle) {
            self::abortChild('Fichier-barrière illisible : '.$path);
        }

        echo self::READY.(null === $sessionPid ? '' : ' '.$sessionPid)."\n";
        flush();

        if (!flock($handle, \LOCK_SH)) {
            self::abortChild("Impossible d'attendre la barrière : flock(LOCK_SH) a échoué sur ".$path);
        }

        // Le verrou partagé n'a plus d'usage une fois obtenu : le test ne
        // reprend jamais la barrière.
        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    /**
     * @return int|null le pid annoncé avec « ready », null s'il n'y en a pas
     */
    private function announcementOf(Process $child, float $deadline, int $timeoutSeconds): ?int
    {
        while (true) {
            if (1 === preg_match('/^'.self::READY.'(?: (\d+))?$/m', $child->getOutput(), $matches)) {
                return isset($matches[1]) ? (int) $matches[1] : null;
            }
            if (!$child->isRunning()) {
                Assert::fail(\sprintf("Un processus fils s'est arrêté avant la barrière :\n%s\n%s", $child->getCommandLine(), $child->getErrorOutput()));
            }
            if (microtime(true) > $deadline) {
                Assert::fail(\sprintf("Un processus fils n'est pas prêt après %d s :\n%s", $timeoutSeconds, $child->getCommandLine()));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private static function abortChild(string $reason): never
    {
        fwrite(\STDERR, $reason."\n");

        exit(self::CHILD_SETUP_FAILED);
    }
}
