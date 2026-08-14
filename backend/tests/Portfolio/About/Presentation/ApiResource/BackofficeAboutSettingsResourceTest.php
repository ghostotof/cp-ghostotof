<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\ApiResource;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre l'édition réservée ROLE_SUPER de /api/backoffice/about/settings/{locale},
 * en miroir de BackofficeQualityPrincipleResourceTest (Get/Put uniquement,
 * pas de Post/Delete : AboutSettings est un singleton par locale).
 */
final class BackofficeAboutSettingsResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM about_settings');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/about/settings/fr');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/about/settings/fr');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetUnseededLocaleReturns404(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('GET', '/api/backoffice/about/settings/fr');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetAndPutAsRoleSuper(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        $container->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $container->get(AboutSettingsRepositoryInterface::class)->save(
            new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs'),
        );

        $client->request('GET', '/api/backoffice/about/settings/fr');
        self::assertResponseIsSuccessful();
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Site', $fetched['siteEyebrow']);

        $client->request('PUT', '/api/backoffice/about/settings/fr', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode([
            'siteEyebrow' => 'Nouveau site',
            'meEyebrow' => 'Nouveau moi',
            'technicalSubtitle' => 'Nouvelle technique',
            'personalSubtitle' => 'Nouveau perso',
            'hobbiesSubtitle' => 'Nouveaux loisirs',
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Nouveau site', $updated['siteEyebrow']);

        // Le contenu public reflète la mise à jour
        $client->request('GET', '/api/backoffice/about/settings/fr');
        $refetched = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Nouveau site', $refetched['siteEyebrow']);
    }

    public function testPutUnseededLocaleReturns404(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->request('PUT', '/api/backoffice/about/settings/en', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode([
            'siteEyebrow' => 'a', 'meEyebrow' => 'b', 'technicalSubtitle' => 'c', 'personalSubtitle' => 'd', 'hobbiesSubtitle' => 'e',
        ]));

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
