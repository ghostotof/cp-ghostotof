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
 * Couvre PUT /api/backoffice/users/{id}/roles (promotion / rétrogradation
 * ROLE_SUPER), réservé ROLE_SUPER.
 *
 * Le cas « dernier super-admin » (CannotDemoteLastSuperAdminException) n'est
 * pas atteignable ici : l'appelant est toujours lui-même ROLE_SUPER, donc dès
 * qu'une autre cible est ROLE_SUPER le décompte vaut au moins 2. Cette garde
 * défensive est couverte par CpgUserRoleAdministratorTest (unitaire).
 */
final class BackofficeUserRoleResourceTest extends WebTestCase
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
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        // PUT "unsafe" : le contrôle CSRF (priorité 20) rejette en 403 avant le
        // firewall, faute de cookie/header XSRF-TOKEN.
        $client->request('PUT', '/api/backoffice/users/1/roles', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['superAdmin' => true]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('PUT', '/api/backoffice/users/1/roles', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['superAdmin' => true]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleSuperPromotesThenDemotesAnotherUser(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken];

        $janeId = $this->findId($this->fetchUsers($client), self::PLAIN_USERNAME);

        // Promotion
        $client->request('PUT', sprintf('/api/backoffice/users/%d/roles', $janeId), server: $server, content: self::jsonBody(['superAdmin' => true]));
        self::assertResponseStatusCodeSame(204);
        self::assertContains(CpgUser::ROLE_SUPER, $this->findRoles($this->fetchUsers($client), self::PLAIN_USERNAME));

        // Idempotent
        $client->request('PUT', sprintf('/api/backoffice/users/%d/roles', $janeId), server: $server, content: self::jsonBody(['superAdmin' => true]));
        self::assertResponseStatusCodeSame(204);

        // Rétrogradation (le compte "super" reste ROLE_SUPER)
        $client->request('PUT', sprintf('/api/backoffice/users/%d/roles', $janeId), server: $server, content: self::jsonBody(['superAdmin' => false]));
        self::assertResponseStatusCodeSame(204);
        self::assertNotContains(CpgUser::ROLE_SUPER, $this->findRoles($this->fetchUsers($client), self::PLAIN_USERNAME));
    }

    public function testDemotingOwnAccountReturns409(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $superId = $this->findId($this->fetchUsers($client), self::SUPER_USERNAME);

        $client->request('PUT', sprintf('/api/backoffice/users/%d/roles', $superId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['superAdmin' => false]));

        self::assertResponseStatusCodeSame(409);

        // Le `type` du problem+json porte un slug stable, exploité par le
        // frontend pour choisir le message affiché sans analyser le `detail`.
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('/errors/cannot-modify-own-roles', $body['type'] ?? null);
    }

    public function testMissingSuperAdminFieldReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $janeId = $this->findId($this->fetchUsers($client), self::PLAIN_USERNAME);

        $client->request('PUT', sprintf('/api/backoffice/users/%d/roles', $janeId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownIdReturns404(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('PUT', '/api/backoffice/users/999999/roles', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['superAdmin' => true]));

        self::assertResponseStatusCodeSame(404);
    }

    private function fetchUsers(KernelBrowser $client): mixed
    {
        $client->request('GET', '/api/backoffice/users');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{id: int, username: string, roles: list<string>}> $users
     *
     * @return list<string>
     */
    private function findRoles(array $users, string $username): array
    {
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                return $user['roles'];
            }
        }

        throw new \RuntimeException(sprintf('User "%s" not found.', $username));
    }

    /**
     * @param list<array{id: int, username: string, roles: list<string>}> $users
     */
    private function findId(array $users, string $username): int
    {
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                return $user['id'];
            }
        }

        throw new \RuntimeException(sprintf('User "%s" not found.', $username));
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
