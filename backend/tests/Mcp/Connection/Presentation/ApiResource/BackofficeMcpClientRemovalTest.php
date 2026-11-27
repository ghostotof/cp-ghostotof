<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\ApiResource;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsSecurityAuditLog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * La suppression d'un client MCP par le backoffice (#652, spec 0007 T13, Task
 * 17B), vue du client MCP : le jeton d'accès qu'une **vraie** connexion tient
 * (parcours OAuth complet, {@see HoldsAnMcpConnection}) n'ouvre plus rien
 * dès que le `DELETE` a répondu 204.
 *
 * Classe à part de {@see BackofficeMcpClientResourceTest} : le parcours OAuth
 * apporte son propre `KernelBrowser`, son `loginAs()` et son `purge()`, qui
 * entreraient en collision avec ceux de la classe des opérations.
 */
final class BackofficeMcpClientRemovalTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string ADMIN_USERNAME = 'admin';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions($this->mcpRows());
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Le jeton est accepté avant, refusé (401) après : la suppression coupe la
     * connexion à la requête suivante, sans attendre l'expiration du jeton
     * (ADR 0006 D4 : l'accès est relu en base à chaque requête). La coupure
     * est auditée avec sa cause, et l'acteur est l'administrateur du jeton.
     */
    public function testAfterDeletionTheHeldBearerOpensNothing(): void
    {
        $held = $this->holdMcpConnection();
        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($held->accessToken));
        self::assertResponseIsSuccessful('Avant la suppression, la connexion est acceptée.');

        $adminId = $this->loginAs(self::ADMIN_USERNAME, [CpgUser::ROLE_SUPER]);
        $this->client->request('DELETE', '/api/backoffice/mcp/clients/'.$this->rowIdOfHeldClient($held->clientId), server: [
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Le journal se lit tout de suite : la requête suivante redémarre le noyau, et son relevé avec lui.
        $revoked = self::singleSecurityAuditEvent('mcp-connection-revoked');
        self::assertSame($held->connectionId, $revoked['connectionId']);
        self::assertSame('client-removed', $revoked['cause']);
        self::assertSame($adminId, $revoked['actor']);
        $removed = self::singleSecurityAuditEvent('mcp-client-removed');
        self::assertSame($held->clientId, $removed['clientId']);
        self::assertSame(1, $removed['cutConnections']);
        self::assertSame($adminId, $removed['actor']);

        // Le client MCP ne porte que son jeton.
        $this->client->getCookieJar()->clear();
        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($held->accessToken));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        // La connexion est supprimée avec son client (cascade), pas seulement marquée.
        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]));
    }

    /**
     * Le cas courant du backoffice : un client **pré-enregistré** (le parcours
     * OAuth complet enregistre le sien par `McpClient::register()`), détenu par
     * une vraie connexion. Le `DELETE` répond 204 et supprime, **par cascade**,
     * la ligne du client, celle de la connexion, ses codes d'autorisation, ses
     * jetons d'accès et de rafraîchissement : aucune ligne ne survit à son
     * client. Le journal dit la cause (`client-removed`) et l'acteur, et le
     * jeton détenu n'ouvre plus rien. Test de caractérisation : il fixe un
     * comportement déjà en place, que le test CIMD ci-dessus ne couvrait ni
     * pour les codes ni pour les jetons.
     */
    public function testDeletingAPreregisteredClientRemovesItsRowAndEveryRowOfItsConnections(): void
    {
        $held = $this->holdMcpConnection();
        $clientRowId = $this->rowIdOfHeldClient($held->clientId);
        $byConnection = ['id' => $held->connectionId];
        $tables = ['mcp_connection' => 'id', 'mcp_authorization_code' => 'connection_id', 'mcp_access_token' => 'connection_id', 'mcp_refresh_token' => 'connection_id'];

        // Avant : chaque table porte au moins une ligne de la connexion, sans quoi la cascade ne prouverait rien.
        foreach ($tables as $table => $column) {
            self::assertGreaterThan(0, $this->countRows(\sprintf('SELECT count(*) FROM %s WHERE %s = :id', $table, $column), $byConnection), $table.' : une ligne avant la suppression.');
        }

        $adminId = $this->loginAs(self::ADMIN_USERNAME, [CpgUser::ROLE_SUPER]);
        $this->client->request('DELETE', '/api/backoffice/mcp/clients/'.$clientRowId, server: [
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        // Le journal se lit tout de suite : la requête suivante redémarre le noyau, et son relevé avec lui.
        $revoked = self::singleSecurityAuditEvent('mcp-connection-revoked');
        self::assertSame($held->connectionId, $revoked['connectionId']);
        self::assertSame('client-removed', $revoked['cause']);
        self::assertSame($adminId, $revoked['actor']);
        $removed = self::singleSecurityAuditEvent('mcp-client-removed');
        self::assertSame($held->clientId, $removed['clientId']);
        self::assertSame('preregistered', $removed['registration']);
        self::assertSame(1, $removed['cutConnections']);
        self::assertSame($adminId, $removed['actor']);

        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_client WHERE id = :id', ['id' => $clientRowId]), 'La ligne du client est supprimée.');
        foreach ($tables as $table => $column) {
            self::assertSame(0, $this->countRows(\sprintf('SELECT count(*) FROM %s WHERE %s = :id', $table, $column), $byConnection), $table.' : cascade.');
        }

        // Le client MCP ne porte que son jeton : il n'ouvre plus rien.
        $this->client->getCookieJar()->clear();
        $this->postMcpInitialize($this->client, ['name' => 'phpunit', 'version' => '1.0.0'], self::bearer($held->accessToken));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    private function rowIdOfHeldClient(string $clientId): string
    {
        $id = $this->mcpRows()->fetchOne('SELECT id FROM mcp_client WHERE client_id = :clientId', ['clientId' => $clientId]);
        self::assertIsString($id);

        return $id;
    }
}
