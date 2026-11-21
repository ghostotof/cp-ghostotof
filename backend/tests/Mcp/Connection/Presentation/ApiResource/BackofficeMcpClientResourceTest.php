<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Put;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProcessor;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProvider;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientResource;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\RepositoryFiles;
use App\Tests\Support\TestCredentials;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre la lecture, l'enregistrement, la révision et la suppression des
 * clients MCP par le backoffice (#652, spec 0007 T13) : GET
 * /api/backoffice/mcp/clients, GET, PUT et DELETE
 * /api/backoffice/mcp/clients/{id} et POST /api/backoffice/mcp/clients,
 * réservés ROLE_SUPER par l'`access_control` `^/api/backoffice`, et la garde
 * des opérations de la ressource. La suppression vue par le client MCP (son
 * jeton cesse d'ouvrir) est dans {@see BackofficeMcpClientRemovalTest}.
 */
final class BackofficeMcpClientResourceTest extends WebTestCase
{
    use HttpJson;
    use ReadsSecurityAuditLog;

    private const string SUPER_USERNAME = 'super';
    private const string TRUSTED_USERNAME = 'trusted';
    private const string SCOPE = 'nominative:read';

    /** UUID syntaxiquement valide mais absent de la base : 404 applicatif. */
    private const string UNKNOWN_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    private const string PREREGISTERED_CLIENT_ID = 'https://client.example.test/metadata.json';

    /** Le client que la garde des refus fait écrire, révisé ou supprimé : il doit sortir intact. */
    private const string SEEDED_CLIENT_ID = 'https://seeded.example.test/metadata.json';

    /** Le jeton, dans le chemin d'une opération d'élément, que le test remplace par l'identifiant du client semé. */
    private const string ID_PLACEHOLDER = '{id}';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSuperListsBothRegistrationsWithTheirActiveConnectionCount(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);

        $preregistered = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Client pré-enregistré', ['https://client.example.test/callback']);
        $discovered = ClaudeCodeMetadata::storedRow();
        $clients = $this->clients();
        $clients->add($preregistered);
        $clients->add($discovered);

        // Deux connexions actives et une révoquée sur le client pré-enregistré :
        // seules les actives comptent. Le client CIMD n'en a aucune : 0, pas une clé absente.
        $jane = $this->registerAccount($client, 'jane');
        $john = $this->registerAccount($client, 'john');
        $revoked = $this->registerAccount($client, 'revoked');
        $connections = $this->connections();
        $revokedConnection = McpConnection::grant($revoked, $preregistered, self::SCOPE, new DateTimeImmutable());
        foreach ([
            McpConnection::grant($jane, $preregistered, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($john, $preregistered, self::SCOPE, new DateTimeImmutable()),
            $revokedConnection,
        ] as $connection) {
            $connections->add($connection);
        }
        $connections->revokeActive($revokedConnection->getId(), RevocationCause::Client, new DateTimeImmutable());

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', '/api/backoffice/mcp/clients');

        self::assertResponseIsSuccessful();
        $rows = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertCount(2, $rows);

        $byClientId = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['clientId']);
            $byClientId[$row['clientId']] = $row;
        }

        $pre = $byClientId[self::PREREGISTERED_CLIENT_ID];
        self::assertSame($preregistered->getId()->toRfc4122(), $pre['id']);
        self::assertSame('Client pré-enregistré', $pre['name']);
        self::assertSame(['https://client.example.test/callback'], $pre['redirectUris']);
        self::assertSame('preregistered', $pre['registration']);
        self::assertSame($preregistered->registeredAt()->format(\DATE_ATOM), $pre['registeredAt']);
        self::assertSame(2, $pre['activeConnections']);

        $cimd = $byClientId[ClaudeCodeMetadata::CLIENT_ID];
        self::assertSame('cimd', $cimd['registration']);
        self::assertSame(0, $cimd['activeConnections']);
    }

    public function testGetReturnsOneClientAndUnknownIdIs404(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $registered = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Client pré-enregistré', ['https://client.example.test/callback']);
        $this->clients()->add($registered);
        // Un second client, pour prouver que la lecture désigne bien le bon.
        $this->clients()->add(ClaudeCodeMetadata::storedRow());
        $this->registerAccount($client, 'jane');
        $this->connections()->add(McpConnection::grant($this->findAccount('jane'), $registered, self::SCOPE, new DateTimeImmutable()));

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', '/api/backoffice/mcp/clients/'.$registered->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $row = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertSame($registered->getId()->toRfc4122(), $row['id']);
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $row['clientId']);
        self::assertSame(1, $row['activeConnections']);

        $client->request('GET', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);

        // Un identifiant mal formé est refusé par le routeur (Requirement::UUID), pas par le Provider.
        $client->request('GET', '/api/backoffice/mcp/clients/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuperRegistersAPreregisteredClient(): void
    {
        $client = self::createClient();
        $super = $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['https://client.example.test/callback', 'http://localhost/callback'],
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $stored = $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID);
        self::assertNotNull($stored);
        self::assertSame($stored->getId()->toRfc4122(), $body['id']);
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $body['clientId']);
        self::assertSame('Client pré-enregistré', $body['name']);
        self::assertSame(['https://client.example.test/callback', 'http://localhost/callback'], $body['redirectUris']);
        self::assertSame('preregistered', $body['registration']);
        self::assertSame($stored->registeredAt()->format(\DATE_ATOM), $body['registeredAt']);
        self::assertSame(0, $body['activeConnections']);

        // L'audit est celui d'une requête : l'acteur est celui du jeton, jamais `console`.
        $event = self::singleSecurityAuditEvent('mcp-client-registered');
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $event['clientId']);
        self::assertSame('preregistered', $event['registration']);
        self::assertSame($super->getId()->toRfc4122(), $event['actor']);
    }

    public function testAnInvalidRedirectAnswers422AtItsPath(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['not a redirect uri'],
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['violations']);
        self::assertIsArray($body['violations'][0]);
        self::assertSame('redirectUris[0]', $body['violations'][0]['propertyPath']);
        // Un refus n'écrit rien et n'audite rien.
        self::assertNull($this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID));
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    public function testTheMetadataUrlOfAnAllowedDomainAnswers422AtClientIdAndWritesNothing(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        // Pré-enregistrer cette URL masquerait le vrai client CIMD (règle D-C) : refusé (#652).
        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => ClaudeCodeMetadata::CLIENT_ID,
            'name' => 'Faux Claude Code',
            'redirectUris' => ['https://attacker.example.test/callback'],
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['violations']);
        self::assertIsArray($body['violations'][0]);
        self::assertSame('clientId', $body['violations'][0]['propertyPath']);
        self::assertNull($this->clients()->findOneByClientId(ClaudeCodeMetadata::CLIENT_ID));
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    public function testATakenClientIdAnswers409(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $this->clients()->add(RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Premier', ['https://client.example.test/callback']));
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Second',
            'redirectUris' => ['https://other.example.test/callback'],
        ]));

        self::assertResponseStatusCodeSame(409);
        // Le premier client est intact, et le refus n'est pas audité comme un enregistrement.
        self::assertSame('Premier', $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID)?->getName());
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    /**
     * Même règle que pour le `PUT` : un attribut que l'entrée ne porte pas est un
     * corps mal formé, pas un champ « ignoré avec un 201 ». `registration` (que
     * l'API calcule, jamais l'appelant : un client `cimd` ne se pré-enregistre
     * pas) et la faute de frappe sur un nom de champ créaient un client en
     * silence. `allow_extra_attributes: false` sur l'opération les refuse
     * (`MalformedRequestBodyException`, 400, message fixe). Le refus précède toute
     * écriture : aucune ligne `mcp_client`, aucun audit, et ni le nom ni la valeur
     * de l'attribut ne sont recopiés dans la réponse.
     */
    #[DataProvider('registrationBodiesWithAnAttributeTheInputDoesNotTake')]
    public function testARegistrationBodyWithAnUnknownAttributeIsRefusedAsMalformed(string $attribute, string $value): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['https://client.example.test/callback'],
            $attribute => $value,
        ]));

        self::assertResponseStatusCodeSame(400);
        $content = (string) $client->getResponse()->getContent();
        $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('Le corps de la requête n\'est pas un document JSON exploitable.', $body['detail'] ?? null, 'Le message est fixe.');
        self::assertStringNotContainsString($attribute, $content, 'Le nom de l\'attribut n\'est jamais recopié dans la réponse.');
        self::assertStringNotContainsString($value, $content, 'La valeur saisie n\'est jamais recopiée dans la réponse.');
        self::assertSame(0, $this->countClientRows(), 'Un corps refusé n\'écrit aucune ligne mcp_client.');
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function registrationBodiesWithAnAttributeTheInputDoesNotTake(): iterable
    {
        yield 'registration (calculé par l\'API)' => ['registration', 'cimd'];
        yield 'faute de frappe sur un nom de champ' => ['redirectUri', 'https://typo.example.test/callback'];
        yield 'attribut inconnu' => ['colour', 'turquoise-value'];
    }

    public function testSuperRevisesNameAndRedirects(): void
    {
        $client = self::createClient();
        $super = $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $stored = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Ancien nom', ['https://client.example.test/callback']);
        $this->clients()->add($stored);
        $holder = $this->registerAccount($client, 'holder');
        $this->connections()->add(McpConnection::grant($holder, $stored, self::SCOPE, new DateTimeImmutable()));
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', '/api/backoffice/mcp/clients/'.$stored->getId()->toRfc4122(), server: $this->jsonWrite($csrf), content: self::jsonBody([
            'name' => 'Nouveau nom',
            'redirectUris' => ['https://client.example.test/callback', 'http://localhost/callback'],
        ]));

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($stored->getId()->toRfc4122(), $body['id']);
        // L'identifiant public du client n'est jamais révisé.
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $body['clientId']);
        self::assertSame('Nouveau nom', $body['name']);
        self::assertSame(['https://client.example.test/callback', 'http://localhost/callback'], $body['redirectUris']);
        self::assertSame('preregistered', $body['registration']);
        // Réviser ne coupe aucune connexion (D-E) : la réponse compte toujours la connexion active.
        self::assertSame(1, $body['activeConnections']);

        $revised = $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID);
        self::assertNotNull($revised);
        self::assertSame('Nouveau nom', $revised->getName());
        self::assertSame(['https://client.example.test/callback', 'http://localhost/callback'], $revised->redirectUris());
        self::assertSame(1, $this->connections()->countActiveOfClient($stored->getId()));

        // L'audit est celui d'une requête : l'acteur est celui du jeton, des nombres et jamais une URI.
        $event = self::singleSecurityAuditEvent('mcp-client-revised');
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $event['clientId']);
        self::assertSame(2, $event['redirectUriCount']);
        self::assertSame(1, $event['addedRedirectCount']);
        self::assertSame(0, $event['removedRedirectCount']);
        self::assertSame($super->getId()->toRfc4122(), $event['actor']);
        self::assertSame([], self::securityAuditEvents('mcp-connection-revoked'));
    }

    /**
     * Un client déclaré par son document CIMD n'est pas révisable : le document
     * fait foi (D-E). 409 typé, la ligne est intacte et rien n'est audité.
     */
    public function testRevisingACimdClientIsRefusedWithATypedConflict(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $discovered = ClaudeCodeMetadata::storedRow();
        $this->clients()->add($discovered);
        $nameBefore = $discovered->getName();
        $redirectsBefore = $discovered->redirectUris();
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', '/api/backoffice/mcp/clients/'.$discovered->getId()->toRfc4122(), server: $this->jsonWrite($csrf), content: self::jsonBody([
            'name' => 'Nom imposé',
            'redirectUris' => ['https://attacker.example.test/callback'],
        ]));

        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['type']);
        self::assertStringEndsWith('/errors/client-declared-by-document', $body['type']);
        $stored = $this->clients()->findOneByClientId(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($stored);
        self::assertSame($nameBefore, $stored->getName());
        self::assertSame($redirectsBefore, $stored->redirectUris());
        self::assertSame([], self::securityAuditEvents('mcp-client-revised'));
    }

    public function testAnInvalidRevisionAnswers422AtItsPath(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $stored = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Nom', ['https://client.example.test/callback']);
        $this->clients()->add($stored);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', '/api/backoffice/mcp/clients/'.$stored->getId()->toRfc4122(), server: $this->jsonWrite($csrf), content: self::jsonBody([
            'name' => 'Autre nom',
            'redirectUris' => ['not a redirect uri'],
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['violations']);
        self::assertIsArray($body['violations'][0]);
        self::assertSame('redirectUris[0]', $body['violations'][0]['propertyPath']);
        // Un refus ne modifie rien et n'audite rien.
        self::assertSame('Nom', $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID)?->getName());
        self::assertSame([], self::securityAuditEvents('mcp-client-revised'));
    }

    /**
     * Le `clientId` est immuable (il est celui que les connexions et les
     * consentements ont vu) : l'envoyer dans un `PUT` n'est pas « ignoré avec
     * un 200 », c'est un corps mal formé. `allow_extra_attributes: false` sur
     * l'opération le refuse (`MalformedRequestBodyException`, 400, message fixe) ;
     * tout autre attribut inconnu l'est de la même façon, à dessein : un corps
     * qui ne tient pas le contrat échoue au lieu de laisser croire qu'un champ
     * a été pris en compte. Le refus précède toute écriture : la ligne est
     * intacte, rien n'est audité, et la valeur saisie n'est jamais recopiée.
     */
    #[DataProvider('bodiesWithAnAttributeTheRevisionDoesNotTake')]
    public function testARevisionBodyWithAnUnknownAttributeIsRefusedAsMalformed(string $attribute, string $value): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $stored = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Nom intact', ['https://client.example.test/callback']);
        $this->clients()->add($stored);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', '/api/backoffice/mcp/clients/'.$stored->getId()->toRfc4122(), server: $this->jsonWrite($csrf), content: self::jsonBody([
            'name' => 'Nom changé',
            'redirectUris' => ['https://client.example.test/callback'],
            $attribute => $value,
        ]));

        self::assertResponseStatusCodeSame(400);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($value, $content, 'La valeur saisie n\'est jamais recopiée dans la réponse.');
        $reloaded = $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID);
        self::assertNotNull($reloaded);
        self::assertSame('Nom intact', $reloaded->getName(), 'Un corps refusé n\'écrit rien.');
        self::assertSame([], self::securityAuditEvents('mcp-client-revised'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesWithAnAttributeTheRevisionDoesNotTake(): iterable
    {
        yield 'clientId (immuable)' => ['clientId', 'https://autre.example.test/metadata.json'];
        yield 'attribut inconnu' => ['colour', 'turquoise-value'];
    }

    public function testRevisingAnUnknownClientIs404(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('PUT', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID, server: $this->jsonWrite($csrf), content: self::jsonBody([
            'name' => 'Nom',
            'redirectUris' => ['https://client.example.test/callback'],
        ]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], self::securityAuditEvents('mcp-client-revised'));
    }

    public function testDeletingAnUnknownClientIs404(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('DELETE', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrf]);

        self::assertResponseStatusCodeSame(404);
        // Un refus ne coupe rien et n'audite rien.
        self::assertSame([], self::securityAuditEvents('mcp-client-removed'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-revoked'));
    }

    /**
     * Course : le client disparaît entre la lecture du Provider (404 évité) et
     * l'écriture. L'administrateur relit sous verrou et lève
     * `McpClientNotFoundException`, que `exception_to_status` rend en 404 : le
     * Processor n'a rien à rattraper. Joué sans HTTP, le Provider étant ce qu'on
     * contourne : le même 404 qu'un identifiant inconnu, pas un 500.
     */
    public function testAClientGoneBeforeTheWriteRaisesTheNotFoundException(): void
    {
        self::bootKernel();
        $processor = self::getContainer()->get(BackofficeMcpClientProcessor::class);

        try {
            // Pour un Delete, API Platform passe la ressource lue par le Provider (ici
            // celle qu'il avait vue avant la disparition) ; le trait n'en lit que l'`id` d'URI.
            $seen = new BackofficeMcpClientResource(self::UNKNOWN_ID, 'gone', 'Gone', [], 'preregistered', '2026-01-01T00:00:00+00:00', 0);
            $processor->process($seen, new Delete(), ['id' => self::UNKNOWN_ID]);
            self::fail('Un client absent doit lever McpClientNotFoundException.');
        } catch (McpClientNotFoundException $exception) {
            self::assertSame(404, $exception->getStatus());
        }
    }

    /**
     * Un client CIMD se supprime (rien ne le ressaisit à la main) : la ligne
     * disparaît avec ses connexions (cascade, D-A), une ligne d'audit par
     * connexion coupée, de cause `client-removed`, puis `mcp-client-removed`.
     * Une connexion déjà révoquée n'est pas recoupée ; celle d'un autre
     * client est intacte.
     */
    public function testDeletingACimdClientCutsItsConnections(): void
    {
        $client = self::createClient();
        $super = $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $discovered = ClaudeCodeMetadata::storedRow();
        $other = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Autre client', ['https://client.example.test/callback']);
        $this->clients()->add($discovered);
        $this->clients()->add($other);

        $jane = $this->registerAccount($client, 'jane');
        $john = $this->registerAccount($client, 'john');
        $revoked = $this->registerAccount($client, 'revoked');
        $connections = $this->connections();
        $cut = [
            McpConnection::grant($jane, $discovered, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($john, $discovered, self::SCOPE, new DateTimeImmutable()),
        ];
        $alreadyRevoked = McpConnection::grant($revoked, $discovered, self::SCOPE, new DateTimeImmutable());
        $foreign = McpConnection::grant($jane, $other, self::SCOPE, new DateTimeImmutable());
        foreach ([...$cut, $alreadyRevoked, $foreign] as $connection) {
            $connections->add($connection);
        }
        $connections->revokeActive($alreadyRevoked->getId(), RevocationCause::Client, new DateTimeImmutable());
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('DELETE', '/api/backoffice/mcp/clients/'.$discovered->getId()->toRfc4122(), server: ['HTTP_X_XSRF_TOKEN' => $csrf]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertNull($this->clients()->findOneByClientId(ClaudeCodeMetadata::CLIENT_ID));
        self::assertNotNull($this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID));
        self::assertSame(0, $this->countConnectionRowsOf($discovered->getId()->toRfc4122()));
        self::assertSame(1, $this->connections()->countActiveOfClient($other->getId()), 'La connexion d\'un autre client est intacte.');

        $lines = self::securityAuditEvents('mcp-connection-revoked');
        $connectionIds = array_map(static fn (array $line): mixed => $line['connectionId'] ?? null, $lines);
        sort($connectionIds);
        $expected = array_map(static fn (McpConnection $connection): string => $connection->getId()->toRfc4122(), $cut);
        sort($expected);
        self::assertSame($expected, $connectionIds, 'Une ligne par connexion coupée, aucune pour la connexion déjà révoquée.');
        foreach ($lines as $line) {
            self::assertSame('client-removed', $line['cause'] ?? null);
            self::assertSame($super->getId()->toRfc4122(), $line['actor'] ?? null);
        }
        $event = self::singleSecurityAuditEvent('mcp-client-removed');
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $event['clientId']);
        self::assertSame('cimd', $event['registration']);
        self::assertSame(2, $event['cutConnections']);
        self::assertSame($super->getId()->toRfc4122(), $event['actor']);
    }

    /**
     * `McpClientAdministrator` (enregistrement, révision, suppression) audite
     * **après** son COMMIT : un Processor qui enveloppait l'appel dans une
     * transaction ferait précéder le COMMIT par l'audit, et un échec du COMMIT
     * laisserait une opération auditée que la base n'a pas. Le Processor
     * n'ouvre donc aucune transaction, ne touche ni l'EntityManager ni la
     * connexion : il assemble et délègue. Le source entier est lu, `create()`,
     * `update()` et `delete()` compris.
     */
    public function testTheProcessorOpensNoTransactionAroundAnyWrite(): void
    {
        $source = RepositoryFiles::read(RepositoryFiles::backend('src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProcessor.php'));

        foreach (['beginTransaction', 'wrapInTransaction', 'TransactionInterface', 'Doctrine\\'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'Le Processor ne porte aucune transaction : l\'audit suit le COMMIT de l\'administrateur.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function jsonWrite(string $csrf): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf];
    }

    /**
     * La garde est l'`access_control`, pas la ressource : un compte de
     * confiance sans ROLE_SUPER et un jeton du palier de base reçoivent 403,
     * un anonyme 401 (403 pour une écriture : la garde CSRF, qui précède le
     * pare-feu, refuse d'abord une écriture sans cookie de session).
     *
     * Chaque opération déclarée figure ici : une route absente répondrait 404 à
     * tout le monde, anonyme compris, car le routeur passe avant le pare-feu
     * (constaté à la Task 16 sur Post, Put et Delete), et le test ne prouverait
     * rien. Les opérations de la Task 18 (connexions) entrent dans ce
     * fournisseur avec leur route. Le troisième élément est le corps d'une
     * écriture : un corps valide, pour que seul le refus de l'accès puisse
     * répondre. Un chemin portant {@see self::ID_PLACEHOLDER} vise le client
     * semé par le test, qui doit sortir de chaque refus intact.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function operations(): iterable
    {
        yield 'GetCollection' => ['GET', '/api/backoffice/mcp/clients', null];
        yield 'Get' => ['GET', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID, null];
        yield 'Post' => ['POST', '/api/backoffice/mcp/clients', [
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['https://client.example.test/callback'],
        ]];
        yield 'Put' => ['PUT', '/api/backoffice/mcp/clients/'.self::ID_PLACEHOLDER, [
            'name' => 'Nom imposé',
            'redirectUris' => ['https://attacker.example.test/callback'],
        ]];
        yield 'Delete' => ['DELETE', '/api/backoffice/mcp/clients/'.self::ID_PLACEHOLDER, null];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('operations')]
    public function testEveryOperationRefusesAnAccountWithoutSuper(string $method, string $path, ?array $body): void
    {
        $content = null === $body ? null : self::jsonBody($body);
        $json = null === $body ? [] : ['CONTENT_TYPE' => 'application/json'];
        // Une écriture (POST, PUT, DELETE) est refusée d'abord par la garde CSRF, avant le pare-feu.
        $isWrite = 'GET' !== $method;

        // Le client semé : une ligne, une connexion active. Chaque refus doit la laisser intacte.
        $anonymous = self::createClient();
        $seeded = RegisteredMcpClient::of(self::SEEDED_CLIENT_ID, 'Client semé', ['https://seeded.example.test/callback']);
        $this->clients()->add($seeded);
        $this->connections()->add(McpConnection::grant($this->registerAccount($anonymous, 'holder'), $seeded, self::SCOPE, new DateTimeImmutable()));
        $path = str_replace(self::ID_PLACEHOLDER, $seeded->getId()->toRfc4122(), $path);

        // Anonyme : 401 (403 par la garde CSRF pour une écriture).
        $anonymous->request($method, $path, server: $json, content: $content);
        self::assertResponseStatusCodeSame($isWrite ? 403 : 401, 'Anonyme : '.$method.' '.$path);

        // Compte de confiance sans ROLE_SUPER : 403.
        self::ensureKernelShutdown();
        $trusted = self::createClient();
        $this->registerAccount($trusted, self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $csrf = $this->loginAs($trusted, self::TRUSTED_USERNAME, TestCredentials::plainPassword());
        $trusted->request($method, $path, server: [...$json, 'HTTP_X_XSRF_TOKEN' => $csrf], content: $content);
        self::assertResponseStatusCodeSame(403, 'Compte de confiance : '.$method.' '.$path);

        // Jeton du palier de base (ROLE_USER seul) : 403.
        self::ensureKernelShutdown();
        $base = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $base->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        $csrfCookie = $base->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);
        $base->request($method, $path, server: [...$json, 'HTTP_X_XSRF_TOKEN' => $csrfCookie->getValue()], content: $content);
        self::assertResponseStatusCodeSame(403, 'Palier de base : '.$method.' '.$path);

        // Un refus n'écrit, ne modifie, ne supprime ni ne révoque rien, et n'audite rien.
        self::assertNull($this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID));
        $after = $this->clients()->findOneByClientId(self::SEEDED_CLIENT_ID);
        self::assertNotNull($after, 'Le client semé a survécu au refus.');
        self::assertSame('Client semé', $after->getName());
        self::assertSame(['https://seeded.example.test/callback'], $after->redirectUris());
        self::assertSame(1, $this->connections()->countActiveOfClient($seeded->getId()), 'Aucune connexion n\'a été coupée.');
        foreach (['mcp-client-registered', 'mcp-client-revised', 'mcp-client-removed', 'mcp-connection-revoked'] as $event) {
            self::assertSame([], self::securityAuditEvents($event), 'Un refus n\'audite pas : '.$event);
        }
    }

    /**
     * Le coût de la liste ne dépend pas du nombre de clients : une requête
     * pour les clients, une `GROUP BY` pour les connexions actives — jamais
     * une par client. Mesuré sur le Provider, hors authentification, pour que
     * le compte ne soit pas celui de la garde.
     */
    public function testListingTakesTwoQueriesWhateverTheClientCount(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());

        $this->addClientsWithOneConnectionEach(2, 0);
        $few = $this->queriesDuring(fn (): array => $this->listing());

        $this->addClientsWithOneConnectionEach(6, 2);
        $many = $this->queriesDuring(fn (): array => $this->listing());

        self::assertCount(2, $few['result']);
        self::assertCount(8, $many['result']);
        self::assertSame(2, $few['count'], 'Deux requêtes pour 2 clients.');
        self::assertSame(2, $many['count'], 'Deux requêtes pour 8 clients : pas de N+1.');
    }

    /**
     * Le détail d'un client est une vraie lecture d'item : une requête pour le
     * client, une pour ses connexions actives — jamais la liste entière ni le
     * décompte de tous les clients. Même mesure que la liste, sur le Provider,
     * à 2 puis 8 clients : le coût ne grandit pas avec leur nombre.
     */
    public function testDetailTakesTwoQueriesWhateverTheClientCount(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());

        $this->addClientsWithOneConnectionEach(2, 0);
        $id = $this->idOfDetailedClient();
        $few = $this->queriesDuring(fn (): mixed => $this->provider()->provide(new Get(), ['id' => $id]));

        $this->addClientsWithOneConnectionEach(6, 2);
        $id = $this->idOfDetailedClient();
        $many = $this->queriesDuring(fn (): mixed => $this->provider()->provide(new Get(), ['id' => $id]));

        foreach ([$few, $many] as $read) {
            self::assertInstanceOf(BackofficeMcpClientResource::class, $read['result']);
            self::assertSame('https://client0.example.test/metadata.json', $read['result']->clientId);
            self::assertSame(1, $read['result']->activeConnections);
        }
        self::assertSame(2, $few['count'], 'Deux requêtes pour 2 clients.');
        self::assertSame(2, $many['count'], 'Deux requêtes pour 8 clients.');
        // Le nombre de requêtes ne suffit pas (la liste en coûte aussi deux) :
        // seul le client demandé a été chargé, pas les sept autres.
        $loaded = self::getContainer()->get(EntityManagerInterface::class)->getUnitOfWork()->getIdentityMap()[McpClient::class] ?? [];
        self::assertCount(1, $loaded, 'Le détail ne lit que le client demandé, pas toute la liste.');
    }

    /**
     * Un identifiant inconnu : le Provider lève `McpClientNotFoundException`
     * (la même que l'administrateur dans la course), après la seule lecture du
     * client — sans décompte à faire.
     */
    public function testDetailOfAnUnknownClientRaisesTheNotFoundExceptionAfterOneQuery(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());
        $this->addClientsWithOneConnectionEach(2, 0);

        $queries = $this->queriesDuring(function (): bool {
            try {
                $this->provider()->provide(new Get(), ['id' => self::UNKNOWN_ID]);
            } catch (McpClientNotFoundException) {
                return true;
            }

            return false;
        });

        self::assertTrue($queries['result'], 'Un client absent lève McpClientNotFoundException (404 typé), il ne rend pas null.');
        self::assertSame(1, $queries['count'], 'Un client absent coûte une requête : pas de décompte pour rien.');
    }

    /**
     * Une seule forme de 404 pour un client absent : l'identifiant d'URI inconnu
     * (Provider) et la course (administrateur) lèvent la même exception, donc
     * le même problème typé — et non le 404 générique d'API Platform, d'un autre
     * `type` et d'un autre `detail`. Le message est fixe : il ne recopie jamais
     * l'identifiant demandé.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('operationsOnAnUnknownClient')]
    public function testAnUnknownClientAnswersTheSameTypedProblemWhateverTheOperation(string $method, ?array $body): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request($method, '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID, server: $this->jsonWrite($csrf), content: null === $body ? null : self::jsonBody($body));

        self::assertResponseStatusCodeSame(404);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        $raised = McpClientNotFoundException::forTheCaller();
        self::assertIsString($problem['type'] ?? null);
        self::assertStringEndsWith('/errors/mcp-client-not-found', $problem['type']);
        self::assertSame($raised->getDetail(), $problem['detail'] ?? null);
        self::assertStringNotContainsString(self::UNKNOWN_ID, (string) $client->getResponse()->getContent(), 'Le corps ne recopie pas l\'identifiant demandé.');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null}>
     */
    public static function operationsOnAnUnknownClient(): iterable
    {
        yield 'Get' => ['GET', null];
        yield 'Put' => ['PUT', ['name' => 'Nom', 'redirectUris' => ['https://client.example.test/callback']]];
        yield 'Delete' => ['DELETE', null];
    }

    /**
     * `PUT` et `DELETE` passent aussi par le Provider d'item, mais il ne leur
     * sert que de preuve d'existence : leur écriture relit sous verrou, et la
     * réponse du `PUT` recompte elle-même les connexions après la révision. Le
     * décompte des connexions actives est un `GET` : une requête, celle du client.
     */
    #[DataProvider('writesReadingAnExistingClient')]
    public function testAWriteOnlyReadsTheClientWithoutCountingItsConnections(Operation $operation): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());
        $this->addClientsWithOneConnectionEach(2, 0);
        $id = $this->idOfDetailedClient();

        $read = $this->queriesDuring(fn (): mixed => $this->provider()->provide($operation, ['id' => $id]));

        self::assertInstanceOf(BackofficeMcpClientResource::class, $read['result']);
        self::assertSame($id, $read['result']->id, 'Le client lu est celui de l\'URI.');
        self::assertSame(1, $read['count'], 'Une écriture ne lit que le client : le décompte des connexions n\'est pas le sien.');
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function writesReadingAnExistingClient(): iterable
    {
        yield 'Put' => [new Put()];
        yield 'Delete' => [new Delete()];
    }

    /**
     * @return list<mixed>
     */
    private function listing(): array
    {
        $provider = self::getContainer()->get(BackofficeMcpClientProvider::class);
        $rows = $provider->provide(new GetCollection());
        self::assertIsArray($rows);

        return $rows;
    }

    /**
     * La clé du client mesuré, lue **avant** la mesure, puis l'unité de travail
     * vidée : le cache d'identité ne doit pas masquer une requête.
     */
    private function idOfDetailedClient(): string
    {
        $client = $this->clients()->findOneByClientId('https://client0.example.test/metadata.json');
        self::assertNotNull($client);
        $id = $client->getId()->toRfc4122();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $id;
    }

    private function provider(): BackofficeMcpClientProvider
    {
        return self::getContainer()->get(BackofficeMcpClientProvider::class);
    }

    private function addClientsWithOneConnectionEach(int $count, int $offset): void
    {
        // Relu à chaque appel : l'unité de travail est vidée en fin de méthode,
        // une instance gardée d'un appel à l'autre serait détachée.
        $account = $this->findAccount('jane');
        for ($i = $offset; $i < $offset + $count; ++$i) {
            $client = RegisteredMcpClient::of(\sprintf('https://client%d.example.test/metadata.json', $i), 'Client '.$i, ['https://client.example.test/callback']);
            $this->clients()->add($client);
            $this->connections()->add(McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable()));
        }
        // Les instances ajoutées restent dans l'unité de travail : la mesure
        // doit relire la base, pas le cache d'identité.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * Exécute `$action` et compte les requêtes de la connexion DBAL du
     * conteneur de test (`doctrine.debug_data_holder`, DBAL 4). Un relevé vide
     * fait échouer le test plutôt que de laisser un zéro passer pour une preuve.
     * Copie locale de McpConnectionRepositoryTest::queriesDuring() ; son
     * extraction vers tests/Support est prévue à la Task 19.
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return array{count: int, result: T}
     */
    private function queriesDuring(callable $action): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $result = $action();

        $count = \count($holder->getData()['default'] ?? []);
        self::assertGreaterThan(0, $count, 'Le relevé des requêtes est vide : le profilage DBAL est inactif, le compteur ne prouverait rien.');

        return ['count' => $count, 'result' => $result];
    }

    /**
     * @param list<string> $roles
     */
    private function registerAccount(KernelBrowser $client, string $username, array $roles = []): CpgUser
    {
        $password = match ($username) {
            self::SUPER_USERNAME => TestCredentials::superPassword(),
            default => TestCredentials::plainPassword(),
        };

        return $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($username, $password, $roles);
    }

    private function findAccount(string $username): CpgUser
    {
        $account = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CpgUser::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(CpgUser::class, $account);

        return $account;
    }

    private function countClientRows(): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT count(*) FROM mcp_client');
        self::assertIsInt($count);

        return $count;
    }

    private function countConnectionRowsOf(string $clientRowId): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT count(*) FROM mcp_connection WHERE mcp_client_id = :id',
            ['id' => $clientRowId],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
