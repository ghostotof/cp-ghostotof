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
 * Sans lui, le test est sauté en le disant — sauf en CI, où il échoue.
 */
final class AmqpPublisherConfirmsIntegrationTest extends TestCase
{
    /** @var array<mixed>|null */
    private static ?array $declaredOptions = null;

    /**
     * Par le refus du broker lui-même, au-delà du max_message_size de 1 Mio
     * (#477) : une autre TransportException (broker injoignable, mauvais
     * identifiants) ne prouverait rien.
     */
    public function testAPublicationTheBrokerRefusesFailsTheSend(): void
    {
        try {
            $this->transport()->send(new Envelope($this->carrying(2 * 1024 * 1024)));
            self::fail('Le broker refuse ce message : l\'envoi doit échouer.');
        } catch (TransportException $refusal) {
            $causes = [];
            for ($cause = $refusal; null !== $cause; $cause = $cause->getPrevious()) {
                $causes[] = $cause->getMessage();
            }
            self::assertStringContainsString('larger than configured max size', implode(' | ', $causes));
        }
    }

    /** Le message accepté est bien dans la file, pas seulement « envoyé ». */
    public function testAPublicationTheBrokerAcceptsReachesTheQueue(): void
    {
        $transport = $this->transport();
        $transport->send(new Envelope($this->carrying(100 * 1024)));

        $received = [...$transport->get()];
        self::assertCount(1, $received);
        $message = $received[0]->getMessage();
        self::assertInstanceOf(stdClass::class, $message);
        self::assertIsString($message->padding ?? null);
        self::assertSame(100 * 1024, \strlen($message->padding));
        $transport->ack($received[0]);
    }

    private function transport(): TransportInterface
    {
        $dsn = getenv('RABBITMQ_INTEGRATION_DSN');
        if (!\is_string($dsn) || '' === $dsn) {
            // En CI, le job démarre le broker : son absence est une faute, pas
            // une raison de passer au vert sans rien vérifier.
            if (false !== getenv('CI')) {
                self::fail('RABBITMQ_INTEGRATION_DSN absent en CI : le job test-backend doit démarrer le broker (#479).');
            }
            self::markTestSkipped('RABBITMQ_INTEGRATION_DSN absent : aucun broker pour l\'intégration (#479).');
        }

        // Une seule construction du noyau de configuration pour la classe.
        $declared = self::$declaredOptions ??= (static function (): array {
            $options = new EnvironmentConfigKernel('prod')->declaredMapping('framework', 'messenger')['transports']['async']['options'] ?? [];
            self::assertIsArray($options);

            return $options;
        })();
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
