<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;

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
    /**
     * L'attente de la confirmation, et celles qui la précèdent : ext-amqp les
     * laisse illimitées par défaut, et `confirm.select` comme la déclaration de
     * l'échange sont des allers-retours. Un broker qui accepte la connexion TCP
     * sans répondre tenait une requête jusqu'au `request_terminate_timeout` de
     * 65 s (relecture de #479).
     */
    private const array BOUNDED_WAITS = ['confirm_timeout', 'connect_timeout', 'read_timeout', 'write_timeout', 'rpc_timeout'];

    public function testTheAsyncTransportWaitsForTheBrokerConfirmationInEveryAmqpEnvironment(): void
    {
        $checked = 0;
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $async = new EnvironmentConfigKernel($environment)->declaredMapping('framework', 'messenger')['transports']['async'] ?? null;
            self::assertIsArray($async, $environment);
            $dsn = $async['dsn'] ?? null;
            self::assertIsString($dsn, $environment);
            if (str_starts_with($dsn, 'in-memory://')) {
                continue;
            }

            foreach (self::BOUNDED_WAITS as $option) {
                $timeout = $async['options'][$option] ?? null;
                self::assertIsNumeric($timeout, \sprintf('%s : transports.async.options.%s absent.', $environment, $option));
                self::assertGreaterThan(0, (float) $timeout, $environment.' '.$option);
                self::assertLessThanOrEqual(10, (float) $timeout, \sprintf('%s : %s ferait attendre une requête plus de 10 s.', $environment, $option));
            }
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'Aucun environnement AMQP vérifié.');
    }
}
