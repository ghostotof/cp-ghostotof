<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Le point d'entrelacement des tests de concurrence ({@see FlushInterleaving},
 * issue #510), exercé sur de vrais processus et une vraie base : les quatre
 * tests qui s'en servent (#364, #389, #435, #458) ne le prouvent
 * qu'indirectement, et aucun ne passe par son délai.
 *
 * Le fils (Fixtures/ChildProcessHarness/act-after-barrier.php) fait, une fois
 * relâché, l'une des trois choses que l'attente distingue : prendre un verrou
 * que le test tient, finir, ou ni l'un ni l'autre.
 */
final class FlushInterleavingTest extends KernelTestCase
{
    private const int TIMEOUT_SECONDS = 20;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/ChildProcessHarness/act-after-barrier.php';

    /** Une clé d'advisory lock que seul ce test prend. */
    private const int LOCK_KEY = 510_510_510;

    /**
     * Le cas des correctifs : le fils attend le verrou que tient le test, le
     * flush reprend sans attendre qu'il finisse, puis le fils passe dès que
     * le verrou est relâché.
     */
    public function testTheFlushResumesWhileTheChildWaitsForALockTheTestHolds(): void
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();

        // Dans le try : un échec du démarrage ne laisse pas la clé tenue.
        try {
            $connection->executeQuery('SELECT pg_advisory_lock(?)', [self::LOCK_KEY]);
            [$barrier, $child] = $this->startChild('lock', (string) self::LOCK_KEY);
            FlushInterleaving::releaseBeforeNextFlush($entityManager, $barrier, $child, self::TIMEOUT_SECONDS);
            $entityManager->flush();

            self::assertTrue($child->isRunning(), "Le flush a repris alors que le fils n'attendait pas le verrou :\n".$child->getOutput().$child->getErrorOutput());
            self::assertStringContainsString('released', $child->getOutput());
            self::assertStringNotContainsString('locked', $child->getOutput());
        } finally {
            $connection->executeQuery('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }

        $child->wait();
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertStringContainsString('locked', $child->getOutput());
    }

    /**
     * Le cas d'une course sans correctif : le fils termine pendant la pause,
     * et le flush ne reprend qu'après lui. L'écouteur se retire : il n'est
     * plus armé sur aucun des deux événements.
     */
    public function testTheFlushResumesOnceTheChildIsDoneAndTheListenerIsDisarmed(): void
    {
        $entityManager = $this->entityManager();
        [$barrier, $child] = $this->startChild('finish');

        FlushInterleaving::releaseAfterNextFlush($entityManager, $barrier, $child, self::TIMEOUT_SECONDS);
        $entityManager->flush();

        self::assertFalse($child->isRunning(), 'Le flush a repris avant que le fils ait fini.');
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertSame([], $this->interleavingListeners($entityManager, Events::postFlush), "L'écouteur reste armé après son flush.");
        self::assertSame([], $this->interleavingListeners($entityManager, Events::preFlush), "L'écouteur s'est armé sur l'autre événement.");
    }

    /**
     * Un fils qui ne finit pas et n'attend aucun verrou fait échouer le test
     * au-delà du délai, au lieu de le suspendre ; l'écouteur est retiré quand
     * même.
     */
    public function testAChildThatNeitherFinishesNorWaitsForALockFailsTheFlushAfterTheDelay(): void
    {
        $entityManager = $this->entityManager();
        [$barrier, $child] = $this->startChild('idle');
        // Le démarrage du kernel dans le fils dépasse le délai court de l'attente.
        $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS);

        $failure = null;
        try {
            FlushInterleaving::releaseBeforeNextFlush($entityManager, $barrier, $child, 1);
            $entityManager->flush();
        } catch (AssertionFailedError $caught) {
            $failure = $caught;
        } finally {
            $stillRunning = $child->isRunning();
            $child->stop(0);
        }

        self::assertInstanceOf(AssertionFailedError::class, $failure, "Le flush a repris alors que le fils n'a ni fini ni attendu un verrou.");
        self::assertStringContainsString("n'a ni fini ni attendu un verrou après 1 s", $failure->getMessage());
        self::assertTrue($stillRunning, "Le fils s'est arrêté pendant l'attente : le délai n'a rien prouvé.");
        self::assertSame([], $this->interleavingListeners($entityManager, Events::preFlush), "L'écouteur reste armé après un échec.");
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{ProcessBarrier, Process}
     */
    private function startChild(string ...$arguments): array
    {
        $barrier = ProcessBarrier::hold('interleaving-barrier-');
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path(), ...$arguments);
        $child->start();

        return [$barrier, $child];
    }

    /**
     * @return list<FlushInterleaving>
     */
    private function interleavingListeners(EntityManagerInterface $entityManager, string $event): array
    {
        // getAllListeners() plutôt que getListeners($event) : celle du
        // ContainerAwareEventManager de Symfony lève une TypeError sur un
        // événement qui n'a jamais eu d'écouteur.
        return array_values(array_filter(
            $entityManager->getEventManager()->getAllListeners()[$event] ?? [],
            static fn (object|string $listener): bool => $listener instanceof FlushInterleaving,
        ));
    }
}
