<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Tests\Support\ReadsAllChannelsLog;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les 4xx du framework sur la vraie pile — routeur et `HttpKernel` — sortent en
 * `info` (audit de #675, C11, décidé le 2026-10-21) : n'importe quel client les
 * produit, et en `error` chacun écrivait une ligne d'incident à volonté,
 * bornée par les seules zones nginx. Leur trace retenue est l'access log de
 * nginx (statut, IP), comme celle du corps illisible (#355).
 *
 * `ApiExceptionLogLevelTest` lit le niveau sur l'`ErrorListener` seul ; ici,
 * c'est l'exception que le noyau lève vraiment qui est jugée — une route
 * inconnue, une méthode refusée, un hôte illisible converti en
 * `BadRequestHttpException` par le routeur.
 */
final class FrameworkClientErrorsLogLevelTest extends WebTestCase
{
    use ReadsAllChannelsLog;

    /**
     * @return iterable<string, array{string, string, array<string, string>, int}>
     */
    public static function frameworkClientErrors(): iterable
    {
        yield 'route inconnue (404)' => ['GET', '/api/nowhere', [], 404];
        yield 'méthode refusée par le routeur (405)' => ['DELETE', '/api/about/en', [], 405];
        // KernelBrowser appelle depuis 127.0.0.1, un proxy de confiance :
        // l'hôte transmis est lu, et refusé au routage.
        yield 'hôte illisible (400)' => ['GET', '/api/about/en', ['HTTP_X_FORWARDED_HOST' => 'local host'], 400];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('frameworkClientErrors')]
    public function testTheKernelLogsItAsAClientError(string $method, string $path, array $server, int $status): void
    {
        $client = self::createClient();

        $client->request($method, $path, server: $server);
        self::assertResponseStatusCodeSame($status);

        $uncaught = self::uncaughtExceptionRecords();
        self::assertCount(1, $uncaught);
        self::assertSame(Level::Info, $uncaught[0]->level, $uncaught[0]->message);
    }
}
