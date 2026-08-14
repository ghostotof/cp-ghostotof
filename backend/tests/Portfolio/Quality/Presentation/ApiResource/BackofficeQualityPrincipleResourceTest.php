<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Presentation\ApiResource;

use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/quality/principles,
 * en miroir de BackofficeStatResourceTest.
 */
final class BackofficeQualityPrincipleResourceTest extends WebTestCase
{
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
        $connection->executeStatement('DELETE FROM quality_principle');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/quality/principles');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/quality/principles');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetCollectionFiltersByLocaleQueryParameter(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $administrator = $client->getContainer()->get(QualityPrincipleAdministratorInterface::class);
        $administrator->create(Locale::FR, 'DDD', 'Description.', 'boxes', 0);
        $administrator->create(Locale::EN, 'DDD', 'Description.', 'boxes', 0);

        $client->request('GET', '/api/backoffice/quality/principles?locale=fr');
        self::assertResponseIsSuccessful();
        $filtered = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $filtered);
        self::assertSame('fr', $filtered[0]['locale']);

        $client->request('GET', '/api/backoffice/quality/principles');
        self::assertResponseIsSuccessful();
        $all = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $all);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        // Post
        $client->request('POST', '/api/backoffice/quality/principles', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['locale' => 'fr', 'title' => 'DDD', 'description' => 'Description.', 'iconKey' => 'boxes', 'position' => 0]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('DDD', $created['title']);
        self::assertIsInt($created['id']);
        $id = $created['id'];

        // Put
        $client->request('PUT', sprintf('/api/backoffice/quality/principles/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['title' => 'SOLID', 'description' => 'Description mise à jour.', 'iconKey' => 'columns-3', 'position' => 1]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('SOLID', $updated['title']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/quality/principles/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['title' => 'x', 'description' => 'x', 'iconKey' => 'x', 'position' => 0]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/quality/principles/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/quality/principles/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        // Le contenu public ne reflète plus le principe supprimé
        $client->request('GET', '/api/quality/fr');
        $publicContent = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([], $publicContent['principles']);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
