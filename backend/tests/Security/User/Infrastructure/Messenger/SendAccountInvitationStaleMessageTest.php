<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Issue #434 : un message d'invitation antérieur à la dernière (re)invitation
 * du compte — un réessai différé, ou un message rejoué à la main depuis le
 * `failure_transport` des jours plus tard — ne doit pas purger le jeton d'une
 * relance plus récente, déjà remise. Base `_test` réelle : c'est l'état de la
 * table qui est vérifié.
 */
final class SendAccountInvitationStaleMessageTest extends KernelTestCase
{
    private const string FIRST_INVITATION = '2026-10-11 09:00:00';
    private const string REINVITATION = '2026-10-11 09:05:00';

    private EntityManagerInterface $em;

    /** @var list<RawMessage> */
    private array $sentEmails = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->logs = new TestHandler();

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Le scénario de l'issue : M1 échoue et part en réessai, l'admin relance,
     * M2 passe avant le réessai de M1 et son lien arrive. Le réessai de M1 ne
     * touche plus à rien.
     */
    public function testAMessageOlderThanTheLastInvitationLeavesTheCurrentTokenInPlace(): void
    {
        $user = $this->pendingUser(self::FIRST_INVITATION);
        $firstMessage = $this->messageFor($user);

        $this->reinvite($user, self::REINVITATION);
        $this->handler()($this->messageFor($user));
        $currentTokenHashes = $this->tokenHashesOf($user);
        self::assertCount(1, $currentTokenHashes);
        self::assertSame(1, $this->sentEmailCount());

        $this->handler()($firstMessage);

        self::assertSame($currentTokenHashes, $this->tokenHashesOf($user), 'Le message périmé a remplacé le jeton de la relance.');
        self::assertSame(1, $this->sentEmailCount(), 'Le message périmé a envoyé un second e-mail.');
        self::assertTrue($this->logs->hasRecordThatPasses(
            static fn (LogRecord $record): bool => str_starts_with($record->message, 'Invitation ignorée')
                && ['userId' => $user->getId()->toRfc4122()] === $record->context,
            Level::Warning,
        ), 'Le message périmé doit être écarté par un warning, sans exception (pas de réessai).');
    }

    /**
     * La colonne `invited_at` est à la seconde : un message de la même seconde
     * que la dernière invitation est celui de cette invitation.
     */
    public function testAMessageOfTheLastInvitationIsProcessed(): void
    {
        $user = $this->pendingUser(self::FIRST_INVITATION);

        $this->handler()($this->messageFor($user));

        self::assertCount(1, $this->tokenHashesOf($user));
        self::assertSame(1, $this->sentEmailCount());
    }

    /**
     * Un message mis en file avant #434 ne porte pas `invitedAt` : il reste
     * traité comme avant, plutôt que de planter le worker à la lecture de la
     * propriété. Forme sérialisée littérale, celle que PhpSerializer a écrite
     * dans RabbitMQ ou le `failure_transport`.
     */
    public function testAMessageQueuedBeforeTheInvitationInstantExistedIsStillProcessed(): void
    {
        $user = $this->pendingUser(self::FIRST_INVITATION);
        $userId = $user->getId()->toRfc4122();
        $legacy = unserialize(\sprintf(
            'O:%d:"%s":2:{s:6:"userId";s:%d:"%s";s:6:"locale";s:2:"fr";}',
            \strlen(SendAccountInvitationMessage::class),
            SendAccountInvitationMessage::class,
            \strlen($userId),
            $userId,
        ));
        self::assertInstanceOf(SendAccountInvitationMessage::class, $legacy);
        self::assertNull($legacy->invitedAt);

        $this->handler()($legacy);

        self::assertCount(1, $this->tokenHashesOf($user));
        self::assertSame(1, $this->sentEmailCount());
    }

    private function messageFor(CpgUser $user): SendAccountInvitationMessage
    {
        $invitedAt = $user->getInvitedAt();
        self::assertNotNull($invitedAt);

        return new SendAccountInvitationMessage($user->getId()->toRfc4122(), 'fr', $invitedAt->getTimestamp());
    }

    private function handler(): SendAccountInvitationHandler
    {
        $container = self::getContainer();
        // Le worker relit le compte en base, jamais l'instance du dispatch.
        $this->em->clear();

        return new SendAccountInvitationHandler(
            $this->recordingMailer(),
            $container->get(CpgUserRepositoryInterface::class),
            $container->get(PasswordSetupTokenRepositoryInterface::class),
            $this->em,
            new MockClock(self::REINVITATION),
            new Logger('test', [$this->logs]),
            'noreply@cp-ghostotof.com',
            'https://front.test',
        );
    }

    /**
     * Par une méthode : PHPStan figerait la propriété après une première
     * assertion, alors que le handler suivant la remplit.
     */
    private function sentEmailCount(): int
    {
        return \count($this->sentEmails);
    }

    private function recordingMailer(): MailerInterface
    {
        return new readonly class(function (RawMessage $message): void {
            $this->sentEmails[] = $message;
        }) implements MailerInterface {
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

    private function pendingUser(string $invitedAt): CpgUser
    {
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new DateTimeImmutable($invitedAt));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Ce que fait CpgUserInviter::reinvite() avant de dispatcher.
     */
    private function reinvite(CpgUser $user, string $at): void
    {
        $reloaded = $this->em->find(CpgUser::class, $user->getId());
        self::assertNotNull($reloaded);
        $reloaded->markInvited(new DateTimeImmutable($at));
        $this->em->flush();
        $user->markInvited(new DateTimeImmutable($at));
    }

    /**
     * Lu en SQL, hors de l'EntityManager : ce que la base garde vraiment.
     *
     * @return list<string>
     */
    private function tokenHashesOf(CpgUser $user): array
    {
        /** @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT token_hash FROM password_setup_token WHERE user_id = :user',
            ['user' => $user->getId()->toRfc4122()],
        );
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}

