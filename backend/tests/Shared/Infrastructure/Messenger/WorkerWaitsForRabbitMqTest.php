<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le worker attend RabbitMQ avant de démarrer (issue #298).
 *
 * Un déploiement qui recrée `rabbitmq` et `worker` ensemble lançait le worker
 * avant que le broker écoute : `messenger:consume` s'arrêtait sur « Could not
 * connect to the AMQP server » (un CRITICAL), et le kubelet le relançait avec
 * un délai croissant. En production, au déploiement de la v0.18.5 : trois
 * redémarrages, et une file sans consommateur 33 s après que le broker était
 * prêt.
 *
 * Un initContainer attend donc que `rabbitmq:5672` réponde, avec une limite de
 * temps : un broker qui ne vient jamais doit faire échouer le pod
 * franchement, pas le bloquer indéfiniment.
 */
final class WorkerWaitsForRabbitMqTest extends TestCase
{
    private const string INIT_CONTAINER = 'wait-for-rabbitmq';

    public function testTheWorkerWaitsForTheAmqpPortBeforeStarting(): void
    {
        $command = $this->command($this->initContainer());

        self::assertMatchesRegularExpression('/\bnc -z\b[^;]*\brabbitmq 5672\b/', $command, 'L\'attente doit tester rabbitmq:5672 en TCP.');
    }

    public function testTheWaitIsBoundedAndFailsExplicitly(): void
    {
        $command = $this->command($this->initContainer());

        self::assertMatchesRegularExpression('/deadline=/', $command, 'L\'attente doit avoir une échéance.');
        self::assertStringContainsString('exit 1', $command, 'Une échéance dépassée doit faire échouer le pod.');
    }

    /**
     * Même durcissement que les autres conteneurs du dépôt, et aucune image
     * supplémentaire : `nc` vient de BusyBox, déjà dans l'image backend.
     */
    public function testTheWaitingContainerIsHardenedAndUsesTheBackendImage(): void
    {
        $container = $this->initContainer();

        self::assertSame('backend', $container['image'] ?? null);
        $securityContext = $container['securityContext'] ?? null;
        self::assertIsArray($securityContext);
        self::assertTrue($securityContext['runAsNonRoot'] ?? null);
        self::assertTrue($securityContext['readOnlyRootFilesystem'] ?? null);
        self::assertFalse($securityContext['allowPrivilegeEscalation'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function initContainer(): array
    {
        $path = \dirname(__DIR__, 5).'/k8s/base/messenger-worker-deployment.yaml';
        self::assertFileIsReadable($path);
        $resource = Yaml::parse((string) file_get_contents($path));
        self::assertIsArray($resource);

        $spec = $resource['spec'] ?? null;
        $template = \is_array($spec) ? ($spec['template'] ?? null) : null;
        $podSpec = \is_array($template) ? ($template['spec'] ?? null) : null;
        $initContainers = \is_array($podSpec) ? ($podSpec['initContainers'] ?? []) : [];
        self::assertIsArray($initContainers);

        foreach ($initContainers as $container) {
            if (\is_array($container) && self::INIT_CONTAINER === ($container['name'] ?? null)) {
                return $container;
            }
        }

        self::fail(\sprintf('initContainer « %s » absent du worker.', self::INIT_CONTAINER));
    }

    /**
     * @param array<mixed> $container
     */
    private function command(array $container): string
    {
        $command = $container['command'] ?? null;
        self::assertIsArray($command);

        return implode(' ', array_filter($command, \is_string(...)));
    }
}
