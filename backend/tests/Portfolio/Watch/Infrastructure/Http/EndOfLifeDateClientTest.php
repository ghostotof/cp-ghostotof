<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Http;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ExternalUrlFilter;
use App\Portfolio\Watch\Infrastructure\Http\EndOfLifeDateClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Aucun de ces tests ne sort sur le réseau : un test qui appellerait réellement
 * endoflife.date serait intermittent par construction et ferait échouer la CI le
 * jour où le fournisseur a un incident — précisément le couplage que cette
 * fonctionnalité prétend démontrer qu'on sait éviter.
 */
final class EndOfLifeDateClientTest extends TestCase
{
    /**
     * Extrait fidèle de la réponse réelle observée le 2026-09-07 sur
     * GET https://endoflife.date/api/v1/products/php/ (tronquée à deux cycles).
     */
    private const string PHP_PAYLOAD = <<<'JSON'
        {
          "schema_version": "1.2.1",
          "generated_at": "2026-09-07T08:04:15+00:00",
          "result": {
            "name": "php",
            "label": "PHP",
            "links": { "html": "https://endoflife.date/php" },
            "releases": [
              {
                "name": "8.5",
                "releaseDate": "2025-11-20",
                "isLts": false,
                "isMaintained": true,
                "isEoas": false,
                "eoasFrom": "2027-12-31",
                "isEol": false,
                "eolFrom": "2029-12-31",
                "latest": { "name": "8.5.10", "date": "2026-08-27" }
              },
              {
                "name": "8.1",
                "releaseDate": "2021-11-25",
                "isLts": false,
                "isMaintained": false,
                "isEoas": true,
                "eoasFrom": "2023-11-25",
                "isEol": true,
                "eolFrom": "2025-12-31",
                "latest": { "name": "8.1.33", "date": "2025-12-18" }
              }
            ]
          }
        }
        JSON;

    /**
     * @param callable(string, string, array<string, mixed>): ResponseInterface|ResponseInterface $response
     */
    private function client(callable|ResponseInterface $response): EndOfLifeDateClient
    {
        return new EndOfLifeDateClient(new MockHttpClient($response), new NullLogger(), new ExternalUrlFilter());
    }

    public function testItMapsTheProductAndEveryReleaseCycle(): void
    {
        $product = $this->client(new MockResponse(self::PHP_PAYLOAD))->fetchProduct('php');

        self::assertSame('php', $product->slug);
        self::assertSame('PHP', $product->label);
        self::assertSame('https://endoflife.date/php', $product->documentationUrl);
        self::assertCount(2, $product->cycles);

        $current = $product->cycles[0];
        self::assertSame('8.5', $current->name);
        self::assertFalse($current->isEol);
        self::assertSame('2029-12-31', $current->eolFrom?->format('Y-m-d'));
        self::assertFalse($current->isEndOfActiveSupport);
        self::assertSame('2027-12-31', $current->endOfActiveSupportFrom?->format('Y-m-d'));
        self::assertTrue($current->isMaintained);
        self::assertSame('8.5.10', $current->latestVersion);

        $old = $product->cycles[1];
        self::assertTrue($old->isEol);
        self::assertTrue($old->isEndOfActiveSupport);
    }

    public function testItCallsTheDocumentedUrlWithAnIdentifyingUserAgent(): void
    {
        $seenUrl = null;
        $seenHeaders = [];

        $client = $this->client(function (string $method, string $url, array $options) use (&$seenUrl, &$seenHeaders): MockResponse {
            $seenUrl = $url;
            /** @var list<string> $headers */
            $headers = $options['headers'] ?? [];
            $seenHeaders = $headers;

            self::assertSame('GET', $method);

            return new MockResponse(self::PHP_PAYLOAD);
        });

        $client->fetchProduct('php');

        self::assertSame('https://endoflife.date/api/v1/products/php/', $seenUrl);
        // Le fournisseur exige un User-Agent identifiable : on lui donne de quoi
        // nous joindre plutôt qu'une chaîne anonyme.
        self::assertNotEmpty(array_filter(
            $seenHeaders,
            static fn (string $header): bool => str_starts_with(strtolower($header), 'user-agent:')
                && str_contains($header, 'cp-ghostotof'),
        ));
    }

    /**
     * Sans borne explicite, un tiers qui répond lentement immobilise un worker
     * PHP-FPM. `timeout` borne l'inactivité, `max_duration` borne l'appel
     * entier : les deux sont nécessaires, l'un ne remplace pas l'autre.
     */
    public function testItBoundsBothTheInactivityAndTheTotalDuration(): void
    {
        $seenOptions = [];

        $client = $this->client(function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse(self::PHP_PAYLOAD);
        });

        $client->fetchProduct('php');

        self::assertArrayHasKey('timeout', $seenOptions);
        self::assertArrayHasKey('max_duration', $seenOptions);
        self::assertGreaterThan(0, $seenOptions['timeout']);
        self::assertGreaterThan(0, $seenOptions['max_duration']);
    }

    /**
     * Revue de sécurité du 2026-09-08. Le catalogue d'endoflife.date est un jeu
     * de données ouvert : `links.html` n'est donc pas une valeur de confiance.
     * Sans filtrage ici, la chaîne traversait la couche anti-corruption, la
     * base et l'API publique intacte, jusqu'à un `:href` — que Vue ne filtre
     * pas. Le lien tombe, le produit reste.
     */
    public function testAHostileDocumentationLinkIsDroppedRatherThanMapped(): void
    {
        $payload = str_replace(
            '"html": "https://endoflife.date/php"',
            '"html": "javascript:alert(document.domain)"',
            self::PHP_PAYLOAD,
        );

        $product = $this->client(new MockResponse($payload))->fetchProduct('php');

        self::assertNull($product->documentationUrl);
        self::assertSame('php', $product->slug);
        self::assertCount(2, $product->cycles);
    }

    /**
     * Sans borne, le client suit jusqu'à 20 redirections (défaut Symfony). Un
     * fournisseur hostile ou détourné pourrait donc faire pointer l'appel vers
     * une adresse interne — le pod du CronJob n'a aucune restriction de sortie.
     * L'URL appelée est déjà canonique : ne suivre aucune redirection ne coûte
     * rien et retire le levier.
     */
    public function testItFollowsNoRedirection(): void
    {
        $seenOptions = [];

        $client = $this->client(function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse(self::PHP_PAYLOAD);
        });

        $client->fetchProduct('php');

        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
    }

    /**
     * Un slug mal saisi au backoffice ne doit pas se confondre avec une panne :
     * l'appelant dégrade cette entrée en « inconnu » et affiche les autres.
     */
    public function testAnUnknownProductIsDistinguishedFromAFailure(): void
    {
        $client = $this->client(new MockResponse('{"message":"not found"}', ['http_code' => 404]));

        $this->expectException(ReleaseCycleProductNotFoundException::class);

        $client->fetchProduct('phpp');
    }

    public function testAServerErrorIsReportedAsUnavailable(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 503]));

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->fetchProduct('php');
    }

    public function testATransportFailureIsReportedAsUnavailable(): void
    {
        $client = $this->client(static function (): MockResponse {
            throw new TransportException('Idle timeout reached');
        });

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->fetchProduct('php');
    }

    public function testMalformedJsonIsReportedAsUnavailable(): void
    {
        $client = $this->client(new MockResponse('<html>maintenance</html>'));

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->fetchProduct('php');
    }

    public function testAResponseWithoutReleasesIsReportedAsUnavailable(): void
    {
        $client = $this->client(new MockResponse('{"schema_version":"1.2.1","result":{"name":"php"}}'));

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->fetchProduct('php');
    }

    /**
     * Choix de conception : on ne rejette pas sur le numéro de schéma, mais sur
     * la forme réellement reçue. Se braquer sur « 1.2.1 » casserait la page au
     * premier incrément de version du fournisseur, alors même que la structure
     * exploitée n'aurait pas bougé — une fragilité gratuite.
     */
    public function testAnUnknownSchemaVersionIsAcceptedWhenTheShapeIsUsable(): void
    {
        $payload = str_replace('"schema_version": "1.2.1"', '"schema_version": "9.9.9"', self::PHP_PAYLOAD);

        $product = $this->client(new MockResponse($payload))->fetchProduct('php');

        self::assertCount(2, $product->cycles);
    }

    public function testItConfirmsAKnownProductExists(): void
    {
        self::assertTrue($this->client(new MockResponse(self::PHP_PAYLOAD))->supportsProduct('php'));
    }

    public function testItReportsAnUnknownProductAsAbsent(): void
    {
        $client = $this->client(new MockResponse('{"message":"not found"}', ['http_code' => 404]));

        self::assertFalse($client->supportsProduct('phpp'));
    }

    /**
     * « Ce produit n'existe pas » et « je n'ai pas pu vérifier » sont deux
     * réponses différentes. Retourner `false` dans le second cas ferait rejeter
     * une saisie correcte à la première indisponibilité du fournisseur.
     */
    public function testAnUndecidableVerificationRaisesRatherThanReturningFalse(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 503]));

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->supportsProduct('php');
    }

    public function testATransportFailureDuringVerificationRaises(): void
    {
        $client = $this->client(static function (): MockResponse {
            throw new TransportException('Idle timeout reached');
        });

        $this->expectException(ReleaseCycleSourceUnavailableException::class);

        $client->supportsProduct('php');
    }

    /**
     * La vérification s'exécute pendant qu'un humain attend devant un
     * formulaire : sa borne doit être plus serrée que celle du rafraîchissement,
     * qui tourne dans un travail planifié.
     */
    public function testVerificationIsBoundedMoreTightlyThanRefreshing(): void
    {
        $verificationOptions = [];
        $refreshOptions = [];

        $client = $this->client(function (string $method, string $url, array $options) use (&$verificationOptions, &$refreshOptions): MockResponse {
            if ([] === $verificationOptions) {
                $verificationOptions = $options;
            } else {
                $refreshOptions = $options;
            }

            return new MockResponse(self::PHP_PAYLOAD);
        });

        $client->supportsProduct('php');
        $client->fetchProduct('php');

        self::assertLessThan($refreshOptions['max_duration'], $verificationOptions['max_duration']);
    }

    /**
     * Une entrée illisible au milieu d'une réponse par ailleurs valide est
     * ignorée, pas fatale : mieux vaut afficher quatre cycles sur cinq qu'une
     * page en erreur.
     */
    public function testAnUnusableCycleIsSkippedRatherThanFatal(): void
    {
        $payload = str_replace('"name": "8.1",', '"name": null,', self::PHP_PAYLOAD);

        $product = $this->client(new MockResponse($payload))->fetchProduct('php');

        self::assertCount(1, $product->cycles);
        self::assertSame('8.5', $product->cycles[0]->name);
    }
}
