<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use App\Contact\Infrastructure\Messenger\SendContactMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class SendContactMessageHandlerTest extends TestCase
{
    private const string CONTACT_RECIPIENT_EMAIL = 'contact@cp-ghostotof.com';

    public function testItSendsAnEmailToTheContactRecipientWithReplyToSetToTheVisitor(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertSame([self::CONTACT_RECIPIENT_EMAIL], array_map(
                    static fn ($address) => $address->getAddress(),
                    $email->getFrom(),
                ));
                self::assertSame([self::CONTACT_RECIPIENT_EMAIL], array_map(
                    static fn ($address) => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame(['jane@example.com'], array_map(
                    static fn ($address) => $address->getAddress(),
                    $email->getReplyTo(),
                ));
                self::assertStringContainsString('Jane Doe', (string) $email->getSubject());
                self::assertSame('Bonjour, ceci est un message.', $email->getTextBody());

                return true;
            }));

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_RECIPIENT_EMAIL);

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

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_RECIPIENT_EMAIL);

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
