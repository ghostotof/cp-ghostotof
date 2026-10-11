<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use App\Tests\Support\RunsInvitationHandlerOnTestDatabase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #434 : un message d'invitation d'une génération dépassée — un
 * réessai différé, ou un message rejoué à la main depuis le
 * `failure_transport` des jours plus tard — ne doit pas purger le jeton d'une
 * relance plus récente, déjà remise. Les messages sont ceux que publie le vrai
 * CpgUserInviter, sur la base `_test` réelle.
 */
final class SendAccountInvitationStaleMessageTest extends KernelTestCase
{
    use RunsInvitationHandlerOnTestDatabase;

    /** @var list<RawMessage> */
    private array $sentEmails = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->logs = new TestHandler();
        $this->purgeInvitationTables();
    }

    protected function tearDown(): void
    {
        $this->purgeInvitationTables();
        parent::tearDown();
    }

    /**
     * Le scénario de l'issue :
     *  1. M1 (invitation) échoue, son réessai attend ;
     *  2. l'admin relance, M2 passe avant le réessai : le jeton TB part ;
     *  3. le réessai de M1 ne touche plus à rien.
     */
    public function testARetryOfAnEarlierInvitationLeavesTheResendsTokenInPlace(): void
    {
        $firstMessage = $this->invite('newcomer@example.com');
        try {
            $this->invitationHandler($this->mailerThat(static fn (): never => throw new TransportException('Connexion impossible')))($firstMessage);
            self::fail('AccountInvitationDeliveryException aurait dû être levée.');
        } catch (AccountInvitationDeliveryException) {
            // Étape 1 : le worker reprogramme M1.
        }

        $user = $this->reloaded($firstMessage);
        // En deux temps : handler() vide l'EntityManager, et PHP l'évaluerait
        // avant reinvite(), qui sauvegarderait alors un compte détaché.
        $resend = $this->reinvite($user);
        $this->handler()($resend);
        $resendTokenHashes = $this->tokenHashesOf($user);
        self::assertCount(1, $resendTokenHashes);
        self::assertSame(1, $this->sentEmailCount());

        $this->handler()($firstMessage);

        self::assertSame($resendTokenHashes, $this->tokenHashesOf($user), 'Le réessai périmé a remplacé le jeton de la relance.');
        self::assertSame(1, $this->sentEmailCount(), 'Le réessai périmé a envoyé un second e-mail.');
        self::assertTrue($this->logs->hasRecordThatPasses(
            static fn (LogRecord $record): bool => str_starts_with($record->message, 'Invitation ignorée')
                && ['userId' => $user->getId()->toRfc4122()] === $record->context,
            Level::Warning,
        ), 'Le message périmé doit être écarté par un warning, sans exception (pas de réessai).');
    }

    /**
     * L'instant ne compte pas, seule la génération : deux relances dans la
     * même seconde (un double clic sur « Renvoyer ») se distinguent.
     */
    public function testTheEarlierOfTwoResendsInTheSameSecondIsStale(): void
    {
        $first = $this->reinvite($this->reloaded($this->invite('newcomer@example.com')));
        $second = $this->reinvite($this->reloaded($first));

        $this->handler()($second);
        $this->handler()($first);

        $user = $this->reloaded($second);
        self::assertSame(1, $this->sentEmailCount());
        self::assertCount(1, $this->tokenHashesOf($user));
    }

    /**
     * Un message d'avance sur la base (la relance n'est pas encore commitée
     * quand le worker le lit) est traité : le dernier message ne se perd
     * jamais.
     */
    public function testAMessageAheadOfTheStoredGenerationIsProcessed(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $ahead = new SendAccountInvitationMessage($user->getId()->toRfc4122(), 'fr', $user->getInvitationGeneration() + 1);

        $this->handler()($ahead);

        self::assertSame(1, $this->sentEmailCount());
        self::assertCount(1, $this->tokenHashesOf($user));
    }

    /**
     * Un message mis en file avant #434 ne porte pas de génération : il reste
     * traité comme avant, plutôt que de planter le worker à la lecture de la
     * propriété. Forme sérialisée littérale, celle que PhpSerializer a écrite
     * dans RabbitMQ ou le `failure_transport`.
     */
    public function testAMessageQueuedBeforeTheGenerationExistedIsStillProcessed(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $userId = $user->getId()->toRfc4122();
        $legacy = unserialize(\sprintf(
            'O:%d:"%s":2:{s:6:"userId";s:%d:"%s";s:6:"locale";s:2:"fr";}',
            \strlen(SendAccountInvitationMessage::class),
            SendAccountInvitationMessage::class,
            \strlen($userId),
            $userId,
        ));
        self::assertInstanceOf(SendAccountInvitationMessage::class, $legacy);

        $this->handler()($legacy);

        self::assertSame(1, $this->sentEmailCount());
        self::assertCount(1, $this->tokenHashesOf($user));
    }

    private function invite(string $email): SendAccountInvitationMessage
    {
        self::getContainer()->get(CpgUserInviterInterface::class)->invite($email, Locale::FR);

        return $this->lastPublishedInvitation();
    }

    private function reinvite(CpgUser $user): SendAccountInvitationMessage
    {
        self::getContainer()->get(CpgUserInviterInterface::class)->reinvite($user, Locale::FR);

        return $this->lastPublishedInvitation();
    }

    private function lastPublishedInvitation(): SendAccountInvitationMessage
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertNotEmpty($envelopes, 'Aucun message d\'invitation publié.');
        $message = end($envelopes)->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);

        return $message;
    }

    /**
     * Le worker relit le compte en base, jamais l'instance du dispatch.
     */
    private function reloaded(SendAccountInvitationMessage $message): CpgUser
    {
        $this->entityManager()->clear();
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneById(Uuid::fromString($message->userId));
        self::assertNotNull($user);

        return $user;
    }

    private function handler(): SendAccountInvitationHandler
    {
        $this->entityManager()->clear();

        return $this->invitationHandler(
            $this->mailerThat(function (RawMessage $message): void {
                $this->sentEmails[] = $message;
            }),
            new Logger('test', [$this->logs]),
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
}
