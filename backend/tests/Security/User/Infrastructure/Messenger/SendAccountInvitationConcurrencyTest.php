<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Process\Process;

/**
 * Deux consommateurs sur le même compte ne laissent qu'un jeton valable
 * (issue #435).
 *
 * En `READ COMMITTED`, le `DELETE … WHERE user_id` d'un consommateur ne voit
 * pas l'`INSERT` non commité de l'autre : une fois les deux commits passés,
 * deux jetons étaient utilisables. L'entrelacement est explicite, sur le
 * modèle de ContributionOrderConcurrencyTest :
 *
 *  1. B (Fixtures/run-invitation-handler.php) démarre et attend la barrière ;
 *  2. A, dans ce processus, traite le message ; au `postFlush` de son jeton —
 *     inséré, pas encore commité —, le test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou que sa session soit **bloquée sur
 *     un verrou** PostgreSQL, puis laisse A commiter ;
 *  4. une fois B terminé, le test compte les jetons du compte.
 *
 * Sans verrou par compte, B purge sans voir le jeton de A et commite le sien
 * pendant la pause : deux jetons. Avec, B attend A, puis remplace son jeton.
 */
final class SendAccountInvitationConcurrencyTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const int TIMEOUT_SECONDS = 30;

    private const string HANDLER_SCRIPT = __DIR__.'/../../Fixtures/run-invitation-handler.php';

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

    public function testTwoConsumersOfTheSameAccountLeaveASingleUsableToken(): void
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $container->get(EntityManagerInterface::class)->clear();
        $user = $container->get(CpgUserRepositoryInterface::class)->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(CpgUser::class, $user);
        $userId = $user->getId()->toRfc4122();
        $generation = $user->getInvitationGeneration();
        $container->get(EntityManagerInterface::class)->clear();

        $concurrent = $this->interleaveAfterNextFlush($userId, $generation);
        $container->get(SendAccountInvitationHandler::class)(new SendAccountInvitationMessage($userId, 'fr', $generation));
        $concurrent->wait();
        self::assertSame(0, $concurrent->getExitCode(), $concurrent->getOutput().$concurrent->getErrorOutput());

        self::assertSame(1, $this->tokenCountOf($userId), 'Deux consommateurs du même compte ont laissé deux jetons utilisables (#435).');
        // B a attendu A, puis remplacé son jeton : celui qui reste est le
        // sien, pas celui de l'e-mail que A a envoyé.
        self::assertNotSame([hash('sha256', $this->lastSentSetupToken())], $this->tokenHashesOf($userId));
    }

    /**
     * Un compte supprimé entre son chargement et le verrou (par l'admin, ou
     * par la purge) : le verrou ne trouve pas la ligne, le message est
     * abandonné sans exception — sans quoi l'insertion du jeton échouait sur
     * la clé étrangère et fermait l'EntityManager (seconde passe d'audit).
     */
    public function testAnAccountDeletedBeforeTheLockIsDroppedWithoutError(): void
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();
        $user = $container->get(CpgUserRepositoryInterface::class)->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(CpgUser::class, $user);
        $entityManager->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($user);
        $handler = new SendAccountInvitationHandler(
            $container->get(MailerInterface::class),
            $repository,
            $container->get(PasswordSetupTokenRepositoryInterface::class),
            $container->get(AccountLockInterface::class),
            new MockClock(),
            new NullLogger(),
            'noreply@cp-ghostotof.com',
            'https://front.test',
        );

        $handler(new SendAccountInvitationMessage($user->getId()->toRfc4122(), 'fr', $user->getInvitationGeneration()));

        self::assertTrue($entityManager->isOpen());
        self::assertSame(0, $this->tokenCountOf($user->getId()->toRfc4122()));
        self::assertCount(0, self::getMailerMessages());
    }

    private function lastSentSetupToken(): string
    {
        $emails = self::getMailerMessages();
        $email = end($emails);
        self::assertInstanceOf(TemplatedEmail::class, $email);
        // Rendu, l'e-mail ne garde plus son contexte : le lien se lit dans le corps.
        $body = $email->getTextBody();
        self::assertIsString($body);
        self::assertSame(1, preg_match('~/set-password#([0-9a-f]{64})~', $body, $matches));

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function tokenHashesOf(string $userId): array
    {
        /** @var list<string> */
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            'SELECT token_hash FROM password_setup_token WHERE user_id = :user',
            ['user' => $userId],
        );
    }

    /**
     * Démarre B et le relâche au prochain `postFlush` ({@see FlushInterleaving}) :
     * le jeton de A est inséré, pas encore validé.
     */
    private function interleaveAfterNextFlush(string $userId, int $generation): Process
    {
        $barrier = ProcessBarrier::hold('invitation-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::HANDLER_SCRIPT, $barrier->path(), $userId, (string) $generation);
        $concurrent->start();
        FlushInterleaving::releaseAfterNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    private function tokenCountOf(string $userId): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM password_setup_token WHERE user_id = :user',
            ['user' => $userId],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
