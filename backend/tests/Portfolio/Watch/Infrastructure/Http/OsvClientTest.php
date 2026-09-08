<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Http;

use App\Portfolio\Watch\Domain\ValueObject\KnownVulnerability;
use App\Portfolio\Watch\Domain\Exception\VulnerabilitySourceUnavailableException;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Infrastructure\Http\OsvClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Comme pour endoflife.date, aucun de ces tests ne sort sur le réseau.
 */
final class OsvClientTest extends TestCase
{
    private const string SYMFONY = 'symfony/http-client';

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): OsvClient
    {
        return new OsvClient(new MockHttpClient($responses), new NullLogger());
    }

    /**
     * @return list<PackageCoordinates>
     */
    private function packages(): array
    {
        return [
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_PACKAGIST, self::SYMFONY, '8.1.4'),
            new PackageCoordinates(PackageCoordinates::ECOSYSTEM_NPM, 'vue', '3.5.42'),
        ];
    }

    private function batch(string $json): MockResponse
    {
        return new MockResponse($json);
    }

    private function vulnerability(string $id, string $fixed = '8.1.5'): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => $id,
            'aliases' => ['CVE-2026-0001'],
            'summary' => 'Une faille sérieuse',
            'database_specific' => ['severity' => 'HIGH'],
            'affected' => [[
                'package' => ['name' => self::SYMFONY, 'ecosystem' => 'Packagist'],
                'ranges' => [[
                    'type' => 'ECOSYSTEM',
                    'events' => [['introduced' => '0'], ['fixed' => $fixed]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Le cas nominal, et de loin le plus fréquent : rien à signaler.
     */
    public function testNoVulnerabilityYieldsAnEmptyResult(): void
    {
        $client = $this->client([$this->batch('{"results":[{},{}]}')]);

        self::assertSame([], $client->findVulnerabilities($this->packages()));
    }

    public function testAnEmptyScopeAsksNothingOfTheProvider(): void
    {
        // Aucune réponse programmée : si le client émettait une requête, le
        // MockHttpClient échouerait.
        $client = $this->client([]);

        self::assertSame([], $client->findVulnerabilities([]));
    }

    /**
     * Le piège du fournisseur : `querybatch` ne renvoie que des identifiants et
     * une date de modification. Tout le reste — résumé, sévérité, version
     * corrigée — demande un second appel par vulnérabilité.
     */
    public function testItEnrichesEachIdentifierWithASecondCall(): void
    {
        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-aaaa","modified":"2026-01-01T00:00:00Z"}]},{}]}'),
            $this->vulnerability('GHSA-aaaa'),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertCount(1, $vulnerabilities);
        self::assertSame('GHSA-aaaa', $vulnerabilities[0]->id);
        self::assertSame(['CVE-2026-0001'], $vulnerabilities[0]->aliases);
        self::assertSame('HIGH', $vulnerabilities[0]->severity);
        self::assertSame('8.1.5', $vulnerabilities[0]->fixedIn);
    }

    /**
     * L'ordre des résultats est le seul lien entre une vulnérabilité et le
     * paquet qui la porte : OSV répond dans l'ordre des requêtes envoyées, sans
     * rappeler de quel paquet il s'agit. S'y tromper attribuerait la faille au
     * mauvais paquet.
     */
    public function testItAttributesEachFindingToTheRightPackage(): void
    {
        $client = $this->client([
            $this->batch('{"results":[{},{"vulns":[{"id":"GHSA-bbbb","modified":"2026-01-01T00:00:00Z"}]}]}'),
            $this->vulnerability('GHSA-bbbb'),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertCount(1, $vulnerabilities);
        self::assertSame('vue', $vulnerabilities[0]->package->name);
        self::assertSame(PackageCoordinates::ECOSYSTEM_NPM, $vulnerabilities[0]->package->ecosystem);
    }

    /**
     * Au-delà d'un millier de résultats pour une même requête, OSV pagine. Le
     * cas est improbable ici — il faudrait qu'un paquet cumule mille failles —
     * mais l'ignorer signifierait tronquer sans le dire.
     */
    public function testItFollowsPagination(): void
    {
        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-aaaa"}],"next_page_token":"page-2"},{}]}'),
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-cccc"}]}]}'),
            $this->vulnerability('GHSA-aaaa'),
            $this->vulnerability('GHSA-cccc'),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertCount(2, $vulnerabilities);
        self::assertSame(['GHSA-aaaa', 'GHSA-cccc'], array_map(
            static fn (KnownVulnerability $vulnerability): string => $vulnerability->id,
            $vulnerabilities,
        ));
    }

    /**
     * Un enrichissement en échec ne doit pas faire disparaître la
     * vulnérabilité : mieux vaut « une faille connue, détail indisponible »
     * qu'un décompte silencieusement amputé.
     */
    public function testAFailedEnrichmentKeepsTheFindingWithoutItsDetail(): void
    {
        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-aaaa"}]},{}]}'),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertCount(1, $vulnerabilities);
        self::assertSame('GHSA-aaaa', $vulnerabilities[0]->id);
        self::assertNull($vulnerabilities[0]->severity);
        self::assertNull($vulnerabilities[0]->fixedIn);
    }

    /**
     * Reproduit GHSA-h7vf-5wrv-9fhv tel que la base le publie : une même faille
     * liste les correctifs de deux paquets et de cinq branches. Interrogé pour
     * `symfony/http-kernel` en 8.1.4, le client doit ignorer les entrées de
     * `symfony/symfony` et retenir la première branche atteignable — pas la
     * plus ancienne, ni celle d'un autre paquet.
     */
    public function testItPicksTheReachableFixOfTheRightPackage(): void
    {
        $detail = json_encode([
            'id' => 'GHSA-real',
            'affected' => [
                ['package' => ['name' => 'symfony/symfony'], 'ranges' => [['events' => [['fixed' => '6.2.6']]]]],
                ['package' => ['name' => self::SYMFONY], 'ranges' => [['events' => [['fixed' => '4.4.50']]]]],
                ['package' => ['name' => self::SYMFONY], 'ranges' => [['events' => [['fixed' => '8.1.5']]]]],
                ['package' => ['name' => self::SYMFONY], 'ranges' => [['events' => [['fixed' => '8.2.1']]]]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-real"}]},{}]}'),
            new MockResponse($detail),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertSame('8.1.5', $vulnerabilities[0]->fixedIn);
    }

    /**
     * Aucun correctif au-dessus de la version installée : on rend tout de même
     * la plus basse connue, l'information reste utile.
     */
    public function testItFallsBackToTheLowestKnownFix(): void
    {
        $detail = json_encode([
            'id' => 'GHSA-old',
            'affected' => [
                ['package' => ['name' => self::SYMFONY], 'ranges' => [['events' => [['fixed' => '4.4.50']]]]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-old"}]},{}]}'),
            new MockResponse($detail),
        ]);

        self::assertSame('4.4.50', $client->findVulnerabilities($this->packages())[0]->fixedIn);
    }

    public function testAFailingBatchIsReportedAsUnavailable(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 503])]);

        $this->expectException(VulnerabilitySourceUnavailableException::class);

        $client->findVulnerabilities($this->packages());
    }

    /**
     * Même raison que côté endoflife.date : sans borne, le client suit jusqu'à
     * 20 redirections (défaut Symfony), ce qui offre à un tiers détourné un
     * levier vers le réseau interne depuis le pod du CronJob.
     */
    public function testItFollowsNoRedirection(): void
    {
        $seenOptions = [];

        $client = new OsvClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions = $options;

                return new MockResponse('{"results":[{},{}]}');
            }),
            new NullLogger(),
        );

        $client->findVulnerabilities($this->packages());

        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
    }

    public function testATransportFailureIsReportedAsUnavailable(): void
    {
        $client = new OsvClient(
            new MockHttpClient(static function (): MockResponse {
                throw new TransportException('Connexion impossible');
            }),
            new NullLogger(),
        );

        $this->expectException(VulnerabilitySourceUnavailableException::class);

        $client->findVulnerabilities($this->packages());
    }

    public function testAMalformedBatchIsReportedAsUnavailable(): void
    {
        $client = $this->client([$this->batch('<html>maintenance</html>')]);

        $this->expectException(VulnerabilitySourceUnavailableException::class);

        $client->findVulnerabilities($this->packages());
    }

    /**
     * Un même identifiant peut remonter pour plusieurs paquets d'un même
     * écosystème. Le compter deux fois gonflerait le décompte affiché.
     */
    public function testTheSameFindingIsEnrichedOnlyOnce(): void
    {
        $client = $this->client([
            $this->batch('{"results":[{"vulns":[{"id":"GHSA-aaaa"}]},{"vulns":[{"id":"GHSA-aaaa"}]}]}'),
            $this->vulnerability('GHSA-aaaa'),
        ]);

        $vulnerabilities = $client->findVulnerabilities($this->packages());

        self::assertCount(1, $vulnerabilities);
    }
}
