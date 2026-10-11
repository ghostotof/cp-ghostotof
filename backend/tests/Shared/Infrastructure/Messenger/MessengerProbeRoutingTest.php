<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\CacheMarkedMessengerProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
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

    /**
     * Le handler est bien celui du bus : sans lui, chaque smoke préprod
     * échouerait, et on ne le verrait qu'en préprod. Envoyé sur `sync`, le
     * témoin est traité ici même et sa marque se relit dans `cache.app`.
     */
    public function testTheContainerHandlesTheProbeMessage(): void
    {
        self::bootKernel();
        $probeId = bin2hex(random_bytes(16));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MessengerProbeMessage($probeId), [new TransportNamesStamp(['sync'])]);

        $cache = self::getContainer()->get('cache.app');
        self::assertTrue($cache->getItem(CacheMarkedMessengerProbe::markerKey($probeId))->isHit());
        $cache->deleteItem(CacheMarkedMessengerProbe::markerKey($probeId));
    }
}
