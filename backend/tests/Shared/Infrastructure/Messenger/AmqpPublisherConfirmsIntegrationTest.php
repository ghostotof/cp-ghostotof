<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Contre un vrai RabbitMQ portant k8s/base/rabbitmq.conf (issue #479) : une
 * publication que le broker refuse fait échouer l'envoi, au lieu d'être
 * perdue en silence.
 *
 * Le transport est construit par la fabrique de Messenger avec les options
 * que la configuration déclare en `prod` (`confirm_timeout` compris), et le
 * sérialiseur de l'application. Seuls l'échange et la file sont des jetables
 * propres au test (file qui expire, échange supprimé avec elle) : la file
 * `messages` des autres n'est jamais touchée.
 *
 * Le broker est donné par RABBITMQ_INTEGRATION_DSN : celui de Compose en dev
 * (docker-compose.yml), un conteneur démarré par le job `test-backend` en CI.
 * Sans lui, le test est sauté en le disant.
 */
final class AmqpPublisherConfirmsIntegrationTest extends TestCase
{
    public function testAPublicationTheBrokerRefusesFailsTheSend(): void
    {
        $transport = $this->transport();

        $this->expectException(TransportException::class);

        // Au-delà du max_message_size de 1 Mio (#477).
        $transport->send(new Envelope($this->carrying(2 * 1024 * 1024)));
    }

    public function testAPublicationTheBrokerAcceptsIsSent(): void
    {
        $sent = $this->transport()->send(new Envelope($this->carrying(100 * 1024)));

        self::assertInstanceOf(stdClass::class, $sent->getMessage());
    }

    private function transport(): TransportInterface
    {
        $dsn = getenv('RABBITMQ_INTEGRATION_DSN');
        if (!\is_string($dsn) || '' === $dsn) {
            self::markTestSkipped('RABBITMQ_INTEGRATION_DSN absent : aucun broker pour l\'intégration (#479).');
        }

        $declared = new EnvironmentConfigKernel('prod')->declaredMapping('framework', 'messenger')['transports']['async']['options'] ?? [];
        self::assertIsArray($declared);
        $name = 'it479-'.bin2hex(random_bytes(4));
        $options = [
            ...$declared,
            'exchange' => ['name' => $name, 'type' => 'fanout', 'flags' => \AMQP_AUTODELETE],
            'queues' => [$name => ['arguments' => ['x-expires' => 60000]]],
        ];

        return new AmqpTransportFactory()->createTransport($dsn, $options, new AuthenticatedEnvelopeSerializer(new PhpSerializer(), TestKeys::material()));
    }

    private function carrying(int $bytes): stdClass
    {
        $message = new stdClass();
        $message->padding = str_repeat('x', $bytes);

        return $message;
    }
}
