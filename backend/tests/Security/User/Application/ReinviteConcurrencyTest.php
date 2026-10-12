<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Deux relances concurrentes portent deux générations distinctes (issue #435).
 *
 * Un double clic sur « Renvoyer » : deux workers PHP-FPM chargent le compte à
 * la génération 1. Avancée en mémoire puis écrite en valeur absolue, la
 * génération devenait 2 des deux côtés, et deux messages de génération 2
 * échappaient au contrôle d'ancienneté de #434. L'entrelacement est
 * explicite : le test charge le compte, un processus fils
 * (Fixtures/reinvite-account.php) le relance jusqu'au bout, puis le test
 * relance avec sa copie d'avant.
 */
final class ReinviteConcurrencyTest extends KernelTestCase
{
    private const int TIMEOUT_SECONDS = 30;

    private const string REINVITE_SCRIPT = __DIR__.'/../Fixtures/reinvite-account.php';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testTwoConcurrentResendsPublishTwoDistinctGenerations(): void
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();
        $userId = $this->userIdOf('newcomer@example.com');
        // La copie du premier worker, chargée avant la relance du second.
        $stale = $container->get(CpgUserRepositoryInterface::class)->findOneById($userId);
        self::assertNotNull($stale);
        self::assertSame(1, $stale->getInvitationGeneration());

        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::REINVITE_SCRIPT, $userId->toRfc4122());
        $concurrent->mustRun();
        self::assertSame(1, preg_match('/^generation (\d+)$/m', $concurrent->getOutput(), $matches), $concurrent->getOutput().$concurrent->getErrorOutput());

        $container->get(CpgUserInviterInterface::class)->reinvite($stale, Locale::FR);

        self::assertSame(2, (int) $matches[1]);
        self::assertSame(3, $this->lastPublishedGeneration(), 'La relance du premier worker a repris la génération du second : mise à jour perdue (#435).');
        self::assertSame(3, $this->storedGeneration($userId->toRfc4122()));
    }

    /**
     * L'écriture atomique ne suffit pas si l'unité de travail garde l'ancienne
     * génération pour état d'origine : un `flush()` ultérieur réécrirait la
     * sienne en valeur absolue, par-dessus celle d'une relance concurrente
     * (seconde passe d'audit de #435).
     */
    public function testAFlushAfterTheResendDoesNotOverwriteAConcurrentGeneration(): void
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $container->get(EntityManagerInterface::class)->clear();
        $userId = $this->userIdOf('newcomer@example.com');
        $user = $container->get(CpgUserRepositoryInterface::class)->findOneById($userId);
        self::assertNotNull($user);

        $container->get(CpgUserInviterInterface::class)->reinvite($user, Locale::FR);
        ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::REINVITE_SCRIPT, $userId->toRfc4122())->mustRun();
        $container->get(EntityManagerInterface::class)->flush();

        self::assertSame(3, $this->storedGeneration($userId->toRfc4122()), 'Un flush après la relance a écrasé la génération d\'une relance concurrente (#435).');
    }

    /**
     * Le contrôle « en attente » est porté par l'écriture elle-même : un compte
     * activé entre le chargement et la relance n'est pas relancé (409), et sa
     * génération ne bouge pas.
     */
    public function testResendingAnAccountActivatedMeanwhileIsRefused(): void
    {
        [$stale, $userId] = $this->staleCopyOfANewInvitation();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE cpg_user SET activated_at = now() WHERE id = :id',
            ['id' => $userId],
        );

        try {
            self::getContainer()->get(CpgUserInviterInterface::class)->reinvite($stale, Locale::FR);
            self::fail('AccountNotAwaitingActivationException attendue.');
        } catch (AccountNotAwaitingActivationException) {
        }

        self::assertSame(1, $this->storedGeneration($userId));
    }

    /**
     * Un compte supprimé entre le chargement et la relance : 404, rien d'écrit.
     */
    public function testResendingAnAccountDeletedMeanwhileIsANotFound(): void
    {
        [$stale, $userId] = $this->staleCopyOfANewInvitation();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'DELETE FROM cpg_user WHERE id = :id',
            ['id' => $userId],
        );

        $this->expectException(CpgUserNotFoundException::class);

        self::getContainer()->get(CpgUserInviterInterface::class)->reinvite($stale, Locale::FR);
    }

    /**
     * @return array{CpgUser, string}
     */
    private function staleCopyOfANewInvitation(): array
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $container->get(EntityManagerInterface::class)->clear();
        $userId = $this->userIdOf('newcomer@example.com');
        $stale = $container->get(CpgUserRepositoryInterface::class)->findOneById($userId);
        self::assertNotNull($stale);

        return [$stale, $userId->toRfc4122()];
    }

    private function userIdOf(string $email): Uuid
    {
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user->getId();
    }

    private function lastPublishedGeneration(): int
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertNotEmpty($sent);
        $message = end($sent)->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);

        return $message->invitationGeneration;
    }

    private function storedGeneration(string $userId): int
    {
        $generation = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT invitation_generation FROM cpg_user WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsInt($generation);

        return $generation;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
