<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class SendAccountInvitationHandlerTest extends TestCase
{
    private const string SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string FRONTEND_BASE_URL = 'https://front.test';

    public function testItBuildsATemplatedInvitationEmailWithALocalisedSetupLink(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(['newcomer@example.com'], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame([self::SENDER_EMAIL], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getFrom(),
                ));
                self::assertNotSame('', (string) $email->getSubject());

                self::assertSame('emails/account_invitation.html.twig', $email->getHtmlTemplate());
                self::assertSame('emails/account_invitation.txt.twig', $email->getTextTemplate());

                $context = $email->getContext();
                self::assertSame('newcomer', $context['username']);
                self::assertSame('https://front.test/fr/set-password/abc123def456', $context['setupUrl']);
                self::assertArrayHasKey('strings', $context);

                return true;
            }));

        $handler = new SendAccountInvitationHandler($mailer, self::SENDER_EMAIL, self::FRONTEND_BASE_URL);

        $handler(new SendAccountInvitationMessage(
            recipientEmail: 'newcomer@example.com',
            username: 'newcomer',
            clearToken: 'abc123def456',
            locale: 'fr',
        ));
    }

    public function testItWrapsAMailerTransportFailureIntoADeliveryException(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $transportException = new TransportException('SMTP indisponible.');
        $mailer->expects(self::once())->method('send')->willThrowException($transportException);

        $handler = new SendAccountInvitationHandler($mailer, self::SENDER_EMAIL, self::FRONTEND_BASE_URL);

        try {
            $handler(new SendAccountInvitationMessage(
                recipientEmail: 'newcomer@example.com',
                username: 'newcomer',
                clearToken: 'abc123',
                locale: 'en',
            ));
            self::fail('AccountInvitationDeliveryException aurait dû être levée.');
        } catch (AccountInvitationDeliveryException $exception) {
            self::assertSame($transportException, $exception->getPrevious());
        }
    }
}
