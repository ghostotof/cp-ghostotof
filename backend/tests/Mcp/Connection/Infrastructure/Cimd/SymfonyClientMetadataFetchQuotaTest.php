<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Infrastructure\Cimd\FetchQuotaKey;
use App\Mcp\Connection\Infrastructure\Cimd\SymfonyClientMetadataFetchQuota;
use App\Tests\Mcp\Connection\Support\GlanceThenRefuseCeiling;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Le quota de récupération des documents CIMD (#651, D-F) : trois limiteurs du
 * conteneur (donc leur stockage en base et leur verrou partagé, contrôlés par
 * RateLimiterStorageTest), `mcp_client_metadata` par appelant (dix par dix
 * minutes), `mcp_client_metadata_global` commun à tous (cent par dix minutes)
 * et `mcp_client_metadata_known_client` par client déjà déclaré (dix par dix
 * minutes, quel que soit le nombre d'appelants).
 *
 * Ce que le test prouve :
 *  - la clé de l'appelant (l'IP du client de la requête principale,
 *    FetchQuotaKey) : une IPv6 comptée par son /64, une IPv4 entière, et
 *    chaque forme qui porte une IPv4 (mappée, NAT64, 6to4, compatible) comptée
 *    comme cette IPv4 ;
 *  - le plafond par appelant, puis le plafond commun ;
 *  - l'ordre des refus : le plafond commun est regardé d'abord, sans être
 *    consommé ; un appelant refusé par son quota ne consomme rien du plafond ;
 *    un appelant refusé par le plafond garde tout son quota ; chaque refus
 *    porte le `retryAfter` du limiteur qui l'a prononcé ;
 *  - la voie des clients connus : un compteur par client, sous l'empreinte
 *    SHA-256 de son `client_id` (jamais en clair dans `cache_items`), regardé
 *    avant le quota de l'appelant et consommé après lui, le plafond commun
 *    jamais touché.
 *
 * Aucune horloge n'est avancée : les fenêtres sont le réglage des limiteurs,
 * pas du code testé ici.
 */
final class SymfonyClientMetadataFetchQuotaTest extends KernelTestCase
{
    /** Deux `client_id` CIMD valides et distincts. */
    private const string CLIENT_A = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const string CLIENT_B = 'https://claude.ai/oauth/another-client-metadata';

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
        // Appelant : une unité par dix minutes, épuisée ; plafond : une heure, large. Les
        // deux fenêtres se distinguent : un retryAfter de l'appelant tient dans les onze
        // minutes, celui du plafond viendrait de la fenêtre d'une heure.
        $quota = $this->quotaWith(
            $this->inMemoryLimiter('caller', 1, '10 minutes'),
            $this->inMemoryLimiter('global', 100, '1 hour'),
            '198.51.100.7',
        );
        $quota->consume();

        $refused = $this->refusalOf($quota);

        self::assertGreaterThan(new DateTimeImmutable(), $refused->retryAfter);
        self::assertLessThanOrEqual(new DateTimeImmutable('+11 minutes'), $refused->retryAfter);
    }

    /**
     * Un appelant que le plafond commun refuse n'a rien consommé de son propre
     * quota : le refus d'un autre ne doit pas lui coûter une récupération.
     */
    public function testACallerRefusedByTheGlobalCeilingKeepsItsWholeQuota(): void
    {
        $global = $this->inMemoryLimiter('global', 1, '1 hour');
        $this->quotaWith($this->inMemoryLimiter('other', 10, '10 minutes'), $global, '198.51.100.8')->consume();

        $perCaller = $this->inMemoryLimiter('caller', 10, '10 minutes');
        $this->refusalOf($this->quotaWith($perCaller, $global, '198.51.100.7'));

        self::assertSame(10, $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume(0)->getRemainingTokens());
    }

    /**
     * Les deux refus possibles en même temps : le plafond est regardé avant
     * l'appelant, c'est donc son `retryAfter` qui est rendu.
     */
    public function testTheGlobalCeilingRefusalWinsWhenTheCallerQuotaIsExhaustedToo(): void
    {
        $global = $this->inMemoryLimiter('global', 1, '1 hour');
        $quota = $this->quotaWith($this->inMemoryLimiter('caller', 1, '10 minutes'), $global, '198.51.100.7');
        $quota->consume();

        $refused = $this->refusalOf($quota);

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter);
    }

    public function testACallerRefusedByItsOwnQuotaLeavesTheGlobalCeilingUntouched(): void
    {
        $global = $this->inMemoryLimiter('global', 100, '1 hour');
        $quota = $this->quotaWith($this->inMemoryLimiter('caller', 1, '10 minutes'), $global, '198.51.100.7');
        $quota->consume();
        $this->refusalOf($quota);

        // Une seule unité du plafond est prise : celle de la récupération acceptée.
        self::assertSame(99, $global->create(SymfonyClientMetadataFetchQuota::GLOBAL_KEY)->consume(0)->getRemainingTokens());
    }

    public function testTheRefusalCarriesTheRetryAfterOfTheGlobalCeilingWhenItRefuses(): void
    {
        // Appelant : dix minutes, large ; plafond : une heure, d'une seule unité.
        $global = $this->inMemoryLimiter('global', 1, '1 hour');
        $this->quotaWith($this->inMemoryLimiter('first', 10, '10 minutes'), $global, '198.51.100.7')->consume();

        $refused = $this->refusalOf($this->quotaWith($this->inMemoryLimiter('second', 10, '10 minutes'), $global, '198.51.100.8'));

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter);
    }

    /**
     * Le plafond commun est saturé (un plafond de deux unités, rempli d'un seul
     * coup), mais un client déjà déclaré passe : sa voie ne regarde ni ne
     * consomme le plafond.
     * Sans elle, dix sources suffiraient à refuser aussi les clients que des
     * personnes de confiance ont déjà approuvés.
     */
    public function testAKnownClientPassesASaturatedCeiling(): void
    {
        $global = $this->inMemoryLimiter('global', 2, '1 hour');
        $global->create(SymfonyClientMetadataFetchQuota::GLOBAL_KEY)->consume(2);
        $perCaller = $this->inMemoryLimiter('caller', 10, '10 minutes');

        $this->quotaWith($perCaller, $global, '198.51.100.7')->consumeForKnownClient($this->clientId(self::CLIENT_A));

        self::assertSame(0, $global->create(SymfonyClientMetadataFetchQuota::GLOBAL_KEY)->consume(0)->getRemainingTokens(), 'Le plafond est resté tel quel.');
        self::assertSame(9, $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume(0)->getRemainingTokens(), 'Une unité est prise au quota de l\'appelant.');
    }

    /** La voie des clients connus reste bornée par le quota de l'appelant, avec son `retryAfter`. */
    public function testAKnownClientIsStillBoundByItsCallerQuota(): void
    {
        $quota = $this->quotaWith(
            $this->inMemoryLimiter('caller', 1, '10 minutes'),
            $this->inMemoryLimiter('global', 100, '1 hour'),
            '198.51.100.7',
        );
        $quota->consumeForKnownClient($this->clientId(self::CLIENT_A));

        try {
            $quota->consumeForKnownClient($this->clientId(self::CLIENT_A));
            self::fail('Le second appel aurait dû être refusé.');
        } catch (ClientMetadataFetchRateLimitExceededException $refused) {
            self::assertGreaterThan(new DateTimeImmutable(), $refused->retryAfter);
            self::assertLessThanOrEqual(new DateTimeImmutable('+11 minutes'), $refused->retryAfter, 'Le retryAfter est celui de l\'appelant, pas du plafond d\'une heure.');
        }
    }

    /** Deux clients déclarés ont chacun leur compteur : épuiser l'un laisse l'autre passer. */
    public function testTwoKnownClientsHaveTheirOwnCounters(): void
    {
        $knownClient = $this->inMemoryLimiter('known-client', 1, '10 minutes');
        $quota = $this->quotaWith(
            $this->inMemoryLimiter('caller', 10, '10 minutes'),
            $this->inMemoryLimiter('global', 100, '1 hour'),
            '198.51.100.7',
            $knownClient,
        );

        $quota->consumeForKnownClient($this->clientId(self::CLIENT_A));
        $quota->consumeForKnownClient($this->clientId(self::CLIENT_B));

        self::assertSame(0, $knownClient->create(hash('sha256', self::CLIENT_A))->consume(0)->getRemainingTokens(), 'Le compteur du premier client est sous l\'empreinte de son client_id.');
        self::assertSame(0, $knownClient->create(hash('sha256', self::CLIENT_B))->consume(0)->getRemainingTokens(), 'Le second client a consommé son propre compteur.');
        $this->expectException(ClientMetadataFetchRateLimitExceededException::class);
        $quota->consumeForKnownClient($this->clientId(self::CLIENT_A));
    }

    /**
     * Dix appelants distincts partagent le compteur d'un même client : le
     * onzième est refusé, avec le `retryAfter` de la fenêtre de dix minutes,
     * quel que soit son propre quota. Les limiteurs du conteneur : c'est leur
     * réglage qui est éprouvé.
     */
    public function testTenCallersShareOneKnownClientCounterAndTheEleventhIsRefused(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->quotaFor('198.51.100.'.$i)->consumeForKnownClient($this->clientId(self::CLIENT_A));
        }

        $refused = $this->knownRefusalOf($this->quotaFor('198.51.100.11'), $this->clientId(self::CLIENT_A));

        self::assertGreaterThan(new DateTimeImmutable(), $refused->retryAfter);
        self::assertLessThanOrEqual(new DateTimeImmutable('+11 minutes'), $refused->retryAfter);
    }

    /** Refusé par le compteur du client (regardé d'abord), l'appelant ne perd rien de son quota. */
    public function testARefusalByTheKnownClientLimiterCostsTheCallerNothing(): void
    {
        $knownClient = $this->inMemoryLimiter('known-client', 1, '10 minutes');
        $knownClient->create(hash('sha256', self::CLIENT_A))->consume();
        $perCaller = $this->inMemoryLimiter('caller', 10, '10 minutes');

        $this->knownRefusalOf(
            $this->quotaWith($perCaller, $this->inMemoryLimiter('global', 100, '1 hour'), '198.51.100.7', $knownClient),
            $this->clientId(self::CLIENT_A),
        );

        self::assertSame(10, $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume(0)->getRemainingTokens());
    }

    /** Refusé par son propre quota, l'appelant ne consomme rien du compteur du client, qu'il ne peut donc pas user en insistant. */
    public function testACallerRefusedByItsQuotaConsumesNothingOfTheKnownClientLimiter(): void
    {
        $perCaller = $this->inMemoryLimiter('caller', 1, '10 minutes');
        $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume();
        $knownClient = $this->inMemoryLimiter('known-client', 10, '10 minutes');

        $this->knownRefusalOf(
            $this->quotaWith($perCaller, $this->inMemoryLimiter('global', 100, '1 hour'), '198.51.100.7', $knownClient),
            $this->clientId(self::CLIENT_A),
        );

        self::assertSame(10, $knownClient->create(hash('sha256', self::CLIENT_A))->consume(0)->getRemainingTokens());
    }

    /** Les deux refuseraient : le compteur du client, regardé d'abord, donne son `retryAfter` (une heure ici, dix minutes pour l'appelant). */
    public function testWhenBothWouldRefuseTheRetryAfterIsTheKnownClientLimiters(): void
    {
        $perCaller = $this->inMemoryLimiter('caller', 1, '10 minutes');
        $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume();
        $knownClient = $this->inMemoryLimiter('known-client', 1, '1 hour');
        $knownClient->create(hash('sha256', self::CLIENT_A))->consume();

        $refused = $this->knownRefusalOf(
            $this->quotaWith($perCaller, $this->inMemoryLimiter('global', 100, '1 hour'), '198.51.100.7', $knownClient),
            $this->clientId(self::CLIENT_A),
        );

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter);
    }

    /**
     * Le `client_id` en clair n'est écrit nulle part dans `cache_items` : ni
     * dans la clé (Symfony la passe par `sha1()`), ni dans la valeur, où
     * l'état sérialisé du limiteur garde l'identifiant qu'il a reçu. Témoin
     * positif : l'empreinte SHA-256, elle, s'y lit, ce qui prouve que la
     * recherche lit bien la valeur.
     */
    public function testNoCacheItemHoldsTheClearClientId(): void
    {
        $this->quotaFor('198.51.100.7')->consumeForKnownClient($this->clientId(self::CLIENT_A));

        self::assertSame(0, $this->cacheItemsHolding(self::CLIENT_A), 'Le client_id en clair ne doit apparaître dans aucune ligne.');
        self::assertSame(1, $this->cacheItemsHolding(hash('sha256', self::CLIENT_A)), 'L\'empreinte du client_id doit se lire dans la valeur du compteur.');
    }

    public function testAnUnknownClientIsRefusedByASaturatedCeiling(): void
    {
        $global = $this->inMemoryLimiter('global', 1, '1 hour');
        $global->create(SymfonyClientMetadataFetchQuota::GLOBAL_KEY)->consume();
        $perCaller = $this->inMemoryLimiter('caller', 10, '10 minutes');

        $refused = $this->refusalOf($this->quotaWith($perCaller, $global, '198.51.100.7'));

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter);
        self::assertSame(10, $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume(0)->getRemainingTokens());
    }

    /**
     * La course acceptée : le plafond répond « il reste un jeton » au coup d'œil,
     * puis refuse la consommation. Le refus rendu est celui du plafond, et
     * l'unité de l'appelant, déjà consommée, est perdue (limite documentée de la
     * classe, que ce test rend visible plutôt que de la taire).
     */
    public function testACeilingFilledBetweenTheGlanceAndTheConsumptionRefusesAndCostsTheCallerOneUnit(): void
    {
        $perCaller = $this->inMemoryLimiter('caller', 10, '10 minutes');

        $refused = $this->refusalOf($this->quotaWith($perCaller, new GlanceThenRefuseCeiling(), '198.51.100.7'));

        self::assertGreaterThan(new DateTimeImmutable('+30 minutes'), $refused->retryAfter, 'Le retryAfter est celui du plafond.');
        self::assertSame(9, $perCaller->create(FetchQuotaKey::forAddress('198.51.100.7'))->consume(0)->getRemainingTokens());
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

    private function knownRefusalOf(SymfonyClientMetadataFetchQuota $quota, CimdClientId $clientId): ClientMetadataFetchRateLimitExceededException
    {
        try {
            $quota->consumeForKnownClient($clientId);
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
            self::getContainer()->get('limiter.mcp_client_metadata_known_client'),
        );
    }

    /** Sans limiteur de client fourni, un limiteur en mémoire au réglage de production (dix par dix minutes). */
    private function quotaWith(
        RateLimiterFactoryInterface $perCaller,
        RateLimiterFactoryInterface $global,
        string $ip,
        ?RateLimiterFactoryInterface $knownClient = null,
    ): SymfonyClientMetadataFetchQuota {
        $requests = new RequestStack();
        $requests->push(Request::create('/oauth/authorize', server: ['REMOTE_ADDR' => $ip]));

        return new SymfonyClientMetadataFetchQuota(
            $perCaller,
            $global,
            $knownClient ?? $this->inMemoryLimiter('known-client', 10, '10 minutes'),
            $requests,
        );
    }

    private function clientId(string $clientId): CimdClientId
    {
        $id = CimdClientId::tryFrom($clientId);
        self::assertNotNull($id);

        return $id;
    }

    /** Le nombre de lignes de `cache_items` dont la clé ou la valeur contient ce texte. */
    private function cacheItemsHolding(string $needle): int
    {
        $count = self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne(
            "SELECT count(*) FROM cache_items WHERE strpos(item_id, :needle) > 0 OR position(convert_to(:needle, 'UTF8') IN item_data) > 0",
            ['needle' => $needle],
        );
        self::assertIsInt($count);

        return $count;
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
