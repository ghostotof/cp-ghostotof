<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;
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

        $concurrent = new Process(ChildPhpProcess::command(self::REINVITE_SCRIPT, $userId->toRfc4122()), env: $this->childEnvironment(), timeout: self::TIMEOUT_SECONDS);
        $concurrent->mustRun();
        self::assertSame(1, preg_match('/^generation (\d+)$/m', $concurrent->getOutput(), $matches), $concurrent->getOutput().$concurrent->getErrorOutput());

        $container->get(CpgUserInviterInterface::class)->reinvite($stale, Locale::FR);

        self::assertSame(2, (int) $matches[1]);
        self::assertSame(3, $this->lastPublishedGeneration(), 'La relance du premier worker a repris la génération du second : mise à jour perdue (#435).');
        self::assertSame(3, $this->storedGeneration($userId->toRfc4122()));
    }

    private function userIdOf(string $email): Uuid
    {
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user->getId();
    }

    private function lastPublishedGeneration(): ?int
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

    /**
     * Même transmission que les autres tests de concurrence : les variables
     * que phpunit.dist.xml force ne vivent que dans $_SERVER.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $environment = [];
        foreach ($_SERVER as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
