<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use App\Tests\Support\RunsInvitationHandlerOnTestDatabase;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Issue #425 : le jeton d'invitation est validé en base avant que l'e-mail ne
 * parte. Si l'envoi échoue, ce jeton n'a été remis à personne : il ne doit
 * pas rester utilisable 48 h en attendant une fuite. Base `_test` réelle
 * (RunsInvitationHandlerOnTestDatabase).
 */
final class SendAccountInvitationDeliveryFailureTest extends KernelTestCase
{
    use RunsInvitationHandlerOnTestDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purgeInvitationTables();
    }

    protected function tearDown(): void
    {
        $this->purgeInvitationTables();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function deliveryFailures(): iterable
    {
        yield 'transport' => [new TransportException('550 5.1.1 Recipient address rejected', 550)];
        // Rendu du gabarit, ou listener de MessageEvent : tout autre échec de send().
        yield 'autre échec de l\'envoi' => [new LogicException('rendu impossible')];
    }

    #[DataProvider('deliveryFailures')]
    public function testAFailedDeliveryLeavesNoTokenOfTheInvitation(Throwable $failure): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $mailer = $this->mailerThat(function () use ($user, $failure): never {
            // Sans ce contrôle, la table vide en fin de test passerait aussi
            // pour un jeton jamais validé.
            self::assertCount(1, $this->tokenHashesOf($user), 'Le jeton doit être validé en base avant l\'envoi.');

            throw $failure;
        });

        $this->expectDeliveryFailure($this->invitationHandler($mailer), $user);

        self::assertSame([], $this->tokenHashesOf($user));
    }

    /**
     * Le retrait ne dépend pas de l'unité de travail : un `clear()` de
     * l'EntityManager pendant l'envoi (un listener, un middleware) détache le
     * jeton, et un `EntityManager::remove()` lèverait alors au lieu de le
     * supprimer.
     */
    public function testAFailedDeliveryRemovesTheTokenEvenOnceDetachedFromTheEntityManager(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $mailer = $this->mailerThat(function (): never {
            $this->entityManager()->clear();

            throw new TransportException('Connexion impossible');
        });

        $this->expectDeliveryFailure($this->invitationHandler($mailer), $user);

        self::assertSame([], $this->tokenHashesOf($user));
    }

    /**
     * Une adresse que Mime refuse fait échouer la construction de l'e-mail,
     * avant send() : le jeton est déjà en base là aussi.
     */
    public function testARecipientAddressMimeRefusesLeavesNoToken(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer..refused@example.com');
        $mailer = $this->mailerThat(static fn (): never => throw new LogicException('send() ne doit pas être atteint.'));

        $this->expectDeliveryFailure($this->invitationHandler($mailer), $user);

        self::assertSame([], $this->tokenHashesOf($user));
    }

    /**
     * Le cycle de réessai de Messenger reste intact : le passage suivant crée
     * un nouveau jeton, et c'est celui de l'e-mail parti qui reste en base.
     */
    public function testTheRetryAfterAFailedDeliveryIssuesTheOnlyRemainingToken(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $attempts = 0;
        $sentEmail = null;
        $mailer = $this->mailerThat(static function (RawMessage $message) use (&$attempts, &$sentEmail): void {
            if (1 === ++$attempts) {
                throw new TransportException('Connexion impossible');
            }
            $sentEmail = $message;
        });
        $handler = $this->invitationHandler($mailer);

        $this->expectDeliveryFailure($handler, $user);
        $handler($this->invitationMessage($user));

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        $setupUrl = $sentEmail->getContext()['setupUrl'] ?? null;
        self::assertIsString($setupUrl);
        self::assertSame(1, preg_match('~#([0-9a-f]{64})\z~', $setupUrl, $matches));
        self::assertSame([hash('sha256', $matches[1])], $this->tokenHashesOf($user));
    }

    /**
     * Seul le jeton de ce passage est retiré. Un second consommateur (un
     * `messenger:consume failed` lancé à la main pendant que le worker tourne)
     * a pu purger ce jeton et émettre le sien, déjà parti : supprimer tous les
     * jetons du compte le casserait. Simulé sur la même connexion : le test
     * fixe le retrait par identifiant, pas l'isolation de deux transactions.
     */
    public function testAFailedDeliveryRemovesItsOwnTokenOnly(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $concurrentHash = hash('sha256', 'jeton-du-worker-concurrent');
        $repository = self::getContainer()->get(PasswordSetupTokenRepositoryInterface::class);
        $mailer = $this->mailerThat(function () use ($repository, $user, $concurrentHash): never {
            // L'autre worker, entre la création de notre jeton et l'échec.
            $repository->deleteForUser($user);
            $repository->save(new PasswordSetupToken($user, $concurrentHash, new DateTimeImmutable('+48 hours')));

            throw new TransportException('Connexion impossible');
        });

        $this->expectDeliveryFailure($this->invitationHandler($mailer), $user);

        self::assertSame([$concurrentHash], $this->tokenHashesOf($user));
    }

    private function expectDeliveryFailure(SendAccountInvitationHandler $handler, CpgUser $user): void
    {
        try {
            $handler($this->invitationMessage($user));
            self::fail('AccountInvitationDeliveryException aurait dû être levée.');
        } catch (AccountInvitationDeliveryException $exception) {
            self::assertNull($exception->getPrevious());
        }
    }
}
