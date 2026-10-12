<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Exception\InvitationQueueUnavailableException;
use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use App\Tests\Support\UnavailableTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Issue #491 : la conversion vit dans le bus, pas chez chaque producteur. Le
 * bus du conteneur, devant un broker qui ne prend rien, lève l'exception que
 * chaque message routé `async` nomme ; la sonde de #470, qui n'en nomme
 * aucune, garde la TransportException que sa commande cite.
 */
final class AsyncPublishFailureTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{object, class-string<MessageQueueUnavailableException>}>
     */
    public static function producedMessages(): iterable
    {
        yield 'contact' => [
            new SendContactMessageMessage(senderName: 'Jane Doe', senderEmail: 'jane@example.com', body: 'Bonjour.'),
            ContactMessageQueueUnavailableException::class,
        ];
        yield 'invitation' => [
            new SendAccountInvitationMessage(Uuid::v7()->toRfc4122(), 'fr', 1),
            InvitationQueueUnavailableException::class,
        ];
    }

    /**
     * @param class-string<MessageQueueUnavailableException> $expected
     */
    #[DataProvider('producedMessages')]
    public function testTheBusRaisesTheExceptionTheMessageNames(object $message, string $expected): void
    {
        $raised = null;
        try {
            $this->busBehindAnUnavailableBroker()->dispatch($message);
        } catch (Throwable $failure) {
            $raised = $failure;
        }

        self::assertInstanceOf($expected, $raised, 'La panne du broker doit remonter, convertie.');
        self::assertInstanceOf(TransportException::class, $raised->getPrevious());
    }

    public function testTheProbeKeepsTheTransportFailureItsCommandQuotes(): void
    {
        $this->expectException(TransportException::class);

        $this->busBehindAnUnavailableBroker()->dispatch(new MessengerProbeMessage('probe'));
    }

    private function busBehindAnUnavailableBroker(): MessageBusInterface
    {
        self::bootKernel();
        self::getContainer()->set('messenger.transport.async', new UnavailableTransport());

        return self::getContainer()->get(MessageBusInterface::class);
    }
}
