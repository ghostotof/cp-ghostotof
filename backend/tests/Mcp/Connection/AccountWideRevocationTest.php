<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * La révocation de toutes les connexions d'un compte (#650, tâche 5 du plan,
 * décision 3), de bout en bout par HTTP : un `ROLE_SUPER` change le mot de
 * passe d'un compte (`PUT /api/backoffice/users/{id}/password`), et les
 * connexions MCP de ce compte meurent avec l'ancien mot de passe.
 *
 * - Toutes les connexions actives du compte, aucune d'un autre ; cause
 *   `password-changed`, une ligne d'audit par connexion, écrite **après** le
 *   COMMIT, à la suite de `password-changed`, l'acteur étant l'administrateur.
 * - **Atomicité** : la révocation et le nouveau mot de passe valident ensemble
 *   ou pas du tout. Un déclencheur de test refuse l'`UPDATE` de
 *   `mcp_connection` : la requête répond 500, l'ancien mot de passe ouvre
 *   encore la session, rien n'est audité. Rouge si l'appel sort de la
 *   transaction du verrou du compte (le mot de passe aurait déjà changé).
 * - Une requête n'écrit de ligne MCP que pour les connexions qu'elle a révoquées.
 *
 * Les connexions sont accordées par le vrai parcours OAuth
 * ({@see HoldsAnMcpConnection}) ; les mots de passe viennent de
 * {@see TestCredentials}, jamais d'un littéral.
 */
final class AccountWideRevocationTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string OTHER_TRUSTED_USERNAME = 'other-trusted';

    private const string ADMIN_USERNAME = 'admin';

    private const string REVOKED_EVENT = 'mcp-connection-revoked';

    private const string REFUSAL_FUNCTION = 'mcp_test_refuse_connection_update';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $connection = $this->mcpRows();
        // Le déclencheur d'un test qui a échoué avant de le retirer ne doit pas survivre.
        $connection->executeStatement('DROP TRIGGER IF EXISTS '.self::REFUSAL_FUNCTION.' ON mcp_connection');
        $connection->executeStatement('DROP FUNCTION IF EXISTS '.self::REFUSAL_FUNCTION.'()');
        self::forgetMcpSessions($connection);
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Le compte `trusted` tient deux connexions, `other-trusted` une : le
     * changement de mot de passe de `trusted` révoque les deux premières, cause
     * `password-changed`, laisse la troisième. Sur la requête du PUT :
     * `password-changed`, puis une ligne par connexion révoquée, l'acteur étant
     * l'administrateur ; l'ancien jeton est refusé (401) et le rafraîchissement
     * aussi (`invalid_grant`).
     */
    public function testAPasswordChangeRevokesEveryConnectionOfTheAccount(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $first = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $second = $this->holdAnotherMcpConnection();
        $foreign = $this->holdConnectionOfAnotherAccount();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($first->accessToken));
        $this->callNominativeCorpus($sessionId, $first);
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'Avant le changement, la connexion est acceptée.');
        $adminId = $this->signInAsAdmin();

        $response = $this->changePasswordOf($first->accountId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('password-changed', $this->causeOf($first));
        self::assertSame('password-changed', $this->causeOf($second));
        self::assertNotNull($this->revokedAtOf($first));
        self::assertNotNull($this->revokedAtOf($second));
        self::assertNull($this->causeOf($foreign), 'La connexion d\'un autre compte est intacte.');

        // Sur la requête du PUT : le mot de passe d'abord, puis une ligne par connexion révoquée.
        $events = [];
        foreach (self::securityAuditRecords() as $record) {
            $event = $record->context['event'] ?? null;
            if ('password-changed' === $event || self::REVOKED_EVENT === $event) {
                $events[] = $event;
            }
        }
        self::assertSame(['password-changed', self::REVOKED_EVENT, self::REVOKED_EVENT], $events);
        $lines = self::securityAuditEvents(self::REVOKED_EVENT);
        $connectionIds = array_map(static fn (array $line): mixed => $line['connectionId'] ?? null, $lines);
        sort($connectionIds);
        $expected = [$first->connectionId, $second->connectionId];
        sort($expected);
        self::assertSame($expected, $connectionIds);
        foreach ($lines as $line) {
            self::assertSame('password-changed', $line['cause'] ?? null);
            self::assertSame($first->accountId, $line['userId'] ?? null);
            self::assertSame($adminId, $line['actor'] ?? null);
        }

        // L'ancien jeton, rejoué dans la session ouverte avant le changement, est refusé ; le rafraîchissement aussi.
        $this->client->getCookieJar()->clear();
        $this->callNominativeCorpus($sessionId, $first);
        $refused = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $refused->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $refused->headers->get('WWW-Authenticate'));
        $this->assertOAuthError($this->refresh($refreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
    }

    /**
     * Atomicité : un déclencheur refuse l'`UPDATE` de `mcp_connection`, la
     * révocation échoue, donc le mot de passe aussi — 500, l'ancien mot de passe
     * ouvre toujours la session, le nouveau non, la connexion reste active,
     * aucune ligne `password-changed` ni `mcp-connection-revoked`. Rouge si la
     * révocation sort de la fermeture du verrou du compte : le mot de passe
     * serait déjà validé.
     */
    public function testARefusedRevocationLeavesThePasswordUnchanged(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAsAdmin();
        $this->refuseConnectionUpdates();

        $response = $this->changePasswordOf($held->accountId);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame([], self::securityAuditEvents('password-changed'), 'Un mot de passe annulé n\'est pas journalisé.');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        $this->allowConnectionUpdates();
        self::assertNull($this->causeOf($held), 'La connexion reste active : rien n\'a été validé.');
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::TRUSTED_USERNAME,
            'password' => TestCredentials::variant('new'),
        ]));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'Le nouveau mot de passe n\'a pas été posé.');
    }

    /** Un compte sans connexion : le mot de passe change, aucune ligne MCP n'est écrite. */
    public function testAPasswordChangeWithoutConnectionsWritesNoMcpLine(): void
    {
        $accountId = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->signInAsAdmin();

        $response = $this->changePasswordOf($accountId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, self::securityAuditEvents('password-changed'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /** Une connexion déjà révoquée par le client garde sa cause : le changement n'en écrit ni ne rejoue rien. */
    public function testAnAlreadyRevokedConnectionKeepsItsCause(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "revoked_at = now() - INTERVAL '1 hour', revocation_cause = 'client'");
        $this->signInAsAdmin();

        $response = $this->changePasswordOf($held->accountId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('client', $this->causeOf($held));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /** Crée l'administrateur et le connecte ; renvoie son UUID (l'acteur des lignes d'audit). */
    private function signInAsAdmin(): string
    {
        return $this->loginAs(self::ADMIN_USERNAME, [CpgUser::ROLE_SUPER]);
    }

    /** `PUT /api/backoffice/users/{id}/password`, garde CSRF double-submit comprise. */
    private function changePasswordOf(string $accountId): Response
    {
        $this->client->request('PUT', \sprintf('/api/backoffice/users/%s/password', $accountId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ], content: self::jsonBody(['password' => TestCredentials::variant('new')]));

        return $this->client->getResponse();
    }

    /** Une connexion de `other-trusted`, accordée par le vrai parcours ; le client MCP n'en garde pas les cookies. */
    private function holdConnectionOfAnotherAccount(): HeldMcpConnection
    {
        $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        return $this->heldMcpConnectionOf($this->exchange($this->codeGrantedByTheSignedInAccount()));
    }

    /** `tools/call get_nominative_corpus` dans la session, porté par le jeton de la connexion. */
    private function callNominativeCorpus(string $sessionId, HeldMcpConnection $held): void
    {
        $this->postMcp($this->client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'en']],
        ], self::bearer($held->accessToken));
    }

    /** Un déclencheur qui refuse toute réécriture de `mcp_connection`, comme le fait `TokenControllerTest` pour les jetons. */
    private function refuseConnectionUpdates(): void
    {
        $connection = $this->mcpRows();
        $connection->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION mcp_test_refuse_connection_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'panne de test : réécriture refusée';
            END
            $$
            SQL);
        $connection->executeStatement('DROP TRIGGER IF EXISTS '.self::REFUSAL_FUNCTION.' ON mcp_connection');
        $connection->executeStatement('CREATE TRIGGER '.self::REFUSAL_FUNCTION.' BEFORE UPDATE ON mcp_connection FOR EACH ROW EXECUTE FUNCTION '.self::REFUSAL_FUNCTION.'()');
    }

    private function allowConnectionUpdates(): void
    {
        $connection = $this->mcpRows();
        $connection->executeStatement('DROP TRIGGER IF EXISTS '.self::REFUSAL_FUNCTION.' ON mcp_connection');
        $connection->executeStatement('DROP FUNCTION IF EXISTS '.self::REFUSAL_FUNCTION.'()');
    }

    private function causeOf(HeldMcpConnection $held): ?string
    {
        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(false === $cause || null === $cause || \is_string($cause));

        return false === $cause ? null : $cause;
    }

    private function revokedAtOf(HeldMcpConnection $held): ?string
    {
        $at = $this->mcpRows()->fetchOne('SELECT revoked_at FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(false === $at || null === $at || \is_string($at));

        return false === $at ? null : $at;
    }
}
