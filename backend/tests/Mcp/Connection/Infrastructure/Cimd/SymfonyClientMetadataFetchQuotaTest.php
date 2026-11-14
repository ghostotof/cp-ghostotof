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
 * Ce que le test prouve : la clé (l'IP du client de la requête principale,
 * une IPv6 comptée par son /64, une IPv4 écrite en IPv6 par l'IPv4 qu'elle
 * porte, FetchQuotaKey) et le plafond. Aucune horloge n'est avancée : la fenêtre de dix minutes est
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

    /**
     * Un attaquant qui tient un /64 change d'adresse source à volonté : s'il
     * obtenait un compteur neuf à chaque adresse, le quota ne bornerait rien.
     */
    public function testTwoIpv6AddressesOfTheSame64ShareTheirQuota(): void
    {
        $first = $this->quotaFor('2001:db8:1:2::1');
        for ($i = 0; $i < 10; ++$i) {
            $first->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        // Même /64, écrite sous sa forme développée et avec un autre identifiant d'interface.
        $this->quotaFor('2001:0db8:0001:0002:ffff:0000:0000:0009')->consume();
    }

    public function testTheQuotaOfAnIpv6IsSharedAcrossItsWholeSixtyFour(): void
    {
        // Dix récupérations venues de dix adresses du même /64, puis une onzième d'une onzième.
        for ($i = 1; $i <= 10; ++$i) {
            $this->quotaFor(\sprintf('2001:db8:1:2:%x::%x', $i, $i))->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $this->quotaFor('2001:db8:1:2:abcd::1')->consume();
    }

    public function testAnIpv6OfAnotherSixtyFourHasItsOwnQuota(): void
    {
        $exhausted = $this->quotaFor('2001:db8:1:2::1');
        for ($i = 0; $i < 10; ++$i) {
            $exhausted->consume();
        }

        // Le /64 voisin (le quatrième groupe diffère) : compteur intact.
        $this->quotaFor('2001:db8:1:3::1')->consume();
        self::addToAssertionCount(1);
    }

    /**
     * Une socket double pile rend l'IPv4 d'un client sous la forme `::ffff:a.b.c.d` :
     * c'est la même source que la socket IPv4, donc le même compteur.
     */
    public function testAnIpv4WrittenAsIpv6SharesTheQuotaOfThatIpv4(): void
    {
        $plain = $this->quotaFor('198.51.100.7');
        for ($i = 0; $i < 10; ++$i) {
            $plain->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $this->quotaFor('::ffff:198.51.100.7')->consume();
    }

    public function testTwoDistinctIpv4AddressesOfTheSameNetworkDoNotShareTheirQuota(): void
    {
        $exhausted = $this->quotaFor('198.51.100.7');
        for ($i = 0; $i < 10; ++$i) {
            $exhausted->consume();
        }

        // Une IPv4 reste comptée entière, pas par son /24.
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
