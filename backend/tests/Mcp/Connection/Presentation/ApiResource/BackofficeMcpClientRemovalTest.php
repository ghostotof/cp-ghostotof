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

    private function rowIdOfHeldClient(string $clientId): string
    {
        $id = $this->mcpRows()->fetchOne('SELECT id FROM mcp_client WHERE client_id = :clientId', ['clientId' => $clientId]);
        self::assertIsString($id);

        return $id;
    }
}
