<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Kernel;
use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Le transport `async` publie avec confirmation du broker (issue #479).
 *
 * Sans `confirm_timeout`, AmqpSender n'attend pas l'accusé de RabbitMQ : un
 * refus du broker (message au-delà de `max_message_size`, #477 ; broker qui se
 * coupe) est asynchrone, le `dispatch` réussit, le contrôleur répond 2xx et le
 * message est perdu en silence — ni `async`, ni `failed`. Avec la
 * confirmation, le refus lève une TransportException : un 500 pour
 * `POST /api/contact`, un échec visible pour un réessai du worker.
 *
 * Lu dans chaque environnement déclaré ({@see EnvironmentConfigKernel}, sans
 * compiler) : préprod et prod tournent en `prod`, que le conteneur de test ne
 * voit pas. Le délai est borné : c'est le temps qu'une requête attend, au
 * pire, un broker qui ne répond pas.
 */
final class AmqpPublisherConfirmsTest extends TestCase
{
    public function testTheAsyncTransportWaitsForTheBrokerConfirmationInEveryAmqpEnvironment(): void
    {
        $checked = 0;
        foreach ($this->environments() as $environment) {
            $async = new EnvironmentConfigKernel($environment)->declaredMapping('framework', 'messenger')['transports']['async'] ?? null;
            self::assertIsArray($async, $environment);
            $dsn = $async['dsn'] ?? null;
            self::assertIsString($dsn, $environment);
            if (str_starts_with($dsn, 'in-memory://')) {
                continue;
            }

            $timeout = $async['options']['confirm_timeout'] ?? null;
            self::assertIsNumeric($timeout, \sprintf('%s : transports.async.options.confirm_timeout absent.', $environment));
            self::assertGreaterThan(0, (float) $timeout, $environment);
            self::assertLessThanOrEqual(10, (float) $timeout, \sprintf('%s : une requête attendrait plus de 10 s un broker muet.', $environment));
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'Aucun environnement AMQP vérifié.');
    }

    /**
     * @return list<string>
     */
    private function environments(): array
    {
        /** @var list<string> $allowed */
        $allowed = new ReflectionMethod(Kernel::class, 'getAllowedEnvs')->invoke(new Kernel('test', false));
        self::assertContains('prod', $allowed);

        return $allowed;
    }
}
