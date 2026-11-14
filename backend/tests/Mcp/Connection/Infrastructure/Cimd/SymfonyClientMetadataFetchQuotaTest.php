<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Infrastructure\Cimd\SymfonyClientMetadataFetchQuota;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le quota de récupération des documents CIMD (#651, D-F) : dix par dix
 * minutes, par adresse IP, sur le limiteur `mcp_client_metadata` du
 * conteneur (donc son stockage en base et son verrou partagé, contrôlés par
 * RateLimiterStorageTest).
 *
 * Ce que le test prouve : la clé (l'IP du client de la requête principale) et
 * le plafond. Aucune horloge n'est avancée : la fenêtre de dix minutes est
 * le réglage du limiteur, pas du code testé ici.
 */
final class SymfonyClientMetadataFetchQuotaTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les compteurs vivent en base : un test précédent ne doit pas peser sur celui-ci.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTenFetchesPassAndTheEleventhIsRefused(): void
    {
        $quota = $this->quotaFor('198.51.100.7');

        for ($i = 0; $i < 10; ++$i) {
            $quota->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $quota->consume();
    }

    public function testAnotherAddressHasItsOwnQuota(): void
    {
        $exhausted = $this->quotaFor('198.51.100.7');
        for ($i = 0; $i < 10; ++$i) {
            $exhausted->consume();
        }

        // Ne lève pas : le compteur de l'autre adresse est intact.
        $this->quotaFor('198.51.100.8')->consume();
        self::addToAssertionCount(1);
    }

    public function testTheRefusalCarriesTheMomentAFetchBecomesPossibleAgain(): void
    {
        $quota = $this->quotaFor('198.51.100.9');
        for ($i = 0; $i < 10; ++$i) {
            $quota->consume();
        }

        try {
            $quota->consume();
            self::fail('Le onzième appel aurait dû être refusé.');
        } catch (ClientMetadataFetchRateLimitExceededException $refused) {
            self::assertGreaterThan(new DateTimeImmutable(), $refused->retryAfter);
        }
    }

    private function quotaFor(string $ip): SymfonyClientMetadataFetchQuota
    {
        $factory = self::getContainer()->get('limiter.mcp_client_metadata');

        $requests = new RequestStack();
        $requests->push(Request::create('/oauth/authorize', server: ['REMOTE_ADDR' => $ip]));

        return new SymfonyClientMetadataFetchQuota($factory, $requests);
    }
}
