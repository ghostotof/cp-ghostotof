<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Application\Cimd\CimdClientResolver;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\Support\CountingFetchQuota;
use App\Tests\Mcp\Connection\Support\InMemoryClientMetadataStore;
use App\Tests\Mcp\Connection\Support\ScriptedMetadataFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * La résolution d'un `client_id` CIMD (#651, D-G) : lire le magasin, sinon
 * consommer le quota, récupérer, valider, garder, rendre. Les trois
 * collaborateurs sont des doublures : aucun réseau, aucune base.
 *
 * Ce que ces tests prouvent : l'ordre et les conditions des étapes (un cache
 * qui répond ne coûte ni quota ni récupération ; seul un document valide est
 * gardé ; la durée suit `max-age` dans ses bornes). Ils ne prouvent ni le
 * stockage (CachedClientMetadataStoreTest) ni le décompte du quota
 * (SymfonyClientMetadataFetchQuotaTest).
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

        $document = new CimdClientResolver($store, $quota, $fetcher)->resolve($clientId);

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

        $document = new CimdClientResolver($store, $quota, $fetcher)->resolve($clientId);

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
        ));

        self::assertSame(ClientMetadataRefusal::InvalidDocument, $refusal->refusal);
        self::assertInstanceOf(Throwable::class, $refusal->getPrevious(), 'La cause de l\'invalidité reste chaînée pour le diagnostic.');
        self::assertSame([], $store->saves(), 'Un document invalide n\'est jamais gardé (draft §6.2).');
    }

    public function testAFetchRefusalIsNeverStored(): void
    {
        $store = new InMemoryClientMetadataStore();
        $fetcher = ScriptedMetadataFetcher::refusing(ClientMetadataRefusal::Redirected);
        $resolver = new CimdClientResolver($store, new CountingFetchQuota(), $fetcher);

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

        $refusal = $this->refusalOf(new CimdClientResolver($store, new CountingFetchQuota(0), $fetcher));

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

        $document = new CimdClientResolver($store, $quota, $fetcher)->resolve($clientId);

        self::assertSame('Claude Code', (string) $document->name);
        self::assertSame(1, $quota->consumed(), 'Un corps du cache qui ne passe plus la validation coûte une unité.');
        self::assertSame(1, $fetcher->calls());
        self::assertSame(ClaudeCodeMetadata::json(), $store->find($clientId), 'Le corps sain remplace le corps périmé.');
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
