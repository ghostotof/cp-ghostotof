<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Application\Cimd\CimdClientResolver;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Tests\Mcp\Connection\Application\McpClientRepositoryFake;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Mcp\Connection\Support\CountingFetchQuota;
use App\Tests\Mcp\Connection\Support\InMemoryClientMetadataStore;
use App\Tests\Mcp\Connection\Support\ScriptedMetadataFetcher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * La résolution d'un `client_id` CIMD (#651, D-G) : juger la liste des
 * domaines, lire le magasin, sinon consommer le quota, récupérer, valider, garder, rendre. Les
 * collaborateurs sont des doublures : aucun réseau, aucune base.
 *
 * Ce que ces tests prouvent : l'ordre et les conditions des étapes (un domaine
 * hors liste est refusé avant le cache et le quota ; un cache
 * qui répond ne coûte ni quota ni récupération ; seul un document valide est
 * gardé ; la durée suit `max-age` dans ses bornes). Ils ne prouvent ni le
 * stockage (CachedClientMetadataStoreTest) ni le décompte du quota
 * (SymfonyClientMetadataFetchQuotaTest). Ils prouvent aussi le choix de la voie
 * du quota (client déjà déclaré ou non : un cache manqué seulement, jamais de
 * lecture des enregistrements pour un cache qui répond ou un domaine hors liste).
 */
final class CimdClientResolverTest extends TestCase
{
    public function testACachedDocumentIsServedWithoutQuotaNorFetch(): void
    {
        $clientId = $this->claudeClientId();
        $store = new InMemoryClientMetadataStore();
        $store->seed($clientId, ClaudeCodeMetadata::json());
        $quota = new CountingFetchQuota();
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());

        $document = new CimdClientResolver($store, $quota, $fetcher, $this->claudeDomains(), new McpClientRepositoryFake(null, null))->resolve($clientId);

        self::assertSame('Claude Code', (string) $document->name);
        self::assertSame(0, $quota->consumed(), 'Un cache qui répond ne consomme pas le quota.');
        self::assertSame(0, $fetcher->calls(), 'Un cache qui répond ne déclenche aucune récupération.');
    }

    public function testAMissConsumesTheQuotaFetchesValidatesAndStores(): void
    {
        $clientId = $this->claudeClientId();
        $store = new InMemoryClientMetadataStore();
        $quota = new CountingFetchQuota();
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json(), 600);

        $document = new CimdClientResolver($store, $quota, $fetcher, $this->claudeDomains(), new McpClientRepositoryFake(null, null))->resolve($clientId);

        self::assertSame('Claude Code', (string) $document->name);
        self::assertSame(1, $quota->consumed());
        self::assertSame(1, $fetcher->calls());
        self::assertSame(
            [['clientId' => ClaudeCodeMetadata::CLIENT_ID, 'body' => ClaudeCodeMetadata::json(), 'ttl' => 600]],
            $store->saves(),
        );
        self::assertSame(ClaudeCodeMetadata::json(), $store->find($clientId));
    }

    #[DataProvider('provideAnnouncedMaxAges')]
    public function testTheLifetimeFollowsMaxAgeWithinItsBounds(?int $maxAge, int $expectedTtl): void
    {
        $store = new InMemoryClientMetadataStore();

        new CimdClientResolver(
            $store,
            new CountingFetchQuota(),
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json(), $maxAge),
            $this->claudeDomains(),
            new McpClientRepositoryFake(null, null),
        )->resolve($this->claudeClientId());

        self::assertSame($expectedTtl, $store->saves()[0]['ttl']);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: int}>
     */
    public static function provideAnnouncedMaxAges(): iterable
    {
        yield 'aucune annonce : le plancher' => [null, 300];
        yield 'sous le plancher' => [60, 300];
        yield 'au plancher' => [300, 300];
        yield 'dans les bornes' => [1800, 1800];
        yield 'au plafond' => [3600, 3600];
        yield 'au-delà du plafond' => [86400, 3600];
    }

    public function testAnInvalidDocumentIsRefusedAndNeverStored(): void
    {
        $store = new InMemoryClientMetadataStore();

        $refusal = $this->refusalOf(new CimdClientResolver(
            $store,
            new CountingFetchQuota(),
            // Le document désigne un autre client_id que celui demandé.
            ScriptedMetadataFetcher::serving('{"client_id":"https://claude.ai/autre","client_name":"X","redirect_uris":["http://localhost/callback"]}'),
            $this->claudeDomains(),
            new McpClientRepositoryFake(null, null),
        ));

        self::assertSame(ClientMetadataRefusal::InvalidDocument, $refusal->refusal);
        self::assertInstanceOf(Throwable::class, $refusal->getPrevious(), 'La cause de l\'invalidité reste chaînée pour le diagnostic.');
        self::assertSame([], $store->saves(), 'Un document invalide n\'est jamais gardé (draft §6.2).');
    }

    public function testAFetchRefusalIsNeverStored(): void
    {
        $store = new InMemoryClientMetadataStore();
        $fetcher = ScriptedMetadataFetcher::refusing(ClientMetadataRefusal::Redirected);
        $resolver = new CimdClientResolver($store, new CountingFetchQuota(), $fetcher, $this->claudeDomains(), new McpClientRepositoryFake(null, null));

        $refusal = $this->refusalOf($resolver);

        self::assertSame(ClientMetadataRefusal::Redirected, $refusal->refusal);
        self::assertSame([], $store->saves(), 'Une erreur n\'est jamais gardée (draft §6.2).');

        $this->refusalOf($resolver);
        self::assertSame(2, $fetcher->calls(), 'L\'appel suivant récupère de nouveau : aucun refus n\'est mis en cache.');
    }

    public function testAnExhaustedQuotaRefusesWithoutFetching(): void
    {
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());
        $store = new InMemoryClientMetadataStore();

        $refusal = $this->refusalOf(new CimdClientResolver($store, new CountingFetchQuota(0), $fetcher, $this->claudeDomains(), new McpClientRepositoryFake(null, null)));

        self::assertSame(ClientMetadataRefusal::Throttled, $refusal->refusal);
        self::assertSame(0, $fetcher->calls(), 'Le quota épuisé arrête avant toute sortie réseau.');
        self::assertSame([], $store->saves());
    }

    public function testACachedBodyThatNoLongerValidatesIsFetchedAgain(): void
    {
        $clientId = $this->claudeClientId();
        $store = new InMemoryClientMetadataStore();
        $store->seed($clientId, '{"ce n\'est pas":"un document"}');
        $quota = new CountingFetchQuota();
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());

        $document = new CimdClientResolver($store, $quota, $fetcher, $this->claudeDomains(), new McpClientRepositoryFake(null, null))->resolve($clientId);

        self::assertSame('Claude Code', (string) $document->name);
        self::assertSame(1, $quota->consumed(), 'Un corps du cache qui ne passe plus la validation coûte une unité.');
        self::assertSame(1, $fetcher->calls());
        self::assertSame(ClaudeCodeMetadata::json(), $store->find($clientId), 'Le corps sain remplace le corps périmé.');
    }

    public function testACachedDocumentOfADomainThatLeftTheListIsRefused(): void
    {
        $clientId = $this->claudeClientId();
        $store = new InMemoryClientMetadataStore();
        // Le document a été gardé quand claude.ai était admis ; la liste a changé depuis.
        $store->seed($clientId, ClaudeCodeMetadata::json());
        $quota = new CountingFetchQuota();
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());

        $refusal = $this->refusalOf(new CimdClientResolver($store, $quota, $fetcher, AllowedClientDomains::fromList(['chatgpt.com']), new McpClientRepositoryFake(null, null)));

        self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $refusal->refusal);
        self::assertSame(0, $quota->consumed(), 'Un domaine retiré de la liste ne coûte aucune unité de quota.');
        self::assertSame(0, $fetcher->calls(), 'Un domaine retiré de la liste ne déclenche aucune récupération.');
    }

    public function testADomainOutsideTheListConsumesNoQuota(): void
    {
        $quota = new CountingFetchQuota();
        // Le récupérateur réel refuse lui aussi un hôte hors liste : ce refus local ne doit pas coûter de quota.
        $fetcher = ScriptedMetadataFetcher::refusing(ClientMetadataRefusal::DomainNotAllowed);

        $refusal = $this->refusalOf(new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            $fetcher,
            AllowedClientDomains::fromList(['chatgpt.com']),
            new McpClientRepositoryFake(null, null),
        ));

        self::assertSame(ClientMetadataRefusal::DomainNotAllowed, $refusal->refusal);
        self::assertSame(0, $quota->consumed(), 'Un refus local ne consomme pas le quota de sortie réseau.');
        self::assertSame(0, $fetcher->calls(), 'Le refus tombe avant le récupérateur.');
    }

    /**
     * Un client déjà déclaré (sa ligne `cimd` existe) qui revient : sa voie
     * consomme le quota de l'appelant et le compteur de ce client, jamais le
     * plafond commun. Dix sources qui saturent le plafond ne doivent pas
     * fermer la porte aux clients que des personnes de confiance ont déjà
     * approuvés.
     */
    public function testAKnownClientMissNeverConsumesTheCeiling(): void
    {
        $quota = new CountingFetchQuota();
        $registrations = new McpClientRepositoryFake($this->discoveredClient(), null);

        new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json()),
            $this->claudeDomains(),
            $registrations,
        )->resolve($this->claudeClientId());

        self::assertSame(1, $quota->consumedForKnownClient());
        self::assertSame(0, $quota->consumed(), 'La voie des clients connus ne touche pas le plafond commun.');
    }

    /**
     * Le compteur par client est tenu sous le `client_id` que le résolveur
     * résout : transmettre un autre identifiant ferait payer à un client le
     * compteur d'un autre.
     */
    public function testAKnownClientMissHandsItsClientIdToTheCounter(): void
    {
        $quota = new CountingFetchQuota();

        new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json()),
            $this->claudeDomains(),
            new McpClientRepositoryFake($this->discoveredClient(), null),
        )->resolve($this->claudeClientId());

        self::assertNotNull($quota->lastKnownClientId());
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $quota->lastKnownClientId()->value());
    }

    /**
     * Un client connu dont le compteur est plein n'est pas refusé pour autant : la
     * voie se replie sur le plafond commun, comme en production. Tant que le
     * plafond a de la place, la récupération a lieu, payée par le plafond.
     */
    public function testAKnownClientWhoseCounterIsFullFallsBackOnTheCeiling(): void
    {
        $quota = new CountingFetchQuota(null, 0);
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());

        new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            $fetcher,
            $this->claudeDomains(),
            new McpClientRepositoryFake($this->discoveredClient(), null),
        )->resolve($this->claudeClientId());

        self::assertSame(1, $fetcher->calls(), 'Le plafond a de la place : la récupération a lieu.');
        self::assertSame(1, $quota->consumed(), 'Le repli est payé par le plafond commun.');
        self::assertSame(0, $quota->consumedForKnownClient(), 'Un compteur plein ne consomme rien de plus.');
    }

    /** Compteur du client plein et plafond plein : le même refus `Throttled`, avant toute sortie réseau. */
    public function testAKnownClientRefusedByTheCounterAndTheCeilingIsThrottledWithoutFetching(): void
    {
        $fetcher = ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json());
        $store = new InMemoryClientMetadataStore();

        $refusal = $this->refusalOf(new CimdClientResolver(
            $store,
            new CountingFetchQuota(0, 0),
            $fetcher,
            $this->claudeDomains(),
            new McpClientRepositoryFake($this->discoveredClient(), null),
        ));

        self::assertSame(ClientMetadataRefusal::Throttled, $refusal->refusal);
        self::assertSame(0, $fetcher->calls(), 'Le plafond refuse avant toute sortie réseau.');
        self::assertSame([], $store->saves());
    }

    public function testAnUnknownClientMissConsumesTheCeilingToo(): void
    {
        $quota = new CountingFetchQuota();
        $registrations = new McpClientRepositoryFake(null, null);

        new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json()),
            $this->claudeDomains(),
            $registrations,
        )->resolve($this->claudeClientId());

        self::assertSame(1, $quota->consumed());
        self::assertSame(0, $quota->consumedForKnownClient());
        self::assertSame(1, $registrations->discoveryChecks);
    }

    /** Un client pré-enregistré n'est pas « connu » au sens du quota : seul un client déclaré l'est. */
    public function testAPreregisteredClientIsNotTreatedAsKnown(): void
    {
        $quota = new CountingFetchQuota();
        $preregistered = RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Pré-enregistré', ['https://claude.ai/cb']);

        new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            $quota,
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json()),
            $this->claudeDomains(),
            new McpClientRepositoryFake($preregistered, null),
        )->resolve($this->claudeClientId());

        self::assertSame(1, $quota->consumed());
        self::assertSame(0, $quota->consumedForKnownClient());
    }

    public function testACachedDocumentNeverReadsTheRegistrations(): void
    {
        $clientId = $this->claudeClientId();
        $store = new InMemoryClientMetadataStore();
        $store->seed($clientId, ClaudeCodeMetadata::json());
        $registrations = new McpClientRepositoryFake(null, null);

        new CimdClientResolver(
            $store,
            new CountingFetchQuota(),
            ScriptedMetadataFetcher::serving(ClaudeCodeMetadata::json()),
            $this->claudeDomains(),
            $registrations,
        )->resolve($clientId);

        self::assertSame(0, $registrations->discoveryChecks, 'Un cache qui répond ne coûte aucune lecture de la base.');
    }

    public function testADomainOutsideTheListNeverReadsTheRegistrations(): void
    {
        $registrations = new McpClientRepositoryFake(null, null);

        $this->refusalOf(new CimdClientResolver(
            new InMemoryClientMetadataStore(),
            new CountingFetchQuota(),
            ScriptedMetadataFetcher::refusing(ClientMetadataRefusal::DomainNotAllowed),
            AllowedClientDomains::fromList(['chatgpt.com']),
            $registrations,
        ));

        self::assertSame(0, $registrations->discoveryChecks, 'Un domaine hors liste est refusé avant toute lecture de la base.');
    }

    private function discoveredClient(): McpClient
    {
        return McpClient::discovered(
            ClientMetadataDocument::fromJson($this->claudeClientId(), ClaudeCodeMetadata::json()),
            new DateTimeImmutable('2026-11-13T10:00:00+01:00'),
        );
    }

    private function claudeDomains(): AllowedClientDomains
    {
        return AllowedClientDomains::fromList(['claude.ai']);
    }

    private function claudeClientId(): CimdClientId
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);

        return $clientId;
    }

    private function refusalOf(CimdClientResolver $resolver): ClientMetadataRefusedException
    {
        try {
            $resolver->resolve($this->claudeClientId());
        } catch (ClientMetadataRefusedException $refused) {
            return $refused;
        }

        self::fail('La résolution aurait dû être refusée.');
    }
}
