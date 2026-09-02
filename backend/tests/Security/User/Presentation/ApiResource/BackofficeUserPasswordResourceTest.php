<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre PUT /api/backoffice/users/{id}/password, réservé ROLE_SUPER. Le test
 * le plus important vérifie, via un cycle de login réel, que l'ancien mot de
 * passe cesse de fonctionner et que le nouveau fonctionne — pas seulement que
 * l'endpoint répond 204.
 */
final class BackofficeUserPasswordResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string SUPER_PASSWORD = 'SuperSecret123';
    private const string PLAIN_USERNAME = 'jane';
    private const string OLD_PASSWORD = 'OldSecurePassword123';
    private const string NEW_PASSWORD = 'NewSecurePassword456';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        // PUT est une méthode "unsafe" : le contrôle CSRF double-submit-cookie
        // (CsrfCookieRequestSubscriber, priorité 20) s'exécute avant même le
        // firewall Security (priorité 8) et rejette en 403 faute de cookie/
        // header XSRF-TOKEN, sans jamais atteindre la vérification d'authentification.
        $client->request('PUT', '/api/backoffice/users/1/password', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => self::NEW_PASSWORD]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::OLD_PASSWORD);
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, self::OLD_PASSWORD);

        // Header CSRF valide fourni : ce test doit échouer sur le contrôle
        // ROLE_SUPER, pas sur le contrôle CSRF (cf. testAnonymousRequestIsRejected).
        $client->request('PUT', '/api/backoffice/users/1/password', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => self::NEW_PASSWORD]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testChangePasswordThenOldPasswordFailsAndNewPasswordWorks(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::OLD_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('PUT', sprintf('/api/backoffice/users/%d/password', $jane->getId()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => self::NEW_PASSWORD]));
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());

        // L'ancien mot de passe échoue désormais
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::PLAIN_USERNAME,
            'password' => self::OLD_PASSWORD,
        ]));
        self::assertResponseStatusCodeSame(401);

        // Le nouveau mot de passe fonctionne
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::PLAIN_USERNAME,
            'password' => self::NEW_PASSWORD,
        ]));
        self::assertResponseIsSuccessful();
    }

    public function testPasswordTooShortIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::OLD_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('PUT', sprintf('/api/backoffice/users/%d/password', $jane->getId()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => 'short']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testPasswordTooLongIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $jane = $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::OLD_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        // 4097 caractères : au-delà de CpgUser::MAX_PASSWORD_LENGTH, le hasher
        // Symfony lèverait une exception (500). La contrainte Assert\Length
        // doit intercepter en 422 avant d'atteindre le Processor.
        $client->request('PUT', sprintf('/api/backoffice/users/%d/password', $jane->getId()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => str_repeat('a', CpgUser::MAX_PASSWORD_LENGTH + 1)]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownIdReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('PUT', '/api/backoffice/users/999999/password', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['password' => self::NEW_PASSWORD]));

        self::assertResponseStatusCodeSame(404);
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
