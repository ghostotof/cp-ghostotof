<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Les sondes de RabbitMQ ne doivent pas tuer un broker qui démarre (issue #295).
 *
 * En production, le pod a redémarré neuf fois en huit jours. Au dernier
 * redémarrage, RabbitMQ était prêt en 51 s mais a reçu un SIGTERM à 86 s :
 * la livenessProbe `rabbitmq-diagnostics -q ping` démarre son propre nœud
 * Erlang à chaque appel, ce qui dépasse son délai de 10 s quand le CPU est
 * chargé, précisément pendant un démarrage. Trois échecs, et le kubelet
 * redémarre un broker qui fonctionnait, ce qui relance un démarrage lent.
 *
 * La correction suit le RabbitMQ Cluster Operator : des sondes TCP sur le
 * port AMQP, qui ne coûtent rien, et une startupProbe qui laisse au démarrage
 * le temps qu'il lui faut.
 */
final class RabbitMqProbesTest extends TestCase
{
    private const int AMQP_PORT = 5672;

    /** Temps laissé au démarrage : plus de trois fois les 51 s observées. */
    private const int MIN_STARTUP_BUDGET_SECONDS = 180;

    public function testAStartupProbeGivesTheBrokerTimeToBoot(): void
    {
        $probe = $this->probe('startupProbe');

        $budget = $this->int($probe, 'periodSeconds', 10) * $this->int($probe, 'failureThreshold', 3);
        self::assertGreaterThanOrEqual(self::MIN_STARTUP_BUDGET_SECONDS, $budget, \sprintf('La startupProbe ne laisse que %d s au démarrage.', $budget));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function probes(): iterable
    {
        yield 'startupProbe' => ['startupProbe'];
        yield 'livenessProbe' => ['livenessProbe'];
        yield 'readinessProbe' => ['readinessProbe'];
    }

    /**
     * Aucune sonde ne lance `rabbitmq-diagnostics` : un nœud Erlang par appel
     * est ce qui dépassait le délai sous charge.
     */
    #[DataProvider('probes')]
    public function testEveryProbeIsACheapTcpCheckOnTheAmqpPort(string $name): void
    {
        $probe = $this->probe($name);

        self::assertArrayNotHasKey('exec', $probe, \sprintf('%s ne doit pas lancer de commande.', $name));
        $tcpSocket = $probe['tcpSocket'] ?? null;
        self::assertIsArray($tcpSocket, \sprintf('%s doit être une sonde tcpSocket.', $name));
        self::assertSame(self::AMQP_PORT, $tcpSocket['port'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function probe(string $name): array
    {
        $probe = $this->container()[$name] ?? null;
        self::assertIsArray($probe, \sprintf('%s absente du conteneur rabbitmq.', $name));

        return $probe;
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        $path = \dirname(__DIR__, 5).'/k8s/base/rabbitmq.yaml';
        self::assertFileIsReadable($path);
        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        self::assertIsArray($documents);

        foreach ($documents as $document) {
            $resource = Yaml::parse($document);
            if (!\is_array($resource) || 'Deployment' !== ($resource['kind'] ?? null)) {
                continue;
            }
            $spec = $resource['spec'] ?? null;
            $template = \is_array($spec) ? ($spec['template'] ?? null) : null;
            $podSpec = \is_array($template) ? ($template['spec'] ?? null) : null;
            $containers = \is_array($podSpec) ? ($podSpec['containers'] ?? null) : null;
            self::assertIsArray($containers);

            foreach ($containers as $container) {
                if (\is_array($container) && 'rabbitmq' === ($container['name'] ?? null)) {
                    return $container;
                }
            }
        }

        self::fail('Conteneur rabbitmq introuvable dans k8s/base/rabbitmq.yaml.');
    }

    /**
     * @param array<mixed> $probe
     */
    private function int(array $probe, string $key, int $kubernetesDefault): int
    {
        $value = $probe[$key] ?? $kubernetesDefault;
        self::assertIsInt($value);

        return $value;
    }
}
