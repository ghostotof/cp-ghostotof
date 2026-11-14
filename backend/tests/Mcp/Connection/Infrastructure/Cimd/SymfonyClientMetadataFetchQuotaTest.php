<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Infrastructure\Cimd\SymfonyClientMetadataFetchQuota;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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

    /**
     * Un serveur IPv6 derrière un traducteur NAT64 voit ses clients IPv4 sous
     * `64:ff9b::/96` : leur compteur est celui de leur IPv4, ni un compteur
     * commun à tous les clients, ni 65 536 compteurs par IPv4 en 6to4.
     */
    public function testAnIpv4CarriedByNat64Or6to4SharesTheQuotaOfThatIpv4(): void
    {
        $plain = $this->quotaFor('198.51.100.7');
        for ($i = 0; $i < 10; ++$i) {
            $plain->consume();
        }

        $refused = [];
        foreach (['64:ff9b::198.51.100.7', '2002:c633:6407::1', '2002:c633:6407:ffff::2', '64:ff9b::198.51.100.8'] as $carried) {
            try {
                $this->quotaFor($carried)->consume();
            } catch (ClientMetadataFetchRateLimitExceededException) {
                $refused[] = $carried;
            }
        }

        // Les trois adresses qui portent 198.51.100.7 partagent son quota
        // épuisé ; une autre IPv4 derrière le même traducteur a son compteur.
        self::assertSame(['64:ff9b::198.51.100.7', '2002:c633:6407::1', '2002:c633:6407:ffff::2'], $refused);
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

    /**
     * Le plafond commun : cent récupérations par dix minutes, tous appelants
     * confondus. Un attaquant qui tient un /48 a 65 536 /64, donc autant de
     * quotas par appelant : seul un compteur commun le borne.
     */
    public function testTheHundredAndFirstFetchOfDistinctCallersIsRefused(): void
    {
        for ($i = 1; $i <= 100; ++$i) {
            $this->quotaFor('192.0.2.'.$i)->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $this->quotaFor('192.0.2.101')->consume();
    }

    /**
     * L'ordre est celui de l'appelant d'abord : un appelant déjà refusé par son
     * propre quota ne doit pas user le plafond que les autres se partagent.
     */
    public function testACallerRefusedByItsOwnQuotaDoesNotLowerTheGlobalCeiling(): void
    {
        $noisy = $this->quotaFor('198.51.100.7');
        for ($i = 0; $i < 10; ++$i) {
            $noisy->consume();
        }

        // Dix refus de cet appelant : aucun ne doit compter au plafond commun.
        for ($i = 0; $i < 10; ++$i) {
            try {
                $noisy->consume();
                self::fail('Le onzième appel d\'un même appelant aurait dû être refusé.');
            } catch (ClientMetadataFetchRateLimitExceededException) {
                // Attendu : c'est son propre quota qui refuse.
            }
        }

        // Dix unités du plafond sont prises par `$noisy` : il en reste quatre-vingt-dix.
        for ($i = 1; $i <= 90; ++$i) {
            $this->quotaFor('192.0.2.'.$i)->consume();
        }

        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $this->quotaFor('192.0.2.200')->consume();
    }

    public function testTheRefusalCarriesTheRetryAfterOfTheCallerQuotaWhenItRefuses(): void
    {
        // Appelant : dix minutes ; plafond : une heure et largement entamé par personne.
        $quota = $this->quotaWith(
            $this->inMemoryLimiter('caller', 1, '10 minutes'),
            $this->inMemoryLimiter('global', 100, '1 hour'),
            '198.51.100.7',
        );
        $quota->consume();

        $refused = $this->refusalOf($quota);

        self::assertLessThanOrEqual(new DateTimeImmutable('+11 minutes'), $refused->retryAfter);
    }

    public function testTheRefusalCarriesTheRetryAfterOfTheGlobalCeilingWhenItRefuses(): void
    {
        // Appelant : dix minutes, large ; plafond : une heure, d'une seule unité.
        $global = $this->inMemoryLimiter('global', 1, '1 hour');
        $this->quotaWith($this->inMemoryLimiter('first', 10, '10 minutes'), $global, '198.51.100.7')->consume();

        $refused = $this->refusalOf($this->quotaWith($this->inMemoryLimiter('second', 10, '10 minutes'), $global, '198.51.100.8'));

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter);
    }

    private function refusalOf(SymfonyClientMetadataFetchQuota $quota): ClientMetadataFetchRateLimitExceededException
    {
        try {
            $quota->consume();
        } catch (ClientMetadataFetchRateLimitExceededException $refused) {
            return $refused;
        }

        self::fail('L\'appel aurait dû être refusé.');
    }

    private function quotaFor(string $ip): SymfonyClientMetadataFetchQuota
    {
        return $this->quotaWith(
            self::getContainer()->get('limiter.mcp_client_metadata'),
            self::getContainer()->get('limiter.mcp_client_metadata_global'),
            $ip,
        );
    }

    private function quotaWith(RateLimiterFactory $perCaller, RateLimiterFactory $global, string $ip): SymfonyClientMetadataFetchQuota
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/oauth/authorize', server: ['REMOTE_ADDR' => $ip]));

        return new SymfonyClientMetadataFetchQuota($perCaller, $global, $requests);
    }

    /** Un limiteur en mémoire, à la fenêtre et au plafond que le test veut. */
    private function inMemoryLimiter(string $id, int $limit, string $interval): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage(),
        );
    }
}
