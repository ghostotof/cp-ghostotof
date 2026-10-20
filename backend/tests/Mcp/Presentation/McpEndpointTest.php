<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation;

use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Stateless\RequestMeta;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

/**
 * Le point d'entrée du serveur MCP (spec 0007, tâche 1 ; ADR 0006) : le
 * handshake aboutit, le serveur n'accepte que l'hôte du site, ses sessions
 * vivent en base, et le canal ignore le cookie du site — ni la garde CSRF ne
 * le refuse, ni le firewall ne le lit (ADR 0006 D4).
 */
final class McpEndpointTest extends WebTestCase
{
    use SpeaksMcp;

    private const string TRUSTED_USERNAME = 'mcp.trusted';

    /** Un `_meta` sous les 16 Kio du corps que nginx admet. */
    private const int META_PADDING_BYTES = 10 * 1024;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM cpg_user');
        self::forgetMcpSessions($connection);
        parent::tearDown();
    }

    public function testInitializeAnswersWithTheServerIdentity(): void
    {
        $client = self::createClient();

        $this->postInitialize($client);

        self::assertResponseIsSuccessful();
        $response = $this->decodeMcpResponse($client);
        self::assertSame('cp-ghostotof', $response['result']['serverInfo']['name']);
        self::assertIsString($response['result']['instructions']);
        self::assertNotSame('', $response['result']['instructions']);
    }

    public function testToolsListAnswersAfterTheHandshake(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/list');

        self::assertIsArray($response['result']['tools']);
    }

    /**
     * Un client ne demande `prompts/list` ou `resources/list` que si le
     * serveur annonce la capacité ; le serveur répond aux deux, par son seul
     * prompt (tâche 3, détaillé dans `PresentForRolePromptTest`) et par une
     * liste vide de ressources, qu'il n'expose pas en phase 1.
     */
    public function testPromptsAndResourcesListsAnswer(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        self::assertSame(['present_for_role'], array_column($this->mcpCall($client, $sessionId, 'prompts/list')['result']['prompts'], 'name'));
        self::assertSame([], $this->mcpCall($client, $sessionId, 'resources/list')['result']['resources']);
    }

    /**
     * Le SDK répond 405 au GET (il n'ouvre pas de flux), ce que l'AI
     * Assistant de PhpStorm prend pour une panne (ADR 0006, conséquences).
     * Le GET ouvre donc un flux SSE vide, qui demande au client d'attendre
     * cinq minutes avant de le rouvrir, et se ferme aussitôt : aucun worker
     * FPM n'est retenu.
     */
    public function testGetOpensAnEmptyEventStreamInsteadOf405(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ACCEPT' => 'text/event-stream']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame("retry: 300000\n\n", $client->getResponse()->getContent());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Le GET répond sans le transport du SDK, mais traverse ses middlewares :
     * la même protection d'hôte que le POST (McpEndpointGetTest pour la liste).
     */
    public function testGetFromAForeignHostIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_HOST' => 'evil.example']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le SDK recopie en session ce que le client envoie : sans borne, un
     * anonyme remplirait la table `cache_items`, donc le disque de PostgreSQL.
     * Une session qui dépasse la borne n'est pas enregistrée, et son
     * identifiant ne sert à rien. Depuis #644, les déclarations de
     * l'`initialize` et les « réponses » du client sont refusées avant le SDK
     * (InboundMessageRefusalListener) ; reste le `_meta` d'une requête, que le
     * SDK range en session (`SESSION_ACTIVE_REQUEST_META`), borné en
     * production par le corps de 16 Kio de nginx — absent en test.
     */
    public function testAnOversizedSessionIsNeitherKeptNorReusable(): void
    {
        $client = self::createClient();

        // Le motif de comptage voit bien une session ordinaire : sans cela,
        // l'égalité finale serait vraie par construction.
        $before = $this->storedMcpSessions();
        $this->initializeMcpSession($client);
        self::assertSame($before + 1, $this->storedMcpSessions());

        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
                '_meta' => ['padding' => str_repeat('x', 600 * 1024)],
            ],
        ]);

        // Aucun identifiant rendu, et la session volumineuse n'atteint pas la
        // base. Le SDK y réécrit une session vide en vidant sa file sortante :
        // une petite ligne orpheline, dont le nombre relève du débit (zone
        // nginx `mcp`), pas de la borne.
        self::assertNull($client->getResponse()->headers->get(self::MCP_SESSION_HEADER));
        self::assertLessThan(BoundedSessionStore::MAX_BYTES, $this->largestStoredMcpSession());
    }

    /**
     * Une session **existante** qui dépasse la borne en cours de route (le
     * client y fait recopier un `_meta` volumineux) est détruite, et le
     * reste : le SDK réécrivait aussitôt sous le même identifiant une session
     * vide en vidant sa file sortante, si bien que la requête suivante
     * tournait sur une session vidée au lieu du 404 qui fait se réinitialiser
     * le client (relecture de la tâche 2).
     */
    public function testASessionThatOutgrowsTheBoundIsGoneForTheNextRequest(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => ['_meta' => ['padding' => str_repeat('x', 600 * 1024)]],
        ]);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        self::assertResponseStatusCodeSame(404);
        self::assertLessThan(BoundedSessionStore::MAX_BYTES, $this->largestStoredMcpSession());
    }

    /**
     * `params._meta` échappe à la borne de 4 096 octets des déclarations de
     * l'`initialize`
     * ({@see \App\Mcp\Infrastructure\Http\InboundMessageRefusalListener}) :
     * accepté, décision de
     * l'auteur du 2026-10-20, rappelée par #672, parce que le SDK ne garde que
     * celui de la **dernière** requête (`SESSION_ACTIVE_REQUEST_META`, réécrit
     * à chaque requête), borné en production par le corps de 16 Kio de nginx.
     * Ce test fige ce remplacement : une montée du SDK qui accumulerait les
     * `_meta` en session rouvrirait le remplissage de `cache_items` que #644 a
     * fermé, et la décision serait à reprendre. Une clé différente à chaque
     * tour : une fusion clé par clé (`array_merge`) accumulerait aussi.
     */
    public function testEachRequestMetaReplacesThePreviousOneInTheSession(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $sizes = [];
        foreach (['a', 'b', 'c'] as $round => $filler) {
            $this->mcpCall($client, $sessionId, 'tools/list', ['_meta' => ['padding_'.$filler => str_repeat($filler, self::META_PADDING_BYTES)]]);
            $sizes[$round] = $this->largestStoredMcpSession();
        }

        // Le `_meta` est bien en session : sans lui, la mesure ne prouverait rien.
        self::assertGreaterThan(self::META_PADDING_BYTES, $sizes[0]);
        // Accumulés, deux `_meta` de plus ajouteraient deux fois le remplissage.
        self::assertLessThan($sizes[0] + intdiv(self::META_PADDING_BYTES, 2), $sizes[2], 'Le SDK accumule les _meta en session.');
    }

    /**
     * La zone nginx `mcpsession` exempte les POST portant
     * `MCP-Protocol-Version: 2026-07-28` (#672). Ce n'est sûr que tant qu'un
     * `initialize` sous cet en-tête n'ouvre aucune session — sans la révision
     * dans `params._meta`, le classifieur du SDK le refuse ; avec, la méthode
     * n'existe pas dans cette révision. Une montée du SDK qui l'accepterait
     * rouvrirait des créations de session hors zone : ce test rougit alors.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function initializeUnderTheModernRevisionProvider(): iterable
    {
        $initialize = [
            'protocolVersion' => self::MCP_PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ];

        yield 'sans la révision dans _meta' => [$initialize, []];
        yield 'avec la révision dans _meta' => [
            $initialize + ['_meta' => self::modernMeta()],
            ['HTTP_MCP_METHOD' => 'initialize'],
        ];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $server
     */
    #[DataProvider('initializeUnderTheModernRevisionProvider')]
    public function testAnInitializeUnderTheModernRevisionOpensNoSession(array $params, array $server): void
    {
        $client = self::createClient();
        $before = $this->storedMcpSessions();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $params], $server + [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION,
        ]);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertNull($client->getResponse()->headers->get(self::MCP_SESSION_HEADER));
        self::assertSame($before, $this->storedMcpSessions());
    }

    /**
     * L'autre moitié de l'exemption : la révision 2026-07-28 est servie sans
     * session, rien n'est écrit en base. Si le SDK se mettait à en écrire une
     * par requête, l'exemption laisserait remplir `cache_items` au rythme de
     * la zone `mcp`.
     */
    public function testAModernRequestIsServedWithoutWritingASession(): void
    {
        $client = self::createClient();
        $before = $this->storedMcpSessions();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => self::modernMeta()]], [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION,
            'HTTP_MCP_METHOD' => 'tools/list',
        ]);

        self::assertResponseIsSuccessful();
        $result = $this->decodeMcpResponse($client)['result'] ?? null;
        self::assertIsArray($result);
        self::assertNotEmpty($result['tools'] ?? null);
        self::assertSame($before, $this->storedMcpSessions());
    }

    /**
     * L'exemption est écrite en dur dans nginx (`"POST:0:<révision>" ""`) :
     * elle doit nommer exactement les révisions sans session du SDK. Une
     * révision moderne ajoutée par une montée du SDK serait sinon comptée
     * comme une création de session, ses clients bridés à 10 appels par
     * minute (relectures de #672). Le garde nginx vérifie que les deux confs
     * ont le même niveau `http` : la conf du sidecar suffit ici.
     */
    public function testTheSessionZoneExemptsExactlyTheSdkModernRevisions(): void
    {
        preg_match_all('/^\s*"POST:0:([^"]+)"\s+"";/m', RepositoryFiles::read(RepositoryFiles::repository('k8s/base/backend-nginx.conf')), $matches);

        $sdkModernRevisions = array_map(static fn (ProtocolVersion $version): string => $version->value, ProtocolVersion::modernVersions());

        self::assertNotSame([], $matches[1], 'Aucune exemption trouvée dans la zone mcpsession.');
        self::assertEqualsCanonicalizing($sdkModernRevisions, $matches[1]);
    }

    /**
     * Protection contre le rebond DNS : seul l'hôte du site (dérivé
     * d'APP_FRONTEND_BASE_URL, `localhost` en test) est servi.
     */
    public function testAForeignHostIsRefused(): void
    {
        $client = self::createClient();

        $this->postInitialize($client, ['HTTP_HOST' => 'evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAForeignOriginIsRefused(): void
    {
        $client = self::createClient();

        $this->postInitialize($client, ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * ADR 0006 D4 : `/api/mcp` est exclu de la garde CSRF, ce qui n'est sûr
     * que parce que son firewall n'accepte aucun cookie. Une requête portant
     * le cookie `BEARER` d'un compte de confiance, sans en-tête XSRF, passe
     * donc la garde et n'authentifie personne.
     */
    public function testATrustedBearerCookieIsNeitherRefusedByTheCsrfGuardNorRead(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $this->loginAs($client);
        self::assertNotNull($client->getCookieJar()->get('BEARER'));

        $this->postInitialize($client);

        self::assertResponseIsSuccessful();
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
    }

    /**
     * ADR 0005 : deux réplicas, un disque en lecture seule. Une session
     * ouverte sur un pod doit être lue par l'autre, donc vivre en base.
     */
    public function testSessionsLiveInTheDatabase(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Le conteneur de test sert le pool tel que compilé (sans traçage) :
        // sa classe est celle que la prod instancie.
        self::assertSame(DoctrineDbalAdapter::class, $container->get('cache.mcp_sessions')::class);
        // Le store du bundle, borné par BoundedSessionStore, écrit dans ce pool.
        // (la lecture échoue si le store n'est pas BoundedSessionStore).
        $store = $container->get('mcp.server.portfolio.session.store');
        self::assertSame(Psr16SessionStore::class, get_debug_type(new ReflectionProperty(BoundedSessionStore::class, 'inner')->getValue($store)));
        // Un `#[AsDecorator]` perd ses tags de canal : le refus d'une session
        // trop grosse doit bien partir sur le canal `mcp`.
        $logger = new ReflectionProperty(BoundedSessionStore::class, 'logger')->getValue($store);
        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('mcp', $logger->getName());
    }

    /**
     * ADR 0006 D7 : pas de `^` sur `symfony/mcp-*` ni `mcp/*` tant qu'ils
     * sont en 0.x — aucune promesse de compatibilité.
     */
    public function testTheMcpPackagesArePinnedExactly(): void
    {
        $composer = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);

        foreach ($composer['require'] as $package => $constraint) {
            if (!\is_string($package) || (!str_starts_with($package, 'symfony/mcp-') && !str_starts_with($package, 'mcp/'))) {
                continue;
            }

            self::assertIsString($constraint);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+\z/', $constraint, \sprintf('%s doit être pinné en version exacte.', $package));
        }

        self::assertArrayHasKey('symfony/mcp-bundle', $composer['require']);
        self::assertArrayHasKey('mcp/sdk', $composer['require']);
    }

    /**
     * `php-http/discovery` n'arrive que par le bundle (son `Psr17Factory`,
     * service `mcp.psr17_factory`) : `src/` dépend des interfaces PSR-17, qui
     * sont à `psr/http-factory` (contrat stable, arrivé par `nyholm/psr7`,
     * que le `require` porte), jamais de cette classe concrète d'un paquet
     * absent du `require` — une montée du bundle qui la retirerait casserait
     * le contrôleur sans qu'aucune contrainte ne le dise (#663, point 3).
     */
    public function testSrcNamesNoClassOfTheDiscoveryPackageItDoesNotRequire(): void
    {
        $composer = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertArrayNotHasKey('php-http/discovery', $composer['require'], 'Requis désormais : ce test n\'a plus de raison d\'être.');

        $offenders = [];
        foreach (PhpSources::files(RepositoryFiles::backend('src')) as $path) {
            foreach (PhpSources::significantTokens(RepositoryFiles::read($path)) as $token) {
                if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && str_starts_with(ltrim($token->text, '\\'), 'Http\\Discovery\\')) {
                    $offenders[] = \sprintf('%s:%d %s', substr($path, \strlen(RepositoryFiles::backend())), $token->line, $token->text);
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * L'enveloppe minimale d'une requête de la révision sans session.
     *
     * @return array<string, mixed>
     */
    private static function modernMeta(): array
    {
        return [
            RequestMeta::PROTOCOL_VERSION => self::MCP_MODERN_REVISION,
            RequestMeta::CLIENT_CAPABILITIES => new stdClass(),
        ];
    }

    private function largestStoredMcpSession(): int
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return $this->integer($connection->fetchOne("SELECT COALESCE(MAX(octet_length(item_data)), 0) FROM cache_items WHERE item_id LIKE '%mcp-portfolio-%'"));
    }

    private function storedMcpSessions(): int
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return $this->integer($connection->fetchOne("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE '%mcp-portfolio-%'"));
    }

    private function integer(mixed $value): int
    {
        self::assertTrue(\is_int($value) || (\is_string($value) && ctype_digit($value)));

        return (int) $value;
    }

    /**
     * @param array<string, string> $server
     */
    private function postInitialize(KernelBrowser $client, array $server = []): void
    {
        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => ['roots' => ['listChanged' => false]],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ],
        ], $server);
    }

    private function loginAs(KernelBrowser $client): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::TRUSTED_USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();
    }
}
