<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use App\Contact\Infrastructure\Messenger\SendContactMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class SendContactMessageHandlerTest extends TestCase
{
    private const string CONTACT_SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string CONTACT_RECIPIENT_EMAIL = 'contact@cp-ghostotof.com';

    public function testItSendsATemplatedEmailToTheRecipientWithReplyToTheVisitorAndSenderIdentityInContext(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame([self::CONTACT_SENDER_EMAIL], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getFrom(),
                ));
                self::assertSame([self::CONTACT_RECIPIENT_EMAIL], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame(['jane@example.com'], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getReplyTo(),
                ));
                self::assertStringContainsString('Jane Doe', (string) $email->getSubject());

                // Le corps est désormais rendu par Twig à l'envoi ; le handler
                // ne fait que désigner les templates et fournir le contexte.
                self::assertSame('emails/contact_notification.html.twig', $email->getHtmlTemplate());
                self::assertSame('emails/contact_notification.txt.twig', $email->getTextTemplate());
                self::assertSame([
                    'senderName' => 'Jane Doe',
                    'senderEmail' => 'jane@example.com',
                    'body' => 'Bonjour, ceci est un message.',
                ], $email->getContext());

                return true;
            }));

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        $handler(new SendContactMessageMessage(
            senderName: 'Jane Doe',
            senderEmail: 'jane@example.com',
            body: 'Bonjour, ceci est un message.',
        ));
    }

    public function testItWrapsAMailerTransportFailureIntoAContactMessageDeliveryException(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $transportException = new TransportException('SMTP indisponible.');
        $mailer->expects(self::once())->method('send')->willThrowException($transportException);

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        try {
            $handler(new SendContactMessageMessage(
                senderName: 'Jane Doe',
                senderEmail: 'jane@example.com',
                body: 'Bonjour, ceci est un message.',
            ));
            self::fail('ContactMessageDeliveryException aurait dû être levée.');
        } catch (ContactMessageDeliveryException $exception) {
            self::assertSame($transportException, $exception->getPrevious());
        }
    }
}
