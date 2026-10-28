<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
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
 * - **Le second chemin d'écriture du mot de passe** (tâche 5b) : un lien
 *   d'invitation qui **remplace** un mot de passe (compte encore « en attente »
 *   auquel un `ROLE_SUPER` en a posé un, #460) coupe les connexions comme le
 *   `PUT` ; le lien d'un compte sans mot de passe ne touche à rien, et un refus
 *   de la révocation annule l'activation entière (mot de passe, jeton, compte).
 *
 * - **Le palier perdu** (tâche 6) : la rétrogradation d'un `ROLE_SUPER` sans
 *   e-mail le ramène au palier de base (ADR 0003 D1) et coupe ses connexions,
 *   cause `trusted-tier-withdrawn`, ligne d'audit après `role-changed`. Une
 *   rétrogradation qui laisse `ROLE_TRUSTED` (compte avec e-mail), une promotion
 *   ou une sortie idempotente ne coupent rien ; un refus de la révocation annule
 *   le changement de rôle (atomicité). La suppression d'un compte coupe ses
 *   connexions par la cascade de la clé étrangère (rien à écrire).
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

    /**
     * Tâche 6. `trusted` (stocké `ROLE_SUPER`, sans e-mail) tient deux
     * connexions ; la rétrogradation par un administrateur le ramène au palier
     * de base : ses deux connexions meurent, cause `trusted-tier-withdrawn`,
     * celle d'un autre compte reste. Sur la requête du PUT : `role-changed`
     * d'abord, puis une ligne par connexion, l'acteur étant l'administrateur.
     * Rétabli `ROLE_TRUSTED` en SQL, l'ancien jeton reste refusé : c'est la
     * révocation qui le tient, pas l'absence passagère du rôle (le défaut
     * que le commentaire de #649 relevait).
     */
    public function testADemotionOutOfTheTrustedTierRevokesEveryConnection(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $first = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $second = $this->holdAnotherMcpConnection();
        $foreign = $this->holdConnectionOfAnotherAccount();
        $this->rewriteHeldAccountRoles($first, [CpgUser::ROLE_SUPER]);
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($first->accessToken));
        $this->callNominativeCorpus($sessionId, $first);
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'Avant la rétrogradation, la connexion est acceptée.');
        $adminId = $this->signInAsAdmin();

        $response = $this->setSuperAdminOf($first->accountId, false);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('trusted-tier-withdrawn', $this->causeOf($first));
        self::assertSame('trusted-tier-withdrawn', $this->causeOf($second));
        self::assertNull($this->causeOf($foreign), 'La connexion d\'un autre compte est intacte.');

        $events = [];
        foreach (self::securityAuditRecords() as $record) {
            $event = $record->context['event'] ?? null;
            if ('role-changed' === $event || self::REVOKED_EVENT === $event) {
                $events[] = $event;
            }
        }
        self::assertSame(['role-changed', self::REVOKED_EVENT, self::REVOKED_EVENT], $events, 'Une ligne par connexion, après celle du changement de rôle.');
        $lines = self::securityAuditEvents(self::REVOKED_EVENT);
        $connectionIds = array_map(static fn (array $line): mixed => $line['connectionId'] ?? null, $lines);
        sort($connectionIds);
        $expected = [$first->connectionId, $second->connectionId];
        sort($expected);
        self::assertSame($expected, $connectionIds);
        foreach ($lines as $line) {
            self::assertSame('trusted-tier-withdrawn', $line['cause'] ?? null);
            self::assertSame($first->accountId, $line['userId'] ?? null);
            self::assertSame($adminId, $line['actor'] ?? null);
        }

        // Le palier rendu en SQL ne ressuscite pas la connexion.
        $this->rewriteHeldAccountRoles($first, [CpgUser::ROLE_TRUSTED]);
        $this->client->getCookieJar()->clear();
        $this->callNominativeCorpus($sessionId, $first);
        $refused = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $refused->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $refused->headers->get('WWW-Authenticate'));
        $this->assertOAuthError($this->refresh($refreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
    }

    /**
     * Atomicité : un déclencheur refuse l'`UPDATE` de `mcp_connection`, la
     * révocation échoue, donc le changement de rôle aussi — 500, le compte reste
     * `ROLE_SUPER`, la connexion active, ni `role-changed` ni ligne MCP. Rouge
     * si la révocation sort de la fermeture du verrou d'administration.
     */
    public function testARefusedRevocationLeavesTheRolesUnchanged(): void
    {
        $held = $this->holdMcpConnection();
        $this->rewriteHeldAccountRoles($held, [CpgUser::ROLE_SUPER]);
        $this->signInAsAdmin();
        $this->refuseConnectionUpdates();

        $response = $this->setSuperAdminOf($held->accountId, false);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->allowConnectionUpdates();
        self::assertSame([], self::securityAuditEvents('role-changed'), 'Un changement annulé n\'est pas journalisé.');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->causeOf($held), 'La connexion reste active : rien n\'a été validé.');
        self::assertSame([CpgUser::ROLE_SUPER], $this->storedRolesOf($held->accountId), 'Le compte reste ROLE_SUPER.');
    }

    /**
     * Un compte **avec** e-mail garde `ROLE_TRUSTED` à la rétrogradation (octroi
     * nominatif, ADR 0003 D1) : il ne perd pas le palier, ses connexions
     * restent actives et rien n'est audité côté MCP. Rouge si l'on révoque dès
     * qu'on rétrograde, sans regarder les rôles écrits.
     */
    public function testADemotionKeepingTheTrustedTierRevokesNothing(): void
    {
        $held = $this->holdMcpConnection();
        $this->rewriteHeldAccountRoles($held, [CpgUser::ROLE_SUPER]);
        $this->alterMcpRows('UPDATE cpg_user SET email = :email WHERE id = :id', ['email' => 'invited-trusted@example.test', 'id' => $held->accountId]);
        $this->signInAsAdmin();

        $response = $this->setSuperAdminOf($held->accountId, false);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame([CpgUser::ROLE_TRUSTED], $this->storedRolesOf($held->accountId), 'Le palier est gardé.');
        self::assertCount(1, self::securityAuditEvents('role-changed'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->causeOf($held));
    }

    /** Une promotion n'ôte rien : `ROLE_SUPER` englobe `ROLE_TRUSTED` par la hiérarchie. */
    public function testAPromotionRevokesNothing(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAsAdmin();

        $response = $this->setSuperAdminOf($held->accountId, true);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, self::securityAuditEvents('role-changed'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->causeOf($held));
    }

    /**
     * Tâche 5b : un compte « en attente » à qui un `ROLE_SUPER` a posé un mot
     * de passe (#460) se connecte déjà, peut tenir des connexions ; le lien
     * d'invitation qu'il utilise ensuite **remplace** ce mot de passe. Les
     * connexions du compte meurent avec l'ancien, cause `password-changed`,
     * celle d'un autre compte reste ; sur la requête, `account-activated`
     * d'abord, puis une ligne par connexion, l'acteur étant `anonymous` (le
     * parcours est public).
     */
    public function testAnInvitationLinkThatReplacesAPasswordRevokesEveryConnectionOfTheAccount(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $first = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $second = $this->holdAnotherMcpConnection();
        $foreign = $this->holdConnectionOfAnotherAccount();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($first->accessToken));
        $linkToken = $this->makePendingWithItsPassword($first->accountId);

        $response = $this->completeSetupLink($linkToken);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('password-changed', $this->causeOf($first));
        self::assertSame('password-changed', $this->causeOf($second));
        self::assertNull($this->causeOf($foreign), 'La connexion d\'un autre compte est intacte.');
        $events = [];
        foreach (self::securityAuditRecords() as $record) {
            $event = $record->context['event'] ?? null;
            if ('account-activated' === $event || self::REVOKED_EVENT === $event) {
                $events[] = $event;
            }
        }
        self::assertSame(['account-activated', self::REVOKED_EVENT, self::REVOKED_EVENT], $events, 'Une ligne par connexion, après celle de l\'activation.');
        foreach (self::securityAuditEvents(self::REVOKED_EVENT) as $line) {
            self::assertSame('password-changed', $line['cause'] ?? null);
            self::assertSame($first->accountId, $line['userId'] ?? null);
            self::assertSame('anonymous', $line['actor'] ?? null);
        }
        $this->callNominativeCorpus($sessionId, $first);
        $refused = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $refused->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $refused->headers->get('WWW-Authenticate'));
        $this->assertOAuthError($this->refresh($refreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
    }

    /**
     * Tâche 5b, atomicité : un déclencheur refuse l'`UPDATE` de
     * `mcp_connection`, la révocation échoue — donc l'activation entière : 500,
     * l'ancien mot de passe ouvre encore la session, le compte reste en
     * attente, le jeton n'est pas consommé (le lien reste réessayable), la
     * connexion reste active, aucune ligne d'audit. Rouge si la révocation
     * sort de la fermeture du verrou du compte.
     */
    public function testARefusedRevocationLeavesTheActivationUndone(): void
    {
        $held = $this->holdMcpConnection();
        $linkToken = $this->makePendingWithItsPassword($held->accountId);
        $this->refuseConnectionUpdates();

        $response = $this->completeSetupLink($linkToken);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->allowConnectionUpdates();
        self::assertSame([], self::securityAuditEvents('account-activated'), 'Une activation annulée n\'est pas journalisée.');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->causeOf($held), 'La connexion reste active : rien n\'a été validé.');
        $account = $this->mcpRows()->fetchAssociative('SELECT activated_at FROM cpg_user WHERE id = :id', ['id' => $held->accountId]);
        self::assertIsArray($account);
        self::assertNull($account['activated_at'], 'Le compte reste en attente.');
        $used = $this->mcpRows()->fetchOne('SELECT used_at FROM password_setup_token WHERE token_hash = :hash', ['hash' => hash('sha256', $linkToken)]);
        self::assertNull($used, 'Le jeton n\'est pas consommé : le lien reste réessayable.');
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::TRUSTED_USERNAME,
            'password' => TestCredentials::variant('chosen'),
        ]));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'Le mot de passe du lien n\'a pas été posé.');
    }

    /**
     * Tâche 5b : le compte dont le mot de passe est **posé** (jamais
     * remplacé) par le lien ne touche à aucune connexion — il n'a pu en
     * accorder aucune ; celles d'un autre compte restent actives.
     */
    public function testAnInvitationLinkOfAnAccountWithoutPasswordRevokesNothing(): void
    {
        $foreign = $this->holdMcpConnection();
        $newcomer = new CpgUser('newcomer', '');
        $newcomer->markInvited(self::getContainer()->get(ClockInterface::class)->now());
        self::getContainer()->get(CpgUserRepositoryInterface::class)->add($newcomer);
        $linkToken = $this->issueSetupLinkFor($newcomer);

        $response = $this->completeSetupLink($linkToken);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, self::securityAuditEvents('account-activated'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->causeOf($foreign));
    }

    /**
     * F2 de la relecture de #650, premier écrivain : le `PUT` du backoffice date
     * le changement de mot de passe du compte **et de lui seul**, et le cookie
     * que la personne tenait avant (un cookie volé, dans l'attaque) n'obtient
     * plus rien du consentement : ni le résumé, ni l'accord, ni le refus — 401
     * `reauthentication-required`. Aucune connexion ni code n'est écrit, aucune
     * ligne `mcp-connection-granted`.
     */
    public function testAPasswordChangeStalesTheCookieHeldBeforeAtTheConsent(): void
    {
        $held = $this->holdMcpConnection();
        $cookiesHeldBefore = $this->cookiesOfASessionOpenedBefore();
        $foreign = $this->holdConnectionOfAnotherAccount();
        $this->signInAsAdmin();
        $connectionsBefore = $this->countRows('SELECT COUNT(*) FROM mcp_connection');
        $this->changePasswordOf($held->accountId);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());

        self::assertNotNull($this->credentialsChangedAtOf($held->accountId), 'Le PUT n\'a pas daté le changement de mot de passe.');
        self::assertNull($this->credentialsChangedAtOf($foreign->accountId), 'Le compte d\'un autre n\'est pas concerné.');

        $this->assertConsentRefusedAsAStaleSession($cookiesHeldBefore);
        self::assertSame($connectionsBefore, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-granted'));
    }

    /**
     * F2, second écrivain : le lien d'invitation qui **remplace** un mot de
     * passe date le changement, et le cookie d'avant ne consent plus.
     */
    public function testAnInvitationLinkReplacingAPasswordStalesTheCookieHeldBeforeAtTheConsent(): void
    {
        $held = $this->holdMcpConnection();
        $cookiesHeldBefore = $this->cookiesOfASessionOpenedBefore();
        $linkToken = $this->makePendingWithItsPassword($held->accountId);

        $response = $this->completeSetupLink($linkToken);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertNotNull($this->credentialsChangedAtOf($held->accountId), 'Le lien n\'a pas daté le remplacement du mot de passe.');

        $this->assertConsentRefusedAsAStaleSession($cookiesHeldBefore);
    }

    /**
     * F2, le lien qui **pose** un premier mot de passe date aussi le changement
     * (aucune condition à oublier : un chemin, une écriture).
     */
    public function testAnInvitationLinkSettingAFirstPasswordDatesTheChangeToo(): void
    {
        $newcomer = new CpgUser('newcomer', '');
        $newcomer->markInvited(self::getContainer()->get(ClockInterface::class)->now());
        self::getContainer()->get(CpgUserRepositoryInterface::class)->add($newcomer);
        $linkToken = $this->issueSetupLinkFor($newcomer);

        $this->completeSetupLink($linkToken);

        self::assertNotNull($this->credentialsChangedAtOf($newcomer->getId()->toRfc4122()), 'Le lien n\'a pas daté le premier mot de passe.');
    }

    /**
     * Le résumé, l'accord et le refus d'une session d'avant le changement : un
     * 401 que le **firewall** rend, avant tout contrôleur (R1b : l'écouteur de
     * `CheckPassportEvent` refuse la session pour toute l'API, le consentement
     * compris), sur le cookie qu'on vient de restaurer. Le refus du consentement
     * lui-même (`reauthentication-required`) n'est plus atteint par HTTP ; il
     * reste pincé en unitaire par `ConsentServiceTest`.
     *
     * Chaque refus **efface** le cookie (le navigateur le perdrait) : le test le
     * remet avant chaque requête, comme l'attaquant qui le garde.
     *
     * @param array<array-key, Cookie> $cookies la session d'avant le changement
     */
    private function assertConsentRefusedAsAStaleSession(array $cookies): void
    {
        $this->restoreCookies($cookies);
        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($this->validParameters()));
        $summary = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $summary->getStatusCode(), substr((string) $summary->getContent(), 0, 300));
        self::assertSame('Session closed by a password change.', $this->json($summary)['message'] ?? null);

        foreach ([true, false] as $approved) {
            $this->restoreCookies($cookies);
            $decision = $this->decide(approved: $approved);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $decision->getStatusCode(), substr((string) $decision->getContent(), 0, 300));
            self::assertSame('Session closed by a password change.', $this->json($decision)['message'] ?? null);
            self::assertArrayNotHasKey('redirectUri', $this->json($decision));
        }
    }

    /**
     * Les cookies de la personne, connectée **avant** le changement de mot de
     * passe : l'échange du code les a vidés (le client MCP n'en garde aucun), il
     * faut donc en ouvrir une session, que l'attaque (un cookie volé) a gardée.
     *
     * @return array<array-key, Cookie>
     */
    private function cookiesOfASessionOpenedBefore(): array
    {
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        return $this->client->getCookieJar()->all();
    }

    /**
     * @param array<array-key, Cookie> $cookies
     */
    private function restoreCookies(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    private function credentialsChangedAtOf(string $accountId): ?string
    {
        $value = $this->mcpRows()->fetchOne('SELECT credentials_changed_at FROM cpg_user WHERE id = :id', ['id' => $accountId]);

        return \is_string($value) ? $value : null;
    }

    /**
     * Rend « en attente » le compte de la connexion tenue (invité, jamais
     * activé) **en gardant son mot de passe** — l'état où un `ROLE_SUPER` a
     * posé un mot de passe sur une invitation (#460) — et lui émet un lien.
     */
    private function makePendingWithItsPassword(string $accountId): string
    {
        $this->alterMcpRows('UPDATE cpg_user SET invited_at = now(), activated_at = NULL WHERE id = :id', ['id' => $accountId]);
        $account = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername(self::TRUSTED_USERNAME);
        self::assertNotNull($account);
        self::assertTrue($account->isPendingActivation());
        self::assertTrue($account->hasPassword());

        return $this->issueSetupLinkFor($account);
    }

    /** Un lien utilisable pour le compte ; le jeton en clair est aléatoire, jamais un littéral. */
    private function issueSetupLinkFor(CpgUser $account): string
    {
        $clearToken = bin2hex(random_bytes(32));
        self::getContainer()->get(PasswordSetupTokenRepositoryInterface::class)->save(
            new PasswordSetupToken($account, hash('sha256', $clearToken), self::getContainer()->get(ClockInterface::class)->now()->add(new DateInterval('PT48H'))),
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $clearToken;
    }

    /** `POST /api/account/password-setup`, public : ni session ni CSRF, mais un quota par IP qu'on remet à zéro. */
    private function completeSetupLink(string $clearToken): Response
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/account/password-setup', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'token' => $clearToken,
            'password' => TestCredentials::variant('chosen'),
        ]));

        return $this->client->getResponse();
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

    /** `PUT /api/backoffice/users/{id}/roles`, garde CSRF double-submit comprise. */
    private function setSuperAdminOf(string $accountId, bool $grant): Response
    {
        $this->client->request('PUT', \sprintf('/api/backoffice/users/%s/roles', $accountId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ], content: self::jsonBody(['superAdmin' => $grant]));

        return $this->client->getResponse();
    }

    /**
     * Les rôles **stockés** du compte, lus en base.
     *
     * @return list<string>
     */
    private function storedRolesOf(string $accountId): array
    {
        $roles = $this->mcpRows()->fetchOne('SELECT roles FROM cpg_user WHERE id = :id', ['id' => $accountId]);
        self::assertIsString($roles);
        $decoded = json_decode($roles, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values(array_filter($decoded, is_string(...)));
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
