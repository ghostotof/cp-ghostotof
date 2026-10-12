<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport dont le broker ne prend rien (issue #485) : ce que lève
 * AmqpSender quand RabbitMQ est arrêté, refuse le message ou ne l'accuse pas
 * (#479). Substitué à `messenger.transport.async` dans les tests de bout en
 * bout, il fait passer l'échec par la vraie chaîne : processor, use case, bus,
 * SendMessageMiddleware.
 */
final class UnavailableTransport implements TransportInterface
{
    public function send(Envelope $envelope): Envelope
    {
        throw new TransportException('Could not connect to the AMQP server. Please verify the provided DSN.');
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}
