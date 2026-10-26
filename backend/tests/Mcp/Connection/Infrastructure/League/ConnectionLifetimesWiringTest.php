<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les durées de la connexion MCP viennent de la configuration (#649) : le
 * conteneur construit ConnectionLifetimes depuis les paramètres
 * `app.mcp.lifetime.*` de `config/services.yaml`, et le serveur d'autorisation
 * de league émet ses jetons avec ces durées-là — aucune constante n'en garde
 * une copie.
 *
 * Un WebTestCase : la seconde vérification traverse le parcours OAuth par
 * HTTP (DrivesTheOAuthFlow), jusqu'à l'échange d'un code.
 */
final class ConnectionLifetimesWiringTest extends WebTestCase
{
    use DrivesTheOAuthFlow;

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testTheContainerBuildsTheConfiguredLifetimes(): void
    {
        $lifetimes = $this->lifetimes();

        self::assertSame($this->shapeOf('PT10M'), $this->shapeOf($lifetimes->authorizationCode()));
        self::assertSame($this->shapeOf('PT1H'), $this->shapeOf($lifetimes->accessToken()));
        self::assertSame($this->shapeOf('P30D'), $this->shapeOf($lifetimes->refreshToken()));
        self::assertSame($this->shapeOf('P30D'), $this->shapeOf($lifetimes->idle()));
        self::assertSame($this->shapeOf('P90D'), $this->shapeOf($lifetimes->maximum()));
    }

    /**
     * L'échange d'un code rend un jeton d'accès de la durée configurée, une
     * heure : `expires_in` est l'échéance moins l'instant de la réponse, une
     * seconde peut s'être écoulée entre les deux.
     */
    public function testTheAuthorizationServerIssuesWithTheConfiguredLifetimes(): void
    {
        $reference = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $configured = $reference->add($this->lifetimes()->accessToken())->getTimestamp() - $reference->getTimestamp();
        self::assertSame(3600, $configured);

        $response = $this->exchange($this->grantedCode());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertContains($this->json($response)['expires_in'] ?? null, [$configured - 1, $configured]);
    }

    private function lifetimes(): ConnectionLifetimes
    {
        return self::getContainer()->get(ConnectionLifetimes::class);
    }

    /** Les champs d'un intervalle, pour comparer deux intervalles construits autrement. */
    private function shapeOf(DateInterval|string $interval): string
    {
        return (\is_string($interval) ? new DateInterval($interval) : $interval)->format('%y %m %d %h %i %s %f');
    }
}
