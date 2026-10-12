<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Kernel;
use App\Shared\Application\Message\AsyncMessageInterface;
use App\Shared\Application\Message\MessengerProbeMessage;
use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tout message que le bus publie dans une file nomme l'exception de son
 * contexte (issue #491) : il implémente {@see AsyncMessageInterface}, que
 * {@see \App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware}
 * lève quand la file ne le prend pas. Sans elle, un nouveau producteur
 * renverrait le 500 `critical` générique de la TransportException, que #485
 * a supprimé, et rien ne rougirait.
 *
 * La garde porte sur le routage, pas sur la forme du code des producteurs :
 * chaque clé de `framework.messenger.routing` qui envoie vers un transport
 * publiant (tout DSN autre que `sync://`, `async` comme `failed`) doit être
 * une classe ou une interface sous-type d'AsyncMessageInterface. Un joker,
 * d'espace de noms ou universel (`*`), est refusé : les messages qu'il atteint
 * ne se listent pas. Seule issue, une entrée justifiée de NO_CONVERSION.
 *
 * La configuration déclarée est lue dans **chaque** environnement que le
 * noyau admet ({@see EnvironmentConfigKernel}, sans compiler), comme
 * SendEmailMessageStaysSynchronousTest : préprod et prod tournent en `prod`.
 */
final class AsyncMessagesNameTheirQueueFailureTest extends TestCase
{
    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/AsyncRoutingProject';

    /**
     * Messages publiés sans conversion, chacun justifié.
     */
    private const array NO_CONVERSION = [
        MessengerProbeMessage::class => 'Témoin du smoke préprod (#470), publié par app:messenger:probe, jamais depuis une requête HTTP : la commande rattrape elle-même la TransportException pour la citer dans les journaux du Job.',
    ];

    public function testEveryPublishedMessageOfTheApplicationNamesItsQueueFailure(): void
    {
        self::assertSame([], $this->unconvertedRoutings(null), 'Un message routé vers une file doit implémenter AsyncMessageInterface (issue #491), sinon une panne du broker redevient un 500 critical.');
    }

    /**
     * Une garde qui ne verrait rien serait verte à vide : chaque forme de
     * routage fautive de la fixture, dans son environnement, doit ressortir,
     * et aucune des deux admises.
     */
    public function testEveryFormOfUnconvertedRoutingIsCaughtInItsEnvironment(): void
    {
        self::assertSame([
            'dev : App\Tests\Shared\Infrastructure\Messenger\Fixtures\* → async',
            'prod : App\Tests\Shared\Infrastructure\Messenger\Fixtures\UnconvertedQueuedMessage → failed',
            'test : * → async',
        ], $this->unconvertedRoutings(self::FIXTURE_PROJECT));
    }

    /**
     * Une exception qui n'excuse plus rien se retire : le message est encore
     * routé vers une file, et ne nomme toujours aucune exception.
     */
    public function testEveryJustifiedExceptionIsStillNeeded(): void
    {
        foreach (array_keys(self::NO_CONVERSION) as $message) {
            // class_implements() plutôt que is_a() : PHPStan juge ce dernier d'avance
            // sur une clé littérale, alors que le test doit lire la classe telle qu'elle est.
            self::assertNotContains(AsyncMessageInterface::class, class_implements($message), \sprintf('%s nomme son exception : le retirer de NO_CONVERSION.', $message));
            self::assertContains($message, $this->publishedKeys(), \sprintf('%s n\'est plus publié dans une file : le retirer de NO_CONVERSION.', $message));
        }
    }

    /**
     * @return list<string> « env : clé → transport » publiant sans conversion, triées
     */
    private function unconvertedRoutings(?string $projectDirectory): array
    {
        $found = [];
        foreach ($this->environments() as $environment) {
            foreach ($this->publishingRoutes($environment, $projectDirectory) as [$key, $transport]) {
                if (!is_a($key, AsyncMessageInterface::class, true) && !\array_key_exists($key, self::NO_CONVERSION)) {
                    $found[] = \sprintf('%s : %s → %s', $environment, $key, $transport);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> les clés publiées de l'application, tous environnements confondus
     */
    private function publishedKeys(): array
    {
        $keys = [];
        foreach ($this->environments() as $environment) {
            foreach ($this->publishingRoutes($environment, null) as [$key]) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Les couples (clé de routage, transport) d'un environnement dont le
     * transport publie : tout transport déclaré hors `sync://`, et tout nom
     * qui n'est pas un transport déclaré (un service émetteur), par prudence.
     *
     * @return list<array{string, string}>
     */
    private function publishingRoutes(string $environment, ?string $projectDirectory): array
    {
        $messenger = new EnvironmentConfigKernel($environment, $projectDirectory)->declaredMapping('framework', 'messenger');
        $routing = $messenger['routing'] ?? [];
        $transports = $messenger['transports'] ?? [];
        self::assertIsArray($routing);
        self::assertIsArray($transports);

        $routes = [];
        foreach ($routing as $key => $senders) {
            self::assertIsArray($senders);
            foreach ($senders as $sender) {
                self::assertIsString($sender);
                if (!$this->isSynchronous($transports[$sender] ?? null)) {
                    $routes[] = [(string) $key, $sender];
                }
            }
        }

        return $routes;
    }

    private function isSynchronous(mixed $transport): bool
    {
        $dsn = \is_array($transport) ? ($transport['dsn'] ?? null) : $transport;

        return \is_string($dsn) && str_starts_with($dsn, 'sync://');
    }

    /**
     * Lus dans {@see Kernel::getAllowedEnvs()} plutôt que recopiés, pour qu'un
     * environnement ajouté soit vérifié sans qu'on y pense.
     *
     * @return list<string>
     */
    private function environments(): array
    {
        /** @var list<string> $allowed */
        $allowed = new ReflectionMethod(Kernel::class, 'getAllowedEnvs')->invoke(new Kernel('test', false));
        self::assertContains('prod', $allowed, 'Le noyau n\'admet plus `prod` : revoir ce test.');

        return $allowed;
    }
}
