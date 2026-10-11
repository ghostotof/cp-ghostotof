<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Le handler d'invitation sur la base `_test` réelle, avec les dépôts du
 * conteneur et un mailer au choix du test : c'est l'état des tables qui est
 * vérifié, pas un appel de bouchon (issues #425, #434).
 *
 * @phpstan-require-extends KernelTestCase
 */
trait RunsInvitationHandlerOnTestDatabase
{
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function invitationHandler(MailerInterface $mailer, LoggerInterface $logger = new NullLogger()): SendAccountInvitationHandler
    {
        $container = self::getContainer();

        return new SendAccountInvitationHandler(
            $mailer,
            $container->get(CpgUserRepositoryInterface::class),
            $container->get(PasswordSetupTokenRepositoryInterface::class),
            $container->get(AccountLockInterface::class),
            new MockClock(),
            $logger,
            'noreply@cp-ghostotof.com',
            'https://front.test',
        );
    }

    /**
     * @param Closure(RawMessage): void $send
     */
    private function mailerThat(Closure $send): MailerInterface
    {
        return new readonly class($send) implements MailerInterface {
            /**
             * @param Closure(RawMessage): void $send
             */
            public function __construct(private Closure $send)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ($this->send)($message);
            }
        };
    }

    private function pendingUser(string $username, string $email): CpgUser
    {
        $user = new CpgUser($username, '');
        $user->setEmail($email);
        $user->markInvited(new DateTimeImmutable('-1 hour'));
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    /**
     * Le message de la dernière invitation du compte, tel que
     * CpgUserInviter le publie.
     */
    private function invitationMessage(CpgUser $user): SendAccountInvitationMessage
    {
        return new SendAccountInvitationMessage($user->getId()->toRfc4122(), 'fr', $user->getInvitationGeneration());
    }

    /**
     * Lu en SQL, hors de l'EntityManager : ce que la base garde vraiment.
     *
     * @return list<string>
     */
    private function tokenHashesOf(CpgUser $user): array
    {
        /** @var list<string> */
        return $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT token_hash FROM password_setup_token WHERE user_id = :user',
            ['user' => $user->getId()->toRfc4122()],
        );
    }

    private function purgeInvitationTables(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->entityManager()->clear();
    }
}
