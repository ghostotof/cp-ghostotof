<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Le témoin passe par la file, jamais par un traitement synchrone (issue
 * #470) : traité dans le processus qui l'envoie, il prouverait que le Job sait
 * se répondre, pas que le worker décode ce que l'API signe.
 */
final class MessengerProbeRoutingTest extends KernelTestCase
{
    public function testTheProbeMessageIsRoutedToTheAsyncTransport(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MessengerProbeMessage(str_repeat('c', 32)));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(MessengerProbeMessage::class, $sent[0]->getMessage());
        $transport->reset();
    }
}
