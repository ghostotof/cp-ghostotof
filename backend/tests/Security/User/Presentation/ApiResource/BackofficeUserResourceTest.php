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
 * Couvre GET /api/backoffice/users (listing) et DELETE /api/backoffice/users/{id},
 * réservés ROLE_SUPER, en miroir de BackofficeExperienceTechnologyResourceTest.
 */
final class BackofficeUserResourceTest extends WebTestCase
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

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListDeleteAndSelfDeleteGuardAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        // GetCollection : liste les 2 comptes, jamais de champ password
        $client->request('GET', '/api/backoffice/users');
        self::assertResponseIsSuccessful();
        $users = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $users);
        foreach ($users as $user) {
            self::assertArrayNotHasKey('password', $user);
            self::assertIsInt($user['id']);
        }

        $superId = $this->findIdByUsername($users, self::SUPER_USERNAME);
        $janeId = $this->findIdByUsername($users, self::PLAIN_USERNAME);

        // Auto-suppression => 409
        $client->request('DELETE', sprintf('/api/backoffice/users/%d', $superId), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(409);

        // Id inconnu => 404
        $client->request('DELETE', '/api/backoffice/users/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Suppression d'un autre utilisateur => 204
        $client->request('DELETE', sprintf('/api/backoffice/users/%d', $janeId), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        // La liste ne contient plus que le compte super
        $client->request('GET', '/api/backoffice/users');
        $remaining = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([self::SUPER_USERNAME], array_column($remaining, 'username'));
    }

    /**
     * @param list<array{id: int, username: string, roles: list<string>}> $users
     */
    private function findIdByUsername(array $users, string $username): int
    {
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                return $user['id'];
            }
        }

        throw new \RuntimeException(sprintf('User "%s" not found in collection.', $username));
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
