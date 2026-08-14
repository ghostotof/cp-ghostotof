<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\ApiResource;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/about/me-cards, en
 * miroir de BackofficeQualityPrincipleResourceTest, avec le filtre
 * supplémentaire ?category=.
 */
final class BackofficeAboutMeCardResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM about_me_card');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/about/me-cards');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/about/me-cards');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetCollectionFiltersByLocaleAndCategory(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $administrator = $client->getContainer()->get(AboutMeCardAdministratorInterface::class);
        $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $administrator->create(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);
        $administrator->create(Locale::EN, AboutMeCardCategory::TECHNICAL, 'Developer', 'Description.', 'code', 0);

        $client->request('GET', '/api/backoffice/about/me-cards?locale=fr&category=technical');
        self::assertResponseIsSuccessful();
        $filtered = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $filtered);
        self::assertSame('Développeur', $filtered[0]['title']);

        $client->request('GET', '/api/backoffice/about/me-cards?locale=fr');
        self::assertResponseIsSuccessful();
        $localeOnly = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $localeOnly);

        $client->request('GET', '/api/backoffice/about/me-cards');
        self::assertResponseIsSuccessful();
        $all = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(3, $all);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        // Post
        $client->request('POST', '/api/backoffice/about/me-cards', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['locale' => 'fr', 'category' => 'hobby', 'title' => 'Musique', 'description' => 'Description.', 'iconKey' => 'guitar', 'position' => 0]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Musique', $created['title']);
        self::assertSame('hobby', $created['category']);
        self::assertIsInt($created['id']);
        $id = $created['id'];

        // Put
        $client->request('PUT', sprintf('/api/backoffice/about/me-cards/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['title' => 'Moto', 'description' => 'Description mise à jour.', 'iconKey' => 'motorbike', 'position' => 1]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Moto', $updated['title']);
        self::assertSame('hobby', $updated['category']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/about/me-cards/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['title' => 'x', 'description' => 'x', 'iconKey' => 'x', 'position' => 0]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/about/me-cards/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/about/me-cards/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', sprintf('/api/backoffice/about/me-cards/%d', $id));
        self::assertResponseStatusCodeSame(404);
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
