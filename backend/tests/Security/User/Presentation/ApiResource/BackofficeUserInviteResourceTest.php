<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Couvre POST /api/backoffice/users (invitation d'un utilisateur par e-mail),
 * réservé ROLE_SUPER. Le transport Messenger "async" est en in-memory en test
 * (cf. when@test dans messenger.yaml) : on inspecte le message dispatché sans
 * consommer, donc sans envoyer de vrai e-mail.
 */
final class BackofficeUserInviteResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string SUPER_PASSWORD = 'SuperSecret123';
    private const string PLAIN_USERNAME = 'jane';
    private const string PLAIN_PASSWORD = 'SecurePassword123';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousInviteIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/backoffice/users', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'email' => 'newcomer@example.com',
            'locale' => 'fr',
        ]));

        // Bloqué en amont du firewall par la protection CSRF (aucun cookie/header
        // XSRF-TOKEN sans login) : de toute façon inaccessible sans ROLE_SUPER.
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testInviteWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'newcomer@example.com', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testRoleSuperInvitesAUserAndAnInvitationMessageIsDispatched(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'jean.dupont@example.com', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(201);

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('jean.dupont', $body['username']);
        self::assertSame('jean.dupont@example.com', $body['email']);
        self::assertSame('pending', $body['status']);
        self::assertContains('ROLE_USER', $body['roles']);
        self::assertArrayNotHasKey('password', $body);

        // Le message ne porte plus que { userId, locale } (audit C2 / D3) :
        // aucun secret, et le jeton est créé côté handler.
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);
        self::assertSame($body['id'], $message->userId);
        self::assertSame('fr', $message->locale);
    }

    public function testInviteWithAnAlreadyUsedEmailReturns409(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $payload = self::jsonBody(['email' => 'jean.dupont@example.com', 'locale' => 'fr']);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken];

        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInviteWithAnInvalidEmailReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'not-an-email', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testInviteWithAMissingLocaleReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'newcomer@example.com']));

        self::assertResponseStatusCodeSame(422);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
