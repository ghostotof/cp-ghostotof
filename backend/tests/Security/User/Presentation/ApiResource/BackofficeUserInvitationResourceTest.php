<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Couvre POST /api/backoffice/users/{id}/invitation (renvoi de l'invitation),
 * réservé ROLE_SUPER : régénère le jeton et redispatch l'e-mail, uniquement
 * pour un compte encore en attente d'activation.
 */
final class BackofficeUserInvitationResourceTest extends WebTestCase
{
    use HttpJson;
    use InvitesUsers;

    private const string SUPER_USERNAME = 'super';
    private const string SUPER_PASSWORD = 'SuperSecret123';
    private const string PLAIN_USERNAME = 'jane';
    private const string PLAIN_PASSWORD = 'SecurePassword123';
    private const string INVITEE_EMAIL = 'newcomer@example.com';
    private const string INVITEE_USERNAME = 'newcomer';

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

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/backoffice/users/1/invitation', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['locale' => 'fr']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('POST', '/api/backoffice/users/1/invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['locale' => 'fr']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testResendToAPendingUserDispatchesANewInvitation(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $this->invite();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $id = $this->findInviteeId($client);

        $client->request('POST', sprintf('/api/backoffice/users/%d/invitation', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['locale' => 'en']));

        self::assertResponseStatusCodeSame(202);

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);
        self::assertSame($id, $message->userId);
        self::assertSame('en', $message->locale);
    }

    public function testResendToAnUnknownIdReturns404(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('POST', '/api/backoffice/users/999999/invitation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['locale' => 'fr']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testResendToAnActivatedAccountReturns409(): void
    {
        $client = self::createClient();
        // /api/account/password-setup est rate-limité par IP sur un cache
        // filesystem partagé par tous les tests fonctionnels (127.0.0.1) : on
        // repart d'un quota vierge.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $token = $this->invite();

        // La personne définit son mot de passe -> compte activé.
        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => 'NotCompromisedPass1']));
        self::assertResponseStatusCodeSame(204);

        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $id = $this->findInviteeId($client);

        $client->request('POST', sprintf('/api/backoffice/users/%d/invitation', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['locale' => 'fr']));

        self::assertResponseStatusCodeSame(409);
    }

    public function testResendWithAMissingLocaleReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $this->invite();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $id = $this->findInviteeId($client);

        $client->request('POST', sprintf('/api/backoffice/users/%d/invitation', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([]));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Invite la personne via le use case (hors requête HTTP), exécute le
     * handler et renvoie le jeton de définition de mot de passe en clair
     * (extrait de l'e-mail : il ne transite plus par le message, cf. audit C2).
     */
    private function invite(): string
    {
        return $this->inviteAndCollectSetupToken(self::INVITEE_EMAIL, Locale::FR);
    }

    private function findInviteeId(KernelBrowser $client): int
    {
        $client->request('GET', '/api/backoffice/users');
        self::assertResponseIsSuccessful();
        $users = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $this->extractId($users);
    }

    /**
     * @param list<array{id: int, username: string}> $users
     */
    private function extractId(array $users): int
    {
        foreach ($users as $user) {
            if ($user['username'] === self::INVITEE_USERNAME) {
                return $user['id'];
            }
        }

        throw new \RuntimeException('Invited user not found.');
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
